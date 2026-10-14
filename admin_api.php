<?php
/**
 * admin_api.php
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from admin.php. Extracted POST/GET handlers:
 *                       handle_admin_ajax_get_permission_details(), process_admin_post().
 *                       Handles: update_profile, change_password, user_update (create/update/
 *                       reset_password/delete), permission_update (create/update/delete),
 *                       subscriber_update (create/update/delete).
 */

if (!function_exists('msgql_db')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'admin_api.php must be included after init.php']);
    exit;
}

if (!function_exists('handle_admin_ajax_get_permission_details')) {
    /**
     * AJAX-обработчик получения расширенных деталей прав доступа
     * (для модального окна редактирования прав).
     *
     * Выводит JSON и завершает работу скрипта.
     *
     * @param mysqli $db
     * @return void
     */
    function handle_admin_ajax_get_permission_details($db) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (!msgql_is_logged_in()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!msgql_is_admin()) {
            echo json_encode(['success' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $perm_uuid = $_POST['perm_uuid'] ?? '';
        $response = ['success' => false, 'error' => ''];

        if (empty($perm_uuid)) {
            $response['error'] = 'Не указан UUID прав';
        } else {
            $stmt = $db->prepare("SELECT can_create_projects, can_edit_own_projects FROM user_project_permissions WHERE uuid = ?");
            $stmt->bind_param("s", $perm_uuid);
            $stmt->execute();
            $perm = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($perm) {
                $response = [
                    'success'                 => true,
                    'can_create_projects'     => (int)$perm['can_create_projects'],
                    'can_edit_own_projects'   => (int)$perm['can_edit_own_projects'],
                ];
            } else {
                $response['error'] = 'Права не найдены';
            }
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('process_admin_post')) {
    /**
     * Обработка всех POST-действий админ-панели.
     *
     * Возвращает массив с результатом:
     *   - error         : string — текст ошибки (пусто, если нет)
     *   - success       : string — текст успеха (пусто, если нет)
     *   - sound_settings: array|null — обновлённые настройки звука (для вставки инлайн-скрипта)
     *
     * @param mysqli $db
     * @param string $current_user_uuid
     * @param bool   $is_admin
     * @param array  $current_user_data
     * @return array
     */
    function process_admin_post($db, $current_user_uuid, $is_admin, $current_user_data) {
        $result = [
            'error'          => '',
            'success'        => '',
            'sound_settings' => null,
        ];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $result;
        }

        $action = $_POST['action'] ?? '';
        if (empty($action)) {
            return $result;
        }

        $min_sound_interval_sec = 6;
        $max_sound_interval_sec = 60000;

        $error = '';
        $success = '';

        // ============================================================
        // ОБНОВЛЕНИЕ ПРОФИЛЯ
        // ============================================================
        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $tel = trim($_POST['tel'] ?? '');
            $login = trim($_POST['login'] ?? '');
            $alert_interval_min = (int)($_POST['alert_interval_min'] ?? 30);

            $alert_days = '';
            if (isset($_POST['alert_days']) && is_array($_POST['alert_days'])) {
                $alert_days = implode(',', $_POST['alert_days']);
            } else {
                $alert_days = '1,2,3,4,5';
            }

            $sound_enabled = isset($_POST['sound_enabled']) ? 1 : 0;
            $sound_interval_sec = (int)($_POST['sound_interval_sec'] ?? 600);
            if ($sound_interval_sec < $min_sound_interval_sec) $sound_interval_sec = $min_sound_interval_sec;
            if ($sound_interval_sec > $max_sound_interval_sec) $sound_interval_sec = $max_sound_interval_sec;

            if ($alert_interval_min < 3) $alert_interval_min = 3;
            if ($alert_interval_min > 5000) $alert_interval_min = 5000;

            $check = $db->prepare("SELECT uuid FROM users WHERE login = ? AND uuid != ?");
            $check->bind_param("ss", $login, $current_user_uuid);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'Этот логин уже занят';
            }

            if (empty($error)) {
                $email_result = validate_and_save_email($db, $current_user_uuid, $email, false);

                if (!$email_result['success']) {
                    $error = $email_result['error'];
                    log_warning("[PROFILE_UPDATE] Email validation failed: {$error}");
                } else {
                    $email = $email_result['email'];
                    log_debug("[PROFILE_UPDATE] Email validation passed, saved email: " . ($email ?: 'null'));
                }
            }

            if (empty($error)) {
                $stmt = $db->prepare("UPDATE users SET name = ?, tel = ?, login = ?, alert_interval_min = ?, alert_days = ?, sound_enabled = ?, sound_interval_sec = ? WHERE uuid = ?");
                $stmt->bind_param("sssisiis", $name, $tel, $login, $alert_interval_min, $alert_days, $sound_enabled, $sound_interval_sec, $current_user_uuid);

                if ($stmt->execute()) {
                    $_SESSION['login'] = $login;
                    $success = 'Личные данные обновлены.';

                    log_debug("[PROFILE_UPDATE] Profile updated successfully for user: {$current_user_uuid}");

                    $check_pass = $db->prepare("SELECT pass, salt FROM users WHERE uuid = ?");
                    $check_pass->bind_param("s", $current_user_uuid);
                    $check_pass->execute();
                    $user_pass = $check_pass->get_result()->fetch_assoc();
                    $check_pass->close();

                    if (!empty($user_pass['pass']) && !empty($user_pass['salt'])) {
                        if (isset($_SESSION['force_password_change'])) {
                            unset($_SESSION['force_password_change']);
                            log_debug("[PASSWORD_FORCE] force_password_change flag removed (password already set)");
                        }
                    }

                    $result['sound_settings'] = [
                        'enabled'  => (int)$sound_enabled,
                        'interval' => (int)$sound_interval_sec,
                    ];
                } else {
                    $error = 'Ошибка при сохранении: ' . $db->error;
                    log_error("[PROFILE_UPDATE] DB error: " . $db->error);
                }
                $stmt->close();
            }
        }

        // ============================================================
        // СМЕНА ПАРОЛЯ
        // ============================================================
        elseif ($action === 'change_password') {
            $new_pass = $_POST['new_pass'] ?? '';
            $confirm_pass = $_POST['confirm_pass'] ?? '';

            log_debug("[PASSWORD_CHANGE] Password change attempt for user: {$current_user_uuid}");

            if (strlen($new_pass) < 10) {
                $error = 'Пароль должен быть не менее 10 символов';
                log_debug("[PASSWORD_CHANGE] Failed: too short (length: " . strlen($new_pass) . ")");
            } elseif ($new_pass !== $confirm_pass) {
                $error = 'Пароли не совпадают';
                log_debug("[PASSWORD_CHANGE] Failed: passwords do not match");
            } else {
                $salt_user = bin2hex(random_bytes(16));
                $hashed = msgql_password_hash($new_pass, $salt_user);

                $stmt = $db->prepare("UPDATE users SET pass = ?, salt = ? WHERE uuid = ?");
                $stmt->bind_param("sss", $hashed, $salt_user, $current_user_uuid);

                if ($stmt->execute()) {
                    $success = 'Пароль успешно изменён.';
                    log_debug("[PASSWORD_CHANGE] Password changed successfully for user: {$current_user_uuid}");

                    if (function_exists('msgql_log_password_change')) {
                        $audit_result = msgql_log_password_change($current_user_uuid, $current_user_uuid, $db);
                        if ($audit_result) {
                            log_debug("[PASSWORD_CHANGE] Password change audited successfully");
                        } else {
                            log_warning("[PASSWORD_CHANGE] Failed to audit password change");
                        }
                    } else {
                        log_warning("[PASSWORD_CHANGE] msgql_log_password_change function not available");
                    }

                    $auth_hash_data = $current_user_uuid . $hashed;
                    $_SESSION['auth_hash'] = hash('sha256', $auth_hash_data);
                    log_debug("[PASSWORD_CHANGE] auth_hash updated for current session");

                    if (isset($_SESSION['force_password_change'])) {
                        unset($_SESSION['force_password_change']);
                        log_debug("[PASSWORD_CHANGE] force_password_change flag removed");

                        $_SESSION['flash_message'] = '✓ Пароль установлен! Теперь вам доступны все разделы.';
                        $_SESSION['flash_type'] = 'success';
                    }
                } else {
                    $error = 'Ошибка при сохранении пароля.';
                    log_error("[PASSWORD_CHANGE] Failed: DB error - " . $db->error);
                }
                $stmt->close();
            }
        }

        // ============================================================
        // ДЕЙСТВИЯ С ПОЛЬЗОВАТЕЛЯМИ (только для админа)
        // ============================================================
        elseif ($action === 'user_update' && $is_admin) {
            $user_uuid = $_POST['user_uuid'] ?? '';
            $sub_action = $_POST['sub_action'] ?? '';

            $actor_name = $current_user_data['name'] ?: $current_user_data['login'];

            if ($sub_action === 'create') {
                $role = (int)($_POST['role'] ?? 2);
                $login = trim($_POST['login'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $tel = trim($_POST['tel'] ?? '');
                $alert_interval_min = 30;
                $alert_days = '1,2,3,4,5';
                $temp_pass = bin2hex(random_bytes(6));

                $salt_user = bin2hex(random_bytes(16));
                $hashed = msgql_password_hash($temp_pass, $salt_user);
                $user_uuid_new = msgql_uuid_v4();
                $now = msgql_now_ms();
                $now_str = (string)$now;
                $user_tz_offset_minutes = msgql_user_timezone_offset();
                $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                $stamp = msgql_stamp($user_tz_offset_hours);

                $stmt = $db->prepare("INSERT INTO users (uuid, role, status, login, email, name, tel, pass, salt, alert_interval_min, alert_days, time, stamp) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sissssssisss", $user_uuid_new, $role, $login, $email, $name, $tel, $hashed, $salt_user, $alert_interval_min, $alert_days, $now_str, $stamp);

                if ($stmt->execute()) {
                    $role_names = ['Администратор', 'Менеджер', 'Контролёр'];
                    $change_details = "Логин: {$login}\nИмя: " . ($name ?: '—') . "\nEmail: " . ($email ?: '—') . "\nТелефон: " . ($tel ?: '—') . "\nРоль: " . ($role_names[$role] ?? '?') . "\nВременный пароль: {$temp_pass}";
                    $change_details .= "\n\nВнимание: Для доступа к проектам необходимо выдать права через раздел 'Права доступа' в админке.";

                    send_user_change_notification($user_uuid_new, 'user_create', $change_details, $actor_name);
                    $success = "Пользователь создан. Временный пароль: $temp_pass\n\nВнимание: Пользователь не имеет доступа ни к одному проекту. Выдайте права в разделе 'Права доступа'.";
                } else {
                    $error = 'Ошибка создания пользователя: ' . $db->error;
                }
            }
            elseif ($sub_action === 'update' && $user_uuid) {
                $old_stmt = $db->prepare("SELECT login, role, status, name, email, tel FROM users WHERE uuid = ?");
                $old_stmt->bind_param("s", $user_uuid);
                $old_stmt->execute();
                $old_user = $old_stmt->get_result()->fetch_assoc();

                $login = trim($_POST['login'] ?? $old_user['login']);
                $name = trim($_POST['name'] ?? $old_user['name']);
                $email = trim($_POST['email'] ?? $old_user['email']);
                $tel = trim($_POST['tel'] ?? $old_user['tel']);

                $changes = [];

                $check = $db->prepare("SELECT uuid FROM users WHERE login = ? AND uuid != ?");
                $check->bind_param("ss", $login, $user_uuid);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error = 'Логин уже занят';
                } else {
                    $stmt = $db->prepare("UPDATE users SET login = ?, name = ?, email = ?, tel = ? WHERE uuid = ?");
                    $stmt->bind_param("sssss", $login, $name, $email, $tel, $user_uuid);

                    log_debug("[ADMIN_UPDATE] Updating user {$user_uuid} with allowed fields only");

                    if ($stmt->execute()) {
                        if ($old_user['login'] !== $login) $changes[] = "Логин: '{$old_user['login']}' → '{$login}'";
                        if (($old_user['name'] ?? '') !== $name) $changes[] = "Имя: '" . ($old_user['name'] ?: '—') . "' → '" . ($name ?: '—') . "'";
                        if (($old_user['email'] ?? '') !== $email) $changes[] = "Email: '" . ($old_user['email'] ?: '—') . "' → '" . ($email ?: '—') . "'";
                        if (($old_user['tel'] ?? '') !== $tel) $changes[] = "Телефон: '" . ($old_user['tel'] ?: '—') . "' → '" . ($tel ?: '—') . "'";

                        $change_details = implode("\n", $changes);

                        if (!empty($changes)) {
                            send_user_change_notification($user_uuid, 'user_update', $change_details, $actor_name);
                        }

                        $success = 'Пользователь обновлён.';

                        log_debug("[ADMIN_UPDATE] User {$user_uuid} updated successfully, changes: " . count($changes));
                    } else {
                        $error = 'Ошибка обновления: ' . $db->error;
                        log_error("[ADMIN_UPDATE] Failed to update user {$user_uuid}: " . $db->error);
                    }
                }
            }
            elseif ($sub_action === 'reset_password' && $user_uuid) {
                $new_pass = bin2hex(random_bytes(6));
                $salt_user = bin2hex(random_bytes(16));
                $hashed = msgql_password_hash($new_pass, $salt_user);

                $stmt = $db->prepare("UPDATE users SET pass = ?, salt = ? WHERE uuid = ?");
                $stmt->bind_param("sss", $hashed, $salt_user, $user_uuid);
                if ($stmt->execute()) {
                    $change_details = "Пароль был сброшен администратором {$actor_name}.\nНовый временный пароль: {$new_pass}\n\nРекомендуем сменить пароль после первого входа.";
                    send_user_change_notification($user_uuid, 'password_reset', $change_details, $actor_name);

                    if (function_exists('msgql_log_password_change')) {
                        $audit_result = msgql_log_password_change($user_uuid, $current_user_uuid, $db);
                        if ($audit_result) {
                            log_debug("[PASSWORD_RESET] Admin {$actor_name} reset password for user {$user_uuid}, audit logged");
                        } else {
                            log_warning("[PASSWORD_RESET] Failed to audit password reset for user {$user_uuid}");
                        }
                    } else {
                        log_warning("[PASSWORD_RESET] msgql_log_password_change function not available");
                    }

                    $success = "Пароль сброшен. Новый пароль: $new_pass" . '.';
                } else {
                    $error = 'Ошибка сброса пароля.';
                    log_error("[PASSWORD_RESET] Failed to reset password for user {$user_uuid}: " . $db->error);
                }
            }
            elseif ($sub_action === 'delete' && $user_uuid && $is_admin) {
                if ($user_uuid === $current_user_uuid) {
                    $error = 'Нельзя удалить самого себя';
                } else {
                    $change_details = "Ваш аккаунт был удалён администратором {$actor_name}.\nДоступ к системе " . ($GLOBALS['system_title'] ?? 'ЗадаЧат') . " утрачен.";
                    send_user_change_notification($user_uuid, 'user_update', $change_details, $actor_name);

                    $stmt = $db->prepare("DELETE FROM users WHERE uuid = ?");
                    $stmt->bind_param("s", $user_uuid);
                    if ($stmt->execute()) {
                        $success = 'Пользователь удалён.';
                    } else {
                        $error = 'Ошибка удаления.';
                    }
                }
            }
        }

        // ============================================================
        // ПРАВА ДОСТУПА К ПРОЕКТАМ
        // ============================================================
        elseif ($action === 'permission_update' && $is_admin) {
            $sub_action = $_POST['sub_action'] ?? '';
            $perm_uuid = $_POST['perm_uuid'] ?? '';
            $user_uuid = $_POST['user_uuid'] ?? '';
            $project_uuid = $_POST['project_uuid'] ?? '';
            $can_view = isset($_POST['can_view']) ? 1 : 0;
            $can_edit_tasks = isset($_POST['can_edit_tasks']) ? 1 : 0;
            $can_edit_messages = isset($_POST['can_edit_messages']) ? 1 : 0;
            $can_upload_files = isset($_POST['can_upload_files']) ? 1 : 0;
            $can_create_projects = isset($_POST['can_create_projects']) ? 1 : 0;
            $can_edit_own_projects = isset($_POST['can_edit_own_projects']) ? 1 : 0;

            log_debug("[PERMISSION_UPDATE] sub_action: {$sub_action}, user_uuid: {$user_uuid}, project_uuid: {$project_uuid}");
            log_debug("[PERMISSION_UPDATE] can_create_projects: {$can_create_projects}, can_edit_own_projects: {$can_edit_own_projects}");

            $actor_name = $current_user_data['name'] ?: $current_user_data['login'];

            $user_info = $db->prepare("SELECT login, name, email FROM users WHERE uuid = ?");
            $user_info->bind_param("s", $user_uuid);
            $user_info->execute();
            $target_user = $user_info->get_result()->fetch_assoc();

            $project_info = $db->prepare("SELECT title FROM projects WHERE uuid = ?");
            $project_info->bind_param("s", $project_uuid);
            $project_info->execute();
            $project = $project_info->get_result()->fetch_assoc();

            if ($sub_action === 'create') {
                $check = $db->prepare("SELECT uuid FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
                $check->bind_param("ss", $user_uuid, $project_uuid);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error = 'Права для этого пользователя и проекта уже существуют';
                } else {
                    $new_uuid = msgql_uuid_v4();
                    $now = msgql_now_ms();
                    $now_str = (string)$now;

                    $user_tz_offset_minutes = msgql_user_timezone_offset();
                    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                    $stamp = msgql_stamp($user_tz_offset_hours);

                    $stmt = $db->prepare("INSERT INTO user_project_permissions (uuid, user_uuid, project_uuid, can_view, can_edit_tasks, can_edit_messages, can_upload_files, can_create_projects, can_edit_own_projects, granted_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssiiiiiiiss", $new_uuid, $user_uuid, $project_uuid, $can_view, $can_edit_tasks, $can_edit_messages, $can_upload_files, $can_create_projects, $can_edit_own_projects, $current_user_uuid, $now_str, $stamp);

                    if ($stmt->execute()) {
                        $rights = [];
                        if ($can_view) $rights[] = "Просмотр";
                        if ($can_edit_tasks) $rights[] = "Редактирование задач";
                        if ($can_edit_messages) $rights[] = "Написание сообщений";
                        if ($can_upload_files) $rights[] = "Загрузка файлов";
                        if ($can_create_projects) $rights[] = "Создание проектов";
                        if ($can_edit_own_projects) $rights[] = "Редактирование своих проектов";

                        $change_details = "Проект: " . ($project['title'] ?? $project_uuid) . "\nПрава: " . ($rights ? implode(", ", $rights) : "нет прав");
                        send_user_change_notification($user_uuid, 'permission_grant', $change_details, $actor_name);

                        $success = 'Права доступа добавлены.';
                        log_debug("[PERMISSION_UPDATE] Created permissions for user {$user_uuid}");
                    } else {
                        $error = 'Ошибка добавления прав: ' . $db->error;
                        log_error("[PERMISSION_UPDATE] DB error: " . $db->error);
                    }
                }
            }
            elseif ($sub_action === 'update' && $perm_uuid) {
                $stmt = $db->prepare("UPDATE user_project_permissions SET can_view = ?, can_edit_tasks = ?, can_edit_messages = ?, can_upload_files = ?, can_create_projects = ?, can_edit_own_projects = ? WHERE uuid = ?");
                $stmt->bind_param("iiiiiii", $can_view, $can_edit_tasks, $can_edit_messages, $can_upload_files, $can_create_projects, $can_edit_own_projects, $perm_uuid);

                if ($stmt->execute()) {
                    $rights = [];
                    if ($can_view) $rights[] = "Просмотр";
                    if ($can_edit_tasks) $rights[] = "Редактирование задач";
                    if ($can_edit_messages) $rights[] = "Написание сообщений";
                    if ($can_upload_files) $rights[] = "Загрузка файлов";
                    if ($can_create_projects) $rights[] = "Создание проектов";
                    if ($can_edit_own_projects) $rights[] = "Редактирование своих проектов";

                    $change_details = "Проект: " . ($project['title'] ?? $project_uuid) . "\nПрава: " . ($rights ? implode(", ", $rights) : "нет прав");
                    send_user_change_notification($user_uuid, 'permission_grant', $change_details, $actor_name);

                    $success = 'Права доступа обновлены.';
                    log_debug("[PERMISSION_UPDATE] Updated permissions for user {$user_uuid}");
                } else {
                    $error = 'Ошибка обновления прав: ' . $db->error;
                    log_error("[PERMISSION_UPDATE] DB error: " . $db->error);
                }
            }
            elseif ($sub_action === 'delete' && $perm_uuid) {
                $perm_info = $db->prepare("SELECT user_uuid, project_uuid FROM user_project_permissions WHERE uuid = ?");
                $perm_info->bind_param("s", $perm_uuid);
                $perm_info->execute();
                $perm_data = $perm_info->get_result()->fetch_assoc();

                if ($perm_data) {
                    $stmt = $db->prepare("DELETE FROM user_project_permissions WHERE uuid = ?");
                    $stmt->bind_param("s", $perm_uuid);
                    if ($stmt->execute()) {
                        $change_details = "Проект: " . ($project['title'] ?? $project_uuid) . "\nПрава полностью отозваны.";
                        send_user_change_notification($perm_data['user_uuid'], 'permission_revoke', $change_details, $actor_name);
                        $success = 'Права доступа удалены.';
                        log_debug("[PERMISSION_UPDATE] Deleted permissions for user {$perm_data['user_uuid']}");
                    } else {
                        $error = 'Ошибка удаления прав';
                    }
                } else {
                    $error = 'Запись прав не найдена';
                }
            }
        }

        // ============================================================
        // ПОДПИСЧИКИ ЗАДАЧ
        // ============================================================
        elseif ($action === 'subscriber_update' && $is_admin) {
            $sub_action = $_POST['sub_action'] ?? '';
            $subscriber_id = (int)($_POST['subscriber_id'] ?? 0);
            $task_uuid = $_POST['task_uuid'] ?? '';
            $user_uuid = $_POST['user_uuid'] ?? '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $now = msgql_now_ms();
            $now_str = (string)$now;
            $user_tz_offset_minutes = msgql_user_timezone_offset();
            $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
            $stamp = msgql_stamp($user_tz_offset_hours);
            $actor_name = $current_user_data['name'] ?: $current_user_data['login'];

            if ($sub_action === 'create') {
                if (empty($task_uuid) || empty($user_uuid)) {
                    $error = 'Заполните все обязательные поля';
                } else {
                    $check = $db->prepare("SELECT id FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                    $check->bind_param("ss", $task_uuid, $user_uuid);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $error = 'Подписка для этого пользователя и задачи уже существует';
                    } else {
                        $stmt = $db->prepare("INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssi", $task_uuid, $user_uuid, $now_str, $current_user_uuid, $is_active);
                        if ($stmt->execute()) {
                            $task_info = $db->prepare("SELECT title FROM tasks WHERE uuid = ?");
                            $task_info->bind_param("s", $task_uuid);
                            $task_info->execute();
                            $task = $task_info->get_result()->fetch_assoc();

                            $change_details = "Задача: " . ($task['title'] ?? $task_uuid) . "\nСтатус: " . ($is_active ? "Активна" : "Неактивна");
                            send_user_change_notification($user_uuid, 'permission_grant', $change_details, $actor_name);

                            $success = 'Подписка добавлена.';
                        } else {
                            $error = 'Ошибка добавления подписки: ' . $db->error;
                        }
                    }
                }
            }
            elseif ($sub_action === 'update' && $subscriber_id > 0) {
                $stmt = $db->prepare("UPDATE task_subscribers SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $subscriber_id);
                if ($stmt->execute()) {
                    $sub_info = $db->prepare("SELECT s.task_uuid, s.user_uuid, t.title as task_title FROM task_subscribers s JOIN tasks t ON s.task_uuid = t.uuid WHERE s.id = ?");
                    $sub_info->bind_param("i", $subscriber_id);
                    $sub_info->execute();
                    $sub_data = $sub_info->get_result()->fetch_assoc();

                    if ($sub_data) {
                        $change_details = "Задача: " . ($sub_data['task_title'] ?? $sub_data['task_uuid']) . "\nНовый статус: " . ($is_active ? "Активна" : "Неактивна");
                        send_user_change_notification($sub_data['user_uuid'], 'permission_grant', $change_details, $actor_name);
                    }

                    $success = 'Статус подписки обновлён.';
                } else {
                    $error = 'Ошибка обновления подписки.';
                }
            }
            elseif ($sub_action === 'delete' && $subscriber_id > 0) {
                $sub_info = $db->prepare("SELECT s.task_uuid, s.user_uuid, t.title as task_title FROM task_subscribers s JOIN tasks t ON s.task_uuid = t.uuid WHERE s.id = ?");
                $sub_info->bind_param("i", $subscriber_id);
                $sub_info->execute();
                $sub_data = $sub_info->get_result()->fetch_assoc();

                if ($sub_data) {
                    $change_details = "Задача: " . ($sub_data['task_title'] ?? $sub_data['task_uuid']) . "\nПодписка полностью удалена";
                    send_user_change_notification($sub_data['user_uuid'], 'permission_revoke', $change_details, $actor_name);
                }

                $stmt = $db->prepare("DELETE FROM task_subscribers WHERE id = ?");
                $stmt->bind_param("i", $subscriber_id);
                if ($stmt->execute()) {
                    $success = 'Подписка удалена.';
                } else {
                    $error = 'Ошибка удаления подписки.';
                }
            }
        }

        $result['error'] = $error;
        $result['success'] = $success;

        return $result;
    }
}