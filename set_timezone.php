<?php
// set_timezone.php version 0.3: сохранение часового пояса пользователя

// 🔥 КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: определяем AJAX_REQUEST ДО подключения init.php
define('AJAX_REQUEST', true);

// Отключаем ВСЕ буферы вывода
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Запрещаем вывод ошибок в поток
ini_set('display_errors', 0);
error_reporting(0);

// Принудительно устанавливаем JSON-заголовок
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

if (!msgql_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Функция для безопасного вывода JSON
function json_exit($success, $data = [], $http_code = 200) {
    http_response_code($http_code);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// Разрешаем только POST-запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_debug("[TIMEZONE] ERROR: Method not allowed - " . $_SERVER['REQUEST_METHOD']);
    json_exit(false, ['error' => 'Method not allowed'], 405);
}

// Проверяем авторизацию
if (!msgql_is_logged_in()) {
    log_debug("[TIMEZONE] ERROR: User not logged in");
    json_exit(false, ['error' => 'Unauthorized'], 401);
}

// Получаем и сохраняем смещение
$offset = (int)($_POST['offset'] ?? 0);
$tz_hours = -$offset / 60;
$tz_sign = $tz_hours >= 0 ? '+' : '';

log_debug("[TIMEZONE] ========== SAVE TIMEZONE ==========");
log_debug("[TIMEZONE] Received offset: " . $offset . " minutes");
log_debug("[TIMEZONE] Calculated hours: " . $tz_sign . $tz_hours);
log_debug("[TIMEZONE] User UUID: " . msgql_current_user_uuid());
log_debug("[TIMEZONE] User login: " . ($_SESSION['login'] ?? 'unknown'));
log_debug("[TIMEZONE] Session ID before save: " . session_id());

// Сохраняем в сессию
msgql_set_user_timezone_offset($offset);

// Проверяем, что сохранилось
$saved_offset = msgql_user_timezone_offset();

log_debug("[TIMEZONE] Saved offset verification: " . $saved_offset);
log_debug("[TIMEZONE] Session ID after save: " . session_id());

// 🔥 БЕЗОПАСНОЕ ЛОГИРОВАНИЕ СЕССИИ (без пароля)
$safe_session = $_SESSION;
unset($safe_session['pass']); // Удаляем пароль из копии для лога
log_debug("[TIMEZONE] Session data after save: " . json_encode($safe_session, JSON_UNESCAPED_UNICODE));
log_debug("[TIMEZONE] ========== SAVE COMPLETE ==========");

json_exit(true, [
    'offset' => $offset,
    'hours' => $tz_sign . $tz_hours,
    'saved_offset' => $saved_offset
]);