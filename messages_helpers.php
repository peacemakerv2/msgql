<?php
/**
 * messages_helpers.php
 * Version: 1.0.1
 * Changelog:
 * - 1.0.1 (2026-10-14): FIX: get_latest_task_with_unread — добавлена защита от исключений,
 *                       уточнён порядок fallback. Возвращает полный набор полей.
 * - 1.0.0 (2026-10-14): Initial split from messages.php. Extracted DB helpers,
 *                       permission checks, sidebar rendering, and message formatting logic.
 */

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

/**
 * Сохранение загруженных файлов и привязка их к сообщению
 */
function save_files_and_link_to_message($files_info, $message_uuid, $user_uuid) {
    log_debug("[SAVE_FILES] ========== START ==========");
    if (empty($files_info)) return [];

    $db = msgql_db();
    $uploaded = [];

    $check_user = $db->prepare("SELECT uuid FROM users WHERE uuid = ?");
    $check_user->bind_param("s", $user_uuid);
    $check_user->execute();
    $user_exists = $check_user->get_result()->num_rows > 0;
    $check_user->close();

    if (!$user_exists) {
        log_error("[SAVE_FILES] User not found: {$user_uuid}");
        throw new Exception("User not found: {$user_uuid}");
    }

    try {
        $db->begin_transaction();
        foreach ($files_info as $index => $file_info) {
            $time = msgql_now_ms();
            $time_str = (string)$time;
            $user_tz_offset_minutes = msgql_user_timezone_offset();
            $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
            $stamp = msgql_stamp($user_tz_offset_hours);

            $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssisss", $file_info['uuid'], $file_info['orig_name'], $file_info['storage_name'], $file_info['mime'], $file_info['size_bytes'], $user_uuid, $time_str, $stamp);
            $stmt->execute();
            $stmt->close();

            $link = $db->prepare("INSERT INTO message_files (message_uuid, file_uuid) VALUES (?, ?)");
            $link->bind_param("ss", $message_uuid, $file_info['uuid']);
            $link->execute();
            $link->close();

            $uploaded[] = [
                'uuid' => $file_info['uuid'],
                'name' => $file_info['orig_name'],
                'mime' => $file_info['mime'],
                'size_bytes' => (int)$file_info['size_bytes'],
                'size' => msgql_format_file_size((int)$file_info['size_bytes']),
                'url' => (isset($GLOBALS['appBase']) ? $GLOBALS['appBase'] : '') . '/download.php?file=' . $file_info['uuid']
            ];
        }
        $db->commit();
        log_debug("[SAVE_FILES] SUCCESS, saved " . count($uploaded) . " files");
        return $uploaded;
    } catch (Exception $e) {
        $db->rollback();
        log_error("[SAVE_FILES] ERROR: " . $e->getMessage());
        foreach ($files_info as $file_info) {
            if (isset($file_info['tmp_path']) && file_exists($file_info['tmp_path'])) {
                @unlink($file_info['tmp_path']);
            }
        }
        throw $e;
    }
}

/**
 * Загрузка файлов сообщения с валидацией безопасности
 */
function upload_message_files($files, &$uploaded_files_info, &$error_message = '', &$security_details = null, $ignore_security = false) {
    log_debug("[UPLOAD_MESSAGE_FILES] === START ===");
    if (!class_exists('UploadSecurity')) {
        require_once __DIR__ . '/lib/upload_security.php';
    }

    $upload_dir = __DIR__ . '/uploads/messages/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $error_message = "Не удалось создать директорию для загрузки";
            return false;
        }
    }

    $uploaded_files_info = [];
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return true;
    }

    $hasRealFiles = false;
    foreach ($files['name'] as $name) {
        if (!empty($name)) { $hasRealFiles = true; break; }
    }
    if (!$hasRealFiles) return true;

    $validation = UploadSecurity::validateMultipleFiles($files, null, $ignore_security);
    if (!$validation['valid']) {
        $error_message = $validation['error'];
        $security_details = [];
        foreach ($validation['files'] as $idx => $file_result) {
            if (!$file_result['valid'] && isset($file_result['security_found'])) {
                $security_details[] = [
                    'filename' => $files['name'][$idx],
                    'issues' => $file_result['security_found']
                ];
            }
        }
        return false;
    }

    $validatedFiles = $validation['files'];
    $count = count($validatedFiles);
    for ($i = 0; $i < $count; $i++) {
        $validated = $validatedFiles[$i];
        if (!$validated['valid']) {
            $error_message = $validated['error'];
            return false;
        }

        $safe_name = $validated['safe_name'] ?? $files['name'][$i];
        $orig_name = $files['name'][$i];
        $storage_name = UploadSecurity::generateSecureFilename($safe_name, 'msg');
        $target_path = $upload_dir . $storage_name;

        if (move_uploaded_file($files['tmp_name'][$i], $target_path)) {
            $file_uuid = msgql_uuid_v4();
            $size_formatted = msgql_format_file_size($validated['size']);
            $uploaded_files_info[] = [
                'uuid' => $file_uuid,
                'orig_name' => $orig_name,
                'storage_name' => $storage_name,
                'mime' => $validated['mime'],
                'size_bytes' => $validated['size'],
                'size' => $size_formatted,
                'tmp_path' => $target_path
            ];
        } else {
            $error_message = "Не удалось сохранить файл {$safe_name}";
            return false;
        }
    }
    return true;
}

/**
 * Получение файлов, привязанных к сообщению
 */
function get_message_files($message_uuid) {
    $db = msgql_db();
    $files = [];
    $fstmt = $db->prepare("SELECT f.* FROM files f JOIN message_files mf ON f.uuid = mf.file_uuid WHERE mf.message_uuid = ?");
    if ($fstmt) {
        $fstmt->bind_param("s", $message_uuid);
        $fstmt->execute();
        $fres = $fstmt->get_result();
        while ($file = $fres->fetch_assoc()) {
            $files[] = [
                'uuid' => $file['uuid'],
                'name' => $file['orig_name'],
                'mime' => $file['mime'],
                'size_bytes' => (int)$file['size_bytes'],
                'size' => msgql_format_file_size((int)$file['size_bytes']),
                'url' => (isset($GLOBALS['appBase']) ? $GLOBALS['appBase'] : '') . '/download.php?file=' . $file['uuid']
            ];
        }
        $fstmt->close();
    }
    return $files;
}

/**
 * Получение данных цитируемого (ответного) сообщения
 */
function get_reply_to_data($reply_to_uuid) {
    if (empty($reply_to_uuid)) return null;
    $db = msgql_db();
    $stmt = $db->prepare("SELECT m.uuid, m.text, m.time, u.name as user_name, u.login as user_login FROM messages m LEFT JOIN users u ON m.user_uuid = u.uuid WHERE m.uuid = ?");
    $stmt->bind_param("s", $reply_to_uuid);
    $stmt->execute();
    $reply = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($reply) {
        return [
            'uuid' => $reply['uuid'],
            'text' => $reply['text'],
            'time' => (int)$reply['time'],
            'user_name' => $reply['user_name'] ?: $reply['user_login']
        ];
    }
    return ['uuid' => $reply_to_uuid, 'text' => '', 'time' => 0, 'user_name' => 'Пользователь'];
}

/**
 * Получение полных данных сообщения
 */
function get_message_data($message_uuid) {
    $db = msgql_db();
    $stmt = $db->prepare("
        SELECT m.*, u.name as user_name, u.login as user_login,
               reply.uuid as reply_uuid, reply.text as reply_text, reply.time as reply_time,
               reply_user.name as reply_user_name, reply_user.login as reply_user_login
        FROM messages m
        JOIN users u ON m.user_uuid = u.uuid
        LEFT JOIN messages reply ON m.reply_to_uuid = reply.uuid
        LEFT JOIN users reply_user ON reply.user_uuid = reply_user.uuid
        WHERE m.uuid = ?
    ");
    if (!$stmt) return null;
    $stmt->bind_param("s", $message_uuid);
    $stmt->execute();
    $msg = $stmt->get_result()->fetch_assoc();
    if (!$msg) return null;

    $reply_data = null;
    if (!empty($msg['reply_uuid'])) {
        $reply_text = $msg['reply_text'] ?? '';
        $reply_user_name = $msg['reply_user_name'] ?: $msg['reply_user_login'];
        if (empty($reply_text) || strpos($reply_text, '<') !== false) {
            $orig_stmt = $db->prepare("SELECT text, user_uuid FROM messages WHERE uuid = ?");
            $orig_stmt->bind_param("s", $msg['reply_uuid']);
            $orig_stmt->execute();
            $orig_msg = $orig_stmt->get_result()->fetch_assoc();
            if ($orig_msg) {
                $reply_text = $orig_msg['text'];
                $user_stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                $user_stmt->bind_param("s", $orig_msg['user_uuid']);
                $user_stmt->execute();
                $orig_user = $user_stmt->get_result()->fetch_assoc();
                if ($orig_user) $reply_user_name = $orig_user['name'] ?: $orig_user['login'];
                $user_stmt->close();
            }
            $orig_stmt->close();
        }
        $reply_data = [
            'uuid' => $msg['reply_uuid'],
            'text' => $reply_text,
            'time' => (int)($msg['reply_time'] ?? 0),
            'user_name' => $reply_user_name
        ];
    }

    $files = get_message_files($message_uuid);
    return [
        'uuid' => $msg['uuid'],
        'task_uuid' => $msg['task_uuid'],
        'text' => $msg['text'],
        'time' => (int)$msg['time'],
        'stamp' => $msg['stamp'],
        'user_uuid' => $msg['user_uuid'],
        'user_name' => $msg['user_name'],
        'user_login' => $msg['user_login'],
        'is_read' => (int)$msg['is_read'],
        'reply_to_uuid' => $msg['reply_to_uuid'] ?? null,
        'reply_to' => $reply_data,
        'files' => $files
    ];
}

/**
 * Получение сообщений задачи с обработкой цитат
 */
function get_task_messages($task_uuid, $last_time = 0) {
    $messages = msgql_get_accessible_messages(msgql_current_user_uuid(), $task_uuid, $last_time);
    $db = msgql_db();
    foreach ($messages as &$msg) {
        if (!empty($msg['reply_to']) && !empty($msg['reply_to']['uuid'])) continue;
        if (!empty($msg['reply_to_uuid'])) {
            $reply_to = get_reply_to_data($msg['reply_to_uuid']);
            if ($reply_to) $msg['reply_to'] = $reply_to;
        }
        if (!empty($msg['reply_to_uuid']) && empty($msg['reply_to'])) {
            $msg['reply_to'] = ['uuid' => $msg['reply_to_uuid'], 'text' => '', 'time' => 0, 'user_name' => 'Пользователь'];
        }
    }
    return $messages;
}

/**
 * Пометка всех сообщений задачи как прочитанных
 */
function mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db) {
    if ($is_admin) {
        $sql = "UPDATE messages SET is_read = 1 WHERE task_uuid = ? AND user_uuid != ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $task_uuid, $current_user_uuid);
    } else {
        $sql = "UPDATE messages m SET m.is_read = 1 WHERE m.task_uuid = ? AND m.user_uuid != ? AND EXISTS (SELECT 1 FROM tasks t JOIN projects p ON t.project_uuid = p.uuid LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ? WHERE m.task_uuid = t.uuid AND (t.assigned_to_uuid = ? OR upp.can_view = 1) AND p.created_by_uuid != ?)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $task_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $marked_count = $db->affected_rows;
    $stmt->close();
    return $marked_count;
}

/**
 * Пометка пакета сообщений как прочитанных
 */
function mark_messages_read_batch($message_uuids, $task_uuid, $current_user_uuid, $is_admin, $db) {
    if (empty($message_uuids)) return 0;
    $placeholders = implode(',', array_fill(0, count($message_uuids), '?'));
    if ($is_admin) {
        $sql = "UPDATE messages SET is_read = 1 WHERE uuid IN ($placeholders) AND user_uuid != ?";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 's';
        $params = array_merge($message_uuids, [$current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    } else {
        $sql = "UPDATE messages m SET m.is_read = 1 WHERE m.uuid IN ($placeholders) AND m.user_uuid != ? AND EXISTS (SELECT 1 FROM tasks t JOIN projects p ON t.project_uuid = p.uuid LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ? WHERE m.task_uuid = t.uuid AND (t.assigned_to_uuid = ? OR upp.can_view = 1) AND p.created_by_uuid != ?)";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 'ssss';
        $params = array_merge($message_uuids, [$current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $marked_count = $db->affected_rows;
    $stmt->close();
    return $marked_count;
}

/**
 * Получение количества непрочитанных сообщений для пользователя
 */
function get_unread_count($user_uuid, $is_admin, $db) {
    if ($is_admin) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE is_read = 0 AND user_uuid != ?");
        $stmt->bind_param("s", $user_uuid);
    } else {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT m.uuid) as cnt FROM messages m JOIN tasks t ON m.task_uuid = t.uuid JOIN projects p ON t.project_uuid = p.uuid LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ? WHERE m.is_read = 0 AND m.user_uuid != ? AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)");
        $stmt->bind_param("ssss", $user_uuid, $user_uuid, $user_uuid, $user_uuid);
    }
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

/**
 * Сортировка списка задач по времени последнего сообщения
 */
function sort_tasks_by_last_message_v2(&$taskList, $msg_info) {
    if (empty($taskList)) return;
    usort($taskList, function($a, $b) use ($msg_info) {
        $a_time = (int)($msg_info[$a['uuid']]['last_msg_time'] ?? 0);
        $b_time = (int)($msg_info[$b['uuid']]['last_msg_time'] ?? 0);
        $a_cnt = (int)($msg_info[$a['uuid']]['cnt'] ?? 0);
        $b_cnt = (int)($msg_info[$b['uuid']]['cnt'] ?? 0);
        if ($a_time == 0 && $b_time == 0) {
            if (($a['time'] ?? 0) != ($b['time'] ?? 0)) return ($b['time'] ?? 0) - ($a['time'] ?? 0);
            return $b_cnt - $a_cnt;
        }
        if ($a_time == 0) return 1;
        if ($b_time == 0) return -1;
        if ($a_time != $b_time) return $b_time - $a_time;
        if (($a['time'] ?? 0) != ($b['time'] ?? 0)) return ($b['time'] ?? 0) - ($a['time'] ?? 0);
        return $b_cnt - $a_cnt;
    });
    foreach ($taskList as &$task) {
        if (!empty($task['subtasks'])) sort_tasks_by_last_message_v2($task['subtasks'], $msg_info);
    }
}

/**
 * Сбор задач с иерархией в плоский список для боковой панели
 */
function collectTasksWithHierarchy(&$task_list, &$result, $depth = 0) {
    usort($task_list, function($a, $b) {
        $a_has_unread = ($a['unread_count'] ?? 0) > 0;
        $b_has_unread = ($b['unread_count'] ?? 0) > 0;
        if ($a_has_unread && !$b_has_unread) return -1;
        if (!$a_has_unread && $b_has_unread) return 1;
        if ($a_has_unread && $b_has_unread) {
            if (($a['last_unread_time'] ?? 0) != ($b['last_unread_time'] ?? 0)) return ($b['last_unread_time'] ?? 0) - ($a['last_unread_time'] ?? 0);
        }
        $a_time = (int)($a['last_msg_time'] ?? 0);
        $b_time = (int)($b['last_msg_time'] ?? 0);
        if ($a_time == 0 && $b_time == 0) {
            if (($a['time'] ?? 0) != ($b['time'] ?? 0)) return ($b['time'] ?? 0) - ($a['time'] ?? 0);
            return ($b['messages_count'] ?? 0) - ($a['messages_count'] ?? 0);
        }
        if ($a_time == 0) return 1;
        if ($b_time == 0) return -1;
        if ($a_time != $b_time) return $b_time - $a_time;
        if (($a['time'] ?? 0) != ($b['time'] ?? 0)) return ($b['time'] ?? 0) - ($a['time'] ?? 0);
        return ($b['messages_count'] ?? 0) - ($a['messages_count'] ?? 0);
    });
    foreach ($task_list as &$task) {
        $task['depth'] = $depth;
        $result[] = [
            'uuid' => $task['uuid'],
            'title' => $task['title'],
            'depth' => $depth,
            'assignee_name' => $task['assignee_name'] ?? null,
            'messages_count' => $task['messages_count'] ?? 0,
            'unread_count' => $task['unread_count'] ?? 0,
            'last_msg_time' => $task['last_msg_time'] ?? 0,
            'time' => $task['time'] ?? 0
        ];
        if (!empty($task['subtasks'])) collectTasksWithHierarchy($task['subtasks'], $result, $depth + 1);
    }
    unset($task);
}

/**
 * Получение задач проекта для боковой панели мессенджера
 */
function get_project_tasks_for_messenger($project_uuid) {
    $db = msgql_db();
    $cu = msgql_current_user_uuid();
    $tasks = msgql_get_accessible_tasks($cu, $project_uuid);
    $msg_info = [];
    $task_uuids = [];
    $collect_uuids = function($task_list) use (&$task_uuids, &$collect_uuids) {
        foreach ($task_list as $t) {
            $task_uuids[] = $t['uuid'];
            if (!empty($t['subtasks'])) $collect_uuids($t['subtasks']);
        }
    };
    $collect_uuids($tasks);

    if (!empty($task_uuids)) {
        $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
        $stmt = $db->prepare("SELECT task_uuid, COUNT(*) as cnt, MAX(time) as last_msg_time, SUM(CASE WHEN is_read = 0 AND user_uuid != ? THEN 1 ELSE 0 END) as unread_count, MAX(CASE WHEN is_read = 0 AND user_uuid != ? THEN time ELSE NULL END) as last_unread_time FROM messages WHERE task_uuid IN ($placeholders) GROUP BY task_uuid");
        $types = str_repeat('s', count($task_uuids) + 2);
        $params = array_merge([$cu, $cu], $task_uuids);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $msg_info[$row['task_uuid']] = [
                'cnt' => (int)$row['cnt'],
                'last_msg_time' => (int)$row['last_msg_time'],
                'unread_count' => (int)$row['unread_count'],
                'last_unread_time' => (int)$row['last_unread_time']
            ];
        }
        $stmt->close();
    }

    $task_map = [];
    $walk_map = function($list, $current_depth) use (&$walk_map, &$task_map, $msg_info) {
        foreach ($list as $t) {
            if (!msgql_can_access_task(msgql_current_user_uuid(), $t['uuid'], 'view')) continue;
            $task_map[$t['uuid']] = [
                'uuid' => $t['uuid'],
                'title' => $t['title'],
                'parent_task_uuid' => $t['parent_task_uuid'] ?? null,
                'assignee_name' => $t['assignee_name'] ?? null,
                'unread_count' => $msg_info[$t['uuid']]['unread_count'] ?? 0,
                'last_unread_time' => $msg_info[$t['uuid']]['last_unread_time'] ?? 0,
                'last_msg_time' => $msg_info[$t['uuid']]['last_msg_time'] ?? 0,
                'messages_count' => $msg_info[$t['uuid']]['cnt'] ?? 0,
                'time' => $t['time'] ?? 0,
                'depth' => $current_depth
            ];
            if (!empty($t['subtasks'])) $walk_map($t['subtasks'], $current_depth + 1);
        }
    };
    $walk_map($tasks, 0);

    $tree = [];
    foreach ($task_map as $uuid => &$task) {
        if (empty($task['parent_task_uuid']) || !isset($task_map[$task['parent_task_uuid']])) {
            $tree[] = &$task;
        } else {
            if (!isset($task_map[$task['parent_task_uuid']]['subtasks'])) $task_map[$task['parent_task_uuid']]['subtasks'] = [];
            $task_map[$task['parent_task_uuid']]['subtasks'][] = &$task;
        }
    }
    unset($task);

    $flat_result = [];
    collectTasksWithHierarchy($tree, $flat_result);
    return $flat_result;
}

/**
 * Получение последней задачи с непрочитанными сообщениями
 */
function get_latest_task_with_unread(string $user_uuid, bool $is_admin, mysqli $db): ?array {
    // 1. Пробуем найти задачу с непрочитанными сообщениями
    if ($is_admin) {
        $sql = "SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title as project_title, m.time as last_message_time FROM messages m JOIN tasks t ON m.task_uuid = t.uuid JOIN projects p ON t.project_uuid = p.uuid WHERE m.is_read = 0 AND m.user_uuid != ? ORDER BY m.time DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $user_uuid);
    } else {
        $sql = "SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title AS project_title, m.time AS last_message_time
                FROM messages m
                JOIN tasks t ON m.task_uuid = t.uuid
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE m.is_read = 0 AND m.user_uuid != ?
                AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
                ORDER BY m.time DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $user_uuid, $user_uuid, $user_uuid, $user_uuid);
    }
    if (!$stmt) return null;
    $stmt->execute();
    $task_with_unread = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($task_with_unread) {
        return [
            'uuid' => $task_with_unread['uuid'],
            'title' => $task_with_unread['title'],
            'project_uuid' => $task_with_unread['project_uuid'],
            'project_title' => $task_with_unread['project_title'],
            'last_message_time' => (int)$task_with_unread['last_message_time']
        ];
    }

    // 2. Fallback — последняя активная задача
    if ($is_admin) {
        $sql = "SELECT t.uuid, t.title, t.project_uuid, p.title AS project_title, MAX(m.time) AS last_message_time
                FROM tasks t
                JOIN messages m ON t.uuid = m.task_uuid
                JOIN projects p ON t.project_uuid = p.uuid
                GROUP BY t.uuid
                ORDER BY last_message_time DESC LIMIT 1";
        $stmt = $db->prepare($sql);
    } else {
        $sql = "SELECT t.uuid, t.title, t.project_uuid, p.title AS project_title, MAX(m.time) AS last_message_time
                FROM tasks t
                JOIN messages m ON t.uuid = m.task_uuid
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                GROUP BY t.uuid
                ORDER BY last_message_time DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if ($stmt) $stmt->bind_param("ss", $user_uuid, $user_uuid);
    }
    if (!$stmt) return null;
    $stmt->execute();
    $last_active = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($last_active) {
        return [
            'uuid' => $last_active['uuid'],
            'title' => $last_active['title'],
            'project_uuid' => $last_active['project_uuid'],
            'project_title' => $last_active['project_title'],
            'last_message_time' => (int)$last_active['last_message_time']
        ];
    }
    return null;
}

if (!function_exists('get_users_with_project_access')) {
    /**
     * Получение списка пользователей, имеющих доступ к проекту.
     * Используется в messages_api.php (action=get_project_users).
     */
    function get_users_with_project_access($db, $project_uuid, $current_user_uuid, $is_admin) {
        if ($is_admin) {
            $stmt = $db->prepare("SELECT uuid, name, login FROM users WHERE status = 0 ORDER BY name, login");
            $stmt->execute();
            $result = $stmt->get_result();
            $users = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            log_debug("[GET_USERS_WITH_ACCESS] Admin mode: found " . count($users) . " users");
            return $users;
        }

        if (empty($project_uuid) || empty($current_user_uuid)) {
            log_warning("[GET_USERS_WITH_ACCESS] Missing parameters");
            return [];
        }

        $stmt = $db->prepare("
            SELECT DISTINCT u.uuid, u.name, u.login
            FROM users u
            WHERE u.status = 0
            AND (
                u.role = 0
                OR u.uuid = ?
                OR u.uuid IN (SELECT assigned_to_uuid FROM tasks WHERE project_uuid = ? AND assigned_to_uuid IS NOT NULL)
                OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_view = 1)
                OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_edit_tasks = 1)
                OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_edit_messages = 1)
                OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_upload_files = 1)
            )
            ORDER BY u.name, u.login
        ");

        $stmt->bind_param(
            "ssssss",
            $current_user_uuid,
            $project_uuid,
            $project_uuid,
            $project_uuid,
            $project_uuid,
            $project_uuid
        );

        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        log_debug("[GET_USERS_WITH_ACCESS] Found " . count($users) . " users with access to project: {$project_uuid}");
        return $users;
    }
}