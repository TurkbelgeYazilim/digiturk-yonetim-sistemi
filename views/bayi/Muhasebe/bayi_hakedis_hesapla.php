<?php
$pageTitle = "Hakediş Hesaplama";
$breadcrumbs = [
    ['title' => 'Hakediş Hesaplama']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// getDatabaseConnection function is already defined in auth.php

// Sayfa yetkilendirme kontrolü
$sayfaYetkileri = [
    'gor' => false,
    'kendi_kullanicini_gor' => false,
    'ekle' => false,
    'duzenle' => false,
    'sil' => false
];

// Admin kontrolü (group_id = 5 ise tüm yetkilere sahip)
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
        $currentPageUrl = basename($_SERVER['PHP_SELF']); // bayi_hakedis_hesapla.php
        
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

// Filtre parametrelerini al - varsayılan değerlerle
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Varsayılan filtreler - sadece herhangi bir parametre yoksa
$hasAnyParam = !empty($_GET);
$outletDurum = isset($_GET['outlet_durum']) ? $_GET['outlet_durum'] : ($hasAnyParam ? '' : 'AKTIF');
$satisDurumu = isset($_GET['satis_durumu']) ? $_GET['satis_durumu'] : ($hasAnyParam ? '' : 'Tamamlandı');
$hakedisDonemi = isset($_GET['hakedis_donemi']) ? $_GET['hakedis_donemi'] : '';
$altBayi = isset($_GET['alt_bayi']) ? (is_array($_GET['alt_bayi']) ? $_GET['alt_bayi'] : [$_GET['alt_bayi']]) : [];
$bayiAdSoyad = isset($_GET['bayi_ad_soyad']) ? $_GET['bayi_ad_soyad'] : '';

// Eğer kendi_kullanicini_gor = 1 ise, sadece kendi kaydını gösterecek
if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
    $bayiAdSoyad = $currentUser['id'];
}

// Hakediş dönemlerini veritabanından al (veritabanı bağlantısı kurulduktan sonra)
$hakedisDonemleri = [];
$altBayiList = [];

// Initialize variables with default values
$outletDurumOptions = [];
$satisDurumuOptions = [];
$altBayiOptions = [];
$bayiAdSoyadOptions = [];
$pivotData = [];
$stats = ['toplam_kayit' => 0, 'toplam_altbayi' => 0, 'toplam_kayit_tipi' => 0];
$columns = ['ISP_NEO', 'ISP_UYDU', 'NEO', 'UYDU'];
$error = null;

try {
    $conn = getDatabaseConnection();
    
    // Hakediş dönemlerini al
    try {
        $hakedisDonemiSql = "SELECT id, donem_adi, baslangic_tarihi, bitis_tarihi FROM digiturk.bayi_hakedis_prim_donem ORDER BY baslangic_tarihi DESC";
        $hakedisDonemiStmt = $conn->prepare($hakedisDonemiSql);
        $hakedisDonemiStmt->execute();
        $hakedisDonemiResult = $hakedisDonemiStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($hakedisDonemiResult as $donem) {
            $hakedisDonemleri[$donem['id']] = [
                'label' => $donem['donem_adi'],
                'start' => $donem['baslangic_tarihi'],
                'end' => $donem['bitis_tarihi']
            ];
        }
    } catch (Exception $e) {
        // Hata durumunda boş dizi kullan
        $hakedisDonemleri = [];
    }

    // Alt bayileri al - önce iris_rapor'dan mevcut bayileri kontrol et
    try {
        // Önce users_bayi tablosundan dene
        $altBayiSql = "SELECT ub.id, ub.user_id, (u.first_name + ' ' + u.last_name) as bayi_adi FROM users_bayi ub 
                       INNER JOIN users u ON ub.user_id = u.id 
                       ORDER BY u.first_name, u.last_name";
        $altBayiStmt = $conn->prepare($altBayiSql);
        $altBayiStmt->execute();
        $altBayiResult = $altBayiStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($altBayiResult)) {
            foreach ($altBayiResult as $bayi) {
                $altBayiList[$bayi['user_id']] = [
                    'id' => $bayi['id'],
                    'user_id' => $bayi['user_id'],
                    'bayi_adi' => $bayi['bayi_adi']
                ];
            }
        } else {
            // Eğer users_bayi tablosu boşsa, iris_rapor'dan al
            $irisBayiSql = "SELECT DISTINCT TALEBI_GIREN_PERSONEL_ALTBAYI as bayi_adi 
                           FROM digiturk.iris_rapor 
                           WHERE TALEBI_GIREN_PERSONEL_ALTBAYI IS NOT NULL 
                           AND TALEBI_GIREN_PERSONEL_ALTBAYI != ''
                           ORDER BY TALEBI_GIREN_PERSONEL_ALTBAYI";
            $irisBayiStmt = $conn->prepare($irisBayiSql);
            $irisBayiStmt->execute();
            $irisBayiResult = $irisBayiStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($irisBayiResult as $index => $bayi) {
                $altBayiList[$bayi['bayi_adi']] = [
                    'id' => $index + 1,
                    'user_id' => $bayi['bayi_adi'],
                    'bayi_adi' => $bayi['bayi_adi']
                ];
            }
        }
    } catch (Exception $e) {
        // Hata durumunda iris_rapor'dan al
        try {
            $irisBayiSql = "SELECT DISTINCT TALEBI_GIREN_PERSONEL_ALTBAYI as bayi_adi 
                           FROM digiturk.iris_rapor 
                           WHERE TALEBI_GIREN_PERSONEL_ALTBAYI IS NOT NULL 
                           AND TALEBI_GIREN_PERSONEL_ALTBAYI != ''
                           ORDER BY TALEBI_GIREN_PERSONEL_ALTBAYI";
            $irisBayiStmt = $conn->prepare($irisBayiSql);
            $irisBayiStmt->execute();
            $irisBayiResult = $irisBayiStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($irisBayiResult as $index => $bayi) {
                $altBayiList[$bayi['bayi_adi']] = [
                    'id' => $index + 1,
                    'user_id' => $bayi['bayi_adi'],
                    'bayi_adi' => $bayi['bayi_adi']
                ];
            }
        } catch (Exception $e2) {
            $altBayiList = [];
        }
    }

    // Hakediş dönemlerini veritabanından al (veritabanı bağlantısı kurulduktan sonra)
    $checkTableSql = "SELECT COUNT(*) as table_exists FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'digiturk' AND TABLE_NAME = 'iris_rapor'";
    $checkStmt = $conn->prepare($checkTableSql);
    $checkStmt->execute();
    $tableCheck = $checkStmt->fetch();
    
    if ($tableCheck['table_exists'] == 0) {
        throw new Exception('iris_rapor tablosu bulunamadı. Lütfen önce raporları yükleyiniz.');
    }
    
    // Gerekli sütunların varlığını kontrol et
    $requiredColumns = ['GUNCEL_OUTLET_DURUM', 'SATIS_DURUMU', 'TALEBI_GIREN_PERSONEL_ALTBAYI', 'MEMO_KAYIT_TIPI', 'MEMO_KAPANIS_TARIHI', 'TALEP_TURU'];
    $checkColumnsSql = "
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'digiturk' AND TABLE_NAME = 'iris_rapor' 
        AND COLUMN_NAME IN ('" . implode("','", $requiredColumns) . "')
    ";
    $checkColStmt = $conn->prepare($checkColumnsSql);
    $checkColStmt->execute();
    $existingColumns = $checkColStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $missingColumns = array_diff($requiredColumns, $existingColumns);
    if (!empty($missingColumns)) {
        throw new Exception('Gerekli sütunlar bulunamadı: ' . implode(', ', $missingColumns) . '. Lütfen güncel rapor formatını kullanın.');
    }
    
    // Test data availability
    $testSql = "SELECT COUNT(*) as row_count FROM digiturk.iris_rapor";
    $testStmt = $conn->prepare($testSql);
    $testStmt->execute();
    $rowCount = $testStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($rowCount['row_count'] == 0) {
        throw new Exception('iris_rapor tablosunda veri bulunamadı. Lütfen önce raporları yükleyiniz.');
    }
    
    // Initialize with empty arrays in case queries fail
    $outletDurumOptions = [];
    $satisDurumuOptions = [];
    $altBayiOptions = [];
    $bayiAdSoyadOptions = [];
    
    // Önce GUNCEL_OUTLET_DURUM değerlerini al (dropdown için)
    try {
        $outletDurumSql = "SELECT DISTINCT GUNCEL_OUTLET_DURUM FROM digiturk.iris_rapor WHERE GUNCEL_OUTLET_DURUM IS NOT NULL AND GUNCEL_OUTLET_DURUM != '' ORDER BY GUNCEL_OUTLET_DURUM";
        $outletDurumStmt = $conn->prepare($outletDurumSql);
        if ($outletDurumStmt) {
            $outletDurumStmt->execute();
            $outletDurumOptions = $outletDurumStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        // Silent fail for dropdown queries
    }
    
    // SATIS_DURUMU değerlerini al (dropdown için)
    try {
        $satisDurumuSql = "SELECT DISTINCT SATIS_DURUMU FROM digiturk.iris_rapor WHERE SATIS_DURUMU IS NOT NULL AND SATIS_DURUMU != '' ORDER BY SATIS_DURUMU";
        $satisDurumuStmt = $conn->prepare($satisDurumuSql);
        if ($satisDurumuStmt) {
            $satisDurumuStmt->execute();
            $satisDurumuOptions = $satisDurumuStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        // Silent fail for dropdown queries
    }
    
    // Alt Bayi değerlerini al (dropdown için) - personel_kimlik_no temelinde
    try {
        $altBayiSql = "SELECT DISTINCT ub.personel_kimlik_no, (u.first_name + ' ' + u.last_name) as bayi_adi 
                       FROM users_bayi ub 
                       INNER JOIN users u ON ub.user_id = u.id 
                       WHERE ub.personel_kimlik_no IS NOT NULL 
                       AND ub.personel_kimlik_no != '' 
                       ORDER BY u.first_name, u.last_name";
        $altBayiStmt = $conn->prepare($altBayiSql);
        if ($altBayiStmt) {
            $altBayiStmt->execute();
            $altBayiResult = $altBayiStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($altBayiResult as $bayi) {
                $altBayiOptions[] = $bayi['personel_kimlik_no'];
            }
        }
    } catch (Exception $e) {
        // Hata durumunda iris_rapor'dan personel no'larını al
        try {
            $irisBayiSql = "SELECT DISTINCT TALEBI_GIREN_PERSONELNO FROM digiturk.iris_rapor WHERE TALEBI_GIREN_PERSONELNO IS NOT NULL AND TALEBI_GIREN_PERSONELNO != '' ORDER BY TALEBI_GIREN_PERSONELNO";
            $irisBayiStmt = $conn->prepare($irisBayiSql);
            $irisBayiStmt->execute();
            $altBayiOptions = $irisBayiStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e2) {
            // Silent fail for dropdown queries
        }
    }
    
    // Bayi Ad Soyad değerlerini al (users_bayi tablosu ile ilişkilendirerek)
    try {
        $bayiAdSoyadSql = "
            SELECT 
                u.id, 
                u.first_name, 
                u.last_name,
                (u.first_name + ' ' + u.last_name) as full_name,
                ub.personel_kimlik_no
            FROM users u 
            INNER JOIN users_bayi ub ON u.id = ub.user_id 
            WHERE u.first_name IS NOT NULL 
            AND u.last_name IS NOT NULL 
            AND ub.personel_kimlik_no IS NOT NULL 
            AND ub.personel_kimlik_no != ''
            ORDER BY u.first_name, u.last_name";
        $bayiAdSoyadStmt = $conn->prepare($bayiAdSoyadSql);
        if ($bayiAdSoyadStmt) {
            $bayiAdSoyadStmt->execute();
            $bayiAdSoyadResult = $bayiAdSoyadStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($bayiAdSoyadResult as $bayi) {
                $fullName = trim($bayi['first_name'] . ' ' . $bayi['last_name']);
                if (!empty($fullName)) {
                    // Aynı kullanıcı için birden fazla personel_kimlik_no olabilir
                    if (!isset($bayiAdSoyadOptions[$bayi['id']])) {
                        $bayiAdSoyadOptions[$bayi['id']] = [
                            'full_name' => $fullName,
                            'personel_kimlik_no' => []
                        ];
                    }
                    // Personel kimlik no'yu array olarak sakla
                    $bayiAdSoyadOptions[$bayi['id']]['personel_kimlik_no'][] = $bayi['personel_kimlik_no'];
                }
            }
        }
    } catch (Exception $e) {
        // Silent fail for dropdown queries - debug için hata mesajı ekleyelim
        error_log("Bayi Ad Soyad sorgu hatası: " . $e->getMessage());
    }
    
    // WHERE koşullarını hazırla
    $whereConditions = [];
    $params = [];
    
    // Temel koşullar
    $whereConditions[] = "TALEBI_GIREN_PERSONEL_ALTBAYI IS NOT NULL";
    $whereConditions[] = "TALEBI_GIREN_PERSONEL_ALTBAYI != ''";
    
    // Tarih filtresi
    if (!empty($startDate)) {
        $whereConditions[] = "MEMO_KAPANIS_TARIHI >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    
    if (!empty($endDate)) {
        $whereConditions[] = "MEMO_KAPANIS_TARIHI <= ?";
        $params[] = $endDate . ' 23:59:59';
    }
    
    // Outlet durum filtresi
    if (!empty($outletDurum)) {
        $whereConditions[] = "GUNCEL_OUTLET_DURUM = ?";
        $params[] = $outletDurum;
    }
    
    // Satış durumu filtresi
    if (!empty($satisDurumu)) {
        $whereConditions[] = "SATIS_DURUMU = ?";
        $params[] = $satisDurumu;
    }
    
    // Personel filtresi - Alt bayi veya Bayi ad soyad (öncelik sırasına göre)
    // Eğer Alt Bayi (Personel Kimlik No) manuel seçildiyse onu kullan
    if (!empty($altBayi) && is_array($altBayi)) {
        $altBayi = array_filter($altBayi); // Boş değerleri temizle
        if (!empty($altBayi)) {
            $placeholders = str_repeat('?,', count($altBayi) - 1) . '?';
            $whereConditions[] = "TALEBI_GIREN_PERSONELNO IN ($placeholders)";
            foreach ($altBayi as $personelNo) {
                $params[] = $personelNo;
            }
        }
    } 
    // Eğer Alt Bayi boş ama Bayi Ad Soyad seçildiyse, bayinin tüm personel kimlik nolarını kullan
    elseif (!empty($bayiAdSoyad)) {
        try {
            // Seçilen bayinin tüm personel_kimlik_no kodlarını al
            $bayiPersonelKimlikQuery = "SELECT personel_kimlik_no FROM users_bayi WHERE user_id = ?";
            $bayiPersonelKimlikStmt = $conn->prepare($bayiPersonelKimlikQuery);
            $bayiPersonelKimlikStmt->execute([$bayiAdSoyad]);
            $bayiPersonelKimlikResults = $bayiPersonelKimlikStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($bayiPersonelKimlikResults)) {
                $personelKimlikNos = array_column($bayiPersonelKimlikResults, 'personel_kimlik_no');
                $personelKimlikNos = array_filter($personelKimlikNos); // Boş değerleri temizle
                
                if (!empty($personelKimlikNos)) {
                    // Birden fazla personel_kimlik_no varsa IN operatörü kullan
                    $placeholders = str_repeat('?,', count($personelKimlikNos) - 1) . '?';
                    $whereConditions[] = "TALEBI_GIREN_PERSONELNO IN ($placeholders)";
                    foreach ($personelKimlikNos as $pkNo) {
                        $params[] = $pkNo;
                    }
                }
            }
        } catch (Exception $e) {
            // Hata durumunda filtreyi atlayalım
            error_log("Bayi Ad Soyad filtresi hatası: " . $e->getMessage());
        }
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Pivot sorgusu - TALEBI_GIREN_PERSONEL_ALTBAYI satır, MEMO_KAYIT_TIPI sütun
    $sql = "
    SELECT 
        ISNULL(TALEBI_GIREN_PERSONEL_ALTBAYI, 'Belirtilmemiş') as ALTBAYI,
        TALEBI_GIREN_PERSONELNO as PERSONEL_NO,
        TALEBI_GIREN_PERSONEL as TALEBI_GIREN_PERSONEL,
        SUM(CASE WHEN MEMO_KAYIT_TIPI = 'ISP' AND TALEP_TURU = 'ISP ve Neo Potansiyel Talep' THEN 1 ELSE 0 END) as ISP_NEO,
        SUM(CASE WHEN MEMO_KAYIT_TIPI = 'ISP' AND TALEP_TURU = 'Uydu ve ISP Potansiyel Talep' THEN 1 ELSE 0 END) as ISP_UYDU,
        SUM(CASE WHEN MEMO_KAYIT_TIPI = 'NEO' THEN 1 ELSE 0 END) as NEO,
        SUM(CASE WHEN MEMO_KAYIT_TIPI = 'UYDU' THEN 1 ELSE 0 END) as UYDU,
        COUNT(*) as TOPLAM
    FROM digiturk.iris_rapor 
    $whereClause
    GROUP BY TALEBI_GIREN_PERSONEL_ALTBAYI, TALEBI_GIREN_PERSONELNO, TALEBI_GIREN_PERSONEL
    ORDER BY TOPLAM DESC, ALTBAYI
    ";
    
    // Main pivot query with error handling
    $pivotData = [];
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->execute($params);
            $pivotData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $pivotData = [];
    }
    
    // Sistemde kayıtlı personel kimlik nolarını al (renklendirme için)
    $registeredPersonelNos = [];
    try {
        $registeredSql = "SELECT DISTINCT personel_kimlik_no FROM users_bayi WHERE personel_kimlik_no IS NOT NULL AND personel_kimlik_no != ''";
        $registeredStmt = $conn->prepare($registeredSql);
        $registeredStmt->execute();
        $registeredPersonelNos = $registeredStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $registeredPersonelNos = [];
    }
    
    // Sütun başlıklarını manuel olarak belirle (ISP'yi ISP_NEO ve ISP_UYDU olarak böl)
    $columns = ['ISP_NEO', 'ISP_UYDU', 'NEO', 'UYDU'];
    
    // Genel istatistikler (aynı filtrelerle)
    $statsSql = "
    SELECT 
        COUNT(*) as toplam_kayit,
        COUNT(DISTINCT TALEBI_GIREN_PERSONEL_ALTBAYI) as toplam_altbayi,
        COUNT(DISTINCT MEMO_KAYIT_TIPI) as toplam_kayit_tipi
    FROM digiturk.iris_rapor
    $whereClause
    ";
    
    // Stats query with error handling
    $stats = ['toplam_kayit' => 0, 'toplam_altbayi' => 0, 'toplam_kayit_tipi' => 0];
    try {
        $statsStmt = $conn->prepare($statsSql);
        if ($statsStmt) {
            $statsStmt->execute($params);
            $statsResult = $statsStmt->fetch(PDO::FETCH_ASSOC);
            if ($statsResult) {
                $stats = $statsResult;
            }
        }
    } catch (Exception $e) {
        // Silent fail with default stats
    }
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Hakediş dönemi seçildiyse tarih aralığını otomatik ayarla (veritabanından veri çekildikten sonra)
if (!empty($hakedisDonemi) && isset($hakedisDonemleri[$hakedisDonemi])) {
    // Sadece tarih parametreleri manuel olarak set edilmemişse otomatik ayarla
    if (empty($_GET['start_date']) && empty($_GET['end_date'])) {
        $startDate = date('Y-m-d', strtotime($hakedisDonemleri[$hakedisDonemi]['start']));
        $endDate = date('Y-m-d', strtotime($hakedisDonemleri[$hakedisDonemi]['end']));
    }
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calculator me-2"></i>Hakediş Hesaplama</h2>
                <div class="btn-group">
                    <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                    <button class="btn btn-outline-primary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i>Excel'e Aktar
                    </button>
                    <button class="btn btn-outline-secondary" onclick="printReport()">
                        <i class="fas fa-print me-1"></i>Yazdır
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-outline-info" onclick="refreshData()">
                        <i class="fas fa-sync-alt me-1"></i>Yenile
                    </button>
                </div>
            </div>
        </div>
    </div>



    <!-- Filtre Formu -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreler
                <button class="btn btn-sm btn-outline-secondary float-end" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </h6>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <!-- Birinci Satır: Hakediş Dönemi ve Tarih Filtreleri -->
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-3">
                            <label for="hakedis_donemi" class="form-label">
                                <i class="fas fa-calendar-check me-1"></i>Hakediş Dönemi
                            </label>
                            <select class="form-select" id="hakedis_donemi" name="hakedis_donemi">
                                <option value="">Manuel Tarih Seçimi</option>
                                <?php foreach ($hakedisDonemleri as $key => $donem): ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo $hakedisDonemi === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($donem['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i>Başlangıç Tarihi
                            </label>
                            <input type="date" 
                                   class="form-control" 
                                   id="start_date" 
                                   name="start_date" 
                                   value="<?php echo htmlspecialchars($startDate); ?>"
                                   <?php echo !empty($hakedisDonemi) ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">
                                <i class="fas fa-calendar-alt me-1"></i>Bitiş Tarihi
                            </label>
                            <input type="date" 
                                   class="form-control" 
                                   id="end_date" 
                                   name="end_date" 
                                   value="<?php echo htmlspecialchars($endDate); ?>"
                                   <?php echo !empty($hakedisDonemi) ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-3">
                            <div class="btn-group w-100">
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setDateRange('today')" <?php echo !empty($hakedisDonemi) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-calendar-day me-1"></i>Bugün
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setDateRange('week')" <?php echo !empty($hakedisDonemi) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-calendar-week me-1"></i>Bu Hafta
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setDateRange('month')" <?php echo !empty($hakedisDonemi) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-calendar me-1"></i>Bu Ay
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- İkinci Satır: Diğer Filtreler ve Butonlar -->
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label for="outlet_durum" class="form-label">
                                <i class="fas fa-store me-1"></i>Outlet Durum
                            </label>
                            <select class="form-select" id="outlet_durum" name="outlet_durum">
                                <option value="">Tümü</option>
                                <?php foreach ($outletDurumOptions as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" 
                                            <?php echo $outletDurum === $option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="satis_durumu" class="form-label">
                                <i class="fas fa-chart-line me-1"></i>Satış Durum
                            </label>
                            <select class="form-select" id="satis_durumu" name="satis_durumu">
                                <option value="">Tümü</option>
                                <?php foreach ($satisDurumuOptions as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" 
                                            <?php echo $satisDurumu === $option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2" style="display: none;">
                            <label for="alt_bayi" class="form-label">
                                <i class="fas fa-user-tie me-1"></i>Personel Kimlik No
                            </label>
                            <select class="form-select" id="alt_bayi" name="alt_bayi[]" multiple style="height: 38px;">
                                <?php foreach ($altBayiOptions as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" 
                                            <?php echo in_array($option, $altBayi) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> Çoklu seçim için Ctrl+Tıklayın
                            </small>
                        </div>
                        <div class="col-md-2">
                            <label for="bayi_ad_soyad" class="form-label">
                                <i class="fas fa-user me-1"></i>Bayi Ad Soyad
                            </label>
                            <select class="form-select" id="bayi_ad_soyad" name="bayi_ad_soyad" <?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>>
                                <option value="">Tümü</option>
                                <?php foreach ($bayiAdSoyadOptions as $id => $bayiData): ?>
                                    <?php 
                                    // kendi_kullanicini_gor = 1 ise sadece kendi kaydını göster
                                    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1 && $id != $currentUser['id']) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" 
                                            <?php echo $bayiAdSoyad == $id ? 'selected' : ''; ?>
                                            data-personel-kimlik-no="<?php echo htmlspecialchars(implode(',', $bayiData['personel_kimlik_no'])); ?>">
                                        <?php echo htmlspecialchars($bayiData['full_name']); ?> 
                                        <small>(<?php echo htmlspecialchars(implode(', ', $bayiData['personel_kimlik_no'])); ?>)</small>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                                <!-- Kısıtlı kullanıcı için hidden input ile değeri gönder -->
                                <input type="hidden" name="bayi_ad_soyad" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Sadece kendi kayıtlarınızı görüntüleyebilirsiniz
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2">
                            <div class="btn-group w-100">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-search me-1"></i>Filtrele
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-lg" onclick="clearFilters()">
                                    <i class="fas fa-times me-1"></i>Temizle
                                </button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <?php if (!empty($hakedisDonemi) && isset($hakedisDonemleri[$hakedisDonemi])): ?>
                                <div class="alert alert-info mb-0 py-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <strong><?php echo htmlspecialchars($hakedisDonemleri[$hakedisDonemi]['label']); ?></strong>
                                    <br>
                                    <small>
                                        <?php echo date('d.m.Y', strtotime($hakedisDonemleri[$hakedisDonemi]['start'])); ?> - 
                                        <?php echo date('d.m.Y', strtotime($hakedisDonemleri[$hakedisDonemi]['end'])); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Aktif Filtreler -->
                    <?php if (!empty($startDate) || !empty($endDate) || !empty($outletDurum) || !empty($satisDurumu) || !empty($hakedisDonemi) || !empty($altBayi) || !empty($bayiAdSoyad)): ?>
                    <div class="mt-3">
                        <h6 class="text-muted mb-2">
                            <i class="fas fa-filter me-1"></i>Aktif Filtreler:
                        </h6>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($hakedisDonemi) && isset($hakedisDonemleri[$hakedisDonemi])): ?>
                                <span class="badge bg-info">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    <?php echo htmlspecialchars($hakedisDonemleri[$hakedisDonemi]['label']); ?>
                                    <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('hakedis_donemi')" style="font-size: 0.7em;"></button>
                                </span>
                            <?php else: ?>
                                <?php if (!empty($startDate)): ?>
                                    <span class="badge bg-primary">
                                        <i class="fas fa-calendar me-1"></i>
                                        Başlangıç: <?php echo date('d.m.Y', strtotime($startDate)); ?>
                                        <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('start_date')" style="font-size: 0.7em;"></button>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($endDate)): ?>
                                    <span class="badge bg-primary">
                                        <i class="fas fa-calendar me-1"></i>
                                        Bitiş: <?php echo date('d.m.Y', strtotime($endDate)); ?>
                                        <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('end_date')" style="font-size: 0.7em;"></button>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($outletDurum)): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-store me-1"></i>
                                    Outlet: <?php echo htmlspecialchars($outletDurum); ?>
                                    <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('outlet_durum')" style="font-size: 0.7em;"></button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($satisDurumu)): ?>
                                <span class="badge bg-warning">
                                    <i class="fas fa-chart-line me-1"></i>
                                    Satış: <?php echo htmlspecialchars($satisDurumu); ?>
                                    <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('satis_durumu')" style="font-size: 0.7em;"></button>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($altBayi) && is_array($altBayi)): ?>
                                <?php foreach ($altBayi as $personelNo): ?>
                                    <span class="badge bg-dark">
                                        <i class="fas fa-user-tie me-1"></i>
                                        Personel No: <?php echo htmlspecialchars($personelNo); ?>
                                        <button type="button" class="btn-close btn-close-white ms-1" onclick="removeArrayFilter('alt_bayi', '<?php echo htmlspecialchars($personelNo); ?>')" style="font-size: 0.7em;"></button>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!empty($bayiAdSoyad) && isset($bayiAdSoyadOptions[$bayiAdSoyad])): ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-user me-1"></i>
                                    Bayi: <?php echo htmlspecialchars($bayiAdSoyadOptions[$bayiAdSoyad]['full_name']); ?>
                                    <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('bayi_ad_soyad')" style="font-size: 0.7em;"></button>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- İstatistik Kartları -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-primary mb-2">
                        <i class="fas fa-database fa-2x"></i>
                    </div>
                    <h4 class="text-primary"><?php echo isset($stats['toplam_kayit']) ? number_format($stats['toplam_kayit']) : '0'; ?></h4>
                    <p class="text-muted mb-0">Toplam Kayıt</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-success mb-2">
                        <i class="fas fa-users fa-2x"></i>
                    </div>
                    <h4 class="text-success"><?php echo isset($stats['toplam_altbayi']) ? number_format($stats['toplam_altbayi']) : '0'; ?></h4>
                    <p class="text-muted mb-0">Alt Bayi Kodu Sayısı</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-info mb-2">
                        <i class="fas fa-tags fa-2x"></i>
                    </div>
                    <h4 class="text-info"><?php echo isset($stats['toplam_kayit_tipi']) ? number_format($stats['toplam_kayit_tipi']) : '0'; ?></h4>
                    <p class="text-muted mb-0">Kayıt Tipi Sayısı</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-warning mb-2">
                        <i class="fas fa-filter fa-2x"></i>
                    </div>
                    <h4 class="text-warning">
                        <?php 
                        $activeFilters = 0;
                        if (!empty($hakedisDonemi)) {
                            $activeFilters++; // Hakediş dönemi seçildiyse tarih aralığı otomatik sayılır
                        } else {
                            if (!empty($startDate)) $activeFilters++;
                            if (!empty($endDate)) $activeFilters++;
                        }
                        if (!empty($outletDurum)) $activeFilters++;
                        if (!empty($satisDurumu)) $activeFilters++;
                        if (!empty($altBayi) && is_array($altBayi)) $activeFilters += count($altBayi);
                        if (!empty($bayiAdSoyad)) $activeFilters++;
                        
                        if ($activeFilters > 0) {
                            echo $activeFilters . ' Filtre Aktif';
                        } else {
                            echo 'Tüm Veriler';
                        }
                        ?>
                    </h4>
                    <p class="text-muted mb-0">
                        <?php 
                        $filterDetails = [];
                        
                        if (!empty($hakedisDonemi) && isset($hakedisDonemleri[$hakedisDonemi])) {
                            $filterDetails[] = $hakedisDonemleri[$hakedisDonemi]['label'];
                        } else {
                            if (!empty($startDate) && !empty($endDate)) {
                                $filterDetails[] = date('d.m.Y', strtotime($startDate)) . ' - ' . date('d.m.Y', strtotime($endDate));
                            } elseif (!empty($startDate)) {
                                $filterDetails[] = date('d.m.Y', strtotime($startDate)) . ' sonrası';
                            } elseif (!empty($endDate)) {
                                $filterDetails[] = date('d.m.Y', strtotime($endDate)) . ' öncesi';
                            }
                        }
                        
                        if (!empty($outletDurum)) {
                            $filterDetails[] = 'Outlet: ' . $outletDurum;
                        }
                        
                        if (!empty($satisDurumu)) {
                            $filterDetails[] = 'Satış: ' . $satisDurumu;
                        }
                        
                        if (!empty($altBayi) && is_array($altBayi)) {
                            if (count($altBayi) > 2) {
                                $filterDetails[] = 'Personel No: ' . implode(', ', array_slice($altBayi, 0, 2)) . '... (+' . (count($altBayi) - 2) . ')';
                            } else {
                                $filterDetails[] = 'Personel No: ' . implode(', ', $altBayi);
                            }
                        }
                        
                        if (!empty($bayiAdSoyad) && isset($bayiAdSoyadOptions[$bayiAdSoyad])) {
                            $filterDetails[] = 'Bayi: ' . $bayiAdSoyadOptions[$bayiAdSoyad]['full_name'];
                        }
                        
                        if (!empty($filterDetails)) {
                            echo implode(' • ', $filterDetails);
                        } else {
                            echo 'Filtre Durumu';
                        }
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Debug Bilgileri (Geliştirme amaçlı) -->
    <?php if (empty($bayiAdSoyadOptions)): ?>
    <div class="alert alert-warning">
        <h6><i class="fas fa-database me-2"></i>Bayi Ad Soyad Debug</h6>
        <p class="mb-2"><strong>Bayi Ad Soyad Listesi:</strong> Boş (<?php echo count($bayiAdSoyadOptions); ?> kayıt)</p>
        
        <?php
        // Users tablosunu kontrol et
        try {
            echo "<div class='mt-3'>";
            echo "<h6>Mevcut Users Tablosu (İlk 10 kayıt):</h6>";
            $testUsersQuery = "SELECT TOP 10 id, first_name, last_name, status FROM users ORDER BY id";
            $testUsersStmt = $conn->prepare($testUsersQuery);
            $testUsersStmt->execute();
            $testUsersResult = $testUsersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($testUsersResult)) {
                echo "<table class='table table-sm'>";
                echo "<thead><tr><th>ID</th><th>First Name</th><th>Last Name</th><th>Status</th></tr></thead>";
                echo "<tbody>";
                foreach ($testUsersResult as $user) {
                    echo "<tr>";
                    echo "<td>{$user['id']}</td>";
                    echo "<td>" . htmlspecialchars($user['first_name'] ?? 'NULL') . "</td>";
                    echo "<td>" . htmlspecialchars($user['last_name'] ?? 'NULL') . "</td>";
                    echo "<td>" . htmlspecialchars($user['status'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            } else {
                echo "<p class='text-danger'>Users tablosu boş!</p>";
            }
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<p class='text-danger'>Debug sorgusu hatası: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- Debug Bilgileri (Geliştirme amaçlı) -->
    <?php if (empty($altBayiList)): ?>
    <div class="alert alert-warning">
        <h6><i class="fas fa-database me-2"></i>Veritabanı Durumu</h6>
        <p class="mb-2"><strong>Alt Bayi Listesi:</strong> Boş (<?php echo count($altBayiList); ?> kayıt)</p>
        <p class="mb-2"><strong>Hakediş Dönemleri:</strong> <?php echo count($hakedisDonemleri); ?> dönem</p>
        
        <div class="mt-3">
            <h6>Olası Çözümler:</h6>
            <ul class="mb-0">
                <li>IRIS raporlarının yüklenmiş olduğundan emin olun</li>
                <li><code>users_bayi</code> tablosunda kayıt bulunduğundan emin olun</li>
                <li><code>iris_rapor</code> tablosunda <code>TALEBI_GIREN_PERSONEL_ALTBAYI</code> verisi olduğundan emin olun</li>
            </ul>
        </div>
    </div>
    <?php endif; ?>




        
        <!-- Ana Rapor Tablosu -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    Alt Bayi Kodu - Kayıt Tipi Dağılımı
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="pivotTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="text-start ps-3">Alt Bayi Kodu</th>
                                <th class="text-center">Hakediş Dönemi</th>
                                <?php if (!empty($columns)): ?>
                                    <?php foreach ($columns as $column): ?>
                                        <th class="text-center"><?php echo htmlspecialchars($column); ?></th>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <th class="text-center bg-light fw-bold">TOPLAM</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pivotData)): ?>
                                <?php 
                                $totalRow = [];
                                foreach ($columns as $col) {
                                    $totalRow[$col] = 0;
                                }
                                $grandTotal = 0;
                                ?>
                                
                                <?php foreach ($pivotData as $row): ?>
                                    <?php 
                                    // Personel no sistemde kayıtlı mı kontrol et
                                    $isRegistered = !empty($row['PERSONEL_NO']) && in_array($row['PERSONEL_NO'], $registeredPersonelNos);
                                    $rowClass = $isRegistered ? 'table-success' : '';
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td class="ps-3 fw-medium">
                                            <?php if ($isRegistered): ?>
                                                <i class="fas fa-check-circle text-success me-1" title="Sistemde kayıtlı"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($row['ALTBAYI']); ?>
                                            <?php if (!empty($row['PERSONEL_NO'])): ?>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($row['PERSONEL_NO']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if (!empty($row['TALEBI_GIREN_PERSONEL'])): ?>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($row['TALEBI_GIREN_PERSONEL']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            // Hakediş dönemi bilgisini göster
                                            if (!empty($hakedisDonemi) && isset($hakedisDonemleri[$hakedisDonemi])) {
                                                echo '<span class="badge bg-info">' . htmlspecialchars($hakedisDonemleri[$hakedisDonemi]['label']) . '</span>';
                                            } elseif (!empty($startDate) && !empty($endDate)) {
                                                echo '<small class="text-muted">' . date('d.m.Y', strtotime($startDate)) . ' - ' . date('d.m.Y', strtotime($endDate)) . '</small>';
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                            ?>
                                        </td>
                                        
                        <?php foreach ($columns as $column): ?>
                            <?php 
                            $value = 0;
                            switch(strtoupper($column)) {
                                case 'ISP_NEO':
                                    $value = $row['ISP_NEO'];
                                    break;
                                case 'ISP_UYDU':
                                    $value = $row['ISP_UYDU'];
                                    break;
                                case 'NEO':
                                    $value = $row['NEO'];
                                    break;
                                case 'UYDU':
                                    $value = $row['UYDU'];
                                    break;
                            }
                            $totalRow[$column] += $value;
                            ?>
                            <td class="text-center">
                                <?php if ($value > 0): ?>
                                    <span class="badge bg-primary"><?php echo number_format($value); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>                                        <td class="text-center bg-light">
                                            <strong class="text-primary"><?php echo number_format($row['TOPLAM']); ?></strong>
                                        </td>
                                    </tr>
                                    <?php $grandTotal += $row['TOPLAM']; ?>
                                <?php endforeach; ?>
                                
                                <!-- Toplam Satırı -->
                                <tr class="table-secondary">
                                    <td class="ps-3 fw-bold">TOPLAM</td>
                                    <td class="text-center fw-bold">-</td>
                                    <?php foreach ($columns as $column): ?>
                                        <td class="text-center fw-bold">
                                            <?php echo number_format($totalRow[$column]); ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center fw-bold bg-primary text-white">
                                        <?php echo number_format($grandTotal); ?>
                                    </td>
                                </tr>
                                
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo count($columns) + 3; ?>" class="text-center py-5 text-muted">
                                        <i class="fas fa-search fa-2x mb-3 d-block"></i>
                        <?php if (!empty($startDate) || !empty($endDate) || !empty($outletDurum) || !empty($satisDurumu) || !empty($hakedisDonemi) || !empty($altBayi) || !empty($bayiAdSoyad)): ?>
                            <h5>Belirtilen kriterlere uygun veri bulunamadı</h5>
                            <p class="mb-0">
                                <?php 
                                $activeFilterCount = 0;
                                if (!empty($hakedisDonemi)) {
                                    $activeFilterCount++; // Hakediş dönemi tarihleri de kapsar
                                } else {
                                    if (!empty($startDate)) $activeFilterCount++;
                                    if (!empty($endDate)) $activeFilterCount++;
                                }
                                if (!empty($outletDurum)) $activeFilterCount++;
                                if (!empty($satisDurumu)) $activeFilterCount++;
                                if (!empty($altBayi)) $activeFilterCount++;
                                if (!empty($bayiAdSoyad)) $activeFilterCount++;                                                echo "Aktif " . $activeFilterCount . " filtre ile eşleşen kayıt bulunamadı.";
                                                ?>
                                                <br>Lütfen farklı kriterler deneyin veya filtreleri temizleyin.
                                            </p>
                                            <button class="btn btn-outline-primary mt-2" onclick="clearFilters()">
                                                <i class="fas fa-times me-1"></i>Filtreleri Temizle
                                            </button>
                                        <?php else: ?>
                                            <h5>Henüz veri bulunmuyor</h5>
                                            <p class="mb-0">Lütfen önce CSV dosyalarınızı sisteme yükleyin.</p>
                                            <a href="upload_iris_final.php" class="btn btn-outline-primary mt-2">
                                                <i class="fas fa-upload me-1"></i>Dosya Yükle
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detaylı Analiz -->
        <?php if (!empty($pivotData)): ?>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">En Yüksek İşlem Hacmi</h6>
                    </div>
                    <div class="card-body">
                        <?php 
                        $topPerformers = array_slice($pivotData, 0, 5);
                        foreach ($topPerformers as $index => $performer): 
                        ?>
                            <div class="d-flex justify-content-between align-items-center <?php echo $index > 0 ? 'mt-2' : ''; ?>">
                                <span class="text-truncate me-2" title="<?php echo htmlspecialchars($performer['ALTBAYI']); ?>">
                                    <?php echo ($index + 1) . '. ' . htmlspecialchars($performer['ALTBAYI']); ?>
                                </span>
                                <span class="badge bg-success"><?php echo number_format($performer['TOPLAM']); ?></span>
                            </div>
                            <?php if ($index < count($topPerformers) - 1): ?>
                                <hr class="my-2">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">Kayıt Tipi Dağılımı</h6>
                    </div>
                    <div class="card-body">
                        <?php 
                        $typeStats = [];
                        foreach ($pivotData as $row) {
                            foreach ($columns as $column) {
                                if (!isset($typeStats[$column])) {
                                    $typeStats[$column] = 0;
                                }
                                switch(strtoupper($column)) {
                                    case 'ISP_NEO':
                                        $typeStats[$column] += $row['ISP_NEO'];
                                        break;
                                    case 'ISP_UYDU':
                                        $typeStats[$column] += $row['ISP_UYDU'];
                                        break;
                                    case 'NEO':
                                        $typeStats[$column] += $row['NEO'];
                                        break;
                                    case 'UYDU':
                                        $typeStats[$column] += $row['UYDU'];
                                        break;
                                }
                            }
                        }
                        arsort($typeStats);
                        
                        foreach ($typeStats as $type => $count):
                            $percentage = $grandTotal > 0 ? round(($count / $grandTotal) * 100, 1) : 0;
                        ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span><?php echo htmlspecialchars($type); ?></span>
                                <div class="text-end">
                                    <span class="badge bg-info me-2"><?php echo number_format($count); ?></span>
                                    <small class="text-muted">(%<?php echo $percentage; ?>)</small>
                                </div>
                            </div>
                            <div class="progress mb-3" style="height: 6px;">
                                <div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Excel Export için SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Excel'e aktarma fonksiyonu
function exportToExcel() {
    try {
        const table = document.getElementById('pivotTable');
        const wb = XLSX.utils.table_to_book(table, {sheet: "Hakediş Raporu"});
        
        // Dosya adını tarih ile oluştur
        const now = new Date();
        const dateStr = now.getFullYear() + '-' + 
                       String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                       String(now.getDate()).padStart(2, '0');
        const filename = `Hakedis_Raporu_${dateStr}.xlsx`;
        
        XLSX.writeFile(wb, filename);
        
        // Başarı mesajı
        showToast('Excel dosyası başarıyla indirildi!', 'success');
    } catch (error) {
        console.error('Excel export error:', error);
        showToast('Excel aktarımında hata oluştu!', 'error');
    }
}

// Yazdırma fonksiyonu
function printReport() {
    window.print();
}

// Sayfayı yenileme (mevcut filtreleri koruyarak)
function refreshData() {
    const currentUrl = new URL(window.location);
    window.location.href = currentUrl.toString();
}

// Filtreleri temizle
function clearFilters() {
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    document.getElementById('outlet_durum').value = '';
    document.getElementById('satis_durumu').value = '';
    document.getElementById('hakedis_donemi').value = '';
    
    // Multi-select için tüm seçimleri kaldır
    const altBayiSelect = document.getElementById('alt_bayi');
    for (let i = 0; i < altBayiSelect.options.length; i++) {
        altBayiSelect.options[i].selected = false;
    }
    
    document.getElementById('bayi_ad_soyad').value = '';
    document.getElementById('filterForm').submit();
}

// Belirli filtreyi kaldır
function removeFilter(filterName) {
    document.getElementById(filterName).value = '';
    document.getElementById('filterForm').submit();
}

// Array filtreyi kaldır (multi-select için)
function removeArrayFilter(filterName, value) {
    const select = document.getElementById(filterName);
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === value) {
            select.options[i].selected = false;
        }
    }
    document.getElementById('filterForm').submit();
}

// Hızlı tarih aralığı seçimi
function setDateRange(range) {
    const today = new Date();
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    let startDate, endDate;
    
    switch(range) {
        case 'today':
            startDate = today;
            endDate = today;
            break;
            
        case 'week':
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay() + 1); // Pazartesi
            const endOfWeek = new Date(startOfWeek);
            endOfWeek.setDate(startOfWeek.getDate() + 6); // Pazar
            startDate = startOfWeek;
            endDate = endOfWeek;
            break;
            
        case 'month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
            
        default:
            return;
    }
    
    startDateInput.value = startDate.toISOString().split('T')[0];
    endDateInput.value = endDate.toISOString().split('T')[0];
    
    // Otomatik submit
    setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 100);
}

// Toast mesaj fonksiyonu
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    toastContainer.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    // Toast kaldırıldıktan sonra DOM'dan sil
    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

// Toast container oluştur
function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
    return container;
}


// Form validasyonu ve etkileşimler
document.addEventListener('DOMContentLoaded', function() {
    const tableRows = document.querySelectorAll('#pivotTable tbody tr:not(.table-secondary)');
    
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
            this.style.transform = 'scale(1.01)';
            this.style.transition = 'all 0.2s ease';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
            this.style.transform = '';
        });
    });
    
    // Tarih validasyonu
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const outletDurumSelect = document.getElementById('outlet_durum');
    const satisDurumuSelect = document.getElementById('satis_durumu');
    const hakedisDonemiSelect = document.getElementById('hakedis_donemi');
    const bayiAdSoyadSelect = document.getElementById('bayi_ad_soyad');
    
    // Hakediş dönemleri tanımları (PHP'den JavaScript'e)
    const hakedisDonemleri = <?php echo json_encode($hakedisDonemleri); ?>;
    
    function validateDates() {
        if (!startDateInput || !endDateInput) return true;
        
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;
        
        if (startDate && endDate) {
            if (new Date(startDate) > new Date(endDate)) {
                showToast('Başlangıç tarihi bitiş tarihinden büyük olamaz!', 'error');
                return false;
            }
        }
        return true;
    }
    
    // Form submit validasyonu (eğer filtre formu varsa)
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            if (!validateDates()) {
                e.preventDefault();
                return false;
            }
            
            // Loading göster
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Filtreleniyor...';
            submitBtn.disabled = true;
            
            // Form submit edilirse loading'i geri al
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
    }
    
    // Tarih değişikliklerinde otomatik validasyon
    if (startDateInput && endDateInput) {
        [startDateInput, endDateInput].forEach(input => {
            input.addEventListener('change', validateDates);
        });
    }
    
    // Dropdown değişikliklerinde otomatik filtreleme (opsiyonel)
    if (outletDurumSelect) {
        outletDurumSelect.addEventListener('change', function() {
            // Eğer kullanıcı dropdown'dan seçim yaparsa otomatik filtreleme yapabilir
            // Bu özellik istenirse aktif edilebilir
            // document.getElementById('filterForm').submit();
        });
    }
    
    if (satisDurumuSelect) {
        satisDurumuSelect.addEventListener('change', function() {
            // Eğer kullanıcı dropdown'dan seçim yaparsa otomatik filtreleme yapabilir
            // Bu özellik istenirse aktif edilebilir
            // document.getElementById('filterForm').submit();
        });
    }
    
    // Bayi Ad Soyad dropdown değişikliklerinde personel kimlik no filtreleme
    if (bayiAdSoyadSelect) {
        bayiAdSoyadSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const altBayiSelect = document.getElementById('alt_bayi');
            
            if (selectedOption.value && selectedOption.getAttribute('data-personel-kimlik-no') && altBayiSelect) {
                // Seçilen bayinin personel_kimlik_no kodlarını al (virgülle ayrılmış)
                const personelKimlikNos = selectedOption.getAttribute('data-personel-kimlik-no').split(',');
                
                // Multi-select için tüm ilgili seçimleri temizle ve yeni seçimleri yap
                for (let i = 0; i < altBayiSelect.options.length; i++) {
                    altBayiSelect.options[i].selected = false;
                }
                
                if (personelKimlikNos.length > 0) {
                    // Tüm personel kimlik no'ları seç
                    personelKimlikNos.forEach(pkNo => {
                        const trimmedPkNo = pkNo.trim();
                        for (let i = 0; i < altBayiSelect.options.length; i++) {
                            if (altBayiSelect.options[i].value === trimmedPkNo) {
                                altBayiSelect.options[i].selected = true;
                            }
                        }
                    });
                    
                    // Bilgi mesajı göster
                    if (personelKimlikNos.length > 1) {
                        showToast(`Bayi seçildi: ${selectedOption.text.split('(')[0].trim()}. Bu bayiye kayıtlı ${personelKimlikNos.length} adet Personel Kimlik No otomatik seçildi.`, 'info');
                    } else {
                        showToast(`Bayi seçildi: ${selectedOption.text.split('(')[0].trim()}. Personel Kimlik No otomatik seçildi: ${personelKimlikNos[0].trim()}`, 'info');
                    }
                }
            } else if (!selectedOption.value && altBayiSelect) {
                // Bayi seçimi temizlenirse alt bayi'yi de temizle
                for (let i = 0; i < altBayiSelect.options.length; i++) {
                    altBayiSelect.options[i].selected = false;
                }
            }
        });
    }
    
    // Hakediş dönemi değişikliğinde otomatik tarih güncelleme (eğer filtre formu varsa)
    if (hakedisDonemiSelect) {
        hakedisDonemiSelect.addEventListener('change', function() {
            const selectedDonem = this.value;
            
            if (selectedDonem && hakedisDonemleri[selectedDonem] && startDateInput && endDateInput) {
                // Hakediş dönemi seçildiyse tarih alanlarını otomatik doldur ve readonly yap
                startDateInput.value = hakedisDonemleri[selectedDonem].start;
                endDateInput.value = hakedisDonemleri[selectedDonem].end;
                startDateInput.readOnly = true;
                endDateInput.readOnly = true;
                
                // Hızlı tarih butonlarını devre dışı bırak
                const quickDateBtns = document.querySelectorAll('[onclick^="setDateRange"]');
                quickDateBtns.forEach(btn => btn.disabled = true);
                
            } else if (startDateInput && endDateInput) {
                // Manuel seçim yapıldıysa tarih alanlarını düzenlenebilir yap
                startDateInput.readOnly = false;
                endDateInput.readOnly = false;
                
                // Hızlı tarih butonlarını aktif et
                const quickDateBtns = document.querySelectorAll('[onclick^="setDateRange"]');
                quickDateBtns.forEach(btn => btn.disabled = false);
            }
            
            // Otomatik form submit (opsiyonel)
            // document.getElementById('filterForm').submit();
        });
    }
    
    // Enter tuşu ile form submit
    if (startDateInput && endDateInput) {
        [startDateInput, endDateInput].forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    if (validateDates()) {
                        document.getElementById('filterForm').submit();
                    }
                }
            });
        });
    }
});

<!-- Yazdırma için CSS -->
const printStyles = `
    <style media="print">
        @page { margin: 1cm; }
        .btn-group, .card-header .btn { display: none !important; }
        .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
        .table { font-size: 12px; }
        .navbar, .breadcrumb { display: none !important; }
        .container-fluid { padding: 0 !important; }
        body { background: white !important; }
    </style>
`;
document.head.insertAdjacentHTML('beforeend', printStyles);

// Multi-select stil iyileştirme
const multiSelectStyles = `
    <style>
        #alt_bayi {
            min-height: 38px !important;
            height: auto !important;
            overflow-y: auto;
            max-height: 150px;
        }
        #alt_bayi option {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
        }
        #alt_bayi option:hover {
            background-color: #e9ecef;
        }
        #alt_bayi option:checked {
            background: linear-gradient(0deg, #0d6efd 0%, #0d6efd 100%);
            color: white;
            font-weight: 500;
        }
        #alt_bayi option:checked::before {
            content: "✓ ";
            font-weight: bold;
        }
    </style>
`;
document.head.insertAdjacentHTML('beforeend', multiSelectStyles);
</script>

<?php include '../../../includes/footer.php'; ?>
