<?php
//index.php version 2.0
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// Если это AJAX-запрос с action, устанавливаем флаг
if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

// Обработчик сохранения интервала уведомлений
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_interval') {
    // Очищаем буфер перед отправкой JSON
    if (ob_get_length()) ob_clean();
    
    // ========== CSRF-ПРОВЕРКА ДЛЯ МУТИРУЮЩЕГО ДЕЙСТВИЯ ==========
    msgql_csrf_check_and_exit();
    
    header('Content-Type: application/json; charset=utf-8');
    $interval = (int)($_POST['interval'] ?? 0);
    if ($interval < 3 || $interval > 5000) {
        echo json_encode(['success' => false, 'error' => 'Интервал должен быть от 3 до 5000 минут']);
        exit;
    }
    $db = msgql_db();
    $stmt = $db->prepare("UPDATE users SET alert_interval_min = ? WHERE uuid = ?");
    $stmt->bind_param("is", $interval, $current_user_uuid);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ошибка БД']);
    }
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $login = isset($_POST['login']) ? trim((string)$_POST['login']) : '';
    $pass = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    if (!msgql_login($login, $pass)) {
        $err = 'Неверный логин или пароль, либо учётная запись заблокирована.';
    } else {
        header('Location: ' . $appBase . "/index.php");
        exit;
    }
}
?>
<?php if (!$is_login): ?>
    <?php require_once __DIR__ . '/layouts/welcome_login.php'; ?>
<?php else: ?>
    <?php require_once __DIR__ . '/layouts/welcome_menu.php'; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/layouts/page_end.php'; ?>

<!-- === СКРИПТ АВТООБНОВЛЕНИЯ ДАШБОРДА С RETRY ЛОГИКОЙ И ЯКОРЯМИ === -->
<?php if ($is_login): ?>
<style>
/* Стили для якорных ссылок и анимации */
.anchor-link {
    opacity: 0.4;
    margin-left: 8px;
    font-size: 14px;
    text-decoration: none;
    transition: opacity 0.2s;
    color: #9bb7ff;
}
.anchor-link:hover {
    opacity: 1;
    text-decoration: none;
}
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}
.scroll-to-block {
    cursor: pointer;
    margin-left: 8px;
    font-size: 12px;
    color: #4f7cff;
    transition: transform 0.2s;
    display: inline-block;
}
.scroll-to-block:hover {
    transform: translateY(2px);
}
.block-highlight {
    transition: box-shadow 0.3s ease;
    box-shadow: 0 0 0 2px #4f7cff;
}
@keyframes blockPulse {
    0%, 100% { box-shadow: 0 0 0 2px #4f7cff; }
    50% { box-shadow: 0 0 0 4px rgba(79,124,255,0.5); }
}
</style>
<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Dashboard Auto-Update with Loading Indicator v2.0 ====================
// ver.2.0 (2026-06-05) - ДОБАВЛЕНО СКРЫТИЕ ИНДИКАТОРА ЗАГРУЗКИ ПОСЛЕ ПЕРВОЙ ЗАГРУЗКИ
(function() {
    'use strict';
    
    // CSRF-токен для AJAX-запросов
    var csrfToken = '<?= msgql_csrf_get_token() ?>';
    
    function addCsrfToFormData(formData) {
        if (formData instanceof FormData) {
            formData.append('csrf_token', csrfToken);
        }
    }
    
    function addCsrfToUrlParams(params) {
        if (params instanceof URLSearchParams) {
            params.append('csrf_token', csrfToken);
        }
    }
    
    // Целевые контейнеры
    const blocks = {
        tasks: document.getElementById('dash-block-tasks'),
        messages: document.getElementById('dash-block-messages'),
        files: document.getElementById('dash-block-files'),
        overdue: document.getElementById('dash-block-overdue')
    };
    
    // Проверяем наличие контейнеров
    if (!Object.values(blocks).every(b => b)) {
        logWarning('Dashboard AutoUpdate: Контейнеры не найдены');
        // v2.0: Скрываем индикатор, если контейнеры не найдены
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('ready', '✓ Загрузка завершена', false);
        }
        return;
    }
    
    // Переменные для retry логики
    let consecutiveErrors = 0;
    let isFetching = false;
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatTimeAgo(ms) {
        var diff = (Date.now() - ms) / 1000;
        if (diff < 60) return 'только что';
        if (diff < 3600) return Math.floor(diff / 60) + ' мин назад';
        if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад';
        return new Date(ms).toLocaleDateString('ru-RU');
    }
    
    function truncateText(text, len) {
        if (!text) return '';
        text = text.replace(/<[^>]*>/g, '');
        if (text.length > len) return text.substring(0, len) + '…';
        return text;
    }
    
    function formatFileSize(bytes) {
        if (!bytes) return '0 B';
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }
    
    function getCardIdByType(type) {
        const cardMap = {
            tasks: 'dash-card-tasks',
            messages: 'dash-card-messages',
            files: 'dash-card-files',
            overdue: 'dash-card-overdue'
        };
        return cardMap[type] || '';
    }
    
    function renderList(container, items, type) {
        if (!container) return;
        
        if (!items || items.length === 0) {
            const emptyTexts = {
                tasks: 'Нет новых задач',
                messages: 'Нет новых сообщений',
                files: 'Нет новых файлов',
                overdue: '✅ Нет просроченных задач'
            };
            container.innerHTML = '<div class="empty-state" style="padding:40px; text-align:center; color:rgba(233,238,252,0.5); font-size:13px;">' + emptyTexts[type] + '</div>';
            return;
        }
        
        const cardId = getCardIdByType(type);
        const anchorLink = `javascript:scrollToBlock('${cardId}')`;
        
        let html = '';
        items.forEach((item, index) => {
            let title = '', link = '#', meta = '', extra = '', badge = '';
            
            if (type === 'tasks') {
                title = escapeHtml(item.title || 'Без названия');
                link = window.APP_BASE + `/projects.php?task=${item.uuid}`;
                meta = `Проект: ${escapeHtml(item.project_title || '-')} · ${escapeHtml(item.stamp || '')}`;
                if (item.user_name) meta += ` · Назначена: ${escapeHtml(item.user_name)}`;
                const statusClass = item.status == 1 ? 'badge-done' : 'badge-new';
                const statusText = item.status == 1 ? '✓ Выполнена' : '🟢 Активна';
                badge = `<div class="feed-badge ${statusClass}" style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; background:${item.status == 1 ? 'rgba(34,197,94,0.2)' : 'rgba(79,124,255,0.2)'}; color:${item.status == 1 ? '#4ade80' : '#9bb7ff'};">${statusText}</div>`;
                if (item.descr) extra = `<div class="feed-preview" style="font-size:12px; color:rgba(233,238,252,0.6); margin-top:6px;">${escapeHtml(truncateText(item.descr, 50))}</div>`;
            } else if (type === 'messages') {
                title = `${escapeHtml(item.user_name || item.user_login || 'Пользователь')}: ${escapeHtml(truncateText((item.text || ''), 100))}`;
                link = window.APP_BASE + `/messages.php?task=${item.task_uuid}`;
                meta = `Задача: ${escapeHtml(item.task_title || '-')} · ${escapeHtml(item.stamp || '')}`;
                badge = `<div class="feed-badge badge-message" style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; background:rgba(236,72,153,0.2); color:#f472b6;">💬 Сообщение</div>`;
                extra = `<div class="feed-preview" style="font-size:12px; color:rgba(233,238,252,0.6); margin-top:6px;">${escapeHtml(truncateText(item.text, 80))}</div>`;
            } else if (type === 'files') {
                title = escapeHtml(item.orig_name || 'Файл');
                link = window.APP_BASE + `download.php?file=${item.uuid}`;
                meta = `Загрузил: ${escapeHtml(item.uploader_name || item.uploader_login || '-')} · ${formatFileSize(item.size_bytes)}`;
                if (item.task_title) meta = `Задача: ${escapeHtml(item.task_title)} · ` + meta;
                badge = `<div class="feed-badge badge-file" style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; background:rgba(168,85,247,0.2); color:#a78bfa;">📄 Файл</div>`;
            } else if (type === 'overdue') {
                title = `⚠️ ${escapeHtml(item.title || 'Без названия')}`;
                link = window.APP_BASE + `/projects.php?task=${item.uuid}`;
                meta = `Проект: ${escapeHtml(item.project_title || '-')}`;
                if (item.assignee_name) meta += ` · Исполнитель: ${escapeHtml(item.assignee_name)}`;
                if (item.deadline) meta += ` · Срок: ${new Date(item.deadline).toLocaleDateString('ru-RU')}`;
                badge = `<div class="feed-badge badge-overdue" style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; background:rgba(239,68,68,0.2); color:#f87171;">⚠️ ПРОСРОЧЕНА</div>`;
                if (item.descr) extra = `<div class="feed-preview" style="font-size:12px; color:rgba(233,238,252,0.6); margin-top:6px;">${escapeHtml(truncateText(item.descr, 50))}</div>`;
            }
            
            const scrollButton = `<span class="scroll-to-block" onclick="${anchorLink}" title="Прокрутить к блоку ${type}" style="cursor:pointer; margin-left:8px; font-size:12px; color:#4f7cff;">⬇️</span>`;
            
            html += `
            <div class="feed-item-wrapper" style="position: relative;">
                <a href="${link}" class="feed-item" style="display:flex; gap:12px; padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); transition:background 0.2s; text-decoration:none; color:inherit;"
                   onmouseover="this.style.background='rgba(79,124,255,0.05)'" onmouseout="this.style.background=''">
                    <div class="feed-icon" style="font-size:24px; flex-shrink:0;">${type === 'tasks' ? '📋' : type === 'messages' ? '💬' : type === 'files' ? '📎' : '⚠️'}</div>
                    <div class="feed-content" style="flex:1; min-width:0;">
                        <div class="feed-title" style="font-weight:600; font-size:14px; margin-bottom:4px;">${title} ${scrollButton}</div>
                        <div class="feed-meta" style="font-size:11px; color:rgba(233,238,252,0.6); margin-bottom:4px;">${meta}</div>
                        ${extra}
                        <div class="feed-time" style="font-size:10px; color:rgba(233,238,252,0.4); margin-top:6px;">🕒 ${formatTimeAgo(item.time)}</div>
                        <div style="margin-top:6px;">${badge}</div>
                    </div>
                </a>
            </div>`;
        });
        container.innerHTML = html;
    }
    
    function fetchDashboardData(retryCount) {
        if (retryCount === undefined) retryCount = 0;
        
        if (isFetching) return;
        
        logDebug('[DASHBOARD] Запрос данных, попытка ' + (retryCount + 1));
        isFetching = true;
        
        // v2.0: Показываем индикатор загрузки при первом запросе
        if (retryCount === 0 && typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('loading', '⏳ Загрузка дашборда...', true);
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'dashboard_data.php', true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        
        xhr.onload = function() {
            isFetching = false;
            
            if (xhr.status === 200) {
                consecutiveErrors = 0;
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        logDebug('[DASHBOARD] Данные получены успешно');
                        renderList(blocks.tasks, data.new_tasks, 'tasks');
                        renderList(blocks.messages, data.new_messages, 'messages');
                        renderList(blocks.files, data.new_files, 'files');
                        renderList(blocks.overdue, data.overdue_tasks, 'overdue');
                        
                        // v2.0: Скрываем индикатор после успешной загрузки
                        if (typeof window.setPageLoadStatus === 'function') {
                            window.setPageLoadStatus('ready', '✓ Готово', false);
                        }
                        
                        if (data.unread_count !== undefined && window.SSE && typeof window.SSE.forceUpdateBadge === 'function') {
                            window.SSE.forceUpdateBadge(data.unread_count);
                        }
                    } else {
                        logWarning('[DASHBOARD] Ошибка в ответе:', data.error);
                        // v2.0: Скрываем индикатор при ошибке в ответе
                        if (typeof window.setPageLoadStatus === 'function') {
                            window.setPageLoadStatus('error', '⚠️ Ошибка загрузки', false);
                        }
                    }
                } catch(e) { 
                    logError('[DASHBOARD] Ошибка парсинга:', e);
                    // v2.0: Скрываем индикатор при ошибке парсинга
                    if (typeof window.setPageLoadStatus === 'function') {
                        window.setPageLoadStatus('error', '⚠️ Ошибка данных', false);
                    }
                }
            } else if (xhr.status === 503 && retryCount < 5) {
                consecutiveErrors++;
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                logWarning('[DASHBOARD] 503 ошибка, повтор через ' + (delay/1000) + 'с');
                setTimeout(function() { fetchDashboardData(retryCount + 1); }, delay);
            } else {
                logError('[DASHBOARD] HTTP ошибка:', xhr.status);
                // v2.0: Скрываем индикатор при HTTP ошибке
                if (typeof window.setPageLoadStatus === 'function') {
                    window.setPageLoadStatus('error', '⚠️ HTTP ' + xhr.status, false);
                }
            }
        };
        
        xhr.onerror = function() { 
            isFetching = false;
            logError('[DASHBOARD] Сетевая ошибка');
            // v2.0: Скрываем индикатор при сетевой ошибке
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('error', '⚠️ Сетевая ошибка', false);
            }
        };
        
        xhr.ontimeout = function() { 
            isFetching = false;
            logError('[DASHBOARD] Таймаут');
            // v2.0: Скрываем индикатор при таймауте
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('error', '⚠️ Таймаут', false);
            }
        };
        
        xhr.send('action=get_updates&csrf_token=' + encodeURIComponent(csrfToken));
    }
    
    // v2.0: Устанавливаем начальный статус "loading" перед загрузкой
    if (typeof window.setPageLoadStatus === 'function') {
        window.setPageLoadStatus('loading', '⏳ Загрузка дашборда...', true);
    }
    
    // Останавливаем старые интервалы
    if (window._dashboardInterval) {
        clearInterval(window._dashboardInterval);
    }
    
    // Первичная загрузка
    fetchDashboardData();
    
    // Обновление каждые 30 секунд
    window._dashboardInterval = setInterval(function() {
        if (document.visibilityState === 'visible' && !isFetching) {
            fetchDashboardData();
        }
    }, 30000);
    
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && !isFetching) {
            logDebug('[DASHBOARD] Вкладка активна, обновляем');
            fetchDashboardData();
        }
    });
    
    logDebug('[DASHBOARD] Автообновление инициализировано v2.0');
})();
// ==================== BLOCK END: Dashboard Auto-Update with Loading Indicator v2.0 ====================
</script>
<?php endif; ?>