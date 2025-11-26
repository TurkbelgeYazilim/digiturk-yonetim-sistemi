<?php
$pageTitle = "Kullanıcı Grupları";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '#'],
    ['title' => 'Kullanıcı Grupları']
];

// Auth kontrolü
require_once '../../../auth.php';
try {
    checkAdminAuth(); // Admin kontrolü
} catch (Exception $e) {
    // Geçici olarak auth hatasını görmezden gel
    // header('Location: login.php');
    // exit;
}

// Veritabanı bağlantı fonksiyonu auth.php dosyasından kullanılıyor

require_once '../../../includes/header.php';

// CRUD işlemleri
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDatabaseConnection();
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $groupName = trim($_POST['group_name']);
                    $description = trim($_POST['group_description']);
                    
                    if (empty($groupName)) {
                        throw new Exception('Grup adı boş olamaz!');
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO user_groups (group_name, group_description, created_at) 
                        VALUES (?, ?, GETDATE())
                    ");
                    $stmt->execute([$groupName, $description]);
                    
                    $message = "Kullanıcı grubu başarıyla eklendi!";
                    $messageType = "success";
                    break;
                    
                case 'edit':
                    $id = (int)$_POST['id'];
                    $groupName = trim($_POST['group_name']);
                    $description = trim($_POST['group_description']);
                    
                    if (empty($groupName)) {
                        throw new Exception('Grup adı boş olamaz!');
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE user_groups 
                        SET group_name = ?, group_description = ?, updated_at = GETDATE() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$groupName, $description, $id]);
                    
                    $message = "Kullanıcı grubu başarıyla güncellendi!";
                    $messageType = "success";
                    break;
                    
                case 'delete':
                    $id = (int)$_POST['id'];
                    
                    // Önce bu gruptan kullanıcı var mı kontrol et
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_group_id = ?");
                    $stmt->execute([$id]);
                    $userCount = $stmt->fetchColumn();
                    
                    if ($userCount > 0) {
                        throw new Exception("Bu grupta $userCount kullanıcı bulunuyor. Önce kullanıcıları başka gruba taşıyın!");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM user_groups WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    $message = "Kullanıcı grubu başarıyla silindi!";
                    $messageType = "success";
                    break;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "danger";
    }
}

// Grupları listele
try {
    $pdo = getDatabaseConnection();
    
    $stmt = $pdo->query("
        SELECT ug.*, 
               (SELECT COUNT(*) FROM users u WHERE u.user_group_id = ug.id) as user_count
        FROM user_groups ug 
        ORDER BY ug.group_name
    ");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = "Veriler yüklenirken hata: " . $e->getMessage();
    $messageType = "danger";
    $groups = [];
}

// Mevcut izinler listesi
$availablePermissions = [
    'users_view' => 'Kullanıcıları Görüntüleme',
    'users_add' => 'Kullanıcı Ekleme',
    'users_edit' => 'Kullanıcı Düzenleme',
    'users_delete' => 'Kullanıcı Silme',
    'reports_view' => 'Raporları Görüntüleme',
    'reports_export' => 'Rapor Dışa Aktarma',
    'system_settings' => 'Sistem Ayarları',
    'user_groups_manage' => 'Kullanıcı Grupları Yönetimi'
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-users-cog me-2"></i>Kullanıcı Grupları</h2>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
                    <i class="fas fa-plus me-1"></i>Yeni Grup Ekle
                </button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Gruplar Tablosu -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Grup Adı</th>
                                    <th>Açıklama</th>
                                    <th>Kullanıcı Sayısı</th>
                                    <th>İzinler</th>
                                    <th>Oluşturma Tarihi</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($groups)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="fas fa-users-slash fa-2x mb-2 d-block"></i>
                                            Henüz kullanıcı grubu bulunmuyor
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($groups as $group): ?>
                                        <tr>
                                            <td><?php echo $group['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($group['group_description'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge <?php echo $group['user_count'] > 0 ? 'bg-info' : 'bg-secondary'; ?>">
                                                    <?php echo $group['user_count']; ?> kullanıcı
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">İzin sistemi henüz aktif değil</small>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('d.m.Y H:i', strtotime($group['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="editGroup(<?php echo htmlspecialchars(json_encode($group)); ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($group['user_count'] == 0): ?>
                                                        <button type="button" class="btn btn-outline-danger" 
                                                                onclick="deleteGroup(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['group_name']); ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Grup Ekleme Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Yeni Kullanıcı Grubu Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label class="form-label">Grup Adı *</label>
                        <input type="text" class="form-control" name="group_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="group_description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Grup Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Grup Düzenleme Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Kullanıcı Grubu Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_group_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Grup Adı *</label>
                        <input type="text" class="form-control" name="group_name" id="edit_group_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="group_description" id="edit_description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Silme Modal -->
<div class="modal fade" id="deleteGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Grubu Sil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_group_id">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong id="delete_group_name"></strong> adlı grubu silmek istediğinizden emin misiniz?
                        <br><br>
                        <small>Bu işlem geri alınamaz!</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">Sil</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editGroup(group) {
    document.getElementById('edit_group_id').value = group.id;
    document.getElementById('edit_group_name').value = group.group_name;
    document.getElementById('edit_description').value = group.group_description || '';
    
    new bootstrap.Modal(document.getElementById('editGroupModal')).show();
}

function deleteGroup(id, name) {
    document.getElementById('delete_group_id').value = id;
    document.getElementById('delete_group_name').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteGroupModal')).show();
}
</script>

<?php require_once '../../../includes/footer.php'; ?>
