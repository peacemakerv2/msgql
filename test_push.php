<?php
// test_push.php - Тестирование Push-уведомлений (МАКСИМАЛЬНОЕ ЛОГИРОВАНИЕ)
// v4.1 (2026-05-29) - ИСПРАВЛЕНО СРАВНЕНИЕ КЛЮЧЕЙ с нормализацией

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// // Только для админов
// if (!msgql_is_logged_in() || !msgql_is_admin()) {
//     die("Доступ запрещён. Только для администраторов.");
// }

// ПРИНУДИТЕЛЬНАЯ ПЕРЕДАЧА VAPID КЛЮЧА В ГЛОБАЛЬНУЮ ОБЛАСТЬ ДЛЯ JS
global $vapid_public_key, $config;
if (empty($vapid_public_key) && isset($config['vapid_public_key'])) {
    $vapid_public_key = $config['vapid_public_key'];
}
if (empty($vapid_private_key) && isset($config['vapid_private_key'])) {
    $vapid_private_key = $config['vapid_private_key'];
}

// ДИАГНОСТИКА VAPID В PHP ЛОГ
//error_log("[VAPID_DEBUG] vapid_public_key from config: " . ($config['vapid_public_key'] ?? 'NOT SET'));
//error_log("[VAPID_DEBUG] vapid_public_key length: " . strlen($config['vapid_public_key'] ?? ''));
//error_log("[VAPID_DEBUG] global \$vapid_public_key: " . ($vapid_public_key ?? 'NOT SET'));

$current_user_uuid = msgql_current_user_uuid();
$test_result = null;
$test_message = '';
$debug_logs = [];

// Функция для добавления логов в отладку
function addDebugLog($message, $data = null) {
    global $debug_logs;
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $entry .= ': ' . json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $entry .= ': ' . $data;
        }
    }
    $debug_logs[] = $entry;
    log_debug("[TEST_PUSH_DEBUG] " . $entry);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_push') {
    addDebugLog("========== НАЧАЛО ТЕСТОВОЙ ОТПРАВКИ ==========");
    
    $target_user = $_POST['user_uuid'] ?? $current_user_uuid;
    $test_message = trim($_POST['test_message'] ?? '');
    
    if (empty($test_message)) {
        $test_message = 'Тестовое уведомление от ' . date('Y-m-d H:i:s');
    }
    
    addDebugLog("Целевой пользователь: {$target_user}");
    addDebugLog("Тестовое сообщение: {$test_message}");
    
    // Проверяем существование целевого пользователя
    $db = msgql_db();
    $stmt = $db->prepare("SELECT uuid, login, name, email FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $target_user);
    $stmt->execute();
    $target_user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($target_user_data) {
        addDebugLog("Целевой пользователь найден: " . ($target_user_data['name'] ?: $target_user_data['login']));
        addDebugLog("Email пользователя: " . ($target_user_data['email'] ?: 'не указан'));
    } else {
        addDebugLog("ОШИБКА: Целевой пользователь не найден!");
    }
    
    // Проверяем наличие push-подписок у пользователя
    $stmt = $db->prepare("SELECT endpoint FROM push_subscriptions WHERE user_uuid = ?");
    $stmt->bind_param("s", $target_user);
    $stmt->execute();
    $subscriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sub_count = count($subscriptions);
    $stmt->close();
    
    addDebugLog("Количество push-подписок у пользователя: {$sub_count}");
    
    if ($sub_count == 0) {
        addDebugLog("ПРЕДУПРЕЖДЕНИЕ: У пользователя нет активных push-подписок!");
        addDebugLog("Убедитесь, что браузер разрешил уведомления и Service Worker зарегистрирован.");
    } else {
        addDebugLog("=== ДЕТАЛИ ПОДПИСОК ===");
        foreach ($subscriptions as $idx => $sub) {
            $endpoint = $sub['endpoint'];
            addDebugLog("  Подписка " . ($idx + 1) . ": " . substr($endpoint, 0, 100) . "...");
            if (strpos($endpoint, 'fcm.googleapis.com') !== false) {
                addDebugLog("    ✅ Тип: FCM (Google Chrome)");
            } elseif (strpos($endpoint, 'wns2') !== false || strpos($endpoint, 'notify.windows.com') !== false) {
                addDebugLog("    ⚠️ Тип: WNS (Windows Edge) - НЕ РАБОТАЕТ на Android!");
            } elseif (strpos($endpoint, 'updates.push.services.mozilla.com') !== false) {
                addDebugLog("    ✅ Тип: Mozilla Autopush (Firefox)");
            } else {
                addDebugLog("    ❓ Тип: неизвестный push-сервис");
            }
        }
    }
    
    addDebugLog("Вызов NotificationCenter::sendTestPush()...");
    
    if (class_exists('NotificationCenter')) {
        $start_time = microtime(true);
        $test_result = NotificationCenter::sendTestPush($target_user, $test_message);
        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time) * 1000, 2);
        
        addDebugLog("Время выполнения: {$execution_time} мс");
        addDebugLog("Результат отправки: " . json_encode($test_result, JSON_UNESCAPED_UNICODE));
        
        if ($test_result['sent']) {
            addDebugLog("✅ ТЕСТОВЫЙ PUSH УСПЕШНО ОТПРАВЛЕН!");
        } else {
            addDebugLog("❌ ОШИБКА ОТПРАВКИ: " . ($test_result['reason'] ?? 'неизвестная причина'));
        }
    } else {
        addDebugLog("ОШИБКА: Класс NotificationCenter не найден!");
        $test_result = ['sent' => false, 'reason' => 'NotificationCenter not found'];
    }
    
    addDebugLog("========== КОНЕЦ ТЕСТОВОЙ ОТПРАВКИ ==========");
}

// Получаем список пользователей для выбора (только администратор)
$db = msgql_db();
$users = [];
$stmt = $db->prepare("SELECT uuid, login, name, email FROM users WHERE status = 0 ORDER BY name, login");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Получаем статистику Push
$stats = ['total_subscriptions' => 0, 'unique_users' => 0, 'last_24h' => 0, 'total_users' => count($users)];

$table_exists = $db->query("SHOW TABLES LIKE 'push_subscriptions'")->num_rows > 0;
if ($table_exists) {
    $result = $db->query("SELECT COUNT(*) as cnt FROM push_subscriptions");
    $stats['total_subscriptions'] = (int)$result->fetch_assoc()['cnt'];
    
    $result = $db->query("SELECT COUNT(DISTINCT user_uuid) as cnt FROM push_subscriptions");
    $stats['unique_users'] = (int)$result->fetch_assoc()['cnt'];
    
    // Получаем список пользователей с подписками
    $sub_users_result = $db->query("SELECT DISTINCT user_uuid, endpoint FROM push_subscriptions");
    $subscribed_users = [];
    $subscription_types = [];
    while ($row = $sub_users_result->fetch_assoc()) {
        $subscribed_users[] = $row['user_uuid'];
        $endpoint = $row['endpoint'];
        if (strpos($endpoint, 'fcm.googleapis.com') !== false) {
            $subscription_types[$row['user_uuid']][] = 'FCM';
        } elseif (strpos($endpoint, 'wns2') !== false) {
            $subscription_types[$row['user_uuid']][] = 'WNS';
        } else {
            $subscription_types[$row['user_uuid']][] = 'OTHER';
        }
    }
    $stats['subscribed_users'] = $subscribed_users;
    $stats['subscription_types'] = $subscription_types;
}

$log_table_exists = $db->query("SHOW TABLES LIKE 'push_sent_log'")->num_rows > 0;
if ($log_table_exists) {
    $day_ago = msgql_now_ms() - 86400000;
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM push_sent_log WHERE created_at > ?");
    $stmt->bind_param("i", $day_ago);
    $stmt->execute();
    $stats['last_24h'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

// Проверяем наличие VAPID ключей в конфиге
global $config;
$vapid_configured = !empty($config['vapid_public_key']) && !empty($config['vapid_private_key']);
$vapid_public_key_preview = $vapid_configured ? substr($config['vapid_public_key'], 0, 20) . '...' : 'не настроен';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Тестирование Push-уведомлений v4.1</title>
    <style>
        body { background: #0b1020; font-family: system-ui, sans-serif; color: #e9eefc; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #121a33; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.08); }
        h1, h2, h3 { margin-bottom: 16px; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .warning { color: #f59e0b; }
        .info { color: #60a5fa; }
        .stat-card { background: #0f1529; padding: 16px; border-radius: 12px; flex: 1; text-align: center; }
        .stat-value { font-size: 32px; font-weight: bold; }
        .stat-label { font-size: 12px; color: rgba(233,238,252,0.6); margin-top: 4px; }
        .stats-grid { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        select, textarea { width: 100%; padding: 10px; border-radius: 8px; background: #0b1020; border: 1px solid rgba(255,255,255,0.15); color: #e9eefc; margin-bottom: 12px; }
        button { background: #4f7cff; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; }
        button:hover { background: #3b66e0; }
        .console-log { background: #1a1a2a; border: 1px solid #4f7cff; border-radius: 8px; padding: 12px; font-family: monospace; font-size: 11px; max-height: 400px; overflow-y: auto; margin-top: 20px; }
        .console-log-entry { padding: 4px 0; border-bottom: 1px solid #2a2a3a; font-family: monospace; font-size: 11px; }
        .console-log-entry.debug { color: #60a5fa; }
        .console-log-entry.info { color: #4ade80; }
        .console-log-entry.warn { color: #f59e0b; }
        .console-log-entry.error { color: #f87171; }
        .console-log-entry.success { color: #4ade80; font-weight: bold; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px; }
        .badge-fcm { background: rgba(79,124,255,0.2); color: #9bb7ff; }
        .badge-wns { background: rgba(248,113,113,0.2); color: #f87171; }
        .badge-mozilla { background: rgba(34,197,94,0.2); color: #4ade80; }
        .debug-log { background: #0a0a0a; border: 1px solid #2c2c2c; border-radius: 8px; padding: 12px; font-family: monospace; font-size: 11px; max-height: 300px; overflow-y: auto; margin-top: 20px; }
        .debug-log-entry { padding: 4px 0; border-bottom: 1px solid #1a1a1a; color: #a0a0a0; }
        .back-link { display: inline-block; margin-top: 20px; color: #9bb7ff; text-decoration: none; }
        hr { border-color: rgba(255,255,255,0.1); margin: 16px 0; }
        .flex-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .btn-secondary { background: #2d3748; color: #e9eefc; border: 1px solid #4a5568; }
        .btn-secondary:hover { background: #4a5568; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-copy { background: #10b981; }
        .btn-copy:hover { background: #059669; }
        .vapid-key-compare { background: #0f1529; border-radius: 8px; padding: 12px; margin-top: 10px; font-family: monospace; font-size: 11px; word-break: break-all; }
        .vapid-key-compare .match { color: #4ade80; }
        .vapid-key-compare .mismatch { color: #f87171; }
    </style>
</head>
<body>
<div class="container">
    <h1>📱 Тестирование Push-уведомлений v4.1</h1>
    
    <!-- Консоль логов JavaScript -->
    <div class="card">
        <h2>📋 JavaScript Консоль</h2>
        <div id="js-console" class="console-log">
            <div class="console-log-entry info">⏳ Инициализация...</div>
        </div>
        <div class="flex-buttons">
            <button id="clearConsoleBtn" class="btn-secondary">🗑️ Очистить консоль</button>
            <button id="copyConsoleBtn" class="btn-copy">📋 Копировать лог</button>
            <button id="checkSubscriptionBtn" class="btn-secondary">🔍 Проверить подписку устройства</button>
            <button id="createSubscriptionBtn" class="btn-secondary">➕ Создать новую подписку</button>
            <button id="deleteSubscriptionBtn" class="btn-danger">🗑️ Удалить подписку</button>
        </div>
    </div>
    
    <!-- Диагностика VAPID с сравнением ключей -->
    <div class="card">
        <h2>🔑 Диагностика VAPID</h2>
        <div class="stats-grid" style="margin-bottom: 0;">
            <div class="stat-card">
                <div class="stat-value"><?= $vapid_configured ? '✅' : '❌' ?></div>
                <div class="stat-label">VAPID ключи настроены</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size: 14px; word-break: break-all;"><?= htmlspecialchars($vapid_public_key_preview) ?></div>
                <div class="stat-label">Публичный ключ (первые 20 символов)</div>
            </div>
        </div>
        <div class="vapid-key-compare" id="vapid-key-compare">
            <div id="vapid-status">⏳ Проверка ключей...</div>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total_subscriptions'] ?></div>
            <div class="stat-label">Активных подписок</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['unique_users'] ?></div>
            <div class="stat-label">Пользователей с подпиской</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total_users'] ?></div>
            <div class="stat-label">Всего пользователей</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['last_24h'] ?></div>
            <div class="stat-label">Push отправлено за 24ч</div>
        </div>
    </div>
    
    <div class="card">
        <h2>🔔 Отправить тестовое Push-уведомление</h2>
        <form method="post" id="testPushForm">
            <input type="hidden" name="action" value="test_push">
            <?= msgql_csrf_form_field() ?>
            
            <label>Получатель:</label>
            <select name="user_uuid" id="targetUser">
                <?php foreach ($users as $user): ?>
                    <?php
                    $has_sub = in_array($user['uuid'], $stats['subscribed_users'] ?? []);
                    $types = $stats['subscription_types'][$user['uuid']] ?? [];
                    $badge = '';
                    if ($has_sub) {
                        if (in_array('FCM', $types)) $badge .= '<span class="badge badge-fcm">FCM</span>';
                        if (in_array('WNS', $types)) $badge .= '<span class="badge badge-wns">WNS</span>';
                    }
                    ?>
                    <option value="<?= htmlspecialchars($user['uuid']) ?>" 
                        <?= $user['uuid'] === $current_user_uuid ? 'selected' : '' ?>
                        data-has-subscription="<?= $has_sub ? '1' : '0' ?>"
                        data-sub-types="<?= implode(',', $types) ?>">
                        <?= htmlspecialchars($user['name'] ?: $user['login']) ?>
                        <?= $has_sub ? '✅ (есть подписка) ' . $badge : '❌ (нет подписки)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <label>Тестовое сообщение (опционально):</label>
            <textarea name="test_message" id="testMessage" rows="2" placeholder="Оставьте пустым для стандартного сообщения..."></textarea>
            
            <button type="submit" id="sendBtn">📨 Отправить тестовый Push</button>
        </form>
        
        <!-- Отображение логов выполнения PHP -->
        <?php if (!empty($debug_logs)): ?>
        <div style="margin-top: 20px;">
            <h3>📋 Логи выполнения (PHP):</h3>
            <div class="debug-log">
                <?php foreach ($debug_logs as $log): ?>
                <div class="debug-log-entry">
                    <?= htmlspecialchars($log) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($test_result !== null): ?>
        <div style="margin-top: 20px; padding: 16px; background: #0f1529; border-radius: 8px;">
            <h3>Результат отправки:</h3>
            <div class="<?= $test_result['sent'] ? 'success' : 'error' ?>">
                <strong><?= $test_result['sent'] ? '✅ Успешно!' : '❌ Ошибка' ?></strong>
            </div>
            <div style="margin-top: 8px; font-size: 13px;">
                <strong>Причина:</strong> <?= htmlspecialchars($test_result['reason'] ?? 'неизвестно') ?>
            </div>
            <?php if (isset($test_result['details']) && is_array($test_result['details'])): ?>
            <div style="margin-top: 8px; font-size: 12px; color: rgba(233,238,252,0.6);">
                <strong>Детали:</strong> 
                Всего подписок: <?= $test_result['details']['total'] ?? 0 ?>, 
                Успешно: <?= $test_result['details']['successful'] ?? 0 ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>📋 Инструкция по тестированию</h2>
        <ol style="margin-left: 20px; line-height: 1.6;">
            <li>Убедитесь, что в браузере разрешены уведомления (🔔)</li>
            <li>Service Worker должен быть зарегистрирован</li>
            <li><strong>Для теста на Android: ЗАКРОЙТЕ все вкладки с сайтом!</strong></li>
            <li>Нажмите "Отправить тестовый Push"</li>
            <li>Следите за логами в консоли выше</li>
        </ol>
    </div>
    
    <a href="admin.php" class="back-link">← Вернуться в админку</a>
</div>

<script nonce="<?= CSP_NONCE ?>">
// ==================== МАКСИМАЛЬНОЕ ЛОГИРОВАНИЕ В JS КОНСОЛЬ ====================
(function() {
    'use strict';
    
    // Контейнер для вывода логов на страницу
    const consoleContainer = document.getElementById('js-console');
    let allLogs = []; // Храним все логи для копирования
    
    // ==================== НОРМАЛИЗАЦИЯ КЛЮЧЕЙ ДЛЯ КОРРЕКТНОГО СРАВНЕНИЯ ====================
    function normalizeVapidKeyForCompare(key) {
        if (!key) return '';
        // Приводим к стандартному base64 (URL-safe → стандартный)
        let normalized = key
            .replace(/-/g, '+')   // URL-safe минус → плюс
            .replace(/_/g, '/')   // URL-safe underscore → слэш
            .replace(/=+$/, '');  // Убираем trailing padding для сравнения
        return normalized;
    }
    
    function findFirstDiffPos(str1, str2) {
        const minLen = Math.min(str1.length, str2.length);
        for (let i = 0; i < minLen; i++) {
            if (str1[i] !== str2[i]) return i;
        }
        return str1.length !== str2.length ? minLen : -1;
    }
    
    function addConsoleLog(message, type = 'debug') {
        if (!consoleContainer) return;
        
        const entry = document.createElement('div');
        entry.className = `console-log-entry ${type}`;
        const timestamp = new Date().toLocaleTimeString();
        const logText = `[${timestamp}] [${type.toUpperCase()}] ${message}`;
        entry.innerHTML = logText;
        consoleContainer.appendChild(entry);
        consoleContainer.scrollTop = consoleContainer.scrollHeight;
        
        // Сохраняем в массив для копирования
        allLogs.push(logText);
        
        // Также выводим в браузерную консоль
        const consoleMethod = type === 'error' ? console.error : (type === 'warn' ? console.warn : console.log);
        consoleMethod(`[TEST_PUSH] ${message}`);
    }
    
    function clearConsole() {
        if (consoleContainer) {
            consoleContainer.innerHTML = '<div class="console-log-entry info">⏳ Консоль очищена. Перезагрузка...</div>';
            allLogs = [];
            setTimeout(() => {
                location.reload();
            }, 500);
        }
    }
    
    function copyConsoleLog() {
        const logText = allLogs.join('\n');
        navigator.clipboard.writeText(logText).then(() => {
            addConsoleLog('✅ Лог скопирован в буфер обмена (' + allLogs.length + ' строк)', 'success');
        }).catch(() => {
            addConsoleLog('❌ Не удалось скопировать лог', 'error');
        });
    }
    
    addConsoleLog('=== ИНИЦИАЛИЗАЦИЯ ТЕСТА PUSH v4.1 ===', 'info');
    addConsoleLog(`APP_BASE: "${window.APP_BASE || '(пусто)'}"`, 'debug');
    addConsoleLog(`User Agent: ${navigator.userAgent}`, 'debug');
    
    // ДИАГНОСТИКА VAPID КЛЮЧА
    const configVapidKey = '<?= addslashes($vapid_public_key ?? '') ?>';
    addConsoleLog(`VAPID ключ из config.php: ${configVapidKey ? configVapidKey.substring(0, 50) + '...' : 'НЕ НАЙДЕН!'}`, configVapidKey ? 'debug' : 'error');
    addConsoleLog(`Длина ключа из config: ${configVapidKey.length} символов`, 'debug');
    addConsoleLog(`window.vapidPublicKey: ${window.vapidPublicKey ? window.vapidPublicKey.substring(0, 50) + '...' : 'НЕ ОПРЕДЕЛЕН'}`, window.vapidPublicKey ? 'debug' : 'error');
    
    // Сравнение ключей
    const keyCompareDiv = document.getElementById('vapid-key-compare');
    if (keyCompareDiv && configVapidKey) {
        keyCompareDiv.innerHTML = `
            <div><strong>🔑 Ключ из config.php:</strong></div>
            <div style="font-family: monospace; font-size: 10px; word-break: break-all; padding: 8px; background: #0b1020; border-radius: 6px; margin: 8px 0;">${configVapidKey}</div>
            <div><strong>🌐 window.vapidPublicKey (JS):</strong></div>
            <div style="font-family: monospace; font-size: 10px; word-break: break-all; padding: 8px; background: #0b1020; border-radius: 6px; margin: 8px 0;" id="js-key-display">⏳ Загрузка...</div>
        `;
    }
    
    // Проверка поддержки уведомлений
    function checkNotificationSupportDetailed() {
        const support = {
            notification: 'Notification' in window,
            serviceWorker: 'serviceWorker' in navigator,
            pushManager: 'PushManager' in window,
            permission: Notification.permission
        };
        
        addConsoleLog(`=== ПРОВЕРКА ПОДДЕРЖКИ ===`, 'info');
        addConsoleLog(`Notification API: ${support.notification ? '✅' : '❌'}`, support.notification ? 'debug' : 'error');
        addConsoleLog(`Service Worker: ${support.serviceWorker ? '✅' : '❌'}`, support.serviceWorker ? 'debug' : 'error');
        addConsoleLog(`Push Manager: ${support.pushManager ? '✅' : '❌'}`, support.pushManager ? 'debug' : 'error');
        addConsoleLog(`Notification permission: ${support.permission}`, support.permission === 'granted' ? 'success' : 'warn');
        
        support.supported = support.notification && support.serviceWorker && support.pushManager;
        return support;
    }
    
    // ==================== ДИАГНОСТИКА ПОДПИСКИ УСТРОЙСТВА ====================
    async function checkDeviceSubscription() {
        addConsoleLog('=== ДИАГНОСТИКА ПОДПИСКИ УСТРОЙСТВА ===', 'info');
        
        if (!('serviceWorker' in navigator)) {
            addConsoleLog('❌ Service Worker не поддерживается', 'error');
            return;
        }
        
        try {
            const registration = await navigator.serviceWorker.ready;
            addConsoleLog(`✅ Service Worker готов, scope: ${registration.scope}`, 'success');
            
            const subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                addConsoleLog('❌ НЕТ АКТИВНОЙ ПОДПИСКИ на этом устройстве', 'warn');
                addConsoleLog('💡 Совет: Разрешите уведомления и обновите страницу', 'info');
                return;
            }
            
            addConsoleLog('✅ АКТИВНАЯ ПОДПИСКА НАЙДЕНА!', 'success');
            addConsoleLog(`Endpoint: ${subscription.endpoint.substring(0, 100)}...`, 'debug');
            
            // Определяем тип подписки
            if (subscription.endpoint.includes('fcm.googleapis.com')) {
                addConsoleLog('📱 ТИП ПОДПИСКИ: FCM (Google Chrome/Firefox) ✅ РАБОТАЕТ на Android', 'success');
            } else if (subscription.endpoint.includes('wns2') || subscription.endpoint.includes('notify.windows.com')) {
                addConsoleLog('🪟 ТИП ПОДПИСКИ: WNS (Windows Edge) ❌ НЕ РАБОТАЕТ на Android', 'error');
                addConsoleLog('💡 РЕШЕНИЕ: Используйте Chrome или Firefox на Android', 'info');
            } else if (subscription.endpoint.includes('updates.push.services.mozilla.com')) {
                addConsoleLog('🦊 ТИП ПОДПИСКИ: Mozilla Autopush (Firefox) ✅ РАБОТАЕТ', 'success');
            } else {
                addConsoleLog(`❓ ТИП ПОДПИСКИ: неизвестный (${subscription.endpoint.split('/')[2] || '?'})`, 'warn');
            }
            
            // Получаем публичный ключ из подписки
            if (subscription.options && subscription.options.applicationServerKey) {
                const key = subscription.options.applicationServerKey;
                const keyBase64 = btoa(String.fromCharCode.apply(null, new Uint8Array(key)));
                addConsoleLog(`🔑 Публичный ключ в ПОДПИСКЕ (сырой): ${keyBase64.substring(0, 50)}...`, 'debug');
                addConsoleLog(`📏 Длина ключа в подписке: ${keyBase64.length} символов`, 'debug');
                
                // НОРМАЛИЗОВАННОЕ СРАВНЕНИЕ КЛЮЧЕЙ
                const normalizedSubscription = normalizeVapidKeyForCompare(keyBase64);
                const normalizedConfig = normalizeVapidKeyForCompare(configVapidKey);
                
                addConsoleLog(`🔧 Нормализованный ключ подписки: ${normalizedSubscription.substring(0, 50)}...`, 'debug');
                addConsoleLog(`🔧 Нормализованный ключ config: ${normalizedConfig.substring(0, 50)}...`, 'debug');
                
                // Обновляем отображение в блоке VAPID
                const jsKeyDisplay = document.getElementById('js-key-display');
                if (jsKeyDisplay) {
                    const isMatch = (normalizedSubscription === normalizedConfig);
                    jsKeyDisplay.innerHTML = `${keyBase64}<br><span style="color: ${isMatch ? '#4ade80' : '#f87171'}">${isMatch ? '✅ КЛЮЧИ СОВПАДАЮТ (после нормализации)!' : '❌ КЛЮЧИ НЕ СОВПАДАЮТ!'}</span>`;
                }
                
                if (normalizedSubscription !== normalizedConfig) {
                    addConsoleLog(`❌❌❌ КЛЮЧИ НЕ СОВПАДАЮТ даже после нормализации!`, 'error');
                    const diffPos = findFirstDiffPos(normalizedSubscription, normalizedConfig);
                    if (diffPos !== -1) {
                        addConsoleLog(`    Разница на позиции ${diffPos}: '${normalizedSubscription[diffPos]}' vs '${normalizedConfig[diffPos]}'`, 'error');
                    }
                } else {
                    addConsoleLog(`✅✅✅ КЛЮЧИ СОВПАДАЮТ! (после нормализации)`, 'success');
                    addConsoleLog(`   Это одни и те же ключи, разница только в URL-safe кодировке (- vs +)`, 'info');
                }
            } else {
                addConsoleLog('⚠️ В подписке отсутствует applicationServerKey', 'warn');
            }
            
            if (subscription.expirationTime) {
                addConsoleLog(`📅 Истекает: ${new Date(subscription.expirationTime).toLocaleString()}`, 'debug');
            } else {
                addConsoleLog(`📅 Истекает: никогда`, 'debug');
            }
            
        } catch (error) {
            addConsoleLog(`❌ Ошибка при проверке подписки: ${error.message}`, 'error');
        }
    }
    
    // ==================== СОЗДАНИЕ НОВОЙ ПОДПИСКИ ====================
    async function createNewSubscription() {
        addConsoleLog('=== СОЗДАНИЕ НОВОЙ ПОДПИСКИ ===', 'info');
        
        if (!('serviceWorker' in navigator)) {
            addConsoleLog('❌ Service Worker не поддерживается', 'error');
            return false;
        }
        
        if (Notification.permission !== 'granted') {
            addConsoleLog('⚠️ Нет разрешения на уведомления, запрашиваем...', 'warn');
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                addConsoleLog('❌ Разрешение не получено', 'error');
                return false;
            }
        }
        
        const vapidKey = window.vapidPublicKey || configVapidKey;
        if (!vapidKey) {
            addConsoleLog('❌ VAPID публичный ключ не найден!', 'error');
            addConsoleLog('💡 Проверьте config.php: $config[\'vapid_public_key\']', 'error');
            return false;
        }
        
        addConsoleLog(`VAPID ключ для подписки: ${vapidKey.substring(0, 30)}...`, 'debug');
        
        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }
        
        try {
            const registration = await navigator.serviceWorker.ready;
            addConsoleLog(`Service Worker готов`, 'debug');
            
            const applicationServerKey = urlBase64ToUint8Array(vapidKey);
            
            // Сначала удаляем старую подписку, если есть
            let oldSubscription = await registration.pushManager.getSubscription();
            if (oldSubscription) {
                addConsoleLog('Удаляем старую подписку...', 'debug');
                await oldSubscription.unsubscribe();
                addConsoleLog('Старая подписка удалена', 'debug');
            }
            
            addConsoleLog('Создаём новую подписку...', 'debug');
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            });
            
            addConsoleLog(`✅ ПОДПИСКА СОЗДАНА УСПЕШНО!`, 'success');
            addConsoleLog(`Endpoint: ${subscription.endpoint.substring(0, 100)}...`, 'debug');
            
            if (subscription.endpoint.includes('fcm.googleapis.com')) {
                addConsoleLog('📱 ТИП: FCM ✅ Работает на Android', 'success');
            } else if (subscription.endpoint.includes('wns2')) {
                addConsoleLog('🪟 ТИП: WNS ❌ Не работает на Android', 'error');
            }
            
            // Сохраняем на сервер
            addConsoleLog('Сохраняем подписку на сервере...', 'debug');
            const subscriptionData = {
                endpoint: subscription.endpoint,
                expirationTime: subscription.expirationTime,
                keys: {
                    p256dh: btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('p256dh')))),
                    auth: btoa(String.fromCharCode.apply(null, new Uint8Array(subscription.getKey('auth'))))
                }
            };
            
            const formData = new URLSearchParams();
            formData.append('ajax_mode', '1');
            formData.append('csrf_token', window.csrfToken || '');
            formData.append('subscription', JSON.stringify(subscriptionData));
            
            const response = await fetch(window.APP_BASE + '/save_push_subscription.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                addConsoleLog(`Ошибка парсинга ответа: ${e.message}`, 'error');
                return false;
            }
            
            if (data.success) {
                addConsoleLog('✅ Подписка сохранена на сервере', 'success');
                localStorage.setItem('push_auto_subscribe_done', 'true');
                // Проверяем созданную подписку
                setTimeout(checkDeviceSubscription, 1000);
                return true;
            } else {
                addConsoleLog(`❌ Ошибка сохранения: ${data.error || 'неизвестная'}`, 'error');
                return false;
            }
            
        } catch (error) {
            addConsoleLog(`❌ Ошибка: ${error.message}`, 'error');
            if (error.message.includes('ApplicationServerKey')) {
                addConsoleLog('  Проблема с VAPID ключом. Проверьте config.php', 'error');
            }
            return false;
        }
    }
    
    // ==================== УДАЛЕНИЕ ПОДПИСКИ ====================
    async function deleteSubscription() {
        addConsoleLog('=== УДАЛЕНИЕ ПОДПИСКИ ===', 'info');
        
        if (!('serviceWorker' in navigator)) {
            addConsoleLog('❌ Service Worker не поддерживается', 'error');
            return;
        }
        
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                addConsoleLog('❌ Нет активной подписки для удаления', 'warn');
                return;
            }
            
            addConsoleLog(`Удаляем подписку: ${subscription.endpoint.substring(0, 80)}...`, 'debug');
            
            const endpoint = subscription.endpoint;
            await subscription.unsubscribe();
            
            // Удаляем с сервера
            const formData = new URLSearchParams();
            formData.append('csrf_token', window.csrfToken || '');
            formData.append('endpoint', endpoint);
            
            await fetch(window.APP_BASE + '/delete_push_subscription.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            
            addConsoleLog('✅ Подписка удалена', 'success');
            localStorage.removeItem('push_auto_subscribe_done');
            
            // Обновляем отображение
            const jsKeyDisplay = document.getElementById('js-key-display');
            if (jsKeyDisplay) {
                jsKeyDisplay.innerHTML = '❌ Нет активной подписки';
            }
            
        } catch (error) {
            addConsoleLog(`❌ Ошибка удаления: ${error.message}`, 'error');
        }
    }
    
    // Проверка существующей подписки (для начальной загрузки)
    async function checkExistingSubscription() {
        addConsoleLog('=== ПРОВЕРКА СУЩЕСТВУЮЩЕЙ ПОДПИСКИ ===', 'info');
        
        if (!('serviceWorker' in navigator)) {
            addConsoleLog('Service Worker не поддерживается', 'error');
            return null;
        }
        
        try {
            const registration = await navigator.serviceWorker.ready;
            addConsoleLog(`Service Worker готов, scope: ${registration.scope}`, 'debug');
            
            const subscription = await registration.pushManager.getSubscription();
            
            if (subscription) {
                addConsoleLog(`✅ Найдена существующая подписка`, 'success');
                addConsoleLog(`  Endpoint: ${subscription.endpoint.substring(0, 100)}...`, 'debug');
                
                // Определяем тип подписки
                if (subscription.endpoint.includes('fcm.googleapis.com')) {
                    addConsoleLog(`  Тип: FCM (Google Chrome) ✅ Работает на Android`, 'success');
                } else if (subscription.endpoint.includes('wns2') || subscription.endpoint.includes('notify.windows.com')) {
                    addConsoleLog(`  Тип: WNS (Windows Edge) ❌ НЕ РАБОТАЕТ на Android`, 'error');
                } else if (subscription.endpoint.includes('updates.push.services.mozilla.com')) {
                    addConsoleLog(`  Тип: Mozilla Autopush (Firefox) ✅ Работает`, 'success');
                } else {
                    addConsoleLog(`  Тип: Неизвестный (${subscription.endpoint.split('/')[2] || '?'})`, 'warn');
                }
                
                // Проверяем ключ с нормализацией
                if (subscription.options && subscription.options.applicationServerKey) {
                    const key = subscription.options.applicationServerKey;
                    const keyBase64 = btoa(String.fromCharCode.apply(null, new Uint8Array(key)));
                    addConsoleLog(`  Публичный ключ в подписке: ${keyBase64.substring(0, 40)}...`, 'debug');
                    
                    const normalizedSub = normalizeVapidKeyForCompare(keyBase64);
                    const normalizedCfg = normalizeVapidKeyForCompare(configVapidKey);
                    
                    if (normalizedSub !== normalizedCfg) {
                        addConsoleLog(`  ⚠️ ВНИМАНИЕ: Ключ в подписке НЕ СОВПАДАЕТ с ключом из config!`, 'warn');
                        addConsoleLog(`  Рекомендуется удалить подписку и создать новую.`, 'info');
                    } else {
                        addConsoleLog(`  ✅ Ключ совпадает с config (после нормализации)`, 'success');
                    }
                }
                
                if (subscription.expirationTime) {
                    addConsoleLog(`  Истекает: ${new Date(subscription.expirationTime).toLocaleString()}`, 'debug');
                }
                return subscription;
            } else {
                addConsoleLog(`❌ Нет активной подписки`, 'warn');
                return null;
            }
        } catch (error) {
            addConsoleLog(`Ошибка при проверке подписки: ${error.message}`, 'error');
            return null;
        }
    }
    
    // Обновление статуса бейджа в UI
    function updateBadgeStatus() {
        const select = document.getElementById('targetUser');
        if (!select) return;
        
        const selected = select.options[select.selectedIndex];
        const hasSub = selected?.getAttribute('data-has-subscription') === '1';
        const subTypes = selected?.getAttribute('data-sub-types') || '';
        
        if (hasSub) {
            addConsoleLog(`Выбран пользователь с подпиской. Типы: ${subTypes || 'неизвестно'}`, 'info');
            if (subTypes.includes('WNS')) {
                addConsoleLog(`⚠️ ВНИМАНИЕ: У пользователя есть WNS подписка (Edge на Windows). На Android такие подписки НЕ РАБОТАЮТ!`, 'warn');
            }
            if (subTypes.includes('FCM')) {
                addConsoleLog(`✅ У пользователя есть FCM подписка (должна работать на Android)`, 'success');
            }
        } else {
            addConsoleLog(`⚠️ У выбранного пользователя нет push-подписок`, 'warn');
        }
    }
    
    // Экспорт в глобальную область для кнопок
    window.checkDeviceSubscription = checkDeviceSubscription;
    window.createNewSubscription = createNewSubscription;
    window.deleteSubscription = deleteSubscription;
    window.clearConsole = clearConsole;
    window.copyConsoleLog = copyConsoleLog;
    
    // Инициализация
    document.addEventListener('DOMContentLoaded', async function() {
        addConsoleLog('=== DOM ЗАГРУЖЕН ===', 'info');
        
        // Проверка поддержки
        checkNotificationSupportDetailed();
        
        // Проверка существующей подписки
        await checkExistingSubscription();
        
        // Запускаем полную диагностику подписки устройства
        setTimeout(checkDeviceSubscription, 1000);
        
        // Обработчик изменения выбора пользователя
        const select = document.getElementById('targetUser');
        if (select) {
            select.addEventListener('change', updateBadgeStatus);
            updateBadgeStatus();
        }
        
        // Кнопки управления
        const clearBtn = document.getElementById('clearConsoleBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', clearConsole);
        }
        
        const copyBtn = document.getElementById('copyConsoleBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', copyConsoleLog);
        }
        
        const checkBtn = document.getElementById('checkSubscriptionBtn');
        if (checkBtn) {
            checkBtn.addEventListener('click', checkDeviceSubscription);
        }
        
        const createBtn = document.getElementById('createSubscriptionBtn');
        if (createBtn) {
            createBtn.addEventListener('click', async () => {
                await createNewSubscription();
                setTimeout(checkDeviceSubscription, 1000);
            });
        }
        
        const deleteBtn = document.getElementById('deleteSubscriptionBtn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                await deleteSubscription();
                setTimeout(checkDeviceSubscription, 1000);
            });
        }
        
        // Обработчик отправки формы
        const form = document.getElementById('testPushForm');
        if (form) {
            form.addEventListener('submit', async function(e) {
                addConsoleLog('=== ФОРМА ОТПРАВЛЕНА ===', 'info');
                
                const btn = document.getElementById('sendBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = '⏳ Отправка...';
                }
                
                setTimeout(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = '📨 Отправить тестовый Push';
                    }
                }, 10000);
            });
        }
        
        addConsoleLog('=== ГОТОВО ===', 'success');
        addConsoleLog('Доступные команды:', 'info');
        addConsoleLog('  checkDeviceSubscription() - проверить подписку устройства', 'debug');
        addConsoleLog('  createNewSubscription() - создать новую подписку', 'debug');
        addConsoleLog('  deleteSubscription() - удалить подписку', 'debug');
        addConsoleLog('  copyConsoleLog() - скопировать лог', 'debug');
    });
})();
</script>

<?php
// ПРИНУДИТЕЛЬНЫЙ ВЫВОД VAPID КЛЮЧА НАПРЯМУЮ (ОБХОД page_end.php)
global $vapid_public_key, $config;
if (empty($vapid_public_key) && isset($config['vapid_public_key'])) {
    $vapid_public_key = $config['vapid_public_key'];
}
// Дублируем вывод ключа напрямую
echo '<script nonce="' . CSP_NONCE . '">';
echo 'window.vapidPublicKey = ' . json_encode($vapid_public_key ?? '') . ';';
echo 'console.log("[VAPID_DIRECT] window.vapidPublicKey set to:", window.vapidPublicKey);';
echo '</script>';
?>
<?php require_once __DIR__ . '/layouts/page_end.php'; ?>