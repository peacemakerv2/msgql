<?php
// lib/file_analyzer.php version 1.3
// Анализ файлов по сигнатурам (magic bytes) для определения реального формата
// Поддерживает: STL (ASCII/Binary), PDF, ZIP, Office, изображения, аудио, видео,
//              CAD/EDA: Altium, KiCAD, Eagle, AutoCAD, STEP, IGES, Gerber, Drill, ODB++
// v1.1 - Добавлена поддержка CAD/EDA файлов
// v1.2 - Добавлено определение текстовых файлов
// v1.3 - Добавлены Arduino/ESP32 firmware, строгая проверка MP3

class FileAnalyzer {
    
    private static $signatures = [
        // ========== ТЕКСТОВЫЕ ФАЙЛЫ ==========
        'plain_text' => [
            'offset' => 0,
            'pattern' => null,
            'mime' => 'text/plain',
            'ext' => 'txt',
            'name' => 'Text File',
            'category' => 'text',
            'check_callback' => 'checkPlainText'
        ],
        'utf8_bom' => [
            'offset' => 0,
            'pattern' => '/^\xef\xbb\xbf/',
            'mime' => 'text/plain',
            'ext' => 'txt',
            'name' => 'Text File (UTF-8 with BOM)',
            'category' => 'text'
        ],
        'json' => [
            'offset' => 0,
            'pattern' => '/^\s*[{\[]/',
            'mime' => 'application/json',
            'ext' => 'json',
            'name' => 'JSON Data',
            'category' => 'text'
        ],
        'xml' => [
            'offset' => 0,
            'pattern' => '/^<\?xml/',
            'mime' => 'application/xml',
            'ext' => 'xml',
            'name' => 'XML Document',
            'category' => 'text'
        ],
        'html' => [
            'offset' => 0,
            'pattern' => '/^<!DOCTYPE html|^<html/i',
            'mime' => 'text/html',
            'ext' => 'html',
            'name' => 'HTML Document',
            'category' => 'text'
        ],
        'css' => [
            'offset' => 0,
            'pattern' => '/^[\s]*[a-zA-Z_][a-zA-Z0-9_-]*\s*\{/',
            'mime' => 'text/css',
            'ext' => 'css',
            'name' => 'CSS Stylesheet',
            'category' => 'text'
        ],
        'javascript' => [
            'offset' => 0,
            'pattern' => '/^(function|var|let|const|if|for|while|return|class|import|export|console)\s*\(/',
            'mime' => 'text/javascript',
            'ext' => 'js',
            'name' => 'JavaScript File',
            'category' => 'text'
        ],
        'csv' => [
            'offset' => 0,
            'pattern' => '/^[a-zA-Z0-9_\-\.]+\s*[,;]\s*[a-zA-Z0-9_\-\.]/',
            'mime' => 'text/csv',
            'ext' => 'csv',
            'name' => 'CSV Table',
            'category' => 'text'
        ],
        'markdown' => [
            'offset' => 0,
            'pattern' => '/^#+\s+\w|^>\s|^[-*+]\s|^\[.*\]\(.*\)/m',
            'mime' => 'text/markdown',
            'ext' => 'md',
            'name' => 'Markdown Document',
            'category' => 'text'
        ],
        'log' => [
            'offset' => 0,
            'pattern' => '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/',
            'mime' => 'text/plain',
            'ext' => 'log',
            'name' => 'Log File',
            'category' => 'text'
        ],
        'php' => [
            'offset' => 0,
            'pattern' => '/^<\?php/',
            'mime' => 'text/x-php',
            'ext' => 'php',
            'name' => 'PHP Script',
            'category' => 'text'
        ],
        'python' => [
            'offset' => 0,
            'pattern' => '/^(import |from |def |class |if __name__)/',
            'mime' => 'text/x-python',
            'ext' => 'py',
            'name' => 'Python Script',
            'category' => 'text'
        ],
        'shell' => [
            'offset' => 0,
            'pattern' => '/^#!\/bin\/(bash|sh)/',
            'mime' => 'text/x-shellscript',
            'ext' => 'sh',
            'name' => 'Shell Script',
            'category' => 'text'
        ],
        
        // ========== ARDUINO/ESP32 FIRMWARE ==========
        'arduino_firmware' => [
            'offset' => 0,
            'pattern' => null,
            'mime' => 'application/x-arduino-firmware',
            'ext' => 'bin',
            'name' => 'Arduino/ESP32 Firmware Binary',
            'category' => 'firmware',
            'check_callback' => 'checkArduinoFirmware'
        ],
        
        // ========== 3D МОДЕЛИ / CAD ==========
        'stl_ascii' => [
            'offset' => 0,
            'pattern' => '/^solid\s/i',
            'mime' => 'model/stl',
            'ext' => 'stl',
            'name' => 'STL (ASCII)',
            'category' => 'cad_3d'
        ],
        'stl_binary' => [
            'offset' => 0,
            'pattern' => null,
            'mime' => 'model/stl',
            'ext' => 'stl',
            'name' => 'STL (Binary)',
            'category' => 'cad_3d',
            'check_callback' => 'checkBinaryStlStrict'
        ],
        
        'step' => [
            'offset' => 0,
            'pattern' => '/^ISO-10303-21/i',
            'mime' => 'model/step',
            'ext' => 'step',
            'name' => 'STEP 3D Model',
            'category' => 'cad_3d',
            'alt_exts' => ['stp', 'stpnc', 'step']
        ],
        
        'iges' => [
            'offset' => 0,
            'pattern' => '/^\s*S\s*\d+/',
            'mime' => 'model/iges',
            'ext' => 'iges',
            'name' => 'IGES CAD Model',
            'category' => 'cad_3d',
            'alt_exts' => ['igs']
        ],
        
        // ========== ПЕЧАТНЫЕ ПЛАТЫ (PCB) ==========
        'altium_sch' => [
            'offset' => 0,
            'pattern' => '/^\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1/',
            'mime' => 'application/x-altium-schematic',
            'ext' => 'schdoc',
            'name' => 'Altium Designer Schematic',
            'category' => 'eda_pcb',
            'check_callback' => 'checkAltiumSubtype'
        ],
        'altium_pcb' => [
            'offset' => 0,
            'pattern' => '/^\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1/',
            'mime' => 'application/x-altium-pcb',
            'ext' => 'pcbdoc',
            'name' => 'Altium Designer PCB',
            'category' => 'eda_pcb',
            'check_callback' => 'checkAltiumSubtype'
        ],
        'altium_lib' => [
            'offset' => 0,
            'pattern' => '/^\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1/',
            'mime' => 'application/x-altium-library',
            'ext' => 'schlib',
            'name' => 'Altium Designer Library',
            'category' => 'eda_pcb',
            'check_callback' => 'checkAltiumSubtype'
        ],
        
        'kicad_sch' => [
            'offset' => 0,
            'pattern' => '/^\(kicad_sch/s',
            'mime' => 'application/x-kicad-schematic',
            'ext' => 'kicad_sch',
            'name' => 'KiCAD Schematic',
            'category' => 'eda_pcb'
        ],
        'kicad_pcb' => [
            'offset' => 0,
            'pattern' => '/^\(kicad_pcb/s',
            'mime' => 'application/x-kicad-pcb',
            'ext' => 'kicad_pcb',
            'name' => 'KiCAD PCB',
            'category' => 'eda_pcb'
        ],
        'kicad_pro' => [
            'offset' => 0,
            'pattern' => '/^{/"board/"?:/s',
            'mime' => 'application/x-kicad-project',
            'ext' => 'kicad_pro',
            'name' => 'KiCAD Project',
            'category' => 'eda_pcb'
        ],
        'kicad_old_sch' => [
            'offset' => 0,
            'pattern' => '/^EESchema/u',
            'mime' => 'application/x-kicad-schematic-legacy',
            'ext' => 'sch',
            'name' => 'KiCAD Schematic (legacy)',
            'category' => 'eda_pcb'
        ],
        'kicad_old_brd' => [
            'offset' => 0,
            'pattern' => '/^PCBNEW/u',
            'mime' => 'application/x-kicad-pcb-legacy',
            'ext' => 'brd',
            'name' => 'KiCAD PCB (legacy)',
            'category' => 'eda_pcb'
        ],
        
        'eagle_sch' => [
            'offset' => 0,
            'pattern' => '/^<\?xml.*<eagle/s',
            'mime' => 'application/x-eagle-schematic',
            'ext' => 'sch',
            'name' => 'Eagle Schematic',
            'category' => 'eda_pcb'
        ],
        'eagle_brd' => [
            'offset' => 0,
            'pattern' => '/^<\?xml.*<eagle/s',
            'mime' => 'application/x-eagle-board',
            'ext' => 'brd',
            'name' => 'Eagle Board',
            'category' => 'eda_pcb'
        ],
        
        'gerber' => [
            'offset' => 0,
            'pattern' => '/^G04\s*\*|^%\s*FS|^G01\*|^G54\*/',
            'mime' => 'application/x-gerber',
            'ext' => 'gbr',
            'name' => 'Gerber File',
            'category' => 'eda_manufacturing',
            'alt_exts' => ['ger', 'gbl', 'gbo', 'gbs', 'gtl', 'gto', 'gtp', 'gts']
        ],
        
        'excellon_drill' => [
            'offset' => 0,
            'pattern' => '/^%?\s*M48\s*\n|^;LEADER\s*:\s*\d+/',
            'mime' => 'application/x-excellon-drill',
            'ext' => 'drl',
            'name' => 'Excellon Drill File',
            'category' => 'eda_manufacturing',
            'alt_exts' => ['nc', 'ncd', 'txt']
        ],
        
        'odb_plus' => [
            'offset' => 0,
            'pattern' => '/^PK\x03\x04/',
            'mime' => 'application/x-odb-plus',
            'ext' => 'tgz',
            'name' => 'ODB++ Manufacturing Data',
            'category' => 'eda_manufacturing',
            'check_callback' => 'checkOdbPlusSubtype',
            'alt_exts' => ['tar', 'gz', 'zip']
        ],
        
        // ========== ЧЕРТЕЖИ (CAD) ==========
        'dwg' => [
            'offset' => 0,
            'pattern' => '/^AC(10|12|14|18|21|24|27|30|31|32)/',
            'mime' => 'application/x-autocad-dwg',
            'ext' => 'dwg',
            'name' => 'AutoCAD Drawing',
            'category' => 'cad_drawing'
        ],
        
        'dxf_ascii' => [
            'offset' => 0,
            'pattern' => '/^\s*0\s*\nSECTION\s*\n\s*2\s*\nHEADER/s',
            'mime' => 'application/x-autocad-dxf',
            'ext' => 'dxf',
            'name' => 'AutoCAD DXF (ASCII)',
            'category' => 'cad_drawing'
        ],
        'dxf_binary' => [
            'offset' => 0,
            'pattern' => '/^AutoCAD\s+Binary\s+DXF/s',
            'mime' => 'application/x-autocad-dxf',
            'ext' => 'dxf',
            'name' => 'AutoCAD DXF (Binary)',
            'category' => 'cad_drawing'
        ],
        
        'svg' => [
            'offset' => 0,
            'pattern' => '/^<\?xml.*<svg|^<svg/si',
            'mime' => 'image/svg+xml',
            'ext' => 'svg',
            'name' => 'SVG Vector Graphic',
            'category' => 'cad_drawing'
        ],
        
        // ========== АУДИО (СТРОГАЯ ПРОВЕРКА) ==========
        'mp3' => [
            'offset' => 0,
            'pattern' => null,
            'mime' => 'audio/mpeg',
            'ext' => 'mp3',
            'name' => 'MP3 Audio',
            'category' => 'audio',
            'check_callback' => 'checkMp3Strict'
        ],
        
        // ========== ОСТАЛЬНЫЕ ФОРМАТЫ ==========
        'pdf' => [
            'offset' => 0,
            'pattern' => '/^%PDF-/',
            'mime' => 'application/pdf',
            'ext' => 'pdf',
            'name' => 'PDF Document',
            'category' => 'document'
        ],
        
        'zip' => [
            'offset' => 0,
            'pattern' => '/^PK\x03\x04/',
            'mime' => 'application/zip',
            'ext' => 'zip',
            'name' => 'ZIP Archive',
            'category' => 'archive',
            'subtype_callback' => 'analyzeZipSubtype'
        ],
        
        'rar' => [
            'offset' => 0,
            'pattern' => '/^Rar!\x1a\x07/',
            'mime' => 'application/vnd.rar',
            'ext' => 'rar',
            'name' => 'RAR Archive',
            'category' => 'archive'
        ],
        
        'seven_zip' => [
            'offset' => 0,
            'pattern' => "/^7z\xbc\xaf\x27\x1c/",
            'mime' => 'application/x-7z-compressed',
            'ext' => '7z',
            'name' => '7-Zip Archive',
            'category' => 'archive'
        ],
        
        'png' => [
            'offset' => 0,
            'pattern' => '/^\x89PNG\x0d\x0a\x1a\x0a/',
            'mime' => 'image/png',
            'ext' => 'png',
            'name' => 'PNG Image',
            'category' => 'image'
        ],
        'jpg' => [
            'offset' => 0,
            'pattern' => '/^\xff\xd8\xff/',
            'mime' => 'image/jpeg',
            'ext' => 'jpg',
            'name' => 'JPEG Image',
            'category' => 'image'
        ],
        'gif' => [
            'offset' => 0,
            'pattern' => '/^GIF8[79]a/',
            'mime' => 'image/gif',
            'ext' => 'gif',
            'name' => 'GIF Image',
            'category' => 'image'
        ],
        'webp' => [
            'offset' => 0,
            'pattern' => '/^RIFF....WEBP/',
            'mime' => 'image/webp',
            'ext' => 'webp',
            'name' => 'WebP Image',
            'category' => 'image'
        ],
        'bmp' => [
            'offset' => 0,
            'pattern' => '/^BM/',
            'mime' => 'image/bmp',
            'ext' => 'bmp',
            'name' => 'BMP Image',
            'category' => 'image'
        ],
        
        'mp4' => [
            'offset' => 0,
            'pattern' => '/^....ftypmp4|^....ftypisom/',
            'mime' => 'video/mp4',
            'ext' => 'mp4',
            'name' => 'MP4 Video',
            'category' => 'video'
        ],
        'ogg' => [
            'offset' => 0,
            'pattern' => '/^OggS/',
            'mime' => 'audio/ogg',
            'ext' => 'ogg',
            'name' => 'OGG Container',
            'category' => 'audio'
        ],
        'wav' => [
            'offset' => 0,
            'pattern' => '/^RIFF....WAVE/',
            'mime' => 'audio/wav',
            'ext' => 'wav',
            'name' => 'WAV Audio',
            'category' => 'audio'
        ],
        'flac' => [
            'offset' => 0,
            'pattern' => '/^fLaC/',
            'mime' => 'audio/flac',
            'ext' => 'flac',
            'name' => 'FLAC Audio',
            'category' => 'audio'
        ],
        
        'avi' => [
            'offset' => 0,
            'pattern' => '/^RIFF....AVI /',
            'mime' => 'video/x-msvideo',
            'ext' => 'avi',
            'name' => 'AVI Video',
            'category' => 'video'
        ],
        'mkv' => [
            'offset' => 0,
            'pattern' => '/^\x1a\x45\xdf\xa3/',
            'mime' => 'video/x-matroska',
            'ext' => 'mkv',
            'name' => 'Matroska Video',
            'category' => 'video'
        ],
        'webm' => [
            'offset' => 0,
            'pattern' => '/^\x1a\x45\xdf\xa3/',
            'mime' => 'video/webm',
            'ext' => 'webm',
            'name' => 'WebM Video',
            'category' => 'video'
        ],
        
        'old_doc' => [
            'offset' => 0,
            'pattern' => '/^\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1/',
            'mime' => 'application/msword',
            'ext' => 'doc',
            'name' => 'Microsoft Word (old .doc)',
            'category' => 'document'
        ],
    ];
    
    private $result = [
        'success' => false,
        'mime' => 'application/octet-stream',
        'extension' => '',
        'format_name' => 'Unknown',
        'category' => 'unknown',
        'is_binary' => true,
        'signature_match' => null,
        'details' => [],
        'hex_preview' => '',
        'text_preview' => '',
        'text_content' => null
    ];
    
    public static function analyze($filePath, $previewSize = 1024) {
        $analyzer = new self();
        return $analyzer->doAnalyze($filePath, $previewSize);
    }
    
    public static function analyzeByUuid($fileUuid, $previewSize = 1024) {
        if (!function_exists('msgql_db')) {
            return ['success' => false, 'error' => 'msgql_db not available'];
        }
        
        $db = msgql_db();
        $stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
        $stmt->bind_param("s", $fileUuid);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$file) {
            return ['success' => false, 'error' => 'File not found in database'];
        }
        
        $storageName = $file['storage_name'];
        
        $paths = [];
        if (defined('MESSAGES_UPLOAD_DIR')) $paths[] = MESSAGES_UPLOAD_DIR . $storageName;
        if (defined('TASKS_UPLOAD_DIR')) $paths[] = TASKS_UPLOAD_DIR . $storageName;
        
        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                return self::analyze($path, $previewSize);
            }
        }
        
        return ['success' => false, 'error' => 'File not found on disk'];
    }
    
    private function doAnalyze($filePath, $previewSize) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->result['error'] = 'File not readable';
            $this->logDebug('File not readable: ' . $filePath);
            return $this->result;
        }
        
        $textPreviewSize = min($previewSize, 512 * 1024);
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->result['error'] = 'Cannot open file';
            $this->logDebug('Cannot open file: ' . $filePath);
            return $this->result;
        }
        
        $rawData = fread($handle, $textPreviewSize);
        fclose($handle);
        
        if ($rawData === false || strlen($rawData) === 0) {
            $this->result['error'] = 'Cannot read file content';
            return $this->result;
        }
        
        $fileSize = filesize($filePath);
        $actualReadSize = strlen($rawData);
        
        $this->result['hex_preview'] = $this->generateHexPreview($rawData);
        $this->result['text_preview'] = $this->generateTextPreview($rawData);
        $this->result['file_size'] = $fileSize;
        $this->result['preview_size'] = $actualReadSize;
        $this->result['is_binary'] = $this->isBinaryData($rawData);
        
        if (!$this->result['is_binary']) {
            $this->result['text_content'] = $this->generateTextPreview($rawData, 512 * 1024);
        }
        
        $matched = false;
        
        foreach (self::$signatures as $key => $sig) {
            if ($key === 'plain_text') continue;
            
            if ($this->matchSignature($rawData, $sig)) {
                $this->result['success'] = true;
                $this->result['mime'] = $sig['mime'];
                $this->result['extension'] = $sig['ext'];
                $this->result['format_name'] = $sig['name'];
                $this->result['category'] = $sig['category'] ?? 'unknown';
                $this->result['signature_match'] = $key;
                $this->result['details']['detected_by'] = 'signature';
                
                if (isset($sig['check_callback']) && method_exists($this, $sig['check_callback'])) {
                    $callbackResult = call_user_func([$this, $sig['check_callback']], $rawData, $filePath);
                    if (is_array($callbackResult)) {
                        $this->result['details'] = array_merge($this->result['details'], $callbackResult);
                    }
                }
                
                if (isset($sig['subtype_callback']) && method_exists($this, $sig['subtype_callback'])) {
                    $subtype = call_user_func([$this, $sig['subtype_callback']], $rawData, $filePath);
                    if ($subtype) {
                        $this->result['mime'] = $subtype['mime'] ?? $this->result['mime'];
                        $this->result['format_name'] = $subtype['name'] ?? $this->result['format_name'];
                        $this->result['details']['subtype'] = $subtype;
                    }
                }
                
                $matched = true;
                $this->logDebug('Signature matched: ' . $key . ' -> ' . $this->result['format_name']);
                break;
            }
        }
        
        if (!$matched && !$this->result['is_binary']) {
            $this->result['success'] = true;
            $this->result['category'] = 'text';
            $this->result['details']['detected_by'] = 'text_detection';
            
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $this->result['extension'] = $ext;
            
            $extToType = [
                'txt' => ['name' => 'Text File', 'mime' => 'text/plain'],
                'log' => ['name' => 'Log File', 'mime' => 'text/plain'],
                'md' => ['name' => 'Markdown Document', 'mime' => 'text/markdown'],
                'json' => ['name' => 'JSON Data', 'mime' => 'application/json'],
                'xml' => ['name' => 'XML Document', 'mime' => 'application/xml'],
                'html' => ['name' => 'HTML Document', 'mime' => 'text/html'],
                'css' => ['name' => 'CSS Stylesheet', 'mime' => 'text/css'],
                'js' => ['name' => 'JavaScript File', 'mime' => 'text/javascript'],
                'csv' => ['name' => 'CSV Table', 'mime' => 'text/csv'],
                'php' => ['name' => 'PHP Script', 'mime' => 'text/x-php'],
                'py' => ['name' => 'Python Script', 'mime' => 'text/x-python'],
                'sh' => ['name' => 'Shell Script', 'mime' => 'text/x-shellscript'],
                'yaml' => ['name' => 'YAML Document', 'mime' => 'text/yaml'],
                'yml' => ['name' => 'YAML Document', 'mime' => 'text/yaml'],
            ];
            
            if (isset($extToType[$ext])) {
                $this->result['format_name'] = $extToType[$ext]['name'];
                $this->result['mime'] = $extToType[$ext]['mime'];
            } else {
                $this->result['format_name'] = 'Text File';
                $this->result['mime'] = 'text/plain';
            }
            
            $this->logDebug('Text file detected, extension: ' . $ext . ' -> ' . $this->result['format_name']);
            $matched = true;
        }
        
        if (!$matched) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $this->result['extension'] = $ext;
            $this->result['details']['detected_by'] = 'extension_fallback';
            $this->logDebug('No signature match, using extension fallback: ' . $ext);
            
            $extToInfo = $this->getExtensionFallbackInfo($ext);
            if ($extToInfo) {
                $this->result['mime'] = $extToInfo['mime'];
                $this->result['format_name'] = $extToInfo['name'];
                $this->result['category'] = $extToInfo['category'];
                $this->result['success'] = true;
            }
        }
        
        return $this->result;
    }
    
    private function matchSignature($data, $signature) {
        $offset = $signature['offset'] ?? 0;
        $pattern = $signature['pattern'] ?? null;
        
        if ($pattern === null) {
            return false;
        }
        
        if ($offset > 0) {
            if (strlen($data) <= $offset) {
                return false;
            }
            $data = substr($data, $offset);
        }
        
        return preg_match($pattern, $data) === 1;
    }
    
    private function checkPlainText($data, $filePath) {
        if ($this->isBinaryData($data)) {
            return null;
        }
        
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $textExts = ['txt', 'log', 'md', 'markdown', 'conf', 'cfg', 'ini', 'csv', 'json', 'xml', 'html', 'htm', 'css', 'js', 'php', 'py', 'sh', 'bash', 'yaml', 'yml'];
        
        if (in_array($ext, $textExts)) {
            return ['type' => 'plain_text', 'encoding' => 'UTF-8'];
        }
        
        if (empty($ext) && !$this->isBinaryData($data)) {
            if (strlen($data) >= 84) {
                $triangleCount = @unpack('V', substr($data, 80, 4))[1] ?? 0;
                if ($triangleCount > 0 && $triangleCount < 10000000) {
                    return null;
                }
            }
            return ['type' => 'plain_text', 'encoding' => 'UTF-8'];
        }
        
        return null;
    }
    
    private function checkBinaryStlStrict($data, $filePath) {
        $fileSize = filesize($filePath);
        
        if ($fileSize < 84) {
            return ['note' => 'File too small for binary STL', 'is_stl' => false];
        }
        
        $header = substr($data, 0, 80);
        
        if (strlen($data) < 84) {
            return ['note' => 'Cannot read triangle count', 'is_stl' => false];
        }
        
        $triangleCount = unpack('V', substr($data, 80, 4))[1] ?? 0;
        
        if ($triangleCount < 1 || $triangleCount > 10000000) {
            return ['note' => 'Invalid triangle count: ' . $triangleCount, 'is_stl' => false];
        }
        
        $expectedSize = 84 + ($triangleCount * 50);
        
        if (abs($expectedSize - $fileSize) > 100) {
            return [
                'note' => 'File size mismatch: expected ' . $expectedSize . ', got ' . $fileSize,
                'is_stl' => false
            ];
        }
        
        $textPatterns = ['<', '>', 'html', 'xml', 'json', '{', '[', 'Microsoft', 'Windows', 'arduino'];
        foreach ($textPatterns as $pattern) {
            if (stripos($header, $pattern) !== false) {
                return ['note' => 'Header contains text pattern "' . $pattern . '", not STL', 'is_stl' => false];
            }
        }
        
        if ($triangleCount > 0 && $triangleCount <= 100) {
            $offset = 84;
            for ($i = 0; $i < min($triangleCount, 5); $i++) {
                if ($offset + 50 > strlen($data)) break;
                
                $attr = unpack('v', substr($data, $offset + 48, 2))[1] ?? 1;
                
                if ($attr !== 0) {
                    return ['note' => 'Non-zero attribute byte at triangle ' . ($i+1), 'is_stl' => false];
                }
                
                $offset += 50;
            }
        }
        
        return [
            'triangle_count' => $triangleCount,
            'expected_size' => $expectedSize,
            'actual_size' => $fileSize,
            'format' => 'binary_stl',
            'is_stl' => true
        ];
    }
    
    private function checkArduinoFirmware($data, $filePath) {
        $firmwarePatterns = [
            'arduino-lib-builder',
            'NVS. Cannot init flash mem',
            'OTA starting...',
            'Configuring WDT...',
            'Update successfully completed',
            'v5.3.2-',
            'v5.1.4-'
        ];
        
        $sample = substr($data, 0, 4096);
        $found = [];
        foreach ($firmwarePatterns as $pattern) {
            if (strpos($sample, $pattern) !== false) {
                $found[] = $pattern;
            }
        }
        
        if (count($found) >= 2) {
            return [
                'type' => 'arduino_firmware',
                'patterns_found' => $found,
                'note' => 'ESP32/Arduino firmware binary'
            ];
        }
        
        return null;
    }
    
    private function checkMp3Strict($data, $filePath) {
        if (strlen($data) >= 10 && substr($data, 0, 3) === 'ID3') {
            return ['format' => 'mp3', 'has_id3' => true];
        }
        
        if (strlen($data) >= 2) {
            $firstTwo = unpack('n', substr($data, 0, 2))[1] ?? 0;
            if (($firstTwo & 0xFFE0) === 0xFFE0) {
                $isProtected = ($firstTwo & 0x0001) === 1;
                if (!$isProtected) {
                    $textPatterns = ['arduino', 'lib-builder', 'NVS', 'OTA', 'WDT', 'Inputs', 'json', 'xml', 'html'];
                    $sample = strtolower(substr($data, 0, 200));
                    foreach ($textPatterns as $pattern) {
                        if (strpos($sample, $pattern) !== false) {
                            return null;
                        }
                    }
                    return ['format' => 'mp3', 'frame_sync' => sprintf('0x%04X', $firstTwo)];
                }
            }
        }
        
        return null;
    }
    
    private function checkAltiumSubtype($data, $filePath) {
        $oleContent = $data;
        
        if (strpos($oleContent, 'SchDoc') !== false || strpos($oleContent, 'Schematic') !== false) {
            return ['type' => 'schematic', 'software' => 'Altium Designer'];
        }
        if (strpos($oleContent, 'PcbDoc') !== false || strpos($oleContent, 'PCBDocument') !== false) {
            return ['type' => 'pcb', 'software' => 'Altium Designer'];
        }
        if (strpos($oleContent, 'SchLib') !== false || strpos($oleContent, 'PcbLib') !== false) {
            return ['type' => 'library', 'software' => 'Altium Designer'];
        }
        
        return ['note' => 'Altium OLE file, type undetermined'];
    }
    
    private function checkOdbPlusSubtype($data, $filePath) {
        if (strpos($data, 'steps') !== false || strpos($data, 'matrix') !== false || strpos($data, 'layers') !== false) {
            return ['type' => 'odb_plus', 'note' => 'ODB++ manufacturing data archive'];
        }
        return null;
    }
    
    private function analyzeZipSubtype($data, $filePath) {
        if (strpos($data, 'SchDoc') !== false || strpos($data, 'PcbDoc') !== false || strpos($data, 'Project') !== false) {
            return ['mime' => 'application/x-altium-project', 'name' => 'Altium Designer Project'];
        }
        
        if (strpos($data, 'steps') !== false && strpos($data, 'matrix') !== false) {
            return ['mime' => 'application/x-odb-plus', 'name' => 'ODB++ Manufacturing Package'];
        }
        
        if (strpos($data, 'word/document.xml') !== false) {
            return ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'name' => 'Microsoft Word Document (DOCX)'];
        }
        if (strpos($data, 'xl/workbook.xml') !== false) {
            return ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'name' => 'Microsoft Excel Spreadsheet (XLSX)'];
        }
        if (strpos($data, 'ppt/presentation.xml') !== false) {
            return ['mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'name' => 'Microsoft PowerPoint Presentation (PPTX)'];
        }
        
        return null;
    }
    
    private function getExtensionFallbackInfo($ext) {
        $fallbacks = [
            'txt' => ['mime' => 'text/plain', 'name' => 'Text File', 'category' => 'text'],
            'log' => ['mime' => 'text/plain', 'name' => 'Log File', 'category' => 'text'],
            'md' => ['mime' => 'text/markdown', 'name' => 'Markdown Document', 'category' => 'text'],
            'json' => ['mime' => 'application/json', 'name' => 'JSON Data', 'category' => 'text'],
            'xml' => ['mime' => 'application/xml', 'name' => 'XML Document', 'category' => 'text'],
            'html' => ['mime' => 'text/html', 'name' => 'HTML Document', 'category' => 'text'],
            'css' => ['mime' => 'text/css', 'name' => 'CSS Stylesheet', 'category' => 'text'],
            'js' => ['mime' => 'text/javascript', 'name' => 'JavaScript File', 'category' => 'text'],
            'csv' => ['mime' => 'text/csv', 'name' => 'CSV Table', 'category' => 'text'],
            'php' => ['mime' => 'text/x-php', 'name' => 'PHP Script', 'category' => 'text'],
            'py' => ['mime' => 'text/x-python', 'name' => 'Python Script', 'category' => 'text'],
            'sh' => ['mime' => 'text/x-shellscript', 'name' => 'Shell Script', 'category' => 'text'],
            'yaml' => ['mime' => 'text/yaml', 'name' => 'YAML Document', 'category' => 'text'],
            'yml' => ['mime' => 'text/yaml', 'name' => 'YAML Document', 'category' => 'text'],
            'stl' => ['mime' => 'model/stl', 'name' => 'STL 3D Model', 'category' => 'cad_3d'],
            'step' => ['mime' => 'model/step', 'name' => 'STEP 3D Model', 'category' => 'cad_3d'],
            'dwg' => ['mime' => 'application/x-autocad-dwg', 'name' => 'AutoCAD Drawing', 'category' => 'cad_drawing'],
            'dxf' => ['mime' => 'application/x-autocad-dxf', 'name' => 'AutoCAD DXF', 'category' => 'cad_drawing'],
            'svg' => ['mime' => 'image/svg+xml', 'name' => 'SVG Vector Graphic', 'category' => 'cad_drawing'],
            'schdoc' => ['mime' => 'application/x-altium-schematic', 'name' => 'Altium Schematic', 'category' => 'eda_pcb'],
            'pcbdoc' => ['mime' => 'application/x-altium-pcb', 'name' => 'Altium PCB', 'category' => 'eda_pcb'],
            'kicad_sch' => ['mime' => 'application/x-kicad-schematic', 'name' => 'KiCAD Schematic', 'category' => 'eda_pcb'],
            'kicad_pcb' => ['mime' => 'application/x-kicad-pcb', 'name' => 'KiCAD PCB', 'category' => 'eda_pcb'],
            'gbr' => ['mime' => 'application/x-gerber', 'name' => 'Gerber File', 'category' => 'eda_manufacturing'],
            'drl' => ['mime' => 'application/x-excellon-drill', 'name' => 'Excellon Drill', 'category' => 'eda_manufacturing'],
        ];
        
        return $fallbacks[$ext] ?? null;
    }
    
    private function generateHexPreview($data, $bytesPerLine = 16, $maxLines = 16) {
        $length = strlen($data);
        $result = '';
        $offset = 0;
        $lines = 0;
        
        while ($offset < $length && $lines < $maxLines) {
            $chunk = substr($data, $offset, $bytesPerLine);
            $hex = implode(' ', str_split(bin2hex($chunk), 2));
            $hex = str_pad($hex, $bytesPerLine * 3 - 1, ' ');
            
            $ascii = '';
            for ($i = 0; $i < strlen($chunk); $i++) {
                $ord = ord($chunk[$i]);
                $ascii .= ($ord >= 32 && $ord <= 126) ? $chunk[$i] : '.';
            }
            
            $result .= sprintf("%08x: %-{$bytesPerLine}s  %s\n", $offset, $hex, $ascii);
            $offset += $bytesPerLine;
            $lines++;
        }
        
        if ($offset < $length) {
            $result .= sprintf("... (%d more bytes)\n", $length - $offset);
        }
        
        return $result;
    }
    
    private function generateTextPreview($data, $maxChars = 1024) {
        $text = '';
        $length = min(strlen($data), $maxChars);
        
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($data[$i]);
            if ($ord >= 32 && $ord <= 126) {
                $text .= $data[$i];
            } elseif ($ord == 9 || $ord == 10 || $ord == 13) {
                $text .= $data[$i];
            } else {
                $text .= '·';
            }
        }
        
        if (strlen($data) > $maxChars) {
            $text .= "\n... (truncated)";
        }
        
        return $text;
    }
    
    private function isBinaryData($data) {
        $binaryCount = 0;
        $checkLength = min(strlen($data), 512);
        
        for ($i = 0; $i < $checkLength; $i++) {
            $ord = ord($data[$i]);
            if ($ord === 0) {
                return true;
            }
            if ($ord < 9 || ($ord > 13 && $ord < 32)) {
                $binaryCount++;
            }
        }
        
        $isBinary = ($binaryCount / $checkLength) > 0.3;
        
        if (strpos($data, "\0") !== false) {
            $isBinary = true;
        }
        
        return $isBinary;
    }
    
    private function logDebug($message) {
        if (function_exists('log_debug')) {
            log_debug('[FileAnalyzer] ' . $message);
        } elseif (function_exists('msgql_log')) {
            msgql_log('debug', '[FileAnalyzer] ' . $message);
        }
    }
}