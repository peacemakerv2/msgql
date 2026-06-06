<?php
// asana_mapper.php
// Маппинг данных из Asana в структуру ЗадаЧат
// Версия: 2.16 - ДОБАВЛЕНЫ: проверки семафора isImportCancelled() во всех циклах
// СТАРАЯ ЛОГИКА РАБОТЫ С СЕРВЕРОМ СОХРАНЕНА
// ДОБАВЛЕНА обработка файлов из сообщений (story attachments)
// ДОБАВЛЕНА поддержка message_time для дат файлов

// Проверяем, определены ли константы режимов импорта
if (!defined('IMPORT_MODE_DRY_RUN')) {
    define('IMPORT_MODE_DRY_RUN', 1);
    define('IMPORT_MODE_FULL', 2);
    define('IMPORT_MODE_UPDATE', 3);
}

// Проверяем, определена ли функция isImportCancelled
if (!function_exists('isImportCancelled')) {
    function isImportCancelled() {
        static $checked = false;
        static $cancelled = false;
        
        $now = microtime(true);
        if (!$checked || ($now - $checked) > 0.2) {
            $checked = $now;
            $cancelFile = defined('IMPORT_CANCEL_FILE') ? IMPORT_CANCEL_FILE : __DIR__ . '/.import_cancel';
            if (file_exists($cancelFile)) {
                $cancelled = true;
                return true;
            }
        }
        return $cancelled;
    }
}

if (!function_exists('msgql_format_file_size')) {
    function msgql_format_file_size(int $bytes): string {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}

class AsanaMapper {
    private $db;
    private $userMapping = [];
    private $projectMapping = [];
    private $taskMapping = [];
    private $importMode;
    private $stats;
    private $forcedUserUuid = null;
    private $newUsersCreated = [];
    
    private $startTime = null;
    private $maxExecutionTime = 50;
    
    public function __construct($db, $importMode = IMPORT_MODE_FULL) {
        $this->db = $db;
        $this->importMode = $importMode;
        $this->stats = [
            'projects' => 0,
            'tasks' => 0,
            'subtasks' => 0,
            'messages' => 0,
            'files' => 0,
            'users_created' => 0,
            'errors' => []
        ];
    }
    
    public function setCurrentUserUuid($uuid) {
        $this->forcedUserUuid = $uuid;
    }
    
    public function setStartTime($startTime = null) {
        $this->startTime = $startTime ?? microtime(true);
    }
    
    public function setMaxExecutionTime($seconds) {
        $this->maxExecutionTime = $seconds;
    }
    
    public function getStats() {
        $this->stats['users_created'] = count($this->newUsersCreated);
        return $this->stats;
    }
    
    public function getCreatedUsers() {
        return $this->newUsersCreated;
    }
    
    private function getCurrentUserUuid(): string {
        if (!empty($this->forcedUserUuid)) {
            return $this->forcedUserUuid;
        }
        
        $userUuid = msgql_current_user_uuid();
        if (!empty($userUuid)) {
            return $userUuid;
        }
        
        global $currentUserUuid;
        if (!empty($currentUserUuid)) {
            return $currentUserUuid;
        }
        
        $stmt = $this->db->prepare("SELECT uuid FROM users WHERE role = 0 AND status = 0 LIMIT 1");
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        if ($admin && !empty($admin['uuid'])) {
            log_info("[MAPPER] Using admin user as fallback: {$admin['uuid']}");
            return $admin['uuid'];
        }
        
        $stmt = $this->db->prepare("SELECT uuid FROM users WHERE status = 0 LIMIT 1");
        $stmt->execute();
        $anyUser = $stmt->get_result()->fetch_assoc();
        if ($anyUser && !empty($anyUser['uuid'])) {
            log_info("[MAPPER] Using any active user as fallback: {$anyUser['uuid']}");
            return $anyUser['uuid'];
        }
        
        log_error("[MAPPER] No active users found in database!");
        return '';
    }
    
    private function convertAsanaDate($dateString) {
        if (empty($dateString)) return null;
        $timestamp = strtotime($dateString);
        if ($timestamp === false) return null;
        return $timestamp * 1000;
    }
    
    private function convertStatus($asanaTask) {
        return isset($asanaTask['completed']) && $asanaTask['completed'] ? 1 : 0;
    }
    
    public function findOrCreateUser($asanaUser, &$wasCreated = null) {
        if ($wasCreated !== null) $wasCreated = false;
        
        if (empty($asanaUser) || empty($asanaUser['gid'])) {
            return $this->getCurrentUserUuid();
        }
        
        $asanaGid = $asanaUser['gid'];
        $asanaEmail = $asanaUser['email'] ?? null;
        $asanaName = $asanaUser['name'] ?? null;
        
        if (isset($this->userMapping[$asanaGid])) {
            return $this->userMapping[$asanaGid];
        }
        
        $stmt = $this->db->prepare("SELECT user_uuid FROM asana_user_mapping WHERE asana_gid = ?");
        $stmt->bind_param("s", $asanaGid);
        $stmt->execute();
        $mapping = $stmt->get_result()->fetch_assoc();
        
        if ($mapping) {
            $userCheck = $this->db->prepare("SELECT uuid FROM users WHERE uuid = ? AND status = 0");
            $userCheck->bind_param("s", $mapping['user_uuid']);
            $userCheck->execute();
            if ($userCheck->get_result()->num_rows > 0) {
                $this->userMapping[$asanaGid] = $mapping['user_uuid'];
                return $mapping['user_uuid'];
            }
            $delStmt = $this->db->prepare("DELETE FROM asana_user_mapping WHERE asana_gid = ?");
            $delStmt->bind_param("s", $asanaGid);
            $delStmt->execute();
        }
        
        if ($asanaEmail) {
            $stmt = $this->db->prepare("SELECT uuid FROM users WHERE email = ? AND status = 0 LIMIT 1");
            $stmt->bind_param("s", $asanaEmail);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user) {
                $this->saveUserMapping($asanaGid, $asanaEmail, $asanaName, $user['uuid'], 'email');
                $this->userMapping[$asanaGid] = $user['uuid'];
                return $user['uuid'];
            }
        }
        
        if ($this->importMode !== IMPORT_MODE_DRY_RUN) {
            $newUserUuid = $this->createUserFromAsana($asanaName, $asanaEmail, $asanaGid);
            if ($newUserUuid) {
                $this->saveUserMapping($asanaGid, $asanaEmail, $asanaName, $newUserUuid, 'auto');
                $this->userMapping[$asanaGid] = $newUserUuid;
                if ($wasCreated !== null) $wasCreated = true;
                return $newUserUuid;
            }
        }
        
        return $this->getCurrentUserUuid();
    }
    
    private function saveUserMapping($asanaGid, $asanaEmail, $asanaName, $userUuid, $type) {
        $now = msgql_now_ms();
        $currentUser = $this->getCurrentUserUuid();
        
        $stmt = $this->db->prepare("
            INSERT INTO asana_user_mapping 
            (asana_gid, asana_email, asana_name, user_uuid, mapping_type, mapped_by_uuid, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            user_uuid = VALUES(user_uuid),
            mapping_type = VALUES(mapping_type),
            updated_at = VALUES(created_at)
        ");
        $stmt->bind_param("sssssss", $asanaGid, $asanaEmail, $asanaName, $userUuid, $type, $currentUser, $now);
        $stmt->execute();
    }
    
    private function createUserFromAsana($name, $email, $asanaGid) {
        if (empty($name)) {
            $name = 'asana_user_' . substr($asanaGid, 0, 8);
        }
        
        $baseLogin = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $this->transliterate($name)));
        if (strlen($baseLogin) < 3) {
            $baseLogin = 'user_' . substr($asanaGid, 0, 8);
        }
        
        $login = $baseLogin;
        $counter = 1;
        while ($this->loginExists($login)) {
            $login = $baseLogin . $counter;
            $counter++;
        }
        
        $tempPass = bin2hex(random_bytes(8));
        $salt = bin2hex(random_bytes(16));
        $hashed = msgql_password_hash($tempPass, $salt);
        
        $uuid = msgql_uuid_v4();
        $now = msgql_now_ms();
        $stamp = msgql_stamp(0);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, role, status, login, email, name, pass, salt, time, stamp)
            VALUES (?, 2, 0, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Исправлено: 8 переменных -> 8 символов 's'
        $stmt->bind_param("ssssssss", $uuid, $login, $email, $name, $hashed, $salt, $now, $stamp);
        
        if ($stmt->execute()) {
            log_info("[MAPPER] Created new user: {$name} ({$login}) with temp password: {$tempPass}");
            $this->newUsersCreated[] = $uuid;
            return $uuid;
        }
        
        log_error("[MAPPER] Failed to create user: " . $this->db->error);
        return null;
    }
    
    private function loginExists($login) {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE login = ?");
        $stmt->bind_param("s", $login);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
    
    private function transliterate($text) {
        $cyrillic = ['а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я'];
        $latin = ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'];
        return str_replace($cyrillic, $latin, $text);
    }
    
    public function importProject($asanaProject, $createdByUuid = null) {
        log_info("[IMPORT_PROJECT] Importing: " . ($asanaProject['name'] ?? 'Unknown'));
        
        if ($createdByUuid === null) {
            $createdByUuid = $this->getCurrentUserUuid();
        }
        
        $projectGid = $asanaProject['gid'];
        $title = substr($asanaProject['name'] ?? 'Imported Project', 0, 190);
        $descr = $asanaProject['notes'] ?? '';
        
        $logStmt = $this->db->prepare("SELECT project_id FROM asana_import_log WHERE asana_gid = ? AND type = 'project' LIMIT 1");
        $logStmt->bind_param("s", $projectGid);
        $logStmt->execute();
        $logResult = $logStmt->get_result()->fetch_assoc();
        
        if ($logResult && !empty($logResult['project_id'])) {
            $checkStmt = $this->db->prepare("SELECT uuid FROM projects WHERE uuid = ?");
            $checkStmt->bind_param("s", $logResult['project_id']);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $existingUuid = $logResult['project_id'];
                if ($this->importMode !== IMPORT_MODE_UPDATE) {
                    $this->projectMapping[$projectGid] = $existingUuid;
                    return $existingUuid;
                }
            }
        }
        
        $nameStmt = $this->db->prepare("SELECT uuid FROM projects WHERE title = ? LIMIT 1");
        $nameStmt->bind_param("s", $title);
        $nameStmt->execute();
        $nameResult = $nameStmt->get_result()->fetch_assoc();
        
        if ($nameResult && !empty($nameResult['uuid']) && $this->importMode !== IMPORT_MODE_UPDATE) {
            $this->projectMapping[$projectGid] = $nameResult['uuid'];
            return $nameResult['uuid'];
        }
        
        if ($this->importMode === IMPORT_MODE_DRY_RUN) {
            $this->stats['projects']++;
            return 'dry-run-' . $projectGid;
        }
        
        $uuid = msgql_uuid_v4();
        $now = msgql_now_ms();
        $stamp = msgql_stamp(0);
        
        if ($nameResult && $this->importMode === IMPORT_MODE_UPDATE) {
            $stmt = $this->db->prepare("UPDATE projects SET title = ?, descr = ? WHERE uuid = ?");
            $stmt->bind_param("sss", $title, $descr, $nameResult['uuid']);
            $stmt->execute();
            $uuid = $nameResult['uuid'];
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO projects (uuid, title, descr, created_by_uuid, time, stamp)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssss", $uuid, $title, $descr, $createdByUuid, $now, $stamp);
            $stmt->execute();
        }
        
        $this->projectMapping[$projectGid] = $uuid;
        $this->stats['projects']++;
        $this->logImport('project', $projectGid, $uuid, $title);
        
        return $uuid;
    }
    
    public function importTask($asanaTask, $projectUuid, $parentTaskUuid = null) {
        // ========== ПРОВЕРКА СЕМАФОРА ==========
        if (isImportCancelled()) {
            log_warning("[IMPORT_TASK] Import cancelled by user before processing task");
            throw new Exception("Import cancelled by user");
        }
        // ======================================
        
        log_debug("[IMPORT_TASK] Importing: " . ($asanaTask['name'] ?? 'Unknown') . " (parent: " . ($parentTaskUuid ?: 'ROOT') . ")");
        
        $title = substr($asanaTask['name'] ?? 'Imported Task', 0, 190);
        $descr = $asanaTask['notes'] ?? '';
        $status = $this->convertStatus($asanaTask);
        $timeStart = $this->convertAsanaDate($asanaTask['start_on'] ?? null);
        $timeEndPlan = $this->convertAsanaDate($asanaTask['due_on'] ?? null);
        $now = msgql_now_ms();
        $stamp = msgql_stamp(0);
        
        $assignedToUuid = null;
        if (isset($asanaTask['assignee']) && !empty($asanaTask['assignee'])) {
            $assignedToUuid = $this->findOrCreateUser($asanaTask['assignee']);
        }
        
        $taskGid = $asanaTask['gid'];
        
        $logStmt = $this->db->prepare("SELECT task_id FROM asana_import_log WHERE asana_gid = ? AND type = 'task' LIMIT 1");
        $logStmt->bind_param("s", $taskGid);
        $logStmt->execute();
        $logResult = $logStmt->get_result()->fetch_assoc();
        
        if ($logResult && !empty($logResult['task_id'])) {
            $checkStmt = $this->db->prepare("SELECT uuid FROM tasks WHERE uuid = ?");
            $checkStmt->bind_param("s", $logResult['task_id']);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                $existingUuid = $logResult['task_id'];
                if ($this->importMode !== IMPORT_MODE_UPDATE) {
                    $this->taskMapping[$taskGid] = $existingUuid;
                    log_debug("[IMPORT_TASK] Task {$taskGid} already exists, skipping");
                    return $existingUuid;
                }
            }
        }
        
        if ($this->importMode === IMPORT_MODE_DRY_RUN) {
            $this->stats['tasks']++;
            return 'dry-run-' . $taskGid;
        }
        
        $uuid = msgql_uuid_v4();
        $currentUserUuid = $this->getCurrentUserUuid();
        
        if ($logResult && $this->importMode === IMPORT_MODE_UPDATE) {
            $stmt = $this->db->prepare("
                UPDATE tasks SET 
                    title = ?, descr = ?, status = ?, time_start = ?, time_end_plan = ?, 
                    assigned_to_uuid = ?
                WHERE uuid = ?
            ");
            $stmt->bind_param("ssiisss", $title, $descr, $status, $timeStart, $timeEndPlan, $assignedToUuid, $logResult['task_id']);
            $stmt->execute();
            $uuid = $logResult['task_id'];
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO tasks (uuid, project_uuid, parent_task_uuid, title, descr, assigned_to_uuid, user_uuid,
                                   time_start, time_end_plan, status, time, stamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssssssiiiss", 
                $uuid, $projectUuid, $parentTaskUuid, $title, $descr, $assignedToUuid, $currentUserUuid,
                $timeStart, $timeEndPlan, $status, $now, $stamp
            );
            $stmt->execute();
        }
        
        $this->taskMapping[$taskGid] = $uuid;
        $this->stats['tasks']++;
        
        $this->logImport('task', $taskGid, $uuid, $title, $projectUuid);
        
        if (!empty($asanaTask['subtasks']) && is_array($asanaTask['subtasks'])) {
            log_info("[IMPORT_TASK] Task {$taskGid} has " . count($asanaTask['subtasks']) . " subtasks");
            foreach ($asanaTask['subtasks'] as $subtask) {
                // ========== ПРОВЕРКА СЕМАФОРА ПЕРЕД КАЖДОЙ ПОДЗАДАЧЕЙ ==========
                if (isImportCancelled()) {
                    log_warning("[IMPORT_TASK] Import cancelled by user during subtask import");
                    throw new Exception("Import cancelled by user");
                }
                // ==============================================================
                
                $this->importTask($subtask, $projectUuid, $uuid);
                $this->stats['subtasks']++;
            }
        }
        
        return $uuid;
    }
    
    public function importMessages($taskUuid, $asanaStories, $asanaClient = null) {
        log_info("[IMPORT_MESSAGES] taskUuid: {$taskUuid}, stories count: " . count($asanaStories));
        
        if (empty($asanaStories)) return 0;
        
        $imported = 0;
        $currentUserUuid = $this->getCurrentUserUuid();
        
        foreach ($asanaStories as $idx => $story) {
            // ========== ПРОВЕРКА СЕМАФОРА ==========
            if (isImportCancelled()) {
                log_warning("[IMPORT_MESSAGES] Import cancelled by user");
                throw new Exception("Import cancelled by user");
            }
            // ======================================
            
            $type = $story['type'] ?? '';
            $subtype = $story['resource_subtype'] ?? '';
            $storyGid = $story['gid'] ?? '';
            
            $isAttachmentStory = ($subtype === 'attachment');
            $hasAttachments = !empty($story['attachments']);
            
            if (!$isAttachmentStory && $type !== 'comment' && $subtype !== 'comment_added') {
                continue;
            }
            
            $text = $story['text'] ?? $story['html_text'] ?? '';
            
            if ($isAttachmentStory && empty($text) && $hasAttachments) {
                $attachmentNames = [];
                foreach ($story['attachments'] as $att) {
                    $attachmentNames[] = $att['name'] ?? 'file';
                }
                $text = "📎 Прикреплённые файлы: " . implode(', ', $attachmentNames);
            }
            
            if (empty($text) && !$isAttachmentStory) {
                continue;
            }
            
            if (strlen($text) > IMPORT_MAX_MESSAGE_LENGTH) {
                $text = substr($text, 0, IMPORT_MAX_MESSAGE_LENGTH - 100) . "\n\n[Сообщение обрезано...]";
            }
            
            $authorUuid = $currentUserUuid;
            if (isset($story['created_by']) && !empty($story['created_by'])) {
                $authorUuid = $this->findOrCreateUser($story['created_by']);
            }
            
            $time = $this->convertAsanaDate($story['created_at'] ?? null);
            if (!$time) $time = msgql_now_ms();
            $stamp = msgql_stamp(0);
            
            $logStmt = $this->db->prepare("SELECT message_id FROM asana_import_log WHERE asana_gid = ? AND type = 'message' LIMIT 1");
            $logStmt->bind_param("s", $storyGid);
            $logStmt->execute();
            $logResult = $logStmt->get_result()->fetch_assoc();
            
            if ($logResult && !empty($logResult['message_id']) && $this->importMode !== IMPORT_MODE_UPDATE) {
                continue;
            }
            
            if ($this->importMode === IMPORT_MODE_DRY_RUN) {
                $imported++;
                continue;
            }
            
            $messageUuid = msgql_uuid_v4();
            
            if ($logResult && $this->importMode === IMPORT_MODE_UPDATE) {
                $stmt = $this->db->prepare("UPDATE messages SET text = ? WHERE uuid = ?");
                $stmt->bind_param("ss", $text, $logResult['message_id']);
                $stmt->execute();
                $messageUuid = $logResult['message_id'];
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO messages (uuid, task_uuid, user_uuid, text, is_read, time, stamp)
                    VALUES (?, ?, ?, ?, 1, ?, ?)
                ");
                $stmt->bind_param("ssssss", $messageUuid, $taskUuid, $authorUuid, $text, $time, $stamp);
                $stmt->execute();
            }
            
            $imported++;
            $this->logImport('message', $storyGid, $messageUuid, substr($text, 0, 100));
            
            if ($asanaClient && $hasAttachments) {
                $messageTime = $this->convertAsanaDate($story['created_at'] ?? null);
                $filesImported = $this->importStoryAttachments($messageUuid, $taskUuid, $story['attachments'], $asanaClient, $messageTime);
                log_debug("[IMPORT_MESSAGES] Imported {$filesImported} attachments for message {$storyGid}");
            }
            
            usleep(ASANA_STORY_DELAY * 1000000);
        }
        
        $this->stats['messages'] += $imported;
        log_info("[IMPORT_MESSAGES] Imported {$imported} messages");
        
        return $imported;
    }
    
    public function importStoryAttachments($messageUuid, $taskUuid, $asanaAttachments, $asanaClient, $fallbackTime = null) {
        log_info("[IMPORT_STORY_ATTACH] messageUuid: {$messageUuid}, attachments: " . count($asanaAttachments));
        
        if (empty($asanaAttachments)) return 0;
        
        if (!file_exists(TEMP_DOWNLOAD_DIR)) {
            mkdir(TEMP_DOWNLOAD_DIR, 0777, true);
        }
        
        if (!file_exists(MESSAGES_UPLOAD_DIR)) {
            mkdir(MESSAGES_UPLOAD_DIR, 0777, true);
        }
        
        $imported = 0;
        $currentUserUuid = $this->getCurrentUserUuid();
        
        foreach ($asanaAttachments as $attachment) {
            // ========== ПРОВЕРКА СЕМАФОРА ==========
            if (isImportCancelled()) {
                log_warning("[IMPORT_STORY_ATTACH] Import cancelled by user");
                throw new Exception("Import cancelled by user");
            }
            // ======================================
            
            $originalFileName = $attachment['name'] ?? '';
            $downloadUrl = $attachment['download_url'] ?? null;
            $attachmentGid = $attachment['gid'] ?? null;
            
            if (empty($attachmentGid)) {
                $attachmentGid = uniqid();
                log_warning("[IMPORT_STORY_ATTACH] No GID for attachment, generated: {$attachmentGid}");
            }
            
            if (!$downloadUrl && $attachmentGid) {
                try {
                    $attachmentDetail = $asanaClient->getAttachment($attachmentGid);
                    if ($attachmentDetail && !empty($attachmentDetail['download_url'])) {
                        $downloadUrl = $attachmentDetail['download_url'];
                        log_debug("[IMPORT_STORY_ATTACH] Got download URL from detail endpoint for {$attachmentGid}");
                    }
                } catch (Exception $e) {
                    log_warning("[IMPORT_STORY_ATTACH] Could not fetch attachment detail: " . $e->getMessage());
                }
            }
            
            if (!$downloadUrl) {
                log_warning("[IMPORT_STORY_ATTACH] No download URL for attachment {$attachmentGid}, skipping");
                continue;
            }
            
            $logStmt = $this->db->prepare("SELECT file_id FROM asana_import_log WHERE asana_gid = ? AND type = 'file' LIMIT 1");
            $logStmt->bind_param("s", $attachmentGid);
            $logStmt->execute();
            $logResult = $logStmt->get_result()->fetch_assoc();
            
            if ($logResult && !empty($logResult['file_id']) && $this->importMode !== IMPORT_MODE_UPDATE) {
                $checkLink = $this->db->prepare("SELECT 1 FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                $checkLink->bind_param("ss", $messageUuid, $logResult['file_id']);
                $checkLink->execute();
                if ($checkLink->get_result()->num_rows === 0) {
                    $linkStmt = $this->db->prepare("INSERT IGNORE INTO message_files (message_uuid, file_uuid) VALUES (?, ?)");
                    $linkStmt->bind_param("ss", $messageUuid, $logResult['file_id']);
                    $linkStmt->execute();
                }
                $imported++;
                continue;
            }
            
            if ($this->importMode === IMPORT_MODE_DRY_RUN) {
                $imported++;
                continue;
            }
            
            $tempPath = TEMP_DOWNLOAD_DIR . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFileName ?: 'file');
            
            try {
                log_debug("[IMPORT_STORY_ATTACH] Downloading: {$originalFileName} (GID: {$attachmentGid})");
                $fileSize = $asanaClient->downloadFile($downloadUrl, $tempPath);
                
                if ($fileSize > 0) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $tempPath);
                    finfo_close($finfo);
                    
                    $ext = $this->getExtension($tempPath, $mime, $originalFileName);
                    
                    $sanitizedGid = preg_replace('/[^a-zA-Z0-9_-]/', '', $attachmentGid);
                    $storageName = 'asana_' . $sanitizedGid;
                    
                    if (!empty($originalFileName)) {
                        $originalBase = pathinfo($originalFileName, PATHINFO_FILENAME);
                        $sanitizedOriginal = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalBase);
                        if (strlen($sanitizedOriginal) > 100) {
                            $sanitizedOriginal = substr($sanitizedOriginal, 0, 100);
                        }
                        if (!empty($sanitizedOriginal)) {
                            $storageName .= '_' . $sanitizedOriginal;
                        }
                        log_debug("[IMPORT_STORY_ATTACH] Generated name with original: {$storageName}");
                    } else {
                        log_debug("[IMPORT_STORY_ATTACH] Generated name without original (GID only): {$storageName}");
                    }
                    
                    $storageName .= $ext;
                    $targetPath = MESSAGES_UPLOAD_DIR . $storageName;
                    
                    if (!rename($tempPath, $targetPath)) {
                        throw new Exception("Failed to move file to {$targetPath}");
                    }
                    
                    $fileTime = null;
                    if (!empty($attachment['created_at'])) {
                        $fileTime = $this->convertAsanaDate($attachment['created_at']);
                    }
                    if (!$fileTime && $fallbackTime) {
                        $fileTime = $fallbackTime;
                        log_debug("[IMPORT_STORY_ATTACH] Using fallback time from message for file: " . date('Y-m-d H:i:s', (int)($fileTime / 1000)));
                    }
                    if (!$fileTime) {
                        $fileTime = msgql_now_ms();
                    }
                    
                    $now = msgql_now_ms();
                    $stamp = date('d.m.Y H:i:s', (int)($fileTime / 1000));
                    $timeStr = (string)$fileTime;
                    
                    if (empty($originalFileName)) {
                        $origNameForDb = $storageName;
                        log_debug("[IMPORT_STORY_ATTACH] No original name, using storage_name: {$origNameForDb}");
                    } else {
                        $origNameForDb = $originalFileName;
                    }
                    
                    $fileUuid = msgql_uuid_v4();
                    $stmt = $this->db->prepare("
                        INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssssisss", $fileUuid, $origNameForDb, $storageName, $mime, $fileSize, $currentUserUuid, $timeStr, $stamp);
                    $stmt->execute();
                    
                    $linkStmt = $this->db->prepare("INSERT INTO message_files (message_uuid, file_uuid) VALUES (?, ?)");
                    $linkStmt->bind_param("ss", $messageUuid, $fileUuid);
                    $linkStmt->execute();
                    
                    $this->logImport('file', $attachmentGid, $fileUuid, $origNameForDb);
                    
                    $imported++;
                    $this->stats['files']++;
                    log_info("[IMPORT_STORY_ATTACH] Imported: {$storageName} ({$fileSize} bytes)");
                } else {
                    log_warning("[IMPORT_STORY_ATTACH] Downloaded file size is 0 for GID {$attachmentGid}");
                    if (file_exists($tempPath)) unlink($tempPath);
                }
            } catch (Exception $e) {
                log_error("[IMPORT_STORY_ATTACH] Failed: {$attachmentGid} - " . $e->getMessage());
                $this->stats['errors'][] = "Failed to download: {$attachmentGid} - " . $e->getMessage();
                if (file_exists($tempPath)) unlink($tempPath);
            }
            
            usleep(ASANA_FILE_DELAY * 1000000);
        }
        
        log_info("[IMPORT_STORY_ATTACH] Imported {$imported} files");
        return $imported;
    }
    
    public function importAttachments($taskUuid, $asanaAttachments, $asanaClient) {
        if (empty($asanaAttachments)) return 0;
        
        if (!file_exists(TEMP_DOWNLOAD_DIR)) {
            mkdir(TEMP_DOWNLOAD_DIR, 0777, true);
        }
        
        if (!file_exists(TASKS_UPLOAD_DIR)) {
            mkdir(TASKS_UPLOAD_DIR, 0777, true);
        }
        
        $imported = 0;
        $currentUserUuid = $this->getCurrentUserUuid();
        
        foreach ($asanaAttachments as $attachment) {
            // ========== ПРОВЕРКА СЕМАФОРА ==========
            if (isImportCancelled()) {
                log_warning("[IMPORT_ATTACHMENTS] Import cancelled by user");
                throw new Exception("Import cancelled by user");
            }
            // ======================================
            
            $originalFileName = $attachment['name'] ?? '';
            $downloadUrl = $attachment['download_url'] ?? null;
            $attachmentGid = $attachment['gid'] ?? null;
            
            if (empty($attachmentGid)) {
                $attachmentGid = uniqid();
                log_warning("[IMPORT_ATTACHMENTS] No GID for attachment, generated: {$attachmentGid}");
            }
            
            if (!$downloadUrl && $attachmentGid) {
                try {
                    $attachmentDetail = $asanaClient->getAttachment($attachmentGid);
                    if ($attachmentDetail && !empty($attachmentDetail['download_url'])) {
                        $downloadUrl = $attachmentDetail['download_url'];
                        log_debug("[IMPORT_ATTACHMENTS] Got download URL from detail endpoint for {$attachmentGid}");
                    }
                } catch (Exception $e) {
                    log_warning("[IMPORT_ATTACHMENTS] Could not fetch attachment detail: " . $e->getMessage());
                }
            }
            
            if (!$downloadUrl) {
                log_warning("[IMPORT_ATTACHMENTS] No download URL for attachment {$attachmentGid}, skipping");
                continue;
            }
            
            $logStmt = $this->db->prepare("SELECT file_id FROM asana_import_log WHERE asana_gid = ? AND type = 'file' LIMIT 1");
            $logStmt->bind_param("s", $attachmentGid);
            $logStmt->execute();
            $logResult = $logStmt->get_result()->fetch_assoc();
            
            if ($logResult && !empty($logResult['file_id']) && $this->importMode !== IMPORT_MODE_UPDATE) {
                $checkLink = $this->db->prepare("SELECT 1 FROM task_files WHERE task_uuid = ? AND file_uuid = ?");
                $checkLink->bind_param("ss", $taskUuid, $logResult['file_id']);
                $checkLink->execute();
                if ($checkLink->get_result()->num_rows === 0) {
                    $linkStmt = $this->db->prepare("INSERT IGNORE INTO task_files (task_uuid, file_uuid) VALUES (?, ?)");
                    $linkStmt->bind_param("ss", $taskUuid, $logResult['file_id']);
                    $linkStmt->execute();
                }
                $imported++;
                continue;
            }
            
            if ($this->importMode === IMPORT_MODE_DRY_RUN) {
                $imported++;
                continue;
            }
            
            $tempPath = TEMP_DOWNLOAD_DIR . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFileName ?: 'file');
            
            try {
                log_debug("[IMPORT_ATTACHMENTS] Downloading: {$originalFileName} (GID: {$attachmentGid})");
                $fileSize = $asanaClient->downloadFile($downloadUrl, $tempPath);
                
                if ($fileSize > 0) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $tempPath);
                    finfo_close($finfo);
                    
                    $ext = $this->getExtension($tempPath, $mime, $originalFileName);
                    
                    $sanitizedGid = preg_replace('/[^a-zA-Z0-9_-]/', '', $attachmentGid);
                    $storageName = 'asana_' . $sanitizedGid;
                    
                    if (!empty($originalFileName)) {
                        $originalBase = pathinfo($originalFileName, PATHINFO_FILENAME);
                        $sanitizedOriginal = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalBase);
                        if (strlen($sanitizedOriginal) > 100) {
                            $sanitizedOriginal = substr($sanitizedOriginal, 0, 100);
                        }
                        if (!empty($sanitizedOriginal)) {
                            $storageName .= '_' . $sanitizedOriginal;
                        }
                        log_debug("[IMPORT_ATTACHMENTS] Generated name with original: {$storageName}");
                    } else {
                        log_debug("[IMPORT_ATTACHMENTS] Generated name without original (GID only): {$storageName}");
                    }
                    
                    $storageName .= $ext;
                    $targetPath = TASKS_UPLOAD_DIR . $storageName;
                    
                    if (!rename($tempPath, $targetPath)) {
                        throw new Exception("Failed to move file to {$targetPath}");
                    }
                    
                    $fileTime = null;
                    if (!empty($attachment['created_at'])) {
                        $fileTime = $this->convertAsanaDate($attachment['created_at']);
                    }
                    if (!$fileTime) {
                        $fileTime = msgql_now_ms();
                    }
                    
                    $stamp = date('d.m.Y H:i:s', (int)($fileTime / 1000));
                    $timeStr = (string)$fileTime;
                    
                    if (empty($originalFileName)) {
                        $origNameForDb = $storageName;
                        log_debug("[IMPORT_ATTACHMENTS] No original name, using storage_name: {$origNameForDb}");
                    } else {
                        $origNameForDb = $originalFileName;
                    }
                    
                    $fileUuid = msgql_uuid_v4();
                    $stmt = $this->db->prepare("
                        INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssssisss", $fileUuid, $origNameForDb, $storageName, $mime, $fileSize, $currentUserUuid, $timeStr, $stamp);
                    $stmt->execute();
                    
                    $linkStmt = $this->db->prepare("INSERT INTO task_files (task_uuid, file_uuid) VALUES (?, ?)");
                    $linkStmt->bind_param("ss", $taskUuid, $fileUuid);
                    $linkStmt->execute();
                    
                    $this->logImport('file', $attachmentGid, $fileUuid, $origNameForDb);
                    
                    $imported++;
                    $this->stats['files']++;
                    log_info("[IMPORT_ATTACHMENTS] Imported: {$storageName} ({$fileSize} bytes)");
                } else {
                    log_warning("[IMPORT_ATTACHMENTS] Downloaded file size is 0 for GID {$attachmentGid}");
                    if (file_exists($tempPath)) unlink($tempPath);
                }
            } catch (Exception $e) {
                log_error("[IMPORT_ATTACHMENTS] Failed: {$attachmentGid} - " . $e->getMessage());
                $this->stats['errors'][] = $e->getMessage();
            }
            
            if (file_exists($tempPath)) unlink($tempPath);
            usleep(ASANA_FILE_DELAY * 1000000);
        }
        
        return $imported;
    }
    
    private function getExtension($path, $mime, $origName) {
        $map = [
            'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif',
            'image/webp' => '.webp', 'application/pdf' => '.pdf', 'application/zip' => '.zip',
            'text/plain' => '.txt', 'text/html' => '.html', 'video/mp4' => '.mp4',
            'application/msword' => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            'application/vnd.ms-excel' => '.xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx'
        ];
        
        if (isset($map[$mime])) return $map[$mime];
        
        if (strpos($origName, '.') !== false) {
            $ext = substr($origName, strrpos($origName, '.'));
            if (strlen($ext) <= 5 && strlen($ext) >= 2) return $ext;
        }
        
        $handle = fopen($path, 'rb');
        $bytes = fread($handle, 12);
        fclose($handle);
        
        if (substr($bytes, 0, 4) === "\x89PNG") return '.png';
        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return '.jpg';
        if (substr($bytes, 0, 4) === 'GIF8') return '.gif';
        if (substr($bytes, 0, 4) === '%PDF') return '.pdf';
        if (substr($bytes, 0, 2) === 'PK') return '.zip';
        
        return '.bin';
    }
    
    private function logImport($type, $asanaGid, $localId, $title, $taskId = null, $messageId = null) {
        $stmt = $this->db->prepare("
            INSERT INTO asana_import_log (asana_gid, type, project_id, task_id, message_id, file_id, title, imported_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                project_id = VALUES(project_id),
                task_id = VALUES(task_id),
                message_id = VALUES(message_id),
                file_id = VALUES(file_id),
                title = VALUES(title),
                imported_at = VALUES(imported_at)
        ");
        
        $projectId = $taskIdDb = $messageIdDb = $fileId = null;
        
        switch ($type) {
            case 'project': 
                $projectId = $localId; 
                break;
            case 'task': 
                $taskIdDb = $localId; 
                if ($taskId !== null) {
                    $projectId = $taskId;
                }
                break;
            case 'message': 
                $messageIdDb = $localId; 
                $taskIdDb = $taskId; 
                break;
            case 'file': 
                $fileId = $localId; 
                $taskIdDb = $taskId; 
                $messageIdDb = $messageId; 
                break;
        }
        
        $now = msgql_now_ms();
        $stmt->bind_param("ssssssss", $asanaGid, $type, $projectId, $taskIdDb, $messageIdDb, $fileId, $title, $now);
        $stmt->execute();
        
        log_debug("[LOG_IMPORT] Logged {$type}: {$asanaGid} -> {$localId}");
    }
}