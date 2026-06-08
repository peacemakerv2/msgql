<?php
// DB conf
$server = "localhost";
$dbbase = "db_name";
$dblogin = "db_login";
$dbpass = "pass";

// users
$salt_global = "D4&5jo_0!01P";

// Явно задаём базовый URL до папки сайта для CRON-скриптов
define('BASE_URL', 'https://pmaker.ru/m/');

// Глобальные настройки системы
$system_name = "ЗадаЧат"; // Имя системы
$system_title = "ЗадаЧат - проектный мессенджер"; // краткое описание системы
$system_version = "1.1x"; //версия системы
$js_debug = 1; //0/1/2 = js-лога нет/только критический лог/логгировать всё подробно для отладки
$php_debug = 1; //0/1/2 = php-лога нет/только критический лог/логгировать всё подробно для отладки

// Статусы пользователей
$User_Status = array("0" => "Активный", "2" => "Заблокированный");
// Роли пользователей
$User_Roles = array("0" => "Администратор", "1" => "Менеджер", "2" => "Контролёр");

// Настройки почты
$EM_Sender = "ЗадаЧат";
$AdminEmail = "radioa@elec.ru";
$EM_Server = "user183320.7ci.ru";
$EM_Login = "noreply@user183320.7ci.ru";
$replyto = "noreply@user183320.7ci.ru";
$EM_Password = "password"; 
$SMTPport = 25; //587; //465;
$SMTPssl = true;

// Глобальные настройки
$time_alertinterval = 120; // Глобальный интервал (минуты) через который отправлять любые уведомления, если не задан индивидуальный интервал пользователя

// Настройки безопасности файлов
define('MAX_FILE_SIZE_IMAGE', 10 * 1024 * 1024);     // 10 MB
define('MAX_FILE_SIZE_DOCUMENT', 25 * 1024 * 1024);  // 25 MB
define('MAX_FILE_SIZE_ARCHIVE', 50 * 1024 * 1024);   // 50 MB
define('MAX_FILE_SIZE_AUDIO', 30 * 1024 * 1024);     // 30 MB
define('MAX_FILE_SIZE_VIDEO', 100 * 1024 * 1024);    // 100 MB
define('MAX_FILES_PER_UPLOAD', 10);

// Разрешённые MIME-типы
$ALLOWED_MIME_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv',
    'application/zip', 'application/x-rar-compressed',
    'audio/mpeg', 'audio/ogg', 'audio/wav',
    'video/mp4', 'video/mpeg', 'video/webm'
];

// ==================== VAPID-КЛЮЧИ ДЛЯ PUSH-УВЕДОМЛЕНИЙ ====================
// Сгенерированы 2026-05-29 09:12:12
$config['vapid_public_key'] = 'BERxqyK8PV_uZtOn-VQZKA0T2l-HUFQQQQQQNRbYEgsssY40Eiba63oru8_76A9MuQQQQQQKc81SUcaIPI';
$config['vapid_private_key'] = 'l6JtZpQQQQQQQQlou6s1_kMcYz1hxsLQQQQQQQ';
$config['vapid_contact_email'] = 'radioa@elec.ru';
// ==================== КОНЕЦ VAPID-КЛЮЧЕЙ ====================

?>