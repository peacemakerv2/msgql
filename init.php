<?php
/**
 * init.php - инициализация окружения
 * НЕ ЗАПУСКАЕТ СЕССИЮ! (сессия уже запущена в boot.php)
 * 
 * ВНИМАНИЕ: Все функции (часовые пояса, CSRF, SSE, конвертация ссылок)
 * теперь находятся в lib/auth.php (гибридная версия)!
 */

// ==================== УСТАНАВЛИВАЕМ UTC ДЛЯ ВСЕХ СЕРВЕРНЫХ ОПЕРАЦИЙ ====================
date_default_timezone_set('UTC');

// Настройка отображения ошибок (только для не-AJAX запросов)
if (!defined('AJAX_REQUEST')) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL & ~E_NOTICE);
}

// Получаем уровень JS-логирования из конфига
global $js_debug;
$js_debug_level = isset($js_debug) ? (int)$js_debug : 2;

// Увеличиваем лимиты для PHP (если позволяет хостинг)
ini_set('max_execution_time', 120);
ini_set('memory_limit', '256M');

// Если это AJAX запрос, устанавливаем специальные заголовки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
}

// Подключаем остальные библиотеки
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/upload_security.php';
require_once __DIR__ . '/lib/notification_center.php';

// ==================== BLOCK START: VAPID key for JS v1.0.0 ====================
global $vapid_public_key;
if (!isset($vapid_public_key)) {
    $vapid_public_key = '';
}
// ==================== BLOCK END: VAPID key for JS v1.0.0 ====================


/**
 * Форматирует UTC timestamp в локальное время пользователя
 * (следует той же логике, что и msgql_stamp в db.php)
 */
function msgql_format_user_datetime($utc_ms, $format = 'd.m.Y H:i', $show_tz = true): string {
    if (empty($utc_ms)) return '';
    
    $user_offset_minutes = msgql_user_timezone_offset(); // Из auth.php
    $tz_hours = -$user_offset_minutes / 60;
    
    $local_timestamp = (int)($utc_ms / 1000) + ($tz_hours * 3600);
    $local_time = date($format, $local_timestamp);
    
    if ($show_tz && $tz_hours != 0) {
        $tz_sign = $tz_hours > 0 ? '+' : '';
        $local_time .= " <small style='font-size: 0.9em; opacity: 0.7;'>(UTC{$tz_sign}{$tz_hours})</small>";
    }
    
    return $local_time;
}

// ==================== СОЗДАЁМ ПАПКИ ДЛЯ ЗАГРУЗОК ====================

$dirs = [
    __DIR__ . '/uploads/',
    __DIR__ . '/uploads/messages/',
    __DIR__ . '/uploads/tasks/'
];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

// ========== ОПРЕДЕЛЯЕМ КОНСТАНТЫ ДЛЯ ПУТЕЙ (доступны во всех файлах) ==========
if (!defined('UPLOADS_BASE_DIR')) {
    define('UPLOADS_BASE_DIR', __DIR__ . '/uploads/');
}
if (!defined('MESSAGES_UPLOAD_DIR')) {
    define('MESSAGES_UPLOAD_DIR', __DIR__ . '/uploads/messages/');
}
if (!defined('TASKS_UPLOAD_DIR')) {
    define('TASKS_UPLOAD_DIR', __DIR__ . '/uploads/tasks/');
}

// ==================== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ДЛЯ ШАБЛОНОВ ====================
$is_login = msgql_is_logged_in();        // Из auth.php
$is_admin = $is_login && msgql_is_admin();
$is_manager = $is_login && msgql_is_manager();
$is_controller = $is_login && msgql_is_controller();
$current_user_uuid = $is_login ? msgql_current_user_uuid() : '';
$current_user_login = $is_login ? ($_SESSION['login'] ?? '') : '';

$smuri = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

// Получаем CSRF-токен для использования в шаблонах
$csrf_token = msgql_csrf_get_token();    // Из auth.php

// ==================== ВЫВОДИМ HTML ТОЛЬКО ДЛЯ НЕ-AJAX ЗАПРОСОВ ====================
// Пропускаем вывод хедера для скачивания файлов и API-подобных скриптов
$no_header_scripts = [
    'download.php', 
    'file_preview.php', 
    'sse_server.php', 
    'set_timezone.php',
    'save_push_subscription.php',    // ← ДОБАВИТЬ
    'delete_push_subscription.php'  // ← ДОБАВИТЬ

];
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');

$is_ajax_request = defined('AJAX_REQUEST') || (isset($_POST['ajax_mode']) && $_POST['ajax_mode'] == 1);
$is_download_script = in_array($current_script, $no_header_scripts);


// ========== ГЛОБАЛЬНЫЕ НАСТРОЙКИ ПОЛЬЗОВАТЕЛЯ ==========
// Эти переменные будут доступны во всех шаблонах
global $db;
$global_sound_enabled = 1;
$global_sound_interval_sec = 600;

if ($db && !empty($current_user_uuid)) {
    $stmt = $db->prepare("SELECT sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $global_sound_enabled = (int)($row['sound_enabled'] ?? 1);
        $global_sound_interval_sec = (int)($row['sound_interval_sec'] ?? 600);
    }
    $stmt->close();
}

// Записываем в сессию для быстрого доступа в любом месте
$_SESSION['user_sound_enabled'] = $global_sound_enabled;
$_SESSION['user_sound_interval_sec'] = $global_sound_interval_sec;


// Определяем, является ли текущая страница главной (index.php или корень сайта)
$is_home_page = ($current_script === 'index.php' || $current_script === '');

if (!$is_ajax_request && !$is_download_script) {
    require_once __DIR__ . '/layouts/page_start.php';

    if (!$is_login || $is_home_page) {
        require_once __DIR__ . '/layouts/header_welcome.php';
    }

    if ($is_login && !$is_home_page) {
        require_once __DIR__ . '/layouts/header_internal.php';
    }
}

//общие функции
// ==================== BLOCK START: Unified parser function ====================
// ver.1.0 (2026-06-27) - ЕДИНАЯ ФУНКЦИЯ ДЛЯ ВСЕГО ПРОЕКТА
// - Используется в projects.php и messages.php
// - Ссылки обрабатываются ДО Markdown
// - Символы _ в URL не ломаются

function parseMarkdownToHtml($text) {
    if (empty($text)) return '';
    
    // Сначала обрабатываем ссылки
    $text = preg_replace_callback(
        '/(?:https?:\/\/|ftp:\/\/|ws:\/\/|wss:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|magnet:|skype:|viber:|whatsapp:|signal:)[a-zA-Z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+/i',
        function($matches) {
            $url = $matches[0];
            $lowerUrl = strtolower($url);
            
            if (strpos($lowerUrl, 'javascript:') === 0 || 
                strpos($lowerUrl, 'data:') === 0 || 
                strpos($lowerUrl, 'vbscript:') === 0) {
                return $url;
            }
            
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $isTelegram = (strpos($lowerUrl, 'tg://') === 0 || strpos($lowerUrl, 'telegram://') === 0);
            $linkClass = $isTelegram ? 'external-link telegram-link' : 'external-link';
            $targetAttr = (strpos($lowerUrl, 'mailto:') === 0 || strpos($lowerUrl, 'tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            
            $displayText = $url;
            if (strlen($displayText) > 80) {
                $displayText = substr($displayText, 0, 70) . '…' . substr($displayText, -10);
            }
            
            return '<a href="' . $safeUrl . '" class="' . $linkClass . '"' . $targetAttr . '>' . $displayText . '</a>';
        },
        $text
    );
    
    // Затем применяем Markdown
    $processed = $text;
    
    // Блоки кода
    $processed = preg_replace_callback('/```([a-z]*)\n(.*?)\n```/s', function($matches) {
        $lang = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
        $class = $lang ? ' class="language-' . $lang . '"' : '';
        return '<pre><code' . $class . '>' . $code . '</code></pre>';
    }, $processed);
    
    // Инлайн-код
    $processed = preg_replace_callback('/`([^`]+)`/', function($matches) {
        return '<code>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</code>';
    }, $processed);
    
    // Ссылки [текст](url)
    $processed = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($matches) {
        $linkText = $matches[1];
        $url = $matches[2];
        $lowerUrl = strtolower($url);
        
        if (strpos($lowerUrl, 'javascript:') === 0 || 
            strpos($lowerUrl, 'data:') === 0 || 
            strpos($lowerUrl, 'vbscript:') === 0) {
            return $linkText;
        }
        
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeText = htmlspecialchars($linkText, ENT_QUOTES, 'UTF-8');
        $targetAttr = (strpos($lowerUrl, 'mailto:') === 0 || strpos($lowerUrl, 'tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
        return '<a href="' . $safeUrl . '"' . $targetAttr . '>' . $safeText . '</a>';
    }, $processed);
    
    // Жирный, курсив, зачеркивание
    $processed = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $processed);
    $processed = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $processed);
    $processed = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $processed);
    // ВАЖНО: НЕ парсим _текст_ в <em>, т.к. это ломает ссылки!
    // $processed = preg_replace('/_(.+?)_/', '<em>$1</em>', $processed);
    $processed = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $processed);
    
    // Заголовки
    $processed = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $processed);
    $processed = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $processed);
    $processed = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $processed);
    
    $processed = preg_replace('/###\s+([^\n<]+)/', '<h3>$1</h3>', $processed);
    $processed = preg_replace('/##\s+([^\n<]+)/', '<h2>$1</h2>', $processed);
    $processed = preg_replace('/#\s+([^\n<]+)/', '<h1>$1</h1>', $processed);
    
    // Списки
    $processed = preg_replace('/^- (.+)$/m', '<li>$1</li>', $processed);
    $processed = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $processed);
    $processed = preg_replace('/((?:<li>.*<\/li>\s*)+)/', '<ul>$1</ul>', $processed);
    $processed = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $processed);
    $processed = preg_replace('/((?:<li>.*<\/li>\s*)+)/', '<ol>$1</ol>', $processed);
    
    // Разделители и цитаты
    $processed = preg_replace('/^(---|\*\*\*)$/m', '<hr>', $processed);
    $processed = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $processed);
    
    // Преобразование переносов
    $lines = explode("\n", $processed);
    $finalResult = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*<[a-z]/', $line) || preg_match('/^\s*<\/[a-z]/', $line)) {
            $finalResult[] = $line;
        } else {
            $finalResult[] = $line . '<br>';
        }
    }
    $processed = implode('', $finalResult);
    
    return $processed;
}

// Алиас для обратной совместимости
if (!function_exists('parseDescriptionLinks')) {
    function parseDescriptionLinks($text) {
        return parseMarkdownToHtml($text);
    }
}
// ==================== BLOCK END: Unified parser function ====================