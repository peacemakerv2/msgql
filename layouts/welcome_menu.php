<?php
// layouts/welcome_menu.php version 2.0: Дашборд с фильтрацией чужого контента и автообновлением
ini_set('display_errors', 0);           // Не выводим на экран
ini_set('log_errors', 1);               // Логируем ошибки
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Путь к логу

// Сохраняем оригинальный уровень error_reporting
error_reporting(E_ALL);



$db = msgql_db();
$current_user_uuid = msgql_current_user_uuid();
$now = msgql_now_ms();
$now_str = (string)$now;
$is_admin = msgql_is_admin();

// Получаем данные пользователя
$stmt = $db->prepare("SELECT login, name, alert_interval_min, alert_days, time_last_dashboard_view FROM users WHERE uuid = ?");
$stmt->bind_param("s", $current_user_uuid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Получаем время последнего просмотра дашборда
$last_view = $user['time_last_dashboard_view'] ?? 0;
// Если last_view = 0 (первый визит), показываем за последние 7 дней
$since_time = ($last_view > 0) ? $last_view : ($now - (7 * 24 * 60 * 60 * 1000));
$since_time_str = (string)$since_time;


// Обновляем время просмотра дашборда
$stmt = $db->prepare("UPDATE users SET time_last_dashboard_view = ? WHERE uuid = ?");
$stmt->bind_param("ss", $now_str, $current_user_uuid);
$stmt->execute();

$alert_interval_min = $user['alert_interval_min'] ?? 30;
if ($alert_interval_min < 1) $alert_interval_min = 30;

// ==================== 1. НОВЫЕ ЗАДАЧИ (только чужие!) ====================
if ($is_admin) {
    $tasks_sql = "SELECT t.uuid, t.title, t.descr, t.status, t.time, t.stamp, t.time_start,
    p.title as project_title, p.uuid as project_uuid,
    u.name as user_name, u.login as user_login
    FROM tasks t
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
    WHERE t.time > ?
    AND t.user_uuid != ?
    ORDER BY t.time DESC
    LIMIT 20";
    $stmt = $db->prepare($tasks_sql);
    $stmt->bind_param("ss", $since_time_str, $current_user_uuid);
} else {
    $tasks_sql = "SELECT DISTINCT t.uuid, t.title, t.descr, t.status, t.time, t.stamp, t.time_start,
    p.title as project_title, p.uuid as project_uuid,
    u.name as user_name, u.login as user_login
    FROM tasks t
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
    WHERE t.time > ?
    AND t.user_uuid != ?
    AND p.created_by_uuid != ?
    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
    ORDER BY t.time DESC
    LIMIT 20";
    $stmt = $db->prepare($tasks_sql);
    $stmt->bind_param("sssss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}
$stmt->execute();
$new_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== 2. НОВЫЕ СООБЩЕНИЯ (только чужие!) ====================
if ($is_admin) {
    $messages_sql = "SELECT m.uuid, m.text, m.time, m.stamp, m.is_read, m.user_uuid,
    t.uuid as task_uuid, t.title as task_title,
    p.uuid as project_uuid, p.title as project_title,
    u.name as user_name, u.login as user_login
    FROM messages m
    JOIN tasks t ON m.task_uuid = t.uuid
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON m.user_uuid = u.uuid
    WHERE m.is_read = 0
    AND m.user_uuid != ?
    AND t.assigned_to_uuid = ?
    ORDER BY m.time DESC
    LIMIT 20";
    $stmt = $db->prepare($messages_sql);
    $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
} else {
    $messages_sql = "SELECT DISTINCT m.uuid, m.text, m.time, m.stamp, m.is_read, m.user_uuid,
    t.uuid as task_uuid, t.title as task_title,
    p.uuid as project_uuid, p.title as project_title,
    u.name as user_name, u.login as user_login
    FROM messages m
    JOIN tasks t ON m.task_uuid = t.uuid
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON m.user_uuid = u.uuid
    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
    WHERE m.is_read = 0
    AND m.user_uuid != ?
    AND p.created_by_uuid != ?
    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
    ORDER BY m.time DESC
    LIMIT 20";
    $stmt = $db->prepare($messages_sql);
    $stmt->bind_param("ssss", $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}
$stmt->execute();
$new_messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== 3. НОВЫЕ ФАЙЛЫ (только чужие!) ====================
if ($is_admin) {
    $files_sql = "SELECT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
    t.uuid as task_uuid, t.title as task_title,
    p.uuid as project_uuid, p.title as project_title,
    u.name as uploader_name, u.login as uploader_login
    FROM files f
    LEFT JOIN task_files tf ON f.uuid = tf.file_uuid
    LEFT JOIN tasks t ON tf.task_uuid = t.uuid
    LEFT JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
    WHERE f.time > ?
    AND f.uploaded_by_uuid != ?
    AND t.assigned_to_uuid = ?
    ORDER BY f.time DESC
    LIMIT 20";
    $stmt = $db->prepare($files_sql);
    $stmt->bind_param("sss", $since_time_str, $current_user_uuid, $current_user_uuid);
} else {
    $files_sql = "SELECT DISTINCT f.uuid, f.orig_name, f.size_bytes, f.mime, f.time, f.stamp,
    t.uuid as task_uuid, t.title as task_title,
    p.uuid as project_uuid, p.title as project_title,
    u.name as uploader_name, u.login as uploader_login
    FROM files f
    JOIN task_files tf ON f.uuid = tf.file_uuid
    JOIN tasks t ON tf.task_uuid = t.uuid
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON f.uploaded_by_uuid = u.uuid
    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
    WHERE f.time > ?
    AND f.uploaded_by_uuid != ?
    AND p.created_by_uuid != ?
    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
    ORDER BY f.time DESC
    LIMIT 20";
    $stmt = $db->prepare($files_sql);
    $stmt->bind_param("sisss", $current_user_uuid, $since_time_str, $current_user_uuid, $current_user_uuid, $current_user_uuid);
}
$stmt->execute();
$new_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== 4. ПРОСРОЧЕННЫЕ ЗАДАЧИ (только чужие!) ====================
if ($is_admin) {
    $overdue_sql = "SELECT t.uuid, t.title, t.descr, t.time_end_plan as deadline, t.time, t.stamp, t.time_start,
    p.title as project_title, p.uuid as project_uuid,
    u.name as assignee_name, u.login as assignee_login
    FROM tasks t
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
    WHERE t.status = 0
    AND t.time_end_plan IS NOT NULL
    AND t.time_end_plan > 0
    AND t.time_end_plan < ?
    AND t.time > ?
    AND t.user_uuid != ?
    ORDER BY t.time_end_plan ASC
    LIMIT 20";
    $stmt = $db->prepare($overdue_sql);
    $stmt->bind_param("sis", $now_str, $since_time_str, $current_user_uuid);
} else {
    $overdue_sql = "SELECT DISTINCT t.uuid, t.title, t.descr, t.time_end_plan as deadline, t.time, t.stamp, t.time_start,
    p.title as project_title, p.uuid as project_uuid,
    u.name as assignee_name, u.login as assignee_login
    FROM tasks t
    JOIN projects p ON t.project_uuid = p.uuid
    LEFT JOIN users u ON t.assigned_to_uuid = u.uuid
    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
    WHERE t.assigned_to_uuid = ?
    AND t.status = 0
    AND t.time_end_plan IS NOT NULL
    AND t.time_end_plan > 0
    AND t.time_end_plan < ?
    AND t.time > ?
    AND t.user_uuid != ?
    AND p.created_by_uuid != ?
    AND upp.can_view = 1
    ORDER BY t.time_end_plan ASC
    LIMIT 20";
    $stmt = $db->prepare($overdue_sql);
    $stmt->bind_param("sssiss", $current_user_uuid, $current_user_uuid, $now_str, $since_time_str, $current_user_uuid, $current_user_uuid);
}
$stmt->execute();
$overdue_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
function format_time_ago($ms) {
    $diff = (msgql_now_ms() - $ms) / 1000;
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    return date('d.m H:i', (int)($ms / 1000));
}

function truncate_text($text, $len = 60) {
    $text = strip_tags($text);
    if (mb_strlen($text) > $len) {
        return mb_substr($text, 0, $len) . '…';
    }
    return $text;
}

function format_file_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
<div class="wrap dashboard">
    <div class="dashboard-header">
        <h1>Добро пожаловать, <?= htmlspecialchars($user['name'] ?: $_SESSION['login'] ?? 'Пользователь') ?>!</h1>
        <div class="dashboard-stats">
            <div class="stat-badge <?= count($new_tasks) > 0 ? 'has-new' : '' ?>" onclick="scrollToBlock('dash-card-tasks')" style="cursor: pointer;">
                📋 Новых задач: <?= count($new_tasks) ?>
            </div>
            <div class="stat-badge <?= count($new_messages) > 0 ? 'has-new' : '' ?>" onclick="scrollToBlock('dash-card-messages')" style="cursor: pointer;">
                💬 Новых сообщений: <?= count($new_messages) ?>
            </div>
            <div class="stat-badge <?= count($new_files) > 0 ? 'has-new' : '' ?>" onclick="scrollToBlock('dash-card-files')" style="cursor: pointer;">
                📁 Новых файлов: <?= count($new_files) ?>
            </div>
            <div class="stat-badge <?= count($overdue_tasks) > 0 ? 'has-overdue' : '' ?>" onclick="scrollToBlock('dash-card-overdue')" style="cursor: pointer;">
                ⚠️ Просроченных задач: <?= count($overdue_tasks) ?>
            </div>
            <div class="stat-badge">
                ⏱️ Email-интервал: <?= $alert_interval_min ?> мин
            </div>
        </div>
    </div>
    
    <div class="dashboard-grid">
        <!-- Левая колонка: задачи и файлы -->
        <div class="dashboard-col">
            <!-- Просроченные задачи -->
            <div class="dashboard-card overdue-card" id="dash-card-overdue">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2>⚠️ Просроченные задачи</h2>
                        <a href="#dash-card-overdue" class="anchor-link" title="Ссылка на этот блок">🔗</a>
                    </div>
                    <a href="<?= $appBase ?>/projects.php" class="card-link">Все задачи →</a>
                </div>
                <div class="card-body" id="dash-block-overdue">
                    <?php if (empty($overdue_tasks)): ?>
                        <div class="empty-state">✅ Нет новых просроченных задач</div>
                    <?php else: ?>
                        <?php foreach ($overdue_tasks as $task): ?>
                        <div class="feed-item task-item overdue" data-type="overdue">
                            <div class="feed-icon">⚠️</div>
                            <div class="feed-content">
                                <div class="feed-title">
                                    <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($task['uuid']) ?>">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <span class="scroll-to-block" onclick="scrollToBlock('dash-card-overdue')" title="Прокрутить к блоку просроченных задач">⬇️</span>
                                </div>
                                <div class="feed-meta">
                                    Проект: <?= htmlspecialchars($task['project_title']) ?>
                                    <?php if ($task['assignee_name']): ?>
                                    • Исполнитель: <?= htmlspecialchars($task['assignee_name'] ?: $task['assignee_login']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($task['descr']): ?>
                                <div class="feed-preview"><?= htmlspecialchars(truncate_text($task['descr'], 50)) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($task['time_start'])): ?>
                                <div class="feed-meta">🚀 Начало: <?= date('d.m.Y H:i', (int)($task['time_start'] / 1000)) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($task['deadline'])): ?>
                                <div class="feed-deadline">
                                ⏰ Срок: <?= date('d.m.Y H:i', (int)($task['deadline'] / 1000)) ?>
                                (просрочена на <?= floor(($now - $task['deadline']) / (24 * 60 * 60 * 1000)) ?> дн)
                                </div>
                                <?php endif; ?>


                                
                                <div class="feed-time">🕒 Создана: <?= format_time_ago($task['time']) ?></div>
                                <div class="feed-badge badge-overdue">⚠️ ПРОСРОЧЕНА</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Новые задачи -->
            <div class="dashboard-card" id="dash-card-tasks">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2>📋 Новые задачи</h2>
                        <a href="#dash-card-tasks" class="anchor-link" title="Ссылка на этот блок">🔗</a>
                    </div>
                    <a href="<?= $appBase ?>/projects.php" class="card-link">Все задачи →</a>
                </div>
                <div class="card-body" id="dash-block-tasks">
                    <?php if (empty($new_tasks)): ?>
                        <div class="empty-state">Нет новых задач</div>
                    <?php else: ?>
                        <?php foreach ($new_tasks as $task): ?>
                        <div class="feed-item task-item" data-type="tasks">
                            <div class="feed-icon">📋</div>
                            <div class="feed-content">
                                <div class="feed-title">
                                    <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($task['uuid']) ?>">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <span class="scroll-to-block" onclick="scrollToBlock('dash-card-tasks')" title="Прокрутить к блоку новых задач">⬇️</span>
                                </div>
                                <div class="feed-meta">
                                    Проект: <?= htmlspecialchars($task['project_title']) ?>
                                    <?php if ($task['user_name']): ?>
                                    • Назначена: <?= htmlspecialchars($task['user_name'] ?: $task['user_login']) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($task['descr']): ?>
                                <div class="feed-preview"><?= htmlspecialchars(truncate_text($task['descr'], 50)) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($task['time_start'])): ?>
                                <div class="feed-meta">🚀 Начало: <?= date('d.m.Y H:i', (int)($task['time_start'] / 1000)) ?></div>
                                <?php endif; ?>

                                <div class="feed-time">🕒 <?= format_time_ago($task['time']) ?></div>
                                <div class="feed-badge <?= $task['status'] == 1 ? 'badge-done' : 'badge-new' ?>">
                                    <?= $task['status'] == 1 ? '✓ Выполнена' : '🟢 Активна' ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Новые файлы -->
            <div class="dashboard-card" id="dash-card-files">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2>📁 Новые файлы</h2>
                        <a href="#dash-card-files" class="anchor-link" title="Ссылка на этот блок">🔗</a>
                    </div>
                    <a href="<?= $appBase ?>/files.php" class="card-link">Все файлы →</a>
                </div>
                <div class="card-body" id="dash-block-files">
                    <?php if (empty($new_files)): ?>
                        <div class="empty-state">Нет новых файлов</div>
                    <?php else: ?>
                        <?php foreach ($new_files as $file): ?>
                        <div class="feed-item file-item" data-type="files">
                            <div class="feed-icon">📎</div>
                            <div class="feed-content">
                                <div class="feed-title">
                                    <a href="#" onclick="showFilePreview('<?= $file['uuid'] ?>', '<?= addslashes($file['orig_name']) ?>', <?= $file['size_bytes'] ?>, '<?= addslashes($file['mime']) ?>'); return false;">
                                        <?= htmlspecialchars($file['orig_name']) ?>
                                    </a>
                                    <span class="scroll-to-block" onclick="scrollToBlock('dash-card-files')" title="Прокрутить к блоку новых файлов">⬇️</span>
                                </div>
                                <?php if ($file['task_title']): ?>
                                <div class="feed-meta">
                                    Задача: <a href="<?= $appBase ?>/projects.php?task=<?= urlencode($file['task_uuid']) ?>"><?= htmlspecialchars($file['task_title']) ?></a>
                                    • Проект: <?= htmlspecialchars($file['project_title']) ?>
                                </div>
                                <?php endif; ?>
                                <div class="feed-meta">
                                    Загрузил: <?= htmlspecialchars($file['uploader_name'] ?: $file['uploader_login']) ?>
                                    • Размер: <?= format_file_size($file['size_bytes']) ?>
                                </div>
                                <div class="feed-time">🕒 <?= format_time_ago($file['time']) ?></div>
                                <div class="feed-badge badge-file">📄 Файл</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Правая колонка: сообщения и настройки -->
        <div class="dashboard-col">
            <!-- Новые сообщения -->
            <div class="dashboard-card" id="dash-card-messages">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2>💬 Новые сообщения</h2>
                        <a href="#dash-card-messages" class="anchor-link" title="Ссылка на этот блок">🔗</a>
                    </div>
                    <a href="<?= $appBase ?>/messages.php" class="card-link">Все сообщения →</a>
                </div>
                <div class="card-body messages-feed" id="dash-block-messages">
                    <?php if (empty($new_messages)): ?>
                        <div class="empty-state">Нет новых сообщений</div>
                    <?php else: ?>
                        <?php foreach ($new_messages as $msg): ?>
                        <div class="feed-item message-item" data-type="messages">
                            <div class="feed-icon">💬</div>
                            <div class="feed-content">
                                <div class="feed-title">
                                    <a href="<?= $appBase ?>/messages.php?task=<?= urlencode($msg['task_uuid']) ?>">
                                        <?= htmlspecialchars($msg['task_title']) ?>
                                    </a>
                                    <span class="scroll-to-block" onclick="scrollToBlock('dash-card-messages')" title="Прокрутить к блоку новых сообщений">⬇️</span>
                                </div>
                                <div class="feed-meta">
                                    Проект: <?= htmlspecialchars($msg['project_title']) ?>
                                    • Автор: <?= htmlspecialchars($msg['user_name'] ?: $msg['user_login']) ?>
                                </div>
                                <div class="feed-preview"><?= htmlspecialchars(truncate_text($msg['text'], 80)) ?></div>
                                <div class="feed-time">🕒 <?= format_time_ago($msg['time']) ?></div>
                                <div class="feed-badge badge-message">💬 Сообщение</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Уведомления -->
            <div class="dashboard-card" id="dash-card-notifications">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2>🔔 Уведомления</h2>
                        <a href="#dash-card-notifications" class="anchor-link" title="Ссылка на этот блок">🔗</a>
                    </div>
                    <button class="btn-secondary" style="font-size: 11px; padding: 4px 12px;" onclick="markAllNotificationsRead()">✓ Всё прочитано</button>
                </div>
                <div class="card-body" id="dash-block-notifications">
                    <div class="empty-state">Загрузка...</div>
                </div>
            </div>

            
            <!-- Настройки дашборда -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2>⚙️ Настройки дашборда</h2>
                </div>
                <div class="card-body">
                    <div class="settings-form">
                        <div class="info-row">
                            <span class="info-label">📅 Последний визит:</span>
                            <span class="info-value" id="last-visit-display">
                                <?php if ($last_view > 0): ?>
                                    <script>
                                        document.write(new Date(<?= $last_view ?>).toLocaleString());
                                    </script>
                                <?php else: ?>
                                    Первый визит
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!$is_admin): ?>
                        <div class="info-row">
                            <span class="info-label">⏱️ Email-интервал (минуты):</span>
                            <span class="info-value"><?= $alert_interval_min ?> мин</span>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-label">📊 Период показа:</span>
                            <span class="info-value">
                                <?php if ($last_view > 0): ?>
                                    С последнего визита (<?= format_time_ago($last_view) ?>)
                                <?php else: ?>
                                    За последние 7 дней
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="button-group">
                            <button class="btn-secondary" id="mark_read_btn" onclick="markAllAsRead()">✓ Отметить всё как прочитанное</button>
                        </div>
                        <div id="interval_success" style="display: none; color: #22c55e; margin-top: 8px;">✓ Настройки сохранены</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* === СТИЛИ ДАШБОРДА === */
.dashboard { max-width: 1400px; }
.dashboard-header { margin-bottom: 24px; }
.dashboard-header h1 { font-size: 24px; font-weight: 600; margin-bottom: 12px; }
.dashboard-stats { display: flex; gap: 16px; flex-wrap: wrap; }
.stat-badge { background: #121a33; padding: 8px 16px; border-radius: 20px; font-size: 13px; color: rgba(233,238,252,0.9); }
.stat-badge.has-new { background: rgba(34,197,94,0.2); border: 1px solid rgba(34,197,94,0.4); color: #4ade80; }
.stat-badge.has-overdue { background: rgba(248,113,113,0.2); border: 1px solid rgba(248,113,113,0.4); color: #f87171; animation: pulse-warning 1.5s infinite; }
@keyframes pulse-warning { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; background: rgba(248,113,113,0.3); } }
.dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.dashboard-col { display: flex; flex-direction: column; gap: 24px; }
.dashboard-card { background: #121a33; border-radius: 16px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; }
.dashboard-card.overdue-card { border-color: rgba(248,113,113,0.3); box-shadow: 0 0 0 1px rgba(248,113,113,0.1); }
.card-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #0f1529; border-bottom: 1px solid rgba(255,255,255,0.06); }
.card-header h2 { font-size: 16px; font-weight: 600; margin: 0; }
.card-link { font-size: 12px; color: #60a5fa; text-decoration: none; }
.card-link:hover { text-decoration: underline; }
.card-body { padding: 16px; max-height: 400px; overflow-y: auto; }
.feed-item { display: flex; gap: 12px; padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s; }
.feed-item:hover { background: rgba(79,124,255,0.05); }
.feed-item.overdue { background: rgba(248,113,113,0.05); border-left: 3px solid #f87171; }
.feed-item.overdue:hover { background: rgba(248,113,113,0.1); }
.feed-icon { font-size: 24px; flex-shrink: 0; }
.feed-content { flex: 1; min-width: 0; }
.feed-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
.feed-title a { color: #9bb7ff; text-decoration: none; }
.feed-title a:hover { text-decoration: underline; }
.feed-meta { font-size: 11px; color: rgba(233,238,252,0.6); margin-bottom: 4px; }
.feed-preview { font-size: 12px; color: rgba(233,238,252,0.7); margin-bottom: 4px; word-break: break-word; }
.feed-time { font-size: 10px; color: rgba(233,238,252,0.4); margin-top: 6px; }
.feed-deadline { font-size: 11px; color: #f87171; margin-top: 4px; font-weight: 500; }
.feed-badge { display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 12px; margin-top: 6px; }
.badge-new { background: rgba(34,197,94,0.15); color: #4ade80; }
.badge-done { background: rgba(59,130,246,0.15); color: #60a5fa; }
.badge-file { background: rgba(168,85,247,0.15); color: #a78bfa; }
.badge-message { background: rgba(236,72,153,0.15); color: #f472b6; }
.badge-overdue { background: rgba(248,113,113,0.2); color: #f87171; font-weight: 600; animation: pulse-text 1.5s infinite; }
@keyframes pulse-text { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
.empty-state { text-align: center; padding: 40px; color: rgba(233,238,252,0.5); font-size: 13px; }
.settings-form { display: flex; flex-direction: column; gap: 16px; }
.info-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
.info-label { font-size: 13px; color: rgba(233,238,252,0.7); }
.info-value { font-size: 13px; color: rgba(233,238,252,0.9); font-weight: 500; }
.button-group { display: flex; gap: 12px; margin-top: 8px; }
.button-group button { flex: 1; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.btn-primary { background: #4f7cff; border: none; color: white; }
.btn-primary:hover { background: #3b66e0; }
.btn-secondary { background: rgba(79,124,255,0.15); border: 1px solid rgba(79,124,255,0.3); color: #9bb7ff; }
.btn-secondary:hover { background: rgba(79,124,255,0.25); }
.messages-feed .feed-preview { background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 12px; margin-top: 6px; }

/* Стили для якорных ссылок и кнопок прокрутки */
.anchor-link {
    opacity: 0.4;
    font-size: 14px;
    text-decoration: none;
    transition: opacity 0.2s;
    color: #9bb7ff;
}
.anchor-link:hover {
    opacity: 1;
    text-decoration: none;
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
    border-radius: 16px;
}

@media (max-width: 768px) {
    .dashboard-grid { grid-template-columns: 1fr; gap: 16px; }
    .card-body { max-height: 350px; }
    .feed-item { padding: 10px; gap: 8px; }
    .feed-icon { font-size: 20px; }
    .button-group { flex-direction: column; }
    .dashboard-stats { gap: 10px; }
    .stat-badge { padding: 6px 12px; font-size: 11px; }
    .feed-title { font-size: 13px; }
    .scroll-to-block { font-size: 10px; }
}

.block-highlight {
    transition: box-shadow 0.3s ease;
    box-shadow: 0 0 0 2px #4f7cff;
    border-radius: 16px;
}

.stat-badge {
    cursor: pointer;
    transition: transform 0.2s, opacity 0.2s;
}

.stat-badge:hover {
    transform: translateY(-2px);
    opacity: 0.9;
}
</style>

<script nonce="<?= CSP_NONCE ?>">
// ========== ФУНКЦИЯ ПРОКРУТКИ К БЛОКУ ==========
function scrollToBlock(blockId) {
    var targetCard = document.getElementById(blockId);
    if (targetCard) {
        var offset = 80; // Отступ сверху (для фиксированного хедера)
        var elementPosition = targetCard.getBoundingClientRect().top;
        var offsetPosition = elementPosition + window.pageYOffset - offset;
        
        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });
        
        // Добавляем эффект подсветки
        targetCard.classList.add('block-highlight');
        setTimeout(function() {
            targetCard.classList.remove('block-highlight');
        }, 1500);
    }
}

// Рендер уведомлений из Центра уведомлений
function renderNotifications(notifications) {
    var container = document.getElementById('dash-block-notifications');
    if (!container) return;
    
    if (!notifications || notifications.length === 0) {
        container.innerHTML = '<div class="empty-state">🔔 Нет новых уведомлений</div>';
        logDebug('[RENDER_NOTIFY] No notifications, showing empty state');
        return;
    }
    logDebug('[RENDER_NOTIFY] Rendering ' + notifications.length + ' notifications');

    var html = '';
    for (var i = 0; i < notifications.length; i++) {
        var n = notifications[i];
        var data = n.data || {};
        
        var title = '';
        var link = '';
        var icon = '🔔';
        
        // Определяем тип уведомления
        if (n.type === 'task_changed') {
            var changes = data.changes || [];
            
            // Ищем изменение исполнителя
            var assigneeChange = changes.find(function(c) { return c.field === 'assigned_to_uuid'; });
            
            if (assigneeChange && data.role === 'new_assignee') {
                title = '👤 Вас назначили исполнителем: ' + escapeHtml(data.task_title || 'задачи');
                icon = '👤';
            } else {
                title = '✏️ Задача изменена: ' + escapeHtml(data.task_title || 'задачи');
                icon = '✏️';
            }
            link = window.APP_BASE + '/projects.php?task=' + n.task_uuid;
            
            // Добавляем описание изменений
            if (changes.length > 0) {
                var changesText = changes.map(function(c) {
                    return c.label + ': ' + (c.new_display || '—');
                }).join(', ');
                html += '<div class="feed-preview" style="margin-top: 6px; font-size: 12px;">📝 ' + escapeHtml(changesText) + '</div>';
            }
        } else if (data.is_new) {
            title = '✨ Новая задача: ' + escapeHtml(data.task_title || '');
            link = window.APP_BASE + '/projects.php?task=' + n.task_uuid;
            icon = '✨';
        } else {
            title = '📋 ' + escapeHtml(data.task_title || 'Уведомление');
            link = window.APP_BASE + '/projects.php?task=' + n.task_uuid;
            icon = '📋';
        }
        
        var timeAgo = formatTimeAgoStatic(n.created_at);
        
        html += `
            <div class="feed-item notification-item" data-uuid="${escapeHtml(n.uuid)}" data-type="notification">
                <div class="feed-icon">${icon}</div>
                <div class="feed-content">
                    <div class="feed-title">
                        <a href="${link}">${title}</a>
                        <span class="scroll-to-block" onclick="scrollToBlock('dash-card-notifications')" title="Прокрутить к блоку уведомлений">⬇️</span>
                    </div>
                    <div class="feed-meta">
                        📁 Проект: ${escapeHtml(data.project_title || '—')}
                    </div>
                    <div class="feed-time">🕒 ${timeAgo}</div>
                </div>
            </div>
        `;
    }
    container.innerHTML = html;
}

function markAllNotificationsRead() {
    if (!window.csrfToken) {
        logError('[NOTIFY] CSRF token not found');
        showToastMessage('Ошибка безопасности', 'error');
        return;
    }
    
    logDebug('[NOTIFY] markAllNotificationsRead called from dashboard - resetting ALL badges');
    
    var btn = document.querySelector('#dash-card-notifications .btn-secondary');
    var originalText = btn ? btn.innerHTML : '✓ Всё прочитано';
    if (btn) {
        btn.innerHTML = '⏳...';
        btn.disabled = true;
    }
    
    // 1. Сначала сбрасываем уведомления
    fetch(window.APP_BASE + '/dashboard_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_notifications_read&csrf_token=' + encodeURIComponent(window.csrfToken)
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            // Обновляем блок уведомлений на дашборде
            renderNotifications([]);
            
            // Сбрасываем бейдж колокольчика
            var bellBadge = document.getElementById('notificationBadge');
            if (bellBadge) bellBadge.style.display = 'none';
            
            logDebug('[NOTIFY] Notifications cleared, now resetting message badges');
            
            // 2. ✅ ДОБАВЛЯЕМ: Сбрасываем бейджи сообщений, проектов, файлов
            return fetch(window.APP_BASE + '/dashboard_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&csrf_token=' + encodeURIComponent(window.csrfToken)
            });
        }
        throw new Error(data.error || 'Ошибка при сбросе уведомлений');
    })
    .then(function(response) { 
        if (!response) return { success: true };
        return response.json(); 
    })
    .then(function(data) {
        if (data && data.success === false) {
            throw new Error(data.error || 'Ошибка при сбросе бейджей');
        }
        
        showToastMessage('✓ Все уведомления и бейджи сброшены', 'success');
        
        // 3. Обновляем ВСЕ бейджи
        setTimeout(function() {
            if (window.SSE && typeof window.SSE.updateAllBadges === 'function') {
                window.SSE.updateAllBadges();
            } else if (typeof window.updateAllBadges === 'function') {
                window.updateAllBadges();
            }
            
            // Сбрасываем бейджи в DOM напрямую
            var messagesBadge = document.getElementById('badge-messages');
            if (messagesBadge) messagesBadge.style.display = 'none';
            
            var projectsBadge = document.getElementById('badge-projects');
            if (projectsBadge) projectsBadge.style.display = 'none';
            
            var filesBadge = document.getElementById('badge-files');
            if (filesBadge) filesBadge.style.display = 'none';
            
            // Обновляем дашборд
            if (typeof window.refreshDashboard === 'function') {
                setTimeout(function() { window.refreshDashboard(); }, 500);
            }
        }, 300);
    })
    .catch(function(e) {
        logError('[NOTIFY] markAllNotificationsRead error:', e);
        showToastMessage('Ошибка: ' + e.message, 'error');
    })
    .finally(function() {
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    });
}

// ========== ОБРАБОТКА URL-ЯКОРЯ ПРИ ЗАГРУЗКЕ ==========
function handleHashScroll() {
    if (window.location.hash) {
        var targetId = window.location.hash.substring(1);
        var targetElement = document.getElementById(targetId);
        if (targetElement) {
            setTimeout(function() {
                var offset = 80;
                var elementPosition = targetElement.getBoundingClientRect().top;
                var offsetPosition = elementPosition + window.pageYOffset - offset;
                window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                
                targetElement.classList.add('block-highlight');
                setTimeout(function() {
                    targetElement.classList.remove('block-highlight');
                }, 1500);
            }, 300);
        }
    }
}

// ========== АВТООБНОВЛЕНИЕ ДАШБОРДА С ДЕБАНСИНГОМ ==========
var refreshDashboardTimer = null;
var isRefreshing = false;

window.refreshDashboard = function() {
    // Дебаунсинг: не вызываем чаще чем раз в 500мс
    if (refreshDashboardTimer) {
        clearTimeout(refreshDashboardTimer);
    }
    
    refreshDashboardTimer = setTimeout(function() {
        if (isRefreshing) {
            logDebug('[DASHBOARD] Уже выполняется обновление, пропускаем');
            return;
        }
        
        logDebug('[DASHBOARD] Обновление данных...');
        isRefreshing = true;
        
        fetch(window.APP_BASE + '/dashboard_data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_updates'
        })
        .then(function(response) {
            if (response.status === 503) {
                logWarning('[DASHBOARD] Сервер перегружен (503), пропускаем');
                return null;
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (data && data.success) {
                logDebug('[DASHBOARD] Получены данные:', {
                    tasks: data.new_tasks?.length || 0,
                    messages: data.new_messages?.length || 0,
                    files: data.new_files?.length || 0,
                    overdue: data.overdue_tasks?.length || 0,
                    unread: data.unread_count || 0
                });
                
                // Обновляем блоки
                renderDashboardBlock('dash-block-tasks', data.new_tasks || [], 'tasks');
                renderDashboardBlock('dash-block-messages', data.new_messages || [], 'messages');
                renderDashboardBlock('dash-block-files', data.new_files || [], 'files');
                renderDashboardBlock('dash-block-overdue', data.overdue_tasks || [], 'overdue');
                
                // Обновляем уведомления
                if (data.notifications) {
                    renderNotifications(data.notifications);
                }

                // Обновляем счетчики в шапке
                updateDashboardStatsCounters(data);
                
                // Синхронизируем бейдж
                if (data.unread_count !== undefined || data.notifications_count !== undefined) {
                    if (window.SSE && typeof window.SSE.forceUpdateBadge === 'function') {
                        window.SSE.forceUpdateBadge(data.unread_count);
                    }
                    sessionStorage.setItem('sse_unread_total', data.unread_count);
                }
            }
        })
        .catch(function(error) {
            logError('[DASHBOARD] Ошибка обновления:', error.message);
        })
        .finally(function() {
            isRefreshing = false;
        });
    }, 500);
};

// Глобальная функция для принудительного обновления дашборда
window.forceRefreshDashboard = function() {
    logDebug('[DASHBOARD] Force refresh requested');
    if (typeof window.refreshDashboard === 'function') {
        // Сбрасываем кэш и обновляем
        if (window._dashboardLastUpdate) {
            window._dashboardLastUpdate = 0;
        }
        window.refreshDashboard();
    } else {
        // Fallback: перезагружаем страницу
        window.location.reload();
    }
};

// Подписываемся на SSE события обновления задач
if (window.SSE && typeof window.SSE.onTaskUpdate === 'undefined') {
    window.SSE.onTaskUpdate = function(taskUuid) {
        logDebug('[SSE] Task updated, refreshing dashboard:', taskUuid);
        setTimeout(function() {
            window.forceRefreshDashboard();
        }, 500);
    };
}

// Функция рендеринга блоков
function renderDashboardBlock(blockId, items, type) {
    var container = document.getElementById(blockId);
    if (!container) return;
    
    if (!items || items.length === 0) {
        container.innerHTML = getEmptyStateHtml(type);
        return;
    }
    
    var cardId = getCardIdByType(type);
    var html = '';
    for (var i = 0; i < items.length; i++) {
        html += renderDashboardItem(items[i], type, cardId);
    }
    container.innerHTML = html;
}

function getCardIdByType(type) {
    var cardMap = {
        'tasks': 'dash-card-tasks',
        'messages': 'dash-card-messages',
        'files': 'dash-card-files',
        'overdue': 'dash-card-overdue'
    };
    return cardMap[type] || '';
}

function getEmptyStateHtml(type) {
    var texts = {
        'tasks': 'Нет новых задач',
        'messages': 'Нет новых сообщений',
        'files': 'Нет новых файлов',
        'overdue': '✅ Нет просроченных задач'
    };
    return '<div class="empty-state">' + (texts[type] || 'Нет данных') + '</div>';
}

function renderDashboardItem(item, type, cardId) {
    var scrollButton = cardId ? `<span class="scroll-to-block" onclick="scrollToBlock('${cardId}')" title="Прокрутить к этому блоку">⬇️</span>` : '';
    
    if (type === 'tasks') {
        var statusClass = item.status == 1 ? 'badge-done' : 'badge-new';
        var statusText = item.status == 1 ? '✓ Выполнена' : '🟢 Активна';
        
        // Форматируем дату начала
        var startHtml = '';
        if (item.time_start) {
            var startDate = new Date(item.time_start);
            var today = new Date();
            var isFuture = startDate > today;
            startHtml = `<div class="feed-meta" style="color: ${isFuture ? '#22c55e' : '#9bb7ff'};">🚀 Начало: ${formatDateStatic(item.time_start)}${isFuture ? ' (будущая)' : ''}</div>`;
        }
        
        return `
            <div class="feed-item task-item" data-type="tasks">
                <div class="feed-icon">📋</div>
                <div class="feed-content">
                    <div class="feed-title">
                        <a href="<?= $appBase ?>/projects.php?task=${escapeHtml(item.uuid)}">${escapeHtml(item.title)}</a>
                        ${scrollButton}
                    </div>
                    <div class="feed-meta">
                        Проект: ${escapeHtml(item.project_title)}
                        ${item.user_name ? `• Назначена: ${escapeHtml(item.user_name)}` : ''}
                    </div>
                    ${startHtml}
                    ${item.descr ? `<div class="feed-preview">${escapeHtml(truncateTextStatic(item.descr, 50))}</div>` : ''}
                    <div class="feed-time">🕒 ${formatTimeAgoStatic(item.time)}</div>
                    <div class="feed-badge ${statusClass}">${statusText}</div>
                </div>
            </div>
        `;
    }
    
    if (type === 'messages') {
        return `
            <div class="feed-item message-item" data-type="messages">
                <div class="feed-icon">💬</div>
                <div class="feed-content">
                    <div class="feed-title">
                        <a href="<?= $appBase ?>/messages.php?task=${escapeHtml(item.task_uuid)}">${escapeHtml(item.task_title)}</a>
                        ${scrollButton}
                    </div>
                    <div class="feed-meta">
                        Проект: ${escapeHtml(item.project_title)}
                        • Автор: ${escapeHtml(item.user_name || item.user_login)}
                    </div>
                    <div class="feed-preview">${escapeHtml(truncateTextStatic(item.text, 80))}</div>
                    <div class="feed-time">🕒 ${formatTimeAgoStatic(item.time)}</div>
                    <div class="feed-badge badge-message">💬 Сообщение</div>
                </div>
            </div>
        `;
    }
    
    if (type === 'files') {
        return `
            <div class="feed-item file-item" data-type="files">
                <div class="feed-icon">📎</div>
                <div class="feed-content">
                    <div class="feed-title">
                        <a href="#" onclick="showFilePreview('${item.uuid}', '${escapeHtml(item.orig_name)}', ${item.size_bytes || 0}, '${escapeHtml(item.mime || '')}'); return false;">
                            ${escapeHtml(item.orig_name)}
                        </a>
                        ${scrollButton}
                    </div>
                    ${item.task_title ? `
                    <div class="feed-meta">
                        Задача: <a href="<?= $appBase ?>/projects.php?task=${escapeHtml(item.task_uuid)}">${escapeHtml(item.task_title)}</a>
                        • Проект: ${escapeHtml(item.project_title)}
                    </div>
                    ` : ''}
                    <div class="feed-meta">
                        Загрузил: ${escapeHtml(item.uploader_name || item.uploader_login)}
                        • Размер: ${formatFileSizeStatic(item.size_bytes)}
                    </div>
                    <div class="feed-time">🕒 ${formatTimeAgoStatic(item.time)}</div>
                    <div class="feed-badge badge-file">📄 Файл</div>
                </div>
            </div>
        `;
    }
    
    if (type === 'overdue') {
        var overdueClass = item.deadline && item.deadline < Date.now() ? 'overdue' : '';
        return `
            <div class="feed-item task-item ${overdueClass}" data-type="overdue">
                <div class="feed-icon">⚠️</div>
                <div class="feed-content">
                    <div class="feed-title">
                        <a href="<?= $appBase ?>/projects.php?task=${escapeHtml(item.uuid)}">${escapeHtml(item.title)}</a>
                        ${scrollButton}
                    </div>
                    <div class="feed-meta">
                        Проект: ${escapeHtml(item.project_title)}
                        ${item.assignee_name ? `• Исполнитель: ${escapeHtml(item.assignee_name)}` : ''}
                    </div>
                    ${item.descr ? `<div class="feed-preview">${escapeHtml(truncateTextStatic(item.descr, 50))}</div>` : ''}
                    ${item.deadline ? `<div class="feed-deadline">⏰ Срок: ${formatDateStatic(item.deadline)} (просрочена)</div>` : ''}
                    <div class="feed-time">🕒 Создана: ${formatTimeAgoStatic(item.time)}</div>
                    <div class="feed-badge badge-overdue">⚠️ ПРОСРОЧЕНА</div>
                </div>
            </div>
        `;
    }
    
    return '';
}

// Вспомогательные функции
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncateTextStatic(text, len) {
    if (!text) return '';
    if (text.length > len) return text.substring(0, len) + '…';
    return text;
}

function formatTimeAgoStatic(ms) {
    var diff = (Date.now() - ms) / 1000;
    if (diff < 60) return 'только что';
    if (diff < 3600) return Math.floor(diff / 60) + ' мин назад';
    if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад';
    return new Date(ms).toLocaleDateString('ru-RU');
}

function formatDateStatic(ms) {
    var d = new Date(ms);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'});
}

function formatFileSizeStatic(bytes) {
    if (!bytes) return '0 B';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

function updateDashboardStatsCounters(data) {
    var tasksCount = data.new_tasks?.length || 0;
    var messagesCount = data.new_messages?.length || 0;
    var filesCount = data.new_files?.length || 0;
    var overdueCount = data.overdue_tasks?.length || 0;
    
    var statsDiv = document.querySelector('.dashboard-stats');
    if (statsDiv) {
        var badges = statsDiv.querySelectorAll('.stat-badge');
        if (badges[0]) badges[0].innerHTML = '📋 Новых задач: ' + tasksCount;
        if (badges[1]) badges[1].innerHTML = '💬 Новых сообщений: ' + messagesCount;
        if (badges[2]) badges[2].innerHTML = '📁 Новых файлов: ' + filesCount;
        if (badges[3]) badges[3].innerHTML = '⚠️ Просроченных задач: ' + overdueCount;
        
        badges[0].classList.toggle('has-new', tasksCount > 0);
        badges[1].classList.toggle('has-new', messagesCount > 0);
        badges[2].classList.toggle('has-new', filesCount > 0);
        badges[3].classList.toggle('has-overdue', overdueCount > 0);
    }
}

// ========== MARK ALL AS READ С RETRY И CSRF-ТОКЕНОМ ==========
function markAllAsRead(retryCount) {
    if (retryCount === undefined) retryCount = 0;
    var maxRetries = 5;
    
    logDebug('[MARK_READ] Попытка ' + (retryCount + 1) + ' из ' + maxRetries);
    
    var btn = document.getElementById('mark_read_btn');
    var originalText = btn ? btn.innerHTML : '✓ Отметить всё';
    if (btn && retryCount === 0) {
        btn.innerHTML = '⏳ Отправка...';
        btn.disabled = true;
    }
    
    // ✅ ДОБАВЛЯЕМ CSRF-ТОКЕН из глобальной переменной
    var csrfToken = window.csrfToken || '';
    var formData = 'action=mark_read&csrf_token=' + encodeURIComponent(csrfToken);
    
    fetch(window.APP_BASE + '/dashboard_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData  // ✅ теперь с CSRF-токеном
    })
    .then(function(response) {
        if (response.status === 503) {
            throw new Error('Service Unavailable (503)');
        }
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            logDebug('[MARK_READ] Успешно отмечено');
            
            if (typeof window.SSE !== 'undefined') {
                if (window.SSE.resetCounters) window.SSE.resetCounters();
                if (window.SSE.refreshCounters) setTimeout(function() { window.SSE.refreshCounters(); }, 500);
            }
            
            sessionStorage.removeItem('sse_unread_total');
            sessionStorage.removeItem('sse_unread_messages');
            sessionStorage.removeItem('sse_unread_tasks');
            sessionStorage.removeItem('sse_unread_files');
            sessionStorage.removeItem('sse_unread_overdue');
            
            var dashboardIndicator = document.querySelector('.sse-dashboard-indicator');
            if (dashboardIndicator) dashboardIndicator.remove();
            
            showToastMessage('✓ Отмечено как прочитанное (' + (data.marked_count || 0) + ' сообщений)', 'success');
            
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
            
            // Обновляем дашборд
            if (window.refreshDashboard) {
                setTimeout(window.refreshDashboard, 500);
            }
        } else {
            throw new Error(data.error || 'Неизвестная ошибка');
        }
    })
    .catch(function(error) {
        logError('[MARK_READ] Ошибка:', error.message);
        
        // Проверяем, не ошибка ли это CSRF
        if (error.message.includes('CSRF') || (error.message.includes('<!doctype') && retryCount < maxRetries)) {
            logDebug('[MARK_READ] Возможно ошибка CSRF, пробуем обновить токен...');
            // Пробуем получить свежий токен из мета-тега или перезагрузить страницу
            if (retryCount === 0) {
                showToastMessage('Ошибка безопасности. Обновите страницу.', 'warning');
                setTimeout(function() { location.reload(); }, 2000);
                return;
            }
        }
        
        if (error.message.includes('503') && retryCount < maxRetries) {
            var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
            logDebug('[MARK_READ] Повтор через ' + (delay/1000) + 'с (попытка ' + (retryCount + 1) + '/' + maxRetries + ')');
            
            if (btn) {
                btn.innerHTML = '⏳ Повтор через ' + Math.round(delay/1000) + 'с...';
            }
            
            setTimeout(function() {
                markAllAsRead(retryCount + 1);
            }, delay);
        } else {
            showToastMessage('Ошибка: ' + error.message, 'error');
            if (btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
    });
}

// Вспомогательная функция для показа toast-уведомлений
function showToastMessage(message, type) {
    var toast = document.createElement('div');
    toast.style.cssText = 'position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:' + 
        (type === 'success' ? '#22c55e' : '#ef4444') + 
        '; color:white; padding:12px 24px; border-radius:8px; z-index:10000; font-size:14px; animation:fadeOut 3s ease forwards; box-shadow:0 4px 12px rgba(0,0,0,0.3);';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() { if (toast.parentNode) toast.remove(); }, 3000);
}

// Добавляем анимацию для fadeOut
var styleToast = document.createElement('style');
styleToast.textContent = '@keyframes fadeOut { 0% { opacity: 1; transform: translateX(-50%) translateY(0); } 100% { opacity: 0; transform: translateX(-50%) translateY(-20px); } }';
document.head.appendChild(styleToast);

// ========== ЗАПУСК АВТООБНОВЛЕНИЯ ==========
document.addEventListener('DOMContentLoaded', function() {
    // Обработка URL-якоря
    handleHashScroll();
    
    // Обработчик изменения хэша
    window.addEventListener('hashchange', handleHashScroll);
    
    // Первоначальная загрузка
    window.refreshDashboard();
    
    // Обновление каждые 30 секунд
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            window.refreshDashboard();
        }
    }, 30000);
    
    // Обновляем при возвращении на вкладку
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            logDebug('[DASHBOARD] Вкладка активна, обновляем');
            window.refreshDashboard();
        }
    });
});

// Функция для показа предпросмотра файла
function showFilePreview(uuid, name, size, mime) {
    if (typeof window.showFilePreview === 'function') {
        window.showFilePreview(uuid, name, size, mime);
    } else {
        window.location.href = window.APP_BASE + '/file_preview.php?uuid=' + encodeURIComponent(uuid);
    }
}

// ========== ФУНКЦИЯ ПРОКРУТКИ К БЛОКУ ==========
window.scrollToBlock = function(blockId) {
    var targetCard = document.getElementById(blockId);
    if (targetCard) {
        var offset = 80; // Отступ сверху (для фиксированного хедера)
        var elementPosition = targetCard.getBoundingClientRect().top;
        var offsetPosition = elementPosition + window.pageYOffset - offset;
        
        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });
        
        // Добавляем эффект подсветки
        targetCard.classList.add('block-highlight');
        setTimeout(function() {
            targetCard.classList.remove('block-highlight');
        }, 1500);
    }
};

// ========== ОБРАБОТКА URL-ЯКОРЯ ПРИ ЗАГРУЗКЕ ==========
function handleHashScroll() {
    if (window.location.hash) {
        var targetId = window.location.hash.substring(1);
        var targetElement = document.getElementById(targetId);
        if (targetElement) {
            setTimeout(function() {
                var offset = 80;
                var elementPosition = targetElement.getBoundingClientRect().top;
                var offsetPosition = elementPosition + window.pageYOffset - offset;
                window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                
                targetElement.classList.add('block-highlight');
                setTimeout(function() {
                    targetElement.classList.remove('block-highlight');
                }, 1500);
            }, 300);
        }
    }
}

// Добавляем обработчик изменения хэша
window.addEventListener('hashchange', handleHashScroll);

// Обрабатываем якорь при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    handleHashScroll();
});
</script>
<?php require_once __DIR__ . '/page_end.php'; ?>