<?php
// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = "Başvuru Yönetimi";
$breadcrumbs = [
    ['title' => 'Başvuru Yönetimi']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

// Admin yetkisi kontrolü
$isAdmin = isset($currentUser['group_id']) && $currentUser['group_id'] == 1;

// Sayfa yetki kontrolü
if (!checkPagePermission('api_basvuru_yonetimi.php')) {
    http_response_code(403);
    die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa yetkilerini belirle
if ($isAdmin) {
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0,
        'ekle' => 1,
        'duzenle' => 1,
        'sil' => 1
    ];
} else {
    try {
        $conn = getDatabaseConnection();
        $currentPageUrl = basename($_SERVER['PHP_SELF']);
        
        $yetkiSql = "
            SELECT 
                tsy.gor,
                tsy.kendi_kullanicini_gor,
                tsy.ekle,
                tsy.duzenle,
                tsy.sil
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
            $sayfaYetkileri = [
                'gor' => 1,
                'kendi_kullanicini_gor' => 1,
                'ekle' => 0,
                'duzenle' => 0,
                'sil' => 0
            ];
        }
    } catch (Exception $e) {
        $sayfaYetkileri = [
            'gor' => 1,
            'kendi_kullanicini_gor' => 1,
            'ekle' => 0,
            'duzenle' => 0,
            'sil' => 0
        ];
    }
}

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
            case 'add_basvuru':
                if (!$sayfaYetkileri['ekle']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                $sql = "INSERT INTO API_basvuruListesi (
                           API_basvuru_kullanici_ID,
                           API_basvuru_firstName,
                           API_basvuru_surname,
                           API_basvuru_citizenNumber,
                           API_basvuru_birthDate,
                           API_basvuru_genderType,
                           API_basvuru_email,
                           API_basvuru_phoneCountryNumber,
                           API_basvuru_phoneAreaNumber,
                           API_basvuru_phoneNumber,
                           API_basvuru_bbkAddressCode,
                           API_basvuru_identityCardType_ID,
                           API_basvuru_CampaignList_ID,
                           API_basvuru_Paket_ID,
                           API_basvuru_kaynakSite,
                           API_basvuru_basvuru_durum_ID,
                           API_basvuru_Basvuru_Aciklama,
                           API_basvuru_olusturma_tarih,
                           API_basvuru_guncelleme_tarihi
                       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $_POST['kullanici_id'],
                    $_POST['firstName'],
                    $_POST['surname'],
                    $_POST['citizenNumber'],
                    $_POST['birthDate'] ?: null,
                    $_POST['genderType'] ?: null,
                    $_POST['email'] ?: null,
                    $_POST['phoneCountryNumber'] ?: null,
                    $_POST['phoneAreaNumber'] ?: null,
                    $_POST['phoneNumber'] ?: null,
                    $_POST['bbkAddressCode'] ?: null,
                    $_POST['identityCardType_id'] ?: null,
                    $_POST['kampanya_id'] ?: null,
                    $_POST['paket_id'] ?: null,
                    $_POST['kaynak_site'] ?: null,
                    $_POST['basvuru_durum_id'],
                    $_POST['basvuru_aciklama'] ?? ''
                ]);
                
                $message = 'Başvuru başarıyla eklendi.';
                $messageType = 'success';
                break;
                
            case 'update_basvuru':
                if (!$sayfaYetkileri['duzenle']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                $id = $_POST['basvuru_id'];
                
                // Kendi kullanıcı kısıtlaması kontrolü
                if ($sayfaYetkileri['kendi_kullanicini_gor']) {
                    $checkSql = "SELECT ak.users_ID 
                                 FROM API_basvuruListesi bl
                                 LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
                                 WHERE bl.API_basvuru_ID = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$id]);
                    $basvuruData = $checkStmt->fetch();
                    
                    if (!$basvuruData || $basvuruData['users_ID'] != $currentUser['id']) {
                        throw new Exception('Bu başvuruyu düzenleme yetkiniz bulunmamaktadır.');
                    }
                }
                
                $sql = "UPDATE API_basvuruListesi SET 
                       API_basvuru_kullanici_ID = ?,
                       API_basvuru_firstName = ?,
                       API_basvuru_surname = ?,
                       API_basvuru_citizenNumber = ?,
                       API_basvuru_birthDate = ?,
                       API_basvuru_genderType = ?,
                       API_basvuru_email = ?,
                       API_basvuru_phoneCountryNumber = ?,
                       API_basvuru_phoneAreaNumber = ?,
                       API_basvuru_phoneNumber = ?,
                       API_basvuru_bbkAddressCode = ?,
                       API_basvuru_identityCardType_ID = ?,
                       API_basvuru_CampaignList_ID = ?,
                       API_basvuru_Paket_ID = ?,
                       API_basvuru_kaynakSite = ?,
                       API_basvuru_basvuru_durum_ID = ?,
                       API_basvuru_Basvuru_Aciklama = ?,
                       API_basvuru_guncelleme_tarihi = GETDATE()
                       WHERE API_basvuru_ID = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    $_POST['kullanici_id'],
                    $_POST['firstName'],
                    $_POST['surname'],
                    $_POST['citizenNumber'],
                    $_POST['birthDate'] ?: null,
                    $_POST['genderType'] ?: null,
                    $_POST['email'] ?: null,
                    $_POST['phoneCountryNumber'] ?: null,
                    $_POST['phoneAreaNumber'] ?: null,
                    $_POST['phoneNumber'] ?: null,
                    $_POST['bbkAddressCode'] ?: null,
                    $_POST['identityCardType_id'] ?: null,
                    $_POST['kampanya_id'] ?: null,
                    $_POST['paket_id'] ?: null,
                    $_POST['kaynak_site'] ?: null,
                    $_POST['basvuru_durum_id'],
                    $_POST['basvuru_aciklama'] ?? '',
                    $id
                ]);
                
                $message = 'Başvuru başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                if (!$sayfaYetkileri['sil']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                $id = $_POST['basvuru_id'];
                
                // Kendi kullanıcı kısıtlaması kontrolü
                if ($sayfaYetkileri['kendi_kullanicini_gor']) {
                    $checkSql = "SELECT ak.users_ID 
                                 FROM API_basvuruListesi bl
                                 LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
                                 WHERE bl.API_basvuru_ID = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$id]);
                    $basvuruData = $checkStmt->fetch();
                    
                    if (!$basvuruData || $basvuruData['users_ID'] != $currentUser['id']) {
                        throw new Exception('Bu başvuruyu silme yetkiniz bulunmamaktadır.');
                    }
                }
                
                $sql = "DELETE FROM API_basvuruListesi WHERE API_basvuru_ID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'Başvuru başarıyla silindi.';
                $messageType = 'success';
                break;
        }
        
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Başvuruları getir
try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT 
                bl.*,
                bd.API_basvuru_durum_Mesaj,
                bd.API_basvuru_durum_renk,
                rc.ResponseCode_Message as API_basvuru_ResponseCode_Mesaj,
                rc.ResponseCode_renk as API_basvuru_ResponseCode_renk,
                cl.API_CampaignList_CampaignName,
                ct.API_GetCardTypeList_card_name,
                ak.api_iris_kullanici_OrganisationCd,
                ak.api_iris_kullanici_LoginCd AS API_kullanici_LoginCd,
                u.id as users_id,
                u.first_name,
                u.last_name,
                u.email,
                CASE 
                    WHEN bl.API_basvuru_CampaignList_ID = 1 THEN 
                        CONCAT(sat.API_GetSatelliteCampaignList_PaketAdi, ' / ', sat.API_GetSatelliteCampaignList_Fiyat)
                    WHEN bl.API_basvuru_CampaignList_ID = 2 THEN 
                        CONCAT(neo.API_GetNeoCampaignList_PaketAdi, ' / ', neo.API_GetNeoCampaignList_Fiyat)
                    ELSE NULL
                END AS Paket_Bilgisi
            FROM API_basvuruListesi bl
            LEFT JOIN API_basvuruDurum bd ON bl.API_basvuru_basvuru_durum_ID = bd.API_basvuru_durum_ID
            LEFT JOIN API_ResponseCode rc ON bl.API_basvuru_ResponseCode_ID = rc.ResponseCode_ID
            LEFT JOIN API_CampaignList cl ON bl.API_basvuru_CampaignList_ID = cl.API_CampaignList_ID
            LEFT JOIN API_GetCardTypeList ct ON bl.API_basvuru_identityCardType_ID = ct.API_GetCardTypeList_ID
            LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID
            LEFT JOIN users u ON ak.users_ID = u.id
            LEFT JOIN API_GetSatelliteCampaignList sat ON bl.API_basvuru_Paket_ID = sat.API_GetSatelliteCampaignList_ID AND bl.API_basvuru_CampaignList_ID = 1
            LEFT JOIN API_GetNeoCampaignList neo ON bl.API_basvuru_Paket_ID = neo.API_GetNeoCampaignList_ID AND bl.API_basvuru_CampaignList_ID = 2";
    
    if ($sayfaYetkileri['kendi_kullanicini_gor']) {
        $sql .= " WHERE ak.users_ID = ?";
        $params = [$currentUser['id']];
    } else {
        $params = [];
    }
    
    $sql .= " ORDER BY bl.API_basvuru_olusturma_tarih DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $basvurular = $stmt->fetchAll();

    $apiKullaniciFilterList = [];
    foreach ($basvurular as $basvuru) {
        if (!empty($basvuru['API_kullanici_LoginCd'])) {
            $apiKullaniciFilterList[$basvuru['API_kullanici_LoginCd']] = true;
        }
    }
    $apiKullaniciFilterList = array_keys($apiKullaniciFilterList);
    sort($apiKullaniciFilterList);
    
    // Durum listesini getir
    $durumSql = "SELECT * FROM API_basvuruDurum WHERE API_basvuru_durum_durum = 1 ORDER BY API_basvuru_durum_Mesaj";
    $durumStmt = $conn->prepare($durumSql);
    $durumStmt->execute();
    $durumlar = $durumStmt->fetchAll();
    
    // Kampanya listesini getir (filtre için)
    $kampanyaSql = "SELECT DISTINCT API_CampaignList_CampaignName 
                    FROM API_CampaignList 
                    WHERE API_CampaignList_ID IN (SELECT DISTINCT API_basvuru_CampaignList_ID FROM API_basvuruListesi WHERE API_basvuru_CampaignList_ID IS NOT NULL)
                    ORDER BY API_CampaignList_CampaignName";
    $kampanyaStmt = $conn->prepare($kampanyaSql);
    $kampanyaStmt->execute();
    $kampanyalar = $kampanyaStmt->fetchAll();
    
    // Paket listesini getir (filtre için)
    $paketSql = "SELECT DISTINCT Paket_Bilgisi
                 FROM (
                     SELECT CASE 
                         WHEN bl.API_basvuru_CampaignList_ID = 1 THEN 
                             CONCAT(sat.API_GetSatelliteCampaignList_PaketAdi, ' / ', sat.API_GetSatelliteCampaignList_Fiyat)
                         WHEN bl.API_basvuru_CampaignList_ID = 2 THEN 
                             CONCAT(neo.API_GetNeoCampaignList_PaketAdi, ' / ', neo.API_GetNeoCampaignList_Fiyat)
                     END AS Paket_Bilgisi
                     FROM API_basvuruListesi bl
                     LEFT JOIN API_GetSatelliteCampaignList sat ON bl.API_basvuru_Paket_ID = sat.API_GetSatelliteCampaignList_ID AND bl.API_basvuru_CampaignList_ID = 1
                     LEFT JOIN API_GetNeoCampaignList neo ON bl.API_basvuru_Paket_ID = neo.API_GetNeoCampaignList_ID AND bl.API_basvuru_CampaignList_ID = 2
                     WHERE bl.API_basvuru_Paket_ID IS NOT NULL
                 ) AS PaketList
                 WHERE Paket_Bilgisi IS NOT NULL
                 ORDER BY Paket_Bilgisi";
    $paketStmt = $conn->prepare($paketSql);
    $paketStmt->execute();
    $paketler = $paketStmt->fetchAll();
    
    // ResponseCode listesini getir (filtre için)
    $responseCodeSql = "SELECT DISTINCT ResponseCode_ID, ResponseCode_Message 
                        FROM API_ResponseCode 
                        WHERE ResponseCode_ID IN (SELECT DISTINCT API_basvuru_ResponseCode_ID FROM API_basvuruListesi WHERE API_basvuru_ResponseCode_ID IS NOT NULL)
                        ORDER BY ResponseCode_Message";
    $responseCodeStmt = $conn->prepare($responseCodeSql);
    $responseCodeStmt->execute();
    $responseCodes = $responseCodeStmt->fetchAll();
    
    // Tüm kampanya listesi (form için)
    $allKampanyaSql = "SELECT API_CampaignList_ID, API_CampaignList_CampaignName FROM API_CampaignList ORDER BY API_CampaignList_CampaignName";
    $allKampanyaStmt = $conn->prepare($allKampanyaSql);
    $allKampanyaStmt->execute();
    $allKampanyalar = $allKampanyaStmt->fetchAll();
    
    // API Kullanıcı listesi (form için)
    if ($sayfaYetkileri['kendi_kullanicini_gor']) {
        // Sadece kendi kullanıcısını göster
        $apiKullaniciSql = "SELECT ak.api_iris_kullanici_ID, ak.api_iris_kullanici_LoginCd, u.first_name, u.last_name
                            FROM API_kullanici ak
                            LEFT JOIN users u ON ak.users_ID = u.id
                            WHERE ak.api_iris_kullanici_durum = 1 AND ak.users_ID = ?
                            ORDER BY ak.api_iris_kullanici_LoginCd";
        $apiKullaniciStmt = $conn->prepare($apiKullaniciSql);
        $apiKullaniciStmt->execute([$currentUser['id']]);
        $apiKullanicilar = $apiKullaniciStmt->fetchAll();
        
        // Mevcut kullanıcının API kullanıcı ID'sini al
        $currentApiKullaniciId = !empty($apiKullanicilar) ? $apiKullanicilar[0]['api_iris_kullanici_ID'] : null;
    } else {
        // Tüm kullanıcıları göster
        $apiKullaniciSql = "SELECT ak.api_iris_kullanici_ID, ak.api_iris_kullanici_LoginCd, u.first_name, u.last_name
                            FROM API_kullanici ak
                            LEFT JOIN users u ON ak.users_ID = u.id
                            WHERE ak.api_iris_kullanici_durum = 1
                            ORDER BY ak.api_iris_kullanici_LoginCd";
        $apiKullaniciStmt = $conn->prepare($apiKullaniciSql);
        $apiKullaniciStmt->execute();
        $apiKullanicilar = $apiKullaniciStmt->fetchAll();
        $currentApiKullaniciId = null;
    }
    
    // Kullanıcı listesini getir (filtre için)
    if (!$sayfaYetkileri['kendi_kullanicini_gor']) {
        $usersSql = "SELECT DISTINCT u.first_name, u.last_name
                     FROM users u
                     INNER JOIN API_kullanici ak ON u.id = ak.users_ID
                     INNER JOIN API_basvuruListesi bl ON ak.api_iris_kullanici_ID = bl.API_basvuru_kullanici_ID
                     WHERE u.first_name IS NOT NULL AND u.last_name IS NOT NULL
                     ORDER BY u.first_name, u.last_name";
        $usersStmt = $conn->prepare($usersSql);
        $usersStmt->execute();
        $users = $usersStmt->fetchAll();
    } else {
        $users = [];
    }
    
    // Kimlik kartı tiplerini getir
    $cardTypeSql = "SELECT API_GetCardTypeList_ID, API_GetCardTypeList_card_name 
                    FROM API_GetCardTypeList 
                    ORDER BY API_GetCardTypeList_card_name";
    $cardTypeStmt = $conn->prepare($cardTypeSql);
    $cardTypeStmt->execute();
    $cardTypes = $cardTypeStmt->fetchAll();
    
    $canAdd = $sayfaYetkileri['ekle'];
    $canEdit = $sayfaYetkileri['duzenle'];
    $canDelete = $sayfaYetkileri['sil'];
    $canView = $sayfaYetkileri['gor'];
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata oluştu: ' . $e->getMessage();
    $messageType = 'danger';
    $basvurular = [];
    $durumlar = [];
    $kampanyalar = [];
    $users = [];
    $apiKullaniciFilterList = [];
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
#basvuruTable {
    width: 100% !important;
}

#basvuruTable thead th {
    vertical-align: middle;
    padding: 12px 8px;
    white-space: nowrap;
}

#basvuruTable tbody td {
    vertical-align: middle;
}

#basvuruTable_wrapper {
    overflow-x: visible !important;
}

.dataTables_wrapper .dataTables_scroll {
    overflow: visible !important;
}

@media (max-width: 768px) {
    #basvuruTable {
        font-size: 14px;
    }
}
</style>
<style>
.full-width-section {
    width: 100%;
    max-width: 100%;
    margin-left: 0;
    margin-right: 0;
}
#basvuruTable {
    width: 100% !important;
    min-width: 100% !important;
}
.main-content > .container {
    max-width: 100%;
    width: 100%;
    padding-left: 0;
    padding-right: 0;
}
.full-width-section .card,
.full-width-section .row {
    width: 100%;
}
</style>
<div class="full-width-section mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-alt me-2"></i>Başvuru Yönetimi</h2>
                <div class="btn-group">
                    <button type="button" class="btn btn-danger" id="btnSendDeleteMail" onclick="sendDeleteMail()" disabled>
                        <i class="fas fa-envelope me-2"></i>No Silme Maili Gönder (<span id="selectedCount">0</span>)
                    </button>
                    <?php if ($canAdd): ?>
                        <button type="button" class="btn btn-primary" onclick="showAddModal()">
                            <i class="fas fa-plus me-2"></i>Yeni Başvuru
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <?php
        $icon = 'info-circle';
        switch ($messageType) {
            case 'success': $icon = 'check-circle'; break;
            case 'danger': $icon = 'exclamation-triangle'; break;
            case 'warning': $icon = 'exclamation-triangle'; break;
        }
        ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $icon; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtreleme -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreleme
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php if (!$sayfaYetkileri['kendi_kullanicini_gor']): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label">Kullanıcı</label>
                    <select id="filterUser" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">API Kullanıcı</label>
                    <select id="filterApiUser" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($apiKullaniciFilterList as $apiLogin): ?>
                            <option value="<?php echo htmlspecialchars($apiLogin); ?>">
                                <?php echo htmlspecialchars($apiLogin); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] && !empty($apiKullaniciFilterList)): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label">API Kullanıcı</label>
                    <select id="filterApiUser" class="form-select">
                        <?php if (count($apiKullaniciFilterList) > 1): ?>
                            <option value="">Tümü</option>
                        <?php endif; ?>
                        <?php foreach ($apiKullaniciFilterList as $apiLogin): ?>
                            <option value="<?php echo htmlspecialchars($apiLogin); ?>">
                                <?php echo htmlspecialchars($apiLogin); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-2">
                    <label class="form-label">Kampanya</label>
                    <select id="filterKampanya" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($kampanyalar as $kampanya): ?>
                            <option value="<?php echo htmlspecialchars($kampanya['API_CampaignList_CampaignName']); ?>">
                                <?php echo htmlspecialchars($kampanya['API_CampaignList_CampaignName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Paket</label>
                    <select id="filterPaket" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($paketler as $paket): ?>
                            <option value="<?php echo htmlspecialchars($paket['Paket_Bilgisi']); ?>">
                                <?php echo htmlspecialchars($paket['Paket_Bilgisi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Talep Durum</label>
                    <select id="filterDurum" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($durumlar as $durum): ?>
                            <option value="<?php echo htmlspecialchars($durum['API_basvuru_durum_Mesaj']); ?>">
                                <?php echo htmlspecialchars($durum['API_basvuru_durum_Mesaj']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Başvuru Durum</label>
                    <select id="filterResponseCode" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($responseCodes as $code): ?>
                            <option value="<?php echo htmlspecialchars($code['ResponseCode_Message']); ?>">
                                <?php echo htmlspecialchars($code['ResponseCode_Message']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Ad / Soyad</label>
                    <input type="text" id="filterAdSoyad" class="form-control" placeholder="Ad/Soyad ara...">
                </div>
                <div class="col-12 col-md-1 d-flex align-items-end">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Başvuru Listesi</h5>
        </div>
        <div class="card-body">
            <?php if (empty($basvurular)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Henüz başvuru bulunmuyor.</p>
                </div>
            <?php else: ?>
                    <table class="table table-striped table-hover" id="basvuruTable" style="width:100%">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" id="selectAll" class="form-check-input" title="Tümünü Seç">
                                </th>
                                <th>ID</th>
                                <th>Kullanıcı</th>
                                <th>API<br>Kullanıcı</th>
                                <th>Ad<br>Soyad</th>
                                <th>Telefon</th>
                                <th>Paket</th>
                                <th>Kampanya</th>
                                <th>Başvuru<br>Durum</th>
                                <th>Mesaj</th>
                                <th>Talep<br>Durum</th>
                                <th>Açıklama</th>
                                <th>Oluşturma<br>Tarihi</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($basvurular as $basvuru): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input basvuru-checkbox" 
                                               value="<?php echo $basvuru['API_basvuru_ID']; ?>"
                                               data-phone-country="<?php echo htmlspecialchars($basvuru['API_basvuru_phoneCountryNumber'] ?? ''); ?>"
                                               data-phone-area="<?php echo htmlspecialchars($basvuru['API_basvuru_phoneAreaNumber'] ?? ''); ?>"
                                               data-phone-number="<?php echo htmlspecialchars($basvuru['API_basvuru_phoneNumber'] ?? ''); ?>"
                                               data-citizen="<?php echo htmlspecialchars($basvuru['API_basvuru_citizenNumber'] ?? ''); ?>"
                                               data-kullanici-id="<?php echo htmlspecialchars($basvuru['users_id'] ?? ''); ?>"
                                               data-kullanici-email="<?php echo htmlspecialchars($basvuru['email'] ?? ''); ?>"
                                               data-organisation-code="<?php echo htmlspecialchars($basvuru['api_iris_kullanici_OrganisationCd'] ?? ''); ?>">
                                    </td>
                                    <td><?php echo $basvuru['API_basvuru_ID']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(($basvuru['first_name'] ?? '') . ' ' . ($basvuru['last_name'] ?? '')); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($basvuru['API_kullanici_LoginCd'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars(($basvuru['API_basvuru_firstName'] ?? '') . ' ' . ($basvuru['API_basvuru_surname'] ?? '')); ?></td>
                                    <td>
                                        <?php
                                        $phoneCountry = $basvuru['API_basvuru_phoneCountryNumber'] ?? '';
                                        $phoneArea = $basvuru['API_basvuru_phoneAreaNumber'] ?? '';
                                        $phoneNumber = $basvuru['API_basvuru_phoneNumber'] ?? '';
                                        $citizenNumber = $basvuru['API_basvuru_citizenNumber'] ?? '';

                                        if ($phoneNumber) {
                                            $displayPhone = trim(($phoneCountry ? $phoneCountry . ' ' : '') . trim($phoneArea . ' ' . $phoneNumber));
                                        ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <span><?php echo htmlspecialchars($displayPhone); ?></span>
                                                <button type="button"
                                                        class="btn btn-outline-primary btn-sm copy-contact"
                                                        data-phone-area="<?php echo htmlspecialchars($phoneArea, ENT_QUOTES); ?>"
                                                        data-phone-number="<?php echo htmlspecialchars($phoneNumber, ENT_QUOTES); ?>"
                                                        data-citizen="<?php echo htmlspecialchars($citizenNumber, ENT_QUOTES); ?>"
                                                        title="Bilgileri kopyala">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        <?php
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($basvuru['Paket_Bilgisi'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($basvuru['API_CampaignList_CampaignName'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($basvuru['API_basvuru_ResponseCode_Mesaj']): ?>
                                            <span class="badge" style="background-color: <?php echo $basvuru['API_basvuru_ResponseCode_renk'] ?? '#6c757d'; ?>;">
                                                <?php echo htmlspecialchars($basvuru['API_basvuru_ResponseCode_Mesaj']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($basvuru['API_basvuru_ResponseMessage']): ?>
                                            <div style="max-width: 200px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($basvuru['API_basvuru_ResponseMessage']); ?>">
                                                <?php echo htmlspecialchars($basvuru['API_basvuru_ResponseMessage']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($basvuru['API_basvuru_durum_Mesaj']): ?>
                                            <div style="max-width: 200px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($basvuru['API_basvuru_durum_Mesaj']); ?>">
                                                <span class="badge" style="background-color: <?php echo $basvuru['API_basvuru_durum_renk'] ?? '#6c757d'; ?>;">
                                                    <?php echo htmlspecialchars($basvuru['API_basvuru_durum_Mesaj']); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Belirsiz</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($basvuru['API_basvuru_Basvuru_Aciklama']): ?>
                                            <div style="max-width: 200px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($basvuru['API_basvuru_Basvuru_Aciklama']); ?>">
                                                <?php echo htmlspecialchars($basvuru['API_basvuru_Basvuru_Aciklama']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $tarih = $basvuru['API_basvuru_guncelleme_tarihi'] ?: $basvuru['API_basvuru_olusturma_tarih'];
                                        if ($tarih): ?>
                                            <?php echo date('d.m.Y H:i', strtotime($tarih)); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-info" 
                                                    onclick="showResendModal(<?php echo htmlspecialchars(json_encode($basvuru)); ?>)"
                                                    title="Tekrar Gönder">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                            
                                            <?php if ($canEdit): ?>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        onclick="showDetailModal(<?php echo htmlspecialchars(json_encode($basvuru)); ?>, true)"
                                                        title="Düzenle">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($canDelete): ?>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteBasvuru(<?php echo $basvuru['API_basvuru_ID']; ?>, '<?php echo htmlspecialchars(($basvuru['API_basvuru_firstName'] ?? '') . ' ' . ($basvuru['API_basvuru_surname'] ?? '')); ?>')"
                                                        title="Sil">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mail Önizleme Modal -->
<div class="modal fade" id="mailPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-envelope me-2"></i>No Silme Maili Önizleme
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Mail gönderilmeden önce içeriği kontrol edin. Onayladıktan sonra mail gönderilecektir.
                </div>

                <!-- Alıcı Bilgileri -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-users me-2"></i>Alıcı Bilgileri</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Kime:</strong>
                            <div class="badge bg-primary ms-2" id="preview_to"></div>
                        </div>
                        <div class="mb-2">
                            <strong>CC:</strong>
                            <div id="preview_cc" class="d-inline-block ms-2"></div>
                        </div>
                        <div>
                            <strong>BCC:</strong>
                            <div class="badge bg-secondary ms-2" id="preview_bcc"></div>
                        </div>
                    </div>
                </div>

                <!-- Mail İçeriği -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-envelope-open-text me-2"></i>Mail İçeriği</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Konu:</strong>
                            <div class="ms-2" id="preview_subject"></div>
                        </div>
                        <div>
                            <strong>İçerik:</strong>
                            <div class="border rounded p-3 mt-2" style="background-color: #f8f9fa;">
                                <pre id="preview_body" class="mb-0" style="white-space: pre-wrap; font-family: 'Courier New', monospace; font-size: 13px;"></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seçilen Kayıtlar -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-list me-2"></i>Seçilen Kayıtlar (<span id="preview_count">0</span> Adet)</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>ID</th>
                                        <th>Telefon</th>
                                        <th>TC Kimlik</th>
                                        <th>Kullanıcı Email</th>
                                    </tr>
                                </thead>
                                <tbody id="preview_items"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>İptal
                </button>
                <button type="button" class="btn btn-success" id="btnConfirmSendMail" onclick="confirmSendMail()">
                    <i class="fas fa-paper-plane me-2"></i>Onayla ve Gönder
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Debug Modal - API Gönderim Önizleme -->
<div class="modal fade" id="debugModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-bug me-2"></i>API Gönderim Debug Ekranı</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Dikkat:</strong> Bu ekran, API'ye gönderilecek verileri önizleme amaçlıdır. 
                    "API'ye Gönder" butonuna bastığınızda gerçek API çağrısı yapılacaktır.
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-primary mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-link me-2"></i>API Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <strong>URL:</strong>
                                    <div class="p-2 bg-light rounded mt-1">
                                        <code id="debug_url" style="font-size: 12px;"></code>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <strong>Token:</strong>
                                    <div class="p-2 bg-light rounded mt-1">
                                        <code id="debug_token" style="font-size: 11px; word-break: break-all;"></code>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-success mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-user me-2"></i>Başvuru Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-bordered mb-0">
                                    <tr>
                                        <td><strong>Başvuru ID:</strong></td>
                                        <td id="debug_basvuru_id"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Kullanıcı:</strong></td>
                                        <td id="debug_kullanici"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Kampanya:</strong></td>
                                        <td id="debug_kampanya"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Paket:</strong></td>
                                        <td id="debug_paket"></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td><strong><i class="fas fa-redo me-1"></i>Deneme Sayısı:</strong></td>
                                        <td id="debug_deneme_sayisi"></td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td><strong><i class="fas fa-clock me-1"></i>Son Deneme:</strong></td>
                                        <td id="debug_son_deneme"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card border-warning">
                    <div class="card-header bg-warning">
                        <h6 class="mb-0"><i class="fas fa-code me-2"></i>JSON Body (Gönderilecek Veri)</h6>
                    </div>
                    <div class="card-body">
                        <pre id="debug_json" class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 12px;"></pre>
                    </div>
                </div>
                
                <div id="debug_response_area" style="display: none;" class="mt-3">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border-info mb-3">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-database me-2"></i>Veritabanına Kaydedilen</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm table-bordered mb-0">
                                        <tr>
                                            <td><strong>Response Code ID:</strong></td>
                                            <td id="debug_saved_response_code_id">-</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Response Message:</strong></td>
                                            <td id="debug_saved_response_message">-</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Müşteri No:</strong></td>
                                            <td id="debug_saved_musteri_no">-</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Talep Kayıt No:</strong></td>
                                            <td id="debug_saved_talep_no">-</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Memo ID:</strong></td>
                                            <td id="debug_saved_memo_id">-</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-danger">
                                <div class="card-header" id="debug_response_header">
                                    <h6 class="mb-0"><i class="fas fa-reply me-2"></i>API Yanıtı</h6>
                                </div>
                                <div class="card-body">
                                    <pre id="debug_response" class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow-y: auto; font-size: 12px;"></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Kapat
                </button>
                <button type="button" class="btn btn-success" id="btn_send_api" onclick="sendToAPI()">
                    <i class="fas fa-paper-plane me-2"></i>API'ye Gönder
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ekleme/Düzenleme Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><i class="fas fa-edit me-2"></i>Başvuru Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="edit_form">
                    <input type="hidden" name="action" value="update_basvuru">
                    <input type="hidden" name="basvuru_id" id="edit_basvuru_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-user me-1"></i>Kişisel Bilgiler</h6>
                            <div class="mb-3">
                                <label class="form-label">Ad</label>
                                <input type="text" name="firstName" id="edit_firstName" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Soyad</label>
                                <input type="text" name="surname" id="edit_surname" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">TC Kimlik No</label>
                                <input type="text" name="citizenNumber" id="edit_citizenNumber" class="form-control" maxlength="11" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kimlik Kartı Tipi</label>
                                <select name="identityCardType_id" id="edit_identityCardType" class="form-select">
                                    <option value="">Seçiniz</option>
                                    <?php foreach ($cardTypes as $cardType): ?>
                                        <option value="<?php echo $cardType['API_GetCardTypeList_ID']; ?>">
                                            <?php echo htmlspecialchars($cardType['API_GetCardTypeList_card_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Doğum Tarihi</label>
                                <input type="date" name="birthDate" id="edit_birthDate" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-address-book me-1"></i>İletişim Bilgileri</h6>
                            <div class="mb-3">
                                <label class="form-label">E-posta</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Telefon</label>
                                <div class="row g-2">
                                    <div class="col-3">
                                        <input type="text" name="phoneCountryNumber" id="edit_phoneCountryNumber" 
                                               class="form-control" placeholder="90" value="90" 
                                               pattern="\d{2}" maxlength="2" title="Ülke kodu 2 rakam olmalı">
                                    </div>
                                    <div class="col-3">
                                        <input type="text" name="phoneAreaNumber" id="edit_phoneAreaNumber" 
                                               class="form-control" placeholder="5XX" 
                                               pattern="\d{3}" maxlength="3" title="Alan kodu 3 rakam olmalı">
                                    </div>
                                    <div class="col-6">
                                        <input type="text" name="phoneNumber" id="edit_phoneNumber" 
                                               class="form-control" placeholder="XXXXXXX" 
                                               pattern="\d{7}" maxlength="7" title="Telefon 7 rakam olmalı">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cinsiyet</label>
                                <select name="genderType" id="edit_genderType" class="form-select">
                                    <option value="">Seçiniz</option>
                                    <option value="BAY">BAY</option>
                                    <option value="BAYAN">BAYAN</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Adres Kodu</label>
                                <input type="text" name="bbkAddressCode" id="edit_bbkAddressCode" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-bullhorn me-1"></i>Kampanya Bilgileri</h6>
                            <div class="mb-3">
                                <label class="form-label">Kampanya <span class="text-danger" id="kampanya_required">*</span></label>
                                <select name="kampanya_id" id="edit_kampanya" class="form-select">
                                    <option value="">Kampanya Seçin</option>
                                    <?php foreach ($allKampanyalar as $kampanya): ?>
                                        <option value="<?php echo $kampanya['API_CampaignList_ID']; ?>">
                                            <?php echo htmlspecialchars($kampanya['API_CampaignList_CampaignName']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3" id="paket_container" style="display: none;">
                                <label class="form-label">Paket <span class="text-danger">*</span></label>
                                <select name="paket_id" id="edit_paket" class="form-select">
                                    <option value="">Önce kampanya seçin</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kaynak Site</label>
                                <input type="text" name="kaynak_site" class="form-control" id="edit_kaynak_site">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-info-circle me-1"></i>API Yanıtı</h6>
                            <div class="mb-3">
                                <label class="form-label">Response Code</label>
                                <input type="text" class="form-control" id="detail_response_code" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Response Message</label>
                                <textarea class="form-control" id="detail_response_message" rows="2" readonly></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6 class="text-primary"><i class="fas fa-clipboard me-1"></i>Durum ve Açıklama</h6>
                            <div class="mb-3">
                                <label class="form-label">Durum <span class="text-danger">*</span></label>
                                <select name="basvuru_durum_id" id="edit_durum" class="form-select" required>
                                    <option value="">Durum Seçin</option>
                                    <?php foreach ($durumlar as $durum): ?>
                                        <option value="<?php echo $durum['API_basvuru_durum_ID']; ?>">
                                            <?php echo htmlspecialchars($durum['API_basvuru_durum_Mesaj']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Açıklama</label>
                                <textarea name="basvuru_aciklama" id="edit_aciklama" class="form-control" rows="3" placeholder="Durum açıklaması..."></textarea>
                            </div>
                            
                            <div class="row" style="display: none;">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Oluşturma Tarihi</label>
                                        <input type="text" class="form-control" id="detail_olusturma" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Güncelleme Tarihi</label>
                                        <input type="text" class="form-control" id="detail_guncelleme" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Kullanıcı <span class="text-danger">*</span></label>
                                <select name="kullanici_id" id="edit_kullanici" class="form-select" required>
                                    <option value="">Kullanıcı Seçin</option>
                                    <?php foreach ($apiKullanicilar as $kullanici): ?>
                                        <option value="<?php echo $kullanici['api_iris_kullanici_ID']; ?>">
                                            <?php echo htmlspecialchars($kullanici['api_iris_kullanici_LoginCd']); ?>
                                            <?php if ($kullanici['first_name'] || $kullanici['last_name']): ?>
                                                - <?php echo htmlspecialchars(trim($kullanici['first_name'] . ' ' . $kullanici['last_name'])); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="btn_save" onclick="saveChanges()">
                    <i class="fas fa-save me-2"></i>Kaydet
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    window.basvuruTable = $('#basvuruTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: false,
        scrollX: false,
        autoWidth: true,
        dom: 'rtip',
        columnDefs: [
            { targets: 0, orderable: false, width: '30px' }, // Checkbox
            { targets: 2, visible: false }, // Kullanıcı (gizli)
            { orderable: true, targets: '_all' },
            { width: '50px', targets: 1 }, // ID
            { width: 'auto', targets: '_all' }
        ]
    });
    
    // Tümünü seç checkbox
    $('#selectAll').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('.basvuru-checkbox').prop('checked', isChecked);
        updateSelectedCount();
    });
    
    // Tekil checkbox değişikliği
    $(document).on('change', '.basvuru-checkbox', function() {
        updateSelectedCount();
        
        // Tümünü seç durumunu güncelle
        var totalCheckboxes = $('.basvuru-checkbox').length;
        var checkedCheckboxes = $('.basvuru-checkbox:checked').length;
        $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
    });
    
    // Telefon alanları için sadece rakam girişi
    $('#edit_phoneCountryNumber, #edit_phoneAreaNumber, #edit_phoneNumber').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
    
    // TC Kimlik için sadece rakam
    $('#edit_citizenNumber').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
    
    // Kampanya değiştiğinde paket listesini yükle
    $(document).on('change', '#edit_kampanya', function() {
        var kampanyaId = $(this).val();
        if (kampanyaId) {
            $('#paket_container').show();
            loadPaketler(kampanyaId);
        } else {
            $('#paket_container').hide();
            $('#edit_paket').html('<option value="">Önce kampanya seçin</option>');
        }
    });
});

// Seçilen checkbox sayısını güncelle
function updateSelectedCount() {
    var checkedCount = $('.basvuru-checkbox:checked').length;
    $('#selectedCount').text(checkedCount);
    $('#btnSendDeleteMail').prop('disabled', checkedCount === 0);
}

// Global değişken - seçilen kayıtları tut
var pendingMailData = null;

// No silme maili gönder - Önizleme modalını aç
function sendDeleteMail() {
    var selectedItems = [];
    
    $('.basvuru-checkbox:checked').each(function() {
        selectedItems.push({
            id: $(this).val(),
            phoneCountry: $(this).data('phone-country'),
            phoneArea: $(this).data('phone-area'),
            phoneNumber: $(this).data('phone-number'),
            citizen: $(this).data('citizen'),
            kullaniciId: $(this).data('kullanici-id'),
            kullaniciEmail: $(this).data('kullanici-email'),
            organisationCode: $(this).data('organisation-code')
        });
    });
    
    if (selectedItems.length === 0) {
        alert('Lütfen en az bir başvuru seçin.');
        return;
    }
    
    // Mail içeriğini hazırla (callback ile)
    prepareMailPreview(selectedItems, function(mailData) {
        pendingMailData = mailData;
        // Modal'ı doldur ve göster
        showMailPreviewModal(mailData);
    });
}

// Mail önizleme verilerini hazırla
function prepareMailPreview(selectedItems, callback) {
    var phoneList = [];
    var tcList = [];
    var ccEmails = ['broadbandsales@digiturk.com.tr'];
    
    // Organisation kodlarını topla
    var organisationCodes = [];
    var kullaniciIds = [];
    
    selectedItems.forEach(function(item) {
        // Telefon numarasını formatla
        var phone = $.trim(item.phoneCountry) + ' ' + $.trim(item.phoneArea) + ' ' + $.trim(item.phoneNumber);
        phoneList.push(phone);
        
        // TC kimlik numarası
        if (item.citizen) {
            tcList.push($.trim(item.citizen));
        }
        
        // Organisation kodlarını topla
        if (item.organisationCode && !organisationCodes.includes(item.organisationCode)) {
            organisationCodes.push(item.organisationCode);
        }
        
        // Kullanıcı ID'lerini topla
        if (item.kullaniciId) {
            kullaniciIds.push(item.kullaniciId);
        }
    });
    
    // Seçili kayıtların kullanıcı emaillerini ekle
    selectedItems.forEach(function(item) {
        if (item.kullaniciEmail && !ccEmails.includes(item.kullaniciEmail)) {
            ccEmails.push(item.kullaniciEmail);
        }
    });
    
    // Dinamik CC'leri backend'den al
    $.ajax({
        url: 'api_basvuru_send_delete_mail.php',
        type: 'POST',
        data: JSON.stringify({ 
            preview: true,
            organisationCodes: organisationCodes
        }),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.dynamicCc) {
                // Dinamik CC'leri ekle
                response.dynamicCc.forEach(function(email) {
                    if (email && !ccEmails.includes(email)) {
                        ccEmails.push(email);
                    }
                });
            }
            
            // Mail içeriği
            var subject = "Numara Sildirme";
            var body = "Merhaba,\n\n";
            body += "Aşağıdaki numaraları silebilir miyiz?\n\n";
            body += "Telefon Numaraları:\n";
            body += phoneList.join("\n");
            
            if (tcList.length > 0) {
                body += "\n\nTC Kimlik Numaraları:\n";
                body += tcList.join("\n");
            }
            
            body += "\n\nTeşekkürler.";
            
            var mailData = {
                to: 'WH_dgtbayisatisdestek@concentrix.com',
                cc: ccEmails,
                bcc: 'batuhan.kahraman@ileka.com.tr',
                subject: subject,
                body: body,
                items: selectedItems,
                organisationCodes: organisationCodes
            };
            
            callback(mailData);
        },
        error: function() {
            // Hata durumunda yine de göster
            var subject = "Numara Sildirme";
            var body = "Merhaba,\n\n";
            body += "Aşağıdaki numaraları silebilir miyiz?\n\n";
            body += "Telefon Numaraları:\n";
            body += phoneList.join("\n");
            
            if (tcList.length > 0) {
                body += "\n\nTC Kimlik Numaraları:\n";
                body += tcList.join("\n");
            }
            
            body += "\n\nTeşekkürler.";
            
            var mailData = {
                to: 'WH_dgtbayisatisdestek@concentrix.com',
                cc: ccEmails,
                bcc: 'batuhan.kahraman@ileka.com.tr',
                subject: subject,
                body: body,
                items: selectedItems,
                organisationCodes: organisationCodes
            };
            
            callback(mailData);
        }
    });
}

// Mail önizleme modalını göster
function showMailPreviewModal(mailData) {
    // Alıcı bilgileri
    $('#preview_to').text(mailData.to);
    
    var ccHtml = '';
    mailData.cc.forEach(function(email) {
        ccHtml += '<span class="badge bg-info me-1 mb-1">' + email + '</span>';
    });
    $('#preview_cc').html(ccHtml);
    
    $('#preview_bcc').text(mailData.bcc);
    
    // Mail içeriği
    $('#preview_subject').text(mailData.subject);
    $('#preview_body').text(mailData.body);
    
    // Seçilen kayıtlar
    $('#preview_count').text(mailData.items.length);
    
    var itemsHtml = '';
    mailData.items.forEach(function(item) {
        var phone = $.trim(item.phoneCountry) + ' ' + $.trim(item.phoneArea) + ' ' + $.trim(item.phoneNumber);
        var tc = item.citizen || '-';
        var email = item.kullaniciEmail || '-';
        
        itemsHtml += '<tr>';
        itemsHtml += '<td>' + item.id + '</td>';
        itemsHtml += '<td>' + phone + '</td>';
        itemsHtml += '<td>' + tc + '</td>';
        itemsHtml += '<td>' + email + '</td>';
        itemsHtml += '</tr>';
    });
    $('#preview_items').html(itemsHtml);
    
    // Modal'ı göster
    new bootstrap.Modal(document.getElementById('mailPreviewModal')).show();
}

// Mail gönderimini onayla ve gönder
function confirmSendMail() {
    if (!pendingMailData) {
        alert('Mail verisi bulunamadı!');
        return;
    }
    
    // Butonu devre dışı bırak
    $('#btnConfirmSendMail').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Gönderiliyor...');
    
    $.ajax({
        url: 'api_basvuru_send_delete_mail.php',
        type: 'POST',
        data: JSON.stringify(pendingMailData.items),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('Mail başarıyla gönderildi!');
                
                // Modal'ı kapat
                bootstrap.Modal.getInstance(document.getElementById('mailPreviewModal')).hide();
                
                // Checkbox'ları temizle
                $('.basvuru-checkbox:checked').prop('checked', false);
                $('#selectAll').prop('checked', false);
                updateSelectedCount();
                
                // Pending data'yı temizle
                pendingMailData = null;
            } else {
                alert('Mail gönderilirken hata oluştu: ' + (response.message || 'Bilinmeyen hata'));
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Hatası:', xhr.responseText);
            alert('Mail gönderilirken hata oluştu: ' + error);
        },
        complete: function() {
            $('#btnConfirmSendMail').prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>Onayla ve Gönder');
        }
    });
}

function applyFilters() {
    var table = window.basvuruTable;
    
    <?php if (!$sayfaYetkileri['kendi_kullanicini_gor']): ?>
    var userFilter = $('#filterUser').val();
    table.column(2).search(userFilter);
    <?php endif; ?>

    if ($('#filterApiUser').length) {
        var apiUserFilter = $('#filterApiUser').val();
        table.column(3).search(apiUserFilter);
    }
    
    var kampanyaFilter = $('#filterKampanya').val();
    table.column(7).search(kampanyaFilter);
    
    var paketFilter = $('#filterPaket').val();
    table.column(6).search(paketFilter);
    
    var durumFilter = $('#filterDurum').val();
    table.column(10).search(durumFilter);
    
    var responseCodeFilter = $('#filterResponseCode').val();
    table.column(8).search(responseCodeFilter);
    
    var adSoyadFilter = $('#filterAdSoyad').val();
    table.column(4).search(adSoyadFilter);
    
    table.draw();
}

$(document).on('click', '.copy-contact', function() {
    var $btn = $(this);
    var phoneArea = $btn.data('phoneArea') || '';
    var phoneNumber = $btn.data('phoneNumber') || '';
    var citizen = $btn.data('citizen') || '';
    var phoneText = $.trim((phoneArea + ' ' + phoneNumber).trim());
    var copyText = 'Tel: ' + phoneText + '\nTC: ' + citizen;

    navigator.clipboard.writeText(copyText).then(function() {
        var originalHtml = $btn.html();
        $btn.html('<i class="fas fa-check"></i>');
        setTimeout(function() {
            $btn.html(originalHtml);
        }, 1500);
    }).catch(function() {
        alert('Kopyalama sırasında hata oluştu.');
    });
});

var currentBasvuru = null;
var isEditMode = false;

function showDetailModal(basvuru, editMode = false) {
    currentBasvuru = basvuru;
    
    // Modal başlığını değiştir
    $('#modalTitle').html('<i class="fas fa-edit me-2"></i>Başvuru Düzenle');
    
    // Form action'ını değiştir
    $('#edit_form').find('input[name="action"]').val('update_basvuru');
    
    // Form değerlerini doldur
    $('#edit_basvuru_id').val(basvuru.API_basvuru_ID);
    
    // Kişisel Bilgiler
    $('#edit_firstName').val(basvuru.API_basvuru_firstName || '');
    $('#edit_surname').val(basvuru.API_basvuru_surname || '');
    $('#edit_citizenNumber').val(basvuru.API_basvuru_citizenNumber || '');
    $('#edit_identityCardType').val(basvuru.API_basvuru_identityCardType_ID || '');
    
    // Doğum tarihi formatı
    if (basvuru.API_basvuru_birthDate) {
        var birthDate = new Date(basvuru.API_basvuru_birthDate);
        var formattedDate = birthDate.toISOString().split('T')[0];
        $('#edit_birthDate').val(formattedDate);
    } else {
        $('#edit_birthDate').val('');
    }
    
    $('#edit_genderType').val(basvuru.API_basvuru_genderType || '');
    
    // İletişim Bilgileri
    $('#edit_email').val(basvuru.API_basvuru_email || '');
    $('#edit_phoneCountryNumber').val(basvuru.API_basvuru_phoneCountryNumber || '90');
    $('#edit_phoneAreaNumber').val(basvuru.API_basvuru_phoneAreaNumber || '');
    $('#edit_phoneNumber').val(basvuru.API_basvuru_phoneNumber || '');
    $('#edit_bbkAddressCode').val(basvuru.API_basvuru_bbkAddressCode || '');
    
    // Kampanya Bilgileri
    $('#edit_kullanici').val(basvuru.API_basvuru_kullanici_ID || '');
    $('#edit_kampanya').val(basvuru.API_basvuru_CampaignList_ID || '');
    $('#edit_kaynak_site').val(basvuru.API_basvuru_kaynakSite || '');
    
    // Kampanya seçiliyse paket alanını göster ve doldur
    if (basvuru.API_basvuru_CampaignList_ID) {
        $('#paket_container').show();
        loadPaketler(basvuru.API_basvuru_CampaignList_ID, basvuru.API_basvuru_Paket_ID);
    } else {
        $('#paket_container').hide();
    }
    
    // Kampanya ve paket düzenlenebilir
    $('#kampanya_required').show();
    $('#edit_kampanya').prop('disabled', false);
    $('#edit_paket').prop('disabled', false);
    $('#edit_kaynak_site').prop('readonly', false);
    
    // API Yanıtı (readonly)
    $('#detail_response_code').val(basvuru.API_basvuru_ResponseCode_Mesaj || '-');
    $('#detail_response_message').val(basvuru.API_basvuru_ResponseMessage || '-');
    
    // Durum ve Açıklama
    $('#edit_durum').val(basvuru.API_basvuru_basvuru_durum_ID || '');
    $('#edit_aciklama').val(basvuru.API_basvuru_Basvuru_Aciklama || '');
    
    // Tarihler (readonly)
    $('#detail_olusturma').val(basvuru.API_basvuru_olusturma_tarih ? new Date(basvuru.API_basvuru_olusturma_tarih).toLocaleString('tr-TR') : '-');
    $('#detail_guncelleme').val(basvuru.API_basvuru_guncelleme_tarihi ? new Date(basvuru.API_basvuru_guncelleme_tarihi).toLocaleString('tr-TR') : '-');
    $('#detail_kullanici').val((basvuru.first_name || '') + ' ' + (basvuru.last_name || '') + (basvuru.email ? ' (' + basvuru.email + ')' : ''));
    
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function saveChanges() {
    if ($('#edit_kullanici').val() === '') {
        alert('Lütfen kullanıcı seçiniz.');
        return;
    }
    
    if ($('#edit_durum').val() === '') {
        alert('Lütfen durum seçiniz.');
        return;
    }
    
    // Kampanya ve paket kontrolü (hem ekleme hem düzenleme için)
    if ($('#edit_kampanya').val() === '') {
        alert('Lütfen kampanya seçiniz.');
        return;
    }
    if ($('#edit_paket').val() === '') {
        alert('Lütfen paket seçiniz.');
        return;
    }
    
    $('#edit_form').submit();
}

function loadPaketler(kampanyaId, selectedPaketId = null) {
    $('#edit_paket').html('<option value="">Yükleniyor...</option>');
    
    var tableName = '';
    var idField = '';
    var nameField = '';
    var priceField = '';
    
    if (kampanyaId == 1) {
        tableName = 'API_GetSatelliteCampaignList';
        idField = 'API_GetSatelliteCampaignList_ID';
        nameField = 'API_GetSatelliteCampaignList_PaketAdi';
        priceField = 'API_GetSatelliteCampaignList_Fiyat';
    } else if (kampanyaId == 2) {
        tableName = 'API_GetNeoCampaignList';
        idField = 'API_GetNeoCampaignList_ID';
        nameField = 'API_GetNeoCampaignList_PaketAdi';
        priceField = 'API_GetNeoCampaignList_Fiyat';
    }
    
    if (tableName) {
        $.ajax({
            url: 'get_paketler.php',
            method: 'POST',
            data: {
                table: tableName,
                idField: idField,
                nameField: nameField,
                priceField: priceField
            },
            dataType: 'json',
            success: function(data) {
                var options = '<option value="">Paket Seçin</option>';
                $.each(data, function(index, paket) {
                    var selected = (selectedPaketId && paket.id == selectedPaketId) ? 'selected' : '';
                    var odemeTuru = paket.odeme_turu ? ' - ' + paket.odeme_turu : '';
                    options += '<option value="' + paket.id + '" ' + selected + '>' + paket.name + ' / ' + paket.price + odemeTuru + '</option>';
                });
                $('#edit_paket').html(options);
            },
            error: function() {
                $('#edit_paket').html('<option value="">Hata oluştu</option>');
            }
        });
    } else {
        $('#edit_paket').html('<option value="">Paket bulunamadı</option>');
    }
}

function showAddModal() {
    // Modal başlığını değiştir
    $('#modalTitle').html('<i class="fas fa-plus me-2"></i>Yeni Başvuru');
    
    // Form action'ını değiştir
    $('#edit_form').find('input[name="action"]').val('add_basvuru');
    
    // Form alanlarını temizle
    $('#edit_form')[0].reset();
    $('#edit_basvuru_id').val('');
    
    // Varsayılan değerler
    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] && !empty($apiKullanicilar)): ?>
    // Kendi kullanıcısını otomatik seç (sadece 1 seçenek varsa)
    $('#edit_kullanici').val('<?php echo $apiKullanicilar[0]['api_iris_kullanici_ID'] ?? ''; ?>');
    <?php else: ?>
    $('#edit_kullanici').val('');
    <?php endif; ?>
    $('#edit_phoneCountryNumber').val('90');
    $('#edit_identityCardType').val('');
    $('#edit_durum').val('');
    $('#edit_kampanya').val('');
    $('#edit_paket').html('<option value="">Önce kampanya seçin</option>');
    $('#paket_container').hide();
    
    // Alanları düzenlenebilir yap
    $('#kampanya_required').show();
    $('#edit_kampanya').prop('disabled', false);
    $('#edit_paket').prop('disabled', false);
    $('#edit_kaynak_site').prop('readonly', false);
    
    // API bilgilerini gizle
    $('#detail_response_code').val('-');
    $('#detail_response_message').val('-');
    $('#detail_olusturma').val('-');
    $('#detail_guncelleme').val('-');
    $('#detail_kullanici').val('-');
    
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function deleteBasvuru(id, adSoyad) {
    if (confirm('Bu başvuruyu silmek istediğinize emin misiniz?\n\n' + adSoyad + ' (' + id + ')')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        form.appendChild(actionInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'basvuru_id';
        idInput.value = id;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

var currentResendData = null;

function showResendModal(basvuru) {
    // Yanıt alanını gizle
    $('#debug_response_area').hide();
    $('#btn_send_api').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Hazırlanıyor...');
    
    // Modal'ı göster
    var debugModal = new bootstrap.Modal(document.getElementById('debugModal'));
    debugModal.show();
    
    // Başvuru bilgilerini göster
    $('#debug_basvuru_id').text(basvuru.API_basvuru_ID || '-');
    $('#debug_kullanici').text((basvuru.first_name || '') + ' ' + (basvuru.last_name || ''));
    $('#debug_kampanya').text(basvuru.API_CampaignList_CampaignName || '-');
    $('#debug_paket').text(basvuru.Paket_Bilgisi || '-');
    $('#debug_deneme_sayisi').text('-');
    $('#debug_son_deneme').text('-');
    
    // API verilerini hazırla - AJAX ile backend'den al
    $.ajax({
        url: 'prepare_resend_data.php',
        method: 'POST',
        data: {
            basvuru_id: basvuru.API_basvuru_ID
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                currentResendData = response.data;
                
                // Debug ekranını doldur
                $('#debug_url').text(response.data.url);
                
                // Token'ı kısalt (ilk 5 + *** + son 5 karakter)
                var token = response.data.token;
                var maskedToken = token.substring(0, 5) + '***' + token.substring(token.length - 5);
                $('#debug_token').text(maskedToken);
                
                // Body - Durum sorgulamada body yok
                if (response.data.is_status_check) {
                    $('#debug_json').text('Durum sorgulama için POST request - Body boş (RequestId URL parametresinde)');
                } else {
                    $('#debug_json').text(JSON.stringify(response.data.body, null, 2));
                }
                
                // Yeni kolonları doldur
                $('#debug_deneme_sayisi').html(
                    '<span class="badge bg-info">' + response.data.deneme_sayisi + ' / 3</span>'
                );
                $('#debug_son_deneme').text(
                    response.data.son_deneme 
                    ? new Date(response.data.son_deneme).toLocaleString('tr-TR') 
                    : 'Hiç denenmedi'
                );
                
                // Gönder butonunu aktif et
                $('#btn_send_api').prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>API\'ye Gönder');
            } else {
                alert('Hata: ' + response.message);
                debugModal.hide();
            }
        },
        error: function(xhr, status, error) {
            alert('Veri hazırlanırken hata oluştu: ' + error);
            debugModal.hide();
        }
    });
}

function sendToAPI() {
    if (!currentResendData) {
        alert('Gönderilecek veri hazırlanmadı!');
        return;
    }
    
    // Butonu devre dışı bırak
    $('#btn_send_api').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Gönderiliyor...');
    
    // API'ye gönder
    $.ajax({
        url: 'send_to_api.php',
        method: 'POST',
        data: {
            api_data: JSON.stringify(currentResendData),
            basvuru_id: currentResendData.basvuru_id
        },
        dataType: 'json',
        success: function(response) {
            // Yanıt alanını göster
            $('#debug_response_area').show();
            
            if (response.success) {
                $('#debug_response_header').removeClass('bg-danger').addClass('bg-success text-white');
                $('#debug_response').html(
                    '<span class="text-success">✓ Başarılı!</span>\n\n' +
                    '<strong>HTTP Status:</strong> ' + response.http_status + '\n' +
                    '<strong>Response:</strong>\n' +
                    JSON.stringify(response.api_response, null, 2)
                );
                
                // Debug bilgisi varsa veritabanı alanlarını doldur
                if (response.debug) {
                    $('#debug_saved_response_code_id').text(response.debug.responseCodeId || 'NULL');
                    
                    if (response.debug.isStatusCheck) {
                        $('#debug_saved_response_message').text('Durum ID: ' + (response.debug.basvuruDurumId || 'NULL'));
                    } else {
                        $('#debug_saved_response_message').text(response.debug.responseCodeValue !== null ? 
                            'Code: ' + response.debug.responseCodeValue : 'NULL');
                    }
                    
                    $('#debug_saved_musteri_no').text(response.debug.musteriNo || 'NULL');
                    $('#debug_saved_talep_no').text(response.debug.talepKayitNo || 'NULL');
                    $('#debug_saved_memo_id').text(response.debug.memoId || 'NULL');
                }
                
                // Butonu yeniden aktif et
                $('#btn_send_api').prop('disabled', false).html('<i class="fas fa-check me-2"></i>Başarıyla Gönderildi');
                
                // 2 saniye sonra sayfayı yenile
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                $('#debug_response_header').removeClass('bg-success').addClass('bg-danger text-white');
                $('#debug_response').html(
                    '<span class="text-danger">✗ Hata!</span>\n\n' +
                    '<strong>Hata Mesajı:</strong> ' + response.message + '\n' +
                    (response.http_status ? '<strong>HTTP Status:</strong> ' + response.http_status + '\n' : '') +
                    (response.api_response ? '<strong>API Response:</strong>\n' + JSON.stringify(response.api_response, null, 2) : '')
                );
                
                // Butonu yeniden aktif et
                $('#btn_send_api').prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>API\'ye Gönder');
            }
        },
        error: function(xhr, status, error) {
            $('#debug_response_area').show();
            $('#debug_response_header').removeClass('bg-success').addClass('bg-danger text-white');
            $('#debug_response').html(
                '<span class="text-danger">✗ Sistem Hatası!</span>\n\n' +
                '<strong>Hata:</strong> ' + error + '\n' +
                '<strong>Status:</strong> ' + status
            );
            
            // Butonu yeniden aktif et
            $('#btn_send_api').prop('disabled', false).html('<i class="fas fa-paper-plane me-2"></i>API\'ye Gönder');
        }
    });
}
</script>

        </div>
    </div>

<?php include '../../../includes/footer.php'; ?>