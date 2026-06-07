<?php
//files.php version 3.2 - ПРИОРИТЕТ: message > task
// version 3.2:
//   - ДОБАВЛЕН чекбокс "Иконки" с сохранением состояния в сессии
//   - При включенном чекбоксе вместо эмодзи-иконок показывается превью изображений
//   - Размер превью задаётся переменной $preview_size (150px)
//   - Превью отображается только для изображений, для остальных типов - стандартная иконка
// version 3.1:
//   - ИСПРАВЛЕН приоритет фильтрации: параметр message важнее task
//   - Если передан message - игнорируем task, показываем только файлы сообщения
//   - СОХРАНЕНИЕ всех параметров: сортировка, тип, per_page, страница в сессии
//   - Убраны дублирующиеся блоки кода (объединены в один)

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/notification_center.php';

if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}
msgql_require_login();


// ========== ОБРАБОТКА AJAX ЗАПРОСОВ НА УДАЛЕНИЕ ФАЙЛА ==========
// Должно быть ПЕРЕД любым выводом HTML!
if (isset($_POST['action']) && $_POST['action'] === 'delete_file' && isset($_POST['ajax_mode']) && $_POST['ajax_mode'] == 1) {
    // Отключаем буферизацию вывода
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    // Проверка CSRF
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    
    $file_uuid = $_POST['file_uuid'] ?? '';
    if (empty($file_uuid)) {
        echo json_encode(['success' => false, 'error' => 'File UUID is required']);
        exit;
    }
    
    $current_user_uuid = msgql_current_user_uuid();
    $is_admin = msgql_is_admin();
    $db = msgql_db();
    
    // Проверяем права на удаление (админ или владелец файла)
    $check_stmt = $db->prepare("SELECT uploaded_by_uuid, storage_name FROM files WHERE uuid = ?");
    $check_stmt->bind_param("s", $file_uuid);
    $check_stmt->execute();
    $file = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }
    
    if (!$is_admin && $file['uploaded_by_uuid'] !== $current_user_uuid) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    // Удаляем связи с сообщениями
    $delete_stmt = $db->prepare("DELETE FROM message_files WHERE file_uuid = ?");
    $delete_stmt->bind_param("s", $file_uuid);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    // Удаляем связи с задачами
    $delete_stmt = $db->prepare("DELETE FROM task_files WHERE file_uuid = ?");
    $delete_stmt->bind_param("s", $file_uuid);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    // Удаляем физический файл (проверяем оба возможных расположения)
    $paths = [
        __DIR__ . '/uploads/tasks/' . $file['storage_name'],
        __DIR__ . '/uploads/messages/' . $file['storage_name']
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            @unlink($path);
            log_debug("[DELETE_FILE] Deleted physical file: {$path}");
        }
    }
    
    // Удаляем запись из БД
    $delete_stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
    $delete_stmt->bind_param("s", $file_uuid);
    $success = $delete_stmt->execute();
    $delete_stmt->close();
    
    if ($success) {
        log_debug("[DELETE_FILE] File deleted: {$file_uuid} by user {$current_user_uuid}");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

// Инициализация сессии для сохранения настроек
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();

// ========== ПРИОРИТЕТ 1: ПОЛУЧЕНИЕ ПАРАМЕТРОВ ФИЛЬТРАЦИИ ==========
// message имеет приоритет выше task!
$filter_message_uuid = isset($_GET['message']) ? trim($_GET['message']) : '';
$filter_task_uuid = isset($_GET['task']) ? trim($_GET['task']) : '';

// Если передан message, игнорируем task
if (!empty($filter_message_uuid)) {
    $filter_task_uuid = '';
}

log_debug("[FILES] Priority: message_uuid={$filter_message_uuid}, task_uuid={$filter_task_uuid}");

// Переменные для отображения контекста
$context_task = null;
$context_message = null;
$is_filtered_by_context = false;


// Получаем контекст для отображения в заголовке
if (!empty($filter_message_uuid)) {
    $is_filtered_by_context = true;
    
    $msg_stmt = $db->prepare("
        SELECT m.uuid, m.task_uuid, m.text, t.title as task_title, t.project_uuid, p.title as project_title
        FROM messages m
        JOIN tasks t ON m.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        WHERE m.uuid = ?
    ");
    $msg_stmt->bind_param("s", $filter_message_uuid);
    $msg_stmt->execute();
    $context_message = $msg_stmt->get_result()->fetch_assoc();
    $msg_stmt->close();
    
    if ($context_message) {
        $context_task = [
            'uuid' => $context_message['task_uuid'],
            'title' => $context_message['task_title'],
            'project_uuid' => $context_message['project_uuid'],
            'project_title' => $context_message['project_title']
        ];
        log_debug("[FILES] Filtered by message: {$filter_message_uuid}, associated task: {$context_task['uuid']}");
    } else {
        log_warning("[FILES] Message not found: {$filter_message_uuid}");
        $filter_message_uuid = '';
        $is_filtered_by_context = false;
    }
} elseif (!empty($filter_task_uuid)) {
    $is_filtered_by_context = true;
    
    $task_stmt = $db->prepare("
        SELECT t.uuid, t.title, t.project_uuid, p.title as project_title
        FROM tasks t
        JOIN projects p ON t.project_uuid = p.uuid
        WHERE t.uuid = ?
    ");
    $task_stmt->bind_param("s", $filter_task_uuid);
    $task_stmt->execute();
    $context_task = $task_stmt->get_result()->fetch_assoc();
    $task_stmt->close();
    
    if (!$context_task) {
        log_warning("[FILES] Task not found: {$filter_task_uuid}");
        $filter_task_uuid = '';
        $is_filtered_by_context = false;
    }
}

// Сбрасываем фильтр при контекстном просмотре, если он не передан явно
if ($is_filtered_by_context && !isset($_GET['type'])) {
    $filter_type = 'all';
    $mime_like = null;
    $extension_filter = null;
    // Не сохраняем в сессию, только для этого запроса
}

// ========== СОХРАНЕНИЕ ПАРАМЕТРОВ В СЕССИИ ==========
// (кроме контекстных параметров task/message, они не сохраняются)

// per_page - сохраняем в сессии
if (isset($_GET['per_page'])) {
    $per_page_val = (int)$_GET['per_page'];
    if ($per_page_val >= 10 && $per_page_val <= 200) {
        $_SESSION['files_per_page'] = $per_page_val;
    }
}
$per_page = $_SESSION['files_per_page'] ?? 50;

// sort - сохраняем в сессии
if (isset($_GET['sort'])) {
    $allowed_sorts = ['time', 'size_bytes', 'orig_name', 'stamp'];
    if (in_array($_GET['sort'], $allowed_sorts, true)) {
        $_SESSION['files_sort'] = $_GET['sort'];
    }
}
$sort = $_SESSION['files_sort'] ?? 'time';

// order - сохраняем в сессии
if (isset($_GET['order'])) {
    $order_val = strtoupper($_GET['order']);
    if ($order_val === 'ASC' || $order_val === 'DESC') {
        $_SESSION['files_order'] = $order_val;
    }
}
$order_upper = $_SESSION['files_order'] ?? 'DESC';

// filter_type - сохраняем в сессии
if (isset($_GET['type'])) {
    $allowed_types = ['all', 'image', 'pdf', 'document', 'spreadsheet', 'archive', 'audio', 'video'];
    if (in_array($_GET['type'], $allowed_types, true)) {
        $_SESSION['files_filter_type'] = $_GET['type'];
    }
}
$filter_type = $_SESSION['files_filter_type'] ?? 'all';


// ========== show_preview - сохраняем в сессии ==========
if (isset($_GET['show_preview'])) {
    $show_preview_val = (int)$_GET['show_preview'];
    if ($show_preview_val === 0 || $show_preview_val === 1) {
        $_SESSION['files_show_preview'] = $show_preview_val;
        log_debug("[FILES] Saved show_preview to session: {$show_preview_val}");
    }
} else {
    // Если параметр не передан - удаляем из сессии
    if (isset($_SESSION['files_show_preview'])) {
        unset($_SESSION['files_show_preview']);
        log_debug("[FILES] Removed show_preview from session");
    }
}
$show_preview = $_SESSION['files_show_preview'] ?? 0;
// ========== КОНЕЦ БЛОКА show_preview ==========


// ========== РАЗМЕР ПРЕВЬЮ ДЛЯ КАРТИНОК ==========
// Размер зависит от режима: при включенном превью - 150px, при выключенном - 40px
if ($show_preview) {
    $preview_size = 200; // размер превью в пикселях (только для изображений)
} else {
    $preview_size = 40; // размер плейсхолдера в пикселях для иконок
}
log_debug("[FILES] Preview size: {$preview_size}px, show_preview: {$show_preview}");
// ========== КОНЕЦ БЛОКА preview_size ==========



// search - НЕ сохраняем в сессии (берем только из GET)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// page - НЕ сохраняем в сессии (всегда из GET)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$offset = ($page - 1) * $per_page;

// ========== ФИЛЬТРАЦИЯ: MIME и РАСШИРЕНИЯ ==========
$mime_like = null;
$extension_filter = null;

switch ($filter_type) {
    case 'image':
        $mime_like = 'image/%';
        $extension_filter = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
        break;
    case 'pdf':
        $mime_like = 'application/pdf';
        $extension_filter = ['pdf'];
        break;
    case 'document':
        $mime_like = '%wordprocessingml%';
        $extension_filter = ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'];
        break;
    case 'spreadsheet':
        $mime_like = '%spreadsheetml%';
        $extension_filter = ['xls', 'xlsx', 'csv', 'ods'];
        break;
    case 'archive':
        $mime_like = '%zip%';
        $extension_filter = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        break;
    case 'audio':
        $mime_like = 'audio/%';
        $extension_filter = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus', 'wma'];
        break;
    case 'video':
        $mime_like = 'video/%';
        $extension_filter = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'm4v', 'mpg', 'mpeg', '3gp', 'ts'];
        break;
    default:
        $mime_like = null;
        $extension_filter = null;
}

// ========== ФУНКЦИЯ ПОЛУЧЕНИЯ ФАЙЛОВ ПО ЗАДАЧЕ ==========
function get_files_by_task($task_uuid, $current_user_uuid, $is_admin, $db) {
    log_debug("[GET_FILES_BY_TASK] Called for task: {$task_uuid}");
    
    if ($is_admin) {
        // Админ: объединяем файлы из task_files и message_files
        $sql = "
            SELECT DISTINCT f.*, u.name as uploader_name, u.login as uploader_login
            FROM files f
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
            WHERE f.uuid IN (
                -- Файлы, прикреплённые напрямую к задаче
                SELECT tf.file_uuid FROM task_files tf WHERE tf.task_uuid = ?
                UNION
                -- Файлы, прикреплённые к сообщениям задачи
                SELECT mf.file_uuid FROM message_files mf
                JOIN messages m ON mf.message_uuid = m.uuid
                WHERE m.task_uuid = ?
            )
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $task_uuid, $task_uuid);
    } else {
        // Обычный пользователь с проверкой прав доступа
        $sql = "
            SELECT DISTINCT f.*, u.name as uploader_name, u.login as uploader_login
            FROM files f
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
            WHERE (
                f.uuid IN (SELECT tf.file_uuid FROM task_files tf WHERE tf.task_uuid = ?)
                OR f.uuid IN (
                    SELECT mf.file_uuid FROM message_files mf
                    JOIN messages m ON mf.message_uuid = m.uuid
                    WHERE m.task_uuid = ?
                )
            )
            AND EXISTS (
                SELECT 1 FROM tasks t
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE t.uuid = ? AND (p.created_by_uuid = ? OR upp.can_view = 1)
            )
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $task_uuid, $task_uuid, $current_user_uuid, $task_uuid, $current_user_uuid);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $files = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    log_debug("[GET_FILES_BY_TASK] Found " . count($files) . " files for task: {$task_uuid}");
    return $files;
}

// ========== ФУНКЦИЯ ПОЛУЧЕНИЯ ФАЙЛОВ ПО СООБЩЕНИЮ ==========
function get_files_by_message($message_uuid, $current_user_uuid, $is_admin, $db) {
    log_debug("[GET_FILES_BY_MESSAGE] Called for message: {$message_uuid}");
    
    if ($is_admin) {
        $sql = "
            SELECT f.*, u.name as uploader_name, u.login as uploader_login
            FROM files f
            JOIN message_files mf ON f.uuid = mf.file_uuid
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
            WHERE mf.message_uuid = ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $message_uuid);
    } else {
        $sql = "
            SELECT f.*, u.name as uploader_name, u.login as uploader_login
            FROM files f
            JOIN message_files mf ON f.uuid = mf.file_uuid
            LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
            WHERE mf.message_uuid = ?
            AND EXISTS (
                SELECT 1 FROM messages m
                JOIN tasks t ON m.task_uuid = t.uuid
                JOIN projects p ON t.project_uuid = p.uuid
                LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                WHERE m.uuid = ? AND (p.created_by_uuid = ? OR upp.can_view = 1)
            )
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ssss", $message_uuid, $current_user_uuid, $message_uuid, $current_user_uuid);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $files = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    log_debug("[GET_FILES_BY_MESSAGE] Found " . count($files) . " files for message: {$message_uuid}");
    return $files;
}

/**
 * Применение фильтрации по типу и поиску к массиву файлов
 */
function apply_filters_to_files($files, $mime_like, $extension_filter, $search) {
    $filtered = [];
    foreach ($files as $file) {
        $include = true;
        
        // Фильтр по типу
        if ($include && ($mime_like !== null || $extension_filter !== null)) {
            $mime_match = ($mime_like !== null && strpos($file['mime'], str_replace('%', '', $mime_like)) !== false);
            $ext = strtolower(pathinfo($file['orig_name'], PATHINFO_EXTENSION));
            $ext_match = ($extension_filter !== null && in_array($ext, $extension_filter));
            
            if (!$mime_match && !$ext_match) {
                $include = false;
            }
        }
        
        // Поиск по имени
        if ($include && !empty($search)) {
            if (stripos($file['orig_name'], $search) === false) {
                $include = false;
            }
        }
        
        if ($include) {
            $filtered[] = $file;
        }
    }
    return $filtered;
}

/**
 * Сортировка массива файлов
 */
function sort_files(&$files, $sort, $order_upper) {
    usort($files, function($a, $b) use ($sort, $order_upper) {
        $cmp = 0;
        switch ($sort) {
            case 'orig_name':
                $cmp = strcmp($a['orig_name'] ?? '', $b['orig_name'] ?? '');
                break;
            case 'size_bytes':
                $cmp = (($a['size_bytes'] ?? 0) <=> ($b['size_bytes'] ?? 0));
                break;
            case 'stamp':
            case 'time':
            default:
                $timeA = (int)($a['time'] ?? 0);
                $timeB = (int)($b['time'] ?? 0);
                $cmp = ($timeA <=> $timeB);
                break;
        }
        return ($order_upper === 'ASC') ? $cmp : -$cmp;
    });
}

// ========== ОСНОВНАЯ ЛОГИКА: ПОЛУЧЕНИЕ ФАЙЛОВ ==========

$files = [];
$total_files = 0;
$total_pages = 0;

if (!empty($filter_message_uuid)) {
    // ПРИОРИТЕТ 1: Фильтрация по сообщению
    log_debug("[FILES] Mode: message filter");
    $files = get_files_by_message($filter_message_uuid, $current_user_uuid, $is_admin, $db);
    
    // Применяем дополнительную фильтрацию по типу и поиску
    $files = apply_filters_to_files($files, $mime_like, $extension_filter, $search);
    
    // Сортировка
    sort_files($files, $sort, $order_upper);
    
    $total_files = count($files);
    $files = array_slice($files, $offset, $per_page);
    $total_pages = ceil($total_files / $per_page);
    
} elseif (!empty($filter_task_uuid)) {
    // ПРИОРИТЕТ 2: Фильтрация по задаче
    log_debug("[FILES] Mode: task filter");
    $files = get_files_by_task($filter_task_uuid, $current_user_uuid, $is_admin, $db);
    
    // Применяем дополнительную фильтрацию по типу и поиску
    $files = apply_filters_to_files($files, $mime_like, $extension_filter, $search);
    
    // Сортировка
    sort_files($files, $sort, $order_upper);
    
    $total_files = count($files);
    $files = array_slice($files, $offset, $per_page);
    $total_pages = ceil($total_files / $per_page);
    
} elseif ($is_admin) {
    // ПРИОРИТЕТ 3: Администратор - все файлы
    log_debug("[FILES] Mode: admin all files");
    
    $where = build_where_condition($mime_like, $extension_filter, $search);
    
    $count_sql = "SELECT COUNT(*) as total FROM files f " . $where['sql'];
    $count_stmt = $db->prepare($count_sql);
    if (!empty($where['params'])) {
        $count_stmt->bind_param($where['types'], ...$where['params']);
    }
    $count_stmt->execute();
    $total_files = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    
    $data_sql = "SELECT f.*, u.name as uploader_name, u.login as uploader_login
                 FROM files f
                 LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                 " . $where['sql'] . "
                 ORDER BY f.`$sort` $order_upper LIMIT ? OFFSET ?";
    
    $data_params = $where['params'];
    $data_types = $where['types'];
    $data_params[] = $per_page;
    $data_params[] = $offset;
    $data_types .= 'ii';
    
    $data_stmt = $db->prepare($data_sql);
    if (!empty($data_params)) {
        $data_stmt->bind_param($data_types, ...$data_params);
    }
    $data_stmt->execute();
    $files = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $data_stmt->close();
    $total_pages = ceil($total_files / $per_page);
    
} else {
    // ПРИОРИТЕТ 4: Обычный пользователь - только файлы из доступных проектов
    log_debug("[FILES] Mode: non-admin user");
    
    // Получаем доступные проекты
    $projects_sql = "SELECT DISTINCT p.uuid 
                     FROM projects p
                     LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                     WHERE p.created_by_uuid = ? OR (upp.can_view = 1)";
    $projects_stmt = $db->prepare($projects_sql);
    $projects_stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
    $projects_stmt->execute();
    $projects_result = $projects_stmt->get_result();
    $accessible_projects = [];
    while ($row = $projects_result->fetch_assoc()) {
        $accessible_projects[] = $row['uuid'];
    }
    $projects_stmt->close();
    
    if (empty($accessible_projects)) {
        $total_files = 0;
        $files = [];
        $total_pages = 0;
    } else {
        $where = build_where_condition($mime_like, $extension_filter, $search);
        
        $access_check = "(p.created_by_uuid = ? OR EXISTS (
            SELECT 1 FROM user_project_permissions upp 
            WHERE upp.project_uuid = p.uuid AND upp.user_uuid = ? AND upp.can_view = 1
        ))";
        
        $base_params = [];
        $base_types = '';
        for ($i = 0; $i < 2; $i++) {
            $base_params[] = $current_user_uuid;
            $base_params[] = $current_user_uuid;
            $base_types .= 'ss';
        }
        
        $filter_params = $where['params'];
        $filter_types = $where['types'];
        
        $count_sql = "SELECT COUNT(DISTINCT f.id) as total
                      FROM files f
                      WHERE (
                          EXISTS (
                              SELECT 1 FROM task_files tf 
                              INNER JOIN tasks t ON tf.task_uuid = t.uuid
                              INNER JOIN projects p ON t.project_uuid = p.uuid
                              WHERE tf.file_uuid = f.uuid AND ($access_check)
                          )
                          OR
                          EXISTS (
                              SELECT 1 FROM message_files mf
                              INNER JOIN messages m ON mf.message_uuid = m.uuid
                              INNER JOIN tasks t ON m.task_uuid = t.uuid
                              INNER JOIN projects p ON t.project_uuid = p.uuid
                              WHERE mf.file_uuid = f.uuid AND ($access_check)
                          )
                      )";
        
        if (!empty($filter_types)) {
            $count_sql .= " AND " . substr($where['sql'], 6);
        }
        
        $count_params = array_merge($base_params, $filter_params);
        $count_types = $base_types . $filter_types;
        
        $count_stmt = $db->prepare($count_sql);
        if (!empty($count_params)) {
            $count_stmt->bind_param($count_types, ...$count_params);
        }
        $count_stmt->execute();
        $total_files = $count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        if ($total_files == 0) {
            $files = [];
            $total_pages = 0;
        } else {
            $data_sql = "SELECT DISTINCT f.*, u.name as uploader_name, u.login as uploader_login
                         FROM files f
                         LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                         WHERE (
                             EXISTS (
                                 SELECT 1 FROM task_files tf 
                                 INNER JOIN tasks t ON tf.task_uuid = t.uuid
                                 INNER JOIN projects p ON t.project_uuid = p.uuid
                                 WHERE tf.file_uuid = f.uuid AND ($access_check)
                             )
                             OR
                             EXISTS (
                                 SELECT 1 FROM message_files mf
                                 INNER JOIN messages m ON mf.message_uuid = m.uuid
                                 INNER JOIN tasks t ON m.task_uuid = t.uuid
                                 INNER JOIN projects p ON t.project_uuid = p.uuid
                                 WHERE mf.file_uuid = f.uuid AND ($access_check)
                             )
                         )";
            
            if (!empty($filter_types)) {
                $data_sql .= " AND " . substr($where['sql'], 6);
            }
            
            $data_sql .= " ORDER BY f.`$sort` $order_upper LIMIT ? OFFSET ?";
            
            $data_params = array_merge($base_params, $filter_params);
            $data_params[] = $per_page;
            $data_params[] = $offset;
            $data_types = $base_types . $filter_types . 'ii';
            
            $data_stmt = $db->prepare($data_sql);
            if (!empty($data_params)) {
                $data_stmt->bind_param($data_types, ...$data_params);
            }
            $data_stmt->execute();
            $files = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $data_stmt->close();
            $total_pages = ceil($total_files / $per_page);
        }
    }
}

// ========== СБОР СВЯЗЕЙ ДЛЯ КАЖДОГО ФАЙЛА ==========
foreach ($files as &$file) {
    $file['messages'] = get_file_messages($file['uuid']);
    $file['tasks'] = get_file_tasks($file['uuid']);
}
unset($file);

// ========== ФУНКЦИЯ ОПРЕДЕЛЕНИЯ КАТЕГОРИИ ==========
function get_file_category($file) {
    $mime = $file['mime'];
    $orig_name = $file['orig_name'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    
    $video_exts = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'm4v', 'mpg', 'mpeg', '3gp', 'ts'];
    if (in_array($ext, $video_exts)) return 'video';
    
    $audio_exts = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus', 'wma'];
    if (in_array($ext, $audio_exts)) return 'audio';
    
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
    if (in_array($ext, $image_exts)) return 'image';
    
    $doc_exts = ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'];
    if (in_array($ext, $doc_exts)) return 'document';
    
    $sheet_exts = ['xls', 'xlsx', 'csv', 'ods'];
    if (in_array($ext, $sheet_exts)) return 'spreadsheet';
    
    if ($ext === 'pdf') return 'pdf';
    
    $archive_exts = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
    if (in_array($ext, $archive_exts)) return 'archive';
    
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    if ($mime === 'application/pdf') return 'pdf';
    if (strpos($mime, 'msword') !== false || strpos($mime, 'wordprocessingml') !== false) return 'document';
    if (strpos($mime, 'ms-excel') !== false || strpos($mime, 'spreadsheetml') !== false) return 'spreadsheet';
    if (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false) return 'archive';
    
    return 'other';
}

// ========== СТАТИСТИКА ==========
$stats = ['total' => 0, 'images' => 0, 'pdfs' => 0, 'documents' => 0, 'spreadsheets' => 0, 'archives' => 0, 'audios' => 0, 'videos' => 0];

if (!$is_filtered_by_context) {
    if ($is_admin) {
        $all_files_result = $db->query("SELECT uuid, orig_name, mime FROM files");
        while ($row = $all_files_result->fetch_assoc()) {
            $stats['total']++;
            $category = get_file_category($row);
            switch ($category) {
                case 'image': $stats['images']++; break;
                case 'pdf': $stats['pdfs']++; break;
                case 'document': $stats['documents']++; break;
                case 'spreadsheet': $stats['spreadsheets']++; break;
                case 'archive': $stats['archives']++; break;
                case 'audio': $stats['audios']++; break;
                case 'video': $stats['videos']++; break;
            }
        }
    } else {
        // Для не-админа статистика по доступным файлам
        $stats['total'] = $total_files;
        foreach ($files as $file) {
            $category = get_file_category($file);
            switch ($category) {
                case 'image': $stats['images']++; break;
                case 'pdf': $stats['pdfs']++; break;
                case 'document': $stats['documents']++; break;
                case 'spreadsheet': $stats['spreadsheets']++; break;
                case 'archive': $stats['archives']++; break;
                case 'audio': $stats['audios']++; break;
                case 'video': $stats['videos']++; break;
            }
        }
    }
}

// ========== ФУНКЦИИ ДЛЯ ВЫВОДА ==========
function get_file_messages($file_uuid) {
    $db = msgql_db();
    $current_user_uuid = msgql_current_user_uuid();
    $is_admin = msgql_is_admin();
    
    if ($is_admin) {
        $stmt = $db->prepare("SELECT m.uuid as message_uuid, m.text, t.uuid as task_uuid, t.title as task_title, p.uuid as project_uuid
                              FROM messages m 
                              JOIN message_files mf ON m.uuid = mf.message_uuid 
                              JOIN tasks t ON m.task_uuid = t.uuid
                              JOIN projects p ON t.project_uuid = p.uuid
                              WHERE mf.file_uuid = ?
                              ORDER BY m.time DESC LIMIT 10");
        $stmt->bind_param("s", $file_uuid);
    } else {
        $stmt = $db->prepare("SELECT m.uuid as message_uuid, m.text, t.uuid as task_uuid, t.title as task_title, p.uuid as project_uuid
                              FROM messages m 
                              JOIN message_files mf ON m.uuid = mf.message_uuid 
                              JOIN tasks t ON m.task_uuid = t.uuid
                              JOIN projects p ON t.project_uuid = p.uuid
                              LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                              WHERE mf.file_uuid = ? 
                              AND (p.created_by_uuid = ? OR upp.can_view = 1)
                              ORDER BY m.time DESC LIMIT 10");
        $stmt->bind_param("sss", $current_user_uuid, $file_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'message_uuid' => $row['message_uuid'],
            'text' => $row['text'],
            'task_uuid' => $row['task_uuid'],
            'task_title' => $row['task_title'],
            'project_uuid' => $row['project_uuid']
        ];
    }
    $stmt->close();
    return $messages;
}

function get_file_tasks($file_uuid) {
    $db = msgql_db();
    $current_user_uuid = msgql_current_user_uuid();
    $is_admin = msgql_is_admin();
    
    if ($is_admin) {
        $stmt = $db->prepare("SELECT t.uuid as task_uuid, t.title, t.time_start, p.title as project_title, p.uuid as project_uuid
                              FROM tasks t 
                              JOIN task_files tf ON t.uuid = tf.task_uuid 
                              JOIN projects p ON t.project_uuid = p.uuid
                              WHERE tf.file_uuid = ?
                              ORDER BY t.time DESC LIMIT 10");
        $stmt->bind_param("s", $file_uuid);
    } else {
        $stmt = $db->prepare("SELECT t.uuid as task_uuid, t.title, t.time_start, p.title as project_title, p.uuid as project_uuid
                              FROM tasks t 
                              JOIN task_files tf ON t.uuid = tf.task_uuid 
                              JOIN projects p ON t.project_uuid = p.uuid
                              LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                              WHERE tf.file_uuid = ? 
                              AND (p.created_by_uuid = ? OR upp.can_view = 1)
                              ORDER BY t.time DESC LIMIT 10");
        $stmt->bind_param("sss", $current_user_uuid, $file_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = [
            'task_uuid' => $row['task_uuid'],
            'title' => $row['title'],
            'time_start' => $row['time_start'],
            'project_title' => $row['project_title'],
            'project_uuid' => $row['project_uuid']
        ];
    }
    $stmt->close();
    return $tasks;
}

function get_file_icon($mime, $orig_name = '') {
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    
    $video_exts = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv', 'wmv', 'm4v', 'mpg', 'mpeg', '3gp'];
    if (in_array($ext, $video_exts)) return '🎬';
    
    $audio_exts = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'];
    if (in_array($ext, $audio_exts)) return '🎵';
    
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
    if (in_array($ext, $image_exts)) return '🖼️';
    
    $doc_exts = ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'];
    if (in_array($ext, $doc_exts)) return '📝';
    
    $sheet_exts = ['xls', 'xlsx', 'csv', 'ods'];
    if (in_array($ext, $sheet_exts)) return '📊';
    
    if ($ext === 'pdf') return '📄';
    
    $archive_exts = ['zip', 'rar', '7z', 'tar', 'gz'];
    if (in_array($ext, $archive_exts)) return '📦';
    
    if (strpos($mime, 'image/') === 0) return '🖼️';
    if ($mime === 'application/pdf') return '📄';
    if (strpos($mime, 'msword') !== false || strpos($mime, 'wordprocessingml') !== false) return '📝';
    if (strpos($mime, 'ms-excel') !== false || strpos($mime, 'spreadsheetml') !== false) return '📊';
    if (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false) return '📦';
    if (strpos($mime, 'audio/') === 0) return '🎵';
    if (strpos($mime, 'video/') === 0) return '🎬';
    if (strpos($mime, 'text/') === 0) return '📃';
    return '📎';
}

/**
 * Проверяет, является ли файл изображением (для показа превью)
 * @param array $file массив с данными файла (mime, orig_name)
 * @return bool true если файл - изображение
 */
function is_image_file($file) {
    $mime = $file['mime'] ?? '';
    $orig_name = $file['orig_name'] ?? '';
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
    
    // Проверка по MIME-типу
    if (strpos($mime, 'image/') === 0) {
        log_debug("[IS_IMAGE] File {$orig_name} is image by MIME: {$mime}");
        return true;
    }
    
    // Проверка по расширению
    if (in_array($ext, $image_exts)) {
        log_debug("[IS_IMAGE] File {$orig_name} is image by extension: {$ext}");
        return true;
    }
    
    log_debug("[IS_IMAGE] File {$orig_name} is NOT image (mime: {$mime}, ext: {$ext})");
    return false;
}
// ========== КОНЕЦ ФУНКЦИИ is_image_file ==========

function format_file_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function truncate_text($text, $length = 30) {
    if (empty($text)) return '';
    $text = strip_tags($text);
    if (mb_strlen($text) > $length) {
        return mb_substr($text, 0, $length) . '...';
    }
    return $text;
}

function build_where_condition($mime_like, $extension_filter, $search) {
    $where_parts = [];
    $params = [];
    $types = '';
    
    if ($mime_like !== null && $extension_filter !== null && !empty($extension_filter)) {
        $placeholders = implode(',', array_fill(0, count($extension_filter), '?'));
        $where_parts[] = "(f.mime LIKE ? OR LOWER(SUBSTRING_INDEX(f.orig_name, '.', -1)) IN ($placeholders))";
        $params[] = $mime_like;
        foreach ($extension_filter as $ext) {
            $params[] = $ext;
        }
        $types .= 's' . str_repeat('s', count($extension_filter));
    } elseif ($mime_like !== null) {
        $where_parts[] = "f.mime LIKE ?";
        $params[] = $mime_like;
        $types .= 's';
    } elseif ($extension_filter !== null && !empty($extension_filter)) {
        $placeholders = implode(',', array_fill(0, count($extension_filter), '?'));
        $where_parts[] = "LOWER(SUBSTRING_INDEX(f.orig_name, '.', -1)) IN ($placeholders)";
        foreach ($extension_filter as $ext) {
            $params[] = $ext;
        }
        $types .= str_repeat('s', count($extension_filter));
    }
    
    if (!empty($search)) {
        $where_parts[] = "f.orig_name LIKE ?";
        $params[] = "%$search%";
        $types .= 's';
    }
    
    $where_sql = empty($where_parts) ? "" : "WHERE " . implode(" AND ", $where_parts);
    
    return ['sql' => $where_sql, 'params' => $params, 'types' => $types];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Файлы</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📁</text></svg>">
    <script nonce="<?= CSP_NONCE ?>">window.APP_BASE = '<?= $appBase ?>'</script>
    <script nonce="<?= CSP_NONCE ?>">
    window.currentUserUuid = '<?= $current_user_uuid ?>';
    window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
    window.showPreview = <?= (int)$show_preview ?>; // ========== ДОБАВЛЕНО для режима превью ==========
    logDebug('[DEBUG] currentUserUuid set to:', window.currentUserUuid);
    logDebug('[DEBUG] showPreview set to:', window.showPreview);
    </script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0b1020; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #e9eefc; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .files-header { margin-bottom: 24px; }
        .files-header h2 { font-size: 24px; font-weight: 600; margin-bottom: 16px; }
        
        .context-header {
            background: linear-gradient(135deg, #121a33 0%, #0f1529 100%);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            border: 1px solid rgba(79, 124, 255, 0.2);
        }
        .context-title {
            font-size: 14px;
            color: rgba(233, 238, 252, 0.7);
            margin-bottom: 8px;
        }
        .context-link {
            color: #9bb7ff;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        .context-link:hover {
            color: #4f7cff;
            text-decoration: underline;
        }
        .context-divider {
            color: rgba(233, 238, 252, 0.4);
            margin: 0 8px;
        }
        .show-all-link {
            display: inline-block;
            margin-top: 8px;
            font-size: 12px;
            color: #60a5fa;
            text-decoration: none;
            background: rgba(79, 124, 255, 0.1);
            padding: 4px 12px;
            border-radius: 16px;
            transition: all 0.2s;
        }
        .show-all-link:hover {
            background: rgba(79, 124, 255, 0.25);
            color: #9bb7ff;
        }
        
        /* ========== ПРЕВЬЮ ИЗОБРАЖЕНИЙ ========== */
        .file-preview-img {
            width: <?= (int)$preview_size ?>px;
            height: <?= (int)$preview_size ?>px;
            object-fit: contain;
            border-radius: 8px;
            background: #0b1020;
            border: 1px solid rgba(255,255,255,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .file-preview-img:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10;
            position: relative;
        }
        .file-preview-placeholder {
            width: <?= (int)$preview_size ?>px;
            height: <?= (int)$preview_size ?>px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #121a33;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.1);
            font-size: 48px;
        }
        .preview-checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
            background: #121a33;
            padding: 8px 16px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .preview-checkbox-label:hover {
            background: #1a2440;
        }
        .preview-checkbox-label input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #4f7cff;
        }
        /* ========== КОНЕЦ БЛОКА ПРЕВЬЮ ========== */

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .stat-card { background: #121a33; border-radius: 12px; padding: 12px; text-align: center; border: 1px solid rgba(255,255,255,0.08); cursor: pointer; transition: all 0.2s; }
        .stat-card:hover { background: #1a2440; transform: translateY(-2px); }
        .stat-card.active { border-color: #4f7cff; background: rgba(79,124,255,0.1); }
        .stat-icon { font-size: 24px; margin-bottom: 6px; }
        .stat-count { font-size: 20px; font-weight: 700; }
        .stat-label { font-size: 11px; color: rgba(233,238,252,0.6); margin-top: 4px; }
        
        .controls-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .search-bar { display: flex; gap: 12px; flex: 2; min-width: 250px; }
        .search-input { flex: 1; padding: 12px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); background: #0b1020; color: #e9eefc; font-size: 14px; }
        .search-input:focus { outline: none; border-color: #4f7cff; }
        .search-btn, .reset-btn { padding: 12px 20px; border-radius: 12px; border: none; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .search-btn { background: #4f7cff; color: white; }
        .search-btn:hover { background: #3b6ef5; }
        .reset-btn { background: rgba(255,255,255,0.1); color: #e9eefc; }
        .reset-btn:hover { background: rgba(255,255,255,0.15); }
        
        .sort-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #121a33; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); }
        .sort-label { font-size: 13px; color: rgba(233,238,252,0.7); }
        .sort-link { font-size: 13px; padding: 6px 12px; border-radius: 8px; background: #0b1020; color: #e9eefc; text-decoration: none; transition: all 0.2s; }
        .sort-link:hover { background: rgba(79,124,255,0.2); }
        .sort-link.active { background: #4f7cff; color: white; }
        .sort-link.asc::after { content: ' ↑'; }
        .sort-link.desc::after { content: ' ↓'; }
        
        .files-table-container { background: #121a33; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); overflow-x: auto; }
        .files-table { width: 100%; border-collapse: collapse; min-width: 900px; }
        .files-table th, .files-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
        .files-table th { background: #0f1529; font-weight: 600; font-size: 13px; color: rgba(233,238,252,0.8); }
        .files-table tr:hover { background: rgba(79,124,255,0.05); }
        .file-preview { width: 40px; text-align: center; font-size: 24px; }
        .file-name { max-width: 250px; word-break: break-word; }
        .file-name a { color: #9bb7ff; text-decoration: none; cursor: pointer; }
        .file-name a:hover { text-decoration: underline; }
        .file-size { font-size: 12px; color: rgba(233,238,252,0.6); white-space: nowrap; }
        .file-time { font-size: 12px; color: rgba(233,238,252,0.6); white-space: nowrap; }
        .file-uploader { font-size: 12px; }
        .file-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .file-action-btn { background: none; border: none; cursor: pointer; font-size: 18px; padding: 4px; color: rgba(233,238,252,0.6); transition: color 0.2s; }
        .file-action-btn:hover { color: #4f7cff; }
        
        .file-usage { font-size: 11px; max-width: 280px; }
        .usage-section { margin-bottom: 6px; }
        .usage-label { color: #60a5fa; font-size: 10px; display: block; margin-bottom: 4px; }
        .usage-link { display: inline-block; background: rgba(79,124,255,0.15); padding: 2px 8px; border-radius: 12px; margin: 2px 4px 2px 0; color: #9bb7ff; text-decoration: none; font-size: 11px; }
        .usage-link:hover { background: rgba(79,124,255,0.3); }
        .usage-empty { color: rgba(233,238,252,0.4); font-style: italic; font-size: 11px; }
        
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
        .page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; background: #121a33; border: 1px solid rgba(255,255,255,0.08); color: #e9eefc; text-decoration: none; font-size: 13px; transition: all 0.2s; }
        .page-link:hover { background: #1a2440; border-color: rgba(79,124,255,0.3); }
        .page-link.active { background: #4f7cff; border-color: #4f7cff; }
        .page-link.disabled { opacity: 0.4; pointer-events: none; }
        .page-info { font-size: 13px; color: rgba(233,238,252,0.6); margin-left: 12px; }
        
        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 50px; height: 50px; border: 3px solid rgba(255,255,255,0.1); border-top-color: #4f7cff; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 60px 20px; color: rgba(233,238,252,0.5); }
        .empty-icon { font-size: 64px; margin-bottom: 16px; }
        .per-page-select { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .per-page-select select { padding: 6px 10px; border-radius: 8px; background: #0b1020; border: 1px solid rgba(255,255,255,0.1); color: #e9eefc; cursor: pointer; }
        
        @media (max-width: 768px) {
            .wrap { padding: 12px; }
            .stats-grid { gap: 8px; }
            .stat-card { padding: 8px; }
            .stat-icon { font-size: 20px; }
            .stat-count { font-size: 16px; }
            .files-table th, .files-table td { padding: 10px 12px; }
            .file-name { max-width: 120px; }
            .file-usage { max-width: 180px; }
        }
        @media (max-width: 800px) {
            .files-table th:nth-child(3), .files-table td:nth-child(3),
            .files-table th:nth-child(4), .files-table td:nth-child(4) { display: none; }
        }
    </style>

     <script>
        // ========== ФУНКЦИЯ ПЕРЕКЛЮЧЕНИЯ РЕЖИМА ПРЕВЬЮ ==========
        function togglePreviewMode() {
            var checkbox = document.getElementById('showPreviewCheckbox');
            var showPreview = checkbox.checked ? 1 : 0;
            
            logDebug('[PREVIEW] Toggling preview mode to:', showPreview);
            
            // Получаем текущие параметры URL
            var urlParams = new URLSearchParams(window.location.search);
            
            // Устанавливаем/удаляем параметр show_preview
            if (showPreview === 1) {
                urlParams.set('show_preview', '1');
            } else {
                urlParams.delete('show_preview');
            }
            
            // Сбрасываем страницу на первую при смене режима
            urlParams.set('page', '1');
            
            // Показываем лоадер и перезагружаем страницу
            showLoading();
            window.location.href = '?' + urlParams.toString();
        }
        // ========== КОНЕЦ ФУНКЦИИ togglePreviewMode ==========
    </script>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<div class="wrap">
    <div class="files-header">
        <h2>📁 Файлы</h2>
    </div>
    
    <?php if ($is_filtered_by_context && ($context_task || $context_message)): ?>
    <div class="context-header">
        <?php if (!empty($filter_message_uuid) && $context_message): ?>
            <div class="context-title">
                📄 Файлы в 
                <a href="<?= $appBase ?>/messages.php?message=<?= urlencode($filter_message_uuid) ?>" class="context-link" target="_blank">
                    этом сообщении
                </a>
                <span class="context-divider">•</span>
                <?= htmlspecialchars($context_task['project_title'] ?? '') ?> \
                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($context_task['uuid'] ?? '') ?>" class="context-link" target="_blank">
                    <?= htmlspecialchars($context_task['title'] ?? '') ?>
                </a>
            </div>
        <?php elseif (!empty($filter_task_uuid) && $context_task): ?>
            <div class="context-title">
                📋 Файлы в 
                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($filter_task_uuid) ?>" class="context-link" target="_blank">
                    этой задаче
                </a>
                <span class="context-divider">•</span>
                <?= htmlspecialchars($context_task['project_title'] ?? '') ?> \
                <?= htmlspecialchars($context_task['title'] ?? '') ?>
            </div>
        <?php endif; ?>
        <a href="<?= $appBase ?>/files.php" class="show-all-link">📁 Показать всё</a>
    </div>
    <?php endif; ?>
    
    <?php if (!$is_filtered_by_context): ?>
    <div class="stats-grid">
        <div class="stat-card <?= $filter_type === 'all' ? 'active' : '' ?>" onclick="setFilter('all')">
            <div class="stat-icon">📁</div>
            <div class="stat-count"><?= number_format($stats['total']) ?></div>
            <div class="stat-label">Все файлы</div>
        </div>
        <div class="stat-card <?= $filter_type === 'image' ? 'active' : '' ?>" onclick="setFilter('image')">
            <div class="stat-icon">🖼️</div>
            <div class="stat-count"><?= number_format($stats['images']) ?></div>
            <div class="stat-label">Изображения</div>
        </div>
        <div class="stat-card <?= $filter_type === 'pdf' ? 'active' : '' ?>" onclick="setFilter('pdf')">
            <div class="stat-icon">📄</div>
            <div class="stat-count"><?= number_format($stats['pdfs']) ?></div>
            <div class="stat-label">PDF</div>
        </div>
        <div class="stat-card <?= $filter_type === 'document' ? 'active' : '' ?>" onclick="setFilter('document')">
            <div class="stat-icon">📝</div>
            <div class="stat-count"><?= number_format($stats['documents']) ?></div>
            <div class="stat-label">Документы</div>
        </div>
        <div class="stat-card <?= $filter_type === 'spreadsheet' ? 'active' : '' ?>" onclick="setFilter('spreadsheet')">
            <div class="stat-icon">📊</div>
            <div class="stat-count"><?= number_format($stats['spreadsheets']) ?></div>
            <div class="stat-label">Таблицы</div>
        </div>
        <div class="stat-card <?= $filter_type === 'archive' ? 'active' : '' ?>" onclick="setFilter('archive')">
            <div class="stat-icon">📦</div>
            <div class="stat-count"><?= number_format($stats['archives']) ?></div>
            <div class="stat-label">Архивы</div>
        </div>
        <div class="stat-card <?= $filter_type === 'audio' ? 'active' : '' ?>" onclick="setFilter('audio')">
            <div class="stat-icon">🎵</div>
            <div class="stat-count"><?= number_format($stats['audios']) ?></div>
            <div class="stat-label">Аудио</div>
        </div>
        <div class="stat-card <?= $filter_type === 'video' ? 'active' : '' ?>" onclick="setFilter('video')">
            <div class="stat-icon">🎬</div>
            <div class="stat-count"><?= number_format($stats['videos']) ?></div>
            <div class="stat-label">Видео</div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="controls-bar">
        <form method="GET" action="" id="searchForm" onsubmit="showLoading(); return true;" style="flex: 2;">
            <div class="search-bar">
                <input type="hidden" name="type" id="filterType" value="<?= htmlspecialchars($filter_type) ?>">
                <input type="hidden" name="sort" id="sortField" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="order" id="sortOrder" value="<?= htmlspecialchars(strtolower($order_upper)) ?>">
                <input type="hidden" name="per_page" id="perPage" value="<?= $per_page ?>">
                <input type="hidden" name="page" id="pageField" value="<?= $page ?>">
                <?php if ($is_filtered_by_context && !empty($filter_task_uuid)): ?>
                    <input type="hidden" name="task" value="<?= htmlspecialchars($filter_task_uuid) ?>">
                <?php endif; ?>
                <?php if ($is_filtered_by_context && !empty($filter_message_uuid)): ?>
                    <input type="hidden" name="message" value="<?= htmlspecialchars($filter_message_uuid) ?>">
                <?php endif; ?>
                <input type="text" name="search" class="search-input" placeholder="Поиск по имени файла..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="search-btn">🔍 Найти</button>
                <?php if ($search || ($filter_type !== 'all' && !$is_filtered_by_context) || $is_filtered_by_context): ?>
                <a href="?<?= $is_filtered_by_context && !empty($filter_task_uuid) ? 'task=' . urlencode($filter_task_uuid) . '&' : '' ?><?= $is_filtered_by_context && !empty($filter_message_uuid) ? 'message=' . urlencode($filter_message_uuid) . '&' : '' ?>page=1&sort=<?= urlencode($sort) ?>&order=<?= urlencode(strtolower($order_upper)) ?>&per_page=<?= $per_page ?><?= $show_preview ? '&show_preview=1' : '' ?>" class="reset-btn" onclick="showLoading()">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </form>
        
        <div class="per-page-select">
            <span class="sort-label">На странице:</span>
            <select onchange="changePerPage(this.value)">
                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                <option value="20" <?= $per_page == 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
            </select>
        </div>
        <!-- ========== ЧЕКБОКС "ИКОНКИ/ПРЕВЬЮ" ========== -->
        <label class="preview-checkbox-label">
            <input type="checkbox" id="showPreviewCheckbox" <?= $show_preview ? 'checked' : '' ?> onchange="togglePreviewMode()">
            <span>🎨 Показывать превью изображений</span>
        </label>
        <!-- ========== КОНЕЦ ЧЕКБОКСА ========== -->
    </div>
    
    <div class="sort-bar">
        <span class="sort-label">Сортировка:</span>
        <a href="#" class="sort-link <?= $sort === 'orig_name' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('orig_name')">По имени</a>
        <a href="#" class="sort-link <?= $sort === 'time' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('time')">По дате</a>
        <a href="#" class="sort-link <?= $sort === 'size_bytes' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('size_bytes')">По размеру</a>
        <a href="#" class="sort-link <?= $sort === 'stamp' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('stamp')">По дате (строка)</a>
    </div>
    
    <div class="files-table-container">
        <?php if (empty($files)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <div>Файлы не найдены</div>
                <?php if ($search || ($filter_type !== 'all' && !$is_filtered_by_context) || $is_filtered_by_context): ?>
                    <div style="margin-top: 12px; font-size: 13px;">Попробуйте изменить параметры поиска</div>
                <?php else: ?>
                    <div style="margin-top: 12px; font-size: 13px;">У вас нет доступа к файлам. Обратитесь к администратору для выдачи прав.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="files-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Имя файла</th>
                        <th>Размер</th>
                        <th>Загружен</th>
                        <th>Кем загружен</th>
                        <th>Используется в</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                    <tr>
                        <?php
                        $is_image = is_image_file($file);
                        $file_uuid_enc = htmlspecialchars($file['uuid']);
                        $orig_name_enc = htmlspecialchars($file['orig_name']);
                        $file_size_int = (int)$file['size_bytes'];
                        $file_mime_enc = addslashes($file['mime']);
                        ?>
                        <td class="file-preview">
                            <?php if ($show_preview && $is_image): ?>
                                <a href="#" onclick="showFilePreview('<?= $file_uuid_enc ?>', '<?= addslashes($file['orig_name']) ?>', <?= $file_size_int ?>, '<?= $file_mime_enc ?>'); return false;" style="display: inline-block; text-decoration: none;">
                                    <img class="file-preview-img"
                                         src="<?= $appBase ?>/file_preview.php?uuid=<?= urlencode($file_uuid_enc) ?>&thumb=1&size=<?= (int)$preview_size ?>"
                                         alt="<?= $orig_name_enc ?>"
                                         loading="lazy"
                                         onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22<?= (int)$preview_size ?>%22%20height%3D%22<?= (int)$preview_size ?>%22%20viewBox%3D%220%200%20100%20100%22%3E%3Crect%20width%3D%22100%22%20height%3D%22100%22%20fill%3D%22%23121a33%22%2F%3E%3Ctext%20x%3D%2250%22%20y%3D%2270%22%20font-size%3D%2248%22%20text-anchor%3D%22middle%22%20fill%3D%22%23e9eefc%22%3E%F0%9F%96%BC%EF%B8%8F%3C%2Ftext%3E%3C%2Fsvg%3E';">
                                </a>
                            <?php else: ?>
                                <div class="file-preview-placeholder" style="width: <?= (int)$preview_size ?>px; height: <?= (int)$preview_size ?>px;">
                                    <?= get_file_icon($file['mime'], $file['orig_name']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="file-name">
                            <a href="#" onclick="showFilePreview('<?= htmlspecialchars($file['uuid']) ?>', '<?= addslashes($file['orig_name']) ?>', <?= (int)$file['size_bytes'] ?>, '<?= addslashes($file['mime']) ?>'); return false;">
                                <?= htmlspecialchars($file['orig_name']) ?>
                            </a>
                        </td>
                        <td class="file-size"><?= format_file_size((int)$file['size_bytes']) ?></td>
                        <td class="file-time"><?= htmlspecialchars($file['stamp'] ?: '-') ?></td>
                        <td class="file-uploader"><?= htmlspecialchars($file['uploader_name'] ?: $file['uploader_login'] ?: '-') ?></td>
                        <td class="file-usage">
                            <?php if (!empty($file['messages'])): ?>
                                <div class="usage-section">
                                    <a href="<?= $appBase ?>/files.php?message=<?= urlencode($file['messages'][0]['message_uuid']) ?>" class="usage-label" style="display: block; text-decoration: none; color: #60a5fa; margin-bottom: 4px;">
                                        💬 Сообщения:
                                    </a>
                                    <?php foreach ($file['messages'] as $msg): ?>
                                        <a href="<?= $appBase ?>/messages.php?task=<?= urlencode($msg['task_uuid']) ?>" class="usage-link" title="<?= htmlspecialchars($msg['text'] ?: 'Без текста') ?>">
                                            💬 <?= htmlspecialchars(truncate_text($msg['text'], 20)) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($file['tasks'])): ?>
                                <div class="usage-section">
                                    <a href="<?= $appBase ?>/files.php?task=<?= urlencode($file['tasks'][0]['task_uuid']) ?>" class="usage-label" style="display: block; text-decoration: none; color: #60a5fa; margin-bottom: 4px;">
                                        📋 Задачи:
                                    </a>
                                    <?php foreach ($file['tasks'] as $task): ?>
                                        <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($task['task_uuid']) ?>" class="usage-link" title="<?= htmlspecialchars($task['title']) ?>">
                                            📋 <?= htmlspecialchars(truncate_text($task['title'], 20)) ?>
                                            <?php if ($task['time_start']): ?>
                                            🚀 <?= date('d.m', (int)($task['time_start'] / 1000)) ?>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (empty($file['messages']) && empty($file['tasks'])): ?>
                                <span class="usage-empty">Не используется</span>
                            <?php endif; ?>
                        </td>
                        <td class="file-actions">
                            <button class="file-action-btn" onclick="showFilePreview('<?= htmlspecialchars($file['uuid']) ?>', '<?= addslashes($file['orig_name']) ?>', <?= (int)$file['size_bytes'] ?>, '<?= addslashes($file['mime']) ?>')" title="Просмотр">👁️</button>
                            <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file['uuid']) ?>" class="file-action-btn" download title="Скачать">⬇️</a>
                            <button class="file-action-btn" onclick="copyFileLink('<?= htmlspecialchars($file['uuid']) ?>')" title="Копировать ссылку" style="color: #9bb7ff;">🔗</button>
                            <?php if ($is_admin || $file['uploaded_by_uuid'] === $current_user_uuid): ?>
                            <button class="file-action-btn" onclick="deleteFile('<?= htmlspecialchars($file['uuid']) ?>')" title="Удалить" style="color: #f87171;">🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <?php if ($total_pages > 1 && $total_files > 0): ?>
    <div class="pagination">
        <?php
        $query_params = [
            'sort' => $sort,
            'order' => strtolower($order_upper),
            'per_page' => $per_page
        ];
        if ($search) $query_params['search'] = $search;
        if ($filter_type !== 'all' && !$is_filtered_by_context) $query_params['type'] = $filter_type;
        if ($is_filtered_by_context && !empty($filter_task_uuid)) $query_params['task'] = $filter_task_uuid;
        if ($is_filtered_by_context && !empty($filter_message_uuid)) $query_params['message'] = $filter_message_uuid;
        if ($show_preview) $query_params['show_preview'] = '1'; // ========== ДОБАВЛЕНО для превью ==========
        
        $base_url = '?' . http_build_query($query_params);
        
        $prev_page = max(1, $page - 1);
        $next_page = min($total_pages, $page + 1);
        ?>
        
        <a href="<?= $base_url ?>&page=1" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" onclick="showLoading()">«</a>
        <a href="<?= $base_url ?>&page=<?= $prev_page ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" onclick="showLoading()">‹</a>
        
        <?php
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        if ($start_page > 1): ?>
            <a href="<?= $base_url ?>&page=1" class="page-link" onclick="showLoading()">1</a>
            <?php if ($start_page > 2): ?>
                <span class="page-link disabled">...</span>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <a href="<?= $base_url ?>&page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>" onclick="showLoading()"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="page-link disabled">...</span>
            <?php endif; ?>
            <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-link" onclick="showLoading()"><?= $total_pages ?></a>
        <?php endif; ?>
        
        <a href="<?= $base_url ?>&page=<?= $next_page ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" onclick="showLoading()">›</a>
        <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" onclick="showLoading()">»</a>
        
        <span class="page-info">
            Всего: <?= number_format($total_files) ?> файлов • Страница <?= $page ?> из <?= $total_pages ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= CSP_NONCE ?>">
window.csrfToken = '<?= $csrf_token ?>';

function addCsrfToFormData(formData) {
    if (formData instanceof FormData) {
        formData.append('csrf_token', window.csrfToken);
    }
}

function addCsrfToUrlParams(params) {
    if (params instanceof URLSearchParams) {
        params.append('csrf_token', window.csrfToken);
    }
}

function addCsrfToObject(obj) {
    obj.csrf_token = window.csrfToken;
    return obj;
}
</script>

<script nonce="<?= CSP_NONCE ?>">
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('active');
}

function setFilter(type) {
    document.getElementById('filterType').value = type;
    document.getElementById('pageField').value = 1;
    showLoading();
    document.getElementById('searchForm').submit();
}

function setSort(field) {
    var currentSort = document.getElementById('sortField').value;
    var currentOrder = document.getElementById('sortOrder').value;
    
    if (currentSort === field) {
        document.getElementById('sortOrder').value = currentOrder === 'asc' ? 'desc' : 'asc';
    } else {
        document.getElementById('sortField').value = field;
        document.getElementById('sortOrder').value = 'desc';
    }
    document.getElementById('pageField').value = 1;
    showLoading();
    document.getElementById('searchForm').submit();
}

function changePerPage(value) {
    document.getElementById('perPage').value = value;
    document.getElementById('pageField').value = 1;
    showLoading();
    document.getElementById('searchForm').submit();
}

function deleteFile(fileUuid) {
    if (!confirm('Удалить этот файл? Он будет удалён из всех сообщений и задач.')) {
        return;
    }
    
    showLoading();
    
    var formData = new URLSearchParams();
    formData.append('action', 'delete_file');
    formData.append('file_uuid', fileUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    var xhr = new XMLHttpRequest();
    // ИСПРАВЛЕНО: отправляем НА ТОТ ЖЕ САМЫЙ files.php, а НЕ projects.php
    xhr.open('POST', window.APP_BASE + 'files.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    showToast('✓ Файл удалён', 'success');
                    setTimeout(function() { location.reload(); }, 500);
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    hideLoading();
                }
            } catch(e) {
                console.error('Parse error:', e);
                showToast('Ошибка обработки ответа сервера', 'error');
                hideLoading();
            }
        } else {
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            hideLoading();
        }
    };
    
    xhr.onerror = function() {
        showToast('Сетевая ошибка', 'error');
        hideLoading();
    };
    
    xhr.ontimeout = function() {
        showToast('Превышено время ожидания', 'error');
        hideLoading();
    };
    
    xhr.send(formData);
}

window.addEventListener('load', function() {
    hideLoading();
});

function copyFileLink(fileUuid) {
    var fullUrl = window.location.origin + window.APP_BASE + '/file_preview.php?uuid=' + fileUuid;
    
    navigator.clipboard.writeText(fullUrl).then(function() {
        showToast('✓ Ссылка на файл скопирована', 'success');
    }).catch(function() {
        var textarea = document.createElement('textarea');
        textarea.value = fullUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('✓ Ссылка на файл скопирована', 'success');
    });
}

function showToast(message, type) {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + (type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981')) + '; color:white; padding:10px 20px; border-radius:8px; z-index:10001; font-size:14px; animation:fadeInOut 2s ease;';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 2000);
}

var toastStyle = document.createElement('style');
toastStyle.textContent = `
    @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(20px); }
        10% { opacity: 1; transform: translateY(0); }
        90% { opacity: 1; transform: translateY(0); }
        100% { opacity: 0; transform: translateY(20px); }
    }
`;
if (!document.querySelector('#toast-animation-style')) {
    toastStyle.id = 'toast-animation-style';
    document.head.appendChild(toastStyle);
}


// Вызываем обновление всех бейджей после загрузки страницы
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
            window.SSE.updateAllBadges();
            logDebug('[FILES_PAGE] Called SSE.updateAllBadges()');
        } else if (typeof updateAllBadges === 'function') {
            updateAllBadges();
            logDebug('[FILES_PAGE] Called updateAllBadges()');
        }
    }, 300);
});
</script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>