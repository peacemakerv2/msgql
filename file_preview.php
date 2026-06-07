<?php
// file_preview.php - версия 5.2 с поддержкой DOCX (mammoth.js)
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

msgql_require_login();

$file_uuid = $_GET['uuid'] ?? '';
if (!$file_uuid) {
    header('Location: ' . $appBase .  '/messages.php');
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

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$is_admin = msgql_is_admin();

// Получаем информацию о файле
$stmt = $db->prepare("SELECT f.*, u.name as uploader_name 
                      FROM files f 
                      LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid 
                      WHERE f.uuid = ?");
$stmt->bind_param("s", $file_uuid);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    header('Location: ' . $appBase . '/messages.php');
    exit;
}

// Проверка доступа
$has_access = false;
$stmt = $db->prepare("SELECT DISTINCT m.task_uuid FROM messages m JOIN message_files mf ON m.uuid = mf.message_uuid WHERE mf.file_uuid = ? LIMIT 1");
$stmt->bind_param("s", $file_uuid);
$stmt->execute();
$msg_task = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($msg_task) {
    $has_access = msgql_can_access_task($current_user_uuid, $msg_task['task_uuid'], 'view');
}

if (!$has_access) {
    $stmt = $db->prepare("SELECT tf.task_uuid FROM task_files tf WHERE tf.file_uuid = ? LIMIT 1");
    $stmt->bind_param("s", $file_uuid);
    $stmt->execute();
    $task_file = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($task_file) {
        $has_access = msgql_can_access_task($current_user_uuid, $task_file['task_uuid'], 'view');
    }
}

if (!$has_access && $is_admin) {
    $has_access = true;
}

if (!$has_access) {
    http_response_code(403);
    die('Нет доступа к этому файлу');
}

function format_file_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function get_file_icon($mime, $filename) {
    if (strpos($mime, 'image/') === 0) return '🖼️';
    if ($mime === 'application/pdf') return '📄';
    if (strpos($mime, 'video/') === 0) return '🎬';
    if (strpos($mime, 'audio/') === 0) return '🎵';
    if (strpos($mime, 'application/zip') !== false || strpos($mime, 'application/x-rar') !== false) return '📦';
    if (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'excel') !== false) return '📊';
    if (strpos($mime, 'wordprocessingml') !== false || strpos($mime, 'msword') !== false) return '📝';
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $icons = ['doc'=>'📝', 'docx'=>'📝', 'xls'=>'📊', 'xlsx'=>'📊', 'txt'=>'📃', 'md'=>'📃'];
    return $icons[$ext] ?? '📎';
}

// Получаем задачи и сообщения для отображения связей
$tasks_stmt = $db->prepare("SELECT t.uuid, t.title, p.title as project_title 
                            FROM tasks t 
                            JOIN task_files tf ON t.uuid = tf.task_uuid 
                            JOIN projects p ON t.project_uuid = p.uuid
                            WHERE tf.file_uuid = ? LIMIT 5");
$tasks_stmt->bind_param("s", $file_uuid);
$tasks_stmt->execute();
$file_tasks = $tasks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tasks_stmt->close();

$msgs_stmt = $db->prepare("SELECT m.uuid, t.uuid as task_uuid, t.title as task_title, m.text 
                           FROM messages m 
                           JOIN message_files mf ON m.uuid = mf.message_uuid
                           JOIN tasks t ON m.task_uuid = t.uuid
                           WHERE mf.file_uuid = ? LIMIT 5");
$msgs_stmt->bind_param("s", $file_uuid);
$msgs_stmt->execute();
$file_messages = $msgs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$msgs_stmt->close();

// ==================== BLOCK START: File type detection v5.3 (added HTML support) ====================
// ver.5.2 - Базовая версия
// ver.5.3 (2026-06-07) - ДОБАВЛЕНА ПОДДЕРЖКА HTML ФАЙЛОВ
// - HTML файлы теперь рендерятся как веб-страницы (не как исходный код)
// - Добавлены типы: .html, .htm

$is_image = strpos($file['mime'], 'image/') === 0;
$is_pdf = $file['mime'] === 'application/pdf';
$is_docx = strpos($file['mime'], 'wordprocessingml') !== false || 
$file['mime'] === 'application/msword' || in_array(strtolower(pathinfo($file['orig_name'], PATHINFO_EXTENSION)), ['docx', 'doc']);

$is_zip = $file['mime'] === 'application/zip' || 
          $file['mime'] === 'application/x-zip-compressed' ||
          in_array(strtolower(pathinfo($file['orig_name'], PATHINFO_EXTENSION)), ['zip', 'rar', '7z']);
$is_audio = strpos($file['mime'], 'audio/') === 0;
$is_video = strpos($file['mime'], 'video/') === 0;

// ========== HTML DETECTION (v5.3) ==========
$ext = strtolower(pathinfo($file['orig_name'], PATHINFO_EXTENSION));
$is_html = ($file['mime'] === 'text/html' || 
            $file['mime'] === 'application/xhtml+xml' ||
            in_array($ext, ['html', 'htm']));

log_debug("[FILE_PREVIEW] File detection - UUID: {$file_uuid}");
log_debug("[FILE_PREVIEW] is_image: " . ($is_image ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_pdf: " . ($is_pdf ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_docx: " . ($is_docx ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_zip: " . ($is_zip ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_audio: " . ($is_audio ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_video: " . ($is_video ? 'true' : 'false'));
log_debug("[FILE_PREVIEW] is_html: " . ($is_html ? 'true' : 'false'));

// ========== ОПРЕДЕЛЯЕМ EXCEL ПО РАСШИРЕНИЮ (ПРИНУДИТЕЛЬНО) ==========
$is_xlsx = in_array($ext, ['xlsx', 'xls', 'xlsm', 'xltx', 'xlt', 'csv', 'xlsb']);

// Отладка в PHP лог
log_debug("[DEBUG][file_preview.php] File UUID: " . $file_uuid);
log_debug("[DEBUG][file_preview.php] File MIME: " . $file['mime']);
log_debug("[DEBUG][file_preview.php] File extension: " . $ext);
log_debug("[DEBUG][file_preview.php] is_xlsx (by extension): " . ($is_xlsx ? "TRUE" : "FALSE"));
log_debug("[DEBUG][file_preview.php] is_pdf: " . ($is_pdf ? "TRUE" : "FALSE"));
log_debug("[DEBUG][file_preview.php] is_image: " . ($is_image ? "TRUE" : "FALSE"));
log_debug("[DEBUG][file_preview.php] is_docx: " . ($is_docx ? "TRUE" : "FALSE"));
log_debug("[DEBUG][file_preview.php] is_html: " . ($is_html ? "TRUE" : "FALSE"));

$file_icon = get_file_icon($file['mime'], $file['orig_name']);
// ==================== BLOCK END: File type detection v5.3 ====================

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Просмотр: <?= htmlspecialchars($file['orig_name']) ?> - msgql</title>
    <script nonce="<?= CSP_NONCE ?>">window.APP_BASE = '<?= $appBase ?>'</script>

    <!-- ПОДКЛЮЧАЕМ SHEETJS ДЛЯ ВСЕХ ФАЙЛОВ .XLSX (ПРИНУДИТЕЛЬНО) -->
    <?php if ($is_xlsx): ?>
    <script src="<?= $appBase ?>/js/xlsx.full.min.js" nonce="<?= CSP_NONCE ?>"></script>
    <script nonce="<?= CSP_NONCE ?>">
        logDebug('[DEBUG][EXCEL] ========== SHEETJS SCRIPT LOADED ==========');
        logDebug('[DEBUG][EXCEL] File extension: <?= $ext ?>');
        logDebug('[DEBUG][EXCEL] File name: <?= htmlspecialchars($file['orig_name']) ?>');
        
        window.XLSX_LOADED = false;
        
        function checkXLSX() {
            if (typeof XLSX !== 'undefined') {
                window.XLSX_LOADED = true;
                logDebug('[DEBUG][EXCEL] ✅ XLSX loaded successfully! Version:', XLSX.version);
                var statusSpan = document.getElementById('xlsx-status');
                if (statusSpan) {
                    statusSpan.innerHTML = '✅ ДА (v' + XLSX.version + ')';
                    statusSpan.style.color = '#10b981';
                }
                return true;
            } else {
                logDebug('[DEBUG][EXCEL] ⏳ XLSX not loaded yet, waiting...');
                return false;
            }
        }
        
        checkXLSX();
        
        var xlsxCheckInterval = setInterval(function() {
            if (checkXLSX()) {
                clearInterval(xlsxCheckInterval);
                logDebug('[DEBUG][EXCEL] XLSX check interval cleared');
            }
        }, 200);
        
        setTimeout(function() {
            clearInterval(xlsxCheckInterval);
            if (!window.XLSX_LOADED) {
                logError('[DEBUG][EXCEL] ❌ XLSX load timeout after 10 seconds!');
                var statusSpan = document.getElementById('xlsx-status');
                if (statusSpan) {
                    statusSpan.innerHTML = '❌ НЕТ (таймаут)';
                    statusSpan.style.color = '#f87171';
                }
            }
        }, 10000);
    </script>
    <?php endif; ?>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0b1020;
            color: #e9eefc;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .preview-header {
            background: rgba(11, 16, 32, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            z-index: 100;
        }
        .file-info { flex: 1; min-width: 200px; }
        .file-name { font-size: 16px; font-weight: 600; margin-bottom: 4px; word-break: break-word; }
        .file-meta { font-size: 12px; color: rgba(233, 238, 252, 0.6); }
        .preview-actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.05);
            color: #e9eefc;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover { background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.2); }
        .btn-primary { background: #4f7cff; border-color: #4f7cff; }
        .btn-primary:hover { background: #6b92ff; }
        .preview-area {
            flex: 1;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #050810;
            position: relative;
        }
        .image-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            cursor: grab;
            overflow: hidden;
        }
        .image-container:active { cursor: grabbing; }
        .preview-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: transform 0.05s ease-out;
            transform-origin: center center;
        }
        .file-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            background: #1a1a2e;
        }
        .file-placeholder {
            text-align: center;
            padding: 40px;
        }
        .file-placeholder .icon { font-size: 80px; margin-bottom: 20px; }
        .file-placeholder h3 { margin-bottom: 16px; color: #e9eefc; word-break: break-word; max-width: 80vw; }
        .file-placeholder p { margin-bottom: 24px; color: rgba(233, 238, 252, 0.7); }
        .debug-info {
            font-size: 11px;
            color: #f59e0b;
            background: #1a1a2e;
            padding: 6px 12px;
            border-radius: 6px;
            margin-top: 12px;
            font-family: monospace;
        }
        .links-section {
            padding: 8px 20px;
            background: rgba(11, 16, 32, 0.8);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            font-size: 12px;
        }
        .links-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .links-label { color: rgba(233, 238, 252, 0.6); font-size: 11px; }
        .relation-link {
            background: rgba(79, 124, 255, 0.15);
            padding: 4px 12px;
            border-radius: 16px;
            color: #9bb7ff;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .relation-link:hover { background: rgba(79, 124, 255, 0.3); }
        .zoom-controls {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
            background: rgba(11, 16, 32, 0.9);
            backdrop-filter: blur(10px);
            padding: 8px 12px;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 200;
        }
        .zoom-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .zoom-btn:hover { background: rgba(255, 255, 255, 0.2); }
        .zoom-level {
            font-size: 13px;
            padding: 0 12px;
            display: flex;
            align-items: center;
            color: rgba(233, 238, 252, 0.8);
        }
        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(20px); }
            10% { opacity: 1; transform: translateY(0); }
            90% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(20px); }
        }
        @media (max-width: 768px) {
            .zoom-btn { width: 44px; height: 44px; font-size: 20px; }
            .zoom-level { font-size: 12px; min-width: 50px; text-align: center; }
            .zoom-controls { bottom: 10px; right: 10px; padding: 4px 8px; }
            .file-placeholder .icon { font-size: 60px; }
            .file-placeholder h3 { font-size: 16px; }
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <div class="file-info">
            <div class="file-name" style="color: black !important;"><?= htmlspecialchars($file['orig_name']) ?></div>
            <div class="file-meta" style="color: #475569;"><?= format_file_size($file['size_bytes']) ?> • 
                Загрузил: <?= htmlspecialchars($file['uploader_name'] ?: 'Неизвестно') ?> •
                <?= htmlspecialchars($file['stamp']) ?>
            </div>
        </div>
        <div class="preview-actions">
            <button class="btn btn-link" onclick="copyFileLink()">🔗 Копировать ссылку на файл</button>
            <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&orig_name=<?= urlencode($file['orig_name']) ?>" class="btn btn-primary" download="<?= htmlspecialchars($file['orig_name']) ?>">📥 Скачать</a>
            <button class="btn" onclick="closeWindow()">✕ Закрыть</button>
        </div>
    </div>
    
    <?php if (!empty($file_tasks) || !empty($file_messages)): ?>
    <div class="links-section">
        <?php if (!empty($file_tasks)): ?>
        <div class="links-group">
            <span class="links-label">📋 Задачи:</span>
            <?php foreach ($file_tasks as $task): ?>
            <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($task['uuid']) ?>" class="relation-link">
                📋 <?= htmlspecialchars(mb_substr($task['title'], 0, 30)) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($file_messages)): ?>
        <div class="links-group">
            <span class="links-label">💬 Сообщения:</span>
            <?php foreach ($file_messages as $msg): ?>
            <a href="<?= $appBase ?>/messages.php?task=<?= urlencode($msg['task_uuid']) ?>&message=<?= urlencode($msg['uuid']) ?>" class="relation-link">
                💬 <?= htmlspecialchars(mb_substr($msg['task_title'], 0, 30)) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="preview-area" id="previewArea">
        <?php if ($is_image): ?>
            <div class="image-container" id="imageContainer">
                <img id="previewImage" class="preview-image" src="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&preview=1" alt="<?= htmlspecialchars($file['orig_name']) ?>">
            </div>
        <?php elseif ($is_pdf): ?>
            <div class="file-container" id="fileContainer">
                <div class="file-placeholder" id="filePlaceholder">
                    <div class="icon">📄</div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <button class="btn btn-primary" onclick="openPdfInModal()">🔍 Открыть PDF в просмотрщике</button>
                </div>
            </div>
        <?php elseif ($is_docx): ?>
            <div class="file-container" id="fileContainer">
                <div class="file-placeholder" id="filePlaceholder">
                    <div class="icon">📝</div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <p style="font-size:12px; opacity:0.6;">Word документ (.docx)</p>
                    <div id="docx-debug-info" class="debug-info">
                        MIME: <?= htmlspecialchars($file['mime']) ?> | Ext: <?= htmlspecialchars($ext) ?>
                    </div>
                    <button class="btn btn-primary" onclick="openDocxInModal()">📝 Открыть DOCX в просмотрщике</button>
                </div>
            </div>
        <?php elseif ($is_xlsx): ?>
            <div class="file-container" id="fileContainer">
                <div class="file-placeholder" id="filePlaceholder">
                    <div class="icon">📊</div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <p style="font-size:12px; opacity:0.6;">Excel таблица</p>
                    <div id="excel-debug-info" class="debug-info">
                        MIME: <?= htmlspecialchars($file['mime']) ?> | Ext: <?= htmlspecialchars($ext) ?> | XLSX loaded: <span id="xlsx-status">⏳ проверка...</span>
                    </div>
                    <button class="btn btn-primary" onclick="openExcelInModal()">📊 Открыть Excel в просмотрщике</button>
                </div>
            </div>

        <?php elseif ($is_zip): ?>
            <div class="file-container" id="fileContainer">
                <div class="file-placeholder" id="filePlaceholder">
                    <div class="icon">📦</div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <p style="font-size:12px; opacity:0.6;">ZIP архив</p>
                    <div class="debug-info">
                        MIME: <?= htmlspecialchars($file['mime']) ?> | Ext: <?= htmlspecialchars($ext) ?>
                    </div>
                    <button class="btn btn-primary" onclick="openZipInModal()">📦 Открыть ZIP в просмотрщике</button>
                </div>
            </div>
        <?php elseif ($is_audio): ?>
            <div class="file-container" id="fileContainer">
                <div class="file-placeholder" id="filePlaceholder">
                    <div class="icon">🎵</div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <p style="font-size:12px; opacity:0.6;">Аудио файл</p>
                    <audio controls style="width:100%; max-width:500px; margin-top:20px;">
                        <source src="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&preview=1" type="<?= htmlspecialchars($file['mime']) ?>">
                        Ваш браузер не поддерживает аудио
                    </audio>
                    <div style="margin-top: 16px;">
                        <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>" class="btn btn-primary">📥 Скачать</a>
                    </div>
                </div>
            </div>
        <?php elseif ($is_video): ?>
            <div class="file-container" id="fileContainer" style="background:#000;">
                <video controls style="width:100%; height:100%; object-fit:contain;">
                    <source src="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&preview=1" type="<?= htmlspecialchars($file['mime']) ?>">
                    Ваш браузер не поддерживает видео
                </video>
                <div style="position:absolute; bottom:20px; right:20px; background:rgba(0,0,0,0.7); padding:8px 16px; border-radius:8px;">
                    <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>" style="color:white; text-decoration:none;">📥 Скачать</a>
                </div>
            </div>

        <?php elseif ($is_html): ?>
            <div class="file-container" id="fileContainer" style="background:#fff; padding:20px; overflow:auto;">
                <div style="max-width:800px; margin:0 auto; background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;">
                        <div>
                            <span style="font-size:14px; font-weight:600; color: #000000 !important;">🌐 <?= htmlspecialchars($file['orig_name']) ?></span>
                            <span style="font-size:12px; color:#64748b; margin-left:12px;"><?= format_file_size($file['size_bytes']) ?></span>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&download=1" class="btn-primary" style="padding:6px 14px; font-size:12px; text-decoration:none; border-radius:6px;">📥 Скачать</a>
                            <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>&inline=1" target="_blank" class="btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none; border-radius:6px; background:#f1f5f9; color:#334155;">🔗 Открыть в новой вкладке</a>
                        </div>
                    </div>
                    
                    <div style="background:#f8fafc; border-radius:8px; padding:16px; font-family:monospace; font-size:13px; white-space:pre-wrap; word-break:break-word; max-height:60vh; overflow:auto;">
                        <div style="color:#475569; margin-bottom:12px; font-size:12px;">📄 Исходный код HTML (первые 100 КБ):</div>
                        <pre id="htmlSourcePreview" style="margin:0; padding:12px; background:#0f172a; border-radius:8px; color:#e2e8f0; overflow-x:auto; font-size:11px;"><?php 
                            // Показываем первые 100 КБ исходного кода
                            $file_path_for_read = $file_path;
                            if (file_exists($file_path_for_read) && is_readable($file_path_for_read)) {
                                $content = file_get_contents($file_path_for_read);
                                $max_len = 102400; // 100 КБ
                                if (strlen($content) > $max_len) {
                                    $content = substr($content, 0, $max_len) . "\n\n... (файл обрезан, показаны первые 100 КБ)";
                                }
                                echo htmlspecialchars($content);
                            } else {
                                echo "Файл недоступен для чтения";
                            }
                        ?></pre>
                    </div>
                    
                    <div style="margin-top:16px; padding:12px; background:#fef3c7; border-radius:8px; font-size:12px; color:#92400e;">
                        ⚠️ <strong>Внимание:</strong> Этот HTML-файл содержит ссылки на внешние ресурсы (<code>user183320.7ci.ru</code>), которые недоступны. 
                        Для корректного отображения скачайте файл и откройте его локально.
                    </div>
                </div>
            </div>


        <?php else: ?>
            <div class="file-container">
                <div class="file-placeholder">
                    <div class="icon"><?= $file_icon ?></div>
                    <h3><?= htmlspecialchars($file['orig_name']) ?></h3>
                    <p>Размер: <?= format_file_size($file['size_bytes']) ?></p>
                    <p style="font-size: 12px; opacity: 0.6;">Тип: <?= htmlspecialchars($file['mime']) ?></p>
                    <a href="<?= $appBase ?>/download.php?file=<?= urlencode($file_uuid) ?>" class="btn btn-primary" style="margin-top: 20px;" download>📥 Скачать файл</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_image): ?>
    <div class="zoom-controls" id="zoomControls">
        <button class="zoom-btn" id="zoomOutBtn" title="Уменьшить">−</button>
        <span class="zoom-level" id="zoomLevel">100%</span>
        <button class="zoom-btn" id="zoomInBtn" title="Увеличить">+</button>
        <button class="zoom-btn" id="resetZoomBtn" title="Сбросить">⟳</button>
    </div>
    <?php endif; ?>

    <script nonce="<?= CSP_NONCE ?>">
        // ========== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ==========
        window.fileUuid = '<?= $file_uuid ?>';
        window.fileName = <?= json_encode($file['orig_name']) ?>;
        window.fileSize = <?= (int)$file['size_bytes'] ?>;
        window.fileMime = '<?= $file['mime'] ?>';
        window.isXlsx = <?= $is_xlsx ? 'true' : 'false' ?>;
        window.isDocx = <?= $is_docx ? 'true' : 'false' ?>;
        
        logDebug('[DEBUG][PAGE] ========== PAGE LOADED ==========');
        logDebug('[DEBUG][PAGE] fileUuid:', window.fileUuid);
        logDebug('[DEBUG][PAGE] fileName:', window.fileName);
        logDebug('[DEBUG][PAGE] fileMime:', window.fileMime);
        logDebug('[DEBUG][PAGE] fileSize:', window.fileSize);
        logDebug('[DEBUG][PAGE] isXlsx:', window.isXlsx);
        logDebug('[DEBUG][PAGE] isDocx:', window.isDocx);
        logDebug('[DEBUG][PAGE] typeof XLSX:', typeof XLSX);
        
        const fileUuid = window.fileUuid;
        const fileName = window.fileName;
        const fileSize = window.fileSize;
        const fileMime = window.fileMime;
        const isImage = <?= $is_image ? 'true' : 'false' ?>;
        const isPdf = <?= $is_pdf ? 'true' : 'false' ?>;
        
        function copyFileLink() {
            const fullUrl = window.location.origin + window.APP_BASE + '/file_preview.php?uuid=' + fileUuid;
            navigator.clipboard.writeText(fullUrl).then(() => {
                showToast('✓ Ссылка на файл скопирована');
            }).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = fullUrl;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showToast('✓ Ссылка на файл скопирована');
            });
        }
        
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.textContent = message;
            let bgColor = '#10b981';
            if (type === 'error') bgColor = '#dc2626';
            else if (type === 'warning') bgColor = '#f59e0b';
            else if (type === 'info') bgColor = '#3b82f6';
            toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + bgColor + '; color:white; padding:10px 20px; border-radius:8px; z-index:10000; font-size:14px; animation:fadeInOut 2s ease;';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 2000);
        }
        
        function openPdfInModal() {
            logDebug('[DEBUG][PAGE] openPdfInModal called');
            if (typeof window.showFilePreview === 'function') {
                window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
            } else {
                logDebug('[DEBUG][PAGE] showFilePreview not ready, waiting...');
                const checkInterval = setInterval(() => {
                    if (typeof window.showFilePreview === 'function') {
                        clearInterval(checkInterval);
                        window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                    }
                }, 100);
                setTimeout(() => clearInterval(checkInterval), 5000);
            }
        }

        function openExcelInModal() {
            logDebug('[DEBUG][PAGE] ========== openExcelInModal called ==========');
            logDebug('[DEBUG][PAGE] fileUuid:', fileUuid);
            logDebug('[DEBUG][PAGE] fileName:', fileName);
            logDebug('[DEBUG][PAGE] fileMime:', fileMime);
            logDebug('[DEBUG][PAGE] typeof XLSX:', typeof XLSX);
            logDebug('[DEBUG][PAGE] window.XLSX_LOADED:', window.XLSX_LOADED);
            
            if (typeof XLSX === 'undefined') {
                logDebug('[DEBUG][PAGE] XLSX not loaded, waiting...');
                showToast('Загрузка библиотеки Excel, пожалуйста, подождите...', 'info');
                
                let waitCount = 0;
                const waitInterval = setInterval(function() {
                    waitCount++;
                    logDebug('[DEBUG][PAGE] Waiting for XLSX... attempt', waitCount);
                    if (typeof XLSX !== 'undefined') {
                        clearInterval(waitInterval);
                        logDebug('[DEBUG][PAGE] XLSX loaded after', waitCount, 'attempts');
                        if (typeof window.showFilePreview === 'function') {
                            window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                        } else {
                            logError('[DEBUG][PAGE] showFilePreview not available after XLSX loaded');
                            showToast('Ошибка: просмотрщик не загружен', 'error');
                        }
                    } else if (waitCount > 50) {
                        clearInterval(waitInterval);
                        logError('[DEBUG][PAGE] XLSX load timeout after 5 seconds');
                        showToast('Не удалось загрузить библиотеку Excel. Обновите страницу.', 'error');
                    }
                }, 100);
                return;
            }
            
            logDebug('[DEBUG][PAGE] XLSX already loaded, calling showFilePreview');
            if (typeof window.showFilePreview === 'function') {
                window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
            } else {
                logDebug('[DEBUG][PAGE] showFilePreview not ready, waiting...');
                const checkInterval = setInterval(function() {
                    if (typeof window.showFilePreview === 'function') {
                        clearInterval(checkInterval);
                        window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                    }
                }, 100);
                setTimeout(function() { clearInterval(checkInterval); }, 5000);
            }
        }
        
        function openDocxInModal() {
            logDebug('[DEBUG][PAGE] ========== openDocxInModal called ==========');
            logDebug('[DEBUG][PAGE] fileUuid:', fileUuid);
            logDebug('[DEBUG][PAGE] fileName:', fileName);
            logDebug('[DEBUG][PAGE] fileMime:', fileMime);
            logDebug('[DEBUG][PAGE] typeof mammoth:', typeof mammoth);
            
            if (typeof mammoth === 'undefined') {
                logDebug('[DEBUG][PAGE] Mammoth not loaded, waiting...');
                showToast('Загрузка библиотеки для просмотра документов, пожалуйста, подождите...', 'info');
                
                let waitCount = 0;
                const waitInterval = setInterval(function() {
                    waitCount++;
                    logDebug('[DEBUG][PAGE] Waiting for mammoth... attempt', waitCount);
                    if (typeof mammoth !== 'undefined') {
                        clearInterval(waitInterval);
                        logDebug('[DEBUG][PAGE] Mammoth loaded after', waitCount, 'attempts');
                        if (typeof window.showFilePreview === 'function') {
                            window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                        } else {
                            logError('[DEBUG][PAGE] showFilePreview not available after mammoth loaded');
                            showToast('Ошибка: просмотрщик не загружен', 'error');
                        }
                    } else if (waitCount > 50) {
                        clearInterval(waitInterval);
                        logError('[DEBUG][PAGE] Mammoth load timeout after 5 seconds');
                        showToast('Не удалось загрузить библиотеку для просмотра документов. Обновите страницу.', 'error');
                    }
                }, 100);
                return;
            }
            
            logDebug('[DEBUG][PAGE] Mammoth already loaded, calling showFilePreview');
            if (typeof window.showFilePreview === 'function') {
                window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
            } else {
                logDebug('[DEBUG][PAGE] showFilePreview not ready, waiting...');
                const checkInterval = setInterval(function() {
                    if (typeof window.showFilePreview === 'function') {
                        clearInterval(checkInterval);
                        window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                    }
                }, 100);
                setTimeout(function() { clearInterval(checkInterval); }, 5000);
            }
        }
        
        <?php if ($is_image): ?>
        // ========== ЗУМ И ПАНОРАМИРОВАНИЕ ДЛЯ ИЗОБРАЖЕНИЙ ==========
        let currentZoom = 1;
        let panX = 0, panY = 0;
        let isDragging = false;
        let startX, startY;
        
        const img = document.getElementById('previewImage');
        const container = document.getElementById('imageContainer');
        const zoomInBtn = document.getElementById('zoomInBtn');
        const zoomOutBtn = document.getElementById('zoomOutBtn');
        const resetZoomBtn = document.getElementById('resetZoomBtn');
        const zoomLevelSpan = document.getElementById('zoomLevel');
        
        function updateTransform() {
            img.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
            if (zoomLevelSpan) zoomLevelSpan.innerText = Math.round(currentZoom * 100) + '%';
        }
        
        function zoomIn() {
            if (currentZoom < 5) {
                currentZoom = Math.min(5, currentZoom + 0.1);
                updateTransform();
            }
        }
        
        function zoomOut() {
            if (currentZoom > 0.25) {
                currentZoom = Math.max(0.25, currentZoom - 0.1);
                updateTransform();
            }
        }
        
        function resetZoom() {
            currentZoom = 1;
            panX = 0;
            panY = 0;
            updateTransform();
        }
        
        if (zoomInBtn) zoomInBtn.addEventListener('click', zoomIn);
        if (zoomOutBtn) zoomOutBtn.addEventListener('click', zoomOut);
        if (resetZoomBtn) resetZoomBtn.addEventListener('click', resetZoom);
        
        function onWheel(e) {
            e.preventDefault();
            e.stopPropagation();
            const delta = e.deltaY > 0 ? -1 : 1;
            let newZoom = currentZoom + (delta * 0.1);
            newZoom = Math.min(5, Math.max(0.25, newZoom));
            if (newZoom !== currentZoom) {
                const rect = img.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;
                const percentX = mouseX / rect.width;
                const percentY = mouseY / rect.height;
                const oldZoom = currentZoom;
                currentZoom = newZoom;
                if (oldZoom !== currentZoom && currentZoom > 1) {
                    const scaleChange = currentZoom / oldZoom;
                    panX = panX * scaleChange + (percentX - 0.5) * rect.width * (scaleChange - 1);
                    panY = panY * scaleChange + (percentY - 0.5) * rect.height * (scaleChange - 1);
                }
                updateTransform();
            }
        }
        
        if (container) {
            container.addEventListener('wheel', onWheel, { passive: false });
        }
        
        if (container) {
            container.addEventListener('mousedown', (e) => {
                if (currentZoom > 1) {
                    isDragging = true;
                    startX = e.clientX - panX;
                    startY = e.clientY - panY;
                    container.style.cursor = 'grabbing';
                    e.preventDefault();
                }
            });
        }
        
        window.addEventListener('mousemove', (e) => {
            if (isDragging) {
                panX = e.clientX - startX;
                panY = e.clientY - startY;
                updateTransform();
            }
        });
        
        window.addEventListener('mouseup', () => {
            isDragging = false;
            if (container) container.style.cursor = 'grab';
        });
        
        let touchDistance = 0;
        let initialTouchZoom = 1;
        
        if (container) {
            container.addEventListener('touchstart', (e) => {
                if (e.touches.length === 2) {
                    touchDistance = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    initialTouchZoom = currentZoom;
                    e.preventDefault();
                } else if (e.touches.length === 1 && currentZoom > 1) {
                    isDragging = true;
                    startX = e.touches[0].clientX - panX;
                    startY = e.touches[0].clientY - panY;
                    e.preventDefault();
                }
            });
            
            container.addEventListener('touchmove', (e) => {
                if (e.touches.length === 2 && touchDistance > 0) {
                    e.preventDefault();
                    const newDistance = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    const scale = newDistance / touchDistance;
                    let newZoom = initialTouchZoom * scale;
                    newZoom = Math.min(5, Math.max(0.25, newZoom));
                    if (newZoom !== currentZoom) {
                        currentZoom = newZoom;
                        updateTransform();
                    }
                } else if (e.touches.length === 1 && isDragging && currentZoom > 1) {
                    e.preventDefault();
                    panX = e.touches[0].clientX - startX;
                    panY = e.touches[0].clientY - startY;
                    updateTransform();
                }
            });
            
            container.addEventListener('touchend', (e) => {
                if (e.touches.length < 2) touchDistance = 0;
                if (e.touches.length === 0) {
                    isDragging = false;
                    if (currentZoom <= 1.05) {
                        currentZoom = 1;
                        panX = 0;
                        panY = 0;
                        updateTransform();
                    }
                }
            });
        }
        
        if (img) img.addEventListener('dblclick', resetZoom);
        <?php endif; ?>
        
        const style = document.createElement('style');
        style.textContent = `@keyframes fadeInOut { 0% { opacity: 0; transform: translateY(20px); } 10% { opacity: 1; transform: translateY(0); } 90% { opacity: 1; transform: translateY(0); } 100% { opacity: 0; transform: translateY(20px); } }`;
        document.head.appendChild(style);

        function closeWindow() {
            window.close();
        }

        function openZipInModal() {
            logDebug('[DEBUG][PAGE] openZipInModal called');
            if (typeof window.showFilePreview === 'function') {
                window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
            } else {
                const checkInterval = setInterval(() => {
                    if (typeof window.showFilePreview === 'function') {
                        clearInterval(checkInterval);
                        window.showFilePreview(fileUuid, fileName, fileSize, fileMime);
                    }
                }, 100);
                setTimeout(() => clearInterval(checkInterval), 5000);
            }
        }
    </script>

    
    <script src="<?= $appBase ?>/js/file_preview.js?v=<?= time() ?>" nonce="<?= CSP_NONCE ?>">
        logDebug('[DEBUG][PAGE] file_preview.js script tag loaded');
    </script>

    <?php if ($is_pdf || $is_xlsx || $is_docx): ?>
        <script nonce="<?= CSP_NONCE ?>">
            
            
            <?php if ($is_docx): ?>
            logDebug('[DEBUG][DOCX] Checking mammoth availability...');
            logDebug('[DEBUG][DOCX] typeof mammoth:', typeof mammoth);
            
            if (typeof mammoth === 'undefined') {
                logDebug('[DEBUG][DOCX] Mammoth not loaded, loading dynamically...');
                var script = document.createElement('script');
                script.src = window.APP_BASE + '/js/mammoth.browser.min.js';
                script.onload = function() {
                    logDebug('[DEBUG][DOCX] Mammoth dynamically loaded');
                    window.MAMMOTH_LOADED = true;
                };
                document.head.appendChild(script);
            } else {
                logDebug('[DEBUG][DOCX] Mammoth already loaded');
                window.MAMMOTH_LOADED = true;
            }
            <?php endif; ?>
            
            logDebug('[DEBUG][PAGE] File analyzer enabled');
        </script>
    <?php endif; ?>

</body>
</html>