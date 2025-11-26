<?php
// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// Sayfa başlığını veritabanından al
$pageTitle = "Abone Bul"; // Varsayılan
try {
    $conn = getDatabaseConnection();
    $currentPageUrl = basename($_SERVER['PHP_SELF']);
    $sayfaSql = "SELECT sayfa_adi FROM dbo.tanim_sayfalar WHERE sayfa_url = ? AND durum = 1";
    $sayfaStmt = $conn->prepare($sayfaSql);
    $sayfaStmt->execute([$currentPageUrl]);
    $sayfaResult = $sayfaStmt->fetch(PDO::FETCH_ASSOC);
    if ($sayfaResult && !empty($sayfaResult['sayfa_adi'])) {
        $pageTitle = $sayfaResult['sayfa_adi'];
    }
} catch (Exception $e) {
    error_log("Sayfa başlığı alınamadı: " . $e->getMessage());
}

$breadcrumbs = [
    ['title' => $pageTitle]
];

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

// Abone arama işlemi
$aboneKayitlar = [];
$musteriNo = '';
$aramaYapildi = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['musteri_no']) && !empty($_POST['musteri_no'])) {
    $musteriNo = trim($_POST['musteri_no']);
    $aramaYapildi = true;
    
    try {
        $conn = getDatabaseConnection();
        
        // kendi_kullanicini_gor = 1 ise sadece kendi giriş yaptığı kayıtları getir
        if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
            // Kullanıcının bayi kodunu al
            $bayiKoduSql = "SELECT bayi_kodu FROM users WHERE id = ?";
            $bayiKoduStmt = $conn->prepare($bayiKoduSql);
            $bayiKoduStmt->execute([$currentUser['id']]);
            $bayiKoduResult = $bayiKoduStmt->fetch(PDO::FETCH_ASSOC);
            $userBayiKodu = $bayiKoduResult['bayi_kodu'] ?? null;
            
            if ($userBayiKodu) {
                $sql = "
                    SELECT 
                        [TALEP_TURU],
                        [UYDU_BASVURU_POTANSIYEL_NO],
                        [DT_MUSTERI_NO],
                        [MEMO_ID],
                        [MEMO_KAYIT_TIPI],
                        [MEMO_ID_TIP],
                        [MEMO_KODU],
                        [MEMO_KAPANIS_TARIHI],
                        [TALEP_GIRIS_TARIHI],
                        [TALEBI_GIREN_BAYI_KODU],
                        [TALEBI_GIREN_BAYI_ADI],
                        [TALEBI_GIREN_PERSONEL],
                        [TALEBI_GIREN_PERSONELNO],
                        [TALEBI_GIREN_PERSONEL_ALTBAYI],
                        [SATIS_DURUMU],
                        [INTERNET_SUREC_DURUMU],
                        [AKTIVE_EDILEN_UYENO],
                        [GUNCEL_OUTLET_DURUM],
                        [TEYIT_DURUM],
                        [MEMO_SON_DURUM],
                        [MEMO_SON_CEVAP],
                        [MEMO_SON_ACIKLAMA],
                        [eklenme_tarihi],
                        [dosya_adi]
                    FROM [digiturk].[iris_rapor]
                    WHERE [DT_MUSTERI_NO] = ?
                    AND [TALEBI_GIREN_BAYI_KODU] = ?
                    ORDER BY [TALEP_GIRIS_TARIHI] DESC
                ";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$musteriNo, $userBayiKodu]);
            } else {
                // Bayi kodu bulunamazsa boş sonuç döndür
                $aboneKayitlar = [];
                $stmt = null;
            }
        } else {
            // kendi_kullanicini_gor = 0 ise tüm kayıtları getir
            $sql = "
                SELECT 
                    [TALEP_TURU],
                    [UYDU_BASVURU_POTANSIYEL_NO],
                    [DT_MUSTERI_NO],
                    [MEMO_ID],
                    [MEMO_KAYIT_TIPI],
                    [MEMO_ID_TIP],
                    [MEMO_KODU],
                    [MEMO_KAPANIS_TARIHI],
                    [TALEP_GIRIS_TARIHI],
                    [TALEBI_GIREN_BAYI_KODU],
                    [TALEBI_GIREN_BAYI_ADI],
                    [TALEBI_GIREN_PERSONEL],
                    [TALEBI_GIREN_PERSONELNO],
                    [TALEBI_GIREN_PERSONEL_ALTBAYI],
                    [SATIS_DURUMU],
                    [INTERNET_SUREC_DURUMU],
                    [AKTIVE_EDILEN_UYENO],
                    [GUNCEL_OUTLET_DURUM],
                    [TEYIT_DURUM],
                    [MEMO_SON_DURUM],
                    [MEMO_SON_CEVAP],
                    [MEMO_SON_ACIKLAMA],
                    [eklenme_tarihi],
                    [dosya_adi]
                FROM [digiturk].[iris_rapor]
                WHERE [DT_MUSTERI_NO] = ?
                ORDER BY [TALEP_GIRIS_TARIHI] DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$musteriNo]);
        }
        
        if ($stmt) {
            $aboneKayitlar = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        $errorMessage = "Arama sırasında bir hata oluştu: " . $e->getMessage();
        error_log($errorMessage);
    }
}

// Header dahil et
require_once '../../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-search me-2"></i><?php echo htmlspecialchars($pageTitle); ?>
                </h5>
            </div>
            <div class="card-body">
                <!-- Yetki Bilgilendirme -->
                <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    Sadece kendi bayi kodunuzla giriş yapılmış kayıtları görüntüleyebilirsiniz.
                </div>
                <?php endif; ?>

                <!-- Arama Formu -->
                <form method="POST" class="mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label for="musteri_no" class="form-label">Müşteri No</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="musteri_no" 
                                   name="musteri_no" 
                                   value="<?php echo htmlspecialchars($musteriNo); ?>"
                                   placeholder="Müşteri numarası girin" 
                                   required>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Ara
                            </button>
                            <?php if ($aramaYapildi): ?>
                            <a href="abone_bul.php" class="btn btn-secondary">
                                <i class="fas fa-redo me-2"></i>Temizle
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <!-- Sonuç Mesajı -->
                <?php if ($aramaYapildi): ?>
                    <?php if (count($aboneKayitlar) > 0): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong><?php echo count($aboneKayitlar); ?></strong> kayıt bulundu.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong><?php echo htmlspecialchars($musteriNo); ?></strong> müşteri numarası için 
                            <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                                kendi bayi kodunuza ait 
                            <?php endif; ?>
                            kayıt bulunamadı.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Arama Sonuçları - Card Görünüm -->
                <?php if (count($aboneKayitlar) > 0): ?>
                <div class="row">
                    <?php foreach ($aboneKayitlar as $index => $kayit): ?>
                    <div class="col-12 mb-3">
                        <div class="card">
                            <div class="card-header bg-light">
                                <div class="row align-items-center">
                                    <div class="col-auto">
                                        <h6 class="mb-0">
                                            <span class="badge bg-primary">#<?php echo $index + 1; ?></span>
                                        </h6>
                                    </div>
                                    <div class="col">
                                        <strong>Müşteri No:</strong> <?php echo htmlspecialchars($kayit['DT_MUSTERI_NO'] ?? ''); ?>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge <?php 
                                            echo ($kayit['SATIS_DURUMU'] ?? '') === 'SATILDI' ? 'bg-success' : 'bg-secondary'; 
                                        ?>">
                                            <?php echo htmlspecialchars($kayit['SATIS_DURUMU'] ?? '-'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Sol Kolon -->
                                    <div class="col-md-4">
                                        <h6 class="text-primary mb-3"><i class="fas fa-info-circle me-2"></i>Genel Bilgiler</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 50%;">Talep Türü:</td>
                                                <td><strong><?php echo htmlspecialchars($kayit['TALEP_TURU'] ?? '-'); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Potansiyel No:</td>
                                                <td><?php echo htmlspecialchars($kayit['UYDU_BASVURU_POTANSIYEL_NO'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Memo ID:</td>
                                                <td><?php echo htmlspecialchars($kayit['MEMO_ID'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Kayıt Tipi:</td>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($kayit['MEMO_KAYIT_TIPI'] ?? '-'); ?></span></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Memo ID Tip:</td>
                                                <td><?php echo htmlspecialchars($kayit['MEMO_ID_TIP'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Memo Kodu:</td>
                                                <td><?php echo htmlspecialchars($kayit['MEMO_KODU'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Aktive Üye No:</td>
                                                <td><?php echo htmlspecialchars($kayit['AKTIVE_EDILEN_UYENO'] ?? '-'); ?></td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Orta Kolon -->
                                    <div class="col-md-4">
                                        <h6 class="text-primary mb-3"><i class="fas fa-store me-2"></i>Bayi Bilgileri</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 50%;">Bayi Kodu:</td>
                                                <td><?php echo htmlspecialchars($kayit['TALEBI_GIREN_BAYI_KODU'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Bayi Adı:</td>
                                                <td><strong><?php echo htmlspecialchars($kayit['TALEBI_GIREN_BAYI_ADI'] ?? '-'); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Alt Bayi:</td>
                                                <td><?php echo htmlspecialchars($kayit['TALEBI_GIREN_PERSONEL_ALTBAYI'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Personel:</td>
                                                <td><?php echo htmlspecialchars($kayit['TALEBI_GIREN_PERSONEL'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Personel No:</td>
                                                <td><?php echo htmlspecialchars($kayit['TALEBI_GIREN_PERSONELNO'] ?? '-'); ?></td>
                                            </tr>
                                        </table>

                                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-calendar me-2"></i>Tarih Bilgileri</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 50%;">Talep Giriş:</td>
                                                <td>
                                                    <?php 
                                                    if (!empty($kayit['TALEP_GIRIS_TARIHI'])) {
                                                        $tarih = new DateTime($kayit['TALEP_GIRIS_TARIHI']);
                                                        echo $tarih->format('d.m.Y H:i');
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Kapanış:</td>
                                                <td>
                                                    <?php 
                                                    if (!empty($kayit['MEMO_KAPANIS_TARIHI'])) {
                                                        $tarih = new DateTime($kayit['MEMO_KAPANIS_TARIHI']);
                                                        echo $tarih->format('d.m.Y H:i');
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Eklenme:</td>
                                                <td>
                                                    <?php 
                                                    if (!empty($kayit['eklenme_tarihi'])) {
                                                        $tarih = new DateTime($kayit['eklenme_tarihi']);
                                                        echo $tarih->format('d.m.Y H:i');
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Sağ Kolon -->
                                    <div class="col-md-4">
                                        <h6 class="text-primary mb-3"><i class="fas fa-chart-bar me-2"></i>Durum Bilgileri</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 50%;">Satış Durumu:</td>
                                                <td>
                                                    <span class="badge <?php 
                                                        echo ($kayit['SATIS_DURUMU'] ?? '') === 'SATILDI' ? 'bg-success' : 'bg-secondary'; 
                                                    ?>">
                                                        <?php echo htmlspecialchars($kayit['SATIS_DURUMU'] ?? '-'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">İnternet Süreç:</td>
                                                <td><?php echo htmlspecialchars($kayit['INTERNET_SUREC_DURUMU'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Outlet Durum:</td>
                                                <td><?php echo htmlspecialchars($kayit['GUNCEL_OUTLET_DURUM'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Teyit Durum:</td>
                                                <td><?php echo htmlspecialchars($kayit['TEYIT_DURUM'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Memo Son Durum:</td>
                                                <td><?php echo htmlspecialchars($kayit['MEMO_SON_DURUM'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Memo Son Cevap:</td>
                                                <td><?php echo htmlspecialchars($kayit['MEMO_SON_CEVAP'] ?? '-'); ?></td>
                                            </tr>
                                        </table>

                                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-file me-2"></i>Diğer</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 50%;">Dosya Adı:</td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($kayit['dosya_adi'] ?? '-'); ?></small></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <!-- Memo Son Açıklama - Tam Genişlik -->
                                <?php if (!empty($kayit['MEMO_SON_ACIKLAMA'])): ?>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="alert alert-info mb-0">
                                            <strong><i class="fas fa-comment-alt me-2"></i>Memo Son Açıklama:</strong>
                                            <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($kayit['MEMO_SON_ACIKLAMA'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Footer dahil et
require_once '../../../includes/footer.php';
?>
