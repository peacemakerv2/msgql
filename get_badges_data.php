<?php
// ==================== BLOCK START: get_badges_data.php v2.0 (with subscription check) ====================
// ver.1.0 (2026-05-26) - НОВЫЙ API-эндпоинт для получения отдельных счётчиков бейджей
// ver.2.0 (2026-05-29) - ДОБАВЛЕНА проверка подписок: бейджи считаются ТОЛЬКО для задач,
//                        на которые пользователь ПОДПИСАН (is_active = 1)
// - messages: непрочитанные сообщения (только из подписанных задач)
// - projects: новые задачи (только если пользователь подписан)
// - files: новые файлы (только если пользователь подписан на задачу)
// - notifications: системные уведомления (для колокольчика)

define('AJAX_REQUEST', true);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!msgql_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();

log_debug("[BADGES_API] Fetching badges for user: {$current_user_uuid}");

$stmt = $db->prepare("SELECT time_last_dashboard_view FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$last_view = $user['time_last_dashboard_view'] ?? 0;
$now = msgql_now_ms();
$since_time = ($last_view > 0) ? $last_view : ($now - 7 * 24 * 60 * 60 * 1000);
$since_time_str = (string)$since_time;

log_debug("[BADGES_API] last_view: {$last_view}, since_time: {$since_time}");

// ==================== ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ПРОВЕРКИ ПОДПИСКИ ====================
function is_subscribed_to_task_badge($user_uuid, $task_uuid, $db) {
    $stmt = $db->prepare("SELECT 1 FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ? AND is_active = 1");
    if (!$stmt) {
        log_debug("[BADGES_SUBSCRIBE] Prepare failed: " . $db->error);
        return false;
    }
    $stmt->bind_param("ss", $task_uuid, $user_uuid);
    $stmt->execute();
    $is_subscribed = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $is_subscribed;
}

// ========== 1. НЕПРОЧИТАННЫЕ СООБЩЕНИЯ (с учётом подписок) ==========
$unread_messages = 0;

if ($is_admin) {
    $stmt = $db->prepare("
        SELECT m.uuid, m.task_uuid 
        FROM messages m 
        WHERE m.is_read = 0 AND m.user_uuid != ?
    ");
    $stmt->bind_param("s", $current_user_uuid);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT m.uuid, m.task_uuid
        FROM messages m
        JOIN tasks t ON m.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE m.is_read = 0 AND m.user_uuid != ?
        AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
    ");
    $stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $task_uuid = $row['task_uuid'];
    $is_subscribed = is_subscribed_to_task_badge($current_user_uuid, $task_uuid, $db);
    $has_access = msgql_can_access_task($current_user_uuid, $task_uuid, 'view');
    
    if ($has_access || $is_subscribed) {
        $unread_messages++;
    }
}
$stmt->close();

log_debug("[BADGES_API] Unread messages (filtered by subscription): {$unread_messages}");

// ========== 2. НОВЫЕ ЗАДАЧИ (только если пользователь подписан) ==========
$new_tasks = 0;

if ($is_admin) {
    $stmt = $db->prepare("
        SELECT t.uuid, t.time
        FROM tasks t
        WHERE t.time > ? AND t.user_uuid != ?
    ");
    $stmt->bind_param("ss", $since_time_str, $current_user_uuid);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT t.uuid, t.time
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE t.time > ? AND t.user_uuid != ?
        AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
        AND p.created_by_uuid != ?
    ");
    $stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $task_uuid = $row['uuid'];
    $is_subscribed = is_subscribed_to_task_badge($current_user_uuid, $task_uuid, $db);
    
    if ($is_subscribed) {
        $new_tasks++;
    }
}
$stmt->close();

log_debug("[BADGES_API] New tasks (filtered by subscription): {$new_tasks}");

// ========== 3. НОВЫЕ ФАЙЛЫ (только если пользователь подписан на задачу) ==========
$new_files = 0;

if ($is_admin) {
    $stmt = $db->prepare("
        SELECT f.uuid, tf.task_uuid
        FROM files f
        JOIN task_files tf ON f.uuid = tf.file_uuid
        WHERE f.time > ? AND f.uploaded_by_uuid != ?
    ");
    $stmt->bind_param("ss", $since_time_str, $current_user_uuid);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT f.uuid, tf.task_uuid
        FROM files f
        JOIN task_files tf ON f.uuid = tf.file_uuid
        JOIN tasks t ON tf.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE f.time > ? AND f.uploaded_by_uuid != ?
        AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
        AND p.created_by_uuid != ?
    ");
    $stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $task_uuid = $row['task_uuid'];
    $is_subscribed = is_subscribed_to_task_badge($current_user_uuid, $task_uuid, $db);
    
    if ($is_subscribed) {
        $new_files++;
    }
}
$stmt->close();

log_debug("[BADGES_API] New files (filtered by subscription): {$new_files}");

// ========== 4. СИСТЕМНЫЕ УВЕДОМЛЕНИЯ (для колокольчика) ==========
$notifications_count = 0;
if (class_exists('NotificationCenter')) {
    $notifications_count = NotificationCenter::getUnreadCount($current_user_uuid);
}
log_debug("[BADGES_API] System notifications: {$notifications_count}");

echo json_encode([
    'success' => true,
    'badges' => [
        'messages' => $unread_messages,
        'projects' => $new_tasks,
        'files' => $new_files,
        'notifications' => $notifications_count
    ],
    'timestamp' => $now
], JSON_UNESCAPED_UNICODE);
exit;
// ==================== BLOCK END: get_badges_data.php v2.0 ====================