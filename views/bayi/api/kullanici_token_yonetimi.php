<?php
// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = "Kullanıcı ve Token Yönetimi";
$breadcrumbs = [
    ['title' => 'Kullanıcı ve Token Yönetimi']
];

// Debug modu kontrolü
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

// Admin yetkisi kontrolü
$isAdmin = isset($currentUser['group_id']) && $currentUser['group_id'] == 1;

// Sayfa yetki kontrolü
if (!checkPagePermission('kullanici_token_yonetimi.php')) {
    http_response_code(403);
    die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa yetkilerini belirle
if ($isAdmin) {
    // Admin için tüm yetkileri aç
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0, // 0 = Herkesi görebilir
        'ekle' => 1,
        'duzenle' => 1,
        'sil' => 1
    ];
} else {
    // Admin değilse normal yetki kontrolü yap
    try {
        $conn = getDatabaseConnection();
        
        // Mevcut sayfa URL'sini al
        $currentPageUrl = basename($_SERVER['PHP_SELF']);
        
        // Sayfa bilgisini ve yetkilerini çek
        $yetkiSql = "
            SELECT 
                tsy.gor,
                tsy.kendi_kullanicini_gor,
                tsy.ekle,
                tsy.duzenle,
                tsy.sil,
                tsy.durum as yetki_durum,
                ts.durum as sayfa_durum
            FROM dbo.tanim_sayfalar ts
            INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
            WHERE ts.sayfa_url = ?
            AND tsy.user_group_id = ?
            AND ts.durum = 1
            AND tsy.durum = 1
        ";
        
        $yetkiStmt = $conn->prepare($yetkiSql);
        $yetkiStmt->execute([$currentPageUrl, $currentUser['group_id']]);
        $yetkiResult = $yetkiStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($yetkiResult) {
            $sayfaYetkileri = [
                'gor' => (int)$yetkiResult['gor'],
                'kendi_kullanicini_gor' => (int)$yetkiResult['kendi_kullanicini_gor'],
                'ekle' => (int)$yetkiResult['ekle'],
                'duzenle' => (int)$yetkiResult['duzenle'],
                'sil' => (int)$yetkiResult['sil']
            ];
        } else {
            // Yetki tanımlanmamışsa varsayılan olarak sadece görme yetkisi ver
            $sayfaYetkileri = [
                'gor' => 1,
                'kendi_kullanicini_gor' => 1, // Sadece kendi verilerini görebilir
                'ekle' => 0,
                'duzenle' => 0,
                'sil' => 0
            ];
        }
    } catch (Exception $e) {
        // Hata durumunda güvenlik için minimal yetki ver
        $sayfaYetkileri = [
            'gor' => 1,
            'kendi_kullanicini_gor' => 1,
            'ekle' => 0,
            'duzenle' => 0,
            'sil' => 0
        ];
    }
}

// Görme yetkisi yoksa sayfaya erişimi engelle
if (!$sayfaYetkileri['gor']) {
    http_response_code(403);
    die('Bu sayfayı görme yetkiniz bulunmamaktadır.');
}

$message = '';
$messageType = '';

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'get_token':
                // Token bilgisini getir - AJAX için
                $id = $_POST['id'];
                
                $sql = "SELECT ak.api_iris_kullanici_token, ak.api_iris_kullanici_tokenGuncellemeTarihi,
                               ak.api_iris_kullanici_response_code, ak.api_iris_kullanici_response_message,
                               arc.ResponseCode_renk
                       FROM API_kullanici ak
                       LEFT JOIN API_ResponseCode arc ON ak.api_iris_kullanici_response_code = arc.ResponseCode_Code
                       WHERE ak.api_iris_kullanici_ID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'token' => $result['api_iris_kullanici_token'] ?? '',
                    'lastUpdate' => $result['api_iris_kullanici_tokenGuncellemeTarihi'] ? 
                                   date('d.m.Y H:i', strtotime($result['api_iris_kullanici_tokenGuncellemeTarihi'])) : '',
                    'responseCode' => $result['api_iris_kullanici_response_code'] ?? '',
                    'responseMessage' => $result['api_iris_kullanici_response_message'] ?? '',
                    'responseColor' => $result['ResponseCode_renk'] ?? ''
                ]);
                exit;
                
            case 'add':
                // Ekleme yetkisi kontrolü
                if (!$sayfaYetkileri['ekle']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                // Yeni kullanıcı ekleme
                // Kısıtlı kullanıcılar için hidden input'tan al, normal kullanıcılar için select'ten al
                $users_ID = !empty($_POST['users_ID_hidden']) ? $_POST['users_ID_hidden'] : $_POST['users_ID'];
                $organizationCd = $_POST['api_iris_kullanici_OrganisationCd'];
                $loginCd = $_POST['api_iris_kullanici_LoginCd'];
                $password = $_POST['api_iris_kullanici_Password'];
                $url = $_POST['api_iris_kullanici_URL'];
                $logoURL = $_POST['api_iris_kullanici_logoURL'];
                $durum = isset($_POST['api_iris_kullanici_durum']) ? 1 : 0;
                
                $sql = "INSERT INTO API_kullanici (users_ID, api_iris_kullanici_OrganisationCd, api_iris_kullanici_LoginCd, 
                       api_iris_kullanici_Password, api_iris_kullanici_URL, api_iris_kullanici_logoURL, api_iris_kullanici_durum) 
                       VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$users_ID, $organizationCd, $loginCd, $password, $url, $logoURL, $durum]);
                
                $message = 'API kullanıcısı başarıyla eklendi.';
                $messageType = 'success';
                break;
                
            case 'edit':
                // Düzenleme yetkisi kontrolü
                if (!$sayfaYetkileri['duzenle']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                // Kullanıcı düzenleme
                $id = $_POST['api_iris_kullanici_ID'];
                // Kısıtlı kullanıcılar için hidden input'tan al, normal kullanıcılar için select'ten al
                $users_ID = !empty($_POST['users_ID_hidden']) ? $_POST['users_ID_hidden'] : $_POST['users_ID'];
                $organizationCd = $_POST['api_iris_kullanici_OrganisationCd'];
                $loginCd = $_POST['api_iris_kullanici_LoginCd'];
                $password = $_POST['api_iris_kullanici_Password'];
                $url = $_POST['api_iris_kullanici_URL'];
                $logoURL = $_POST['api_iris_kullanici_logoURL'];
                $durum = isset($_POST['api_iris_kullanici_durum']) ? 1 : 0;
                
                $sql = "UPDATE API_kullanici SET users_ID = ?, api_iris_kullanici_OrganisationCd = ?, 
                       api_iris_kullanici_LoginCd = ?, api_iris_kullanici_Password = ?, 
                       api_iris_kullanici_URL = ?, api_iris_kullanici_logoURL = ?, api_iris_kullanici_durum = ? 
                       WHERE api_iris_kullanici_ID = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$users_ID, $organizationCd, $loginCd, $password, $url, $logoURL, $durum, $id]);
                
                // Güncelleme sonrası response bilgilerini al
                $responseSql = "SELECT ak.api_iris_kullanici_response_message, ak.api_iris_kullanici_response_code,
                                       arc.ResponseCode_Message, arc.ResponseCode_renk
                               FROM API_kullanici ak
                               LEFT JOIN API_ResponseCode arc ON ak.api_iris_kullanici_response_code = arc.ResponseCode_Code
                               WHERE ak.api_iris_kullanici_ID = ?";
                $responseStmt = $conn->prepare($responseSql);
                $responseStmt->execute([$id]);
                $responseData = $responseStmt->fetch();
                
                if ($responseData && !empty($responseData['ResponseCode_Message'])) {
                    // Response code'a göre mesaj ve renk belirle
                    $message = $responseData['ResponseCode_Message'] . ' - ' . $responseData['api_iris_kullanici_response_message'];
                    
                    // Renk koduna göre message type belirle
                    $responseColor = strtolower($responseData['ResponseCode_renk'] ?? '');
                    if (strpos($responseColor, 'green') !== false || strpos($responseColor, '#28a745') !== false) {
                        $messageType = 'success';
                    } elseif (strpos($responseColor, 'red') !== false || strpos($responseColor, '#dc3545') !== false) {
                        $messageType = 'danger';
                    } elseif (strpos($responseColor, 'yellow') !== false || strpos($responseColor, 'orange') !== false || strpos($responseColor, '#ffc107') !== false) {
                        $messageType = 'warning';
                    } else {
                        $messageType = 'info';
                    }
                } else {
                    $message = 'API kullanıcısı başarıyla güncellendi.';
                    $messageType = 'success';
                }
                break;
                
            case 'refresh_token':
                // Token yenileme yetkisi kontrolü
                if (!$sayfaYetkileri['duzenle']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                // Token yenileme
                $id = $_POST['id'];
                $isAjax = isset($_POST['ajax']) && $_POST['ajax'] == '1';
                
                // Kullanıcı bilgilerini al
                $userSql = "SELECT api_iris_kullanici_OrganisationCd, api_iris_kullanici_LoginCd, api_iris_kullanici_Password 
                           FROM API_kullanici WHERE api_iris_kullanici_ID = ?";
                $userStmt = $conn->prepare($userSql);
                $userStmt->execute([$id]);
                $userData = $userStmt->fetch();
                
                if (!$userData) {
                    throw new Exception('Kullanıcı bulunamadı. ID: ' . $id);
                }
                
                // Debug için kullanıcı bilgilerini logla
                if ($debugMode) {
                    error_log("Token yenileme - Kullanıcı ID: $id");
                    error_log("Organization: " . $userData['api_iris_kullanici_OrganisationCd']);
                    error_log("LoginCd: " . $userData['api_iris_kullanici_LoginCd']);
                }
                
                // API Address URL'ini al
                $addressSql = "SELECT api_iris_Address_URL FROM API_Link WHERE api_iris_Address_ID = 1";
                $addressStmt = $conn->prepare($addressSql);
                $addressStmt->execute();
                $addressData = $addressStmt->fetch();
                
                if (!$addressData || empty($addressData['api_iris_Address_URL'])) {
                    throw new Exception('API adresi bulunamadı. Lütfen API adres ayarlarını kontrol edin. (API_Link tablosunda ID=1 kaydı olmalı)');
                }
                
                $apiUrl = $addressData['api_iris_Address_URL'];
                
                // API çağrısı için veri hazırla
                $postData = [
                    'OrganisationCd' => $userData['api_iris_kullanici_OrganisationCd'],
                    'LoginCd' => $userData['api_iris_kullanici_LoginCd'],
                    'Password' => $userData['api_iris_kullanici_Password']
                ];
                
                // Debug için bilgileri sakla
                $debugInfo = [
                    'user_id' => $id,
                    'api_url' => $apiUrl,
                    'request_data' => $postData,
                    'request_json' => json_encode($postData),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'user_organization' => $userData['api_iris_kullanici_OrganisationCd'],
                    'user_login_cd' => $userData['api_iris_kullanici_LoginCd']
                ];
                
                // cURL ile API çağrısı yap
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen(json_encode($postData))
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlInfo = curl_getinfo($ch);
                curl_close($ch);
                
                // Debug bilgilerini güncelle
                $debugInfo['response_raw'] = $response;
                $debugInfo['http_code'] = $httpCode;
                $debugInfo['curl_error'] = $curlError;
                $debugInfo['curl_info'] = $curlInfo;
                
                // Debug modunda bilgileri session'a kaydet
                if ($debugMode) {
                    $_SESSION['token_debug'] = $debugInfo;
                }
                
                if ($curlError) {
                    throw new Exception('API çağrısı hatası: ' . $curlError);
                }
                
                // API yanıtını decode et (HTTP kod kontrol etmeden önce)
                $apiResponse = json_decode($response, true);
                
                // Debug bilgilerine yanıt detaylarını ekle
                if ($debugMode) {
                    $_SESSION['token_debug']['response_decoded'] = $apiResponse;
                    $_SESSION['token_debug']['json_decode_error'] = json_last_error_msg();
                }
                
                // Response message'ı belirle - API'den ResponseMessage alanını al (HTTP kod ne olursa olsun)
                $responseMessage = null; // Başlangıçta null olarak ayarla
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
                
                // Eğer response message hala null ise ve HTTP kodu 200 değilse hata mesajı oluştur
                if (is_null($responseMessage) && $httpCode !== 200) {
                    $responseMessage = 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 100);
                }
                
                if ($httpCode !== 200) {
                    $errorMsg = 'API yanıt hatası: HTTP ' . $httpCode;
                    if (!is_null($responseMessage)) {
                        $errorMsg .= ' - ' . $responseMessage;
                    }
                    throw new Exception($errorMsg);
                }
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('API yanıtı geçersiz JSON formatında: ' . json_last_error_msg());
                }
                
                // Token'ı al (API yanıtının yapısına göre ayarlanmalı)
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
                    // Eğer yanıt string ise direkt kullan
                    if (is_string($apiResponse)) {
                        $newToken = $apiResponse;
                    } else {
                        $errorMsg = 'API yanıtında token bulunamadı';
                        if (!is_null($responseMessage)) {
                            $errorMsg .= ' - ' . $responseMessage;
                        }
                        throw new Exception($errorMsg);
                    }
                }
                
                if (empty($newToken)) {
                    $errorMsg = 'Boş token alındı';
                    if (!is_null($responseMessage)) {
                        $errorMsg .= ' - ' . $responseMessage;
                    }
                    throw new Exception($errorMsg);
                }
                
                // Token'ı veritabanına kaydet ve response bilgilerini güncelle
                $updateSql = "UPDATE API_kullanici SET 
                             api_iris_kullanici_token = ?, 
                             api_iris_kullanici_tokenGuncellemeTarihi = GETDATE(),
                             api_iris_kullanici_response_code = ?,
                             api_iris_kullanici_response_message = ?,
                             api_iris_kullanici_raw_response = ?
                             WHERE api_iris_kullanici_ID = ?";
                
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([
                    $newToken, 
                    $httpCode, 
                    $responseMessage, 
                    $response, 
                    $id
                ]);
                
                // Debug bilgilerine son durumu ekle
                if ($debugMode) {
                    $_SESSION['token_debug']['new_token'] = $newToken;
                    $_SESSION['token_debug']['database_updated'] = true;
                    $_SESSION['token_debug']['response_code_saved'] = $httpCode;
                    $_SESSION['token_debug']['response_message_saved'] = $responseMessage;
                    $_SESSION['token_debug']['raw_response_saved'] = strlen($response) . ' karakter kaydedildi';
                }
                
                // Token yenileme sonrası response bilgilerini al
                $tokenResponseSql = "SELECT ak.api_iris_kullanici_response_message, ak.api_iris_kullanici_response_code,
                                            arc.ResponseCode_Message, arc.ResponseCode_renk
                                    FROM API_kullanici ak
                                    LEFT JOIN API_ResponseCode arc ON ak.api_iris_kullanici_response_code = arc.ResponseCode_Code
                                    WHERE ak.api_iris_kullanici_ID = ?";
                $tokenResponseStmt = $conn->prepare($tokenResponseSql);
                $tokenResponseStmt->execute([$id]);
                $tokenResponseData = $tokenResponseStmt->fetch();
                
                // Response mesajını belirle
                $tokenMessage = 'Token başarıyla yenilendi.';
                $tokenMessageType = 'success';
                
                if ($tokenResponseData && !empty($tokenResponseData['ResponseCode_Message'])) {
                    $tokenMessage = $tokenResponseData['ResponseCode_Message'] . ' - ' . $tokenResponseData['api_iris_kullanici_response_message'];
                    
                    // Renk koduna göre message type belirle
                    $responseColor = strtolower($tokenResponseData['ResponseCode_renk'] ?? '');
                    if (strpos($responseColor, 'green') !== false || strpos($responseColor, '#28a745') !== false) {
                        $tokenMessageType = 'success';
                    } elseif (strpos($responseColor, 'red') !== false || strpos($responseColor, '#dc3545') !== false) {
                        $tokenMessageType = 'danger';
                    } elseif (strpos($responseColor, 'yellow') !== false || strpos($responseColor, 'orange') !== false || strpos($responseColor, '#ffc107') !== false) {
                        $tokenMessageType = 'warning';
                    } else {
                        $tokenMessageType = 'info';
                    }
                }
                
                // AJAX çağrısı ise JSON yanıt döndür
                if ($isAjax) {
                    header('Content-Type: application/json');
                    $response = [
                        'success' => true,
                        'message' => $tokenMessage,
                        'messageType' => $tokenMessageType
                    ];
                    
                    // Debug modunda debug bilgilerini ekle
                    if ($debugMode && isset($_SESSION['token_debug'])) {
                        $response['debug'] = $_SESSION['token_debug'];
                    }
                    
                    echo json_encode($response);
                    exit;
                }
                
                $message = $tokenMessage;
                $messageType = $tokenMessageType;
                break;
        }
        
    } catch (Exception $e) {
        // Hata durumunda da response bilgilerini kaydet (eğer API çağrısı yapıldıysa)
        if (isset($id) && isset($httpCode) && isset($response)) {
            try {
                // Hata durumunda da ResponseMessage'ı API'den almaya çalış
                $errorResponseMessage = $e->getMessage();
                if (isset($responseMessage)) {
                    // Zaten parse edilmiş ResponseMessage varsa onu kullan
                    $errorResponseMessage = $responseMessage;
                } elseif (isset($response) && !empty($response)) {
                    // Response'u tekrar parse etmeye çalış
                    $errorApiResponse = json_decode($response, true);
                    if ($errorApiResponse && is_array($errorApiResponse)) {
                        if (isset($errorApiResponse['ResponseMessage'])) {
                            $errorResponseMessage = $errorApiResponse['ResponseMessage'];
                        } elseif (isset($errorApiResponse['responseMessage'])) {
                            $errorResponseMessage = $errorApiResponse['responseMessage'];
                        } elseif (isset($errorApiResponse['message'])) {
                            $errorResponseMessage = $errorApiResponse['message'];
                        } elseif (isset($errorApiResponse['error'])) {
                            $errorResponseMessage = $errorApiResponse['error'];
                        }
                    }
                }
                
                $errorUpdateSql = "UPDATE API_kullanici SET 
                                  api_iris_kullanici_response_code = ?,
                                  api_iris_kullanici_response_message = ?,
                                  api_iris_kullanici_raw_response = ?
                                  WHERE api_iris_kullanici_ID = ?";
                $errorUpdateStmt = $conn->prepare($errorUpdateSql);
                $errorUpdateStmt->execute([
                    $httpCode, 
                    $errorResponseMessage, 
                    $response, 
                    $id
                ]);
            } catch (Exception $dbError) {
                // Veritabanı hatası varsa logla
                error_log("Response kaydetme hatası: " . $dbError->getMessage());
            }
        }
        
        // AJAX çağrısı ise JSON hata yanıtı döndür
        if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ]);
            exit;
        }
        
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// API kullanıcılarını getir
try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT ak.*, u.first_name, u.last_name, u.email, 
                   arc.ResponseCode_Message, arc.ResponseCode_renk
            FROM API_kullanici ak 
            LEFT JOIN users u ON ak.users_ID = u.id 
            LEFT JOIN API_ResponseCode arc ON ak.api_iris_kullanici_response_code = arc.ResponseCode_Code";
    
    // Kendi kullanıcı kısıtlaması varsa sadece kendi verilerini getir
    if ($sayfaYetkileri['kendi_kullanicini_gor']) {
        $sql .= " WHERE ak.users_ID = ?";
        $params = [$currentUser['id']];
    } else {
        $params = [];
    }
    
    $sql .= " ORDER BY ak.api_iris_kullanici_ID DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $apiUsers = $stmt->fetchAll();
    
    // Kullanıcı listesini getir
    $usersSql = "SELECT id, first_name, last_name, email FROM users WHERE status = 1 ORDER BY first_name, last_name";
    $usersStmt = $conn->prepare($usersSql);
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();
    
    // Response code listesini getir (filtre için)
    $responseCodeSql = "SELECT DISTINCT ResponseCode_Code, ResponseCode_Message 
                        FROM API_ResponseCode 
                        WHERE ResponseCode_Code IN (SELECT DISTINCT api_iris_kullanici_response_code FROM API_kullanici WHERE api_iris_kullanici_response_code IS NOT NULL)
                        ORDER BY ResponseCode_Code";
    $responseCodeStmt = $conn->prepare($responseCodeSql);
    $responseCodeStmt->execute();
    $responseCodes = $responseCodeStmt->fetchAll();
    
    // Yetki kontrolü
    $canAdd = $sayfaYetkileri['ekle'];
    $canEdit = $sayfaYetkileri['duzenle'];
    $canDelete = $sayfaYetkileri['sil'];
    $canView = $sayfaYetkileri['gor'];
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata oluştu: ' . $e->getMessage();
    $messageType = 'danger';
    $apiUsers = [];
    $users = [];
    $responseCodes = [];
}

include '../../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<style>
/* DataTable stilleri */
#apiUsersTable thead th {
    vertical-align: middle;
    padding: 12px 8px;
}

#apiUsersTable tbody td {
    vertical-align: middle;
}

/* Mobil uyumluluk */
@media (max-width: 768px) {
    #apiUsersTable {
        font-size: 14px;
    }
    
    .table-responsive {
        border: none;
    }
}
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-key me-2"></i>Kullanıcı ve Token Yönetimi</h2>
                <div>
                    <!-- Debug Toggle - Sadece Admin için -->
                    <?php if ($isAdmin): ?>
                        <?php if ($debugMode): ?>
                            <a href="?debug=0" class="btn btn-warning me-2">
                                <i class="fas fa-bug me-1"></i>Debug: ON
                            </a>
                        <?php else: ?>
                            <a href="?debug=1" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-bug me-1"></i>Debug: OFF
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($canAdd): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddUserModal()">
                        <i class="fas fa-plus me-2"></i>Yeni API Kullanıcısı Ekle
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <?php
        // Message type'a göre ikon belirle
        $icon = 'info-circle';
        switch ($messageType) {
            case 'success':
                $icon = 'check-circle';
                break;
            case 'danger':
                $icon = 'exclamation-triangle';
                break;
            case 'warning':
                $icon = 'exclamation-triangle';
                break;
            case 'info':
                $icon = 'info-circle';
                break;
        }
        ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $icon; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Debug Panel -->
    <?php if ($debugMode && isset($_SESSION['token_debug'])): ?>
        <div class="card border-warning shadow-sm mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-bug me-2"></i>Debug Bilgileri - Son Token Yenileme İşlemi
                    <button type="button" class="btn btn-sm btn-outline-dark float-end" onclick="$('#debugDetails').toggle()">
                        <i class="fas fa-eye-slash"></i> Gizle
                    </button>
                </h5>
            </div>
            <div class="card-body" id="debugDetails">
                <?php $debug = $_SESSION['token_debug']; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-up text-primary"></i> Gönderilen İstek:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>URL:</strong><br>
                            <code><?php echo htmlspecialchars($debug['api_url']); ?></code><br><br>
                            
                            <strong>Method:</strong> POST<br>
                            <strong>Content-Type:</strong> application/json<br><br>
                            
                            <strong>Gönderilen JSON:</strong><br>
                            <pre class="bg-dark text-light p-2 rounded"><code><?php echo htmlspecialchars(json_encode($debug['request_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></code></pre>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-down text-success"></i> Gelen Yanıt:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>HTTP Kodu:</strong> 
                            <span class="badge bg-<?php echo $debug['http_code'] == 200 ? 'success' : 'danger'; ?>">
                                <?php echo $debug['http_code']; ?>
                            </span><br><br>
                            
                            <?php if ($debug['curl_error']): ?>
                                <strong>cURL Hatası:</strong><br>
                                <span class="text-danger"><?php echo htmlspecialchars($debug['curl_error']); ?></span><br><br>
                            <?php endif; ?>
                            
                            <strong>Ham Yanıt:</strong><br>
                            <pre class="bg-dark text-light p-2 rounded" style="max-height: 200px; overflow-y: auto;"><code><?php echo htmlspecialchars($debug['response_raw']); ?></code></pre>
                            
                            <?php if (isset($debug['response_decoded'])): ?>
                                <strong>Decode Edilmiş JSON:</strong><br>
                                <pre class="bg-info text-white p-2 rounded" style="max-height: 150px; overflow-y: auto;"><code><?php echo htmlspecialchars(json_encode($debug['response_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></code></pre>
                            <?php endif; ?>
                            
                            <?php if (isset($debug['new_token'])): ?>
                                <strong>Alınan Token:</strong><br>
                                <code class="text-success"><?php echo htmlspecialchars(substr($debug['new_token'], 0, 50)); ?>...</code>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-info-circle text-info"></i> Teknik Detaylar:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>İşlem Zamanı:</strong> <?php echo $debug['timestamp']; ?><br>
                            <strong>JSON Decode Hatası:</strong> <?php echo $debug['json_decode_error'] ?? 'Yok'; ?><br>
                            <strong>Veritabanı Güncellendi:</strong> <?php echo isset($debug['database_updated']) ? 'Evet' : 'Hayır'; ?><br>
                            
                            <details class="mt-2">
                                <summary><strong>cURL Bilgileri</strong></summary>
                                <pre class="bg-secondary text-white p-2 rounded mt-2"><code><?php echo htmlspecialchars(json_encode($debug['curl_info'], JSON_PRETTY_PRINT)); ?></code></pre>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php 
        // Debug bilgilerini temizle (tek seferlik gösterim için)
        // unset($_SESSION['token_debug']); 
        ?>
    <?php endif; ?>

    <!-- Filtreleme -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreleme
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3" id="filterSection">
                <div class="col-md-3">
                    <label class="form-label">Kullanıcı</label>
                    <?php if ($sayfaYetkileri['kendi_kullanicini_gor']): ?>
                        <!-- Kendi kullanıcı kısıtlaması varsa dropdown'u pasif yap -->
                        <select id="filterUser" class="form-select" disabled>
                            <option value="<?php echo htmlspecialchars($currentUser['name']); ?>" selected>
                                <?php echo htmlspecialchars($currentUser['name']); ?> (Sadece Kendi Verileriniz)
                            </option>
                        </select>
                    <?php else: ?>
                        <select id="filterUser" class="form-select">
                            <option value="">Tümü</option>
                            <?php 
                            // Sadece tabloda bulunan kullanıcıları listele
                            $tableUsers = [];
                            foreach ($apiUsers as $apiUser) {
                                if (!empty($apiUser['first_name']) && !empty($apiUser['last_name'])) {
                                    $fullName = $apiUser['first_name'] . ' ' . $apiUser['last_name'];
                                    if (!in_array($fullName, $tableUsers)) {
                                        $tableUsers[] = $fullName;
                                    }
                                }
                            }
                            sort($tableUsers);
                            foreach ($tableUsers as $userName): ?>
                                <option value="<?php echo htmlspecialchars($userName); ?>">
                                    <?php echo htmlspecialchars($userName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Organization Code</label>
                    <input type="text" id="filterOrganization" class="form-control" placeholder="Organization Code ara...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Token Durumu</label>
                    <select id="filterTokenStatus" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($responseCodes as $code): ?>
                            <option value="<?php echo htmlspecialchars($code['ResponseCode_Message']); ?>">
                                <?php echo htmlspecialchars($code['ResponseCode_Message']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="Belirsiz">Belirsiz</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select id="filterStatus" class="form-select">
                        <option value="">Tümü</option>
                        <option value="Aktif">Aktif</option>
                        <option value="Pasif">Pasif</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="fas fa-search me-1"></i>Filtrele
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                            <i class="fas fa-times me-1"></i>Temizle
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>API Kullanıcıları</h5>
        </div>
        <div class="card-body">
            <?php if (empty($apiUsers)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Henüz API kullanıcısı bulunmuyor.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="apiUsersTable">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Kullanıcı</th>
                                <th>Organization Cd</th>
                                <th>Login Cd</th>
                                <th>Token Durumu</th>
                                <th>Son Güncelleme</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiUsers as $user): ?>
                                <tr>
                                    <td><?php echo $user['api_iris_kullanici_ID']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['api_iris_kullanici_OrganisationCd']); ?></td>
                                    <td><?php echo htmlspecialchars($user['api_iris_kullanici_LoginCd']); ?></td>
                                    <td>
                                        <?php if ($user['ResponseCode_Message']): ?>
                                            <span class="badge" style="background-color: <?php echo $user['ResponseCode_renk'] ?? '#6c757d'; ?>;">
                                                <?php echo htmlspecialchars($user['ResponseCode_Message']); ?>
                                            </span>
                                        <?php elseif ($user['api_iris_kullanici_response_code']): ?>
                                            <span class="badge bg-info">
                                                Code: <?php echo $user['api_iris_kullanici_response_code']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-question"></i> Belirsiz
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['api_iris_kullanici_tokenGuncellemeTarihi']): ?>
                                            <?php echo date('d.m.Y H:i', strtotime($user['api_iris_kullanici_tokenGuncellemeTarihi'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['api_iris_kullanici_durum']): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Pasif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($canView): ?>
                                                <?php 
                                                // Kendi kullanıcı kısıtlaması varsa sadece kendi verilerinde buton göster
                                                $showViewButton = true;
                                                if ($sayfaYetkileri['kendi_kullanicini_gor'] && $user['users_ID'] != $currentUser['id']) {
                                                    $showViewButton = false;
                                                }
                                                ?>
                                                <?php if ($showViewButton): ?>
                                                <button type="button" class="btn btn-outline-info" 
                                                        onclick="showTokenModal(<?php echo $user['api_iris_kullanici_ID']; ?>)"
                                                        title="Görüntüle">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($canEdit): ?>
                                                <?php 
                                                // Kendi kullanıcı kısıtlaması varsa sadece kendi verilerinde buton göster
                                                $showEditButton = true;
                                                if ($sayfaYetkileri['kendi_kullanicini_gor'] && $user['users_ID'] != $currentUser['id']) {
                                                    $showEditButton = false;
                                                }
                                                ?>
                                                <?php if ($showEditButton): ?>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        onclick="refreshToken(<?php echo $user['api_iris_kullanici_ID']; ?>)"
                                                        title="Token Yenile">
                                                    <i class="fas fa-sync"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($user)); ?>)"
                                                        title="Düzenle">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Kullanıcı Ekleme/Düzenleme Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">
                    <i class="fas fa-plus me-2" id="userModalIcon"></i>
                    <span id="userModalTitleText">Yeni API Kullanıcısı Ekle</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="form_action" value="add">
                    <input type="hidden" name="api_iris_kullanici_ID" id="form_id">
                    <!-- Kısıtlı kullanıcılar için gizli input -->
                    <input type="hidden" name="users_ID_hidden" id="form_users_ID_hidden">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_users_ID" class="form-label">Kullanıcı <span class="text-danger">*</span></label>
                                <?php if ($sayfaYetkileri['kendi_kullanicini_gor']): ?>
                                    <!-- Kendi kullanıcı kısıtlaması varsa sadece kendi kullanıcısını göster -->
                                    <select class="form-select" name="users_ID" id="form_users_ID" required>
                                        <option value="<?php echo $currentUser['id']; ?>" selected>
                                            <?php echo htmlspecialchars($currentUser['name'] . ' - ' . $currentUser['email']); ?>
                                        </option>
                                    </select>
                                <?php else: ?>
                                    <select class="form-select" name="users_ID" id="form_users_ID" required onchange="updateHiddenUserId()">
                                        <option value="">Kullanıcı Seçin</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>">
                                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' - ' . $user['email']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_organization" class="form-label">Organization Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="api_iris_kullanici_OrganisationCd" id="form_organization" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_login" class="form-label">Login Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="api_iris_kullanici_LoginCd" id="form_login" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="api_iris_kullanici_Password" id="form_password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('form_password', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_url" class="form-label">API URL</label>
                                <input type="url" class="form-control" name="api_iris_kullanici_URL" id="form_url">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="form_logo" class="form-label">Logo URL</label>
                                <input type="url" class="form-control" name="api_iris_kullanici_logoURL" id="form_logo">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="api_iris_kullanici_durum" id="form_durum" checked>
                            <label class="form-check-label" for="form_durum">
                                Aktif
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="userModalSubmitBtn">
                        <i class="fas fa-save me-2"></i><span id="userModalSubmitText">Kaydet</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- API Kullanıcı Detayları Modal -->
<div class="modal fade" id="tokenModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-cog me-2"></i>API Kullanıcı Detayları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Bu token güvenli bir yerde saklanmalı ve üçüncü kişilerle paylaşılmamalıdır.
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Response Code:</label>
                            <input type="text" class="form-control" id="responseCode" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Response Message:</label>
                            <input type="text" class="form-control" id="responseMessage" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Token:</label>
                    <input type="text" class="form-control" id="tokenValue" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Son Güncelleme:</label>
                    <input type="text" class="form-control" id="tokenLastUpdate" readonly>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script>
// DataTable başlat
$(document).ready(function() {
    window.apiTable = $('#apiUsersTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true,
        dom: 'rtip' // Sadece table, info ve pagination göster (search'ü gizle)
    });
    
    // Enter tuşu ile filtreleme
    $('#filterOrganization').on('keypress', function(e) {
        if (e.which === 13) { // Enter tuşu
            applyFilters();
        }
    });
});

// PHP'den JavaScript'e yetki bilgilerini aktar
const sayfaYetkileri = {
    gor: <?php echo $sayfaYetkileri['gor']; ?>,
    kendi_kullanicini_gor: <?php echo $sayfaYetkileri['kendi_kullanicini_gor']; ?>,
    ekle: <?php echo $sayfaYetkileri['ekle']; ?>,
    duzenle: <?php echo $sayfaYetkileri['duzenle']; ?>,
    sil: <?php echo $sayfaYetkileri['sil']; ?>
};

const currentUserId = <?php echo $currentUser['id']; ?>;

// Renkli notification gösterme fonksiyonu
function showNotification(message, type = 'info') {
    // Mevcut notification'ları temizle
    const existingAlerts = document.querySelectorAll('.dynamic-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    // İkon belirle
    let icon = 'info-circle';
    switch (type) {
        case 'success':
            icon = 'check-circle';
            break;
        case 'danger':
            icon = 'exclamation-triangle';
            break;
        case 'warning':
            icon = 'exclamation-triangle';
            break;
        case 'info':
            icon = 'info-circle';
            break;
    }
    
    // Alert HTML oluştur
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show dynamic-alert" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px;">
            <i class="fas fa-${icon} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Alert'i sayfaya ekle
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // 5 saniye sonra otomatik kapat
    setTimeout(() => {
        const alert = document.querySelector('.dynamic-alert');
        if (alert) {
            alert.remove();
        }
    }, 5000);
}

// Kullanıcı seçimi değiştiğinde hidden input'u güncelle
function updateHiddenUserId() {
    const selectValue = document.getElementById('form_users_ID').value;
    document.getElementById('form_users_ID_hidden').value = selectValue;
}

// Filtreleri uygula
function applyFilters() {
    var table = window.apiTable;
    
    // Kullanıcı filtresi
    var userFilter = $('#filterUser').val();
    table.column(1).search(userFilter);
    
    // Organization Code filtresi
    var orgFilter = $('#filterOrganization').val();
    table.column(2).search(orgFilter);
    
    // Token Durumu filtresi (sütun indeksi 4'e değişti)
    var tokenFilter = $('#filterTokenStatus').val();
    table.column(4).search(tokenFilter);
    
    // Durum filtresi (sütun indeksi 6'ya değişti)
    var statusFilter = $('#filterStatus').val();
    table.column(6).search(statusFilter);
    
    // Filtreleri uygula
    table.draw();
}

// Filtreleri temizle
function clearFilters() {
    $('#filterUser').val('');
    $('#filterOrganization').val('');
    $('#filterTokenStatus').val('');
    $('#filterStatus').val('');
    
    // DataTable filtrelerini temizle ve yeniden çiz
    var table = window.apiTable;
    table.search('').columns().search('').draw();
}

// Modal açma fonksiyonları
function openAddUserModal() {
    // Modal başlığını güncelle
    document.getElementById('userModalTitleText').textContent = 'Yeni API Kullanıcısı Ekle';
    document.getElementById('userModalIcon').className = 'fas fa-plus me-2';
    document.getElementById('userModalSubmitText').textContent = 'Kaydet';
    
    // Form action'ını ayarla
    document.getElementById('form_action').value = 'add';
    
    // Formu temizle
    document.getElementById('userForm').reset();
    document.getElementById('form_id').value = '';
    document.getElementById('form_durum').checked = true;
    
    // Kendi kullanıcı kısıtlaması varsa kullanıcı seçimini otomatik yap
    if (sayfaYetkileri.kendi_kullanicini_gor === 1) {
        document.getElementById('form_users_ID').value = currentUserId;
        document.getElementById('form_users_ID').disabled = true;
        // Hidden input'a da değeri set et
        document.getElementById('form_users_ID_hidden').value = currentUserId;
    } else {
        document.getElementById('form_users_ID').disabled = false;
        // Hidden input'u temizle
        document.getElementById('form_users_ID_hidden').value = '';
    }
    
    // Modal'ı göster
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openEditUserModal(user) {
    // Modal başlığını güncelle
    document.getElementById('userModalTitleText').textContent = 'API Kullanıcısı Düzenle';
    document.getElementById('userModalIcon').className = 'fas fa-edit me-2';
    document.getElementById('userModalSubmitText').textContent = 'Güncelle';
    
    // Form action'ını ayarla
    document.getElementById('form_action').value = 'edit';
    
    // Form verilerini doldur
    document.getElementById('form_id').value = user.api_iris_kullanici_ID;
    document.getElementById('form_users_ID').value = user.users_ID;
    document.getElementById('form_organization').value = user.api_iris_kullanici_OrganisationCd || '';
    document.getElementById('form_login').value = user.api_iris_kullanici_LoginCd || '';
    document.getElementById('form_password').value = user.api_iris_kullanici_Password || '';
    document.getElementById('form_url').value = user.api_iris_kullanici_URL || '';
    document.getElementById('form_logo').value = user.api_iris_kullanici_logoURL || '';
    document.getElementById('form_durum').checked = user.api_iris_kullanici_durum == 1;
    
    // Kendi kullanıcı kısıtlaması varsa kullanıcı seçimini devre dışı bırak
    if (sayfaYetkileri.kendi_kullanicini_gor === 1) {
        document.getElementById('form_users_ID').disabled = true;
        // Hidden input'a değeri set et
        document.getElementById('form_users_ID_hidden').value = user.users_ID;
    } else {
        document.getElementById('form_users_ID').disabled = false;
        // Hidden input'u temizle
        document.getElementById('form_users_ID_hidden').value = '';
    }
    
    // Modal'ı göster
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

// Şifre görünürlük toggle fonksiyonu
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
        button.title = 'Şifreyi Gizle';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
        button.title = 'Şifreyi Göster';
    }
}

// Kullanıcı düzenleme (eski fonksiyon - geriye uyumluluk için)
function editUser(user) {
    openEditUserModal(user);
}

// Token yenileme
function refreshToken(userId) {
    // Debug modunu kontrol et
    const urlParams = new URLSearchParams(window.location.search);
    const debugMode = urlParams.get('debug') === '1';
    
    if (confirm('Bu kullanıcının token bilgisini yenilemek istediğinizden emin misiniz?\n\nBu işlem birkaç saniye sürebilir.')) {
        // Loading state
        const button = event.target.closest('button');
        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        button.disabled = true;
        
        // AJAX body oluştur
        let body = `action=refresh_token&id=${userId}`;
        if (debugMode) {
            body += '&ajax=1';
        }
        
        // AJAX ile token yenileme
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body
        })
        .then(response => {
            if (debugMode) {
                return response.json();
            } else {
                return response.text();
            }
        })
        .then(data => {
            if (debugMode) {
                // Debug modunda JSON yanıt bekleniyor
                if (data.success) {
                    if (data.debug) {
                        // Debug bilgilerini göster
                        showDebugPanel(data.debug);
                    }
                    
                    // Renkli notification göster
                    showNotification(data.message || 'Token başarıyla yenilendi.', data.messageType || 'success');
                    
                    // Button'ı eski haline getir
                    button.innerHTML = originalContent;
                    button.disabled = false;
                    
                    // Sayfayı yenile (tablo güncellenmesi için)
                    setTimeout(() => window.location.reload(), 3000);
                } else {
                    // Hata durumu
                    showNotification(data.message || 'Token yenileme sırasında hata oluştu.', 'danger');
                    button.innerHTML = originalContent;
                    button.disabled = false;
                }
            } else {
                // Normal mod - sayfayı yenile
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Token yenileme sırasında hata oluştu. Lütfen tekrar deneyin.', 'danger');
            
            // Button'ı eski haline getir
            button.innerHTML = originalContent;
            button.disabled = false;
        });
    }
}

// Debug panelini göster
function showDebugPanel(debugData) {
    // Mevcut debug paneli varsa kaldır
    const existingPanel = document.getElementById('dynamicDebugPanel');
    if (existingPanel) {
        existingPanel.remove();
    }
    
    // Debug paneli HTML'i oluştur
    const debugHtml = `
        <div class="card border-warning shadow-sm mb-4" id="dynamicDebugPanel">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-bug me-2"></i>Debug Bilgileri - Son Token Yenileme İşlemi
                    <button type="button" class="btn btn-sm btn-outline-dark float-end" onclick="document.getElementById('dynamicDebugPanel').remove()">
                        <i class="fas fa-times"></i> Kapat
                    </button>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-up text-primary"></i> Gönderilen İstek:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>Kullanıcı ID:</strong> ${debugData.user_id}<br>
                            <strong>Organization:</strong> ${debugData.user_organization}<br>
                            <strong>Login Code:</strong> ${debugData.user_login_cd}<br><br>
                            
                            <strong>URL:</strong><br>
                            <code>${debugData.api_url}</code><br><br>
                            
                            <strong>Method:</strong> POST<br>
                            <strong>Content-Type:</strong> application/json<br><br>
                            
                            <strong>Gönderilen JSON:</strong><br>
                            <pre class="bg-dark text-light p-2 rounded"><code>${JSON.stringify(debugData.request_data, null, 2)}</code></pre>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-down text-success"></i> Gelen Yanıt:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>HTTP Kodu:</strong> 
                            <span class="badge bg-${debugData.http_code == 200 ? 'success' : 'danger'}">
                                ${debugData.http_code}
                            </span><br><br>
                            
                            ${debugData.curl_error ? `<strong>cURL Hatası:</strong><br><span class="text-danger">${debugData.curl_error}</span><br><br>` : ''}
                            
                            <strong>Ham Yanıt:</strong><br>
                            <pre class="bg-dark text-light p-2 rounded" style="max-height: 200px; overflow-y: auto;"><code>${debugData.response_raw}</code></pre>
                            
                            ${debugData.response_decoded ? `<strong>Decode Edilmiş JSON:</strong><br><pre class="bg-info text-white p-2 rounded" style="max-height: 150px; overflow-y: auto;"><code>${JSON.stringify(debugData.response_decoded, null, 2)}</code></pre>` : ''}
                            
                            ${debugData.new_token ? `<strong>Alınan Token:</strong><br><code class="text-success">${debugData.new_token.substring(0, 50)}...</code>` : ''}
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-info-circle text-info"></i> Teknik Detaylar:</h6>
                        <div class="bg-light p-3 rounded">
                            <strong>İşlem Zamanı:</strong> ${debugData.timestamp}<br>
                            <strong>JSON Decode Hatası:</strong> ${debugData.json_decode_error || 'Yok'}<br>
                            <strong>Veritabanı Güncellendi:</strong> ${debugData.database_updated ? 'Evet' : 'Hayır'}<br>
                            ${debugData.response_code_saved ? `<strong>Kaydedilen Response Code:</strong> ${debugData.response_code_saved}<br>` : ''}
                            ${debugData.response_message_saved ? `<strong>Kaydedilen Response Message:</strong> ${debugData.response_message_saved}<br>` : ''}
                            ${debugData.raw_response_saved ? `<strong>Raw Response:</strong> ${debugData.raw_response_saved}<br>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Debug panelini filtreleme panelinin altına ekle
    const filterPanel = document.querySelector('.card.border-0.shadow-sm.mb-4');
    filterPanel.insertAdjacentHTML('afterend', debugHtml);
    
    // Panele scroll et
    setTimeout(() => {
        document.getElementById('dynamicDebugPanel').scrollIntoView({ behavior: 'smooth' });
    }, 100);
}

// Token modal gösterme
function showTokenModal(userId) {
    // API çağrısı ile token bilgisini getir
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_token&id=${userId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('responseCode').value = data.responseCode || 'Belirtilmemiş';
            document.getElementById('responseMessage').value = data.responseMessage || 'Mesaj yok';
            
            // Token'ı maskele
            let maskedToken = 'Token henüz oluşturulmamış';
            if (data.token && data.token.length > 20) {
                const start = data.token.substring(0, 8);
                const end = data.token.substring(data.token.length - 8);
                maskedToken = start + '***...' + end;
            } else if (data.token && data.token.length > 0) {
                maskedToken = data.token.substring(0, 4) + '***...';
            }
            
            document.getElementById('tokenValue').value = maskedToken;
            document.getElementById('tokenLastUpdate').value = data.lastUpdate || 'Henüz güncellenmemiş';
            
            // Modal header rengini response code rengine göre değiştir
            const modalHeader = document.querySelector('#tokenModal .modal-header');
            if (data.responseColor) {
                modalHeader.style.backgroundColor = data.responseColor;
                modalHeader.style.color = '#ffffff'; // Beyaz yazı
            } else {
                // Varsayılan renk
                modalHeader.style.backgroundColor = '#0d6efd';
                modalHeader.style.color = '#ffffff';
            }
            
            new bootstrap.Modal(document.getElementById('tokenModal')).show();
        } else {
            showNotification('Kullanıcı bilgisi alınırken hata oluştu.', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Kullanıcı bilgisi alınırken hata oluştu.', 'danger');
    });
}
</script>

        </div> <!-- main-content kapatma -->
    </div> <!-- container kapatma -->

<?php include '../../../includes/footer.php'; ?>