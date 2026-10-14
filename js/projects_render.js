/**
 * projects_render.js
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from projects.php. Extracted project UI rendering:
 *                       selectProject, showCreateProjectModal, saveProject, initMultiSelectFilters,
 *                       renderPagination, updateProjectBadge, deleteProject, loadFilterSettings, applyFilterSettings.
 */

(function() {
    'use strict';

    function logDebugWrapper() {
        if (typeof window.logDebug === 'function') window.logDebug.apply(null, arguments);
    }
    function logErrorWrapper() {
        if (typeof window.logError === 'function') window.logError.apply(null, arguments);
    }

    // ========== ФИЛЬТРЫ: НАСТРОЙКИ ==========

    window.getFilterSettingsKey = function() {
        var userUuid = window.currentUserUuid || '';
        if (!userUuid) {
            return 'task_filters_default';
        }
        return 'task_filters_' + userUuid;
    };

    window.saveFilterSettings = function() {
        try {
            var settings = {
                filterStatuses: JSON.parse(JSON.stringify(window.currentFilterStatuses)),
                filterAssigned: JSON.parse(JSON.stringify(window.currentFilterAssigned)),
                search: window.currentSearch,
                sortBy: window.currentSortBy,
                sortDir: window.currentSortDir,
                perPage: window.currentPerPage,
                savedAt: Date.now()
            };

            var key = window.getFilterSettingsKey();
            localStorage.setItem(key, JSON.stringify(settings));
        } catch (e) {
            logErrorWrapper('[FILTER_SETTINGS] Failed to save settings:', e);
        }
    };

    window.loadFilterSettings = function() {
        try {
            var key = window.getFilterSettingsKey();
            var saved = localStorage.getItem(key);
            if (!saved) {
                return null;
            }

            var settings = JSON.parse(saved);
            return settings;
        } catch (e) {
            logErrorWrapper('[FILTER_SETTINGS] Failed to load settings:', e);
            return null;
        }
    };

    window.applyFilterSettings = function(settings) {
        if (!settings) return false;

        if (settings.perPage && !isNaN(parseInt(settings.perPage))) {
            window.currentPerPage = parseInt(settings.perPage);
            var perPageSelect = document.getElementById('per-page');
            if (perPageSelect) perPageSelect.value = window.currentPerPage.toString();
            localStorage.setItem('tasks_per_page', window.currentPerPage);
        }

        if (settings.sortBy) {
            window.currentSortBy = settings.sortBy;
            var sortBySelect = document.getElementById('sort-by');
            if (sortBySelect && sortBySelect.value !== window.currentSortBy) {
                sortBySelect.value = window.currentSortBy;
            }
        }

        if (settings.sortDir) {
            window.currentSortDir = settings.sortDir;
        }

        if (typeof settings.search === 'string') {
            window.currentSearch = settings.search;
            var searchInput = document.getElementById('task-search');
            if (searchInput && searchInput.value !== window.currentSearch) {
                searchInput.value = window.currentSearch;
            }
        }

        if (settings.filterStatuses && Array.isArray(settings.filterStatuses)) {
            window.currentFilterStatuses = settings.filterStatuses;

            var statusDropdown = document.getElementById('status-filter-dropdown');
            if (statusDropdown) {
                var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]');
                statusCbs.forEach(function(cb) {
                    var val = parseInt(cb.value);
                    cb.checked = window.currentFilterStatuses.includes(val);
                });
            }
            window.updateStatusFilter();
        }

        if (settings.filterAssigned && Array.isArray(settings.filterAssigned)) {
            window.currentFilterAssigned = settings.filterAssigned;

            var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
            if (assigneeDropdown) {
                var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]');
                assigneeCbs.forEach(function(cb) {
                    cb.checked = window.currentFilterAssigned.includes(cb.value);
                });
            }
            window.updateAssigneeFilter();
        }

        return true;
    };

    window.clearFilterSettings = function() {
        try {
            var key = window.getFilterSettingsKey();
            localStorage.removeItem(key);
        } catch (e) {
            logErrorWrapper('[FILTER_SETTINGS] Failed to clear settings:', e);
        }
    };

    // ========== ЗАГРУЗКА ПОЛЬЗОВАТЕЛЕЙ ДЛЯ ФИЛЬТРА ==========

    window.loadProjectUsersForFilter = function(projectUuid) {
        var dropdown = document.getElementById('assignee-filter-dropdown');
        if (!dropdown) return;

        dropdown.innerHTML = '<div class="assignee-loading" style="padding: 12px; text-align: center; color: rgba(233,238,252,0.6);">⏳ Загрузка списка пользователей...</div>';

        var formData = new URLSearchParams();
        formData.append('action', 'get_project_users');
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
            if (data.success && data.users) {
                var html = '';
                for (var i = 0; i < data.users.length; i++) {
                    var user = data.users[i];
                    html += '<div class="multi-select-option">';
                    html += '<input type="checkbox" value="' + escapeHtml(user.uuid) + '" id="assignee_' + escapeHtml(user.uuid) + '">';
                    html += '<label for="assignee_' + escapeHtml(user.uuid) + '">' + escapeHtml(user.name || user.login) + '</label>';
                    html += '</div>';
                }

                if (data.users.length === 0) {
                    html = '<div style="padding: 12px; text-align: center; color: rgba(233,238,252,0.5);">Нет доступных пользователей</div>';
                }

                dropdown.innerHTML = html;

                if (window.currentFilterAssigned && window.currentFilterAssigned.length > 0) {
                    var checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
                    for (var j = 0; j < checkboxes.length; j++) {
                        if (window.currentFilterAssigned.includes(checkboxes[j].value)) {
                            checkboxes[j].checked = true;
                        }
                    }
                    window.updateAssigneeFilter();
                }

                var newCheckboxes = dropdown.querySelectorAll('input[type="checkbox"]');
                for (var k = 0; k < newCheckboxes.length; k++) {
                    newCheckboxes[k].addEventListener('change', function() {
                        window.updateAssigneeFilter();
                        window.loadProjectTasks(true);
                    });
                }
            } else {
                dropdown.innerHTML = '<div style="padding: 12px; text-align: center; color: #f87171;">❌ Ошибка загрузки пользователей</div>';
            }
        })
        .catch(function(err) {
            logErrorWrapper('[FILTER] Error loading users:', err);
            dropdown.innerHTML = '<div style="padding: 12px; text-align: center; color: #f87171;">❌ Ошибка загрузки</div>';
        });
    };

    // ========== ОБНОВЛЕНИЕ ФИЛЬТРОВ UI ==========

    window.updateStatusFilter = function() {
        var statusDropdown = document.getElementById('status-filter-dropdown');
        var statusBtn = document.getElementById('status-filter-btn');
        if (!statusDropdown || !statusBtn) return;

        var newFilterStatuses = [];
        var checkboxes = statusDropdown.querySelectorAll('input[type="checkbox"]:checked');
        checkboxes.forEach(function(cb) {
            newFilterStatuses.push(parseInt(cb.value));
        });

        window.currentFilterStatuses = newFilterStatuses;
        window.saveFilterSettings();

        if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();

        var btnSpan = statusBtn.querySelector('span:first-child');
        if (btnSpan) {
            if (window.currentFilterStatuses.length === 0) btnSpan.innerHTML = '📊 Статус: все';
            else if (window.currentFilterStatuses.length === 1) {
                var statusText = window.currentFilterStatuses[0] === 0 ? '🟢 Активные' : '✅ Выполненные';
                btnSpan.innerHTML = '📊 ' + statusText;
            } else btnSpan.innerHTML = '📊 Статус: выбрано ' + window.currentFilterStatuses.length;
        }
    };

    window.updateAssigneeFilter = function() {
        var assigneeDropdown = document.getElementById('assignee-filter-dropdown');
        var assigneeBtn = document.getElementById('assignee-filter-btn');
        if (!assigneeDropdown || !assigneeBtn) return;

        var newFilterAssigned = [];
        var checkboxes = assigneeDropdown.querySelectorAll('input[type="checkbox"]:checked');
        checkboxes.forEach(function(cb) {
            newFilterAssigned.push(cb.value);
        });

        window.currentFilterAssigned = newFilterAssigned;
        window.saveFilterSettings();

        if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();

        var btnSpan = assigneeBtn.querySelector('span:first-child');
        if (btnSpan) {
            if (window.currentFilterAssigned.length === 0) btnSpan.innerHTML = '👤 Исполнители: все';
            else if (window.currentFilterAssigned.length === 1) {
                var label = assigneeDropdown.querySelector('label[for="' + checkboxes[0].id + '"]');
                var name = label ? label.textContent : 'выбран';
                btnSpan.innerHTML = '👤 ' + name;
            } else btnSpan.innerHTML = '👤 Исполнители: выбрано ' + window.currentFilterAssigned.length;
        }
    };

    // ========== МУЛЬТИСЕЛЕКТ ФИЛЬТРЫ ==========

    window.initMultiSelectFilters = function() {
        var statusBtn = document.getElementById('status-filter-btn');
        var statusDropdown = document.getElementById('status-filter-dropdown');
        var assigneeBtn = document.getElementById('assignee-filter-btn');
        var assigneeDropdown = document.getElementById('assignee-filter-dropdown');

        if (statusBtn && statusDropdown) {
            var newStatusBtn = statusBtn.cloneNode(true);
            if (statusBtn.parentNode) {
                statusBtn.parentNode.replaceChild(newStatusBtn, statusBtn);
                statusBtn = newStatusBtn;
            }

            statusBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (statusDropdown) statusDropdown.classList.toggle('show');
                if (assigneeDropdown) assigneeDropdown.classList.remove('show');
            });

            var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]');
            statusCbs.forEach(function(cb) {
                cb.removeEventListener('change', statusChangeHandler);
                cb.addEventListener('change', statusChangeHandler);
            });

            function statusChangeHandler() {
                window.updateStatusFilter();
                if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
                window.loadProjectTasks(true);
            }
        }

        if (assigneeBtn && assigneeDropdown) {
            var newAssigneeBtn = assigneeBtn.cloneNode(true);
            if (assigneeBtn.parentNode) {
                assigneeBtn.parentNode.replaceChild(newAssigneeBtn, assigneeBtn);
                assigneeBtn = newAssigneeBtn;
            }

            assigneeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (assigneeDropdown) assigneeDropdown.classList.toggle('show');
                if (statusDropdown) statusDropdown.classList.remove('show');
            });

            var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]');
            assigneeCbs.forEach(function(cb) {
                cb.removeEventListener('change', assigneeChangeHandler);
                cb.addEventListener('change', assigneeChangeHandler);
            });

            function assigneeChangeHandler() {
                window.updateAssigneeFilter();
                if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
                window.loadProjectTasks(true);
            }
        }

        document.addEventListener('click', function(e) {
            if (statusDropdown && !statusDropdown.contains(e.target) && e.target !== statusBtn && !(statusBtn && statusBtn.contains(e.target))) {
                statusDropdown.classList.remove('show');
            }
            if (assigneeDropdown && !assigneeDropdown.contains(e.target) && e.target !== assigneeBtn && !(assigneeBtn && assigneeBtn.contains(e.target))) {
                assigneeDropdown.classList.remove('show');
            }
        });
    };

    // ========== ФИЛЬТРЫ (INPUT/CHANGE) ==========

    window.initFilters = function() {
        var searchInput = document.getElementById('task-search');
        var sortBy = document.getElementById('sort-by');
        var perPageSelect = document.getElementById('per-page');

        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                if (window.loadDebounceTimer) clearTimeout(window.loadDebounceTimer);
                window.loadDebounceTimer = setTimeout(function() {
                    window.currentSearch = e.target.value;
                    window.saveFilterSettings();
                    if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
                    window.loadProjectTasks(true);
                }, 500);
            });
        }

        if (sortBy) {
            sortBy.addEventListener('change', function(e) {
                window.currentSortBy = e.target.value;
                window.saveFilterSettings();
                if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
                window.loadProjectTasks(true);
            });
        }

        if (perPageSelect) {
            perPageSelect.addEventListener('change', function(e) {
                window.currentPerPage = parseInt(e.target.value, 10);
                localStorage.setItem('tasks_per_page', window.currentPerPage);
                window.saveFilterSettings();
                if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
                window.loadProjectTasks(true);
            });
        }
    };

    // ========== ПАГИНАЦИЯ ==========

    window.renderPagination = function(total, currentPage, perPage) {
        var paginationDiv = document.getElementById('tasks-pagination');
        if (!paginationDiv) return;
        var totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) {
            paginationDiv.style.display = 'none';
            return;
        }
        paginationDiv.style.display = 'flex';

        var html = '';
        if (currentPage > 1) html += '<button class="btn-secondary" onclick="goToPage(' + (currentPage - 1) + ')">◀ Назад</button>';
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(totalPages, currentPage + 2);
        if (startPage > 1) {
            html += '<button class="btn-secondary" onclick="goToPage(1)">1</button>';
            if (startPage > 2) html += '<span style="color: rgba(233,238,252,.5);">...</span>';
        }
        for (var i = startPage; i <= endPage; i++) {
            var isActive = (i === currentPage);
            html += '<button class="' + (isActive ? 'btn-primary' : 'btn-secondary') + '" onclick="goToPage(' + i + ')">' + i + '</button>';
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += '<span style="color: rgba(233,238,252,.5);">...</span>';
            html += '<button class="btn-secondary" onclick="goToPage(' + totalPages + ')">' + totalPages + '</button>';
        }
        if (currentPage < totalPages) html += '<button class="btn-secondary" onclick="goToPage(' + (currentPage + 1) + ')">Вперёд ▶</button>';
        var startItem = (currentPage - 1) * perPage + 1;
        var endItem = Math.min(currentPage * perPage, total);
        html += '<span class="pagination-info">' + startItem + '-' + endItem + ' из ' + total + '</span>';
        paginationDiv.innerHTML = html;
    };

    window.goToPage = function(page) {
        window.currentTaskPage = page;
        window.loadProjectTasks(false);
    };

    // ========== ВЫБОР ПРОЕКТА ==========

    window.selectProject = function(uuid) {
        window.currentProjectUuid = uuid;

        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('loading', '⏳ Загрузка проекта...', true);
        }

        var badge = document.getElementById('project-badge-' + uuid);
        if (badge) {
            badge.style.display = 'none';
        }

        var formData = new URLSearchParams();
        formData.append('action', 'mark_project_notifications_read');
        formData.append('project_uuid', uuid);
        formData.append('ajax_mode', '1');
        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(formData);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                logDebugWrapper('[SELECT_PROJECT] Project notifications marked as read:', data.marked_count || 0);
            }
        })
        .catch(function(err) {
            logErrorWrapper('[SELECT_PROJECT] Error marking notifications as read:', err);
        });

        window.loadedFilesCache = {};

        document.querySelectorAll('.project-card').forEach(function(card) {
            card.classList.toggle('selected', card.dataset.projectUuid === uuid);
        });
        document.getElementById('tasks-area').style.display = 'block';
        var project = window.projectsData.find(function(p) { return p.uuid === uuid; });
        if (project) {
            document.getElementById('selected-project-title').innerHTML = escapeHtml(project.title) + ' <span style="font-size:13px;color:rgba(233,238,252,.6);font-weight:normal">- задачи проекта</span>';
        }

        if (typeof window.checkTaskCreationPermission === 'function') window.checkTaskCreationPermission(uuid);
        if (typeof window.loadProjectUsersForFilter === 'function') window.loadProjectUsersForFilter(uuid);

        if (window.selectedTaskUuid) {
            var taskFormData = new URLSearchParams();
            taskFormData.append('action', 'get_task_page_info');
            taskFormData.append('task_uuid', window.selectedTaskUuid);
            taskFormData.append('project_uuid', uuid);
            taskFormData.append('per_page', window.currentPerPage);
            taskFormData.append('sort_by', window.currentSortBy);
            taskFormData.append('sort_dir', window.currentSortDir);
            taskFormData.append('filter_statuses', JSON.stringify(window.currentFilterStatuses));
            taskFormData.append('filter_assigned', JSON.stringify(window.currentFilterAssigned));
            taskFormData.append('search', window.currentSearch);
            taskFormData.append('ajax_mode', '1');
            if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(taskFormData);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: taskFormData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.page) {
                    window.currentTaskPage = data.page;
                    window.loadProjectTasks(false);

                    setTimeout(function() {
                        var chainFormData = new URLSearchParams();
                        chainFormData.append('action', 'get_task_info');
                        chainFormData.append('task_uuid', window.selectedTaskUuid);
                        chainFormData.append('ajax_mode', '1');
                        if (typeof addCsrfToUrlParams === 'function') addCsrfToUrlParams(chainFormData);

                        fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: chainFormData
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(taskData) {
                            if (taskData.success && taskData.task) {
                                var parentChain = taskData.task.parent_chain || [];
                                if (parentChain.length > 0) {
                                    window.expandParentsChainInDom(parentChain, window.selectedTaskUuid, function() {
                                        setTimeout(function() {
                                            window.findAndHighlightTask(window.selectedTaskUuid, 30, 0);
                                        }, 500);
                                    });
                                } else {
                                    window.findAndHighlightTask(window.selectedTaskUuid, 30, 0);
                                }
                            } else {
                                window.findAndHighlightTask(window.selectedTaskUuid, 30, 0);
                            }
                        })
                        .catch(function(err) {
                            logErrorWrapper('[SELECT_PROJECT] Error getting parent chain:', err);
                            window.findAndHighlightTask(window.selectedTaskUuid, 30, 0);
                        });
                    }, 1500);
                } else {
                    window.resetFiltersAndLoad();
                }
            })
            .catch(function(err) {
                logErrorWrapper('[SELECT_PROJECT] Error getting task page:', err);
                window.resetFiltersAndLoad();
            });
        } else {
            window.resetFiltersAndLoad();
        }

        setTimeout(function() {
            if (typeof window.setPageLoadStatus === 'function') {
                var indicator = document.getElementById('page-load-indicator');
                if (indicator && indicator.style.opacity !== '0') {
                    window.setPageLoadStatus('ready', '✓ Загрузка завершена', false);
                }
            }
        }, 8000);

        setTimeout(function() {
            var tasksArea = document.getElementById('tasks-area');
            if (tasksArea) tasksArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 300);
    };

    window.resetFiltersAndLoad = function() {
        window.currentTaskPage = 1;
        window.currentSearch = '';
        window.currentFilterStatuses = [];
        window.currentFilterAssigned = [];
        window.currentSortBy = 'last_activity';
        window.currentSortDir = 'DESC';
        var savedPerPage = localStorage.getItem('tasks_per_page');
        window.currentPerPage = savedPerPage ? parseInt(savedPerPage) : 10;

        var searchInput = document.getElementById('task-search');
        var sortBy = document.getElementById('sort-by');
        var perPageSelect = document.getElementById('per-page');
        var statusDropdown = document.getElementById('status-filter-dropdown');
        var assigneeDropdown = document.getElementById('assignee-filter-dropdown');

        if (searchInput) searchInput.value = '';
        if (sortBy) sortBy.value = 'last_activity';
        if (perPageSelect) perPageSelect.value = window.currentPerPage.toString();
        if (statusDropdown) {
            var statusCbs = statusDropdown.querySelectorAll('input[type="checkbox"]');
            statusCbs.forEach(function(cb) { cb.checked = false; });
        }
        if (assigneeDropdown) {
            var assigneeCbs = assigneeDropdown.querySelectorAll('input[type="checkbox"]');
            assigneeCbs.forEach(function(cb) { cb.checked = false; });
        }

        window.updateStatusFilter();
        window.updateAssigneeFilter();

        if (typeof window.resetSubtasksCache === 'function') window.resetSubtasksCache();
        window.saveFilterSettings();
        window.loadProjectTasks(true);
    };

    // ========== ПРОЕКТ: МОДАЛЫ ==========

    window.showCreateProjectModal = function() {
        var createProjectBtn = document.getElementById('create-project-btn');
        if (createProjectBtn && createProjectBtn.style.display === 'none') {
            window.showAlert('У вас нет прав на создание проектов', 'warning');
            return;
        }

        document.getElementById('project-modal-title').innerText = 'Создание проекта';
        document.getElementById('project-uuid').value = '';
        document.getElementById('project-title').value = '';
        document.getElementById('project-descr').value = '';
        var projectDeleteSection = document.getElementById('project-delete-section');
        if (projectDeleteSection) projectDeleteSection.style.display = 'none';
        document.getElementById('project-modal').classList.add('active');
    };

    window.editProject = function(uuid) {
        var p = window.projectsData.find(function(x) { return x.uuid === uuid; });
        if (!p) return;
        document.getElementById('project-modal-title').innerText = 'Редактирование проекта';
        document.getElementById('project-uuid').value = p.uuid;
        document.getElementById('project-title').value = p.title;
        document.getElementById('project-descr').value = p.descr || '';
        var projectDeleteSection = document.getElementById('project-delete-section');
        if (projectDeleteSection) projectDeleteSection.style.display = 'block';
        document.getElementById('project-modal').classList.add('active');
    };

    window.saveProject = function(e) {
        e.preventDefault();
        var uuid = document.getElementById('project-uuid').value.trim();
        var title = document.getElementById('project-title').value.trim();
        var descr = document.getElementById('project-descr').value.trim();
        if (!title) { window.showAlert('Название обязательно', 'warning'); return; }
        var action = uuid ? 'edit_project' : 'create_project';
        var formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('uuid', uuid);
        formData.append('title', title);
        formData.append('descr', descr);
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
                window.closeModal('project-modal');
                window.showAlert('Проект "' + title + '" ' + (action === 'create_project' ? 'создан' : 'сохранён'), 'success');
                location.reload();
            } else { window.showAlert('Ошибка: ' + (d.error || 'Неизвестная'), 'error'); }
        })
        .catch(function(err) { logErrorWrapper(err); window.showAlert('Ошибка сохранения', 'error'); });
    };

    window.deleteProjectWithConfirm = function() {
        var confirmValue = document.getElementById('project-delete-confirm').value;
        if (confirmValue !== 'DELETE') { window.showAlert('Для подтверждения удаления введите слово DELETE', 'error'); return; }
        if (!confirm('ВНИМАНИЕ! Это действие НЕОБРАТИМО. Вы уверены, что хотите удалить проект и все его данные?')) return;
        var uuid = document.getElementById('project-uuid').value.trim();
        var formData = new URLSearchParams();
        formData.append('action', 'delete_project');
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
                window.closeModal('project-modal');
                window.showAlert('Проект удалён', 'success');
                location.reload();
            } else { window.showAlert('Ошибка: ' + d.error, 'error'); }
        });
    };

    // ========== БЕЙДЖИ ПРОЕКТОВ ==========

    window.updateProjectBadge = function(projectUuid, count) {
        var badge = document.getElementById('project-badge-' + projectUuid);
        var countSpan = document.getElementById('badge-count-' + projectUuid);

        if (!badge || !countSpan) {
            return;
        }

        var countNum = parseInt(count, 10) || 0;

        if (countNum > 0) {
            countSpan.textContent = countNum > 99 ? '99+' : countNum;
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    };

    window.scrollToNotifications = function(projectUuid) {
        var projectCard = document.querySelector('.project-card[data-project-uuid="' + projectUuid + '"]');
        if (projectCard) {
            projectCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            projectCard.classList.add('block-highlight');
            setTimeout(function() {
                projectCard.classList.remove('block-highlight');
            }, 2000);
        }

        window.selectProject(projectUuid);
    };

    window.refreshAllProjectBadges = function() {
        var csrfToken = window.csrfToken || '';
        var url = window.APP_BASE + '/get_project_badges.php';

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
                for (var projectUuid in data.badges) {
                    if (data.badges.hasOwnProperty(projectUuid)) {
                        window.updateProjectBadge(projectUuid, data.badges[projectUuid]);
                    }
                }
            }
        })
        .catch(function(err) {
            logErrorWrapper('[PROJECT_BADGE] Error fetching badges:', err);
        });
    };

    // ========== ОБНОВЛЕНИЕ БЕЙДЖА СТАТУСА ПРИ СМЕНЕ ПОЛЬЗОВАТЕЛЯ ==========

    window.updateBadgeStatus = function() {
        var select = document.getElementById('targetUser');
        if (!select) return;

        var selected = select.options[select.selectedIndex];
        var hasSub = selected && selected.getAttribute('data-has-subscription') === '1';
        var subTypes = selected ? (selected.getAttribute('data-sub-types') || '') : '';
    };

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
    } else {
        setInterval(function() {
            if (typeof window.refreshAllProjectBadges === 'function') {
                window.refreshAllProjectBadges();
            }
        }, 60000);
    }

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            setTimeout(function() {
                if (typeof window.refreshAllProjectBadges === 'function') {
                    window.refreshAllProjectBadges();
                }
            }, 1000);
        }
    });

    setTimeout(function() {
        if (typeof window.refreshAllProjectBadges === 'function') {
            window.refreshAllProjectBadges();
        }
    }, 2000);

    logDebugWrapper('[PROJECTS_RENDER] projects_render.js v1.0.0 initialized');
})();