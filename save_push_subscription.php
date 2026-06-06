<?php
// save_push_subscription.php version 2.3: сохранение push-подписки в отдельной таблице
// - v2.3 (2026-05-28): ИСПРАВЛЕНО - поддержка application/x-www-form-urlencoded
//   * Добавлена поддержка обоих форматов: JSON и FormData
//   * Улучшена обработка CSRF-токена
//   * Добавлено логирование для отладки

// ========== ВАЖНО: ОПРЕДЕЛЯЕМ AJAX_REQUEST ДО ВСЕГО ==========
define('AJAX_REQUEST', true);

// ========== ПРИНУДИТЕЛЬНАЯ ОЧИСТКА БУФЕРОВ ==========
while (ob_get_level() > 0) {
    ob_end_clean();
}

// ========== ОПРЕДЕЛЯЕМ $appBase ==========
if (!isset($appBase) || $appBase === '') {
    $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($appBase === '' || $appBase === '\\') $appBase = '';
}

// ========== ПОДДЕРЖКА ОБОИХ ФОРМАТОВ: JSON И FORM-DATA ==========
$input_data = [];

$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
$raw_input = file_get_contents('php://input');

if (strpos($content_type, 'application/json') !== false) {
    $input_data = json_decode($raw_input, true) ?: [];
} elseif (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
    parse_str($raw_input, $input_data);
} elseif (!empty($_POST)) {
    $input_data = $_POST;
}

// Если есть action 'check_existing' - отвечаем JSON
if (isset($input_data['check_existing']) && $input_data['check_existing'] === true) {
    header('Content-Type: application/json');
    
    require_once __DIR__ . '/boot.php';
    require_once __DIR__ . '/init.php';
    
    if (!msgql_is_logged_in()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $db = msgql_db();
    $endpoint = $input_data['endpoint'] ?? '';
    $current_user_uuid = msgql_current_user_uuid();
    
    if (empty($endpoint)) {
        echo json_encode(['success' => false, 'exists' => false]);
        exit;
    }
    
    $table_check = $db->query("SHOW TABLES LIKE 'push_subscriptions'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $db->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? AND user_uuid = ?");
        $stmt->bind_param("ss", $endpoint, $current_user_uuid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        echo json_encode(['success' => true, 'exists' => $exists]);
    } else {
        echo json_encode(['success' => true, 'exists' => false, 'table_missing' => true]);
    }
    exit;
}

// ========== ОСНОВНОЙ ОБРАБОТЧИК СОХРАНЕНИЯ ==========
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
msgql_require_login();

// Проверка CSRF (поддерживаем оба формата)
$csrf_token = $input_data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!msgql_csrf_validate_token($csrf_token)) {
    log_warning("[PUSH_SAVE] CSRF validation failed");
    echo json_encode(['success' => false, 'error' => 'CSRF token validation failed']);
    exit;
}

header('Content-Type: application/json');

// Получаем subscription из разных мест
$subscription = $input_data['subscription'] ?? null;

// Если subscription пришёл как JSON строка (из FormData)
if (is_string($subscription)) {
    $subscription = json_decode($subscription, true);
}

if (!$subscription || !isset($subscription['endpoint'])) {
    log_debug("[PUSH_SAVE] No subscription data. Input: " . json_encode(array_keys($input_data)));
    echo json_encode(['success' => false, 'error' => 'No subscription data']);
    exit;
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();

if (empty($current_user_uuid)) {
    log_debug("[PUSH_SAVE] User not authenticated");
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

$endpoint = $subscription['endpoint'];
$expirationTime = isset($subscription['expirationTime']) ? (string)$subscription['expirationTime'] : null;
$p256dh = $subscription['keys']['p256dh'] ?? '';
$auth = $subscription['keys']['auth'] ?? '';

$now = msgql_now_ms();
$now_str = (string)$now;
$user_tz_offset_minutes = msgql_user_timezone_offset();
$user_tz_offset_hours = -$user_tz_offset_minutes / 60;
$stamp = msgql_stamp($user_tz_offset_hours);

log_debug("[PUSH_SAVE] Saving for user: {$current_user_uuid}");
log_debug("[PUSH_SAVE] Endpoint: " . substr($endpoint, 0, 80) . "...");

// ========== СОЗДАЁМ ТАБЛИЦУ, ЕСЛИ ЕЁ НЕТ ==========
$check_table = $db->query("SHOW TABLES LIKE 'push_subscriptions'");

if (!$check_table) {
    log_error("[PUSH_SAVE] Failed to check table: " . $db->error);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $db->error]);
    exit;
}

if ($check_table->num_rows == 0) {
    log_debug("[PUSH_SAVE] Creating table 'push_subscriptions'...");
    
    $create_sql = "CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `uuid` VARCHAR(36) NOT NULL,
        `user_uuid` VARCHAR(36) NOT NULL,
        `endpoint` TEXT NOT NULL,
        `p256dh` VARCHAR(255) NOT NULL,
        `auth` VARCHAR(255) NOT NULL,
        `expiration_time` VARCHAR(20) DEFAULT NULL,
        `created_at` VARCHAR(20) NOT NULL,
        `updated_at` VARCHAR(20) NOT NULL,
        `stamp` VARCHAR(20) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_uuid` (`uuid`),
        INDEX `idx_user_uuid` (`user_uuid`),
        INDEX `idx_endpoint` (`endpoint`(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($db->query($create_sql)) {
        log_debug("[PUSH_SAVE] Table created successfully");
    } else {
        log_error("[PUSH_SAVE] CREATE TABLE failed: " . $db->error);
        echo json_encode(['success' => false, 'error' => 'Failed to create table: ' . $db->error]);
        exit;
    }
}

// ========== СОХРАНЯЕМ ПОДПИСКУ ==========
try {
    $check_stmt = $db->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
    if (!$check_stmt) {
        throw new Exception("Prepare SELECT failed: " . $db->error);
    }
    
    $check_stmt->bind_param("s", $endpoint);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($existing) {
        $update_stmt = $db->prepare("UPDATE push_subscriptions SET user_uuid = ?, p256dh = ?, auth = ?, expiration_time = ?, updated_at = ?, stamp = ? WHERE endpoint = ?");
        if (!$update_stmt) {
            throw new Exception("Prepare UPDATE failed: " . $db->error);
        }
        $update_stmt->bind_param("sssssss", $current_user_uuid, $p256dh, $auth, $expirationTime, $now_str, $stamp, $endpoint);
        $update_stmt->execute();
        $update_stmt->close();
        log_debug("[PUSH_SAVE] Updated existing subscription");
    } else {
        $uuid = msgql_uuid_v4();
        $insert_stmt = $db->prepare("INSERT INTO push_subscriptions (uuid, user_uuid, endpoint, p256dh, auth, expiration_time, created_at, updated_at, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insert_stmt) {
            throw new Exception("Prepare INSERT failed: " . $db->error);
        }
        $insert_stmt->bind_param("sssssssss", $uuid, $current_user_uuid, $endpoint, $p256dh, $auth, $expirationTime, $now_str, $now_str, $stamp);
        $insert_stmt->execute();
        $insert_stmt->close();
        log_debug("[PUSH_SAVE] Created new subscription");
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    log_error("[PUSH_SAVE] Exception: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}