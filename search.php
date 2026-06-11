<?php
// search.php - ИСПРАВЛЕННАЯ ВЕРСИЯ с параметризованными запросами
// ver.3.3 (2026-05-26) - ДОБАВЛЕНА ПАГИНАЦИЯ для результатов поиска
// - Добавлена пагинация для каждого типа результатов (проекты, задачи, сообщения, файлы)
// - Сохранение параметров сортировки и per_page в сессии
// - Поддержка всех параметров фильтрации в пагинации
// ver.3.2 (2026-05-26) - Добавлена поддержка обновления бейджей
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// Если это AJAX-запрос с action, устанавливаем флаг
if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

// ==================== BLOCK START: Badges Initialization v3.2 ====================
$csrf_token = '';
if (function_exists('msgql_csrf_get_token')) {
    $csrf_token = msgql_csrf_get_token();
}

$current_user_uuid_for_js = '';
if (function_exists('msgql_current_user_uuid')) {
    $current_user_uuid_for_js = msgql_current_user_uuid();
}

$user_tz_offset = 0;
if (function_exists('msgql_user_timezone_offset')) {
    $user_tz_offset = msgql_user_timezone_offset();
}
// ==================== BLOCK END: Badges Initialization v3.2 ====================

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

// ==================== BLOCK START: Pagination Settings v3.3 ====================
// ver.3.3 - Сохранение настроек пагинации в сессии (аналогично files.php)

// Инициализация сессии для сохранения настроек (если не активна)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// per_page - сохраняем в сессии
if (isset($_GET['per_page'])) {
    $per_page_val = (int)$_GET['per_page'];
    if ($per_page_val >= 5 && $per_page_val <= 200) {
        $_SESSION['search_per_page'] = $per_page_val;
    }
}
$per_page = $_SESSION['search_per_page'] ?? 20;

// sort - сохраняем в сессии (для каждого типа свой)
if (isset($_GET['sort'])) {
    $allowed_sorts = ['time', 'title', 'relevance'];
    if (in_array($_GET['sort'], $allowed_sorts, true)) {
        $_SESSION['search_sort'] = $_GET['sort'];
    }
}
$sort = $_SESSION['search_sort'] ?? 'time';

// order - сохраняем в сессии
if (isset($_GET['order'])) {
    $order_val = strtoupper($_GET['order']);
    if ($order_val === 'ASC' || $order_val === 'DESC') {
        $_SESSION['search_order'] = $order_val;
    }
}
$order_upper = $_SESSION['search_order'] ?? 'DESC';
$order_dir = ($order_upper === 'ASC') ? 'ASC' : 'DESC';

// page - из GET
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// ==================== BLOCK END: Pagination Settings v3.3 ====================

$results = [
    'projects' => [],
    'tasks' => [],
    'messages' => [],
    'files' => [],
    'total_projects' => 0,
    'total_tasks' => 0,
    'total_messages' => 0,
    'total_files' => 0,
    'total_pages_projects' => 0,
    'total_pages_tasks' => 0,
    'total_pages_messages' => 0,
    'total_pages_files' => 0
];

if (!empty($search_query) && !empty($current_user_uuid)) {
    $search_like = '%' . $search_query . '%';
    
    // ==================== BLOCK START: Search Projects with Pagination v3.5 ====================
    // ver.3.5 - Добавлена нормализация запроса для поиска вариантов (дефисы/пробелы)
    // Поиск по проектам с пагинацией
    $search_variants = normalize_search_query($search_query);
    $combined_like = build_like_condition('CONCAT(COALESCE(p.title,""), " ", COALESCE(p.descr,""))', $search_variants, $db);
    
    if ($is_admin) {
        // Получаем общее количество
        $count_sql = "SELECT COUNT(*) as total FROM projects p WHERE {$combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute();
        $results['total_projects'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_projects'] = ceil($results['total_projects'] / $per_page);
        
        // Получаем данные с пагинацией и сортировкой
        $order_by = ($sort === 'title') ? 'p.title' : 'p.time';
        $projects_sql = "SELECT p.uuid, p.title, p.descr, p.stamp,
                                u.name as creator_name, u.login as creator_login,
                                (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
                         FROM projects p
                         LEFT JOIN users u ON p.created_by_uuid = u.uuid
                         WHERE {$combined_like}
                         ORDER BY {$order_by} {$order_dir}
                         LIMIT ? OFFSET ?";
        $stmt = $db->prepare($projects_sql);
        $stmt->bind_param("ii", $per_page, $offset);
    } else {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM projects p
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                      AND {$combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
        $count_stmt->execute();
        $results['total_projects'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_projects'] = ceil($results['total_projects'] / $per_page);
        
        $order_by = ($sort === 'title') ? 'p.title' : 'p.time';
        $projects_sql = "SELECT p.uuid, p.title, p.descr, p.stamp,
                                u.name as creator_name, u.login as creator_login,
                                (SELECT COUNT(*) FROM tasks WHERE project_uuid = p.uuid) as tasks_count
                         FROM projects p
                         LEFT JOIN users u ON p.created_by_uuid = u.uuid
                         LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                         WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                         AND {$combined_like}
                         ORDER BY {$order_by} {$order_dir}
                         LIMIT ? OFFSET ?";
        $stmt = $db->prepare($projects_sql);
        $stmt->bind_param("ssii", $current_user_uuid, $current_user_uuid, $per_page, $offset);
    }
    
    if ($stmt && $stmt->execute()) {
        $results['projects'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        log_debug('[SEARCH_PROJECTS] Found ' . count($results['projects']) . ' projects');
    }
    if ($stmt) $stmt->close();
    // ==================== BLOCK END: Search Projects with Pagination v3.5 ====================

    
        // ==================== BLOCK START: Search Tasks with Pagination v3.5 ====================
    // ver.3.5 - Добавлена нормализация запроса для поиска вариантов (дефисы/пробелы)
    // Формируем условия для поиска по задачам
    $task_search_fields = "CONCAT(COALESCE(t.title,''), ' ', COALESCE(t.descr,''), ' ', 
                                  COALESCE(assignee.name,''), ' ', COALESCE(assignee.login,''), ' ',
                                  COALESCE(creator.name,''), ' ', COALESCE(creator.login,''))";
    $task_combined_like = build_like_condition($task_search_fields, $search_variants, $db);
    
    if ($is_admin) {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM tasks t
                      LEFT JOIN users assignee ON t.assigned_to_uuid = assignee.uuid
                      LEFT JOIN users creator ON t.user_uuid = creator.uuid
                      WHERE {$task_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute();
        $results['total_tasks'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_tasks'] = ceil($results['total_tasks'] / $per_page);
        
        $order_by = ($sort === 'title') ? 't.title' : ($sort === 'relevance' ? 't.time' : 't.time');
        $tasks_sql = "SELECT t.uuid, t.title, t.descr, t.status, t.time_end_plan, t.time_start, t.stamp,
                             p.title as project_title, p.uuid as project_uuid,
                             assignee.name as assignee_name, assignee.login as assignee_login,
                             creator.name as creator_name, creator.login as creator_login
                      FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN users assignee ON t.assigned_to_uuid = assignee.uuid
                      LEFT JOIN users creator ON t.user_uuid = creator.uuid
                      WHERE {$task_combined_like}
                      ORDER BY {$order_by} {$order_dir}
                      LIMIT ? OFFSET ?";
        $stmt = $db->prepare($tasks_sql);
        $stmt->bind_param("ii", $per_page, $offset);
    } else {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN users assignee ON t.assigned_to_uuid = assignee.uuid
                      LEFT JOIN users creator ON t.user_uuid = creator.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                      AND {$task_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
        $count_stmt->execute();
        $results['total_tasks'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_tasks'] = ceil($results['total_tasks'] / $per_page);
        
        $order_by = ($sort === 'title') ? 't.title' : ($sort === 'relevance' ? 't.time' : 't.time');
        $tasks_sql = "SELECT t.uuid, t.title, t.descr, t.status, t.time_end_plan, t.time_start, t.stamp,
                             p.title as project_title, p.uuid as project_uuid,
                             assignee.name as assignee_name, assignee.login as assignee_login,
                             creator.name as creator_name, creator.login as creator_login
                      FROM tasks t
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN users assignee ON t.assigned_to_uuid = assignee.uuid
                      LEFT JOIN users creator ON t.user_uuid = creator.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                      AND {$task_combined_like}
                      ORDER BY {$order_by} {$order_dir}
                      LIMIT ? OFFSET ?";
        $stmt = $db->prepare($tasks_sql);
        $stmt->bind_param("ssii", $current_user_uuid, $current_user_uuid, $per_page, $offset);
    }
    
    if ($stmt && $stmt->execute()) {
        $results['tasks'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        log_debug('[SEARCH_TASKS] Found ' . count($results['tasks']) . ' tasks');
    }
    if ($stmt) $stmt->close();
    // ==================== BLOCK END: Search Tasks with Pagination v3.5 ====================
    
        // ==================== BLOCK START: Search Messages with Pagination v3.5 ====================
    // ver.3.5 - Добавлена нормализация запроса для поиска вариантов (дефисы/пробелы)
    $message_combined_like = build_like_condition('m.text', $search_variants, $db);
    
    if ($is_admin) {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM messages m
                      WHERE {$message_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute();
        $results['total_messages'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_messages'] = ceil($results['total_messages'] / $per_page);
        
        $order_by = ($sort === 'title') ? 't.title' : 'm.time';
        $messages_sql = "SELECT m.uuid, m.text, m.time, m.stamp,
                                t.uuid as task_uuid, t.title as task_title,
                                p.uuid as project_uuid, p.title as project_title,
                                u.name as user_name, u.login as user_login
                         FROM messages m
                         JOIN tasks t ON m.task_uuid = t.uuid
                         JOIN projects p ON t.project_uuid = p.uuid
                         LEFT JOIN users u ON m.user_uuid = u.uuid
                         WHERE {$message_combined_like}
                         ORDER BY {$order_by} {$order_dir}
                         LIMIT ? OFFSET ?";
        $stmt = $db->prepare($messages_sql);
        $stmt->bind_param("ii", $per_page, $offset);
    } else {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM messages m
                      JOIN tasks t ON m.task_uuid = t.uuid
                      JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                      AND {$message_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
        $count_stmt->execute();
        $results['total_messages'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_messages'] = ceil($results['total_messages'] / $per_page);
        
        $order_by = ($sort === 'title') ? 't.title' : 'm.time';
        $messages_sql = "SELECT m.uuid, m.text, m.time, m.stamp,
                                t.uuid as task_uuid, t.title as task_title,
                                p.uuid as project_uuid, p.title as project_title,
                                u.name as user_name, u.login as user_login
                         FROM messages m
                         JOIN tasks t ON m.task_uuid = t.uuid
                         JOIN projects p ON t.project_uuid = p.uuid
                         LEFT JOIN users u ON m.user_uuid = u.uuid
                         LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                         WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
                         AND {$message_combined_like}
                         ORDER BY {$order_by} {$order_dir}
                         LIMIT ? OFFSET ?";
        $stmt = $db->prepare($messages_sql);
        $stmt->bind_param("ssii", $current_user_uuid, $current_user_uuid, $per_page, $offset);
    }
    
    if ($stmt && $stmt->execute()) {
        $results['messages'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        log_debug('[SEARCH_MESSAGES] Found ' . count($results['messages']) . ' messages');
    }
    if ($stmt) $stmt->close();
    // ==================== BLOCK END: Search Messages with Pagination v3.5 ====================
    
        // ==================== BLOCK START: Search Files with Pagination v3.5 ====================
    // ver.3.5 - Добавлена нормализация запроса для поиска вариантов (дефисы/пробелы)
    $file_combined_like = build_like_condition('f.orig_name', $search_variants, $db);
    
    if ($is_admin) {
        $count_sql = "SELECT COUNT(*) as total 
                      FROM files f
                      WHERE {$file_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute();
        $results['total_files'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_files'] = ceil($results['total_files'] / $per_page);
        
        $order_by = ($sort === 'title') ? 'f.orig_name' : 'f.time';
        $files_sql = "SELECT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
                             t.uuid as task_uuid, t.title as task_title,
                             p.uuid as project_uuid, p.title as project_title,
                             u.name as uploader_name, u.login as uploader_login
                      FROM files f
                      LEFT JOIN task_files tf ON f.uuid = tf.file_uuid
                      LEFT JOIN tasks t ON tf.task_uuid = t.uuid
                      LEFT JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                      WHERE {$file_combined_like}
                      ORDER BY {$order_by} {$order_dir}
                      LIMIT ? OFFSET ?";
        $stmt = $db->prepare($files_sql);
        $stmt->bind_param("ii", $per_page, $offset);
        
        // Добавляем файлы-призраки (без привязки) - отдельный запрос с пагинацией
        $orphan_combined_like = build_like_condition('f.orig_name', $search_variants, $db);
        
        $orphan_count_sql = "SELECT COUNT(*) as total 
                             FROM files f
                             WHERE NOT EXISTS (SELECT 1 FROM task_files tf WHERE tf.file_uuid = f.uuid)
                             AND NOT EXISTS (SELECT 1 FROM message_files mf WHERE mf.file_uuid = f.uuid)
                             AND {$orphan_combined_like}";
        $orphan_count_stmt = $db->prepare($orphan_count_sql);
        $orphan_count_stmt->execute();
        $orphan_total = (int)$orphan_count_stmt->get_result()->fetch_assoc()['total'];
        $orphan_count_stmt->close();
        
        // Общее количество файлов с учётом призраков
        $results['total_files'] = $results['total_files'] + $orphan_total;
        $results['total_pages_files'] = ceil($results['total_files'] / $per_page);
        
    } else {
        $count_sql = "SELECT COUNT(DISTINCT f.uuid) as total 
                      FROM files f
                      LEFT JOIN task_files tf ON f.uuid = tf.file_uuid
                      LEFT JOIN tasks t ON tf.task_uuid = t.uuid
                      LEFT JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1 OR t.uuid IS NULL)
                      AND {$file_combined_like}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
        $count_stmt->execute();
        $results['total_files'] = (int)$count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
        
        $results['total_pages_files'] = ceil($results['total_files'] / $per_page);
        
        $order_by = ($sort === 'title') ? 'f.orig_name' : 'f.time';
        $files_sql = "SELECT DISTINCT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
                             t.uuid as task_uuid, t.title as task_title,
                             p.uuid as project_uuid, p.title as project_title,
                             u.name as uploader_name, u.login as uploader_login
                      FROM files f
                      LEFT JOIN task_files tf ON f.uuid = tf.file_uuid
                      LEFT JOIN tasks t ON tf.task_uuid = t.uuid
                      LEFT JOIN projects p ON t.project_uuid = p.uuid
                      LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                      LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                      WHERE (p.created_by_uuid = ? OR upp.can_view = 1 OR t.uuid IS NULL)
                      AND {$file_combined_like}
                      ORDER BY {$order_by} {$order_dir}
                      LIMIT ? OFFSET ?";
        $stmt = $db->prepare($files_sql);
        $stmt->bind_param("ssii", $current_user_uuid, $current_user_uuid, $per_page, $offset);
    }
    
    if ($stmt && $stmt->execute()) {
        $results['files'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        log_debug('[SEARCH_FILES] Found ' . count($results['files']) . ' files');
    }
    if ($stmt) $stmt->close();
    
    // Файлы без привязки (только для админа)
    if ($is_admin && $results['total_files'] > 0 && count($results['files']) < $per_page) {
        $remaining = $per_page - count($results['files']);
        $orphan_offset = max(0, $offset - $results['total_files'] + $orphan_total);
        if ($orphan_offset < $orphan_total && $remaining > 0) {
            $orphan_sql = "SELECT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
                                  NULL as task_uuid, NULL as task_title,
                                  NULL as project_uuid, NULL as project_title,
                                  u.name as uploader_name, u.login as uploader_login
                           FROM files f
                           LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
                           WHERE NOT EXISTS (SELECT 1 FROM task_files tf WHERE tf.file_uuid = f.uuid)
                           AND NOT EXISTS (SELECT 1 FROM message_files mf WHERE mf.file_uuid = f.uuid)
                           AND {$orphan_combined_like}
                           ORDER BY {$order_by} {$order_dir}
                           LIMIT ? OFFSET ?";
            $orphan_stmt = $db->prepare($orphan_sql);
            $orphan_stmt->bind_param("ii", $remaining, $orphan_offset);
            $orphan_stmt->execute();
            $orphan_files = $orphan_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($orphan_files as $file) {
                $results['files'][] = $file;
            }
            $orphan_stmt->close();
        }
    }
    // ==================== BLOCK END: Search Files with Pagination v3.5 ====================
}

// Вспомогательные функции
function truncate_text($text, $len = 100) {
    $text = strip_tags($text);
    if (mb_strlen($text) > $len) {
        return mb_substr($text, 0, $len) . '…';
    }
    return $text;
}

function format_file_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ==================== BLOCK START: highlight_text v3.6 (simple) ====================
/**
 * Подсвечивает ключевые слова в тексте
 */
function highlight_text($text, $query) {
    if (empty($query) || empty($text)) {
        return htmlspecialchars($text);
    }
    
    $variants = normalize_search_query($query);
    
    // Собираем все ключевые слова
    $all_keywords = [];
    foreach ($variants as $variant) {
        if (strpos($variant, 'SMART_ALL:') === 0) {
            $keywords = explode('|', substr($variant, 10));
            $all_keywords = array_merge($all_keywords, $keywords);
        } elseif (strpos($variant, 'SMART_ANY:') === 0) {
            $keywords = explode('|', substr($variant, 10));
            $all_keywords = array_merge($all_keywords, $keywords);
        } elseif (strlen($variant) >= 3 && strpos($variant, 'SMART_') !== 0) {
            $all_keywords[] = $variant;
        }
    }
    
    $all_keywords = array_unique($all_keywords);
    usort($all_keywords, function($a, $b) {
        return strlen($b) - strlen($a);
    });
    
    $escaped_text = htmlspecialchars($text);
    
    foreach ($all_keywords as $keyword) {
        if (strlen($keyword) < 2) continue;
        $pattern = '/' . preg_quote($keyword, '/') . '/iu';
        $escaped_text = preg_replace($pattern, '<mark style="background:#4f7cff40; padding:0 2px; border-radius:3px;">$0</mark>', $escaped_text);
    }
    
    return $escaped_text;
}
// ==================== BLOCK END: highlight_text v3.6 ====================


// ==================== BLOCK START: Search Query Normalization v3.6 (NO STOP WORDS) ====================
/**
 * Нормализует поисковый запрос и генерирует варианты для поиска
 * ver.3.6 - Умный поиск БЕЗ стоп-слов (работает на любом языке)
 *          - Разбиение на ключевые слова (все слова важны)
 *          - Поиск по маске с игнорированием порядка слов
 * 
 * @param string $query Исходный поисковый запрос
 * @return array Массив вариантов запроса для поиска
 */
function normalize_search_query($query) {
    if (empty($query)) {
        return [''];
    }
    
    $variants = [];
    $original = trim($query);
    $variants[] = $original;
    
    log_debug('[SEARCH_NORM] Original query: ' . $original);
    
    // Шаг 1: Приводим к нижнему регистру
    $normalized = mb_strtolower($original, 'UTF-8');
    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    $normalized = trim($normalized);
    
    // Шаг 2: Удаляем знаки препинания, но сохраняем буквы, цифры, пробелы, дефисы
    $cleaned = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $normalized);
    log_debug('[SEARCH_NORM] Cleaned: ' . $cleaned);
    
    // Шаг 3: Разбиваем на ВСЕ слова (без удаления)
    $all_words = preg_split('/\s+/u', $cleaned);
    $filtered_words = [];
    foreach ($all_words as $word) {
        $word = trim($word);
        if (strlen($word) >= 2) { // только слова длиной 2+ символа
            $filtered_words[] = $word;
        }
    }
    
    log_debug('[SEARCH_NORM] All keywords: ' . implode(', ', $filtered_words));
    
    // Вариант 1: ВСЕ ключевые слова (порядок не важен)
    if (count($filtered_words) >= 2) {
        $variants[] = 'SMART_ALL:' . implode('|', $filtered_words);
        log_debug('[SEARCH_NORM] Added SMART_ALL with ' . count($filtered_words) . ' keywords');
    }
    
    // Вариант 2: ЛЮБОЕ из ключевых слов (хотя бы одно)
    if (count($filtered_words) >= 1) {
        $variants[] = 'SMART_ANY:' . implode('|', $filtered_words);
        log_debug('[SEARCH_NORM] Added SMART_ANY with ' . count($filtered_words) . ' keywords');
    }
    
    // Вариант 3: Перестановки слов для поиска фраз (только для 2-4 слов)
    if (count($filtered_words) >= 2 && count($filtered_words) <= 4) {
        $permutations = generate_word_permutations($filtered_words, 8);
        foreach ($permutations as $perm) {
            $variants[] = implode(' ', $perm);
        }
        log_debug('[SEARCH_NORM] Added ' . count($permutations) . ' permutations');
    }
    
    // Вариант 4: Обработка дефисов и пробелов
    $words = preg_split('/\s+/u', $cleaned);
    $word_variants = [];
    
    foreach ($words as $word) {
        $word = trim($word);
        if (strlen($word) < 2) {
            $word_variants[] = [$word];
            continue;
        }
        
        $variants_for_word = [$word];
        
        // Дефис -> пробел и наоборот
        if (strpos($word, '-') !== false) {
            $variants_for_word[] = str_replace('-', '', $word);
            $variants_for_word[] = str_replace('-', ' ', $word);
        }
        
        // Пробел -> дефис и слитно
        if (strpos($word, ' ') !== false) {
            $variants_for_word[] = str_replace(' ', '', $word);
            $variants_for_word[] = str_replace(' ', '-', $word);
        }
        
        $word_variants[] = $variants_for_word;
    }
    
    // Комбинируем
    $combined_variants = [''];
    foreach ($word_variants as $word_opts) {
        $new_combined = [];
        foreach ($combined_variants as $existing_phrase) {
            foreach ($word_opts as $word_opt) {
                $new_phrase = trim($existing_phrase . ' ' . $word_opt);
                $new_combined[] = $new_phrase;
            }
        }
        $combined_variants = $new_combined;
    }
    
    foreach ($combined_variants as $variant) {
        $variant = trim($variant);
        if (!empty($variant) && !in_array($variant, $variants) && strpos($variant, 'SMART_') !== 0) {
            $variants[] = $variant;
        }
    }
    
    // Слитное написание
    $joined = str_replace(['-', ' '], '', $cleaned);
    if (!empty($joined) && !in_array($joined, $variants) && strpos($joined, 'SMART_') !== 0) {
        $variants[] = $joined;
    }
    
    log_debug('[SEARCH_NORM] Total variants: ' . count($variants));
    
    return $variants;
}

/**
 * Генерирует перестановки слов (упрощенная версия)
 */
function generate_word_permutations($words, $max = 8) {
    if (count($words) <= 1) return [$words];
    
    $result = [];
    $result[] = $words; // исходный порядок
    
    if (count($words) == 2) {
        $result[] = [$words[1], $words[0]];
    } elseif (count($words) == 3) {
        $result[] = [$words[1], $words[0], $words[2]];
        $result[] = [$words[0], $words[2], $words[1]];
        $result[] = [$words[2], $words[1], $words[0]];
        $result[] = [$words[2], $words[0], $words[1]];
        $result[] = [$words[1], $words[2], $words[0]];
        if (count($result) > $max) $result = array_slice($result, 0, $max);
    }
    
    return $result;
}

/**
 * Формирует SQL условие для "умного поиска"
 * ver.3.6 - Поддержка поиска по ключевым словам (без стоп-слов)
 */
function build_like_condition($column, $variants, $db) {
    if (empty($variants)) {
        return '1=0';
    }
    
    $conditions = [];
    $smart_all_conditions = [];
    $smart_any_conditions = [];
    
    foreach ($variants as $variant) {
        // Умный поиск: должны быть ВСЕ слова
        if (strpos($variant, 'SMART_ALL:') === 0) {
            $keywords = explode('|', substr($variant, 10));
            if (count($keywords) >= 2) {
                $kw_conditions = [];
                foreach ($keywords as $kw) {
                    $escaped = $db->real_escape_string($kw);
                    // 🔥 ФИКС: ищем как с дефисом, так и без
                    $kw_normalized = str_replace('-', ' ', $kw);
                    $escaped_normalized = $db->real_escape_string($kw_normalized);
                    $kw_conditions[] = "({$column} LIKE '%{$escaped}%' OR {$column} LIKE '%{$escaped_normalized}%')";
                }
                $smart_all_conditions[] = '(' . implode(' AND ', $kw_conditions) . ')';
            }
            continue;
        }
        
        // Умный поиск: хотя бы ОДНО слово
        if (strpos($variant, 'SMART_ANY:') === 0) {
            $keywords = explode('|', substr($variant, 10));
            if (count($keywords) >= 1) {
                $kw_conditions = [];
                foreach ($keywords as $kw) {
                    $escaped = $db->real_escape_string($kw);
                    // 🔥 ФИКС: ищем как с дефисом, так и без
                    $kw_normalized = str_replace('-', ' ', $kw);
                    $escaped_normalized = $db->real_escape_string($kw_normalized);
                    $kw_conditions[] = "({$column} LIKE '%{$escaped}%' OR {$column} LIKE '%{$escaped_normalized}%')";
                }
                $smart_any_conditions[] = '(' . implode(' OR ', $kw_conditions) . ')';
            }
            continue;
        }
        
        // Обычный поиск (точные фразы)
        $escaped = $db->real_escape_string($variant);
        if (strlen($variant) >= 2) {
            $conditions[] = "{$column} LIKE '%{$escaped}%'";
        }
    }
    
    $all_parts = [];
    
    if (!empty($conditions)) {
        $all_parts[] = '(' . implode(' OR ', $conditions) . ')';
    }
    
    if (!empty($smart_all_conditions)) {
        $all_parts[] = '(' . implode(' OR ', $smart_all_conditions) . ')';
    }
    
    if (!empty($smart_any_conditions)) {
        $all_parts[] = '(' . implode(' OR ', $smart_any_conditions) . ')';
    }
    
    if (empty($all_parts)) {
        return '1=0';
    }
    
    return '(' . implode(' OR ', $all_parts) . ')';
}
// ==================== BLOCK END: Search Query Normalization v3.6 ====================


// ==================== BLOCK START: Pagination HTML Functions v3.5 (with loading indicator) ====================
// ver.3.4 - Базовая версия
// ver.3.5 (2026-06-05) - ДОБАВЛЕНО СКРЫТИЕ ИНДИКАТОРА ПРИ ЗАГРУЗКЕ СТРАНИЦЫ
function render_pagination($current_page, $total_pages, $per_page, $base_url_params, $anchor = '') {
    if ($total_pages <= 1) return '';
    
    $html = '<div class="pagination">';
    
    $prev_page = max(1, $current_page - 1);
    $next_page = min($total_pages, $current_page + 1);
    
    $params = http_build_query($base_url_params);
    $url_prefix = '?' . ($params ? $params . '&' : '');
    
    // v3.5: Добавляем параметр show_loading для скрытия индикатора
    $loading_script = 'onclick="document.getElementById(\'loadingOverlay\')?.classList.add(\'active\'); setTimeout(function(){ if(window.setPageLoadStatus) window.setPageLoadStatus(\'loading\', \'⏳ Загрузка...\', true); }, 50);"';
    
    if ($current_page > 1) {
        $html .= '<a href="' . $url_prefix . 'page=1' . $anchor . '" class="page-link" ' . $loading_script . '>«</a>';
    } else {
        $html .= '<span class="page-link disabled">«</span>';
    }
    
    if ($current_page > 1) {
        $html .= '<a href="' . $url_prefix . 'page=' . $prev_page . $anchor . '" class="page-link" ' . $loading_script . '>‹</a>';
    } else {
        $html .= '<span class="page-link disabled">‹</span>';
    }
    
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $html .= '<a href="' . $url_prefix . 'page=1' . $anchor . '" class="page-link" ' . $loading_script . '>1</a>';
        if ($start_page > 2) {
            $html .= '<span class="page-link disabled">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $html .= '<span class="page-link active">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $url_prefix . 'page=' . $i . $anchor . '" class="page-link" ' . $loading_script . '>' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<span class="page-link disabled">...</span>';
        }
        $html .= '<a href="' . $url_prefix . 'page=' . $total_pages . $anchor . '" class="page-link" ' . $loading_script . '>' . $total_pages . '</a>';
    }
    
    if ($current_page < $total_pages) {
        $html .= '<a href="' . $url_prefix . 'page=' . $next_page . $anchor . '" class="page-link" ' . $loading_script . '>›</a>';
    } else {
        $html .= '<span class="page-link disabled">›</span>';
    }
    
    if ($current_page < $total_pages) {
        $html .= '<a href="' . $url_prefix . 'page=' . $total_pages . $anchor . '" class="page-link" ' . $loading_script . '>»</a>';
    } else {
        $html .= '<span class="page-link disabled">»</span>';
    }
    
    $total_items = $total_pages * $per_page;
    $start_item = ($current_page - 1) * $per_page + 1;
    $end_item = min($current_page * $per_page, $total_items);
    $html .= '<span class="page-info">' . $start_item . '-' . $end_item . ' из ' . $total_items . '</span>';
    
    $html .= '</div>';
    return $html;
}
// ==================== BLOCK END: Pagination HTML Functions v3.5 ====================

$has_search = !empty($search_query) && !empty($current_user_uuid);
$base_params = ['q' => $search_query, 'tab' => $active_tab, 'per_page' => $per_page, 'sort' => $sort, 'order' => strtolower($order_upper)];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Поиск</title>

    <?php if ($has_search): ?>
    <script src="js/file_preview.js" nonce="<?= CSP_NONCE ?>"></script>
    <?php endif; ?>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0b1020; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #e9eefc; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        .search-header { margin-bottom: 24px; }
        .search-header h1 { font-size: 24px; margin-bottom: 16px; }
        .search-form { display: flex; gap: 12px; max-width: 600px; }
        .search-input { 
            flex: 1; 
            padding: 12px 16px; 
            border-radius: 12px; 
            border: 1px solid rgba(255,255,255,0.15); 
            background: #0b1020; 
            color: #e9eefc; 
            font-size: 16px; 
            -webkit-appearance: none;
            appearance: none;
        }
        .search-input:focus { outline: none; border-color: #4f7cff; }
        .search-btn { 
            padding: 12px 24px; 
            border-radius: 12px; 
            background: #4f7cff; 
            border: none; 
            color: white; 
            font-weight: 600; 
            cursor: pointer; 
            white-space: nowrap;
            transition: background 0.2s;
        }
        .search-btn:hover { background: #3b6ef5; }
        .search-btn:active { transform: scale(0.98); }
        
        .controls-bar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .per-page-select { display: flex; align-items: center; gap: 8px; }
        .per-page-select select { padding: 8px 12px; border-radius: 8px; background: #0b1020; border: 1px solid rgba(255,255,255,0.1); color: #e9eefc; cursor: pointer; }
        .sort-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #121a33; padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sort-label { font-size: 13px; color: rgba(233,238,252,0.7); }
        .sort-link { font-size: 13px; padding: 6px 12px; border-radius: 8px; background: #0b1020; color: #e9eefc; text-decoration: none; transition: all 0.2s; }
        .sort-link:hover { background: rgba(79,124,255,0.2); }
        .sort-link.active { background: #4f7cff; color: white; }
        .sort-link.asc::after { content: ' ↑'; }
        .sort-link.desc::after { content: ' ↓'; }
        
        .search-tabs-wrapper {
            margin: 20px 0;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .search-tabs {
            display: flex;
            gap: 8px;
            padding-bottom: 8px;
            min-width: min-content;
        }
        .tab { 
            padding: 8px 16px; 
            border-radius: 20px; 
            color: rgba(233,238,252,0.7); 
            text-decoration: none; 
            transition: all 0.2s; 
            font-size: 14px;
            white-space: nowrap;
        }
        .tab:hover { background: rgba(79,124,255,0.1); color: #e9eefc; }
        .tab.active { background: #4f7cff; color: white; }
        .tab-count { margin-left: 4px; font-size: 11px; opacity: 0.7; }
        
        .results-section { margin-top: 20px; }
        .result-card { 
            background: #121a33; 
            border-radius: 12px; 
            border: 1px solid rgba(255,255,255,0.08); 
            padding: 16px; 
            margin-bottom: 12px; 
            transition: all 0.2s; 
            word-break: break-word;
        }
        .result-card:hover { border-color: #4f7cff; transform: translateX(4px); }
        .result-title { 
            font-size: 16px; 
            font-weight: 600; 
            margin-bottom: 8px; 
            line-height: 1.4;
        }
        .result-title a { color: #9bb7ff; text-decoration: none; }
        .result-title a:hover { text-decoration: underline; }
        .result-meta { 
            font-size: 11px; 
            color: rgba(233,238,252,0.5); 
            margin-bottom: 8px; 
            display: flex; 
            flex-wrap: wrap; 
            gap: 8px 12px; 
            line-height: 1.4;
        }
        .result-descr { 
            font-size: 13px; 
            color: rgba(233,238,252,0.7); 
            line-height: 1.5; 
            margin-top: 4px;
        }
        .result-badge { 
            display: inline-block; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 10px; 
            font-weight: 500;
            margin-right: 4px;
        }
        .badge-project { background: rgba(79,124,255,0.2); color: #9bb7ff; }
        .badge-task { background: rgba(34,197,94,0.2); color: #4ade80; }
        .badge-message { background: rgba(236,72,153,0.2); color: #f472b6; }
        .badge-file { background: rgba(168,85,247,0.2); color: #a78bfa; }
        
        .empty-state { 
            text-align: center; 
            padding: 60px 20px; 
            color: rgba(233,238,252,0.5); 
        }
        .empty-state h3 { margin-bottom: 12px; font-size: 18px; }
        .empty-state p { font-size: 14px; }
        
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
        
        .footer { margin-top: 24px; font-size: 13px; text-align: center; color: rgba(233,238,252,0.4); padding: 16px; }
        .footer a { color: rgba(233,238,252,0.5); text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        
        /* Стили для бейджей */
        .nav-badge {
            position: absolute !important;
            top: -8px !important;
            right: -12px !important;
            background: #ef4444 !important;
            color: white !important;
            font-size: 10px !important;
            font-weight: bold !important;
            min-width: 18px !important;
            height: 18px !important;
            line-height: 18px !important;
            text-align: center !important;
            border-radius: 50% !important;
            padding: 0 4px !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2) !important;
            transition: transform 0.2s ease !important;
            z-index: 100 !important;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif !important;
            box-sizing: border-box !important;
        }
        .nav-badge:hover { transform: scale(1.05) !important; }
        @media (max-width: 768px) {
            .nav-badge { top: -6px !important; right: -10px !important; min-width: 16px !important; height: 16px !important; line-height: 16px !important; font-size: 9px !important; }
        }
        .nav-links a { position: relative !important; }
        
        /* Мобильная адаптация */
        @media (max-width: 768px) {
            .wrap { padding: 16px; }
            .search-header h1 { font-size: 20px; margin-bottom: 12px; }
            .search-form { gap: 8px; }
            .search-input { padding: 10px 14px; font-size: 15px; }
            .search-btn { padding: 10px 20px; font-size: 14px; }
            .controls-bar { flex-direction: column; align-items: stretch; }
            .per-page-select { justify-content: flex-end; }
            .sort-bar { flex-wrap: wrap; justify-content: center; }
            .tab { padding: 6px 14px; font-size: 13px; }
            .result-card { padding: 12px; margin-bottom: 10px; }
            .result-title { font-size: 15px; }
            .result-meta { font-size: 10px; gap: 6px 10px; }
            .result-descr { font-size: 12px; }
            .result-badge { padding: 1px 6px; font-size: 9px; }
            .empty-state { padding: 40px 16px; }
            .empty-state h3 { font-size: 16px; }
            .empty-state p { font-size: 13px; }
            .footer { margin-top: 16px; font-size: 12px; }
            .pagination { gap: 4px; }
            .page-link { min-width: 32px; height: 32px; font-size: 12px; }
            .page-info { font-size: 11px; margin-left: 8px; }
        }
        
        @media (max-width: 480px) {
            .wrap { padding: 12px; }
            .search-header h1 { font-size: 18px; }
            .search-input { padding: 8px 12px; font-size: 14px; }
            .search-btn { padding: 8px 16px; font-size: 13px; }
            .result-card { padding: 10px; }
            .result-title { font-size: 14px; }
            .result-meta span:first-child { width: 100%; margin-bottom: 4px; }
            .search-tabs-wrapper::-webkit-scrollbar { height: 3px; }
            .search-tabs-wrapper::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 3px; }
            .search-tabs-wrapper::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
        }
    </style>
    
    <script nonce="<?= CSP_NONCE ?>">window.APP_BASE = '<?= $appBase ?>'</script>
    
    <!-- ==================== BLOCK START: Badges Scripts and Global Variables v3.2 ==================== -->
    <script nonce="<?= CSP_NONCE ?>">
        if (typeof window.csrfToken === 'undefined' || !window.csrfToken) {
            window.csrfToken = '<?= $csrf_token ?>';
        }
        window.currentUserUuid = '<?= $current_user_uuid_for_js ?>';
        window.userTimezoneOffset = <?= (int)$user_tz_offset ?>;
        
        window._isPageLoading = true;
        
        if (!window.userTimezoneOffset || window.userTimezoneOffset === 0) {
            window.userTimezoneOffset = new Date().getTimezoneOffset();
            if (window.csrfToken) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.APP_BASE + '/set_timezone.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('offset=' + window.userTimezoneOffset + '&csrf_token=' + encodeURIComponent(window.csrfToken));
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() { window._isPageLoading = false; }, 2000);
        });
    </script>
    
    <script nonce="<?= CSP_NONCE ?>">
    function initBadges() {
        log_debug('[BADGES_INIT] Initializing badges on search page');
        var csrfToken = window.csrfToken || '';
        var url = window.APP_BASE + '/get_badges_data.php';
        
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.badges) {
                log_debug('[BADGES_INIT] Badges data loaded:', data.badges);
                
                var messagesBadge = document.getElementById('badge-messages');
                if (messagesBadge) {
                    if (data.badges.messages > 0) {
                        messagesBadge.textContent = data.badges.messages > 99 ? '99+' : data.badges.messages;
                        messagesBadge.style.display = 'inline-block';
                    } else {
                        messagesBadge.style.display = 'none';
                    }
                }
                
                var projectsBadge = document.getElementById('badge-projects');
                if (projectsBadge) {
                    if (data.badges.projects > 0) {
                        projectsBadge.textContent = data.badges.projects > 99 ? '99+' : data.badges.projects;
                        projectsBadge.style.display = 'inline-block';
                    } else {
                        projectsBadge.style.display = 'none';
                    }
                }
                
                var filesBadge = document.getElementById('badge-files');
                if (filesBadge) {
                    if (data.badges.files > 0) {
                        filesBadge.textContent = data.badges.files > 99 ? '99+' : data.badges.files;
                        filesBadge.style.display = 'inline-block';
                    } else {
                        filesBadge.style.display = 'none';
                    }
                }
                
                if (typeof window.updateNotificationBadge === 'function') {
                    window.updateNotificationBadge(data.badges.notifications);
                } else {
                    var bellBadge = document.getElementById('notificationBadge');
                    if (bellBadge) {
                        if (data.badges.notifications > 0) {
                            bellBadge.textContent = data.badges.notifications > 99 ? '99+' : data.badges.notifications;
                            bellBadge.style.display = 'inline-block';
                        } else {
                            bellBadge.style.display = 'none';
                        }
                    }
                }
            }
        })
        .catch(function(err) { logError('[BADGES_INIT] Error:', err.message); });
    }
    
    window.updateAllBadges = function() {
        if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
            window.SSE.updateAllBadges();
        } else {
            log_debug('[BADGES] SSE not ready, using fallback');
            initBadges();
        }
    };
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBadges);
    } else {
        initBadges();
    }
    </script>
    <!-- ==================== BLOCK END: Badges Scripts and Global Variables v3.2 ==================== -->
    
    <script nonce="<?= CSP_NONCE ?>">
        function showFilePreview(uuid, name, size, mime) {
            if (typeof window.showFilePreview === 'function') {
                window.showFilePreview(uuid, name, size, mime);
            } else {
                window.location.href = window.APP_BASE + '/file_preview.php?uuid=' + encodeURIComponent(uuid);
            }
        }
        
        function showLoading() {
            var overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.classList.add('active');
        }
        
        function hideLoading() {
            var overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.classList.remove('active');
        }
        
        function changePerPage(value) {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', value);
            url.searchParams.set('page', '1');
            showLoading();
            window.location.href = url.toString();
        }
        
        function setSort(field) {
            var url = new URL(window.location.href);
            var currentSort = url.searchParams.get('sort') || 'time';
            var currentOrder = url.searchParams.get('order') || 'desc';
            
            if (currentSort === field) {
                url.searchParams.set('order', currentOrder === 'asc' ? 'desc' : 'asc');
            } else {
                url.searchParams.set('sort', field);
                url.searchParams.set('order', 'desc');
            }
            url.searchParams.set('page', '1');
            showLoading();
            window.location.href = url.toString();
        }
        
        window.addEventListener('load', hideLoading);
        
        function showToast(message, type) {
            var toast = document.createElement('div');
            toast.textContent = message;
            var bgColor = type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981');
            toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + bgColor + '; color:white; padding:10px 20px; border-radius:8px; z-index:10001; font-size:14px; animation:fadeInOut 2s ease;';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 2000);
        }
        
        var toastStyle = document.createElement('style');
        toastStyle.textContent = '@keyframes fadeInOut { 0% { opacity: 0; transform: translateY(20px); } 10% { opacity: 1; transform: translateY(0); } 90% { opacity: 1; transform: translateY(0); } 100% { opacity: 0; transform: translateY(20px); } }';
        if (!document.querySelector('#toast-animation-style')) {
            toastStyle.id = 'toast-animation-style';
            document.head.appendChild(toastStyle);
        }
    </script>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<div class="wrap">
    <div class="search-header">
        <h1>🔍 Результаты поиска: "<?= htmlspecialchars($search_query) ?>"</h1>
        <form method="GET" action="" class="search-form" onsubmit="showLoading(); return true;">
            <input type="text" name="q" class="search-input" placeholder="Поиск..." value="<?= htmlspecialchars($search_query) ?>">
            <button type="submit" class="search-btn">🔍 Найти</button>
            <?php if ($search_query): ?>
            <a href="?" class="reset-btn search-btn" style="background: rgba(255,255,255,0.1);" onclick="showLoading()">✕ Сбросить</a>
            <?php endif; ?>
        </form>
    </div>
    
    <?php if (!empty($search_query) && empty($current_user_uuid)): ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 16px;">🔒</div>
            <h3>Требуется авторизация</h3>
            <p>Пожалуйста, <a href="index.php">войдите в систему</a> для выполнения поиска.</p>
        </div>
    <?php elseif ($has_search): ?>
    
    <!-- ==================== BLOCK START: Controls Bar v3.3 ==================== -->
    <div class="controls-bar">
        <div class="per-page-select">
            <span class="sort-label">На странице:</span>
            <select onchange="changePerPage(this.value)">
                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                <option value="20" <?= $per_page == 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
            </select>
        </div>
    </div>
    
    <div class="sort-bar">
        <span class="sort-label">Сортировка:</span>
        <a href="#" class="sort-link <?= $sort === 'time' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('time')">По дате</a>
        <a href="#" class="sort-link <?= $sort === 'title' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('title')">По названию</a>
        <a href="#" class="sort-link <?= $sort === 'relevance' ? 'active ' . ($order_upper === 'ASC' ? 'asc' : 'desc') : '' ?>" onclick="setSort('relevance')">По релевантности</a>
    </div>
    <!-- ==================== BLOCK END: Controls Bar v3.3 ==================== -->
    
    <div class="search-tabs-wrapper">
        <div class="search-tabs">
            <a href="?q=<?= urlencode($search_query) ?>&tab=all&per_page=<?= $per_page ?>&sort=<?= urlencode($sort) ?>&order=<?= strtolower($order_upper) ?>" class="tab <?= $active_tab === 'all' ? 'active' : '' ?>">
                Все <span class="tab-count">(<?= $results['total_projects'] + $results['total_tasks'] + $results['total_messages'] + $results['total_files'] ?>)</span>
            </a>
            <a href="?q=<?= urlencode($search_query) ?>&tab=projects&per_page=<?= $per_page ?>&sort=<?= urlencode($sort) ?>&order=<?= strtolower($order_upper) ?>" class="tab <?= $active_tab === 'projects' ? 'active' : '' ?>">
                Проекты <span class="tab-count">(<?= $results['total_projects'] ?>)</span>
            </a>
            <a href="?q=<?= urlencode($search_query) ?>&tab=tasks&per_page=<?= $per_page ?>&sort=<?= urlencode($sort) ?>&order=<?= strtolower($order_upper) ?>" class="tab <?= $active_tab === 'tasks' ? 'active' : '' ?>">
                Задачи <span class="tab-count">(<?= $results['total_tasks'] ?>)</span>
            </a>
            <a href="?q=<?= urlencode($search_query) ?>&tab=messages&per_page=<?= $per_page ?>&sort=<?= urlencode($sort) ?>&order=<?= strtolower($order_upper) ?>" class="tab <?= $active_tab === 'messages' ? 'active' : '' ?>">
                Сообщения <span class="tab-count">(<?= $results['total_messages'] ?>)</span>
            </a>
            <a href="?q=<?= urlencode($search_query) ?>&tab=files&per_page=<?= $per_page ?>&sort=<?= urlencode($sort) ?>&order=<?= strtolower($order_upper) ?>" class="tab <?= $active_tab === 'files' ? 'active' : '' ?>">
                Файлы <span class="tab-count">(<?= $results['total_files'] ?>)</span>
            </a>
        </div>
    </div>
    
    <div class="results-section">
        <?php if ($active_tab === 'all' || $active_tab === 'projects'): ?>
            <?php foreach ($results['projects'] as $project): ?>
            <div class="result-card">
                <div class="result-meta">
                    <span class="result-badge badge-project">📁 Проект</span>
                    <span>👤 <?= htmlspecialchars($project['creator_name'] ?: $project['creator_login'] ?: '—') ?></span>
                    <span>📅 <?= htmlspecialchars($project['stamp']) ?></span>
                </div>
                <div class="result-title">
                    <a href="projects.php?project=<?= urlencode($project['uuid']) ?>#project-<?= urlencode($project['uuid']) ?>"><?= highlight_text($project['title'], $search_query) ?></a>
                </div>
                <div class="result-descr"><?= highlight_text($project['descr'] ?: 'Нет описания', $search_query) ?></div>
                <?php if (isset($project['tasks_count']) && $project['tasks_count'] > 0): ?>
                <div class="result-meta" style="margin-top: 8px;">📋 Задач в проекте: <?= (int)$project['tasks_count'] ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($results['projects']) && ($active_tab === 'projects' || $active_tab === 'all')): ?>
                <div class="empty-state">Проекты не найдены</div>
            <?php else: ?>
                <?= render_pagination($page, $results['total_pages_projects'], $per_page, $base_params, '#projects') ?>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($active_tab === 'all' || $active_tab === 'tasks'): ?>
            <?php foreach ($results['tasks'] as $task): ?>
            <div class="result-card">
                <div class="result-meta">
                    <span class="result-badge badge-task">📋 Задача</span>
                    <span>📁 <?= htmlspecialchars($task['project_title']) ?></span>
                    <?php if ($task['assignee_name']): ?>
                    <span>👤 Исполнитель: <?= htmlspecialchars($task['assignee_name'] ?: $task['assignee_login']) ?></span>
                    <?php endif; ?>
                    <span>📅 <?= htmlspecialchars($task['stamp']) ?></span>
                </div>
                <div class="result-title">
                    <a href="projects.php?task=<?= urlencode($task['uuid']) ?>"><?= highlight_text($task['title'], $search_query) ?></a>
                </div>
                <div class="result-descr"><?= highlight_text($task['descr'] ?: 'Нет описания', $search_query) ?></div>
                <?php if ($task['time_start']): ?>
                <div class="result-meta" style="margin-top: 8px;">🚀 Начало: <?= date('d.m.Y H:i', (int)($task['time_start'] / 1000)) ?></div>
                <?php endif; ?>
                <?php if ($task['time_end_plan']): ?>
                <div class="result-meta">⏰ Плановое окончание: <?= date('d.m.Y H:i', (int)($task['time_end_plan'] / 1000)) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($results['tasks']) && ($active_tab === 'tasks' || $active_tab === 'all')): ?>
                <div class="empty-state">Задачи не найдены</div>
            <?php else: ?>
                <?= render_pagination($page, $results['total_pages_tasks'], $per_page, $base_params, '#tasks') ?>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($active_tab === 'all' || $active_tab === 'messages'): ?>
            <?php foreach ($results['messages'] as $msg): ?>
            <div class="result-card">
                <div class="result-meta">
                    <span class="result-badge badge-message">💬 Сообщение</span>
                    <span>📁 <?= htmlspecialchars($msg['project_title']) ?></span>
                    <span>📋 <a href="projects.php?task=<?= urlencode($msg['task_uuid']) ?>" style="color:#9bb7ff;"><?= htmlspecialchars($msg['task_title']) ?></a></span>
                    <span>👤 <?= htmlspecialchars($msg['user_name'] ?: $msg['user_login']) ?></span>
                    <span>📅 <?= htmlspecialchars($msg['stamp']) ?></span>
                </div>
                <div class="result-descr">💬 <?= highlight_text(truncate_text($msg['text'], 150), $search_query) ?></div>
                <div class="result-meta" style="margin-top: 8px;">
                    <a href="messages.php?task=<?= urlencode($msg['task_uuid']) ?>" class="result-badge" style="background:#4f7cff20; text-decoration:none;">➡️ Перейти к обсуждению</a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($results['messages']) && ($active_tab === 'messages' || $active_tab === 'all')): ?>
                <div class="empty-state">Сообщения не найдены</div>
            <?php else: ?>
                <?= render_pagination($page, $results['total_pages_messages'], $per_page, $base_params, '#messages') ?>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($active_tab === 'all' || $active_tab === 'files'): ?>
            <?php foreach ($results['files'] as $file): ?>
            <div class="result-card">
                <div class="result-meta">
                    <span class="result-badge badge-file">📎 Файл</span>
                    <?php if ($file['project_title']): ?>
                    <span>📁 <?= htmlspecialchars($file['project_title']) ?></span>
                    <span>📋 <a href="projects.php?task=<?= urlencode($file['task_uuid']) ?>" style="color:#9bb7ff;"><?= htmlspecialchars($file['task_title']) ?></a></span>
                    <?php else: ?>
                    <span>📁 Не привязан к задаче</span>
                    <?php endif; ?>
                    <span>👤 <?= htmlspecialchars($file['uploader_name'] ?: $file['uploader_login']) ?></span>
                    <span>📅 <?= htmlspecialchars($file['stamp']) ?></span>
                </div>
                <div class="result-title">
                    <a href="#" onclick="showFilePreview('<?= $file['uuid'] ?>', '<?= addslashes($file['orig_name']) ?>', <?= (int)$file['size_bytes'] ?>, '<?= addslashes($file['mime']) ?>'); return false;">
                        <?= highlight_text($file['orig_name'], $search_query) ?>
                    </a>
                </div>
                <div class="result-meta">📦 Размер: <?= format_file_size($file['size_bytes']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($results['files']) && ($active_tab === 'files' || $active_tab === 'all')): ?>
                <div class="empty-state">Файлы не найдены</div>
            <?php else: ?>
                <?= render_pagination($page, $results['total_pages_files'], $per_page, $base_params, '#files') ?>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($results['total_projects'] + $results['total_tasks'] + $results['total_messages'] + $results['total_files'] === 0): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                <h3>Ничего не найдено</h3>
                <p>По запросу "<?= htmlspecialchars($search_query) ?>" ничего не найдено.</p>
                <p style="margin-top: 8px; font-size: 13px;">Попробуйте изменить поисковый запрос.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php elseif (!empty($search_query) && empty($results['total_projects'])): ?>
    <div class="empty-state">
        <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
        <h3>Ничего не найдено</h3>
        <p>По запросу "<?= htmlspecialchars($search_query) ?>" ничего не найдено.</p>
    </div>
    <?php endif; ?>
    
    <div class="footer">
        <a href="index.php">← Вернуться на главную</a>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/sse_client.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/toast.js"></script>

<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    log_debug('[SEARCH_PAGE] Page loaded, initializing badges sync');
    
    setTimeout(function() {
        if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
            window.SSE.updateAllBadges();
            log_debug('[SEARCH_PAGE] Called SSE.updateAllBadges()');
        } else if (typeof window.updateAllBadges === 'function') {
            window.updateAllBadges();
            log_debug('[SEARCH_PAGE] Called updateAllBadges()');
        }
    }, 500);
    
    setTimeout(function() {
        if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
            window.SSE.updateAllBadges();
            log_debug('[SEARCH_PAGE] Second sync: SSE.updateAllBadges()');
        }
    }, 3000);
});

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        log_debug('[SEARCH_PAGE] Tab became visible, syncing badges');
        setTimeout(function() {
            if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
                window.SSE.updateAllBadges();
            } else if (typeof window.updateAllBadges === 'function') {
                window.updateAllBadges();
            }
        }, 500);
    }
});
</script>

</body>
</html>
<?php require_once __DIR__ . '/layouts/page_end.php'; ?>