<?php
// lib/notification_center.php version 3.2 - ИСПРАВЛЕНА СТРУКТУРА: notifyNewMessage больше не внутри sendWebPush

require_once __DIR__ . '/mailer.php';

class NotificationCenter {
    
    /**
     * Сравнивает старые и новые данные задачи и отправляет уведомления
     */
    public static function notifyTaskChanges($task_uuid, $old_data, $new_data, $changed_by_uuid) {
        log_debug("[NOTIFY] === START notifyTaskChanges ===");
        log_debug("[NOTIFY] task_uuid: " . $task_uuid);
        
        $changes = [];
        
        $fields_to_check = [
            'title' => 'название',
            'descr' => 'описание',
            'assigned_to_uuid' => 'исполнитель',
            'parent_task_uuid' => 'родительская задача',
            'time_start' => 'дата начала',
            'time_end_plan' => 'плановое окончание',
            'status' => 'статус'
        ];
        
        foreach ($fields_to_check as $field => $label) {
            $old_val = $old_data[$field] ?? null;
            $new_val = $new_data[$field] ?? null;
            
            $old_normalized = ($old_val === null || $old_val === '') ? null : $old_val;
            $new_normalized = ($new_val === null || $new_val === '') ? null : $new_val;
            
            if ($old_normalized != $new_normalized) {
                $changes[] = [
                    'field' => $field,
                    'label' => $label,
                    'old' => $old_val,
                    'new' => $new_val,
                    'old_display' => self::formatFieldValue($field, $old_val),
                    'new_display' => self::formatFieldValue($field, $new_val)
                ];
            }
        }
        
        if (empty($changes)) {
            log_debug("[NOTIFY] No changes detected");
            return ['sent' => false, 'reason' => 'no_changes'];
        }
        
        $db = msgql_db();
        $stmt = $db->prepare("
            SELECT t.*, p.title as project_title, p.uuid as project_uuid, p.created_by_uuid as project_creator
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            WHERE t.uuid = ?
        ");
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        
        if (!$task) {
            return ['sent' => false, 'reason' => 'task_not_found'];
        }
        
        $stmt = $db->prepare("SELECT name, login, uuid FROM users WHERE uuid = ?");
        $stmt->bind_param("s", $changed_by_uuid);
        $stmt->execute();
        $changed_by = $stmt->get_result()->fetch_assoc();
        $changed_by_name = $changed_by['name'] ?: $changed_by['login'];
        
        $recipients = [];
        $recipient_roles = [];
        
        $assigned_change = self::findChangeByField($changes, 'assigned_to_uuid');
        if ($assigned_change && !empty($assigned_change['new'])) {
            $new_assignee = $assigned_change['new'];
            if ($new_assignee !== $changed_by_uuid) {
                $recipients[$new_assignee] = true;
                $recipient_roles[$new_assignee] = 'new_assignee';
                log_debug("[NOTIFY] Added new assignee: {$new_assignee}");
            }
        }
        
        if ($assigned_change && !empty($assigned_change['old']) && $assigned_change['old'] !== $changed_by_uuid) {
            $old_assignee = $assigned_change['old'];
            if (!isset($recipients[$old_assignee])) {
                $recipients[$old_assignee] = true;
                $recipient_roles[$old_assignee] = 'removed_assignee';
                log_debug("[NOTIFY] Added removed assignee: {$old_assignee}");
            }
        }
        
        $task_creator = $task['user_uuid'] ?? null;
        if ($task_creator && $task_creator !== $changed_by_uuid && !isset($recipients[$task_creator])) {
            $recipients[$task_creator] = true;
            $recipient_roles[$task_creator] = 'task_creator';
            log_debug("[NOTIFY] Added task creator: {$task_creator}");
        }
        
        $subscribers = self::getTaskSubscribers($task_uuid);
        foreach ($subscribers as $sub) {
            $user_uuid = $sub['user_uuid'];
            if ($user_uuid !== $changed_by_uuid && !isset($recipients[$user_uuid])) {
                $recipients[$user_uuid] = true;
                $recipient_roles[$user_uuid] = 'subscriber';
                log_debug("[NOTIFY] Added subscriber: {$user_uuid}");
            }
        }
        
        log_debug("[NOTIFY] Final unique recipients: " . count($recipients));
        
        $results = [];
        foreach (array_keys($recipients) as $user_uuid) {
            $role = $recipient_roles[$user_uuid] ?? 'other';
            log_debug("[NOTIFY] Sending notification to {$user_uuid} (role: {$role})");
            
            $result = self::sendTaskChangeNotification(
                $user_uuid, 
                $task, 
                $changes, 
                $changed_by_name,
                $role
            );
            $results[$user_uuid] = $result;
        }
        
        self::saveChangeHistory($task_uuid, $changes, $changed_by_uuid, $task['title']);
        self::broadcastTaskUpdate($task_uuid, $changes);
        
        log_debug("[NOTIFY] === END notifyTaskChanges, sent to " . count($results) . " recipients ===");
        
        return [
            'sent' => count($results) > 0,
            'recipients' => count($results),
            'changes' => $changes,
            'results' => $results
        ];
    }
    
    private static function formatFieldValue($field, $value) {
        if (empty($value) || $value === 'null') return '—';
        
        $db = msgql_db();
        
        switch ($field) {
            case 'assigned_to_uuid':
                $stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                $stmt->bind_param("s", $value);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                return $user ? ($user['name'] ?: $user['login']) : 'Неизвестный';
                
            case 'parent_task_uuid':
                $stmt = $db->prepare("SELECT title FROM tasks WHERE uuid = ?");
                $stmt->bind_param("s", $value);
                $stmt->execute();
                $task = $stmt->get_result()->fetch_assoc();
                return $task ? $task['title'] : '—';
                
            case 'time_start':
            case 'time_end_plan':
                if (!$value) return '—';
                return date('d.m.Y H:i', (int)($value / 1000));
                
            case 'status':
                $statuses = ['0' => 'Активна', '1' => 'Выполнена'];
                return $statuses[$value] ?? $value;
                
            default:
                return (string)$value;
        }
    }
    
    private static function findChangeByField($changes, $field) {
        foreach ($changes as $change) {
            if ($change['field'] === $field) {
                return $change;
            }
        }
        return null;
    }
    
    private static function getTaskSubscribers($task_uuid) {
        $db = msgql_db();
        $stmt = $db->prepare("
            SELECT user_uuid, subscribed_at 
            FROM task_subscribers 
            WHERE task_uuid = ? AND is_active = 1
        ");
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // ==================== BLOCK START: sendTaskChangeNotification v1.3 ====================
    private static function sendTaskChangeNotification($user_uuid, $task, $changes, $changed_by_name, $role) {
        $db = msgql_db();
        
        $stmt = $db->prepare("SELECT uuid, login, name, email FROM users WHERE uuid = ? AND status = 0");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            log_debug("[NOTIFY_TASK] User not found: {$user_uuid}");
            return ['sent' => false, 'reason' => 'user_not_found'];
        }
        
        $check_duplicate = $db->prepare("
            SELECT COUNT(*) as cnt 
            FROM user_notifications 
            WHERE user_uuid = ? AND task_uuid = ? AND created_at > ?
        ");
        $five_seconds_ago = msgql_now_ms() - 5000;
        $check_duplicate->bind_param("ssi", $user_uuid, $task['uuid'], $five_seconds_ago);
        $check_duplicate->execute();
        $duplicate_count = $check_duplicate->get_result()->fetch_assoc()['cnt'];
        $check_duplicate->close();
        
        $skip_web_notification = ($duplicate_count > 0);
        
        $changes_text = [];
        $has_assignee_change = false;
        
        foreach ($changes as $change) {
            if ($change['field'] === 'assigned_to_uuid') {
                $has_assignee_change = true;
                if ($role === 'new_assignee') {
                    $changes_text[] = "• Вас назначили исполнителем задачи";
                } elseif ($role === 'removed_assignee') {
                    $changes_text[] = "• Вас сняли с исполнителей задачи";
                } else {
                    $changes_text[] = "• {$change['label']}: «{$change['old_display']}» → «{$change['new_display']}»";
                }
            } else {
                $changes_text[] = "• {$change['label']}: «{$change['old_display']}» → «{$change['new_display']}»";
            }
        }
        
        if (count($changes) === 1 && $has_assignee_change && $role !== 'new_assignee' && $role !== 'removed_assignee') {
            log_debug("[NOTIFY_TASK] Skipping notification for assignee change without role match");
            return ['sent' => false, 'reason' => 'skip_assignee_change'];
        }
        
        $subject = "Изменение в задаче: {$task['title']}";
        
        $plain_message = "Пользователь {$changed_by_name} изменил задачу:\r\n";
        $plain_message .= "📋 «{$task['title']}»\r\n";
        $plain_message .= "📁 Проект: {$task['project_title']}\r\n\r\n";
        $plain_message .= "📝 Изменения:\r\n" . implode("\r\n", $changes_text) . "\r\n\r\n";
        $plain_message .= "🔗 Подробнее: " . msgql_get_base_url() . "/projects.php?task={$task['uuid']}\r\n";
        
        $email_sent = false;
        if (!empty($user['email'])) {
            $extra_data = [
                'user_name' => $user['name'] ?: $user['login']
            ];
            $email_sent = msgql_send_email($user['email'], $subject, $plain_message, $extra_data);
            log_debug("[NOTIFY_TASK] Email sent to {$user['email']}: " . ($email_sent ? 'SUCCESS' : 'FAILED'));
        }
        
        $notification_saved = false;
        if (!$skip_web_notification) {
            $notification_saved = self::saveUserNotification($user_uuid, $task['uuid'], [
                'task_title' => $task['title'],
                'project_title' => $task['project_title'],
                'changes' => $changes,
                'changed_by' => $changed_by_name,
                'role' => $role
            ]);
            log_debug("[NOTIFY_TASK] Web notification saved: " . ($notification_saved ? 'SUCCESS' : 'FAILED'));
        } else {
            log_debug("[NOTIFY_TASK] Web notification skipped (duplicate within 5 seconds) for user {$user_uuid}");
        }
        
        if (method_exists(self::class, 'sendWebPush')) {
            $push_url = msgql_get_base_url() . "/projects.php?task={$task['uuid']}";
            
            $push_body = "Пользователь {$changed_by_name} изменил задачу";
            if (!empty($changes_text)) {
                $push_body .= ": " . implode(", ", array_slice($changes_text, 0, 2));
                if (count($changes_text) > 2) {
                    $push_body .= "…";
                }
            }
            if (strlen($push_body) > 120) {
                $push_body = substr($push_body, 0, 117) . "…";
            }
            
            $push_result = self::sendWebPush(
                $user_uuid,
                "✏️ Изменение задачи: {$task['title']}",
                $push_body,
                $push_url,
                'task',
                $task['uuid'],
                null,
                false
            );
            log_debug("[NOTIFY_TASK] WebPush result for {$user_uuid}: " . ($push_result['sent'] ? 'sent' : 'skipped - ' . ($push_result['reason'] ?? 'unknown')));
        } else {
            log_debug("[NOTIFY_TASK] sendWebPush method not available");
        }
        
        self::triggerSSEUpdate($user_uuid, $task['uuid']);
        
        return [
            'sent' => $email_sent || $notification_saved,
            'user_uuid' => $user_uuid,
            'user_name' => $user['name'] ?: $user['login'],
            'role' => $role,
            'email_sent' => $email_sent,
            'notification_saved' => $notification_saved
        ];
    }
    // ==================== BLOCK END: sendTaskChangeNotification v1.3 ====================
    
    // ========== СОХРАНЕНИЕ УВЕДОМЛЕНИЯ В БД ==========
    private static function saveUserNotification($user_uuid, $task_uuid, $data) {
        $db = msgql_db();
        
        $check = $db->query("SHOW TABLES LIKE 'user_notifications'");
        if ($check->num_rows == 0) {
            $db->query("
                CREATE TABLE IF NOT EXISTS `user_notifications` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `uuid` char(36) NOT NULL,
                    `user_uuid` char(36) NOT NULL,
                    `task_uuid` char(36) DEFAULT NULL,
                    `type` varchar(50) NOT NULL,
                    `data` text NOT NULL,
                    `is_read` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` bigint(20) NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_uuid` (`user_uuid`),
                    KEY `idx_is_read` (`is_read`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $uuid = msgql_uuid_v4();
        $now = msgql_now_ms();
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $stmt = $db->prepare("
            INSERT INTO user_notifications (uuid, user_uuid, task_uuid, type, data, created_at) 
            VALUES (?, ?, ?, 'task_changed', ?, ?)
        ");
        $stmt->bind_param("ssssi", $uuid, $user_uuid, $task_uuid, $json_data, $now);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    // ========== СОХРАНЕНИЕ ИСТОРИИ ИЗМЕНЕНИЙ ==========
    private static function saveChangeHistory($task_uuid, $changes, $changed_by_uuid, $task_title) {
        $db = msgql_db();
        
        $check = $db->query("SHOW TABLES LIKE 'task_change_history'");
        if ($check->num_rows == 0) {
            $db->query("
                CREATE TABLE IF NOT EXISTS `task_change_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `uuid` char(36) NOT NULL,
                    `task_uuid` char(36) NOT NULL,
                    `changed_by_uuid` char(36) NOT NULL,
                    `changes` text NOT NULL,
                    `created_at` bigint(20) NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_task_uuid` (`task_uuid`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $uuid = msgql_uuid_v4();
        $now = msgql_now_ms();
        $changes_json = json_encode($changes, JSON_UNESCAPED_UNICODE);
        
        $stmt = $db->prepare("
            INSERT INTO task_change_history (uuid, task_uuid, changed_by_uuid, changes, created_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssi", $uuid, $task_uuid, $changed_by_uuid, $changes_json, $now);
        return $stmt->execute();
    }
    
    // ========== ОТПРАВКА SSE СОБЫТИЙ ==========
    private static function triggerSSEUpdate($user_uuid, $task_uuid) {
        if (function_exists('msgql_send_sse_event')) {
            $event_data = [
                'type' => 'task_updated',
                'task_uuid' => $task_uuid,
                'user_uuid' => $user_uuid,
                'timestamp' => msgql_now_ms()
            ];
            msgql_send_sse_event($task_uuid, 'task_updated', $event_data);
        }
    }
    
    private static function broadcastTaskUpdate($task_uuid, $changes) {
        if (function_exists('msgql_send_sse_event')) {
            $event_data = [
                'type' => 'task_broadcast',
                'task_uuid' => $task_uuid,
                'changes' => $changes,
                'timestamp' => msgql_now_ms()
            ];
            msgql_send_sse_event($task_uuid, 'task_broadcast', $event_data);
        }
    }
    
    // ========== УВЕДОМЛЕНИЕ О НОВОЙ ЗАДАЧЕ ==========
    public static function notifyNewTask($task, $created_by_uuid) {
        $db = msgql_db();
        
        $stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
        $stmt->bind_param("s", $created_by_uuid);
        $stmt->execute();
        $creator = $stmt->get_result()->fetch_assoc();
        $creator_name = $creator['name'] ?: $creator['login'];
        
        $recipients = [];
        
        if (!empty($task['assigned_to_uuid']) && $task['assigned_to_uuid'] !== $created_by_uuid) {
            $recipients[$task['assigned_to_uuid']] = 'assignee';
        }
        
        $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
        $stmt->bind_param("s", $task['project_uuid']);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        if ($project && $project['created_by_uuid'] !== $created_by_uuid) {
            if (!isset($recipients[$project['created_by_uuid']])) {
                $recipients[$project['created_by_uuid']] = 'project_creator';
            }
        }
        
        $results = [];
        foreach ($recipients as $user_uuid => $role) {
            $result = self::sendNewTaskNotification($user_uuid, $task, $creator_name, $role);
            $results[$role] = $result;
        }
        
        return [
            'sent' => count($results) > 0,
            'recipients' => count($results),
            'results' => $results
        ];
    }
    
    // ==================== BLOCK START: sendNewTaskNotification v1.3 ====================
    private static function sendNewTaskNotification($user_uuid, $task, $creator_name, $role) {
        $db = msgql_db();
        
        $stmt = $db->prepare("SELECT uuid, login, name, email FROM users WHERE uuid = ? AND status = 0");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            log_debug("[NOTIFY_NEW] User not found: {$user_uuid}");
            return ['sent' => false, 'reason' => 'user_not_found'];
        }
        
        $check_duplicate = $db->prepare("
            SELECT COUNT(*) as cnt 
            FROM user_notifications 
            WHERE user_uuid = ? AND task_uuid = ? AND created_at > ?
        ");
        $five_seconds_ago = msgql_now_ms() - 5000;
        $check_duplicate->bind_param("ssi", $user_uuid, $task['uuid'], $five_seconds_ago);
        $check_duplicate->execute();
        $duplicate_count = $check_duplicate->get_result()->fetch_assoc()['cnt'];
        $check_duplicate->close();
        
        $skip_web_notification = ($duplicate_count > 0);
        
        $subject = "Новая задача: {$task['title']}";
        
        $plain_message = "Пользователь {$creator_name} создал новую задачу:\r\n";
        $plain_message .= "📋 «{$task['title']}»\r\n";
        $plain_message .= "📁 Проект: {$task['project_title']}\r\n";
        
        if ($role === 'assignee') {
            $plain_message .= "\r\n👤 Вас назначили исполнителем этой задачи.\r\n";
        }
        
        if (!empty($task['descr'])) {
            $plain_message .= "\r\n📝 Описание: " . mb_substr($task['descr'], 0, 200) . "\r\n";
        }
        
        if (!empty($task['time_start']) && $task['time_start'] > 0) {
            $plain_message .= "\r\n🚀 Дата начала: " . date('d.m.Y H:i', (int)($task['time_start'] / 1000)) . "\r\n";
        }

        if (!empty($task['time_end_plan']) && $task['time_end_plan'] > 0) {
            $plain_message .= "\r\n⏰ Плановое окончание: " . date('d.m.Y H:i', (int)($task['time_end_plan'] / 1000)) . "\r\n";
        }
        
        $plain_message .= "\r\n🔗 Подробнее: " . msgql_get_base_url() . "/projects.php?task={$task['uuid']}\r\n";
        
        $email_sent = false;
        if (!empty($user['email'])) {
            $extra_data = [
                'user_name' => $user['name'] ?: $user['login']
            ];
            $email_sent = msgql_send_email($user['email'], $subject, $plain_message, $extra_data);
            log_debug("[NOTIFY_NEW] Email sent to {$user['email']}: " . ($email_sent ? 'SUCCESS' : 'FAILED'));
        }
        
        $notification_saved = false;
        if (!$skip_web_notification) {
            $notification_saved = self::saveUserNotification($user_uuid, $task['uuid'], [
                'task_title' => $task['title'],
                'project_title' => $task['project_title'],
                'creator_name' => $creator_name,
                'role' => $role,
                'is_new' => true
            ]);
            log_debug("[NOTIFY_NEW] Web notification saved: " . ($notification_saved ? 'SUCCESS' : 'FAILED'));
        } else {
            log_debug("[NOTIFY_NEW] Web notification skipped (duplicate within 5 seconds) for user {$user_uuid}");
        }
        
        if (method_exists(self::class, 'sendWebPush')) {
            $push_url = msgql_get_base_url() . "/projects.php?task={$task['uuid']}";
            
            if ($role === 'assignee') {
                $push_title = "📋 Вас назначили на задачу";
                $push_body = "{$creator_name} назначил вас исполнителем задачи «{$task['title']}»";
            } else {
                $push_title = "📋 Новая задача в проекте";
                $push_body = "{$creator_name} создал задачу «{$task['title']}» в проекте {$task['project_title']}";
            }
            
            if (strlen($push_body) > 120) {
                $push_body = substr($push_body, 0, 117) . "…";
            }
            
            $push_result = self::sendWebPush(
                $user_uuid,
                $push_title,
                $push_body,
                $push_url,
                'task',
                $task['uuid'],
                null,
                false
            );
            log_debug("[NOTIFY_NEW] WebPush result for {$user_uuid}: " . ($push_result['sent'] ? 'sent' : 'skipped - ' . ($push_result['reason'] ?? 'unknown')));
        } else {
            log_debug("[NOTIFY_NEW] sendWebPush method not available");
        }
        
        self::triggerSSEUpdate($user_uuid, $task['uuid']);
        
        return [
            'sent' => $email_sent || $notification_saved,
            'user_uuid' => $user_uuid,
            'user_name' => $user['name'] ?: $user['login'],
            'role' => $role,
            'email_sent' => $email_sent,
            'notification_saved' => $notification_saved
        ];
    }
    // ==================== BLOCK END: sendNewTaskNotification v1.3 ====================
    
    // ========== РАБОТА С УВЕДОМЛЕНИЯМИ В БД ==========
    public static function getUserUnreadNotifications($user_uuid, $limit = 50) {
        $db = msgql_db();
        
        $check = $db->query("SHOW TABLES LIKE 'user_notifications'");
        if ($check->num_rows == 0) {
            return [];
        }
        
        $stmt = $db->prepare("
            SELECT n.*, t.title as task_title
            FROM user_notifications n
            LEFT JOIN tasks t ON n.task_uuid = t.uuid
            WHERE n.user_uuid = ? AND n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("si", $user_uuid, $limit);
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($notifications as &$n) {
            $n['data'] = json_decode($n['data'], true);
        }
        
        return $notifications;
    }
    
    public static function markNotificationAsRead($notification_uuid, $user_uuid) {
        $db = msgql_db();
        $stmt = $db->prepare("
            UPDATE user_notifications 
            SET is_read = 1 
            WHERE uuid = ? AND user_uuid = ?
        ");
        $stmt->bind_param("ss", $notification_uuid, $user_uuid);
        return $stmt->execute();
    }
    
    public static function markAllNotificationsAsRead($user_uuid) {
        $db = msgql_db();
        
        log_debug("[NOTIFY_MARK] markAllNotificationsAsRead for user: " . $user_uuid);
        
        $check = $db->prepare("SELECT COUNT(*) as cnt FROM user_notifications WHERE user_uuid = ? AND is_read = 0");
        $check->bind_param("s", $user_uuid);
        $check->execute();
        $to_update = $check->get_result()->fetch_assoc()['cnt'];
        log_debug("[NOTIFY_MARK] Records to update: " . $to_update);
        $check->close();
        
        if ($to_update == 0) {
            log_debug("[NOTIFY_MARK] No unread notifications for user: " . $user_uuid);
            return true;
        }
        
        $stmt = $db->prepare("
            UPDATE user_notifications 
            SET is_read = 1 
            WHERE user_uuid = ? AND is_read = 0
        ");
        $stmt->bind_param("s", $user_uuid);
        $result = $stmt->execute();
        
        log_debug("[NOTIFY_MARK] Update result: " . ($result ? 'true' : 'false'));
        log_debug("[NOTIFY_MARK] Affected rows: " . $db->affected_rows);
        
        $stmt->close();
        return $result;
    }
    
    public static function getUnreadCount($user_uuid) {
        $db = msgql_db();
        
        $check = $db->query("SHOW TABLES LIKE 'user_notifications'");
        if ($check->num_rows == 0) {
            return 0;
        }
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt 
            FROM user_notifications 
            WHERE user_uuid = ? AND is_read = 0
        ");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int)($result['cnt'] ?? 0);
    }

    // ==================== BLOCK START: sendWebPush v1.3 ====================
    /**
     * Отправка Push-уведомления пользователю (только когда сайт закрыт/свёрнут)
     * 
     * @param string $user_uuid Получатель
     * @param string $title Заголовок
     * @param string $body Текст
     * @param string $url Ссылка для перехода
     * @param string $type Тип (message/task/file/test)
     * @param string|null $task_uuid UUID задачи
     * @param string|null $message_uuid UUID сообщения
     * @param bool $forceSend Принудительная отправка (для тестов)
     * @return array Результат отправки
     */
    public static function sendWebPush($user_uuid, $title, $body, $url, $type = 'message', $task_uuid = null, $message_uuid = null, $forceSend = false) {
        log_debug("[NOTIFY_PUSH] ========== START ==========");
        log_debug("[NOTIFY_PUSH] User: {$user_uuid}, Type: {$type}, Force: " . ($forceSend ? 'YES' : 'NO'));
        log_debug("[NOTIFY_PUSH] Title: {$title}");
        log_debug("[NOTIFY_PUSH] URL: {$url}");
        log_debug("[NOTIFY_PUSH] task_uuid: " . ($task_uuid ?? 'null'));
        log_debug("[NOTIFY_PUSH] message_uuid: " . ($message_uuid ?? 'null'));

        if ($type === 'message' && $message_uuid) {
            $db = msgql_db();
            $stmt = $db->prepare("SELECT user_uuid FROM messages WHERE uuid = ?");
            $stmt->bind_param("s", $message_uuid);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result && $result['user_uuid'] === $user_uuid) {
                log_debug("[NOTIFY_PUSH] Skipping push for message author");
                return ['sent' => false, 'reason' => 'own_message'];
            }
        }
        
        if (!function_exists('send_push_to_user')) {
            $wrapper_path = __DIR__ . '/web_push_wrapper.php';
            if (file_exists($wrapper_path)) {
                require_once $wrapper_path;
                log_debug("[NOTIFY_PUSH] Loaded web_push_wrapper.php");
            } else {
                log_error("[NOTIFY_PUSH] web_push_wrapper.php not found!");
                return ['sent' => false, 'reason' => 'wrapper_not_found'];
            }
        }
        
        $data = [
            'url' => $url,
            'type' => $type,
            'task_uuid' => $task_uuid,
            'message_uuid' => $message_uuid
        ];
        
        $result = send_push_to_user($user_uuid, $title, $body, $data, $forceSend);
        
        log_debug("[NOTIFY_PUSH] Result: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
        if (!$result['success']) {
            log_debug("[NOTIFY_PUSH] Reason: " . ($result['reason'] ?? 'unknown'));
        }
        
        return [
            'sent' => $result['success'],
            'reason' => $result['reason'] ?? ($result['success'] ? 'ok' : 'unknown'),
            'details' => $result
        ];
    }
    
    /**
     * ТЕСТОВАЯ ОТПРАВКА PUSH-УВЕДОМЛЕНИЯ (для отладки)
     */
    public static function sendTestPush($user_uuid, $test_message = null) {
        log_debug("[NOTIFY_PUSH] ========== TEST PUSH ==========");
        
        if (!function_exists('send_test_push_to_user')) {
            $wrapper_path = __DIR__ . '/web_push_wrapper.php';
            if (file_exists($wrapper_path)) {
                require_once $wrapper_path;
            } else {
                return ['sent' => false, 'reason' => 'wrapper_not_found'];
            }
        }
        
        $testMsg = $test_message ?? 'Тестовое уведомление системы ' . ($GLOBALS['system_title'] ?? 'ЗадаЧат');
        $url = ($GLOBALS['appBase'] ?? '') . '/messages.php';
        
        $result = send_test_push_to_user($user_uuid, $testMsg);
        
        log_debug("[NOTIFY_PUSH] Test push result: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
        
        return [
            'sent' => $result['success'],
            'reason' => $result['reason'] ?? ($result['success'] ? 'ok' : 'unknown'),
            'details' => $result
        ];
    }
    // ==================== BLOCK END: sendWebPush v1.3 ====================

    // ==================== BLOCK START: notifyNewMessage v1.3 (diagnostics) ====================
    /**
     * Отправка уведомлений о новом сообщении
     * 
     * @param string $message_uuid UUID сообщения
     * @param string $task_uuid UUID задачи
     * @param string $message_text Текст сообщения
     * @param string $author_name Имя автора
     * @param string $author_uuid UUID автора
     * @return array Результаты отправки
     */
    public static function notifyNewMessage($message_uuid, $task_uuid, $message_text, $author_name, $author_uuid) {
        log_debug("[NOTIFY_MSG] ========== START ==========");
        log_debug("[NOTIFY_MSG] Message: {$message_uuid}, Task: {$task_uuid}, Author: {$author_name}");
        
        if ($message_uuid === $task_uuid) {
            log_error("[NOTIFY_MSG] ❌ CRITICAL: message_uuid EQUALS task_uuid! This is a bug!");
            log_error("[NOTIFY_MSG] message_uuid: {$message_uuid}, task_uuid: {$task_uuid}");
            return ['sent' => false, 'reason' => 'invalid_message_uuid'];
        }
        
        $db = msgql_db();
        
        $check_msg = $db->prepare("SELECT uuid, task_uuid, user_uuid FROM messages WHERE uuid = ?");
        $check_msg->bind_param("s", $message_uuid);
        $check_msg->execute();
        $msg_exists = $check_msg->get_result()->fetch_assoc();
        $check_msg->close();
        
        if (!$msg_exists) {
            log_error("[NOTIFY_MSG] ❌ Message NOT found in DB: {$message_uuid}");
            return ['sent' => false, 'reason' => 'message_not_found'];
        }
        
        if ($msg_exists['task_uuid'] !== $task_uuid) {
            log_error("[NOTIFY_MSG] ⚠️ Task mismatch: message.task_uuid={$msg_exists['task_uuid']} != provided task_uuid={$task_uuid}");
        }
        
        log_debug("[NOTIFY_MSG] ✅ Message verified in DB, task_uuid matches: " . ($msg_exists['task_uuid'] === $task_uuid ? 'YES' : 'NO'));
        
        $stmt = $db->prepare("
            SELECT t.*, p.title as project_title, p.created_by_uuid as project_creator
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            WHERE t.uuid = ?
        ");
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$task) {
            log_debug("[NOTIFY_MSG] Task not found: {$task_uuid}");
            return ['sent' => false, 'reason' => 'task_not_found'];
        }
        
        // 🔥 ДИАГНОСТИКА: выводим информацию о задаче
        log_debug("[NOTIFY_MSG] 📋 Task info - title: {$task['title']}, assigned_to: " . ($task['assigned_to_uuid'] ?? 'NULL') . ", created_by: " . ($task['user_uuid'] ?? 'NULL'));
        
        $recipients = [];
        
        if (!empty($task['assigned_to_uuid']) && $task['assigned_to_uuid'] !== $author_uuid) {
            $recipients[$task['assigned_to_uuid']] = 'assignee';
            log_debug("[NOTIFY_MSG] Added assignee: {$task['assigned_to_uuid']}");
        } else {
            log_debug("[NOTIFY_MSG] Skipping assignee: assigned_to=" . ($task['assigned_to_uuid'] ?? 'NULL') . ", author={$author_uuid}");
        }
        
        if (!empty($task['user_uuid']) && $task['user_uuid'] !== $author_uuid) {
            if (!isset($recipients[$task['user_uuid']])) {
                $recipients[$task['user_uuid']] = 'task_creator';
                log_debug("[NOTIFY_MSG] Added task creator: {$task['user_uuid']}");
            }
        } else {
            log_debug("[NOTIFY_MSG] Skipping task creator: user_uuid=" . ($task['user_uuid'] ?? 'NULL') . ", author={$author_uuid}");
        }
        
        $subscribers = self::getTaskSubscribers($task_uuid);
        log_debug("[NOTIFY_MSG] Found " . count($subscribers) . " subscribers");
        
        foreach ($subscribers as $sub) {
            $user_uuid = $sub['user_uuid'];
            if ($user_uuid !== $author_uuid && !isset($recipients[$user_uuid])) {
                $recipients[$user_uuid] = 'subscriber';
                log_debug("[NOTIFY_MSG] Added subscriber: {$user_uuid}");
            }
        }
        
        log_debug("[NOTIFY_MSG] 📊 Final recipients list: " . (empty($recipients) ? 'EMPTY' : json_encode(array_keys($recipients))));
        
        if (empty($recipients)) {
            log_debug("[NOTIFY_MSG] No recipients found - push notification will NOT be sent");
            log_debug("[NOTIFY_MSG] 💡 Tip: Assign a different user to this task or add subscribers");
            return ['sent' => false, 'reason' => 'no_recipients'];
        }
        
        $short_text = mb_substr(strip_tags($message_text), 0, 100);
        if (mb_strlen($short_text) >= 100) {
            $short_text .= '…';
        }
        
        $project_prefix = !empty($task['project_title']) ? "[{$task['project_title']}] " : "";
        
        $message_url = msgql_get_base_url() . "/messages.php?message={$message_uuid}";
        
        log_debug("[NOTIFY_MSG] 🔗 Message URL: " . $message_url);
        log_debug("[NOTIFY_MSG] 📤 Sending to " . count($recipients) . " recipients");
        
        $results = [];
        foreach ($recipients as $user_uuid => $role) {
            log_debug("[NOTIFY_MSG] Sending push to {$user_uuid} (role: {$role})");
            
            $push_title = "{$project_prefix}💬 {$author_name}";
            $push_body = $short_text;
            
            if (strlen($push_body) > 120) {
                $push_body = substr($push_body, 0, 117) . '…';
            }
            if (strlen($push_title) > 120) {
                $push_title = substr($push_title, 0, 117) . '…';
            }
            
            log_debug("[NOTIFY_MSG] 📦 message_uuid for push: {$message_uuid}");
            
            $result = self::sendWebPush(
                $user_uuid,
                $push_title,
                $push_body,
                $message_url,
                'message',
                $task_uuid,
                $message_uuid,
                false
            );
            
            $results[$user_uuid] = $result;
            log_debug("[NOTIFY_MSG] Push result for {$user_uuid}: " . ($result['sent'] ? 'sent' : 'skipped - ' . ($result['reason'] ?? 'unknown')));
        }
        
        log_debug("[NOTIFY_MSG] Sent to " . count($results) . " recipients");
        return $results;
    }
// ==================== BLOCK END: notifyNewMessage v1.3 ====================
}