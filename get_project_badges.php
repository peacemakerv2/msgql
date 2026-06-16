<?php
// ==================== BLOCK START: get_project_badges.php v1.0 ====================
// ver.1.0 (2026-06-16) - API-ЭНДПОИНТ ДЛЯ ПОЛУЧЕНИЯ КОЛИЧЕСТВА НЕПРОЧИТАННЫХ УВЕДОМЛЕНИЙ ПО ПРОЕКТАМ
// - Возвращает JSON с количеством непрочитанных уведомлений для каждого проекта
// - Используется для обновления бейджей на карточках проектов

define('AJAX_REQUEST', true);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!msgql_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();

log_debug("[PROJECT_BADGES_API] Fetching project badges for user: {$current_user_uuid}");

// Получаем все доступные проекты
if ($is_admin) {
    $sql = "SELECT uuid FROM projects";
    $result = $db->query($sql);
    $projects = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $sql = "SELECT DISTINCT p.uuid
            FROM projects p
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE p.created_by_uuid = ? OR (upp.can_view = 1)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if (empty($projects)) {
    echo json_encode(['success' => true, 'badges' => []]);
    exit;
}

$project_uuids = array_column($projects, 'uuid');
$placeholders = implode(',', array_fill(0, count($project_uuids), '?'));

// Проверяем существование таблицы user_notifications
$table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
$table_exists = $table_check->num_rows > 0;

if (!$table_exists) {
    log_debug("[PROJECT_BADGES_API] user_notifications table not found");
    $badges = array_fill_keys($project_uuids, 0);
    echo json_encode(['success' => true, 'badges' => $badges]);
    exit;
}

// Получаем количество непрочитанных уведомлений по каждому проекту
$badge_sql = "
    SELECT 
        t.project_uuid,
        COUNT(DISTINCT n.id) as unread_count
    FROM user_notifications n
    JOIN tasks t ON n.task_uuid = t.uuid
    WHERE n.user_uuid = ? 
    AND n.is_read = 0
    AND t.project_uuid IN ($placeholders)
    GROUP BY t.project_uuid
";

$badge_stmt = $db->prepare($badge_sql);
if (!$badge_stmt) {
    log_error("[PROJECT_BADGES_API] Prepare failed: " . $db->error);
    $badges = array_fill_keys($project_uuids, 0);
    echo json_encode(['success' => true, 'badges' => $badges]);
    exit;
}

$params = array_merge([$current_user_uuid], $project_uuids);
$types = 's' . str_repeat('s', count($project_uuids));
$badge_stmt->bind_param($types, ...$params);
$badge_stmt->execute();
$badge_result = $badge_stmt->get_result();

$badges = array_fill_keys($project_uuids, 0);
while ($row = $badge_result->fetch_assoc()) {
    $badges[$row['project_uuid']] = (int)$row['unread_count'];
}
$badge_stmt->close();

log_debug("[PROJECT_BADGES_API] Found " . count(array_filter($badges)) . " projects with unread notifications");

echo json_encode([
    'success' => true,
    'badges' => $badges,
    'timestamp' => msgql_now_ms()
], JSON_UNESCAPED_UNICODE);
exit;
// ==================== BLOCK END: get_project_badges.php v1.0 ====================