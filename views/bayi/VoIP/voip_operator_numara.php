<?php
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
            SELECT tsy.gor, tsy.kendi_kullanicini_gor, tsy.ekle, tsy.duzenle, tsy.sil
            FROM dbo.tanim_sayfalar ts
            INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
            WHERE ts.sayfa_url = ? AND tsy.user_group_id = ? AND ts.durum = 1 AND tsy.durum = 1
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
            
            if ($sayfaYetkileri['gor'] == 0) {
                header('Location: ../../../index.php?error=yetki_yok');
                exit;
            }
        } else {
            header('Location: ../../../index.php?error=yetki_tanimlanmamis');
            exit;
        }
    } catch (Exception $e) {
        error_log("Yetki kontrol hatası: " . $e->getMessage());
        header('Location: ../../../index.php?error=sistem_hatasi');
        exit;
    }
}

$pageTitle = "VoIP Numara Tanımlama";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '../../../index.php'],
    ['title' => 'VoIP', 'url' => '#'],
    ['title' => 'VoIP Numara Tanımlama']
];

$message = '';
$messageType = '';

// VoIP Numara işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                $voipOperatorTanimId = (int)$_POST['voip_operator_tanim_id'];
                $voipNo = trim($_POST['voip_operator_numara_VoIPNo']);
                $sifre = trim($_POST['voip_operator_numara_Sifre']);
                $ip = trim($_POST['voip_operator_numara_IP']);
                $aciklama = trim($_POST['voip_operator_numara_Aciklama']);
                $durum = isset($_POST['voip_operator_numara_durum']) ? 1 : 0;
                
                $sql = "INSERT INTO voip_operator_numara 
                        (voip_operator_tanim_id, voip_operator_numara_VoIPNo, voip_operator_numara_Sifre, 
                         voip_operator_numara_IP, voip_operator_numara_Aciklama, voip_operator_numara_durum) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$voipOperatorTanimId, $voipNo, $sifre, $ip, $aciklama, $durum]);
                
                $message = 'VoIP Numara başarıyla eklendi.';
                $messageType = 'success';
                break;
                
            case 'edit':
                if ($sayfaYetkileri['duzenle'] != 1) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['voip_operator_numara_id'];
                $voipOperatorTanimId = (int)$_POST['voip_operator_tanim_id'];
                $voipNo = trim($_POST['voip_operator_numara_VoIPNo']);
                $sifre = trim($_POST['voip_operator_numara_Sifre']);
                $ip = trim($_POST['voip_operator_numara_IP']);
                $aciklama = trim($_POST['voip_operator_numara_Aciklama']);
                $durum = isset($_POST['voip_operator_numara_durum']) ? 1 : 0;
                
                $sql = "UPDATE voip_operator_numara SET 
                        voip_operator_tanim_id = ?, voip_operator_numara_VoIPNo = ?, voip_operator_numara_Sifre = ?, 
                        voip_operator_numara_IP = ?, voip_operator_numara_Aciklama = ?, voip_operator_numara_durum = ? 
                        WHERE voip_operator_numara_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$voipOperatorTanimId, $voipNo, $sifre, $ip, $aciklama, $durum, $id]);
                
                $message = 'VoIP Numara başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                if ($sayfaYetkileri['sil'] != 1) {
                    throw new Exception('Silme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['id'];
                
                $sql = "DELETE FROM voip_operator_numara WHERE voip_operator_numara_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'VoIP Numara başarıyla silindi.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// VoIP Operatörlerini al
try {
    $conn = getDatabaseConnection();
    
    $operatorSql = "SELECT voip_operator_id, voip_operator_adi FROM voip_operator_tanim WHERE voip_operator_durum = 1 ORDER BY voip_operator_adi";
    $operatorStmt = $conn->prepare($operatorSql);
    $operatorStmt->execute();
    $operators = $operatorStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $operators = [];
}

// Kullanıcıları al (teslim edilen filtresi için)
try {
    $conn = getDatabaseConnection();
    
    $userSql = "SELECT id, ISNULL(first_name, '') as first_name, ISNULL(last_name, '') as last_name FROM users ORDER BY first_name, last_name";
    $userStmt = $conn->prepare($userSql);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $users = [];
}

// Filtreleme parametrelerini al
$filterOperator = $_GET['filter_operator'] ?? '';
$filterDurum = $_GET['filter_durum'] ?? '';
$filterTeslimUser = $_GET['filter_teslim_user'] ?? '';

// VoIP Numaralarını listele
$voipNumbers = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $whereConditions = [];
    $params = [];
    
    if ($filterOperator) {
        $whereConditions[] = "n.voip_operator_tanim_id = ?";
        $params[] = $filterOperator;
    }
    
    if ($filterDurum !== '') {
        $whereConditions[] = "n.voip_operator_numara_durum = ?";
        $params[] = $filterDurum;
    }
    
    if ($filterTeslimUser) {
        $whereConditions[] = "nt.voip_operator_users_id = ?";
        $params[] = $filterTeslimUser;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT n.*, o.voip_operator_adi,
                   nt.voip_operator_users_id as teslim_user_id,
                   u.first_name as teslim_first_name, 
                   u.last_name as teslim_last_name
            FROM voip_operator_numara n 
            LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
            LEFT JOIN voip_operator_NoTeslim nt ON n.voip_operator_numara_id = nt.voip_operator_numara_id 
                      AND nt.voip_operator_NoTeslim_TeslimTarihi IS NOT NULL 
                      AND nt.voip_operator_NoTeslim_IadeTarihi IS NULL
            LEFT JOIN users u ON nt.voip_operator_users_id = u.id
            $whereClause
            ORDER BY n.voip_operator_numara_olusturmaTarihi DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $voipNumbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-phone me-2"></i>VoIP Numara Tanımlama</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNumberModal">
                    <i class="fas fa-plus me-1"></i>Yeni Numara
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

    <!-- Filtreleme -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreleme
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">VoIP Operatör</label>
                    <select name="filter_operator" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($operators as $operator): ?>
                            <option value="<?php echo $operator['voip_operator_id']; ?>" <?php echo $filterOperator == $operator['voip_operator_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($operator['voip_operator_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select name="filter_durum" class="form-select">
                        <option value="">Tümü</option>
                        <option value="1" <?php echo $filterDurum === '1' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="0" <?php echo $filterDurum === '0' ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Teslim Edilen</label>
                    <select name="filter_teslim_user" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filterTeslimUser == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>Filtrele
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <a href="voip_operator_numara.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Temizle
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- VoIP Numaralar Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>VoIP Numara Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>VoIP Operatör</th>
                            <th>VoIP No</th>
                            <th>Şifre</th>
                            <th>IP Adres</th>
                            <th>Açıklama</th>
                            <th>Durum</th>
                            <th>Teslim Edilen</th>
                            <th>Oluşturma Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($voipNumbers)): ?>
                            <?php foreach ($voipNumbers as $number): ?>
                                <tr>
                                    <td><?php echo $number['voip_operator_numara_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($number['voip_operator_adi'] ?? 'Bilinmeyen'); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($number['voip_operator_numara_VoIPNo']); ?></code>
                                    </td>
                                    <td>
                                        <?php if ($number['voip_operator_numara_Sifre']): ?>
                                            <span class="text-muted">••••••••</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($number['voip_operator_numara_IP'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($number['voip_operator_numara_Aciklama'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $number['voip_operator_numara_durum'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $number['voip_operator_numara_durum'] ? 'Aktif' : 'Pasif'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($number['teslim_user_id']): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars(($number['teslim_first_name'] ?? '') . ' ' . ($number['teslim_last_name'] ?? '')); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-minus me-1"></i>Müsait
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $number['voip_operator_numara_olusturmaTarihi'] ? date('d.m.Y', strtotime($number['voip_operator_numara_olusturmaTarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editNumber(<?php echo htmlspecialchars(json_encode($number)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteNumber(<?php echo $number['voip_operator_numara_id']; ?>, '<?php echo htmlspecialchars($number['voip_operator_numara_VoIPNo']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="fas fa-phone fa-3x mb-3 d-block"></i>
                                    <h5>Henüz VoIP numara bulunmuyor</h5>
                                    <p>İlk numarayı eklemek için "Yeni Numara" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Numara Ekleme Modal -->
<div class="modal fade" id="addNumberModal" tabindex="-1" aria-labelledby="addNumberModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addNumberModalLabel">
                    <i class="fas fa-plus me-2"></i>Yeni VoIP Numara Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="voip_operator_tanim_id" class="form-label">VoIP Operatör <span class="text-danger">*</span></label>
                        <select class="form-select" id="voip_operator_tanim_id" name="voip_operator_tanim_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($operators as $operator): ?>
                                <option value="<?php echo $operator['voip_operator_id']; ?>">
                                    <?php echo htmlspecialchars($operator['voip_operator_adi']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_VoIPNo" class="form-label">VoIP No <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="voip_operator_numara_VoIPNo" name="voip_operator_numara_VoIPNo" maxlength="20" required placeholder="örn: 1001, 90555123456">
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_Sifre" class="form-label">Şifre</label>
                        <input type="password" class="form-control" id="voip_operator_numara_Sifre" name="voip_operator_numara_Sifre" maxlength="20">
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_IP" class="form-label">IP Adres</label>
                        <input type="text" class="form-control" id="voip_operator_numara_IP" name="voip_operator_numara_IP" maxlength="50" placeholder="örn: 192.168.1.100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_Aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="voip_operator_numara_Aciklama" name="voip_operator_numara_Aciklama" maxlength="100" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="voip_operator_numara_durum" name="voip_operator_numara_durum" checked>
                            <label class="form-check-label" for="voip_operator_numara_durum">
                                Aktif
                            </label>
                        </div>
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

<!-- Numara Düzenleme Modal -->
<div class="modal fade" id="editNumberModal" tabindex="-1" aria-labelledby="editNumberModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editNumberModalLabel">
                    <i class="fas fa-edit me-2"></i>VoIP Numara Düzenle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editNumberForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="voip_operator_numara_id" id="edit_voip_operator_numara_id">
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_tanim_id" class="form-label">VoIP Operatör <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_voip_operator_tanim_id" name="voip_operator_tanim_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($operators as $operator): ?>
                                <option value="<?php echo $operator['voip_operator_id']; ?>">
                                    <?php echo htmlspecialchars($operator['voip_operator_adi']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_VoIPNo" class="form-label">VoIP No <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_voip_operator_numara_VoIPNo" name="voip_operator_numara_VoIPNo" maxlength="20" required placeholder="örn: 1001, 90555123456">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_Sifre" class="form-label">Şifre</label>
                        <input type="password" class="form-control" id="edit_voip_operator_numara_Sifre" name="voip_operator_numara_Sifre" maxlength="20">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_IP" class="form-label">IP Adres</label>
                        <input type="text" class="form-control" id="edit_voip_operator_numara_IP" name="voip_operator_numara_IP" maxlength="50" placeholder="örn: 192.168.1.100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_Aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="edit_voip_operator_numara_Aciklama" name="voip_operator_numara_Aciklama" maxlength="100" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_voip_operator_numara_durum" name="voip_operator_numara_durum">
                            <label class="form-check-label" for="edit_voip_operator_numara_durum">
                                Aktif
                            </label>
                        </div>
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

<!-- Silme Onay Modal -->
<div class="modal fade" id="deleteNumberModal" tabindex="-1" aria-labelledby="deleteNumberModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteNumberModalLabel">
                    <i class="fas fa-trash me-2"></i>Numara Sil
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Bu VoIP numarayı silmek istediğinizden emin misiniz?</p>
                <p><strong id="deleteNumberName"></strong></p>
                <p class="text-danger"><small>Bu işlem geri alınamaz!</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteNumberForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteNumberId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Sil
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editNumber(number) {
    // Modal formuna veri doldur
    document.getElementById('edit_voip_operator_numara_id').value = number.voip_operator_numara_id;
    document.getElementById('edit_voip_operator_tanim_id').value = number.voip_operator_tanim_id;
    document.getElementById('edit_voip_operator_numara_VoIPNo').value = number.voip_operator_numara_VoIPNo;
    document.getElementById('edit_voip_operator_numara_Sifre').value = number.voip_operator_numara_Sifre || '';
    document.getElementById('edit_voip_operator_numara_IP').value = number.voip_operator_numara_IP || '';
    document.getElementById('edit_voip_operator_numara_Aciklama').value = number.voip_operator_numara_Aciklama || '';
    document.getElementById('edit_voip_operator_numara_durum').checked = number.voip_operator_numara_durum == 1;
    
    // Modal'ı aç
    new bootstrap.Modal(document.getElementById('editNumberModal')).show();
}

function deleteNumber(id, voipNo) {
    document.getElementById('deleteNumberId').value = id;
    document.getElementById('deleteNumberName').textContent = voipNo;
    new bootstrap.Modal(document.getElementById('deleteNumberModal')).show();
}
</script>

<?php include '../../../includes/footer.php'; ?>