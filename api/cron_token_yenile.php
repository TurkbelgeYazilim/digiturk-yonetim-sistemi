<?php
/**
 * Cron Job için Token Yenileme Scripti
 * Plesk'te 6 saatte bir çalıştırılacak
 * 
 * URL: https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025
 * 
 * Plesk Cron Ayarı:
 * Komut: curl "https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025"
 */

// Güvenlik kontrolü - URL'den çağrılıyorsa key kontrolü yap
if (php_sapi_name() !== 'cli') {
    // Web'den çağrıldı
    $cronConfig = require_once __DIR__ . '/../config/cron.php';
    $secretKey = $cronConfig['secret_key'];
    $providedKey = $_GET['key'] ?? '';
    
    if ($providedKey !== $secretKey) {
        http_response_code(403);
        die('Yetkisiz erişim! Geçersiz key.');
    }
    
    // Web'den çalıştırıldığında çıktıyı HTML olarak formatla
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Cron Token Yenileme</title>";
    echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
    echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#9cdcfe;}</style></head><body>";
    echo "<h2>Token Otomatik Yenileme - Cron Job</h2>";
    echo "<pre>";
}

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log dosyası
$logFile = __DIR__ . '/../logs/cron_token_log.txt';
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
        if (strpos($message, 'BAŞARILI') !== false || strpos($message, 'başarılı') !== false) {
            $class = 'success';
        } elseif (strpos($message, 'HATA') !== false || strpos($message, 'hatası') !== false) {
            $class = 'error';
        } else {
            $class = 'info';
        }
        echo "<span class='$class'>" . htmlspecialchars($logMessage) . "</span>";
    } else {
        echo $logMessage;
    }
}

logMessage("=== Token Yenileme Başladı ===");

try {
    // Veritabanı bağlantısı
    require_once __DIR__ . '/../auth.php';
    $conn = getDatabaseConnection();
    
    logMessage("Veritabanı bağlantısı başarılı");
    
    // Aktif API kullanıcılarını getir
    $sql = "SELECT api_iris_kullanici_ID, api_iris_kullanici_OrganisationCd, 
                   api_iris_kullanici_LoginCd, api_iris_kullanici_Password,
                   users_ID, api_iris_kullanici_tokenGuncellemeTarihi
            FROM API_kullanici 
            WHERE api_iris_kullanici_durum = 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalUsers = count($users);
    logMessage("$totalUsers aktif kullanıcı bulundu");
    
    if ($totalUsers == 0) {
        logMessage("İşlenecek kullanıcı yok, işlem sonlandırılıyor");
        exit(0);
    }
    
    // API Address URL'ini al
    $addressSql = "SELECT api_iris_Address_URL FROM API_Link WHERE api_iris_Address_ID = 1";
    $addressStmt = $conn->prepare($addressSql);
    $addressStmt->execute();
    $addressData = $addressStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$addressData || empty($addressData['api_iris_Address_URL'])) {
        logMessage("HATA: API adresi bulunamadı (API_Link tablosunda ID=1 kaydı olmalı)");
        exit(1);
    }
    
    $apiUrl = $addressData['api_iris_Address_URL'];
    logMessage("API URL: $apiUrl");
    
    // Başarı ve hata sayaçları
    $successCount = 0;
    $errorCount = 0;
    
    // Her kullanıcı için token yenileme işlemi
    foreach ($users as $user) {
        $userId = $user['api_iris_kullanici_ID'];
        $organizationCd = $user['api_iris_kullanici_OrganisationCd'];
        $loginCd = $user['api_iris_kullanici_LoginCd'];
        $password = $user['api_iris_kullanici_Password'];
        $lastUpdate = $user['api_iris_kullanici_tokenGuncellemeTarihi'];
        
        logMessage("---");
        logMessage("İşleniyor: ID=$userId, Org=$organizationCd, Login=$loginCd");
        
        // Son güncelleme zamanını kontrol et
        if ($lastUpdate) {
            $lastUpdateTime = strtotime($lastUpdate);
            $currentTime = time();
            $timeDiff = ($currentTime - $lastUpdateTime) / 3600; // Saat cinsinden
            
            logMessage("Son güncelleme: $lastUpdate (%.2f saat önce)", $timeDiff);
            
            // 6 saatten daha yeni güncellenmişse atla
            if ($timeDiff < 6) {
                logMessage("Son güncelleme 6 saatten yeni, atlanıyor");
                continue;
            }
        } else {
            logMessage("İlk kez token oluşturulacak");
        }
        
        // API çağrısı için veri hazırla
        $postData = [
            'OrganisationCd' => $organizationCd,
            'LoginCd' => $loginCd,
            'Password' => $password
        ];
        
        $postDataJson = json_encode($postData);
        
        // cURL ile API çağrısı yap
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postDataJson)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            logMessage("HATA: cURL hatası - $curlError");
            $errorCount++;
            
            // Hata durumunda veritabanını güncelle
            $errorUpdateSql = "UPDATE API_kullanici SET 
                              api_iris_kullanici_response_code = 0,
                              api_iris_kullanici_response_message = ?,
                              api_iris_kullanici_raw_response = ?
                              WHERE api_iris_kullanici_ID = ?";
            $errorStmt = $conn->prepare($errorUpdateSql);
            $errorStmt->execute(['cURL Hatası: ' . $curlError, $response, $userId]);
            
            continue;
        }
        
        logMessage("HTTP Kodu: $httpCode");
        
        // API yanıtını decode et
        $apiResponse = json_decode($response, true);
        
        // Response message'ı belirle
        $responseMessage = null;
        if ($apiResponse && is_array($apiResponse)) {
            if (isset($apiResponse['ResponseMessage']) && !empty($apiResponse['ResponseMessage'])) {
                $responseMessage = $apiResponse['ResponseMessage'];
            } elseif (isset($apiResponse['responseMessage']) && !empty($apiResponse['responseMessage'])) {
                $responseMessage = $apiResponse['responseMessage'];
            } elseif (isset($apiResponse['message']) && !empty($apiResponse['message'])) {
                $responseMessage = $apiResponse['message'];
            } elseif (isset($apiResponse['status']) && !empty($apiResponse['status'])) {
                $responseMessage = $apiResponse['status'];
            } elseif (isset($apiResponse['error']) && !empty($apiResponse['error'])) {
                $responseMessage = $apiResponse['error'];
            }
        }
        
        if (is_null($responseMessage) && $httpCode !== 200) {
            $responseMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 100);
        }
        
        if ($httpCode !== 200) {
            logMessage("HATA: HTTP $httpCode - " . ($responseMessage ?? 'Bilinmeyen hata'));
            $errorCount++;
            
            // Hata durumunda veritabanını güncelle
            $errorUpdateSql = "UPDATE API_kullanici SET 
                              api_iris_kullanici_response_code = ?,
                              api_iris_kullanici_response_message = ?,
                              api_iris_kullanici_raw_response = ?
                              WHERE api_iris_kullanici_ID = ?";
            $errorStmt = $conn->prepare($errorUpdateSql);
            $errorStmt->execute([$httpCode, $responseMessage, $response, $userId]);
            
            continue;
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("HATA: Geçersiz JSON - " . json_last_error_msg());
            $errorCount++;
            
            // Hata durumunda veritabanını güncelle
            $errorUpdateSql = "UPDATE API_kullanici SET 
                              api_iris_kullanici_response_code = ?,
                              api_iris_kullanici_response_message = ?,
                              api_iris_kullanici_raw_response = ?
                              WHERE api_iris_kullanici_ID = ?";
            $errorStmt = $conn->prepare($errorUpdateSql);
            $errorStmt->execute([$httpCode, 'JSON Hatası: ' . json_last_error_msg(), $response, $userId]);
            
            continue;
        }
        
        // Token'ı al
        $newToken = null;
        if (isset($apiResponse['token'])) {
            $newToken = $apiResponse['token'];
        } elseif (isset($apiResponse['Token'])) {
            $newToken = $apiResponse['Token'];
        } elseif (isset($apiResponse['access_token'])) {
            $newToken = $apiResponse['access_token'];
        } elseif (isset($apiResponse['data']['token'])) {
            $newToken = $apiResponse['data']['token'];
        } else {
            if (is_string($apiResponse)) {
                $newToken = $apiResponse;
            }
        }
        
        if (empty($newToken)) {
            logMessage("HATA: Token bulunamadı - " . ($responseMessage ?? 'Yanıtta token yok'));
            $errorCount++;
            
            // Hata durumunda veritabanını güncelle
            $errorUpdateSql = "UPDATE API_kullanici SET 
                              api_iris_kullanici_response_code = ?,
                              api_iris_kullanici_response_message = ?,
                              api_iris_kullanici_raw_response = ?
                              WHERE api_iris_kullanici_ID = ?";
            $errorStmt = $conn->prepare($errorUpdateSql);
            $errorStmt->execute([$httpCode, 'Token bulunamadı: ' . ($responseMessage ?? ''), $response, $userId]);
            
            continue;
        }
        
        // Token'ı veritabanına kaydet
        $updateSql = "UPDATE API_kullanici SET 
                     api_iris_kullanici_token = ?, 
                     api_iris_kullanici_tokenGuncellemeTarihi = GETDATE(),
                     api_iris_kullanici_response_code = ?,
                     api_iris_kullanici_response_message = ?,
                     api_iris_kullanici_raw_response = ?
                     WHERE api_iris_kullanici_ID = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$newToken, $httpCode, $responseMessage, $response, $userId]);
        
        $tokenPreview = substr($newToken, 0, 20) . '...';
        logMessage("BAŞARILI: Token yenilendi - $tokenPreview");
        $successCount++;
        
        // API'ye fazla yük vermemek için kısa bir bekleme
        sleep(2);
    }
    
    logMessage("---");
    logMessage("=== İşlem Tamamlandı ===");
    logMessage("Toplam: $totalUsers kullanıcı");
    logMessage("Başarılı: $successCount");
    logMessage("Hatalı: $errorCount");
    logMessage("Atlandı: " . ($totalUsers - $successCount - $errorCount));
    
    // Web modunda footer ekle
    if ($isWebMode) {
        echo "</pre>";
        echo "<hr>";
        echo "<p class='success'>✓ İşlem tamamlandı!</p>";
        echo "<p>Log dosyası: <code>" . htmlspecialchars($logFile) . "</code></p>";
        echo "<p><a href='?key=" . htmlspecialchars($_GET['key'] ?? '') . "' style='color:#569cd6;'>Tekrar Çalıştır</a></p>";
        echo "</body></html>";
    }
    
} catch (Exception $e) {
    logMessage("KRITIK HATA: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    
    // Web modunda footer ekle
    if ($isWebMode) {
        echo "</pre>";
        echo "<hr>";
        echo "<p class='error'>✗ Hata oluştu!</p>";
        echo "</body></html>";
    }
    
    exit(1);
}

exit(0);
