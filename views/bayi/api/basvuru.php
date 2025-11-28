<?php
/**
 * Başvuru Sayfası (Kimlik Bilgileri)
 * 
 * ⚠️ ÖNEMLİ İSTİSNA: Bu sayfa dışarıdan (public) erişime açıktır!
 * - Auth kontrolü YOK
 * - Yetki kontrolü YOK
* - Header/Footer/Navigation YOK, Kullanma!t
 * - Sadece veritabanı bağlantısı kullanılır
 * 
 * Neden? Bu sayfa müşterilerin başvuru yapması için kullanılacak.
 * Bayi/API kullanıcıları link paylaşacak, müşteriler bu linke tıklayarak başvuru yapacak.
 * 
 * ═══════════════════════════════════════════════════════════════════════════
 * VERİTABANI MAPPING - [dbo].[API_basvuruListesi]
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * Form Alanı / Session        → Veritabanı Kolonu              → Tablo/Kaynak
 * ─────────────────────────────────────────────────────────────────────────
 * URL Parametreleri:
 * api_ID                      → [API_basvuru_kullanici_ID]    → GET parameter
 * kampanya                    → [API_basvuru_CampaignList_ID] → GET parameter
 * paket                       → [API_basvuru_Paket_ID]        → GET parameter
 * 
 * Kimlik Bilgileri Formu:
 * firstName                   → [API_basvuru_firstName]       → Form input
 * surname                     → [API_basvuru_surname]         → Form input
 * email                       → [API_basvuru_email]           → Form input
 * phoneCountryNumber (90)     → [API_basvuru_phoneCountryNumber] → Sabit değer
 * phoneAreaNumber             → [API_basvuru_phoneAreaNumber] → Form input
 * phoneNumber                 → [API_basvuru_phoneNumber]     → Form input
 * birthDate                   → [API_basvuru_birthDate]       → Form input
 * citizenNumber               → [API_basvuru_citizenNumber]   → Form input
 * genderType (BAY/BAYAN)      → [API_basvuru_genderType]      → Form radio
 * identityCardType_ID         → [API_basvuru_identityCardType_ID] → Form select
 * 
 * Adres Bilgileri (Neo için otomatik):
 * bbkAddressCode              → [API_basvuru_bbkAddressCode]  → Random unique
 * 
 * Sistem Alanları (Otomatik):
 * kaynakSite                  → [API_basvuru_kaynakSite]      → 'digiturk.ilekasoft.com'
 * basvuru_durum_ID            → [API_basvuru_basvuru_durum_ID] → Varsayılan: 1
 * olusturma_tarih             → [API_basvuru_olusturma_tarih] → GETDATE()
 * guncelleme_tarihi           → [API_basvuru_guncelleme_tarihi] → GETDATE()
 * 
 * API Response Alanları (Daha sonra doldurulacak):
 * ResponseCode_ID             → [API_basvuru_ResponseCode_ID] → API cevabı
 * ResponseMessage             → [API_basvuru_ResponseMessage] → API cevabı
 * MusteriNo                   → [API_basvuru_MusteriNo]       → API cevabı
 * TalepKayitNo                → [API_basvuru_TalepKayitNo]    → API cevabı
 * MemoID                      → [API_basvuru_MemoID]          → API cevabı
 * 
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 * @phone +90 501 357 10 85
 */

// iframe cross-origin için header ayarları (dinamik origin)
$allowedOrigin = '*';
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $allowedOrigin = $_SERVER['HTTP_ORIGIN'];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $refererParts = parse_url($_SERVER['HTTP_REFERER']);
    $allowedOrigin = ($refererParts['scheme'] ?? 'https') . '://' . ($refererParts['host'] ?? '');
}
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('P3P: CP="CAO PSA OUR"');

// Session başlat (başvuru bilgilerini saklamak için)
// iframe içinde çalışması için SameSite=None ve Secure gerekli
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    session_start();
}

// Sadece veritabanı bağlantısı için config'i dahil et
require_once '../../../config/mssql.php';

// Veritabanı bağlantı fonksiyonu
function getDatabaseConnection() {
    $configFile = __DIR__ . '/../../../config/mssql.php';
    $config = include $configFile;
    
    try {
        $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']};TrustServerCertificate=yes";
        $connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $connection;
    } catch (PDOException $e) {
        throw new Exception('Veritabanı bağlantı hatası: ' . $e->getMessage());
    }
}

// Veritabanı bağlantısı
$conn = getDatabaseConnection();

// Admin kontrolü (Debug için - session'da varsa)
$isAdmin = (isset($_SESSION['user_group_id']) && $_SESSION['user_group_id'] == 1);

// URL parametrelerini al ve session'da sakla
$apiId = isset($_GET['api_ID']) ? intval($_GET['api_ID']) : null;
$kampanyaId = isset($_GET['kampanya']) ? intval($_GET['kampanya']) : null;
$paketId = isset($_GET['paket']) ? intval($_GET['paket']) : null;

/**
 * URL PARAMETRELERİ SESSION YAPISI
 * 
 * Bu parametreler devamındaki sayfalarda ve kayıtta kullanılacak:
 * 
 * Session Key   → Veritabanı Kolonu / Kullanım Amacı
 * ─────────────────────────────────────────────────────────────
 * api_ID        → [API_basvuru_kullanici_ID] (Başvuruyu yapan API kullanıcısı)
 * kampanya      → [API_basvuru_CampaignList_ID] (1=Kutulu TV, 2=Kutusuz NEO)
 * paket         → [API_basvuru_Paket_ID] (Seçilen paket ID - opsiyonel)
 */
if ($apiId) {
    $_SESSION['basvuru_params'] = [
        'api_ID' => $apiId,      // [API_basvuru_kullanici_ID]
        'kampanya' => $kampanyaId, // [API_basvuru_CampaignList_ID]
        'paket' => $paketId      // [API_basvuru_Paket_ID]
    ];
}

// Neo kampanyası kontrolü (kampanya ID'sine göre)
$isNeoKampanya = ($kampanyaId == 2); // Kutusuz TV Paketi = Neo
$kampanyaTipi = $isNeoKampanya ? 'neo' : 'kutulu';

// Kampanya adını belirle
$kampanyaAdi = '';
if ($kampanyaId == 1) {
    $kampanyaAdi = 'Kutulu TV Paketi';
} elseif ($kampanyaId == 2) {
    $kampanyaAdi = 'Kutusuz TV Paketi (NEO)';
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    if ($_POST['action'] === 'saveKimlik') {
        try {
            // Veritabanı bağlantısını al
            $conn = getDatabaseConnection();
            
            // Doğrulama
            $errors = [];
            
            if (empty($_POST['firstName'])) $errors[] = 'İsim zorunludur';
            if (empty($_POST['surname'])) $errors[] = 'Soyisim zorunludur';
            if (empty($_POST['phoneAreaNumber']) || !preg_match('/^\d{3}$/', $_POST['phoneAreaNumber'])) 
                $errors[] = 'Telefon alan kodu 3 haneli olmalıdır';
            if (empty($_POST['phoneNumber']) || !preg_match('/^\d{7}$/', $_POST['phoneNumber'])) 
                $errors[] = 'Telefon numarası 7 haneli olmalıdır';
            if (empty($_POST['birthDate'])) $errors[] = 'Doğum tarihi zorunludur';
            if (empty($_POST['citizenNumber']) || !preg_match('/^\d{11}$/', $_POST['citizenNumber'])) 
                $errors[] = 'TC Kimlik No 11 haneli olmalıdır';
            if (empty($_POST['genderType']) || !in_array($_POST['genderType'], ['BAY', 'BAYAN'])) 
                $errors[] = 'Cinsiyet seçimi zorunludur';
            
            // İl kontrolü (zorunlu)
            if (empty($_POST['il'])) {
                $errors[] = 'İl seçimi zorunludur';
            }
            
            if (!empty($errors)) {
                echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
                exit;
            }
            
            // Session'da sakla
            // Seçilen şehrin code değerini al
            $ilCode = null;
            if (!empty($_POST['il'])) {
                $stmt = $conn->prepare("SELECT [adres_iller_code] FROM [dbo].[adres_iller] WHERE [adres_iller_ID] = ?");
                $stmt->execute([intval($_POST['il'])]);
                $il = $stmt->fetch();
                if ($il) {
                    $ilCode = $il['adres_iller_code'];
                }
            }
            
            /**
             * KİMLİK BİLGİLERİ SESSION YAPISI
             * 
             * Bu veriler [dbo].[API_basvuruListesi] tablosuna kaydedilecek:
             * 
             * Session Key              → Veritabanı Kolonu
             * ─────────────────────────────────────────────────────────────
             * firstName                → [API_basvuru_firstName]
             * surname                  → [API_basvuru_surname]
             * email                    → [API_basvuru_email]
             * phoneCountryNumber       → [API_basvuru_phoneCountryNumber]
             * phoneAreaNumber          → [API_basvuru_phoneAreaNumber]
             * phoneNumber              → [API_basvuru_phoneNumber]
             * birthDate                → [API_basvuru_birthDate]
             * citizenNumber            → [API_basvuru_citizenNumber]
             * genderType               → [API_basvuru_genderType] (BAY/BAYAN)
             * identityCardType_ID      → [API_basvuru_identityCardType_ID]
             * il_ID                    → Sadece referans (adres seçiminde kullanılacak)
             * il_code                  → Sadece referans (adres seçiminde kullanılacak)
             */
            $_SESSION['basvuru_kimlik'] = [
                'firstName' => trim($_POST['firstName']),              // [API_basvuru_firstName]
                'surname' => trim($_POST['surname']),                  // [API_basvuru_surname]
                'email' => trim($_POST['email']),                      // [API_basvuru_email]
                'phoneCountryNumber' => '90',                          // [API_basvuru_phoneCountryNumber]
                'phoneAreaNumber' => trim($_POST['phoneAreaNumber']),  // [API_basvuru_phoneAreaNumber]
                'phoneNumber' => trim($_POST['phoneNumber']),          // [API_basvuru_phoneNumber]
                'birthDate' => $_POST['birthDate'],                    // [API_basvuru_birthDate]
                'citizenNumber' => trim($_POST['citizenNumber']),      // [API_basvuru_citizenNumber]
                'genderType' => $_POST['genderType'],                  // [API_basvuru_genderType]
                'identityCardType_ID' => intval($_POST['identityCardType_ID']), // [API_basvuru_identityCardType_ID]
                'il_ID' => !empty($_POST['il']) ? intval($_POST['il']) : null,  // Adres seçimi için referans
                'il_code' => $ilCode                                   // Adres seçimi için referans
            ];
            
            // URL parametrelerini al - POST'tan veya session'dan
            // Önce POST'tan kontrol et (JavaScript formData ile gönderilecek)
            $apiUserId = isset($_POST['api_ID']) ? intval($_POST['api_ID']) : (isset($_SESSION['basvuru_params']['api_ID']) ? $_SESSION['basvuru_params']['api_ID'] : null);
            $kampanyaId = isset($_POST['kampanya']) ? intval($_POST['kampanya']) : (isset($_SESSION['basvuru_params']['kampanya']) ? $_SESSION['basvuru_params']['kampanya'] : null);
            $paketId = isset($_POST['paket']) ? intval($_POST['paket']) : (isset($_SESSION['basvuru_params']['paket']) ? $_SESSION['basvuru_params']['paket'] : null);
            
            // Debug log
            error_log("AJAX saveKimlik - api_ID: $apiUserId, kampanya: $kampanyaId, paket: $paketId");
            
            // Session'a params kaydet (daha sonraki sayfalar için)
            $_SESSION['basvuru_params'] = [
                'api_ID' => $apiUserId,
                'kampanya' => $kampanyaId,
                'paket' => $paketId
            ];
            
            // NEO kampanyası için adres adımını atla (kampanya ID = 2)
            $isNeo = ($kampanyaId == 2);
            
            // Neo kampanyası için bbkAddressCode üret
            $bbkCode = null;
            if ($isNeo) {
                $min = 130109;
                $max = 111069460;
                $maxAttempts = 100;
                
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $code = rand($min, $max);
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi] WHERE [API_basvuru_bbkAddressCode] = ?");
                    $stmt->execute([$code]);
                    $result = $stmt->fetch();
                    
                    if ($result && $result['count'] == 0) {
                        $bbkCode = $code;
                        break;
                    }
                }
                
                if (!$bbkCode) {
                    $bbkCode = rand($min, $max); // Fallback
                }
                
                /**
                 * NEO KAMPANYA ADRES BİLGİLERİ
                 * 
                 * Neo kampanyasında adres bilgileri otomatik oluşturulur.
                 * Bu veriler [dbo].[API_basvuruListesi] tablosuna kaydedilecek:
                 * 
                 * bbkAddressCode → [API_basvuru_bbkAddressCode] (Unique random kod)
                 * 
                 * Diğer adres bilgileri API'ye gönderilir ama veritabanında saklanmaz.
                 */
                $_SESSION['basvuru_adres'] = [
                    'county_code' => null,
                    'county_name' => 'Neo Kampanya - Adres Gerekmez',
                    'quarter_code' => null,
                    'quarter_name' => null,
                    'street_code' => null,
                    'street_name' => null,
                    'building_code' => null,
                    'building_name' => null,
                    'door_code' => null,
                    'door_name' => null,
                    'bbkAddressCode' => $bbkCode
                ];
            }
            
            // VERİTABANINA KAYDET
            $insertSql = "
                INSERT INTO [dbo].[API_basvuruListesi] (
                    [API_basvuru_firstName],
                    [API_basvuru_surname],
                    [API_basvuru_email],
                    [API_basvuru_phoneCountryNumber],
                    [API_basvuru_phoneAreaNumber],
                    [API_basvuru_phoneNumber],
                    [API_basvuru_birthDate],
                    [API_basvuru_citizenNumber],
                    [API_basvuru_genderType],
                    [API_basvuru_identityCardType_ID],
                    [API_basvuru_bbkAddressCode],
                    [API_basvuru_kullanici_ID],
                    [API_basvuru_CampaignList_ID],
                    [API_basvuru_Paket_ID],
                    [API_basvuru_kaynakSite],
                    [API_basvuru_basvuru_durum_ID],
                    [API_basvuru_olusturma_tarih],
                    [API_basvuru_guncelleme_tarihi]
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE()
                )
            ";
            
            $insertStmt = $conn->prepare($insertSql);
            
            // Kaynak siteyi belirle (JavaScript'ten gelen, yoksa server'dan)
            $kaynakSite = 'digiturk.ilekasoft.com'; // Varsayılan
            
            // Önce JavaScript'ten gelen değeri kontrol et
            if (!empty($_POST['kaynak_site'])) {
                $kaynakSite = trim($_POST['kaynak_site']);
            } 
            // Yoksa HTTP header'lardan bak
            elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
                $kaynakSite = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?: $kaynakSite;
            } elseif (!empty($_SERVER['HTTP_REFERER'])) {
                $kaynakSite = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) ?: $kaynakSite;
            }
            
            // Debug: Parametreleri logla
            $params = [
                trim($_POST['firstName']),
                trim($_POST['surname']),
                trim($_POST['email']),
                '90',
                trim($_POST['phoneAreaNumber']),
                trim($_POST['phoneNumber']),
                $_POST['birthDate'],
                trim($_POST['citizenNumber']),
                $_POST['genderType'],
                intval($_POST['identityCardType_ID']),
                $bbkCode, // Neo için dolu, normal için null
                $apiUserId,
                $kampanyaId,
                $paketId,
                $kaynakSite, // Dinamik kaynak site
                1 // Varsayılan durum: Yeni
            ];
            
            error_log("INSERT parametreleri: " . print_r($params, true));
            
            $insertResult = $insertStmt->execute($params);
            
            if (!$insertResult) {
                error_log("INSERT başarısız! PDO errorInfo: " . print_r($insertStmt->errorInfo(), true));
                throw new Exception('Veritabanına kayıt eklenemedi');
            }
            
            error_log("INSERT başarılı, SCOPE_IDENTITY çağrılıyor...");
            
            // Eklenen kaydın ID'sini al (MSSQL için SCOPE_IDENTITY kullan)
            $idStmt = $conn->query("SELECT SCOPE_IDENTITY() as lastId");
            $idResult = $idStmt->fetch();
            $basvuruId = $idResult['lastId'];
            
            // Debug log
            error_log("SCOPE_IDENTITY sonuç: " . print_r($idResult, true));
            error_log("basvuruId değişkeni: " . ($basvuruId ?? 'NULL') . " (type: " . gettype($basvuruId) . ")");
            
            // ID kontrolü
            if (empty($basvuruId) || $basvuruId == 0) {
                error_log("UYARI: SCOPE_IDENTITY boş veya 0 döndü!");
                // Manuel olarak en son ID'yi al
                $lastIdStmt = $conn->query("SELECT MAX([API_basvuru_ID]) as maxId FROM [dbo].[API_basvuruListesi]");
                $lastIdResult = $lastIdStmt->fetch();
                $basvuruId = $lastIdResult['maxId'];
                error_log("Fallback MAX(ID): " . $basvuruId);
            }
            
            // Session'a kaydet
            $_SESSION['basvuru_id'] = intval($basvuruId);
            
            // Debug log
            error_log("Session'a kaydedilen ID: " . $_SESSION['basvuru_id']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Kimlik bilgileri kaydedildi', 
                'isNeo' => $isNeo,
                'basvuru_id' => intval($basvuruId)
            ]);
            exit;
            
        } catch (Exception $e) {
            error_log("Kimlik bilgileri hatası: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Bir hata oluştu']);
            exit;
        }
    }
}

// Kimlik kartı tiplerini çek
$cardTypes = [];
try {
    $stmt = $conn->query("SELECT [API_GetCardTypeList_ID], [API_GetCardTypeList_card_name] FROM [dbo].[API_GetCardTypeList] ORDER BY [API_GetCardTypeList_ID]");
    $cardTypes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Kart tipleri çekilemedi: " . $e->getMessage());
}

// İlleri çek
$iller = [];
try {
    $stmt = $conn->query("SELECT [adres_iller_ID], [adres_iller_name], [adres_iller_code], [adres_iller_KOI] FROM [dbo].[adres_iller] WHERE [adres_iller_durum] = 1 ORDER BY [adres_iller_name]");
    $iller = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("İller çekilemedi: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Digiturk Başvuru Formu - Kimlik Bilgileri">
    <title>Başvuru - Kimlik Bilgileri | Digiturk</title>
    
    <!-- Base URL for iframe compatibility -->
    <base href="https://digiturk.ilekasoft.com/views/Bayi/api/">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Tom Select CSS -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.css" rel="stylesheet">

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
}

.navbar-brand img {
    max-height: 40px;
}

.kimlik-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px 0 30px;
    border-radius: 0;
}

.kimlik-hero .hero-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.kimlik-hero .hero-subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
}

.progress-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.form-label {
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #333;
}

.form-control, .form-select {
    border: 1px solid #ddd;
    padding: 0.75rem;
    border-radius: 5px;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.gender-buttons {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.btn-gender {
    flex: 1;
    min-width: 140px;
    padding: 12px 20px;
    font-size: 1rem;
    font-weight: 500;
    border-width: 2px;
    transition: all 0.3s ease;
}

#labelBay {
    border: 2px solid #3b82f6;
    color: #3b82f6;
    background: white;
}

#labelBayan {
    border: 2px solid #ec4899;
    color: #ec4899;
    background: white;
}

#labelBay:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

#labelBayan:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
}

.btn-check:checked + #labelBay {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #3b82f6;
    color: white;
    transform: scale(1.02);
}

.btn-check:checked + #labelBayan {
    background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
    border-color: #ec4899;
    color: white;
    transform: scale(1.02);
}

.form-buttons .btn {
    min-height: 50px;
}

@media (max-width: 768px) {
    .kimlik-hero .hero-title {
        font-size: 1.5rem;
    }
    
    .btn-gender {
        min-width: 120px;
        padding: 14px 20px;
        font-size: 1.1rem;
    }
}

footer {
    margin-top: auto;
}
</style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Kimlik Form Section -->
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <!-- Progress Info -->
                    <div class="progress-info">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Adım 1 / 2</span>
                            <span class="badge bg-primary">Kimlik Bilgileri</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: 50%"></div>
                        </div>
                    </div>

                    <form id="kimlikForm">
                        <div class="row g-3">
                            <!-- İsim -->
                            <div class="col-md-6">
                                <label for="firstName" class="form-label">İsim <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="firstName" name="firstName" 
                                       required maxlength="50" placeholder="İsminiz">
                            </div>

                            <!-- Soyisim -->
                            <div class="col-md-6">
                                <label for="surname" class="form-label">Soyisim <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="surname" name="surname" 
                                       required maxlength="50" placeholder="Soyisminiz">
                            </div>

                            <!-- Email -->
                            <div class="col-md-6">
                                <label for="email" class="form-label">E-posta</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       maxlength="100" placeholder="ornek@mail.com">
                                <small class="text-muted">İsteğe bağlı</small>
                            </div>

                            <!-- Telefon -->
                            <div class="col-md-6">
                                <label class="form-label">Telefon <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">90</span>
                                    <input type="tel" inputmode="numeric" class="form-control" id="phoneAreaNumber" name="phoneAreaNumber" 
                                           required maxlength="3" placeholder="5XX" pattern="\d{3}">
                                    <input type="tel" inputmode="numeric" class="form-control" id="phoneNumber" name="phoneNumber" 
                                           required maxlength="7" placeholder="XXX XX XX" pattern="\d{7}">
                                </div>
                                <small class="text-muted">Örnek: 5XX XXX XXXX</small>
                            </div>

                            <!-- Doğum Tarihi -->
                            <div class="col-md-6">
                                <label for="birthDate" class="form-label">Doğum Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="birthDate" name="birthDate" 
                                       required max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                                <small class="text-muted">En az 18 yaşında olmalısınız</small>
                            </div>

                            <!-- İl -->
                            <div class="col-md-6">
                                <label for="il" class="form-label">İl <span class="text-danger">*</span></label>
                                <select class="form-select" id="il" name="il" required>
                                    <option value="">İl Seçiniz</option>
                                    <?php foreach ($iller as $il): ?>
                                        <option value="<?php echo $il['adres_iller_ID']; ?>" 
                                                data-code="<?php echo $il['adres_iller_code']; ?>"
                                                data-koi="<?php echo $il['adres_iller_KOI']; ?>">
                                            <?php echo htmlspecialchars($il['adres_iller_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- TC Kimlik No -->
                            <div class="col-md-6">
                                <label for="citizenNumber" class="form-label">TC Kimlik No <span class="text-danger">*</span></label>
                                <input type="tel" inputmode="numeric" class="form-control" id="citizenNumber" name="citizenNumber" 
                                       required maxlength="11" placeholder="XXXXXXXXXXX" pattern="\d{11}">
                                <small class="text-muted">11 haneli TC Kimlik Numaranız</small>
                            </div>

                            <!-- Kimlik Kartı Tipi -->
                            <div class="col-md-6">
                                <label for="identityCardType_ID" class="form-label">Kimlik Kartı Tipi</label>
                                <select class="form-select" id="identityCardType_ID" name="identityCardType_ID">
                                    <?php foreach ($cardTypes as $cardType): ?>
                                        <option value="<?php echo $cardType['API_GetCardTypeList_ID']; ?>"
                                                <?php echo $cardType['API_GetCardTypeList_ID'] == 1 ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cardType['API_GetCardTypeList_card_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Cinsiyet -->
                            <div class="col-12">
                                <label class="form-label">Cinsiyet <span class="text-danger">*</span></label>
                                <div class="gender-buttons mt-2">
                                    <input type="radio" class="btn-check" name="genderType" id="genderBay" value="BAY" required>
                                    <label class="btn btn-gender" for="genderBay" id="labelBay">
                                        Bay
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="genderType" id="genderBayan" value="BAYAN" required>
                                    <label class="btn btn-gender" for="genderBayan" id="labelBayan">
                                        Bayan
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-buttons mt-4">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                Devam Et <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                        
                        <?php if ($isAdmin && !empty($_SESSION['basvuru_params'])): ?>
                        <div class="alert alert-info mt-3 small">
                            <strong><i class="fas fa-info-circle"></i> Session Parametreler:</strong><br>
                            API ID: <?php echo $_SESSION['basvuru_params']['api_ID'] ?? '-'; ?> | 
                            Kampanya: <?php echo $_SESSION['basvuru_params']['kampanya'] ?? '-'; ?> | 
                            Paket: <?php echo $_SESSION['basvuru_params']['paket'] ?? '-'; ?>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const kimlikForm = document.getElementById('kimlikForm');
    
    // İl dropdown'a search ekle
    const ilSelect = document.getElementById('il');
    if (ilSelect) {
        new TomSelect(ilSelect, {
            placeholder: 'İl Seçiniz',
            allowEmptyOption: false,
            create: false,
            sortField: 'text',
            plugins: ['dropdown_input'],
            render: {
                no_results: function() {
                    return '<div class="no-results">Sonuç bulunamadı</div>';
                }
            }
        });
    }
    
    // Telefon alanlarına sadece sayı girişi
    document.getElementById('phoneAreaNumber').addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').substring(0, 3);
    });
    
    document.getElementById('phoneNumber').addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').substring(0, 7);
    });
    
    // TC Kimlik No sadece sayı
    document.getElementById('citizenNumber').addEventListener('input', function(e) {
        this.value = this.value.replace(/\D/g, '').substring(0, 11);
    });
    
    // Form gönderimi
    kimlikForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'saveKimlik');
        
        // URL parametrelerini ekle (session için)
        const params = new URLSearchParams(window.location.search);
        if (params.has('api_ID')) formData.append('api_ID', params.get('api_ID'));
        if (params.has('kampanya')) formData.append('kampanya', params.get('kampanya'));
        if (params.has('paket')) formData.append('paket', params.get('paket'));
        
        // Kaynak site bilgisi ekle (iframe parent URL)
        try {
            // iframe içindeyse parent'ın URL'ini al
            if (window.self !== window.top && window.parent.location.href) {
                const kaynakSite = new URL(window.parent.location.href).hostname;
                formData.append('kaynak_site', kaynakSite);
                console.log('Kaynak site (parent): ' + kaynakSite);
            } else if (document.referrer) {
                const kaynakSite = new URL(document.referrer).hostname;
                formData.append('kaynak_site', kaynakSite);
                console.log('Kaynak site (referrer): ' + kaynakSite);
            }
        } catch(e) {
            // Cross-origin iframe'de parent.location erişimi engellenirse referrer kullan
            if (document.referrer) {
                const kaynakSite = new URL(document.referrer).hostname;
                formData.append('kaynak_site', kaynakSite);
                console.log('Kaynak site (referrer-catch): ' + kaynakSite);
            } else {
                console.log('Kaynak site tespit edilemedi, varsayılan kullanılacak');
            }
        }
        
        console.log('Form gönderiliyor - URL parametreleri:', {
            api_ID: params.get('api_ID'),
            kampanya: params.get('kampanya'),
            paket: params.get('paket')
        });
        
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kaydediliyor...';
        
        fetch('basvuru.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            
            if (data.success) {
                // URL parametrelerini koru
                const params = new URLSearchParams(window.location.search);
                
                // Absolute URL oluştur (iframe uyumluluğu için)
                const baseUrl = 'https://digiturk.ilekasoft.com/views/Bayi/api/';
                let nextUrl = '';
                
                // Neo kampanyası ise adres adımını atla
                if (data.isNeo) {
                    // Neo kampanya - Adres atlandı, direkt paket seçimine git
                    nextUrl = baseUrl + 'basvuru-paket.php?' + params.toString();
                    console.log('Neo kampanya - Adres atlandı, bbkAddressCode otomatik üretildi');
                } else {
                    // Normal kampanya - Adres bilgilerine git
                    nextUrl = baseUrl + 'basvuru-adres.php?' + params.toString();
                    console.log('Normal kampanya - Adres bilgilerine yönlendiriliyor...');
                }
                
                console.log('Yönlendirme URL:', nextUrl);
                console.log('window.location.href değiştiriliyor...');
                
                // Yönlendirmeyi biraz geciktir (debugging için)
                setTimeout(function() {
                    window.location.href = nextUrl;
                }, 100);
            } else {
                // Hata durumunda kullanıcıya göster
                alert('❌ Hata: ' + data.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Bir hata oluştu. Lütfen tekrar deneyin.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    });
});
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>