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
        'sayfa_adi' => 'Neo Kampanya Yönetimi',
        'sayfa_aciklama' => 'API entegreli Neo kampanya yönetim sistemi',
        'sayfa_icon' => 'fa-gift'
    ];
}

// Sayfa bilgilerini değişkenlere ata
$pageTitle = $sayfaBilgileri['sayfa_adi'] ?? 'Neo Kampanya Yönetimi';
$pageDescription = $sayfaBilgileri['sayfa_aciklama'] ?? 'API entegreli Neo kampanya yönetim sistemi';
$pageIcon = $sayfaBilgileri['sayfa_icon'] ?? 'fa-gift';

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

// API'den neo kampanya verilerini parse etme fonksiyonu
function parseAndSaveNeoData($apiData, $conn) {
    $debug = ($_SESSION['user_group_id'] == 1); // Admin kullanıcılar için debug
    $debugInfo = [];
    
    if ($debug) {
        $debugInfo['raw_response'] = $apiData;
        $debugInfo['parse_steps'] = [];
    }
    
    if (!isset($apiData['data']['offerResultList']) || !is_array($apiData['data']['offerResultList'])) {
        if ($debug) {
            $debugInfo['error'] = 'offerResultList bulunamadı veya array değil';
            $_SESSION['neo_debug'] = $debugInfo;
        }
        return false;
    }
    
    try {
        $processedCount = 0;
        $insertedCount = 0;
        $updatedCount = 0;
        
        foreach ($apiData['data']['offerResultList'] as $item) {
            $processedCount++;
            
            if ($debug) {
                $debugInfo['parse_steps'][] = "İşlenen kayıt #$processedCount: " . json_encode($item, JSON_UNESCAPED_UNICODE);
            }
            
            // Gerekli alanları kontrol et
            if (!isset($item['name'])) {
                if ($debug) {
                    $debugInfo['parse_steps'][] = "Kayıt #$processedCount: 'name' alanı eksik, atlanıyor";
                }
                continue;
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
                'bC Sporun Yıldızı Pa' => 'Sporun Yıldızı Paketi',
                'bC Beşiktaş Taraftar' => 'Beşiktaş Taraftar Paketi',
                'bC Fenerbahçe Taraft' => 'Fenerbahçe Taraftar Paketi',
                'bC Galatasaray Taraf' => 'Galatasaray Taraftar Paketi',
                'bC Trabzonspor Taraf' => 'Trabzonspor Taraftar Paketi',
                'bC  Eğlence ve Avrup' => 'Eğlencenin ve Avrupanın Yıldızı Paketi',
                'bC Yıldız Dolu Paket' => 'Yıldız Dolu Paketi'
            ];
            
            if (isset($descriptionMapping[$description])) {
                $paketAdi = $descriptionMapping[$description];
            }
            
            // Raw response JSON olarak sakla
            $rawResponse = json_encode($item, JSON_UNESCAPED_UNICODE);
            
            // Benzersiz anahtar oluştur (name + offerFromCode + offerToCode kombinasyonu)
            $uniqueKey = $name . '_' . $offerFromCode . '_' . $offerToCode;
            
            // Mevcut kaydı kontrol et (benzersiz anahtar ile)
            $checkSql = "SELECT API_GetNeoCampaignList_ID FROM dbo.API_GetNeoCampaignList 
                        WHERE API_GetNeoCampaignList_name = ? 
                        AND API_GetNeoCampaignList_offerFromCode = ? 
                        AND API_GetNeoCampaignList_offerToCode = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->execute([$name, $offerFromCode, $offerToCode]);
            $existingRecord = $checkStmt->fetch();
            
            if ($existingRecord) {
                // Güncelle - mevcut kayıtlarda durum alanı değişmesin
                $updateSql = "UPDATE dbo.API_GetNeoCampaignList SET 
                             API_GetNeoCampaignList_description = ?,
                             API_GetNeoCampaignList_billFrequency = ?,
                             API_GetNeoCampaignList_billFrequencyTypeCd = ?,
                             API_GetNeoCampaignList_currencyTypeCd = ?,
                             API_GetNeoCampaignList_offerFromId = ?,
                             API_GetNeoCampaignList_offerToId = ?,
                             API_GetNeoCampaignList_priceAmount = ?,
                             API_GetNeoCampaignList_Fiyat = ?,
                             API_GetNeoCampaignList_guncelleme_tarihi = GETDATE()
                             WHERE API_GetNeoCampaignList_name = ? 
                             AND API_GetNeoCampaignList_offerFromCode = ? 
                             AND API_GetNeoCampaignList_offerToCode = ?";
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
                $insertSql = "INSERT INTO dbo.API_GetNeoCampaignList 
                             (API_GetNeoCampaignList_name, API_GetNeoCampaignList_description,
                              API_GetNeoCampaignList_billFrequency, API_GetNeoCampaignList_billFrequencyTypeCd,
                              API_GetNeoCampaignList_currencyTypeCd, API_GetNeoCampaignList_offerFromCode,
                              API_GetNeoCampaignList_offerFromId, API_GetNeoCampaignList_offerToCode,
                              API_GetNeoCampaignList_offerToId, API_GetNeoCampaignList_priceAmount,
                              API_GetNeoCampaignList_Fiyat, API_GetNeoCampaignList_PaketAdi,
                              API_GetNeoCampaignList_durum, API_GetNeoCampaignList_olusturma_tarih) 
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
        }
        
        if ($debug) {
            $debugInfo['summary'] = [
                'total_processed' => $processedCount,
                'inserted' => $insertedCount,
                'updated' => $updatedCount
            ];
            $_SESSION['neo_debug'] = $debugInfo;
        }
        
        return true;
        
    } catch (Exception $e) {
        if ($debug) {
            $debugInfo['error'] = 'Database hatası: ' . $e->getMessage();
            $_SESSION['neo_debug'] = $debugInfo;
        }
        error_log("Neo kampanya parse hatası: " . $e->getMessage());
        return false;
    }
}

// API'den veri güncelleme işlemi
if (isset($_GET['refresh_api']) && $_GET['refresh_api'] == '1') {
    try {
        // Neo Campaign API'den veri çek (ID 13 varsayıyoruz)
        $apiNeoData = fetchApiData(13, $conn);
        if ($apiNeoData) {
            $parseResult = parseAndSaveNeoData($apiNeoData, $conn);
            if ($parseResult) {
                // Debug bilgilerinden özet mesaj oluştur
                if (isset($_SESSION['neo_debug']['summary'])) {
                    $summary = $_SESSION['neo_debug']['summary'];
                    $message = sprintf(
                        'Neo kampanya verileri güncellendi! Toplam İşlenen: %d, Yeni Eklenen: %d, Güncellenen: %d',
                        $summary['total_processed'] ?? 0,
                        $summary['inserted'] ?? 0,
                        $summary['updated'] ?? 0
                    );
                } else {
                    $message = 'Neo kampanya verileri başarıyla güncellendi!';
                }
                $messageType = 'success';
            } else {
                $message = 'Neo kampanya verileri güncellenirken hata oluştu!';
                $messageType = 'danger';
            }
        } else {
            $message = 'API\'den Neo kampanya verileri alınamadı!';
            $messageType = 'warning';
        }
    } catch (Exception $e) {
        $message = 'API güncelleme hatası: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Veritabanından verileri çek
try {
    // Neo kampanya verileri
    $neoSql = "SELECT * FROM dbo.API_GetNeoCampaignList ORDER BY API_GetNeoCampaignList_ID ASC";
    $neoStmt = $conn->prepare($neoSql);
    $neoStmt->execute();
    $neoVerileri = $neoStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ödeme türü verileri
    $odemeTuruSql = "SELECT API_odeme_turu_ID, API_odeme_turu_ad FROM dbo.API_odeme_turu ORDER BY API_odeme_turu_ad ASC";
    $odemeTuruStmt = $conn->prepare($odemeTuruSql);
    $odemeTuruStmt->execute();
    $odemeTurleri = $odemeTuruStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata: ' . $e->getMessage();
    $messageType = 'danger';
}

// KÖİ güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_koi') {
    try {
        $conn = getDatabaseConnection();
        $koiValue = $_POST['koi'] === 'true' ? 1 : 0;
        $sql = "UPDATE dbo.API_GetNeoCampaignList SET API_GetNeoCampaignList_KOI = ?, API_GetNeoCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetNeoCampaignList_ID = ?";
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
        $sql = "UPDATE dbo.API_GetNeoCampaignList SET API_GetNeoCampaignList_durum = ?, API_GetNeoCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetNeoCampaignList_ID = ?";
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
            'PaketAdi' => 'API_GetNeoCampaignList_PaketAdi',
            'Fiyat' => 'API_GetNeoCampaignList_Fiyat',
            'Hediye' => 'API_GetNeoCampaignList_Hediye',
            'odeme_turu_id' => 'API_GetNeoCampaignList_odeme_turu_id'
        ];
        
        $field = $_POST['field'];
        $value = $_POST['value'];
        $id = $_POST['id'];
        
        if (!array_key_exists($field, $fieldMapping)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz alan adı']);
            exit;
        }
        
        $dbField = $fieldMapping[$field];
        $sql = "UPDATE dbo.API_GetNeoCampaignList SET [$dbField] = ?, API_GetNeoCampaignList_guncelleme_tarihi = GETDATE() WHERE API_GetNeoCampaignList_ID = ?";
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
            $result = parseAndSaveNeoData($apiData);
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

<!-- DataTables CSS ve JS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="<?php echo htmlspecialchars($pageIcon); ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h2>
                <div>
                    <a href="?refresh_api=1" class="btn btn-info btn-sm">
                        <i class="fas fa-sync me-1"></i>API'den Güncelle
                    </a>
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
                                <?php foreach ($odemeTurleri as $odemeTuru): ?>
                                    <option value="<?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>">
                                        <?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>
                                    </option>
                                <?php endforeach; ?>
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
                            <table class="table table-hover" id="neoTable">
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
                                    <?php if (isset($neoVerileri) && !empty($neoVerileri)): ?>
                                        <?php foreach ($neoVerileri as $neo): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($neo['API_GetNeoCampaignList_ID']); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(substr($neo['API_GetNeoCampaignList_name'], 0, 4)); ?></strong>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $aciklama = $neo['API_GetNeoCampaignList_description'] ?? '';
                                                        echo htmlspecialchars(strlen($aciklama) > 50 ? substr($aciklama, 0, 50) . '...' : $aciklama); 
                                                        ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($neo['API_GetNeoCampaignList_Fiyat']): ?>
                                                        <strong><?php echo number_format($neo['API_GetNeoCampaignList_Fiyat'], 2); ?> TL</strong>
                                                    <?php else: ?>
                                                        <strong><?php echo number_format($neo['API_GetNeoCampaignList_priceAmount'], 2); ?> TL</strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $isDurumAktif = isset($neo['API_GetNeoCampaignList_durum']) && $neo['API_GetNeoCampaignList_durum'] == 1;
                                                    $isOdemeTuruNull = empty($neo['API_GetNeoCampaignList_odeme_turu_id']);
                                                    $requiresWarning = $isDurumAktif && $isOdemeTuruNull;
                                                    ?>
                                                    <select class="form-select form-select-sm auto-save-select <?php echo $requiresWarning ? 'border-danger' : ''; ?>" 
                                                            data-field="odeme_turu_id" 
                                                            data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                            <?php echo $requiresWarning ? 'title="⚠️ Durum aktif ama ödeme türü seçilmemiş!"' : ''; ?>>
                                                        <option value=""><?php echo $requiresWarning ? '⚠️ SEÇİNİZ (ZORUNLU)' : 'Seçiniz...'; ?></option>
                                                        <?php foreach ($odemeTurleri as $odemeTuru): ?>
                                                            <option value="<?php echo $odemeTuru['API_odeme_turu_ID']; ?>"
                                                                    <?php echo ($neo['API_GetNeoCampaignList_odeme_turu_id'] == $odemeTuru['API_odeme_turu_ID']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($odemeTuru['API_odeme_turu_ad']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
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
                                                           data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                           value="<?php echo htmlspecialchars($neo['API_GetNeoCampaignList_PaketAdi'] ?? ''); ?>"
                                                           placeholder="Paket adı...">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm auto-save-input" 
                                                           data-field="Fiyat" 
                                                           data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                           value="<?php echo htmlspecialchars($neo['API_GetNeoCampaignList_Fiyat'] ?? ''); ?>"
                                                           placeholder="Fiyat..."
                                                           step="0.01">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm auto-save-input" 
                                                           data-field="Hediye" 
                                                           data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                           value="<?php echo htmlspecialchars($neo['API_GetNeoCampaignList_Hediye'] ?? ''); ?>"
                                                           placeholder="Hediye...">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input koi-checkbox" type="checkbox" 
                                                               data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                               <?php echo $neo['API_GetNeoCampaignList_KOI'] ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input durum-checkbox" type="checkbox" 
                                                               data-id="<?php echo $neo['API_GetNeoCampaignList_ID']; ?>"
                                                               <?php echo $neo['API_GetNeoCampaignList_durum'] ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td><?php echo $neo['API_GetNeoCampaignList_guncelleme_tarihi'] ? date('d.m.Y H:i', strtotime($neo['API_GetNeoCampaignList_guncelleme_tarihi'])) : '-'; ?></td>
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
// DataTable başlatma
$(document).ready(function() {
    var table = $('#neoTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Turkish.json"
        },
        "pageLength": 25,
        "lengthMenu": [10, 25, 50, 100],
        "order": [[0, "desc"]],
        "columnDefs": [
            { "orderable": false, "targets": [8, 9] }, // KÖİ ve Durum checkbox columns
            { "orderable": true, "targets": [0, 1, 2, 3, 4, 5, 6, 7, 10] } // Diğer sütunlar sıralanabilir
        ],
        "ordering": true, // Sıralama özelliğini açık tut
        "info": true,
        "paging": true,
        "searching": true
    });
    
    // Filtreleme fonksiyonları
    function applyFilters() {
        // Text input filtreleri
        table.column(1).search($('#filter-kod').val());
        table.column(2).search($('#filter-aciklama').val());
        table.column(3).search($('#filter-fiyat').val());
        table.column(4).search($('#filter-odeme-turu').val(), false, false);
        table.column(5).search($('#filter-paket').val());
        
        // Checkbox filtreleri
        applyCheckboxFilters();
        
        table.draw();
    }
    
    // KÖİ ve Durum için özel filtreleme
    function applyCheckboxFilters() {
        var koiFilter = $('#filter-koi').val();
        var durumFilter = $('#filter-durum').val();
        
        // Önceki özel aramayı temizle
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('neoTable') === -1;
        });
        
        if (koiFilter || durumFilter) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'neoTable') {
                    return true;
                }
                
                var row = table.row(dataIndex).node();
                
                // KÖİ filtreleme
                if (koiFilter) {
                    var koiCheckbox = $(row).find('td:eq(8) input[type="checkbox"]');
                    if (koiFilter === 'checked' && !koiCheckbox.is(':checked')) {
                        return false;
                    }
                    if (koiFilter === 'unchecked' && koiCheckbox.is(':checked')) {
                        return false;
                    }
                }
                
                // Durum filtreleme
                if (durumFilter) {
                    var durumCheckbox = $(row).find('td:eq(9) input[type="checkbox"]');
                    if (durumFilter === 'checked' && !durumCheckbox.is(':checked')) {
                        return false;
                    }
                    if (durumFilter === 'unchecked' && durumCheckbox.is(':checked')) {
                        return false;
                    }
                }
                
                return true;
            });
        }
    }
    
    // Filtreleri temizle
    function clearFilters() {
        $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').val('');
        $('#filter-odeme-turu, #filter-koi, #filter-durum').val('');
        
        // DataTable aramalarını temizle
        table.search('').columns().search('').draw();
        
        // Özel aramayı temizle
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('neoTable') === -1;
        });
        
        table.draw();
    }
    
    // Enter tuşuna basınca filtrele
    $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').on('keypress', function(e) {
        if (e.which === 13) {
            applyFilters();
        }
    });
    
    // Filtreleri temizleme fonksiyonu
    window.clearFilters = function() {
        $('#filter-kod, #filter-aciklama, #filter-fiyat, #filter-paket').val('');
        $('#filter-odeme-turu, #filter-koi, #filter-durum').val('');
        
        table.search('').columns().search('').draw();
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('neoTable') === -1;
        });
        table.draw();
    };
    
    // Filtreleme fonksiyonu
    window.applyFilters = function() {
        // Text input filtreleri
        table.column(1).search($('#filter-kod').val());
        table.column(2).search($('#filter-aciklama').val());
        table.column(3).search($('#filter-fiyat').val());
        table.column(4).search($('#filter-odeme-turu').val(), false, false);
        table.column(5).search($('#filter-paket').val());
        
        // Checkbox filtreleri
        applyCheckboxFilters();
        
        table.draw();
    };
    
    // KÖİ ve Durum için özel filtreleme
    function applyCheckboxFilters() {
        var koiFilter = $('#filter-koi').val();
        var durumFilter = $('#filter-durum').val();
        
        // Önceki özel aramayı temizle
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('neoTable') === -1;
        });
        
        if (koiFilter || durumFilter) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'neoTable') {
                    return true;
                }
                
                var row = table.row(dataIndex).node();
                
                // KÖİ filtreleme
                if (koiFilter) {
                    var koiCheckbox = $(row).find('td:eq(8) input[type="checkbox"]');
                    if (koiFilter === 'checked' && !koiCheckbox.is(':checked')) {
                        return false;
                    }
                    if (koiFilter === 'unchecked' && koiCheckbox.is(':checked')) {
                        return false;
                    }
                }
                
                // Durum filtreleme
                if (durumFilter) {
                    var durumCheckbox = $(row).find('td:eq(9) input[type="checkbox"]');
                    if (durumFilter === 'checked' && !durumCheckbox.is(':checked')) {
                        return false;
                    }
                    if (durumFilter === 'unchecked' && durumCheckbox.is(':checked')) {
                        return false;
                    }
                }
                
                return true;
            });
        }
    }
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
    
    // 3 saniye sonra otomatik kapat
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 3000);
}
</script>

<?php include '../../../includes/footer.php'; ?>