<?php
// asana_fix_extensions.php - исправление расширений уже загруженных файлов
// Версия 1.1 - исправлено подключение файлов инициализации

// Отключаем вывод ошибок в браузер, но логируем
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/asana_config.php';

$db = msgql_db();

// Получаем все файлы без расширения в storage_name
$stmt = $db->prepare("
    SELECT uuid, storage_name, orig_name, mime 
    FROM files 
    WHERE storage_name NOT LIKE '%.%' AND storage_name LIKE 'asana_%'
");
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "Found " . count($files) . " files without extension\n";

foreach ($files as $file) {
    $oldPath = MESSAGES_UPLOAD_DIR . $file['storage_name'];
    if (!file_exists($oldPath)) {
        echo "File not found: {$file['storage_name']}\n";
        continue;
    }
    
    // Определяем расширение по MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $oldPath);
    finfo_close($finfo);
    
    $extension = '';
    $mimeMap = [
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
        'image/jpg' => '.jpg',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
        'image/bmp' => '.bmp',
        'application/pdf' => '.pdf',
        'application/zip' => '.zip',
        'application/x-rar-compressed' => '.rar',
        'application/x-7z-compressed' => '.7z',
        'text/plain' => '.txt',
        'text/html' => '.html',
        'text/css' => '.css',
        'text/javascript' => '.js',
        'application/json' => '.json',
        'application/xml' => '.xml',
        'video/mp4' => '.mp4',
        'video/x-msvideo' => '.avi',
        'video/mpeg' => '.mpg',
        'audio/mpeg' => '.mp3',
        'audio/wav' => '.wav',
        'application/msword' => '.doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'application/vnd.ms-excel' => '.xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
        'application/vnd.ms-powerpoint' => '.ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
    ];
    
    $extension = $mimeMap[$mime] ?? '';
    
    // Если не нашли по MIME, пробуем по имени файла
    if (empty($extension) && strpos($file['orig_name'], '.') !== false) {
        $extension = substr($file['orig_name'], strrpos($file['orig_name'], '.'));
        echo "Extension from orig_name: {$extension} for {$file['storage_name']}\n";
    }
    
    if (!empty($extension)) {
        $newStorageName = $file['storage_name'] . $extension;
        $newPath = MESSAGES_UPLOAD_DIR . $newStorageName;
        
        // Проверяем, не существует ли уже файл с таким именем
        if (file_exists($newPath)) {
            echo "SKIP: Target file already exists: {$newStorageName}\n";
            continue;
        }
        
        if (rename($oldPath, $newPath)) {
            $updateStmt = $db->prepare("UPDATE files SET storage_name = ? WHERE uuid = ?");
            $updateStmt->bind_param("ss", $newStorageName, $file['uuid']);
            $updateStmt->execute();
            $updateStmt->close();
            
            echo "Fixed: {$file['storage_name']} → {$newStorageName} (MIME: {$mime})\n";
        } else {
            echo "FAILED to rename: {$file['storage_name']}\n";
        }
    } else {
        // Пробуем прочитать первые байты файла для определения типа
        $handle = fopen($oldPath, 'rb');
        $bytes = fread($handle, 12);
        fclose($handle);
        
        // PNG сигнатура: 89 50 4E 47
        if (substr($bytes, 0, 4) === "\x89PNG") {
            $extension = '.png';
        } 
        // JPEG сигнатура: FF D8 FF
        elseif (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            $extension = '.jpg';
        }
        // GIF сигнатура: GIF8
        elseif (substr($bytes, 0, 4) === 'GIF8') {
            $extension = '.gif';
        }
        // PDF сигнатура: %PDF
        elseif (substr($bytes, 0, 4) === '%PDF') {
            $extension = '.pdf';
        }
        // ZIP сигнатура: PK
        elseif (substr($bytes, 0, 2) === 'PK') {
            $extension = '.zip';
        }
        
        if (!empty($extension)) {
            $newStorageName = $file['storage_name'] . $extension;
            $newPath = MESSAGES_UPLOAD_DIR . $newStorageName;
            
            if (file_exists($newPath)) {
                echo "SKIP: Target file already exists: {$newStorageName}\n";
                continue;
            }
            
            if (rename($oldPath, $newPath)) {
                $updateStmt = $db->prepare("UPDATE files SET storage_name = ? WHERE uuid = ?");
                $updateStmt->bind_param("ss", $newStorageName, $file['uuid']);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo "Fixed (by magic bytes): {$file['storage_name']} → {$newStorageName}\n";
            }
        } else {
            echo "UNKNOWN type for: {$file['storage_name']} (MIME: {$mime}, orig: {$file['orig_name']})\n";
        }
    }
}

echo "Done!\n";