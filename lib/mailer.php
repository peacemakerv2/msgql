<?php
// lib/mailer.php version 2.6 - исправлено дублирование приветствия и подвала

if (!function_exists('smtpmail')) {
    $smtp_func_path = __DIR__ . '/smtp_func.php';
    if (file_exists($smtp_func_path)) {
        require_once $smtp_func_path;
    }
}

if (!function_exists('msgql_send_email')) {
    
    function msgql_send_email($to, $subject, $message, $extra_data = []) {
        global $system_title;
        
        if (function_exists('log_debug')) {
            log_debug("[MAILER] ========== START msgql_send_email ==========");
            log_debug("[MAILER] Recipient: {$to}");
            log_debug("[MAILER] Subject: {$subject}");
        }
        
        if (empty($to)) {
            return false;
        }
        
        $to = trim($to);
        
        // Получаем имя пользователя
        $user_name = $extra_data['user_name'] ?? '';
        if (empty($user_name)) {
            $parts = explode('@', $to);
            $user_name = $parts[0];
        }
        
        $base_url = '';
        if (function_exists('msgql_get_base_url')) {
            $base_url = msgql_get_base_url();
        }
        if (empty($base_url)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_url = $protocol . '://' . $host;
        }
        
        // Очищаем сообщение от HTML
        $plain_message = strip_tags($message);
        $plain_message = str_replace(["<br>", "<br/>", "<br />", "</p>", "</div>"], "\r\n", $plain_message);
        $plain_message = preg_replace('/<[^>]*>/', '', $plain_message);
        $plain_message = preg_replace('/(\r\n|\n|\r)/', "\r\n", $plain_message);
        $plain_message = preg_replace("/\r\n\r\n\r\n+/", "\r\n\r\n", $plain_message);
        
        // Формируем ПОЛНОЕ тело письма (только здесь добавляем приветствие и подвал)
        $email_body = "Здравствуйте, {$user_name}!\r\n\r\n";
        $email_body .= $plain_message . "\r\n\r\n";
        $email_body .= "---\r\n";
        $email_body .= "Это автоматическое сообщение системы " . ($system_title ?? 'ЗадаЧат') . ".\r\n";
        $email_body .= "Настройки уведомлений можно изменить в личном кабинете: {$base_url}/admin.php?tab=profile\r\n";
        
        if (function_exists('log_debug')) {
            log_debug("[MAILER] Final email body length: " . strlen($email_body));
            log_debug("[MAILER] Calling smtpmail()...");
        }
        
        if (function_exists('smtpmail')) {
            $result = smtpmail($to, $subject, $email_body, '');
            
            if (function_exists('log_debug')) {
                log_debug("[MAILER] smtpmail result: " . ($result ? 'SUCCESS' : 'FAILED'));
                log_debug("[MAILER] ========== END ==========");
            }
            
            return $result;
        }
        
        // Fallback
        global $replyto, $EM_Sender, $system_name;
        $sender_email = $replyto ?? $EM_Sender ?? 'noreply@localhost';
        $sender_name = $system_name ?? 'ЗадаЧат';
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: {$sender_name} <{$sender_email}>\r\n";
        
        return mail($to, $subject, $email_body, $headers);
    }
}