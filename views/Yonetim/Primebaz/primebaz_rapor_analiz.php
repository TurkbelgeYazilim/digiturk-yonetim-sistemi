<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Auth kontrolü
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
        'kendi_kullanicini_gor' => 0,
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

$pageTitle = 'Primebaz Rapor Analizi';
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '#'],
    ['title' => 'Primebaz', 'url' => '#'],
    ['title' => 'Rapor Analizi']
];

// Hata ve başarı mesajları
$message = '';
$messageType = '';

try {
    $conn = getDatabaseConnection();
    
    // Önce primebaz_rapor tablosunun var olup olmadığını kontrol et
    $tableCheckSQL = "
        SELECT COUNT(*) as table_exists 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME = 'primebaz_rapor'
    ";
    
    $tableCheckStmt = $conn->prepare($tableCheckSQL);
    $tableCheckStmt->execute();
    $tableExists = $tableCheckStmt->fetch(PDO::FETCH_ASSOC)['table_exists'] > 0;
    
    if (!$tableExists) {
        throw new Exception('Primebaz rapor tablosu bulunamadı. Önce bir rapor yüklemeniz gerekiyor.');
    }
    
    // bayi_hakedis_prim_donem_id kolonunun var olup olmadığını kontrol et ve yoksa ekle
    $columnCheckSQL = "
        SELECT COUNT(*) as column_exists 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'primebaz_rapor' AND COLUMN_NAME = 'bayi_hakedis_prim_donem_id'
    ";
    
    $columnCheckStmt = $conn->prepare($columnCheckSQL);
    $columnCheckStmt->execute();
    $columnExists = $columnCheckStmt->fetch(PDO::FETCH_ASSOC)['column_exists'] > 0;
    
    if (!$columnExists) {
        // Kolonu ekle
        $addColumnSQL = "ALTER TABLE primebaz_rapor ADD bayi_hakedis_prim_donem_id INT NULL";
        $conn->exec($addColumnSQL);
    }
    
    // users_bayi tablosunun var olup olmadığını kontrol et
    $usersBayiCheckSQL = "
        SELECT COUNT(*) as table_exists 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME = 'users_bayi'
    ";
    
    $usersBayiCheckStmt = $conn->prepare($usersBayiCheckSQL);
    $usersBayiCheckStmt->execute();
    $usersBayiExists = $usersBayiCheckStmt->fetch(PDO::FETCH_ASSOC)['table_exists'] > 0;
    
    if (!$usersBayiExists) {
        throw new Exception('Kullanıcı-bayi eşleştirme tablosu bulunamadı. Sistem yöneticisiyle iletişime geçin.');
    }
    
    // users_bayi tablosunun var olup olmadığını kontrol et
    $usersBayiCheckSQL = "
        SELECT COUNT(*) as table_exists 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME = 'users_bayi'
    ";
    
    $usersBayiCheckStmt = $conn->prepare($usersBayiCheckSQL);
    $usersBayiCheckStmt->execute();
    $usersBayiExists = $usersBayiCheckStmt->fetch(PDO::FETCH_ASSOC)['table_exists'] > 0;
    
    if (!$usersBayiExists) {
        throw new Exception('Kullanıcı-bayi eşleştirme tablosu bulunamadı. Sistem yöneticisiyle iletişime geçin.');
    }
    
    // Ana SQL sorgusu - kendi kullanıcısını görme yetkisi kontrolü
    $sql = "
        SELECT 
            pr.memo_acan_personel_kimlik,
            CONCAT(u.first_name, ' ', u.last_name) AS personel_adi,
            ub.iris_altbayi,
            pr.bayi_adi,
            pr.dosya_adi,
            ISNULL(bhpd.donem_adi, 'Dönem Tanımlanmamış') AS prim_donemi,
            COUNT(*) AS toplam_adet,
            SUM(CASE WHEN pr.uyelik_turu = 'ISP'  THEN 1 ELSE 0 END) AS ISP_adet,
            SUM(CASE WHEN pr.uyelik_turu = 'NEO'  THEN 1 ELSE 0 END) AS NEO_adet,
            SUM(CASE WHEN pr.uyelik_turu = 'UYDU' THEN 1 ELSE 0 END) AS UYDU_adet,
            SUM(ISNULL(pr.odenecek_prim, 0)) AS toplam_odenecek_prim
        FROM primebaz_rapor pr
        INNER JOIN users_bayi ub 
            ON pr.memo_acan_personel_kimlik = ub.personel_kimlik_no
        INNER JOIN users u 
            ON ub.user_id = u.id
        LEFT JOIN digiturk.bayi_hakedis_prim_donem bhpd 
            ON pr.bayi_hakedis_prim_donem_id = bhpd.id
        WHERE ISNULL(pr.odenecek_prim, 0) > 0";
    
    // Kendi kullanıcısını görme yetkisi kontrolü
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $sql .= " AND ub.user_id = ?";
    }
    
    $sql .= "
        GROUP BY 
            pr.memo_acan_personel_kimlik, 
            u.first_name, 
            u.last_name, 
            ub.iris_altbayi, 
            pr.bayi_adi,
            pr.dosya_adi,
            bhpd.donem_adi
        ORDER BY toplam_adet DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $stmt->execute([$currentUser['id']]);
    } else {
        $stmt->execute();
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Özet istatistikler - kendi kullanıcısını görme yetkisi kontrolü
    $summarySQL = "
        SELECT 
            COUNT(*) as toplam_kayit,
            COUNT(DISTINCT pr.memo_acan_personel_kimlik) as personel_sayisi,
            COUNT(DISTINCT pr.bayi_adi) as bayi_sayisi,
            COUNT(DISTINCT pr.dosya_adi) as dosya_sayisi,
            SUM(CASE WHEN pr.uyelik_turu = 'ISP' THEN 1 ELSE 0 END) as toplam_isp,
            SUM(CASE WHEN pr.uyelik_turu = 'NEO' THEN 1 ELSE 0 END) as toplam_neo,
            SUM(CASE WHEN pr.uyelik_turu = 'UYDU' THEN 1 ELSE 0 END) as toplam_uydu,
            SUM(ISNULL(pr.odenecek_prim, 0)) as toplam_odenecek_prim_tutari
        FROM primebaz_rapor pr";
    
    // Kendi kullanıcısını görme yetkisi kontrolü
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $summarySQL .= "
        INNER JOIN users_bayi ub ON pr.memo_acan_personel_kimlik = ub.personel_kimlik_no
        WHERE ISNULL(pr.odenecek_prim, 0) > 0 AND ub.user_id = ?";
    } else {
        $summarySQL .= "
        WHERE ISNULL(pr.odenecek_prim, 0) > 0";
    }
    
    $summaryStmt = $conn->prepare($summarySQL);
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $summaryStmt->execute([$currentUser['id']]);
    } else {
        $summaryStmt->execute();
    }
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // En son yüklenen dosya bilgisi - kendi kullanıcısını görme yetkisi kontrolü
    $lastFileSQL = "
        SELECT TOP 1 pr.dosya_adi, pr.yuklenme_tarihi, COUNT(*) as kayit_sayisi
        FROM primebaz_rapor pr";
    
    // Kendi kullanıcısını görme yetkisi kontrolü
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $lastFileSQL .= "
        INNER JOIN users_bayi ub ON pr.memo_acan_personel_kimlik = ub.personel_kimlik_no
        WHERE ISNULL(pr.odenecek_prim, 0) > 0 AND ub.user_id = ?";
    } else {
        $lastFileSQL .= "
        WHERE ISNULL(pr.odenecek_prim, 0) > 0";
    }
    
    $lastFileSQL .= "
        GROUP BY pr.dosya_adi, pr.yuklenme_tarihi
        ORDER BY pr.yuklenme_tarihi DESC
    ";
    
    $lastFileStmt = $conn->prepare($lastFileSQL);
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $lastFileStmt->execute([$currentUser['id']]);
    } else {
        $lastFileStmt->execute();
    }
    $lastFile = $lastFileStmt->fetch(PDO::FETCH_ASSOC);
    
    // Prim dönemleri listesi (id sırasına göre) - kendi kullanıcısını görme yetkisi kontrolü
    $primDonemlerSQL = "
        SELECT DISTINCT bhpd.id, bhpd.donem_adi
        FROM digiturk.bayi_hakedis_prim_donem bhpd
        INNER JOIN primebaz_rapor pr ON pr.bayi_hakedis_prim_donem_id = bhpd.id";
    
    // Kendi kullanıcısını görme yetkisi kontrolü
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $primDonemlerSQL .= "
        INNER JOIN users_bayi ub ON pr.memo_acan_personel_kimlik = ub.personel_kimlik_no
        WHERE bhpd.durum = 'AKTIF' AND ISNULL(pr.odenecek_prim, 0) > 0 AND ub.user_id = ?";
    } else {
        $primDonemlerSQL .= "
        WHERE bhpd.durum = 'AKTIF' AND ISNULL(pr.odenecek_prim, 0) > 0";
    }
    
    $primDonemlerSQL .= "
        ORDER BY bhpd.id
    ";
    
    $primDonemlerStmt = $conn->prepare($primDonemlerSQL);
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $primDonemlerStmt->execute([$currentUser['id']]);
    } else {
        $primDonemlerStmt->execute();
    }
    $primDonemler = $primDonemlerStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = 'Veritabanı hatası: ' . $e->getMessage();
    $messageType = 'danger';
    $results = [];
    $summary = [];
    $lastFile = null;
    $primDonemler = [];
}

include '../../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <!-- Başlık -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    Primebaz Rapor Analizi
                </h4>
            </div>
            <div class="card-body">
                <p class="card-text text-muted">
                    Personel Kimlik No bazında üyelik türü analizi ve detaylı rapor görüntüleme sistemi
                </p>
            </div>
        </div>

        <!-- Hata/Başarı Mesajları -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $messageType === 'danger' ? 'exclamation-triangle' : 'check-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Özet İstatistikler -->
        <?php if (!empty($summary)): ?>
        <div class="row mb-4" id="summaryStats">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-primary fw-bold" id="stat-toplam-kayit">
                            <?php echo number_format($summary['toplam_kayit'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">Toplam Girilen  İş</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-primary fw-bold" id="stat-toplam-kayit">
                            <?php echo number_format($summary['toplam_kayit'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">Toplam Hakedilen  İş</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-info fw-bold" id="stat-bayi-sayisi">
                            <?php echo number_format($summary['bayi_sayisi'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">Bayi Sayısı</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-warning fw-bold" id="stat-dosya-sayisi">
                            <?php echo number_format($summary['dosya_sayisi'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">Dosya Sayısı</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Üyelik Türü Dağılımı -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-danger fw-bold" id="stat-toplam-isp">
                            <?php echo number_format($summary['toplam_isp'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">ISP Üyelikleri</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-success fw-bold" id="stat-toplam-neo">
                            <?php echo number_format($summary['toplam_neo'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">NEO Üyelikleri</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-primary fw-bold" id="stat-toplam-uydu">
                            <?php echo number_format($summary['toplam_uydu'] ?? 0); ?>
                        </div>
                        <div class="text-muted small">UYDU Üyelikleri</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center">
                        <div class="display-6 text-warning fw-bold" id="stat-toplam-prim">
                            <?php echo number_format($summary['toplam_odenecek_prim_tutari'] ?? 0, 2); ?> ₺
                        </div>
                        <div class="text-muted small">Toplam Ödenecek Prim</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Kendi kullanıcısını görme yetkisi uyarısı -->
        <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Sadece kendi personellerinize ait kayıtları görüntüleyebilirsiniz.
        </div>
        <?php endif; ?>

        <!-- Son Yüklenen Dosya Bilgisi -->
        <?php if (!empty($lastFile)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Son Yüklenen Dosya:</strong> 
            <?php echo htmlspecialchars($lastFile['dosya_adi']); ?> 
            <small class="text-muted">
                (<?php echo date('d.m.Y H:i', strtotime($lastFile['yuklenme_tarihi'])); ?> - 
                <?php echo number_format($lastFile['kayit_sayisi']); ?> kayıt)
            </small>
        </div>
        <?php endif; ?>

        <!-- Filtreler -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="card-title mb-0">
                    <i class="fas fa-filter me-2"></i>
                    Filtreler
                </h6>
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Sadece ödenecek primi 0'dan büyük olan kayıtlar gösterilmektedir.
                </small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="filterPrimDonem" class="form-label">Prim Dönemi</label>
                        <select class="form-select" id="filterPrimDonem">
                            <option value="">Tüm Dönemler</option>
                            <?php if (!empty($primDonemler)): ?>
                                <?php foreach ($primDonemler as $donem): ?>
                                    <option value="<?php echo htmlspecialchars($donem['donem_adi']); ?>">
                                        <?php echo htmlspecialchars($donem['donem_adi']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php 
                            // Dönem tanımlanmamış kayıtları için
                            if (!empty($results)): 
                                $hasUndefinedPeriod = false;
                                foreach ($results as $result) {
                                    if (($result['prim_donemi'] ?? '') === 'Dönem Tanımlanmamış') {
                                        $hasUndefinedPeriod = true;
                                        break;
                                    }
                                }
                                if ($hasUndefinedPeriod): 
                            ?>
                                <option value="Dönem Tanımlanmamış">Dönem Tanımlanmamış</option>
                            <?php 
                                endif;
                            endif; 
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filterPersonel" class="form-label">Bayi Personel Adı</label>
                        <select class="form-select" id="filterPersonel">
                            <option value="">Tüm Personeller</option>
                            <?php if (!empty($results)): ?>
                                <?php 
                                $personelList = array_unique(array_column($results, 'personel_adi'));
                                sort($personelList);
                                foreach ($personelList as $personel): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($personel); ?>">
                                        <?php echo htmlspecialchars($personel); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filterDosya" class="form-label">Dosya Adı</label>
                        <select class="form-select" id="filterDosya">
                            <option value="">Tüm Dosyalar</option>
                            <?php if (!empty($results)): ?>
                                <?php 
                                $dosyaList = array_unique(array_column($results, 'dosya_adi'));
                                sort($dosyaList);
                                foreach ($dosyaList as $dosya): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($dosya); ?>">
                                        <?php echo htmlspecialchars($dosya); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary" id="clearFilters">
                            <i class="fas fa-times me-1"></i>
                            Filtreleri Temizle
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ana Tablo -->
        <div class="card">
            <div class="card-header bg-light">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-table me-2"></i>
                            Personel Bazında Analiz Sonuçları
                        </h5>
                    </div>
                    <div class="col-auto">
                        <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                        <button class="btn btn-success btn-sm" id="exportExcel">
                            <i class="fas fa-file-excel me-1"></i>
                            Excel'e Aktar
                        </button>
                        <button class="btn btn-info btn-sm" id="printTable">
                            <i class="fas fa-print me-1"></i>
                            Yazdır
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($results)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Henüz analiz edilecek veri bulunmuyor</h5>
                        <p class="text-muted">Primebaz raporu yüklemek için <a href="primebaz_rapor_yukle.php" class="text-decoration-none">buraya tıklayın</a></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped" id="analysisTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Prim Dönemi</th>
                                    <th>Personel Kimlik No</th>
                                    <th>Bayi Personel Adı</th>
                                    <th>Bayi Adı</th>
                                    <th>Ana Bayi Adı</th>
                                    <th>Dosya Adı</th>
                                    <th class="text-center">Toplam</th>
                                    <th class="text-center">ISP</th>
                                    <th class="text-center">NEO</th>
                                    <th class="text-center">UYDU</th>
                                    <th class="text-center">Ödenecek Prim</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $row): ?>
                                <tr>
                                    <td class="text-center text-muted">
                                        <?php echo $index + 1; ?>
                                    </td>
                                    <td data-filter="<?php echo htmlspecialchars($row['prim_donemi'] ?? 'Dönem Tanımlanmamış'); ?>">
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($row['prim_donemi'] ?? 'Dönem Tanımlanmamış'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($row['memo_acan_personel_kimlik'] ?? '-'); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['personel_adi'] ?? '-'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($row['iris_altbayi'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['bayi_adi'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($row['dosya_adi'] ?? '-'); ?>
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary fs-6">
                                            <?php echo number_format($row['toplam_adet'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if (($row['ISP_adet'] ?? 0) > 0): ?>
                                            <span class="badge bg-danger">
                                                <?php echo number_format($row['ISP_adet']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (($row['NEO_adet'] ?? 0) > 0): ?>
                                            <span class="badge bg-success">
                                                <?php echo number_format($row['NEO_adet']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (($row['UYDU_adet'] ?? 0) > 0): ?>
                                            <span class="badge bg-info">
                                                <?php echo number_format($row['UYDU_adet']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning text-dark fs-6">
                                            <?php echo number_format($row['toplam_odenecek_prim'] ?? 0, 2); ?> ₺
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Sayfalama bilgisi -->
                    <div class="mt-3">
                        <small class="text-muted">
                            Toplam <?php echo count($results); ?> sonuç gösteriliyor
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- DataTables CSS ve JS -->
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.2.3/css/buttons.bootstrap5.min.css">

<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.3/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // DataTable başlatma
    var table = $('#analysisTable').DataTable({
        dom: 'Bfrtip',
        buttons: [],
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/tr.json'
        },
        order: [[7, 'desc']], // Toplam adet sütununa göre sırala
        pageLength: 50,
        responsive: true,
        columnDefs: [
            { targets: [0, 7, 8, 9, 10, 11], className: 'text-center' },
            { targets: [2], className: 'font-monospace' }
        ]
    });
    
    // Filtre fonksiyonları
    function updateSummaryStats() {
        // Filtrelenmiş satırları al
        var filteredData = table.rows({ filter: 'applied' }).data().toArray();
        
        var stats = {
            toplamKayit: 0,
            personelSayisi: new Set(),
            bayiSayisi: new Set(),
            dosyaSayisi: new Set(),
            toplamIsp: 0,
            toplamNeo: 0,
            toplamUydu: 0,
            toplamPrim: 0
        };
        
        // Her filtrelenmiş satır için istatistikleri hesapla
        filteredData.forEach(function(row) {
            // Toplam kayıt sayısı (her satır bir kayıt)
            stats.toplamKayit += parseInt($(row[7]).text().replace(/,/g, '')) || 0;
            
            // Benzersiz personel, bayi ve dosya sayıları
            stats.personelSayisi.add($(row[3]).text().trim());
            stats.bayiSayisi.add($(row[5]).text().trim());
            stats.dosyaSayisi.add($(row[6]).text().trim());
            
            // Üyelik türü toplamları
            stats.toplamIsp += parseInt($(row[8]).text().replace(/,/g, '')) || 0;
            stats.toplamNeo += parseInt($(row[9]).text().replace(/,/g, '')) || 0;
            stats.toplamUydu += parseInt($(row[10]).text().replace(/,/g, '')) || 0;
            
            // Toplam prim tutarı
            var primText = $(row[11]).text().replace(/₺|,/g, '').trim();
            stats.toplamPrim = (stats.toplamPrim || 0) + (parseFloat(primText) || 0);
        });
        
        // İstatistikleri güncelle
        $('#stat-toplam-kayit').text(stats.toplamKayit.toLocaleString('tr-TR'));
        $('#stat-personel-sayisi').text(stats.personelSayisi.size.toLocaleString('tr-TR'));
        $('#stat-bayi-sayisi').text(stats.bayiSayisi.size.toLocaleString('tr-TR'));
        $('#stat-dosya-sayisi').text(stats.dosyaSayisi.size.toLocaleString('tr-TR'));
        $('#stat-toplam-isp').text(stats.toplamIsp.toLocaleString('tr-TR'));
        $('#stat-toplam-neo').text(stats.toplamNeo.toLocaleString('tr-TR'));
        $('#stat-toplam-uydu').text(stats.toplamUydu.toLocaleString('tr-TR'));
        $('#stat-toplam-prim').text((stats.toplamPrim || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺');
    }
    
    function applyFilters() {
        var primDonemFilter = $('#filterPrimDonem').val();
        var personelFilter = $('#filterPersonel').val();
        var dosyaFilter = $('#filterDosya').val();
        
        console.log('Prim Dönem Filtresi:', primDonemFilter); // Debug için
        
        // Tüm filtreleri temizle
        table.columns().search('');
        
        // Prim Dönemi filtresi
        if (primDonemFilter !== '') {
            // Önce data-filter attribute'unda ara
            table.column(1).search(primDonemFilter, false, false);
        }
        
        // Personel filtresi - tam eşleşme
        if (personelFilter !== '') {
            table.column(3).search('^' + $.fn.dataTable.util.escapeRegex(personelFilter) + '$', true, false);
        }
        
        // Dosya filtresi - tam eşleşme
        if (dosyaFilter !== '') {
            table.column(6).search('^' + $.fn.dataTable.util.escapeRegex(dosyaFilter) + '$', true, false);
        }
        
        table.draw();
        
        // İstatistikleri güncelle
        updateSummaryStats();
    }
    
    // Filtre butonları
    $('#clearFilters').click(function() {
        $('#filterPrimDonem').val('');
        $('#filterPersonel').val('');
        $('#filterDosya').val('');
        table.columns().search('').draw();
        // İstatistikleri güncelle
        updateSummaryStats();
    });
    
    // Filtre değişikliklerinde otomatik uygula
    $('#filterPrimDonem, #filterPersonel, #filterDosya').change(function() {
        applyFilters();
    });
    
    // Excel Export
    $('#exportExcel').click(function() {
        var data = [];
        
        // Başlıkları ekle
        data.push([
            'Sıra',
            'Prim Dönemi',
            'Personel Kimlik No',
            'Bayi Personel Adı',
            'Bayi Adı',
            'Ana Bayi Adı',
            'Dosya Adı',
            'Toplam Adet',
            'ISP Adet',
            'NEO Adet',
            'UYDU Adet',
            'Ödenecek Prim (₺)'
        ]);
        
        // Filtrelenmiş veri satırlarını ekle
        table.rows({ filter: 'applied' }).data().each(function(row, index) {
            data.push([
                index + 1,
                $(row[1]).text(),
                $(row[2]).text(),
                $(row[3]).text(),
                $(row[4]).text(),
                $(row[5]).text(),
                $(row[6]).text(),
                $(row[7]).text(),
                $(row[8]).text() || '0',
                $(row[9]).text() || '0',
                $(row[10]).text() || '0',
                $(row[11]).text() || '0'
            ]);
        });
        
        // Excel dosyası oluştur ve indir
        var ws = XLSX.utils.aoa_to_sheet(data);
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Primebaz Analiz");
        XLSX.writeFile(wb, 'primebaz_rapor_analizi_' + new Date().toISOString().split('T')[0] + '.xlsx');
    });
    
    // Print function
    $('#printTable').click(function() {
        window.print();
    });
    
    // Tablo satırlarına hover efekti
    $('#analysisTable tbody tr').hover(
        function() {
            $(this).addClass('table-active');
        },
        function() {
            $(this).removeClass('table-active');
        }
    );
});
</script>

<!-- Excel Export için SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- Print için özel CSS -->
<style>
@media print {
    .card-header .col-auto,
    .breadcrumb,
    .btn,
    .alert {
        display: none !important;
    }
    
    .table {
        font-size: 10px !important;
    }
    
    .badge {
        background-color: #000 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}

/* Özel stiller */
.table th {
    font-weight: 600;
    font-size: 0.875rem;
}

.table td {
    vertical-align: middle;
}

.badge {
    font-size: 0.75rem;
    padding: 0.375em 0.5em;
}

code {
    background-color: #f8f9fa;
    color: #495057;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}
</style>

<?php include '../../../includes/footer.php'; ?>