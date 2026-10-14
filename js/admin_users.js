/**
 * admin_users.js
 * Version: 1.0.0
 * Changelog:
 * - 1.0.0 (2026-10-14): Initial split from admin.php. Extracted user-management and
 *                       profile-related client logic: notification diagnostics, permission
 *                       request, status display, user CRUD (editUser, resetPassword, deleteUser),
 *                       email masking helpers.
 *
 * Требует глобально установленных переменных:
 *   window.APP_BASE, window.csrfToken, window.currentUserUuid, window.currentUserIsAdmin
 * Использует showToast() из toast.js и logDebug()/logError() из logger.js.
 */

(function () {
    'use strict';

    // ============================================================
    // CSRF helpers (публикуются на window для использования в других скриптах)
    // ============================================================
    window.csrfToken = window.csrfToken || '';

    window.addCsrfToFormData = function (formData) {
        if (formData instanceof FormData) {
            formData.append('csrf_token', window.csrfToken);
        } else if (formData instanceof URLSearchParams) {
            formData.append('csrf_token', window.csrfToken);
        } else if (typeof formData === 'object' && formData !== null) {
            formData.csrf_token = window.csrfToken;
        }
    };

    window.addCsrfToUrlParams = function (params) {
        if (params instanceof URLSearchParams) {
            params.append('csrf_token', window.csrfToken);
        } else if (typeof params === 'object' && params !== null) {
            params.csrf_token = window.csrfToken;
        }
    };

    window.addCsrfToObject = function (obj) {
        obj.csrf_token = window.csrfToken;
        return obj;
    };

    // ============================================================
    // ДИАГНОСТИКА УВЕДОМЛЕНИЙ
    // ============================================================
    window.runNotificationDiagnostics = function () {
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] ========== STARTING NOTIFICATION DIAGNOSTICS ==========');

        var results = [];

        results.push({ test: 'Notification API', passed: 'Notification' in window, value: typeof Notification });
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Notification API:', 'Notification' in window);

        if ('Notification' in window) {
            results.push({ test: 'Notification permission', passed: true, value: Notification.permission });
            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Notification permission:', Notification.permission);
        }

        results.push({ test: 'Service Worker support', passed: 'serviceWorker' in navigator, value: 'serviceWorker' in navigator });
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Service Worker support:', 'serviceWorker' in navigator);

        if ('serviceWorker' in navigator) {
            results.push({ test: 'Service Worker controller', passed: !!navigator.serviceWorker.controller, value: !!navigator.serviceWorker.controller });
            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Service Worker controller:', !!navigator.serviceWorker.controller);
        }

        results.push({ test: 'checkNotificationSupport function', passed: typeof window.checkNotificationSupport === 'function', value: typeof window.checkNotificationSupport });
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] checkNotificationSupport:', typeof window.checkNotificationSupport === 'function');

        results.push({ test: 'showServiceWorkerNotification function', passed: typeof window.showServiceWorkerNotification === 'function', value: typeof window.showServiceWorkerNotification });
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] showServiceWorkerNotification:', typeof window.showServiceWorkerNotification === 'function');

        results.push({ test: 'showMobileNotification function', passed: typeof window.showMobileNotification === 'function', value: typeof window.showMobileNotification });
        if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] showMobileNotification:', typeof window.showMobileNotification === 'function');

        if (typeof window.checkNotificationSupport === 'function') {
            var support = window.checkNotificationSupport();
            results.push({ test: 'checkNotificationSupport result', passed: support.supported, value: JSON.stringify(support) });
            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Support result:', support);
        }

        if (typeof window.showServiceWorkerNotification === 'function') {
            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Sending test notification...');
            try {
                window.showServiceWorkerNotification(
                    '🔔 Тест уведомлений',
                    'Если вы видите это уведомление, система работает корректно!',
                    window.APP_BASE + '/messages.php',
                    null
                );
                results.push({ test: 'Test notification sent', passed: true, value: 'Sent' });
            } catch (e) {
                results.push({ test: 'Test notification failed', passed: false, value: e.message });
                if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Test notification error:', e.message);
            }
        } else if (typeof window.showMobileNotification === 'function') {
            window.showMobileNotification(
                '🔔 Тест уведомлений',
                'Если вы видите это уведомление, система работает корректно!',
                window.APP_BASE + '/messages.php',
                null
            );
            results.push({ test: 'Test notification sent (mobile)', passed: true, value: 'Sent' });
        } else {
            results.push({ test: 'Test notification', passed: false, value: 'No notification function available' });
            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] No notification function available');
        }

        if ('serviceWorker' in navigator && navigator.serviceWorker.ready) {
            navigator.serviceWorker.ready.then(function (registration) {
                if (typeof logDebug === 'function') {
                    logDebug('[DIAGNOSTIC] Service Worker registration active:', !!registration.active);
                    logDebug('[DIAGNOSTIC] Service Worker scope:', registration.scope);
                }
                results.push({ test: 'Service Worker active', passed: !!registration.active, value: registration.scope });
                showDiagnosticResults(results);
            }).catch(function (err) {
                if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC] Service Worker ready error:', err.message);
                results.push({ test: 'Service Worker ready', passed: false, value: err.message });
                showDiagnosticResults(results);
            });
        } else {
            showDiagnosticResults(results);
        }

        function showDiagnosticResults(resultsArray) {
            var message = '🔍 Результаты диагностики:\n';
            var allPassed = true;
            for (var i = 0; i < resultsArray.length; i++) {
                var r = resultsArray[i];
                var status = r.passed ? '✅' : '❌';
                message += status + ' ' + r.test + ': ' + (r.value || (r.passed ? 'OK' : 'FAIL')) + '\n';
                if (!r.passed) allPassed = false;
            }
            message += '\n' + (allPassed ? '✅ Все проверки пройдены!' : '❌ Есть проблемы, проверьте логи выше');

            if (typeof showToast === 'function') {
                showToast(allPassed ? '✅ Диагностика: всё работает' : '❌ Диагностика: есть проблемы', allPassed ? 'success' : 'error');
            }

            if (typeof logDebug === 'function') logDebug('[DIAGNOSTIC]\n' + message);
            alert(message);
        }
    };

    // ============================================================
    // СТАТУС УВЕДОМЛЕНИЙ
    // ============================================================
    window.updateNotificationStatusDisplay = function () {
        var statusText = document.getElementById('notify-status-text');
        if (!statusText) return;

        if (typeof window.checkNotificationSupport === 'function') {
            var support = window.checkNotificationSupport();

            if (!support.supported) {
                statusText.innerHTML = '❌ Ваш браузер не поддерживает уведомления';
                statusText.style.color = '#f87171';
            } else if (support.permission === 'granted') {
                if (support.canShowWhenClosed) {
                    statusText.innerHTML = '✅ Уведомления включены<br><span style="font-size:11px; opacity:0.7;">Уведомления будут приходить даже когда приложение свёрнуто</span>';
                    statusText.style.color = '#4ade80';
                } else {
                    statusText.innerHTML = '✅ Уведомления включены<br><span style="font-size:11px; opacity:0.7;">⚠️ На iOS уведомления работают только когда приложение открыто</span>';
                    statusText.style.color = '#f59e0b';
                }
            } else if (support.permission === 'denied') {
                statusText.innerHTML = '❌ Уведомления запрещены в настройках браузера<br><span style="font-size:11px; opacity:0.7;">Пожалуйста, разрешите уведомления в настройках сайта</span>';
                statusText.style.color = '#f87171';
            } else {
                statusText.innerHTML = '⏳ Уведомления не запрошены<br><span style="font-size:11px; opacity:0.7;">Система запросит разрешение автоматически</span>';
                statusText.style.color = '#60a5fa';
            }
        } else {
            statusText.innerHTML = 'ℹ️ Проверка статуса недоступна';
        }
    };

    // ============================================================
    // ЗАПРОС РАЗРЕШЕНИЯ НА УВЕДОМЛЕНИЯ (РУЧНОЙ)
    // ============================================================
    window.requestNotificationPermissionManual = function () {
        if (typeof logDebug === 'function') logDebug('[NOTIFY_MANUAL] Manual permission request triggered');

        if (!('Notification' in window)) {
            if (typeof showToast === 'function') {
                showToast('Ваш браузер не поддерживает уведомления', 'error');
            } else {
                alert('Ваш браузер не поддерживает уведомления');
            }
            return;
        }

        if (Notification.permission === 'granted') {
            if (typeof showToast === 'function') {
                showToast('Уведомления уже разрешены', 'success');
            } else {
                alert('Уведомления уже разрешены');
            }
            window.updateNotificationStatusDisplay();
            return;
        }

        if (Notification.permission === 'denied') {
            if (typeof showToast === 'function') {
                showToast('Уведомления запрещены в настройках браузера. Пожалуйста, измените настройки вручную.', 'warning');
            } else {
                alert('Уведомления запрещены в настройках браузера. Пожалуйста, измените настройки вручную.');
            }
            return;
        }

        Notification.requestPermission().then(function (permission) {
            if (typeof logDebug === 'function') logDebug('[NOTIFY_MANUAL] Permission result:', permission);

            if (permission === 'granted') {
                if (typeof showToast === 'function') {
                    showToast('✓ Уведомления разрешены!', 'success');
                } else {
                    alert('✓ Уведомления разрешены!');
                }
                window.updateNotificationStatusDisplay();

                if (typeof window.showServiceWorkerNotification === 'function') {
                    setTimeout(function () {
                        window.showServiceWorkerNotification(
                            '🔔 Тест уведомлений',
                            'Уведомления успешно разрешены!',
                            window.APP_BASE + '/messages.php',
                            null
                        );
                    }, 500);
                } else if (typeof window.showMobileNotification === 'function') {
                    setTimeout(function () {
                        window.showMobileNotification(
                            '🔔 Тест уведомлений',
                            'Уведомления успешно разрешены!',
                            window.APP_BASE + '/messages.php',
                            null
                        );
                    }, 500);
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast('Уведомления не были разрешены', 'warning');
                } else {
                    alert('Уведомления не были разрешены');
                }
                window.updateNotificationStatusDisplay();
            }
        }).catch(function (err) {
            if (typeof logError === 'function') logError('[NOTIFY_MANUAL] Error requesting permission:', err);
            if (typeof showToast === 'function') {
                showToast('Ошибка при запросе разрешения', 'error');
            }
        });
    };

    // ============================================================
    // УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ
    // ============================================================
    window.editUser = function (uuid, login, name, email, tel, role, status) {
        var editUuid = document.getElementById('edit_uuid');
        if (editUuid) editUuid.value = uuid;

        var editLogin = document.getElementById('edit_login');
        if (editLogin) editLogin.value = login;

        var editName = document.getElementById('edit_name');
        if (editName) editName.value = name;

        var editEmail = document.getElementById('edit_email');
        if (editEmail) editEmail.value = email;

        var editTel = document.getElementById('edit_tel');
        if (editTel) editTel.value = tel;

        var editRole = document.getElementById('edit_role');
        if (editRole) editRole.value = role;

        var editStatus = document.getElementById('edit_status');
        if (editStatus) editStatus.value = status;

        var editModal = document.getElementById('editUserModal');
        if (editModal) editModal.style.display = 'flex';
    };

    window.resetPassword = function (uuid, login) {
        if (confirm('Сбросить пароль пользователю ' + login + '?')) {
            var resetUuid = document.getElementById('reset_uuid');
            if (resetUuid) resetUuid.value = uuid;

            var form = document.getElementById('resetPasswordForm');
            if (form) form.submit();
        }
    };

    window.deleteUser = function (uuid, login) {
        if (confirm('Удалить пользователя ' + login + '? Это действие необратимо.')) {
            var deleteUuid = document.getElementById('delete_uuid');
            if (deleteUuid) deleteUuid.value = uuid;

            var form = document.getElementById('deleteUserForm');
            if (form) form.submit();
        }
    };

    // ============================================================
    // УТИЛИТА: МАСКИРОВАНИЕ EMAIL НА КЛИЕНТЕ
    // ============================================================
    window.maskEmailJS = function (email) {
        if (!email) return '—';
        var parts = email.split('@');
        if (parts.length !== 2) {
            return '*'.repeat(email.length);
        }
        var username = parts[0];
        var domain = parts[1];
        var maskedUsername = username.length > 1
            ? username[0] + '****' + username[username.length - 1]
            : '*'.repeat(Math.max(1, username.length));
        return maskedUsername + '@' + domain;
    };

    // ============================================================
    // ИНИЦИАЛИЗАЦИЯ (при загрузке страницы)
    // ============================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(window.updateNotificationStatusDisplay, 1500);
        });
    } else {
        setTimeout(window.updateNotificationStatusDisplay, 1500);
    }
})();