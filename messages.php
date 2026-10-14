<?php
/**
* messages.php
* Version: 3.4.0
* Changelog:
* - 3.4.0 (2026-10-14): FIX: 
*                       1) В init-скрипте порядок вызовов: сначала initTaskSequential,
*                          затем loadTaskUsersSequential, затем loadTaskLastPageSequential
*                          через enqueueRequest (безопасно от race condition).
*                       2) Очистка чата через messages-area-inner, внешний скролл-контейнер
*                          messages-area НЕ трогается.
*                       3) Вызов setupStaticSpacer и setupMentionAutocomplete перенесён
*                          в правильные места.
*                       4) Глобальные addCsrfTo* функции — в inline-скрипте до external JS.
* - 3.3.0 (2026-10-14): FIX КРИТИЧЕСКИЙ: разделены scrollContainer и innerContainer.
* - 3.1.0 (2026-10-14): FIX: добавлен listener serviceWorker для scroll-to-bottom.
* - 3.0.0 (2026-10-14): Полная переработка. Все CSS/JS/PHP вынесены во внешние файлы.
*/

// ==================== БЛОК: Инициализация окружения ====================
if (!isset($appBase) || $appBase === '') {
    $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($appBase === '' || $appBase === '\\') $appBase = '';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

// ==================== БЛОК: Подключение ядра ====================
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// ==================== БЛОК: Подключение вынесенных PHP-модулей ====================
require_once __DIR__ . '/messages_helpers.php';
require_once __DIR__ . '/messages_api.php';

// ==================== БЛОК: Проверка авторизации ====================
msgql_require_login();
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}

// ==================== БЛОК: Инициализация переменных ====================
$upload_dir = __DIR__ . '/uploads/messages/';
if (!file_exists($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

$current_user_uuid = msgql_current_user_uuid();
$db = msgql_db();
$is_admin = msgql_is_admin();
$per_page = 500;

// Логин текущего пользователя для мобильного drawer
$current_user_login_display = '';
if ($db && !empty($current_user_uuid)) {
    $stmt = $db->prepare("SELECT login FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $current_user_login_display = $user_data['login'] ?? 'Пользователь';
    $stmt->close();
}

// Настройки звука пользователя
$sound_enabled = 1;
$sound_interval_sec = 600;
if ($db && !empty($current_user_uuid)) {
    $stmt = $db->prepare("SELECT sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sound_enabled = (int)($row['sound_enabled'] ?? 1);
        $sound_interval_sec = (int)($row['sound_interval_sec'] ?? 600);
    }
    $stmt->close();
}

// ==================== БЛОК: Обработка AJAX-запросов ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    handle_messages_ajax($db, $current_user_uuid, $is_admin, $per_page);
    exit;
}

// ==================== БЛОК: Сбор данных для отображения ====================
$projects = msgql_get_accessible_projects($current_user_uuid);
$tasks_by_project = [];
$projects_with_info = [];

foreach ($projects as $p) {
    $project_uuid = $p['uuid'];
    $tasks = get_project_tasks_for_messenger($project_uuid);
    $tasks_by_project[$project_uuid] = $tasks;

    $max_last_msg_time = 0;
    $total_messages = 0;
    foreach ($tasks as $task) {
        if (($task['last_msg_time'] ?? 0) > $max_last_msg_time) {
            $max_last_msg_time = $task['last_msg_time'];
        }
        $total_messages += $task['messages_count'] ?? 0;
    }

    $projects_with_info[] = [
        'project' => $p,
        'last_msg_time' => $max_last_msg_time,
        'total_messages' => $total_messages
    ];
}

usort($projects_with_info, function($a, $b) {
    $a_last = $a['last_msg_time'] ?? 0;
    $b_last = $b['last_msg_time'] ?? 0;

    if ($a_last == 0 && $b_last == 0) {
        if ($a['total_messages'] != $b['total_messages']) {
            return $b['total_messages'] - $a['total_messages'];
        }
        $a_time = $a['project']['time'] ?? 0;
        $b_time = $b['project']['time'] ?? 0;
        return $b_time - $a_time;
    }
    if ($a_last == 0) return 1;
    if ($b_last == 0) return -1;
    if ($a_last != $b_last) {
        return $b_last - $a_last;
    }
    if ($a['total_messages'] != $b['total_messages']) {
        return $b['total_messages'] - $a['total_messages'];
    }
    $a_time = $a['project']['time'] ?? 0;
    $b_time = $b['project']['time'] ?? 0;
    return $b_time - $a_time;
});

log_debug("[PROJECTS_SORT] Sorted " . count($projects_with_info) . " projects by last_msg_time");
$projects = array_column($projects_with_info, 'project');

// ==================== БЛОК: Определение выбранной задачи ====================
$selected_task_uuid = isset($_GET['task']) ? $_GET['task'] : '';
$selected_message_uuid = isset($_GET['message']) ? $_GET['message'] : '';
$selected_project_uuid = null;
$scroll_to_message = null;

log_debug("[INIT] URL parameters - task: {$selected_task_uuid}, message: {$selected_message_uuid}");

if (!empty($selected_message_uuid)) {
    log_debug("[MESSAGES_PAGE] ========== MESSAGE PARAMETER RECEIVED ==========");
    $check_stmt = $db->prepare("SELECT uuid, task_uuid, user_uuid FROM messages WHERE uuid = ?");
    $check_stmt->bind_param("s", $selected_message_uuid);
    $check_stmt->execute();
    $msg_exists = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    if ($msg_exists && msgql_can_access_task($current_user_uuid, $msg_exists['task_uuid'], 'view')) {
        $selected_task_uuid = $msg_exists['task_uuid'];
        $scroll_to_message = $selected_message_uuid;
        log_debug("[INIT_TASK] Message found, set task_uuid: {$selected_task_uuid}");
    } else {
        if (!isset($_SESSION['flash_message'])) {
            $_SESSION['flash_message'] = 'Сообщение не найдено или было удалено';
            $_SESSION['flash_type'] = 'warning';
        }
        $scroll_to_message = null;
    }
}

if (!empty($selected_task_uuid)) {
    $taskStmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $taskStmt->bind_param("s", $selected_task_uuid);
    $taskStmt->execute();
    $taskInfo = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();

    if ($taskInfo && msgql_can_access_task($current_user_uuid, $selected_task_uuid, 'view')) {
        $selected_project_uuid = $taskInfo['project_uuid'];
    } else {
        $selected_task_uuid = '';
        $selected_project_uuid = null;
    }
}

if (empty($selected_task_uuid)) {
    $unread_count = get_unread_count($current_user_uuid, $is_admin, $db);
    if ($unread_count > 0 && function_exists('get_latest_task_with_unread')) {
        $unread_task = get_latest_task_with_unread($current_user_uuid, $is_admin, $db);
        if ($unread_task && !empty($unread_task['uuid'])) {
            $selected_task_uuid = $unread_task['uuid'];
            $selected_project_uuid = $unread_task['project_uuid'];
        }
    }

    if (empty($selected_task_uuid)) {
        if ($is_admin) {
            $stmt = $db->prepare("SELECT t.uuid, t.project_uuid FROM tasks t JOIN messages m ON t.uuid = m.task_uuid GROUP BY t.uuid ORDER BY MAX(m.time) DESC LIMIT 1");
            $stmt->execute();
        } else {
            $stmt = $db->prepare("SELECT t.uuid, t.project_uuid FROM tasks t JOIN messages m ON t.uuid = m.task_uuid JOIN projects p ON t.project_uuid = p.uuid LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ? WHERE (p.created_by_uuid = ? OR upp.can_view = 1) GROUP BY t.uuid ORDER BY MAX(m.time) DESC LIMIT 1");
            $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
            $stmt->execute();
        }
        $latest = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($latest) {
            $selected_task_uuid = $latest['uuid'];
            $selected_project_uuid = $latest['project_uuid'];
        }
    }
}

if ($selected_task_uuid && !$selected_project_uuid) {
    foreach ($tasks_by_project as $pu => $ts) {
        foreach ($ts as $t) {
            if ($t['uuid'] === $selected_task_uuid) {
                $selected_project_uuid = $pu;
                break 2;
            }
        }
    }
}

$lastMessageTime = 0;
if (!empty($selected_task_uuid)) {
    $stmt = $db->prepare("SELECT MAX(time) as max_time FROM messages WHERE task_uuid = ?");
    $stmt->bind_param("s", $selected_task_uuid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $lastMessageTime = (int)($result['max_time'] ?? 0);
    $stmt->close();
}

$csrf_token = msgql_csrf_get_token();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>Сообщения</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
    <link rel="stylesheet" href="<?= $appBase ?>/css/messages.css?v=1.2.0">

    <script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/logger.js"></script>
    <script nonce="<?= CSP_NONCE ?>">
        window.APP_BASE = '<?= $appBase ?>';
        window.csrfToken = '<?= $csrf_token ?>';
        window.currentUserUuid = '<?= $current_user_uuid ?>';
        window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
        window.currentTaskUuid = '<?= $selected_task_uuid ?>';
        window.scrollToMessageUuid = '<?= $scroll_to_message ?? '' ?>';
        window.lastMessageTime = <?= (int)$lastMessageTime ?>;
        window.MESSAGES_PER_PAGE = <?= (int)$per_page ?>;
        window.userSettings = {
            soundEnabled: <?= $sound_enabled ? 'true' : 'false' ?>,
            soundIntervalSec: <?= (int)$sound_interval_sec ?>
        };
        window.selectedFiles = [];
        window.isSending = false;
        window.userIsScrolling = false;
        window.imageCache = new Map();

        logDebug('[INIT] Global vars set - currentUserUuid:', window.currentUserUuid, 'currentTaskUuid:', window.currentTaskUuid);

        // Глобальные функции CSRF
        function addCsrfToFormData(formData) {
            if (formData instanceof FormData) formData.append('csrf_token', window.csrfToken);
        }
        function addCsrfToUrlParams(params) {
            if (params instanceof URLSearchParams) params.append('csrf_token', window.csrfToken);
        }
        function addCsrfToObject(obj) {
            obj.csrf_token = window.csrfToken;
            return obj;
        }
    </script>
</head>
<body>

<?php if (isset($_SESSION['flash_message'])): ?>
<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.showToast === 'function') {
        window.showToast('<?= addslashes($_SESSION['flash_message']) ?>', '<?= $_SESSION['flash_type'] ?? 'warning' ?>');
    }
});
</script>
<?php unset($_SESSION['flash_message']); unset($_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="wrap">
    <button class="mobile-drawer-toggle" id="mobile-drawer-toggle" aria-label="Открыть меню">☰</button>
    <div class="mobile-drawer-overlay" id="mobile-drawer-overlay"></div>

    <!-- МОБИЛЬНЫЙ DRAWER -->
    <div class="mobile-drawer-panel" id="mobile-drawer-panel">
        <div class="mobile-drawer-header">
            <div class="mobile-drawer-logo">
                <img src="<?= $appBase ?>/favicon.ico" alt="Logo" style="width:28px;height:28px;">
                <span>ЗадаЧат</span>
            </div>
            <button class="mobile-drawer-close" id="mobile-drawer-close">✕</button>
        </div>
        <div class="mobile-drawer-user">
            <div class="mobile-drawer-user-avatar">👤</div>
            <div class="mobile-drawer-user-info">
                <div class="mobile-drawer-user-name"><?= htmlspecialchars($current_user_login_display) ?></div>
                <div class="mobile-drawer-user-role"><?= $is_admin ? 'Администратор' : 'Пользователь' ?></div>
            </div>
        </div>
        <div class="mobile-drawer-nav">
            <a href="<?= $appBase ?>/index.php">🏠 Главная</a>
            <a href="<?= $appBase ?>/projects.php">📁 Проекты</a>
            <a href="<?= $appBase ?>/messages.php" class="active">💬 Сообщения</a>
            <a href="<?= $appBase ?>/files.php">📎 Файлы</a>
            <a href="<?= $appBase ?>/search.php">🔍 Поиск</a>
            <a href="<?= $appBase ?>/admin.php">⚙️ Админка</a>
        </div>
        <div class="mobile-drawer-sidebar">
            <div class="mobile-drawer-sidebar-title">📋 Проекты и задачи</div>
            <?php foreach ($projects as $project): ?>
            <div class="project-item" data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>">
                <div class="project-header">
                    <div style="display:flex;align-items:center;min-width:0;">
                        <svg class="project-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span class="project-title"><?= htmlspecialchars($project['title']) ?></span>
                    </div>
                    <span class="project-count"><?= count($tasks_by_project[$project['uuid']] ?? []) ?></span>
                </div>
                <div class="tasks-list" <?= $selected_project_uuid === $project['uuid'] ? 'style="display:block;"' : '' ?>>
                    <?php foreach (($tasks_by_project[$project['uuid']] ?? []) as $task):
                        $depth = $task['depth'] ?? 0;
                        $indentPx = $depth * 20;
                        $bullet = ($depth === 0) ? '' : (($depth === 1) ? '└─ ' : '• ');
                        $bulletPadding = $depth > 0 ? 4 : 0;
                        $totalIndent = $indentPx + $bulletPadding;
                        $style = 'padding-left: ' . (48 + $totalIndent) . 'px;';
                        if ($depth > 0) {
                            $style .= ' border-left: 2px solid rgba(79,124,255,0.2);';
                            $style .= ' margin-left: ' . $totalIndent . 'px;';
                        }
                        $unreadBadge = '';
                        if (($task['unread_count'] ?? 0) > 0) {
                            $unreadBadge = '<span style="font-size:10px;background:#f07070;color:white;padding:1px 6px;border-radius:10px;margin-left:4px;font-weight:bold;">' . $task['unread_count'] . ' нов.</span>';
                        }
                        $bulletHtml = $bullet ? '<span style="color:#6b7280;font-weight:normal;">' . $bullet . '</span> ' : '';
                    ?>
                    <div class="task-item <?= $selected_task_uuid === $task['uuid'] ? 'active' : '' ?>"
                         data-task-uuid="<?= htmlspecialchars($task['uuid']) ?>"
                         data-depth="<?= $depth ?>"
                         style="<?= $style ?>">
                        <div class="task-title">
                            <?= $bulletHtml . htmlspecialchars($task['title']) ?>
                            <?php if (($task['messages_count'] ?? 0) > 0): ?>
                                <span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[<?= $task['messages_count'] ?>]</span>
                            <?php endif; ?>
                            <?= $unreadBadge ?>
                        </div>
                        <?php if ($task['assignee_name']): ?>
                            <div class="task-assignee">👤 <?= htmlspecialchars($task['assignee_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mobile-drawer-footer">
            <form method="post" action="<?= $appBase ?>/logout.php" onsubmit="return confirm('Выйти из системы?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" class="logout-btn-mobile">🚪 Выйти</button>
            </form>
        </div>
    </div>

    <!-- ОСНОВНОЙ ИНТЕРФЕЙС -->
    <div class="messenger-container">
        <!-- DESKTOP SIDEBAR -->
        <div class="messenger-sidebar">
            <?php foreach ($projects as $project): ?>
            <div class="project-item" data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>">
                <div class="project-header">
                    <div style="display:flex;align-items:center;min-width:0;">
                        <svg class="project-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span class="project-title"><?= htmlspecialchars($project['title']) ?></span>
                    </div>
                    <span class="project-count"><?= count($tasks_by_project[$project['uuid']] ?? []) ?></span>
                </div>
                <div class="tasks-list" <?= $selected_project_uuid === $project['uuid'] ? 'style="display:block;"' : '' ?>>
                    <?php foreach (($tasks_by_project[$project['uuid']] ?? []) as $task):
                        $depth = $task['depth'] ?? 0;
                        $indentPx = $depth * 20;
                        $bullet = ($depth === 0) ? '' : (($depth === 1) ? '└─ ' : '• ');
                        $bulletPadding = $depth > 0 ? 4 : 0;
                        $totalIndent = $indentPx + $bulletPadding;
                        $style = 'padding-left: ' . (48 + $totalIndent) . 'px;';
                        if ($depth > 0) {
                            $style .= ' border-left: 2px solid rgba(79,124,255,0.2);';
                            $style .= ' margin-left: ' . $totalIndent . 'px;';
                        }
                        $unreadBadge = '';
                        if (($task['unread_count'] ?? 0) > 0) {
                            $unreadBadge = '<span style="font-size:10px;background:#f07070;color:white;padding:1px 6px;border-radius:10px;margin-left:4px;font-weight:bold;">' . $task['unread_count'] . ' нов.</span>';
                        }
                        $bulletHtml = $bullet ? '<span style="color:#6b7280;font-weight:normal;">' . $bullet . '</span> ' : '';
                    ?>
                    <div class="task-item <?= $selected_task_uuid === $task['uuid'] ? 'active' : '' ?>"
                         data-task-uuid="<?= htmlspecialchars($task['uuid']) ?>"
                         data-depth="<?= $depth ?>"
                         style="<?= $style ?>">
                        <div class="task-title">
                            <?= $bulletHtml . htmlspecialchars($task['title']) ?>
                            <?php if (($task['messages_count'] ?? 0) > 0): ?>
                                <span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[<?= $task['messages_count'] ?>]</span>
                            <?php endif; ?>
                            <?= $unreadBadge ?>
                        </div>
                        <?php if ($task['assignee_name']): ?>
                            <div class="task-assignee">👤 <?= htmlspecialchars($task['assignee_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- CHAT AREA -->
        <div class="messenger-chat">
            <div class="chat-header">
                <h3 id="chat-title-wrapper" style="margin: 0 0 4px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <a href="#" id="chat-title" target="_self" style="text-decoration: none; border-bottom: 1px dashed #70a0ff; cursor: pointer;" title="Нажмите для просмотра описания и файлов задачи">Выберите задачу</a>
                    <a href="#" id="task-files-link" style="display: none; background: rgba(79,124,255,0.12); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: #4f7cff; text-decoration: none; transition: all 0.2s;" target="_blank">📎 Все файлы задачи</a>
                </h3>
                <div class="task-info" id="chat-subtitle">Нажмите на проект и задачу</div>
            </div>

            <div class="messages-area" id="messages-area">
                <div class="messages-area-inner" id="messages-area-inner">
                    <div class="empty-state">Выберите задачу для обсуждения</div>
                </div>
            </div>

            <div class="message-input-area" id="input-area" style="<?= $selected_task_uuid ? 'display:block;' : 'display:none;' ?>">
                <form id="message-form" enctype="multipart/form-data">
                    <div id="perm-warning" class="perm-warning"></div>
                    <div class="input-wrapper">
                        <button type="button" class="file-attach-btn" onclick="document.getElementById('file-input').click()" title="Прикрепить файл">📎</button>
                        <textarea id="message-input" class="message-input" placeholder="Сообщение (Enter - отправка, Ctrl+V - вставить картинку)" rows="1" maxlength="65000"></textarea>
                        <button type="submit" class="send-btn" id="send-btn"><span class="btn-text">Отправить</span><span class="spinner"></span></button>
                    </div>

                    <div id="reply-indicator" style="display: none; background: #eef2ff; border-left: 4px solid #4f7cff; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; font-size: 13px; color: #1e293b; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span>↩️</span>
                            <span id="reply-indicator-text">Вы отвечаете на сообщение от <strong id="reply-author-name"></strong></span>
                        </div>
                        <button type="button" id="cancel-reply-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #ef4444; padding: 4px 8px; border-radius: 6px;">✕ Отменить</button>
                    </div>

                    <div class="selected-files" id="selected-files"></div>
                    <input type="file" id="file-input" name="files[]" multiple style="display:none;">
                    <input type="hidden" id="current-task-uuid" name="task_uuid" value="<?= htmlspecialchars($selected_task_uuid) ?>">
                    <input type="hidden" id="reply-to-uuid" name="reply_to_uuid" value="">
                    <?= msgql_csrf_form_field() ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- МЕНЮ СООБЩЕНИЯ -->
<div id="messageMenuOverlay" class="message-menu-overlay" onclick="closeMessageMenu()">
    <div class="message-menu" onclick="event.stopPropagation()">
        <div class="message-menu-item danger" id="deleteMessageBtn" onclick="deleteCurrentMessage()">🗑️ Удалить сообщение</div>
        <div class="message-menu-divider"></div>
        <div class="message-menu-item" id="replaceFileBtn" onclick="replaceFileInMessage()" style="display: none;">🔄 Заменить\удалить файл</div>
        <div class="message-menu-item" id="addFilesToMessageBtn" onclick="addFilesToMessage()" style="display: none;">📎 Добавить файл</div>
        <div class="message-menu-divider"></div>
        <div class="message-menu-item" onclick="copyMessageLink()">🔗 Копировать ссылку</div>
        <div class="message-menu-item" onclick="copyMessageText()">📋 Копировать текст</div>
        <div class="message-menu-item" onclick="replyToCurrentMessage()">↩️ Ответить</div>
        <div class="message-menu-item" id="editMessageBtn" onclick="editCurrentMessage()" style="display: none;">✏️ Редактировать</div>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО РЕДАКТИРОВАНИЯ -->
<div id="editMessageModal" class="edit-message-modal" style="display: none;">
    <div class="edit-message-modal-overlay"></div>
    <div class="edit-message-modal-container">
        <div class="edit-message-modal-header">
            <h3>✏️ Редактирование сообщения</h3>
            <button type="button" class="edit-message-modal-close" id="editMessageModalCloseBtn">✕</button>
        </div>
        <div class="edit-message-modal-body">
            <div class="edit-message-reply-info" id="editMessageReplyInfo" style="display: none;">
                <div class="edit-message-reply-badge">↩️ Ответ на сообщение</div>
                <div class="edit-message-reply-quote" id="editMessageReplyQuote"></div>
            </div>
            <textarea id="editMessageTextarea" class="edit-message-textarea" rows="5" placeholder="Введите текст сообщения..."></textarea>
            <div class="edit-message-warning" id="editMessageWarning" style="display: none;">⚠️ Текст не может быть пустым</div>
        </div>
        <div class="edit-message-modal-footer">
            <button type="button" class="edit-message-btn-cancel" id="editMessageCancelBtn">Отмена</button>
            <button type="button" class="edit-message-btn-save" id="editMessageSaveBtn">💾 Сохранить</button>
        </div>
    </div>
</div>

<!-- ПАНЕЛЬ ДЕТАЛЕЙ ЗАДАЧИ -->
<div id="taskDetailsOverlay" class="task-details-overlay"></div>
<div id="taskDetailsPanel" class="task-details-panel">
    <div class="task-details-header">
        <h3 id="taskDetailsTitle">📋 Информация о задаче</h3>
        <button class="task-details-close" id="taskDetailsCloseBtn">✕</button>
    </div>
    <div class="task-details-body" id="taskDetailsBody">
        <div class="task-details-loading">⏳ Загрузка информации о задаче...</div>
    </div>
</div>

<!-- ПОДКЛЮЧЕНИЕ ВНЕШНИХ JS (порядок критичен!) -->
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/sse_client.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/toast.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/file_preview.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/messages_render.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/messages_taskbar.js"></script>

<!-- ИНИЦИАЛИЗАЦИЯ (запускается ПОСЛЕ всех внешних модулей) -->
<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    logDebug('[DOM] DOMContentLoaded v3.4');

    // ==================== ИНИЦИАЛИЗАЦИЯ МОБИЛЬНОГО DRAWER ====================
    (function initMobileDrawer() {
        if (window.innerWidth > 768) return;
        var body = document.body;
        var toggleBtn = document.getElementById('mobile-drawer-toggle');
        var overlay = document.getElementById('mobile-drawer-overlay');
        var closeBtn = document.getElementById('mobile-drawer-close');

        function openDrawer() { body.classList.add('mobile-drawer-open'); body.style.overflow = 'hidden'; }
        function closeDrawer() { body.classList.remove('mobile-drawer-open'); body.style.overflow = ''; }

        if (toggleBtn) toggleBtn.addEventListener('click', function(e) { e.stopPropagation(); openDrawer(); });
        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        if (overlay) overlay.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('mobile-drawer-open')) closeDrawer();
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) { body.classList.remove('mobile-drawer-open'); body.style.overflow = ''; }
        });
        window.closeMobileDrawer = closeDrawer;
    })();

    // ==================== КЛИКИ ПО ЗАДАЧАМ ====================
    document.querySelectorAll('.task-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            logDebug('[TASK_CLICK] Clicked task:', this.dataset.taskUuid);
            if (typeof window.selectTask === 'function') {
                window.selectTask(this);
            } else {
                logError('[TASK_CLICK] window.selectTask not defined!');
            }
            if (window.innerWidth <= 768 && typeof window.closeMobileDrawer === 'function') {
                window.closeMobileDrawer();
            }
        });
    });

    // ==================== СВОРАЧИВАНИЕ ПРОЕКТОВ ====================
    document.querySelectorAll('.project-header').forEach(function(h) {
        h.addEventListener('click', function(e) {
            e.stopPropagation();
            var tasksList = this.parentNode.querySelector('.tasks-list');
            if (tasksList) {
                tasksList.classList.toggle('active');
                if (tasksList.classList.contains('active')) {
                    var activeTask = tasksList.querySelector('.task-item.active');
                    if (activeTask) {
                        setTimeout(function() {
                            activeTask.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 100);
                    }
                }
            }
        });
    });

    // ==================== ФОРМА ОТПРАВКИ ====================
    var form = document.getElementById('message-form');
    if (form && typeof window.sendMessage === 'function') form.addEventListener('submit', window.sendMessage);

    var fi = document.getElementById('file-input');
    if (fi && typeof window.handleFileSelect === 'function') fi.addEventListener('change', window.handleFileSelect);

    var ta = document.getElementById('message-input');
    if (ta) {
        ta.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        ta.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (form) form.dispatchEvent(new Event('submit'));
            }
        });
        if (typeof window.setupMentionAutocomplete === 'function') window.setupMentionAutocomplete();
    }

    var cancelReplyBtn = document.getElementById('cancel-reply-btn');
    if (cancelReplyBtn && typeof window.cancelReply === 'function') cancelReplyBtn.addEventListener('click', window.cancelReply);

    // Вставка из буфера обмена
    document.addEventListener('paste', function(e) {
        if (!window.currentTaskUuid) return;
        var items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                e.preventDefault();
                var blob = items[i].getAsFile();
                var now = new Date();
                var fileName = 'image_' + now.getFullYear() + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0') + '_' + String(now.getHours()).padStart(2,'0') + String(now.getMinutes()).padStart(2,'0') + String(now.getSeconds()).padStart(2,'0') + '.png';
                var newFile = new File([blob], fileName, { type: blob.type });
                window.selectedFiles.push(newFile);
                if (typeof window.updateSelectedFiles === 'function') window.updateSelectedFiles();
                break;
            }
        }
    });

    // ==================== СТАТИЧЕСКИЙ SPACER ====================
    if (typeof window.setupStaticSpacer === 'function') {
        window.setupStaticSpacer();
    } else if (typeof window.applyStaticMobileSpacer === 'function') {
        window.applyStaticMobileSpacer();
    }

    // ==================== ЗАГРУЗКА ЗАДАЧИ ====================
    setTimeout(function() {
        if (window.currentTaskUuid) {
            logDebug('[INIT_CHAT] Loading task:', window.currentTaskUuid);
            var inputArea = document.getElementById('input-area');
            if (inputArea) inputArea.style.display = 'block';
            var currentTaskInput = document.getElementById('current-task-uuid');
            if (currentTaskInput) currentTaskInput.value = window.currentTaskUuid;

            if (typeof window.updateChatHeaderForTask === 'function') {
                window.updateChatHeaderForTask(window.currentTaskUuid);
            }

            var innerContainer = document.getElementById('messages-area-inner');
            if (innerContainer) {
                innerContainer.innerHTML = '<div class="loading-messages">⏳ Загрузка сообщений...</div>';
            }

            if (typeof window.enqueueRequest === 'function') {
                // 1. initTask
                window.enqueueRequest('initTask_' + window.currentTaskUuid, function(done) {
                    if (typeof window.initTaskSequential === 'function') {
                        window.initTaskSequential(window.currentTaskUuid, function() {
                            logDebug('[INIT_CHAT] Task initialized');
                            done();
                        });
                    } else { done(); }
                });

                // 2. loadTaskUsers
                window.enqueueRequest('loadTaskUsers_' + window.currentTaskUuid, function(done) {
                    if (typeof window.loadTaskUsersSequential === 'function') {
                        window.loadTaskUsersSequential(window.currentTaskUuid, function() { done(); });
                    } else { done(); }
                });

                // 3. loadTaskLastPage
                window.enqueueRequest('loadMessages_lastpage_' + window.currentTaskUuid, function(done) {
                    if (typeof window.loadTaskLastPageSequential === 'function') {
                        window.loadTaskLastPageSequential(window.currentTaskUuid, function() {
                            logDebug('[INIT_CHAT] Last page loaded');
                            setTimeout(function() {
                                if (typeof window.applyStaticMobileSpacer === 'function') window.applyStaticMobileSpacer();
                            }, 1000);
                            if (window.scrollToMessageUuid) {
                                setTimeout(function() {
                                    if (typeof window.scrollToMessageByUuid === 'function') {
                                        window.scrollToMessageByUuid(window.scrollToMessageUuid);
                                    }
                                }, 800);
                            }
                            done();
                        });
                    } else { done(); }
                });
            } else {
                // Fallback — прямые вызовы
                if (typeof window.initTaskSequential === 'function') window.initTaskSequential(window.currentTaskUuid, function() {});
                if (typeof window.loadTaskUsersSequential === 'function') window.loadTaskUsersSequential(window.currentTaskUuid, function() {});
                if (typeof window.loadTaskLastPageSequential === 'function') {
                    window.loadTaskLastPageSequential(window.currentTaskUuid, function() {});
                }
            }

            setTimeout(function() {
                if (typeof window.scrollSidebarToActiveTask === 'function') window.scrollSidebarToActiveTask();
            }, 500);
        } else {
            logDebug('[INIT_CHAT] No task selected');
        }

        // Бейджи
        setTimeout(function() {
            if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
                window.SSE.updateAllBadges();
            }
        }, 1000);

        // Lazy previews
        setTimeout(function() {
            if (typeof window.loadLazyPreviews === 'function') window.loadLazyPreviews();
        }, 1500);
    }, 200);

    // ==================== ОБРАБОТКА СКРОЛЛА ====================
    var messagesArea = document.getElementById('messages-area');
    if (messagesArea && typeof window.onMessagesScroll === 'function') {
        messagesArea.addEventListener('scroll', window.onMessagesScroll);
    }

    // Авто-расширение родительских проектов
    setTimeout(function() {
        if (typeof window.autoExpandParentProjects === 'function') window.autoExpandParentProjects();
    }, 500);
});

// ==================== СЛУШАТЕЛЬ ОТ SERVICE WORKER ====================
if ('serviceWorker' in navigator && navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', function(event) {
        var data = event.data;

        if (data && data.type === 'scroll-to-bottom') {
            logDebug('[SW_MSG] Received scroll-to-bottom for task: ' + data.taskUuid);
            var currentTaskInput = document.getElementById('current-task-uuid');
            var currentTaskUuid = currentTaskInput ? currentTaskInput.value : null;

            if (currentTaskUuid === data.taskUuid) {
                var container = document.getElementById('messages-area');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                    if (data.messageUuid) {
                        var msgElement = document.getElementById('msg-' + data.messageUuid);
                        if (msgElement) {
                            msgElement.classList.add('message-highlight');
                            setTimeout(function() { msgElement.classList.remove('message-highlight'); }, 2000);
                        }
                    }
                }
            } else {
                logDebug('[SW_MSG] Different task open, redirecting');
                window.location.href = data.url || (window.APP_BASE + '/messages.php?task=' + data.taskUuid);
            }
        }

        if (data && data.type === 'notification-click' && data.url) {
            window.focus();
            if (data.taskUuid && window.currentTaskUuid === data.taskUuid) {
                var container = document.getElementById('messages-area');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            } else {
                window.location.href = data.url;
            }
        }
    });
}

// ==================== POPSTATE ====================
window.addEventListener('popstate', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var taskUuid = urlParams.get('task');
    if (taskUuid && typeof window.selectTaskByUuid === 'function') {
        window.selectTaskByUuid(taskUuid);
    }
});
</script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>