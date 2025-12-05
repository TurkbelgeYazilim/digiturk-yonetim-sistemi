<?php
/**
 * Cron Job - API ResponseCode Düzeltme
 * Belirli hata mesajlarına sahip başvuruların ResponseCode_ID'sini NULL yapar
 * 
 * Web Test URL: https://digiturk.ilekasoft.com/api/cron_duzeltme.php?key=CRON_SECRET_KEY_2025
 * 
 * Plesk Cron Ayarı:
 * Zamanlama: Her 10 dakika - 4 dakika kaydırılmış (cron: 4,14,24,34,44,54 * * * *)
 * Komut: curl "https://digiturk.ilekasoft.com/api/cron_duzeltme.php?key=CRON_SECRET_KEY_2025"
 * 
 * Düzeltilen Durum:
 * - "Bir Dakika İçerisinde Aynı Üyelikte Aynı Case Açılmaya Çalışıldı" hatası
 * - ResponseCode_ID NULL yapılır, böylece tekrar gönderim yapılabilir
 */

// ===== AYARLAR =====
$cronConfig = require_once __DIR__ . '/../config/cron.php';
$SECRET_KEY = $cronConfig['secret_key'];
$MAX_KAYIT = 50;                        // Her çalışmada işlenecek maksimum kayıt

// Güvenlik kontrolü - URL'den çağrılıyorsa key kontrolü yap
if (php_sapi_name() !== 'cli') {
    $providedKey = $_GET['key'] ?? '';
    
    if ($providedKey !== $SECRET_KEY) {
        http_response_code(403);
        die('Yetkisiz erişim! Geçersiz key.');
    }
    
    // Web'den çalıştırıldığında çıktıyı HTML olarak formatla
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Cron ResponseCode Düzeltme</title>";
    echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
    echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#9cdcfe;}.warning{color:#dcdcaa;}</style></head><body>";
    echo "<h2>🔧 API ResponseCode Düzeltme - Cron Job</h2>";
    echo "<pre>";
}

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log dosyası
$logFile = __DIR__ . '/../logs/cron_duzeltme_log.txt';
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

logMessage("=== API ResponseCode Düzeltme Başladı ===");
logMessage("Maksimum Kayıt: $MAX_KAYIT");

try {
    // Veritabanı bağlantısı
    require_once __DIR__ . '/../auth.php';
    $conn = getDatabaseConnection();
    
    logMessage("Veritabanı bağlantısı başarılı");
    
    // Belirtilen hata mesajına sahip başvuruları getir
    $sql = "SELECT TOP $MAX_KAYIT
                API_basvuru_ID,
                API_basvuru_ResponseCode_ID,
                API_basvuru_ResponseMessage,
                API_basvuru_firstName,
                API_basvuru_surname,
                API_basvuru_olusturma_tarih
            FROM API_basvuruListesi
            WHERE API_basvuru_ResponseMessage LIKE '%Case oluşturma işleminde hata:Bir Dakika İçerisinde Aynı Üyelikte Aynı Case Açılmaya Çalışıldı%'
            ORDER BY API_basvuru_olusturma_tarih ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $basvurular = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCount = count($basvurular);
    logMessage("$totalCount adet düzeltilecek başvuru bulundu");
    
    if ($totalCount == 0) {
        logMessage("İşlenecek başvuru yok, işlem sonlandırılıyor");
        logMessage("=== İşlem Tamamlandı ===");
        
        if ($isWebMode) {
            echo "</pre><hr><p class='info'>ℹ Düzeltilecek başvuru bulunamadı.</p></body></html>";
        }
        exit(0);
    }
    
    // Başarı ve hata sayaçları
    $successCount = 0;
    $errorCount = 0;
    
    // Her başvuru için işlem yap
    foreach ($basvurular as $basvuru) {
        $basvuruId = $basvuru['API_basvuru_ID'];
        $adSoyad = $basvuru['API_basvuru_firstName'] . ' ' . $basvuru['API_basvuru_surname'];
        $eskiResponseCodeId = $basvuru['API_basvuru_ResponseCode_ID'];
        
        try {
            // ResponseCode_ID'yi NULL yap
            $updateSql = "UPDATE API_basvuruListesi 
                         SET API_basvuru_ResponseCode_ID = NULL,
                             API_basvuru_guncelleme_tarihi = GETDATE()
                         WHERE API_basvuru_ID = :basvuru_id";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bindParam(':basvuru_id', $basvuruId, PDO::PARAM_INT);
            
            if ($updateStmt->execute()) {
                $successCount++;
                logMessage("✓ ID:$basvuruId - $adSoyad - ResponseCode_ID:$eskiResponseCodeId → NULL yapıldı");
            } else {
                $errorCount++;
                logMessage("✗ HATA - ID:$basvuruId - $adSoyad - Güncelleme başarısız");
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $errorMsg = $e->getMessage();
            logMessage("✗ HATA - ID:$basvuruId - $adSoyad - Exception: $errorMsg");
        }
        
        // Her 10 kayıtta bir kısa bekleme (veritabanı yükünü azaltmak için)
        if (($successCount + $errorCount) % 10 == 0) {
            usleep(100000); // 0.1 saniye
        }
    }
    
    logMessage("=== İŞLEM SONUÇLARI ===");
    logMessage("Toplam İşlenen: $totalCount");
    logMessage("BAŞARILI: $successCount");
    logMessage("BAŞARISIZ: $errorCount");
    logMessage("=== İşlem Tamamlandı ===");
    
    if ($isWebMode) {
        echo "</pre><hr>";
        echo "<div class='success'>✓ Başarılı: $successCount</div>";
        if ($errorCount > 0) {
            echo "<div class='error'>✗ Başarısız: $errorCount</div>";
        }
        echo "<div class='info'>Toplam İşlenen: $totalCount</div>";
        echo "<hr><p class='info'>Log dosyası: logs/cron_duzeltme_log.txt</p>";
        echo "</body></html>";
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    logMessage("KRITIK HATA: $errorMsg");
    
    if ($isWebMode) {
        echo "</pre><hr><div class='error'>❌ Kritik Hata: " . htmlspecialchars($errorMsg) . "</div></body></html>";
    }
    
    exit(1);
}
