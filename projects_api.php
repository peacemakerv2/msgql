<?php
/**
 * projects_api.php
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from projects.php. Extracted AJAX handlers into handle_projects_ajax().
 */

if (!function_exists('handle_projects_ajax')) {
    /**
     * Обработчик всех AJAX запросов для projects.php
     * 
     * @param mysqli $db
     * @param string $current_user_uuid
     * @param bool $is_admin
     */
    function handle_projects_ajax($db, $current_user_uuid, $is_admin) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();

        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);

        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => 'Fatal PHP error: ' . $error['message'],
                    'error_file' => basename($error['file'] ?? ''),
                    'error_line' => $error['line'] ?? 0
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        });

        $response = ['success' => false, 'error' => ''];
        $action = $_POST['action'] ?? '';

        $mutating_actions = [
            'create_project', 'edit_project', 'delete_project',
            'create_task', 'edit_task', 'delete_task',
            'toggle_task_status', 'move_task',
            'upload_task_file', 'detach_file_from_task',
            'toggle_task_subscription', 'mark_project_notifications_read'
        ];
        if (in_array($action, $mutating_actions)) {
            msgql_csrf_check_and_exit();
        }

        log_debug("[AJAX] Action: {$action}");

        try {
            if ($action === 'get_project_tasks_sorted') {
                $response = projects_api_get_project_tasks_sorted($db, $current_user_uuid, $is_admin);
            }
            elseif ($action === 'get_project_users') {
                $response = projects_api_get_project_users($db, $current_user_uuid, $is_admin);
            }
            elseif ($action === 'get_task_page_info') {
                $response = projects_api_get_task_page_info($db, $current_user_uuid, $is_admin);
            }
            elseif ($action === 'get_tasks_files_batch') {
                $response = projects_api_get_tasks_files_batch($db);
            }
            elseif ($action === 'create_project') {
                $response = projects_api_create_project($db, $current_user_uuid);
            }
            elseif ($action === 'edit_project') {
                $response = projects_api_edit_project($db, $current_user_uuid, $is_admin);
            }
            elseif ($action === 'delete_project') {
                $response = projects_api_delete_project($db, $is_admin);
            }
            elseif ($action === 'create_task') {
                $response = projects_api_create_task($db, $current_user_uuid);
            }
            elseif ($action === 'edit_task') {
                $response = projects_api_edit_task($db, $current_user_uuid, $is_admin);
            }
            elseif ($action === 'toggle_task_status') {
                $response = projects_api_toggle_task_status($db, $current_user_uuid);
            }
            elseif ($action === 'delete_task') {
                $response = projects_api_delete_task($db, $current_user_uuid);
            }
            elseif ($action === 'move_task') {
                $response = projects_api_move_task($db, $current_user_uuid);
            }
            elseif ($action === 'get_task_files') {
                $response = projects_api_get_task_files($db);
            }
            elseif ($action === 'detach_file_from_task') {
                $response = projects_api_detach_file_from_task($db, $current_user_uuid);
            }
            elseif ($action === 'upload_task_file') {
                $response = projects_api_upload_task_file($db, $current_user_uuid);
            }
            elseif ($action === 'get_task') {
                $response = projects_api_get_task($db, $current_user_uuid);
            }
            elseif ($action === 'get_task_info') {
                $response = projects_api_get_task_info($db, $current_user_uuid);
            }
            elseif ($action === 'check_edit_permission') {
                $response = projects_api_check_edit_permission($db, $current_user_uuid);
            }
            elseif ($action === 'get_task_users') {
                $response = projects_api_get_task_users($db, $current_user_uuid);
            }
            elseif ($action === 'toggle_task_subscription') {
                $response = projects_api_toggle_task_subscription($db, $current_user_uuid);
            }
            elseif ($action === 'check_project_permission') {
                $response = projects_api_check_project_permission($db, $current_user_uuid);
            }
            elseif ($action === 'check_task_subscription') {
                $response = projects_api_check_task_subscription($db, $current_user_uuid);
            }
            elseif ($action === 'mark_project_notifications_read') {
                $response = projects_api_mark_project_notifications_read($db, $current_user_uuid);
            }
            else {
                $response['error'] = 'Unknown action: ' . $action;
                log_warning("[AJAX] Unknown action: {$action}");
            }

        } catch (Exception $e) {
            $response = ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
            log_error("[AJAX] Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        } catch (Error $e) {
            $response = ['success' => false, 'error' => 'PHP Error: ' . $e->getMessage()];
            log_error("[AJAX] PHP Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function projects_api_get_project_tasks_sorted($db, $current_user_uuid, $is_admin) {
    $response = ['success' => false, 'error' => ''];
    $project_uuid = $_POST['project_uuid'] ?? '';
    $parent_uuid = $_POST['parent_uuid'] ?? null;
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(1000, max(5, (int)($_POST['per_page'] ?? 10)));
    $sort_by = $_POST['sort_by'] ?? 'last_activity';
    $sort_dir = ($_POST['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $filter_statuses = [];
    if (isset($_POST['filter_statuses']) && !empty($_POST['filter_statuses'])) {
        $filter_statuses = json_decode($_POST['filter_statuses'], true);
        if (!is_array($filter_statuses)) $filter_statuses = [];
    }

    $filter_assigned = [];
    if (isset($_POST['filter_assigned']) && !empty($_POST['filter_assigned'])) {
        $filter_assigned = json_decode($_POST['filter_assigned'], true);
        if (!is_array($filter_assigned)) $filter_assigned = [];
    }

    $search = trim($_POST['search'] ?? '');
    $is_subtask_request = isset($parent_uuid) && !empty($parent_uuid);

    log_debug("[AJAX] get_project_tasks_sorted v4.12: project_uuid={$project_uuid}, parent_uuid=" . ($parent_uuid ?? 'NULL'));
    log_debug("[AJAX] filter_statuses: " . json_encode($filter_statuses));
    log_debug("[AJAX] filter_assigned: " . json_encode($filter_assigned));
    log_debug("[AJAX] search: '{$search}'");

    if (empty($project_uuid)) {
        $response['error'] = 'Не указан проект';
        return $response;
    }
    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
        $response['error'] = 'Нет доступа к проекту';
        return $response;
    }

    if ($is_subtask_request) {
        $result = get_project_subtasks_sorted($project_uuid, $parent_uuid, $current_user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db);
        $response['success'] = true;
        $response['tasks'] = $result['tasks'];
        $response['total'] = $result['total'];
        $response['page'] = $page;
        $response['per_page'] = $per_page;
        $response['has_more'] = $result['has_more'];

        if (!empty($result['tasks'])) {
            $task_uuids = array_column($result['tasks'], 'uuid');
            $subscriptions = [];
            $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
            $sub_stmt = $db->prepare("
                SELECT task_uuid, is_active 
                FROM task_subscribers 
                WHERE task_uuid IN ($placeholders) AND user_uuid = ?
            ");
            $sub_params = array_merge($task_uuids, [$current_user_uuid]);
            $sub_types = str_repeat('s', count($task_uuids)) . 's';
            $sub_stmt->bind_param($sub_types, ...$sub_params);
            $sub_stmt->execute();
            $sub_result = $sub_stmt->get_result();
            while ($row = $sub_result->fetch_assoc()) {
                $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
            }
            $sub_stmt->close();

            foreach ($response['tasks'] as &$task) {
                $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
            }
        }
        return $response;
    }

    $has_filters = !empty($filter_statuses) || !empty($filter_assigned) || !empty($search);

    if ($has_filters) {
        log_debug("[AJAX] v4.12: Filter mode - finding parent tasks that have matching subtasks");

        $matching_tasks = get_all_tasks_matching_filters($project_uuid, $current_user_uuid, $filter_statuses, $filter_assigned, $search, $is_admin, $db);

        log_debug("[AJAX] Found " . count($matching_tasks) . " tasks matching filters");

        $parent_uuids = [];
        $root_task_uuids = [];

        foreach ($matching_tasks as $mt) {
            if (empty($mt['parent_task_uuid'])) {
                if (!in_array($mt['uuid'], $root_task_uuids)) {
                    $root_task_uuids[] = $mt['uuid'];
                }
            } else {
                if (!in_array($mt['parent_task_uuid'], $parent_uuids)) {
                    $parent_uuids[] = $mt['parent_task_uuid'];
                }
            }
        }

        $all_parent_uuids = array_merge($parent_uuids, $root_task_uuids);
        $all_parent_uuids = array_unique($all_parent_uuids);

        log_debug("[AJAX] Unique parent/root tasks to display: " . count($all_parent_uuids));

        $parent_tasks = [];
        if (!empty($all_parent_uuids)) {
            $placeholders = implode(',', array_fill(0, count($all_parent_uuids), '?'));
            $parent_sql = "SELECT t.*, 
                    u.name as assignee_name, 
                    u.login as assignee_login,
                    (SELECT MAX(m.time) FROM messages m WHERE m.task_uuid = t.uuid) as last_msg_time,
                    (SELECT COUNT(*) FROM messages m WHERE m.task_uuid = t.uuid) as messages_count,
                    (SELECT COUNT(*) FROM tasks WHERE parent_task_uuid = t.uuid) as subtasks_count,
                    (SELECT COUNT(*) FROM task_files tf WHERE tf.task_uuid = t.uuid) as files_count
                  FROM tasks t
                  LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                  WHERE t.uuid IN ($placeholders) AND t.project_uuid = ?";

            $parent_params = array_merge($all_parent_uuids, [$project_uuid]);
            $parent_types = str_repeat('s', count($all_parent_uuids)) . 's';

            $parent_stmt = $db->prepare($parent_sql);
            $parent_stmt->bind_param($parent_types, ...$parent_params);
            $parent_stmt->execute();
            $parent_tasks = $parent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $parent_stmt->close();

            foreach ($parent_tasks as &$pt) {
                $pt['can_edit'] = msgql_can_edit_task($current_user_uuid, $pt['uuid']);
                $pt['messages_count'] = (int)($pt['messages_count'] ?? 0);
                $pt['subtasks_count'] = (int)($pt['subtasks_count'] ?? 0);
                $pt['files_count'] = (int)($pt['files_count'] ?? 0);
                $pt['status'] = (int)($pt['status'] ?? 0);
                $pt['is_parent_context'] = false;
                $pt['has_filtered_children'] = in_array($pt['uuid'], $parent_uuids);
            }
        }

        usort($parent_tasks, function($a, $b) {
            $timeA = $a['last_msg_time'] ?? $a['time'] ?? 0;
            $timeB = $b['last_msg_time'] ?? $b['time'] ?? 0;
            return $timeB - $timeA;
        });

        $total_parents = count($parent_tasks);
        $offset = ($page - 1) * $per_page;
        $parent_tasks_paged = array_slice($parent_tasks, $offset, $per_page);

        log_debug("[AJAX] Returning " . count($parent_tasks_paged) . " parent tasks (total: {$total_parents})");

        $subscriptions = [];
        if (!empty($parent_tasks_paged)) {
            $task_uuids = array_column($parent_tasks_paged, 'uuid');
            $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
            $sub_stmt = $db->prepare("
                SELECT task_uuid, is_active 
                FROM task_subscribers 
                WHERE task_uuid IN ($placeholders) AND user_uuid = ?
            ");
            $sub_params = array_merge($task_uuids, [$current_user_uuid]);
            $sub_types = str_repeat('s', count($task_uuids)) . 's';
            $sub_stmt->bind_param($sub_types, ...$sub_params);
            $sub_stmt->execute();
            $sub_result = $sub_stmt->get_result();
            while ($row = $sub_result->fetch_assoc()) {
                $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
            }
            $sub_stmt->close();

            foreach ($parent_tasks_paged as &$task) {
                $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
            }
        }

        $response['success'] = true;
        $response['tasks'] = $parent_tasks_paged;
        $response['total'] = $total_parents;
        $response['page'] = $page;
        $response['per_page'] = $per_page;
        $response['has_more'] = ($offset + $per_page) < $total_parents;
        $response['has_filtered_children'] = true;
        return $response;
    }

    $result = get_project_tasks_sorted($project_uuid, $current_user_uuid, $page, $per_page, $sort_by, $sort_dir, $filter_statuses, $filter_assigned, $search, $is_admin, $db);

    $response['success'] = true;
    $response['tasks'] = $result['tasks'];
    $response['total'] = $result['total'];
    $response['page'] = $page;
    $response['per_page'] = $per_page;
    $response['has_more'] = $result['has_more'];
    $response['has_filtered_children'] = false;

    if (!empty($result['tasks'])) {
        $task_uuids = array_column($result['tasks'], 'uuid');
        $subscriptions = [];
        $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
        $sub_stmt = $db->prepare("
            SELECT task_uuid, is_active 
            FROM task_subscribers 
            WHERE task_uuid IN ($placeholders) AND user_uuid = ?
        ");
        $sub_params = array_merge($task_uuids, [$current_user_uuid]);
        $sub_types = str_repeat('s', count($task_uuids)) . 's';
        $sub_stmt->bind_param($sub_types, ...$sub_params);
        $sub_stmt->execute();
        $sub_result = $sub_stmt->get_result();
        while ($row = $sub_result->fetch_assoc()) {
            $subscriptions[$row['task_uuid']] = ($row['is_active'] == 1);
        }
        $sub_stmt->close();

        foreach ($response['tasks'] as &$task) {
            $task['is_subscribed'] = $subscriptions[$task['uuid']] ?? false;
        }
    }

    log_debug("[AJAX] Returning " . count($response['tasks'] ?? []) . " tasks");
    return $response;
}

function projects_api_get_project_users($db, $current_user_uuid, $is_admin) {
    $response = ['success' => false, 'error' => '', 'users' => []];
    $project_uuid = $_POST['project_uuid'] ?? '';

    log_debug("[GET_PROJECT_USERS] Getting users for project: {$project_uuid}");

    if (empty($project_uuid)) {
        $response['error'] = 'Не указан проект';
        return $response;
    }
    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
        $response['error'] = 'Нет доступа к проекту';
        return $response;
    }

    $users = get_users_with_project_access($db, $project_uuid, $current_user_uuid, $is_admin);
    $response['success'] = true;
    $response['users'] = $users;
    log_debug("[GET_PROJECT_USERS] Found " . count($users) . " users for project: {$project_uuid}");
    return $response;
}

function projects_api_get_task_page_info($db, $current_user_uuid, $is_admin) {
    $response = ['success' => false, 'error' => ''];
    $task_uuid = $_POST['task_uuid'] ?? '';
    $project_uuid = $_POST['project_uuid'] ?? '';
    $per_page = max(1, min(1000, (int)($_POST['per_page'] ?? 10)));
    $sort_by = $_POST['sort_by'] ?? 'last_activity';
    $sort_dir = ($_POST['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $filter_statuses = [];
    if (isset($_POST['filter_statuses']) && !empty($_POST['filter_statuses'])) {
        $filter_statuses = json_decode($_POST['filter_statuses'], true);
        if (!is_array($filter_statuses)) $filter_statuses = [];
    }
    $filter_assigned = [];
    if (isset($_POST['filter_assigned']) && !empty($_POST['filter_assigned'])) {
        $filter_assigned = json_decode($_POST['filter_assigned'], true);
        if (!is_array($filter_assigned)) $filter_assigned = [];
    }
    $search = trim($_POST['search'] ?? '');

    log_debug("[GET_TASK_PAGE_INFO] task_uuid: {$task_uuid}, project_uuid: {$project_uuid}, per_page: {$per_page}");

    if (empty($task_uuid) || empty($project_uuid)) {
        $response['error'] = 'Не указаны обязательные параметры';
        return $response;
    }
    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
        $response['error'] = 'Нет доступа к проекту';
        return $response;
    }

    $task_check = $db->prepare("SELECT uuid FROM tasks WHERE uuid = ? AND project_uuid = ?");
    $task_check->bind_param("ss", $task_uuid, $project_uuid);
    $task_check->execute();
    $task_exists = $task_check->get_result()->num_rows > 0;
    $task_check->close();

    if (!$task_exists) {
        $response['error'] = 'Задача не найдена в указанном проекте';
        return $response;
    }

    $where_conditions = ["t.project_uuid = ?"];
    $params = [$project_uuid];
    $types = "s";

    if (!$is_admin) {
        $where_conditions[] = "(p.created_by_uuid = ? OR upp.can_view = 1)";
        $params[] = $current_user_uuid;
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

    $order_map = [
        'last_activity' => 'COALESCE(last_msg_time, t.time)',
        'title' => 't.title',
        'status' => 't.status',
        'deadline' => 't.time_end_plan'
    ];
    $order_by = $order_map[$sort_by] ?? 'COALESCE(last_msg_time, t.time)';
    $order_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

    $count_sql = "SELECT COUNT(*) as total 
                 FROM tasks t
                 JOIN projects p ON t.project_uuid = p.uuid
                 LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                 LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
                 LEFT JOIN (
                     SELECT task_uuid, MAX(time) as last_msg_time
                     FROM messages
                     GROUP BY task_uuid
                 ) m ON t.uuid = m.task_uuid
                 {$where_clause}";

    $count_params = array_merge([$current_user_uuid], $params);
    $count_types = "s" . $types;

    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    log_debug("[GET_TASK_PAGE_INFO] Total tasks: {$total}");

    $position_sql = "
        SELECT position FROM (
            SELECT t.uuid,
                   ROW_NUMBER() OVER (ORDER BY {$order_by} {$order_dir}) as position
            FROM tasks t
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
            LEFT JOIN (
                SELECT task_uuid, MAX(time) as last_msg_time
                FROM messages
                GROUP BY task_uuid
            ) m ON t.uuid = m.task_uuid
            {$where_clause}
        ) numbered
        WHERE uuid = ?
    ";

    $position_params = array_merge([$current_user_uuid], $params, [$task_uuid]);
    $position_types = "s" . $types . "s";

    $stmt = $db->prepare($position_sql);
    $stmt->bind_param($position_types, ...$position_params);
    $stmt->execute();
    $position_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($position_result && isset($position_result['position'])) {
        $position = (int)$position_result['position'];
        $page = ceil($position / $per_page);

        log_debug("[GET_TASK_PAGE_INFO] Task position: {$position}, page: {$page}");

        $response['success'] = true;
        $response['position'] = $position;
        $response['page'] = $page;
        $response['total'] = $total;
        $response['per_page'] = $per_page;
        $response['has_more'] = ($page * $per_page) < $total;
    } else {
        $response['error'] = 'Не удалось определить позицию задачи';
        log_warning("[GET_TASK_PAGE_INFO] Could not determine position for task {$task_uuid}");
    }
    return $response;
}

function projects_api_get_tasks_files_batch($db) {
    $response = ['success' => false, 'error' => '', 'files_by_task' => []];
    $task_uuids = json_decode($_POST['task_uuids'] ?? '[]', true);
    if (!is_array($task_uuids) || empty($task_uuids)) {
        $response['success'] = true;
        $response['files_by_task'] = [];
        return $response;
    }

    $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
    $stmt = $db->prepare("
        SELECT tf.task_uuid, f.uuid, f.orig_name, f.size_bytes, f.mime
        FROM task_files tf
        JOIN files f ON tf.file_uuid = f.uuid
        WHERE tf.task_uuid IN ($placeholders)
    ");
    $stmt->bind_param(str_repeat('s', count($task_uuids)), ...$task_uuids);
    $stmt->execute();
    $result = $stmt->get_result();

    $files_by_task = [];
    while ($row = $result->fetch_assoc()) {
        if (!isset($files_by_task[$row['task_uuid']])) {
            $files_by_task[$row['task_uuid']] = [];
        }
        $files_by_task[$row['task_uuid']][] = [
            'uuid' => $row['uuid'],
            'name' => $row['orig_name'],
            'size' => msgql_format_file_size((int)$row['size_bytes']),
            'size_bytes' => (int)$row['size_bytes'],
            'mime' => $row['mime']
        ];
    }
    $stmt->close();

    $response['success'] = true;
    $response['files_by_task'] = $files_by_task;
    log_debug("[GET_TASKS_FILES_BATCH] Loaded files for " . count($task_uuids) . " tasks");
    return $response;
}

function projects_api_create_project($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $title = trim($_POST['title'] ?? '');
    $descr = trim($_POST['descr'] ?? '');

    log_debug("[CREATE_PROJECT] Attempt by user: {$current_user_uuid}");

    if (empty($title)) {
        $response['error'] = 'Название проекта обязательно';
        return $response;
    }
    if (!msgql_can_create_project($current_user_uuid)) {
        log_warning("[CREATE_PROJECT] User {$current_user_uuid} has no permission to create projects");
        $response['error'] = 'У вас нет прав на создание проектов';
        return $response;
    }

    $uuid = msgql_uuid_v4();
    $time = msgql_now_ms();
    $time_str = (string)$time;
    $user_tz_offset_minutes = msgql_user_timezone_offset();
    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
    $stamp = msgql_stamp($user_tz_offset_hours);
    if ($stamp === null) $stamp = date('Y-m-d H:i:s');

    $stmt = $db->prepare("INSERT INTO projects (uuid, title, descr, created_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $uuid, $title, $descr, $current_user_uuid, $time_str, $stamp);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['project'] = get_project_data($uuid, $db);
        log_debug("[CREATE_PROJECT] Project created: {$uuid}");
    } else {
        $response['error'] = 'Ошибка БД: ' . $db->error;
        log_error("[CREATE_PROJECT] DB Error: " . $db->error);
    }
    $stmt->close();
    return $response;
}

function projects_api_edit_project($db, $current_user_uuid, $is_admin) {
    $response = ['success' => false, 'error' => ''];
    $uuid = $_POST['uuid'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $descr = trim($_POST['descr'] ?? '');

    $stmt = $db->prepare("SELECT created_by_uuid FROM projects WHERE uuid = ?");
    $stmt->bind_param("s", $uuid);
    $stmt->execute();
    $proj = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proj || ($proj['created_by_uuid'] !== $current_user_uuid && !$is_admin)) {
        $response['error'] = 'Нет прав для редактирования проекта';
        return $response;
    }
    if (empty($title)) {
        $response['error'] = 'Название проекта обязательно';
        return $response;
    }

    $stmt = $db->prepare("UPDATE projects SET title = ?, descr = ? WHERE uuid = ?");
    $stmt->bind_param("sss", $title, $descr, $uuid);
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['project'] = get_project_data($uuid, $db);
        log_debug("[EDIT_PROJECT] Project updated: {$uuid}");
    } else {
        $response['error'] = 'Ошибка БД: ' . $db->error;
    }
    $stmt->close();
    return $response;
}

function projects_api_delete_project($db, $is_admin) {
    $response = ['success' => false, 'error' => ''];
    if (!$is_admin) {
        $response['error'] = 'Нет прав для удаления проекта';
        return $response;
    }
    $uuid = $_POST['uuid'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($confirm !== 'DELETE') {
        $response['error'] = 'Подтверждение удаления неверно. Введите DELETE для подтверждения.';
        return $response;
    }
    delete_project_recursive($uuid, $db);
    $response['success'] = true;
    log_debug("[DELETE_PROJECT] Project deleted: {$uuid}");
    return $response;
}

function projects_api_create_task($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $project_uuid = $_POST['project_uuid'] ?? '';
    $parent_task_uuid = $_POST['parent_task_uuid'] ?? '';

    if (empty($parent_task_uuid) || $parent_task_uuid === 'null' || $parent_task_uuid === '') {
        $parent_task_uuid = null;
    }

    $title = trim($_POST['title'] ?? '');
    $descr = trim($_POST['descr'] ?? '');
    $assigned_to_uuid = $_POST['assigned_to_uuid'] ?: null;

    $user_tz_offset_minutes = msgql_user_timezone_offset();
    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;

    $time_start = null;
    if (!empty($_POST['time_start'])) {
        $local_timestamp = strtotime($_POST['time_start']);
        if ($local_timestamp !== false) {
            $time_start = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
        }
    }

    $time_end_plan = null;
    if (!empty($_POST['time_end_plan'])) {
        $local_timestamp = strtotime($_POST['time_end_plan']);
        if ($local_timestamp !== false) {
            $time_end_plan = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
        }
    }

    if ($time_start !== null && $time_start <= 0) $time_start = null;
    if ($time_end_plan !== null && $time_end_plan <= 0) $time_end_plan = null;

    if ($time_start !== null && $time_end_plan !== null && $time_start > $time_end_plan) {
        $response['error'] = 'Дата начала не может быть позже даты окончания';
        return $response;
    }
    if (empty($title)) {
        $response['error'] = 'Название задачи обязательно';
        return $response;
    }
    if (empty($project_uuid)) {
        $response['error'] = 'Не указан проект';
        return $response;
    }
    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks')) {
        $response['error'] = 'Нет прав на создание задач в этом проекте';
        return $response;
    }

    $uuid = msgql_uuid_v4();
    $time = msgql_now_ms();
    $time_str = (string)$time;
    $stamp = msgql_stamp($user_tz_offset_hours);
    if ($stamp === null) $stamp = date('d.m.Y H:i:s');

    $time_start_str = $time_start !== null ? (string)$time_start : 'NULL';
    $time_end_plan_str = $time_end_plan !== null ? (string)$time_end_plan : 'NULL';

    $stmt = $db->prepare("INSERT INTO tasks (uuid, project_uuid, parent_task_uuid, title, descr, assigned_to_uuid, user_uuid, time_start, time_end_plan, status, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
    $stmt->bind_param("sssssssssss", $uuid, $project_uuid, $parent_task_uuid, $title, $descr, $assigned_to_uuid, $current_user_uuid, $time_start_str, $time_end_plan_str, $time_str, $stamp);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['task'] = get_task_data($uuid, $current_user_uuid, $db);
        log_debug("[CREATE_TASK] Task created: {$uuid}");

        if (class_exists('NotificationCenter')) {
            $proj_stmt = $db->prepare("SELECT title FROM projects WHERE uuid = ?");
            $proj_stmt->bind_param("s", $project_uuid);
            $proj_stmt->execute();
            $project_title = $proj_stmt->get_result()->fetch_assoc()['title'] ?? '';
            $proj_stmt->close();

            $task_data = [
                'uuid' => $uuid,
                'title' => $title,
                'descr' => $descr,
                'project_uuid' => $project_uuid,
                'project_title' => $project_title,
                'assigned_to_uuid' => $assigned_to_uuid,
                'time_start' => $time_start,
                'time_end_plan' => $time_end_plan
            ];
            NotificationCenter::notifyNewTask($task_data, $current_user_uuid);
        }

        $now = msgql_now_ms();
        $now_str = (string)$now;
        $subscriber_stmt = $db->prepare("
            INSERT IGNORE INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");

        log_debug("[CREATE_TASK] Adding author to subscribers: user={$current_user_uuid}, task={$uuid}");
        $subscriber_stmt->bind_param("ssis", $uuid, $current_user_uuid, $now_str, $current_user_uuid);
        $subscriber_stmt->execute();

        if (!empty($assigned_to_uuid) && $assigned_to_uuid !== $current_user_uuid) {
            log_debug("[CREATE_TASK] Adding assignee to subscribers: user={$assigned_to_uuid}, task={$uuid}");
            $subscriber_stmt->bind_param("ssis", $uuid, $assigned_to_uuid, $now_str, $current_user_uuid);
            $subscriber_stmt->execute();
        }
        $subscriber_stmt->close();

        log_debug("[CREATE_TASK] Subscription setup completed for task: {$uuid}");
    } else {
        $response['error'] = 'Ошибка БД: ' . $db->error;
        log_error("[CREATE_TASK] DB Error: " . $db->error);
    }
    $stmt->close();
    return $response;
}

function projects_api_edit_task($db, $current_user_uuid, $is_admin) {
    $response = ['success' => false, 'error' => ''];
    $uuid = $_POST['uuid'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $descr = trim($_POST['descr'] ?? '');
    $assigned_to_uuid = $_POST['assigned_to_uuid'] ?: null;
    $parent_task_uuid = $_POST['parent_task_uuid'] ?? '';
    $project_uuid = $_POST['project_uuid'] ?? '';

    if (empty($parent_task_uuid) || $parent_task_uuid === 'null') {
        $parent_task_uuid = null;
    }

    $user_tz_offset_minutes = msgql_user_timezone_offset();

    $time_start = null;
    if (isset($_POST['time_start']) && $_POST['time_start'] !== '') {
        $local_timestamp = strtotime($_POST['time_start']);
        if ($local_timestamp !== false) {
            $time_start = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
        }
    }

    $time_end_plan = null;
    if (isset($_POST['time_end_plan']) && $_POST['time_end_plan'] !== '') {
        $local_timestamp = strtotime($_POST['time_end_plan']);
        if ($local_timestamp !== false) {
            $time_end_plan = ($local_timestamp + $user_tz_offset_minutes * 60) * 1000;
        }
    }

    if ($time_start !== null && $time_start <= 0) $time_start = null;
    if ($time_end_plan !== null && $time_end_plan <= 0) $time_end_plan = null;

    if ($time_start !== null && $time_end_plan !== null && $time_start > $time_end_plan) {
        $response['error'] = 'Дата начала не может быть позже даты окончания';
        return $response;
    }
    if (empty($title)) {
        $response['error'] = 'Название задачи обязательно';
        return $response;
    }
    if (!msgql_can_edit_task($current_user_uuid, $uuid)) {
        $response['error'] = 'Нет прав на редактирование этой задачи';
        return $response;
    }

    $old_stmt = $db->prepare("SELECT title, descr, assigned_to_uuid, parent_task_uuid, time_start, time_end_plan, status, project_uuid FROM tasks WHERE uuid = ?");
    $old_stmt->bind_param("s", $uuid);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    $time_start_str = $time_start !== null ? (string)$time_start : 'NULL';
    $time_end_plan_str = $time_end_plan !== null ? (string)$time_end_plan : 'NULL';

    if (!empty($project_uuid) && $project_uuid !== $old_data['project_uuid']) {
        log_debug("[EDIT_TASK] Moving task from project {$old_data['project_uuid']} to {$project_uuid}");

        if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks')) {
            $response['error'] = 'Нет прав на создание задач в выбранном проекте';
            return $response;
        }

        $sql = "UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, project_uuid = ?";
        $params = [$title, $descr, $assigned_to_uuid, $parent_task_uuid, $project_uuid];
        $types = "sssss";

        if ($time_start_str === 'NULL' && $time_end_plan_str === 'NULL') {
            $sql .= ", time_start = NULL, time_end_plan = NULL";
        } elseif ($time_start_str === 'NULL') {
            $sql .= ", time_start = NULL, time_end_plan = ?";
            $params[] = $time_end_plan_str;
            $types .= "s";
        } elseif ($time_end_plan_str === 'NULL') {
            $sql .= ", time_start = ?, time_end_plan = NULL";
            $params[] = $time_start_str;
            $types .= "s";
        } else {
            $sql .= ", time_start = ?, time_end_plan = ?";
            $params[] = $time_start_str;
            $params[] = $time_end_plan_str;
            $types .= "ss";
        }

        $sql .= " WHERE uuid = ?";
        $params[] = $uuid;
        $types .= "s";

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
    } else {
        if ($time_start_str === 'NULL' && $time_end_plan_str === 'NULL') {
            $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = NULL, time_end_plan = NULL WHERE uuid = ?");
            $stmt->bind_param("sssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $uuid);
        } elseif ($time_start_str === 'NULL') {
            $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = NULL, time_end_plan = ? WHERE uuid = ?");
            $stmt->bind_param("ssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_end_plan_str, $uuid);
        } elseif ($time_end_plan_str === 'NULL') {
            $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = ?, time_end_plan = NULL WHERE uuid = ?");
            $stmt->bind_param("ssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_start_str, $uuid);
        } else {
            $stmt = $db->prepare("UPDATE tasks SET title = ?, descr = ?, assigned_to_uuid = ?, parent_task_uuid = ?, time_start = ?, time_end_plan = ? WHERE uuid = ?");
            $stmt->bind_param("sssssss", $title, $descr, $assigned_to_uuid, $parent_task_uuid, $time_start_str, $time_end_plan_str, $uuid);
        }
    }

    $response['success'] = $stmt->execute();

    if ($response['success']) {
        log_debug("[EDIT_TASK] Task updated: {$uuid}");

        if (class_exists('NotificationCenter')) {
            $new_data = [
                'title' => $title,
                'descr' => $descr,
                'assigned_to_uuid' => $assigned_to_uuid,
                'parent_task_uuid' => $parent_task_uuid,
                'time_start' => $time_start,
                'time_end_plan' => $time_end_plan,
                'project_uuid' => $project_uuid ?: $old_data['project_uuid']
            ];
            NotificationCenter::notifyTaskChanges($uuid, $old_data, $new_data, $current_user_uuid);
            $response['refresh_dashboard'] = true;
        }

        if (!empty($assigned_to_uuid) && $assigned_to_uuid !== $current_user_uuid) {
            $check_sub = $db->prepare("
                SELECT id, is_active FROM task_subscribers 
                WHERE task_uuid = ? AND user_uuid = ?
            ");
            $check_sub->bind_param("ss", $uuid, $assigned_to_uuid);
            $check_sub->execute();
            $existing = $check_sub->get_result()->fetch_assoc();
            $check_sub->close();

            $now = msgql_now_ms();
            $now_str = (string)$now;

            if ($existing) {
                if ($existing['is_active'] == 0) {
                    $update_sub = $db->prepare("
                        UPDATE task_subscribers 
                        SET is_active = 1, subscribed_at = ?, subscribed_by_uuid = ? 
                        WHERE id = ?
                    ");
                    $update_sub->bind_param("ssi", $now_str, $current_user_uuid, $existing['id']);
                    $update_sub->execute();
                    $update_sub->close();
                    log_debug("[EDIT_TASK] Reactivated subscription for assignee: {$assigned_to_uuid}");
                } else {
                    log_debug("[EDIT_TASK] Assignee already subscribed: {$assigned_to_uuid}");
                }
            } else {
                $sub_stmt = $db->prepare("
                    INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $sub_stmt->bind_param("ssis", $uuid, $assigned_to_uuid, $now_str, $current_user_uuid);
                $sub_stmt->execute();
                $sub_stmt->close();
                log_debug("[EDIT_TASK] Added assignee as subscriber: {$assigned_to_uuid}");
            }
        }
    } else {
        $response['error'] = 'Ошибка обновления задачи: ' . $db->error;
        log_error("[EDIT_TASK] DB Error: " . $db->error);
    }
    $stmt->close();
    return $response;
}

function projects_api_toggle_task_status($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $uuid = $_POST['uuid'] ?? '';
    $status = (int)($_POST['status'] ?? 0);

    if (!msgql_can_edit_task($current_user_uuid, $uuid)) {
        $response['error'] = 'Нет прав на изменение статуса задачи';
        return $response;
    }

    $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE uuid = ?");
    $stmt->bind_param("is", $status, $uuid);
    $response['success'] = $stmt->execute();
    if (!$response['success']) $response['error'] = 'Ошибка изменения статуса';
    $stmt->close();
    log_debug("[TOGGLE_TASK_STATUS] Task {$uuid} status set to {$status}");
    return $response;
}

function projects_api_delete_task($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $uuid = $_POST['uuid'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!msgql_can_edit_task($current_user_uuid, $uuid)) {
        $response['error'] = 'Нет прав на удаление этой задачи';
        return $response;
    }
    if ($confirm !== 'DELETE') {
        $response['error'] = 'Подтверждение удаления неверно. Введите DELETE для подтверждения.';
        return $response;
    }

    $project_stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $project_stmt->bind_param("s", $uuid);
    $project_stmt->execute();
    $project = $project_stmt->get_result()->fetch_assoc();
    $project_stmt->close();

    delete_task_recursive($uuid, $db);
    $response['success'] = true;
    log_debug("[DELETE_TASK] Task deleted: {$uuid}");

    if (function_exists('msgql_send_sse_event') && $project) {
        $event_data = [
            'task_uuid' => $uuid,
            'project_uuid' => $project['project_uuid'],
            'timestamp' => msgql_now_ms()
        ];
        msgql_send_sse_event($project['project_uuid'], 'task_deleted', $event_data);
    }
    return $response;
}

function projects_api_move_task($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $task_uuid = $_POST['task_uuid'] ?? '';
    $new_parent_uuid = $_POST['new_parent_uuid'] ?: null;

    if (!msgql_can_edit_task($current_user_uuid, $task_uuid)) {
        $response['error'] = 'Нет прав на перемещение этой задачи';
        return $response;
    }

    if ($new_parent_uuid) {
        $cycle_check = $db->prepare("
            WITH RECURSIVE task_tree AS (
                SELECT uuid, parent_task_uuid FROM tasks WHERE uuid = ?
                UNION ALL
                SELECT t.uuid, t.parent_task_uuid
                FROM tasks t
                INNER JOIN task_tree tt ON t.uuid = tt.parent_task_uuid
            )
            SELECT COUNT(*) as cnt FROM task_tree WHERE uuid = ?
        ");
        $cycle_check->bind_param("ss", $new_parent_uuid, $task_uuid);
        $cycle_check->execute();
        $cycle_result = $cycle_check->get_result()->fetch_assoc();
        $cycle_check->close();

        if ($cycle_result && $cycle_result['cnt'] > 0) {
            $response['error'] = 'Нельзя переместить задачу в её собственную подзадачу (циклическая ссылка)';
            return $response;
        }
    }

    $stmt = $db->prepare("UPDATE tasks SET parent_task_uuid = ? WHERE uuid = ?");
    $stmt->bind_param("ss", $new_parent_uuid, $task_uuid);
    $response['success'] = $stmt->execute();
    if (!$response['success']) $response['error'] = 'Ошибка перемещения задачи';
    $stmt->close();
    log_debug("[MOVE_TASK] Task {$task_uuid} moved to parent: " . ($new_parent_uuid ?: 'root'));
    return $response;
}

function projects_api_get_task_files($db) {
    $response = ['success' => false, 'error' => '', 'files' => []];
    $task_uuid = $_POST['task_uuid'] ?? '';

    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }

    $response['success'] = true;
    $response['files'] = get_task_files($task_uuid, $db);
    log_debug("[GET_TASK_FILES] Found " . count($response['files']) . " files for task {$task_uuid}");
    return $response;
}

function projects_api_detach_file_from_task($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => '', 'files' => []];
    $task_uuid = $_POST['task_uuid'] ?? '';
    $file_uuid = $_POST['file_uuid'] ?? '';

    if (empty($task_uuid) || empty($file_uuid)) {
        $response['error'] = 'Не указаны обязательные параметры';
        return $response;
    }
    if (!msgql_can_edit_task($current_user_uuid, $task_uuid)) {
        $response['error'] = 'Нет прав на редактирование этой задачи';
        return $response;
    }

    $check = $db->prepare("SELECT 1 FROM task_files WHERE task_uuid = ? AND file_uuid = ?");
    $check->bind_param("ss", $task_uuid, $file_uuid);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        $response['error'] = 'Файл не прикреплён к этой задаче';
        return $response;
    }

    $stmt = $db->prepare("DELETE FROM task_files WHERE task_uuid = ? AND file_uuid = ?");
    $stmt->bind_param("ss", $task_uuid, $file_uuid);
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        $check_refs = $db->prepare("
            SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?
            UNION ALL
            SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?
        ");
        $check_refs->bind_param("ss", $file_uuid, $file_uuid);
        $check_refs->execute();
        $refs_result = $check_refs->get_result();
        $total_refs = 0;
        while ($row = $refs_result->fetch_assoc()) {
            $total_refs += (int)$row['cnt'];
        }
        $check_refs->close();

        if ($total_refs == 0) {
            $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
            $file_stmt->bind_param("s", $file_uuid);
            $file_stmt->execute();
            $file_row = $file_stmt->get_result()->fetch_assoc();
            $file_stmt->close();

            if ($file_row) {
                $file_path = __DIR__ . '/uploads/tasks/' . $file_row['storage_name'];
                if (file_exists($file_path)) {
                    @unlink($file_path);
                    log_debug("[DETACH_FILE] Deleted file: {$file_path}");
                }
            }

            $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
            $del_file->bind_param("s", $file_uuid);
            $del_file->execute();
            $del_file->close();
            log_debug("[DETACH_FILE] Deleted file record: {$file_uuid}");
        }

        $response['success'] = true;
        $response['files'] = get_task_files($task_uuid, $db);
        log_debug("[DETACH_FILE] File detached from task: {$file_uuid}");
    } else {
        $response['error'] = 'Ошибка открепления файла';
    }
    return $response;
}

function projects_api_upload_task_file($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];

    if (!class_exists('UploadSecurity')) {
        require_once __DIR__ . '/lib/upload_security.php';
    }
    $task_uuid = $_POST['task_uuid'] ?? '';

    log_debug("[UPLOAD_TASK_FILE] task_uuid: {$task_uuid}");

    if (!msgql_can_upload_file($current_user_uuid, $task_uuid)) {
        $response['error'] = 'Нет прав на загрузку файлов в эту задачу';
        return $response;
    }
    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $response['error'] = 'Ошибка загрузки файла';
        return $response;
    }

    $quota_check = check_user_upload_quota($current_user_uuid, $db);
    if (!$quota_check['allowed']) {
        $response['error'] = $quota_check['message'];
        log_warning("[UPLOAD_TASK_FILE] Quota exceeded for user {$current_user_uuid}: {$quota_check['reason']}");
        return $response;
    }

    $validation = UploadSecurity::validateUploadedFile($_FILES['file']);

    if (!$validation['valid']) {
        $response['error'] = $validation['error'];
        return $response;
    }

    $upload_dir = __DIR__ . '/uploads/tasks/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $storage_name = generate_unique_filename($validation['safe_name'], 'task', $upload_dir);
    if ($storage_name === false) {
        $response['error'] = 'Не удалось сгенерировать уникальное имя файла';
        return $response;
    }

    $target_path = $upload_dir . $storage_name;

    $file_uuid = msgql_uuid_v4();
    $time = msgql_now_ms();
    $time_str = (string)$time;
    $user_tz_offset_minutes = msgql_user_timezone_offset();
    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
    $stamp = msgql_stamp($user_tz_offset_hours);
    if ($stamp === null) $stamp = date('Y-m-d H:i:s');

    $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssisss", 
        $file_uuid, 
        $validation['safe_name'], 
        $storage_name, 
        $validation['mime'], 
        $validation['size'], 
        $current_user_uuid,
        $time_str, 
        $stamp
    );

    if ($stmt->execute()) {
        log_debug("[UPLOAD_TASK_FILE] DB record created for file: {$file_uuid}");

        if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
            log_debug("[UPLOAD_TASK_FILE] File saved to disk: {$target_path}");

            $check = $db->prepare("SELECT 1 FROM task_files WHERE task_uuid = ? AND file_uuid = ?");
            $check->bind_param("ss", $task_uuid, $file_uuid);
            $check->execute();

            if ($check->get_result()->num_rows === 0) {
                $stmt2 = $db->prepare("INSERT INTO task_files (task_uuid, file_uuid) VALUES (?, ?)");
                $stmt2->bind_param("ss", $task_uuid, $file_uuid);
                $stmt2->execute();
                $stmt2->close();
                log_debug("[UPLOAD_TASK_FILE] Task-file link created");
            }
            $check->close();

            $response['success'] = true;
            $response['file'] = [
                'uuid' => $file_uuid,
                'name' => $validation['safe_name'],
                'size' => msgql_format_file_size((int)$validation['size']),
                'size_bytes' => (int)$validation['size'],
                'mime' => $validation['mime']
            ];
            $response['files'] = get_task_files($task_uuid, $db);
            log_debug("[UPLOAD_TASK_FILE] File uploaded successfully: {$file_uuid}");
        } else {
            log_error("[UPLOAD_TASK_FILE] Failed to save file to disk, rolling back DB record");
            $del_stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
            $del_stmt->bind_param("s", $file_uuid);
            $del_stmt->execute();
            $del_stmt->close();
            $response['error'] = 'Ошибка сохранения файла на диск (откат выполнен)';
        }
    } else {
        $response['error'] = 'Ошибка сохранения в БД: ' . $db->error;
        log_error("[UPLOAD_TASK_FILE] DB Error: " . $db->error);
    }
    $stmt->close();
    return $response;
}

function projects_api_get_task($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => '', 'files' => []];
    $uuid = $_POST['uuid'] ?? '';
    $task = get_task_data($uuid, $current_user_uuid, $db);

    if ($task) {
        $response['success'] = true;
        $response['task'] = $task;
        $response['files'] = get_task_files($uuid, $db);
    } else {
        $response['error'] = 'Задача не найдена или нет доступа';
    }
    return $response;
}

function projects_api_get_task_info($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $task_uuid = $_POST['task_uuid'] ?? '';

    log_debug("[GET_TASK_INFO] v5.3 Called for task_uuid: {$task_uuid}");

    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }

    $task_check = $db->prepare("
        SELECT t.uuid, t.project_uuid, t.parent_task_uuid, t.title,
               p.created_by_uuid as project_created_by
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        WHERE t.uuid = ?
    ");
    $task_check->bind_param("s", $task_uuid);
    $task_check->execute();
    $task_info_db = $task_check->get_result()->fetch_assoc();
    $task_check->close();

    if (!$task_info_db) {
        $response['error'] = 'Задача не найдена';
        log_warning("[GET_TASK_INFO] Task not found: {$task_uuid}");
        return $response;
    }

    $project_uuid = $task_info_db['project_uuid'];
    $direct_parent = $task_info_db['parent_task_uuid'];

    log_debug("[GET_TASK_INFO] Task belongs to project: {$project_uuid}, direct_parent: " . ($direct_parent ?: 'NULL'));

    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
        $response['error'] = 'Нет доступа к проекту задачи';
        log_warning("[GET_TASK_INFO] Access denied to project: {$project_uuid}");
        return $response;
    }

    $task = get_task_data($task_uuid, $current_user_uuid, $db);
    if (!$task) {
        $response['error'] = 'Задача не найдена или нет доступа';
        log_warning("[GET_TASK_INFO] Task data not accessible: {$task_uuid}");
        return $response;
    }

    $parent_chain = [];

    if (!empty($direct_parent)) {
        log_debug("[GET_TASK_INFO] Building parent chain using recursive CTE starting from: {$direct_parent}");

        $cte_sql = "
            WITH RECURSIVE task_parents AS (
                SELECT uuid, parent_task_uuid, title, 1 as level
                FROM tasks
                WHERE uuid = ?
                UNION ALL
                SELECT t.uuid, t.parent_task_uuid, t.title, tp.level + 1
                FROM tasks t
                INNER JOIN task_parents tp ON t.uuid = tp.parent_task_uuid
                WHERE tp.parent_task_uuid IS NOT NULL AND tp.level < 50
            )
            SELECT uuid, title FROM task_parents ORDER BY level DESC
        ";

        $cte_stmt = $db->prepare($cte_sql);
        if ($cte_stmt) {
            $cte_stmt->bind_param("s", $direct_parent);
            $cte_stmt->execute();
            $parents_result = $cte_stmt->get_result();

            while ($row = $parents_result->fetch_assoc()) {
                $parent_chain[] = $row['uuid'];
                log_debug("[GET_TASK_INFO] CTE found parent: " . $row['uuid'] . " - " . $row['title']);
            }
            $cte_stmt->close();
        } else {
            log_warning("[GET_TASK_INFO] CTE not supported, using iterative fallback");
            $current_parent = $direct_parent;
            $fallback_stmt = $db->prepare("SELECT parent_task_uuid, title FROM tasks WHERE uuid = ?");
            $fallback_depth = 0;
            $fallback_max = 50;
            $parent_titles = [];

            while ($current_parent && $fallback_depth < $fallback_max) {
                log_debug("[GET_TASK_INFO] Fallback adding parent: {$current_parent}");
                $fallback_stmt->bind_param("s", $current_parent);
                $fallback_stmt->execute();
                $fallback_row = $fallback_stmt->get_result()->fetch_assoc();

                if ($fallback_row) {
                    array_unshift($parent_chain, $current_parent);
                    $parent_titles[$current_parent] = $fallback_row['title'] ?? 'Задача';

                    if (!empty($fallback_row['parent_task_uuid'])) {
                        $current_parent = $fallback_row['parent_task_uuid'];
                    } else {
                        $current_parent = null;
                    }
                } else {
                    $current_parent = null;
                }
                $fallback_depth++;
            }
            $fallback_stmt->close();
        }

        log_debug("[GET_TASK_INFO] Final parent_chain (" . count($parent_chain) . " items): " . json_encode($parent_chain));
    } else {
        log_debug("[GET_TASK_INFO] Task is root (no parent)");
    }

    $parent_tasks_data = [];
    if (!empty($parent_chain)) {
        $placeholders = implode(',', array_fill(0, count($parent_chain), '?'));
        $parent_sql = "SELECT uuid, title, status FROM tasks WHERE uuid IN ($placeholders)";
        $parent_stmt = $db->prepare($parent_sql);
        $parent_stmt->bind_param(str_repeat('s', count($parent_chain)), ...$parent_chain);
        $parent_stmt->execute();
        $parents_result = $parent_stmt->get_result();
        while ($prow = $parents_result->fetch_assoc()) {
            $parent_tasks_data[$prow['uuid']] = [
                'uuid' => $prow['uuid'],
                'title' => $prow['title'],
                'status' => (int)$prow['status']
            ];
        }
        $parent_stmt->close();
        log_debug("[GET_TASK_INFO] Loaded " . count($parent_tasks_data) . " parent tasks data");
    }

    $task_files = get_task_files($task_uuid, $db);
    log_debug("[GET_TASK_INFO] Found " . count($task_files) . " files for task: {$task_uuid}");

    $response['success'] = true;
    $response['task'] = $task;
    $response['task']['files'] = $task_files;
    $response['task']['parent_chain'] = $parent_chain;
    $response['task']['parent_tasks'] = $parent_tasks_data;
    $response['task']['project_uuid'] = $task['project_uuid'];
    $response['task']['direct_parent_uuid'] = $direct_parent;
    return $response;
}

function projects_api_check_edit_permission($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $task_uuid = $_POST['task_uuid'] ?? '';

    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }
    if (!msgql_can_edit_task($current_user_uuid, $task_uuid)) {
        $response['error'] = 'У вас нет прав на редактирование этой задачи';
        return $response;
    }
    $response['success'] = true;
    return $response;
}

function projects_api_get_task_users($db, $current_user_uuid) {
    $response = ['success' => true, 'users' => []];
    $task_uuid = $_POST['task_uuid'] ?? '';

    if (empty($task_uuid)) {
        return $response;
    }

    $taskStmt = $db->prepare("
        SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        WHERE t.uuid = ?
    ");
    $taskStmt->bind_param("s", $task_uuid);
    $taskStmt->execute();
    $taskInfo = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();

    if (!$taskInfo) {
        return $response;
    }

    $project_uuid = $taskInfo['project_uuid'];
    $created_by = $taskInfo['created_by_uuid'];
    $assigned_to = $taskInfo['assigned_to_uuid'];

    $stmt = $db->prepare("
        SELECT DISTINCT u.uuid, u.name, u.login, u.role
        FROM users u
        WHERE u.status = 0
        AND u.uuid != ?
        AND (
            u.role = 0
            OR u.uuid = ?
            OR u.uuid = ?
            OR u.uuid IN (
                SELECT user_uuid FROM user_project_permissions 
                WHERE project_uuid = ? AND can_view = 1
            )
        )
        ORDER BY 
            CASE 
                WHEN u.role = 0 THEN 1
                WHEN u.uuid = ? THEN 2
                WHEN u.uuid = ? THEN 3
                ELSE 4
            END,
            u.name ASC
    ");

    $stmt->bind_param("ssssss", 
        $current_user_uuid,
        $created_by,
        $assigned_to,
        $project_uuid,
        $created_by,
        $assigned_to
    );
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $response['users'] = $users;
    return $response;
}

function projects_api_toggle_task_subscription($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => '', 'is_subscribed' => false];
    $task_uuid = $_POST['task_uuid'] ?? '';

    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }

    $check_stmt = $db->prepare("SELECT id, is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
    $check_stmt->bind_param("ss", $task_uuid, $current_user_uuid);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $now = msgql_now_ms();
    $now_str = (string)$now;

    if ($existing) {
        $new_status = $existing['is_active'] ? 0 : 1;
        $update_stmt = $db->prepare("UPDATE task_subscribers SET is_active = ?, subscribed_at = ?, subscribed_by_uuid = ? WHERE id = ?");
        $update_stmt->bind_param("iisi", $new_status, $now_str, $current_user_uuid, $existing['id']);

        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['is_subscribed'] = ($new_status == 1);
            $response['message'] = $new_status ? 'Вы подписаны на уведомления задачи' : 'Вы отписались от уведомлений задачи';
            log_debug("[TOGGLE_SUB] User {$current_user_uuid} " . ($new_status ? 'subscribed to' : 'unsubscribed from') . " task {$task_uuid}");
        } else {
            $response['error'] = 'Ошибка изменения подписки';
        }
        $update_stmt->close();
    } else {
        $insert_stmt = $db->prepare("
            INSERT INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $insert_stmt->bind_param("ssis", $task_uuid, $current_user_uuid, $now_str, $current_user_uuid);

        if ($insert_stmt->execute()) {
            $response['success'] = true;
            $response['is_subscribed'] = true;
            $response['message'] = 'Вы подписаны на уведомления задачи';
            log_debug("[TOGGLE_SUB] User {$current_user_uuid} subscribed to task {$task_uuid}");
        } else {
            $response['error'] = 'Ошибка создания подписки';
        }
        $insert_stmt->close();
    }
    return $response;
}

function projects_api_check_project_permission($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $project_uuid = $_POST['project_uuid'] ?? '';

    log_debug("[CHECK_PROJECT_PERM] Checking edit_tasks permission for project: {$project_uuid}");

    if (empty($project_uuid)) {
        $response['error'] = 'Не указан проект';
        return $response;
    }

    $can_edit_tasks = msgql_can_access_project($current_user_uuid, $project_uuid, 'edit_tasks');
    log_debug("[CHECK_PROJECT_PERM] User {$current_user_uuid} can_edit_tasks: " . ($can_edit_tasks ? 'true' : 'false'));

    $response['success'] = true;
    $response['can_create_task'] = $can_edit_tasks;
    return $response;
}

function projects_api_check_task_subscription($db, $current_user_uuid) {
    $response = ['success' => true, 'is_subscribed' => false];
    $task_uuid = $_POST['task_uuid'] ?? '';

    if (empty($task_uuid)) {
        $response['error'] = 'Не указана задача';
        return $response;
    }

    $check_stmt = $db->prepare("SELECT is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
    $check_stmt->bind_param("ss", $task_uuid, $current_user_uuid);
    $check_stmt->execute();
    $sub = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $response['is_subscribed'] = ($sub && $sub['is_active'] == 1);
    return $response;
}

function projects_api_mark_project_notifications_read($db, $current_user_uuid) {
    $response = ['success' => false, 'error' => ''];
    $project_uuid = $_POST['project_uuid'] ?? '';

    log_debug("[MARK_PROJECT_READ] Marking project notifications as read: {$project_uuid} for user: {$current_user_uuid}");

    if (empty($project_uuid)) {
        $response['error'] = 'Не указан проект';
        return $response;
    }
    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
        $response['error'] = 'Нет доступа к проекту';
        return $response;
    }

    $table_check = $db->query("SHOW TABLES LIKE 'user_notifications'");
    if ($table_check->num_rows == 0) {
        $response['success'] = true;
        $response['marked_count'] = 0;
        return $response;
    }

    $sql = "
        UPDATE user_notifications n
        JOIN tasks t ON n.task_uuid = t.uuid
        SET n.is_read = 1
        WHERE n.user_uuid = ?
        AND n.is_read = 0
        AND t.project_uuid = ?
    ";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $current_user_uuid, $project_uuid);
        $stmt->execute();
        $marked_count = $db->affected_rows;
        $stmt->close();

        log_debug("[MARK_PROJECT_READ] Marked {$marked_count} notifications as read for project {$project_uuid}");

        $response['success'] = true;
        $response['marked_count'] = $marked_count;
    } else {
        $response['error'] = 'Ошибка подготовки запроса';
    }
    return $response;
}