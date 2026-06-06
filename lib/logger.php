<?php
// lib/logger.php version 0.9 - универсальный логгер с поддержкой уровней debug (0/1/2)
// Поддерживает глобальную переменную $php_debug из config.php
// Добавлено: логирование пользователя, правильный caller, ротация лога 5 МБ
// v0.9 - ИСПРАВЛЕНИЕ V-MED-01: добавлена санация служебных символов (\n, \r, \t) в логах

// Уровни логирования:
// 0 - логирование ОТКЛЮЧЕНО полностью
// 1 - логирование ТОЛЬКО ошибок (error, warning, db_error, fk_error)
// 2 - логирование ВСЕГО (полная отладка: debug, info, sql, ajax)

class Logger {
    
    // Путь к файлу лога
    private static $logFile = null;
    
    // Уровень логирования (0, 1, 2)
    private static $logLevel = 2;
    
    // Флаг, инициализирован ли логгер
    private static $initialized = false;
    
    // Флаг, нужно ли выводить логи в error_log
    private static $useErrorLog = false;
    
    // Максимальный размер лог-файла в байтах (5 МБ)
    private static $maxLogSize = 5242880;
    
    // Включить логирование информации о пользователе
    private static $logUserInfo = true;
    
    // Включить логирование IP адреса
    private static $logUserIp = true;
    
    // Включить показ caller (файл:строка - функция)
    private static $showCaller = true;
    
    /**
     * Инициализация логгера (загружает настройки из config.php)
     */
    public static function init() {
        if (self::$initialized) return;
        
        global $php_debug;
        
        if (isset($php_debug)) {
            $level = (int)$php_debug;
            if ($level >= 0 && $level <= 2) {
                self::$logLevel = $level;
            }
        }
        
        // Устанавливаем путь к файлу лога
        if (self::$logFile === null) {
            self::$logFile = __DIR__ . '/../logs/debug.log';
            $logDir = dirname(self::$logFile);
            if (!file_exists($logDir)) {
                @mkdir($logDir, 0777, true);
            }
        }
        
        self::$initialized = true;
        
        // Логируем инициализацию (только если уровень >= 2)
        if (self::isLevelEnabled(2)) {
            // self::writeDirect('info', "Логгер инициализирован, уровень: {$level}");
        }
    }
    
    /**
     * Установить путь к файлу лога
     */
    public static function setLogFile($path) {
        self::$logFile = $path;
        $logDir = dirname($path);
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0777, true);
        }
    }
    
    /**
     * Установить уровень логирования
     * @param int $level 0 - выключено, 1 - только ошибки, 2 - всё
     */
    public static function setLogLevel($level) {
        $level = (int)$level;
        if ($level >= 0 && $level <= 2) {
            self::$logLevel = $level;
        }
    }
    
    /**
     * Получить текущий уровень логирования
     */
    public static function getLogLevel() {
        return self::$logLevel;
    }
    
    /**
     * Установить максимальный размер лог-файла в байтах
     */
    public static function setMaxLogSize($bytes) {
        self::$maxLogSize = max(102400, (int)$bytes);
    }
    
    /**
     * Включить/отключить логирование информации о пользователе
     */
    public static function setLogUserInfo($enabled) {
        self::$logUserInfo = (bool)$enabled;
    }
    
    /**
     * Включить/отключить вывод caller (файл:строка - функция)
     */
    public static function setShowCaller($enabled) {
        self::$showCaller = (bool)$enabled;
    }
    
    /**
     * Проверка, включён ли указанный уровень логирования
     */
    private static function isLevelEnabled($requiredLevel) {
        if (!self::$initialized) {
            self::init();
        }
        return self::$logLevel >= $requiredLevel;
    }
    
    /**
     * Проверка, включён ли логгинг для указанного уровня сообщения
     */
    private static function isMessageLevelEnabled($level) {
        if (self::$logLevel === 0) return false;
        
        if (self::$logLevel === 1) {
            $errorLevels = ['warning', 'error', 'db_error', 'fk_error'];
            return in_array($level, $errorLevels);
        }
        
        return true;
    }
    
    /**
     * Получить информацию о текущем пользователе
     */
    private static function getUserInfo() {
        if (!self::$logUserInfo) {
            return '';
        }
        
        $info = [];
        
        // Получаем данные из сессии (как в auth.php)
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['user_uuid'])) {
                $info['user_uuid'] = $_SESSION['user_uuid'];
            }
            if (isset($_SESSION['login'])) {
                $info['login'] = $_SESSION['login'];
            }
            if (isset($_SESSION['role'])) {
                $roleNames = ['admin', 'manager', 'controller'];
                $info['role'] = $roleNames[$_SESSION['role']] ?? $_SESSION['role'];
            }
        }
        
        // IP адрес (как в init.php)
        if (self::$logUserIp) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
            $info['ip'] = $ip;
        }
        
        if (empty($info)) {
            return '';
        }
        
        return ' [USER: ' . json_encode($info, JSON_UNESCAPED_UNICODE) . ']';
    }
    
    /**
     * Определение реального места вызова (а не логгера)
     * Возвращает строку вида "file.php:123 - Class::method"
     */
    private static function getRealCaller() {
        if (!self::$showCaller) {
            return '';
        }
        
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        // Методы логгера, которые нужно пропустить
        $loggerMethods = ['debug', 'info', 'warning', 'error', 'sql', 'ajax', 'dbError', 'foreignKeyError', 'write', 'writeDirect'];
        $wrapperFunctions = ['log_debug', 'log_info', 'log_warning', 'log_error', 'log_sql', 'log_ajax', 'log_db_error', 'log_fk_error', 'safe_errorlog'];
        
        // Пропускаем минимум 2 кадра (сам getRealCaller и write)
        $skip = 2;
        
        foreach ($trace as $index => $frame) {
            if ($index < $skip) continue;
            
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            
            // Пропускаем методы самого класса Logger
            if ($class === 'Logger' && in_array($function, $loggerMethods)) {
                continue;
            }
            
            // Пропускаем глобальные функции-обёртки
            if (in_array($function, $wrapperFunctions)) {
                continue;
            }
            
            // Нашли реальный вызов
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'global';
            $class = $frame['class'] ?? '';
            
            // Сокращаем путь к файлу
            $file = basename($file);
            
            $callerStr = $file . ':' . $line;
            if ($class) {
                $callerStr .= ' - ' . $class . '::' . $function;
            } elseif ($function !== 'global' && $function !== '{closure}') {
                $callerStr .= ' - ' . $function;
            }
            
            return $callerStr;
        }
        
        // Если не нашли — возвращаем пустую строку
        return '';
    }
    
    /**
     * Форматирование контекста для вывода
     */
    private static function formatContext($context) {
        if (empty($context)) return '';
        
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($json) > 2000) {
            $json = substr($json, 0, 2000) . '... (truncated)';
        }
        
        // ========== V-MED-01 FIX: Экранируем контекст ==========
        $json = self::escapeForLog($json);
        
        return ' | CONTEXT: ' . $json;
    }
    
    /**
     * ========== V-MED-01 FIX: Экранирование служебных символов ==========
     * Экранирует служебные символы для безопасной записи в лог
     * 
     * @param mixed $string Входная строка или данные
     * @return string Экранированная строка
     */
    private static function escapeForLog($string) {
        if (!is_string($string)) {
            if ($string === null) {
                return 'null';
            }
            if (is_bool($string)) {
                return $string ? 'true' : 'false';
            }
            if (is_array($string) || is_object($string)) {
                $string = json_encode($string, JSON_UNESCAPED_UNICODE);
            } else {
                $string = (string)$string;
            }
        }
        
        // Заменяем управляющие символы на их escape-последовательности
        $search = ["\r\n", "\n\r", "\n", "\r", "\t", "\0", "\x0B"];
        $replace = ['\\r\\n', '\\n\\r', '\\n', '\\r', '\\t', '\\0', '\\x0B'];
        
        // Дополнительная защита: удаляем непечатные символы кроме пробелов
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
        
        return str_replace($search, $replace, $string);
    }
    
    /**
     * Проверить размер лог-файла и выполнить ротацию при превышении
     */
    private static function rotateIfNeeded() {
        if (!self::$logFile || !file_exists(self::$logFile)) {
            return;
        }
        
        $size = @filesize(self::$logFile);
        if ($size === false || $size < self::$maxLogSize) {
            return;
        }
        
        // Сохраняем последние 2 МБ данных
        $keepBytes = min(2097152, self::$maxLogSize / 2);
        
        $handle = @fopen(self::$logFile, 'r');
        if (!$handle) {
            return;
        }
        
        @fseek($handle, -$keepBytes, SEEK_END);
        $content = @fread($handle, $keepBytes);
        @fclose($handle);
        
        if ($content === false || $content === '') {
            $content = '';
        }
        
        // Находим первую целую строку
        $firstNewline = strpos($content, PHP_EOL);
        if ($firstNewline !== false && $firstNewline > 0) {
            $content = substr($content, $firstNewline + 1);
        }
        
        // Записываем обратно с меткой о ротации
        $rotationNote = "# Log rotated at " . date('Y-m-d H:i:s') . " (previous size: " . round($size / 1048576, 2) . " MB)\n";
        @file_put_contents(self::$logFile, $rotationNote . $content, LOCK_EX);
        
        // Логируем факт ротации в error_log (не в файл, чтобы не создавать рекурсию)
        error_log("[Logger] Log file rotated, old size: " . round($size / 1048576, 2) . " MB");
    }
    
    /**
     * Прямая запись в лог (без рекурсии, для внутренних нужд)
     * НЕ вызывает getRealCaller() и rotateIfNeeded()
     */
    private static function writeDirect($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s (T)');
        $userInfo = self::getUserInfo();
        
        // ========== V-MED-01 FIX: Экранируем сообщение ==========
        $message = self::escapeForLog($message);
        $contextStr = self::formatContext($context);
        
        $logMessage = sprintf(
            "[%s] [%s]%s %s%s",
            $timestamp,
            strtoupper($level),
            $userInfo,
            $message,
            $contextStr
        );
        
        $logMessage .= PHP_EOL;
        
        if (self::$logFile) {
            @file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
        
        if (self::$useErrorLog) {
            error_log($logMessage);
        }
    }
    
    /**
     * Основной метод логирования
     */
    private static function write($level, $message, $context = []) {
        // Проверяем, включён ли этот уровень сообщений
        if (!self::isMessageLevelEnabled($level)) {
            return;
        }
        
        // Инициализация при первом вызове
        if (!self::$initialized) {
            self::init();
        }
        
        // Проверяем размер лог-файла и выполняем ротацию
        self::rotateIfNeeded();
        
        $timestamp = date('Y-m-d H:i:s (T)');
        $userInfo = self::getUserInfo();
        
        // Определяем caller
        $caller = '';
        if (self::$showCaller) {
            $caller = self::getRealCaller();
        }
        
        $callerPart = '';
        if (!empty($caller)) {
            $callerPart = ' [' . $caller . ']';
        }
        
        // ========== V-MED-01 FIX: Экранируем сообщение ==========
        $message = self::escapeForLog($message);
        $contextStr = self::formatContext($context);
        
        $logMessage = sprintf(
            "[%s] [%s]%s%s %s%s",
            $timestamp,
            strtoupper($level),
            $userInfo,
            $callerPart,
            $message,
            $contextStr
        );
        
        $logMessage .= PHP_EOL;
        
        // Пишем в файл
        if (self::$logFile) {
            @file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
        
        // Пишем в error_log
        if (self::$useErrorLog) {
            error_log($logMessage);
        }
    }
    
    // ==================== ПУБЛИЧНЫЕ МЕТОДЫ ====================
    
    public static function debug($message, $context = []) {
        self::write('debug', $message, $context);
    }
    
    public static function info($message, $context = []) {
        self::write('info', $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::write('warning', $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::write('error', $message, $context);
    }
    
    public static function sql($sql, $params = []) {
        if (self::isMessageLevelEnabled('sql')) {
            self::write('sql', $sql, ['params' => $params]);
        }
    }
    
    public static function ajax($action, $data = []) {
        if (self::isMessageLevelEnabled('ajax')) {
            self::write('ajax', "Action: {$action}", ['data' => $data]);
        }
    }
    
    public static function dbError($error, $query = null) {
        self::write('db_error', $error, ['query' => $query]);
    }
    
    public static function foreignKeyError($error, $table, $field, $value) {
        // ========== V-MED-01 FIX: Экранируем значение перед логированием ==========
        $safeValue = self::escapeForLog($value);
        self::write('fk_error', "Foreign key constraint failed on {$table}.{$field}", [
            'error' => $error,
            'value' => $safeValue,
            'length' => strlen($value),
            'hex' => bin2hex($value)
        ]);
    }
    
    public static function clear() {
        if (self::$logFile && file_exists(self::$logFile)) {
            @file_put_contents(self::$logFile, '');
            self::info('Лог-файл очищен');
        }
    }
    
    public static function getLog() {
        if (self::$logFile && file_exists(self::$logFile)) {
            return @file_get_contents(self::$logFile);
        }
        return '';
    }
    
    public static function getLogSize() {
        if (self::$logFile && file_exists(self::$logFile)) {
            return @filesize(self::$logFile);
        }
        return 0;
    }
    
    public static function setUseErrorLog($enabled) {
        self::$useErrorLog = (bool)$enabled;
    }
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function log_debug($message, $context = []) { 
    Logger::debug($message, $context); 
}

function log_info($message, $context = []) { 
    Logger::info($message, $context); 
}

function log_warning($message, $context = []) { 
    Logger::warning($message, $context); 
}

function log_error($message, $context = []) { 
    Logger::error($message, $context); 
}

function log_sql($sql, $params = []) { 
    Logger::sql($sql, $params); 
}

function log_ajax($action, $data = []) { 
    Logger::ajax($action, $data); 
}

function log_db_error($error, $query = null) { 
    Logger::dbError($error, $query); 
}

function log_fk_error($error, $table, $field, $value) { 
    Logger::foreignKeyError($error, $table, $field, $value); 
}

/**
 * Умная замена для error_log
 */
function safe_errorlog($message, $level = 'debug', $context = []) {
    switch ($level) {
        case 'error':
            log_error($message, $context);
            break;
        case 'warning':
            log_warning($message, $context);
            break;
        case 'info':
            log_info($message, $context);
            break;
        default:
            log_debug($message, $context);
    }
}