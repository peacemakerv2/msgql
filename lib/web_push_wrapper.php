<?php
// lib/web_push_wrapper.php - Обёртка для отправки Push (без внешних библиотек)
// v1.7 (2026-05-29) - ИСПРАВЛЕНА ПЕРЕДАЧА КЛЮЧЕЙ (p256dh и auth)

define('PUSH_USER_INACTIVITY_MS', 300000); // 5 минут
define('PUSH_LOG_RETENTION_MS', 86400000); // 24 часа

// Загружаем fallback (чистая реализация на openssl)
require_once __DIR__ . '/web_push_fallback.php';

log_debug("[PUSH_WRAPPER] Using fallback mode (no external libraries)");

class WebPushWrapper {
    
    private static function getVapidConfig() {
        global $config;
        return [
            'subject' => 'mailto:' . ($config['vapid_contact_email'] ?? 'admin@localhost'),
            'publicKey' => $config['vapid_public_key'] ?? '',
            'privateKey' => $config['vapid_private_key'] ?? ''
        ];
    }
    
    private static function canSendPush($user_uuid) {
        $db = msgql_db();
        if (!$db) {
            return ['can_send' => false, 'reason' => 'db_connection_failed'];
        }
        
        // ========== ВАЖНО: создаём таблицу ПЕРЕД запросом ==========
        self::ensurePushLogTable();
        

        $stmt = $db->prepare("
            SELECT time_last_dashboard_view, sound_enabled, sound_interval_sec 
            FROM users WHERE uuid = ?
        ");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            return ['can_send' => false, 'reason' => 'user_not_found'];
        }
        
        // Проверка активности (5 минут)
        $lastView = (int)($user['time_last_dashboard_view'] ?? 0);
        $now = msgql_now_ms();
        if ($lastView > 0 && ($now - $lastView) < PUSH_USER_INACTIVITY_MS) {
            return ['can_send' => false, 'reason' => 'user_active'];
        }
        
        // Проверка настройки звука
        if ((int)($user['sound_enabled'] ?? 0) !== 1) {
            return ['can_send' => false, 'reason' => 'sound_disabled'];
        }
        
        // Проверка интервала
        $intervalMs = ((int)($user['sound_interval_sec'] ?? 60)) * 1000;
        $sinceTime = $now - $intervalMs;
        
        self::ensurePushLogTable();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM push_sent_log 
            WHERE user_uuid = ? AND created_at > ?
        ");
        $stmt->bind_param("si", $user_uuid, $sinceTime);
        $stmt->execute();
        $recentCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();
        
        if ($recentCount > 0) {
            return ['can_send' => false, 'reason' => 'interval_limit'];
        }
        
        return ['can_send' => true, 'reason' => 'ok'];
    }
    
    private static function ensurePushLogTable() {
        $db = msgql_db();
        if (!$db) return;
        
        $db->query("
            CREATE TABLE IF NOT EXISTS `push_sent_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_uuid` char(36) NOT NULL,
                `type` varchar(50) NOT NULL,
                `task_uuid` char(36) DEFAULT NULL,
                `message_uuid` char(36) DEFAULT NULL,
                `created_at` bigint(20) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_uuid` (`user_uuid`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $olderThan = msgql_now_ms() - PUSH_LOG_RETENTION_MS;
        $stmt = $db->prepare("DELETE FROM push_sent_log WHERE created_at < ?");
        $stmt->bind_param("i", $olderThan);
        $stmt->execute();
        $stmt->close();
    }
    
    private static function logPushSent($user_uuid, $type, $task_uuid, $message_uuid) {
        $db = msgql_db();
        if (!$db) return false;
        
        $now = msgql_now_ms();
        $stmt = $db->prepare("
            INSERT INTO push_sent_log (user_uuid, type, task_uuid, message_uuid, created_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssi", $user_uuid, $type, $task_uuid, $message_uuid, $now);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    
    // ==================== BLOCK START: sendToUser v1.8 (with debug logging) ====================
    public static function sendToUser($user_uuid, $title, $body, $data = [], $forceSend = false) {
        log_debug("[PUSH_WRAPPER] ========== SENDING PUSH ==========");
        log_debug("[PUSH_WRAPPER] To user: {$user_uuid}, Force: " . ($forceSend ? 'YES' : 'NO'));
        log_debug("[PUSH_WRAPPER] Title: {$title}");
        log_debug("[PUSH_WRAPPER] Body: " . substr($body, 0, 100) . (strlen($body) > 100 ? '...' : ''));
        
        // 🔥 ДОБАВЛЯЕМ ЛОГИРОВАНИЕ UUID сообщения и задачи
        $message_uuid = $data['message_uuid'] ?? 'null';
        $task_uuid = $data['task_uuid'] ?? 'null';
        log_debug("[PUSH_WRAPPER] 📦 Data dump: " . json_encode($data, JSON_UNESCAPED_UNICODE));
        log_debug("[PUSH_WRAPPER] 🔑 message_uuid from data: " . $message_uuid);
        log_debug("[PUSH_WRAPPER] 🔑 task_uuid from data: " . $task_uuid);
        
        // 🔥 ПРОВЕРКА: не перепутаны ли UUID
        if ($message_uuid !== 'null' && $task_uuid !== 'null' && $message_uuid === $task_uuid) {
            log_error("[PUSH_WRAPPER] ⚠️ CRITICAL WARNING: message_uuid EQUALS task_uuid! This will break scroll to message!");
            log_error("[PUSH_WRAPPER] Both are: {$message_uuid}");
        }
        
        if (!$forceSend) {
            $canSend = self::canSendPush($user_uuid);
            if (!$canSend['can_send']) {
                log_debug("[PUSH_WRAPPER] ❌ Cannot send: " . ($canSend['reason'] ?? 'unknown'));
                return ['success' => false, 'reason' => $canSend['reason']];
            }
        }
        
        $db = msgql_db();
        $stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_uuid = ?");
        $stmt->bind_param("s", $user_uuid);
        $stmt->execute();
        $subscriptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($subscriptions)) {
            log_debug("[PUSH_WRAPPER] ❌ No subscriptions found for user: {$user_uuid}");
            return ['success' => false, 'reason' => 'no_subscriptions'];
        }
        
        log_debug("[PUSH_WRAPPER] Found " . count($subscriptions) . " subscription(s)");
        
        $vapidConfig = self::getVapidConfig();
        if (empty($vapidConfig['publicKey']) || empty($vapidConfig['privateKey'])) {
            log_error("[PUSH_WRAPPER] ❌ No VAPID keys configured");
            return ['success' => false, 'reason' => 'no_vapid_keys'];
        }
        
        $appBase = $GLOBALS['appBase'] ?? '';
        
        // 🔥 ФОРМИРУЕМ ПРАВИЛЬНЫЙ payload с раздельными UUID
        $payload_data = [
            'title' => $title,
            'body' => $body,
            'icon' => ($appBase ?: '') . '/favicon.ico',
            'badge' => ($appBase ?: '') . '/favicon.ico',
            'data' => $data,
            'url' => $data['url'] ?? ($appBase . '/messages.php'),
            'taskUuid' => $task_uuid,
            'messageUuid' => $message_uuid,  // 🔥 КЛЮЧЕВОЕ: используем message_uuid из data
            'notificationType' => $data['type'] ?? 'message',
            'appBase' => $appBase
        ];
        
        log_debug("[PUSH_WRAPPER] 📦 Final payload messageUuid: " . ($payload_data['messageUuid'] ?? 'null'));
        log_debug("[PUSH_WRAPPER] 📦 Final payload taskUuid: " . ($payload_data['taskUuid'] ?? 'null'));
        
        $payload = json_encode($payload_data, JSON_UNESCAPED_UNICODE);
        log_debug("[PUSH_WRAPPER] Payload JSON: " . substr($payload, 0, 300) . (strlen($payload) > 300 ? '...' : ''));
        
        $success_count = 0;
        
        foreach ($subscriptions as $sub) {
            log_debug("[PUSH_WRAPPER] Processing subscription, endpoint: " . substr($sub['endpoint'], 0, 80) . "...");
            log_debug("[PUSH_WRAPPER] p256dh: " . substr($sub['p256dh'], 0, 30) . "...");
            log_debug("[PUSH_WRAPPER] auth: " . substr($sub['auth'], 0, 20) . "...");
            
            $result = WebPushFallback::sendPushWithKeys(
                $sub['endpoint'],
                $payload,
                $sub['p256dh'],
                $sub['auth'],
                $vapidConfig['publicKey'],
                $vapidConfig['privateKey'],
                $vapidConfig['subject']
            );
            
            if ($result['success']) {
                $success_count++;
                log_debug("[PUSH_WRAPPER] ✅ Push sent successfully");
            } elseif ($result['reason'] === 'subscription_expired') {
                log_debug("[PUSH_WRAPPER] Subscription expired, deleting: " . $sub['endpoint']);
                $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_uuid = ?");
                $stmt->bind_param("ss", $sub['endpoint'], $user_uuid);
                $stmt->execute();
                $stmt->close();
            } else {
                log_debug("[PUSH_WRAPPER] ❌ Push failed: " . ($result['reason'] ?? 'unknown'));
            }
        }
        
        if ($success_count > 0) {
            self::logPushSent($user_uuid, $data['type'] ?? 'unknown', $data['task_uuid'] ?? null, $data['message_uuid'] ?? null);
        }
        
        log_debug("[PUSH_WRAPPER] ========== PUSH COMPLETE: {$success_count}/" . count($subscriptions) . " successful ==========");
        
        return [
            'success' => $success_count > 0,
            'total' => count($subscriptions),
            'successful' => $success_count
        ];
    }
// ==================== BLOCK END: sendToUser v1.8 ====================
    
    public static function sendTestPush($user_uuid, $test_message = null) {
        $testMessage = $test_message ?? 'Тестовое уведомление от ' . date('Y-m-d H:i:s');
        $testData = [
            'url' => ($GLOBALS['appBase'] ?? '') . '/messages.php',
            'type' => 'test',
            'is_test' => true
        ];
        return self::sendToUser($user_uuid, '🔔 Тестовое уведомление', $testMessage, $testData, true);
    }
}

function send_push_to_user($user_uuid, $title, $body, $data = [], $forceSend = false) {
    return WebPushWrapper::sendToUser($user_uuid, $title, $body, $data, $forceSend);
}

function send_test_push_to_user($user_uuid, $test_message = null) {
    return WebPushWrapper::sendTestPush($user_uuid, $test_message);
}