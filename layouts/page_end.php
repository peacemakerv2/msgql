<?php
// Общий конец HTML-страницы
// ПРИНУДИТЕЛЬНАЯ ПЕРЕДАЧА VAPID КЛЮЧА
global $vapid_public_key, $config;
if (empty($vapid_public_key) && isset($config['vapid_public_key'])) {
    $vapid_public_key = $config['vapid_public_key'];
}
// Для отладки
//error_log("[PAGE_END] vapid_public_key: " . ($vapid_public_key ?? 'NOT SET'));
?>
<!-- ========== ОПРЕДЕЛЕНИЕ CSRF-ТОКЕНА (ДО ИНИЦИАЛИЗАЦИИ ЧАСОВОГО ПОЯСА) ========== -->
<script nonce="<?= CSP_NONCE ?>">
    // Передаем название системы из конфига в JS
    window.systemTitle = <?= json_encode($system_name ?? 'ЗадаЧат') ?>;
    // Сохраняем в sessionStorage для доступа из любого места
    if (window.systemTitle && typeof sessionStorage !== 'undefined') {
        sessionStorage.setItem('systemTitle', window.systemTitle);
    }
    window.vapidPublicKey = '<?= $vapid_public_key ?? '' ?>';
    logDebug('[VAPID] Public key loaded, length:', window.vapidPublicKey ? window.vapidPublicKey.length : 0);
</script>

<script nonce="<?= CSP_NONCE ?>">
if (typeof window.csrfToken === 'undefined') {
    window.csrfToken = '<?= msgql_csrf_get_token() ?>';
    logDebug('[TIMEZONE] CSRF token initialized:', window.csrfToken);
}
</script>

<!-- ========== ЕДИНАЯ ИНИЦИАЛИЗАЦИЯ ЧАСОВОГО ПОЯСА ПОЛЬЗОВАТЕЛЯ ========== -->
<script nonce="<?= CSP_NONCE ?>">
(function() {
    'use strict';

    // ✅ Добавить проверку: запускаем только если пользователь авторизован
    if (!window.currentUserUuid || window.currentUserUuid === '') {
        logDebug('[TIMEZONE] User not logged in, skipping timezone sync');
        return;
    }
    
    logDebug('[TIMEZONE] === DIAG: timezone init block started ===');
    logDebug('[TIMEZONE] window.csrfToken exists:', !!window.csrfToken);
    
    if (window.csrfToken && window.csrfToken !== '') {
        
        var userTimezoneOffset = new Date().getTimezoneOffset();
        var tzHours = -userTimezoneOffset / 60;
        var tzSign = tzHours >= 0 ? '+' : '';
        
        logDebug('[TIMEZONE] JS detected - offset minutes: ' + userTimezoneOffset + ', hours: ' + tzSign + tzHours);
        
        sessionStorage.setItem('user_tz_offset', userTimezoneOffset);
        
        logDebug('[TIMEZONE] Sending POST to /set_timezone.php...');
        
        fetch(window.APP_BASE + '/set_timezone.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'offset=' + userTimezoneOffset + '&csrf_token=' + window.csrfToken,
            keepalive: true
        })
        .then(function(response) {
            logDebug('[TIMEZONE] Response status:', response.status);
            return response.text();
        })
        .then(function(text) {
            logDebug('[TIMEZONE] Response text (first 200 chars):', text.substring(0, 200));
            try {
                var data = JSON.parse(text);
                if (data && data.success) {
                    logDebug('[TIMEZONE] ✅ Success! offset:', data.offset, 'hours:', data.hours);
                } else {
                    console.warn('[TIMEZONE] ⚠️ Server error:', data);
                }
            } catch(e) {
                console.error('[TIMEZONE] ❌ Invalid JSON response:', e.message);
            }
        })
        .catch(function(e) {
            console.error('[TIMEZONE] ❌ Fetch error:', e.message);
        });
        
    } else {
        logDebug('[TIMEZONE] No CSRF token, skipping');
    }
})();
</script>
<!-- ========== КОНЕЦ ИНИЦИАЛИЗАЦИИ ЧАСОВОГО ПОЯСА ========== -->

<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/sse_client.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/toast.js"></script>
<?php
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>


<!-- ==================== BLOCK START: Global Notification Functions Export v1.0.0 ==================== -->
<script nonce="<?= CSP_NONCE ?>">
// Экспортируем функции из sse_client.js в глобальную область
setTimeout(function() {
    if (typeof window.showServiceWorkerNotification !== 'function') {
        logDebug('[GLOBAL_EXPORT] showServiceWorkerNotification not found, attempting to export from SSE');
        if (window.SSE && typeof window.SSE.showServiceWorkerNotification === 'function') {
            window.showServiceWorkerNotification = window.SSE.showServiceWorkerNotification;
            logDebug('[GLOBAL_EXPORT] ✅ Exported showServiceWorkerNotification from SSE');
        }
    }
    
    if (typeof window.checkNotificationSupport !== 'function') {
        logDebug('[GLOBAL_EXPORT] checkNotificationSupport not found, attempting to export from SSE');
        if (window.SSE && typeof window.SSE.checkNotificationSupport === 'function') {
            window.checkNotificationSupport = window.SSE.checkNotificationSupport;
            logDebug('[GLOBAL_EXPORT] ✅ Exported checkNotificationSupport from SSE');
        }
    }
    
    if (typeof window.showMobileNotification !== 'function') {
        logDebug('[GLOBAL_EXPORT] showMobileNotification not found, using fallback');
        window.showMobileNotification = function(title, body, link, taskUuid) {
            logDebug('[GLOBAL_EXPORT] Fallback showMobileNotification called');
            if (typeof showToast === 'function') {
                showToast(title + ': ' + body, 'info');
            } else {
                alert(title + ': ' + body);
            }
        };
    }
    
    logDebug('[GLOBAL_EXPORT] Final check - showServiceWorkerNotification:', typeof window.showServiceWorkerNotification);
    logDebug('[GLOBAL_EXPORT] Final check - checkNotificationSupport:', typeof window.checkNotificationSupport);
}, 500);
</script>
<!-- ==================== BLOCK END: Global Notification Functions Export v1.0.0 ==================== -->

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Global SW Functions for Notifications v1.1.0 ====================
// ver.1.1.0 (2026-05-28) - Глобальные функции для отправки уведомлений через Service Worker
// - Доступны из любого скрипта через window.showMobileNotification
// - Автоматическая вибрация на мобильных устройствах

window.showMobileNotification = function(title, body, link, taskUuid) {
    logDebug('[MOBILE_NOTIFY] showMobileNotification called:', { title: title, body: body, link: link });
    
    if (!('serviceWorker' in navigator)) {
        logDebug('[MOBILE_NOTIFY] Service Worker not supported, falling back to toast');
        if (typeof showToast === 'function') {
            showToast(title + ': ' + body, 'info');
        }
        return;
    }
    
    // Вибрация для мобильных
    if (window.navigator && window.navigator.vibrate) {
        window.navigator.vibrate([200, 100, 200]);
        logDebug('[MOBILE_NOTIFY] Vibration triggered');
    }
    
    // Отправляем через Service Worker
    if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
            type: 'showNotification',
            title: title,
            body: body,
            icon: (window.APP_BASE || '') + '/favicon.ico',
            badge: (window.APP_BASE || '') + '/favicon.ico',
            url: link,
            notificationType: 'message',
            id: taskUuid,
            tag: 'msgql-' + Date.now(),
            systemTitle: window.systemTitle || 'ЗадаЧат',
            appBase: window.APP_BASE || ''
        });
        logDebug('[MOBILE_NOTIFY] Notification sent via Service Worker');
    } else {
        navigator.serviceWorker.ready.then(function(registration) {
            registration.showNotification(title, {
                body: body,
                icon: (window.APP_BASE || '') + '/favicon.ico',
                badge: (window.APP_BASE || '') + '/favicon.ico',
                vibrate: [200, 100, 200],
                data: { url: link, taskUuid: taskUuid, appBase: window.APP_BASE || '' },
                tag: 'msgql-' + Date.now()
            });
            logDebug('[MOBILE_NOTIFY] Notification shown via registration.ready');
        }).catch(function(err) {
            logDebug('[MOBILE_NOTIFY] Failed to show notification:', err);
        });
    }
};

// Функция для проверки поддержки уведомлений на мобильном устройстве
window.isMobileNotificationSupported = function() {
    var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    var hasServiceWorker = 'serviceWorker' in navigator;
    var hasNotification = 'Notification' in window;
    
    logDebug('[MOBILE_NOTIFY] Support check - isMobile:', isMobile, 'hasSW:', hasServiceWorker, 'hasNotification:', hasNotification);
    
    return isMobile && hasServiceWorker && hasNotification;
};
// ==================== BLOCK END: Global SW Functions for Notifications v1.1.0 ====================


</script>

</body>
</html>