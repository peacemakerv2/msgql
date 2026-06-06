<?php
// page_start.php - Общий старт HTML-страницы
if (defined('AJAX_REQUEST')) {
    return; // Ничего не выводим для AJAX-запросов
}

// Вычисляем один раз на старте
$appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($appBase === '' || $appBase === '\\') $appBase = '';


// Передаём в JS
echo '<script nonce="' . CSP_NONCE . '">';
echo 'window.APP_BASE = ' . json_encode($appBase) . ';';
echo 'window.JS_DEBUG = ' . $js_debug_level . ';';
echo '</script>';

// ==================== BLOCK START: Global SW registration flag v1.0 ====================
// ver.1.0 (2026-06-05) - Глобальный флаг для предотвращения двойной регистрации Service Worker
echo '<script nonce="' . CSP_NONCE . '">';
echo 'if (typeof window._swRegistrationStarted === "undefined") {';
echo '    window._swRegistrationStarted = false;';
echo '    if (typeof logDebug === "function") logDebug("[SW_GLOBAL] Registration flag initialized");';
echo '}';
echo '</script>';
// ==================== BLOCK END: Global SW registration flag v1.0 ====================
?>

<!doctype html>
<html lang="ru">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
	<title><?= htmlspecialchars($system_title ?? 'ЗадаЧат', ENT_QUOTES, 'UTF-8') ?></title>
	<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/logger.js"></script>

	<script nonce="<?= CSP_NONCE ?>">
		// ЕДИНСТВЕННОЕ место инициализации глобальных настроек пользователя
		// Все остальные скрипты ТОЛЬКО читают window.userSettings
		window.userSettings = {
		    soundEnabled: <?= ($_SESSION['user_sound_enabled'] ?? 1) === 1 ? 'true' : 'false' ?>,
		    soundIntervalSec: <?= (int)($_SESSION['user_sound_interval_sec'] ?? 61) ?>
		};

		// Сохраняем в sessionStorage для Service Worker (единственное место!)
		sessionStorage.setItem('sse_sound_enabled', window.userSettings.soundEnabled ? '1' : '0');
		sessionStorage.setItem('sse_sound_interval', window.userSettings.soundIntervalSec);

		logDebug('[GLOBAL] User sound settings loaded:', window.userSettings);
	</script>

	<style>
		*, *::before, *::after { box-sizing: border-box; }
		body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#0b1020; color:#e9eefc;}
		a{color:#9bb7ff; text-decoration:none;}
		a:hover{text-decoration:underline;}

		.wrap{max-width:980px; margin:0 auto; padding:24px;}
		.card{background:#121a33; border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:18px;}
		.row{display:flex; gap:16px; flex-wrap:wrap;}
		.col{flex:1 1 320px;}

		label{display:block; margin:0 0 6px;}
		input{display:block; width:100%; max-width:100%; padding:12px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0b1020; color:#e9eefc;}
		button{display:inline-block; max-width:100%; padding:12px 14px; border-radius:10px; border:0; background:#4f7cff; color:white; font-weight:600; cursor:pointer;}

		.err{background:#2a1120; border:1px solid rgba(255,100,130,.35); color:#ffd1dc; padding:10px 12px; border-radius:10px; margin:12px 0;}
		.muted{color:rgba(233,238,252,.7);}

		header.welcome{padding:18px 0;}
		header.welcome .appname{font-size:22px; font-weight:700;}

		header.internal{position:sticky; top:0; background:rgba(11,16,32,.9); backdrop-filter: blur(10px); border-bottom:1px solid rgba(255,255,255,.08);}
		header.internal nav{max-width:980px; margin:0 auto; padding:12px 24px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
		header.internal nav a{padding:8px 10px; border-radius:10px;}
		header.internal nav a.active{background:rgba(79,124,255,.18); border:1px solid rgba(79,124,255,.35);}
		header.internal nav .spacer{flex:1 1 auto;}

		.main-menu{display:flex; gap:12px; flex-wrap:wrap;}
		.tile{display:block; min-width:220px; padding:14px 14px; border-radius:14px; border:1px solid rgba(255,255,255,.1); background:#0b1020;}
		.tile .t{font-weight:700; margin-bottom:6px;}
		.tile .d{font-size:14px; color:rgba(233,238,252,.7);}

		.footer{margin-top:14px; font-size:14px;}
	</style>
</head>
<body>

<script nonce="<?= CSP_NONCE ?>">




// ==================== BLOCK START: Reload Source Detector v1.0 ====================
// ver.1.0 (2026-06-05) - ОПРЕДЕЛЕНИЕ ИСТОЧНИКА ПЕРЕЗАГРУЗКИ СТРАНИЦЫ
// - Отслеживает, кто вызвал перезагрузку (location.reload, навигация, Service Worker)
// - Логирует стек вызовов при перезагрузке

(function() {
    // Перехватываем location.reload
    var originalReload = window.location.reload;
    window.location.reload = function() {
        console.error('🔴🔴🔴 LOCATION.RELOAD CALLED! 🔴🔴🔴');
        console.trace('Stack trace of reload call:');
        
        // Логируем через ваш логгер
        if (typeof logError === 'function') {
            logError('[RELOAD_SOURCE] location.reload() called', { stack: new Error().stack });
        }
        
        // Вызываем оригинальный reload
        originalReload.apply(window.location, arguments);
    };
    
    // Отслеживаем навигацию
    var originalReplace = window.location.replace;
    window.location.replace = function(url) {
        console.error('🔴🔴🔴 LOCATION.REPLACE CALLED to:', url);
        console.trace('Stack trace:');
        if (typeof logError === 'function') {
            logError('[RELOAD_SOURCE] location.replace() called', { url: url, stack: new Error().stack });
        }
        originalReplace.apply(window.location, arguments);
    };
    
    // Отслеживаем Service Worker сообщения о перезагрузке
    if ('serviceWorker' in navigator && navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', function(event) {
            if (event.data && (event.data.type === 'force-reload' || event.data.type === 'reload')) {
                console.error('🔴🔴🔴 SERVICE WORKER REQUESTED RELOAD! 🔴🔴🔴');
                console.log('SW message:', event.data);
                if (typeof logError === 'function') {
                    logError('[RELOAD_SOURCE] Service Worker requested reload', event.data);
                }
            }
        });
    }
    
    // Сохраняем время последней перезагрузки
    var lastReloadTime = sessionStorage.getItem('last_reload_time');
    var reloadCount = parseInt(sessionStorage.getItem('reload_count') || '0', 10);
    var now = Date.now();
    
    if (lastReloadTime && (now - parseInt(lastReloadTime, 10)) < 3000) {
        reloadCount++;
        console.error(`🔴 CRITICAL: Page reloaded ${reloadCount} times in last 3 seconds!`);
        
        if (typeof logError === 'function') {
            logError(`[RELOAD_SOURCE] Page reloaded ${reloadCount} times in last 3 seconds`, {
                reloadCount: reloadCount,
                timeSinceLastReload: now - parseInt(lastReloadTime, 10)
            });
        }
        
        // Если перезагрузок слишком много - останавливаем цикл через alert
        if (reloadCount > 3) {
            alert('Обнаружена циклическая перезагрузка страницы. Пожалуйста, очистите кэш браузера (Ctrl+Shift+Delete) и обновите страницу вручную.');
            sessionStorage.clear();
        }
    } else {
        reloadCount = 0;
    }
    
    sessionStorage.setItem('last_reload_time', now.toString());
    sessionStorage.setItem('reload_count', reloadCount.toString());
    
    logDebug('[RELOAD_DETECTOR] Initialized, current reload count in last 3 seconds:', reloadCount);
})();
// ==================== BLOCK END: Reload Source Detector v1.0 ====================


// ==================== BLOCK START: Track Full Page Unload v1.0 ====================
// ver.1.0 (2026-06-05) - ОТСЛЕЖИВАНИЕ ПРИЧИНЫ ПЕРЕЗАГРУЗКИ СТРАНИЦЫ
(function() {
    // Перехватываем все способы ухода со страницы
    window.addEventListener('beforeunload', function(e) {
        var reason = 'unknown';
        
        // Проверяем, есть ли активный запрос на редирект
        if (window._redirectTriggered) {
            reason = 'manual redirect';
        } else if (document.querySelector('.btn, .btn-primary, .btn-secondary, a[href]')) {
            // Возможно клик по ссылке или кнопке
            var activeElement = document.activeElement;
            if (activeElement && (activeElement.tagName === 'A' || activeElement.tagName === 'BUTTON' || activeElement.closest('a, button'))) {
                reason = 'click on ' + (activeElement.tagName === 'A' ? 'link' : 'button');
            }
        }
        
        logDebug('[BEFOREUNLOAD] Page is unloading, reason: ' + reason);
        
        // Сохраняем стек вызовов для анализа
        sessionStorage.setItem('last_unload_reason', reason);
        sessionStorage.setItem('last_unload_time', Date.now().toString());
    });
    
    // Отслеживаем программные редиректы
    var originalAssign = window.location.assign;
    var originalReplace = window.location.replace;
    
    window.location.assign = function(url) {
        window._redirectTriggered = true;
        console.error('[REDIRECT] location.assign called to:', url);
        logError('[REDIRECT] location.assign called', { url: url, stack: new Error().stack });
        return originalAssign.apply(this, arguments);
    };
    
    window.location.replace = function(url) {
        window._redirectTriggered = true;
        console.error('[REDIRECT] location.replace called to:', url);
        logError('[REDIRECT] location.replace called', { url: url, stack: new Error().stack });
        return originalReplace.apply(this, arguments);
    };
    
    
    // Проверяем, был ли редирект при прошлой загрузке
    var lastReason = sessionStorage.getItem('last_unload_reason');
    var lastTime = sessionStorage.getItem('last_unload_time');
    if (lastReason && lastTime && (Date.now() - parseInt(lastTime, 10)) < 3000) {
        console.error('[PAGE_LOAD] Previous page unload reason:', lastReason);
        logDebug('[PAGE_LOAD] Previous unload reason: ' + lastReason);
    }
})();
// ==================== BLOCK END: Track Full Page Unload v1.0 ====================


// ==================== BLOCK START: Global Loading Indicator v1.4 (auto-hide after timeout) ====================
// ver.1.0 (2026-06-05) - Top-most индикатор загрузки страницы
// ver.1.1 (2026-06-05) - ДОБАВЛЕНА ВОЗМОЖНОСТЬ ВНЕШНЕГО УПРАВЛЕНИЯ
// ver.1.2 (2026-06-05) - УВЕЛИЧЕН ТАЙМЕР АВТО-СКРЫТИЯ ДО 5 СЕКУНД
// ver.1.3 (2026-06-05) - ИСПРАВЛЕНА ДВОЙНАЯ ПЕРЕРИСОВКА ПРИ Ctrl+F5
// ver.1.4 (2026-06-05) - ДОБАВЛЕН АВТОМАТИЧЕСКИЙ ТАЙМЕР СКРЫТИЯ ЧЕРЕЗ 8 СЕКУНД
// - Индикатор автоматически скрывается через 8 секунд, если не был скрыт ранее
// - При любом вызове setPageLoadStatus('ready') таймер сбрасывается

(function() {
    'use strict';
    
    // Создаём контейнер для индикатора
    var indicatorContainer = document.createElement('div');
    indicatorContainer.id = 'page-load-indicator';
    indicatorContainer.style.cssText = [
        'position: fixed',
        'top: 8px',
        'right: 8px',
        'z-index: 999999',
        'display: flex',
        'align-items: center',
        'justify-content: center',
        'gap: 6px',
        'background: rgba(0, 0, 0, 0.7)',
        'backdrop-filter: blur(8px)',
        'border-radius: 24px',
        'padding: 6px 12px',
        'font-size: 12px',
        'font-family: system-ui, -apple-system, sans-serif',
        'transition: opacity 0.3s ease',
        'opacity: 1',
        'pointer-events: none',
        'box-shadow: 0 2px 8px rgba(0,0,0,0.2)',
        'border: 1px solid rgba(255,255,255,0.1)'
    ].join(';');
    
    // Иконка загрузки
    var spinner = document.createElement('div');
    spinner.id = 'load-indicator-spinner';
    spinner.style.cssText = [
        'width: 14px',
        'height: 14px',
        'border: 2px solid rgba(79,124,255,0.3)',
        'border-top-color: #4f7cff',
        'border-radius: 50%',
        'animation: spin 0.8s linear infinite',
        'display: inline-block'
    ].join(';');
    
    // Текст статуса
    var statusText = document.createElement('span');
    statusText.id = 'load-indicator-text';
    statusText.style.cssText = 'color: #e9eefc; font-weight: 500;';
    statusText.textContent = 'Загрузка...';
    
    // Добавляем анимацию
    var style = document.createElement('style');
    style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
    document.head.appendChild(style);
    
    indicatorContainer.appendChild(spinner);
    indicatorContainer.appendChild(statusText);
    document.body.appendChild(indicatorContainer);
    
    // Переменная для хранения таймера авто-скрытия
    var autoHideTimer = null;
    var globalHideTimer = null;
    var isHidden = false;
    
    // Функция очистки таймеров
    function clearAllTimers() {
        if (autoHideTimer) {
            clearTimeout(autoHideTimer);
            autoHideTimer = null;
        }
        if (globalHideTimer) {
            clearTimeout(globalHideTimer);
            globalHideTimer = null;
        }
    }
    
    // Функция фактического скрытия индикатора
    function performHide() {
        if (isHidden) return;
        
        var container = document.getElementById('page-load-indicator');
        if (container) {
            container.style.opacity = '0';
            setTimeout(function() {
                if (container && container.parentNode) {
                    container.parentNode.removeChild(container);
                }
            }, 300);
        }
        isHidden = true;
        clearAllTimers();
        logDebug('[LOAD_INDICATOR] Indicator hidden');
    }
    
    // Функция обновления статуса (внутренняя)
    function updateStatus(status, message, keepVisible) {
        var spinnerEl = document.getElementById('load-indicator-spinner');
        var textEl = document.getElementById('load-indicator-text');
        var container = document.getElementById('page-load-indicator');
        
        if (!spinnerEl || !textEl || !container) return;
        
        // Если индикатор уже скрыт, не показываем его снова (кроме loading)
        if (isHidden && status !== 'loading') {
            logDebug('[LOAD_INDICATOR] Already hidden, ignoring status:', status);
            return;
        }
        
        logDebug('[LOAD_INDICATOR] Status update:', status, message, 'keepVisible:', keepVisible);
        
        // Очищаем предыдущие таймеры
        clearAllTimers();
        
        // Если индикатор был скрыт, но нужно показать loading - восстанавливаем
        if (isHidden && status === 'loading') {
            isHidden = false;
            container.style.opacity = '1';
            logDebug('[LOAD_INDICATOR] Re-showing indicator for loading');
        }
        
        switch(status) {
            case 'loading':
                spinnerEl.style.display = 'inline-block';
                spinnerEl.style.animation = 'spin 0.8s linear infinite';
                textEl.textContent = message || 'Загрузка...';
                textEl.style.color = '#e9eefc';
                container.style.opacity = '1';
                isHidden = false;
                
                // v1.4: Таймер безопасности - скрыть через 8 секунд, если не было ready
                globalHideTimer = setTimeout(function() {
                    if (!isHidden) {
                        logDebug('[LOAD_INDICATOR] Global safety timeout (5s) triggered, hiding indicator');
                        performHide();
                    }
                }, 5000);
                break;
                
            case 'ready':
                spinnerEl.style.display = 'none';
                textEl.textContent = message || '✓ Готово';
                textEl.style.color = '#22c55e';
                if (!keepVisible) {
                    // Таймер 3 секунд для готовности
                    autoHideTimer = setTimeout(function() {
                        performHide();
                    }, 3000);
                } else {
                    logDebug('[LOAD_INDICATOR] keepVisible=true, not hiding yet');
                    // v1.4: Даже с keepVisible=true скрываем через 8 секунд
                    globalHideTimer = setTimeout(function() {
                        if (!isHidden) {
                            logDebug('[LOAD_INDICATOR] keepVisible safety timeout (5s) triggered, hiding indicator');
                            performHide();
                        }
                    }, 5000);
                }
                break;
                
            case 'error':
                spinnerEl.style.display = 'none';
                textEl.textContent = message || '⚠️ Ошибка';
                textEl.style.color = '#f87171';
                if (!keepVisible) {
                    autoHideTimer = setTimeout(function() {
                        performHide();
                    }, 3000);
                } else {
                    globalHideTimer = setTimeout(function() {
                        if (!isHidden) {
                            logDebug('[LOAD_INDICATOR] Error safety timeout (8s) triggered, hiding indicator');
                            performHide();
                        }
                    }, 5000);
                }
                break;
        }
    }
    
    // Глобальная функция для внешнего управления
    window.setPageLoadStatus = function(status, message, keepVisible) {
        updateStatus(status, message, keepVisible === true);
    };
    
    // v1.4: Устанавливаем начальный статус "ready" НО с таймером скрытия через 8 секунд
    // Это гарантирует, что индикатор скроется даже если AJAX-загрузка не произойдёт
    window.setPageLoadStatus('ready', '✓ Готово', true);
    logDebug('[LOAD_INDICATOR] Initialized v1.4 - ready status with 8s safety timeout');
    
    // Обработка ошибок загрузки (но не скрываем сразу)
    window.addEventListener('error', function(e) {
        window.setPageLoadStatus('error', '⚠️ Ошибка: ' + (e.message || 'сеть'), false);
    });
})();
// ==================== BLOCK END: Global Loading Indicator v1.4 ====================
</script>