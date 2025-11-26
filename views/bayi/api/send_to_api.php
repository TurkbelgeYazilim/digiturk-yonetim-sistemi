<?php
// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

// JSON response
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_POST['api_data']) || !isset($_POST['basvuru_id'])) {
        throw new Exception('Gerekli parametreler eksik.');
    }
    
    $apiData = json_decode($_POST['api_data'], true);
    $basvuruId = $_POST['basvuru_id'];
    
    if (!$apiData) {
        throw new Exception('API verileri geçersiz.');
    }
    
    $conn = getDatabaseConnection();
    
    // Yetki kontrolü
    $isAdmin = isset($currentUser['group_id']) && $currentUser['group_id'] == 1;
    if (!$isAdmin) {
        // Sayfa yetkisini kontrol et
        $currentPageUrl = 'api_basvuru_yonetimi.php';
        $yetkiSql = "SELECT tsy.kendi_kullanicini_gor
                     FROM dbo.tanim_sayfalar ts
                     INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
                     WHERE ts.sayfa_url = ? AND tsy.user_group_id = ? AND ts.durum = 1 AND tsy.durum = 1";
        $yetkiStmt = $conn->prepare($yetkiSql);
        $yetkiStmt->execute([$currentPageUrl, $currentUser['group_id']]);
        $yetkiResult = $yetkiStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($yetkiResult && $yetkiResult['kendi_kullanicini_gor'] == 1) {
            // Kendi kullanıcısı kontrolü
            $basvuruCheckSql = "SELECT ak.users_ID 
                                FROM API_basvuruListesi bl
                                LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
                                WHERE bl.API_basvuru_ID = ?";
            $basvuruCheckStmt = $conn->prepare($basvuruCheckSql);
            $basvuruCheckStmt->execute([$basvuruId]);
            $basvuruUser = $basvuruCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$basvuruUser || $basvuruUser['users_ID'] != $currentUser['id']) {
                throw new Exception('Bu başvuruya erişim yetkiniz yok.');
            }
        }
    }
    
    // API çağrısını yap
    $ch = curl_init();
    
    $headers = [
        'Content-Type: application/json',
        'Token: ' . $apiData['token']
    ];
    
    $isStatusCheck = isset($apiData['is_status_check']) && $apiData['is_status_check'];
    
    // Log kaydetme için değişkenler
    $logRequestBody = null;
    $logIslemTipi = $isStatusCheck ? 'Durum Sorgulama' : 'Normal Başvuru';
    
    // Token'ı maskele (baştan 5, sondan 5)
    $token = $apiData['token'];
    $maskedToken = substr($token, 0, 5) . str_repeat('*', max(0, strlen($token) - 10)) . substr($token, -5);
    
    if ($isStatusCheck) {
        // Durum sorgulama için POST request (boş body)
        $logRequestBody = null;
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiData['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30
        ]);
    } else {
        // Normal başvuru için POST request
        $logRequestBody = json_encode($apiData['body'], JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiData['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($apiData['body']),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30
        ]);
    }
    
    $apiResponse = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('API bağlantı hatası: ' . $curlError);
    }
    
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
            // requestStatusCode -> API_basvuru_basvuru_durum_ID
            if (isset($apiResponseData['data']['requestStatusCode'])) {
                $basvuruDurumId = (int)$apiResponseData['data']['requestStatusCode'];
            }
            
            // responseCode
            if (isset($apiResponseData['responseCode'])) {
                $responseCodeValue = $apiResponseData['responseCode'];
            } elseif (isset($apiResponseData['ResponseCode'])) {
                $responseCodeValue = $apiResponseData['ResponseCode'];
            }
            
            // responseMessage - sadece responseMessage kullan, null ise boş string
            if (isset($apiResponseData['responseMessage']) && $apiResponseData['responseMessage'] !== null) {
                $responseMessage = $apiResponseData['responseMessage'];
            } elseif (isset($apiResponseData['ResponseMessage']) && $apiResponseData['ResponseMessage'] !== null) {
                $responseMessage = $apiResponseData['ResponseMessage'];
            } else {
                // null veya yoksa requestStatusTitle kullan
                if (isset($apiResponseData['data']['requestStatusTitle'])) {
                    $responseMessage = $apiResponseData['data']['requestStatusTitle'];
                } else {
                    $responseMessage = null;
                }
            }
            
            // Varsa diğer bilgileri de al
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
            // data.resultCode varsa kullan (öncelikli)
            if (isset($apiResponseData['data']['resultCode'])) {
                $responseCodeValue = $apiResponseData['data']['resultCode'];
            }
            // responseCode varsa kullan (alternatif)
            elseif (isset($apiResponseData['responseCode'])) {
                $responseCodeValue = $apiResponseData['responseCode'];
            }
            // ResponseCode varsa kullan (büyük harf kontrolü)
            elseif (isset($apiResponseData['ResponseCode'])) {
                $responseCodeValue = $apiResponseData['ResponseCode'];
            }
            
            // Müşteri numarası
            if (isset($apiResponseData['data']['accountNumber'])) {
                $musteriNo = $apiResponseData['data']['accountNumber'];
            }
            
            // Talep kayıt numarası
            if (isset($apiResponseData['data']['requestId'])) {
                $talepKayitNo = $apiResponseData['data']['requestId'];
            }
            
            // Memo ID (Case ID)
            if (isset($apiResponseData['data']['caseId'])) {
                $memoId = $apiResponseData['data']['caseId'];
            }
            
            // responseCode = 0 ise özel mesaj oluştur
            if ($responseCodeValue === 0 && $musteriNo && $talepKayitNo && $memoId) {
                $responseMessage = "Müşteri No: {$musteriNo}, Talep Kayıt No: {$talepKayitNo}, Memo ID: {$memoId}";
            }
            // Diğer durumlar için standart mesajları kullan
            else {
                // resultMessage varsa kullan
                if (isset($apiResponseData['data']['resultMessage'])) {
                    $responseMessage = $apiResponseData['data']['resultMessage'];
                }
                // responseMessage varsa kullan (alternatif)
                elseif (isset($apiResponseData['responseMessage'])) {
                    $responseMessage = $apiResponseData['responseMessage'];
                }
                // ResponseMessage varsa kullan (büyük harf kontrolü)
                elseif (isset($apiResponseData['ResponseMessage'])) {
                    $responseMessage = $apiResponseData['ResponseMessage'];
                }
                // message varsa kullan (genel)
                elseif (isset($apiResponseData['message'])) {
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
        } else {
            // ResponseCode bulunamazsa null bırak
            $responseCodeId = null;
        }
    }
    
    // Başvuruyu güncelle
    if ($isStatusCheck) {
        // Durum sorgulama için başvuru durumu güncelle (ResponseMessage hariç)
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
        // Normal başvuru için tüm alanları güncelle
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
    
    // HTTP status kontrolü
    $isSuccess = ($httpStatus >= 200 && $httpStatus < 300);
    
    // Log kaydet
    try {
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
            $currentUser['id'],
            $logIslemTipi,
            $apiData['url'],
            'POST',
            $logRequestBody,
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
        // Log kaydetme hatası sessizce yoksay
    }
    
    // Debug bilgisi (sadece admin için)
    $debugInfo = null;
    if ($isAdmin) {
        $debugInfo = [
            'isStatusCheck' => $isStatusCheck,
            'responseCodeValue' => $responseCodeValue,
            'responseCodeId' => $responseCodeId,
            'basvuruDurumId' => $basvuruDurumId,
            'musteriNo' => $musteriNo,
            'talepKayitNo' => $talepKayitNo,
            'memoId' => $memoId
        ];
    }
    
    // Başarılı yanıt
    $response = [
        'success' => $isSuccess,
        'http_status' => $httpStatus,
        'api_response' => $apiResponseData ?: $apiResponse,
        'message' => $isSuccess ? 'API çağrısı başarıyla tamamlandı.' : 'API HTTP ' . $httpStatus . ' hatası döndü.'
    ];
    
    if ($debugInfo) {
        $response['debug'] = $debugInfo;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Hata durumunda da log kaydet
    try {
        if (isset($conn) && isset($basvuruId) && isset($currentUser)) {
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
                           log_islem_basarili,
                           log_hata_mesaji,
                           log_olusturma_tarihi
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, GETDATE())";
            
            $logStmt = $conn->prepare($logSql);
            $logStmt->execute([
                $basvuruId,
                $currentUser['id'],
                isset($logIslemTipi) ? $logIslemTipi : null,
                isset($apiData['url']) ? $apiData['url'] : null,
                'POST',
                isset($logRequestBody) ? $logRequestBody : null,
                isset($maskedToken) ? $maskedToken : null,
                isset($httpStatus) ? $httpStatus : null,
                isset($apiResponse) ? $apiResponse : null,
                $e->getMessage()
            ]);
        }
    } catch (Exception $logError) {
        // Log kaydetme hatası sessizce yoksay
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'http_status' => isset($httpStatus) ? $httpStatus : null,
        'api_response' => isset($apiResponse) ? $apiResponse : null
    ], JSON_UNESCAPED_UNICODE);
}
