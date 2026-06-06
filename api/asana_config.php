<?php
// asana_config.php
// Конфигурация для импорта из Asana
// Версия: 2.9 - ГИБРИДНАЯ (на базе старой архитектуры с доработками из новой)

// ========== ASANA API НАСТРОЙКИ ==========
define('ASANA_PERSONAL_ACCESS_TOKEN', '2/1200108408678292/1214957608084423:b85caa6b0e132438380081eb71b15a19');
define('ASANA_WORKSPACE_GID', '1200000000060706');
define('ASANA_API_BASE', 'https://app.asana.com/api/1.0');
define('ASANA_API_VERSION', '1.0');

// ========== НАСТРОЙКИ ИМПОРТА ==========
define('IMPORT_BATCH_SIZE', 50);           // Количество объектов за один запрос
define('IMPORT_MAX_FILES', 100);           // Максимальное количество файлов на задачу
define('IMPORT_MAX_MESSAGE_LENGTH', 65000); // Максимальная длина сообщения

// ========== ПУТИ ДЛЯ ВРЕМЕННОГО ХРАНЕНИЯ ФАЙЛОВ ==========
define('TEMP_DOWNLOAD_DIR', __DIR__ . '/temp_asana_files/');

// ========== РЕЖИМЫ ИМПОРТА ==========
define('IMPORT_MODE_DRY_RUN', 1);    // Только анализ, без записи в БД
define('IMPORT_MODE_FULL', 2);       // Полный импорт
define('IMPORT_MODE_UPDATE', 3);     // Обновление существующих данных

// ========== ЗАДЕРЖКИ ДЛЯ RATE LIMITING ==========
define('ASANA_REQUEST_DELAY', 0.2);      // 300ms между API запросами
define('ASANA_STORY_DELAY', 0.1);        // 100ms между сторис
define('ASANA_FILE_DELAY', 0.1);         // 200ms между файлами
define('ASANA_BATCH_DELAY', 0.2);          // секунд между батчами

// ========== ТАЙМАУТЫ ==========
define('ASANA_API_TIMEOUT', 150);          // Таймаут API запроса
define('ASANA_API_CONNECT_TIMEOUT', 40);  // Таймаут соединения
define('ASANA_DOWNLOAD_TIMEOUT', 600);    // Таймаут скачивания файла
define('ASANA_DOWNLOAD_CONNECT_TIMEOUT', 50);

// ========== ЧАНКОВЫЙ ИМПОРТ (ДЛЯ ОБХОДА ТАЙМАУТОВ) ==========
define('IMPORT_TASK_CHUNK_SIZE', 4);      // Задач за один запрос (МАЛЕНЬКИЙ!)
define('IMPORT_MESSAGE_CHUNK_SIZE', 3);   // Задач для импорта сообщений за раз (МАЛЕНЬКИЙ!)
define('IMPORT_CHUNK_DELAY_MS', 400);    // Пауза между чанками (мс)
define('IMPORT_ITEM_DELAY_MS', 100);      // Пауза между задачами (мс)

// ========== ФАЙЛ-СЕМАФОР ДЛЯ ОСТАНОВКИ ИМПОРТА ==========
define('IMPORT_CANCEL_FILE', __DIR__ . '/.import_cancel');

// ========== МАППИНГ ПОЛЕЙ ==========
$asana_field_mapping = [
    'name' => 'title',
    'notes' => 'descr',
    'due_on' => 'time_end_plan',
    'start_on' => 'time_start',
    'completed' => 'status',
    'assignee' => 'assigned_to',
    'created_at' => 'time'
];

$task_status_mapping = [
    0 => 0,  // Не выполнена
    1 => 1   // Выполнена
];