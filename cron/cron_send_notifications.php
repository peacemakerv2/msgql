<?php
// cron/send_notifications.php 
// lib/notification_center.php version 3.3 - ИСПРАВЛЕНО: обработка нулевых дат (0 → NULL → "Не указана")
// version 3.2 (2026-05-19): Добавлена очистка старых записей login_attempts
// version 3.1 (2026-05-18): Добавлены фильтры is_read, time_last_dashboard_view и ограничение 10 событий
// Работает в CLI режиме без сессий

// ==================== ПОДКЛЮЧЕНИЕ КОНФИГУРАЦИИ ====================
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/smtp_func.php';
require_once __DIR__ . '/../lib/mailer.php';

// ==================== НАСТРОЙКИ CRON ====================
$SSE_QUEUE_KEEP_COUNT = 5000;           // Количество записей очереди SSE для хранения
$SSE_QUEUE_MAX_AGE_DAYS = 7;            // Максимальный возраст записи в очереди (дней)
$MAX_NOTIFICATIONS_PER_EMAIL = 10;      // Максимальное количество событий в одном письме
$LOGIN_ATTEMPTS_RETENTION_DAYS = 30;     // Количество дней хранения записей login_attempts
$log_file = __DIR__ . '/../logs/cron_mail.log';

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
    
    if ($deleted_count > 0) {
        write_log("  → Cleaned up {$deleted_count} old READ notifications (older than {$retention_days} days)");
    } else {
        write_log("  → No old read notifications to clean up");
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
    
    if ($deleted_count > 0) {
        write_log("  → Cleaned up {$deleted_count} old records from login_attempts (older than {$retention_days} days)");
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
    
    return $total_deleted;
}

// ==================== ОСНОВНОЙ КОД ====================
global $system_name;

write_log("=== CRON START ===");
write_log("System title: " . ($system_name ?? 'ЗадаЧат'));
write_log("Max notifications per email: " . $MAX_NOTIFICATIONS_PER_EMAIL);
write_log("Login attempts retention days: " . $LOGIN_ATTEMPTS_RETENTION_DAYS);

// Проверка подключения к БД
$db = msgql_db();
if (!$db) {
    write_log("FATAL: Cannot connect to database");
    exit(1);
}
write_log("Database connected successfully");

cleanup_old_notifications($db, 7);

// ========== V-MED-02 FIX: Очистка старых записей ==========
write_log("--- CLEANUP START ---");
$login_attempts_deleted = cleanup_login_attempts_table($db, $LOGIN_ATTEMPTS_RETENTION_DAYS);
$sse_queue_deleted = cleanup_sse_queue($db, $SSE_QUEUE_MAX_AGE_DAYS, $SSE_QUEUE_KEEP_COUNT);
write_log("--- CLEANUP END ---");
write_log("Total cleaned: login_attempts={$login_attempts_deleted}, sse_queue={$sse_queue_deleted}");

// Получаем пользователей
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
write_log("Found " . count($users) . " users with email and interval > 0");

$skipped_by_days = 0;
$skipped_by_interval = 0;
$sent_count = 0;

$day_names = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

foreach ($users as $user) {
    $now = msgql_now_ms();
    $last_alert = $user['time_lastalert'] ?: ($now - ($user['alert_interval_min'] * 60 * 1000));
    $interval_ms = $user['alert_interval_min'] * 60 * 1000;
    $time_since_last = $now - $last_alert;
    
    write_log("User: {$user['login']}, interval: {$user['alert_interval_min']} min");
    
    $alert_days = $user['alert_days'] ?? '1,2,3,4,5';
    $today = date('N');
    $allowed_days = explode(',', $alert_days);
    $is_allowed_today = in_array($today, $allowed_days);
    
    write_log("  → Today: {$day_names[$today]} (№{$today}), allowed days: {$alert_days}, allowed: " . ($is_allowed_today ? 'YES' : 'NO'));
    
    if (!$is_allowed_today) {
        write_log("  → Skip: notifications disabled for today");
        $skipped_by_days++;
        continue;
    }
    
    if ($time_since_last < $interval_ms) {
        write_log("  → Skip: not enough time passed");
        $skipped_by_interval++;
        continue;
    }
    
    // Вычисляем since_time с учетом последнего просмотра дашборда
    $dashboard_last_view = $user['time_last_dashboard_view'] ?? 0;
    $since_time = max($now - $interval_ms, $dashboard_last_view);
    write_log("  → Since timestamp (after dashboard check): " . $since_time);
    write_log("  → Dashboard last view: " . ($dashboard_last_view ? date('Y-m-d H:i:s', (int)($dashboard_last_view / 1000)) : 'never'));
    
    $notifications = [];
    
    // НОВЫЕ ЗАДАЧИ
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
    write_log("  → New tasks: " . count($tasks));
    
    // НОВЫЕ СООБЩЕНИЯ (только непрочитанные)
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
                     AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ?)
                     ORDER BY m.time DESC
                     LIMIT 20";
    $stmt = $db->prepare($messages_sql);
    $stmt->bind_param("siss", $since_time, $user['uuid'], $user['uuid'], $user['uuid']);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($messages as $msg) {
        $msg['type_text'] = 'Новое сообщение';
        $msg['link'] = "/messages.php?task={$msg['task_uuid']}";
        $notifications[] = $msg;
    }
    write_log("  → New messages (unread only): " . count($messages));
    
    // НОВЫЕ ФАЙЛЫ
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
    write_log("  → New files: " . count($files));
    
    // ПРОСРОЧЕННЫЕ ЗАДАЧИ
    $overdue_sql = "SELECT t.uuid, t.title, t.stamp, 'overdue' as type, t.time_end_plan as deadline, t.time,
                           p.title as project_title
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE t.assigned_to_uuid = ?
                    AND t.status = 0
                    AND t.time_end_plan IS NOT NULL
                    AND t.time_end_plan > 0 
                    AND t.time_end_plan < ?
                    ORDER BY t.time_end_plan ASC
                    LIMIT 20";
    $stmt = $db->prepare($overdue_sql);
    $stmt->bind_param("si", $user['uuid'], $now);
    $stmt->execute();
    $overdue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($overdue as $task) {
        $task['type_text'] = 'Просроченная задача';
        $task['link'] = "/projects.php?task={$task['uuid']}";
        $notifications[] = $task;
    }
    write_log("  → Overdue tasks: " . count($overdue));
    
    // Сортируем по времени (новые сверху)
    usort($notifications, function($a, $b) {
        return $b['time'] - $a['time'];
    });
    
    // Ограничиваем общее количество событий
    $total_before_limit = count($notifications);
    $notifications = array_slice($notifications, 0, $MAX_NOTIFICATIONS_PER_EMAIL);
    
    write_log("  → Total notifications found: " . $total_before_limit);
    write_log("  → After limit (max {$MAX_NOTIFICATIONS_PER_EMAIL}): " . count($notifications));
    
    if (!empty($notifications)) {
        write_log("  → Sending email to {$user['email']}");
        
        $system_name = $system_name ?? 'ЗадаЧат';
        $base_url = msgql_get_base_url();
        
        // Формируем тело письма
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
        
        // Отправляем письмо
        $sent = smtpmail($user['email'], $subject, $plain_message, '');
        
        write_log("  → SMTP result: " . ($sent ? 'SUCCESS' : 'FAILED'));
        
        if ($sent) {
            $sent_count++;
            $update_stmt = $db->prepare("UPDATE users SET time_lastalert = ? WHERE uuid = ?");
            $update_stmt->bind_param("is", $now, $user['uuid']);
            $update_stmt->execute();
            write_log("  → time_lastalert updated");
        }
    } else {
        write_log("  → No notifications, skipping email");
    }
}

write_log("=== SUMMARY ===");
write_log("Total users processed: " . count($users));
write_log("Skipped (days off): " . $skipped_by_days);
write_log("Skipped (interval not passed): " . $skipped_by_interval);
write_log("Emails sent: " . $sent_count);
write_log("Max notifications per email: " . $MAX_NOTIFICATIONS_PER_EMAIL);
write_log("Login attempts retention days: " . $LOGIN_ATTEMPTS_RETENTION_DAYS);
write_log("SSE queue keep count: " . $SSE_QUEUE_KEEP_COUNT);
write_log("=== CRON END ===\n");

// Закрываем соединение с БД
if ($db) {
    $db->close();
}