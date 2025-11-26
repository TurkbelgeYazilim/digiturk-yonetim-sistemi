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
    error_log("Sayfa bilgileri alınamadı: " . $e->getMessage());
}

// Varsayılan değerler (eğer veritabanından alınamazsa)
$pageTitle = $sayfaBilgileri['sayfa_adi'] ?? "Başvuru Durum ve Kimlik Türü Yönetimi";
$pageIcon = $sayfaBilgileri['sayfa_icon'] ?? "fas fa-file";
$pageDescription = $sayfaBilgileri['sayfa_aciklama'] ?? "";

$breadcrumbs = [
    ['title' => $pageTitle]
];

// Sayfa yetkilerini kontrol et
$pagePermissions = checkPagePermission($currentPageUrl);
if (!$pagePermissions) {
    header('Location: /index.php');
    exit;
}

$message = '';
$messageType = '';

// API bilgilerini al
try {
    $conn = getDatabaseConnection();
    
    // API URL'lerini al
    $apiSql = "SELECT api_iris_Address_ID, api_iris_Address_URL FROM dbo.API_Link WHERE api_iris_Address_ID IN (11, 12)";
    $apiStmt = $conn->prepare($apiSql);
    $apiStmt->execute();
    $apiLinks = $apiStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $durumApiUrl = '';
    $kimlikApiUrl = '';
    
    foreach ($apiLinks as $link) {
        if ($link['api_iris_Address_ID'] == 12) {
            $durumApiUrl = $link['api_iris_Address_URL'];
        } elseif ($link['api_iris_Address_ID'] == 11) {
            $kimlikApiUrl = $link['api_iris_Address_URL'];
        }
    }
    
    // API Token al
    $tokenSql = "SELECT api_iris_kullanici_token FROM dbo.API_kullanici WHERE api_iris_kullanici_ID = 1";
    $tokenStmt = $conn->prepare($tokenSql);
    $tokenStmt->execute();
    $tokenResult = $tokenStmt->fetch(PDO::FETCH_ASSOC);
    $apiToken = $tokenResult['api_iris_kullanici_token'] ?? '';
    
} catch (Exception $e) {
    // API bilgileri alınamadı, hata loglanabilir
    error_log("API bilgileri alınamadı: " . $e->getMessage());
}

// API'den kimlik türü verilerini parse etme fonksiyonu
function parseAndSaveKimlikData($apiData, $conn) {
    if (!isset($apiData['data']) || !is_array($apiData['data'])) {
        return false;
    }
    
    try {
        foreach ($apiData['data'] as $item) {
            // Key-value pair formatında parse et
            if (!is_array($item) || empty($item)) {
                continue;
            }
            
            // Her item'daki key-value pair'leri işle
            foreach ($item as $cardCode => $cardName) {
                $rawResponse = json_encode([$cardCode => $cardName], JSON_UNESCAPED_UNICODE);
                
                // Mevcut kaydı kontrol et (card_code ile)
                $checkSql = "SELECT API_GetCardTypeList_ID FROM dbo.API_GetCardTypeList WHERE API_GetCardTypeList_card_code = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$cardCode]);
                $existingRecord = $checkStmt->fetch();
                
                if ($existingRecord) {
                    // Güncelle
                    $updateSql = "UPDATE dbo.API_GetCardTypeList SET 
                                 API_GetCardTypeList_card_name = ?, 
                                 API_GetCardTypeList_raw_response = ?,
                                 API_GetCardTypeList_guncelleme_tarihi = GETDATE()
                                 WHERE API_GetCardTypeList_card_code = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$cardName, $rawResponse, $cardCode]);
                } else {
                    // Yeni kayıt ekle
                    $insertSql = "INSERT INTO dbo.API_GetCardTypeList 
                                 (API_GetCardTypeList_card_code, API_GetCardTypeList_card_name, 
                                  API_GetCardTypeList_raw_response, API_GetCardTypeList_olusturma_tarih) 
                                 VALUES (?, ?, ?, GETDATE())";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->execute([$cardCode, $cardName, $rawResponse]);
                }
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Kimlik türü parse hatası: " . $e->getMessage());
        return false;
    }
}

// API'den başvuru durum verilerini parse etme fonksiyonu
function parseAndSaveDurumData($apiData, $conn) {
    if (!isset($apiData['data']) || !is_array($apiData['data'])) {
        return false;
    }
    
    try {
        foreach ($apiData['data'] as $item) {
            // Gerekli alanları kontrol et
            if (!isset($item['id'], $item['title'])) {
                continue;
            }
            
            $apiId = $item['id'];
            $title = $item['title'];
            $description = $item['description'] ?? '';
            
            // Mevcut kaydı kontrol et (API ID ile)
            $checkSql = "SELECT API_basvuru_durum_ID FROM dbo.API_basvuruDurum WHERE API_basvuru_durum_ID = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->execute([$apiId]);
            $existingRecord = $checkStmt->fetch();
            
            if ($existingRecord) {
                // Güncelle
                $updateSql = "UPDATE dbo.API_basvuruDurum SET 
                             API_basvuru_durum_Mesaj = ?,
                             API_basvuru_durum_Aciklama = ?,
                             API_basvuru_durum_guncelleme_tarihi = GETDATE()
                             WHERE API_basvuru_durum_ID = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$title, $description, $apiId]);
            } else {
                // Yeni kayıt ekle (API ID ile)
                $insertSql = "INSERT INTO dbo.API_basvuruDurum 
                             (API_basvuru_durum_ID, API_basvuru_durum_Mesaj, API_basvuru_durum_Aciklama, 
                              API_basvuru_durum_renk, API_basvuru_durum_durum, API_basvuru_durum_olusturma_tarih) 
                             VALUES (?, ?, ?, '#6c757d', 1, GETDATE())";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->execute([$apiId, $title, $description]);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Başvuru durum parse hatası: " . $e->getMessage());
        return false;
    }
}
function fetchApiData($url, $token) {
    if (empty($url) || empty($token)) {
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Token: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError || $httpCode !== 200) {
        return false;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    
    return $data;
}

// API'den verileri çek
$durumVerileri = [];
$kimlikVerileri = [];

// Manuel yenileme kontrolü
if (isset($_GET['refresh_api'])) {
    // Başvuru Durum verilerini API'den çek
    if (!empty($durumApiUrl) && !empty($apiToken)) {
        $apiDurumData = fetchApiData($durumApiUrl, $apiToken);
        if ($apiDurumData) {
            // API'den gelen veriyi parse et ve veritabanına kaydet
            $parseResult = parseAndSaveDurumData($apiDurumData, $conn);
            if ($parseResult) {
                $message = 'Başvuru durum verileri API\'den başarıyla güncellendi.';
                $messageType = 'success';
            } else {
                $message = 'API verisi alındı ancak parse edilirken hata oluştu.';
                $messageType = 'warning';
            }
        } else {
            $message = 'Başvuru durum API\'sinden veri alınamadı.';
            $messageType = 'danger';
        }
    }
    
    // Kimlik Türü verilerini API'den çek
    if (!empty($kimlikApiUrl) && !empty($apiToken)) {
        $apiKimlikData = fetchApiData($kimlikApiUrl, $apiToken);
        if ($apiKimlikData) {
            // API'den gelen veriyi parse et ve veritabanına kaydet
            $parseResult = parseAndSaveKimlikData($apiKimlikData, $conn);
            if ($parseResult) {
                if (!$message) { // Eğer başvuru durum mesajı yoksa
                    $message = 'Kimlik türü verileri API\'den başarıyla güncellendi.';
                    $messageType = 'success';
                } else {
                    $message .= ' Kimlik türü verileri de güncellendi.';
                }
            } else {
                if (!$message) {
                    $message = 'Kimlik türü API verisi alındı ancak parse edilirken hata oluştu.';
                    $messageType = 'warning';
                }
            }
        } else {
            if (!$message) {
                $message = 'Kimlik türü API\'sinden veri alınamadı.';
                $messageType = 'danger';
            }
        }
    }
}

// Veritabanından verileri çek
try {
    // Başvuru durum verileri
    $durumSql = "SELECT * FROM dbo.API_basvuruDurum ORDER BY API_basvuru_durum_ID ASC";
    $durumStmt = $conn->prepare($durumSql);
    $durumStmt->execute();
    $durumVerileri = $durumStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kimlik türü verileri
    $kimlikSql = "SELECT * FROM dbo.API_GetCardTypeList ORDER BY API_GetCardTypeList_ID ASC";
    $kimlikStmt = $conn->prepare($kimlikSql);
    $kimlikStmt->execute();
    $kimlikVerileri = $kimlikStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata: ' . $e->getMessage();
    $messageType = 'danger';
}

// Renk güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_color') {
    try {
        $conn = getDatabaseConnection();
        $sql = "UPDATE dbo.API_basvuruDurum SET API_basvuru_durum_renk = ?, API_basvuru_durum_guncelleme_tarihi = GETDATE() WHERE API_basvuru_durum_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_POST['color'], $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Renk başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Form işlemleri - API'den gelen veriler için bu kısım artık kullanılmıyor

include '../../../includes/header.php';
?>

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
            <!-- Nav tabs -->
            <ul class="nav nav-tabs" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="durum-tab" data-bs-toggle="tab" data-bs-target="#durum" type="button" role="tab" aria-controls="durum" aria-selected="true">
                        <i class="fas fa-tasks me-1"></i>Başvuru Durum Yönetimi
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="kimlik-tab" data-bs-toggle="tab" data-bs-target="#kimlik" type="button" role="tab" aria-controls="kimlik" aria-selected="false">
                        <i class="fas fa-id-card me-1"></i>Kimlik Türü Yönetimi
                    </button>
                </li>
            </ul>

            <!-- Tab panes -->
            <div class="tab-content" id="myTabContent">
                <!-- Başvuru Durum Tab -->
                <div class="tab-pane fade show active" id="durum" role="tabpanel" aria-labelledby="durum-tab">
                    <div class="mt-3">
                        <div class="mb-3">
                            <h6>
                                <i class="fas fa-tasks me-2"></i>Başvuru Durum Listesi
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-sync me-1"></i>API Entegreli
                                </span>
                            </h6>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover" id="durumTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Mesaj</th>
                                        <th>Açıklama</th>
                                        <th>Renk</th>
                                        <th>Durum</th>
                                        <th>Güncelleme Tarihi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (isset($durumVerileri) && !empty($durumVerileri)): ?>
                                        <?php foreach ($durumVerileri as $durum): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($durum['API_basvuru_durum_ID']); ?></td>
                                                <td><?php echo htmlspecialchars($durum['API_basvuru_durum_Mesaj']); ?></td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $aciklama = $durum['API_basvuru_durum_Aciklama'] ?? '';
                                                        echo htmlspecialchars(strlen($aciklama) > 50 ? substr($aciklama, 0, 50) . '...' : $aciklama); 
                                                        ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <input type="color" 
                                                           class="form-control form-control-color renk-editor" 
                                                           value="<?php echo htmlspecialchars($durum['API_basvuru_durum_renk']); ?>"
                                                           data-id="<?php echo $durum['API_basvuru_durum_ID']; ?>"
                                                           data-original-color="<?php echo htmlspecialchars($durum['API_basvuru_durum_renk']); ?>"
                                                           style="width: 60px; height: 35px; border: none; cursor: pointer;"
                                                           title="Rengi değiştirmek için tıklayın">
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $durum['API_basvuru_durum_durum'] ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $durum['API_basvuru_durum_durum'] ? 'Aktif' : 'Pasif'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $durum['API_basvuru_durum_guncelleme_tarihi'] ? date('d.m.Y H:i', strtotime($durum['API_basvuru_durum_guncelleme_tarihi'])) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">Kayıt bulunamadı</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Kimlik Türü Tab -->
                <div class="tab-pane fade" id="kimlik" role="tabpanel" aria-labelledby="kimlik-tab">
                    <div class="mt-3">
                        <div class="mb-3">
                            <h6>
                                <i class="fas fa-id-card me-2"></i>Kimlik Türü Listesi
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-sync me-1"></i>API Entegreli
                                </span>
                            </h6>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover" id="kimlikTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Kart Kodu</th>
                                        <th>Kart Adı</th>
                                        <th>Raw Response</th>
                                        <th>Güncelleme Tarihi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (isset($kimlikVerileri) && !empty($kimlikVerileri)): ?>
                                        <?php foreach ($kimlikVerileri as $kimlik): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($kimlik['API_GetCardTypeList_ID']); ?></td>
                                                <td>
                                                    <code class="text-primary"><?php echo htmlspecialchars($kimlik['API_GetCardTypeList_card_code']); ?></code>
                                                </td>
                                                <td><?php echo htmlspecialchars($kimlik['API_GetCardTypeList_card_name']); ?></td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?php 
                                                        $rawResponse = $kimlik['API_GetCardTypeList_raw_response'] ?? '';
                                                        echo htmlspecialchars(strlen($rawResponse) > 30 ? substr($rawResponse, 0, 30) . '...' : $rawResponse); 
                                                        ?>
                                                    </small>
                                                </td>
                                                <td><?php echo $kimlik['API_GetCardTypeList_guncelleme_tarihi'] ? date('d.m.Y H:i', strtotime($kimlik['API_GetCardTypeList_guncelleme_tarihi'])) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">Kayıt bulunamadı</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// DataTable başlatma
$(document).ready(function() {
    $('#durumTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Turkish.json"
        },
        "pageLength": 25,
        "lengthMenu": [10, 25, 50, 100],
        "order": [[0, "asc"]]
    });
    
    $('#kimlikTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Turkish.json"
        },
        "pageLength": 25,
        "lengthMenu": [10, 25, 50, 100],
        "order": [[0, "asc"]]
    });
});

// Renk değiştirme fonksiyonu
$(document).on('change', '.renk-editor', function() {
    const id = $(this).data('id');
    const newColor = $(this).val();
    const $this = $(this);
    
    // Loading durumu
    $this.prop('disabled', true);
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: {
            action: 'update_color',
            id: id,
            color: newColor
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Başarılı mesajı göster
                showMessage('success', response.message);
            } else {
                // Hata durumunda eski renge geri döndür
                $this.val($this.data('original-color'));
                showMessage('danger', response.message);
            }
        },
        error: function() {
            // Hata durumunda eski renge geri döndür
            $this.val($this.data('original-color'));
            showMessage('danger', 'Renk güncellenirken bir hata oluştu.');
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