// /js/logger.js version 2.1: универсальный логгер для отладки
// Уровни логирования (JS_DEBUG из config.php):
// 0 - логирование ОТКЛЮЧЕНО полностью
// 1 - логирование ТОЛЬКО ошибок (error, warning)
// 2 - логирование ВСЕГО (полная отладка)
// Добавлена автоматическая ротация лога в localStorage при превышении 5 МБ

(function() {
    'use strict';
    
    window.JS_DEBUG = (typeof window.JS_DEBUG !== 'undefined') ? parseInt(window.JS_DEBUG, 10) : 2;
    window.LOGGER_DISABLED = window.LOGGER_DISABLED || false;
    
    // Максимальный размер лога в localStorage (5 МБ)
    var MAX_LOG_SIZE_BYTES = 5 * 1024 * 1024; // 5 МБ
    
    function isDebugLevelEnabled(level) {
        if (window.LOGGER_DISABLED === true) return false;
        if (localStorage.getItem('logger_disabled') === 'true') return false;
        
        var jsDebug = window.JS_DEBUG;
        if (jsDebug === 0) return false;
        if (jsDebug === 1) return (level === 'error' || level === 'warning');
        if (jsDebug === 2) return true;
        return false;
    }
    
    function getTimestamp() {
        var now = new Date();
        var year = now.getFullYear();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        var hours = String(now.getHours()).padStart(2, '0');
        var minutes = String(now.getMinutes()).padStart(2, '0');
        var seconds = String(now.getSeconds()).padStart(2, '0');
        return year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
    }
    
    function getCaller() {
        try {
            throw new Error();
        } catch(e) {
            var stack = e.stack.split('\n');
            for (var i = 3; i < stack.length && i < 10; i++) {
                var line = stack[i];
                if (line.indexOf('logger.js') === -1 && line.indexOf('at ') !== -1) {
                    var match = line.match(/at\s+(.+?)(?:\s+\(|$)/);
                    if (match && match[1]) return match[1].split('/').pop();
                    var simpleMatch = line.match(/at\s+(?:.*\/)?([^\/\s:]+)(?::\d+)?/);
                    if (simpleMatch && simpleMatch[1]) return simpleMatch[1];
                    return line.substring(0, 50);
                }
            }
        }
        return 'unknown';
    }
    
    // Функция для объединения нескольких аргументов в строку
    function formatMessage(args) {
        var parts = [];
        for (var i = 0; i < args.length; i++) {
            var arg = args[i];
            if (typeof arg === 'object') {
                try {
                    parts.push(JSON.stringify(arg));
                } catch(e) {
                    parts.push(String(arg));
                }
            } else {
                parts.push(String(arg));
            }
        }
        return parts.join(' ');
    }
    
    // Функция для приблизительного подсчёта размера строки в байтах (UTF-16)
    function getStringSizeInBytes(str) {
        return str.length * 2; // В JavaScript строки хранятся в UTF-16, каждый символ ~2 байта
    }
    
    // Сохранение лога в localStorage с ротацией по размеру
    function saveToLocalStorage(logMessage) {
        if (window.JS_DEBUG < 2) return;
        
        try {
            var key = 'debug_logs';
            var oldLogs = localStorage.getItem(key) || '';
            var newLogs = oldLogs ? oldLogs + '\n' + logMessage : logMessage;
            
            // Проверяем размер в байтах
            var currentSize = getStringSizeInBytes(newLogs);
            
            if (currentSize > MAX_LOG_SIZE_BYTES) {
                // Обрезаем: оставляем последние 2 МБ (40% от лимита)
                var keepBytes = Math.floor(MAX_LOG_SIZE_BYTES * 0.4);
                var keepChars = Math.floor(keepBytes / 2); // Переводим в символы UTF-16
                
                if (newLogs.length > keepChars) {
                    var trimmed = newLogs.substring(newLogs.length - keepChars);
                    // Ищем первую целую строку, чтобы не обрезать посередине
                    var firstNewline = trimmed.indexOf('\n');
                    if (firstNewline !== -1 && firstNewline > 0) {
                        trimmed = trimmed.substring(firstNewline + 1);
                    }
                    // Добавляем заголовок о ротации
                    var rotationHeader = '[LOG ROTATED at ' + new Date().toISOString() + '] (exceeded ' + (MAX_LOG_SIZE_BYTES / 1048576).toFixed(1) + ' MB)\n';
                    newLogs = rotationHeader + trimmed;
                } else {
                    // Если не удалось обрезать, просто очищаем
                    newLogs = '[LOG CLEARED at ' + new Date().toISOString() + '] (exceeded size limit)\n' + logMessage;
                }
            }
            
            localStorage.setItem(key, newLogs);
        } catch(e) {
            // Если превышен лимит localStorage (~5-10 МБ) или другая ошибка
            if (e.name === 'QuotaExceededError') {
                try {
                    // Пытаемся очистить половину
                    var existing = localStorage.getItem('debug_logs') || '';
                    var halfLength = Math.floor(existing.length / 2);
                    var halfLog = existing.substring(halfLength);
                    var firstNewline = halfLog.indexOf('\n');
                    if (firstNewline !== -1) {
                        halfLog = halfLog.substring(firstNewline + 1);
                    }
                    localStorage.setItem('debug_logs', '[QUOTA EXCEEDED - HALF CLEARED at ' + new Date().toISOString() + ']\n' + halfLog);
                } catch(e2) {
                    // Если всё плохо - полностью очищаем
                    localStorage.removeItem('debug_logs');
                    console.warn('[LOGGER] localStorage quota exceeded, logs cleared completely');
                }
            } else {
                console.warn('[LOGGER] Failed to save to localStorage:', e);
            }
        }
    }
    
    // Основная функция записи лога
    function writeToConsole(level, args) {
        if (!isDebugLevelEnabled(level)) return;
        
        // Формируем сообщение из всех аргументов
        var message = formatMessage(args);
        
        var timestamp = getTimestamp();
        var caller = getCaller();
        var logMessage = '[' + timestamp + '] [' + level.toUpperCase() + '] [' + caller + '] ' + message;
        
        switch(level) {
            case 'error':
                console.error(logMessage);
                break;
            case 'warning':
                console.warn(logMessage);
                break;
            case 'debug':
                console.log(logMessage);
                break;
            default:
                console.log(logMessage);
        }
        
        // Сохраняем в localStorage с ротацией по размеру
        if (window.JS_DEBUG >= 2) {
            saveToLocalStorage(logMessage);
        }
    }
    
    // Публичные методы с поддержкой нескольких аргументов
    window.logDebug = function() {
        writeToConsole('debug', Array.prototype.slice.call(arguments));
    };
    
    window.logInfo = function() {
        writeToConsole('info', Array.prototype.slice.call(arguments));
    };
    
    window.logWarning = function() {
        writeToConsole('warning', Array.prototype.slice.call(arguments));
    };
    
    window.logError = function() {
        writeToConsole('error', Array.prototype.slice.call(arguments));
    };
    
    // Алиас для совместимости
    window.log_jsconsole = function(level, message, context) {
        var args = [message];
        if (context !== undefined) args.push(context);
        writeToConsole(level, args);
    };
    
    window.logAjax = function(action, data) {
        writeToConsole('ajax', ['Action: ' + action, data]);
    };
    
    window.getLogs = function() {
        return localStorage.getItem('debug_logs') || '';
    };
    
    window.getLogSize = function() {
        var logs = localStorage.getItem('debug_logs') || '';
        return logs.length * 2; // Приблизительный размер в байтах
    };
    
    window.clearLogs = function() {
        localStorage.removeItem('debug_logs');
        if (isDebugLevelEnabled('info')) {
            console.log('[LOGGER] Logs cleared');
        }
    };
    
    window.enableLogging = function() {
        window.LOGGER_DISABLED = false;
        localStorage.setItem('logger_disabled', 'false');
        if (isDebugLevelEnabled('info')) {
            console.log('[LOGGER] Logging ENABLED');
        }
    };
    
    window.disableLogging = function() {
        window.LOGGER_DISABLED = true;
        localStorage.setItem('logger_disabled', 'true');
    };
    
    window.setJsDebugLevel = function(level) {
        var newLevel = parseInt(level, 10);
        if (isNaN(newLevel) || newLevel < 0 || newLevel > 2) {
            console.warn('[LOGGER] Invalid level. Use 0, 1, or 2');
            return false;
        }
        window.JS_DEBUG = newLevel;
        var levelNames = ['ОТКЛЮЧЁН', 'ТОЛЬКО ОШИБКИ', 'ПОЛНЫЙ'];
        console.log('[LOGGER] JS_DEBUG level changed to ' + newLevel + ' (' + levelNames[newLevel] + ')');
        return true;
    };
    
    window.getJsDebugLevel = function() {
        return window.JS_DEBUG;
    };
    
    window.setMaxLogSize = function(megabytes) {
        var size = parseFloat(megabytes);
        if (isNaN(size) || size < 0.1) {
            console.warn('[LOGGER] Invalid size. Use megabytes (min 0.1)');
            return false;
        }
        window.MAX_LOG_SIZE_MB = size;
        console.log('[LOGGER] Max log size set to ' + size + ' MB');
        return true;
    };
    
    if (window.JS_DEBUG >= 2) {
        var levelNames = ['ОТКЛЮЧЁН', 'только ошибки', 'полный'];
        console.log('[LOGGER] Инициализирован, уровень: ' + window.JS_DEBUG + ' (' + levelNames[window.JS_DEBUG] + ')');
        console.log('[LOGGER] Максимальный размер лога: ' + (MAX_LOG_SIZE_BYTES / 1048576) + ' MB');
    } else if (window.JS_DEBUG === 1) {
        console.log('[LOGGER] Инициализирован, уровень: ТОЛЬКО ОШИБКИ');
    }
})();