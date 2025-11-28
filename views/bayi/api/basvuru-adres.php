<?php
/**
 * Başvuru Sayfası - Adres Bilgileri (Adım 2)
 * 
 * ⚠️ ÖNEMLİ İSTİSNA: Bu sayfa dışarıdan (public) erişime açıktır!
 * - Auth kontrolü YOK
 * - Yetki kontrolü YOK
 * - Header/Footer/Navigation YOK, Kullanma!
 * 
 * NEO KAMPANYA KONTROLÜ:
 * - kampanya=2 ise bu sayfaya erişilmemeli
 * - Neo için otomatik bbkAddressCode üretilip bir sonraki sayfaya yönlendirilmeli
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

// Session başlat
// iframe içinde çalışması için SameSite=None ve Secure gerekli
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
    session_start();
}

// URL parametrelerini al (yönlendirme için)
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = 'basvuru.php' . ($queryString ? '?' . $queryString : '');

// Kimlik bilgilerinin session'da olup olmadığını kontrol et
if (!isset($_SESSION['basvuru_kimlik'])) {
    header('Location: ' . $redirectUrl);
    exit;
}

// NEO KAMPANYA KONTROLÜ - kampanya=2 ise bu sayfayı atla
$kampanyaId = isset($_SESSION['basvuru_params']['kampanya']) ? $_SESSION['basvuru_params']['kampanya'] : null;
$isNeo = ($kampanyaId == 2);

if ($isNeo) {
    // Neo kampanyası için zaten adres bilgileri üretilmiş olmalı
    // Eğer yoksa üret
    if (!isset($_SESSION['basvuru_adres'])) {
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
        
        // Unique bbkAddressCode üret
        $min = 130109;
        $max = 111069460;
        $maxAttempts = 100;
        $bbkCode = null;
        
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
            $bbkCode = rand($min, $max);
        }
        
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
        
        // Başvuru kaydını güncelle
        if (isset($_SESSION['basvuru_id'])) {
            $updateSql = "UPDATE [dbo].[API_basvuruListesi] SET [API_basvuru_bbkAddressCode] = ?, [API_basvuru_guncelleme_tarihi] = GETDATE() WHERE [API_basvuru_ID] = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$bbkCode, $_SESSION['basvuru_id']]);
        }
    }
    
    // Paket seçim sayfasına yönlendir
    $params = '';
    if (isset($_GET['api_ID'])) {
        $params = '?api_ID=' . urlencode($_GET['api_ID']);
        if (isset($_GET['kampanya'])) {
            $params .= '&kampanya=' . urlencode($_GET['kampanya']);
        }
        if (isset($_GET['paket'])) {
            $params .= '&paket=' . urlencode($_GET['paket']);
        }
    }
    header('Location: basvuru-paket.php' . $params);
    exit;
}

// Veritabanı bağlantısı
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

// AJAX - Adres API çağrıları
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fetchAddress') {
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        // Token al
        $tokenStmt = $conn->query("SELECT TOP 1 [api_iris_kullanici_token] FROM [dbo].[API_kullanici] WHERE [api_iris_kullanici_ID] = 1");
        $tokenData = $tokenStmt->fetch();
        
        if (!$tokenData) {
            throw new Exception('Token bulunamadı');
        }
        
        $token = trim($tokenData['api_iris_kullanici_token']);
        
        // API URL'ini al
        $apiId = intval($_POST['apiId']);
        $stmt = $conn->prepare("SELECT [api_iris_Address_URL] FROM [dbo].[API_Link] WHERE [api_iris_Address_ID] = ? AND [api_iris_durum] = 1");
        $stmt->execute([$apiId]);
        $urlData = $stmt->fetch();
        
        if (!$urlData) {
            throw new Exception('API URL bulunamadı');
        }
        
        $apiUrl = trim($urlData['api_iris_Address_URL']);
        
        // POST body hazırla
        $postData = ['code' => intval($_POST['code'])];
        
        // cURL ile API çağrısı
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Token: ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('API hatası: HTTP ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode hatası');
        }
        
        echo json_encode(['success' => true, 'data' => $result]);
        exit;
        
    } catch (Exception $e) {
        error_log("Adres API hatası: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// AJAX - Adres bilgilerini kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saveAddress'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    try {
        $_SESSION['basvuru_adres'] = [
            'county_code' => intval($_POST['county_code']),
            'county_name' => trim($_POST['county_name']),
            'burg_code' => intval($_POST['burg_code']),
            'burg_name' => trim($_POST['burg_name']),
            'village_code' => intval($_POST['village_code']),
            'village_name' => trim($_POST['village_name']),
            'quarter_code' => intval($_POST['quarter_code']),
            'quarter_name' => trim($_POST['quarter_name']),
            'street_code' => intval($_POST['street_code']),
            'street_name' => trim($_POST['street_name']),
            'building_code' => intval($_POST['building_code']),
            'building_name' => trim($_POST['building_name']),
            'door_code' => intval($_POST['door_code']),
            'door_name' => trim($_POST['door_name']),
            'bbkAddressCode' => trim($_POST['bbkAddressCode'])
        ];
        
        // Başvuru kaydını güncelle - bbkAddressCode'u SQL'e kaydet
        if (isset($_SESSION['basvuru_id']) && !empty($_SESSION['basvuru_adres']['bbkAddressCode'])) {
            $bbkCode = $_SESSION['basvuru_adres']['bbkAddressCode'];
            
            $updateSql = "UPDATE [dbo].[API_basvuruListesi] 
                         SET [API_basvuru_bbkAddressCode] = ?, 
                             [API_basvuru_guncelleme_tarihi] = GETDATE() 
                         WHERE [API_basvuru_ID] = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$bbkCode, $_SESSION['basvuru_id']]);
            
            // Debug log
            error_log("Adres kaydedildi - ID: " . $_SESSION['basvuru_id'] . ", bbkAddressCode: " . $bbkCode);
        } else {
            error_log("UYARI: bbkAddressCode boş veya basvuru_id yok!");
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Adres bilgileri kaydedildi',
            'bbkAddressCode' => $_SESSION['basvuru_adres']['bbkAddressCode']
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("Adres kayıt hatası: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu']);
        exit;
    }
}

$kimlik = $_SESSION['basvuru_kimlik'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Digiturk Başvuru Formu - Adres Bilgileri">
    <title>Adres Bilgileri - Digiturk Başvuru</title>
    
    <!-- Base URL for iframe compatibility -->
    <base href="https://digiturk.ilekasoft.com/views/Bayi/api/">
    
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
        
        .adres-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 0 30px;
            border-radius: 0;
        }
        
        .adres-hero .hero-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .adres-hero .hero-subtitle {
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
        
        .form-select {
            border: 1px solid #ddd;
            padding: 0.75rem;
            border-radius: 5px;
        }
        
        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-select:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        footer {
            margin-top: auto;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Adres Form Section -->
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <!-- Progress Info -->
                    <div class="progress-info">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Adım 2 / 3</span>
                            <span class="badge bg-primary">Adres Bilgileri</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: 66%"></div>
                        </div>
                    </div>

                    <form id="adresForm">
                        <!-- İlçe -->
                        <div class="mb-3">
                            <label for="county" class="form-label">İlçe <span class="text-danger">*</span></label>
                            <select class="form-select" id="county" name="county" required>
                                <option value="">İlçe Seçiniz</option>
                            </select>
                        </div>

                        <!-- Bucak -->
                        <div class="mb-3">
                            <label for="burg" class="form-label">Bucak <span class="text-danger">*</span></label>
                            <select class="form-select" id="burg" name="burg" required disabled>
                                <option value="">Önce İlçe Seçiniz</option>
                            </select>
                        </div>

                        <!-- Köy -->
                        <div class="mb-3">
                            <label for="village" class="form-label">Köy <span class="text-danger">*</span></label>
                            <select class="form-select" id="village" name="village" required disabled>
                                <option value="">Önce Bucak Seçiniz</option>
                            </select>
                        </div>

                        <!-- Mahalle -->
                        <div class="mb-3">
                            <label for="quarter" class="form-label">Mahalle <span class="text-danger">*</span></label>
                            <select class="form-select" id="quarter" name="quarter" required disabled>
                                <option value="">Önce Köy Seçiniz</option>
                            </select>
                        </div>

                        <!-- Sokak -->
                        <div class="mb-3">
                            <label for="street" class="form-label">Sokak <span class="text-danger">*</span></label>
                            <select class="form-select" id="street" name="street" required disabled>
                                <option value="">Önce Mahalle Seçiniz</option>
                            </select>
                        </div>

                        <!-- Bina -->
                        <div class="mb-3">
                            <label for="building" class="form-label">Bina <span class="text-danger">*</span></label>
                            <select class="form-select" id="building" name="building" required disabled>
                                <option value="">Önce Sokak Seçiniz</option>
                            </select>
                        </div>

                        <!-- Kapı -->
                        <div class="mb-3">
                            <label for="door" class="form-label">Kapı <span class="text-danger">*</span></label>
                            <select class="form-select" id="door" name="door" required disabled>
                                <option value="">Önce Bina Seçiniz</option>
                            </select>
                        </div>

                        <input type="hidden" id="bbkAddressCode" name="bbkAddressCode" required>

                        <!-- DEBUG: bbkAddressCode Bilgisi -->
                        <div class="alert alert-info mt-3" id="debugInfo" style="display: none;">
                            <h6 class="fw-bold mb-2"><i class="fas fa-info-circle me-2"></i>Debug Bilgisi</h6>
                            <p class="mb-1"><strong>bbkAddressCode:</strong> <span id="debugBbkCode">-</span></p>
                            <small class="text-muted">Bu kod veritabanına kaydedilecek</small>
                        </div>

                        <div class="d-flex justify-content-between gap-3 mt-4">
                            <a href="basvuru.php<?php echo isset($_GET['api_ID']) ? '?api_ID='.$_GET['api_ID'].'&kampanya='.$kampanyaId.'&paket='.(isset($_SESSION['basvuru_params']['paket']) ? $_SESSION['basvuru_params']['paket'] : '') : ''; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Geri
                            </a>
                            <button type="submit" class="btn btn-primary px-5">
                                Devam Et <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ilCode = <?php echo $kimlik['il_code']; ?>;
    const countySelect = document.getElementById('county');
    const burgSelect = document.getElementById('burg');
    const villageSelect = document.getElementById('village');
    const quarterSelect = document.getElementById('quarter');
    const streetSelect = document.getElementById('street');
    const buildingSelect = document.getElementById('building');
    const doorSelect = document.getElementById('door');

    // API çağrısı
    function fetchData(apiId, code, callback) {
        const formData = new FormData();
        formData.append('action', 'fetchAddress');
        formData.append('apiId', apiId);
        formData.append('code', code);
        
        fetch('basvuru-adres.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const apiData = data.data && data.data.data ? data.data.data : [];
                callback(apiData);
            } else {
                console.error('API hatası:', data.message);
                alert('Adres bilgileri yüklenirken hata oluştu: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Fetch hatası:', error);
            alert('Bağlantı hatası oluştu. Lütfen tekrar deneyin.');
        });
    }

    // Select doldur
    function populateSelect(selectElement, data, valueKey, textKey) {
        selectElement.innerHTML = '<option value="">Seçiniz</option>';
        if (data && Array.isArray(data)) {
            data.forEach(item => {
                const option = document.createElement('option');
                option.value = item[valueKey];
                option.textContent = item[textKey];
                option.dataset.code = item[valueKey];
                option.dataset.name = item[textKey];
                selectElement.appendChild(option);
            });
        }
    }

    // İlçeleri yükle (API 3: GetCountysByCityCode)
    fetchData(3, ilCode, data => {
        populateSelect(countySelect, data, 'code', 'name');
    });

    // İlçe değiştiğinde - Bucak getir (API 4)
    countySelect.addEventListener('change', function() {
        const code = this.value;
        
        burgSelect.innerHTML = '<option value="">Bucak Yükleniyor...</option>';
        burgSelect.disabled = true;
        villageSelect.innerHTML = '<option value="">Önce Bucak Seçiniz</option>';
        villageSelect.disabled = true;
        quarterSelect.innerHTML = '<option value="">Önce Köy Seçiniz</option>';
        quarterSelect.disabled = true;
        streetSelect.innerHTML = '<option value="">Önce Mahalle Seçiniz</option>';
        streetSelect.disabled = true;
        buildingSelect.innerHTML = '<option value="">Önce Sokak Seçiniz</option>';
        buildingSelect.disabled = true;
        doorSelect.innerHTML = '<option value="">Önce Bina Seçiniz</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(4, code, data => {
                populateSelect(burgSelect, data, 'code', 'name');
                burgSelect.disabled = false;
            });
        }
    });

    // Bucak değiştiğinde - Köy getir (API 5)
    burgSelect.addEventListener('change', function() {
        const code = this.value;
        
        villageSelect.innerHTML = '<option value="">Köy Yükleniyor...</option>';
        villageSelect.disabled = true;
        quarterSelect.innerHTML = '<option value="">Önce Köy Seçiniz</option>';
        quarterSelect.disabled = true;
        streetSelect.innerHTML = '<option value="">Önce Mahalle Seçiniz</option>';
        streetSelect.disabled = true;
        buildingSelect.innerHTML = '<option value="">Önce Sokak Seçiniz</option>';
        buildingSelect.disabled = true;
        doorSelect.innerHTML = '<option value="">Önce Bina Seçiniz</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(5, code, data => {
                populateSelect(villageSelect, data, 'code', 'name');
                villageSelect.disabled = false;
            });
        }
    });

    // Köy değiştiğinde - Mahalle getir (API 6)
    villageSelect.addEventListener('change', function() {
        const code = this.value;
        
        quarterSelect.innerHTML = '<option value="">Mahalle Yükleniyor...</option>';
        quarterSelect.disabled = true;
        streetSelect.innerHTML = '<option value="">Önce Mahalle Seçiniz</option>';
        streetSelect.disabled = true;
        buildingSelect.innerHTML = '<option value="">Önce Sokak Seçiniz</option>';
        buildingSelect.disabled = true;
        doorSelect.innerHTML = '<option value="">Önce Bina Seçiniz</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(6, code, data => {
                populateSelect(quarterSelect, data, 'code', 'name');
                quarterSelect.disabled = false;
            });
        }
    });

    // Mahalle değiştiğinde - Sokak getir (API 7)
    quarterSelect.addEventListener('change', function() {
        const code = this.value;
        
        streetSelect.innerHTML = '<option value="">Sokak Yükleniyor...</option>';
        streetSelect.disabled = true;
        buildingSelect.innerHTML = '<option value="">Önce Sokak Seçiniz</option>';
        buildingSelect.disabled = true;
        doorSelect.innerHTML = '<option value="">Önce Bina Seçiniz</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(7, code, data => {
                populateSelect(streetSelect, data, 'code', 'name');
                streetSelect.disabled = false;
            });
        }
    });

    // Sokak değiştiğinde - Bina getir (API 8)
    streetSelect.addEventListener('change', function() {
        const code = this.value;
        
        buildingSelect.innerHTML = '<option value="">Bina Yükleniyor...</option>';
        buildingSelect.disabled = true;
        doorSelect.innerHTML = '<option value="">Önce Bina Seçiniz</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(8, code, data => {
                populateSelect(buildingSelect, data, 'code', 'name');
                buildingSelect.disabled = false;
            });
        }
    });

    // Bina değiştiğinde - Kapı getir (API 9)
    buildingSelect.addEventListener('change', function() {
        const code = this.value;
        
        doorSelect.innerHTML = '<option value="">Kapı Yükleniyor...</option>';
        doorSelect.disabled = true;
        
        if (code) {
            fetchData(9, code, data => {
                populateSelect(doorSelect, data, 'code', 'name');
                doorSelect.disabled = false;
            });
        }
    });

    // Kapı değiştiğinde - bbkAddressCode'u ayarla (API 10 ile tam adres alınacak)
    doorSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const doorCode = selectedOption.dataset.code;
            // API 10: GetFullAdressByAdressCode çağrısı
            fetchData(10, doorCode, fullAddressData => {
                let bbkCode = '';
                if (fullAddressData && fullAddressData.bbkAddressCode) {
                    bbkCode = fullAddressData.bbkAddressCode;
                } else {
                    bbkCode = doorCode;
                }
                
                // Hidden input'a kaydet
                document.getElementById('bbkAddressCode').value = bbkCode;
                
                // Debug bilgisini göster
                document.getElementById('debugBbkCode').textContent = bbkCode;
                document.getElementById('debugInfo').style.display = 'block';
            });
        } else {
            // Kapı seçimi kaldırılırsa debug'ı gizle
            document.getElementById('debugInfo').style.display = 'none';
            document.getElementById('bbkAddressCode').value = '';
        }
    });

    // Form submit
    document.getElementById('adresForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        formData.append('saveAddress', '1');
        
        const countyOption = countySelect.options[countySelect.selectedIndex];
        formData.append('county_code', countyOption.dataset.code);
        formData.append('county_name', countyOption.dataset.name);
        
        const burgOption = burgSelect.options[burgSelect.selectedIndex];
        formData.append('burg_code', burgOption.dataset.code);
        formData.append('burg_name', burgOption.dataset.name);
        
        const villageOption = villageSelect.options[villageSelect.selectedIndex];
        formData.append('village_code', villageOption.dataset.code);
        formData.append('village_name', villageOption.dataset.name);
        
        const quarterOption = quarterSelect.options[quarterSelect.selectedIndex];
        formData.append('quarter_code', quarterOption.dataset.code);
        formData.append('quarter_name', quarterOption.dataset.name);
        
        const streetOption = streetSelect.options[streetSelect.selectedIndex];
        formData.append('street_code', streetOption.dataset.code);
        formData.append('street_name', streetOption.dataset.name);
        
        const buildingOption = buildingSelect.options[buildingSelect.selectedIndex];
        formData.append('building_code', buildingOption.dataset.code);
        formData.append('building_name', buildingOption.dataset.name);
        
        const doorOption = doorSelect.options[doorSelect.selectedIndex];
        formData.append('door_code', doorOption.dataset.code);
        formData.append('door_name', doorOption.dataset.name);
        
        const bbkCode = document.getElementById('bbkAddressCode').value || '';
        
        // bbkAddressCode kontrolü
        if (!bbkCode) {
            alert('❌ Hata: bbkAddressCode bulunamadı! Lütfen tüm adres alanlarını seçtiğinizden emin olun.');
            return;
        }
        
        formData.append('bbkAddressCode', bbkCode);
        
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kaydediliyor...';
        
        console.log('Form gönderiliyor - bbkAddressCode:', bbkCode);
        
        fetch('basvuru-adres.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('✓ Adres bilgileri kaydedildi!');
                console.log('✓ bbkAddressCode SQL\'e kaydedildi:', data.bbkAddressCode || bbkCode);
                
                const params = new URLSearchParams(window.location.search);
                window.location.href = 'basvuru-paket.php?' + params.toString();
            } else {
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

</body>
</html>
