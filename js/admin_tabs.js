/**
 * admin_tabs.js
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from admin.php. Extracted permission management,
 *                       subscriber management and subscriber filter logic:
 *                       openCreatePermissionModal, editPermission, deletePermission,
 *                       openCreateSubscriberModal, editSubscriber, deleteSubscriber,
 *                       subscriber filter (IIFE with MutationObserver),
 *                       modal close-on-outside-click.
 *
 * Требует глобально установленных переменных:
 *   window.APP_BASE, window.csrfToken
 * Использует logDebug()/logError() из logger.js.
 */

(function () {
    'use strict';

    // ============================================================
    // ПРАВА ДОСТУПА: МОДАЛЬНОЕ ОКНО
    // ============================================================
    window.openCreatePermissionModal = function () {
        var title = document.getElementById('permissionModalTitle');
        if (title) title.innerText = 'Добавление прав доступа';

        var subAction = document.getElementById('perm_sub_action');
        if (subAction) subAction.value = 'create';

        var permUuid = document.getElementById('perm_uuid');
        if (permUuid) permUuid.value = '';

        var userSelect = document.getElementById('perm_user_uuid');
        if (userSelect) userSelect.value = '';

        var projectSelect = document.getElementById('perm_project_uuid');
        if (projectSelect) projectSelect.value = '';

        var cbView = document.getElementById('perm_can_view');
        if (cbView) cbView.checked = true;

        var cbEditTasks = document.getElementById('perm_can_edit_tasks');
        if (cbEditTasks) cbEditTasks.checked = false;

        var cbEditMessages = document.getElementById('perm_can_edit_messages');
        if (cbEditMessages) cbEditMessages.checked = false;

        var cbUpload = document.getElementById('perm_can_upload_files');
        if (cbUpload) cbUpload.checked = false;

        var cbCreate = document.getElementById('perm_can_create_projects');
        if (cbCreate) cbCreate.checked = false;

        var cbEditOwn = document.getElementById('perm_can_edit_own_projects');
        if (cbEditOwn) cbEditOwn.checked = false;

        var modal = document.getElementById('permissionModal');
        if (modal) modal.style.display = 'flex';
    };

    window.editPermission = function (uuid, user_uuid, project_uuid, can_view, can_edit_tasks, can_edit_messages, can_upload_files) {
        var title = document.getElementById('permissionModalTitle');
        if (title) title.innerText = 'Редактирование прав доступа';

        var subAction = document.getElementById('perm_sub_action');
        if (subAction) subAction.value = 'update';

        var permUuid = document.getElementById('perm_uuid');
        if (permUuid) permUuid.value = uuid;

        var userSelect = document.getElementById('perm_user_uuid');
        if (userSelect) userSelect.value = user_uuid;

        var projectSelect = document.getElementById('perm_project_uuid');
        if (projectSelect) projectSelect.value = project_uuid;

        var cbView = document.getElementById('perm_can_view');
        if (cbView) cbView.checked = (can_view == 1);

        var cbEditTasks = document.getElementById('perm_can_edit_tasks');
        if (cbEditTasks) cbEditTasks.checked = (can_edit_tasks == 1);

        var cbEditMessages = document.getElementById('perm_can_edit_messages');
        if (cbEditMessages) cbEditMessages.checked = (can_edit_messages == 1);

        var cbUpload = document.getElementById('perm_can_upload_files');
        if (cbUpload) cbUpload.checked = (can_upload_files == 1);

        // Асинхронная загрузка дополнительных прав
        var formData = new URLSearchParams();
        formData.append('action', 'get_permission_details');
        formData.append('perm_uuid', uuid);
        formData.append('ajax_mode', '1');
        if (typeof window.addCsrfToUrlParams === 'function') {
            window.addCsrfToUrlParams(formData);
        }

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                var cbCreate = document.getElementById('perm_can_create_projects');
                if (cbCreate) cbCreate.checked = (d.can_create_projects == 1);

                var cbEditOwn = document.getElementById('perm_can_edit_own_projects');
                if (cbEditOwn) cbEditOwn.checked = (d.can_edit_own_projects == 1);

                if (typeof logDebug === 'function') {
                    logDebug('[PERMISSION_EDIT] Loaded extra permissions - create_projects:', d.can_create_projects, 'edit_own_projects:', d.can_edit_own_projects);
                }
            }
        })
        .catch(function (err) {
            if (typeof logDebug === 'function') {
                logDebug('[PERMISSION_EDIT] Could not load extra permissions:', err);
            }
        });

        var modal = document.getElementById('permissionModal');
        if (modal) modal.style.display = 'flex';
    };

    window.deletePermission = function (uuid, userName, projectTitle) {
        if (confirm('Удалить права доступа пользователя "' + userName + '" к проекту "' + projectTitle + '"?')) {
            var deletePermUuid = document.getElementById('delete_perm_uuid');
            if (deletePermUuid) deletePermUuid.value = uuid;

            var form = document.getElementById('deletePermissionForm');
            if (form) form.submit();
        }
    };

    // ============================================================
    // ПОДПИСЧИКИ: МОДАЛЬНОЕ ОКНО
    // ============================================================
    window.openCreateSubscriberModal = function () {
        var title = document.getElementById('subscriberModalTitle');
        if (title) title.innerText = 'Добавление подписки';

        var subAction = document.getElementById('sub_sub_action');
        if (subAction) subAction.value = 'create';

        var subId = document.getElementById('subscriber_id');
        if (subId) subId.value = '';

        var taskUuid = document.getElementById('sub_task_uuid');
        if (taskUuid) taskUuid.value = '';

        var userUuid = document.getElementById('sub_user_uuid');
        if (userUuid) userUuid.value = '';

        var isActive = document.getElementById('sub_is_active');
        if (isActive) isActive.checked = true;

        var modal = document.getElementById('subscriberModal');
        if (modal) modal.style.display = 'flex';
    };

    window.editSubscriber = function (id, taskUuid, userUuid, isActive) {
        var title = document.getElementById('subscriberModalTitle');
        if (title) title.innerText = 'Редактирование подписки';

        var subAction = document.getElementById('sub_sub_action');
        if (subAction) subAction.value = 'update';

        var subId = document.getElementById('subscriber_id');
        if (subId) subId.value = id;

        var taskSelect = document.getElementById('sub_task_uuid');
        if (taskSelect) taskSelect.value = taskUuid;

        var userSelect = document.getElementById('sub_user_uuid');
        if (userSelect) userSelect.value = userUuid;

        var cbActive = document.getElementById('sub_is_active');
        if (cbActive) cbActive.checked = (isActive == 1);

        var modal = document.getElementById('subscriberModal');
        if (modal) modal.style.display = 'flex';
    };

    window.deleteSubscriber = function (id, taskTitle, userName) {
        if (confirm('Удалить подписку пользователя "' + userName + '" на задачу "' + taskTitle + '"?')) {
            var deleteSubId = document.getElementById('delete_subscriber_id');
            if (deleteSubId) deleteSubId.value = id;

            var form = document.getElementById('deleteSubscriberForm');
            if (form) form.submit();
        }
    };

    // ============================================================
    // ЗАКРЫТИЕ МОДАЛЬНЫХ ОКОН ПО КЛИКУ ВНЕ
    // ============================================================
    window.addEventListener('click', function (event) {
        if (event.target.classList && event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });

    // ============================================================
    // ФИЛЬТР ПОДПИСЧИКОВ
    // ============================================================
    (function initSubscriberFilter() {
        var searchInput = document.getElementById('subscriberSearchInput');
        var clearBtn = document.getElementById('subscriberSearchClear');
        var tbody = document.getElementById('subscribersTableBody');
        var noSubscribersRow = document.getElementById('noSubscribersRow');
        var searchInfo = document.getElementById('subscriberSearchInfo');
        var filteredCountSpan = document.getElementById('filteredCount');
        var totalCountSpan = document.getElementById('totalCount');

        if (!searchInput || !tbody) return;

        var allRows = [];
        var totalRows = 0;

        function collectRows() {
            allRows = [];
            if (tbody) {
                var rows = tbody.querySelectorAll('tr');
                rows.forEach(function (row) {
                    if (row.id === 'noSubscribersRow') return;
                    if (row.parentElement === tbody) {
                        allRows.push(row);
                    }
                });
            }
            totalRows = allRows.length;
            if (totalCountSpan) totalCountSpan.textContent = totalRows;
        }

        function filterSubscribers() {
            var searchTerm = searchInput.value.toLowerCase().trim();

            if (!tbody) return;

            var visibleCount = 0;

            if (searchTerm === '') {
                allRows.forEach(function (row) {
                    row.style.display = '';
                });
                visibleCount = allRows.length;

                if (clearBtn) clearBtn.style.display = 'none';
                if (searchInfo) searchInfo.style.display = 'none';
            } else {
                allRows.forEach(function (row) {
                    var taskName = (row.getAttribute('data-task') || '').toLowerCase();
                    var userName = (row.getAttribute('data-user') || '').toLowerCase();
                    var subscribedBy = (row.getAttribute('data-subscribed-by') || '').toLowerCase();
                    var status = (row.getAttribute('data-status') || '').toLowerCase();

                    var matches = taskName.indexOf(searchTerm) !== -1 ||
                                  userName.indexOf(searchTerm) !== -1 ||
                                  subscribedBy.indexOf(searchTerm) !== -1 ||
                                  status.indexOf(searchTerm) !== -1;

                    if (matches) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (clearBtn) clearBtn.style.display = 'inline-block';
                if (searchInfo) {
                    searchInfo.style.display = 'block';
                    if (filteredCountSpan) filteredCountSpan.textContent = visibleCount;
                }
            }

            var noDataRow = document.getElementById('noSubscribersRow');
            if (visibleCount === 0 && allRows.length > 0) {
                if (!noDataRow) {
                    var newRow = document.createElement('tr');
                    newRow.id = 'noSubscribersRow';
                    newRow.innerHTML = '<td colspan="6" style="text-align:center; padding:40px;">🔍 Подписки не найдены</td>';
                    if (tbody) tbody.appendChild(newRow);
                } else {
                    noDataRow.style.display = '';
                }
            } else {
                if (noDataRow) noDataRow.style.display = 'none';
            }
        }

        window.clearSubscriberSearch = function () {
            if (searchInput) {
                searchInput.value = '';
                filterSubscribers();
                searchInput.focus();
            }
        };

        var debounceTimer;
        function debouncedFilter() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(filterSubscribers, 250);
        }

        collectRows();

        if (searchInput) {
            searchInput.addEventListener('input', debouncedFilter);
            searchInput.addEventListener('keyup', function (e) {
                if (e.key === 'Escape') {
                    window.clearSubscriberSearch();
                }
            });
        }

        var observer = new MutationObserver(function () {
            collectRows();
            filterSubscribers();
        });

        if (tbody) {
            observer.observe(tbody, { childList: true, subtree: true });
        }
    })();
})();