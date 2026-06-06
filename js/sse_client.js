// /js/sse_client.js - Версия 3.9.0
// v3.6.0 (2025-05-16) - Исправлено удаление сообщений у собеседника: добавлено обновление lastMessageTime и бейджа
// v3.5.0 (2025-05-16) - Исправлены desktop-уведомления, удаление сообщений у собеседника, прокрутка к последнему сообщению
// v3.4.2 (2025-05-16) - Исправлены функции логирования: теперь context выводится только если передан
// v3.4.1 (2025-05-16) - Исправлен AudioContext: ошибка "not allowed to start" больше не появляется
// v3.3.0 (2025-05-15) - Звук только для новых непрочитанных сообщений, автоматический реконнект
// v3.7.0 (2025-05-21) - ДОБАВЛЕНО: проверка assigned_to_uuid перед уведомлением о новой задаче, 
//                        вывод имени проекта в тосте, расширенное логирование
// v3.8.0 (2025-05-21) - ДОБАВЛЕНО: поддержка подписок task_subscribers, пользователь получает уведомления 
//                        о задачах, на которые он подписан (даже если не исполнитель)
// v3.9.0 (2025-05-22) - ИСПРАВЛЕНО: тостовые уведомления о новых сообщениях приходят ВСЕГДА (даже при открытом чате),
//                        клик по тосту открывает нужный чат и обновляет его без перезагрузки страницы
// v3.23.0 (2026-05-31) - ИСПРАВЛЕНИЕ: единая глобальная переменная soundIntervalSec
//                        - Восстановлена глобальная переменная soundIntervalSec
//                        - updateSoundSettings обновляет глобальную переменную
//                        - ВСЕ функции используют ТОЛЬКО глобальную soundIntervalSec
//                        - Удалены дублирующиеся локальные переменные

(function() {
'use strict';

// ==================== БЕЗОПАСНЫЕ ОБЁРТКИ ДЛЯ ЛОГГИРОВАНИЯ ====================
function safeLogDebug(message, context) {
    if (typeof window.logDebug === 'function') {
        if (context !== undefined && context !== null) {
            window.logDebug(message, context);
        } else {
            window.logDebug(message);
        }
    } else if (typeof console !== 'undefined' && console.log) {
        if (context !== undefined && context !== null) {
            console.log('[SSE] ' + message, context);
        } else {
            console.log('[SSE] ' + message);
        }
    }
}

function safeLogWarning(message, context) {
    if (typeof window.logWarning === 'function') {
        if (context !== undefined && context !== null) {
            window.logWarning(message, context);
        } else {
            window.logWarning(message);
        }
    } else if (typeof console !== 'undefined' && console.warn) {
        if (context !== undefined && context !== null) {
            console.warn('[SSE] ' + message, context);
        } else {
            console.warn('[SSE] ' + message);
        }
    }
}

function safeLogError(message, context) {
    if (typeof window.logError === 'function') {
        if (context !== undefined && context !== null) {
            window.logError(message, context);
        } else {
            window.logError(message);
        }
    } else if (typeof console !== 'undefined' && console.error) {
        if (context !== undefined && context !== null) {
            console.error('[SSE] ' + message, context);
        } else {
            console.error('[SSE] ' + message);
        }
    }
}

// Для краткости - алиасы
var logDebug = function(message, context) {
    if (context !== undefined && context !== null) {
        safeLogDebug(message, context);
    } else {
        safeLogDebug(message);
    }
};

var logWarning = function(message, context) {
    if (context !== undefined && context !== null) {
        safeLogWarning(message, context);
    } else {
        safeLogWarning(message);
    }
};

var logError = function(message, context) {
    if (context !== undefined && context !== null) {
        safeLogError(message, context);
    } else {
        safeLogError(message);
    }
};

// ==================== СОСТОЯНИЕ ====================
var eventSource = null;
var reconnectTimer = null;
var pingTimeout = null;
var heartbeatCheckTimer = null;
var reconnectAttempts = 0;
var MAX_RECONNECT_ATTEMPTS = 50;
var CONSECUTIVE_ERRORS = 0;
var currentUserUuid = null;
var currentWatchingTaskUuid = null;


var sseOriginalTitle = document.title;
var lastPingTime = 0;
var isManuallyDisconnected = false;
var isFirstConnection = true;

// ==================== ГЛОБАЛЬНЫЕ НАСТРОЙКИ ЗВУКА (единый источник) ====================
var lastToastTime = 0;
var soundEnabled = true;
var soundIntervalSec = 600; // будет перезаписано из настроек пользователя через updateSoundSettings
var notificationPermission = false;
var lastSoundTime = 0; // для проверки интервала


// Кэш подписок пользователя
var userSubscriptionsCache = {};
var subscriptionsCacheTimestamp = 0;
var SUBSCRIPTIONS_CACHE_TTL = 60000; // 1 минута

window._pageLoadTime = Date.now();



if (typeof window.currentUserUuid !== 'undefined') {
    currentUserUuid = window.currentUserUuid;
}

// ==================== ТАЙМАУТЫ КОНФИГУРАЦИЯ ====================
var PING_TIMEOUT = 45000;
var RECONNECT_BASE_DELAY = 1000;
var RECONNECT_MAX_DELAY = 10000;
var HEARTBEAT_INTERVAL = 5000;

// Добавьте в начало sse_client.js, после объявления переменных:

// ==================== ДЕДУПЛИКАЦИЯ УВЕДОМЛЕНИЙ ====================
var notifiedMessageIds = {};
var NOTIFICATION_CLEANUP_INTERVAL = 10000; // 10 секунд

// Функция очистки старых записей о уведомлениях
function cleanupNotifiedMessages() {
    var now = Date.now();
    for (var id in notifiedMessageIds) {
        if (notifiedMessageIds[id] && (now - notifiedMessageIds[id]) > NOTIFICATION_CLEANUP_INTERVAL) {
            delete notifiedMessageIds[id];
        }
    }
}

// Периодическая очистка
setInterval(cleanupNotifiedMessages, NOTIFICATION_CLEANUP_INTERVAL);

// Функция проверки, было ли уже уведомление для этого сообщения
function wasMessageNotified(messageUuid) {
    if (notifiedMessageIds[messageUuid]) {
        logDebug('[SSE_DEDUP] Notification already sent for message:', messageUuid);
        return true;
    }
    notifiedMessageIds[messageUuid] = Date.now();
    return false;
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
// ==================== BLOCK START: User activity tracking v1.0 ====================
// ver.1.0 - Отслеживание активности пользователя для определения "неактивности"
// - Сохраняет время последнего действия в sessionStorage
// - Используется для показа уведомлений в текущем чате при неактивности

function initUserActivityTracking() {
    if (window.userSettings) {
        soundEnabled = window.userSettings.soundEnabled === true;
        soundIntervalSec = window.userSettings.soundIntervalSec || 600;
        logDebug('[SOUND] Initialized from userSettings: enabled=' + soundEnabled + ', intervalSec=' + soundIntervalSec);
    }
    // Инициализируем время последней активности, если ещё не установлено
    if (!sessionStorage.getItem('sse_last_user_activity')) {
        sessionStorage.setItem('sse_last_user_activity', Date.now().toString());
        logDebug('[ACTIVITY] Initialized last activity time');
    }
    
    // Функция обновления времени активности
    function updateActivityTime() {
        sessionStorage.setItem('sse_last_user_activity', Date.now().toString());
    }
    
    // Навешиваем обработчики на события, указывающие на активность пользователя
    var activityEvents = ['mousedown', 'keydown', 'touchstart', 'scroll', 'click', 'mousemove'];
    var alreadyAttached = window._activityTrackingAttached;
    
    if (!alreadyAttached) {
        activityEvents.forEach(function(eventName) {
            document.addEventListener(eventName, updateActivityTime);
        });
        window.addEventListener('focus', updateActivityTime);
        window._activityTrackingAttached = true;
        logDebug('[ACTIVITY] Activity tracking handlers attached');
    }
    
    // Периодически обновляем время активности, если страница видима (для фоновых вкладок)
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            var lastActivity = parseInt(sessionStorage.getItem('sse_last_user_activity') || '0', 10);
            var now = Date.now();
            if (now - lastActivity > 30000) {
                updateActivityTime();
                logDebug('[ACTIVITY] Periodic activity update (page visible)');
            }
        }
    }, 30000);
}

// Запускаем при загрузке
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUserActivityTracking);
} else {
    initUserActivityTracking();
}
// ==================== BLOCK END: User activity tracking v1.0 ====================


// ==================== ЗВУК (единый источник через глобальные переменные) ====================

// Единая функция проверки возможности воспроизведения звука
function canPlaySound() {
    // Всегда читаем из глобальной переменной
    if (!soundEnabled) {
        logDebug('[SOUND] Disabled by user settings (soundEnabled=false)');
        return false;
    }
    
    var now = Date.now();
    var intervalMs = Math.max(6000, soundIntervalSec * 1000);
    var lastSoundTimeSaved = parseInt(sessionStorage.getItem('sse_last_sound_time') || '0', 10);
    
    if (now - lastSoundTimeSaved < intervalMs) {
        logDebug('[SOUND] Skipped, interval not passed: ' + (now - lastSoundTimeSaved) + 'ms < ' + intervalMs + 'ms');
        return false;
    }
    
    // Запоминаем время воспроизведения
    sessionStorage.setItem('sse_last_sound_time', now.toString());
    logDebug('[SOUND] ✅ Allowed, interval: ' + intervalMs + 'ms, soundIntervalSec: ' + soundIntervalSec);
    return true;
}

// Функция воспроизведения (без дублирования настроек)
function playNotificationSound() {
    if (!canPlaySound()) return;
    
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var oscillator = ctx.createOscillator();
        var gainNode = ctx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        oscillator.frequency.value = 600;
        gainNode.gain.value = 0.1;
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.4);
        oscillator.stop(ctx.currentTime + 0.4);
        logDebug('[SOUND] Sound played successfully');
    } catch(e) {
        logError('[SOUND] Play error:', e);
    }
}

// Обновление настроек извне (например, из admin.php)
function updateSoundSettings(enabled, intervalSec) {
    soundEnabled = enabled;
    soundIntervalSec = intervalSec;  // ← ЭТА СТРОКА ДОЛЖНА БЫТЬ!
    sessionStorage.setItem('sse_sound_enabled', enabled ? '1' : '0');
    sessionStorage.setItem('sse_sound_interval', intervalSec);
    logDebug('[SOUND] Settings updated: enabled=' + enabled + ', intervalSec=' + intervalSec);
    
    if (window.userSettings) {
        window.userSettings.soundEnabled = enabled;
        window.userSettings.soundIntervalSec = intervalSec;
    }
}

// Экспорт
window.canPlaySound = canPlaySound;
window.playNotificationSound = playNotificationSound;
window.updateSoundSettings = updateSoundSettings;

function getCurrentTaskUuid() {
    var taskInput = document.getElementById('current-task-uuid');
    return (taskInput && taskInput.value) ? taskInput.value : null;
}

if (typeof window.escapeHtml !== 'function') {
    window.escapeHtml = function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };
}

// ==================== BLOCK START: SSE Badges Update Functions v3.11.0 ====================
// ver.3.10.0 (2026-05-26) - ДОБАВЛЕНА система отдельных бейджей
// ver.3.11.0 (2026-05-27) - Добавлена поддержка мобильного бейджа на гамбургере

// ========== 1. БАЗОВАЯ ФУНКЦИЯ ОБНОВЛЕНИЯ ВСЕХ БЕЙДЖЕЙ ==========
function updateAllBadges() {
    logDebug('[SSE_BADGES] updateAllBadges called');
    
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
            logDebug('[SSE_BADGES] Badges data received:', data.badges);
            
            updateSingleBadge('badge-messages', data.badges.messages);
            updateSingleBadge('badge-projects', data.badges.projects);
            updateSingleBadge('badge-files', data.badges.files);
            
            if (typeof window.updateNotificationBadge === 'function') {
                window.updateNotificationBadge(data.badges.notifications);
            } else {
                updateNotificationBadgeInternal(data.badges.notifications);
            }
            
            // v3.11.0: Обновляем бейдж на мобильной кнопке-гамбургере
            if (typeof window.updateMobileDrawerBadge === 'function') {
                setTimeout(function() {
                    window.updateMobileDrawerBadge();
                    logDebug('[SSE_BADGES] Mobile drawer badge updated');
                }, 300);
            }
        } else {
            logDebug('[SSE_BADGES] Failed to get badges data');
        }
    })
    .catch(function(err) {
        logError('[SSE_BADGES] Error fetching badges:', err.message);
    });
}

// ========== 2. ФУНКЦИЯ ОБНОВЛЕНИЯ ОДНОГО БЕЙДЖА ==========
function updateSingleBadge(elementId, count) {
    var badge = document.getElementById(elementId);
    if (badge) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-block';
            logDebug('[BADGES] Updated ' + elementId + ' to ' + count);
        } else {
            badge.style.display = 'none';
            logDebug('[BADGES] Hidden ' + elementId);
        }
    }
}

// ========== 3. ВНУТРЕННЯЯ ФУНКЦИЯ ДЛЯ КОЛОКОЛЬЧИКА ==========
function updateNotificationBadgeInternal(count) {
    var bellBadge = document.getElementById('notificationBadge');
    if (bellBadge) {
        if (count > 0) {
            bellBadge.textContent = count > 99 ? '99+' : count;
            bellBadge.style.display = 'inline-block';
        } else {
            bellBadge.style.display = 'none';
        }
    }
}

// ========== 4. ФУНКЦИИ-ТРИГГЕРЫ ДЛЯ РАЗНЫХ СОБЫТИЙ ==========
function triggerBadgeUpdateOnNewMessages() {
    logDebug('[BADGES] New messages received, updating badges');
    updateAllBadges();
}

function triggerBadgeUpdateOnNewTasks() {
    logDebug('[BADGES] New tasks received, updating badges');
    updateAllBadges();
}

function triggerBadgeUpdateOnNewFiles() {
    logDebug('[BADGES] New files received, updating badges');
    updateAllBadges();
}

function triggerBadgeUpdateOnMessageDelete() {
    logDebug('[BADGES] Message deleted, updating badges');
    updateAllBadges();
}

// ========== 5. ЭКСПОРТ В ГЛОБАЛЬНУЮ ОБЛАСТЬ ==========
window.updateAllBadges = updateAllBadges;
// ==================== BLOCK END: SSE Badges Update Functions v3.11.0 ====================




// ==================== ОБНОВЛЕНИЕ БЕЙДЖА ИЗ DOM ====================
function updateUnreadBadgeFromDOM() {
    var unreadMessages = document.querySelectorAll('.message.unread:not(.own)').length;
    updateAllBadges();
    return unreadMessages;
}

// Экспортируем для использования из других скриптов
window.updateUnreadBadge = updateUnreadBadgeFromDOM;

// ==================== ФУНКЦИЯ ВЫЧИСЛЕНИЯ ЗАДЕРЖКИ ====================
function getReconnectDelay() {
    var delay = RECONNECT_BASE_DELAY * Math.pow(1.5, Math.min(reconnectAttempts, 10));
    return Math.min(delay, RECONNECT_MAX_DELAY);
}

// ==================== СБРОС ТАЙМЕРОВ ====================
function clearAllTimers() {
    if (reconnectTimer) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
    if (pingTimeout) {
        clearTimeout(pingTimeout);
        pingTimeout = null;
    }
    if (heartbeatCheckTimer) {
        clearInterval(heartbeatCheckTimer);
        heartbeatCheckTimer = null;
    }
}

// ==================== ПРОВЕРКА СЕРДЦЕБИЕНИЯ ====================
function startHeartbeatCheck() {
    if (heartbeatCheckTimer) {
        clearInterval(heartbeatCheckTimer);
    }
    lastPingTime = Date.now();
    heartbeatCheckTimer = setInterval(function() {
        var timeSinceLastPing = Date.now() - lastPingTime;
        if (timeSinceLastPing > PING_TIMEOUT) {
            logWarning('[SSE] Heartbeat check: no ping for ' + Math.round(timeSinceLastPing / 1000) + 's, reconnecting...');
            reconnect();
        }
    }, HEARTBEAT_INTERVAL);
}

function stopHeartbeatCheck() {
    if (heartbeatCheckTimer) {
        clearInterval(heartbeatCheckTimer);
        heartbeatCheckTimer = null;
    }
}

// ==================== ЗВУК И УВЕДОМЛЕНИЯ ====================
var audioContext = null;
var audioContextInitialized = false;

function getAudioContext() {
    if (audioContextInitialized && audioContext) {
        return audioContext;
    }
    
    try {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        audioContextInitialized = true;
        logDebug('[SSE] AudioContext created');
        return audioContext;
    } catch(e) {
        logError('[SSE] Failed to create AudioContext:', e);
        return null;
    }
}

function tryResumeAndPlay(playCallback) {
    var ctx = getAudioContext();
    if (!ctx) return;
    
    if (ctx.state === 'suspended') {
        logDebug('[SSE] AudioContext suspended, attempting to resume...');
        ctx.resume().then(function() {
            logDebug('[SSE] AudioContext resumed successfully, playing sound');
            if (playCallback) playCallback();
        }).catch(function(e) {
            logDebug('[SSE] AudioContext resume failed (user gesture required):', e.message);
        });
    } else if (ctx.state === 'running') {
        if (playCallback) playCallback();
    } else {
        logDebug('[SSE] AudioContext is closed, reinitializing...');
        audioContextInitialized = false;
        audioContext = null;
        tryResumeAndPlay(playCallback);
    }
}

function actuallyPlaySound() {
    try {
        var ctx = getAudioContext();
        if (!ctx) return;
        
        var oscillator = ctx.createOscillator();
        var gainNode = ctx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        oscillator.frequency.value = 600;
        gainNode.gain.value = 0.1;
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.4);
        oscillator.stop(ctx.currentTime + 0.4);
        logDebug('[SSE] Sound played successfully');
    } catch(e) {
        logError('[SSE] Error playing sound:', e);
    }
}


// ==================== BLOCK START: Mobile Browser Detection v1.1.0 ====================
// ver.1.1.0 (2026-05-28) - Добавлен DuckDuckGo Browser
// ver.1.0.0 - Базовая версия
// Поддерживает: Chrome, Safari, Firefox, Samsung Internet, Edge, Opera,
//               UC Browser, Brave, Vivaldi, Yandex, Huawei, MIUI, Puffin, Dolphin, Kiwi, Via,
//               DuckDuckGo Browser (Android и iOS)

function isMobileBrowser() {
    var ua = navigator.userAgent || navigator.vendor || window.opera || '';
    
    var mobileMarkers = [
        'Android',
        'webOS',
        'iPhone',
        'iPad',
        'iPod',
        'BlackBerry',
        'IEMobile',
        'Opera Mini',
        'Mobile',
        'mobile',
        'SamsungBrowser',
        'Firefox',
        'Opera',
        'OPR',
        'Edg',
        'Edge',
        'UCBrowser',
        'Brave',
        'Vivaldi',
        'YaBrowser',
        'HuaweiBrowser',
        'MiuiBrowser',
        'XiaoMi',
        'Puffin',
        'Dolphin',
        'Kiwi',
        'Via',
        'DuckDuckGo',
        'Ddg/'
    ];
    
    for (var i = 0; i < mobileMarkers.length; i++) {
        if (ua.indexOf(mobileMarkers[i]) !== -1) {
            logDebug('[MOBILE_DETECT] Detected as mobile via marker: ' + mobileMarkers[i]);
            return true;
        }
    }
    
    // Дополнительная проверка: ширина экрана (для планшетов в режиме десктопа)
    var isSmallScreen = window.innerWidth <= 768;
    if (isSmallScreen) {
        logDebug('[MOBILE_DETECT] Detected as mobile via screen width: ' + window.innerWidth);
        return true;
    }
    
    logDebug('[MOBILE_DETECT] Detected as desktop');
    return false;
}

// Проверка на iOS (для особой обработки)
function isIOSBrowser() {
    var ua = navigator.userAgent || navigator.vendor || window.opera || '';
    var iOSMarkers = ['iPhone', 'iPad', 'iPod'];
    
    for (var i = 0; i < iOSMarkers.length; i++) {
        if (ua.indexOf(iOSMarkers[i]) !== -1) {
            logDebug('[MOBILE_DETECT] Detected as iOS via marker: ' + iOSMarkers[i]);
            return true;
        }
    }
    return false;
}

// Проверка на DuckDuckGo Browser
function isDuckDuckGoBrowser() {
    var ua = navigator.userAgent || navigator.vendor || window.opera || '';
    var ddgMarkers = ['DuckDuckGo', 'Ddg/'];
    
    for (var i = 0; i < ddgMarkers.length; i++) {
        if (ua.indexOf(ddgMarkers[i]) !== -1) {
            logDebug('[MOBILE_DETECT] Detected as DuckDuckGo Browser via marker: ' + ddgMarkers[i]);
            return true;
        }
    }
    return false;
}

// Проверка на Android DuckDuckGo (поддерживает Service Worker)
function isDuckDuckGoAndroid() {
    if (!isDuckDuckGoBrowser()) return false;
    var ua = navigator.userAgent || '';
    return ua.indexOf('Android') !== -1;
}

// Проверка на iOS DuckDuckGo (НЕ поддерживает Service Worker в фоне)
function isDuckDuckGoIOS() {
    if (!isDuckDuckGoBrowser()) return false;
    var ua = navigator.userAgent || '';
    return ua.indexOf('iPhone') !== -1 || ua.indexOf('iPad') !== -1 || ua.indexOf('iPod') !== -1;
}

// Проверка на Android
function isAndroidBrowser() {
    var ua = navigator.userAgent || navigator.vendor || window.opera || '';
    return ua.indexOf('Android') !== -1;
}

// Экспортируем глобально
window.isMobileBrowser = isMobileBrowser;
window.isIOSBrowser = isIOSBrowser;
window.isAndroidBrowser = isAndroidBrowser;
window.isDuckDuckGoBrowser = isDuckDuckGoBrowser;
window.isDuckDuckGoAndroid = isDuckDuckGoAndroid;
window.isDuckDuckGoIOS = isDuckDuckGoIOS;

logDebug('[MOBILE_DETECT] Detection initialized - isMobile: ' + isMobileBrowser() + 
         ', isIOS: ' + isIOSBrowser() + 
         ', isAndroid: ' + isAndroidBrowser() +
         ', isDuckDuckGo: ' + isDuckDuckGoBrowser());
// ==================== BLOCK END: Mobile Browser Detection v1.1.0 ====================

function showDesktopNotification(title, body, link, taskUuid) {
    if (Notification.permission !== 'granted') {
        logDebug('[SSE_NOTIFY] Notification permission not granted, skipping');
        return;
    }
    
    var now = Date.now();
    if (now - lastToastTime < soundIntervalSec * 1000) return;
    lastToastTime = now;

    var maxLength = 150;
    if (body && body.length > maxLength) {
        body = body.substring(0, maxLength - 1) + '…';
    }
    
    var fullLink = link;
    if (window.APP_BASE && link && link.indexOf(window.APP_BASE) === -1 && !link.startsWith('http')) {
        var cleanLink = link.startsWith('/') ? link.substring(1) : link;
        fullLink = window.APP_BASE + '/' + cleanLink;
        logDebug('[SSE_NOTIFY] Fixed link: ' + link + ' -> ' + fullLink);
    }
    
    // Используем расширенное определение мобильного браузера
    var isMobile = false;
    if (typeof window.isMobileBrowser === 'function') {
        isMobile = window.isMobileBrowser();
    } else {
        isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|SamsungBrowser|Firefox|Edg|UCBrowser|YaBrowser/i.test(navigator.userAgent);
    }
    
    // Для мобильных устройств используем Service Worker вместо обычного Notification
    if (isMobile && 'serviceWorker' in navigator) {
        logDebug('[SSE_NOTIFY] Mobile device detected, using Service Worker notification');
        if (typeof showServiceWorkerNotification === 'function') {
            showServiceWorkerNotification(title, body, fullLink, taskUuid);
            return;
        }
    }
    
    // Для iOS специальная обработка (локальные уведомления не работают в фоне)
    var isIOS = false;
    if (typeof window.isIOSBrowser === 'function') {
        isIOS = window.isIOSBrowser();
    } else {
        isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    }
    
    if (isIOS && document.visibilityState !== 'visible') {
        logDebug('[SSE_NOTIFY] iOS in background, notification may not appear');
        // Показываем тост вместо уведомления на iOS в фоне
        if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
        return;
    }
    
    try {
        var notification = new Notification(title, { 
            body: body, 
            icon: (window.APP_BASE || '') + '/favicon.ico',
            silent: false,
            requireInteraction: false,
            data: {
                url: fullLink,
                taskUuid: taskUuid,
                appBase: window.APP_BASE || ''
            }
        });
        
        notification.onclick = function() {
            window.focus();
            if (taskUuid) {
                var fullTaskLink = window.APP_BASE + '/messages.php?task=' + taskUuid;
                window.location.href = fullTaskLink;
            } else if (fullLink) {
                window.location.href = fullLink;
            }
            notification.close();
        };
        
        setTimeout(function() { notification.close(); }, 8000);
        logDebug('[SSE_NOTIFY] Notification shown:', title, 'Link:', fullLink);
        
    } catch(e) {
        logError('[SSE_NOTIFY] Error showing notification:', e);
        // Fallback: показываем тост
        if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
    }
}




// ==================== BLOCK START: Service Worker Notifications v3.21.0 WITH DEDUP ====================
function showServiceWorkerNotification(title, body, link, taskUuid, messageUuid) {
    logDebug('[SW_NOTIFY] ========== START ==========');
    logDebug('[SW_NOTIFY] title:', title);
    logDebug('[SW_NOTIFY] body:', body);
    logDebug('[SW_NOTIFY] link:', link);
    logDebug('[SW_NOTIFY] taskUuid:', taskUuid);
    logDebug('[SW_NOTIFY] messageUuid:', messageUuid);
    
    // ========== ДЕДУПЛИКАЦИЯ ПО messageUuid ==========
    if (messageUuid && wasMessageNotified(messageUuid)) {
        logDebug('[SW_NOTIFY] ❌ Duplicate notification, skipping');
        return;
    }
    
    // ========== ФИЛЬТРАЦИЯ СВОИХ СООБЩЕНИЙ ==========
    if (messageUuid && window.currentUserUuid) {
        // Можно добавить проверку автора, если данные передаются
        if (window.lastMessageAuthor && window.lastMessageAuthor === window.currentUserUuid) {
            logDebug('[SW_NOTIFY] Skipping own message notification');
            return;
        }
    }
    
    var maxBodyLength = 120;
    if (body && body.length > maxBodyLength) {
        body = body.substring(0, maxBodyLength - 1) + '…';
    }

    logDebug('[SW_NOTIFY] soundEnabled:', soundEnabled, 'soundIntervalSec:', soundIntervalSec);
    
    // ========== ЗВУК ==========
    if (soundEnabled && canPlaySound()) {
        logDebug('[SW_NOTIFY] Playing sound for Service Worker notification');
        playNotificationSound();
        vibrateMobile();
    } else if (soundEnabled) {
        var now = Date.now();
        if (now - lastSoundTime >= soundIntervalSec * 1000) {
            lastSoundTime = now;
            logDebug('[SW_NOTIFY] Playing sound (direct check)');
            playNotificationSound();
            vibrateMobile();
        } else {
            logDebug('[SW_NOTIFY] Sound skipped (interval limit)');
        }
    } else {
        logDebug('[SW_NOTIFY] Sound skipped (soundEnabled=false)');
    }
    
    if (!('serviceWorker' in navigator)) {
        logDebug('[SW_NOTIFY] ❌ Service Worker not supported');
        if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
        return;
    }
    
    // Определяем DuckDuckGo Browser
    var isDDG = false;
    var isDDGiOS = false;
    
    if (typeof window.isDuckDuckGoBrowser === 'function') {
        isDDG = window.isDuckDuckGoBrowser();
        isDDGiOS = window.isDuckDuckGoIOS();
    } else {
        var ua = navigator.userAgent;
        isDDG = (ua.indexOf('DuckDuckGo') !== -1 || ua.indexOf('Ddg/') !== -1);
        isDDGiOS = isDDG && (ua.indexOf('iPhone') !== -1 || ua.indexOf('iPad') !== -1);
    }
    
    if (isDDGiOS && document.visibilityState !== 'visible') {
        logDebug('[SW_NOTIFY] ⚠️ DuckDuckGo iOS in background, using toast');
        if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
        return;
    }
    
    if (navigator.serviceWorker.controller) {
        logDebug('[SW_NOTIFY] Active controller found, sending postMessage');
        
        var messageData = {
            type: 'showNotification',
            title: title,
            body: body,
            icon: (window.APP_BASE || '') + '/favicon.ico',
            badge: (window.APP_BASE || '') + '/favicon.ico',
            url: link,
            notificationType: 'message',
            id: taskUuid,
            taskUuid: taskUuid,
            messageUuid: messageUuid,  // ← ДОБАВЛЕНО
            tag: 'msgql-' + (messageUuid || Date.now()),
            systemTitle: window.systemTitle || 'ЗадаЧат',
            appBase: window.APP_BASE || '',
            browser: isDDG ? 'DuckDuckGo' : 'unknown'
        };
        
        logDebug('[SW_NOTIFY] messageData prepared');
        navigator.serviceWorker.controller.postMessage(messageData);
        logDebug('[SW_NOTIFY] ✅ Message sent to Service Worker');
    } else {
        logDebug('[SW_NOTIFY] No controller, using registration.showNotification');
        navigator.serviceWorker.ready.then(function(registration) {
            registration.showNotification(title, {
                body: body,
                icon: (window.APP_BASE || '') + '/favicon.ico',
                badge: (window.APP_BASE || '') + '/favicon.ico',
                vibrate: [200, 100, 200],
                data: {
                    url: link,
                    type: 'message',
                    id: taskUuid,
                    taskUuid: taskUuid,
                    messageUuid: messageUuid,  // ← ДОБАВЛЕНО
                    appBase: window.APP_BASE || ''
                },
                requireInteraction: false,
                tag: 'msgql-' + (messageUuid || Date.now())
            }).then(function() {
                logDebug('[SW_NOTIFY] ✅ Notification shown via registration');
            }).catch(function(err) {
                logDebug('[SW_NOTIFY] ❌ registration error:', err.message);
            });
        });
    }
}
// ==================== BLOCK END: Service Worker Notifications v3.21.0 ====================

function vibrateMobile() {
    if (window.navigator && window.navigator.vibrate) {
        window.navigator.vibrate(200);
        logDebug('[VIBRATE] Mobile vibration triggered');
    }
}
// ==================== BLOCK END: Service Worker Notifications v3.15.0 ====================


// ==================== BLOCK START: isSubscribedToTaskJS v1.0 ====================
// ver.1.0 - JavaScript функция проверки подписки (клиентская)
// Проверяет, подписан ли пользователь на задачу (через кэш подписок)
function isSubscribedToTaskJS(taskUuid) {
    if (userSubscriptionsCache && userSubscriptionsCache[taskUuid]) {
        logDebug('[SSE_SUBSCRIBE_JS] User is SUBSCRIBED to task:', taskUuid);
        return true;
    }
    logDebug('[SSE_SUBSCRIBE_JS] User is NOT SUBSCRIBED to task:', taskUuid);
    return false;
}
// ==================== BLOCK END: isSubscribedToTaskJS v1.0 ====================



// ==================== BLOCK START: fetchUserSubscriptions v2.0 (enhanced) ====================
// ver.1.0 - Базовая версия с кэшированием
// ver.2.0 - ДОБАВЛЕНА повторная попытка при ошибке, расширенное логирование
function fetchUserSubscriptions() {
    // ✅ Проверяем авторизацию
    if (!window.currentUserUuid || window.currentUserUuid === '') {
        logDebug('[SSE] User not logged in, skipping subscriptions fetch');
        return Promise.resolve({});
    }

    var now = Date.now();
    if (userSubscriptionsCache && (now - subscriptionsCacheTimestamp) < SUBSCRIPTIONS_CACHE_TTL) {
        logDebug('[SSE] Using cached subscriptions, count:', Object.keys(userSubscriptionsCache).length);
        return Promise.resolve(userSubscriptionsCache);
    }
    
    logDebug('[SSE] Fetching user subscriptions from server...');
    
    var maxRetries = 2;
    var retryDelay = 1000;
    
    function doFetch(attempt) {
        return fetch(window.APP_BASE + '/get_subscriptions.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.subscriptions) {
                var newCache = {};
                for (var i = 0; i < data.subscriptions.length; i++) {
                    newCache[data.subscriptions[i].task_uuid] = true;
                }
                userSubscriptionsCache = newCache;
                subscriptionsCacheTimestamp = Date.now();
                logDebug('[SSE] Subscriptions updated, count:', Object.keys(userSubscriptionsCache).length);
                return userSubscriptionsCache;
            }
            logDebug('[SSE] No subscriptions found or invalid response');
            return {};
        })
        .catch(function(err) {
            logError('[SSE] Failed to fetch subscriptions (attempt ' + (attempt + 1) + '/' + (maxRetries + 1) + '):', err.message);
            if (attempt < maxRetries) {
                logDebug('[SSE] Retrying in ' + retryDelay + 'ms...');
                return new Promise(function(resolve) {
                    setTimeout(function() {
                        resolve(doFetch(attempt + 1));
                    }, retryDelay);
                });
            }
            return userSubscriptionsCache || {};
        });
    }
    
    return doFetch(0);
}
// ==================== BLOCK END: fetchUserSubscriptions v2.0 ====================

// ==================== BLOCK START: connect() function with Badges Support v3.10.0 ====================
// ver.3.10.0 (2026-05-26) - ДОБАВЛЕНА поддержка отдельных бейджей:
// - messages: непрочитанные сообщения (бейдж на "Сообщения")
// - projects: новые задачи (бейдж на "Проекты")
// - files: новые файлы (бейдж на "Файлы")
// - notifications: системные уведомления (колокольчик)
// - Вызовы updateAllBadges() после всех мутирующих событий

function connect() {
    // ✅ Проверяем, авторизован ли пользователь
    if (!window.currentUserUuid || window.currentUserUuid === '') {
        logDebug('[SSE] User not logged in, skipping SSE connection');
        return;
    }
    if (isManuallyDisconnected) {
        logDebug('[SSE] Manually disconnected, skipping connect');
        return;
    }
    clearAllTimers();
    if (eventSource) {
        try { eventSource.close(); } catch(e) {}
        eventSource = null;
    }
    
    var url = window.APP_BASE + '/sse.php';
    var taskUuid = getCurrentTaskUuid();
    
    logDebug('[SSE_PATH] window.APP_BASE =', window.APP_BASE);
    logDebug('[SSE_PATH] Base URL =', url);
    logDebug('[SSE_PATH] Current location pathname =', window.location.pathname);
    logDebug('[SSE_PATH] Current location href =', window.location.href);
    
    if (currentWatchingTaskUuid && currentWatchingTaskUuid !== taskUuid) {
        reconnectAttempts = 0;
        CONSECUTIVE_ERRORS = 0;
    }
    currentWatchingTaskUuid = taskUuid;
    
    var params = [];
    if (taskUuid) params.push('task_uuid=' + encodeURIComponent(taskUuid));
    if (params.length > 0) url += '?' + params.join('&');
    
    logDebug('[SSE] 🔌 Connecting to ' + url + ' (attempt ' + (reconnectAttempts + 1) + '/' + MAX_RECONNECT_ATTEMPTS + ')');
    logDebug('[SSE] task=' + taskUuid + ', params removed: since_time is no longer sent');
    
    fetchUserSubscriptions().catch(function() {});
    
    try {
        eventSource = new EventSource(url);
        
        eventSource.onopen = function() {
            logDebug('[SSE] ✅ Connection OPEN (task: ' + currentWatchingTaskUuid + ')');
            reconnectAttempts = 0;
            CONSECUTIVE_ERRORS = 0;
            startHeartbeatCheck();
            setTimeout(function() {
                isFirstConnection = false;
                logDebug('[SSE] First connection flag cleared, sound enabled');
            }, 5000);
        };
        
        eventSource.addEventListener('connected', function(e) {
            lastPingTime = Date.now();
        });
        
        eventSource.addEventListener('ping', function(e) {
            lastPingTime = Date.now();
        });
        
        eventSource.addEventListener('unread_update', function(e) {
            try {
                var data = JSON.parse(e.data);
                if (data.count !== undefined) {
                    logDebug('[SSE_BADGES] unread_update received, updating badges');
                    setTimeout(function() {
                        if (typeof updateAllBadges === 'function') {
                            updateAllBadges();
                        }
                    }, 300);
                }
                if (data.type === 'unread_update') {
                    updateAllBadges(data.count);
                    // 🔥 СОХРАНЯЕМ UUID последнего сообщения для прокрутки
                    if (data.last_message_uuid) {
                        window.lastUnreadMessageUuid = data.last_message_uuid;
                        window.lastUnreadTaskUuid = data.task_uuid;
                    }
                }
            } catch(err) {
                logError('[SSE] connect error:', err.message);
                logError('[SSE] Error stack:', err.stack);
                logError('[SSE] Data that caused error:', e.data);
            }
        });
        
        // ==================== BLOCK START: new_messages handler v3.22.0 (with DB settings & inactivity) ====================
        eventSource.addEventListener('new_messages', function(e) {
            try {
                logDebug('[SSE_NEW_MSG] ========== RAW EVENT ==========');
                logDebug('[SSE_NEW_MSG] raw data length:', e.data ? e.data.length : 0);
                
                var data = JSON.parse(e.data);
                
                var projectTitle = data.project_title || data.project_name || '';
                
                // Фильтрация своих сообщений
                if (data.messages && Array.isArray(data.messages)) {
                    var filteredMessages = data.messages.filter(function(msg) {
                        return msg.user_uuid !== window.currentUserUuid;
                    });
                    
                    if (filteredMessages.length === 0) {
                        logDebug('[SSE_NEW_MSG] Only self-messages, skipping');
                        return;
                    }
                    data.messages = filteredMessages;
                }

                logDebug('[SSE_NEW_MSG] Parsed data:', { 
                    task_uuid: data.task_uuid, 
                    messages_count: data.messages ? data.messages.length : 0,
                    project_title: projectTitle
                });
                
                if (!data.messages || data.messages.length === 0) {
                    logDebug('[SSE_NEW_MSG] No messages in event');
                    return;
                }
                
                // v3.20.0: ПРОВЕРКА ПОДПИСКИ
                var isSubscribedToTask = false;
                if (userSubscriptionsCache && userSubscriptionsCache[data.task_uuid]) {
                    isSubscribedToTask = true;
                    logDebug('[SSE_NEW_MSG] User is SUBSCRIBED to task:', data.task_uuid);
                } else {
                    logDebug('[SSE_NEW_MSG] User is NOT SUBSCRIBED to task:', data.task_uuid);
                }
                
                var isPageVisible = document.visibilityState === 'visible';
                var hasFocus = document.hasFocus();
                
                var currentOpenTaskUuid = null;
                var taskInput = document.getElementById('current-task-uuid');
                if (taskInput && taskInput.value) {
                    currentOpenTaskUuid = taskInput.value;
                }
                
                var isCurrentChat = (currentOpenTaskUuid === data.task_uuid);

                // Получаем время последней активности пользователя
                var lastUserActivityTime = parseInt(sessionStorage.getItem('sse_last_user_activity') || '0', 10);
                var now = Date.now();

                if (lastUserActivityTime === 0) {
                    lastUserActivityTime = window._pageLoadTime || now;
                }

                // Используем ГЛОБАЛЬНУЮ переменную soundIntervalSec (единый источник)
                var isInactive = (now - lastUserActivityTime) > (soundIntervalSec * 1000);

                logDebug('[SSE_NEW_MSG] Inactivity check - lastActivity:', new Date(lastUserActivityTime).toLocaleTimeString(), 
                         'inactivityMs:', (now - lastUserActivityTime), 'soundIntervalSec:', soundIntervalSec, 'isInactive:', isInactive);

                // Обновляем время активности при любом взаимодействии с пользователем
                function updateUserActivity() {
                    sessionStorage.setItem('sse_last_user_activity', Date.now().toString());
                }

                // Навешиваем обработчики один раз
                if (!window._activityHandlersAttached) {
                    window._activityHandlersAttached = true;
                    var events = ['mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
                    events.forEach(function(evt) {
                        document.addEventListener(evt, updateUserActivity);
                    });
                    window.addEventListener('focus', updateUserActivity);
                    logDebug('[SSE_NEW_MSG] Activity handlers attached');
                }

                var newUnreadMessages = data.messages.filter(function(m) {
                    return m.is_read === 0 && m.user_uuid !== window.currentUserUuid;
                });

                // ========== УСЛОВИЕ ПОКАЗА УВЕДОМЛЕНИЯ ==========
                var shouldShowNotification = (newUnreadMessages.length > 0) && 
                                             isSubscribedToTask &&
                                             (!isCurrentChat || isInactive);

                logDebug('[SSE_NEW_MSG] shouldShowNotification:', shouldShowNotification, 
                         '(unread:', newUnreadMessages.length > 0, 
                         ', isCurrentChat:', isCurrentChat, 
                         ', isInactive:', isInactive,
                         ', isSubscribed:', isSubscribedToTask, ')');

                // Обновляем бейджи всегда
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        updateAllBadges();
                    }
                }, 300);

                if (shouldShowNotification) {
                    var pageJustLoaded = window._isPageLoading === true;
                    var loadTime = window._pageLoadTime || Date.now();
                    var timeSinceLoad = Date.now() - loadTime;
                    var shouldNotify = !pageJustLoaded || timeSinceLoad > 3000;
                    
                    if (shouldNotify) {
                        logDebug('[SSE_NEW_MSG] 🔔 Sending notification (inactive or different chat)!');
                        
                        if (soundEnabled && canPlaySound()) {
                            playNotificationSound();
                            vibrateMobile();
                            logDebug('[SSE_NEW_MSG] Sound played (soundEnabled=true)');
                        } else if (!soundEnabled) {
                            logDebug('[SSE_NEW_MSG] Sound skipped (sound disabled in user settings)');
                        } else {
                            logDebug('[SSE_NEW_MSG] Sound skipped (interval limit or other reason)');
                        }
                        
                        var firstMsg = newUnreadMessages[0];
                        if (firstMsg) {
                            var notificationTitle = projectTitle ? '[' + projectTitle + '] ' + (firstMsg.user_name || 'Пользователь') : (firstMsg.user_name || 'Пользователь');
                            var notificationBody = (firstMsg.text || 'Новое сообщение').substring(0, 100);
                            
                            logDebug('[SSE_NEW_MSG] Notification details - title:', notificationTitle, 'body:', notificationBody);
                            logDebug('[SSE_NEW_MSG] Showing notification via Service Worker for message:', firstMsg.uuid);
                            
                            if (typeof showServiceWorkerNotification === 'function') {
                                setTimeout(function() {
                                    showServiceWorkerNotification(
                                        notificationTitle,
                                        notificationBody,
                                        window.APP_BASE + '/messages.php?task=' + data.task_uuid,
                                        data.task_uuid,
                                        firstMsg.uuid
                                    );
                                }, 100);
                            }
                        }
                    } else {
                        logDebug('[SSE_NEW_MSG] 🔕 Notification suppressed: first connection');
                    }
                } else if (!isSubscribedToTask && newUnreadMessages.length > 0) {
                    logDebug('[SSE_NEW_MSG] 🔕 Notification suppressed: user not subscribed to task');
                } else if (isCurrentChat && !isInactive && newUnreadMessages.length > 0) {
                    logDebug('[SSE_NEW_MSG] 🔕 Notification suppressed: user active in current chat');
                }

                // Обновляем DOM если открыт этот чат
                if (window.location.pathname.endsWith('/messages.php')) {
                    var currentTaskInput = document.getElementById('current-task-uuid');
                    var currentOpenTask = currentTaskInput ? currentTaskInput.value : null;
                    
                    if (currentOpenTask === data.task_uuid) {
                        logDebug('[SSE_NEW_MSG] ✅ Same task open, appending messages directly');
                        if (typeof window.appendNewMessages === 'function') {
                            window.appendNewMessages(data.messages, false);
                        }
                    }
                }            
            } catch(err) {
                logError('[SSE_NEW_MSG] ❌ Error processing new_messages:', err.message);
                logError('[SSE_NEW_MSG] Error stack:', err.stack);
            }
        });
        // ==================== BLOCK END: new_messages handler v3.22.0 ====================

        // ==================== ДОБАВЛЕН: message_read handler ====================
        eventSource.addEventListener('message_read', function(e) {
            try {
                var data = JSON.parse(e.data);
                logDebug('[SSE_READ] Message read event:', data);
                
                if (data.message_uuid && window.location.pathname.endsWith('/messages.php')) {
                    var msgElement = document.querySelector('.message[data-uuid="' + data.message_uuid + '"]');
                    if (msgElement) {
                        msgElement.classList.remove('unread');
                        msgElement.classList.add('read');
                        logDebug('[SSE_READ] Marked message as read in DOM:', data.message_uuid);
                    }
                }
                
                // Обновляем бейджи после прочтения
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        updateAllBadges();
                    }
                }, 300);
                
            } catch(err) {
                logError('[SSE_READ] Error processing message_read:', err);
            }
        });
        
        eventSource.addEventListener('message_edited', function(e) {
            try {
                var data = JSON.parse(e.data);
                if (window.location.pathname.endsWith('/messages.php') && data.message_uuid) {
                    if (!data.new_text) {
                        logDebug('[SSE] message_edited event missing new_text, reloading messages');
                        if (typeof window.loadMessages === 'function') {
                            window.loadMessages(true);
                        }
                        return;
                    }
                    updateMessageInDOM(data.message_uuid, data.new_text, data.edited_by_uuid, data.task_uuid);
                }
                // Обновляем бейджи после редактирования сообщения
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        logDebug('[SSE_BADGES] Message edited, updating badges');
                        updateAllBadges();
                    }
                }, 500);
            } catch(err) {
                logError('[SSE] message_edited error:', err);
            }
        });
        
        eventSource.addEventListener('message_deleted', function(e) {
            try {
                var data = JSON.parse(e.data);
                logDebug('[SSE] message_deleted received:', data);
                logDebug('[SSE] Current watching task:', currentWatchingTaskUuid);
                logDebug('[SSE] Deleted from task:', data.task_uuid);
                
                if (!data.message_uuid) return;
                
                if (typeof window.updateUnreadBadge === 'function') {
                    setTimeout(function() { window.updateUnreadBadge(); }, 100);
                }
                if (typeof window.SSE !== 'undefined' && typeof window.SSE.forceUpdateBadge === 'function') {
                    var currentUnread = document.querySelectorAll('.message.unread:not(.own)').length;
                    window.SSE.forceUpdateBadge(currentUnread);
                }
                
                if (window.location.pathname.endsWith('/messages.php') && data.message_uuid) {
                    var msgElement = document.querySelector('.message[data-uuid="' + data.message_uuid + '"]');
                    if (msgElement) {
                        var wasUnread = msgElement.classList.contains('unread') && !msgElement.classList.contains('own');
                        
                        msgElement.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        msgElement.style.opacity = '0';
                        msgElement.style.transform = 'translateX(-20px)';
                        setTimeout(function() {
                            if (msgElement && msgElement.parentNode) {
                                msgElement.remove();
                                
                                if (wasUnread && typeof window.updateUnreadBadge === 'function') {
                                    window.updateUnreadBadge();
                                }
                                
                                var messagesArea = document.getElementById('messages-area');
                                if (messagesArea && !messagesArea.querySelector('.message')) {
                                    messagesArea.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
                                }
                                
                                var lastMsg = document.querySelector('.message:last-child');
                                if (lastMsg) {
                                    var lastTime = parseInt(lastMsg.getAttribute('data-time'));
                                    if (!isNaN(lastTime) && lastTime > 0) {
                                        if (typeof window.lastMessageTime !== 'undefined') {
                                            window.lastMessageTime = lastTime;
                                        }
                                    }
                                } else {
                                    if (typeof window.lastMessageTime !== 'undefined') {
                                        window.lastMessageTime = 0;
                                    }
                                }
                                
                                logDebug('[SSE_DELETE] Message removed from DOM:', data.message_uuid);
                                
                                // Обновляем бейджи после удаления сообщения
                                setTimeout(function() {
                                    if (typeof updateAllBadges === 'function') {
                                        logDebug('[SSE_BADGES] Message deleted, updating badges');
                                        updateAllBadges();
                                    }
                                }, 500);
                            }
                        }, 300);
                    } else {
                        logDebug('[SSE_DELETE] Message element not found, reloading messages');
                        if (typeof window.loadMessages === 'function') {
                            window.loadMessages(true);
                        } else if (typeof window.refreshCurrentTaskMessages === 'function') {
                            window.refreshCurrentTaskMessages();
                        }
                    }
                }
            } catch(err) {
                logError('[SSE] message_deleted error:', err);
            }
        });
        
        eventSource.addEventListener('new_task', function(e) {
            try {
                var data = JSON.parse(e.data);
                logDebug('[SSE] new_task event received:', data);
                
                if (!data.task || !data.task.uuid) {
                    logDebug('[SSE] new_task event missing task data');
                    return;
                }
                
                var assignedToUuid = data.task.assigned_to_uuid;
                var isAssignedToCurrentUser = (assignedToUuid === currentUserUuid);
                
                logDebug('[SSE] Task assigned_to_uuid:', assignedToUuid);
                logDebug('[SSE] Current user UUID:', currentUserUuid);
                logDebug('[SSE] Is assigned to current user:', isAssignedToCurrentUser);
                
                var isSubscribedToTask = false;
                var taskUuidForCheck = data.task.uuid;
                
                if (userSubscriptionsCache && userSubscriptionsCache[taskUuidForCheck]) {
                    isSubscribedToTask = true;
                    logDebug('[SSE] User is SUBSCRIBED to this task (from cache)');
                } else {
                    logDebug('[SSE] User is NOT subscribed to this task (from cache)');
                }
                
                var shouldNotify = isAssignedToCurrentUser || isSubscribedToTask;
                
                logDebug('[SSE] Should notify:', shouldNotify, '(assigned:', isAssignedToCurrentUser, ', subscribed:', isSubscribedToTask, ')');
                
                if (!shouldNotify) {
                    logDebug('[SSE] 🔕 Skipping notification - task not assigned to user and user not subscribed');
                    return;
                }
                
                var taskTime = data.task.time || 0;
                var lastSeen = parseInt(localStorage.getItem('sse_last_task_seen_time') || '0');
                var isPageVisible = document.visibilityState === 'visible';
                var hasFocus = document.hasFocus();
                
                var notifyReason = isAssignedToCurrentUser ? 'assigned' : 'subscribed';
                
                if (taskTime > lastSeen) {
                    updateAllBadges();
                    localStorage.setItem('sse_last_task_seen_time', taskTime);
                    
                    var taskTitle = data.task.title || 'Новая задача';
                    var projectTitle = data.task.project_title || '';
                    var notificationTitle = projectTitle ? '📋 ' + projectTitle + ' → ' + taskTitle : '📋 ' + taskTitle;
                    
                    logDebug('[SSE] 📋 New task (' + notifyReason + '):', taskTitle, 'Project:', projectTitle);
                    
                    if (!hasFocus || !isPageVisible) {
                        // Звук воспроизводится только если включен и соблюден интервал
                        if (soundEnabled && canPlaySound()) {
                            logDebug('[SSE] Playing sound for new task notification');
                            playNotificationSound();
                            vibrateMobile();
                        } else {
                            logDebug('[SSE] Sound skipped for new task (disabled or interval limit)');
                        }
                        
                        showDesktopNotification(
                            notificationTitle,
                            taskTitle,
                            window.APP_BASE + '/projects.php?task=' + data.task.uuid,
                            data.task.uuid
                        );
                    }
                } else {
                    logDebug('[SSE] Task time not newer than last seen, skipping notification');
                }
                
                fetchUserSubscriptions().catch(function() {});
                
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        logDebug('[SSE_BADGES] New task received, updating badges');
                        updateAllBadges();
                    }
                }, 500);
                
            } catch(err) {
                logError('[SSE] new_task error:', err);
            }
        });
        
        eventSource.addEventListener('new_file', function(e) {
            try {
                var data = JSON.parse(e.data);
                updateAllBadges();
                logDebug('[SSE] 📎 New file:', data.file ? data.file.orig_name : '');
                
                var isPageVisible = document.visibilityState === 'visible';
                var hasFocus = document.hasFocus();
                
                if (!hasFocus || !isPageVisible) {
                    // Звук воспроизводится только если включен и соблюден интервал
                    if (soundEnabled && canPlaySound()) {
                        logDebug('[SSE] Playing sound for new file notification');
                        playNotificationSound();
                        vibrateMobile();
                    } else {
                        logDebug('[SSE] Sound skipped for new file (disabled or interval limit)');
                    }
                    
                    var fileName = data.file ? data.file.orig_name : 'Файл';
                    var taskTitle = data.task_title || 'задачи';
                    showDesktopNotification(
                        '📎 Новый файл',
                        fileName + ' (в задаче: ' + taskTitle + ')',
                        window.APP_BASE + '/projects.php?task=' + (data.file ? data.file.task_uuid : ''),
                        data.file ? data.file.task_uuid : null
                    );
                }
                
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        logDebug('[SSE_BADGES] New file received, updating badges');
                        updateAllBadges();
                    }
                }, 500);
            } catch(err) {
                logError('[SSE] new_file error:', err);
            }
        });
        
        eventSource.addEventListener('overdue_task', function(e) {
            try {
                var data = JSON.parse(e.data);
                var isPageVisible = document.visibilityState === 'visible';
                var hasFocus = document.hasFocus();
                if (!hasFocus || !isPageVisible) {
                    // Звук воспроизводится только если включен и соблюден интервал
                    if (soundEnabled && canPlaySound()) {
                        logDebug('[SSE] Playing sound for overdue task notification');
                        playNotificationSound();
                        vibrateMobile();
                    } else {
                        logDebug('[SSE] Sound skipped for overdue task (disabled or interval limit)');
                    }
                    
                    showDesktopNotification(
                        '⚠️ Просроченная задача', 
                        data.task ? data.task.title : '', 
                        window.APP_BASE + '/projects.php?task=' + (data.task ? data.task.uuid : ''),
                        data.task ? data.task.uuid : null
                    );
                }
            } catch(err) {
                logError('[SSE] overdue_task error:', err);
            }
        });

        eventSource.addEventListener('task_deleted', function(e) {
            try {
                var data = JSON.parse(e.data);
                logDebug('[SSE_TASK_DELETE] Received task_deleted event:', data);
                if (window.location.pathname.endsWith('/projects.php')) {
                    var taskElement = document.querySelector('.task-item[data-task-uuid="' + data.task_uuid + '"]');
                    if (taskElement) {
                        taskElement.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        taskElement.style.opacity = '0';
                        taskElement.style.transform = 'translateX(-20px)';
                        setTimeout(function() {
                            if (taskElement && taskElement.parentNode) {
                                taskElement.remove();
                                var taskTree = document.getElementById('task-tree');
                                if (taskTree && !taskTree.querySelector('.task-item')) {
                                    taskTree.innerHTML = '<div style="padding:30px;text-align:center;color:rgba(233,238,252,.6)">Нет задач. Создайте первую!</div>';
                                }
                                var projectCard = document.querySelector('.project-card[data-project-uuid="' + data.project_uuid + '"]');
                                if (projectCard) {
                                    var statsSpan = projectCard.querySelector('.project-stats span');
                                    if (statsSpan) {
                                        var match = statsSpan.textContent.match(/\d+/);
                                        if (match) {
                                            var newCount = Math.max(0, parseInt(match[0]) - 1);
                                            statsSpan.textContent = '📋 Задач: ' + newCount;
                                        }
                                    }
                                }
                            }
                        }, 300);
                    } else {
                        if (typeof loadTasks === 'function' && window.currentProjectUuid) {
                            loadTasks(window.currentProjectUuid);
                        }
                    }
                }
                if (window.location.pathname.endsWith('/index.php') && typeof window.refreshDashboard === 'function') {
                    setTimeout(function() { window.refreshDashboard(); }, 500);
                }
                if (typeof window.SSE !== 'undefined' && typeof window.SSE.refreshCounters === 'function') {
                    setTimeout(function() { window.SSE.refreshCounters(); }, 500);
                }
                
                // Обновляем бейджи после удаления задачи
                setTimeout(function() {
                    if (typeof updateAllBadges === 'function') {
                        logDebug('[SSE_BADGES] Task deleted, updating badges');
                        updateAllBadges();
                    }
                }, 500);
            } catch(err) {
                logError('[SSE] task_deleted error:', err);
            }
        });
        
        eventSource.onerror = function(event) {
            var readyState = eventSource ? eventSource.readyState : 'null';
            if (!eventSource || readyState === EventSource.CLOSED) {
                if (eventSource) { try { eventSource.close(); } catch(e) {} eventSource = null; }
                CONSECUTIVE_ERRORS++;
                reconnectAttempts++;
                stopHeartbeatCheck();
                if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) return;
                var delay = getReconnectDelay();
                if (reconnectTimer) clearTimeout(reconnectTimer);
                reconnectTimer = setTimeout(function() { if (!isManuallyDisconnected) connect(); }, delay);
            }
        };
        
    } catch (e) {
        CONSECUTIVE_ERRORS++;
        reconnectAttempts++;
        stopHeartbeatCheck();
        if (reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
            var delay = getReconnectDelay();
            if (reconnectTimer) clearTimeout(reconnectTimer);
            reconnectTimer = setTimeout(connect, delay);
        }
    }
}
// ==================== BLOCK END: connect() function with Badges Support v3.10.0 ====================

// Обработчик сообщений от Service Worker для умного клика по уведомлению
if ('serviceWorker' in navigator && navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function(event) {
        const data = event.data;
        
        if (data && data.type === 'scroll-to-bottom') {
            logDebug('[SW_MSG] Received scroll-to-bottom for task: ' + data.taskUuid);
            
            const currentTaskInput = document.getElementById('current-task-uuid');
            const currentTaskUuid = currentTaskInput ? currentTaskInput.value : null;
            
            if (currentTaskUuid === data.taskUuid) {
                // ✅ Тот же чат — просто прокручиваем
                const container = document.getElementById('messages-area');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                    logDebug('[SW_MSG] Scrolled to bottom');
                    
                    // Подсветка сообщения, если есть UUID
                    if (data.messageUuid) {
                        const msgElement = document.getElementById('msg-' + data.messageUuid);
                        if (msgElement) {
                            msgElement.classList.add('message-highlight');
                            setTimeout(() => msgElement.classList.remove('message-highlight'), 2000);
                        }
                    }
                }
            } else {
                // ❌ Открыт другой чат — перенаправляем
                logDebug('[SW_MSG] Different task open, redirecting');
                window.location.href = data.url || (window.APP_BASE + '/messages.php?task=' + data.taskUuid);
            }
        }
        
        if (data && data.type === 'notification-click' && data.url) {
            // Обычный клик по уведомлению
            window.focus();
            if (data.taskUuid && window.currentTaskUuid === data.taskUuid) {
                // Уже в нужном чате — прокручиваем
                const container = document.getElementById('messages-area');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            } else {
                window.location.href = data.url;
            }
        }
    });
}


// ==================== ПУБЛИЧНЫЕ МЕТОДЫ ====================
function reconnect() {
    CONSECUTIVE_ERRORS = 0;
    reconnectAttempts = 0;
    isManuallyDisconnected = false;
    if (eventSource) { try { eventSource.close(); } catch(e) {} eventSource = null; }
    clearAllTimers();
    if (reconnectTimer) clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(connect, 300);
    fetchUserSubscriptions().catch(function() {});
}

function disconnect() {
    isManuallyDisconnected = true;
    clearAllTimers();
    if (eventSource) { try { eventSource.close(); } catch(e) {} eventSource = null; }
    currentWatchingTaskUuid = null;
}

function changeTask(taskUuid) {
    if (eventSource) { try { eventSource.close(); } catch(e) {} eventSource = null; }
    clearAllTimers();
    currentWatchingTaskUuid = taskUuid;
    CONSECUTIVE_ERRORS = 0;
    reconnectAttempts = 0;
    isManuallyDisconnected = false;
    if (reconnectTimer) clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(connect, 2500);
    fetchUserSubscriptions().catch(function() {});
}

function updateMessageInDOM(messageUuid, newText, editedByUuid, taskUuid) {
    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
    if (!msgElement) return;
    var textDiv = msgElement.querySelector('.message-text');
    if (!textDiv) return;
    
    var own = msgElement.classList.contains('own');
    var userName = msgElement.querySelector('.message-author')?.innerText || 'Пользователь';
    var userUuid = own ? (typeof currentUserUuid !== 'undefined' ? currentUserUuid : '') : '';
    var currentTime = parseInt(msgElement.getAttribute('data-time')) || Date.now();
    var isRead = msgElement.classList.contains('unread') ? 0 : 1;
    
    var files = [];
    var fileItems = msgElement.querySelectorAll('.file-item, .file-preview-thumb');
    fileItems.forEach(function(item) {
        var onclickAttr = item.getAttribute('onclick');
        if (onclickAttr) {
            var match = onclickAttr.match(/showFilePreview\('([^']+)'/);
            if (match) {
                files.push({ 
                    uuid: match[1],
                    name: 'Файл',
                    size: '',
                    size_bytes: 0,
                    mime: '',
                    url: window.APP_BASE + '/download.php?file=' + match[1]
                });
            }
        }
    });
    
    var replyTo = null;
    var replyQuoteElement = msgElement.querySelector('.message-quote.clickable-quote');
    if (replyQuoteElement && replyQuoteElement.getAttribute('data-quote-uuid')) {
        replyTo = {
            uuid: replyQuoteElement.getAttribute('data-quote-uuid'),
            user_name: replyQuoteElement.querySelector('strong')?.innerText || 'Пользователь',
            text: replyQuoteElement.innerHTML.replace(/<[^>]*>/g, '').substring(0, 200),
            time: 0
        };
    }
    
    var replyUuidAttr = msgElement.getAttribute('data-reply-uuid');
    if (!replyTo && replyUuidAttr) {
        replyTo = {
            uuid: replyUuidAttr,
            user_name: 'Пользователь',
            text: '',
            time: 0
        };
    }
    
    var tempMsg = {
        uuid: messageUuid,
        text: newText,
        user_uuid: userUuid,
        user_name: userName,
        time: currentTime,
        is_read: isRead,
        files: files,
        reply_to: replyTo
    };
    
    var newHtml = '';
    if (typeof window.renderMessage === 'function') {
        newHtml = window.renderMessage(tempMsg);
    } else {
        newHtml = '<div class="message-text">' + escapeHtml(newText) + '</div>';
    }
    
    var tempContainer = document.createElement('div');
    tempContainer.innerHTML = newHtml;
    var newTextDiv = tempContainer.querySelector('.message-text');
    
    if (newTextDiv) {
        textDiv.innerHTML = newTextDiv.innerHTML;
    } else {
        textDiv.innerHTML = escapeHtml(newText);
    }
    
    msgElement.setAttribute('data-text', escapeHtml(newText).replace(/"/g, '&quot;'));
    if (replyTo && replyTo.uuid) {
        msgElement.setAttribute('data-reply-uuid', replyTo.uuid);
    }
    
    var timeSpan = msgElement.querySelector('.message-time');
    if (timeSpan && !timeSpan.textContent.includes('✎')) {
        timeSpan.textContent += ' ✎';
    }
}

function deleteMessageFromDOM(messageUuid, taskUuid) {
    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
    if (!msgElement) {
        logDebug('[SSE_DELETE] Message element not found, might need reload');
        if (typeof loadMessages === 'function') {
            loadMessages(true);
        }
        return;
    }
    
    var wasUnread = msgElement.classList.contains('unread') && !msgElement.classList.contains('own');
    
    msgElement.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    msgElement.style.opacity = '0';
    msgElement.style.transform = 'translateX(-20px)';
    
    setTimeout(function() {
        if (msgElement && msgElement.parentNode) {
            msgElement.remove();
            
            if (wasUnread && typeof updateUnreadBadge === 'function') {
                updateUnreadBadge();
            }
            
            var messagesArea = document.getElementById('messages-area');
            if (messagesArea && !messagesArea.querySelector('.message')) {
                messagesArea.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
            }
            
            var lastMsg = document.querySelector('.message:last-child');
            if (lastMsg) {
                var lastTime = parseInt(lastMsg.getAttribute('data-time'));
                if (!isNaN(lastTime) && lastTime > 0) {
                    window.lastMessageTime = lastTime;
                }
            } else {
                window.lastMessageTime = 0;
            }
            
            logDebug('[SSE_DELETE] Message removed from DOM:', messageUuid);
        }
    }, 300);
}

// ==================== ИНИЦИАЛИЗАЦИЯ ====================
function initAudioOnFirstInteraction() {
    if (!audioContextInitialized) {
        getAudioContext();
    }
    document.removeEventListener('click', initAudioOnFirstInteraction);
    document.removeEventListener('keydown', initAudioOnFirstInteraction);
    document.removeEventListener('touchstart', initAudioOnFirstInteraction);
}

function requestNotificationPermission() {
    if (Notification && Notification.permission === 'default') {
        Notification.requestPermission().then(function(perm) {
            notificationPermission = perm === 'granted';
            logDebug('[SSE] Notification permission:', notificationPermission ? 'granted' : 'denied');
        });
    } else if (Notification && Notification.permission === 'granted') {
        notificationPermission = true;
        logDebug('[SSE] Notification permission already granted');
    }
}

// ==================== BLOCK START: Notification Subscription Check v3.13.0 ====================
// ver.3.13.0 (2026-05-28) - Проверка статуса подписки на уведомления
// - Функция checkNotificationSupport() для диагностики
// - Сохранение статуса в sessionStorage

function checkNotificationSupport() {
    var result = {
        supported: false,
        permission: 'default',
        serviceWorker: false,
        pushSupported: false,
        canShowWhenClosed: false
    };
    
    result.supported = 'Notification' in window;
    if (result.supported) {
        result.permission = Notification.permission;
    }
    
    result.serviceWorker = 'serviceWorker' in navigator;
    
    result.pushSupported = result.serviceWorker && 'PushManager' in window;
    
    var isIOS = false;
    if (typeof window.isIOSBrowser === 'function') {
        isIOS = window.isIOSBrowser();
    } else {
        isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    }
    
    result.canShowWhenClosed = !isIOS && result.serviceWorker;
    
    sessionStorage.setItem('notification_support', JSON.stringify(result));
    logDebug('[NOTIFY_SUPPORT] Support check result:', result);
    
    return result;
}

// Вызываем при загрузке
setTimeout(checkNotificationSupport, 1000);

// Экспортируем глобально
window.checkNotificationSupport = checkNotificationSupport;
// ==================== BLOCK END: Notification Subscription Check v3.13.0 ====================

// ==================== BLOCK START: registerServiceWorker v3.4.0 (FULLY FIXED) ====================
// ver.3.0.0 - Базовая версия
// ver.3.1.0 (2026-06-05) - ДОБАВЛЕНА ПРОВЕРКА, ЧТОБЫ ИЗБЕЖАТЬ ДВОЙНОЙ РЕГИСТРАЦИИ
// ver.3.2.0 (2026-06-05) - УЛУЧШЕНА ПРОВЕРКА СУЩЕСТВУЮЩЕЙ РЕГИСТРАЦИИ
// ver.3.3.0 (2026-06-05) - ДОБАВЛЕНА ЗАЩИТА ОТ ПОВТОРНЫХ ВЫЗОВОВ ЧЕРЕЗ ТАЙМАУТ
// ver.3.4.0 (2026-06-05) - УДАЛЕНА ОТПРАВКА УРОВНЯ ЛОГИРОВАНИЯ (ДУБЛИРУЕТСЯ В HEADER)
//                        - УБРАН ДУБЛИРУЮЩИЙ ВЫЗОВ sendLogLevel
//                        - ДОБАВЛЕНО ПОДРОБНОЕ ЛОГИРОВАНИЕ КАЖДОГО ШАГА
//                        - Service Worker регистрируется ТОЛЬКО ОДИН РАЗ

var lastRegistrationAttempt = 0;
var REGISTRATION_COOLDOWN_MS = 5000;

function registerServiceWorker() {
    logDebug('[SW_REG] ========== START ==========');
    
    if (!('serviceWorker' in navigator)) {
        logDebug('[SW_REG] ❌ Service Worker not supported in this browser');
        return;
    }
    
    // Защита от частых вызовов (cooldown)
    var now = Date.now();
    if (now - lastRegistrationAttempt < REGISTRATION_COOLDOWN_MS) {
        logDebug('[SW_REG] ⏳ Registration called too frequently, skipping (cooldown: ' + (now - lastRegistrationAttempt) + 'ms)');
        return;
    }
    lastRegistrationAttempt = now;
    
    // Предотвращаем двойную регистрацию через глобальный флаг
    if (window._swRegistrationStarted) {
        logDebug('[SW_REG] ⏳ Registration already started (flag is true), skipping duplicate');
        return;
    }
    window._swRegistrationStarted = true;
    logDebug('[SW_REG] ✅ Global flag set to true');
    
    var swUrl = (window.APP_BASE || '') + '/sw.js';
    logDebug('[SW_REG] SW URL: ' + swUrl);
    
    // Тщательная проверка существующей регистрации
    navigator.serviceWorker.getRegistration(swUrl).then(function(existingRegistration) {
        if (existingRegistration) {
            logDebug('[SW_REG] ⚠️ Service Worker ALREADY REGISTERED!');
            logDebug('[SW_REG]   Scope: ' + existingRegistration.scope);
            logDebug('[SW_REG]   Active state: ' + (existingRegistration.active ? 'active' : 'none'));
            logDebug('[SW_REG]   Waiting state: ' + (existingRegistration.waiting ? 'waiting' : 'none'));
            logDebug('[SW_REG]   Installing state: ' + (existingRegistration.installing ? 'installing' : 'none'));
            
            // Проверяем, нужно ли обновить (только если версия изменилась)
            return existingRegistration.update().then(function() {
                logDebug('[SW_REG] ✅ Service Worker update check completed');
                return existingRegistration;
            }).catch(function(e) {
                logDebug('[SW_REG] ⚠️ Service Worker update failed: ' + e.message);
                return existingRegistration;
            });
        }
        
        logDebug('[SW_REG] 🔄 No existing registration found, creating new one');
        return navigator.serviceWorker.register(swUrl);
    }).then(function(registration) {
        logDebug('[SW_REG] ✅✅✅ Service Worker registration COMPLETE!');
        logDebug('[SW_REG]   Scope: ' + registration.scope);
        
        // Добавляем обработчик обновления
        registration.addEventListener('updatefound', function() {
            var newWorker = registration.installing;
            logDebug('[SW_REG] 🔄 New Service Worker found, state: ' + newWorker.state);
            
            newWorker.addEventListener('statechange', function() {
                logDebug('[SW_REG] 🔄 Service Worker state changed to: ' + newWorker.state);
                if (newWorker.state === 'activated') {
                    logDebug('[SW_REG] ✅ New Service Worker activated');
                }
            });
        });
        
        // ПРИМЕЧАНИЕ: Отправка уровня логирования УДАЛЕНА из этого места,
        // так как она уже выполняется в header_internal.php
        // Это устраняет дублирование вызовов.
        
        return registration;
    }).catch(function(err) {
        logError('[SW_REG] ❌❌❌ Service Worker registration FAILED: ' + err.message);
        logError('[SW_REG]   Error details: ' + (err.stack || err.message));
        // Сбрасываем флаг при ошибке, чтобы можно было повторить
        window._swRegistrationStarted = false;
        logDebug('[SW_REG] Global flag reset to false due to error');
    });
    
    logDebug('[SW_REG] ========== END ==========');
}
// ==================== BLOCK END: registerServiceWorker v3.4.0 ====================

// Запуск
document.addEventListener('click', initAudioOnFirstInteraction);
document.addEventListener('keydown', initAudioOnFirstInteraction);
document.addEventListener('touchstart', initAudioOnFirstInteraction);
setTimeout(requestNotificationPermission, 5000);
document.addEventListener('DOMContentLoaded', function() {
    registerServiceWorker();
    // ✅ Запускаем SSE только если пользователь авторизован
    if (window.currentUserUuid && window.currentUserUuid !== '') {
        setTimeout(connect, 1000);
    } else {
        logDebug('[SSE] User not logged in, SSE not started');
    }
});
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        if (!eventSource || eventSource.readyState !== EventSource.OPEN) reconnect();
    }
});
window.addEventListener('focus', function() {
    if (!isManuallyDisconnected) {
        if (!eventSource || eventSource.readyState !== EventSource.OPEN) reconnect();
    }
});
window.addEventListener('beforeunload', function() {
    disconnect();
});

// ==================== PUBLIC API ====================
window.SSE = {
    connect: connect,
    disconnect: disconnect,
    reconnect: reconnect,
    changeTask: changeTask,
    refreshCounters: function() {
        if (!eventSource || eventSource.readyState !== EventSource.OPEN) reconnect();
    },
    forceUpdateBadge: function(count) {
        updateAllBadges();
    },
    updateSoundSettings: function(enabled, intervalSec) {
        soundEnabled = enabled;
        soundIntervalSec = intervalSec;
        sessionStorage.setItem('sse_sound_enabled', enabled ? '1' : '0');
        sessionStorage.setItem('sse_sound_interval', intervalSec);
    },
    deleteMessageFromDOM: deleteMessageFromDOM,
    refreshSubscriptions: function() {
        userSubscriptionsCache = {};
        subscriptionsCacheTimestamp = 0;
        return fetchUserSubscriptions();
    },
    updateAllBadges: function() {
        updateAllBadges();
    }
};


// ==================== BLOCK START: Export functions to global scope v3.14.0 ====================
// ver.3.14.0 (2026-05-28) - Экспорт функций в глобальную область для доступа из других скриптов
// - showServiceWorkerNotification
// - checkNotificationSupport
// - showMobileNotification
// - vibrateMobile
// - canPlaySound (v3.16.1)

// Экспортируем функции в глобальную область
window.showServiceWorkerNotification = showServiceWorkerNotification;
window.checkNotificationSupport = checkNotificationSupport;
window.vibrateMobile = vibrateMobile;
window.canPlaySound = canPlaySound;  // <-- ДОБАВИТЬ ЭТУ СТРОКУ

// Если showMobileNotification ещё не определена глобально, экспортируем и её
if (typeof window.showMobileNotification === 'undefined') {
    window.showMobileNotification = function(title, body, link, taskUuid) {
        logDebug('[GLOBAL] showMobileNotification fallback called');
        if (typeof showServiceWorkerNotification === 'function') {
            showServiceWorkerNotification(title, body, link, taskUuid);
        } else if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
    };
}

logDebug('[SSE_EXPORT] ✅ Functions exported to global scope - showServiceWorkerNotification:', typeof window.showServiceWorkerNotification);
logDebug('[SSE_EXPORT] ✅ checkNotificationSupport:', typeof window.checkNotificationSupport);
logDebug('[SSE_EXPORT] ✅ canPlaySound:', typeof window.canPlaySound);
// ==================== BLOCK END: Export functions to global scope v3.14.0 ====================

})();