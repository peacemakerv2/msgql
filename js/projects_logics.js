/**
 * projects_logics.js
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from projects.php. Extracted task-related logic:
 *                       loadProjectTasks, renderTaskList, loadSubtasks, attachSubtaskToggleHandlers,
 *                       createSubtask, editTask, deleteTask, toggleTaskStatus, uploadTaskFile и др.
 */

(function() {
    'use strict';

    function logDebugWrapper() {
        if (typeof window.logDebug === 'function') window.logDebug.apply(null, arguments);
    }
    function logErrorWrapper() {
        if (typeof window.logError === 'function') window.logError.apply(null, arguments);
    }

    // ========== ХЕЛПЕРЫ ==========

    function escapeHtmlLocal(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    window.escapeHtml = window.escapeHtml || escapeHtmlLocal;

    function formatDateLocal(ts) {
        if (!ts || ts === null || ts === 0) return '';
        var d = new Date(parseInt(ts));
        if (isNaN(d.getTime())) return '';
        var tz = -d.getTimezoneOffset() / 60;
        var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
        return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}) + ' (' + tzName + ')';
    }
    window.formatDate = window.formatDate || formatDateLocal;

    function showAlertLocal(message, type) {
        type = type || 'info';
        var alertDiv = document.createElement('div');
        alertDiv.className = 'custom-alert ' + type;
        alertDiv.innerHTML = message;
        alertDiv.style.cssText = 'position:fixed;bottom:20px;right:20px;background:' + (type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981')) + ';color:white;padding:10px 20px;border-radius:8px;z-index:10000;font-size:14px;max-width:300px;word-wrap:break-word;box-shadow:0 2px 10px rgba(0,0,0,0.2);';
        document.body.appendChild(alertDiv);
        setTimeout(function() { if (alertDiv && alertDiv.remove) alertDiv.remove(); }, 3000);
    }
    window.showAlert = window.showAlert || showAlertLocal;

    function closeModalLocal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('active');
        if (id === 'project-modal') { var ci = document.getElementById('project-delete-confirm'); if (ci) ci.value = ''; }
        if (id === 'task-modal') { var ci = document.getElementById('task-delete-confirm'); if (ci) ci.value = ''; }
    }
    window.closeModal = window.closeModal || closeModalLocal;

    function getFileIconLocal(filename) {
        if (!filename) return '📎';
        var ext = filename.split('.').pop().toLowerCase();
        var icons = {'jpg':'🖼️','jpeg':'🖼️','png':'🖼️','gif':'🖼️','webp':'🖼️','pdf':'📄','doc':'📝','docx':'📝','xls':'📊','xlsx':'📊','zip':'📦','rar':'📦','7z':'📦','mp3':'🎵','mp4':'🎬','avi':'🎬','txt':'📃','md':'📃'};
        return icons[ext] || '📎';
    }
    window.getFileIcon = window.getFileIcon || getFileIconLocal;

    function utcToLocalDatetimeStringLocal(utcTimestamp) {
        if (!utcTimestamp || utcTimestamp === null || utcTimestamp === 0) return '';
        var date = new Date(parseInt(utcTimestamp));
        if (isNaN(date.getTime())) return '';
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');
        return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
    }
    window.utcToLocalDatetimeString = window.utcToLocalDatetimeString || utcToLocalDatetimeStringLocal;

    function getCurrentProjectUuidLocal() {
        return window.currentProjectUuid;
    }
    window.getCurrentProjectUuid = getCurrentProjectUuidLocal;

    // ========== ОТКРЫТИЕ СООБЩЕНИЙ ==========

    window.openTaskMessagesByUuid = function(taskUuid) {
        localStorage.setItem('selected_task_uuid', taskUuid);
        window.location.href = window.APP_BASE + '/messages.php?task=' + encodeURIComponent(taskUuid);
    };

    window.openTaskMessages = function() {
        var taskUuid = document.getElementById('task-uuid').value;
        if (taskUuid) {
            localStorage.setItem('selected_task_uuid', taskUuid);
            window.location.href = window.APP_BASE + '/messages.php?task=' + encodeURIComponent(taskUuid);
        }
    };

    // ========== ПРОВЕРКА ПРАВ ==========

    window.checkTaskCreationPermission = function(projectUuid) {
        var createTaskBtn = document.getElementById('create-task-btn');
        if (!createTaskBtn) return;

        var originalText = createTaskBtn.innerHTML;

        createTaskBtn.style.opacity = '0.7';
        createTaskBtn.disabled = true;
        createTaskBtn.innerHTML = '⏳ Проверка прав...';
        createTaskBtn.title = 'Проверка прав...';

        var formData = new URLSearchParams();
        formData.append('action', 'check_project_permission');
        formData.append('project_uuid', projectUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.can_create_task === true) {
                createTaskBtn.style.opacity = '1';
                createTaskBtn.disabled = false;
                createTaskBtn.innerHTML = '➕ Новая задача';
                createTaskBtn.title = 'Создать новую задачу';
                logDebugWrapper('[PERMISSION] User can create tasks in project:', projectUuid);
            } else {
                createTaskBtn.style.opacity = '0.3';
                createTaskBtn.disabled = true;
                createTaskBtn.innerHTML = '🚫 Нет прав';
                createTaskBtn.title = 'У вас нет прав на создание задач в этом проекте';
                logDebugWrapper('[PERMISSION] User CANNOT create tasks in project:', projectUuid);
            }
        })
        .catch(function(err) {
            logErrorWrapper('[PERMISSION] Error checking permission:', err);
            createTaskBtn.style.opacity = '0.5';
            createTaskBtn.disabled = true;
            createTaskBtn.innerHTML = '❌ Ошибка';
            createTaskBtn.title = 'Ошибка проверки прав. Попробуйте обновить страницу.';
        });
    };

    // ========== ЗАГРУЗКА ФАЙЛОВ ==========

    window.loadFilesForTasksBatch = function(taskUuids) {
        if (!taskUuids || taskUuids.length === 0) return Promise.resolve();

        var uuidsToLoad = taskUuids.filter(function(uuid) { return !window.loadedFilesCache[uuid]; });
        if (uuidsToLoad.length === 0) {
            for (var i = 0; i < taskUuids.length; i++) {
                window.displayFilesForTask(taskUuids[i]);
            }
            return Promise.resolve();
        }

        return new Promise(function(resolve, reject) {
            var formData = new URLSearchParams();
            formData.append('action', 'get_tasks_files_batch');
            formData.append('task_uuids', JSON.stringify(uuidsToLoad));
            formData.append('ajax_mode', '1');
            if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.files_by_task) {
                    for (var taskUuid in data.files_by_task) {
                        if (data.files_by_task.hasOwnProperty(taskUuid)) {
                            window.loadedFilesCache[taskUuid] = data.files_by_task[taskUuid];
                        }
                    }
                }
                for (var i = 0; i < taskUuids.length; i++) {
                    window.displayFilesForTask(taskUuids[i]);
                }
                resolve();
            })
            .catch(function(err) {
                logErrorWrapper('[BATCH_FILES] Error:', err);
                resolve();
            });
        });
    };

    window.displayFilesForTask = function(taskUuid) {
        var container = document.getElementById('task-files-' + taskUuid);
        if (!container) return;

        var files = window.loadedFilesCache[taskUuid] || [];
        if (files.length > 0) {
            var filesHtml = '<div style="display: flex; flex-wrap: wrap; gap: 6px;">';
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                var safeName = (file.name || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
                filesHtml += '<a href="#" class="task-file-item" onclick="event.stopPropagation(); showFilePreview(\'' +
                    file.uuid + '\', \'' + safeName + '\', ' + (file.size_bytes || 0) + ', \'' + (file.mime || '') + '\'); return false;">';
                filesHtml += getFileIconLocal(file.name) + ' ' + escapeHtmlLocal(file.name) + ' (' + file.size + ')';
                filesHtml += '</a>';
            }
            filesHtml += '</div>';
            container.innerHTML = filesHtml;
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
            container.innerHTML = '';
        }
    };

    // ========== ПОДЗАДАЧИ ==========

    window.loadSubtasks = function(parentUuid, containerId, page, append) {
        if (window.isLoadingSubtasks[parentUuid]) {
            logDebugWrapper('[LOAD_SUBTASKS] Already loading, skipping for parent:', parentUuid);
            return Promise.resolve();
        }

        page = page || 1;
        append = append || false;
        window.isLoadingSubtasks[parentUuid] = true;

        var container = document.getElementById(containerId);
        if (!container) {
            logDebugWrapper('[LOAD_SUBTASKS] Container not found:', containerId);
            window.isLoadingSubtasks[parentUuid] = false;
            return Promise.resolve();
        }

        logDebugWrapper('[LOAD_SUBTASKS] START - parentUuid:', parentUuid, 'page:', page, 'append:', append);

        var loadingDiv = container.querySelector('.subtasks-loading');
        var listDiv = container.querySelector('.subtasks-list');
        var paginationDiv = container.querySelector('.subtasks-pagination');

        if (loadingDiv) loadingDiv.style.display = 'block';

        var formData = new URLSearchParams();
        formData.append('action', 'get_project_tasks_sorted');
        formData.append('project_uuid', window.currentProjectUuid);
        formData.append('parent_uuid', parentUuid);
        formData.append('page', page);
        formData.append('per_page', '20');
        formData.append('sort_by', window.currentSortBy);
        formData.append('sort_dir', window.currentSortDir);
        formData.append('filter_statuses', JSON.stringify(window.currentFilterStatuses));
        formData.append('filter_assigned', JSON.stringify(window.currentFilterAssigned));
        formData.append('search', window.currentSearch);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            window.isLoadingSubtasks[parentUuid] = false;
            if (loadingDiv) loadingDiv.style.display = 'none';

            if (data.success && data.tasks && data.tasks.length > 0) {
                logDebugWrapper('[LOAD_SUBTASKS] Received', data.tasks.length, 'subtasks for parent:', parentUuid, 'total:', data.total);
                var tasksHtml = renderSubtasksList(data.tasks, parentUuid);

                if (append && listDiv) {
                    listDiv.insertAdjacentHTML('beforeend', tasksHtml);
                } else if (listDiv) {
                    listDiv.innerHTML = tasksHtml;
                }

                container.setAttribute('data-loaded', 'true');
                container.setAttribute('data-page', data.page || page);
                container.setAttribute('data-has-more', data.has_more ? 'true' : 'false');

                if (paginationDiv) {
                    if (data.has_more) {
                        var nextPage = (data.page || page) + 1;
                        var remaining = data.total - ((data.page || page) * 20);
                        paginationDiv.innerHTML = '<button class="btn-secondary" onclick="loadSubtasks(\'' + parentUuid + '\', \'' + containerId + '\', ' + nextPage + ', true)">📋 Загрузить ещё (' + remaining + ' осталось)</button>';
                        paginationDiv.style.display = 'block';
                    } else {
                        paginationDiv.style.display = 'none';
                        paginationDiv.innerHTML = '';
                    }
                }

                var subtaskUuids = [];
                var newTasks = listDiv.querySelectorAll('.subtask-item');
                for (var i = 0; i < newTasks.length; i++) {
                    var taskId = newTasks[i].dataset.taskUuid;
                    if (taskId) subtaskUuids.push(taskId);
                }
                if (subtaskUuids.length > 0) {
                    logDebugWrapper('[LOAD_SUBTASKS] Loading files for', subtaskUuids.length, 'subtasks');
                    window.loadFilesForTasksBatch(subtaskUuids);
                }
            } else if (!append && listDiv) {
                listDiv.innerHTML = '<div style="padding:16px; color:rgba(233,238,252,0.5); text-align:center;">📭 Нет подзадач, соответствующих фильтрам</div>';
            } else if (!data.success) {
                logErrorWrapper('[LOAD_SUBTASKS] Server error for parent', parentUuid, ':', data.error);
            }
            return Promise.resolve();
        })
        .catch(function(err) {
            window.isLoadingSubtasks[parentUuid] = false;
            logErrorWrapper('[LOAD_SUBTASKS] Error for parent', parentUuid, ':', err);
            if (loadingDiv) loadingDiv.style.display = 'none';
            if (listDiv) listDiv.innerHTML = '<div style="padding:16px; color:#f87171; text-align:center;">❌ Ошибка загрузки подзадач</div>';
            return Promise.resolve();
        });
    };

    function renderSubtasksList(tasks, parentUuid) {
        if (!tasks || tasks.length === 0) return '';

        var html = '';
        for (var i = 0; i < tasks.length; i++) {
            var t = tasks[i];
            var isCompleted = (t.status === 1);
            var assigneeText = t.assignee_name || t.assignee_login || 'Не назначен';
            var hasSubtasks = (t.subtasks_count > 0);
            var filesContainerId = 'task-files-' + t.uuid;
            var isSubscribed = t.is_subscribed === true;
            var subBtnText = isSubscribed ? '🔕 Отписаться' : '🔔 Подписаться';
            var subBtnClass = isSubscribed ? 'subscribed' : '';

            var descrHtml = '';
            var descrText = t.descr || '';
            var descId = 'task_' + t.uuid + '_desc';
            if (descrText.trim() !== '') {
                descrHtml = '<div class="task-description collapsible-description collapsed" data-desc-id="' + descId + '">' + (typeof parseDescriptionLinks === 'function' ? parseDescriptionLinks(descrText) : escapeHtmlLocal(descrText)) + '</div>';
            } else {
                descrHtml = '<div class="task-description-empty">Нет описания</div>';
            }

            var taskUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + t.uuid;
            var escapedTitle = escapeHtmlLocal(t.title).replace(/"/g, '&quot;');

            html += '<div class="subtask-item flat-task-item" data-task-uuid="' + t.uuid + '">';
            html += '<div class="flat-task-row-link" style="display: block; text-decoration: none; color: inherit;" data-task-url="' + taskUrl + '" data-task-title="' + escapedTitle + '">';
            html += '<div class="flat-task-row" style="cursor: pointer;">';
            html += '<div class="flat-task-checkbox ' + (isCompleted ? 'completed' : '') + '" onclick="event.stopPropagation(); toggleTaskStatus(event, \'' + t.uuid + '\', ' + (!isCompleted) + ')"></div>';
            html += '<div class="flat-task-content">';
            html += '<div class="flat-task-title ' + (isCompleted ? 'completed' : '') + '">' + escapeHtmlLocal(t.title) + '</div>';
            html += descrHtml;
            html += '<div class="flat-task-meta">';
            html += '<span class="flat-task-badge">👤 ' + escapeHtmlLocal(assigneeText) + '</span>';
            if (t.time_start) html += '<span class="flat-task-badge">🚀 ' + formatDateLocal(t.time_start) + '</span>';
            if (t.time_end_plan) html += '<span class="flat-task-badge">📅 ' + formatDateLocal(t.time_end_plan) + '</span>';
            if (t.messages_count > 0) html += '<span class="flat-task-badge clickable" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + t.uuid + '\')">💬 ' + t.messages_count + '</span>';
            if (t.files_count > 0) html += '<span class="flat-task-badge">📎 ' + t.files_count + '</span>';
            if (hasSubtasks) html += '<span class="flat-task-badge clickable subtasks-toggle" data-task-uuid="' + t.uuid + '">📋 ' + t.subtasks_count + '</span>';
            html += '</div>';
            html += '<div class="task-files-container" id="' + filesContainerId + '"><span class="files-loading">⏳ Загрузка файлов...</span></div>';
            html += '</div>';
            html += '<div class="flat-task-actions" onclick="event.stopPropagation()">';
            if (t.can_edit) {
                html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="editTask(\'' + t.uuid + '\')" title="Редактировать">✏️</button>';
                html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="createSubtask(\'' + t.uuid + '\')" title="Создать подзадачу">➕</button>';
            }
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="copyTaskLink(\'' + t.uuid + '\', \'' + escapeHtmlLocal(t.title).replace(/'/g, "\\'") + '\')" title="Скопировать ссылку на задачу">🔗</button>';
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px ' + subBtnClass + '" id="sub-btn-' + t.uuid + '" onclick="toggleTaskSubscription(\'' + t.uuid + '\', this)">' + subBtnText + '</button>';
            html += '<button class="btn-messages-icon" onclick="openTaskMessagesByUuid(\'' + t.uuid + '\')">💬</button>';

            if (t.files_count > 0) {
                html += '<a href="' + window.APP_BASE + '/files.php?task=' + t.uuid + '" class="btn-secondary" style="padding:4px 8px;font-size:11px; text-decoration: none;" target="_blank" title="Все файлы задачи">📎 Файлы</a>';
            }

            html += '</div>';
            html += '</div>';
            html += '</div>';

            if (hasSubtasks) {
                html += '<div class="subtasks-container" id="children-' + t.uuid + '" data-loaded="false" data-page="1" data-has-more="false">';
                html += '<div class="subtasks-loading" style="display:none;">⏳ Загрузка подзадач...</div>';
                html += '<div class="subtasks-list"></div>';
                html += '<div class="subtasks-pagination" style="display:none;"></div>';
                html += '</div>';
            }
            html += '</div>';
        }

        setTimeout(function() {
            initCollapsibleDescriptions();
        }, 200);

        setTimeout(function() {
            var container = document.getElementById('children-' + parentUuid);
            if (container) {
                var rows = container.querySelectorAll('.flat-task-row');
                for (var j = 0; j < rows.length; j++) {
                    var parentLink = rows[j].closest('.flat-task-row-link');
                    if (parentLink && parentLink.dataset.taskUrl) {
                        rows[j].dataset.taskUrl = parentLink.dataset.taskUrl;
                        rows[j].dataset.taskTitle = parentLink.dataset.taskTitle || 'задачи';
                    }

                    rows[j].addEventListener('click', function(e) {
                        if (window.isSelectingText) {
                            e.stopPropagation();
                            return;
                        }

                        var selection = window.getSelection();
                        if (selection && selection.toString().trim().length > 0) {
                            e.stopPropagation();
                            return;
                        }

                        if (e.target.closest('.flat-task-actions, .flat-task-checkbox, .btn-secondary, .btn-messages-icon, .subtasks-toggle, .task-file-item')) {
                            return;
                        }

                        if (e.target.closest('.task-description, .task-description-empty, .flat-task-title')) {
                            return;
                        }

                        var url = this.dataset.taskUrl;
                        var title = this.dataset.taskTitle || 'задачи';
                        if (url) {
                            navigator.clipboard.writeText(url).then(function() {
                                showAlertLocal('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                            }).catch(function() {
                                var textarea = document.createElement('textarea');
                                textarea.value = url;
                                document.body.appendChild(textarea);
                                textarea.select();
                                document.execCommand('copy');
                                document.body.removeChild(textarea);
                                showAlertLocal('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                            });
                        }
                    });
                }
            }
        }, 100);
        window.restoreHighlightAfterRender();
        return html;
    }

    // ========== COLLAPSIBLE DESCRIPTIONS ==========

    function initCollapsibleDescriptions() {
        var taskDescs = document.querySelectorAll('.task-description');
        for (var i = 0; i < taskDescs.length; i++) { makeCollapsible(taskDescs[i]); }
        var projectDescs = document.querySelectorAll('.project-descr');
        for (var i = 0; i < projectDescs.length; i++) { makeCollapsible(projectDescs[i]); }
    }
    window.initCollapsibleDescriptions = initCollapsibleDescriptions;

    function makeCollapsible(descElement) {
        if (!descElement || descElement.classList.contains('collapsible-processed')) return;

        var pureText = descElement.innerText || descElement.textContent || '';
        var descId = descElement.getAttribute('data-desc-id');
        if (!descId) {
            var parentTask = descElement.closest('[data-task-uuid]');
            if (parentTask && parentTask.dataset.taskUuid) {
                descId = 'task_' + parentTask.dataset.taskUuid + '_desc';
            } else {
                var parentProject = descElement.closest('.project-card');
                if (parentProject && parentProject.dataset.projectUuid) {
                    descId = 'project_' + parentProject.dataset.projectUuid + '_desc';
                } else {
                    descId = 'desc_' + Math.random().toString(36).substr(2, 8);
                }
            }
            descElement.setAttribute('data-desc-id', descId);
        }

        var lineCount = (pureText.match(/\n/g) || []).length;
        var isLongText = pureText.length > 300;
        var isMultiLine = lineCount > 5;
        var isLongMultiLine = pureText.length > 150 && lineCount > 2;

        if (!isLongText && !isMultiLine && !isLongMultiLine) {
            return;
        }

        var existingToggle = descElement.nextElementSibling;
        if (existingToggle && existingToggle.classList.contains('collapsible-description-toggle')) {
            return;
        }

        descElement.classList.add('collapsible-description');
        descElement.classList.add('collapsible-processed');

        var savedState = localStorage.getItem('collapsed_desc_' + descId);
        var isCollapsed = (savedState !== 'false');
        if (isCollapsed) descElement.classList.add('collapsed');
        else descElement.classList.remove('collapsed');

        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'collapsible-description-toggle' + (isCollapsed ? ' collapsed' : '');
        toggleBtn.innerHTML = isCollapsed ? 'Развернуть описание' : 'Свернуть описание';

        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var currentlyCollapsed = descElement.classList.contains('collapsed');
            if (currentlyCollapsed) {
                descElement.classList.remove('collapsed');
                toggleBtn.classList.remove('collapsed');
                toggleBtn.innerHTML = 'Свернуть описание';
                localStorage.setItem('collapsed_desc_' + descId, 'false');
            } else {
                descElement.classList.add('collapsed');
                toggleBtn.classList.add('collapsed');
                toggleBtn.innerHTML = 'Развернуть описание';
                localStorage.setItem('collapsed_desc_' + descId, 'true');
            }
            setTimeout(function() {
                if (descElement.classList.contains('collapsed')) descElement.style.maxHeight = '';
            }, 10);
        });

        descElement.parentNode.insertBefore(toggleBtn, descElement.nextSibling);
    }

    // ========== ПОДПИСКИ ==========

    window.toggleTaskSubscription = function(taskUuid, buttonElement) {
        if (!taskUuid) { showAlertLocal('Не указана задача', 'warning'); return; }
        var originalText = buttonElement ? buttonElement.innerHTML : 'Подписаться';
        if (buttonElement) { buttonElement.innerHTML = '⏳...'; buttonElement.disabled = true; }

        var formData = new URLSearchParams();
        formData.append('action', 'toggle_task_subscription');
        formData.append('task_uuid', taskUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (buttonElement) buttonElement.disabled = false;
            if (d.success) {
                if (buttonElement) {
                    if (d.is_subscribed) {
                        buttonElement.innerHTML = '🔕 Отписаться';
                        buttonElement.title = 'Отписаться от уведомлений задачи';
                        buttonElement.classList.add('subscribed');
                    } else {
                        buttonElement.innerHTML = '🔔 Подписаться';
                        buttonElement.title = 'Подписаться на уведомления задачи';
                        buttonElement.classList.remove('subscribed');
                    }
                }
                showAlertLocal(d.message, 'success');
            } else {
                if (buttonElement) buttonElement.innerHTML = originalText;
                showAlertLocal('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(function(err) {
            logErrorWrapper('Toggle subscription error:', err);
            if (buttonElement) { buttonElement.innerHTML = originalText; buttonElement.disabled = false; }
            showAlertLocal('Ошибка сети', 'error');
        });
    };

    // ========== СТАТУС ЗАДАЧИ ==========

    window.toggleTaskStatus = function(e, uuid, done) {
        e.stopPropagation();
        var newStatus = done ? 'выполнена' : 'не выполнена';
        if (!confirm('Вы уверены, что хотите отметить задачу как ' + newStatus + '?')) return;

        var formData = new URLSearchParams();
        formData.append('action', 'toggle_task_status');
        formData.append('uuid', uuid);
        formData.append('status', done ? 1 : 0);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                showAlertLocal('Статус задачи изменён на "' + newStatus + '"', 'success');
                if (window.currentProjectUuid) window.loadProjectTasks(true);
            } else {
                if (d.csrf_error) { showAlertLocal('Ошибка безопасности. Обновите страницу.', 'error'); return; }
                showAlertLocal('Ошибка: ' + d.error, 'error');
            }
        })
        .catch(function(err) { logErrorWrapper('[TOGGLE_STATUS] Error:', err); showAlertLocal('Ошибка при изменении статуса', 'error'); });
    };

    // ========== СОЗДАНИЕ ПОДЗАДАЧИ ==========

    window.createSubtask = function(parentUuid) {
        if (!window.currentProjectUuid) {
            showAlertLocal('Сначала выберите проект', 'warning');
            return;
        }

        logDebugWrapper('[CREATE_SUBTASK] Creating subtask for parent:', parentUuid, 'currentProjectUuid:', window.currentProjectUuid);

        window._pendingParentUuid = parentUuid;

        var triggerButton = document.activeElement;
        var originalButtonText = '';
        if (triggerButton && triggerButton.classList && triggerButton.classList.contains('btn-secondary')) {
            originalButtonText = triggerButton.innerHTML;
            triggerButton.innerHTML = '⏳ Проверка...';
            triggerButton.disabled = true;
        }

        var formData = new URLSearchParams();
        formData.append('action', 'check_project_permission');
        formData.append('project_uuid', window.currentProjectUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (triggerButton && originalButtonText) {
                triggerButton.innerHTML = originalButtonText;
                triggerButton.disabled = false;
            }

            if (data.success && data.can_create_task === true) {
                window.openCreateSubtaskModal(parentUuid);
            } else {
                showAlertLocal('У вас нет прав на создание подзадач в этом проекте', 'warning');
            }
            window._pendingParentUuid = null;
        })
        .catch(function(err) {
            logErrorWrapper('[CREATE_SUBTASK] Error checking permission:', err);
            if (triggerButton && originalButtonText) {
                triggerButton.innerHTML = originalButtonText;
                triggerButton.disabled = false;
            }
            showAlertLocal('Ошибка проверки прав. Попробуйте обновить страницу.', 'error');
            window._pendingParentUuid = null;
        });
    };

    window.openCreateSubtaskModal = function(parentUuid) {
        window.isCreatingSubtask = true;

        var parentTaskElement = document.querySelector('.flat-task-item[data-task-uuid="' + parentUuid + '"] .flat-task-title, .subtask-item[data-task-uuid="' + parentUuid + '"] .flat-task-title');
        var parentTitle = parentTaskElement ? parentTaskElement.innerText : 'задачи';

        document.getElementById('task-modal-title').innerText = '➕ Создание подзадачи: ' + escapeHtmlLocal(parentTitle);

        document.getElementById('task-uuid').value = '';
        document.getElementById('task-title').value = '';
        document.getElementById('task-descr').value = '';
        document.getElementById('task-assigned-to').value = '';
        document.getElementById('task-time-start').value = '';
        document.getElementById('task-time-end').value = '';
        document.getElementById('task-files-list').innerHTML = '';
        document.getElementById('file-manager').style.display = 'none';
        document.getElementById('open-messages-btn').style.display = 'none';

        var taskDeleteSection = document.getElementById('task-delete-section');
        if (taskDeleteSection) taskDeleteSection.style.display = 'none';
        var deleteConfirm = document.getElementById('task-delete-confirm');
        if (deleteConfirm) deleteConfirm.value = '';

        document.getElementById('task-project-uuid').value = window.currentProjectUuid;
        document.getElementById('task-parent-uuid-old').value = '';

        var projectSelect = document.getElementById('task-project-select');
        if (projectSelect) {
            projectSelect.value = window.currentProjectUuid;
        }

        window.updateParentSelect(window.currentProjectUuid, null, function() {
            var parentSelect = document.getElementById('task-parent-select');
            if (parentSelect) {
                var optionExists = false;
                for (var i = 0; i < parentSelect.options.length; i++) {
                    if (parentSelect.options[i].value === parentUuid) {
                        optionExists = true;
                        break;
                    }
                }

                if (optionExists) {
                    parentSelect.value = parentUuid;
                } else {
                    var newOption = document.createElement('option');
                    newOption.value = parentUuid;
                    newOption.textContent = parentTitle + ' (текущая задача)';
                    parentSelect.appendChild(newOption);
                    parentSelect.value = parentUuid;
                }
            }
        });

        document.getElementById('task-modal').classList.add('active');

        setTimeout(function() {
            window.isCreatingSubtask = false;
        }, 1000);
    };

    // ========== МОДАЛ СОЗДАНИЯ ЗАДАЧИ ==========

    window.showCreateTaskModal = function() {
        var createTaskBtn = document.getElementById('create-task-btn');
        if (createTaskBtn && createTaskBtn.disabled === true) {
            showAlertLocal('У вас нет прав на создание задач в этом проекте', 'warning');
            return;
        }

        if (!window.currentProjectUuid) {
            showAlertLocal('Сначала выберите проект', 'warning');
            return;
        }

        var originalText = createTaskBtn ? createTaskBtn.innerHTML : '';
        if (createTaskBtn) {
            createTaskBtn.innerHTML = '⏳ Проверка...';
            createTaskBtn.disabled = true;
        }

        var formData = new URLSearchParams();
        formData.append('action', 'check_project_permission');
        formData.append('project_uuid', window.currentProjectUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (createTaskBtn) {
                createTaskBtn.innerHTML = originalText;
                createTaskBtn.disabled = false;
            }

            if (data.success && data.can_create_task === true) {
                window.isCreatingSubtask = false;
                document.getElementById('task-modal-title').innerText = '➕ Создание задачи';
                document.getElementById('task-uuid').value = '';
                document.getElementById('task-title').value = '';
                document.getElementById('task-descr').value = '';
                document.getElementById('task-assigned-to').value = '';
                document.getElementById('task-time-start').value = '';
                document.getElementById('task-time-end').value = '';
                document.getElementById('task-files-list').innerHTML = '';

                document.getElementById('task-project-uuid').value = window.currentProjectUuid;

                var projectSelect = document.getElementById('task-project-select');
                if (projectSelect) {
                    projectSelect.value = window.currentProjectUuid;
                }

                window.updateParentSelect(window.currentProjectUuid, null, function() {
                    var parentSelect = document.getElementById('task-parent-select');
                    if (parentSelect) parentSelect.value = '';
                });
                document.getElementById('file-manager').style.display = 'none';
                document.getElementById('open-messages-btn').style.display = 'none';
                var taskDeleteSection = document.getElementById('task-delete-section');
                if (taskDeleteSection) taskDeleteSection.style.display = 'none';
                var deleteConfirm = document.getElementById('task-delete-confirm');
                if (deleteConfirm) deleteConfirm.value = '';
                document.getElementById('task-modal').classList.add('active');
            } else {
                showAlertLocal('У вас нет прав на создание задач в этом проекте', 'warning');
            }
        })
        .catch(function(err) {
            logErrorWrapper('[SHOW_CREATE_TASK_MODAL] Error checking permission:', err);
            if (createTaskBtn) {
                createTaskBtn.innerHTML = originalText;
                createTaskBtn.disabled = false;
            }
            showAlertLocal('Ошибка проверки прав. Попробуйте обновить страницу.', 'error');
        });
    };

    // ========== РЕДАКТИРОВАНИЕ ЗАДАЧИ ==========

    window.editTask = function(uuid) {
        logDebugWrapper('[EDIT_TASK] Editing task:', uuid);
        window.currentEditTaskUuid = uuid;
        var permFormData = new URLSearchParams();
        permFormData.append('action', 'check_edit_permission');
        permFormData.append('task_uuid', uuid);
        permFormData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(permFormData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: permFormData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { showAlertLocal(d.error || 'Нет прав на редактирование', 'error'); return; }
            window.loadTaskForEdit(uuid);
        })
        .catch(function(err) { logErrorWrapper('[EDIT_TASK] Permission check error:', err); showAlertLocal('Ошибка проверки прав', 'error'); });
    };

    window.loadTaskForEdit = function(uuid) {
        logDebugWrapper('[LOAD_TASK_FOR_EDIT] Loading task for edit:', uuid);

        var formData = new URLSearchParams();
        formData.append('action', 'get_task');
        formData.append('uuid', uuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success && d.task) {
                logDebugWrapper('[LOAD_TASK_FOR_EDIT] Task loaded, project_uuid:', d.task.project_uuid);

                document.getElementById('task-modal-title').innerText = 'Редактирование задачи';
                document.getElementById('task-uuid').value = d.task.uuid;
                document.getElementById('task-project-uuid').value = d.task.project_uuid;
                document.getElementById('task-parent-uuid-old').value = d.task.parent_task_uuid || '';
                document.getElementById('task-title').value = d.task.title;
                document.getElementById('task-descr').value = d.task.descr || '';
                document.getElementById('task-assigned-to').value = d.task.assigned_to_uuid || '';

                var projectSelect = document.getElementById('task-project-select');
                if (projectSelect) {
                    projectSelect.value = d.task.project_uuid;
                }

                document.getElementById('task-time-start').value = utcToLocalDatetimeStringLocal(d.task.time_start_utc);
                document.getElementById('task-time-end').value = utcToLocalDatetimeStringLocal(d.task.time_end_plan_utc);

                if (d.task.project_uuid) {
                    window.updateParentSelect(d.task.project_uuid, d.task.uuid, function() {
                        var select = document.getElementById('task-parent-select');
                        if (select && d.task.parent_task_uuid) {
                            var optionExists = false;
                            for (var i = 0; i < select.options.length; i++) {
                                if (select.options[i].value === d.task.parent_task_uuid) {
                                    optionExists = true;
                                    break;
                                }
                            }

                            if (optionExists) {
                                select.value = d.task.parent_task_uuid;
                            } else {
                                var newOption = document.createElement('option');
                                newOption.value = d.task.parent_task_uuid;
                                newOption.textContent = d.task.title + ' (текущая задача)';
                                select.appendChild(newOption);
                                select.value = d.task.parent_task_uuid;
                            }
                        }
                    });
                }

                document.getElementById('file-manager').style.display = 'block';
                document.getElementById('open-messages-btn').style.display = 'inline-flex';
                var taskDeleteSection = document.getElementById('task-delete-section');
                if (taskDeleteSection) taskDeleteSection.style.display = 'block';
                if (d.files) window.renderTaskFiles(d.files);
                document.getElementById('task-modal').classList.add('active');
            } else {
                logErrorWrapper('[LOAD_TASK_FOR_EDIT] Task not found or no access:', d.error);
                showAlertLocal('Задача не найдена', 'error');
            }
        })
        .catch(function(err) {
            logErrorWrapper('[LOAD_TASK_FOR_EDIT] Error:', err);
            showAlertLocal('Ошибка загрузки задачи', 'error');
        });
    };

    // ========== РОДИТЕЛЬСКИЕ ЗАДАЧИ ==========

    window.updateParentSelect = function(projectUuid, currentTaskUuid, callback) {
        if (!projectUuid) {
            if (callback) callback();
            return;
        }

        var formData = new URLSearchParams();
        formData.append('action', 'get_project_tasks_sorted');
        formData.append('project_uuid', projectUuid);
        formData.append('page', '1');
        formData.append('per_page', '500');
        formData.append('sort_by', 'title');
        formData.append('sort_dir', 'ASC');
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var select = document.getElementById('task-parent-select');
            if (!select) {
                if (callback) callback();
                return;
            }

            if (data.success && data.tasks) {
                var html = '<option value="">-- Нет (корневая задача) --</option>';
                var excludedCount = 0;

                for (var i = 0; i < data.tasks.length; i++) {
                    var task = data.tasks[i];
                    if (currentTaskUuid && task.uuid === currentTaskUuid) {
                        excludedCount++;
                        continue;
                    }
                    html += '<option value="' + escapeHtmlLocal(task.uuid) + '">' + escapeHtmlLocal(task.title) + '</option>';
                }

                select.innerHTML = html;
                logDebugWrapper('[UPDATE_PARENT_SELECT] Loaded ' + data.tasks.length + ' potential parents, excluded ' + excludedCount);

                if (callback) callback();
            } else {
                select.innerHTML = '<option value="">-- Нет доступных родительских задач --</option>';
                if (callback) callback();
            }
        })
        .catch(function(err) {
            logErrorWrapper('[UPDATE_PARENT_SELECT] Error:', err);
            var select = document.getElementById('task-parent-select');
            if (select) {
                select.innerHTML = '<option value="">-- Ошибка загрузки --</option>';
            }
            if (callback) callback();
        });
    };

    window.onTaskProjectChange = function() {
        var newProjectUuid = document.getElementById('task-project-select').value;
        var taskUuid = document.getElementById('task-uuid').value;

        if (!taskUuid) {
            if (newProjectUuid) {
                window.updateParentSelect(newProjectUuid, null);
                document.getElementById('task-project-uuid').value = newProjectUuid;
            }
            return;
        }

        var oldProjectUuid = document.getElementById('task-project-uuid').value;
        if (newProjectUuid !== oldProjectUuid) {
            if (confirm('Внимание! Перемещение задачи в другой проект также переместит все подзадачи и сообщения. Продолжить?')) {
                document.getElementById('task-project-uuid').value = newProjectUuid;
                window.updateParentSelect(newProjectUuid, taskUuid);
                showAlertLocal('Проект задачи изменён. Не забудьте сохранить изменения.', 'info');
            } else {
                document.getElementById('task-project-select').value = oldProjectUuid;
            }
        } else {
            window.updateParentSelect(newProjectUuid, taskUuid);
        }
    };

    // ========== ФАЙЛЫ ЗАДАЧИ ==========

    window.renderTaskFiles = function(files) {
        var container = document.getElementById('task-files-list');
        if (!container) return;
        if (!files || files.length === 0) { container.innerHTML = '<div style="text-align:center;color:rgba(233,238,252,.5);padding:16px;">Нет прикреплённых файлов</div>'; return; }
        var html = '';
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            html += '<div class="file-item-row" style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.05);">';
            html += '<div class="file-info"><span>' + getFileIconLocal(file.name) + ' ' + escapeHtmlLocal(file.name) + ' (' + file.size + ')</span></div>';
            html += '<div class="file-actions" style="display:flex; gap:8px;">';
            html += '<a href="' + file.url + '" class="btn-icon" download style="background:none; border:none; cursor:pointer; font-size:16px;">📥</a>';
            html += '<button type="button" class="btn-icon" onclick="detachFile(\'' + file.uuid + '\')" style="background:none; border:none; cursor:pointer; font-size:16px; color:#f87171;">❌</button>';
            html += '</div></div>';
        }
        container.innerHTML = html;
    };

    window.uploadTaskFile = function() {
        var taskUuid = document.getElementById('task-uuid').value;
        if (!taskUuid) { showAlertLocal('Сначала сохраните задачу', 'warning'); return; }
        var fileInput = document.getElementById('task-file-upload');
        var file = fileInput.files[0];
        if (!file) { showAlertLocal('Выберите файл', 'warning'); return; }
        var formData = new FormData();
        formData.append('action', 'upload_task_file');
        formData.append('task_uuid', taskUuid);
        formData.append('ajax_mode', '1');
        formData.append('file', file);
        if (typeof addCsrfToFormData === 'function') addCsrfToFormData(formData);

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                showAlertLocal('Файл загружен', 'success');
                fileInput.value = '';
                window.renderTaskFiles(d.files);
                if (window.currentProjectUuid) window.loadProjectTasks(true);
            } else {
                if (d.csrf_error) { showAlertLocal('Ошибка безопасности. Обновите страницу.', 'error'); return; }
                showAlertLocal('Ошибка: ' + (d.error || 'Неизвестная'), 'error');
            }
        })
        .catch(function(err) { logErrorWrapper('[UPLOAD_FILE] Error:', err); showAlertLocal('Ошибка загрузки файла', 'error'); });
    };

    window.detachFile = function(fileUuid) {
        if (!confirm('Открепить файл от задачи?')) return;
        var taskUuid = document.getElementById('task-uuid').value;
        var formData = new URLSearchParams();
        formData.append('action', 'detach_file_from_task');
        formData.append('task_uuid', taskUuid);
        formData.append('file_uuid', fileUuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                showAlertLocal('Файл откреплён', 'success');
                window.renderTaskFiles(d.files);
                if (window.currentProjectUuid) window.loadProjectTasks(true);
            } else { showAlertLocal('Ошибка: ' + (d.error || 'Неизвестная'), 'error'); }
        });
    };

    // ========== СОХРАНЕНИЕ ЗАДАЧИ ==========

    window.saveTask = function(e, retryCount) {
        if (retryCount === undefined) retryCount = 0;
        e.preventDefault();
        var uuid = document.getElementById('task-uuid').value.trim();
        var action = uuid ? 'edit_task' : 'create_task';
        var title = document.getElementById('task-title').value.trim();
        var parentSelect = document.getElementById('task-parent-select');
        var parentTaskUuid = parentSelect ? parentSelect.value : '';
        if (!parentTaskUuid || parentTaskUuid === '' || parentTaskUuid === 'null') parentTaskUuid = '';
        var localStart = document.getElementById('task-time-start').value;
        var localEnd = document.getElementById('task-time-end').value;

        var isCreatingSubtask = (action === 'create_task' && parentTaskUuid && parentTaskUuid !== '');
        var createdParentUuid = isCreatingSubtask ? parentTaskUuid : null;

        var formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('uuid', uuid);
        formData.append('project_uuid', document.getElementById('task-project-select').value);
        formData.append('parent_task_uuid', parentTaskUuid);
        formData.append('title', title);
        formData.append('descr', document.getElementById('task-descr').value.trim());
        formData.append('assigned_to_uuid', document.getElementById('task-assigned-to').value);
        formData.append('time_start', localStart);
        formData.append('time_end_plan', localEnd);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        if (!title) { showAlertLocal('Название задачи обязательно', 'warning'); return; }
        var submitBtn = document.querySelector('#task-form button[type="submit"]');
        var originalText = submitBtn ? submitBtn.innerHTML : 'Сохранить';
        if (submitBtn && retryCount === 0) { submitBtn.innerHTML = '⏳ Сохранение...'; submitBtn.disabled = true; }

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) {
            if (response.status === 503 && retryCount < 3) {
                var delay = Math.min(10000, 1000 * Math.pow(2, retryCount));
                if (submitBtn) submitBtn.innerHTML = '⏳ Повтор через ' + Math.round(delay/1000) + 'с...';
                setTimeout(function() { window.saveTask(e, retryCount + 1); }, delay);
                return null;
            }
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(d) {
            if (d && d.success) {
                closeModalLocal('task-modal');
                showAlertLocal('Задача "' + title + '" ' + (action === 'create_task' ? 'создана' : 'сохранена'), 'success');

                if (isCreatingSubtask && d.task && d.task.uuid) {
                    var newTaskUuid = d.task.uuid;
                    window.ensureTaskVisible(newTaskUuid, function(success) {
                        if (!success) {
                            if (window.currentProjectUuid) window.loadProjectTasks(true);
                        }
                    });
                } else if (window.currentProjectUuid) {
                    window.loadProjectTasks(true);
                }

                if (d.refresh_dashboard && typeof window.refreshDashboard === 'function') setTimeout(function() { window.refreshDashboard(); }, 500);
                if (window.SSE && typeof window.SSE.refreshCounters === 'function') setTimeout(function() { window.SSE.refreshCounters(); }, 1000);
            } else if (d && !d.success) {
                if (d.csrf_error) showAlertLocal('Ошибка безопасности. Обновите страницу.', 'error');
                else showAlertLocal('Ошибка: ' + (d.error || 'Неизвестная'), 'error');
            }
            if (submitBtn) { submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
        })
        .catch(function(err) {
            logErrorWrapper('[SAVE_TASK] Error:', err);
            if (retryCount < 3) {
                var delay = Math.min(10000, 1000 * Math.pow(2, retryCount));
                setTimeout(function() { window.saveTask(e, retryCount + 1); }, delay);
            } else {
                showAlertLocal('Ошибка сохранения задачи. Попробуйте позже.', 'error');
                if (submitBtn) { submitBtn.innerHTML = originalText; submitBtn.disabled = false; }
            }
        });
    };

    window.deleteTaskWithConfirm = function() {
        var confirmValue = document.getElementById('task-delete-confirm').value;
        if (confirmValue !== 'DELETE') { showAlertLocal('Для подтверждения удаления введите слово DELETE', 'error'); return; }
        if (!confirm('ВНИМАНИЕ! Это действие НЕОБРАТИМО. Удалить задачу и все подзадачи?')) return;
        var uuid = document.getElementById('task-uuid').value.trim();
        var formData = new URLSearchParams();
        formData.append('action', 'delete_task');
        formData.append('uuid', uuid);
        formData.append('confirm', 'DELETE');
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                closeModalLocal('task-modal');
                showAlertLocal('Задача удалена', 'success');
                if (window.currentProjectUuid) window.loadProjectTasks(true);
            } else { showAlertLocal('Ошибка: ' + d.error, 'error'); }
        });
    };

    // ========== КОПИРОВАНИЕ ССЫЛКИ ==========

    window.copyTaskLink = function(taskUuid, taskTitle) {
        var fullUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + taskUuid;
        navigator.clipboard.writeText(fullUrl).then(function() {
            showAlertLocal('✓ Ссылка на задачу "' + taskTitle + '" скопирована', 'success');
        }).catch(function() {
            var textarea = document.createElement('textarea');
            textarea.value = fullUrl;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            showAlertLocal('✓ Ссылка на задачу "' + taskTitle + '" скопирована', 'success');
        });
    };

    // ========== СПИСОК ЗАДАЧ ==========

    window.loadProjectTasks = function(resetPage) {
        if (!window.currentProjectUuid) {
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('ready', '✓ Выберите проект', false);
            }
            return;
        }
        if (resetPage === true) {
            window.currentTaskPage = 1;
        }
        if (window.isLoadingTasks) {
            return;
        }
        window.isLoadingTasks = true;

        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('loading', '⏳ Загрузка задач...', true);
        }

        var container = document.getElementById('tasks-list-container');
        if (container && window.currentTaskPage === 1) container.innerHTML = '<div class="tasks-loading">⏳ Загрузка задач...</div>';

        var formData = new URLSearchParams();
        formData.append('action', 'get_project_tasks_sorted');
        formData.append('project_uuid', window.currentProjectUuid);
        formData.append('page', window.currentTaskPage);
        formData.append('per_page', window.currentPerPage);
        formData.append('sort_by', window.currentSortBy);
        formData.append('sort_dir', window.currentSortDir);
        formData.append('filter_statuses', JSON.stringify(window.currentFilterStatuses));
        formData.append('filter_assigned', JSON.stringify(window.currentFilterAssigned));
        formData.append('search', window.currentSearch);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) {
            if (response.status === 503) throw new Error('503 Service Unavailable');
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            window.isLoadingTasks = false;

            if (data.success) {
                logDebugWrapper('[LOAD_TASKS] Received', data.tasks.length, 'tasks, total:', data.total);
                window.renderTaskList(data.tasks);
                window.renderPagination(data.total, data.page, data.per_page);
                if (typeof window.setPageLoadStatus === 'function') {
                    window.setPageLoadStatus('ready', '✓ Готово', false);
                }
            } else if (data.error) {
                logErrorWrapper('[LOAD_TASKS] Server error:', data.error);
                if (container) container.innerHTML = '<div class="empty-state">❌ ' + escapeHtmlLocal(data.error) + '</div>';
                if (typeof window.setPageLoadStatus === 'function') {
                    window.setPageLoadStatus('error', '⚠️ ' + escapeHtmlLocal(data.error), false);
                }
            } else {
                if (container) container.innerHTML = '<div class="empty-state">📭 Нет задач в этом проекте</div>';
                if (typeof window.setPageLoadStatus === 'function') {
                    window.setPageLoadStatus('ready', '✓ Нет задач', false);
                }
            }
        })
        .catch(function(err) {
            window.isLoadingTasks = false;
            logErrorWrapper('[LOAD_TASKS] Error:', err.message);

            var container = document.getElementById('tasks-list-container');
            if (container) container.innerHTML = '<div class="empty-state">❌ Ошибка загрузки: ' + escapeHtmlLocal(err.message) + '</div>';

            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('error', '⚠️ Ошибка: ' + err.message, false);
            }
        });
    };

    window.renderTaskList = function(tasks) {
        var container = document.getElementById('tasks-list-container');
        if (!container) {
            return;
        }
        if (!tasks || tasks.length === 0) {
            container.innerHTML = '<div class="empty-state">📭 Нет задач, соответствующих фильтрам</div>';
            return;
        }

        var hasActiveFilters = (window.currentFilterStatuses.length > 0) || 
                               (window.currentFilterAssigned.length > 0) || 
                               (window.currentSearch && window.currentSearch.trim() !== '');

        var html = '<div class="flat-tasks-list">';
        var taskUuidsForFiles = [];

        for (var i = 0; i < tasks.length; i++) {
            var task = tasks[i];
            var isCompleted = (task.status === 1);
            var assigneeText = task.assignee_name || task.assignee_login || 'Не назначен';
            var hasSubtasks = (task.subtasks_count > 0);
            var isOverdue = false;
            if (task.time_end_plan && !isCompleted) {
                var deadlineDate = parseInt(task.time_end_plan);
                isOverdue = deadlineDate < Date.now();
            }

            var isParentContext = task.is_parent_context === true;
            var isCollapsedByDefault = task.is_collapsed_by_default === true;

            var shouldExpand = hasActiveFilters || (isParentContext && task.has_filtered_children === true);

            var parentContextClass = isParentContext ? ' parent-context-task' : '';
            var collapsedClass = (isParentContext && !hasActiveFilters && isCollapsedByDefault) ? ' collapsed-by-default' : '';

            var hasUnreadNotifications = (task.unread_notifications_count && task.unread_notifications_count > 0);
            var unreadClass = hasUnreadNotifications ? ' has-unread-notifications' : '';

            var descrHtml = '';
            var descrText = task.descr || '';
            var descId = 'task_' + task.uuid + '_desc';
            if (descrText.trim() !== '') {
                descrHtml = '<div class="task-description collapsible-description collapsed" data-desc-id="' + descId + '">' + (typeof parseDescriptionLinks === 'function' ? parseDescriptionLinks(descrText) : escapeHtmlLocal(descrText)) + '</div>';
            } else {
                descrHtml = '<div class="task-description-empty">Нет описания</div>';
            }

            var filesContainerId = 'task-files-' + task.uuid;
            var taskUrl = window.location.origin + window.APP_BASE + '/projects.php?task=' + task.uuid;
            var escapedTitle = escapeHtmlLocal(task.title).replace(/"/g, '&quot;');
            var isSubscribed = task.is_subscribed === true;
            var subBtnText = isSubscribed ? '🔕 Отписаться' : '🔔 Подписаться';
            var subBtnClass = isSubscribed ? 'subscribed' : '';

            var parentContextBadge = isParentContext ? '<span class="flat-task-badge" style="background: rgba(79,124,255,0.3);">📁 Контекст фильтра</span>' : '';

            var unreadBadge = '';
            if (hasUnreadNotifications) {
                unreadBadge = '<span class="flat-task-badge clickable unread-badge" style="background: rgba(239,68,68,0.2); color: #f87171; cursor: pointer;" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + task.uuid + '\')" title="Непрочитанные уведомления в задаче">🔔 ' + task.unread_notifications_count + '</span>';
            }

            html += '<div class="flat-task-item' + parentContextClass + collapsedClass + unreadClass + '" data-task-uuid="' + task.uuid + '" data-is-parent-context="' + (isParentContext ? 'true' : 'false') + '">';
            html += '<div class="flat-task-row-link" style="display: block; text-decoration: none; color: inherit;" data-task-url="' + taskUrl + '" data-task-title="' + escapedTitle + '">';
            html += '<div class="flat-task-row" style="cursor: pointer;">';
            html += '<div class="flat-task-checkbox ' + (isCompleted ? 'completed' : '') + '" onclick="event.stopPropagation(); toggleTaskStatus(event, \'' + task.uuid + '\', ' + (!isCompleted) + ')"></div>';
            html += '<div class="flat-task-content">';
            html += '<div class="flat-task-title ' + (isCompleted ? 'completed' : '') + '">' + escapeHtmlLocal(task.title) + '</div>';
            html += descrHtml;
            html += '<div class="flat-task-meta">';
            html += '<span class="flat-task-badge">👤 ' + escapeHtmlLocal(assigneeText) + '</span>';
            if (task.time_start) html += '<span class="flat-task-badge">🚀 ' + formatDateLocal(task.time_start) + '</span>';
            if (task.time_end_plan) html += '<span class="flat-task-badge' + (isOverdue ? ' highlight' : '') + '">📅 ' + formatDateLocal(task.time_end_plan) + (isOverdue ? ' ⚠️' : '') + '</span>';
            if (task.messages_count > 0) html += '<span class="flat-task-badge clickable" onclick="event.stopPropagation(); openTaskMessagesByUuid(\'' + task.uuid + '\')">💬 ' + task.messages_count + '</span>';
            if (task.files_count > 0) html += '<span class="flat-task-badge">📎 ' + task.files_count + '</span>';
            if (hasSubtasks) html += '<span class="flat-task-badge clickable subtasks-toggle" data-task-uuid="' + task.uuid + '">📋 ' + task.subtasks_count + '</span>';
            if (parentContextBadge) html += parentContextBadge;
            if (unreadBadge) html += unreadBadge;
            html += '</div>';
            html += '<div class="task-files-container" id="' + filesContainerId + '"><span class="files-loading">⏳ Загрузка файлов...</span></div>';
            html += '</div>';
            html += '<div class="flat-task-actions" onclick="event.stopPropagation()">';
            if (task.can_edit) {
                html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="editTask(\'' + task.uuid + '\')" title="Редактировать">✏️</button>';
                html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="createSubtask(\'' + task.uuid + '\')" title="Создать подзадачу">➕</button>';
            }
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px" onclick="copyTaskLink(\'' + task.uuid + '\', \'' + escapeHtmlLocal(task.title).replace(/'/g, "\\'") + '\')" title="Скопировать ссылку на задачу">🔗</button>';
            html += '<button class="btn-secondary" style="padding:4px 8px;font-size:11px ' + subBtnClass + '" id="sub-btn-' + task.uuid + '" onclick="toggleTaskSubscription(\'' + task.uuid + '\', this)">' + subBtnText + '</button>';
            html += '<button class="btn-messages-icon" onclick="openTaskMessagesByUuid(\'' + task.uuid + '\')">💬</button>';

            if (task.files_count > 0) {
                html += '<a href="' + window.APP_BASE + '/files.php?task=' + task.uuid + '" class="btn-secondary" style="padding:4px 8px;font-size:11px; text-decoration: none;" target="_blank" title="Все файлы задачи">📎 Файлы</a>';
            }

            html += '</div>';
            html += '</div>';
            html += '</div>';

            if (hasSubtasks) {
                var expandedClass = shouldExpand ? ' expanded' : '';

                html += '<div class="subtasks-container' + expandedClass + '" id="children-' + task.uuid + '" data-loaded="false" data-page="1" data-has-more="false">';
                html += '<div class="subtasks-loading" style="display:none;">⏳ Загрузка подзадач...</div>';
                html += '<div class="subtasks-list"></div>';
                html += '<div class="subtasks-pagination" style="display:none;"></div>';
                html += '</div>';
            }
            html += '</div>';

            taskUuidsForFiles.push(task.uuid);
        }
        html += '</div>';
        container.innerHTML = html;

        var rows = container.querySelectorAll('.flat-task-row');
        for (var j = 0; j < rows.length; j++) {
            var parentLink = rows[j].closest('.flat-task-row-link');
            if (parentLink && parentLink.dataset.taskUrl) {
                rows[j].dataset.taskUrl = parentLink.dataset.taskUrl;
                rows[j].dataset.taskTitle = parentLink.dataset.taskTitle || 'задачи';
            }

            rows[j].addEventListener('click', function(e) {
                if (window.isSelectingText) {
                    e.stopPropagation();
                    return;
                }

                var selection = window.getSelection();
                if (selection && selection.toString().trim().length > 0) {
                    e.stopPropagation();
                    return;
                }

                if (e.target.closest('.flat-task-actions, .flat-task-checkbox, .btn-secondary, .btn-messages-icon, .subtasks-toggle, .task-file-item')) {
                    return;
                }

                if (e.target.closest('.task-description, .task-description-empty, .flat-task-title')) {
                    return;
                }

                var url = this.dataset.taskUrl;
                var title = this.dataset.taskTitle || 'задачи';
                if (url) {
                    navigator.clipboard.writeText(url).then(function() {
                        showAlertLocal('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                    }).catch(function() {
                        var textarea = document.createElement('textarea');
                        textarea.value = url;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        showAlertLocal('✓ Ссылка на задачу "' + title + '" скопирована', 'success');
                    });
                }
            });
        }

        if (taskUuidsForFiles.length > 0) {
            window.loadFilesForTasksBatch(taskUuidsForFiles);
        }
        window.restoreHighlightAfterRender();

        initCollapsibleDescriptions();
        window.attachSubtaskToggleHandlers();

        if (hasActiveFilters) {
            var containers = document.querySelectorAll('.subtasks-container');

            containers.forEach(function(container) {
                var parentUuid = container.id.replace('children-', '');
                if (parentUuid && container.getAttribute('data-loaded') !== 'true') {
                    var loadingDiv = container.querySelector('.subtasks-loading');
                    if (loadingDiv) loadingDiv.style.display = 'block';

                    window.loadSubtasks(parentUuid, container.id, 1, false).then(function() {
                        if (loadingDiv) loadingDiv.style.display = 'none';
                    }).catch(function(err) {
                        logErrorWrapper('[RENDER_TASK_LIST] Auto-load failed for parent:', parentUuid, err);
                        if (loadingDiv) loadingDiv.style.display = 'none';
                    });
                }
            });
        }
    };

    // ========== ПОДЗАДАЧИ: ОБРАБОТЧИКИ ==========

    window.attachSubtaskToggleHandlers = function() {
        var toggles = document.querySelectorAll('.subtasks-toggle');
        for (var i = 0; i < toggles.length; i++) {
            if (toggles[i]._subtaskHandler) {
                toggles[i].removeEventListener('click', toggles[i]._subtaskHandler);
                toggles[i].removeEventListener('touchstart', toggles[i]._subtaskHandler);
            }

            var handler = function(e) {
                e.stopPropagation();
                if (e.type === 'touchstart') {
                    e.preventDefault();
                }

                var taskUuid = this.dataset.taskUuid;
                if (!taskUuid) {
                    return;
                }

                var childrenDiv = document.getElementById('children-' + taskUuid);

                if (!childrenDiv) {
                    var self = this;
                    setTimeout(function() {
                        var retryDiv = document.getElementById('children-' + taskUuid);
                        if (retryDiv) {
                            self.click();
                        } else {
                            showAlertLocal('Ошибка: контейнер подзадач не найден', 'error');
                        }
                    }, 100);
                    return;
                }

                this.style.transform = 'scale(0.95)';
                setTimeout(function(el) {
                    if (el) el.style.transform = '';
                }, 150, this);

                if (childrenDiv.classList.contains('expanded')) {
                    childrenDiv.classList.remove('expanded');
                } else {
                    childrenDiv.classList.add('expanded');

                    if (childrenDiv.getAttribute('data-loaded') !== 'true') {
                        var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
                        if (loadingDiv) {
                            loadingDiv.style.display = 'block';
                        }
                        window.loadSubtasks(taskUuid, 'children-' + taskUuid, 1, false).then(function() {
                            if (loadingDiv) loadingDiv.style.display = 'none';
                        }).catch(function(err) {
                            logErrorWrapper('[SUBTASK_TOGGLE] Failed to load subtasks:', err);
                            if (loadingDiv) loadingDiv.style.display = 'none';
                        });
                    }
                }
            };

            toggles[i].addEventListener('click', handler);
            toggles[i].addEventListener('touchstart', handler);
            toggles[i]._subtaskHandler = handler;
        }
    };

    // ========== СБРОС КЭША ==========

    window.resetSubtasksCache = function() {
        var allContainers = document.querySelectorAll('.subtasks-container');
        for (var i = 0; i < allContainers.length; i++) {
            allContainers[i].setAttribute('data-loaded', 'false');
            allContainers[i].setAttribute('data-page', '1');
            var listDiv = allContainers[i].querySelector('.subtasks-list');
            if (listDiv) listDiv.innerHTML = '';
        }
        window.isLoadingSubtasks = {};
    };

    // ========== ВЫДЕЛЕНИЕ ТЕКСТА ==========

    document.addEventListener('mousedown', function(e) {
        var target = e.target;
        if (target.closest && (target.closest('.task-description') || target.closest('.task-description-empty') || target.closest('.flat-task-title'))) {
            window.isSelectingText = false;
            if (window.textSelectionTimeout) clearTimeout(window.textSelectionTimeout);
            window.textSelectionTimeout = setTimeout(function() {
                window.isSelectingText = true;
            }, 150);
        }
    });

    document.addEventListener('mouseup', function() {
        var selection = window.getSelection();
        var selectedText = selection.toString().trim();

        if (selectedText.length > 0) {
            window.isSelectingText = true;
            setTimeout(function() {
                window.isSelectingText = false;
            }, 300);
        } else if (window.textSelectionTimeout) {
            clearTimeout(window.textSelectionTimeout);
            window.isSelectingText = false;
        }
    });

    // ========== ВЫДЕЛЕНИЕ И ПОИСК ЗАДАЧИ ==========

    window.highlightAndScrollToTask = function(taskElement) {
        if (!taskElement) return;

        window.highlightedTaskUuid = taskElement.dataset.taskUuid;

        taskElement.classList.remove('highlight');
        void taskElement.offsetWidth;
        taskElement.classList.add('highlight');

        taskElement.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(function() {
            var currentElement = document.querySelector('.flat-task-item[data-task-uuid="' + window.highlightedTaskUuid + '"], .subtask-item[data-task-uuid="' + window.highlightedTaskUuid + '"]');
            if (currentElement) {
                currentElement.classList.remove('highlight');
            }
            window.highlightedTaskUuid = null;
        }, 30000);
    };

    window.restoreHighlightAfterRender = function() {
        if (window.highlightedTaskUuid) {
            var taskElement = document.querySelector('.flat-task-item[data-task-uuid="' + window.highlightedTaskUuid + '"], .subtask-item[data-task-uuid="' + window.highlightedTaskUuid + '"]');
            if (taskElement && !taskElement.classList.contains('highlight')) {
                taskElement.classList.add('highlight');
            }
        }
    };

    window.ensureTaskVisible = function(taskUuid, callback) {
        var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
        if (existingTask) {
            window.highlightAndScrollToTask(existingTask);
            if (callback) callback(true);
            return;
        }

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
                var parentChain = data.task.parent_chain || [];

                if (parentChain.length === 0) {
                    if (window.currentProjectUuid) {
                        window.loadProjectTasks(true);
                        setTimeout(function() {
                            window.findAndHighlightTask(taskUuid, 50, 0);
                        }, 1500);
                    }
                    if (callback) callback(true);
                    return;
                }

                if (typeof window.expandParentsChainInDom === 'function') {
                    window.expandParentsChainInDom(parentChain, taskUuid, function() {
                        setTimeout(function() {
                            window.findAndHighlightTask(taskUuid, 50, 0);
                            if (callback) callback(true);
                        }, 800);
                    });
                } else {
                    if (callback) callback(false);
                }
            } else {
                if (callback) callback(false);
            }
        })
        .catch(function(err) {
            logErrorWrapper('[ENSURE_VISIBLE] Error:', err);
            if (callback) callback(false);
        });
    };

    window.findAndHighlightTask = function(taskUuid, maxAttempts, attempt) {
        attempt = attempt || 0;
        maxAttempts = maxAttempts || 50;

        var taskElement = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');

        if (taskElement) {
            window.highlightAndScrollToTask(taskElement);
            return true;
        }

        if (attempt < maxAttempts) {
            var delay = Math.min(500, 100 + attempt * 30);
            setTimeout(function() {
                window.findAndHighlightTask(taskUuid, maxAttempts, attempt + 1);
            }, delay);
            return false;
        }

        return false;
    };

    window.expandParentsChainInDom = function(parentChain, targetTaskUuid, callback) {
        if (!parentChain || parentChain.length === 0) {
            if (callback) callback();
            return;
        }

        var index = 0;

        function expandNext() {
            if (index >= parentChain.length) {
                if (callback) callback();
                return;
            }

            var parentId = parentChain[index];

            var attempts = 0;
            var maxAttempts = 40;

            function waitForParent() {
                var parentElement = document.querySelector('.flat-task-item[data-task-uuid="' + parentId + '"], .subtask-item[data-task-uuid="' + parentId + '"]');

                if (parentElement) {
                    var childrenDiv = document.getElementById('children-' + parentId);
                    if (!childrenDiv) {
                        index++;
                        expandNext();
                        return;
                    }

                    if (!childrenDiv.classList.contains('expanded')) {
                        childrenDiv.classList.add('expanded');
                    }

                    if (childrenDiv.getAttribute('data-loaded') !== 'true') {
                        var loadingDiv = childrenDiv.querySelector('.subtasks-loading');
                        if (loadingDiv) loadingDiv.style.display = 'block';

                        window.loadSubtasks(parentId, 'children-' + parentId, 1, false).then(function() {
                            if (loadingDiv) loadingDiv.style.display = 'none';
                            index++;
                            setTimeout(expandNext, 400);
                        }).catch(function(err) {
                            logErrorWrapper('[EXPAND_CHAIN] Failed to load subtasks:', err);
                            if (loadingDiv) loadingDiv.style.display = 'none';
                            index++;
                            setTimeout(expandNext, 600);
                        });
                    } else {
                        index++;
                        setTimeout(expandNext, 300);
                    }
                } else {
                    attempts++;
                    if (attempts < maxAttempts) {
                        setTimeout(waitForParent, 200);
                    } else {
                        index++;
                        expandNext();
                    }
                }
            }

            waitForParent();
        }

        expandNext();
    };

    // ========== ПОИСК ПО СТРАНИЦАМ ==========

    window.searchTaskAcrossPages = function(targetUuid, startPage, maxPages) {
        var page = startPage;
        var totalPages = maxPages;

        function tryPage(p) {
            if (p > totalPages) {
                showAlertLocal('Задача не найдена. Возможно, она была удалена.', 'warning');
                return;
            }

            var formData = new URLSearchParams();
            formData.append('action', 'get_project_tasks_sorted');
            formData.append('project_uuid', window.currentProjectUuid);
            formData.append('page', p);
            formData.append('per_page', window.currentPerPage);
            formData.append('sort_by', window.currentSortBy);
            formData.append('sort_dir', window.currentSortDir);
            formData.append('filter_statuses', JSON.stringify(window.currentFilterStatuses));
            formData.append('filter_assigned', JSON.stringify(window.currentFilterAssigned));
            formData.append('search', window.currentSearch);
            formData.append('ajax_mode', '1');
            if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.tasks) {
                    var found = data.tasks.some(function(t) { return t.uuid === targetUuid; });

                    if (found) {
                        window.currentTaskPage = p;
                        window.loadProjectTasks(false);

                        setTimeout(function() {
                            window.findAndHighlightTask(targetUuid, 30, 0);
                        }, 800);
                    } else {
                        tryPage(p + 1);
                    }
                } else {
                    tryPage(p + 1);
                }
            })
            .catch(function(err) {
                logErrorWrapper('[SEARCH_PAGES] Error loading page', p, ':', err);
                tryPage(p + 1);
            });
        }

        tryPage(startPage);
    };

    // ========== ВЫБОР ЗАДАЧИ ПО UUID ==========

    window.selectTaskByUuid = function(taskUuid) {
        var existingTask = document.querySelector('.flat-task-item[data-task-uuid="' + taskUuid + '"], .subtask-item[data-task-uuid="' + taskUuid + '"]');
        if (existingTask) {
            window.highlightAndScrollToTask(existingTask);
            return;
        }

        window.ensureTaskVisible(taskUuid, function(success) {
            if (!success) {
                showAlertLocal('Не удалось найти задачу. Возможно, она была удалена или у вас нет доступа.', 'warning');
            }
        });
    };

    // ========== ВЫДЕЛЕНИЕ (appendNewMessages) ==========

    logDebugWrapper('[MESSAGES_TASKBAR] projects_logics.js v1.0.0 initialized');
})();