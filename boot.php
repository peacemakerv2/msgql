<?php
/**
 * boot.php - ГИБРИДНАЯ ВЕРСИЯ
 * 
 * ОСНОВНОЕ: использует старую надёжную архитектуру (сессия запускается только здесь)
 * ДОБАВЛЕНО: все новые функции из современной версии
 * 
 * НЕ СОДЕРЖИТ НИКАКОГО ВЫВОДА HTML!
 */

// Глобальная переменная 
if (!isset($appBase)) {
    $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($appBase === '' || $appBase === '\\') {
        $appBase = '';  // ← Пустая строка для корня, не "/"
    }
    //error_log("appBase_appBase = ".$appBase);
}

// Проверка на уже отправленные заголовки (полезно для отладки)
if (headers_sent($file, $line)) {
    error_log("[BOOT] CRITICAL: Headers already sent in {$file} on line {$line}");
    $contents = ob_get_contents();
    if ($contents) {
        error_log("[BOOT] Output buffer content (first 500 chars): " . substr($contents, 0, 500));
    }
}

// ========== ЗАПУСК СЕССИИ (ТОЛЬКО ЗДЕСЬ, как в старой версии) ==========
if (session_status() === PHP_SESSION_NONE) {
    // Автоматически определяем домен
    $domain = $_SERVER['HTTP_HOST'] ?? '';
    
    // Убираем порт, если есть (например, localhost:8000 → localhost)
    $domain = explode(':', $domain)[0];
    
    // Для localhost и IP-адресов домен не указываем (оставляем пустым)
    // потому что браузеры могут некорректно обрабатывать domain для них
    if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
        $domain = '';
    }
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',  // ← ВАЖНО: ОСТАВЛЯЕМ ПУСТЫМ для автоматического определения
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();

}

// ========== ЗАГРУЗКА КОНФИГА ==========
require_once __DIR__ . '/lib/config.php';

// ==================== BLOCK START: Load VAPID keys from config v1.0.0 ====================
global $config;
if (isset($config['vapid_public_key']) && isset($config['vapid_private_key'])) {
    $vapid_public_key = $config['vapid_public_key'];
    $vapid_private_key = $config['vapid_private_key'];
    $vapid_contact_email = $config['vapid_contact_email'] ?? 'admin@localhost';
} else {
    $vapid_public_key = '';
    $vapid_private_key = '';
    $vapid_contact_email = '';
}
// ==================== BLOCK END: Load VAPID keys from config v1.0.0 ====================

// ========== ИНИЦИАЛИЗАЦИЯ ЛОГГЕРА (ПОСЛЕ КОНФИГА!) ==========
require_once __DIR__ . '/lib/logger.php';
Logger::setUseErrorLog(false); //по умолчанию отключим вывод в системный лог сервера

// Применяем уровень логирования из config.php
if (isset($php_debug)) {
    Logger::setLogLevel((int)$php_debug);
    
    // Логируем инициализацию только при полной отладке
    if ($php_debug >= 2) {
        //log_info("Логгер инициализирован из boot.php, уровень: {$php_debug}");
    }
}

// ✅ ТЕПЕРЬ МОЖНО ЛОГИРОВАТЬ (логгер уже подключён)
if (session_status() === PHP_SESSION_ACTIVE) {
    log_debug("[BOOT] Session started successfully, ID: " . session_id(), 'debug');
}

// ========== ДОБАВЛЕННЫЕ HTTP SECURITY HEADERS ==========
// Защита от Clickjacking
header('X-Frame-Options: DENY');

// Контроль передачи Referer
header('Referrer-Policy: strict-origin-when-cross-origin');

// Запрет доступа к геолокации, микрофону, камере
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Дополнительные рекомендованные заголовки (у вас уже есть частично)
header('X-Content-Type-Options: nosniff');  // Защита от MIME-сниффинга
// X-XSS-Protection уже устарел, но для старых браузеров можно добавить:
header('X-XSS-Protection: 1; mode=block');

// Генерация nonce для CSP
$csp_nonce = base64_encode(random_bytes(16));
define('CSP_NONCE', $csp_nonce);

// ========== ВАЖНО: Обновлённая CSP (с учётом новых заголовков) ==========
// Временно отключаем блокировку inline-обработчиков событий
//header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; frame-ancestors 'none';");

// Включаем буферизацию вывода для предотвращения проблем с заголовками
ob_start();

// Базовая константа для путей
define('ROOT_PATH', __DIR__);

// Подключаем ТОЛЬКО самые необходимые библиотеки (без вывода!)
require_once ROOT_PATH . '/lib/db.php';
require_once ROOT_PATH . '/lib/auth.php';

// Вспомогательные функции для работы с сессией (без вывода!)
function is_logged_in() {
    return msgql_is_logged_in();
}

function current_user_uuid() {
    return msgql_current_user_uuid();
}

function is_admin() {
    return msgql_is_admin();
}

// Регистрируем функцию для автоматической отправки буфера при завершении скрипта
register_shutdown_function(function() {
    if (ob_get_level() > 0 && !headers_sent()) {
        ob_end_flush();
    }
});