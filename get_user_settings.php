<?php
// get_user_settings.php - Получение настроек текущего пользователя
define('AJAX_REQUEST', true);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию
if (!msgql_is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$current_user_uuid = msgql_current_user_uuid();
$db = msgql_db();

// Получаем настройки звука и уведомлений пользователя
$stmt = $db->prepare("SELECT sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'sound_enabled' => (int)($user['sound_enabled'] ?? 1) === 1,
    'sound_interval_sec' => (int)($user['sound_interval_sec'] ?? 600)
]);