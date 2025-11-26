<?php
// SMTP E-posta Gönderim Ayarları
// Bu dosyayı 'smtp.php' olarak kopyalayın ve gerçek değerlerinizle doldurun

return [
    'host' => 'smtp.gmail.com',                          // SMTP sunucu adresi (örn: smtp.gmail.com, smtp.yandex.com)
    'port' => 465,                                       // SMTP port (587 = TLS, 465 = SSL)
    'username' => 'your-email@example.com',              // SMTP kullanıcı adı (email adresiniz)
    'password' => 'your-app-password-here',              // SMTP şifre veya uygulama şifresi
    'from_email' => 'your-email@example.com',            // Gönderen e-posta
    'from_name' => 'Your Company Name',                  // Gönderen adı
    'encryption' => 'ssl',                               // Şifreleme: 'tls' veya 'ssl'
    'debug' => false,                                    // Debug modu (geliştirme için true, production'da false)
];
