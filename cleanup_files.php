<?php
// cleanup_files.php - Очистка мертвых файлов

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// Только для админов
if (!msgql_is_logged_in() || !msgql_is_admin()) {
    die("Доступ запрещен");
}

$db = msgql_db();
$deletedCount = 0;
$totalSize = 0;
$errors = [];

echo "<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <title>Очистка мертвых файлов</title>
    <style>
        body { background: #0b1020; font-family: system-ui, sans-serif; color: #e9eefc; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #121a33; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.08); }
        h1 { margin-bottom: 20px; }
        h2, h3 { margin-bottom: 15px; margin-top: 20px; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        .warning { color: #f59e0b; }
        .file-item { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); font-family: monospace; font-size: 12px; }
        .stats { font-size: 16px; margin: 10px 0; }
        button { background: #4f7cff; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin: 5px; }
        button:hover { background: #3b66e0; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-warning { background: #f59e0b; color: #0b1020; }
        .btn-warning:hover { background: #d97706; }
        pre { background: #0b1020; padding: 10px; border-radius: 8px; overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); }
        th { color: rgba(233,238,252,0.7); font-weight: 500; background: rgba(79,124,255,0.1); }
        .files-table { margin-top: 10px; }
        .files-table td { font-family: monospace; font-size: 12px; word-break: break-all; }
        .files-table tr:hover { background: rgba(79,124,255,0.05); }
    </style>
</head>
<body>
<div class='container'>
    <div class='card'>
        <h1>🧹 Очистка мертвых файлов</h1>
        <p>Файлы, которые есть на диске, но не привязаны ни к одной задаче или сообщению.</p>";

$mode = $_GET['mode'] ?? 'dry_run';
$confirm = $_GET['confirm'] ?? '';
$delete_asana = isset($_GET['delete_asana']) && $_GET['delete_asana'] === 'yes';

// ⚠️ ВАЖНО: Удаляем ТОЛЬКО из подпапок messages/ и tasks/
// UPLOADS_BASE_DIR (корень) НЕ ТРОГАЕМ!
$directories = [
    ['const' => 'MESSAGES_UPLOAD_DIR', 'name' => 'messages', 'path' => MESSAGES_UPLOAD_DIR],
    ['const' => 'TASKS_UPLOAD_DIR', 'name' => 'tasks', 'path' => TASKS_UPLOAD_DIR]
];

// Функция для получения всех файлов из директории
function getFilesFromDir($dirPath, $dirName) {
    $files = [];
    if (!file_exists($dirPath)) return $files;
    
    $items = scandir($dirPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullPath = $dirPath . $item;
        if (!file_exists($fullPath)) continue;
        
        $fileSize = @filesize($fullPath);
        $files[] = [
            'name' => $item,
            'path' => $fullPath,
            'size' => ($fileSize !== false) ? $fileSize : 0,
            'dir' => $dirName,
            'rel_path' => $dirName . '/' . $item
        ];
    }
    return $files;
}

// Удаление всех asana_* и msg_* файлов (только из подпапок)
if ($delete_asana && $mode === 'delete') {
    echo "<div class='card'><h2>🗑️ Удаление всех asana_* и msg_* файлов...</h2>";
    echo "<p class='warning'>⚠️ Удаление производится ТОЛЬКО из папок messages/ и tasks/</p>";
    
    $asanaFilesCount = 0;
    $asanaFilesSize = 0;
    
    foreach ($directories as $dir) {
        $files = getFilesFromDir($dir['path'], $dir['name']);
        foreach ($files as $file) {
            if (strpos($file['name'], 'asana_') === 0 || strpos($file['name'], 'msg_') === 0) {
                if (unlink($file['path'])) {
                    $asanaFilesCount++;
                    $asanaFilesSize += $file['size'];
                    echo "<div class='file-item success'>✅ Удален: {$file['rel_path']} (" . formatBytes($file['size']) . ")</div>";
                } else {
                    echo "<div class='file-item error'>❌ Не удалось удалить: {$file['rel_path']}</div>";
                }
            }
        }
    }
    
    echo "<div class='stats'>";
    echo "<p class='success'>📊 Итого удалено: {$asanaFilesCount} файлов (" . formatBytes($asanaFilesSize) . ")</p>";
    echo "</div>";
    
// Удаление мертвых файлов (без связей в БД)
} elseif ($mode === 'delete' && $confirm === 'yes') {
    echo "<div class='card'><h2>🗑️ Удаление мертвых файлов...</h2>";
    
    // 1. Получаем все файлы из БД
    $dbFiles = [];
    $result = $db->query("SELECT uuid, storage_name, size_bytes FROM files");
    while ($row = $result->fetch_assoc()) {
        $dbFiles[$row['uuid']] = ['storage_name' => $row['storage_name'], 'size_bytes' => $row['size_bytes']];
    }
    
    // 2. Получаем используемые UUID
    $usedInTasks = [];
    $result = $db->query("SELECT DISTINCT file_uuid FROM task_files");
    while ($row = $result->fetch_assoc()) {
        $usedInTasks[$row['file_uuid']] = true;
    }
    
    $usedInMessages = [];
    $result = $db->query("SELECT DISTINCT file_uuid FROM message_files");
    while ($row = $result->fetch_assoc()) {
        $usedInMessages[$row['file_uuid']] = true;
    }
    
    // 3. Удаляем сиротские записи из БД
    $orphanedRecords = [];
    foreach ($dbFiles as $uuid => $info) {
        if (!isset($usedInTasks[$uuid]) && !isset($usedInMessages[$uuid])) {
            $orphanedRecords[] = $uuid;
            $stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
            $stmt->bind_param("s", $uuid);
            if ($stmt->execute()) {
                $deletedCount++;
                $totalSize += $info['size_bytes'];
                echo "<div class='file-item success'>✅ Удалена запись: {$info['storage_name']}</div>";
            } else {
                $errors[] = "Не удалось удалить запись: {$info['storage_name']}";
            }
            $stmt->close();
        }
    }
    
    // 4. Удаляем физические файлы без записей в БД (только из подпапок)
    foreach ($directories as $dir) {
        $files = getFilesFromDir($dir['path'], $dir['name']);
        foreach ($files as $file) {
            $found = false;
            foreach ($dbFiles as $uuid => $info) {
                if ($info['storage_name'] === $file['name']) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                if (unlink($file['path'])) {
                    $deletedCount++;
                    $totalSize += $file['size'];
                    echo "<div class='file-item success'>✅ Удален файл: {$file['rel_path']}</div>";
                } else {
                    $errors[] = "Не удалось удалить файл: {$file['rel_path']}";
                }
            }
        }
    }
    
    echo "<div class='stats'>";
    echo "<p class='success'>📊 Итого удалено: {$deletedCount} записей/файлов (" . formatBytes($totalSize) . ")</p>";
    if (!empty($errors)) {
        echo "<div class='error'><strong>Ошибки:</strong><br>" . implode("<br>", $errors) . "</div>";
    }
    echo "</div>";
    
} else {
    // DRY RUN - только анализ
    echo "<div class='card'>";
    echo "<h2>🔍 Анализ (dry run)</h2>";
    echo "<p class='info'>В этом режиме файлы НЕ удаляются.</p>";
    echo "<p class='info'>✅ Безопасно: удаление производится ТОЛЬКО из подпапок <strong>messages/</strong> и <strong>tasks/</strong>.</p>";
    echo "<p class='info'>❌ Корневая папка uploads/ НЕ трогается.</p>";
    
    // Проверяем доступные директории
    echo "<p>Используемые директории:</p>";
    echo "<ul>";
    foreach ($directories as $dir) {
        $exists = file_exists($dir['path']) ? '✅' : '❌';
        $path = htmlspecialchars($dir['path']);
        echo "<li>{$exists} {$path}</li>";
    }
    echo "</ul>";
    
    // Получаем файлы из БД
    $dbFiles = [];
    $result = $db->query("SELECT uuid, storage_name, size_bytes FROM files");
    while ($row = $result->fetch_assoc()) {
        $dbFiles[$row['uuid']] = ['storage_name' => $row['storage_name'], 'size_bytes' => $row['size_bytes']];
    }
    
    // Получаем используемые UUID
    $usedInTasks = [];
    $result = $db->query("SELECT DISTINCT file_uuid FROM task_files");
    while ($row = $result->fetch_assoc()) {
        $usedInTasks[$row['file_uuid']] = true;
    }
    
    $usedInMessages = [];
    $result = $db->query("SELECT DISTINCT file_uuid FROM message_files");
    while ($row = $result->fetch_assoc()) {
        $usedInMessages[$row['file_uuid']] = true;
    }
    
    // Сиротские записи в БД (записи без связей с задачами/сообщениями)
    $orphanedRecords = [];
    foreach ($dbFiles as $uuid => $info) {
        if (!isset($usedInTasks[$uuid]) && !isset($usedInMessages[$uuid])) {
            $info['uuid'] = $uuid;    
            $orphanedRecords[] = $info;
        }
    }
    
    echo "<h3>📄 Записи в БД без связей (сиротские записи):</h3>";
    if (empty($orphanedRecords)) {
        echo "<p class='success'>✅ Нет сиротских записей</p>";
    } else {
        echo "<table class='files-table'>";
        echo "<thead><tr><th>Имя файла</th><th>Размер</th><th>UUID</th></tr></thead>";
        echo "<tbody>";
        foreach ($orphanedRecords as $record) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($record['storage_name']) . "</td>";
            echo "<td>" . formatBytes($record['size_bytes']) . "</td>";
            echo "<td><code>" . htmlspecialchars($record['uuid']) . "</code></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p class='warning'>📊 Всего сиротских записей: " . count($orphanedRecords) . "</p>";
    }
    
    // Файлы asana_* и msg_*
    $asanaFiles = [];
    $physicalFilesWithoutRecord = [];
    
    foreach ($directories as $dir) {
        $files = getFilesFromDir($dir['path'], $dir['name']);
        foreach ($files as $file) {
            // Собираем импортированные файлы
            if (strpos($file['name'], 'asana_') === 0 || strpos($file['name'], 'msg_') === 0) {
                $asanaFiles[] = $file;
            }
            
            // Проверяем, есть ли запись в БД
            $found = false;
            foreach ($dbFiles as $uuid => $info) {
                if ($info['storage_name'] === $file['name']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $physicalFilesWithoutRecord[] = $file;
            }
        }
    }
    
    echo "<h3>📁 Импортированные файлы (asana_* / msg_*) в папках messages/ и tasks/:</h3>";
    if (empty($asanaFiles)) {
        echo "<p class='success'>✅ Нет импортированных файлов</p>";
    } else {
        echo "<table class='files-table'>";
        echo "<thead><tr><th>Директория</th><th>Имя файла</th><th>Размер</th></tr></thead>";
        echo "<tbody>";
        foreach ($asanaFiles as $file) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($file['dir']) . "</td>";
            echo "<td style='word-break: break-all;'>" . htmlspecialchars($file['name']) . "</td>";
            echo "<td>" . formatBytes($file['size']) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p class='warning'>⚠️ Найдено " . count($asanaFiles) . " импортированных файлов.</p>";
        echo "<p class='info'>💡 Для удаления всех asana_* и msg_* файлов используйте:</p>";
        echo "<pre>?mode=delete&delete_asana=yes</pre>";
    }
    
    echo "<h3>📁 Файлы-призраки (есть на диске, но нет записи в БД):</h3>";
    if (empty($physicalFilesWithoutRecord)) {
        echo "<p class='success'>✅ Нет файлов-призраков</p>";
    } else {
        echo "<table class='files-table'>";
        echo "<thead><tr><th>Директория</th><th>Имя файла</th><th>Размер</th></tr></thead>";
        echo "<tbody>";
        foreach ($physicalFilesWithoutRecord as $file) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($file['dir']) . "</td>";
            echo "<td style='word-break: break-all;'>" . htmlspecialchars($file['name']) . "</td>";
            echo "<td>" . formatBytes($file['size']) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p class='warning'>📊 Всего файлов-призраков: " . count($physicalFilesWithoutRecord) . "</p>";
    }
    
    echo "<div class='stats'>";
    echo "<p class='info'>📊 Всего записей в таблице files: " . count($dbFiles) . "</p>";
    echo "<p class='info'>📊 Используется в задачах: " . count($usedInTasks) . "</p>";
    echo "<p class='info'>📊 Используется в сообщениях: " . count($usedInMessages) . "</p>";
    echo "</div>";
}

echo "
    <div class='flex' style='display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;'>
        <a href='?mode=dry_run'><button>🔄 Обновить анализ</button></a>
        <a href='?mode=delete&confirm=yes' onclick='return confirm(\"ВНИМАНИЕ! Будут удалены файлы без связей из папок messages/ и tasks/. Продолжить?\")'><button class='btn-danger'>🗑️ Удалить мертвые файлы</button></a>
        <a href='?mode=delete&delete_asana=yes' onclick='return confirm(\"ВНИМАНИЕ! Будут удалены ВСЕ файлы asana_* и msg_* из папок messages/ и tasks/. Продолжить?\")'><button class='btn-warning'>🧹 Удалить все asana_* файлы</button></a>
        <a href='admin.php'><button>◀️ Назад в админку</button></a>
    </div>
</div>
</div>
</body>
</html>";

function formatBytes($bytes, $precision = 2) {
    if ($bytes === false || $bytes === 0) return '0 Б';
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}