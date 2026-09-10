<?php
// cron/send_notifications.php 
// lib/notification_center.php version 3.4 - ДОБАВЛЕНЫ ДЕТАЛЬНЫЕ ЛОГИ ВРЕМЕНИ ВЫПОЛНЕНИЯ
// version 3.3 (2026-05-26): Атомарное обновление time_lastalert через UPDATE с условием
// version 3.2 (2026-05-19): Добавлена очистка старых записей login_attempts
// version 3.1 (2026-05-18): Добавлены фильтры is_read, time_last_dashboard_view и ограничение 10 событий
// version 3.4 (2026-09-08): Добавлена таблица overdue_notification_log для отправки уведомлений о просрочке 1 раз в сутки
// Работает в CLI режиме без сессий

// ==================== ПОДКЛЮЧЕНИЕ КОНФИГУРАЦИИ ====================
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/smtp_func.php';
require_once __DIR__ . '/../lib/mailer.php';

// ==================== НАСТРОЙКИ CRON ====================
$SSE_QUEUE_KEEP_COUNT = 5000;           // Количество записей очереди SSE для хранения
$SSE_QUEUE_MAX_AGE_DAYS = 7;            // Максимальный возраст записи в очереди (дней)
$MAX_NOTIFICATIONS_PER_EMAIL = 10;      // Максимальное количество событий в одном письме
$LOGIN_ATTEMPTS_RETENTION_DAYS = 30;    // Количество дней хранения записей login_attempts
$OVERDUE_LOG_RETENTION_DAYS = 180;      // Количество дней хранения лога уведомлений о просрочке (6 месяцев)
$log_file = __DIR__ . '/../logs/cron_mail.log';

// Режим отладки - включает расширенные логи даже при отсутствии уведомлений
$DEBUG_MODE = isset($_ENV['CRON_DEBUG']) && $_ENV['CRON_DEBUG'] === '1';

// Проверяем и удаляем если существует
if (file_exists($log_file)) {
    unlink($log_file);  // Удаляем старый лог
}

// ==================== ФУНКЦИИ (МИНИМАЛЬНО НЕОБХОДИМЫЕ) ====================

function write_log($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s (T)');
    file_put_contents($log_file, "[{$timestamp}] {$msg}\n", FILE_APPEND);
}

function msgql_db() {
    global $server, $dblogin, $dbpass, $dbbase;
    
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    
    $db = new mysqli($server, $dblogin, $dbpass, $dbbase);
    
    if ($db->connect_error) {
        write_log("Database connection failed: " . $db->connect_error);
        return null;
    }
    
    $db->set_charset("utf8mb4");
    return $db;
}

function msgql_now_ms() {
    return round(microtime(true) * 1000);
}

function msgql_get_base_url() {
    static $base_url = null;

    if ($base_url !== null) {
        return $base_url;
    }

    // Приоритет 1: глобальная константа
    if (defined('BASE_URL')) {
        $base_url = BASE_URL;
    }
    // Приоритет 2: переменная окружения (удобно для cron)
    elseif (isset($_ENV['BASE_URL']) && !empty($_ENV['BASE_URL'])) {
        $base_url = $_ENV['BASE_URL'];
    }
    // Приоритет 3: глобальная переменная
    elseif (isset($GLOBALS['base_url'])) {
        $base_url = $GLOBALS['base_url'];
    }
    // Значение по умолчанию для cron-скриптов
    else {
        $base_url = 'https://user183320.7ci.ru';
    }

    // Удаляем слэш в конце полностью (так как в других местах уже добавляют)
    $base_url = rtrim($base_url, '/');
    
    // Проверка и исправление двойных слэшей (но сохраняем протокол https://)
    // Разбиваем на протокол и остальную часть
    $pattern = '#^(https?://)(.*)$#i';
    if (preg_match($pattern, $base_url, $matches)) {
        $protocol = $matches[1];
        $rest = $matches[2];
        // Заменяем множественные слэши на одинарные в остальной части
        $rest = preg_replace('#/{2,}#', '/', $rest);
        $base_url = $protocol . $rest;
    } else {
        // Если нет протокола, просто заменяем множественные слэши
        $base_url = preg_replace('#/{2,}#', '/', $base_url);
    }

    return $base_url;
}

/**
 * ========== V-MED-02 FIX: Очистка старых ПРОЧИТАННЫХ уведомлений ==========
 * Удаляет прочитанные уведомления (is_read = 1) старше указанного количества дней
 * 
 * @param mysqli $db Подключение к БД
 * @param int $retention_days Количество дней хранения прочитанных уведомлений
 * @return int Количество удаленных записей
 */
function cleanup_old_notifications($db, $retention_days = 7) {
    $start_time = microtime(true);
    // Проверяем, существует ли таблица user_notifications
    $check_table = $db->query("SHOW TABLES LIKE 'user_notifications'");
    if ($check_table->num_rows == 0) {
        write_log("  → Table 'user_notifications' does not exist, skipping cleanup");
        return 0;
    }
    
    // Вычисляем cutoff time (в миллисекундах)
    $cutoff_time = msgql_now_ms() - ($retention_days * 24 * 3600000);
    
    // Удаляем ТОЛЬКО прочитанные уведомления (is_read = 1) старше cutoff_time
    $stmt = $db->prepare("DELETE FROM user_notifications WHERE is_read = 1 AND created_at < ?");
    $stmt->bind_param("i", $cutoff_time);
    $stmt->execute();
    $deleted_count = $db->affected_rows;
    $stmt->close();
    
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    if ($deleted_count > 0) {
        write_log("  → Cleaned up {$deleted_count} old READ notifications (older than {$retention_days} days) [{$elapsed}ms]");
    } else {
        write_log("  → No old read notifications to clean up [{$elapsed}ms]");
    }
    
    return $deleted_count;
}

function server_parse_cron($socket, $expected_response) {
    $server_response = "";
    while (substr($server_response, 3, 1) != ' ') {
        if (!($server_response = fgets($socket, 256))) {
            return false;
        }
    }
    return substr($server_response, 0, 3) == $expected_response;
}

/**
 * ========== V-MED-02 FIX: Очистка старых записей login_attempts ==========
 * Удаляет записи старше указанного количества дней
 * 
 * @param mysqli $db Подключение к БД
 * @param int $retention_days Количество дней хранения
 * @return int Количество удаленных записей
 */
function cleanup_login_attempts_table($db, $retention_days = 7) {
    $start_time = microtime(true);
    // Проверяем, существует ли таблица login_attempts
    $check_table = $db->query("SHOW TABLES LIKE 'login_attempts'");
    if ($check_table->num_rows == 0) {
        write_log("  → Table 'login_attempts' does not exist, skipping cleanup");
        return 0;
    }
    
    $cutoff_time = msgql_now_ms() - ($retention_days * 24 * 3600000);
    
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < ?");
    $stmt->bind_param("i", $cutoff_time);
    $stmt->execute();
    $deleted_count = $db->affected_rows;
    $stmt->close();
    
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    if ($deleted_count > 0) {
        write_log("  → Cleaned up {$deleted_count} old records from login_attempts (older than {$retention_days} days) [{$elapsed}ms]");
    }
    
    return $deleted_count;
}

/**
 * ========== V-MED-02 FIX: Очистка старых записей sse_queue ==========
 * Удаляет обработанные события старше указанного количества дней
 * 
 * @param mysqli $db Подключение к БД
 * @param int $max_age_days Максимальный возраст в днях
 * @param int $keep_count Минимальное количество записей для сохранения
 * @return int Количество удаленных записей
 */
function cleanup_sse_queue($db, $max_age_days = 7, $keep_count = 5000) {
    $start_time = microtime(true);
    // Проверяем, существует ли таблица sse_queue
    $check_table = $db->query("SHOW TABLES LIKE 'sse_queue'");
    if ($check_table->num_rows == 0) {
        write_log("  → Table 'sse_queue' does not exist, skipping cleanup");
        return 0;
    }
    
    $cutoff_time = msgql_now_ms() - ($max_age_days * 24 * 3600000);
    $total_deleted = 0;
    
    // Удаляем старые обработанные события
    $stmt = $db->prepare("DELETE FROM sse_queue WHERE processed = 1 AND created_at < ?");
    $stmt->bind_param("i", $cutoff_time);
    $stmt->execute();
    $deleted_old = $db->affected_rows;
    $total_deleted += $deleted_old;
    $stmt->close();
    
    if ($deleted_old > 0) {
        write_log("  → Cleaned up {$deleted_old} old processed events from sse_queue");
    }
    
    // Получаем общее количество обработанных событий
    $count_stmt = $db->query("SELECT COUNT(*) as cnt FROM sse_queue WHERE processed = 1");
    $total_processed = (int)$count_stmt->fetch_assoc()['cnt'];
    $count_stmt->close();
    
    // Если обработанных событий слишком много, удаляем самые старые, оставляя keep_count
    if ($total_processed > $keep_count) {
        $to_delete = $total_processed - $keep_count;
        
        $del_stmt = $db->prepare("
            DELETE FROM sse_queue 
            WHERE processed = 1 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $del_stmt->bind_param("i", $to_delete);
        $del_stmt->execute();
        $deleted_excess = $db->affected_rows;
        $total_deleted += $deleted_excess;
        $del_stmt->close();
        
        if ($deleted_excess > 0) {
            write_log("  → Cleaned up {$deleted_excess} excess processed events (keeping {$keep_count})");
        }
    }
    
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    write_log("  → SSE queue cleanup completed [{$elapsed}ms]");
    
    return $total_deleted;
}

/**
 * ========== V-MED-03 FIX: Очистка старых записей overdue_notification_log ==========
 * Удаляет записи старше указанного количества дней
 * 
 * @param mysqli $db Подключение к БД
 * @param int $retention_days Количество дней хранения (по умолчанию 180 - 6 месяцев)
 * @return int Количество удаленных записей
 */
function cleanup_overdue_log($db, $retention_days = 180) {
    $start_time = microtime(true);
    
    // Проверяем, существует ли таблица overdue_notification_log
    $check_table = $db->query("SHOW TABLES LIKE 'overdue_notification_log'");
    if ($check_table->num_rows == 0) {
        write_log("  → Table 'overdue_notification_log' does not exist, skipping cleanup");
        return 0;
    }
    
    // Вычисляем cutoff time (в миллисекундах)
    $cutoff_time = msgql_now_ms() - ($retention_days * 24 * 3600000);
    
    // Удаляем записи старше cutoff_time
    $stmt = $db->prepare("DELETE FROM overdue_notification_log WHERE sent_at < ?");
    $stmt->bind_param("i", $cutoff_time);
    $stmt->execute();
    $deleted_count = $db->affected_rows;
    $stmt->close();
    
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    if ($deleted_count > 0) {
        write_log("  → Cleaned up {$deleted_count} old overdue notification logs (older than {$retention_days} days / 6 months) [{$elapsed}ms]");
    } else {
        write_log("  → No old overdue notification logs to clean up [{$elapsed}ms]");
    }
    
    return $deleted_count;
}

/**
 * ========== V-MED-03 FIX: Создание таблицы overdue_notification_log ==========
 * Создает таблицу для лога уведомлений о просрочке, если она не существует
 * 
 * @param mysqli $db Подключение к БД
 * @return bool Результат создания
 */
function create_overdue_log_table($db) {
    $check_table = $db->query("SHOW TABLES LIKE 'overdue_notification_log'");
    if ($check_table->num_rows > 0) {
        return true;
    }
    
    $sql = "
        CREATE TABLE IF NOT EXISTS `overdue_notification_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `task_uuid` char(36) NOT NULL COMMENT 'UUID задачи',
            `user_uuid` char(36) NOT NULL COMMENT 'UUID пользователя, которому отправлено уведомление',
            `sent_at` bigint(20) NOT NULL COMMENT 'Время отправки в миллисекундах',
            `day_date` date NOT NULL COMMENT 'Дата отправки (для группировки)',
            PRIMARY KEY (`id`),
            KEY `idx_task_user_day` (`task_uuid`, `user_uuid`, `day_date`),
            KEY `idx_user_day` (`user_uuid`, `day_date`),
            KEY `idx_sent_at` (`sent_at`),
            KEY `idx_day_date` (`day_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог отправленных уведомлений о просроченных задачах'
    ";
    
    $result = $db->query($sql);
    if ($result) {
        write_log("  → Created table 'overdue_notification_log'");
    } else {
        write_log("  → ERROR creating table 'overdue_notification_log': " . $db->error);
    }
    
    return $result;
}

/**
 * Логирование времени выполнения с меткой
 */
function log_timing($label, $start_time, $force = false) {
    global $DEBUG_MODE;
    if ($force || $DEBUG_MODE) {
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        write_log("  ⏱ {$label}: {$elapsed}ms");
    }
    return microtime(true);
}

// ==================== ОСНОВНОЙ КОД ====================
global $system_name;

$script_start_time = microtime(true);
write_log("=== CRON START ===");
write_log("System title: " . ($system_name ?? 'ЗадаЧат'));
write_log("Max notifications per email: " . $MAX_NOTIFICATIONS_PER_EMAIL);
write_log("Login attempts retention days: " . $LOGIN_ATTEMPTS_RETENTION_DAYS);
write_log("Overdue log retention days: " . $OVERDUE_LOG_RETENTION_DAYS . " (6 months)");
write_log("Debug mode: " . ($DEBUG_MODE ? 'ON' : 'OFF'));

// Проверка подключения к БД
$db_start = microtime(true);
$db = msgql_db();
if (!$db) {
    write_log("FATAL: Cannot connect to database");
    exit(1);
}
log_timing('Database connection', $db_start, true);
write_log("Database connected successfully");

// ========== V-MED-03: Создание таблицы лога уведомлений о просрочке ==========
write_log("--- OVERDUE LOG TABLE SETUP ---");
$table_create_start = microtime(true);
$table_created = create_overdue_log_table($db);
log_timing('Create overdue log table', $table_create_start, true);
write_log("--- OVERDUE LOG TABLE SETUP END ---");

// Очистка старых уведомлений
$cleanup_start = microtime(true);
cleanup_old_notifications($db, 7);
log_timing('Cleanup old notifications', $cleanup_start, true);

// ========== V-MED-02 FIX: Очистка старых записей ==========
write_log("--- CLEANUP START ---");
$cleanup_start = microtime(true);
$login_attempts_deleted = cleanup_login_attempts_table($db, $LOGIN_ATTEMPTS_RETENTION_DAYS);
$sse_queue_deleted = cleanup_sse_queue($db, $SSE_QUEUE_MAX_AGE_DAYS, $SSE_QUEUE_KEEP_COUNT);
// ========== V-MED-03: Очистка старого лога уведомлений о просрочке ==========
$overdue_log_deleted = cleanup_overdue_log($db, $OVERDUE_LOG_RETENTION_DAYS);
log_timing('Total cleanup', $cleanup_start, true);
write_log("--- CLEANUP END ---");
write_log("Total cleaned: login_attempts={$login_attempts_deleted}, sse_queue={$sse_queue_deleted}, overdue_log={$overdue_log_deleted}");

// ========== V-MED-02 FIX: ФОРСИРОВАННАЯ ОТПРАВКА УВЕДОМЛЕНИЙ О СКОРОМ СТАРТЕ ==========
function send_start_soon_notifications($db, $MAX_NOTIFICATIONS_PER_EMAIL = 10) {
    $start_time = microtime(true);
    write_log("--- FORCED START SOON NOTIFICATIONS ---");
    
    $now = msgql_now_ms();
    $start_soon_time = $now + (3 * 60 * 1000);
    
    $start_soon_sql = "SELECT DISTINCT 
                        t.uuid, 
                        t.title, 
                        t.stamp, 
                        'start_soon' as type, 
                        t.time_start as start_time, 
                        t.time,
                        p.title as project_title,
                        p.created_by_uuid as project_creator_uuid,
                        t.assigned_to_uuid,
                        t.user_uuid as task_creator_uuid
                       FROM tasks t
                       JOIN projects p ON t.project_uuid = p.uuid
                       LEFT JOIN task_subscribers ts ON t.uuid = ts.task_uuid AND ts.is_active = 1
                       WHERE t.status = 0
                       AND t.time_start IS NOT NULL
                       AND t.time_start > 0
                       AND t.time_start > ?
                       AND t.time_start <= ?
                       ORDER BY t.time_start ASC
                       LIMIT 20";
    
    $stmt = $db->prepare($start_soon_sql);
    $stmt->bind_param("ii", $now, $start_soon_time);
    $stmt->execute();
    $start_soon_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($start_soon_tasks)) {
        write_log("  → No tasks starting soon");
        return 0;
    }
    
    write_log("  → Found " . count($start_soon_tasks) . " tasks starting soon");
    
    $recipients_by_user = [];
    $base_url = msgql_get_base_url();
    $system_name = $GLOBALS['system_name'] ?? 'ЗадаЧат';
    $day_names = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
    
    foreach ($start_soon_tasks as $task) {
        $task_uuid = $task['uuid'];
        write_log("  → Task: {$task['title']} (ID: {$task_uuid}) starts at " . date('Y-m-d H:i:s', (int)($task['start_time'] / 1000)));
        
        $recipients_sql = "SELECT DISTINCT u.uuid, u.login, u.name, u.email, u.alert_interval_min, u.alert_days, u.time_lastalert
                          FROM users u
                          WHERE u.status = 0
                          AND u.email IS NOT NULL 
                          AND u.email != ''
                          AND u.alert_interval_min > 0
                          AND FIND_IN_SET(DAYOFWEEK(NOW()) - 1, u.alert_days) > 0
                          AND u.uuid IN (
                              SELECT ? 
                              UNION
                              SELECT ? 
                              UNION
                              SELECT ? 
                              UNION
                              SELECT user_uuid FROM task_subscribers WHERE task_uuid = ? AND is_active = 1
                          )";
        
        $stmt2 = $db->prepare($recipients_sql);
        $assigned = $task['assigned_to_uuid'] ?? '';
        $project_creator = $task['project_creator_uuid'] ?? '';
        $task_creator = $task['task_creator_uuid'] ?? '';
        $stmt2->bind_param("ssss", $assigned, $project_creator, $task_creator, $task_uuid);
        $stmt2->execute();
        $recipients = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
        
        foreach ($recipients as $user) {
            $user_uuid = $user['uuid'];
            if (!isset($recipients_by_user[$user_uuid])) {
                $recipients_by_user[$user_uuid] = ['user' => $user, 'tasks' => []];
            }
            $recipients_by_user[$user_uuid]['tasks'][] = $task;
            write_log("    → Added recipient: {$user['login']} ({$user['name']})");
        }
    }
    
    $sent_count = 0;
    foreach ($recipients_by_user as $user_uuid => $data) {
        $user = $data['user'];
        $tasks = array_slice($data['tasks'], 0, $MAX_NOTIFICATIONS_PER_EMAIL);
        
        $plain_message = "Здравствуйте, " . ($user['name'] ?: $user['login']) . "!\r\n\r\n";
        $plain_message .= "⚠️ ВНИМАНИЕ! Следующие задачи СКОРО НАЧНУТСЯ (в течение 3 минут):\r\n\r\n";
        
        foreach ($tasks as $task) {
            $start_time = date('d.m.Y H:i (T)', (int)($task['start_time'] / 1000));
            $plain_message .= "🚀 " . $task['title'] . "\r\n";
            $plain_message .= "   📁 Проект: " . $task['project_title'] . "\r\n";
            $plain_message .= "   🕒 Старт: {$start_time}\r\n";
            $plain_message .= "   🔗 " . $base_url . "/projects.php?task={$task['uuid']}\r\n\r\n";
        }
        
        $plain_message .= "---\r\n";
        $plain_message .= "Это форсированное уведомление о начале задач.\r\n";
        $plain_message .= "Дни уведомлений: " . implode(', ', array_map(function($d) use ($day_names) { 
            return $day_names[(int)$d]; 
        }, explode(',', $user['alert_days']))) . "\r\n";
        $plain_message .= "Интервал: {$user['alert_interval_min']} минут\r\n\r\n";
        $plain_message .= "---\r\n";
        $plain_message .= "Перейти в систему: " . $base_url . "/index.php\r\n";
        $plain_message .= "---\r\n\r\n";
        $plain_message .= "Это автоматическое сообщение системы {$system_name}.\r\n";
        $plain_message .= "Настройки уведомлений можно изменить в личном кабинете: " . $base_url . "/admin.php?tab=profile\r\n";
        
        $subject = "🚀 СКОРЫЙ СТАРТ ЗАДАЧ от {$system_name} (" . date('d.m.Y H:i (T)') . ")";
        
        write_log("  → Sending forced start notification to {$user['email']} (" . count($tasks) . " tasks)");
        
        $sent = smtpmail($user['email'], $subject, $plain_message, '');
        
        if ($sent) {
            $sent_count++;
            write_log("  → SUCCESS: Forced notification sent to {$user['email']}");
            $update_sql = "UPDATE users SET time_lastalert = ? WHERE uuid = ?";
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->bind_param("is", $now, $user_uuid);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            write_log("  → FAILED: Could not send forced notification to {$user['email']}");
        }
    }
    
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    write_log("--- FORCED START SOON NOTIFICATIONS COMPLETED: {$sent_count} emails sent [{$elapsed}ms] ---");
    
    return $sent_count;
}

// ВЫЗЫВАЕМ ФУНКЦИЮ ПЕРЕД ОСНОВНЫМ ЦИКЛОМ
$forced_sent = send_start_soon_notifications($db, $MAX_NOTIFICATIONS_PER_EMAIL);
write_log("Forced start notifications sent: " . $forced_sent);

// Получаем пользователей
$users_query_start = microtime(true);
$users_sql = "SELECT uuid, login, name, email, alert_interval_min, alert_days, time_lastalert, time_last_dashboard_view 
              FROM users 
              WHERE status = 0 
              AND email IS NOT NULL 
              AND email != '' 
              AND alert_interval_min > 0";
$result = $db->query($users_sql);

if (!$result) {
    write_log("Database query failed: " . $db->error);
    exit(1);
}

$users = $result->fetch_all(MYSQLI_ASSOC);
log_timing('Fetch users query', $users_query_start, true);
write_log("Found " . count($users) . " users with email and interval > 0");

$skipped_by_days = 0;
$skipped_by_interval = 0;
$sent_count = 0;
$total_emails_with_notifications = 0;

$day_names = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

$loop_start_time = microtime(true);

foreach ($users as $user) {
    $user_loop_start = microtime(true);
    $now = msgql_now_ms();
    $has_notifications_for_user = false;
    
    write_log("User: {$user['login']}, interval: {$user['alert_interval_min']} min");
    
    // Проверяем дни недели
    $days_check_start = microtime(true);
    $alert_days = $user['alert_days'] ?? '1,2,3,4,5';
    $today = date('N');
    $allowed_days = explode(',', $alert_days);
    $is_allowed_today = in_array($today, $allowed_days);
    
    write_log("  → Today: {$day_names[$today]} (№{$today}), allowed days: {$alert_days}, allowed: " . ($is_allowed_today ? 'YES' : 'NO'));
    log_timing('  Days check', $days_check_start, $DEBUG_MODE);
    
    if (!$is_allowed_today) {
        write_log("  → Skip: notifications disabled for today");
        $skipped_by_days++;
        continue;
    }
    
    // ========== АТОМАРНОЕ ОБНОВЛЕНИЕ time_lastalert ==========
    $update_start = microtime(true);
    $update_sql = "UPDATE users 
                   SET time_lastalert = ? 
                   WHERE uuid = ? 
                   AND status = 0
                   AND email IS NOT NULL 
                   AND email != ''
                   AND alert_interval_min > 0
                   AND (
                       time_lastalert = 0 
                       OR (? - time_lastalert) >= (alert_interval_min * 60 * 1000)
                   )";
    
    $stmt = $db->prepare($update_sql);
    $stmt->bind_param("isi", $now, $user['uuid'], $now);
    $stmt->execute();
    
    // Если запись НЕ обновлена — интервал ещё не прошёл
    if ($db->affected_rows == 0) {
        log_timing('  Update time_lastalert (skipped)', $update_start, $DEBUG_MODE);
        write_log("  → Skip: interval not passed yet or another process already updated");
        $skipped_by_interval++;
        continue;
    }
    
    log_timing('  Update time_lastalert (acquired)', $update_start, true);
    write_log("  → Interval passed, time_lastalert updated to " . date('Y-m-d H:i:s', (int)($now / 1000)));
    
    // Вычисляем since_time с учетом последнего просмотра дашборда
    $interval_ms = $user['alert_interval_min'] * 60 * 1000;
    $dashboard_last_view = $user['time_last_dashboard_view'] ?? 0;
    $since_time = max($now - $interval_ms, $dashboard_last_view);
    write_log("  → Since timestamp (after dashboard check): " . $since_time);
    write_log("  → Dashboard last view: " . ($dashboard_last_view ? date('Y-m-d H:i:s', (int)($dashboard_last_view / 1000)) : 'never'));
    
    $notifications = [];
    $overdue_tasks_for_logging = [];
    
    // НОВЫЕ ЗАДАЧИ
    $task_query_start = microtime(true);
    $tasks_sql = "SELECT t.uuid, t.title, t.stamp, 'task' as type, t.time, t.time_start,
                         p.title as project_title
                  FROM tasks t
                  JOIN projects p ON t.project_uuid = p.uuid
                  WHERE t.time > ? 
                  AND t.user_uuid != ?
                  AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ?)
                  ORDER BY t.time DESC
                  LIMIT 20";
    $stmt = $db->prepare($tasks_sql);
    $stmt->bind_param("siss", $since_time, $user['uuid'], $user['uuid'], $user['uuid']);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($tasks as $task) {
        $task['type_text'] = 'Новая задача';
        $task['link'] = "/projects.php?task={$task['uuid']}";
        $notifications[] = $task;
    }
    $task_count = count($tasks);
    log_timing("  New tasks query ({$task_count} found)", $task_query_start, $task_count > 0 || $DEBUG_MODE);
    write_log("  → New tasks: " . $task_count);

    // НОВЫЕ СООБЩЕНИЯ (только непрочитанные) - С УЧЕТОМ ПОДПИСЧИКОВ
    $msg_query_start = microtime(true);
    $messages_sql = "SELECT m.uuid, m.text, m.stamp, 'message' as type, m.time, m.is_read,
                            t.uuid as task_uuid, t.title as task_title,
                            p.title as project_title,
                            u.name as user_name, u.login as user_login
                     FROM messages m
                     JOIN tasks t ON m.task_uuid = t.uuid
                     JOIN projects p ON t.project_uuid = p.uuid
                     LEFT JOIN users u ON m.user_uuid = u.uuid
                     WHERE m.time > ?
                     AND m.user_uuid != ?
                     AND m.is_read = 0
                     AND (
                         p.created_by_uuid = ? 
                         OR t.assigned_to_uuid = ? 
                         OR EXISTS (
                             SELECT 1 FROM task_subscribers ts 
                             WHERE ts.task_uuid = t.uuid 
                             AND ts.user_uuid = ? 
                             AND ts.is_active = 1
                         )
                     )
                     ORDER BY m.time DESC
                     LIMIT 20";
    $stmt = $db->prepare($messages_sql);
    $stmt->bind_param("sisss", $since_time, $user['uuid'], $user['uuid'], $user['uuid'], $user['uuid']);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($messages as $msg) {
        $msg['type_text'] = 'Новое сообщение';
        $msg['link'] = "/messages.php?task={$msg['task_uuid']}";
        $notifications[] = $msg;
    }
    $msg_count = count($messages);
    log_timing("  New messages query ({$msg_count} found)", $msg_query_start, $msg_count > 0 || $DEBUG_MODE);
    write_log("  → New messages (unread only): " . $msg_count);
    
    // НОВЫЕ ФАЙЛЫ
    $file_query_start = microtime(true);
    $files_sql = "SELECT f.uuid, f.orig_name, f.stamp, 'file' as type, f.time,
                         t.uuid as task_uuid, t.title as task_title,
                         p.title as project_title
                  FROM files f
                  JOIN task_files tf ON f.uuid = tf.file_uuid
                  JOIN tasks t ON tf.task_uuid = t.uuid
                  JOIN projects p ON t.project_uuid = p.uuid
                  WHERE f.time > ?
                  AND f.uploaded_by_uuid != ?
                  AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ?)
                  ORDER BY f.time DESC
                  LIMIT 20";
    $stmt = $db->prepare($files_sql);
    $stmt->bind_param("siss", $since_time, $user['uuid'], $user['uuid'], $user['uuid']);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($files as $file) {
        $file['type_text'] = 'Новый файл';
        $file['link'] = "/file_preview.php?uuid={$file['uuid']}";
        $notifications[] = $file;
    }
    $file_count = count($files);
    log_timing("  New files query ({$file_count} found)", $file_query_start, $file_count > 0 || $DEBUG_MODE);
    write_log("  → New files: " . $file_count);
    
    // ========== V-MED-03 FIX: ПРОСРОЧЕННЫЕ ЗАДАЧИ (только 1 раз в сутки) ==========
    $overdue_query_start = microtime(true);
    $today_date = date('Y-m-d');
    
    $overdue_sql = "SELECT t.uuid, t.title, t.stamp, 'overdue' as type, t.time_end_plan as deadline, t.time,
                           p.title as project_title
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE t.assigned_to_uuid = ?
                    AND t.status = 0
                    AND t.time_end_plan IS NOT NULL
                    AND t.time_end_plan > 0 
                    AND t.time_end_plan < ?
                    AND NOT EXISTS (
                        SELECT 1 FROM overdue_notification_log l
                        WHERE l.task_uuid = t.uuid
                        AND l.user_uuid = ?
                        AND l.day_date = ?
                    )
                    ORDER BY t.time_end_plan ASC
                    LIMIT 20";
    $stmt = $db->prepare($overdue_sql);
    $stmt->bind_param("siss", $user['uuid'], $now, $user['uuid'], $today_date);
    $stmt->execute();
    $overdue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($overdue as $task) {
        $task['type_text'] = 'Просроченная задача';
        $task['link'] = "/projects.php?task={$task['uuid']}";
        $notifications[] = $task;
        $overdue_tasks_for_logging[] = $task;
    }
    $overdue_count = count($overdue);
    log_timing("  Overdue tasks query ({$overdue_count} found)", $overdue_query_start, $overdue_count > 0 || $DEBUG_MODE);
    write_log("  → Overdue tasks (new today): " . $overdue_count);
    // ========== КОНЕЦ V-MED-03 FIX ==========

    // Сортируем по времени (новые сверху)
    $sort_start = microtime(true);
    usort($notifications, function($a, $b) {
        return $b['time'] - $a['time'];
    });
    log_timing('  Sort notifications', $sort_start, $DEBUG_MODE);
    
    // Ограничиваем общее количество событий
    $total_before_limit = count($notifications);
    $notifications = array_slice($notifications, 0, $MAX_NOTIFICATIONS_PER_EMAIL);
    
    write_log("  → Total notifications found: " . $total_before_limit);
    write_log("  → After limit (max {$MAX_NOTIFICATIONS_PER_EMAIL}): " . count($notifications));
    
    $has_notifications_for_user = !empty($notifications);
    
    if ($has_notifications_for_user) {
        $email_start = microtime(true);
        write_log("  → Sending email to {$user['email']}");
        
        $system_name = $system_name ?? 'ЗадаЧат';
        $base_url = msgql_get_base_url();
        
        // Формируем тело письма
        $message_build_start = microtime(true);
        $plain_message = "Здравствуйте, " . ($user['name'] ?: $user['login']) . "!\r\n\r\n";
        $plain_message .= "За последние " . $user['alert_interval_min'] . " мин. произошли следующие события:\r\n";
        
        if ($total_before_limit > $MAX_NOTIFICATIONS_PER_EMAIL) {
            $plain_message .= "(показаны последние " . $MAX_NOTIFICATIONS_PER_EMAIL . " из " . $total_before_limit . " событий)\r\n";
        }
        $plain_message .= "\r\n";
        
        foreach ($notifications as $notik) {
            // Иконка по типу (без дублирования)
            $type_icon = match($notik['type']) {
                'task' => '📋',
                'message' => '💬',
                'file' => '📎',
                'overdue' => '⚠️',
                'start_soon' => '🚀',
                default => '📌'
            };
            
            $time_display = $notik['stamp'] ?? date('d.m.Y H:i', (int)($notik['time'] / 1000));
            
            // Заголовок (что именно произошло)
            $plain_message .= $type_icon . " " . $notik['type_text'] . "\r\n";
            
            // Проект (если есть)
            if (!empty($notik['project_title'])) {
                $plain_message .= "   📁 Проект: " . $notik['project_title'] . "\r\n";
            }
            
            // Задача/файл (что именно)
            $item_title = $notik['title'] ?? $notik['task_title'] ?? $notik['orig_name'] ?? 'Без названия';
            $plain_message .= "   📌 " . $item_title . "\r\n";

            // Для задач, которые скоро начнутся — показываем дату старта
            if ($notik['type'] == 'start_soon' && !empty($notik['start_time'])) {
                $start_time = date('d.m.Y H:i (T)', (int)($notik['start_time'] / 1000));
                $plain_message .= "   🚀 СТАРТУЕТ СЕЙЧАС: {$start_time}\r\n";
            }
            
            // Для просроченных задач — отдельно выделяем дедлайн
            if ($notik['type'] == 'overdue' && !empty($notik['deadline']) && $notik['deadline'] > 0) {
                $deadline = date('d.m.Y H:i (T)', (int)($notik['deadline'] / 1000));
                $plain_message .= "   ⏰ Срок истёк: {$deadline}\r\n";
            }
            
            // Время начала (для задач) - только если указано и больше 0
            if (!empty($notik['time_start']) && $notik['time_start'] > 0) {
                $start_date = date('d.m.Y H:i', (int)($notik['time_start'] / 1000));
                $plain_message .= "   🚀 Начало: {$start_date}\r\n";
            }
            
            // Текст сообщения (обрезанный)
            if (!empty($notik['text'])) {
                $preview = mb_substr(strip_tags($notik['text']), 0, 100);
                $plain_message .= "   💬 " . $preview . (mb_strlen($notik['text']) > 100 ? '…' : '') . "\r\n";
            }
            
            $plain_message .= "   🕒 " . $time_display . "\r\n";
            $plain_message .= "   🔗 " . $base_url . $notik['link'] . "\r\n\r\n";
        }
        
        $plain_message .= "---\r\n";
        $plain_message .= "Дни уведомлений: " . implode(', ', array_map(function($d) use ($day_names) { return $day_names[(int)$d]; }, $allowed_days)) . "\r\n";
        $plain_message .= "Интервал: {$user['alert_interval_min']} минут\r\n\r\n";
        $plain_message .= "---\r\n";
        $plain_message .= "Перейти в систему: " . $base_url . "/index.php\r\n";
        $plain_message .= "---\r\n\r\n";
        $plain_message .= "Это автоматическое сообщение системы {$system_name}.\r\n";
        $plain_message .= "Настройки уведомлений можно изменить в личном кабинете: " . $base_url . "/admin.php?tab=profile\r\n";
        
        $subject = "Уведомления от {$system_name} за " . $user['alert_interval_min'] . " минут (" . date('d.m.Y H:i (T)') . ")";
        
        log_timing('  Build email body', $message_build_start, true);
        
        // Отправляем письмо
        $smtp_start = microtime(true);
        $sent = smtpmail($user['email'], $subject, $plain_message, '');
        log_timing('  SMTP send', $smtp_start, true);
        
        write_log("  → SMTP result: " . ($sent ? 'SUCCESS' : 'FAILED'));
        
        if ($sent) {
            $sent_count++;
            $total_emails_with_notifications++;
            write_log("  → time_lastalert already updated");
            
            // ========== V-MED-03: Логируем отправку уведомлений о просрочке ==========
            if (!empty($overdue_tasks_for_logging)) {
                $log_start = microtime(true);
                $log_stmt = $db->prepare("
                    INSERT IGNORE INTO overdue_notification_log (task_uuid, user_uuid, sent_at, day_date)
                    VALUES (?, ?, ?, ?)
                ");
                $logged_count = 0;
                foreach ($overdue_tasks_for_logging as $task) {
                    $log_stmt->bind_param("ssis", $task['uuid'], $user['uuid'], $now, $today_date);
                    if ($log_stmt->execute()) {
                        $logged_count++;
                    }
                }
                $log_stmt->close();
                log_timing('  Log overdue notifications', $log_start, true);
                write_log("  → Logged overdue notifications for {$logged_count} tasks (user {$user['login']})");
            }
            // ========== КОНЕЦ V-MED-03 ==========
            
        } else {
            write_log("  → WARNING: Email sending failed, time_lastalert already updated");
        }
        
        log_timing("  Total user processing (with email)", $user_loop_start, true);
    } else {
        write_log("  → No notifications, but time_lastalert was already updated");
        log_timing("  Total user processing (no notifications)", $user_loop_start, $DEBUG_MODE);
    }
}

log_timing("TOTAL user loop", $loop_start_time, true);

write_log("=== SUMMARY ===");
write_log("Total users processed: " . count($users));
write_log("Skipped (days off): " . $skipped_by_days);
write_log("Skipped (interval not passed): " . $skipped_by_interval);
write_log("Emails sent: " . $sent_count);
write_log("Users with notifications: " . $total_emails_with_notifications);
write_log("Max notifications per email: " . $MAX_NOTIFICATIONS_PER_EMAIL);
write_log("Login attempts retention days: " . $LOGIN_ATTEMPTS_RETENTION_DAYS);
write_log("SSE queue keep count: " . $SSE_QUEUE_KEEP_COUNT);
write_log("Overdue log retention days: " . $OVERDUE_LOG_RETENTION_DAYS . " (6 months)");
$total_elapsed = round((microtime(true) - $script_start_time) * 1000, 2);
write_log("Total execution time: {$total_elapsed}ms");
write_log("=== CRON END ===\n");

// Закрываем соединение с БД
if ($db) {
    $db->close();
}