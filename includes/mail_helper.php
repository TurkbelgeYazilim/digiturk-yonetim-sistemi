<?php
/**
 * E-posta Gönderim Helper Fonksiyonu
 * SMTP ile e-posta gönderir
 */

function sendMail($to, $subject, $body, $isHTML = true, $cc = '', $bcc = '') {
    try {
        // SMTP ayarlarını yükle
        $smtpConfig = require __DIR__ . '/../config/smtp.php';
        
        // Socket bağlantısı oluştur
        $smtp = fsockopen(
            ($smtpConfig['encryption'] === 'ssl' ? 'ssl://' : '') . $smtpConfig['host'],
            $smtpConfig['port'],
            $errno,
            $errstr,
            30
        );
        
        if (!$smtp) {
            throw new Exception("SMTP bağlantı hatası: $errstr ($errno)");
        }
        
        // SMTP yanıtlarını oku
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            echo "SMTP Connect: $response<br>";
            error_log("SMTP Connect: $response");
        }
        
        // EHLO komutu
        fputs($smtp, "EHLO " . gethostname() . "\r\n");
        
        // EHLO yanıtlarını oku (çok satırlı olabilir)
        $response = '';
        while ($line = fgets($smtp, 515)) {
            $response .= $line;
            if ($smtpConfig['debug']) {
                echo "SMTP EHLO: $line<br>";
            }
            // 250 ile başlayan ve 4. karakter boşluk ise son satır
            if (preg_match('/^250 /', $line)) {
                break;
            }
        }
        
        // TLS başlat (port 587 için)
        if ($smtpConfig['encryption'] === 'tls') {
            fputs($smtp, "STARTTLS\r\n");
            $response = fgets($smtp, 515);
            if ($smtpConfig['debug']) {
                echo "SMTP STARTTLS: $response<br>";
                error_log("SMTP STARTTLS: $response");
            }
            
            stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            
            // TLS sonrası tekrar EHLO
            fputs($smtp, "EHLO " . gethostname() . "\r\n");
            $response = fgets($smtp, 515);
        }
        
        // Kimlik doğrulama
        fputs($smtp, "AUTH LOGIN\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            echo "SMTP AUTH LOGIN: $response<br>";
            error_log("SMTP AUTH LOGIN: $response");
        }
        
        if (strpos($response, '334') === false) {
            throw new Exception("SMTP AUTH LOGIN hatası: $response");
        }
        
        fputs($smtp, base64_encode($smtpConfig['username']) . "\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            echo "SMTP Username: $response<br>";
            error_log("SMTP Username: $response");
        }
        
        if (strpos($response, '334') === false) {
            throw new Exception("SMTP kullanıcı adı hatası: $response");
        }
        
        fputs($smtp, base64_encode($smtpConfig['password']) . "\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            echo "SMTP Password: $response<br>";
            error_log("SMTP Password: $response");
        }
        
        if (strpos($response, '235') === false) {
            throw new Exception("SMTP şifre hatası (Kimlik doğrulama başarısız): $response");
        }
        
        // MAIL FROM
        fputs($smtp, "MAIL FROM: <" . $smtpConfig['from_email'] . ">\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            error_log("SMTP MAIL FROM: $response");
        }
        
        // RCPT TO - Ana alıcı
        fputs($smtp, "RCPT TO: <$to>\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            error_log("SMTP RCPT TO: $response");
        }
        
        // RCPT TO - CC alıcıları
        if (!empty($cc)) {
            $ccList = array_map('trim', explode(',', $cc));
            foreach ($ccList as $ccEmail) {
                if (!empty($ccEmail)) {
                    fputs($smtp, "RCPT TO: <$ccEmail>\r\n");
                    $response = fgets($smtp, 515);
                    if ($smtpConfig['debug']) {
                        error_log("SMTP RCPT TO (CC): $response");
                    }
                }
            }
        }
        
        // RCPT TO - BCC alıcıları
        if (!empty($bcc)) {
            $bccList = array_map('trim', explode(',', $bcc));
            foreach ($bccList as $bccEmail) {
                if (!empty($bccEmail)) {
                    fputs($smtp, "RCPT TO: <$bccEmail>\r\n");
                    $response = fgets($smtp, 515);
                    if ($smtpConfig['debug']) {
                        error_log("SMTP RCPT TO (BCC): $response");
                    }
                }
            }
        }
        
        // DATA
        fputs($smtp, "DATA\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            error_log("SMTP DATA: $response");
        }
        
        // E-posta başlıkları ve içeriği
        $contentType = $isHTML ? 'text/html' : 'text/plain';
        $headers = "From: {$smtpConfig['from_name']} <{$smtpConfig['from_email']}>\r\n";
        $headers .= "To: $to\r\n";
        
        // CC ekle (başlıkta gösterilecek)
        if (!empty($cc)) {
            $headers .= "Cc: $cc\r\n";
        }
        
        // BCC başlıkta gösterilmez, sadece RCPT TO'da belirtilir
        
        $headers .= "Subject: $subject\r\n";
        $headers .= "Content-Type: $contentType; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        
        $message = $headers . "\r\n" . $body . "\r\n.\r\n";
        
        fputs($smtp, $message);
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            error_log("SMTP Message: $response");
        }
        
        // QUIT
        fputs($smtp, "QUIT\r\n");
        $response = fgets($smtp, 515);
        if ($smtpConfig['debug']) {
            error_log("SMTP QUIT: $response");
        }
        
        fclose($smtp);
        
        return true;
        
    } catch (Exception $e) {
        $errorMsg = "Mail gönderim hatası: " . $e->getMessage();
        echo "<span style='color: red; font-weight: bold;'>$errorMsg</span><br>";
        error_log($errorMsg);
        return false;
    }
}
?>
