// ========== УНИВЕРСАЛЬНЫЕ TOAST-УВЕДОМЛЕНИЯ ==========
(function() {
    'use strict';
    
    // Контейнер для всех тостов
    let toastContainer = null;
    
    function getToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.style.cssText = 'position:fixed; bottom:20px; right:20px; z-index:10000; display:flex; flex-direction:column; gap:10px; max-width:350px;';
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }
    
    // Генерация уникального ID
    function generateId() {
        return 'toast_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
    
    // Копирование текста в буфер
    function copyToClipboard(text, toastId) {
        navigator.clipboard.writeText(text).then(function() {
            // Показываем временный индикатор копирования
            var copyBtn = document.querySelector('#toast-' + toastId + ' .toast-copy-btn');
            if (copyBtn) {
                var originalText = copyBtn.innerHTML;
                copyBtn.innerHTML = '✓';
                setTimeout(function() {
                    copyBtn.innerHTML = originalText;
                }, 1000);
            }
        }).catch(function(err) {
            logError('Ошибка копирования:', err);
            // Fallback для старых браузеров
            var textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        });
    }
    
    window.showToast = function(message, type, duration) {
        // type: 'success', 'error', 'warning', 'info'
        // duration: время в мс (по умолчанию 5000)
        
        type = type || 'info';
        duration = duration || 5000;

        var maxToastLength = 100;
        if (message && message.length > maxToastLength) {
            message = message.substring(0, maxToastLength - 1) + '…';
        }
        
        var bgColor = '#22c55e'; // success green
        var icon = '✓';
        
        switch(type) {
            case 'error':
                bgColor = '#ef4444';
                icon = '✗';
                break;
            case 'warning':
                bgColor = '#f59e0b';
                icon = '⚠';
                break;
            case 'info':
                bgColor = '#3b82f6';
                icon = 'ℹ';
                break;
            default:
                bgColor = '#22c55e';
                icon = '✓';
        }
        
        var id = generateId();
        var container = getToastContainer();
        
        var toast = document.createElement('div');
        toast.id = 'toast-' + id;
        toast.style.cssText = 'background:' + bgColor + '; color:white; border-radius:8px; padding:12px 16px; box-shadow:0 4px 12px rgba(0,0,0,0.3); display:flex; align-items:flex-start; gap:12px; animation:slideInRight 0.3s ease; max-width:100%; word-break:break-word;';
        
        // HTML структура тоста
        toast.innerHTML = `
            <div style="font-size:18px; flex-shrink:0;">${icon}</div>
            <div style="flex:1; font-size:13px; line-height:1.4; white-space:pre-wrap;">${escapeHtml(message)}</div>
            <div style="display:flex; gap:6px; flex-shrink:0;">
                <button class="toast-copy-btn" style="background:rgba(255,255,255,0.2); border:none; color:white; cursor:pointer; padding:4px 8px; border-radius:4px; font-size:11px;" title="Копировать текст">📋</button>
                <button class="toast-close-btn" style="background:rgba(255,255,255,0.2); border:none; color:white; cursor:pointer; padding:4px 8px; border-radius:4px; font-size:14px;" title="Закрыть">✕</button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Обработчик копирования
        var copyBtn = toast.querySelector('.toast-copy-btn');
        copyBtn.addEventListener('click', function() {
            copyToClipboard(message, id);
        });
        
        // Обработчик закрытия
        var closeBtn = toast.querySelector('.toast-close-btn');
        closeBtn.addEventListener('click', function() {
            removeToast(id);
        });
        
        // Автоматическое закрытие через duration
        var timeoutId = setTimeout(function() {
            removeToast(id);
        }, duration);
        
        // Сохраняем timeout для возможной отмены
        toast.dataset.timeoutId = timeoutId;
        
        function removeToast(toastId) {
            var toastElement = document.getElementById('toast-' + toastId);
            if (toastElement) {
                var timeoutId = toastElement.dataset.timeoutId;
                if (timeoutId) clearTimeout(parseInt(timeoutId));
                toastElement.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(function() {
                    if (toastElement.parentNode) toastElement.remove();
                }, 300);
            }
        }
        
        return id;
    };
    
    // Вспомогательная функция для экранирования HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Добавляем CSS анимации
    var style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
    
    if (typeof logDebug === 'function') {
        logDebug('[TOAST] Универсальные уведомления инициализированы');
    } else if (console && console.log) {
        console.log('[TOAST] Универсальные уведомления инициализированы');
    }
})();