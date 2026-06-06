<?php
// /get_subscriptions.php - API для получения подписок пользователя на задачи
// Версия 1.1

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

$current_user_uuid = msgql_current_user_uuid();
if (!msgql_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = msgql_db();

// Проверяем существование таблицы task_subscribers
$check_table = $db->query("SHOW TABLES LIKE 'task_subscribers'");
if ($check_table->num_rows == 0) {
    echo json_encode([
        'success' => true,
        'subscriptions' => [],
        'count' => 0,
        'message' => 'Table not found'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "SELECT ts.task_uuid, t.title as task_title
        FROM task_subscribers ts
        JOIN tasks t ON ts.task_uuid = t.uuid
        WHERE ts.user_uuid = ? AND ts.is_active = 1
        ORDER BY t.title ASC";

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'error' => $db->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$result = $stmt->get_result();

$subscriptions = [];
while ($row = $result->fetch_assoc()) {
    $subscriptions[] = [
        'task_uuid' => $row['task_uuid'],
        'task_title' => $row['task_title']
    ];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'subscriptions' => $subscriptions,
    'count' => count($subscriptions)
], JSON_UNESCAPED_UNICODE);
exit;