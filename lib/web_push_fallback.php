<?php
// lib/web_push_fallback.php - ИСПРАВЛЕННАЯ ВЕРСИЯ
// v1.3 (2026-05-29) - ФИКС: удалён дубликат класса, исправлена структура

class WebPushFallback {
    
    /**
     * ПРАВИЛЬНЫЙ метод отправки Push с использованием всех ключей
     * 
     * @param string $endpoint URL push-сервиса
     * @param string $payload JSON-строка с данными уведомления
     * @param string $p256dh Публичный ключ ПОЛУЧАТЕЛЯ (Base64URL, из подписки)
     * @param string $auth Секрет аутентификации (Base64URL, из подписки)
     * @param string $vapidPublicKey VAPID публичный ключ (Base64URL)
     * @param string $vapidPrivateKey VAPID приватный ключ (Base64URL)
     * @param string $subject mailto: или URL
     * @return array ['success' => bool, 'reason' => string]
     */
    public static function sendPushWithKeys($endpoint, $payload, $p256dh, $auth, $vapidPublicKey, $vapidPrivateKey, $subject) {
        log_debug("[PUSH_FALLBACK] ========== SEND WITH KEYS ==========");
        log_debug("[PUSH_FALLBACK] Endpoint: " . substr($endpoint, 0, 80) . "...");
        log_debug("[PUSH_FALLBACK] p256dh length: " . strlen($p256dh));
        log_debug("[PUSH_FALLBACK] auth length: " . strlen($auth));
        log_debug("[PUSH_FALLBACK] Payload length: " . strlen($payload));
        
        try {
            // 1. Декодируем ключи получателя из Base64URL
            $recipientPublicKey = self::base64UrlDecode($p256dh);
            $authSecret = self::base64UrlDecode($auth);
            
            if (!$recipientPublicKey || strlen($recipientPublicKey) !== 65) {
                log_error("[PUSH_FALLBACK] Invalid p256dh key length: " . strlen($recipientPublicKey));
                return ['success' => false, 'reason' => 'invalid_p256dh_key'];
            }
            
            if (!$authSecret || strlen($authSecret) !== 16) {
                log_error("[PUSH_FALLBACK] Invalid auth secret length: " . strlen($authSecret));
                return ['success' => false, 'reason' => 'invalid_auth_secret'];
            }
            
            log_debug("[PUSH_FALLBACK] ✅ Keys decoded successfully");
            
            // 2. Шифруем payload с использованием ключа получателя
            $encryptedData = self::encryptPayloadAes128Gcm($payload, $recipientPublicKey, $authSecret);
            if (!$encryptedData) {
                return ['success' => false, 'reason' => 'encryption_failed'];
            }
            
            log_debug("[PUSH_FALLBACK] ✅ Payload encrypted, size: " . strlen($encryptedData));
            
            // 3. Формируем заголовки
            $headers = [
                'TTL: 2419200',              // 28 дней
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Content-Length: ' . strlen($encryptedData)
            ];
            
            // 4. Добавляем VAPID авторизацию
            $vapidHeader = self::generateVapidHeader($endpoint, $vapidPublicKey, $vapidPrivateKey, $subject);
            if ($vapidHeader) {
                $headers[] = 'Authorization: ' . $vapidHeader;
                log_debug("[PUSH_FALLBACK] ✅ VAPID header generated");
            } else {
                log_warning("[PUSH_FALLBACK] VAPID header generation failed, sending without auth");
            }
            
            // 5. Отправляем запрос через cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encryptedData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WebPush-Fallback/1.3');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            log_debug("[PUSH_FALLBACK] HTTP Response Code: {$httpCode}");
            if ($response) {
                log_debug("[PUSH_FALLBACK] Response body: " . substr($response, 0, 200));
            }
            
            // 6. Анализируем ответ
            if ($httpCode === 201 || $httpCode === 200) {
                log_debug("[PUSH_FALLBACK] ✅ Push sent successfully!");
                return ['success' => true, 'reason' => 'OK'];
            } elseif ($httpCode === 404 || $httpCode === 410) {
                log_debug("[PUSH_FALLBACK] Subscription expired (HTTP {$httpCode})");
                return ['success' => false, 'reason' => 'subscription_expired'];
            } elseif ($httpCode === 429) {
                log_debug("[PUSH_FALLBACK] Rate limited (HTTP 429)");
                return ['success' => false, 'reason' => 'rate_limited'];
            } else {
                $errorMsg = "HTTP {$httpCode}";
                if ($curlError) {
                    $errorMsg .= " (curl: {$curlError})";
                }
                log_debug("[PUSH_FALLBACK] Push failed: {$errorMsg}; {$endpoint}");
                return ['success' => false, 'reason' => $errorMsg];
            }
            
        } catch (Exception $e) {
            log_error("[PUSH_FALLBACK] Exception: " . $e->getMessage());
            return ['success' => false, 'reason' => 'exception: ' . $e->getMessage()];
        }
    }
    
    /**
     * Шифрование payload в формате aes128gcm (RFC 8188)
     * Использует ПРАВИЛЬНЫЙ ключ получателя (p256dh)
     * 
     * @param string $payload Данные для шифрования (JSON)
     * @param string $recipientPublicKey Публичный ключ ПОЛУЧАТЕЛЯ (65 байт, 0x04 + X + Y)
     * @param string $authSecret Секрет аутентификации (16 байт)
     * @return string|false Зашифрованные данные или false
     */
    private static function encryptPayloadAes128Gcm($payload, $recipientPublicKey, $authSecret) {
        log_debug("[PUSH_FALLBACK] Encrypting payload with recipient public key...");
        
        // 1. Генерируем случайную соль (16 байт)
        $salt = random_bytes(16);
        
        // 2. Генерируем эфемерную пару ключей (временную, для этого уведомления)
        $ephemeralKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC
        ]);
        
        if (!$ephemeralKey) {
            log_error("[PUSH_FALLBACK] Failed to generate ephemeral EC key");
            return false;
        }
        
        // 3. Получаем публичный ключ эфемерного ключа (в uncompressed формате)
        $ephemeralDetails = openssl_pkey_get_details($ephemeralKey);
        if (!$ephemeralDetails || !isset($ephemeralDetails['ec'])) {
            log_error("[PUSH_FALLBACK] Failed to get ephemeral key details");
            return false;
        }
        
        $ephemeralPublicX = $ephemeralDetails['ec']['x'];
        $ephemeralPublicY = $ephemeralDetails['ec']['y'];
        $ephemeralPublicKey = "\x04" . $ephemeralPublicX . $ephemeralPublicY;
        
        log_debug("[PUSH_FALLBACK] Ephemeral public key generated, length: " . strlen($ephemeralPublicKey));
        
        // 4. Вычисляем общий секрет через ECDH
        $sharedSecret = self::ecdhDeriveSecret($ephemeralKey, $recipientPublicKey);
        if (!$sharedSecret || strlen($sharedSecret) !== 32) {
            log_error("[PUSH_FALLBACK] Failed to derive shared secret");
            return false;
        }
        
        log_debug("[PUSH_FALLBACK] Shared secret derived, length: " . strlen($sharedSecret));
        
        // 5. HKDF для получения ключа шифрования и nonce
        $info = 'WebPush: info' . "\x00" . $recipientPublicKey . $ephemeralPublicKey;
        
        $contentEncryptionKey = self::hkdfSha256($sharedSecret, $salt, $info, 16);
        $nonce = self::hkdfSha256($sharedSecret, $salt, $info . 'nonce', 12);
        
        log_debug("[PUSH_FALLBACK] Encryption key and nonce generated");
        
        // 6. Добавляем padding (минимальный, 2 байта - маркер конца сообщения)
        $paddedPayload = $payload . chr(2);
        
        // 7. Шифрование AES-128-GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $paddedPayload,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        
        if ($ciphertext === false) {
            log_error("[PUSH_FALLBACK] Encryption failed: " . openssl_error_string());
            return false;
        }
        
        log_debug("[PUSH_FALLBACK] Encryption successful, ciphertext length: " . strlen($ciphertext));
        log_debug("[PUSH_FALLBACK] Tag length: " . strlen($tag));
        
        // 8. Формируем финальный payload (RFC 8188)
        $recordSize = pack('N', 4096);
        $keyLength = chr(strlen($ephemeralPublicKey));
        
        $result = $salt . $recordSize . $keyLength . $ephemeralPublicKey . $ciphertext . $tag;
        
        log_debug("[PUSH_FALLBACK] Final encrypted payload length: " . strlen($result));
        return $result;
    }
    
    /**
     * ECDH: вычисление общего секрета между эфемерным ключом и публичным ключом получателя
     */
    private static function ecdhDeriveSecret($ephemeralKey, $recipientPublicKey) {
        $recipientPem = self::publicKeyDerToPem($recipientPublicKey);
        if (!$recipientPem) {
            log_error("[PUSH_FALLBACK] Failed to convert recipient public key to PEM");
            return false;
        }
        
        $secret = openssl_pkey_derive($recipientPem, $ephemeralKey);
        if ($secret === false) {
            log_error("[PUSH_FALLBACK] openssl_pkey_derive failed: " . openssl_error_string());
            return false;
        }
        
        return $secret;
    }
    
    /**
     * Конвертация публичного EC ключа из DER в PEM
     */
    private static function publicKeyDerToPem($publicKey) {
        if (strlen($publicKey) !== 65 || $publicKey[0] !== "\x04") {
            return false;
        }
        
        $x = substr($publicKey, 1, 32);
        $y = substr($publicKey, 33, 32);
        
        $der = "\x30\x59" .
               "\x30\x13" .
               "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" .
               "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" .
               "\x03\x42\x00" .
               "\x04" . $x . $y;
        
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        
        return $pem;
    }
    
    /**
     * Генерация VAPID JWT токена
     */
    private static function generateVapidHeader($audience, $publicKey, $privateKey, $subject) {
        try {
            $audience = parse_url($audience, PHP_URL_SCHEME) . '://' . parse_url($audience, PHP_URL_HOST);
            $expiration = time() + 43200;
            
            $jwtPayload = [
                'aud' => $audience,
                'exp' => $expiration,
                'sub' => $subject
            ];
            
            $header = ['typ' => 'JWT', 'alg' => 'ES256'];
            $base64UrlHeader = self::base64UrlEncode(json_encode($header));
            $base64UrlPayload = self::base64UrlEncode(json_encode($jwtPayload));
            $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;
            
            $privateKeyBinary = self::base64UrlDecode($privateKey);
            if (strlen($privateKeyBinary) !== 32) {
                log_error("[PUSH_FALLBACK] Invalid private key length: " . strlen($privateKeyBinary));
                return false;
            }
            
            $privatePem = self::privateKeyToPem($privateKeyBinary);
            if (!$privatePem) {
                log_error("[PUSH_FALLBACK] Failed to create PEM from private key");
                return false;
            }
            
            $signature = '';
            $signResult = openssl_sign($signatureInput, $signature, $privatePem, OPENSSL_ALGO_SHA256);
            if (!$signResult) {
                log_error("[PUSH_FALLBACK] openssl_sign failed: " . openssl_error_string());
                return false;
            }
            
            $rawSignature = self::derSignatureToRaw($signature);
            if (!$rawSignature || strlen($rawSignature) !== 64) {
                log_error("[PUSH_FALLBACK] Failed to convert signature to raw format");
                return false;
            }
            
            $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . self::base64UrlEncode($rawSignature);
            $encodedPublicKey = self::base64UrlEncode(self::base64UrlDecode($publicKey));
            
            return 'vapid t=' . $jwt . ', k=' . $encodedPublicKey;
            
        } catch (Exception $e) {
            log_error("[PUSH_FALLBACK] VAPID generation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Конвертация 32-байтного приватного ключа в PEM формат
     */
    private static function privateKeyToPem($privateKey) {
        $version = "\x02\x01\x01";
        $privateKeyOctet = "\x04\x20" . $privateKey;
        $oid_curve = hex2bin('06082a8648ce3d030107');
        $parameters = "\xa0\x0a" . $oid_curve;
        
        $content = $version . $privateKeyOctet . $parameters;
        $der = "\x30" . self::derLength(strlen($content)) . $content;
        
        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";
        
        return $pem;
    }
    
    private static function derLength($length) {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
    
    private static function derSignatureToRaw($derSignature) {
        $len = strlen($derSignature);
        $offset = 0;
        
        if ($offset >= $len || ord($derSignature[$offset]) !== 0x30) {
            log_error("[PUSH_FALLBACK] Invalid DER: expected SEQUENCE (0x30)");
            return false;
        }
        $offset++;
        
        $seqLen = ord($derSignature[$offset]);
        $offset++;
        if ($seqLen & 0x80) {
            $lenBytes = $seqLen & 0x7F;
            $seqLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $seqLen = ($seqLen << 8) | ord($derSignature[$offset + $i]);
            }
            $offset += $lenBytes;
        }
        
        if ($offset >= $len || ord($derSignature[$offset]) !== 0x02) {
            log_error("[PUSH_FALLBACK] Invalid DER: expected INTEGER for r");
            return false;
        }
        $offset++;
        
        $rLen = ord($derSignature[$offset]);
        $offset++;
        if ($rLen & 0x80) {
            $lenBytes = $rLen & 0x7F;
            $rLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $rLen = ($rLen << 8) | ord($derSignature[$offset + $i]);
            }
            $offset += $lenBytes;
        }
        
        $r = substr($derSignature, $offset, $rLen);
        $offset += $rLen;
        
        if ($offset >= $len || ord($derSignature[$offset]) !== 0x02) {
            log_error("[PUSH_FALLBACK] Invalid DER: expected INTEGER for s");
            return false;
        }
        $offset++;
        
        $sLen = ord($derSignature[$offset]);
        $offset++;
        if ($sLen & 0x80) {
            $lenBytes = $sLen & 0x7F;
            $sLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $sLen = ($sLen << 8) | ord($derSignature[$offset + $i]);
            }
            $offset += $lenBytes;
        }
        
        $s = substr($derSignature, $offset, $sLen);
        
        if (strlen($r) > 32) {
            if (ord($r[0]) === 0x00 && strlen($r) === 33) {
                $r = substr($r, 1);
            } else {
                log_error("[PUSH_FALLBACK] r too long: " . strlen($r) . " bytes");
                return false;
            }
        }
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        
        if (strlen($s) > 32) {
            if (ord($s[0]) === 0x00 && strlen($s) === 33) {
                $s = substr($s, 1);
            } else {
                log_error("[PUSH_FALLBACK] s too long: " . strlen($s) . " bytes");
                return false;
            }
        }
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        
        return $r . $s;
    }
    
    private static function hkdfSha256($ikm, $salt, $info, $length) {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        
        $t = '';
        $output = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $output .= $t;
        }
        
        return substr($output, 0, $length);
    }
    
    public static function base64UrlDecode($data) {
        $data = strtr($data, '-_', '+/');
        $result = base64_decode($data);
        return $result === false ? '' : $result;
    }
    
    public static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}