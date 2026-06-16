<?php
//projects.php ver.4.4 - ИСПРАВЛЕНА УСТАНОВКА ПРОЕКТА В МОДАЛЬНОМ ОКНЕ ЗАДАЧ
//ver.4.0 - ОБЪЕДИНЕНЫ ЗАПРОСЫ: файлы и подписки загружаются одним запросом
//ver.4.0 - УСТРАНЕНА МНОЖЕСТВЕННАЯ ЗАГРУЗКА ФАЙЛОВ (было до 30 запросов на страницу)
//ver.4.0 - УСТРАНЕНА МНОЖЕСТВЕННАЯ ПРОВЕРКА ПОДПИСОК (было до 50 запросов)
//ver.4.0 - ДОБАВЛЕНА ОЧЕРЕДЬ ЗАГРУЗКИ ПОДЗАДАЧ (не более 1 одновременной)
//ver.4.0 - СОХРАНЕНИЕ per_page в localStorage (уже было)
//ver.4.2 - ОПТИМИЗИРОВАН ПЕРЕХОД ПО ССЫЛКЕ НА ЗАДАЧУ
//ver.4.2 - ДОБАВЛЕНА ФУНКЦИЯ get_task_page_info ДЛЯ ПОЛУЧЕНИЯ НОМЕРА СТРАНИЦЫ ЗАДАЧИ ОДНИМ ЗАПРОСОМ
//ver.4.2 - УСТРАНЕНА ДВОЙНАЯ ЗАГРУЗКА (сначала первая страница, потом поиск)
//ver.4.2 - ТЕПЕРЬ СРАЗУ ЗАГРУЖАЕТСЯ НУЖНАЯ СТРАНИЦА С ЗАДАЧЕЙ
//ver.4.2 - ДОБАВЛЕНО АВТОМАТИЧЕСКОЕ РАСКРЫТИЕ ЦЕПОЧКИ РОДИТЕЛЕЙ ПРИ ПЕРЕХОДЕ ПО ССЫЛКЕ
//ver.4.3 - ДОБАВЛЕНО ОТСЛЕЖИВАНИЕ ВЫДЕЛЕНИЯ ТЕКСТА ДЛЯ КОРРЕКТНОГО КОПИРОВАНИЯ
//ver.4.3 - ИСПРАВЛЕНА ПРОБЛЕМА, КОГДА ВЫДЕЛЕНИЕ ТЕКСТА В ОПИСАНИИ ПРИВОДИЛО К КОПИРОВАНИЮ ССЫЛКИ
//ver.4.4 - ИСПРАВЛЕНА УСТАНОВКА ПРОЕКТА В МОДАЛЬНОМ ОКНЕ ЗАДАЧ
//projects.php ver.4.9 - ДОБАВЛЕН ПОКАЗ ИЕРАРХИИ ПРИ ФИЛЬТРАЦИИ ПОДЗАДАЧ

ini_set('log_errors', 1);
error_reporting(E_ALL);

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if ((isset($_REQUEST['ajax_mode']) && $_REQUEST['ajax_mode'] == 1) || isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

msgql_require_login();

// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

require_once __DIR__ . '/lib/smtp_func.php';
require_once __DIR__ . '/lib/notification_center.php';

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
log_debug("[DEBUG] current_user_uuid: " . $current_user_uuid);
$is_admin = msgql_is_admin();


// // Обновляем время последнего просмотра дашборда (для сброса бейджей)
// $now_str = (string)msgql_now_ms();
// $update_stmt = $db->prepare("UPDATE users SET time_last_dashboard_view = ? WHERE uuid = ?");
// $update_stmt->bind_param("ss", $now_str, $current_user_uuid);
// $update_stmt->execute();
// $update_stmt->close();

$task_upload_dir = __DIR__ . '/uploads/tasks/';
if (!file_exists($task_upload_dir)) {
    mkdir($task_upload_dir, 0777, true);
}

$selected_task_uuid = $_GET['task'] ?? '';
$selected_project_uuid = null;
$highlight_project_uuid = $_GET['project'] ?? null;

if ($selected_task_uuid) {
    $stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $stmt->bind_param("s", $selected_task_uuid);
    $stmt->execute();
    $task_info = $stmt->get_result()->fetch_assoc();
    if ($task_info) {
        $selected_project_uuid = $task_info['project_uuid'];
    }
    $stmt->close();
}

// Получение последнего активного проекта
function get_last_active_project($user_uuid, $is_admin, $db) {
    log_debug("[GET_LAST_ACTIVE_PROJECT] Called for user: {$user_uuid}");
    
    if ($is_admin) {
        $stmt = $db->prepare("
            SELECT p.uuid, p.title, MAX(COALESCE(m.time, t.time, p.time)) as last_activity
            FROM projects p
            LEFT JOIN tasks t ON p.uuid = t.project_uuid
            LEFT JOIN messages m ON t.uuid = m.task_uuid
            GROUP BY p.uuid
            ORDER BY last_activity DESC
            LIMIT 1
        ");
    } else {
        $stmt = $db->prepare("
            SELECT p.uuid, p.title, MAX(COALESCE(m.time, t.time, p.time)) as last_activity
            FROM projects p
            LEFT JOIN tasks t ON p.uuid = t.project_uuid
            LEFT JOIN messages m ON t.uuid = m.task_uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE p.created_by_uuid = ? OR upp.can_view = 1
            GROUP BY p.uuid
            ORDER BY last_activity DESC
            LIMIT 1
        ");
        $stmt->bind_param("ss", $user_uuid, $user_uuid);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();
    
    log_debug("[GET_LAST_ACTIVE_PROJECT] Found project: " . ($project['uuid'] ?? 'none'));
    return $project;
}

$default_project_uuid = null;
if ($selected_project_uuid) {
    $default_project_uuid = $selected_project_uuid;
    log_debug("[INIT] Using project from URL parameter: {$default_project_uuid}");
} elseif ($highlight_project_uuid) {
    $default_project_uuid = $highlight_project_uuid;
    log_debug("[INIT] Using project from highlight parameter: {$default_project_uuid}");
} else {
    $last_active = get_last_active_project($current_user_uuid, $is_admin, $db);
    if ($last_active && !empty($last_active['uuid'])) {
        $default_project_uuid = $last_active['uuid'];
        log_debug("[INIT] Using last active project: {$default_project_uuid}");
    }
}



// ==================== BLOCK START: upload_quota_helper v1.0 ====================
// ==================== BLOCK START: check_user_upload_quota v1.1 ====================
// ver.1.0 (2026-06-05) - ПРОВЕРКА ДНЕВНОЙ КВОТЫ ПОЛЬЗОВАТЕЛЯ
// ver.1.1 (2026-06-05) - ИСПРАВЛЕН ТИП ПАРАМЕТРА (string для времени)

function check_user_upload_quota(string $user_uuid, mysqli $db): array {
    $max_files_per_day = 100;
    $max_size_per_day = 500 * 1024 * 1024; // 500 MB
    
    $today_start = strtotime('today 00:00:00') * 1000;
    $today_start_str = (string)$today_start;
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_size
        FROM files
        WHERE uploaded_by_uuid = ? AND time >= ?
    ");
    
    // v1.1: Оба параметра - строки (UUID и timestamp как string)
    $stmt->bind_param("ss", $user_uuid, $today_start_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $file_count = (int)($result['file_count'] ?? 0);
    $total_size = (int)($result['total_size'] ?? 0);
    
    $size_mb = round($total_size / 1048576, 2);
    log_debug("[UPLOAD_QUOTA] User {$user_uuid} - files today: {$file_count}, size today: {$size_mb} MB");
    
    if ($file_count >= $max_files_per_day) {
        log_warning("[UPLOAD_QUOTA] User {$user_uuid} exceeded daily file limit: {$file_count}/{$max_files_per_day}");
        return [
            'allowed' => false,
            'reason' => 'daily_file_limit',
            'message' => "Превышен лимит количества файлов в день ({$max_files_per_day})"
        ];
    }
    
    if ($total_size >= $max_size_per_day) {
        log_warning("[UPLOAD_QUOTA] User {$user_uuid} exceeded daily size limit: {$size_mb} MB / " . round($max_size_per_day / 1048576, 2) . " MB");
        return [
            'allowed' => false,
            'reason' => 'daily_size_limit',
            'message' => "Превышен лимит общего размера файлов в день (" . round($max_size_per_day / 1048576, 2) . " MB)"
        ];
    }
    
    return ['allowed' => true, 'reason' => '', 'message' => ''];
}
// ==================== BLOCK END: check_user_upload_quota v1.1 ====================


/**
 * Генерирует уникальное имя файла с проверкой на существование
 * 
 * @param string $original_name Оригинальное имя файла
 * @param string $prefix Префикс (msg, task)
 * @param string $upload_dir Директория для проверки существования
 * @param int $max_attempts Максимальное количество попыток
 * @return string|false Уникальное имя файла или false при ошибке
 */
// ==================== BLOCK START: generate_unique_filename v1.1 ====================
// ver.1.0 (2026-06-05) - ГЕНЕРАЦИЯ УНИКАЛЬНОГО ИМЕНИ ФАЙЛА
// ver.1.1 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА ДИРЕКТОРИИ И УЛУЧШЕНА ОБРАБОТКА ОШИБОК

function generate_unique_filename(string $original_name, string $prefix, string $upload_dir, int $max_attempts = 10) {
    // v1.1: Проверка существования директории
    if (!is_dir($upload_dir)) {
        log_error("[FILE_NAME] Upload directory does not exist: {$upload_dir}");
        if (!mkdir($upload_dir, 0777, true)) {
            log_error("[FILE_NAME] Failed to create upload directory: {$upload_dir}");
            return false;
        }
        log_debug("[FILE_NAME] Created upload directory: {$upload_dir}");
    }
    
    $ext = pathinfo($original_name, PATHINFO_EXTENSION);
    $ext = $ext ? '.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) : '';
    if (strlen($ext) > 10) {
        $ext = '';
        log_debug("[FILE_NAME] Extension too long, removed");
    }
    
    $date = date('Ymd_His');
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix);
    if (strlen($prefix) > 20) {
        $prefix = substr($prefix, 0, 20);
        log_debug("[FILE_NAME] Prefix truncated to 20 chars");
    }
    
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $random = bin2hex(random_bytes(32));
        $filename = sprintf('%s_%s_%s%s', $prefix, $date, $random, $ext);
        
        if (!file_exists($upload_dir . $filename)) {
            log_debug("[FILE_NAME] Generated unique filename after {$attempt} attempts: {$filename}");
            return $filename;
        }
        
        log_debug("[FILE_NAME] Collision detected for: {$filename}, retrying...");
    }
    
    log_error("[FILE_NAME] Failed to generate unique filename after {$max_attempts} attempts");
    return false;
}
// ==================== BLOCK END: generate_unique_filename v1.1 ====================
// ==================== BLOCK END: upload_quota_helper v1.0 ====================


// Рекурсивное удаление задачи
// ==================== BLOCK START: delete_task_recursive v2.0 (with depth protection) ====================
// ver.1.0 - Базовая версия
// ver.2.0 (2026-06-05) - ДОБАВЛЕНА ЗАЩИТА ОТ ГЛУБОКОЙ РЕКУРСИИ
// - Максимальная глубина рекурсии 100 уровней
// - Логирование при превышении глубины
// - Возвращает false при ошибке вместо падения

function delete_task_recursive($task_uuid, $db, $depth = 0) {
    // v2.0: Защита от переполнения стека при глубокой вложенности
    $max_depth = 100;
    if ($depth > $max_depth) {
        log_error("[DELETE_TASK_RECURSIVE] MAX DEPTH EXCEEDED ({$depth}) for task: {$task_uuid}");
        log_debug("[DELETE_TASK_RECURSIVE] Possible circular reference or extremely deep nesting");
        return false;
    }
    
    log_debug("[DELETE_TASK_RECURSIVE] Starting deletion of task: {$task_uuid} (depth: {$depth})");
    
    // Получаем подзадачи с пагинацией, чтобы не загружать сразу все
    $sub_stmt = $db->prepare("SELECT uuid FROM tasks WHERE parent_task_uuid = ?");
    $sub_stmt->bind_param("s", $task_uuid);
    $sub_stmt->execute();
    $subtasks = $sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sub_stmt->close();
    
    $subtask_count = count($subtasks);
    log_debug("[DELETE_TASK_RECURSIVE] Found {$subtask_count} subtasks for task: {$task_uuid}");
    
    // Рекурсивно удаляем подзадачи
    foreach ($subtasks as $subtask) {
        $result = delete_task_recursive($subtask['uuid'], $db, $depth + 1);
        if ($result === false) {
            log_error("[DELETE_TASK_RECURSIVE] Failed to delete subtask: {$subtask['uuid']}");
            // Продолжаем удаление остальных подзадач
        }
    }
    
    // Получаем сообщения задачи
    $msgs_stmt = $db->prepare("SELECT uuid FROM messages WHERE task_uuid = ?");
    $msgs_stmt->bind_param("s", $task_uuid);
    $msgs_stmt->execute();
    $messages = $msgs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $msgs_stmt->close();
    
    log_debug("[DELETE_TASK_RECURSIVE] Found " . count($messages) . " messages for task: {$task_uuid}");
    
    foreach ($messages as $msg) {
        // Получаем файлы сообщения
        $files_stmt = $db->prepare("SELECT file_uuid FROM message_files WHERE message_uuid = ?");
        $files_stmt->bind_param("s", $msg['uuid']);
        $files_stmt->execute();
        $file_uuids = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $files_stmt->close();
        
        // Удаляем связи сообщение-файл
        $del_mf = $db->prepare("DELETE FROM message_files WHERE message_uuid = ?");
        $del_mf->bind_param("s", $msg['uuid']);
        $del_mf->execute();
        $del_mf->close();
        
        // Проверяем каждый файл на наличие других ссылок
        foreach ($file_uuids as $f) {
            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
            $check_msg->bind_param("s", $f['file_uuid']);
            $check_msg->execute();
            $msg_refs = $check_msg->get_result()->fetch_assoc()['cnt'];
            $check_msg->close();
            
            $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
            $check_task->bind_param("s", $f['file_uuid']);
            $check_task->execute();
            $task_refs = $check_task->get_result()->fetch_assoc()['cnt'];
            $check_task->close();
            
            // Если нет ссылок - удаляем файл с диска
            if ($msg_refs == 0 && $task_refs == 0) {
                $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                $file_stmt->bind_param("s", $f['file_uuid']);
                $file_stmt->execute();
                $file_row = $file_stmt->get_result()->fetch_assoc();
                $file_stmt->close();
                
                if ($file_row) {
                    $paths = [
                        __DIR__ . '/uploads/messages/' . $file_row['storage_name'],
                        __DIR__ . '/uploads/tasks/' . $file_row['storage_name']
                    ];
                    foreach ($paths as $path) {
                        if (file_exists($path)) {
                            if (@unlink($path)) {
                                log_debug("[DELETE_TASK_RECURSIVE] Deleted file: {$path}");
                            } else {
                                log_error("[DELETE_TASK_RECURSIVE] Failed to delete file: {$path}");
                            }
                        }
                    }
                }
                
                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                $del_file->bind_param("s", $f['file_uuid']);
                $del_file->execute();
                $del_file->close();
                log_debug("[DELETE_TASK_RECURSIVE] Deleted file record: {$f['file_uuid']}");
            }
        }
        
        // Удаляем сообщение
        $del_msg = $db->prepare("DELETE FROM messages WHERE uuid = ?");
        $del_msg->bind_param("s", $msg['uuid']);
        $del_msg->execute();
        $del_msg->close();
        log_debug("[DELETE_TASK_RECURSIVE] Deleted message: {$msg['uuid']}");
    }
    
    // Получаем файлы задачи
    $files_stmt = $db->prepare("SELECT f.uuid, f.storage_name FROM files f
        JOIN task_files tf ON f.uuid = tf.file_uuid
        WHERE tf.task_uuid = ?");
    $files_stmt->bind_param("s", $task_uuid);
    $files_stmt->execute();
    $files = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $files_stmt->close();
    
    // Удаляем связи задача-файл
    $del_tf = $db->prepare("DELETE FROM task_files WHERE task_uuid = ?");
    $del_tf->bind_param("s", $task_uuid);
    $del_tf->execute();
    $del_tf->close();
    
    // Проверяем файлы задачи на другие ссылки
    foreach ($files as $file) {
        $check_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
        $check_stmt->bind_param("s", $file['uuid']);
        $check_stmt->execute();
        $refs = $check_stmt->get_result()->fetch_assoc()['cnt'];
        $check_stmt->close();
        
        $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
        $check_msg->bind_param("s", $file['uuid']);
        $check_msg->execute();
        $msg_refs = $check_msg->get_result()->fetch_assoc()['cnt'];
        $check_msg->close();
        
        if ($refs == 0 && $msg_refs == 0) {
            $file_path = __DIR__ . '/uploads/tasks/' . $file['storage_name'];
            if (file_exists($file_path)) {
                if (@unlink($file_path)) {
                    log_debug("[DELETE_TASK_RECURSIVE] Deleted task file: {$file_path}");
                } else {
                    log_error("[DELETE_TASK_RECURSIVE] Failed to delete task file: {$file_path}");
                }
            }
            
            $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
            $del_file->bind_param("s", $file['uuid']);
            $del_file->execute();
            $del_file->close();
            log_debug("[DELETE_TASK_RECURSIVE] Deleted file record: {$file['uuid']}");
        }
    }
    
    // Удаляем саму задачу
    $del_task = $db->prepare("DELETE FROM tasks WHERE uuid = ?");
    $del_task->bind_param("s", $task_uuid);
    $del_task->execute();
    $del_task->close();
    
    log_debug("[DELETE_TASK_RECURSIVE] Task deleted: {$task_uuid} (depth: {$depth})");
    return true;
}
// ==================== BLOCK END: delete_task_recursive v2.0 ====================

// Рекурсивное удаление проекта
function delete_project_recursive($project_uuid, $db) {
    log_debug("[DELETE_PROJECT_RECURSIVE] Starting deletion of project: {$project_uuid}");
    
    $files_stmt = $db->prepare("
        SELECT DISTINCT f.uuid, f.storage_name 
        FROM files f
        WHERE f.uuid IN (
            SELECT tf.file_uuid FROM task_files tf
            JOIN tasks t ON tf.task_uuid = t.uuid
            WHERE t.project_uuid = ?
        ) OR f.uuid IN (
            SELECT mf.file_uuid FROM message_files mf
            JOIN messages m ON mf.message_uuid = m.uuid
            JOIN tasks t ON m.task_uuid = t.uuid
            WHERE t.project_uuid = ?
        )
    ");
    $files_stmt->bind_param("ss", $project_uuid, $project_uuid);
    $files_stmt->execute();
    $all_files = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $files_stmt->close();
    
    $tasks_stmt = $db->prepare("SELECT uuid FROM tasks WHERE project_uuid = ?");
    $tasks_stmt->bind_param("s", $project_uuid);
    $tasks_stmt->execute();
    $tasks = $tasks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $tasks_stmt->close();
    
    foreach ($tasks as $task) {
        delete_task_recursive($task['uuid'], $db);
    }
    
    foreach ($all_files as $file) {
        $paths = [
            __DIR__ . '/uploads/messages/' . $file['storage_name'],
            __DIR__ . '/uploads/tasks/' . $file['storage_name']
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
                log_debug("[DELETE_PROJECT_RECURSIVE] Deleted file: {$path}");
            }
        }
        
        $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
        $del_file->bind_param("s", $file['uuid']);
        $del_file->execute();
        $del_file->close();
    }
    
    $del_perm = $db->prepare("DELETE FROM user_project_permissions WHERE project_uuid = ?");
    $del_perm->bind_param("s", $project_uuid);
    $del_perm->execute();
    $del_perm->close();
    
    $del_proj = $db->prepare("DELETE FROM projects WHERE uuid = ?");
    $del_proj->bind_param("s", $project_uuid);
    $del_proj->execute();
    $del_proj->close();
    
    log_debug("[DELETE_PROJECT_RECURSIVE] Project deleted: {$project_uuid}");
}

// AJAX-обработчик
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['ajax_mode']) && $_POST['ajax_mode'] == 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error: ' . $error['message']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });
    
    $response = ['success' => false, 'error' => ''];
    $action = $_POST['action'] ?? '';
    
    $mutating_actions = [
        'create_project', 'edit_project', 'delete_project',
        'create_task', 'edit_task', 'toggle_task_status', 'delete_task', 'move_task',
        'attach_file_to_task', 'detach_file_from_task', 'upload_task_file',
        'toggle_task_subscription'
    ];
    if (in_array($action, $mutating_actions)) {
        msgql_csrf_check_and_exit();
    }
    
    log_debug("[AJAX] Action: {$action}");
    
    try {
        // ==================== BLOCK START: ajax_get_project_tasks_sorted v4.12 ====================
        // ver.4.5 - Базовый вызов с parent_uuid
        // ver.4.6 (2026-06-02) - ИСПРАВЛЕНА ПЕРЕДАЧА $user_uuid
        // ver.4.9 (2026-06-03) - ДОБАВЛЕНА ПОДДЕРЖКА ПОКАЗА ИЕРАРХИИ ПРИ ФИЛЬТРАЦИИ ПОДЗАДАЧ
        // ver.4.10 (2026-06-03) - ИСПРАВЛЕНА ЛОГИКА ПОКАЗА РОДИТЕЛЕЙ
        // ver.4.12 (2026-06-03) - ОПТИМИЗИРОВАН ПОКАЗ: только родители + раскрытые подзадачи
        // - При фильтрации возвращаются только родительские задачи
        // - Подзадачи загружаются через обычный механизм loadSubtasks
        // - Родители автоматически раскрываются, показывая подзадачи, соответствующие фильтру

        if ($action === 'get_project_tasks_sorted') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            $parent_uuid = $_POST['parent_uuid'] ?? null;
            $page = max(1, (int)($_POST['page'] ?? 1));
            $per_page = min(1000, max(5, (int)($_POST['per_page'] ?? 10)));
            $sort_by = $_POST['sort_by'] ?? 'last_activity';
            $sort_dir = ($_POST['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
            
            $filter_statuses = [];
            if (isset($_POST['filter_statuses']) && !empty($_POST['filter_statuses'])) {
                $filter_statuses = json_decode($_POST['filter_statuses'], true);
                if (!is_array($filter_statuses)) $filter_statuses = [];
            }
            
            $filter_assigned = [];
            if (isset($_POST['filter_assigned']) && !empty($_POST['filter_assigned'])) {
                $filter_assigned = json_decode($_POST['filter_assigned'], true);
                if (!is_array($filter_assigned)) $filter_assigned = [];
            }
            
            $search = trim($_POST['search'] ?? '');
            
            $is_subtask_request = isset($parent_uuid) && !empty($parent_uuid);
            
            log_debug("[AJAX] get_project_tasks_sorted v4.12: project_uuid={$project_uuid}, parent_uuid=" . ($parent_uuid ?? 'NULL'));
            log_debug("[AJAX] filter_statuses: " . json_encode($filter_statuses));
            log_debug("[AJAX] filter_assigned: " . json_encode($filter_assigned));
            log_debug("[AJAX] search: '{$search}'");
            
            if (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
            } elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                $response['error'] = 'Нет доступа к проекту';
            } else {
                // Для запроса подзадач используем специальную функцию
                if ($is_subtask_request) {
                    $result = get_project_subtasks_sorted($project_uuid, $parent_uuid, $current_user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db);
                    $response['success'] = true;
                    $response['tasks'] = $result['tasks'];
                    $response['total'] = $result['total'];
                    $response['page'] = $page;
                    $response['per_page'] = $per_page;
                    $response['has_more'] = $result['has_more'];
                    
                    // Загружаем подписки для задач
                    if (!empty($result['tasks'])) {
                        $task_uuids = array_column($result['tasks'], 'uuid');
                        $subscriptions = [];
                        $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
                        $sub_stmt = $db->prepare("
                            SELECT task_uuid, is_active 
                            FROM task_subscribers 
                            WHERE task_uuid IN ($placeholders) AND user_uuid = ?
                        ");
                        $sub_params = array_merge($task_uuids, [$current_user_uuid]);
                        $sub_types = str_repeat('s', count($task_uuids)) . 's';
                        $sub_stmt->bind_param($sub_types, ...$sub_params);
                        $sub_stmt->execute();
                        $sub_result = $sub_stmt->get_result();
                        while ($row = $sub_result->fetch_assoc()) {
                            $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
                        }
                        $sub_stmt->close();
                        
                        foreach ($response['tasks'] as &$task) {
                            $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
                        }
                    }
                } else {
                    // Для корневого запроса: определяем, есть ли фильтры
                    $has_filters = !empty($filter_statuses) || !empty($filter_assigned) || !empty($search);
                    
                    if ($has_filters) {
                        // v4.12: При фильтрации - сначала находим ВСЕ задачи (включая подзадачи), соответствующие фильтру,
                        // затем собираем их родителей, и возвращаем ТОЛЬКО родителей (уникальных)
                        log_debug("[AJAX] v4.12: Filter mode - finding parent tasks that have matching subtasks");
                        
                        // 1. Находим все задачи (включая подзадачи), соответствующие фильтру
                        $matching_tasks = get_all_tasks_matching_filters($project_uuid, $current_user_uuid, $filter_statuses, $filter_assigned, $search, $is_admin, $db);
                        
                        log_debug("[AJAX] Found " . count($matching_tasks) . " tasks matching filters");
                        
                        // 2. Собираем уникальных родителей (включая тех, у кого подзадачи совпадают с фильтром)
                        // Также включаем сами задачи, если они корневые
                        $parent_uuids = [];
                        $root_task_uuids = [];
                        
                        foreach ($matching_tasks as $mt) {
                            if (empty($mt['parent_task_uuid'])) {
                                // Это корневая задача - добавляем её саму
                                if (!in_array($mt['uuid'], $root_task_uuids)) {
                                    $root_task_uuids[] = $mt['uuid'];
                                }
                            } else {
                                // Это подзадача - добавляем её родителя
                                if (!in_array($mt['parent_task_uuid'], $parent_uuids)) {
                                    $parent_uuids[] = $mt['parent_task_uuid'];
                                }
                            }
                        }
                        
                        // 3. Загружаем всех родителей (которые не являются подзадачами других)
                        $all_parent_uuids = array_merge($parent_uuids, $root_task_uuids);
                        $all_parent_uuids = array_unique($all_parent_uuids);
                        
                        log_debug("[AJAX] Unique parent/root tasks to display: " . count($all_parent_uuids));
                        
                        $parent_tasks = [];
                        if (!empty($all_parent_uuids)) {
                            // Загружаем данные родителей
                            $placeholders = implode(',', array_fill(0, count($all_parent_uuids), '?'));
                            $parent_sql = "SELECT t.*, 
                                    u.name as assignee_name, 
                                    u.login as assignee_login,
                                    (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                                    (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                                    (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                                    (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count
                                  FROM tasks t
                                  LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                                  WHERE t.uuid IN ($placeholders) AND t.project_uuid = ?";
                            
                            $parent_params = array_merge($all_parent_uuids, [$project_uuid]);
                            $parent_types = str_repeat('s', count($all_parent_uuids)) . 's';
                            
                            $parent_stmt = $db->prepare($parent_sql);
                            $parent_stmt->bind_param($parent_types, ...$parent_params);
                            $parent_stmt->execute();
                            $parent_tasks = $parent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $parent_stmt->close();
                            
                            foreach ($parent_tasks as &$pt) {
                                $pt['can_edit'] = msgql_can_edit_task($current_user_uuid, $pt['uuid']);
                                $pt['messages_count'] = (int)($pt['messages_count'] ?? 0);
                                $pt['subtasks_count'] = (int)($pt['subtasks_count'] ?? 0);
                                $pt['files_count'] = (int)($pt['files_count'] ?? 0);
                                $pt['status'] = (int)($pt['status'] ?? 0);
                                $pt['is_parent_context'] = false;
                                $pt['has_filtered_children'] = in_array($pt['uuid'], $parent_uuids);
                            }
                        }
                        
                        // 4. Сортируем родительские задачи по last_activity
                        usort($parent_tasks, function($a, $b) {
                            $timeA = $a['last_msg_time'] ?? $a['time'] ?? 0;
                            $timeB = $b['last_msg_time'] ?? $b['time'] ?? 0;
                            return $timeB - $timeA;
                        });
                        
                        // Пагинация для родительских задач
                        $total_parents = count($parent_tasks);
                        $offset = ($page - 1) * $per_page;
                        $parent_tasks_paged = array_slice($parent_tasks, $offset, $per_page);
                        
                        log_debug("[AJAX] Returning " . count($parent_tasks_paged) . " parent tasks (total: {$total_parents})");
                        
                        // Загружаем подписки
                        $subscriptions = [];
                        if (!empty($parent_tasks_paged)) {
                            $task_uuids = array_column($parent_tasks_paged, 'uuid');
                            $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
                            $sub_stmt = $db->prepare("
                                SELECT task_uuid, is_active 
                                FROM task_subscribers 
                                WHERE task_uuid IN ($placeholders) AND user_uuid = ?
                            ");
                            $sub_params = array_merge($task_uuids, [$current_user_uuid]);
                            $sub_types = str_repeat('s', count($task_uuids)) . 's';
                            $sub_stmt->bind_param($sub_types, ...$sub_params);
                            $sub_stmt->execute();
                            $sub_result = $sub_stmt->get_result();
                            while ($row = $sub_result->fetch_assoc()) {
                                $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
                            }
                            $sub_stmt->close();
                            
                            foreach ($parent_tasks_paged as &$task) {
                                $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
                            }
                        }
                        
                        $response['success'] = true;
                        $response['tasks'] = $parent_tasks_paged;
                        $response['total'] = $total_parents;
                        $response['page'] = $page;
                        $response['per_page'] = $per_page;
                        $response['has_more'] = ($offset + $per_page) < $total_parents;
                        $response['has_filtered_children'] = true;
                        
                    } else {
                        // Без фильтров - обычная загрузка корневых задач
                        $result = get_project_tasks_sorted($project_uuid, $current_user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db);
                        
                        $response['success'] = true;
                        $response['tasks'] = $result['tasks'];
                        $response['total'] = $result['total'];
                        $response['page'] = $page;
                        $response['per_page'] = $per_page;
                        $response['has_more'] = $result['has_more'];
                        $response['has_filtered_children'] = false;
                        
                        // Загружаем подписки
                        if (!empty($result['tasks'])) {
                            $task_uuids = array_column($result['tasks'], 'uuid');
                            $subscriptions = [];
                            $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
                            $sub_stmt = $db->prepare("
                                SELECT task_uuid, is_active 
                                FROM task_subscribers 
                                WHERE task_uuid IN ($placeholders) AND user_uuid = ?
                            ");
                            $sub_params = array_merge($task_uuids, [$current_user_uuid]);
                            $sub_types = str_repeat('s', count($task_uuids)) . 's';
                            $sub_stmt->bind_param($sub_types, ...$sub_params);
                            $sub_stmt->execute();
                            $sub_result = $sub_stmt->get_result();
                            while ($row = $sub_result->fetch_assoc()) {
                                $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
                            }
                            $sub_stmt->close();
                            
                            foreach ($response['tasks'] as &$task) {
                                $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
                            }
                        }
                    }
                }
                
                log_debug("[AJAX] Returning " . count($response['tasks'] ?? []) . " tasks");
            }
        }
        // ==================== BLOCK END: ajax_get_project_tasks_sorted v4.12 ====================

        // ==================== BLOCK START: get_project_users v1.0 ====================
        // ver.1.0 (2026-06-05) - ПОЛУЧЕНИЕ ПОЛЬЗОВАТЕЛЕЙ, ИМЕЮЩИХ ДОСТУП К ПРОЕКТУ
        elseif ($action === 'get_project_users') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            
            log_debug("[GET_PROJECT_USERS] Getting users for project: {$project_uuid}");
            
            if (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
                $response['users'] = [];
            } elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                $response['error'] = 'Нет доступа к проекту';
                $response['users'] = [];
            } else {
                $users = get_users_with_project_access($db, $project_uuid, $current_user_uuid, $is_admin);
                $response['success'] = true;
                $response['users'] = $users;
                log_debug("[GET_PROJECT_USERS] Found " . count($users) . " users for project: {$project_uuid}");
            }
        }
        // ==================== BLOCK END: get_project_users v1.0 ====================

        // Получение номера страницы для задачи (для прямой ссылки)
        elseif ($action === 'get_task_page_info') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $project_uuid = $_POST['project_uuid'] ?? '';
            $per_page = max(1, min(1000, (int)($_POST['per_page'] ?? 10)));
            $sort_by = $_POST['sort_by'] ?? 'last_activity';
            $sort_dir = ($_POST['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
            $filter_statuses = [];
            if (isset($_POST['filter_statuses']) && !empty($_POST['filter_statuses'])) {
                $filter_statuses = json_decode($_POST['filter_statuses'], true);
                if (!is_array($filter_statuses)) $filter_statuses = [];
            }
            $filter_assigned = [];
            if (isset($_POST['filter_assigned']) && !empty($_POST['filter_assigned'])) {
                $filter_assigned = json_decode($_POST['filter_assigned'], true);
                if (!is_array($filter_assigned)) $filter_assigned = [];
            }
            $search = trim($_POST['search'] ?? '');
            
            log_debug("[GET_TASK_PAGE_INFO] task_uuid: {$task_uuid}, project_uuid: {$project_uuid}, per_page: {$per_page}");
            
            if (empty($task_uuid) || empty($project_uuid)) {
                $response['error'] = 'Не указаны обязательные параметры';
            } elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                $response['error'] = 'Нет доступа к проекту';
            } else {
                // Проверяем, существует ли задача и принадлежит ли проекту
                $task_check = $db->prepare("SELECT uuid FROM tasks WHERE uuid = ? AND project_uuid = ?");
                $task_check->bind_param("ss", $task_uuid, $project_uuid);
                $task_check->execute();
                $task_exists = $task_check->get_result()->num_rows > 0;
                $task_check->close();
                
                if (!$task_exists) {
                    $response['error'] = 'Задача не найдена в указанном проекте';
                } else {
                    // Формируем WHERE условия (аналогично get_project_tasks_sorted)
                    $where_conditions = ["t.project_uuid = ?"];
                    $params = [$project_uuid];
                    $types = "s";
                    
                    if (!$is_admin) {
                        $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
                        $params[] = $current_user_uuid;
                        $types .= "s";
                    }
                    
                    if (!empty($filter_statuses) && is_array($filter_statuses)) {
                        $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
                        $where_conditions[] = "t.status IN ($status_placeholders)";
                        foreach ($filter_statuses as $status) {
                            $params[] = (int)$status;
                            $types .= "i";
                        }
                    }
                    
                    if (!empty($filter_assigned) && is_array($filter_assigned)) {
                        $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
                        $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
                        foreach ($filter_assigned as $assigned_uuid) {
                            $params[] = $assigned_uuid;
                            $types .= "s";
                        }
                    }
                    
                    if (!empty($search)) {
                        $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
                        $like = "%{$search}%";
                        $params[] = $like;
                        $params[] = $like;
                        $types .= "ss";
                    }
                    
                    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
                    
                    $order_map = [
                        'last_activity' => 'COALESCE(last_msg_time, t.time)',
                        'title' => 't.title',
                        'status' => 't.status',
                        'deadline' => 't.time_end_plan'
                    ];
                    $order_by = $order_map[$sort_by] ?? 'COALESCE(last_msg_time, t.time)';
                    $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';
                    
                    // Получаем общее количество задач
                    $count_sql = "SELECT COUNT(*) as total 
                                 FROM tasks t
                                 JOIN projects p ON t.project_uuid = p.uuid
                                 LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                                 LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                                 LEFT JOIN (
                                     SELECT task_uuid, MAX(time) as last_msg_time
                                     FROM messages
                                     GROUP BY task_uuid
                                 ) m ON t.uuid = m.task_uuid
                                 {$where_clause}";
                    
                    $count_params = array_merge([$current_user_uuid], $params);
                    $count_types = "s" . $types;
                    
                    $stmt = $db->prepare($count_sql);
                    $stmt->bind_param($count_types, ...$count_params);
                    $stmt->execute();
                    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
                    $stmt->close();
                    
                    log_debug("[GET_TASK_PAGE_INFO] Total tasks: {$total}");
                    
                    // Получаем позицию задачи в отсортированном списке
                    // Используем подзапрос для нумерации строк
                    $position_sql = "
                        SELECT position FROM (
                            SELECT t.uuid,
                                   ROW_NUMBER() OVER (ORDER BY {$order_by} {$order_dir}) as position
                            FROM tasks t
                            JOIN projects p ON t.project_uuid = p.uuid
                            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                            LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                            LEFT JOIN (
                                SELECT task_uuid, MAX(time) as last_msg_time
                                FROM messages
                                GROUP BY task_uuid
                            ) m ON t.uuid = m.task_uuid
                            {$where_clause}
                        ) numbered
                        WHERE uuid = ?
                    ";
                    
                    $position_params = array_merge([$current_user_uuid], $params, [$task_uuid]);
                    $position_types = "s" . $types . "s";
                    
                    $stmt = $db->prepare($position_sql);
                    $stmt->bind_param($position_types, ...$position_params);
                    $stmt->execute();
                    $position_result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($position_result && isset($position_result['position'])) {
                        $position = (int)$position_result['position'];
                        $page = ceil($position / $per_page);
                        
                        log_debug("[GET_TASK_PAGE_INFO] Task position: {$position}, page: {$page}");
                        
                        $response['success'] = true;
                        $response['position'] = $position;
                        $response['page'] = $page;
                        $response['total'] = $total;
                        $response['per_page'] = $per_page;
                        $response['has_more'] = ($page * $per_page) < $total;
                    } else {
                        $response['error'] = 'Не удалось определить позицию задачи';
                        log_warning("[GET_TASK_PAGE_INFO] Could not determine position for task {$task_uuid}");
                    }
                }
            }
        }
        
        // Получение файлов для нескольких задач
        elseif ($action === 'get_tasks_files_batch') {
            $task_uuids = json_decode($_POST['task_uuids'] ?? '[]', true);
            if (!is_array($task_uuids) || empty($task_uuids)) {
                $response['success'] = true;
                $response['files'] = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
                $stmt = $db->prepare("
                    SELECT tf.task_uuid, f.uuid, f.orig_name, f.size_bytes, f.mime
                    FROM task_files tf
                    JOIN files f ON tf.file_uuid = f.uuid
                    WHERE tf.task_uuid IN ($placeholders)
                ");
                $stmt->bind_param(str_repeat('s', count($task_uuids)), ...$task_uuids);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $files_by_task = [];
                while ($row = $result->fetch_assoc()) {
                    if (!isset($files_by_task[$row['task_uuid']])) {
                        $files_by_task[$row['task_uuid']] = [];
                    }
                    $files_by_task[$row['task_uuid']][] = [
                        'uuid' => $row['uuid'],
                        'name' => $row['orig_name'],
                        'size' => msgql_format_file_size($row['size_bytes']),
                        'size_bytes' => (int)$row['size_bytes'],
                        'mime' => $row['mime'],
                        'url' => $appBase . "/download.php?file={$row['uuid']}"
                    ];
                }
                $stmt->close();
                
                $response['success'] = true;
                $response['files_by_task'] = $files_by_task;
                log_debug("[GET_TASKS_FILES_BATCH] Loaded files for " . count($task_uuids) . " tasks");
            }
        }
        
        // Создание проекта
        elseif ($action === 'create_project') {
            $title = trim($_POST['title'] ?? '');
            $descr = trim($_POST['descr'] ?? '');
            
            log_debug("[CREATE_PROJECT] Attempt by user: {$current_user_uuid}");
            
            if (empty($title)) {
                $response['error'] = 'Название проекта обязательно';
            } elseif (!msgql_can_create_project($current_user_uuid)) {  // ЭТА ПРОВЕРКА ДОЛЖНА БЫТЬ
                log_warning("[CREATE_PROJECT] User {$current_user_uuid} has no permission to create projects");
                $response['error'] = 'У вас нет прав на создание проектов';
            } else {
                $uuid = msgql_uuid_v4();
                $time = msgql_now_ms();
                $time_str = (string)$time;
                $user_tz_offset_minutes = msgql_user_timezone_offset();
                $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                $stamp = msgql_stamp($user_tz_offset_hours);
                if ($stamp === null) $stamp = date('Y-m-d H:i:s');
                
                $stmt = $db->prepare("INSERT INTO projects (uuid, title, descr, created_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $uuid, $title, $descr, $current_user_uuid, $time_str, $stamp);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['project'] = get_project_data($uuid, $db);
                    log_debug("[CREATE_PROJECT] Project created: {$uuid}");
                } else {
                    $response['error'] = 'Ошибка БД: ' . $db->error;
                    log_error("[CREATE_PROJECT] DB Error: " . $db->error);
                }
                $stmt->close();
            }
        }
        
        // Редактирование проекта
        elseif ($action === 'edit_project') {
            $uuid = $_POST['uuid'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $descr = trim($_POST['descr'] ?? '');
            
            $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
            $stmt->bind_param("s", $uuid);
            $stmt->execute();
            $proj = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$proj || ($proj['created_by_uuid'] !== $current_user_uuid && !$is_admin)) {
                $response['error'] = 'Нет прав для редактирования проекта';
            } elseif (empty($title)) {
                $response['error'] = 'Название проекта обязательно';
            } else {
                $stmt = $db->prepare("UPDATE projects SET title = ?, descr = ? WHERE uuid = ?");
                $stmt->bind_param("sss", $title, $descr, $uuid);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['project'] = get_project_data($uuid, $db);
                    log_debug("[EDIT_PROJECT] Project updated: {$uuid}");
                } else {
                    $response['error'] = 'Ошибка БД: ' . $db->error;
                }
                $stmt->close();
            }
        }
        
        // Удаление проекта
        elseif ($action === 'delete_project') {
            if (!$is_admin) {
                $response['error'] = 'Нет прав для удаления проекта';
            } else {
                $uuid = $_POST['uuid'] ?? '';
                $confirm = $_POST['confirm'] ?? '';
                
                if ($confirm !== 'DELETE') {
                    $response['error'] = 'Подтверждение удаления неверно. Введите DELETE для подтверждения.';
                } else {
                    delete_project_recursive($uuid, $db);
                    $response['success'] = true;
                    log_debug("[DELETE_PROJECT] Project deleted: {$uuid}");
                }
            }
        }
        
        // Создание задачи
        elseif ($action === 'create_task') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            $parent_task_uuid = $_POST['parent_task_uuid'] ?? '';
            
            if (empty($parent_task_uuid) || $parent_task_uuid === 'null' || $parent_task_uuid === '') {
                $parent_task_uuid = null;
            }
            
            $title = trim($_POST['title'] ?? '');
            $descr = trim($_POST['descr'] ?? '');
            $assigned_to_uuid = $_POST['assigned_to_uuid'] ?: null;
            
            $user_tz_offset_minutes = msgql_user_timezone_offset();
            $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
            
            $time_start = null;
            if (!empty($_POST['time_start'])) {
                $local_timestamp = strtotime($_POST['time_start']);
                if ($local_timestamp !== false) {
                    $time_start = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
                }
            }
            
            $time_end_plan = null;
            if (!empty($_POST['time_end_plan'])) {
                $local_timestamp = strtotime($_POST['time_end_plan']);
                if ($local_timestamp !== false) {
                    $time_end_plan = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
                }
            }
            
            if ($time_start !== null && $time_start <= 0) $time_start = null;
            if ($time_end_plan !== null && $time_end_plan <= 0) $time_end_plan = null;
            
            if ($time_start !== null && $time_end_plan !== null && $time_start > $time_end_plan) {
                $response['error'] = 'Дата начала не может быть позже даты окончания';
            } elseif (empty($title)) {
                $response['error'] = 'Название задачи обязательно';
            } elseif (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
            } elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks')) {
                $response['error'] = 'Нет прав на создание задач в этом проекте';
            } else {
                $uuid = msgql_uuid_v4();
                $time = msgql_now_ms();
                $time_str = (string)$time;
                $stamp = msgql_stamp($user_tz_offset_hours);
                if ($stamp === null) $stamp = date('d.m.Y H:i:s');
                
                $time_start_str = $time_start !== null ? (string)$time_start : 'NULL';
                $time_end_plan_str = $time_end_plan !== null ? (string)$time_end_plan : 'NULL';
                
                $stmt = $db->prepare("INSERT INTO tasks (uuid, project_uuid, parent_task_uuid, title, descr, assigned_to_uuid, user_uuid, time_start, time_end_plan, status, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                $stmt->bind_param("sssssssssss", $uuid, $project_uuid, $parent_task_uuid, $title, $descr, $assigned_to_uuid, $current_user_uuid, $time_start_str, $time_end_plan_str, $time_str, $stamp);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['task'] = get_task_data($uuid, $current_user_uuid, $db);
                    log_debug("[CREATE_TASK] Task created: {$uuid}");
                    
                    // ==================== BLOCK START: create_task_subscription v2.0 (with author subscription) ====================
                    // ver.1.0 - Базовая подписка для исполнителя
                    // ver.2.0 (2026-05-29) - ДОБАВЛЕНА автоматическая подписка АВТОРА задачи
                    // Автор и исполнитель автоматически подписываются на задачу (is_active = 1)
                    // Они могут отписаться позже через интерфейс

                    if (class_exists('NotificationCenter')) {
                        $proj_stmt = $db->prepare("SELECT title FROM projects WHERE uuid = ?");
                        $proj_stmt->bind_param("s", $project_uuid);
                        $proj_stmt->execute();
                        $project_title = $proj_stmt->get_result()->fetch_assoc()['title'] ?? '';
                        $proj_stmt->close();
                        
                        $task_data = [
                            'uuid' => $uuid,
                            'title' => $title,
                            'descr' => $descr,
                            'project_uuid' => $project_uuid,
                            'project_title' => $project_title,
                            'assigned_to_uuid' => $assigned_to_uuid,
                            'time_end_plan' => $time_end_plan,
                            'user_uuid' => $current_user_uuid
                        ];
                        NotificationCenter::notifyNewTask($task_data, $current_user_uuid);
                    }

                    $now = msgql_now_ms();
                    $now_str = (string)$now;
                    $subscriber_stmt = $db->prepare("
                        INSERT IGNORE INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");

                    // v2.0: Подписываем АВТОРА задачи (создателя)
                    log_debug("[CREATE_TASK] Adding author to subscribers: user={$current_user_uuid}, task={$uuid}");
                    $subscriber_stmt->bind_param("ssis", $uuid, $current_user_uuid, $now_str, $current_user_uuid);
                    $subscriber_stmt->execute();

                    // Подписываем ИСПОЛНИТЕЛЯ, если он указан и отличается от автора
                    if (!empty($assigned_to_uuid) && $assigned_to_uuid !== $current_user_uuid) {
                        log_debug("[CREATE_TASK] Adding assignee to subscribers: user={$assigned_to_uuid}, task={$uuid}");
                        $subscriber_stmt->bind_param("ssis", $uuid, $assigned_to_uuid, $now_str, $current_user_uuid);
                        $subscriber_stmt->execute();
                    }
                    $subscriber_stmt->close();

                    log_debug("[CREATE_TASK] Subscription setup completed for task: {$uuid}");
                    // ==================== BLOCK END: create_task_subscription v2.0 ====================
                    
                
                } else {
                    $response['error'] = 'Ошибка БД: ' . $db->error;
                    log_error("[CREATE_TASK] DB Error: " . $db->error);
                }
                $stmt->close();
            }
        }
        
        // Редактирование задачи
        elseif ($action === 'edit_task') {
            $uuid = $_POST['uuid'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $descr = trim($_POST['descr'] ?? '');
            $assigned_to_uuid = $_POST['assigned_to_uuid'] ?: null;
            $parent_task_uuid = $_POST['parent_task_uuid'] ?? '';
            $project_uuid = $_POST['project_uuid'] ?? '';
            
            if (empty($parent_task_uuid) || $parent_task_uuid === 'null') {
                $parent_task_uuid = null;
            }
            
            $user_tz_offset_minutes = msgql_user_timezone_offset();
            
            $time_start = null;
            if (isset($_POST['time_start']) && $_POST['time_start'] !== '') {
                $local_timestamp = strtotime($_POST['time_start']);
                if ($local_timestamp !== false) {
                    $time_start = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
                }
            }
            
            $time_end_plan = null;
            if (isset($_POST['time_end_plan']) && $_POST['time_end_plan'] !== '') {
                $local_timestamp = strtotime($_POST['time_end_plan']);
                if ($local_timestamp !== false) {
                    $time_end_plan = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
                }
            }
            
            if ($time_start !== null && $time_start <= 0) $time_start = null;
            if ($time_end_plan !== null && $time_end_plan <= 0) $time_end_plan = null;
            
            if ($time_start !== null && $time_end_plan !== null && $time_start > $time_end_plan) {
                $response['error'] = 'Дата начала не может быть позже даты окончания';
            } elseif (empty($title)) {
                $response['error'] = 'Название задачи обязательно';
            } elseif (!msgql_can_edit_task($current_user_uuid, $uuid)) {
                $response['error'] = 'Нет прав на редактирование этой задачи';
            } else {
                $old_stmt = $db->prepare("SELECT title, descr, assigned_to_uuid, parent_task_uuid, time_start, time_end_plan, status, project_uuid FROM tasks WHERE uuid = ?");
                $old_stmt->bind_param("s", $uuid);
                $old_stmt->execute();
                $old_data = $old_stmt->get_result()->fetch_assoc();
                $old_stmt->close();
                
                $time_start_str = $time_start !== null ? (string)$time_start : 'NULL';
                $time_end_plan_str = $time_end_plan !== null ? (string)$time_end_plan : 'NULL';
                
                if (!empty($project_uuid) && $project_uuid !== $old_data['project_uuid']) {
                    log_debug("[EDIT_TASK] Moving task from project {$old_data['project_uuid']} to {$project_uuid}");
                    
                    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks')) {
                        $response['error'] = 'Нет прав на создание задач в выбранном проекте';
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $sql = "UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, project_uuid = ?";
                    $params = [$title, $descr, $assigned_to_uuid, $parent_task_uuid, $project_uuid];
                    $types = "sssss";
                    
                    if ($time_start_str === 'NULL' && $time_end_plan_str === 'NULL') {
                        $sql .= ", time_start = NULL, time_end_plan = NULL";
                    } elseif ($time_start_str === 'NULL') {
                        $sql .= ", time_start = NULL, time_end_plan = ?";
                        $params[] = $time_end_plan_str;
                        $types .= "s";
                    } elseif ($time_end_plan_str === 'NULL') {
                        $sql .= ", time_start = ?, time_end_plan = NULL";
                        $params[] = $time_start_str;
                        $types .= "s";
                    } else {
                        $sql .= ", time_start = ?, time_end_plan = ?";
                        $params[] = $time_start_str;
                        $params[] = $time_end_plan_str;
                        $types .= "ss";
                    }
                    
                    $sql .= " WHERE uuid = ?";
                    $params[] = $uuid;
                    $types .= "s";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                } else {
                    if ($time_start_str === 'NULL' && $time_end_plan_str === 'NULL') {
                        $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = NULL, time_end_plan = NULL WHERE uuid = ?");
                        $stmt->bind_param("sssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $uuid);
                    } elseif ($time_start_str === 'NULL') {
                        $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = NULL, time_end_plan = ? WHERE uuid = ?");
                        $stmt->bind_param("ssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_end_plan_str, $uuid);
                    } elseif ($time_end_plan_str === 'NULL') {
                        $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = ?, time_end_plan = NULL WHERE uuid = ?");
                        $stmt->bind_param("ssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_start_str, $uuid);
                    } else {
                        $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = ?, time_end_plan = ? WHERE uuid = ?");
                        $stmt->bind_param("sssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_start_str, $time_end_plan_str, $uuid);
                    }
                }
                
                $response['success'] = $stmt->execute();
                
                // ==================== BLOCK START: edit_task_subscription v2.0 (with assignee subscription) ====================
                // ver.1.0 - Базовая проверка и добавление подписки для нового исполнителя
                // ver.2.0 (2026-05-29) - ДОБАВЛЕНА проверка что исполнитель не отписывался ранее
                // При изменении исполнителя, новый исполнитель автоматически подписывается

                if ($response['success']) {
                    log_debug("[EDIT_TASK] Task updated: {$uuid}");
                    
                    if (class_exists('NotificationCenter')) {
                        $new_data = [
                            'title' => $title,
                            'descr' => $descr,
                            'assigned_to_uuid' => $assigned_to_uuid,
                            'parent_task_uuid' => $parent_task_uuid,
                            'time_start' => $time_start,
                            'time_end_plan' => $time_end_plan,
                            'status' => $old_data['status']
                        ];
                        NotificationCenter::notifyTaskChanges($uuid, $old_data, $new_data, $current_user_uuid);
                        $response['refresh_dashboard'] = true;
                    }
                    
                    // v2.0: Проверяем и подписываем нового исполнителя
                    if (!empty($assigned_to_uuid) && $assigned_to_uuid !== $current_user_uuid) {
                        // Проверяем существующую подписку
                        $check_sub = $db->prepare("
                            SELECT id, is_active FROM task_subscribers 
                            WHERE task_uuid = ? AND user_uuid = ?
                        ");
                        $check_sub->bind_param("ss", $uuid, $assigned_to_uuid);
                        $check_sub->execute();
                        $existing = $check_sub->get_result()->fetch_assoc();
                        $check_sub->close();
                        
                        $now = msgql_now_ms();
                        $now_str = (string)$now;
                        
                        if ($existing) {
                            // Если подписка существует но неактивна - активируем её
                            if ($existing['is_active'] == 0) {
                                $update_sub = $db->prepare("
                                    UPDATE task_subscribers 
                                    SET is_active = 1, subscribed_at = ?, subscribed_by_uuid = ? 
                                    WHERE id = ?
                                ");
                                $update_sub->bind_param("ssi", $now_str, $current_user_uuid, $existing['id']);
                                $update_sub->execute();
                                $update_sub->close();
                                log_debug("[EDIT_TASK] Reactivated subscription for assignee: {$assigned_to_uuid}");
                            } else {
                                log_debug("[EDIT_TASK] Assignee already subscribed: {$assigned_to_uuid}");
                            }
                        } else {
                            // Создаём новую подписку
                            $sub_stmt = $db->prepare("
                                INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
                                VALUES (?, ?, ?, ?, 1)
                            ");
                            $sub_stmt->bind_param("ssis", $uuid, $assigned_to_uuid, $now_str, $current_user_uuid);
                            $sub_stmt->execute();
                            $sub_stmt->close();
                            log_debug("[EDIT_TASK] Added assignee as subscriber: {$assigned_to_uuid}");
                        }
                    }
                }
                // ==================== BLOCK END: edit_task_subscription v2.0 ====================
                else {
                    $response['error'] = 'Ошибка обновления задачи: ' . $db->error;
                    log_error("[EDIT_TASK] DB Error: " . $db->error);
                }
                $stmt->close();
            }
        }
        
        // Переключение статуса задачи
        elseif ($action === 'toggle_task_status') {
            $uuid = $_POST['uuid'] ?? '';
            $status = (int)($_POST['status'] ?? 0);
            
            if (!msgql_can_edit_task($current_user_uuid, $uuid)) {
                $response['error'] = 'Нет прав на изменение статуса задачи';
            } else {
                $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE uuid = ?");
                $stmt->bind_param("is", $status, $uuid);
                $response['success'] = $stmt->execute();
                if (!$response['success']) $response['error'] = 'Ошибка изменения статуса';
                $stmt->close();
                log_debug("[TOGGLE_TASK_STATUS] Task {$uuid} status set to {$status}");
            }
        }
        
        // Удаление задачи
        elseif ($action === 'delete_task') {
            $uuid = $_POST['uuid'] ?? '';
            $confirm = $_POST['confirm'] ?? '';
            
            if (!msgql_can_edit_task($current_user_uuid, $uuid)) {
                $response['error'] = 'Нет прав на удаление этой задачи';
            } elseif ($confirm !== 'DELETE') {
                $response['error'] = 'Подтверждение удаления неверно. Введите DELETE для подтверждения.';
            } else {
                $project_stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
                $project_stmt->bind_param("s", $uuid);
                $project_stmt->execute();
                $project = $project_stmt->get_result()->fetch_assoc();
                $project_stmt->close();
                
                delete_task_recursive($uuid, $db);
                $response['success'] = true;
                log_debug("[DELETE_TASK] Task deleted: {$uuid}");
                
                if (function_exists('msgql_send_sse_event') && $project) {
                    $event_data = [
                        'type' => 'task_deleted',
                        'task_uuid' => $uuid,
                        'project_uuid' => $project['project_uuid'],
                        'deleted_by_uuid' => $current_user_uuid,
                        'deleted_at' => msgql_now_ms()
                    ];
                    msgql_send_sse_event($project['project_uuid'], 'task_deleted', $event_data);
                }
            }
        }
        
        // Перемещение задачи
        elseif ($action === 'move_task') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $new_parent_uuid = $_POST['new_parent_uuid'] ?: null;
            
            if (!msgql_can_edit_task($current_user_uuid, $task_uuid)) {
                $response['error'] = 'Нет прав на перемещение этой задачи';
            } else {
                if ($new_parent_uuid) {
                    $cycle_check = $db->prepare("
                        WITH RECURSIVE task_tree AS (
                            SELECT uuid, parent_task_uuid FROM tasks WHERE uuid = ?
                            UNION ALL
                            SELECT t.uuid, t.parent_task_uuid
                            FROM tasks t
                            INNER JOIN task_tree tt ON t.uuid = tt.parent_task_uuid
                        )
                        SELECT COUNT(*) as cnt FROM task_tree WHERE uuid = ?
                    ");
                    $cycle_check->bind_param("ss", $new_parent_uuid, $task_uuid);
                    $cycle_check->execute();
                    $cycle_result = $cycle_check->get_result()->fetch_assoc();
                    $cycle_check->close();
                    
                    if ($cycle_result && $cycle_result['cnt'] > 0) {
                        $response['error'] = 'Нельзя переместить задачу в её собственную подзадачу (циклическая ссылка)';
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                
                $stmt = $db->prepare("UPDATE tasks SET parent_task_uuid = ? WHERE uuid = ?");
                $stmt->bind_param("ss", $new_parent_uuid, $task_uuid);
                $response['success'] = $stmt->execute();
                if (!$response['success']) $response['error'] = 'Ошибка перемещения задачи';
                $stmt->close();
                log_debug("[MOVE_TASK] Task {$task_uuid} moved to parent: " . ($new_parent_uuid ?: 'root'));
            }
        }
        
        // Загрузка файлов задачи (одиночная, для модалки)
        elseif ($action === 'get_task_files') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } else {
                $response['success'] = true;
                $response['files'] = get_task_files($task_uuid, $db);
                log_debug("[GET_TASK_FILES] Found " . count($response['files']) . " files for task {$task_uuid}");
            }
        }
        
        // Загрузка файла к задаче
        // ==================== BLOCK START: upload_task_file v2.0 (TOCTOU fix) ====================
        // ver.1.0 - Базовая версия
        // ver.2.0 (2026-06-05) - ИСПРАВЛЕНА TOCTOU УЯЗВИМОСТЬ
        // - Сначала запись в БД, потом сохранение файла
        // - Откат БД при ошибке сохранения файла
        // - Добавлены проверки квот пользователя
        
        elseif ($action === 'upload_task_file') {
            if (!class_exists('UploadSecurity')) {
                require_once __DIR__ . '/lib/upload_security.php';
            }
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            log_debug("[UPLOAD_TASK_FILE] task_uuid: {$task_uuid}");
            
            if (!msgql_can_upload_file($current_user_uuid, $task_uuid)) {
                $response['error'] = 'Нет прав на загрузку файлов в эту задачу';
            } elseif (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $response['error'] = 'Ошибка загрузки файла';
            } else {
                // v2.0: Проверка квоты пользователя перед загрузкой
                $quota_check = check_user_upload_quota($current_user_uuid, $db);
                if (!$quota_check['allowed']) {
                    $response['error'] = $quota_check['message'];
                    log_warning("[UPLOAD_TASK_FILE] Quota exceeded for user {$current_user_uuid}: {$quota_check['reason']}");
                } else {
                    $validation = UploadSecurity::validateUploadedFile($_FILES['file']);
                    
                    if (!$validation['valid']) {
                        $response['error'] = $validation['error'];
                    } else {
                        $upload_dir = __DIR__ . '/uploads/tasks/';
                        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                        
                        // v2.0: Генерируем имя с проверкой на коллизии
                        $storage_name = generate_unique_filename($validation['safe_name'], 'task', $upload_dir);
                        if ($storage_name === false) {
                            $response['error'] = 'Не удалось сгенерировать уникальное имя файла';
                        } else {
                            $target_path = $upload_dir . $storage_name;
                            
                            // v2.0: Сначала создаём запись в БД
                            $file_uuid = msgql_uuid_v4();
                            $time = msgql_now_ms();
                            $time_str = (string)$time;
                            $user_tz_offset_minutes = msgql_user_timezone_offset();
                            $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                            $stamp = msgql_stamp($user_tz_offset_hours);
                            if ($stamp === null) $stamp = date('Y-m-d H:i:s');
                            
                            $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("ssssisss", 
                                $file_uuid, 
                                $validation['safe_name'], 
                                $storage_name, 
                                $validation['mime'], 
                                $validation['size'], 
                                $current_user_uuid,
                                $time_str, 
                                $stamp
                            );
                            
                            if ($stmt->execute()) {
                                log_debug("[UPLOAD_TASK_FILE] DB record created for file: {$file_uuid}");
                                
                                // v2.0: Теперь сохраняем файл на диск
                                if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
                                    log_debug("[UPLOAD_TASK_FILE] File saved to disk: {$target_path}");
                                    
                                    // Создаём связь задача-файл
                                    $check = $db->prepare("SELECT 1 FROM task_files WHERE task_uuid = ? AND file_uuid = ?");
                                    $check->bind_param("ss", $task_uuid, $file_uuid);
                                    $check->execute();
                                    
                                    if ($check->get_result()->num_rows === 0) {
                                        $stmt2 = $db->prepare("INSERT INTO task_files (task_uuid, file_uuid) VALUES (?, ?)");
                                        $stmt2->bind_param("ss", $task_uuid, $file_uuid);
                                        $stmt2->execute();
                                        $stmt2->close();
                                        log_debug("[UPLOAD_TASK_FILE] Task-file link created");
                                    }
                                    $check->close();
                                    
                                    $response['success'] = true;
                                    $response['file'] = [
                                        'uuid' => $file_uuid,
                                        'name' => $validation['safe_name'],
                                        'size' => msgql_format_file_size($validation['size']),
                                        'size_bytes' => $validation['size'],
                                        'mime' => $validation['mime'],
                                        'url' => "download.php?file={$file_uuid}"
                                    ];
                                    $response['files'] = get_task_files($task_uuid, $db);
                                    log_debug("[UPLOAD_TASK_FILE] File uploaded successfully: {$file_uuid}");
                                } else {
                                    // v2.0: Откат - удаляем запись из БД
                                    log_error("[UPLOAD_TASK_FILE] Failed to save file to disk, rolling back DB record");
                                    $del_stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                    $del_stmt->bind_param("s", $file_uuid);
                                    $del_stmt->execute();
                                    $del_stmt->close();
                                    $response['error'] = 'Ошибка сохранения файла на диск (откат выполнен)';
                                }
                            } else {
                                $response['error'] = 'Ошибка сохранения в БД: ' . $db->error;
                                log_error("[UPLOAD_TASK_FILE] DB Error: " . $db->error);
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
        // ==================== BLOCK END: upload_task_file v2.0 ====================
        
        // Получение задачи для редактирования
        elseif ($action === 'get_task') {
            $uuid = $_POST['uuid'] ?? '';
            $task = get_task_data($uuid, $current_user_uuid, $db);
            
            if ($task) {
                $response['success'] = true;
                $response['task'] = $task;
                $response['files'] = get_task_files($uuid, $db);
            } else {
                $response['error'] = 'Задача не найдена или нет доступа';
            }
        }
        
        // ==================== BLOCK START: ajax_get_task_info v5.3 (with parent chain titles) ====================
        // ver.5.1 (2026-06-10) - Базовая версия с parent_chain
        // ver.5.2 (2026-06-11) - ДОБАВЛЕНА ЗАГРУЗКА ФАЙЛОВ ЗАДАЧИ
        // ver.5.3 (2026-06-11) - ДОБАВЛЕНА ЗАГРУЗКА ДАННЫХ О РОДИТЕЛЬСКИХ ЗАДАЧАХ (title)
        elseif ($action === 'get_task_info') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            log_debug("[GET_TASK_INFO] v5.3 Called for task_uuid: {$task_uuid}");
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } else {
                // Получаем полную информацию о задаче и проекте
                $task_check = $db->prepare("
                    SELECT t.uuid, t.project_uuid, t.parent_task_uuid, t.title,
                           p.created_by_uuid as project_created_by
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE t.uuid = ?
                ");
                $task_check->bind_param("s", $task_uuid);
                $task_check->execute();
                $task_info_db = $task_check->get_result()->fetch_assoc();
                $task_check->close();
                
                if (!$task_info_db) {
                    $response['error'] = 'Задача не найдена';
                    log_warning("[GET_TASK_INFO] Task not found: {$task_uuid}");
                } else {
                    $project_uuid = $task_info_db['project_uuid'];
                    $direct_parent = $task_info_db['parent_task_uuid'];
                    
                    log_debug("[GET_TASK_INFO] Task belongs to project: {$project_uuid}, direct_parent: " . ($direct_parent ?: 'NULL'));
                    
                    // Проверяем доступ к проекту
                    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                        $response['error'] = 'Нет доступа к проекту задачи';
                        log_warning("[GET_TASK_INFO] Access denied to project: {$project_uuid}");
                    } else {
                        $task = get_task_data($task_uuid, $current_user_uuid, $db);
                        if ($task) {
                            // ========== v5.1: ПОСТРОЕНИЕ parent_chain через рекурсивный CTE ==========
                            $parent_chain = [];
                            
                            if (!empty($direct_parent)) {
                                log_debug("[GET_TASK_INFO] Building parent chain using recursive CTE starting from: {$direct_parent}");
                                
                                $cte_sql = "
                                    WITH RECURSIVE task_parents AS (
                                        SELECT uuid, parent_task_uuid, title, 1 as level
                                        FROM tasks
                                        WHERE uuid = ?
                                        UNION ALL
                                        SELECT t.uuid, t.parent_task_uuid, t.title, tp.level + 1
                                        FROM tasks t
                                        INNER JOIN task_parents tp ON t.uuid = tp.parent_task_uuid
                                        WHERE tp.parent_task_uuid IS NOT NULL AND tp.level < 50
                                    )
                                    SELECT uuid, title FROM task_parents ORDER BY level DESC
                                ";
                                
                                $cte_stmt = $db->prepare($cte_sql);
                                if ($cte_stmt) {
                                    $cte_stmt->bind_param("s", $direct_parent);
                                    $cte_stmt->execute();
                                    $parents_result = $cte_stmt->get_result();
                                    
                                    while ($row = $parents_result->fetch_assoc()) {
                                        $parent_chain[] = $row['uuid'];
                                        log_debug("[GET_TASK_INFO] CTE found parent: " . $row['uuid'] . " - " . $row['title']);
                                    }
                                    $cte_stmt->close();
                                } else {
                                    log_warning("[GET_TASK_INFO] CTE not supported, using iterative fallback");
                                    $current_parent = $direct_parent;
                                    $fallback_stmt = $db->prepare("SELECT parent_task_uuid, title FROM tasks WHERE uuid = ?");
                                    $fallback_depth = 0;
                                    $fallback_max = 50;
                                    $parent_titles = [];
                                    
                                    while ($current_parent && $fallback_depth < $fallback_max) {
                                        log_debug("[GET_TASK_INFO] Fallback adding parent: {$current_parent}");
                                        $fallback_stmt->bind_param("s", $current_parent);
                                        $fallback_stmt->execute();
                                        $fallback_row = $fallback_stmt->get_result()->fetch_assoc();
                                        
                                        if ($fallback_row) {
                                            array_unshift($parent_chain, $current_parent);
                                            $parent_titles[$current_parent] = $fallback_row['title'] ?? 'Задача';
                                            
                                            if (!empty($fallback_row['parent_task_uuid'])) {
                                                $current_parent = $fallback_row['parent_task_uuid'];
                                            } else {
                                                $current_parent = null;
                                            }
                                        } else {
                                            $current_parent = null;
                                        }
                                        $fallback_depth++;
                                    }
                                    $fallback_stmt->close();
                                }
                                
                                log_debug("[GET_TASK_INFO] Final parent_chain (" . count($parent_chain) . " items): " . json_encode($parent_chain));
                            } else {
                                log_debug("[GET_TASK_INFO] Task is root (no parent)");
                            }
                            
                            // ========== v5.3: ЗАГРУЖАЕМ ДАННЫЕ О РОДИТЕЛЬСКИХ ЗАДАЧАХ ==========
                            $parent_tasks_data = [];
                            if (!empty($parent_chain)) {
                                $placeholders = implode(',', array_fill(0, count($parent_chain), '?'));
                                $parent_sql = "SELECT uuid, title, status FROM tasks WHERE uuid IN ($placeholders)";
                                $parent_stmt = $db->prepare($parent_sql);
                                $parent_stmt->bind_param(str_repeat('s', count($parent_chain)), ...$parent_chain);
                                $parent_stmt->execute();
                                $parents_result = $parent_stmt->get_result();
                                while ($prow = $parents_result->fetch_assoc()) {
                                    $parent_tasks_data[$prow['uuid']] = [
                                        'uuid' => $prow['uuid'],
                                        'title' => $prow['title'],
                                        'status' => (int)$prow['status']
                                    ];
                                }
                                $parent_stmt->close();
                                log_debug("[GET_TASK_INFO] Loaded " . count($parent_tasks_data) . " parent tasks data");
                            }
                            
                            // ========== v5.2: ЗАГРУЖАЕМ ФАЙЛЫ ЗАДАЧИ ==========
                            $task_files = get_task_files($task_uuid, $db);
                            log_debug("[GET_TASK_INFO] Found " . count($task_files) . " files for task: {$task_uuid}");
                            
                            $response['success'] = true;
                            $response['task'] = $task;
                            $response['task']['files'] = $task_files;
                            $response['task']['parent_chain'] = $parent_chain;
                            $response['task']['parent_tasks'] = $parent_tasks_data;  // ДОБАВЛЯЕМ ДАННЫЕ О РОДИТЕЛЯХ
                            $response['task']['project_uuid'] = $task['project_uuid'];
                            $response['task']['direct_parent_uuid'] = $direct_parent;
                        } else {
                            $response['error'] = 'Задача не найдена или нет доступа';
                            log_warning("[GET_TASK_INFO] Task data not accessible: {$task_uuid}");
                        }
                    }
                }
            }
        }
        // ==================== BLOCK END: ajax_get_task_info v5.3 ====================
        
        // Проверка прав на редактирование
        elseif ($action === 'check_edit_permission') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } elseif (!msgql_can_edit_task($current_user_uuid, $task_uuid)) {
                $response['error'] = 'У вас нет прав на редактирование этой задачи';
            } else {
                $response['success'] = true;
            }
        }
        
        // Получение пользователей для задачи
        elseif ($action === 'get_task_users') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['users'] = [];
            } else {
                $taskStmt = $db->prepare("
                    SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE t.uuid = ?
                ");
                $taskStmt->bind_param("s", $task_uuid);
                $taskStmt->execute();
                $taskInfo = $taskStmt->get_result()->fetch_assoc();
                $taskStmt->close();
                
                if (!$taskInfo) {
                    $response['users'] = [];
                } else {
                    $project_uuid = $taskInfo['project_uuid'];
                    $created_by = $taskInfo['created_by_uuid'];
                    $assigned_to = $taskInfo['assigned_to_uuid'];
                    
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.uuid, u.name, u.login, u.role
                        FROM users u
                        WHERE u.status = 0
                        AND u.uuid != ?
                        AND (
                            u.role = 0
                            OR u.uuid = ?
                            OR u.uuid = ?
                            OR u.uuid IN (
                                SELECT user_uuid FROM user_project_permissions 
                                WHERE project_uuid = ? AND can_view = 1
                            )
                        )
                        ORDER BY 
                            CASE 
                                WHEN u.role = 0 THEN 1
                                WHEN u.uuid = ? THEN 2
                                WHEN u.uuid = ? THEN 3
                                ELSE 4
                            END,
                            u.name ASC
                    ");
                    
                    $stmt->bind_param("ssssss", 
                        $current_user_uuid,
                        $created_by,
                        $assigned_to,
                        $project_uuid,
                        $created_by,
                        $assigned_to
                    );
                    $stmt->execute();
                    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    $response['success'] = true;
                    $response['users'] = $users;
                }
            }
        }
        
        // Переключение подписки на задачу
        elseif ($action === 'toggle_task_subscription') {
            // V1.0: Добавляем CSRF-проверку для мутирующего действия
            msgql_csrf_check_and_exit();
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } else {
                $check_stmt = $db->prepare("SELECT id, is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                $check_stmt->bind_param("ss", $task_uuid, $current_user_uuid);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                $now = msgql_now_ms();
                $now_str = (string)$now;
                
                if ($existing) {
                    $new_status = $existing['is_active'] ? 0 : 1;
                    $update_stmt = $db->prepare("UPDATE task_subscribers SET is_active = ?, subscribed_at = ?, subscribed_by_uuid = ? WHERE id = ?");
                    $update_stmt->bind_param("iisi", $new_status, $now_str, $current_user_uuid, $existing['id']);
                    
                    if ($update_stmt->execute()) {
                        $response['success'] = true;
                        $response['is_subscribed'] = ($new_status == 1);
                        $response['message'] = $new_status ? 'Вы подписаны на уведомления задачи' : 'Вы отписались от уведомлений задачи';
                        log_debug("[TOGGLE_SUB] User {$current_user_uuid} " . ($new_status ? 'subscribed to' : 'unsubscribed from') . " task {$task_uuid}");
                    } else {
                        $response['error'] = 'Ошибка изменения подписки';
                    }
                    $update_stmt->close();
                } else {
                    $insert_stmt = $db->prepare("
                        INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $insert_stmt->bind_param("ssis", $task_uuid, $current_user_uuid, $now_str, $current_user_uuid);
                    
                    if ($insert_stmt->execute()) {
                        $response['success'] = true;
                        $response['is_subscribed'] = true;
                        $response['message'] = 'Вы подписаны на уведомления задачи';
                        log_debug("[TOGGLE_SUB] User {$current_user_uuid} subscribed to task {$task_uuid}");
                    } else {
                        $response['error'] = 'Ошибка создания подписки';
                    }
                    $insert_stmt->close();
                }
            }
        }


        // ==================== BLOCK START: check_project_permission v1.0 ====================
        // ver.1.0 (2026-06-05) - ПРОВЕРКА ПРАВ ПОЛЬЗОВАТЕЛЯ НА СОЗДАНИЕ ЗАДАЧ В ПРОЕКТЕ
        elseif ($action === 'check_project_permission') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            
            log_debug("[CHECK_PROJECT_PERM] Checking edit_tasks permission for project: {$project_uuid}");
            
            if (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
            } else {
                $can_edit_tasks = msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks');
                log_debug("[CHECK_PROJECT_PERM] User {$current_user_uuid} can_edit_tasks: " . ($can_edit_tasks ? 'true' : 'false'));
                
                $response['success'] = true;
                $response['can_create_task'] = $can_edit_tasks;
            }
        }
        // ==================== BLOCK END: check_project_permission v1.0 ====================
        
        // Проверка статуса подписки
        elseif ($action === 'check_task_subscription') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
                $response['is_subscribed'] = false;
            } else {
                $check_stmt = $db->prepare("SELECT is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                $check_stmt->bind_param("ss", $task_uuid, $current_user_uuid);
                $check_stmt->execute();
                $sub = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                $response['success'] = true;
                $response['is_subscribed'] = ($sub && $sub['is_active'] == 1);
            }
        }

        // ==================== BLOCK START: mark_project_notifications_read v1.0 ====================
        // ver.1.0 (2026-06-16) - ОБРАБОТЧИК ДЛЯ СБРОСА НЕПРОЧИТАННЫХ УВЕДОМЛЕНИЙ ПРОЕКТА
        // - Помечает все уведомления по задачам проекта как прочитанные
        // - Вызывается при клике на проект
        elseif ($action === 'mark_project_notifications_read') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            
            log_debug("[MARK_PROJECT_READ] Marking project notifications as read: {$project_uuid} for user: {$current_user_uuid}");
            
            if (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
            } elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                $response['error'] = 'Нет доступа к проекту';
            } else {
                // Проверяем существование таблицы user_notifications
                $table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
                if ($table_check->num_rows == 0) {
                    $response['success'] = true;
                    $response['marked_count'] = 0;
                } else {
                    // Помечаем как прочитанные все уведомления по задачам этого проекта
                    $sql = "
                        UPDATE user_notifications n
                        JOIN tasks t ON n.task_uuid = t.uuid
                        SET n.is_read = 1
                        WHERE n.user_uuid = ?
                        AND n.is_read = 0
                        AND t.project_uuid = ?
                    ";
                    $stmt = $db->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("ss", $current_user_uuid, $project_uuid);
                        $stmt->execute();
                        $marked_count = $db->affected_rows;
                        $stmt->close();
                        
                        log_debug("[MARK_PROJECT_READ] Marked {$marked_count} notifications as read for project {$project_uuid}");
                        
                        $response['success'] = true;
                        $response['marked_count'] = $marked_count;
                    } else {
                        $response['error'] = 'Ошибка подготовки запроса';
                    }
                }
            }
        }
        // ==================== BLOCK END: mark_project_notifications_read v1.0 ====================
        
        else {
            $response['error'] = 'Unknown action: ' . $action;
            log_warning("[AJAX] Unknown action: {$action}");
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        log_error("[AJAX] Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } catch (Error $e) {
        $response = ['success' => false, 'error' => 'PHP Error: ' . $e->getMessage()];
        log_error("[AJAX] PHP Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

// ==================== BLOCK START: get_task_with_parents v1.0 ====================
// ver.1.0 (2026-06-10) - ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ ЦЕПОЧКИ РОДИТЕЛЕЙ ЗАДАЧИ
// Используется при прямом переходе по ссылке на подзадачу
function get_task_with_parents($task_uuid, $db) {
    $stmt = $db->prepare("
        WITH RECURSIVE task_tree AS (
            SELECT uuid, parent_task_uuid, project_uuid, title
            FROM tasks WHERE uuid = ?
            UNION ALL
            SELECT t.uuid, t.parent_task_uuid, t.project_uuid, t.title
            FROM tasks t
            INNER JOIN task_tree tt ON t.uuid = tt.parent_task_uuid
        )
        SELECT uuid, parent_task_uuid, project_uuid, title FROM task_tree
    ");
    $stmt->bind_param("s", $task_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    $parents = [];
    while ($row = $result->fetch_assoc()) {
        $parents[] = $row;
    }
    $stmt->close();
    return $parents;
}
// ==================== BLOCK END: get_task_with_parents v1.0 ====================


// ==================== BLOCK START: get_task_parent_chain v4.9 ====================
// ver.4.9 (2026-06-03) - ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ ВСЕХ РОДИТЕЛЕЙ ЗАДАЧИ
// Используется при фильтрации для показа иерархии задач
// ver.4.9 (2026-06-10) - ИСПРАВЛЕНА ЛОГИКА: рекурсивный подъём до корня с защитой от циклов
// - Удалён лимит глубины, вместо этого защита от циклов через visited array
// - Добавлено подробное логирование каждого шага
// - Возвращает массив UUID родителей в порядке от корня к непосредственному родителю
function get_task_parent_chain($task_uuid, $db) {
    $chain = [];
    $visited = []; // Защита от циклических ссылок
    $current = $task_uuid;
    $max_depth = 50; // Защита от слишком глубокой вложенности
    
    $stmt = $db->prepare("SELECT parent_task_uuid FROM tasks WHERE uuid = ?");
    if (!$stmt) {
        log_error("[GET_TASK_PARENT_CHAIN] Prepare failed: " . $db->error);
        return $chain;
    }
    
    $depth = 0;
    while ($current && $depth < $max_depth) {
        // Защита от циклов
        if (isset($visited[$current])) {
            log_warning("[GET_TASK_PARENT_CHAIN] Cycle detected at task: {$current}");
            break;
        }
        $visited[$current] = true;
        
        $stmt->bind_param("s", $current);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if ($row && !empty($row['parent_task_uuid'])) {
            $parent = $row['parent_task_uuid'];
            log_debug("[GET_TASK_PARENT_CHAIN] Found parent '{$parent}' for task '{$current}' (depth: {$depth})");
            // Добавляем в начало массива (чтобы порядок был от корня к листу)
            array_unshift($chain, $parent);
            $current = $parent;
        } else {
            log_debug("[GET_TASK_PARENT_CHAIN] No more parents for task '{$current}', reached root");
            $current = null;
        }
        $depth++;
    }
    
    if ($depth >= $max_depth) {
        log_warning("[GET_TASK_PARENT_CHAIN] Reached max depth ({$max_depth}) for task: {$task_uuid}");
    }
    
    $stmt->close();
    
    log_debug("[GET_TASK_PARENT_CHAIN] Final chain (" . count($chain) . " parents) for task {$task_uuid}: " . json_encode($chain));
    return $chain;
}
// ==================== BLOCK END: get_task_parent_chain v4.9 ====================


// ==================== BLOCK START: get_all_tasks_matching_filters v4.12 ====================
// ver.4.12 (2026-06-03) - ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ ПОИСКА ЗАДАЧ ПО ФИЛЬТРАМ
// Возвращает все задачи (включая подзадачи), соответствующие фильтрам
function get_all_tasks_matching_filters($project_uuid, $user_uuid, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
    $where_conditions = ["t.project_uuid = ?"];
    $params = [$project_uuid];
    $types = "s";
    
    if (!$is_admin) {
        $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
        $params[] = $user_uuid;
        $types .= "s";
    }
    
    if (!empty($filter_statuses) && is_array($filter_statuses)) {
        $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
        $where_conditions[] = "t.status IN ($status_placeholders)";
        foreach ($filter_statuses as $status) {
            $params[] = (int)$status;
            $types .= "i";
        }
    }
    
    if (!empty($filter_assigned) && is_array($filter_assigned)) {
        $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
        $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
        foreach ($filter_assigned as $assigned_uuid) {
            $params[] = $assigned_uuid;
            $types .= "s";
        }
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    $sql = "SELECT t.uuid, t.parent_task_uuid, t.title, t.status, t.assigned_to_uuid
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        {$where_clause}
        LIMIT 5000";  // Максимум 5000 задач
    
    $all_params = array_merge([$user_uuid], $params);
    $all_types = "s" . $types;
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($all_types, ...$all_params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $result;
}
// ==================== BLOCK END: get_all_tasks_matching_filters v4.12 ====================


// ==================== BLOCK START: get_project_tasks_sorted v4.16 (FIXED bind_param) ====================
// ver.4.5 - Фильтрация только корневых задач
// ver.4.6 (2026-06-02) - УЛУЧШЕНА СОРТИРОВКА
// ver.4.11 (2026-06-03) - ИСПРАВЛЕНА ФИЛЬТРАЦИЯ ПОДЗАДАЧ
// ver.4.13 (2026-06-05) - ИСПРАВЛЕНА SQL-ИНЪЕКЦИЯ В ORDER BY (строгая валидация через switch)
// ver.4.14 (2026-06-05) - ДОБАВЛЕНА ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА ТИПОВ ПАРАМЕТРОВ
// ver.4.15 (2026-06-16) - ДОБАВЛЕН ПРИОРИТЕТ: задачи с непрочитанными уведомлениями ВСЕГДА сверху
// ver.4.16 (2026-06-16) - ИСПРАВЛЕНА ОШИБКА bind_param: добавлена проверка количества параметров

function get_project_tasks_sorted($project_uuid, $user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
    log_debug("[GET_PROJECT_TASKS_SORTED] v4.16 - project_uuid: {$project_uuid}, sort_by: {$sort_by}, sort_dir: {$sort_dir}");
    log_debug("[GET_PROJECT_TASKS_SORTED] filter_statuses: " . json_encode($filter_statuses));
    log_debug("[GET_PROJECT_TASKS_SORTED] filter_assigned: " . json_encode($filter_assigned));
    log_debug("[GET_PROJECT_TASKS_SORTED] search: '{$search}'");
    
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ["t.project_uuid = ?"];
    $params = [$project_uuid];
    $types = "s";
    
    $has_filters = !empty($filter_statuses) || !empty($filter_assigned) || !empty($search);
    
    if (!$has_filters) {
        $where_conditions[] = "(t.parent_task_uuid IS NULL OR t.parent_task_uuid = '')";
        log_debug("[GET_PROJECT_TASKS_SORTED] No filters - showing root tasks only");
    } else {
        log_debug("[GET_PROJECT_TASKS_SORTED] Filters active - showing ALL tasks (including subtasks)");
    }
    
    if (!$is_admin) {
        $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
        $params[] = $user_uuid;
        $types .= "s";
    }
    
    if (!empty($filter_statuses) && is_array($filter_statuses)) {
        $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
        $where_conditions[] = "t.status IN ($status_placeholders)";
        foreach ($filter_statuses as $status) {
            $params[] = (int)$status;
            $types .= "i";
        }
        log_debug("[FILTER] Status filter applied: " . implode(',', $filter_statuses));
    }
    
    if (!empty($filter_assigned) && is_array($filter_assigned)) {
        $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
        $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
        foreach ($filter_assigned as $assigned_uuid) {
            $params[] = $assigned_uuid;
            $types .= "s";
        }
        log_debug("[FILTER] Assigned filter applied: " . count($filter_assigned) . " users");
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // v4.14: Строгая валидация sort_by через switch (защита от SQL-инъекции)
    $order_column = '';
    switch ($sort_by) {
        case 'title':
            $order_column = 't.title';
            break;
        case 'status':
            $order_column = 't.status';
            break;
        case 'deadline':
            $order_column = 't.time_end_plan';
            break;
        case 'last_activity':
        default:
            $order_column = 'COALESCE(last_msg_time, t.time)';
            $sort_by = 'last_activity';
            break;
    }
    
    // Валидация направления сортировки
    $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';
    
    // Для сортировки по активности добавляем вторичную сортировку по времени создания
    $order_secondary = ($sort_by === 'last_activity') ? ', t.time DESC' : '';
    
    // ========== V4.15: ПРОВЕРЯЕМ СУЩЕСТВОВАНИЕ ТАБЛИЦЫ user_notifications ==========
    $table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
    $has_notifications_table = ($table_check && $table_check->num_rows > 0);
    
    // ========== V4.15: ПОЛУЧАЕМ КОЛИЧЕСТВО НЕПРОЧИТАННЫХ УВЕДОМЛЕНИЙ ПО КАЖДОЙ ЗАДАЧЕ ==========
    // Получаем все задачи, удовлетворяющие WHERE условиям
    $all_tasks_sql = "SELECT t.uuid FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      {$where_clause}";
    
    $all_tasks_stmt = $db->prepare($all_tasks_sql);
    $all_tasks_params = array_merge([$user_uuid], $params);
    $all_tasks_types = "s" . $types;
    $all_tasks_stmt->bind_param($all_tasks_types, ...$all_tasks_params);
    $all_tasks_stmt->execute();
    $all_tasks_result = $all_tasks_stmt->get_result();
    $task_uuids = [];
    while ($row = $all_tasks_result->fetch_assoc()) {
        $task_uuids[] = $row['uuid'];
    }
    $all_tasks_stmt->close();
    
    // Получаем количество непрочитанных уведомлений по каждой задаче
    $unread_counts = [];
    if ($has_notifications_table && !empty($task_uuids)) {
        $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
        $unread_sql = "
            SELECT n.task_uuid, COUNT(*) as unread_count
            FROM user_notifications n
            WHERE n.user_uuid = ?
            AND n.is_read = 0
            AND n.task_uuid IN ($placeholders)
            GROUP BY n.task_uuid
        ";
        $unread_stmt = $db->prepare($unread_sql);
        $unread_params = array_merge([$user_uuid], $task_uuids);
        $unread_types = 's' . str_repeat('s', count($task_uuids));
        $unread_stmt->bind_param($unread_types, ...$unread_params);
        $unread_stmt->execute();
        $unread_result = $unread_stmt->get_result();
        while ($row = $unread_result->fetch_assoc()) {
            $unread_counts[$row['task_uuid']] = (int)$row['unread_count'];
        }
        $unread_stmt->close();
        log_debug("[GET_PROJECT_TASKS_SORTED] Found " . count($unread_counts) . " tasks with unread notifications");
    }
    
    // Получаем общее количество задач
    $count_sql = "SELECT COUNT(*) as total 
                  FROM tasks t
                  JOIN projects p ON t.project_uuid = p.uuid
                  LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                  {$where_clause}";
    
    $count_params = array_merge([$user_uuid], $params);
    $count_types = "s" . $types;
    
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    log_debug("[GET_PROJECT_TASKS_SORTED] Total tasks matching filters: {$total}");
    
    if ($total === 0) {
        return ['tasks' => [], 'total' => 0, 'has_more' => false];
    }
    
    // ========== V4.16: ПРАВИЛЬНОЕ ФОРМИРОВАНИЕ SQL И ПАРАМЕТРОВ ==========
    // Базовые параметры: user_uuid для upp + все параметры из WHERE
    $base_params = array_merge([$user_uuid], $params);
    $base_types = "s" . $types;
    
    // Если таблица уведомлений существует, добавляем подзапрос unc
    if ($has_notifications_table) {
        // Для подзапроса unc нужен дополнительный user_uuid
        $data_sql = "SELECT t.*, 
                            u.name as assignee_name, 
                            u.login as assignee_login,
                            (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                            (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                            (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                            (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count,
                            COALESCE(unc.unread_count, 0) as unread_notifications_count
                     FROM tasks t
                     JOIN projects p ON t.project_uuid = p.uuid
                     LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                     LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                     LEFT JOIN (
                         SELECT task_uuid, COUNT(*) as unread_count
                         FROM user_notifications
                         WHERE user_uuid = ?
                         AND is_read = 0
                         GROUP BY task_uuid
                     ) unc ON t.uuid = unc.task_uuid
                     {$where_clause}
                     ORDER BY 
                         CASE WHEN COALESCE(unc.unread_count, 0) > 0 THEN 0 ELSE 1 END ASC,
                         {$order_column} {$order_dir}{$order_secondary}
                     LIMIT ? OFFSET ?";
        
        // Параметры: upp.user_uuid (1) + unc.user_uuid (2) + все params из WHERE + per_page + offset
        $data_params = array_merge([$user_uuid], [$user_uuid], $params, [$per_page, $offset]);
        $data_types = "ss" . $types . "ii";
        
        log_debug("[GET_PROJECT_TASKS_SORTED] Data params count: " . count($data_params) . ", types: " . $data_types);
        
    } else {
        // Без подзапроса unc
        $data_sql = "SELECT t.*, 
                            u.name as assignee_name, 
                            u.login as assignee_login,
                            (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                            (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                            (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                            (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count,
                            0 as unread_notifications_count
                     FROM tasks t
                     JOIN projects p ON t.project_uuid = p.uuid
                     LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                     LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                     {$where_clause}
                     ORDER BY {$order_column} {$order_dir}{$order_secondary}
                     LIMIT ? OFFSET ?";
        
        $data_params = array_merge([$user_uuid], $params, [$per_page, $offset]);
        $data_types = "s" . $types . "ii";
    }
    
    $stmt = $db->prepare($data_sql);
    
    // Проверка что количество параметров совпадает
    if (count($data_params) !== substr_count($data_types, 's') + substr_count($data_types, 'i')) {
        log_error("[GET_PROJECT_TASKS_SORTED] Parameter count mismatch: params=" . count($data_params) . ", types_count=" . (substr_count($data_types, 's') + substr_count($data_types, 'i')));
        log_error("[GET_PROJECT_TASKS_SORTED] data_types: " . $data_types);
        log_error("[GET_PROJECT_TASKS_SORTED] data_params: " . json_encode($data_params));
        return ['tasks' => [], 'total' => 0, 'has_more' => false];
    }
    
    $stmt->bind_param($data_types, ...$data_params);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($tasks as &$task) {
        $task['can_edit'] = msgql_can_edit_task($user_uuid, $task['uuid']);
        $task['messages_count'] = (int)($task['messages_count'] ?? 0);
        $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
        $task['files_count'] = (int)($task['files_count'] ?? 0);
        $task['status'] = (int)($task['status'] ?? 0);
        $task['unread_notifications_count'] = (int)($task['unread_notifications_count'] ?? 0);
        $task['has_unread_notifications'] = ($task['unread_notifications_count'] > 0);
    }
    
    log_debug("[GET_PROJECT_TASKS_SORTED] Returning " . count($tasks) . " tasks, " . count(array_filter($tasks, function($t) { return $t['has_unread_notifications']; })) . " with unread notifications");
    
    return [
        'tasks' => $tasks,
        'total' => $total,
        'has_more' => ($offset + $per_page) < $total
    ];
}
// ==================== BLOCK END: get_project_tasks_sorted v4.16 ====================


// ==================== BLOCK START: get_project_subtasks_sorted v4.8 ====================
// ver.4.5 - Новая функция для загрузки подзадач
// ver.4.6 (2026-06-02) - ИСПРАВЛЕНА ПЕРЕДАЧА ПАРАМЕТРОВ
// ver.4.7 (2026-06-05) - ИСПРАВЛЕНА SQL-ИНЪЕКЦИЯ В ORDER BY (строгая валидация через switch)
// ver.4.8 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА КОРРЕКТНОСТИ ТИПОВ ПАРАМЕТРОВ

function get_project_subtasks_sorted($project_uuid, $parent_uuid, $user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
    log_debug("[GET_PROJECT_SUBTASKS_SORTED] v4.8 - project_uuid: {$project_uuid}, parent_uuid: {$parent_uuid}, page: {$page}, per_page: {$per_page}");
    
    if (empty($parent_uuid)) {
        log_warning("[GET_PROJECT_SUBTASKS_SORTED] parent_uuid is empty, returning empty result");
        return ['tasks' => [], 'total' => 0, 'has_more' => false];
    }
    
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ["t.project_uuid = ?", "t.parent_task_uuid = ?"];
    $params = [$project_uuid, $parent_uuid];
    $types = "ss";
    
    if (!$is_admin) {
        $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
        $params[] = $user_uuid;
        $types .= "s";
    }
    
    if (!empty($filter_statuses) && is_array($filter_statuses)) {
        $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
        $where_conditions[] = "t.status IN ($status_placeholders)";
        foreach ($filter_statuses as $status) {
            $params[] = (int)$status;
            $types .= "i";
        }
        log_debug("[FILTER] Status filter applied for subtasks: " . implode(',', $filter_statuses));
    }
    
    if (!empty($filter_assigned) && is_array($filter_assigned)) {
        $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
        $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
        foreach ($filter_assigned as $assigned_uuid) {
            $params[] = $assigned_uuid;
            $types .= "s";
        }
        log_debug("[FILTER] Assigned filter applied for subtasks: " . count($filter_assigned) . " users");
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // v4.8: Строгая валидация sort_by через switch (защита от SQL-инъекции)
    $order_column = '';
    switch ($sort_by) {
        case 'title':
            $order_column = 't.title';
            break;
        case 'status':
            $order_column = 't.status';
            break;
        case 'deadline':
            $order_column = 't.time_end_plan';
            break;
        case 'last_activity':
        default:
            $order_column = 'COALESCE(last_msg_time, t.time)';
            break;
    }
    
    $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';
    
    $count_sql = "SELECT COUNT(*) as total 
                  FROM tasks t
                  JOIN projects p ON t.project_uuid = p.uuid
                  LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                  {$where_clause}";
    
    $count_params = array_merge([$user_uuid], $params);
    $count_types = "s" . $types;
    
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    log_debug("[GET_PROJECT_SUBTASKS_SORTED] Total subtasks for parent {$parent_uuid}: {$total}");
    
    if ($total === 0) {
        return ['tasks' => [], 'total' => 0, 'has_more' => false];
    }
    
    // v4.8: Безопасное использование order_column (уже проверено через switch)
    $data_sql = "SELECT t.*, 
                        u.name as assignee_name, 
                        u.login as assignee_login,
                        (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                        (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                        (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                        (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count
                 FROM tasks t
                 JOIN projects p ON t.project_uuid = p.uuid
                 LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                 LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                 {$where_clause}
                 ORDER BY {$order_column} {$order_dir}
                 LIMIT ? OFFSET ?";
    
    $data_params = array_merge([$user_uuid], $params, [$per_page, $offset]);
    $data_types = "s" . $types . "ii";
    
    $stmt = $db->prepare($data_sql);
    $stmt->bind_param($data_types, ...$data_params);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($tasks as &$task) {
        $task['can_edit'] = msgql_can_edit_task($user_uuid, $task['uuid']);
        $task['messages_count'] = (int)($task['messages_count'] ?? 0);
        $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
        $task['files_count'] = (int)($task['files_count'] ?? 0);
        $task['status'] = (int)($task['status'] ?? 0);
    }
    
    log_debug("[GET_PROJECT_SUBTASKS_SORTED] Returning " . count($tasks) . " subtasks for parent {$parent_uuid}");
    
    usort($tasks, function($a, $b) {
        $timeA = $a['last_msg_time'] ?? $a['time'] ?? 0;
        $timeB = $b['last_msg_time'] ?? $b['time'] ?? 0;
        return $timeB - $timeA;
    });
    
    return [
        'tasks' => $tasks,
        'total' => $total,
        'has_more' => ($offset + $per_page) < $total
    ];
}
// ==================== BLOCK END: get_project_subtasks_sorted v4.8 ====================


function get_project_data($uuid, $db) {
    $stmt = $db->prepare("SELECT p.*,
        u.name as creator_name,
        u.login as creator_login,
        (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
        FROM projects p
        LEFT JOIN users u ON p.created_by_uuid = u.uuid
        WHERE p.uuid = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

function get_task_data($uuid, $current_user_uuid, $db) {
    if (!msgql_can_access_task($current_user_uuid, $uuid, 'view')) {
        return null;
    }
    
    $stmt = $db->prepare("SELECT t.*, 
        u.name as assignee_name, 
        u.login as assignee_login,
        (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
        (SELECT COUNT(*) FROM messages WHERE task_uuid = t.uuid) as messages_count
        FROM tasks t 
        LEFT JOIN users u ON t.assigned_to_uuid = u.uuid 
        WHERE t.uuid = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($task) {
        $task['time_start_utc'] = $task['time_start'];
        $task['time_end_plan_utc'] = $task['time_end_plan'];
        $task['status'] = (int)($task['status'] ?? 0);
        $task['messages_count'] = (int)($task['messages_count'] ?? 0);
        $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
    }
    return $task;
}

function get_task_files($task_uuid, $db) {
    log_debug("[GET_TASK_FILES] Called for task_uuid: " . ($task_uuid ?: 'EMPTY'));
    
    $stmt = $db->prepare("SELECT f.* FROM files f
        JOIN task_files tf ON f.uuid = tf.file_uuid
        WHERE tf.task_uuid = ?");
    
    if (!$stmt) {
        log_error("[GET_TASK_FILES] Prepare failed: " . $db->error);
        return [];
    }
    
    $stmt->bind_param("s", $task_uuid);
    
    if (!$stmt->execute()) {
        log_error("[GET_TASK_FILES] Execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $files = [];
    
    while ($row = $result->fetch_assoc()) {
        $files[] = [
            'uuid' => $row['uuid'],
            'name' => $row['orig_name'],
            'size' => msgql_format_file_size($row['size_bytes']),
            'size_bytes' => (int)$row['size_bytes'],
            'mime' => $row['mime'],
            'url' => "download.php?file={$row['uuid']}"
        ];
    }
    
    $stmt->close();
    log_debug("[GET_TASK_FILES] Returning " . count($files) . " files");
    return $files;
}

function get_all_users($db) {
    $res = $db->query("SELECT uuid, name, login, email FROM users WHERE status = 0 ORDER BY name, login");
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
    return $users;
}

// ==================== BLOCK START: get_users_with_project_access v1.3 ====================
// ver.1.0 (2026-06-05) - ПОЛУЧЕНИЕ СПИСКА ПОЛЬЗОВАТЕЛЕЙ, ИМЕЮЩИХ ДОСТУП К ПРОЕКТУ
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНА ОШИБКА bind_param
// ver.1.2 (2026-06-05) - ПРАВИЛЬНЫЙ ПОДСЧЁТ: 6 плейсхолдеров, 6 переменных
// ver.1.3 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА СУЩЕСТВОВАНИЯ ПАРАМЕТРОВ ПЕРЕД bind_param

function get_users_with_project_access($db, $project_uuid, $current_user_uuid, $is_admin) {
    if ($is_admin) {
        // Администратор видит всех активных пользователей
        $stmt = $db->prepare("SELECT uuid, name, login FROM users WHERE status = 0 ORDER BY name, login");
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        log_debug("[GET_USERS_WITH_ACCESS] Admin mode: found " . count($users) . " users");
        return $users;
    }
    
    // Для не-администратора: только пользователи, имеющие доступ к проекту
    // v1.3: Проверяем, что все параметры не пустые
    if (empty($project_uuid) || empty($current_user_uuid)) {
        log_warning("[GET_USERS_WITH_ACCESS] Missing parameters: project_uuid={$project_uuid}, user_uuid={$current_user_uuid}");
        return [];
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT u.uuid, u.name, u.login
        FROM users u
        WHERE u.status = 0
        AND (
            u.role = 0
            OR u.uuid = ?
            OR u.uuid IN (
                SELECT assigned_to_uuid FROM tasks WHERE project_uuid = ? AND assigned_to_uuid IS NOT NULL
            )
            OR u.uuid IN (
                SELECT user_uuid FROM user_project_permissions 
                WHERE project_uuid = ? AND can_view = 1
            )
            OR u.uuid IN (
                SELECT user_uuid FROM user_project_permissions 
                WHERE project_uuid = ? AND can_edit_tasks = 1
            )
            OR u.uuid IN (
                SELECT user_uuid FROM user_project_permissions 
                WHERE project_uuid = ? AND can_edit_messages = 1
            )
            OR u.uuid IN (
                SELECT user_uuid FROM user_project_permissions 
                WHERE project_uuid = ? AND can_upload_files = 1
            )
        )
        ORDER BY u.name, u.login
    ");
    
    // v1.3: 6 плейсхолдеров -> 6 переменных
    $stmt->bind_param(
        "ssssss",
        $current_user_uuid,
        $project_uuid,
        $project_uuid,
        $project_uuid,
        $project_uuid,
        $project_uuid
    );
    
    log_debug("[GET_USERS_WITH_ACCESS] Executing query for project: {$project_uuid}");
    
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    log_debug("[GET_USERS_WITH_ACCESS] Found " . count($users) . " users with access to project: {$project_uuid}");
    return $users;
}
// ==================== BLOCK END: get_users_with_project_access v1.3 ====================

// Подготовка данных для шаблона
// ==================== BLOCK START: projects initialization with notification badges v1.0 ====================
// ver.1.0 (2026-06-16) - ЗАМЕНА msgql_get_accessible_projects НА get_projects_with_notification_counts
// - Добавлено поле unread_notifications_count для отображения бейджей на карточках проектов

// Получаем проекты с количеством непрочитанных уведомлений
if (function_exists('get_projects_with_notification_counts')) {
    $projects = get_projects_with_notification_counts($current_user_uuid);
    log_debug("[PROJECTS_INIT] Loaded " . count($projects) . " projects with notification counts");
} else {
    // Fallback на старую функцию
    $projects = msgql_get_accessible_projects($current_user_uuid);
    log_warning("[PROJECTS_INIT] get_projects_with_notification_counts not found, using fallback");
}
// ==================== BLOCK END: projects initialization with notification badges v1.0 ====================


$users = get_all_users($db);
$is_admin = msgql_is_admin();
$csrf_token = msgql_csrf_get_token();

$selected_project_for_highlight = $selected_project_uuid ?: $highlight_project_uuid;
if (!$selected_project_for_highlight && $default_project_uuid) {
    $selected_project_for_highlight = $default_project_uuid;
    log_debug("[INIT] Using default project for highlight: {$default_project_uuid}");
}
// ==================== BLOCK START: userUuidForFilters v4.8 ====================
// ver.4.8 (2026-06-03) - ДОБАВЛЕНА ПЕРЕДАЧА UUID ПОЛЬЗОВАТЕЛЯ В JS ДЛЯ ФИЛЬТРОВ
?>
<script nonce="<?= CSP_NONCE ?>">
    window.currentUserUuid = '<?= htmlspecialchars($current_user_uuid, ENT_QUOTES) ?>';
    if (typeof logDebug === 'function') {
        logDebug('[INIT] User UUID for filter settings:', window.currentUserUuid);
    }
</script>
<?php
// ==================== BLOCK END: userUuidForFilters v4.8 ====================

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Проекты</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
/* Стили для переключателя подзадач */
.subtasks-toggle {
    cursor: pointer;
    position: relative;
    z-index: 5;
}
/* Переопределение стилей уведомлений из header_internal.php */
.custom-alert {
    position: fixed !important;
    bottom: 20px !important;
    right: 20px !important;
    left: auto !important;
    top: auto !important;
    max-width: 300px !important;
    min-width: 200px !important;
    width: auto !important;
    padding: 10px 20px !important;
    z-index: 10000 !important;
    font-size: 14px !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2) !important;
}

@media (max-width: 768px) {
    .custom-alert {
        max-width: calc(100% - 40px) !important;
        right: 20px !important;
        left: auto !important;
        bottom: 20px !important;
    }
}

body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#0b1020; color:#e9eefc; overflow-x: hidden; width: 100%; max-width: 100%;}
a{color:#9bb7ff; text-decoration:none;} a:hover{text-decoration:underline;}

.wrap{max-width:1200px; margin:0 auto; padding:24px; width: 100%; overflow-x: hidden;}
.projects-container { min-height: calc(100vh - 200px); width: 100%; max-width: 100%; overflow-x: hidden; }
.projects-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.projects-header h2 { margin: 0; font-size: 24px; font-weight: 600; }

.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 32px; width: 100%; }
.project-card { background: #121a33; border-radius: 12px; border: 1px solid rgba(255,255,255,.08); overflow: hidden; transition: all 0.2s; cursor: pointer; width: 100%; }
.project-card:hover { border-color: #4f7cff; transform: translateY(-2px); }
.project-card.selected { border-color: #4f7cff; box-shadow: 0 0 0 2px rgba(79,124,255,.3); }
.project-card-header { padding: 16px 20px; background: linear-gradient(135deg, #4f7cff 0%, #7c3aed 100%); }
.project-card-header h3 { margin: 0 0 8px 0; font-size: 16px; font-weight: 600; word-break: break-word; }
.project-card-meta { font-size: 11px; opacity: .9; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.project-card-body { padding: 14px 20px; }
.project-descr { color: rgba(233,238,252,.7); font-size: 13px; margin-bottom: 12px; line-height: 1.4; word-break: break-word; user-select: text !important; -webkit-user-select: text !important; cursor: text !important; }
.project-stats { display: flex; gap: 12px; font-size: 12px; color: rgba(233,238,252,.6); padding-top: 10px; border-top: 1px solid rgba(255,255,255,.06); flex-wrap: wrap; }
.project-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }

.external-link, .telegram-link {
    display: inline-block;
    background: rgba(79,124,255,0.15);
    padding: 2px 8px;
    border-radius: 16px;
    font-size: 12px;
    text-decoration: none;
    transition: all .2s;
    margin: 0 2px;
    border-left: 2px solid #f59e0b;
    color: #f59e0b;
    word-break: break-all;
    pointer-events: auto !important;
    cursor: pointer;
}
.external-link:hover, .telegram-link:hover { background: rgba(79,124,255,0.3); text-decoration: none; transform: translateY(-1px); }
.telegram-link { border-left-color: #26a5e4; color: #26a5e4; }

.tasks-view { background: #121a33; border-radius: 12px; border: 1px solid rgba(255,255,255,.08); margin-top: 24px; width: 100%; }
.tasks-header { padding: 14px 20px; background: #0f1529; border-bottom: 1px solid rgba(255,255,255,.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
.tasks-header h3 { margin: 0; font-size: 16px; font-weight: 600; word-break: break-word; }

.tasks-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 20px;
    background: #0f1529;
    border-bottom: 1px solid rgba(255,255,255,.06);
}
.filter-group { flex: 1; min-width: 140px; }
.filter-group-multi { flex: 1; min-width: 180px; position: relative; }
.search-input, .filter-select {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.15);
    background: #0b1020;
    color: #e9eefc;
    font-size: 13px;
    font-family: inherit;
}
.search-input:focus, .filter-select:focus { outline: none; border-color: #4f7cff; }
.per-page-group { min-width: 110px; }

.multi-select-container { position: relative; }
.multi-select-button {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.15);
    background: #0b1020;
    color: #e9eefc;
    font-size: 13px;
    cursor: pointer;
    text-align: left;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.multi-select-button:hover { border-color: #4f7cff; }
.multi-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #1e293b;
    border: 1px solid #4f7cff;
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
    display: none;
    margin-top: 4px;
}
.multi-select-dropdown.show { display: block; }
.multi-select-option {
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #e9eefc;
    font-size: 13px;
}
.multi-select-option:hover { background: #2d3a5e; }
.multi-select-option input { margin: 0; width: 16px; height: 16px; }

.tasks-list-container { min-height: 200px; }
.flat-tasks-list { display: flex; flex-direction: column; }
.flat-task-item {
    display: block;
    border-bottom: 1px solid rgba(255,255,255,.06);
    transition: background 0.2s;
}
.flat-task-row {
    display: flex;
    align-items: flex-start;
    padding: 12px 16px;
    gap: 12px;
    cursor: pointer;
}
.flat-task-item:hover { background: rgba(79,124,255,.08); }
/* Усиленная подсветка задачи */
.flat-task-item.highlight {
    background: rgba(79,124,255,0.5) !important;
    border-left: 4px solid #4f7cff !important;
    box-shadow: 0 0 0 2px rgba(79,124,255,0.3) !important;
    transition: all 0.3s ease !important;
    animation: pulse-highlight 1s ease 3 !important;
}

@keyframes pulse-highlight {
    0%, 100% { 
        background: rgba(79,124,255,0.5);
        border-left-color: #4f7cff;
    }
    50% { 
        background: rgba(79,124,255,0.8);
        border-left-color: #ffaa00;
    }
}

/* Для подзадач */
.subtask-item.highlight {
    background: rgba(79,124,255,0.5) !important;
    border-left: 4px solid #4f7cff !important;
    box-shadow: 0 0 0 2px rgba(79,124,255,0.3) !important;
    transition: all 0.3s ease !important;
    animation: pulse-highlight 1s ease 3 !important;
}

.flat-task-checkbox {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,.3);
    border-radius: 4px;
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 2px;
    transition: all 0.2s;
}
.flat-task-checkbox.completed { background: #4f7cff; border-color: #4f7cff; position: relative; }
.flat-task-checkbox.completed::after {
    content: '✓';
    color: white;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 100%;
    height: 100%;
}

.flat-task-content { flex: 1; min-width: 0; }
.flat-task-title { font-size: 14px; font-weight: 600; word-break: break-word; }
.flat-task-title.completed { text-decoration: line-through; opacity: .6; }

.task-description, .task-description-empty {
    font-size: 12px;
    color: rgba(233,238,252,.7);
    margin: 6px 0 6px 24px;
    padding: 6px 10px;
    background: rgba(0,0,0,.2);
    border-radius: 8px;
    border-left: 2px solid #4f7cff;
    word-break: break-word;
    white-space: pre-wrap;
    user-select: text !important;
    -webkit-user-select: text !important;
    cursor: text !important;
}
.task-description-empty { font-style: italic; color: rgba(233,238,252,.4); }

.collapsible-description { position: relative; word-break: break-word; white-space: pre-wrap; }
.collapsible-description.collapsed {
    max-height: 15em;
    overflow-y: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 8;
    line-clamp: 8;
    -webkit-box-orient: vertical;
}
.collapsible-description-toggle {
    display: inline-block;
    background: rgba(79,124,255,0.2);
    border: 1px solid rgba(79,124,255,0.4);
    border-radius: 16px;
    padding: 2px 10px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.2s;
    margin: 4px 0 4px 24px;
    color: #9bb7ff;
}
.collapsible-description-toggle:hover {
    background: rgba(79,124,255,0.35);
    border-color: #4f7cff;
}
.collapsible-description-toggle:before { content: '▼ '; font-size: 10px; }
.collapsible-description-toggle.collapsed:before { content: '▶ '; }

.flat-task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
    font-size: 11px;
    color: rgba(233,238,252,.6);
}
.flat-task-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,.1);
    padding: 2px 8px;
    border-radius: 12px;
}
.flat-task-badge.highlight { color: #f87171; }
.flat-task-badge.clickable { cursor: pointer; transition: background 0.2s; }
.flat-task-badge.clickable:hover { background: rgba(79,124,255,0.3); }

.flat-task-actions {
    display: flex;
    gap: 8px;
    opacity: 0;
    transition: opacity 0.2s;
    flex-shrink: 0;
    align-items: center;
}
.flat-task-item:hover .flat-task-actions { opacity: 1; }
.task-files-container { margin-top: 8px; margin-left: 24px; display: flex; flex-wrap: wrap; gap: 6px; }
.task-file-item {
    background: rgba(79,124,255,0.12);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px;
    color: #9bb7ff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.task-file-item:hover { background: rgba(79,124,255,0.25); text-decoration: none; }
.files-loading { font-size: 11px; color: rgba(233,238,252,.5); font-style: italic; }

/* Подзадачи */
.subtasks-container {
    margin-left: 32px;
    display: none;
}
.subtasks-container.expanded { display: block; }
.subtasks-loading { padding: 16px; text-align: center; color: rgba(233,238,252,0.5); }
.subtasks-list { display: flex; flex-direction: column; }
.subtask-item { border-left: 2px solid rgba(79,124,255,0.3); margin-left: 8px; }

.tasks-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    padding: 16px 20px;
    border-top: 1px solid rgba(255,255,255,.08);
    flex-wrap: wrap;
}
.tasks-pagination .btn-primary, .tasks-pagination .btn-secondary { padding: 6px 12px; font-size: 13px; }
.pagination-info { margin-left: 12px; font-size: 12px; color: rgba(233,238,252,.5); }

.modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.6); z-index: 1000; align-items: center; justify-content: center; }
.modal.active { display: flex; }
.modal-content { background: #121a33; border-radius: 14px; width: 95%; max-width: 600px; max-height: 90vh; overflow-y: auto; border: 1px solid rgba(255,255,255,.1); }
.modal-header { padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; justify-content: space-between; align-items: center; }
.modal-header h3 { margin: 0; font-size: 16px; word-break: break-word; }
.modal-close { cursor: pointer; font-size: 22px; color: rgba(233,238,252,.6); }
.modal-body { padding: 20px; }
.modal-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,.15); background: #0b1020; color: #e9eefc; font-size: 14px; font-family: inherit; }
.form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: #4f7cff; }
.confirm-input { background: rgba(220,38,38,0.1); border-color: #dc2626 !important; }
#task-descr { min-height: 200px; resize: vertical; }

.btn-primary { background: #4f7cff; color: white; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
.btn-primary:hover { background: #3b6ef5; }
.btn-secondary { background: #1e293b; color: #e9eefc; border: 1px solid rgba(255,255,255,.1); padding: 8px 16px; border-radius: 10px; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-secondary:hover { background: #334155; }
.btn-danger { background: #dc2626; color: white; border: none; padding: 8px 16px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-danger:hover { background: #b91c1c; }
.btn-messages-icon {
    background: #4f7cff20;
    border: 1px solid #4f7cff40;
    border-radius: 8px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
    color: #9bb7ff;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-messages-icon:hover { background: #4f7cff40; border-color: #4f7cff80; }

.tasks-loading, .empty-state { text-align: center; padding: 40px 20px; color: rgba(233,238,252,.5); }

@media (max-width: 768px) {
    .wrap { padding: 12px; }
    .projects-header h2 { font-size: 20px; }
    .btn-primary, .btn-secondary, .btn-danger { padding: 6px 12px; font-size: 13px; }
    .tasks-header { padding: 10px 16px; }
    .tasks-header h3 { font-size: 14px; }
    .tasks-filters { flex-direction: column; gap: 8px; }
    .filter-group, .filter-group-multi { min-width: auto; }
    .flat-task-row { padding: 10px 12px; flex-wrap: wrap; }
    .flat-task-actions { opacity: 1; width: 100%; justify-content: flex-end; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,.1); }
    .flat-task-meta { width: 100%; }
    .tasks-pagination { flex-wrap: wrap; }
    .projects-grid { grid-template-columns: 1fr; gap: 12px; }
    .task-description, .task-description-empty { margin-left: 0 !important; margin-right: 0 !important; width: 100% !important; }
    .task-files-container { margin-left: 0 !important; }
    .subtasks-container { margin-left: 16px; }
    .collapsible-description-toggle { margin-left: 0; }
}
@media (max-width: 480px) {
    .wrap { padding: 8px; }
    .project-card-header { padding: 12px 16px; }
    .project-card-body { padding: 10px 16px; }
    .flat-task-title { font-size: 13px; }
    .flat-task-meta { gap: 6px; }
    .flat-task-badge { font-size: 10px; padding: 2px 6px; }
}

/* Простые стили для разделения подзадач */
.subtask-item {
    border-bottom: 1px solid rgba(255,255,255,0.05);
    margin-left: 24px;
    padding-left: 8px;
}
.subtask-item:last-child {
    border-bottom: none;
}
.subtask-item .flat-task-row {
    padding: 10px 12px;
}
/* Левая граница для родительской задачи */
.flat-task-item .subtasks-container {
    border-left: 2px solid rgba(79,124,255,0.2);
    margin-left: 24px;
}


/* Разрешаем выделение текста в описаниях задач */
.task-description, .task-description-empty, .project-descr {
    user-select: text !important;
    -webkit-user-select: text !important;
    cursor: text !important;
}

/* Отключаем обработку клика на контейнере описания, чтобы не мешать выделению */
.task-description, .task-description-empty {
    pointer-events: auto !important;
}

/* Убираем курсор pointer с родительских контейнеров, чтобы не мешать выделению */
.flat-task-row, .flat-task-row-link {
    cursor: default;
}

/* Но оставляем pointer на кликабельных элементах */
.flat-task-checkbox, .btn-secondary, .btn-messages-icon, .subtasks-toggle, .task-file-item, .flat-task-badge.clickable {
    cursor: pointer;
}

/* Для заголовка задачи оставляем pointer, но разрешаем выделение */
.flat-task-title {
    cursor: default;
    user-select: text !important;
    -webkit-user-select: text !important;
}

/* ==================== FIX: Mobile subtasks toggle button visibility v1.0 ==================== */
/* Исправляет отображение кнопки раскрытия подзадач на мобильных устройствах */

/* Основные стили для кнопки раскрытия подзадач */
.subtasks-toggle {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    padding: 2px 8px;
    background: rgba(79, 124, 255, 0.2);
    border-radius: 16px;
    font-size: 11px;
    transition: all 0.2s ease;
    user-select: none;
}

.subtasks-toggle:hover,
.subtasks-toggle:active {
    background: rgba(79, 124, 255, 0.4);
    transform: scale(1.02);
}

/* Убеждаемся, что метка в бейдже не теряет кликабельность */
.flat-task-badge.clickable {
    cursor: pointer;
}

/* Специальные стили для мобильных устройств */
@media (max-width: 768px) {
    .subtasks-toggle {
        display: inline-flex !important;
        background: rgba(79, 124, 255, 0.25);
        padding: 4px 10px !important;
        font-size: 12px !important;
        min-width: 44px; /* Увеличенная область касания для пальцев */
        min-height: 28px;
        justify-content: center;
        border-radius: 20px !important;
    }
    
    .subtasks-toggle:active {
        background: rgba(79, 124, 255, 0.6) !important;
    }
    
    /* Убеждаемся, что контейнер подзадач виден при раскрытии */
    .subtasks-container {
        width: 100%;
        overflow-x: hidden;
    }
    
    .subtasks-container.expanded {
        display: block !important;
    }
    
    /* Подзадачи на мобильных - с отступом */
    .subtask-item {
        margin-left: 8px;
        padding-left: 8px;
        border-left: 2px solid rgba(79, 124, 255, 0.3);
    }
}

/* Для очень маленьких экранов (до 480px) */
@media (max-width: 480px) {
    .subtasks-toggle {
        padding: 4px 8px !important;
        font-size: 10px !important;
        min-width: 40px;
    }
}
/* ==================== END FIX ==================== */

.flat-task-item.has-unread-notifications {
    background: rgba(239, 68, 68, 0.05);
    border-left: 3px solid #ef4444;
}
</style>
<script nonce="<?= CSP_NONCE ?>">window.APP_BASE = '<?= $appBase ?>'</script>
</head>
<body>
<div class="wrap projects-container">
    <div class="projects-header">
        <h2>📁 Проекты</h2>
        <button class="btn-primary" onclick="showCreateProjectModal()" <?= msgql_can_create_project($current_user_uuid) ? '' : 'style="display:none;"' ?> id="create-project-btn">➕ Создать проект</button>
    </div>
    
    <div class="projects-grid" id="projects-grid">
        <?php if (empty($projects)): ?>
            <div style="text-align: center; padding: 40px; color: rgba(233,238,252,.5);">
                Нет доступных проектов. Обратитесь к администратору для выдачи прав.
            </div>
        <?php else: ?>

        <?php foreach ($projects as $project): ?>
            <div class="project-card" id="project-<?= htmlspecialchars($project['uuid']) ?>" 
                 data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>" 
                 data-unread-notifications="<?= (int)($project['unread_notifications_count'] ?? 0) ?>"
                 onclick="selectProject('<?= htmlspecialchars($project['uuid']) ?>')">
                <div class="project-card-header">
                    <h3><?= htmlspecialchars($project['title']) ?></h3>
                    <div class="project-card-meta">
                        <span>📅 <?= htmlspecialchars($project['stamp']) ?></span>
                        <span>👤 <?= htmlspecialchars($project['creator_name'] ?: $project['creator_login'] ?: 'Вы') ?></span>
                    </div>
                </div>
                <div class="project-card-body">
                    <div class="project-descr" data-desc-id="project_<?= htmlspecialchars($project['uuid']) ?>_desc">
                        <?= msgql_parse_links_to_html($project['descr'] ?: 'Нет описания') ?>
                    </div>
                    <div class="project-stats" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>📋 Задач: <?= $project['tasks_count'] ?? 0 ?></span>
                        <!-- ========== БЕЙДЖ НЕПРОЧИТАННЫХ УВЕДОМЛЕНИЙ ========== -->
                        <span class="project-notification-badge" 
                              id="project-badge-<?= htmlspecialchars($project['uuid']) ?>"
                              data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>"
                              style="display: <?= ((int)($project['unread_notifications_count'] ?? 0) > 0) ? 'inline-flex' : 'none' ?>; 
                                     background: #ef4444; 
                                     color: white; 
                                     border-radius: 20px; 
                                     padding: 2px 10px; 
                                     font-size: 11px; 
                                     font-weight: bold; 
                                     cursor: pointer; 
                                     transition: all 0.2s ease;
                                     box-shadow: 0 1px 3px rgba(0,0,0,0.3);
                                     align-items: center;
                                     gap: 4px;"
                             onclick="event.stopPropagation(); scrollToNotifications('<?= htmlspecialchars($project['uuid']) ?>');"
                             title="Непрочитанные уведомления в проекте">
                            🔔 <span id="badge-count-<?= htmlspecialchars($project['uuid']) ?>"><?= (int)($project['unread_notifications_count'] ?? 0) ?></span>
                        </span>
                        <!-- ========== КОНЕЦ БЕЙДЖА ========== -->
                    </div>
                    <?php if ($is_admin || $project['created_by_uuid'] === $current_user_uuid): ?>
                        <div class="project-actions" onclick="event.stopPropagation()">
                            <button class="btn-secondary" onclick="editProject('<?= htmlspecialchars($project['uuid']) ?>')">✏️ Редактировать</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div id="tasks-area" style="display: none;">
        <div class="tasks-view">
            <div class="tasks-header">
                <h3 id="selected-project-title">Задачи проекта</h3>
                <button class="btn-primary" id="create-task-btn" onclick="showCreateTaskModal()">➕ Новая задача</button>
            </div>
            
            <div class="tasks-filters">
                <div class="filter-group">
                    <input type="text" id="task-search" class="search-input" placeholder="🔍 Поиск по названию или описанию...">
                </div>
                
                <div class="filter-group-multi" id="status-filter-container">
                    <div class="multi-select-container">
                        <div class="multi-select-button" id="status-filter-btn">
                            <span>📊 Статус: все</span>
                            <span>▼</span>
                        </div>
                        <div class="multi-select-dropdown" id="status-filter-dropdown">
                            <div class="multi-select-option">
                                <input type="checkbox" value="0" id="status-active"> <label for="status-active">🟢 Активные</label>
                            </div>
                            <div class="multi-select-option">
                                <input type="checkbox" value="1" id="status-completed"> <label for="status-completed">✅ Выполненные</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="filter-group-multi" id="assignee-filter-container">
                    <div class="multi-select-container">
                        <div class="multi-select-button" id="assignee-filter-btn">
                            <span>👤 Исполнители: все</span>
                            <span>▼</span>
                        </div>
                        <div class="multi-select-dropdown" id="assignee-filter-dropdown">
                            <!-- Список будет загружаться динамически через AJAX -->
                            <div class="assignee-loading">⏳ Загрузка списка пользователей...</div>
                        </div>
                    </div>
                </div>
                
                <div class="filter-group">
                    <select id="sort-by" class="filter-select">
                        <option value="last_activity">📅 По активности (новые сверху)</option>
                        <option value="title">📝 По названию</option>
                        <option value="status">🔄 По статусу</option>
                        <option value="deadline">⏰ По сроку</option>
                    </select>
                </div>
                
                <div class="filter-group per-page-group">
                    <select id="per-page" class="filter-select">
                        <option value="5">5 на странице</option>
                        <option value="10" selected>10 на странице</option>
                        <option value="15">15 на странице</option>
                        <option value="25">25 на странице</option>
                        <option value="50">50 на странице</option>
                        <option value="100">100 на странице</option>
                        <option value="200">200 на странице</option>
                        <option value="500">500 на странице</option>
                        <option value="1000">1000 на странице</option>
                    </select>
                </div>
            </div>
            
            <div class="tasks-list-container" id="tasks-list-container">
                <div class="tasks-loading">📋 Выберите проект для просмотра задач</div>
            </div>
            
            <div class="tasks-pagination" id="tasks-pagination" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- Модальное окно для проекта -->
<div id="project-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="project-modal-title">Создание проекта</h3>
            <span class="modal-close" onclick="closeModal('project-modal')">&times;</span>
        </div>
        <form id="project-form" onsubmit="saveProject(event)">
            <div class="modal-body">
                <input type="hidden" id="project-uuid" name="uuid">
                <div class="form-group">
                    <label>Название проекта *</label>
                    <input type="text" id="project-title" name="title" required>
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea id="project-descr" name="descr" rows="4"></textarea>
                </div>
                <?php if ($is_admin): ?>
                    <div id="project-delete-section" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
                        <hr style="border-color: rgba(220,38,38,0.3); margin-bottom: 16px;">
                        <h4 style="color: #dc2626; margin-bottom: 12px;">⚠️ Опасная зона - удаление проекта</h4>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.7); margin-bottom: 12px;">
                            Удаление проекта приведёт к безвозвратному удалению всех задач, сообщений и файлов проекта.
                            Для подтверждения введите слово <strong style="color: #dc2626;">DELETE</strong> в поле ниже.
                        </p>
                        <div class="form-group">
                            <label style="color: #dc2626;">Подтверждение удаления</label>
                            <input type="text" id="project-delete-confirm" class="confirm-input" placeholder="Введите DELETE для подтверждения" style="border-color: #dc2626;">
                        </div>
                        <button type="button" class="btn-danger" onclick="deleteProjectWithConfirm()" style="width: 100%;">🗑️ УДАЛИТЬ ПРОЕКТ</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('project-modal')">Отмена</button>
                <button type="submit" class="btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно для задачи -->
<div id="task-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="task-modal-title">Создание задачи</h3>
            <span class="modal-close" onclick="closeModal('task-modal')">&times;</span>
        </div>
        <form id="task-form" onsubmit="saveTask(event)">
            <div class="modal-body">
                <input type="hidden" id="task-uuid" name="uuid">
                <input type="hidden" id="task-project-uuid" name="project_uuid">
                <input type="hidden" id="task-parent-uuid-old" name="parent_task_uuid_old">
                
                <div class="form-group">
                    <label>Проект</label>
                    <select id="task-project-select" name="project_uuid" onchange="onTaskProjectChange()">
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= htmlspecialchars($project['uuid']) ?>"><?= htmlspecialchars($project['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Название задачи *</label>
                    <input type="text" id="task-title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label>Описание</label>
                    <textarea id="task-descr" name="descr" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Исполнитель</label>
                    <select id="task-assigned-to" name="assigned_to_uuid">
                        <option value="">Не назначен</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['uuid']) ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Родительская задача</label>
                    <select id="task-parent-select" name="parent_task_uuid">
                        <option value="">-- Нет (корневая задача) --</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Дата начала <span style="color:#9bb7ff; font-size:11px;">(в вашем часовом поясе)</span></label>
                    <input type="datetime-local" id="task-time-start" name="time_start">
                </div>
                
                <div class="form-group">
                    <label>Плановое окончание <span style="color:#9bb7ff; font-size:11px;">(в вашем часовом поясе)</span></label>
                    <input type="datetime-local" id="task-time-end" name="time_end_plan">
                </div>
                
                <div class="file-manager" id="file-manager" style="display: none;">
                    <h4>📎 Прикреплённые файлы</h4>
                    <div class="file-list" id="task-files-list"></div>
                    <div class="upload-area">
                        <input type="file" id="task-file-upload" accept="*/*">
                        <button type="button" class="btn-secondary" onclick="uploadTaskFile()">📤 Загрузить</button>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 16px;">
                    <button type="button" class="btn-secondary btn-messages" id="open-messages-btn" style="display: none;" onclick="openTaskMessages()">💬 Перейти к сообщениям</button>
                </div>
                
                <?php if ($is_admin): ?>
                    <div id="task-delete-section" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
                        <hr style="border-color: rgba(220,38,38,0.3); margin-bottom: 16px;">
                        <h4 style="color: #dc2626; margin-bottom: 12px;">⚠️ Опасная зона - удаление задачи</h4>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.7); margin-bottom: 12px;">
                            Удаление задачи приведёт к безвозвратному удалению всех подзадач, сообщений и файлов.
                            Для подтверждения введите слово <strong style="color: #dc2626;">DELETE</strong> в поле ниже.
                        </p>
                        <div class="form-group">
                            <label style="color: #dc2626;">Подтверждение удаления</label>
                            <input type="text" id="task-delete-confirm" class="confirm-input" placeholder="Введите DELETE для подтверждения" style="border-color: #dc2626;">
                        </div>
                        <button type="button" class="btn-danger" onclick="deleteTaskWithConfirm()" style="width: 100%;">🗑️ УДАЛИТЬ ЗАДАЧУ</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('task-modal')">Отмена</button>
                <button type="submit" class="btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
// ========== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ==========
window.csrfToken = '<?= $csrf_token ?>';
let currentProjectUuid = null;
let projectsData = <?= json_encode($projects, JSON_UNESCAPED_UNICODE) ?>;
let currentTaskPage = 1;
let currentPerPage = 10;
let currentSortBy = 'last_activity';
let currentSortDir = 'DESC';
let currentFilterStatuses = [];
let currentFilterAssigned = [];
let currentSearch = '';
let isLoadingTasks = false;
let loadDebounceTimer = null;
let currentEditTaskUuid = null;
let isLoadingSubtasks = {};
let loadedFilesCache = {}; // Кэш для файлов задач
// Переменные для отслеживания выделения текста
let isSelectingText = false;
let textSelectionTimeout = null;
let highlightedTaskUuid = null;

// ========== CSRF-ФУНКЦИИ ==========
function addCsrfToFormData(formData) {
    if (formData instanceof FormData) formData.append('csrf_token', window.csrfToken);
}
function addCsrfToUrlParams(params) {
    if (params instanceof URLSearchParams) params.append('csrf_token', window.csrfToken);
}
function addCsrfToObject(obj) { obj.csrf_token = window.csrfToken; return obj; }


// ==================== BLOCK START: filterSettings v4.8 ====================
// ver.4.8 (2026-06-03) - ДОБАВЛЕНО СОХРАНЕНИЕ НАСТРОЕК ФИЛЬТРОВ ДЛЯ ПОЛЬЗОВАТЕЛЯ
// - Настройки сохраняются в localStorage с привязкой к user_uuid
// - При загрузке страницы настройки восстанавливаются
// - Поддерживаются: статусы, исполнители, поиск, сортировка, per_page

/**
 * Получает ключ для хранения настроек фильтров пользователя
 * @returns {string} Ключ для localStorage
 */
function getFilterSettingsKey() {
    // Получаем user_uuid из глобальной переменной (должна быть определена в PHP)
    var userUuid = window.currentUserUuid || '';
    if (!userUuid) {
        logDebug('[FILTER_SETTINGS] No user UUID available, using default key');
        return 'task_filters_default';
    }
    return 'task_filters_' + userUuid;
}

/**
 * Сохраняет текущие настройки фильтров в localStorage
 */
function saveFilterSettings() {
    try {
        var settings = {
            // Основные параметры фильтрации
            filterStatuses: JSON.parse(JSON.stringify(currentFilterStatuses)), // deep copy
            filterAssigned: JSON.parse(JSON.stringify(currentFilterAssigned)),
            search: currentSearch,
            sortBy: currentSortBy,
            sortDir: currentSortDir,
            perPage: currentPerPage,
            // Сохраняем timestamp для отладки
            savedAt: Date.now()
        };
        
        var key = getFilterSettingsKey();
        localStorage.setItem(key, JSON.stringify(settings));
        logDebug('[FILTER_SETTINGS] Settings saved:', settings);
    } catch (e) {
        logError('[FILTER_SETTINGS] Failed to save settings:', e);
    }
}

// ==================== BLOCK START: loadProjectUsersForFilter v1.0 ====================
// ver.1.0 (2026-06-05) - ЗАГРУЗКА СПИСКА ПОЛЬЗОВАТЕЛЕЙ ДЛЯ ФИЛЬТРА "ИСПОЛНИТЕЛИ"
function loadProjectUsersForFilter(projectUuid) {
    var dropdown = document.getElementById('assignee-filter-dropdown');
    if (!dropdown) return;
    
    // Показываем индикатор загрузки
    dropdown.innerHTML = '<div class="assignee-loading" style="padding: 12px; text-align: center; color: rgba(233,238,252,0.6);">⏳ Загрузка списка пользователей...</div>';
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_project_users');
    formData.append('project_uuid', projectUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.users) {
            var html = '';
            for (var i = 0; i < data.users.length; i++) {
                var user = data.users[i];
                html += '<div class="multi-select-option">';
                html += '<input type="checkbox" value="' + escapeHtml(user.uuid) + '" id="assignee_' + escapeHtml(user.uuid) + '">';
                html += '<label for="assignee_' + escapeHtml(user.uuid) + '">' + escapeHtml(user.name || user.login) + '</label>';
                html += '</div>';
            }
            
            if (data.users.length === 0) {
                html = '<div style="padding: 12px; text-align: center; color: rgba(233,238,252,0.5);">Нет доступных пользователей</div>';
            }
            
            dropdown.innerHTML = html;
            
            // Восстанавливаем выбранные значения из currentFilterAssigned
            if (currentFilterAssigned && currentFilterAssigned.length > 0) {
                var checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
                for (var j = 0; j < checkboxes.length; j++) {
                    if (currentFilterAssigned.includes(checkboxes[j].value)) {
                        checkboxes[j].checked = true;
                    }
                }
                updateAssigneeFilter();
            }
            
            // Переназначаем обработчики
            var newCheckboxes = dropdown.querySelectorAll('input[type="checkbox"]');
            for (var k = 0; k < newCheckboxes.length; k++) {
                newCheckboxes[k].addEventListener('change', function() {
                    updateAssigneeFilter();
                    loadProjectTasks(true);
                });
            }
            
            logDebug('[FILTER] Loaded ' + data.users.length + ' users for project:', projectUuid);
        } else {
            dropdown.innerHTML = '<div style="padding: 12px; text-align: center; color: #f87171;">❌ Ошибка загрузки пользователей</div>';
            logError('[FILTER] Failed to load users:', data.error);
        }
    })
    .catch(function(err) {
        logError('[FILTER] Error loading users:', err);
        dropdown.innerHTML = '<div style="padding: 12px; text-align: center; color: #f87171;">❌ Ошибка загрузки</div>';
    });
}
// ==================== BLOCK END: loadProjectUsersForFilter v1.0 ====================

/**
 * Загружает настройки фильтров из localStorage
 * @returns {Object|null} Загруженные настройки или null
 */
function loadFilterSettings() {
    try {
        var key = getFilterSettingsKey();
        var saved = localStorage.getItem(key);
        if (!saved) {
            logDebug('[FILTER_SETTINGS] No saved settings found for key:', key);
            return null;
        }
        
        var settings = JSON.parse(saved);
        logDebug('[FILTER_SETTINGS] Settings loaded:', settings);
        return settings;
    } catch (e) {
        logError('[FILTER_SETTINGS] Failed to load settings:', e);
        return null;
    }
}

/**
 * Применяет загруженные настройки к UI и глобальным переменным
 * @param {Object} settings Настройки из localStorage
 */
function applyFilterSettings(settings) {
    if (!settings) return false;
    
    logDebug('[FILTER_SETTINGS] Applying settings version:', settings.savedAt);
    
    // Применяем per_page
    if (settings.perPage && !isNaN(parseInt(settings.perPage))) {
        currentPerPage = parseInt(settings.perPage);
        var perPageSelect = document.getElementById('per-page');
        if (perPageSelect) perPageSelect.value = currentPerPage.toString();
        localStorage.setItem('tasks_per_page', currentPerPage); // для обратной совместимости
        logDebug('[FILTER_SETTINGS] Applied per_page:', currentPerPage);
    }
    
    // Применяем сортировку
    if (settings.sortBy) {
        currentSortBy = settings.sortBy;
        var sortBySelect = document.getElementById('sort-by');
        if (sortBySelect && sortBySelect.value !== currentSortBy) {
            sortBySelect.value = currentSortBy;
            logDebug('[FILTER_SETTINGS] Applied sort_by:', currentSortBy);
        }
    }
    
    if (settings.sortDir) {
        currentSortDir = settings.sortDir;
        logDebug('[FILTER_SETTINGS] Applied sort_dir:', currentSortDir);
    }
    
    // Применяем поисковый запрос
    if (typeof settings.search === 'string') {
        currentSearch = settings.search;
        var searchInput = document.getElementById('task-search');
        if (searchInput && searchInput.value !== currentSearch) {
            searchInput.value = currentSearch;
            logDebug('[FILTER_SETTINGS] Applied search:', currentSearch);
        }
    }
    
    // Применяем фильтр статусов
    if (settings.filterStatuses && Array.isArray(settings.filterStatuses)) {
        currentFilterStatuses = settings.filterStatuses;
        
        // Обновляем UI чекбоксов статусов
        var statusDropdown = document.getElementById('status-filter-dropdown');
        if (statusDropdown) {
            var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]');
            statusCbs.forEach(function(cb) {
                var val = parseInt(cb.value);
                cb.checked = currentFilterStatuses.includes(val);
            });
        }
        updateStatusFilter(); // обновляем текст кнопки
        logDebug('[FILTER_SETTINGS] Applied filter_statuses:', currentFilterStatuses);
    }
    
    // Применяем фильтр исполнителей
    if (settings.filterAssigned && Array.isArray(settings.filterAssigned)) {
        currentFilterAssigned = settings.filterAssigned;
        
        // Обновляем UI чекбоксов исполнителей
        var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
        if (assigneeDropdown) {
            var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]');
            assigneeCbs.forEach(function(cb) {
                cb.checked = currentFilterAssigned.includes(cb.value);
            });
        }
        updateAssigneeFilter(); // обновляем текст кнопки
        logDebug('[FILTER_SETTINGS] Applied filter_assigned:', currentFilterAssigned);
    }
    
    return true;
}

/**
 * Очищает сохранённые настройки фильтров (при выходе из системы)
 */
function clearFilterSettings() {
    try {
        var key = getFilterSettingsKey();
        localStorage.removeItem(key);
        logDebug('[FILTER_SETTINGS] Settings cleared for key:', key);
    } catch (e) {
        logError('[FILTER_SETTINGS] Failed to clear settings:', e);
    }
}

// ==================== BLOCK END: filterSettings v4.8 ====================

// ========== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==========

function restoreHighlightAfterRender() {
    if (highlightedTaskUuid) {
        var taskElement = document.querySelector('.flat-task-item[data-task-uuid="' + highlightedTaskUuid + '"], .subtask-item[data-task-uuid="' + highlightedTaskUuid + '"]');
        if (taskElement && !taskElement.classList.contains('highlight')) {
            taskElement.classList.add('highlight');
            logDebug('[RESTORE_HIGHLIGHT] Restored highlight for task:', highlightedTaskUuid);
        }
    }
}

function escapeHtml(text) { if (!text) return ''; var div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

function formatDate(ts) {
    if (!ts || ts === null || ts === 0) return '';
    var d = new Date(parseInt(ts));
    if (isNaN(d.getTime())) return '';
    var tz = -d.getTimezoneOffset() / 60;
    var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}) + ' (' + tzName + ')';
}

function showAlert(message, type) {
    type = type || 'info';
    var alertDiv = document.createElement('div');
    alertDiv.className = 'custom-alert ' + type;
    alertDiv.innerHTML = message;
    alertDiv.style.cssText = 'position:fixed;bottom:20px;right:20px;background:' + (type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981')) + ';color:white;padding:10px 20px;border-radius:8px;z-index:10000;font-size:14px;max-width:300px;word-wrap:break-word;box-shadow:0 2px 10px rgba(0,0,0,0.2);';
    document.body.appendChild(alertDiv);
    setTimeout(function() { if (alertDiv && alertDiv.remove) alertDiv.remove(); }, 3000);
}

function closeModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('active');
    if (id === 'project-modal') { var ci = document.getElementById('project-delete-confirm'); if (ci) ci.value = ''; }
    if (id === 'task-modal') { var ci = document.getElementById('task-delete-confirm'); if (ci) ci.value = ''; }
}

function getFileIcon(filename) {
    if (!filename) return '📎';
    var ext = filename.split('.').pop().toLowerCase();
    var icons = {'jpg':'🖼️','jpeg':'🖼️','png':'🖼️','gif':'🖼️','webp':'🖼️','pdf':'📄','doc':'📝','docx':'📝','xls':'📊','xlsx':'📊','zip':'📦','rar':'📦','7z':'📦','mp3':'🎵','mp4':'🎬','avi':'🎬','txt':'📃','md':'📃'};
    return icons[ext] || '📎';
}

function utcToLocalDatetimeString(utcTimestamp) {
    if (!utcTimestamp || utcTimestamp === null || utcTimestamp === 0) return '';
    var date = new Date(parseInt(utcTimestamp));
    if (isNaN(date.getTime())) return '';
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    var hours = String(date.getHours()).padStart(2, '0');
    var minutes = String(date.getMinutes()).padStart(2, '0');
    return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
}

function openTaskMessagesByUuid(taskUuid) {
    localStorage.setItem('selected_task_uuid', taskUuid);
    window.location.href = window.APP_BASE + '/messages.php?task=' + encodeURIComponent(taskUuid);
}

function openTaskMessages() {
    var taskUuid = document.getElementById('task-uuid').value;
    if (taskUuid) { localStorage.setItem('selected_task_uuid', taskUuid); window.location.href = window.APP_BASE + '/messages.php?task=' + encodeURIComponent(taskUuid); }
}

// ==================== BLOCK START: checkTaskCreationPermission v1.1 (with loading indicator) ====================
// ver.1.0 (2026-06-05) - ПРОВЕРКА ПРАВ НА СОЗДАНИЕ ЗАДАЧ ПРИ ВЫБОРЕ ПРОЕКТА
// ver.1.1 (2026-06-05) - ДОБАВЛЕН ИНДИКАТОР ЗАГРУЗКИ НА КНОПКУ
function checkTaskCreationPermission(projectUuid) {
    var createTaskBtn = document.getElementById('create-task-btn');
    if (!createTaskBtn) return;
    
    // Сохраняем оригинальный текст кнопки
    var originalText = createTaskBtn.innerHTML;
    
    // Показываем индикатор загрузки
    createTaskBtn.style.opacity = '0.7';
    createTaskBtn.disabled = true;
    createTaskBtn.innerHTML = '⏳ Проверка прав...';
    createTaskBtn.title = 'Проверка прав...';
    
    var formData = new URLSearchParams();
    formData.append('action', 'check_project_permission');
    formData.append('project_uuid', projectUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.can_create_task === true) {
            createTaskBtn.style.opacity = '1';
            createTaskBtn.disabled = false;
            createTaskBtn.innerHTML = '➕ Новая задача';
            createTaskBtn.title = 'Создать новую задачу';
            logDebug('[PERMISSION] User can create tasks in project:', projectUuid);
        } else {
            createTaskBtn.style.opacity = '0.3';
            createTaskBtn.disabled = true;
            createTaskBtn.innerHTML = '🚫 Нет прав';
            createTaskBtn.title = 'У вас нет прав на создание задач в этом проекте';
            logDebug('[PERMISSION] User CANNOT create tasks in project:', projectUuid);
        }
    })
    .catch(function(err) {
        logError('[PERMISSION] Error checking permission:', err);
        createTaskBtn.style.opacity = '0.5';
        createTaskBtn.disabled = true;
        createTaskBtn.innerHTML = '❌ Ошибка';
        createTaskBtn.title = 'Ошибка проверки прав. Попробуйте обновить страницу.';
    });
}
// ==================== BLOCK END: checkTaskCreationPermission v1.1 ====================

// ========== ОПТИМИЗИРОВАННАЯ ЗАГРУЗКА ФАЙЛОВ (ПАКЕТНАЯ) ==========
function loadFilesForTasksBatch(taskUuids) {
    if (!taskUuids || taskUuids.length === 0) return Promise.resolve();
    
    const uuidsToLoad = taskUuids.filter(uuid => !loadedFilesCache[uuid]);
    if (uuidsToLoad.length === 0) {
        for (var i = 0; i < taskUuids.length; i++) {
            displayFilesForTask(taskUuids[i]);
        }
        return Promise.resolve();
    }
    
    return new Promise(function(resolve, reject) {
        var formData = new URLSearchParams();
        formData.append('action', 'get_tasks_files_batch');
        formData.append('task_uuids', JSON.stringify(uuidsToLoad));
        formData.append('ajax_mode', '1');
        addCsrfToUrlParams(formData);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.files_by_task) {
                for (var taskUuid in data.files_by_task) {
                    if (data.files_by_task.hasOwnProperty(taskUuid)) {
                        loadedFilesCache[taskUuid] = data.files_by_task[taskUuid];
                    }
                }
            }
            for (var i = 0; i < taskUuids.length; i++) {
                displayFilesForTask(taskUuids[i]);
            }
            resolve();
        })
        .catch(function(err) {
            logError('[BATCH_FILES] Error:', err);
            resolve();
        });
    });
}

function displayFilesForTask(taskUuid) {
    var container = document.getElementById('task-files-' + taskUuid);
    if (!container) return;
    
    var files = loadedFilesCache[taskUuid] || [];
    if (files.length > 0) {
        var filesHtml = '<div style="display: flex; flex-wrap: wrap; gap: 6px;">';
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var safeName = (file.name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
            filesHtml += '<a href="#" class="task-file-item" onclick="event.stopPropagation(); showFilePreview(\'' +
                file.uuid + '\', \'' + safeName + '\', ' + (file.size_bytes || 0) + ', \'' + (file.mime || '') + '\'); return false;">';
            filesHtml += getFileIcon(file.name) + ' ' + escapeHtml(file.name) + ' (' + file.size + ')';
            filesHtml += '</a>';
        }
        filesHtml += '</div>';
        container.innerHTML = filesHtml;
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
        container.innerHTML = '';
    }
}

// ==================== BLOCK START: loadSubtasks v4.7 (with filters) ====================
// ver.4.6 - Базовая версия
// ver.4.7 (2026-06-11) - ДОБАВЛЕНА ПЕРЕДАЧА ФИЛЬТРОВ ПРИ ЗАГРУЗКЕ ПОДЗАДАЧ

function loadSubtasks(parentUuid, containerId, page, append) {
    if (isLoadingSubtasks[parentUuid]) {
        logDebug('[LOAD_SUBTASKS] Already loading, skipping for parent:', parentUuid);
        return Promise.resolve();
    }
    
    page = page || 1;
    append = append || false;
    isLoadingSubtasks[parentUuid] = true;
    
    var container = document.getElementById(containerId);
    if (!container) {
        logDebug('[LOAD_SUBTASKS] Container not found:', containerId);
        isLoadingSubtasks[parentUuid] = false;
        return Promise.resolve();
    }
    
    logDebug('[LOAD_SUBTASKS] START - parentUuid:', parentUuid, 'page:', page, 'append:', append);
    logDebug('[LOAD_SUBTASKS] Current filters - statuses:', JSON.stringify(currentFilterStatuses), 
             'assignees:', JSON.stringify(currentFilterAssigned), 'search:', currentSearch);
    
    var loadingDiv = container.querySelector('.subtasks-loading');
    var listDiv = container.querySelector('.subtasks-list');
    var paginationDiv = container.querySelector('.subtasks-pagination');
    
    if (loadingDiv) loadingDiv.style.display = 'block';
    
    // v4.7: Передаём текущие фильтры!
    var formData = new URLSearchParams();
    formData.append('action', 'get_project_tasks_sorted');
    formData.append('project_uuid', currentProjectUuid);
    formData.append('parent_uuid', parentUuid);
    formData.append('page', page);
    formData.append('per_page', '20');
    formData.append('sort_by', currentSortBy);
    formData.append('sort_dir', currentSortDir);
    formData.append('filter_statuses', JSON.stringify(currentFilterStatuses));
    formData.append('filter_assigned', JSON.stringify(currentFilterAssigned));
    formData.append('search', currentSearch);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    logDebug('[LOAD_SUBTASKS] Sending request for parent:', parentUuid);
    
    return fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        isLoadingSubtasks[parentUuid] = false;
        if (loadingDiv) loadingDiv.style.display = 'none';
        
        if (data.success && data.tasks && data.tasks.length > 0) {
            logDebug('[LOAD_SUBTASKS] Received', data.tasks.length, 'subtasks for parent:', parentUuid, 'total:', data.total);
            var tasksHtml = renderSubtasksList(data.tasks, parentUuid);
            
            if (append && listDiv) {
                listDiv.insertAdjacentHTML('beforeend', tasksHtml);
                logDebug('[LOAD_SUBTASKS] Appended to existing list');
            } else if (listDiv) {
                listDiv.innerHTML = tasksHtml;
                logDebug('[LOAD_SUBTASKS] Replaced list content');
            }
            
            container.setAttribute('data-loaded', 'true');
            container.setAttribute('data-page', data.page || page);
            container.setAttribute('data-has-more', data.has_more ? 'true' : 'false');
            
            if (paginationDiv) {
                if (data.has_more) {
                    var nextPage = (data.page || page) + 1;
                    var remaining = data.total - ((data.page || page) * 20);
                    paginationDiv.innerHTML = '<button class="btn-secondary" onclick="loadSubtasks(\'' + parentUuid + '\', \'' + containerId + '\', ' + nextPage + ', true)">📋 Загрузить ещё (' + remaining + ' осталось)</button>';
                    paginationDiv.style.display = 'block';
                    logDebug('[LOAD_SUBTASKS] Pagination added, has_more=true');
                } else {
                    paginationDiv.style.display = 'none';
                    paginationDiv.innerHTML = '';
                    logDebug('[LOAD_SUBTASKS] No more subtasks, pagination hidden');
                }
            }
            
            var subtaskUuids = [];
            var newTasks = listDiv.querySelectorAll('.subtask-item');
            for (var i = 0; i < newTasks.length; i++) {
                var taskId = newTasks[i].dataset.taskUuid;
                if (taskId) subtaskUuids.push(taskId);
            }
            if (subtaskUuids.length > 0) {
                logDebug('[LOAD_SUBTASKS] Loading files for', subtaskUuids.length, 'subtasks');
                loadFilesForTasksBatch(subtaskUuids);
            }
        } else if (!append && listDiv) {
            logDebug('[LOAD_SUBTASKS] No subtasks found for parent:', parentUuid);
            listDiv.innerHTML = '<div style="padding:16px; color:rgba(233,238,252,0.5); text-align:center;">📭 Нет подзадач, соответствующих фильтрам</div>';
        } else if (!data.success) {
            logError('[LOAD_SUBTASKS] Server error for parent', parentUuid, ':', data.error);
        }
        return Promise.resolve();
    })
    .catch(function(err) {
        isLoadingSubtasks[parentUuid] = false;
        logError('[LOAD_SUBTASKS] Error for parent', parentUuid, ':', err);
        if (loadingDiv) loadingDiv.style.display = 'none';
        if (listDiv) listDiv.innerHTML = '<div style="padding:16px; color:#f87171; text-align:center;">❌ Ошибка загрузки подзадач</div>';
        return Promise.resolve();
    });
}
// ==================== BLOCK END: loadSubtasks v4.7 ====================

function renderSubtasksList(tasks, parentUuid) {
    if (!tasks || tasks.length === 0) return '';
    
    var html = '';
    for (var i = 0; i < tasks.length; i++) {
        var t = tasks[i];
        var isCompleted = (t.status === 1);
        var assigneeText = t.assignee_name || t.assignee_login || 'Не назначен';
        var hasSubtasks = (t.subtasks_count > 0);
        var filesContainerId = 'task-files-' + t.uuid;
        var isSubscribed = t.is_subscribed === true;
        var subBtnText = isSubscribed ? '🔕 Отписаться' : '🔔 Подписаться';
        var subBtnClass = isSubscribed ? 'subscribed' : '';
        
        var descrHtml = '';
        var descrText = t.descr || '';
        var descId = 'task_' + t.uuid + '_desc';
        if (descrText.trim() !== '') {
            descrHtml = '<div class="task-description collapsible-description collapsed" data-desc-id="' + descId + '">' + parseDescriptionLinks(descrText) + '</div>';
        } else {
            descrHtml = '<div class="task-description-empty">Нет описания</div>';
        }
        
        var taskUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + t.uuid;
        var escapedTitle = escapeHtml(t.title).replace(/"/g, '&quot;');
        
        html += '<div class="subtask-item flat-task-item" data-task-uuid="' + t.uuid + '">';
        html += '<div class="flat-task-row-link" style="display: block; text-decoration: none; color: inherit;" data-task-url="' + taskUrl + '" data-task-title="' + escapedTitle + '">';
        html += '<div class="flat-task-row" style="cursor: pointer;">';
        html += '<div class="flat-task-checkbox ' + (isCompleted ? 'completed' : '') + '" onclick="event.stopPropagation(); toggleTaskStatus(event, \'' + t.uuid + '\', ' + (!isCompleted) + ')"></div>';
        html += '<div class="flat-task-content">';
        html += '<div class="flat-task-title ' + (isCompleted ? 'completed' : '') + '">' + escapeHtml(t.title) + '</div>';
        html += descrHtml;
        html += '<div class="flat-task-meta">';
        html += '<span class="flat-task-badge">👤 ' + escapeHtml(assigneeText) + '</span>';
        if (t.time_start) html += '<span class="flat-task-badge">🚀 ' + formatDate(t.time_start) + '</span>';
        if (t.time_end_plan) html += '<span class="flat-task-badge">📅 ' + formatDate(t.time_end_plan) + '</span>';
        if (t.messages_count > 0) html += '<span class="flat-task-badge clickable" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + t.uuid + '\')">💬 ' + t.messages_count + '</span>';
        if (t.files_count > 0) html += '<span class="flat-task-badge">📎 ' + t.files_count + '</span>';
        if (hasSubtasks) html += '<span class="flat-task-badge clickable subtasks-toggle" data-task-uuid="' + t.uuid + '">📋 ' + t.subtasks_count + '</span>';
        html += '</div>';
        html += '<div class="task-files-container" id="' + filesContainerId + '"><span class="files-loading">⏳ Загрузка файлов...</span></div>';
        html += '</div>';
        html += '<div class="flat-task-actions" onclick="event.stopPropagation()">';
        if (t.can_edit) {
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="editTask(\'' + t.uuid + '\')" title="Редактировать">✏️</button>';
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="createSubtask(\'' + t.uuid + '\')" title="Создать подзадачу">➕</button>';
        }
        html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="copyTaskLink(\'' + t.uuid + '\', \'' + escapeHtml(t.title).replace(/'/g, "\\'") + '\')" title="Скопировать ссылку на задачу">🔗</button>';
        html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px ' + subBtnClass + '" id="sub-btn-' + t.uuid + '" onclick="toggleTaskSubscription(\'' + t.uuid + '\', this)">' + subBtnText + '</button>';
        html += '<button class="btn-messages-icon" onclick="openTaskMessagesByUuid(\'' + t.uuid + '\')">💬</button>';

        if (t.files_count > 0) {
            html += '<a href="<?= $appBase ?>/files.php?task=' + t.uuid + '" class="btn-secondary" style="padding:4px 8px;font-size:11px; text-decoration: none;" target="_blank" title="Все файлы задачи">📎 Файлы</a>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        if (hasSubtasks) {
            html += '<div class="subtasks-container" id="children-' + t.uuid + '" data-loaded="false" data-page="1" data-has-more="false">';
            html += '<div class="subtasks-loading" style="display:none;">⏳ Загрузка подзадач...</div>';
            html += '<div class="subtasks-list"></div>';
            html += '<div class="subtasks-pagination" style="display:none;"></div>';
            html += '</div>';
        }
        html += '</div>';
    }
    
    // Добавляем обработчик клика для копирования ссылки для подзадач
    setTimeout(function() {
        var container = document.getElementById('children-' + parentUuid);
        if (container) {
            var rows = container.querySelectorAll('.flat-task-row');
            for (var j = 0; j < rows.length; j++) {
                var parentLink = rows[j].closest('.flat-task-row-link');
                if (parentLink && parentLink.dataset.taskUrl) {
                    rows[j].dataset.taskUrl = parentLink.dataset.taskUrl;
                    rows[j].dataset.taskTitle = parentLink.dataset.taskTitle || 'задачи';
                }
                
                rows[j].addEventListener('click', function(e) {
                    // Если есть выделение текста - не копируем ссылку
                    if (isSelectingText) {
                        logDebug('[SUBTASK_ROW_CLICK] Blocked by isSelectingText flag');
                        e.stopPropagation();
                        return;
                    }
                    
                    // Проверяем выделение на момент клика
                    var selection = window.getSelection();
                    if (selection && selection.toString().trim().length > 0) {
                        logDebug('[SUBTASK_ROW_CLICK] Blocked by active selection');
                        e.stopPropagation();
                        return;
                    }
                    
                    // Проверяем, что клик был не по кнопкам
                    if (e.target.closest('.flat-task-actions, .flat-task-checkbox, .btn-secondary, .btn-messages-icon, .subtasks-toggle, .task-file-item')) {
                        return;
                    }
                    
                    // Проверяем, не кликнули ли по текстовой области
                    if (e.target.closest('.task-description, .task-description-empty, .flat-task-title')) {
                        logDebug('[SUBTASK_ROW_CLICK] Click on text area, skipping copy');
                        return;
                    }
                    
                    var url = this.dataset.taskUrl;
                    var title = this.dataset.taskTitle || 'задачи';
                    if (url) {
                        navigator.clipboard.writeText(url).then(function() {
                            showAlert('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                        }).catch(function() {
                            var textarea = document.createElement('textarea');
                            textarea.value = url;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            showAlert('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                        });
                    }
                });
            }
        }
    }, 100);
    restoreHighlightAfterRender();
    return html;
}

// ========== ОСНОВНЫЕ ФУНКЦИИ ==========

// ==================== BLOCK START: parseDescriptionLinks v2.0 (XSS fix) ====================
// ver.1.0 - Базовая версия
// ver.2.0 (2026-06-05) - ИСПРАВЛЕНА XSS УЯЗВИМОСТЬ
// - Блокировка javascript:, data:, vbscript: схем
// - Экранирование URL перед вставкой в href
// - Двойное экранирование отображаемого текста

function parseDescriptionLinks(text) {
    if (!text) return '';
    var escaped = escapeHtml(text);
    var urlRegex = /(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[^\s<>\[\]\(\)\{\}]+/gi;
    
    return escaped.replace(urlRegex, function(match) {
        // v2.0: Блокируем опасные схемы (XSS защита)
        var lowerMatch = match.toLowerCase();
        if (lowerMatch.indexOf('javascript:') === 0 || 
            lowerMatch.indexOf('data:') === 0 || 
            lowerMatch.indexOf('vbscript:') === 0) {
            logDebug('[XSS_BLOCK] Blocked dangerous URL scheme: ' + match.substring(0, 100));
            return match;
        }
        
        // Очищаем URL от потенциально опасных символов
        var safeUrl = match.replace(/['"]/g, '');
        safeUrl = safeUrl.replace(/[<>]/g, '');
        
        var isTelegram = lowerMatch.indexOf('tg://') === 0 || lowerMatch.indexOf('telegram://') === 0;
        var linkClass = isTelegram ? 'external-link telegram-link' : 'external-link';
        var targetAttr = (lowerMatch.indexOf('mailto:') === 0 || lowerMatch.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
        
        // Отображаемый текст также экранируем
        var displayText = escapeHtml(match);
        if (displayText.length > 80) {
            displayText = displayText.substring(0, 70) + '…' + displayText.substring(displayText.length - 10);
        }
        
        logDebug('[LINK_PARSER] Safe link generated: ' + safeUrl.substring(0, 100));
        
        return '<a href="' + safeUrl + '" class="' + linkClass + '"' + targetAttr + ' title="' + escapeHtml(match) + '">' + displayText + '</a>';
    });
}
// ==================== BLOCK END: parseDescriptionLinks v2.0 ====================

function initCollapsibleDescriptions() {
    var taskDescs = document.querySelectorAll('.task-description');
    for (var i = 0; i < taskDescs.length; i++) { makeCollapsible(taskDescs[i]); }
    var projectDescs = document.querySelectorAll('.project-descr');
    for (var i = 0; i < projectDescs.length; i++) { makeCollapsible(projectDescs[i]); }
}

function makeCollapsible(descElement) {
    if (!descElement || descElement.classList.contains('collapsible-processed')) return;
    var text = descElement.innerText || descElement.textContent || '';
    if (text.length <= 300) return;
    
    var descId = descElement.getAttribute('data-desc-id');
    if (!descId) {
        var parentTask = descElement.closest('[data-task-uuid]');
        if (parentTask && parentTask.dataset.taskUuid) {
            descId = 'task_' + parentTask.dataset.taskUuid + '_desc';
        } else {
            var parentProject = descElement.closest('.project-card');
            if (parentProject && parentProject.dataset.projectUuid) {
                descId = 'project_' + parentProject.dataset.projectUuid + '_desc';
            } else {
                descId = 'desc_' + Math.random().toString(36).substr(2, 8);
            }
        }
        descElement.setAttribute('data-desc-id', descId);
    }
    
    descElement.classList.add('collapsible-description');
    descElement.classList.add('collapsible-processed');
    var savedState = localStorage.getItem('collapsed_desc_' + descId);
    var isCollapsed = (savedState !== 'false');
    if (isCollapsed) descElement.classList.add('collapsed');
    else descElement.classList.remove('collapsed');
    
    var toggleBtn = document.createElement('button');
    toggleBtn.className = 'collapsible-description-toggle' + (isCollapsed ? ' collapsed' : '');
    toggleBtn.innerHTML = isCollapsed ? 'Развернуть описание' : 'Свернуть описание';
    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var currentlyCollapsed = descElement.classList.contains('collapsed');
        if (currentlyCollapsed) {
            descElement.classList.remove('collapsed');
            toggleBtn.classList.remove('collapsed');
            toggleBtn.innerHTML = 'Свернуть описание';
            localStorage.setItem('collapsed_desc_' + descId, 'false');
        } else {
            descElement.classList.add('collapsed');
            toggleBtn.classList.add('collapsed');
            toggleBtn.innerHTML = 'Развернуть описание';
            localStorage.setItem('collapsed_desc_' + descId, 'true');
        }
        setTimeout(function() {
            if (descElement.classList.contains('collapsed')) descElement.style.maxHeight = '';
        }, 10);
    });
    descElement.parentNode.insertBefore(toggleBtn, descElement.nextSibling);
}

function toggleTaskSubscription(taskUuid, buttonElement) {
    if (!taskUuid) { showAlert('Не указана задача', 'warning'); return; }
    var originalText = buttonElement ? buttonElement.innerHTML : 'Подписаться';
    if (buttonElement) { buttonElement.innerHTML = '⏳...'; buttonElement.disabled = true; }
    
    var formData = new URLSearchParams();
    formData.append('action', 'toggle_task_subscription');
    formData.append('task_uuid', taskUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (buttonElement) buttonElement.disabled = false;
        if (d.success) {
            if (buttonElement) {
                if (d.is_subscribed) {
                    buttonElement.innerHTML = '🔕 Отписаться';
                    buttonElement.title = 'Отписаться от уведомлений задачи';
                    buttonElement.classList.add('subscribed');
                } else {
                    buttonElement.innerHTML = '🔔 Подписаться';
                    buttonElement.title = 'Подписаться на уведомления задачи';
                    buttonElement.classList.remove('subscribed');
                }
            }
            showAlert(d.message, 'success');
        } else {
            if (buttonElement) buttonElement.innerHTML = originalText;
            showAlert('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
        }
    })
    .catch(function(err) {
        logError('Toggle subscription error:', err);
        if (buttonElement) { buttonElement.innerHTML = originalText; buttonElement.disabled = false; }
        showAlert('Ошибка сети', 'error');
    });
}

function toggleTaskStatus(e, uuid, done) {
    e.stopPropagation();
    var newStatus = done ? 'выполнена' : 'не выполнена';
    if (!confirm('Вы уверены, что хотите отметить задачу как ' + newStatus + '?')) return;
    
    var formData = new URLSearchParams();
    formData.append('action', 'toggle_task_status');
    formData.append('uuid', uuid);
    formData.append('status', done ? 1 : 0);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showAlert('Статус задачи изменён на "' + newStatus + '"', 'success');
            if (currentProjectUuid) loadProjectTasks(true);
        } else {
            if (d.csrf_error) { showAlert('Ошибка безопасности. Обновите страницу.', 'error'); return; }
            showAlert('Ошибка: ' + d.error, 'error');
        }
    })
    .catch(function(err) { logError('[TOGGLE_STATUS] Error:', err); showAlert('Ошибка при изменении статуса', 'error'); });
}

// ==================== BLOCK START: createSubtask v4.7 (FIXED parent pre-selection) ====================
// ver.4.4 - ИСПРАВЛЕНА УСТАНОВКА ПРОЕКТА ПРИ СОЗДАНИИ ПОДЗАДАЧИ
// ver.4.5 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА ПРАВ НА СОЗДАНИЕ ПОДЗАДАЧ
// ver.4.6 (2026-06-11) - ИСПРАВЛЕНА УСТАНОВКА РОДИТЕЛЬСКОЙ ЗАДАЧИ
// ver.4.7 (2026-06-11) - ИСПРАВЛЕНА ПРОБЛЕМА: родительская задача теперь предустанавливается КОРРЕКТНО
// - Исправлена асинхронная проблема с updateParentSelect
// - Родительская задача устанавливается ТОЛЬКО ПОСЛЕ загрузки списка
// - Добавлены логи для отладки

function createSubtask(parentUuid) {
    if (!currentProjectUuid) { 
        showAlert('Сначала выберите проект', 'warning'); 
        return; 
    }
    
    logDebug('[CREATE_SUBTASK] Creating subtask for parent:', parentUuid, 'currentProjectUuid:', currentProjectUuid);
    
    // Сохраняем родительский UUID в глобальную переменную на случай, если проверка прав займёт время
    window._pendingParentUuid = parentUuid;
    
    // Показываем индикатор загрузки на кнопке (если есть)
    var triggerButton = document.activeElement;
    var originalButtonText = '';
    if (triggerButton && triggerButton.classList && triggerButton.classList.contains('btn-secondary')) {
        originalButtonText = triggerButton.innerHTML;
        triggerButton.innerHTML = '⏳ Проверка...';
        triggerButton.disabled = true;
    }
    
    // Отправляем запрос на проверку прав
    var formData = new URLSearchParams();
    formData.append('action', 'check_project_permission');
    formData.append('project_uuid', currentProjectUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        // Восстанавливаем кнопку
        if (triggerButton && originalButtonText) {
            triggerButton.innerHTML = originalButtonText;
            triggerButton.disabled = false;
        }
        
        if (data.success && data.can_create_task === true) {
            // Есть права — открываем модальное окно, передавая родительский UUID
            openCreateSubtaskModal(parentUuid);
        } else {
            // Нет прав — показываем предупреждение
            showAlert('У вас нет прав на создание подзадач в этом проекте', 'warning');
            logDebug('[CREATE_SUBTASK] User has no permission to create subtasks in project:', currentProjectUuid);
        }
        window._pendingParentUuid = null;
    })
    .catch(function(err) {
        logError('[CREATE_SUBTASK] Error checking permission:', err);
        if (triggerButton && originalButtonText) {
            triggerButton.innerHTML = originalButtonText;
            triggerButton.disabled = false;
        }
        showAlert('Ошибка проверки прав. Попробуйте обновить страницу.', 'error');
        window._pendingParentUuid = null;
    });
}
// ==================== BLOCK END: createSubtask v4.7 ====================

// ==================== BLOCK START: openCreateSubtaskModal v4.8 (FIXED parent pre-selection) ====================
// ver.4.4 - Базовая версия
// ver.4.8 (2026-06-11) - ИСПРАВЛЕНА УСТАНОВКА РОДИТЕЛЬСКОЙ ЗАДАЧИ
// - Теперь родительская задача устанавливается через callback после загрузки списка
// - Добавлена проверка, что select действительно существует перед установкой
// - Добавлены подробные логи для отладки

function openCreateSubtaskModal(parentUuid) {
    window.isCreatingSubtask = true;
    
    logDebug('[CREATE_SUBTASK_MODAL] v4.8 Opening modal for parent UUID:', parentUuid);
    
    // Получаем название родительской задачи для заголовка
    var parentTaskElement = document.querySelector('.flat-task-item[data-task-uuid="' + parentUuid + '"] .flat-task-title, .subtask-item[data-task-uuid="' + parentUuid + '"] .flat-task-title');
    var parentTitle = parentTaskElement ? parentTaskElement.innerText : 'задачи';
    
    document.getElementById('task-modal-title').innerText = '➕ Создание подзадачи: ' + escapeHtml(parentTitle);
    
    // Очищаем форму
    document.getElementById('task-uuid').value = '';
    document.getElementById('task-title').value = '';
    document.getElementById('task-descr').value = '';
    document.getElementById('task-assigned-to').value = '';
    document.getElementById('task-time-start').value = '';
    document.getElementById('task-time-end').value = '';
    document.getElementById('task-files-list').innerHTML = '';
    document.getElementById('file-manager').style.display = 'none';
    document.getElementById('open-messages-btn').style.display = 'none';
    
    var taskDeleteSection = document.getElementById('task-delete-section');
    if (taskDeleteSection) taskDeleteSection.style.display = 'none';
    var deleteConfirm = document.getElementById('task-delete-confirm');
    if (deleteConfirm) deleteConfirm.value = '';
    
    // Устанавливаем проект в скрытое поле
    document.getElementById('task-project-uuid').value = currentProjectUuid;
    document.getElementById('task-parent-uuid-old').value = ''; // Очищаем старый родитель
    
    // Устанавливаем проект в select (важно!)
    var projectSelect = document.getElementById('task-project-select');
    if (projectSelect) {
        projectSelect.value = currentProjectUuid;
        logDebug('[CREATE_SUBTASK_MODAL] Set project select to:', currentProjectUuid);
    }
    
    // v4.8: ЗАГРУЖАЕМ СПИСОК РОДИТЕЛЕЙ И УСТАНАВЛИВАЕМ РОДИТЕЛЬСКУЮ ЗАДАЧУ через callback
    logDebug('[CREATE_SUBTASK_MODAL] Loading parent select options...');
    updateParentSelect(currentProjectUuid, null, function() {
        var parentSelect = document.getElementById('task-parent-select');
        if (parentSelect) {
            // Проверяем, есть ли опция с нужным значением
            var optionExists = false;
            for (var i = 0; i < parentSelect.options.length; i++) {
                if (parentSelect.options[i].value === parentUuid) {
                    optionExists = true;
                    break;
                }
            }
            
            if (optionExists) {
                parentSelect.value = parentUuid;
                logDebug('[CREATE_SUBTASK_MODAL] SUCCESS: Set parent select to:', parentUuid);
            } else {
                logDebug('[CREATE_SUBTASK_MODAL] WARNING: Parent option not found:', parentUuid);
                logDebug('[CREATE_SUBTASK_MODAL] Available options:', 
                    Array.from(parentSelect.options).map(function(opt) { return opt.value; }));
                
                // Пробуем добавить опцию, если её нет
                var newOption = document.createElement('option');
                newOption.value = parentUuid;
                newOption.textContent = parentTitle + ' (текущая задача)';
                parentSelect.appendChild(newOption);
                parentSelect.value = parentUuid;
                logDebug('[CREATE_SUBTASK_MODAL] Added missing parent option');
            }
        } else {
            logError('[CREATE_SUBTASK_MODAL] Parent select element not found!');
        }
    });
    
    // Открываем модальное окно
    document.getElementById('task-modal').classList.add('active');
    
    // Сбрасываем флаг через секунду
    setTimeout(function() { 
        window.isCreatingSubtask = false; 
    }, 1000);
}
// ==================== BLOCK END: openCreateSubtaskModal v4.8 ====================


// ========== ФУНКЦИЯ showCreateTaskModal - ИСПРАВЛЕНА ver.4.5 ==========
// ver.4.4 - ИСПРАВЛЕНА УСТАНОВКА ПРОЕКТА ПРИ СОЗДАНИИ КОРНЕВОЙ ЗАДАЧИ
// ver.4.5 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА ПРАВ С ИНДИКАТОРОМ ЗАГРУЗКИ
function showCreateTaskModal() {
    var createTaskBtn = document.getElementById('create-task-btn');
    if (createTaskBtn && createTaskBtn.disabled === true) {
        showAlert('У вас нет прав на создание задач в этом проекте', 'warning');
        return;
    }
    
    if (!currentProjectUuid) { 
        showAlert('Сначала выберите проект', 'warning'); 
        return; 
    }
    
    logDebug('[SHOW_CREATE_TASK_MODAL] Creating task for project:', currentProjectUuid);
    
    // Показываем индикатор загрузки на кнопке
    var originalText = createTaskBtn ? createTaskBtn.innerHTML : '';
    if (createTaskBtn) {
        createTaskBtn.innerHTML = '⏳ Проверка...';
        createTaskBtn.disabled = true;
    }
    
    // Проверяем права (на случай, если кэш протух или кнопка была восстановлена)
    var formData = new URLSearchParams();
    formData.append('action', 'check_project_permission');
    formData.append('project_uuid', currentProjectUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        // Восстанавливаем кнопку
        if (createTaskBtn) {
            createTaskBtn.innerHTML = originalText;
            createTaskBtn.disabled = false;
        }
        
        if (data.success && data.can_create_task === true) {
            // Открываем модальное окно
            window.isCreatingSubtask = false;
            document.getElementById('task-modal-title').innerText = '➕ Создание задачи';
            document.getElementById('task-uuid').value = '';
            document.getElementById('task-title').value = '';
            document.getElementById('task-descr').value = '';
            document.getElementById('task-assigned-to').value = '';
            document.getElementById('task-time-start').value = '';
            document.getElementById('task-time-end').value = '';
            document.getElementById('task-files-list').innerHTML = '';
            
            document.getElementById('task-project-uuid').value = currentProjectUuid;
            
            var projectSelect = document.getElementById('task-project-select');
            if (projectSelect) {
                projectSelect.value = currentProjectUuid;
            }
            
            updateParentSelect(currentProjectUuid, null, function() {
                var parentSelect = document.getElementById('task-parent-select');
                if (parentSelect) parentSelect.value = '';
            });
            document.getElementById('file-manager').style.display = 'none';
            document.getElementById('open-messages-btn').style.display = 'none';
            var taskDeleteSection = document.getElementById('task-delete-section');
            if (taskDeleteSection) taskDeleteSection.style.display = 'none';
            var deleteConfirm = document.getElementById('task-delete-confirm');
            if (deleteConfirm) deleteConfirm.value = '';
            document.getElementById('task-modal').classList.add('active');
        } else {
            showAlert('У вас нет прав на создание задач в этом проекте', 'warning');
        }
    })
    .catch(function(err) {
        logError('[SHOW_CREATE_TASK_MODAL] Error checking permission:', err);
        if (createTaskBtn) {
            createTaskBtn.innerHTML = originalText;
            createTaskBtn.disabled = false;
        }
        showAlert('Ошибка проверки прав. Попробуйте обновить страницу.', 'error');
    });
}
// ========== КОНЕЦ ФУНКЦИИ showCreateTaskModal ==========

function editTask(uuid) {
    logDebug('[EDIT_TASK] Editing task:', uuid);
    currentEditTaskUuid = uuid;
    var permFormData = new URLSearchParams();
    permFormData.append('action', 'check_edit_permission');
    permFormData.append('task_uuid', uuid);
    permFormData.append('ajax_mode', '1');
    addCsrfToUrlParams(permFormData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: permFormData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { showAlert(d.error || 'Нет прав на редактирование', 'error'); return; }
        loadTaskForEdit(uuid);
    })
    .catch(function(err) { logError('[EDIT_TASK] Permission check error:', err); showAlert('Ошибка проверки прав', 'error'); });
}

// ==================== BLOCK START: loadTaskForEdit v4.5 (with callback) ====================
// ver.4.4 - ИСПРАВЛЕНА УСТАНОВКА ПРОЕКТА ПРИ РЕДАКТИРОВАНИИ ЗАДАЧИ
// ver.4.5 (2026-06-11) - ИСПРАВЛЕНА УСТАНОВКА РОДИТЕЛЬСКОЙ ЗАДАЧИ ЧЕРЕЗ CALLBACK

function loadTaskForEdit(uuid) {
    logDebug('[LOAD_TASK_FOR_EDIT] Loading task for edit:', uuid);
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_task');
    formData.append('uuid', uuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success && d.task) {
            logDebug('[LOAD_TASK_FOR_EDIT] Task loaded, project_uuid:', d.task.project_uuid);
            
            document.getElementById('task-modal-title').innerText = 'Редактирование задачи';
            document.getElementById('task-uuid').value = d.task.uuid;
            document.getElementById('task-project-uuid').value = d.task.project_uuid;
            document.getElementById('task-parent-uuid-old').value = d.task.parent_task_uuid || '';
            document.getElementById('task-title').value = d.task.title;
            document.getElementById('task-descr').value = d.task.descr || '';
            document.getElementById('task-assigned-to').value = d.task.assigned_to_uuid || '';
            
            // Устанавливаем проект в select (важно!)
            var projectSelect = document.getElementById('task-project-select');
            if (projectSelect) {
                projectSelect.value = d.task.project_uuid;
                logDebug('[LOAD_TASK_FOR_EDIT] Set project select to:', d.task.project_uuid);
            }
            
            document.getElementById('task-time-start').value = utcToLocalDatetimeString(d.task.time_start_utc);
            document.getElementById('task-time-end').value = utcToLocalDatetimeString(d.task.time_end_plan_utc);
            
            // v4.5: Загружаем список родителей и только после этого устанавливаем значение
            if (d.task.project_uuid) {
                logDebug('[LOAD_TASK_FOR_EDIT] Updating parent select with callback...');
                updateParentSelect(d.task.project_uuid, d.task.uuid, function() {
                    var select = document.getElementById('task-parent-select');
                    if (select && d.task.parent_task_uuid) {
                        // Проверяем, существует ли опция
                        var optionExists = false;
                        for (var i = 0; i < select.options.length; i++) {
                            if (select.options[i].value === d.task.parent_task_uuid) {
                                optionExists = true;
                                break;
                            }
                        }
                        
                        if (optionExists) {
                            select.value = d.task.parent_task_uuid;
                            logDebug('[LOAD_TASK_FOR_EDIT] Set parent select to:', d.task.parent_task_uuid);
                        } else {
                            logDebug('[LOAD_TASK_FOR_EDIT] Parent option not found:', d.task.parent_task_uuid);
                            // Добавляем опцию, если её нет
                            var newOption = document.createElement('option');
                            newOption.value = d.task.parent_task_uuid;
                            newOption.textContent = d.task.title + ' (текущая задача)';
                            select.appendChild(newOption);
                            select.value = d.task.parent_task_uuid;
                        }
                    }
                });
            }
            
            document.getElementById('file-manager').style.display = 'block';
            document.getElementById('open-messages-btn').style.display = 'inline-flex';
            var taskDeleteSection = document.getElementById('task-delete-section');
            if (taskDeleteSection) taskDeleteSection.style.display = 'block';
            if (d.files) renderTaskFiles(d.files);
            document.getElementById('task-modal').classList.add('active');
        } else { 
            logError('[LOAD_TASK_FOR_EDIT] Task not found or no access:', d.error);
            showAlert('Задача не найдена', 'error'); 
        }
    })
    .catch(function(err) { 
        logError('[LOAD_TASK_FOR_EDIT] Error:', err); 
        showAlert('Ошибка загрузки задачи', 'error'); 
    });
}
// ==================== BLOCK END: loadTaskForEdit v4.5 ====================

// ==================== BLOCK START: updateParentSelect v4.3 (with callback support) ====================
// ver.4.0 - Базовая версия
// ver.4.1 - Добавлено логирование
// ver.4.2 - Исправлена фильтрация подзадач
// ver.4.3 (2026-06-11) - ДОБАВЛЕН ПАРАМЕТР callback ДЛЯ АСИНХРОННОЙ ОБРАБОТКИ
// - Теперь можно передать callback, который выполнится после загрузки списка
// - Улучшена обработка ошибок

function updateParentSelect(projectUuid, currentTaskUuid, callback) {
    if (!projectUuid) {
        logDebug('[UPDATE_PARENT_SELECT] No project UUID, skipping');
        if (callback) callback();
        return;
    }
    
    logDebug('[UPDATE_PARENT_SELECT] v4.3 Loading parents for project:', projectUuid, 'currentTaskUuid:', currentTaskUuid);
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_project_tasks_sorted');
    formData.append('project_uuid', projectUuid);
    formData.append('page', '1');
    formData.append('per_page', '500');
    formData.append('sort_by', 'title');
    formData.append('sort_dir', 'ASC');
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var select = document.getElementById('task-parent-select');
        if (!select) {
            logDebug('[UPDATE_PARENT_SELECT] Parent select element not found');
            if (callback) callback();
            return;
        }
        
        if (data.success && data.tasks) {
            var html = '<option value="">-- Нет (корневая задача) --</option>';
            var excludedCount = 0;
            
            for (var i = 0; i < data.tasks.length; i++) {
                var task = data.tasks[i];
                // Исключаем текущую задачу из списка (чтобы нельзя было сделать себя родителем)
                if (currentTaskUuid && task.uuid === currentTaskUuid) {
                    excludedCount++;
                    continue;
                }
                html += '<option value="' + escapeHtml(task.uuid) + '">' + escapeHtml(task.title) + '</option>';
            }
            
            select.innerHTML = html;
            logDebug('[UPDATE_PARENT_SELECT] Loaded ' + data.tasks.length + ' potential parents, excluded ' + excludedCount);
            
            // v4.3: Вызываем callback ПОСЛЕ того, как DOM обновлён
            if (callback) {
                logDebug('[UPDATE_PARENT_SELECT] Calling callback after select population');
                callback();
            }
        } else {
            logDebug('[UPDATE_PARENT_SELECT] No tasks found or error:', data.error);
            select.innerHTML = '<option value="">-- Нет доступных родительских задач --</option>';
            if (callback) callback();
        }
    })
    .catch(function(err) { 
        logError('[UPDATE_PARENT_SELECT] Error:', err);
        var select = document.getElementById('task-parent-select');
        if (select) {
            select.innerHTML = '<option value="">-- Ошибка загрузки --</option>';
        }
        if (callback) callback();
    });
}
// ==================== BLOCK END: updateParentSelect v4.3 ====================


// ========== ФУНКЦИЯ onTaskProjectChange - ИСПРАВЛЕНА ver.4.4 ==========
// ver.4.4 - ДОБАВЛЕНО ЛОГГИРОВАНИЕ ДЛЯ ОТЛАДКИ
function onTaskProjectChange() {
    var newProjectUuid = document.getElementById('task-project-select').value;
    var taskUuid = document.getElementById('task-uuid').value;
    
    logDebug('[ON_TASK_PROJECT_CHANGE] New project:', newProjectUuid, 'Task UUID:', taskUuid || 'new task');
    
    if (!taskUuid) {
        if (newProjectUuid) {
            updateParentSelect(newProjectUuid, null);
            // Обновляем скрытое поле проекта
            document.getElementById('task-project-uuid').value = newProjectUuid;
            logDebug('[ON_TASK_PROJECT_CHANGE] Updated hidden project field to:', newProjectUuid);
        }
        return;
    }
    
    var oldProjectUuid = document.getElementById('task-project-uuid').value;
    if (newProjectUuid !== oldProjectUuid) {
        if (confirm('Внимание! Перемещение задачи в другой проект также переместит все подзадачи и сообщения. Продолжить?')) {
            document.getElementById('task-project-uuid').value = newProjectUuid;
            updateParentSelect(newProjectUuid, taskUuid);
            showAlert('Проект задачи изменён. Не забудьте сохранить изменения.', 'info');
            logDebug('[ON_TASK_PROJECT_CHANGE] Moving task from', oldProjectUuid, 'to', newProjectUuid);
        } else {
            document.getElementById('task-project-select').value = oldProjectUuid;
            logDebug('[ON_TASK_PROJECT_CHANGE] Move cancelled, reverted to:', oldProjectUuid);
        }
    } else { 
        updateParentSelect(newProjectUuid, taskUuid);
        logDebug('[ON_TASK_PROJECT_CHANGE] Same project, updating parent list only');
    }
}
// ========== КОНЕЦ ФУНКЦИИ onTaskProjectChange ==========

function renderTaskFiles(files) {
    var container = document.getElementById('task-files-list');
    if (!container) return;
    if (!files || files.length === 0) { container.innerHTML = '<div style="text-align:center;color:rgba(233,238,252,.5);padding:16px;">Нет прикреплённых файлов</div>'; return; }
    var html = '';
    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        html += '<div class="file-item-row" style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.05);">';
        html += '<div class="file-info"><span>' + getFileIcon(file.name) + ' ' + escapeHtml(file.name) + ' (' + file.size + ')</span></div>';
        html += '<div class="file-actions" style="display:flex; gap:8px;">';
        html += '<a href="' + file.url + '" class="btn-icon" download style="background:none; border:none; cursor:pointer; font-size:16px;">📥</a>';
        html += '<button type="button" class="btn-icon" onclick="detachFile(\'' + file.uuid + '\')" style="background:none; border:none; cursor:pointer; font-size:16px; color:#f87171;">❌</button>';
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function uploadTaskFile() {
    var taskUuid = document.getElementById('task-uuid').value;
    if (!taskUuid) { showAlert('Сначала сохраните задачу', 'warning'); return; }
    var fileInput = document.getElementById('task-file-upload');
    var file = fileInput.files[0];
    if (!file) { showAlert('Выберите файл', 'warning'); return; }
    var formData = new FormData();
    formData.append('action', 'upload_task_file');
    formData.append('task_uuid', taskUuid);
    formData.append('ajax_mode', '1');
    formData.append('file', file);
    addCsrfToFormData(formData);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showAlert('Файл загружен', 'success');
            fileInput.value = '';
            renderTaskFiles(d.files);
            if (currentProjectUuid) loadProjectTasks(true);
        } else {
            if (d.csrf_error) { showAlert('Ошибка безопасности. Обновите страницу.', 'error'); return; }
            showAlert('Ошибка: ' + (d.error || 'Неизвестная'), 'error');
        }
    })
    .catch(function(err) { logError('[UPLOAD_FILE] Error:', err); showAlert('Ошибка загрузки файла', 'error'); });
}

function detachFile(fileUuid) {
    if (!confirm('Открепить файл от задачи?')) return;
    var taskUuid = document.getElementById('task-uuid').value;
    var formData = new URLSearchParams();
    formData.append('action', 'detach_file_from_task');
    formData.append('task_uuid', taskUuid);
    formData.append('file_uuid', fileUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            showAlert('Файл откреплён', 'success');
            renderTaskFiles(d.files);
            if (currentProjectUuid) loadProjectTasks(true);
        } else { showAlert('Ошибка: ' + (d.error || 'Неизвестная'), 'error'); }
    });
}

// ==================== BLOCK START: saveTask v4.7 (with ensureTaskVisible) ====================
// ver.4.6 - Базовая версия с auto-expand
// ver.4.7 (2026-06-11) - ЗАМЕНА НА ensureTaskVisible

function saveTask(e, retryCount) {
    if (retryCount === undefined) retryCount = 0;
    e.preventDefault();
    var uuid = document.getElementById('task-uuid').value.trim();
    var action = uuid ? 'edit_task' : 'create_task';
    var title = document.getElementById('task-title').value.trim();
    var parentSelect = document.getElementById('task-parent-select');
    var parentTaskUuid = parentSelect ? parentSelect.value : '';
    if (!parentTaskUuid || parentTaskUuid === '' || parentTaskUuid === 'null') parentTaskUuid = '';
    var localStart = document.getElementById('task-time-start').value;
    var localEnd = document.getElementById('task-time-end').value;
    
    // Сохраняем UUID родителя ДО сохранения (для случая создания подзадачи)
    var isCreatingSubtask = (action === 'create_task' && parentTaskUuid && parentTaskUuid !== '');
    var createdParentUuid = isCreatingSubtask ? parentTaskUuid : null;
    
    logDebug('[SAVE_TASK] Action:', action, 'isCreatingSubtask:', isCreatingSubtask, 'parentTaskUuid:', parentTaskUuid);
    
    var formData = new URLSearchParams();
    formData.append('action', action);
    formData.append('uuid', uuid);
    formData.append('project_uuid', document.getElementById('task-project-select').value);
    formData.append('parent_task_uuid', parentTaskUuid);
    formData.append('title', title);
    formData.append('descr', document.getElementById('task-descr').value.trim());
    formData.append('assigned_to_uuid', document.getElementById('task-assigned-to').value);
    formData.append('time_start', localStart);
    formData.append('time_end_plan', localEnd);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    if (!title) { showAlert('Название задачи обязательно', 'warning'); return; }
    var submitBtn = document.querySelector('#task-form button[type="submit"]');
    var originalText = submitBtn ? submitBtn.innerHTML : 'Сохранить';
    if (submitBtn && retryCount === 0) { submitBtn.innerHTML = '⏳ Сохранение...'; submitBtn.disabled = true; }
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(response) {
        if (response.status === 503 && retryCount < 3) {
            var delay = Math.min(10000, 1000 * Math.pow(2, retryCount));
            if (submitBtn) submitBtn.innerHTML = '⏳ Повтор через ' + Math.round(delay/1000) + 'с...';
            setTimeout(function() { saveTask(e, retryCount + 1); }, delay);
            return null;
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(function(d) {
        if (d && d.success) {
            closeModal('task-modal');
            showAlert('Задача "' + title + '" ' + (action === 'create_task' ? 'создана' : 'сохранена'), 'success');
            
            // v4.7: Если создавалась подзадача — используем ensureTaskVisible
            if (isCreatingSubtask && d.task && d.task.uuid) {
                var newTaskUuid = d.task.uuid;
                logDebug('[SAVE_TASK] Created subtask, ensuring visibility for task:', newTaskUuid);
                ensureTaskVisible(newTaskUuid, function(success) {
                    if (success) {
                        logDebug('[SAVE_TASK] Task is now visible');
                    } else {
                        logDebug('[SAVE_TASK] Failed to ensure visibility, fallback reload');
                        if (currentProjectUuid) loadProjectTasks(true);
                    }
                });
            } else if (action === 'create_task' && d.task && d.task.uuid) {
                // Корневая задача — просто перезагружаем
                if (currentProjectUuid) loadProjectTasks(true);
            } else {
                // Редактирование задачи
                if (currentProjectUuid) loadProjectTasks(true);
            }
            
            if (d.refresh_dashboard && typeof window.refreshDashboard === 'function') setTimeout(function() { window.refreshDashboard(); }, 500);
            if (window.SSE && typeof window.SSE.refreshCounters === 'function') setTimeout(function() { window.SSE.refreshCounters(); }, 1000);
        } else if (d && !d.success) {
            if (d.csrf_error) showAlert('Ошибка безопасности. Обновите страницу.', 'error');
            else showAlert('Ошибка: ' + (d.error || 'Неизвестная'), 'error');
        }
        if (submitBtn) { submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
    })
    .catch(function(err) {
        logError('[SAVE_TASK] Error:', err);
        if (retryCount < 3) {
            var delay = Math.min(10000, 1000 * Math.pow(2, retryCount));
            setTimeout(function() { saveTask(e, retryCount + 1); }, delay);
        } else {
            showAlert('Ошибка сохранения задачи. Попробуйте позже.', 'error');
            if (submitBtn) { submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
        }
    });
}
// ==================== BLOCK END: saveTask v4.7 ====================



function deleteTaskWithConfirm() {
    var confirmValue = document.getElementById('task-delete-confirm').value;
    if (confirmValue !== 'DELETE') { showAlert('Для подтверждения удаления введите слово DELETE', 'error'); return; }
    if (!confirm('ВНИМАНИЕ! Это действие НЕОБРАТИМО. Удалить задачу и все подзадачи?')) return;
    var uuid = document.getElementById('task-uuid').value.trim();
    var formData = new URLSearchParams();
    formData.append('action', 'delete_task');
    formData.append('uuid', uuid);
    formData.append('confirm', 'DELETE');
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            closeModal('task-modal');
            showAlert('Задача удалена', 'success');
            if (currentProjectUuid) loadProjectTasks(true);
        } else { showAlert('Ошибка: ' + d.error, 'error'); }
    });
}

// ========== ПРОЕКТЫ ==========
function showCreateProjectModal() {
    // Проверяем права перед открытием модального окна
    var createProjectBtn = document.getElementById('create-project-btn');
    if (createProjectBtn && createProjectBtn.style.display === 'none') {
        showAlert('У вас нет прав на создание проектов', 'warning');
        return;
    }
    
    document.getElementById('project-modal-title').innerText = 'Создание проекта';
    document.getElementById('project-uuid').value = '';
    document.getElementById('project-title').value = '';
    document.getElementById('project-descr').value = '';
    var projectDeleteSection = document.getElementById('project-delete-section');
    if (projectDeleteSection) projectDeleteSection.style.display = 'none';
    document.getElementById('project-modal').classList.add('active');
}

function editProject(uuid) {
    var p = projectsData.find(function(x) { return x.uuid === uuid; });
    if (!p) return;
    document.getElementById('project-modal-title').innerText = 'Редактирование проекта';
    document.getElementById('project-uuid').value = p.uuid;
    document.getElementById('project-title').value = p.title;
    document.getElementById('project-descr').value = p.descr || '';
    var projectDeleteSection = document.getElementById('project-delete-section');
    if (projectDeleteSection) projectDeleteSection.style.display = 'block';
    document.getElementById('project-modal').classList.add('active');
}

function saveProject(e) {
    e.preventDefault();
    var uuid = document.getElementById('project-uuid').value.trim();
    var title = document.getElementById('project-title').value.trim();
    var descr = document.getElementById('project-descr').value.trim();
    if (!title) { showAlert('Название обязательно', 'warning'); return; }
    var action = uuid ? 'edit_project' : 'create_project';
    var formData = new URLSearchParams();
    formData.append('action', action);
    formData.append('uuid', uuid);
    formData.append('title', title);
    formData.append('descr', descr);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            closeModal('project-modal');
            showAlert('Проект "' + title + '" ' + (action === 'create_project' ? 'создан' : 'сохранён'), 'success');
            location.reload();
        } else { showAlert('Ошибка: ' + (d.error || 'Неизвестная'), 'error'); }
    })
    .catch(function(err) { logError(err); showAlert('Ошибка сохранения', 'error'); });
}

function deleteProjectWithConfirm() {
    var confirmValue = document.getElementById('project-delete-confirm').value;
    if (confirmValue !== 'DELETE') { showAlert('Для подтверждения удаления введите слово DELETE', 'error'); return; }
    if (!confirm('ВНИМАНИЕ! Это действие НЕОБРАТИМО. Вы уверены, что хотите удалить проект и все его данные?')) return;
    var uuid = document.getElementById('project-uuid').value.trim();
    var formData = new URLSearchParams();
    formData.append('action', 'delete_project');
    formData.append('uuid', uuid);
    formData.append('confirm', 'DELETE');
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            closeModal('project-modal');
            showAlert('Проект удалён', 'success');
            location.reload();
        } else { showAlert('Ошибка: ' + d.error, 'error'); }
    });
}

// ========== КОПИРОВАНИЕ ССЫЛКИ НА ЗАДАЧУ ==========
function copyTaskLink(taskUuid, taskTitle) {
    var fullUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + taskUuid;
    navigator.clipboard.writeText(fullUrl).then(function() {
        showAlert('✓ Ссылка на задачу "' + taskTitle + '" скопирована', 'success');
    }).catch(function() {
        var textarea = document.createElement('textarea');
        textarea.value = fullUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showAlert('✓ Ссылка на задачу "' + taskTitle + '" скопирована', 'success');
    });
}

// ========== ОСНОВНАЯ ЛОГИКА ЗАГРУЗКИ ==========

// ==================== BLOCK START: selectProject v4.3 (with loading indicator auto-hide) ====================
// ver.4.0 - Базовая версия
// ver.4.2 (2026-06-05) - ДОБАВЛЕНО УПРАВЛЕНИЕ ИНДИКАТОРОМ ЗАГРУЗКИ
// ver.4.3 (2026-06-05) - ИСПРАВЛЕНА ПРОБЛЕМА: индикатор скрывается при ошибках
// ver.4.4 (2026-06-16) - ДОБАВЛЕН СБРОС БЕЙДЖА ПРИ ВЫБОРЕ ПРОЕКТА
// - При выборе проекта скрываем красный бейдж с уведомлениями
// - Отправляем запрос на сервер для пометки уведомлений проекта как прочитанных

function selectProject(uuid) {
    logDebug('[SELECT_PROJECT] Selecting project:', uuid);
    currentProjectUuid = uuid;
    
    // Показываем индикатор загрузки при выборе проекта
    if (typeof window.setPageLoadStatus === 'function') {
        window.setPageLoadStatus('loading', '⏳ Загрузка проекта...', true);
    }
    
    // ========== V4.4: СБРАСЫВАЕМ БЕЙДЖ ПРИ ВЫБОРЕ ПРОЕКТА ==========
    // Скрываем бейдж на карточке проекта (оптимистичное обновление)
    var badge = document.getElementById('project-badge-' + uuid);
    if (badge) {
        badge.style.display = 'none';
        logDebug('[SELECT_PROJECT] Hidden badge for project:', uuid);
    }
    
    // Отправляем запрос на сброс уведомлений для этого проекта
    var csrfToken = window.csrfToken || '';
    var formData = new URLSearchParams();
    formData.append('action', 'mark_project_notifications_read');
    formData.append('project_uuid', uuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            logDebug('[SELECT_PROJECT] Project notifications marked as read:', data.marked_count || 0);
        } else {
            logWarning('[SELECT_PROJECT] Failed to mark notifications as read:', data.error);
        }
    })
    .catch(function(err) {
        logError('[SELECT_PROJECT] Error marking notifications as read:', err);
    });
    // ========== КОНЕЦ V4.4 ==========
    
    loadedFilesCache = {};
    
    document.querySelectorAll('.project-card').forEach(function(card) {
        card.classList.toggle('selected', card.dataset.projectUuid === uuid);
    });
    document.getElementById('tasks-area').style.display = 'block';
    var project = projectsData.find(function(p) { return p.uuid === uuid; });
    if (project) {
        document.getElementById('selected-project-title').innerHTML = escapeHtml(project.title) + ' <span style="font-size:13px;color:rgba(233,238,252,.6);font-weight:normal">- задачи проекта</span>';
    }
    
    // Проверка прав на создание задач
    checkTaskCreationPermission(uuid);
    
    // Загрузка пользователей для фильтра
    loadProjectUsersForFilter(uuid);
    
    resetFiltersAndLoad();
    
    // v4.3: Таймер безопасности - скрыть индикатор через 8 секунд если загрузка не завершилась
    setTimeout(function() {
        if (typeof window.setPageLoadStatus === 'function') {
            // Проверяем, не скрыт ли уже индикатор
            var indicator = document.getElementById('page-load-indicator');
            if (indicator && indicator.style.opacity !== '0') {
                logDebug('[SELECT_PROJECT] Safety timeout: hiding indicator');
                window.setPageLoadStatus('ready', '✓ Загрузка завершена', false);
            }
        }
    }, 8000);
    
    setTimeout(function() {
        var tasksArea = document.getElementById('tasks-area');
        if (tasksArea) tasksArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}
// ==================== BLOCK END: selectProject v4.4 ====================

function resetFiltersAndLoad() {
    logDebug('[RESET_FILTERS] Resetting all filters');
    currentTaskPage = 1;
    currentSearch = '';
    currentFilterStatuses = [];
    currentFilterAssigned = [];
    currentSortBy = 'last_activity';
    currentSortDir = 'DESC';
    var savedPerPage = localStorage.getItem('tasks_per_page');
    currentPerPage = savedPerPage ? parseInt(savedPerPage) : 10;
    
    var searchInput = document.getElementById('task-search');
    var sortBy = document.getElementById('sort-by');
    var perPageSelect = document.getElementById('per-page');
    var statusDropdown = document.getElementById('status-filter-dropdown');
    var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
    
    if (searchInput) searchInput.value = '';
    if (sortBy) sortBy.value = 'last_activity';
    if (perPageSelect) perPageSelect.value = currentPerPage.toString();
    if (statusDropdown) { var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]'); statusCbs.forEach(function(cb) { cb.checked = false; }); }
    if (assigneeDropdown) { var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]'); assigneeCbs.forEach(function(cb) { cb.checked = false; }); }
    
    updateStatusFilter();
    updateAssigneeFilter();
    
    // ========== ДОБАВИТЬ ЭТУ СТРОКУ ==========
    resetSubtasksCache();  // Сбрасываем кэш подзадач при сбросе фильтров
    
    saveFilterSettings();
    
    loadProjectTasks(true);
}



// ==================== BLOCK START: updateStatusFilter v4.8 ====================
// ver.4.0 - Базовая версия
// ver.4.1 (2026-06-02) - ДОБАВЛЕНО ЛОГИРОВАНИЕ И ЯВНАЯ УСТАНОВКА ПЕРЕМЕННОЙ
// ver.4.8 (2026-06-03) - ДОБАВЛЕНО СОХРАНЕНИЕ НАСТРОЕК ПРИ ИЗМЕНЕНИИ ФИЛЬТРА СТАТУСОВ

function updateStatusFilter() {
    var statusDropdown = document.getElementById('status-filter-dropdown');
    var statusBtn = document.getElementById('status-filter-btn');
    if (!statusDropdown || !statusBtn) return;
    
    var newFilterStatuses = [];
    var checkboxes = statusDropdown.querySelectorAll('input[type="checkbox"]:checked');
    checkboxes.forEach(function(cb) { 
        newFilterStatuses.push(parseInt(cb.value)); 
    });
    
    currentFilterStatuses = newFilterStatuses;
    saveFilterSettings();
    
    // ========== ДОБАВИТЬ ЭТУ СТРОКУ ==========
    resetSubtasksCache();  // Сбрасываем кэш подзадач при изменении фильтра
    
    var btnSpan = statusBtn.querySelector('span:first-child');
    if (btnSpan) {
        if (currentFilterStatuses.length === 0) btnSpan.innerHTML = '📊 Статус: все';
        else if (currentFilterStatuses.length === 1) {
            var statusText = currentFilterStatuses[0] === 0 ? '🟢 Активные' : '✅ Выполненные';
            btnSpan.innerHTML = '📊 ' + statusText;
        } else btnSpan.innerHTML = '📊 Статус: выбрано ' + currentFilterStatuses.length;
    }
    
    logDebug('[FILTER] Statuses updated (v4.8):', JSON.stringify(currentFilterStatuses));
}
// ==================== BLOCK END: updateStatusFilter v4.8 ====================



// ==================== BLOCK START: updateAssigneeFilter v4.8 ====================
// ver.4.0 - Базовая версия
// ver.4.1 (2026-06-02) - ДОБАВЛЕНО ЛОГИРОВАНИЕ И ЯВНАЯ УСТАНОВКА ПЕРЕМЕННОЙ
// ver.4.8 (2026-06-03) - ДОБАВЛЕНО СОХРАНЕНИЕ НАСТРОЕК ПРИ ИЗМЕНЕНИИ ФИЛЬТРА ИСПОЛНИТЕЛЕЙ

function updateAssigneeFilter() {
    var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
    var assigneeBtn = document.getElementById('assignee-filter-btn');
    if (!assigneeDropdown || !assigneeBtn) return;
    
    var newFilterAssigned = [];
    var checkboxes = assigneeDropdown.querySelectorAll('input[type="checkbox"]:checked');
    checkboxes.forEach(function(cb) { 
        newFilterAssigned.push(cb.value); 
    });
    
    // v4.1: Явно присваиваем глобальной переменной
    currentFilterAssigned = newFilterAssigned;
    
    // v4.8: Сохраняем настройки при изменении исполнителей
    saveFilterSettings();

    resetSubtasksCache();  // Сбрасываем кэш подзадач при изменении фильтра
    
    var btnSpan = assigneeBtn.querySelector('span:first-child');
    if (btnSpan) {
        if (currentFilterAssigned.length === 0) btnSpan.innerHTML = '👤 Исполнители: все';
        else if (currentFilterAssigned.length === 1) {
            var label = assigneeDropdown.querySelector('label[for="' + checkboxes[0].id + '"]');
            var name = label ? label.textContent : 'выбран';
            btnSpan.innerHTML = '👤 ' + name;
        } else btnSpan.innerHTML = '👤 Исполнители: выбрано ' + currentFilterAssigned.length;
    }
    
    logDebug('[FILTER] Assignees updated (v4.8):', JSON.stringify(currentFilterAssigned));
}
// ==================== BLOCK END: updateAssigneeFilter v4.8 ====================


// При изменении фильтров сбрасываем кэш подзадач
function resetSubtasksCache() {
    var allContainers = document.querySelectorAll('.subtasks-container');
    for (var i = 0; i < allContainers.length; i++) {
        allContainers[i].setAttribute('data-loaded', 'false');
        allContainers[i].setAttribute('data-page', '1');
        var listDiv = allContainers[i].querySelector('.subtasks-list');
        if (listDiv) listDiv.innerHTML = '';
    }
    isLoadingSubtasks = {};
    logDebug('[RESET_CACHE] Subtasks cache cleared');
}


// ==================== BLOCK START: loadProjectTasks v4.3 (with loading indicator auto-hide) ====================
// ver.4.0 - Базовая версия
// ver.4.1 (2026-06-02) - ДОБАВЛЕНО ЛОГИРОВАНИЕ ОТПРАВЛЯЕМЫХ ПАРАМЕТРОВ
// ver.4.2 (2026-06-05) - ДОБАВЛЕНО УПРАВЛЕНИЕ ИНДИКАТОРОМ ЗАГРУЗКИ
// ver.4.3 (2026-06-05) - ИСПРАВЛЕНА ПРОБЛЕМА: индикатор теперь скрывается даже при ошибке или пустых данных

function loadProjectTasks(resetPage) {
    if (!currentProjectUuid) { 
        logDebug('[LOAD_TASKS] No project selected'); 
        // v4.3: Если нет проекта, скрываем индикатор
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('ready', '✓ Выберите проект', false);
        }
        return; 
    }
    if (resetPage === true) { 
        currentTaskPage = 1; 
        logDebug('[LOAD_TASKS] Resetting page to 1'); 
    }
    if (isLoadingTasks) { 
        logDebug('[LOAD_TASKS] Already loading, skipping'); 
        return; 
    }
    isLoadingTasks = true;
    
    // Показываем индикатор загрузки (keepVisible = true)
    if (typeof window.setPageLoadStatus === 'function') {
        window.setPageLoadStatus('loading', '⏳ Загрузка задач...', true);
    }
    
    var container = document.getElementById('tasks-list-container');
    if (container && currentTaskPage === 1) container.innerHTML = '<div class="tasks-loading">⏳ Загрузка задач...</div>';
    
    logDebug('[LOAD_TASKS] Loading page', currentTaskPage, 'with per_page', currentPerPage);
    logDebug('[LOAD_TASKS] filter_statuses (v4.1):', JSON.stringify(currentFilterStatuses));
    logDebug('[LOAD_TASKS] filter_assigned (v4.1):', JSON.stringify(currentFilterAssigned));
    logDebug('[LOAD_TASKS] search (v4.1):', currentSearch);
    logDebug('[LOAD_TASKS] sort_by:', currentSortBy, 'sort_dir:', currentSortDir);
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_project_tasks_sorted');
    formData.append('project_uuid', currentProjectUuid);
    formData.append('page', currentTaskPage);
    formData.append('per_page', currentPerPage);
    formData.append('sort_by', currentSortBy);
    formData.append('sort_dir', currentSortDir);
    formData.append('filter_statuses', JSON.stringify(currentFilterStatuses));
    formData.append('filter_assigned', JSON.stringify(currentFilterAssigned));
    formData.append('search', currentSearch);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(response) {
        if (response.status === 503) throw new Error('503 Service Unavailable');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(function(data) {
        isLoadingTasks = false;
        
        if (data.success) {
            logDebug('[LOAD_TASKS] Received', data.tasks.length, 'tasks, total:', data.total);
            renderTaskList(data.tasks);
            renderPagination(data.total, data.page, data.per_page);
            // v4.3: Скрываем индикатор при успешной загрузке
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('ready', '✓ Готово', false);
            }
        } else if (data.error) {
            logError('[LOAD_TASKS] Server error:', data.error);
            if (container) container.innerHTML = '<div class="empty-state">❌ ' + escapeHtml(data.error) + '</div>';
            // v4.3: Скрываем индикатор при ошибке
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('error', '⚠️ ' + escapeHtml(data.error), false);
            }
        } else {
            if (container) container.innerHTML = '<div class="empty-state">📭 Нет задач в этом проекте</div>';
            // v4.3: Скрываем индикатор если задач нет
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('ready', '✓ Нет задач', false);
            }
        }
    })
    .catch(function(err) {
        isLoadingTasks = false;
        logError('[LOAD_TASKS] Error:', err.message);
        
        var container = document.getElementById('tasks-list-container');
        if (container) container.innerHTML = '<div class="empty-state">❌ Ошибка загрузки: ' + escapeHtml(err.message) + '</div>';
        
        // v4.3: Скрываем индикатор при сетевой ошибке
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('error', '⚠️ Ошибка: ' + err.message, false);
        }
    });
}
// ==================== BLOCK END: loadProjectTasks v4.3 ====================



// ==================== BLOCK START: renderTaskList v4.15 (with unread notifications priority) ====================
// ver.4.14 (2026-06-11) - ПРИ ФИЛЬТРАЦИИ ПОДЗАДАЧИ ПРИНУДИТЕЛЬНО РАСКРЫВАЮТСЯ
// ver.4.15 (2026-06-16) - ДОБАВЛЕНА ПОДСВЕТКА ЗАДАЧ С НЕПРОЧИТАННЫМИ УВЕДОМЛЕНИЯМИ
// - Задачи с непрочитанными уведомлениями получают специальный класс и бейдж
// - Бейдж отображается рядом с количеством сообщений

function renderTaskList(tasks) {
    var container = document.getElementById('tasks-list-container');
    if (!container) {
        logDebug('[RENDER_TASK_LIST] Container not found');
        return;
    }
    if (!tasks || tasks.length === 0) {
        container.innerHTML = '<div class="empty-state">📭 Нет задач, соответствующих фильтрам</div>';
        logDebug('[RENDER_TASK_LIST] No tasks to render');
        return;
    }
    
    // v4.14: Проверяем, есть ли активные фильтры
    var hasActiveFilters = (currentFilterStatuses.length > 0) || 
                           (currentFilterAssigned.length > 0) || 
                           (currentSearch && currentSearch.trim() !== '');
    
    logDebug('[RENDER_TASK_LIST] Rendering ' + tasks.length + ' tasks, hasActiveFilters:', hasActiveFilters);
    
    var html = '<div class="flat-tasks-list">';
    var taskUuidsForFiles = [];
    
    for (var i = 0; i < tasks.length; i++) {
        var task = tasks[i];
        var isCompleted = (task.status === 1);
        var assigneeText = task.assignee_name || task.assignee_login || 'Не назначен';
        var hasSubtasks = (task.subtasks_count > 0);
        var isOverdue = false;
        if (task.time_end_plan && !isCompleted) {
            var deadlineDate = parseInt(task.time_end_plan);
            isOverdue = deadlineDate < Date.now();
        }
        
        // v4.9: Определяем, является ли задача родительским контекстом
        var isParentContext = task.is_parent_context === true;
        var isCollapsedByDefault = task.is_collapsed_by_default === true;
        
        // v4.14: Если есть активные фильтры, то подзадачи ДОЛЖНЫ быть раскрыты
        // Также если задача сама соответствует фильтру (родительский контекст) - раскрываем
        var shouldExpand = hasActiveFilters || (isParentContext && task.has_filtered_children === true);
        
        var parentContextClass = isParentContext ? ' parent-context-task' : '';
        // Для родительских контекстов при фильтрации НЕ сворачиваем
        var collapsedClass = (isParentContext && !hasActiveFilters && isCollapsedByDefault) ? ' collapsed-by-default' : '';
        
        // ========== V4.15: КЛАСС ДЛЯ ЗАДАЧ С НЕПРОЧИТАННЫМИ УВЕДОМЛЕНИЯМИ ==========
        var hasUnreadNotifications = (task.unread_notifications_count && task.unread_notifications_count > 0);
        var unreadClass = hasUnreadNotifications ? ' has-unread-notifications' : '';
        // ========== КОНЕЦ V4.15 ==========
        
        var descrHtml = '';
        var descrText = task.descr || '';
        var descId = 'task_' + task.uuid + '_desc';
        if (descrText.trim() !== '') {
            descrHtml = '<div class="task-description collapsible-description collapsed" data-desc-id="' + descId + '">' + parseDescriptionLinks(descrText) + '</div>';
        } else {
            descrHtml = '<div class="task-description-empty">Нет описания</div>';
        }
        
        var filesContainerId = 'task-files-' + task.uuid;
        var taskUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + task.uuid;
        var escapedTitle = escapeHtml(task.title).replace(/"/g, '&quot;');
        var isSubscribed = task.is_subscribed === true;
        var subBtnText = isSubscribed ? '🔕 Отписаться' : '🔔 Подписаться';
        var subBtnClass = isSubscribed ? 'subscribed' : '';
        
        var parentContextBadge = isParentContext ? '<span class="flat-task-badge" style="background: rgba(79,124,255,0.3);">📁 Контекст фильтра</span>' : '';
        
        // ========== V4.15: БЕЙДЖ НЕПРОЧИТАННЫХ УВЕДОМЛЕНИЙ ==========
        var unreadBadge = '';
        if (hasUnreadNotifications) {
            unreadBadge = '<span class="flat-task-badge clickable unread-badge" style="background: rgba(239,68,68,0.2); color: #f87171; cursor: pointer;" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + task.uuid + '\')" title="Непрочитанные уведомления в задаче">🔔 ' + task.unread_notifications_count + '</span>';
        }
        // ========== КОНЕЦ V4.15 ==========
        
        html += '<div class="flat-task-item' + parentContextClass + collapsedClass + unreadClass + '" data-task-uuid="' + task.uuid + '" data-is-parent-context="' + (isParentContext ? 'true' : 'false') + '">';
        html += '<div class="flat-task-row-link" style="display: block; text-decoration: none; color: inherit;" data-task-url="' + taskUrl + '" data-task-title="' + escapedTitle + '">';
        html += '<div class="flat-task-row" style="cursor: pointer;">';
        html += '<div class="flat-task-checkbox ' + (isCompleted ? 'completed' : '') + '" onclick="event.stopPropagation(); toggleTaskStatus(event, \'' + task.uuid + '\', ' + (!isCompleted) + ')"></div>';
        html += '<div class="flat-task-content">';
        html += '<div class="flat-task-title ' + (isCompleted ? 'completed' : '') + '">' + escapeHtml(task.title) + '</div>';
        html += descrHtml;
        html += '<div class="flat-task-meta">';
        html += '<span class="flat-task-badge">👤 ' + escapeHtml(assigneeText) + '</span>';
        if (task.time_start) html += '<span class="flat-task-badge">🚀 ' + formatDate(task.time_start) + '</span>';
        if (task.time_end_plan) html += '<span class="flat-task-badge' + (isOverdue ? ' highlight' : '') + '">📅 ' + formatDate(task.time_end_plan) + (isOverdue ? ' ⚠️' : '') + '</span>';
        if (task.messages_count > 0) html += '<span class="flat-task-badge clickable" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + task.uuid + '\')">💬 ' + task.messages_count + '</span>';
        if (task.files_count > 0) html += '<span class="flat-task-badge">📎 ' + task.files_count + '</span>';
        if (hasSubtasks) html += '<span class="flat-task-badge clickable subtasks-toggle" data-task-uuid="' + task.uuid + '">📋 ' + task.subtasks_count + '</span>';
        if (parentContextBadge) html += parentContextBadge;
        // ========== V4.15: ДОБАВЛЯЕМ БЕЙДЖ УВЕДОМЛЕНИЙ ==========
        if (unreadBadge) html += unreadBadge;
        // ========== КОНЕЦ V4.15 ==========
        html += '</div>';
        html += '<div class="task-files-container" id="' + filesContainerId + '"><span class="files-loading">⏳ Загрузка файлов...</span></div>';
        html += '</div>';
        html += '<div class="flat-task-actions" onclick="event.stopPropagation()">';
        if (task.can_edit) {
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="editTask(\'' + task.uuid + '\')" title="Редактировать">✏️</button>';
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="createSubtask(\'' + task.uuid + '\')" title="Создать подзадачу">➕</button>';
        }
        html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="copyTaskLink(\'' + task.uuid + '\', \'' + escapeHtml(task.title).replace(/'/g, "\\'") + '\')" title="Скопировать ссылку на задачу">🔗</button>';
        html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px ' + subBtnClass + '" id="sub-btn-' + task.uuid + '" onclick="toggleTaskSubscription(\'' + task.uuid + '\', this)">' + subBtnText + '</button>';
        html += '<button class="btn-messages-icon" onclick="openTaskMessagesByUuid(\'' + task.uuid + '\')">💬</button>';

        if (task.files_count > 0) {
            html += '<a href="<?= $appBase ?>/files.php?task=' + task.uuid + '" class="btn-secondary" style="padding:4px 8px;font-size:11px; text-decoration: none;" target="_blank" title="Все файлы задачи">📎 Файлы</a>';
        }

        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        if (hasSubtasks) {
            // v4.14: При фильтрации контейнер должен быть сразу раскрыт
            var expandedClass = shouldExpand ? ' expanded' : '';
            logDebug('[RENDER_TASK_LIST] Task ' + task.uuid + ' has ' + task.subtasks_count + ' subtasks, expanded=' + shouldExpand);
            
            html += '<div class="subtasks-container' + expandedClass + '" id="children-' + task.uuid + '" data-loaded="false" data-page="1" data-has-more="false">';
            html += '<div class="subtasks-loading" style="display:none;">⏳ Загрузка подзадач...</div>';
            html += '<div class="subtasks-list"></div>';
            html += '<div class="subtasks-pagination" style="display:none;"></div>';
            html += '</div>';
        }
        html += '</div>';
        
        taskUuidsForFiles.push(task.uuid);
    }
    html += '</div>';
    container.innerHTML = html;
    
    logDebug('[RENDER_TASK_LIST] HTML inserted, container has ' + container.querySelectorAll('.subtasks-container').length + ' subtask containers');
    
    // Остальной код без изменений...
    var rows = container.querySelectorAll('.flat-task-row');
    for (var j = 0; j < rows.length; j++) {
        var parentLink = rows[j].closest('.flat-task-row-link');
        if (parentLink && parentLink.dataset.taskUrl) {
            rows[j].dataset.taskUrl = parentLink.dataset.taskUrl;
            rows[j].dataset.taskTitle = parentLink.dataset.taskTitle || 'задачи';
        }
        
        rows[j].addEventListener('click', function(e) {
            if (isSelectingText) {
                logDebug('[ROW_CLICK] Blocked by isSelectingText flag');
                e.stopPropagation();
                return;
            }
            
            var selection = window.getSelection();
            if (selection && selection.toString().trim().length > 0) {
                logDebug('[ROW_CLICK] Blocked by active selection');
                e.stopPropagation();
                return;
            }
            
            if (e.target.closest('.flat-task-actions, .flat-task-checkbox, .btn-secondary, .btn-messages-icon, .subtasks-toggle, .task-file-item')) {
                return;
            }
            
            if (e.target.closest('.task-description, .task-description-empty, .flat-task-title')) {
                logDebug('[ROW_CLICK] Click on text area, skipping copy');
                return;
            }
            
            var url = this.dataset.taskUrl;
            var title = this.dataset.taskTitle || 'задачи';
            if (url) {
                navigator.clipboard.writeText(url).then(function() {
                    showAlert('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                }).catch(function() {
                    var textarea = document.createElement('textarea');
                    textarea.value = url;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    showAlert('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                });
            }
        });
    }
    
    if (taskUuidsForFiles.length > 0) {
        loadFilesForTasksBatch(taskUuidsForFiles);
    }
    restoreHighlightAfterRender();
    
    initCollapsibleDescriptions();
    attachSubtaskToggleHandlers();
    
    // v4.14: Если есть активные фильтры и есть контейнеры, принудительно загружаем подзадачи
    if (hasActiveFilters) {
        var containers = document.querySelectorAll('.subtasks-container');
        logDebug('[RENDER_TASK_LIST] Auto-loading subtasks for ' + containers.length + ' containers due to active filters');
        
        containers.forEach(function(container) {
            var parentUuid = container.id.replace('children-', '');
            if (parentUuid && container.getAttribute('data-loaded') !== 'true') {
                var loadingDiv = container.querySelector('.subtasks-loading');
                if (loadingDiv) loadingDiv.style.display = 'block';
                
                loadSubtasks(parentUuid, container.id, 1, false).then(function() {
                    if (loadingDiv) loadingDiv.style.display = 'none';
                    logDebug('[RENDER_TASK_LIST] Auto-loaded subtasks for parent:', parentUuid);
                }).catch(function(err) {
                    logError('[RENDER_TASK_LIST] Auto-load failed for parent:', parentUuid, err);
                    if (loadingDiv) loadingDiv.style.display = 'none';
                });
            }
        });
    }

    logDebug('[RENDER_TASK_LIST] Handlers attached, rendered ' + tasks.length + ' tasks');
}
// ==================== BLOCK END: renderTaskList v4.15 ====================

// ==================== BLOCK START: Container Diagnostics v1.0 ====================
// ver.1.0 (2026-06-05) - Диагностика видимости контейнеров подзадач

function diagnoseSubtaskContainers() {
    var containers = document.querySelectorAll('.subtasks-container');
    logDebug('[DIAGNOSE] Found ' + containers.length + ' subtask containers');
    
    for (var i = 0; i < containers.length; i++) {
        var c = containers[i];
        var computedStyle = window.getComputedStyle(c);
        var isVisible = c.offsetParent !== null;
        var hasExpanded = c.classList.contains('expanded');
        
        logDebug('[DIAGNOSE] Container ' + i + ': id=' + c.id + 
                 ', expanded=' + hasExpanded + 
                 ', display=' + computedStyle.display + 
                 ', visible=' + isVisible +
                 ', data-loaded=' + c.getAttribute('data-loaded'));
    }
}

// Запускаем диагностику через 500мс после загрузки страницы
setTimeout(function() {
    diagnoseSubtaskContainers();
}, 1000);
// ==================== BLOCK END: Container Diagnostics v1.0 ====================


// ==================== BLOCK START: attachSubtaskToggleHandlers v2.2 (with mobile touch support) ====================
// ver.1.0 - Базовая версия
// ver.2.0 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА НА СУЩЕСТВОВАНИЕ КОНТЕЙНЕРА ПРИ КЛИКЕ
// ver.2.1 (2026-06-05) - УЛУЧШЕНА ОБРАБОТКА
// ver.2.2 (2026-06-11) - ДОБАВЛЕНА ПОДДЕРЖКА МОБИЛЬНЫХ УСТРОЙСТВ (touch events)
// - Добавлена обработка touchstart для мобильных
// - Улучшена визуальная обратная связь

function attachSubtaskToggleHandlers() {
    var toggles = document.querySelectorAll('.subtasks-toggle');
    logDebug('[ATTACH_HANDLERS] v2.2 Found ' + toggles.length + ' subtask toggle buttons');
    
    for (var i = 0; i < toggles.length; i++) {
        // Удаляем старый обработчик, если есть
        if (toggles[i]._subtaskHandler) {
            toggles[i].removeEventListener('click', toggles[i]._subtaskHandler);
            toggles[i].removeEventListener('touchstart', toggles[i]._subtaskHandler);
            logDebug('[ATTACH_HANDLERS] Removed old handler from button ' + i);
        }
        
        // Создаём новый обработчик
        var handler = function(e) {
            // Предотвращаем всплытие, но не предотвращаем действие по умолчанию полностью
            e.stopPropagation();
            if (e.type === 'touchstart') {
                e.preventDefault(); // Только для touch, чтобы избежать двойного срабатывания
            }
            
            var taskUuid = this.dataset.taskUuid;
            if (!taskUuid) {
                logDebug('[SUBTASK_TOGGLE] No task UUID found on button');
                return;
            }
            
            var childrenDiv = document.getElementById('children-' + taskUuid);
            
            if (!childrenDiv) {
                logDebug('[SUBTASK_TOGGLE] Container children-' + taskUuid + ' NOT FOUND, will retry');
                var self = this;
                setTimeout(function() {
                    var retryDiv = document.getElementById('children-' + taskUuid);
                    if (retryDiv) {
                        logDebug('[SUBTASK_TOGGLE] Container found on retry, executing click again');
                        self.click();
                    } else {
                        logDebug('[SUBTASK_TOGGLE] Container still not found after retry for task:', taskUuid);
                        showAlert('Ошибка: контейнер подзадач не найден', 'error');
                    }
                }, 100);
                return;
            }
            
            logDebug('[SUBTASK_TOGGLE] Toggling subtasks for task:', taskUuid, 'current expanded:', childrenDiv.classList.contains('expanded'));
            
            // Визуальная обратная связь
            this.style.transform = 'scale(0.95)';
            setTimeout(function(el) {
                if (el) el.style.transform = '';
            }, 150, this);
            
            if (childrenDiv.classList.contains('expanded')) {
                childrenDiv.classList.remove('expanded');
                logDebug('[SUBTASK_TOGGLE] Collapsed subtasks for task:', taskUuid);
            } else {
                childrenDiv.classList.add('expanded');
                logDebug('[SUBTASK_TOGGLE] Expanded container for task:', taskUuid);
                
                if (childrenDiv.getAttribute('data-loaded') !== 'true') {
                    var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
                    if (loadingDiv) {
                        loadingDiv.style.display = 'block';
                        logDebug('[SUBTASK_TOGGLE] Showing loading indicator');
                    }
                    loadSubtasks(taskUuid, 'children-' + taskUuid, 1, false).then(function() {
                        if (loadingDiv) loadingDiv.style.display = 'none';
                        logDebug('[SUBTASK_TOGGLE] Subtasks loaded for task:', taskUuid);
                    }).catch(function(err) {
                        logError('[SUBTASK_TOGGLE] Failed to load subtasks:', err);
                        if (loadingDiv) loadingDiv.style.display = 'none';
                    });
                } else {
                    logDebug('[SUBTASK_TOGGLE] Subtasks already loaded for task:', taskUuid);
                }
            }
        };
        
        // Добавляем оба типа событий для лучшей поддержки мобильных
        toggles[i].addEventListener('click', handler);
        toggles[i].addEventListener('touchstart', handler);
        toggles[i]._subtaskHandler = handler;
        logDebug('[ATTACH_HANDLERS] Attached handler to button ' + i + ' for task:', toggles[i].dataset.taskUuid);
    }
}
// ==================== BLOCK END: attachSubtaskToggleHandlers v2.2 ====================


// ========== ОТСЛЕЖИВАНИЕ ВЫДЕЛЕНИЯ ТЕКСТА ==========
// Отслеживаем начало выделения текста
document.addEventListener('mousedown', function(e) {
    // Проверяем, что клик внутри описания задачи или заголовка
    var target = e.target;
    if (target.closest && (target.closest('.task-description') || target.closest('.task-description-empty') || target.closest('.flat-task-title'))) {
        isSelectingText = false;
        if (textSelectionTimeout) clearTimeout(textSelectionTimeout);
        // Устанавливаем флаг, что возможно началось выделение
        textSelectionTimeout = setTimeout(function() {
            isSelectingText = true;
        }, 150);
    }
});

// Отслеживаем окончание выделения
document.addEventListener('mouseup', function() {
    var selection = window.getSelection();
    var selectedText = selection.toString().trim();
    
    if (selectedText.length > 0) {
        // Был выделен текст - блокируем следующий клик
        isSelectingText = true;
        logDebug('[TEXT_SELECTION] Text selected, length:', selectedText.length);
        // Сбрасываем флаг через небольшую задержку
        setTimeout(function() {
            isSelectingText = false;
        }, 300);
    } else if (textSelectionTimeout) {
        clearTimeout(textSelectionTimeout);
        isSelectingText = false;
    }
});

function subtaskToggleHandler(e) {
    e.stopPropagation();
    e.preventDefault();
    
    var taskUuid = this.dataset.taskUuid;
    var childrenDiv = document.getElementById('children-' + taskUuid);
    if (!childrenDiv) return;
    
    if (childrenDiv.classList.contains('expanded')) {
        childrenDiv.classList.remove('expanded');
    } else {
        childrenDiv.classList.add('expanded');
        if (childrenDiv.getAttribute('data-loaded') !== 'true') {
            var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
            if (loadingDiv) loadingDiv.style.display = 'block';
            loadSubtasks(taskUuid, 'children-' + taskUuid, 1, false).then(function() {
                if (loadingDiv) loadingDiv.style.display = 'none';
            });
        }
    }
}

function renderPagination(total, currentPage, perPage) {
    var paginationDiv = document.getElementById('tasks-pagination');
    if (!paginationDiv) return;
    var totalPages = Math.ceil(total / perPage);
    if (totalPages <= 1) { paginationDiv.style.display = 'none'; return; }
    paginationDiv.style.display = 'flex';
    
    var html = '';
    if (currentPage > 1) html += '<button class="btn-secondary" onclick="goToPage(' + (currentPage - 1) + ')">◀ Назад</button>';
    var startPage = Math.max(1, currentPage - 2);
    var endPage = Math.min(totalPages, currentPage + 2);
    if (startPage > 1) { html += '<button class="btn-secondary" onclick="goToPage(1)">1</button>'; if (startPage > 2) html += '<span style="color: rgba(233,238,252,.5);">...</span>'; }
    for (var i = startPage; i <= endPage; i++) {
        var isActive = (i === currentPage);
        html += '<button class="' + (isActive ? 'btn-primary' : 'btn-secondary') + '" onclick="goToPage(' + i + ')">' + i + '</button>';
    }
    if (endPage < totalPages) { if (endPage < totalPages - 1) html += '<span style="color: rgba(233,238,252,.5);">...</span>'; html += '<button class="btn-secondary" onclick="goToPage(' + totalPages + ')">' + totalPages + '</button>'; }
    if (currentPage < totalPages) html += '<button class="btn-secondary" onclick="goToPage(' + (currentPage + 1) + ')">Вперёд ▶</button>';
    var startItem = (currentPage - 1) * perPage + 1;
    var endItem = Math.min(currentPage * perPage, total);
    html += '<span class="pagination-info">' + startItem + '-' + endItem + ' из ' + total + '</span>';
    paginationDiv.innerHTML = html;
}

function goToPage(page) { currentTaskPage = page; loadProjectTasks(false); }


// ==================== BLOCK START: initMultiSelectFilters v4.2 ====================
// ver.4.0 - Базовая версия
// ver.4.1 (2026-06-02) - ДОБАВЛЕНА ЗАЩИТА ОТ ПОВТОРНОЙ ИНИЦИАЛИЗАЦИИ
// ver.4.2 (2026-06-02) - ИСПРАВЛЕНА ПРОВЕРКА СУЩЕСТВОВАНИЯ ЭЛЕМЕНТОВ

function initMultiSelectFilters() {
    logDebug('[MULTISELECT] Initializing multi-select filters (v4.2)');
    
    var statusBtn = document.getElementById('status-filter-btn');
    var statusDropdown = document.getElementById('status-filter-dropdown');
    var assigneeBtn = document.getElementById('assignee-filter-btn');
    var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
    
    if (statusBtn && statusDropdown) {
        logDebug('[MULTISELECT] Initializing status filter');
        
        // Удаляем старые обработчики, чтобы не дублировать
        var newStatusBtn = statusBtn.cloneNode(true);
        if (statusBtn.parentNode) {
            statusBtn.parentNode.replaceChild(newStatusBtn, statusBtn);
            statusBtn = newStatusBtn;
        }
        
        statusBtn.addEventListener('click', function(e) { 
            e.stopPropagation(); 
            if (statusDropdown) statusDropdown.classList.toggle('show'); 
            if (assigneeDropdown) assigneeDropdown.classList.remove('show');
            logDebug('[MULTISELECT] Status dropdown toggled');
        });
        
        var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]');
        statusCbs.forEach(function(cb) { 
            cb.removeEventListener('change', statusChangeHandler);
            cb.addEventListener('change', statusChangeHandler);
        });
        
        function statusChangeHandler() { 
            updateStatusFilter(); 
            resetSubtasksCache();
            loadProjectTasks(true);
            logDebug('[MULTISELECT] Status filter changed, reloading tasks');
        }
        
        logDebug('[MULTISELECT] Status filter ready, found', statusCbs.length, 'checkboxes');
    } else {
        logWarning('[MULTISELECT] Status filter elements not found - btn:', !!statusBtn, 'dropdown:', !!statusDropdown);
    }
    
    if (assigneeBtn && assigneeDropdown) {
        logDebug('[MULTISELECT] Initializing assignee filter');
        
        var newAssigneeBtn = assigneeBtn.cloneNode(true);
        if (assigneeBtn.parentNode) {
            assigneeBtn.parentNode.replaceChild(newAssigneeBtn, assigneeBtn);
            assigneeBtn = newAssigneeBtn;
        }
        
        assigneeBtn.addEventListener('click', function(e) { 
            e.stopPropagation(); 
            if (assigneeDropdown) assigneeDropdown.classList.toggle('show'); 
            if (statusDropdown) statusDropdown.classList.remove('show');
            logDebug('[MULTISELECT] Assignee dropdown toggled');
        });
        
        var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]');
        assigneeCbs.forEach(function(cb) { 
            cb.removeEventListener('change', assigneeChangeHandler);
            cb.addEventListener('change', assigneeChangeHandler);
        });
        
        function assigneeChangeHandler() { 
            updateAssigneeFilter(); 
            resetSubtasksCache();
            loadProjectTasks(true);
            logDebug('[MULTISELECT] Assignee filter changed, reloading tasks');
        }
        
        logDebug('[MULTISELECT] Assignee filter ready, found', assigneeCbs.length, 'checkboxes');
    } else {
        logWarning('[MULTISELECT] Assignee filter elements not found - btn:', !!assigneeBtn, 'dropdown:', !!assigneeDropdown);
    }
    
    // Закрытие дропдаунов при клике вне их
    document.addEventListener('click', function(e) {
        if (statusDropdown && !statusDropdown.contains(e.target) && e.target !== statusBtn && !statusBtn?.contains(e.target)) {
            statusDropdown.classList.remove('show');
        }
        if (assigneeDropdown && !assigneeDropdown.contains(e.target) && e.target !== assigneeBtn && !assigneeBtn?.contains(e.target)) {
            assigneeDropdown.classList.remove('show');
        }
    });
    
    logDebug('[MULTISELECT] Initialization complete');
}
// ==================== BLOCK END: initMultiSelectFilters v4.2 ====================


function initFilters() {
    var searchInput = document.getElementById('task-search');
    var sortBy = document.getElementById('sort-by');
    var perPageSelect = document.getElementById('per-page');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            if (loadDebounceTimer) clearTimeout(loadDebounceTimer);
            loadDebounceTimer = setTimeout(function() {
                currentSearch = e.target.value;
                logDebug('[FILTER] Search changed (v4.8):', currentSearch);
                saveFilterSettings();
                resetSubtasksCache();  // <-- ДОБАВИТЬ ЭТУ СТРОКУ
                loadProjectTasks(true);
            }, 500);
        });
    }
    
    if (sortBy) {
        sortBy.addEventListener('change', function(e) {
            currentSortBy = e.target.value;
            logDebug('[FILTER] Sort by changed (v4.8):', currentSortBy);
            saveFilterSettings();
            // ========== ДОБАВИТЬ ЭТУ СТРОКУ ==========
            resetSubtasksCache();  // Сбрасываем кэш подзадач при изменении сортировки
            loadProjectTasks(true);
        });
    }
    
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function(e) {
            currentPerPage = parseInt(e.target.value, 10);
            localStorage.setItem('tasks_per_page', currentPerPage);
            logDebug('[FILTER] Per page changed (v4.8):', currentPerPage);
            saveFilterSettings();
            // ========== ДОБАВИТЬ ЭТУ СТРОКУ ==========
            resetSubtasksCache();  // Сбрасываем кэш подзадач при изменении per_page
            loadProjectTasks(true);
        });
    }
}



// ========== ФУНКЦИИ ДЛЯ ПЕРЕХОДА ПО ССЫЛКЕ ==========
// ver.4.2 - ОПТИМИЗИРОВАН ПЕРЕХОД ПО ССЫЛКЕ НА ЗАДАЧУ
// ver.4.2 - ДОБАВЛЕНА ФУНКЦИЯ getTaskPageInfo() ДЛЯ ПОЛУЧЕНИЯ НОМЕРА СТРАНИЦЫ ЗАДАЧИ ОДНИМ ЗАПРОСОМ
// ver.4.2 - УСТРАНЕНА ДВОЙНАЯ ЗАГРУЗКА (сначала первая страница, потом поиск)
// ver.4.2 - ТЕПЕРЬ СРАЗУ ЗАГРУЖАЕТСЯ НУЖНАЯ СТРАНИЦА С ЗАДАЧЕЙ

// Получение информации о странице задачи одним запросом
// Возвращает { page: number, total: number, has_more: boolean }
function getTaskPageInfo(taskUuid, projectUuid, sortBy, sortDir, filterStatuses, filterAssigned, search, perPage) {
    logDebug('[GET_TASK_PAGE_INFO] Getting page info for task:', taskUuid);
    
    return new Promise(function(resolve, reject) {
        var formData = new URLSearchParams();
        formData.append('action', 'get_task_page_info');
        formData.append('task_uuid', taskUuid);
        formData.append('project_uuid', projectUuid);
        formData.append('per_page', perPage);
        formData.append('sort_by', sortBy);
        formData.append('sort_dir', sortDir);
        formData.append('filter_statuses', JSON.stringify(filterStatuses));
        formData.append('filter_assigned', JSON.stringify(filterAssigned));
        formData.append('search', search);
        formData.append('ajax_mode', '1');
        addCsrfToUrlParams(formData);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                logDebug('[GET_TASK_PAGE_INFO] Task found on page:', data.page);
                resolve(data);
            } else {
                logWarning('[GET_TASK_PAGE_INFO] Task not found:', data.error);
                reject(new Error(data.error || 'Task not found'));
            }
        })
        .catch(function(err) {
            logError('[GET_TASK_PAGE_INFO] Error:', err);
            reject(err);
        });
    });
}

function highlightAndScrollToTask(taskElement) {
    if (!taskElement) return;
    
    // Сохраняем UUID подсвеченной задачи
    highlightedTaskUuid = taskElement.dataset.taskUuid;
    logDebug('[HIGHLIGHT] Setting highlightedTaskUuid:', highlightedTaskUuid);
    
    // Убираем старую подсветку
    taskElement.classList.remove('highlight');
    
    // Форсируем перерисовку
    void taskElement.offsetWidth;
    
    // Добавляем новую подсветку
    taskElement.classList.add('highlight');
    
    // Плавная прокрутка к элементу
    taskElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Убираем подсветку через 30 секунд
    setTimeout(function() {
        var currentElement = document.querySelector('.flat-task-item[data-task-uuid="' + highlightedTaskUuid + '"], .subtask-item[data-task-uuid="' + highlightedTaskUuid + '"]');
        if (currentElement) {
            currentElement.classList.remove('highlight');
        }
        highlightedTaskUuid = null;
        logDebug('[HIGHLIGHT] Removed highlight after 30 seconds');
    }, 30000);
}

// ==================== BLOCK START: selectTaskByUuid v2.0 (using ensureTaskVisible) ====================
// ver.1.0 - Базовая версия с getTaskPageInfo
// ver.2.0 (2026-06-11) - УПРОЩЕНА с использованием ensureTaskVisible для поддержки глубокой вложенности

function selectTaskByUuid(taskUuid) {
    logDebug('[SELECT_TASK] v2.0 Looking for task:', taskUuid);
    
    // Проверяем, не отображается ли уже задача в DOM
    var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
    if (existingTask) {
        logDebug('[SELECT_TASK] Task already in DOM, highlighting');
        highlightAndScrollToTask(existingTask);
        return;
    }
    
    // Используем универсальную функцию ensureTaskVisible
    // Она сама получит parent_chain через API и раскроет всех родителей
    logDebug('[SELECT_TASK] Task not in DOM, using ensureTaskVisible');
    ensureTaskVisible(taskUuid, function(success) {
        if (success) {
            logDebug('[SELECT_TASK] Task is now visible');
        } else {
            logDebug('[SELECT_TASK] Failed to make task visible');
            showAlert('Не удалось найти задачу. Возможно, она была удалена или у вас нет доступа.', 'warning');
        }
    });
}
// ==================== BLOCK END: selectTaskByUuid v2.0 ====================



// ==================== BLOCK START: findAndExpandTask v5.2 (FIXED for 3+ levels) ====================
// ver.4.9 - Базовая версия
// ver.5.0 (2026-06-10) - ДОБАВЛЕН ПОИСК ПО ВСЕМ СТРАНИЦАМ
// ver.5.1 (2026-06-10) - ИСПРАВЛЕНА ЛОГИКА РАСКРЫТИЯ РОДИТЕЛЕЙ
// ver.5.2 (2026-06-11) - ИСПРАВЛЕНА РАБОТА ДЛЯ 3+ УРОВНЕЙ ВЛОЖЕННОСТИ
// - Теперь раскрываются ВСЕ уровни, а не только первый родитель
// - Добавлены задержки для гарантированной загрузки каждого уровня

function findAndExpandTask(targetUuid, maxAttempts, attempt, parentChain, searchPagesRemaining) {
    attempt = attempt || 0;
    maxAttempts = maxAttempts || 60;
    parentChain = parentChain || [];
    searchPagesRemaining = searchPagesRemaining || 0;
    
    logDebug('[FIND_TASK] v5.2 Attempt', attempt + 1, 'of', maxAttempts, 'for task:', targetUuid);
    logDebug('[FIND_TASK] Parent chain length:', parentChain.length, 'chain:', JSON.stringify(parentChain));
    
    var taskElement = document.querySelector('.flat-task-item[data-task-uuid="' + targetUuid + '"], .subtask-item[data-task-uuid="' + targetUuid + '"]');
    
    if (taskElement) {
        logDebug('[FIND_TASK] Task found! Highlighting and scrolling...');
        highlightAndScrollToTask(taskElement);
        return true;
    }
    
    // Если задача не найдена и это первая попытка - пробуем поискать по страницам
    if (attempt === 0 && searchPagesRemaining === 0 && currentProjectUuid) {
        logDebug('[FIND_TASK] Task not on page ' + currentTaskPage + ', starting cross-page search');
        searchTaskAcrossPages(targetUuid, 1, 20);
        return false;
    }
    
    // v5.2: Раскрываем ВСЕХ родителей по очереди, а не только первого
    if (parentChain && parentChain.length > 0) {
        // Функция для последовательного раскрытия всех родителей
        var currentIndex = 0;
        
        function expandNextParent() {
            if (currentIndex >= parentChain.length) {
                logDebug('[FIND_TASK] All parents expanded, searching for task...');
                setTimeout(function() {
                    findAndExpandTask(targetUuid, maxAttempts, attempt + 1, [], searchPagesRemaining);
                }, 500);
                return;
            }
            
            var parentId = parentChain[currentIndex];
            logDebug('[FIND_TASK] Expanding parent', currentIndex + 1, 'of', parentChain.length, ':', parentId);
            
            // Ждём появления родителя в DOM
            var parentAttempts = 0;
            var parentMaxAttempts = 30;
            
            function waitForParent() {
                var parentElement = document.querySelector('.flat-task-item[data-task-uuid="' + parentId + '"]');
                
                if (parentElement) {
                    logDebug('[FIND_TASK] Parent found in DOM:', parentId);
                    
                    var childrenDiv = document.getElementById('children-' + parentId);
                    if (childrenDiv && !childrenDiv.classList.contains('expanded')) {
                        logDebug('[FIND_TASK] Expanding container for parent:', parentId);
                        childrenDiv.classList.add('expanded');
                    }
                    
                    if (childrenDiv && childrenDiv.getAttribute('data-loaded') !== 'true') {
                        var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
                        if (loadingDiv) loadingDiv.style.display = 'block';
                        
                        logDebug('[FIND_TASK] Loading subtasks for parent:', parentId);
                        loadSubtasks(parentId, 'children-' + parentId, 1, false).then(function() {
                            if (loadingDiv) loadingDiv.style.display = 'none';
                            logDebug('[FIND_TASK] Subtasks loaded for parent:', parentId);
                            currentIndex++;
                            setTimeout(expandNextParent, 300);
                        }).catch(function(err) {
                            logError('[FIND_TASK] Failed to load subtasks:', err);
                            if (loadingDiv) loadingDiv.style.display = 'none';
                            currentIndex++;
                            setTimeout(expandNextParent, 500);
                        });
                    } else if (childrenDiv && childrenDiv.getAttribute('data-loaded') === 'true') {
                        logDebug('[FIND_TASK] Subtasks already loaded for parent:', parentId);
                        currentIndex++;
                        setTimeout(expandNextParent, 200);
                    } else {
                        // Нет контейнера подзадач - возможно, это корневая задача
                        currentIndex++;
                        setTimeout(expandNextParent, 200);
                    }
                } else {
                    parentAttempts++;
                    if (parentAttempts < parentMaxAttempts) {
                        logDebug('[FIND_TASK] Waiting for parent element:', parentId, 'attempt', parentAttempts);
                        setTimeout(waitForParent, 200);
                    } else {
                        logDebug('[FIND_TASK] Parent element not found after max attempts:', parentId);
                        currentIndex++;
                        expandNextParent();
                    }
                }
            }
            
            waitForParent();
        }
        
        expandNextParent();
        return false;
    }
    
    if (attempt >= maxAttempts) {
        logWarning('[FIND_TASK] Task not found after', maxAttempts, 'attempts:', targetUuid);
        showAlert('Задача не найдена. Возможно, она была удалена или у вас нет доступа.', 'warning');
        return false;
    }
    
    var delay = Math.min(1000, 200 + attempt * 30);
    setTimeout(function() {
        findAndExpandTask(targetUuid, maxAttempts, attempt + 1, parentChain, searchPagesRemaining);
    }, delay);
    return false;
}
// ==================== BLOCK END: findAndExpandTask v5.2 ====================

function searchTaskAcrossPages(targetUuid, startPage, maxPages) {
    var page = startPage;
    var totalPages = maxPages;
    
    function tryPage(p) {
        if (p > totalPages) {
            logWarning('[SEARCH_PAGES] Task not found on any of', totalPages, 'pages');
            showAlert('Задача не найдена. Возможно, она была удалена.', 'warning');
            return;
        }
        
        logDebug('[SEARCH_PAGES] Checking page', p, 'for task:', targetUuid);
        
        var formData = new URLSearchParams();
        formData.append('action', 'get_project_tasks_sorted');
        formData.append('project_uuid', currentProjectUuid);
        formData.append('page', p);
        formData.append('per_page', currentPerPage);
        formData.append('sort_by', currentSortBy);
        formData.append('sort_dir', currentSortDir);
        formData.append('filter_statuses', JSON.stringify(currentFilterStatuses));
        formData.append('filter_assigned', JSON.stringify(currentFilterAssigned));
        formData.append('search', currentSearch);
        formData.append('ajax_mode', '1');
        addCsrfToUrlParams(formData);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.tasks) {
                var found = data.tasks.some(function(t) { return t.uuid === targetUuid; });
                
                if (found) {
                    logDebug('[SEARCH_PAGES] Task found on page', p);
                    currentTaskPage = p;
                    loadProjectTasks(false);
                    
                    setTimeout(function() {
                        findAndExpandTask(targetUuid, 30, 0, window._cachedParentChain || [], -1);
                    }, 800);
                } else {
                    logDebug('[SEARCH_PAGES] Task not on page', p);
                    tryPage(p + 1);
                }
            } else {
                logError('[SEARCH_PAGES] Failed to load page', p);
                tryPage(p + 1);
            }
        })
        .catch(function(err) {
            logError('[SEARCH_PAGES] Error loading page', p, ':', err);
            tryPage(p + 1);
        });
    }
    
    tryPage(startPage);
}
// ==================== BLOCK END: findAndExpandTask v5.1 ====================


// ==================== BLOCK START: expandParentsChainInDom v2.0 (supports 3+ levels) ====================
// ver.1.0 - Базовая версия
// ver.2.0 (2026-06-11) - ПОДДЕРЖКА 3+ УРОВНЕЙ ВЛОЖЕННОСТИ
// - Рекурсивно раскрывает ВСЕ уровни, загружая подзадачи по мере необходимости

window.expandParentsChainInDom = function(parentChain, targetTaskUuid, callback) {
    if (!parentChain || parentChain.length === 0) {
        logDebug('[EXPAND_CHAIN] No parents to expand, searching for task:', targetTaskUuid);
        if (callback) callback();
        return;
    }
    
    logDebug('[EXPAND_CHAIN] Expanding ' + parentChain.length + ' parents for task:', targetTaskUuid);
    
    var index = 0;
    
    function expandNext() {
        if (index >= parentChain.length) {
            logDebug('[EXPAND_CHAIN] All parents expanded, searching for task:', targetTaskUuid);
            if (callback) callback();
            return;
        }
        
        var parentId = parentChain[index];
        logDebug('[EXPAND_CHAIN] Expanding parent', index + 1, 'of', parentChain.length, ':', parentId);
        
        var attempts = 0;
        var maxAttempts = 40;
        
        function waitForParent() {
            // Ищем родителя как в корневых, так и в подзадачах
            var parentElement = document.querySelector('.flat-task-item[data-task-uuid="' + parentId + '"], .subtask-item[data-task-uuid="' + parentId + '"]');
            
            if (parentElement) {
                logDebug('[EXPAND_CHAIN] Parent element found:', parentId);
                
                var childrenDiv = document.getElementById('children-' + parentId);
                if (!childrenDiv) {
                    logDebug('[EXPAND_CHAIN] Children container not found for:', parentId);
                    index++;
                    expandNext();
                    return;
                }
                
                // Раскрываем контейнер
                if (!childrenDiv.classList.contains('expanded')) {
                    childrenDiv.classList.add('expanded');
                    logDebug('[EXPAND_CHAIN] Expanded container for:', parentId);
                }
                
                // Если подзадачи ещё не загружены - загружаем
                if (childrenDiv.getAttribute('data-loaded') !== 'true') {
                    var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
                    if (loadingDiv) loadingDiv.style.display = 'block';
                    
                    logDebug('[EXPAND_CHAIN] Loading subtasks for parent:', parentId);
                    loadSubtasks(parentId, 'children-' + parentId, 1, false).then(function() {
                        if (loadingDiv) loadingDiv.style.display = 'none';
                        logDebug('[EXPAND_CHAIN] Subtasks loaded for parent:', parentId);
                        index++;
                        setTimeout(expandNext, 400);
                    }).catch(function(err) {
                        logError('[EXPAND_CHAIN] Failed to load subtasks:', err);
                        if (loadingDiv) loadingDiv.style.display = 'none';
                        index++;
                        setTimeout(expandNext, 600);
                    });
                } else {
                    logDebug('[EXPAND_CHAIN] Subtasks already loaded for parent:', parentId);
                    index++;
                    setTimeout(expandNext, 300);
                }
            } else {
                attempts++;
                if (attempts < maxAttempts) {
                    logDebug('[EXPAND_CHAIN] Waiting for parent element:', parentId, 'attempt', attempts);
                    setTimeout(waitForParent, 200);
                } else {
                    logDebug('[EXPAND_CHAIN] Parent element not found after max attempts:', parentId);
                    index++;
                    expandNext();
                }
            }
        }
        
        waitForParent();
    }
    
    expandNext();
};
// ==================== BLOCK END: expandParentsChainInDom v2.0 ====================

function findAndHighlightTask(taskUuid, maxAttempts, attempt) {
    attempt = attempt || 0;
    maxAttempts = maxAttempts || 50;
    
    logDebug('[FIND_TASK] Attempt', attempt + 1, 'of', maxAttempts, 'for task:', taskUuid);
    
    var taskElement = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
    
    if (taskElement) {
        logDebug('[FIND_TASK] Task found! Highlighting and scrolling...');
        highlightAndScrollToTask(taskElement);
        return true;
    }
    
    if (attempt < maxAttempts) {
        var delay = Math.min(500, 100 + attempt * 30);
        setTimeout(function() {
            findAndHighlightTask(taskUuid, maxAttempts, attempt + 1);
        }, delay);
        return false;
    }
    
    logDebug('[FIND_TASK] Task not found after', maxAttempts, 'attempts:', taskUuid);
    return false;
}

// ==================== BLOCK START: ensureTaskVisible v1.1 (with longer delays) ====================
// ver.1.0 (2026-06-11) - УНИВЕРСАЛЬНАЯ ФУНКЦИЯ ДЛЯ РАСКРЫТИЯ ЛЮБОЙ ЗАДАЧИ
// ver.1.1 (2026-06-11) - УВЕЛИЧЕНЫ ЗАДЕРЖКИ ДЛЯ 3+ УРОВНЕЙ

function ensureTaskVisible(taskUuid, callback) {
    logDebug('[ENSURE_VISIBLE] Ensuring task is visible:', taskUuid);
    
    // Сначала пробуем найти задачу в DOM
    var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
    if (existingTask) {
        logDebug('[ENSURE_VISIBLE] Task already visible, just highlight');
        if (typeof highlightAndScrollToTask === 'function') {
            highlightAndScrollToTask(existingTask);
        }
        if (callback) callback(true);
        return;
    }
    
    // Получаем цепочку родителей через API
    var formData = new URLSearchParams();
    formData.append('action', 'get_task_info');
    formData.append('task_uuid', taskUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.task) {
            var parentChain = data.task.parent_chain || [];
            logDebug('[ENSURE_VISIBLE] Parent chain:', JSON.stringify(parentChain));
            
            if (parentChain.length === 0) {
                // Корневая задача — просто перезагружаем страницу задач
                if (currentProjectUuid) {
                    loadProjectTasks(true);
                    setTimeout(function() {
                        window.findAndHighlightTask(taskUuid, 50, 0);
                    }, 1500);
                }
                if (callback) callback(true);
                return;
            }
            
            // Раскрываем всех родителей
            if (typeof window.expandParentsChainInDom === 'function') {
                window.expandParentsChainInDom(parentChain, taskUuid, function() {
                    logDebug('[ENSURE_VISIBLE] Parents expanded, searching for task...');
                    setTimeout(function() {
                        if (typeof window.findAndHighlightTask === 'function') {
                            window.findAndHighlightTask(taskUuid, 50, 0);
                        }
                        if (callback) callback(true);
                    }, 800);
                });
            } else {
                logError('[ENSURE_VISIBLE] expandParentsChainInDom not defined');
                if (callback) callback(false);
            }
        } else {
            logError('[ENSURE_VISIBLE] Task not found:', data.error);
            if (callback) callback(false);
        }
    })
    .catch(function(err) {
        logError('[ENSURE_VISIBLE] Error:', err);
        if (callback) callback(false);
    });
}
// ==================== BLOCK END: ensureTaskVisible v1.1 ====================


// ==================== BLOCK START: Final initialization v5.3 (COMPLETE FIX) ====================
// ver.5.3 (2026-06-11) - ПОЛНОСТЬЮ ИСПРАВЛЕНА ЛОГИКА ПЕРЕХОДА ПО ССЫЛКЕ
// - Добавлена недостающая функция expandParentsChainInDom
// - Исправлен порядок вызова: сначала выбор проекта, потом загрузка задач, потом раскрытие
// - Добавлены задержки для гарантированной загрузки DOM
// - Улучшено логирование

document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var taskUuid = urlParams.get('task');
    var projectUuid = urlParams.get('project');
    
    logDebug('[INIT] === START INITIALIZATION v5.3 ===');
    logDebug('[INIT] URL parameters - task:', taskUuid, 'project:', projectUuid);
    
    var hasUrlTaskOrProject = !!(taskUuid || projectUuid);
    
    // Инициализация фильтров
    var loadedSettings = null;
    if (!hasUrlTaskOrProject) {
        loadedSettings = loadFilterSettings();
        if (loadedSettings) {
            logDebug('[INIT] Loaded saved filter settings, applying...');
            applyFilterSettings(loadedSettings);
        }
    } else {
        logDebug('[INIT] URL has task/project parameter, skipping saved filter settings');
        if (taskUuid) {
            clearFilterSettings();
        }
    }
    
    initMultiSelectFilters();
    initFilters();
    
    var savedPerPage = localStorage.getItem('tasks_per_page');
    if (savedPerPage && !loadedSettings) {
        currentPerPage = parseInt(savedPerPage);
        var perPageEl = document.getElementById('per-page');
        if (perPageEl) perPageEl.value = currentPerPage.toString();
    }
    
    updateStatusFilter();
    updateAssigneeFilter();
    

    
    // ========== ОБРАБОТЧИК ЗАДАЧИ ИЗ URL ==========
    if (taskUuid) {
        logDebug('[INIT] Processing task URL parameter:', taskUuid);
        
        // Сначала получаем информацию о задаче
        var formData = new URLSearchParams();
        formData.append('action', 'get_task_info');
        formData.append('task_uuid', taskUuid);
        formData.append('ajax_mode', '1');
        addCsrfToUrlParams(formData);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.task) {
                var neededProjectUuid = data.task.project_uuid;
                var parentChain = data.task.parent_chain || [];
                
                logDebug('[INIT] Task info received:');
                logDebug('   - title:', data.task.title);
                logDebug('   - project:', neededProjectUuid);
                logDebug('   - parent_chain:', JSON.stringify(parentChain));
                
                // Сохраняем в глобальную переменную
                window._cachedParentChain = parentChain;
                
                // Проверяем, не отображается ли уже задача
                var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
                if (existingTask) {
                    logDebug('[INIT] Task already in DOM, just highlight');
                    highlightAndScrollToTask(existingTask);
                    return;
                }
                
                // Выбираем проект
                logDebug('[INIT] Selecting project:', neededProjectUuid);
                selectProject(neededProjectUuid);
                
                // Ждём загрузки задач и раскрываем родителей
                setTimeout(function() {
                    logDebug('[INIT] Starting parent chain expansion...');
                    expandParentsChainInDom(parentChain, taskUuid, function() {
                        logDebug('[INIT] Parent expansion complete, searching for task...');
                        setTimeout(function() {
                            findAndHighlightTask(taskUuid, 50, 0);
                        }, 500);
                    });
                }, 2000);
                
            } else {
                logDebug('[INIT] Task not found:', data.error);
                showAlert('Задача не найдена или нет доступа', 'warning');
            }
        })
        .catch(function(err) {
            logDebug('[INIT] Error loading task info:', err);
        });
    } 
    else if (projectUuid) {
        logDebug('[INIT] Processing project URL parameter:', projectUuid);
        var projectCard = document.querySelector('.project-card[data-project-uuid="' + projectUuid + '"]');
        if (projectCard) {
            selectProject(projectUuid);
        } else {
            logDebug('[INIT] Project card not found:', projectUuid);
        }
    } else {
        logDebug('[INIT] No task or project in URL, normal loading');
    }
    
    // Обновляем бейджи
    setTimeout(function() {
        if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
            window.SSE.updateAllBadges();
        } else if (typeof updateAllBadges === 'function') {
            updateAllBadges();
        }
    }, 300);
});

// Обработчик кнопки "Назад"
window.addEventListener('popstate', function(event) {
    var urlParams = new URLSearchParams(window.location.search);
    var taskUuid = urlParams.get('task');
    if (taskUuid) {
        logDebug('[POPSTATE] Navigating to task:', taskUuid);
        findAndHighlightTask(taskUuid, 50, 0);
    }
});
// ==================== BLOCK END: Final initialization v5.3 ====================


// ==================== BLOCK START: ensureSubtaskHandlers v1.0 ====================
// Автоматически привязывает обработчики к новым кнопкам подзадач

function ensureSubtaskHandlers() {
    var toggles = document.querySelectorAll('.subtasks-toggle');
    var needsUpdate = false;
    
    for (var i = 0; i < toggles.length; i++) {
        if (!toggles[i]._subtaskHandler) {
            needsUpdate = true;
            break;
        }
    }
    
    if (needsUpdate) {
        logDebug('[ENSURE_HANDLERS] Re-attaching handlers for subtask toggles');
        attachSubtaskToggleHandlers();
    }
}

// Наблюдатель за изменениями DOM
var observer = new MutationObserver(function(mutations) {
    var shouldCheck = false;
    for (var i = 0; i < mutations.length; i++) {
        if (mutations[i].type === 'childList' && mutations[i].addedNodes.length > 0) {
            shouldCheck = true;
            break;
        }
    }
    if (shouldCheck) {
        setTimeout(ensureSubtaskHandlers, 100);
    }
});

// Запускаем наблюдатель после загрузки страницы
setTimeout(function() {
    observer.observe(document.body, { childList: true, subtree: true });
    ensureSubtaskHandlers();
}, 1000);
// ==================== BLOCK END: ensureSubtaskHandlers v1.0 ====================


// ==================== BLOCK START: Project notification badge updater v1.0 ====================
// ver.1.0 (2026-06-16) - ФУНКЦИЯ ОБНОВЛЕНИЯ БЕЙДЖА ПРОЕКТА
// - Обновляет количество непрочитанных уведомлений на карточке проекта
// - Скрывает бейдж, если уведомлений нет

function updateProjectBadge(projectUuid, count) {
    var badge = document.getElementById('project-badge-' + projectUuid);
    var countSpan = document.getElementById('badge-count-' + projectUuid);
    
    if (!badge || !countSpan) {
        logDebug('[PROJECT_BADGE] Badge elements not found for project:', projectUuid);
        return;
    }
    
    var countNum = parseInt(count, 10) || 0;
    
    if (countNum > 0) {
        countSpan.textContent = countNum > 99 ? '99+' : countNum;
        badge.style.display = 'inline-flex';
        logDebug('[PROJECT_BADGE] Updated badge for project ' + projectUuid + ' to ' + countNum);
    } else {
        badge.style.display = 'none';
        logDebug('[PROJECT_BADGE] Hidden badge for project ' + projectUuid);
    }
}

// Функция для прокрутки к уведомлениям проекта (вызывается по клику на бейдж)
function scrollToNotifications(projectUuid) {
    logDebug('[PROJECT_BADGE] Clicked badge for project:', projectUuid);
    
    // Показываем/открываем задачи проекта
    var projectCard = document.querySelector('.project-card[data-project-uuid="' + projectUuid + '"]');
    if (projectCard) {
        projectCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        projectCard.classList.add('block-highlight');
        setTimeout(function() {
            projectCard.classList.remove('block-highlight');
        }, 2000);
    }
    
    // Выбираем проект, чтобы открыть задачи
    selectProject(projectUuid);
}

// Функция обновления всех бейджей проектов (вызывается при получении SSE-события)
function refreshAllProjectBadges() {
    var csrfToken = window.csrfToken || '';
    var url = window.APP_BASE + '/get_project_badges.php';
    
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(function(data) {
        if (data.success && data.badges) {
            logDebug('[PROJECT_BADGE] Received badges data:', data.badges);
            for (var projectUuid in data.badges) {
                if (data.badges.hasOwnProperty(projectUuid)) {
                    updateProjectBadge(projectUuid, data.badges[projectUuid]);
                }
            }
        }
    })
    .catch(function(err) {
        logError('[PROJECT_BADGE] Error fetching badges:', err);
    });
}

// Экспортируем функции в глобальную область
window.updateProjectBadge = updateProjectBadge;
window.scrollToNotifications = scrollToNotifications;
window.refreshAllProjectBadges = refreshAllProjectBadges;

logDebug('[PROJECT_BADGE] Functions initialized v1.0');
// ==================== BLOCK END: Project notification badge updater v1.0 ====================



// ==================== BLOCK START: SSE notification badge update handler v1.0 ====================
// ver.1.0 (2026-06-16) - ОБРАБОТЧИК ДЛЯ ОБНОВЛЕНИЯ БЕЙДЖЕЙ ПРОЕКТОВ ЧЕРЕЗ SSE
// - При получении события task_updated или task_broadcast обновляем бейджи

// Подписываемся на SSE события для обновления бейджей проектов
if (window.SSE && typeof window.SSE.addListener === 'function') {
    // Если SSE имеет систему подписок, добавляем слушатель
    window.SSE.addListener('task_updated', function(data) {
        logDebug('[SSE_PROJECT_BADGE] task_updated received, refreshing badges');
        if (typeof window.refreshAllProjectBadges === 'function') {
            setTimeout(window.refreshAllProjectBadges, 500);
        }
    });
    
    window.SSE.addListener('task_broadcast', function(data) {
        logDebug('[SSE_PROJECT_BADGE] task_broadcast received, refreshing badges');
        if (typeof window.refreshAllProjectBadges === 'function') {
            setTimeout(window.refreshAllProjectBadges, 500);
        }
    });
    
    logDebug('[SSE_PROJECT_BADGE] SSE listeners attached');
} else {
    // Fallback: периодическое обновление через интервал
    logDebug('[SSE_PROJECT_BADGE] SSE not available, using interval fallback');
    setInterval(function() {
        if (typeof window.refreshAllProjectBadges === 'function') {
            window.refreshAllProjectBadges();
        }
    }, 60000); // Обновляем каждую минуту
}

// Также обновляем при возвращении на вкладку
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        logDebug('[SSE_PROJECT_BADGE] Tab became visible, refreshing badges');
        setTimeout(function() {
            if (typeof window.refreshAllProjectBadges === 'function') {
                window.refreshAllProjectBadges();
            }
        }, 1000);
    }
});

// Инициализируем бейджи при загрузке страницы
setTimeout(function() {
    if (typeof window.refreshAllProjectBadges === 'function') {
        window.refreshAllProjectBadges();
    }
}, 2000);

logDebug('[SSE_PROJECT_BADGE] Handler initialized v1.0');
// ==================== BLOCK END: SSE notification badge update handler v1.0 ====================



</script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>