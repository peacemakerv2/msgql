<?php
/**
 * admin.php
 * Version: 2.0.0
 * Changelog:
 * - 2.0.0 (2026-10-14): Refactored per plan_v2.0. Extracted:
 *                       - CSS → /css/admin.css
 *                       - JS → /js/admin_users.js, /js/admin_tabs.js
 *                       - POST handlers → admin_api.php (process_admin_post, handle_admin_ajax_get_permission_details)
 *                       - Helpers → admin_helpers.php (send_user_change_notification,
 *                         validate_and_save_email, maskEmailFixedStars, get_all_permissions,
 *                         get_all_subscribers, get_subscriber_stats, get_all_system_users,
 *                         get_all_projects_list, check_user_project_permission, check_user_task_permission)
 *                       Main file now contains only entry-point logic, HTML markup, and init.
 * - 1.0.0: Initial version (monolithic).
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/smtp_func.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/admin_api.php';

// ============================================================
// AJAX-обработчик получения деталей прав (выполняется до основного flow)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_permission_details') {
    handle_admin_ajax_get_permission_details(msgql_db());
    exit;
}

if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

msgql_require_login();

if (!$is_login) {
    header('Location: ' . $appBase . "/index.php");
    exit;
}

$active_tab = $_GET['tab'] ?? 'profile';
$error = '';
$success = '';

$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}

$db = msgql_db();
$stmt = $db->prepare("SELECT login, name, email, tel, role, alert_interval_min, alert_days, sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$current_user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ============================================================
// ОБРАБОТКА POST-ЗАПРОСОВ
// ============================================================
$post_result = process_admin_post($db, $current_user_uuid, $is_admin, $current_user_data);
$error = $post_result['error'];
$success = $post_result['success'];
$sound_settings_updated = $post_result['sound_settings'];

// ============================================================
// ПЕРЕЗАГРУЗКА ДАННЫХ ПОЛЬЗОВАТЕЛЯ (после возможных обновлений)
// ============================================================
$stmt = $db->prepare("SELECT login, name, email, tel, role, alert_interval_min, alert_days, sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$current_user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();
$current_user_role = $current_user_data['role'] ?? 2;

if (!isset($_SESSION['user_uuid']) || $_SESSION['user_uuid'] !== $current_user_uuid) {
    header('Location: ' . $appBase . "/index.php?msg=role_changed");
    exit;
}

// ============================================================
// ЗАГРУЗКА ДАННЫХ ДЛЯ ОТОБРАЖЕНИЯ
// ============================================================
$users_list = [];
if ($is_admin) {
    $users_list = get_all_system_users($db);
}

$projects_list = get_all_projects_list($db);

$permissions_list = [];
if ($is_admin) {
    $permissions_list = get_all_permissions($db);
}

$role_names = ['Администратор', 'Менеджер', 'Контролёр'];
$status_names = ['Активный', 'Заблокированный'];

$weekdays = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

$current_alert_days = explode(',', $current_user_data['alert_days'] ?? '1,2,3,4,5');

$csrf_token = msgql_csrf_get_token();

// Подключаем внешние CSS
?>
<link rel="stylesheet" href="<?= $appBase ?>/css/admin.css?v=1.0.0">

<?php
// Инлайн-скрипт: экспорт PHP-данных в window + звуковые настройки после сохранения профиля
?>
<script nonce="<?= CSP_NONCE ?>">
    window.csrfToken = '<?= $csrf_token ?>';
    window.currentUserUuid = '<?= $current_user_uuid ?>';
    window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
</script>
<?php if ($sound_settings_updated): ?>
<script nonce="<?= CSP_NONCE ?>">
(function() {
    if (typeof window.userSettings === 'undefined') {
        window.userSettings = {};
    }

    window.userSettings.soundEnabled = <?= $sound_settings_updated['enabled'] ? 'true' : 'false' ?>;
    window.userSettings.soundIntervalSec = <?= (int)$sound_settings_updated['interval'] ?>;

    sessionStorage.setItem('sse_sound_enabled', window.userSettings.soundEnabled ? '1' : '0');
    sessionStorage.setItem('sse_sound_interval', window.userSettings.soundIntervalSec);

    if (window.SSE && typeof window.SSE.updateSoundSettings === 'function') {
        window.SSE.updateSoundSettings(
            window.userSettings.soundEnabled,
            window.userSettings.soundIntervalSec
        );
    }

    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
            type: 'updateSoundSettings',
            soundEnabled: window.userSettings.soundEnabled,
            soundIntervalSec: window.userSettings.soundIntervalSec
        });
    }

    if (typeof logDebug === 'function') {
        logDebug('[ADMIN] Sound settings saved:', window.userSettings);
    }
})();
</script>
<?php endif; ?>

<div class="wrap">
    <div class="admin-tabs">
        <a href="?tab=profile" class="tab <?= $active_tab === 'profile' ? 'active' : '' ?>">👤 Личные данные</a>
        <?php if ($is_admin): ?>
        <a href="?tab=users" class="tab <?= $active_tab === 'users' ? 'active' : '' ?>">👥 Пользователи</a>
        <a href="?tab=permissions" class="tab <?= $active_tab === 'permissions' ? 'active' : '' ?>">🔒 Права доступа</a>
        <a href="?tab=subscribers" class="tab <?= $active_tab === 'subscribers' ? 'active' : '' ?>">🔔 Подписчики</a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="background:#163a2a; border:1px solid #2a8a5a; color:#a0f0c0; padding:12px; border-radius:10px; margin-bottom:16px;">
            <?= nl2br(htmlspecialchars($success)) ?>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'profile'): ?>

        <?php if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true): ?>
        <div class="card" style="background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div style="font-size: 32px;">⚠️</div>
                <div style="flex: 1;">
                    <h3 style="color: #f87171; margin: 0 0 8px 0;">Внимание! Необходимо установить пароль</h3>
                    <p style="margin: 0; color: rgba(233,238,252,0.9); font-size: 14px;">
                        Ваша учётная запись была создана без пароля. Для безопасности системы,
                        <strong>пожалуйста, установите новый пароль</strong> в форме ниже.
                    </p>
                    <p style="margin: 8px 0 0 0; color: #f87171; font-size: 13px;">
                        ⚠️ Пока вы не установите пароль, доступ к другим разделам будет ограничен.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin:0 0 16px;">Личные данные</h3>

            <form method="post">
                <input type="hidden" name="action" value="update_profile">
                <?= msgql_csrf_form_field() ?>

                <label class="muted">Логин</label>
                <input type="text" name="login" value="<?= htmlspecialchars($current_user_data['login']) ?>" required>

                <div style="height:12px"></div>
                <label class="muted">Имя</label>
                <input type="text" name="name" value="<?= htmlspecialchars($current_user_data['name'] ?? '') ?>">

                <div style="height:12px"></div>
                <label class="muted">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($current_user_data['email'] ?? '') ?>">

                <div style="height:12px"></div>
                <label class="muted">Телефон</label>
                <input type="tel" name="tel" value="<?= htmlspecialchars($current_user_data['tel'] ?? '') ?>">

                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">
                <h4 style="margin:0 0 12px;">📧 Настройки email-уведомлений</h4>

                <label class="muted">Интервал между уведомлениями (минуты)</label>
                <input type="number" name="alert_interval_min" min="3" max="5000" value="<?= $current_user_data['alert_interval_min'] ?? 30 ?>">
                <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">Минимум 3 минуты, максимум 5000 минут (~3.5 дня)</div>

                <div style="height:12px"></div>
                <label class="muted">Дни недели для уведомлений</label>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px;">
                    <?php foreach ($weekdays as $num => $name): ?>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" name="alert_days[]" value="<?= $num ?>" <?= in_array($num, $current_alert_days) ? 'checked' : '' ?>>
                        <span><?= $name ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:6px;">По умолчанию уведомления отправляются только в рабочие дни (ПН-ПТ)</div>

                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">
                <h4 style="margin:0 0 12px;">🔊 Настройки звуковых уведомлений</h4>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="muted" style="display: block; margin-bottom: 6px;">Звуковые уведомления</label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="sound_enabled" value="1" <?= ($current_user_data['sound_enabled'] ?? '1') == '1' ? 'checked' : '' ?> style="margin: 0; width: 18px; height: 18px;">
                        <span>Включить звук при новых сообщениях</span>
                    </label>
                    <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">
                        При отключённой галочке — уведомления приходят без звука, но всплывающие окна и бейджики работают.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="muted" style="display: block; margin-bottom: 6px;">Интервал между звуками (секунды)</label>
                    <input type="number" name="sound_interval_sec" min="6" max="60000" value="<?= $current_user_data['sound_interval_sec'] ?? 600 ?>">
                    <div style="font-size:11px; color:rgba(233,238,252,0.5); margin-top:4px;">
                        Минимум 6 секунд, максимум 60000 секунд.
                        Звук не будет повторяться чаще указанного интервала для всех типов уведомлений (всплывающие, push, мобильные).
                    </div>
                </div>

                <div style="height:20px"></div>
                <hr style="border-color:rgba(255,255,255,0.1); margin:16px 0;">

                <div style="background: rgba(79,124,255,0.08); border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <h4 style="margin:0 0 12px;">📱 Статус уведомлений</h4>

                    <div id="notification-status" style="font-size:13px; color:rgba(233,238,252,0.8); margin-bottom: 12px;">
                        <span id="notify-status-text">⏳ Проверка...</span>
                    </div>

                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="requestNotificationPermissionManual()" style="font-size: 13px; padding: 8px 16px;">
                            🔔 Запросить разрешение на уведомления
                        </button>
                        <button type="button" class="btn-secondary" onclick="runNotificationDiagnostics()" style="font-size: 12px; padding: 8px 12px;">
                            🔍 Диагностика уведомлений
                        </button>
                    </div>
                </div>

                <button type="submit" style="margin-top: 20px;">Сохранить изменения личных настроек</button>
            </form>
        </div>

        <div class="card" style="margin-top:20px;">
            <h3 style="margin:0 0 16px;">Смена пароля</h3>

            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <?= msgql_csrf_form_field() ?>

                <div style="margin-bottom: 12px;">
                    <label class="muted">Для логина:</label>
                    <input type="text" name="display_login" value="<?= htmlspecialchars($current_user_data['login'] ?? '') ?>"
                           readonly disabled
                           autocomplete="username"
                           style="background: rgba(255,255,255,0.05); cursor: not-allowed;">
                </div>

                <label class="muted">Новый пароль</label>
                <input type="password" name="new_pass" autocomplete="new-password" required minlength="10">

                <div style="height:12px"></div>
                <label class="muted">Подтверждение пароля</label>
                <input type="password" name="confirm_pass" autocomplete="new-password" required>

                <div style="height:20px"></div>
                <button type="submit">Сменить пароль</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($active_tab === 'users' && $is_admin): ?>
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">👥 Пользователи системы</h3>
                <button class="btn-primary" onclick="document.getElementById('createUserModal').style.display='flex'">+ Создать пользователя</button>
            </div>

            <div class="users-table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr><th>Логин</th><th>Имя</th><th>Email</th><th>Телефон</th><th>Роль</th><th>Статус</th><th>Создан</th><th>Действия</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_list as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['login']) ?></td>
                            <td><?= htmlspecialchars($user['name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars(maskEmailFixedStars($user['email'] ?? '—')) ?></td>
                            <td><?= htmlspecialchars($user['tel'] ?? '—') ?></td>
                            <td><?= $role_names[$user['role']] ?? '?' ?></td>
                            <td class="status-<?= $user['status'] == 0 ? 'active' : 'blocked' ?>">
                                <?= $status_names[$user['status']] ?? '?' ?>
                            </td>
                            <td><?= htmlspecialchars($user['stamp']) ?></td>
                            <td class="actions">
                                <button class="btn-icon" onclick="editUser('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>', '<?= addslashes($user['name'] ?? '') ?>', '<?= addslashes($user['email'] ?? '') ?>', '<?= addslashes($user['tel'] ?? '') ?>', <?= $user['role'] ?>, <?= $user['status'] ?>)">✏️</button>
                                <button class="btn-icon" onclick="resetPassword('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>')">🔑</button>
                                <?php if ($user['uuid'] !== $current_user_uuid): ?>
                                <button class="btn-icon delete" onclick="deleteUser('<?= $user['uuid'] ?>', '<?= addslashes($user['login']) ?>')">🗑️</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="createUserModal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close" onclick="document.getElementById('createUserModal').style.display='none'">&times;</span>
                <h3>Создание пользователя</h3>
                <form method="post" id="createUserForm">
                    <input type="hidden" name="action" value="user_update">
                    <input type="hidden" name="sub_action" value="create">
                    <?= msgql_csrf_form_field() ?>

                    <label class="muted">Логин *</label>
                    <input type="text" name="login" required>

                    <label class="muted">Имя</label>
                    <input type="text" name="name">

                    <label class="muted">Email</label>
                    <input type="email" name="email">

                    <label class="muted">Телефон</label>
                    <input type="tel" name="tel">

                    <label class="muted">Роль</label>
                    <select name="role">
                        <option value="0">Администратор</option>
                        <option value="1">Менеджер</option>
                        <option value="2" selected>Контролёр</option>
                    </select>

                    <div style="margin-top:20px;">
                        <button type="submit">Создать</button>
                        <button type="button" onclick="document.getElementById('createUserModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="editUserModal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close" onclick="document.getElementById('editUserModal').style.display='none'">&times;</span>
                <h3>Редактирование пользователя</h3>
                <form method="post" id="editUserForm">
                    <input type="hidden" name="action" value="user_update">
                    <input type="hidden" name="sub_action" value="update">
                    <input type="hidden" name="user_uuid" id="edit_uuid">
                    <?= msgql_csrf_form_field() ?>

                    <label class="muted">Логин *</label>
                    <input type="text" name="login" id="edit_login" required>

                    <label class="muted">Имя</label>
                    <input type="text" name="name" id="edit_name">

                    <label class="muted">Email</label>
                    <input type="email" name="email" id="edit_email">

                    <label class="muted">Телефон</label>
                    <input type="tel" name="tel" id="edit_tel">

                    <label class="muted">Роль</label>
                    <select name="role" id="edit_role">
                        <option value="0">Администратор</option>
                        <option value="1">Менеджер</option>
                        <option value="2">Контролёр</option>
                    </select>

                    <label class="muted">Статус</label>
                    <select name="status" id="edit_status">
                        <option value="0">Активный</option>
                        <option value="2">Заблокированный</option>
                    </select>

                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('editUserModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>

        <form method="post" id="resetPasswordForm" style="display:none;">
            <input type="hidden" name="action" value="user_update">
            <input type="hidden" name="sub_action" value="reset_password">
            <input type="hidden" name="user_uuid" id="reset_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>

        <form method="post" id="deleteUserForm" style="display:none;">
            <input type="hidden" name="action" value="user_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="user_uuid" id="delete_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>
    <?php endif; ?>

    <?php if ($active_tab === 'permissions' && $is_admin): ?>
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">🔒 Права доступа к проектам</h3>
                <button class="btn-primary" onclick="openCreatePermissionModal()">+ Добавить права</button>
            </div>

            <div class="users-table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Проект</th>
                            <th>Просмотр</th>
                            <th>Создание проектов</th>
                            <th>Правка своих проектов</th>
                            <th>Правка задач</th>
                            <th>Сообщения</th>
                            <th>Загрузка файлов</th>
                            <th>Кем выдано</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions_list as $perm): ?>
                        <tr>
                            <td><?= htmlspecialchars($perm['user_name'] ?: $perm['user_login']) ?></td>
                            <td><?= htmlspecialchars($perm['project_title']) ?></td>
                            <td class="status-<?= $perm['can_view'] ? 'active' : 'blocked' ?>"><?= $perm['can_view'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_create_projects'] ? 'active' : 'blocked' ?>"><?= $perm['can_create_projects'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_own_projects'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_own_projects'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_tasks'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_tasks'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_edit_messages'] ? 'active' : 'blocked' ?>"><?= $perm['can_edit_messages'] ? '✅ Да' : '❌ Нет' ?></td>
                            <td class="status-<?= $perm['can_upload_files'] ? 'active' : 'blocked' ?>"><?= $perm['can_upload_files'] ? '✅ Да' : '❌ Нет' ?></td>

                            <td><?= htmlspecialchars($perm['granted_by_name'] ?: $perm['granted_by_login']) ?></td>
                            <td class="actions">
                                <button class="btn-icon" onclick="editPermission('<?= $perm['uuid'] ?>', '<?= $perm['user_uuid'] ?>', '<?= $perm['project_uuid'] ?>', <?= $perm['can_view'] ?>, <?= $perm['can_edit_tasks'] ?>, <?= $perm['can_edit_messages'] ?>, <?= $perm['can_upload_files'] ?>)">✏️</button>
                                <button class="btn-icon delete" onclick="deletePermission('<?= $perm['uuid'] ?>', '<?= addslashes($perm['user_name'] ?: $perm['user_login']) ?>', '<?= addslashes($perm['project_title']) ?>')">🗑️</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($permissions_list)): ?>
                        <tr><td colspan="10" style="text-align:center; padding:40px;">Нет назначенных прав доступа</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="permissionModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 550px;">
                <span class="close" onclick="document.getElementById('permissionModal').style.display='none'">&times;</span>
                <h3 id="permissionModalTitle">Добавление прав доступа</h3>
                <form method="post" id="permissionForm">
                    <input type="hidden" name="action" value="permission_update">
                    <input type="hidden" name="sub_action" id="perm_sub_action" value="create">
                    <input type="hidden" name="perm_uuid" id="perm_uuid" value="">
                    <?= msgql_csrf_form_field() ?>

                    <label class="muted">Пользователь *</label>
                    <select name="user_uuid" id="perm_user_uuid" required>
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= $user['uuid'] ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?> (<?= $role_names[$user['role']] ?>)</option>
                        <?php endforeach; ?>
                    </select>

                    <label class="muted" style="margin-top:12px;">Проект *</label>
                    <select name="project_uuid" id="perm_project_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите проект --</option>
                        <?php foreach ($projects_list as $project): ?>
                        <option value="<?= $project['uuid'] ?>"><?= htmlspecialchars($project['title']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="permissions-group">
                        <div class="perm-item">
                            <input type="checkbox" name="can_view" id="perm_can_view" value="1" checked>
                            <span>👁️ Просмотр проекта и задач</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_create_projects" id="perm_can_create_projects" value="1">
                            <span>📁 Создание проектов</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_own_projects" id="perm_can_edit_own_projects" value="1">
                            <span>✏️ Редактирование своих проектов</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_tasks" id="perm_can_edit_tasks" value="1">
                            <span>✏️ Создание и редактирование задач</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_edit_messages" id="perm_can_edit_messages" value="1">
                            <span>💬 Написание сообщений</span>
                        </div>
                        <div class="perm-item">
                            <input type="checkbox" name="can_upload_files" id="perm_can_upload_files" value="1">
                            <span>📎 Загрузка файлов</span>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('permissionModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>

        <form method="post" id="deletePermissionForm" style="display:none;">
            <input type="hidden" name="action" value="permission_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="perm_uuid" id="delete_perm_uuid">
            <?= msgql_csrf_form_field() ?>
        </form>
    <?php endif; ?>

    <?php if ($active_tab === 'subscribers' && $is_admin): ?>
        <div class="card">
            <div class="users-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap: wrap; gap: 12px;">
                <h3 style="margin:0;">🔔 Подписчики задач</h3>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="subscriber-search-wrapper" style="position: relative;">
                        <input type="text" id="subscriberSearchInput" placeholder="🔍 Фильтр по задаче, пользователю..."
                               style="padding: 8px 32px 8px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: #0b1020; color: #e9eefc; min-width: 220px;">
                        <span id="subscriberSearchClear" onclick="clearSubscriberSearch()"
                              style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: rgba(233,238,252,0.5); display: none;">✕</span>
                    </div>
                    <button class="btn-primary" onclick="openCreateSubscriberModal()">+ Добавить подписку</button>
                </div>
            </div>

            <div class="users-table-wrapper">
                <table class="users-table" id="subscribersTable">
                    <thead>
                        <tr>
                            <th data-column="task">Задача</th>
                            <th data-column="user">Пользователь</th>
                            <th data-column="subscribed_at">Подписан</th>
                            <th data-column="subscribed_by">Кем подписан</th>
                            <th data-column="status">Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="subscribersTableBody">
                        <?php
                        $subscribers_list = get_all_subscribers($db);
                        ?>
                        <?php foreach ($subscribers_list as $sub): ?>
                        <tr data-task="<?= htmlspecialchars(strtolower($sub['task_title'])) ?>"
                            data-user="<?= htmlspecialchars(strtolower($sub['user_name'] ?: $sub['user_login'])) ?>"
                            data-subscribed-by="<?= htmlspecialchars(strtolower($sub['subscribed_by_name'] ?: $sub['subscribed_by_login'])) ?>"
                            data-status="<?= $sub['is_active'] ? 'active' : 'inactive' ?>">
                            <td class="task-link-cell">
                                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($sub['task_uuid']) ?>" class="task-link" title="Перейти к задаче">
                                    📋 <?= htmlspecialchars($sub['task_title']) ?>
                                </a>
                            </td>
                            <td class="user-cell">
                                <?= htmlspecialchars($sub['user_name'] ?: $sub['user_login']) ?>
                             </td>
                            <td><?= date('d.m.Y H:i', (int)($sub['subscribed_at'] / 1000)) ?></td>
                            <td><?= htmlspecialchars($sub['subscribed_by_name'] ?: $sub['subscribed_by_login']) ?></td>
                            <td class="status-<?= $sub['is_active'] ? 'active' : 'blocked' ?>">
                                <?= $sub['is_active'] ? '✅ Активна' : '❌ Отписан' ?>
                             </td>
                            <td class="actions">
                                <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($sub['task_uuid']) ?>" class="btn-icon" title="Перейти к задаче" style="display: inline-flex; text-decoration: none;">🔗</a>
                                <button class="btn-icon" onclick="editSubscriber('<?= $sub['id'] ?>', '<?= $sub['task_uuid'] ?>', '<?= $sub['user_uuid'] ?>', <?= $sub['is_active'] ?>)">✏️</button>
                                <button class="btn-icon delete" onclick="deleteSubscriber('<?= $sub['id'] ?>', '<?= addslashes($sub['task_title']) ?>', '<?= addslashes($sub['user_name'] ?: $sub['user_login']) ?>')">🗑️</button>
                             </td>
                         </tr>
                        <?php endforeach; ?>
                        <?php if (empty($subscribers_list)): ?>
                        <tr id="noSubscribersRow"><td colspan="6" style="text-align:center; padding:40px;">Нет активных подписок</td></tr>
                        <?php endif; ?>
                    </tbody>
                 </table>
            </div>
            <div id="subscriberSearchInfo" style="margin-top: 12px; font-size: 12px; color: rgba(233,238,252,0.5); text-align: center; display: none;">
                Показано <span id="filteredCount">0</span> из <span id="totalCount">0</span> записей
            </div>
        </div>

        <div id="subscriberModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 550px;">
                <span class="close" onclick="document.getElementById('subscriberModal').style.display='none'">&times;</span>
                <h3 id="subscriberModalTitle">Добавление подписки</h3>
                <form method="post" id="subscriberForm">
                    <input type="hidden" name="action" value="subscriber_update">
                    <input type="hidden" name="sub_action" id="sub_sub_action" value="create">
                    <input type="hidden" name="subscriber_id" id="subscriber_id" value="">
                    <?= msgql_csrf_form_field() ?>

                    <label class="muted">Задача *</label>
                    <select name="task_uuid" id="sub_task_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите задачу --</option>
                        <?php
                        $tasks_result = $db->query("SELECT t.uuid, t.title, p.title as project_title FROM tasks t JOIN projects p ON t.project_uuid = p.uuid ORDER BY p.title, t.title");
                        $all_tasks = $tasks_result->fetch_all(MYSQLI_ASSOC);
                        foreach ($all_tasks as $task): ?>
                            <option value="<?= $task['uuid'] ?>">[<?= htmlspecialchars($task['project_title']) ?>] <?= htmlspecialchars($task['title']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="muted" style="margin-top:12px;">Пользователь *</label>
                    <select name="user_uuid" id="sub_user_uuid" required style="width:100%; padding:10px; border-radius:8px; background:#0b1020; color:#e9eefc; border:1px solid rgba(255,255,255,0.12);">
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= $user['uuid'] ?>"><?= htmlspecialchars($user['name'] ?: $user['login']) ?> (<?= $role_names[$user['role']] ?>)</option>
                        <?php endforeach; ?>
                    </select>

                    <div class="permissions-group" style="margin-top:16px;">
                        <div class="perm-item">
                            <input type="checkbox" name="is_active" id="sub_is_active" value="1" checked>
                            <span>✅ Активная подписка (пользователь получает уведомления)</span>
                        </div>
                    </div>

                    <div style="margin-top:20px;">
                        <button type="submit">Сохранить</button>
                        <button type="button" onclick="document.getElementById('subscriberModal').style.display='none'">Отмена</button>
                    </div>
                </form>
            </div>
        </div>

        <form method="post" id="deleteSubscriberForm" style="display:none;">
            <input type="hidden" name="action" value="subscriber_update">
            <input type="hidden" name="sub_action" value="delete">
            <input type="hidden" name="subscriber_id" id="delete_subscriber_id">
            <?= msgql_csrf_form_field() ?>
        </form>
    <?php endif; ?>

    <?php if ($is_admin): ?>
        <div class="card" style="margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0 0 8px 0;">🧹 Очистка файлов</h3>
                    <p style="margin: 0; color: rgba(233,238,252,0.7); font-size: 13px;">
                        Удаление файлов, которые не привязаны ни к одной задаче или сообщению.<br>
                        <span style="color: #f59e0b;">⚠️ Внимание! Это действие необратимо.</span>
                    </p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <a href="<?= $appBase ?>/cleanup_files.php?mode=dry_run" class="btn-secondary" style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                        🔍 Анализ (dry run)
                    </a>
                    <a href="<?= $appBase ?>/cleanup_files.php?mode=delete&confirm=yes"
                       class="btn-danger"
                       style="display: inline-block; padding: 10px 20px; text-decoration: none; background: #dc2626; border-radius: 8px; color: white;"
                       onclick="return confirm('ВНИМАНИЕ! Будут удалены все файлы, не привязанные к задачам или сообщениям. Продолжить?')">
                        🗑️ Удалить мертвые файлы
                    </a>
                </div>
            </div>
            <div style="margin-top: 12px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 8px; font-size: 12px; color: rgba(233,238,252,0.6);">
                💡 <strong>Как использовать:</strong> Сначала нажмите "Анализ (dry run)" для просмотра файлов, которые будут удалены.
                Затем, если всё верно, нажмите "Удалить мертвые файлы".
            </div>
        </div>
    <?php endif; ?>

    <div class="footer muted">
        <a href="<?= $appBase ?>/index.php">← На главную</a>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/admin_users.js?v=1.0.0"></script>
<script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/admin_tabs.js?v=1.0.0"></script>

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>