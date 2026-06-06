<?php
// smtp_func.php ver.1.7 - ИСПОЛЬЗУЕТ BASE64 ДЛЯ ЗАГОЛОВКОВ (БЕЗ РАЗРЫВОВ)

global $smtp_debug_info;
$smtp_debug_info = "";

function email_log_write($level, $message, $details = []) {
    $log_dir = __DIR__ . '/../logs';
    $log_file = $log_dir . '/log_email.txt';
    
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    
    $timestamp = date('d.m.Y H:i:s (T)');
    $pid = getmypid();
    $log_entry = "[{$timestamp}] [PID:{$pid}] [{$level}] " . $message;
    
    if (!empty($details)) {
        foreach ($details as $key => $value) {
            $log_entry .= "\n  {$key}: " . str_replace(["\r", "\n"], ['\r', '\n'], (string)$value);
        }
    }
    $log_entry .= "\n" . str_repeat('-', 80) . "\n";
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
        $old_log = $log_dir . '/log_email_old.txt';
        @copy($log_file, $old_log);
        @ftruncate(fopen($log_file, 'w'), 0);
    }
}

/**
 * Кодирование заголовка в Base64 (RFC 2047)
 * Без разрывов строк - одной непрерывной строкой
 */
function encode_email_header_b64($string) {
    if (empty($string)) {
        return '';
    }
    
    // Проверяем, нужно ли кодирование
    if (!preg_match('/[^\x20-\x7E]/', $string)) {
        return $string;
    }
    
    $encoded = base64_encode($string);
    
    // Возвращаем одной строкой, без разрывов
    return '=?UTF-8?B?' . $encoded . '?=';
}

function encode_from_header($display_name, $email) {
    if (empty($display_name)) {
        return "From: {$email}\r\n";
    }
    
    if (!preg_match('/[^\x20-\x7E]/', $display_name)) {
        return "From: {$display_name} <{$email}>\r\n";
    }
    
    $encoded_name = encode_email_header_b64($display_name);
    
    return "From: {$encoded_name} <{$email}>\r\n";
}

function smtpmail($mail_to, $subject, $message, $headers = '') {
    global $EM_Server, $EM_Login, $EM_Password, $SMTPport, $SMTPssl;
    global $system_title, $replyto, $mailmsg, $smtp_debug_info;
    
    $start_time = microtime(true);
    $mail_to = trim($mail_to);
    
    $sender_email = $replyto ?? $EM_Login ?? 'noreply@localhost';
    $sender_name = $system_title ?? 'ЗадаЧат';
    
    $sender_name = preg_replace('/[\x00-\x1F\x7F]/', '', $sender_name);
    if (empty($sender_name)) {
        $sender_name = 'System';
    }
    
    email_log_write('INFO', 'Начало отправки письма (SMTP)', [
        'Получатель' => $mail_to,
        'Тема' => $subject,
        'Отправитель_имя' => $sender_name
    ]);
    
    $smtp_debug_info = "";
    
    if (empty($subject)) {
        $subject = "Сообщение от системы";
    }
    
    // Кодируем тему (Base64, одной строкой)
    $encoded_subject = encode_email_header_b64($subject);
    
    // Кодируем имя отправителя
    $from_header = encode_from_header($sender_name, $sender_email);
    
    // Убеждаемся, что тело в UTF-8
    if (!mb_check_encoding($message, 'UTF-8')) {
        $message = mb_convert_encoding($message, 'UTF-8', 'auto');
    }
    
    email_log_write('DEBUG', 'Header encoding', [
        'original_subject' => $subject,
        'encoded_subject' => $encoded_subject,
        'encoded_subject_length' => strlen($encoded_subject),
        'message_encoding' => mb_detect_encoding($message, 'UTF-8, Windows-1251, KOI8-R', true) ?: 'unknown'
    ]);
    
    $moscow_offset_seconds = 3 * 3600;
    $moscow_timestamp = time() + $moscow_offset_seconds;
    $date_header = "Date: " . gmdate("D, d M Y H:i:s", $moscow_timestamp) . " +0300\r\n";
    
    $full_headers = "MIME-Version: 1.0\r\n";
    $full_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $full_headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $full_headers .= $from_header;
    $full_headers .= "To: {$mail_to}\r\n";
    $full_headers .= "Subject: {$encoded_subject}\r\n";
    $full_headers .= $date_header;
    $full_headers .= "X-Priority: 3\r\n";
    $full_headers .= "X-Mailer: msgql/2.0\r\n";
    
    if (!empty($headers)) {
        $headers = preg_replace('/^From:.*\r\n/mi', '', $headers);
        $headers = preg_replace('/^Subject:.*\r\n/mi', '', $headers);
        $full_headers .= $headers;
    }
    
    $full_headers .= "\r\n";
    $full_message = $full_headers . $message;
    
    $smtp_debug_info .= "=== SMTP SESSION START ===\r\n";
    $smtp_debug_info .= "From header: " . trim($from_header) . "\r\n";
    $smtp_debug_info .= "Subject: {$encoded_subject}\r\n";
    
    if (!$socket = @fsockopen($EM_Server, $SMTPport, $errno, $errstr, 30)) {
        $error = "Connection failed: {$errno} - {$errstr}";
        $smtp_debug_info .= "CONNECTION FAILED: {$error}\r\n";
        $mailmsg = $error;
        email_log_write('ERROR', 'SMTP: Connection failed', ['error' => $errstr]);
        return false;
    }
    
    if (!server_parse($socket, "220", __LINE__, "Connection")) {
        fclose($socket);
        $mailmsg = "Server not ready";
        return false;
    }
    
    fputs($socket, "EHLO " . $EM_Server . "\r\n");
    if (!server_parse($socket, "250", __LINE__, "EHLO")) {
        fclose($socket);
        $mailmsg = "EHLO failed";
        return false;
    }
    
    fputs($socket, "AUTH LOGIN\r\n");
    if (!server_parse($socket, "334", __LINE__, "AUTH LOGIN")) {
        fclose($socket);
        $mailmsg = "AUTH LOGIN not supported";
        return false;
    }
    
    fputs($socket, base64_encode($EM_Login) . "\r\n");
    if (!server_parse($socket, "334", __LINE__, "USERNAME")) {
        fclose($socket);
        $mailmsg = "Username rejected";
        return false;
    }
    
    fputs($socket, base64_encode($EM_Password) . "\r\n");
    if (!server_parse($socket, "235", __LINE__, "PASSWORD")) {
        fclose($socket);
        $mailmsg = "Authentication failed";
        return false;
    }
    
    fputs($socket, "MAIL FROM: <{$sender_email}>\r\n");
    if (!server_parse($socket, "250", __LINE__, "MAIL FROM")) {
        fclose($socket);
        $mailmsg = "MAIL FROM failed";
        return false;
    }
    
    fputs($socket, "RCPT TO: <{$mail_to}>\r\n");
    if (!server_parse($socket, "250", __LINE__, "RCPT TO")) {
        fclose($socket);
        $mailmsg = "RCPT TO failed";
        return false;
    }
    
    fputs($socket, "DATA\r\n");
    if (!server_parse($socket, "354", __LINE__, "DATA")) {
        fclose($socket);
        $mailmsg = "DATA command failed";
        return false;
    }
    
    fputs($socket, $full_message . "\r\n.\r\n");
    
    if (!server_parse($socket, "250", __LINE__, "MESSAGE DATA")) {
        fclose($socket);
        $mailmsg = "Message data rejected";
        return false;
    }
    
    fputs($socket, "QUIT\r\n");
    fclose($socket);
    
    $smtp_debug_info .= "=== SMTP SESSION END - SUCCESS ===\r\n";
    $mailmsg = "";
    $elapsed = round((microtime(true) - $start_time) * 1000, 2);
    
    email_log_write('SUCCESS', 'SMTP: Email sent successfully', [
        'recipient' => $mail_to,
        'elapsed_ms' => $elapsed
    ]);
    
    return true;
}

function server_parse($socket, $expected_response, $line = __LINE__, $step = "Unknown") {
    global $smtp_debug_info;
    
    $server_response = "";
    while (substr($server_response, 3, 1) != ' ') {
        if (!($server_response = fgets($socket, 256))) {
            $smtp_debug_info .= "NO RESPONSE at step: {$step}\r\n";
            return false;
        }
    }
    
    $smtp_debug_info .= "SERVER RESPONSE [{$step}]: " . trim($server_response) . "\r\n";
    
    if (substr($server_response, 0, 3) != $expected_response) {
        $smtp_debug_info .= "UNEXPECTED RESPONSE: expected {$expected_response}\r\n";
        return false;
    }
    
    return true;
}

function smtpmassmail($mail_to, $subject, $message, $headers = '') {
    $mailaddresses = explode(",", $mail_to);
    $results = ['success' => 0, 'fail' => 0, 'errors' => []];
    
    foreach ($mailaddresses as $mailaddress) {
        $mailaddress = trim($mailaddress);
        if (empty($mailaddress)) continue;
        
        if (smtpmail($mailaddress, $subject, $message, $headers)) {
            $results['success']++;
        } else {
            $results['fail']++;
            $results['errors'][] = $mailaddress;
        }
    }
    
    return $results['fail'] == 0;
}