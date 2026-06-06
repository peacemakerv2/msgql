<?php
// lib/notifications.php version 2.0: исправленные функции уведомлений

/**
 * Получение уведомлений ТОЛЬКО для текущего пользователя
 * (задачи, где пользователь - исполнитель или создатель, сообщения в его задачах)
 */
// lib/notifications.php version 2.1 - ИСПРАВЛЕНИЕ: исключаем собственные сообщения

function get_user_notifications($user_uuid, $since_time) {
    $db = msgql_db();
    $is_admin = msgql_is_admin();
    $notifications = [];
    
    // 1. Новые задачи, где пользователь НАЗНАЧЕН исполнителем (не создатель)
    $tasks_sql = "SELECT t.uuid, t.title, t.descr, 'task' as type, t.time, t.stamp,
                         p.title as project_title, p.uuid as project_uuid,
                         u.name as creator_name, u.login as creator_login
                  FROM tasks t
                  JOIN projects p ON t.project_uuid = p.uuid
                  LEFT JOIN users u ON t.user_uuid = u.uuid
                  WHERE t.time > ? 
                  AND t.user_uuid != ?
                  AND t.assigned_to_uuid = ?
                  ORDER BY t.time DESC
                  LIMIT 30";
    $stmt = $db->prepare($tasks_sql);
    $stmt->bind_param("iss", $since_time, $user_uuid, $user_uuid);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($tasks as $task) {
        $task['type_text'] = 'Вас назначили на задачу';
        $task['link'] = $appBase . "/projects.php?task={$task['uuid']}";
        $notifications[] = $task;
    }
    
    // 2. НОВЫЕ СООБЩЕНИЯ (ИСКЛЮЧАЕМ СВОИ)
    $messages_sql = "SELECT m.uuid, m.text, 'message' as type, m.time, m.stamp, m.is_read,
                            t.uuid as task_uuid, t.title as task_title,
                            p.uuid as project_uuid, p.title as project_title,
                            u.name as user_name, u.login as user_login
                     FROM messages m
                     JOIN tasks t ON m.task_uuid = t.uuid
                     JOIN projects p ON t.project_uuid = p.uuid
                     LEFT JOIN users u ON m.user_uuid = u.uuid
                     WHERE m.time > ?
                     AND m.user_uuid != ?   -- ИСКЛЮЧАЕМ СВОИ СООБЩЕНИЯ
                     AND (t.assigned_to_uuid = ? OR p.created_by_uuid = ?)
                     ORDER BY m.time DESC
                     LIMIT 30";
    $stmt = $db->prepare($messages_sql);
    $stmt->bind_param("isss", $since_time, $user_uuid, $user_uuid, $user_uuid);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($messages as $msg) {
        $msg['type_text'] = 'Новое сообщение';
        $msg['link'] = $appBase . "/messages.php?task={$msg['task_uuid']}&message={$msg['uuid']}";
        $notifications[] = $msg;
    }
    
    // 3. Новые файлы (исключаем свои)
    $files_sql = "SELECT f.uuid, f.orig_name, f.size_bytes, f.mime, 'file' as type, f.time, f.stamp,
                         t.uuid as task_uuid, t.title as task_title,
                         p.uuid as project_uuid, p.title as project_title,
                         u.name as uploader_name, u.login as uploader_login
                  FROM files f
                  JOIN task_files tf ON f.uuid = tf.file_uuid
                  JOIN tasks t ON tf.task_uuid = t.uuid
                  JOIN projects p ON t.project_uuid = p.uuid
                  LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                  WHERE f.time > ?
                  AND f.uploaded_by_uuid != ?
                  AND t.assigned_to_uuid = ?
                  ORDER BY f.time DESC
                  LIMIT 30";
    $stmt = $db->prepare($files_sql);
    $stmt->bind_param("iss", $since_time, $user_uuid, $user_uuid);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($files as $file) {
        $file['type_text'] = 'Новый файл';
        $file['link'] = $appBase . "/file_preview.php?uuid={$file['uuid']}";
        $notifications[] = $file;
    }
    
    // 4. Просроченные задачи (только назначенные на пользователя)
    $now = msgql_now_ms();
    $overdue_sql = "SELECT t.uuid, t.title, t.descr, 'overdue' as type, t.time_end_plan as deadline, t.time, t.stamp,
                           p.title as project_title, p.uuid as project_uuid,
                           u.name as assignee_name, u.login as assignee_login
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                    WHERE t.assigned_to_uuid = ?
                    AND t.status = 0
                    AND t.time_end_plan IS NOT NULL
                    AND t.time_end_plan > 0
                    AND t.time_end_plan < ?
                    AND t.time_end_plan > ?
                    ORDER BY t.time_end_plan ASC
                    LIMIT 30";
    $stmt = $db->prepare($overdue_sql);
    $stmt->bind_param("sii", $user_uuid, $now, $since_time);
    $stmt->execute();
    $overdue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($overdue as $task) {
        $task['type_text'] = 'Просроченная задача';
        $task['link'] = $appBase . "/projects.php?task={$task['uuid']}";
        $notifications[] = $task;
    }
    
    // Сортируем по времени (новые сверху)
    usort($notifications, function($a, $b) {
        return $b['time'] - $a['time'];
    });
    
    return $notifications;
}