<?php
/**
 * admin_helpers.php
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from admin.php. Extracted helper functions:
 *                       send_user_change_notification, check_user_project_permission,
 *                       check_user_task_permission, validate_and_save_email,
 *                       maskEmailFixedStars, get_all_permissions, get_subscriber_stats,
 *                       get_all_subscribers, get_all_system_users, get_all_projects_list.
 */

if (!function_exists('send_user_change_notification')) {
    /**
     * Отправка email-уведомления пользователю об изменении его данных.
     *
     * @param string $user_uuid UUID пользователя-получателя
     * @param string $change_type Тип изменения (user_create, user_update, password_reset, permission_grant, permission_revoke)
     * @param string $change_details Подробности изменения (многострочный текст)
     * @param string $actor_name Имя администратора, выполнившего действие
     * @return bool Результат отправки
     */
    function send_user_change_notification($user_uuid, $change_type, $change_details, $actor_name) {
        global $system_title, $EM_Sender, $replyto;

        log_debug("[ADMIN_NOTIFY] ========== START ==========");
        log_debug("[ADMIN_NOTIFY] user_uuid: {$user_uuid}");
        log_debug("[ADMIN_NOTIFY] change_type: {$change_type}");
        log_debug("[ADMIN_NOTIFY] actor_name: {$actor_name}");

        $db = msgql_db();
        $stmt = $db->prepare("SELECT login, email, name FROM users WHERE uuid = ?");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || empty($user['email'])) {
            log_warning("[ADMIN_NOTIFY] User not found or no email: " . ($user['email'] ?? 'empty'));
            return false;
        }

        log_debug("[ADMIN_NOTIFY] Target email: {$user['email']}");

        $type_text = [
            'user_create'        => 'создание учётной записи',
            'user_update'        => 'изменение данных',
            'password_reset'     => 'сброс пароля',
            'permission_grant'   => 'выдача прав доступа',
            'permission_revoke'  => 'отзыв прав доступа',
        ][$change_type] ?? 'изменение данных';

        $subject = "Уведомление от {$system_title}: {$type_text}";

        $base_url = function_exists('msgql_get_base_url') ? msgql_get_base_url() : '';
        if (empty($base_url)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_url = $protocol . '://' . $host;
        }

        $user_name = $user['name'] ?: $user['login'];

        $plain_message = "Администратор {$actor_name} выполнил действие: {$type_text}\r\n\r\n";
        $plain_message .= "Подробности:\r\n";
        $plain_message .= "----------------------------------------\r\n";
        $plain_message .= $change_details . "\r\n";
        $plain_message .= "----------------------------------------\r\n\r\n";
        $plain_message .= "Перейти в систему: {$base_url}/index.php\r\n";

        log_debug("[ADMIN_NOTIFY] Subject: {$subject}");
        log_debug("[ADMIN_NOTIFY] Message length: " . strlen($plain_message));

        $extra_data = ['user_name' => $user_name];

        $result = msgql_send_email($user['email'], $subject, $plain_message, $extra_data);

        log_debug("[ADMIN_NOTIFY] SMTP result: " . ($result ? 'SUCCESS' : 'FAILED'));
        log_debug("[ADMIN_NOTIFY] ========== END ==========");

        return $result;
    }
}

if (!function_exists('check_user_project_permission')) {
    /**
     * Проверка прав пользователя на проект.
     *
     * @param string $user_uuid
     * @param string $project_uuid
     * @param string $permission_type view|edit_tasks|edit_messages|upload_files
     * @return bool
     */
    function check_user_project_permission($user_uuid, $project_uuid, $permission_type = 'view') {
        global $is_admin;

        if ($is_admin) return true;

        $db = msgql_db();

        $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
        $stmt->bind_param("s", $project_uuid);
        $stmt->execute();
        $project = $stmt->get_result()->fetch_assoc();
        if ($project && $project['created_by_uuid'] === $user_uuid) {
            return true;
        }

        $perm_field = '';
        switch ($permission_type) {
            case 'view':          $perm_field = 'can_view'; break;
            case 'edit_tasks':    $perm_field = 'can_edit_tasks'; break;
            case 'edit_messages': $perm_field = 'can_edit_messages'; break;
            case 'upload_files':  $perm_field = 'can_upload_files'; break;
            default:
                log_warning("[SECURITY] Invalid permission_type: {$permission_type}");
                return false;
        }

        $stmt = $db->prepare("SELECT {$perm_field} FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
        $stmt->bind_param("ss", $user_uuid, $project_uuid);
        $stmt->execute();
        $perm = $stmt->get_result()->fetch_assoc();

        return $perm && $perm[$perm_field] == 1;
    }
}

if (!function_exists('check_user_task_permission')) {
    /**
     * Проверка прав пользователя на задачу (через проект задачи).
     *
     * @param string $user_uuid
     * @param string $task_uuid
     * @param string $permission_type
     * @return bool
     */
    function check_user_task_permission($user_uuid, $task_uuid, $permission_type = 'view') {
        global $is_admin;

        if ($is_admin) return true;

        $allowed_types = ['view', 'edit_tasks', 'edit_messages', 'upload_files'];
        if (!in_array($permission_type, $allowed_types, true)) {
            log_warning("[SECURITY] Invalid permission_type in task check: {$permission_type}");
            return false;
        }

        $db = msgql_db();

        $stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();

        if (!$task) return false;

        return check_user_project_permission($user_uuid, $task['project_uuid'], $permission_type);
    }
}

if (!function_exists('validate_and_save_email')) {
    /**
     * Единая процедура проверки и сохранения email для пользователя.
     *
     * @param mysqli $db
     * @param string $user_uuid
     * @param string $email
     * @param bool $is_new_user true - создание нового пользователя (email не сохраняется, только валидируется)
     * @return array ['success' => bool, 'error' => string, 'email' => string|null]
     */
    function validate_and_save_email(mysqli $db, string $user_uuid, string $email, bool $is_new_user = false): array {
        $blocked_domains = [
            // Список запрещённых доменов (можно расширять)
            'tempmail', 'guerrillamail', '10minutemail', 'throwaway', 'mailinator',
            'trashmail', 'yopmail', 'sharklasers', 'getairmail', 'dispostable',
            'fakeinbox', 'tempinbox', 'spam4.me', 'spam.la', 'spambox',
            'discard.email', 'mailnesia', 'mailexpire', 'tempemail', 'throwam',
            'burnermail', 'tempr.email', 'mohmal', 'emailondeck', 'tempmailaddress',
            'fakemailgenerator', 'mintemail', 'mytemp', 'smellfear', 'temp-mail',
            'throwawaymail', 'wegwerfemail', 'trbvm', 'grr.la', 'inboxkitten',
        ];

        $original_email = $email;
        $email = trim($email);

        log_debug("[EMAIL_VALIDATE] Starting validation for user: {$user_uuid}, email: " . ($email ?: '(empty)'));

        if (empty($email)) {
            log_debug("[EMAIL_VALIDATE] Email is empty, skipping validation");

            if ($is_new_user) {
                return ['success' => true, 'error' => '', 'email' => null];
            } else {
                $stmt = $db->prepare("UPDATE users SET email = NULL WHERE uuid = ?");
                $stmt->bind_param("s", $user_uuid);
                $success = $stmt->execute();
                $stmt->close();

                if ($success) {
                    log_debug("[EMAIL_VALIDATE] Email cleared successfully for user: {$user_uuid}");
                    return ['success' => true, 'error' => '', 'email' => null];
                } else {
                    log_error("[EMAIL_VALIDATE] Failed to clear email: " . $db->error);
                    return ['success' => false, 'error' => 'Ошибка сохранения email', 'email' => null];
                }
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            log_warning("[EMAIL_VALIDATE] Invalid email format: {$email}");
            return ['success' => false, 'error' => 'Некорректный формат email адреса', 'email' => $email];
        }

        $email = strtolower($email);

        $is_blocked = false;
        $blocked_domain_found = '';

        foreach ($blocked_domains as $blocked) {
            if (strpos($email, $blocked) !== false) {
                $is_blocked = true;
                $blocked_domain_found = $blocked;
                break;
            }
        }

        if ($is_blocked) {
            log_warning("[EMAIL_VALIDATE] Blocked domain detected: {$blocked_domain_found} in email: {$email}");
            return ['success' => false, 'error' => "Использование домена '{$blocked_domain_found}' запрещено. Пожалуйста, используйте другой email.", 'email' => $email];
        }

        if (!$is_new_user) {
            $stmt = $db->prepare("UPDATE users SET email = ? WHERE uuid = ?");
            $stmt->bind_param("ss", $email, $user_uuid);
            $success = $stmt->execute();
            $stmt->close();

            if ($success) {
                log_debug("[EMAIL_VALIDATE] Email saved successfully: {$email} for user: {$user_uuid}");
                return ['success' => true, 'error' => '', 'email' => $email];
            } else {
                log_error("[EMAIL_VALIDATE] Failed to save email: " . $db->error);
                return ['success' => false, 'error' => 'Ошибка сохранения email: ' . $db->error, 'email' => $email];
            }
        }

        log_debug("[EMAIL_VALIDATE] Email validation passed for new user: {$email}");
        return ['success' => true, 'error' => '', 'email' => $email];
    }
}

if (!function_exists('maskEmailFixedStars')) {
    /**
     * Маскирует email, заменяя середину имени пользователя на звёздочки.
     * Сохраняет первый и последний символ до '@', домен остаётся без изменений.
     *
     * @param string|null $email
     * @return string
     */
    function maskEmailFixedStars($email) {
        if (empty($email)) {
            return '—';
        }
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return str_repeat('*', strlen($email));
        }
        $username = $parts[0];
        $domain = $parts[1];

        $maskedUsername = strlen($username) > 1
            ? $username[0] . str_repeat('*', 4) . ($username[-1] ?? '')
            : str_repeat('*', max(1, strlen($username)));

        return $maskedUsername . '@' . $domain;
    }
}

if (!function_exists('get_all_permissions')) {
    /**
     * Получить все права доступа к проектам с данными пользователей и проектов.
     *
     * @param mysqli $db
     * @return array
     */
    function get_all_permissions(mysqli $db): array {
        $perm_sql = "SELECT p.*, 
                            u.login as user_login, u.name as user_name, 
                            pr.title as project_title,
                            g.login as granted_by_login, g.name as granted_by_name
                     FROM user_project_permissions p
                     JOIN users u ON p.user_uuid = u.uuid
                     JOIN projects pr ON p.project_uuid = pr.uuid
                     JOIN users g ON p.granted_by_uuid = g.uuid
                     ORDER BY pr.title, u.login";

        $result = $db->query($perm_sql);
        if (!$result) {
            log_error("[ADMIN_HELPERS] get_all_permissions query failed: " . $db->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('get_all_subscribers')) {
    /**
     * Получить всех подписчиков задач с данными задач и пользователей.
     *
     * @param mysqli $db
     * @return array
     */
    function get_all_subscribers(mysqli $db): array {
        $sql = "SELECT s.*, 
                       t.uuid as task_uuid,
                       t.title as task_title,
                       u.login as user_login, u.name as user_name,
                       sb.login as subscribed_by_login, sb.name as subscribed_by_name
                FROM task_subscribers s
                JOIN tasks t ON s.task_uuid = t.uuid
                JOIN users u ON s.user_uuid = u.uuid
                JOIN users sb ON s.subscribed_by_uuid = sb.uuid
                ORDER BY s.subscribed_at DESC";

        $result = $db->query($sql);
        if (!$result) {
            log_error("[ADMIN_HELPERS] get_all_subscribers query failed: " . $db->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('get_subscriber_stats')) {
    /**
     * Получить агрегированную статистику по подписчикам.
     *
     * @param mysqli $db
     * @return array ['total' => int, 'active' => int, 'inactive' => int, 'unique_users' => int, 'unique_tasks' => int]
     */
    function get_subscriber_stats(mysqli $db): array {
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'unique_users' => 0, 'unique_tasks' => 0];

        $check = $db->query("SHOW TABLES LIKE 'task_subscribers'");
        if (!$check || $check->num_rows == 0) {
            return $stats;
        }

        $res = $db->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
            COUNT(DISTINCT user_uuid) as unique_users,
            COUNT(DISTINCT task_uuid) as unique_tasks
            FROM task_subscribers");
        if ($res) {
            $row = $res->fetch_assoc();
            $stats = [
                'total'        => (int)($row['total'] ?? 0),
                'active'       => (int)($row['active'] ?? 0),
                'inactive'     => (int)($row['inactive'] ?? 0),
                'unique_users' => (int)($row['unique_users'] ?? 0),
                'unique_tasks' => (int)($row['unique_tasks'] ?? 0),
            ];
        }
        return $stats;
    }
}

if (!function_exists('get_all_system_users')) {
    /**
     * Получить список всех пользователей системы (для выпадающих списков).
     *
     * @param mysqli $db
     * @return array
     */
    function get_all_system_users(mysqli $db): array {
        $result = $db->query("SELECT uuid, login, name, email, tel, role, status, stamp FROM users ORDER BY id");
        if (!$result) {
            log_error("[ADMIN_HELPERS] get_all_system_users query failed: " . $db->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('get_all_projects_list')) {
    /**
     * Получить краткий список всех проектов.
     *
     * @param mysqli $db
     * @return array
     */
    function get_all_projects_list(mysqli $db): array {
        $result = $db->query("SELECT uuid, title, created_by_uuid FROM projects ORDER BY title");
        if (!$result) {
            log_error("[ADMIN_HELPERS] get_all_projects_list query failed: " . $db->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}