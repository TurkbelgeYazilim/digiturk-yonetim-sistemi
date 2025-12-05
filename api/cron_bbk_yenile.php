<?php
/**
 * Cron Job - bbkAddressCode Yenileme
 * Her 30 dakikada bir çalışır ve bbkAddressCode hatalı başvuruları yeniden gönderime hazırlar
 * 
 * Web Test URL: https://digiturk.ilekasoft.com/api/cron_bbk_yenile.php?key=CRON_SECRET_KEY_2025
 * 
 * Plesk Cron Ayarı:
 * Zamanlama: Her 30 dakika (cron: 0,30 * * * *)
 * Komut: curl "https://digiturk.ilekasoft.com/api/cron_bbk_yenile.php?key=CRON_SECRET_KEY_2025"
 */

// ===== AYARLAR =====
$cronConfig = require_once __DIR__ . '/../config/cron.php';
$SECRET_KEY = $cronConfig['secret_key'];
$MAX_KAYIT = 50; // Her çalışmada işlenecek maksimum kayıt (yüksek, sadece DB işlemi)

// Güvenlik kontrolü - URL'den çağrılıyorsa key kontrolü yap
if (php_sapi_name() !== 'cli') {
    $providedKey = $_GET['key'] ?? '';
    
    if ($providedKey !== $SECRET_KEY) {
        http_response_code(403);
        die('Yetkisiz erişim! Geçersiz key.');
    }
    
    // Web'den çalıştırıldığında çıktıyı HTML olarak formatla
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Cron bbkAddressCode Yenileme</title>";
    echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
    echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#9cdcfe;}.warning{color:#dcdcaa;}</style></head><body>";
    echo "<h2>🔄 bbkAddressCode Yenileme - Cron Job</h2>";
    echo "<pre>";
}

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log dosyası
$logFile = __DIR__ . '/../logs/cron_bbk_log.txt';
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
        } elseif (strpos($message, 'HATA') !== false || strpos($message, 'hatası') !== false) {
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

logMessage("=== bbkAddressCode Yenileme Başladı ===");
logMessage("Maksimum Kayıt: $MAX_KAYIT");

try {
    // Veritabanı bağlantısı
    require_once __DIR__ . '/../auth.php';
    $conn = getDatabaseConnection();
    
    logMessage("Veritabanı bağlantısı başarılı");
    
    // bbkAddressCode hatalarını tanımla
    $hataMesajlari = [
        'Value cannot be null',
        'Parameter name: source',
        'Geçersiz GeoLocationId değeri:0',
        'Value cannot be null.\r\nParameter name: source'
    ];
    
    // Hata koşulunu oluştur
    $whereConditions = [];
    foreach ($hataMesajlari as $hata) {
        $whereConditions[] = "API_basvuru_ResponseMessage LIKE '%" . str_replace("'", "''", $hata) . "%'";
    }
    $whereClause = "(" . implode(" OR ", $whereConditions) . ")";
    
    // Hatalı başvuruları getir
    $sql = "SELECT TOP $MAX_KAYIT
                API_basvuru_ID,
                API_basvuru_bbkAddressCode,
                API_basvuru_ResponseMessage,
                API_basvuru_firstName + ' ' + API_basvuru_surname as musteri_adi,
                API_basvuru_gonderim_deneme_sayisi
            FROM API_basvuruListesi
            WHERE $whereClause
              AND API_basvuru_ResponseCode_ID IS NOT NULL
              AND API_basvuru_otomatik_gonderim = 1
            ORDER BY API_basvuru_guncelleme_tarihi ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $basvurular = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCount = count($basvurular);
    logMessage("$totalCount adet bbkAddressCode hatalı başvuru bulundu");
    
    if ($totalCount == 0) {
        logMessage("İşlenecek hatalı başvuru yok, işlem sonlandırılıyor");
        logMessage("=== İşlem Tamamlandı ===");
        
        if ($isWebMode) {
            echo "</pre><hr><p class='info'>ℹ İşlenecek hatalı başvuru bulunamadı.</p></body></html>";
        }
        exit(0);
    }
    
    // Başarı sayacı
    $successCount = 0;
    $errorCount = 0;
    
    // bbkAddressCode aralığı
    $minCode = 130109;
    $maxCode = 111069460;
    
    // Her başvuru için işlem yap
    foreach ($basvurular as $basvuru) {
        $basvuruId = $basvuru['API_basvuru_ID'];
        $eskiBbkCode = $basvuru['API_basvuru_bbkAddressCode'];
        $musteriAdi = $basvuru['musteri_adi'];
        
        logMessage("---");
        logMessage("İşleniyor: Başvuru ID=$basvuruId, Müşteri: $musteriAdi");
        
        // Yeni bbkAddressCode üret
        $yeniBbkCode = rand($minCode, $maxCode);
        
        logMessage("⚠ bbkAddressCode yenileniyor...");
        logMessage("Eski Kod: $eskiBbkCode → Yeni Kod: $yeniBbkCode");
        
        try {
            // Başvuruyu yeniden gönderime hazırla
            $resetSql = "UPDATE API_basvuruListesi SET 
                        API_basvuru_bbkAddressCode = ?,
                        API_basvuru_ResponseCode_ID = NULL,
                        API_basvuru_ResponseMessage = NULL,
                        API_basvuru_gonderim_deneme_sayisi = 0,
                        API_basvuru_son_gonderim_denemesi = NULL,
                        API_basvuru_guncelleme_tarihi = GETDATE()
                        WHERE API_basvuru_ID = ?";
            
            $resetStmt = $conn->prepare($resetSql);
            $resetStmt->execute([$yeniBbkCode, $basvuruId]);
            
            logMessage("✓ Başvuru yeni kodla tekrar gönderime hazırlandı");
            $successCount++;
            
        } catch (Exception $e) {
            logMessage("HATA: Güncelleme başarısız - " . $e->getMessage());
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
