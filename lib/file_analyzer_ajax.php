<?php
// lib/file_analyzer_ajax.php version 1.4
// AJAX-эндпоинт для анализа файлов по UUID

// ========== 1. ОЧИСТКА БУФЕРОВ ПЕРЕД ВСЕМ ==========
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// ========== 2. УСТАНАВЛИВАЕМ ФЛАГ AJAX ==========
define('AJAX_REQUEST', true);
$GLOBALS['_AJAX_MODE'] = true;

// ========== 3. ЗАГРУЖАЕМ boot.php (он не выводит HTML) ==========
$bootPath = __DIR__ . '/../boot.php';
if (!file_exists($bootPath)) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'boot.php not found']);
    exit;
}
require_once $bootPath;

// ========== 4. ВРЕМЕННО ПЕРЕХВАТЫВАЕМ ВЫВОД init.php ==========
ob_start();
require_once __DIR__ . '/../init.php';
$initOutput = ob_get_clean();

// Логируем только если есть нежелательный вывод (для отладки)
if (!empty(trim($initOutput)) && defined('MSGQL_DEBUG') && MSGQL_DEBUG) {
    error_log("[file_analyzer_ajax] init.php output suppressed: " . substr($initOutput, 0, 200));
}

// ========== 5. ПРОВЕРЯЕМ ФАЙЛ АНАЛИЗАТОРА ==========
$analyzerPath = __DIR__ . '/file_analyzer.php';
if (!file_exists($analyzerPath)) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'file_analyzer.php not found']);
    exit;
}
require_once $analyzerPath;

// ========== 6. ОТВЕТЫ ==========
ob_clean(); // Очищаем буфер перед JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Проверка авторизации
if (!function_exists('msgql_is_logged_in') || !msgql_is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$fileUuid = $_GET['uuid'] ?? $_POST['uuid'] ?? '';
if (empty($fileUuid)) {
    echo json_encode(['success' => false, 'error' => 'File UUID required']);
    exit;
}

// Проверка существования файла
$db = msgql_db();
$stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
$stmt->bind_param("s", $fileUuid);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit;
}

// Выполняем анализ
$previewSize = isset($_GET['size']) ? min(65536, (int)$_GET['size']) : 65536;

try {
    if (!class_exists('FileAnalyzer')) {
        throw new Exception('FileAnalyzer class not found');
    }
    $result = FileAnalyzer::analyzeByUuid($fileUuid, $previewSize);
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

exit;