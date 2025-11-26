<?php
// Debug için hata raporlamasını aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = "Bayi Hakediş Tanımlama";
$breadcrumbs = [
    ['title' => 'Bayi Hakediş Tanımlama']
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
        $currentPageUrl = basename($_SERVER['PHP_SELF']); // bayi_hakedis_tanimla.php
        
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

// Hakediş tanım işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $bayiId = (int)$_POST['bayi_id'];
                $ispNeoFiyat = !empty($_POST['isp_neo_fiyat']) ? (float)$_POST['isp_neo_fiyat'] : null;
                $ispUyduFiyat = !empty($_POST['isp_uydu_fiyat']) ? (float)$_POST['isp_uydu_fiyat'] : null;
                $neoFiyat = !empty($_POST['neo_fiyat']) ? (float)$_POST['neo_fiyat'] : null;
                $uyduFiyat = !empty($_POST['uydu_fiyat']) ? (float)$_POST['uydu_fiyat'] : null;
                $donemIds = $_POST['bayi_hakedis_prim_donem_id'] ?? [];
                $aciklama = trim($_POST['aciklama'] ?? '');
                
                // Açıklama uzunluk kontrolü
                if (strlen($aciklama) > 200) {
                    $message = 'Açıklama metni 200 karakteri geçemez. Mevcut uzunluk: ' . strlen($aciklama) . ' karakter.';
                    $messageType = 'danger';
                } 
                // Dönem seçimi kontrolü
                elseif (empty($donemIds)) {
                    $message = 'En az bir dönem seçmelisiniz.';
                    $messageType = 'danger';
                } else {
                    // Her dönem için ayrı kayıt ekle
                    $conn->beginTransaction();
                    $successCount = 0;
                    
                    try {
                        $sql = "INSERT INTO hakedis_tanim (bayi_id, isp_neo_fiyat, isp_uydu_fiyat, neo_fiyat, uydu_fiyat, bayi_hakedis_prim_donem_id, aciklama) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        
                        foreach ($donemIds as $donemId) {
                            $stmt->execute([$bayiId, $ispNeoFiyat, $ispUyduFiyat, $neoFiyat, $uyduFiyat, (int)$donemId, $aciklama]);
                            $successCount++;
                        }
                        
                        $conn->commit();
                        $message = $successCount . ' adet hakediş tanımı başarıyla eklendi.';
                        $messageType = 'success';
                    } catch (Exception $e) {
                        $conn->rollBack();
                        $message = 'Hata: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
                break;
                
            case 'edit':
                $id = (int)$_POST['id'];
                $bayiId = (int)$_POST['bayi_id'];
                $ispNeoFiyat = !empty($_POST['isp_neo_fiyat']) ? (float)$_POST['isp_neo_fiyat'] : null;
                $ispUyduFiyat = !empty($_POST['isp_uydu_fiyat']) ? (float)$_POST['isp_uydu_fiyat'] : null;
                $neoFiyat = !empty($_POST['neo_fiyat']) ? (float)$_POST['neo_fiyat'] : null;
                $uyduFiyat = !empty($_POST['uydu_fiyat']) ? (float)$_POST['uydu_fiyat'] : null;
                $donemIds = $_POST['bayi_hakedis_prim_donem_id'] ?? [];
                $aciklama = trim($_POST['aciklama'] ?? '');
                
                // Açıklama uzunluk kontrolü
                if (strlen($aciklama) > 200) {
                    $message = 'Açıklama metni 200 karakteri geçemez. Mevcut uzunluk: ' . strlen($aciklama) . ' karakter.';
                    $messageType = 'danger';
                } 
                // Dönem seçimi kontrolü
                elseif (empty($donemIds)) {
                    $message = 'En az bir dönem seçmelisiniz. (Case 1)';
                    $messageType = 'danger';
                } else {
                    // Dönem IDs'leri virgülle ayrılmış string olarak güncelle
                    $donemIdsString = implode(',', array_map('intval', $donemIds));
                    
                    $sql = "UPDATE hakedis_tanim SET bayi_id = ?, isp_neo_fiyat = ?, isp_uydu_fiyat = ?, neo_fiyat = ?, uydu_fiyat = ?, bayi_hakedis_prim_donem_id = ?, aciklama = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$bayiId, $ispNeoFiyat, $ispUyduFiyat, $neoFiyat, $uyduFiyat, $donemIdsString, $aciklama, $id]);
                    
                    $message = 'Hakediş tanımı başarıyla güncellendi. Dönemler: ' . $donemIdsString;
                    $messageType = 'success';
                }
                break;
                
            case 'delete':
                $id = (int)$_POST['id'];
                
                $sql = "DELETE FROM hakedis_tanim WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'Hakediş tanımı başarıyla silindi.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Bayileri al
$bayiler = [];
$donemler = [];
$debugLog = [];

// Veritabanı sorgularında schema prefix'ini ekle
try {
    $conn = getDatabaseConnection();
    
    // TÜM BAYILARI LISTELE - users_bayi tablosundan (Yetki kısıtlaması yok, tüm grup)
    $bayiSql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email 
               FROM users u
               INNER JOIN users_bayi ub ON u.id = ub.user_id
               WHERE u.status = 1
               ORDER BY u.first_name, u.last_name";
    
    $bayiStmt = $conn->prepare($bayiSql);
    $bayiStmt->execute();
    $bayiler = $bayiStmt->fetchAll(PDO::FETCH_ASSOC);
    $debugLog[] = "Bayiler sorgusu - Bulunan bayi sayısı: " . count($bayiler);
    
    // Dönemleri al
    $donemSql = "SELECT id, donem_adi FROM digiturk.bayi_hakedis_prim_donem WHERE durum = 'AKTIF' ORDER BY id DESC";
    $donemStmt = $conn->prepare($donemSql);
    $donemStmt->execute();
    $donemler = $donemStmt->fetchAll(PDO::FETCH_ASSOC);
    $debugLog[] = "Dönemler sorgusu - Bulunan dönem sayısı: " . count($donemler);
    
} catch (Exception $e) {
    $debugLog[] = "HATA: " . $e->getMessage();
}

// Hakediş tanımlarını listele - CLEAN VERSION
$hakedisler = [];
try {
    $conn = getDatabaseConnection();
    
    // kendi_kullanicini_gor = 1 ise sadece kendi kayıtlarını getir
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $sql = "SELECT DISTINCT ht.*, u.first_name, u.last_name, u.email
                FROM hakedis_tanim ht
                INNER JOIN users u ON ht.bayi_id = u.id
                WHERE ht.bayi_id = ?
                ORDER BY ht.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$currentUser['id']]);
    } else {
        $sql = "SELECT DISTINCT ht.*, u.first_name, u.last_name, u.email
                FROM hakedis_tanim ht
                INNER JOIN users u ON ht.bayi_id = u.id
                ORDER BY ht.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    $hakedislerRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // İlk duplikasyon temizleme - ID'ye göre benzersiz kayıtlar
    $uniqueHakedisler = [];
    foreach ($hakedislerRaw as $hakedis) {
        $uniqueHakedisler[$hakedis['id']] = $hakedis;
    }
    
    // Array'e çevir ve sıralama yap
    $hakedisler = array_values($uniqueHakedisler);
    usort($hakedisler, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Her hakediş için dönem isimlerini al
    for ($i = 0; $i < count($hakedisler); $i++) {
        if (!empty($hakedisler[$i]['bayi_hakedis_prim_donem_id'])) {
            $donemIds = explode(',', $hakedisler[$i]['bayi_hakedis_prim_donem_id']);
            $donemIds = array_filter(array_map('intval', $donemIds));
            
            if (!empty($donemIds)) {
                $placeholders = str_repeat('?,', count($donemIds) - 1) . '?';
                $donemSql = "SELECT donem_adi FROM digiturk.bayi_hakedis_prim_donem WHERE id IN ($placeholders)";
                $donemStmt = $conn->prepare($donemSql);
                $donemStmt->execute($donemIds);
                $donemAdilar = $donemStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $hakedisler[$i]['donem_adi'] = implode(', ', $donemAdilar);
                $hakedisler[$i]['donemIds'] = $donemIds;
            } else {
                $hakedisler[$i]['donem_adi'] = 'Dönem Seçilmemiş';
                $hakedisler[$i]['donemIds'] = [];
            }
        } else {
            $hakedisler[$i]['donem_adi'] = 'Dönem Seçilmemiş';
            $hakedisler[$i]['donemIds'] = [];
        }
    }
    
    // FINAL: Son duplikasyon kontrolü
    $finalUnique = [];
    foreach ($hakedisler as $hakedis) {
        $finalUnique[$hakedis['id']] = $hakedis;
    }
    $hakedisler = array_values($finalUnique);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Filtre dropdown'ları için benzersiz değerleri topla
$filterData = [
    'bayiler' => [],
    'donemler' => [],
    'fiyat_araliklari' => [
        '0-100' => '0 - 100 ₺',
        '100-500' => '100 - 500 ₺', 
        '500-1000' => '500 - 1000 ₺',
        '1000-5000' => '1000 - 5000 ₺',
        '5000+' => '5000+ ₺'
    ]
];

// Benzersiz bayi isimlerini topla
foreach ($hakedisler as $hakedis) {
    $bayiKey = $hakedis['bayi_id'];
    $bayiName = $hakedis['first_name'] . ' ' . $hakedis['last_name'];
    
    // Eğer kendi_kullanicini_gor = 1 ise sadece kendi kaydını ekle
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        if ($bayiKey == $currentUser['id']) {
            $filterData['bayiler'][$bayiKey] = $bayiName;
        }
    } else {
        if (!isset($filterData['bayiler'][$bayiKey])) {
            $filterData['bayiler'][$bayiKey] = $bayiName;
        }
    }
}

// Benzersiz dönem isimlerini topla
foreach ($hakedisler as $hakedis) {
    if (!empty($hakedis['donem_adi'])) {
        $donemIds = explode(',', $hakedis['bayi_hakedis_prim_donem_id']);
        $donemAdilar = explode(', ', $hakedis['donem_adi']);
        
        for ($i = 0; $i < count($donemIds); $i++) {
            if (isset($donemAdilar[$i])) {
                $donemKey = trim($donemIds[$i]);
                $donemAdi = trim($donemAdilar[$i]);
                if (!isset($filterData['donemler'][$donemKey])) {
                    $filterData['donemler'][$donemKey] = $donemAdi;
                }
            }
        }
    }
}

include '../../../includes/header.php';
?>

<script>
// JavaScript fonksiyonlarını sayfanın başında tanımla
function editHakedisFromButton(button) {
    try {
        const hakedisData = button.getAttribute('data-hakedis');
        if (!hakedisData) {
            console.error('Hakedis data bulunamadı');
            alert('Veri yükleme hatası. Sayfayı yenileyin ve tekrar deneyin.');
            return;
        }
        
        const hakedis = JSON.parse(hakedisData);
        editHakedis(hakedis);
    } catch (error) {
        console.error('JSON parse hatası:', error);
        alert('Veri okuma hatası. Sayfayı yenileyin ve tekrar deneyin.');
    }
}

function validateForm() {
    // Bayi seçimi kontrolü
    let bayiId = document.getElementById('bayi_id') ? document.getElementById('bayi_id').value : '';
    
    if (!bayiId) {
        alert('Lütfen bir bayi seçin.');
        return false;
    }
    
    // Dönem seçimi kontrolü
    const checkedDonems = document.querySelectorAll('.donem-checkbox:checked');
    
    if (checkedDonems.length === 0) {
        alert('Lütfen en az bir dönem seçin.');
        return false;
    }
    
    return true;
}

function selectAllPeriods() {
    const checkboxes = document.querySelectorAll('.donem-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = true);
}

function clearAllPeriods() {
    const checkboxes = document.querySelectorAll('.donem-checkbox');
    checkboxes.forEach(checkbox => checkbox.checked = false);
}

function deleteHakedis(hakedisId, bayiName) {
    if (confirm(bayiName + ' için hakediş tanımını silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${hakedisId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function openNewModal() {
    const modal = document.getElementById('addHakedisModal');
    
    if (!modal) {
        console.error('Modal bulunamadı!');
        alert('Modal bulunamadı. Sayfayı yenileyin.');
        return;
    }
    
    // Modal'ı temizle
    clearModal();
    
    // Modal'ı aç
    try {
        if (typeof bootstrap !== 'undefined') {
            const bootstrapModal = new bootstrap.Modal(modal);
            bootstrapModal.show();
        } else {
            // Vanilla JavaScript ile aç
            modal.style.display = 'block';
            modal.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Backdrop ekle
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.id = 'temp-backdrop';
            document.body.appendChild(backdrop);
            
            // Close events
            const closeButtons = modal.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    document.body.classList.remove('modal-open');
                    const tempBackdrop = document.getElementById('temp-backdrop');
                    if (tempBackdrop) tempBackdrop.remove();
                });
            });
            
            backdrop.addEventListener('click', function() {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                backdrop.remove();
            });
        }
    } catch (error) {
        console.error('Modal açılırken hata:', error);
        alert('Modal açılırken bir hata oluştu.');
    }
}

function clearModal() {
    if (document.getElementById('modal_action')) {
        document.getElementById('modal_action').value = 'add';
        document.getElementById('modal_id').value = '';
        document.getElementById('modal_title_text').textContent = 'Yeni Hakediş Tanımı';
        document.getElementById('modal_icon').className = 'fas fa-plus me-2';
        document.getElementById('modal_submit_text').textContent = 'Kaydet';
        
        // Formu temizle
        const bayiSelect = document.getElementById('bayi_id');
        if (bayiSelect) {
            bayiSelect.value = '';
        }
        
        document.getElementById('isp_neo_fiyat').value = '';
        document.getElementById('isp_uydu_fiyat').value = '';
        document.getElementById('neo_fiyat').value = '';
        document.getElementById('uydu_fiyat').value = '';
        document.getElementById('aciklama').value = '';
        
        // Dönem checkbox'larını temizle
        clearAllPeriods();
    }
}

function editHakedis(hakedis) {
    // DOM elementlerinin varlığını kontrol et
    const modalAction = document.getElementById('modal_action');
    const modalId = document.getElementById('modal_id');
    const modalTitleText = document.getElementById('modal_title_text');
    const modalIcon = document.getElementById('modal_icon');
    const modalSubmitText = document.getElementById('modal_submit_text');
    const bayiIdSelect = document.getElementById('bayi_id');
    const ispNeoFiyat = document.getElementById('isp_neo_fiyat');
    const ispUyduFiyat = document.getElementById('isp_uydu_fiyat');
    const neoFiyat = document.getElementById('neo_fiyat');
    const uyduFiyat = document.getElementById('uydu_fiyat');
    const aciklama = document.getElementById('aciklama');
    const modal = document.getElementById('addHakedisModal');
    
    // Eğer modal elementleri henüz yüklenmemişse, bekle ve tekrar dene
    if (!modalAction || !modalId || !modalTitleText || !modal) {
        setTimeout(() => editHakedis(hakedis), 500);
        return;
    }
    
    // Modal başlığını güncelle
    modalAction.value = 'edit';
    modalId.value = hakedis.id;
    modalTitleText.textContent = 'Hakediş Tanımını Düzenle';
    if (modalIcon) modalIcon.className = 'fas fa-edit me-2';
    if (modalSubmitText) modalSubmitText.textContent = 'Güncelle';
    
    // Modal alanlarını doldur
    if (bayiIdSelect) {
        bayiIdSelect.value = hakedis.bayi_id;
    }
    if (ispNeoFiyat) ispNeoFiyat.value = hakedis.isp_neo_fiyat || '';
    if (ispUyduFiyat) ispUyduFiyat.value = hakedis.isp_uydu_fiyat || '';
    if (neoFiyat) neoFiyat.value = hakedis.neo_fiyat || '';
    if (uyduFiyat) uyduFiyat.value = hakedis.uydu_fiyat || '';
    if (aciklama) aciklama.value = hakedis.aciklama || '';
    
    // Önce tüm checkbox'ları temizle
    clearAllPeriods();
    
    // Dönem ID'lerini seç
    if (hakedis.bayi_hakedis_prim_donem_id) {
        const donemIds = hakedis.bayi_hakedis_prim_donem_id.toString().split(',').map(id => id.trim());
        
        donemIds.forEach(donemId => {
            if (donemId) {
                const checkbox = document.getElementById('donem_' + donemId);
                if (checkbox) {
                    checkbox.checked = true;
                }
            }
        });
    }
    
    // Modal'ı aç
    try {
        // Bootstrap kontrolü
        if (typeof bootstrap !== 'undefined') {
            const bootstrapModal = new bootstrap.Modal(modal);
            bootstrapModal.show();
        } else {
            // Fallback: Modal'ı manuel olarak aç
            modal.style.display = 'block';
            modal.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Backdrop ekle
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.id = 'temp-backdrop';
            document.body.appendChild(backdrop);
            
            // Modal kapat butonu için event listener
            const closeButtons = modal.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    document.body.classList.remove('modal-open');
                    const tempBackdrop = document.getElementById('temp-backdrop');
                    if (tempBackdrop) tempBackdrop.remove();
                });
            });
            
            // Backdrop tıklama ile kapatma
            backdrop.addEventListener('click', function() {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                backdrop.remove();
            });
        }
    } catch (error) {
        console.error('Modal açılırken hata:', error);
        alert('Modal açılırken bir hata oluştu. Sayfa yenilenecek.');
        location.reload();
    }
}

// Global error handler
window.addEventListener('error', function(event) {
    console.error('JavaScript hatası:', event.error);
});

console.log('JavaScript fonksiyonları yüklendi!');

// DOM yüklendikten sonra dönem checkbox'larını kontrol et
document.addEventListener('DOMContentLoaded', function() {
    const donemCheckboxes = document.querySelectorAll('.donem-checkbox');
    
    if (donemCheckboxes.length === 0) {
        console.warn('Hiç dönem checkbox bulunamadı! Dönem verileri yüklenmemiş olabilir.');
    }
});
</script>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calculator me-2"></i>Bayi Hakediş Tanımlama</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHakedisModal" onclick="openNewModal()">
                    <i class="fas fa-plus me-1"></i>Yeni Hakediş Tanımı
                </button>
                <?php endif; ?>
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

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hakediş Tanımları Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Hakediş Tanım Listesi (<?php echo count($hakedisler); ?> kayıt)
            </h5>
            <div class="d-flex align-items-center">
                <small class="me-3">
                    <i class="fas fa-filter me-1"></i>Filtreler aktif
                </small>
                <button type="button" class="btn btn-sm btn-light" onclick="clearAllFilters()" title="Tüm Filtreleri Temizle">
                    <i class="fas fa-times me-1"></i>Temizle
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="hakedisTable">
                    <thead class="table-light">
                        <tr>
                            <th class="sortable" data-column="0">
                                ID <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="1">
                                Bayi <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="2">
                                ISP Neo Fiyat <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="3">
                                ISP Uydu Fiyat <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="4">
                                Neo Fiyat <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="5">
                                Uydu Fiyat <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="6">
                                Dönem <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th class="sortable" data-column="7">
                                Açıklama <i class="fas fa-sort ms-1"></i>
                            </th>
                            <th>İşlemler</th>
                        </tr>
                        <tr class="filter-row">
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="0">
                                    <option value="">Tüm ID'ler</option>
                                    <?php 
                                    $uniqueIds = array_unique(array_column($hakedisler, 'id'));
                                    sort($uniqueIds);
                                    foreach ($uniqueIds as $id): 
                                    ?>
                                        <option value="<?php echo $id; ?>">
                                            ID: <?php echo $id; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="1">
                                    <option value="">Tüm Bayiler</option>
                                    <?php foreach ($filterData['bayiler'] as $bayiId => $bayiName): ?>
                                        <option value="<?php echo $bayiId; ?>">
                                            <?php echo htmlspecialchars($bayiName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="2">
                                    <option value="">Tüm Fiyatlar</option>
                                    <?php foreach ($filterData['fiyat_araliklari'] as $range => $label): ?>
                                        <option value="<?php echo $range; ?>">
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="3">
                                    <option value="">Tüm Fiyatlar</option>
                                    <?php foreach ($filterData['fiyat_araliklari'] as $range => $label): ?>
                                        <option value="<?php echo $range; ?>">
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="4">
                                    <option value="">Tüm Fiyatlar</option>
                                    <?php foreach ($filterData['fiyat_araliklari'] as $range => $label): ?>
                                        <option value="<?php echo $range; ?>">
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="5">
                                    <option value="">Tüm Fiyatlar</option>
                                    <?php foreach ($filterData['fiyat_araliklari'] as $range => $label): ?>
                                        <option value="<?php echo $range; ?>">
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="6">
                                    <option value="">Tüm Dönemler</option>
                                    <?php foreach ($filterData['donemler'] as $donemId => $donemAdi): ?>
                                        <option value="<?php echo $donemId; ?>">
                                            <?php echo htmlspecialchars($donemAdi); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select class="form-select form-select-sm table-filter" data-column="7">
                                    <option value="">Tüm Açıklamalar</option>
                                    <option value="has_description">Açıklaması Var</option>
                                    <option value="no_description">Açıklaması Yok</option>
                                </select>
                            </th>
                            <th>
                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                        onclick="clearAllFilters()" title="Filtreleri Temizle">
                                    <i class="fas fa-times"></i>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($hakedisler)): ?>
                            <?php $siraNo = 1; ?>
                            <?php foreach ($hakedisler as $hakedis): ?>
                                <tr>
                                    <td>
                                        <?php echo $hakedis['id']; ?>
                                        <br><small class="text-muted">Sıra: <?php echo $siraNo; ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($hakedis['first_name'] . ' ' . $hakedis['last_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($hakedis['email']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo $hakedis['isp_neo_fiyat'] ? number_format($hakedis['isp_neo_fiyat'], 2) . ' ₺' : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $hakedis['isp_uydu_fiyat'] ? number_format($hakedis['isp_uydu_fiyat'], 2) . ' ₺' : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $hakedis['neo_fiyat'] ? number_format($hakedis['neo_fiyat'], 2) . ' ₺' : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $hakedis['uydu_fiyat'] ? number_format($hakedis['uydu_fiyat'], 2) . ' ₺' : '-'; ?>
                                    </td>
                                    <td style="max-width: 200px;">
                                        <div class="donem-content" 
                                             data-bs-toggle="tooltip" 
                                             data-bs-placement="top" 
                                             title="<?php echo htmlspecialchars($hakedis['donem_adi'] ?? 'Dönem Tanımlanmamış'); ?>">
                                            <span class="badge bg-info text-truncate d-inline-block" style="max-width: 180px;">
                                                <?php echo htmlspecialchars($hakedis['donem_adi'] ?? 'Dönem Tanımlanmamış'); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted" title="<?php echo htmlspecialchars($hakedis['aciklama'] ?? ''); ?>">
                                            <?php 
                                            $aciklama = $hakedis['aciklama'] ?? '';
                                            if (strlen($aciklama) > 30) {
                                                echo htmlspecialchars(substr($aciklama, 0, 30)) . '...';
                                            } else {
                                                echo htmlspecialchars($aciklama) ?: '-';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    data-hakedis='<?php echo json_encode($hakedis, JSON_HEX_APOS | JSON_HEX_QUOT); ?>'
                                                    onclick="editHakedisFromButton(this)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteHakedis(<?php echo $hakedis['id']; ?>, '<?php echo htmlspecialchars($hakedis['first_name'] . ' ' . $hakedis['last_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['duzenle'] == 0 && $sayfaYetkileri['sil'] == 0): ?>
                                            <span class="badge bg-secondary">Yetki Yok</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php $siraNo++; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-calculator fa-3x mb-3 d-block"></i>
                                    <h5>Henüz hakediş tanımı bulunmuyor</h5>
                                    <p>İlk hakediş tanımını eklemek için "Yeni Hakediş Tanımı" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Hakediş Tanımı Modal (Ekleme/Düzenleme) -->
<div class="modal fade" id="addHakedisModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" id="modal_action" value="add">
                <input type="hidden" name="id" id="modal_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal_title">
                        <i class="fas fa-plus me-2" id="modal_icon"></i><span id="modal_title_text">Yeni Hakediş Tanımı</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bayi_id" class="form-label">Bayi Ad Soyad <span class="text-danger">*</span></label>
                        <select class="form-select" id="bayi_id" name="bayi_id" required>
                            <option value="">Bayi Seçin</option>
                            <?php foreach ($bayiler as $bayi): ?>
                                <option value="<?php echo $bayi['id']; ?>">
                                    [<?php echo $bayi['id']; ?>] <?php echo htmlspecialchars($bayi['first_name'] . ' ' . $bayi['last_name']); ?> (<?php echo htmlspecialchars($bayi['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="isp_neo_fiyat" class="form-label">ISP Neo Fiyat</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="isp_neo_fiyat" name="isp_neo_fiyat" 
                                       step="0.01" min="0" placeholder="0.00">
                                <span class="input-group-text">₺</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="isp_uydu_fiyat" class="form-label">ISP Uydu Fiyat</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="isp_uydu_fiyat" name="isp_uydu_fiyat" 
                                       step="0.01" min="0" placeholder="0.00">
                                <span class="input-group-text">₺</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="neo_fiyat" class="form-label">Neo Fiyat</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="neo_fiyat" name="neo_fiyat" 
                                       step="0.01" min="0" placeholder="0.00">
                                <span class="input-group-text">₺</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="uydu_fiyat" class="form-label">Uydu Fiyat</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="uydu_fiyat" name="uydu_fiyat" 
                                       step="0.01" min="0" placeholder="0.00">
                                <span class="input-group-text">₺</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bayi_hakedis_prim_donem_id" class="form-label">Dönem Seçimi <span class="text-danger">*</span></label>
                        <?php if (empty($donemler)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Hiç aktif dönem bulunamadı! Veritabanını kontrol edin.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-2">
                                <small><i class="fas fa-info-circle me-1"></i>Toplam <?php echo count($donemler); ?> dönem mevcut</small>
                            </div>
                        <?php endif; ?>
                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($donemler as $donem): ?>
                                <div class="form-check">
                                    <input class="form-check-input donem-checkbox" type="checkbox" 
                                           name="bayi_hakedis_prim_donem_id[]" 
                                           value="<?php echo $donem['id']; ?>" 
                                           id="donem_<?php echo $donem['id']; ?>">
                                    <label class="form-check-label" for="donem_<?php echo $donem['id']; ?>">
                                        <?php echo htmlspecialchars($donem['donem_adi']); ?> (ID: <?php echo $donem['id']; ?>)
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Birden fazla dönem seçebilirsiniz. En az bir dönem seçimi gereklidir.
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPeriods()">Tümünü Seç</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPeriods()">Tümünü Temizle</button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="aciklama" name="aciklama" rows="3" 
                                  placeholder="Hakediş tanımı ile ilgili açıklama (isteğe bağlı)" maxlength="200"></textarea>
                        <div class="form-text">Maksimum 200 karakter</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="modal_submit_btn" onclick="return validateForm()">
                        <i class="fas fa-save me-1"></i><span id="modal_submit_text">Kaydet</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.donem-checkbox:checked + .form-check-label {
    font-weight: bold;
    color: #0d6efd;
}

.form-check {
    padding: 0.25rem 0;
}

.form-check:hover {
    background-color: #f8f9fa;
    border-radius: 0.25rem;
}

.border.rounded {
    border-color: #dee2e6 !important;
}

/* Tablo sıralama stilleri */
.sortable {
    cursor: pointer;
    user-select: none;
    position: relative;
    transition: background-color 0.2s ease;
}

.sortable:hover {
    background-color: #e9ecef !important;
}

.sortable i {
    opacity: 0.5;
    transition: opacity 0.2s ease;
}

.sortable:hover i {
    opacity: 1;
}

.sortable.asc i:before {
    content: "\f0de"; /* fa-sort-up */
    color: #0d6efd;
    opacity: 1;
}

.sortable.desc i:before {
    content: "\f0dd"; /* fa-sort-down */
    color: #0d6efd;
    opacity: 1;
}

/* Dönem sütunu için özel stil */
.donem-content {
    white-space: nowrap;
    overflow: hidden;
}

.text-truncate {
    text-overflow: ellipsis;
}

/* Filtre satırı stilleri */
.filter-row th {
    padding: 0.5rem;
    background-color: #f8f9fa !important;
    border-top: 1px solid #dee2e6;
}

.table-filter {
    font-size: 0.8rem;
    border: 1px solid #ced4da;
    height: 32px;
}

.table-filter:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.1rem rgba(13, 110, 253, 0.25);
}

/* Aktif filtre göstergesi */
.table-filter.has-value {
    background-color: #e7f3ff;
    border-color: #0d6efd;
    font-weight: 500;
}

.table-filter.has-value option:first-child {
    font-weight: bold;
    color: #0d6efd;
}

/* Dropdown ok işareti özelleştirme */
.table-filter {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 16px 12px;
}

/* Gizli satır sınıfı */
.filtered-hidden {
    display: none !important;
}

/* Responsive tablo için mobil görünüm */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .table th, .table td {
        padding: 0.5rem 0.25rem;
    }
    
    .btn-group .btn {
        padding: 0.25rem 0.5rem;
    }
    
    .filter-row th {
        padding: 0.25rem;
    }
    
    .table-filter {
        font-size: 0.75rem;
    }
}
</style>

<script>
// DOM yüklendikten sonra çalışacak
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM yüklendi. Event listener\'lar ekleniyor...');
    
    // Modal event listener
    const modal = document.getElementById('addHakedisModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function(event) {
            // Eğer modal düzenleme için açılmıyorsa temizle
            if (event.relatedTarget && event.relatedTarget.getAttribute('data-bs-target') === '#addHakedisModal') {
                clearModal();
            }
        });
    }
    
    // Form submit listener
    const form = document.querySelector('#addHakedisModal form');
    if (form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submit edildi');
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Tooltip'leri etkinleştir
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Tablo sıralama özelliği
    initTableSorting();
    
    // Tablo filtreleme özelliği
    initTableFiltering();
});

// Tablo filtreleme fonksiyonları
function initTableFiltering() {
    const filterSelects = document.querySelectorAll('.table-filter');
    
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            // Aktif filtre göstergesi
            if (this.value) {
                this.classList.add('has-value');
            } else {
                this.classList.remove('has-value');
            }
            
            // Filtreleme işlemini gerçekleştir
            applyTableFilters();
        });
    });
}

function applyTableFilters() {
    const table = document.querySelector('#hakedisTable tbody');
    const rows = table.querySelectorAll('tr');
    const filterSelects = document.querySelectorAll('.table-filter');
    
    // Filtre değerlerini al
    const filters = {};
    filterSelects.forEach(select => {
        const column = parseInt(select.dataset.column);
        const value = select.value.trim();
        if (value) {
            filters[column] = value;
        }
    });
    
    let visibleRowCount = 0;
    
    rows.forEach(row => {
        // Boş satır kontrolü (veri yoksa)
        if (row.querySelector('td[colspan]')) {
            return;
        }
        
        let shouldShow = true;
        
        // Her filtre için kontrol et
        Object.keys(filters).forEach(columnIndex => {
            const filterValue = filters[columnIndex];
            const cell = row.children[columnIndex];
            const column = parseInt(columnIndex);
            
            if (!cell) return;
            
            if (column === 0) { // ID sütunu
                const cellId = cell.textContent.trim().split('\n')[0]; // Sadece ID'yi al
                if (cellId !== filterValue) {
                    shouldShow = false;
                }
            } else if (column === 1) { // Bayi sütunu
                // Bayi ID'sini row data'sından al
                const editButton = row.querySelector('button[data-hakedis]');
                if (editButton) {
                    try {
                        const hakedisData = JSON.parse(editButton.getAttribute('data-hakedis'));
                        if (hakedisData.bayi_id != filterValue) {
                            shouldShow = false;
                        }
                    } catch (e) {
                        shouldShow = false;
                    }
                } else {
                    shouldShow = false;
                }
            } else if (column >= 2 && column <= 5) { // Fiyat sütunları
                const priceText = cell.textContent.replace(/[₺,\s-]/g, '');
                const price = parseFloat(priceText) || 0;
                
                // Fiyat aralığı kontrolü
                if (!checkPriceRange(price, filterValue)) {
                    shouldShow = false;
                }
            } else if (column === 6) { // Dönem sütunu
                // Dönem ID'lerini kontrol et
                const editButton = row.querySelector('button[data-hakedis]');
                if (editButton) {
                    try {
                        const hakedisData = JSON.parse(editButton.getAttribute('data-hakedis'));
                        const donemIds = hakedisData.bayi_hakedis_prim_donem_id ? 
                            hakedisData.bayi_hakedis_prim_donem_id.toString().split(',') : [];
                        
                        if (!donemIds.some(id => id.trim() === filterValue)) {
                            shouldShow = false;
                        }
                    } catch (e) {
                        shouldShow = false;
                    }
                } else {
                    shouldShow = false;
                }
            } else if (column === 7) { // Açıklama sütunu
                const span = cell.querySelector('span[title]');
                const aciklama = span ? span.getAttribute('title') : cell.textContent.trim();
                
                if (filterValue === 'has_description' && (!aciklama || aciklama === '-')) {
                    shouldShow = false;
                } else if (filterValue === 'no_description' && aciklama && aciklama !== '-') {
                    shouldShow = false;
                }
            }
        });
        
        // Satırı göster/gizle
        if (shouldShow) {
            row.classList.remove('filtered-hidden');
            visibleRowCount++;
        } else {
            row.classList.add('filtered-hidden');
        }
    });
    
    // Sonuç sayısını güncelle
    updateFilterResultsInfo(visibleRowCount, rows.length);
}

function checkPriceRange(price, range) {
    switch (range) {
        case '0-100':
            return price >= 0 && price <= 100;
        case '100-500':
            return price > 100 && price <= 500;
        case '500-1000':
            return price > 500 && price <= 1000;
        case '1000-5000':
            return price > 1000 && price <= 5000;
        case '5000+':
            return price > 5000;
        default:
            return true;
    }
}

function clearAllFilters() {
    const filterSelects = document.querySelectorAll('.table-filter');
    const rows = document.querySelectorAll('#hakedisTable tbody tr');
    
    // Tüm dropdown'ları temizle
    filterSelects.forEach(select => {
        select.value = '';
        select.classList.remove('has-value');
    });
    
    // Tüm satırları göster
    rows.forEach(row => {
        row.classList.remove('filtered-hidden');
    });
    
    // Sonuç sayısını güncelle
    const visibleRows = rows.length;
    updateFilterResultsInfo(visibleRows, visibleRows);
}

function updateFilterResultsInfo(visibleCount, totalCount) {
    // Kart başlığındaki kayıt sayısını güncelle
    const cardHeader = document.querySelector('.card-header h5');
    if (cardHeader) {
        const baseText = 'Hakediş Tanım Listesi';
        if (visibleCount === totalCount) {
            cardHeader.innerHTML = `<i class="fas fa-list me-2"></i>${baseText} (${totalCount} kayıt)`;
        } else {
            cardHeader.innerHTML = `<i class="fas fa-list me-2"></i>${baseText} (${visibleCount}/${totalCount} kayıt)`;
        }
    }
    
    // Eğer hiç sonuç yoksa bilgi mesajı göster
    showNoResultsMessage(visibleCount, totalCount);
}

function showNoResultsMessage(visibleCount, totalCount) {
    const tbody = document.querySelector('#hakedisTable tbody');
    let noResultsRow = tbody.querySelector('.no-results-row');
    
    // Mevcut "sonuç yok" satırını kaldır
    if (noResultsRow) {
        noResultsRow.remove();
    }
    
    // Eğer filtre sonucu hiç kayıt yoksa mesaj göster
    if (visibleCount === 0 && totalCount > 0) {
        const row = document.createElement('tr');
        row.className = 'no-results-row';
        row.innerHTML = `
            <td colspan="9" class="text-center py-4 text-muted">
                <i class="fas fa-search fa-2x mb-2 d-block"></i>
                <h6>Filtreleme sonucu kayıt bulunamadı</h6>
                <p class="mb-2">Arama kriterlerinizi değiştirerek tekrar deneyin.</p>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="clearAllFilters()">
                    <i class="fas fa-times me-1"></i>Filtreleri Temizle
                </button>
            </td>
        `;
        tbody.appendChild(row);
    }
}

// Tablo sıralama fonksiyonları
function initTableSorting() {
    const table = document.querySelector('#hakedisTable');
    const headers = table.querySelectorAll('.sortable');
    
    headers.forEach(header => {
        header.addEventListener('click', function() {
            const column = parseInt(this.dataset.column);
            const currentSort = this.classList.contains('asc') ? 'asc' : this.classList.contains('desc') ? 'desc' : 'none';
            
            // Tüm header'lardan sıralama classlarını kaldır
            headers.forEach(h => h.classList.remove('asc', 'desc'));
            
            // Yeni sıralama yönünü belirle
            let newSort;
            if (currentSort === 'none' || currentSort === 'desc') {
                newSort = 'asc';
            } else {
                newSort = 'desc';
            }
            
            this.classList.add(newSort);
            sortTable(column, newSort);
        });
    });
}

function sortTable(column, direction) {
    const table = document.querySelector('#hakedisTable tbody');
    const rows = Array.from(table.querySelectorAll('tr'));
    
    // Boş satır kontrolü (henüz veri yoksa)
    if (rows.length === 1 && rows[0].querySelector('td[colspan]')) {
        return;
    }
    
    rows.sort((a, b) => {
        const aCell = a.children[column];
        const bCell = b.children[column];
        
        let aValue = '';
        let bValue = '';
        
        // Hücre içeriğini al
        if (column === 0) { // ID sütunu
            aValue = parseInt(aCell.textContent.trim()) || 0;
            bValue = parseInt(bCell.textContent.trim()) || 0;
        } else if (column >= 2 && column <= 5) { // Fiyat sütunları
            // Fiyat değerini al (₺ işaretini kaldır)
            const aText = aCell.textContent.replace(/[₺,\s]/g, '').replace('-', '0');
            const bText = bCell.textContent.replace(/[₺,\s]/g, '').replace('-', '0');
            aValue = parseFloat(aText) || 0;
            bValue = parseFloat(bText) || 0;
        } else {
            // Metin sütunları
            aValue = aCell.textContent.trim().toLowerCase();
            bValue = bCell.textContent.trim().toLowerCase();
        }
        
        // Sıralama karşılaştırması
        if (typeof aValue === 'number' && typeof bValue === 'number') {
            return direction === 'asc' ? aValue - bValue : bValue - aValue;
        } else {
            if (direction === 'asc') {
                return aValue.localeCompare(bValue, 'tr');
            } else {
                return bValue.localeCompare(aValue, 'tr');
            }
        }
    });
    
    // Sıralanmış satırları tabloya ekle
    rows.forEach(row => table.appendChild(row));
}
</script>

<?php include '../../../includes/footer.php'; ?>