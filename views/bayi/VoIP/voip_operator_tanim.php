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

$pageTitle = "VoIP Operatör Tanımlama";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '../../../index.php'],
    ['title' => 'VoIP', 'url' => '#'],
    ['title' => 'VoIP Operatör Tanımlama']
];

$message = '';
$messageType = '';

// VoIP Operatör işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                // Ekleme yetkisi kontrolü
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                
                $operatorAdi = trim($_POST['voip_operator_adi']);
                $operatorAciklama = trim($_POST['voip_operator_aciklama']);
                $operatorKanalSayisi = $_POST['voip_operator_kanalSayisi'] ? (int)$_POST['voip_operator_kanalSayisi'] : null;
                $operatorCPS = $_POST['voip_operator_CPS'] ? (int)$_POST['voip_operator_CPS'] : null;
                $operatorLink = trim($_POST['voip_operator_link']);
                $operatorIP = trim($_POST['voip_operator_IP']);
                $operatorKullanici = trim($_POST['voip_operator_kullanici']);
                $operatorSifre = trim($_POST['voip_operator_sifre']);
                $operatorTrunk = $_POST['voip_operator_trunk'] ? (int)$_POST['voip_operator_trunk'] : 0;
                $operatorDurum = isset($_POST['voip_operator_durum']) ? 1 : 0;
                $operatorUsersId = (int)$_POST['voip_operator_users_id'];
                
                $sql = "INSERT INTO voip_operator_tanim 
                        (voip_operator_users_id, voip_operator_adi, voip_operator_aciklama, 
                         voip_operator_kanalSayisi, voip_operator_CPS, voip_operator_link, 
                         voip_operator_IP, voip_operator_kullanici, voip_operator_sifre, voip_operator_trunk, 
                         voip_operator_durum) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$operatorUsersId, $operatorAdi, $operatorAciklama, 
                               $operatorKanalSayisi, $operatorCPS, $operatorLink, 
                               $operatorIP, $operatorKullanici, $operatorSifre, $operatorTrunk, $operatorDurum]);
                
                $message = 'VoIP Operatör başarıyla eklendi.';
                $messageType = 'success';
                break;
                
            case 'edit':
                // Düzenleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['voip_operator_id'];
                $operatorAdi = trim($_POST['voip_operator_adi']);
                $operatorAciklama = trim($_POST['voip_operator_aciklama']);
                $operatorKanalSayisi = $_POST['voip_operator_kanalSayisi'] ? (int)$_POST['voip_operator_kanalSayisi'] : null;
                $operatorCPS = $_POST['voip_operator_CPS'] ? (int)$_POST['voip_operator_CPS'] : null;
                $operatorLink = trim($_POST['voip_operator_link']);
                $operatorIP = trim($_POST['voip_operator_IP']);
                $operatorKullanici = trim($_POST['voip_operator_kullanici']);
                $operatorSifre = trim($_POST['voip_operator_sifre']);
                $operatorTrunk = $_POST['voip_operator_trunk'] ? (int)$_POST['voip_operator_trunk'] : 0;
                $operatorDurum = isset($_POST['voip_operator_durum']) ? 1 : 0;
                $operatorUsersId = (int)$_POST['voip_operator_users_id'];
                
                $sql = "UPDATE voip_operator_tanim SET 
                        voip_operator_users_id = ?, voip_operator_adi = ?, voip_operator_aciklama = ?, 
                        voip_operator_kanalSayisi = ?, voip_operator_CPS = ?, voip_operator_link = ?, 
                        voip_operator_IP = ?, voip_operator_kullanici = ?, voip_operator_sifre = ?, voip_operator_trunk = ?, 
                        voip_operator_durum = ? 
                        WHERE voip_operator_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$operatorUsersId, $operatorAdi, $operatorAciklama, 
                               $operatorKanalSayisi, $operatorCPS, $operatorLink, 
                               $operatorIP, $operatorKullanici, $operatorSifre, $operatorTrunk, $operatorDurum, $id]);
                
                $message = 'VoIP Operatör başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                // Silme yetkisi kontrolü
                if ($sayfaYetkileri['sil'] != 1) {
                    throw new Exception('Silme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['id'];
                
                $sql = "DELETE FROM voip_operator_tanim WHERE voip_operator_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'VoIP Operatör başarıyla silindi.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Kullanıcıları al
try {
    $conn = getDatabaseConnection();
    
    $userSql = "SELECT id, first_name, last_name, email FROM users WHERE status = 'AKTIF' ORDER BY first_name, last_name";
    $userStmt = $conn->prepare($userSql);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $users = [];
}

// Filtreleme parametrelerini al
$filterUser = $_GET['filter_user'] ?? '';
$filterDurum = $_GET['filter_durum'] ?? '';
$filterTrunk = $_GET['filter_trunk'] ?? '';

// VoIP Operatörlerini listele
$voipOperators = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $whereConditions = [];
    $params = [];
    
    if ($filterUser) {
        $whereConditions[] = "v.voip_operator_users_id = ?";
        $params[] = $filterUser;
    }
    
    if ($filterDurum !== '') {
        $whereConditions[] = "v.voip_operator_durum = ?";
        $params[] = $filterDurum;
    }
    
    if ($filterTrunk !== '') {
        $whereConditions[] = "v.voip_operator_trunk = ?";
        $params[] = $filterTrunk;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT v.*, u.first_name, u.last_name, u.email
            FROM voip_operator_tanim v 
            LEFT JOIN users u ON v.voip_operator_users_id = u.id
            $whereClause
            ORDER BY v.voip_operator_olusturmaTarihi DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $voipOperators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-headset me-2"></i>VoIP Operatör Tanımlama</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOperatorModal">
                    <i class="fas fa-plus me-1"></i>Yeni Operatör
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
                <div class="col-md-4">
                    <label class="form-label">Kullanıcı</label>
                    <select name="filter_user" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select name="filter_durum" class="form-select">
                        <option value="">Tümü</option>
                        <option value="1" <?php echo $filterDurum === '1' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="0" <?php echo $filterDurum === '0' ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Trunk Tipi</label>
                    <select name="filter_trunk" class="form-select">
                        <option value="">Tümü</option>
                        <option value="0" <?php echo $filterTrunk === '0' ? 'selected' : ''; ?>>SIP</option>
                        <option value="1" <?php echo $filterTrunk === '1' ? 'selected' : ''; ?>>IP Register</option>
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
            </form>
        </div>
    </div>

    <!-- VoIP Operatörler Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>VoIP Operatör Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Operatör Adı</th>
                            <th>Kullanıcı</th>
                            <th>Açıklama</th>
                            <th>Kanal Sayısı</th>
                            <th>CPS</th>
                            <th>Link</th>
                            <th>IP Adres</th>
                            <th>Trunk</th>
                            <th>Durum</th>
                            <th>Oluşturma Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($voipOperators)): ?>
                            <?php foreach ($voipOperators as $operator): ?>
                                <tr>
                                    <td><?php echo $operator['voip_operator_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($operator['voip_operator_adi']); ?></strong></td>
                                    <td>
                                        <?php if ($operator['first_name']): ?>
                                            <?php echo htmlspecialchars($operator['first_name'] . ' ' . $operator['last_name']); ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($operator['email']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Kullanıcı bulunamadı</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($operator['voip_operator_aciklama'] ?? '-'); ?></td>
                                    <td><?php echo $operator['voip_operator_kanalSayisi'] ?? '-'; ?></td>
                                    <td><?php echo $operator['voip_operator_CPS'] ?? '-'; ?></td>
                                    <td><?php echo htmlspecialchars($operator['voip_operator_link'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($operator['voip_operator_IP'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $operator['voip_operator_trunk'] ? 'bg-info' : 'bg-secondary'; ?>">
                                            <?php echo $operator['voip_operator_trunk'] ? 'IP Register' : 'SIP'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $operator['voip_operator_durum'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $operator['voip_operator_durum'] ? 'Aktif' : 'Pasif'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $operator['voip_operator_olusturmaTarihi'] ? date('d.m.Y', strtotime($operator['voip_operator_olusturmaTarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editOperator(<?php echo htmlspecialchars(json_encode($operator)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteOperator(<?php echo $operator['voip_operator_id']; ?>, '<?php echo htmlspecialchars($operator['voip_operator_adi']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5 text-muted">
                                    <i class="fas fa-headset fa-3x mb-3 d-block"></i>
                                    <h5>Henüz VoIP operatör bulunmuyor</h5>
                                    <p>İlk operatörü eklemek için "Yeni Operatör" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Operatör Ekleme Modal -->
<div class="modal fade" id="addOperatorModal" tabindex="-1" aria-labelledby="addOperatorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addOperatorModalLabel">
                    <i class="fas fa-plus me-2"></i>Yeni VoIP Operatör Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_adi" class="form-label">Operatör Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="voip_operator_adi" name="voip_operator_adi" maxlength="20" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_users_id" class="form-label">Kullanıcı <span class="text-danger">*</span></label>
                                <select class="form-select" id="voip_operator_users_id" name="voip_operator_users_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="voip_operator_aciklama" name="voip_operator_aciklama" maxlength="100" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="voip_operator_kanalSayisi" class="form-label">Kanal Sayısı</label>
                                <input type="number" class="form-control" id="voip_operator_kanalSayisi" name="voip_operator_kanalSayisi" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="voip_operator_CPS" class="form-label">CPS</label>
                                <input type="number" class="form-control" id="voip_operator_CPS" name="voip_operator_CPS" min="1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_link" class="form-label">Link (URL)</label>
                                <input type="text" class="form-control" id="voip_operator_link" name="voip_operator_link" maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_IP" class="form-label">IP Adres</label>
                                <input type="text" class="form-control" id="voip_operator_IP" name="voip_operator_IP" maxlength="50" placeholder="örn: 192.168.1.1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_kullanici" class="form-label">Kullanıcı Adı</label>
                                <input type="text" class="form-control" id="voip_operator_kullanici" name="voip_operator_kullanici" maxlength="20">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_sifre" class="form-label">Şifre</label>
                                <input type="password" class="form-control" id="voip_operator_sifre" name="voip_operator_sifre" maxlength="20">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_trunk" class="form-label">Trunk Tipi</label>
                                <select class="form-select" id="voip_operator_trunk" name="voip_operator_trunk">
                                    <option value="0">SIP</option>
                                    <option value="1">IP Register</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="voip_operator_durum" name="voip_operator_durum" checked>
                                    <label class="form-check-label" for="voip_operator_durum">
                                        Aktif
                                    </label>
                                </div>
                            </div>
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

<!-- Operatör Düzenleme Modal -->
<div class="modal fade" id="editOperatorModal" tabindex="-1" aria-labelledby="editOperatorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editOperatorModalLabel">
                    <i class="fas fa-edit me-2"></i>VoIP Operatör Düzenle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editOperatorForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="voip_operator_id" id="edit_voip_operator_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_adi" class="form-label">Operatör Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_voip_operator_adi" name="voip_operator_adi" maxlength="20" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_users_id" class="form-label">Kullanıcı <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_voip_operator_users_id" name="voip_operator_users_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="edit_voip_operator_aciklama" name="voip_operator_aciklama" maxlength="100" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="edit_voip_operator_kanalSayisi" class="form-label">Kanal Sayısı</label>
                                <input type="number" class="form-control" id="edit_voip_operator_kanalSayisi" name="voip_operator_kanalSayisi" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="edit_voip_operator_CPS" class="form-label">CPS</label>
                                <input type="number" class="form-control" id="edit_voip_operator_CPS" name="voip_operator_CPS" min="1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_link" class="form-label">Link (URL)</label>
                                <input type="text" class="form-control" id="edit_voip_operator_link" name="voip_operator_link" maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_IP" class="form-label">IP Adres</label>
                                <input type="text" class="form-control" id="edit_voip_operator_IP" name="voip_operator_IP" maxlength="50" placeholder="örn: 192.168.1.1">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_kullanici" class="form-label">Kullanıcı Adı</label>
                                <input type="text" class="form-control" id="edit_voip_operator_kullanici" name="voip_operator_kullanici" maxlength="20">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_sifre" class="form-label">Şifre</label>
                                <input type="password" class="form-control" id="edit_voip_operator_sifre" name="voip_operator_sifre" maxlength="20">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_trunk" class="form-label">Trunk Tipi</label>
                                <select class="form-select" id="edit_voip_operator_trunk" name="voip_operator_trunk">
                                    <option value="0">SIP</option>
                                    <option value="1">IP Register</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="edit_voip_operator_durum" name="voip_operator_durum">
                                    <label class="form-check-label" for="edit_voip_operator_durum">
                                        Aktif
                                    </label>
                                </div>
                            </div>
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
<div class="modal fade" id="deleteOperatorModal" tabindex="-1" aria-labelledby="deleteOperatorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteOperatorModalLabel">
                    <i class="fas fa-trash me-2"></i>Operatör Sil
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Bu VoIP operatörü silmek istediğinizden emin misiniz?</p>
                <p><strong id="deleteOperatorName"></strong></p>
                <p class="text-danger"><small>Bu işlem geri alınamaz!</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteOperatorForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteOperatorId">
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
function editOperator(operator) {
    // Modal formuna veri doldur
    document.getElementById('edit_voip_operator_id').value = operator.voip_operator_id;
    document.getElementById('edit_voip_operator_adi').value = operator.voip_operator_adi;
    document.getElementById('edit_voip_operator_users_id').value = operator.voip_operator_users_id;
    document.getElementById('edit_voip_operator_aciklama').value = operator.voip_operator_aciklama || '';
    document.getElementById('edit_voip_operator_kanalSayisi').value = operator.voip_operator_kanalSayisi || '';
    document.getElementById('edit_voip_operator_CPS').value = operator.voip_operator_CPS || '';
    document.getElementById('edit_voip_operator_link').value = operator.voip_operator_link || '';
    document.getElementById('edit_voip_operator_IP').value = operator.voip_operator_IP || '';
    document.getElementById('edit_voip_operator_kullanici').value = operator.voip_operator_kullanici || '';
    document.getElementById('edit_voip_operator_sifre').value = operator.voip_operator_sifre || '';
    document.getElementById('edit_voip_operator_trunk').value = operator.voip_operator_trunk || '0';
    document.getElementById('edit_voip_operator_durum').checked = operator.voip_operator_durum == 1;
    
    // Modal'ı aç
    new bootstrap.Modal(document.getElementById('editOperatorModal')).show();
}

function deleteOperator(id, name) {
    document.getElementById('deleteOperatorId').value = id;
    document.getElementById('deleteOperatorName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteOperatorModal')).show();
}
</script>

<?php include '../../../includes/footer.php'; ?>