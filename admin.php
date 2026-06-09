<?php
//admin.php version 2.2
//admin.php version 2.1: добавлена кнопка перехода в систему, добавлено логирование, убрана лишняя строка
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/smtp_func.php';
require_once __DIR__ . '/lib/mailer.php';


// ==================== BLOCK START: ajax_get_permission_details_v1.0 ====================
// ver.1.0 (2026-06-05) - ПОЛУЧЕНИЕ ДЕТАЛЕЙ ПРАВ ДЛЯ РЕДАКТИРОВАНИЯ
// ver.1.1 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_permission_details') {
    // Отключаем буферизацию вывода
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    
    // Проверяем, авторизован ли пользователь и является ли он администратором
    msgql_require_login();
    if (!msgql_is_admin()) {
        echo json_encode(['success' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $perm_uuid = $_POST['perm_uuid'] ?? '';
    $response = ['success' => false, 'error' => ''];
    
    if (empty($perm_uuid)) {
        $response['error'] = 'Не указан UUID прав';
    } else {
        $db = msgql_db();
        $stmt = $db->prepare("SELECT can_create_projects, can_edit_own_projects FROM user_project_permissions WHERE uuid = ?");
        $stmt->bind_param("s", $perm_uuid);
        $stmt->execute();
        $perm = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($perm) {
            $response = [
                'success' => true,
                'can_create_projects' => (int)$perm['can_create_projects'],
                'can_edit_own_projects' => (int)$perm['can_edit_own_projects']
            ];
        } else {
            $response['error'] = 'Права не найдены';
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
// ==================== BLOCK END: ajax_get_permission_details_v1.0 ====================

// Если это AJAX-запрос с action, устанавливаем флаг
if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

// Проверяем авторизацию (кроме страницы логина)
msgql_require_login();

// Доступ только для авторизованных
if (!$is_login) {
    header('Location: ' . $appBase . "/index.php");
    exit;
}

$active_tab = $_GET['tab'] ?? 'profile';
$error = '';
$success = '';
$min_sound_interval_sec = 6; //минимальный интервал между SSE или пуш уведомлениями
$max_sound_interval_sec = 60000; //макс интервал между SSE или пуш уведомлениями

// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

// Получаем данные текущего пользователя ДО обработки POST (нужны для уведомлений)
$db = msgql_db();
$stmt = $db->prepare("SELECT login, name, email, tel, role, alert_interval_min, alert_days, sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$current_user_data = $stmt->get_result()->fetch_assoc();

// ========== ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ОТПРАВКИ УВЕДОМЛЕНИЙ (ОБНОВЛЕНА) ==========

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
        'password_reset' => 'сброс пароля',
        'role_change' => 'изменение роли',
        'status_change' => 'изменение статуса',
        'user_update' => 'изменение данных',
        'user_create' => 'создание аккаунта',
        'permission_grant' => 'выдача прав доступа',
        'permission_revoke' => 'отзыв прав доступа'
    ][$change_type] ?? 'изменение данных';
    
    $subject = "Уведомление от {$system_title}: {$type_text}";
    
    $base_url = function_exists('msgql_get_base_url') ? msgql_get_base_url() : '';
    if (empty($base_url)) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_url = $protocol . '://' . $host;
    }
    
    $user_name = $user['name'] ?: $user['login'];
    
    // ========== ФОРМИРУЕМ ТОЛЬКО ОСНОВНОЕ СООБЩЕНИЕ (БЕЗ ПРИВЕТСТВИЯ И ПОДВАЛА) ==========
    // msgql_send_email() сама добавит приветствие "Здравствуйте, ..." и подвал
    $plain_message = "Администратор {$actor_name} выполнил действие: {$type_text}\r\n\r\n";
    $plain_message .= "Подробности:\r\n";
    $plain_message .= "----------------------------------------\r\n";
    $plain_message .= $change_details . "\r\n";
    $plain_message .= "----------------------------------------\r\n\r\n";
    $plain_message .= "Перейти в систему: {$base_url}/index.php\r\n";
    
    log_debug("[ADMIN_NOTIFY] Subject: {$subject}");
    log_debug("[ADMIN_NOTIFY] Message length: " . strlen($plain_message));
    
    // Передаём имя пользователя для приветствия в mailer.php
    $extra_data = [
        'user_name' => $user_name
    ];
    
    $result = msgql_send_email($user['email'], $subject, $plain_message, $extra_data);
    
    log_debug("[ADMIN_NOTIFY] SMTP result: " . ($result ? 'SUCCESS' : 'FAILED'));
    log_debug("[ADMIN_NOTIFY] ========== END ==========");
    
    return $result;
}

// Функция проверки прав пользователя на проект
// ==================== BLOCK START: check_user_project_permission v1.1 (SQL injection fix) ====================
// ver.1.0 (2026-06-05) - Базовая версия
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: добавлена валидация $permission_type через switch
function check_user_project_permission($user_uuid, $project_uuid, $permission_type = 'view') {
    global $is_admin;
    
    if ($is_admin) return true;
    
    $db = msgql_db();
    
    // Проверяем, является ли пользователь создателем проекта
    $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
    $stmt->bind_param("s", $project_uuid);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    if ($project && $project['created_by_uuid'] === $user_uuid) {
        return true;
    }
    
    // v1.1: Строгая валидация $permission_type через switch
    $perm_field = '';
    switch ($permission_type) {
        case 'view':
            $perm_field = 'can_view';
            break;
        case 'edit_tasks':
            $perm_field = 'can_edit_tasks';
            break;
        case 'edit_messages':
            $perm_field = 'can_edit_messages';
            break;
        case 'upload_files':
            $perm_field = 'can_upload_files';
            break;
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
// ==================== BLOCK END: check_user_project_permission v1.1 ====================

// Проверка, имеет ли пользователь доступ к задаче
// ==================== BLOCK START: check_user_task_permission v1.1 (SQL injection fix) ====================
// ver.1.0 (2026-06-05) - Базовая версия
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: валидация $permission_type перед передачей
function check_user_task_permission($user_uuid, $task_uuid, $permission_type = 'view') {
    global $is_admin;
    
    if ($is_admin) return true;
    
    // v1.1: Валидация типа разрешения перед использованием
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
// ==================== BLOCK END: check_user_task_permission v1.1 ====================

// ==================== BLOCK START: validate_and_save_email v1.0 ====================
// ver.1.0 (2026-06-09) - ЕДИНАЯ ПРОЦЕДУРА ПРОВЕРКИ И СОХРАНЕНИЯ EMAIL
// - Проверка формата email
// - Проверка запрещённых доменов (.com и другие)
// - Проверка уникальности email в БД
// - Сохранение email в БД
// - Логирование всех действий

/**
 * Единая процедура проверки и сохранения email для пользователя
 * 
 * @param mysqli $db Подключение к БД
 * @param string $user_uuid UUID пользователя (для проверки уникальности при обновлении)
 * @param string $email Email для проверки и сохранения
 * @param bool $is_new_user true - создание нового пользователя, false - обновление существующего
 * @return array ['success' => bool, 'error' => string, 'email' => string]
 */
function validate_and_save_email(mysqli $db, string $user_uuid, string $email, bool $is_new_user = false): array {
    // Запрещённые домены (можно добавлять любые)
    $blocked_domains = [
        '.com',
        '.ua',
        // Добавляйте другие домены при необходимости:
        // 'gmail.com',
    ];
    
    $original_email = $email;
    $email = trim($email);
    
    // Логируем начало проверки
    log_debug("[EMAIL_VALIDATE] Starting validation for user: {$user_uuid}, email: " . ($email ?: '(empty)'));
    
    // 1. Если email пустой - разрешено (не обязательное поле)
    if (empty($email)) {
        log_debug("[EMAIL_VALIDATE] Email is empty, skipping validation");
        
        // Сохраняем NULL в БД
        if ($is_new_user) {
            // При создании пользователя email будет сохранён позже в основном INSERT
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
    
    // 2. Проверка формата email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        log_warning("[EMAIL_VALIDATE] Invalid email format: {$email}");
        return ['success' => false, 'error' => 'Некорректный формат email адреса', 'email' => $email];
    }
    
    // 3. Приводим к нижнему регистру
    $email = strtolower($email);
    
    // 4. Проверка запрещённых доменов
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
    
    // // 5. Проверка уникальности email (только для обновления существующего пользователя)
    // if (!$is_new_user) {
    //     $check_stmt = $db->prepare("SELECT uuid FROM users WHERE email = ? AND uuid != ?");
    //     $check_stmt->bind_param("ss", $email, $user_uuid);
    //     $check_stmt->execute();
        
    //     if ($check_stmt->get_result()->num_rows > 0) {
    //         log_warning("[EMAIL_VALIDATE] Duplicate email detected: {$email}");
    //         $check_stmt->close();
    //         return ['success' => false, 'error' => 'Этот email уже используется другим пользователем', 'email' => $email];
    //     }
    //     $check_stmt->close();
    // } else {
    //     // Для нового пользователя проверяем вообще всех
    //     $check_stmt = $db->prepare("SELECT uuid FROM users WHERE email = ?");
    //     $check_stmt->bind_param("s", $email);
    //     $check_stmt->execute();
        
    //     if ($check_stmt->get_result()->num_rows > 0) {
    //         log_warning("[EMAIL_VALIDATE] Duplicate email detected for new user: {$email}");
    //         $check_stmt->close();
    //         return ['success' => false, 'error' => 'Пользователь с таким email уже существует', 'email' => $email];
    //     }
    //     $check_stmt->close();
    // }
    
    // 6. Сохраняем email
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
    
    // Для нового пользователя - возвращаем успех, сохранение будет в основном запросе
    log_debug("[EMAIL_VALIDATE] Email validation passed for new user: {$email}");
    return ['success' => true, 'error' => '', 'email' => $email];
}
// ==================== BLOCK END: validate_and_save_email v1.0 ====================


// ==================== CSRF-ЗАЩИТА ДЛЯ ВСЕХ POST-ЗАПРОСОВ ====================
// Проверяем CSRF-токен для всех мутирующих действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Действия, которые требуют CSRF-проверки (все мутирующие)
    $mutating_actions = [
        'update_profile', 'change_password', 'user_update', 
        'permission_update', 'subscriber_update'
    ];
    
    $action = $_POST['action'] ?? '';
    if (in_array($action, $mutating_actions)) {
        msgql_csrf_check_and_exit();
    }
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// ==================== BLOCK START: update_profile with email validation v1.0 ====================
// ver.1.0 (2026-06-09) - ИНТЕГРАЦИЯ ПРОВЕРКИ EMAIL В ОБНОВЛЕНИЕ ПРОФИЛЯ
// - Вызов единой процедуры validate_and_save_email()
// - Сохранение остальных полей без изменений

    if ($_POST['action'] === 'update_profile') {
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
        
        $db = msgql_db();
        
        // Проверка уникальности логина
        $check = $db->prepare("SELECT uuid FROM users WHERE login = ? AND uuid != ?");
        $check->bind_param("ss", $login, $current_user_uuid);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'Этот логин уже занят';
        }
        
        // ========== ПРОВЕРКА И СОХРАНЕНИЕ EMAIL ==========
        if (empty($error)) {
            $email_result = validate_and_save_email($db, $current_user_uuid, $email, false);
            
            if (!$email_result['success']) {
                $error = $email_result['error'];
                log_warning("[PROFILE_UPDATE] Email validation failed: {$error}");
            } else {
                // Email успешно сохранён функцией validate_and_save_email
                $email = $email_result['email']; // может быть null если был очищен
                log_debug("[PROFILE_UPDATE] Email validation passed, saved email: " . ($email ?: 'null'));
            }
        }
        // ========== КОНЕЦ ПРОВЕРКИ EMAIL ==========
        
        if (empty($error)) {
            // Обновляем остальные поля (email уже обновлён в validate_and_save_email)
            $stmt = $db->prepare("UPDATE users SET name = ?, tel = ?, login = ?, alert_interval_min = ?, alert_days = ?, sound_enabled = ?, sound_interval_sec = ? WHERE uuid = ?");
            $stmt->bind_param("sssisiis", $name, $tel, $login, $alert_interval_min, $alert_days, $sound_enabled, $sound_interval_sec, $current_user_uuid);
            
            if ($stmt->execute()) {
                $_SESSION['login'] = $login;
                $success = 'Личные данные обновлены.';
                $current_user_data['alert_interval_min'] = $alert_interval_min;
                $current_user_data['alert_days'] = $alert_days;
                $current_user_data['sound_enabled'] = $sound_enabled;
                $current_user_data['sound_interval_sec'] = $sound_interval_sec;
                
                log_debug("[PROFILE_UPDATE] Profile updated successfully for user: {$current_user_uuid}");
                
                // ========== ПРОВЕРКА ПАРОЛЯ ДЛЯ force_password_change ==========
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
                // ========== КОНЕЦ ПРОВЕРКИ ==========
                
                ?>
                <script nonce="<?= CSP_NONCE ?>">
                (function() {
                    if (typeof window.userSettings === 'undefined') {
                        window.userSettings = {};
                    }
                    
                    window.userSettings.soundEnabled = <?= $sound_enabled ? 'true' : 'false' ?>;
                    window.userSettings.soundIntervalSec = <?= (int)$sound_interval_sec ?>;
                    
                    sessionStorage.setItem('sse_sound_enabled', window.userSettings.soundEnabled ? '1' : '0');
                    sessionStorage.setItem('sse_sound_interval', window.userSettings.soundIntervalSec);
                    
                    if (window.SSE && typeof window.SSE.updateSoundSettings === 'function') {
                        window.SSE.updateSoundSettings(
                            window.userSettings.soundEnabled,
                            window.userSettings.soundIntervalSec
                        );
                    }
                    
                    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                        navigator.serviceWorker.controller.postMessage({
                            type: 'updateSoundSettings',
                            soundEnabled: window.userSettings.soundEnabled,
                            soundIntervalSec: window.userSettings.soundIntervalSec
                        });
                    }
                    
                    console.log('[ADMIN] Sound settings saved:', window.userSettings);
                })();
                </script>
                <?php
            } else {
                $error = 'Ошибка при сохранении: ' . $db->error;
                log_error("[PROFILE_UPDATE] DB error: " . $db->error);
            }
            $stmt->close();
        }
    }
    // ==================== BLOCK END: update_profile with email validation v1.0 ====================

        
    // ==================== BLOCK START: change_password handler v3.0 (with audit logging) ====================
    // ver.2.1 (2026-06-03) - Упрощённая версия без pass_version
    // ver.3.0 (2026-06-05) - ДОБАВЛЕН АУДИТ СМЕНЫ ПАРОЛЯ
    // - Логирование в файл и БД при успешной смене пароля
    // - Сброс флага принудительной смены пароля

    elseif ($_POST['action'] === 'change_password') {
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
            $db = msgql_db();
            $salt_user = bin2hex(random_bytes(16));
            $hashed = msgql_password_hash($new_pass, $salt_user);
            
            $stmt = $db->prepare("UPDATE users SET pass = ?, salt = ? WHERE uuid = ?");
            $stmt->bind_param("sss", $hashed, $salt_user, $current_user_uuid);
            
            if ($stmt->execute()) {
                $success = 'Пароль успешно изменён.';
                log_debug("[PASSWORD_CHANGE] Password changed successfully for user: {$current_user_uuid}");
                
                // V3.0: АУДИТ СМЕНЫ ПАРРОЛЯ
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
                
                // V2.1: Обновляем auth_hash в текущей сессии (чтобы не разлогинивать пользователя)
                $auth_hash_data = $current_user_uuid . $hashed;
                $_SESSION['auth_hash'] = hash('sha256', $auth_hash_data);
                log_debug("[PASSWORD_CHANGE] auth_hash updated for current session");
                
                // Сбрасываем флаг принудительной смены пароля
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
    // ==================== BLOCK END: change_password handler v3.0 ====================

     
    // Обработка пользователей ТОЛЬКО ДЛЯ АДМИНИСТРАТОРА
    elseif ($_POST['action'] === 'user_update' && $is_admin) {
        $user_uuid = $_POST['user_uuid'] ?? '';
        $sub_action = $_POST['sub_action'] ?? '';
        
        $db = msgql_db();
        $actor_name = $current_user_data['name'] ?: $current_user_data['login'];
        
        if ($sub_action === 'create') {
            $role = (int)($_POST['role'] ?? 2);
            $login = trim($_POST['login'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $tel = trim($_POST['tel'] ?? '');
            $alert_interval_min = 30;
            $alert_days = '1,2,3,4,5'; // ПН-ПТ по умолчанию
            $temp_pass = bin2hex(random_bytes(6));
            
            $salt_user = bin2hex(random_bytes(16));
            $hashed = msgql_password_hash($temp_pass, $salt_user);
            $user_uuid_new = msgql_uuid_v4();
            $now = msgql_now_ms();
            $now_str = (string)$now;
            $user_tz_offset_minutes = msgql_user_timezone_offset(); // из сессии
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
            // ========== V-CRIT-01 FIX: Явное перечисление разрешенных полей ==========
            // Запрещаем обновление role и status через этот обработчик
            // Администратор может менять role и status только через специальную форму
            
            // Разрешенные поля для обновления (белый список)
            $allowed_fields = ['login', 'name', 'email', 'tel'];
            
            // Проверяем, не пытается ли запрос изменить запрещенные поля
            $forbidden_fields = ['role', 'status'];
            foreach ($forbidden_fields as $field) {
                if (isset($_POST[$field])) {
                    log_warning("[ADMIN_SECURITY] Attempt to update forbidden field '{$field}' for user {$user_uuid} by {$current_user_uuid}");
                    $error = 'Изменение роли или статуса через эту форму запрещено. Используйте специальные поля.';
                    // Не выходим, продолжаем обработку, но не обновляем запрещенные поля
                    // Удаляем запрещенные поля из POST
                    unset($_POST[$field]);
                }
            }
            
            // Получаем старые данные пользователя
            $old_stmt = $db->prepare("SELECT login, role, status, name, email, tel FROM users WHERE uuid = ?");
            $old_stmt->bind_param("s", $user_uuid);
            $old_stmt->execute();
            $old_user = $old_stmt->get_result()->fetch_assoc();
            
            // Извлекаем только разрешенные поля
            $login = trim($_POST['login'] ?? $old_user['login']);
            $name = trim($_POST['name'] ?? $old_user['name']);
            $email = trim($_POST['email'] ?? $old_user['email']);
            $tel = trim($_POST['tel'] ?? $old_user['tel']);
            
            // role и status остаются неизменными
            $role = (int)$old_user['role'];
            $status = (int)$old_user['status'];
            
            $changes = [];
            $change_details_extra = '';
            $role_names = ['Администратор', 'Менеджер', 'Контролёр'];
            $status_names = ['Активный', 'Заблокированный'];
            
            // Проверка уникальности логина
            $check = $db->prepare("SELECT uuid FROM users WHERE login = ? AND uuid != ?");
            $check->bind_param("ss", $login, $user_uuid);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = 'Логин уже занят';
            } else {
                // Обновляем ТОЛЬКО разрешенные поля
                $stmt = $db->prepare("UPDATE users SET login = ?, name = ?, email = ?, tel = ? WHERE uuid = ?");
                $stmt->bind_param("sssss", $login, $name, $email, $tel, $user_uuid);
                
                log_debug("[ADMIN_UPDATE] Updating user {$user_uuid} with allowed fields only");
                
                if ($stmt->execute()) {
                    // ==================== ОСНОВНЫЕ ИЗМЕНЕНИЯ (логин, имя, email, телефон) ====================
                    if ($old_user['login'] !== $login) $changes[] = "Логин: '{$old_user['login']}' → '{$login}'";
                    if (($old_user['name'] ?? '') !== $name) $changes[] = "Имя: '" . ($old_user['name'] ?: '—') . "' → '" . ($name ?: '—') . "'";
                    if (($old_user['email'] ?? '') !== $email) $changes[] = "Email: '" . ($old_user['email'] ?: '—') . "' → '" . ($email ?: '—') . "'";
                    if (($old_user['tel'] ?? '') !== $tel) $changes[] = "Телефон: '" . ($old_user['tel'] ?: '—') . "' → '" . ($tel ?: '—') . "'";
                    
                    // ==================== ФОРМИРУЕМ ИТОГОВОЕ ОПИСАНИЕ ДЛЯ УВЕДОМЛЕНИЯ ====================
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
            // ==================== BLOCK START: reset_password_handler_v2.0 (with audit) ====================
        // ver.1.0 - Базовая версия
        // ver.2.0 (2026-06-05) - ДОБАВЛЕН АУДИТ СБРОСА ПАРОЛЯ
        
        elseif ($sub_action === 'reset_password' && $user_uuid) {
            $new_pass = bin2hex(random_bytes(6));
            $salt_user = bin2hex(random_bytes(16));
            $hashed = msgql_password_hash($new_pass, $salt_user);
            
            $stmt = $db->prepare("UPDATE users SET pass = ?, salt = ? WHERE uuid = ?");
            $stmt->bind_param("sss", $hashed, $salt_user, $user_uuid);
            if ($stmt->execute()) {
                $change_details = "Пароль был сброшен администратором {$actor_name}.\nНовый временный пароль: {$new_pass}\n\nРекомендуем сменить пароль после первого входа.";
                send_user_change_notification($user_uuid, 'password_reset', $change_details, $actor_name);
                
                // v2.0: АУДИТ СБРОСА ПАРОЛЯ АДМИНИСТРАТОРОМ
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
        // ==================== BLOCK END: reset_password_handler_v2.0 ====================
        
        elseif ($sub_action === 'delete' && $user_uuid && $is_admin) {
            if ($user_uuid === $current_user_uuid) {
                $error = 'Нельзя удалить самого себя';
            } else {
                $change_details = "Ваш аккаунт был удалён администратором {$actor_name}.\nДоступ к системе {$system_title} утрачен.";
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
    
    // ==================== BLOCK START: permission_update_handler_v1.0 ====================
    // ver.1.0 (2026-06-05) - ДОБАВЛЕНЫ ПРАВА can_create_projects И can_edit_own_projects
    // Обработка прав доступа ТОЛЬКО ДЛЯ АДМИНИСТРАТОРА
    elseif ($_POST['action'] === 'permission_update' && $is_admin) {
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
        
        $db = msgql_db();
        $actor_name = $current_user_data['name'] ?: $current_user_data['login'];
        
        // Получаем информацию о пользователе и проекте для уведомления
        $user_info = $db->prepare("SELECT login, name, email FROM users WHERE uuid = ?");
        $user_info->bind_param("s", $user_uuid);
        $user_info->execute();
        $target_user = $user_info->get_result()->fetch_assoc();
        
        $project_info = $db->prepare("SELECT title FROM projects WHERE uuid = ?");
        $project_info->bind_param("s", $project_uuid);
        $project_info->execute();
        $project = $project_info->get_result()->fetch_assoc();
        
        if ($sub_action === 'create') {
            // Проверяем, не существует ли уже запись
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
            // Получаем информацию перед удалением
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
    // ==================== BLOCK END: permission_update_handler_v1.0 ====================

    // Обработка подписчиков задач ТОЛЬКО ДЛЯ АДМИНИСТРАТОРА
    elseif ($_POST['action'] === 'subscriber_update' && $is_admin) {
        $sub_action = $_POST['sub_action'] ?? '';
        $subscriber_id = (int)($_POST['subscriber_id'] ?? 0);
        $task_uuid = $_POST['task_uuid'] ?? '';
        $user_uuid = $_POST['user_uuid'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $db = msgql_db();
        $now = msgql_now_ms();
        $now_str = (string)$now;
        $user_tz_offset_minutes = msgql_user_timezone_offset(); // из сессии
        $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
        $stamp = msgql_stamp($user_tz_offset_hours);
        $actor_name = $current_user_data['name'] ?: $current_user_data['login'];
        
        if ($sub_action === 'create') {
            if (empty($task_uuid) || empty($user_uuid)) {
                $error = 'Заполните все обязательные поля';
            } else {
                // Проверяем, не существует ли уже подписка
                $check = $db->prepare("SELECT id FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                $check->bind_param("ss", $task_uuid, $user_uuid);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $error = 'Подписка для этого пользователя и задачи уже существует';
                } else {
                    $stmt = $db->prepare("INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssi", $task_uuid, $user_uuid, $now_str, $current_user_uuid, $is_active);
                    if ($stmt->execute()) {
                        // Получаем информацию для уведомления
                        $task_info = $db->prepare("SELECT title FROM tasks WHERE uuid = ?");
                        $task_info->bind_param("s", $task_uuid);
                        $task_info->execute();
                        $task = $task_info->get_result()->fetch_assoc();
                        
                        $user_info = $db->prepare("SELECT login, name, email FROM users WHERE uuid = ?");
                        $user_info->bind_param("s", $user_uuid);
                        $user_info->execute();
                        $target_user = $user_info->get_result()->fetch_assoc();
                        
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
                // Получаем информацию для уведомления
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
            // Получаем информацию перед удалением
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
}

// Получаем данные текущего пользователя (ещё раз для обновления после возможных изменений)
$db = msgql_db();
$stmt = $db->prepare("SELECT login, name, email, tel, role, alert_interval_min, alert_days, sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$current_user_data = $stmt->get_result()->fetch_assoc();
$current_user_role = $current_user_data['role'] ?? 2;

// Проверяем, не была ли сессия уничтожена из-за смены роли
if (!isset($_SESSION['user_uuid']) || $_SESSION['user_uuid'] !== $current_user_uuid) {
    // Сессия была уничтожена, перенаправляем на страницу входа
    header('Location: ' . $appBase . "/index.php?msg=role_changed");
    exit;
}

// Получаем список всех пользователей (только для администратора)
$users_list = [];
if ($is_admin) {
    $result = $db->query("SELECT uuid, login, name, email, tel, role, status, stamp FROM users ORDER BY id");
    $all_users = $result->fetch_all(MYSQLI_ASSOC);
    
    foreach ($all_users as $user) {
        // Администратор видит всех, включая себя
        $users_list[] = $user;
    }
}

// Получаем список проектов
$projects_list = [];
$result = $db->query("SELECT uuid, title, created_by_uuid FROM projects ORDER BY title");
$projects_list = $result->fetch_all(MYSQLI_ASSOC);

// Получаем список прав доступа (только для администратора)
$permissions_list = [];
if ($is_admin) {
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
    $permissions_list = $result->fetch_all(MYSQLI_ASSOC);
}

$role_names = ['Администратор', 'Менеджер', 'Контролёр'];
$status_names = ['Активный', 'Заблокированный'];

// Массив дней недели для отображения в форме
$weekdays = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

$current_alert_days = explode(',', $current_user_data['alert_days'] ?? '1,2,3,4,5');

// Получаем CSRF-токен для всех форм
$csrf_token = msgql_csrf_get_token();
?>

<div class="wrap">
    <div class="admin-tabs">
        <a href="?tab=profile" class="tab <?= $active_tab === 'profile' ? 'active' : '' ?>">👤 Личные данные</a>
        <?php if ($is_admin): ?>
        <a href="?tab=users" class="tab <?= $active_tab === 'users' ? 'active' : '' ?>">👥 Пользователи</a>
        <a href="?tab=permissions" class="tab <?= $active_tab === 'permissions' ? 'active' : '' ?>">🔒 Права доступа</a>
        <a href="?tab=subscribers" class="tab <?= $active_tab === 'subscribers' ? 'active' : '' ?>">🔔 Подписчики</a>
        <?php endif; ?>
    </div>
    
    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="background:#163a2a; border:1px solid #2a8a5a; color:#a0f0c0; padding:12px; border-radius:10px; margin-bottom:16px;">
            <?= nl2br(htmlspecialchars($success)) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($active_tab === 'profile'): ?>
    <!-- ========== ВКЛАДКА: ЛИЧНЫЕ ДАННЫЕ (доступна всем) ========== -->
    
        <?php if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true): ?>
        <div class="card" style="background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div style="font-size: 32px;">⚠️</div>
                <div style="flex: 1;">
                    <h3 style="color: #f87171; margin: 0 0 8px 0;">Внимание! Необходимо установить пароль</h3>
                    <p style="margin: 0; color: rgba(233,238,252,0.9); font-size: 14px;">
                        Ваша учётная запись была создана без пароля. Для безопасности системы, 
                        <strong>пожалуйста, установите новый пароль</strong> в форме ниже.
                    </p>
                    <p style="margin: 8px 0 0 0; color: #f87171; font-size: 13px;">
                        ⚠️ Пока вы не установите пароль, доступ к другим разделам будет ограничен.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3 style="margin:0 0 16px;">Личные данные</h3>
            
            <form method="post">
                <input type="hidden" name="action" value="update_profile">
                <?= msgql_csrf_form_field() ?>
                
                <label class="muted">Логин</label>
                <input type="text" name="login" value="<?= htmlspecialchars($current_user_data['login']) ?>" required>
                
                <div style="height:12px"></div>
                <label class="muted">Имя</label>
                <input type="text" name="name" value="<?= htmlspecialchars($current_user_data['name'] ?? '') ?>">
                
                <div style="height:12px"></div>
                <label class="muted">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($current_user_data['email'] ?? '') ?>">
                
                <div style="height:12px"></div>
                <label class="muted">Телефон</label>
                <input type="tel" name="tel" value="<?= htmlspecialchars($current_user_data['tel'] ?? '') ?>">
                
                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">
                <h4 style="margin:0 0 12px;">📧 Настройки email-уведомлений</h4>
                
                <label class="muted">Интервал между уведомлениями (минуты)</label>
                <input type="number" name="alert_interval_min" min="3" max="5000" value="<?= $current_user_data['alert_interval_min'] ?? 30 ?>">
                <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">Минимум 3 минуты, максимум 5000 минут (~3.5 дня)</div>
                
                <div style="height:12px"></div>
                <label class="muted">Дни недели для уведомлений</label>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px;">
                    <?php foreach ($weekdays as $num => $name): ?>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" name="alert_days[]" value="<?= $num ?>" <?= in_array($num, $current_alert_days) ? 'checked' : '' ?>>
                        <span><?= $name ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:6px;">По умолчанию уведомления отправляются только в рабочие дни (ПН-ПТ)</div>
                

                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">
                <h4 style="margin:0 0 12px;">🔊 Настройки звуковых уведомлений</h4>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="muted" style="display: block; margin-bottom: 6px;">Звуковые уведомления</label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="sound_enabled" value="1" <?= ($current_user_data['sound_enabled'] ?? '1') == '1' ? 'checked' : '' ?> style="margin: 0; width: 18px; height: 18px;">
                        <span>Включить звук при новых сообщениях</span>
                    </label>
                    <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">
                        При отключённой галочке — уведомления приходят без звука, но всплывающие окна и бейджики работают.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="muted" style="display: block; margin-bottom: 6px;">Интервал между звуками (секунды)</label>
                    <input type="number" name="sound_interval_sec" min="6" max="60000" value="<?= $current_user_data['sound_interval_sec'] ?? 600 ?>">
                    <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">
                        Минимум 6 секунд, максимум 60000 секунд. 
                        Звук не будет повторяться чаще указанного интервала для всех типов уведомлений (всплывающие, push, мобильные).
                    </div>
                </div>


                <!-- ==================== BLOCK START: Notification Status Display v1.1 ==================== -->
                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">

                <!-- Отдельный блок для статуса уведомлений -->
                <div style="background: rgba(79,124,255,0.08); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <h4 style="margin:0 0 12px;">📱 Статус уведомлений</h4>
                    
                    <div id="notification-status" style="font-size:13px; color:rgba(233,238,252,0.8); margin-bottom: 12px;">
                        <span id="notify-status-text">⏳ Проверка...</span>
                    </div>
                    
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="requestNotificationPermissionManual()" style="font-size: 13px; padding: 8px 16px;">
                            🔔 Запросить разрешение на уведомления
                        </button>
                        <button type="button" class="btn-secondary" onclick="runNotificationDiagnostics()" style="font-size: 12px; padding: 8px 12px;">
                            🔍 Диагностика уведомлений
                        </button>
                    </div>
                </div>

                <script nonce="<?= CSP_NONCE ?>">
                    window.currentUserUuid = '<?= $current_user_uuid ?>';
                    window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
                    logDebug('[DEBUG] currentUserUuid set to:', window.currentUserUuid);

                    function runNotificationDiagnostics() {
                        logDebug('[DIAGNOSTIC] ========== STARTING NOTIFICATION DIAGNOSTICS ==========');
                        
                        var results = [];
                        
                        // 1. Проверка Notification API
                        results.push({ test: 'Notification API', passed: 'Notification' in window, value: typeof Notification });
                        logDebug('[DIAGNOSTIC] Notification API:', 'Notification' in window);
                        
                        // 2. Проверка разрешения
                        if ('Notification' in window) {
                            results.push({ test: 'Notification permission', passed: true, value: Notification.permission });
                            logDebug('[DIAGNOSTIC] Notification permission:', Notification.permission);
                        }
                        
                        // 3. Проверка Service Worker
                        results.push({ test: 'Service Worker support', passed: 'serviceWorker' in navigator, value: 'serviceWorker' in navigator });
                        logDebug('[DIAGNOSTIC] Service Worker support:', 'serviceWorker' in navigator);
                        
                        // 4. Проверка Service Worker controller
                        if ('serviceWorker' in navigator) {
                            results.push({ test: 'Service Worker controller', passed: !!navigator.serviceWorker.controller, value: !!navigator.serviceWorker.controller });
                            logDebug('[DIAGNOSTIC] Service Worker controller:', !!navigator.serviceWorker.controller);
                        }
                        
                        // 5. Проверка checkNotificationSupport
                        results.push({ test: 'checkNotificationSupport function', passed: typeof window.checkNotificationSupport === 'function', value: typeof window.checkNotificationSupport });
                        logDebug('[DIAGNOSTIC] checkNotificationSupport:', typeof window.checkNotificationSupport === 'function');
                        
                        // 6. Проверка showServiceWorkerNotification
                        results.push({ test: 'showServiceWorkerNotification function', passed: typeof window.showServiceWorkerNotification === 'function', value: typeof window.showServiceWorkerNotification });
                        logDebug('[DIAGNOSTIC] showServiceWorkerNotification:', typeof window.showServiceWorkerNotification === 'function');
                        
                        // 7. Проверка showMobileNotification
                        results.push({ test: 'showMobileNotification function', passed: typeof window.showMobileNotification === 'function', value: typeof window.showMobileNotification });
                        logDebug('[DIAGNOSTIC] showMobileNotification:', typeof window.showMobileNotification === 'function');
                        
                        // 8. Вызов checkNotificationSupport
                        if (typeof window.checkNotificationSupport === 'function') {
                            var support = window.checkNotificationSupport();
                            results.push({ test: 'checkNotificationSupport result', passed: support.supported, value: JSON.stringify(support) });
                            logDebug('[DIAGNOSTIC] Support result:', support);
                        }
                        
                        // 9. Отправка тестового уведомления
                        if (typeof window.showServiceWorkerNotification === 'function') {
                            logDebug('[DIAGNOSTIC] Sending test notification...');
                            try {
                                window.showServiceWorkerNotification(
                                    '🔍 Диагностика',
                                    'Если вы видите это сообщение — уведомления работают!',
                                    window.APP_BASE + '/messages.php',
                                    null
                                );
                                results.push({ test: 'Test notification sent', passed: true, value: 'Sent' });
                            } catch(e) {
                                results.push({ test: 'Test notification failed', passed: false, value: e.message });
                                logDebug('[DIAGNOSTIC] Test notification error:', e.message);
                            }
                        } else if (typeof window.showMobileNotification === 'function') {
                            window.showMobileNotification(
                                '🔍 Диагностика',
                                'Если вы видите это сообщение — уведомления работают!',
                                window.APP_BASE + '/messages.php',
                                null
                            );
                            results.push({ test: 'Test notification sent (mobile)', passed: true, value: 'Sent' });
                        } else {
                            results.push({ test: 'Test notification', passed: false, value: 'No notification function available' });
                            logDebug('[DIAGNOSTIC] No notification function available');
                        }
                        
                        // 10. Проверка регистрации Service Worker через API
                        if ('serviceWorker' in navigator && navigator.serviceWorker.ready) {
                            navigator.serviceWorker.ready.then(function(registration) {
                                logDebug('[DIAGNOSTIC] Service Worker registration active:', !!registration.active);
                                logDebug('[DIAGNOSTIC] Service Worker scope:', registration.scope);
                                results.push({ test: 'Service Worker active', passed: !!registration.active, value: registration.scope });
                                showDiagnosticResults(results);
                            }).catch(function(err) {
                                logDebug('[DIAGNOSTIC] Service Worker ready error:', err.message);
                                results.push({ test: 'Service Worker ready', passed: false, value: err.message });
                                showDiagnosticResults(results);
                            });
                        } else {
                            showDiagnosticResults(results);
                        }
                        
                        function showDiagnosticResults(resultsArray) {
                            var message = '🔍 Результаты диагностики:\n';
                            var allPassed = true;
                            for (var i = 0; i < resultsArray.length; i++) {
                                var r = resultsArray[i];
                                var status = r.passed ? '✅' : '❌';
                                message += status + ' ' + r.test + ': ' + (r.value || (r.passed ? 'OK' : 'FAIL')) + '\n';
                                if (!r.passed) allPassed = false;
                            }
                            message += '\n' + (allPassed ? '✅ Все проверки пройдены!' : '❌ Есть проблемы, проверьте логи выше');
                            
                            if (typeof showToast === 'function') {
                                showToast(allPassed ? '✅ Диагностика: всё работает' : '❌ Диагностика: есть проблемы', allPassed ? 'success' : 'error');
                            }
                            
                            logDebug('[DIAGNOSTIC]\n' + message);
                            alert(message);
                        }
                    }
                </script>

                <script nonce="<?= CSP_NONCE ?>">
                    function updateNotificationStatusDisplay() {
                        var statusText = document.getElementById('notify-status-text');
                        if (!statusText) return;
                        
                        if (typeof window.checkNotificationSupport === 'function') {
                            var support = window.checkNotificationSupport();
                            
                            if (!support.supported) {
                                statusText.innerHTML = '❌ Ваш браузер не поддерживает уведомления';
                                statusText.style.color = '#f87171';
                            } else if (support.permission === 'granted') {
                                if (support.canShowWhenClosed) {
                                    statusText.innerHTML = '✅ Уведомления включены<br><span style="font-size:11px; opacity:0.7;">Уведомления будут приходить даже когда приложение свёрнуто</span>';
                                    statusText.style.color = '#4ade80';
                                } else {
                                    statusText.innerHTML = '✅ Уведомления включены<br><span style="font-size:11px; opacity:0.7;">⚠️ На iOS уведомления работают только когда приложение открыто</span>';
                                    statusText.style.color = '#f59e0b';
                                }
                            } else if (support.permission === 'denied') {
                                statusText.innerHTML = '❌ Уведомления запрещены в настройках браузера<br><span style="font-size:11px; opacity:0.7;">Пожалуйста, разрешите уведомления в настройках сайта</span>';
                                statusText.style.color = '#f87171';
                            } else {
                                statusText.innerHTML = '⏳ Уведомления не запрошены<br><span style="font-size:11px; opacity:0.7;">Система запросит разрешение автоматически</span>';
                                statusText.style.color = '#60a5fa';
                            }
                        } else {
                            statusText.innerHTML = 'ℹ️ Проверка статуса недоступна';
                        }
                    }

                    function requestNotificationPermissionManual() {
                        logDebug('[NOTIFY_MANUAL] Manual permission request triggered');
                        
                        if (!('Notification' in window)) {
                            if (typeof showToast === 'function') {
                                showToast('Ваш браузер не поддерживает уведомления', 'error');
                            } else {
                                alert('Ваш браузер не поддерживает уведомления');
                            }
                            return;
                        }
                        
                        if (Notification.permission === 'granted') {
                            if (typeof showToast === 'function') {
                                showToast('Уведомления уже разрешены', 'success');
                            } else {
                                alert('Уведомления уже разрешены');
                            }
                            updateNotificationStatusDisplay();
                            return;
                        }
                        
                        if (Notification.permission === 'denied') {
                            if (typeof showToast === 'function') {
                                showToast('Уведомления запрещены в настройках браузера. Пожалуйста, измените настройки вручную.', 'warning');
                            } else {
                                alert('Уведомления запрещены в настройках браузера. Пожалуйста, измените настройки вручную.');
                            }
                            return;
                        }
                        
                        Notification.requestPermission().then(function(permission) {
                            logDebug('[NOTIFY_MANUAL] Permission result:', permission);
                            
                            if (permission === 'granted') {
                                if (typeof showToast === 'function') {
                                    showToast('✓ Уведомления разрешены!', 'success');
                                } else {
                                    alert('✓ Уведомления разрешены!');
                                }
                                updateNotificationStatusDisplay();
                                
                                if (typeof window.showServiceWorkerNotification === 'function') {
                                    setTimeout(function() {
                                        window.showServiceWorkerNotification(
                                            'Уведомления включены',
                                            'Теперь вы будете получать уведомления о новых сообщениях',
                                            window.APP_BASE + '/messages.php',
                                            null
                                        );
                                    }, 500);
                                } else if (typeof window.showMobileNotification === 'function') {
                                    setTimeout(function() {
                                        window.showMobileNotification(
                                            'Уведомления включены',
                                            'Теперь вы будете получать уведомления о новых сообщениях',
                                            window.APP_BASE + '/messages.php',
                                            null
                                        );
                                    }, 500);
                                }
                            } else {
                                if (typeof showToast === 'function') {
                                    showToast('Уведомления не были разрешены', 'warning');
                                } else {
                                    alert('Уведомления не были разрешены');
                                }
                                updateNotificationStatusDisplay();
                            }
                        }).catch(function(err) {
                            logError('[NOTIFY_MANUAL] Error requesting permission:', err);
                            if (typeof showToast === 'function') {
                                showToast('Ошибка при запросе разрешения', 'error');
                            }
                        });
                    }

                    setTimeout(updateNotificationStatusDisplay, 1500);
                </script>
                <!-- ==================== BLOCK END: Notification Status Display v1.1 ==================== -->

                <!-- Кнопка Сохранить - теперь отдельно -->
                <button type="submit" style="margin-top: 20px;">Сохранить изменения личных настроек</button>
            </form>
        </div>
        
        <div class="card" style="margin-top:20px;">
            <h3 style="margin:0 0 16px;">Смена пароля</h3>
            
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <?= msgql_csrf_form_field() ?>

                <div style="margin-bottom: 12px;">
                    <label class="muted">Для логина:</label>
                    <input type="text" name="display_login" value="<?= htmlspecialchars($current_user_data['login'] ?? '') ?>" 
                           readonly disabled 
                           autocomplete="username"
                           style="background: rgba(255,255,255,0.05); cursor: not-allowed;">
                </div>
                
                <label class="muted">Новый пароль</label>
                <input type="password" name="new_pass" autocomplete="new-password" required minlength="10">

                <div style="height:12px"></div>
                <label class="muted">Подтверждение пароля</label>
                <input type="password" name="confirm_pass" autocomplete="new-password" required>
                
                <div style="height:20px"></div>
                <button type="submit">Сменить пароль</button>
            </form>
        </div>
    <?php endif; ?>

    
    <?php
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
    ?>
    
    <?php if ($active_tab === 'users' && $is_admin): ?>
        <!-- ========== ВКЛАДКА: УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ (только администратор) ========== -->
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">👥 Пользователи системы</h3>
                <button class="btn-primary" onclick="document.getElementById('createUserModal').style.display='flex'">+ Создать пользователя</button>
            </div>
            
            <div class="users-table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr><th>Логин</th><th>Имя</th><th>Email</th><th>Телефон</th><th>Роль</th><th>Статус</th><th>Создан</th><th>Действия</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_list as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['login']) ?></td>
                            <td><?= htmlspecialchars($user['name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(maskEmailFixedStars($user['email'] ?? '—')) ?></td>
                            <td><?= htmlspecialchars($user['tel'] ?? '—') ?></td>
                            <td><?= $role_names[$user['role']] ?? '?' ?></td>
                            <td class="status-<?= $user['status'] == 0 ? 'active' : 'blocked' ?>">
                                <?= $status_names[$user['status']] ?? '?' ?>
                            </td>
                            <td><?= htmlspecialchars($user['stamp']) ?></td>
                            <td class="actions">
                                <button class="btn-icon" onclick="editUser('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>', '<?= addslashes($user['name'] ?? '') ?>', '<?= addslashes($user['email'] ?? '') ?>', '<?= addslashes($user['tel'] ?? '') ?>', <?= $user['role'] ?>, <?= $user['status'] ?>)">✏️</button>
                                <button class="btn-icon" onclick="resetPassword('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>')">🔑</button>
                                <?php if ($user['uuid'] !== $current_user_uuid): ?>
                                <button class="btn-icon delete" onclick="deleteUser('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>')">🗑️</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Модальное окно создания пользователя -->
        <div id="createUserModal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close" onclick="document.getElementById('createUserModal').style.display='none'">&times;</span>
                <h3>Создание пользователя</h3>
                <form method="post" id="createUserForm">
                    <input type="hidden" name="action" value="user_update">
                    <input type="hidden" name="sub_action" value="create">
                    <?= msgql_csrf_form_field() ?>
                    
                    <label class="muted">Логин *</label>
                    <input type="text" name="login" required>
                    
                    <label class="muted">Имя</label>
                    <input type="text" name="name">
                    
                    <label class="muted">Email</label>
                    <input type="email" name="email">
                    
                    <label class="muted">Телефон</label>
                    <input type="tel" name="tel">
                    
                    <label class="muted">Роль</label>
                    <select name="role">
                        <option value="0">Администратор</option>
                        <option value="1">Менеджер</option>
                        <option value="2" selected>Контролёр</option>
                    </select>
                    
                    <div style="margin-top:20px;">
                        <button type="submit">Создать</button>
                        <button type="button" onclick="document.getElementById('createUserModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Модальное окно редактирования пользователя -->
        <div id="editUserModal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close" onclick="document.getElementById('editUserModal').style.display='none'">&times;</span>
                <h3>Редактирование пользователя</h3>
                <form method="post" id="editUserForm">
                    <input type="hidden" name="action" value="user_update">
                    <input type="hidden" name="sub_action" value="update">
                    <input type="hidden" name="user_uuid" id="edit_uuid">
                    <?= msgql_csrf_form_field() ?>
                    
                    <label class="muted">Логин *</label>
                    <input type="text" name="login" id="edit_login" required>
                    
                    <label class="muted">Имя</label>
                    <input type="text" name="name" id="edit_name">
                    
                    <label class="muted">Email</label>
                    <input type="email" name="email" id="edit_email">
                    
                    <label class="muted">Телефон</label>
                    <input type="tel" name="tel" id="edit_tel">
                    
                    <label class="muted">Роль</label>
                    <select name="role" id="edit_role">
                        <option value="0">Администратор</option>
                        <option value="1">Менеджер</option>
                        <option value="2">Контролёр</option>
                    </select>
                    
                    <label class="muted">Статус</label>
                    <select name="status" id="edit_status">
                        <option value="0">Активный</option>
                        <option value="2">Заблокированный</option>
                    </select>
                    
                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('editUserModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Форма сброса пароля (скрытая) -->
        <form method="post" id="resetPasswordForm" style="display:none;">
            <input type="hidden" name="action" value="user_update">
            <input type="hidden" name="sub_action" value="reset_password">
            <input type="hidden" name="user_uuid" id="reset_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>
        
        <!-- Форма удаления (скрытая) -->
        <form method="post" id="deleteUserForm" style="display:none;">
            <input type="hidden" name="action" value="user_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="user_uuid" id="delete_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>
    <?php endif; ?>
    
    <?php if ($active_tab === 'permissions' && $is_admin): ?>
        <!-- ========== ВКЛАДКА: ПРАВА ДОСТУПА (только администратор) ========== -->
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">🔒 Права доступа к проектам</h3>
                <button class="btn-primary" onclick="openCreatePermissionModal()">+ Добавить права</button>
            </div>
            
            <div class="users-table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Проект</th>
                            <th>Просмотр</th>
                            <th>Создание проектов</th>
                            <th>Правка своих проектов</th>
                            <th>Правка задач</th>
                            <th>Сообщения</th>
                            <th>Загрузка файлов</th>
                            <th>Кем выдано</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions_list as $perm): ?>
                        <tr>
                            <td><?= htmlspecialchars($perm['user_name'] ?: $perm['user_login']) ?></td>
                            <td><?= htmlspecialchars($perm['project_title']) ?></td>
                            <td class="status-<?= $perm['can_view'] ? 'active' : 'blocked' ?>"><?= $perm['can_view'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_create_projects'] ? 'active' : 'blocked' ?>"><?= $perm['can_create_projects'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_own_projects'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_own_projects'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_tasks'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_tasks'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_messages'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_messages'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_upload_files'] ? 'active' : 'blocked' ?>"><?= $perm['can_upload_files'] ? '✅ Да' : '❌ Нет' ?></td>


                            <td><?= htmlspecialchars($perm['granted_by_name'] ?: $perm['granted_by_login']) ?></td>
                            <td class="actions">
                                <button class="btn-icon" onclick="editPermission('<?= $perm['uuid'] ?>', '<?= $perm['user_uuid'] ?>', '<?= $perm['project_uuid'] ?>', <?= $perm['can_view'] ?>, <?= $perm['can_edit_tasks'] ?>, <?= $perm['can_edit_messages'] ?>, <?= $perm['can_upload_files'] ?>)">✏️</button>
                                <button class="btn-icon delete" onclick="deletePermission('<?= $perm['uuid'] ?>', '<?= addslashes($perm['user_name'] ?: $perm['user_login']) ?>', '<?= addslashes($perm['project_title']) ?>')">🗑️</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($permissions_list)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:40px;">Нет назначенных прав доступа</td><tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        

        <!-- ==================== BLOCK START: permission_modal_v1.0 ==================== -->
        <!-- Модальное окно добавления/редактирования прав -->
        <div id="permissionModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 550px;">
                <span class="close" onclick="document.getElementById('permissionModal').style.display='none'">&times;</span>
                <h3 id="permissionModalTitle">Добавление прав доступа</h3>
                <form method="post" id="permissionForm">
                    <input type="hidden" name="action" value="permission_update">
                    <input type="hidden" name="sub_action" id="perm_sub_action" value="create">
                    <input type="hidden" name="perm_uuid" id="perm_uuid" value="">
                    <?= msgql_csrf_form_field() ?>
                    
                    <label class="muted">Пользователь *</label>
                    <select name="user_uuid" id="perm_user_uuid" required>
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= $user['uuid'] ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?> (<?= $role_names[$user['role']] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label class="muted" style="margin-top:12px;">Проект *</label>
                    <select name="project_uuid" id="perm_project_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите проект --</option>
                        <?php foreach ($projects_list as $project): ?>
                        <option value="<?= $project['uuid'] ?>"><?= htmlspecialchars($project['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="permissions-group">
                        <div class="perm-item">
                            <input type="checkbox" name="can_view" id="perm_can_view" value="1" checked>
                            <span>👁️ Просмотр проекта и задач</span>
                        </div>
                        <div class="perm-item" >
                            <input type="checkbox" name="can_create_projects" id="perm_can_create_projects" value="1">
                            <span>📁 Создание проектов</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_own_projects" id="perm_can_edit_own_projects" value="1">
                            <span>✏️ Редактирование своих проектов</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_tasks" id="perm_can_edit_tasks" value="1">
                            <span>✏️ Создание и редактирование задач</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_messages" id="perm_can_edit_messages" value="1">
                            <span>💬 Написание сообщений</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_upload_files" id="perm_can_upload_files" value="1">
                            <span>📎 Загрузка файлов</span>
                        </div>
                    </div>
                    
                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('permissionModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- ==================== BLOCK END: permission_modal_v1.0 ==================== -->
        
        <!-- Форма удаления прав (скрытая) -->
        <form method="post" id="deletePermissionForm" style="display:none;">
            <input type="hidden" name="action" value="permission_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="perm_uuid" id="delete_perm_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>
    <?php endif; ?>

    <?php if ($active_tab === 'subscribers' && $is_admin): ?>
        <!-- ========== ВКЛАДКА: ПОДПИСЧИКИ ЗАДАЧ (только администратор) ========== -->
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap: wrap; gap: 12px;">
                <h3 style="margin:0;">🔔 Подписчики задач</h3>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="subscriber-search-wrapper" style="position: relative;">
                        <input type="text" id="subscriberSearchInput" placeholder="🔍 Фильтр по задаче, пользователю..." 
                               style="padding: 8px 32px 8px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: #0b1020; color: #e9eefc; min-width: 220px;">
                        <span id="subscriberSearchClear" onclick="clearSubscriberSearch()" 
                              style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: rgba(233,238,252,0.5); display: none;">✕</span>
                    </div>
                    <button class="btn-primary" onclick="openCreateSubscriberModal()">+ Добавить подписку</button>
                </div>
            </div>
            
            <div class="users-table-wrapper">
                <table class="users-table" id="subscribersTable">
                    <thead>
                        <tr>
                            <th data-column="task">Задача</th>
                            <th data-column="user">Пользователь</th>
                            <th data-column="subscribed_at">Подписан</th>
                            <th data-column="subscribed_by">Кем подписан</th>
                            <th data-column="status">Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="subscribersTableBody">
                        <?php 
                        // Получаем список подписчиков
                        $subscribers_sql = "SELECT s.*, 
                                                   t.uuid as task_uuid,
                                                   t.title as task_title,
                                                   u.login as user_login, u.name as user_name,
                                                   sb.login as subscribed_by_login, sb.name as subscribed_by_name
                                            FROM task_subscribers s
                                            JOIN tasks t ON s.task_uuid = t.uuid
                                            JOIN users u ON s.user_uuid = u.uuid
                                            JOIN users sb ON s.subscribed_by_uuid = sb.uuid
                                            ORDER BY s.subscribed_at DESC";
                        $subscribers_result = $db->query($subscribers_sql);
                        $subscribers_list = $subscribers_result->fetch_all(MYSQLI_ASSOC);
                        ?>
                        <?php foreach ($subscribers_list as $sub): ?>
                        <tr data-task="<?= htmlspecialchars(strtolower($sub['task_title'])) ?>" 
                            data-user="<?= htmlspecialchars(strtolower($sub['user_name'] ?: $sub['user_login'])) ?>"
                            data-subscribed-by="<?= htmlspecialchars(strtolower($sub['subscribed_by_name'] ?: $sub['subscribed_by_login'])) ?>"
                            data-status="<?= $sub['is_active'] ? 'active' : 'inactive' ?>">
                            <td class="task-link-cell">
                                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($sub['task_uuid']) ?>" class="task-link" title="Перейти к задаче">
                                    📋 <?= htmlspecialchars($sub['task_title']) ?>
                                </a>
                            </td>
                            <td class="user-cell">
                                <?= htmlspecialchars($sub['user_name'] ?: $sub['user_login']) ?>
                             </td>
                            <td><?= date('d.m.Y H:i', (int)($sub['subscribed_at'] / 1000)) ?></td>
                            <td><?= htmlspecialchars($sub['subscribed_by_name'] ?: $sub['subscribed_by_login']) ?></td>
                            <td class="status-<?= $sub['is_active'] ? 'active' : 'blocked' ?>">
                                <?= $sub['is_active'] ? '✅ Активна' : '❌ Отписан' ?>
                             </td>
                            <td class="actions">
                                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($sub['task_uuid']) ?>" class="btn-icon" title="Перейти к задаче" style="display: inline-flex; text-decoration: none;">🔗</a>
                                <button class="btn-icon" onclick="editSubscriber('<?= $sub['id'] ?>', '<?= $sub['task_uuid'] ?>', '<?= $sub['user_uuid'] ?>', <?= $sub['is_active'] ?>)">✏️</button>
                                <button class="btn-icon delete" onclick="deleteSubscriber('<?= $sub['id'] ?>', '<?= addslashes($sub['task_title']) ?>', '<?= addslashes($sub['user_name'] ?: $sub['user_login']) ?>')">🗑️</button>
                             </td>
                         </tr>
                        <?php endforeach; ?>
                        <?php if (empty($subscribers_list)): ?>
                        <tr id="noSubscribersRow"><td colspan="6" style="text-align:center; padding:40px;">Нет активных подписок</td></tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
            </div>
            <div id="subscriberSearchInfo" style="margin-top: 12px; font-size: 12px; color: rgba(233,238,252,0.5); text-align: center; display: none;">
                Показано <span id="filteredCount">0</span> из <span id="totalCount">0</span> записей
            </div>
        </div>
        
        <!-- Модальное окно добавления/редактирования подписки -->
        <div id="subscriberModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 550px;">
                <span class="close" onclick="document.getElementById('subscriberModal').style.display='none'">&times;</span>
                <h3 id="subscriberModalTitle">Добавление подписки</h3>
                <form method="post" id="subscriberForm">
                    <input type="hidden" name="action" value="subscriber_update">
                    <input type="hidden" name="sub_action" id="sub_sub_action" value="create">
                    <input type="hidden" name="subscriber_id" id="subscriber_id" value="">
                    <?= msgql_csrf_form_field() ?>
                    
                    <label class="muted">Задача *</label>
                    <select name="task_uuid" id="sub_task_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите задачу --</option>
                        <?php 
                        // Получаем все задачи для выбора
                        $tasks_result = $db->query("SELECT t.uuid, t.title, p.title as project_title FROM tasks t JOIN projects p ON t.project_uuid = p.uuid ORDER BY p.title, t.title");
                        $all_tasks = $tasks_result->fetch_all(MYSQLI_ASSOC);
                        foreach ($all_tasks as $task): ?>
                            <option value="<?= $task['uuid'] ?>">[<?= htmlspecialchars($task['project_title']) ?>] <?= htmlspecialchars($task['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label class="muted" style="margin-top:12px;">Пользователь *</label>
                    <select name="user_uuid" id="sub_user_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= $user['uuid'] ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?> (<?= $role_names[$user['role']] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="permissions-group" style="margin-top:16px;">
                        <div class="perm-item">
                            <input type="checkbox" name="is_active" id="sub_is_active" value="1" checked>
                            <span>✅ Активная подписка (пользователь получает уведомления)</span>
                        </div>
                    </div>
                    
                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('subscriberModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Форма удаления подписки (скрытая) -->
        <form method="post" id="deleteSubscriberForm" style="display:none;">
            <input type="hidden" name="action" value="subscriber_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="subscriber_id" id="delete_subscriber_id">
            <?= msgql_csrf_form_field() ?>
        </form>

        <!-- Стили для фильтра и ссылок -->
        <style>
            .subscriber-search-wrapper {
                position: relative;
            }
            .subscriber-search-wrapper input:focus {
                outline: none;
                border-color: #4f7cff;
            }
            .task-link-cell .task-link {
                color: #9bb7ff;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                transition: color 0.2s;
            }
            .task-link-cell .task-link:hover {
                color: #4f7cff;
                text-decoration: underline;
            }
            .highlight-row {
                background-color: rgba(79,124,255,0.15) !important;
            }
            @media (max-width: 768px) {
                .users-header {
                    flex-direction: column;
                    align-items: stretch !important;
                }
                .subscriber-search-wrapper {
                    width: 100%;
                }
                .subscriber-search-wrapper input {
                    width: 100%;
                    min-width: auto;
                }
                .users-header .btn-primary {
                    width: 100%;
                }
            }
        </style>

        <script nonce="<?= CSP_NONCE ?>">
        // ==================== ФИЛЬТРАЦИЯ ПОДПИСЧИКОВ ====================
        (function() {
            var searchInput = document.getElementById('subscriberSearchInput');
            var clearBtn = document.getElementById('subscriberSearchClear');
            var tbody = document.getElementById('subscribersTableBody');
            var noSubscribersRow = document.getElementById('noSubscribersRow');
            var searchInfo = document.getElementById('subscriberSearchInfo');
            var filteredCountSpan = document.getElementById('filteredCount');
            var totalCountSpan = document.getElementById('totalCount');
            
            if (!searchInput || !tbody) return;
            
            var allRows = [];
            var totalRows = 0;
            
            // Собираем все строки с данными (исключая "нет данных")
            function collectRows() {
                allRows = [];
                if (tbody) {
                    var rows = tbody.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        // Пропускаем строку "нет данных"
                        if (row.id === 'noSubscribersRow') return;
                        if (row.parentElement === tbody) {
                            allRows.push(row);
                        }
                    });
                }
                totalRows = allRows.length;
                if (totalCountSpan) totalCountSpan.textContent = totalRows;
            }
            
            // Функция фильтрации
            function filterSubscribers() {
                var searchTerm = searchInput.value.toLowerCase().trim();
                
                if (!tbody) return;
                
                var visibleCount = 0;
                
                if (searchTerm === '') {
                    // Показываем все строки
                    allRows.forEach(function(row) {
                        row.style.display = '';
                    });
                    visibleCount = allRows.length;
                    
                    if (clearBtn) clearBtn.style.display = 'none';
                    if (searchInfo) searchInfo.style.display = 'none';
                } else {
                    // Фильтруем
                    allRows.forEach(function(row) {
                        var taskName = (row.getAttribute('data-task') || '').toLowerCase();
                        var userName = (row.getAttribute('data-user') || '').toLowerCase();
                        var subscribedBy = (row.getAttribute('data-subscribed-by') || '').toLowerCase();
                        var status = (row.getAttribute('data-status') || '').toLowerCase();
                        
                        var matches = taskName.indexOf(searchTerm) !== -1 ||
                                      userName.indexOf(searchTerm) !== -1 ||
                                      subscribedBy.indexOf(searchTerm) !== -1 ||
                                      status.indexOf(searchTerm) !== -1;
                        
                        if (matches) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    if (clearBtn) clearBtn.style.display = 'inline-block';
                    if (searchInfo) {
                        searchInfo.style.display = 'block';
                        if (filteredCountSpan) filteredCountSpan.textContent = visibleCount;
                    }
                }
                
                // Показываем сообщение "нет результатов"
                var noDataRow = document.getElementById('noSubscribersRow');
                if (visibleCount === 0 && allRows.length > 0) {
                    if (!noDataRow) {
                        var newRow = document.createElement('tr');
                        newRow.id = 'noSubscribersRow';
                        newRow.innerHTML = '<td colspan="6" style="text-align:center; padding:40px;">🔍 Подписки не найдены</td>';
                        if (tbody) tbody.appendChild(newRow);
                    } else {
                        noDataRow.style.display = '';
                    }
                } else {
                    if (noDataRow) noDataRow.style.display = 'none';
                }
            }
            
            // Очистка поиска
            window.clearSubscriberSearch = function() {
                if (searchInput) {
                    searchInput.value = '';
                    filterSubscribers();
                    searchInput.focus();
                }
            };
            
            // Debounce для производительности
            var debounceTimer;
            function debouncedFilter() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(filterSubscribers, 250);
            }
            
            // Инициализация
            collectRows();
            
            if (searchInput) {
                searchInput.addEventListener('input', debouncedFilter);
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Escape') {
                        clearSubscriberSearch();
                    }
                });
            }
            
            // Обновляем при любых изменениях DOM (например, после редактирования)
            var observer = new MutationObserver(function() {
                collectRows();
                filterSubscribers();
            });
            
            if (tbody) {
                observer.observe(tbody, { childList: true, subtree: true });
            }
        })();
        </script>
    <?php endif; ?>


    <!-- ========== НОВАЯ КАРТОЧКА: ОЧИСТКА ФАЙЛОВ (только администратор) ========== -->
    <?php if ($is_admin): ?>
        <div class="card" style="margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0 0 8px 0;">🧹 Очистка файлов</h3>
                    <p style="margin: 0; color: rgba(233,238,252,0.7); font-size: 13px;">
                        Удаление файлов, которые не привязаны ни к одной задаче или сообщению.<br>
                        <span style="color: #f59e0b;">⚠️ Внимание! Это действие необратимо.</span>
                    </p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <a href="<?= $appBase ?>/cleanup_files.php?mode=dry_run" class="btn-secondary" style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                        🔍 Анализ (dry run)
                    </a>
                    <a href="<?= $appBase ?>/cleanup_files.php?mode=delete&confirm=yes" 
                       class="btn-danger" 
                       style="display: inline-block; padding: 10px 20px; text-decoration: none; background: #dc2626; border-radius: 8px; color: white;"
                       onclick="return confirm('ВНИМАНИЕ! Будут удалены все файлы, не привязанные к задачам или сообщениям. Продолжить?')">
                        🗑️ Удалить мертвые файлы
                    </a>
                </div>
            </div>
            <div style="margin-top: 12px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 8px; font-size: 12px; color: rgba(233,238,252,0.6);">
                💡 <strong>Как использовать:</strong> Сначала нажмите "Анализ (dry run)" для просмотра файлов, которые будут удалены. 
                Затем, если всё верно, нажмите "Удалить мертвые файлы".
            </div>
        </div>
        <?php endif; ?>

        <div class="footer muted" style="margin-top:20px;">
            <a href="<?= $appBase ?>/index.php">← На главную</a>
        </div>
    </div>
</div>

<style>
.admin-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding-bottom: 8px;
}
.admin-tabs .tab {
    padding: 10px 20px;
    border-radius: 10px 10px 0 0;
    color: rgba(233,238,252,0.7);
    text-decoration: none;
    transition: all 0.2s;
}
.admin-tabs .tab:hover {
    background: rgba(79,124,255,0.1);
    color: #e9eefc;
}
.admin-tabs .tab.active {
    background: #4f7cff;
    color: white;
}
.users-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.users-table th,
.users-table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.users-table th {
    color: rgba(233,238,252,0.6);
    font-weight: 500;
}
.users-table td {
    color: rgba(233,238,252,0.9);
}
.users-table-wrapper {
    overflow-x: auto;
}
.status-active {
    color: #4ade80;
}
.status-blocked {
    color: #f87171;
}
.actions {
    display: flex;
    gap: 8px;
}
.btn-icon {
    background: rgba(79,124,255,0.15);
    border: none;
    padding: 6px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}
.btn-icon:hover {
    background: rgba(79,124,255,0.3);
}
.btn-icon.delete:hover {
    background: rgba(248,113,113,0.3);
}
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal-content {
    background: #121a33;
    border-radius: 16px;
    padding: 24px;
    width: 90%;
    max-width: 550px;
    border: 1px solid rgba(255,255,255,0.1);
    position: relative;
}
.modal-content .close {
    position: absolute;
    top: 16px;
    right: 20px;
    font-size: 24px;
    cursor: pointer;
    color: rgba(233,238,252,0.6);
}
.modal-content .close:hover {
    color: white;
}
.modal-content label {
    margin-top: 12px;
    margin-bottom: 4px;
}
.modal-content input,
.modal-content select {
    width: 100%;
}
.modal-content button {
    margin-right: 12px;
}
.btn-primary {
    background: #4f7cff;
    border: none;
    color: white;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary:hover {
    background: #3b66e0;
}

/* Правильное выравнивание чекбоксов */
.permissions-group {
    background: rgba(79,124,255,0.08);
    padding: 12px 16px;
    border-radius: 10px;
    margin: 16px 0;
}

.permissions-group .perm-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    cursor: pointer;
}

.permissions-group .perm-item:last-child {
    border-bottom: none;
}

.permissions-group .perm-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0;
    cursor: pointer;
    flex-shrink: 0;
}

.permissions-group .perm-item span {
    font-size: 13px;
    cursor: pointer;
    color: rgba(233,238,252,0.9);
}

.permissions-group .perm-item:hover {
    background: rgba(79,124,255,0.05);
    margin: 0 -8px;
    padding-left: 8px;
    padding-right: 8px;
    border-radius: 8px;
}

/* Адаптивные табы для мобильных устройств */
@media (max-width: 768px) {
    .admin-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding-bottom: 6px;
    }
    
    .admin-tabs .tab {
        padding: 8px 12px;
        font-size: 13px;
        border-radius: 8px;
        background: rgba(79,124,255,0.1);
        flex: 1 0 auto;
        text-align: center;
        min-width: 80px;
    }
    
    .admin-tabs .tab.active {
        background: #4f7cff;
    }
    
    /* Адаптивные модальные окна */
    .modal-content {
        width: 95%;
        max-width: 95%;
        margin: 16px;
        padding: 20px;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-content h3 {
        font-size: 18px;
        margin-right: 24px;
    }
    
    .modal-content .close {
        top: 12px;
        right: 16px;
        font-size: 24px;
    }
    
    /* Адаптивные таблицы - горизонтальная прокрутка */
    .users-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .users-table {
        min-width: 600px;
    }
    
    .users-table th,
    .users-table td {
        padding: 8px 10px;
        font-size: 12px;
    }
    
    /* Кнопки действий в таблице */
    .actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    
    .btn-icon {
        padding: 6px 10px;
        font-size: 12px;
    }
    
    /* Заголовок карточки */
    .users-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start !important;
    }
    
    .users-header h3 {
        font-size: 18px;
    }
    
    .btn-primary {
        padding: 8px 14px;
        font-size: 12px;
        width: 100%;
        text-align: center;
    }
    
    /* Группы чекбоксов */
    .permissions-group {
        padding: 10px 12px;
    }
    
    .permissions-group .perm-item {
        padding: 8px 0;
        gap: 10px;
    }
    
    .permissions-group .perm-item span {
        font-size: 12px;
    }
}

@media (max-width: 480px) {
    .admin-tabs {
        gap: 4px;
    }
    
    .admin-tabs .tab {
        padding: 6px 8px;
        font-size: 11px;
        min-width: 70px;
    }
    
    .users-table th,
    .users-table td {
        padding: 6px 8px;
        font-size: 11px;
    }
    
    .btn-icon {
        padding: 4px 8px;
        font-size: 11px;
    }
    
    .modal-content {
        padding: 16px;
    }
    
    .modal-content label {
        font-size: 12px;
    }
    
    .modal-content input,
    .modal-content select {
        padding: 8px 10px;
        font-size: 13px;
    }
    
    .modal-content button {
        padding: 8px 12px;
        font-size: 13px;
    }
}
</style>

<script nonce="<?= CSP_NONCE ?>">

window.csrfToken = '<?= $csrf_token ?>';

// Функции для работы с CSRF токеном
function addCsrfToFormData(formData) {
    if (formData instanceof FormData) {
        formData.append('csrf_token', window.csrfToken);
    } else if (formData instanceof URLSearchParams) {
        formData.append('csrf_token', window.csrfToken);
    } else if (typeof formData === 'object' && formData !== null) {
        formData.csrf_token = window.csrfToken;
    }
}

function addCsrfToUrlParams(params) {
    if (params instanceof URLSearchParams) {
        params.append('csrf_token', window.csrfToken);
    } else if (typeof params === 'object' && params !== null) {
        params.csrf_token = window.csrfToken;
    }
}

function addCsrfToObject(obj) {
    obj.csrf_token = window.csrfToken;
    return obj;
}

function editUser(uuid, login, name, email, tel, role, status) {
    document.getElementById('edit_uuid').value = uuid;
    document.getElementById('edit_login').value = login;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_tel').value = tel;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_status').value = status;
    document.getElementById('editUserModal').style.display = 'flex';
}

function resetPassword(uuid, login) {
    if (confirm('Сбросить пароль пользователю ' + login + '?')) {
        document.getElementById('reset_uuid').value = uuid;
        document.getElementById('resetPasswordForm').submit();
    }
}

function deleteUser(uuid, login) {
    if (confirm('Удалить пользователя ' + login + '? Это действие необратимо.')) {
        document.getElementById('delete_uuid').value = uuid;
        document.getElementById('deleteUserForm').submit();
    }
}

// ==================== BLOCK START: permission_js_functions_v1.0 ====================
function openCreatePermissionModal() {
    document.getElementById('permissionModalTitle').innerText = 'Добавление прав доступа';
    document.getElementById('perm_sub_action').value = 'create';
    document.getElementById('perm_uuid').value = '';
    document.getElementById('perm_user_uuid').value = '';
    document.getElementById('perm_project_uuid').value = '';
    document.getElementById('perm_can_view').checked = true;
    document.getElementById('perm_can_edit_tasks').checked = false;
    document.getElementById('perm_can_edit_messages').checked = false;
    document.getElementById('perm_can_upload_files').checked = false;
    document.getElementById('perm_can_create_projects').checked = false;
    document.getElementById('perm_can_edit_own_projects').checked = false;
    document.getElementById('permissionModal').style.display = 'flex';
}

function editPermission(uuid, user_uuid, project_uuid, can_view, can_edit_tasks, can_edit_messages, can_upload_files) {
    document.getElementById('permissionModalTitle').innerText = 'Редактирование прав доступа';
    document.getElementById('perm_sub_action').value = 'update';
    document.getElementById('perm_uuid').value = uuid;
    document.getElementById('perm_user_uuid').value = user_uuid;
    document.getElementById('perm_project_uuid').value = project_uuid;
    document.getElementById('perm_can_view').checked = can_view == 1;
    document.getElementById('perm_can_edit_tasks').checked = can_edit_tasks == 1;
    document.getElementById('perm_can_edit_messages').checked = can_edit_messages == 1;
    document.getElementById('perm_can_upload_files').checked = can_upload_files == 1;
    
    // Загружаем дополнительные права через AJAX
    var formData = new URLSearchParams();
    formData.append('action', 'get_permission_details');
    formData.append('perm_uuid', uuid);
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
            document.getElementById('perm_can_create_projects').checked = d.can_create_projects == 1;
            document.getElementById('perm_can_edit_own_projects').checked = d.can_edit_own_projects == 1;
            logDebug('[PERMISSION_EDIT] Loaded extra permissions - create_projects:', d.can_create_projects, 'edit_own_projects:', d.can_edit_own_projects);
        }
    })
    .catch(function(err) {
        logDebug('[PERMISSION_EDIT] Could not load extra permissions:', err);
    });
    
    document.getElementById('permissionModal').style.display = 'flex';
}

// ==================== BLOCK END: permission_js_functions_v1.0 ====================

function deletePermission(uuid, userName, projectTitle) {
    if (confirm('Удалить права доступа пользователя "' + userName + '" к проекту "' + projectTitle + '"?')) {
        document.getElementById('delete_perm_uuid').value = uuid;
        document.getElementById('deletePermissionForm').submit();
    }
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

//подписчики
function openCreateSubscriberModal() {
    document.getElementById('subscriberModalTitle').innerText = 'Добавление подписки';
    document.getElementById('sub_sub_action').value = 'create';
    document.getElementById('subscriber_id').value = '';
    document.getElementById('sub_task_uuid').value = '';
    document.getElementById('sub_user_uuid').value = '';
    document.getElementById('sub_is_active').checked = true;
    document.getElementById('subscriberModal').style.display = 'flex';
}

function editSubscriber(id, taskUuid, userUuid, isActive) {
    document.getElementById('subscriberModalTitle').innerText = 'Редактирование подписки';
    document.getElementById('sub_sub_action').value = 'update';
    document.getElementById('subscriber_id').value = id;
    document.getElementById('sub_task_uuid').value = taskUuid;
    document.getElementById('sub_user_uuid').value = userUuid;
    document.getElementById('sub_is_active').checked = isActive == 1;
    document.getElementById('subscriberModal').style.display = 'flex';
}

function deleteSubscriber(id, taskTitle, userName) {
    if (confirm('Удалить подписку пользователя "' + userName + '" на задачу "' + taskTitle + '"?')) {
        document.getElementById('delete_subscriber_id').value = id;
        document.getElementById('deleteSubscriberForm').submit();
    }
}

</script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>