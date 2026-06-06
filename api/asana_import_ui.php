<?php
// api/asana_import_ui.php - Единый интерфейс для управления импортом из Asana
// Версия: 2.0 - Полная
// на базе кода https://github.com/dosaboy/asana-exporter

// Отключаем буферы
while (ob_get_level() > 0) {
    ob_end_clean();
}

define('AJAX_REQUEST', true);

// error_log("appBase1 = ".$appBase);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../init.php';

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$fullPath = __DIR__;  // /var/www/.../api

// Если $appBase не пустой, удаляем его из полного пути
$correctedPath = $fullPath;
if (!empty($appBase) && $appBase !== '/') {
    // $appBase = '/api' — удаляем его из конца пути
    $correctedPath = preg_replace('/' . preg_quote($appBase, '/') . '$/', '', $fullPath);
}

// Вычисляем относительный путь от DOCUMENT_ROOT
$relativePath = str_replace($docRoot, '', $correctedPath);
$relativePath = str_replace('\\', '/', $relativePath);
$appBase = rtrim($relativePath, '/');

// Если корень сайта — пустая строка
if ($appBase === '' || $appBase === '/') {
    $appBase = '';
}

// error_log("[ASANA_UI] DOCUMENT_ROOT = " . $docRoot);
// error_log("[ASANA_UI] fullPath = " . $fullPath);
// error_log("[ASANA_UI] correctedPath = " . $correctedPath);
// error_log("[ASANA_UI] FINAL appBase = '" . $appBase . "'");

require_once __DIR__ . '/asana_config.php';
require_once __DIR__ . '/asana_client.php';
require_once __DIR__ . '/asana_mapper.php';

//error_log("appBase2 = ".$appBase);

// Проверка прав (только администратор)
if (!msgql_is_logged_in()) {
    header('Location: ' . $appBase . "/index.php");
    exit;
}

if (!msgql_is_admin()) {
    die("Доступ запрещён. Требуются права администратора.");
}

$db = msgql_db();
$currentUserUuid = msgql_current_user_uuid();
if (empty($currentUserUuid)) {
    // Fallback - берём первого админа
    $adminStmt = $db->prepare("SELECT uuid FROM users WHERE role = 0 AND status = 0 LIMIT 1");
    $adminStmt->execute();
    $admin = $adminStmt->get_result()->fetch_assoc();
    $currentUserUuid = $admin['uuid'] ?? '';
}
$csrf_token = msgql_csrf_get_token();

// Получаем список существующих проектов в системе для отображения
$existingProjects = [];
$projResult = $db->query("SELECT uuid, title, stamp FROM projects ORDER BY time DESC LIMIT 50");
while ($row = $projResult->fetch_assoc()) {
    $existingProjects[] = $row;
}

// Получаем статистику импорта из лога
$importStats = [];
$statsResult = $db->query("
    SELECT type, COUNT(*) as count, MAX(imported_at) as last_import 
    FROM asana_import_log 
    GROUP BY type
");
while ($row = $statsResult->fetch_assoc()) {
    $importStats[$row['type']] = [
        'count' => $row['count'],
        'last_import' => $row['last_import']
    ];
}

// Получаем сопоставления пользователей
$userMappings = [];
$mapResult = $db->query("
    SELECT a.*, u.login as user_login, u.name as user_name 
    FROM asana_user_mapping a
    LEFT JOIN users u ON a.user_uuid = u.uuid
    ORDER BY a.created_at DESC
");
while ($row = $mapResult->fetch_assoc()) {
    $userMappings[] = $row;
}



// Получаем всех пользователей системы для выбора
$systemUsers = [];
$usersResult = $db->query("SELECT uuid, login, name, email FROM users WHERE status = 0 ORDER BY login");
while ($row = $usersResult->fetch_assoc()) {
    $systemUsers[] = $row;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <script nonce="<?= CSP_NONCE ?>">
        // Убедимся, что APP_BASE определен
        if (typeof window.APP_BASE === 'undefined') {
            window.APP_BASE = '<?= $appBase ?>';
        }
    </script>
    <script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/logger.js"></script>
    <script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/toast.js"></script>
    <script nonce="<?= CSP_NONCE ?>" src="<?= $appBase ?>/js/sse_client.js"></script>


    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Импорт из Asana - ЗадаЧат</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔄</text></svg>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0b1020; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #e9eefc; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        h1 { font-size: 28px; margin-bottom: 8px; }
        h2 { font-size: 18px; margin-bottom: 16px; }
        h3 { font-size: 16px; margin-bottom: 12px; }
        
        .page-header { margin-bottom: 24px; }
        .page-desc { color: rgba(233,238,252,0.6); margin-bottom: 24px; }
        
        .tabs { display: flex; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 24px; flex-wrap: wrap; }
        .tab { padding: 10px 20px; border-radius: 10px 10px 0 0; color: rgba(233,238,252,0.7); text-decoration: none; transition: all 0.2s; cursor: pointer; background: none; border: none; font-size: 14px; }
        .tab:hover { background: rgba(79,124,255,0.1); color: #e9eefc; }
        .tab.active { background: #4f7cff; color: white; }
        
        .card { background: #121a33; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 24px; overflow: hidden; }
        .card-header { padding: 16px 20px; background: #0f1529; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header h3 { margin: 0; }
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: rgba(233,238,252,0.8); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.12); background: #0b1020;
            color: #e9eefc; font-size: 14px; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #4f7cff;
        }
        
        .btn-primary { background: #4f7cff; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #3b66e0; }
        .btn-secondary { background: rgba(79,124,255,0.15); border: 1px solid rgba(79,124,255,0.3); color: #9bb7ff; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .btn-secondary:hover { background: rgba(79,124,255,0.25); }
        .btn-danger { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #f87171; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .btn-danger:hover { background: rgba(239,68,68,0.3); }
        .btn-success { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .btn-success:hover { background: rgba(34,197,94,0.3); }
        
        .button-group { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .data-table th { background: rgba(79,124,255,0.08); font-weight: 600; }
        .data-table tr:hover { background: rgba(79,124,255,0.05); }
        
        .status-success { color: #4ade80; }
        .status-warning { color: #f59e0b; }
        .status-error { color: #f87171; }
        .status-info { color: #60a5fa; }
        
        .progress-bar { width: 100%; height: 4px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; margin: 12px 0; }
        .progress-fill { height: 100%; background: #4f7cff; width: 0%; transition: width 0.3s ease; }
        
        .log-container { background: #0b1020; border-radius: 8px; padding: 12px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .log-entry { padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .log-entry.info { color: #60a5fa; }
        .log-entry.success { color: #4ade80; }
        .log-entry.warning { color: #f59e0b; }
        .log-entry.error { color: #f87171; }
        
        .option-checkbox { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer; }
        .option-checkbox input { width: 18px; height: 18px; margin: 0; cursor: pointer; }
        .option-checkbox span { font-size: 13px; cursor: pointer; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .back-link { margin-top: 24px; text-align: center; }
        .back-link a { color: #9bb7ff; text-decoration: none; }
        
        .project-item { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: background 0.2s; }
        .project-item:hover { background: rgba(79,124,255,0.1); }
        .project-item.selected { background: rgba(79,124,255,0.2); border-left: 3px solid #4f7cff; }
        .project-name { font-weight: 600; margin-bottom: 4px; }
        .project-meta { font-size: 11px; color: rgba(233,238,252,0.5); }
    </style>
</head>
<body>

<div class="wrap">
    <div class="page-header">
        <h1>🔄 Импорт из Asana</h1>
        <p class="page-desc">Управление импортом проектов, задач, сообщений и файлов из Asana в ЗадаЧат</p>
    </div>
    
    <!-- Настройки подключения -->
    <div class="card">
        <div class="card-header">
            <h3>🔌 Настройки подключения к Asana API</h3>
            <button class="btn-secondary" id="testConnectionBtn" onclick="testConnection()">🔍 Проверить подключение</button>
        </div>
        <div class="card-body">
            <div class="grid-2">
                <div class="form-group">
                    <label>Personal Access Token</label>
                    <input type="password" id="asana_token" value="<?= htmlspecialchars(ASANA_PERSONAL_ACCESS_TOKEN !== 'your_personal_access_token_here' ? ASANA_PERSONAL_ACCESS_TOKEN : '') ?>" placeholder="Введите Personal Access Token">
                    <div style="font-size: 11px; color: rgba(233,238,252,0.5); margin-top: 4px;">Получить можно в Asana → Настройки → Мои приложения → Personal Access Tokens</div>
                </div>
                <div class="form-group">
                    <label>Workspace GID</label>
                    <input type="text" id="workspace_gid" value="<?= htmlspecialchars(ASANA_WORKSPACE_GID !== 'your_workspace_gid_here' ? ASANA_WORKSPACE_GID : '') ?>" placeholder="Введите Workspace GID">
                    <div style="font-size: 11px; color: rgba(233,238,252,0.5); margin-top: 4px;">GID можно найти в URL рабочего пространства Asana</div>
                </div>
            </div>
            <div id="connectionStatus" style="margin-top: 12px; font-size: 13px;"></div>
        </div>
    </div>
    
    <!-- Вкладки -->
    <div class="tabs">
        <button class="tab active" data-tab="projects">📁 Проекты Asana</button>
        <button class="tab" data-tab="import">📥 Импорт</button>
        <button class="tab" data-tab="users">👥 Пользователи</button>
        <button class="tab" data-tab="log">📋 Лог импорта</button>
        <button class="tab" data-tab="settings">⚙️ Настройки</button>
    </div>
    
    <!-- Вкладка: Проекты Asana -->
    <div id="tab-projects" class="tab-content active">
        <div class="card">
            <div class="card-header">
                <h3>📁 Проекты в Asana</h3>
                <button class="btn-primary" id="refreshProjectsBtn" onclick="loadProjects()">🔄 Обновить список</button>
            </div>
            <div class="card-body">
                <div id="projectsList">
                    <div style="text-align: center; padding: 40px; color: rgba(233,238,252,0.5);">
                        Нажмите "Обновить список", чтобы загрузить проекты из Asana
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Вкладка: Импорт -->
    <div id="tab-import" class="tab-content" style="display: none;">
        <!-- Полный импорт и пользователи в одном блоке -->
        <div class="card">
            <div class="card-header">
                <h3>📥 Импорт данных из Asana</h3>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div class="option-checkbox">
                        <input type="checkbox" id="dryRunMode" checked>
                        <span>🔍 Тестовый режим (Dry Run) - только анализ, без записи в БД</span>
                    </div>
                    <div class="option-checkbox">
                        <input type="radio" name="importMode" id="modeFull" value="2" checked>
                        <span>📥 Полный импорт (пропуск существующих)</span>
                    </div>
                    <div class="option-checkbox">
                        <input type="radio" name="importMode" id="modeUpdate" value="3">
                        <span>🔄 Обновление (перезапись существующих)</span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="grid-2">
                    <!-- Шаг 1: Пользователи -->
                    <div class="step-card" style="background: rgba(79,124,255,0.05); border-radius: 12px; padding: 16px;">
                        <h3 style="margin-bottom: 12px;">👥 Шаг 1: Пользователи</h3>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.6); margin-bottom: 12px;">Импорт и сопоставление пользователей Asana с аккаунтами в системе.</p>
                        <div class="button-group">
                            <button id="importUsersBtn" class="btn-primary" onclick="importUsers()">👥 Импортировать пользователей</button>
                            <button class="btn-secondary" onclick="loadAsanaUsers()">📋 Показать пользователей Asana</button>
                        </div>
                        <div id="usersImportResult" style="margin-top: 12px; font-size: 12px;"></div>
                    </div>
                    
                    <!-- Полный импорт (бывший Шаг 2+3+4, теперь справа) -->
                    <div class="step-card" style="background: rgba(79,124,255,0.05); border-radius: 12px; padding: 16px;">
                        <h3 style="margin-bottom: 12px;">🚀 Полный импорт проекта</h3>
                        <p style="font-size: 12px; color: rgba(233,238,252,0.6); margin-bottom: 12px;">Импорт проекта со всеми задачами, сообщениями и файлами.</p>
                        <div class="form-group">
                            <label>Выберите проект Asana</label>
                            <select id="fullImportProjectSelect" style="width: 100%;">
                                <option value="">-- Выберите проект --</option>
                            </select>
                        </div>
                        <div class="button-group">
                            <button id="fullImportBtn" class="btn-success" onclick="fullImport()">🚀 Выполнить полный импорт</button>
                            <button id="cancelImportBtn" class="btn-danger" onclick="cancelImport()" style="display: none;">⛔ Остановить импорт</button>
                        </div>
                        <div id="fullImportResult" style="margin-top: 12px;"></div>
                        <div class="progress-bar" id="importProgressBar" style="display: none;"><div class="progress-fill" id="importProgressFill"></div></div>
                    </div>
                </div>
                
                <!-- Скрытые шаги 2,3,4 (оставлены для обратной совместимости JS, но не отображаются) -->
                <div style="display: none;">
                    <div class="step-card">
                        <h3>📁 Шаг 2: Проекты</h3>
                        <select id="importProjectSelect"></select>
                        <button onclick="importProject()">Импортировать проект</button>
                        <div id="projectImportResult"></div>
                    </div>
                    <div class="step-card">
                        <h3>📋 Шаг 3: Задачи</h3>
                        <select id="tasksProjectSelect"></select>
                        <button onclick="importTasks()">Импортировать задачи</button>
                        <div id="tasksImportResult"></div>
                    </div>
                    <div class="step-card">
                        <h3>💬 Шаг 4: Сообщения и файлы</h3>
                        <select id="messagesTaskSelect"></select>
                        <button onclick="importMessages()">Импортировать сообщения</button>
                        <button onclick="importFiles()">Импортировать файлы</button>
                        <div id="messagesImportResult"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Вкладка: Пользователи -->
    <div id="tab-users" class="tab-content" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h3>👥 Пользователи Asana</h3>
                <button class="btn-secondary" onclick="loadAsanaUsers()">🔄 Загрузить пользователей Asana</button>
            </div>
            <div class="card-body">
                <div id="asanaUsersList">
                    <div style="text-align: center; padding: 40px; color: rgba(233,238,252,0.5);">
                        Нажмите "Загрузить пользователей Asana"
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>🔗 Сопоставление пользователей</h3>
                <button class="btn-secondary" onclick="autoMatchUsers()">🤖 Автосопоставление</button>
            </div>
            <div class="card-body">
                <div id="userMappingsList">
                    <?php if (empty($userMappings)): ?>
                        <div style="text-align: center; padding: 40px; color: rgba(233,238,252,0.5);">
                            Нет сопоставлений. Нажмите "Автосопоставление" или импортируйте пользователей.
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr><th>Asana GID</th><th>Asana Email</th><th>Asana Имя</th><th>Системный пользователь</th><th>Тип</th><th>Действия</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userMappings as $map): ?>
                                <tr id="mapping-row-<?= htmlspecialchars($map['asana_gid']) ?>">
                                    <td><code><?= htmlspecialchars(substr($map['asana_gid'], 0, 8)) ?>...</code></td>
                                    <td><?= htmlspecialchars($map['asana_email'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($map['asana_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($map['user_name'] ?? $map['user_login'] ?? '-') ?></td>
                                    <td><span class="status-info"><?= htmlspecialchars($map['mapping_type']) ?></span></td>
                                    <td><button class="btn-secondary" style="padding: 4px 8px; font-size: 11px;" onclick="removeMapping('<?= htmlspecialchars($map['asana_gid']) ?>')">🗑️ Удалить</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Вкладка: Лог импорта -->
    <div id="tab-log" class="tab-content" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h3>📋 История импорта</h3>
                <button class="btn-secondary" onclick="clearImportLog()">🗑️ Очистить лог</button>
            </div>
            <div class="card-body">
                <div class="log-container" id="importLogContainer">
                    <?php
                    $logResult = $db->query("SELECT * FROM asana_import_log ORDER BY imported_at DESC LIMIT 100");
                    if ($logResult->num_rows > 0) {
                        while ($log = $logResult->fetch_assoc()) {
                            $date = date('Y-m-d H:i:s', (int)($log['imported_at'] / 1000));
                            echo "<div class='log-entry info'>[{$date}] [{$log['type']}] {$log['title']} (ID: {$log['asana_gid']})</div>";
                        }
                    } else {
                        echo "<div class='log-entry'>Лог импорта пуст</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>📊 Статистика импорта</h3>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead><tr><th>Тип</th><th>Количество</th><th>Последний импорт</th></tr></thead>
                    <tbody>
                        <tr><td>📁 Проекты</td><td><?= $importStats['project']['count'] ?? 0 ?></td><td><?= $importStats['project']['last_import'] ? date('Y-m-d H:i:s', (int)($importStats['project']['last_import'] / 1000)) : '-' ?></td></tr>
                        <tr><td>📋 Задачи</td><td><?= $importStats['task']['count'] ?? 0 ?></td><td><?= $importStats['task']['last_import'] ? date('Y-m-d H:i:s', (int)($importStats['task']['last_import'] / 1000)) : '-' ?></td></tr>
                        <tr><td>💬 Сообщения</td><td><?= $importStats['message']['count'] ?? 0 ?></td><td><?= $importStats['message']['last_import'] ? date('Y-m-d H:i:s', (int)($importStats['message']['last_import'] / 1000)) : '-' ?></td></tr>
                        <tr><td>📎 Файлы</td><td><?= $importStats['file']['count'] ?? 0 ?></td><td><?= $importStats['file']['last_import'] ? date('Y-m-d H:i:s', (int)($importStats['file']['last_import'] / 1000)) : '-' ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Вкладка: Настройки -->
    <div id="tab-settings" class="tab-content" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h3>⚙️ Настройки импорта</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Максимальное количество файлов на задачу</label>
                    <input type="number" id="maxFilesPerTask" value="<?= IMPORT_MAX_FILES ?>">
                </div>
                <div class="form-group">
                    <label>Максимальная длина сообщения (символов)</label>
                    <input type="number" id="maxMessageLength" value="<?= IMPORT_MAX_MESSAGE_LENGTH ?>">
                </div>
                <div class="form-group">
                    <label>Размер пакета для API запросов</label>
                    <input type="number" id="batchSize" value="<?= IMPORT_BATCH_SIZE ?>">
                </div>
                <button class="btn-primary" onclick="saveImportSettings()">💾 Сохранить настройки</button>
                <div id="settingsResult" style="margin-top: 12px;"></div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>ℹ️ Информация</h3>
            </div>
            <div class="card-body">
                <p><strong>Ручное сопоставление пользователей:</strong> Для ручного сопоставления откройте консоль браузера (F12) и выполните:</p>
                <pre style="background: #0b1020; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 11px;">
updateUserMapping('asana_gid_здесь', 'user_uuid_здесь');
                </pre>
                <hr style="border-color: rgba(255,255,255,0.1); margin: 16px 0;">
                <p><strong>API Endpoints:</strong></p>
                <ul style="margin-left: 20px; color: rgba(233,238,252,0.7);">
                    <li><code>GET /api/asana_import.php?action=get_projects</code> - получить проекты</li>
                    <li><code>GET /api/asana_import.php?action=get_tasks&project_gid=...</code> - получить задачи</li>
                    <li><code>POST /api/asana_import.php?action=import&project_gid=...</code> - импорт</li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="back-link">
        <a href="<?= $appBase ?>/index.php">← Вернуться на главную</a>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
// ==================== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ ====================
let csrfToken = '<?= $csrf_token ?>';
let projectsCache = [];
let asanaUsersCache = [];
window.systemUsers = <?= json_encode($systemUsers) ?>;
window.userMappings = <?= json_encode($userMappings) ?>;


let importQueue = [];
let importInProgress = false;
let importCurrentOffset = 0;
let importTotalTasks = 0;
let importPhase = 'tasks';
let importProjectGid = null;
let importMode = 2;


async function cancelImport() {
    logDebug('[CANCEL] cancelImport() called');
    
    if (!confirm('Действительно остановить импорт? Текущая операция завершится, после чего импорт прекратится.')) {
        return;
    }
    
    let btn = document.getElementById('cancelImportBtn');
    setButtonLoading(btn, true);
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=cancel_import&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            showToast('✅ Импорт будет остановлен после завершения текущей операции', 'success');
            addLogEntry('Запрошена остановка импорта (файл-семафор)', 'warning');
            
            // Отключаем кнопку остановки
            let cancelBtn = document.getElementById('cancelImportBtn');
            if (cancelBtn) cancelBtn.style.display = 'none';
            
            // Показываем кнопку "Очистить флаг" на случай, если нужно отменить отмену
            let clearCancelBtn = document.createElement('button');
            clearCancelBtn.id = 'clearCancelBtn';
            clearCancelBtn.className = 'btn-secondary';
            clearCancelBtn.innerHTML = '↺ Отменить остановку';
            clearCancelBtn.onclick = clearCancelFlag;
            document.querySelector('#fullImportResult').appendChild(clearCancelBtn);
        } else {
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        logError('[CANCEL] Exception:', e.message);
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

async function clearCancelFlag() {
    logDebug('[CANCEL] clearCancelFlag() called');
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=clear_cancel_flag&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            showToast('✅ Остановка импорта отменена', 'success');
            addLogEntry('Флаг остановки импорта сброшен', 'info');
            
            // Восстанавливаем кнопку остановки
            let cancelBtn = document.getElementById('cancelImportBtn');
            if (cancelBtn) cancelBtn.style.display = 'inline-block';
            
            // Удаляем кнопку отмены остановки
            let clearCancelBtn = document.getElementById('clearCancelBtn');
            if (clearCancelBtn) clearCancelBtn.remove();
        } else {
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        logError('[CANCEL] Exception:', e.message);
        showToast('Ошибка: ' + e.message, 'error');
    }
}


async function startChunkedImport() {
    let projectGid = document.getElementById('fullImportProjectSelect').value;
    importMode = getImportMode();
    let isDryRun = (importMode === 1);
    
    if (!projectGid) {
        showToast('Выберите проект для импорта', 'warning');
        return;
    }
    
    let modeText = isDryRun ? 'ТЕСТОВЫЙ РЕЖИМ' : (importMode === 2 ? 'ПОЛНЫЙ ИМПОРТ' : 'ОБНОВЛЕНИЕ');
    
    if (!confirm('Начать чанковый импорт? Режим: ' + modeText + '\n\nИмпорт будет выполняться порциями для обхода лимитов хостинга.')) {
        return;
    }
    
    importProjectGid = projectGid;
    importInProgress = true;
    importPhase = 'tasks';
    importCurrentOffset = 0;
    
    let btn = document.getElementById('fullImportBtn');
    let cancelBtn = document.getElementById('cancelImportBtn');
    setButtonLoading(btn, true);
    if (cancelBtn) cancelBtn.style.display = 'inline-block';
    
    document.getElementById('fullImportResult').innerHTML = '<div class="status-info">🚀 Начинаем импорт проекта...</div>';
    updateProgress(0, 'Получение списка задач...');
    
    try {
        // Фаза 1: Получение списка задач
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_chunk&project_gid=' + encodeURIComponent(projectGid) + 
                  '&mode=' + importMode + 
                  '&phase=tasks' +
                  '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success && data.task_gids && data.task_gids.length > 0) {
            importTotalTasks = data.total_tasks;
            logDebug('[CHUNK_IMPORT] Got ' + importTotalTasks + ' tasks');
            
            // Фаза 2: Импорт задач чанками - МАЛЕНЬКИМИ порциями!
            let taskChunkSize = 3;  // 3 задачи за раз (было 15 - слишком много!)
            let currentOffset = 0;
            let tasksImported = 0;
            let subtasksImported = 0;
            
            while (currentOffset < importTotalTasks && importInProgress) {
                let percent = Math.floor(currentOffset / importTotalTasks * 50);
                updateProgress(percent, `Импорт задач: ${currentOffset}/${importTotalTasks}`);
                
                let chunkResponse = await fetch(window.APP_BASE + '/api/asana_import.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=import_chunk&project_gid=' + encodeURIComponent(projectGid) + 
                          '&mode=' + importMode + 
                          '&phase=tasks' +
                          '&offset=' + currentOffset +
                          '&chunk_size=' + taskChunkSize +
                          '&task_gids=' + encodeURIComponent(JSON.stringify(data.task_gids)) +
                          '&csrf_token=' + encodeURIComponent(csrfToken)
                });
                let chunkData = await chunkResponse.json();
                
                if (chunkData.success) {
                    tasksImported += chunkData.tasks_imported || 0;
                    subtasksImported += chunkData.subtasks_imported || 0;
                    currentOffset = chunkData.next_offset;
                    
                    addLogEntry(`Чанк задач: импортировано ${chunkData.tasks_imported || 0} задач (всего ${tasksImported})`, 'info');
                    
                    if (chunkData.complete) {
                        break;
                    }
                    
                    // Пауза между чанками - важна для сервера!
                    await sleep(2000);
                } else {
                    throw new Error(chunkData.error || 'Ошибка импорта задач');
                }
            }
            
            updateProgress(50, `Задачи импортированы: ${tasksImported} (+${subtasksImported} подзадач)`);
            
            // Фаза 3: Импорт сообщений и файлов - ЕЩЁ МЕНЬШЕ порциями!
            if (importInProgress) {
                updateProgress(50, 'Импорт сообщений и файлов...');
                
                currentOffset = 0;
                let messagesImported = 0;
                let filesImported = 0;
                let totalTasks = importTotalTasks;
                let messageChunkSize = 2; // ВСЕГО 2 задачи за раз для сообщений!
                
                while (currentOffset < totalTasks && importInProgress) {
                    let progressPercent = 50 + Math.floor(currentOffset / totalTasks * 50);
                    updateProgress(
                        progressPercent,
                        `Импорт сообщений: ${currentOffset}/${totalTasks} задач`
                    );
                    
                    let msgResponse = await fetch(window.APP_BASE + '/api/asana_import.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=import_chunk&project_gid=' + encodeURIComponent(projectGid) + 
                              '&mode=' + importMode + 
                              '&phase=messages' +
                              '&offset=' + currentOffset +
                              '&chunk_size=' + messageChunkSize +
                              '&csrf_token=' + encodeURIComponent(csrfToken)
                    });
                    let msgData = await msgResponse.json();
                    
                    if (msgData.success) {
                        messagesImported += msgData.messages_imported || 0;
                        filesImported += msgData.files_imported || 0;
                        currentOffset = msgData.next_offset;
                        
                        addLogEntry(`Чанк сообщений: +${msgData.messages_imported || 0} сообщений, +${msgData.files_imported || 0} файлов`, 'info');
                        
                        if (msgData.complete) {
                            break;
                        }
                        
                        // Большая пауза для сообщений - они тяжелее
                        await sleep(3000);
                    } else {
                        throw new Error(msgData.error || 'Ошибка импорта сообщений');
                    }
                }
                
                updateProgress(100, 'Импорт завершён!');
                
                let resultHtml = `
                    <div class="status-success">✅ Импорт завершён!</div>
                    <table class="data-table" style="margin-top:12px;">
                        <tr><td>📋 Задачи:</td><td>${tasksImported}</td></tr>
                        <tr><td>📋 Подзадачи:</td><td>${subtasksImported}</td></tr>
                        <tr><td>💬 Сообщения:</td><td>${messagesImported}</td></tr>
                        <tr><td>📎 Файлы:</td><td>${filesImported}</td></tr>
                    </table>
                `;
                document.getElementById('fullImportResult').innerHTML = resultHtml;
                addLogEntry(`Чанковый импорт завершён: задачи ${tasksImported}, сообщения ${messagesImported}, файлы ${filesImported}`, 'success');
                
                if (importMode !== 1) {
                    setTimeout(() => location.reload(), 200000);
                }
            }
            
        } else {
            throw new Error(data.error || 'Не удалось получить список задач');
        }
        
    } catch(e) {
        if (e.name === 'AbortError') {
            document.getElementById('fullImportResult').innerHTML = '<span class="status-warning">⏸️ Импорт остановлен пользователем</span>';
            addLogEntry('Импорт остановлен пользователем', 'warning');
        } else {
            document.getElementById('fullImportResult').innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
            showToast('Ошибка: ' + e.message, 'error');
        }
    } finally {
        setButtonLoading(btn, false);
        if (cancelBtn) cancelBtn.style.display = 'none';
        importInProgress = false;
    }
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Переопределяем fullImport для использования чанкового импорта
async function fullImport() {
    await startChunkedImport();
}

// Функция для ручного продолжения импорта (если нужна)
async function continueImport() {
    if (!importInProgress) {
        showToast('Нет активного импорта', 'warning');
        return;
    }
    // Продолжаем с текущего состояния
    await startChunkedImport();
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
function addCsrfToUrlParams(params) {
    params.append('csrf_token', csrfToken);
}

function showToast(message, type) {
    let toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + 
        (type === 'success' ? '#22c55e' : (type === 'error' ? '#ef4444' : (type === 'warning' ? '#f59e0b' : '#4f7cff'))) + 
        '; color:white; padding:10px 20px; border-radius:8px; z-index:10000; font-size:14px; animation:fadeInOut 2s ease;';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

function addLogEntry(message, type) {
    let container = document.getElementById('importLogContainer');
    let time = new Date().toLocaleTimeString();
    let entry = document.createElement('div');
    entry.className = `log-entry ${type}`;
    entry.innerHTML = `[${time}] ${message}`;
    if (container) {
        container.insertBefore(entry, container.firstChild);
        if (container.children.length > 200) {
            container.removeChild(container.lastChild);
        }
    }
}

function setButtonLoading(btn, loading) {
    // Проверяем, существует ли кнопка
    if (!btn) {
        logWarning('[BUTTON] Button element is null, skipping setButtonLoading');
        return;
    }
    
    if (loading) {
        btn.classList.add('btn-loading');
        btn.disabled = true;
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner"></span> Загрузка...';
    } else {
        btn.classList.remove('btn-loading');
        btn.disabled = false;
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
    }
}

function updateProgress(percent, text) {
    let progressBar = document.getElementById('importProgressBar');
    let progressFill = document.getElementById('importProgressFill');
    if (progressBar) progressBar.style.display = 'block';
    if (progressFill) progressFill.style.width = percent + '%';
    if (text) {
        let resultDiv = document.getElementById('fullImportResult');
        if (resultDiv) resultDiv.innerHTML = '<div class="status-info">' + text + ' (' + percent + '%)</div>';
    }
    if (percent >= 100) {
        setTimeout(() => { if (progressBar) progressBar.style.display = 'none'; }, 2000);
    }
}

function getImportMode() {
    let dryRun = document.getElementById('dryRunMode').checked;
    
    // Используем logDebug вместо console.log
    logDebug('[getImportMode] dryRunMode.checked = ' + dryRun);
    
    if (dryRun) {
        logDebug('[getImportMode] → возвращаем DRY_RUN (1)');
        return 1;
    }
    
    let modeFull = document.getElementById('modeFull');
    let modeUpdate = document.getElementById('modeUpdate');
    
    if (modeFull && modeFull.checked) {
        logDebug('[getImportMode] → возвращаем FULL (2)');
        return 2;
    }
    
    logDebug('[getImportMode] → возвращаем UPDATE (3)');
    return 3;
}

// ==================== ВКЛАДКИ ====================
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        let tabName = tab.dataset.tab;
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        tab.classList.add('active');
        document.getElementById(`tab-${tabName}`).style.display = 'block';
    });
});

// ==================== ПОДКЛЮЧЕНИЕ ====================
async function testConnection() {
    let token = document.getElementById('asana_token').value;
    let workspace = document.getElementById('workspace_gid').value;
    
    if (!token || !workspace) {
        showToast('Введите Token и Workspace GID', 'warning');
        return;
    }
    
    let statusDiv = document.getElementById('connectionStatus');
    statusDiv.innerHTML = '<span class="spinner"></span> Проверка подключения...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=test_connection&csrf_token=' + encodeURIComponent(csrfToken), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(token) + '&workspace=' + encodeURIComponent(workspace)
        });
        let data = await response.json();
        
        if (data.success) {
            statusDiv.innerHTML = '<span class="status-success">✅ Подключение успешно! Workspace: ' + (data.workspace || workspace) + '</span>';
            showToast('Подключение к Asana API успешно', 'success');
            loadProjects();
            loadAsanaUsers();
        } else {
            statusDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
        }
    } catch(e) {
        statusDiv.innerHTML = '<span class="status-error">❌ Ошибка подключения: ' + e.message + '</span>';
    }
}

// ==================== ПРОЕКТЫ ====================
async function loadProjects() {
    let token = document.getElementById('asana_token').value;
    let workspace = document.getElementById('workspace_gid').value;
    
    if (!token || !workspace) {
        showToast('Сначала настройте подключение к Asana', 'warning');
        return;
    }
    
    let container = document.getElementById('projectsList');
    container.innerHTML = '<div style="text-align:center; padding:40px;"><span class="spinner"></span> Загрузка проектов...</div>';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=get_projects&csrf_token=' + encodeURIComponent(csrfToken));
        let data = await response.json();
        
        if (data.success && data.projects) {
            projectsCache = data.projects;
            
            let html = '<div style="max-height: 400px; overflow-y: auto;">';
            for (let project of data.projects) {
                let archivedBadge = project.archived ? ' <span class="status-warning">(Архивный)</span>' : '';
                html += `
                    <div class="project-item" onclick="selectProject('${project.gid}', '${escapeHtml(project.name)}')">
                        <div class="project-name">📁 ${escapeHtml(project.name)}${archivedBadge}</div>
                        <div class="project-meta">GID: ${project.gid} | Создан: ${project.created_at || '?'}</div>
                        <div class="project-meta">${escapeHtml((project.notes || '').substring(0, 100))}</div>
                    </div>
                `;
            }
            html += '</div>';
            if (data.projects.length === 0) {
                html = '<div style="text-align:center; padding:40px;">Проекты не найдены в этом рабочем пространстве</div>';
            }
            container.innerHTML = html;
            
            // Обновляем селекты
            updateProjectSelects(data.projects);
        } else {
            container.innerHTML = '<div style="text-align:center; padding:40px; color:#f87171;">Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</div>';
        }
    } catch(e) {
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#f87171;">Ошибка загрузки: ' + e.message + '</div>';
    }
}

function updateProjectSelects(projects) {
    let selects = ['importProjectSelect', 'tasksProjectSelect', 'fullImportProjectSelect'];
    for (let selectId of selects) {
        let select = document.getElementById(selectId);
        if (select) {
            let html = '<option value="">-- Выберите проект --</option>';
            for (let project of projects) {
                if (!project.archived) {
                    html += `<option value="${project.gid}">${escapeHtml(project.name)}</option>`;
                }
            }
            select.innerHTML = html;
        }
    }
}

function selectProject(gid, name) {
    showToast(`Выбран проект: ${name}`, 'info');
    document.getElementById('importProjectSelect').value = gid;
    document.getElementById('tasksProjectSelect').value = gid;
    document.getElementById('fullImportProjectSelect').value = gid;
    
    // Подсветка выбранного проекта
    document.querySelectorAll('.project-item').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

function escapeHtml(text) {
    if (!text) return '';
    let div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==================== ПОЛЬЗОВАТЕЛИ ====================
async function loadAsanaUsers() {
    let token = document.getElementById('asana_token').value;
    let workspace = document.getElementById('workspace_gid').value;
    
    logDebug('[USERS] ========== loadAsanaUsers START ==========');
    logDebug('[USERS] Token exists:', !!token);
    logDebug('[USERS] Workspace:', workspace);
    logDebug('[USERS] window.userMappings:', window.userMappings);
    
    if (!token || !workspace) {
        logWarning('[USERS] No token or workspace');
        showToast('Сначала настройте подключение к Asana', 'warning');
        return;
    }
    
    let container = document.getElementById('asanaUsersList');
    if (!container) {
        logError('[USERS] Container #asanaUsersList not found');
        return;
    }
    
    container.innerHTML = '<div style="text-align:center; padding:40px;"><span class="spinner"></span> Загрузка пользователей...</div>';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=get_users&csrf_token=' + encodeURIComponent(csrfToken));
        logDebug('[USERS] Response status:', response.status);
        
        let data = await response.json();
        logDebug('[USERS] Response data success:', data.success);
        logDebug('[USERS] Users count:', data.users ? data.users.length : 0);
        
        if (data.success && data.users) {
            asanaUsersCache = data.users;
            let html = '<table class="data-table"><thead><tr><th>GID</th><th>Имя</th><th>Email</th><th>Статус</th><th>Действия</th></tr></thead><tbody>';
            
            for (let user of data.users) {
                // Проверяем, есть ли сопоставление
                let existingMapping = null;
                if (window.userMappings && window.userMappings.length) {
                    existingMapping = window.userMappings.find(m => m.asana_gid === user.gid);
                    logDebug('[USERS] User ' + user.gid + ' mapping exists:', !!existingMapping);
                }
                
                let statusHtml = existingMapping ? 
                    '<span class="status-success">✅ Сопоставлен</span>' : 
                    '<span class="status-warning">⚠️ Не сопоставлен</span>';
                
                html += `
                    <tr>
                        <td><code>${user.gid.substring(0, 8)}...</code></td>
                        <td>${escapeHtml(user.name || '-')}</td>
                        <td>${escapeHtml(user.email || '-')}</td>
                        <td>${statusHtml}</td>
                        <td><button class="btn-secondary" style="padding:4px 8px;font-size:11px;" onclick="showUserMappingModal('${user.gid}', '${escapeHtml(user.name || '')}', '${escapeHtml(user.email || '')}')">🔗 Сопоставить</button></td>
                    </tr>
                `;
            }
            html += '</tbody></table>';
            container.innerHTML = html;
            logDebug('[USERS] Table rendered successfully');
        } else {
            logError('[USERS] Error loading users:', data.error);
            container.innerHTML = '<div style="text-align:center; padding:40px;">Ошибка загрузки: ' + (data.error || 'Неизвестная ошибка') + '</div>';
        }
    } catch(e) {
        logError('[USERS] Exception:', e.message);
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#f87171;">Ошибка: ' + e.message + '</div>';
    }
    
    logDebug('[USERS] ========== loadAsanaUsers END ==========');
}

async function importUsers() {
    let dryRun = document.getElementById('dryRunMode').checked;
    let btn = document.getElementById('importUsersBtn');
    setButtonLoading(btn, true);
    
    let resultDiv = document.getElementById('usersImportResult');
    resultDiv.innerHTML = '<span class="spinner"></span> Импорт пользователей...';
    
    try {
        // Сначала получаем список пользователей Asana
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=get_users&csrf_token=' + encodeURIComponent(csrfToken));
        let data = await response.json();
        
        if (!data.success || !data.users) {
            throw new Error(data.error || 'Не удалось загрузить пользователей Asana');
        }
        
        // Фильтруем только тех, кто ещё не сопоставлен
        let newUsers = [];
        for (let user of data.users) {
            let existingMapping = window.userMappings ? window.userMappings.find(m => m.asana_gid === user.gid) : null;
            if (!existingMapping) {
                newUsers.push(user);
                logDebug('[IMPORT_USERS] New user to import:', user.gid, user.name);
            } else {
                logDebug('[IMPORT_USERS] Already mapped, skipping:', user.gid, user.name);
            }
        }
        
        if (newUsers.length === 0) {
            resultDiv.innerHTML = '<span class="status-success">✅ Все пользователи уже сопоставлены. Импорт не требуется.</span>';
            setButtonLoading(btn, false);
            return;
        }
        
        logDebug('[IMPORT_USERS] Need to import', newUsers.length, 'new users');
        
        // Импортируем только новых пользователей через отдельный API
        let importResponse = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_new_users&dry_run=' + (dryRun ? 1 : 0) + '&users=' + encodeURIComponent(JSON.stringify(newUsers)) + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let importData = await importResponse.json();
        
        if (importData.success) {
            resultDiv.innerHTML = '<span class="status-success">✅ Импорт завершён. Создано: ' + (importData.created || 0) + '</span>';
            addLogEntry(`Импорт пользователей: создано ${importData.created || 0}`, 'success');
            loadAsanaUsers(); // Обновляем список
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (importData.error || 'Неизвестная ошибка') + '</span>';
        }
    } catch(e) {
        logError('[IMPORT_USERS] Exception:', e.message);
        resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
    } finally {
        setButtonLoading(btn, false);
    }
}

async function autoMatchUsers() {
    let resultDiv = document.getElementById('userMappingsList');
    resultDiv.innerHTML = '<span class="spinner"></span> Автосопоставление...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=auto_match_users&csrf_token=' + encodeURIComponent(csrfToken));
        let data = await response.json();
        
        if (data.success) {
            resultDiv.innerHTML = '<span class="status-success">✅ Автосопоставление завершено. Сопоставлено: ' + (data.matched || 0) + '</span>';
            addLogEntry(`Автосопоставление пользователей: сопоставлено ${data.matched || 0}`, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
        }
    } catch(e) {
        resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
    }
}

async function removeMapping(asanaGid) {
    if (!confirm('Удалить сопоставление для этого пользователя?')) return;
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=remove_mapping&asana_gid=' + asanaGid + '&csrf_token=' + encodeURIComponent(csrfToken));
        let data = await response.json();
        
        if (data.success) {
            showToast('Сопоставление удалено', 'success');
            let row = document.getElementById('mapping-row-' + asanaGid);
            if (row) row.remove();
        } else {
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        showToast('Ошибка: ' + e.message, 'error');
    }
}



// ==================== ИМПОРТ ====================
async function importProject() {
    let projectGid = document.getElementById('importProjectSelect').value;
    let importMode = getImportMode();
    
    logDebug('[IMPORT_PROJECT] projectGid: ' + projectGid + ', mode: ' + importMode);
    
    if (!projectGid) {
        showToast('Выберите проект для импорта', 'warning');
        return;
    }
    
    let btn = document.getElementById('importProjectBtn');
    if (!btn) {
        showToast('Ошибка: кнопка не найдена. Обновите страницу.', 'error');
        return;
    }
    
    setButtonLoading(btn, true);
    let resultDiv = document.getElementById('projectImportResult');
    if (resultDiv) resultDiv.innerHTML = '<span class="spinner"></span> Импорт проекта...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_project&project_gid=' + encodeURIComponent(projectGid) + '&mode=' + importMode + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-success">✅ Проект импортирован: ' + (data.project_title || '') + '</span>';
            addLogEntry('Импортирован проект: ' + (data.project_title || projectGid) + ' (mode: ' + importMode + ')', 'success');
            showToast('Проект импортирован', 'success');
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

async function importTasks() {
    let projectGid = document.getElementById('tasksProjectSelect').value;
    let importMode = getImportMode();
    
    logDebug('[IMPORT_TASKS] projectGid: ' + projectGid + ', mode: ' + importMode);
    
    if (!projectGid) {
        showToast('Выберите проект для импорта задач', 'warning');
        return;
    }
    
    let btn = document.getElementById('importTasksBtn');
    if (!btn) {
        showToast('Ошибка: кнопка не найдена. Обновите страницу.', 'error');
        return;
    }
    
    setButtonLoading(btn, true);
    let resultDiv = document.getElementById('tasksImportResult');
    if (resultDiv) resultDiv.innerHTML = '<span class="spinner"></span> Импорт задач...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_tasks&project_gid=' + encodeURIComponent(projectGid) + '&mode=' + importMode + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-success">✅ Импортировано задач: ' + (data.tasks_count || 0) + ', подзадач: ' + (data.subtasks_count || 0) + '</span>';
            addLogEntry('Импорт задач: ' + (data.tasks_count || 0) + ' задач, ' + (data.subtasks_count || 0) + ' подзадач (mode: ' + importMode + ')', 'success');
            showToast('Импортировано ' + (data.tasks_count || 0) + ' задач', 'success');
        } else {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

async function importMessages() {
    let taskGid = document.getElementById('messagesTaskSelect').value;
    let importMode = getImportMode();
    
    logDebug('[IMPORT_MESSAGES] taskGid: ' + taskGid + ', mode: ' + importMode);
    
    if (!taskGid) {
        showToast('Сначала импортируйте задачи, затем выберите задачу', 'warning');
        return;
    }
    
    let btn = document.getElementById('importMessagesBtn');
    if (!btn) {
        showToast('Ошибка: кнопка не найдена. Обновите страницу.', 'error');
        return;
    }
    
    setButtonLoading(btn, true);
    let resultDiv = document.getElementById('messagesImportResult');
    if (resultDiv) resultDiv.innerHTML = '<span class="spinner"></span> Импорт сообщений...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_messages&task_gid=' + encodeURIComponent(taskGid) + '&mode=' + importMode + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-success">✅ Импортировано сообщений: ' + (data.messages_count || 0) + '</span>';
            addLogEntry('Импорт сообщений: ' + (data.messages_count || 0) + ' сообщений (mode: ' + importMode + ')', 'success');
        } else {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

async function importFiles() {
    let taskGid = document.getElementById('messagesTaskSelect').value;
    let importMode = getImportMode();
    
    logDebug('[IMPORT_FILES] taskGid: ' + taskGid + ', mode: ' + importMode);
    
    if (!taskGid) {
        showToast('Сначала импортируйте задачи, затем выберите задачу', 'warning');
        return;
    }
    
    let btn = document.getElementById('importFilesBtn');
    if (!btn) {
        showToast('Ошибка: кнопка не найдена. Обновите страницу.', 'error');
        return;
    }
    
    setButtonLoading(btn, true);
    let resultDiv = document.getElementById('messagesImportResult');
    if (resultDiv) resultDiv.innerHTML = '<span class="spinner"></span> Импорт файлов...';
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=import_files&task_gid=' + encodeURIComponent(taskGid) + '&mode=' + importMode + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-success">✅ Импортировано файлов: ' + (data.files_count || 0) + '</span>';
            addLogEntry('Импорт файлов: ' + (data.files_count || 0) + ' файлов (mode: ' + importMode + ')', 'success');
        } else {
            if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        if (resultDiv) resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}



function cancelImport() {
    if (currentAbortController) {
        currentAbortController.abort();
        currentAbortController = null;
        showToast('Останавливаем импорт...', 'info');
    } else {
        showToast('Нет активного импорта', 'warning');
    }
}





// ==================== НАСТРОЙКИ ====================
async function saveImportSettings(btnElement) {
    let btn = btnElement || event.target;
    let maxFiles = document.getElementById('maxFilesPerTask').value;
    let maxLength = document.getElementById('maxMessageLength').value;
    let batchSize = document.getElementById('batchSize').value;
    
    setButtonLoading(btn, true);
    let resultDiv = document.getElementById('settingsResult');
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_settings&max_files=' + maxFiles + '&max_length=' + maxLength + '&batch_size=' + batchSize + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        
        if (data.success) {
            resultDiv.innerHTML = '<span class="status-success">✅ Настройки сохранены</span>';
            showToast('Настройки сохранены', 'success');
        } else {
            resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</span>';
        }
    } catch(e) {
        resultDiv.innerHTML = '<span class="status-error">❌ Ошибка: ' + e.message + '</span>';
    } finally {
        setButtonLoading(btn, false);
        setTimeout(() => { resultDiv.innerHTML = ''; }, 3000);
    }
}

async function clearImportLog() {
    if (!confirm('Очистить лог импорта? Это действие необратимо.')) return;
    
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php?action=clear_log&csrf_token=' + encodeURIComponent(csrfToken));
        let data = await response.json();
        
        if (data.success) {
            document.getElementById('importLogContainer').innerHTML = '<div class="log-entry">Лог очищен</div>';
            showToast('Лог очищен', 'success');
        } else {
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        showToast('Ошибка: ' + e.message, 'error');
    }
}

// ==================== МОДАЛЬНОЕ ОКНО ДЛЯ СОПОСТАВЛЕНИЯ ====================
// ==================== МОДАЛЬНОЕ ОКНО ДЛЯ СОПОСТАВЛЕНИЯ ====================
function showUserMappingModal(asanaGid, asanaName, asanaEmail) {
    logDebug('[MAPPING] ========== showUserMappingModal START ==========');
    logDebug('[MAPPING] asanaGid:', asanaGid);
    logDebug('[MAPPING] asanaName:', asanaName);
    logDebug('[MAPPING] asanaEmail:', asanaEmail);
    logDebug('[MAPPING] window.systemUsers:', window.systemUsers);
    logDebug('[MAPPING] window.systemUsers length:', window.systemUsers ? window.systemUsers.length : 0);
    
    // Проверяем наличие systemUsers
    if (!window.systemUsers || window.systemUsers.length === 0) {
        logError('[MAPPING] window.systemUsers is empty or not defined!');
        showToast('Ошибка: список пользователей не загружен. Обновите страницу.', 'error');
        return;
    }
    
    let modal = document.createElement('div');
    modal.className = 'modal active';
    modal.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:10000; display:flex; align-items:center; justify-content:center;';
    
    let usersHtml = '<option value="">-- Выберите пользователя --</option>';
    for (let user of window.systemUsers) {
        let userName = user.name || user.login;
        usersHtml += `<option value="${user.uuid}">${escapeHtml(userName)} (${escapeHtml(user.login)})</option>`;
    }
    usersHtml += '<option value="__CREATE__">➕ Создать нового пользователя</option>';
    
    modal.innerHTML = `
        <div class="modal-content" style="background:#121a33; border-radius:16px; width:90%; max-width:500px; border:1px solid rgba(255,255,255,0.1);">
            <div class="modal-header" style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px;">🔗 Сопоставление пользователя</h3>
                <span class="modal-close" style="cursor:pointer; font-size:24px; color:rgba(233,238,252,0.6);" onclick="this.closest('.modal').remove()">&times;</span>
            </div>
            <div class="modal-body" style="padding:20px;">
                <p><strong>Asana пользователь:</strong> ${escapeHtml(asanaName)} (${escapeHtml(asanaEmail)})</p>
                <div class="form-group" style="margin-bottom:16px;">
                    <label style="display:block; margin-bottom:6px; font-weight:500;">Выберите пользователя в системе</label>
                    <select id="mappingUserSelect" style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.12); background:#0b1020; color:#e9eefc;">
                        ${usersHtml}
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="padding:16px 20px; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end; gap:12px;">
                <button class="btn-secondary" style="background:rgba(79,124,255,0.15); border:1px solid rgba(79,124,255,0.3); color:#9bb7ff; padding:8px 16px; border-radius:8px; cursor:pointer;" onclick="this.closest('.modal').remove()">Отмена</button>
                <button class="btn-primary" style="background:#4f7cff; color:white; border:none; padding:8px 16px; border-radius:8px; cursor:pointer;" onclick="saveUserMapping('${asanaGid}', document.getElementById('mappingUserSelect').value, '${escapeHtml(asanaName)}', '${escapeHtml(asanaEmail)}')">Сохранить</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    logDebug('[MAPPING] Modal created and appended to body');
}

async function saveUserMapping(asanaGid, userUuid, asanaName, asanaEmail) {
    logDebug('[MAPPING] ========== saveUserMapping START ==========');
    logDebug('[MAPPING] asanaGid:', asanaGid);
    logDebug('[MAPPING] userUuid:', userUuid);
    logDebug('[MAPPING] asanaName:', asanaName);
    logDebug('[MAPPING] asanaEmail:', asanaEmail);
    logDebug('[MAPPING] csrfToken exists:', !!csrfToken);
    
    if (!userUuid) {
        logWarning('[MAPPING] No user selected');
        showToast('Выберите пользователя', 'warning');
        return;
    }
    
    // Закрываем модальное окно
    let modal = document.querySelector('.modal.active');
    if (modal) {
        modal.remove();
        logDebug('[MAPPING] Modal closed');
    }
    
    // Показываем индикатор загрузки
    showToast('⏳ Сохранение сопоставления...', 'info');
    
    try {
        let requestBody = 'action=save_user_mapping&asana_gid=' + encodeURIComponent(asanaGid) + 
                          '&user_uuid=' + encodeURIComponent(userUuid) + 
                          '&asana_name=' + encodeURIComponent(asanaName) + 
                          '&asana_email=' + encodeURIComponent(asanaEmail) + 
                          '&csrf_token=' + encodeURIComponent(csrfToken);
        
        logDebug('[MAPPING] Request body:', requestBody);
        
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: requestBody
        });
        
        logDebug('[MAPPING] Response status:', response.status);
        
        let data = await response.json();
        logDebug('[MAPPING] Response data:', data);
        
        if (data.success) {
            logDebug('[MAPPING] ✅ Mapping saved successfully');
            showToast('✅ Сопоставление сохранено', 'success');
            // Перезагружаем страницу для обновления списка
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            logError('[MAPPING] ❌ Error saving mapping:', data.error);
            showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    } catch(e) {
        logError('[MAPPING] ❌ Exception:', e.message);
        logError('[MAPPING] Stack:', e.stack);
        showToast('Ошибка: ' + e.message, 'error');
    }
    
    logDebug('[MAPPING] ========== saveUserMapping END ==========');
}

// Глобальная функция для ручного сопоставления из консоли
window.updateUserMapping = async function(asanaGid, userUuid) {
    try {
        let response = await fetch(window.APP_BASE + '/api/asana_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_user_mapping&asana_gid=' + encodeURIComponent(asanaGid) + '&user_uuid=' + encodeURIComponent(userUuid) + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        let data = await response.json();
        console.log('Mapping result:', data);
        if (data.success) location.reload();
    } catch(e) {
        console.error('Error:', e);
    }
};

// ==================== ИНИЦИАЛИЗАЦИЯ ====================
document.addEventListener('DOMContentLoaded', () => {
    // Автоматическая загрузка проектов если есть токен
    let token = document.getElementById('asana_token').value;
    let workspace = document.getElementById('workspace_gid').value;
    if (token && workspace && token !== 'your_personal_access_token_here' && workspace !== 'your_workspace_gid_here') {
        setTimeout(() => loadProjects(), 500);
    }
});
</script>




<?php require_once __DIR__ . '/../layouts/page_end.php'; ?>

