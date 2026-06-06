<?php
// download.php version 2.1: скачивание и предпросмотр файлов
// v2.1 - ИСПРАВЛЕНИЕ: добавлена строгая валидация имени файла,
//        защита от LFI, использование констант путей из init.php

// Отключаем вывод ошибок в поток
define('AJAX_REQUEST', true);
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
msgql_require_login();


// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

// 🔥 Очищаем все буферы вывода перед отправкой бинарных данных
while (ob_get_level() > 0) {
    ob_end_clean();
}

// ========== ИСПОЛЬЗУЕМ КОНСТАНТЫ ИЗ init.php ==========
if (!defined('UPLOADS_BASE_DIR')) {
    // Fallback если константы не определены
    define('UPLOADS_BASE_DIR', __DIR__ . '/uploads/');
    define('MESSAGES_UPLOAD_DIR', __DIR__ . '/uploads/messages/');
    define('TASKS_UPLOAD_DIR', __DIR__ . '/uploads/tasks/');
}

// Проверка существования базовой директории
if (!file_exists(UPLOADS_BASE_DIR)) {
    log_error("[DOWNLOAD] Uploads directory not found: " . UPLOADS_BASE_DIR);
    header('HTTP/1.0 500 Internal Server Error');
    echo json_encode(['error' => 'System configuration error']);
    exit;
}

$file_uuid = $_GET['file'] ?? '';
if (!$file_uuid) {
    header('HTTP/1.0 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File UUID not specified']);
    exit;
}

// Функция для корректного Content-Disposition
function get_content_disposition_filename($filename, $disposition = 'inline') {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_mobile = preg_match('/(android|iphone|ipad|mobile|phone)/i', $ua);
    
    $safe_name = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($filename));
    
    if ($is_mobile) {
        return $disposition . '; filename="' . $safe_name . '"';
    }
    
    $encoded = rawurlencode($filename);
    return $disposition . "; filename*=UTF-8''{$encoded}";
}

$db = msgql_db();
$stmt = $db->prepare("SELECT storage_name, orig_name, mime, size_bytes FROM files WHERE uuid = ?");
$stmt->bind_param("s", $file_uuid);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found in database']);
    exit;
}

// ========== V-HIGH-03 FIX: Строгая валидация имени файла ==========
$storage_name = $file['storage_name'];

// 1. Проверка на допустимые символы
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $storage_name)) {
    log_error("[DOWNLOAD_SECURITY] Invalid characters in storage_name: {$storage_name}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid file name format']);
    exit;
}

// 2. Проверка на path traversal
if (strpos($storage_name, '/') !== false || 
    strpos($storage_name, '\\') !== false || 
    strpos($storage_name, '..') !== false) {
    log_error("[DOWNLOAD_SECURITY] Path traversal attempt: {$storage_name}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid file path']);
    exit;
}

// 3. Проверка на опасные расширения
$dangerous_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'bash'];
$file_ext = strtolower(pathinfo($storage_name, PATHINFO_EXTENSION));
if (in_array($file_ext, $dangerous_extensions)) {
    log_error("[DOWNLOAD_SECURITY] Dangerous extension blocked: {$file_ext} - {$storage_name}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

log_debug("[DOWNLOAD_SECURITY] File validation passed: {$storage_name}");

// Проверка прав доступа
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();
$has_access = false;

if ($is_admin) {
    $has_access = true;
} else {
    // 1. Через task_files
    $perm_stmt = $db->prepare("
        SELECT 1 FROM task_files tf
        JOIN tasks t ON tf.task_uuid = t.uuid
        JOIN projects p ON t.project_uuid = p.uuid
        LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
        WHERE tf.file_uuid = ?
        AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
        LIMIT 1
    ");
    $perm_stmt->bind_param("ssss", $current_user_uuid, $file_uuid, $current_user_uuid, $current_user_uuid);
    $perm_stmt->execute();
    $has_access = (bool)$perm_stmt->get_result()->fetch_row();
    $perm_stmt->close();

    // 2. Через message_files
    if (!$has_access) {
        $perm_stmt2 = $db->prepare("
            SELECT 1 FROM message_files mf
            JOIN messages m ON mf.message_uuid = m.uuid
            JOIN tasks t ON m.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE mf.file_uuid = ?
            AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
            LIMIT 1
        ");
        $perm_stmt2->bind_param("ssss", $current_user_uuid, $file_uuid, $current_user_uuid, $current_user_uuid);
        $perm_stmt2->execute();
        $has_access = (bool)$perm_stmt2->get_result()->fetch_row();
        $perm_stmt2->close();
    }

    // 3. Через подписки
    if (!$has_access) {
        $perm_stmt3 = $db->prepare("
            SELECT 1 FROM task_subscribers ts
            JOIN task_files tf ON ts.task_uuid = tf.task_uuid
            WHERE tf.file_uuid = ? AND ts.user_uuid = ? AND ts.is_active = 1
            LIMIT 1
        ");
        $perm_stmt3->bind_param("ss", $file_uuid, $current_user_uuid);
        $perm_stmt3->execute();
        $has_access = (bool)$perm_stmt3->get_result()->fetch_row();
        $perm_stmt3->close();
    }
}

if (!$has_access) {
    log_warning("[DOWNLOAD_ACCESS] Access denied for user {$current_user_uuid} to file {$file_uuid}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ========== ПОИСК ФАЙЛА НА ДИСКЕ с использованием констант ==========
$paths = [
    MESSAGES_UPLOAD_DIR . $storage_name,
    TASKS_UPLOAD_DIR . $storage_name
];
$file_path = null;
foreach ($paths as $path) {
    if (is_file($path) && is_readable($path)) {
        $file_path = $path;
        break;
    }
}

if (!$file_path) {
    log_error("[DOWNLOAD] File not found on disk: {$storage_name}");
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found on disk']);
    exit;
}

// ========== ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА REALPATH ==========
$real_path = realpath($file_path);
$upload_base = realpath(UPLOADS_BASE_DIR);

if ($real_path === false) {
    log_error("[DOWNLOAD_SECURITY] Cannot resolve realpath for: {$file_path}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid file path']);
    exit;
}

if (strpos($real_path, $upload_base) !== 0) {
    log_error("[DOWNLOAD_SECURITY] Path traversal detected! Path: {$real_path}, Base: {$upload_base}");
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid file path']);
    exit;
}

// Определяем режим: inline (preview) или attachment (download)
$is_preview = isset($_GET['preview']) && $_GET['preview'] === '1';
$is_force_download = isset($_GET['download']) && $_GET['download'] === '1';
$mime = $file['mime'] ?: 'application/octet-stream';

if ($is_force_download) {
    $disposition = 'attachment';
} elseif ($is_preview) {
    $disposition = 'inline';
} else {
    $inline_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'text/plain'];
    $disposition = in_array($mime, $inline_mimes) ? 'inline' : 'attachment';
}

// Отправка заголовков
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . get_content_disposition_filename($file['orig_name'], $disposition));
header('Content-Length: ' . $file['size_bytes']);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600, immutable');
header('X-Content-Type-Options: nosniff');

// Для PDF-превью убираем X-Frame-Options
if ($is_preview && $mime === 'application/pdf') {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
} else {
    header('X-Frame-Options: SAMEORIGIN');
}

// Отправка файла
readfile($file_path);
exit;