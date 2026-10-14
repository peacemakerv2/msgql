<?php
/**
 * projects_helpers.php
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from projects.php. Extracted business logic helpers:
 *                       get_last_active_project, check_user_upload_quota, generate_unique_filename,
 *                       delete_task_recursive, delete_project_recursive, get_task_with_parents,
 *                       get_task_parent_chain, get_all_tasks_matching_filters, get_project_tasks_sorted,
 *                       get_project_subtasks_sorted, get_project_data, get_task_data, get_task_files,
 *                       get_all_users, get_users_with_project_access, parseDescriptionLinks.
 */

if (!function_exists('get_last_active_project')) {
    /**
     * Получение последнего активного проекта пользователя
     */
    function get_last_active_project($user_uuid, $is_admin, $db) {
        log_debug("[GET_LAST_ACTIVE_PROJECT] Called for user: {$user_uuid}");

        if ($is_admin) {
            $stmt = $db->prepare("
                SELECT p.uuid, p.title, MAX(COALESCE(m.time, t.time, p.time)) as last_activity
                FROM projects p
                LEFT JOIN tasks t ON p.uuid = t.project_uuid
                LEFT JOIN messages m ON t.uuid = m.task_uuid
                GROUP BY p.uuid
                ORDER BY last_activity DESC
                LIMIT 1
            ");
        } else {
            $stmt = $db->prepare("
                SELECT p.uuid, p.title, MAX(COALESCE(m.time, t.time, p.time)) as last_activity
                FROM projects p
                LEFT JOIN tasks t ON p.uuid = t.project_uuid
                LEFT JOIN messages m ON t.uuid = m.task_uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE p.created_by_uuid = ? OR upp.can_view = 1
                GROUP BY p.uuid
                ORDER BY last_activity DESC
                LIMIT 1
            ");
            $stmt->bind_param("ss", $user_uuid, $user_uuid);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $project = $result->fetch_assoc();
        $stmt->close();

        log_debug("[GET_LAST_ACTIVE_PROJECT] Found project: " . ($project['uuid'] ?? 'none'));
        return $project;
    }
}

if (!function_exists('check_user_upload_quota')) {
    /**
     * Проверка квоты на загрузку файлов пользователем
     */
    function check_user_upload_quota(string $user_uuid, mysqli $db): array {
        $max_files_per_day = 100;
        $max_size_per_day = 500 * 1024 * 1024; // 500 MB

        $today_start = strtotime('today 00:00:00') * 1000;
        $today_start_str = (string)$today_start;

        $stmt = $db->prepare("
            SELECT COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_size
            FROM files
            WHERE uploaded_by_uuid = ? AND time >= ?
        ");

        $stmt->bind_param("ss", $user_uuid, $today_start_str);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $file_count = (int)($result['file_count'] ?? 0);
        $total_size = (int)($result['total_size'] ?? 0);

        $size_mb = round($total_size / 1048576, 2);
        log_debug("[UPLOAD_QUOTA] User {$user_uuid} - files today: {$file_count}, size today: {$size_mb} MB");

        if ($file_count >= $max_files_per_day) {
            log_warning("[UPLOAD_QUOTA] User {$user_uuid} exceeded daily file limit: {$file_count}/{$max_files_per_day}");
            return [
                'allowed' => false,
                'reason' => 'file_count_exceeded',
                'message' => "Превышен дневной лимит файлов ({$max_files_per_day} файлов в день). Загружено сегодня: {$file_count}."
            ];
        }

        if ($total_size >= $max_size_per_day) {
            log_warning("[UPLOAD_QUOTA] User {$user_uuid} exceeded daily size limit: {$size_mb} MB / " . round($max_size_per_day / 1048576, 2) . " MB");
            return [
                'allowed' => false,
                'reason' => 'size_exceeded',
                'message' => "Превышен дневной лимит объёма загрузок (500 MB в день). Загружено сегодня: {$size_mb} MB."
            ];
        }

        return ['allowed' => true, 'reason' => '', 'message' => ''];
    }
}

if (!function_exists('generate_unique_filename')) {
    /**
     * Генерирует уникальное имя файла с проверкой на существование
     */
    function generate_unique_filename(string $original_name, string $prefix, string $upload_dir, int $max_attempts = 10) {
        if (!is_dir($upload_dir)) {
            log_error("[FILE_NAME] Upload directory does not exist: {$upload_dir}");
            if (!mkdir($upload_dir, 0777, true)) {
                log_error("[FILE_NAME] Failed to create upload directory: {$upload_dir}");
                return false;
            }
            log_debug("[FILE_NAME] Created upload directory: {$upload_dir}");
        }

        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        $ext = $ext ? '.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) : '';
        if (strlen($ext) > 10) {
            $ext = '';
            log_debug("[FILE_NAME] Extension too long, removed");
        }

        $date = date('Ymd_His');
        $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix);
        if (strlen($prefix) > 20) {
            $prefix = substr($prefix, 0, 20);
            log_debug("[FILE_NAME] Prefix truncated to 20 chars");
        }

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $random = bin2hex(random_bytes(32));
            $filename = sprintf('%s_%s_%s%s', $prefix, $date, $random, $ext);

            if (!file_exists($upload_dir . $filename)) {
                log_debug("[FILE_NAME] Generated unique filename after {$attempt} attempts: {$filename}");
                return $filename;
            }

            log_debug("[FILE_NAME] Collision detected for: {$filename}, retrying...");
        }

        log_error("[FILE_NAME] Failed to generate unique filename after {$max_attempts} attempts");
        return false;
    }
}

if (!function_exists('delete_task_recursive')) {
    /**
     * Рекурсивное удаление задачи со всеми подзадачами, сообщениями и файлами
     */
    function delete_task_recursive($task_uuid, $db, $depth = 0) {
        $max_depth = 100;
        if ($depth > $max_depth) {
            log_error("[DELETE_TASK_RECURSIVE] MAX DEPTH EXCEEDED ({$depth}) for task: {$task_uuid}");
            log_debug("[DELETE_TASK_RECURSIVE] Possible circular reference or extremely deep nesting");
            return false;
        }

        log_debug("[DELETE_TASK_RECURSIVE] Starting deletion of task: {$task_uuid} (depth: {$depth})");

        $sub_stmt = $db->prepare("SELECT uuid FROM tasks WHERE parent_task_uuid = ?");
        $sub_stmt->bind_param("s", $task_uuid);
        $sub_stmt->execute();
        $subtasks = $sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sub_stmt->close();

        $subtask_count = count($subtasks);
        log_debug("[DELETE_TASK_RECURSIVE] Found {$subtask_count} subtasks for task: {$task_uuid}");

        foreach ($subtasks as $subtask) {
            $result = delete_task_recursive($subtask['uuid'], $db, $depth + 1);
            if ($result === false) {
                log_error("[DELETE_TASK_RECURSIVE] Failed to delete subtask: {$subtask['uuid']}");
            }
        }

        $msgs_stmt = $db->prepare("SELECT uuid FROM messages WHERE task_uuid = ?");
        $msgs_stmt->bind_param("s", $task_uuid);
        $msgs_stmt->execute();
        $messages = $msgs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $msgs_stmt->close();

        log_debug("[DELETE_TASK_RECURSIVE] Found " . count($messages) . " messages for task: {$task_uuid}");

        foreach ($messages as $msg) {
            $files_stmt = $db->prepare("SELECT file_uuid FROM message_files WHERE message_uuid = ?");
            $files_stmt->bind_param("s", $msg['uuid']);
            $files_stmt->execute();
            $file_uuids = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $files_stmt->close();

            $del_mf = $db->prepare("DELETE FROM message_files WHERE message_uuid = ?");
            $del_mf->bind_param("s", $msg['uuid']);
            $del_mf->execute();
            $del_mf->close();

            foreach ($file_uuids as $f) {
                $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                $check_msg->bind_param("s", $f['file_uuid']);
                $check_msg->execute();
                $msg_refs = $check_msg->get_result()->fetch_assoc()['cnt'];
                $check_msg->close();

                $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                $check_task->bind_param("s", $f['file_uuid']);
                $check_task->execute();
                $task_refs = $check_task->get_result()->fetch_assoc()['cnt'];
                $check_task->close();

                if ($msg_refs == 0 && $task_refs == 0) {
                    $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                    $file_stmt->bind_param("s", $f['file_uuid']);
                    $file_stmt->execute();
                    $file_row = $file_stmt->get_result()->fetch_assoc();
                    $file_stmt->close();

                    if ($file_row) {
                        $paths = [
                            __DIR__ . '/uploads/messages/' . $file_row['storage_name'],
                            __DIR__ . '/uploads/tasks/' . $file_row['storage_name']
                        ];
                        foreach ($paths as $path) {
                            if (file_exists($path)) {
                                if (@unlink($path)) {
                                    log_debug("[DELETE_TASK_RECURSIVE] Deleted file: {$path}");
                                } else {
                                    log_error("[DELETE_TASK_RECURSIVE] Failed to delete file: {$path}");
                                }
                            }
                        }
                    }

                    $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                    $del_file->bind_param("s", $f['file_uuid']);
                    $del_file->execute();
                    $del_file->close();
                    log_debug("[DELETE_TASK_RECURSIVE] Deleted file record: {$f['file_uuid']}");
                }
            }

            $del_msg = $db->prepare("DELETE FROM messages WHERE uuid = ?");
            $del_msg->bind_param("s", $msg['uuid']);
            $del_msg->execute();
            $del_msg->close();
            log_debug("[DELETE_TASK_RECURSIVE] Deleted message: {$msg['uuid']}");
        }

        $files_stmt = $db->prepare("SELECT f.uuid, f.storage_name FROM files f
            JOIN task_files tf ON f.uuid = tf.file_uuid
            WHERE tf.task_uuid = ?");
        $files_stmt->bind_param("s", $task_uuid);
        $files_stmt->execute();
        $files = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $files_stmt->close();

        $del_tf = $db->prepare("DELETE FROM task_files WHERE task_uuid = ?");
        $del_tf->bind_param("s", $task_uuid);
        $del_tf->execute();
        $del_tf->close();

        foreach ($files as $file) {
            $check_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
            $check_stmt->bind_param("s", $file['uuid']);
            $check_stmt->execute();
            $refs = $check_stmt->get_result()->fetch_assoc()['cnt'];
            $check_stmt->close();

            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
            $check_msg->bind_param("s", $file['uuid']);
            $check_msg->execute();
            $msg_refs = $check_msg->get_result()->fetch_assoc()['cnt'];
            $check_msg->close();

            if ($refs == 0 && $msg_refs == 0) {
                $file_path = __DIR__ . '/uploads/tasks/' . $file['storage_name'];
                if (file_exists($file_path)) {
                    if (@unlink($file_path)) {
                        log_debug("[DELETE_TASK_RECURSIVE] Deleted task file: {$file_path}");
                    } else {
                        log_error("[DELETE_TASK_RECURSIVE] Failed to delete task file: {$file_path}");
                    }
                }

                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                $del_file->bind_param("s", $file['uuid']);
                $del_file->execute();
                $del_file->close();
                log_debug("[DELETE_TASK_RECURSIVE] Deleted file record: {$file['uuid']}");
            }
        }

        $del_task = $db->prepare("DELETE FROM tasks WHERE uuid = ?");
        $del_task->bind_param("s", $task_uuid);
        $del_task->execute();
        $del_task->close();

        log_debug("[DELETE_TASK_RECURSIVE] Task deleted: {$task_uuid} (depth: {$depth})");
        return true;
    }
}

if (!function_exists('delete_project_recursive')) {
    /**
     * Рекурсивное удаление проекта со всеми задачами, сообщениями и файлами
     */
    function delete_project_recursive($project_uuid, $db) {
        log_debug("[DELETE_PROJECT_RECURSIVE] Starting deletion of project: {$project_uuid}");

        $files_stmt = $db->prepare("
            SELECT DISTINCT f.uuid, f.storage_name 
            FROM files f
            WHERE f.uuid IN (
                SELECT tf.file_uuid FROM task_files tf
                JOIN tasks t ON tf.task_uuid = t.uuid
                WHERE t.project_uuid = ?
            ) OR f.uuid IN (
                SELECT mf.file_uuid FROM message_files mf
                JOIN messages m ON mf.message_uuid = m.uuid
                JOIN tasks t ON m.task_uuid = t.uuid
                WHERE t.project_uuid = ?
            )
        ");
        $files_stmt->bind_param("ss", $project_uuid, $project_uuid);
        $files_stmt->execute();
        $all_files = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $files_stmt->close();

        $tasks_stmt = $db->prepare("SELECT uuid FROM tasks WHERE project_uuid = ?");
        $tasks_stmt->bind_param("s", $project_uuid);
        $tasks_stmt->execute();
        $tasks = $tasks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $tasks_stmt->close();

        foreach ($tasks as $task) {
            delete_task_recursive($task['uuid'], $db);
        }

        foreach ($all_files as $file) {
            $paths = [
                __DIR__ . '/uploads/messages/' . $file['storage_name'],
                __DIR__ . '/uploads/tasks/' . $file['storage_name']
            ];
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                    log_debug("[DELETE_PROJECT_RECURSIVE] Deleted file: {$path}");
                }
            }

            $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
            $del_file->bind_param("s", $file['uuid']);
            $del_file->execute();
            $del_file->close();
        }

        $del_perm = $db->prepare("DELETE FROM user_project_permissions WHERE project_uuid = ?");
        $del_perm->bind_param("s", $project_uuid);
        $del_perm->execute();
        $del_perm->close();

        $del_proj = $db->prepare("DELETE FROM projects WHERE uuid = ?");
        $del_proj->bind_param("s", $project_uuid);
        $del_proj->execute();
        $del_proj->close();

        log_debug("[DELETE_PROJECT_RECURSIVE] Project deleted: {$project_uuid}");
    }
}

if (!function_exists('get_task_with_parents')) {
    /**
     * Получение задачи со всеми родителями (рекурсивно)
     */
    function get_task_with_parents($task_uuid, $db) {
        $stmt = $db->prepare("
            WITH RECURSIVE task_tree AS (
                SELECT uuid, parent_task_uuid, project_uuid, title
                FROM tasks WHERE uuid = ?
                UNION ALL
                SELECT t.uuid, t.parent_task_uuid, t.project_uuid, t.title
                FROM tasks t
                INNER JOIN task_tree tt ON t.uuid = tt.parent_task_uuid
            )
            SELECT uuid, parent_task_uuid, project_uuid, title FROM task_tree
        ");
        $stmt->bind_param("s", $task_uuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $parents = [];
        while ($row = $result->fetch_assoc()) {
            $parents[] = $row;
        }
        $stmt->close();
        return $parents;
    }
}

if (!function_exists('get_task_parent_chain')) {
    /**
     * Получение цепочки родителей задачи (итеративный fallback)
     */
    function get_task_parent_chain($task_uuid, $db) {
        $chain = [];
        $visited = [];
        $current = $task_uuid;
        $max_depth = 50;

        $stmt = $db->prepare("SELECT parent_task_uuid FROM tasks WHERE uuid = ?");
        if (!$stmt) {
            log_error("[GET_TASK_PARENT_CHAIN] Prepare failed: " . $db->error);
            return $chain;
        }

        $depth = 0;
        while ($current && $depth < $max_depth) {
            if (isset($visited[$current])) {
                log_warning("[GET_TASK_PARENT_CHAIN] Cycle detected at task: {$current}");
                break;
            }
            $visited[$current] = true;

            $stmt->bind_param("s", $current);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if ($row && !empty($row['parent_task_uuid'])) {
                $parent = $row['parent_task_uuid'];
                log_debug("[GET_TASK_PARENT_CHAIN] Found parent '{$parent}' for task '{$current}' (depth: {$depth})");
                array_unshift($chain, $parent);
                $current = $parent;
            } else {
                log_debug("[GET_TASK_PARENT_CHAIN] No more parents for task '{$current}', reached root");
                $current = null;
            }
            $depth++;
        }

        if ($depth >= $max_depth) {
            log_warning("[GET_TASK_PARENT_CHAIN] Reached max depth ({$max_depth}) for task: {$task_uuid}");
        }

        $stmt->close();

        log_debug("[GET_TASK_PARENT_CHAIN] Final chain (" . count($chain) . " parents) for task {$task_uuid}: " . json_encode($chain));
        return $chain;
    }
}

if (!function_exists('get_all_tasks_matching_filters')) {
    /**
     * Получение всех задач, соответствующих фильтрам (для режима фильтрации)
     */
    function get_all_tasks_matching_filters($project_uuid, $user_uuid, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
        $where_conditions = ["t.project_uuid = ?"];
        $params = [$project_uuid];
        $types = "s";

        if (!$is_admin) {
            $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
            $params[] = $user_uuid;
            $types .= "s";
        }

        if (!empty($filter_statuses) && is_array($filter_statuses)) {
            $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
            $where_conditions[] = "t.status IN ($status_placeholders)";
            foreach ($filter_statuses as $status) {
                $params[] = (int)$status;
                $types .= "i";
            }
        }

        if (!empty($filter_assigned) && is_array($filter_assigned)) {
            $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
            $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
            foreach ($filter_assigned as $assigned_uuid) {
                $params[] = $assigned_uuid;
                $types .= "s";
            }
        }

        if (!empty($search)) {
            $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $types .= "ss";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);

        $sql = "SELECT t.uuid, t.parent_task_uuid, t.title, t.status, t.assigned_to_uuid
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            {$where_clause}
            LIMIT 5000";

        $all_params = array_merge([$user_uuid], $params);
        $all_types = "s" . $types;

        $stmt = $db->prepare($sql);
        $stmt->bind_param($all_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result;
    }
}

if (!function_exists('get_project_tasks_sorted')) {
    /**
     * Получение задач проекта с сортировкой, фильтрацией и пагинацией
     */
    function get_project_tasks_sorted($project_uuid, $user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
        log_debug("[GET_PROJECT_TASKS_SORTED] v4.16 - project_uuid: {$project_uuid}, sort_by: {$sort_by}, sort_dir: {$sort_dir}");
        log_debug("[GET_PROJECT_TASKS_SORTED] filter_statuses: " . json_encode($filter_statuses));
        log_debug("[GET_PROJECT_TASKS_SORTED] filter_assigned: " . json_encode($filter_assigned));
        log_debug("[GET_PROJECT_TASKS_SORTED] search: '{$search}'");

        $offset = ($page - 1) * $per_page;

        $where_conditions = ["t.project_uuid = ?"];
        $params = [$project_uuid];
        $types = "s";

        $has_filters = !empty($filter_statuses) || !empty($filter_assigned) || !empty($search);

        if (!$has_filters) {
            $where_conditions[] = "(t.parent_task_uuid IS NULL OR t.parent_task_uuid = '')";
            log_debug("[GET_PROJECT_TASKS_SORTED] No filters - showing root tasks only");
        } else {
            log_debug("[GET_PROJECT_TASKS_SORTED] Filters active - showing ALL tasks (including subtasks)");
        }

        if (!$is_admin) {
            $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
            $params[] = $user_uuid;
            $types .= "s";
        }

        if (!empty($filter_statuses) && is_array($filter_statuses)) {
            $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
            $where_conditions[] = "t.status IN ($status_placeholders)";
            foreach ($filter_statuses as $status) {
                $params[] = (int)$status;
                $types .= "i";
            }
            log_debug("[FILTER] Status filter applied: " . implode(',', $filter_statuses));
        }

        if (!empty($filter_assigned) && is_array($filter_assigned)) {
            $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
            $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
            foreach ($filter_assigned as $assigned_uuid) {
                $params[] = $assigned_uuid;
                $types .= "s";
            }
            log_debug("[FILTER] Assigned filter applied: " . count($filter_assigned) . " users");
        }

        if (!empty($search)) {
            $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $types .= "ss";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);

        $order_column = '';
        switch ($sort_by) {
            case 'title':
                $order_column = 't.title';
                break;
            case 'status':
                $order_column = 't.status';
                break;
            case 'deadline':
                $order_column = 't.time_end_plan';
                break;
            case 'last_activity':
            default:
                $order_column = 'COALESCE(last_msg_time, t.time)';
                $sort_by = 'last_activity';
                break;
        }

        $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';
        $order_secondary = ($sort_by === 'last_activity') ? ', t.time DESC' : '';

        $table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
        $has_notifications_table = ($table_check && $table_check->num_rows > 0);

        $all_tasks_sql = "SELECT t.uuid FROM tasks t
                          JOIN projects p ON t.project_uuid = p.uuid
                          LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                          {$where_clause}";

        $all_tasks_stmt = $db->prepare($all_tasks_sql);
        $all_tasks_params = array_merge([$user_uuid], $params);
        $all_tasks_types = "s" . $types;
        $all_tasks_stmt->bind_param($all_tasks_types, ...$all_tasks_params);
        $all_tasks_stmt->execute();
        $all_tasks_result = $all_tasks_stmt->get_result();
        $task_uuids = [];
        while ($row = $all_tasks_result->fetch_assoc()) {
            $task_uuids[] = $row['uuid'];
        }
        $all_tasks_stmt->close();

        $unread_counts = [];
        if ($has_notifications_table && !empty($task_uuids)) {
            $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
            $unread_sql = "
                SELECT n.task_uuid, COUNT(*) as unread_count
                FROM user_notifications n
                WHERE n.user_uuid = ?
                AND n.is_read = 0
                AND n.task_uuid IN ($placeholders)
                GROUP BY n.task_uuid
            ";
            $unread_stmt = $db->prepare($unread_sql);
            $unread_params = array_merge([$user_uuid], $task_uuids);
            $unread_types = 's' . str_repeat('s', count($task_uuids));
            $unread_stmt->bind_param($unread_types, ...$unread_params);
            $unread_stmt->execute();
            $unread_result = $unread_stmt->get_result();
            while ($row = $unread_result->fetch_assoc()) {
                $unread_counts[$row['task_uuid']] = (int)$row['unread_count'];
            }
            $unread_stmt->close();
            log_debug("[GET_PROJECT_TASKS_SORTED] Found " . count($unread_counts) . " tasks with unread notifications");
        }

        $count_sql = "SELECT COUNT(*) as total 
                      FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      {$where_clause}";

        $count_params = array_merge([$user_uuid], $params);
        $count_types = "s" . $types;

        $stmt = $db->prepare($count_sql);
        $stmt->bind_param($count_types, ...$count_params);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        log_debug("[GET_PROJECT_TASKS_SORTED] Total tasks matching filters: {$total}");

        if ($total === 0) {
            return ['tasks' => [], 'total' => 0, 'has_more' => false];
        }

        $base_params = array_merge([$user_uuid], $params);
        $base_types = "s" . $types;

        if ($has_notifications_table) {
            $data_sql = "SELECT t.*, 
                                u.name as assignee_name, 
                                u.login as assignee_login,
                                (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                                (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                                (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                                (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count,
                                COALESCE(unc.unread_count, 0) as unread_notifications_count
                         FROM tasks t
                         JOIN projects p ON t.project_uuid = p.uuid
                         LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                         LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                         LEFT JOIN (
                             SELECT task_uuid, COUNT(*) as unread_count
                             FROM user_notifications
                             WHERE user_uuid = ?
                             AND is_read = 0
                             GROUP BY task_uuid
                         ) unc ON t.uuid = unc.task_uuid
                         {$where_clause}
                         ORDER BY 
                             CASE WHEN COALESCE(unc.unread_count, 0) > 0 THEN 0 ELSE 1 END ASC,
                             {$order_column} {$order_dir}{$order_secondary}
                         LIMIT ? OFFSET ?";

            $data_params = array_merge([$user_uuid], [$user_uuid], $params, [$per_page, $offset]);
            $data_types = "ss" . $types . "ii";

            log_debug("[GET_PROJECT_TASKS_SORTED] Data params count: " . count($data_params) . ", types: " . $data_types);

        } else {
            $data_sql = "SELECT t.*, 
                                u.name as assignee_name, 
                                u.login as assignee_login,
                                (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                                (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                                (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                                (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count,
                                0 as unread_notifications_count
                         FROM tasks t
                         JOIN projects p ON t.project_uuid = p.uuid
                         LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                         LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                         {$where_clause}
                         ORDER BY {$order_column} {$order_dir}{$order_secondary}
                         LIMIT ? OFFSET ?";

            $data_params = array_merge([$user_uuid], $params, [$per_page, $offset]);
            $data_types = "s" . $types . "ii";
        }

        $stmt = $db->prepare($data_sql);

        if (count($data_params) !== substr_count($data_types, 's') + substr_count($data_types, 'i')) {
            log_error("[GET_PROJECT_TASKS_SORTED] Parameter count mismatch: params=" . count($data_params) . ", types_count=" . (substr_count($data_types, 's') + substr_count($data_types, 'i')));
            log_error("[GET_PROJECT_TASKS_SORTED] data_types: " . $data_types);
            log_error("[GET_PROJECT_TASKS_SORTED] data_params: " . json_encode($data_params));
            return ['tasks' => [], 'total' => 0, 'has_more' => false];
        }

        $stmt->bind_param($data_types, ...$data_params);
        $stmt->execute();
        $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($tasks as &$task) {
            $task['can_edit'] = msgql_can_edit_task($user_uuid, $task['uuid']);
            $task['messages_count'] = (int)($task['messages_count'] ?? 0);
            $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
            $task['files_count'] = (int)($task['files_count'] ?? 0);
            $task['status'] = (int)($task['status'] ?? 0);
            $task['unread_notifications_count'] = (int)($task['unread_notifications_count'] ?? 0);
            $task['has_unread_notifications'] = ($task['unread_notifications_count'] > 0);
        }

        log_debug("[GET_PROJECT_TASKS_SORTED] Returning " . count($tasks) . " tasks, " . count(array_filter($tasks, function($t) { return $t['has_unread_notifications']; })) . " with unread notifications");

        return [
            'tasks' => $tasks,
            'total' => $total,
            'has_more' => ($offset + $per_page) < $total,
            'page' => $page,
            'per_page' => $per_page
        ];
    }
}

if (!function_exists('get_project_subtasks_sorted')) {
    /**
     * Получение подзадач с сортировкой и пагинацией
     */
    function get_project_subtasks_sorted($project_uuid, $parent_uuid, $user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db) {
        log_debug("[GET_PROJECT_SUBTASKS_SORTED] v4.8 - project_uuid: {$project_uuid}, parent_uuid: {$parent_uuid}, page: {$page}, per_page: {$per_page}");

        if (empty($parent_uuid)) {
            log_warning("[GET_PROJECT_SUBTASKS_SORTED] parent_uuid is empty, returning empty result");
            return ['tasks' => [], 'total' => 0, 'has_more' => false];
        }

        $offset = ($page - 1) * $per_page;

        $where_conditions = ["t.project_uuid = ?", "t.parent_task_uuid = ?"];
        $params = [$project_uuid, $parent_uuid];
        $types = "ss";

        if (!$is_admin) {
            $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
            $params[] = $user_uuid;
            $types .= "s";
        }

        if (!empty($filter_statuses) && is_array($filter_statuses)) {
            $status_placeholders = implode(',', array_fill(0, count($filter_statuses), '?'));
            $where_conditions[] = "t.status IN ($status_placeholders)";
            foreach ($filter_statuses as $status) {
                $params[] = (int)$status;
                $types .= "i";
            }
        }

        if (!empty($filter_assigned) && is_array($filter_assigned)) {
            $assigned_placeholders = implode(',', array_fill(0, count($filter_assigned), '?'));
            $where_conditions[] = "t.assigned_to_uuid IN ($assigned_placeholders)";
            foreach ($filter_assigned as $assigned_uuid) {
                $params[] = $assigned_uuid;
                $types .= "s";
            }
        }

        if (!empty($search)) {
            $where_conditions[] = "(t.title LIKE ? OR t.descr LIKE ?)";
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $types .= "ss";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_conditions);

        $order_column = '';
        switch ($sort_by) {
            case 'title':
                $order_column = 't.title';
                break;
            case 'status':
                $order_column = 't.status';
                break;
            case 'deadline':
                $order_column = 't.time_end_plan';
                break;
            case 'last_activity':
            default:
                $order_column = 'COALESCE(last_msg_time, t.time)';
                break;
        }

        $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) as total 
                      FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      {$where_clause}";

        $count_params = array_merge([$user_uuid], $params);
        $count_types = "s" . $types;

        $stmt = $db->prepare($count_sql);
        $stmt->bind_param($count_types, ...$count_params);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        log_debug("[GET_PROJECT_SUBTASKS_SORTED] Total subtasks for parent {$parent_uuid}: {$total}");

        if ($total === 0) {
            return ['tasks' => [], 'total' => 0, 'has_more' => false];
        }

        $data_sql = "SELECT t.*, 
                            u.name as assignee_name, 
                            u.login as assignee_login,
                            (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                            (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                            (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                            (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count
                     FROM tasks t
                     JOIN projects p ON t.project_uuid = p.uuid
                     LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                     LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                     {$where_clause}
                     ORDER BY {$order_column} {$order_dir}
                     LIMIT ? OFFSET ?";

        $data_params = array_merge([$user_uuid], $params, [$per_page, $offset]);
        $data_types = "s" . $types . "ii";

        $stmt = $db->prepare($data_sql);
        $stmt->bind_param($data_types, ...$data_params);
        $stmt->execute();
        $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($tasks as &$task) {
            $task['can_edit'] = msgql_can_edit_task($user_uuid, $task['uuid']);
            $task['messages_count'] = (int)($task['messages_count'] ?? 0);
            $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
            $task['files_count'] = (int)($task['files_count'] ?? 0);
            $task['status'] = (int)($task['status'] ?? 0);
        }

        log_debug("[GET_PROJECT_SUBTASKS_SORTED] Returning " . count($tasks) . " subtasks for parent {$parent_uuid}");

        usort($tasks, function($a, $b) {
            $timeA = $a['last_msg_time'] ?? $a['time'] ?? 0;
            $timeB = $b['last_msg_time'] ?? $b['time'] ?? 0;
            return $timeB - $timeA;
        });

        return [
            'tasks' => $tasks,
            'total' => $total,
            'has_more' => ($offset + $per_page) < $total,
            'page' => $page,
            'per_page' => $per_page
        ];
    }
}

if (!function_exists('get_project_data')) {
    /**
     * Получение данных проекта
     */
    function get_project_data($uuid, $db) {
        $stmt = $db->prepare("SELECT p.*,
            u.name as creator_name,
            u.login as creator_login,
            (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
            FROM projects p
            LEFT JOIN users u ON p.created_by_uuid = u.uuid
            WHERE p.uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }
}

if (!function_exists('get_task_data')) {
    /**
     * Получение данных задачи с проверкой доступа
     */
    function get_task_data($uuid, $current_user_uuid, $db) {
        if (!msgql_can_access_task($current_user_uuid, $uuid, 'view')) {
            return null;
        }

        $stmt = $db->prepare("SELECT t.*, 
            u.name as assignee_name, 
            u.login as assignee_login,
            (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
            (SELECT COUNT(*) FROM messages WHERE task_uuid = t.uuid) as messages_count
            FROM tasks t 
            LEFT JOIN users u ON t.assigned_to_uuid = u.uuid 
            WHERE t.uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($task) {
            $task['time_start_utc'] = $task['time_start'];
            $task['time_end_plan_utc'] = $task['time_end_plan'];
            $task['status'] = (int)($task['status'] ?? 0);
            $task['messages_count'] = (int)($task['messages_count'] ?? 0);
            $task['subtasks_count'] = (int)($task['subtasks_count'] ?? 0);
        }
        return $task;
    }
}

if (!function_exists('get_task_files')) {
    /**
     * Получение файлов задачи
     */
    function get_task_files($task_uuid, $db) {
        log_debug("[GET_TASK_FILES] Called for task_uuid: " . ($task_uuid ?: 'EMPTY'));

        $stmt = $db->prepare("SELECT f.* FROM files f
            JOIN task_files tf ON f.uuid = tf.file_uuid
            WHERE tf.task_uuid = ?");

        if (!$stmt) {
            log_error("[GET_TASK_FILES] Prepare failed: " . $db->error);
            return [];
        }

        $stmt->bind_param("s", $task_uuid);

        if (!$stmt->execute()) {
            log_error("[GET_TASK_FILES] Execute failed: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $files = [];

        while ($row = $result->fetch_assoc()) {
            $files[] = [
                'uuid' => $row['uuid'],
                'name' => $row['orig_name'],
                'storage_name' => $row['storage_name'],
                'size' => msgql_format_file_size((int)$row['size_bytes']),
                'size_bytes' => (int)$row['size_bytes'],
                'mime' => $row['mime'],
                'time' => (int)$row['time'],
                'stamp' => $row['stamp'],
                'url' => (isset($GLOBALS['appBase']) ? $GLOBALS['appBase'] : '') . '/download.php?file=' . $row['uuid']
            ];
        }

        $stmt->close();
        log_debug("[GET_TASK_FILES] Returning " . count($files) . " files");
        return $files;
    }
}

if (!function_exists('get_all_users')) {
    /**
     * Получение всех активных пользователей
     */
    function get_all_users($db) {
        $res = $db->query("SELECT uuid, name, login, email FROM users WHERE status = 0 ORDER BY name, login");
        $users = [];
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }
        return $users;
    }
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
            log_warning("[GET_USERS_WITH_ACCESS] Missing parameters: project_uuid={$project_uuid}, user_uuid={$current_user_uuid}");
            return [];
        }

        $stmt = $db->prepare("
            SELECT DISTINCT u.uuid, u.name, u.login
            FROM users u
            WHERE u.status = 0
            AND (
                u.role = 0
                OR u.uuid = ?
                OR u.uuid IN (
                    SELECT assigned_to_uuid FROM tasks WHERE project_uuid = ? AND assigned_to_uuid IS NOT NULL
                )
                OR u.uuid IN (
                    SELECT user_uuid FROM user_project_permissions 
                    WHERE project_uuid = ? AND can_view = 1
                )
                OR u.uuid IN (
                    SELECT user_uuid FROM user_project_permissions 
                    WHERE project_uuid = ? AND can_edit_tasks = 1
                )
                OR u.uuid IN (
                    SELECT user_uuid FROM user_project_permissions 
                    WHERE project_uuid = ? AND can_edit_messages = 1
                )
                OR u.uuid IN (
                    SELECT user_uuid FROM user_project_permissions 
                    WHERE project_uuid = ? AND can_upload_files = 1
                )
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

        log_debug("[GET_USERS_WITH_ACCESS] Executing query for project: {$project_uuid}");

        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        log_debug("[GET_USERS_WITH_ACCESS] Found " . count($users) . " users with access to project: {$project_uuid}");
        return $users;
    }
}

if (!function_exists('parseDescriptionLinks')) {
    /**
     * Парсинг описаний с поддержкой Markdown и ссылок
     */
    function parseDescriptionLinks($text) {
        if (!$text) return '';

        $result = $text;

        $result = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $result);
        $result = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $result);

        $result = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $result);
        $result = preg_replace('/_(.+?)_/', '<em>$1</em>', $result);

        $result = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $result);

        $result = preg_replace_callback('/`([^`]+)`/', function($m) {
            return '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        }, $result);

        $result = preg_replace_callback('/```([a-z]*)\n(.*?)\n```/s', function($m) {
            $lang = $m[1];
            $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $langAttr = $lang ? ' class="language-' . $lang . '"' : '';
            return '<pre><code' . $langAttr . '>' . $code . '</code></pre>';
        }, $result);

        $result = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $result);
        $result = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $result);
        $result = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $result);

        $result = preg_replace('/###\s+([^\n<]+)/', '<h3>$1</h3>', $result);
        $result = preg_replace('/##\s+([^\n<]+)/', '<h2>$1</h2>', $result);
        $result = preg_replace('/#\s+([^\n<]+)/', '<h1>$1</h1>', $result);

        $result = preg_replace('/^- (.+)$/m', '<li>$1</li>', $result);
        $result = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $result);
        $result = preg_replace('/((?:<li>.*<\/li>\s*)+)/', '<ul>$1</ul>', $result);

        $result = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $result);
        $result = preg_replace('/((?:<li>.*<\/li>\s*)+)/', '<ol>$1</ol>', $result);

        $result = preg_replace('/^(---|\*\*\*|___)$/m', '<hr>', $result);

        $result = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
            $url = $m[2];
            $lowerUrl = strtolower($url);
            if (strpos($lowerUrl, 'javascript:') === 0 || 
                strpos($lowerUrl, 'data:') === 0 || 
                strpos($lowerUrl, 'vbscript:') === 0) {
                return $m[1];
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $targetAttr = (strpos($lowerUrl, 'mailto:') === 0 || strpos($lowerUrl, 'tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            return '<a href="' . $safeUrl . '"' . $targetAttr . '>' . $m[1] . '</a>';
        }, $result);

        $urlRegex = '/(?:https?:\/\/|ftp:\/\/|ws:\/\/|wss:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|magnet:|skype:|viber:|whatsapp:|signal:)[a-zA-Z0-9\-._~!$&\'()*+,;=:@\/?%#\[\]]+(?=[\s<>\[\]\(\)\{\}]|$)/i';
        $result = preg_replace_callback($urlRegex, function($m) {
            $url = $m[0];
            $lowerUrl = strtolower($url);
            if (strpos($lowerUrl, 'javascript:') === 0 || 
                strpos($lowerUrl, 'data:') === 0 || 
                strpos($lowerUrl, 'vbscript:') === 0) {
                return $url;
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $isTelegram = (strpos($lowerUrl, 'tg://') === 0 || strpos($lowerUrl, 'telegram://') === 0);
            $linkClass = $isTelegram ? 'external-link telegram-link' : 'external-link';
            $targetAttr = (strpos($lowerUrl, 'mailto:') === 0 || strpos($lowerUrl, 'tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            $displayText = $url;
            if (strlen($displayText) > 80) {
                $displayText = substr($displayText, 0, 70) . '…' . substr($displayText, -10);
            }
            return '<a href="' . $safeUrl . '" class="' . $linkClass . '"' . $targetAttr . '>' . $displayText . '</a>';
        }, $result);

        $result = preg_replace('/(<\/a>)(<a\s)/', '$1 $2', $result);

        $result = nl2br($result);

        return $result;
    }
}