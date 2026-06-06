<?php
/**
 * generate_vapid_keys.php
 * Генерация VAPID-ключей для Web Push (в формате Base64URL)
 * ЗАПУСТИТЕ ОДИН РАЗ ЧЕРЕЗ БРАУЗЕР, ЗАТЕМ УДАЛИТЕ!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <title>Генерация VAPID-ключей</title>
    <style>
        body { font-family: monospace; background: #0a0a0a; color: #e0e0e0; padding: 20px; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .key-box { background: #1e1e1e; border: 1px solid #334155; border-radius: 8px; padding: 12px; margin: 16px 0; overflow-x: auto; white-space: pre-wrap; word-break: break-all; font-size: 12px; }
        .warning { background: #3a2a1a; border-left: 4px solid #f59e0b; padding: 12px; margin: 16px 0; }
        hr { border-color: #334155; margin: 20px 0; }
        button { background: #4f7cff; border: none; color: white; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; margin: 5px; }
        button:hover { background: #3b66e0; }
        .code-block { background: #0a0a2a; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto; }
    </style>
</head>
<body>
';

echo "<h1>🔐 Генерация VAPID-ключей для Web Push (Base64URL)</h1>\n";

if (!extension_loaded('openssl')) {
    echo '<div class="error">❌ Ошибка: Расширение OpenSSL не загружено. Обратитесь к хостинг-провайдеру.</div>';
    echo '</body></html>';
    exit;
}

/**
 * Генерация VAPID ключей в формате Base64URL (RFC 8291)
 * Приватный ключ: 32 байта (44 символа в Base64URL)
 * Публичный ключ: 65 байт (87 символов в Base64URL)
 */
function generateVapidKeysBase64() {
    // Генерируем EC ключ P-256
    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC
    ]);
    
    if (!$key) {
        throw new Exception("Ошибка генерации ключа: " . openssl_error_string());
    }
    
    // Получаем детали ключа
    $details = openssl_pkey_get_details($key);
    if (!$details || !isset($details['ec'])) {
        throw new Exception("Не удалось получить детали ключа");
    }
    
    // Публичный ключ в формате uncompressed (0x04 + X + Y) = 65 байт
    $publicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    
    // Приватный ключ - сырые 32 байта (d)
    $privateKey = $details['ec']['d'];
    
    // Конвертируем в Base64URL
    $publicKeyBase64 = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');
    $privateKeyBase64 = rtrim(strtr(base64_encode($privateKey), '+/', '-_'), '=');
    
    return [
        'public_key' => $publicKeyBase64,
        'private_key' => $privateKeyBase64
    ];
}

try {
    $keys = generateVapidKeysBase64();
    
    echo '<div class="success">✅ КЛЮЧИ УСПЕШНО СГЕНЕРИРОВАНЫ!</div>';
    echo '<div class="success">📏 Длина public key: ' . strlen($keys['public_key']) . ' символов (должно быть 87)</div>';
    echo '<div class="success">📏 Длина private key: ' . strlen($keys['private_key']) . ' символов (должно быть 43-44)</div>';
    echo '<hr>';
    
    echo '<h3>📌 ПУБЛИЧНЫЙ КЛЮЧ (скопируйте в config.php):</h3>';
    echo '<div class="key-box" id="public-key">' . htmlspecialchars($keys['public_key']) . '</div>';
    echo '<button onclick="copyToClipboard(\'public-key\')">📋 Копировать публичный ключ</button>';
    
    echo '<hr>';
    
    echo '<h3>🔒 ПРИВАТНЫЙ КЛЮЧ (скопируйте в config.php):</h3>';
    echo '<div class="key-box" id="private-key">' . htmlspecialchars($keys['private_key']) . '</div>';
    echo '<button onclick="copyToClipboard(\'private-key\')">📋 Копировать приватный ключ</button>';
    
    echo '<hr>';
    
    echo '<h3>⚠️ Инструкция по добавлению в lib/config.php:</h3>';
    echo '<div class="code-block">';
    echo '// ==================== VAPID-КЛЮЧИ ДЛЯ PUSH-УВЕДОМЛЕНИЙ ====================<br>';
    echo '// Сгенерированы ' . date('Y-m-d H:i:s') . '<br>';
    echo '$config[\'vapid_public_key\'] = \'' . htmlspecialchars($keys['public_key']) . '\';<br>';
    echo '$config[\'vapid_private_key\'] = \'' . htmlspecialchars($keys['private_key']) . '\';<br>';
    echo '$config[\'vapid_contact_email\'] = \'radioa@elec.ru\';<br>';
    echo '// ==================== КОНЕЦ VAPID-КЛЮЧЕЙ ====================</div>';
    
    echo '<div class="warning">';
    echo '⚠️ <strong>ВАЖНО:</strong><br>';
    echo '1. <strong>ЗАМЕНИТЕ</strong> существующие ключи в файле <strong>lib/config.php</strong> на новые<br>';
    echo '2. Удалите старые push-подписки из БД:<br>';
    echo '   <code>DELETE FROM push_subscriptions WHERE user_uuid = \'ваш_uuid\';</code><br>';
    echo '3. После сохранения <strong>УДАЛИТЕ ЭТОТ ФАЙЛ</strong> (generate_vapid_keys.php)!<br>';
    echo '</div>';
    
    echo '<hr>';
    echo '<h3>📋 ГОТОВЫЙ БЛОК ДЛЯ ВСТАВКИ В config.php:</h3>';
    echo '<div class="code-block" id="full-config-block">';
    echo "// ==================== VAPID-КЛЮЧИ ДЛЯ PUSH-УВЕДОМЛЕНИЙ ====================\n";
    echo "\$config['vapid_public_key'] = '" . $keys['public_key'] . "';\n";
    echo "\$config['vapid_private_key'] = '" . $keys['private_key'] . "';\n";
    echo "\$config['vapid_contact_email'] = 'radioa@elec.ru';\n";
    echo "// ==================== КОНЕЦ VAPID-КЛЮЧЕЙ ====================";
    echo '</div>';
    echo '<button onclick="copyToClipboard(\'full-config-block\')">📋 Копировать весь блок</button>';
    
    echo '<script>
        function copyToClipboard(elementId) {
            var text = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(text).then(function() {
                var btns = document.querySelectorAll("button");
                for(var i = 0; i < btns.length; i++) {
                    if(btns[i].innerHTML.indexOf("Копировать") !== -1) {
                        var originalText = btns[i].innerHTML;
                        btns[i].innerHTML = "✓ Скопировано!";
                        setTimeout(function(btn, orig) {
                            return function() { btn.innerHTML = orig; };
                        }(btns[i], originalText), 2000);
                        break;
                    }
                }
            }).catch(function() {
                alert("Не удалось скопировать. Скопируйте вручную.");
            });
        }
    </script>';
    
} catch (Exception $e) {
    echo '<div class="error">❌ ОШИБКА: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<div class="error">Подробности: ' . htmlspecialchars(openssl_error_string()) . '</div>';
    exit(1);
}

echo '</body></html>';