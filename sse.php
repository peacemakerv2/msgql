<?php
// sse.php ver.2.2 - Сервер отправки событий (Server-Sent Events)
// Оптимизировано для shared-хостинга
// ИСПРАВЛЕНИЯ: добавлены принудительные flush(), отключена компрессия,
// освобождение сессии до основного цикла
define('AJAX_REQUEST', true);

// Отключаем лимит времени выполнения
set_time_limit(0);
// 🔥 ВАЖНО: не прерывать скрипт при отключении клиента
ignore_user_abort(true);

// ========== ОТКЛЮЧАЕМ ВСЕ ВОЗМОЖНЫЕ БУФЕРЫ ==========
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', 0);
ini_set('output_buffering', 'off');

// Очищаем все буферы вывода
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Заголовки для SSE - КРИТИЧЕСКИ ВАЖНЫЙ ПОРЯДОК
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');  // Отключаем буферизацию nginx
header('Access-Control-Allow-Origin: *');
header('Content-Encoding: none');  // Отключаем сжатие

// Дополнительные заголовки для обхода прокси
header('Connection: keep-alive');
header('Keep-Alive: timeout=300');

ob_implicit_flush(true);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// ==================== ВСЕ ФУНКЦИИ ДОЛЖНЫ БЫТЬ ДО ОСНОВНОГО КОДА ====================

// Функция принудительного сброса буферов
function force_flush() {
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
    
    // Для FastCGI (например, PHP-FPM)
    if (function_exists('fastcgi_finish_request')) {
        // Не вызываем fastcgi_finish_request() полностью, 
        // так как это завершит запрос, а нам нужно продолжать отправку
        // Просто сбрасываем буферы
    }
}

/**
 * Получение количества непрочитанных сообщений
 */
function get_unread_count_sse($user_uuid, $is_admin, $db) {
    if ($is_admin) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE is_read = 0 AND user_uuid != ?");
        $stmt->bind_param("s", $user_uuid);
    } else {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT m.uuid) as cnt 
                              FROM messages m
                              JOIN tasks t ON m.task_uuid = t.uuid
                              JOIN projects p ON t.project_uuid = p.uuid
                              LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                              WHERE m.is_read = 0 AND m.user_uuid != ?
                              AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)");
        $stmt->bind_param("ssss", $user_uuid, $user_uuid, $user_uuid, $user_uuid);
    }
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

/**
 * Получение времени последнего сообщения в задаче
 */
function get_task_last_message_time($task_uuid, $db) {
    $stmt = $db->prepare("SELECT MAX(time) as max_time FROM messages WHERE task_uuid = ?");
    $stmt->bind_param("s", $task_uuid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $max_time = (int)($result['max_time'] ?? 0);
    $stmt->close();
    return $max_time;
}

/**
 * Проверка, подписан ли пользователь на задачу
 */
function is_subscribed_to_task($user_uuid, $task_uuid, $db) {
    $stmt = $db->prepare("SELECT 1 FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ? AND is_active = 1");
    $stmt->bind_param("ss", $task_uuid, $user_uuid);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// ==================== BLOCK START: get_new_messages_sse v2.5 (with subscription check) ====================
// ver.2.2 - Оптимизировано для shared-хостинга
// ver.2.3 - Добавлено поле project_title
// ver.2.4 - Добавлена поддержка reply_to (цитирование)
// ver.2.5 - ДОБАВЛЕНА проверка подписки перед возвратом сообщений
function get_new_messages_sse($user_uuid, $task_uuid, $last_time, $db) {
    $messages = [];
    
    // Проверка прав доступа
    $has_access = msgql_can_access_task($user_uuid, $task_uuid, 'view');
    $is_subscribed = is_subscribed_to_task($user_uuid, $task_uuid, $db);
    
    log_debug("[SSE_GET_MSG] User: {$user_uuid}, Task: {$task_uuid}, has_access: " . ($has_access ? 'true' : 'false') . ", is_subscribed: " . ($is_subscribed ? 'true' : 'false'));
    
    // v2.5: Проверяем подписку даже если нет доступа
    if (!$has_access && !$is_subscribed) {
        log_debug("[SSE_GET_MSG] No access and not subscribed, returning empty");
        return $messages;
    }
    
    // v2.4: Получаем название проекта
    $project_title = '';
    $proj_stmt = $db->prepare("SELECT p.title FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
    if ($proj_stmt) {
        $proj_stmt->bind_param("s", $task_uuid);
        $proj_stmt->execute();
        $proj_result = $proj_stmt->get_result()->fetch_assoc();
        if ($proj_result) {
            $project_title = $proj_result['title'];
        }
        $proj_stmt->close();
    }

    // v2.4: ОБНОВЛЕННЫЙ SQL: Добавлены JOIN для получения данных цитаты
    $sql = "SELECT 
        m.*, 
        u.name as user_name, 
        u.login as user_login, 
        t.title as task_title,
        reply.uuid as reply_uuid,
        reply.text as reply_text,
        reply.time as reply_time,
        reply_user.name as reply_user_name,
        reply_user.login as reply_user_login
    FROM messages m
    JOIN users u ON m.user_uuid = u.uuid
    JOIN tasks t ON m.task_uuid = t.uuid
    LEFT JOIN messages reply ON m.reply_to_uuid = reply.uuid
    LEFT JOIN users reply_user ON reply.user_uuid = reply_user.uuid
    WHERE m.task_uuid = ? AND m.time > ? AND m.user_uuid != ?  
    ORDER BY m.time ASC";

    $stmt = $db->prepare($sql);
    if (!$stmt) return $messages;
    $last_time_str = (string)$last_time;
    $stmt->bind_param("sss", $task_uuid, $last_time_str, $user_uuid);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $files = [];
        $fstmt = $db->prepare("SELECT f.* FROM files f JOIN message_files mf ON f.uuid = mf.file_uuid WHERE mf.message_uuid = ?");
        if ($fstmt) {
            $fstmt->bind_param("s", $row['uuid']);
            $fstmt->execute();
            $fres = $fstmt->get_result();
            while ($file = $fres->fetch_assoc()) {
                $files[] = [
                    'uuid' => $file['uuid'],
                    'name' => $file['orig_name'],
                    'size' => msgql_format_file_size($file['size_bytes']),
                    'size_bytes' => (int)$file['size_bytes'],
                    'mime' => $file['mime'],
                    'url' => "download.php?file={$file['uuid']}"
                ];
            }
            $fstmt->close();
        }

        $reply_to = null;
        if (!empty($row['reply_uuid'])) {
            $reply_to = [
                'uuid' => $row['reply_uuid'],
                'user_name' => $row['reply_user_name'] ?: $row['reply_user_login'],
                'text' => $row['reply_text'],
                'time' => (int)$row['reply_time']
            ];
        }

        $messages[] = [
            'uuid' => $row['uuid'],
            'task_uuid' => $row['task_uuid'],
            'task_title' => $row['task_title'],
            'text' => $row['text'],
            'time' => (int)$row['time'],
            'stamp' => $row['stamp'],
            'user_uuid' => $row['user_uuid'],
            'user_name' => $row['user_name'] ?: $row['user_login'],
            'is_read' => (int)$row['is_read'],
            'reply_to_uuid' => $row['reply_to_uuid'],
            'reply_to' => $reply_to,
            'files' => $files
        ];
    }
    
    $stmt->close();
    
    log_debug("[SSE_GET_MSG] Returning " . count($messages) . " messages for task {$task_uuid}");
    
    return [
        'messages' => $messages,
        'project_title' => $project_title
    ];
}
// ==================== BLOCK END: get_new_messages_sse v2.5 ====================

/**
 * Получение всех новых сообщений пользователя (без привязки к конкретной задаче)
 * Используется когда task_uuid не передан (пользователь на другой странице)
 * v2.5: добавлена поддержка глобальных уведомлений
 */
/**
 * Получение всех новых сообщений пользователя (без привязки к конкретной задаче)
 * Используется когда task_uuid не передан (пользователь на другой странице)
 * v2.5: добавлена поддержка глобальных уведомлений
 * ИСПРАВЛЕНО: при last_time = 0 не отправляем старые сообщения
 */
function get_all_new_messages_sse($user_uuid, $last_time, $is_admin, $db) {
    // 🔥 ИСПРАВЛЕНИЕ: при первом подключении (last_time = 0) не отправляем старые сообщения
    if ($last_time == 0) {
        log_debug("[SSE_MESSAGES] Skipping old messages (last_time=0)");
        return [];
    }
    
    $messages_by_task = [];
    
    if ($is_admin) {
        $sql = "SELECT DISTINCT m.*, u.name as user_name, u.login as user_login, t.title as task_title, p.title as project_title
                FROM messages m
                JOIN users u ON m.user_uuid = u.uuid
                JOIN tasks t ON m.task_uuid = t.uuid
                JOIN projects p ON t.project_uuid = p.uuid
                WHERE m.time > ? AND m.user_uuid != ? AND m.is_read = 0
                ORDER BY m.time ASC
                LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $last_time, $user_uuid);
    } else {
        $sql = "SELECT DISTINCT m.*, u.name as user_name, u.login as user_login, t.title as task_title, p.title as project_title
                FROM messages m
                JOIN users u ON m.user_uuid = u.uuid
                JOIN tasks t ON m.task_uuid = t.uuid
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE m.time > ? AND m.user_uuid != ? AND m.is_read = 0
                AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
                ORDER BY m.time ASC
                LIMIT 50";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $user_uuid, $last_time, $user_uuid, $user_uuid, $user_uuid);
    }
    
    if (!$stmt) return [];
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $task_uuid = $row['task_uuid'];
        
        if (!isset($messages_by_task[$task_uuid])) {
            $messages_by_task[$task_uuid] = [
                'task_uuid' => $task_uuid,
                'task_title' => $row['task_title'],
                'project_title' => $row['project_title'],
                'messages' => []
            ];
        }
        
        $files = [];
        $fstmt = $db->prepare("SELECT f.* FROM files f JOIN message_files mf ON f.uuid = mf.file_uuid WHERE mf.message_uuid = ?");
        if ($fstmt) {
            $fstmt->bind_param("s", $row['uuid']);
            $fstmt->execute();
            $fres = $fstmt->get_result();
            while ($file = $fres->fetch_assoc()) {
                $files[] = [
                    'uuid' => $file['uuid'],
                    'name' => $file['orig_name'],
                    'size' => msgql_format_file_size($file['size_bytes']),
                    'size_bytes' => (int)$file['size_bytes'],
                    'mime' => $file['mime'],
                    'url' => "download.php?file={$file['uuid']}"
                ];
            }
            $fstmt->close();
        }
        
        $reply_to = null;
        if (!empty($row['reply_uuid'])) {
            $reply_stmt = $db->prepare("SELECT text, user_uuid FROM messages WHERE uuid = ?");
            if ($reply_stmt) {
                $reply_stmt->bind_param("s", $row['reply_uuid']);
                $reply_stmt->execute();
                $reply_row = $reply_stmt->get_result()->fetch_assoc();
                if ($reply_row) {
                    $reply_user_stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                    $reply_user_stmt->bind_param("s", $reply_row['user_uuid']);
                    $reply_user_stmt->execute();
                    $reply_user = $reply_user_stmt->get_result()->fetch_assoc();
                    $reply_to = [
                        'uuid' => $row['reply_uuid'],
                        'user_name' => ($reply_user['name'] ?? $reply_user['login'] ?? 'Пользователь'),
                        'text' => $reply_row['text'],
                        'time' => (int)($row['reply_time'] ?? 0)
                    ];
                    $reply_user_stmt->close();
                }
                $reply_stmt->close();
            }
        }
        
        $messages_by_task[$task_uuid]['messages'][] = [
            'uuid' => $row['uuid'],
            'task_uuid' => $row['task_uuid'],
            'task_title' => $row['task_title'],
            'text' => $row['text'],
            'time' => (int)$row['time'],
            'stamp' => $row['stamp'],
            'user_uuid' => $row['user_uuid'],
            'user_name' => $row['user_name'] ?: $row['user_login'],
            'is_read' => (int)$row['is_read'],
            'reply_to_uuid' => $row['reply_to_uuid'],
            'reply_to' => $reply_to,
            'files' => $files
        ];
    }
    
    $stmt->close();
    return $messages_by_task;
}

/**
 * Получение необработанных событий из очереди
 */
function get_pending_queue_events($task_uuid, $db) {
    $events = [];
    
    $check = $db->query("SHOW TABLES LIKE 'sse_queue'");
    if ($check->num_rows == 0) {
        return $events;
    }
    
    $stmt = $db->prepare("
        SELECT uuid, event_type, event_data, created_at 
        FROM sse_queue 
        WHERE task_uuid = ? AND processed = 0 
        ORDER BY created_at ASC 
        LIMIT 20
    ");
    if ($stmt) {
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();
    }
    
    return $events;
}

/**
 * Пометить событие как обработанное
 */
function mark_queue_event_processed($event_uuid, $db) {
    $stmt = $db->prepare("UPDATE sse_queue SET processed = 1 WHERE uuid = ?");
    if ($stmt) {
        $stmt->bind_param("s", $event_uuid);
        $stmt->execute();
        $stmt->close();
    }
}

// sse.php - Версия 2.3
// ver.2.2 - Оптимизировано для shared-хостинга, добавлены принудительные flush()
// ver.2.3 - ДОБАВЛЕНО: поле project_title в new_task для отображения в тостах

// ========== ОСТАЛЬНОЙ КОД БЕЗ ИЗМЕНЕНИЙ ==========

/**
 * Получение новых задач (только для пользователя - назначенных на него)
 */
/**
 * Получение новых задач (только для пользователя - назначенных на него)
 * ИСПРАВЛЕНО: при last_time = 0 не отправляем старые задачи
 */
function get_new_tasks_sse($user_uuid, $last_time, $is_admin, $db) {
    // 🔥 ИСПРАВЛЕНИЕ: при первом подключении (last_time = 0) не отправляем старые задачи
    if ($last_time == 0) {
        log_debug("[SSE_TASKS] Skipping old tasks (last_time=0)");
        return [];
    }
    
    $tasks = [];
    
    $sql = "SELECT t.*, p.title as project_title, u.name as creator_name
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN users u ON t.user_uuid = u.uuid
            WHERE t.time > ?
            AND t.user_uuid != ?
            AND t.assigned_to_uuid = ?
            ORDER BY t.time ASC
            LIMIT 10";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return $tasks;
    $last_time_str = (string)$last_time;
    $stmt->bind_param("sss", $last_time_str, $user_uuid, $user_uuid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as $row) {
        $tasks[] = [
            'uuid' => $row['uuid'],
            'title' => $row['title'],
            'descr' => $row['descr'],
            'project_title' => $row['project_title'],
            'project_uuid' => $row['project_uuid'],
            'assigned_to_uuid' => $row['assigned_to_uuid'],
            'creator_name' => $row['creator_name'] ?? 'Система',
            'time' => (int)$row['time'],
            'stamp' => $row['stamp'],
            'user_uuid' => $row['user_uuid'],
            'assigned_to_uuid' => $row['assigned_to_uuid']
        ];
    }
    
    return $tasks;
}


/**
 * Получение новых файлов (только для задач пользователя)
 */
/**
 * Получение новых файлов (только для задач пользователя)
 * ИСПРАВЛЕНО: при last_time = 0 не отправляем старые файлы
 */
function get_new_files_sse($user_uuid, $last_time, $is_admin, $db) {
    // 🔥 ИСПРАВЛЕНИЕ: при первом подключении (last_time = 0) не отправляем старые файлы
    if ($last_time == 0) {
        //log_debug("[SSE_FILES] Skipping old files (last_time=0)");
        return [];
    }
    
    $files = [];
    
    $sql = "SELECT DISTINCT f.*, t.uuid as task_uuid, t.title as task_title,
                   p.title as project_title, u.name as uploader_name
            FROM files f
            JOIN task_files tf ON f.uuid = tf.file_uuid
            JOIN tasks t ON tf.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
            WHERE f.time > ?
            AND f.uploaded_by_uuid != ?
            AND t.assigned_to_uuid = ?
            ORDER BY f.time ASC
            LIMIT 10";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return $files;
    $last_time_str = (string)$last_time;
    $stmt->bind_param("sss", $last_time_str, $user_uuid, $user_uuid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as $row) {
        $files[] = [
            'uuid' => $row['uuid'],
            'orig_name' => $row['orig_name'],
            'size_bytes' => (int)$row['size_bytes'],
            'mime' => $row['mime'],
            'task_uuid' => $row['task_uuid'],
            'task_title' => $row['task_title'],
            'project_title' => $row['project_title'],
            'uploader_name' => $row['uploader_name'] ?? 'Система',
            'uploaded_by_uuid' => $row['uploaded_by_uuid'],
            'time' => (int)$row['time'],
            'stamp' => $row['stamp']
        ];
    }
    
    return $files;
}

/**
 * Получение просроченных задач (только назначенных на пользователя)
 */
/**
 * Получение просроченных задач (только назначенных на пользователя)
 * ИСПРАВЛЕНО: при last_time = 0 не отправляем старые просроченные задачи
 */
function get_overdue_tasks_sse($user_uuid, $is_admin, $db) {
    global $last_time;
    
    // 🔥 ИСПРАВЛЕНИЕ: при первом подключении (last_time = 0) не отправляем старые просрочки
    if ($last_time == 0) {
        log_debug("[SSE_OVERDUE] Skipping old overdue tasks (last_time=0)");
        return [];
    }
    
    $now = msgql_now_ms();
    $tasks = [];
    
    $sql = "SELECT t.*, p.title as project_title, u.name as assignee_name
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
            WHERE t.assigned_to_uuid = ?
            AND t.status = 0
            AND t.time_end_plan IS NOT NULL
            AND t.time_end_plan > 0
            AND t.time_end_plan < ?
            AND t.time_end_plan > ?  -- 🔥 ДОБАВЛЕНО: только просрочки после last_time
            ORDER BY t.time_end_plan ASC
            LIMIT 20";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return $tasks;
    
    $stmt->bind_param("sii", $user_uuid, $now, $last_time);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as $row) {
        $tasks[] = [
            'uuid' => $row['uuid'],
            'title' => $row['title'],
            'descr' => $row['descr'],
            'project_title' => $row['project_title'],
            'project_uuid' => $row['project_uuid'],
            'assignee_name' => $row['assignee_name'] ?? 'Не назначен',
            'assigned_to_uuid' => $row['assigned_to_uuid'],
            'deadline' => (int)$row['time_end_plan'],
            'time' => (int)$row['time'],
            'stamp' => $row['stamp']
        ];
    }
    
    return $tasks;
}

/**
 * Получение названия задачи
 */
function get_task_title($task_uuid, $db) {
    $stmt = $db->prepare("SELECT title FROM tasks WHERE uuid = ?");
    $stmt->bind_param("s", $task_uuid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['title'] ?? 'Задача';
}

// ==================== ОСНОВНОЙ КОД ====================

// ========== НАЧАЛО БЛОКА ЗАМЕНЫ: получение параметров (v2.5 - удалён since_time, добавлено логирование путей) ==========
// Проверяем авторизацию
if (!msgql_is_logged_in()) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    force_flush();
    exit;
}

$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();
$db = msgql_db();

// 🔥 v2.5: Логирование параметров окружения для отладки
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'unknown';
$request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
$script_name = $_SERVER['SCRIPT_NAME'] ?? 'unknown';

log_debug("[SSE_ENV] Protocol: {$protocol}, Host: {$host}");
log_debug("[SSE_ENV] Request URI: {$request_uri}");
log_debug("[SSE_ENV] Script name: {$script_name}");
log_debug("[SSE_ENV] Full URL: {$protocol}://{$host}{$request_uri}");

// 🔥 Закрываем сессию и ОСВОБОЖДАЕМ БЛОКИРОВКУ ДО начала цикла
session_write_close();

// Параметры клиента
$last_event_id = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;
$last_time = isset($_GET['last_time']) ? (int)$_GET['last_time'] : $last_event_id;
$task_uuid = isset($_GET['task_uuid']) ? $_GET['task_uuid'] : null;

// 🔥 ИСПРАВЛЕНИЕ: если last_time == 0, устанавливаем в текущее время
if ($last_time == 0) {
    $last_time = msgql_now_ms();
    log_debug("[SSE] Initialized last_time to current time: " . $last_time);
}

// 🔥 v2.5: Удалён параметр since_time — он вызывал проблемы с дублированием сообщений
log_debug("[SSE] Parameters: task_uuid={$task_uuid}, last_time={$last_time}, last_event_id={$last_event_id}");
log_debug("[SSE] NOTE: since_time parameter is no longer used (removed in v2.5)");

$last_time_str = (string)$last_time;

// Переменные состояния
$last_overdue_check = 0;
$last_unread_count = -1;
$last_task_time = $last_time;
$last_task_check = 0;
$last_file_time = 0;
$last_file_check = 0;
$last_overdue_ids = [];
$last_queue_check = 0;
// ========== КОНЕЦ БЛОКА ЗАМЕНЫ ==========

// Отправляем приветственное сообщение
echo "event: connected\n";
echo "data: " . json_encode([
    'status' => 'ok', 
    'user_uuid' => $current_user_uuid, 
    'timestamp' => msgql_now_ms(),
    'task_watching' => $task_uuid,
    'last_time' => $last_time
]) . "\n\n";
force_flush();

$last_ping_sent = time();
$ping_interval = 15;
$max_lifetime = 600; // 10 минут максимум
$start_time = time();
$max_iterations = 17280;
$sleep_time = 5000000; // 5 секунд

// Логируем запуск SSE (для отладки)
//log_debug("[SSE] Started for user {$current_user_uuid}, task: " . ($task_uuid ?? 'none'));

// ==================== ОСНОВНОЙ ЦИКЛ С ОГРАНИЧЕНИЯМИ ====================

// Максимальное количество событий за одну итерацию
$MAX_EVENTS_PER_BATCH = 10;

for ($i = 0; $i < $max_iterations; $i++) {
    $events_sent_in_batch = 0;
    
    // Проверяем лимит времени жизни
    if (time() - $start_time > $max_lifetime) {
        echo "event: reconnect\n";
        echo "data: " . json_encode(['reason' => 'max_lifetime', 'reconnect_ms' => 500]) . "\n\n";
        force_flush();
        break;
    }
    
    $now = msgql_now_ms();
    $current_time = time();
    
    // PING каждые 15 секунд
    if ($current_time - $last_ping_sent >= $ping_interval) {
        echo "event: ping\n";
        echo "data: " . json_encode(['timestamp' => $now, 'ping_id' => $i]) . "\n\n";
        force_flush();
        $last_ping_sent = $current_time;
        
        if (connection_aborted()) break;
    }
    

    // 1. НЕПРОЧИТАННЫЕ СООБЩЕНИЯ (всегда отправляем)
    $unread_count = get_unread_count_sse($current_user_uuid, $is_admin, $db);
    if ($unread_count !== $last_unread_count) {
        // 🔥 ДОБАВЛЯЕМ: получаем UUID последнего непрочитанного сообщения
        $last_message_uuid = null;
        $last_message_task_uuid = null;
        
        if ($unread_count > 0) {
            // Получаем самое новое непрочитанное сообщение
            if ($is_admin) {
                $msg_stmt = $db->prepare("SELECT uuid, task_uuid FROM messages WHERE is_read = 0 AND user_uuid != ? ORDER BY time DESC LIMIT 1");
                $msg_stmt->bind_param("s", $current_user_uuid);
            } else {
                $msg_stmt = $db->prepare("
                    SELECT m.uuid, m.task_uuid 
                    FROM messages m
                    JOIN tasks t ON m.task_uuid = t.uuid
                    JOIN projects p ON t.project_uuid = p.uuid
                    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                    WHERE m.is_read = 0 AND m.user_uuid != ?
                    AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
                    ORDER BY m.time DESC LIMIT 1
                ");
                $msg_stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
            }
            
            if ($msg_stmt) {
                $msg_stmt->execute();
                $last_msg = $msg_stmt->get_result()->fetch_assoc();
                if ($last_msg) {
                    $last_message_uuid = $last_msg['uuid'];
                    $last_message_task_uuid = $last_msg['task_uuid'];
                }
                $msg_stmt->close();
            }
        }
        
        echo "event: unread_update\n";
        echo "data: " . json_encode([
            'count' => $unread_count, 
            'timestamp' => $now,
            'last_message_uuid' => $last_message_uuid,
            'task_uuid' => $last_message_task_uuid
        ]) . "\n\n";
        force_flush();
        $last_unread_count = $unread_count;
        $events_sent_in_batch++;
        
        if (connection_aborted()) break;
    }
    
    // 2. НОВЫЕ СООБЩЕНИЯ (ГЛОБАЛЬНО ИЛИ В ТЕКУЩЕЙ ЗАДАЧЕ) - только если last_time > 0
    // ==================== BLOCK START: SSE Main Loop New Messages v2.5 (with subscription filter) ====================
    // ver.2.0 - Базовая версия
    // ver.2.5 - ДОБАВЛЕНА фильтрация по подписке перед отправкой сообщений
    if ($last_time > 0) {
        if ($task_uuid && !empty($task_uuid)) {
            $current_max = get_task_last_message_time($task_uuid, $db);
            
            if ($current_max > $last_time && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
                // v2.5: Проверяем подписку перед отправкой сообщений
                $is_subscribed = is_subscribed_to_task($current_user_uuid, $task_uuid, $db);
                $has_access = msgql_can_access_task($current_user_uuid, $task_uuid, 'view');
                
                $should_send = $has_access || $is_subscribed;
                
                log_debug("[SSE_LOOP] Task {$task_uuid} - has_access: " . ($has_access ? 'true' : 'false') . 
                          ", is_subscribed: " . ($is_subscribed ? 'true' : 'false') . 
                          ", should_send: " . ($should_send ? 'true' : 'false'));
                
                if (!$should_send) {
                    log_debug("[SSE_LOOP] User {$current_user_uuid} not subscribed to task {$task_uuid}, skipping messages");
                    // Обновляем last_time чтобы не запрашивать снова
                    $last_time = $current_max;
                    $last_task_time = $current_max;
                } else {
                    $result_data = get_new_messages_sse($current_user_uuid, $task_uuid, $last_time, $db);
                    
                    if (isset($result_data['messages'])) {
                        $messages = $result_data['messages'];
                        $project_title = $result_data['project_title'];
                    } else {
                        $messages = $result_data;
                        $project_title = '';
                    }
                    
                    if (!empty($messages)) {
                        $new_max_time = $last_time;
                        foreach ($messages as $msg) {
                            if ($msg['time'] > $new_max_time) {
                                $new_max_time = $msg['time'];
                            }
                        }
                        
                        $task_title = get_task_title($task_uuid, $db);
                        
                        echo "event: new_messages\n";
                        echo "data: " . json_encode([
                            'messages' => $messages,
                            'new_time' => $new_max_time,
                            'task_uuid' => $task_uuid,
                            'task_title' => $task_title,
                            'project_title' => $project_title
                        ], JSON_UNESCAPED_UNICODE) . "\n\n";
                        force_flush();
                        $last_time = $new_max_time;
                        $last_task_time = $new_max_time;
                        $events_sent_in_batch++;
                        
                        log_debug("[SSE_LOOP] Sent " . count($messages) . " messages for task {$task_uuid}");
                        
                        if (connection_aborted()) break;
                    }
                }
            }
        } else {
            // Глобальный режим (без task_uuid) - отправляем все новые сообщения
            $all_new_messages = get_all_new_messages_sse($current_user_uuid, $last_time, $is_admin, $db);
            
            if (!empty($all_new_messages) && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
                $global_max_time = $last_time;
                $messages_sent = 0;
                
                foreach ($all_new_messages as $task_uuid_key => $task_data) {
                    if ($messages_sent >= 5) break;
                    
                    // Для глобального режима тоже проверяем подписку
                    $is_subscribed = is_subscribed_to_task($current_user_uuid, $task_uuid_key, $db);
                    $has_access = msgql_can_access_task($current_user_uuid, $task_uuid_key, 'view');
                    
                    if (!$has_access && !$is_subscribed) {
                        log_debug("[SSE_LOOP] Global mode: skipping task {$task_uuid_key} (no access, no subscription)");
                        continue;
                    }
                    
                    $task_messages = $task_data['messages'];
                    $project_title = $task_data['project_title'];
                    $task_title = $task_data['task_title'];
                    
                    if (!empty($task_messages)) {
                        $new_max_time = $last_time;
                        foreach ($task_messages as $msg) {
                            if ($msg['time'] > $new_max_time) {
                                $new_max_time = $msg['time'];
                            }
                            if ($msg['time'] > $global_max_time) {
                                $global_max_time = $msg['time'];
                            }
                        }
                        
                        echo "event: new_messages\n";
                        echo "data: " . json_encode([
                            'messages' => $task_messages,
                            'new_time' => $new_max_time,
                            'task_uuid' => $task_uuid_key,
                            'task_title' => $task_title,
                            'project_title' => $project_title
                        ], JSON_UNESCAPED_UNICODE) . "\n\n";
                        force_flush();
                        $messages_sent++;
                        $events_sent_in_batch++;
                    }
                }
                
                if ($global_max_time > $last_time) {
                    $last_time = $global_max_time;
                    $last_task_time = $global_max_time;
                }
                
                if (connection_aborted()) break;
            }
        }
    }
    // ==================== BLOCK END: SSE Main Loop New Messages v2.5 ====================
    
    // 3. НОВЫЕ ЗАДАЧИ (раз в 60 секунд) - только если last_time > 0
    if ($last_time > 0 && $current_time - $last_task_check >= 60 && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
        $new_tasks = get_new_tasks_sse($current_user_uuid, $last_task_time, $is_admin, $db);
        
        if (!empty($new_tasks)) {
            $tasks_sent = 0;
            foreach ($new_tasks as $task) {
                if ($tasks_sent >= 5) break; // Ограничение: не более 5 задач за раз
                
                echo "event: new_task\n";
                echo "data: " . json_encode([
                    'task' => $task,
                    'project_title' => $task['project_title'] ?? '',
                    'timestamp' => $now
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                force_flush();
                $tasks_sent++;
                $events_sent_in_batch++;
                
                if ($task['time'] > $last_task_time) {
                    $last_task_time = $task['time'];
                }
            }
        }
        $last_task_check = $current_time;
        
        if (connection_aborted()) break;
    }
    
    // 4. НОВЫЕ ФАЙЛЫ (раз в 60 секунд) - только если last_time > 0
    if ($last_time > 0 && $current_time - $last_file_check >= 60 && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
        $new_files = get_new_files_sse($current_user_uuid, $last_file_time, $is_admin, $db);
        
        if (!empty($new_files)) {
            $files_sent = 0;
            foreach ($new_files as $file) {
                if ($files_sent >= 5) break; // Ограничение: не более 5 файлов за раз
                
                echo "event: new_file\n";
                echo "data: " . json_encode([
                    'file' => $file,
                    'task_title' => $file['task_title'] ?? '',
                    'timestamp' => $now
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                force_flush();
                $files_sent++;
                $events_sent_in_batch++;
                
                if ($file['time'] > $last_file_time) {
                    $last_file_time = $file['time'];
                }
            }
        }
        $last_file_check = $current_time;
        
        if (connection_aborted()) break;
    }
    
    // 5. ПРОСРОЧЕННЫЕ ЗАДАЧИ (раз в 5 минут) - только если last_time > 0
    if ($last_time > 0 && $current_time - $last_overdue_check >= 300 && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
        $overdue_tasks = get_overdue_tasks_sse($current_user_uuid, $is_admin, $db);
        $current_overdue_ids = [];
        $overdue_sent = 0;
        
        foreach ($overdue_tasks as $task) {
            if ($overdue_sent >= 5) break; // Ограничение: не более 5 просрочек за раз
            
            $current_overdue_ids[] = $task['uuid'];
            
            if (!in_array($task['uuid'], $last_overdue_ids)) {
                echo "event: overdue_task\n";
                echo "data: " . json_encode([
                    'task' => $task,
                    'project_title' => $task['project_title'] ?? '',
                    'timestamp' => $now
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                force_flush();
                $overdue_sent++;
                $events_sent_in_batch++;
            }
        }
        
        $last_overdue_ids = $current_overdue_ids;
        $last_overdue_check = $current_time;
        
        if (connection_aborted()) break;
    }
    
    // 6. СОБЫТИЯ ИЗ ОЧЕРЕДИ (раз в 2 секунды) - с ограничением
    if ($task_uuid && !empty($task_uuid) && ($current_time - $last_queue_check >= 2) && $events_sent_in_batch < $MAX_EVENTS_PER_BATCH) {
        $events = get_pending_queue_events($task_uuid, $db);
        $queue_sent = 0;
        
        if (count($events) > 0) {
            log_debug("[SSE] Found " . count($events) . " pending events for task {$task_uuid}");
        }
        
        foreach ($events as $event) {
            if ($queue_sent >= 5) break; // Ограничение: не более 5 событий из очереди за раз
            
            log_debug("[SSE] Sending event: " . $event['event_type'] . " for task {$task_uuid}");
            echo "event: " . $event['event_type'] . "\n";
            echo "data: " . $event['event_data'] . "\n\n";
            force_flush();
            
            mark_queue_event_processed($event['uuid'], $db);
            $queue_sent++;
            $events_sent_in_batch++;
        }
        $last_queue_check = $current_time;
        
        if (connection_aborted()) break;
    }
    
    // Если отправили много событий за раз, делаем паузу
    if ($events_sent_in_batch >= 5) {
        usleep(1000000); // 1 секунда паузы
    }
    
    usleep($sleep_time);
}

// 🔥 Завершение: шлём reconnect и закрываем
echo "event: reconnect\n";
echo "data: " . json_encode(['reason' => 'timeout', 'reconnect_ms' => 1000]) . "\n\n";
force_flush();

//log_debug("[SSE] Connection closed for user {$current_user_uuid}, task: " . ($task_uuid ?? 'none'));

// Для FastCGI - финализируем запрос
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
exit;