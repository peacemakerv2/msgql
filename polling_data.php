<?php
// polling_data.php version 2.1: Лёгкий эндпоинт для синхронизации бейджа (не для постоянного опроса)
// Добавлена CSRF-защита для мутирующих действий (mark_messages_read)
// v2.1 - ИСПРАВЛЕНИЕ: полная CSRF-проверка для всех мутирующих действий, добавлено логирование

define('AJAX_REQUEST', true);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!msgql_is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();
$db = msgql_db();

session_write_close();

$action = isset($_POST['action']) ? $_POST['action'] : '';

// ==================== CSRF-ПРОВЕРКА ДЛЯ МУТИРУЮЩИХ ДЕЙСТВИЙ ====================
$mutating_actions = ['mark_messages_read'];

// ========== V-HIGH-01 FIX: Полная CSRF-проверка ==========
if (in_array($action, $mutating_actions)) {
    log_debug("[POLLING_CSRF] Checking CSRF for action: {$action}");
    
    // Проверяем наличие CSRF-токена
    if (!isset($_POST['csrf_token']) && !isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        log_warning("[POLLING_CSRF] CSRF token missing for action: {$action}");
        echo json_encode([
            'success' => false, 
            'error' => 'CSRF token missing',
            'csrf_error' => true
        ]);
        exit;
    }
    
    msgql_csrf_check_and_exit();
    log_debug("[POLLING_CSRF] CSRF validation passed for action: {$action}");
}


// ==================== BLOCK START: Polling Data with Badges Support v2.2 ====================
// ver.2.2 - Добавлена поддержка получения всех типов бейджей
// - get_all_badges: новый action для получения всех счётчиков

if ($action === 'get_all_badges') {
    log_debug("[POLLING] get_all_badges called for user: {$current_user_uuid}");
    
    $last_view_stmt = $db->prepare("SELECT time_last_dashboard_view FROM users WHERE uuid = ?");
    $last_view_stmt->bind_param("s", $current_user_uuid);
    $last_view_stmt->execute();
    $user_data = $last_view_stmt->get_result()->fetch_assoc();
    $last_view_stmt->close();
    
    $last_view = $user_data['time_last_dashboard_view'] ?? 0;
    $now_ms = msgql_now_ms();
    $since_time = ($last_view > 0) ? $last_view : ($now_ms - 7 * 24 * 60 * 60 * 1000);
    $since_time_str = (string)$since_time;
    
    // Непрочитанные сообщения
    if ($is_admin) {
        $msg_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE is_read = 0 AND user_uuid != ?");
        $msg_stmt->bind_param("s", $current_user_uuid);
    } else {
        $msg_stmt = $db->prepare("SELECT COUNT(DISTINCT m.uuid) as cnt 
            FROM messages m
            JOIN tasks t ON m.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE m.is_read = 0 AND m.user_uuid != ?
            AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)");
        $msg_stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $msg_stmt->execute();
    $unread_messages = (int)$msg_stmt->get_result()->fetch_assoc()['cnt'];
    $msg_stmt->close();
    
    // Новые задачи
    if ($is_admin) {
        $tasks_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM tasks t WHERE t.time > ? AND t.user_uuid != ?");
        $tasks_stmt->bind_param("ss", $since_time_str, $current_user_uuid);
    } else {
        $tasks_stmt = $db->prepare("SELECT COUNT(DISTINCT t.uuid) as cnt 
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE t.time > ? AND t.user_uuid != ?
            AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
            AND p.created_by_uuid != ?");
        $tasks_stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $tasks_stmt->execute();
    $new_tasks = (int)$tasks_stmt->get_result()->fetch_assoc()['cnt'];
    $tasks_stmt->close();
    
    // Новые файлы
    if ($is_admin) {
        $files_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM files f WHERE f.time > ? AND f.uploaded_by_uuid != ?");
        $files_stmt->bind_param("ss", $since_time_str, $current_user_uuid);
    } else {
        $files_stmt = $db->prepare("SELECT COUNT(DISTINCT f.uuid) as cnt 
            FROM files f
            JOIN task_files tf ON f.uuid = tf.file_uuid
            JOIN tasks t ON tf.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE f.time > ? AND f.uploaded_by_uuid != ?
            AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
            AND p.created_by_uuid != ?");
        $files_stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $files_stmt->execute();
    $new_files = (int)$files_stmt->get_result()->fetch_assoc()['cnt'];
    $files_stmt->close();
    
    // Системные уведомления
    $notifications_count = 0;
    if (class_exists('NotificationCenter')) {
        $notifications_count = NotificationCenter::getUnreadCount($current_user_uuid);
    }
    
    log_debug("[POLLING] Badges response - messages: {$unread_messages}, tasks: {$new_tasks}, files: {$new_files}, notifications: {$notifications_count}");
    
    echo json_encode([
        'success' => true,
        'badges' => [
            'messages' => $unread_messages,
            'projects' => $new_tasks,
            'files' => $new_files,
            'notifications' => $notifications_count
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// ==================== BLOCK END: Polling Data with Badges Support v2.2 ====================

// ==================== mark_messages_read (массовая отметка) с CSRF-защитой ====================
if ($action === 'mark_messages_read') {
    $task_uuid = $_POST['task_uuid'] ?? '';
    $message_uuids = isset($_POST['message_uuids']) && is_array($_POST['message_uuids']) ? $_POST['message_uuids'] : [];
    
    log_debug("[POLLING_MARK] mark_messages_read called for task: {$task_uuid}, messages: " . count($message_uuids));
    
    if (empty($task_uuid) || empty($message_uuids)) {
        echo json_encode(['success' => false, 'error' => 'Не указаны параметры']);
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($message_uuids), '?'));
    
    if ($is_admin) {
        $sql = "UPDATE messages SET is_read = 1 WHERE uuid IN ($placeholders) AND user_uuid != ?";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 's';
        $params = array_merge($message_uuids, [$current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    } else {
        $sql = "UPDATE messages m
                SET m.is_read = 1
                WHERE m.uuid IN ($placeholders) 
                AND m.user_uuid != ?
                AND EXISTS (
                    SELECT 1 FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                    WHERE m.task_uuid = t.uuid
                    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
                    AND p.created_by_uuid != ?
                )";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 'ssss';
        $params = array_merge($message_uuids, [$current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $affected = $db->affected_rows;
    $stmt->close();
    
    log_debug("[POLLING_MARK] Marked {$affected} messages as read");
    
    if ($is_admin) {
        $cnt_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE is_read = 0 AND user_uuid != ?");
        $cnt_stmt->bind_param("s", $current_user_uuid);
    } else {
        $cnt_stmt = $db->prepare("SELECT COUNT(DISTINCT m.uuid) as cnt 
                                  FROM messages m
                                  JOIN tasks t ON m.task_uuid = t.uuid
                                  JOIN projects p ON t.project_uuid = p.uuid
                                  LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                                  WHERE m.is_read = 0 
                                  AND m.user_uuid != ?
                                  AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)");
        $cnt_stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $cnt_stmt->execute();
    $new_unread_count = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
    $cnt_stmt->close();
    
    echo json_encode([
        'success' => true, 
        'marked_count' => $affected,
        'unread_count' => $new_unread_count
    ]);
    exit;
}

// Если действие не распознано
log_warning("[POLLING] Unknown action: {$action}");
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
exit;