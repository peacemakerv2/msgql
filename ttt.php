<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/smtp_func.php';
require_once __DIR__ . '/lib/mailer.php';

// Инициализация логгера с максимальным уровнем детализации
Logger::setLogLevel(2);
Logger::setShowCaller(true);
Logger::setLogUserInfo(true);

log_debug("==========================================");
log_debug("=== НАЧАЛО ТЕСТА ОТПРАВКИ ПОЧТЫ ===");
log_debug("==========================================");

global $mailmsg, $smtp_debug_info;
global $system_title, $EM_Sender, $replyto;
global $EM_Server, $EM_Login, $EM_Password, $SMTPport, $SMTPssl, $AdminEmail, $config;

// ========== ЛОГИРУЕМ ВСЕ ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ==========
log_debug("--- ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ---");

// Переменные отправителя
log_debug("\$system_title = " . (isset($system_title) ? var_export($system_title, true) : 'NOT SET'));
log_debug("\$EM_Sender = " . (isset($EM_Sender) ? var_export($EM_Sender, true) : 'NOT SET'));
log_debug("\$replyto = " . (isset($replyto) ? var_export($replyto, true) : 'NOT SET'));

// SMTP настройки
log_debug("\$EM_Server = " . (isset($EM_Server) ? var_export($EM_Server, true) : 'NOT SET'));
log_debug("\$EM_Login = " . (isset($EM_Login) ? var_export($EM_Login, true) : 'NOT SET'));
log_debug("\$EM_Password = " . (isset($EM_Password) ? '***[SET]***' : 'NOT SET'));
log_debug("\$SMTPport = " . (isset($SMTPport) ? var_export($SMTPport, true) : 'NOT SET'));
log_debug("\$SMTPssl = " . (isset($SMTPssl) ? var_export($SMTPssl, true) : 'NOT SET'));
log_debug("\$AdminEmail = " . (isset($AdminEmail) ? var_export($AdminEmail, true) : 'NOT SET'));

// Конфиг
log_debug("\$config = " . (isset($config) ? var_export($config, true) : 'NOT SET'));

// Проверка наличия необходимых файлов
log_debug("--- ПРОВЕРКА ФАЙЛОВ ---");
log_debug("logger.php exists: " . (file_exists(__DIR__ . '/lib/logger.php') ? 'YES' : 'NO'));
log_debug("smtp_func.php exists: " . (file_exists(__DIR__ . '/lib/smtp_func.php') ? 'YES' : 'NO'));
log_debug("mailer.php exists: " . (file_exists(__DIR__ . '/lib/mailer.php') ? 'YES' : 'NO'));

// Проверка существования функций
log_debug("--- ПРОВЕРКА ФУНКЦИЙ ---");
log_debug("function smtpmail exists: " . (function_exists('smtpmail') ? 'YES' : 'NO'));
log_debug("function msgql_send_email exists: " . (function_exists('msgql_send_email') ? 'YES' : 'NO'));
log_debug("function log_debug exists: " . (function_exists('log_debug') ? 'YES' : 'NO'));

// Параметры тестового письма
$test_to = "radioa@elec.ru";
$test_subject = "test_subject";
$test_body = "body texts";
$test_headers = "MIME-Version: 1.0\r\n";
$test_headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$test_headers .= "From: " . (isset($system_title) ? $system_title : 'Test System') . " <" . (isset($replyto) ? $replyto : (isset($EM_Sender) ? $EM_Sender : 'noreply@localhost')) . ">\r\n";
$test_headers .= "X-Priority: 3\r\n";
$test_headers .= "X-Mailer: msgql/2.0\r\n";

log_debug("--- ПАРАМЕТРЫ ТЕСТОВОГО ПИСЬМА ---");
log_debug("Кому (to): " . $test_to);
log_debug("Тема (subject): " . $test_subject);
log_debug("Тело (body): " . $test_body);
log_debug("Заголовки (headers): " . str_replace("\r\n", "\\r\\n", $test_headers));

// Сохраняем старые значения глобальных переменных для восстановления
$old_mailmsg = $mailmsg ?? null;
$old_smtp_debug_info = $smtp_debug_info ?? null;

// Сбрасываем перед тестом
$mailmsg = '';
$smtp_debug_info = '';

log_debug("--- НАЧАЛО ВЫЗОВА smtpmail() ---");
log_debug("Переменные перед вызовом:");
log_debug("  mailmsg (был): " . var_export($old_mailmsg, true));
log_debug("  smtp_debug_info (была): " . (strlen($old_smtp_debug_info ?? '') > 500 ? substr($old_smtp_debug_info, 0, 500) . '...' : var_export($old_smtp_debug_info, true)));

// Засекаем время выполнения
$start_time = microtime(true);

// Вызов функции отправки
log_debug(">>> ВЫЗОВ: smtpmail(\"{$test_to}\", \"{$test_subject}\", \"body texts\", [headers]) <<<");

$result = smtpmail($test_to, $test_subject, $test_body, $test_headers);

$execution_time = round((microtime(true) - $start_time) * 1000, 2);

log_debug("--- РЕЗУЛЬТАТ ВЫПОЛНЕНИЯ ---");
log_debug("Время выполнения: {$execution_time} мс");
log_debug("Результат (result): " . ($result ? 'TRUE (УСПЕШНО)' : 'FALSE (ОШИБКА)'));

// Логируем содержимое глобальных переменных после вызова
log_debug("--- ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ПОСЛЕ ВЫЗОВА ---");
log_debug("\$mailmsg = " . (empty($mailmsg) ? '(empty)' : var_export($mailmsg, true)));

if (!empty($smtp_debug_info)) {
    log_debug("\$smtp_debug_info (ПОЛНЫЙ ЛОГ SMTP):");
    // Разбиваем длинный лог на части
    $log_lines = explode("\n", $smtp_debug_info);
    foreach ($log_lines as $line) {
        if (trim($line) !== '') {
            log_debug("  SMTP: " . $line);
        }
    }
} else {
    log_debug("\$smtp_debug_info = (empty)");
}

// Дополнительная информация об ошибках PHP
$last_error = error_get_last();
if ($last_error && strpos($last_error['message'] ?? '', 'smtpmail') !== false) {
    log_debug("--- ПОСЛЕДНЯЯ ОШИБКА PHP ---");
    log_debug("  message: " . ($last_error['message'] ?? 'N/A'));
    log_debug("  file: " . ($last_error['file'] ?? 'N/A'));
    log_debug("  line: " . ($last_error['line'] ?? 'N/A'));
}

// Если письмо не отправлено, пробуем альтернативный метод
if (!$result) {
    log_debug("--- ПОПЫТКА АЛЬТЕРНАТИВНОГО МЕТОДА ---");
    
    // Проверяем, доступна ли функция mail()
    if (function_exists('mail')) {
        log_debug("Пробуем отправить через mail()...");
        
        $alt_headers = "MIME-Version: 1.0\r\n";
        $alt_headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $alt_from = (isset($replyto) ? $replyto : (isset($EM_Sender) ? $EM_Sender : 'noreply@localhost'));
        $alt_headers .= "From: " . (isset($system_title) ? $system_title : 'Test') . " <{$alt_from}>\r\n";
        
        $alt_result = mail($test_to, $test_subject, $test_body, $alt_headers);
        log_debug("Результат mail(): " . ($alt_result ? 'TRUE' : 'FALSE'));
        
        if (!$alt_result) {
            $alt_error = error_get_last();
            log_debug("Ошибка mail(): " . ($alt_error['message'] ?? 'unknown'));
        }
    } else {
        log_debug("Функция mail() не доступна");
    }
}

// Проверяем, загружены ли SMTP настройки из config.php
log_debug("--- ДИАГНОСТИКА SMTP НАСТРОЕК ---");

// Пытаемся загрузить config.php если он существует
$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    log_debug("Найден config.php, проверяем наличие переменных...");
    $config_content = file_get_contents($config_path);
    
    // Проверяем наличие ключевых переменных в конфиге
    $vars_to_check = ['EM_Server', 'EM_Login', 'EM_Password', 'SMTPport', 'SMTPssl', 'system_title', 'EM_Sender'];
    foreach ($vars_to_check as $var) {
        if (preg_match('/\$' . $var . '\s*=/', $config_content)) {
            log_debug("  Переменная \${$var} определена в config.php");
        } else {
            log_debug("  Переменная \${$var} НЕ найдена в config.php");
        }
    }
} else {
    log_debug("config.php НЕ НАЙДЕН по пути: {$config_path}");
}

// Логируем информацию о сервере
log_debug("--- ИНФОРМАЦИЯ О СЕРВЕРЕ ---");
log_debug("PHP Version: " . phpversion());
log_debug("Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'));
log_debug("Server Name: " . ($_SERVER['SERVER_NAME'] ?? 'unknown'));
log_debug("Remote Addr: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Проверка возможности fsockopen (нужна для SMTP)
log_debug("Функция fsockopen доступна: " . (function_exists('fsockopen') ? 'YES' : 'NO'));

// Если указан SMTP сервер, пробуем проверить соединение
if (isset($EM_Server) && !empty($EM_Server) && isset($SMTPport)) {
    log_debug("--- ПРОВЕРКА СОЕДИНЕНИЯ С SMTP СЕРВЕРОМ ---");
    log_debug("Хост: {$EM_Server}");
    log_debug("Порт: {$SMTPport}");
    
    if (function_exists('fsockopen')) {
        $test_socket = @fsockopen($EM_Server, $SMTPport, $errno, $errstr, 5);
        if ($test_socket) {
            log_debug("СОЕДИНЕНИЕ УСПЕШНО: порт {$SMTPport} открыт");
            fclose($test_socket);
        } else {
            log_debug("СОЕДИНЕНИЕ НЕ УДАЛОСЬ: [{$errno}] {$errstr}");
        }
    } else {
        log_debug("Невозможно проверить соединение: fsockopen не доступен");
    }
}

log_debug("==========================================");
log_debug("=== ЗАВЕРШЕНИЕ ТЕСТА ОТПРАВКИ ПОЧТЫ ===");
log_debug("==========================================");

// Вывод результата в консоль/браузер
echo "<pre>";
echo "=== РЕЗУЛЬТАТ ТЕСТА ===\n";
echo "SMTP отправка: " . ($result ? "УСПЕШНО ✓" : "НЕ УДАЛОСЬ ✗") . "\n";
if (!$result && !empty($mailmsg)) {
    echo "Ошибка: " . htmlspecialchars($mailmsg) . "\n";
}
if (!empty($smtp_debug_info)) {
    echo "\n=== SMTP ЛОГ ===\n";
    echo htmlspecialchars(substr($smtp_debug_info, 0, 5000));
}
echo "</pre>";

?>