<?php
/**
 * logout.php version 2.1: выход из системы (только POST с CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!msgql_csrf_validate_token()) {
    http_response_code(403);
    echo 'CSRF token validation failed';
    exit;
}

$cookieParams = session_get_cookie_params();
$session_was_active = (session_status() === PHP_SESSION_ACTIVE);

if ($session_was_active) {
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
        
        setcookie(
            session_name(),
            '',
            time() - 42000,
            '/',
            '',
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }
    
    session_destroy();
}

// Очищаем cookies
$allCookies = array_keys($_COOKIE);
foreach ($allCookies as $cookieName) {
    if (strpos($cookieName, 'PHPSESSID') !== false || strpos($cookieName, 'csrf') !== false) {
        setcookie(
            $cookieName,
            '',
            time() - 42000,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }
}

unset($_SESSION);

while (ob_get_level() > 0) {
    ob_end_clean();
}

// 🔥 ДОБАВИТЬ: возвращаем HTML с инструкцией очистить sessionStorage
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Выход...</title>
    <script>
        // Очищаем sessionStorage при выходе
        sessionStorage.removeItem('user_tz_offset');
        sessionStorage.removeItem('sse_sound_enabled');
        sessionStorage.removeItem('sse_sound_interval');
        
        // Перенаправляем на главную
        window.location.href = 'index.php';
    </script>
</head>
<body>
    <p>Выход из системы...</p>
</body>
</html>
<?php
exit;