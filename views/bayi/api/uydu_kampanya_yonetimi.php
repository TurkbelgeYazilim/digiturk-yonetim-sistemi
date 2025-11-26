<?php
// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// Dinamik sayfa bilgilerini al
$sayfaBilgileri = [];
try {
    $conn = getDatabaseConnection();
    
    // Mevcut sayfa URL'sini al
    $currentPageUrl = basename($_SERVER['PHP_SELF']);
    
    // Sayfa bilgilerini çek
    $sayfaSql = "
        SELECT 
            sayfa_adi,
            sayfa_aciklama,
            sayfa_icon
        FROM dbo.tanim_sayfalar 
        WHERE sayfa_url = ? 
        AND durum = 1
    ";
    
    $sayfaStmt = $conn->prepare($sayfaSql);
    $sayfaStmt->execute([$currentPageUrl]);
    $sayfaBilgileri = $sayfaStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Hata durumunda varsayılan değerler
    $sayfaBilgileri = [
        'sayfa_adi' => 'Uydu Kampanya Yönetimi',
        'sayfa_aciklama' => 'API entegreli Uydu kampanya yönetim sistemi',
        'sayfa_icon' => 'fa-satellite'
    ];
}

// Sayfa bilgilerini değişkenlere ata
$pageTitle = $sayfaBilgileri['sayfa_adi'] ?? 'Uydu Kampanya Yönetimi';
$pageDescription = $sayfaBilgileri['sayfa_aciklama'] ?? 'API entegreli Uydu kampanya yönetim sistemi';
$pageIcon = $sayfaBilgileri['sayfa_icon'] ?? 'fa-satellite';

// Sayfa yetki kontrolü
$pagePermissions = checkPagePermission($currentPageUrl);
if (!$pagePermissions) {
    header('Location: /index.php');
    exit;
}

$message = '';
$messageType = '';

// API'den veri çekme fonksiyonu
function fetchApiData($apiId, $conn) {
    try {
        // API bilgilerini al
        $apiSql = "SELECT api_iris_Address_URL FROM dbo.API_Link WHERE api_iris_Address_ID = ?";
        $apiStmt = $conn->prepare($apiSql);
        $apiStmt->execute([$apiId]);
        $apiInfo = $apiStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$apiInfo) {
            return false;
        }
        
        // Token bilgisini al
        $tokenSql = "SELECT api_iris_kullanici_token FROM dbo.API_kullanici WHERE api_iris_kullanici_ID = 1";
        $tokenStmt = $conn->prepare($tokenSql);
        $tokenStmt->execute();
        $tokenInfo = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        
        $apiUrl = $apiInfo['api_iris_Address_URL'];
        $token = $tokenInfo['api_iris_kullanici_token'] ?? '';
        
        // CURL ile API çağrısı
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Token: ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("API çağrısı hatası: " . $e->getMessage());
        return false;
    }
}

// API'den uydu kampanya verilerini parse etme fonksiyonu
function parseAndSaveSatelliteData($apiData, $conn) {
    $debug = ($_SESSION['user_group_id'] == 1); // Admin kullanıcılar için debug
    $debugInfo = [];
    
    if ($debug) {
        $debugInfo['raw_response'] = $apiData;
        $debugInfo['parse_steps'] = [];
    }
    
    if (!isset($apiData['data'])) {
        if ($debug) {
            $debugInfo['error'] = 'data alanı bulunamadı';
            $_SESSION['satellite_debug'] = $debugInfo;
        }
        return false;
    }
    
    try {
        $processedCount = 0;
        $insertedCount = 0;
        $updatedCount = 0;
        
        // Ana offerResultList'i atla, sadece subProductCatalogList'i işle
        if (isset($apiData['data']['subProductCatalogList']) && is_array($apiData['data']['subProductCatalogList'])) {
            foreach ($apiData['data']['subProductCatalogList'] as $subCatalog) {
                if (isset($subCatalog['offerResultList']) && is_array($subCatalog['offerResultList'])) {
                    foreach ($subCatalog['offerResultList'] as $item) {
                        $result = processSatelliteItem($item, $conn, $debug, $processedCount, $insertedCount, $updatedCount, $debugInfo);
                        $processedCount = $result['processed'];
                        $insertedCount = $result['inserted'];
                        $updatedCount = $result['updated'];
                    }
                }
            }
        }
        
        if ($debug) {
            $debugInfo['summary'] = [
                'total_processed' => $processedCount,
                'inserted' => $insertedCount,
                'updated' => $updatedCount
            ];
            $_SESSION['satellite_debug'] = $debugInfo;
        }
        
        return true;
        
    } catch (Exception $e) {
        if ($debug) {
            $debugInfo['error'] = 'Database hatası: ' . $e->getMessage();
            $_SESSION['satellite_debug'] = $debugInfo;
        }
        error_log("Uydu kampanya parse hatası: " . $e->getMessage());
        return false;
    }
}

// Tekil kampanya item'ını işleyen yardımcı fonksiyon
function processSatelliteItem($item, $conn, $debug, $processedCount, $insertedCount, $updatedCount, &$debugInfo) {
    $processedCount++;
    
    if ($debug) {
        $debugInfo['parse_steps'][] = "İşlenen kayıt #$processedCount: " . json_encode($item, JSON_UNESCAPED_UNICODE);
    }
    
    // Gerekli alanları kontrol et
    if (!isset($item['name'])) {
        if ($debug) {
            $debugInfo['parse_steps'][] = "Kayıt #$processedCount: 'name' alanı eksik, atlanıyor";
        }
        return ['processed' => $processedCount, 'inserted' => $insertedCount, 'updated' => $updatedCount];
    }
    
    $name = $item['name'];
    $description = $item['description'] ?? '';
    $billFrequency = $item['billFrequency'] ?? '';
    $billFrequencyTypeCd = $item['billFrequencyTypeCd'] ?? '';
    $currencyTypeCd = $item['currencyTypeCd'] ?? '';
    $offerFromCode = $item['offerFromCode'] ?? '';
    $offerFromId = $item['offerFromId'] ?? '';
    $offerToCode = $item['offerToCode'] ?? '';
    $offerToId = $item['offerToId'] ?? '';
    $priceAmount = $item['priceAmount'] ?? 0;
    
    // priceAmount 0 ise durum pasif olmalı
    $durum = ($priceAmount > 0) ? 1 : 0;
    
    // Fiyat hesaplama ve formatlama
    $calculatedPrice = null;
    if ($priceAmount > 0) {
        if (strtoupper($billFrequencyTypeCd) === 'AY') {
            // Aylık fiyat - direkt kullan
            $calculatedPrice = number_format($priceAmount, 2, '.', '');
        } elseif (strtoupper($billFrequencyTypeCd) === 'YIL') {
            // Yıllık fiyat - 12'ye böl
            $calculatedPrice = number_format($priceAmount / 12, 2, '.', '');
        }
    }
    
    // Paket adı otomatik eşleştirme
    $paketAdi = null;
    $descriptionMapping = [
        'Sporun Yıldızı' => 'Sporun Yıldızı Paketi',
        'Beşiktaş Taraftar Pa' => 'Beşiktaş Taraftar Paketi',
        'Fenerbahçe Taraftar ' => 'Fenerbahçe Taraftar Paketi',
        'Galatasaray Taraftar' => 'Galatasaray Taraftar Paketi',
        'Trabzonspor Taraftar' => 'Trabzonspor Taraftar Paketi',
        'Eğlence ve Avrupanın' => 'Eğlencenin ve Avrupanın Yıldızı Paketi',
        'Yıldız Dolu Paketi' => 'Yıldız Dolu Paketi'
    ];
    
    if (isset($descriptionMapping[$description])) {
        $paketAdi = $descriptionMapping[$description];
    }
    
    // Benzersiz anahtar oluştur (name + offerFromCode + offerToCode kombinasyonu)
    $uniqueKey = $name . '_' . $offerFromCode . '_' . $offerToCode;
    
    // Mevcut kaydı kontrol et (benzersiz anahtar ile)
    $checkSql = "SELECT API_GetSatelliteCampaignList_ID FROM dbo.API_GetSatelliteCampaignList 
                WHERE API_GetSatelliteCampaignList_name = ? 
                AND API_GetSatelliteCampaignList_offerFromCode = ? 
                AND API_GetSatelliteCampaignList_offerToCode = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$name, $offerFromCode, $offerToCode]);
    $existingRecord = $checkStmt->fetch();
    
    if ($existingRecord) {
        // Güncelle - mevcut kayıtlarda durum alanı değişmesin
        $updateSql = "UPDATE dbo.API_GetSatelliteCampaignList SET 
                     API_GetSatelliteCampaignList_description = ?,
                     API_GetSatelliteCampaignList_billFrequency = ?,
                     API_GetSatelliteCampaignList_billFrequencyTypeCd = ?,
                     API_GetSatelliteCampaignList_currencyTypeCd = ?,
                     API_GetSatelliteCampaignList_offerFromId = ?,
                     API_GetSatelliteCampaignList_offerToId = ?,
                     API_GetSatelliteCampaignList_priceAmount = ?,
                     API_GetSatelliteCampaignList_Fiyat = ?,
                     API_GetSatelliteCampaignList_guncelleme_tarihi = GETDATE()
                     WHERE API_GetSatelliteCampaignList_name = ? 
                     AND API_GetSatelliteCampaignList_offerFromCode = ? 
                     AND API_GetSatelliteCampaignList_offerToCode = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([
            $description, $billFrequency, $billFrequencyTypeCd, $currencyTypeCd,
            $offerFromId, $offerToId, $priceAmount, $calculatedPrice, $name, $offerFromCode, $offerToCode
        ]);
        $updatedCount++;
        
        if ($debug) {
            $debugInfo['parse_steps'][] = "Kayıt #$processedCount güncellendi: $uniqueKey (Durum değiştirilmedi, Fiyat: " . ($calculatedPrice ?? 'N/A') . ")";
        }
    } else {
        // Yeni kayıt ekle
        $insertSql = "INSERT INTO dbo.API_GetSatelliteCampaignList 
                     (API_GetSatelliteCampaignList_name, API_GetSatelliteCampaignList_description,
                      API_GetSatelliteCampaignList_billFrequency, API_GetSatelliteCampaignList_billFrequencyTypeCd,
                      API_GetSatelliteCampaignList_currencyTypeCd, API_GetSatelliteCampaignList_offerFromCode,
                      API_GetSatelliteCampaignList_offerFromId, API_GetSatelliteCampaignList_offerToCode,
                      API_GetSatelliteCampaignList_offerToId, API_GetSatelliteCampaignList_priceAmount,
                      API_GetSatelliteCampaignList_Fiyat, API_GetSatelliteCampaignList_PaketAdi,
                      API_GetSatelliteCampaignList_durum, API_GetSatelliteCampaignList_olusturma_tarih) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
        $insertStmt = $conn->prepare($insertSql);
        $insertStmt->execute([
            $name, $description, $billFrequency, $billFrequencyTypeCd, $currencyTypeCd,
            $offerFromCode, $offerFromId, $offerToCode, $offerToId, $priceAmount, $calculatedPrice, $paketAdi, $durum
        ]);
        $insertedCount++;
        
        if ($debug) {
            $debugInfo['parse_steps'][] = "Kayıt #$processedCount eklendi: $uniqueKey (Paket: " . ($paketAdi ?? 'N/A') . ", Durum: " . ($durum ? 'Aktif' : 'Pasif') . ", Fiyat: " . ($calculatedPrice ?? 'N/A') . ")";
        }
    }
    
    return ['processed' => $processedCount, 'inserted' => $insertedCount, 'updated' => $updatedCount];
}

// API'den veri güncelleme işlemi
if (isset($_GET['refresh_api']) && $_GET['refresh_api'] == '1') {
    try {
        // Satellite Campaign API'den veri çek (ID 14)
        $apiSatelliteData = fetchApiData(14, $conn);
        if ($apiSatelliteData) {
            $parseResult = parseAndSaveSatelliteData($apiSatelliteData, $conn);
            if ($parseResult) {
                // Debug bilgilerinden özet mesaj oluştur
                if (isset($_SESSION['satellite_debug']['summary'])) {
                    $summary = $_SESSION['satellite_debug']['summary'];
                    $message = sprintf(
                        'Uydu kampanya verileri güncellendi! Toplam İşlenen: %d, Yeni Eklenen: %d, Güncellenen: %d',
                        $summary['total_processed'] ?? 0,
                        $summary['inserted'] ?? 0,
                        $summary['updated'] ?? 0
                    );
                } else {
                    $message = 'Uydu kampanya verileri başarıyla güncellendi!';
                }
                $messageType = 'success';
            } else {
                $message = 'Uydu kampanya verileri güncellenirken hata oluştu!';
                $messageType = 'danger';
            }
        } else {
            $message = 'API\'den Uydu kampanya verileri alınamadı!';
            $messageType = 'warning';
        }
    } catch (Exception $e) {
        $message = 'API güncelleme hatası: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Veritabanından verileri çek
$satelliteVerileri = [];
$odemeTurleri = [];

try {
    // Direkt tabloya erişmeyi dene
    $satelliteVerileri = [];
    $recordCount = ['record_count' => 0];
    
    // Tablodan kayıt sayısını kontrol et
    $countSql = "SELECT COUNT(*) as record_count FROM dbo.API_GetSatelliteCampaignList";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $recordCount = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($_SESSION['user_group_id'] == 1) {
        error_log("Satellite verileri sayısı: " . $recordCount['record_count']);
    }
    
    // Uydu kampanya verileri
    $satelliteSql = "SELECT * FROM dbo.API_GetSatelliteCampaignList ORDER BY API_GetSatelliteCampaignList_ID ASC";
    $satelliteStmt = $conn->prepare($satelliteSql);
    $satelliteStmt->execute();
    $satelliteVerileri = $satelliteStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($_SESSION['user_group_id'] == 1) {
        error_log("Çekilen satellite veri sayısı: " . count($satelliteVerileri));
        if (count($satelliteVerileri) > 0) {
            error_log("İlk kayıt örneği: " . print_r(array_keys($satelliteVerileri[0]), true));
        }
        
        // Debug mesajını sayfada da göster
        if (count($satelliteVerileri) == 0) {
            $message = 'Debug: Tablo mevcut ama kayıt yok. Kayıt sayısı: ' . $recordCount['record_count'] . '. Test verisi eklemek için "API\'den Güncelle" butonunu kullanabilirsin.';
            $messageType = 'info';
            
            // Test verisi ekle (sadece admin için)
            if (isset($_GET['add_test_data'])) {
                $testSql = "INSERT INTO dbo.API_GetSatelliteCampaignList 
                           (API_GetSatelliteCampaignList_name, API_GetSatelliteCampaignList_description,
                            API_GetSatelliteCampaignList_priceAmount, API_GetSatelliteCampaignList_durum,
                            API_GetSatelliteCampaignList_olusturma_tarih) 
                           VALUES (?, ?, ?, ?, GETDATE())";
                $testStmt = $conn->prepare($testSql);
                $testStmt->execute(['TEST_SAT_001', 'Test Uydu Kampanyası', 299.99, 1]);
                
                $message = 'Test verisi eklendi! Sayfayı yenile.';
                $messageType = 'success';
            }
        }
    }
    
    // Ödeme türü verileri
    $odemeTuruSql = "SELECT API_odeme_turu_ID, API_odeme_turu_ad FROM dbo.API_odeme_turu ORDER BY API_odeme_turu_ad ASC";
    $odemeTuruStmt = $conn->prepare($odemeTuruSql);
    $odemeTuruStmt->execute();
    $odemeTurleri = $odemeTuruStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata: ' . $e->getMessage();
    $messageType = 'danger';
    $satelliteVerileri = [];
    $odemeTurleri = [];
    
    if ($_SESSION['user_group_id'] == 1) {
        error_log("Database hatası: " . $e->getMessage());
    }
}

// KÖİ güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_koi') {
    try {
        $conn = getDatabaseConnection();
        $koiValue = $_POST['koi'] === 'true' ? 1 : 0;
        $sql = "UPDATE dbo.API_GetSatelliteCampaignList SET API_GetSatelliteCampaignList_KOI = ?, API_GetSatelliteCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetSatelliteCampaignList_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$koiValue, $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'KÖİ durumu başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Durum güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_durum') {
    try {
        $conn = getDatabaseConnection();
        $durumValue = $_POST['durum'] === 'true' ? 1 : 0;
        $sql = "UPDATE dbo.API_GetSatelliteCampaignList SET API_GetSatelliteCampaignList_durum = ?, API_GetSatelliteCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetSatelliteCampaignList_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$durumValue, $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Durum başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Alan güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_field') {
    try {
        $conn = getDatabaseConnection();
        $fieldMapping = [
            'PaketAdi' => 'API_GetSatelliteCampaignList_PaketAdi',
            'Fiyat' => 'API_GetSatelliteCampaignList_Fiyat',
            'Hediye' => 'API_GetSatelliteCampaignList_Hediye',
            'odeme_turu_id' => 'API_GetSatelliteCampaignList_odeme_turu_id'
        ];
        
        $field = $_POST['field'];
        $value = $_POST['value'];
        $id = $_POST['id'];
        
        if (!array_key_exists($field, $fieldMapping)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz alan adı']);
            exit;
        }
        
        $dbField = $fieldMapping[$field];
        $sql = "UPDATE dbo.API_GetSatelliteCampaignList SET [$dbField] = ?, API_GetSatelliteCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetSatelliteCampaignList_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$value, $id]);
        
        echo json_encode(['success' => true, 'message' => $field . ' başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// API güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_from_api') {
    try {
        $apiData = fetchApiData();
        if ($apiData) {
            $result = parseAndSaveSatelliteData($apiData);
            echo json_encode(['success' => true, 'message' => $result['message']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'API\'den veri alınamadı']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

include '../../../includes/header.php';
?>

<!-- DataTables devre dışı - Basit tablo kullanıyoruz -->
<!-- <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script> -->

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="<?php echo htmlspecialchars($pageIcon); ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h2>
                <div>
                    <a href="?refresh_api=1" class="btn btn-info btn-sm">
                        <i class="fas fa-sync me-1"></i>API'den Güncelle
                    </a>
                    <?php if ($_SESSION['user_group_id'] == 1): ?>
                        <a href="?add_test_data=1" class="btn btn-warning btn-sm ms-2">
                            <i class="fas fa-plus me-1"></i>Test Verisi Ekle
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="<?php echo htmlspecialchars($pageIcon); ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?>
            </h5>
        </div>
        <div class="card-body">
            <!-- Filtreleme Kartı -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Filtreleme
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">KOD</label>
                            <input type="text" class="form-control form-control-sm" id="filter-kod" placeholder="KOD...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control form-control-sm" id="filter-aciklama" placeholder="Açıklama...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fiyat</label>
                            <input type="text" class="form-control form-control-sm" id="filter-fiyat" placeholder="Fiyat...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ödeme Türü</label>
                            <select class="form-select form-select-sm" id="filter-odeme-turu">
                                <option value="">Tümü</option>
                                <?php if (is_array($odemeTurleri)): ?>
                                    <?php foreach ($odemeTurleri as $odemeTuru): ?>
                                        <option value="<?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>">
                                            <?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-3">
                            <label class="form-label">Paket Adı</label>
                            <input type="text" class="form-control form-control-sm" id="filter-paket" placeholder="Paket adı...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">KÖİ</label>
                            <select class="form-select form-select-sm" id="filter-koi">
                                <option value="">Tümü</option>
                                <option value="checked">KÖİ</option>
                                <option value="unchecked">KÖİ Değil</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select form-select-sm" id="filter-durum">
                                <option value="">Tümü</option>
                                <option value="checked">Aktif</option>
                                <option value="unchecked">Pasif</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()">
                                    <i class="fas fa-filter me-1"></i>Filtrele
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                    <i class="fas fa-times me-1"></i>Temizle
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover" id="satelliteTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>KOD</th>
                                        <th>Açıklama</th>
                                        <th>Fiyat</th>
                                        <th>Ödeme Türü</th>
                                        <th>Paket Adı</th>
                                        <th>Fiyat</th>
                                        <th>Hediye</th>
                                        <th>KÖİ</th>
                                        <th>Durum</th>
                                        <th>Güncelleme Tarihi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (isset($satelliteVerileri) && !empty($satelliteVerileri)): ?>
                                        <?php foreach ($satelliteVerileri as $index => $satellite): ?>
                                            <?php
                                            // Debug: Veri yapısını kontrol et
                                            if ($_SESSION['user_group_id'] == 1 && $index < 3) {
                                                error_log("Satellite veri " . $index . ": " . print_r(array_keys($satellite), true));
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($satellite['API_GetSatelliteCampaignList_ID'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(substr($satellite['API_GetSatelliteCampaignList_name'] ?? '', 0, 4)); ?></strong>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $aciklama = $satellite['API_GetSatelliteCampaignList_description'] ?? '';
                                                        echo htmlspecialchars(strlen($aciklama) > 50 ? substr($aciklama, 0, 50) . '...' : $aciklama); 
                                                        ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if (isset($satellite['API_GetSatelliteCampaignList_Fiyat']) && $satellite['API_GetSatelliteCampaignList_Fiyat']): ?>
                                                        <strong><?php echo number_format($satellite['API_GetSatelliteCampaignList_Fiyat'], 2); ?> TL</strong>
                                                    <?php else: ?>
                                                        <strong><?php echo number_format($satellite['API_GetSatelliteCampaignList_priceAmount'] ?? 0, 2); ?> TL</strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $isDurumAktif = isset($satellite['API_GetSatelliteCampaignList_durum']) && $satellite['API_GetSatelliteCampaignList_durum'] == 1;
                                                    $isOdemeTuruNull = empty($satellite['API_GetSatelliteCampaignList_odeme_turu_id']);
                                                    $requiresWarning = $isDurumAktif && $isOdemeTuruNull;
                                                    ?>
                                                    <select class="form-select form-select-sm auto-save-select <?php echo $requiresWarning ? 'border-danger' : ''; ?>" 
                                                            data-field="odeme_turu_id" 
                                                            data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                            <?php echo $requiresWarning ? 'title="⚠️ Durum aktif ama ödeme türü seçilmemiş!"' : ''; ?>>
                                                        <option value=""><?php echo $requiresWarning ? '⚠️ SEÇİNİZ (ZORUNLU)' : 'Seçiniz...'; ?></option>
                                                        <?php if (is_array($odemeTurleri)): ?>
                                                            <?php foreach ($odemeTurleri as $odemeTuru): ?>
                                                                <option value="<?php echo $odemeTuru['API_odeme_turu_ID']; ?>"
                                                                        <?php echo (isset($satellite['API_GetSatelliteCampaignList_odeme_turu_id']) && $satellite['API_GetSatelliteCampaignList_odeme_turu_id'] == $odemeTuru['API_odeme_turu_ID']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </select>
                                                    <?php if ($requiresWarning): ?>
                                                        <small class="text-danger d-block mt-1">
                                                            <i class="fas fa-exclamation-triangle"></i> Ödeme türü seç!
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm auto-save-input" 
                                                           data-field="PaketAdi" 
                                                           data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                           value="<?php echo htmlspecialchars($satellite['API_GetSatelliteCampaignList_PaketAdi'] ?? ''); ?>"
                                                           placeholder="Paket adı...">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm auto-save-input" 
                                                           data-field="Fiyat" 
                                                           data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                           value="<?php echo htmlspecialchars($satellite['API_GetSatelliteCampaignList_Fiyat'] ?? ''); ?>"
                                                           placeholder="Fiyat..."
                                                           step="0.01">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm auto-save-input" 
                                                           data-field="Hediye" 
                                                           data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                           value="<?php echo htmlspecialchars($satellite['API_GetSatelliteCampaignList_Hediye'] ?? ''); ?>"
                                                           placeholder="Hediye...">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input koi-checkbox" type="checkbox" 
                                                               data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                               <?php echo (isset($satellite['API_GetSatelliteCampaignList_KOI']) && $satellite['API_GetSatelliteCampaignList_KOI']) ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input durum-checkbox" type="checkbox" 
                                                               data-id="<?php echo $satellite['API_GetSatelliteCampaignList_ID'] ?? '0'; ?>"
                                                               <?php echo (isset($satellite['API_GetSatelliteCampaignList_durum']) && $satellite['API_GetSatelliteCampaignList_durum']) ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td><?php echo (isset($satellite['API_GetSatelliteCampaignList_guncelleme_tarihi']) && $satellite['API_GetSatelliteCampaignList_guncelleme_tarihi']) ? date('d.m.Y H:i', strtotime($satellite['API_GetSatelliteCampaignList_guncelleme_tarihi'])) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center">Kayıt bulunamadı</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
        </div>
    </div>
</div>

<script>
// Basit tablo yönetimi - DataTable yerine
$(document).ready(function() {
    console.log('Basit tablo modu başlatıldı');
    
    // Tablonun var olup olmadığını kontrol et
    if ($('#satelliteTable').length === 0) {
        console.error('satelliteTable bulunamadı');
        return;
    }
    
    // Tablo başlıklarını kontrol et
    var headerCount = $('#satelliteTable thead th').length;
    console.log('Başlık sayısı:', headerCount);
    
    // Basit sayfalama ekle
    addSimplePagination();
    
    console.log('Basit tablo başarıyla yüklendi');
});

// Basit sayfalama fonksiyonu
function addSimplePagination() {
    var rowsPerPage = 25;
    var rows = $('#satelliteTable tbody tr');
    var rowsCount = rows.length;
    var pageCount = Math.ceil(rowsCount / rowsPerPage);
    
    if (pageCount <= 1) return; // Sayfalama gereksiz
    
    // Sayfalama butonları ekle
    var paginationHtml = '<div class="d-flex justify-content-between align-items-center mt-3">';
    paginationHtml += '<div>Toplam ' + rowsCount + ' kayıt gösteriliyor</div>';
    paginationHtml += '<nav><ul class="pagination pagination-sm">';
    
    for (var i = 1; i <= pageCount; i++) {
        paginationHtml += '<li class="page-item' + (i === 1 ? ' active' : '') + '">';
        paginationHtml += '<a class="page-link" href="#" onclick="showPage(' + i + ', ' + rowsPerPage + ')">' + i + '</a>';
        paginationHtml += '</li>';
    }
    
    paginationHtml += '</ul></nav></div>';
    
    $('#satelliteTable').after(paginationHtml);
    
    // İlk sayfayı göster
    showPage(1, rowsPerPage);
}

// Sayfa gösterme fonksiyonu
function showPage(page, rowsPerPage) {
    var rows = $('#satelliteTable tbody tr');
    var start = (page - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    
    rows.hide();
    rows.slice(start, end).show();
    
    // Aktif sayfa butonunu güncelle
    $('.pagination .page-item').removeClass('active');
    $('.pagination .page-item:nth-child(' + page + ')').addClass('active');
}

// Basit filtreleme fonksiyonları
function applyFilters() {
        var kodFilter = $('#filter-kod').val().toLowerCase();
        var aciklamaFilter = $('#filter-aciklama').val().toLowerCase();
        var fiyatFilter = $('#filter-fiyat').val().toLowerCase();
        var odemeTuruFilter = $('#filter-odeme-turu').val().toLowerCase();
        var paketFilter = $('#filter-paket').val().toLowerCase();
        var koiFilter = $('#filter-koi').val();
        var durumFilter = $('#filter-durum').val();
        
        $('#satelliteTable tbody tr').each(function() {
            var row = $(this);
            var show = true;
            
            if (kodFilter && !row.find('td:eq(1)').text().toLowerCase().includes(kodFilter)) {
                show = false;
            }
            if (aciklamaFilter && !row.find('td:eq(2)').text().toLowerCase().includes(aciklamaFilter)) {
                show = false;
            }
            if (fiyatFilter && !row.find('td:eq(3)').text().toLowerCase().includes(fiyatFilter)) {
                show = false;
            }
            if (paketFilter && !row.find('td:eq(5)').find('input').val().toLowerCase().includes(paketFilter)) {
                show = false;
            }
            
            // Checkbox filtreleri
            if (koiFilter) {
                var koiChecked = row.find('td:eq(8) input[type="checkbox"]').is(':checked');
                if (koiFilter === 'checked' && !koiChecked) show = false;
                if (koiFilter === 'unchecked' && koiChecked) show = false;
            }
            
            if (durumFilter) {
                var durumChecked = row.find('td:eq(9) input[type="checkbox"]').is(':checked');
                if (durumFilter === 'checked' && !durumChecked) show = false;
                if (durumFilter === 'unchecked' && durumChecked) show = false;
            }
            
            if (show) {
                row.show();
            } else {
                row.hide();
            }
        });
        
        // Sayfalamayı yeniden hesapla
        $('.pagination').remove();
        addSimplePagination();
    }
    
    // Filtreleri temizle
    function clearFilters() {
        $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').val('');
        $('#filter-odeme-turu, #filter-koi, #filter-durum').val('');
        
        $('#satelliteTable tbody tr').show();
        
        // Sayfalamayı yeniden hesapla
        $('.pagination').remove();
        addSimplePagination();
    }
    
    // Enter tuşuna basınca filtrele
    $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').on('keypress', function(e) {
        if (e.which === 13) {
            applyFilters();
        }
    });
    
    // Global fonksiyonlar
    window.clearFilters = clearFilters;
    window.applyFilters = applyFilters;
});

// KÖİ checkbox değiştirme fonksiyonu
$(document).on('change', '.koi-checkbox', function() {
    const id = $(this).data('id');
    const isChecked = $(this).is(':checked');
    const $this = $(this);
    
    // Checkbox'ı geçici olarak devre dışı bırak
    $this.prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'update_koi',
            id: id,
            koi: isChecked
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                // Hata durumunda eski duruma geri döndür
                $this.prop('checked', !isChecked);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            // Hata durumunda eski duruma geri döndür
            $this.prop('checked', !isChecked);
            showMessage('danger', 'KÖİ durumu güncellenirken bir hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

// Durum checkbox değiştirme fonksiyonu
$(document).on('change', '.durum-checkbox', function() {
    const id = $(this).data('id');
    const isChecked = $(this).is(':checked');
    const $this = $(this);
    
    // Checkbox'ı geçici olarak devre dışı bırak
    $this.prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'update_durum',
            id: id,
            durum: isChecked
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                // Hata durumunda eski duruma geri döndür
                $this.prop('checked', !isChecked);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            // Hata durumunda eski duruma geri döndür
            $this.prop('checked', !isChecked);
            showMessage('danger', 'Durum güncellenirken bir hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

// Otomatik kaydetme fonksiyonu
$(document).on('blur', '.auto-save-input', function() {
    const $this = $(this);
    const id = $this.data('id');
    const field = $this.data('field');
    const value = $this.val();
    const originalValue = $this.attr('data-original-value') || '';
    
    // Değer değişmemişse kaydetme
    if (value === originalValue) {
        return;
    }
    
    // Loading state
    $this.prop('disabled', true).addClass('bg-light');
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'update_field',
            id: id,
            field: field,
            value: value
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $this.attr('data-original-value', value);
                $this.removeClass('bg-light').addClass('bg-success-subtle');
                setTimeout(() => {
                    $this.removeClass('bg-success-subtle');
                }, 1000);
                showMessage('success', response.message);
            } else {
                $this.removeClass('bg-light').addClass('bg-danger-subtle');
                setTimeout(() => {
                    $this.removeClass('bg-danger-subtle');
                }, 1000);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.removeClass('bg-light').addClass('bg-danger-subtle');
            setTimeout(() => {
                $this.removeClass('bg-danger-subtle');
            }, 1000);
            showMessage('danger', field + ' güncellenirken bir hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

// Input'ların orijinal değerlerini kaydet
$(document).ready(function() {
    $('.auto-save-input').each(function() {
        $(this).attr('data-original-value', $(this).val());
    });
    
    $('.auto-save-select').each(function() {
        $(this).attr('data-original-value', $(this).val());
    });
});

// Select için otomatik kaydetme fonksiyonu
$(document).on('change', '.auto-save-select', function() {
    const $this = $(this);
    const id = $this.data('id');
    const field = $this.data('field');
    const value = $this.val();
    const originalValue = $this.attr('data-original-value') || '';
    
    // Değer değişmemişse kaydetme
    if (value === originalValue) {
        return;
    }
    
    // Loading state
    $this.prop('disabled', true).addClass('bg-light');
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'update_field',
            id: id,
            field: field,
            value: value
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $this.attr('data-original-value', value);
                $this.removeClass('bg-light').addClass('bg-success-subtle');
                setTimeout(() => {
                    $this.removeClass('bg-success-subtle');
                }, 1000);
                showMessage('success', response.message);
            } else {
                $this.removeClass('bg-light').addClass('bg-danger-subtle');
                setTimeout(() => {
                    $this.removeClass('bg-danger-subtle');
                }, 1000);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.removeClass('bg-light').addClass('bg-danger-subtle');
            setTimeout(() => {
                $this.removeClass('bg-danger-subtle');
            }, 1000);
            showMessage('danger', field + ' güncellenirken bir hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

// Mesaj gösterme fonksiyonu
function showMessage(type, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Mevcut mesajları temizle
    $('.alert').remove();
    
    // Yeni mesajı ekle
    $('.container-fluid').first().append(alertHtml);
    
</script>

<script>
// Temiz JavaScript - Syntax hataları düzeltildi
$(document).ready(function() {
    console.log('Basit tablo modu başlatıldı');
    
    if ($('#satelliteTable').length > 0) {
        console.log('Tablo bulundu, başlatılıyor...');
        initializeTable();
    }
});

function initializeTable() {
    // Enter tuşu ile filtreleme
    $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').on('keypress', function(e) {
        if (e.which === 13) {
            applyFilters();
        }
    });
    
    // Basit sayfalama
    addSimplePagination();
}

function addSimplePagination() {
    var rows = $('#satelliteTable tbody tr:visible');
    var rowsCount = rows.length;
    
    if (rowsCount <= 25) return;
    
    var pageCount = Math.ceil(rowsCount / 25);
    var paginationHtml = '<div class="mt-3 d-flex justify-content-between">';
    paginationHtml += '<span>Toplam ' + rowsCount + ' kayıt</span>';
    paginationHtml += '<div class="pagination-simple">';
    
    for (var i = 1; i <= pageCount; i++) {
        paginationHtml += '<button class="btn btn-sm btn-outline-primary me-1" onclick="showPage(' + i + ')">' + i + '</button>';
    }
    
    paginationHtml += '</div></div>';
    $('#satelliteTable').after(paginationHtml);
    
    showPage(1);
}

function showPage(page) {
    var rows = $('#satelliteTable tbody tr:visible');
    var start = (page - 1) * 25;
    var end = start + 25;
    
    rows.hide();
    rows.slice(start, end).show();
    
    $('.pagination-simple button').removeClass('btn-primary').addClass('btn-outline-primary');
    $('.pagination-simple button:eq(' + (page - 1) + ')').removeClass('btn-outline-primary').addClass('btn-primary');
}

function applyFilters() {
    var kodFilter = $('#filter-kod').val().toLowerCase();
    var aciklamaFilter = $('#filter-aciklama').val().toLowerCase();
    var fiyatFilter = $('#filter-fiyat').val().toLowerCase();
    var paketFilter = $('#filter-paket').val().toLowerCase();
    var koiFilter = $('#filter-koi').val();
    var durumFilter = $('#filter-durum').val();
    
    $('#satelliteTable tbody tr').each(function() {
        var row = $(this);
        var show = true;
        
        if (kodFilter && !row.find('td:eq(1)').text().toLowerCase().includes(kodFilter)) show = false;
        if (aciklamaFilter && !row.find('td:eq(2)').text().toLowerCase().includes(aciklamaFilter)) show = false;
        if (fiyatFilter && !row.find('td:eq(3)').text().toLowerCase().includes(fiyatFilter)) show = false;
        if (paketFilter && !row.find('td:eq(5) input').val().toLowerCase().includes(paketFilter)) show = false;
        
        if (koiFilter) {
            var koiChecked = row.find('td:eq(8) input').is(':checked');
            if ((koiFilter === 'checked' && !koiChecked) || (koiFilter === 'unchecked' && koiChecked)) show = false;
        }
        
        if (durumFilter) {
            var durumChecked = row.find('td:eq(9) input').is(':checked');
            if ((durumFilter === 'checked' && !durumChecked) || (durumFilter === 'unchecked' && durumChecked)) show = false;
        }
        
        row.toggle(show);
    });
    
    $('.pagination-simple').parent().remove();
    addSimplePagination();
}

function clearFilters() {
    $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').val('');
    $('#filter-odeme-turu, #filter-koi, #filter-durum').val('');
    $('#satelliteTable tbody tr').show();
    $('.pagination-simple').parent().remove();
    addSimplePagination();
}

// AJAX işlemleri
$(document).on('change', '.koi-checkbox', function() {
    var $this = $(this);
    var id = $this.data('id');
    var isChecked = $this.is(':checked');
    
    $this.prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_koi', id: id, koi: isChecked },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                $this.prop('checked', !isChecked);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.prop('checked', !isChecked);
            showMessage('danger', 'KÖİ durumu güncellenirken hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

$(document).on('change', '.durum-checkbox', function() {
    var $this = $(this);
    var id = $this.data('id');
    var isChecked = $this.is(':checked');
    
    $this.prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_durum', id: id, durum: isChecked },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                $this.prop('checked', !isChecked);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.prop('checked', !isChecked);
            showMessage('danger', 'Durum güncellenirken hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

$(document).on('blur', '.auto-save-input, .auto-save-select', function() {
    var $this = $(this);
    var id = $this.data('id');
    var field = $this.data('field');
    var value = $this.val();
    var originalValue = $this.attr('data-original-value') || '';
    
    if (value === originalValue) return;
    
    $this.prop('disabled', true).addClass('bg-light');
    
    $.ajax({
        url: '',
        type: 'POST',
        data: { action: 'update_field', id: id, field: field, value: value },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $this.attr('data-original-value', value);
                $this.removeClass('bg-light').addClass('bg-success-subtle');
                setTimeout(() => $this.removeClass('bg-success-subtle'), 1000);
                showMessage('success', response.message);
            } else {
                $this.removeClass('bg-light').addClass('bg-danger-subtle');
                setTimeout(() => $this.removeClass('bg-danger-subtle'), 1000);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.removeClass('bg-light').addClass('bg-danger-subtle');
            setTimeout(() => $this.removeClass('bg-danger-subtle'), 1000);
            showMessage('danger', field + ' güncellenirken hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

function showMessage(type, message) {
    var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">';
    alertHtml += '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + ' me-2"></i>';
    alertHtml += message;
    alertHtml += '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    alertHtml += '</div>';
    
    $('.alert').remove();
    $('.container-fluid').first().append(alertHtml);
    
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 3000);
}
</script>

<?php include '../../../includes/footer.php'; ?>