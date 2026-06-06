<?php
/**
//auth.php version 1.4 (ИСПРАВЛЕНА структура: закрыты все фигурные скобки)
 * 
 * ОСНОВНОЕ: использует старую надёжную архитектуру (НЕ ЗАПУСКАЕТ сессию)
 * ДОБАВЛЕНО: все новые функции из современной версии (CSRF, часовые пояса, SSE и т.д.)
 * 
 * НЕ СОДЕРЖИТ _safe_session_start() - это главная причина ошибок!
 * Сессия уже запущена в boot.php
 */

ini_set('log_errors', 1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';


// ========== V-MED-02 FIX: Защита от брутфорса (сессионный метод) ==========

/**
 * Проверяет, не превышен ли лимит попыток входа (через сессию)
 * @return array ['blocked' => bool, 'wait_seconds' => int]
 */
// ==================== BLOCK START: check_login_attempts_session v1.1 ====================
// ver.1.0 (2026-06-05) - Базовая версия
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: переиндексация массива после фильтрации
// - Исправлен подсчёт количества попыток (устранены проблемы с дырками в ключах)
// - Добавлено логирование при блокировке

function check_login_attempts_session(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['blocked' => false, 'wait_seconds' => 0];
    }
    
    $now = time();
    $window_start = $now - 900; // 15 минут
    
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    // Фильтруем старые попытки
    foreach ($_SESSION['login_attempts'] as $key => $timestamp) {
        if ($timestamp < $window_start) {
            unset($_SESSION['login_attempts'][$key]);
        }
    }
    
    // v1.1: Переиндексируем массив после удаления элементов
    $_SESSION['login_attempts'] = array_values($_SESSION['login_attempts']);
    $attempts = count($_SESSION['login_attempts']);
    
    $max_attempts = 10;
    $block_seconds = 300;
    
    if ($attempts >= $max_attempts) {
        if (isset($_SESSION['login_blocked_until']) && $_SESSION['login_blocked_until'] > $now) {
            $wait = $_SESSION['login_blocked_until'] - $now;
            log_debug("[BRUTEFORCE_SESSION] Already blocked, wait {$wait} seconds");
            return ['blocked' => true, 'wait_seconds' => $wait];
        } elseif ($attempts >= $max_attempts) {
            $_SESSION['login_blocked_until'] = $now + $block_seconds;
            log_debug("[BRUTEFORCE_SESSION] Blocked for {$block_seconds} seconds after {$attempts} attempts");
            return ['blocked' => true, 'wait_seconds' => $block_seconds];
        }
    }
    
    return ['blocked' => false, 'wait_seconds' => 0];
}
// ==================== BLOCK END: check_login_attempts_session v1.1 ====================

/**
 * Логирует неудачную попытку входа (через сессию)
 */
function log_failed_attempt_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    $_SESSION['login_attempts'][] = time();
    
    if (count($_SESSION['login_attempts']) > 10) {
        $_SESSION['login_attempts'] = array_slice($_SESSION['login_attempts'], -10);
    }
}

/**
 * Сбрасывает счетчик попыток при успешном входе
 */
function reset_login_attempts_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset($_SESSION['login_attempts']);
    unset($_SESSION['login_blocked_until']);
}


// ========== V-MED-02 FIX: Защита от брутфорса (БД-версия) ==========

/**
 * Очищает старые записи о попытках входа (старше 1 часа)
 */
function cleanup_login_attempts($db): void {
    $one_hour_ago = msgql_now_ms() - 3600000;
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < ?");
    $stmt->bind_param("i", $one_hour_ago);
    $stmt->execute();
    $stmt->close();
    log_debug("[BRUTEFORCE] Cleaned up login attempts older than 1 hour");
}

/**
 * Проверяет, не превышен ли лимит попыток входа для IP и логина
 */
function check_login_attempts($db, string $ip, string $login): array {
    $now = msgql_now_ms();
    $window_start = $now - 900000;
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt 
        FROM login_attempts 
        WHERE ip_address = ? AND login = ? AND success = 0 AND attempt_time > ?
    ");
    $stmt->bind_param("ssi", $ip, $login, $window_start);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $attempts = (int)$result['cnt'];
    $stmt->close();
    
    $max_attempts = 10;
    $block_seconds = 300;
    
    if ($attempts >= $max_attempts) {
        log_warning("[BRUTEFORCE] IP {$ip} blocked for login '{$login}' after {$attempts} attempts");
        return [
            'blocked' => true, 
            'wait_seconds' => $block_seconds,
            'attempts' => $attempts
        ];
    }
    
    return [
        'blocked' => false, 
        'wait_seconds' => 0,
        'attempts' => $attempts
    ];
}

/**
 * Логирует попытку входа в БД
 */
function log_login_attempt($db, string $ip, string $login, bool $success): void {
    $now = msgql_now_ms();
    $stmt = $db->prepare("
        INSERT INTO login_attempts (ip_address, login, attempt_time, success) 
        VALUES (?, ?, ?, ?)
    ");
    $success_int = $success ? 1 : 0;
    $stmt->bind_param("ssii", $ip, $login, $now, $success_int);
    $stmt->execute();
    $stmt->close();
}

/**
 * Получает информацию о неудачных попытках для отображения пользователю
 */
// ==================== BLOCK START: get_login_attempts_info v1.1 ====================
// ver.1.0 (2026-06-05) - Базовая версия
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: явное приведение типов и переиндексация

function get_login_attempts_info($db, string $ip, string $login): array {
    $now = msgql_now_ms();
    $window_start = $now - 900000; // 15 минут в миллисекундах
    $max_attempts = 10;
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt 
        FROM login_attempts 
        WHERE ip_address = ? AND login = ? AND success = 0 AND attempt_time > ?
    ");
    $stmt->bind_param("ssi", $ip, $login, $window_start);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $attempts = (int)$result['cnt'];
    $stmt->close();
    
    $remaining_attempts = max(0, $max_attempts - $attempts);
    $blocked_until = ($attempts >= $max_attempts) ? ($now + 300000) : 0;
    
    log_debug("[BRUTEFORCE_INFO] IP: {$ip}, login: {$login}, attempts: {$attempts}, remaining: {$remaining_attempts}");
    
    return [
        'remaining_attempts' => $remaining_attempts,
        'blocked_until' => $blocked_until
    ];
}
// ==================== BLOCK END: get_login_attempts_info v1.1 ====================

// ==================== ОСНОВНЫЕ ФУНКЦИИ АУТЕНТИФИКАЦИИ ====================

function msgql_user_touch(string $user_uuid): void {
    $db = msgql_db();
    $time = msgql_now_ms();
    $user_tz_offset_minutes = msgql_user_timezone_offset();
    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
    $stamp = msgql_stamp($user_tz_offset_hours);
    $stmt = $db->prepare("UPDATE users SET time = ?, stamp = ? WHERE uuid = ? LIMIT 1");
    $stmt->bind_param("iss", $time, $stamp, $user_uuid);
    $stmt->execute();
}

function msgql_password_hash(string $password, string $salt_user): string {
    global $salt_global;
    return password_hash($password . $salt_user . $salt_global, PASSWORD_DEFAULT);
}

function msgql_password_verify(string $password, string $salt_user, string $stored_hash): bool {
    global $salt_global;
    
    if (strlen($stored_hash) === 32 && ctype_xdigit($stored_hash)) {
        $legacy = md5($password . $salt_user . $salt_global);
        return hash_equals($stored_hash, $legacy);
    }
    
    return password_verify($password . $salt_user . $salt_global, $stored_hash);
}

function msgql_user_by_login(string $login): array {
    $db = msgql_db();
    $stmt = $db->prepare("SELECT id, uuid, login, pass, salt, role, status, email, name, tel, time_lastalert, alert_interval_min, alert_days FROM users WHERE login = ? LIMIT 1");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return $row ? $row : array();
}

function msgql_user_by_uuid(string $uuid): array {
    $db = msgql_db();
    $stmt = $db->prepare("SELECT id, uuid, login, pass, salt, role, status, email, name, tel, time_lastalert, alert_interval_min, alert_days FROM users WHERE uuid = ? LIMIT 1");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return $row ? $row : array();
}

// ==================== BLOCK START: msgql_login v2.2 ====================
// ver.2.1 (2026-06-03) - Упрощённая версия без pass_version
// ver.2.2 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА АКТИВНОСТИ СЕССИИ ПЕРЕД ОЧИСТКОЙ

function msgql_login(string $login, string $password): bool {
    log_debug("[LOGIN] === START LOGIN ATTEMPT ===");
    log_debug("[LOGIN] Login: '{$login}', Password length: " . strlen($password));
    
    $db = msgql_db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    
    cleanup_login_attempts($db);
    
    $check = check_login_attempts($db, $ip, $login);
    if ($check['blocked']) {
        $wait_seconds = $check['wait_seconds'];
        log_warning("[LOGIN] Blocked for IP {$ip}, login '{$login}' - {$check['attempts']} failed attempts");
        
        $_SESSION['flash_message'] = "Слишком много неудачных попыток входа. Попробуйте через {$wait_seconds} секунд.";
        $_SESSION['flash_type'] = 'error';
        
        usleep(2000000);
        return false;
    }
    
    $info = get_login_attempts_info($db, $ip, $login);
    $base_delay = 500000;
    $delay = $base_delay + ($info['remaining_attempts'] < 3 ? 1000000 : 0);
    
    if ($login === '') {
        usleep($delay);
        log_debug("[LOGIN] Empty login rejected");
        log_login_attempt($db, $ip, $login, false);
        return false;
    }
    
    $user = msgql_user_by_login($login);
    if (!$user) {
        usleep($delay);
        log_debug("[LOGIN] User not found: {$login}");
        log_login_attempt($db, $ip, $login, false);
        return false;
    }
    
    log_debug("[LOGIN] User found - UUID: {$user['uuid']}, status: {$user['status']}, role: {$user['role']}");
    
    if ((int)$user['status'] === 2) {
        usleep($delay);
        log_debug("[LOGIN] User blocked (status=2)");
        log_login_attempt($db, $ip, $login, false);
        return false;
    }
    
    $stored_pass = isset($user['pass']) ? (string)$user['pass'] : '';
    $is_empty_pass = ($stored_pass === '');
    
    if ($is_empty_pass) {
        if ($password !== '') {
            usleep($delay);
            log_debug("[LOGIN] Empty pass in DB but password provided - reject");
            log_login_attempt($db, $ip, $login, false);
            return false;
        }
        log_debug("[LOGIN] Empty password accepted (empty pass in DB)");
    } else {
        if ($password === '') {
            usleep($delay);
            log_debug("[LOGIN] Password empty but DB has hash - reject");
            log_login_attempt($db, $ip, $login, false);
            return false;
        }
        
        $verify_result = msgql_password_verify($password, (string)$user['salt'], $stored_pass);
        log_debug("[LOGIN] password_verify result: " . ($verify_result ? 'true' : 'false'));
        if (!$verify_result) {
            usleep($delay);
            log_login_attempt($db, $ip, $login, false);
            return false;
        }
    }
    
    log_login_attempt($db, $ip, $login, true);
    
    // v2.2: Проверка активности сессии перед очисткой
    if (session_status() !== PHP_SESSION_ACTIVE) {
        log_error("[LOGIN] Session not active!");
        return false;
    }

    // Очищаем сессию перед установкой новых данных
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_regenerate_id(true);
    
    // v2.1: НЕ храним пароль в сессии
    $_SESSION['login'] = $login;
    $_SESSION['user_uuid'] = (string)$user['uuid'];
    $_SESSION['role'] = (int)$user['role'];
    $_SESSION['authenticated'] = true;
    $_SESSION['auth_time'] = time();
    
    // Для детектирования смены пароля: сохраняем хеш из UUID + хеш пароля из БД
    if (!$is_empty_pass) {
        $auth_hash_data = $user['uuid'] . $stored_pass;
        $_SESSION['auth_hash'] = hash('sha256', $auth_hash_data);
        log_debug("[LOGIN] auth_hash generated for user with password");
    } else {
        // Для пользователей без пароля — отдельный флаг
        $_SESSION['no_password_user'] = true;
        log_debug("[LOGIN] User has no password, no_password_user flag set");
    }
    
    global $appBase;
    $_SESSION['app_base'] = $appBase;
    
    $needs_password_change = empty($user['pass']) || empty($user['salt']);
    
    if ($needs_password_change) {
        $_SESSION['force_password_change'] = true;
        log_debug("[PASSWORD_FORCE] User {$user['uuid']} has empty password, force_password_change flag set");
    } else {
        unset($_SESSION['force_password_change']);
        log_debug("[PASSWORD_FORCE] User {$user['uuid']} has password set, no force flag");
    }
    
    $cookieParams = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => 0,
            'path' => $cookieParams['path'],
            'domain' => '',
            'secure' => $cookieParams['secure'],
            'httponly' => $cookieParams['httponly'],
            'samesite' => 'Lax'
        ]
    );
    
    log_debug("[LOGIN] === LOGIN SUCCESS ===");
    log_debug("[LOGIN] Session keys set: login, user_uuid, role, authenticated, auth_time" . ($is_empty_pass ? ", no_password_user" : ", auth_hash"));
    
    msgql_user_touch((string)$user['uuid']);
    return true;
}
// ==================== BLOCK END: msgql_login v2.2 ====================


function msgql_logout(): void {
    log_debug("[LOGOUT] Starting logout process");
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cookieParams = session_get_cookie_params();
        
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }
        
        setcookie(
            session_name(),
            '',
            time() - 42000,
            '/',
            '',
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
        
        session_destroy();
        log_debug("[LOGOUT] Session destroyed");
    } else {
        log_warning("[LOGOUT] No active session");
    }
}

// ========== ФУНКЦИИ ПРОВЕРКИ СЕССИИ ==========

// ==================== BLOCK START: msgql_is_logged_in v2.2 ====================
// ver.2.1 (2026-06-03) - Упрощённая версия без pass_version
// ver.2.2 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА ТИПОВ ПЕРЕД СРАВНЕНИЕМ

function msgql_is_logged_in(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        log_error("[AUTH] Session not active in is_logged_in");
        return false;
    }
    
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        log_debug("[AUTH] Not authenticated: missing or false authenticated flag");
        return false;
    }
    
    if (!isset($_SESSION['user_uuid'])) {
        log_debug("[AUTH] Not authenticated: missing user_uuid");
        return false;
    }
    
    $user_uuid = (string)$_SESSION['user_uuid'];
    $user = msgql_user_by_uuid($user_uuid);
    
    if (!$user) {
        log_debug("[AUTH] Not authenticated: user not found for UUID {$user_uuid}");
        return false;
    }
    
    if ((int)$user['status'] === 2) {
        log_debug("[AUTH] Not authenticated: user blocked (status=2)");
        return false;
    }
    
    $stored_pass = isset($user['pass']) ? (string)$user['pass'] : '';
    
    // Случай 1: Пользователь без пароля (импортированный/новый)
    if ($stored_pass === '') {
        if (!isset($_SESSION['no_password_user']) || $_SESSION['no_password_user'] !== true) {
            log_debug("[AUTH] User has no password but session flag missing, forcing re-login");
            return false;
        }
        log_debug("[AUTH] Authenticated: user without password (no_password_user flag valid)");
        return true;
    }
    
    // Случай 2: Пользователь с паролем — проверяем auth_hash
    if (!isset($_SESSION['auth_hash'])) {
        log_debug("[AUTH] Not authenticated: user has password but auth_hash missing in session");
        return false;
    }
    
    // Вычисляем текущий хеш из данных пользователя в БД
    $current_hash_data = $user['uuid'] . $stored_pass;
    $current_hash = hash('sha256', $current_hash_data);
    $stored_hash = (string)$_SESSION['auth_hash'];
    
    // v2.2: Явное сравнение строк с учётом типов
    if ($current_hash !== $stored_hash) {
        log_debug("[AUTH] Not authenticated: auth_hash mismatch - password changed");
        log_debug("[AUTH] Expected hash: " . substr($current_hash, 0, 16) . "..., stored: " . substr($stored_hash, 0, 16) . "...");
        return false;
    }
    
    // Дополнительная проверка: время жизни сессии (опционально, 7 дней)
    if (isset($_SESSION['auth_time'])) {
        $max_session_lifetime = 604800; // 7 дней в секундах
        $session_age = time() - (int)$_SESSION['auth_time'];
        if ($session_age > $max_session_lifetime) {
            log_debug("[AUTH] Not authenticated: session expired (age: {$session_age} seconds)");
            return false;
        }
    }
    
    log_debug("[AUTH] Authenticated: user {$user_uuid}, auth_hash valid");
    return true;
}
// ==================== BLOCK END: msgql_is_logged_in v2.2 ====================

function msgql_require_login(): void {
    global $appBase;
    if (!msgql_is_logged_in()) {
        log_debug("[AUTH] Require login failed, redirecting to " . $appBase . "/index.php");
        header('Location: ' . $appBase . "/index.php");
        exit;
    }
}

function msgql_role(): int {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 999;
    }
    return isset($_SESSION['role']) ? (int)$_SESSION['role'] : 999;
}

function msgql_current_user_uuid(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return isset($_SESSION['user_uuid']) ? (string)$_SESSION['user_uuid'] : '';
}

function msgql_is_admin(): bool { return msgql_role() === 0; }
function msgql_is_manager(): bool { return msgql_role() === 1; }
function msgql_is_controller(): bool { return msgql_role() === 2; }

// ==================== НОВЫЕ ФУНКЦИИ ====================

function msgql_user_timezone_offset(): int {
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_tz_offset'])) {
        return (int)$_SESSION['user_tz_offset'];
    }
    return 0;
}

function msgql_set_user_timezone_offset($offset_minutes): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['user_tz_offset'] = (int)$offset_minutes;
    }
}

function msgql_csrf_get_token($regenerate = false) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        log_error('[CSRF] Session not active');
        return '';
    }
    
    if ($regenerate || empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

function msgql_csrf_validate_token($token = null) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        log_error('[CSRF] Session not active');
        return false;
    }
    
    if ($token === null) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? null;
        }
    }
    
    if (empty($_SESSION['csrf_token'])) {
        log_error('[CSRF] Session token missing');
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}

function msgql_csrf_check_and_exit() {
    if (!msgql_csrf_validate_token()) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
            (isset($_POST['ajax_mode']) && $_POST['ajax_mode'] == 1) ||
            isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false, 
                'error' => 'CSRF token validation failed. Please refresh the page and try again.',
                'csrf_error' => true
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $_SESSION['flash_message'] = 'Ошибка безопасности. Пожалуйста, обновите страницу.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/index.php'));
        exit;
    }
    return true;
}

function msgql_csrf_form_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(msgql_csrf_get_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function msgql_csrf_js_token() {
    return msgql_csrf_get_token();
}

function msgql_get_base_url() {
    global $appBase;
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $path = ($appBase === '/' || $appBase === '') ? '' : $appBase;
    
    return $protocol . '://' . $host . $path;
}

function msgql_send_sse_event($task_uuid, $event_type, $event_data) {
    $db = msgql_db();
    if (!$db) return false;
    
    $check = $db->query("SHOW TABLES LIKE 'sse_queue'");
    if ($check->num_rows == 0) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `sse_queue` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uuid` char(36) NOT NULL,
                `task_uuid` char(36) NOT NULL,
                `event_type` varchar(50) NOT NULL,
                `event_data` text NOT NULL,
                `created_at` bigint(20) NOT NULL,
                `processed` tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_uuid` (`uuid`),
                KEY `idx_task_processed` (`task_uuid`, `processed`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    $event_uuid = msgql_uuid_v4();
    $now = msgql_now_ms();
    $event_json = json_encode($event_data, JSON_UNESCAPED_UNICODE);
    
    $stmt = $db->prepare("
        INSERT INTO sse_queue (uuid, task_uuid, event_type, event_data, created_at, processed)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    if ($stmt) {
        $stmt->bind_param("ssssi", $event_uuid, $task_uuid, $event_type, $event_json, $now);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}

function convert_links_to_markers($text) {
    if (empty($text)) return $text;
    
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $escaped_host = preg_quote($current_host, '/');
    
    $patterns = [
        "/(https?:\/\/{$escaped_host})?\/projects\.php\?(?:task|uuid|id)=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/\/projects\.php\?(?:task|uuid|id)=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/(https?:\/\/{$escaped_host})?\/messages\.php\?(?:message|msg)=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/\/messages\.php\?(?:message|msg)=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/(https?:\/\/{$escaped_host})?\/file_preview\.php\?uuid=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/(https?:\/\/{$escaped_host})?\/download\.php\?file=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/\/file_preview\.php\?uuid=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i",
        "/\/download\.php\?file=([a-f0-9\-]{36})(?:&[^\\s<>\"']*)?/i"
    ];
    
    foreach ($patterns as $pattern) {
        $text = preg_replace_callback($pattern, function($matches) {
            $uuid = end($matches);
            if (strpos($matches[0], 'projects.php') !== false) return "[task:{$uuid}]";
            if (strpos($matches[0], 'messages.php') !== false) return "[msg:{$uuid}]";
            return "[file:{$uuid}]";
        }, $text);
    }
    
    return $text;
}

function msgql_format_file_size(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ==================== ФУНКЦИИ ПРОВЕРКИ ПРАВ ДОСТУПА ====================

// ==================== BLOCK START: msgql_can_create_project_v1.1 ====================
// ver.1.0 (2026-06-05) - ФУНКЦИЯ ПРОВЕРКИ ПРАВА НА СОЗДАНИЕ ПРОЕКТОВ
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: КОНТРОЛЁРЫ ВСЕГДА ВОЗВРАЩАЮТ FALSE
function msgql_can_create_project(string $user_uuid): bool {
    if (msgql_is_admin()) {
        log_debug("[CREATE_PROJECT_CHECK] Admin user - can create projects");
        return true;
    }
    
    // Контролёры не могут создавать проекты НИКОГДА
    if (msgql_is_controller()) {
        log_debug("[CREATE_PROJECT_CHECK] Controller user - cannot create projects");
        return false;
    }
    
    $db = msgql_db();
    
    $stmt = $db->prepare("SELECT can_create_projects FROM user_project_permissions WHERE user_uuid = ? LIMIT 1");
    $stmt->bind_param("s", $user_uuid);
    $stmt->execute();
    $perm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $can_create = $perm && (int)$perm['can_create_projects'] === 1;
    log_debug("[CREATE_PROJECT_CHECK] User {$user_uuid} can create projects: " . ($can_create ? 'true' : 'false'));
    
    return $can_create;
}
// ==================== BLOCK END: msgql_can_create_project_v1.1 ====================

// ==================== BLOCK START: msgql_can_edit_project_v1.1 ====================
// ver.1.0 (2026-06-05) - ФУНКЦИЯ ПРОВЕРКИ ПРАВА НА РЕДАКТИРОВАНИЕ ПРОЕКТА
// ver.1.1 (2026-06-05) - ИСПРАВЛЕНИЕ: КОНТРОЛЁРЫ ВСЕГДА ВОЗВРАЩАЮТ FALSE
function msgql_can_edit_project(string $user_uuid, string $project_uuid): bool {
    log_debug("[EDIT_PROJECT_CHECK] Checking edit permission for user {$user_uuid} on project {$project_uuid}");
    
    if (msgql_is_admin()) {
        log_debug("[EDIT_PROJECT_CHECK] Admin user - can edit project");
        return true;
    }
    
    // Контролёры не могут редактировать проекты НИКОГДА
    if (msgql_is_controller()) {
        log_debug("[EDIT_PROJECT_CHECK] Controller user - cannot edit projects");
        return false;
    }
    
    $db = msgql_db();
    
    // Проверяем, является ли пользователь создателем проекта
    $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
    $stmt->bind_param("s", $project_uuid);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $is_creator = ($project && $project['created_by_uuid'] === $user_uuid);
    
    if ($is_creator) {
        // Создатель может редактировать только если есть право edit_own_projects
        $stmt = $db->prepare("SELECT can_edit_own_projects FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
        $stmt->bind_param("ss", $user_uuid, $project_uuid);
        $stmt->execute();
        $perm = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $can_edit = $perm && (int)$perm['can_edit_own_projects'] === 1;
        log_debug("[EDIT_PROJECT_CHECK] Creator user - edit_own_projects: " . ($can_edit ? 'granted' : 'denied'));
        return $can_edit;
    }
    
    // Не создатель - проверяем общее право edit_tasks (включает управление проектом)
    $stmt = $db->prepare("SELECT can_edit_tasks FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
    $stmt->bind_param("ss", $user_uuid, $project_uuid);
    $stmt->execute();
    $perm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $can_edit = $perm && (int)$perm['can_edit_tasks'] === 1;
    log_debug("[EDIT_PROJECT_CHECK] Non-creator user - can_edit_tasks: " . ($can_edit ? 'granted' : 'denied'));
    
    return $can_edit;
}
// ==================== BLOCK END: msgql_can_edit_project_v1.1 ====================



// ==================== BLOCK START: msgql_can_access_project_v2.2 (with audit logging) ====================
// ver.1.0 - Базовая версия
// ver.2.0 (2026-06-05) - ДОБАВЛЕНЫ ПРАВА create_projects И edit_own_projects
// ver.2.1 (2026-06-05) - ИСПРАВЛЕНИЕ: КОНТРОЛЁРЫ НЕ МОГУТ СОЗДАВАТЬ/РЕДАКТИРОВАТЬ ПРОЕКТЫ
// ver.2.2 (2026-06-05) - ДОБАВЛЕН АУДИТ НЕУДАЧНЫХ ПОПЫТОК ДОСТУПА

function msgql_can_access_project(string $user_uuid, string $project_uuid, string $permission = 'view'): bool {
    log_debug("[ACCESS_CHECK] Checking permission '{$permission}' for user {$user_uuid} on project {$project_uuid}");
    
    if (msgql_is_admin()) {
        log_debug("[ACCESS_CHECK] Admin user - access granted");
        return true;
    }
    
    $db = msgql_db();
    
    $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
    $stmt->bind_param("s", $project_uuid);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $is_creator = ($project && $project['created_by_uuid'] === $user_uuid);
    
    $user_role = msgql_role();
    $is_controller = ($user_role === 2);
    
    if ($permission === 'edit_own_projects') {
        if ($is_controller) {
            //log_warning("[ACCESS_AUDIT] Controller user {$user_uuid} denied edit_own_projects on project {$project_uuid}");
            log_debug("[ACCESS_CHECK] Controller user - edit_own_projects ALWAYS DENIED");
            return false;
        }
        if ($is_creator) {
            $stmt = $db->prepare("SELECT can_edit_own_projects FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
            $stmt->bind_param("ss", $user_uuid, $project_uuid);
            $stmt->execute();
            $perm = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $has_permission = $perm && (int)$perm['can_edit_own_projects'] === 1;
            if (!$has_permission) {
                //log_warning("[ACCESS_AUDIT] Creator user {$user_uuid} denied edit_own_projects on project {$project_uuid}");
            }
            log_debug("[ACCESS_CHECK] Creator user - edit_own_projects: " . ($has_permission ? 'granted' : 'denied'));
            return $has_permission;
        }
        //log_warning("[ACCESS_AUDIT] Non-creator user {$user_uuid} denied edit_own_projects on project {$project_uuid}");
        log_debug("[ACCESS_CHECK] Not creator - edit_own_projects denied");
        return false;
    }
    
    if ($permission === 'create_projects') {
        if ($is_controller) {
            //log_warning("[ACCESS_AUDIT] Controller user {$user_uuid} denied create_projects");
            log_debug("[ACCESS_CHECK] Controller user - create_projects ALWAYS DENIED");
            return false;
        }
        $stmt = $db->prepare("SELECT can_create_projects FROM user_project_permissions WHERE user_uuid = ? LIMIT 1");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $perm = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $has_permission = $perm && (int)$perm['can_create_projects'] === 1;
        if (!$has_permission) {
            //log_warning("[ACCESS_AUDIT] User {$user_uuid} denied create_projects (no permission)");
        }
        log_debug("[ACCESS_CHECK] create_projects permission: " . ($has_permission ? 'granted' : 'denied'));
        return $has_permission;
    }
    
    $perm_field = 'can_view';
    switch ($permission) {
        case 'view':          $perm_field = 'can_view'; break;
        case 'edit_tasks':    $perm_field = 'can_edit_tasks'; break;
        case 'edit_messages': $perm_field = 'can_edit_messages'; break;
        case 'upload_files':  $perm_field = 'can_upload_files'; break;
        default:              
            //log_warning("[ACCESS_AUDIT] Unknown permission type '{$permission}' requested by user {$user_uuid}");
            return false;
    }
    
    if ($permission === 'edit_tasks' && $is_controller) {
        //log_warning("[ACCESS_AUDIT] Controller user {$user_uuid} denied edit_tasks on project {$project_uuid}");
        log_debug("[ACCESS_CHECK] Controller user - edit_tasks ALWAYS DENIED");
        return false;
    }
    
    $stmt = $db->prepare("SELECT `$perm_field` FROM user_project_permissions WHERE user_uuid = ? AND project_uuid = ?");
    $stmt->bind_param("ss", $user_uuid, $project_uuid);
    $stmt->execute();
    $perm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $has_permission = $perm && (int)$perm[$perm_field] === 1;
    
    if (!$has_permission) {
        //log_warning("[ACCESS_AUDIT] User {$user_uuid} denied {$perm_field} on project {$project_uuid}");
    }
    
    log_debug("[ACCESS_CHECK] {$perm_field} permission: " . ($has_permission ? 'granted' : 'denied'));
    
    return $has_permission;
}
// ==================== BLOCK END: msgql_can_access_project_v2.2 ====================

function msgql_can_access_task(string $user_uuid, string $task_uuid, string $permission = 'view'): bool {
    if (msgql_is_admin()) return true;
    $db = msgql_db();
    $stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $stmt->bind_param("s", $task_uuid);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    
    if (!$task) return false;
    return msgql_can_access_project($user_uuid, $task['project_uuid'], $permission);
}

function msgql_get_accessible_projects(string $user_uuid): array {
    $db = msgql_db();
    
    if (msgql_is_admin()) {
        $sql = "SELECT p.*, u.name as creator_name, u.login as creator_login,
                       (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
                FROM projects p 
                LEFT JOIN users u ON p.created_by_uuid = u.uuid 
                ORDER BY p.time DESC";
        $result = $db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $sql = "SELECT DISTINCT p.*, u.name as creator_name, u.login as creator_login,
                   (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
            FROM projects p
            LEFT JOIN users u ON p.created_by_uuid = u.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE p.created_by_uuid = ? OR (upp.can_view = 1)
            ORDER BY p.time DESC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $user_uuid, $user_uuid);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ==================== BLOCK START: msgql_get_accessible_tasks v1.5 ====================
// ver.1.0 - Базовая версия
// ver.1.5 (2026-06-02) - ДОБАВЛЕНА СОРТИРОВКА ПО ПОСЛЕДНЕМУ СООБЩЕНИЮ
// - Задачи сортируются по времени последнего сообщения (новые сверху)
// - Добавлено логирование для отладки

function msgql_get_accessible_tasks(string $user_uuid, string $project_uuid, ?string $parent_uuid = null): array {
    $db = msgql_db();
    
    if (msgql_is_admin()) {
        $query = "SELECT t.*, u.name as assignee_name, u.login as assignee_login 
                  FROM tasks t 
                  LEFT JOIN users u ON t.assigned_to_uuid = u.uuid 
                  WHERE t.project_uuid = ?";
        if ($parent_uuid === null) {
            $query .= " AND (t.parent_task_uuid IS NULL OR t.parent_task_uuid = '')";
        } else {
            $query .= " AND t.parent_task_uuid = ?";
        }
        $query .= " ORDER BY t.time DESC";
        
        $stmt = $db->prepare($query);
        if ($parent_uuid === null) {
            $stmt->bind_param("s", $project_uuid);
        } else {
            $stmt->bind_param("ss", $project_uuid, $parent_uuid);
        }
        $stmt->execute();
        $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($tasks as &$task) {
            $task['subtasks'] = msgql_get_accessible_tasks($user_uuid, $project_uuid, $task['uuid']);
        }
        
        // v1.5: Сортируем задачи по времени последнего сообщения
        $tasks = sort_tasks_by_last_message($tasks, $db);
        
        return $tasks;
    }
    
    if (!msgql_can_access_project($user_uuid, $project_uuid, 'view')) {
        return [];
    }
    
    $query = "SELECT t.*, u.name as assignee_name, u.login as assignee_login 
              FROM tasks t 
              LEFT JOIN users u ON t.assigned_to_uuid = u.uuid 
              WHERE t.project_uuid = ?";
    if ($parent_uuid === null) {
        $query .= " AND (t.parent_task_uuid IS NULL OR t.parent_task_uuid = '')";
    } else {
        $query .= " AND t.parent_task_uuid = ?";
    }
    $query .= " ORDER BY t.time DESC";
    
    $stmt = $db->prepare($query);
    if ($parent_uuid === null) {
        $stmt->bind_param("s", $project_uuid);
    } else {
        $stmt->bind_param("ss", $project_uuid, $parent_uuid);
    }
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($tasks as &$task) {
        $task['subtasks'] = msgql_get_accessible_tasks($user_uuid, $project_uuid, $task['uuid']);
    }
    
    // v1.5: Сортируем задачи по времени последнего сообщения
    $tasks = sort_tasks_by_last_message($tasks, $db);
    
    return $tasks;
}
// ==================== BLOCK END: msgql_get_accessible_tasks v1.5 ====================


// ==================== BLOCK START: sort_tasks_by_last_message v1.7 ====================
// ver.1.7 (2026-06-02) - УЛУЧШЕНА НАДЕЖНОСТЬ СОРТИРОВКИ
// - Явное приведение типов к int для времени и счетчиков
// - Гарантированный приоритет: задачи с сообщениями > задачи без сообщений
function sort_tasks_by_last_message(array $tasks, mysqli $db): array {
    if (empty($tasks)) {
        return $tasks;
    }
    
    // Получаем время последнего сообщения для каждой задачи
    $task_uuids = array_column($tasks, 'uuid');
    if (empty($task_uuids)) {
        return $tasks;
    }
    
    $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
    $stmt = $db->prepare("
        SELECT task_uuid, COUNT(*) as cnt, MAX(time) as last_msg_time
        FROM messages
        WHERE task_uuid IN ($placeholders)
        GROUP BY task_uuid
    ");
    $types = str_repeat('s', count($task_uuids));
    $stmt->bind_param($types, ...$task_uuids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $last_msg_times = [];
    $msg_counts = [];
    while ($row = $result->fetch_assoc()) {
        $last_msg_times[$row['task_uuid']] = (int)($row['last_msg_time'] ?? 0);
        $msg_counts[$row['task_uuid']] = (int)($row['cnt'] ?? 0);
    }
    $stmt->close();
    
    // Добавляем информацию к каждой задаче
    foreach ($tasks as &$task) {
        $task['last_msg_time'] = $last_msg_times[$task['uuid']] ?? 0;
        $task['msg_count'] = $msg_counts[$task['uuid']] ?? 0;
        if (!empty($task['subtasks'])) {
            $task['subtasks'] = sort_tasks_by_last_message($task['subtasks'], $db);
        }
    }
    
    // Сортируем: сначала задачи с сообщениями, затем без сообщений
    usort($tasks, function($a, $b) {
        $a_has_msgs = ((int)$a['msg_count'] > 0);
        $b_has_msgs = ((int)$b['msg_count'] > 0);
        
        // Задачи с сообщениями выше, чем задачи без сообщений
        if ($a_has_msgs !== $b_has_msgs) {
            return $a_has_msgs ? -1 : 1;
        }
        
        // Если у обеих задач нет сообщений, сортируем по времени создания
        if (!$a_has_msgs && !$b_has_msgs) {
            return ((int)$b['time']) - ((int)$a['time']);
        }
        
        // У обеих есть сообщения - сортируем по времени последнего сообщения
        $a_time = (int)$a['last_msg_time'];
        $b_time = (int)$b['last_msg_time'];
        if ($a_time != $b_time) {
            return $b_time - $a_time;
        }
        
        // При одинаковом времени последнего сообщения - по времени создания задачи
        return ((int)$b['time']) - ((int)$a['time']);
    });
    
    log_debug("[SORT_TASKS_v1.7] Sorted " . count($tasks) . " tasks by last_msg_time");
    return $tasks;
}
// ==================== BLOCK END: sort_tasks_by_last_message v1.7 ====================

function msgql_get_accessible_messages(string $user_uuid, string $task_uuid, int $last_time = 0, int $limit = 100): array {
    global $appBase;
    $db = msgql_db();
    if (!$db) {
        log_error("[DB] Connection failed");
        return [];
    }
    
    $can_access = msgql_is_admin() ? true : msgql_can_access_task($user_uuid, $task_uuid, 'view');
    
    if (!$can_access) {
        return [];
    }
    
    $sql = "
        SELECT 
            m.uuid as message_uuid,
            m.task_uuid,
            m.user_uuid,
            m.text,
            m.is_read,
            m.time,
            m.stamp,
            m.reply_to_uuid,
            u.name as user_name,
            u.login as user_login,
            reply.uuid as reply_uuid,
            reply.text as reply_text,
            reply.time as reply_time,
            reply_user.name as reply_user_name,
            reply_user.login as reply_user_login
        FROM messages m
        INNER JOIN users u ON m.user_uuid = u.uuid
        LEFT JOIN messages reply ON m.reply_to_uuid = reply.uuid
        LEFT JOIN users reply_user ON reply.user_uuid = reply_user.uuid
        WHERE m.task_uuid = ?
    ";
    
    $params = [$task_uuid];
    $types = "s";
    
    if ($last_time > 0) {
        $sql .= " AND m.time > ?";
        $params[] = $last_time;
        $types .= "i";
    }
    
    $sql .= " ORDER BY m.time ASC LIMIT ?";
    $params[] = $limit;
    $types .= "i";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        log_error("[SQL] Prepare failed: " . $db->error);
        return [];
    }
    
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        log_error("[SQL] Execute failed: " . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $messages = [];
    
    while ($row = $result->fetch_assoc()) {
        $msg_uuid = $row['message_uuid'];
        
        $files = [];
        $files_stmt = $db->prepare("            SELECT f.uuid, f.orig_name, f.size_bytes, f.mime
            FROM files f
            INNER JOIN message_files mf ON f.uuid = mf.file_uuid
            WHERE mf.message_uuid = ?
        ");
        if ($files_stmt) {
            $files_stmt->bind_param("s", $msg_uuid);
            $files_stmt->execute();
            $files_result = $files_stmt->get_result();
            while ($file = $files_result->fetch_assoc()) {
                $files[] = [
                    'uuid' => $file['uuid'],
                    'name' => $file['orig_name'],
                    'size' => msgql_format_file_size((int)$file['size_bytes']),
                    'size_bytes' => (int)$file['size_bytes'],
                    'mime' => $file['mime'],
                    'url' => $appBase . "/download.php?file={$file['uuid']}"
                ];
            }
            $files_stmt->close();
        }
        
        $reply_to = null;
        if (!empty($row['reply_uuid'])) {
            $reply_to = [
                'uuid' => $row['reply_uuid'],
                'user_name' => $row['reply_user_name'] ?: $row['reply_user_login'],
                'text' => $row['reply_text'],
                'time' => (int)$row['reply_time']
            ];
        } elseif (!empty($row['reply_to_uuid'])) {
            $fallback_stmt = $db->prepare("
                SELECT m.uuid, m.text, m.time, u.name as user_name, u.login as user_login
                FROM messages m
                LEFT JOIN users u ON m.user_uuid = u.uuid
                WHERE m.uuid = ?
            ");
            if ($fallback_stmt) {
                $fallback_stmt->bind_param("s", $row['reply_to_uuid']);
                $fallback_stmt->execute();
                $fallback_result = $fallback_stmt->get_result();
                $reply_row = $fallback_result->fetch_assoc();
                if ($reply_row) {
                    $reply_to = [
                        'uuid' => $reply_row['uuid'],
                        'user_name' => $reply_row['user_name'] ?: $reply_row['user_login'],
                        'text' => $reply_row['text'],
                        'time' => (int)$reply_row['time']
                    ];
                }
                $fallback_stmt->close();
            }
        }
        
        $messages[] = [
            'uuid' => $row['message_uuid'],
            'task_uuid' => $row['task_uuid'],
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
    return $messages;
}

function msgql_get_accessible_files(string $user_uuid, array $filters = [], int $limit = 50, int $offset = 0): array {
    $db = msgql_db();
    if (!$db) return [];
    
    $isAdmin = msgql_is_admin();
    $params = [];
    $types = "";
    
    $sql = "SELECT f.*, u.name as uploader_name, u.login as uploader_login
            FROM files f
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid";
    
    if (!$isAdmin) {
        $sql .= "
            INNER JOIN task_files tf ON f.uuid = tf.file_uuid
            INNER JOIN tasks t ON tf.task_uuid = t.uuid
            INNER JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        ";
        $params[] = $user_uuid;
        $types .= "s";
    }
    
    $sql .= " WHERE 1=1";
    
    if (!$isAdmin) {
        $sql .= " AND (p.created_by_uuid = ? OR upp.can_view = 1)";
        $params[] = $user_uuid;
        $types .= "s";
    }
    
    if (!empty($filters['project_uuid'])) {
        $sql .= ($isAdmin 
            ? " AND EXISTS(SELECT 1 FROM task_files tf_f JOIN tasks t_f ON tf_f.task_uuid = t_f.uuid JOIN projects p_f ON t_f.project_uuid = p_f.uuid WHERE tf_f.file_uuid = f.uuid AND p_f.uuid = ?)"
            : " AND p.uuid = ?"
        );
        $params[] = $filters['project_uuid'];
        $types .= "s";
    }
    if (!empty($filters['task_uuid'])) {
        $sql .= ($isAdmin
            ? " AND EXISTS(SELECT 1 FROM task_files tf_t WHERE tf_t.file_uuid = f.uuid AND tf_t.task_uuid = ?)"
            : " AND t.uuid = ?"
        );
        $params[] = $filters['task_uuid'];
        $types .= "s";
    }
    if (!empty($filters['mime'])) {
        $sql .= " AND f.mime LIKE ?";
        $params[] = $filters['mime'];
        $types .= "s";
    }
    if (!empty($filters['search'])) {
        $sql .= " AND f.orig_name LIKE ?";
        $params[] = '%' . $filters['search'] . '%';
        $types .= "s";
    }
    
    $sql .= " ORDER BY f.time DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    
    if (!$stmt->bind_param($types, ...$params)) {
        $stmt->close();
        return [];
    }
    
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($files as &$f) {
        $size = (int)($f['size_bytes'] ?? 0);
        $f['size_formatted'] = msgql_format_file_size($size);
        $f['url'] = $appBase . "/download.php?file={$f['uuid']}";
        $f['is_image'] = !empty($f['mime']) && (strpos($f['mime'], 'image/') === 0);
    }
    
    return $files;
}

function msgql_can_edit_task(string $user_uuid, string $task_uuid): bool {
    return msgql_can_access_task($user_uuid, $task_uuid, 'edit_tasks');
}

function msgql_can_write_message(string $user_uuid, string $task_uuid): bool {
    return msgql_can_access_task($user_uuid, $task_uuid, 'edit_messages');
}

function msgql_can_upload_file(string $user_uuid, string $task_uuid): bool {
    return msgql_can_access_task($user_uuid, $task_uuid, 'upload_files');
}

function msgql_should_send_notification_today(string $alert_days): bool {
    $today = date('N');
    $allowed_days = explode(',', $alert_days);
    return in_array($today, $allowed_days);
}

if (!function_exists('msgql_uuid_v4')) {
    function msgql_uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

// ==================== ФУНКЦИЯ ДЛЯ ПРОВЕРКИ ВНУТРЕННИХ URL ====================

function msgql_is_internal_url(string $url): bool {
    $parts = parse_url($url);
    if (!isset($parts['host'])) {
        if (preg_match('/^(localhost|127\.0\.0\.1)/i', $url)) {
            return true;
        }
        return false;
    }
    $host = $parts['host'];
    
    if (strtolower($host) === 'localhost') {
        return true;
    }
    
    $internal_patterns = [
        '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
        '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}$/',
        '/^192\.168\.\d{1,3}\.\d{1,3}$/',
        '/^169\.254\.\d{1,3}\.\d{1,3}$/',
    ];
    
    foreach ($internal_patterns as $pattern) {
        if (preg_match($pattern, $host)) {
            return true;
        }
    }
    
    return false;
}

// ==================== ПАРСИНГ ССЫЛОК ДЛЯ ОПИСАНИЙ ====================
// ver.1.3 (2026-05-31) - Добавлено преобразование \n в <br>
function msgql_parse_links_to_html(string $text): string {
    if (empty($text)) return '';
    
    // Преобразуем переносы строк в <br> ДО обработки ссылок
    $textWithBreaks = nl2br($text);
    
    $internal_ip_patterns = [
        '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}/',
        '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}/',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}/',
        '/^192\.168\.\d{1,3}\.\d{1,3}/',
        '/^169\.254\.\d{1,3}\.\d{1,3}/',
        '/^::1$/',
        '/^fc00:/',
        '/^fd00:/',
        '/^fe80:/',
    ];
    
    $text = htmlspecialchars($textWithBreaks, ENT_QUOTES, 'UTF-8');
    
    $pattern = '/(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[^\s<>\[\]\(\)\{\}]+/i';
    
    $result = preg_replace_callback($pattern, function($matches) use ($internal_ip_patterns) {
        $url = $matches[0];
        $lowerUrl = strtolower($url);
        
        if (msgql_is_internal_url($url)) {
            log_warning("[SSRF_PROTECTION] Blocked internal URL: " . substr($url, 0, 200));
            return $url;
        }
        
        $ip_only_pattern = '/^(?:https?:\/\/)?(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?::\d+)?(?:[\/?#]|$)/i';
        if (preg_match($ip_only_pattern, $url)) {
            foreach ($internal_ip_patterns as $pattern) {
                if (preg_match($pattern, $url)) {
                    log_warning("[SSRF_PROTECTION] Blocked IP-only URL: " . substr($url, 0, 200));
                    return $url;
                }
            }
        }
        
        $safeProtocols = ['http://', 'https://', 'tg://', 'telegram://', 'mailto:', 'tel:', 'ftp://', 'ws://', 'wss://', 'magnet:', 'skype:', 'viber:', 'whatsapp:', 'signal:'];
        
        $isSafe = false;
        foreach ($safeProtocols as $protocol) {
            if (strpos($lowerUrl, $protocol) === 0) {
                $isSafe = true;
                break;
            }
        }
        
        if (!$isSafe) {
            return $url;
        }
        
        $isTelegram = (strpos($lowerUrl, 'tg://') === 0 || strpos($lowerUrl, 'telegram://') === 0);
        $linkClass = $isTelegram ? 'external-link telegram-link' : 'external-link';
        
        $targetAttr = (strpos($lowerUrl, 'mailto:') === 0 || strpos($lowerUrl, 'tel:') === 0) 
            ? '' 
            : ' target="_blank" rel="noopener noreferrer"';
        
        $cleanUrl = preg_replace('/javascript:|data:|vbscript:/i', '', $url);
        
        $displayUrl = (strlen($url) > 80) ? substr($url, 0, 70) . '…' . substr($url, -10) : $url;
        
        return '<a href="' . $cleanUrl . '" class="' . $linkClass . '"' . $targetAttr . ' title="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $displayUrl . '</a>';
    }, $text);
    
    return $result;
}
// ==================== КОНЕЦ ПАРСИНГА ССЫЛОК ====================

// ==================== BLOCK START: Force password change functions v2.0 (with audit logging) ====================
// ver.1.1 (2026-05-27) - ПЕРЕМЕЩЁН В КОНЕЦ ФАЙЛА (после всех функций)
// ver.2.0 (2026-06-05) - ДОБАВЛЕНО АУДИТИРОВАНИЕ СМЕНЫ ПАРОЛЯ
// - Логирование всех попыток принудительной смены пароля
// - Запись в лог при успешной смене пароля (через admin.php)

function msgql_needs_password_change(string $user_uuid, mysqli $db): bool {
    $stmt = $db->prepare("SELECT pass, salt FROM users WHERE uuid = ?");
    if (!$stmt) {
        log_error("[PASSWORD_FORCE] Prepare failed: " . $db->error);
        return false;
    }
    
    $stmt->bind_param("s", $user_uuid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        log_error("[PASSWORD_FORCE] User not found: {$user_uuid}");
        return false;
    }
    
    $needs_change = empty($user['pass']) || empty($user['salt']);
    
    log_debug("[PASSWORD_FORCE] User {$user_uuid} needs password change: " . ($needs_change ? 'true' : 'false'));
    
    return $needs_change;
}

function msgql_set_force_password_change(bool $needs_change): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        log_warning("[PASSWORD_FORCE] Session not active, cannot set flag");
        return;
    }
    
    if ($needs_change) {
        $_SESSION['force_password_change'] = true;
        log_debug("[PASSWORD_FORCE] Flag force_password_change set to TRUE");
    } else {
        unset($_SESSION['force_password_change']);
        log_debug("[PASSWORD_FORCE] Flag force_password_change removed");
    }
}

/**
 * Проверяет, нужно ли перенаправить пользователя на смену пароля
 * v2.0: Добавлено логирование перенаправлений
 * 
 * @param string $current_script Имя текущего скрипта (basename)
 * @return bool Был ли выполнен редирект
 */
function msgql_check_force_password_redirect(string $current_script): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    if (!isset($_SESSION['force_password_change']) || $_SESSION['force_password_change'] !== true) {
        return false;
    }
    
    $user_uuid = $_SESSION['user_uuid'] ?? 'unknown';
    $user_login = $_SESSION['login'] ?? 'unknown';
    
    $allowed_scripts = ['admin.php', 'logout.php', 'set_timezone.php'];
    
    if (in_array($current_script, $allowed_scripts)) {
        if ($current_script === 'admin.php') {
            $tab = $_GET['tab'] ?? '';
            if ($tab !== 'profile') {
                log_warning("[PASSWORD_FORCE] Redirecting user {$user_login} ({$user_uuid}) to profile tab from {$current_script}");
                $base = $_SESSION['app_base'] ?? '';
                header('Location: ' . $base . '/admin.php?tab=profile');
                exit;
            }
        }
        return false;
    }
    
    log_warning("[PASSWORD_FORCE] Redirecting user {$user_login} ({$user_uuid}) from {$current_script} to admin.php?tab=profile (empty password)");
    
    $base = $_SESSION['app_base'] ?? '';
    $redirect_url = $base . '/admin.php?tab=profile';
    header('Location: ' . $redirect_url);
    exit;
}

/**
 * Логирует успешную смену пароля пользователем
 * Вызывается из admin.php после обновления пароля
 * 
 * @param string $user_uuid UUID пользователя, сменившего пароль
 * @param string $changed_by_uuid UUID того, кто выполнил смену (обычно тот же пользователь)
 * @param mysqli $db Подключение к БД
 * @return bool Результат логирования
 */
function msgql_log_password_change(string $user_uuid, string $changed_by_uuid, mysqli $db): bool {
    // Получаем информацию о пользователе
    $stmt = $db->prepare("SELECT login, name FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $user_uuid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        log_error("[PASSWORD_AUDIT] User not found: {$user_uuid}");
        return false;
    }
    
    $user_login = $user['login'];
    $user_name = $user['name'] ?: $user_login;
    
    // Получаем информацию о том, кто выполнил смену
    $is_self = ($user_uuid === $changed_by_uuid);
    if ($is_self) {
        log_warning("[PASSWORD_AUDIT] User {$user_login} ({$user_uuid}) changed their own password");
    } else {
        $stmt2 = $db->prepare("SELECT login, name FROM users WHERE uuid = ?");
        $stmt2->bind_param("s", $changed_by_uuid);
        $stmt2->execute();
        $changer = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        
        $changer_login = $changer['login'] ?? 'unknown';
        log_warning("[PASSWORD_AUDIT] Admin {$changer_login} ({$changed_by_uuid}) changed password for user {$user_login} ({$user_uuid})");
    }
    
    // Дополнительно: сохраняем факт смены пароля в отдельную таблицу (опционально)
    $table_check = $db->query("SHOW TABLES LIKE 'password_change_log'");
    if ($table_check->num_rows == 0) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `password_change_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_uuid` char(36) NOT NULL,
                `changed_by_uuid` char(36) NOT NULL,
                `changed_at` bigint(20) NOT NULL,
                `ip_address` varchar(45) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_uuid` (`user_uuid`),
                KEY `idx_changed_at` (`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        log_debug("[PASSWORD_AUDIT] Created password_change_log table");
    }
    
    // Сохраняем в БД для долгосрочного аудита
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = msgql_now_ms();
    $now_str = (string)$now;
    
    $log_stmt = $db->prepare("
        INSERT INTO password_change_log (user_uuid, changed_by_uuid, changed_at, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $log_stmt->bind_param("ssis", $user_uuid, $changed_by_uuid, $now_str, $ip);
    $result = $log_stmt->execute();
    $log_stmt->close();
    
    if ($result) {
        log_debug("[PASSWORD_AUDIT] Password change logged to database for user {$user_uuid}");
    } else {
        log_error("[PASSWORD_AUDIT] Failed to log password change to database: " . $db->error);
    }
    
    return $result;
}
// ==================== BLOCK END: Force password change functions v2.0 ====================