<?php
/**
 * Cron Job - Otomatik API Başvuru Gönderimi
 * Her 2 dakikada bir çalışır ve ResponseCode NULL olan başvuruları API'ye gönderir
 * 
 * Web Test URL: https://digiturk.ilekasoft.com/api/cron_basvuru_gonder.php?key=CRON_SECRET_KEY_2025
 * 
 * Plesk Cron Ayarı:
 * Zamanlama: Her 2 dakika
 * Pattern: * / 2   *   *   *   * (boşluksuz yaz)
 * Komut: curl "https://digiturk.ilekasoft.com/api/cron_basvuru_gonder.php?key=CRON_SECRET_KEY_2025"
 * 
 * Optimizasyon Notu:
 * - MAX_KAYIT=3: Ortalama 62sn, en fazla 92sn sürer
 * - cURL timeout=60sn artırıldı, timeout sorunu giderildi
 * - Her 2 dakikada çalışma güvenli ve yeterli marj sağlar
 */

// ===== PHP TIMEOUT AYARLARI =====
set_time_limit(300);                    // 5 dakika (300 saniye) - FastCGI timeout'u aşmamak için
ini_set('max_execution_time', '300');   
ignore_user_abort(true);                // Kullanıcı bağlantıyı kesse bile devam et
// NOT: Timezone php.ini'de global olarak Europe/Istanbul olarak ayarlandı

// ===== AYARLAR =====
$cronConfig = require_once __DIR__ . '/../config/cron.php';
$SECRET_KEY = $cronConfig['secret_key'];
$MAX_KAYIT = 3;                         // Her çalışmada işlenecek maksimum kayıt (daha da azaltıldı)
$DENEME_LIMIT = 3;                      // Maksimum deneme sayısı
$KAYIT_ARASI_BEKLEME = 1;               // Saniye - API rate limiting için (azaltıldı)
$CURL_TIMEOUT = 60;                     // cURL timeout - 60 saniye (API yanıt süreleri uzun olabiliyor)
$CURL_CONNECTTIMEOUT = 10;              // Bağlantı timeout - 10 saniye

// Güvenlik kontrolü - URL'den çağrılıyorsa key kontrolü yap
if (php_sapi_name() !== 'cli') {
    $providedKey = $_GET['key'] ?? '';
    
    if ($providedKey !== $SECRET_KEY) {
        http_response_code(403);
        die('Yetkisiz erişim! Geçersiz key.');
    }
    
    // Web'den çalıştırıldığında çıktıyı HTML olarak formatla
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Cron Başvuru Gönderimi</title>";
    echo "<style>body{font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:20px;}";
    echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#9cdcfe;}.warning{color:#dcdcaa;}</style></head><body>";
    echo "<h2>🚀 Otomatik API Başvuru Gönderimi - Cron Job</h2>";
    echo "<pre>";
}

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log dosyası
$logFile = __DIR__ . '/../logs/cron_basvuru_log.txt';
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
        } elseif (strpos($message, 'HATA') !== false || strpos($message, 'hatası') !== false || strpos($message, 'Başarısız') !== false) {
            $class = 'error';
        } elseif (strpos($message, 'UYARI') !== false || strpos($message, 'Atlandı') !== false) {
            $class = 'warning';
        } else {
            $class = 'info';
        }
        echo "<span class='$class'>" . htmlspecialchars($logMessage) . "</span>";
    } else {
        echo $logMessage;
    }
}

logMessage("=== Otomatik API Başvuru Gönderimi Başladı ===");
logMessage("Maksimum Kayıt: $MAX_KAYIT, Deneme Limit: $DENEME_LIMIT");

try {
    // Veritabanı bağlantısı
    require_once __DIR__ . '/../auth.php';
    $conn = getDatabaseConnection();
    
    logMessage("Veritabanı bağlantısı başarılı");
    
    // Gönderilecek başvuruları getir
    $sql = "SELECT TOP $MAX_KAYIT
                bl.API_basvuru_ID,
                bl.API_basvuru_bbkAddressCode,
                bl.API_basvuru_email,
                bl.API_basvuru_phoneCountryNumber,
                bl.API_basvuru_phoneAreaNumber,
                bl.API_basvuru_phoneNumber,
                bl.API_basvuru_birthDate,
                bl.API_basvuru_citizenNumber,
                bl.API_basvuru_firstName,
                bl.API_basvuru_surname,
                bl.API_basvuru_genderType,
                bl.API_basvuru_identityCardType_ID,
                bl.API_basvuru_CampaignList_ID,
                bl.API_basvuru_Paket_ID,
                bl.API_basvuru_TalepKayitNo,
                bl.API_basvuru_gonderim_deneme_sayisi,
                bl.API_basvuru_kullanici_ID,
                ak.api_iris_kullanici_token,
                ak.api_iris_kullanici_LoginCd,
                ct.API_GetCardTypeList_card_code,
                u.first_name,
                u.last_name,
                cam.API_CampaignList_CampaignName
            FROM API_basvuruListesi bl
            LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
            LEFT JOIN API_GetCardTypeList ct ON bl.API_basvuru_identityCardType_ID = ct.API_GetCardTypeList_ID
            LEFT JOIN users u ON ak.users_ID = u.id
            LEFT JOIN API_CampaignList cam ON bl.API_basvuru_CampaignList_ID = cam.API_CampaignList_ID
            WHERE bl.API_basvuru_ResponseCode_ID IS NULL
              AND (bl.API_basvuru_gonderim_deneme_sayisi < ? OR bl.API_basvuru_gonderim_deneme_sayisi IS NULL)
              AND bl.API_basvuru_otomatik_gonderim = 1
              AND ak.api_iris_kullanici_durum = 1
              AND ak.api_iris_kullanici_token IS NOT NULL
              AND (
                bl.API_basvuru_son_gonderim_denemesi IS NULL
                OR DATEDIFF(MINUTE, bl.API_basvuru_son_gonderim_denemesi, GETDATE()) >= 5
              )
            ORDER BY bl.API_basvuru_olusturma_tarih DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$DENEME_LIMIT]);
    $basvurular = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCount = count($basvurular);
    logMessage("$totalCount adet gönderilecek başvuru bulundu");
    
    if ($totalCount == 0) {
        logMessage("İşlenecek başvuru yok, işlem sonlandırılıyor");
        logMessage("=== İşlem Tamamlandı ===");
        
        if ($isWebMode) {
            echo "</pre><hr><p class='info'>ℹ Gönderilecek başvuru bulunamadı.</p></body></html>";
        }
        exit(0);
    }
    
    // Başarı ve hata sayaçları
    $successCount = 0;
    $errorCount = 0;
    $skipCount = 0;
    
    // Her başvuru için işlem yap
    foreach ($basvurular as $basvuru) {
        $basvuruId = $basvuru['API_basvuru_ID'];
        $kullanici = trim($basvuru['first_name'] . ' ' . $basvuru['last_name']);
        $kampanya = $basvuru['API_CampaignList_CampaignName'];
        $denemeSayisi = (int)$basvuru['API_basvuru_gonderim_deneme_sayisi'];
        
        logMessage("---");
        logMessage("İşleniyor: Başvuru ID=$basvuruId, Kullanıcı: $kullanici, Kampanya: $kampanya");
        logMessage("Mevcut deneme sayısı: $denemeSayisi/$DENEME_LIMIT");
        
        // Token kontrolü
        if (empty($basvuru['api_iris_kullanici_token'])) {
            logMessage("UYARI: Token bulunamadı, atlanıyor");
            $skipCount++;
            continue;
        }
        
        // TalepKayitNo doluysa durum sorgulama, değilse normal başvuru
        $isStatusCheck = !empty($basvuru['API_basvuru_TalepKayitNo']);
        
        // API URL'ini belirle
        $apiLinkSql = "SELECT api_iris_Address_URL FROM API_Link WHERE api_iris_Address_ID = ?";
        $apiLinkStmt = $conn->prepare($apiLinkSql);
        
        if ($isStatusCheck) {
            // Durum sorgulama API'si (ID: 17)
            $apiLinkStmt->execute([17]);
            $apiLink = $apiLinkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$apiLink) {
                logMessage("HATA: Durum sorgulama API URL bulunamadı");
                $errorCount++;
                continue;
            }
            $apiUrl = $apiLink['api_iris_Address_URL'] . '?RequestId=' . $basvuru['API_basvuru_TalepKayitNo'];
            logMessage("İşlem Tipi: Durum Sorgulama (API Link ID: 17)");
        } else {
            // Normal başvuru - Kampanyaya göre API seç
            if ($basvuru['API_basvuru_CampaignList_ID'] == 1) {
                $apiLinkStmt->execute([15]); // Satellite
                $apiLinkId = 15;
            } elseif ($basvuru['API_basvuru_CampaignList_ID'] == 2) {
                $apiLinkStmt->execute([16]); // Neo
                $apiLinkId = 16;
            } else {
                logMessage("HATA: Geçersiz kampanya tipi");
                $errorCount++;
                continue;
            }
            
            $apiLink = $apiLinkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$apiLink) {
                logMessage("HATA: API URL bulunamadı");
                $errorCount++;
                continue;
            }
            
            $apiUrl = $apiLink['api_iris_Address_URL'];
            logMessage("İşlem Tipi: Normal Başvuru (API Link ID: $apiLinkId)");
        }
        
        logMessage("API URL: $apiUrl");
        
        // Body hazırla (sadece normal başvuru için)
        $bodyData = null;
        
        if (!$isStatusCheck) {
            // Paket bilgilerini getir
            $paketSql = "";
            $paketParams = [];
            
            if ($basvuru['API_basvuru_CampaignList_ID'] == 1) {
                // Satellite
                $paketSql = "SELECT 
                                API_GetSatelliteCampaignList_offerToId as offerToId,
                                API_GetSatelliteCampaignList_offerFromId as offerFromId,
                                API_GetSatelliteCampaignList_billFrequency as frequency,
                                API_GetSatelliteCampaignList_billFrequencyTypeCd as frequencyCode
                             FROM API_GetSatelliteCampaignList 
                             WHERE API_GetSatelliteCampaignList_ID = ?";
                $paketParams = [$basvuru['API_basvuru_Paket_ID']];
            } elseif ($basvuru['API_basvuru_CampaignList_ID'] == 2) {
                // Neo
                $paketSql = "SELECT 
                                API_GetNEOCampaignList_offerToId as offerToId,
                                API_GetNEOCampaignList_offerFromId as offerFromId,
                                API_GetNEOCampaignList_billFrequency as frequency,
                                API_GetNEOCampaignList_billFrequencyTypeCd as frequencyCode
                             FROM API_GetNEOCampaignList 
                             WHERE API_GetNEOCampaignList_ID = ?";
                $paketParams = [$basvuru['API_basvuru_Paket_ID']];
            }
            
            $paketStmt = $conn->prepare($paketSql);
            $paketStmt->execute($paketParams);
            $paketBilgi = $paketStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$paketBilgi) {
                logMessage("HATA: Paket bilgisi bulunamadı");
                $errorCount++;
                continue;
            }
            
            // JSON Body oluştur
            $bodyData = [
                'bbkAddressCode' => $basvuru['API_basvuru_bbkAddressCode'],
                'email' => $basvuru['API_basvuru_email'],
                'phoneCountryNumber' => $basvuru['API_basvuru_phoneCountryNumber'],
                'phoneAreaNumber' => $basvuru['API_basvuru_phoneAreaNumber'],
                'phoneNumber' => $basvuru['API_basvuru_phoneNumber'],
                'birthDate' => $basvuru['API_basvuru_birthDate'],
                'citizenNumber' => $basvuru['API_basvuru_citizenNumber'],
                'firstName' => $basvuru['API_basvuru_firstName'],
                'surname' => $basvuru['API_basvuru_surname'],
                'genderType' => $basvuru['API_basvuru_genderType'],
                'identityCardType' => $basvuru['API_GetCardTypeList_card_code'],
                'orderBasketSimulateItemList' => [
                    [
                        'offerToId' => (int)$paketBilgi['offerToId'],
                        'offerFromId' => (int)$paketBilgi['offerFromId'],
                        'frequency' => (int)$paketBilgi['frequency'],
                        'frequencyCode' => $paketBilgi['frequencyCode']
                    ]
                ],
                'orderBasketSimulateItemGiftList' => []
            ];
        }
        
        // API çağrısı yap
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'Token: ' . $basvuru['api_iris_kullanici_token']
        ];
        
        // Token'ı maskele (log için) - Max 50 karakter
        $token = $basvuru['api_iris_kullanici_token'];
        if (strlen($token) > 50) {
            $maskedToken = substr($token, 0, 20) . '...[' . (strlen($token) - 40) . ' chars]...' . substr($token, -20);
        } else {
            $maskedToken = substr($token, 0, 10) . '***' . substr($token, -10);
        }
        
        if ($isStatusCheck) {
            // Durum sorgulama için POST request (boş body)
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([]),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => $CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => $CURL_CONNECTTIMEOUT,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 30,
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false
            ]);
        } else {
            // Normal başvuru için POST request
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($bodyData),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => $CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => $CURL_CONNECTTIMEOUT,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 30,
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false
            ]);
        }
        
        $apiResponse = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        // cURL hatası kontrolü
        if ($curlError) {
            $isTimeoutError = (stripos($curlError, 'timeout') !== false || $curlErrno == CURLE_OPERATION_TIMEDOUT);
            
            if ($isTimeoutError) {
                logMessage("⚠ TIMEOUT: $curlError (Deneme: " . ($denemeSayisi + 1) . "/$DENEME_LIMIT)");
                
                // Timeout hatalarında son_gonderim_denemesi GÜNCELLENMESİN (hemen tekrar denensin)
                // Sadece deneme sayısını artır
                $updateDenemeSql = "UPDATE API_basvuruListesi SET 
                                    API_basvuru_gonderim_deneme_sayisi = ?
                                    WHERE API_basvuru_ID = ?";
                $updateDenemeStmt = $conn->prepare($updateDenemeSql);
                $updateDenemeStmt->execute([$denemeSayisi + 1, $basvuruId]);
                
                logMessage("💡 Timeout hatası, bir sonraki çalışmada hemen tekrar denenecek");
            } else {
                logMessage("HATA: cURL hatası - $curlError");
                
                // Diğer hatalar için normal güncelleme
                $updateDenemeSql = "UPDATE API_basvuruListesi SET 
                                    API_basvuru_gonderim_deneme_sayisi = ?,
                                    API_basvuru_son_gonderim_denemesi = GETDATE()
                                    WHERE API_basvuru_ID = ?";
                $updateDenemeStmt = $conn->prepare($updateDenemeSql);
                $updateDenemeStmt->execute([$denemeSayisi + 1, $basvuruId]);
            }
            
            $errorCount++;
            continue;
        }
        
        // Başarılı istek - deneme sayısını artır ve son deneme tarihini güncelle
        $updateDenemeSql = "UPDATE API_basvuruListesi SET 
                            API_basvuru_gonderim_deneme_sayisi = ?,
                            API_basvuru_son_gonderim_denemesi = GETDATE()
                            WHERE API_basvuru_ID = ?";
        $updateDenemeStmt = $conn->prepare($updateDenemeSql);
        $updateDenemeStmt->execute([$denemeSayisi + 1, $basvuruId]);
        
        
        logMessage("HTTP Status: $httpStatus");
        
        // API yanıtını parse et
        $apiResponseData = json_decode($apiResponse, true);
        
        $responseCodeValue = null;
        $responseCodeId = null;
        $responseMessage = $apiResponse;
        $musteriNo = null;
        $talepKayitNo = null;
        $memoId = null;
        $basvuruDurumId = null;
        
        if ($apiResponseData) {
            if ($isStatusCheck) {
                // Durum sorgulama yanıtı
                if (isset($apiResponseData['data']['requestStatusCode'])) {
                    $basvuruDurumId = (int)$apiResponseData['data']['requestStatusCode'];
                }
                
                if (isset($apiResponseData['responseCode'])) {
                    $responseCodeValue = $apiResponseData['responseCode'];
                } elseif (isset($apiResponseData['ResponseCode'])) {
                    $responseCodeValue = $apiResponseData['ResponseCode'];
                }
                
                if (isset($apiResponseData['responseMessage']) && $apiResponseData['responseMessage'] !== null) {
                    $responseMessage = $apiResponseData['responseMessage'];
                } elseif (isset($apiResponseData['ResponseMessage']) && $apiResponseData['ResponseMessage'] !== null) {
                    $responseMessage = $apiResponseData['ResponseMessage'];
                } elseif (isset($apiResponseData['data']['requestStatusTitle'])) {
                    $responseMessage = $apiResponseData['data']['requestStatusTitle'];
                } else {
                    $responseMessage = null;
                }
                
                if (isset($apiResponseData['data']['memberNo']) && $apiResponseData['data']['memberNo'] > 0) {
                    $musteriNo = $apiResponseData['data']['memberNo'];
                }
                if (isset($apiResponseData['data']['requestID'])) {
                    $talepKayitNo = $apiResponseData['data']['requestID'];
                }
                if (isset($apiResponseData['data']['ticketID'])) {
                    $memoId = $apiResponseData['data']['ticketID'];
                }
            } else {
                // Normal başvuru yanıtı
                if (isset($apiResponseData['data']['resultCode'])) {
                    $responseCodeValue = $apiResponseData['data']['resultCode'];
                } elseif (isset($apiResponseData['responseCode'])) {
                    $responseCodeValue = $apiResponseData['responseCode'];
                } elseif (isset($apiResponseData['ResponseCode'])) {
                    $responseCodeValue = $apiResponseData['ResponseCode'];
                }
                
                if (isset($apiResponseData['data']['accountNumber'])) {
                    $musteriNo = $apiResponseData['data']['accountNumber'];
                }
                
                if (isset($apiResponseData['data']['requestId'])) {
                    $talepKayitNo = $apiResponseData['data']['requestId'];
                }
                
                if (isset($apiResponseData['data']['caseId'])) {
                    $memoId = $apiResponseData['data']['caseId'];
                }
                
                if ($responseCodeValue === 0 && $musteriNo && $talepKayitNo && $memoId) {
                    $responseMessage = "Müşteri No: {$musteriNo}, Talep Kayıt No: {$talepKayitNo}, Memo ID: {$memoId}";
                } else {
                    if (isset($apiResponseData['data']['resultMessage'])) {
                        $responseMessage = $apiResponseData['data']['resultMessage'];
                    } elseif (isset($apiResponseData['responseMessage'])) {
                        $responseMessage = $apiResponseData['responseMessage'];
                    } elseif (isset($apiResponseData['ResponseMessage'])) {
                        $responseMessage = $apiResponseData['ResponseMessage'];
                    } elseif (isset($apiResponseData['message'])) {
                        $responseMessage = $apiResponseData['message'];
                    }
                }
            }
        }
        
        // ResponseCode_Code ile ResponseCode_ID'yi bul
        if ($responseCodeValue !== null) {
            $rcSql = "SELECT ResponseCode_ID FROM API_ResponseCode WHERE ResponseCode_Code = ? AND ResponseCode_Durum = 1";
            $rcStmt = $conn->prepare($rcSql);
            $rcStmt->execute([$responseCodeValue]);
            $rcResult = $rcStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($rcResult) {
                $responseCodeId = $rcResult['ResponseCode_ID'];
            }
        }
        
        // Başvuruyu güncelle
        if ($isStatusCheck) {
            // Durum sorgulama için
            $updateSql = "UPDATE API_basvuruListesi SET 
                          API_basvuru_basvuru_durum_ID = ?,
                          API_basvuru_ResponseCode_ID = ?,
                          API_basvuru_guncelleme_tarihi = GETDATE()
                          WHERE API_basvuru_ID = ?";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                $basvuruDurumId,
                $responseCodeId,
                $basvuruId
            ]);
        } else {
            // Normal başvuru için
            $updateSql = "UPDATE API_basvuruListesi SET 
                          API_basvuru_ResponseCode_ID = ?,
                          API_basvuru_ResponseMessage = ?,
                          API_basvuru_MusteriNo = ?,
                          API_basvuru_TalepKayitNo = ?,
                          API_basvuru_MemoID = ?,
                          API_basvuru_guncelleme_tarihi = GETDATE()
                          WHERE API_basvuru_ID = ?";
            
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                $responseCodeId,
                $responseMessage,
                $musteriNo,
                $talepKayitNo,
                $memoId,
                $basvuruId
            ]);
        }
        
        // ===== Case Çakışması Kontrolü =====
        // "Bir Dakika İçerisinde Aynı Case" hatası varsa, deneme sayısını sıfırla ve 2 dakika beklet
        if (!empty($responseMessage) && stripos($responseMessage, 'Bir Dakika İçerisinde Aynı Üyelikte Aynı Case') !== false) {
            logMessage("⚠ Case çakışması tespit edildi, 2 dakika sonra tekrar denenecek");
            
            // Deneme sayısını sıfırla, ama son deneme tarihini koru (5 dakika sonra tekrar denensin)
            $resetCaseSql = "UPDATE API_basvuruListesi SET 
                            API_basvuru_gonderim_deneme_sayisi = 0,
                            API_basvuru_son_gonderim_denemesi = GETDATE()
                            WHERE API_basvuru_ID = ?";
            
            $resetCaseStmt = $conn->prepare($resetCaseSql);
            $resetCaseStmt->execute([$basvuruId]);
            
            logMessage("✓ Başvuru 5 dakika sonra tekrar gönderilecek");
            
            // Bu başvuruyu başarısız saymayalım, atlandı olarak sayalım
            $skipCount++;
            
            // Bir sonraki kayda geç
            if ($basvuru !== end($basvurular)) {
                sleep($KAYIT_ARASI_BEKLEME);
            }
            continue;
        }
        // ===== Case Çakışması Kontrolü Sonu =====
        
        // HTTP status kontrolü
        $isSuccess = ($httpStatus >= 200 && $httpStatus < 300);
        
        if ($isSuccess) {
            logMessage("BAŞARILI: ResponseCode=$responseCodeValue" . 
                      ($musteriNo ? ", Müşteri No: $musteriNo" : "") .
                      ($talepKayitNo ? ", Talep No: $talepKayitNo" : ""));
            $successCount++;
        } else {
            logMessage("Başarısız: HTTP $httpStatus" . ($responseMessage ? " - $responseMessage" : ""));
            logMessage("Deneme: " . ($denemeSayisi + 1) . "/$DENEME_LIMIT");
            $errorCount++;
        }
        
        // Log kaydı oluştur
        try {
            // Kullanıcı ID'sini belirle: Başvuruya ait kullanıcı varsa onu, yoksa sistem kullanıcısını (22) kullan
            $logKullaniciId = !empty($basvuru['API_basvuru_kullanici_ID']) 
                ? (int)$basvuru['API_basvuru_kullanici_ID'] 
                : 22;
            
            $logSql = "INSERT INTO API_Gonderim_Log (
                           log_basvuru_ID,
                           log_islem_yapan_kullanici_ID,
                           log_istek_tipi,
                           log_api_url,
                           log_api_method,
                           log_request_body,
                           log_request_token,
                           log_http_status,
                           log_response_body,
                           log_response_code,
                           log_response_message,
                           log_islem_basarili,
                           log_hata_mesaji,
                           log_kaydedilen_ResponseCodeID,
                           log_kaydedilen_MusteriNo,
                           log_kaydedilen_TalepKayitNo,
                           log_kaydedilen_MemoID,
                           log_kaydedilen_BasvuruDurumID,
                           log_olusturma_tarihi
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
            
            $logStmt = $conn->prepare($logSql);
            $logStmt->execute([
                $basvuruId,
                $logKullaniciId, // Başvuru sahibi veya sistem kullanıcısı
                $isStatusCheck ? 'Durum Sorgulama (Cron)' : 'Normal Başvuru (Cron)',
                $apiUrl,
                'POST',
                $isStatusCheck ? null : json_encode($bodyData, JSON_UNESCAPED_UNICODE),
                $maskedToken,
                $httpStatus,
                $apiResponse,
                $responseCodeValue,
                $responseMessage,
                $isSuccess ? 1 : 0,
                !$isSuccess ? 'API HTTP ' . $httpStatus . ' hatası döndü.' : null,
                $responseCodeId,
                $musteriNo,
                $talepKayitNo,
                $memoId,
                $basvuruDurumId
            ]);
        } catch (Exception $logError) {
            logMessage("UYARI: Log kaydedilemedi - " . $logError->getMessage());
        }
        
        // Bir sonraki kayıt için bekle (API rate limiting)
        if ($basvuru !== end($basvurular)) {
            sleep($KAYIT_ARASI_BEKLEME);
        }
    }
    
    logMessage("---");
    logMessage("=== İşlem Tamamlandı ===");
    logMessage("Toplam: $totalCount başvuru");
    logMessage("Başarılı: $successCount");
    logMessage("Başarısız: $errorCount");
    logMessage("Atlandı: $skipCount");
    
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
        echo "<tr><td><strong>Atlandı:</strong></td><td style='color:#dcdcaa;'>$skipCount</td></tr>";
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
