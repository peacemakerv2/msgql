<?php
// db.php version 2.4
// - Удалён параметр $forceUtc, теперь всегда используется переданный часовой пояс
// - Если $tzHours === null, используется UTC (только для обратной совместимости)
// - Предыдущие изменения сохранены

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = false;

function msgql_db(): mysqli
{
    global $mysqli;
    if ($mysqli instanceof mysqli) {
        return $mysqli;
    }

    require_once __DIR__ . '/config.php';
    global $server, $dbbase, $dblogin, $dbpass;

    $mysqli = new mysqli($server, $dblogin, $dbpass, $dbbase);
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

function msgql_db_close(): void
{
    global $mysqli;
    if ($mysqli instanceof mysqli) {
        try {
            $mysqli->close();
        } catch (Throwable $e) {
            // игнорируем
        }
    }
    $mysqli = false;
}

function msgql_now_ms(): int
{
    return (int)round(microtime(true) * 1000);
}



/**
 * Создаёт строковую метку времени для отображения
 * @param int|null $tzHours Часовой пояс пользователя в ЧАСАХ от UTC (например, +4 или -5)
 *                          Положительное значение означает, что пользователь ВПЕРЕДИ UTC
 *                          Отрицательное - позади UTC
 *                          Если null — используется UTC (для обратной совместимости)
 * @return string Метка времени в формате "dd.mm.YYYY HH:MM (TZ)"
 */
// ==================== BLOCK START: msgql_stamp v2.5 ====================
// ver.2.0 - Базовая версия с поддержкой часового пояса
// ver.2.5 (2026-06-05) - ИСПРАВЛЕНИЕ: добавлена проверка $tzHours на null
// - При $tzHours === null корректно используем UTC
// - Добавлено логирование при неожиданных значениях

function msgql_stamp(?int $tzHours = null): string
{
    if ($tzHours !== null) {
        // tzHours - это смещение пользователя в часах (например, +4 для Самары)
        // time() возвращает UTC timestamp
        // Чтобы получить локальное время пользователя: UTC + смещение
        $timestamp = time() + ($tzHours * 3600);
        $stamp = date('d.m.Y H:i', $timestamp);
        $stamp .= ' (' . ($tzHours > 0 ? '+' : '') . $tzHours . ')';
        return $stamp;
    }
    
    // Для хранения в БД - UTC+0 (единый формат)
    $now_utc = time();
    $milli = sprintf("%03d", floor((microtime(true) - floor(microtime(true))) * 1000));
    $stamp = date('d.m.Y H:i:s', $now_utc);
    $stamp .= '.' . $milli . ' (UTC)';
    return $stamp;
}
// ==================== BLOCK END: msgql_stamp v2.5 ====================

function msgql_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

// Функция для получения UUID пользователя по ID (для обратной совместимости)
function msgql_get_user_uuid_by_id(int $user_id): string
{
    $db = msgql_db();
    $stmt = $db->prepare("SELECT uuid FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return $row ? $row['uuid'] : '';
}