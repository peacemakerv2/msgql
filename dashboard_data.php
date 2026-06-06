<?php
// dashboard_data.php version 2.2: AJAX-обработчик для дашборда с CSRF-защитой
define('AJAX_REQUEST', true);

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/notification_center.php'; 

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

if (!msgql_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();
$now = msgql_now_ms();
$now_str = (string)$now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

// ==================== CSRF-ЗАЩИТА ДЛЯ МУТИРУЮЩИХ ДЕЙСТВИЙ ====================
$mutating_actions = ['mark_read', 'mark_message_read', 'mark_notifications_read'];
if (in_array($action, $mutating_actions)) {
    msgql_csrf_check_and_exit();
}

// ==================== mark_read ====================
if ($action === 'mark_read') {
    $stmt = $db->prepare("UPDATE users SET time_last_dashboard_view = ? WHERE uuid = ?");
    $stmt->bind_param("ss", $now_str, $current_user_uuid);
    $stmt->execute();

    if ($is_admin) {
        $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE is_read = 0 AND user_uuid != ?");
        $stmt->bind_param("s", $current_user_uuid);
    } else {
        $sql = "UPDATE messages m
        SET m.is_read = 1
        WHERE m.is_read = 0 AND m.user_uuid != ?
        AND EXISTS (
            SELECT 1 FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE m.task_uuid = t.uuid
            AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
            AND p.created_by_uuid != ?
        )";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $marked_count = $db->affected_rows;
    
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
    $unread_count = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
    $cnt_stmt->close();
    
    echo json_encode(['success' => true, 'timestamp' => $now, 'marked_count' => $marked_count, 'unread_count' => $unread_count]);
    exit;
}

// dashboard_data.php
if ($action === 'mark_notifications_read') {
    if (class_exists('NotificationCenter')) {
        // 🔥 ДОБАВЬТЕ ЛОГИРОВАНИЕ
        log_debug("[MARK_READ] markAllNotificationsAsRead called for user: " . $current_user_uuid);
        
        $result = NotificationCenter::markAllNotificationsAsRead($current_user_uuid);
        
        log_debug("[MARK_READ] Result: " . ($result ? 'true' : 'false'));
        
        // 🔥 ПРОВЕРЯЕМ, СКОЛЬКО ЗАПИСЕЙ ОБНОВЛЕНО
        $db = msgql_db();
        $check = $db->prepare("SELECT COUNT(*) as cnt FROM user_notifications WHERE user_uuid = ? AND is_read = 0");
        $check->bind_param("s", $current_user_uuid);
        $check->execute();
        $remaining = $check->get_result()->fetch_assoc()['cnt'];
        log_debug("[MARK_READ] Remaining unread after update: " . $remaining);
        $check->close();
        
        echo json_encode(['success' => true, 'remaining' => $remaining]);
    } else {
        log_debug("[MARK_READ] NotificationCenter class NOT FOUND!");
        echo json_encode(['success' => false, 'error' => 'NotificationCenter not found']);
    }
    exit;
}

// ==================== mark_notification_read ====================
if ($action === 'mark_notification_read') {
    $notification_uuid = $_POST['uuid'] ?? '';
    if (empty($notification_uuid)) {
        echo json_encode(['success' => false, 'error' => 'No notification UUID']);
        exit;
    }
    
    $stmt = $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE uuid = ? AND user_uuid = ?");
    $stmt->bind_param("ss", $notification_uuid, $current_user_uuid);
    $success = $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => $success]);
    exit;
}

// ==================== mark_message_read ====================
if ($action === 'mark_message_read') {
    $message_uuid = $_POST['message_uuid'] ?? '';
    if (empty($message_uuid)) {
        echo json_encode(['success' => false, 'error' => 'No message UUID']);
        exit;
    }

    if ($is_admin) {
        $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE uuid = ? AND is_read = 0 AND user_uuid != ?");
        $stmt->bind_param("ss", $message_uuid, $current_user_uuid);
    } else {
        $sql = "UPDATE messages m
        SET m.is_read = 1
        WHERE m.uuid = ? AND m.is_read = 0 AND m.user_uuid != ?
        AND EXISTS (
            SELECT 1 FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE m.task_uuid = t.uuid
            AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
            AND p.created_by_uuid != ?
        )";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $message_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }

    if ($stmt->execute()) {
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
        $unread_count = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
        $cnt_stmt->close();
        
        echo json_encode(['success' => true, 'affected' => $db->affected_rows, 'unread_count' => $unread_count]);
    } else {
        echo json_encode(['success' => false, 'error' => $db->error]);
    }
    exit;
}

// ==================== get_updates ====================
if ($action === 'get_updates') {
    // Получаем старое значение ДО обновления
    $stmt_old = $db->prepare("SELECT time_last_dashboard_view FROM users WHERE uuid = ?");
    $stmt_old->bind_param("s", $current_user_uuid);
    $stmt_old->execute();
    $user_old = $stmt_old->get_result()->fetch_assoc();
    $last_view = $user_old['time_last_dashboard_view'] ?? 0;
    $stmt_old->close();
    
    // Обновляем на текущее время
    $now_str = (string)$now;
    $update_stmt2 = $db->prepare("UPDATE users SET time_last_dashboard_view = ? WHERE uuid = ?");
    $update_stmt2->bind_param("ss", $now_str, $current_user_uuid);
    $update_stmt2->execute();
    $update_stmt2->close();
    
    $since_time = ($last_view > 0) ? $last_view : ($now - 7 * 24 * 60 * 60 * 1000);

    $response = ['success' => true, 'since_time' => $since_time, 'last_updated' => $now];
    $since_time_str = (string)$since_time;
    // --- НОВЫЕ ЗАДАЧИ ---
    if ($is_admin) {
        $sql = "SELECT t.uuid, t.title, t.descr, t.status, t.time, t.stamp, t.time_start,
        p.title as project_title, p.uuid as project_uuid,
        u.name as user_name, u.login as user_login
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
        WHERE t.time > ? AND t.user_uuid != ?
        ORDER BY t.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $since_time_str, $current_user_uuid);
    } else {
        $sql = "SELECT DISTINCT t.uuid, t.title, t.descr, t.status, t.time, t.stamp, t.time_start,
        p.title as project_title, p.uuid as project_uuid,
        u.name as user_name, u.login as user_login
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE t.time > ? AND t.user_uuid != ? 
        AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
        AND p.created_by_uuid != ?
        ORDER BY t.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $response['new_tasks'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // --- НОВЫЕ СООБЩЕНИЯ (ИСПРАВЛЕНО: исключаем свои сообщения) ---
    if ($is_admin) {
        $sql = "SELECT m.uuid, m.text, m.time, m.stamp, m.is_read, m.user_uuid,
        t.uuid as task_uuid, t.title as task_title,
        p.uuid as project_uuid, p.title as project_title,
        u.name as user_name, u.login as user_login
        FROM messages m
        JOIN tasks t ON m.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON m.user_uuid = u.uuid
        WHERE m.is_read = 0
        AND m.user_uuid != ? 
        ORDER BY m.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $current_user_uuid);
    } else {
        $sql = "SELECT DISTINCT m.uuid, m.text, m.time, m.stamp, m.is_read, m.user_uuid,
        t.uuid as task_uuid, t.title as task_title,
        p.uuid as project_uuid, p.title as project_title,
        u.name as user_name, u.login as user_login
        FROM messages m
        JOIN tasks t ON m.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON m.user_uuid = u.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE m.is_read = 0
        AND m.user_uuid != ? 
        AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
        ORDER BY m.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $response['new_messages'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $since_time_str = (string)$since_time;

    // --- НОВЫЕ ФАЙЛЫ ---
    if ($is_admin) {
        $sql = "SELECT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
        t.uuid as task_uuid, t.title as task_title,
        p.uuid as project_uuid, p.title as project_title,
        u.name as uploader_name, u.login as uploader_login
        FROM files f
        LEFT JOIN task_files tf ON f.uuid = tf.file_uuid
        LEFT JOIN tasks t ON tf.task_uuid = t.uuid
        LEFT JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
        WHERE f.time > ?
        ORDER BY f.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $since_time_str);
    } else {
        $sql = "SELECT DISTINCT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
        t.uuid as task_uuid, t.title as task_title,
        p.uuid as project_uuid, p.title as project_title,
        u.name as uploader_name, u.login as uploader_login
        FROM files f
        JOIN task_files tf ON f.uuid = tf.file_uuid
        JOIN tasks t ON tf.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE f.time > ? 
        AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
        ORDER BY f.time DESC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $response['new_files'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $now_str = (string)$now;
    // ПРОСРОЧЕННЫЕ ЗАДАЧИ (с проверкой на > 0)
    $now_str = (string)$now;

    if ($is_admin) {
        $sql = "SELECT t.uuid, t.title, t.time_end_plan as deadline, t.time, t.stamp, t.time_start,
                p.title as project_title, p.uuid as project_uuid,
                u.name as assignee_name, u.login as assignee_login
                FROM tasks t
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                WHERE t.status = 0 
                AND t.time_end_plan IS NOT NULL 
                AND t.time_end_plan > 0      
                AND t.time_end_plan < ?
                AND t.assigned_to_uuid = ?
                ORDER BY t.time_end_plan ASC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $now_str, $current_user_uuid);
    } else {
        $sql = "SELECT DISTINCT t.uuid, t.title, t.time_end_plan as deadline, t.time, t.stamp,
                p.title as project_title, p.uuid as project_uuid,
                u.name as assignee_name, u.login as assignee_login
                FROM tasks t
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE t.status = 0 
                AND t.time_end_plan IS NOT NULL 
                AND t.time_end_plan > 0      
                AND t.time_end_plan < ? 
                AND t.assigned_to_uuid = ?
                AND (upp.can_view = 1 OR p.created_by_uuid = ?)
                ORDER BY t.time_end_plan ASC LIMIT 20";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $current_user_uuid, $now_str, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $response['overdue_tasks'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Общее количество непрочитанных сообщений
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
    $response['unread_count'] = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
    $cnt_stmt->close();


    
    // 🔥 ПОЛУЧАЕМ УВЕДОМЛЕНИЯ ИЗ ЦЕНТРАЛЬНОЙ СИСТЕМЫ
    if (class_exists('NotificationCenter')) {
        $notifications = NotificationCenter::getUserUnreadNotifications($current_user_uuid, 10);
        $response['notifications'] = $notifications;
        $notifications_count = NotificationCenter::getUnreadCount($current_user_uuid);
        $response['notifications_count'] = $notifications_count;

        // 🔥 СКЛАДЫВАЕМ СЧЁТЧИКИ для бейджика
        $response['unread_count'] = ($response['unread_count'] ?? 0) + $notifications_count;
        
        // 🔥 ЛОГИРУЕМ ДЛЯ ОТЛАДКИ
        log_debug("[DASHBOARD] Notifications found: " . ($notifications ? count($notifications) : 0));
        if ($notifications) {
            log_debug("[DASHBOARD] First notification: " . json_encode($notifications[0], JSON_UNESCAPED_UNICODE));
        }
    } else {
        log_warning("[DASHBOARD] NotificationCenter class not found!");
        $response['notifications'] = [];
        $response['notifications_count'] = 0;
    }

    // ==================== BLOCK START: Dashboard Data with Badges Support v2.3 ====================
    // ver.2.3 - Добавлена поддержка бейджей для дашборда
    // - Логирование состояния бейджей для отладки

        log_debug("[DASHBOARD_BADGES] New tasks count: " . count($response['new_tasks']));
        log_debug("[DASHBOARD_BADGES] New messages count: " . count($response['new_messages']));
        log_debug("[DASHBOARD_BADGES] New files count: " . count($response['new_files']));
        log_debug("[DASHBOARD_BADGES] Unread messages: " . ($response['unread_count'] ?? 0));
        log_debug("[DASHBOARD_BADGES] Notifications count: " . ($response['notifications_count'] ?? 0));

    // ==================== BLOCK END: Dashboard Data with Badges Support v2.3 ====================

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
exit;