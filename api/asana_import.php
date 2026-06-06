<?php
// asana_import.php
// Основной API скрипт импорта данных из Asana в ЗадаЧат
// Версия: 2.10 - ИСПРАВЛЕНА: использование иерархического получения задач для подзадач
// СТАРАЯ ЛОГИКА РАБОТЫ С СЕРВЕРОМ СОХРАНЕНА
// ДОБАВЛЕНА поддержка импорта файлов из сообщений

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

// Перехват всех ошибок для логирования
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        debugLog("FATAL ERROR: " . print_r($error, true));
        // Попытка отправить JSON-ответ вместо HTML
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false, 
                'error' => 'Fatal error: ' . ($error['message'] ?? 'Unknown'),
                'file' => $error['file'] ?? '',
                'line' => $error['line'] ?? 0
            ]);
        }
    }
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    debugLog("WARNING: [$errno] $errstr in $errfile on line $errline");
    return false;
});

// Включение подробного логирования для отладки
$debugLogFile = __DIR__ . '/asana_debug.log';
function debugLog($message) {
    global $debugLogFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($debugLogFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

debugLog("=== SCRIPT START ===");
debugLog("Action: " . ($_POST['action'] ?? $_GET['action'] ?? 'unknown'));

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('X-Accel-Buffering: no');

define('AJAX_REQUEST', true);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/asana_config.php';
require_once __DIR__ . '/asana_client.php';
require_once __DIR__ . '/asana_mapper.php';

if (!msgql_is_logged_in() || !msgql_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!msgql_csrf_validate_token()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
        exit;
    }
}

$db = msgql_db();
$currentUserUuid = msgql_current_user_uuid();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$db->query("
    CREATE TABLE IF NOT EXISTS `asana_import_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asana_gid` varchar(50) NOT NULL,
        `type` enum('project','task','message','file','user') NOT NULL,
        `project_id` char(36) DEFAULT NULL,
        `task_id` char(36) DEFAULT NULL,
        `message_id` char(36) DEFAULT NULL,
        `file_id` char(36) DEFAULT NULL,
        `title` varchar(255) DEFAULT NULL,
        `status` enum('pending','success','error') DEFAULT 'success',
        `error_msg` text DEFAULT NULL,
        `imported_at` bigint(20) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_asana_gid_type` (`asana_gid`,`type`),
        KEY `idx_type_status` (`type`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$db->query("
    CREATE TABLE IF NOT EXISTS `asana_user_mapping` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asana_gid` varchar(50) NOT NULL,
        `asana_email` varchar(255) DEFAULT NULL,
        `asana_name` varchar(255) DEFAULT NULL,
        `user_uuid` char(36) NOT NULL,
        `mapping_type` enum('auto','manual','email','login') DEFAULT 'auto',
        `matched_by` enum('email','login','name','manual','system') DEFAULT NULL,
        `confidence` float DEFAULT 1,
        `mapped_by_uuid` char(36) DEFAULT NULL,
        `created_at` bigint(20) NOT NULL,
        `updated_at` bigint(20) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_asana_gid` (`asana_gid`),
        KEY `idx_user_uuid` (`user_uuid`),
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function isImportCancelled() {
    $cancelFile = IMPORT_CANCEL_FILE;
    if (file_exists($cancelFile)) {
        $content = @file_get_contents($cancelFile);
        if ($content === '1' || $content === 'true') {
            log_info("[CANCEL] Import cancellation detected via semaphore file");
            return true;
        }
    }
    return false;
}

function cancelImport() {
    $cancelFile = IMPORT_CANCEL_FILE;
    $result = @file_put_contents($cancelFile, '1');
    if ($result !== false) {
        log_info("[CANCEL] Import cancellation semaphore created at: {$cancelFile}");
    } else {
        log_error("[CANCEL] Failed to create cancellation semaphore: {$cancelFile}");
    }
}

function clearCancelFlag() {
    $cancelFile = IMPORT_CANCEL_FILE;
    if (file_exists($cancelFile)) {
        @unlink($cancelFile);
        log_info("[CANCEL] Import cancellation semaphore removed");
    }
}

try {
    $client = new AsanaClient(ASANA_PERSONAL_ACCESS_TOKEN, ASANA_WORKSPACE_GID);
    $mapper = new AsanaMapper($db, IMPORT_MODE_FULL);
    
    if (!empty($currentUserUuid)) {
        $mapper->setCurrentUserUuid($currentUserUuid);
    }
    
    switch ($action) {
        case 'cancel_import':
            cancelImport();
            echo json_encode(['success' => true, 'message' => 'Import cancellation requested']);
            break;
            
        case 'clear_cancel_flag':
            clearCancelFlag();
            echo json_encode(['success' => true, 'message' => 'Cancel flag cleared']);
            break;
            
        case 'get_projects':
            log_debug("[ASANA_API] Getting projects");
            $projects = $client->getAllProjects();
            echo json_encode(['success' => true, 'projects' => $projects]);
            break;
            
        case 'get_users':
            log_debug("[ASANA_API] Getting users");
            $users = $client->getUsers();
            echo json_encode(['success' => true, 'users' => $users]);
            break;
            
        case 'get_system_users':
            $result = $db->query("SELECT uuid, login, name, email FROM users WHERE status = 0 ORDER BY login");
            $users = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            break;
            
        case 'get_user_mappings':
            $result = $db->query("SELECT asana_gid, user_uuid FROM asana_user_mapping");
            $mappings = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'mappings' => $mappings]);
            break;
            
        case 'save_user_mapping':
            $asanaGid = $_POST['asana_gid'] ?? '';
            $asanaEmail = $_POST['asana_email'] ?? '';
            $asanaName = $_POST['asana_name'] ?? '';
            $userUuid = $_POST['user_uuid'] ?? '';
            
            if (empty($asanaGid) || empty($userUuid)) {
                throw new Exception("asana_gid and user_uuid required");
            }
            
            $now = msgql_now_ms();
            $stmt = $db->prepare("
                INSERT INTO asana_user_mapping (asana_gid, asana_email, asana_name, user_uuid, mapping_type, matched_by, created_at)
                VALUES (?, ?, ?, ?, 'manual', 'manual', ?)
                ON DUPLICATE KEY UPDATE 
                    asana_email = VALUES(asana_email),
                    asana_name = VALUES(asana_name),
                    user_uuid = VALUES(user_uuid),
                    mapping_type = 'manual',
                    updated_at = VALUES(created_at)
            ");
            $stmt->bind_param("sssss", $asanaGid, $asanaEmail, $asanaName, $userUuid, $now);
            $stmt->execute();
            echo json_encode(['success' => true]);
            break;
            
        case 'import_users':
            $dryRun = (int)($_POST['dry_run'] ?? 0);
            $mode = $dryRun ? IMPORT_MODE_DRY_RUN : IMPORT_MODE_FULL;
            $mapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $mapper->setCurrentUserUuid($currentUserUuid);
            
            $users = $client->getUsers();
            $imported = 0;
            foreach ($users as $user) {
                $wasCreated = false;
                $mapper->findOrCreateUser($user, $wasCreated);
                if ($wasCreated) $imported++;
                usleep(100000);
            }
            
            echo json_encode(['success' => true, 'imported' => $imported, 'total' => count($users)]);
            break;
            
        case 'import_project':
            $projectGid = $_POST['project_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            
            if (empty($projectGid)) {
                throw new Exception("project_gid required");
            }
            
            $mapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $mapper->setCurrentUserUuid($currentUserUuid);
            
            $asanaProject = $client->getProject($projectGid);
            if (!$asanaProject) {
                throw new Exception("Project not found");
            }
            
            $projectUuid = $mapper->importProject($asanaProject);
            echo json_encode([
                'success' => true, 
                'project_uuid' => $projectUuid,
                'project_title' => $asanaProject['name']
            ]);
            break;
            
        case 'import_tasks':
            $projectGid = $_POST['project_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            
            if (empty($projectGid)) {
                throw new Exception("project_gid required");
            }
            
            $mapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $mapper->setCurrentUserUuid($currentUserUuid);
            
            $stmt = $db->prepare("SELECT project_id FROM asana_import_log WHERE asana_gid = ? AND type = 'project' LIMIT 1");
            $stmt->bind_param("s", $projectGid);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            $projectUuid = null;
            if ($existing && !empty($existing['project_id'])) {
                $projectUuid = $existing['project_id'];
            } else {
                $asanaProject = $client->getProject($projectGid);
                if ($asanaProject) {
                    $projectUuid = $mapper->importProject($asanaProject);
                }
            }
            
            if (!$projectUuid && $mode !== IMPORT_MODE_DRY_RUN) {
                throw new Exception("Failed to get project UUID");
            }
            
            $rootTasks = $client->getAllTasksHierarchical($projectGid);
            
            $countTasks = function($tasks, &$counter, &$subCounter) use (&$countTasks) {
                foreach ($tasks as $task) {
                    $counter++;
                    if (!empty($task['subtasks'])) {
                        $subCounter += count($task['subtasks']);
                        $countTasks($task['subtasks'], $counter, $subCounter);
                    }
                }
            };
            $totalTasks = 0;
            $subtasksCount = 0;
            $countTasks($rootTasks, $totalTasks, $subtasksCount);
            $tasksCount = $totalTasks - $subtasksCount;
            
            foreach ($rootTasks as $task) {
                if (isImportCancelled()) {
                    throw new Exception("Import cancelled by user");
                }
                $mapper->importTask($task, $projectUuid);
                usleep(200000);
            }
            
            echo json_encode([
                'success' => true,
                'tasks_count' => $tasksCount,
                'subtasks_count' => $subtasksCount
            ]);
            break;
            
        case 'import_messages':
            $taskGid = $_POST['task_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            
            if (empty($taskGid)) {
                throw new Exception("task_gid required");
            }
            
            $importMapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $importMapper->setCurrentUserUuid($currentUserUuid);
            
            $stmt = $db->prepare("SELECT task_id FROM asana_import_log WHERE asana_gid = ? AND type = 'task' LIMIT 1");
            $stmt->bind_param("s", $taskGid);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if (!$existing && $mode !== IMPORT_MODE_DRY_RUN) {
                throw new Exception("Task not imported yet");
            }
            
            $taskUuid = $existing['task_id'] ?? null;
            $stories = $client->getAllStories($taskGid);
            
            $messagesCount = 0;
            if ($taskUuid || $mode === IMPORT_MODE_DRY_RUN) {
                $messagesCount = $importMapper->importMessages($taskUuid, $stories, $client);
                log_info("[IMPORT_MESSAGES] Imported {$messagesCount} messages for task {$taskGid}");
            }
            
            echo json_encode([
                'success' => true,
                'messages_count' => $messagesCount
            ]);
            break;
            
        case 'import_files':
            $taskGid = $_POST['task_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            
            if (empty($taskGid)) {
                throw new Exception("task_gid required");
            }
            
            $mapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $mapper->setCurrentUserUuid($currentUserUuid);
            
            $stmt = $db->prepare("SELECT task_id FROM asana_import_log WHERE asana_gid = ? AND type = 'task' LIMIT 1");
            $stmt->bind_param("s", $taskGid);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            
            if (!$existing && $mode !== IMPORT_MODE_DRY_RUN) {
                throw new Exception("Task not imported yet");
            }
            
            $taskUuid = $existing['task_id'] ?? null;
            $attachments = $client->getAttachments($taskGid);
            
            $filesCount = 0;
            if ($taskUuid || $mode === IMPORT_MODE_DRY_RUN) {
                $filesCount = $mapper->importAttachments($taskUuid, $attachments, $client);
            }
            
            echo json_encode([
                'success' => true,
                'files_count' => $filesCount
            ]);
            break;
            
            case 'full_import':
            $projectGid = $_POST['project_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            
            if (empty($projectGid)) {
                throw new Exception("project_gid required");
            }
            
            clearCancelFlag();
            
            $mapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) $mapper->setCurrentUserUuid($currentUserUuid);
            $mapper->setStartTime();
            $mapper->setMaxExecutionTime(45);
            
            $asanaProject = $client->getProject($projectGid);
            if (!$asanaProject) {
                throw new Exception("Project not found");
            }
            $projectUuid = $mapper->importProject($asanaProject);
            
            $rootTasks = $client->getAllTasksHierarchical($projectGid);
            $tasksCount = 0;
            foreach ($rootTasks as $task) {
                // ========== ВСТАВИТЬ ЗДЕСЬ ==========
                if (isImportCancelled()) {
                    log_info("[FULL_IMPORT] Cancelled by user during task import");
                    throw new Exception("Import cancelled by user");
                }
                // ===================================
                
                $mapper->importTask($task, $projectUuid);
                $tasksCount++;
                usleep(200000);
            }
            
            $stmt = $db->prepare("
                SELECT asana_gid, task_id 
                FROM asana_import_log 
                WHERE project_id = ? AND type = 'task'
            ");
            $stmt->bind_param("s", $projectUuid);
            $stmt->execute();
            $importedTasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $messagesCount = 0;
            $filesCount = 0;
            
            foreach ($importedTasks as $task) {
                // ========== ВСТАВИТЬ ЗДЕСЬ ==========
                if (isImportCancelled()) {
                    log_info("[FULL_IMPORT] Cancelled by user during messages/files import");
                    throw new Exception("Import cancelled by user");
                }
                // ===================================
                
                $stories = $client->getAllStories($task['asana_gid']);
                $messagesCount += $mapper->importMessages($task['task_id'], $stories, $client);
                
                $attachments = $client->getAttachments($task['asana_gid']);
                $filesCount += $mapper->importAttachments($task['task_id'], $attachments, $client);
                
                usleep(300000);
            }
            
            $stats = $mapper->getStats();
            echo json_encode([
                'success' => true,
                'project_uuid' => $projectUuid,
                'stats' => [
                    'projects' => 1,
                    'tasks' => $tasksCount,
                    'messages' => $messagesCount,
                    'files' => $filesCount
                ]
            ]);
            break;
            
        case 'cancel_import':
            cancelImport();
            echo json_encode(['success' => true, 'message' => 'Import cancelled']);
            break;
            
        case 'clear_cancel_flag':
            clearCancelFlag();
            echo json_encode(['success' => true]);
            break;
            
        case 'get_status':
            $stats = [
                'projects' => 0,
                'tasks' => 0,
                'messages' => 0,
                'files' => 0
            ];
            
            $result = $db->query("SELECT type, COUNT(*) as cnt FROM asana_import_log WHERE status = 'success' GROUP BY type");
            while ($row = $result->fetch_assoc()) {
                $key = $row['type'] . 's';
                $stats[$key] = $row['cnt'];
            }
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        case 'clear_log':
            $db->query("TRUNCATE TABLE asana_import_log");
            echo json_encode(['success' => true]);
            break;
            
        case 'test_connection':
            try {
                $projects = $client->getProjects(1);
                echo json_encode(['success' => true, 'message' => 'Connection successful']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'import_chunk':
            debugLog("=== IMPORT_CHUNK START ===");
            debugLog("POST data: " . print_r($_POST, true));
            
            $projectGid = $_POST['project_gid'] ?? '';
            $mode = (int)($_POST['mode'] ?? IMPORT_MODE_FULL);
            $offset = (int)($_POST['offset'] ?? 0);
            $chunkSize = (int)($_POST['chunk_size'] ?? 5);
            $phase = $_POST['phase'] ?? 'tasks';
            $taskGids = isset($_POST['task_gids']) ? json_decode($_POST['task_gids'], true) : [];
            $projectUuid = $_POST['project_uuid'] ?? '';
            
            debugLog("Parsed: projectGid={$projectGid}, phase={$phase}, offset={$offset}, chunkSize={$chunkSize}, mode={$mode}");
            
            if (empty($projectGid) && empty($taskGids)) {
                debugLog("ERROR: project_gid or task_gids required");
                throw new Exception("project_gid or task_gids required");
            }
            
            debugLog("Creating AsanaMapper with mode={$mode}");
            $importMapper = new AsanaMapper($db, $mode);
            if (!empty($currentUserUuid)) {
                $importMapper->setCurrentUserUuid($currentUserUuid);
                debugLog("Set currentUserUuid: {$currentUserUuid}");
            }
            
            // Фаза 1: Получение списка задач (только при offset=0 и phase=tasks)
            if ($phase === 'tasks' && empty($taskGids)) {
                debugLog("[PHASE1] Fetching task list for project {$projectGid}");
                
                try {
                    $rootTasks = $client->getAllTasksHierarchical($projectGid);
                    debugLog("[PHASE1] Got " . count($rootTasks) . " root tasks");
                    
                    $taskGids = [];
                    $flatten = function($tasks) use (&$flatten, &$taskGids) {
                        foreach ($tasks as $task) {
                            $taskGids[] = $task['gid'];
                            if (!empty($task['subtasks'])) {
                                $flatten($task['subtasks']);
                            }
                        }
                    };
                    $flatten($rootTasks);
                    debugLog("[PHASE1] Flattened to " . count($taskGids) . " total tasks (including subtasks)");
                    
                    $response = [
                        'success' => true,
                        'phase' => 'tasks',
                        'total_tasks' => count($taskGids),
                        'task_gids' => $taskGids,
                        'next_offset' => 0,
                        'continue' => true
                    ];
                    debugLog("[PHASE1] Response prepared, task_gids count: " . count($taskGids));
                    echo json_encode($response);
                    debugLog("[PHASE1] JSON sent successfully");
                } catch (Exception $e) {
                    debugLog("[PHASE1] ERROR: " . $e->getMessage());
                    debugLog("[PHASE1] Stack trace: " . $e->getTraceAsString());
                    throw $e;
                }
                break;
            }
            
            // Фаза 2: Импорт задач чанками
            if ($phase === 'tasks' && !empty($taskGids)) {
                debugLog("[PHASE2] Importing tasks chunk offset={$offset}, chunkSize={$chunkSize}");
                debugLog("[PHASE2] Total taskGids count: " . count($taskGids));
                
                if (empty($projectUuid)) {
                    debugLog("[PHASE2] projectUuid empty, trying to get from import log");
                    $stmt = $db->prepare("SELECT project_id FROM asana_import_log WHERE asana_gid = ? AND type = 'project' LIMIT 1");
                    $stmt->bind_param("s", $projectGid);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    if ($existing && !empty($existing['project_id'])) {
                        $projectUuid = $existing['project_id'];
                        debugLog("[PHASE2] Found projectUuid in log: {$projectUuid}");
                    } else {
                        debugLog("[PHASE2] Project not found in log, fetching from Asana API");
                        $asanaProject = $client->getProject($projectGid);
                        if ($asanaProject) {
                            $projectUuid = $importMapper->importProject($asanaProject);
                            debugLog("[PHASE2] Created new project with UUID: {$projectUuid}");
                        }
                    }
                }
                
                if (empty($projectUuid) && $mode !== IMPORT_MODE_DRY_RUN) {
                    debugLog("[PHASE2] ERROR: Failed to get project UUID");
                    throw new Exception("Failed to get project UUID");
                }
                
                debugLog("[PHASE2] Getting hierarchical tasks for project {$projectGid}");
                $rootTasks = $client->getAllTasksHierarchical($projectGid);
                $flattenedTasks = [];
                $flatten = function($tasks) use (&$flatten, &$flattenedTasks) {
                    foreach ($tasks as $task) {
                        $flattenedTasks[] = $task;
                        if (!empty($task['subtasks'])) {
                            $flatten($task['subtasks']);
                        }
                    }
                };
                $flatten($rootTasks);
                debugLog("[PHASE2] Flattened to " . count($flattenedTasks) . " tasks");
                
                $chunk = array_slice($flattenedTasks, $offset, $chunkSize);
                debugLog("[PHASE2] Processing chunk of " . count($chunk) . " tasks (offset={$offset})");
                
                $tasksCount = 0;
                $subtasksCount = 0;
                
                foreach ($chunk as $idx => $task) {
                    if (isImportCancelled()) {
                        debugLog("[PHASE2] Import cancelled by user at task {$idx}");
                        throw new Exception("Import cancelled by user");
                    }
                    
                    $taskGid = $task['gid'];
                    debugLog("[PHASE2] Processing task {$idx}: {$taskGid} - {$task['name']}");
                    
                    $parentUuid = null;
                    if (!empty($task['parent']) && isset($importMapper->taskMapping[$task['parent']['gid']])) {
                        $parentUuid = $importMapper->taskMapping[$task['parent']['gid']];
                        $subtasksCount++;
                        debugLog("[PHASE2] Task {$taskGid} is subtask of {$task['parent']['gid']}, parentUuid={$parentUuid}");
                    }
                    
                    try {
                        $importMapper->importTask($task, $projectUuid, $parentUuid);
                        $tasksCount++;
                        debugLog("[PHASE2] Successfully imported task {$taskGid}");
                    } catch (Exception $e) {
                        debugLog("[PHASE2] ERROR importing task {$taskGid}: " . $e->getMessage());
                        throw $e;
                    }
                    usleep(200000);
                }
                
                $nextOffset = $offset + $chunkSize;
                $isComplete = $nextOffset >= count($flattenedTasks);
                debugLog("[PHASE2] Completed: tasks_imported={$tasksCount}, subtasks_imported={$subtasksCount}, nextOffset={$nextOffset}, complete=" . ($isComplete ? 'true' : 'false'));
                
                echo json_encode([
                    'success' => true,
                    'phase' => 'tasks',
                    'offset' => $offset,
                    'next_offset' => $nextOffset,
                    'total' => count($flattenedTasks),
                    'tasks_imported' => $tasksCount,
                    'subtasks_imported' => $subtasksCount,
                    'complete' => $isComplete,
                    'continue' => !$isComplete,
                    'project_uuid' => $projectUuid,
                    'project_gid' => $projectGid,
                    'mode' => $mode
                ]);
                debugLog("[PHASE2] JSON response sent");
                break;
            }
        
            // Фаза 3: Импорт сообщений и файлов
            if ($phase === 'messages') {
                debugLog("[PHASE3] Importing messages and files for project {$projectGid}, offset={$offset}, chunkSize={$chunkSize}");
                
                $projectStmt = $db->prepare("
                    SELECT project_id FROM asana_import_log 
                    WHERE asana_gid = ? AND type = 'project' LIMIT 1
                ");
                $projectStmt->bind_param("s", $projectGid);
                $projectStmt->execute();
                $projectResult = $projectStmt->get_result()->fetch_assoc();
                $projectUuid = $projectResult['project_id'] ?? null;
                
                if (!$projectUuid) {
                    debugLog("[PHASE3] ERROR: Project not found in import log: {$projectGid}");
                    throw new Exception("Project not found in import log: {$projectGid}");
                }
                
                debugLog("[PHASE3] Found project_uuid: {$projectUuid}");
                
                $stmt = $db->prepare("
                    SELECT asana_gid, task_id, title 
                    FROM asana_import_log 
                    WHERE project_id = ? AND type = 'task'
                    LIMIT ? OFFSET ?
                ");
                $stmt->bind_param("sii", $projectUuid, $chunkSize, $offset);
                $stmt->execute();
                $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                debugLog("[PHASE3] Found " . count($tasks) . " tasks for import (offset={$offset})");
                
                $messagesCount = 0;
                $filesCount = 0;
                
                foreach ($tasks as $idx => $task) {
                    if (isImportCancelled()) {
                        debugLog("[PHASE3] Import cancelled by user at task {$idx}");
                        throw new Exception("Import cancelled by user");
                    }
                    
                    debugLog("[PHASE3] Processing task {$idx}: {$task['title']} (gid: {$task['asana_gid']}, task_id: {$task['task_id']})");
                    
                    try {
                        debugLog("[PHASE3] Fetching stories for task {$task['asana_gid']}");
                        $stories = $client->getAllStories($task['asana_gid']);
                        debugLog("[PHASE3] Got " . count($stories) . " stories");
                        
                        if (!empty($stories)) {
                            $messagesCount += $importMapper->importMessages($task['task_id'], $stories, $client);
                            debugLog("[PHASE3] Imported messages, total so far: {$messagesCount}");
                        }
                        
                        debugLog("[PHASE3] Fetching attachments for task {$task['asana_gid']}");
                        $attachments = $client->getAttachments($task['asana_gid']);
                        debugLog("[PHASE3] Got " . count($attachments) . " attachments");
                        
                        if (!empty($attachments)) {
                            $filesCount += $importMapper->importAttachments($task['task_id'], $attachments, $client);
                            debugLog("[PHASE3] Imported files, total so far: {$filesCount}");
                        }
                    } catch (Exception $e) {
                        debugLog("[PHASE3] ERROR processing task {$task['asana_gid']}: " . $e->getMessage());
                        debugLog("[PHASE3] Stack trace: " . $e->getTraceAsString());
                        throw $e;
                    }
                    
                    usleep(300000);
                }
                
                $nextOffset = $offset + $chunkSize;
                
                $totalStmt = $db->prepare("
                    SELECT COUNT(*) as total 
                    FROM asana_import_log 
                    WHERE project_id = ? AND type = 'task'
                ");
                $totalStmt->bind_param("s", $projectUuid);
                $totalStmt->execute();
                $totalResult = $totalStmt->get_result()->fetch_assoc();
                $totalTasks = $totalResult['total'] ?? 0;
                
                $isComplete = $nextOffset >= $totalTasks;
                debugLog("[PHASE3] Completed: messages={$messagesCount}, files={$filesCount}, nextOffset={$nextOffset}, totalTasks={$totalTasks}, complete=" . ($isComplete ? 'true' : 'false'));
                
                echo json_encode([
                    'success' => true,
                    'phase' => 'messages',
                    'offset' => $offset,
                    'next_offset' => $nextOffset,
                    'total' => $totalTasks,
                    'messages_imported' => $messagesCount,
                    'files_imported' => $filesCount,
                    'complete' => $isComplete,
                    'continue' => !$isComplete,
                    'project_gid' => $projectGid
                ]);
                debugLog("[PHASE3] JSON response sent");
                break;
            }
        
        break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
    
} catch (Exception $e) {
    log_error("[ASANA_API] Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}