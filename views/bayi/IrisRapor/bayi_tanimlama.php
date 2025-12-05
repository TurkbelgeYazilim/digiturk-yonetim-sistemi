<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pageTitle = "Bayi Tanımlama";
$breadcrumbs = [
    ['title' => 'Bayi Tanımlama']
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

// Bayi işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                // Ekleme yetkisi kontrolü
                if ($sayfaYetkileri['ekle'] != 1) {
                    $message = 'Bayi ekleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $userId = (int)$_POST['user_id'];
                $irisAltbayi = trim($_POST['iris_altbayi']);
                $personelKimlikNo = trim($_POST['personel_kimlik_no']);
                
                if ($userId <= 0) {
                    $message = 'Geçerli bir kullanıcı seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($irisAltbayi)) {
                    $message = 'İRİS ALTBAYI alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Personel Kimlik No boş değilse, zaten kullanılıp kullanılmadığını kontrol et
                if (!empty($personelKimlikNo)) {
                    $checkSql = "SELECT id FROM users_bayi WHERE personel_kimlik_no = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$personelKimlikNo]);
                    
                    if ($checkStmt->fetch()) {
                        $message = 'Bu Personel Kimlik No zaten kullanılmaktadır.';
                        $messageType = 'danger';
                        break;
                    }
                }
                
                $sql = "INSERT INTO users_bayi (user_id, iris_altbayi, personel_kimlik_no) 
                        VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$userId, $irisAltbayi, $personelKimlikNo]);
                
                $message = 'Bayi başarıyla tanımlandı.';
                $messageType = 'success';
                break;
                
            case 'edit':
                // Düzenleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    $message = 'Bayi düzenleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = (int)$_POST['id'];
                $userId = (int)$_POST['user_id'];
                $irisAltbayi = trim($_POST['iris_altbayi']);
                $personelKimlikNo = trim($_POST['personel_kimlik_no']);
                
                if ($id <= 0 || $userId <= 0) {
                    $message = 'Geçersiz parametreler.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($irisAltbayi)) {
                    $message = 'İRİS ALTBAYI alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Personel Kimlik No boş değilse, başka bir kayıtta kullanılıp kullanılmadığını kontrol et (mevcut kayıt hariç)
                if (!empty($personelKimlikNo)) {
                    $checkSql = "SELECT id FROM users_bayi WHERE personel_kimlik_no = ? AND id != ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$personelKimlikNo, $id]);
                    
                    if ($checkStmt->fetch()) {
                        $message = 'Bu Personel Kimlik No başka bir bayi kaydında kullanılmaktadır.';
                        $messageType = 'danger';
                        break;
                    }
                }
                
                $sql = "UPDATE users_bayi SET user_id = ?, iris_altbayi = ?, personel_kimlik_no = ?, updated_at = GETDATE() 
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$userId, $irisAltbayi, $personelKimlikNo, $id]);
                
                $message = 'Bayi bilgileri başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                // Silme yetkisi kontrolü
                if ($sayfaYetkileri['sil'] != 1) {
                    $message = 'Bayi silme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = (int)$_POST['id'];
                
                if ($id <= 0) {
                    $message = 'Geçersiz ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "DELETE FROM users_bayi WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'Bayi kaydı başarıyla silindi.';
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

// Kullanıcıları al (dropdown için) - Modal'da tüm kullanıcıları göster, yetki kısıtlaması yok
$users = [];
try {
    $conn = getDatabaseConnection();
    
    // Modal'daki dropdown'ta yetki kısıtlaması olmadan tüm kullanıcıları göster
    $userSql = "SELECT id, first_name, last_name, email FROM users ORDER BY first_name, last_name";
    $userStmt = $conn->prepare($userSql);
    $userStmt->execute();
    
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $users = [];
    error_log("Error fetching users: " . $e->getMessage());
    $error = "Kullanıcı listesi alınırken hata oluştu: " . $e->getMessage();
}

// Bayileri listele
$bayiler = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    // kendi_kullanicini_gor = 1 ise sadece kendi bayi kaydını getir
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $sql = "SELECT ub.id, ub.user_id, ub.iris_altbayi, ub.personel_kimlik_no, ub.created_at, ub.updated_at,
                       u.first_name, u.last_name
                FROM users_bayi ub 
                INNER JOIN users u ON ub.user_id = u.id
                WHERE ub.user_id = ?
                ORDER BY ub.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$currentUser['id']]);
    } else {
        // kendi_kullanicini_gor = 0 ise tüm bayileri getir
        $sql = "SELECT ub.id, ub.user_id, ub.iris_altbayi, ub.personel_kimlik_no, ub.created_at, ub.updated_at,
                       u.first_name, u.last_name
                FROM users_bayi ub 
                INNER JOIN users u ON ub.user_id = u.id
                ORDER BY ub.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    $bayiler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching bayiler: " . $e->getMessage());
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
                <h2><i class="fas fa-store me-2"></i>Bayi Tanımlama</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBayiModal">
                    <i class="fas fa-plus me-1"></i>Yeni Bayi
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

    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            Sadece kendi bayi kayıtlarınızı görüntüleyebilirsiniz.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Bayiler Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Bayi Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Kullanıcı ID</th>
                            <th>Kullanıcı Adı</th>
                            <th>İRİS ALTBAYI</th>
                            <th>Personel Kimlik No</th>
                            <th>Kayıt Tarihi</th>
                            <th>Güncelleme Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($bayiler)): ?>
                            <?php foreach ($bayiler as $bayi): ?>
                                <tr>
                                    <td><?php echo $bayi['id']; ?></td>
                                    <td><?php echo $bayi['user_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bayi['first_name'] . ' ' . $bayi['last_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($bayi['iris_altbayi']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($bayi['personel_kimlik_no'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo $bayi['created_at'] ? date('d.m.Y H:i', strtotime($bayi['created_at'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $bayi['updated_at'] ? date('d.m.Y H:i', strtotime($bayi['updated_at'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editBayi(<?php echo htmlspecialchars(json_encode($bayi)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteBayi(<?php echo $bayi['id']; ?>, '<?php echo htmlspecialchars($bayi['first_name'] . ' ' . $bayi['last_name']); ?>')">
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
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-store fa-3x mb-3 d-block"></i>
                                    <h5>Henüz bayi tanımlanmamış</h5>
                                    <p>İlk bayiyi tanımlamak için "Yeni Bayi" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Bayi Modal -->
<div class="modal fade" id="addBayiModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-store me-2"></i>Yeni Bayi Tanımla
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_user_id" class="form-label">Kullanıcı Seçin</label>
                        <select class="form-select" id="add_user_id" name="user_id" required>
                            <option value="">Kullanıcı Seçin</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" title="<?php echo htmlspecialchars($user['email']); ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> 
                                    (<?php echo htmlspecialchars($user['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_iris_altbayi" class="form-label">İRİS ALTBAYI</label>
                        <input type="text" class="form-control" id="add_iris_altbayi" name="iris_altbayi" required 
                               placeholder="İRİS ALTBAYI kodunu girin">
                    </div>
                    <div class="mb-3">
                        <label for="add_personel_kimlik_no" class="form-label">Personel Kimlik No</label>
                        <input type="text" class="form-control" id="add_personel_kimlik_no" name="personel_kimlik_no" 
                               placeholder="Personel kimlik numarasını girin (opsiyonel)">
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

<!-- Bayi Düzenle Modal -->
<div class="modal fade" id="editBayiModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Bayi Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_user_id" class="form-label">Kullanıcı Seçin</label>
                        <select class="form-select" id="edit_user_id" name="user_id" required>
                            <option value="">Kullanıcı Seçin</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" title="<?php echo htmlspecialchars($user['email']); ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> 
                                    (<?php echo htmlspecialchars($user['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_iris_altbayi" class="form-label">İRİS ALTBAYI</label>
                        <input type="text" class="form-control" id="edit_iris_altbayi" name="iris_altbayi" required 
                               placeholder="İRİS ALTBAYI kodunu girin">
                    </div>
                    <div class="mb-3">
                        <label for="edit_personel_kimlik_no" class="form-label">Personel Kimlik No</label>
                        <input type="text" class="form-control" id="edit_personel_kimlik_no" name="personel_kimlik_no" 
                               placeholder="Personel kimlik numarasını girin (opsiyonel)">
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

<script>
function editBayi(bayi) {
    document.getElementById('edit_id').value = bayi.id;
    document.getElementById('edit_user_id').value = bayi.user_id;
    document.getElementById('edit_iris_altbayi').value = bayi.iris_altbayi;
    document.getElementById('edit_personel_kimlik_no').value = bayi.personel_kimlik_no || '';
    
    new bootstrap.Modal(document.getElementById('editBayiModal')).show();
}

function deleteBayi(bayiId, kullaniciAdi) {
    if (confirm(kullaniciAdi + ' kullanıcısının bayi kaydını silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${bayiId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Modal açıldığında temizle
document.getElementById('addBayiModal').addEventListener('show.bs.modal', function () {
    document.getElementById('add_user_id').value = '';
    document.getElementById('add_iris_altbayi').value = '';
    document.getElementById('add_personel_kimlik_no').value = '';
});
</script>

<?php include '../../../includes/footer.php'; ?>
