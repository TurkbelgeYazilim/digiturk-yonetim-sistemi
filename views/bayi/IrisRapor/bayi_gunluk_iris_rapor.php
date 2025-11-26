<?php
$pageTitle = "Bayi Günlük Satış Adet";
$breadcrumbs = [
    ['title' => 'Bayi Günlük Satış Adet']
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

// Tarih filtresi kontrolü
$selectedDate = isset($_POST['selected_date']) && !empty($_POST['selected_date']) ? $_POST['selected_date'] : date('Y-m-d', strtotime('-1 day'));
$displayDate = date('d.m.Y', strtotime($selectedDate));

// Veritabanından veri çek
$bayiRapor = [];
$bayiOnayRapor = [];
$bayiKurulumRapor = [];
$bayiHaftalikRapor = [];
try {
    $conn = getDatabaseConnection();
    
    // BAYİ GÜNLÜK SATIŞ ADET sorgusu
    $sql = "
    DECLARE @selectedDate date = ?;
    
    WITH P AS (
        SELECT
            TALEBI_GIREN_PERSONEL_ALTBAYI AS [BAYİ İSMİ],
            ISNULL([ISP], 0)  AS ISP,
            ISNULL([NEO], 0)  AS NEO,
            ISNULL([UYDU], 0) AS UYDU,
            ISNULL([NEO], 0) + ISNULL([UYDU], 0) AS [TV Toplam]
        FROM (
            SELECT TALEBI_GIREN_PERSONEL_ALTBAYI, MEMO_KAYIT_TIPI
            FROM digiturk.iris_rapor
            WHERE TALEP_GIRIS_TARIHI >= @selectedDate
              AND TALEP_GIRIS_TARIHI < DATEADD(day, 1, @selectedDate)
        ) AS S
        PIVOT (
            COUNT(MEMO_KAYIT_TIPI)
            FOR MEMO_KAYIT_TIPI IN ([ISP], [NEO], [UYDU])
        ) AS PV
    )
    -- Yardımcı sıralama kolonları (_sort_total, _sort_tv) ekle
    , U AS (
        SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam],
               CAST(0 AS int) AS _sort_total,
               [TV Toplam]     AS _sort_tv
        FROM P
        UNION ALL
        SELECT N'Genel Toplam',
               SUM(ISP), SUM(NEO), SUM(UYDU),
               SUM(NEO) + SUM(UYDU),
               1 AS _sort_total,
               SUM(NEO) + SUM(UYDU) AS _sort_tv
        FROM P
    )
    -- Dış sarmalda sadece istediğin kolonları göster, sıralamayı yardımcılarla yap
    SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam]
    FROM U
    ORDER BY _sort_total, _sort_tv DESC, [BAYİ İSMİ];
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$selectedDate]);
    $bayiRapor = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // BAYİ GÜNLÜK ONAY ADET sorgusu
    $sqlOnay = "
    DECLARE @selectedDate date = ?;
    
    WITH P AS (
        SELECT
            TALEBI_GIREN_PERSONEL_ALTBAYI AS [BAYİ İSMİ],
            ISNULL([ISP], 0)  AS ISP,
            ISNULL([NEO], 0)  AS NEO,
            ISNULL([UYDU], 0) AS UYDU,
            ISNULL([NEO], 0) + ISNULL([UYDU], 0) AS [TV Toplam]
        FROM (
            SELECT TALEBI_GIREN_PERSONEL_ALTBAYI, MEMO_KAYIT_TIPI
            FROM digiturk.iris_rapor
            WHERE TALEP_GIRIS_TARIHI >= @selectedDate
              AND TALEP_GIRIS_TARIHI < DATEADD(day, 1, @selectedDate)
              AND TEYIT_DURUM = N'ONAYLANDI'
        ) AS S
        PIVOT (
            COUNT(MEMO_KAYIT_TIPI)
            FOR MEMO_KAYIT_TIPI IN ([ISP], [NEO], [UYDU])
        ) AS PV
    )
    , U AS (
        SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam],
               CAST(0 AS int) AS _sort_total,
               [TV Toplam]     AS _sort_tv
        FROM P
        UNION ALL
        SELECT N'Genel Toplam',
               SUM(ISP), SUM(NEO), SUM(UYDU),
               SUM(NEO) + SUM(UYDU),
               1 AS _sort_total,
               SUM(NEO) + SUM(UYDU) AS _sort_tv
        FROM P
    )
    SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam]
    FROM U
    ORDER BY _sort_total, _sort_tv DESC, [BAYİ İSMİ];
    ";
    
    $stmtOnay = $conn->prepare($sqlOnay);
    $stmtOnay->execute([$selectedDate]);
    $bayiOnayRapor = $stmtOnay->fetchAll(PDO::FETCH_ASSOC);
    
    // BAYİ GÜNLÜK KURULUM ADET sorgusu
    $sqlKurulum = "
    DECLARE @selectedDate date = ?;
    
    WITH P AS (
        SELECT
            TALEBI_GIREN_PERSONEL_ALTBAYI AS [BAYİ İSMİ],
            ISNULL([ISP], 0)  AS ISP,
            ISNULL([NEO], 0)  AS NEO,
            ISNULL([UYDU], 0) AS UYDU,
            ISNULL([NEO], 0) + ISNULL([UYDU], 0) AS [TV Toplam]
        FROM (
            SELECT TALEBI_GIREN_PERSONEL_ALTBAYI, MEMO_KAYIT_TIPI
            FROM digiturk.iris_rapor
            WHERE MEMO_KAPANIS_TARIHI >= @selectedDate
              AND MEMO_KAPANIS_TARIHI < DATEADD(day, 1, @selectedDate)
              AND SATIS_DURUMU = N'Tamamlandı'
        ) AS S
        PIVOT (
            COUNT(MEMO_KAYIT_TIPI)
            FOR MEMO_KAYIT_TIPI IN ([ISP], [NEO], [UYDU])
        ) AS PV
    )
    , U AS (
        SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam],
               CAST(0 AS int) AS _sort_total,
               [TV Toplam]     AS _sort_tv
        FROM P
        UNION ALL
        SELECT N'Genel Toplam',
               SUM(ISP), SUM(NEO), SUM(UYDU),
               SUM(NEO) + SUM(UYDU),
               1 AS _sort_total,
               SUM(NEO) + SUM(UYDU) AS _sort_tv
        FROM P
    )
    SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam]
    FROM U
    ORDER BY _sort_total, _sort_tv DESC, [BAYİ İSMİ];
    ";
    
    $stmtKurulum = $conn->prepare($sqlKurulum);
    $stmtKurulum->execute([$selectedDate]);
    $bayiKurulumRapor = $stmtKurulum->fetchAll(PDO::FETCH_ASSOC);
    
    // BAYİ HAFTALIK ORTALAMA sorgusu (son 7 gün ortalaması)
    $sqlHaftalik = "
    DECLARE @endDate date = ?;
    DECLARE @startDate date = DATEADD(day, -6, @endDate); -- 7 gün (bugün dahil)
    
    WITH P AS (
        SELECT
            TALEBI_GIREN_PERSONEL_ALTBAYI AS [BAYİ İSMİ],
            ISNULL([ISP], 0)  AS ISP,
            ISNULL([NEO], 0)  AS NEO,
            ISNULL([UYDU], 0) AS UYDU,
            ISNULL([NEO], 0) + ISNULL([UYDU], 0) AS [TV Toplam]
        FROM (
            SELECT TALEBI_GIREN_PERSONEL_ALTBAYI, MEMO_KAYIT_TIPI
            FROM digiturk.iris_rapor
            WHERE TALEP_GIRIS_TARIHI >= @startDate
              AND TALEP_GIRIS_TARIHI <= @endDate
        ) AS S
        PIVOT (
            COUNT(MEMO_KAYIT_TIPI)
            FOR MEMO_KAYIT_TIPI IN ([ISP], [NEO], [UYDU])
        ) AS PV
    )
    , U AS (
        SELECT [BAYİ İSMİ], 
               CAST(ISP / 7.0 AS DECIMAL(10,1)) AS ISP,
               CAST(NEO / 7.0 AS DECIMAL(10,1)) AS NEO,
               CAST(UYDU / 7.0 AS DECIMAL(10,1)) AS UYDU,
               CAST([TV Toplam] / 7.0 AS DECIMAL(10,1)) AS [TV Toplam],
               CAST(0 AS int) AS _sort_total,
               [TV Toplam] AS _sort_tv
        FROM P
        UNION ALL
        SELECT N'Haftalık Ortalama',
               CAST(SUM(ISP) / 7.0 AS DECIMAL(10,1)),
               CAST(SUM(NEO) / 7.0 AS DECIMAL(10,1)),
               CAST(SUM(UYDU) / 7.0 AS DECIMAL(10,1)),
               CAST((SUM(NEO) + SUM(UYDU)) / 7.0 AS DECIMAL(10,1)),
               1 AS _sort_total,
               SUM(NEO) + SUM(UYDU) AS _sort_tv
        FROM P
    )
    SELECT [BAYİ İSMİ], ISP, NEO, UYDU, [TV Toplam]
    FROM U
    ORDER BY _sort_total, _sort_tv DESC, [BAYİ İSMİ];
    ";
    
    $stmtHaftalik = $conn->prepare($sqlHaftalik);
    $stmtHaftalik->execute([$selectedDate]);
    $bayiHaftalikRapor = $stmtHaftalik->fetchAll(PDO::FETCH_ASSOC);
    
    // Tüm bayilerin listesini çıkar (Genel Toplam/Haftalık Ortalama hariç)
    $tumBayiler = [];
    foreach ([$bayiRapor, $bayiOnayRapor, $bayiKurulumRapor, $bayiHaftalikRapor] as $rapor) {
        foreach ($rapor as $row) {
            if (!in_array($row['BAYİ İSMİ'], ['Genel Toplam', 'Haftalık Ortalama']) && !in_array($row['BAYİ İSMİ'], $tumBayiler)) {
                $tumBayiler[] = $row['BAYİ İSMİ'];
            }
        }
    }
    sort($tumBayiler);
    
    // Her tablo için normalize et (eksik bayiler için boş satır ekle)
    function normalizeTable($rapor, $tumBayiler, $toplamBaslik) {
        $normalized = [];
        $mevcutBayiler = [];
        $toplamRow = null;
        
        // Mevcut bayileri topla ve toplam satırını ayrı tut
        foreach ($rapor as $row) {
            if ($row['BAYİ İSMİ'] === $toplamBaslik) {
                $toplamRow = $row;
            } else {
                $mevcutBayiler[$row['BAYİ İSMİ']] = $row;
            }
        }
        
        // Tüm bayiler için satır oluştur ve TV Toplam'a göre sırala
        $bayiSatirlari = [];
        foreach ($tumBayiler as $bayi) {
            if (isset($mevcutBayiler[$bayi])) {
                $bayiSatirlari[] = $mevcutBayiler[$bayi];
            } else {
                // Boş satır ekle
                $bayiSatirlari[] = [
                    'BAYİ İSMİ' => $bayi,
                    'ISP' => 0,
                    'NEO' => 0,
                    'UYDU' => 0,
                    'TV Toplam' => 0
                ];
            }
        }
        
        // TV Toplam değerine göre yüksekten düşüğe sırala
        usort($bayiSatirlari, function($a, $b) {
            return $b['TV Toplam'] <=> $a['TV Toplam'];
        });
        
        // Sıralı bayileri normalized array'e ekle
        $normalized = $bayiSatirlari;
        
        // Toplam satırını en sona ekle
        if ($toplamRow) {
            $normalized[] = $toplamRow;
        }
        
        return $normalized;
    }
    
    // Tabloları normalize et
    $bayiRapor = normalizeTable($bayiRapor, $tumBayiler, 'Genel Toplam');
    $bayiOnayRapor = normalizeTable($bayiOnayRapor, $tumBayiler, 'Genel Toplam');
    $bayiKurulumRapor = normalizeTable($bayiKurulumRapor, $tumBayiler, 'Genel Toplam');
    $bayiHaftalikRapor = normalizeTable($bayiHaftalikRapor, $tumBayiler, 'Haftalık Ortalama');
    
    // En son yüklenen dosyanın tarih-saat bilgisini al
    $sonDosyaTarih = '';
    try {
        $sqlSonDosya = "SELECT TOP 1 dosya_adi FROM digiturk.iris_rapor WHERE dosya_adi IS NOT NULL ORDER BY id DESC";
        $stmtSonDosya = $conn->prepare($sqlSonDosya);
        $stmtSonDosya->execute();
        $sonDosya = $stmtSonDosya->fetch(PDO::FETCH_ASSOC);
        
        if ($sonDosya && $sonDosya['dosya_adi']) {
            // "Talep Raporu - Tüm Kayıtlar-27.09.2025 09_50.csv" formatından tarih-saat çıkar
            if (preg_match('/-(\d{2}\.\d{2}\.\d{4})\s+(\d{2}_\d{2})\.csv$/', $sonDosya['dosya_adi'], $matches)) {
                $tarih = $matches[1]; // 27.09.2025
                $saat = str_replace('_', ':', $matches[2]); // 09:50
                $sonDosyaTarih = "Rapor Tarihi: " . $tarih . " " . $saat;
            }
        }
    } catch (Exception $e) {
        // Hata durumunda boş bırak
    }
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-shopping-cart me-2"></i>Bayi Günlük Satış Adet</h2>
                <div class="text-muted small">
                    Tarih: <?php echo date('d.m.Y H:i'); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            Sadece kendi bayinize ait raporları görüntüleyebilirsiniz.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tarih Filtresi -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="selected_date" class="form-label"><i class="fas fa-calendar-alt me-1"></i>Rapor Tarihi Seçin</label>
                            <input type="date" class="form-control" id="selected_date" name="selected_date" 
                                   value="<?php echo $selectedDate; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Raporu Göster
                            </button>
                        </div>
                        <div class="col-md-5">
                            <div class="d-flex gap-3 align-items-center justify-content-end">
                                <div class="text-end">
                                    <div class="d-flex align-items-center justify-content-end mb-1">
                                        <small class="text-muted me-2" id="report1"><?php echo $displayDate; ?> Bayi Özet Raporu</small>
                                        <button type="button" class="btn btn-sm btn-outline-secondary p-1" onclick="copyToClipboard('report1')" title="Kopyala">
                                            <i class="fas fa-copy" style="font-size: 10px;"></i>
                                        </button>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-end">
                                        <small class="text-muted me-2" id="report2"><?php echo $displayDate; ?> Kapanış Bayi Özet Raporu</small>
                                        <button type="button" class="btn btn-sm btn-outline-secondary p-1" onclick="copyToClipboard('report2')" title="Kopyala">
                                            <i class="fas fa-copy" style="font-size: 10px;"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if ($sayfaYetkileri['duzenle'] == 1 && !isset($error) && !empty($bayiRapor)): ?>
                                    <button onclick="shareScreenshot()" class="btn btn-success" title="Raporu Panoya Kopyala">
                                        <i class="fas fa-copy me-1"></i>Kopyala
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Tüm Tablolar Container -->
    <div id="report-tables">
        <!-- Üst Sıra: Satış ve Kurulum Tabloları -->
        <div class="row">
        <!-- BAYİ GÜNLÜK SATIŞ ADET Tablosu -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white" style="background-color: #4d93d9;">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>BAYİ GÜNLÜK SATIŞ ADET
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center" style="width: 30%;">BAYİ İSMİ</th>
                                        <th class="text-center">ISP</th>
                                        <th class="text-center">NEO</th>
                                        <th class="text-center">UYDU</th>
                                        <th class="text-center">TV TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bayiRapor)): ?>
                                        <?php foreach ($bayiRapor as $row): ?>
                                            <tr <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;" class="fw-bold"' : ''; ?>>
                                                <td <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo htmlspecialchars($row['BAYİ İSMİ']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['ISP']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['NEO']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['UYDU']); ?></td>
                                                <td class="text-center fw-bold" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['TV Toplam']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fas fa-chart-bar fa-3x mb-3 d-block"></i>
                                                <h5>Veri bulunamadı</h5>
                                                <p>Seçilen tarih aralığında satış verisi bulunmuyor.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- BAYİ GÜNLÜK KURULUM ADET Tablosu -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white" style="background-color: #4d93d9;">
                    <h5 class="mb-0">
                        <i class="fas fa-cogs me-2"></i>BAYİ GÜNLÜK KURULUM ADET
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center" style="width: 30%;">BAYİ İSMİ</th>
                                        <th class="text-center">ISP</th>
                                        <th class="text-center">NEO</th>
                                        <th class="text-center">UYDU</th>
                                        <th class="text-center">TV TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bayiKurulumRapor)): ?>
                                        <?php foreach ($bayiKurulumRapor as $row): ?>
                                            <tr <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;" class="fw-bold"' : ''; ?>>
                                                <td <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo htmlspecialchars($row['BAYİ İSMİ']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['ISP']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['NEO']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['UYDU']); ?></td>
                                                <td class="text-center fw-bold" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['TV Toplam']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fas fa-chart-bar fa-3x mb-3 d-block"></i>
                                                <h5>Veri bulunamadı</h5>
                                                <p>Seçilen tarih aralığında kurulum verisi bulunmuyor.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alt Sıra: Onay ve Dördüncü Tablo -->
    <div class="row mt-4">
        <!-- BAYİ GÜNLÜK ONAY ADET Tablosu -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white" style="background-color: #4d93d9;">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle me-2"></i>BAYİ GÜNLÜK ONAY ADET
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center" style="width: 30%;">BAYİ İSMİ</th>
                                        <th class="text-center">ISP</th>
                                        <th class="text-center">NEO</th>
                                        <th class="text-center">UYDU</th>
                                        <th class="text-center">TV TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bayiOnayRapor)): ?>
                                        <?php foreach ($bayiOnayRapor as $row): ?>
                                            <tr <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;" class="fw-bold"' : ''; ?>>
                                                <td <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo htmlspecialchars($row['BAYİ İSMİ']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['ISP']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['NEO']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['UYDU']); ?></td>
                                                <td class="text-center fw-bold" <?php echo $row['BAYİ İSMİ'] === 'Genel Toplam' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['TV Toplam']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fas fa-chart-bar fa-3x mb-3 d-block"></i>
                                                <h5>Veri bulunamadı</h5>
                                                <p>Seçilen tarih aralığında onaylı satış verisi bulunmuyor.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- BAYİ HAFTALIK ORTALAMA Tablosu -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header text-white" style="background-color: #4d93d9;">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>BAYİ HAFTALIK ORTALAMA
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-center" style="width: 30%;">BAYİ İSMİ</th>
                                        <th class="text-center">ISP</th>
                                        <th class="text-center">NEO</th>
                                        <th class="text-center">UYDU</th>
                                        <th class="text-center">TV TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bayiHaftalikRapor)): ?>
                                        <?php foreach ($bayiHaftalikRapor as $row): ?>
                                            <tr <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;" class="fw-bold"' : ''; ?>>
                                                <td <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo htmlspecialchars($row['BAYİ İSMİ']); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['ISP'], 1); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['NEO'], 1); ?></td>
                                                <td class="text-center" <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['UYDU'], 1); ?></td>
                                                <td class="text-center fw-bold" <?php echo $row['BAYİ İSMİ'] === 'Haftalık Ortalama' ? 'style="background-color: #104861; color: white;"' : ''; ?>><?php echo number_format($row['TV Toplam'], 1); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="fas fa-chart-line fa-3x mb-3 d-block"></i>
                                                <h5>Veri bulunamadı</h5>
                                                <p>Son 7 günde haftalık ortalama verisi bulunmuyor.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- Report Tables Container Sonu -->
</div>

<!-- HTML2Canvas CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
function shareScreenshot() {
    const element = document.getElementById('report-tables');
    const button = event.target.closest('button');
    
    // Butonu devre dışı bırak ve loading göster
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Hazırlanıyor...';
    
    // Geçici container oluştur
    const container = document.createElement('div');
    container.style.cssText = 'position: absolute; top: -9999px; left: -9999px; background: white; padding: 20px; width: 1200px; position: relative;';
    container.appendChild(element.cloneNode(true));
    
    // Sağ alt köşeye tarih bilgisi ekle
    <?php if (!empty($sonDosyaTarih)): ?>
    const dateOverlay = document.createElement('div');
    dateOverlay.style.cssText = 'position: absolute; bottom: -2px; right: 10px; background: rgba(0,0,0,0.7); color: white; padding: 8px 12px; border-radius: 5px; font-size: 12px; font-family: Arial, sans-serif;';
    dateOverlay.textContent = '<?php echo $sonDosyaTarih; ?>';
    container.appendChild(dateOverlay);
    <?php endif; ?>
    
    document.body.appendChild(container);
    
    html2canvas(container, {
        width: 1200,
        height: container.scrollHeight + 40,
        backgroundColor: '#ffffff',
        scale: 2,
        useCORS: true,
        allowTaint: true,
        scrollX: 0,
        scrollY: 0
    }).then(canvas => {
        // Geçici container'ı kaldır
        document.body.removeChild(container);
        
        // Canvas'ı blob'a dönüştür
        canvas.toBlob(blob => {
            // Doğrudan panoya kopyala (sadece panoya kopyalama için native share'i atlıyoruz)
            if (navigator.clipboard && window.ClipboardItem) {
                const item = new ClipboardItem({ 'image/png': blob });
                navigator.clipboard.write([item]).then(() => {
                    // Başarılı kopyalama mesajı göster
                    const alertDiv = document.createElement('div');
                    alertDiv.style.cssText = `
                        position: fixed; top: 20px; right: 20px; z-index: 10000; 
                        background: #28a745; color: white; padding: 15px 20px; 
                        border-radius: 8px; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                        animation: slideIn 0.3s ease-out;
                    `;
                    alertDiv.innerHTML = '✅ Rapor panoya kopyalandı! WhatsApp Web\'te Ctrl+V ile yapıştırabilirsiniz.';
                    
                    // CSS animasyonu ekle
                    if (!document.getElementById('slideInStyle')) {
                        const style = document.createElement('style');
                        style.id = 'slideInStyle';
                        style.textContent = '@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }';
                        document.head.appendChild(style);
                    }
                    
                    document.body.appendChild(alertDiv);
                    
                    // 4 saniye sonra mesajı kaldır
                    setTimeout(() => {
                        if (document.body.contains(alertDiv)) {
                            alertDiv.style.animation = 'slideIn 0.3s ease-out reverse';
                            setTimeout(() => document.body.removeChild(alertDiv), 300);
                        }
                    }, 4000);
                    
                }).catch(err => {
                    console.error('Panoya kopyalama hatası:', err);
                    alert('Tarayıcınız panoya görüntü kopyalamayı desteklemiyor. Lütfen Chrome veya Edge kullanın.');
                });
            } else {
                alert('Tarayıcınız panoya görüntü kopyalamayı desteklemiyor. Lütfen Chrome veya Edge kullanın.');
            }
            
            // Butonu normale döndür
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-copy me-1"></i>Kopyala';
        }, 'image/png');
    }).catch(error => {
        console.error('Screenshot hatası:', error);
        alert('Ekran görüntüsü alınamadı. Lütfen tekrar deneyin.');
        
        // Geçici container'ı temizle
        if (document.body.contains(container)) {
            document.body.removeChild(container);
        }
        
        // Butonu normale döndür
        button.disabled = false;
        button.innerHTML = '<i class="fab fa-whatsapp me-1"></i>Paylaş';
    });
}

// Kopyalama fonksiyonu
function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    const text = element.textContent;
    
    navigator.clipboard.writeText(text).then(function() {
        // Başarılı kopyalama feedback'i
        const originalIcon = element.parentElement.querySelector('i');
        const originalClass = originalIcon.className;
        
        // İkonu geçici olarak değiştir
        originalIcon.className = 'fas fa-check text-success';
        
        // 2 saniye sonra eski haline döndür
        setTimeout(() => {
            originalIcon.className = originalClass;
        }, 2000);
    }).catch(function(err) {
        console.error('Kopyalama hatası: ', err);
        alert('Metin kopyalanamadı. Lütfen manuel olarak kopyalayın.');
    });
}
</script>

<?php include '../../../includes/footer.php'; ?>
