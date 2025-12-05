<?php
/**
 * Cron Job - Otomatik E-mail Düzeltme
 * Her 5 dakikada bir çalışır ve e-mail hatası alan başvurulara rastgele Gmail adresi atar
 * 
 * Web Test URL: https://digiturk.ilekasoft.com/api/cron_email_duzelt.php?key=CRON_SECRET_KEY_2025
 * 
 * Plesk Cron Ayarı:
 * Zamanlama: Her 5 dakika - 2 dakika kaydırılmış (cron: 2,7,12,17,22,27,32,37,42,47,52,57 * * * *)
 * Komut: curl "https://digiturk.ilekasoft.com/api/cron_email_duzelt.php?key=CRON_SECRET_KEY_2025"
 * 
 * NOT: Başvuru cron'u ile çakışmaması için 2 dakika kaydırılmıştır.
 */

// ===== AYARLAR =====
$cronConfig = require_once __DIR__ . '/../config/cron.php';
$SECRET_KEY = $cronConfig['secret_key'];
$MAX_KAYIT = 10;                        // Her çalışmada işlenecek maksimum kayıt

// Güvenlik kontrolü - URL'den çağrılıyorsa key kontrolü yap
if (php_sapi_name() !== 'cli') {
    $providedKey = $_GET['key'] ?? '';
    
    if ($providedKey !== $SECRET_KEY) {
        http_response_code(403);
        die('Yetkisiz erişim! Geçersiz key.');
    }
    
    // Web'den çalıştırıldığında çıktıyı HTML olarak formatla
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Cron E-mail Düzeltme</title>";
    echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
    echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#9cdcfe;}.warning{color:#dcdcaa;}</style></head><body>";
    echo "<h2>🚀 Otomatik E-mail Düzeltme - Cron Job</h2>";
    echo "<pre>";
}

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log dosyası
$logFile = __DIR__ . '/../logs/cron_email_log.txt';
$isWebMode = php_sapi_name() !== 'cli';

// Log fonksiyonu
function logMessage($message, $type = 'info') {
    global $logFile, $isWebMode;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Web modunda renkli çıktı
    if ($isWebMode) {
        $class = '';
        if (strpos($message, 'BAŞARILI') !== false || strpos($message, 'başarılı') !== false || strpos($message, '✓') !== false) {
            $class = 'success';
        } elseif (strpos($message, 'HATA') !== false || strpos($message, 'hatası') !== false || strpos($message, 'Başarısız') !== false) {
            $class = 'error';
        } elseif (strpos($message, 'UYARI') !== false || strpos($message, 'Atlandı') !== false || strpos($message, '⚠') !== false) {
            $class = 'warning';
        } else {
            $class = 'info';
        }
        echo "<span class='$class'>" . htmlspecialchars($logMessage) . "</span>";
    } else {
        echo $logMessage;
    }
}

// E-mail oluşturma fonksiyonu
function generateRandomEmail($firstName, $lastName) {
    // Ad ve soyadı temizle (Türkçe karakterleri değiştir)
    $turkishChars = ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'];
    $englishChars = ['i', 'g', 'u', 's', 'o', 'c', 'I', 'G', 'U', 'S', 'O', 'C'];
    
    $firstName = str_replace($turkishChars, $englishChars, $firstName);
    $lastName = str_replace($turkishChars, $englishChars, $lastName);
    
    // Küçük harfe çevir ve boşlukları temizle
    $firstName = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $firstName)));
    $lastName = strtolower(trim(preg_replace('/[^a-zA-Z]/', '', $lastName)));
    
    // Rastgele sayı ekle (1000-9999 arası)
    $randomNum = rand(1000, 9999);
    
    // Farklı format seçenekleri
    $formats = [
        $firstName . '.' . $lastName . $randomNum . '@gmail.com',
        $firstName . $lastName . $randomNum . '@gmail.com',
        $firstName . '_' . $lastName . $randomNum . '@gmail.com',
        $lastName . '.' . $firstName . $randomNum . '@gmail.com'
    ];
    
    return $formats[array_rand($formats)];
}

logMessage("=== Otomatik E-mail Düzeltme Başladı ===");
logMessage("Maksimum Kayıt: $MAX_KAYIT");

try {
    // Veritabanı bağlantısı
    require_once __DIR__ . '/../auth.php';
    $conn = getDatabaseConnection();
    
    logMessage("Veritabanı bağlantısı başarılı");
    
    // E-mail'i boş olan veya "E-mail boş olamaz." hatası alan başvuruları getir
    $sql = "SELECT TOP $MAX_KAYIT
                bl.API_basvuru_ID,
                bl.API_basvuru_email,
                bl.API_basvuru_ResponseMessage,
                bl.API_basvuru_firstName,
                bl.API_basvuru_surname,
                u.first_name,
                u.last_name
            FROM API_basvuruListesi bl
            LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
            LEFT JOIN users u ON ak.users_ID = u.id
            WHERE (
                (
                    (bl.API_basvuru_email IS NULL OR bl.API_basvuru_email = '')
                    AND bl.API_basvuru_ResponseCode_ID IS NULL
                )
                OR bl.API_basvuru_ResponseMessage = 'E-mail boş olamaz.'
              )
              AND bl.API_basvuru_otomatik_gonderim = 1
              AND ak.api_iris_kullanici_durum = 1
              AND ak.api_iris_kullanici_token IS NOT NULL
            ORDER BY bl.API_basvuru_olusturma_tarih ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $basvurular = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCount = count($basvurular);
    logMessage("$totalCount adet e-mail hatası olan başvuru bulundu (boş veya 'E-mail boş olamaz.' hatası)");
    
    if ($totalCount == 0) {
        logMessage("İşlenecek başvuru yok, işlem sonlandırılıyor");
        logMessage("=== İşlem Tamamlandı ===");
        
        if ($isWebMode) {
            echo "</pre><hr><p class='info'>ℹ E-mail hatası olan başvuru bulunamadı.</p></body></html>";
        }
        exit(0);
    }
    
    // Başarı ve hata sayaçları
    $successCount = 0;
    $errorCount = 0;
    
    // Her başvuru için işlem yap
    foreach ($basvurular as $basvuru) {
        $basvuruId = $basvuru['API_basvuru_ID'];
        $eskiEmail = $basvuru['API_basvuru_email'] ?? 'NULL';
        $firstName = $basvuru['API_basvuru_firstName'];
        $lastName = $basvuru['API_basvuru_surname'];
        $kullanici = trim($basvuru['first_name'] . ' ' . $basvuru['last_name']);
        
        logMessage("---");
        logMessage("İşleniyor: Başvuru ID=$basvuruId, Müşteri: $firstName $lastName, Kullanıcı: $kullanici");
        logMessage("Mevcut E-mail: $eskiEmail");
        
        // Yeni e-mail üret
        $yeniEmail = generateRandomEmail($firstName, $lastName);
        logMessage("Yeni E-mail: $yeniEmail");
        
        try {
            // E-mail'i güncelle ve hata bilgilerini temizle
            $updateSql = "UPDATE API_basvuruListesi 
                         SET API_basvuru_email = ?,
                             API_basvuru_ResponseMessage = NULL,
                             API_basvuru_ResponseCode_ID = NULL,
                             API_basvuru_guncelleme_tarihi = GETDATE()
                         WHERE API_basvuru_ID = ?";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$yeniEmail, $basvuruId]);
            
            logMessage("✓ BAŞARILI: E-mail güncellendi ve hata bilgileri temizlendi, başvuru gönderime hazır");
            $successCount++;
            
        } catch (Exception $updateErr) {
            logMessage("HATA: Güncelleme başarısız - " . $updateErr->getMessage());
            $errorCount++;
        }
    }
    
    logMessage("---");
    logMessage("=== İşlem Tamamlandı ===");
    logMessage("Toplam: $totalCount başvuru");
    logMessage("Başarılı: $successCount");
    logMessage("Başarısız: $errorCount");
    
    // Web modunda footer ekle
    if ($isWebMode) {
        echo "</pre>";
        echo "<hr>";
        echo "<div style='background:#2d2d30;padding:15px;border-radius:5px;'>";
        echo "<h3 style='color:#4ec9b0;'>✓ İşlem Tamamlandı!</h3>";
        echo "<table style='color:#d4d4d4;'>";
        echo "<tr><td><strong>Toplam:</strong></td><td>$totalCount başvuru</td></tr>";
        echo "<tr><td><strong>Başarılı:</strong></td><td style='color:#4ec9b0;'>$successCount</td></tr>";
        echo "<tr><td><strong>Başarısız:</strong></td><td style='color:#f48771;'>$errorCount</td></tr>";
        echo "</table>";
        echo "</div>";
        echo "<p style='margin-top:20px;'><strong>Log dosyası:</strong> <code>" . htmlspecialchars($logFile) . "</code></p>";
        echo "<p><a href='?key=" . htmlspecialchars($_GET['key'] ?? '') . "' style='color:#569cd6;text-decoration:none;'>🔄 Tekrar Çalıştır</a></p>";
        echo "</body></html>";
    }
    
} catch (Exception $e) {
    logMessage("KRİTİK HATA: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    
    // Web modunda hata göster
    if ($isWebMode) {
        echo "</pre>";
        echo "<hr>";
        echo "<div style='background:#3f1d1d;padding:15px;border-radius:5px;color:#f48771;'>";
        echo "<h3>✗ Hata Oluştu!</h3>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
        echo "</body></html>";
    }
    
    exit(1);
}

exit(0);
