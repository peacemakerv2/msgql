<?php
/**
 * messages_api.php
 * Version: 3.0.1
 * Changelog:
 * - 3.0.1 (2026-10-14): FIX: get_task_info теперь всегда возвращает files,
 *                       добавлены защитные проверки для get_project_users.
 * - 3.0.0 (2026-10-14): FIX: Added missing handlers.
 * - 1.0.0 (2026-10-14): Initial split from messages.php.
 */

if (!function_exists('msgql_db') || !function_exists('msgql_current_user_uuid')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'messages_api.php must be included after init.php']);
    exit;
}

function handle_messages_ajax($db, $current_user_uuid, $is_admin, $per_page = 500) {
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');

    $response = ['success' => false, 'error' => ''];
    $action = $_POST['action'] ?? '';

    try {
        if (!$db) throw new Exception('DB connection failed');

        $mutating_actions = ['send_message', 'delete_message', 'edit_message', 'delete_file', 'mark_messages_read', 'mark_task_read', 'delete_message_file', 'add_files_to_message', 'replace_message_file'];
        if (in_array($action, $mutating_actions)) {
            msgql_csrf_check_and_exit();
        }

        // ==================== LOAD MESSAGES PAGED ====================
        if ($action === 'load_messages_paged') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $requested_page = max(0, (int)($_POST['page'] ?? 0));
            $just_count = isset($_POST['just_count']) && $_POST['just_count'] === '1';
            $buffer_size = (int)($_POST['buffer_size'] ?? 1);
            if (empty($task_uuid)) { $response['error'] = 'Не указана задача'; }
            elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) { $response['error'] = 'Нет доступа к задаче'; }
            else {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE task_uuid = ?");
                $stmt->bind_param("s", $task_uuid);
                $stmt->execute();
                $total_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                $total_pages = ($total_count > 0) ? (int)ceil($total_count / $per_page) : 1;
                $total_pages = max(1, $total_pages);
                if ($just_count) {
                    $response = ['success' => true, 'total_count' => $total_count, 'total_pages' => $total_pages, 'messages' => [], 'current_window_page' => 0, 'window_start_page' => 0, 'window_end_page' => 0];
                } else {
                    $requested_page = max(0, min($requested_page, $total_pages - 1));
                    $window_start_page = max(0, $requested_page - $buffer_size);
                    $window_end_page = min($total_pages - 1, $requested_page + $buffer_size);
                    $sql_parts = []; $params = []; $types = "";
                    for ($page = $window_start_page; $page <= $window_end_page; $page++) {
                        $offset = $page * $per_page;
                        $sql_parts[] = "(SELECT m.*, u.name as user_name, u.login as user_login, ? as page_num FROM messages m JOIN users u ON m.user_uuid = u.uuid WHERE m.task_uuid = ? ORDER BY m.time ASC LIMIT ? OFFSET ?)";
                        $params[] = $page; $params[] = $task_uuid; $params[] = $per_page; $params[] = $offset;
                        $types .= "issi";
                    }
                    $sql = implode(" UNION ALL ", $sql_parts) . " ORDER BY time ASC";
                    $stmt = $db->prepare($sql);
                    if (!$stmt) { $response['error'] = 'Ошибка подготовки запроса'; }
                    else {
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $messages_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        $messages_by_page = [];
                        foreach ($messages_raw as $msg) {
                            $page_num = (int)$msg['page_num'];
                            if (!isset($messages_by_page[$page_num])) $messages_by_page[$page_num] = [];
                            $messages_by_page[$page_num][] = $msg;
                            unset($msg['page_num']);
                        }
                        $response['success'] = true;
                        $response['total_count'] = $total_count;
                        $response['total_pages'] = $total_pages;
                        $response['current_window_page'] = $requested_page;
                        $response['window_start_page'] = $window_start_page;
                        $response['window_end_page'] = $window_end_page;
                        $response['pages_data'] = [];
                        foreach ($messages_by_page as $page_num => $page_messages) {
                            $messages = [];
                            foreach ($page_messages as $msg) {
                                $files = get_message_files($msg['uuid']);
                                $reply_to = get_reply_to_data($msg['reply_to_uuid']);
                                $messages[] = [
                                    'uuid' => $msg['uuid'], 'task_uuid' => $msg['task_uuid'], 'text' => $msg['text'],
                                    'time' => (int)$msg['time'], 'stamp' => $msg['stamp'], 'user_uuid' => $msg['user_uuid'],
                                    'user_name' => $msg['user_name'], 'user_login' => $msg['user_login'],
                                    'is_read' => (int)$msg['is_read'], 'reply_to_uuid' => $msg['reply_to_uuid'] ?? null,
                                    'reply_to' => $reply_to, 'files' => $files
                                ];
                            }
                            $response['pages_data'][$page_num] = ['messages' => $messages, 'has_older' => ($page_num > 0), 'has_newer' => ($page_num < $total_pages - 1)];
                        }
                    }
                }
            }
        }

        // ==================== SEND MESSAGE ====================
        elseif ($action === 'send_message') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $text = trim($_POST['text'] ?? '');
            $reply_to_uuid = $_POST['reply_to_uuid'] ?? null;
            if (empty($reply_to_uuid) || $reply_to_uuid === 'null' || $reply_to_uuid === '') $reply_to_uuid = null;
            if (!empty($text)) $text = convert_links_to_markers($text);
            $hasFiles = isset($_FILES['files']) && is_array($_FILES['files']['name']) && !empty(array_filter($_FILES['files']['name']));

            if (empty($task_uuid)) $response['error'] = 'Не указана задача';
            elseif (empty($text) && !$hasFiles) $response['error'] = 'Введите текст или прикрепите файл';
            elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) { $response['error'] = 'Нет доступа к задаче'; $response['no_permission'] = true; }
            elseif ($hasFiles && !msgql_can_upload_file($current_user_uuid, $task_uuid)) { $response['error'] = 'Нет прав на загрузку файлов'; $response['no_permission'] = true; }
            elseif (!msgql_can_write_message($current_user_uuid, $task_uuid)) { $response['error'] = 'Нет прав на написание сообщений'; $response['no_permission'] = true; }
            else {
                $uploaded_files_info = []; $upload_success = true; $ignore_security = isset($_POST['ignore_security']) && $_POST['ignore_security'] === '1';
                if ($hasFiles) {
                    $upload_error = ''; $security_details = null;
                    $upload_success = upload_message_files($_FILES['files'], $uploaded_files_info, $upload_error, $security_details, $ignore_security);
                    if (!$upload_success || empty($uploaded_files_info)) {
                        if ($security_details && !$ignore_security) {
                            $response = ['needs_confirmation' => true, 'security_details' => $security_details, 'security_message' => $upload_error];
                        } else {
                            $response['error'] = $upload_error ?: 'Ошибка загрузки файлов.';
                        }
                        echo json_encode($response, JSON_UNESCAPED_UNICODE); exit;
                    }
                }
                $message_uuid = msgql_uuid_v4(); $time = msgql_now_ms(); $time_str = (string)$time;
                $user_tz_offset_minutes = msgql_user_timezone_offset(); $user_tz_offset_hours = -$user_tz_offset_minutes / 60; $stamp = msgql_stamp($user_tz_offset_hours);
                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("INSERT INTO messages (uuid, task_uuid, user_uuid, text, reply_to_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssss", $message_uuid, $task_uuid, $current_user_uuid, $text, $reply_to_uuid, $time_str, $stamp);
                    if (!$stmt->execute()) throw new Exception("Execute message: " . $db->error);
                    $stmt->close();
                    $saved_files = [];
                    if ($hasFiles && !empty($uploaded_files_info)) $saved_files = save_files_and_link_to_message($uploaded_files_info, $message_uuid, $current_user_uuid);
                    $db->commit();
                    $response = ['success' => true, 'message_uuid' => $message_uuid, 'message' => get_message_data($message_uuid)];
                    if (!empty($saved_files)) $response['files'] = $saved_files;

                    if (!empty($text) && preg_match_all('/@([a-zA-Z0-9а-яА-Я_]+)/u', $text, $matches)) {
                        $mentioned_logins = $matches[1];
                        $placeholders = implode(',', array_fill(0, count($mentioned_logins), '?'));
                        $stmt_mentions = $db->prepare("SELECT uuid, name, login FROM users WHERE login IN ($placeholders) AND status = 0");
                        $stmt_mentions->bind_param(str_repeat('s', count($mentioned_logins)), ...$mentioned_logins);
                        $stmt_mentions->execute();
                        $mentioned_users = $stmt_mentions->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt_mentions->close();
                        $subscribe_stmt = $db->prepare("INSERT IGNORE INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active) VALUES (?, ?, ?, ?, 1)");
                        $now = msgql_now_ms(); $now_str = (string)$now;
                        $modified_text = $text;
                        foreach ($mentioned_users as $mentioned) {
                            $check_sub = $db->prepare("SELECT id, is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                            $check_sub->bind_param("ss", $task_uuid, $mentioned['uuid']);
                            $check_sub->execute();
                            $existing = $check_sub->get_result()->fetch_assoc();
                            $check_sub->close();
                            if ($existing) {
                                if ($existing['is_active'] == 0) {
                                    $update_sub = $db->prepare("UPDATE task_subscribers SET is_active = 1, subscribed_at = ?, subscribed_by_uuid = ? WHERE id = ?");
                                    $update_sub->bind_param("ssi", $now_str, $current_user_uuid, $existing['id']);
                                    $update_sub->execute(); $update_sub->close();
                                }
                            } else {
                                $subscribe_stmt->bind_param("ssis", $task_uuid, $mentioned['uuid'], $now_str, $current_user_uuid);
                                $subscribe_stmt->execute();
                            }
                            $display_name = !empty($mentioned['name']) ? $mentioned['name'] : $mentioned['login'];
                            $pattern = '/@' . preg_quote($mentioned['login'], '/') . '\b/';
                            $replacement = '@' . $mentioned['login'] . ' ' . $display_name . ', ';
                            $modified_text = preg_replace($pattern, $replacement, $modified_text, 1);
                        }
                        $subscribe_stmt->close();
                        if ($modified_text !== $text) {
                            $update_stmt = $db->prepare("UPDATE messages SET text = ? WHERE uuid = ?");
                            $update_stmt->bind_param("ss", $modified_text, $message_uuid);
                            $update_stmt->execute(); $update_stmt->close();
                            $response['message']['text'] = $modified_text;
                        }
                    }
                    if (class_exists('NotificationCenter')) {
                        $author_stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                        $author_stmt->bind_param("s", $current_user_uuid);
                        $author_stmt->execute();
                        $author = $author_stmt->get_result()->fetch_assoc();
                        $author_stmt->close();
                        $author_display_name = $author['name'] ?: $author['login'];
                        NotificationCenter::notifyNewMessage($message_uuid, $task_uuid, $text, $author_display_name, $current_user_uuid);
                    }
                    if (function_exists('msgql_send_sse_event')) {
                        $event_data = ['message_uuid' => $message_uuid, 'task_uuid' => $task_uuid, 'user_uuid' => $current_user_uuid];
                        msgql_send_sse_event($task_uuid, 'new_message', $event_data);
                        $unread_data = ['type' => 'unread_update', 'task_uuid' => $task_uuid];
                        msgql_send_sse_event($task_uuid, 'unread_update', $unread_data);
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    if (!empty($uploaded_files_info)) {
                        foreach ($uploaded_files_info as $file_info) {
                            if (isset($file_info['tmp_path']) && file_exists($file_info['tmp_path'])) @unlink($file_info['tmp_path']);
                        }
                    }
                    $response['error'] = 'Ошибка при сохранении: ' . $e->getMessage();
                }
            }
        }

        // ==================== INIT TASK ====================
        elseif ($action === 'init_task') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) $response['error'] = 'Не указана задача';
            elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) $response['error'] = 'Нет доступа к задаче';
            else {
                $can_write = msgql_can_write_message($current_user_uuid, $task_uuid);
                $marked_count = mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db);
                $response = ['success' => true, 'can_write' => $can_write, 'marked_count' => $marked_count ?? 0];
            }
        }

        // ==================== GET MESSAGE INFO ====================
        elseif ($action === 'get_message_info') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            if (empty($message_uuid)) $response['error'] = 'Не указан UUID сообщения';
            else {
                $stmt = $db->prepare("SELECT m.uuid, m.task_uuid, m.time, t.title as task_title, t.project_uuid, p.title as project_title FROM messages m JOIN tasks t ON m.task_uuid = t.uuid JOIN projects p ON t.project_uuid = p.uuid WHERE m.uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg_info) { $response['success'] = false; $response['error'] = 'Сообщение не найдено в БД'; }
                else {
                    if (msgql_can_access_task($current_user_uuid, $msg_info['task_uuid'], 'view')) $response = ['success' => true, 'message' => $msg_info];
                    else { $response['success'] = false; $response['error'] = 'Нет доступа к задаче'; }
                }
            }
        }

        // ==================== MARK READ ====================
        elseif ($action === 'mark_messages_read') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $message_uuids = isset($_POST['message_uuids']) && is_array($_POST['message_uuids']) ? $_POST['message_uuids'] : [];
            if (empty($task_uuid) || empty($message_uuids)) $response['error'] = 'Не указаны параметры';
            else {
                $marked_count = mark_messages_read_batch($message_uuids, $task_uuid, $current_user_uuid, $is_admin, $db);
                $response = ['success' => true, 'marked_count' => $marked_count, 'unread_count' => get_unread_count($current_user_uuid, $is_admin, $db)];
            }
        }
        elseif ($action === 'mark_task_read') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) $response['error'] = 'Не указана задача';
            elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) $response['error'] = 'Нет доступа к задаче';
            else {
                $marked_count = mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db);
                $response = ['success' => true, 'marked_count' => $marked_count, 'unread_count' => get_unread_count($current_user_uuid, $is_admin, $db)];
            }
        }

        // ==================== DELETE MESSAGE ====================
        elseif ($action === 'delete_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            if (empty($message_uuid)) $response['error'] = 'Не указан UUID сообщения';
            else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg) $response['error'] = 'Сообщение не найдено';
                elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав для удаления';
                else {
                    $task_uuid = $msg['task_uuid'];
                    $files_stmt = $db->prepare("SELECT file_uuid FROM message_files WHERE message_uuid = ?");
                    $files_stmt->bind_param("s", $message_uuid);
                    $files_stmt->execute();
                    $file_uuids = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $files_stmt->close();
                    $del_mf = $db->prepare("DELETE FROM message_files WHERE message_uuid = ?");
                    $del_mf->bind_param("s", $message_uuid);
                    $del_mf->execute(); $del_mf->close();
                    $del = $db->prepare("DELETE FROM messages WHERE uuid = ?");
                    $del->bind_param("s", $message_uuid);
                    $response['success'] = $del->execute();
                    $del->close();
                    if ($response['success'] && !empty($file_uuids)) {
                        foreach ($file_uuids as $file_item) {
                            $file_uuid = $file_item['file_uuid'];
                            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                            $check_msg->bind_param("s", $file_uuid); $check_msg->execute();
                            $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                            $check_msg->close();
                            $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                            $check_task->bind_param("s", $file_uuid); $check_task->execute();
                            $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                            $check_task->close();
                            if ($msg_usage == 0 && $task_usage == 0) {
                                $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                                $file_stmt->bind_param("s", $file_uuid); $file_stmt->execute();
                                $file_row = $file_stmt->get_result()->fetch_assoc();
                                $file_stmt->close();
                                if ($file_row) {
                                    $disk_paths = [__DIR__ . '/uploads/messages/' . $file_row['storage_name'], __DIR__ . '/uploads/tasks/' . $file_row['storage_name']];
                                    foreach ($disk_paths as $path) { if (file_exists($path)) @unlink($path); }
                                }
                                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                $del_file->bind_param("s", $file_uuid); $del_file->execute(); $del_file->close();
                            }
                        }
                    }
                    if ($response['success'] && function_exists('msgql_send_sse_event')) {
                        $event_data = ['message_uuid' => $message_uuid, 'task_uuid' => $task_uuid];
                        msgql_send_sse_event($task_uuid, 'message_deleted', $event_data);
                        msgql_send_sse_event($task_uuid, 'unread_update', ['type' => 'unread_update']);
                    }
                }
            }
        }

        // ==================== EDIT MESSAGE ====================
        elseif ($action === 'edit_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $new_text = trim($_POST['text'] ?? '');
            $reply_to_uuid = $_POST['reply_to_uuid'] ?? null;
            if (empty($reply_to_uuid) || $reply_to_uuid === 'null' || $reply_to_uuid === '') $reply_to_uuid = null;
            if (empty($message_uuid)) $response['error'] = 'Не указан UUID сообщения';
            elseif (empty($new_text)) $response['error'] = 'Текст сообщения не может быть пустым';
            else {
                $new_text = convert_links_to_markers($new_text);
                $stmt = $db->prepare("SELECT user_uuid, task_uuid, text FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg) $response['error'] = 'Сообщение не найдено';
                elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав для редактирования';
                else {
                    $old_text = $msg['text'];
                    $stmt = $db->prepare("UPDATE messages SET text = ?, reply_to_uuid = ? WHERE uuid = ?");
                    $stmt->bind_param("sss", $new_text, $reply_to_uuid, $message_uuid);
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = get_message_data($message_uuid);
                        if (function_exists('msgql_send_sse_event')) {
                            $event_data = ['message_uuid' => $message_uuid, 'new_text' => $new_text, 'task_uuid' => $msg['task_uuid']];
                            msgql_send_sse_event($msg['task_uuid'], 'message_edited', $event_data);
                        }
                    } else $response['error'] = 'Ошибка обновления: ' . $db->error;
                    $stmt->close();
                }
            }
        }

        // ==================== ADD FILES ====================
        elseif ($action === 'add_files_to_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            if (empty($message_uuid)) $response['error'] = 'Не указан UUID сообщения';
            elseif (!isset($_FILES['files']) || empty(array_filter($_FILES['files']['name']))) $response['error'] = 'Не выбраны файлы';
            else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg) $response['error'] = 'Сообщение не найдено';
                elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав';
                elseif (!msgql_can_upload_file($current_user_uuid, $msg['task_uuid'])) $response['error'] = 'Нет прав на загрузку файлов';
                else {
                    $uploaded_files_info = []; $upload_error = ''; $security_details = null;
                    $ignore_security = isset($_POST['ignore_security']) && $_POST['ignore_security'] === '1';
                    $upload_success = upload_message_files($_FILES['files'], $uploaded_files_info, $upload_error, $security_details, $ignore_security);
                    if (!$upload_success || empty($uploaded_files_info)) {
                        if ($security_details && !$ignore_security) {
                            $response = ['needs_confirmation' => true, 'security_details' => $security_details, 'security_message' => $upload_error];
                        } else $response['error'] = $upload_error ?: 'Ошибка загрузки файлов.';
                        echo json_encode($response, JSON_UNESCAPED_UNICODE); exit;
                    }
                    $db->begin_transaction();
                    try {
                        $saved_files = save_files_and_link_to_message($uploaded_files_info, $message_uuid, $current_user_uuid);
                        $db->commit();
                        $response = ['success' => true, 'message' => get_message_data($message_uuid)];
                        $response['message']['files_added'] = $saved_files;
                        if (function_exists('msgql_send_sse_event')) {
                            msgql_send_sse_event($msg['task_uuid'], 'message_edited', ['message_uuid' => $message_uuid]);
                        }
                    } catch (Exception $e) { $db->rollback(); $response['error'] = 'Ошибка при сохранении: ' . $e->getMessage(); }
                }
            }
        }

        // ==================== REPLACE FILE ====================
        elseif ($action === 'replace_message_file') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $old_file_uuid = $_POST['old_file_uuid'] ?? '';
            if (empty($message_uuid) || empty($old_file_uuid)) $response['error'] = 'Не указаны параметры';
            elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) $response['error'] = 'Ошибка загрузки файла';
            else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg) $response['error'] = 'Сообщение не найдено';
                elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав';
                else {
                    $check_stmt = $db->prepare("SELECT 1 FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                    $check_stmt->bind_param("ss", $message_uuid, $old_file_uuid);
                    $check_stmt->execute();
                    $file_belongs = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    if (!$file_belongs) $response['error'] = 'Файл не принадлежит этому сообщению';
                    else {
                        if (!class_exists('UploadSecurity')) require_once __DIR__ . '/lib/upload_security.php';
                        $validation = UploadSecurity::validateUploadedFile($_FILES['file']);
                        if (!$validation['valid']) { $response['error'] = $validation['error']; echo json_encode($response); exit; }
                        else {
                            $upload_dir = __DIR__ . '/uploads/messages/';
                            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                            $storage_name = UploadSecurity::generateSecureFilename($validation['safe_name'], 'msg');
                            $target_path = $upload_dir . $storage_name;
                            if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
                                $db->begin_transaction();
                                try {
                                    $new_file_uuid = msgql_uuid_v4();
                                    $time = msgql_now_ms(); $time_str = (string)$time;
                                    $user_tz_offset_minutes = msgql_user_timezone_offset();
                                    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                                    $stamp = msgql_stamp($user_tz_offset_hours);
                                    $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssisss", $new_file_uuid, $validation['safe_name'], $storage_name, $validation['mime'], $validation['size'], $current_user_uuid, $time_str, $stamp);
                                    $stmt->execute(); $stmt->close();
                                    $update_stmt = $db->prepare("UPDATE message_files SET file_uuid = ? WHERE message_uuid = ? AND file_uuid = ?");
                                    $update_stmt->bind_param("sss", $new_file_uuid, $message_uuid, $old_file_uuid);
                                    $update_stmt->execute(); $update_stmt->close();
                                    $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                                    $check_msg->bind_param("s", $old_file_uuid); $check_msg->execute();
                                    $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                                    $check_msg->close();
                                    $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                                    $check_task->bind_param("s", $old_file_uuid); $check_task->execute();
                                    $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                                    $check_task->close();
                                    if ($msg_usage == 0 && $task_usage == 0) {
                                        $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                                        $file_stmt->bind_param("s", $old_file_uuid); $file_stmt->execute();
                                        $old_file = $file_stmt->get_result()->fetch_assoc(); $file_stmt->close();
                                        if ($old_file) {
                                            $paths = [__DIR__ . '/uploads/messages/' . $old_file['storage_name'], __DIR__ . '/uploads/tasks/' . $old_file['storage_name']];
                                            foreach ($paths as $p) { if (file_exists($p)) @unlink($p); }
                                        }
                                        $del_stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                        $del_stmt->bind_param("s", $old_file_uuid); $del_stmt->execute(); $del_stmt->close();
                                    }
                                    $db->commit();
                                    $response = ['success' => true, 'message' => get_message_data($message_uuid)];
                                    if (function_exists('msgql_send_sse_event')) msgql_send_sse_event($msg['task_uuid'], 'message_edited', ['message_uuid' => $message_uuid]);
                                } catch (Exception $e) {
                                    $db->rollback();
                                    if (file_exists($target_path)) @unlink($target_path);
                                    throw $e;
                                }
                            } else $response['error'] = 'Ошибка сохранения файла на диск';
                        }
                    }
                }
            }
        }

        // ==================== DELETE FILE ====================
        elseif ($action === 'delete_message_file') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $file_uuid = $_POST['file_uuid'] ?? '';
            if (empty($message_uuid) || empty($file_uuid)) $response['error'] = 'Не указаны параметры';
            else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$msg) $response['error'] = 'Сообщение не найдено';
                elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав';
                else {
                    $check_stmt = $db->prepare("SELECT 1 FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                    $check_stmt->bind_param("ss", $message_uuid, $file_uuid);
                    $check_stmt->execute();
                    $file_belongs = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    if (!$file_belongs) $response['error'] = 'Файл не принадлежит этому сообщению';
                    else {
                        $db->begin_transaction();
                        try {
                            $del_link = $db->prepare("DELETE FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                            $del_link->bind_param("ss", $message_uuid, $file_uuid);
                            $del_link->execute(); $del_link->close();
                            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                            $check_msg->bind_param("s", $file_uuid); $check_msg->execute();
                            $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                            $check_msg->close();
                            $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                            $check_task->bind_param("s", $file_uuid); $check_task->execute();
                            $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                            $check_task->close();
                            if ($msg_usage == 0 && $task_usage == 0) {
                                $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                                $file_stmt->bind_param("s", $file_uuid); $file_stmt->execute();
                                $file_row = $file_stmt->get_result()->fetch_assoc(); $file_stmt->close();
                                if ($file_row) {
                                    $paths = [__DIR__ . '/uploads/messages/' . $file_row['storage_name'], __DIR__ . '/uploads/tasks/' . $file_row['storage_name']];
                                    foreach ($paths as $p) { if (file_exists($p)) @unlink($p); }
                                }
                                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                $del_file->bind_param("s", $file_uuid); $del_file->execute(); $del_file->close();
                            }
                            $db->commit();
                            $response = ['success' => true, 'message' => get_message_data($message_uuid)];
                            if (function_exists('msgql_send_sse_event')) msgql_send_sse_event($msg['task_uuid'], 'message_edited', ['message_uuid' => $message_uuid]);
                        } catch (Exception $e) { $db->rollback(); $response['error'] = 'Ошибка при удалении файла: ' . $e->getMessage(); }
                    }
                }
            }
        }

        // ==================== DELETE FILE (GLOBAL) ====================
        elseif ($action === 'delete_file') {
            $file_uuid = $_POST['file_uuid'] ?? '';
            if (empty($file_uuid)) $response['error'] = 'Не указан UUID файла';
            else {
                $stmt = $db->prepare("SELECT uploaded_by_uuid, storage_name FROM files WHERE uuid = ?");
                $stmt->bind_param("s", $file_uuid);
                $stmt->execute();
                $file = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$file) $response['error'] = 'Файл не найден';
                elseif ($file['uploaded_by_uuid'] !== $current_user_uuid && !$is_admin) $response['error'] = 'Нет прав для удаления';
                else {
                    $db->begin_transaction();
                    try {
                        $del_mf = $db->prepare("DELETE FROM message_files WHERE file_uuid = ?");
                        $del_mf->bind_param("s", $file_uuid); $del_mf->execute(); $del_mf->close();
                        $del_tf = $db->prepare("DELETE FROM task_files WHERE file_uuid = ?");
                        $del_tf->bind_param("s", $file_uuid); $del_tf->execute(); $del_tf->close();
                        $paths = [__DIR__ . '/uploads/messages/' . $file['storage_name'], __DIR__ . '/uploads/tasks/' . $file['storage_name']];
                        foreach ($paths as $path) { if (file_exists($path)) @unlink($path); }
                        $del = $db->prepare("DELETE FROM files WHERE uuid = ?");
                        $del->bind_param("s", $file_uuid); $del->execute(); $del->close();
                        $db->commit();
                        $response['success'] = true;
                    } catch (Exception $e) { $db->rollback(); $response['error'] = $e->getMessage(); }
                }
            }
        }

        // ==================== GET PROJECT TASKS ====================
        elseif ($action === 'get_project_tasks') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            if (empty($project_uuid)) { $response['error'] = 'Не указан проект'; $response['tasks'] = []; }
            elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) { $response['error'] = 'Нет доступа к проекту'; $response['tasks'] = []; }
            else $response = ['success' => true, 'tasks' => get_project_tasks_for_messenger($project_uuid)];
        }

        // ==================== GET TASK INFO ====================
        elseif ($action === 'get_task_info') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) $response['error'] = 'Не указана задача';
            else {
                $task_check = $db->prepare("SELECT t.uuid, t.project_uuid, t.parent_task_uuid, t.title, p.created_by_uuid as project_created_by FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
                $task_check->bind_param("s", $task_uuid);
                $task_check->execute();
                $task_info_db = $task_check->get_result()->fetch_assoc();
                $task_check->close();
                if (!$task_info_db) $response['error'] = 'Задача не найдена';
                elseif (!msgql_can_access_project($current_user_uuid, $task_info_db['project_uuid'], 'view')) $response['error'] = 'Нет доступа к проекту задачи';
                else {
                    $stmt = $db->prepare("SELECT t.*, u.name as assignee_name, u.login as assignee_login FROM tasks t LEFT JOIN users u ON t.assigned_to_uuid = u.uuid WHERE t.uuid = ?");
                    $stmt->bind_param("s", $task_uuid);
                    $stmt->execute();
                    $task = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($task) {
                        $parent_chain = [];
                        if (!empty($task_info_db['parent_task_uuid'])) {
                            $cte_sql = "WITH RECURSIVE task_parents AS (SELECT uuid, parent_task_uuid, title, 1 as level FROM tasks WHERE uuid = ? UNION ALL SELECT t.uuid, t.parent_task_uuid, t.title, tp.level + 1 FROM tasks t INNER JOIN task_parents tp ON t.uuid = tp.parent_task_uuid WHERE tp.parent_task_uuid IS NOT NULL AND tp.level < 50) SELECT uuid FROM task_parents ORDER BY level DESC";
                            $cte_stmt = $db->prepare($cte_sql);
                            if ($cte_stmt) {
                                $cte_stmt->bind_param("s", $task_info_db['parent_task_uuid']);
                                $cte_stmt->execute();
                                $parents_result = $cte_stmt->get_result();
                                while ($row = $parents_result->fetch_assoc()) $parent_chain[] = $row['uuid'];
                                $cte_stmt->close();
                            }
                        }
                        $files = get_message_files_for_task($task_uuid);
                        $response = [
                            'success' => true,
                            'task' => $task,
                            'task_files' => $files,
                            'parent_chain' => $parent_chain,
                            'project_uuid' => $task['project_uuid'],
                            'direct_parent_uuid' => $task_info_db['parent_task_uuid']
                        ];
                    } else $response['error'] = 'Задача не найдена или нет доступа';
                }
            }
        }

        // ==================== GET TASK USERS ====================
        elseif ($action === 'get_task_users') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) $response['users'] = [];
            else {
                $taskStmt = $db->prepare("SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
                $taskStmt->bind_param("s", $task_uuid);
                $taskStmt->execute();
                $taskInfo = $taskStmt->get_result()->fetch_assoc();
                $taskStmt->close();
                if ($taskInfo) {
                    $stmt = $db->prepare("SELECT DISTINCT u.uuid, u.name, u.login, u.role FROM users u WHERE u.status = 0 AND u.uuid != ? AND (u.role = 0 OR u.uuid = ? OR u.uuid = ? OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_view = 1)) ORDER BY CASE WHEN u.role = 0 THEN 1 WHEN u.uuid = ? THEN 2 WHEN u.uuid = ? THEN 3 ELSE 4 END, u.name ASC");
                    $stmt->bind_param("ssssss", $current_user_uuid, $taskInfo['created_by_uuid'], $taskInfo['assigned_to_uuid'], $taskInfo['project_uuid'], $taskInfo['created_by_uuid'], $taskInfo['assigned_to_uuid']);
                    $stmt->execute();
                    $response = ['success' => true, 'users' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
                    $stmt->close();
                } else $response['users'] = [];
            }
        }

        // ==================== SEARCH USERS FOR MENTION ====================
        elseif ($action === 'search_users_for_mention') {
            $query = trim($_POST['query'] ?? '');
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) { $response['success'] = true; $response['users'] = []; }
            else {
                $taskStmt = $db->prepare("SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
                $taskStmt->bind_param("s", $task_uuid);
                $taskStmt->execute();
                $taskInfo = $taskStmt->get_result()->fetch_assoc();
                $taskStmt->close();
                if ($taskInfo) {
                    $like = "%$query%";
                    $stmt = $db->prepare("SELECT DISTINCT u.uuid, u.name, u.login, u.role FROM users u WHERE u.status = 0 AND u.uuid != ? AND (u.role = 0 OR u.uuid = ? OR u.uuid = ? OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_view = 1)) AND (u.name LIKE ? OR u.login LIKE ?) ORDER BY CASE WHEN u.role = 0 THEN 1 WHEN u.uuid = ? THEN 2 WHEN u.uuid = ? THEN 3 ELSE 4 END, u.name ASC LIMIT 15");
                    $stmt->bind_param("ssssssss", $current_user_uuid, $taskInfo['created_by_uuid'], $taskInfo['assigned_to_uuid'], $taskInfo['project_uuid'], $like, $like, $taskInfo['created_by_uuid'], $taskInfo['assigned_to_uuid']);
                    $stmt->execute();
                    $response = ['success' => true, 'users' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
                    $stmt->close();
                } else $response['users'] = [];
            }
        }

        // ==================== GET LATEST TASK ====================
        elseif ($action === 'get_latest_task') {
            $stmt = $db->prepare("SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title as project_title, m.time as last_message_time FROM tasks t JOIN messages m ON t.uuid = m.task_uuid JOIN projects p ON t.project_uuid = p.uuid WHERE m.user_uuid != ? OR m.user_uuid = ? ORDER BY m.time DESC LIMIT 1");
            $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
            $stmt->execute();
            $latest_task = $stmt->get_result()->fetch_assoc();
            if ($latest_task && msgql_can_access_task($current_user_uuid, $latest_task['uuid'], 'view')) {
                $response['success'] = true;
                $response['task'] = $latest_task;
            } else { $response['success'] = false; $response['error'] = 'Нет доступных сообщений'; }
        }

        // ==================== GET PROJECT USERS ====================
        elseif ($action === 'get_project_users') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            if (empty($project_uuid)) { $response['error'] = 'Не указан проект'; $response['users'] = []; }
            elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) { $response['error'] = 'Нет доступа к проекту'; $response['users'] = []; }
            else {
                $users = get_users_with_project_access($db, $project_uuid, $current_user_uuid, $is_admin);
                $response = ['success' => true, 'users' => $users];
            }
        }

        // ==================== MARK PROJECT NOTIFICATIONS READ ====================
        elseif ($action === 'mark_project_notifications_read') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            if (empty($project_uuid)) $response['error'] = 'Не указан проект';
            elseif (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) $response['error'] = 'Нет доступа к проекту';
            else {
                $table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
                if ($table_check->num_rows == 0) { $response['success'] = true; $response['marked_count'] = 0; }
                else {
                    $sql = "UPDATE user_notifications n JOIN tasks t ON n.task_uuid = t.uuid SET n.is_read = 1 WHERE n.user_uuid = ? AND n.is_read = 0 AND t.project_uuid = ?";
                    $stmt = $db->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param("ss", $current_user_uuid, $project_uuid);
                        $stmt->execute();
                        $marked_count = $db->affected_rows;
                        $stmt->close();
                        $response = ['success' => true, 'marked_count' => $marked_count];
                    } else $response['error'] = 'Ошибка подготовки запроса';
                }
            }
        }

        else {
            $response['error'] = 'Unknown action: ' . $action;
        }
    } catch (Throwable $e) {
        $response['error'] = 'Error: ' . substr($e->getMessage(), 0, 100);
        log_error("[AJAX] Exception: " . $e->getMessage());
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Получение файлов задачи (для get_task_info)
 */
function get_message_files_for_task($task_uuid) {
    $db = msgql_db();
    $files = [];
    $stmt = $db->prepare("
        SELECT DISTINCT f.* FROM files f
        WHERE f.uuid IN (SELECT tf.file_uuid FROM task_files tf WHERE tf.task_uuid = ?)
           OR f.uuid IN (
               SELECT mf.file_uuid FROM message_files mf
               JOIN messages m ON mf.message_uuid = m.uuid
               WHERE m.task_uuid = ?
           )
    ");
    if (!$stmt) return [];
    $stmt->bind_param("ss", $task_uuid, $task_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $files[] = [
            'uuid' => $row['uuid'],
            'name' => $row['orig_name'],
            'mime' => $row['mime'],
            'size_bytes' => (int)$row['size_bytes'],
            'size' => msgql_format_file_size((int)$row['size_bytes'])
        ];
    }
    $stmt->close();
    return $files;
}