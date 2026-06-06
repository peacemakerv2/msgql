<?php
// lib/upload_security.php version 0.3: Усиленная валидация загружаемых файлов

class UploadSecurity {
    
    // Допустимые MIME-типы по категориям
    private static $allowed_mimes = [
        'image' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 
            'image/svg+xml', 'image/bmp', 'image/tiff'
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv', 'text/markdown'
        ],
        'archive' => [
            'application/zip', 'application/x-zip-compressed',
            'application/x-rar-compressed', 'application/x-7z-compressed',
            'application/x-tar', 'application/gzip'
        ],
        'audio' => [
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/flac',
            'audio/x-m4a', 'audio/aac'
        ],
        'video' => [
            'video/mp4', 'video/mpeg', 'video/ogg', 'video/webm',
            'video/quicktime', 'video/x-msvideo'
        ]
    ];
    
    // Максимальные размеры по категориям (байты)
    private static $max_sizes = [
        'image' => 10 * 1024 * 1024,      // 10 MB
        'document' => 25 * 1024 * 1024,   // 25 MB
        'archive' => 50 * 1024 * 1024,    // 50 MB
        'audio' => 30 * 1024 * 1024,      // 30 MB
        'video' => 100 * 1024 * 1024,     // 100 MB
        'default' => 10 * 1024 * 1024     // 10 MB по умолчанию
    ];
    
    // Максимальное количество файлов за раз
    private static $max_files_per_upload = 10;
    
    /**
     * Определяет категорию файла по MIME-типу
     */
    public static function getFileCategory($mime): string {
        foreach (self::$allowed_mimes as $category => $mimes) {
            if (in_array($mime, $mimes)) {
                return $category;
            }
        }
        return 'default';
    }
    
    /**
     * Проверяет, разрешён ли MIME-тип
     */
    public static function isMimeAllowed($mime): bool {
        foreach (self::$allowed_mimes as $mimes) {
            if (in_array($mime, $mimes)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Проверяет размер файла
     */
    public static function checkFileSize($size, $category = null): bool {
        if ($category === null) {
            $maxSize = self::$max_sizes['default'];
        } else {
            $maxSize = self::$max_sizes[$category] ?? self::$max_sizes['default'];
        }
        return $size <= $maxSize;
    }
    
    /**
     * Получает максимальный размер для категории
     */
    public static function getMaxSizeForCategory($category): int {
        return self::$max_sizes[$category] ?? self::$max_sizes['default'];
    }
    
    /**
     * Генерирует криптостойкое имя файла
     * Формат: [тип]_[дата]_[32 байта hex].[расширение]
     */
    public static function generateSecureFilename($originalName, $prefix = 'file'): string {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $ext = $ext ? '.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) : '';
        
        if (strlen($ext) > 10) $ext = '';
        
        $random = bin2hex(random_bytes(32));
        $date = date('Ymd_His');
        
        $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix);
        if (strlen($prefix) > 20) $prefix = substr($prefix, 0, 20);
        
        return sprintf('%s_%s_%s%s', $prefix, $date, $random, $ext);
    }
    
    /**
     * Проверяет реальный MIME-тип файла через finfo
     */
    public static function getRealMimeType($filePath): string|false {
        if (!function_exists('finfo_open')) {
            return mime_content_type($filePath);
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mime;
    }
    
    /**
     * Дополнительная проверка изображений
     */
    public static function validateImage($filePath, $mime): bool {
        $image = null;
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($filePath);
                }
                break;
            case 'image/bmp':
                $image = @imagecreatefrombmp($filePath);
                break;
        }
        
        if ($image === false) {
            return false;
        }
        
        $content = file_get_contents($filePath, false, null, 0, 4096);
        if (preg_match('/<\?(?:php|=)|<\?xml/i', $content)) {
            return false;
        }
        
        imagedestroy($image);
        return true;
    }
    
    /**
     * Проверяет PDF на наличие вредоносного JavaScript
     */
    public static function validatePdf($filePath): array {
        $content = file_get_contents($filePath, false, null, 0, 1024 * 1024);
        if ($content === false) {
            return ['valid' => true, 'found' => []];
        }
        
        $suspicious = [
            '/JavaScript' => 'JavaScript код (возможно вредоносный)',
            '/JS' => 'JavaScript код (возможно вредоносный)',
            '/Launch' => 'Автоматический запуск программы при открытии PDF',
            '/OpenAction' => 'Автоматическое действие при открытии PDF',
            '/SubmitForm' => 'Отправка данных формы без подтверждения пользователя',
            '/ImportData' => 'Импорт внешних данных',
            '/SetOCGState' => 'Автоматическое переключение слоёв PDF'
        ];
        
        $found = [];
        foreach ($suspicious as $pattern => $description) {
            if (stripos($content, $pattern) !== false) {
                $found[] = $description;
            }
        }
        
        return [
            'valid' => empty($found),
            'found' => $found
        ];
    }
    
    /**
     * Главная функция валидации загруженного файла
     * 
     * @param array $file Элемент из $_FILES
     * @param string $expectedCategory Ожидаемая категория (опционально)
     * @param bool $ignoreSecurity Игнорировать проверки безопасности (для принудительной загрузки)
     * @return array
     */
    public static function validateUploadedFile($file, $expectedCategory = null, $ignoreSecurity = false): array {
        // 1. Проверка на ошибки загрузки
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер (PHP)',
                UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер (форма)',
                UPLOAD_ERR_PARTIAL => 'Файл был загружен частично',
                UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Временная папка не найдена',
                UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла на диск',
                UPLOAD_ERR_EXTENSION => 'Загрузка файла остановлена расширением PHP'
            ];
            return ['valid' => false, 'error' => $errors[$file['error']] ?? 'Неизвестная ошибка', 'mime' => null, 'category' => null];
        }
        
        // 2. Проверка размера
        if ($file['size'] <= 0) {
            return ['valid' => false, 'error' => 'Файл пуст', 'mime' => null, 'category' => null];
        }
        
        // 3. Проверка временного файла
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Подозрительный файл (не загружен через HTTP POST)', 'mime' => null, 'category' => null];
        }
        
        // 4. Получаем РЕАЛЬНЫЙ MIME-тип
        $realMime = self::getRealMimeType($file['tmp_name']);
        if (!$realMime) {
            return ['valid' => false, 'error' => 'Не удалось определить тип файла', 'mime' => null, 'category' => null];
        }
        
        // 5. Проверка MIME по белому списку
        if (!self::isMimeAllowed($realMime)) {
            return ['valid' => false, 'error' => 'Тип файла "' . $realMime . '" запрещён', 'mime' => $realMime, 'category' => null];
        }
        
        $category = self::getFileCategory($realMime);
        
        // 6. Проверка размера по категории
        if (!self::checkFileSize($file['size'], $category)) {
            $maxSize = self::getMaxSizeForCategory($category);
            $maxSizeFormatted = msgql_format_file_size($maxSize);
            return ['valid' => false, 'error' => "Размер файла превышает максимальный для этого типа ({$maxSizeFormatted})", 'mime' => $realMime, 'category' => $category];
        }
        
        // 7. Специфические проверки
        if ($category === 'image') {
            if (!self::validateImage($file['tmp_name'], $realMime)) {
                return ['valid' => false, 'error' => 'Файл не является корректным изображением или содержит вредоносный код', 'mime' => $realMime, 'category' => $category];
            }
        }
        
        // 🔥 КЛЮЧЕВОЕ ИЗМЕНЕНИЕ: проверка PDF только если НЕ игнорируем безопасность
        if ($realMime === 'application/pdf' && !$ignoreSecurity) {
            $pdfCheck = self::validatePdf($file['tmp_name']);
            if (!$pdfCheck['valid']) {
                return [
                    'valid' => false, 
                    'error' => 'PDF-файл содержит потенциально опасный JavaScript', 
                    'mime' => $realMime, 
                    'category' => $category,
                    'security_found' => $pdfCheck['found']
                ];
            }
        }
        
        // 8. Проверка ожидаемой категории
        if ($expectedCategory !== null && $category !== $expectedCategory) {
            return ['valid' => false, 'error' => "Ожидался файл типа '{$expectedCategory}', загружен '{$category}'", 'mime' => $realMime, 'category' => $category];
        }
        
        // 9. Очистка имени файла
        $safeOriginalName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ\s\._\-\(\)\[\]]/u', '_', $file['name']);
        if (strlen($safeOriginalName) > 250) {
            $ext = pathinfo($safeOriginalName, PATHINFO_EXTENSION);
            $safeOriginalName = substr($safeOriginalName, 0, 240) . '.' . $ext;
        }
        
        return [
            'valid' => true,
            'error' => null,
            'mime' => $realMime,
            'category' => $category,
            'safe_name' => $safeOriginalName,
            'size' => $file['size']
        ];
    }
    
    /**
     * Проверка нескольких файлов
     * 
     * @param array $files Массив файлов из $_FILES
     * @param string|null $expectedCategory Ожидаемая категория
     * @param bool $ignoreSecurity Игнорировать проверки безопасности
     * @return array
     */
    public static function validateMultipleFiles($files, $expectedCategory = null, $ignoreSecurity = false): array {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return ['valid' => false, 'error' => 'Неверный формат данных файлов', 'files' => []];
        }
        
        $count = count($files['name']);
        
        if ($count > self::$max_files_per_upload) {
            return ['valid' => false, 'error' => 'Можно загрузить не более ' . self::$max_files_per_upload . ' файлов за раз', 'files' => []];
        }
        
        $results = [];
        $hasError = false;
        $errors = [];
        
        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i])) continue;
            
            $singleFile = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0
            ];
            
            // 🔥 Передаём ignoreSecurity в validateUploadedFile
            $result = self::validateUploadedFile($singleFile, $expectedCategory, $ignoreSecurity);
            $results[] = $result;
            
            if (!$result['valid']) {
                $hasError = true;
                $errors[] = "{$singleFile['name']}: {$result['error']}";
            }
        }
        
        if ($hasError) {
            return ['valid' => false, 'error' => implode('; ', $errors), 'files' => $results];
        }
        
        return ['valid' => true, 'error' => null, 'files' => $results];
    }
}