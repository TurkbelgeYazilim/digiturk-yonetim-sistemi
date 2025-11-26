<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = "Adres ve KÖİ Yönetimi";
$breadcrumbs = [
    ['title' => 'Adres ve KÖİ Yönetimi']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// Sayfa yetkilendirme kontrolü
$sayfaYetkileri = [
    'gor' => false,
    'kendi_kullanicini_gor' => false,
    'ekle' => false,
    'duzenle' => false,
    'sil' => false
];

// Admin kontrolü (group_id = 1 ise tüm yetkilere sahip)
$isAdmin = ($currentUser['group_id'] == 1);

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
            
            // Görme yetkisi yoksa (0 ise) sayfaya erişimi engelle
            if ($sayfaYetkileri['gor'] == 0) {
                header('Location: ../../../index.php?error=yetki_yok');
                exit;
            }
        } else {
            // Yetki tanımı bulunamazsa erişimi engelle
            header('Location: ../../../index.php?error=yetki_tanimlanmamis');
            exit;
        }
        
    } catch (Exception $e) {
        // Hata durumunda güvenlik için erişimi engelle
        error_log("Yetki kontrol hatası: " . $e->getMessage());
        header('Location: ../../../index.php?error=sistem_hatasi');
        exit;
    }
}

$message = '';
$messageType = '';

// Şehir işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_koi':
                // KÖİ güncelleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'KÖİ güncelleme yetkiniz bulunmamaktadır.']);
                    exit;
                }
                
                $id = (int)$_POST['id'];
                $koi = isset($_POST['koi']) && $_POST['koi'] == '1' ? 1 : 0;
                
                if ($id <= 0) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Geçersiz ID.']);
                    exit;
                }
                
                $sql = "UPDATE dbo.adres_iller SET 
                        adres_iller_KOI = ?, 
                        adres_iller_guncelleme_tarihi = GETDATE() 
                        WHERE adres_iller_ID = ?";
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute([$koi, $id]);
                
                header('Content-Type: application/json');
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'KÖİ durumu başarıyla güncellendi.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Güncelleme sırasında hata oluştu.']);
                }
                exit;
                break;
                
            case 'api_guncelle':
                // API çağrısı ile şehir bilgilerini güncelle
                if ($sayfaYetkileri['duzenle'] != 1) {
                    $message = 'API güncelleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                // API Link bilgisini al (ID=2)
                $addressSql = "SELECT api_iris_Address_URL FROM dbo.API_Link WHERE api_iris_Address_ID = 2";
                $addressStmt = $conn->prepare($addressSql);
                $addressStmt->execute();
                $addressData = $addressStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$addressData || empty($addressData['api_iris_Address_URL'])) {
                    throw new Exception('API adresi bulunamadı. Lütfen API adres ayarlarını kontrol edin. (API_Link tablosunda ID=2 kaydı olmalı)');
                }
                
                $apiUrl = trim($addressData['api_iris_Address_URL']);
                
                // URL formatını kontrol et
                if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                    throw new Exception('Geçersiz API URL formatı: ' . $apiUrl . ' (API_Link tablosunda düzeltin)');
                }
                
                // API Kullanıcı token bilgisini al (ID=1)
                $tokenSql = "SELECT api_iris_kullanici_token FROM dbo.API_kullanici WHERE api_iris_kullanici_ID = 1";
                $tokenStmt = $conn->prepare($tokenSql);
                $tokenStmt->execute();
                $tokenData = $tokenStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$tokenData || empty($tokenData['api_iris_kullanici_token'])) {
                    throw new Exception('API token bulunamadı. Lütfen önce token yönetimi sayfasından token oluşturun. (API_kullanici tablosunda ID=1 kaydı olmalı)');
                }
                
                $token = trim($tokenData['api_iris_kullanici_token']);
                
                if (empty($token)) {
                    throw new Exception('API token boş. Lütfen token yönetimi sayfasından token yenileyin.');
                }
                
                // API çağrısı için veri hazırla
                $postData = [
                    'code' => 0
                ];
                
                // cURL başlamadan önce son kontroller
                if (empty($apiUrl) || empty($token)) {
                    throw new Exception('API URL veya Token eksik. URL: ' . $apiUrl . ', Token length: ' . strlen($token));
                }
                
                // cURL ile API çağrısı yap
                $ch = curl_init();
                
                if ($ch === false) {
                    throw new Exception('cURL başlatılamadı');
                }
                
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Token: ' . $token
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'DigiturkAPI/1.0');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrorNumber = curl_errno($ch);
                $curlInfo = curl_getinfo($ch);
                curl_close($ch);
                
                // cURL hata kontrolü
                if ($curlError) {
                    $errorMsg = 'API çağrısı hatası: ' . $curlError;
                    if ($curlErrorNumber) {
                        $errorMsg .= ' (Error #' . $curlErrorNumber . ')';
                    }
                    $errorMsg .= ' - URL: ' . $apiUrl;
                    throw new Exception($errorMsg);
                }
                
                // Response kontrolü
                if ($response === false) {
                    throw new Exception('API\'den yanıt alınamadı. URL: ' . $apiUrl);
                }
                
                if ($httpCode !== 200) {
                    throw new Exception('API yanıt hatası: HTTP ' . $httpCode . ' - ' . substr($response, 0, 100));
                }
                
                // API yanıtını decode et
                $apiResponse = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('API yanıtı geçersiz JSON formatında: ' . json_last_error_msg());
                }
                
                // Şehir verilerini işle ve veritabanına kaydet
                $addedCount = 0;
                $updatedCount = 0;
                
                // API yanıtında data array'i var mı kontrol et
                if (is_array($apiResponse) && isset($apiResponse['data']) && is_array($apiResponse['data'])) {
                    $cities = $apiResponse['data'];
                } elseif (is_array($apiResponse)) {
                    // Eğer doğrudan array geliyorsa
                    $cities = $apiResponse;
                } else {
                    throw new Exception('API yanıtında şehir verisi bulunamadı.');
                }
                
                if (!empty($cities)) {
                    foreach ($cities as $city) {
                        if (isset($city['code']) && isset($city['name'])) {
                            $cityCode = (int)$city['code'];
                            $cityName = trim($city['name']);
                            
                            if ($cityCode > 0 && !empty($cityName)) {
                                // Şehir zaten var mı kontrol et
                                $checkSql = "SELECT adres_iller_ID FROM dbo.adres_iller WHERE adres_iller_code = ?";
                                $checkStmt = $conn->prepare($checkSql);
                                $checkStmt->execute([$cityCode]);
                                
                                if ($checkStmt->fetch()) {
                                    // Güncelle
                                    $updateSql = "UPDATE dbo.adres_iller SET 
                                                  adres_iller_name = ?, 
                                                  adres_iller_raw_response = ?, 
                                                  adres_iller_guncelleme_tarihi = GETDATE() 
                                                  WHERE adres_iller_code = ?";
                                    $updateStmt = $conn->prepare($updateSql);
                                    $updateStmt->execute([$cityName, json_encode($city), $cityCode]);
                                    $updatedCount++;
                                } else {
                                    // Ekle
                                    $insertSql = "INSERT INTO dbo.adres_iller (adres_iller_name, adres_iller_code, adres_iller_raw_response, adres_iller_KOI, adres_iller_durum) 
                                                  VALUES (?, ?, ?, 1, 1)";
                                    $insertStmt = $conn->prepare($insertSql);
                                    $insertStmt->execute([$cityName, $cityCode, json_encode($city)]);
                                    $addedCount++;
                                }
                            }
                        }
                    }
                }
                
                $message = "API'den şehir bilgileri başarıyla güncellendi. Eklenen: {$addedCount}, Güncellenen: {$updatedCount}";
                $messageType = 'success';
                break;
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'danger';
                break;
        }
    } catch (Exception $e) {
        error_log("POST operation error: " . $e->getMessage());
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Şehirleri listele
$sehirler = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT 
                adres_iller_ID,
                adres_iller_name,
                adres_iller_code,
                adres_iller_KOI,
                adres_iller_durum,
                adres_iller_guncelleme_tarihi
            FROM dbo.adres_iller
            ORDER BY adres_iller_code ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    $sehirler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching cities: " . $e->getMessage());
    $error = "Veritabanı hatası: " . $e->getMessage();
}

try {
    include '../../../includes/header.php';
} catch (Exception $e) {
    die("Header dosyası yüklenirken hata: " . $e->getMessage());
}

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-city me-2"></i>Adres ve KÖİ Yönetimi</h2>
                <div>
                    <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                    <button class="btn btn-success" onclick="apiGuncelle()">
                        <i class="fas fa-sync me-1"></i>API'den Güncelle
                    </button>
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

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtreleme -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreleme
            </h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <label for="filterName" class="form-label small">Şehir Adı</label>
                    <select class="form-select form-select-sm" id="filterName" data-placeholder="Şehir seçin...">
                        <option value="">Tümü</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filterCode" class="form-label small">Kod</label>
                    <select class="form-select form-select-sm" id="filterCode" data-placeholder="Kod seçin...">
                        <option value="">Tümü</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filterKOI" class="form-label small">KÖİ Durumu</label>
                    <select class="form-select form-select-sm" id="filterKOI">
                        <option value="">Tümü</option>
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div class="col-md-5 d-flex align-items-end">
                    <button type="button" class="btn btn-primary btn-sm me-2" onclick="applyFilters()">
                        <i class="fas fa-search me-1"></i>Filtrele
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                        <i class="fas fa-times me-1"></i>Temizle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Şehirler Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Şehir Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="sehirlerTable">
                    <thead class="table-light">
                        <tr>
                            <th onclick="sortTable(0)" style="cursor: pointer;">
                                ID <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable(1)" style="cursor: pointer;">
                                Şehir Adı <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable(2)" style="cursor: pointer;">
                                Kod <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable(3)" style="cursor: pointer;">
                                KÖİ <i class="fas fa-sort"></i>
                            </th>
                            <th onclick="sortTable(4)" style="cursor: pointer;">
                                Güncelleme Tarihi <i class="fas fa-sort"></i>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="sehirlerTableBody">
                        <?php if (!empty($sehirler)): ?>
                            <?php foreach ($sehirler as $sehir): ?>
                                <tr>
                                    <td><?php echo $sehir['adres_iller_ID']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($sehir['adres_iller_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo $sehir['adres_iller_code']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <input type="checkbox" onchange="updateKOI(<?php echo $sehir['adres_iller_ID']; ?>, this.checked)" <?php echo $sehir['adres_iller_KOI'] ? 'checked' : ''; ?>>
                                        <?php else: ?>
                                            <input type="checkbox" disabled <?php echo $sehir['adres_iller_KOI'] ? 'checked' : ''; ?>>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $sehir['adres_iller_guncelleme_tarihi'] ? date('d.m.Y H:i', strtotime($sehir['adres_iller_guncelleme_tarihi'])) : '-'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fas fa-city fa-3x mb-3 d-block"></i>
                                    <h5>Henüz şehir tanımlanmamış</h5>
                                    <p>İlk şehri tanımlamak için "API'den Güncelle" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center" id="pagination">
                </ul>
            </nav>
        </div>
    </div>
</div>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let currentPage = 1;
const rowsPerPage = 10;
let allRows = [];
let filteredRows = [];
let sortDirection = {};

// Sayfa yüklendiğinde tabloyu başlat
document.addEventListener('DOMContentLoaded', function() {
    initializeTable();
    setupFilters();
    populateFilterOptions();
});

function initializeTable() {
    const tbody = document.getElementById('sehirlerTableBody');
    allRows = Array.from(tbody.querySelectorAll('tr'));
    filteredRows = [...allRows];
    updateTable();
}

function setupFilters() {
    // Select2 başlat
    $('#filterName').select2({
        theme: 'bootstrap-5',
        placeholder: 'Şehir seçin...',
        allowClear: true,
        width: '100%'
    });
    
    $('#filterCode').select2({
        theme: 'bootstrap-5',
        placeholder: 'Kod seçin...',
        allowClear: true,
        width: '100%'
    });
    
    // Manuel filtreleme kaldırıldı - sadece buton ile çalışacak
}

function populateFilterOptions() {
    const nameSelect = document.getElementById('filterName');
    const codeSelect = document.getElementById('filterCode');
    
    // Mevcut options'ları temizle (Tümü hariç)
    nameSelect.innerHTML = '<option value="">Tümü</option>';
    codeSelect.innerHTML = '<option value="">Tümü</option>';
    
    // Unique değerleri topla
    const uniqueNames = new Set();
    const uniqueCodes = new Set();
    
    allRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const name = cells[1].textContent.trim();
            const code = cells[2].textContent.trim();
            
            uniqueNames.add(name);
            uniqueCodes.add(code);
        }
    });
    
    // Şehir adlarını ekle (alfabetik sırayla)
    Array.from(uniqueNames).sort().forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        nameSelect.appendChild(option);
    });
    
    // Kodları ekle (sayısal sırayla)
    Array.from(uniqueCodes).sort((a, b) => parseInt(a) - parseInt(b)).forEach(code => {
        const option = document.createElement('option');
        option.value = code;
        option.textContent = code;
        codeSelect.appendChild(option);
    });
    
    // Select2'yi yenile
    $('#filterName').trigger('change');
    $('#filterCode').trigger('change');
}

function applyFilters() {
    const nameFilter = document.getElementById('filterName').value;
    const codeFilter = document.getElementById('filterCode').value;
    const koiFilter = document.getElementById('filterKOI').value;
    
    filteredRows = allRows.filter(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length === 0) return false;
        
        const name = cells[1].textContent.trim();
        const code = cells[2].textContent.trim();
        const koi = cells[3].querySelector('input').checked ? '1' : '0';
        
        return (!nameFilter || name === nameFilter) &&
               (!codeFilter || code === codeFilter) &&
               (!koiFilter || koi === koiFilter);
    });
    
    currentPage = 1;
    updateTable();
}

function clearFilters() {
    $('#filterName').val('').trigger('change');
    $('#filterCode').val('').trigger('change');
    document.getElementById('filterKOI').value = '';
    
    // Filtreleri temizledikten sonra otomatik uygula
    applyFilters();
}

function updateTable() {
    const tbody = document.getElementById('sehirlerTableBody');
    const start = (currentPage - 1) * rowsPerPage;
    const end = start + rowsPerPage;
    
    // Tüm satırları gizle
    allRows.forEach(row => row.style.display = 'none');
    
    // Filtrelenmiş ve sayfalanmış satırları göster
    const visibleRows = filteredRows.slice(start, end);
    visibleRows.forEach(row => row.style.display = '');
    
    updatePagination();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    const pagination = document.getElementById('pagination');
    
    if (totalPages <= 1) {
        pagination.innerHTML = '';
        return;
    }
    
    let html = '';
    
    // Önceki sayfa
    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage - 1})">Önceki</a>
             </li>`;
    
    // Sayfa numaraları
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                     </li>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    // Sonraki sayfa
    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage + 1})">Sonraki</a>
             </li>`;
    
    pagination.innerHTML = html;
}

function changePage(page) {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    if (page >= 1 && page <= totalPages) {
        currentPage = page;
        updateTable();
    }
}

function sortTable(columnIndex) {
    const direction = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
    sortDirection = {}; // Diğer sütunları sıfırla
    sortDirection[columnIndex] = direction;
    
    filteredRows.sort((a, b) => {
        const cellA = a.querySelectorAll('td')[columnIndex];
        const cellB = b.querySelectorAll('td')[columnIndex];
        
        if (!cellA || !cellB) return 0;
        
        let valueA = cellA.textContent.trim();
        let valueB = cellB.textContent.trim();
        
        // Sayısal sütunlar için
        if (columnIndex === 0 || columnIndex === 2) {
            valueA = parseInt(valueA) || 0;
            valueB = parseInt(valueB) || 0;
        }
        // Tarih sütunları için
        else if (columnIndex === 4) {
            valueA = valueA === '-' ? new Date(0) : new Date(valueA.split('.').reverse().join('-'));
            valueB = valueB === '-' ? new Date(0) : new Date(valueB.split('.').reverse().join('-'));
        }
        
        if (valueA < valueB) return direction === 'asc' ? -1 : 1;
        if (valueA > valueB) return direction === 'asc' ? 1 : -1;
        return 0;
    });
    
    currentPage = 1;
    updateTable();
}

// KÖİ durumu güncelleme fonksiyonu
function updateKOI(sehirId, koiDurum) {
    // Loading göster
    const checkbox = event.target;
    const originalState = checkbox.checked;
    checkbox.disabled = true;
    
    // AJAX ile güncelleme yap
    const formData = new FormData();
    formData.append('action', 'update_koi');
    formData.append('id', sehirId);
    formData.append('koi', koiDurum ? '1' : '0');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        checkbox.disabled = false;
        
        if (data.success) {
            // Başarılı güncelleme - checkbox durumunu koru
            console.log('KÖİ durumu güncellendi:', data.message);
        } else {
            // Hata durumunda checkbox'ı eski haline döndür
            checkbox.checked = !originalState;
            alert('Hata: ' + data.message);
        }
    })
    .catch(error => {
        checkbox.disabled = false;
        checkbox.checked = !originalState;
        console.error('Error:', error);
        alert('Güncelleme sırasında bir hata oluştu.');
    });
}

// API Güncelleme fonksiyonu
function apiGuncelle() {
    if (confirm('API\'den şehir bilgilerini güncellemek istediğinizden emin misiniz?\n\nBu işlem birkaç saniye sürebilir ve mevcut veriler güncellenebilir.')) {
        // Loading state göster
        const button = event.target;
        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Güncelleniyor...';
        button.disabled = true;
        
        // Form oluştur ve gönder
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="api_guncelle">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>