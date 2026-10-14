<?php
/**
 * projects.php
 * Version: 2.0.0
 * Changelog:
 * - 2.0.0 (2026-10-14): Refactored per plan_v2.0. Extracted:
 *                       - CSS → /css/projects.css
 *                       - JS → /js/projects_logics.js, /js/projects_render.js
 *                       - AJAX handlers → projects_api.php
 *                       - Helpers → projects_helpers.php
 *                       Main file now contains only entry-point logic, HTML markup, and init.
 * - 1.0.0: Initial version (monolithic).
 */

ini_set('log_errors', 1);
error_reporting(E_ALL);

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

if ((isset($_REQUEST['ajax_mode']) && $_REQUEST['ajax_mode'] == 1) || isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/projects_helpers.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/projects_api.php';
require_once __DIR__ . '/lib/smtp_func.php';
require_once __DIR__ . '/lib/notification_center.php';

if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

msgql_require_login();

$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}

$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
log_debug("[DEBUG] current_user_uuid: " . $current_user_uuid);
$is_admin = msgql_is_admin();

$task_upload_dir = __DIR__ . '/uploads/tasks/';
if (!file_exists($task_upload_dir)) {
    mkdir($task_upload_dir, 0777, true);
}

$selected_task_uuid = $_GET['task'] ?? '';
$selected_project_uuid = null;
$highlight_project_uuid = $_GET['project'] ?? null;

if ($selected_task_uuid) {
    $stmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $stmt->bind_param("s", $selected_task_uuid);
    $stmt->execute();
    $task_info = $stmt->get_result()->fetch_assoc();
    if ($task_info) {
        $selected_project_uuid = $task_info['project_uuid'];
    }
    $stmt->close();
}

$default_project_uuid = null;
if ($selected_project_uuid) {
    $default_project_uuid = $selected_project_uuid;
    log_debug("[INIT] Using project from URL parameter: {$default_project_uuid}");
} elseif ($highlight_project_uuid) {
    $default_project_uuid = $highlight_project_uuid;
    log_debug("[INIT] Using project from highlight parameter: {$default_project_uuid}");
} else {
    $last_active = get_last_active_project($current_user_uuid, $is_admin, $db);
    if ($last_active && !empty($last_active['uuid'])) {
        $default_project_uuid = $last_active['uuid'];
        log_debug("[INIT] Using last active project: {$default_project_uuid}");
    }
}

// ========== AJAX HANDLER (обязательно ДО вывода HTML) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['ajax_mode']) && $_POST['ajax_mode'] == 1) {
    handle_projects_ajax($db, $current_user_uuid, $is_admin);
    exit;
}

// ========== ЗАГРУЗКА НАЧАЛЬНЫХ ДАННЫХ ==========
if (function_exists('get_projects_with_notification_counts')) {
    $projects = get_projects_with_notification_counts($current_user_uuid);
    log_debug("[PROJECTS_INIT] Loaded " . count($projects) . " projects with notification counts");
} else {
    $projects = msgql_get_accessible_projects($current_user_uuid);
    log_warning("[PROJECTS_INIT] get_projects_with_notification_counts not found, using fallback");
}

$users = get_all_users($db);
$is_admin = msgql_is_admin();
$csrf_token = msgql_csrf_get_token();

$selected_project_for_highlight = $selected_project_uuid ?: $highlight_project_uuid;
if (!$selected_project_for_highlight && $default_project_uuid) {
    $selected_project_for_highlight = $default_project_uuid;
    log_debug("[INIT] Using default project for highlight: {$default_project_uuid}");
}
?>
<script nonce="<?= CSP_NONCE ?>">
    window.APP_BASE = <?= json_encode($appBase) ?>;
    window.csrfToken = <?= json_encode($csrf_token) ?>;
    window.currentUserUuid = '<?= htmlspecialchars($current_user_uuid, ENT_QUOTES) ?>';
    window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
    window.projectsData = <?= json_encode($projects, JSON_UNESCAPED_UNICODE) ?>;
    window.selectedTaskUuid = '<?= htmlspecialchars($selected_task_uuid, ENT_QUOTES) ?>';
    window.selectedProjectForHighlight = '<?= htmlspecialchars($selected_project_for_highlight ?? '', ENT_QUOTES) ?>';

    // ========== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ СОСТОЯНИЯ (могут использоваться внешними JS) ==========
    window.currentProjectUuid = null;
    window.currentTaskPage = 1;
    window.currentPerPage = 10;
    window.currentSortBy = 'last_activity';
    window.currentSortDir = 'DESC';
    window.currentFilterStatuses = [];
    window.currentFilterAssigned = [];
    window.currentSearch = '';
    window.isLoadingTasks = false;
    window.loadDebounceTimer = null;
    window.currentEditTaskUuid = null;
    window.isLoadingSubtasks = {};
    window.loadedFilesCache = {};
    window.isSelectingText = false;
    window.textSelectionTimeout = null;
    window.highlightedTaskUuid = null;
    window.isCreatingSubtask = false;

    // ========== CSRF ХЕЛПЕРЫ (используются и в inline, и во внешних JS) ==========
    function addCsrfToFormData(formData) {
        if (formData instanceof FormData) formData.append('csrf_token', window.csrfToken);
    }
    function addCsrfToUrlParams(params) {
        if (params instanceof URLSearchParams) params.append('csrf_token', window.csrfToken);
    }
    function addCsrfToObject(obj) { obj.csrf_token = window.csrfToken; return obj; }

    if (typeof logDebug === 'function') {
        logDebug('[INIT] User UUID:', window.currentUserUuid);
        logDebug('[INIT] Projects loaded:', window.projectsData ? window.projectsData.length : 0);
        logDebug('[INIT] Selected task UUID:', window.selectedTaskUuid);
    }
</script>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Проекты</title>
<link rel="stylesheet" href="<?= $appBase ?>/css/projects.css?v=1.0.0">
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/logger.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/parser.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/toast.js"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/file_preview.js?v=1.0.0"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/sse_client.js"></script>
</head>
<body>
<div class="wrap projects-container">
    <div class="projects-header">
        <h2>📁 Проекты</h2>
        <button class="btn-primary" onclick="showCreateProjectModal()" <?= msgql_can_create_project($current_user_uuid) ? '' : 'style="display:none;"' ?> id="create-project-btn">➕ Создать проект</button>
    </div>

    <div class="projects-grid" id="projects-grid">
        <?php if (empty($projects)): ?>
            <div style="text-align: center; padding: 40px; color: rgba(233,238,252,.5);">
                Нет доступных проектов. Обратитесь к администратору для выдачи прав.
            </div>
        <?php else: ?>
        <?php foreach ($projects as $project): ?>
            <div class="project-card" id="project-<?= htmlspecialchars($project['uuid']) ?>"
                 data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>"
                 data-unread-notifications="<?= (int)($project['unread_notifications_count'] ?? 0) ?>"
                 onclick="selectProject('<?= htmlspecialchars($project['uuid']) ?>')">
                <div class="project-card-header">
                    <h3><?= htmlspecialchars($project['title']) ?></h3>
                    <div class="project-card-meta">
                        <span>📅 <?= htmlspecialchars($project['stamp']) ?></span>
                        <span>👤 <?= htmlspecialchars($project['creator_name'] ?: $project['creator_login'] ?: 'Вы') ?></span>
                    </div>
                </div>
                <div class="project-card-body">
                    <div class="project-descr" data-desc-id="project_<?= htmlspecialchars($project['uuid']) ?>_desc">
                         <?= parseDescriptionLinks($project['descr'] ?: 'Нет описания') ?>
                    </div>
                    <div class="project-stats" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>📋 Задач: <?= $project['tasks_count'] ?? 0 ?></span>
                        <span class="project-notification-badge"
                              id="project-badge-<?= htmlspecialchars($project['uuid']) ?>"
                              data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>"
                              style="display: <?= ((int)($project['unread_notifications_count'] ?? 0) > 0) ? 'inline-flex' : 'none' ?>;
                                     background: #ef4444;
                                     color: white;
                                     border-radius: 20px;
                                     padding: 2px 10px;
                                     font-size: 11px;
                                     font-weight: bold;
                                     cursor: pointer;
                                     transition: all 0.2s ease;
                                     box-shadow: 0 1px 3px rgba(0,0,0,0.3);
                                     align-items: center;
                                     gap: 4px;"
                             onclick="event.stopPropagation(); scrollToNotifications('<?= htmlspecialchars($project['uuid']) ?>');"
                             title="Непрочитанные уведомления в проекте">
                            🔔 <span id="badge-count-<?= htmlspecialchars($project['uuid']) ?>"><?= (int)($project['unread_notifications_count'] ?? 0) ?></span>
                        </span>
                    </div>
                    <?php if ($is_admin || $project['created_by_uuid'] === $current_user_uuid): ?>
                        <div class="project-actions" onclick="event.stopPropagation()">
                            <button class="btn-secondary" onclick="editProject('<?= htmlspecialchars($project['uuid']) ?>')">✏️ Редактировать</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="tasks-area" style="display: none;">
        <div class="tasks-view">
            <div class="tasks-header">
                <h3 id="selected-project-title">Задачи проекта</h3>
                <button class="btn-primary" id="create-task-btn" onclick="showCreateTaskModal()">➕ Новая задача</button>
            </div>

            <div class="tasks-filters">
                <div class="filter-group">
                    <input type="text" id="task-search" class="search-input" placeholder="🔍 Поиск по названию или описанию...">
                </div>

                <div class="filter-group-multi" id="status-filter-container">
                    <div class="multi-select-container">
                        <div class="multi-select-button" id="status-filter-btn">
                            <span>📊 Статус: все</span>
                            <span>▼</span>
                        </div>
                        <div class="multi-select-dropdown" id="status-filter-dropdown">
                            <div class="multi-select-option">
                                <input type="checkbox" value="0" id="status-active"> <label for="status-active">🟢 Активные</label>
                            </div>
                            <div class="multi-select-option">
                                <input type="checkbox" value="1" id="status-completed"> <label for="status-completed">✅ Выполненные</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-group-multi" id="assignee-filter-container">
                    <div class="multi-select-container">
                        <div class="multi-select-button" id="assignee-filter-btn">
                            <span>👤 Исполнители: все</span>
                            <span>▼</span>
                        </div>
                        <div class="multi-select-dropdown" id="assignee-filter-dropdown">
                            <div class="assignee-loading">⏳ Загрузка списка пользователей...</div>
                        </div>
                    </div>
                </div>

                <div class="filter-group">
                    <select id="sort-by" class="filter-select">
                        <option value="last_activity">📅 По активности (новые сверху)</option>
                        <option value="title">📝 По названию</option>
                        <option value="status">🔄 По статусу</option>
                        <option value="deadline">⏰ По сроку</option>
                    </select>
                </div>

                <div class="filter-group per-page-group">
                    <select id="per-page" class="filter-select">
                        <option value="5">5 на странице</option>
                        <option value="10" selected>10 на странице</option>
                        <option value="15">15 на странице</option>
                        <option value="25">25 на странице</option>
                        <option value="50">50 на странице</option>
                        <option value="100">100 на странице</option>
                        <option value="200">200 на странице</option>
                        <option value="500">500 на странице</option>
                        <option value="1000">1000 на странице</option>
                    </select>
                </div>
            </div>

            <div class="tasks-list-container" id="tasks-list-container">
                <div class="tasks-loading">📋 Выберите проект для просмотра задач</div>
            </div>

            <div class="tasks-pagination" id="tasks-pagination" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- MODALS -->
<div id="project-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="project-modal-title">Создание проекта</h3>
            <span class="modal-close" onclick="closeModal('project-modal')">&times;</span>
        </div>
        <form id="project-form" onsubmit="saveProject(event)">
            <div class="modal-body">
                <input type="hidden" id="project-uuid" name="uuid">
                <div class="form-group">
                    <label>Название проекта *</label>
                    <input type="text" id="project-title" name="title" required>
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea id="project-descr" name="descr" rows="4"></textarea>
                </div>
                <?php if ($is_admin): ?>
                    <div id="project-delete-section" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
                        <hr style="border-color: rgba(220,38,38,0.3); margin-bottom: 16px;">
                        <h4 style="color: #dc2626; margin-bottom: 12px;">⚠️ Опасная зона - удаление проекта</h4>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.7); margin-bottom: 12px;">
                            Удаление проекта приведёт к безвозвратному удалению всех задач, сообщений и файлов проекта.
                            Для подтверждения введите слово <strong style="color: #dc2626;">DELETE</strong> в поле ниже.
                        </p>
                        <div class="form-group">
                            <label style="color: #dc2626;">Подтверждение удаления</label>
                            <input type="text" id="project-delete-confirm" class="confirm-input" placeholder="Введите DELETE для подтверждения" style="border-color: #dc2626;">
                        </div>
                        <button type="button" class="btn-danger" onclick="deleteProjectWithConfirm()" style="width: 100%;">🗑️ УДАЛИТЬ ПРОЕКТ</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('project-modal')">Отмена</button>
                <button type="submit" class="btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div id="task-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="task-modal-title">Создание задачи</h3>
            <span class="modal-close" onclick="closeModal('task-modal')">&times;</span>
        </div>
        <form id="task-form" onsubmit="saveTask(event)">
            <div class="modal-body">
                <input type="hidden" id="task-uuid" name="uuid">
                <input type="hidden" id="task-project-uuid" name="project_uuid">
                <input type="hidden" id="task-parent-uuid-old" name="parent_task_uuid_old">

                <div class="form-group">
                    <label>Проект</label>
                    <select id="task-project-select" name="project_uuid" onchange="onTaskProjectChange()">
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= htmlspecialchars($project['uuid']) ?>"><?= htmlspecialchars($project['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Название задачи *</label>
                    <input type="text" id="task-title" name="title" required>
                </div>

                <div class="form-group">
                    <label>Описание</label>
                    <textarea id="task-descr" name="descr" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Исполнитель</label>
                    <select id="task-assigned-to" name="assigned_to_uuid">
                        <option value="">Не назначен</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user['uuid']) ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Родительская задача</label>
                    <select id="task-parent-select" name="parent_task_uuid">
                        <option value="">-- Нет (корневая задача) --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Дата начала <span style="color:#9bb7ff; font-size:11px;">(в вашем часовом поясе)</span></label>
                    <input type="datetime-local" id="task-time-start" name="time_start">
                </div>

                <div class="form-group">
                    <label>Плановое окончание <span style="color:#9bb7ff; font-size:11px;">(в вашем часовом поясе)</span></label>
                    <input type="datetime-local" id="task-time-end" name="time_end_plan">
                </div>

                <div class="file-manager" id="file-manager" style="display: none;">
                    <h4>📎 Прикреплённые файлы</h4>
                    <div class="file-list" id="task-files-list"></div>
                    <div class="upload-area">
                        <input type="file" id="task-file-upload" accept="*/*">
                        <button type="button" class="btn-secondary" onclick="uploadTaskFile()">📤 Загрузить</button>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 16px;">
                    <button type="button" class="btn-secondary btn-messages" id="open-messages-btn" style="display: none;" onclick="openTaskMessages()">💬 Перейти к сообщениям</button>
                </div>

                <?php if ($is_admin): ?>
                    <div id="task-delete-section" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1);">
                        <hr style="border-color: rgba(220,38,38,0.3); margin-bottom: 16px;">
                        <h4 style="color: #dc2626; margin-bottom: 12px;">⚠️ Опасная зона - удаление задачи</h4>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.7); margin-bottom: 12px;">
                            Удаление задачи приведёт к безвозвратному удалению всех подзадач, сообщений и файлов.
                            Для подтверждения введите слово <strong style="color: #dc2626;">DELETE</strong> в поле ниже.
                        </p>
                        <div class="form-group">
                            <label style="color: #dc2626;">Подтверждение удаления</label>
                            <input type="text" id="task-delete-confirm" class="confirm-input" placeholder="Введите DELETE для подтверждения" style="border-color: #dc2626;">
                        </div>
                        <button type="button" class="btn-danger" onclick="deleteTaskWithConfirm()" style="width: 100%;">🗑️ УДАЛИТЬ ЗАДАЧУ</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('task-modal')">Отмена</button>
                <button type="submit" class="btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== ВНЕШНИЕ JS ========== -->
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/projects_logics.js?v=1.0.0"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/projects_render.js?v=1.0.0"></script>

<!-- ========== INLINE INIT ========== -->
<script nonce="<?= CSP_NONCE ?>">
(function() {
    'use strict';

    // ========== ДИАГНОСТИЧЕСКОЕ ЛОГИРОВАНИЕ ==========
    function logDebugSafe() {
        if (typeof window.logDebug === 'function') window.logDebug.apply(null, arguments);
    }
    function logErrorSafe() {
        if (typeof window.logError === 'function') window.logError.apply(null, arguments);
    }

    // ========== ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ ==========
    document.addEventListener('DOMContentLoaded', function() {
        var urlParams = new URLSearchParams(window.location.search);
        var taskUuid = urlParams.get('task');
        var projectUuid = urlParams.get('project');

        logDebugSafe('[INIT] === START INITIALIZATION v1.0.0 (refactored) ===');
        logDebugSafe('[INIT] URL parameters - task:', taskUuid, 'project:', projectUuid);

        var hasUrlTaskOrProject = !!(taskUuid || projectUuid);

        // Восстанавливаем настройки фильтров только если URL не содержит task/project
        var loadedSettings = null;
        if (!hasUrlTaskOrProject) {
            loadedSettings = window.loadFilterSettings();
            if (loadedSettings) {
                window.applyFilterSettings(loadedSettings);
            }
        } else {
            if (taskUuid) {
                window.clearFilterSettings();
            }
        }

        // Инициализация фильтров
        window.initMultiSelectFilters();
        window.initFilters();

        // per_page
        var savedPerPage = localStorage.getItem('tasks_per_page');
        if (savedPerPage && !loadedSettings) {
            window.currentPerPage = parseInt(savedPerPage);
            var perPageEl = document.getElementById('per-page');
            if (perPageEl) perPageEl.value = window.currentPerPage.toString();
        }

        // Обновление UI фильтров
        window.updateStatusFilter();
        window.updateAssigneeFilter();

        // Если у нас есть task в URL, ставим большое per_page чтобы задача точно попала на страницу
        if (window.selectedTaskUuid) {
            var perPageParam = urlParams.get('per_page');
            var pageParam = urlParams.get('page');

            if (!perPageParam && !pageParam) {
                window.currentPerPage = 100;
                var perPageSelect = document.getElementById('per-page');
                if (perPageSelect) {
                    perPageSelect.value = '100';
                }
                localStorage.setItem('tasks_per_page', '100');
            }
        }

        // ========== ОБРАБОТКА URL ==========
        if (taskUuid) {
            logDebugSafe('[INIT] Processing task URL parameter:', taskUuid);

            var formData = new URLSearchParams();
            formData.append('action', 'get_task_info');
            formData.append('task_uuid', taskUuid);
            formData.append('ajax_mode', '1');
            if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.task) {
                    var neededProjectUuid = data.task.project_uuid;
                    var parentChain = data.task.parent_chain || [];

                    window._cachedParentChain = parentChain;

                    var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
                    if (existingTask) {
                        window.highlightAndScrollToTask(existingTask);
                        return;
                    }

                    window.selectProject(neededProjectUuid);

                    setTimeout(function() {
                        window.expandParentsChainInDom(parentChain, taskUuid, function() {
                            setTimeout(function() {
                                window.findAndHighlightTask(taskUuid, 50, 0);
                            }, 500);
                        });
                    }, 2000);

                } else {
                    window.showAlert('Задача не найдена или нет доступа', 'warning');
                }
            })
            .catch(function(err) {
                logErrorSafe('[INIT] Error loading task info:', err);
            });
        }
        else if (projectUuid) {
            logDebugSafe('[INIT] Processing project URL parameter:', projectUuid);
            var projectCard = document.querySelector('.project-card[data-project-uuid="' + projectUuid + '"]');
            if (projectCard) {
                window.selectProject(projectUuid);
            } else {
                logDebugSafe('[INIT] Project card not found:', projectUuid);
            }
        }

        // Синхронизация бейджей
        setTimeout(function() {
            if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
                window.SSE.updateAllBadges();
            } else if (typeof updateAllBadges === 'function') {
                updateAllBadges();
            }
        }, 300);

        // Привязка обработчиков change к выбранному пользователю
        var select = document.getElementById('targetUser');
        if (select) {
            select.addEventListener('change', window.updateBadgeStatus);
            window.updateBadgeStatus();
        }

        // Привязка обработчиков закрытия модалок
        document.querySelectorAll('.modal').forEach(function(m) {
            m.addEventListener('click', function(e) {
                if (e.target === m) {
                    m.classList.remove('active');
                }
            });
        });

        // Инициализация collapsible описаний для проектов
        window.initCollapsibleDescriptions();

        logDebugSafe('[INIT] === INITIALIZATION COMPLETE ===');
    });

    // ========== ОБРАБОТЧИК POPSTATE ==========
    window.addEventListener('popstate', function(event) {
        var urlParams = new URLSearchParams(window.location.search);
        var taskUuid = urlParams.get('task');
        if (taskUuid && typeof window.findAndHighlightTask === 'function') {
            window.findAndHighlightTask(taskUuid, 50, 0);
        }
    });

    // ========== MUTATION OBSERVER ДЛЯ ПОДЗАДАЧ ==========
    var observer = new MutationObserver(function(mutations) {
        var shouldCheck = false;
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].type === 'childList' && mutations[i].addedNodes.length > 0) {
                shouldCheck = true;
                break;
            }
        }
        if (shouldCheck) {
            setTimeout(function() {
                var toggles = document.querySelectorAll('.subtasks-toggle');
                var needsUpdate = false;
                for (var i = 0; i < toggles.length; i++) {
                    if (!toggles[i]._subtaskHandler) {
                        needsUpdate = true;
                        break;
                    }
                }
                if (needsUpdate && typeof window.attachSubtaskToggleHandlers === 'function') {
                    window.attachSubtaskToggleHandlers();
                }
            }, 100);
        }
    });

    setTimeout(function() {
        observer.observe(document.body, { childList: true, subtree: true });
        if (typeof window.attachSubtaskToggleHandlers === 'function') {
            window.attachSubtaskToggleHandlers();
        }
    }, 1000);

    // ========== SSE LISTENERS ==========
    if (window.SSE && typeof window.SSE.addListener === 'function') {
        window.SSE.addListener('task_updated', function(data) {
            if (typeof window.refreshAllProjectBadges === 'function') {
                setTimeout(window.refreshAllProjectBadges, 500);
            }
        });

        window.SSE.addListener('task_broadcast', function(data) {
            if (typeof window.refreshAllProjectBadges === 'function') {
                setTimeout(window.refreshAllProjectBadges, 500);
            }
        });
    }

    logDebugSafe('[INIT] Inline init v1.0.0 complete');
})();
</script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>