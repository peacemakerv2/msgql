/**
* messages_render.js
* Version: 3.3.0
* Changelog:
* - 3.3.0 (2026-10-14): FIX КРИТИЧЕСКИЙ: разделены scrollContainer (messages-area) и
*                       innerContainer (messages-area-inner). Скролл теперь выполняется
*                       по ВНЕШНЕМУ контейнеру, а сообщения вставляются во ВНУТРЕННИЙ
*                       flex-контейнер. Это восстанавливает автопрокрутку к последнему
*                       сообщению.
*                       FIX: восстановлена очередь enqueueRequest/processRequestQueue
*                       (потеряна при рефакторинге). Это гарантирует последовательность
*                       initTask → loadTaskUsers → loadTaskLastPage.
* - 3.2.0 (2026-10-14): FIX loadTaskUsersSequential обновляет локальный taskUsersCache.
*                       Добавлена selectTaskByUuid.
* - 3.1.0 (2026-10-14): Восстановлены отсутствовавшие функции.
* - 3.0.0 (2026-10-14): Added setupFileEventDelegation, navigation, pagination.
* - 1.0.0 (2026-10-14): Initial split from messages.php.
*/

(function() {
    // ==================== АЛИАСЫ ЛОГИРОВАНИЯ ====================
    var logDebug = window.logDebug || function() {};
    var logError = window.logError || function() {};

    // ==================== УТИЛИТЫ ФОРМАТИРОВАНИЯ ====================

    function escapeHtmlStatic(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(ts) {
        var d = new Date(parseInt(ts));
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var tz = -d.getTimezoneOffset() / 60;
        var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
        var tStr = d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'});
        if (msgDate.getTime() === today.getTime()) {
            return tStr + ' (' + tzName + ')';
        } else if (msgDate.getTime() === today.getTime() - 86400000) {
            return 'Вчера ' + tStr + ' (' + tzName + ')';
        } else {
            return d.toLocaleDateString('ru-RU') + ' ' + tStr + ' (' + tzName + ')';
        }
    }

    function formatFileSize(b) {
        if (b >= 1e9) return (b/1e9).toFixed(2) + ' GB';
        if (b >= 1e6) return (b/1e6).toFixed(2) + ' MB';
        if (b >= 1024) return (b/1024).toFixed(1) + ' KB';
        return b + ' B';
    }

    function getFileIcon(n) {
        if (!n) return '📎';
        var e = n.split('.').pop().toLowerCase();
        return {jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',pdf:'📄',doc:'📝',docx:'📝',xls:'📊',xlsx:'📊',zip:'📦',rar:'📦','7z':'📦',mp3:'🎵',mp4:'🎬',avi:'🎬',txt:'📃'}[e] || '📎';
    }

    // ==================== КОНТЕЙНЕРЫ ====================
    // ВАЖНО: во всём модуле придерживаемся соглашения:
    //   - scrollContainer  = document.getElementById('messages-area')      — скроллируемый
    //   - innerContainer   = document.getElementById('messages-area-inner') — flex-контейнер для сообщений
    // Сообщения ВСЕГДА вставляются в innerContainer.
    // Скролл ВСЕГДА выполняется по scrollContainer.

    function getScrollContainer() {
        return document.getElementById('messages-area');
    }

    function getInnerContainer() {
        var inner = document.getElementById('messages-area-inner');
        if (inner) return inner;
        var outer = document.getElementById('messages-area');
        if (!outer) return null;
        inner = document.createElement('div');
        inner.id = 'messages-area-inner';
        inner.className = 'messages-area-inner';
        // Переносим существующие дочерние элементы внутрь
        while (outer.firstChild) {
            inner.appendChild(outer.firstChild);
        }
        outer.appendChild(inner);
        logDebug('[CONTAINERS] messages-area-inner created on demand');
        return inner;
    }

    // ==================== ОЧЕРЕДЬ ЗАПРОСОВ ====================
    // FIX v3.3.0: восстановлено из оригинала. Гарантирует последовательность
    // initTask → loadTaskUsers → loadTaskLastPage и предотвращает race conditions.

    window._requestQueue = window._requestQueue || [];
    window._isRequestActive = window._isRequestActive || false;

    function processRequestQueue() {
        if (window._isRequestActive) return;
        if (window._requestQueue.length === 0) return;
        var next = window._requestQueue.shift();
        window._isRequestActive = true;
        logDebug('[QUEUE] Processing next request, remaining: ' + window._requestQueue.length);
        next(function() {
            window._isRequestActive = false;
            setTimeout(processRequestQueue, 300);
        });
    }

    function enqueueRequest(name, fn) {
        window._requestQueue.push(function(done) {
            logDebug('[QUEUE] Starting request: ' + name);
            var startTime = Date.now();
            fn(function() {
                var elapsed = Date.now() - startTime;
                logDebug('[QUEUE] Completed request: ' + name + ' in ' + elapsed + 'ms');
                done();
            });
        });
        processRequestQueue();
    }

    // ==================== ПАРСИНГ ТЕКСТА СООБЩЕНИЯ ====================

    function parseMessageText(text) {
        if (!text) return '';
        function escapeHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
        var result = text;

        var urlRegex = /(?:https?:\/\/|ftp:\/\/|ws:\/\/|wss:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|magnet:|skype:|viber:|whatsapp:|signal:)[a-zA-Z0-9\-._~:/?#\[\]@!$&'()*+,;=%]+/gi;
        result = result.replace(urlRegex, function(url) {
            var lowerUrl = url.toLowerCase();
            if (lowerUrl.indexOf('javascript:') === 0 || lowerUrl.indexOf('data:') === 0 || lowerUrl.indexOf('vbscript:') === 0) return url;
            var safeUrl = url.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            var isTelegram = (lowerUrl.indexOf('tg://') === 0 || lowerUrl.indexOf('telegram://') === 0);
            var linkClass = isTelegram ? 'external-link telegram-link' : 'external-link';
            var targetAttr = (lowerUrl.indexOf('mailto:') === 0 || lowerUrl.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            var displayText = url;
            if (displayText.length > 80) displayText = displayText.substring(0, 70) + '…' + displayText.substring(displayText.length - 10);
            return '<a href="' + safeUrl + '" class="' + linkClass + '"' + targetAttr + '>' + displayText + '</a>';
        });

        var currentHost = window.location.host;
        var appBase = window.APP_BASE || '';
        var baseUrl = window.location.protocol + '//' + currentHost + appBase;
        var UUID_REGEX = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
        function safeLink(uuid, type, displayText) {
            if (!UUID_REGEX.test(uuid)) return '[неверный идентификатор]';
            var encodedUuid = encodeURIComponent(uuid);
            switch(type) {
                case 'msg': return '<a href="' + baseUrl + '/messages.php?message=' + encodedUuid + '" class="smart-link-message" data-msg-uuid="' + encodedUuid + '">' + displayText + '</a>';
                case 'task': return '<a href="' + baseUrl + '/projects.php?task=' + encodedUuid + '" class="smart-link-task" target="_blank" rel="noopener noreferrer">' + displayText + '</a>';
                case 'file': return '<a href="' + baseUrl + '/file_preview.php?uuid=' + encodedUuid + '" class="smart-link-file" target="_blank" rel="noopener noreferrer">' + displayText + '</a>';
                default: return displayText;
            }
        }
        result = result.replace(/\[msg:([a-f0-9\-]{36})\]/gi, function(m, u) { return safeLink(u, 'msg', '💬 сообщение'); });
        result = result.replace(/\[task:([a-f0-9\-]{36})\]/gi, function(m, u) { return safeLink(u, 'task', '📋 задача'); });
        result = result.replace(/\[file:([a-f0-9\-]{36})\]/gi, function(m, u) { return safeLink(u, 'file', '📎 файл'); });

        result = result.replace(/```([a-z]*)\n([\s\S]*?)\n```/g, function(m, lang, code) {
            var langAttr = lang ? ' class="language-' + lang + '"' : '';
            return '<pre><code' + langAttr + '>' + escapeHtml(code) + '</code></pre>';
        });
        result = result.replace(/``\n([\s\S]*?)\n``/g, function(m, code) { return '<pre><code>' + escapeHtml(code) + '</code></pre>'; });
        result = result.replace(/`\n([\s\S]*?)\n`/g, function(m, code) { return '<pre><code>' + escapeHtml(code) + '</code></pre>'; });
        result = result.replace(/(?<!<code>)(?<!<pre>)(?<!<\/code>)(?<!<\/pre>)`([^`\n]+)`(?!<\/code>)(?!<\/pre>)/g, function(m, code) { return '<code>' + escapeHtml(code) + '</code>'; });

        result = result.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        result = result.replace(/__([^_]+)__/g, '<strong>$1</strong>');
        result = result.replace(/~~(.+?)~~/g, '<del>$1</del>');

        result = result.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        result = result.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        result = result.replace(/^# (.+)$/gm, '<h1>$1</h1>');
        result = result.replace(/###\s+([^\n<]+)/g, '<h3>$1</h3>');
        result = result.replace(/##\s+([^\n<]+)/g, '<h2>$1</h2>');
        result = result.replace(/#\s+([^\n<]+)/g, '<h1>$1</h1>');

        result = result.replace(/^(\d+)\.\s+(.+)$/gm, '<li>$2</li>');
        result = result.replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>');
        result = result.replace(/((?:<li>.*<\/li>\s*)+)/g, function(match) {
            if (match.indexOf('</li>') !== -1) {
                var hasNumbers = /<li>\d+\./.test(match);
                if (hasNumbers) {
                    var cleaned = match.replace(/<li>\d+\.\s+/g, '<li>');
                    return '<ol>' + cleaned + '</ol>';
                }
                return '<ul>' + match + '</ul>';
            }
            return match;
        });

        result = result.replace(/^(---|\*\*\*|___)$/gm, '<hr>');
        result = result.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');

        result = result.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(m, linkText, url) {
            var lowerUrl = url.toLowerCase();
            if (lowerUrl.indexOf('javascript:') === 0 || lowerUrl.indexOf('data:') === 0 || lowerUrl.indexOf('vbscript:') === 0) return linkText;
            var safeUrl = escapeHtml(url);
            var targetAttr = (lowerUrl.indexOf('mailto:') === 0 || lowerUrl.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            return '<a href="' + safeUrl + '"' + targetAttr + '>' + linkText + '</a>';
        });

        var lines = result.split('\n');
        var finalResult = [];
        for (var j = 0; j < lines.length; j++) {
            var line = lines[j];
            if (/^\s*<[a-z]/.test(line) || /^\s*<\/[a-z]/.test(line)) {
                finalResult.push(line);
            } else {
                finalResult.push(line);
                if (j < lines.length - 1) {
                    var nextLine = lines[j + 1];
                    if (!/^\s*<[a-z]/.test(nextLine) && !/^\s*<\/[a-z]/.test(nextLine)) {
                        finalResult.push('<br>');
                    }
                }
            }
        }
        return finalResult.join('');
    }

    // ==================== РЕНДЕРИНГ СООБЩЕНИЯ ====================

    function renderMessage(msg) {
        var own = msg.user_uuid === window.currentUserUuid;
        var init = msg.user_name ? msg.user_name.charAt(0).toUpperCase() : 'U';
        var unread = (msg.is_read === 0 && !own) ? 'unread' : '';
        var processedText = parseMessageText(msg.text || '');
        var pureText = msg.text || '';
        var pureTextForAttr = encodeURIComponent(pureText);

        var replyHtml = '';
        if (msg.reply_to && msg.reply_to.uuid) {
            var replyAuthor = escapeHtmlStatic(msg.reply_to.user_name || 'Пользователь');
            var replyTextShort = escapeHtmlStatic((msg.reply_to.text || '').substring(0, 200));
            replyHtml = '<div class="message-quote clickable-quote" data-quote-uuid="' + msg.reply_to.uuid + '">';
            replyHtml += '<strong>' + replyAuthor + '</strong>: ';
            replyHtml += replyTextShort;
            if ((msg.reply_to.text || '').length > 200) replyHtml += '…';
            replyHtml += '</div>';
        }

        var fHtml = '';
        if (msg.files && msg.files.length) {
            fHtml = '<div class="message-files">';
            for (var fi = 0; fi < msg.files.length; fi++) {
                var f = msg.files[fi];
                var safeName = (f.name || 'Файл').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
                var safeMime = (f.mime || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
                var msgTextForAttr = encodeURIComponent(pureText);
                if (f.mime && f.mime.startsWith('image/')) {
                    var imageUrl = window.APP_BASE + '/download.php?file=' + f.uuid + '&preview=1';
                    fHtml += '<div class="file-image-wrapper" data-msg-uuid="' + msg.uuid + '" data-file-uuid="' + f.uuid + '" data-is-own="' + (own ? 'true' : 'false') + '" data-file-name="' + safeName + '" data-file-size="' + (f.size_bytes || 0) + '" data-file-mime="' + safeMime + '" data-message-text="' + msgTextForAttr + '" style="display: inline-flex; flex-direction: column; align-items: center; gap: 4px;">';
                    fHtml += '<div class="file-preview-thumb" onclick="event.stopPropagation(); showFilePreview(\''+f.uuid+'\',\''+safeName+'\','+(f.size_bytes||0)+',\''+safeMime+'\');" title="Нажмите для просмотра">';
                    fHtml += '<img class="lazy-preview" data-src="' + imageUrl + '" src="" alt="' + escapeHtmlStatic(f.name) + '">';
                    fHtml += '<div class="file-preview-overlay">🔍</div>';
                    fHtml += '</div></div>';
                } else {
                    fHtml += '<div style="display: inline-flex; align-items: center; gap: 4px;">';
                    fHtml += '<a href="#" class="file-item" data-file-uuid="' + f.uuid + '" data-file-name="' + safeName + '" data-file-size="' + (f.size_bytes || 0) + '" data-file-mime="' + safeMime + '" onclick="event.stopPropagation(); showFilePreview(\''+f.uuid+'\',\''+safeName+'\','+(f.size_bytes||0)+',\''+safeMime+'\');return false;">'+getFileIcon(f.name)+' '+escapeHtmlStatic(f.name)+' ('+f.size+')</a>';
                    fHtml += '</div>';
                }
            }
            fHtml += '</div>';
        }

        var dataTextForAttr = encodeURIComponent(pureText);
        var replyAttr = (msg.reply_to && msg.reply_to.uuid) ? ' data-reply-uuid="' + msg.reply_to.uuid + '"' : '';

        return '<div class="message '+(own?'own':'')+' '+unread+'" id="msg-'+msg.uuid+'" data-uuid="'+msg.uuid+'" data-text="'+dataTextForAttr+'" data-original-text="'+dataTextForAttr+'" data-time="'+msg.time+'"' + replyAttr + ' onclick="showMessageMenu(event, \''+msg.uuid+'\', '+(own ? 'true' : 'false')+', \''+(msg.user_name||msg.user_login||'').replace(/'/g, "\\'")+'\', '+msg.time+')">' +
            '<div class="message-avatar">' + init + '</div>' +
            '<div class="message-content">' +
                '<div class="message-header">' +
                    '<span class="message-author">' + escapeHtmlStatic(msg.user_name || msg.user_login || 'Пользователь') + '</span>' +
                    '<span class="message-time">' + formatTime(msg.time) + '</span>' +
                '</div>' +
                replyHtml +
                '<div class="message-text">' + processedText + '</div>' +
                fHtml +
            '</div>' +
        '</div>';
    }

    // ==================== STATIC MOBILE SPACER ====================

    function applyStaticMobileSpacer() {
        if (window.innerWidth > 768) return;
        var container = getInnerContainer();
        if (!container) container = getScrollContainer();
        if (!container) return;
        var existingSpacers = container.querySelectorAll('.message-last-spacer');
        for (var i = 0; i < existingSpacers.length; i++) {
            existingSpacers[i].remove();
        }
        var currentPadding = window.getComputedStyle(container).paddingBottom;
        var targetPadding = '300px';
        if (currentPadding !== targetPadding) {
            container.style.paddingBottom = targetPadding;
            logDebug('[SPACER] Applied static padding-bottom: ' + targetPadding);
        }
    }

    function setupStaticSpacer() {
        applyStaticMobileSpacer();
        window.addEventListener('resize', function() {
            setTimeout(applyStaticMobileSpacer, 100);
        });
        setTimeout(applyStaticMobileSpacer, 500);
        setTimeout(applyStaticMobileSpacer, 1500);
        setTimeout(applyStaticMobileSpacer, 3000);
    }

    // ==================== ПАГИНАЦИЯ ====================

    window.pagination = window.pagination || {
        currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE || 500,
        totalMessages: 0, isLoading: false, hasOlder: false, hasNewer: false,
        lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
        consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
        prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: false,
        pageCache: {}, currentWindowPage: 0, windowStartPage: 0, windowEndPage: 0
    };

    window._activeRequest = null;
    window._pendingLoadParams = null;

    function cancelActiveRequest() {
        if (window._activeRequest) {
            logDebug('[PAGINATION] Cancelling active request');
            try { window._activeRequest.abort(); } catch(e) {}
            window._activeRequest = null;
        }
    }

    function processPendingLoad() {
        if (window._pendingLoadParams && !window.pagination.isLoading) {
            var params = window._pendingLoadParams;
            window._pendingLoadParams = null;
            loadMessagesPage(params.page, {
                scrollToBottom: params.scrollToBottom,
                preserveScroll: params.preserveScroll,
                callback: params.callback,
                isLastPage: params.isLastPage,
                bufferSize: params.bufferSize
            });
        }
    }

    function loadMessagesPage(page, options) {
        options = options || {};
        var scrollToBottom = options.scrollToBottom || false;
        var preserveScroll = options.preserveScroll || false;
        var callback = options.callback || null;
        var isLastPage = options.isLastPage || false;
        var bufferSize = options.bufferSize || 1;
        var oldScrollTopParam = options.oldScrollTop || 0;

        logDebug('[PAGINATION_v8] loadMessagesPage called: page=' + page);

        if (!window.currentTaskUuid) {
            logDebug('[PAGINATION_v8] No task selected');
            if (callback) callback({ success: false, error: 'No task selected' });
            return;
        }
        if (window.pagination.isLoading && window._activeRequest) {
            logDebug('[PAGINATION_v8] Already loading, queueing');
            window._pendingLoadParams = { page: page, scrollToBottom: scrollToBottom, preserveScroll: preserveScroll, callback: callback, isLastPage: isLastPage, bufferSize: bufferSize };
            return;
        }
        var requestHash = window.currentTaskUuid + '_window_' + page + '_buffer_' + bufferSize;
        var now = Date.now();
        if (window.pagination.lastRequestHash === requestHash && (now - window.pagination.lastRequestTime) < 500) {
            logDebug('[PAGINATION_v8] Duplicate request ignored');
            return;
        }
        window.pagination.lastRequestHash = requestHash;
        window.pagination.lastRequestTime = now;
        window.pagination.isLoading = true;
        window.pagination.retryCount = 0;

        // FIX v3.3.0: old scrollTop читаем с ВНЕШНЕГО контейнера (scroll)
        var scrollContainer = getScrollContainer();
        var oldScrollTop = oldScrollTopParam || (scrollContainer ? scrollContainer.scrollTop : 0);

        var formData = new URLSearchParams();
        formData.append('action', 'load_messages_paged');
        formData.append('task_uuid', window.currentTaskUuid);
        formData.append('page', page);
        formData.append('buffer_size', bufferSize);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        cancelActiveRequest();
        window._activeRequest = new XMLHttpRequest();
        var xhr = window._activeRequest;
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;

        xhr.onload = function() {
            window.pagination.isLoading = false;
            window._activeRequest = null;
            if (xhr.status === 503 && window.pagination.retryCount < window.pagination.maxRetries) {
                window.pagination.retryCount++;
                var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
                logDebug('[PAGINATION_v8] 503, retry ' + window.pagination.retryCount + ' in ' + delay + 'ms');
                setTimeout(function() { loadMessagesPage(page, options); }, delay);
                if (callback) callback({ success: false, error: '503' });
                processPendingLoad();
                return;
            }
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        window.pagination.totalMessages = data.total_count;
                        window.pagination.totalPages = data.total_pages;
                        window.pagination.currentWindowPage = data.current_window_page;
                        window.pagination.windowStartPage = data.window_start_page;
                        window.pagination.windowEndPage = data.window_end_page;
                        if (!window.pagination.pageCache) window.pagination.pageCache = {};
                        if (data.pages_data) {
                            for (var p in data.pages_data) {
                                if (data.pages_data.hasOwnProperty(p)) {
                                    window.pagination.pageCache[p] = data.pages_data[p];
                                }
                            }
                        }
                        var currentPageData = window.pagination.pageCache[page];
                        if (currentPageData) {
                            window.pagination.hasOlder = currentPageData.has_older;
                            window.pagination.hasNewer = currentPageData.has_newer;
                        } else {
                            window.pagination.hasOlder = (page > 0);
                            window.pagination.hasNewer = (page + 1) < data.total_pages;
                        }
                        renderWindowPages({
                            scrollToBottom: scrollToBottom,
                            preserveScroll: preserveScroll,
                            oldScrollTop: oldScrollTop,
                            targetPage: page,
                            isLastPage: isLastPage
                        });
                        if (callback) callback(data);
                    } else if (callback) callback({ success: false, error: data.error });
                } catch(e) {
                    logDebug('[PAGINATION_v8] JSON parse error: ' + e.message);
                    if (callback) callback({ success: false, error: e.message });
                }
            } else if (callback) callback({ success: false, error: 'HTTP ' + xhr.status });
            processPendingLoad();
        };
        xhr.onerror = function() {
            window.pagination.isLoading = false;
            window._activeRequest = null;
            logDebug('[PAGINATION_v8] Network error');
            if (window.pagination.retryCount < window.pagination.maxRetries) {
                window.pagination.retryCount++;
                var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
                setTimeout(function() { loadMessagesPage(page, options); }, delay);
            } else if (callback) callback({ success: false, error: 'Network error' });
            processPendingLoad();
        };
        xhr.ontimeout = function() {
            window.pagination.isLoading = false;
            window._activeRequest = null;
            logDebug('[PAGINATION_v8] Timeout');
            if (window.pagination.retryCount < window.pagination.maxRetries) {
                window.pagination.retryCount++;
                var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
                setTimeout(function() { loadMessagesPage(page, options); }, delay);
            } else if (callback) callback({ success: false, error: 'Timeout' });
            processPendingLoad();
        };
        xhr.send(formData);
    }

    // ==================== ОТРИСОВКА ОКНА СТРАНИЦ ====================
    // FIX v3.3.0: КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ.
    // scrollContainer = messages-area (скроллируемый)
    // innerContainer  = messages-area-inner (flex-контейнер для сообщений)
    // Все операции со scrollTop/scrollHeight/clientHeight — на scrollContainer.
    // Все операции с содержимым (innerHTML/insertAdjacentHTML/children) — на innerContainer.

    function renderWindowPages(options) {
        var scrollContainer = getScrollContainer();
        var innerContainer = getInnerContainer();

        if (!scrollContainer && !innerContainer) {
            logDebug('[RENDER_WINDOW] Container not found!');
            return;
        }
        if (!scrollContainer) scrollContainer = innerContainer;
        if (!innerContainer) innerContainer = scrollContainer;

        var scrollToBottom = options.scrollToBottom || false;
        var preserveScroll = options.preserveScroll || false;
        var oldScrollTop = options.oldScrollTop || 0;
        var isLastPage = options.isLastPage || false;

        var isDesktop = window.innerWidth > 768;
        var body = document.body;
        var html = document.documentElement;
        var originalBodyOverflow = null;
        var originalHtmlOverflow = null;

        if (!isDesktop) {
            originalBodyOverflow = body.style.overflow;
            originalHtmlOverflow = html.style.overflow;
            body.style.overflow = 'hidden';
            html.style.overflow = 'hidden';
        } else {
            if (window.scrollY !== 0) window.scrollTo(0, 0);
        }

        // Собираем сообщения из кэша
        var allMessages = [];
        if (window.pagination.pageCache) {
            var pageNumbers = Object.keys(window.pagination.pageCache).map(Number).sort(function(a,b) { return a - b; });
            for (var i = 0; i < pageNumbers.length; i++) {
                var pageData = window.pagination.pageCache[pageNumbers[i]];
                if (pageData && pageData.messages && pageData.messages.length > 0) {
                    for (var j = 0; j < pageData.messages.length; j++) allMessages.push(pageData.messages[j]);
                }
            }
        }

        if (allMessages.length === 0) {
            innerContainer.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
            if (!isDesktop) { body.style.overflow = originalBodyOverflow; html.style.overflow = originalHtmlOverflow; }
            return;
        }

        // Запоминаем состояние СКРОЛЛ-контейнера
        var oldScrollHeight = scrollContainer.scrollHeight;
        var wasAtBottom = (scrollContainer.scrollHeight > 0 && scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 50);
        var topVisibleElement = null;
        var topVisibleOffset = 0;
        if (preserveScroll && oldScrollTop === 0 && innerContainer.children.length > 0) {
            var viewportTop = scrollContainer.scrollTop;
            for (var i = 0; i < innerContainer.children.length; i++) {
                var child = innerContainer.children[i];
                if (child.offsetTop >= viewportTop) {
                    topVisibleElement = child;
                    topVisibleOffset = child.offsetTop - viewportTop;
                    break;
                }
            }
        }

        // Вставляем сообщения в INNER-контейнер
        innerContainer.innerHTML = '';
        for (var i = 0; i < allMessages.length; i++) {
            innerContainer.insertAdjacentHTML('beforeend', renderMessage(allMessages[i]));
        }
        // Форсируем reflow для правильного измерения scrollHeight
        void scrollContainer.offsetHeight;

        if (allMessages.length > 0) {
            var maxTime = allMessages[allMessages.length - 1].time || 0;
            if (maxTime > window.lastMessageTime) window.lastMessageTime = maxTime;
        }
        var newScrollHeight = scrollContainer.scrollHeight;

        function restoreBodyScroll() {
            if (!isDesktop && originalBodyOverflow !== null) {
                body.style.overflow = originalBodyOverflow;
                html.style.overflow = originalHtmlOverflow;
            }
        }
        function safeScrollToBottom() {
            // FIX v3.3.0: скроллим ВНЕШНИЙ контейнер
            scrollContainer.scrollTop = scrollContainer.scrollHeight;
            setTimeout(function() {
                if (!isDesktop && window.scrollY !== 0) window.scrollTo(0, 0);
            }, 50);
            restoreBodyScroll();
        }
        function safeRestoreScroll(targetScrollTop) {
            // FIX v3.3.0: скроллим ВНЕШНИЙ контейнер
            scrollContainer.scrollTop = targetScrollTop;
            setTimeout(function() {
                if (!isDesktop && window.scrollY !== 0) window.scrollTo(0, 0);
            }, 50);
            restoreBodyScroll();
        }

        // Логика прокрутки
        if (scrollToBottom || isLastPage || wasAtBottom) {
            // FIX v3.3.0: несколько попыток прокрутки — компенсируют рост высоты
            // из-за подгрузки картинок/шрифтов на первых кадрах
            setTimeout(safeScrollToBottom, 100);
            setTimeout(safeScrollToBottom, 400);
            setTimeout(safeScrollToBottom, 1000);
        } else if (preserveScroll && oldScrollTop > 0) {
            setTimeout(function() {
                var targetScrollTop = oldScrollTop;
                if (newScrollHeight > oldScrollHeight) targetScrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
                targetScrollTop = Math.min(targetScrollTop, scrollContainer.scrollHeight - scrollContainer.clientHeight);
                safeRestoreScroll(targetScrollTop);
            }, 100);
        } else if (preserveScroll && topVisibleElement) {
            setTimeout(function() {
                if (topVisibleElement.parentNode === innerContainer) {
                    var newTop = topVisibleElement.offsetTop - topVisibleOffset;
                    newTop = Math.max(0, Math.min(newTop, scrollContainer.scrollHeight - scrollContainer.clientHeight));
                    safeRestoreScroll(newTop);
                } else restoreBodyScroll();
            }, 100);
        } else restoreBodyScroll();

        setTimeout(function() {
            loadLazyPreviews();
            markVisibleMessagesAsRead();
        }, 200);
    }

    // ==================== ПРОКРУТКА К СООБЩЕНИЮ ====================

    function scrollToMessageByUuid(messageUuid, retryCount) {
        if (retryCount === undefined) retryCount = 0;
        logDebug('[SCROLL_TO_MSG] Called with UUID:', messageUuid);
        var msgElement = document.getElementById('msg-' + messageUuid);
        if (msgElement) {
            msgElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            msgElement.classList.add('message-highlight');
            setTimeout(function() { msgElement.classList.remove('message-highlight'); }, 20000);
            return;
        }
        if (typeof window.showToast === 'function') window.showToast('Загрузка сообщения...', 'info');
        var formData = new URLSearchParams();
        formData.append('action', 'get_message_info');
        formData.append('message_uuid', messageUuid);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.message) {
                        var targetTaskUuid = data.message.task_uuid;
                        if (window.currentTaskUuid !== targetTaskUuid) {
                            var taskElement = document.querySelector('.task-item[data-task-uuid="' + targetTaskUuid + '"]');
                            if (taskElement && typeof window.selectTask === 'function') {
                                window.selectTask(taskElement);
                                setTimeout(function() { scrollToMessageByUuid(messageUuid); }, 1000);
                            } else {
                                window.location.href = window.APP_BASE + '/messages.php?message=' + messageUuid;
                            }
                        } else {
                            if (typeof window.loadTaskLastPageSequential === 'function') {
                                window.loadTaskLastPageSequential(window.currentTaskUuid, function() {
                                    setTimeout(function() {
                                        var el = document.getElementById('msg-' + messageUuid);
                                        if (el) {
                                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                            el.classList.add('message-highlight');
                                        } else if (retryCount < 3) setTimeout(function() { scrollToMessageByUuid(messageUuid, retryCount + 1); }, 1000);
                                    }, 800);
                                });
                            } else location.reload();
                        }
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Сообщение не найдено', 'warning');
                    }
                } catch(e) { logDebug('[SCROLL_TO_MSG] JSON parse error:', e.message); }
            }
        };
        xhr.send(formData);
    }

    // ==================== МАРКИРОВКА ПРОЧИТАННЫХ ====================

    var pendingReadMessages = [];
    var readMarkTimeout = null;

    function markVisibleMessagesAsRead() {
        var container = getInnerContainer();
        if (!container) container = getScrollContainer();
        if (!container) return;
        var unreadMessages = container.querySelectorAll('.message.unread:not(.own)');
        if (unreadMessages.length === 0) return;
        var unreadUuids = [];
        for (var i = 0; i < unreadMessages.length; i++) {
            var uuid = unreadMessages[i].getAttribute('data-uuid');
            if (uuid) unreadUuids.push(uuid);
        }
        if (unreadUuids.length > 0) queueMarkMessagesRead(unreadUuids);
    }

    function queueMarkMessagesRead(messageUuids) {
        if (!messageUuids || messageUuids.length === 0) return;
        for (var i = 0; i < messageUuids.length; i++) {
            if (pendingReadMessages.indexOf(messageUuids[i]) === -1) pendingReadMessages.push(messageUuids[i]);
        }
        if (readMarkTimeout) clearTimeout(readMarkTimeout);
        readMarkTimeout = setTimeout(function() {
            if (pendingReadMessages.length === 0) return;
            sendMarkReadRequest(pendingReadMessages.slice());
            pendingReadMessages = [];
        }, 1000);
    }

    function sendMarkReadRequest(uuidsToMark, retryCount) {
        if (retryCount === undefined) retryCount = 0;
        var maxRetries = 3;
        var formData = new URLSearchParams();
        formData.append('action', 'mark_messages_read');
        formData.append('task_uuid', window.currentTaskUuid);
        for (var i = 0; i < uuidsToMark.length; i++) formData.append('message_uuids[]', uuidsToMark[i]);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 15000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success) {
                        for (var i = 0; i < uuidsToMark.length; i++) {
                            var msgElement = document.querySelector('.message[data-uuid="' + uuidsToMark[i] + '"]');
                            if (msgElement) msgElement.classList.remove('unread');
                        }
                    }
                } catch(e) {}
            } else if (xhr.status === 503 && retryCount < maxRetries) {
                setTimeout(function() { sendMarkReadRequest(uuidsToMark, retryCount + 1); }, 2000 * Math.pow(2, retryCount));
            }
        };
        xhr.send(formData);
    }

    // ==================== СКРОЛЛ ====================

    var lastBottomLoadTime = 0;
    var BOTTOM_LOAD_COOLDOWN = 500;
    var userScrollTimeout = null;

    function onMessagesScroll() {
        // FIX v3.3.0: слушаем скролл ВНЕШНЕГО контейнера
        var container = getScrollContainer();
        if (!container || window.pagination.isLoading) return;
        var scrollTop = container.scrollTop;
        var scrollHeight = container.scrollHeight;
        var clientHeight = container.clientHeight;
        var now = Date.now();
        var direction = null;
        if (scrollTop < window.pagination.lastScrollTop - 5) direction = 'up';
        else if (scrollTop > window.pagination.lastScrollTop + 5) direction = 'down';
        window.pagination.lastScrollTop = scrollTop;
        window.pagination.lastScrollTime = now;
        window.pagination.scrollDirection = direction;

        window.userIsScrolling = true;
        if (userScrollTimeout) clearTimeout(userScrollTimeout);
        userScrollTimeout = setTimeout(function() { window.userIsScrolling = false; }, 1500);

        var isAtTop = scrollTop <= 50;
        var distanceToBottom = scrollHeight - (scrollTop + clientHeight);
        var isAtBottom = distanceToBottom <= 100;

        if (isAtTop && direction === 'up' && window.pagination.hasOlder && !window.pagination.isLoading) {
            window.pagination.consecutiveScrolls.up++;
            if (window.pagination.consecutiveScrolls.up >= 1) {
                loadOlderMessages();
                window.pagination.consecutiveScrolls.up = 0;
            }
        } else if (isAtBottom && direction === 'down' && window.pagination.hasNewer && !window.pagination.isLoading) {
            var nowMs = Date.now();
            if (nowMs - lastBottomLoadTime > BOTTOM_LOAD_COOLDOWN) {
                window.pagination.consecutiveScrolls.down++;
                if (window.pagination.consecutiveScrolls.down >= 1) {
                    lastBottomLoadTime = nowMs;
                    loadNewerMessages();
                    window.pagination.consecutiveScrolls.down = 0;
                }
            }
        }
        if (direction !== window.pagination.scrollDirection && window.pagination.lastScrollTime && (now - window.pagination.lastScrollTime) > 500) {
            window.pagination.consecutiveScrolls.up = 0;
            window.pagination.consecutiveScrolls.down = 0;
        }
        if (!window._readMarkScheduled) {
            window._readMarkScheduled = true;
            setTimeout(function() {
                markVisibleMessagesAsRead();
                window._readMarkScheduled = false;
            }, 500);
        }
    }

    function loadOlderMessages() {
        if (!window.pagination.hasOlder || window.pagination.isLoading) return;
        var currentPage = window.pagination.currentWindowPage;
        var prevPage = currentPage - 1;
        if (prevPage < 0) { window.pagination.hasOlder = false; return; }
        var scrollContainer = getScrollContainer();
        var scrollPosition = scrollContainer ? scrollContainer.scrollTop : 0;
        if (window.pagination.pageCache && window.pagination.pageCache[prevPage]) {
            window.pagination.currentWindowPage = prevPage;
            window.pagination.hasOlder = (prevPage > 0);
            window.pagination.hasNewer = true;
            renderWindowPages({ preserveScroll: true, targetPage: prevPage, scrollToBottom: false, oldScrollTop: scrollPosition });
        } else {
            loadMessagesPage(prevPage, { preserveScroll: true, oldScrollTop: scrollPosition, bufferSize: 1 });
        }
    }

    function loadNewerMessages() {
        if (!window.pagination.hasNewer || window.pagination.isLoading) return;
        var currentPage = window.pagination.currentWindowPage;
        var nextPage = currentPage + 1;
        if (nextPage >= window.pagination.totalPages) { window.pagination.hasNewer = false; return; }
        if (window.pagination.pageCache && window.pagination.pageCache[nextPage]) {
            window.pagination.currentWindowPage = nextPage;
            window.pagination.hasOlder = true;
            window.pagination.hasNewer = (nextPage + 1) < window.pagination.totalPages;
            renderWindowPages({ preserveScroll: false, targetPage: nextPage, scrollToBottom: true });
        } else {
            loadMessagesPage(nextPage, { preserveScroll: false, scrollToBottom: true, bufferSize: 1 });
        }
    }

    // ==================== NAVIGATION / SIDEBAR ====================

    function getCurrentProjectUuid() {
        var activeTask = document.querySelector('.task-item.active');
        if (activeTask) {
            var projectItem = activeTask.closest('.project-item');
            if (projectItem) return projectItem.getAttribute('data-project-uuid');
        }
        return null;
    }

    function closeMobileDrawer() {
        var body = document.body;
        body.classList.remove('mobile-drawer-open');
        body.style.overflow = '';
    }

    function updateMobileDrawerBadge() {
        var badgeElement = document.getElementById('mobile-drawer-badge');
        if (!badgeElement) return;
        var csrfToken = window.csrfToken || '';
        fetch(window.APP_BASE + '/get_badges_data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.badges) {
                var totalCount = (data.badges.messages || 0) + (data.badges.projects || 0) + (data.badges.files || 0) + (data.badges.notifications || 0);
                if (totalCount > 0) {
                    badgeElement.textContent = totalCount > 99 ? '99+' : totalCount;
                    badgeElement.style.display = 'inline-block';
                } else badgeElement.style.display = 'none';
            }
        })
        .catch(function(err) {});
    }

    function scrollSidebarToActiveTask() {
        var currentUuid = window.currentTaskUuid;
        if (!currentUuid) return;
        function scrollToTaskInContainer(container, taskElement, isMobile) {
            if (!container || !taskElement) return false;
            var containerRect = container.getBoundingClientRect();
            var taskRect = taskElement.getBoundingClientRect();
            var relativeTop = taskRect.top - containerRect.top;
            var targetScrollTop = container.scrollTop + relativeTop - (container.clientHeight / 2) + (taskRect.height / 2);
            container.scrollTo({ top: Math.max(0, targetScrollTop), behavior: 'smooth' });
            return true;
        }
        function activateAndScrollToTaskInContainer(container, isMobile) {
            if (!container) return false;
            var taskElement = container.querySelector('.task-item[data-task-uuid="' + currentUuid + '"]');
            if (!taskElement) return false;
            if (!taskElement.classList.contains('active')) {
                container.querySelectorAll('.task-item').forEach(function(t) { t.classList.remove('active'); });
                taskElement.classList.add('active');
            }
            scrollToTaskInContainer(container, taskElement, isMobile);
            return true;
        }
        var body = document.body;
        var html = document.documentElement;
        var originalBodyOverflow = body.style.overflow;
        var originalHtmlOverflow = html.style.overflow;
        body.style.overflow = 'hidden';
        html.style.overflow = 'hidden';
        var scrollAttempts = [100, 300, 600, 1000, 2000];
        var attemptCount = 0;
        function doScrollAttempt() {
            if (attemptCount >= scrollAttempts.length) {
                body.style.overflow = originalBodyOverflow;
                html.style.overflow = originalHtmlOverflow;
                return;
            }
            var delay = scrollAttempts[attemptCount];
            attemptCount++;
            setTimeout(function() {
                if (window.currentTaskUuid !== currentUuid) {
                    body.style.overflow = originalBodyOverflow;
                    html.style.overflow = originalHtmlOverflow;
                    return;
                }
                var desktopSidebar = document.querySelector('.messenger-sidebar');
                if (desktopSidebar) activateAndScrollToTaskInContainer(desktopSidebar, false);
                var mobileSidebar = document.getElementById('mobile-drawer-panel');
                if (mobileSidebar && mobileSidebar.querySelector('.mobile-drawer-sidebar')) {
                    var mobileTasksContainer = mobileSidebar.querySelector('.mobile-drawer-sidebar');
                    activateAndScrollToTaskInContainer(mobileTasksContainer, true);
                }
                doScrollAttempt();
            }, delay);
        }
        doScrollAttempt();
    }

    function initTaskSequential(taskUuid, callback) {
        if (!taskUuid) { if(callback) callback(); return; }
        var formData = new URLSearchParams();
        formData.append('action', 'init_task');
        formData.append('task_uuid', taskUuid);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success) {
                        var inp = document.getElementById('message-input');
                        var btn = document.getElementById('send-btn');
                        var att = document.querySelector('.file-attach-btn');
                        var pw = document.getElementById('perm-warning');
                        if (d.can_write === false) {
                            if (pw) { pw.textContent = 'У вас нет прав на отправку сообщений в этой задаче'; pw.className = 'perm-warning error show'; }
                            if(inp){ inp.placeholder = 'Нет прав на отправку'; inp.disabled = true; }
                            if(btn) btn.disabled = true;
                            if(att) att.disabled = true;
                        } else {
                            if (pw) pw.classList.remove('show');
                            if(inp){ inp.placeholder = 'Сообщение (Enter)'; inp.disabled = false; }
                            if(btn) btn.disabled = false;
                            if(att) att.disabled = false;
                        }
                    }
                } catch(e) {}
            }
            if (callback) callback();
        };
        xhr.onerror = function() { if(callback) callback(); };
        xhr.ontimeout = function() { if(callback) callback(); };
        xhr.send(formData);
    }

    function loadTaskUsersSequential(taskUuid, callback) {
        if (!taskUuid) { if(callback) callback([]); return; }
        var formData = new URLSearchParams();
        formData.append('action', 'get_task_users');
        formData.append('task_uuid', taskUuid);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        formData.append('ajax_mode', '1');
        fetch(window.APP_BASE + '/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data && data.success && data.users) {
                taskUsersCache = data.users;
                taskUsersLoaded = true;
                window.taskUsersCache = data.users;
                window.taskUsersLoaded = true;
                if (callback) callback(data.users);
            } else if (callback) callback([]);
        })
        .catch(function(err) {
            if (callback) callback([]);
        });
    }

    function loadTaskLastPageSequential(taskUuid, callback, retryCount) {
        if (retryCount === undefined) retryCount = 0;
        var MAX_RETRIES = 3;
        if (!taskUuid || window.currentTaskUuid !== taskUuid) { if (callback) callback(); return; }
        var countFormData = new URLSearchParams();
        countFormData.append('action', 'load_messages_paged');
        countFormData.append('task_uuid', taskUuid);
        countFormData.append('page', 0);
        countFormData.append('just_count', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(countFormData);
        var countXhr = new XMLHttpRequest();
        countXhr.open('POST', window.APP_BASE + '/messages.php', true);
        countXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        countXhr.timeout = 30000;
        countXhr.onload = function() {
            if (window.currentTaskUuid !== taskUuid) { if (callback) callback(); return; }
            if (countXhr.status === 503 && retryCount < MAX_RETRIES) {
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                setTimeout(function() { loadTaskLastPageSequential(taskUuid, callback, retryCount + 1); }, delay);
                return;
            }
            if (countXhr.status === 200) {
                try {
                    var data = JSON.parse(countXhr.responseText);
                    if (data.success && data.total_pages !== undefined) {
                        var lastPage = Math.max(0, data.total_pages - 1);
                        window.pagination.totalPages = data.total_pages;
                        window.pagination.totalMessages = data.total_count || 0;
                        window.pagination.initializing = false;
                        var isDesktop = window.innerWidth > 768;
                        loadMessagesPage(lastPage, { scrollToBottom: !isDesktop, isLastPage: true, bufferSize: 1, callback: callback });
                    } else {
                        loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
                    }
                } catch(e) {
                    loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
                }
            } else if (countXhr.status >= 500 && countXhr.status < 600 && retryCount < MAX_RETRIES) {
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                setTimeout(function() { loadTaskLastPageSequential(taskUuid, callback, retryCount + 1); }, delay);
            } else {
                loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
            }
        };
        countXhr.onerror = function() {
            if (retryCount < MAX_RETRIES) {
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                setTimeout(function() { loadTaskLastPageSequential(taskUuid, callback, retryCount + 1); }, delay);
            } else loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
        };
        countXhr.ontimeout = function() {
            if (retryCount < MAX_RETRIES) {
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                setTimeout(function() { loadTaskLastPageSequential(taskUuid, callback, retryCount + 1); }, delay);
            } else loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
        };
        countXhr.send(countFormData);
    }

    function selectTask(el) {
        var editModal = document.getElementById('editMessageModal');
        if (editModal && editModal.style.display === 'flex') {
            editModal.style.display = 'none';
            if (window._editModalKeydownHandler) {
                document.removeEventListener('keydown', window._editModalKeydownHandler);
                window._editModalKeydownHandler = null;
            }
            window._editMessageContext = null;
        }

        var newTaskUuid = el.dataset.taskUuid;
        var previousTaskUuid = window.currentTaskUuid;

        if (newTaskUuid && newTaskUuid === previousTaskUuid) {
            var container = getScrollContainer();
            if (container) {
                var distanceToBottom = container.scrollHeight - (container.scrollTop + container.clientHeight);
                if (distanceToBottom > 100) {
                    var scrollDelays = [50, 150, 300, 600, 1000];
                    for (var d = 0; d < scrollDelays.length; d++) {
                        (function(delay) {
                            setTimeout(function() {
                                if (container && !window.userIsScrolling) {
                                    container.scrollTop = container.scrollHeight;
                                }
                            }, delay);
                        })(scrollDelays[d]);
                    }
                }
            }
            return;
        }
        if (!newTaskUuid || newTaskUuid === window.currentTaskUuid) return;

        cancelActiveRequest();

        window.pagination = {
            currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE, totalMessages: 0,
            isLoading: false, hasOlder: false, hasNewer: false,
            lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
            consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
            prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: true,
            pageCache: {}, currentWindowPage: 0, windowStartPage: 0, windowEndPage: 0
        };
        window._pendingLoadParams = null;
        window.currentTaskUuid = newTaskUuid;
        document.getElementById('current-task-uuid').value = newTaskUuid;

        document.querySelectorAll('.task-item').forEach(function(t){ t.classList.remove('active'); });
        el.classList.add('active');

        var taskTitle = el.querySelector('.task-title').textContent;
        var chatTitle = document.getElementById('chat-title');
        if (chatTitle) {
            chatTitle.textContent = taskTitle;
            chatTitle.href = window.APP_BASE + '/projects.php?task=' + newTaskUuid;
        }
        var taskFilesLink = document.getElementById('task-files-link');
        if (taskFilesLink) {
            taskFilesLink.href = window.APP_BASE + '/files.php?task=' + newTaskUuid;
            taskFilesLink.style.display = 'inline-flex';
        }
        document.getElementById('chat-subtitle').textContent = 'Обсуждение задачи';
        document.getElementById('input-area').style.display = 'block';

        // FIX v3.3.0: очищаем INNER-контейнер, не трогая скролл-контейнер
        var innerContainer = getInnerContainer();
        if (innerContainer) innerContainer.innerHTML = '<div class="loading-messages">⏳ Загрузка сообщений...</div>';
        window.lastMessageTime = 0;

        // FIX v3.3.0: используем очередь enqueueRequest, чтобы гарантировать порядок
        enqueueRequest('initTask_' + newTaskUuid, function(done) {
            initTaskSequential(newTaskUuid, done);
        });

        enqueueRequest('loadTaskUsers_' + newTaskUuid, function(done) {
            loadTaskUsersSequential(newTaskUuid, function() { done(); });
        });

        enqueueRequest('loadMessages_' + newTaskUuid, function(done) {
            loadTaskLastPageSequential(newTaskUuid, function() {
                logDebug('[SELECT_TASK] Last page loaded');
                var isDesktop = window.innerWidth > 768;
                if (!isDesktop) {
                    var scrollAttempts = [100, 300, 700, 1500, 2500];
                    for (var a = 0; a < scrollAttempts.length; a++) {
                        (function(delay) {
                            setTimeout(function() {
                                var msgsArea = getScrollContainer();
                                if (msgsArea && !window.userIsScrolling) {
                                    msgsArea.scrollTop = msgsArea.scrollHeight;
                                }
                            }, delay);
                        })(scrollAttempts[a]);
                    }
                }
                setTimeout(function() {
                    if (typeof applyStaticMobileSpacer === 'function') applyStaticMobileSpacer();
                }, 1000);
                done();
            });
        });

        scrollSidebarToActiveTask();

        var projectItem = el.closest('.project-item');
        if (projectItem) {
            var projectUuid = projectItem.getAttribute('data-project-uuid');
            if (projectUuid && typeof refreshSidebarForProject === 'function') {
                setTimeout(function() {
                    refreshSidebarForProject(projectUuid, function(success) {});
                }, 500);
            }
        }
    }

    function selectTaskByUuid(taskUuid) {
        if (!taskUuid) return;
        var taskElement = document.querySelector('.task-item[data-task-uuid="' + taskUuid + '"]');
        if (taskElement) {
            selectTask(taskElement);
        } else {
            window.location.href = window.APP_BASE + '/messages.php?task=' + taskUuid;
        }
    }

    function refreshSidebarForProject(projectUuid, callback) {
        if (!projectUuid) { if (callback) callback(false); return; }
        var formData = new URLSearchParams();
        formData.append('action', 'get_project_tasks');
        formData.append('project_uuid', projectUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.tasks) {
                        updateSidebarTasks(projectUuid, data.tasks);
                        if (callback) callback(true);
                    } else if (callback) callback(false);
                } catch(e) { if (callback) callback(false); }
            } else if (callback) callback(false);
        };
        xhr.onerror = function() { if (callback) callback(false); };
        xhr.send(formData);
    }

    function updateSidebarTasks(projectUuid, tasks) {
        var projectElement = document.querySelector('.messenger-sidebar .project-item[data-project-uuid="' + projectUuid + '"]');
        if (!projectElement) return;
        var tasksList = projectElement.querySelector('.tasks-list');
        if (!tasksList) return;
        var activeTaskUuid = window.currentTaskUuid;
        var newHtml = '';
        for (var i = 0; i < tasks.length; i++) {
            var task = tasks[i];
            var isActive = (task.uuid === activeTaskUuid);
            var activeClass = isActive ? 'active' : '';
            var countHtml = (task.messages_count > 0) ? '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + task.messages_count + ']</span>' : '';
            var unreadBadge = (task.unread_count > 0) ? '<span style="font-size:10px;background:#f07070;color:white;padding:1px 6px;border-radius:10px;margin-left:4px;font-weight:bold;">' + task.unread_count + ' нов.</span>' : '';
            var assigneeHtml = task.assignee_name ? '<div class="task-assignee">👤 ' + escapeHtmlStatic(task.assignee_name) + '</div>' : '';
            var depth = task.depth || 0;
            var style = 'padding-left: ' + (48 + (depth * 20)) + 'px;';
            if (depth > 0) style += ' border-left: 2px solid rgba(79,124,255,0.2); margin-left: ' + (depth * 20) + 'px;';
            newHtml += '<div class="task-item ' + activeClass + '" data-task-uuid="' + task.uuid + '" data-depth="' + depth + '" style="' + style + '">';
            newHtml += '<div class="task-title">' + escapeHtmlStatic(task.title) + ' ' + countHtml + ' ' + unreadBadge + '</div>';
            newHtml += assigneeHtml;
            newHtml += '</div>';
        }
        tasksList.innerHTML = newHtml;
        var newTaskItems = tasksList.querySelectorAll('.task-item');
        newTaskItems.forEach(function(taskItem) {
            taskItem.addEventListener('click', function(e) {
                e.stopPropagation();
                if (typeof window.selectTask === 'function') window.selectTask(this);
            });
        });
        var projectCount = projectElement.querySelector('.project-count');
        if (projectCount) projectCount.textContent = tasks.length;
        updateMobileSidebarTasks(projectUuid, tasks);
    }

    function updateMobileSidebarTasks(projectUuid, tasks) {
        var mobileProjectElement = document.querySelector('#mobile-drawer-panel .project-item[data-project-uuid="' + projectUuid + '"]');
        if (!mobileProjectElement) return;
        var mobileTasksList = mobileProjectElement.querySelector('.tasks-list');
        if (!mobileTasksList) return;
        var activeTaskUuid = window.currentTaskUuid;
        var newHtml = '';
        for (var i = 0; i < tasks.length; i++) {
            var task = tasks[i];
            var isActive = (task.uuid === activeTaskUuid);
            var activeClass = isActive ? 'active' : '';
            var countHtml = (task.messages_count > 0) ? '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + task.messages_count + ']</span>' : '';
            var assigneeHtml = task.assignee_name ? '<div class="task-assignee">👤 ' + escapeHtmlStatic(task.assignee_name) + '</div>' : '';
            var depth = task.depth || 0;
            var style = 'padding-left: ' + (48 + (depth * 20)) + 'px;';
            if (depth > 0) style += ' border-left: 2px solid rgba(79,124,255,0.2); margin-left: ' + (depth * 20) + 'px;';
            newHtml += '<div class="task-item ' + activeClass + '" data-task-uuid="' + task.uuid + '" data-depth="' + depth + '" style="' + style + '">';
            newHtml += '<div class="task-title">' + escapeHtmlStatic(task.title) + ' ' + countHtml + '</div>';
            newHtml += assigneeHtml;
            newHtml += '</div>';
        }
        mobileTasksList.innerHTML = newHtml;
        mobileTasksList.querySelectorAll('.task-item').forEach(function(taskItem) {
            taskItem.addEventListener('click', function(e) {
                e.stopPropagation();
                if (window.innerWidth <= 768) closeMobileDrawer();
                if (typeof window.selectTask === 'function') window.selectTask(this);
            });
        });
    }

    function updateChatHeaderForTask(taskUuid) {
        var taskElement = document.querySelector('.messenger-sidebar .task-item[data-task-uuid="' + taskUuid + '"]');
        if (!taskElement) {
            taskElement = document.querySelector('#mobile-drawer-panel .task-item[data-task-uuid="' + taskUuid + '"]');
        }
        if (!taskElement) {
            setTimeout(function() { updateChatHeaderForTask(taskUuid); }, 500);
            return;
        }
        var taskTitle = taskElement.querySelector('.task-title').textContent;
        var chatTitle = document.getElementById('chat-title');
        if (chatTitle) {
            chatTitle.textContent = taskTitle;
            chatTitle.href = window.APP_BASE + '/projects.php?task=' + taskUuid;
        }
        var taskFilesLink = document.getElementById('task-files-link');
        if (taskFilesLink) {
            taskFilesLink.href = window.APP_BASE + '/files.php?task=' + taskUuid;
            taskFilesLink.style.display = 'inline-flex';
        }
        document.querySelectorAll('.task-item').forEach(function(t){ t.classList.remove('active'); });
        taskElement.classList.add('active');
        var projectItem = taskElement.closest('.project-item');
        if (projectItem) {
            var tasksList = projectItem.querySelector('.tasks-list');
            if (tasksList && !tasksList.classList.contains('active')) tasksList.classList.add('active');
        }
    }

    function autoExpandParentProjects() {
        var activeTaskUuid = window.currentTaskUuid;
        if (!activeTaskUuid) return;
        var taskElement = document.querySelector('.task-item[data-task-uuid="' + activeTaskUuid + '"]');
        if (!taskElement) {
            setTimeout(autoExpandParentProjects, 500);
            return;
        }
        var depth = parseInt(taskElement.getAttribute('data-depth') || '0');
        if (depth > 0) {
            var projectItem = taskElement.closest('.project-item');
            if (projectItem) {
                var tasksList = projectItem.querySelector('.tasks-list');
                if (tasksList && !tasksList.classList.contains('active')) tasksList.classList.add('active');
            }
            setTimeout(function() { scrollSidebarToActiveTask(); }, 300);
        }
    }

    // ==================== LAZY PREVIEWS ====================

    var lazyImageObserver = null;
    var lazyImageQueue = [];
    var isProcessingLazyQueue = false;
    var currentLoadingImage = null;
    var imageLoadRetryCount = 0;
    var MAX_RETRIES = 3;
    var LOAD_DELAY_MS = 500;

    function loadLazyPreviews() {
        var lazyImages = document.querySelectorAll('.lazy-preview');
        if (!lazyImages.length) return;
        var imagesToLoad = [];
        for (var i = 0; i < lazyImages.length; i++) {
            var img = lazyImages[i];
            var hasDataSrc = img.dataset && img.dataset.src;
            var isLoaded = img.getAttribute('data-loaded') === 'true';
            if (hasDataSrc && !isLoaded) imagesToLoad.push(img);
        }
        if (imagesToLoad.length === 0) return;
        if (window.IntersectionObserver && !lazyImageObserver) {
            lazyImageObserver = new IntersectionObserver(function(entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        var img = entries[i].target;
                        if (img.getAttribute('data-loaded') !== 'true') addToLazyQueue(img);
                        lazyImageObserver.unobserve(img);
                    }
                }
            }, { rootMargin: '300px', threshold: 0.01 });
            for (var i = 0; i < imagesToLoad.length; i++) lazyImageObserver.observe(imagesToLoad[i]);
        } else {
            for (var i = 0; i < imagesToLoad.length; i++) addToLazyQueue(imagesToLoad[i]);
        }
    }

    function addToLazyQueue(img) {
        for (var i = 0; i < lazyImageQueue.length; i++) if (lazyImageQueue[i] === img) return;
        if (img.getAttribute('data-loaded') === 'true') return;
        lazyImageQueue.push(img);
        if (!isProcessingLazyQueue) processLazyImageQueue();
    }

    function processLazyImageQueue() {
        if (lazyImageQueue.length === 0) {
            isProcessingLazyQueue = false;
            currentLoadingImage = null;
            imageLoadRetryCount = 0;
            return;
        }
        if (isProcessingLazyQueue) return;
        isProcessingLazyQueue = true;
        var img = lazyImageQueue.shift();
        currentLoadingImage = img;
        if (!img || !img.dataset || !img.dataset.src) {
            isProcessingLazyQueue = false;
            currentLoadingImage = null;
            setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
            return;
        }
        var dataSrc = img.dataset.src;
        var finalUrl = dataSrc;
        if (imageLoadRetryCount > 0) {
            finalUrl = dataSrc + (dataSrc.indexOf('?') === -1 ? '?' : '&') + 'retry=' + imageLoadRetryCount + '&_=' + Date.now();
        }
        img.src = finalUrl;
        var loadTimeout = setTimeout(function() {
            if (currentLoadingImage === img) {
                if (imageLoadRetryCount < MAX_RETRIES) {
                    imageLoadRetryCount++;
                    lazyImageQueue.unshift(img);
                    isProcessingLazyQueue = false;
                    currentLoadingImage = null;
                    setTimeout(processLazyImageQueue, LOAD_DELAY_MS * (imageLoadRetryCount + 1));
                } else {
                    setImageError(img);
                    isProcessingLazyQueue = false;
                    currentLoadingImage = null;
                    imageLoadRetryCount = 0;
                    setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
                }
            }
        }, 30000);
        img.onload = function() {
            clearTimeout(loadTimeout);
            img.setAttribute('data-loaded', 'true');
            img.classList.remove('lazy-preview');
            imageLoadRetryCount = 0;
            currentLoadingImage = null;
            isProcessingLazyQueue = false;
            setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
        };
        img.onerror = function() {
            clearTimeout(loadTimeout);
            if (imageLoadRetryCount < MAX_RETRIES) {
                imageLoadRetryCount++;
                lazyImageQueue.unshift(img);
                currentLoadingImage = null;
                isProcessingLazyQueue = false;
                var retryDelay = LOAD_DELAY_MS * Math.pow(2, imageLoadRetryCount);
                setTimeout(processLazyImageQueue, retryDelay);
            } else {
                setImageError(img);
                currentLoadingImage = null;
                isProcessingLazyQueue = false;
                imageLoadRetryCount = 0;
                setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
            }
        };
    }

    function setImageError(img) {
        var errorSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='150'%3E%3Crect fill='%23374151' width='200' height='150'/%3E%3Ctext x='50%25' y='50%25' text-anchor='middle' dy='.3em' fill='%239ca3af' font-size='12'%3EОшибка%3C/text%3E%3C/svg%3E";
        img.src = errorSvg;
        img.classList.add('preview-error');
        img.setAttribute('data-loaded', 'error');
    }

    // ==================== IMAGE CACHE + SMART UPDATE ====================

    if (!window.imageCache) window.imageCache = new Map();

    function restoreCachedImagesToDOM() {
        var lazyImages = document.querySelectorAll('.lazy-preview');
        if (!lazyImages.length) return;
        var restored = 0;
        for (var i = 0; i < lazyImages.length; i++) {
            var img = lazyImages[i];
            if (img.getAttribute('data-loaded') === 'true') continue;
            var fileUuid = null;
            var wrapper = img.closest('.file-image-wrapper');
            if (wrapper) fileUuid = wrapper.getAttribute('data-file-uuid');
            if (!fileUuid && img.dataset && img.dataset.src) {
                var match = img.dataset.src.match(/[?&]file=([a-f0-9-]+)/i);
                if (match) fileUuid = match[1];
            }
            if (fileUuid) {
                var cacheKey = 'file_' + fileUuid;
                var cached = window.imageCache.get(cacheKey);
                if (cached && cached.loaded && cached.dataUrl) {
                    img.src = cached.dataUrl;
                    img.setAttribute('data-loaded', 'true');
                    img.classList.remove('lazy-preview');
                    if (img.dataset) delete img.dataset.src;
                    restored++;
                } else if (cached && cached.url && !img.src) {
                    img.src = cached.url;
                    img.setAttribute('data-loaded', 'true');
                    img.classList.remove('lazy-preview');
                    if (img.dataset) delete img.dataset.src;
                    restored++;
                }
            }
        }
        if (restored > 0) logDebug('[IMAGE_CACHE] Restored ' + restored + ' images from cache');
    }

    function smartUpdateMessage(messageData) {
        if (!messageData || !messageData.uuid) return false;
        var existingMsg = document.getElementById('msg-' + messageData.uuid);
        if (!existingMsg) {
            var innerContainer = getInnerContainer();
            var scrollContainer = getScrollContainer();
            if (!innerContainer) return false;
            var emptyState = innerContainer.querySelector('.empty-state');
            if (emptyState) emptyState.remove();
            var inserted = false;
            var messages = innerContainer.querySelectorAll('.message');
            for (var i = 0; i < messages.length; i++) {
                var existingTime = parseInt(messages[i].getAttribute('data-time') || '0');
                if (messageData.time < existingTime) {
                    messages[i].insertAdjacentHTML('beforebegin', renderMessage(messageData));
                    inserted = true;
                    break;
                }
            }
            if (!inserted) innerContainer.insertAdjacentHTML('beforeend', renderMessage(messageData));
            setTimeout(function() { loadLazyPreviews(); restoreCachedImagesToDOM(); }, 100);
            if (scrollContainer) {
                var distToBottom = scrollContainer.scrollHeight - scrollContainer.scrollTop - scrollContainer.clientHeight;
                if (distToBottom < 300) {
                    setTimeout(function() { scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 100);
                }
            }
            logDebug('[SMART_UPDATE] Added new message: ' + messageData.uuid);
            return true;
        }
        var newHtml = renderMessage(messageData);
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = newHtml;
        var newMsgElement = tempDiv.firstChild;
        var scrollContainer = getScrollContainer();
        var scrollTop = scrollContainer ? scrollContainer.scrollTop : 0;
        var wasAtBottom = scrollContainer ? (scrollContainer.scrollHeight - scrollTop - scrollContainer.clientHeight) < 100 : false;
        existingMsg.parentNode.replaceChild(newMsgElement, existingMsg);
        if (messageData.text !== undefined) {
            var encodedText = encodeURIComponent(messageData.text);
            newMsgElement.setAttribute('data-original-text', encodedText);
        }
        if (wasAtBottom && scrollContainer) {
            setTimeout(function() { scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 50);
        } else if (scrollTop > 0 && scrollContainer) {
            scrollContainer.scrollTop = scrollTop;
        }
        setTimeout(function() {
            var el = document.getElementById('msg-' + messageData.uuid);
            if (el) {
                var lazyImages = el.querySelectorAll('.lazy-preview');
                for (var i = 0; i < lazyImages.length; i++) {
                    var img = lazyImages[i];
                    var wrapper = img.closest('.file-image-wrapper');
                    if (wrapper) {
                        var fileUuid = wrapper.getAttribute('data-file-uuid');
                        if (fileUuid && window.imageCache) {
                            var cached = window.imageCache.get('file_' + fileUuid);
                            if (cached && cached.loaded && cached.dataUrl && !img.src) {
                                img.src = cached.dataUrl;
                                img.setAttribute('data-loaded', 'true');
                                img.classList.remove('lazy-preview');
                                if (img.dataset) delete img.dataset.src;
                            }
                        }
                    }
                }
            }
        }, 50);
        logDebug('[SMART_UPDATE] Updated message: ' + messageData.uuid);
        return true;
    }

    function refreshPaginationAfterMutation(mutationType, affectedMessageUuid, newMessageData) {
        logDebug('[SMART_UPDATE] refreshPaginationAfterMutation called with type:', mutationType, 'uuid:', affectedMessageUuid);

        if (newMessageData && newMessageData.uuid) {
            smartUpdateMessage(newMessageData);
            if (window.pagination && mutationType !== 'delete') {
                if (mutationType === 'add') {
                    window.pagination.totalMessages = (window.pagination.totalMessages || 0) + 1;
                }
                window.pagination.totalPages = Math.ceil(window.pagination.totalMessages / window.MESSAGES_PER_PAGE);
            }
            setTimeout(function() {
                if (typeof markVisibleMessagesAsRead === 'function') markVisibleMessagesAsRead();
                restoreCachedImagesToDOM();
            }, 200);
            return;
        }

        if (mutationType === 'delete' && affectedMessageUuid) {
            var msgElement = document.getElementById('msg-' + affectedMessageUuid);
            if (msgElement) {
                msgElement.style.transition = 'opacity 0.2s ease';
                msgElement.style.opacity = '0';
                setTimeout(function() {
                    if (msgElement && msgElement.parentNode) {
                        msgElement.remove();
                        if (window.pagination) {
                            window.pagination.totalMessages = Math.max(0, (window.pagination.totalMessages || 0) - 1);
                            window.pagination.totalPages = Math.ceil(window.pagination.totalMessages / window.MESSAGES_PER_PAGE);
                        }
                        var taskElement = document.querySelector('.task-item[data-task-uuid="' + window.currentTaskUuid + '"]');
                        if (taskElement) {
                            var countSpan = taskElement.querySelector('.task-title span');
                            if (window.pagination && window.pagination.totalMessages > 0) {
                                if (countSpan) countSpan.textContent = '[' + window.pagination.totalMessages + ']';
                            } else if (countSpan) {
                                countSpan.remove();
                                var innerContainer = getInnerContainer();
                                if (innerContainer && !innerContainer.querySelector('.message')) {
                                    innerContainer.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
                                }
                            }
                        }
                        if (typeof window.updateUnreadBadge === 'function') window.updateUnreadBadge();
                        logDebug('[SMART_UPDATE] Message removed: ' + affectedMessageUuid);
                    }
                }, 200);
            }
            return;
        }

        if (window.currentTaskUuid && typeof loadMessagesPage === 'function') {
            loadMessagesPage(window.pagination.currentWindowPage || 0, { scrollToBottom: false });
        }
    }

    function updateMessageInCache(uuid, newData) {
        var el = document.querySelector('.message[data-uuid="' + uuid + '"]');
        if (el && newData) {
            var newHtml = renderMessage(newData);
            var temp = document.createElement('div');
            temp.innerHTML = newHtml;
            el.parentNode.replaceChild(temp.firstChild, el);
            setTimeout(loadLazyPreviews, 100);
        }
    }

    function deleteMessageFromCache(uuid) {
        var el = document.querySelector('.message[data-uuid="' + uuid + '"]');
        if (el) {
            el.style.transition = 'opacity 0.3s ease';
            el.style.opacity = '0';
            setTimeout(function() {
                if (el && el.parentNode) el.remove();
                var innerContainer = getInnerContainer();
                if (innerContainer && !innerContainer.querySelector('.message')) {
                    innerContainer.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
                }
                if (typeof window.updateUnreadBadge === 'function') window.updateUnreadBadge();
            }, 300);
        }
    }

    function refreshCurrentTaskMessages() {
        if (window.currentTaskUuid) {
            window.lastMessageTime = 0;
            loadMessagesPage(0, { scrollToBottom: true });
        }
    }

    // ==================== MENTION AUTOCOMPLETE ====================

    var taskUsersCache = [];
    var taskUsersLoaded = false;
    var currentMentionQuery = '';

    function loadTaskUsers(taskUuid) {
        if (!taskUuid) return Promise.resolve([]);
        var formData = new URLSearchParams();
        formData.append('action', 'get_task_users');
        formData.append('task_uuid', taskUuid);
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);
        formData.append('ajax_mode', '1');
        return fetch(window.APP_BASE + '/messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function(response) {
            if (!response.ok) {
                if (response.status === 503) return [];
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (data && data.success && data.users) {
                taskUsersCache = data.users;
                taskUsersLoaded = true;
                window.taskUsersCache = data.users;
                window.taskUsersLoaded = true;
                return data.users;
            }
            return [];
        })
        .catch(function(err) {
            logDebug('[MENTION] Error loading users: ' + err.message);
            return [];
        });
    }

    function filterUsersByQuery(query) {
        var cache = window.taskUsersCache || taskUsersCache || [];
        if (!query || query.length === 0) return cache;
        var lowerQuery = query.toLowerCase();
        return cache.filter(function(user) {
            return (user.name && user.name.toLowerCase().includes(lowerQuery)) ||
                   (user.login && user.login.toLowerCase().includes(lowerQuery));
        }).slice(0, 10);
    }

    function setupMentionAutocomplete() {
        var textarea = document.getElementById('message-input');
        if (!textarea) return;
        textarea.addEventListener('input', function(e) {
            var caretPos = textarea.selectionStart;
            var textBeforeCaret = textarea.value.substring(0, caretPos);
            var lastAtIndex = textBeforeCaret.lastIndexOf('@');
            if (lastAtIndex !== -1 && (caretPos - lastAtIndex) <= 30) {
                var query = textBeforeCaret.substring(lastAtIndex + 1);
                var matchedUsers = filterUsersByQuery(query);
                if (matchedUsers.length > 0) {
                    showMentionDropdown(matchedUsers, lastAtIndex);
                } else {
                    hideMentionDropdown();
                }
                currentMentionQuery = query;
            } else {
                hideMentionDropdown();
                currentMentionQuery = '';
            }
        });
    }

    function showMentionDropdown(users, atPosition) {
        var dropdown = document.getElementById('mention-dropdown');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.id = 'mention-dropdown';
            dropdown.style.cssText = 'position:absolute; background:#1e293b; border:1px solid #4f7cff; border-radius:8px; max-height:200px; overflow-y:auto; z-index:1000; min-width:180px; box-shadow:0 4px 12px rgba(0,0,0,0.3);';
            document.body.appendChild(dropdown);
        }
        dropdown.innerHTML = '';
        users.forEach(function(user) {
            var item = document.createElement('div');
            var roleIcon = user.role === 0 ? '👑 ' : '';
            var displayName = user.name ? roleIcon + user.name + ' (@' + user.login + ')' : roleIcon + '@' + user.login;
            item.textContent = displayName;
            item.style.cssText = 'padding:8px 12px; cursor:pointer; color:#e9eefc; border-bottom:1px solid #334155; transition:background 0.2s;';
            item.onmouseover = function() { item.style.background = '#2d3a5e'; };
            item.onmouseout = function() { item.style.background = ''; };
            item.onclick = function() { insertMention(user.login, atPosition); };
            dropdown.appendChild(item);
        });
        var textarea = document.getElementById('message-input');
        if (textarea) {
            var rect = textarea.getBoundingClientRect();
            dropdown.style.left = rect.left + 'px';
            dropdown.style.bottom = (window.innerHeight - rect.top + 5) + 'px';
            dropdown.style.display = 'block';
        }
    }

    function hideMentionDropdown() {
        var dropdown = document.getElementById('mention-dropdown');
        if (dropdown) dropdown.style.display = 'none';
    }

    function insertMention(login, atPosition) {
        var textarea = document.getElementById('message-input');
        if (!textarea) return;
        var before = textarea.value.substring(0, atPosition);
        var after = textarea.value.substring(textarea.selectionStart);
        textarea.value = before + '@' + login + ' ' + after;
        textarea.focus();
        var newCursorPos = atPosition + login.length + 2;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        hideMentionDropdown();
        currentMentionQuery = '';
        textarea.dispatchEvent(new Event('input'));
    }

    // ==================== FILE EVENT DELEGATION ====================

    var currentFileMenuContext = { messageUuid: null, fileUuid: null, fileName: null, isOwn: false, messageText: null };

    function handleFileMenuEscape(e) {
        if (e.key === 'Escape') closeFileMenu();
    }

    function showFileMenu(event, messageUuid, fileUuid, fileName, isOwn, messageText) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof window.closeMessageMenu === 'function') window.closeMessageMenu();

        var actualMessageText = '';
        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
        if (msgElement) {
            var encodedText = msgElement.getAttribute('data-original-text');
            if (encodedText) {
                try { actualMessageText = decodeURIComponent(encodedText); }
                catch(e) { actualMessageText = encodedText; }
            }
            var actualIsOwn = msgElement.classList.contains('own');
            if (actualIsOwn !== isOwn) isOwn = actualIsOwn;
        }
        if (!actualMessageText && messageText) {
            if (messageText.indexOf('<') !== -1 || messageText.indexOf('&gt;') !== -1) {
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = messageText;
                var quotes = tempDiv.querySelectorAll('.message-quote');
                quotes.forEach(function(quote) { quote.remove(); });
                actualMessageText = (tempDiv.textContent || tempDiv.innerText || '').trim();
            } else actualMessageText = messageText;
        }

        currentFileMenuContext = { messageUuid: messageUuid, fileUuid: fileUuid, fileName: fileName, isOwn: isOwn, messageText: actualMessageText };
        window._currentFileUuid = fileUuid;
        window._currentMessageUuidForFile = messageUuid;
        window._currentMessageTextForFile = actualMessageText;

        var overlay = document.getElementById('fileMenuOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'fileMenuOverlay';
            overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10001; display:none; align-items:center; justify-content:center;';
            overlay.onclick = function(e) { if (e.target === overlay) closeFileMenu(); };
            document.body.appendChild(overlay);
            var menu = document.createElement('div');
            menu.id = 'fileMenu';
            menu.className = 'message-menu';
            menu.onclick = function(e) { e.stopPropagation(); };
            overlay.appendChild(menu);
        }
        var menu = document.getElementById('fileMenu');
        var html = '';
        if (currentFileMenuContext.isOwn) {
            html += '<div class="message-menu-item danger" id="fileMenuDeleteMsgBtn">🗑️ Удалить сообщение</div>';
            html += '<div class="message-menu-divider"></div>';
            html += '<div class="message-menu-item" id="fileMenuReplaceFileBtn">🔄 Заменить этот файл</div>';
            html += '<div class="message-menu-item danger" id="fileMenuDeleteFileBtn">🗑️ Удалить этот файл</div>';
            html += '<div class="message-menu-item" id="fileMenuAddFilesBtn">📎 Добавить другой файл</div>';
            html += '<div class="message-menu-divider"></div>';
        }
        html += '<div class="message-menu-item" id="fileMenuCopyLinkBtn">🔗 Копировать ссылку на файл</div>';
        html += '<div class="message-menu-item" id="fileMenuCopyTextBtn">📋 Копировать текст сообщения</div>';
        html += '<div class="message-menu-item" id="fileMenuReplyBtn">↩️ Ответить на сообщение</div>';
        if (currentFileMenuContext.isOwn) {
            html += '<div class="message-menu-divider"></div>';
            html += '<div class="message-menu-item" id="fileMenuEditMsgBtn">✏️ Редактировать сообщение</div>';
        }
        menu.innerHTML = html;

        if (currentFileMenuContext.isOwn) {
            var delMsgBtn = document.getElementById('fileMenuDeleteMsgBtn');
            if (delMsgBtn) delMsgBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); if (confirm('Удалить сообщение?')) window.deleteMessage(currentFileMenuContext.messageUuid); };
            var replaceFileBtn = document.getElementById('fileMenuReplaceFileBtn');
            if (replaceFileBtn) replaceFileBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); window.currentReplaceFileUuid = currentFileMenuContext.fileUuid; window.currentReplaceMessageUuid = currentFileMenuContext.messageUuid; window.openFileReplaceDialog(); };
            var deleteFileBtn = document.getElementById('fileMenuDeleteFileBtn');
            if (deleteFileBtn) deleteFileBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); if (confirm('Удалить файл?')) window.executeFileDelete(currentFileMenuContext.messageUuid, currentFileMenuContext.fileUuid); };
            var addFilesBtn = document.getElementById('fileMenuAddFilesBtn');
            if (addFilesBtn) addFilesBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); window._currentMessageUuid = currentFileMenuContext.messageUuid; window.addFilesToMessage(); };
        }
        var copyLinkBtn = document.getElementById('fileMenuCopyLinkBtn');
        if (copyLinkBtn) copyLinkBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); window.copyFileLink(currentFileMenuContext.fileUuid); };
        var copyTextBtn = document.getElementById('fileMenuCopyTextBtn');
        if (copyTextBtn) copyTextBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); if (currentFileMenuContext.messageText) { window._currentMessageText = currentFileMenuContext.messageText; window.copyMessageText(); } };
        var replyBtn = document.getElementById('fileMenuReplyBtn');
        if (replyBtn) replyBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); window._currentMessageUuid = currentFileMenuContext.messageUuid; window.replyToCurrentMessage(); };
        if (currentFileMenuContext.isOwn) {
            var editMsgBtn = document.getElementById('fileMenuEditMsgBtn');
            if (editMsgBtn) editMsgBtn.onclick = function(e) { e.stopPropagation(); closeFileMenu(); window._currentMessageUuid = currentFileMenuContext.messageUuid; window._currentMessageOwn = true; window.editCurrentMessage(); };
        }

        var isMobile = window.innerWidth <= 768;
        if (isMobile) {
            menu.style.position = 'relative';
            menu.style.left = menu.style.top = menu.style.right = menu.style.bottom = 'auto';
            menu.style.transform = 'none';
        } else {
            var x = event.clientX;
            var y = event.clientY;
            if (x && y) {
                menu.style.position = 'fixed';
                menu.style.left = (x + 10) + 'px';
                menu.style.top = (y + 10) + 'px';
                menu.style.right = menu.style.bottom = 'auto';
                menu.style.transform = 'none';
            } else {
                menu.style.position = 'fixed';
                menu.style.left = '50%';
                menu.style.top = '50%';
                menu.style.transform = 'translate(-50%, -50%)';
            }
        }
        overlay.style.display = 'flex';
        window._fileMenuContext = currentFileMenuContext;
        document.addEventListener('keydown', handleFileMenuEscape);
    }

    function closeFileMenu() {
        var overlay = document.getElementById('fileMenuOverlay');
        if (overlay) overlay.style.display = 'none';
        currentFileMenuContext = { messageUuid: null, fileUuid: null, fileName: null, isOwn: false, messageText: null };
        window._fileMenuContext = null;
        document.removeEventListener('keydown', handleFileMenuEscape);
    }

    function setupFileEventDelegation() {
        var messagesArea = getScrollContainer();
        if (!messagesArea) {
            setTimeout(setupFileEventDelegation, 500);
            return;
        }
        var longPressTimer = null;
        var longPressTargetElement = null;
        var longPressStartX = 0, longPressStartY = 0;
        var touchStartTime = 0;
        var LONG_PRESS_DURATION = 500;
        var MAX_TAP_MOVEMENT = 15;
        var longPressTriggered = false;

        function isFileElement(target) {
            if (target.classList) {
                if (target.classList.contains('file-image-wrapper') || target.classList.contains('file-preview-thumb') || target.classList.contains('file-item')) return true;
            }
            var parent = target.parentElement;
            while (parent && parent !== messagesArea) {
                if (parent.classList && (parent.classList.contains('file-image-wrapper') || parent.classList.contains('file-preview-thumb') || parent.classList.contains('file-item'))) return true;
                parent = parent.parentElement;
            }
            return false;
        }
        function getRootFileElement(target) {
            if (target.classList && target.classList.contains('file-image-wrapper')) return target;
            if (target.classList && target.classList.contains('file-preview-thumb')) return target;
            if (target.classList && target.classList.contains('file-item')) return target;
            var parent = target.parentElement;
            while (parent && parent !== messagesArea) {
                if (parent.classList && parent.classList.contains('file-image-wrapper')) return parent;
                if (parent.classList && parent.classList.contains('file-preview-thumb')) return parent;
                if (parent.classList && parent.classList.contains('file-item')) return parent;
                parent = parent.parentElement;
            }
            return null;
        }
        function getFileDataForPreview(fileEl) {
            var fileUuid = null, fileName = 'Файл', fileSize = 0, fileMime = '';
            if (fileEl.classList.contains('file-image-wrapper')) {
                fileUuid = fileEl.getAttribute('data-file-uuid');
                fileName = fileEl.getAttribute('data-file-name') || 'Файл';
            } else {
                var wrapper = fileEl.closest('.file-image-wrapper');
                if (wrapper) {
                    fileUuid = wrapper.getAttribute('data-file-uuid');
                    fileName = wrapper.getAttribute('data-file-name') || 'Файл';
                } else {
                    var clickAttr = fileEl.getAttribute('onclick');
                    if (!clickAttr) {
                        var childWithClick = fileEl.querySelector('[onclick*="showFilePreview"]');
                        if (childWithClick) clickAttr = childWithClick.getAttribute('onclick');
                    }
                    if (clickAttr) {
                        var match = clickAttr.match(/showFilePreview\('([^']+)',\s*'([^']*)',\s*(\d+),\s*'([^']*)'/);
                        if (match) { fileUuid = match[1]; fileName = match[2] || 'Файл'; fileSize = parseInt(match[3], 10) || 0; fileMime = match[4] || ''; }
                        else { var simpleMatch = clickAttr.match(/showFilePreview\('([^']+)'/); if (simpleMatch) fileUuid = simpleMatch[1]; }
                    }
                    if (!fileUuid) {
                        var fileLink = fileEl.querySelector('a') || fileEl;
                        if (fileLink && fileLink.href) {
                            var hrefMatch = fileLink.href.match(/file=([a-f0-9-]+)/i);
                            if (hrefMatch) fileUuid = hrefMatch[1];
                        }
                    }
                }
            }
            return { fileUuid: fileUuid, fileName: fileName, fileSize: fileSize, fileMime: fileMime };
        }
        function getFileDataFromFileElement(fileEl) {
            var messageUuid = null, fileUuid = null, isOwn = false, fileName = 'Файл', messageText = '';
            function decodeMessageText(encodedText) {
                if (!encodedText) return '';
                try { return decodeURIComponent(encodedText); } catch(e) { return encodedText; }
            }
            if (fileEl.classList.contains('file-image-wrapper')) {
                messageUuid = fileEl.getAttribute('data-msg-uuid');
                fileUuid = fileEl.getAttribute('data-file-uuid');
                isOwn = fileEl.getAttribute('data-is-own') === 'true';
                fileName = fileEl.getAttribute('data-file-name') || 'Файл';
                messageText = decodeMessageText(fileEl.getAttribute('data-message-text') || '');
            } else {
                var wrapper = fileEl.closest('.file-image-wrapper');
                if (wrapper) {
                    messageUuid = wrapper.getAttribute('data-msg-uuid');
                    fileUuid = wrapper.getAttribute('data-file-uuid');
                    isOwn = wrapper.getAttribute('data-is-own') === 'true';
                    fileName = wrapper.getAttribute('data-file-name') || 'Файл';
                    messageText = decodeMessageText(wrapper.getAttribute('data-message-text') || '');
                } else {
                    var msgElement = fileEl.closest('.message');
                    if (msgElement) {
                        messageUuid = msgElement.getAttribute('data-uuid');
                        isOwn = msgElement.classList.contains('own');
                        var origEncoded = msgElement.getAttribute('data-original-text');
                        if (origEncoded) messageText = decodeMessageText(origEncoded);
                        else {
                            var textDiv = msgElement.querySelector('.message-text');
                            if (textDiv) {
                                var clone = textDiv.cloneNode(true);
                                var quotes = clone.querySelectorAll('.message-quote');
                                quotes.forEach(function(q) { q.remove(); });
                                messageText = (clone.textContent || clone.innerText || '').trim();
                            }
                        }
                        var clickAttr = fileEl.getAttribute('onclick');
                        if (!clickAttr) {
                            var childWithClick = fileEl.querySelector('[onclick*="showFilePreview"]');
                            if (childWithClick) clickAttr = childWithClick.getAttribute('onclick');
                        }
                        if (clickAttr) { var match = clickAttr.match(/showFilePreview\('([^']+)'/); if (match) fileUuid = match[1]; }
                        if (!fileUuid) {
                            var fileLink = fileEl.querySelector('a') || fileEl;
                            if (fileLink && fileLink.href) { var hrefMatch = fileLink.href.match(/file=([a-f0-9-]+)/i); if (hrefMatch) fileUuid = hrefMatch[1]; }
                        }
                    }
                }
            }
            return { messageUuid: messageUuid, fileUuid: fileUuid, isOwn: isOwn, fileName: fileName, messageText: messageText };
        }
        function clearLongPress() {
            if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
            longPressTargetElement = null;
            touchStartTime = 0;
        }

        messagesArea.addEventListener('contextmenu', function(e) {
            if (isFileElement(e.target)) {
                e.preventDefault();
                e.stopPropagation();
                var fileEl = getRootFileElement(e.target);
                if (fileEl) {
                    var data = getFileDataFromFileElement(fileEl);
                    if (data.messageUuid && data.fileUuid) showFileMenu(e, data.messageUuid, data.fileUuid, data.fileName, data.isOwn, data.messageText);
                }
            }
        }, true);
        messagesArea.addEventListener('touchstart', function(e) {
            if (isFileElement(e.target)) {
                e.preventDefault();
                e.stopPropagation();
                var touch = e.touches[0];
                longPressStartX = touch.clientX;
                longPressStartY = touch.clientY;
                longPressTargetElement = getRootFileElement(e.target);
                touchStartTime = Date.now();
                longPressTriggered = false;
                longPressTimer = setTimeout(function() {
                    if (longPressTargetElement && !longPressTriggered) {
                        longPressTriggered = true;
                        var data = getFileDataFromFileElement(longPressTargetElement);
                        if (data.messageUuid && data.fileUuid) {
                            var syntheticEvent = { clientX: longPressStartX, clientY: longPressStartY, preventDefault: function(){}, stopPropagation: function(){} };
                            showFileMenu(syntheticEvent, data.messageUuid, data.fileUuid, data.fileName, data.isOwn, data.messageText);
                        }
                        clearLongPress();
                    }
                }, LONG_PRESS_DURATION);
            }
        }, true);
        messagesArea.addEventListener('touchmove', function(e) {
            if (longPressTimer) {
                var touch = e.touches[0];
                if (touch && (Math.abs(touch.clientX - longPressStartX) > MAX_TAP_MOVEMENT || Math.abs(touch.clientY - longPressStartY) > MAX_TAP_MOVEMENT)) {
                    clearLongPress();
                    longPressTriggered = false;
                }
            }
        }, true);
        messagesArea.addEventListener('touchend', function(e) {
            var targetElement = longPressTargetElement;
            var touchDuration = Date.now() - touchStartTime;
            var wasLongPressTriggered = longPressTriggered;
            var isShortTap = (!wasLongPressTriggered && targetElement && touchDuration < LONG_PRESS_DURATION && touchDuration > 0);
            if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
            if (isShortTap && targetElement) {
                e.preventDefault();
                e.stopPropagation();
                var previewData = getFileDataForPreview(targetElement);
                if (previewData.fileUuid) {
                    if (typeof window.showFilePreview === 'function') {
                        window.showFilePreview(previewData.fileUuid, previewData.fileName, previewData.fileSize, previewData.fileMime);
                    }
                }
            }
            clearLongPress();
            longPressTriggered = false;
        }, true);
        messagesArea.addEventListener('touchcancel', function(e) {
            clearLongPress();
            longPressTriggered = false;
        }, true);
    }

    function setupQuoteClickHandler() {
        var container = getScrollContainer();
        if (!container) { setTimeout(setupQuoteClickHandler, 500); return; }
        container.addEventListener('click', function(e) {
            var quoteElement = e.target.closest('.clickable-quote');
            if (!quoteElement) return;
            var quoteUuid = quoteElement.getAttribute('data-quote-uuid');
            if (quoteUuid) {
                e.preventDefault();
                e.stopPropagation();
                scrollToMessageByUuid(quoteUuid);
            }
        });
        document.body.addEventListener('click', function(event) {
            var link = event.target.closest('.smart-link-message, .smart-link-task, .smart-link-file');
            if (link) {
                event.preventDefault();
                event.stopPropagation();
                if (link.classList.contains('smart-link-message')) {
                    var msgUuid = link.getAttribute('data-msg-uuid');
                    if (msgUuid) scrollToMessageByUuid(msgUuid);
                } else if (link.classList.contains('smart-link-task')) {
                    window.open(link.href, '_blank');
                } else if (link.classList.contains('smart-link-file')) {
                    window.open(link.href, '_blank');
                }
            }
        });
    }

    // ==================== ЭКСПОРТ ====================
    window.parseMessageText = parseMessageText;
    window.renderMessage = renderMessage;
    window.escapeHtmlStatic = escapeHtmlStatic;
    window.formatTimeStatic = formatTime;
    window.formatFileSizeStatic = formatFileSize;
    window.getFileIconStatic = getFileIcon;
    window.getScrollContainer = getScrollContainer;
    window.getInnerContainer = getInnerContainer;
    window.loadMessagesPage = loadMessagesPage;
    window.renderWindowPages = renderWindowPages;
    window.scrollToMessageByUuid = scrollToMessageByUuid;
    window.cancelActiveRequest = cancelActiveRequest;
    window.processPendingLoad = processPendingLoad;
    window.markVisibleMessagesAsRead = markVisibleMessagesAsRead;
    window.onMessagesScroll = onMessagesScroll;
    window.loadOlderMessages = loadOlderMessages;
    window.loadNewerMessages = loadNewerMessages;
    window.loadLazyPreviews = loadLazyPreviews;
    window.showFileMenu = showFileMenu;
    window.closeFileMenu = closeFileMenu;
    window.setupFileEventDelegation = setupFileEventDelegation;
    window.selectTask = selectTask;
    window.selectTaskByUuid = selectTaskByUuid;
    window.scrollSidebarToActiveTask = scrollSidebarToActiveTask;
    window.initTaskSequential = initTaskSequential;
    window.loadTaskUsersSequential = loadTaskUsersSequential;
    window.loadTaskLastPageSequential = loadTaskLastPageSequential;
    window.refreshSidebarForProject = refreshSidebarForProject;
    window.updateSidebarTasks = updateSidebarTasks;
    window.updateMobileSidebarTasks = updateMobileSidebarTasks;
    window.updateChatHeaderForTask = updateChatHeaderForTask;
    window.getCurrentProjectUuid = getCurrentProjectUuid;
    window.closeMobileDrawer = closeMobileDrawer;
    window.updateMobileDrawerBadge = updateMobileDrawerBadge;
    window.autoExpandParentProjects = autoExpandParentProjects;

    window.applyStaticMobileSpacer = applyStaticMobileSpacer;
    window.setupStaticSpacer = setupStaticSpacer;
    window.setupMentionAutocomplete = setupMentionAutocomplete;
    window.loadTaskUsers = loadTaskUsers;
    window.restoreCachedImagesToDOM = restoreCachedImagesToDOM;
    window.smartUpdateMessage = smartUpdateMessage;
    window.refreshPaginationAfterMutation = refreshPaginationAfterMutation;
    window.updateMessageInCache = updateMessageInCache;
    window.deleteMessageFromCache = deleteMessageFromCache;
    window.refreshCurrentTaskMessages = refreshCurrentTaskMessages;

    // FIX v3.3.0: экспорт очереди запросов
    window.enqueueRequest = enqueueRequest;

    // ==================== INIT ====================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setupFileEventDelegation();
            setupQuoteClickHandler();
        });
    } else {
        setupFileEventDelegation();
        setupQuoteClickHandler();
    }

    logDebug('[MESSAGES_RENDER] v3.3.0 initialized');
})();