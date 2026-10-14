/**
 * messages_taskbar.js
 * Version: 2.4.0
 * Changelog:
 * - 2.4.0 (2026-10-14): FIX: восстановлен приоритет showToast в showAlert.
 *                       FIX: после сохранения задачи всегда выставляем view-режим
 *                       (чтобы «редактирование не останавливалось» — панель не залипала в edit).
 * - 2.3.0 (2026-10-14): FIX: saveTaskDetails — добавлен forceViewMode().
 * - 2.2.0 (2026-10-14): FIX: разделены scrollContainer и innerContainer при вставке сообщений.
 * - 2.1.0 (2026-10-14): FIX: локальный fallback для escapeHtmlStatic.
 * - 2.0.0 (2026-10-14): Added handleFileSelect, updateSelectedFiles, removeFile, copyFileLink.
 * - 1.0.0 (2026-10-14): Initial split from messages.php.
 */

(function() {
    var logDebug = window.logDebug || function() {};
    var logError = window.logError || function() {};

    // Локальный fallback
    var escapeHtmlStatic = window.escapeHtmlStatic || function(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    // Контейнеры
    function getScrollContainer() {
        if (typeof window.getScrollContainer === 'function') return window.getScrollContainer();
        return document.getElementById('messages-area');
    }
    function getInnerContainer() {
        if (typeof window.getInnerContainer === 'function') return window.getInnerContainer();
        var inner = document.getElementById('messages-area-inner');
        if (inner) return inner;
        var outer = document.getElementById('messages-area');
        if (!outer) return null;
        inner = document.createElement('div');
        inner.id = 'messages-area-inner';
        inner.className = 'messages-area-inner';
        while (outer.firstChild) inner.appendChild(outer.firstChild);
        outer.appendChild(inner);
        return inner;
    }

    // ==================== ПАНЕЛЬ ДЕТАЛЕЙ ЗАДАЧИ ====================

    function parseTaskDetailsLinks(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        var escaped = div.innerHTML;
        var urlRegex = /(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[a-zA-Z0-9\-._~:/?#\[\]@!$&'()*+,;=%]+/gi;
        escaped = escaped.replace(urlRegex, function(match) {
            var lowerMatch = match.toLowerCase();
            if (lowerMatch.indexOf('javascript:') === 0 || lowerMatch.indexOf('data:') === 0 || lowerMatch.indexOf('vbscript:') === 0) return match;
            var safeUrl = match.replace(/['"]/g, '').replace(/[<>]/g, '');
            var isTelegram = lowerMatch.indexOf('tg://') === 0 || lowerMatch.indexOf('telegram://') === 0;
            var linkClass = isTelegram ? 'external-link telegram-link' : 'external-link';
            var targetAttr = (lowerMatch.indexOf('mailto:') === 0 || lowerMatch.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
            var displayText = match;
            if (displayText.length > 80) displayText = displayText.substring(0, 70) + '…' + displayText.substring(displayText.length - 10);
            return '<a href="' + safeUrl + '" class="' + linkClass + '"' + targetAttr + '>' + displayText + '</a>';
        });
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }

    var taskDetailsPanel = {
        isOpen: false,
        currentTaskUuid: null,
        isLoading: false
    };

    function formatTaskDate(ts) {
        if (!ts || ts === null || ts === 0) return '';
        var d = new Date(parseInt(ts));
        if (isNaN(d.getTime())) return '';
        var tz = -d.getTimezoneOffset() / 60;
        var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
        return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}) + ' (' + tzName + ')';
    }

    function loadTaskDetails(taskUuid) {
        if (!taskUuid) return;
        if (taskDetailsPanel.isLoading) return;
        taskDetailsPanel.isLoading = true;
        taskDetailsPanel.currentTaskUuid = taskUuid;

        var bodyContainer = document.getElementById('taskDetailsBody');
        if (bodyContainer) bodyContainer.innerHTML = '<div class="task-details-loading">⏳ Загрузка информации о задаче...</div>';

        var formData = new URLSearchParams();
        formData.append('action', 'get_task_info');
        formData.append('task_uuid', taskUuid);
        formData.append('ajax_mode', '1');
        if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);

        fetch(window.APP_BASE + '/projects.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            taskDetailsPanel.isLoading = false;
            if (data.success && data.task) {
                window.currentTaskDetailsData = data.task;
                window.currentTaskDetailsProjectUuid = data.task.project_uuid;
                window.currentTaskDetailsParentUuid = data.task.parent_task_uuid || '';
                window.currentTaskDetailsUuid = data.task.uuid;
                var cleanTitle = data.task.title || '';
                cleanTitle = cleanTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
                if (!cleanTitle && data.task.title) cleanTitle = data.task.title;
                window.currentTaskDetailsCleanTitle = cleanTitle;
                var projectUuid = data.task.project_uuid;
                if (projectUuid && (!window.projectUsersList || window.projectUsersListProjectUuid !== projectUuid)) {
                    var usersFormData = new URLSearchParams();
                    usersFormData.append('action', 'get_project_users');
                    usersFormData.append('project_uuid', projectUuid);
                    usersFormData.append('ajax_mode', '1');
                    if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(usersFormData);
                    fetch(window.APP_BASE + '/projects.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: usersFormData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(usersData) {
                        if (usersData.success && usersData.users) {
                            window.projectUsersList = usersData.users;
                            window.projectUsersListProjectUuid = projectUuid;
                        } else {
                            window.projectUsersList = [];
                            window.projectUsersListProjectUuid = null;
                        }
                        renderTaskDetails(data.task);
                    })
                    .catch(function() {
                        window.projectUsersList = [];
                        window.projectUsersListProjectUuid = null;
                        renderTaskDetails(data.task);
                    });
                } else {
                    renderTaskDetails(data.task);
                }
            } else {
                if (bodyContainer) {
                    bodyContainer.innerHTML = '<div class="task-details-error">❌ ' + escapeHtmlStatic(data.error || 'Задача не найдена') + '</div>';
                }
            }
        })
        .catch(function(err) {
            taskDetailsPanel.isLoading = false;
            if (bodyContainer) {
                bodyContainer.innerHTML = '<div class="task-details-error">❌ Ошибка загрузки: ' + escapeHtmlStatic(err.message) + '</div>';
            }
        });
    }

    function utcToLocalDatetimeString(utcTimestamp) {
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

    function formatFileSize(bytes) {
        if (bytes === undefined || bytes === null) return '0 B';
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' B';
    }

    function getFileIconFromName(filename) {
        if (!filename) return '📎';
        var ext = filename.split('.').pop().toLowerCase();
        var icons = {'jpg':'🖼️','jpeg':'🖼️','png':'🖼️','gif':'🖼️','webp':'🖼️','pdf':'📄','doc':'📝','docx':'📝','xls':'📊','xlsx':'📊','zip':'📦','rar':'📦','7z':'📦','mp3':'🎵','mp4':'🎬','avi':'🎬','txt':'📃','md':'📃'};
        return icons[ext] || '📎';
    }

    function renderTaskDetails(task) {
        var statusText = (task.status === 1) ? '✅ Выполнена' : '🟢 Активна';
        var statusClass = (task.status === 1) ? 'completed' : '';
        var assigneeText = (task.assignee_name || task.assignee_login) ? escapeHtmlStatic(task.assignee_name || task.assignee_login) : 'Не назначен';

        var descrHtml = '';
        var descrText = task.descr || '';
        if (descrText.trim() !== '') {
            descrHtml = '<div class="task-details-description">' +
                (typeof window.parseMarkdownToHtml === 'function' ? window.parseMarkdownToHtml(descrText) : parseTaskDetailsLinks(descrText)) +
                '</div>';
        } else {
            descrHtml = '<div class="task-details-description-empty">Нет описания</div>';
        }

        var assigneeOptionsHtml = '<option value="">Не назначен</option>';
        if (window.projectUsersList && window.projectUsersList.length > 0) {
            for (var i = 0; i < window.projectUsersList.length; i++) {
                var user = window.projectUsersList[i];
                var selected = (user.uuid === task.assigned_to_uuid) ? ' selected' : '';
                var userName = user.name || user.login;
                assigneeOptionsHtml += '<option value="' + escapeHtmlStatic(user.uuid) + '"' + selected + '>' + escapeHtmlStatic(userName) + '</option>';
            }
        }

        var editFormHtml = '<div id="task-details-edit-form" style="display: none;">' +
            '<div class="form-group"><label>Описание</label><textarea id="task-details-descr" class="task-details-textarea" rows="5">' + escapeHtmlStatic(descrText) + '</textarea></div>' +
            '<div class="form-group"><label>Исполнитель</label><select id="task-details-assignee" class="task-details-input">' + assigneeOptionsHtml + '</select></div>' +
            '<div class="form-group"><label>Дата начала (в вашем часовом поясе)</label><input type="datetime-local" id="task-details-time-start" class="task-details-input" value="' + utcToLocalDatetimeString(task.time_start_utc) + '"></div>' +
            '<div class="form-group"><label>Плановое окончание (в вашем часовом поясе)</label><input type="datetime-local" id="task-details-time-end" class="task-details-input" value="' + utcToLocalDatetimeString(task.time_end_plan_utc) + '"></div>' +
            '<div class="file-manager" id="task-details-file-manager"><label>📎 Прикреплённые файлы</label><div class="file-list" id="task-details-files-list"></div>' +
            '<div class="upload-area" style="margin-top: 12px; display: flex; gap: 8px;"><input type="file" id="task-details-file-upload" accept="*/*" style="flex: 1;"><button type="button" class="btn-secondary" onclick="uploadTaskFileFromDetails()">📤 Загрузить</button></div></div></div>';

        var parentsHtml = '';
        var parentChain = task.parent_chain || [];
        var parentTasks = task.parent_tasks || {};
        if (parentChain.length > 0) {
            parentsHtml = '<div class="task-details-field"><div class="task-details-field-label">📁 Родительские задачи</div><div class="task-details-field-value">';
            for (var i = 0; i < parentChain.length; i++) {
                var parentUuid = parentChain[i];
                var parentData = parentTasks[parentUuid];
                var parentTitle = parentData ? parentData.title : 'Задача';
                var isCompleted = parentData ? (parentData.status === 1) : false;
                var completedClass = isCompleted ? ' style="text-decoration: line-through; opacity: 0.7;"' : '';
                var parentLink = window.location.origin + (window.APP_BASE || '') + '/projects.php?task=' + parentUuid;
                parentsHtml += '<div style="margin-bottom: 8px; padding-left: ' + (i * 20) + 'px;"><span style="color: #9bb7ff;">↳</span> ';
                parentsHtml += '<a href="' + parentLink + '" target="_blank" rel="noopener noreferrer" style="color: #e9eefc; text-decoration: none; border-bottom: 1px dashed #4f7cff;"' + completedClass + '>';
                parentsHtml += escapeHtmlStatic(parentTitle);
                parentsHtml += '</a></div>';
            }
            parentsHtml += '</div></div>';
        }

        var files = task.files || task.task_files || [];
        var filesViewHtml = '<div class="task-details-files-title">📎 Прикреплённые файлы (' + files.length + ')</div>';
        if (files.length > 0) {
            filesViewHtml += '<div class="task-details-files-list" id="task-details-files-view-list">';
            for (var i = 0; i < files.length; i++) {
                var file = files[i];
                var fileIcon = getFileIconFromName(file.name);
                var safeName = escapeHtmlStatic(file.name).replace(/'/g, "\\'");
                filesViewHtml += '<div class="task-details-file-item" onclick="showFilePreview(\'' +
                    escapeHtmlStatic(file.uuid) + '\', \'' + safeName + '\', ' + (file.size_bytes || 0) + ', \'' +
                    escapeHtmlStatic(file.mime || '') + '\')" title="' + safeName + '">';
                filesViewHtml += fileIcon + ' ' + escapeHtmlStatic(file.name) + ' (' + (file.size || formatFileSize(file.size_bytes || 0)) + ')';
                filesViewHtml += '</div>';
            }
            filesViewHtml += '</div>';
        } else {
            filesViewHtml += '<div class="task-details-description-empty">Нет прикреплённых файлов</div>';
        }

        var taskUrl = window.location.origin + (window.APP_BASE || '') + '/projects.php?task=' + task.uuid;
        var actionButtonsHtml = '<div class="task-details-actions" id="task-details-view-actions">' +
            '<button class="task-details-btn-primary" id="task-details-edit-btn">✏️ Редактировать</button>' +
            '<a href="' + taskUrl + '" class="task-details-btn-secondary" target="_blank" rel="noopener noreferrer">📋 Перейти к задаче</a></div>' +
            '<div class="task-details-actions" id="task-details-edit-actions" style="display: none;">' +
            '<button class="task-details-btn-primary" id="task-details-save-btn">💾 Сохранить</button>' +
            '<button class="task-details-btn-secondary" id="task-details-cancel-btn">❌ Отмена</button></div>';

        var html = '<div class="task-details-content">';
        if (parentsHtml) html += parentsHtml;
        html += '<div id="task-details-view-mode">';
        html += '<div class="task-details-field"><div class="task-details-field-label">📋 Статус</div><div class="task-details-field-value ' + statusClass + '">' + statusText + '</div></div>';
        html += '<div class="task-details-field"><div class="task-details-field-label">👤 Исполнитель</div><div class="task-details-field-value">' + assigneeText + '</div></div>';
        if (task.time_start) {
            html += '<div class="task-details-field"><div class="task-details-field-label">🚀 Дата начала</div><div class="task-details-field-value">' + formatTaskDate(task.time_start) + '</div></div>';
        }
        if (task.time_end_plan) {
            var isOverdue = false;
            if (task.status !== 1 && task.time_end_plan) isOverdue = parseInt(task.time_end_plan) < Date.now();
            var overdueClass = isOverdue ? ' style="color: #f87171;"' : '';
            html += '<div class="task-details-field"><div class="task-details-field-label">📅 Плановое окончание</div><div class="task-details-field-value"' + overdueClass + '>' + formatTaskDate(task.time_end_plan);
            if (isOverdue) html += ' ⚠️ Просрочено';
            html += '</div></div>';
        }
        html += '<div class="task-details-field"><div class="task-details-field-label">📝 Описание</div>' + descrHtml + '</div>';
        html += '<div class="task-details-field">' + filesViewHtml + '</div>';
        html += '</div>';
        html += '<div id="task-details-edit-mode" style="display: none;">' + editFormHtml + '</div>';
        html += actionButtonsHtml;
        html += '</div>';

        var bodyContainer = document.getElementById('taskDetailsBody');
        if (bodyContainer) {
            bodyContainer.innerHTML = html;
            renderTaskDetailsFilesList(files);
            var editBtn = document.getElementById('task-details-edit-btn');
            if (editBtn) editBtn.onclick = function() { enterTaskEditMode(task); };
            var saveBtn = document.getElementById('task-details-save-btn');
            if (saveBtn) saveBtn.onclick = function() { saveTaskDetails(task.uuid); };
            var cancelBtn = document.getElementById('task-details-cancel-btn');
            if (cancelBtn) cancelBtn.onclick = function() { cancelTaskEditMode(task); };
            var fileUploadInput = document.getElementById('task-details-file-upload');
            if (fileUploadInput) {
                fileUploadInput.onkeypress = function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); uploadTaskFileFromDetails(); }
                };
            }
        }
        if (window.innerWidth <= 768) {
            setTimeout(function() {
                var header = document.querySelector('.task-details-header');
                if (header && parseInt(window.getComputedStyle(header).paddingLeft) < 60) header.style.paddingLeft = '70px';
            }, 50);
        }
    }

    function renderTaskDetailsFilesList(files) {
        var container = document.getElementById('task-details-files-list');
        if (!container) return;
        if (!files || files.length === 0) {
            container.innerHTML = '<div class="task-details-description-empty" style="padding: 8px;">Нет прикреплённых файлов</div>';
            return;
        }
        var html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var fileIcon = getFileIconFromName(file.name);
            html += '<div class="task-details-file-item" style="display: flex; justify-content: space-between; align-items: center;">';
            html += '<div style="display: flex; align-items: center; gap: 8px; cursor: pointer;" onclick="showFilePreview(\'' +
                escapeHtmlStatic(file.uuid) + '\', \'' + escapeHtmlStatic(file.name).replace(/'/g, "\\'") + '\', ' +
                (file.size_bytes || 0) + ', \'' + escapeHtmlStatic(file.mime || '') + '\')">';
            html += fileIcon + ' ' + escapeHtmlStatic(file.name) + ' (' + (file.size || formatFileSize(file.size_bytes || 0)) + ')';
            html += '</div>';
            html += '<button class="btn-danger" style="padding: 4px 8px; font-size: 11px;" onclick="detachTaskFileFromDetails(\'' + escapeHtmlStatic(file.uuid) + '\')">🗑️</button>';
            html += '</div>';
        }
        html += '</div>';
        container.innerHTML = html;
    }

    function enterTaskEditMode(task) {
        window.currentTaskDetailsParentUuid = task.parent_task_uuid || '';
        var viewMode = document.getElementById('task-details-view-mode');
        var editMode = document.getElementById('task-details-edit-mode');
        var editForm = document.getElementById('task-details-edit-form');
        var viewActions = document.getElementById('task-details-view-actions');
        var editActions = document.getElementById('task-details-edit-actions');
        if (viewMode) viewMode.style.display = 'none';
        if (editMode) editMode.style.display = 'block';
        if (editForm) editForm.style.display = 'block';
        if (viewActions) viewActions.style.display = 'none';
        if (editActions) editActions.style.display = 'flex';
        var descrField = document.getElementById('task-details-descr');
        var assigneeField = document.getElementById('task-details-assignee');
        var timeStartField = document.getElementById('task-details-time-start');
        var timeEndField = document.getElementById('task-details-time-end');
        if (descrField) descrField.value = task.descr || '';
        if (assigneeField) assigneeField.value = task.assigned_to_uuid || '';
        if (timeStartField) timeStartField.value = utcToLocalDatetimeString(task.time_start_utc);
        if (timeEndField) timeEndField.value = utcToLocalDatetimeString(task.time_end_plan_utc);
        renderTaskDetailsFilesList(task.files || task.task_files || []);
    }

    /**
     * Принудительно переключает панель в view-режим (защита от «залипания» в edit)
     */
    function forceTaskViewMode() {
        var viewMode = document.getElementById('task-details-view-mode');
        var editMode = document.getElementById('task-details-edit-mode');
        var editForm = document.getElementById('task-details-edit-form');
        var viewActions = document.getElementById('task-details-view-actions');
        var editActions = document.getElementById('task-details-edit-actions');
        if (viewMode) viewMode.style.display = 'block';
        if (editMode) editMode.style.display = 'none';
        if (editForm) editForm.style.display = 'none';
        if (viewActions) viewActions.style.display = 'flex';
        if (editActions) editActions.style.display = 'none';
    }

    function cancelTaskEditMode(originalTask) {
        forceTaskViewMode();
        if (originalTask) renderTaskDetails(originalTask);
        else forceTaskViewMode();
    }

    function saveTaskDetails(taskUuid) {
        if (!taskUuid) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка: задача не определена', 'error');
            return;
        }
        var newDescr = document.getElementById('task-details-descr') ? document.getElementById('task-details-descr').value : '';
        var newAssigneeUuid = document.getElementById('task-details-assignee') ? document.getElementById('task-details-assignee').value : '';
        var localStart = document.getElementById('task-details-time-start') ? document.getElementById('task-details-time-start').value : '';
        var localEnd = document.getElementById('task-details-time-end') ? document.getElementById('task-details-time-end').value : '';
        var parentTaskUuid = window.currentTaskDetailsParentUuid || '';
        if (!parentTaskUuid && window.currentTaskDetailsData) parentTaskUuid = window.currentTaskDetailsData.parent_task_uuid || '';
        var taskTitle = window.currentTaskDetailsCleanTitle || '';
        if (!taskTitle && window.currentTaskDetailsData) taskTitle = window.currentTaskDetailsData.title || '';
        if (!taskTitle) {
            var titleHeader = document.getElementById('taskDetailsTitle');
            if (titleHeader) {
                var domTitle = titleHeader.innerText.replace(/^📋\s*/, '').trim();
                taskTitle = domTitle.replace(/^[└─•]+\s*/, '').trim().replace(/^└─/, '').trim().replace(/^•/, '').trim();
            }
        }

        var saveBtn = document.getElementById('task-details-save-btn');
        var originalBtnText = saveBtn ? saveBtn.innerHTML : '💾 Сохранить';
        if (saveBtn) { saveBtn.innerHTML = '⏳ Сохранение...'; saveBtn.disabled = true; }

        var formData = new URLSearchParams();
        formData.append('action', 'edit_task');
        formData.append('uuid', taskUuid);
        formData.append('project_uuid', window.currentTaskDetailsProjectUuid || '');
        formData.append('parent_task_uuid', parentTaskUuid);
        formData.append('title', taskTitle);
        formData.append('descr', newDescr);
        formData.append('assigned_to_uuid', newAssigneeUuid);
        formData.append('time_start', localStart);
        formData.append('time_end_plan', localEnd);
        formData.append('ajax_mode', '1');
        if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);

        fetch(window.APP_BASE + '/projects.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (saveBtn) { saveBtn.innerHTML = originalBtnText; saveBtn.disabled = false; }
            if (data.success) {
                if (typeof window.showAlert === 'function') window.showAlert('Задача обновлена', 'success');
                if (data.task) {
                    window.currentTaskDetailsData = data.task;
                    window.currentTaskDetailsProjectUuid = data.task.project_uuid;
                    window.currentTaskDetailsParentUuid = data.task.parent_task_uuid || '';
                    var savedTitle = (data.task.title || taskTitle);
                    savedTitle = savedTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
                    savedTitle = savedTitle.replace(/^[└─•]+\s*/, '').trim();
                    savedTitle = savedTitle.replace(/^└─/, '').trim();
                    savedTitle = savedTitle.replace(/^•/, '').trim();
                    window.currentTaskDetailsCleanTitle = savedTitle;
                    renderTaskDetails(data.task);
                } else {
                    loadTaskDetails(taskUuid);
                }
                // FIX: принудительно выставляем view-режим ПОСЛЕ рендера
                forceTaskViewMode();
                if (typeof window.refreshTaskInList === 'function') window.refreshTaskInList(taskUuid);
                if (window.currentTaskUuid === taskUuid) {
                    var chatTitle = document.getElementById('chat-title');
                    if (chatTitle && (window.currentTaskDetailsCleanTitle || taskTitle)) {
                        chatTitle.textContent = window.currentTaskDetailsCleanTitle || taskTitle;
                    }
                }
            } else {
                if (typeof window.showAlert === 'function') window.showAlert('Ошибка сохранения: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(function(err) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка сети при сохранении', 'error');
            if (saveBtn) { saveBtn.innerHTML = originalBtnText; saveBtn.disabled = false; }
        });
    }

    function uploadTaskFileFromDetails() {
        var taskUuid = window.currentTaskDetailsUuid;
        if (!taskUuid) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка: задача не определена', 'error');
            return;
        }
        var fileInput = document.getElementById('task-details-file-upload');
        var file = fileInput ? fileInput.files[0] : null;
        if (!file) {
            if (typeof window.showAlert === 'function') window.showAlert('Выберите файл', 'warning');
            return;
        }
        if (typeof window.showAlert === 'function') window.showAlert('⏳ Загрузка файла...', 'info');
        var formData = new FormData();
        formData.append('action', 'upload_task_file');
        formData.append('task_uuid', taskUuid);
        formData.append('ajax_mode', '1');
        formData.append('file', file);
        if (typeof window.addCsrfToFormData === 'function') window.addCsrfToFormData(formData);

        fetch(window.APP_BASE + '/projects.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof window.showAlert === 'function') window.showAlert('✓ Файл загружен', 'success');
                fileInput.value = '';
                if (window.currentTaskDetailsUuid) loadTaskDetails(window.currentTaskDetailsUuid);
                if (typeof window.refreshTaskInList === 'function') window.refreshTaskInList(taskUuid);
            } else {
                if (typeof window.showAlert === 'function') window.showAlert('Ошибка загрузки: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(function(err) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка сети при загрузке файла', 'error');
        });
    }

    function detachTaskFileFromDetails(fileUuid) {
        var taskUuid = window.currentTaskDetailsUuid;
        if (!taskUuid) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка: задача не определена', 'error');
            return;
        }
        if (!confirm('Удалить этот файл из задачи? Он будет полностью удалён с сервера.')) return;
        if (typeof window.showAlert === 'function') window.showAlert('🗑️ Удаление файла...', 'info');
        var formData = new URLSearchParams();
        formData.append('action', 'detach_file_from_task');
        formData.append('task_uuid', taskUuid);
        formData.append('file_uuid', fileUuid);
        formData.append('ajax_mode', '1');
        if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);

        fetch(window.APP_BASE + '/projects.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof window.showAlert === 'function') window.showAlert('✓ Файл удалён', 'success');
                if (window.currentTaskDetailsUuid) loadTaskDetails(window.currentTaskDetailsUuid);
                if (typeof window.refreshTaskInList === 'function') window.refreshTaskInList(taskUuid);
            } else {
                if (typeof window.showAlert === 'function') window.showAlert('Ошибка удаления: ' + (data.error || 'Неизвестная ошибка'), 'error');
            }
        })
        .catch(function(err) {
            if (typeof window.showAlert === 'function') window.showAlert('Ошибка сети при удалении файла', 'error');
        });
    }

    function openTaskDetailsPanel(taskUuid, taskTitle) {
        if (!taskUuid) return;
        var panel = document.getElementById('taskDetailsPanel');
        var overlay = document.getElementById('taskDetailsOverlay');
        var titleElement = document.getElementById('taskDetailsTitle');
        if (!panel || !overlay) return;
        var cleanTitle = (taskTitle || 'Информация о задаче');
        cleanTitle = cleanTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
        cleanTitle = cleanTitle.replace(/^[└─•]+\s*/, '').trim();
        cleanTitle = cleanTitle.replace(/^└─/, '').trim();
        cleanTitle = cleanTitle.replace(/^•/, '').trim();
        if (titleElement) titleElement.innerHTML = '📋 ' + cleanTitle;
        window.currentTaskDetailsUuid = taskUuid;
        window.currentTaskDetailsData = null;
        window.currentTaskDetailsProjectUuid = null;
        window.currentTaskDetailsParentUuid = null;
        window.currentTaskDetailsCleanTitle = cleanTitle;
        loadTaskDetails(taskUuid);
        panel.classList.add('open');
        overlay.classList.add('open');
        taskDetailsPanel.isOpen = true;
        if (window.innerWidth <= 768) {
            document.body.style.overflow = 'hidden';
            setTimeout(function() {
                var header = document.querySelector('.task-details-header');
                if (header && parseInt(window.getComputedStyle(header).paddingLeft) < 60) header.style.paddingLeft = '70px';
            }, 50);
            setTimeout(fixMobileTaskButtonsPosition, 100);
            setTimeout(fixMobileTaskButtonsPosition, 500);
            setTimeout(fixMobileTaskButtonsPosition, 1000);
        }
    }

    function fixMobileTaskButtonsPosition() {
        if (window.innerWidth > 768) return;
        var panel = document.getElementById('taskDetailsPanel');
        if (!panel || !panel.classList.contains('open')) return;
        var actions = document.querySelector('.task-details-actions');
        var body = document.querySelector('.task-details-body');
        if (!actions) return;
        var safeAreaBottom = 0;
        try {
            var testDiv = document.createElement('div');
            testDiv.style.position = 'fixed';
            testDiv.style.bottom = 'env(safe-area-inset-bottom)';
            testDiv.style.visibility = 'hidden';
            document.body.appendChild(testDiv);
            var safeBottomValue = getComputedStyle(testDiv).bottom;
            if (safeBottomValue && safeBottomValue !== '0px') safeAreaBottom = parseInt(safeBottomValue) || 0;
            testDiv.remove();
        } catch(e) {}
        if (safeAreaBottom === 0 && window.innerHeight > window.screen.height - 20) safeAreaBottom = 34;
        var actionsHeight = actions.offsetHeight;
        actions.style.position = 'fixed';
        actions.style.bottom = safeAreaBottom + 'px';
        actions.style.left = '0';
        actions.style.right = '0';
        actions.style.width = '100%';
        actions.style.zIndex = '100000';
        actions.style.background = '#1a1a2e';
        actions.style.padding = '12px 16px';
        actions.style.paddingBottom = (12 + safeAreaBottom) + 'px';
        actions.style.margin = '0';
        actions.style.borderTop = '2px solid #4f7cff';
        actions.style.boxShadow = '0 -4px 15px rgba(0,0,0,0.3)';
        actions.style.borderRadius = '0';
        if (body) {
            body.style.paddingBottom = (actionsHeight + safeAreaBottom + 20) + 'px';
            body.style.marginBottom = '0';
        }
    }

    function closeTaskDetailsPanel() {
        var panel = document.getElementById('taskDetailsPanel');
        var overlay = document.getElementById('taskDetailsOverlay');
        if (panel) panel.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
        taskDetailsPanel.isOpen = false;
        if (window.innerWidth <= 768) {
            document.body.style.overflow = '';
            var actions = document.querySelector('.task-details-actions');
            var body = document.querySelector('.task-details-body');
            if (actions) {
                actions.style.position = '';
                actions.style.bottom = '';
                actions.style.left = '';
                actions.style.right = '';
                actions.style.width = '';
                actions.style.zIndex = '';
                actions.style.background = '';
                actions.style.padding = '';
                actions.style.margin = '';
                actions.style.borderTop = '';
                actions.style.boxShadow = '';
                actions.style.borderRadius = '';
            }
            if (body) {
                body.style.paddingBottom = '';
                body.style.marginBottom = '';
            }
        }
    }

    function setupTaskTitleClickHandler() {
        var chatTitle = document.getElementById('chat-title');
        if (!chatTitle) { setTimeout(setupTaskTitleClickHandler, 500); return; }
        chatTitle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var taskUuid = window.currentTaskUuid;
            var rawTaskTitle = this.textContent || 'Задача';
            var cleanTaskTitle = rawTaskTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
            if (taskUuid) {
                openTaskDetailsPanel(taskUuid, cleanTaskTitle);
            } else {
                var originalHref = this.getAttribute('href');
                if (originalHref && originalHref !== '#') window.location.href = originalHref;
            }
        });
        chatTitle.style.cursor = 'pointer';
        chatTitle.style.borderBottom = '1px dashed #70a0ff';
    }

    function setupTaskDetailsCloseHandler() {
        var closeBtn = document.getElementById('taskDetailsCloseBtn');
        var overlay = document.getElementById('taskDetailsOverlay');
        if (closeBtn) closeBtn.addEventListener('click', function() { closeTaskDetailsPanel(); });
        if (overlay) overlay.addEventListener('click', function() { closeTaskDetailsPanel(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && taskDetailsPanel.isOpen) closeTaskDetailsPanel();
        });
    }

    function initTaskDetailsPanel() {
        setupTaskTitleClickHandler();
        setupTaskDetailsCloseHandler();
        var header = document.querySelector('.task-details-header');
        if (header) {
            var computedPadding = window.getComputedStyle(header).paddingLeft;
            if (parseInt(computedPadding) < 60) header.style.paddingLeft = '70px';
        }
    }

    // ==================== СООБЩЕНИЯ ====================

    var pendingUploadData = null;

    function sendMessage(e) {
        e.preventDefault();
        if (window.isSending) return;
        var task = document.getElementById('current-task-uuid').value;
        var text = document.getElementById('message-input').value.trim();
        var replyToUuid = document.getElementById('reply-to-uuid').value;
        var hasFiles = window.selectedFiles.length > 0;
        if (!text && !hasFiles) {
            if (typeof window.showToast === 'function') window.showToast('Введите текст или прикрепите файл', 'warning');
            return;
        }
        if (!task) {
            if (typeof window.showToast === 'function') window.showToast('Сначала выберите задачу', 'warning');
            return;
        }
        window.isSending = true;
        var btn = document.getElementById('send-btn');
        if (btn) btn.classList.add('loading');
        var fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('task_uuid', task);
        fd.append('text', text);
        if (replyToUuid) fd.append('reply_to_uuid', replyToUuid);
        if (typeof window.addCsrfToFormData === 'function') window.addCsrfToFormData(fd);
        window.selectedFiles.forEach(function(f){ fd.append('files[]', f); });
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php');
        xhr.timeout = 60000;
        xhr.onload = function() {
            window.isSending = false;
            if (btn) btn.classList.remove('loading');
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.needs_confirmation && d.security_details) {
                        showSecurityWarningModal(d.security_details, d.security_message, window.selectedFiles, task, text, replyToUuid);
                        return;
                    }
                    if (d.success && d.message) {
                        document.getElementById('message-input').value = '';
                        window.selectedFiles = [];
                        if (typeof window.updateSelectedFiles === 'function') window.updateSelectedFiles();
                        document.getElementById('reply-to-uuid').value = '';
                        var replyIndicator = document.getElementById('reply-indicator');
                        if (replyIndicator) replyIndicator.style.display = 'none';
                        var innerContainer = getInnerContainer();
                        var scrollContainer = getScrollContainer();
                        if (innerContainer) {
                            var emptyState = innerContainer.querySelector('.empty-state');
                            if (emptyState) emptyState.remove();
                            var existingMsg = innerContainer.querySelector('.message[data-uuid="' + d.message.uuid + '"]');
                            if (!existingMsg && typeof window.renderMessage === 'function') {
                                innerContainer.insertAdjacentHTML('beforeend', window.renderMessage(d.message));
                            }
                        }
                        if (scrollContainer) {
                            setTimeout(function() { if (!window.userIsScrolling) scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 100);
                            setTimeout(function() { if (!window.userIsScrolling) scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 500);
                            setTimeout(function() { if (!window.userIsScrolling) scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 1500);
                        }
                        if (typeof window.loadLazyPreviews === 'function') setTimeout(window.loadLazyPreviews, 2000);
                        if (typeof window.refreshPaginationAfterMutation === 'function') {
                            setTimeout(function() { window.refreshPaginationAfterMutation(); }, 500);
                        }
                        var currentProjectUuid = null;
                        if (typeof window.getCurrentProjectUuid === 'function') currentProjectUuid = window.getCurrentProjectUuid();
                        if (currentProjectUuid && typeof window.refreshSidebarForProject === 'function') {
                            setTimeout(function() { window.refreshSidebarForProject(currentProjectUuid, function(success) {}); }, 1000);
                        }
                        if (d.message.time > window.lastMessageTime) window.lastMessageTime = d.message.time;
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function() {
            window.isSending = false;
            if (btn) btn.classList.remove('loading');
            if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error');
        };
        xhr.send(fd);
    }

    function showSecurityWarningModal(securityDetails, securityMessage, filesToUpload, taskUuid, text, replyToUuid) {
        var modal = document.getElementById('securityWarningModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'securityWarningModal';
            modal.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:20000; display:none; align-items:center; justify-content:center;';
            modal.innerHTML = '<div style="background:#1e1e2f; border-radius:16px; max-width:500px; width:90%; max-height:80vh; overflow:auto; border:1px solid #f59e0b; box-shadow:0 8px 30px rgba(0,0,0,0.5);"><div style="padding:20px; border-bottom:1px solid rgba(255,255,255,0.1); background:#f59e0b20;"><h3 style="margin:0; color:#f59e0b;">⚠️ Внимание! Обнаружены потенциально опасные элементы</h3></div><div style="padding:20px;" id="securityWarningContent"></div><div style="padding:16px 20px; border-top:1px solid rgba(255,255,255,0.1); display:flex; gap:12px; justify-content:flex-end;"><button id="securityCancelBtn" style="padding:10px 20px; border-radius:8px; background:rgba(255,255,255,0.1); border:none; color:#fff; cursor:pointer;">❌ Отмена</button><button id="securityConfirmBtn" style="padding:10px 20px; border-radius:8px; background:#f59e0b; border:none; color:#fff; cursor:pointer; font-weight:600;">⚠️ Всё равно отправить</button></div></div>';
            document.body.appendChild(modal);
        }
        var contentDiv = document.getElementById('securityWarningContent');
        var html = '<p style="margin-bottom:16px; color:rgba(255,255,255,0.8);">' + escapeHtmlStatic(securityMessage) + '</p>';
        html += '<div style="background:#2a2a3a; border-radius:8px; padding:12px; margin-bottom:16px;"><strong style="color:#f59e0b;">📋 Детали проверки:</strong><br><br>';
        for (var i = 0; i < securityDetails.length; i++) {
            var file = securityDetails[i];
            html += '<div style="margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid rgba(255,255,255,0.1);"><strong style="color:#ffd700;">📎 ' + escapeHtmlStatic(file.filename) + '</strong><br><span style="font-size:12px; color:rgba(255,255,255,0.7);">Обнаружено:</span><br><ul style="margin:8px 0 0 20px; color:#f87171;">';
            for (var j = 0; j < file.issues.length; j++) html += '<li style="margin-bottom:4px;">⚠️ ' + escapeHtmlStatic(file.issues[j]) + '</li>';
            html += '</ul></div>';
        }
        html += '<p style="margin-top:12px; font-size:12px; color:rgba(255,255,255,0.6); background:#2a2a3a; padding:8px; border-radius:6px;"><strong>💡 Что делать?</strong><br>Если вы уверены, что файл безопасен, нажмите «Всё равно отправить».<br>Если сомневаетесь — нажмите «Отмена» и проверьте файл.</p></div>';
        contentDiv.innerHTML = html;
        pendingUploadData = { task_uuid: taskUuid, text: text, reply_to_uuid: replyToUuid, files: filesToUpload };
        var confirmBtn = document.getElementById('securityConfirmBtn');
        var cancelBtn = document.getElementById('securityCancelBtn');
        var newConfirmBtn = confirmBtn.cloneNode(true);
        var newCancelBtn = cancelBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
        newConfirmBtn.onclick = function() {
            modal.style.display = 'none';
            if (pendingUploadData) { sendIgnoredSecurityUpload(pendingUploadData); pendingUploadData = null; }
        };
        newCancelBtn.onclick = function() {
            modal.style.display = 'none';
            pendingUploadData = null;
            window.isSending = false;
            var btn = document.getElementById('send-btn');
            if (btn) btn.classList.remove('loading');
            if (typeof window.showToast === 'function') window.showToast('Отправка отменена', 'warning');
        };
        modal.style.display = 'flex';
    }

    function sendIgnoredSecurityUpload(data) {
        var fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('task_uuid', data.task_uuid);
        fd.append('text', data.text);
        if (data.reply_to_uuid) fd.append('reply_to_uuid', data.reply_to_uuid);
        fd.append('ignore_security', '1');
        for (var i = 0; i < data.files.length; i++) fd.append('files[]', data.files[i]);
        if (typeof window.addCsrfToFormData === 'function') window.addCsrfToFormData(fd);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php');
        xhr.timeout = 60000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success && d.message) {
                        document.getElementById('message-input').value = '';
                        window.selectedFiles = [];
                        if (typeof window.updateSelectedFiles === 'function') window.updateSelectedFiles();
                        document.getElementById('reply-to-uuid').value = '';
                        var replyIndicator = document.getElementById('reply-indicator');
                        if (replyIndicator) replyIndicator.style.display = 'none';
                        var innerContainer = getInnerContainer();
                        var scrollContainer = getScrollContainer();
                        if (innerContainer) {
                            var emptyState = innerContainer.querySelector('.empty-state');
                            if (emptyState) emptyState.remove();
                            var existingMsg = innerContainer.querySelector('.message[data-uuid="' + d.message.uuid + '"]');
                            if (!existingMsg && typeof window.renderMessage === 'function') {
                                innerContainer.insertAdjacentHTML('beforeend', window.renderMessage(d.message));
                            }
                        }
                        if (scrollContainer) {
                            var distToBottom = scrollContainer.scrollHeight - scrollContainer.scrollTop - scrollContainer.clientHeight;
                            if (distToBottom < 500 || !window.userIsScrolling) {
                                setTimeout(function() { scrollContainer.scrollTop = scrollContainer.scrollHeight; }, 100);
                            }
                        }
                        if (typeof window.loadLazyPreviews === 'function') setTimeout(window.loadLazyPreviews, 100);
                        if (d.message.time > window.lastMessageTime) window.lastMessageTime = d.message.time;
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
            window.isSending = false;
            var btn = document.getElementById('send-btn');
            if (btn) btn.classList.remove('loading');
        };
        xhr.onerror = function() {
            window.isSending = false;
            var btn = document.getElementById('send-btn');
            if (btn) btn.classList.remove('loading');
            if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error');
        };
        xhr.send(fd);
    }

    function editCurrentMessage() {
        var messageUuid = window._currentMessageUuid;
        var isOwn = window._currentMessageOwn;
        if (!messageUuid || !isOwn) {
            if (typeof window.showToast === 'function') window.showToast('Нельзя редактировать чужие сообщения', 'error');
            closeMessageMenu();
            return;
        }
        closeMessageMenu();
        if (typeof window.closeFileMenu === 'function') window.closeFileMenu();
        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
        if (!msgElement) {
            if (typeof window.showToast === 'function') window.showToast('Сообщение не найдено', 'error');
            return;
        }
        var encodedText = msgElement.getAttribute('data-original-text') || '';
        var originalText = '';
        try { originalText = decodeURIComponent(encodedText); }
        catch(e) {
            var tempTextarea = document.createElement('textarea');
            tempTextarea.innerHTML = encodedText;
            originalText = tempTextarea.value;
        }
        if (!originalText) {
            var textDiv = msgElement.querySelector('.message-text');
            if (textDiv) {
                var clone = textDiv.cloneNode(true);
                var quotes = clone.querySelectorAll('.message-quote');
                quotes.forEach(function(quote) { quote.remove(); });
                originalText = clone.textContent || clone.innerText || '';
                originalText = originalText.trim();
            }
        }
        var replyToUuid = msgElement.getAttribute('data-reply-uuid') || '';
        window._editMessageContext = { messageUuid: messageUuid, originalText: originalText, replyToUuid: replyToUuid, msgElement: msgElement };
        var modal = document.getElementById('editMessageModal');
        var textarea = document.getElementById('editMessageTextarea');
        var saveBtn = document.getElementById('editMessageSaveBtn');
        var cancelBtn = document.getElementById('editMessageCancelBtn');
        var closeBtn = document.getElementById('editMessageModalCloseBtn');
        var warningDiv = document.getElementById('editMessageWarning');
        var replyInfoDiv = document.getElementById('editMessageReplyInfo');
        var replyQuoteDiv = document.getElementById('editMessageReplyQuote');
        if (!modal || !textarea) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: не удалось открыть редактор', 'error');
            return;
        }
        textarea.value = originalText;
        if (replyToUuid) {
            var replyText = '';
            var quoteElement = msgElement.querySelector('.message-quote');
            if (quoteElement) {
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = quoteElement.innerHTML;
                replyText = tempDiv.textContent || tempDiv.innerText || '';
                replyText = replyText.replace(/📎 Цитата:/g, '').trim();
                if (replyText.length > 300) replyText = replyText.substring(0, 300) + '…';
            }
            if (replyText) { replyQuoteDiv.textContent = replyText; replyInfoDiv.style.display = 'block'; }
            else replyInfoDiv.style.display = 'none';
        } else replyInfoDiv.style.display = 'none';
        warningDiv.style.display = 'none';
        var scrollContainer = getScrollContainer();
        var savedScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;
        var wasAtBottom = scrollContainer ? (scrollContainer.scrollHeight - scrollContainer.scrollTop - scrollContainer.clientHeight) < 100 : false;
        window._editScrollPosition = savedScrollTop;
        window._editWasAtBottom = wasAtBottom;
        modal.style.display = 'flex';
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        function handleSave() {
            var newText = textarea.value.trim();
            if (!newText) { warningDiv.style.display = 'block'; return; }
            warningDiv.style.display = 'none';
            saveBtn.disabled = true;
            cancelBtn.disabled = true;
            if (closeBtn) closeBtn.disabled = true;
            saveBtn.textContent = '⏳ Сохранение...';
            var formData = new URLSearchParams();
            formData.append('action', 'edit_message');
            formData.append('message_uuid', messageUuid);
            formData.append('text', newText);
            if (replyToUuid) formData.append('reply_to_uuid', replyToUuid);
            if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.APP_BASE + '/messages.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.timeout = 30000;
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var d = JSON.parse(xhr.responseText);
                        if (d.success && d.message) {
                            modal.style.display = 'none';
                            if (typeof window.smartUpdateMessage === 'function') window.smartUpdateMessage(d.message);
                            else if (typeof window.refreshPaginationAfterMutation === 'function') window.refreshPaginationAfterMutation();
                            setTimeout(function() {
                                var containerElem = getScrollContainer();
                                if (containerElem) {
                                    if (window._editWasAtBottom) containerElem.scrollTop = containerElem.scrollHeight;
                                    else if (window._editScrollPosition > 0) containerElem.scrollTop = window._editScrollPosition;
                                }
                                if (typeof window.restoreCachedImagesToDOM === 'function') window.restoreCachedImagesToDOM();
                            }, 100);
                            if (typeof window.showToast === 'function') window.showToast('✓ Сообщение изменено', 'success');
                        } else {
                            if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                        }
                    } catch(e) {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа сервера', 'error');
                    }
                } else {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
                }
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                if (closeBtn) closeBtn.disabled = false;
                saveBtn.textContent = '💾 Сохранить';
            };
            xhr.onerror = function() {
                if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error');
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                if (closeBtn) closeBtn.disabled = false;
                saveBtn.textContent = '💾 Сохранить';
            };
            xhr.ontimeout = function() {
                if (typeof window.showToast === 'function') window.showToast('Превышено время ожидания', 'error');
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                if (closeBtn) closeBtn.disabled = false;
                saveBtn.textContent = '💾 Сохранить';
            };
            xhr.send(formData);
        }

        function handleCancel() {
            modal.style.display = 'none';
            window._editMessageContext = null;
        }

        function handleModalKeydown(e) {
            if (e.key === 'Escape') { e.preventDefault(); handleCancel(); }
        }

        var newSaveBtn = saveBtn.cloneNode(true);
        var newCancelBtn = cancelBtn.cloneNode(true);
        var newCloseBtn = closeBtn ? closeBtn.cloneNode(true) : null;
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
        if (closeBtn && newCloseBtn) closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
        newSaveBtn.onclick = function(e) { e.stopPropagation(); handleSave(); };
        newCancelBtn.onclick = function(e) { e.stopPropagation(); handleCancel(); };
        if (newCloseBtn) newCloseBtn.onclick = function(e) { e.stopPropagation(); handleCancel(); };
        var overlayEl = modal.querySelector('.edit-message-modal-overlay');
        if (overlayEl) overlayEl.onclick = function(e) { e.stopPropagation(); handleCancel(); };
        document.removeEventListener('keydown', window._editModalKeydownHandler);
        window._editModalKeydownHandler = handleModalKeydown;
        document.addEventListener('keydown', window._editModalKeydownHandler);
        textarea.onkeydown = function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSave(); }
            if (e.key === 'Escape') { e.preventDefault(); handleCancel(); }
        };
    }

    function deleteMessage(messageUuid) {
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) messageUuid = window._fileMenuContext.messageUuid;
        }
        if (!messageUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: сообщение не найдено', 'error');
            return;
        }
        if (!confirm('Удалить сообщение?')) return;
        var formData = new URLSearchParams();
        formData.append('action', 'delete_message');
        formData.append('message_uuid', messageUuid);
        if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success) {
                        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                        if (msgElement) {
                            msgElement.style.transition = 'opacity 0.3s ease';
                            msgElement.style.opacity = '0';
                            setTimeout(function() {
                                if (msgElement && msgElement.parentNode) {
                                    msgElement.remove();
                                    var innerContainer = getInnerContainer();
                                    if (innerContainer && !innerContainer.querySelector('.message')) {
                                        innerContainer.innerHTML = '<div class="empty-state">Нет сообщений</div>';
                                    }
                                    if (typeof window.updateUnreadBadge === 'function') window.updateUnreadBadge();
                                    setTimeout(function() {
                                        if (typeof window.refreshPaginationAfterMutation === 'function') window.refreshPaginationAfterMutation();
                                    }, 500);
                                }
                            }, 300);
                        }
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + d.error, 'error');
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function() { if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error'); };
        xhr.ontimeout = function() { if (typeof window.showToast === 'function') window.showToast('Превышено время ожидания', 'error'); };
        xhr.send(formData);
    }

    function deleteCurrentMessage() {
        var messageUuid = window._currentMessageUuid;
        var isOwn = window._currentMessageOwn;
        var isAdmin = (window.currentUserIsAdmin === true);
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
                messageUuid = window._fileMenuContext.messageUuid;
                isOwn = window._fileMenuContext.isOwn;
            }
        }
        var canDelete = (messageUuid && (isOwn || isAdmin));
        if (!canDelete) {
            if (typeof window.showToast === 'function') window.showToast('Нельзя удалять чужие сообщения', 'error');
            closeMessageMenu();
            return;
        }
        closeMessageMenu();
        deleteMessage(messageUuid);
    }

    function replyToCurrentMessage() {
        var replyToUuid = window._currentMessageUuid;
        var authorName = window._currentMessageAuthor;
        if (!replyToUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
                replyToUuid = window._fileMenuContext.messageUuid;
                authorName = '';
            }
        }
        if (!replyToUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: сообщение не найдено', 'error');
            closeMessageMenu();
            return;
        }
        var replyToInput = document.getElementById('reply-to-uuid');
        if (replyToInput) replyToInput.value = replyToUuid;
        var indicator = document.getElementById('reply-indicator');
        var authorSpan = document.getElementById('reply-author-name');
        if (indicator && authorSpan) {
            authorSpan.textContent = authorName || 'Пользователя';
            indicator.style.display = 'flex';
        }
        document.getElementById('message-input').focus();
        closeMessageMenu();
    }

    function cancelReply() {
        var replyToInput = document.getElementById('reply-to-uuid');
        if (replyToInput) replyToInput.value = '';
        var indicator = document.getElementById('reply-indicator');
        if (indicator) indicator.style.display = 'none';
    }

    function copyMessageLink() {
        var messageUuid = window._currentMessageUuid;
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) messageUuid = window._fileMenuContext.messageUuid;
        }
        if (!messageUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: сообщение не найдено', 'error');
            closeMessageMenu();
            return;
        }
        var fullUrl = window.location.protocol + '//' + window.location.host + (window.APP_BASE || '') + '/messages.php?message=' + messageUuid;
        navigator.clipboard.writeText(fullUrl).then(function() {
            if (typeof window.showToast === 'function') window.showToast('✓ Ссылка на сообщение скопирована', 'success');
        }).catch(function() {
            var textarea = document.createElement('textarea');
            textarea.value = fullUrl;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            if (typeof window.showToast === 'function') window.showToast('✓ Ссылка на сообщение скопирована', 'success');
        });
        closeMessageMenu();
    }

    function copyMessageText() {
        var text = null;
        function decodeText(encodedText) {
            if (!encodedText) return '';
            try { return decodeURIComponent(encodedText); } catch(e) { return encodedText; }
        }
        if (window._currentMessageText && window._currentMessageText.length > 0) text = decodeText(window._currentMessageText);
        else if (window._fileMenuContext && window._fileMenuContext.messageText && window._fileMenuContext.messageText.length > 0) text = decodeText(window._fileMenuContext.messageText);
        if (!text || text.length === 0) { closeMessageMenu(); return; }
        navigator.clipboard.writeText(text).then(function() {
            if (typeof window.showToast === 'function') window.showToast('✓ Текст скопирован', 'success');
        }).catch(function() {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            if (typeof window.showToast === 'function') window.showToast('✓ Текст скопирован', 'success');
        });
        closeMessageMenu();
    }

    function showMessageMenu(event, messageUuid, isOwn, authorName, messageTime) {
        if (event.target.tagName === 'A' || event.target.closest('a') || event.target.closest('.clickable-quote')) return;
        event.stopPropagation();
        if (typeof window.closeFileMenu === 'function') window.closeFileMenu();
        var isAdmin = (window.currentUserIsAdmin === true);
        window._currentMessageUuid = messageUuid;
        window._currentMessageOwn = isOwn;
        window._currentMessageAuthor = authorName;
        window._currentMessageTime = messageTime;
        var msgElement = document.querySelector('.message[data-uuid="'+messageUuid+'"]');
        if (msgElement) {
            var originalText = msgElement.getAttribute('data-original-text');
            if (originalText) {
                try { window._currentMessageText = decodeURIComponent(originalText); }
                catch(e) {
                    var textarea = document.createElement('textarea');
                    textarea.innerHTML = originalText;
                    window._currentMessageText = textarea.value;
                }
            } else {
                var messageTextDiv = msgElement.querySelector('.message-text');
                if (messageTextDiv) {
                    var clone = messageTextDiv.cloneNode(true);
                    var quotes = clone.querySelectorAll('.message-quote');
                    quotes.forEach(function(quote) { quote.remove(); });
                    window._currentMessageText = clone.textContent || clone.innerText || '';
                    window._currentMessageText = window._currentMessageText.trim();
                }
            }
        } else window._currentMessageText = '';
        var showFullMenu = isOwn;
        var showDeleteOnly = (!isOwn && isAdmin);
        var editBtn = document.getElementById('editMessageBtn');
        var deleteMenuItem = document.getElementById('deleteMessageBtn');
        var addFilesBtn = document.getElementById('addFilesToMessageBtn');
        var replaceFileBtn = document.getElementById('replaceFileBtn');
        if (showFullMenu) {
            if (editBtn) editBtn.style.display = 'flex';
            if (deleteMenuItem) deleteMenuItem.style.display = 'flex';
            if (addFilesBtn) addFilesBtn.style.display = 'flex';
            if (replaceFileBtn) {
                var hasFiles = msgElement && msgElement.querySelectorAll('.file-item, .file-preview-thumb').length > 0;
                replaceFileBtn.style.display = hasFiles ? 'flex' : 'none';
            }
        } else if (showDeleteOnly) {
            if (editBtn) editBtn.style.display = 'none';
            if (deleteMenuItem) deleteMenuItem.style.display = 'flex';
            if (addFilesBtn) addFilesBtn.style.display = 'none';
            if (replaceFileBtn) replaceFileBtn.style.display = 'none';
        } else {
            if (editBtn) editBtn.style.display = 'none';
            if (deleteMenuItem) deleteMenuItem.style.display = 'none';
            if (addFilesBtn) addFilesBtn.style.display = 'none';
            if (replaceFileBtn) replaceFileBtn.style.display = 'none';
        }
        var overlay = document.getElementById('messageMenuOverlay');
        if (overlay) overlay.classList.add('show');
    }

    function closeMessageMenu() {
        var editModal = document.getElementById('editMessageModal');
        if (editModal && editModal.style.display === 'flex') {
            editModal.style.display = 'none';
            if (window._editModalKeydownHandler) {
                document.removeEventListener('keydown', window._editModalKeydownHandler);
                window._editModalKeydownHandler = null;
            }
            window._editMessageContext = null;
        }
        var overlay = document.getElementById('messageMenuOverlay');
        if (overlay) overlay.classList.remove('show');
        if (typeof window.closeFileMenu === 'function') window.closeFileMenu();
    }

    function addFilesToMessage() {
        var messageUuid = window._currentMessageUuid;
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) messageUuid = window._fileMenuContext.messageUuid;
        }
        if (!messageUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: сообщение не найдено', 'error');
            return;
        }
        closeMessageMenu();
        if (typeof window.closeFileMenu === 'function') window.closeFileMenu();
        uploadFilesToMessage(messageUuid, null);
    }

    function uploadFilesToMessage(messageUuid, files) {
        if (!messageUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: сообщение не найдено', 'error');
            return;
        }
        if (!files || files.length === 0) {
            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.multiple = true;
            fileInput.style.display = 'none';
            fileInput.accept = 'image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.*,text/plain,application/zip';
            fileInput.onchange = function(e) {
                if (e.target.files && e.target.files.length > 0) uploadFilesToMessage(messageUuid, Array.from(e.target.files));
                fileInput.remove();
            };
            document.body.appendChild(fileInput);
            fileInput.click();
            return;
        }
        if (typeof window.showToast === 'function') window.showToast('⏳ Загрузка файлов...', 'info');
        var formData = new FormData();
        formData.append('action', 'add_files_to_message');
        formData.append('message_uuid', messageUuid);
        if (typeof window.addCsrfToFormData === 'function') window.addCsrfToFormData(formData);
        for (var i = 0; i < files.length; i++) formData.append('files[]', files[i]);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php');
        xhr.timeout = 120000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success && d.message) {
                        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                        if (msgElement && typeof window.renderMessage === 'function') {
                            var newMsgHtml = window.renderMessage(d.message);
                            var temp = document.createElement('div');
                            temp.innerHTML = newMsgHtml;
                            var newMsgElement = temp.firstChild;
                            msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                            if (typeof window.loadLazyPreviews === 'function') setTimeout(window.loadLazyPreviews, 100);
                            if (typeof window.refreshPaginationAfterMutation === 'function') {
                                setTimeout(function() { window.refreshPaginationAfterMutation(); }, 500);
                            }
                        } else location.reload();
                    } else if (d.needs_confirmation && d.security_details) {
                        if (typeof window.showToast === 'function') window.showToast('Требуется подтверждение безопасности', 'warning');
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function() { if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error'); };
        xhr.ontimeout = function() { if (typeof window.showToast === 'function') window.showToast('Превышено время ожидания', 'error'); };
        xhr.send(formData);
    }

    function replaceFileInMessage() {
        var messageUuid = window._currentMessageUuid;
        var isOwn = window._currentMessageOwn;
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
                messageUuid = window._fileMenuContext.messageUuid;
                isOwn = window._fileMenuContext.isOwn;
            }
        }
        if (!messageUuid || !isOwn) {
            if (typeof window.showToast === 'function') window.showToast('Нельзя заменять файлы в чужих сообщениях', 'error');
            closeMessageMenu();
            return;
        }
        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
        if (!msgElement) {
            if (typeof window.showToast === 'function') window.showToast('Сообщение не найдено', 'error');
            closeMessageMenu();
            return;
        }
        var fileItems = msgElement.querySelectorAll('.file-item, .file-preview-thumb');
        var filesList = [];
        fileItems.forEach(function(item) {
            var onclickAttr = item.getAttribute('onclick');
            if (onclickAttr) {
                var match = onclickAttr.match(/showFilePreview\('([^']+)'/);
                if (match) filesList.push({ uuid: match[1], element: item });
            }
        });
        if (filesList.length === 0) {
            if (typeof window.showToast === 'function') window.showToast('В этом сообщении нет файлов для замены', 'warning');
            closeMessageMenu();
            return;
        }
        if (filesList.length === 1) {
            window.currentReplaceFileUuid = filesList[0].uuid;
            window.currentReplaceMessageUuid = messageUuid;
            closeMessageMenu();
            openFileReplaceDialog();
        } else {
            closeMessageMenu();
            showFileSelectionForReplace(filesList, messageUuid);
        }
    }

    function showFileSelectionForReplace(filesList, messageUuid) {
        var modal = document.createElement('div');
        modal.id = 'fileSelectModal';
        modal.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:20001; display:flex; align-items:center; justify-content:center;';
        var modalContent = document.createElement('div');
        modalContent.style.cssText = 'background:#1e293b; border-radius:16px; width:90%; max-width:400px; max-height:80vh; overflow:auto; border:1px solid rgba(255,255,255,0.1);';
        var html = '<div style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.1);">';
        html += '<h3 style="margin:0; font-size:16px;">🔄 Выберите файл для замены</h3>';
        html += '</div><div style="padding:12px;">';
        for (var i = 0; i < filesList.length; i++) {
            var file = filesList[i];
            var fileName = '';
            var fileLink = file.element.querySelector('a') || file.element;
            if (fileLink && fileLink.textContent) fileName = fileLink.textContent.replace(/[📎📄📝📊🖼️]/g, '').trim();
            html += '<div class="file-select-item" data-file-uuid="' + file.uuid + '" style="padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background=\'rgba(79,124,255,0.1)\'" onmouseout="this.style.background=\'transparent\'">';
            html += '<div style="display:flex; align-items:center; gap:12px;">';
            html += '<span style="font-size:20px;">📎</span>';
            html += '<span style="flex:1; word-break:break-word;">' + escapeHtmlStatic(fileName || 'Файл') + '</span>';
            html += '<span style="color:#4f7cff;">→</span>';
            html += '</div></div>';
        }
        html += '</div><div style="padding:12px 20px; border-top:1px solid rgba(255,255,255,0.1); display:flex; justify-content:flex-end;">';
        html += '<button class="btn-secondary" onclick="closeFileSelectModal()" style="background:rgba(255,255,255,0.1); border:none; padding:8px 16px; border-radius:8px; color:#e9eefc; cursor:pointer;">Отмена</button>';
        html += '</div>';
        modalContent.innerHTML = html;
        modal.appendChild(modalContent);
        document.body.appendChild(modal);
        var items = modalContent.querySelectorAll('.file-select-item');
        for (var i = 0; i < items.length; i++) {
            items[i].addEventListener('click', function(e) {
                var fileUuid = this.dataset.fileUuid;
                window.currentReplaceFileUuid = fileUuid;
                window.currentReplaceMessageUuid = messageUuid;
                closeFileSelectModal();
                openFileReplaceDialog();
            });
        }
        window.closeFileSelectModal = function() { if (modal && modal.parentNode) modal.remove(); };
    }

    function openFileReplaceDialog() {
        if (typeof window.closeFileMenu === 'function') window.closeFileMenu();
        closeMessageMenu();
        if (document.getElementById('replaceFileDialog')) return;
        var overlay = document.createElement('div');
        overlay.id = 'replaceFileDialog';
        overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); z-index:20002; display:flex; align-items:center; justify-content:center;';
        var modalContent = document.createElement('div');
        modalContent.style.cssText = 'background:#1e293b; border-radius:20px; width:90%; max-width:380px; border:1px solid rgba(79,124,255,0.3); box-shadow:0 20px 40px rgba(0,0,0,0.5); overflow:hidden;';
        modalContent.innerHTML = '\
            <div style="padding:20px 24px; background:linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-bottom:1px solid rgba(79,124,255,0.3);">\
                <h3 style="margin:0; font-size:18px; color:#e9eefc; display:flex; align-items:center; gap:10px;">\
                    <span>🔄</span> Управление файлом\
                </h3>\
                <p style="margin:8px 0 0 0; font-size:12px; color:rgba(233,238,252,0.6);">Выберите действие для файла</p>\
            </div>\
            <div style="padding:24px;">\
                <button id="replaceFileChooseBtn" style="width:100%; padding:14px; background:#4f7cff; border:none; border-radius:12px; color:white; cursor:pointer; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">\
                    📁 Заменить файл с диска\
                </button>\
                <button id="replaceFilePasteBtn" style="width:100%; padding:14px; background:rgba(79,124,255,0.15); border:1px solid rgba(79,124,255,0.4); border-radius:12px; color:#9bb7ff; cursor:pointer; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">\
                    📋 Заменить из буфера обмена (Ctrl+V)\
                </button>\
                <button id="replaceFileDeleteBtn" style="width:100%; padding:14px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:12px; color:#f87171; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">\
                    🗑️ Удалить файл из сообщения\
                </button>\
            </div>\
            <div style="padding:16px 24px; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end;">\
                <button id="replaceFileCancelBtn" style="background:rgba(255,255,255,0.08); border:none; padding:10px 20px; border-radius:10px; color:#e9eefc; cursor:pointer; font-size:14px; transition:all 0.2s;">Отмена</button>\
            </div>\
        ';
        overlay.appendChild(modalContent);
        document.body.appendChild(overlay);
        var chooseBtn = document.getElementById('replaceFileChooseBtn');
        if (chooseBtn) {
            chooseBtn.onclick = function(e) {
                e.stopPropagation();
                overlay.remove();
                var fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.style.display = 'none';
                fileInput.accept = 'image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.*,text/plain';
                fileInput.onchange = function(e) {
                    if (e.target.files && e.target.files.length > 0) {
                        if (confirm('⚠️ Вы уверены, что хотите ЗАМЕНИТЬ этот файл?\n\nСтарый файл будет удалён безвозвратно.')) {
                            executeFileReplace(window.currentReplaceMessageUuid, window.currentReplaceFileUuid, e.target.files[0]);
                        }
                    }
                    fileInput.remove();
                    window.currentReplaceFileUuid = null;
                    window.currentReplaceMessageUuid = null;
                };
                document.body.appendChild(fileInput);
                fileInput.click();
            };
        }
        var pasteBtn = document.getElementById('replaceFilePasteBtn');
        if (pasteBtn) {
            pasteBtn.onclick = function(e) {
                e.stopPropagation();
                overlay.remove();
                var waitingIndicator = document.createElement('div');
                waitingIndicator.id = 'pasteWaitingIndicator';
                waitingIndicator.style.cssText = 'position:fixed; bottom:30px; left:50%; transform:translateX(-50%); background:#1e293b; border:2px solid #4f7cff; border-radius:50px; padding:14px 28px; color:#e9eefc; font-size:15px; z-index:20003; box-shadow:0 4px 20px rgba(0,0,0,0.4); display:flex; align-items:center; gap:12px;';
                waitingIndicator.innerHTML = '📋 Нажмите <kbd style="background:#0b1020; padding:4px 10px; border-radius:6px; font-family:monospace;">Ctrl+V</kbd> для вставки изображения';
                document.body.appendChild(waitingIndicator);
                var timeoutId = setTimeout(function() {
                    if (waitingIndicator && waitingIndicator.parentNode) waitingIndicator.remove();
                    document.removeEventListener('paste', pasteHandler);
                    if (typeof window.showToast === 'function') window.showToast('⏰ Время ожидания истекло', 'warning');
                    window.currentReplaceFileUuid = null;
                    window.currentReplaceMessageUuid = null;
                }, 30000);
                var pasteHandler = function(e) {
                    e.preventDefault();
                    clearTimeout(timeoutId);
                    if (waitingIndicator && waitingIndicator.parentNode) waitingIndicator.remove();
                    document.removeEventListener('paste', pasteHandler);
                    var items = (e.clipboardData || e.originalEvent.clipboardData).items;
                    var imageBlob = null;
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].type.indexOf('image') !== -1) { imageBlob = items[i].getAsFile(); break; }
                    }
                    if (imageBlob) {
                        var now = new Date();
                        var fileName = 'pasted_image_' + now.getFullYear() + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0') + '_' + String(now.getHours()).padStart(2,'0') + String(now.getMinutes()).padStart(2,'0') + String(now.getSeconds()).padStart(2,'0') + '.png';
                        var newFile = new File([imageBlob], fileName, { type: imageBlob.type });
                        if (confirm('⚠️ Вы уверены, что хотите ЗАМЕНИТЬ файл на изображение из буфера?\n\nСтарый файл будет удалён безвозвратно.')) {
                            executeFileReplace(window.currentReplaceMessageUuid, window.currentReplaceFileUuid, newFile);
                        }
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('❌ В буфере обмена нет изображения', 'error');
                    }
                    window.currentReplaceFileUuid = null;
                    window.currentReplaceMessageUuid = null;
                };
                document.addEventListener('paste', pasteHandler);
            };
        }
        var deleteBtn = document.getElementById('replaceFileDeleteBtn');
        if (deleteBtn) {
            deleteBtn.onclick = function(e) {
                e.stopPropagation();
                overlay.remove();
                if (confirm('⚠️ Вы уверены, что хотите УДАЛИТЬ этот файл из сообщения?\n\nФайл будет полностью удалён с сервера и из всех сообщений, где используется.\n\nДействие необратимо!')) {
                    executeFileDelete(window.currentReplaceMessageUuid, window.currentReplaceFileUuid);
                } else {
                    if (typeof window.showToast === 'function') window.showToast('Удаление отменено', 'info');
                }
                window.currentReplaceFileUuid = null;
                window.currentReplaceMessageUuid = null;
            };
        }
        var cancelBtn = document.getElementById('replaceFileCancelBtn');
        if (cancelBtn) {
            cancelBtn.onclick = function(e) {
                e.stopPropagation();
                overlay.remove();
                window.currentReplaceFileUuid = null;
                window.currentReplaceMessageUuid = null;
                if (typeof window.showToast === 'function') window.showToast('Замена файла отменена', 'info');
            };
        }
        overlay.onclick = function(e) {
            if (e.target === overlay) {
                overlay.remove();
                window.currentReplaceFileUuid = null;
                window.currentReplaceMessageUuid = null;
                if (typeof window.showToast === 'function') window.showToast('Замена файла отменена', 'info');
            }
        };
    }

    function executeFileReplace(messageUuid, oldFileUuid, newFile) {
        if (!messageUuid || !oldFileUuid || !newFile) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: не указаны параметры', 'error');
            return;
        }
        if (typeof window.showToast === 'function') window.showToast('🔄 Замена файла...', 'info');
        var formData = new FormData();
        formData.append('action', 'replace_message_file');
        formData.append('message_uuid', messageUuid);
        formData.append('old_file_uuid', oldFileUuid);
        formData.append('file', newFile);
        if (typeof window.addCsrfToFormData === 'function') window.addCsrfToFormData(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php');
        xhr.timeout = 60000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        if (typeof window.refreshPaginationAfterMutation === 'function') {
                            setTimeout(function() { window.refreshPaginationAfterMutation(); }, 500);
                        }
                        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                        if (msgElement && data.message && typeof window.renderMessage === 'function') {
                            var newMsgHtml = window.renderMessage(data.message);
                            var tempContainer = document.createElement('div');
                            tempContainer.innerHTML = newMsgHtml;
                            var newMsgElement = tempContainer.firstChild;
                            msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                            if (typeof window.loadLazyPreviews === 'function') setTimeout(window.loadLazyPreviews, 100);
                        } else location.reload();
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function() { if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error'); };
        xhr.ontimeout = function() { if (typeof window.showToast === 'function') window.showToast('Превышено время ожидания', 'error'); };
        xhr.send(formData);
    }

    function executeFileDelete(messageUuid, fileUuid) {
        if (!messageUuid || !fileUuid) {
            if (typeof window.showToast === 'function') window.showToast('Ошибка: не указаны параметры', 'error');
            return;
        }
        if (typeof window.showToast === 'function') window.showToast('🗑️ Удаление файла...', 'info');
        var formData = new URLSearchParams();
        formData.append('action', 'delete_message_file');
        formData.append('message_uuid', messageUuid);
        formData.append('file_uuid', fileUuid);
        if (typeof window.addCsrfToUrlParams === 'function') window.addCsrfToUrlParams(formData);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.APP_BASE + '/messages.php');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        if (typeof window.refreshPaginationAfterMutation === 'function') {
                            setTimeout(function() { window.refreshPaginationAfterMutation(); }, 500);
                        }
                        var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                        if (msgElement && data.message && typeof window.renderMessage === 'function') {
                            var newMsgHtml = window.renderMessage(data.message);
                            var tempContainer = document.createElement('div');
                            tempContainer.innerHTML = newMsgHtml;
                            var newMsgElement = tempContainer.firstChild;
                            msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                            if (typeof window.loadLazyPreviews === 'function') setTimeout(window.loadLazyPreviews, 100);
                        } else if (msgElement) location.reload();
                    } else {
                        if (typeof window.showToast === 'function') window.showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    if (typeof window.showToast === 'function') window.showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                if (typeof window.showToast === 'function') window.showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function() { if (typeof window.showToast === 'function') window.showToast('Сетевая ошибка', 'error'); };
        xhr.ontimeout = function() { if (typeof window.showToast === 'function') window.showToast('Превышено время ожидания', 'error'); };
        xhr.send(formData);
    }

    // ==================== УПРАВЛЕНИЕ ФАЙЛАМИ UI ====================

    function handleFileSelect(e) {
        window.selectedFiles = window.selectedFiles.concat(Array.from(e.target.files));
        if (typeof window.updateSelectedFiles === 'function') window.updateSelectedFiles();
        e.target.value = '';
    }

    function updateSelectedFiles() {
        var c = document.getElementById('selected-files');
        if (!c) return;
        c.innerHTML = window.selectedFiles.map(function(f, i) {
            return '<div class="selected-file">📎 ' + escapeHtmlStatic(f.name) + ' (' + formatFileSize(f.size) + ') <span class="remove-file" onclick="removeFile(' + i + ')">✕</span></div>';
        }).join('');
    }

    function removeFile(i) {
        window.selectedFiles.splice(i, 1);
        if (typeof window.updateSelectedFiles === 'function') window.updateSelectedFiles();
    }

    function copyFileLink(fileUuid) {
        var fullUrl = window.location.protocol + '//' + window.location.host + (window.APP_BASE || '') + '/file_preview.php?uuid=' + fileUuid;
        navigator.clipboard.writeText(fullUrl).then(function() {
            if (typeof window.showToast === 'function') window.showToast('✓ Ссылка на файл скопирована', 'success');
        }).catch(function() {
            var textarea = document.createElement('textarea');
            textarea.value = fullUrl;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            if (typeof window.showToast === 'function') window.showToast('✓ Ссылка на файл скопирована', 'success');
        });
    }

    /**
     * FIX v2.4.0: приоритет showToast — как в оригинале.
     * Если доступна showToast (из toast.js) — используем её (красивая анимация).
     * Иначе — простой custom-alert.
     */
    function showAlert(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }
        var alertDiv = document.createElement('div');
        alertDiv.className = 'custom-alert ' + (type || 'info');
        alertDiv.innerHTML = message;
        var bgColor = type === 'error' ? '#dc2626'
                   : type === 'warning' ? '#f59e0b'
                   : type === 'info' ? '#3b82f6'
                   : '#10b981';
        alertDiv.style.cssText = 'position:fixed;bottom:20px;right:20px;background:' + bgColor +
            ';color:white;padding:10px 20px;border-radius:8px;z-index:10000;font-size:14px;' +
            'max-width:300px;word-wrap:break-word;box-shadow:0 2px 10px rgba(0,0,0,0.2);';
        document.body.appendChild(alertDiv);
        setTimeout(function() { if (alertDiv && alertDiv.remove) alertDiv.remove(); }, 3000);
    }

    // ==================== ИНИЦИАЛИЗАЦИЯ ====================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { initTaskDetailsPanel(); });
    } else {
        initTaskDetailsPanel();
    }

    // ==================== ЭКСПОРТ ====================

    window.parseTaskDetailsLinks = parseTaskDetailsLinks;
    window.taskDetailsPanel = taskDetailsPanel;
    window.formatTaskDate = formatTaskDate;
    window.loadTaskDetails = loadTaskDetails;
    window.utcToLocalDatetimeString = utcToLocalDatetimeString;
    window.formatFileSize = formatFileSize;
    window.getFileIconFromName = getFileIconFromName;
    window.renderTaskDetails = renderTaskDetails;
    window.renderTaskDetailsFilesList = renderTaskDetailsFilesList;
    window.enterTaskEditMode = enterTaskEditMode;
    window.cancelTaskEditMode = cancelTaskEditMode;
    window.forceTaskViewMode = forceTaskViewMode;
    window.saveTaskDetails = saveTaskDetails;
    window.uploadTaskFileFromDetails = uploadTaskFileFromDetails;
    window.detachTaskFileFromDetails = detachTaskFileFromDetails;
    window.openTaskDetailsPanel = openTaskDetailsPanel;
    window.fixMobileTaskButtonsPosition = fixMobileTaskButtonsPosition;
    window.closeTaskDetailsPanel = closeTaskDetailsPanel;
    window.setupTaskTitleClickHandler = setupTaskTitleClickHandler;
    window.setupTaskDetailsCloseHandler = setupTaskDetailsCloseHandler;
    window.initTaskDetailsPanel = initTaskDetailsPanel;
    window.showAlert = showAlert;

    // Сообщения
    window.sendMessage = sendMessage;
    window.showSecurityWarningModal = showSecurityWarningModal;
    window.sendIgnoredSecurityUpload = sendIgnoredSecurityUpload;
    window.editCurrentMessage = editCurrentMessage;
    window.deleteMessage = deleteMessage;
    window.deleteCurrentMessage = deleteCurrentMessage;
    window.replyToCurrentMessage = replyToCurrentMessage;
    window.cancelReply = cancelReply;
    window.copyMessageLink = copyMessageLink;
    window.copyMessageText = copyMessageText;
    window.showMessageMenu = showMessageMenu;
    window.closeMessageMenu = closeMessageMenu;
    window.addFilesToMessage = addFilesToMessage;
    window.uploadFilesToMessage = uploadFilesToMessage;
    window.replaceFileInMessage = replaceFileInMessage;
    window.showFileSelectionForReplace = showFileSelectionForReplace;
    window.openFileReplaceDialog = openFileReplaceDialog;
    window.executeFileReplace = executeFileReplace;
    window.executeFileDelete = executeFileDelete;

    // Файлы UI
    window.handleFileSelect = handleFileSelect;
    window.updateSelectedFiles = updateSelectedFiles;
    window.removeFile = removeFile;
    window.copyFileLink = copyFileLink;

    logDebug('[MESSAGES_TASKBAR] v2.4.0 initialized');
})();