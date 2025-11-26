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
    if (!isset($_POST['basvuru_id'])) {
        throw new Exception('Başvuru ID belirtilmedi.');
    }
    
    $basvuruId = $_POST['basvuru_id'];
    $conn = getDatabaseConnection();
    
    // Başvuru bilgilerini getir
    $sql = "SELECT 
                bl.*,
                ak.api_iris_kullanici_token,
                ak.api_iris_kullanici_LoginCd,
                ct.API_GetCardTypeList_card_code,
                u.first_name,
                u.last_name
            FROM API_basvuruListesi bl
            LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
            LEFT JOIN API_GetCardTypeList ct ON bl.API_basvuru_identityCardType_ID = ct.API_GetCardTypeList_ID
            LEFT JOIN users u ON ak.users_ID = u.id
            WHERE bl.API_basvuru_ID = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$basvuruId]);
    $basvuru = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$basvuru) {
        throw new Exception('Başvuru bulunamadı.');
    }
    
    // Yetki kontrolü - Kendi kullanıcısı değilse ve admin değilse hata ver
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
            $userCheckSql = "SELECT users_ID FROM API_kullanici WHERE api_iris_kullanici_ID = ?";
            $userCheckStmt = $conn->prepare($userCheckSql);
            $userCheckStmt->execute([$basvuru['API_basvuru_kullanici_ID']]);
            $apiUser = $userCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$apiUser || $apiUser['users_ID'] != $currentUser['id']) {
                throw new Exception('Bu başvuruya erişim yetkiniz yok.');
            }
        }
    }
    
    // API_basvuru_TalepKayitNo doluysa durum sorgulama API'si kullan
    $isStatusCheck = !empty($basvuru['API_basvuru_TalepKayitNo']);
    
    // API URL'ini belirle
    $apiLinkSql = "SELECT api_iris_Address_URL FROM API_Link WHERE api_iris_Address_ID = ?";
    $apiLinkStmt = $conn->prepare($apiLinkSql);
    
    if ($isStatusCheck) {
        // Durum sorgulama API'si
        $apiLinkStmt->execute([17]);
        $apiLink = $apiLinkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$apiLink) {
            throw new Exception('Durum sorgulama API URL bulunamadı.');
        }
        $apiUrl = $apiLink['api_iris_Address_URL'] . '?RequestId=' . $basvuru['API_basvuru_TalepKayitNo'];
    } else {
        // Normal başvuru API'si
        if ($basvuru['API_basvuru_CampaignList_ID'] == 1) {
            $apiLinkStmt->execute([15]); // Satellite için
        } elseif ($basvuru['API_basvuru_CampaignList_ID'] == 2) {
            $apiLinkStmt->execute([16]); // Neo için
        } else {
            throw new Exception('Geçersiz kampanya tipi.');
        }
        
        $apiLink = $apiLinkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$apiLink) {
            throw new Exception('API URL bulunamadı.');
        }
        
        $apiUrl = $apiLink['api_iris_Address_URL'];
    }
    
    // Token kontrolü
    if (empty($basvuru['api_iris_kullanici_token'])) {
        throw new Exception('Kullanıcı token bulunamadı.');
    }
    
    // Body JSON oluştur
    $bodyData = null;
    
    if (!$isStatusCheck) {
        // Normal başvuru için body hazırla
        
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
                            API_GetNeoCampaignList_offerToId as offerToId,
                            API_GetNeoCampaignList_offerFromId as offerFromId,
                            API_GetNeoCampaignList_billFrequency as frequency,
                            API_GetNeoCampaignList_billFrequencyTypeCd as frequencyCode
                         FROM API_GetNeoCampaignList 
                         WHERE API_GetNeoCampaignList_ID = ?";
            $paketParams = [$basvuru['API_basvuru_Paket_ID']];
        }
        
        $paketStmt = $conn->prepare($paketSql);
        $paketStmt->execute($paketParams);
        $paketBilgi = $paketStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$paketBilgi) {
            throw new Exception('Paket bilgisi bulunamadı.');
        }
        
        // birthDate formatı - ISO 8601 formatına çevir
        $birthDate = null;
        if ($basvuru['API_basvuru_birthDate']) {
            $birthDateObj = new DateTime($basvuru['API_basvuru_birthDate']);
            $birthDate = $birthDateObj->format('Y-m-d\TH:i:sP');
        }
        
        $bodyData = [
            'bbkAddressCode' => (int)$basvuru['API_basvuru_bbkAddressCode'],
            'email' => $basvuru['API_basvuru_email'],
            'phoneCountryNumber' => $basvuru['API_basvuru_phoneCountryNumber'],
            'phoneAreaNumber' => $basvuru['API_basvuru_phoneAreaNumber'],
            'phoneNumber' => $basvuru['API_basvuru_phoneNumber'],
            'birthDate' => $birthDate,
            'citizenNumber' => $basvuru['API_basvuru_citizenNumber'],
            'firstName' => $basvuru['API_basvuru_firstName'],
            'surname' => $basvuru['API_basvuru_surname'],
            'genderType' => strtolower($basvuru['API_basvuru_genderType']),
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
    // Durum sorgulama için body yok (GET request)
    
    // Yanıt hazırla
    $response = [
        'success' => true,
        'data' => [
            'basvuru_id' => $basvuruId,
            'url' => $apiUrl,
            'token' => $basvuru['api_iris_kullanici_token'],
            'body' => $bodyData,
            'is_status_check' => $isStatusCheck,
            'kullanici' => $basvuru['first_name'] . ' ' . $basvuru['last_name'],
            'kampanya_id' => $basvuru['API_basvuru_CampaignList_ID'],
            'otomatik_gonderim' => (int)$basvuru['API_basvuru_otomatik_gonderim'],
            'deneme_sayisi' => (int)$basvuru['API_basvuru_gonderim_deneme_sayisi'],
            'son_deneme' => $basvuru['API_basvuru_son_gonderim_denemesi']
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
