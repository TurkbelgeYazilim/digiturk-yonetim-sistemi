<?php
/**
 * Başvuru Sayfası - Paket Seçimi (Adım 3)
 * 
 * ⚠️ ÖNEMLİ İSTİSNA: Bu sayfa dışarıdan (public) erişime açıktır!
 * - Auth kontrolü YOK
 * - Yetki kontrolü YOK
 * - Header/Footer/Navigation YOK, Kullanma!
 * 
 * PAKET SEÇİM KURALLARI:
 * 1) api_ID=x                    → Kutulu VE Kutusuz tüm paketler (KOI kontrollü)
 * 2) api_ID=x&kampanya=1/2       → Sadece seçili kampanya paketleri (KOI kontrollü)
 * 3) api_ID=x&kampanya=1/2&paket=x → Paket seçimini atla, direkt kaydet
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 * @phone +90 501 357 10 85
 */

// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Debug: Session içeriğini logla
error_log("Paket sayfası yüklendi - Session kontrol:");
error_log("basvuru_kimlik: " . (isset($_SESSION['basvuru_kimlik']) ? 'VAR' : 'YOK'));
error_log("basvuru_adres: " . (isset($_SESSION['basvuru_adres']) ? 'VAR' : 'YOK'));
error_log("basvuru_id: " . (isset($_SESSION['basvuru_id']) ? $_SESSION['basvuru_id'] : 'YOK'));
error_log("basvuru_id type: " . (isset($_SESSION['basvuru_id']) ? gettype($_SESSION['basvuru_id']) : 'N/A'));
error_log("basvuru_id empty check: " . (empty($_SESSION['basvuru_id']) ? 'EMPTY' : 'DOLU'));

// Kimlik bilgisi mutlaka olmalı
if (!isset($_SESSION['basvuru_kimlik']) || empty($_SESSION['basvuru_kimlik'])) {
    error_log("HATA: basvuru_kimlik yok veya boş, başa yönlendiriliyor");
    header('Location: basvuru.php');
    exit;
}

// basvuru_id mutlaka olmalı ve boş olmamalı
if (!isset($_SESSION['basvuru_id']) || empty($_SESSION['basvuru_id'])) {
    error_log("HATA: basvuru_id yok veya boş, başa yönlendiriliyor");
    error_log("Session içeriği: " . print_r($_SESSION, true));
    header('Location: basvuru.php');
    exit;
}

// Veritabanı bağlantısı (sayfa başında tanımla)
require_once '../../../config/mssql.php';

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

$conn = getDatabaseConnection();

// KURAL 3: Paket parametresi varsa bu sayfayı atla ve direkt teşekkür sayfasına git
if (isset($_SESSION['basvuru_params']['paket']) && !empty($_SESSION['basvuru_params']['paket'])) {
    $paketId = intval($_SESSION['basvuru_params']['paket']);
    
    error_log("KURAL 3: Paket parametresi var ($paketId), sayfa atlanıyor");
    
    // Session'a paket bilgisini kaydet
    $_SESSION['basvuru_paket'] = [
        'paketId' => $paketId,
        'odemeTuruId' => null // Paket tablosunda tutuluyor
    ];
    
    // Veritabanına kaydet
    if (isset($_SESSION['basvuru_id']) && !empty($_SESSION['basvuru_id'])) {
        try {
            $updateSql = "UPDATE [dbo].[API_basvuruListesi] 
                         SET [API_basvuru_Paket_ID] = ?,
                             [API_basvuru_guncelleme_tarihi] = GETDATE() 
                         WHERE [API_basvuru_ID] = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateResult = $updateStmt->execute([$paketId, $_SESSION['basvuru_id']]);
            
            error_log("KURAL 3: Paket ID güncellendi - Sonuç: " . ($updateResult ? 'BAŞARILI' : 'BAŞARISIZ'));
        } catch (Exception $e) {
            error_log("KURAL 3: UPDATE hatası - " . $e->getMessage());
        }
    }
    
    // URL parametrelerini koru ve teşekkür sayfasına yönlendir
    $params = new stdClass();
    if (isset($_SESSION['basvuru_params']['api_ID'])) {
        $apiParam = '?api_ID=' . urlencode($_SESSION['basvuru_params']['api_ID']);
    } else {
        $apiParam = '';
    }
    
    error_log("KURAL 3: Teşekkür sayfasına yönlendiriliyor");
    header('Location: basvuru-tesekkurler.php' . $apiParam);
    exit;
}

// AJAX - Paketleri getir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'getPaketler') {
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        $kampanyaId = isset($_POST['kampanyaId']) ? intval($_POST['kampanyaId']) : null;
        $odemeTuruId = intval($_POST['odemeTuruId']);
        
        // İl bazlı KOI değerini al
        $ilId = isset($_SESSION['basvuru_kimlik']['il_ID']) ? $_SESSION['basvuru_kimlik']['il_ID'] : null;
        $koiValue = 0;
        
        if ($ilId) {
            $ilStmt = $conn->prepare("SELECT [adres_iller_KOI] FROM [dbo].[adres_iller] WHERE [adres_iller_ID] = ?");
            $ilStmt->execute([$ilId]);
            $ilData = $ilStmt->fetch();
            if ($ilData) {
                $koiValue = $ilData['adres_iller_KOI'];
            }
        }
        
        $paketler = [];
        
        // KURAL 1: Kampanya ID yok - Hem Neo hem Satellite paketleri göster
        if ($kampanyaId === null || $kampanyaId === 0) {
            // Neo paketleri
            $neoSql = "SELECT [API_GetNeoCampaignList_ID] as id,
                              [API_GetNeoCampaignList_PaketAdi] as paket_adi,
                              [API_GetNeoCampaignList_Fiyat] as fiyat,
                              [API_GetNeoCampaignList_Hediye] as hediye,
                              [API_GetNeoCampaignList_Periyot] as periyot,
                              'neo' as kampanya_tipi,
                              2 as kampanya_id
                       FROM [dbo].[API_GetNeoCampaignList] 
                       WHERE [API_GetNeoCampaignList_durum] = 1 
                       AND [API_GetNeoCampaignList_odeme_turu_id] = ?
                       ORDER BY [API_GetNeoCampaignList_PaketAdi]";
            
            $neoStmt = $conn->prepare($neoSql);
            $neoStmt->execute([$odemeTuruId]);
            $neoPaketler = $neoStmt->fetchAll();
            
            // Satellite paketleri (KOI kontrollü)
            $satSql = "SELECT [API_GetSatelliteCampaignList_ID] as id,
                              [API_GetSatelliteCampaignList_PaketAdi] as paket_adi,
                              [API_GetSatelliteCampaignList_Fiyat] as fiyat,
                              [API_GetSatelliteCampaignList_Hediye] as hediye,
                              [API_GetSatelliteCampaignList_Periyot] as periyot,
                              'satellite' as kampanya_tipi,
                              1 as kampanya_id
                       FROM [dbo].[API_GetSatelliteCampaignList] 
                       WHERE [API_GetSatelliteCampaignList_durum] = 1 
                       AND [API_GetSatelliteCampaignList_KOI] = ?
                       AND [API_GetSatelliteCampaignList_odeme_turu_id] = ?
                       ORDER BY [API_GetSatelliteCampaignList_PaketAdi]";
            
            $satStmt = $conn->prepare($satSql);
            $satStmt->execute([$koiValue, $odemeTuruId]);
            $satPaketler = $satStmt->fetchAll();
            
            // İki listeyi birleştir
            $paketler = array_merge($neoPaketler, $satPaketler);
            
        } 
        // KURAL 2: Kampanya ID var - Sadece o kampanyanın paketleri
        else if ($kampanyaId == 2) {
            // Sadece Neo paketleri
            $sql = "SELECT [API_GetNeoCampaignList_ID] as id,
                           [API_GetNeoCampaignList_PaketAdi] as paket_adi,
                           [API_GetNeoCampaignList_Fiyat] as fiyat,
                           [API_GetNeoCampaignList_Hediye] as hediye,
                           [API_GetNeoCampaignList_Periyot] as periyot,
                           'neo' as kampanya_tipi,
                           2 as kampanya_id
                    FROM [dbo].[API_GetNeoCampaignList] 
                    WHERE [API_GetNeoCampaignList_durum] = 1 
                    AND [API_GetNeoCampaignList_odeme_turu_id] = ?
                    ORDER BY [API_GetNeoCampaignList_PaketAdi]";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$odemeTuruId]);
            $paketler = $stmt->fetchAll();
            
        } else {
            // Sadece Satellite paketleri (KOI kontrollü)
            $sql = "SELECT [API_GetSatelliteCampaignList_ID] as id,
                           [API_GetSatelliteCampaignList_PaketAdi] as paket_adi,
                           [API_GetSatelliteCampaignList_Fiyat] as fiyat,
                           [API_GetSatelliteCampaignList_Hediye] as hediye,
                           [API_GetSatelliteCampaignList_Periyot] as periyot,
                           'satellite' as kampanya_tipi,
                           1 as kampanya_id
                    FROM [dbo].[API_GetSatelliteCampaignList] 
                    WHERE [API_GetSatelliteCampaignList_durum] = 1 
                    AND [API_GetSatelliteCampaignList_KOI] = ?
                    AND [API_GetSatelliteCampaignList_odeme_turu_id] = ?
                    ORDER BY [API_GetSatelliteCampaignList_PaketAdi]";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$koiValue, $odemeTuruId]);
            $paketler = $stmt->fetchAll();
        }
        
        echo json_encode(['success' => true, 'paketler' => $paketler]);
        exit;
        
    } catch (Exception $e) {
        error_log("Paket listesi hatası: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Paketler yüklenemedi']);
        exit;
    }
}

// AJAX - Paket seçimini kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['savePaket'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        // Veritabanı bağlantısını al
        $conn = getDatabaseConnection();
        
        $odemeTuruId = intval($_POST['odemeTuruId']);
        $paketId = intval($_POST['paketId']);
        
        // Debug log
        error_log("savePaket çağrıldı - paketId: $paketId, odemeTuruId: $odemeTuruId, basvuru_id: " . $_SESSION['basvuru_id']);
        
        // Paketin hangi kampanyaya ait olduğunu tespit et
        $kampanyaId = null;
        
        // Önce Neo'da ara (kampanya ID = 2)
        $checkNeoSql = "SELECT COUNT(*) as count FROM [dbo].[API_GetNeoCampaignList] 
                        WHERE [API_GetNeoCampaignList_ID] = ? AND [API_GetNeoCampaignList_durum] = 1";
        $checkNeoStmt = $conn->prepare($checkNeoSql);
        $checkNeoStmt->execute([$paketId]);
        $neoResult = $checkNeoStmt->fetch();
        
        if ($neoResult && $neoResult['count'] > 0) {
            $kampanyaId = 2; // Neo kampanya
            error_log("Paket Neo kampanyasında bulundu (kampanya ID: 2)");
        } else {
            // Satellite'de ara (kampanya ID = 1)
            $checkSatSql = "SELECT COUNT(*) as count FROM [dbo].[API_GetSatelliteCampaignList] 
                            WHERE [API_GetSatelliteCampaignList_ID] = ? AND [API_GetSatelliteCampaignList_durum] = 1";
            $checkSatStmt = $conn->prepare($checkSatSql);
            $checkSatStmt->execute([$paketId]);
            $satResult = $checkSatStmt->fetch();
            
            if ($satResult && $satResult['count'] > 0) {
                $kampanyaId = 1; // Satellite kampanya
                error_log("Paket Satellite kampanyasında bulundu (kampanya ID: 1)");
            }
        }
        
        // Session'a kampanya bilgisini ekle
        if ($kampanyaId) {
            $_SESSION['basvuru_params']['kampanya'] = $kampanyaId;
        }
        
        $_SESSION['basvuru_paket'] = [
            'odemeTuruId' => $odemeTuruId,
            'paketId' => $paketId,
            'kampanyaId' => $kampanyaId
        ];
        
        // Başvuru kaydını güncelle (Paket ID + Kampanya ID)
        if (isset($_SESSION['basvuru_id']) && !empty($_SESSION['basvuru_id'])) {
            $updateSql = "UPDATE [dbo].[API_basvuruListesi] 
                         SET [API_basvuru_Paket_ID] = ?,
                             [API_basvuru_CampaignList_ID] = ?,
                             [API_basvuru_guncelleme_tarihi] = GETDATE() 
                         WHERE [API_basvuru_ID] = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateResult = $updateStmt->execute([$paketId, $kampanyaId, $_SESSION['basvuru_id']]);
            
            // Debug log
            error_log("UPDATE sonucu: " . ($updateResult ? 'BAŞARILI' : 'BAŞARISIZ'));
            error_log("Güncellenen: Paket=$paketId, Kampanya=$kampanyaId");
            error_log("Etkilenen satır: " . $updateStmt->rowCount());
            
            if (!$updateResult) {
                throw new Exception('Veritabanı güncelleme başarısız');
            }
        } else {
            error_log("UYARI: basvuru_id yok, UPDATE yapılmadı!");
        }
        
        echo json_encode(['success' => true, 'message' => 'Paket seçimi kaydedildi']);
        exit;
        
    } catch (Exception $e) {
        error_log("Paket kayıt hatası: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}

// Ödeme türlerini çek
$odemeTurleri = [];
try {
    $stmt = $conn->query("SELECT [API_odeme_turu_ID], [API_odeme_turu_ad] FROM [dbo].[API_odeme_turu] ORDER BY [API_odeme_turu_ID]");
    $odemeTurleri = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Ödeme türleri hatası: " . $e->getMessage());
}

// Kampanya ID'sini al (null olabilir - o zaman her ikisi de gösterilecek)
$kampanyaId = isset($_SESSION['basvuru_params']['kampanya']) ? intval($_SESSION['basvuru_params']['kampanya']) : null;

// Kampanya adını belirle
if ($kampanyaId === null || $kampanyaId === 0) {
    $kampanyaAdi = 'Kutulu & Kutusuz';
    $kampanyaBadgeColor = 'linear-gradient(135deg, #f97316 0%, #ea580c 100%)';
} else if ($kampanyaId == 2) {
    $kampanyaAdi = 'Kutusuz (Neo)';
    $kampanyaBadgeColor = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
} else {
    $kampanyaAdi = 'Kutulu (Satellite)';
    $kampanyaBadgeColor = 'linear-gradient(135deg, #ec4899 0%, #db2777 100%)';
}

$kimlik = $_SESSION['basvuru_kimlik'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Digiturk Başvuru Formu - Paket Seçimi">
    <title>Paket Seçimi - Digiturk Başvuru</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        .paket-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 0 30px;
            border-radius: 0;
        }
        
        .paket-hero .hero-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .paket-hero .hero-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .progress-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        
        .kampanya-badge {
            display: inline-block;
            padding: 8px 20px;
            background: <?php echo $kampanyaBadgeColor; ?>;
            color: white;
            border-radius: 25px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .paket-card .kampanya-tag {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 12px;
            color: white;
        }
        
        .paket-card .kampanya-tag.neo {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .paket-card .kampanya-tag.satellite {
            background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
        }
        
        .odeme-turu-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        
        .btn-odeme {
            flex: 1;
            min-width: 180px;
            padding: 15px 20px;
            font-size: 1.1rem;
            font-weight: 500;
            border: 2px solid #10b981;
            color: #10b981;
            background: white;
            transition: all 0.3s ease;
            border-radius: 8px;
        }
        
        .btn-odeme:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .btn-check:checked + .btn-odeme {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
            color: white;
            transform: scale(1.02);
        }
        
        .paket-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            background: white;
            height: 100%;
            position: relative;
        }
        
        .paket-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        
        .paket-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transform: scale(1.02);
        }
        
        .paket-card .paket-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            min-height: 50px;
        }
        
        .paket-card .paket-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .paket-card .paket-hediye {
            font-size: 0.9rem;
            color: #10b981;
            font-weight: 500;
            padding: 5px 10px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 6px;
            display: inline-block;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Paket Form Section -->
    <div class="row justify-content-center mt-4">
        <div class="col-lg-10">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <!-- Progress Info -->
                    <div class="progress-info">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Adım 3 / 3</span>
                            <span class="badge bg-primary">Paket Seçimi</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: 100%"></div>
                        </div>
                    </div>

                    <!-- Kampanya Bilgisi -->
                    <div class="text-center mb-4">
                        <div class="kampanya-badge">
                            <i class="fas fa-satellite-dish me-2"></i><?php echo $kampanyaAdi; ?> Kampanyası
                        </div>
                    </div>

                    <!-- Ödeme Türü Seçimi -->
                    <div class="mb-4">
                        <label class="form-label fw-bold fs-5">Ödeme Türü Seçiniz <span class="text-danger">*</span></label>
                        <div class="odeme-turu-buttons">
                            <?php foreach ($odemeTurleri as $odemeTuru): 
                                $icon = $odemeTuru['API_odeme_turu_ID'] == 1 ? 'bi-credit-card' : 'bi-receipt';
                            ?>
                                <input type="radio" class="btn-check" name="odemeTuru" 
                                       id="odeme<?php echo $odemeTuru['API_odeme_turu_ID']; ?>" 
                                       value="<?php echo $odemeTuru['API_odeme_turu_ID']; ?>" required>
                                <label class="btn btn-odeme" for="odeme<?php echo $odemeTuru['API_odeme_turu_ID']; ?>">
                                    <i class="bi <?php echo $icon; ?> me-2"></i><?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Paket Listesi -->
                    <div id="paketListSection" style="display: none;">
                        <label class="form-label fw-bold fs-5 mb-3">Paket Seçiniz <span class="text-danger">*</span></label>
                        <div id="paketList" class="row g-3">
                            <!-- Paketler buraya yüklenecek -->
                        </div>
                    </div>

                    <!-- Loading Spinner -->
                    <div id="loadingSpinner" class="text-center py-5" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Yükleniyor...</span>
                        </div>
                        <p class="mt-3 text-muted">Paketler yükleniyor...</p>
                    </div>

                    <!-- Form Buttons -->
                    <div class="d-flex justify-content-between gap-3 mt-4">
                        <a href="basvuru-adres.php<?php echo isset($_GET['api_ID']) ? '?api_ID='.$_GET['api_ID'].'&kampanya='.$kampanyaId.'&paket='.(isset($_SESSION['basvuru_params']['paket']) ? $_SESSION['basvuru_params']['paket'] : '') : ''; ?>" class="btn btn-outline-secondary btn-lg">
                            <i class="bi bi-arrow-left me-2"></i>Geri
                        </a>
                        <button type="button" id="devamBtn" class="btn btn-primary btn-lg px-5" disabled>
                            Tamamla <i class="bi bi-check-circle ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const kampanyaId = <?php echo ($kampanyaId !== null ? $kampanyaId : 'null'); ?>;
    let selectedOdemeTuru = null;
    let selectedPaketId = null;
    
    const odemeTuruInputs = document.querySelectorAll('input[name="odemeTuru"]');
    const paketListSection = document.getElementById('paketListSection');
    const paketList = document.getElementById('paketList');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const devamBtn = document.getElementById('devamBtn');
    
    // Ödeme türü seçimi
    odemeTuruInputs.forEach(input => {
        input.addEventListener('change', function() {
            selectedOdemeTuru = this.value;
            selectedPaketId = null;
            devamBtn.disabled = true;
            
            if (!selectedOdemeTuru) {
                paketListSection.style.display = 'none';
                return;
            }
            
            // Paketleri yükle
            loadPaketler();
        });
    });
    
    // Paketleri yükle
    function loadPaketler() {
        loadingSpinner.style.display = 'block';
        paketListSection.style.display = 'none';
        paketList.innerHTML = '';
        
        const formData = new FormData();
        formData.append('action', 'getPaketler');
        formData.append('kampanyaId', kampanyaId);
        formData.append('odemeTuruId', selectedOdemeTuru);
        
        fetch('basvuru-paket.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            loadingSpinner.style.display = 'none';
            
            if (data.success && data.paketler.length > 0) {
                renderPaketler(data.paketler);
                paketListSection.style.display = 'block';
            } else {
                paketList.innerHTML = '<div class="col-12 text-center text-muted py-4">Bu ödeme türü için paket bulunamadı</div>';
                paketListSection.style.display = 'block';
            }
        })
        .catch(error => {
            loadingSpinner.style.display = 'none';
            console.error('Hata:', error);
            alert('❌ Paketler yüklenirken bir hata oluştu');
        });
    }
    
    // Paketleri render et
    function renderPaketler(paketler) {
        paketList.innerHTML = '';
        
        paketler.forEach(paket => {
            // Yeni unified yapı
            const paketId = paket.id;
            const paketName = paket.paket_adi || 'Paket';
            const paketPrice = paket.fiyat || '';
            const paketHediye = paket.hediye || '';
            const paketPeriyot = paket.periyot || '';
            const kampanyaTipi = paket.kampanya_tipi; // 'neo' veya 'satellite'
            const kampanyaTagText = kampanyaTipi === 'neo' ? 'Kutusuz' : 'Kutulu';
            
            const col = document.createElement('div');
            col.className = 'col-md-6 col-lg-4';
            
            // Eğer her iki kampanya da gösteriliyorsa tag ekle
            const showTag = (kampanyaId === null || kampanyaId === 0);
            
            col.innerHTML = `
                <div class="paket-card" data-paket-id="${paketId}" data-kampanya-id="${paket.kampanya_id}">
                    ${showTag ? '<div class="kampanya-tag ' + kampanyaTipi + '">' + kampanyaTagText + '</div>' : ''}
                    <div class="paket-name">${paketName}</div>
                    <div class="paket-price">${paketPrice ? paketPrice + ' ₺' : 'Fiyat Yok'}${paketPeriyot ? '/' + paketPeriyot : ''}</div>
                    ${paketHediye ? '<div class="paket-hediye"><i class="bi bi-gift me-1"></i>' + paketHediye + '</div>' : ''}
                </div>
            `;
            
            paketList.appendChild(col);
        });
        
        // Paket kartlarına click eventi ekle
        document.querySelectorAll('.paket-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.paket-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                selectedPaketId = this.getAttribute('data-paket-id');
                devamBtn.disabled = false;
            });
        });
    }
    
    // Devam et butonu
    devamBtn.addEventListener('click', function(e) {
        e.preventDefault();
        
        console.log('Devam butonu tıklandı!');
        console.log('Seçili Paket ID:', selectedPaketId);
        console.log('Seçili Ödeme Türü:', selectedOdemeTuru);
        
        if (!selectedPaketId) {
            alert('⚠️ Lütfen bir paket seçiniz!');
            return;
        }
        
        if (!selectedOdemeTuru) {
            alert('⚠️ Lütfen bir ödeme türü seçiniz!');
            return;
        }
        
        const formData = new FormData();
        formData.append('savePaket', '1');
        formData.append('odemeTuruId', selectedOdemeTuru);
        formData.append('paketId', selectedPaketId);
        
        const btn = this;
        const originalText = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kaydediliyor...';
        
        console.log('Paket kaydediliyor - Paket ID:', selectedPaketId, 'Ödeme Türü:', selectedOdemeTuru);
        console.log('FormData içeriği:', {
            savePaket: '1',
            odemeTuruId: selectedOdemeTuru,
            paketId: selectedPaketId
        });
        
        fetch('basvuru-paket.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Response text:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed data:', data);
                
                if (data.success) {
                    console.log('✓ Paket seçimi kaydedildi!');
                    
                    // URL parametrelerini koruyarak teşekkür sayfasına yönlendir
                    const params = new URLSearchParams(window.location.search);
                    const redirectUrl = 'basvuru-tesekkurler.php?' + params.toString();
                    console.log('Yönlendiriliyor:', redirectUrl);
                    window.location.href = redirectUrl;
                } else {
                    alert('❌ Hata: ' + data.message);
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (e) {
                console.error('JSON parse hatası:', e);
                console.error('Gelen text:', text);
                alert('❌ Sunucu yanıtı hatalı');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Fetch hatası:', error);
            alert('❌ Bir hata oluştu: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });
});
</script>

</body>
</html>
