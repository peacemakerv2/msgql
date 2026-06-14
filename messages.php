<?php
// Что исправлено в v8.7:
// Проблема    Исправление
// Хедер не фиксирован Добавлен position: sticky !important; top: 0 для .chat-header на мобильных
// Панель ввода не фиксирована Возвращено position: fixed !important; bottom: -10px для .message-input-area
// Нет автопрокрутки   .messages-area теперь display: block с правильным padding-bottom
// Кнопка отправки испорчена   Возвращены оригинальные стили .send-btn::before
// ver.8.0 (Window Pagination + Buffer + Full Mutation Handling)
// - v8.0: ОКОННАЯ ПАГИНАЦИЯ С БУФЕРОМ (3 страницы: текущая + 2 соседних)
//   * Гарантированный порядок ORDER BY time ASC
//   * Загрузка окна страниц (текущая + buffer_size сверху/снизу)
//   * Кеширование страниц для быстрого доступа
//   * Плавная подгрузка при скролле за границы окна
//   * Полный перерасчёт пагинации при мутациях (добавление/удаление/редактирование)
//   * Автоскролл к последнему сообщению при обновлении
//   * Множественные попытки прокрутки для надёжности
// - v7.9: ИСПРАВЛЕНА НАЧАЛЬНАЯ ПРОКРУТКА
// - v7.8: Исправлена хронология сообщений
// - v7.7: Обработка 503 ошибок
// ==================== BLOCK END: File header v8.0 ====================

// Определяем $appBase для работы в подкаталогах
if (!isset($appBase) || $appBase === '') {
    $appBase = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($appBase === '' || $appBase === '\\') $appBase = '';
}

// 🔥 ПРИНУДИТЕЛЬНАЯ ОЧИСТКА БУФЕРА ПЕРЕД ВСЕМ
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Если это AJAX-запрос с action, устанавливаем флаг
if (isset($_POST['action'])) {
    define('AJAX_REQUEST', true);
}

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/init.php';

// Проверяем авторизацию
msgql_require_login();

// ========== ПРОВЕРКА ПРИНУДИТЕЛЬНОЙ СМЕНЫ ПАРОЛЯ ==========
// Получаем имя текущего скрипта
$current_script_for_check = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Проверяем, нужно ли перенаправить пользователя на смену пароля
if (function_exists('msgql_check_force_password_redirect')) {
    msgql_check_force_password_redirect($current_script_for_check);
}
// ========== КОНЕЦ ПРОВЕРКИ ==========

$upload_dir = __DIR__ . '/uploads/messages/';
if (!file_exists($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

$current_user_uuid = msgql_current_user_uuid();
$db = msgql_db();
$is_admin = msgql_is_admin();
$per_page = 500;


// Получаем логин пользователя для отображения в мобильной шторке
$current_user_login_display = '';
if ($db && !empty($current_user_uuid)) {
    $stmt = $db->prepare("SELECT login FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $current_user_login_display = $user_data['login'] ?? 'Пользователь';
    $stmt->close();
}

// Получаем настройки звука пользователя из БД
$sound_enabled = 1;
$sound_interval_sec = 600;
if ($db && !empty($current_user_uuid)) {
    $stmt = $db->prepare("SELECT sound_enabled, sound_interval_sec FROM users WHERE uuid = ?");
    $stmt->bind_param("s", $current_user_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sound_enabled = (int)($row['sound_enabled'] ?? 1);
        $sound_interval_sec = (int)($row['sound_interval_sec'] ?? 600);
    }
    $stmt->close();
}

// ==================== ФУНКЦИИ РАБОТЫ С ФАЙЛАМИ ====================
function save_files_and_link_to_message($files_info, $message_uuid, $user_uuid) {
    log_debug("[SAVE_FILES] ========== START ==========");
    log_debug("[SAVE_FILES] files_info count: " . count($files_info));
    
    if (empty($files_info)) {
        log_debug("[SAVE_FILES] No files to save");
        return [];
    }

    $db = msgql_db();
    $uploaded = [];

    $check_user = $db->prepare("SELECT uuid FROM users WHERE uuid = ?");
    $check_user->bind_param("s", $user_uuid);
    $check_user->execute();
    $user_exists = $check_user->get_result()->num_rows > 0;
    $check_user->close();

    if (!$user_exists) {
        log_error("[SAVE_FILES] User not found: {$user_uuid}");
        throw new Exception("User not found: {$user_uuid}");
    }

    try {
        $db->begin_transaction();
        
        foreach ($files_info as $index => $file_info) {
            $time = msgql_now_ms();
            $time_str = (string)$time;
            $user_tz_offset_minutes = msgql_user_timezone_offset();
            $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
            $stamp = msgql_stamp($user_tz_offset_hours);

            $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception("Prepare files insert: " . $db->error);
            
            $stmt->bind_param("ssssisss",
                $file_info['uuid'],
                $file_info['orig_name'],
                $file_info['storage_name'],
                $file_info['mime'],
                $file_info['size_bytes'],
                $user_uuid,
                $time_str,
                $stamp
            );
            
            if (!$stmt->execute()) throw new Exception("Execute files insert: " . $stmt->error);
            $stmt->close();

            $link = $db->prepare("INSERT INTO message_files (message_uuid, file_uuid) VALUES (?, ?)");
            if (!$link) throw new Exception("Prepare message_files insert: " . $db->error);
            
            $link->bind_param("ss", $message_uuid, $file_info['uuid']);
            if (!$link->execute()) throw new Exception("Execute message_files insert: " . $link->error);
            $link->close();

            $uploaded[] = [
                'uuid' => $file_info['uuid'],
                'name' => $file_info['orig_name'],
                'size' => $file_info['size_formatted'],
                'size_bytes' => $file_info['size_bytes'],
                'mime' => $file_info['mime'],
                'url' => "download.php?file={$file_info['uuid']}"
            ];
        }
        
        $db->commit();
        log_debug("[SAVE_FILES] SUCCESS, saved " . count($uploaded) . " files");
        return $uploaded;

    } catch (Exception $e) {
        $db->rollback();
        log_error("[SAVE_FILES] ERROR: " . $e->getMessage());
        
        foreach ($files_info as $file_info) {
            if (isset($file_info['tmp_path']) && file_exists($file_info['tmp_path'])) {
                @unlink($file_info['tmp_path']);
            }
        }
        throw $e;
    }
}

function upload_message_files($files, &$uploaded_files_info, &$error_message = '', &$security_details = null, $ignore_security = false) {
    log_debug("[UPLOAD_MESSAGE_FILES] === START ===");
    if (!class_exists('UploadSecurity')) {
        require_once __DIR__ . '/lib/upload_security.php';
    }
    
    $upload_dir = __DIR__ . '/uploads/messages/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $error_message = "Не удалось создать директорию для загрузки";
            return false;
        }
    }
    
    $uploaded_files_info = [];
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return true;
    }
    
    $hasRealFiles = false;
    foreach ($files['name'] as $name) {
        if (!empty($name)) {
            $hasRealFiles = true;
            break;
        }
    }
    if (!$hasRealFiles) return true;
    
    $validation = UploadSecurity::validateMultipleFiles($files, null, $ignore_security);
    
    if (!$validation['valid']) {
        $error_message = $validation['error'];
        $security_details = [];
        foreach ($validation['files'] as $idx => $file_result) {
            if (!$file_result['valid'] && isset($file_result['security_found'])) {
                $security_details[] = [
                    'filename' => $files['name'][$idx] ?? 'unknown',
                    'issues' => $file_result['security_found']
                ];
            }
        }
        return false;
    }
    
    $validatedFiles = $validation['files'];
    $count = count($validatedFiles);
    
    for ($i = 0; $i < $count; $i++) {
        $validated = $validatedFiles[$i];
        if (!$validated['valid']) {
            $error_message = $validated['error'];
            return false;
        }
        
        $safe_name = $validated['safe_name'] ?? $files['name'][$i];
        $orig_name = $files['name'][$i];
        $storage_name = UploadSecurity::generateSecureFilename($safe_name, 'msg');
        $target_path = $upload_dir . $storage_name;
        
        if (move_uploaded_file($files['tmp_name'][$i], $target_path)) {
            $file_uuid = msgql_uuid_v4();
            $size_formatted = msgql_format_file_size($validated['size']);
            $uploaded_files_info[] = [
                'storage_name' => $storage_name,
                'uuid' => $file_uuid,
                'orig_name' => $safe_name,
                'mime' => $validated['mime'],
                'size_bytes' => $validated['size'],
                'size_formatted' => $size_formatted,
                'tmp_path' => $target_path
            ];
        } else {
            $error_message = "Не удалось сохранить файл {$safe_name}";
            return false;
        }
    }
    return true;
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ СООБЩЕНИЙ ====================
function get_message_files($message_uuid) {
    $db = msgql_db();
    $files = [];
    $fstmt = $db->prepare("SELECT f.* FROM files f JOIN message_files mf ON f.uuid = mf.file_uuid WHERE mf.message_uuid = ?");
    if ($fstmt) {
        $fstmt->bind_param("s", $message_uuid);
        $fstmt->execute();
        $fres = $fstmt->get_result();
        while ($file = $fres->fetch_assoc()) {
            $files[] = [
                'uuid' => $file['uuid'],
                'name' => $file['orig_name'],
                'size' => msgql_format_file_size($file['size_bytes']),
                'size_bytes' => (int)$file['size_bytes'],
                'mime' => $file['mime'],
                'url' => "download.php?file={$file['uuid']}"
            ];
        }
        $fstmt->close();
    }
    return $files;
}

function get_reply_to_data($reply_to_uuid) {
    if (empty($reply_to_uuid)) return null;
    
    $db = msgql_db();
    $stmt = $db->prepare("SELECT m.uuid, m.text, m.time, u.name as user_name, u.login as user_login FROM messages m LEFT JOIN users u ON m.user_uuid = u.uuid WHERE m.uuid = ?");
    $stmt->bind_param("s", $reply_to_uuid);
    $stmt->execute();
    $reply = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($reply) {
        return [
            'uuid' => $reply['uuid'],
            'user_name' => $reply['user_name'] ?: $reply['user_login'],
            'text' => $reply['text'],
            'time' => (int)$reply['time']
        ];
    }
    
    return [
        'uuid' => $reply_to_uuid,
        'user_name' => 'Удалённый пользователь',
        'text' => '[Сообщение удалено]',
        'time' => 0,
        'deleted' => true
    ];
}

function get_message_data($message_uuid) {
    $db = msgql_db();
    $stmt = $db->prepare("
        SELECT m.*, u.name as user_name, u.login as user_login,
        reply.uuid as reply_uuid, reply.text as reply_text, reply.time as reply_time,
        reply_user.name as reply_user_name, reply_user.login as reply_user_login
        FROM messages m
        JOIN users u ON m.user_uuid = u.uuid
        LEFT JOIN messages reply ON m.reply_to_uuid = reply.uuid
        LEFT JOIN users reply_user ON reply.user_uuid = reply_user.uuid
        WHERE m.uuid = ?
    ");
    if (!$stmt) return null;
    $stmt->bind_param("s", $message_uuid);
    $stmt->execute();
    $msg = $stmt->get_result()->fetch_assoc();
    if (!$msg) return null;
    
    $reply_data = null;
    if (!empty($msg['reply_uuid'])) {
        $reply_text = $msg['reply_text'] ?? '';
        $reply_user_name = $msg['reply_user_name'] ?: $msg['reply_user_login'];
        
        if (empty($reply_text) || strpos($reply_text, '<') !== false) {
            $orig_stmt = $db->prepare("SELECT text, user_uuid FROM messages WHERE uuid = ?");
            $orig_stmt->bind_param("s", $msg['reply_uuid']);
            $orig_stmt->execute();
            $orig_msg = $orig_stmt->get_result()->fetch_assoc();
            if ($orig_msg) {
                $reply_text = $orig_msg['text'];
                $user_stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                $user_stmt->bind_param("s", $orig_msg['user_uuid']);
                $user_stmt->execute();
                $orig_user = $user_stmt->get_result()->fetch_assoc();
                if ($orig_user) {
                    $reply_user_name = $orig_user['name'] ?: $orig_user['login'];
                }
                $user_stmt->close();
            }
            $orig_stmt->close();
        }
        
        $reply_data = [
            'uuid' => $msg['reply_uuid'],
            'user_name' => $reply_user_name,
            'text' => $reply_text,
            'time' => (int)($msg['reply_time'] ?? 0)
        ];
    }
    
    $files = get_message_files($message_uuid);
    
    return [
        'uuid' => $msg['uuid'],
        'text' => $msg['text'],
        'reply_to' => $reply_data,
        'time' => (int)$msg['time'],
        'stamp' => $msg['stamp'],
        'user_uuid' => $msg['user_uuid'],
        'user_name' => $msg['user_name'] ?: $msg['user_login'],
        'is_read' => (int)$msg['is_read'],
        'files' => $files
    ];
}

function get_task_messages($task_uuid, $last_time = 0) {
    $messages = msgql_get_accessible_messages(msgql_current_user_uuid(), $task_uuid, $last_time);
    $db = msgql_db();
    
    foreach ($messages as &$msg) {
        if (!empty($msg['reply_to']) && !empty($msg['reply_to']['uuid'])) continue;
        
        if (!empty($msg['reply_to_uuid'])) {
            $reply_to = get_reply_to_data($msg['reply_to_uuid']);
            if ($reply_to) {
                $msg['reply_to'] = $reply_to;
            }
        }
        
        if (!empty($msg['reply_to_uuid']) && empty($msg['reply_to'])) {
            $msg['reply_to'] = [
                'uuid' => $msg['reply_to_uuid'],
                'user_name' => 'Удалённый пользователь',
                'text' => '[Сообщение удалено]',
                'time' => 0,
                'deleted' => true
            ];
        }
    }
    return $messages;
}

function mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db) {
    if ($is_admin) {
        $sql = "UPDATE messages SET is_read = 1 WHERE task_uuid = ? AND user_uuid != ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $task_uuid, $current_user_uuid);
    } else {
        $sql = "UPDATE messages m
                SET m.is_read = 1
                WHERE m.task_uuid = ?
                AND m.user_uuid != ?
                AND EXISTS (
                    SELECT 1 FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                    WHERE m.task_uuid = t.uuid
                    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
                    AND p.created_by_uuid != ?
                )";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("sssss", $task_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid);
    }
    $stmt->execute();
    $marked_count = $db->affected_rows;
    $stmt->close();
    return $marked_count;
}

function mark_messages_read_batch($message_uuids, $task_uuid, $current_user_uuid, $is_admin, $db) {
    if (empty($message_uuids)) return 0;
    $placeholders = implode(',', array_fill(0, count($message_uuids), '?'));
    
    if ($is_admin) {
        $sql = "UPDATE messages SET is_read = 1 WHERE uuid IN ($placeholders) AND user_uuid != ?";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 's';
        $params = array_merge($message_uuids, [$current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    } else {
        $sql = "UPDATE messages m
                SET m.is_read = 1
                WHERE m.uuid IN ($placeholders)
                AND m.user_uuid != ?
                AND EXISTS (
                    SELECT 1 FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
                    WHERE m.task_uuid = t.uuid
                    AND (t.assigned_to_uuid = ? OR upp.can_view = 1)
                    AND p.created_by_uuid != ?
                )";
        $stmt = $db->prepare($sql);
        $types = str_repeat('s', count($message_uuids)) . 'ssss';
        $params = array_merge($message_uuids, [$current_user_uuid, $current_user_uuid, $current_user_uuid, $current_user_uuid]);
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $marked_count = $db->affected_rows;
    $stmt->close();
    return $marked_count;
}

function get_unread_count($user_uuid, $is_admin, $db) {
    if ($is_admin) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE is_read = 0 AND user_uuid != ?");
        $stmt->bind_param("s", $user_uuid);
    } else {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT m.uuid) as cnt
            FROM messages m
            JOIN tasks t ON m.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE m.is_read = 0
            AND m.user_uuid != ?
            AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)");
        $stmt->bind_param("ssss", $user_uuid, $user_uuid, $user_uuid, $user_uuid);
    }
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

// ==================== BLOCK START: sort_tasks_by_last_message_v2 v2.2 ====================
// ver.2.2 (2026-06-02) - УЛУЧШЕНА НАДЕЖНОСТЬ СОРТИРОВКИ
// - Явное приведение типов к int для времени и счетчиков (защита от строкового сравнения)
// - Добавлено подробное логирование первых элементов для отладки
// - Гарантированный приоритет: задачи с сообщениями > задачи без сообщений
function sort_tasks_by_last_message_v2(&$taskList, $msg_info) {
    if (empty($taskList)) {
        return;
    }
    log_debug("[SORT_TASKS_v2.2] Sorting " . count($taskList) . " tasks");
    
    usort($taskList, function($a, $b) use ($msg_info) {
        $a_time = (int)($msg_info[$a['uuid']]['last_msg_time'] ?? 0);
        $b_time = (int)($msg_info[$b['uuid']]['last_msg_time'] ?? 0);
        $a_cnt = (int)($msg_info[$a['uuid']]['cnt'] ?? 0);
        $b_cnt = (int)($msg_info[$b['uuid']]['cnt'] ?? 0);

        // 1. Если у обеих задач нет сообщений, сортируем по времени создания (новые сверху)
        if ($a_time == 0 && $b_time == 0) {
            $a_task_time = (int)($a['time'] ?? 0);
            $b_task_time = (int)($b['time'] ?? 0);
            if ($a_task_time != $b_task_time) {
                return $b_task_time - $a_task_time;
            }
            return $b_cnt - $a_cnt;
        }
        
        // 2. Задачи с сообщениями ВСЕГДА выше, чем задачи без сообщений
        if ($a_time == 0) return 1;
        if ($b_time == 0) return -1;
        
        // 3. Сортировка по убыванию времени последнего сообщения (новые сверху)
        if ($a_time != $b_time) {
            return $b_time - $a_time;
        }
        
        // 4. При одинаковом времени последнего сообщения - по времени создания задачи
        $a_task_time = (int)($a['time'] ?? 0);
        $b_task_time = (int)($b['time'] ?? 0);
        if ($a_task_time != $b_task_time) {
            return $b_task_time - $a_task_time;
        }
        
        // 5. Если всё совпадает, по количеству сообщений
        return $b_cnt - $a_cnt;
    });
    
    // Рекурсивно сортируем подзадачи
    foreach ($taskList as &$task) {
        if (!empty($task['subtasks'])) {
            sort_tasks_by_last_message_v2($task['subtasks'], $msg_info);
        }
    }
    
    log_debug("[SORT_TASKS_v2.2] Sorting completed");
}
// ==================== BLOCK END: sort_tasks_by_last_message_v2 v2.2 ====================



// ==================== BLOCK START: get_project_tasks_for_messenger v2.3 ====================
// ver.2.3 (2026-06-02) - ИСПРАВЛЕНА СОРТИРОВКА ПОДЗАДАЧ
// - Сначала собираем все задачи (родители и подзадачи) в плоский массив
// - Применяем ГЛОБАЛЬНУЮ сортировку к плоскому массиву
// - Это гарантирует, что подзадача с новым сообщением поднимется в самый верх списка проекта
function get_project_tasks_for_messenger($project_uuid) {
    $db = msgql_db();
    $cu = msgql_current_user_uuid();
    $tasks = msgql_get_accessible_tasks($cu, $project_uuid);
    log_debug("[GET_TASKS_v2.3] Getting tasks for project: {$project_uuid}");
    
    $msg_info = [];
    $task_uuids = [];
    
    // 1. Собираем все UUID задач (включая подзадачи)
    $collect_uuids = function($task_list) use (&$task_uuids, &$collect_uuids) {
        foreach ($task_list as $t) {
            $task_uuids[] = $t['uuid'];
            if (!empty($t['subtasks'])) {
                $collect_uuids($t['subtasks']);
            }
        }
    };
    $collect_uuids($tasks);
    
    // 2. Получаем информацию о сообщениях для всех собранных UUID
    if (!empty($task_uuids)) {
        $placeholders = implode(',', array_fill(0, count($task_uuids), '?'));
        $stmt = $db->prepare("
            SELECT task_uuid, COUNT(*) as cnt, MAX(time) as last_msg_time
            FROM messages
            WHERE task_uuid IN ($placeholders)
            GROUP BY task_uuid
        ");
        $types = str_repeat('s', count($task_uuids));
        $stmt->bind_param($types, ...$task_uuids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $msg_info[$row['task_uuid']] = [
                'cnt' => (int)$row['cnt'],
                'last_msg_time' => (int)($row['last_msg_time'] ?? 0)
            ];
        }
        $stmt->close();
    }
    
    log_debug("[GET_TASKS_v2.3] Found " . count($msg_info) . " tasks with messages out of " . count($task_uuids) . " total tasks");
    
    // 3. Собираем плоский список всех задач
    $flat = [];
    $walk = function($list) use (&$walk, &$flat, $msg_info) {
        foreach ($list as $t) {
            $flat[] = [
                'uuid' => $t['uuid'],
                'title' => $t['title'],
                'parent_task_uuid' => $t['parent_task_uuid'] ?? null,
                'assignee_name' => $t['assignee_name'] ?? null,
                'messages_count' => $msg_info[$t['uuid']]['cnt'] ?? 0,
                'last_msg_time' => $msg_info[$t['uuid']]['last_msg_time'] ?? 0,
                'time' => (int)($t['time'] ?? 0)
            ];
            if (!empty($t['subtasks'])) {
                $walk($t['subtasks']);
            }
        }
    };
    $walk($tasks);
    
    // 4. ГЛОБАЛЬНАЯ СОРТИРОВКА плоского списка
    usort($flat, function($a, $b) {
        $a_time = (int)($a['last_msg_time'] ?? 0);
        $b_time = (int)($b['last_msg_time'] ?? 0);
        $a_cnt = (int)($a['messages_count'] ?? 0);
        $b_cnt = (int)($b['messages_count'] ?? 0);

        // 1. Если у обеих задач нет сообщений, сортируем по времени создания (новые сверху)
        if ($a_time == 0 && $b_time == 0) {
            if ($a['time'] != $b['time']) {
                return $b['time'] - $a['time'];
            }
            return $b_cnt - $a_cnt;
        }
        
        // 2. Задачи с сообщениями ВСЕГДА выше, чем задачи без сообщений
        if ($a_time == 0) return 1;
        if ($b_time == 0) return -1;
        
        // 3. Сортировка по убыванию времени последнего сообщения (новые сверху)
        if ($a_time != $b_time) {
            return $b_time - $a_time;
        }
        
        // 4. При одинаковом времени последнего сообщения - по времени создания задачи
        if ($a['time'] != $b['time']) {
            return $b['time'] - $a['time'];
        }
        
        // 5. Если всё совпадает, по количеству сообщений
        return $b_cnt - $a_cnt;
    });
    
    log_debug("[GET_TASKS_v2.3] Returned " . count($flat) . " flattened and globally sorted tasks for project {$project_uuid}");
    return $flat;
}
// ==================== BLOCK END: get_project_tasks_for_messenger v2.3 ====================

// ==================== BLOCK START: get_latest_task_with_unread v2.0 ====================
// ver.1.0 (2026-06-11) - Функция для получения последней задачи с непрочитанными сообщениями
// ver.2.0 (2026-06-11) - Приоритет: сначала задачи с непрочитанными сообщениями,
//                        потом последние активные задачи
// - Возвращает информацию о задаче с непрочитанным сообщением (самое позднее)
// - Если нет непрочитанных, возвращает задачу с последним сообщением (старое поведение)
function get_latest_task_with_unread(string $user_uuid, bool $is_admin, mysqli $db): ?array {
    log_debug("[GET_LATEST_TASK_UNREAD] v2.0 - User: {$user_uuid}, is_admin: " . ($is_admin ? 'true' : 'false'));
    
    // ПРИОРИТЕТ 1: Задача с самым поздним НЕПРОЧИТАННЫМ сообщением
    if ($is_admin) {
        $sql = "
            SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title as project_title,
                   m.time as last_message_time
            FROM messages m
            JOIN tasks t ON m.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            WHERE m.is_read = 0 AND m.user_uuid != ?
            ORDER BY m.time DESC
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            log_error("[GET_LATEST_TASK_UNREAD] Prepare failed: " . $db->error);
            return null;
        }
        $stmt->bind_param("s", $user_uuid);
    } else {
        $sql = "
            SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title as project_title,
                   m.time as last_message_time
            FROM messages m
            JOIN tasks t ON m.task_uuid = t.uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE m.is_read = 0 AND m.user_uuid != ?
            AND (p.created_by_uuid = ? OR t.assigned_to_uuid = ? OR upp.can_view = 1)
            ORDER BY m.time DESC
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            log_error("[GET_LATEST_TASK_UNREAD] Prepare failed: " . $db->error);
            return null;
        }
        $stmt->bind_param("ssss", $user_uuid, $user_uuid, $user_uuid, $user_uuid);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $task_with_unread = $result->fetch_assoc();
    $stmt->close();
    
    if ($task_with_unread) {
        log_debug("[GET_LATEST_TASK_UNREAD] Found task with unread message: {$task_with_unread['uuid']} - {$task_with_unread['title']}");
        return [
            'uuid' => $task_with_unread['uuid'],
            'title' => $task_with_unread['title'],
            'project_uuid' => $task_with_unread['project_uuid'],
            'project_title' => $task_with_unread['project_title']
        ];
    }
    
    log_debug("[GET_LATEST_TASK_UNREAD] No unread messages found, falling back to latest active task");
    
    // ПРИОРИТЕТ 2: Последняя задача с сообщениями (старое поведение)
    if ($is_admin) {
        $sql = "
            SELECT t.uuid, t.title, t.project_uuid, p.title as project_title,
                   MAX(m.time) as last_message_time
            FROM tasks t
            JOIN messages m ON t.uuid = m.task_uuid
            JOIN projects p ON t.project_uuid = p.uuid
            GROUP BY t.uuid
            ORDER BY last_message_time DESC
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
    } else {
        $sql = "
            SELECT t.uuid, t.title, t.project_uuid, p.title as project_title,
                   MAX(m.time) as last_message_time
            FROM tasks t
            JOIN messages m ON t.uuid = m.task_uuid
            JOIN projects p ON t.project_uuid = p.uuid
            LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ?
            WHERE (p.created_by_uuid = ? OR upp.can_view = 1)
            GROUP BY t.uuid
            ORDER BY last_message_time DESC
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $user_uuid, $user_uuid);
        }
    }
    
    if (!$stmt) {
        log_error("[GET_LATEST_TASK_UNREAD] Fallback prepare failed: " . $db->error);
        return null;
    }
    
    $stmt->execute();
    $last_active = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($last_active) {
        log_debug("[GET_LATEST_TASK_UNREAD] Fallback - latest active task: {$last_active['uuid']} - {$last_active['title']}");
        return [
            'uuid' => $last_active['uuid'],
            'title' => $last_active['title'],
            'project_uuid' => $last_active['project_uuid'],
            'project_title' => $last_active['project_title']
        ];
    }
    
    log_debug("[GET_LATEST_TASK_UNREAD] No tasks found at all");
    return null;
}
// ==================== BLOCK END: get_latest_task_with_unread v2.0 ====================


// ==================== AJAX ОБРАБОТЧИК ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false, 'error' => ''];
    $action = $_POST['action'] ?? '';

    try {
        if (!$db) {
            throw new Exception('DB connection failed');
        }

        $mutating_actions = ['send_message', 'delete_message', 'edit_message', 'delete_file', 'mark_messages_read', 'mark_task_read', 'delete_message_file', 'add_files_to_message', 'replace_message_file'];
        if (in_array($action, $mutating_actions)) {
            msgql_csrf_check_and_exit();
        }

        // ========== SEND MESSAGE ==========
        if ($action === 'send_message') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $text = trim($_POST['text'] ?? '');
            $reply_to_uuid = $_POST['reply_to_uuid'] ?? null;

            if (empty($reply_to_uuid) || $reply_to_uuid === 'null' || $reply_to_uuid === '') {
                $reply_to_uuid = null;
            }

            if (!empty($text)) {
                $text = convert_links_to_markers($text);
            }

            $hasFiles = isset($_FILES['files']) && is_array($_FILES['files']['name']) && !empty(array_filter($_FILES['files']['name']));

            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } elseif (empty($text) && !$hasFiles) {
                $response['error'] = 'Введите текст или прикрепите файл';
            } elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) {
                $response['error'] = 'Нет доступа к задаче';
                $response['no_permission'] = true;
            } elseif ($hasFiles && !msgql_can_upload_file($current_user_uuid, $task_uuid)) {
                $response['error'] = 'Нет прав на загрузку файлов';
                $response['no_permission'] = true;
            } elseif (!msgql_can_write_message($current_user_uuid, $task_uuid)) {
                $response['error'] = 'Нет прав на написание сообщений';
                $response['no_permission'] = true;
            } else {
                $uploaded_files_info = [];
                $upload_success = true;
                $ignore_security = isset($_POST['ignore_security']) && $_POST['ignore_security'] === '1';
                
                if ($hasFiles) {
                    $upload_error = '';
                    $security_details = null;
                    $upload_success = upload_message_files($_FILES['files'], $uploaded_files_info, $upload_error, $security_details, $ignore_security);
                    
                    if (!$upload_success || empty($uploaded_files_info)) {
                        if ($security_details && !$ignore_security) {
                            $response['needs_confirmation'] = true;
                            $response['security_details'] = $security_details;
                            $response['security_message'] = $upload_error;
                        } else {
                            $response['error'] = $upload_error ?: 'Ошибка загрузки файлов.';
                        }
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }

                $message_uuid = msgql_uuid_v4();
                $time = msgql_now_ms();
                $time_str = (string)$time;
                $user_tz_offset_minutes = msgql_user_timezone_offset();
                $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                $stamp = msgql_stamp($user_tz_offset_hours);

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare("INSERT INTO messages (uuid, task_uuid, user_uuid, text, reply_to_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception("Prepare message: " . $db->error);
                    $stmt->bind_param("sssssss", $message_uuid, $task_uuid, $current_user_uuid, $text, $reply_to_uuid, $time_str, $stamp);
                    if (!$stmt->execute()) throw new Exception("Execute message: " . $db->error);
                    $stmt->close();

                    $saved_files = [];
                    if ($hasFiles && !empty($uploaded_files_info)) {
                        $saved_files = save_files_and_link_to_message($uploaded_files_info, $message_uuid, $current_user_uuid);
                    }
                    
                    $db->commit();

                    $response['success'] = true;
                    $response['message_uuid'] = $message_uuid;
                    if (!empty($saved_files)) {
                        $response['files'] = $saved_files;
                    }
                    $response['message'] = get_message_data($message_uuid);

                    // Обработка упоминаний
                    if (!empty($text) && preg_match_all('/@([a-zA-Z0-9а-яА-Я_]+)/u', $text, $matches)) {
                        $mentioned_logins = $matches[1];
                        $placeholders = implode(',', array_fill(0, count($mentioned_logins), '?'));
                        $stmt_mentions = $db->prepare("SELECT uuid, name, login FROM users WHERE login IN ($placeholders) AND status = 0");
                        $stmt_mentions->bind_param(str_repeat('s', count($mentioned_logins)), ...$mentioned_logins);
                        $stmt_mentions->execute();
                        $mentioned_users = $stmt_mentions->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt_mentions->close();

                        $subscribe_stmt = $db->prepare("INSERT IGNORE INTO task_subscribers (task_uuid, user_uuid, subscribed_at, subscribed_by_uuid, is_active) VALUES (?, ?, ?, ?, 1)");
                        $now = msgql_now_ms();
                        $now_str = (string)$now;
                        $modified_text = $text;
                        
                        log_debug("[MENTIONS] Found " . count($mentioned_users) . " mentioned users in message {$message_uuid}");

                        foreach ($mentioned_users as $mentioned) {
                            $check_sub = $db->prepare("SELECT id, is_active FROM task_subscribers WHERE task_uuid = ? AND user_uuid = ?");
                            $check_sub->bind_param("ss", $task_uuid, $mentioned['uuid']);
                            $check_sub->execute();
                            $existing = $check_sub->get_result()->fetch_assoc();
                            $check_sub->close();
                            
                            if ($existing) {
                                if ($existing['is_active'] == 0) {
                                    $update_sub = $db->prepare("UPDATE task_subscribers SET is_active = 1, subscribed_at = ?, subscribed_by_uuid = ? WHERE id = ?");
                                    $update_sub->bind_param("ssi", $now_str, $current_user_uuid, $existing['id']);
                                    $update_sub->execute();
                                    $update_sub->close();
                                    log_debug("[MENTIONS] Reactivated subscription for user {$mentioned['login']} (UUID: {$mentioned['uuid']}) to task {$task_uuid}");
                                } else {
                                    log_debug("[MENTIONS] User {$mentioned['login']} already subscribed to task {$task_uuid}");
                                }
                            } else {
                                $subscribe_stmt->bind_param("ssis", $task_uuid, $mentioned['uuid'], $now_str, $current_user_uuid);
                                $subscribe_stmt->execute();
                                log_debug("[MENTIONS] Auto-subscribed user {$mentioned['login']} (UUID: {$mentioned['uuid']}) to task {$task_uuid}");
                            }
                            
                            $display_name = !empty($mentioned['name']) ? $mentioned['name'] : $mentioned['login'];
                            $pattern = '/@' . preg_quote($mentioned['login'], '/') . '\b/';
                            $replacement = '@' . $mentioned['login'] . ' ' . $display_name . ', ';
                            $modified_text = preg_replace($pattern, $replacement, $modified_text, 1);
                        }
                        $subscribe_stmt->close();
                        
                        log_debug("[MENTIONS] Modified text: " . substr($modified_text, 0, 200));

                        if ($modified_text !== $text) {
                            $update_stmt = $db->prepare("UPDATE messages SET text = ? WHERE uuid = ?");
                            $update_stmt->bind_param("ss", $modified_text, $message_uuid);
                            $update_stmt->execute();
                            $update_stmt->close();
                            $response['message']['text'] = $modified_text;
                            log_debug("[MENTIONS] Message text updated with user names");
                        }
                    }

                    // Уведомления
                    if (class_exists('NotificationCenter')) {
                        $author_stmt = $db->prepare("SELECT name, login FROM users WHERE uuid = ?");
                        $author_stmt->bind_param("s", $current_user_uuid);
                        $author_stmt->execute();
                        $author = $author_stmt->get_result()->fetch_assoc();
                        $author_stmt->close();
                        $author_display_name = $author['name'] ?: $author['login'];

                        log_debug("[SEND_MESSAGE] Calling NotificationCenter::notifyNewMessage for task: {$task_uuid}, message: {$message_uuid}");

                        NotificationCenter::notifyNewMessage(
                            $message_uuid,
                            $task_uuid,
                            $text,
                            $author_display_name,
                            $current_user_uuid
                        );
                    }
                    
                    if (function_exists('msgql_send_sse_event')) {
                        $event_data = [
                            'type' => 'new_message',
                            'message_uuid' => $message_uuid,
                            'task_uuid' => $task_uuid,
                            'user_uuid' => $current_user_uuid,
                            'time' => $time,
                            'timestamp' => msgql_now_ms()
                        ];
                        msgql_send_sse_event($task_uuid, 'new_message', $event_data);
                        
                        $unread_data = [
                            'type' => 'unread_update',
                            'message_uuid' => $message_uuid,
                            'task_uuid' => $task_uuid,
                            'count' => get_unread_count($current_user_uuid, $is_admin, $db),
                            'timestamp' => msgql_now_ms()
                        ];
                        msgql_send_sse_event($task_uuid, 'unread_update', $unread_data);
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    if (!empty($uploaded_files_info)) {
                        foreach ($uploaded_files_info as $file_info) {
                            if (isset($file_info['tmp_path']) && file_exists($file_info['tmp_path'])) {
                                @unlink($file_info['tmp_path']);
                            }
                        }
                    }
                    $response['error'] = 'Ошибка при сохранении: ' . $e->getMessage();
                }
            }
        // ========== LOAD MESSAGES PAGED v8.0 ==========
        // ========== LOAD MESSAGES PAGED v8.0 ==========
        } elseif ($action === 'load_messages_paged') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $requested_page = max(0, (int)($_POST['page'] ?? 0));
            $just_count = isset($_POST['just_count']) && $_POST['just_count'] === '1';
            $buffer_size = (int)($_POST['buffer_size'] ?? 1);

            log_debug("[PAGINATION_v8] Request - task_uuid: $task_uuid, requested_page: $requested_page, just_count: " . ($just_count ? 'true' : 'false') . ", buffer_size: $buffer_size");

            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
                $response['messages'] = [];
            } elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) {
                $response['error'] = 'Нет доступа к задаче';
                $response['messages'] = [];
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE task_uuid = ?");
                $stmt->bind_param("s", $task_uuid);
                $stmt->execute();
                $total_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                $total_pages = ($total_count > 0) ? (int)ceil($total_count / $per_page) : 1;
                $total_pages = max(1, $total_pages);
                
                log_debug("[PAGINATION_v8] total_count=$total_count, total_pages=$total_pages, per_page=$per_page, requested_page=$requested_page");
                
                if ($just_count) {
                    $response['success'] = true;
                    $response['total_count'] = $total_count;
                    $response['total_pages'] = $total_pages;
                    $response['messages'] = [];
                    $response['current_window_page'] = 0;
                    $response['window_start_page'] = 0;
                    $response['window_end_page'] = 0;
                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                $requested_page = max(0, min($requested_page, $total_pages - 1));
                
                $window_start_page = max(0, $requested_page - $buffer_size);
                $window_end_page = min($total_pages - 1, $requested_page + $buffer_size);
                
                log_debug("[PAGINATION_v8] Window: start_page=$window_start_page, end_page=$window_end_page (buffer=$buffer_size)");
                
                $sql_parts = [];
                $params = [];
                $types = "";
                
                for ($page = $window_start_page; $page <= $window_end_page; $page++) {
                    $offset = $page * $per_page;
                    $sql_parts[] = "(SELECT m.*, u.name as user_name, u.login as user_login, ? as page_num 
                                     FROM messages m 
                                     JOIN users u ON m.user_uuid = u.uuid 
                                     WHERE m.task_uuid = ? 
                                     ORDER BY m.time ASC 
                                     LIMIT ? OFFSET ?)";
                    $params[] = $page;
                    $params[] = $task_uuid;
                    $params[] = $per_page;
                    $params[] = $offset;
                    $types .= "issi";
                }
                
                $sql = implode(" UNION ALL ", $sql_parts) . " ORDER BY time ASC";
                
                log_debug("[PAGINATION_v8] Executing window query with " . count($sql_parts) . " parts");
                
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    log_error("[PAGINATION_v8] Prepare failed: " . $db->error);
                    $response['error'] = 'Ошибка подготовки запроса';
                    $response['messages'] = [];
                } else {
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $messages_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    log_debug("[PAGINATION_v8] Retrieved " . count($messages_raw) . " messages from window");
                    
                    $messages_by_page = [];
                    foreach ($messages_raw as $msg) {
                        $page_num = (int)$msg['page_num'];
                        if (!isset($messages_by_page[$page_num])) {
                            $messages_by_page[$page_num] = [];
                        }
                        $messages_by_page[$page_num][] = $msg;
                        unset($msg['page_num']);
                    }
                    
                    $response['success'] = true;
                    $response['total_count'] = $total_count;
                    $response['total_pages'] = $total_pages;
                    $response['current_window_page'] = $requested_page;
                    $response['window_start_page'] = $window_start_page;
                    $response['window_end_page'] = $window_end_page;
                    $response['pages_data'] = [];
                    
                    foreach ($messages_by_page as $page_num => $page_messages) {
                        $messages = [];
                        foreach ($page_messages as $msg) {
                            $files = get_message_files($msg['uuid']);
                            $reply_to = get_reply_to_data($msg['reply_to_uuid']);
                            
                            $messages[] = [
                                'uuid' => $msg['uuid'],
                                'task_uuid' => $msg['task_uuid'],
                                'text' => $msg['text'],
                                'time' => (int)$msg['time'],
                                'stamp' => $msg['stamp'],
                                'user_uuid' => $msg['user_uuid'],
                                'user_name' => $msg['user_name'] ?: $msg['user_login'],
                                'is_read' => (int)$msg['is_read'],
                                'reply_to_uuid' => $msg['reply_to_uuid'],
                                'reply_to' => $reply_to,
                                'files' => $files
                            ];
                        }
                        
                        $response['pages_data'][$page_num] = [
                            'page' => $page_num,
                            'messages' => $messages,
                            'has_older' => ($page_num > 0),
                            'has_newer' => ($page_num + 1) < $total_pages
                        ];
                    }
                    
                    log_debug("[PAGINATION_v8] Response ready. Current window page: $requested_page, pages in window: " . count($messages_by_page));
                }  // ← ЗАКРЫВАЕМ else для if (!$stmt)
            }  // ← ЗАКРЫВАЕМ else для if (empty($task_uuid))
        // ==================== BLOCK END: LOAD MESSAGES PAGED v8.0 ====================

        // ========== DELETE MESSAGE ==========
        } elseif ($action === 'delete_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            log_debug("[DELETE_MESSAGE] Start: $message_uuid");
            
            if (empty($message_uuid)) {
                $response['error'] = 'Не указан UUID сообщения';
            } else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$msg) {
                    $response['error'] = 'Сообщение не найдено';
                } elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав для удаления';
                } else {
                    $task_uuid = $msg['task_uuid'];
                    
                    $files_stmt = $db->prepare("SELECT file_uuid FROM message_files WHERE message_uuid = ?");
                    $files_stmt->bind_param("s", $message_uuid);
                    $files_stmt->execute();
                    $file_uuids = $files_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $files_stmt->close();

                    $del_mf = $db->prepare("DELETE FROM message_files WHERE message_uuid = ?");
                    $del_mf->bind_param("s", $message_uuid);
                    $del_mf->execute();
                    $del_mf->close();

                    $del = $db->prepare("DELETE FROM messages WHERE uuid = ?");
                    $del->bind_param("s", $message_uuid);
                    $response['success'] = $del->execute();
                    $del->close();

                    if ($response['success'] && !empty($file_uuids)) {
                        foreach ($file_uuids as $file_item) {
                            $file_uuid = $file_item['file_uuid'];
                            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                            $check_msg->bind_param("s", $file_uuid);
                            $check_msg->execute();
                            $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                            $check_msg->close();

                            $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                            $check_task->bind_param("s", $file_uuid);
                            $check_task->execute();
                            $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                            $check_task->close();

                            if ($msg_usage == 0 && $task_usage == 0) {
                                $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                                $file_stmt->bind_param("s", $file_uuid);
                                $file_stmt->execute();
                                $file_row = $file_stmt->get_result()->fetch_assoc();
                                $file_stmt->close();

                                if ($file_row) {
                                    $disk_paths = [
                                        __DIR__ . '/uploads/messages/' . $file_row['storage_name'],
                                        __DIR__ . '/uploads/tasks/' . $file_row['storage_name']
                                    ];
                                    foreach ($disk_paths as $path) {
                                        if (file_exists($path)) @unlink($path);
                                    }
                                }
                                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                $del_file->bind_param("s", $file_uuid);
                                $del_file->execute();
                                $del_file->close();
                            }
                        }
                    }

                    if ($response['success'] && function_exists('msgql_send_sse_event')) {
                        $event_data = [
                            'type' => 'message_deleted',
                            'message_uuid' => $message_uuid,
                            'task_uuid' => $task_uuid,
                            'deleted_by_uuid' => $current_user_uuid,
                            'deleted_at' => msgql_now_ms()
                        ];
                        msgql_send_sse_event($task_uuid, 'message_deleted', $event_data);
                        
                        $unread_data = [
                            'type' => 'unread_update',
                            'task_uuid' => $task_uuid,
                            'count' => get_unread_count($current_user_uuid, $is_admin, $db),
                            'timestamp' => msgql_now_ms()
                        ];
                        msgql_send_sse_event($task_uuid, 'unread_update', $unread_data);
                    }
                }
            }

        // ========== EDIT MESSAGE ==========
        } elseif ($action === 'edit_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $new_text = trim($_POST['text'] ?? '');
            $reply_to_uuid = $_POST['reply_to_uuid'] ?? null;

            if (empty($reply_to_uuid) || $reply_to_uuid === 'null' || $reply_to_uuid === '') {
                $reply_to_uuid = null;
            }

            if (empty($message_uuid)) {
                $response['error'] = 'Не указан UUID сообщения';
            } elseif (empty($new_text)) {
                $response['error'] = 'Текст сообщения не может быть пустым';
            } else {
                $new_text = convert_links_to_markers($new_text);
                
                $stmt = $db->prepare("SELECT user_uuid, task_uuid, text FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$msg) {
                    $response['error'] = 'Сообщение не найдено';
                } elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав для редактирования';
                } else {
                    $old_text = $msg['text'];
                    $stmt = $db->prepare("UPDATE messages SET text = ?, reply_to_uuid = ? WHERE uuid = ?");
                    $stmt->bind_param("sss", $new_text, $reply_to_uuid, $message_uuid);
                    
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = get_message_data($message_uuid);
                        
                        if (function_exists('msgql_send_sse_event')) {
                            $event_data = [
                                'type' => 'message_edited',
                                'message_uuid' => $message_uuid,
                                'task_uuid' => $msg['task_uuid'],
                                'new_text' => $new_text,
                                'old_text' => $old_text,
                                'edited_by_uuid' => $current_user_uuid,
                                'edited_at' => msgql_now_ms()
                            ];
                            msgql_send_sse_event($msg['task_uuid'], 'message_edited', $event_data);
                        }
                    } else {
                        $response['error'] = 'Ошибка обновления: ' . $db->error;
                    }
                    $stmt->close();
                }
            }
            
        // ========== ADD FILES TO MESSAGE ==========
        } elseif ($action === 'add_files_to_message') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            log_debug("[ADD_FILES] Start for message: $message_uuid");
            
            if (empty($message_uuid)) {
                $response['error'] = 'Не указан UUID сообщения';
            } elseif (!isset($_FILES['files']) || empty(array_filter($_FILES['files']['name']))) {
                $response['error'] = 'Не выбраны файлы';
            } else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$msg) {
                    $response['error'] = 'Сообщение не найдено';
                } elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав';
                } elseif (!msgql_can_upload_file($current_user_uuid, $msg['task_uuid'])) {
                    $response['error'] = 'Нет прав на загрузку файлов';
                } else {
                    $uploaded_files_info = [];
                    $upload_error = '';
                    $security_details = null;
                    $ignore_security = isset($_POST['ignore_security']) && $_POST['ignore_security'] === '1';
                    
                    $upload_success = upload_message_files($_FILES['files'], $uploaded_files_info, $upload_error, $security_details, $ignore_security);
                    
                    if (!$upload_success || empty($uploaded_files_info)) {
                        if ($security_details && !$ignore_security) {
                            $response['needs_confirmation'] = true;
                            $response['security_details'] = $security_details;
                            $response['security_message'] = $upload_error;
                        } else {
                            $response['error'] = $upload_error ?: 'Ошибка загрузки файлов.';
                        }
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $db->begin_transaction();
                    try {
                        $saved_files = save_files_and_link_to_message($uploaded_files_info, $message_uuid, $current_user_uuid);
                        $db->commit();
                        
                        $response['success'] = true;
                        $response['message'] = get_message_data($message_uuid);
                        $response['message']['files_added'] = $saved_files;
                        
                        if (function_exists('msgql_send_sse_event')) {
                            $event_data = [
                                'type' => 'message_edited',
                                'message_uuid' => $message_uuid,
                                'task_uuid' => $msg['task_uuid'],
                                'edited_by_uuid' => $current_user_uuid,
                                'edited_at' => msgql_now_ms()
                            ];
                            msgql_send_sse_event($msg['task_uuid'], 'message_edited', $event_data);
                        }
                    } catch (Exception $e) {
                        $db->rollback();
                        $response['error'] = 'Ошибка при сохранении: ' . $e->getMessage();
                    }
                }
            }

        // ========== REPLACE MESSAGE FILE ==========
        } elseif ($action === 'replace_message_file') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $old_file_uuid = $_POST['old_file_uuid'] ?? '';
            
            if (empty($message_uuid) || empty($old_file_uuid)) {
                $response['error'] = 'Не указаны параметры';
            } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $response['error'] = 'Ошибка загрузки файла';
            } else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$msg) {
                    $response['error'] = 'Сообщение не найдено';
                } elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав';
                } else {
                    $check_stmt = $db->prepare("SELECT 1 FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                    $check_stmt->bind_param("ss", $message_uuid, $old_file_uuid);
                    $check_stmt->execute();
                    $file_belongs = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    
                    if (!$file_belongs) {
                        $response['error'] = 'Файл не принадлежит этому сообщению';
                    } else {
                        if (!class_exists('UploadSecurity')) {
                            require_once __DIR__ . '/lib/upload_security.php';
                        }
                        $validation = UploadSecurity::validateUploadedFile($_FILES['file']);
                        if (!$validation['valid']) {
                            $response['error'] = $validation['error'];
                            echo json_encode($response);
                            exit;
                        } else {
                            $upload_dir = __DIR__ . '/uploads/messages/';
                            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                            
                            $storage_name = UploadSecurity::generateSecureFilename($validation['safe_name'], 'msg');
                            $target_path = $upload_dir . $storage_name;
                            
                            if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
                                $db->begin_transaction();
                                try {
                                    $new_file_uuid = msgql_uuid_v4();
                                    $time = msgql_now_ms();
                                    $time_str = (string)$time;
                                    $user_tz_offset_minutes = msgql_user_timezone_offset();
                                    $user_tz_offset_hours = -$user_tz_offset_minutes / 60;
                                    $stamp = msgql_stamp($user_tz_offset_hours);
                                    
                                    $stmt = $db->prepare("INSERT INTO files (uuid, orig_name, storage_name, mime, size_bytes, uploaded_by_uuid, time, stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssisss", $new_file_uuid, $validation['safe_name'], $storage_name, $validation['mime'], $validation['size'], $current_user_uuid, $time_str, $stamp);
                                    $stmt->execute();
                                    $stmt->close();
                                    
                                    $update_stmt = $db->prepare("UPDATE message_files SET file_uuid = ? WHERE message_uuid = ? AND file_uuid = ?");
                                    $update_stmt->bind_param("sss", $new_file_uuid, $message_uuid, $old_file_uuid);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                    
                                    $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                                    $check_msg->bind_param("s", $old_file_uuid);
                                    $check_msg->execute();
                                    $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                                    $check_msg->close();
                                    
                                    $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                                    $check_task->bind_param("s", $old_file_uuid);
                                    $check_task->execute();
                                    $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                                    $check_task->close();
                                    
                                    if ($msg_usage == 0 && $task_usage == 0) {
                                        $file_stmt = $db->prepare("SELECT storage_name FROM files WHERE uuid = ?");
                                        $file_stmt->bind_param("s", $old_file_uuid);
                                        $file_stmt->execute();
                                        $old_file = $file_stmt->get_result()->fetch_assoc();
                                        $file_stmt->close();
                                        
                                        if ($old_file) {
                                            $paths = [
                                                __DIR__ . '/uploads/messages/' . $old_file['storage_name'],
                                                __DIR__ . '/uploads/tasks/' . $old_file['storage_name']
                                            ];
                                            foreach ($paths as $p) { if (file_exists($p)) @unlink($p); }
                                        }
                                        $del_stmt = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                        $del_stmt->bind_param("s", $old_file_uuid);
                                        $del_stmt->execute();
                                        $del_stmt->close();
                                    }
                                    
                                    $db->commit();
                                    $response['success'] = true;
                                    $response['message'] = get_message_data($message_uuid);
                                    
                                    if (function_exists('msgql_send_sse_event')) {
                                        msgql_send_sse_event($msg['task_uuid'], 'message_edited', [
                                            'type' => 'message_edited',
                                            'message_uuid' => $message_uuid,
                                            'task_uuid' => $msg['task_uuid'],
                                            'edited_by_uuid' => $current_user_uuid,
                                            'edited_at' => msgql_now_ms()
                                        ]);
                                    }
                                } catch (Exception $e) {
                                    $db->rollback();
                                    if (file_exists($target_path)) @unlink($target_path);
                                    throw $e;
                                }
                            } else {
                                $response['error'] = 'Ошибка сохранения файла на диск';
                            }
                        }
                    }
                }
            }

        // ========== DELETE MESSAGE FILE ==========
        } elseif ($action === 'delete_message_file') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            $file_uuid = $_POST['file_uuid'] ?? '';
            
            if (empty($message_uuid) || empty($file_uuid)) {
                $response['error'] = 'Не указаны параметры';
            } else {
                $stmt = $db->prepare("SELECT user_uuid, task_uuid FROM messages WHERE uuid = ?");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$msg) {
                    $response['error'] = 'Сообщение не найдено';
                } elseif ($msg['user_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав';
                } else {
                    $check_stmt = $db->prepare("SELECT 1 FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                    $check_stmt->bind_param("ss", $message_uuid, $file_uuid);
                    $check_stmt->execute();
                    $file_belongs = $check_stmt->get_result()->num_rows > 0;
                    $check_stmt->close();
                    
                    if (!$file_belongs) {
                        $response['error'] = 'Файл не принадлежит этому сообщению';
                    } else {
                        $db->begin_transaction();
                        try {
                            $del_link = $db->prepare("DELETE FROM message_files WHERE message_uuid = ? AND file_uuid = ?");
                            $del_link->bind_param("ss", $message_uuid, $file_uuid);
                            $del_link->execute();
                            $del_link->close();
                            
                            $check_msg = $db->prepare("SELECT COUNT(*) as cnt FROM message_files WHERE file_uuid = ?");
                            $check_msg->bind_param("s", $file_uuid);
                            $check_msg->execute();
                            $msg_usage = $check_msg->get_result()->fetch_assoc()['cnt'];
                            $check_msg->close();
                            
                            $check_task = $db->prepare("SELECT COUNT(*) as cnt FROM task_files WHERE file_uuid = ?");
                            $check_task->bind_param("s", $file_uuid);
                            $check_task->execute();
                            $task_usage = $check_task->get_result()->fetch_assoc()['cnt'];
                            $check_task->close();
                            
                            if ($msg_usage == 0 && $task_usage == 0) {
                                $file_stmt = $db->prepare("SELECT storage_name, orig_name FROM files WHERE uuid = ?");
                                $file_stmt->bind_param("s", $file_uuid);
                                $file_stmt->execute();
                                $file_row = $file_stmt->get_result()->fetch_assoc();
                                $file_stmt->close();
                                
                                if ($file_row) {
                                    $paths = [
                                        __DIR__ . '/uploads/messages/' . $file_row['storage_name'],
                                        __DIR__ . '/uploads/tasks/' . $file_row['storage_name']
                                    ];
                                    foreach ($paths as $p) { if (file_exists($p)) @unlink($p); }
                                }
                                $del_file = $db->prepare("DELETE FROM files WHERE uuid = ?");
                                $del_file->bind_param("s", $file_uuid);
                                $del_file->execute();
                                $del_file->close();
                            }
                            $db->commit();
                            $response['success'] = true;
                            $response['message'] = get_message_data($message_uuid);
                            
                            if (function_exists('msgql_send_sse_event')) {
                                msgql_send_sse_event($msg['task_uuid'], 'message_edited', [
                                    'type' => 'message_edited',
                                    'message_uuid' => $message_uuid,
                                    'task_uuid' => $msg['task_uuid'],
                                    'edited_by_uuid' => $current_user_uuid,
                                    'edited_at' => msgql_now_ms()
                                ]);
                            }
                        } catch (Exception $e) {
                            $db->rollback();
                            $response['error'] = 'Ошибка при удалении файла: ' . $e->getMessage();
                        }
                    }
                }
            }

        // ========== GET PROJECT TASKS ==========
        } elseif ($action === 'get_project_tasks') {
            $project_uuid = $_POST['project_uuid'] ?? '';
            if (empty($project_uuid)) {
                $response['error'] = 'Не указан проект';
                $response['tasks'] = [];
            } else {
                $can_proj = msgql_can_access_project($current_user_uuid, $project_uuid, 'view');
                if (!$can_proj) {
                    $response['error'] = 'Нет доступа к проекту';
                    $response['tasks'] = [];
                } else {
                    $response['success'] = true;
                    $response['tasks'] = get_project_tasks_for_messenger($project_uuid);
                }
            }

        // ========== INIT TASK ==========
        } elseif ($action === 'init_task') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } else {
                $can_view = msgql_can_access_task($current_user_uuid, $task_uuid, 'view');
                if (!$can_view) {
                    $response['error'] = 'Нет доступа к задаче';
                } else {
                    $can_write = msgql_can_write_message($current_user_uuid, $task_uuid);
                    $marked_count = mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db);
                    $response['success'] = true;
                    $response['can_write'] = $can_write;
                    $response['marked_count'] = $marked_count ?? 0;
                }
            }

        // ========== DELETE FILE ==========
        } elseif ($action === 'delete_file') {
            $file_uuid = $_POST['file_uuid'] ?? '';
            if (empty($file_uuid)) {
                $response['error'] = 'Не указан UUID файла';
            } else {
                $stmt = $db->prepare("SELECT uploaded_by_uuid, storage_name, orig_name, size_bytes FROM files WHERE uuid = ?");
                $stmt->bind_param("s", $file_uuid);
                $stmt->execute();
                $file = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$file) {
                    $response['error'] = 'Файл не найден';
                } elseif ($file['uploaded_by_uuid'] !== $current_user_uuid && !$is_admin) {
                    $response['error'] = 'Нет прав для удаления';
                } else {
                    $db->begin_transaction();
                    try {
                        $del_mf = $db->prepare("DELETE FROM message_files WHERE file_uuid = ?");
                        $del_mf->bind_param("s", $file_uuid);
                        $del_mf->execute();
                        $del_mf->close();
                        
                        $del_tf = $db->prepare("DELETE FROM task_files WHERE file_uuid = ?");
                        $del_tf->bind_param("s", $file_uuid);
                        $del_tf->execute();
                        $del_tf->close();
                        
                        $paths = [
                            __DIR__ . '/uploads/messages/' . $file['storage_name'],
                            __DIR__ . '/uploads/tasks/' . $file['storage_name']
                        ];
                        foreach ($paths as $path) {
                            if (file_exists($path)) @unlink($path);
                        }
                        
                        $del = $db->prepare("DELETE FROM files WHERE uuid = ?");
                        $del->bind_param("s", $file_uuid);
                        $del->execute();
                        $del->close();
                        
                        $db->commit();
                        $response['success'] = true;
                    } catch (Exception $e) {
                        $db->rollback();
                        $response['error'] = $e->getMessage();
                    }
                }
            }

        // ========== GET LATEST TASK ==========
        } elseif ($action === 'get_latest_task') {
            $stmt = $db->prepare("
                SELECT DISTINCT t.uuid, t.title, t.project_uuid, p.title as project_title, m.time as last_message_time
                FROM tasks t
                JOIN messages m ON t.uuid = m.task_uuid
                JOIN projects p ON t.project_uuid = p.uuid
                WHERE m.user_uuid != ? OR m.user_uuid = ?
                ORDER BY m.time DESC
                LIMIT 1
            ");
            $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
            $stmt->execute();
            $latest_task = $stmt->get_result()->fetch_assoc();
            
            if ($latest_task && msgql_can_access_task($current_user_uuid, $latest_task['uuid'], 'view')) {
                $response['success'] = true;
                $response['task'] = $latest_task;
            } else {
                $response['success'] = false;
                $response['error'] = 'Нет доступных сообщений';
            }

        // ========== GET MESSAGE INFO ==========
        } elseif ($action === 'get_message_info') {
            $message_uuid = $_POST['message_uuid'] ?? '';
            if (empty($message_uuid)) {
                $response['error'] = 'Не указан UUID сообщения';
            } else {
                $stmt = $db->prepare("
                    SELECT m.uuid, m.task_uuid, m.time, t.title as task_title, t.project_uuid, p.title as project_title
                    FROM messages m
                    JOIN tasks t ON m.task_uuid = t.uuid
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE m.uuid = ?
                ");
                $stmt->bind_param("s", $message_uuid);
                $stmt->execute();
                $msg_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$msg_info) {
                    $response['success'] = false;
                    $response['error'] = 'Сообщение не найдено в БД';
                } else {
                    $can_access = msgql_can_access_task($current_user_uuid, $msg_info['task_uuid'], 'view');
                    if ($can_access) {
                        $response['success'] = true;
                        $response['message'] = $msg_info;
                    } else {
                        $response['success'] = false;
                        $response['error'] = 'Нет доступа к задаче';
                    }
                }
            }

        // ========== MARK MESSAGES READ ==========
        } elseif ($action === 'mark_messages_read') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            $message_uuids = isset($_POST['message_uuids']) && is_array($_POST['message_uuids']) ? $_POST['message_uuids'] : [];
            
            if (empty($task_uuid) || empty($message_uuids)) {
                $response['error'] = 'Не указаны параметры';
            } else {
                $marked_count = mark_messages_read_batch($message_uuids, $task_uuid, $current_user_uuid, $is_admin, $db);
                $response['success'] = true;
                $response['marked_count'] = $marked_count;
                $response['unread_count'] = get_unread_count($current_user_uuid, $is_admin, $db);
            }

        // ========== MARK TASK READ ==========
        } elseif ($action === 'mark_task_read') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } elseif (!msgql_can_access_task($current_user_uuid, $task_uuid, 'view')) {
                $response['error'] = 'Нет доступа к задаче';
            } else {
                $marked_count = mark_task_messages_read($task_uuid, $current_user_uuid, $is_admin, $db);
                $response['success'] = true;
                $response['marked_count'] = $marked_count;
                $response['unread_count'] = get_unread_count($current_user_uuid, $is_admin, $db);
            }

        // ========== SEARCH USERS FOR MENTION ==========
        } elseif ($action === 'search_users_for_mention') {
            $query = trim($_POST['query'] ?? '');
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            if (empty($task_uuid)) {
                $response['success'] = true;
                $response['users'] = [];
            } else {
                $taskStmt = $db->prepare("SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
                $taskStmt->bind_param("s", $task_uuid);
                $taskStmt->execute();
                $taskInfo = $taskStmt->get_result()->fetch_assoc();
                $taskStmt->close();
                
                if (!$taskInfo) {
                    $response['users'] = [];
                } else {
                    $project_uuid = $taskInfo['project_uuid'];
                    $created_by = $taskInfo['created_by_uuid'];
                    $assigned_to = $taskInfo['assigned_to_uuid'];
                    $like = "%$query%";
                    
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.uuid, u.name, u.login, u.role
                        FROM users u
                        WHERE u.status = 0
                        AND u.uuid != ?
                        AND (
                            u.role = 0
                            OR u.uuid = ?
                            OR u.uuid = ?
                            OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_view = 1)
                        )
                        AND (u.name LIKE ? OR u.login LIKE ?)
                        ORDER BY
                        CASE
                            WHEN u.role = 0 THEN 1
                            WHEN u.uuid = ? THEN 2
                            WHEN u.uuid = ? THEN 3
                            ELSE 4
                        END,
                        u.name ASC
                        LIMIT 15
                    ");
                    $stmt->bind_param("sssssssss", $current_user_uuid, $created_by, $assigned_to, $project_uuid, $like, $like, $created_by, $assigned_to);
                    $stmt->execute();
                    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    $response['success'] = true;
                    $response['users'] = $users;
                }
            }

        // ==================== BLOCK START: ajax_get_task_info v5.2 (with files) ====================
        // ver.5.1 (2026-06-10) - Базовая версия с parent_chain
        // ver.5.2 (2026-06-11) - ДОБАВЛЕНА ЗАГРУЗКА ФАЙЛОВ ЗАДАЧИ
        } elseif ($action === 'get_task_info') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            
            log_debug("[GET_TASK_INFO] v5.2 Called for task_uuid: {$task_uuid}");
            
            if (empty($task_uuid)) {
                $response['error'] = 'Не указана задача';
            } else {
                // Получаем полную информацию о задаче и проекте
                $task_check = $db->prepare("
                    SELECT t.uuid, t.project_uuid, t.parent_task_uuid, t.title,
                           p.created_by_uuid as project_created_by
                    FROM tasks t
                    JOIN projects p ON t.project_uuid = p.uuid
                    WHERE t.uuid = ?
                ");
                $task_check->bind_param("s", $task_uuid);
                $task_check->execute();
                $task_info_db = $task_check->get_result()->fetch_assoc();
                $task_check->close();
                
                if (!$task_info_db) {
                    $response['error'] = 'Задача не найдена';
                    log_warning("[GET_TASK_INFO] Task not found: {$task_uuid}");
                } else {
                    $project_uuid = $task_info_db['project_uuid'];
                    $direct_parent = $task_info_db['parent_task_uuid'];
                    
                    log_debug("[GET_TASK_INFO] Task belongs to project: {$project_uuid}, direct_parent: " . ($direct_parent ?: 'NULL'));
                    
                    // Проверяем доступ к проекту
                    if (!msgql_can_access_project($current_user_uuid, $project_uuid, 'view')) {
                        $response['error'] = 'Нет доступа к проекту задачи';
                        log_warning("[GET_TASK_INFO] Access denied to project: {$project_uuid}");
                    } else {
                        $task = get_task_data($task_uuid, $current_user_uuid, $db);
                        if ($task) {
                            // ========== v5.1: ПОСТРОЕНИЕ parent_chain через рекурсивный CTE ==========
                            $parent_chain = [];
                            
                            if (!empty($direct_parent)) {
                                log_debug("[GET_TASK_INFO] Building parent chain using recursive CTE starting from: {$direct_parent}");
                                
                                $cte_sql = "
                                    WITH RECURSIVE task_parents AS (
                                        SELECT uuid, parent_task_uuid, title, 1 as level
                                        FROM tasks
                                        WHERE uuid = ?
                                        UNION ALL
                                        SELECT t.uuid, t.parent_task_uuid, t.title, tp.level + 1
                                        FROM tasks t
                                        INNER JOIN task_parents tp ON t.uuid = tp.parent_task_uuid
                                        WHERE tp.parent_task_uuid IS NOT NULL AND tp.level < 50
                                    )
                                    SELECT uuid FROM task_parents ORDER BY level DESC
                                ";
                                
                                $cte_stmt = $db->prepare($cte_sql);
                                if ($cte_stmt) {
                                    $cte_stmt->bind_param("s", $direct_parent);
                                    $cte_stmt->execute();
                                    $parents_result = $cte_stmt->get_result();
                                    
                                    while ($row = $parents_result->fetch_assoc()) {
                                        $parent_chain[] = $row['uuid'];
                                        log_debug("[GET_TASK_INFO] CTE found parent: " . $row['uuid']);
                                    }
                                    $cte_stmt->close();
                                } else {
                                    log_warning("[GET_TASK_INFO] CTE not supported, using iterative fallback");
                                    $current_parent = $direct_parent;
                                    $fallback_stmt = $db->prepare("SELECT parent_task_uuid FROM tasks WHERE uuid = ?");
                                    $fallback_depth = 0;
                                    $fallback_max = 50;
                                    
                                    while ($current_parent && $fallback_depth < $fallback_max) {
                                        log_debug("[GET_TASK_INFO] Fallback adding parent: {$current_parent}");
                                        array_unshift($parent_chain, $current_parent);
                                        
                                        $fallback_stmt->bind_param("s", $current_parent);
                                        $fallback_stmt->execute();
                                        $fallback_row = $fallback_stmt->get_result()->fetch_assoc();
                                        
                                        if ($fallback_row && !empty($fallback_row['parent_task_uuid'])) {
                                            $current_parent = $fallback_row['parent_task_uuid'];
                                        } else {
                                            $current_parent = null;
                                        }
                                        $fallback_depth++;
                                    }
                                    $fallback_stmt->close();
                                }
                                
                                log_debug("[GET_TASK_INFO] Final parent_chain (" . count($parent_chain) . " items): " . json_encode($parent_chain));
                            } else {
                                log_debug("[GET_TASK_INFO] Task is root (no parent)");
                            }
                            
                            // ========== v5.2: ЗАГРУЖАЕМ ФАЙЛЫ ЗАДАЧИ ==========
                            $task_files = get_task_files($task_uuid, $db);
                            log_debug("[GET_TASK_INFO] Found " . count($task_files) . " files for task: {$task_uuid}");
                            
                            $response['success'] = true;
                            $response['task'] = $task;
                            $response['task']['files'] = $task_files;  // ДОБАВЛЯЕМ ФАЙЛЫ
                            $response['task']['parent_chain'] = $parent_chain;
                            $response['task']['project_uuid'] = $task['project_uuid'];
                            $response['task']['direct_parent_uuid'] = $direct_parent;
                        } else {
                            $response['error'] = 'Задача не найдена или нет доступа';
                            log_warning("[GET_TASK_INFO] Task data not accessible: {$task_uuid}");
                        }
                    }
                }
            }
        }
        // ==================== BLOCK END: ajax_get_task_info v5.2 ====================


        // ========== GET TASK USERS ==========
        elseif ($action === 'get_task_users') {
            $task_uuid = $_POST['task_uuid'] ?? '';
            if (empty($task_uuid)) {
                $response['users'] = [];
            } else {
                $taskStmt = $db->prepare("SELECT t.project_uuid, t.assigned_to_uuid, p.created_by_uuid FROM tasks t JOIN projects p ON t.project_uuid = p.uuid WHERE t.uuid = ?");
                $taskStmt->bind_param("s", $task_uuid);
                $taskStmt->execute();
                $taskInfo = $taskStmt->get_result()->fetch_assoc();
                $taskStmt->close();
                
                if (!$taskInfo) {
                    $response['users'] = [];
                } else {
                    $project_uuid = $taskInfo['project_uuid'];
                    $created_by = $taskInfo['created_by_uuid'];
                    $assigned_to = $taskInfo['assigned_to_uuid'];
                    
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.uuid, u.name, u.login, u.role
                        FROM users u
                        WHERE u.status = 0
                        AND u.uuid != ?
                        AND (
                            u.role = 0
                            OR u.uuid = ?
                            OR u.uuid = ?
                            OR u.uuid IN (SELECT user_uuid FROM user_project_permissions WHERE project_uuid = ? AND can_view = 1)
                        )
                        ORDER BY
                        CASE
                            WHEN u.role = 0 THEN 1
                            WHEN u.uuid = ? THEN 2
                            WHEN u.uuid = ? THEN 3
                            ELSE 4
                        END,
                        u.name ASC
                    ");
                    $stmt->bind_param("ssssss", $current_user_uuid, $created_by, $assigned_to, $project_uuid, $created_by, $assigned_to);
                    $stmt->execute();
                    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    
                    $response['success'] = true;
                    $response['users'] = $users;
                }
            }
        } else {
            $response['error'] = 'Unknown action: ' . $action;
        }

    } catch (Throwable $e) {
        $response['error'] = 'Error: ' . substr($e->getMessage(), 0, 100);
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== BLOCK START: Projects sorting and task selection with unread priority v2.0 ====================
// ver.1.0 - Базовая версия (сортировка проектов)
// ver.2.0 (2026-06-11) - ДОБАВЛЕН ПРИОРИТЕТ: сначала непрочитанные сообщения,
//                        потом последние активные задачи
// - Сохранена сортировка проектов
// - Добавлена функция get_latest_task_with_unread() для определения приоритетной задачи
// - Сохранена поддержка URL-параметров task и message
// - Добавлено подробное логирование

// ==================== ПОДГОТОВКА ДАННЫХ ДЛЯ ШАБЛОНА ====================

$projects = msgql_get_accessible_projects($current_user_uuid);
$tasks_by_project = [];
$projects_with_info = [];

foreach ($projects as $p) {
    $project_uuid = $p['uuid'];
    $tasks = get_project_tasks_for_messenger($project_uuid);
    $tasks_by_project[$project_uuid] = $tasks;
    
    $max_last_msg_time = 0;
    $total_messages = 0;
    foreach ($tasks as $task) {
        if ($task['last_msg_time'] > $max_last_msg_time) $max_last_msg_time = $task['last_msg_time'];
        $total_messages += $task['messages_count'];
    }
    
    $projects_with_info[] = [
        'project' => $p,
        'last_msg_time' => $max_last_msg_time,
        'total_messages' => $total_messages
    ];
}

// ==================== BLOCK START: Projects sorting for messenger page v2.0 ====================
// ver.2.0 (2026-06-02) - ДОБАВЛЕНА СОРТИРОВКА ПРОЕКТОВ
// - Проекты сортируются по времени последнего сообщения (новые сверху)
// - Проекты без сообщений идут внизу
// - Добавлено логирование

usort($projects_with_info, function($a, $b) {
    $a_last = $a['last_msg_time'] ?? 0;
    $b_last = $b['last_msg_time'] ?? 0;
    
    // Если у обеих проектов нет сообщений, сортируем по общему количеству сообщений
    if ($a_last == 0 && $b_last == 0) {
        if ($a['total_messages'] != $b['total_messages']) {
            return $b['total_messages'] - $a['total_messages'];
        }
        // Если и сообщений нет, то по времени создания проекта
        $a_time = $a['project']['time'] ?? 0;
        $b_time = $b['project']['time'] ?? 0;
        return $b_time - $a_time;
    }
    
    // Проекты с сообщениями выше, чем проекты без сообщений
    if ($a_last == 0) return 1;
    if ($b_last == 0) return -1;
    
    // Сортировка по убыванию времени последнего сообщения (новые сверху)
    if ($a_last != $b_last) {
        return $b_last - $a_last;
    }
    
    // При одинаковом времени последнего сообщения - по общему количеству сообщений
    if ($a['total_messages'] != $b['total_messages']) {
        return $b['total_messages'] - $a['total_messages'];
    }
    
    // Иначе по времени создания проекта
    $a_time = $a['project']['time'] ?? 0;
    $b_time = $b['project']['time'] ?? 0;
    return $b_time - $a_time;
});

log_debug("[PROJECTS_SORT] Sorted " . count($projects_with_info) . " projects by last_msg_time");

$sorted_projects = array_column($projects_with_info, 'project');
$projects = $sorted_projects;
// ==================== BLOCK END: Projects sorting for messenger page v2.0 ====================

$selected_task_uuid = isset($_GET['task']) ? $_GET['task'] : '';
$selected_message_uuid = isset($_GET['message']) ? $_GET['message'] : '';
$selected_project_uuid = null;
$scroll_to_message = null;

log_debug("[INIT] URL parameters - task: {$selected_task_uuid}, message: {$selected_message_uuid}");

// ==================== BLOCK START: Debug logging for message parameter ====================
if (!empty($selected_message_uuid)) {
    log_debug("[MESSAGES_PAGE] ========== MESSAGE PARAMETER RECEIVED ==========");
    log_debug("[MESSAGES_PAGE] selected_message_uuid: " . $selected_message_uuid);
    log_debug("[MESSAGES_PAGE] current_user_uuid: " . $current_user_uuid);
    log_debug("[MESSAGES_PAGE] is_admin: " . ($is_admin ? 'true' : 'false'));
    
    // Проверяем существование сообщения в БД
    $check_stmt = $db->prepare("SELECT uuid, task_uuid, user_uuid FROM messages WHERE uuid = ?");
    $check_stmt->bind_param("s", $selected_message_uuid);
    $check_stmt->execute();
    $msg_exists = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($msg_exists) {
        log_debug("[MESSAGES_PAGE] Message EXISTS in DB - task_uuid: " . $msg_exists['task_uuid'] . ", user_uuid: " . $msg_exists['user_uuid']);
        
        // Проверяем доступ к задаче
        $has_access = msgql_can_access_task($current_user_uuid, $msg_exists['task_uuid'], 'view');
        log_debug("[MESSAGES_PAGE] User has access to task: " . ($has_access ? 'true' : 'false'));
        
        if (!$has_access) {
            log_debug("[MESSAGES_PAGE] ⚠️ User has NO access to task, will show error");
        }
    } else {
        log_debug("[MESSAGES_PAGE] ❌ Message NOT FOUND in DB for UUID: " . $selected_message_uuid);
    }
    log_debug("[MESSAGES_PAGE] ===============================================");
}
// ==================== BLOCK END: Debug logging for message parameter ====================

// ==================== BLOCK START: Task selection with unread priority v2.0 ====================
// ver.1.0 - Базовая версия (последнее сообщение)
// ver.2.0 (2026-06-11) - ДОБАВЛЕН ПРИОРИТЕТ: сначала непрочитанные сообщения,
//                        потом последние активные задачи

log_debug("[INIT_TASK] ========== START TASK SELECTION v2.0 ==========");

// ПРИОРИТЕТ 1: Прямая ссылка на сообщение (через параметр message)
if (!empty($selected_message_uuid)) {
    log_debug("[INIT_TASK] Processing message parameter: {$selected_message_uuid}");
    $stmt = $db->prepare("SELECT task_uuid FROM messages WHERE uuid = ?");
    $stmt->bind_param("s", $selected_message_uuid);
    $stmt->execute();
    $msg_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($msg_info && msgql_can_access_task($current_user_uuid, $msg_info['task_uuid'], 'view')) {
        $selected_task_uuid = $msg_info['task_uuid'];
        $scroll_to_message = $selected_message_uuid;
        log_debug("[INIT_TASK] Message found, set task_uuid: {$selected_task_uuid}");
    } else {
        $scroll_to_message = null;
        if (!isset($_SESSION['flash_message'])) {
            $_SESSION['flash_message'] = 'Сообщение не найдено или было удалено';
            $_SESSION['flash_type'] = 'warning';
        }
        log_debug("[INIT_TASK] Message not found or no access");
    }
}

// ПРИОРИТЕТ 2: Прямая ссылка на задачу (через параметр task)
if (empty($selected_task_uuid)) {
    $selected_task_uuid = '';
}

if (!empty($selected_task_uuid)) {
    log_debug("[INIT_TASK] Using task from URL parameter: {$selected_task_uuid}");
    
    $taskStmt = $db->prepare("SELECT project_uuid FROM tasks WHERE uuid = ?");
    $taskStmt->bind_param("s", $selected_task_uuid);
    $taskStmt->execute();
    $taskInfo = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();
    
    if ($taskInfo && msgql_can_access_task($current_user_uuid, $selected_task_uuid, 'view')) {
        $selected_project_uuid = $taskInfo['project_uuid'];
        log_debug("[INIT_TASK] Task access confirmed, project_uuid: {$selected_project_uuid}");
    } else {
        log_warning("[INIT_TASK] Task not accessible: {$selected_task_uuid}, resetting");
        $selected_task_uuid = '';
        $selected_project_uuid = null;
    }
}

// ПРИОРИТЕТ 3: Выбор задачи с непрочитанными сообщениями (НОВОЕ ПОВЕДЕНИЕ)
if (empty($selected_task_uuid)) {
    log_debug("[INIT_TASK] No task in URL, checking for tasks with unread messages");
    
    // Получаем актуальное количество непрочитанных сообщений
    $unread_count = get_unread_count($current_user_uuid, $is_admin, $db);
    log_debug("[INIT_TASK] Current unread count: {$unread_count}");
    
    // Проверяем, нужно ли показывать flash-сообщение и выбирать задачу с непрочитанными
    $should_show_flash = false;
    $unread_task = null;
    
    if ($unread_count > 0) {
        if (function_exists('get_latest_task_with_unread')) {
            $unread_task = get_latest_task_with_unread($current_user_uuid, $is_admin, $db);
            $should_show_flash = ($unread_task !== null && !empty($unread_task['uuid']));
        } else {
            log_warning("[INIT_TASK] get_latest_task_with_unread not found, using fallback");
        }
    } else {
        log_debug("[INIT_TASK] No unread messages, skipping flash message");
        // Очищаем flash-сообщение из сессии, если оно там осталось
        if (isset($_SESSION['flash_message']) && strpos($_SESSION['flash_message'], 'непрочитанные сообщения') !== false) {
            unset($_SESSION['flash_message']);
            unset($_SESSION['flash_type']);
            log_debug("[INIT_TASK] Cleaned stale flash message from session");
        }
    }
    
    if ($should_show_flash && $unread_task && !empty($unread_task['uuid'])) {
        $selected_task_uuid = $unread_task['uuid'];
        $selected_project_uuid = $unread_task['project_uuid'];
        log_debug("[INIT_TASK] ✅ Selected task with unread messages: {$selected_task_uuid} - {$unread_task['title']}");
        
        // Показываем flash-сообщение ТОЛЬКО если оно ещё не было показано в этой сессии
        $flash_shown_key = 'flash_unread_shown_' . $unread_task['uuid'];
        if (!isset($_SESSION[$flash_shown_key])) {
            $_SESSION['flash_message'] = "📬 У вас есть непрочитанные сообщения в задаче \"{$unread_task['title']}\"";
            $_SESSION['flash_type'] = 'info';
            $_SESSION[$flash_shown_key] = time();
            log_debug("[INIT_TASK] Flash message set for task: {$unread_task['title']}");
        } else {
            log_debug("[INIT_TASK] Flash message already shown for this task, skipping");
        }
    } else {
        log_debug("[INIT_TASK] No unread messages found, looking for latest active task");
    }
}

// ПРИОРИТЕТ 4: Последняя активная задача (старое поведение - fallback)
if (empty($selected_task_uuid)) {
    log_debug("[INIT_TASK] Finding latest active task with messages");
    
    if ($is_admin) {
        $stmt = $db->prepare("SELECT t.uuid, t.project_uuid, MAX(m.time) as last_msg_time 
                              FROM tasks t 
                              JOIN messages m ON t.uuid = m.task_uuid 
                              GROUP BY t.uuid 
                              ORDER BY last_msg_time DESC 
                              LIMIT 1");
        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $stmt = $db->prepare("SELECT t.uuid, t.project_uuid, MAX(m.time) as last_msg_time 
                              FROM tasks t 
                              JOIN messages m ON t.uuid = m.task_uuid 
                              JOIN projects p ON t.project_uuid = p.uuid 
                              LEFT JOIN user_project_permissions upp ON p.uuid = upp.project_uuid AND upp.user_uuid = ? 
                              WHERE (p.created_by_uuid = ? OR upp.can_view = 1) 
                              GROUP BY t.uuid 
                              ORDER BY last_msg_time DESC 
                              LIMIT 1");
        $stmt->bind_param("ss", $current_user_uuid, $current_user_uuid);
        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    if ($latest && msgql_can_access_task($current_user_uuid, $latest['uuid'], 'view')) {
        $selected_task_uuid = $latest['uuid'];
        $selected_project_uuid = $latest['project_uuid'];
        log_debug("[INIT_TASK] Fallback - selected latest active task: {$selected_task_uuid}");
    } else {
        log_debug("[INIT_TASK] No latest active task found, user has no accessible messages");
    }
}

// Получаем project_title для выбранной задачи (если есть)
if ($selected_task_uuid && $selected_project_uuid) {
    $proj_stmt = $db->prepare("SELECT title FROM projects WHERE uuid = ?");
    $proj_stmt->bind_param("s", $selected_project_uuid);
    $proj_stmt->execute();
    $proj_title = $proj_stmt->get_result()->fetch_assoc();
    $proj_stmt->close();
    log_debug("[INIT_TASK] Final selection - task: {$selected_task_uuid}, project: {$selected_project_uuid}, project_title: " . ($proj_title['title'] ?? 'unknown'));
} elseif ($selected_task_uuid && !$selected_project_uuid) {
    // Fallback: ищем проект по задаче через tasks_by_project
    foreach ($tasks_by_project as $pu => $ts) {
        foreach ($ts as $t) {
            if ($t['uuid'] === $selected_task_uuid) {
                $selected_project_uuid = $pu;
                break 2;
            }
        }
    }
    log_debug("[INIT_TASK] Found project for task via fallback: {$selected_project_uuid}");
}

log_debug("[INIT_TASK] ========== END TASK SELECTION ==========");
// ==================== BLOCK END: Task selection with unread priority v2.0 ====================

$lastMessageTime = 0;
if (!empty($selected_task_uuid)) {
    $stmt = $db->prepare("SELECT MAX(time) as max_time FROM messages WHERE task_uuid = ?");
    $stmt->bind_param("s", $selected_task_uuid);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $lastMessageTime = (int)($result['max_time'] ?? 0);
    $stmt->close();
    log_debug("[INIT] Last message time for task: {$lastMessageTime}");
}

$csrf_token = msgql_csrf_get_token();
log_debug("[INIT] CSRF token generated");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
<title>Сообщения</title>
<script nonce="<?= CSP_NONCE ?>">window.APP_BASE = '<?= $appBase ?>'</script>

<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
<style>
/* ==================== BLOCK START: Global Styles v8.7 (Telegram Colors) ==================== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: #0a0a0a;
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
}

/* ==================== DESKTOP STYLES v9.2 (FULL FIX) ==================== */
@media (min-width: 769px) {
    /* Кнопка-гамбургер скрыта на десктопе */
    .mobile-drawer-toggle,
    .mobile-drawer-overlay,
    .mobile-drawer-panel {
        display: none !important;
    }
    
    /* Правильная интеграция top-bar в flex-схему */
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
    }
    
    body {
        display: flex;
        flex-direction: column;
    }
    
    .top-bar {
        flex-shrink: 0;
        position: relative;
        z-index: 100;
    }
    
    .wrap {
        flex: 1;
        min-height: 0;
        max-width: 98%;
        margin: 0 auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-sizing: border-box;
        width: 100%;
    }
    
    .messenger-container {
        display: flex;
        background: #1e1e1e;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        flex: 1;
        min-height: 0;
        width: 100%;
    }
    
    .messenger-sidebar {
        width: 25%;
        min-width: 280px;
        max-width: 400px;
        background: #1a1a1a;
        border-right: 1px solid #2c2c2c;
        overflow-y: auto;
        flex-shrink: 0;
        height: 100%;
    }
    
    .messenger-chat {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 0;
        height: 100%;
    }
    
    .messages-area {
        flex: 1;
        overflow-y: auto;
        min-height: 0;
    }
    
    .message-input-area {
        flex-shrink: 0;
        background: #1e1e1e;
        border-top: 1px solid #2c2c2c;
        padding: 16px 20px;
        margin-top: auto;
        position: static;
        bottom: auto;
    }
}

/* ==================== MOBILE STYLES ==================== */
@media (max-width: 768px) {
    .top-bar {
        display: none !important;
    }
    .messenger-sidebar {
        display: none !important;
    }
    
    .mobile-drawer-toggle {
        display: flex !important;
        position: fixed !important;
        top: 12px !important;
        left: 12px !important;
        width: 44px !important;
        height: 44px !important;
        background: rgba(30, 30, 30, 0.95) !important;
        backdrop-filter: blur(8px) !important;
        color: white !important;
        border: none !important;
        border-radius: 50% !important;
        font-size: 22px !important;
        z-index: 2001 !important;
        cursor: pointer !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .mobile-drawer-overlay {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        background: rgba(0, 0, 0, 0.6) !important;
        z-index: 1999 !important;
        opacity: 0 !important;
        pointer-events: none !important;
        transition: opacity 0.25s ease !important;
    }
    
    body.mobile-drawer-open .mobile-drawer-overlay {
        opacity: 1 !important;
        pointer-events: auto !important;
    }
    
    .mobile-drawer-panel {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 85% !important;
        max-width: 320px !important;
        height: 100dvh !important;
        background: #1a1a1a !important;
        z-index: 2000 !important;
        transform: translateX(-100%) !important;
        transition: transform 0.25s ease !important;
        display: flex !important;
        flex-direction: column !important;
        overflow-y: auto !important;
        box-shadow: 2px 0 12px rgba(0, 0, 0, 0.5) !important;
    }
    
    body.mobile-drawer-open .mobile-drawer-panel {
        transform: translateX(0) !important;
    }
    
    .mobile-drawer-header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 16px !important;
        padding-top: max(16px, env(safe-area-inset-top, 16px)) !important;
        border-bottom: 1px solid #2c2c2c !important;
        background: #1a1a1a !important;
        flex-shrink: 0 !important;
    }
    
    .mobile-drawer-logo {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        font-size: 18px !important;
        font-weight: 700 !important;
        color: #ffffff !important;
    }
    
    .mobile-drawer-close {
        background: #2c2c2c !important;
        border: none !important;
        color: white !important;
        font-size: 20px !important;
        width: 36px !important;
        height: 36px !important;
        border-radius: 50% !important;
        cursor: pointer !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .mobile-drawer-nav {
        display: flex !important;
        flex-direction: column !important;
        padding: 12px 16px !important;
        border-bottom: 1px solid #2c2c2c !important;
        flex-shrink: 0 !important;
    }
    
    .mobile-drawer-nav a {
        padding: 12px 0 !important;
        color: #aaaaaa !important;
        text-decoration: none !important;
        font-size: 15px !important;
        border-bottom: 1px solid #2c2c2c !important;
        display: block !important;
        width: 100% !important;
    }
    
    .mobile-drawer-nav a:last-child {
        border-bottom: none !important;
    }
    
    .mobile-drawer-nav a.active {
        color: #70a0ff !important;
    }
    
    .mobile-drawer-sidebar {
        flex: 1 !important;
        min-height: 0 !important;
        overflow-y: auto !important;
        padding: 8px 0 !important;
    }
    
    .mobile-drawer-sidebar-title {
        padding: 12px 16px !important;
        font-size: 12px !important;
        color: #6b6b6b !important;
        text-transform: uppercase !important;
        letter-spacing: 1px !important;
        border-bottom: 1px solid #2c2c2c !important;
        margin-bottom: 8px !important;
    }
    
    .mobile-drawer-sidebar .project-item {
        background: transparent !important;
        border-bottom: 1px solid #2c2c2c !important;
        margin-bottom: 0 !important;
    }
    
    .mobile-drawer-sidebar .project-header {
        background: #242424 !important;
        color: #ffffff !important;
        padding: 12px 16px !important;
    }
    
    .mobile-drawer-sidebar .project-title {
        color: #ffffff !important;
    }
    
    .mobile-drawer-sidebar .project-count {
        background: #2c2c2c !important;
        color: #70a0ff !important;
    }
    
    .mobile-drawer-sidebar .tasks-list {
        background: #121212 !important;
    }
    
    .mobile-drawer-sidebar .task-item {
        color: #aaaaaa !important;
        padding: 10px 16px 10px 48px !important;
    }
    
    .mobile-drawer-sidebar .task-item.active {
        background: #2a2a2a !important;
        border-left-color: #70a0ff !important;
        color: #70a0ff !important;
    }
    
    .mobile-drawer-footer {
        padding: 16px !important;
        border-top: 1px solid #2c2c2c !important;
        flex-shrink: 0 !important;
    }
    
    .logout-btn-mobile {
        width: 100% !important;
        background: #2c2c2c !important;
        border: 1px solid #404040 !important;
        color: #f87171 !important;
        padding: 12px !important;
        border-radius: 12px !important;
        font-size: 14px !important;
        cursor: pointer !important;
    }
    
    .wrap {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        height: 100dvh !important;
        overflow: hidden !important;
    }
    
    .messenger-container {
        height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
    }
    
    .messenger-chat {
        flex: 1 !important;
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        background: #121212 !important;
        overflow: hidden !important;
    }
    
    .chat-header {
        position: sticky !important;
        top: 0 !important;
        z-index: 10 !important;
        background: #121212 !important;
        border-bottom: 1px solid #2c2c2c !important;
        flex-shrink: 0 !important;
        padding-top: 60px !important;
    }
    
    .messages-area {
        flex: 1 !important;
        overflow-y: auto !important;
        padding: 16px !important;
        display: block !important;
        padding-bottom: 200px !important;
    }
    
    .message-input-area {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        background: #1e1e1e !important;
        border-top: 1px solid #2c2c2c !important;
        padding: 10px 12px !important;
        padding-bottom: env(safe-area-inset-bottom, 10px) !important;
        z-index: 100 !important;
    }
    
    .send-btn .btn-text {
        display: none !important;
    }
    .send-btn::before {
        content: "➤" !important;
        font-size: 18px !important;
    }
    .send-btn {
        width: 44px !important;
        height: 44px !important;
        min-width: auto !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 40px !important;
    }
    
    .message {
        max-width: 90% !important;
    }
}

/* Общие стили для всех размеров экрана */
.project-item {
    border-bottom: 1px solid #2c2c2c;
    margin-bottom: 4px;
    background-color: #1a1a1a;
    transition: all 0.2s ease;
}

.project-header {
    padding: 14px 16px;
    background: #242424;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 600;
    color: #ffffff;
    transition: background 0.2s;
    user-select: none;
    border-left: 4px solid #70a0ff;
}

.project-header:hover {
    background: #2c2c2c;
}

.project-icon {
    width: 20px;
    height: 20px;
    margin-right: 12px;
    color: #70a0ff;
    flex-shrink: 0;
}

.project-title {
    flex: 1;
    font-size: 14px;
    word-break: break-word;
    color: #ffffff;
}

.project-count {
    background: #2c2c2c;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: #70a0ff;
    flex-shrink: 0;
}

.tasks-list {
    display: none;
    background: #121212;
}

.tasks-list.active {
    display: block;
}

.task-item {
    padding: 10px 16px 10px 48px;
    cursor: pointer;
    border-left: 3px solid transparent;
    font-size: 13px;
    color: #aaaaaa;
    word-break: break-word;
    transition: background 0.2s;
}

.task-item:hover {
    background: #242424;
}

.task-item.active {
    background: #2a2a2a;
    border-left-color: #70a0ff;
    color: #70a0ff;
}

.task-title {
    font-weight: 500;
    margin-bottom: 4px;
}

.task-assignee {
    font-size: 11px;
    color: #6b6b6b;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.chat-header {
    padding: 16px 20px;
    border-bottom: 1px solid #2c2c2c;
    background: #121212;
    flex-shrink: 0;
}

.chat-header h3 {
    margin: 0 0 4px;
    font-size: 18px;
    font-weight: 600;
    word-break: break-word;
    color: #ffffff;
}

#chat-title-wrapper {
    margin: 0 0 4px 0;
}

#chat-title {
    color: #ffffff;
    text-decoration: none;
    border-bottom: 1px dashed #70a0ff;
    transition: all 0.2s ease;
}

#chat-title:hover {
    color: #70a0ff;
    border-bottom-color: #70a0ff;
}

.chat-header .task-info {
    font-size: 13px;
    color: #888888;
}

.messages-area {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 20px;
    background: #121212;
    display: block;
}

.message {
    display: flex;
    gap: 12px;
    max-width: 80%;
    min-width: 0;
    word-wrap: break-word;
    overflow-wrap: break-word;
    margin-bottom: 16px;
}

.message:last-child {
    margin-bottom: 0;
}

.message.own {
    align-self: flex-end;
    flex-direction: row-reverse;
    margin-left: auto;
    margin-right: 0;
}

.message.own .message-content {
    align-items: flex-end;
    margin-right: 0;
}

.message.own .message-header {
    flex-direction: row-reverse;
}

.message.own .message-text {
    background: #2c2c2c;
    border-color: #404040;
    color: #ffffff;
}

.message.own .message-author {
    color: #70a0ff;
}

.message.own .message-time {
    color: #888888 !important;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #70a0ff, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-weight: 600;
    font-size: 14px;
    flex-shrink: 0;
}

.message.own .message-avatar {
    background: linear-gradient(135deg, #70a0ff, #8b5cf6);
}

.message-content {
    flex: 1;
    min-width: 0;
}

.message-header {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 6px;
    flex-wrap: wrap;
}

.message-author {
    font-weight: 600;
    font-size: 14px;
    color: #e0e0e0;
}

.message-time {
    font-size: 11px;
    color: #888888 !important;
}

.message-text {
    background: #1e1e1e;
    padding: 10px 14px;
    border-radius: 12px;
    border: 1px solid #2c2c2c;
    font-size: 14px;
    line-height: 1.5;
    color: #e0e0e0;
    word-wrap: break-word;
    user-select: text !important;
    -webkit-user-select: text !important;
}

.message-files {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}

.message.unread .message-text {
    border-left: 3px solid #70a0ff;
    background: #1e1e2a;
}

.message.unread .message-author {
    color: #70a0ff;
    font-weight: 700;
}

.file-preview-thumb {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: transform 0.2s;
    background: #000;
    display: inline-block;
    max-width: 100%;
    width: auto;
}

.file-preview-thumb:hover {
    transform: scale(1.02);
    z-index: 10;
}

.file-preview-thumb img {
    display: block;
    width: 100%;
    height: auto;
    max-height: 200px;
    object-fit: cover;
    object-position: center;
}

.file-item,
a.file-item {
    background: #2c2c2c !important;
    border-radius: 8px !important;
    padding: 6px 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #e0e0e0 !important;
    text-decoration: none !important;
    cursor: pointer !important;
    word-break: break-word !important;
    max-width: 100% !important;
    transition: all 0.2s !important;
    border: 1px solid #404040 !important;
}

.file-item:hover {
    background: #3a3a3a !important;
    transform: scale(1.02);
}

.message-input-area {
    padding: 16px 20px;
    border-top: 1px solid #2c2c2c;
    background: #1e1e1e;
    flex-shrink: 0;
}

.input-wrapper {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.file-attach-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 22px;
    padding: 8px;
    color: #888888;
    flex-shrink: 0;
    transition: color 0.2s;
}

.file-attach-btn:hover {
    color: #70a0ff;
}

.message-input {
    flex: 1;
    border: 1px solid #2c2c2c;
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 14px;
    resize: none;
    font-family: inherit;
    max-height: 100px;
    background: #121212;
    color: #e0e0e0;
}

.message-input:focus {
    outline: none;
    border-color: #70a0ff;
}

.send-btn {
    background: #70a0ff;
    border: none;
    padding: 8px 18px;
    border-radius: 20px;
    color: #ffffff;
    font-weight: 500;
    cursor: pointer;
    flex-shrink: 0;
    font-size: 14px;
    transition: background 0.2s;
    position: relative;
    min-width: 100px;
}

.send-btn:hover {
    background: #5a8be0;
}

.send-btn:disabled {
    background: #3a3a3a;
    cursor: not-allowed;
    opacity: 0.7;
}

.send-btn .spinner {
    display: none;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
}

.send-btn.loading .btn-text {
    display: none;
}

.send-btn.loading .spinner {
    display: inline-block;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.perm-warning {
    background: #2a2a1a;
    border: 1px solid #b0a030;
    border-radius: 8px;
    padding: 10px 14px;
    margin: 8px 0 0;
    font-size: 13px;
    color: #d0b040;
    display: none;
}

.perm-warning.show {
    display: block;
    animation: fadeIn 0.2s ease;
}

.perm-warning.error {
    background: #2a1a1a;
    border-color: #f07070;
    color: #f0a0a0;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.selected-files {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.selected-file {
    background: #242424;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    word-break: break-word;
    color: #e0e0e0;
}

.remove-file {
    cursor: pointer;
    color: #888888;
    font-weight: bold;
    flex-shrink: 0;
    transition: color 0.2s;
}

.remove-file:hover {
    color: #f07070;
}

.empty-state,
.loading-messages {
    text-align: center;
    padding: 40px 20px;
    color: #888888;
}

.message-highlight {
    background: #2a2a2a !important;
    border-left: 3px solid #f0a030 !important;
    animation: highlight-pulse 1s ease 3;
}

@keyframes highlight-pulse {
    0%, 100% { background: #2a2a2a; }
    50% { background: #3a3a2a; }
}

#reply-indicator {
    transition: all 0.2s ease;
}

#cancel-reply-btn:hover {
    background: rgba(240, 80, 80, 0.15);
}

.message-menu-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
}

.message-menu-overlay.show {
    display: flex;
}

.message-menu {
    background: #1e1e1e;
    border-radius: 16px;
    padding: 12px 0;
    min-width: 220px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
    border: 1px solid #2c2c2c;
    backdrop-filter: blur(10px);
}

.message-menu-item {
    padding: 12px 20px;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 15px;
    color: #e0e0e0;
}

.message-menu-item:hover {
    background: #2c2c2c;
}

.message-menu-item.danger {
    color: #f87171;
}

.message-menu-item.danger:hover {
    background: rgba(248, 113, 113, 0.15);
}

.message-menu-divider {
    height: 1px;
    background: #2c2c2c;
    margin: 8px 0;
}

@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

.mobile-drawer-user {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 16px !important;
    background: #2c2c2c !important;
    border-bottom: 1px solid #3a3a3a !important;
    margin: 0 !important;
}

.mobile-drawer-user-avatar {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #70a0ff, #8b5cf6) !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 20px !important;
}

.mobile-drawer-user-info {
    flex: 1 !important;
}

.mobile-drawer-user-name {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: #ffffff !important;
}

.mobile-drawer-user-role {
    font-size: 11px !important;
    color: #888888 !important;
}

/* ==================== BLOCK START: Mobile drawer badge styles v8.24 ==================== */
.mobile-drawer-toggle {
    position: relative !important;
}

.mobile-drawer-badge {
    position: absolute !important;
    top: -5px !important;
    right: -5px !important;
    background: #f07070 !important;
    color: white !important;
    font-size: 10px !important;
    font-weight: bold !important;
    min-width: 18px !important;
    height: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
    border-radius: 50% !important;
    padding: 0 4px !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.3) !important;
    z-index: 2002 !important;
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif !important;
    box-sizing: border-box !important;
}

@media (max-width: 480px) {
    .mobile-drawer-badge {
        top: -3px !important;
        right: -3px !important;
        min-width: 16px !important;
        height: 16px !important;
        line-height: 16px !important;
        font-size: 9px !important;
    }
}
/* ==================== BLOCK END: Mobile drawer badge styles v8.24 ==================== */

/* ==================== BLOCK START: Smart link styles v2.24 (Telegram colors) ==================== */
.smart-link-message,
.smart-link-task,
.smart-link-file {
    display: inline-block;
    background: #2c2c2c;
    padding: 2px 8px;
    border-radius: 16px;
    font-size: 11px;
    text-decoration: none;
    transition: all 0.2s;
    margin: 0 2px;
    font-weight: 500;
    cursor: pointer;
}

.message:not(.own) .smart-link-message {
    background: #2a2a3a;
    color: #70a0ff;
    border-left: 2px solid #70a0ff;
}

.message:not(.own) .smart-link-task {
    background: #2a3a2a;
    color: #70d070;
    border-left: 2px solid #70d070;
}

.message:not(.own) .smart-link-file {
    background: #2a2a3a;
    color: #b070f0;
    border-left: 2px solid #b070f0;
}

.message.own .smart-link-message {
    background: #3a3a3a;
    color: #d0d0d0;
    border-left: 2px solid #f0b050;
}

.message.own .smart-link-task {
    background: #3a3a3a;
    color: #d0d0d0;
    border-left: 2px solid #90f090;
}

.message.own .smart-link-file {
    background: #3a3a3a;
    color: #d0d0d0;
    border-left: 2px solid #f0b050;
}

.smart-link-message:hover,
.smart-link-task:hover,
.smart-link-file:hover {
    transform: translateY(-1px);
    text-decoration: none;
}

.message.own .smart-link-message:hover,
.message.own .smart-link-task:hover,
.message.own .smart-link-file:hover {
    background: #4a4a4a;
}

.message:not(.own) .smart-link-message:hover {
    background: #3a3a4a;
}

.message:not(.own) .smart-link-task:hover {
    background: #3a4a3a;
}

.message:not(.own) .smart-link-file:hover {
    background: #3a3a4a;
}

.external-link {
    display: inline-block;
    background: #2a2a1a;
    padding: 2px 8px;
    border-radius: 16px;
    font-size: 11px;
    text-decoration: none;
    transition: all 0.2s;
    margin: 0 2px;
    border-left: 2px solid #d0a030;
    color: #d0a030;
    word-break: break-all;
}

.message.own .external-link {
    background: #3a3a2a;
    color: #f0b050;
    border-left-color: #f0b050;
}

.external-link:hover {
    background: #3a3a2a;
    text-decoration: none;
    transform: translateY(-1px);
}
/* ==================== BLOCK END: Smart link styles v2.24 ==================== */

/* ==================== BLOCK START: Quote styles (Telegram colors) ==================== */
.message-quote {
    font-size: 12px;
    color: #aaaaaa;
    background: #1a1a1a;
    border-left: 3px solid #70a0ff;
    padding: 6px 10px;
    margin: 6px 0;
    border-radius: 0 6px 6px 0;
}
.message.own .message-quote {
    background: #2a2a2a;
    border-left-color: #f0b050;
    color: #bbbbbb;
}
.message-quote strong {
    font-size: 11px;
    font-weight: 600;
    color: #c0c0c0;
}
.message.own .message-quote strong {
    color: #f0b050;
}
.quote-time {
    font-size: 9px;
    font-weight: normal;
    color: #888888;
}
.message.own .quote-time {
    color: #888888;
}
.quote-link {
    display: inline-block;
    background: #2c2c3c;
    padding: 0px 6px;
    border-radius: 12px;
    font-size: 10px;
    color: #70a0ff;
    text-decoration: none;
}
.message.own .quote-link {
    background: #3a3a3a;
    color: #f0b050;
}
.message-quote.clickable-quote {
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    z-index: 10;
}
.message-quote.clickable-quote:hover {
    background: #242424;
    transform: translateX(2px);
}
.message.own .message-quote.clickable-quote:hover {
    background: #343434;
}
.message-quote.clickable-quote * {
    pointer-events: none;
}
.message-quote.clickable-quote .quote-link {
    pointer-events: auto;
}
/* ==================== BLOCK END: Quote styles ==================== */

/* ==================== BLOCK START: File styles v2.24 ==================== */
.file-preview-thumb {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: transform 0.2s;
    background: #000;
    display: inline-block;
    max-width: 100%;
    width: auto;
}

.file-preview-thumb:hover {
    transform: scale(1.02);
    z-index: 10;
}

.file-preview-thumb img {
    display: block;
    width: 100%;
    height: auto;
    max-height: 200px;
    object-fit: cover;
    object-position: center;
}

.file-preview-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
    color: #ffffff;
    font-size: 24px;
}

.file-preview-thumb:hover .file-preview-overlay {
    opacity: 1;
}

.file-item,
a.file-item {
    background: #2c2c2c !important;
    border-radius: 8px !important;
    padding: 6px 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #e0e0e0 !important;
    text-decoration: none !important;
    cursor: pointer !important;
    word-break: break-word !important;
    max-width: 100% !important;
    transition: all 0.2s !important;
    border: 1px solid #404040 !important;
}

.file-item:hover {
    background: #3a3a3a !important;
    transform: scale(1.02);
}

.file-link-btn:hover {
    color: #70a0ff !important;
}

.message.own .file-link-btn {
    color: #aaaaaa !important;
}

.message.own .file-link-btn:hover {
    color: #f0b050 !important;
}

.message.own .file-item {
    background: #3a3a3a !important;
    color: #e0e0e0 !important;
    border: 1px solid #555555 !important;
}

.message.own .file-item:hover {
    background: #4a4a4a !important;
}
/* ==================== BLOCK END: File styles v2.24 ==================== */


/* ==================== BLOCK START: Mobile drawer button fix v8.41 ==================== */
/* ver.8.41 (2026-06-06) - ФИКСАЦИЯ КНОПКИ-ГАМБУРГЕРА НА МОБИЛЬНЫХ
 * - Кнопка всегда видна и не уползает вверх
 * - Правильное позиционирование относительно viewport
 */

/* Принудительная фиксация кнопки-гамбургера */
.mobile-drawer-toggle {
    position: fixed !important;
    top: env(safe-area-inset-top, 12px) !important;
    left: 12px !important;
    width: 44px !important;
    height: 44px !important;
    background: rgba(30, 30, 30, 0.95) !important;
    backdrop-filter: blur(8px) !important;
    color: white !important;
    border: none !important;
    border-radius: 50% !important;
    font-size: 22px !important;
    z-index: 2001 !important;
    cursor: pointer !important;
    align-items: center !important;
    justify-content: center !important;
    display: flex !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3) !important;
    transition: none !important; /* Убираем анимации, которые могут дёргать кнопку */
}

/* Запрещаем любым родительским элементам влиять на кнопку */
.mobile-drawer-toggle,
.mobile-drawer-toggle * {
    transform: none !important;
    translate: none !important;
}

/* Убеждаемся, что кнопка не исчезает при скролле */
body.mobile-drawer-open .mobile-drawer-toggle {
    z-index: 2001 !important;
}

/* Для очень маленьких экранов */
@media (max-width: 480px) {
    .mobile-drawer-toggle {
        top: env(safe-area-inset-top, 8px) !important;
        left: 8px !important;
        width: 40px !important;
        height: 40px !important;
        font-size: 20px !important;
    }
}

/* Убираем возможные отступы на html/body, которые могут смещать fixed элементы */
@media (max-width: 768px) {
    html, body {
        position: relative !important;
        overflow-x: hidden !important;
    }
    
    /* Кнопка должна быть поверх всего */
    .mobile-drawer-toggle {
        position: fixed !important;
        top: env(safe-area-inset-top, 12px) !important;
        left: 12px !important;
        right: auto !important;
        bottom: auto !important;
        margin: 0 !important;
    }
}

/* Дополнительная защита: фиксируем кнопку в самом верху DOM */
body > .mobile-drawer-toggle {
    position: fixed !important;
}
/* ==================== BLOCK END: Mobile drawer button fix v8.41 ==================== */

/* ==================== BLOCK START: Edit Message Modal Styles v8.32 ==================== */
.edit-message-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 30000;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.edit-message-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    pointer-events: auto;
}

.edit-message-modal-container {
    position: relative;
    width: 90%;
    max-width: 550px;
    background: #1e293b;
    border-radius: 20px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(79, 124, 255, 0.3);
    pointer-events: auto;
    animation: editModalSlideIn 0.2s ease;
    overflow: hidden;
}

@keyframes editModalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-10px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.edit-message-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-bottom: 1px solid rgba(79, 124, 255, 0.3);
}

.edit-message-modal-header h3 {
    margin: 0;
    font-size: 18px;
    color: #e9eefc;
    display: flex;
    align-items: center;
    gap: 8px;
}

.edit-message-modal-close {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #9ca3af;
    font-size: 20px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.edit-message-modal-close:hover {
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
}

.edit-message-modal-body {
    padding: 20px;
}

.edit-message-reply-info {
    background: rgba(79, 124, 255, 0.1);
    border-left: 3px solid #4f7cff;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 16px;
}

.edit-message-reply-badge {
    font-size: 12px;
    color: #4f7cff;
    margin-bottom: 8px;
    font-weight: 500;
}

.edit-message-reply-quote {
    font-size: 13px;
    color: #cbd5e1;
    background: rgba(0, 0, 0, 0.3);
    padding: 8px 12px;
    border-radius: 8px;
    word-break: break-word;
    max-height: 100px;
    overflow-y: auto;
}

.edit-message-textarea {
    width: 100%;
    padding: 14px;
    border-radius: 12px;
    border: 1px solid #334155;
    background: #0f172a;
    color: #e9eefc;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    transition: border-color 0.2s;
}

.edit-message-textarea:focus {
    outline: none;
    border-color: #4f7cff;
    box-shadow: 0 0 0 2px rgba(79, 124, 255, 0.2);
}

.edit-message-warning {
    margin-top: 10px;
    padding: 8px 12px;
    background: rgba(239, 68, 68, 0.15);
    border-radius: 8px;
    color: #f87171;
    font-size: 13px;
}

.edit-message-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    background: #0f172a;
}

.edit-message-btn-cancel {
    background: rgba(255, 255, 255, 0.08);
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    color: #e9eefc;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.edit-message-btn-cancel:hover {
    background: rgba(0, 0, 0, 0.05);
}

.edit-message-btn-save {
    background: #4f7cff;
    border: none;
    padding: 10px 24px;
    border-radius: 10px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
}

.edit-message-btn-save:hover {
    background: #3b66e0;
    transform: translateY(-1px);
}

.edit-message-btn-save:disabled,
.edit-message-btn-cancel:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* На мобильных устройствах */
@media (max-width: 768px) {
    .edit-message-modal-container {
        width: 95%;
        max-width: none;
        margin: 16px;
    }
    
    .edit-message-modal-body {
        padding: 16px;
    }
    
    .edit-message-textarea {
        font-size: 16px;
    }
}
/* ==================== BLOCK END: Edit Message Modal Styles v8.32 ==================== */


/* ==================== BLOCK START: Task details edit form styles v1.0 ==================== */
/* ver.1.0 (2026-06-14) - Стили для формы редактирования задачи в панели деталей */

.task-details-textarea,
.task-details-input {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    background: #0b1020;
    color: #e9eefc;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
}

.task-details-textarea:focus,
.task-details-input:focus {
    outline: none;
    border-color: #4f7cff;
}

#task-details-file-manager {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

#task-details-file-manager label {
    display: block;
    margin-bottom: 12px;
    font-size: 13px;
    font-weight: 600;
    color: #9bb7ff;
}

#task-details-files-list {
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 12px;
}

#task-details-file-manager .upload-area {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

#task-details-file-manager .upload-area input[type="file"] {
    flex: 1;
    background: #0b1020;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    padding: 8px 12px;
    color: #e9eefc;
    font-size: 13px;
}

#task-details-file-manager .upload-area button {
    white-space: nowrap;
}

/* Стили для кнопок в панели деталей */
.task-details-btn-primary,
.task-details-btn-secondary {
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: none;
}

.task-details-btn-primary {
    background: #4f7cff;
    color: white;
}

.task-details-btn-primary:hover {
    background: #3b6ef5;
    transform: translateY(-1px);
}

.task-details-btn-secondary {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #e9eefc;
}

.task-details-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.15);
}

#task-details-view-actions,
#task-details-edit-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    flex-wrap: wrap;
}

/* ==================== BLOCK END: Task details edit form styles v1.0 ==================== */

</style>
</head>
<body>
<?php if (isset($_SESSION['flash_message'])): ?>
<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    showToast('<?= addslashes($_SESSION['flash_message']) ?>', '<?= $_SESSION['flash_type'] ?? 'warning' ?>');
});
</script>
<?php unset($_SESSION['flash_message']); unset($_SESSION['flash_type']); ?>
<?php endif; ?>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Global variables and CSRF functions v8.23 ====================
window.APP_BASE = '<?= $appBase ?>';
logDebug('[INIT] APP_BASE set to: ' + window.APP_BASE);
window.csrfToken = '<?= $csrf_token ?>';
window.currentUserUuid = '<?= $current_user_uuid ?>';
window.currentUserIsAdmin = <?= $is_admin ? 'true' : 'false' ?>;
window.currentTaskUuid = '<?= $selected_task_uuid ?>';
window.lastMessageTime = <?= (int)$lastMessageTime ?>;
window.MESSAGES_PER_PAGE = <?= (int)$per_page ?>;

// ==================== BLOCK START: Global task details variables v1.0 ====================
window.taskDetailsPanelOpen = false;
window.currentTaskDetailsUuid = null;
window.currentTaskDetailsCleanTitle = null;  // ЧИСТЫЙ заголовок (без [число]) для сохранения
window.projectUsersList = null;
window.projectUsersListProjectUuid = null;
// ==================== BLOCK END: Global task details variables v1.0 ====================

logDebug('[INIT] currentUserIsAdmin:', window.currentUserIsAdmin);

function addCsrfToFormData(formData) {
    if (formData instanceof FormData) formData.append('csrf_token', window.csrfToken);
}
function addCsrfToUrlParams(params) {
    if (params instanceof URLSearchParams) params.append('csrf_token', window.csrfToken);
}
function addCsrfToObject(obj) {
    obj.csrf_token = window.csrfToken;
    return obj;
}

var sseOriginalTitle = document.title;
var soundEnabled = true;
var lastSoundTime = 0;
var lastToastTime = 0;
var notificationPermission = false;

var taskUsersCache = [];
var taskUsersLoaded = false;
var mentionTimeout = null;
var currentMentionQuery = '';

var selectedFiles = [];
var isSending = false;

window.userIsScrolling = false;
window._suppressScrollOnEdit = false;
var userScrollTimeout = null;
var pendingScrollTimeouts = [];

var pendingReadMessages = [];
var readMarkTimeout = null;

function showToast(message, type) {
    var toast = document.createElement('div');
    toast.textContent = message;
    var bgColor = type === 'error' ? '#dc2626' : (type === 'warning' ? '#f59e0b' : '#10b981');
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + bgColor + '; color:white; padding:10px 20px; border-radius:8px; z-index:10000; font-size:14px; animation:slideInRight 0.3s ease;';
    document.body.appendChild(toast);
    setTimeout(function() { toast.style.animation = 'slideOutRight 0.3s ease'; setTimeout(function() { toast.remove(); }, 300); }, 3000);
}

function updateGlobalBadge(count) {
    logDebug('[BADGE] Updating badge to:', count);
    if (count > 0) {
        var match = document.title.match(/^\((\d+)\)\s/);
        if (match) {
            document.title = document.title.replace(/^\(\d+\)\s/, '(' + count + ') ');
        } else {
            document.title = '(' + count + ') ' + sseOriginalTitle;
        }
    } else {
        document.title = sseOriginalTitle;
    }
    updateUnreadBadge();
}

// ==================== BLOCK START: showAlert function v1.0 ====================
// ver.1.0 (2026-06-14) - Функция для отображения алертов/уведомлений
// Используется в saveTaskDetails, uploadTaskFileFromDetails и других функциях
// - Поддерживает типы: success, error, warning, info
// - Использует существующую функцию showToast если доступна
// - Fallback: alert для ошибок

function showAlert(message, type) {
    logDebug('[ALERT] showAlert called:', message, 'type:', type);
    
    // Приоритет 1: использовать существующую showToast
    if (typeof showToast === 'function') {
        showToast(message, type);
        return;
    }
    
    // Приоритет 2: для ошибок используем стандартный alert
    if (type === 'error') {
        alert('❌ ' + message);
        return;
    }
    
    // Приоритет 3: создаём временное уведомление
    var toast = document.createElement('div');
    toast.textContent = message;
    
    var bgColor = '#10b981';
    if (type === 'error') bgColor = '#dc2626';
    else if (type === 'warning') bgColor = '#f59e0b';
    else if (type === 'info') bgColor = '#3b82f6';
    
    toast.style.cssText = 'position:fixed; bottom:20px; right:20px; background:' + bgColor + '; color:white; padding:10px 20px; border-radius:8px; z-index:10000; font-size:14px; animation:slideInRight 0.3s ease;';
    document.body.appendChild(toast);
    
    setTimeout(function() {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(function() { if (toast && toast.parentNode) toast.remove(); }, 300);
    }, 3000);
}
// ==================== BLOCK END: showAlert function v1.0 ====================

function updateUnreadBadge() {
    var unreadMessages = document.querySelectorAll('.message.unread:not(.own)').length;
    updateGlobalBadge(unreadMessages);
    return unreadMessages;
}
// ==================== BLOCK END: Global variables and CSRF functions v8.23 ====================




// ==================== BLOCK START: Desktop scroll diagnostics v8.54 (DISABLED ON TASK PAGE) ====================
// ver.8.54 (2026-06-10) - ОТКЛЮЧАЕМ ДИАГНОСТИКУ НА СТРАНИЦАХ С PARAMETER task
// - Диагностика вызывала "подпрыгивание" страницы на десктопе
// - Оставляем только для страниц без параметра task

// Запускаем диагностику ТОЛЬКО для страниц БЕЗ параметра task
// (чтобы не вызывать подпрыгивание)
var urlParamsForDiagnostics = new URLSearchParams(window.location.search);
var hasTaskParam = urlParamsForDiagnostics.has('task');

if (!hasTaskParam && window.innerWidth > 768) {
    setTimeout(function() {
        if (typeof desktopScrollDiagnostics !== 'undefined' && desktopScrollDiagnostics.init) {
            desktopScrollDiagnostics.init();
            logDebug('[SCROLL_DIAG] Started for desktop page WITHOUT task parameter');
        }
    }, 100);
} else {
    logDebug('[SCROLL_DIAG] Skipped diagnostics (hasTaskParam=' + hasTaskParam + ')');
}
// ==================== BLOCK END: Desktop scroll diagnostics v8.54 ====================


// ==================== BLOCK START: Sequential request queue v7.14 ====================
window._requestQueue = [];
window._isRequestActive = false;

function processRequestQueue() {
    if (window._isRequestActive) return;
    if (window._requestQueue.length === 0) return;
    
    var next = window._requestQueue.shift();
    window._isRequestActive = true;
    logDebug('[QUEUE] Processing next request, remaining: ' + window._requestQueue.length);
    
    next(function() {
        window._isRequestActive = false;
        setTimeout(processRequestQueue, 300);
    });
}

function enqueueRequest(name, fn) {
    window._requestQueue.push(function(done) {
        logDebug('[QUEUE] Starting request: ' + name);
        var startTime = Date.now();
        fn(function() {
            var elapsed = Date.now() - startTime;
            logDebug('[QUEUE] Completed request: ' + name + ' in ' + elapsed + 'ms');
            done();
        });
    });
    processRequestQueue();
}

function cancelAllPendingScrolls() {
    logDebug('[SCROLL] cancelAllPendingScrolls called');
    if (pendingScrollTimeouts) {
        for (var i = 0; i < pendingScrollTimeouts.length; i++) {
            clearTimeout(pendingScrollTimeouts[i]);
        }
        pendingScrollTimeouts = [];
    }
    if (userScrollTimeout) {
        clearTimeout(userScrollTimeout);
        userScrollTimeout = null;
    }
}

function playNotificationSound() {
    if (!soundEnabled) return;
    var now = Date.now();
    if (now - lastSoundTime < soundIntervalSec * 1000) return;
    lastSoundTime = now;
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var oscillator = ctx.createOscillator();
        var gainNode = ctx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        oscillator.frequency.value = 600;
        gainNode.gain.value = 0.1;
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.4);
        oscillator.stop(ctx.currentTime + 0.4);
    } catch(e) {}
}

function requestNotificationPermission() {
    if (Notification && Notification.permission === 'default') {
        Notification.requestPermission().then(function(perm) {
            notificationPermission = perm === 'granted';
        });
    } else if (Notification && Notification.permission === 'granted') {
        notificationPermission = true;
    }
}

function showDesktopNotification(title, body, link, taskUuid) {
    if (!notificationPermission) return;
    var now = Date.now();
    if (now - lastToastTime < soundIntervalSec * 1000) return;
    lastToastTime = now;
    try {
        var notification = new Notification(title, { body: body, icon: window.APP_BASE + '/favicon.ico', silent: true });
        notification.onclick = function() {
            window.focus();
            if (taskUuid) {
                var taskElement = document.querySelector('.task-item[data-task-uuid="' + taskUuid + '"]');
                
                // Если чат с этой задачей уже открыт — просто прокручиваем к последним сообщениям
                if (taskElement && window.currentTaskUuid === taskUuid) {
                    var container = document.getElementById('messages-area');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                        logDebug('[NOTIFICATION] Already on task, scrolled to bottom');
                    }
                    notification.close();
                    return;
                }
                
                // Если это другая задача — очищаем кэш и переключаемся
                if (taskElement && typeof window.selectTask === 'function') {
                    logDebug('[NOTIFICATION] Switching to different task: ' + taskUuid);
                    
                    // Очищаем кэш изображений для старой задачи
                    if (window.imageCache) {
                        window.imageCache.clear();
                        logDebug('[NOTIFICATION] Image cache cleared');
                    }
                    
                    // Очищаем кэш пагинации
                    if (window.pagination && window.pagination.pageCache) {
                        window.pagination.pageCache = {};
                        logDebug('[NOTIFICATION] Pagination cache cleared');
                    }
                    
                    // Переключаем задачу (selectTask сам загрузит новые данные)
                    window.selectTask(taskElement);
                    
                    // Дополнительная прокрутка после загрузки
                    setTimeout(function() {
                        var container = document.getElementById('messages-area');
                        if (container && !window.userIsScrolling) {
                            container.scrollTop = container.scrollHeight;
                        }
                    }, 500);
                    
                    notification.close();
                    return;
                }
                
                // Фоллбек — редирект
                if (link) {
                    window.location.href = link;
                }
            } else if (link) {
                window.location.href = link;
            }
            notification.close();
        };
        setTimeout(function() { notification.close(); }, 5000);
    } catch(e) {}
}

setTimeout(requestNotificationPermission, 5000);
// ==================== BLOCK END: Global variables and CSRF functions v7.14 ====================
</script>

<!-- Далее идут те же JS функции, что были в вашем файле (parseMessageText, renderMessage, pagination, и т.д.) -->
<!-- Для краткости я их не дублирую, но в итоговом файле они должны быть полностью -->

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: parseMessageText v8.25 (restored quotes and links) ====================
// ver.8.25 - Добавлено преобразование \n в <br>
function parseMessageText(text) {
    if (!text) return '';
    
    // Сначала преобразуем \n в <br> (для правильного отображения переносов строк)
    var withBreaks = text.replace(/\n/g, '<br>');
    
    var currentHost = window.location.host;
    var appBase = window.APP_BASE || '';
    var escapedHost = currentHost.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var escapedAppBase = appBase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var UUID_REGEX = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
    var protocol = window.location.protocol;
    var baseUrl = protocol + '//' + currentHost + appBase;
    
    function safeLink(protocol, uuid, type, displayText) {
        if (!UUID_REGEX.test(uuid)) return '[неверный идентификатор]';
        var encodedUuid = encodeURIComponent(uuid);
        switch(type) {
            case 'msg':
                return '<a href="' + baseUrl + '/messages.php?message=' + encodedUuid + '" class="smart-link-message" data-msg-uuid="' + encodedUuid + '">' + displayText + '</a>';
            case 'task':
                return '<a href="' + baseUrl + '/projects.php?task=' + encodedUuid + '" class="smart-link-task" target="_blank" rel="noopener noreferrer">' + displayText + '</a>';
            case 'file':
                return '<a href="' + baseUrl + '/file_preview.php?uuid=' + encodedUuid + '" class="smart-link-file" target="_blank" rel="noopener noreferrer">' + displayText + '</a>';
            default:
                return displayText;
        }
    }
    
    function makeSafeExternalLink(url) {
        var lowerUrl = url.toLowerCase();
        var safeProtocols = ['http://', 'https://', 'tg://', 'telegram://', 'mailto:', 'tel:', 'ftp://', 'ws://', 'wss://', 'magnet:', 'skype:', 'viber:', 'whatsapp:', 'signal:'];
        var isSafe = false;
        for (var i = 0; i < safeProtocols.length; i++) {
            if (lowerUrl.startsWith(safeProtocols[i])) {
                isSafe = true;
                break;
            }
        }
        if (isSafe) {
            var cleanUrl = url.replace(/javascript:/gi, '').replace(/data:/gi, '').replace(/vbscript:/gi, '');
            var encodedUrl = encodeURI(cleanUrl);
            var linkClass = (lowerUrl.startsWith('tg://') || lowerUrl.startsWith('telegram://')) ? 'external-link telegram-link' : 'external-link';
            var targetAttr = (lowerUrl.startsWith('mailto:') || lowerUrl.startsWith('tel:')) ? '' : ' target="_blank" rel="noopener noreferrer"';
            return '<a href="' + encodedUrl + '" class="' + linkClass + '"' + targetAttr + '>' + escapeHtml(url) + '</a>';
        }
        return escapeHtml(url);
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    // Экранируем HTML, но с сохранением <br>
    var escapedText = escapeHtml(withBreaks);
    
    // Восстанавливаем <br> обратно (он был заэкранирован как &lt;br&gt;)
    escapedText = escapedText.replace(/&lt;br&gt;/g, '<br>');
    
    // Маркеры [msg:uuid], [task:uuid], [file:uuid]
    escapedText = escapedText.replace(/\[msg:([a-f0-9\-]{36})\]/gi, function(match, uuid) {
        return safeLink('https', uuid, 'msg', '💬 сообщение');
    });
    escapedText = escapedText.replace(/\[task:([a-f0-9\-]{36})\]/gi, function(match, uuid) {
        return safeLink('https', uuid, 'task', '📋 задача');
    });
    escapedText = escapedText.replace(/\[file:([a-f0-9\-]{36})\]/gi, function(match, uuid) {
        return safeLink('https', uuid, 'file', '📎 файл');
    });
    
    // ========== ПРОЕКТЫ ==========
    // с http/https и APP_BASE
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + escapedAppBase + '/projects\\.php\\?(?:task|uuid|id)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'task', '📋 задача');
        }
    );
    // относительные с APP_BASE
    escapedText = escapedText.replace(
        new RegExp(escapedAppBase + '/projects\\.php\\?(?:task|uuid|id)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'task', '📋 задача');
        }
    );
    // без APP_BASE (если appBase пустой или ссылка без него)
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + '/projects\\.php\\?(?:task|uuid|id)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'task', '📋 задача');
        }
    );
    
    // ========== СООБЩЕНИЯ ==========
    // с http/https и APP_BASE
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + escapedAppBase + '/messages\\.php\\?(?:message|msg)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'msg', '💬 сообщение');
        }
    );
    // относительные с APP_BASE
    escapedText = escapedText.replace(
        new RegExp(escapedAppBase + '/messages\\.php\\?(?:message|msg)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'msg', '💬 сообщение');
        }
    );
    // без APP_BASE
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + '/messages\\.php\\?(?:message|msg)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'msg', '💬 сообщение');
        }
    );
    
    // ========== ФАЙЛЫ (file_preview.php и download.php) ==========
    // с http/https и APP_BASE
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + escapedAppBase + '/(?:file_preview|download)\\.php\\?(?:uuid|file)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'file', '📎 файл');
        }
    );
    // относительные с APP_BASE
    escapedText = escapedText.replace(
        new RegExp(escapedAppBase + '/(?:file_preview|download)\\.php\\?(?:uuid|file)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'file', '📎 файл');
        }
    );
    // без APP_BASE (вариант 1: /file_preview.php)
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + '/file_preview\\.php\\?(?:uuid|file)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'file', '📎 файл');
        }
    );
    // без APP_BASE (вариант 2: без слеша после домена - https://site.comfile_preview.php)
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + 'file_preview\\.php\\?(?:uuid|file)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'file', '📎 файл');
        }
    );
    // без APP_BASE (download.php)
    escapedText = escapedText.replace(
        new RegExp('https?://' + escapedHost + '/download\\.php\\?(?:uuid|file)=([a-f0-9\\-]{36})(?:[&\\s]|$)', 'gi'),
        function(match, uuid) {
            return safeLink('https', uuid, 'file', '📎 файл');
        }
    );
    
    // ========== ВНЕШНИЕ ССЫЛКИ ==========
    var urlRegex = /(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[^\s<>\[\]\(\)\{\}]+/gi;
    escapedText = escapedText.replace(urlRegex, function(match) {
        if (match.indexOf('<a') !== -1) return match;
        if (match.indexOf(currentHost) !== -1 && (match.indexOf('http://') === 0 || match.indexOf('https://') === 0)) return match;
        return makeSafeExternalLink(match);
    });
    
    // ========== ФОРМАТИРОВАНИЕ ЦИТАТ ==========
    // Разбиваем по <br> для обработки цитат
    var lines = escapedText.split('<br>');
    var quoteLines = [];
    var resultLines = [];
    var inQuote = false;
    var currentQuoteUuid = null;
    
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        // Обработка цитат: &gt; (экранированный >) или просто >
        if (line.match(/^&gt;\s/) || line.match(/^>\s/) || line.match(/^»\s/)) {
            var content = '';
            if (line.match(/^&gt;\s/)) content = line.substring(5);
            else if (line.match(/^>\s/)) content = line.substring(2);
            else if (line.match(/^»\s/)) content = line.substring(2);
            
            content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            var uuidMatch = content.match(/\[msg:([a-f0-9\-]{36})\]/);
            if (uuidMatch && !currentQuoteUuid) currentQuoteUuid = uuidMatch[1];
            content = content.replace(/\[msg:[a-f0-9\-]{36}\]/g, '');
            content = content.replace(/\s+/g, ' ').trim();
            quoteLines.push(content);
            inQuote = true;
        } else {
            if (inQuote && quoteLines.length > 0) {
                var quoteHtml = '<div class="message-quote';
                if (currentQuoteUuid && UUID_REGEX.test(currentQuoteUuid)) quoteHtml += ' clickable-quote" data-quote-uuid="' + encodeURIComponent(currentQuoteUuid) + '"';
                else quoteHtml += '"';
                quoteHtml += '><span style="font-size:10px; color:#6b7280; display:block; margin-bottom:4px;">📎 Цитата:</span>' + quoteLines.join('<br>') + '</div>';
                resultLines.push(quoteHtml);
                quoteLines = [];
                inQuote = false;
                currentQuoteUuid = null;
            }
            resultLines.push(line);
        }
    }
    if (quoteLines.length > 0) {
        var quoteHtml = '<div class="message-quote';
        if (currentQuoteUuid && UUID_REGEX.test(currentQuoteUuid)) quoteHtml += ' clickable-quote" data-quote-uuid="' + encodeURIComponent(currentQuoteUuid) + '"';
        else quoteHtml += '"';
        quoteHtml += '><span style="font-size:10px; color:#6b7280; display:block; margin-bottom:4px;">📎 Цитата:</span>' + quoteLines.join('<br>') + '</div>';
        resultLines.push(quoteHtml);
    }
    
    // Соединяем обратно с <br>
    return resultLines.join('<br>');
}
// ==================== BLOCK END: parseMessageText v8.36 (with line breaks) ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: renderMessage and helpers v8.34 (pure text for copy) ====================
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(ts){
    var d = new Date(parseInt(ts));
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var tz = -d.getTimezoneOffset() / 60;
    var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
    var tStr = d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'});
    
    if (msgDate.getTime() === today.getTime()) {
        return tStr + ' (' + tzName + ')';
    } else if (msgDate.getTime() === today.getTime() - 86400000) {
        return 'Вчера ' + tStr + ' (' + tzName + ')';
    } else {
        return d.toLocaleDateString('ru-RU') + ' ' + tStr + ' (' + tzName + ')';
    }
}

function formatFileSize(b){return b>=1e9?(b/1e9).toFixed(2)+' GB':b>=1e6?(b/1e6).toFixed(2)+' MB':b>=1024?(b/1024).toFixed(1)+' KB':b+' B'}

function getFileIcon(n){var e=n.split('.').pop().toLowerCase();return{jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',pdf:'📄',doc:'📝',docx:'📝',xls:'📊',xlsx:'📊',zip:'📦',rar:'📦','7z':'📦',mp3:'🎵',mp4:'🎬',avi:'🎬',txt:'📃'}[e]||'📎'}

function renderMessage(msg) {
    var own = msg.user_uuid === window.currentUserUuid;
    var init = msg.user_name ? msg.user_name.charAt(0).toUpperCase() : 'U';
    var unread = (msg.is_read === 0 && !own) ? 'unread' : '';
    
    var processedText = parseMessageText(msg.text || '');
    
    // ЧИСТЫЙ ТЕКСТ для копирования (из базы, без обработки, без цитат)
    var pureText = msg.text || '';
    // НЕ ЭКРАНИРУЕМ для атрибутов - используем encodeURIComponent для безопасного хранения
    var pureTextForAttr = encodeURIComponent(pureText);
    
    var replyHtml = '';
    if (msg.reply_to && msg.reply_to.uuid) {
        var replyAuthor = escapeHtml(msg.reply_to.user_name || 'Пользователь');
        var replyTextShort = escapeHtml((msg.reply_to.text || '').substring(0, 200));
        replyHtml = '<div class="message-quote clickable-quote" data-quote-uuid="' + msg.reply_to.uuid + '">';
        replyHtml += '<strong>' + replyAuthor + '</strong>: ';
        replyHtml += replyTextShort;
        if ((msg.reply_to.text || '').length > 200) replyHtml += '…';
        replyHtml += '</div>';
    }
    
    var fHtml = '';
    if (msg.files && msg.files.length) {
        fHtml = '<div class="message-files">';
        for (var fi = 0; fi < msg.files.length; fi++) {
            var f = msg.files[fi];
            var safeName = (f.name || 'Файл').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
            var safeMime = (f.mime || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
            // Для data-message-text используем encodeURIComponent
            var msgTextForAttr = encodeURIComponent(pureText);
            
            if (f.mime && f.mime.startsWith('image/')) {
                var imageUrl = window.APP_BASE + '/download.php?file=' + f.uuid + '&preview=1';
                fHtml += '<div class="file-image-wrapper" data-msg-uuid="' + msg.uuid + '" data-file-uuid="' + f.uuid + '" data-is-own="' + (own ? 'true' : 'false') + '" data-file-name="' + safeName + '" data-file-size="' + (f.size_bytes || 0) + '" data-file-mime="' + safeMime + '" data-message-text="' + msgTextForAttr + '" style="display: inline-flex; flex-direction: column; align-items: center; gap: 4px;">';
                fHtml += '<div class="file-preview-thumb" style="position:relative; border-radius:8px; overflow:hidden; box-shadow:0 2px 5px rgba(0,0,0,0.1); cursor:pointer; background:#e5e7eb; display:inline-block; max-width:100%; width:auto;" onclick="event.stopPropagation(); showFilePreview(\''+f.uuid+'\',\''+safeName+'\','+(f.size_bytes||0)+',\''+safeMime+'\');" title="Нажмите для просмотра">';
                fHtml += '<img class="lazy-preview" data-src="' + imageUrl + '" src="" alt="' + escapeHtml(f.name) + '" style="display:block; width:100%; height:auto; max-height:200px; object-fit:cover;">';
                fHtml += '<div class="file-preview-overlay" style="position:absolute; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.3); display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.2s; color:white; font-size:24px;">🔍</div>';
                fHtml += '</div>';
                fHtml += '</div>';
            } else {
                fHtml += '<div style="display: inline-flex; align-items: center; gap: 4px;">';
                fHtml += '<a href="#" class="file-item" data-file-uuid="' + f.uuid + '" data-file-name="' + safeName + '" data-file-size="' + (f.size_bytes || 0) + '" data-file-mime="' + safeMime + '" onclick="event.stopPropagation(); showFilePreview(\''+f.uuid+'\',\''+safeName+'\','+(f.size_bytes||0)+',\''+safeMime+'\');return false;">'+getFileIcon(f.name)+' '+escapeHtml(f.name)+' ('+f.size+')</a>';
                fHtml += '</div>';
            }
        }
        fHtml += '</div>';
    }
    
    // Для data-text и data-original-text тоже используем encodeURIComponent
    var dataTextForAttr = encodeURIComponent(pureText);
    var replyAttr = (msg.reply_to && msg.reply_to.uuid) ? ' data-reply-uuid="' + msg.reply_to.uuid + '"' : '';
    
    return '<div class="message '+(own?'own':'')+' '+unread+'" id="msg-'+msg.uuid+'" data-uuid="'+msg.uuid+'" data-text="'+dataTextForAttr+'" data-original-text="'+dataTextForAttr+'" data-time="'+msg.time+'"' + replyAttr + ' onclick="showMessageMenu(event, \''+msg.uuid+'\', '+(own ? 'true' : 'false')+', \''+(msg.user_name||msg.user_login||'').replace(/'/g, "\\'")+'\', '+msg.time+')">' +
        '<div class="message-avatar">'+init+'</div>' +
        '<div class="message-content">' +
            '<div class="message-header">' +
                '<span class="message-author">'+escapeHtml(msg.user_name||msg.user_login||'Пользователь')+'</span>' +
                '<span class="message-time">'+formatTime(msg.time)+'</span>' +
            '</div>' +
            '<div class="message-text">' + replyHtml + processedText + '</div>' +
            fHtml +
        '</div>' +
    '</div>';
}
// ==================== BLOCK END: renderMessage and helpers v8.34 ====================



// ==================== BLOCK START: Add spacer to last message v8.51 (INNER CONTAINER) ====================
// ver.8.51: Используем внутренний контейнер для спейсера

function applyStaticMobileSpacer() {
    if (window.innerWidth > 768) return;
    
    // v8.51: Используем внутренний контейнер
    var container = document.getElementById('messages-area-inner');
    if (!container) {
        container = document.getElementById('messages-area');
    }
    if (!container) return;
    
    // Удаляем все существующие динамические спейсеры, если они есть
    var existingSpacers = container.querySelectorAll('.message-last-spacer');
    for (var i = 0; i < existingSpacers.length; i++) {
        existingSpacers[i].remove();
    }
    
    var currentPadding = window.getComputedStyle(container).paddingBottom;
    var targetPadding = '300px';
    
    if (currentPadding !== targetPadding) {
        container.style.paddingBottom = targetPadding;
        logDebug('[SPACER] Applied static padding-bottom: ' + targetPadding);
    }
}

function setupStaticSpacer() {
    applyStaticMobileSpacer();
    window.addEventListener('resize', function() {
        setTimeout(applyStaticMobileSpacer, 100);
    });
    setTimeout(applyStaticMobileSpacer, 500);
    setTimeout(applyStaticMobileSpacer, 1500);
    setTimeout(applyStaticMobileSpacer, 3000);
    logDebug('[SPACER] Static spacer setup complete (dynamic spacer disabled)');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupStaticSpacer);
} else {
    setupStaticSpacer();
}
// ==================== BLOCK END: Add spacer to last message v8.51 ====================



// Настройка статического отступа (без MutationObserver)
function setupStaticSpacer() {
    // Применяем сразу
    applyStaticMobileSpacer();
    
    // При изменении размера окна пересчитываем
    window.addEventListener('resize', function() {
        setTimeout(applyStaticMobileSpacer, 100);
    });
    
    // Также применяем после загрузки сообщений (один раз через 500ms)
    setTimeout(applyStaticMobileSpacer, 500);
    setTimeout(applyStaticMobileSpacer, 1500);
    setTimeout(applyStaticMobileSpacer, 3000);
    
    logDebug('[SPACER] Static spacer setup complete (dynamic spacer disabled)');
}

// Запускаем статический спейсер
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupStaticSpacer);
} else {
    setupStaticSpacer();
}
// ==================== BLOCK END: Add spacer to last message v8.20 ====================
</script>

<!-- ==================== BLOCK START: Pagination and message loading v8.0 ==================== -->
<script nonce="<?= CSP_NONCE ?>">
var AJAX_URL = window.APP_BASE + '/messages.php';

window.pagination = {
    currentPage: 0,
    totalPages: 0,
    messagesPerPage: window.MESSAGES_PER_PAGE,
    totalMessages: 0,
    isLoading: false,
    hasOlder: false,
    hasNewer: false,
    lastScrollTop: 0,
    lastScrollTime: 0,
    scrollDirection: null,
    consecutiveScrolls: { up: 0, down: 0 },
    retryCount: 0,
    maxRetries: 3,
    prefetchNext: true,
    lastRequestTime: 0,
    lastRequestHash: null,
    initializing: false,
    pageCache: {},
    currentWindowPage: 0,
    windowStartPage: 0,
    windowEndPage: 0
};

window._activeRequest = null;
window._pendingLoadParams = null;

function cancelActiveRequest() {
    if (window._activeRequest) {
        logDebug('[PAGINATION] Cancelling active request');
        try { window._activeRequest.abort(); } catch(e) {}
        window._activeRequest = null;
    }
}

// ==================== BLOCK START: Mobile drawer badge update function v8.24 ====================
// ver.8.24 (2026-05-27) - Функция обновления общего бейджа на кнопке-гамбургере
// Суммирует все непрочитанные уведомления: сообщения + проекты (новые задачи) + файлы + системные

function updateMobileDrawerBadge() {
    var badgeElement = document.getElementById('mobile-drawer-badge');
    if (!badgeElement) {
        return;
    }
    
    logDebug('[MOBILE_BADGE] updateMobileDrawerBadge called');
    
    var csrfToken = window.csrfToken || '';
    var url = window.APP_BASE + '/get_badges_data.php';
    
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
            var messagesCount = data.badges.messages || 0;
            var projectsCount = data.badges.projects || 0;
            var filesCount = data.badges.files || 0;
            var notificationsCount = data.badges.notifications || 0;
            
            var totalCount = messagesCount + projectsCount + filesCount + notificationsCount;
            
            logDebug('[MOBILE_BADGE] Counts - msg:' + messagesCount + ', proj:' + projectsCount + 
                     ', files:' + filesCount + ', notif:' + notificationsCount + ', total:' + totalCount);
            
            if (totalCount > 0) {
                badgeElement.textContent = totalCount > 99 ? '99+' : totalCount;
                badgeElement.style.display = 'inline-block';
            } else {
                badgeElement.style.display = 'none';
            }
        } else {
            logDebug('[MOBILE_BADGE] Failed to get badges data');
        }
    })
    .catch(function(err) {
        logError('[MOBILE_BADGE] Error:', err.message);
    });
}

window.updateMobileDrawerBadge = updateMobileDrawerBadge;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(updateMobileDrawerBadge, 500);
    });
} else {
    setTimeout(updateMobileDrawerBadge, 500);
}

document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('mobile-drawer-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            setTimeout(updateMobileDrawerBadge, 100);
        });
    }
});
// ==================== BLOCK END: Mobile drawer badge update function v8.24 ====================

function loadMessagesPage(page, options) {
    options = options || {};
    var scrollToBottom = options.scrollToBottom || false;
    var preserveScroll = options.preserveScroll || false;
    var callback = options.callback || null;
    var isLastPage = options.isLastPage || false;
    var bufferSize = options.bufferSize || 1;

    logDebug('[PAGINATION_v8] loadMessagesPage called: page=' + page + ', scrollToBottom=' + scrollToBottom + ', isLastPage=' + isLastPage + ', bufferSize=' + bufferSize);

    if (!window.currentTaskUuid) {
        logDebug('[PAGINATION_v8] No task selected');
        if (callback) callback({ success: false, error: 'No task selected' });
        return;
    }

    if (window.pagination.isLoading && window._activeRequest) {
        logDebug('[PAGINATION_v8] Already loading, queueing');
        window._pendingLoadParams = { page: page, scrollToBottom: scrollToBottom, preserveScroll: preserveScroll, callback: callback, isLastPage: isLastPage, bufferSize: bufferSize };
        return;
    }

    var requestHash = window.currentTaskUuid + '_window_' + page + '_buffer_' + bufferSize;
    var now = Date.now();
    if (window.pagination.lastRequestHash === requestHash && (now - window.pagination.lastRequestTime) < 500) {
        logDebug('[PAGINATION_v8] Duplicate request ignored');
        return;
    }
    window.pagination.lastRequestHash = requestHash;
    window.pagination.lastRequestTime = now;

    logDebug('[PAGINATION_v8] Loading window with page ' + page + ' for task ' + window.currentTaskUuid);
    window.pagination.isLoading = true;
    window.pagination.retryCount = 0;

    var container = document.getElementById('messages-area');
    var oldScrollTop = container ? container.scrollTop : 0;

    var formData = new URLSearchParams();
    formData.append('action', 'load_messages_paged');
    formData.append('task_uuid', window.currentTaskUuid);
    formData.append('page', page);
    formData.append('buffer_size', bufferSize);
    addCsrfToUrlParams(formData);

    cancelActiveRequest();

    window._activeRequest = new XMLHttpRequest();
    var xhr = window._activeRequest;
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        window.pagination.isLoading = false;
        window._activeRequest = null;
        
        if (xhr.status === 503 && window.pagination.retryCount < window.pagination.maxRetries) {
            window.pagination.retryCount++;
            var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
            logDebug('[PAGINATION_v8] 503, retry ' + window.pagination.retryCount + ' in ' + delay + 'ms');
            setTimeout(function() { loadMessagesPage(page, options); }, delay);
            if (callback) callback({ success: false, error: '503' });
            processPendingLoad();
            return;
        }
        
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                logDebug('[PAGINATION_v8] Response received, success=' + data.success);
                
                if (data.success) {
                    window.pagination.totalMessages = data.total_count;
                    window.pagination.totalPages = data.total_pages;
                    window.pagination.currentWindowPage = data.current_window_page;
                    window.pagination.windowStartPage = data.window_start_page;
                    window.pagination.windowEndPage = data.window_end_page;
                    
                    if (!window.pagination.pageCache) {
                        window.pagination.pageCache = {};
                    }
                    
                    if (data.pages_data) {
                        for (var p in data.pages_data) {
                            if (data.pages_data.hasOwnProperty(p)) {
                                window.pagination.pageCache[p] = data.pages_data[p];
                                logDebug('[PAGINATION_v8] Cached page ' + p + ' with ' + data.pages_data[p].messages.length + ' messages');
                            }
                        }
                    }
                    
                    var currentPageData = window.pagination.pageCache[page];
                    if (currentPageData) {
                        window.pagination.hasOlder = currentPageData.has_older;
                        window.pagination.hasNewer = currentPageData.has_newer;
                    } else {
                        window.pagination.hasOlder = (page > 0);
                        window.pagination.hasNewer = (page + 1) < data.total_pages;
                    }
                    
                    logDebug('[PAGINATION_v8] Pagination state: currentPage=' + page + ', totalPages=' + data.total_pages + 
                             ', hasOlder=' + window.pagination.hasOlder + ', hasNewer=' + window.pagination.hasNewer +
                             ', windowStart=' + window.pagination.windowStartPage + ', windowEnd=' + window.pagination.windowEndPage);
                    
                    renderWindowPages({
                        scrollToBottom: scrollToBottom,
                        preserveScroll: preserveScroll,
                        oldScrollTop: oldScrollTop,
                        targetPage: page,
                        isLastPage: isLastPage
                    });
                    
                    if (callback) callback(data);
                } else if (callback) {
                    callback({ success: false, error: data.error });
                }
            } catch(e) {
                logDebug('[PAGINATION_v8] JSON parse error: ' + e.message);
                if (callback) callback({ success: false, error: e.message });
            }
        } else if (callback) {
            logDebug('[PAGINATION_v8] HTTP error: ' + xhr.status);
            callback({ success: false, error: 'HTTP ' + xhr.status });
        }
        processPendingLoad();
    };
    
    xhr.onerror = function() {
        window.pagination.isLoading = false;
        window._activeRequest = null;
        logDebug('[PAGINATION_v8] Network error');
        if (window.pagination.retryCount < window.pagination.maxRetries) {
            window.pagination.retryCount++;
            var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
            setTimeout(function() { loadMessagesPage(page, options); }, delay);
        } else if (callback) {
            callback({ success: false, error: 'Network error' });
        }
        processPendingLoad();
    };
    
    xhr.ontimeout = function() {
        window.pagination.isLoading = false;
        window._activeRequest = null;
        logDebug('[PAGINATION_v8] Timeout');
        if (window.pagination.retryCount < window.pagination.maxRetries) {
            window.pagination.retryCount++;
            var delay = Math.min(60000, 3000 * Math.pow(2, window.pagination.retryCount));
            setTimeout(function() { loadMessagesPage(page, options); }, delay);
        } else if (callback) {
            callback({ success: false, error: 'Timeout' });
        }
        processPendingLoad();
    };
    
    xhr.send(formData);
}

// ==================== BLOCK START: renderWindowPages scroll fix v8.54 (DESKTOP PROTECTION) ====================
// ver.8.54 (2026-06-10) - ДОБАВЛЕНА ЗАЩИТА ОТ СКРОЛЛА WINDOW НА ДЕСКТОПЕ
// - На десктопе НЕ блокируем body (это вызывало прыжки)
// - Восстанавливаем скролл только для контейнера

function renderWindowPages(options) {
    var container = document.getElementById('messages-area-inner');
    if (!container) {
        container = document.getElementById('messages-area');
        logDebug('[RENDER_WINDOW_v8.54] Using fallback container: messages-area');
    }
    if (!container) {
        logDebug('[RENDER_WINDOW] Container not found!');
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('error', '⚠️ Ошибка: контейнер не найден', false);
        }
        return;
    }

    var scrollToBottom = options.scrollToBottom || false;
    var preserveScroll = options.preserveScroll || false;
    var oldScrollTop = options.oldScrollTop || 0;
    var targetPage = options.targetPage || 0;
    var isLastPage = options.isLastPage || false;

    logDebug('[RENDER_WINDOW_v8.54] ========== START ==========');
    logDebug('[RENDER_WINDOW_v8.54] scrollToBottom=' + scrollToBottom +
        ', preserveScroll=' + preserveScroll +
        ', oldScrollTop=' + oldScrollTop +
        ', isLastPage=' + isLastPage);
    logDebug('[RENDER_WINDOW_v8.54] BEFORE RENDER - window.scrollY=' + window.scrollY +
        ', container.scrollTop=' + container.scrollTop +
        ', container.scrollHeight=' + container.scrollHeight);

    // v8.54: НА ДЕСКТОПЕ НЕ БЛОКИРУЕМ body (это вызывало прыжки)
    var isDesktop = window.innerWidth > 768;
    var body = document.body;
    var html = document.documentElement;
    var originalBodyOverflow = null;
    var originalHtmlOverflow = null;

    if (!isDesktop) {
        // Только на мобильных блокируем body
        originalBodyOverflow = body.style.overflow;
        originalHtmlOverflow = html.style.overflow;
        body.style.overflow = 'hidden';
        html.style.overflow = 'hidden';
        logDebug('[RENDER_WINDOW_v8.54] Body scroll locked (mobile only)');
    } else {
        logDebug('[RENDER_WINDOW_v8.54] Desktop mode - body scroll NOT locked');
        // На десктопе принудительно сбрасываем скролл окна
        if (window.scrollY !== 0) {
            window.scrollTo(0, 0);
            logDebug('[RENDER_WINDOW_v8.54] Reset window scroll to 0');
        }
    }

    var allMessages = [];
    var pageOrder = [];
    if (window.pagination.pageCache) {
        var pageNumbers = Object.keys(window.pagination.pageCache).map(Number);
        pageNumbers.sort(function(a, b) { return a - b; });
        for (var i = 0; i < pageNumbers.length; i++) {
            var pageNum = pageNumbers[i];
            var pageData = window.pagination.pageCache[pageNum];
            if (pageData && pageData.messages && pageData.messages.length > 0) {
                pageOrder.push(pageNum);
                for (var j = 0; j < pageData.messages.length; j++) {
                    allMessages.push(pageData.messages[j]);
                }
            }
        }
        logDebug('[RENDER_WINDOW_v8.54] Collected ' + allMessages.length + ' messages from pages: ' + pageOrder.join(', '));
    }

    if (allMessages.length === 0) {
        logDebug('[RENDER_WINDOW_v8.54] No messages to render');
        container.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
        if (!isDesktop && originalBodyOverflow !== null) {
            body.style.overflow = originalBodyOverflow;
            html.style.overflow = originalHtmlOverflow;
        }
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('ready', '✓ Нет сообщений', false);
        }
        return;
    }

    var oldScrollHeight = container.scrollHeight;
    var wasAtBottom = false;
    if (container.scrollHeight > 0 && container.scrollTop + container.clientHeight >= container.scrollHeight - 50) {
        wasAtBottom = true;
        logDebug('[RENDER_WINDOW_v8.54] User was at bottom before render');
    }

    var topVisibleElement = null;
    var topVisibleOffset = 0;
    if (preserveScroll && oldScrollTop === 0 && container.children.length > 0) {
        var viewportTop = container.scrollTop;
        for (var i = 0; i < container.children.length; i++) {
            var child = container.children[i];
            var childTop = child.offsetTop;
            if (childTop >= viewportTop) {
                topVisibleElement = child;
                topVisibleOffset = childTop - viewportTop;
                break;
            }
        }
        logDebug('[RENDER_WINDOW_v8.54] Found top visible element for restore');
    }

    container.innerHTML = '';
    for (var i = 0; i < allMessages.length; i++) {
        var msg = allMessages[i];
        container.insertAdjacentHTML('beforeend', renderMessage(msg));
    }
    logDebug('[RENDER_WINDOW_v8.54] Rendered ' + allMessages.length + ' messages');

    void container.offsetHeight;

    if (allMessages.length > 0) {
        var maxTime = allMessages[allMessages.length - 1].time || 0;
        if (maxTime > window.lastMessageTime) {
            window.lastMessageTime = maxTime;
        }
    }

    if (typeof window.setPageLoadStatus === 'function') {
        window.setPageLoadStatus('ready', '✓ Готово', false);
    }

    var newScrollHeight = container.scrollHeight;

    function restoreBodyScroll() {
        if (!isDesktop && originalBodyOverflow !== null) {
            body.style.overflow = originalBodyOverflow;
            html.style.overflow = originalHtmlOverflow;
            logDebug('[RENDER_WINDOW_v8.54] Body scroll restored');
        }
    }

    function safeScrollToBottom() {
        if (!container) return;
        logDebug('[RENDER_WINDOW_v8.54] 📍 BEFORE SCROLL - window.scrollY=' + window.scrollY +
            ', container.scrollTop=' + container.scrollTop);
        container.scrollTop = container.scrollHeight;
        setTimeout(function() {
            logDebug('[RENDER_WINDOW_v8.54] 📍 AFTER SCROLL - window.scrollY=' + window.scrollY +
                ', container.scrollTop=' + container.scrollTop);
            if (!isDesktop && window.scrollY !== 0) {
                logDebug('[RENDER_WINDOW_v8.54] ⚠️ window.scrollY changed on mobile, resetting');
                window.scrollTo(0, 0);
            }
        }, 50);
        restoreBodyScroll();
    }

    function safeRestoreScroll(targetScrollTop) {
        if (!container) return;
        logDebug('[RENDER_WINDOW_v8.54] 📍 BEFORE RESTORE - window.scrollY=' + window.scrollY +
            ', setting container.scrollTop=' + targetScrollTop);
        container.scrollTop = targetScrollTop;
        setTimeout(function() {
            logDebug('[RENDER_WINDOW_v8.54] 📍 AFTER RESTORE - window.scrollY=' + window.scrollY);
            if (!isDesktop && window.scrollY !== 0) {
                window.scrollTo(0, 0);
            }
        }, 50);
        restoreBodyScroll();
    }

    if (scrollToBottom || isLastPage || wasAtBottom) {
        setTimeout(function() {
            safeScrollToBottom();
        }, 100);
    } else if (preserveScroll && oldScrollTop > 0) {
        setTimeout(function() {
            var targetScrollTop = oldScrollTop;
            if (newScrollHeight > oldScrollHeight) {
                targetScrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
            }
            targetScrollTop = Math.min(targetScrollTop, container.scrollHeight - container.clientHeight);
            safeRestoreScroll(targetScrollTop);
        }, 100);
    } else if (preserveScroll && topVisibleElement) {
        setTimeout(function() {
            if (container && topVisibleElement && topVisibleElement.parentNode === container) {
                var newTop = topVisibleElement.offsetTop - topVisibleOffset;
                newTop = Math.max(0, Math.min(newTop, container.scrollHeight - container.clientHeight));
                safeRestoreScroll(newTop);
            } else {
                restoreBodyScroll();
            }
        }, 100);
    } else {
        restoreBodyScroll();
    }

    if (typeof desktopScrollDiagnostics !== 'undefined' && desktopScrollDiagnostics.enabled) {
        logDebug('[RENDER_WINDOW_v8.54] Stopping desktop diagnostics');
        desktopScrollDiagnostics.stop();
    }

    setTimeout(function() {
        loadLazyPreviews();
        markVisibleMessagesAsRead();
    }, 200);
    logDebug('[RENDER_WINDOW_v8.54] ========== END ==========');
}
// ==================== BLOCK END: renderWindowPages scroll fix v8.54 ====================


// ==================== BLOCK START: Container scroll diagnostics v1.0 ====================
// Добавьте этот код в DOMContentLoaded после получения элементов

function diagnoseScrollContainer() {
    var container = document.getElementById('messages-area');
    if (!container) {
        logDebug('[DIAGNOSE] messages-area container not found');
        return;
    }
    
    var computedStyle = window.getComputedStyle(container);
    logDebug('[DIAGNOSE] messages-area computed styles:');
    logDebug('[DIAGNOSE]   overflow-y: ' + computedStyle.overflowY);
    logDebug('[DIAGNOSE]   height: ' + computedStyle.height);
    logDebug('[DIAGNOSE]   max-height: ' + computedStyle.maxHeight);
    logDebug('[DIAGNOSE]   position: ' + computedStyle.position);
    logDebug('[DIAGNOSE]   display: ' + computedStyle.display);
    logDebug('[DIAGNOSE]   flex: ' + computedStyle.flex);
    
    // Проверяем родительские элементы
    var parent = container.parentElement;
    while (parent && parent !== document.body) {
        var parentStyle = window.getComputedStyle(parent);
        logDebug('[DIAGNOSE] Parent ' + parent.tagName + ': overflow=' + parentStyle.overflow + ', height=' + parentStyle.height);
        parent = parent.parentElement;
    }
    
    // Принудительно устанавливаем стили для уверенности
    container.style.overflowY = 'auto';
    container.style.height = 'auto';
    container.style.flex = '1';
    container.style.minHeight = '0';
    
    logDebug('[DIAGNOSE] Container styles enforced');
}

// Вызовите после загрузки
setTimeout(diagnoseScrollContainer, 1000);
setTimeout(diagnoseScrollContainer, 3000);
// ==================== BLOCK END: Container scroll diagnostics v1.0 ====================

function processPendingLoad() {
    if (window._pendingLoadParams && !window.pagination.isLoading) {
        var params = window._pendingLoadParams;
        window._pendingLoadParams = null;
        loadMessagesPage(params.page, {
            scrollToBottom: params.scrollToBottom,
            preserveScroll: params.preserveScroll,
            callback: params.callback,
            isLastPage: params.isLastPage
        });
    }
}

// ==================== BLOCK START: markVisibleMessagesAsRead v8.51 (INNER CONTAINER) ====================
function markVisibleMessagesAsRead() {
    // v8.51: Используем внутренний контейнер
    var container = document.getElementById('messages-area-inner');
    if (!container) {
        container = document.getElementById('messages-area');
    }
    if (!container) return;
    
    var unreadMessages = container.querySelectorAll('.message.unread:not(.own)');
    if (unreadMessages.length === 0) return;
    
    var unreadUuids = [];
    for (var i = 0; i < unreadMessages.length; i++) {
        var uuid = unreadMessages[i].getAttribute('data-uuid');
        if (uuid) unreadUuids.push(uuid);
    }
    
    if (unreadUuids.length > 0) {
        queueMarkMessagesRead(unreadUuids);
    }
}
// ==================== BLOCK END: markVisibleMessagesAsRead v8.51 ====================

function queueMarkMessagesRead(messageUuids) {
    if (!messageUuids || messageUuids.length === 0) return;
    
    for (var i = 0; i < messageUuids.length; i++) {
        if (pendingReadMessages.indexOf(messageUuids[i]) === -1) {
            pendingReadMessages.push(messageUuids[i]);
        }
    }
    
    if (readMarkTimeout) clearTimeout(readMarkTimeout);
    readMarkTimeout = setTimeout(function() {
        if (pendingReadMessages.length === 0) return;
        sendMarkReadRequest(pendingReadMessages.slice());
        pendingReadMessages = [];
    }, 1000);
}

function sendMarkReadRequest(uuidsToMark, retryCount) {
    if (retryCount === undefined) retryCount = 0;
    var maxRetries = 3;
    
    var formData = new URLSearchParams();
    formData.append('action', 'mark_messages_read');
    formData.append('task_uuid', window.currentTaskUuid);
    for (var i = 0; i < uuidsToMark.length; i++) {
        formData.append('message_uuids[]', uuidsToMark[i]);
    }
    addCsrfToUrlParams(formData);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 15000;
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.success) {
                    for (var i = 0; i < uuidsToMark.length; i++) {
                        var msgElement = document.querySelector('.message[data-uuid="' + uuidsToMark[i] + '"]');
                        if (msgElement) msgElement.classList.remove('unread');
                    }
                    updateUnreadBadge();
                }
            } catch(e) {}
        } else if (xhr.status === 503 && retryCount < maxRetries) {
            setTimeout(function() { sendMarkReadRequest(uuidsToMark, retryCount + 1); }, 2000 * Math.pow(2, retryCount));
        }
    };
    xhr.send(formData);
}

// ==================== BLOCK START: onMessagesScroll v8.51 (INNER CONTAINER) ====================
// ver.8.51: Используем внутренний контейнер для скролла

var lastBottomLoadTime = 0;
var BOTTOM_LOAD_COOLDOWN = 500;

function onMessagesScroll() {
    // v8.51: Используем внутренний контейнер
    var container = document.getElementById('messages-area-inner');
    if (!container) {
        container = document.getElementById('messages-area');
    }
    if (!container || window.pagination.isLoading) return;
    
    var scrollTop = container.scrollTop;
    var scrollHeight = container.scrollHeight;
    var clientHeight = container.clientHeight;
    var now = Date.now();
    
    var direction = null;
    if (scrollTop < window.pagination.lastScrollTop - 5) {
        direction = 'up';
    } else if (scrollTop > window.pagination.lastScrollTop + 5) {
        direction = 'down';
    }
    
    window.pagination.lastScrollTop = scrollTop;
    window.pagination.lastScrollTime = now;
    window.pagination.scrollDirection = direction;
    
    var isAtTop = scrollTop <= 50;
    var distanceToBottom = scrollHeight - (scrollTop + clientHeight);
    var isAtBottom = distanceToBottom <= 100;
    
    if (isAtBottom && direction === 'down') {
        logDebug('[SCROLL] Bottom reached: distance=' + Math.round(distanceToBottom) + 'px, direction=down, hasNewer=' + window.pagination.hasNewer);
    }
    
    if (isAtTop && direction === 'up' && window.pagination.hasOlder && !window.pagination.isLoading) {
        window.pagination.consecutiveScrolls.up++;
        if (window.pagination.consecutiveScrolls.up >= 1) {
            logDebug('[SCROLL] Loading older messages (top reached)');
            loadOlderMessages();
            window.pagination.consecutiveScrolls.up = 0;
        }
    } 
    else if (isAtBottom && direction === 'down' && window.pagination.hasNewer && !window.pagination.isLoading) {
        var nowMs = Date.now();
        if (nowMs - lastBottomLoadTime > BOTTOM_LOAD_COOLDOWN) {
            window.pagination.consecutiveScrolls.down++;
            if (window.pagination.consecutiveScrolls.down >= 1) {
                lastBottomLoadTime = nowMs;
                logDebug('[SCROLL] Loading newer messages (bottom reached)');
                loadNewerMessages();
                window.pagination.consecutiveScrolls.down = 0;
            }
        } else {
            logDebug('[SCROLL] Bottom load skipped due to cooldown');
        }
    }
    
    if (direction !== window.pagination.scrollDirection && window.pagination.lastScrollTime && (now - window.pagination.lastScrollTime) > 500) {
        window.pagination.consecutiveScrolls.up = 0;
        window.pagination.consecutiveScrolls.down = 0;
    }
    
    if (!window._readMarkScheduled) {
        window._readMarkScheduled = true;
        setTimeout(function() {
            markVisibleMessagesAsRead();
            window._readMarkScheduled = false;
        }, 500);
    }
}
// ==================== BLOCK END: onMessagesScroll v8.51 ====================


// ==================== BLOCK START: loadOlderMessages v8.51 (INNER CONTAINER) ====================
function loadOlderMessages() {
    logDebug('[PAGINATION_v8] loadOlderMessages called');
    
    if (!window.pagination.hasOlder || window.pagination.isLoading) {
        logDebug('[PAGINATION_v8] Cannot load older: hasOlder=' + window.pagination.hasOlder + ', isLoading=' + window.pagination.isLoading);
        return;
    }
    
    var currentPage = window.pagination.currentWindowPage;
    var prevPage = currentPage - 1;
    
    if (prevPage < 0) {
        logDebug('[PAGINATION_v8] Already at first page, setting hasOlder=false');
        window.pagination.hasOlder = false;
        return;
    }
    
    // v8.51: Используем внутренний контейнер
    var scrollContainer = document.getElementById('messages-area-inner');
    if (!scrollContainer) {
        scrollContainer = document.getElementById('messages-area');
    }
    var scrollPosition = scrollContainer ? scrollContainer.scrollTop : 0;
    
    if (window.pagination.pageCache && window.pagination.pageCache[prevPage]) {
        logDebug('[PAGINATION_v8] Page ' + prevPage + ' already in cache, updating window');
        window.pagination.currentWindowPage = prevPage;
        window.pagination.hasOlder = (prevPage > 0);
        window.pagination.hasNewer = true;
        
        renderWindowPages({
            preserveScroll: true,
            targetPage: prevPage,
            scrollToBottom: false,
            oldScrollTop: scrollPosition
        });
    } else {
        logDebug('[PAGINATION_v8] Loading older page ' + prevPage + ' with buffer');
        loadMessagesPage(prevPage, { 
            preserveScroll: true,
            oldScrollTop: scrollPosition,
            bufferSize: 1
        });
    }
}
// ==================== BLOCK END: loadOlderMessages v8.51 ====================

// ==================== BLOCK START: loadNewerMessages v8.51 (INNER CONTAINER) ====================
function loadNewerMessages() {
    logDebug('[PAGINATION_v8] loadNewerMessages called');
    
    if (!window.pagination.hasNewer) {
        logDebug('[PAGINATION_v8] Cannot load newer: already at last page');
        return;
    }
    
    if (window.pagination.isLoading) {
        logDebug('[PAGINATION_v8] Cannot load newer: already loading');
        return;
    }
    
    var currentPage = window.pagination.currentWindowPage;
    var nextPage = currentPage + 1;
    
    if (nextPage >= window.pagination.totalPages) {
        logDebug('[PAGINATION_v8] Already at last page, setting hasNewer=false');
        window.pagination.hasNewer = false;
        return;
    }
    
    if (window.pagination.pageCache && window.pagination.pageCache[nextPage]) {
        logDebug('[PAGINATION_v8] Page ' + nextPage + ' already in cache, updating window');
        window.pagination.currentWindowPage = nextPage;
        window.pagination.hasOlder = true;
        window.pagination.hasNewer = (nextPage + 1) < window.pagination.totalPages;
        
        renderWindowPages({
            preserveScroll: false,
            targetPage: nextPage,
            scrollToBottom: true
        });
    } else {
        logDebug('[PAGINATION_v8] Loading newer page ' + nextPage + ' with buffer');
        loadMessagesPage(nextPage, { 
            preserveScroll: false,
            scrollToBottom: true,
            bufferSize: 1
        });
    }
}
// ==================== BLOCK END: loadNewerMessages v8.51 ====================


// ==================== BLOCK START: Sidebar refresh functions v8.37 ====================
// ver.8.36 (2026-06-02) - ДИНАМИЧЕСКОЕ ОБНОВЛЕНИЕ САЙДБАРА
// - Получение задач проекта через AJAX с правильной сортировкой
// - Перерисовка списка задач в сайдбаре без перезагрузки страницы
// - Сохранение активной задачи
// - Обновление счётчика сообщений
// ver.8.37 (2026-06-02) - ИСПРАВЛЕНА СОРТИРОВКА ЗАДАЧ ПРИ ОБНОВЛЕНИИ
// - Задачи обновляются в правильном порядке (сначала с последними сообщениями)
// - Активная задача остаётся активной после обновления
// - Добавлено логирование для отладки

function refreshSidebarForProject(projectUuid, callback) {
    logDebug('[SIDEBAR_REFRESH_v8.37] Refreshing sidebar for project:', projectUuid);
    
    if (!projectUuid) {
        logDebug('[SIDEBAR_REFRESH_v8.37] No project UUID provided');
        if (callback) callback(false);
        return;
    }
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_project_tasks');
    formData.append('project_uuid', projectUuid);
    formData.append('ajax_mode', '1');
    if (typeof addCsrfToUrlParams === 'function') {
        addCsrfToUrlParams(formData);
    }
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success && data.tasks) {
                    logDebug('[SIDEBAR_REFRESH_v8.37] Received ' + data.tasks.length + ' tasks');
                    updateSidebarTasks(projectUuid, data.tasks);
                    if (callback) callback(true);
                } else {
                    logDebug('[SIDEBAR_REFRESH_v8.37] No tasks in response or error:', data.error);
                    if (callback) callback(false);
                }
            } catch(e) {
                logDebug('[SIDEBAR_REFRESH_v8.37] JSON parse error:', e);
                if (callback) callback(false);
            }
        } else {
            logDebug('[SIDEBAR_REFRESH_v8.37] HTTP error:', xhr.status);
            if (callback) callback(false);
        }
    };
    
    xhr.onerror = function() {
        logDebug('[SIDEBAR_REFRESH_v8.37] Network error');
        if (callback) callback(false);
    };
    
    xhr.send(formData);
}

function updateSidebarTasks(projectUuid, tasks) {
    logDebug('[SIDEBAR_REFRESH_v8.37] updateSidebarTasks called for project:', projectUuid, 'tasks count:', tasks.length);
    
    var projectElement = document.querySelector('.project-item[data-project-uuid="' + projectUuid + '"]');
    if (!projectElement) {
        logDebug('[SIDEBAR_REFRESH_v8.37] Project element not found:', projectUuid);
        return;
    }
    
    var tasksList = projectElement.querySelector('.tasks-list');
    if (!tasksList) {
        logDebug('[SIDEBAR_REFRESH_v8.37] Tasks list not found for project:', projectUuid);
        return;
    }
    
    // ========== ИСПРАВЛЕНИЕ: Удаляем ВСЕ существующие счётчики перед обновлением ==========
    var existingSpans = tasksList.querySelectorAll('.task-title span');
    existingSpans.forEach(function(span) {
        span.remove();
    });
    // ====================================================================================
    
    // Сохраняем активную задачу
    var activeTaskUuid = window.currentTaskUuid;
    logDebug('[SIDEBAR_REFRESH_v8.37] Active task UUID:', activeTaskUuid);
    
    // Перестраиваем список задач
    var newHtml = '';
    for (var i = 0; i < tasks.length; i++) {
        var task = tasks[i];
        var activeClass = (task.uuid === activeTaskUuid) ? 'active' : '';
        var countHtml = (task.messages_count > 0) ? '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + task.messages_count + ']</span>' : '';
        var assigneeHtml = task.assignee_name ? '<div class="task-assignee">👤 ' + escapeHtml(task.assignee_name) + '</div>' : '';
        
        newHtml += '<div class="task-item ' + activeClass + '" data-task-uuid="' + task.uuid + '">';
        newHtml += '<div class="task-title">' + escapeHtml(task.title) + ' ' + countHtml + '</div>';
        newHtml += assigneeHtml;
        newHtml += '</div>';
    }
    
    tasksList.innerHTML = newHtml;
    
    // Переназначаем обработчики кликов
    var newTaskItems = tasksList.querySelectorAll('.task-item');
    logDebug('[SIDEBAR_REFRESH_v8.37] Reattaching click handlers to ' + newTaskItems.length + ' task items');
    
    newTaskItems.forEach(function(taskItem) {
        taskItem.addEventListener('click', function(e) {
            e.stopPropagation();
            if (typeof selectTask === 'function') {
                selectTask(this);
            }
        });
    });
    
    // Обновляем счётчик задач в заголовке проекта
    var projectCount = projectElement.querySelector('.project-count');
    if (projectCount) {
        projectCount.textContent = tasks.length;
    }
    
    // Обновляем мобильный сайдбар, если он существует
    updateMobileSidebarTasks(projectUuid, tasks);
    
    logDebug('[SIDEBAR_REFRESH_v8.37] Sidebar updated for project:', projectUuid, 'tasks:', tasks.length);
}


function updateMobileSidebarTasks(projectUuid, tasks) {
    var mobileProjectElement = document.querySelector('#mobile-drawer-panel .project-item[data-project-uuid="' + projectUuid + '"]');
    if (!mobileProjectElement) {
        logDebug('[SIDEBAR_REFRESH_v8.37] Mobile project element not found:', projectUuid);
        return;
    }
    
    var mobileTasksList = mobileProjectElement.querySelector('.tasks-list');
    if (!mobileTasksList) {
        logDebug('[SIDEBAR_REFRESH_v8.37] Mobile tasks list not found');
        return;
    }
    
    // ========== ИСПРАВЛЕНИЕ: Удаляем ВСЕ существующие счётчики перед обновлением ==========
    var existingSpans = mobileTasksList.querySelectorAll('.task-title span');
    existingSpans.forEach(function(span) {
        span.remove();
    });
    // ====================================================================================
    
    var activeTaskUuid = window.currentTaskUuid;
    
    var newHtml = '';
    for (var i = 0; i < tasks.length; i++) {
        var task = tasks[i];
        var activeClass = (task.uuid === activeTaskUuid) ? 'active' : '';
        var countHtml = (task.messages_count > 0) ? '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + task.messages_count + ']</span>' : '';
        var assigneeHtml = task.assignee_name ? '<div class="task-assignee">👤 ' + escapeHtml(task.assignee_name) + '</div>' : '';
        
        newHtml += '<div class="task-item ' + activeClass + '" data-task-uuid="' + task.uuid + '">';
        newHtml += '<div class="task-title">' + escapeHtml(task.title) + ' ' + countHtml + '</div>';
        newHtml += assigneeHtml;
        newHtml += '</div>';
    }
    
    mobileTasksList.innerHTML = newHtml;
    
    mobileTasksList.querySelectorAll('.task-item').forEach(function(taskItem) {
        taskItem.addEventListener('click', function(e) {
            e.stopPropagation();
            if (window.innerWidth <= 768) {
                closeMobileDrawer();
            }
            if (typeof selectTask === 'function') {
                selectTask(this);
            }
        });
    });
    
    logDebug('[SIDEBAR_REFRESH_v8.37] Mobile sidebar updated for project:', projectUuid);
}

function getCurrentProjectUuid() {
    var activeTask = document.querySelector('.task-item.active');
    if (activeTask) {
        var projectItem = activeTask.closest('.project-item');
        if (projectItem) {
            return projectItem.getAttribute('data-project-uuid');
        }
    }
    return null;
}

function closeMobileDrawer() {
    var body = document.body;
    body.classList.remove('mobile-drawer-open');
    body.style.overflow = '';
}
// ==================== BLOCK END: Sidebar refresh functions v8.37 ====================
</script>


<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: selectTask v8.37 ====================
// ver.8.36: Добавлено обновление сайдбара при переключении задачи
// ver.8.32: Добавлено закрытие модального окна редактирования при переключении задачи
// ver.8.0: Базовая реализация с пагинацией
// ver.8.37 (2026-06-02): Улучшено обновление сайдбара - задачи сортируются правильно
// - После переключения задачи сайдбар обновляется с сохранением порядка
// - Активная задача остаётся активной
// - Добавлено логирование

function selectTask(el) {
    // Закрываем модальное окно редактирования при переключении задачи
    var editModal = document.getElementById('editMessageModal');
    if (editModal && editModal.style.display === 'flex') {
        editModal.style.display = 'none';
        if (window._editModalKeydownHandler) {
            document.removeEventListener('keydown', window._editModalKeydownHandler);
            window._editModalKeydownHandler = null;
        }
        window._editMessageContext = null;
        logDebug('[SELECT_TASK_v8.37] Closed edit modal due to task switch');
    }
    
    var newTaskUuid = el.dataset.taskUuid;
    logDebug('[SELECT_TASK_v8.37] Called with task UUID:', newTaskUuid);
    logDebug('[SELECT_TASK_v8.37] Current task UUID:', window.currentTaskUuid);
    
    var previousTaskUuid = window.currentTaskUuid;
    
    if (newTaskUuid && newTaskUuid === previousTaskUuid) {
        logDebug('[SELECT_TASK_v8.37] Same task clicked, checking scroll position');
        
        var container = document.getElementById('messages-area');
        if (container) {
            var scrollTop = container.scrollTop;
            var scrollHeight = container.scrollHeight;
            var clientHeight = container.clientHeight;
            var distanceToBottom = scrollHeight - (scrollTop + clientHeight);
            
            logDebug('[SELECT_TASK_v8.37] Distance to bottom: ' + distanceToBottom + 'px');
            
            if (distanceToBottom > 100) {
                logDebug('[SELECT_TASK_v8.37] Not at bottom, scrolling to bottom');
                
                var scrollDelays = [50, 150, 300, 600, 1000];
                for (var d = 0; d < scrollDelays.length; d++) {
                    (function(delay) {
                        setTimeout(function() {
                            if (container && !window.userIsScrolling) {
                                container.scrollTop = container.scrollHeight;
                                logDebug('[SELECT_TASK_v8.37] Scroll attempt ' + delay + 'ms: scrollTop=' + container.scrollTop + ', scrollHeight=' + container.scrollHeight);
                            }
                        }, delay);
                    })(scrollDelays[d]);
                }
                
                showToast('📜 Прокрутка к последним сообщениям', 'info');
            } else {
                logDebug('[SELECT_TASK_v8.37] Already at bottom, no scroll needed');
            }
        } else {
            logDebug('[SELECT_TASK_v8.37] messages-area container not found');
        }
        return;
    }
    
    if (!newTaskUuid || newTaskUuid === window.currentTaskUuid) return;
    
    logDebug('[SELECT_TASK_v8.37] Switching to task: ' + newTaskUuid);
    
    cancelActiveRequest();
    
    window.pagination = {
        currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE, totalMessages: 0,
        isLoading: false, hasOlder: false, hasNewer: false,
        lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
        consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
        prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: true,
        pageCache: {},
        currentWindowPage: 0,
        windowStartPage: 0,
        windowEndPage: 0
    };
    window._pendingLoadParams = null;
    
    window.currentTaskUuid = newTaskUuid;
    document.getElementById('current-task-uuid').value = newTaskUuid;
    
    document.querySelectorAll('.task-item').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    
    var taskTitle = el.querySelector('.task-title').textContent;
    var chatTitle = document.getElementById('chat-title');
    if (chatTitle) {
        chatTitle.textContent = taskTitle;
        chatTitle.href = window.APP_BASE + '/projects.php?task=' + newTaskUuid;
    }
    
    var taskFilesLink = document.getElementById('task-files-link');
    if (taskFilesLink) {
        taskFilesLink.href = window.APP_BASE + '/files.php?task=' + newTaskUuid;
        taskFilesLink.style.display = 'inline-flex';
    }
    
    document.getElementById('chat-subtitle').textContent = 'Обсуждение задачи';
    document.getElementById('input-area').style.display = 'block';
    
    var container = document.getElementById('messages-area');
    if (container) container.innerHTML = '<div class="loading-messages">⏳ Загрузка сообщений...</div>';
    
    window.lastMessageTime = 0;
    
    enqueueRequest('initTask_' + newTaskUuid, function(done) {
        initTaskSequential(newTaskUuid, done);
    });
    
    enqueueRequest('loadTaskUsers_' + newTaskUuid, function(done) {
        loadTaskUsersSequential(newTaskUuid, function() { done(); });
    });
    
    enqueueRequest('loadMessages_' + newTaskUuid, function(done) {
        loadTaskLastPageSequential(newTaskUuid, done);
    });
    
    scrollSidebarToActiveTask();
    
    // ==================== ОБНОВЛЯЕМ САЙДБАР ДЛЯ ТЕКУЩЕГО ПРОЕКТА ====================
    var projectItem = el.closest('.project-item');
    if (projectItem) {
        var projectUuid = projectItem.getAttribute('data-project-uuid');
        if (projectUuid) {
            logDebug('[SELECT_TASK_v8.37] Refreshing sidebar for project:', projectUuid);
            setTimeout(function() {
                refreshSidebarForProject(projectUuid, function(success) {
                    if (success) {
                        logDebug('[SELECT_TASK_v8.37] Sidebar refresh completed');
                    } else {
                        logDebug('[SELECT_TASK_v8.37] Sidebar refresh failed');
                    }
                });
            }, 500);
        }
    }
}
// ==================== BLOCK END: selectTask v8.37 ====================



// ==================== BLOCK START: scrollSidebarToActiveTask v2.4 (SCROLL ONLY SIDEBAR) ====================
// ver.2.4 (2026-06-10) - ИСПРАВЛЕНИЕ: ПРОКРУЧИВАЕМ ТОЛЬКО САЙДБАР, НЕ ВСЮ СТРАНИЦУ
// - Используем scrollTop для прокрутки сайдбара вместо scrollIntoView
// - Предотвращаем прокрутку body и html
// - Добавлена принудительная фиксация body во время прокрутки

function scrollSidebarToActiveTask() {
    var currentUuid = window.currentTaskUuid;
    if (!currentUuid) {
        logDebug('[SCROLL_SIDEBAR_v2.4] No currentTaskUuid, skipping');
        return;
    }
    
    logDebug('[SCROLL_SIDEBAR_v2.4] Starting scroll to task UUID:', currentUuid);
    
    // Функция для прокрутки к задаче в указанном контейнере (только внутри контейнера)
    function scrollToTaskInContainer(container, taskElement, isMobile) {
        if (!container || !taskElement) return false;
        
        // Получаем позицию задачи внутри контейнера
        var containerRect = container.getBoundingClientRect();
        var taskRect = taskElement.getBoundingClientRect();
        
        // Вычисляем относительную позицию задачи внутри контейнера
        var relativeTop = taskRect.top - containerRect.top;
        var targetScrollTop = container.scrollTop + relativeTop - (container.clientHeight / 2) + (taskRect.height / 2);
        
        // Прокручиваем ТОЛЬКО контейнер, не затрагивая body
        container.scrollTo({
            top: Math.max(0, targetScrollTop),
            behavior: 'smooth'
        });
        
        logDebug('[SCROLL_SIDEBAR_v2.4] Scrolled container (not page) to task in ' + (isMobile ? 'mobile' : 'desktop') + ' sidebar');
        return true;
    }
    
    // Функция для поиска и активации задачи по UUID в указанном контейнере
    function activateAndScrollToTaskInContainer(container, isMobile) {
        if (!container) return false;
        
        var taskElement = container.querySelector('.task-item[data-task-uuid="' + currentUuid + '"]');
        if (!taskElement) return false;
        
        // Проверяем, активна ли задача
        var isActive = taskElement.classList.contains('active');
        
        if (!isActive) {
            logDebug('[SCROLL_SIDEBAR_v2.4] Task found but not active, activating');
            
            // Снимаем active со всех задач в этом контейнере
            var allTasks = container.querySelectorAll('.task-item');
            allTasks.forEach(function(t) {
                t.classList.remove('active');
            });
            
            // Добавляем active нужной задаче
            taskElement.classList.add('active');
            logDebug('[SCROLL_SIDEBAR_v2.4] Activated task in ' + (isMobile ? 'mobile' : 'desktop') + ' sidebar');
        }
        
        // Прокручиваем ТОЛЬКО контейнер (не страницу!)
        scrollToTaskInContainer(container, taskElement, isMobile);
        
        return true;
    }
    
    // Временно блокируем прокрутку страницы, чтобы она не дёргалась
    var body = document.body;
    var html = document.documentElement;
    var originalBodyOverflow = body.style.overflow;
    var originalHtmlOverflow = html.style.overflow;
    body.style.overflow = 'hidden';
    html.style.overflow = 'hidden';
    
    // Множественные попытки с разными задержками (для надёжности)
    var scrollAttempts = [100, 300, 600, 1000, 2000];
    var attemptCount = 0;
    
    function doScrollAttempt() {
        if (attemptCount >= scrollAttempts.length) {
            // Восстанавливаем прокрутку страницы после всех попыток
            body.style.overflow = originalBodyOverflow;
            html.style.overflow = originalHtmlOverflow;
            logDebug('[SCROLL_SIDEBAR_v2.4] Restored page scroll');
            return;
        }
        
        var delay = scrollAttempts[attemptCount];
        attemptCount++;
        
        setTimeout(function() {
            // Проверяем, не изменилась ли задача за время ожидания
            if (window.currentTaskUuid !== currentUuid) {
                logDebug('[SCROLL_SIDEBAR_v2.4] Task changed during wait, stopping scroll attempts');
                body.style.overflow = originalBodyOverflow;
                html.style.overflow = originalHtmlOverflow;
                return;
            }
            
            logDebug('[SCROLL_SIDEBAR_v2.4] Scroll attempt ' + attemptCount + ' at ' + delay + 'ms');
            
            // Десктопный сайдбар
            var desktopSidebar = document.querySelector('.messenger-sidebar');
            if (desktopSidebar) {
                activateAndScrollToTaskInContainer(desktopSidebar, false);
            }
            
            // Мобильный сайдбар (если существует)
            var mobileSidebar = document.getElementById('mobile-drawer-panel');
            if (mobileSidebar && mobileSidebar.querySelector('.mobile-drawer-sidebar')) {
                var mobileTasksContainer = mobileSidebar.querySelector('.mobile-drawer-sidebar');
                activateAndScrollToTaskInContainer(mobileTasksContainer, true);
            }
            
            // Также пробуем найти в глобальном DOM и активировать
            var anyTask = document.querySelector('.task-item[data-task-uuid="' + currentUuid + '"]');
            if (anyTask && !anyTask.classList.contains('active')) {
                logDebug('[SCROLL_SIDEBAR_v2.4] Global fallback: activating task');
                document.querySelectorAll('.task-item').forEach(function(t) {
                    t.classList.remove('active');
                });
                anyTask.classList.add('active');
            }
            
            // Следующая попытка
            doScrollAttempt();
        }, delay);
    }
    
    doScrollAttempt();
}
// ==================== BLOCK END: scrollSidebarToActiveTask v2.4 ====================

function initTaskSequential(taskUuid, callback) {
    if (!taskUuid) { if(callback) callback(); return; }
    
    var formData = new URLSearchParams();
    formData.append('action', 'init_task');
    formData.append('task_uuid', taskUuid);
    addCsrfToUrlParams(formData);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.success) {
                    var inp = document.getElementById('message-input');
                    var btn = document.getElementById('send-btn');
                    var att = document.querySelector('.file-attach-btn');
                    var pw = document.getElementById('perm-warning');
                    
                    if (d.can_write === false) {
                        if (pw) { pw.textContent = 'У вас нет прав на отправку сообщений в этой задаче'; pw.className = 'perm-warning error show'; }
                        if(inp){ inp.placeholder = 'Нет прав на отправку'; inp.disabled = true; }
                        if(btn) btn.disabled = true;
                        if(att) att.disabled = true;
                    } else {
                        if (pw) pw.classList.remove('show');
                        if(inp){ inp.placeholder = 'Сообщение (Enter)'; inp.disabled = false; }
                        if(btn) btn.disabled = false;
                        if(att) att.disabled = false;
                    }
                    logDebug('[INIT_TASK] Marked', d.marked_count, 'messages as read');
                }
            } catch(e) { logDebug('[INIT_TASK] JSON error:', e); }
        }
        if (callback) callback();
    };
    xhr.onerror = function() { if(callback) callback(); };
    xhr.ontimeout = function() { if(callback) callback(); };
    xhr.send(formData);
}

function loadTaskUsersSequential(taskUuid, callback) {
    if (!taskUuid) { if(callback) callback([]); return; }
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_task_users');
    formData.append('task_uuid', taskUuid);
    addCsrfToUrlParams(formData);
    formData.append('ajax_mode', '1');
    
    fetch(AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(function(response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(function(data) {
        if (data && data.success && data.users) {
            taskUsersCache = data.users;
            taskUsersLoaded = true;
            logDebug('[MENTION] Loaded ' + data.users.length + ' users');
            if (callback) callback(data.users);
        } else if (callback) callback([]);
    })
    .catch(function(err) {
        logDebug('[MENTION] Error loading users: ' + err.message);
        if (callback) callback([]);
    });
}

// ==================== BLOCK START: loadTaskLastPageSequential v8.54 (NO EXTRA SCROLL ON DESKTOP) ====================
// ver.8.54 (2026-06-10) - НЕ ДЕЛАЕМ ПРОКРУТКУ НА ДЕСКТОПЕ В ЭТОЙ ФУНКЦИИ
// - Прокрутка выполняется только в renderWindowPages
// - Убираем дублирующие scroll-вызовы

function loadTaskLastPageSequential(taskUuid, callback, retryCount) {
    if (retryCount === undefined) retryCount = 0;
    var MAX_RETRIES = 3;
    
    logDebug('[LAST_PAGE_v8.54] Loading last page for task: ' + taskUuid + ' (attempt ' + (retryCount + 1) + '/' + MAX_RETRIES + ')');
    
    if (!taskUuid || window.currentTaskUuid !== taskUuid) {
        logDebug('[LAST_PAGE_v8.54] Task UUID mismatch or empty, aborting');
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('error', '⚠️ Ошибка загрузки', false);
        }
        if (callback) callback();
        return;
    }
    
    var countFormData = new URLSearchParams();
    countFormData.append('action', 'load_messages_paged');
    countFormData.append('task_uuid', taskUuid);
    countFormData.append('page', 0);
    countFormData.append('just_count', '1');
    addCsrfToUrlParams(countFormData);
    
    var countXhr = new XMLHttpRequest();
    countXhr.open('POST', AJAX_URL, true);
    countXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    countXhr.timeout = 30000;
    
    countXhr.onload = function() {
        if (window.currentTaskUuid !== taskUuid) {
            logDebug('[LAST_PAGE_v8.54] Task changed during request, aborting');
            if (callback) callback();
            return;
        }
        
        if (countXhr.status === 503 && retryCount < MAX_RETRIES) {
            var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
            logDebug('[LAST_PAGE_v8.54] Got 503, retrying in ' + delay + 'ms');
            setTimeout(function() {
                loadTaskLastPageSequential(taskUuid, callback, retryCount + 1);
            }, delay);
            return;
        }
        
        if (countXhr.status === 200) {
            try {
                var data = JSON.parse(countXhr.responseText);
                logDebug('[LAST_PAGE_v8.54] Response:', data);
                
                if (data.success && data.total_pages !== undefined) {
                    var lastPage = Math.max(0, data.total_pages - 1);
                    var totalMessages = data.total_count || 0;
                    
                    logDebug('[LAST_PAGE_v8.54] Total pages: ' + data.total_pages + ', total messages: ' + totalMessages + ', loading last page: ' + lastPage);
                    
                    if (!window.pagination.pageCache) {
                        window.pagination.pageCache = {};
                    }
                    
                    window.pagination.totalPages = data.total_pages;
                    window.pagination.totalMessages = totalMessages;
                    window.pagination.initializing = false;
                    
                    // v8.54: НА ДЕСКТОПЕ НЕ ДЕЛАЕМ scrollToBottom ЗДЕСЬ
                    // scrollToBottom будет передан в renderWindowPages
                    var isDesktop = window.innerWidth > 768;
                    
                    logDebug('[LAST_PAGE_v8.54] isDesktop=' + isDesktop + ', scrollToBottom=' + !isDesktop);
                    
                    loadMessagesPage(lastPage, { 
                        scrollToBottom: !isDesktop,
                        isLastPage: true,
                        bufferSize: 1,
                        callback: function(result) {
                            logDebug('[LAST_PAGE_v8.54] Page loaded');
                            if (callback) callback(result);
                        }
                    });
                } else {
                    logDebug('[LAST_PAGE_v8.54] No messages or invalid response, loading page 0');
                    loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
                }
            } catch(e) {
                logDebug('[LAST_PAGE_v8.54] JSON parse error: ' + e.message);
                loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
            }
        } else if (countXhr.status >= 500 && countXhr.status < 600) {
            if (retryCount < MAX_RETRIES) {
                var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
                logDebug('[LAST_PAGE_v8.54] Got HTTP ' + countXhr.status + ', retrying in ' + delay + 'ms');
                setTimeout(function() {
                    loadTaskLastPageSequential(taskUuid, callback, retryCount + 1);
                }, delay);
                return;
            } else {
                logDebug('[LAST_PAGE_v8.54] Max retries reached, falling back to page 0');
                loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
            }
        } else {
            logDebug('[LAST_PAGE_v8.54] HTTP error: ' + countXhr.status);
            if (typeof window.setPageLoadStatus === 'function') {
                window.setPageLoadStatus('error', '⚠️ HTTP ' + countXhr.status, false);
            }
            loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
        }
    };
    
    countXhr.onerror = function() {
        logDebug('[LAST_PAGE_v8.54] Network error');
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('error', '⚠️ Сетевая ошибка', false);
        }
        if (retryCount < MAX_RETRIES) {
            var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
            logDebug('[LAST_PAGE_v8.54] Network error, retrying in ' + delay + 'ms');
            setTimeout(function() {
                loadTaskLastPageSequential(taskUuid, callback, retryCount + 1);
            }, delay);
        } else {
            loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
        }
    };
    
    countXhr.ontimeout = function() {
        logDebug('[LAST_PAGE_v8.54] Timeout');
        if (typeof window.setPageLoadStatus === 'function') {
            window.setPageLoadStatus('error', '⚠️ Таймаут', false);
        }
        if (retryCount < MAX_RETRIES) {
            var delay = Math.min(30000, 1000 * Math.pow(2, retryCount));
            logDebug('[LAST_PAGE_v8.54] Timeout, retrying in ' + delay + 'ms');
            setTimeout(function() {
                loadTaskLastPageSequential(taskUuid, callback, retryCount + 1);
            }, delay);
        } else {
            loadMessagesPage(0, { scrollToBottom: false, callback: callback, bufferSize: 1 });
        }
    };
    
    countXhr.send(countFormData);
}
// ==================== BLOCK END: loadTaskLastPageSequential v8.54 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Message actions (reply, edit, delete, menu) v7.0 ====================
var pendingUploadData = null;

// ==================== BLOCK START: sendMessage v8.36 (with sidebar refresh) ====================
// ver.8.36: Добавлено обновление сайдбара после отправки сообщения
// ver.8.0: Базовая реализация

function sendMessage(e) {
    e.preventDefault();
    if (isSending) return;
    
    var task = document.getElementById('current-task-uuid').value;
    var text = document.getElementById('message-input').value.trim();
    var replyToUuid = document.getElementById('reply-to-uuid').value;
    var hasFiles = selectedFiles.length > 0;
    
    if (!text && !hasFiles) {
        showToast('Введите текст или прикрепите файл', 'warning');
        return;
    }
    if (!task) {
        showToast('Сначала выберите задачу', 'warning');
        return;
    }
    
    isSending = true;
    var btn = document.getElementById('send-btn');
    if (btn) btn.classList.add('loading');
    
    var fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('task_uuid', task);
    fd.append('text', text);
    if (replyToUuid) fd.append('reply_to_uuid', replyToUuid);
    addCsrfToFormData(fd);
    
    selectedFiles.forEach(function(f){ fd.append('files[]', f); });
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.timeout = 60000;
    
    xhr.onload = function() {
        isSending = false;
        if (btn) btn.classList.remove('loading');
        
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.needs_confirmation && d.security_details) {
                    showSecurityWarningModal(d.security_details, d.security_message, selectedFiles, task, text, replyToUuid);
                    return;
                }
                
                if (d.success && d.message) {
                    document.getElementById('message-input').value = '';
                    selectedFiles = [];
                    updateSelectedFiles();
                    document.getElementById('reply-to-uuid').value = '';
                    document.getElementById('reply-indicator').style.display = 'none';
                    
                    var container = document.getElementById('messages-area');
                    var emptyState = container.querySelector('.empty-state');
                    if (emptyState) emptyState.remove();
                    
                    var existingMsg = document.querySelector('.message[data-uuid="' + d.message.uuid + '"]');
                    if (!existingMsg) {
                        container.insertAdjacentHTML('beforeend', renderMessage(d.message));
                        logDebug('[SEND] Message added to DOM');
                    }
                    
                    // Прокрутка с увеличенными задержками
                    setTimeout(function() {
                        if (container && !window.userIsScrolling) {
                            container.scrollTop = container.scrollHeight;
                            logDebug('[SEND] Scroll after 100ms: ' + container.scrollTop + ' / ' + container.scrollHeight);
                        }
                    }, 100);
                    
                    setTimeout(function() {
                        if (container && !window.userIsScrolling) {
                            container.scrollTop = container.scrollHeight;
                            logDebug('[SEND] Scroll after 500ms: ' + container.scrollTop + ' / ' + container.scrollHeight);
                        }
                    }, 500);
                    
                    setTimeout(function() {
                        if (container && !window.userIsScrolling) {
                            container.scrollTop = container.scrollHeight;
                            logDebug('[SEND] Scroll after 1500ms: ' + container.scrollTop + ' / ' + container.scrollHeight);
                        }
                    }, 1500);
                    
                    setTimeout(loadLazyPreviews, 2000);
                    
                    // v8.36: Обновляем пагинацию и сайдбар после добавления сообщения
                    setTimeout(function() {
                        refreshPaginationAfterMutation();
                    }, 500);
                    
                    // Обновляем сайдбар для текущего проекта
                    var currentProjectUuid = getCurrentProjectUuid();
                    if (currentProjectUuid) {
                        logDebug('[SEND] Refreshing sidebar for project:', currentProjectUuid);
                        setTimeout(function() {
                            refreshSidebarForProject(currentProjectUuid, function(success) {
                                if (success) {
                                    logDebug('[SEND] Sidebar refresh completed');
                                } else {
                                    logDebug('[SEND] Sidebar refresh failed');
                                }
                            });
                        }, 1000);
                    }
                    
                    if (d.message.time > lastMessageTime) lastMessageTime = d.message.time;
                }
                
            } catch(e) {
                logDebug('[SEND] JSON error: ' + e);
                showToast('Ошибка обработки ответа сервера', 'error');
            }
        } else {
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
        }
    };
    
    xhr.onerror = function() {
        isSending = false;
        if (btn) btn.classList.remove('loading');
        showToast('Сетевая ошибка', 'error');
    };
    
    xhr.send(fd);
}
// ==================== BLOCK END: sendMessage v8.36 ====================

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
    var html = '<p style="margin-bottom:16px; color:rgba(255,255,255,0.8);">' + escapeHtml(securityMessage) + '</p>';
    html += '<div style="background:#2a2a3a; border-radius:8px; padding:12px; margin-bottom:16px;"><strong style="color:#f59e0b;">📋 Детали проверки:</strong><br><br>';
    for (var i = 0; i < securityDetails.length; i++) {
        var file = securityDetails[i];
        html += '<div style="margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid rgba(255,255,255,0.1);"><strong style="color:#ffd700;">📎 ' + escapeHtml(file.filename) + '</strong><br><span style="font-size:12px; color:rgba(255,255,255,0.7);">Обнаружено:</span><br><ul style="margin:8px 0 0 20px; color:#f87171;">';
        for (var j = 0; j < file.issues.length; j++) {
            html += '<li style="margin-bottom:4px;">⚠️ ' + escapeHtml(file.issues[j]) + '</li>';
        }
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
        if (pendingUploadData) {
            sendIgnoredSecurityUpload(pendingUploadData);
            pendingUploadData = null;
        }
    };
    newCancelBtn.onclick = function() {
        modal.style.display = 'none';
        pendingUploadData = null;
        isSending = false;
        var btn = document.getElementById('send-btn');
        if (btn) btn.classList.remove('loading');
        showToast('Отправка отменена', 'warning');
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
    addCsrfToFormData(fd);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.timeout = 60000;
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.success && d.message) {
                    document.getElementById('message-input').value = '';
                    selectedFiles = [];
                    updateSelectedFiles();
                    document.getElementById('reply-to-uuid').value = '';
                    document.getElementById('reply-indicator').style.display = 'none';
                    var container = document.getElementById('messages-area');
                    var emptyState = container.querySelector('.empty-state');
                    if (emptyState) emptyState.remove();
                    var existingMsg = document.querySelector('.message[data-uuid="' + d.message.uuid + '"]');
                    if (!existingMsg) {
                        container.insertAdjacentHTML('beforeend', renderMessage(d.message));
                    }
                    var distToBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                    if (distToBottom < 500 || !window.userIsScrolling) {
                        setTimeout(function() { container.scrollTop = container.scrollHeight; }, 100);
                    }
                    setTimeout(loadLazyPreviews, 100);
                    //showToast('✓ Сообщение с файлом отправлено', 'success');
                    if (d.message.time > window.lastMessageTime) window.lastMessageTime = d.message.time;
                    playNotificationSound();
                } else {
                    showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                }
            } catch(e) { showToast('Ошибка обработки ответа', 'error'); }
        } else { showToast('Ошибка сервера (' + xhr.status + ')', 'error'); }
        isSending = false;
        var btn = document.getElementById('send-btn');
        if (btn) btn.classList.remove('loading');
    };
    xhr.onerror = function() { isSending = false; if(btn) btn.classList.remove('loading'); showToast('Сетевая ошибка', 'error'); };
    xhr.send(fd);
}

// ==================== BLOCK START: editCurrentMessage v8.32 (Modal Dialog) ====================
// ver.8.32: ПЕРЕПИСАНА С ИСПОЛЬЗОВАНИЕМ МОДАЛЬНОГО ОКНА
// - Модальное окно не перехватывается меню сообщений
// - Нет наложения DOM-элементов
// - Поддержка reply_to_uuid
// - Сохранение позиции скролла после редактирования

function editCurrentMessage() {
    var messageUuid = window._currentMessageUuid;
    var isOwn = window._currentMessageOwn;
    
    logDebug('[EDIT_MODAL] ========== START ==========');
    logDebug('[EDIT_MODAL] messageUuid:', messageUuid);
    logDebug('[EDIT_MODAL] isOwn:', isOwn);
    
    if (!messageUuid || !isOwn) {
        logDebug('[EDIT_MODAL] ERROR: No permission to edit');
        showToast('Нельзя редактировать чужие сообщения', 'error');
        closeMessageMenu();
        return;
    }
    
    // Закрываем все меню
    closeMessageMenu();
    closeFileMenu();
    
    // Получаем элемент сообщения
    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
    if (!msgElement) {
        logDebug('[EDIT_MODAL] ERROR: Message element not found');
        showToast('Сообщение не найдено', 'error');
        return;
    }
    
    // ========== ПОЛУЧАЕМ И ДЕКОДИРУЕМ ТЕКСТ ==========
    var encodedText = msgElement.getAttribute('data-original-text') || '';
    var originalText = '';
    
    // Пробуем декодировать через decodeURIComponent
    try {
        originalText = decodeURIComponent(encodedText);
        logDebug('[EDIT_MODAL] Decoded via decodeURIComponent, length:', originalText.length);
    } catch(e) {
        logDebug('[EDIT_MODAL] decodeURIComponent failed:', e.message);
        // Fallback: используем textarea для декодирования HTML-сущностей
        var tempTextarea = document.createElement('textarea');
        tempTextarea.innerHTML = encodedText;
        originalText = tempTextarea.value;
        logDebug('[EDIT_MODAL] Fallback decode via textarea, length:', originalText.length);
    }
    
    // Если всё равно пусто, пробуем взять текст из .message-text
    if (!originalText) {
        var textDiv = msgElement.querySelector('.message-text');
        if (textDiv) {
            var clone = textDiv.cloneNode(true);
            var quotes = clone.querySelectorAll('.message-quote');
            quotes.forEach(function(quote) { quote.remove(); });
            originalText = clone.textContent || clone.innerText || '';
            originalText = originalText.trim();
            logDebug('[EDIT_MODAL] Extracted text from DOM, length:', originalText.length);
        }
    }
    // ==================================================
    
    var replyToUuid = msgElement.getAttribute('data-reply-uuid') || '';
    
    logDebug('[EDIT_MODAL] originalText preview:', originalText.substring(0, 100));
    
    // Сохраняем контекст
    window._editMessageContext = {
        messageUuid: messageUuid,
        originalText: originalText,
        replyToUuid: replyToUuid,
        msgElement: msgElement
    };
    
    // Получаем элементы модального окна
    var modal = document.getElementById('editMessageModal');
    var textarea = document.getElementById('editMessageTextarea');
    var saveBtn = document.getElementById('editMessageSaveBtn');
    var cancelBtn = document.getElementById('editMessageCancelBtn');
    var closeBtn = document.getElementById('editMessageModalCloseBtn');
    var warningDiv = document.getElementById('editMessageWarning');
    var replyInfoDiv = document.getElementById('editMessageReplyInfo');
    var replyQuoteDiv = document.getElementById('editMessageReplyQuote');
    
    if (!modal || !textarea) {
        logDebug('[EDIT_MODAL] ERROR: Modal elements not found');
        showToast('Ошибка: не удалось открыть редактор', 'error');
        return;
    }
    
    // Устанавливаем ДЕКОДИРОВАННЫЙ текст в textarea
    textarea.value = originalText;
    
    // Показываем информацию о reply, если есть
    if (replyToUuid) {
        var replyText = '';
        var quoteElement = msgElement.querySelector('.message-quote');
        if (quoteElement) {
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = quoteElement.innerHTML;
            replyText = tempDiv.textContent || tempDiv.innerText || '';
            replyText = replyText.replace(/📎 Цитата:/g, '').trim();
            if (replyText.length > 300) {
                replyText = replyText.substring(0, 300) + '…';
            }
        }
        
        if (replyText) {
            replyQuoteDiv.textContent = replyText;
            replyInfoDiv.style.display = 'block';
            logDebug('[EDIT_MODAL] Reply info displayed, length:', replyText.length);
        } else {
            replyInfoDiv.style.display = 'none';
        }
    } else {
        replyInfoDiv.style.display = 'none';
    }
    
    // Скрываем предупреждение
    warningDiv.style.display = 'none';
    
    // Сохраняем позицию скролла
    var container = document.getElementById('messages-area');
    var savedScrollTop = container ? container.scrollTop : 0;
    var wasAtBottom = container ? (container.scrollHeight - container.scrollTop - container.clientHeight) < 100 : false;
    window._editScrollPosition = savedScrollTop;
    window._editWasAtBottom = wasAtBottom;
    
    // Показываем модальное окно
    modal.style.display = 'flex';
    
    // Фокусируемся и ставим курсор в конец
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    
    // Обработчик сохранения
    function handleSave() {
        var newText = textarea.value.trim();
        logDebug('[EDIT_MODAL] handleSave called, newText length:', newText.length);
        
        if (!newText) {
            warningDiv.style.display = 'block';
            return;
        }
        
        warningDiv.style.display = 'none';
        
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        if (closeBtn) closeBtn.disabled = true;
        saveBtn.textContent = '⏳ Сохранение...';
        
        var formData = new URLSearchParams();
        formData.append('action', 'edit_message');
        formData.append('message_uuid', messageUuid);
        formData.append('text', newText);
        if (replyToUuid) {
            formData.append('reply_to_uuid', replyToUuid);
        }
        if (typeof addCsrfToUrlParams === 'function') {
            addCsrfToUrlParams(formData);
        }
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', typeof AJAX_URL !== 'undefined' ? AJAX_URL : window.APP_BASE + '/messages.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success && d.message) {
                        modal.style.display = 'none';
                        
                        if (typeof smartUpdateMessage === 'function') {
                            smartUpdateMessage(d.message);
                            logDebug('[EDIT_MODAL] smartUpdateMessage called');
                        } else if (typeof refreshPaginationAfterMutation === 'function') {
                            refreshPaginationAfterMutation();
                        }
                        
                        setTimeout(function() {
                            var containerElem = document.getElementById('messages-area');
                            if (containerElem) {
                                if (window._editWasAtBottom) {
                                    containerElem.scrollTop = containerElem.scrollHeight;
                                } else if (window._editScrollPosition > 0) {
                                    containerElem.scrollTop = window._editScrollPosition;
                                }
                            }
                            if (typeof restoreCachedImagesToDOM === 'function') {
                                restoreCachedImagesToDOM();
                            }
                        }, 100);
                        
                        showToast('✓ Сообщение изменено', 'success');
                    } else {
                        showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
            
            saveBtn.disabled = false;
            cancelBtn.disabled = false;
            if (closeBtn) closeBtn.disabled = false;
            saveBtn.textContent = '💾 Сохранить';
        };
        
        xhr.onerror = function() {
            showToast('Сетевая ошибка', 'error');
            saveBtn.disabled = false;
            cancelBtn.disabled = false;
            if (closeBtn) closeBtn.disabled = false;
            saveBtn.textContent = '💾 Сохранить';
        };
        
        xhr.ontimeout = function() {
            showToast('Превышено время ожидания', 'error');
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
        if (e.key === 'Escape') {
            e.preventDefault();
            handleCancel();
        }
    }
    
    // Навешиваем обработчики
    var newSaveBtn = saveBtn.cloneNode(true);
    var newCancelBtn = cancelBtn.cloneNode(true);
    var newCloseBtn = closeBtn ? closeBtn.cloneNode(true) : null;
    
    saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
    if (closeBtn && newCloseBtn) {
        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
    }
    
    newSaveBtn.onclick = function(e) {
        e.stopPropagation();
        handleSave();
    };
    
    newCancelBtn.onclick = function(e) {
        e.stopPropagation();
        handleCancel();
    };
    
    if (newCloseBtn) {
        newCloseBtn.onclick = function(e) {
            e.stopPropagation();
            handleCancel();
        };
    }
    
    var overlay = modal.querySelector('.edit-message-modal-overlay');
    if (overlay) {
        overlay.onclick = function(e) {
            e.stopPropagation();
            handleCancel();
        };
    }
    
    document.removeEventListener('keydown', window._editModalKeydownHandler);
    window._editModalKeydownHandler = handleModalKeydown;
    document.addEventListener('keydown', window._editModalKeydownHandler);
    
    textarea.onkeydown = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSave();
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            handleCancel();
        }
    };
    
    logDebug('[EDIT_MODAL] Modal opened successfully');
}
// ==================== BLOCK END: editCurrentMessage v8.32 ====================


// ==================== BLOCK START: deleteMessage v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование

function deleteMessage(messageUuid) {
    logDebug('[DELETE_MSG] ========== START ==========');
    logDebug('[DELETE_MSG] Called with messageUuid:', messageUuid);
    
    if (!messageUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            messageUuid = window._fileMenuContext.messageUuid;
            logDebug('[DELETE_MSG] Using fileMenuContext.messageUuid:', messageUuid);
        }
    }
    
    if (!messageUuid) {
        logDebug('[DELETE_MSG] ERROR: No message UUID found');
        showToast('Ошибка: сообщение не найдено', 'error');
        return;
    }
    
    if (!confirm('Удалить сообщение?')) {
        logDebug('[DELETE_MSG] Cancelled by user');
        return;
    }
    
    logDebug('[DELETE_MSG] Sending delete request for message:', messageUuid);
    
    var formData = new URLSearchParams();
    formData.append('action', 'delete_message');
    formData.append('message_uuid', messageUuid);
    addCsrfToUrlParams(formData);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        logDebug('[DELETE_MSG] Response status:', xhr.status);
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                logDebug('[DELETE_MSG] Response data:', d);
                if (d.success) {
                    logDebug('[DELETE_MSG] SUCCESS! Message deleted');
                    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                    if (msgElement) {
                        msgElement.style.transition = 'opacity 0.3s ease';
                        msgElement.style.opacity = '0';
                        setTimeout(function() {
                            if (msgElement && msgElement.parentNode) {
                                msgElement.remove();
                                var container = document.getElementById('messages-area');
                                if (container && !container.querySelector('.message')) {
                                    container.innerHTML = '<div class="empty-state">Нет сообщений</div>';
                                }
                                updateUnreadBadge();
                                setTimeout(function() {
                                    refreshPaginationAfterMutation();
                                }, 500);
                                logDebug('[DELETE_MSG] Message element removed from DOM');
                            }
                        }, 300);
                    }
                    //showToast('✓ Сообщение удалено', 'success');
                } else {
                    logDebug('[DELETE_MSG] ERROR:', d.error);
                    showToast('Ошибка: ' + d.error, 'error');
                }
            } catch(e) {
                logDebug('[DELETE_MSG] JSON parse error:', e);
                showToast('Ошибка обработки ответа', 'error');
            }
        } else {
            logDebug('[DELETE_MSG] HTTP error:', xhr.status);
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
        }
    };
    
    xhr.onerror = function() {
        logDebug('[DELETE_MSG] Network error');
        showToast('Сетевая ошибка', 'error');
    };
    
    xhr.ontimeout = function() {
        logDebug('[DELETE_MSG] Timeout');
        showToast('Превышено время ожидания', 'error');
    };
    
    xhr.send(formData);
}
// ==================== BLOCK END: deleteMessage v8.16 ====================



// ==================== BLOCK START: deleteCurrentMessage v8.23 (admins can delete foreign messages) ====================
// ver.8.23: Администраторам разрешено удалять чужие сообщения
// - Проверка: можно удалить если (isOwn ИЛИ isAdmin)
// - Добавлено подробное логирование

function deleteCurrentMessage() {
    var messageUuid = window._currentMessageUuid;
    var isOwn = window._currentMessageOwn;
    var isAdmin = (window.currentUserIsAdmin === true);
    
    logDebug('[DELETE_CURRENT] ========== START ==========');
    logDebug('[DELETE_CURRENT] messageUuid:', messageUuid);
    logDebug('[DELETE_CURRENT] isOwn:', isOwn);
    logDebug('[DELETE_CURRENT] isAdmin:', isAdmin);
    logDebug('[DELETE_CURRENT] window._fileMenuContext:', window._fileMenuContext);
    
    // v8.23: Если нет messageUuid, пытаемся взять из контекста файлового меню
    if (!messageUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            messageUuid = window._fileMenuContext.messageUuid;
            isOwn = window._fileMenuContext.isOwn;
            logDebug('[DELETE_CURRENT] Using fileMenuContext - messageUuid:', messageUuid, 'isOwn:', isOwn);
        }
    }
    
    // v8.23: Администратор может удалять любые сообщения (isOwn ИЛИ isAdmin)
    var canDelete = (messageUuid && (isOwn || isAdmin));
    
    logDebug('[DELETE_CURRENT] canDelete (messageUuid AND (isOwn OR isAdmin)):', canDelete);
    
    if (!canDelete) {
        logDebug('[DELETE_CURRENT] ERROR: Cannot delete - messageUuid:', messageUuid, 'isOwn:', isOwn, 'isAdmin:', isAdmin);
        showToast('Нельзя удалять чужие сообщения', 'error');
        closeMessageMenu();
        return;
    }
    
    logDebug('[DELETE_CURRENT] Proceeding with delete for message:', messageUuid);
    closeMessageMenu();
    deleteMessage(messageUuid);
}
// ==================== BLOCK END: deleteCurrentMessage v8.23 ====================

// ==================== BLOCK START: replyToCurrentMessage v8.16 (with logging) ====================
// ver.8.16: Добавлена поддержка вызова из файлового меню

function replyToCurrentMessage() {
    var replyToUuid = window._currentMessageUuid;
    var authorName = window._currentMessageAuthor;
    
    logDebug('[REPLY_CURRENT] ========== START ==========');
    logDebug('[REPLY_CURRENT] window._currentMessageUuid:', replyToUuid);
    
    if (!replyToUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            replyToUuid = window._fileMenuContext.messageUuid;
            authorName = '';
            logDebug('[REPLY_CURRENT] Using fileMenuContext.messageUuid:', replyToUuid);
        }
    }
    
    if (!replyToUuid) {
        logDebug('[REPLY_CURRENT] ERROR: No message UUID found');
        showToast('Ошибка: сообщение не найдено', 'error');
        closeMessageMenu();
        return;
    }
    
    logDebug('[REPLY_CURRENT] Setting reply to message:', replyToUuid);
    var replyToInput = document.getElementById('reply-to-uuid');
    if (replyToInput) replyToInput.value = replyToUuid;
    
    var indicator = document.getElementById('reply-indicator');
    var authorSpan = document.getElementById('reply-author-name');
    if (indicator && authorSpan) {
        authorSpan.textContent = authorName || 'Пользователя';
        indicator.style.display = 'flex';
        logDebug('[REPLY_CURRENT] Reply indicator shown');
    }
    
    document.getElementById('message-input').focus();
    closeMessageMenu();
    logDebug('[REPLY_CURRENT] Done');
}
// ==================== BLOCK END: replyToCurrentMessage v8.16 ====================

function cancelReply() {
    document.getElementById('reply-to-uuid').value = '';
    document.getElementById('reply-indicator').style.display = 'none';
}

// ==================== BLOCK START: copyMessageLink v8.16 (with logging) ====================
function copyMessageLink() {
    var messageUuid = window._currentMessageUuid;
    
    if (!messageUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            messageUuid = window._fileMenuContext.messageUuid;
        }
    }
    
    if (!messageUuid) {
        showToast('Ошибка: сообщение не найдено', 'error');
        closeMessageMenu();
        return;
    }
    
    var fullUrl = window.location.protocol + '//' + window.location.host + (window.APP_BASE || '') + '/messages.php?message=' + messageUuid;
    navigator.clipboard.writeText(fullUrl).then(function() {
        showToast('✓ Ссылка на сообщение скопирована', 'success');
    }).catch(function() {
        var textarea = document.createElement('textarea');
        textarea.value = fullUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('✓ Ссылка на сообщение скопирована', 'success');
    });
    closeMessageMenu();
}

// ==================== BLOCK START: copyMessageText v8.34 (pure text without quotes) ====================
// ==================== BLOCK START: copyMessageText v8.35 (fix URI decoding) ====================
// ver.8.35: Исправлено декодирование URI-компонентов при копировании текста

function copyMessageText() {
    var text = null;
    
    logDebug('[COPY_TEXT] ========== START ==========');
    
    // Функция для декодирования текста
    function decodeText(encodedText) {
        if (!encodedText) return '';
        try {
            // Пробуем декодировать как URI component
            var decoded = decodeURIComponent(encodedText);
            logDebug('[COPY_TEXT] Successfully decoded URI component');
            return decoded;
        } catch(e) {
            // Если не получилось, возвращаем как есть
            logDebug('[COPY_TEXT] Not a URI component, using as is');
            return encodedText;
        }
    }
    
    // Получаем текст из меню сообщения
    if (window._currentMessageText && window._currentMessageText.length > 0) {
        text = decodeText(window._currentMessageText);
        logDebug('[COPY_TEXT] Using _currentMessageText, decoded length:', text.length);
    } 
    // Или из контекста файлового меню
    else if (window._fileMenuContext && window._fileMenuContext.messageText && window._fileMenuContext.messageText.length > 0) {
        text = decodeText(window._fileMenuContext.messageText);
        logDebug('[COPY_TEXT] Using fileMenuContext.messageText, decoded length:', text.length);
    }
    // Или из currentFileMenuContext
    else if (typeof currentFileMenuContext !== 'undefined' && currentFileMenuContext && currentFileMenuContext.messageText) {
        text = decodeText(currentFileMenuContext.messageText);
        logDebug('[COPY_TEXT] Using currentFileMenuContext.messageText, decoded length:', text.length);
    }
    
    if (!text || text.length === 0) {
        logDebug('[COPY_TEXT] ERROR: No text to copy');
        closeMessageMenu();
        return;
    }
    
    logDebug('[COPY_TEXT] Copying text, preview:', text.substring(0, 100));
    
    navigator.clipboard.writeText(text).then(function() {
        logDebug('[COPY_TEXT] SUCCESS! Text copied via clipboard API');
        showToast('✓ Текст скопирован', 'success');
    }).catch(function() {
        logDebug('[COPY_TEXT] Clipboard API failed, using fallback');
        var textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        logDebug('[COPY_TEXT] SUCCESS! Text copied via fallback');
        showToast('✓ Текст скопирован', 'success');
    });
    closeMessageMenu();
}
// ==================== BLOCK END: copyMessageText v8.35 ====================

// Вспомогательная функция для очистки текста от HTML и форматирования цитат
function cleanMessageText(htmlText) {
    if (!htmlText) return '';
    
    // Создаём временный DOM элемент
    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlText;
    
    // Находим все цитаты
    var quotes = tempDiv.querySelectorAll('.message-quote');
    var quoteTexts = [];
    
    // Обрабатываем цитаты
    quotes.forEach(function(quote) {
        // Удаляем служебные элементы
        var quoteCopy = quote.cloneNode(true);
        
        // Удаляем спаны с иконками и служебные элементы
        var iconSpans = quoteCopy.querySelectorAll('span[style*="font-size:10px"]');
        iconSpans.forEach(function(span) { span.remove(); });
        
        // Получаем чистый текст цитаты
        var quoteContent = quoteCopy.textContent || quoteCopy.innerText;
        quoteContent = quoteContent.replace(/📎 Цитата:/g, '').trim();
        
        // Форматируем цитату с символом "> " в начале каждой строки
        var quotedLines = quoteContent.split('\n').map(function(line) {
            return '> ' + line;
        }).join('\n');
        
        quoteTexts.push(quotedLines);
        
        // Удаляем оригинальную цитату из текста
        quote.remove();
    });
    
    // Получаем остальной текст (основное сообщение)
    var mainText = tempDiv.textContent || tempDiv.innerText;
    mainText = mainText.trim();
    
    // Формируем итоговый текст
    var result = [];
    
    // Добавляем цитаты (если есть)
    if (quoteTexts.length > 0) {
        result.push(quoteTexts.join('\n\n'));
    }
    
    // Добавляем основное сообщение (с двумя переносами после цитаты)
    if (mainText.length > 0) {
        if (quoteTexts.length > 0) {
            result.push(mainText);
        } else {
            result.push(mainText);
        }
    }
    
    // Соединяем с двумя переносами строки
    return result.join('\n\n');
}




// ==================== BLOCK START: showMessageMenu v8.34 (pure text from database) ====================
// ver.8.34: Копируется только чистый текст сообщения из базы, без цитат и HTML

function showMessageMenu(event, messageUuid, isOwn, authorName, messageTime) {
    if (event.target.tagName === 'A' || event.target.closest('a') || event.target.closest('.clickable-quote')) return;
    event.stopPropagation();
    
    // Закрываем файловое меню, если оно открыто
    closeFileMenu();
    
    var isAdmin = (window.currentUserIsAdmin === true);
    
    logDebug('[MENU] showMessageMenu - messageUuid:', messageUuid, 'isOwn:', isOwn, 'isAdmin:', isAdmin);
    
    window._currentMessageUuid = messageUuid;
    window._currentMessageOwn = isOwn;
    window._currentMessageAuthor = authorName;
    window._currentMessageTime = messageTime;
    
    var msgElement = document.querySelector('.message[data-uuid="'+messageUuid+'"]');
    // В функции showMessageMenu, замените получение текста на:
    if (msgElement) {
        // БЕРЁМ ЧИСТЫЙ ТЕКСТ ИЗ АТРИБУТА data-original-text (это текст из базы, без цитат)
        var originalText = msgElement.getAttribute('data-original-text');
        if (originalText) {
            // Декодируем из URI-компонента обратно в нормальный текст
            try {
                window._currentMessageText = decodeURIComponent(originalText);
                logDebug('[MENU] Decoded message text from data-original-text, length:', window._currentMessageText.length);
            } catch(e) {
                // Если не получилось, используем как есть
                var textarea = document.createElement('textarea');
                textarea.innerHTML = originalText;
                window._currentMessageText = textarea.value;
                logDebug('[MENU] Fallback decode, length:', window._currentMessageText.length);
            }
        } else {
            // Fallback: извлекаем текст из .message-text, но без цитат
            var messageTextDiv = msgElement.querySelector('.message-text');
            if (messageTextDiv) {
                var clone = messageTextDiv.cloneNode(true);
                var quotes = clone.querySelectorAll('.message-quote');
                quotes.forEach(function(quote) { quote.remove(); });
                window._currentMessageText = clone.textContent || clone.innerText || '';
                window._currentMessageText = window._currentMessageText.trim();
                logDebug('[MENU] Extracted text without quotes, length:', window._currentMessageText.length);
            }
        }
    } else {
        logDebug('[MENU] WARNING: Message element not found for UUID:', messageUuid);
        window._currentMessageText = '';
    }
    
    // Определяем видимость кнопок
    var showFullMenu = isOwn;
    var showDeleteOnly = (!isOwn && isAdmin);
    
    logDebug('[MENU] showFullMenu:', showFullMenu, 'showDeleteOnly:', showDeleteOnly);
    
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
        logDebug('[MENU] Full menu shown (own message)');
    } else if (showDeleteOnly) {
        if (editBtn) editBtn.style.display = 'none';
        if (deleteMenuItem) deleteMenuItem.style.display = 'flex';
        if (addFilesBtn) addFilesBtn.style.display = 'none';
        if (replaceFileBtn) replaceFileBtn.style.display = 'none';
        logDebug('[MENU] Delete-only menu shown (foreign message, admin)');
    } else {
        if (editBtn) editBtn.style.display = 'none';
        if (deleteMenuItem) deleteMenuItem.style.display = 'none';
        if (addFilesBtn) addFilesBtn.style.display = 'none';
        if (replaceFileBtn) replaceFileBtn.style.display = 'none';
        logDebug('[MENU] No modification menu shown (foreign message, not admin)');
    }
    
    var overlay = document.getElementById('messageMenuOverlay');
    if (overlay) overlay.classList.add('show');
}
// ==================== BLOCK END: showMessageMenu v8.34 ====================



// ==================== BLOCK START: closeMessageMenu v8.32 (with edit modal close) ====================
// ver.8.32: Добавлено закрытие модального окна редактирования
// ver.8.9: При закрытии меню сообщения также закрываем файловое меню

function closeMessageMenu() {
    // ==================== BLOCK START: Close edit modal in closeMessageMenu v8.32 ====================
    // Закрываем модальное окно редактирования, если оно открыто
    var editModal = document.getElementById('editMessageModal');
    if (editModal && editModal.style.display === 'flex') {
        logDebug('[CLOSE_MENU] Closing edit modal');
        editModal.style.display = 'none';
        if (window._editModalKeydownHandler) {
            document.removeEventListener('keydown', window._editModalKeydownHandler);
            window._editModalKeydownHandler = null;
        }
        window._editMessageContext = null;
    }
    // ==================== BLOCK END: Close edit modal in closeMessageMenu v8.32 ====================
    
    var overlay = document.getElementById('messageMenuOverlay');
    if (overlay) overlay.classList.remove('show');
    
    // Также закрываем файловое меню для чистоты
    closeFileMenu();
}
// ==================== BLOCK END: closeMessageMenu v8.32 ====================



// ==================== BLOCK START: addFilesToMessage v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование, прямая передача messageUuid

function addFilesToMessage() {
    var messageUuid = window._currentMessageUuid;
    
    logDebug('[ADD_FILES_TO_MSG] ========== START ==========');
    logDebug('[ADD_FILES_TO_MSG] window._currentMessageUuid:', messageUuid);
    logDebug('[ADD_FILES_TO_MSG] window._fileMenuContext:', window._fileMenuContext);
    
    if (!messageUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            messageUuid = window._fileMenuContext.messageUuid;
            logDebug('[ADD_FILES_TO_MSG] Using fileMenuContext.messageUuid:', messageUuid);
        }
    }
    
    if (!messageUuid) {
        logDebug('[ADD_FILES_TO_MSG] ERROR: No message UUID found');
        showToast('Ошибка: сообщение не найдено', 'error');
        return;
    }
    
    logDebug('[ADD_FILES_TO_MSG] Closing menus and opening file dialog for message:', messageUuid);
    closeMessageMenu();
    closeFileMenu();
    
    // Прямой вызов uploadFilesToMessage с передачей UUID
    uploadFilesToMessage(messageUuid, null);
}
// ==================== BLOCK END: addFilesToMessage v8.16 ====================


// ==================== BLOCK START: uploadFilesToMessage v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование

function uploadFilesToMessage(messageUuid, files) {
    logDebug('[UPLOAD_FILES] ========== START ==========');
    logDebug('[UPLOAD_FILES] messageUuid:', messageUuid);
    logDebug('[UPLOAD_FILES] files provided:', files ? files.length : 'null (will open dialog)');
    
    if (!messageUuid) {
        logDebug('[UPLOAD_FILES] ERROR: No message UUID');
        showToast('Ошибка: сообщение не найдено', 'error');
        return;
    }
    
    // Если файлы не переданы, открываем диалог выбора файлов
    if (!files || files.length === 0) {
        logDebug('[UPLOAD_FILES] Opening file selection dialog');
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.style.display = 'none';
        fileInput.accept = 'image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.*,text/plain,application/zip';
        
        fileInput.onchange = function(e) {
            logDebug('[UPLOAD_FILES] File input onchange, files selected:', e.target.files ? e.target.files.length : 0);
            if (e.target.files && e.target.files.length > 0) {
                uploadFilesToMessage(messageUuid, Array.from(e.target.files));
            }
            fileInput.remove();
        };
        
        document.body.appendChild(fileInput);
        fileInput.click();
        return;
    }
    
    logDebug('[UPLOAD_FILES] Uploading', files.length, 'files to message:', messageUuid);
    showToast('⏳ Загрузка файлов...', 'info');
    
    var formData = new FormData();
    formData.append('action', 'add_files_to_message');
    formData.append('message_uuid', messageUuid);
    addCsrfToFormData(formData);
    
    for (var i = 0; i < files.length; i++) {
        logDebug('[UPLOAD_FILES] Appending file:', files[i].name, files[i].size, files[i].type);
        formData.append('files[]', files[i]);
    }
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.timeout = 120000;
    
    xhr.onload = function() {
        logDebug('[UPLOAD_FILES] Response status:', xhr.status);
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                logDebug('[UPLOAD_FILES] Response data:', d);
                if (d.success && d.message) {
                    logDebug('[UPLOAD_FILES] SUCCESS! Message updated');
                    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                    if (msgElement) {
                        var newMsgHtml = renderMessage(d.message);
                        var temp = document.createElement('div');
                        temp.innerHTML = newMsgHtml;
                        var newMsgElement = temp.firstChild;
                        msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                        //showToast('✓ Файлы добавлены', 'success');
                        setTimeout(loadLazyPreviews, 100);
                        setTimeout(function() {
                            refreshPaginationAfterMutation();
                        }, 500);
                        logDebug('[UPLOAD_FILES] Message element replaced successfully');
                    } else {
                        logDebug('[UPLOAD_FILES] WARNING: Message element not found, reloading page');
                        location.reload();
                    }
                } else if (d.needs_confirmation && d.security_details) {
                    logDebug('[UPLOAD_FILES] Security confirmation needed');
                    showToast('Требуется подтверждение безопасности', 'warning');
                } else {
                    logDebug('[UPLOAD_FILES] ERROR:', d.error);
                    showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                }
            } catch(e) {
                logDebug('[UPLOAD_FILES] JSON parse error:', e);
                showToast('Ошибка обработки ответа', 'error');
            }
        } else {
            logDebug('[UPLOAD_FILES] HTTP error:', xhr.status);
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
        }
    };
    
    xhr.onerror = function() {
        logDebug('[UPLOAD_FILES] Network error');
        showToast('Сетевая ошибка', 'error');
    };
    
    xhr.ontimeout = function() {
        logDebug('[UPLOAD_FILES] Timeout');
        showToast('Превышено время ожидания', 'error');
    };
    
    xhr.send(formData);
}
// ==================== BLOCK END: uploadFilesToMessage v8.16 ====================


// ==================== BLOCK START: replaceFileInMessage v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование

function replaceFileInMessage() {
    var messageUuid = window._currentMessageUuid;
    var isOwn = window._currentMessageOwn;
    
    logDebug('[REPLACE_FILE] ========== START ==========');
    logDebug('[REPLACE_FILE] window._currentMessageUuid:', messageUuid);
    logDebug('[REPLACE_FILE] window._currentMessageOwn:', isOwn);
    
    if (!messageUuid) {
        if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
            messageUuid = window._fileMenuContext.messageUuid;
            isOwn = window._fileMenuContext.isOwn;
            logDebug('[REPLACE_FILE] Using fileMenuContext - messageUuid:', messageUuid, 'isOwn:', isOwn);
        }
    }
    
    if (!messageUuid || !isOwn) {
        logDebug('[REPLACE_FILE] ERROR: Cannot replace - messageUuid:', messageUuid, 'isOwn:', isOwn);
        showToast('Нельзя заменять файлы в чужих сообщениях', 'error');
        closeMessageMenu();
        return;
    }
    
    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
    if (!msgElement) {
        logDebug('[REPLACE_FILE] ERROR: Message element not found for UUID:', messageUuid);
        showToast('Сообщение не найдено', 'error');
        closeMessageMenu();
        return;
    }
    
    var fileItems = msgElement.querySelectorAll('.file-item, .file-preview-thumb');
    var filesList = [];
    fileItems.forEach(function(item) {
        var onclickAttr = item.getAttribute('onclick');
        if (onclickAttr) {
            var match = onclickAttr.match(/showFilePreview\('([^']+)'/);
            if (match) {
                filesList.push({ uuid: match[1], element: item });
                logDebug('[REPLACE_FILE] Found file in message:', match[1]);
            }
        }
    });
    
    logDebug('[REPLACE_FILE] Total files found in message:', filesList.length);
    
    if (filesList.length === 0) {
        logDebug('[REPLACE_FILE] No files found in message');
        showToast('В этом сообщении нет файлов для замены', 'warning');
        closeMessageMenu();
        return;
    }
    
    if (filesList.length === 1) {
        logDebug('[REPLACE_FILE] Single file, proceeding with replace for file:', filesList[0].uuid);
        window.currentReplaceFileUuid = filesList[0].uuid;
        window.currentReplaceMessageUuid = messageUuid;
        closeMessageMenu();
        openFileReplaceDialog();
    } else {
        logDebug('[REPLACE_FILE] Multiple files, showing selection dialog');
        closeMessageMenu();
        showFileSelectionForReplace(filesList, messageUuid);
    }
}
// ==================== BLOCK END: replaceFileInMessage v8.16 ====================



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
        if (fileLink && fileLink.textContent) {
            fileName = fileLink.textContent.replace(/[📎📄📝📊🖼️]/g, '').trim();
        }
        html += '<div class="file-select-item" data-file-uuid="' + file.uuid + '" style="padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background=\'rgba(79,124,255,0.1)\'" onmouseout="this.style.background=\'transparent\'">';
        html += '<div style="display:flex; align-items:center; gap:12px;">';
        html += '<span style="font-size:20px;">📎</span>';
        html += '<span style="flex:1; word-break:break-word;">' + escapeHtml(fileName || 'Файл') + '</span>';
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
    
    window.closeFileSelectModal = function() {
        if (modal && modal.parentNode) modal.remove();
    };
}

// ==================== BLOCK START: openFileReplaceDialog v8.9 (with menu cleanup) ====================
// ver.8.9: Добавлена очистка меню при открытии диалога

function openFileReplaceDialog() {
    // Дополнительная защита - закрываем любые открытые меню
    closeFileMenu();
    closeMessageMenu();
    
    if (document.getElementById('replaceFileDialog')) return;
    
    var overlay = document.createElement('div');
    overlay.id = 'replaceFileDialog';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.85); z-index:20002; display:flex; align-items:center; justify-content:center;';
    
    var modalContent = document.createElement('div');
    modalContent.style.cssText = 'background:#1e293b; border-radius:20px; width:90%; max-width:380px; border:1px solid rgba(79,124,255,0.3); box-shadow:0 20px 40px rgba(0,0,0,0.5); overflow:hidden;';
    
    modalContent.innerHTML = `
        <div style="padding:20px 24px; background:linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-bottom:1px solid rgba(79,124,255,0.3);">
            <h3 style="margin:0; font-size:18px; color:#e9eefc; display:flex; align-items:center; gap:10px;">
                <span>🔄</span> Управление файлом
            </h3>
            <p style="margin:8px 0 0 0; font-size:12px; color:rgba(233,238,252,0.6);">Выберите действие для файла</p>
        </div>
        <div style="padding:24px;">
            <button id="replaceFileChooseBtn" style="width:100%; padding:14px; background:#4f7cff; border:none; border-radius:12px; color:white; cursor:pointer; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">
                📁 Заменить файл с диска
            </button>
            <button id="replaceFilePasteBtn" style="width:100%; padding:14px; background:rgba(79,124,255,0.15); border:1px solid rgba(79,124,255,0.4); border-radius:12px; color:#9bb7ff; cursor:pointer; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">
                📋 Заменить из буфера обмена (Ctrl+V)
            </button>
            <button id="replaceFileDeleteBtn" style="width:100%; padding:14px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:12px; color:#f87171; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:12px; font-size:15px; font-weight:500; transition:all 0.2s;">
                🗑️ Удалить файл из сообщения
            </button>
        </div>
        <div style="padding:16px 24px; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:flex-end;">
            <button id="replaceFileCancelBtn" style="background:rgba(255,255,255,0.08); border:none; padding:10px 20px; border-radius:10px; color:#e9eefc; cursor:pointer; font-size:14px; transition:all 0.2s;">Отмена</button>
        </div>
    `;
    
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
                showToast('⏰ Время ожидания истекло', 'warning');
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
                    if (items[i].type.indexOf('image') !== -1) {
                        imageBlob = items[i].getAsFile();
                        break;
                    }
                }
                
                if (imageBlob) {
                    var now = new Date();
                    var fileName = 'pasted_image_' + now.getFullYear() + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0') + '_' + String(now.getHours()).padStart(2,'0') + String(now.getMinutes()).padStart(2,'0') + String(now.getSeconds()).padStart(2,'0') + '.png';
                    var newFile = new File([imageBlob], fileName, { type: imageBlob.type });
                    if (confirm('⚠️ Вы уверены, что хотите ЗАМЕНИТЬ файл на изображение из буфера?\n\nСтарый файл будет удалён безвозвратно.')) {
                        executeFileReplace(window.currentReplaceMessageUuid, window.currentReplaceFileUuid, newFile);
                    }
                } else {
                    showToast('❌ В буфере обмена нет изображения', 'error');
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
                showToast('Удаление отменено', 'info');
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
            showToast('Замена файла отменена', 'info');
        };
    }
    
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            overlay.remove();
            window.currentReplaceFileUuid = null;
            window.currentReplaceMessageUuid = null;
            showToast('Замена файла отменена', 'info');
        }
    };
}
// ==================== BLOCK END: openFileReplaceDialog v8.9 ====================

// ==================== BLOCK START: executeFileReplace v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование

function executeFileReplace(messageUuid, oldFileUuid, newFile) {
    logDebug('[EXECUTE_FILE_REPLACE] ========== START ==========');
    logDebug('[EXECUTE_FILE_REPLACE] messageUuid:', messageUuid);
    logDebug('[EXECUTE_FILE_REPLACE] oldFileUuid:', oldFileUuid);
    logDebug('[EXECUTE_FILE_REPLACE] newFile:', newFile ? newFile.name : 'null');
    
    if (!messageUuid || !oldFileUuid || !newFile) {
        logDebug('[EXECUTE_FILE_REPLACE] ERROR: Missing parameters');
        showToast('Ошибка: не указаны параметры', 'error');
        return;
    }
    
    logDebug('[EXECUTE_FILE_REPLACE] Sending replace request');
    showToast('🔄 Замена файла...', 'info');
    
    var formData = new FormData();
    formData.append('action', 'replace_message_file');
    formData.append('message_uuid', messageUuid);
    formData.append('old_file_uuid', oldFileUuid);
    formData.append('file', newFile);
    addCsrfToFormData(formData);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.timeout = 60000;
    
    xhr.onload = function() {
        logDebug('[EXECUTE_FILE_REPLACE] Response status:', xhr.status);
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                logDebug('[EXECUTE_FILE_REPLACE] Response data:', data);
                if (data.success) {
                    logDebug('[EXECUTE_FILE_REPLACE] SUCCESS! File replaced');
                    setTimeout(function() {
                        refreshPaginationAfterMutation();
                    }, 500);
                    
                    //showToast('✓ Файл успешно заменён', 'success');
                    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                    if (msgElement && data.message) {
                        var newMsgHtml = renderMessage(data.message);
                        var tempContainer = document.createElement('div');
                        tempContainer.innerHTML = newMsgHtml;
                        var newMsgElement = tempContainer.firstChild;
                        msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                        setTimeout(loadLazyPreviews, 100);
                        logDebug('[EXECUTE_FILE_REPLACE] Message element updated');
                    } else {
                        logDebug('[EXECUTE_FILE_REPLACE] No message in response, reloading');
                        location.reload();
                    }
                } else {
                    logDebug('[EXECUTE_FILE_REPLACE] ERROR:', data.error);
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            } catch(e) {
                logDebug('[EXECUTE_FILE_REPLACE] JSON parse error:', e);
                showToast('Ошибка обработки ответа сервера', 'error');
            }
        } else {
            logDebug('[EXECUTE_FILE_REPLACE] HTTP error:', xhr.status);
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
        }
    };
    
    xhr.onerror = function() {
        logDebug('[EXECUTE_FILE_REPLACE] Network error');
        showToast('Сетевая ошибка', 'error');
    };
    
    xhr.ontimeout = function() {
        logDebug('[EXECUTE_FILE_REPLACE] Timeout');
        showToast('Превышено время ожидания', 'error');
    };
    
    xhr.send(formData);
}
// ==================== BLOCK END: executeFileReplace v8.16 ====================

// ==================== BLOCK START: executeFileDelete v8.16 (with logging) ====================
// ver.8.16: Добавлено подробное логирование

function executeFileDelete(messageUuid, fileUuid) {
    logDebug('[EXECUTE_FILE_DELETE] ========== START ==========');
    logDebug('[EXECUTE_FILE_DELETE] messageUuid:', messageUuid);
    logDebug('[EXECUTE_FILE_DELETE] fileUuid:', fileUuid);
    
    if (!messageUuid || !fileUuid) {
        logDebug('[EXECUTE_FILE_DELETE] ERROR: Missing parameters');
        showToast('Ошибка: не указаны параметры', 'error');
        return;
    }
    
    logDebug('[EXECUTE_FILE_DELETE] Sending delete request');
    showToast('🗑️ Удаление файла...', 'info');
    
    var formData = new URLSearchParams();
    formData.append('action', 'delete_message_file');
    formData.append('message_uuid', messageUuid);
    formData.append('file_uuid', fileUuid);
    addCsrfToUrlParams(formData);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        logDebug('[EXECUTE_FILE_DELETE] Response status:', xhr.status);
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                logDebug('[EXECUTE_FILE_DELETE] Response data:', data);
                if (data.success) {
                    logDebug('[EXECUTE_FILE_DELETE] SUCCESS! File deleted');
                    setTimeout(function() {
                        refreshPaginationAfterMutation();
                    }, 500);
                    
                    //showToast('✓ Файл удалён из сообщения', 'success');
                    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
                    if (msgElement && data.message) {
                        var newMsgHtml = renderMessage(data.message);
                        var tempContainer = document.createElement('div');
                        tempContainer.innerHTML = newMsgHtml;
                        var newMsgElement = tempContainer.firstChild;
                        msgElement.parentNode.replaceChild(newMsgElement, msgElement);
                        setTimeout(loadLazyPreviews, 100);
                        logDebug('[EXECUTE_FILE_DELETE] Message element updated');
                    } else if (msgElement) {
                        logDebug('[EXECUTE_FILE_DELETE] No message in response, reloading');
                        location.reload();
                    }
                } else {
                    logDebug('[EXECUTE_FILE_DELETE] ERROR:', data.error);
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            } catch(e) {
                logDebug('[EXECUTE_FILE_DELETE] JSON parse error:', e);
                showToast('Ошибка обработки ответа сервера', 'error');
            }
        } else {
            logDebug('[EXECUTE_FILE_DELETE] HTTP error:', xhr.status);
            showToast('Ошибка сервера (' + xhr.status + ')', 'error');
        }
    };
    
    xhr.onerror = function() {
        logDebug('[EXECUTE_FILE_DELETE] Network error');
        showToast('Сетевая ошибка', 'error');
    };
    
    xhr.ontimeout = function() {
        logDebug('[EXECUTE_FILE_DELETE] Timeout');
        showToast('Превышено время ожидания', 'error');
    };
    
    xhr.send(formData);
}
// ==================== BLOCK END: executeFileDelete v8.16 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: File handling v7.0 ====================
function handleFileSelect(e) {
    selectedFiles = selectedFiles.concat(Array.from(e.target.files));
    updateSelectedFiles();
    e.target.value = '';
}

function updateSelectedFiles() {
    var c = document.getElementById('selected-files');
    if(!c) return;
    c.innerHTML = selectedFiles.map(function(f,i){ return '<div class="selected-file">📎 '+escapeHtml(f.name)+' ('+formatFileSize(f.size)+') <span class="remove-file" onclick="removeFile('+i+')">✕</span></div>'; }).join('');
}

function removeFile(i) { selectedFiles.splice(i,1); updateSelectedFiles(); }

function copyFileLink(fileUuid) {
    var fullUrl = window.location.protocol + '//' + window.location.host + (window.APP_BASE || '') + '/file_preview.php?uuid=' + fileUuid;
    navigator.clipboard.writeText(fullUrl).then(function() {
        showToast('✓ Ссылка на файл скопирована', 'success');
    }).catch(function() {
        var textarea = document.createElement('textarea');
        textarea.value = fullUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('✓ Ссылка на файл скопирована', 'success');
    });
}

document.addEventListener('paste', function(e) {
    if (!window.currentTaskUuid) return;
    
    var items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (var i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            e.preventDefault();
            var blob = items[i].getAsFile();
            var now = new Date();
            var fileName = 'image_' + now.getFullYear() + String(now.getMonth()+1).padStart(2,'0') + String(now.getDate()).padStart(2,'0') + '_' + String(now.getHours()).padStart(2,'0') + String(now.getMinutes()).padStart(2,'0') + String(now.getSeconds()).padStart(2,'0') + '.png';
            var newFile = new File([blob], fileName, { type: blob.type });
            selectedFiles.push(newFile);
            updateSelectedFiles();
            //showToast('📸 Картинка из буфера добавлена', 'success');
            break;
        }
    }
});
// ==================== BLOCK END: File handling v7.0 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: loadLazyPreviews v7.4 (sequential loading from v6.7) ====================
// ver.7.4: ПОСЛЕДОВАТЕЛЬНАЯ загрузка для shared-хостинга (как в v6.7)
// - batchSize = 1 (только одно изображение за раз)
// - Задержка 500ms между загрузками (разгрузка сервера)
// - Повторные попытки при ошибках
// - Сброс очереди при 503

var lazyImageObserver = null;
var lazyImageQueue = [];
var isProcessingLazyQueue = false;
var currentLoadingImage = null;
var imageLoadRetryCount = 0;
var MAX_RETRIES = 3;
var LOAD_DELAY_MS = 500; // Задержка между загрузками (как в v6.7)

function loadLazyPreviews() {
    var lazyImages = document.querySelectorAll('.lazy-preview');
    if (!lazyImages.length) {
        logDebug('[PREVIEW] No lazy images found.');
        return;
    }
    logDebug('[PREVIEW] Starting lazy load for', lazyImages.length, 'images.');

    var imagesToLoad = [];
    for (var i = 0; i < lazyImages.length; i++) {
        var img = lazyImages[i];
        var hasDataSrc = img.dataset && img.dataset.src;
        var isLoaded = img.getAttribute('data-loaded') === 'true';
        
        if (hasDataSrc && !isLoaded) {
            imagesToLoad.push(img);
        }
    }
    
    if (imagesToLoad.length === 0) {
        logDebug('[PREVIEW] No images need loading');
        return;
    }

    if (window.IntersectionObserver && !lazyImageObserver) {
        lazyImageObserver = new IntersectionObserver(function(entries) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    var img = entries[i].target;
                    if (img.getAttribute('data-loaded') !== 'true') {
                        addToLazyQueue(img);
                    }
                    lazyImageObserver.unobserve(img);
                }
            }
        }, { rootMargin: '300px', threshold: 0.01 });
        
        for (var i = 0; i < imagesToLoad.length; i++) {
            lazyImageObserver.observe(imagesToLoad[i]);
        }
        logDebug('[PREVIEW] Using IntersectionObserver for', imagesToLoad.length, 'images');
    } else {
        for (var i = 0; i < imagesToLoad.length; i++) {
            addToLazyQueue(imagesToLoad[i]);
        }
        logDebug('[PREVIEW] Using queue processing for', imagesToLoad.length, 'images');
    }
}

// ==================== BLOCK START: File menu for images (long press / right click) v8.17 (fixed context capture) ====================
// ver.8.17: ИСПРАВЛЕН ЗАХВАТ КОНТЕКСТА - теперь сохраняем значения ДО закрытия меню
// - Короткий клик по любому файлу: открывает модальное окно через file_preview.js
// - Правый клик на любом файле: открывает меню
// - Долгий тап на любом файле: открывает меню
// - v8.9: Закрытие меню по клавише Escape
// - v8.9: На мобильных устройствах меню центрируется как модальное
// - v8.10: Удалены дублирующиеся функции
// - v8.11: Исправлена проверка isOwn при редактировании
// - v8.12: Исправлено копирование текста и операции с файлами
// - v8.13: Все операции используют контекст кликнутого файла
// - v8.14: Переиспользование существующих функций
// - v8.17: ИСПРАВЛЕН ЗАХВАТ КОНТЕКСТА - сохранение переменных перед closeFileMenu()

var currentFileMenuContext = {
    messageUuid: null,
    fileUuid: null,
    fileName: null,
    isOwn: false,
    messageText: null
};

// Глобальный обработчик Escape для закрытия меню
function handleFileMenuEscape(e) {
    if (e.key === 'Escape') {
        closeFileMenu();
    }
}

// ==================== BLOCK START: showFileMenu v8.34 (pure text without quotes) ====================
// ver.8.34: Исправлено копирование текста - используется чистый текст из data-original-text
// - Текст сообщения берётся из data-original-text (декодируется через decodeURIComponent)
// - При копировании передаётся только текст сообщения, без цитат и HTML

function showFileMenu(event, messageUuid, fileUuid, fileName, isOwn, messageText) {
    event.preventDefault();
    event.stopPropagation();
    
    logDebug('[FILE_MENU] ========== START ==========');
    logDebug('[FILE_MENU] Showing menu for file:', fileUuid);
    logDebug('[FILE_MENU] messageUuid:', messageUuid);
    logDebug('[FILE_MENU] fileName:', fileName);
    logDebug('[FILE_MENU] isOwn (passed):', isOwn);
    logDebug('[FILE_MENU] messageText (passed length):', messageText ? messageText.length : 0);
    
    // Закрываем меню сообщения, если открыто
    closeMessageMenu();
    
    // Получаем актуальный чистый текст сообщения из DOM (без цитат)
    var actualMessageText = '';
    var msgElement = document.querySelector('.message[data-uuid="' + messageUuid + '"]');
    
    if (msgElement) {
        // Пытаемся получить чистый текст из data-original-text (декодируем)
        var encodedText = msgElement.getAttribute('data-original-text');
        if (encodedText) {
            try {
                actualMessageText = decodeURIComponent(encodedText);
                logDebug('[FILE_MENU] Got pure text from data-original-text, length:', actualMessageText.length);
                logDebug('[FILE_MENU] Text preview:', actualMessageText.substring(0, 100));
            } catch(e) {
                logDebug('[FILE_MENU] decodeURIComponent failed, using raw:', e.message);
                actualMessageText = encodedText;
            }
        } else {
            logDebug('[FILE_MENU] No data-original-text attribute found');
        }
        
        // Проверяем и корректируем isOwn
        var actualIsOwn = msgElement.classList.contains('own');
        if (actualIsOwn !== isOwn) {
            logDebug('[FILE_MENU] isOwn corrected from', isOwn, 'to', actualIsOwn);
            isOwn = actualIsOwn;
        }
    } else {
        logDebug('[FILE_MENU] WARNING: Message element not found for UUID:', messageUuid);
    }
    
    // Если не получили текст из атрибута, используем переданный (но очищаем от цитат)
    if (!actualMessageText && messageText) {
        // Если переданный текст содержит HTML или цитаты, пробуем очистить
        if (messageText.indexOf('<') !== -1 || messageText.indexOf('&gt;') !== -1) {
            // Создаём временный элемент для очистки от HTML
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = messageText;
            // Удаляем цитаты если есть
            var quotes = tempDiv.querySelectorAll('.message-quote');
            quotes.forEach(function(quote) { quote.remove(); });
            actualMessageText = tempDiv.textContent || tempDiv.innerText || '';
            actualMessageText = actualMessageText.trim();
            logDebug('[FILE_MENU] Cleaned passed messageText from HTML, result length:', actualMessageText.length);
        } else {
            actualMessageText = messageText;
            logDebug('[FILE_MENU] Using passed messageText as is, length:', actualMessageText.length);
        }
    }
    
    // Финальная проверка: если текст всё ещё пустой, пробуем извлечь из DOM без цитат
    if (!actualMessageText && msgElement) {
        var textDiv = msgElement.querySelector('.message-text');
        if (textDiv) {
            var clone = textDiv.cloneNode(true);
            var quotes = clone.querySelectorAll('.message-quote');
            quotes.forEach(function(quote) { quote.remove(); });
            actualMessageText = clone.textContent || clone.innerText || '';
            actualMessageText = actualMessageText.trim();
            logDebug('[FILE_MENU] Extracted text from DOM without quotes, length:', actualMessageText.length);
        }
    }
    
    // СОХРАНЯЕМ КОНТЕКСТ (глобально для доступа из обработчиков)
    currentFileMenuContext = {
        messageUuid: messageUuid,
        fileUuid: fileUuid,
        fileName: fileName,
        isOwn: isOwn,
        messageText: actualMessageText  // Сохраняем ЧИСТЫЙ текст без цитат
    };
    
    // ДОПОЛНИТЕЛЬНО: сохраняем в window для резервного доступа
    window._currentFileUuid = fileUuid;
    window._currentMessageUuidForFile = messageUuid;
    window._currentMessageTextForFile = actualMessageText;
    
    logDebug('[FILE_MENU] Context saved, messageText length:', actualMessageText.length);
    
    // Создаём или получаем оверлей
    var overlay = document.getElementById('fileMenuOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'fileMenuOverlay';
        overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10001; display:none; align-items:center; justify-content:center;';
        overlay.onclick = function(e) { 
            if (e.target === overlay) closeFileMenu(); 
        };
        document.body.appendChild(overlay);
        
        var menu = document.createElement('div');
        menu.id = 'fileMenu';
        menu.className = 'message-menu';
        menu.style.cssText = 'background:#1e293b; border-radius:16px; padding:12px 0; min-width:220px; box-shadow:0 8px 30px rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.1); backdrop-filter:blur(10px);';
        menu.onclick = function(e) { 
            e.stopPropagation(); 
        };
        overlay.appendChild(menu);
    }
    
    var menu = document.getElementById('fileMenu');
    if (!menu) return;
    
    var html = '';
    
    if (currentFileMenuContext.isOwn) {
        html += '<div class="message-menu-item danger" id="fileMenuDeleteMsgBtn">🗑️ Удалить сообщение</div>';
        html += '<div class="message-menu-divider"></div>';
        html += '<div class="message-menu-item" id="fileMenuReplaceFileBtn">🔄 Заменить этот файл</div>';
        html += '<div class="message-menu-item danger" id="fileMenuDeleteFileBtn">🗑️ Удалить этот файл</div>';
        html += '<div class="message-menu-item" id="fileMenuAddFilesBtn">📎 Добавить другой файл</div>';
        html += '<div class="message-menu-divider"></div>';
    }
    
    html += '<div class="message-menu-item" id="fileMenuCopyLinkBtn">🔗 Копировать ссылку на файл</div>';
    html += '<div class="message-menu-item" id="fileMenuCopyTextBtn">📋 Копировать текст сообщения</div>';
    html += '<div class="message-menu-item" id="fileMenuReplyBtn">↩️ Ответить на сообщение</div>';
    
    if (currentFileMenuContext.isOwn) {
        html += '<div class="message-menu-divider"></div>';
        html += '<div class="message-menu-item" id="fileMenuEditMsgBtn">✏️ Редактировать сообщение</div>';
    }
    
    menu.innerHTML = html;
    
    // ========== НАЗНАЧАЕМ ОБРАБОТЧИКИ ==========
    // ВАЖНО: СОХРАНЯЕМ ЗНАЧЕНИЯ В ЛОКАЛЬНЫЕ ПЕРЕМЕННЫЕ ПЕРЕД closeFileMenu()
    
    if (currentFileMenuContext.isOwn) {
        // Удалить сообщение
        var delMsgUuid = currentFileMenuContext.messageUuid;
        var delMsgBtn = document.getElementById('fileMenuDeleteMsgBtn');
        if (delMsgBtn) delMsgBtn.onclick = function(e) { 
            e.stopPropagation(); 
            logDebug('[FILE_MENU] Delete message - UUID:', delMsgUuid);
            closeFileMenu();
            if (delMsgUuid && confirm('Удалить сообщение?')) {
                deleteMessage(delMsgUuid);
            }
        };
        
        // Заменить файл - сохраняем значения ДО закрытия меню
        var replaceMsgUuid = currentFileMenuContext.messageUuid;
        var replaceFileUuid = currentFileMenuContext.fileUuid;
        var replaceFileBtn = document.getElementById('fileMenuReplaceFileBtn');
        if (replaceFileBtn) replaceFileBtn.onclick = function(e) { 
            e.stopPropagation(); 
            logDebug('[FILE_MENU] Replace file - msg:', replaceMsgUuid, 'file:', replaceFileUuid);
            closeFileMenu();
            if (replaceMsgUuid && replaceFileUuid) {
                window.currentReplaceFileUuid = replaceFileUuid;
                window.currentReplaceMessageUuid = replaceMsgUuid;
                openFileReplaceDialog();
            } else {
                showToast('Ошибка: не удалось определить файл', 'error');
            }
        };
        
        // Удалить файл - сохраняем значения ДО закрытия меню
        var delFileMsgUuid = currentFileMenuContext.messageUuid;
        var delFileUuid = currentFileMenuContext.fileUuid;
        var deleteFileBtn = document.getElementById('fileMenuDeleteFileBtn');
        if (deleteFileBtn) deleteFileBtn.onclick = function(e) { 
            e.stopPropagation(); 
            logDebug('[FILE_MENU] Delete file - msg:', delFileMsgUuid, 'file:', delFileUuid);
            closeFileMenu();
            if (delFileMsgUuid && delFileUuid) {
                if (confirm('⚠️ Вы уверены, что хотите УДАЛИТЬ этот файл из сообщения?\n\nФайл будет полностью удалён с сервера.\n\nДействие необратимо!')) {
                    executeFileDelete(delFileMsgUuid, delFileUuid);
                }
            } else {
                showToast('Ошибка: не удалось определить файл', 'error');
            }
        };
        
        // Добавить файлы
        var addFilesMsgUuid = currentFileMenuContext.messageUuid;
        var addFilesBtn = document.getElementById('fileMenuAddFilesBtn');
        if (addFilesBtn) addFilesBtn.onclick = function(e) { 
            e.stopPropagation(); 
            logDebug('[FILE_MENU] Add files - msg:', addFilesMsgUuid);
            closeFileMenu();
            if (addFilesMsgUuid) {
                window._currentMessageUuid = addFilesMsgUuid;
                addFilesToMessage();
            } else {
                showToast('Ошибка: сообщение не найдено', 'error');
            }
        };
    }
    
    // Копировать ссылку на файл
    var copyLinkFileUuid = currentFileMenuContext.fileUuid;
    var copyLinkBtn = document.getElementById('fileMenuCopyLinkBtn');
    if (copyLinkBtn) copyLinkBtn.onclick = function(e) { 
        e.stopPropagation(); 
        logDebug('[FILE_MENU] Copy link - file:', copyLinkFileUuid);
        closeFileMenu();
        if (copyLinkFileUuid) {
            copyFileLink(copyLinkFileUuid);
        }
    };
    
    // Копировать текст - используем сохранённый ЧИСТЫЙ текст из currentFileMenuContext
    var copyText = currentFileMenuContext.messageText;
    var copyTextBtn = document.getElementById('fileMenuCopyTextBtn');
    if (copyTextBtn) copyTextBtn.onclick = function(e) { 
        e.stopPropagation(); 
        logDebug('[FILE_MENU] Copy text - length:', copyText ? copyText.length : 0);
        logDebug('[FILE_MENU] Copy text - preview:', copyText ? copyText.substring(0, 100) : '(empty)');
        closeFileMenu();
        if (copyText && copyText.length > 0) {
            // Устанавливаем глобальную переменную для copyMessageText
            window._currentMessageText = copyText;
            copyMessageText();
        } else {
            showToast('Нет текста для копирования', 'warning');
        }
    };
    
    // Ответить
    var replyMsgUuid = currentFileMenuContext.messageUuid;
    var replyBtn = document.getElementById('fileMenuReplyBtn');
    if (replyBtn) replyBtn.onclick = function(e) { 
        e.stopPropagation(); 
        logDebug('[FILE_MENU] Reply - msg:', replyMsgUuid);
        closeFileMenu();
        if (replyMsgUuid) {
            window._currentMessageUuid = replyMsgUuid;
            replyToCurrentMessage();
        } else {
            showToast('Ошибка: сообщение не найдено', 'error');
        }
    };
    
    if (currentFileMenuContext.isOwn) {
        // Редактировать сообщение
        var editMsgUuid = currentFileMenuContext.messageUuid;
        var editMsgBtn = document.getElementById('fileMenuEditMsgBtn');
        if (editMsgBtn) editMsgBtn.onclick = function(e) { 
            e.stopPropagation(); 
            logDebug('[FILE_MENU] Edit message - msg:', editMsgUuid);
            closeFileMenu();
            if (editMsgUuid) {
                window._currentMessageUuid = editMsgUuid;
                window._currentMessageOwn = true;
                editCurrentMessage();
            } else {
                showToast('Ошибка: сообщение не найдено', 'error');
            }
        };
    }
    
    // Определяем, мобильное ли устройство (ширина экрана <= 768px)
    var isMobile = window.innerWidth <= 768;
    
    if (isMobile) {
        menu.style.position = 'relative';
        menu.style.left = 'auto';
        menu.style.top = 'auto';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';
        menu.style.transform = 'none';
        overlay.style.display = 'flex';
        logDebug('[FILE_MENU] Mobile mode - centered modal');
    } else {
        var x = event.clientX;
        var y = event.clientY;
        
        if (x && y) {
            menu.style.position = 'fixed';
            menu.style.left = (x + 10) + 'px';
            menu.style.top = (y + 10) + 'px';
            menu.style.right = 'auto';
            menu.style.bottom = 'auto';
            menu.style.transform = 'none';
        } else {
            menu.style.position = 'fixed';
            menu.style.left = '50%';
            menu.style.top = '50%';
            menu.style.transform = 'translate(-50%, -50%)';
        }
        overlay.style.display = 'flex';
        logDebug('[FILE_MENU] Desktop mode - positioned at cursor');
    }
    
    window._fileMenuContext = currentFileMenuContext;
    document.addEventListener('keydown', handleFileMenuEscape);
    
    logDebug('[FILE_MENU] Menu shown successfully');
}
// ==================== BLOCK END: showFileMenu v8.34 ====================

function closeFileMenu() {
    var overlay = document.getElementById('fileMenuOverlay');
    if (overlay) overlay.style.display = 'none';
    currentFileMenuContext = { messageUuid: null, fileUuid: null, fileName: null, isOwn: false, messageText: null };
    window._fileMenuContext = null;
    document.removeEventListener('keydown', handleFileMenuEscape);
}
// ==================== BLOCK END: File menu for images v8.17 ====================




function addToLazyQueue(img) {
    for (var i = 0; i < lazyImageQueue.length; i++) {
        if (lazyImageQueue[i] === img) return;
    }
    
    if (img.getAttribute('data-loaded') === 'true') return;
    
    lazyImageQueue.push(img);
    logDebug('[PREVIEW] Added to queue, size:', lazyImageQueue.length);
    
    if (!isProcessingLazyQueue) {
        processLazyImageQueue();
    }
}

function processLazyImageQueue() {
    if (lazyImageQueue.length === 0) {
        isProcessingLazyQueue = false;
        currentLoadingImage = null;
        imageLoadRetryCount = 0;
        logDebug('[PREVIEW] Queue processing finished.');
        return;
    }
    
    if (isProcessingLazyQueue) return;
    
    isProcessingLazyQueue = true;
    
    // СТРОГО ПОСЛЕДОВАТЕЛЬНО: берём ТОЛЬКО ОДНО изображение
    var img = lazyImageQueue.shift();
    currentLoadingImage = img;
    
    if (!img || !img.dataset || !img.dataset.src) {
        logDebug('[PREVIEW] Invalid image, skipping');
        isProcessingLazyQueue = false;
        currentLoadingImage = null;
        setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
        return;
    }
    
    var dataSrc = img.dataset.src;
    logDebug('[PREVIEW] Loading image (sequential):', img.alt || 'image');
    
    // Добавляем timestamp для обхода кеша при проблемах
    var finalUrl = dataSrc;
    if (imageLoadRetryCount > 0) {
        finalUrl = dataSrc + (dataSrc.indexOf('?') === -1 ? '?' : '&') + 'retry=' + imageLoadRetryCount + '&_=' + Date.now();
    }
    
    img.src = finalUrl;
    
    var loadTimeout = setTimeout(function() {
        if (currentLoadingImage === img) {
            logDebug('[PREVIEW] Load timeout');
            if (imageLoadRetryCount < MAX_RETRIES) {
                imageLoadRetryCount++;
                lazyImageQueue.unshift(img);
                isProcessingLazyQueue = false;
                currentLoadingImage = null;
                setTimeout(processLazyImageQueue, LOAD_DELAY_MS * (imageLoadRetryCount + 1));
            } else {
                setImageError(img);
                isProcessingLazyQueue = false;
                currentLoadingImage = null;
                imageLoadRetryCount = 0;
                setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
            }
        }
    }, 30000);
    
    img.onload = function() {
        clearTimeout(loadTimeout);
        logDebug('[PREVIEW] Loaded:', img.alt || 'image');
        img.setAttribute('data-loaded', 'true');
        img.classList.remove('lazy-preview');
        imageLoadRetryCount = 0;
        currentLoadingImage = null;
        isProcessingLazyQueue = false;
        
        // Задержка перед следующей загрузкой (разгрузка сервера)
        setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
    };
    
    img.onerror = function() {
        clearTimeout(loadTimeout);
        logDebug('[PREVIEW] Error loading:', img.alt || 'image');
        
        if (imageLoadRetryCount < MAX_RETRIES) {
            imageLoadRetryCount++;
            lazyImageQueue.unshift(img);
            currentLoadingImage = null;
            isProcessingLazyQueue = false;
            var retryDelay = LOAD_DELAY_MS * Math.pow(2, imageLoadRetryCount);
            setTimeout(processLazyImageQueue, retryDelay);
        } else {
            setImageError(img);
            currentLoadingImage = null;
            isProcessingLazyQueue = false;
            imageLoadRetryCount = 0;
            setTimeout(processLazyImageQueue, LOAD_DELAY_MS);
        }
    };
}

function setImageError(img) {
    logDebug('[PREVIEW] Setting error state');
    var errorSvg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='150'%3E%3Crect fill='%23374151' width='200' height='150'/%3E%3Ctext x='50%25' y='50%25' text-anchor='middle' dy='.3em' fill='%239ca3af' font-size='12'%3EОшибка%3C/text%3E%3C/svg%3E";
    img.src = errorSvg;
    img.classList.add('preview-error');
    img.setAttribute('data-loaded', 'error');
}
// ==================== BLOCK END: loadLazyPreviews v7.4 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: loadTaskUsers and mentions v7.0 ====================
function loadTaskUsers(taskUuid) {
    if (!taskUuid) return Promise.resolve([]);
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_task_users');
    formData.append('task_uuid', taskUuid);
    addCsrfToUrlParams(formData);
    formData.append('ajax_mode', '1');
    
    return fetch(AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(function(response) {
        if (!response.ok) {
            if (response.status === 503) return [];
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(function(data) {
        if (data && data.success && data.users) {
            return data.users;
        }
        return [];
    })
    .catch(function(err) {
        logDebug('[MENTION] Error loading users: ' + err.message);
        return [];
    });
}

function filterUsersByQuery(query) {
    if (!query || query.length === 0) return taskUsersCache;
    var lowerQuery = query.toLowerCase();
    return taskUsersCache.filter(function(user) {
        return (user.name && user.name.toLowerCase().includes(lowerQuery)) ||
               (user.login && user.login.toLowerCase().includes(lowerQuery));
    }).slice(0, 10);
}

function setupMentionAutocomplete() {
    var textarea = document.getElementById('message-input');
    if (!textarea) return;
    
    textarea.addEventListener('input', function(e) {
        var caretPos = textarea.selectionStart;
        var textBeforeCaret = textarea.value.substring(0, caretPos);
        var lastAtIndex = textBeforeCaret.lastIndexOf('@');
        
        if (lastAtIndex !== -1 && (caretPos - lastAtIndex) <= 30) {
            var query = textBeforeCaret.substring(lastAtIndex + 1);
            var matchedUsers = filterUsersByQuery(query);
            
            if (matchedUsers.length > 0) {
                showMentionDropdown(matchedUsers, lastAtIndex);
            } else {
                hideMentionDropdown();
            }
            currentMentionQuery = query;
        } else {
            hideMentionDropdown();
            currentMentionQuery = '';
        }
    });
}

function showMentionDropdown(users, atPosition) {
    var dropdown = document.getElementById('mention-dropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'mention-dropdown';
        dropdown.style.cssText = 'position:absolute; background:#1e293b; border:1px solid #4f7cff; border-radius:8px; max-height:200px; overflow-y:auto; z-index:1000; min-width:180px; box-shadow:0 4px 12px rgba(0,0,0,0.3);';
        document.body.appendChild(dropdown);
    }
    
    dropdown.innerHTML = '';
    users.forEach(function(user) {
        var item = document.createElement('div');
        var roleIcon = user.role === 0 ? '👑 ' : '';
        var displayName = user.name ? roleIcon + user.name + ' (@' + user.login + ')' : roleIcon + '@' + user.login;
        item.textContent = displayName;
        item.style.cssText = 'padding:8px 12px; cursor:pointer; color:#e9eefc; border-bottom:1px solid #334155; transition:background 0.2s;';
        item.onmouseover = function() { item.style.background = '#2d3a5e'; };
        item.onmouseout = function() { item.style.background = ''; };
        item.onclick = function() { insertMention(user.login, atPosition); };
        dropdown.appendChild(item);
    });
    
    var textarea = document.getElementById('message-input');
    if (textarea) {
        var rect = textarea.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.bottom = (window.innerHeight - rect.top + 5) + 'px';
        dropdown.style.display = 'block';
    }
}

function hideMentionDropdown() {
    var dropdown = document.getElementById('mention-dropdown');
    if (dropdown) dropdown.style.display = 'none';
}

function insertMention(login, atPosition) {
    var textarea = document.getElementById('message-input');
    if (!textarea) return;
    
    var before = textarea.value.substring(0, atPosition);
    var after = textarea.value.substring(textarea.selectionStart);
    textarea.value = before + '@' + login + ' ' + after;
    textarea.focus();
    var newCursorPos = atPosition + login.length + 2;
    textarea.setSelectionRange(newCursorPos, newCursorPos);
    hideMentionDropdown();
    currentMentionQuery = '';
    textarea.dispatchEvent(new Event('input'));
}
// ==================== BLOCK END: loadTaskUsers and mentions v7.0 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: scrollToMessageByUuid v8.25 (full version) ====================
// ver.8.25 (2026-05-27) - ПОЛНАЯ ВЕРСИЯ ФУНКЦИИ ПРОКРУТКИ К СООБЩЕНИЮ ПО UUID
// - Поиск сообщения в DOM, если найдено — прокрутка и подсветка
// - Если не найдено — запрос к серверу для определения задачи и переключения
// ver.8.25 (2026-05-27) - ВОССТАНОВЛЕН ОБРАБОТЧИК КЛИКА ПО ЦИТАТАМ
// - При клике на .clickable-quote вызывает scrollToMessageByUuid
// - Добавлен повторный вызов после динамической загрузки сообщений (через MutationObserver)
function scrollToMessageByUuid(messageUuid, retryCount) {
    if (retryCount === undefined) retryCount = 0;
    
    logDebug('[SCROLL_TO_MSG] Called with UUID:', messageUuid, 'retryCount:', retryCount);
    
    var msgElement = document.getElementById('msg-' + messageUuid);
    if (msgElement) {
        logDebug('[SCROLL_TO_MSG] Message found in DOM, scrolling to it');
        msgElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        msgElement.classList.add('message-highlight');
        setTimeout(function() { 
            msgElement.classList.remove('message-highlight'); 
        }, 20000);
        return;
    }
    
    logDebug('[SCROLL_TO_MSG] Message not in DOM, fetching info from server');
    if (typeof showToast === 'function') {
        showToast('Загрузка сообщения...', 'info');
    }
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_message_info');
    formData.append('message_uuid', messageUuid);
    if (typeof addCsrfToUrlParams === 'function') {
        addCsrfToUrlParams(formData);
    }
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_BASE + '/messages.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                logDebug('[SCROLL_TO_MSG] Server response:', data.success ? 'success' : 'error', data.error || '');
                
                if (data.success && data.message) {
                    var targetTaskUuid = data.message.task_uuid;
                    logDebug('[SCROLL_TO_MSG] Message belongs to task:', targetTaskUuid);
                    logDebug('[SCROLL_TO_MSG] Current task:', window.currentTaskUuid);
                    
                    if (window.currentTaskUuid !== targetTaskUuid) {
                        var taskElement = document.querySelector('.task-item[data-task-uuid="' + targetTaskUuid + '"]');
                        if (taskElement) {
                            logDebug('[SCROLL_TO_MSG] Switching to different task:', targetTaskUuid);
                            if (typeof window.selectTask === 'function') {
                                window.selectTask(taskElement);
                            } else {
                                window.location.href = window.APP_BASE + '/messages.php?task=' + targetTaskUuid;
                            }
                            setTimeout(function() { 
                                scrollToMessageByUuid(messageUuid); 
                            }, 1000);
                        } else {
                            logDebug('[SCROLL_TO_MSG] Task not found in sidebar, redirecting to messages.php');
                            window.location.href = window.APP_BASE + '/messages.php?message=' + messageUuid;
                        }
                    } else {
                        logDebug('[SCROLL_TO_MSG] Same task, reloading messages and scrolling');
                        // Проверяем, не загружены ли уже сообщения этой задачи
                        var existingMessages = document.getElementById('messages-area');
                        if (existingMessages && existingMessages.children.length > 1) {
                            // Сообщения уже загружены, но нужного нет - возможно, не та страница
                            logDebug('[SCROLL_TO_MSG] Messages loaded but target message not found, loading specific page');
                        }
                        
                        if (typeof refreshPaginationAfterMutation === 'function') {
                            refreshPaginationAfterMutation();
                        } else if (typeof loadTaskLastPageSequential === 'function') {
                            loadTaskLastPageSequential(window.currentTaskUuid, function() {
                                setTimeout(function() {
                                    var el = document.getElementById('msg-' + messageUuid);
                                    if (el) {
                                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        el.classList.add('message-highlight');
                                    } else if (retryCount < 3) {
                                        logDebug('[SCROLL_TO_MSG] Message still not found, retry ' + (retryCount + 1));
                                        setTimeout(function() {
                                            scrollToMessageByUuid(messageUuid, retryCount + 1);
                                        }, 1000);
                                    }
                                }, 800);
                            });
                        } else {
                            location.reload();
                        }
                    }
                } else {
                    logDebug('[SCROLL_TO_MSG] Message not found on server');
                    if (typeof showToast === 'function') {
                        showToast('Сообщение не найдено', 'warning');
                    }
                }
            } catch(e) {
                logDebug('[SCROLL_TO_MSG] JSON parse error:', e.message);
            }
        } else {
            logDebug('[SCROLL_TO_MSG] HTTP error:', xhr.status);
        }
    };
    
    xhr.onerror = function() {
        logDebug('[SCROLL_TO_MSG] Network error');
    };
    
    xhr.send(formData);
}
// ==================== BLOCK END: scrollToMessageByUuid v8.33 ====================




function setupQuoteClickHandler() {
    var container = document.getElementById('messages-area');
    if (!container) {
        logDebug('[QUOTE] Container not found, retrying in 500ms');
        setTimeout(setupQuoteClickHandler, 500);
        return;
    }
    
    logDebug('[QUOTE] Setting up quote click handler on messages-area');
    
    container.addEventListener('click', function(e) {
        var quoteElement = e.target.closest('.clickable-quote');
        if (!quoteElement) return;
        
        var quoteUuid = quoteElement.getAttribute('data-quote-uuid');
        logDebug('[QUOTE] Clicked on quote, UUID:', quoteUuid);
        
        if (quoteUuid) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof scrollToMessageByUuid === 'function') {
                scrollToMessageByUuid(quoteUuid);
            } else {
                logDebug('[QUOTE] scrollToMessageByUuid not defined, falling back to location');
                window.location.href = window.APP_BASE + '/messages.php?message=' + encodeURIComponent(quoteUuid);
            }
        }
    });
    
    // Наблюдатель за добавлением новых сообщений
    if (window.MutationObserver && container.parentNode) {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length) {
                    for (var i = 0; i < mutation.addedNodes.length; i++) {
                        var node = mutation.addedNodes[i];
                        if (node.nodeType === 1 && (node.classList && node.classList.contains('clickable-quote'))) {
                            logDebug('[QUOTE] New quote element detected');
                        }
                    }
                }
            });
        });
        observer.observe(container, { childList: true, subtree: true });
    }
    
    logDebug('[QUOTE] Quote click handler initialized successfully');
}

// Запускаем после загрузки DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupQuoteClickHandler);
} else {
    setupQuoteClickHandler();
}
// ==================== BLOCK END: Quote click handler v8.25 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: SSE and global functions v7.0 ====================
window.appendNewMessages = function(messages, isNewMessageFromUser) {
    if (!messages || !messages.length) return;
    if (!window.location.pathname.endsWith('/messages.php')) return;
    
    // v3.15.0: Исключаем сообщения от текущего пользователя
    var filteredMessages = messages.filter(function(m) {
        return m.user_uuid !== window.currentUserUuid;
    });
    
    if (filteredMessages.length === 0) {
        logDebug('[APPEND_MSG] All messages are from current user, skipping');
        return;
    }
    
    var currentTask = document.getElementById('current-task-uuid').value;
    var taskFiltered = filteredMessages.filter(function(m) { return m.task_uuid === currentTask; });
    
    if (taskFiltered.length === 0) return;
    
    var container = document.getElementById('messages-area');
    if (!container) return;
    
    var emptyState = container.querySelector('.empty-state');
    if (emptyState) emptyState.remove();
    
    for (var i = 0; i < taskFiltered.length; i++) {
        if (!document.getElementById('msg-' + taskFiltered[i].uuid)) {
            container.insertAdjacentHTML('beforeend', renderMessage(taskFiltered[i]));
        }
    }
    
    var distToBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
    if (distToBottom < 500 || isNewMessageFromUser === true) {
        setTimeout(function() { container.scrollTop = container.scrollHeight; }, 100);
    }
    
    setTimeout(loadLazyPreviews, 300);
    updateUnreadBadge();
};

// ==================== BLOCK START: Auto-hide flash message on unread clear v1.0 ====================
// ver.1.0 (2026-06-11) - Автоматическое скрытие flash-сообщения о непрочитанных сообщениях
// - При получении события unread_update с count=0 скрываем flash-сообщение
// - При ручном прочтении всех сообщений также скрываем
// - Удаляем флаг из sessionStorage, чтобы сообщение не появлялось снова

(function() {
    'use strict';
    
    // Функция для скрытия flash-сообщения
    function hideUnreadFlashMessage() {
        var flashElement = document.querySelector('.flash-message, .custom-alert');
        if (flashElement && flashElement.textContent && flashElement.textContent.includes('непрочитанные сообщения')) {
            logDebug('[FLASH_AUTO_HIDE] Hiding unread flash message');
            flashElement.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(function() {
                if (flashElement && flashElement.parentNode) {
                    flashElement.remove();
                }
            }, 300);
        }
    }
    
    // Слушаем событие unread_update от SSE
    if (window.SSE && typeof window.SSE === 'object') {
        // Сохраняем оригинальный обработчик, если он есть
        var originalUnreadHandler = null;
        
        // Переопределяем или добавляем обработчик unread_update
        if (window.eventSource) {
            window.eventSource.addEventListener('unread_update', function(e) {
                try {
                    var data = JSON.parse(e.data);
                    logDebug('[FLASH_AUTO_HIDE] unread_update received, count:', data.count);
                    if (data.count === 0) {
                        hideUnreadFlashMessage();
                        // Также очищаем флаг в sessionStorage, чтобы сообщение не появилось при следующей загрузке
                        sessionStorage.removeItem('flash_unread_shown');
                    }
                } catch(err) {
                    logDebug('[FLASH_AUTO_HIDE] Error parsing unread_update:', err);
                }
            });
        }
    }
    
    // Также проверяем при загрузке страницы, если нет непрочитанных - скрываем сообщение
    function checkAndHideFlashOnLoad() {
        var csrfToken = window.csrfToken || '';
        fetch(window.APP_BASE + '/get_badges_data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.badges) {
                var unreadMessages = data.badges.messages || 0;
                logDebug('[FLASH_AUTO_HIDE] On load - unread messages:', unreadMessages);
                if (unreadMessages === 0) {
                    hideUnreadFlashMessage();
                }
            }
        })
        .catch(function(err) {
            logDebug('[FLASH_AUTO_HIDE] Error checking unread count:', err);
        });
    }
    
    // Запускаем проверку после загрузки DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(checkAndHideFlashOnLoad, 1000);
        });
    } else {
        setTimeout(checkAndHideFlashOnLoad, 1000);
    }
    
    // Также при возвращении на вкладку проверяем
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            setTimeout(checkAndHideFlashOnLoad, 500);
        }
    });
})();
// ==================== BLOCK END: Auto-hide flash message on unread clear v1.0 ====================

window.updateMessageInCache = function(uuid, newData) {
    var el = document.querySelector('.message[data-uuid="' + uuid + '"]');
    if (el && newData) {
        var newHtml = renderMessage(newData);
        var temp = document.createElement('div');
        temp.innerHTML = newHtml;
        el.parentNode.replaceChild(temp.firstChild, el);
        setTimeout(loadLazyPreviews, 100);
    }
};

window.deleteMessageFromCache = function(uuid) {
    var el = document.querySelector('.message[data-uuid="' + uuid + '"]');
    if (el) {
        el.style.transition = 'opacity 0.3s ease';
        el.style.opacity = '0';
        setTimeout(function() {
            if (el && el.parentNode) el.remove();
            var container = document.getElementById('messages-area');
            if (container && !container.querySelector('.message')) {
                container.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
            }
            updateUnreadBadge();
        }, 300);
    }
};

window.refreshCurrentTaskMessages = function() {
    if (window.currentTaskUuid) {
        window.lastMessageTime = 0;
        loadMessagesPage(0, { scrollToBottom: true });
    }
};

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var overlay = document.getElementById('messageMenuOverlay');
        if (overlay && overlay.classList.contains('show')) {
            e.preventDefault();
            e.stopPropagation();
            closeMessageMenu();
        }
    }
});
// ==================== BLOCK END: SSE and global functions v7.0 ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
document.addEventListener('DOMContentLoaded', function() {
    logDebug('[DOM] DOMContentLoaded v8.1');
    
    function waitForElement(selector, callback, maxAttempts = 20, interval = 100) {
        var attempts = 0;
        var element = document.getElementById(selector) || document.querySelector(selector);
        if (element) {
            callback(element);
            return;
        }
        
        var checkInterval = setInterval(function() {
            attempts++;
            var el = document.getElementById(selector) || document.querySelector(selector);
            if (el) {
                clearInterval(checkInterval);
                callback(el);
            } else if (attempts >= maxAttempts) {
                clearInterval(checkInterval);
                logDebug('[DOM] Element not found after ' + maxAttempts + ' attempts:', selector);
            }
        }, interval);
    }
    
    // Получаем элемент messages-area
    var messagesArea = document.getElementById('messages-area');
    var scrollStopTimeout;
    
    // Настройка обработчиков событий для messages-area
    if (messagesArea) {
        // Основной обработчик скролла (пагинация)
        messagesArea.addEventListener('scroll', onMessagesScroll);
        
        // Обработчик для отслеживания активности пользователя
        messagesArea.addEventListener('scroll', function() {
            window.userIsScrolling = true;
            if (userScrollTimeout) clearTimeout(userScrollTimeout);
            userScrollTimeout = setTimeout(function() { 
                window.userIsScrolling = false;
                logDebug('[SCROLL] userIsScrolling reset to false');
            }, 1500);
        });
        
        // Обработчик остановки скролла (дополнительная проверка низа)
        messagesArea.addEventListener('scroll', function() {
            if (scrollStopTimeout) clearTimeout(scrollStopTimeout);
            scrollStopTimeout = setTimeout(function() {
                if (!window.pagination.isLoading && window.pagination.hasNewer) {
                    var container = document.getElementById('messages-area');
                    if (container) {
                        var scrollTop = container.scrollTop;
                        var scrollHeight = container.scrollHeight;
                        var clientHeight = container.clientHeight;
                        var distanceToBottom = scrollHeight - (scrollTop + clientHeight);
                        
                        // Если после остановки мы всё ещё внизу - загружаем новые сообщения
                        if (distanceToBottom <= 150 && window.pagination.hasNewer) {
                            logDebug('[SCROLL] After stop, bottom detected, loading newer messages');
                            loadNewerMessages();
                        }
                    }
                }
            }, 300);
        });
        
        logDebug('[DOM] messages-area scroll listeners attached');
    } else {
        waitForElement('messages-area', function(el) {
            el.addEventListener('scroll', onMessagesScroll);
            el.addEventListener('scroll', function() {
                window.userIsScrolling = true;
                if (userScrollTimeout) clearTimeout(userScrollTimeout);
                userScrollTimeout = setTimeout(function() { 
                    window.userIsScrolling = false;
                    logDebug('[SCROLL] userIsScrolling reset to false');
                }, 1500);
            });
            el.addEventListener('scroll', function() {
                if (scrollStopTimeout) clearTimeout(scrollStopTimeout);
                scrollStopTimeout = setTimeout(function() {
                    if (!window.pagination.isLoading && window.pagination.hasNewer) {
                        var container = document.getElementById('messages-area');
                        if (container) {
                            var scrollTop = container.scrollTop;
                            var scrollHeight = container.scrollHeight;
                            var clientHeight = container.clientHeight;
                            var distanceToBottom = scrollHeight - (scrollTop + clientHeight);
                            if (distanceToBottom <= 150 && window.pagination.hasNewer) {
                                logDebug('[SCROLL] After stop, bottom detected, loading newer messages');
                                loadNewerMessages();
                            }
                        }
                    }
                }, 300);
            });
        });
    }
    
    document.querySelectorAll('.task-item').forEach(function(t) {
        t.addEventListener('click', function(e) {
            e.stopPropagation();
            selectTask(this);
        });
    });
    
    document.querySelectorAll('.project-header').forEach(function(h) {
        h.addEventListener('click', function(e) {
            e.stopPropagation();
            var projectItem = this.parentNode;
            var tasksList = projectItem.querySelector('.tasks-list');
            
            if (tasksList) {
                tasksList.classList.toggle('active');
                
                if (tasksList.classList.contains('active')) {
                    var activeTask = tasksList.querySelector('.task-item.active');
                    if (activeTask) {
                        setTimeout(function() {
                            activeTask.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center',
                                inline: 'nearest'
                            });
                        }, 100);
                    } else {
                        var firstTask = tasksList.querySelector('.task-item');
                        if (firstTask) {
                            setTimeout(function() {
                                firstTask.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'center',
                                    inline: 'nearest'
                                });
                            }, 100);
                        }
                    }
                }
            }
        });
    });
    
    var form = document.getElementById('message-form');
    if (form) form.addEventListener('submit', sendMessage);
    
    var fi = document.getElementById('file-input');
    if (fi) fi.addEventListener('change', handleFileSelect);
    
    var ta = document.getElementById('message-input');
    if (ta) {
        ta.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });
        ta.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
        });
        setupMentionAutocomplete();
    }
    
    var cancelReplyBtn = document.getElementById('cancel-reply-btn');
    if (cancelReplyBtn) cancelReplyBtn.addEventListener('click', cancelReply);
    
    var urlParams = new URLSearchParams(window.location.search);
    var taskParam = urlParams.get('task');
    var messageParam = urlParams.get('message');
    
    logDebug('[DOM] URL params - task:', taskParam, 'message:', messageParam);
    logDebug('[DOM] window.currentTaskUuid:', window.currentTaskUuid);
    
    // ==================== BLOCK START: Ensure messages-area-inner container v8.51 ====================
    // v8.51: Создаём внутренний контейнер для скролла, если его нет
    var messagesAreaOuter = document.getElementById('messages-area');
    if (messagesAreaOuter && !messagesAreaOuter.querySelector('.messages-area-inner')) {
        logDebug('[DOM] Creating messages-area-inner container');
        var innerDiv = document.createElement('div');
        innerDiv.className = 'messages-area-inner';
        innerDiv.id = 'messages-area-inner';
        // Переносим всё содержимое во внутренний контейнер
        while (messagesAreaOuter.firstChild) {
            innerDiv.appendChild(messagesAreaOuter.firstChild);
        }
        messagesAreaOuter.appendChild(innerDiv);
        logDebug('[DOM] messages-area-inner created and content moved');
    } else if (messagesAreaOuter) {
        logDebug('[DOM] messages-area-inner already exists');
    }
    // ==================== BLOCK END: Ensure messages-area-inner container v8.51 ====================

    function setCurrentTaskUuid(value) {
        window.currentTaskUuid = value;
        var input = document.getElementById('current-task-uuid');
        if (input) {
            input.value = value;
            logDebug('[DOM] Set current-task-uuid to:', value);
        }
    }
    
    // ==================== BLOCK START: updateChatHeaderForTask v1.4 ====================
    // ver.1.4 (2026-06-05) - Исправлено: теперь активирует задачу в ОБОИХ сайдбарах

    function updateChatHeaderForTask(taskUuid) {
        // Поиск в десктопном сайдбаре
        var desktopTaskElement = document.querySelector('.messenger-sidebar .task-item[data-task-uuid="' + taskUuid + '"]');
        
        // Поиск в мобильном сайдбаре
        var mobileTaskElement = document.getElementById('mobile-drawer-panel') 
            ? document.querySelector('#mobile-drawer-panel .task-item[data-task-uuid="' + taskUuid + '"]')
            : null;
        
        // Используем любой найденный элемент для получения данных
        var taskElement = desktopTaskElement || mobileTaskElement;
        
        if (taskElement) {
            logDebug('[DOM] Found task element for UUID:', taskUuid);
            
            var taskTitle = taskElement.querySelector('.task-title').textContent;
            var chatTitle = document.getElementById('chat-title');
            if (chatTitle) {
                chatTitle.textContent = taskTitle;
                chatTitle.href = window.APP_BASE + '/projects.php?task=' + taskUuid;
                logDebug('[DOM] Chat header updated to:', taskTitle);
            }
            
            var taskFilesLink = document.getElementById('task-files-link');
            if (taskFilesLink) {
                taskFilesLink.href = window.APP_BASE + '/files.php?task=' + taskUuid;
                taskFilesLink.style.display = 'inline-flex';
            }
            
            var chatSubtitle = document.getElementById('chat-subtitle');
            if (chatSubtitle) chatSubtitle.textContent = 'Обсуждение задачи';
            
            var inputArea = document.getElementById('input-area');
            if (inputArea) inputArea.style.display = 'block';
            
            // Активируем задачу в ДЕСКТОПНОМ сайдбаре
            if (desktopTaskElement) {
                document.querySelectorAll('.messenger-sidebar .task-item').forEach(function(t) {
                    t.classList.remove('active');
                });
                desktopTaskElement.classList.add('active');
                logDebug('[DOM] Added active class to desktop task');
            }
            
            // Активируем задачу в МОБИЛЬНОМ сайдбаре
            if (mobileTaskElement) {
                var mobileTasksContainer = document.getElementById('mobile-drawer-panel');
                if (mobileTasksContainer) {
                    mobileTasksContainer.querySelectorAll('.task-item').forEach(function(t) {
                        t.classList.remove('active');
                    });
                    mobileTaskElement.classList.add('active');
                    logDebug('[DOM] Added active class to mobile task');
                }
            }
            
            // Открываем проект и список задач, если нужно
            var projectItem = taskElement.closest('.project-item');
            if (projectItem) {
                var tasksList = projectItem.querySelector('.tasks-list');
                if (tasksList && !tasksList.classList.contains('active')) {
                    tasksList.classList.add('active');
                    logDebug('[DOM] Expanded tasks list for project');
                }
            }
        } else {
            logDebug('[DOM] Task element NOT found for UUID:', taskUuid);
            // Повторная попытка через 500ms (если DOM ещё не полностью загружен)
            setTimeout(function() {
                var retryElement = document.querySelector('.task-item[data-task-uuid="' + taskUuid + '"]');
                if (retryElement) {
                    logDebug('[DOM] Retry: found task element for UUID:', taskUuid);
                    updateChatHeaderForTask(taskUuid);
                }
            }, 500);
        }
    }
    // ==================== BLOCK END: updateChatHeaderForTask v1.4 ====================
    
    // ========== ОСНОВНАЯ ЛОГИКА ЗАГРУЗКИ ==========
    
    if (taskParam && !messageParam) {
        logDebug('[DOM] Processing task parameter (will load LAST page):', taskParam);
        
        // v8.50: Добавляем класс loading для стабилизации высоты
        var messagesAreaElement = document.getElementById('messages-area');
        if (messagesAreaElement) {
            messagesAreaElement.classList.add('loading');
            logDebug('[DOM] Added loading class to messages-area');
        }
        
        setCurrentTaskUuid(taskParam);
        
        // Сначала обновляем заголовок (из DOM, если задача уже есть)
        updateChatHeaderForTask(taskParam);
        
        // Очищаем сообщения и показываем индикатор загрузки
        var container = document.getElementById('messages-area');
        if (container) {
            container.innerHTML = '<div class="loading-messages">⏳ Загрузка последних сообщений...</div>';
        }
        
        // Сбрасываем состояние пагинации перед загрузкой с поддержкой кеша страниц
        window.pagination = {
            currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE, totalMessages: 0,
            isLoading: false, hasOlder: false, hasNewer: false,
            lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
            consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
            prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: true,
            pageCache: {},
            currentWindowPage: 0,
            windowStartPage: 0,
            windowEndPage: 0
        };
        
        // ПОСЛЕДОВАТЕЛЬНАЯ ЗАГРУЗКА: init -> users -> last page
        enqueueRequest('initTask_' + taskParam, function(done) {
            initTaskSequential(taskParam, done);
        });
        
        enqueueRequest('loadTaskUsers_' + taskParam, function(done) {
            loadTaskUsersSequential(taskParam, function() { done(); });
        });
        
        enqueueRequest('loadMessages_lastpage_' + taskParam, function(done) {
            loadTaskLastPageSequential(taskParam, function() {
                logDebug('[DOM] Last page loaded');
                
                // v8.50: Убираем класс loading после загрузки
                setTimeout(function() {
                    var msgsArea = document.getElementById('messages-area');
                    if (msgsArea) {
                        msgsArea.classList.remove('loading');
                        msgsArea.classList.add('loaded');
                        logDebug('[DOM] Removed loading class from messages-area, added loaded class');
                    }
                }, 500);
                
                // v8.48: НА ДЕСКТОПЕ НЕ ДЕЛАЕМ МНОЖЕСТВЕННЫЕ ПРОКРУТКИ
                // Прокрутка уже выполнена в renderWindowPages (один раз, с блокировкой body)
                var isDesktop = window.innerWidth > 768;
                
                if (!isDesktop) {
                    // Только для мобильных - множественные попытки (для надёжности)
                    var scrollAttempts = [100, 300, 700, 1500, 2500];
                    for (var a = 0; a < scrollAttempts.length; a++) {
                        (function(delay) {
                            setTimeout(function() {
                                var msgsArea = document.getElementById('messages-area');
                                if (msgsArea && !window.userIsScrolling) {
                                    msgsArea.scrollTop = msgsArea.scrollHeight;
                                    logDebug('[DOM] Mobile scroll attempt ' + delay + 'ms: scrollTop=' + msgsArea.scrollTop);
                                }
                            }, delay);
                        })(scrollAttempts[a]);
                    }
                } else {
                    logDebug('[DOM] Desktop: no additional scroll attempts (renderWindowPages already handled it)');
                }
                
                // v8.9: Добавляем спейсер для мобильных устройств после загрузки
                setTimeout(function() {
                    applyStaticMobileSpacer();
                }, 1000);
                
                done();
            });
        });
        
        setTimeout(function() { scrollSidebarToActiveTask(); }, 500);
        
// ==================== BLOCK START: DOMContentLoaded messageParam handling v1.4 (FIXED) ====================
// ver.1.3 (2026-06-02) - ДОБАВЛЕН ЯВНЫЙ СКРОЛЛ САЙДБАРА К АКТИВНОЙ ЗАДАЧЕ
// ver.1.4 (2026-06-05) - ИСПРАВЛЕНИЕ: ЗАГРУЗКА СООБЩЕНИЙ ПРИ ОТКРЫТИИ ЧЕРЕЗ ?message=
// - После определения задачи, инициализируется пагинация и загружаются сообщения
// - Добавлено подробное логирование

    } else if (messageParam) {
        logDebug('[DOM] Processing message parameter:', messageParam);
        var formData = new URLSearchParams();
        formData.append('action', 'get_message_info');
        formData.append('message_uuid', messageParam);
        if (typeof addCsrfToUrlParams === 'function') {
            addCsrfToUrlParams(formData);
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.message) {
                        var targetTaskUuid = data.message.task_uuid;
                        logDebug('[DOM] Found task for message:', targetTaskUuid);
                        
                        // ========== v1.4: ЗАГРУЖАЕМ СООБЩЕНИЯ ЗАДАЧИ ==========
                        setCurrentTaskUuid(targetTaskUuid);
                        updateChatHeaderForTask(targetTaskUuid);
                        
                        // Сбрасываем состояние пагинации
                        window.pagination = {
                            currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE, totalMessages: 0,
                            isLoading: false, hasOlder: false, hasNewer: false,
                            lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
                            consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
                            prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: true,
                            pageCache: {},
                            currentWindowPage: 0,
                            windowStartPage: 0,
                            windowEndPage: 0
                        };
                        window._pendingLoadParams = null;
                        
                        // Показываем индикатор загрузки
                        var container = document.getElementById('messages-area');
                        if (container) {
                            container.innerHTML = '<div class="loading-messages">⏳ Загрузка сообщений...</div>';
                        }
                        logDebug('[DOM] Pagination reset, loading messages for task:', targetTaskUuid);
                        
                        // Последовательная загрузка: init -> users -> last page
                        enqueueRequest('initTask_' + targetTaskUuid, function(done) {
                            initTaskSequential(targetTaskUuid, done);
                        });
                        
                        enqueueRequest('loadTaskUsers_' + targetTaskUuid, function(done) {
                            loadTaskUsersSequential(targetTaskUuid, function() { done(); });
                        });
                        
                        enqueueRequest('loadMessages_lastpage_' + targetTaskUuid, function(done) {
                            logDebug('[DOM] Loading last page for task:', targetTaskUuid);
                            loadTaskLastPageSequential(targetTaskUuid, function() {
                                logDebug('[DOM] Last page loaded, will scroll to message');
                                // Прокрутка к нужному сообщению после загрузки
                                setTimeout(function() {
                                    scrollToMessageByUuid(messageParam);
                                }, 800);
                                done();
                            });
                        });
                        
                        // Прокрутка сайдбара к активной задаче
                        setTimeout(function() {
                            scrollSidebarToActiveTask();
                        }, 400);
                        
                    } else {
                        logDebug('[DOM] Message not found or no access:', data.error);
                        if (typeof showToast === 'function') {
                            showToast('Сообщение не найдено или нет доступа', 'warning');
                        }
                    }
                } catch(e) {
                    logDebug('[DOM] Error getting message info:', e);
                }
            } else {
                logDebug('[DOM] HTTP error getting message info:', xhr.status);
            }
        };
        xhr.onerror = function() {
            logDebug('[DOM] Network error getting message info');
        };
        xhr.send(formData);
    }
// ==================== BLOCK END: DOMContentLoaded messageParam handling v1.4 ====================
            
    else if (window.currentTaskUuid && window.currentTaskUuid !== '') {
        logDebug('[DOM] Using existing currentTaskUuid (will load LAST page):', window.currentTaskUuid);
        
        // v8.50: Добавляем класс loading для стабилизации высоты
        var messagesAreaElement = document.getElementById('messages-area');
        if (messagesAreaElement) {
            messagesAreaElement.classList.add('loading');
            logDebug('[DOM] Added loading class to messages-area');
        }
        
        setCurrentTaskUuid(window.currentTaskUuid);
        
        updateChatHeaderForTask(window.currentTaskUuid);
        
        // ========== ДОБАВИТЬ ЭТИ СТРОКИ ==========
        setTimeout(function() {
            scrollSidebarToActiveTask();
        }, 500);
        // ========================================
        
        var container = document.getElementById('messages-area');
        if (container) {
            container.innerHTML = '<div class="loading-messages">⏳ Загрузка последних сообщений...</div>';
        }
        
        // Сбрасываем состояние пагинации с поддержкой кеша страниц
        window.pagination = {
            currentPage: 0, totalPages: 0, messagesPerPage: window.MESSAGES_PER_PAGE, totalMessages: 0,
            isLoading: false, hasOlder: false, hasNewer: false,
            lastScrollTop: 0, lastScrollTime: 0, scrollDirection: null,
            consecutiveScrolls: { up: 0, down: 0 }, retryCount: 0, maxRetries: 3,
            prefetchNext: true, lastRequestTime: 0, lastRequestHash: null, initializing: true,
            pageCache: {},
            currentWindowPage: 0,
            windowStartPage: 0,
            windowEndPage: 0
        };
        
        enqueueRequest('initTask_' + window.currentTaskUuid, function(done) {
            initTaskSequential(window.currentTaskUuid, done);
        });
        
        enqueueRequest('loadTaskUsers_' + window.currentTaskUuid, function(done) {
            loadTaskUsersSequential(window.currentTaskUuid, function() { done(); });
        });
        
        enqueueRequest('loadMessages_lastpage_' + window.currentTaskUuid, function(done) {
            loadTaskLastPageSequential(window.currentTaskUuid, function() {
                logDebug('[DOM] Last page loaded');
                
                // v8.50: Убираем класс loading после загрузки
                setTimeout(function() {
                    var msgsArea = document.getElementById('messages-area');
                    if (msgsArea) {
                        msgsArea.classList.remove('loading');
                        msgsArea.classList.add('loaded');
                        logDebug('[DOM] Removed loading class from messages-area, added loaded class');
                    }
                }, 500);
                
                var isDesktop = window.innerWidth > 768;
                
                if (!isDesktop) {
                    var scrollAttempts = [100, 300, 700, 1500, 2500];
                    for (var a = 0; a < scrollAttempts.length; a++) {
                        (function(delay) {
                            setTimeout(function() {
                                var msgsArea = document.getElementById('messages-area');
                                if (msgsArea && !window.userIsScrolling) {
                                    msgsArea.scrollTop = msgsArea.scrollHeight;
                                    logDebug('[DOM] Mobile scroll attempt ' + delay + 'ms: scrollTop=' + msgsArea.scrollTop);
                                }
                            }, delay);
                        })(scrollAttempts[a]);
                    }
                } else {
                    logDebug('[DOM] Desktop: no additional scroll attempts');
                }
                
                // v8.9: Добавляем спейсер для мобильных устройств после загрузки
                setTimeout(function() {
                    applyStaticMobileSpacer();
                }, 1000);
                
                done();
            });
        });
    }
    
    if (typeof window.scrollToMessageUuid !== 'undefined' && window.scrollToMessageUuid) {
        logDebug('[DOM] Will scroll to message:', window.scrollToMessageUuid);
        setTimeout(function() { scrollToMessageByUuid(window.scrollToMessageUuid); }, 1500);
    }


    // ==================== BLOCK START: File long press and right click handlers v8.18 (fixed click on mobile) ====================
    // ver.8.18: ИСПРАВЛЕНА ПРОБЛЕМА - на мобильных устройствах короткий клик не открывал предпросмотр
    // - В touchend проверяем, был ли это короткий тап (менее 300ms, без значительного движения)
    // - При коротком тапе вручную вызываем showFilePreview()
    // - Добавлено подробное логирование
    function setupFileEventDelegation() {
        var messagesArea = document.getElementById('messages-area');
        if (!messagesArea) {
            setTimeout(setupFileEventDelegation, 500);
            return;
        }
        
        var longPressTimer = null;
        var longPressTargetElement = null;
        var longPressStartX = 0, longPressStartY = 0;
        var touchStartTime = 0;
        var LONG_PRESS_DURATION = 500;
        var MAX_TAP_MOVEMENT = 15; // Максимальное движение для короткого тапа (пикселей)
        
        // Проверка, является ли элемент файлом (или находится внутри файлового элемента)
        function isFileElement(target) {
            // Прямая проверка классов
            if (target.classList) {
                if (target.classList.contains('file-image-wrapper') ||
                    target.classList.contains('file-preview-thumb') ||
                    target.classList.contains('file-item')) {
                    return true;
                }
            }
            // Проверка родительских элементов
            var parent = target.parentElement;
            while (parent && parent !== messagesArea) {
                if (parent.classList && (
                    parent.classList.contains('file-image-wrapper') ||
                    parent.classList.contains('file-preview-thumb') ||
                    parent.classList.contains('file-item')
                )) {
                    return true;
                }
                parent = parent.parentElement;
            }
            return false;
        }
        
        // Получение корневого файлового элемента
        function getRootFileElement(target) {
            if (target.classList && target.classList.contains('file-image-wrapper')) return target;
            if (target.classList && target.classList.contains('file-preview-thumb')) return target;
            if (target.classList && target.classList.contains('file-item')) return target;
            
            var parent = target.parentElement;
            while (parent && parent !== messagesArea) {
                if (parent.classList && parent.classList.contains('file-image-wrapper')) return parent;
                if (parent.classList && parent.classList.contains('file-preview-thumb')) return parent;
                if (parent.classList && parent.classList.contains('file-item')) return parent;
                parent = parent.parentElement;
            }
            return null;
        }
        
        // Извлечение данных из файлового элемента для предпросмотра
        function getFileDataForPreview(fileEl) {
            var fileUuid = null;
            var fileName = 'Файл';
            var fileSize = 0;
            var fileMime = '';
            
            // Пытаемся получить данные из атрибутов file-image-wrapper
            if (fileEl.classList.contains('file-image-wrapper')) {
                fileUuid = fileEl.getAttribute('data-file-uuid');
                fileName = fileEl.getAttribute('data-file-name') || 'Файл';
            } else {
                // Ищем ближайший file-image-wrapper
                var wrapper = fileEl.closest('.file-image-wrapper');
                if (wrapper) {
                    fileUuid = wrapper.getAttribute('data-file-uuid');
                    fileName = wrapper.getAttribute('data-file-name') || 'Файл';
                } else {
                    // Ищем через onclick атрибут
                    var clickAttr = fileEl.getAttribute('onclick');
                    if (!clickAttr) {
                        var childWithClick = fileEl.querySelector('[onclick*="showFilePreview"]');
                        if (childWithClick) clickAttr = childWithClick.getAttribute('onclick');
                    }
                    if (clickAttr) {
                        var match = clickAttr.match(/showFilePreview\('([^']+)',\s*'([^']*)',\s*(\d+),\s*'([^']*)'/);
                        if (match) {
                            fileUuid = match[1];
                            fileName = match[2] || 'Файл';
                            fileSize = parseInt(match[3], 10) || 0;
                            fileMime = match[4] || '';
                        } else {
                            var simpleMatch = clickAttr.match(/showFilePreview\('([^']+)'/);
                            if (simpleMatch) fileUuid = simpleMatch[1];
                        }
                    }
                    if (!fileUuid) {
                        var fileLink = fileEl.querySelector('a') || fileEl;
                        if (fileLink && fileLink.href) {
                            var hrefMatch = fileLink.href.match(/file=([a-f0-9-]+)/i);
                            if (hrefMatch) fileUuid = hrefMatch[1];
                        }
                    }
                }
            }
            
            return { fileUuid: fileUuid, fileName: fileName, fileSize: fileSize, fileMime: fileMime };
        }
        
        // Извлечение данных из файлового элемента для меню
        // В функции getFileDataFromFileElement (в блоке file menu)
        function getFileDataFromFileElement(fileEl) {
            var messageUuid = null;
            var fileUuid = null;
            var isOwn = false;
            var fileName = 'Файл';
            var messageText = '';
            
            // Функция для декодирования текста из URI-компонента
            function decodeMessageText(encodedText) {
                if (!encodedText) return '';
                try {
                    return decodeURIComponent(encodedText);
                } catch(e) {
                    // Если decodeURIComponent не сработал, пробуем как есть
                    return encodedText;
                }
            }
            
            // Пытаемся получить данные из атрибутов file-image-wrapper
            if (fileEl.classList.contains('file-image-wrapper')) {
                messageUuid = fileEl.getAttribute('data-msg-uuid');
                fileUuid = fileEl.getAttribute('data-file-uuid');
                isOwn = fileEl.getAttribute('data-is-own') === 'true';
                fileName = fileEl.getAttribute('data-file-name') || 'Файл';
                // Декодируем текст сообщения из атрибута
                var encodedText = fileEl.getAttribute('data-message-text') || '';
                messageText = decodeMessageText(encodedText);
                logDebug('[FILE_DATA] Got text from file-image-wrapper, length:', messageText.length);
            } else {
                // Ищем ближайший file-image-wrapper
                var wrapper = fileEl.closest('.file-image-wrapper');
                if (wrapper) {
                    messageUuid = wrapper.getAttribute('data-msg-uuid');
                    fileUuid = wrapper.getAttribute('data-file-uuid');
                    isOwn = wrapper.getAttribute('data-is-own') === 'true';
                    fileName = wrapper.getAttribute('data-file-name') || 'Файл';
                    var encodedText = wrapper.getAttribute('data-message-text') || '';
                    messageText = decodeMessageText(encodedText);
                    logDebug('[FILE_DATA] Got text from wrapper, length:', messageText.length);
                } else {
                    // Ищем сообщение через DOM
                    var msgElement = fileEl.closest('.message');
                    if (msgElement) {
                        messageUuid = msgElement.getAttribute('data-uuid');
                        isOwn = msgElement.classList.contains('own');
                        
                        // Получаем ЧИСТЫЙ ТЕКСТ из data-original-text (декодируем)
                        var originalTextEncoded = msgElement.getAttribute('data-original-text');
                        if (originalTextEncoded) {
                            messageText = decodeMessageText(originalTextEncoded);
                            logDebug('[FILE_DATA] Got text from data-original-text, length:', messageText.length);
                        } else {
                            // Fallback: извлекаем текст без цитат
                            var textDiv = msgElement.querySelector('.message-text');
                            if (textDiv) {
                                var clone = textDiv.cloneNode(true);
                                var quotes = clone.querySelectorAll('.message-quote');
                                quotes.forEach(function(quote) { quote.remove(); });
                                messageText = clone.textContent || clone.innerText || '';
                                messageText = messageText.trim();
                                logDebug('[FILE_DATA] Extracted text without quotes, length:', messageText.length);
                            }
                        }
                        
                        // Получаем file-uuid из onclick
                        var clickAttr = fileEl.getAttribute('onclick');
                        if (!clickAttr) {
                            var childWithClick = fileEl.querySelector('[onclick*="showFilePreview"]');
                            if (childWithClick) clickAttr = childWithClick.getAttribute('onclick');
                        }
                        if (clickAttr) {
                            var match = clickAttr.match(/showFilePreview\('([^']+)'/);
                            if (match) fileUuid = match[1];
                        }
                        if (!fileUuid) {
                            var fileLink = fileEl.querySelector('a') || fileEl;
                            if (fileLink && fileLink.href) {
                                var hrefMatch = fileLink.href.match(/file=([a-f0-9-]+)/i);
                                if (hrefMatch) fileUuid = hrefMatch[1];
                            }
                        }
                    }
                }
            }
            
            logDebug('[FILE_DATA] Final - messageUuid:', messageUuid, 'fileUuid:', fileUuid, 'text length:', messageText.length);
            
            return { messageUuid: messageUuid, fileUuid: fileUuid, isOwn: isOwn, fileName: fileName, messageText: messageText };
        }
        
        function clearLongPress() {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            longPressTargetElement = null;
            touchStartTime = 0;
            // НЕ сбрасываем longPressTriggered здесь - он сбрасывается отдельно в touchend
        }
        
        // ========== ПРАВЫЙ КЛИК (contextmenu) - используем capture ==========
        messagesArea.addEventListener('contextmenu', function(e) {
            if (isFileElement(e.target)) {
                logDebug('[FILE_MENU] contextmenu detected on file');
                e.preventDefault();
                e.stopPropagation();
                
                var fileEl = getRootFileElement(e.target);
                if (fileEl) {
                    var data = getFileDataFromFileElement(fileEl);
                    if (data.messageUuid && data.fileUuid) {
                        logDebug('[FILE_MENU] Right click - showing menu for file:', data.fileUuid);
                        showFileMenu(e, data.messageUuid, data.fileUuid, data.fileName, data.isOwn, data.messageText);
                    }
                }
            }
        }, true); // capture phase - важно!
        
        // ========== ТАЧ-СОБЫТИЯ (долгий тап и короткий тап) - используем capture ==========
        var longPressTriggered = false; // Флаг, сработал ли долгий тап
        
        messagesArea.addEventListener('touchstart', function(e) {
            if (isFileElement(e.target)) {
                logDebug('[FILE_MENU] touchstart detected on file');
                // Предотвращаем стандартное поведение (выделение, меню браузера)
                e.preventDefault();
                e.stopPropagation();
                
                var touch = e.touches[0];
                longPressStartX = touch.clientX;
                longPressStartY = touch.clientY;
                longPressTargetElement = getRootFileElement(e.target);
                touchStartTime = Date.now();
                longPressTriggered = false; // Сбрасываем флаг
                
                logDebug('[FILE_MENU] Long press timer started, target:', longPressTargetElement);
                
                longPressTimer = setTimeout(function() {
                    if (longPressTargetElement && !longPressTriggered) {
                        longPressTriggered = true; // Помечаем, что долгий тап сработал
                        logDebug('[FILE_MENU] Long press detected! Showing menu');
                        var data = getFileDataFromFileElement(longPressTargetElement);
                        if (data.messageUuid && data.fileUuid) {
                            var syntheticEvent = {
                                clientX: longPressStartX,
                                clientY: longPressStartY,
                                preventDefault: function() {},
                                stopPropagation: function() {}
                            };
                            showFileMenu(syntheticEvent, data.messageUuid, data.fileUuid, data.fileName, data.isOwn, data.messageText);
                        }
                        clearLongPress();
                    }
                }, LONG_PRESS_DURATION);
            }
        }, true); // capture phase - важно!
        
        messagesArea.addEventListener('touchmove', function(e) {
            if (longPressTimer) {
                var touch = e.touches[0];
                if (touch && (Math.abs(touch.clientX - longPressStartX) > MAX_TAP_MOVEMENT || 
                              Math.abs(touch.clientY - longPressStartY) > MAX_TAP_MOVEMENT)) {
                    logDebug('[FILE_MENU] touchmove - movement detected, cancelling long press');
                    clearLongPress();
                    longPressTriggered = false;
                }
            }
        }, true);
        
        messagesArea.addEventListener('touchend', function(e) {
            // Сохраняем данные ДО очистки
            var targetElement = longPressTargetElement;
            var touchDuration = Date.now() - touchStartTime;
            var wasLongPressTriggered = longPressTriggered; // Сработал ли долгий тап
            var isShortTap = (!wasLongPressTriggered && targetElement && touchDuration < LONG_PRESS_DURATION && touchDuration > 0);
            
            logDebug('[FILE_MENU] touchend - wasLongPressTriggered:', wasLongPressTriggered, 'isShortTap:', isShortTap, 'duration:', touchDuration);
            
            // ОТМЕНЯЕМ ТАЙМЕР СРАЗУ (чтобы он не сработал после touchend)
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            
            if (isShortTap && targetElement) {
                logDebug('[FILE_MENU] Short tap detected! Opening file preview');
                e.preventDefault();
                e.stopPropagation();
                
                // Получаем данные для предпросмотра
                var previewData = getFileDataForPreview(targetElement);
                if (previewData.fileUuid) {
                    logDebug('[FILE_MENU] Opening preview for file:', previewData.fileUuid, previewData.fileName);
                    // Вызываем showFilePreview напрямую
                    if (typeof window.showFilePreview === 'function') {
                        window.showFilePreview(
                            previewData.fileUuid, 
                            previewData.fileName, 
                            previewData.fileSize, 
                            previewData.fileMime
                        );
                    } else {
                        logDebug('[FILE_MENU] ERROR: showFilePreview is not defined');
                    }
                } else {
                    logDebug('[FILE_MENU] Could not extract file UUID for preview');
                }
            }
            
            clearLongPress();
            longPressTriggered = false;
        }, true);
        
        messagesArea.addEventListener('touchcancel', function(e) {
            logDebug('[FILE_MENU] touchcancel');
            clearLongPress();
            longPressTriggered = false;
        }, true);
        
        logDebug('[FILE_MENU] Event delegation setup complete (capture phase) - v8.18 with short tap fix');
    }

    // CSS для предотвращения выделения текста
    if (!document.getElementById('file-menu-styles')) {
        var style = document.createElement('style');
        style.id = 'file-menu-styles';
        style.textContent = `
            .file-image-wrapper,
            .file-preview-thumb,
            .file-item,
            .file-link-btn {
                -webkit-touch-callout: none !important;
                -webkit-user-select: none !important;
                user-select: none !important;
                touch-action: manipulation !important;
            }
        `;
        document.head.appendChild(style);
        logDebug('[FILE_MENU] CSS styles added');
    }

    // Вызываем setup
    setupFileEventDelegation();
    // ==================== BLOCK END: File long press and right click handlers v8.18 ====================

    
    setTimeout(loadLazyPreviews, 3000);
    logDebug('[DOM] DOMContentLoaded finished');
});
// ==================== BLOCK END: DOMContentLoaded v8.1 ====================

</script>

<!-- ==================== BLOCK START: HTML body (как в версии 2.66 с добавлением шторки) ==================== -->
<div class="wrap">
    <!-- Кнопка-гамбургер ТОЛЬКО ДЛЯ МОБИЛЬНЫХ -->
    <button class="mobile-drawer-toggle" id="mobile-drawer-toggle" aria-label="Открыть меню">
    ☰
    <span id="mobile-drawer-badge" class="mobile-drawer-badge" style="display:none;">0</span></button>
    <div class="mobile-drawer-overlay" id="mobile-drawer-overlay"></div>
    <!-- ШТОРКА ДЛЯ МОБИЛЬНЫХ -->
        <div class="mobile-drawer-panel" id="mobile-drawer-panel">
        <div class="mobile-drawer-header">
            <div class="mobile-drawer-logo">
                <img src="<?= $appBase ?>/favicon.ico" alt="Logo" style="width: 28px; height: 28px;">
                <span>ЗадаЧат</span>
            </div>
            <button class="mobile-drawer-close" id="mobile-drawer-close">✕</button>
        </div>
        
        <!-- ========== ДОБАВЛЯЕМ БЛОК С ЛОГИНОМ ПОЛЬЗОВАТЕЛЯ ========== -->
        <div class="mobile-drawer-user">
            <div class="mobile-drawer-user-avatar">👤</div>
            <div class="mobile-drawer-user-info">
                <div class="mobile-drawer-user-name"><?= htmlspecialchars($current_user_login_display) ?></div>
                <div class="mobile-drawer-user-role"><?= $is_admin ? 'Администратор' : 'Пользователь' ?></div>
            </div>
        </div>
        <!-- ========== КОНЕЦ БЛОКА С ЛОГИНОМ ========== -->
        
        <div class="mobile-drawer-nav">
            <a href="<?= $appBase ?>/index.php">🏠 Главная</a>
            <a href="<?= $appBase ?>/projects.php">📁 Проекты</a>
            <a href="<?= $appBase ?>/messages.php" class="active">💬 Сообщения</a>
            <a href="<?= $appBase ?>/files.php">📎 Файлы</a>
            <a href="<?= $appBase ?>/search.php">🔍 Поиск</a>
            <a href="<?= $appBase ?>/admin.php">⚙️ Админка</a>
        </div>
        
        <div class="mobile-drawer-sidebar">
            <div class="mobile-drawer-sidebar-title">📋 Проекты и задачи</div>
            <?php foreach ($projects as $project): ?>
            <div class="project-item" data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>">
                <div class="project-header">
                    <div style="display:flex;align-items:center;min-width:0;">
                        <svg class="project-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span class="project-title"><?= htmlspecialchars($project['title']) ?></span>
                    </div>
                    <span class="project-count"><?= count($tasks_by_project[$project['uuid']] ?? []) ?></span>
                </div>
                <div class="tasks-list" <?= $selected_project_uuid === $project['uuid'] ? 'style="display:block;"' : '' ?>>
                    <?php foreach (($tasks_by_project[$project['uuid']] ?? []) as $task): ?>
                    <div class="task-item <?= $selected_task_uuid === $task['uuid'] ? 'active' : '' ?>" data-task-uuid="<?= htmlspecialchars($task['uuid']) ?>">
                        <div class="task-title"><?= htmlspecialchars($task['title']) ?> <?php if (($task['messages_count'] ?? 0) > 0): ?><span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[<?= $task['messages_count'] ?>]</span><?php endif; ?></div>
                        <?php if ($task['assignee_name']): ?><div class="task-assignee">👤 <?= htmlspecialchars($task['assignee_name']) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mobile-drawer-footer">
            <form method="post" action="<?= $appBase ?>/logout.php" onsubmit="return confirm('Выйти из системы?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" class="logout-btn-mobile">🚪 Выйти</button>
            </form>
        </div>
    </div>

    <!-- ОСНОВНОЙ КОНТЕЙНЕР (как в версии 2.66 - с сайдбаром) -->
    <div class="messenger-container">
        <!-- Сайдбар - ВИДИМ НА ДЕСКТОПЕ, СКРЫВАЕТСЯ НА МОБИЛЬНЫХ -->
        <div class="messenger-sidebar">
            <?php foreach ($projects as $project): ?>
            <div class="project-item" data-project-uuid="<?= htmlspecialchars($project['uuid']) ?>">
                <div class="project-header">
                    <div style="display:flex;align-items:center;min-width:0;">
                        <svg class="project-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span class="project-title"><?= htmlspecialchars($project['title']) ?></span>
                    </div>
                    <span class="project-count"><?= count($tasks_by_project[$project['uuid']] ?? []) ?></span>
                </div>
                <div class="tasks-list" <?= $selected_project_uuid === $project['uuid'] ? 'style="display:block;"' : '' ?>>
                    <?php foreach (($tasks_by_project[$project['uuid']] ?? []) as $task): ?>
                    <div class="task-item <?= $selected_task_uuid === $task['uuid'] ? 'active' : '' ?>" data-task-uuid="<?= htmlspecialchars($task['uuid']) ?>">
                        <div class="task-title"><?= htmlspecialchars($task['title']) ?> <?php if (($task['messages_count'] ?? 0) > 0): ?><span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[<?= $task['messages_count'] ?>]</span><?php endif; ?></div>
                        <?php if ($task['assignee_name']): ?><div class="task-assignee">👤 <?= htmlspecialchars($task['assignee_name']) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="messenger-chat">
            <div class="chat-header">
                <h3 id="chat-title-wrapper" style="margin: 0 0 4px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <a href="#" id="chat-title" target="_self" style="text-decoration: none; border-bottom: 1px dashed #70a0ff; cursor: pointer;" title="Нажмите для просмотра описания и файлов задачи">Выберите задачу</a>
                    <a href="#" id="task-files-link" style="display: none; background: rgba(79,124,255,0.12); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: #4f7cff; text-decoration: none; transition: all 0.2s;" target="_blank">📎 Все файлы задачи</a>
                </h3>
                <div class="task-info" id="chat-subtitle">Нажмите на проект и задачу</div>
            </div>
            
            <div class="messages-area" id="messages-area">
                <div class="messages-area-inner" id="messages-area-inner">
                    <div class="empty-state">Выберите задачу для обсуждения</div>
                </div>
            </div>
            
            <div class="message-input-area" id="input-area" style="<?= $selected_task_uuid ? 'display:block;' : 'display:none;' ?>">
                <form id="message-form" enctype="multipart/form-data">
                    <div id="perm-warning" class="perm-warning"></div>
                    <div class="input-wrapper">
                        <button type="button" class="file-attach-btn" onclick="document.getElementById('file-input').click()" title="Прикрепить файл">📎</button>
                        <textarea id="message-input" class="message-input" placeholder="Сообщение (Enter - отправка, Ctrl+V - вставить картинку)" rows="1" maxlength="65000"></textarea>
                        <button type="submit" class="send-btn" id="send-btn"><span class="btn-text">Отправить</span><span class="spinner"></span></button>
                    </div>
                    
                    <div id="reply-indicator" style="display: none; background: #eef2ff; border-left: 4px solid #4f7cff; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; font-size: 13px; color: #1e293b; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span>↩️</span>
                            <span id="reply-indicator-text">Вы отвечаете на сообщение от <strong id="reply-author-name"></strong></span>
                        </div>
                        <button type="button" id="cancel-reply-btn" style="background: none; border: none; cursor: pointer; font-size: 16px; color: #ef4444; padding: 4px 8px; border-radius: 6px;">✕ Отменить</button>
                    </div>
                    
                    <div class="selected-files" id="selected-files"></div>
                    <input type="file" id="file-input" name="files[]" multiple style="display:none;">
                    <input type="hidden" id="current-task-uuid" name="task_uuid" value="<?= htmlspecialchars($selected_task_uuid) ?>">
                    <input type="hidden" id="reply-to-uuid" name="reply_to_uuid" value="">
                    <?= msgql_csrf_form_field() ?>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="messageMenuOverlay" class="message-menu-overlay" onclick="closeMessageMenu()">
    <div class="message-menu" onclick="event.stopPropagation()">
        <div class="message-menu-item danger" id="deleteMessageBtn" onclick="deleteCurrentMessage()">🗑️ Удалить сообщение</div>
        <div class="message-menu-divider"></div>    
        <div class="message-menu-item" id="replaceFileBtn" onclick="replaceFileInMessage()" style="display: none;">🔄 Заменить\удалить файл</div>
        <div class="message-menu-item" id="addFilesToMessageBtn" onclick="addFilesToMessage()" style="display: none;">📎 Добавить файл</div>
        <div class="message-menu-divider"></div>
        <div class="message-menu-item" onclick="copyMessageLink()">🔗 Копировать ссылку</div>
        <div class="message-menu-item" onclick="copyMessageText()">📋 Копировать текст</div>
        <div class="message-menu-item" onclick="replyToCurrentMessage()">↩️ Ответить</div>
        <div class="message-menu-item" id="editMessageBtn" onclick="editCurrentMessage()" style="display: none;">✏️ Редактировать</div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Mobile Drawer Controller ====================
document.addEventListener('DOMContentLoaded', function() {
    if (window.innerWidth > 768) return;
    
    var body = document.body;
    var toggleBtn = document.getElementById('mobile-drawer-toggle');
    var overlay = document.getElementById('mobile-drawer-overlay');
    var closeBtn = document.getElementById('mobile-drawer-close');
    var panel = document.getElementById('mobile-drawer-panel');
    
    function openDrawer() {
        body.classList.add('mobile-drawer-open');
        body.style.overflow = 'hidden';
    }
    
    function closeDrawer() {
        body.classList.remove('mobile-drawer-open');
        body.style.overflow = '';
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            openDrawer();
        });
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            closeDrawer();
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            closeDrawer();
        });
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && body.classList.contains('mobile-drawer-open')) {
            closeDrawer();
        }
    });
    
    document.querySelectorAll('.task-item').forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeDrawer();
            }
        });
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            body.classList.remove('mobile-drawer-open');
            body.style.overflow = '';
        }
    });
});


// ==================== BLOCK END: Mobile Drawer Controller ====================
</script>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Smart links and quotes handler ====================
(function() {
    // Обработчик кликов по умным ссылкам
    function handleSmartLinkClick(event) {
        var link = event.target.closest('.smart-link-message, .smart-link-task, .smart-link-file');
        if (!link) return;
        
        event.preventDefault();
        event.stopPropagation();
        
        // Для сообщений
        if (link.classList.contains('smart-link-message')) {
            var msgUuid = link.getAttribute('data-msg-uuid');
            if (msgUuid && typeof scrollToMessageByUuid === 'function') {
                scrollToMessageByUuid(msgUuid);
            } else if (msgUuid) {
                window.location.href = window.APP_BASE + '/messages.php?message=' + msgUuid;
            }
        }
        
        // Для задач
        if (link.classList.contains('smart-link-task')) {
            var href = link.getAttribute('href');
            if (href && typeof selectTaskByUuid === 'function') {
                var match = href.match(/[?&]task=([a-f0-9-]+)/i);
                if (match && match[1]) {
                    selectTaskByUuid(match[1]);
                    return;
                }
            }
            // fallback
            window.open(link.href, '_blank');
        }
        
        // Для файлов
        if (link.classList.contains('smart-link-file')) {
            var href = link.getAttribute('href');
            if (href && typeof showFilePreview === 'function') {
                var match = href.match(/[?&](?:uuid|file)=([a-f0-9-]+)/i);
                if (match && match[1]) {
                    showFilePreview(match[1], '', 0, '');
                    return;
                }
            }
            window.open(link.href, '_blank');
        }
    }
    
    // Обработчик кликов по цитатам
    function handleQuoteClick(event) {
        var quote = event.target.closest('.clickable-quote');
        if (!quote) return;
        
        var quoteUuid = quote.getAttribute('data-quote-uuid');
        if (quoteUuid && typeof scrollToMessageByUuid === 'function') {
            event.preventDefault();
            event.stopPropagation();
            scrollToMessageByUuid(quoteUuid);
        }
    }
    
    // Функция выбора задачи по UUID
    window.selectTaskByUuid = function(taskUuid) {
        var taskElement = document.querySelector('.task-item[data-task-uuid="' + taskUuid + '"]');
        if (taskElement && typeof selectTask === 'function') {
            selectTask(taskElement);
        } else {
            window.location.href = window.APP_BASE + '/messages.php?task=' + taskUuid;
        }
    };
    
    // Навешиваем обработчики после загрузки DOM
    document.addEventListener('DOMContentLoaded', function() {
        document.body.addEventListener('click', handleSmartLinkClick);
        document.body.addEventListener('click', handleQuoteClick);
        logDebug('[LINKS] Smart links and quotes handlers initialized');
    });
})();
// ==================== BLOCK END: Smart links and quotes handler ====================
</script>








<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Smart Mutation Update v8.31 ====================
// ver.8.31 (2026-05-27) - УМНОЕ ОБНОВЛЕНИЕ ПРИ ЛЮБЫХ ИЗМЕНЕНИЯХ
// - Отправка сообщения: добавляет только новое сообщение
// - Редактирование текста: обновляет только текст сообщения
// - Добавление/удаление/замена файлов: обновляет только блок файлов сообщения
// - Удаление сообщения: удаляет только один элемент
// - НЕ перезагружает всю страницу, НЕ перезагружает картинки
// - Кэширование изображений для мгновенного восстановления

// Инициализируем кэш изображений (сохраняем между обновлениями)
window.imageCache = window.imageCache || new Map();

// ==================== КЭШИРОВАНИЕ ИЗОБРАЖЕНИЙ ====================

// Функция для восстановления кэшированных изображений в DOM
function restoreCachedImagesToDOM() {
    var lazyImages = document.querySelectorAll('.lazy-preview');
    if (!lazyImages.length) return;
    
    var restored = 0;
    for (var i = 0; i < lazyImages.length; i++) {
        var img = lazyImages[i];
        
        // Пропускаем уже загруженные
        if (img.getAttribute('data-loaded') === 'true') continue;
        
        // Ищем file-uuid
        var fileUuid = null;
        var wrapper = img.closest('.file-image-wrapper');
        if (wrapper) {
            fileUuid = wrapper.getAttribute('data-file-uuid');
        }
        if (!fileUuid && img.dataset && img.dataset.src) {
            var match = img.dataset.src.match(/[?&]file=([a-f0-9-]+)/i);
            if (match) fileUuid = match[1];
        }
        
        if (fileUuid) {
            var cacheKey = 'file_' + fileUuid;
            var cached = window.imageCache.get(cacheKey);
            if (cached && cached.loaded && cached.dataUrl) {
                img.src = cached.dataUrl;
                img.setAttribute('data-loaded', 'true');
                img.classList.remove('lazy-preview');
                if (img.dataset) delete img.dataset.src;
                restored++;
            } else if (cached && cached.url && !img.src) {
                img.src = cached.url;
                img.setAttribute('data-loaded', 'true');
                img.classList.remove('lazy-preview');
                if (img.dataset) delete img.dataset.src;
                restored++;
            }
        }
    }
    
    if (restored > 0) {
        logDebug('[IMAGE_CACHE] Restored ' + restored + ' images from cache');
    }
}

// Перехватываем загрузку через lazy queue для сохранения в кэш
var originalProcessLazyImageQueue = window.processLazyImageQueue;
if (originalProcessLazyImageQueue) {
    window.processLazyImageQueue = function() {
        var currentImg = window.currentLoadingImage;
        
        if (currentImg && currentImg.dataset && currentImg.dataset.src) {
            var dataSrc = currentImg.dataset.src;
            var match = dataSrc.match(/[?&]file=([a-f0-9-]+)/i);
            if (match) {
                var fileUuid = match[1];
                var cacheKey = 'file_' + fileUuid;
                
                if (!window.imageCache.has(cacheKey)) {
                    window.imageCache.set(cacheKey, {
                        url: dataSrc,
                        loaded: false,
                        dataUrl: null
                    });
                }
                
                var cached = window.imageCache.get(cacheKey);
                if (!cached.loaded) {
                    var originalOnload = currentImg.onload;
                    currentImg.onload = function() {
                        if (originalOnload) originalOnload.call(this);
                        cached.loaded = true;
                        cached.dataUrl = this.src;
                        logDebug('[IMAGE_CACHE] Cached image: ' + fileUuid);
                    };
                }
            }
        }
        
        originalProcessLazyImageQueue();
    };
    logDebug('[IMAGE_CACHE] processLazyImageQueue wrapped');
}

// ==================== УМНОЕ ОБНОВЛЕНИЕ СООБЩЕНИЙ ====================

// Универсальная функция обновления одного сообщения по данным с сервера
function smartUpdateMessage(messageData) {
    if (!messageData || !messageData.uuid) return false;
    
    var existingMsg = document.getElementById('msg-' + messageData.uuid);
    if (!existingMsg) {
        // Сообщения нет — добавляем новое
        var container = document.getElementById('messages-area');
        if (!container) return false;
        
        // Удаляем empty-state если есть
        var emptyState = container.querySelector('.empty-state');
        if (emptyState) emptyState.remove();
        
        // Вставляем в правильную позицию по времени
        var inserted = false;
        var messages = container.querySelectorAll('.message');
        for (var i = 0; i < messages.length; i++) {
            var existingTime = parseInt(messages[i].getAttribute('data-time') || '0');
            if (messageData.time < existingTime) {
                messages[i].insertAdjacentHTML('beforebegin', renderMessage(messageData));
                inserted = true;
                break;
            }
        }
        if (!inserted) {
            container.insertAdjacentHTML('beforeend', renderMessage(messageData));
        }

        setTimeout(function() {
            loadLazyPreviews();
            restoreCachedImagesToDOM();
        }, 100);
        
        // Сохраняем информацию о файлах в кэш для изображений
        if (messageData.files && messageData.files.length) {
            for (var j = 0; j < messageData.files.length; j++) {
                var f = messageData.files[j];
                if (f.mime && f.mime.startsWith('image/')) {
                    var cacheKey = 'file_' + f.uuid;
                    var imageUrl = window.APP_BASE + '/download.php?file=' + f.uuid + '&preview=1';
                    if (!window.imageCache.has(cacheKey)) {
                        window.imageCache.set(cacheKey, {
                            url: imageUrl,
                            loaded: false,
                            dataUrl: null
                        });
                    }
                }
            }
        }
        

        // Прокрутка если новое сообщение внизу
        var containerScroll = document.getElementById('messages-area');
        if (containerScroll) {
            var distToBottom = containerScroll.scrollHeight - containerScroll.scrollTop - containerScroll.clientHeight;
            if (distToBottom < 300) {
                setTimeout(function() { 
                    containerScroll.scrollTop = containerScroll.scrollHeight; 
                    logDebug('[SMART_UPDATE] Scrolled container to bottom');
                }, 100);
            }
        }        
        logDebug('[SMART_UPDATE] Added new message: ' + messageData.uuid);
        return true;
    }
    
    // Сообщение есть — обновляем
    var newHtml = renderMessage(messageData);
    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = newHtml;
    var newMsgElement = tempDiv.firstChild;
    
    // Сохраняем позицию скролла
    var container = document.getElementById('messages-area');
    var scrollTop = container ? container.scrollTop : 0;
    var wasAtBottom = container ? (container.scrollHeight - scrollTop - container.clientHeight) < 100 : false;
    
    // Заменяем элемент
    existingMsg.parentNode.replaceChild(newMsgElement, existingMsg);
    
    // ========== ОБНОВЛЯЕМ АТРИБУТ data-original-text ==========
    if (messageData.text !== undefined) {
        var encodedText = encodeURIComponent(messageData.text);
        newMsgElement.setAttribute('data-original-text', encodedText);
        logDebug('[SMART_UPDATE] Updated data-original-text for message: ' + messageData.uuid);
    }
    // ===========================================================
    
    // Восстанавливаем позицию скролла
    if (wasAtBottom && container) {
        setTimeout(function() { container.scrollTop = container.scrollHeight; }, 50);
    } else if (scrollTop > 0 && container) {
        container.scrollTop = scrollTop;
    }
    
    // Восстанавливаем кэшированные изображения в обновлённом сообщении
    setTimeout(function() {
        var newMsgElementAfter = document.getElementById('msg-' + messageData.uuid);
        if (newMsgElementAfter) {
            var lazyImages = newMsgElementAfter.querySelectorAll('.lazy-preview');
            for (var i = 0; i < lazyImages.length; i++) {
                var img = lazyImages[i];
                var wrapper = img.closest('.file-image-wrapper');
                if (wrapper) {
                    var fileUuid = wrapper.getAttribute('data-file-uuid');
                    if (fileUuid && window.imageCache) {
                        var cached = window.imageCache.get('file_' + fileUuid);
                        if (cached && cached.loaded && cached.dataUrl && !img.src) {
                            img.src = cached.dataUrl;
                            img.setAttribute('data-loaded', 'true');
                            img.classList.remove('lazy-preview');
                            if (img.dataset) delete img.dataset.src;
                        }
                    }
                }
            }
        }
    }, 50);
    
    logDebug('[SMART_UPDATE] Updated message: ' + messageData.uuid);
    return true;
}

// ==================== ПЕРЕОПРЕДЕЛЕНИЕ refreshPaginationAfterMutation ====================

var originalRefreshPaginationAfterMutation = window.refreshPaginationAfterMutation;
window.refreshPaginationAfterMutation = function(mutationType, affectedMessageUuid, newMessageData) {
    logDebug('[SMART_UPDATE] refreshPaginationAfterMutation called with type:', mutationType, 'uuid:', affectedMessageUuid);
    
    // Если передан новые данные сообщения — обновляем его
    if (newMessageData && newMessageData.uuid) {
        smartUpdateMessage(newMessageData);
        
        // Обновляем счётчик сообщений в пагинации
        if (window.pagination && mutationType !== 'delete') {
            if (mutationType === 'add') {
                window.pagination.totalMessages = (window.pagination.totalMessages || 0) + 1;
            }
            window.pagination.totalPages = Math.ceil(window.pagination.totalMessages / window.MESSAGES_PER_PAGE);
        }
        
        // // Обновляем счётчик в сайдбаре
        // var taskElement = document.querySelector('.task-item[data-task-uuid="' + window.currentTaskUuid + '"]');
        // if (taskElement) {
        //     var totalMessages = window.pagination ? window.pagination.totalMessages : 0;
        //     var countSpan = taskElement.querySelector('.task-title span');
        //     if (totalMessages > 0) {
        //         if (countSpan) {
        //             countSpan.textContent = '[' + totalMessages + ']';
        //         } else {
        //             var titleSpan = taskElement.querySelector('.task-title');
        //             if (titleSpan) {
        //                 titleSpan.insertAdjacentHTML('beforeend', '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + totalMessages + ']</span>');
        //             }
        //         }
        //     } else if (countSpan) {
        //         countSpan.remove();
        //     }
        // }
        
        setTimeout(function() {
            if (typeof markVisibleMessagesAsRead === 'function') {
                markVisibleMessagesAsRead();
            }
            restoreCachedImagesToDOM();
        }, 200);
        return;
    }
    
    // Если это удаление сообщения
    if (mutationType === 'delete' && affectedMessageUuid) {
        var msgElement = document.getElementById('msg-' + affectedMessageUuid);
        if (msgElement) {
            msgElement.style.transition = 'opacity 0.2s ease';
            msgElement.style.opacity = '0';
            setTimeout(function() {
                if (msgElement && msgElement.parentNode) {
                    msgElement.remove();
                    
                    // Обновляем счётчик
                    if (window.pagination) {
                        window.pagination.totalMessages = Math.max(0, (window.pagination.totalMessages || 0) - 1);
                        window.pagination.totalPages = Math.ceil(window.pagination.totalMessages / window.MESSAGES_PER_PAGE);
                    }
                    
                    var taskElement = document.querySelector('.task-item[data-task-uuid="' + window.currentTaskUuid + '"]');
                    if (taskElement) {
                        var countSpan = taskElement.querySelector('.task-title span');
                        if (window.pagination && window.pagination.totalMessages > 0) {
                            if (countSpan) {
                                countSpan.textContent = '[' + window.pagination.totalMessages + ']';
                            }
                        } else if (countSpan) {
                            countSpan.remove();
                            var container = document.getElementById('messages-area');
                            if (container && !container.querySelector('.message')) {
                                container.innerHTML = '<div class="empty-state">💬 Нет сообщений. Напишите первое!</div>';
                            }
                        }
                    }
                    
                    updateUnreadBadge();
                    logDebug('[SMART_UPDATE] Message removed: ' + affectedMessageUuid);
                }
            }, 200);
        }
        return;
    }
    
    // Fallback: если не удалось определить тип мутации, используем оригинальную функцию
    if (originalRefreshPaginationAfterMutation) {
        logDebug('[SMART_UPDATE] Fallback to original refreshPaginationAfterMutation');
        originalRefreshPaginationAfterMutation();
    }
};

// ==================== ПЕРЕОПРЕДЕЛЕНИЕ sendMessage ====================

var originalSendMessage = window.sendMessage;
if (originalSendMessage) {
    window.sendMessage = function(e) {
        e.preventDefault();
        if (isSending) return;
        
        var task = document.getElementById('current-task-uuid').value;
        var text = document.getElementById('message-input').value.trim();
        var replyToUuid = document.getElementById('reply-to-uuid').value;
        var hasFiles = selectedFiles.length > 0;
        
        if (!text && !hasFiles) {
            showToast('Введите текст или прикрепите файл', 'warning');
            return;
        }
        if (!task) {
            showToast('Сначала выберите задачу', 'warning');
            return;
        }
        
        isSending = true;
        var btn = document.getElementById('send-btn');
        if (btn) btn.classList.add('loading');
        
        var fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('task_uuid', task);
        fd.append('text', text);
        if (replyToUuid) fd.append('reply_to_uuid', replyToUuid);
        addCsrfToFormData(fd);
        
        selectedFiles.forEach(function(f){ fd.append('files[]', f); });
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL);
        xhr.timeout = 60000;
        
        xhr.onload = function() {
            isSending = false;
            if (btn) btn.classList.remove('loading');
            
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.needs_confirmation && d.security_details) {
                        showSecurityWarningModal(d.security_details, d.security_message, selectedFiles, task, text, replyToUuid);
                        return;
                    }
                    
                    if (d.success && d.message) {
                        document.getElementById('message-input').value = '';
                        selectedFiles = [];
                        updateSelectedFiles();
                        document.getElementById('reply-to-uuid').value = '';
                        document.getElementById('reply-indicator').style.display = 'none';
                        
                        // Используем умное обновление вместо полной перезагрузки
                        smartUpdateMessage(d.message);
                        
                        // Обновляем счётчик в пагинации
                        if (window.pagination) {
                            window.pagination.totalMessages = (window.pagination.totalMessages || 0) + 1;
                            window.pagination.totalPages = Math.ceil(window.pagination.totalMessages / window.MESSAGES_PER_PAGE);
                        }
                        
                        // // Обновляем счётчик в сайдбаре
                        // var taskElement = document.querySelector('.task-item[data-task-uuid="' + task + '"]');
                        // if (taskElement) {
                        //     var newCount = window.pagination ? window.pagination.totalMessages : 0;
                        //     var countSpan = taskElement.querySelector('.task-title span');
                        //     if (newCount > 0) {
                        //         if (countSpan) {
                        //             countSpan.textContent = '[' + newCount + ']';
                        //         } else {
                        //             var titleSpan = taskElement.querySelector('.task-title');
                        //             if (titleSpan) {
                        //                 titleSpan.insertAdjacentHTML('beforeend', '<span style="font-size:11px;color:#4f7cff;background:rgba(79,124,255,0.15);padding:2px 6px;border-radius:10px;margin-left:6px;">[' + newCount + ']</span>');
                        //             }
                        //         }
                        //     }
                        // }
                        
                        if (d.message.time > window.lastMessageTime) {
                            window.lastMessageTime = d.message.time;
                        }
                        
                        // Прокрутка
                        var container = document.getElementById('messages-area');
                        if (container && !window.userIsScrolling) {
                            setTimeout(function() { container.scrollTop = container.scrollHeight; }, 100);
                            setTimeout(function() { container.scrollTop = container.scrollHeight; }, 500);
                            setTimeout(function() { container.scrollTop = container.scrollHeight; }, 1000);
                        }
                        
                        restoreCachedImagesToDOM();
                    }
                } catch(e) {
                    logDebug('[SEND] JSON error: ' + e);
                    showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        
        xhr.onerror = function() {
            isSending = false;
            if (btn) btn.classList.remove('loading');
            showToast('Сетевая ошибка', 'error');
        };
        
        xhr.send(fd);
    };
    logDebug('[SMART_UPDATE] sendMessage wrapped with smart update');
}



var originalDeleteMessage = window.deleteMessage;
if (originalDeleteMessage) {
    window.deleteMessage = function(messageUuid) {
        logDebug('[DELETE_MSG] ========== START ==========');
        logDebug('[DELETE_MSG] Called with messageUuid:', messageUuid);
        
        if (!messageUuid) {
            if (window._fileMenuContext && window._fileMenuContext.messageUuid) {
                messageUuid = window._fileMenuContext.messageUuid;
            }
        }
        
        if (!messageUuid) {
            logDebug('[DELETE_MSG] ERROR: No message UUID found');
            showToast('Ошибка: сообщение не найдено', 'error');
            return;
        }
        
        if (!confirm('Удалить сообщение?')) {
            logDebug('[DELETE_MSG] Cancelled by user');
            return;
        }
        
        logDebug('[DELETE_MSG] Sending delete request for message:', messageUuid);
        
        var formData = new URLSearchParams();
        formData.append('action', 'delete_message');
        formData.append('message_uuid', messageUuid);
        addCsrfToUrlParams(formData);
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;
        
        xhr.onload = function() {
            logDebug('[DELETE_MSG] Response status:', xhr.status);
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    logDebug('[DELETE_MSG] Response data:', d);
                    if (d.success) {
                        logDebug('[DELETE_MSG] SUCCESS! Message deleted');
                        
                        // Используем умное удаление
                        window.refreshPaginationAfterMutation('delete', messageUuid);
                        showToast('✓ Сообщение удалено', 'success');
                    } else {
                        logDebug('[DELETE_MSG] ERROR:', d.error);
                        showToast('Ошибка: ' + d.error, 'error');
                    }
                } catch(e) {
                    logDebug('[DELETE_MSG] JSON parse error:', e);
                    showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                logDebug('[DELETE_MSG] HTTP error:', xhr.status);
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        
        xhr.onerror = function() {
            logDebug('[DELETE_MSG] Network error');
            showToast('Сетевая ошибка', 'error');
        };
        
        xhr.ontimeout = function() {
            logDebug('[DELETE_MSG] Timeout');
            showToast('Превышено время ожидания', 'error');
        };
        
        xhr.send(formData);
    };
    logDebug('[SMART_UPDATE] deleteMessage wrapped with smart delete');
}

// ==================== ОБРАБОТКА ДОБАВЛЕНИЯ/ЗАМЕНЫ/УДАЛЕНИЯ ФАЙЛОВ ====================

// Обёртка для uploadFilesToMessage
// Обёртка для uploadFilesToMessage
var originalUploadFilesToMessage = window.uploadFilesToMessage;
if (originalUploadFilesToMessage) {
    window.uploadFilesToMessage = function(messageUuid, files) {
        logDebug('[UPLOAD_FILES] ========== START ==========');
        logDebug('[UPLOAD_FILES] messageUuid:', messageUuid);
        
        if (!messageUuid) {
            showToast('Ошибка: сообщение не найдено', 'error');
            return;
        }
        
        if (!files || files.length === 0) {
            var fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.multiple = true;
            fileInput.style.display = 'none';
            fileInput.accept = 'image/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.*,text/plain,application/zip';
            
            fileInput.onchange = function(e) {
                if (e.target.files && e.target.files.length > 0) {
                    window.uploadFilesToMessage(messageUuid, Array.from(e.target.files));
                }
                fileInput.remove();
            };
            
            document.body.appendChild(fileInput);
            fileInput.click();
            return;
        }
        
        showToast('⏳ Загрузка файлов...', 'info');
        
        var formData = new FormData();
        formData.append('action', 'add_files_to_message');
        formData.append('message_uuid', messageUuid);
        addCsrfToFormData(formData);
        
        for (var i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL);
        xhr.timeout = 120000;
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var d = JSON.parse(xhr.responseText);
                    if (d.success && d.message) {
                        // Используем умное обновление
                        smartUpdateMessage(d.message);
                        showToast('✓ Файлы добавлены', 'success');
                        
                        // ВАЖНО: Запускаем загрузку превью с МНОЖЕСТВЕННЫМИ попытками
                        // и сохраняем изображения в кэш
                        setTimeout(function() {
                            loadLazyPreviews();
                            // Дополнительная попытка через 500ms
                            setTimeout(loadLazyPreviews, 500);
                            // И через 1500ms
                            setTimeout(loadLazyPreviews, 1500);
                        }, 100);
                        
                        restoreCachedImagesToDOM();
                    } else if (d.needs_confirmation && d.security_details) {
                        showToast('Требуется подтверждение безопасности', 'warning');
                    } else {
                        showToast('Ошибка: ' + (d.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    logDebug('[UPLOAD_FILES] JSON error: ' + e);
                    showToast('Ошибка обработки ответа', 'error');
                }
            } else {
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        
        xhr.onerror = function() { 
            showToast('Сетевая ошибка', 'error');
        };
        xhr.ontimeout = function() { 
            showToast('Превышено время ожидания', 'error');
        };
        xhr.send(formData);
    };
    logDebug('[SMART_UPDATE] uploadFilesToMessage wrapped with smart update');
}

// Обёртка для executeFileReplace
// Обёртка для executeFileReplace
var originalExecuteFileReplace = window.executeFileReplace;
if (originalExecuteFileReplace) {
    window.executeFileReplace = function(messageUuid, oldFileUuid, newFile) {
        if (!messageUuid || !oldFileUuid || !newFile) {
            showToast('Ошибка: не указаны параметры', 'error');
            return;
        }
        
        logDebug('[EXECUTE_FILE_REPLACE] Starting file replacement');
        showToast('🔄 Замена файла...', 'info');
        
        var formData = new FormData();
        formData.append('action', 'replace_message_file');
        formData.append('message_uuid', messageUuid);
        formData.append('old_file_uuid', oldFileUuid);
        formData.append('file', newFile);
        addCsrfToFormData(formData);
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL);
        xhr.timeout = 60000;
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.message) {
                        // Используем умное обновление
                        smartUpdateMessage(data.message);
                        showToast('✓ Файл успешно заменён', 'success');
                        
                        // ВАЖНО: Запускаем загрузку превью для нового файла
                        setTimeout(function() {
                            loadLazyPreviews();
                            // Дополнительные попытки для надёжности
                            setTimeout(loadLazyPreviews, 500);
                            setTimeout(loadLazyPreviews, 1500);
                        }, 100);
                        
                        restoreCachedImagesToDOM();
                    } else {
                        showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    logDebug('[EXECUTE_FILE_REPLACE] JSON error: ' + e);
                    showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        
        xhr.onerror = function() { 
            showToast('Сетевая ошибка', 'error');
        };
        xhr.ontimeout = function() { 
            showToast('Превышено время ожидания', 'error');
        };
        xhr.send(formData);
    };
    logDebug('[SMART_UPDATE] executeFileReplace wrapped with smart update and preview loading');
}

// Обёртка для executeFileDelete
// Обёртка для executeFileDelete
var originalExecuteFileDelete = window.executeFileDelete;
if (originalExecuteFileDelete) {
    window.executeFileDelete = function(messageUuid, fileUuid) {
        if (!messageUuid || !fileUuid) {
            showToast('Ошибка: не указаны параметры', 'error');
            return;
        }
        
        logDebug('[EXECUTE_FILE_DELETE] Starting file deletion');
        showToast('🗑️ Удаление файла...', 'info');
        
        var formData = new URLSearchParams();
        formData.append('action', 'delete_message_file');
        formData.append('message_uuid', messageUuid);
        formData.append('file_uuid', fileUuid);
        addCsrfToUrlParams(formData);
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL);
        xhr.timeout = 30000;
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.message) {
                        smartUpdateMessage(data.message);
                        showToast('✓ Файл удалён из сообщения', 'success');
                        
                        // Обновляем превью (если остались другие изображения)
                        setTimeout(function() {
                            loadLazyPreviews();
                        }, 100);
                        
                        restoreCachedImagesToDOM();
                    } else {
                        showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch(e) {
                    logDebug('[EXECUTE_FILE_DELETE] JSON error: ' + e);
                    showToast('Ошибка обработки ответа сервера', 'error');
                }
            } else {
                showToast('Ошибка сервера (' + xhr.status + ')', 'error');
            }
        };
        
        xhr.onerror = function() { 
            showToast('Сетевая ошибка', 'error');
        };
        xhr.ontimeout = function() { 
            showToast('Превышено время ожидания', 'error');
        };
        xhr.send(formData);
    };
    logDebug('[SMART_UPDATE] executeFileDelete wrapped with smart update');
}

// ==================== ВОССТАНОВЛЕНИЕ КЭША ПРИ ЗАГРУЗКЕ СТРАНИЦЫ ====================

// Принудительно запускаем восстановление кэша после полной загрузки
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(restoreCachedImagesToDOM, 500);
    setTimeout(restoreCachedImagesToDOM, 1000);
    setTimeout(restoreCachedImagesToDOM, 2000);
});

logDebug('[SMART_UPDATE] v8.31 initialized with smart updates for all mutations');
// ==================== BLOCK END: Smart Mutation Update v8.31 ====================
</script>

<!-- ==================== BLOCK START: Edit Message Modal v8.32 ==================== -->
<div id="editMessageModal" class="edit-message-modal" style="display: none;">
    <div class="edit-message-modal-overlay"></div>
    <div class="edit-message-modal-container">
        <div class="edit-message-modal-header">
            <h3>✏️ Редактирование сообщения</h3>
            <button type="button" class="edit-message-modal-close" id="editMessageModalCloseBtn">✕</button>
        </div>
        <div class="edit-message-modal-body">
            <div class="edit-message-reply-info" id="editMessageReplyInfo" style="display: none;">
                <div class="edit-message-reply-badge">↩️ Ответ на сообщение</div>
                <div class="edit-message-reply-quote" id="editMessageReplyQuote"></div>
            </div>
            <textarea id="editMessageTextarea" class="edit-message-textarea" rows="5" placeholder="Введите текст сообщения..."></textarea>
            <div class="edit-message-warning" id="editMessageWarning" style="display: none;">⚠️ Текст не может быть пустым</div>
        </div>
        <div class="edit-message-modal-footer">
            <button type="button" class="edit-message-btn-cancel" id="editMessageCancelBtn">Отмена</button>
            <button type="button" class="edit-message-btn-save" id="editMessageSaveBtn">💾 Сохранить</button>
        </div>
    </div>
</div>




<style>
/* ==================== FORCE FIX: Input area position ==================== */
@media (max-width: 768px) {
    .message-input-area {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        background: #1e1e1e !important;
        border-top: 1px solid #2c2c2c !important;
        padding: 12px !important;
        padding-bottom: max(12px, env(safe-area-inset-bottom, 12px)) !important;
        z-index: 9999 !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    
    /* Убеждаемся, что body не мешает */
    body {
        position: relative !important;
        min-height: 100vh !important;
    }
    
    /* Отступ снизу для контента, чтобы не перекрывался панелью */
    #messages-area {
        padding-bottom: 100px !important;
    }
}

/* ==================== BLOCK START: Task details panel styles v1.0 ==================== */
/* ver.1.0 (2026-06-11) - Стили для немодальной панели деталей задачи */

.task-details-panel {
    position: fixed;
    top: 0;
    left: 0;
    width: 400px;
    max-width: 85vw;
    height: 100vh;
    background: #1a1a2e;
    border-right: 1px solid #2c2c3e;
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
    z-index: 2000;
    transform: translateX(-100%);
    transition: transform 0.25s ease;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.task-details-panel.open {
    transform: translateX(0);
}

/* На мобильных устройствах панель поверх чата */
@media (max-width: 768px) {
    .task-details-panel {
        width: 100%;
        max-width: 100%;
        z-index: 2100;
        transform: translateX(-100%);
    }
    
    .task-details-panel.open {
        transform: translateX(0);
    }
}

.task-details-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1999;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}

.task-details-overlay.open {
    opacity: 1;
    pointer-events: auto;
}

.task-details-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-bottom: 1px solid rgba(79, 124, 255, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.task-details-header h3 {
    margin: 0;
    font-size: 18px;
    color: #e9eefc;
    font-weight: 600;
    word-break: break-word;
    flex: 1;
}

.task-details-close {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #9ca3af;
    font-size: 20px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    margin-left: 12px;
    flex-shrink: 0;
}

.task-details-close:hover {
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
}

.task-details-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

.task-details-field {
    margin-bottom: 20px;
}

.task-details-field-label {
    font-size: 11px;
    text-transform: uppercase;
    color: #6b7280;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    font-weight: 600;
}

.task-details-field-value {
    font-size: 14px;
    color: #e9eefc;
    word-break: break-word;
    white-space: pre-wrap;
    line-height: 1.5;
}

.task-details-description {
    background: #0f172a;
    border-radius: 12px;
    padding: 14px;
    border-left: 3px solid #4f7cff;
    margin-top: 8px;
}

.task-details-description-empty {
    color: #6b7280;
    font-style: italic;
}

.task-details-files-title {
    font-size: 13px;
    font-weight: 600;
    color: #9bb7ff;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.task-details-files-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
}

.task-details-file-item {
    background: #1e293b;
    border-radius: 8px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #e9eefc;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid #334155;
}

.task-details-file-item:hover {
    background: #2d3a5e;
    transform: scale(1.02);
}

.task-details-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    flex-wrap: wrap;
}

.task-details-btn-primary {
    background: #4f7cff;
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    color: white;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.task-details-btn-primary:hover {
    background: #3b6ef5;
    transform: translateY(-1px);
}

.task-details-btn-secondary {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 10px 20px;
    border-radius: 10px;
    color: #e9eefc;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.task-details-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.15);
}

.task-details-loading {
    text-align: center;
    padding: 40px;
    color: #6b7280;
}

.task-details-error {
    text-align: center;
    padding: 40px;
    color: #f87171;
}

/* Для ссылок внутри описания */
.task-details-field-value .external-link {
    display: inline-block;
    background: rgba(79, 124, 255, 0.15);
    padding: 2px 8px;
    border-radius: 16px;
    font-size: 12px;
    text-decoration: none;
    margin: 2px;
    border-left: 2px solid #f59e0b;
    color: #f59e0b;
    word-break: break-all;
}

.task-details-field-value .external-link:hover {
    background: rgba(79, 124, 255, 0.3);
    text-decoration: none;
}

/* ==================== BLOCK END: Task details panel styles v1.0 ==================== */
</style>


<!-- ==================== BLOCK START: Task details panel v1.0 ==================== -->
<!-- ver.1.0 (2026-06-11) - Немодальная панель с деталями задачи -->

<div id="taskDetailsOverlay" class="task-details-overlay"></div>
<div id="taskDetailsPanel" class="task-details-panel">
    <div class="task-details-header">
        <h3 id="taskDetailsTitle">📋 Информация о задаче</h3>
        <button class="task-details-close" id="taskDetailsCloseBtn">✕</button>
    </div>
    <div class="task-details-body" id="taskDetailsBody">
        <div class="task-details-loading">⏳ Загрузка информации о задаче...</div>
    </div>
</div>

<script nonce="<?= CSP_NONCE ?>">
// ==================== BLOCK START: Task details panel functionality v1.2 ====================
// ver.1.0 (2026-06-11) - Базовая реализация панели деталей задачи
// ver.1.1 (2026-06-11) - Добавлена функция parseDescriptionLinks для ссылок в описании
// ver.1.2 (2026-06-11) - Исправлено форматирование дат (используется formatDate из глобальной области)

// Функция для парсинга ссылок в описании (аналог parseDescriptionLinks из projects.php)
function parseTaskDetailsLinks(text) {
    if (!text) return '';
    
    // Экранируем HTML
    var div = document.createElement('div');
    div.textContent = text;
    var escaped = div.innerHTML;
    
    // URL regex
    var urlRegex = /(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[^\s<>\[\]\(\)\{\}]+/gi;
    
    escaped = escaped.replace(urlRegex, function(match) {
        var lowerMatch = match.toLowerCase();
        // Блокируем опасные схемы
        if (lowerMatch.indexOf('javascript:') === 0 || 
            lowerMatch.indexOf('data:') === 0 || 
            lowerMatch.indexOf('vbscript:') === 0) {
            logDebug('[TASK_DETAILS] Blocked dangerous URL: ' + match.substring(0, 100));
            return match;
        }
        
        var safeUrl = match.replace(/['"]/g, '').replace(/[<>]/g, '');
        var isTelegram = lowerMatch.indexOf('tg://') === 0 || lowerMatch.indexOf('telegram://') === 0;
        var linkClass = isTelegram ? 'external-link telegram-link' : 'external-link';
        var targetAttr = (lowerMatch.indexOf('mailto:') === 0 || lowerMatch.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
        
        var displayText = match;
        if (displayText.length > 80) {
            displayText = displayText.substring(0, 70) + '…' + displayText.substring(displayText.length - 10);
        }
        
        return '<a href="' + safeUrl + '" class="' + linkClass + '"' + targetAttr + '>' + displayText + '</a>';
    });
    
    // Преобразуем переносы строк в <br>
    escaped = escaped.replace(/\n/g, '<br>');
    
    return escaped;
}

// Глобальная переменная для хранения состояния панели
var taskDetailsPanel = {
    isOpen: false,
    currentTaskUuid: null,
    isLoading: false
};

// Функция для форматирования даты (использует глобальную formatDate если доступна)
function formatTaskDate(ts) {
    if (!ts || ts === null || ts === 0) return '';
    var d = new Date(parseInt(ts));
    if (isNaN(d.getTime())) return '';
    var tz = -d.getTimezoneOffset() / 60;
    var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}) + ' (' + tzName + ')';
}

// ==================== BLOCK START: loadTaskDetails v1.3 (with project users) ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ДОБАВЛЕНО СОХРАНЕНИЕ project_uuid
// ver.1.2 (2026-06-14) - ДОБАВЛЕНО ЛОГГИРОВАНИЕ ДЛЯ ОТЛАДКИ
// ver.1.3 (2026-06-14) - ДОБАВЛЕНА ЗАГРУЗКА СПИСКА ПОЛЬЗОВАТЕЛЕЙ ПРОЕКТА ДЛЯ SELECT ИСПОЛНИТЕЛЯ

function loadTaskDetails(taskUuid) {
    if (!taskUuid) {
        logDebug('[TASK_DETAILS] No task UUID provided');
        return;
    }
    
    if (taskDetailsPanel.isLoading) {
        logDebug('[TASK_DETAILS] Already loading, skipping');
        return;
    }
    
    taskDetailsPanel.isLoading = true;
    taskDetailsPanel.currentTaskUuid = taskUuid;
    
    var bodyContainer = document.getElementById('taskDetailsBody');
    if (bodyContainer) {
        bodyContainer.innerHTML = '<div class="task-details-loading">⏳ Загрузка информации о задаче...</div>';
    }
    
    logDebug('[TASK_DETAILS] Loading details for task:', taskUuid);
    
    var formData = new URLSearchParams();
    formData.append('action', 'get_task_info');
    formData.append('task_uuid', taskUuid);
    formData.append('ajax_mode', '1');
    if (typeof addCsrfToUrlParams === 'function') {
        addCsrfToUrlParams(formData);
    }
    
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
        logDebug('[TASK_DETAILS] Response received, success:', data.success);
        
        if (data.success && data.task) {
            // Сохраняем данные задачи в глобальную переменную
            window.currentTaskDetailsData = data.task;
            window.currentTaskDetailsProjectUuid = data.task.project_uuid;
            window.currentTaskDetailsUuid = data.task.uuid;
            logDebug('[TASK_DETAILS] Task loaded, project_uuid:', data.task.project_uuid);
            
            // ========== v1.3: Загружаем список пользователей проекта для select исполнителя ==========
            var projectUuid = data.task.project_uuid;
            if (projectUuid && (!window.projectUsersList || window.projectUsersListProjectUuid !== projectUuid)) {
                logDebug('[TASK_DETAILS] Loading project users for project:', projectUuid);
                
                var usersFormData = new URLSearchParams();
                usersFormData.append('action', 'get_project_users');
                usersFormData.append('project_uuid', projectUuid);
                usersFormData.append('ajax_mode', '1');
                if (typeof addCsrfToUrlParams === 'function') {
                    addCsrfToUrlParams(usersFormData);
                }
                
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
                        logDebug('[TASK_DETAILS] Loaded ' + usersData.users.length + ' users for project:', projectUuid);
                    } else {
                        window.projectUsersList = [];
                        window.projectUsersListProjectUuid = null;
                        logDebug('[TASK_DETAILS] No users loaded or error:', usersData.error);
                    }
                    // Рендерим панель после загрузки пользователей
                    renderTaskDetails(data.task);
                })
                .catch(function(err) {
                    logDebug('[TASK_DETAILS] Error loading project users:', err);
                    window.projectUsersList = [];
                    window.projectUsersListProjectUuid = null;
                    // Всё равно рендерим панель, но без списка пользователей
                    renderTaskDetails(data.task);
                });
            } else {
                // Пользователи уже загружены или проект не указан
                if (window.projectUsersList && window.projectUsersListProjectUuid === projectUuid) {
                    logDebug('[TASK_DETAILS] Using cached project users, count:', window.projectUsersList.length);
                }
                renderTaskDetails(data.task);
            }
        } else {
            if (bodyContainer) {
                bodyContainer.innerHTML = '<div class="task-details-error">❌ ' + escapeHtml(data.error || 'Задача не найдена') + '</div>';
            }
            logDebug('[TASK_DETAILS] Task not found or error:', data.error);
        }
    })
    .catch(function(err) {
        taskDetailsPanel.isLoading = false;
        logError('[TASK_DETAILS] Error:', err.message);
        if (bodyContainer) {
            bodyContainer.innerHTML = '<div class="task-details-error">❌ Ошибка загрузки: ' + escapeHtml(err.message) + '</div>';
        }
    });
}
// ==================== BLOCK END: loadTaskDetails v1.3 ====================


// ==================== BLOCK START: Helper functions for task details panel v1.0 ====================
// ver.1.0 (2026-06-14) - ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ ПАНЕЛИ ДЕТАЛЕЙ ЗАДАЧИ
// Функции скопированы из projects.php, так как они не доступны в глобальной области видимости messages.php

// Преобразование UTC timestamp в локальный datetime-local формат
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

// Форматирование размера файла (копия из глобальной области)
function formatFileSize(bytes) {
    if (bytes === undefined || bytes === null) return '0 B';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

// Получение иконки файла по имени
function getFileIconFromName(filename) {
    if (!filename) return '📎';
    var ext = filename.split('.').pop().toLowerCase();
    var icons = {
        'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'webp': '🖼️',
        'pdf': '📄', 'doc': '📝', 'docx': '📝', 'xls': '📊', 'xlsx': '📊',
        'zip': '📦', 'rar': '📦', '7z': '📦', 'mp3': '🎵', 'mp4': '🎬',
        'avi': '🎬', 'txt': '📃', 'md': '📃'
    };
    return icons[ext] || '📎';
}

// Форматирование даты для отображения в панели
function formatTaskDate(ts) {
    if (!ts || ts === null || ts === 0) return '';
    var d = new Date(parseInt(ts));
    if (isNaN(d.getTime())) return '';
    var tz = -d.getTimezoneOffset() / 60;
    var tzName = tz === 3 ? 'MSK' : ((tz >= 0 ? '+' : '') + tz);
    return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}) + ' (' + tzName + ')';
}

// Парсинг ссылок в описании (безопасная версия)
function parseTaskDetailsLinks(text) {
    if (!text) return '';
    
    // Экранируем HTML
    var div = document.createElement('div');
    div.textContent = text;
    var escaped = div.innerHTML;
    
    // URL regex
    var urlRegex = /(?:https?:\/\/|tg:\/\/|telegram:\/\/|mailto:|tel:|ftp:\/\/|ws:\/\/|wss:\/\/|magnet:|skype:|viber:|whatsapp:|signal:)[^\s<>\[\]\(\)\{\}]+/gi;
    
    escaped = escaped.replace(urlRegex, function(match) {
        var lowerMatch = match.toLowerCase();
        // Блокируем опасные схемы
        if (lowerMatch.indexOf('javascript:') === 0 || 
            lowerMatch.indexOf('data:') === 0 || 
            lowerMatch.indexOf('vbscript:') === 0) {
            logDebug('[TASK_DETAILS] Blocked dangerous URL: ' + match.substring(0, 100));
            return match;
        }
        
        var safeUrl = match.replace(/['"]/g, '').replace(/[<>]/g, '');
        var isTelegram = lowerMatch.indexOf('tg://') === 0 || lowerMatch.indexOf('telegram://') === 0;
        var linkClass = isTelegram ? 'external-link telegram-link' : 'external-link';
        var targetAttr = (lowerMatch.indexOf('mailto:') === 0 || lowerMatch.indexOf('tel:') === 0) ? '' : ' target="_blank" rel="noopener noreferrer"';
        
        var displayText = match;
        if (displayText.length > 80) {
            displayText = displayText.substring(0, 70) + '…' + displayText.substring(displayText.length - 10);
        }
        
        return '<a href="' + safeUrl + '" class="' + linkClass + '"' + targetAttr + '>' + displayText + '</a>';
    });
    
    // Преобразуем переносы строк в <br>
    escaped = escaped.replace(/\n/g, '<br>');
    
    return escaped;
}
// ==================== BLOCK END: Helper functions for task details panel v1.0 ====================


// ==================== BLOCK START: renderTaskDetails v2.3 (with assignee field) ====================
// ver.2.2 - Базовая версия с формой редактирования
// ver.2.3 (2026-06-14) - ДОБАВЛЕНО ПОЛЕ "ИСПОЛНИТЕЛЬ" В ФОРМУ РЕДАКТИРОВАНИЯ

function renderTaskDetails(task) {
    logDebug('[TASK_DETAILS] v2.3 Rendering task details for:', task.uuid);
    
    var statusText = (task.status === 1) ? '✅ Выполнена' : '🟢 Активна';
    var statusClass = (task.status === 1) ? 'completed' : '';
    
    var assigneeText = (task.assignee_name || task.assignee_login) ? 
        escapeHtml(task.assignee_name || task.assignee_login) : 'Не назначен';
    
    var descrHtml = '';
    var descrText = task.descr || '';
    if (descrText.trim() !== '') {
        descrHtml = '<div class="task-details-description">' + parseTaskDetailsLinks(descrText) + '</div>';
    } else {
        descrHtml = '<div class="task-details-description-empty">Нет описания</div>';
    }
    
    // Генерируем HTML для выпадающего списка исполнителей
    var assigneeOptionsHtml = '<option value="">Не назначен</option>';
    if (window.projectUsersList && window.projectUsersList.length > 0) {
        for (var i = 0; i < window.projectUsersList.length; i++) {
            var user = window.projectUsersList[i];
            var selected = (user.uuid === task.assigned_to_uuid) ? ' selected' : '';
            var userName = user.name || user.login;
            assigneeOptionsHtml += '<option value="' + escapeHtml(user.uuid) + '"' + selected + '>' + escapeHtml(userName) + '</option>';
        }
    }
    
    // ========== ФОРМА РЕДАКТИРОВАНИЯ (скрыта по умолчанию) ==========
    var editFormHtml = `
        <div id="task-details-edit-form" style="display: none;">
            <div class="form-group">
                <label>Описание</label>
                <textarea id="task-details-descr" class="task-details-textarea" rows="5">` + escapeHtml(descrText) + `</textarea>
            </div>
            <div class="form-group">
                <label>Исполнитель</label>
                <select id="task-details-assignee" class="task-details-input">` + assigneeOptionsHtml + `</select>
            </div>
            <div class="form-group">
                <label>Дата начала (в вашем часовом поясе)</label>
                <input type="datetime-local" id="task-details-time-start" class="task-details-input" value="` + utcToLocalDatetimeString(task.time_start_utc) + `">
            </div>
            <div class="form-group">
                <label>Плановое окончание (в вашем часовом поясе)</label>
                <input type="datetime-local" id="task-details-time-end" class="task-details-input" value="` + utcToLocalDatetimeString(task.time_end_plan_utc) + `">
            </div>
            <div class="file-manager" id="task-details-file-manager">
                <label>📎 Прикреплённые файлы</label>
                <div class="file-list" id="task-details-files-list"></div>
                <div class="upload-area" style="margin-top: 12px; display: flex; gap: 8px;">
                    <input type="file" id="task-details-file-upload" accept="*/*" style="flex: 1;">
                    <button type="button" class="btn-secondary" onclick="uploadTaskFileFromDetails()">📤 Загрузить</button>
                </div>
            </div>
        </div>
    `;
    
    // ========== ПОКАЗ РОДИТЕЛЬСКИХ ЗАДАЧ ==========
    var parentsHtml = '';
    var parentChain = task.parent_chain || [];
    var parentTasks = task.parent_tasks || {};
    
    if (parentChain.length > 0) {
        parentsHtml = '<div class="task-details-field">';
        parentsHtml += '<div class="task-details-field-label">📁 Родительские задачи</div>';
        parentsHtml += '<div class="task-details-field-value">';
        
        for (var i = 0; i < parentChain.length; i++) {
            var parentUuid = parentChain[i];
            var parentData = parentTasks[parentUuid];
            var parentTitle = parentData ? parentData.title : 'Задача';
            var isCompleted = parentData ? (parentData.status === 1) : false;
            var completedClass = isCompleted ? ' style="text-decoration: line-through; opacity: 0.7;"' : '';
            var parentLink = window.location.origin + (window.APP_BASE || '') + '/projects.php?task=' + parentUuid;
            
            parentsHtml += '<div style="margin-bottom: 8px; padding-left: ' + (i * 20) + 'px;">';
            parentsHtml += '<span style="color: #9bb7ff;">↳</span> ';
            parentsHtml += '<a href="' + parentLink + '" target="_blank" rel="noopener noreferrer" style="color: #e9eefc; text-decoration: none; border-bottom: 1px dashed #4f7cff;"' + completedClass + '>';
            parentsHtml += escapeHtml(parentTitle);
            parentsHtml += '</a>';
            parentsHtml += '</div>';
        }
        
        parentsHtml += '</div></div>';
        logDebug('[TASK_DETAILS] Added parents HTML with ' + parentChain.length + ' parents');
    }
    
    // Формируем HTML для файлов (режим просмотра)
    var files = task.files || [];
    var filesViewHtml = '<div class="task-details-files-title">📎 Прикреплённые файлы (' + files.length + ')</div>';
    if (files.length > 0) {
        filesViewHtml += '<div class="task-details-files-list" id="task-details-files-view-list">';
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var fileIcon = getFileIconFromName(file.name);
            var safeName = escapeHtml(file.name).replace(/'/g, "\\'");
            filesViewHtml += '<div class="task-details-file-item" onclick="showFilePreview(\'' + 
                escapeHtml(file.uuid) + '\', \'' + safeName + '\', ' + (file.size_bytes || 0) + ', \'' + 
                escapeHtml(file.mime || '') + '\')" title="' + safeName + '">';
            filesViewHtml += fileIcon + ' ' + escapeHtml(file.name) + ' (' + (file.size || formatFileSize(file.size_bytes || 0)) + ')';
            filesViewHtml += '</div>';
        }
        filesViewHtml += '</div>';
    } else {
        filesViewHtml += '<div class="task-details-description-empty">Нет прикреплённых файлов</div>';
    }
    
    // Ссылка на задачу в projects.php
    var taskUrl = window.location.origin + (window.APP_BASE || '') + '/projects.php?task=' + task.uuid;
    
    // Кнопки действий
    var actionButtonsHtml = `
        <div class="task-details-actions" id="task-details-view-actions">
            <button class="task-details-btn-primary" id="task-details-edit-btn">✏️ Редактировать</button>
            <a href="` + taskUrl + `" class="task-details-btn-secondary" target="_blank" rel="noopener noreferrer">📋 Перейти к задаче</a>
        </div>
        <div class="task-details-actions" id="task-details-edit-actions" style="display: none;">
            <button class="task-details-btn-primary" id="task-details-save-btn">💾 Сохранить</button>
            <button class="task-details-btn-secondary" id="task-details-cancel-btn">❌ Отмена</button>
        </div>
    `;
    
    // Собираем весь HTML для панели
    var html = '';
    
    // Блок с родителями
    if (parentsHtml) {
        html += parentsHtml;
    }
    
    // Блок статуса и исполнителя (режим просмотра)
    html += '<div id="task-details-view-mode">';
    html += '<div class="task-details-field">';
    html += '<div class="task-details-field-label">📋 Статус</div>';
    html += '<div class="task-details-field-value ' + statusClass + '">' + statusText + '</div>';
    html += '</div>';
    
    html += '<div class="task-details-field">';
    html += '<div class="task-details-field-label">👤 Исполнитель</div>';
    html += '<div class="task-details-field-value">' + assigneeText + '</div>';
    html += '</div>';
    
    if (task.time_start) {
        html += '<div class="task-details-field">';
        html += '<div class="task-details-field-label">🚀 Дата начала</div>';
        html += '<div class="task-details-field-value">' + formatTaskDate(task.time_start) + '</div>';
        html += '</div>';
    }
    
    if (task.time_end_plan) {
        var isOverdue = false;
        if (task.status !== 1 && task.time_end_plan) {
            var deadlineDate = parseInt(task.time_end_plan);
            isOverdue = deadlineDate < Date.now();
        }
        var overdueClass = isOverdue ? ' style="color: #f87171;"' : '';
        html += '<div class="task-details-field">';
        html += '<div class="task-details-field-label">📅 Плановое окончание</div>';
        html += '<div class="task-details-field-value"' + overdueClass + '>' + formatTaskDate(task.time_end_plan);
        if (isOverdue) html += ' ⚠️ Просрочено';
        html += '</div></div>';
    }
    
    html += '<div class="task-details-field">';
    html += '<div class="task-details-field-label">📝 Описание</div>';
    html += descrHtml;
    html += '</div>';
    
    html += '<div class="task-details-field">';
    html += filesViewHtml;
    html += '</div>';
    html += '</div>'; // закрываем view-mode
    
    // Режим редактирования (скрыт)
    html += '<div id="task-details-edit-mode" style="display: none;">';
    html += editFormHtml;
    html += '</div>';
    
    // Кнопки действий
    html += actionButtonsHtml;
    
    var bodyContainer = document.getElementById('taskDetailsBody');
    if (bodyContainer) {
        bodyContainer.innerHTML = html;
        
        // Рендерим список файлов в режиме редактирования
        renderTaskDetailsFilesList(files);
        
        // Назначаем обработчики
        var editBtn = document.getElementById('task-details-edit-btn');
        if (editBtn) editBtn.onclick = function() { enterTaskEditMode(task); };
        
        var saveBtn = document.getElementById('task-details-save-btn');
        if (saveBtn) saveBtn.onclick = function() { saveTaskDetails(task.uuid); };
        
        var cancelBtn = document.getElementById('task-details-cancel-btn');
        if (cancelBtn) cancelBtn.onclick = function() { cancelTaskEditMode(task); };
        
        // Обработчик для загрузки файлов по Enter в поле выбора файла
        var fileUploadInput = document.getElementById('task-details-file-upload');
        if (fileUploadInput) {
            fileUploadInput.onkeypress = function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    uploadTaskFileFromDetails();
                }
            };
        }
    }
    
    logDebug('[TASK_DETAILS] Rendering complete');
}
// ==================== BLOCK END: renderTaskDetails v2.3 ====================

// Вспомогательная функция для рендеринга списка файлов в режиме редактирования
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
            escapeHtml(file.uuid) + '\', \'' + escapeHtml(file.name).replace(/'/g, "\\'") + '\', ' + (file.size_bytes || 0) + ', \'' + 
            escapeHtml(file.mime || '') + '\')">';
        html += fileIcon + ' ' + escapeHtml(file.name) + ' (' + (file.size || formatFileSize(file.size_bytes || 0)) + ')';
        html += '</div>';
        html += '<button class="btn-danger" style="padding: 4px 8px; font-size: 11px;" onclick="detachTaskFileFromDetails(\'' + escapeHtml(file.uuid) + '\')">🗑️</button>';
        html += '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
}
// ==================== BLOCK END: renderTaskDetails v2.0 ====================

// ==================== BLOCK START: enterTaskEditMode v1.2 (with assignee) ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ИСПРАВЛЕНА ОШИБКА: теперь правильно показывает форму редактирования
// ver.1.2 (2026-06-14) - ДОБАВЛЕНА УСТАНОВКА ТЕКУЩЕГО ИСПОЛНИТЕЛЯ В SELECT

function enterTaskEditMode(task) {
    logDebug('[TASK_DETAILS_EDIT] Entering edit mode for task:', task.uuid);
    
    // Скрываем режим просмотра, показываем режим редактирования
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
    
    // Заполняем поля формы текущими данными
    var descrField = document.getElementById('task-details-descr');
    var assigneeField = document.getElementById('task-details-assignee');
    var timeStartField = document.getElementById('task-details-time-start');
    var timeEndField = document.getElementById('task-details-time-end');
    
    if (descrField) descrField.value = task.descr || '';
    if (assigneeField) assigneeField.value = task.assigned_to_uuid || '';
    if (timeStartField) timeStartField.value = utcToLocalDatetimeString(task.time_start_utc);
    if (timeEndField) timeEndField.value = utcToLocalDatetimeString(task.time_end_plan_utc);
    
    // Обновляем список файлов
    var files = task.files || [];
    if (typeof renderTaskDetailsFilesList === 'function') {
        renderTaskDetailsFilesList(files);
    }
    
    logDebug('[TASK_DETAILS_EDIT] Edit mode activated');
}
// ==================== BLOCK END: enterTaskEditMode v1.2 ====================

function cancelTaskEditMode(originalTask) {
    logDebug('[TASK_DETAILS_EDIT] Cancelling edit mode for task:', originalTask.uuid);
    
    // Переключаем обратно в режим просмотра
    var viewMode = document.getElementById('task-details-view-mode');
    var editMode = document.getElementById('task-details-edit-mode');
    var viewActions = document.getElementById('task-details-view-actions');
    var editActions = document.getElementById('task-details-edit-actions');
    
    if (viewMode) viewMode.style.display = 'block';
    if (editMode) editMode.style.display = 'none';
    if (viewActions) viewActions.style.display = 'flex';
    if (editActions) editActions.style.display = 'none';
    
    // Восстанавливаем исходные данные в режиме просмотра (перерисовываем)
    renderTaskDetails(originalTask);
    
    logDebug('[TASK_DETAILS_EDIT] Edit mode cancelled');
}

// ==================== BLOCK START: saveTaskDetails v1.3 (FIXED) ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ИСПРАВЛЕНА ОШИБКА "Ошибка обработки ответа сервера"
// ver.1.2 (2026-06-14) - ДОБАВЛЕНО ПОЛЕ "ИСПОЛНИТЕЛЬ" ПРИ СОХРАНЕНИИ
// ver.1.3 (2026-06-14) - ИСПРАВЛЕНИЕ: БЕРЁМ ЧИСТЫЙ ЗАГОЛОВОК ИЗ currentTaskDetailsCleanTitle
//                       - Удалено получение заголовка из DOM (который мог содержать счётчик [число])
//                       - Добавлено логирование для отладки

function saveTaskDetails(taskUuid) {
    logDebug('[TASK_DETAILS_EDIT] Saving task details for:', taskUuid);
    
    if (!taskUuid) {
        logError('[TASK_DETAILS_EDIT] No task UUID provided');
        showAlert('Ошибка: задача не определена', 'error');
        return;
    }
    
    var newDescr = document.getElementById('task-details-descr') ? document.getElementById('task-details-descr').value : '';
    var newAssigneeUuid = document.getElementById('task-details-assignee') ? document.getElementById('task-details-assignee').value : '';
    var localStart = document.getElementById('task-details-time-start') ? document.getElementById('task-details-time-start').value : '';
    var localEnd = document.getElementById('task-details-time-end') ? document.getElementById('task-details-time-end').value : '';
    
    // ========== ИСПРАВЛЕНИЕ v1.3: БЕРЁМ ЧИСТЫЙ ЗАГОЛОВОК ИЗ СОХРАНЁННОЙ ПЕРЕМЕННОЙ ==========
    // Раньше заголовок брался из DOM (taskDetailsTitle), который мог содержать счётчик [число]
    // Это приводило к сохранению счётчика обратно в БД и появлению "[9] [9]" в названии задачи
    var taskTitle = window.currentTaskDetailsCleanTitle || '';
    
    if (!taskTitle && window.currentTaskDetailsData) {
        taskTitle = window.currentTaskDetailsData.title || '';
        logDebug('[TASK_DETAILS_EDIT] Got title from currentTaskDetailsData:', taskTitle);
    }
    
    if (!taskTitle) {
        // Fallback: пробуем извлечь из DOM, но ОБЯЗАТЕЛЬНО очищаем от счётчика
        var titleHeader = document.getElementById('taskDetailsTitle');
        if (titleHeader) {
            taskTitle = titleHeader.innerText.replace(/^📋\s*/, '').replace(/\s*\[\d+\]\s*$/, '').trim();
            logDebug('[TASK_DETAILS_EDIT] Got title from DOM (fallback, cleaned):', taskTitle);
        }
    }
    
    logDebug('[TASK_DETAILS_EDIT] Final clean task title:', taskTitle);
    // ====================================================================================
    
    // Получаем project_uuid из сохранённой переменной или из данных задачи
    var projectUuid = window.currentTaskDetailsProjectUuid;
    if (!projectUuid && window.currentTaskDetailsData) {
        projectUuid = window.currentTaskDetailsData.project_uuid;
    }
    
    logDebug('[TASK_DETAILS_EDIT] Task title:', taskTitle);
    logDebug('[TASK_DETAILS_EDIT] Project UUID:', projectUuid);
    logDebug('[TASK_DETAILS_EDIT] Assignee UUID:', newAssigneeUuid);
    
    // Показываем индикатор загрузки на кнопке сохранения
    var saveBtn = document.getElementById('task-details-save-btn');
    var originalBtnText = saveBtn ? saveBtn.innerHTML : '💾 Сохранить';
    if (saveBtn) {
        saveBtn.innerHTML = '⏳ Сохранение...';
        saveBtn.disabled = true;
    }
    
    var formData = new URLSearchParams();
    formData.append('action', 'edit_task');
    formData.append('uuid', taskUuid);
    formData.append('project_uuid', projectUuid || '');
    formData.append('parent_task_uuid', '');
    formData.append('title', taskTitle);
    formData.append('descr', newDescr);
    formData.append('assigned_to_uuid', newAssigneeUuid);
    formData.append('time_start', localStart);
    formData.append('time_end_plan', localEnd);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    logDebug('[TASK_DETAILS_EDIT] Sending request to:', window.APP_BASE + '/projects.php');
    
    fetch(window.APP_BASE + '/projects.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(response) {
        logDebug('[TASK_DETAILS_EDIT] Response status:', response.status);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    })
    .then(function(data) {
        logDebug('[TASK_DETAILS_EDIT] Response data:', data);
        
        if (saveBtn) {
            saveBtn.innerHTML = originalBtnText;
            saveBtn.disabled = false;
        }
        
        if (data.success) {
            logDebug('[TASK_DETAILS_EDIT] Task saved successfully');
            showAlert('Задача обновлена', 'success');
            
            if (data.task) {
                window.currentTaskDetailsData = data.task;
                window.currentTaskDetailsProjectUuid = data.task.project_uuid;
                // Обновляем сохранённый чистый заголовок из ответа сервера
                window.currentTaskDetailsCleanTitle = data.task.title || taskTitle;
                renderTaskDetails(data.task);
            } else {
                logDebug('[TASK_DETAILS_EDIT] No task data in response, reloading');
                loadTaskDetails(taskUuid);
            }
            
            var viewMode = document.getElementById('task-details-view-mode');
            var editMode = document.getElementById('task-details-edit-mode');
            var viewActions = document.getElementById('task-details-view-actions');
            var editActions = document.getElementById('task-details-edit-actions');
            if (viewMode) viewMode.style.display = 'block';
            if (editMode) editMode.style.display = 'none';
            if (viewActions) viewActions.style.display = 'flex';
            if (editActions) editActions.style.display = 'none';
            
            if (typeof window.refreshTaskInList === 'function') {
                window.refreshTaskInList(taskUuid);
            }
            
            if (window.currentTaskUuid === taskUuid) {
                var chatTitle = document.getElementById('chat-title');
                if (chatTitle && taskTitle) {
                    chatTitle.textContent = taskTitle;
                }
            }
        } else {
            logError('[TASK_DETAILS_EDIT] Save failed:', data.error);
            showAlert('Ошибка сохранения: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    })
    .catch(function(err) {
        logError('[TASK_DETAILS_EDIT] Network error:', err);
        showAlert('Ошибка сети при сохранении', 'error');
        if (saveBtn) {
            saveBtn.innerHTML = originalBtnText;
            saveBtn.disabled = false;
        }
    });
}
// ==================== BLOCK END: saveTaskDetails v1.3 ====================

function uploadTaskFileFromDetails() {
    var taskUuid = window.currentTaskDetailsUuid;
    if (!taskUuid) {
        showAlert('Ошибка: задача не определена', 'error');
        return;
    }
    
    var fileInput = document.getElementById('task-details-file-upload');
    var file = fileInput ? fileInput.files[0] : null;
    if (!file) {
        showAlert('Выберите файл', 'warning');
        return;
    }
    
    logDebug('[TASK_DETAILS_EDIT] Uploading file to task:', taskUuid, file.name);
    showAlert('⏳ Загрузка файла...', 'info');
    
    var formData = new FormData();
    formData.append('action', 'upload_task_file');
    formData.append('task_uuid', taskUuid);
    formData.append('ajax_mode', '1');
    formData.append('file', file);
    addCsrfToFormData(formData);
    
    fetch(window.APP_BASE + '/projects.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            logDebug('[TASK_DETAILS_EDIT] File uploaded successfully');
            showAlert('✓ Файл загружен', 'success');
            fileInput.value = '';
            
            // Обновляем задачу в панели
            if (window.currentTaskDetailsUuid) {
                loadTaskDetails(window.currentTaskDetailsUuid);
            }
            
            // Обновляем задачу в DOM projects.php
            if (typeof window.refreshTaskInList === 'function') {
                window.refreshTaskInList(taskUuid);
            }
        } else {
            logError('[TASK_DETAILS_EDIT] Upload failed:', data.error);
            showAlert('Ошибка загрузки: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    })
    .catch(function(err) {
        logError('[TASK_DETAILS_EDIT] Network error during upload:', err);
        showAlert('Ошибка сети при загрузке файла', 'error');
    });
}

function detachTaskFileFromDetails(fileUuid) {
    var taskUuid = window.currentTaskDetailsUuid;
    if (!taskUuid) {
        showAlert('Ошибка: задача не определена', 'error');
        return;
    }
    
    if (!confirm('Удалить этот файл из задачи? Он будет полностью удалён с сервера.')) {
        return;
    }
    
    logDebug('[TASK_DETAILS_EDIT] Detaching file:', fileUuid, 'from task:', taskUuid);
    showAlert('🗑️ Удаление файла...', 'info');
    
    var formData = new URLSearchParams();
    formData.append('action', 'detach_file_from_task');
    formData.append('task_uuid', taskUuid);
    formData.append('file_uuid', fileUuid);
    formData.append('ajax_mode', '1');
    addCsrfToUrlParams(formData);
    
    fetch(window.APP_BASE + '/projects.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            logDebug('[TASK_DETAILS_EDIT] File detached successfully');
            showAlert('✓ Файл удалён', 'success');
            
            // Обновляем задачу в панели
            if (window.currentTaskDetailsUuid) {
                loadTaskDetails(window.currentTaskDetailsUuid);
            }
            
            // Обновляем задачу в DOM projects.php
            if (typeof window.refreshTaskInList === 'function') {
                window.refreshTaskInList(taskUuid);
            }
        } else {
            logError('[TASK_DETAILS_EDIT] Detach failed:', data.error);
            showAlert('Ошибка удаления: ' + (data.error || 'Неизвестная ошибка'), 'error');
        }
    })
    .catch(function(err) {
        logError('[TASK_DETAILS_EDIT] Network error during detach:', err);
        showAlert('Ошибка сети при удалении файла', 'error');
    });
}
// ==================== BLOCK END: Task details edit functions v1.0 ====================

// Вспомогательная функция для получения иконки файла
function getFileIconFromName(filename) {
    if (!filename) return '📎';
    var ext = filename.split('.').pop().toLowerCase();
    var icons = {
        'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'webp': '🖼️',
        'pdf': '📄', 'doc': '📝', 'docx': '📝', 'xls': '📊', 'xlsx': '📊',
        'zip': '📦', 'rar': '📦', '7z': '📦', 'mp3': '🎵', 'mp4': '🎬',
        'avi': '🎬', 'txt': '📃', 'md': '📃'
    };
    return icons[ext] || '📎';
}

// ==================== BLOCK START: openTaskDetailsPanel v1.3 (FIXED) ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ДОБАВЛЕНО СОХРАНЕНИЕ project_uuid
// ver.1.2 (2026-06-14) - ИСПРАВЛЕНА ИНИЦИАЛИЗАЦИЯ project_uuid
// ver.1.3 (2026-06-14) - ИСПРАВЛЕНИЕ: ОЧИСТКА ЗАГОЛОВКА ОТ СЧЁТЧИКА СООБЩЕНИЙ [число]
//                       - Добавлено сохранение чистого заголовка в window.currentTaskDetailsCleanTitle
//                       - Добавлено логирование для отладки

function openTaskDetailsPanel(taskUuid, taskTitle) {
    logDebug('[TASK_DETAILS] Opening panel for task:', taskUuid, 'title:', taskTitle);
    
    if (!taskUuid) {
        logDebug('[TASK_DETAILS] No task UUID provided');
        return;
    }
    
    var panel = document.getElementById('taskDetailsPanel');
    var overlay = document.getElementById('taskDetailsOverlay');
    var titleElement = document.getElementById('taskDetailsTitle');
    
    if (!panel || !overlay) {
        logDebug('[TASK_DETAILS] Panel or overlay not found');
        return;
    }
    
    // ОЧИЩАЕМ заголовок от счётчика сообщений [число] в конце
    // Это предотвращает сохранение счётчика обратно в БД при редактировании
    var cleanTitle = taskTitle || 'Информация о задаче';
    cleanTitle = cleanTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
    
    logDebug('[TASK_DETAILS] Cleaned title: "' + cleanTitle + '" (original: "' + taskTitle + '")');
    
    // Обновляем заголовок в панели (чистый, без счётчика)
    if (titleElement) {
        titleElement.innerHTML = '📋 ' + cleanTitle;
    }
    
    // Сохраняем UUID текущей задачи в глобальную переменную
    window.currentTaskDetailsUuid = taskUuid;
    window.currentTaskDetailsData = null;
    window.currentTaskDetailsProjectUuid = null;
    
    // Сохраняем ЧИСТЫЙ заголовок для последующего использования в saveTaskDetails
    // Это критично: при сохранении задачи не должен использоваться заголовок со счётчиком
    window.currentTaskDetailsCleanTitle = cleanTitle;
    
    logDebug('[TASK_DETAILS] Saved clean title to window.currentTaskDetailsCleanTitle:', cleanTitle);
    
    // Загружаем данные
    loadTaskDetails(taskUuid);
    
    // Открываем панель
    panel.classList.add('open');
    overlay.classList.add('open');
    taskDetailsPanel.isOpen = true;
    
    // Блокируем скролл body на мобильных
    if (window.innerWidth <= 768) {
        document.body.style.overflow = 'hidden';
    }
}
// ==================== BLOCK END: openTaskDetailsPanel v1.3 ====================

// Функция закрытия панели деталей задачи
function closeTaskDetailsPanel() {
    logDebug('[TASK_DETAILS] Closing panel');
    
    var panel = document.getElementById('taskDetailsPanel');
    var overlay = document.getElementById('taskDetailsOverlay');
    
    if (panel) panel.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    
    taskDetailsPanel.isOpen = false;
    
    // Восстанавливаем скролл body на мобильных
    if (window.innerWidth <= 768) {
        document.body.style.overflow = '';
    }
}

// ==================== BLOCK START: setupTaskTitleClickHandler v1.3 (FIXED) ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ДОБАВЛЕНА ПРОВЕРКА НА СУЩЕСТВОВАНИЕ chat-title
// ver.1.3 (2026-06-14) - ИСПРАВЛЕНИЕ: ПЕРЕДАЁМ ЧИСТЫЙ ЗАГОЛОВОК (БЕЗ СЧЁТЧИКА) В openTaskDetailsPanel
//                       - Заголовок очищается от [число] перед передачей

function setupTaskTitleClickHandler() {
    var chatTitle = document.getElementById('chat-title');
    if (!chatTitle) {
        logDebug('[TASK_DETAILS] chat-title element not found, will retry');
        setTimeout(setupTaskTitleClickHandler, 500);
        return;
    }
    
    logDebug('[TASK_DETAILS] Setting up click handler for chat-title');
    
    chatTitle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var taskUuid = window.currentTaskUuid;
        var rawTaskTitle = this.textContent || 'Задача';
        
        logDebug('[TASK_DETAILS] Chat title clicked, raw title from DOM:', rawTaskTitle);
        
        // ОЧИЩАЕМ заголовок от счётчика сообщений [число] в конце
        // Это предотвращает передачу грязного заголовка в openTaskDetailsPanel
        var cleanTaskTitle = rawTaskTitle.replace(/\s*\[\d+\]\s*$/, '').trim();
        
        logDebug('[TASK_DETAILS] Cleaned title for panel:', cleanTaskTitle);
        
        if (taskUuid) {
            openTaskDetailsPanel(taskUuid, cleanTaskTitle);
        } else {
            logDebug('[TASK_DETAILS] No currentTaskUuid, falling back to navigation');
            var originalHref = this.getAttribute('href');
            if (originalHref && originalHref !== '#') {
                window.location.href = originalHref;
            }
        }
    });
    
    chatTitle.style.cursor = 'pointer';
    chatTitle.style.borderBottom = '1px dashed #70a0ff';
    
    logDebug('[TASK_DETAILS] Click handler attached to chat-title');
}
// ==================== BLOCK END: setupTaskTitleClickHandler v1.3 ====================

// Обработчик закрытия панели
function setupTaskDetailsCloseHandler() {
    var closeBtn = document.getElementById('taskDetailsCloseBtn');
    var overlay = document.getElementById('taskDetailsOverlay');
    
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            closeTaskDetailsPanel();
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            closeTaskDetailsPanel();
        });
    }
    
    // Закрытие по клавише Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && taskDetailsPanel.isOpen) {
            closeTaskDetailsPanel();
        }
    });
}

// ==================== BLOCK START: initTaskDetailsPanel v1.1 ====================
// ver.1.0 - Базовая версия
// ver.1.1 (2026-06-14) - ДОБАВЛЕНА ПРОВЕРКА НА СУЩЕСТВОВАНИЕ chat-title

function initTaskDetailsPanel() {
    logDebug('[TASK_DETAILS] Initializing panel');
    
    setupTaskTitleClickHandler();
    setupTaskDetailsCloseHandler();
    
    logDebug('[TASK_DETAILS] Initialization complete');
}

function setupTaskTitleClickHandler() {
    var chatTitle = document.getElementById('chat-title');
    if (!chatTitle) {
        logDebug('[TASK_DETAILS] chat-title element not found, will retry');
        setTimeout(setupTaskTitleClickHandler, 500);
        return;
    }
    
    logDebug('[TASK_DETAILS] Setting up click handler for chat-title');
    
    chatTitle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var taskUuid = window.currentTaskUuid;
        var taskTitle = this.textContent || 'Задача';
        
        logDebug('[TASK_DETAILS] Chat title clicked, taskUuid:', taskUuid);
        
        if (taskUuid) {
            openTaskDetailsPanel(taskUuid, taskTitle);
        } else {
            logDebug('[TASK_DETAILS] No currentTaskUuid, falling back to navigation');
            var originalHref = this.getAttribute('href');
            if (originalHref && originalHref !== '#') {
                window.location.href = originalHref;
            }
        }
    });
    
    chatTitle.style.cursor = 'pointer';
    chatTitle.style.borderBottom = '1px dashed #70a0ff';
    
    logDebug('[TASK_DETAILS] Click handler attached to chat-title');
}

function setupTaskDetailsCloseHandler() {
    var closeBtn = document.getElementById('taskDetailsCloseBtn');
    var overlay = document.getElementById('taskDetailsOverlay');
    
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            closeTaskDetailsPanel();
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            closeTaskDetailsPanel();
        });
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && taskDetailsPanel.isOpen) {
            closeTaskDetailsPanel();
        }
    });
}
// ==================== BLOCK END: initTaskDetailsPanel v1.1 ====================

// Запускаем инициализацию после загрузки DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initTaskDetailsPanel();
    });
} else {
    initTaskDetailsPanel();
}

// ==================== BLOCK END: Task details panel functionality v1.2 ====================
</script>
<!-- ==================== BLOCK END: Task details panel v1.0 ==================== -->

<?php require_once __DIR__ . '/layouts/page_end.php'; ?>