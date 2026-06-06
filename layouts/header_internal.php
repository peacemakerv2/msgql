<?php
/**
 * header_internal.php version 3.0: объединённая версия
 * Сохраняет новые функции (колокольчик, адаптивность) + добавляет
 * рабочий бейдж и корректную передачу часового пояса
 */

// ПРИНУДИТЕЛЬНАЯ ПЕРЕДАЧА VAPID КЛЮЧА
global $vapid_public_key, $config;
if (empty($vapid_public_key) && isset($config['vapid_public_key'])) {
    $vapid_public_key = $config['vapid_public_key'];
}

if (defined('AJAX_REQUEST')) return;

$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($appBase === '' || $appBase === '\\') $appBase = '';

$menuActive = [
    'home' => ($smuri === 'index.php'),
    'projects' => ($smuri === 'projects.php'),
    'messages' => ($smuri === 'messages.php'),
    'files' => ($smuri === 'files.php'),
    'search' => ($smuri === 'search.php'),
    'admin' => ($smuri === 'admin.php'),
];

// Получаем CSRF-токен
$csrf_token = '';
if (function_exists('msgql_csrf_get_token')) {
    $csrf_token = msgql_csrf_get_token();
}

// Получаем смещение пользователя (минуты от UTC)
$user_tz_offset = 0;
if (function_exists('msgql_user_timezone_offset')) {
    $user_tz_offset = msgql_user_timezone_offset();
}

// Получаем UUID текущего пользователя
$current_user_uuid_for_js = '';
if (function_exists('msgql_current_user_uuid')) {
    $current_user_uuid_for_js = msgql_current_user_uuid();
}

// Получаем логин для отображения
$current_user_login_display = $_SESSION['login'] ?? '';


// Получаем настройки звука текущего пользователя из БД
$db = msgql_db();
$sound_enabled = 1;
$sound_interval_sec = 600;

if ($db && !empty($current_user_uuid_for_js)) {
    $stmt = $db->prepare("SELECT sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid_for_js);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sound_enabled = (int)($row['sound_enabled'] ?? 1);
        $sound_interval_sec = (int)($row['sound_interval_sec'] ?? 600);
        
        // 🔥 ДОБАВЬТЕ ЭТИ СТРОКИ ДЛЯ ОТЛАДКИ
        log_debug("[HEADER_DEBUG] User {$current_user_uuid_for_js} - sound_enabled: {$sound_enabled}, sound_interval_sec: {$sound_interval_sec}");
    }
    $stmt->close();
}

?>
<!doctype html>
<html lang="ru">
<head>


<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover"/>
<title><?= htmlspecialchars($system_title ?? 'ЗадаЧат') ?> - Система управления задачами</title>
<link rel="icon" type="image/x-icon" href="<?= $appBase ?>/favicon.ico?v=2">
<style>
/* Глобальные стили для всех внутренних страниц */
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0b1020;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#e9eefc;overflow-x:hidden}
.wrap{max-width:1280px;margin:0 auto;padding:16px 20px}
@media (max-width:768px){.wrap{padding:12px}}
.top-bar{background:#0f1529;border-bottom:1px solid rgba(255,255,255,0.08);padding:12px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}



.logo {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 18px;
    font-weight: 700;
    color: #e9eefc;  /* Светлый цвет вместо градиента */
}
.logo-icon {
    font-size: 22px;
    filter: drop-shadow(0 0 2px rgba(79,124,255,0.5)); /* Лёгкое свечение для иконки */
}
.logo-text {
    background: linear-gradient(135deg, #e9eefc, #9bb7ff);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

/* Скрываем текст на мобильных */
@media (max-width: 768px) {
    .logo {
        display: none;
    }
}


.nav-links{display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.nav-links a{color:rgba(233,238,252,0.8);text-decoration:none;font-size:14px;transition:color 0.2s;padding:6px 0}
.nav-links a:hover{color:#4f7cff}
.nav-links a.active{color:#4f7cff;border-bottom:2px solid #4f7cff}

/* Стили для поиска в навигации */
.nav-search-form {
    display: flex;
    gap: 4px;
    margin: 0 8px;
}
.nav-search-input {
    padding: 6px 12px;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.05);
    color: white;
    font-size: 13px;
    width: 180px;
}
.nav-search-input:focus {
    outline: none;
    border-color: #4f7cff;
    background: rgba(255,255,255,0.1);
}
.nav-search-btn {
    padding: 6px 12px;
    border-radius: 20px;
    background: #4f7cff;
    border: none;
    color: white;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.2s;
}
.nav-search-btn:hover {
    background: #3b66e0;
}

.message-input::placeholder {
    font-size: 12px;
    color: #9ca3af;
    opacity: 0.8;
}

.user-menu{display:flex;align-items:center;gap:20px}
.notification-bell{position:relative;cursor:pointer;font-size:20px}
.notification-badge{position:absolute;top:-8px;right:-12px;background:#ef4444;color:white;border-radius:20px;padding:2px 6px;font-size:10px;font-weight:bold;min-width:18px;text-align:center}
.user-name{font-size:13px;color:rgba(233,238,252,0.9)}
.logout-btn{background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;padding:6px 14px;border-radius:20px;font-size:12px;cursor:pointer;transition:all 0.2s;text-decoration:none}
.logout-btn:hover{background:rgba(239,68,68,0.3);border-color:#f87171}

.card{background:#121a33;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px;margin-bottom:20px}
.btn-primary{background:#4f7cff;color:white;border:none;padding:10px 20px;border-radius:10px;font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px}
.btn-primary:hover{background:#3b6ef5}
.btn-secondary{background:#1e293b;color:#e9eefc;border:1px solid rgba(255,255,255,.1);padding:8px 16px;border-radius:10px;cursor:pointer;transition:all 0.2s}
.btn-secondary:hover{background:#334155}
.btn-danger{background:#dc2626;color:white;border:none;padding:8px 16px;border-radius:10px;font-weight:600;cursor:pointer;transition:all 0.2s}
.btn-danger:hover{background:#b91c1c}
input,select,textarea{background:#0b1020;border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:10px 12px;color:#e9eefc;font-size:14px;width:100%;font-family:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:#4f7cff}
label{display:block;margin-bottom:6px;font-size:13px;color:rgba(233,238,252,0.8)}
.custom-alert{position:fixed;top:20px;right:20px;padding:12px 20px;border-radius:10px;color:white;z-index:10000;animation:slideIn 0.3s ease;max-width:350px;word-break:break-word}
.custom-alert.success{background:#22c55e}
.custom-alert.error{background:#ef4444}
.custom-alert.warning{background:#f59e0b}
.custom-alert.info{background:#3b82f6}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@media (max-width:768px){
.top-bar{flex-direction:column;align-items:stretch}
.nav-links{justify-content:center}
.user-menu{justify-content:space-between}
.custom-alert{top:auto;bottom:20px;right:20px;left:auto;max-width:calc(100% - 40px);min-width:200px;width:auto}
.nav-search-form { margin: 8px 0; }
.nav-search-input { width: 100%; }
}
@media (max-width:480px){
.nav-links { gap: 12px; }
.nav-links a { font-size: 12px; }
.user-name { font-size: 11px; }
.logout-btn { padding: 4px 10px; font-size: 10px; }
}
</style>
<script nonce="<?= CSP_NONCE ?>">
    window.jsDebugLevel = <?= (int)($js_debug_level ?? 2) ?>;
    // CSRF токен для уведомлений и AJAX
    if (typeof window.csrfToken === 'undefined' || !window.csrfToken) {
        window.csrfToken = '<?= $csrf_token ?>';
    }
    // ========== ПЕРЕДАЧА ДАННЫХ ПОЛЬЗОВАТЕЛЯ ДЛЯ SSE И ДРУГИХ СКРИПТОВ ==========
    window.currentUserUuid = '<?= $current_user_uuid_for_js ?>';
    // Часовой пояс пользователя (минуты от UTC)
    window.userTimezoneOffset = <?= (int)$user_tz_offset ?>;

    // Флаг загрузки страницы
    window._isPageLoading = true;
    
    // Если offset не установлен или равен 0, получаем из браузера и сохраняем на сервер
    if (!window.userTimezoneOffset || window.userTimezoneOffset === 0) {
        window.userTimezoneOffset = new Date().getTimezoneOffset();
        
        // Асинхронно сохраняем на сервер
        if (window.csrfToken) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.APP_BASE + '/set_timezone.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('offset=' + window.userTimezoneOffset + '&csrf_token=' + encodeURIComponent(window.csrfToken));
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            window._isPageLoading = false;
        }, 2000);
    });

    // ========== ПЕРЕДАЧА VAPID КЛЮЧА ДЛЯ PUSH-УВЕДОМЛЕНИЙ ==========
    window.vapidPublicKey = '<?= addslashes($vapid_public_key ?? '') ?>';
    if (window.vapidPublicKey) {
        logDebug('[VAPID] Public key loaded in header, length:', window.vapidPublicKey.length);
        logDebug('[VAPID] First 50 chars:', window.vapidPublicKey.substring(0, 50) + '...');
    } else {
        logDebug('[VAPID] ❌ VAPID public key NOT SET in header!');
    }



// ==================== BLOCK START: Service Worker Registration v3.2.0 (REMOVED DUPLICATE) ====================
// ver.3.0 - Базовая версия
// ver.3.1 - Добавлен флаг для предотвращения двойной регистрации
// ver.3.2 (2026-06-05) - РЕГИСТРАЦИЯ SERVICE WORKER ПОЛНОСТЬЮ УБРАНА ИЗ HEADER
// - Регистрация Service Worker выполняется ТОЛЬКО в sse_client.js
// - В header_internal.php оставлена только отправка настроек в уже зарегистрированный SW
// - Устранена проблема двойной регистрации

// ПРИМЕЧАНИЕ: Регистрация Service Worker полностью вынесена в sse_client.js
// Здесь НЕ выполняется регистрация, только отправка настроек в уже существующий SW

// Отправляем уровень логирования в Service Worker (если он уже зарегистрирован)
(function sendInitialLogLevelToSW() {
    if (!('serviceWorker' in navigator)) return;
    
    var jsDebugLevel = <?= (int)($js_debug_level ?? 2) ?>;
    
    function trySend() {
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'updateLogLevel',
                logLevel: jsDebugLevel
            });
            if (typeof logDebug === 'function') {
                logDebug('[SW_LOG] Sent log level from header to existing SW:', jsDebugLevel);
            }
        } else {
            // Повторяем попытку через 500 мс (ждём регистрации от sse_client.js)
            setTimeout(trySend, 500);
        }
    }
    
    // Запускаем отправку с задержкой, чтобы дать время sse_client.js зарегистрировать SW
    setTimeout(trySend, 1000);
})();

// Обновляем глобальный флаг для синхронизации с sse_client.js
if (typeof window._swRegistrationStarted === 'undefined') {
    window._swRegistrationStarted = false;
    if (typeof logDebug === 'function') {
        logDebug('[SW_REG] Header: registration flag initialized (registration delegated to sse_client.js)');
    }
}

// ==================== BLOCK START: User Sound Settings Sync v3.3.0 ====================
// ver.3.0 - Базовая версия
// ver.3.1 - Добавлена синхронизация с SSE
// ver.3.2 - Устранено дублирование отправки настроек в Service Worker
// ver.3.3 (2026-06-05) - ОБЪЕДИНЕНЫ ДУБЛИРУЮЩИЕСЯ БЛОКИ ОТПРАВКИ НАСТРОЕК
// - Удалён дублирующий блок sendSoundSettingsToSW
// - Все настройки звука теперь отправляются в Service Worker в одном месте

(function() {
    'use strict';
    
    // Обновляем существующий window.userSettings (НЕ создаём новый!)
    if (typeof window.userSettings === 'undefined') {
        window.userSettings = {};
    }
    
    // Перезаписываем свежими данными из БД
    window.userSettings.soundEnabled = <?= $sound_enabled ? 'true' : 'false' ?>;
    window.userSettings.soundIntervalSec = <?= (int)$sound_interval_sec ?>;
    
    // Синхронизируем sessionStorage для Service Worker
    sessionStorage.setItem('sse_sound_enabled', window.userSettings.soundEnabled ? '1' : '0');
    sessionStorage.setItem('sse_sound_interval', window.userSettings.soundIntervalSec);
    
    logDebug('[HEADER] User sound settings updated:', window.userSettings);
    
    // Если SSE уже инициализирован, обновляем его
    if (window.SSE && typeof window.SSE.updateSoundSettings === 'function') {
        window.SSE.updateSoundSettings(
            window.userSettings.soundEnabled,
            window.userSettings.soundIntervalSec
        );
    }
    
    // ЕДИНСТВЕННАЯ отправка настроек в Service Worker (без дублирования)
    function sendSoundToServiceWorker() {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'updateSoundSettings',
                soundEnabled: window.userSettings.soundEnabled,
                soundIntervalSec: window.userSettings.soundIntervalSec
            });
            logDebug('[SW_SOUND] Sent sound settings to Service Worker (from unified block)');
            // Очищаем счётчик попыток при успехе
            sessionStorage.removeItem('sw_sound_attempts');
        } else {
            // Если Service Worker ещё не активирован, пробуем позже (увеличено до 10 попыток)
            var attempts = parseInt(sessionStorage.getItem('sw_sound_attempts') || '0', 10);
            if (attempts < 10) {
                sessionStorage.setItem('sw_sound_attempts', attempts + 1);
                var delay = Math.min(5000, 500 * Math.pow(1.5, attempts));
                logDebug('[SW_SOUND] Attempt ' + (attempts + 1) + '/10, retrying in ' + delay + 'ms');
                setTimeout(sendSoundToServiceWorker, delay);
            } else {
                logDebug('[SW_SOUND] Max attempts (10) reached, giving up');
                sessionStorage.removeItem('sw_sound_attempts');
            }
        }
    }
    
    // Запускаем отправку с небольшой задержкой
    setTimeout(sendSoundToServiceWorker, 500);
})();
// ==================== BLOCK END: User Sound Settings Sync v3.3.0 ====================
</script>

<!-- ==================== BLOCK START: Push Subscription Functions v1.0.0 ==================== -->
<script nonce="<?= CSP_NONCE ?>">
// Конвертация base64 в Uint8Array (для VAPID ключа)
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

// Проверка поддержки push-уведомлений
function isPushSupported() {
    const supported = 'serviceWorker' in navigator && 'PushManager' in window;
    logDebug('[PUSH] Push supported: ' + supported);
    return supported;
}

// Подписка на push-уведомления
async function subscribeToPushNotifications() {
    logDebug('[PUSH] ========== SUBSCRIBE START ==========');
    
    if (!isPushSupported()) {
        logDebug('[PUSH] ❌ Push not supported');
        if (typeof showToast === 'function') {
            showToast('Ваш браузер не поддерживает push-уведомления', 'error');
        }
        return false;
    }
    
    if (Notification.permission !== 'granted') {
        logDebug('[PUSH] Requesting notification permission...');
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            logDebug('[PUSH] ❌ Notification denied');
            if (typeof showToast === 'function') {
                showToast('Разрешите уведомления для получения push-сообщений', 'warning');
            }
            return false;
        }
    }
    
    try {
        const registration = await navigator.serviceWorker.ready;
        logDebug('[PUSH] Service Worker ready');
        
        const vapidPublicKey = window.vapidPublicKey || '';
        if (!vapidPublicKey) {
            logDebug('[PUSH] ❌ VAPID public key missing');
            if (typeof showToast === 'function') {
                showToast('Система не настроена для push-уведомлений', 'error');
            }
            return false;
        }
        
        const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);
        
        let subscription = await registration.pushManager.getSubscription();
        
        if (subscription) {
            logDebug('[PUSH] Existing subscription found, checking if valid');
            // Проверяем, истекла ли подписка
            if (subscription.expirationTime && subscription.expirationTime < Date.now()) {
                logDebug('[PUSH] Subscription expired, unsubscribing');
                await subscription.unsubscribe();
                subscription = null;
            } else {
                logDebug('[PUSH] Subscription is still valid');
                if (typeof showToast === 'function') {
                    showToast('Push-уведомления уже активны', 'success');
                }
                return true;
            }
        }
        
        logDebug('[PUSH] Creating new subscription...');
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey
        });
        
        logDebug('[PUSH] ✅ Subscription created');
        
        const saved = await savePushSubscription(subscription);
        
        if (saved) {
            logDebug('[PUSH] ✅ Saved on server');
            if (typeof showToast === 'function') {
                showToast('✅ Push-уведомления включены!', 'success');
            }
            return true;
        } else {
            logDebug('[PUSH] ❌ Failed to save');
            return false;
        }
        
    } catch (error) {
        logDebug('[PUSH] ❌ Error:', error.message);
        if (typeof showToast === 'function') {
            showToast('Ошибка подписки: ' + error.message, 'error');
        }
        return false;
    }
}

// Сохранение подписки на сервере
async function savePushSubscription(subscription) {
    logDebug('[PUSH] Saving subscription...');
    
    const subscriptionData = {
        endpoint: subscription.endpoint,
        expirationTime: subscription.expirationTime,
        keys: {
            p256dh: arrayBufferToBase64(subscription.getKey('p256dh')),
            auth: arrayBufferToBase64(subscription.getKey('auth'))
        }
    };
    
    try {
        const response = await fetch(window.APP_BASE + '/save_push_subscription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                subscription: subscriptionData,
                csrf_token: window.csrfToken || ''
            })
        });
        
        const data = await response.json();
        logDebug('[PUSH] Server response:', data);
        return data.success === true;
        
    } catch (error) {
        logDebug('[PUSH] Save error:', error.message);
        return false;
    }
}

// Конвертация ArrayBuffer в base64
function arrayBufferToBase64(buffer) {
    if (!buffer) return '';
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

// Отписка от push-уведомлений
async function unsubscribeFromPushNotifications() {
    logDebug('[PUSH] Unsubscribing...');
    
    if (!isPushSupported()) return false;
    
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        
        if (!subscription) {
            logDebug('[PUSH] No active subscription');
            return true;
        }
        
        await subscription.unsubscribe();
        
        await fetch(window.APP_BASE + '/delete_push_subscription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                csrf_token: window.csrfToken || ''
            })
        });
        
        logDebug('[PUSH] ✅ Unsubscribed');
        if (typeof showToast === 'function') {
            showToast('✅ Push-уведомления отключены', 'success');
        }
        return true;
        
    } catch (error) {
        logDebug('[PUSH] Unsubscribe error:', error.message);
        return false;
    }
}

// Проверка статуса подписки
async function checkPushSubscriptionStatus() {
    if (!isPushSupported()) return { subscribed: false, supported: false };
    
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return { subscribed: !!subscription, supported: true };
    } catch (error) {
        return { subscribed: false, supported: true, error: error.message };
    }
}

// Автоматическая подписка
// ==================== BLOCK START: Fixed autoSubscribePush with DB check v1.1 ====================
async function autoSubscribePush() {
    // ✅ Проверяем, авторизован ли пользователь
    if (!window.currentUserUuid || window.currentUserUuid === '') {
        logDebug('[PUSH] User not logged in, skipping auto-subscribe');
        return;
    }

    const done = localStorage.getItem('push_auto_subscribe_done');
    
    // Проверяем поддержку
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        logDebug('[PUSH] Push not supported');
        return;
    }
    
    // Проверяем разрешение на уведомления
    if (Notification.permission !== 'granted') {
        logDebug('[PUSH] Notification permission not granted, requesting...');
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            logDebug('[PUSH] Permission denied');
            return;
        }
    }
    
    try {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        
        if (!subscription) {
            logDebug('[PUSH] Creating new subscription...');
            
            const vapidPublicKey = window.vapidPublicKey || '';
            if (!vapidPublicKey) {
                logDebug('[PUSH] No VAPID public key');
                return;
            }
            
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
            
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
            });
            logDebug('[PUSH] Subscription created');
        }
        
        // ========== ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: есть ли подписка в БД? ==========
        // Даже если localStorage говорит "done", проверяем реальное наличие в БД
        let needSave = true;
        
        if (done === 'true') {
            logDebug('[PUSH] LocalStorage flag is set, verifying with server...');
            
            // Проверяем, существует ли подписка в БД
            const checkFormData = new URLSearchParams();
            checkFormData.append('ajax_mode', '1');
            checkFormData.append('csrf_token', window.csrfToken || '');
            checkFormData.append('check_existing', '1');
            checkFormData.append('endpoint', subscription.endpoint);
            
            const checkResponse = await fetch(window.APP_BASE + '/save_push_subscription.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: checkFormData
            });
            
            const checkText = await checkResponse.text();
            try {
                const checkData = JSON.parse(checkText);
                if (checkData.exists === true) {
                    needSave = false;
                    logDebug('[PUSH] Subscription already exists in database, skipping save');
                } else {
                    logDebug('[PUSH] Subscription NOT found in database, will re-save');
                }
            } catch(e) {
                logDebug('[PUSH] Could not verify DB status, will re-save');
            }
        }
        
        if (needSave) {
            // Сохраняем через FormData (рабочий способ)
            const formData = new URLSearchParams();
            formData.append('ajax_mode', '1');
            formData.append('csrf_token', window.csrfToken || '');
            formData.append('subscription', JSON.stringify({
                endpoint: subscription.endpoint,
                expirationTime: subscription.expirationTime,
                keys: {
                    p256dh: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('p256dh')))),
                    auth: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('auth'))))
                }
            }));
            
            const response = await fetch(window.APP_BASE + '/save_push_subscription.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });
            
            const text = await response.text();
            const data = JSON.parse(text);
            
            if (data.success) {
                logDebug('[PUSH] ✅ Subscription saved to server');
                localStorage.setItem('push_auto_subscribe_done', 'true');
            } else {
                logDebug('[PUSH] ❌ Failed to save:', data.error);
            }
        }
        
    } catch (error) {
        logDebug('[PUSH] Auto-subscribe error:', error.message);
    }
}
// ==================== BLOCK END: Fixed autoSubscribePush with DB check v1.1 ====================

// Экспорт в глобальную область
window.subscribeToPushNotifications = subscribeToPushNotifications;
window.unsubscribeFromPushNotifications = unsubscribeFromPushNotifications;
window.checkPushSubscriptionStatus = checkPushSubscriptionStatus;
window.autoSubscribePush = autoSubscribePush;

// Запуск авто-подписки через 5 секунд
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(autoSubscribePush, 5000);
    });
} else {
    setTimeout(autoSubscribePush, 5000);
}

// ПРИНУДИТЕЛЬНЫЙ ЗАПУСК ДЛЯ ОТЛАДКИ (УДАЛИТЬ ПОСЛЕ ТЕСТА)
setTimeout(function() {
    logDebug('[PUSH] Manual auto-subscribe trigger');
    autoSubscribePush();
}, 3000);

logDebug('[PUSH] Push module initialized');
</script>
<!-- ==================== BLOCK END: Push Subscription Functions v1.0.0 ==================== -->

<!-- Подключаем единый скрипт для просмотра файлов -->
<script src="<?= $appBase ?>/js/file_preview.js?v=<?= time() ?>" nonce="<?= CSP_NONCE ?>"></script>
</head>
<body>
<div class="top-bar">
    <div class="logo">
        <img src="<?= $appBase ?>/favicon.ico" alt="Logo" style="width: 24px; height: 24px; margin-right: 8px;">
        <span class="logo-text"><?= htmlspecialchars($system_name ?? 'ЗадаЧат') ?></span>
    </div>
    <div class="nav-links">
        <a href="<?= $appBase ?>/index.php" class="<?= $menuActive['home'] ? 'active' : '' ?>">
            🏠 Главная
        </a>
        <a href="<?= $appBase ?>/projects.php" class="<?= $menuActive['projects'] ? 'active' : '' ?>" style="position: relative;">
            📁 Проекты
            <span id="badge-projects" class="nav-badge" style="display:none;">0</span>
        </a>
        <a href="<?= $appBase ?>/messages.php" class="<?= $menuActive['messages'] ? 'active' : '' ?>" style="position: relative;">
            💬 Сообщения
            <span id="badge-messages" class="nav-badge" style="display:none;">0</span>
        </a>
        <a href="<?= $appBase ?>/files.php" class="<?= $menuActive['files'] ? 'active' : '' ?>" style="position: relative;">
            📎 Файлы
            <span id="badge-files" class="nav-badge" style="display:none;">0</span>
        </a>
        
        <!-- Форма поиска -->
        <form method="GET" action="<?= $appBase ?>/search.php" class="nav-search-form">
            <input type="text" name="q" class="nav-search-input" placeholder="🔍 Поиск..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <button type="submit" class="nav-search-btn">🔍</button>
        </form>
        
        <a href="<?= $appBase ?>/admin.php" class="<?= $menuActive['admin'] ? 'active' : '' ?>">⚙️ Админка</a>
    </div>
    <div class="user-menu">
        <!-- Колокольчик уведомлений (для выпадающего списка) -->
        <div class="notification-bell" id="notificationBell" onclick="toggleNotificationDropdown()">
            🔔
            <span id="notificationBadge" class="notification-badge" style="display:none;">0</span>
        </div>
        
        <div class="user-name">👤 <?= htmlspecialchars($current_user_login_display) ?></div>
        <form method="post" action="<?= $appBase ?>/logout.php" style="display:inline;" onsubmit="return confirm('Выйти из системы?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <button type="submit" class="logout-btn">🚪 Выйти</button>
        </form>
    </div>
</div>

<!-- Выпадающее меню уведомлений -->
<div id="notificationDropdown" style="display:none; position:fixed; top:60px; right:20px; width:320px; max-height:400px; overflow-y:auto; background:#121a33; border:1px solid rgba(255,255,255,0.1); border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.5); z-index:1000;">
    <div style="padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.1); font-weight:600;">🔔 Уведомления</div>
    <div id="notificationList" style="padding:8px 0;">
        <div style="padding:12px; text-align:center; color:rgba(233,238,252,0.5);">Загрузка...</div>
    </div>
    <div style="padding:10px 16px; border-top:1px solid rgba(255,255,255,0.1); text-align:center;">
        <button class="btn-secondary" style="width:100%; padding:6px; font-size:12px;" onclick="markAllNotificationsRead()">Отметить все прочитанными</button>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">

// ==================== BLOCK START: Badges Initialization v3.0 ====================
// ver.3.0 - Инициализация бейджей при загрузке страницы
// Добавить в существующий блок скриптов в header_internal.php

// Функция начальной загрузки бейджей
function initBadges() {
    // ✅ Проверяем авторизацию
    if (!window.currentUserUuid || window.currentUserUuid === '') {
        logDebug('[BADGES] User not logged in, skipping badges init');
        return;
    }
    
    logDebug('[BADGES_INIT] Initializing badges');
    
    var csrfToken = window.csrfToken || '';
    var url = window.APP_BASE + '/get_badges_data.php';
    
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(function(data) {
        if (data.success && data.badges) {
            logDebug('[BADGES_INIT] Badges data loaded:', data.badges);
            
            var messagesBadge = document.getElementById('badge-messages');
            if (messagesBadge) {
                if (data.badges.messages > 0) {
                    messagesBadge.textContent = data.badges.messages > 99 ? '99+' : data.badges.messages;
                    messagesBadge.style.display = 'inline-block';
                } else {
                    messagesBadge.style.display = 'none';
                }
            }
            
            var projectsBadge = document.getElementById('badge-projects');
            if (projectsBadge) {
                if (data.badges.projects > 0) {
                    projectsBadge.textContent = data.badges.projects > 99 ? '99+' : data.badges.projects;
                    projectsBadge.style.display = 'inline-block';
                } else {
                    projectsBadge.style.display = 'none';
                }
            }
            
            var filesBadge = document.getElementById('badge-files');
            if (filesBadge) {
                if (data.badges.files > 0) {
                    filesBadge.textContent = data.badges.files > 99 ? '99+' : data.badges.files;
                    filesBadge.style.display = 'inline-block';
                } else {
                    filesBadge.style.display = 'none';
                }
            }
            
            if (typeof window.updateNotificationBadge === 'function') {
                window.updateNotificationBadge(data.badges.notifications);
            } else {
                var bellBadge = document.getElementById('notificationBadge');
                if (bellBadge) {
                    if (data.badges.notifications > 0) {
                        bellBadge.textContent = data.badges.notifications > 99 ? '99+' : data.badges.notifications;
                        bellBadge.style.display = 'inline-block';
                    } else {
                        bellBadge.style.display = 'none';
                    }
                }
            }
        }
    })
    .catch(function(err) {
        logError('[BADGES_INIT] Error:', err.message);
    });
}

// Запускаем инициализацию после загрузки DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBadges);
} else {
    initBadges();
}

// Экспортируем функции для использования из других скриптов
window.updateAllBadges = function() {
    if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
        window.SSE.updateAllBadges();
    } else {
        logDebug('[BADGES] SSE not ready, using fallback');
        initBadges();
    }
};
// ==================== BLOCK END: Badges Initialization v3.0 ====================

// ========== ФУНКЦИИ ДЛЯ РАБОТЫ С УВЕДОМЛЕНИЯМИ ==========
function toggleNotificationDropdown() {
    var dropdown = document.getElementById('notificationDropdown');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        loadNotifications();
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function loadNotifications() {
    var list = document.getElementById('notificationList');
    if (!list) return;
    
    list.innerHTML = '<div style="padding:12px; text-align:center; color:rgba(233,238,252,0.5);">⏳ Загрузка...</div>';
    
    fetch(window.APP_BASE + '/dashboard_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_updates&csrf_token=' + encodeURIComponent(window.csrfToken)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success && data.notifications && data.notifications.length > 0) {
            var html = '';
            for (var i = 0; i < data.notifications.length; i++) {
                var n = data.notifications[i];
                var nData = n.data || {};
                var title = '';
                var link = '#';
                
                if (n.type === 'task_changed') {
                    title = '✏️ Изменена задача: ' + (nData.task_title || '');
                    link = '<?= $appBase ?>/projects.php?task=' + n.task_uuid;
                } else if (nData.is_new) {
                    title = '📋 Новая задача: ' + (nData.task_title || '');
                    link = '<?= $appBase ?>/projects.php?task=' + n.task_uuid;
                } else {
                    title = '📋 Задача: ' + (nData.task_title || '');
                    link = '<?= $appBase ?>/projects.php?task=' + n.task_uuid;
                }
                
                html += '<div class="notification-item" data-uuid="' + n.uuid + '" onclick="markNotificationRead(\'' + n.uuid + '\', \'' + link + '\')" style="padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.05); cursor:pointer; transition:background 0.2s;">';
                html += '<div style="font-size:13px; font-weight:500;">' + escapeHtmlStatic(title) + '</div>';
                html += '<div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">' + new Date(n.created_at).toLocaleString() + '</div>';
                html += '</div>';
            }
            list.innerHTML = html;
        } else {
            list.innerHTML = '<div style="padding:12px; text-align:center; color:rgba(233,238,252,0.5);">📭 Нет уведомлений</div>';
        }
    })
    .catch(function(e) {
        list.innerHTML = '<div style="padding:12px; text-align:center; color:#f87171;">⚠️ Ошибка загрузки</div>';
    });
}

function markNotificationRead(uuid, link) {
    if (link && link !== '#') {
        if (window.csrfToken) {
            fetch(window.APP_BASE + '/dashboard_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_notification_read&uuid=' + encodeURIComponent(uuid) + '&csrf_token=' + encodeURIComponent(window.csrfToken)
            }).catch(function(e) {});
        }
        window.location.href = link;
        return;
    }
    
    if (!window.csrfToken) return;
    
    fetch(window.APP_BASE + '/dashboard_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_notification_read&uuid=' + encodeURIComponent(uuid) + '&csrf_token=' + encodeURIComponent(window.csrfToken)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            var item = document.querySelector('.notification-item[data-uuid="' + uuid + '"]');
            if (item) item.style.opacity = '0.5';
        }
    })
    .catch(function(e) {});
}

function markAllNotificationsRead() {
    if (!window.csrfToken) {
        logError('[NOTIFY] CSRF token not found');
        showCustomToast('Ошибка безопасности', 'error');
        return;
    }
    
    logDebug('[NOTIFY] markAllNotificationsRead called - resetting ALL badges');
    
    var btn = document.querySelector('#notificationDropdown .btn-secondary');
    var originalText = btn ? btn.innerHTML : 'Отметить все прочитанными';
    if (btn) {
        btn.innerHTML = '⏳...';
        btn.disabled = true;
    }
    
    // 1. Сначала сбрасываем уведомления
    fetch(window.APP_BASE + '/dashboard_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_notifications_read&csrf_token=' + encodeURIComponent(window.csrfToken)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Обновляем список уведомлений, если дропдаун открыт
            if (document.getElementById('notificationDropdown').style.display === 'block') {
                loadNotifications();
            }
            
            // Сбрасываем бейдж колокольчика
            var bellBadge = document.getElementById('notificationBadge');
            if (bellBadge) bellBadge.style.display = 'none';
            
            logDebug('[NOTIFY] Notifications cleared, now resetting message badges');
            
            // 2. ✅ ДОБАВЛЯЕМ: Сбрасываем бейджи сообщений, проектов, файлов
            // Отправляем запрос на mark_read, который обновляет time_last_dashboard_view
            return fetch(window.APP_BASE + '/dashboard_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&csrf_token=' + encodeURIComponent(window.csrfToken)
            });
        }
        throw new Error(data.error || 'Ошибка при сбросе уведомлений');
    })
    .then(function(response) { 
        if (!response) return { success: true };
        return response.json(); 
    })
    .then(function(data) {
        if (data && data.success === false) {
            throw new Error(data.error || 'Ошибка при сбросе бейджей');
        }
        
        logDebug('[NOTIFY] All badges reset successfully');
        showCustomToast('✓ Все уведомления и бейджи сброшены', 'success');
        
        // 3. Обновляем ВСЕ бейджи через API (для синхронизации)
        setTimeout(function() {
            if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
                window.SSE.updateAllBadges();
            } else if (typeof window.updateAllBadges === 'function') {
                window.updateAllBadges();
            } else if (typeof initBadges === 'function') {
                initBadges();
            }
            
            // Дополнительно сбрасываем бейджи в DOM напрямую (для мгновенного эффекта)
            var messagesBadge = document.getElementById('badge-messages');
            if (messagesBadge) messagesBadge.style.display = 'none';
            
            var projectsBadge = document.getElementById('badge-projects');
            if (projectsBadge) projectsBadge.style.display = 'none';
            
            var filesBadge = document.getElementById('badge-files');
            if (filesBadge) filesBadge.style.display = 'none';
            
            // Обновляем дашборд если он открыт
            if (typeof window.refreshDashboard === 'function') {
                setTimeout(function() { window.refreshDashboard(); }, 500);
            }
        }, 300);
    })
    .catch(function(e) {
        logError('[NOTIFY] markAllNotificationsRead error:', e);
        showCustomToast('Ошибка: ' + e.message, 'error');
    })
    .finally(function() {
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    });
}

// Добавьте вспомогательную функцию для тостов, если её нет
function showCustomToast(message, type) {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + 
        (type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981')) + 
        '; color:white; padding:10px 20px; border-radius:8px; z-index:10001; font-size:14px; animation:fadeInOut 2s ease;';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 2000);
}

// Добавьте стили для тоста, если их нет
if (!document.querySelector('#toastFadeStyle')) {
    var toastStyle = document.createElement('style');
    toastStyle.id = 'toastFadeStyle';
    toastStyle.textContent = '@keyframes fadeInOut { 0% { opacity: 0; transform: translateY(20px); } 10% { opacity: 1; transform: translateY(0); } 90% { opacity: 1; transform: translateY(0); } 100% { opacity: 0; transform: translateY(20px); } }';
    document.head.appendChild(toastStyle);
}

// Закрытие дропдауна при клике вне
document.addEventListener('click', function(e) {
    var bell = document.getElementById('notificationBell');
    var dropdown = document.getElementById('notificationDropdown');
    if (dropdown && bell && !bell.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});


// Глобальная функция для обновления бейджа уведомлений
window.updateNotificationBadge = function(count) {
    var badge = document.getElementById('notificationBadge');
    if (badge) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }
};


// ========== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==========
function escapeHtmlStatic(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========== ОБРАБОТКА URL-ЯКОРЯ ==========
function handleHashScroll() {
    if (window.location.hash) {
        var targetId = window.location.hash.substring(1);
        var targetElement = document.getElementById(targetId);
        if (targetElement) {
            setTimeout(function() {
                var offset = 80;
                var elementPosition = targetElement.getBoundingClientRect().top;
                var offsetPosition = elementPosition + window.pageYOffset - offset;
                window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                
                targetElement.classList.add('block-highlight');
                setTimeout(function() {
                    targetElement.classList.remove('block-highlight');
                }, 1500);
            }, 300);
        }
    }
}

// Добавляем обработчик изменения хэша
window.addEventListener('hashchange', handleHashScroll);
document.addEventListener('DOMContentLoaded', handleHashScroll);
</script>

<!-- Глобальный контейнер для всплывающих уведомлений -->
<div id="toastContainer" style="position:fixed; bottom:20px; right:20px; z-index:10000;"></div>

<style>
.notification-item:hover {
    background: rgba(79,124,255,0.1);
}
.block-highlight {
    transition: box-shadow 0.3s ease;
    box-shadow: 0 0 0 2px #4f7cff;
    border-radius: 16px;
}


/* Стили для бейджей в навигации - с повышенным приоритетом */
.nav-badge {
    position: absolute !important;
    top: -8px !important;
    right: -12px !important;
    background: #ef4444 !important;
    color: white !important;
    font-size: 10px !important;
    font-weight: bold !important;
    min-width: 18px !important;
    height: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
    border-radius: 50% !important;
    padding: 0 4px !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2) !important;
    transition: transform 0.2s ease !important;
    z-index: 100 !important;
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif !important;
    box-sizing: border-box !important;
}

.nav-badge:hover {
    transform: scale(1.05) !important;
}

/* Адаптация для мобильных устройств */
@media (max-width: 768px) {
    .nav-badge {
        top: -6px !important;
        right: -10px !important;
        min-width: 16px !important;
        height: 16px !important;
        line-height: 16px !important;
        font-size: 9px !important;
    }
}

/* Дополнительная гарантия, что бейдж будет красным */
a[style*="position: relative"] .nav-badge,
.nav-links a .nav-badge {
    background: #ef4444 !important;
    color: white !important;
}

/* Убедимся, что бейдж не перекрывается другими элементами */
.nav-links a {
    position: relative !important;
}
</style>

