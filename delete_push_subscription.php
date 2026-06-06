<?php
// delete_push_subscription.php version 1.0: удаление push-подписки
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
msgql_require_login();

$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}

msgql_csrf_check_and_exit();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$endpoint = $input['endpoint'] ?? '';

if (empty($endpoint)) {
    echo json_encode(['success' => true, 'message' => 'No endpoint provided']);
    exit;
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();

$stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_uuid = ?");
$stmt->bind_param("ss", $endpoint, $current_user_uuid);
$stmt->execute();
$deleted = $db->affected_rows;
$stmt->close();

log_debug("[PUSH_DELETE] Deleted subscription for user: {$current_user_uuid}, rows: {$deleted}");
echo json_encode(['success' => true, 'deleted' => $deleted]);