<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pageTitle = "Bayi Hakedis Prim Dönem Tanımlama";
$breadcrumbs = [
    ['title' => 'Bayi Hakedis Prim Dönem Tanımlama']
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

// Dönem işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                // Ekleme yetkisi kontrolü
                if ($sayfaYetkileri['ekle'] != 1) {
                    $message = 'Dönem ekleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $donemAdi = trim($_POST['donem_adi']);
                $baslangicTarihi = trim($_POST['baslangic_tarihi']);
                $bitisTarihi = trim($_POST['bitis_tarihi']);
                $durum = $_POST['durum'] ?? 'AKTIF';
                
                if (empty($donemAdi)) {
                    $message = 'Dönem adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($baslangicTarihi)) {
                    $message = 'Başlangıç tarihi alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($bitisTarihi)) {
                    $message = 'Bitiş tarihi alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Tarih kontrolü
                if (strtotime($baslangicTarihi) >= strtotime($bitisTarihi)) {
                    $message = 'Başlangıç tarihi bitiş tarihinden önce olmalıdır.';
                    $messageType = 'danger';
                    break;
                }
                
                // Dönem adı kontrolü
                $checkSql = "SELECT id FROM digiturk.bayi_hakedis_prim_donem WHERE donem_adi = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$donemAdi]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu dönem adı zaten kullanılmaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "INSERT INTO digiturk.bayi_hakedis_prim_donem (donem_adi, baslangic_tarihi, bitis_tarihi, durum, olusturma_tarihi, olusturan) 
                        VALUES (?, ?, ?, ?, GETDATE(), ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$donemAdi, $baslangicTarihi, $bitisTarihi, $durum, $currentUser['id']]);
                
                $message = 'Dönem başarıyla tanımlandı.';
                $messageType = 'success';
                break;
                
            case 'edit':
                // Düzenleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    $message = 'Dönem düzenleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = (int)$_POST['id'];
                $donemAdi = trim($_POST['donem_adi']);
                $baslangicTarihi = trim($_POST['baslangic_tarihi']);
                $bitisTarihi = trim($_POST['bitis_tarihi']);
                $durum = $_POST['durum'] ?? 'AKTIF';
                
                if ($id <= 0) {
                    $message = 'Geçersiz ID.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($donemAdi)) {
                    $message = 'Dönem adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($baslangicTarihi)) {
                    $message = 'Başlangıç tarihi alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($bitisTarihi)) {
                    $message = 'Bitiş tarihi alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Tarih kontrolü
                if (strtotime($baslangicTarihi) >= strtotime($bitisTarihi)) {
                    $message = 'Başlangıç tarihi bitiş tarihinden önce olmalıdır.';
                    $messageType = 'danger';
                    break;
                }
                
                // Dönem adı kontrolü (mevcut kayıt hariç)
                $checkSql = "SELECT id FROM digiturk.bayi_hakedis_prim_donem WHERE donem_adi = ? AND id != ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$donemAdi, $id]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu dönem adı başka bir kayıtta kullanılmaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "UPDATE digiturk.bayi_hakedis_prim_donem SET donem_adi = ?, baslangic_tarihi = ?, bitis_tarihi = ?, durum = ?, 
                        guncelleme_tarihi = GETDATE(), guncelleyen = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$donemAdi, $baslangicTarihi, $bitisTarihi, $durum, $currentUser['id'], $id]);
                
                $message = 'Dönem bilgileri başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                // Silme yetkisi kontrolü
                if ($sayfaYetkileri['sil'] != 1) {
                    $message = 'Dönem silme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = (int)$_POST['id'];
                
                if ($id <= 0) {
                    $message = 'Geçersiz ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "DELETE FROM digiturk.bayi_hakedis_prim_donem WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'Dönem kaydı başarıyla silindi.';
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

// Dönemleri listele
$donemler = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT d.id, d.donem_adi, d.baslangic_tarihi, d.bitis_tarihi, d.durum, 
                   d.olusturma_tarihi, d.guncelleme_tarihi,
                   u1.first_name + ' ' + u1.last_name as olusturan_adi,
                   u2.first_name + ' ' + u2.last_name as guncelleyen_adi
            FROM digiturk.bayi_hakedis_prim_donem d 
            LEFT JOIN users u1 ON d.olusturan = u1.id
            LEFT JOIN users u2 ON d.guncelleyen = u2.id
            ORDER BY d.olusturma_tarihi DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $donemler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching donemler: " . $e->getMessage());
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
                <h2><i class="fas fa-calendar-alt me-2"></i>Bayi Hakedis Prim Dönem Tanımlama</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDonemModal">
                    <i class="fas fa-plus me-1"></i>Yeni Dönem
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

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Dönemler Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Dönem Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Dönem Adı</th>
                            <th>Başlangıç Tarihi</th>
                            <th>Bitiş Tarihi</th>
                            <th>Durum</th>
                            <th>Oluşturan</th>
                            <th>Oluşturma Tarihi</th>
                            <th>Son Güncelleme</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($donemler)): ?>
                            <?php foreach ($donemler as $donem): ?>
                                <tr>
                                    <td><?php echo $donem['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($donem['donem_adi']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo $donem['baslangic_tarihi'] ? date('d.m.Y', strtotime($donem['baslangic_tarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $donem['bitis_tarihi'] ? date('d.m.Y', strtotime($donem['bitis_tarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $donem['durum'] === 'AKTIF' ? 'success' : 'secondary'; ?>">
                                            <?php echo htmlspecialchars($donem['durum']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($donem['olusturan_adi'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo $donem['olusturma_tarihi'] ? date('d.m.Y H:i', strtotime($donem['olusturma_tarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $donem['guncelleme_tarihi'] ? date('d.m.Y H:i', strtotime($donem['guncelleme_tarihi'])) : '-'; ?>
                                        <?php if ($donem['guncelleyen_adi']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($donem['guncelleyen_adi']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editDonem(<?php echo htmlspecialchars(json_encode($donem)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteDonem(<?php echo $donem['id']; ?>, '<?php echo htmlspecialchars($donem['donem_adi']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['duzenle'] == 0 && $sayfaYetkileri['sil'] == 0): ?>
                                            <span class="badge bg-secondary">Yetki Yok</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-calendar-alt fa-3x mb-3 d-block"></i>
                                    <h5>Henüz dönem tanımlanmamış</h5>
                                    <p>İlk dönemi tanımlamak için "Yeni Dönem" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Dönem Modal -->
<div class="modal fade" id="addDonemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Yeni Dönem Tanımla
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_donem_adi" class="form-label">Dönem Adı</label>
                        <input type="text" class="form-control" id="add_donem_adi" name="donem_adi" required 
                               placeholder="Örn: 2025 1. Dönem">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="add_baslangic_tarihi" class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="add_baslangic_tarihi" name="baslangic_tarihi" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="add_bitis_tarihi" class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="add_bitis_tarihi" name="bitis_tarihi" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="add_durum" class="form-label">Durum</label>
                        <select class="form-select" id="add_durum" name="durum" required>
                            <option value="AKTIF">AKTIF</option>
                            <option value="PASIF">PASIF</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Dönem Düzenle Modal -->
<div class="modal fade" id="editDonemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Dönem Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_donem_adi" class="form-label">Dönem Adı</label>
                        <input type="text" class="form-control" id="edit_donem_adi" name="donem_adi" required 
                               placeholder="Örn: 2025 1. Dönem">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_baslangic_tarihi" class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="edit_baslangic_tarihi" name="baslangic_tarihi" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_bitis_tarihi" class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="edit_bitis_tarihi" name="bitis_tarihi" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_durum" name="durum" required>
                            <option value="AKTIF">AKTIF</option>
                            <option value="PASIF">PASIF</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Güncelle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editDonem(donem) {
    document.getElementById('edit_id').value = donem.id;
    document.getElementById('edit_donem_adi').value = donem.donem_adi;
    document.getElementById('edit_baslangic_tarihi').value = donem.baslangic_tarihi;
    document.getElementById('edit_bitis_tarihi').value = donem.bitis_tarihi;
    document.getElementById('edit_durum').value = donem.durum;
    
    new bootstrap.Modal(document.getElementById('editDonemModal')).show();
}

function deleteDonem(donemId, donemAdi) {
    if (confirm(donemAdi + ' dönemini silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${donemId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Modal açıldığında temizle
document.getElementById('addDonemModal').addEventListener('show.bs.modal', function () {
    document.getElementById('add_donem_adi').value = '';
    document.getElementById('add_baslangic_tarihi').value = '';
    document.getElementById('add_bitis_tarihi').value = '';
    document.getElementById('add_durum').value = 'AKTIF';
});

// Tarih validasyonu
document.addEventListener('DOMContentLoaded', function() {
    const baslangicInputs = document.querySelectorAll('[name="baslangic_tarihi"]');
    const bitisInputs = document.querySelectorAll('[name="bitis_tarihi"]');
    
    function validateDates(baslangicInput, bitisInput) {
        if (baslangicInput.value && bitisInput.value) {
            if (new Date(baslangicInput.value) >= new Date(bitisInput.value)) {
                bitisInput.setCustomValidity('Bitiş tarihi başlangıç tarihinden sonra olmalıdır.');
            } else {
                bitisInput.setCustomValidity('');
            }
        }
    }
    
    baslangicInputs.forEach((input, index) => {
        input.addEventListener('change', function() {
            validateDates(this, bitisInputs[index]);
        });
    });
    
    bitisInputs.forEach((input, index) => {
        input.addEventListener('change', function() {
            validateDates(baslangicInputs[index], this);
        });
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>