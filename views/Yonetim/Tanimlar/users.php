<?php
$pageTitle = "Kullanıcı Yönetimi";
$breadcrumbs = [
    ['title' => 'Kullanıcı Yönetimi']
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

// Veritabanı bağlantı fonksiyonu auth.php dosyasından kullanılıyor

$message = '';
$messageType = '';

// Kullanıcı işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                // Ekleme yetkisi kontrolü
                if ($sayfaYetkileri['ekle'] != 1) {
                    $message = 'Kullanıcı ekleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $email = trim($_POST['email']);
                $password = $_POST['password'];
                $firstName = trim($_POST['first_name']);
                $lastName = trim($_POST['last_name']);
                $phone = trim($_POST['phone']);
                $status = ($_POST['status'] === 'AKTIF' || $_POST['status'] == '1') ? 1 : 0;
                $groupId = $_POST['user_group_id'] ? (int)$_POST['user_group_id'] : null;
                
                // Email kontrolü
                $checkSql = "SELECT id FROM users WHERE email = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$email]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu e-posta adresi zaten kullanılıyor.';
                    $messageType = 'danger';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $sql = "INSERT INTO users (email, password_hash, first_name, last_name, phone, status, user_group_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone, $status, $groupId]);
                    
                    $message = 'Kullanıcı başarıyla eklendi.';
                    $messageType = 'success';
                }
                break;
                
            case 'edit':
                // Düzenleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    $message = 'Kullanıcı düzenleme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = $_POST['id'];
                $email = trim($_POST['email']);
                $firstName = trim($_POST['first_name']);
                $lastName = trim($_POST['last_name']);
                $phone = trim($_POST['phone']);
                $status = ($_POST['status'] === 'AKTIF' || $_POST['status'] == '1') ? 1 : 0;
                $groupId = $_POST['user_group_id'] ? (int)$_POST['user_group_id'] : null;
                
                // Email kontrolü (mevcut kullanıcı hariç)
                $checkSql = "SELECT id FROM users WHERE email = ? AND id != ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$email, $id]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.';
                    $messageType = 'danger';
                } else {
                    $sql = "UPDATE users SET email = ?, first_name = ?, last_name = ?, phone = ?, status = ?, user_group_id = ?, updated_at = GETDATE() 
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$email, $firstName, $lastName, $phone, $status, $groupId, $id]);
                    
                    $message = 'Kullanıcı başarıyla güncellendi.';
                    $messageType = 'success';
                }
                break;
                
            case 'change_password':
                // Düzenleme yetkisi kontrolü (şifre değiştirme de düzenleme sayılır)
                if ($sayfaYetkileri['duzenle'] != 1) {
                    $message = 'Şifre değiştirme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = $_POST['id'];
                $newPassword = $_POST['new_password'];
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $sql = "UPDATE users SET password_hash = ?, updated_at = GETDATE() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$passwordHash, $id]);
                
                $message = 'Şifre başarıyla değiştirildi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                // Silme yetkisi kontrolü
                if ($sayfaYetkileri['sil'] != 1) {
                    $message = 'Kullanıcı silme yetkiniz bulunmamaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $id = $_POST['id'];
                
                // Kendi hesabını silmeye çalışıyor mu?
                if ($id == $currentUser['id']) {
                    $message = 'Kendi hesabınızı silemezsiniz.';
                    $messageType = 'danger';
                } else {
                    $sql = "DELETE FROM users WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$id]);
                    
                    $message = 'Kullanıcı başarıyla silindi.';
                    $messageType = 'success';
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Kullanıcı gruplarını al
try {
    $conn = getDatabaseConnection();
    
    $groupSql = "SELECT id, group_name, group_description as description FROM user_groups ORDER BY group_name";
    $groupStmt = $conn->prepare($groupSql);
    $groupStmt->execute();
    $userGroups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $userGroups = [];
}

// Kullanıcıları listele (grup bilgileri ile birlikte)
$users = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    // kendi_kullanicini_gor = 1 ise sadece kendi kaydını getir
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, 
                       u.last_login, u.login_attempts, u.user_group_id,
                       ug.group_name, ug.group_description as group_description
                FROM users u 
                LEFT JOIN user_groups ug ON u.user_group_id = ug.id
                WHERE u.id = ?
                ORDER BY u.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$currentUser['id']]);
    } else {
        // kendi_kullanicini_gor = 0 ise tüm kullanıcıları getir
        $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, 
                       u.last_login, u.login_attempts, u.user_group_id,
                       ug.group_name, ug.group_description as group_description
                FROM users u 
                LEFT JOIN user_groups ug ON u.user_group_id = ug.id
                ORDER BY u.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    }
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug için
    error_log("Users query executed. Found " . count($users) . " users.");
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    error_log("Users query error: " . $e->getMessage());
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-users me-2"></i>Kullanıcı Yönetimi</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-1"></i>Yeni Kullanıcı
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
            Sadece kendi kullanıcı bilgilerinizi görüntüleyebilirsiniz.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Kullanıcılar Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Kullanıcı Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Ad Soyad</th>
                            <th>E-posta</th>
                            <th>Telefon</th>
                            <th>Grup</th>
                            <th>Durum</th>
                            <th>Kayıt Tarihi</th>
                            <th>Son Giriş</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($users)): ?>
                            <!-- Debug: <?php echo count($users); ?> kullanıcı bulundu -->
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                        <?php if ($user['id'] == $currentUser['id']): ?>
                                            <span class="badge bg-info ms-1">Sen</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($user['group_name']): ?>
                                            <?php
                                            $groupClass = '';
                                            $groupText = $user['group_name'];
                                            switch ($user['group_name']) {
                                                case 'admin': $groupClass = 'bg-danger'; break;
                                                case 'bolge_yoneticisi': $groupClass = 'bg-warning text-dark'; break;
                                                case 'bayi': $groupClass = 'bg-info'; break;
                                                default: $groupClass = 'bg-primary'; break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $groupClass; ?>" title="<?php echo htmlspecialchars($user['group_description'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($groupText); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Grup Yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = ($user['status'] == 1) ? 'bg-success' : 'bg-danger';
                                        $statusText = ($user['status'] == 1) ? 'AKTIF' : 'PASIF';
                                        ?>
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                        <?php if ($user['login_attempts'] >= 5): ?>
                                            <span class="badge bg-danger ms-1">Kilitli</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $user['created_at'] ? date('d.m.Y H:i', strtotime($user['created_at'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : 'Hiç'; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                    onclick="changePassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1 && $user['id'] != $currentUser['id']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
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
                                    <i class="fas fa-users fa-3x mb-3 d-block"></i>
                                    <h5>Henüz kullanıcı bulunmuyor</h5>
                                    <p>İlk kullanıcıyı eklemek için "Yeni Kullanıcı" butonuna tıklayın.</p>
                                    <!-- Debug: Users array count = <?php echo count($users); ?> -->
                                    <?php if ($error): ?>
                                        <p class="text-danger">Hata: <?php echo htmlspecialchars($error); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Kullanıcı Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Yeni Kullanıcı Ekle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="add_first_name" class="form-label">Ad</label>
                            <input type="text" class="form-control" id="add_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="add_last_name" class="form-label">Soyad</label>
                            <input type="text" class="form-control" id="add_last_name" name="last_name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="add_email" class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="add_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="add_password" class="form-label">Şifre</label>
                        <input type="password" class="form-control" id="add_password" name="password" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label for="add_phone" class="form-label">Telefon</label>
                        <input type="tel" class="form-control" id="add_phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="add_group" class="form-label">Kullanıcı Grubu</label>
                        <select class="form-select" id="add_group" name="user_group_id" required>
                            <option value="">Grup Seçin</option>
                            <?php foreach ($userGroups as $group): ?>
                                <option value="<?php echo $group['id']; ?>">
                                    <?php 
                                    $displayName = htmlspecialchars($group['group_name']);
                                    if (!empty($group['description'])) {
                                        $displayName .= ' - ' . htmlspecialchars($group['description']);
                                    }
                                    echo $displayName;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_status" class="form-label">Durum</label>
                        <select class="form-select" id="add_status" name="status" required>
                            <option value="1">Aktif</option>
                            <option value="0">Pasif</option>
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

<!-- Kullanıcı Düzenle Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Kullanıcı Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_first_name" class="form-label">Ad</label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_last_name" class="form-label">Soyad</label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label">Telefon</label>
                        <input type="tel" class="form-control" id="edit_phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="edit_group" class="form-label">Kullanıcı Grubu</label>
                        <select class="form-select" id="edit_group" name="user_group_id">
                            <option value="">Grup Seçin</option>
                            <?php foreach ($userGroups as $group): ?>
                                <option value="<?php echo $group['id']; ?>">
                                    <?php 
                                    $displayName = htmlspecialchars($group['group_name']);
                                    if (!empty($group['description'])) {
                                        $displayName .= ' - ' . htmlspecialchars($group['description']);
                                    }
                                    echo $displayName;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Durum</label>
                        <select class="form-select" id="edit_status" name="status" required>
                            <option value="1">Aktif</option>
                            <option value="0">Pasif</option>
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

<!-- Şifre Değiştir Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="id" id="password_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Şifre Değiştir
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="password_user_name" class="text-muted"></p>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Yeni Şifre</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                        <div class="form-text">Minimum 6 karakter olmalıdır.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-1"></i>Şifreyi Değiştir
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editUser(user) {
    document.getElementById('edit_id').value = user.id;
    document.getElementById('edit_first_name').value = user.first_name;
    document.getElementById('edit_last_name').value = user.last_name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_group').value = user.user_group_id || '';
    document.getElementById('edit_status').value = user.status;
    
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function changePassword(userId, userName) {
    document.getElementById('password_id').value = userId;
    document.getElementById('password_user_name').textContent = userName + ' kullanıcısının şifresini değiştiriyorsunuz.';
    document.getElementById('new_password').value = '';
    
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}

function deleteUser(userId, userName) {
    if (confirm(userName + ' kullanıcısını silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
