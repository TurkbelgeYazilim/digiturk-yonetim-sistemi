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

$pageTitle = "VoIP Numara Teslim/İade";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '../../../index.php'],
    ['title' => 'VoIP', 'url' => '#'],
    ['title' => 'VoIP Numara Teslim/İade']
];

$message = '';
$messageType = '';

// VoIP Numara Teslim/İade işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                
                $userId = (int)$_POST['voip_operator_users_id'];
                
                // Kendi kullanıcısını görme yetkisi varsa, sadece kendi adına kayıt oluşturabilir
                if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1 && $userId != $currentUser['id']) {
                    throw new Exception('Sadece kendi adınıza kayıt oluşturabilirsiniz.');
                }
                
                $numeroIds = $_POST['voip_operator_numara_id'] ?? [];
                $aciklama = trim($_POST['voip_operator_NoTeslim_Aciklama']);
                $teslimTarihi = $_POST['voip_operator_NoTeslim_TeslimTarihi'] ? $_POST['voip_operator_NoTeslim_TeslimTarihi'] : null;
                $iadeTarihi = $_POST['voip_operator_NoTeslim_IadeTarihi'] ? $_POST['voip_operator_NoTeslim_IadeTarihi'] : null;
                
                // Çoklu numara ekleme - her numara için teslim kontrolü
                foreach ($numeroIds as $numeroId) {
                    // Numaranın daha önce teslim edilip iade alınmamış olup olmadığını kontrol et
                    $kontrolSql = "SELECT COUNT(*) as toplam FROM voip_operator_NoTeslim 
                                   WHERE voip_operator_numara_id = ? 
                                   AND voip_operator_NoTeslim_TeslimTarihi IS NOT NULL 
                                   AND voip_operator_NoTeslim_IadeTarihi IS NULL";
                    $kontrolStmt = $conn->prepare($kontrolSql);
                    $kontrolStmt->execute([$numeroId]);
                    $kontrolResult = $kontrolStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($kontrolResult['toplam'] > 0) {
                        // Numara bilgisini al
                        $numBilgiSql = "SELECT voip_operator_numara_VoIPNo FROM voip_operator_numara WHERE voip_operator_numara_id = ?";
                        $numBilgiStmt = $conn->prepare($numBilgiSql);
                        $numBilgiStmt->execute([$numeroId]);
                        $numBilgi = $numBilgiStmt->fetch(PDO::FETCH_ASSOC);
                        
                        throw new Exception('VoIP Numara "' . $numBilgi['voip_operator_numara_VoIPNo'] . '" zaten teslim edilmiş ve henüz iade alınmamış. Bu numara başkasına teslim edilemez.');
                    }
                    
                    $sql = "INSERT INTO voip_operator_NoTeslim 
                            (voip_operator_users_id, voip_operator_numara_id, voip_operator_NoTeslim_Aciklama, 
                             voip_operator_NoTeslim_TeslimTarihi, voip_operator_NoTeslim_IadeTarihi) 
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$userId, $numeroId, $aciklama, $teslimTarihi, $iadeTarihi]);
                }
                
                $message = 'VoIP Numara Teslim/İade kaydı başarıyla eklendi.';
                $messageType = 'success';
                break;
                
            case 'edit':
                if ($sayfaYetkileri['duzenle'] != 1) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['voip_operator_NoTeslim_id'];
                $userId = (int)$_POST['voip_operator_users_id'];
                
                // Kendi kullanıcısını görme yetkisi varsa, sadece kendi kayıtlarını düzenleyebilir
                if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1 && $userId != $currentUser['id']) {
                    throw new Exception('Sadece kendi kayıtlarınızı düzenleyebilirsiniz.');
                }
                
                $numeroId = (int)$_POST['voip_operator_numara_id'];
                $aciklama = trim($_POST['voip_operator_NoTeslim_Aciklama']);
                $teslimTarihi = $_POST['voip_operator_NoTeslim_TeslimTarihi'] ? $_POST['voip_operator_NoTeslim_TeslimTarihi'] : null;
                $iadeTarihi = $_POST['voip_operator_NoTeslim_IadeTarihi'] ? $_POST['voip_operator_NoTeslim_IadeTarihi'] : null;
                
                // Düzenlenen kaydın mevcut numara bilgisini al
                $mevcutSql = "SELECT voip_operator_numara_id FROM voip_operator_NoTeslim WHERE voip_operator_NoTeslim_id = ?";
                $mevcutStmt = $conn->prepare($mevcutSql);
                $mevcutStmt->execute([$id]);
                $mevcutKayit = $mevcutStmt->fetch(PDO::FETCH_ASSOC);
                
                // Eğer numara değiştiriliyorsa, yeni numaranın teslim kontrolünü yap
                if ($mevcutKayit && $mevcutKayit['voip_operator_numara_id'] != $numeroId) {
                    $kontrolSql = "SELECT COUNT(*) as toplam FROM voip_operator_NoTeslim 
                                   WHERE voip_operator_numara_id = ? 
                                   AND voip_operator_NoTeslim_TeslimTarihi IS NOT NULL 
                                   AND voip_operator_NoTeslim_IadeTarihi IS NULL";
                    $kontrolStmt = $conn->prepare($kontrolSql);
                    $kontrolStmt->execute([$numeroId]);
                    $kontrolResult = $kontrolStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($kontrolResult['toplam'] > 0) {
                        // Numara bilgisini al
                        $numBilgiSql = "SELECT voip_operator_numara_VoIPNo FROM voip_operator_numara WHERE voip_operator_numara_id = ?";
                        $numBilgiStmt = $conn->prepare($numBilgiSql);
                        $numBilgiStmt->execute([$numeroId]);
                        $numBilgi = $numBilgiStmt->fetch(PDO::FETCH_ASSOC);
                        
                        throw new Exception('VoIP Numara "' . $numBilgi['voip_operator_numara_VoIPNo'] . '" zaten teslim edilmiş ve henüz iade alınmamış. Bu numara başkasına teslim edilemez.');
                    }
                }
                
                $sql = "UPDATE voip_operator_NoTeslim SET 
                        voip_operator_users_id = ?, voip_operator_numara_id = ?, 
                        voip_operator_NoTeslim_Aciklama = ?, voip_operator_NoTeslim_TeslimTarihi = ?, 
                        voip_operator_NoTeslim_IadeTarihi = ? 
                        WHERE voip_operator_NoTeslim_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$userId, $numeroId, $aciklama, $teslimTarihi, $iadeTarihi, $id]);
                
                $message = 'VoIP Numara Teslim/İade kaydı başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                if ($sayfaYetkileri['sil'] != 1) {
                    throw new Exception('Silme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['id'];
                
                $sql = "DELETE FROM voip_operator_NoTeslim WHERE voip_operator_NoTeslim_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'VoIP Numara Teslim/İade kaydı başarıyla silindi.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Kullanıcıları al (tüm kullanıcılar, status kontrolü yok)
try {
    $conn = getDatabaseConnection();
    
    $userSql = "SELECT id, ISNULL(first_name, '') as first_name, ISNULL(last_name, '') as last_name FROM users ORDER BY first_name, last_name";
    $userStmt = $conn->prepare($userSql);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $users = [];
    $error = "Kullanıcılar alınırken hata: " . $e->getMessage();
}

// VoIP Numaralarını al (sadece teslim edilmemiş veya iade alınmış olanlar)
try {
    $conn = getDatabaseConnection();
    
    $numeroSql = "SELECT n.voip_operator_numara_id, n.voip_operator_numara_VoIPNo, o.voip_operator_adi 
                  FROM voip_operator_numara n 
                  LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
                  WHERE n.voip_operator_numara_durum = 1 
                  AND n.voip_operator_numara_id NOT IN (
                      SELECT nt.voip_operator_numara_id 
                      FROM voip_operator_NoTeslim nt 
                      WHERE nt.voip_operator_NoTeslim_TeslimTarihi IS NOT NULL 
                      AND nt.voip_operator_NoTeslim_IadeTarihi IS NULL
                  )
                  ORDER BY o.voip_operator_adi, n.voip_operator_numara_VoIPNo";
    $numeroStmt = $conn->prepare($numeroSql);
    $numeroStmt->execute();
    $numeros = $numeroStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Düzenleme için tüm numaraları al (mevcut numarayı dahil etmek için)
    $allNumeroSql = "SELECT n.voip_operator_numara_id, n.voip_operator_numara_VoIPNo, o.voip_operator_adi 
                     FROM voip_operator_numara n 
                     LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
                     WHERE n.voip_operator_numara_durum = 1 
                     ORDER BY o.voip_operator_adi, n.voip_operator_numara_VoIPNo";
    $allNumeroStmt = $conn->prepare($allNumeroSql);
    $allNumeroStmt->execute();
    $allNumeros = $allNumeroStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $numeros = [];
    $allNumeros = [];
    if (!isset($error)) $error = "";
    $error .= " VoIP numaralar alınırken hata: " . $e->getMessage();
}

// Filtreleme parametrelerini al
$filterUser = $_GET['filter_user'] ?? '';
$filterNumero = $_GET['filter_numero'] ?? '';
$filterTeslim = $_GET['filter_teslim'] ?? '';
$filterTeslimUser = $_GET['filter_teslim_user'] ?? '';

// VoIP Numara Teslim/İade kayıtlarını listele
$teslimRecords = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $whereConditions = [];
    $params = [];
    
    // Kendi kullanıcısını görme yetkisi kontrolü - sadece kendi kayıtlarını göster
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $whereConditions[] = "nt.voip_operator_users_id = ?";
        $params[] = $currentUser['id'];
        // Filtreyi de otomatik olarak ayarla
        $filterUser = $currentUser['id'];
    }
    
    if ($sayfaYetkileri['kendi_kullanicini_gor'] != 1 && $filterUser) {
        $whereConditions[] = "nt.voip_operator_users_id = ?";
        $params[] = $filterUser;
    }
    
    if ($filterNumero) {
        $whereConditions[] = "nt.voip_operator_numara_id = ?";
        $params[] = $filterNumero;
    }
    
    if ($filterTeslim === 'teslim') {
        $whereConditions[] = "nt.voip_operator_NoTeslim_TeslimTarihi IS NOT NULL AND nt.voip_operator_NoTeslim_IadeTarihi IS NULL";
    } elseif ($filterTeslim === 'iade') {
        $whereConditions[] = "nt.voip_operator_NoTeslim_IadeTarihi IS NOT NULL";
    }
    
    // Teslim Edilen kullanıcı filtresi
    if ($filterTeslimUser) {
        $whereConditions[] = "nt.voip_operator_users_id = ? AND nt.voip_operator_NoTeslim_TeslimTarihi IS NOT NULL AND nt.voip_operator_NoTeslim_IadeTarihi IS NULL";
        $params[] = $filterTeslimUser;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT nt.*, 
                   u.first_name, u.last_name,
                   n.voip_operator_numara_VoIPNo,
                   o.voip_operator_adi
            FROM voip_operator_NoTeslim nt 
            LEFT JOIN users u ON nt.voip_operator_users_id = u.id
            LEFT JOIN voip_operator_numara n ON nt.voip_operator_numara_id = n.voip_operator_numara_id
            LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
            $whereClause
            ORDER BY nt.voip_operator_NoTeslim_TeslimTarihi DESC, nt.voip_operator_NoTeslim_id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $teslimRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-exchange-alt me-2"></i>VoIP Numara Teslim/İade</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeslimModal">
                    <i class="fas fa-plus me-1"></i>Yeni Kayıt
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

    <!-- Kendi kullanıcısını görme yetkisi uyarısı -->
    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Sadece kendinize ait teslim/iade kayıtlarını görüntüleyebilirsiniz.
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
                <div class="col-md-2">
                    <label class="form-label">Alt Bayi</label>
                    <select name="filter_user" class="form-select" <?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>>
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                    <small class="text-muted">Otomatik filtrelenmiştir</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Numara</label>
                    <select name="filter_numero" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($numeros as $numero): ?>
                            <option value="<?php echo $numero['voip_operator_numara_id']; ?>" <?php echo $filterNumero == $numero['voip_operator_numara_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($numero['voip_operator_numara_VoIPNo'] . ' (' . $numero['voip_operator_adi'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select name="filter_teslim" class="form-select">
                        <option value="">Tümü</option>
                        <option value="teslim" <?php echo $filterTeslim === 'teslim' ? 'selected' : ''; ?>>Teslim Edilen</option>
                        <option value="iade" <?php echo $filterTeslim === 'iade' ? 'selected' : ''; ?>>İade Edilen</option>
                    </select>
                </div>
                <div class="col-md-2">
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
                        <a href="voip_operator_noteslim.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- VoIP Numara Teslim/İade Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Teslim/İade Kayıt Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Alt Bayi</th>
                            <th>VoIP Numara</th>
                            <th>Operatör</th>
                            <th>Açıklama</th>
                            <th>Teslim Tarihi</th>
                            <th>İade Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($teslimRecords)): ?>
                            <?php foreach ($teslimRecords as $record): ?>
                                <tr>
                                    <td><?php echo $record['voip_operator_NoTeslim_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($record['voip_operator_numara_VoIPNo'] ?? ''); ?></code>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['voip_operator_adi'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($record['voip_operator_NoTeslim_Aciklama'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo $record['voip_operator_NoTeslim_TeslimTarihi'] ? date('d.m.Y', strtotime($record['voip_operator_NoTeslim_TeslimTarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo $record['voip_operator_NoTeslim_IadeTarihi'] ? date('d.m.Y', strtotime($record['voip_operator_NoTeslim_IadeTarihi'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($record['voip_operator_NoTeslim_IadeTarihi']) {
                                            echo '<span class="badge bg-warning">İade Edildi</span>';
                                        } elseif ($record['voip_operator_NoTeslim_TeslimTarihi']) {
                                            echo '<span class="badge bg-success">Teslim Edildi</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">Belirsiz</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editTeslim(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteTeslim(<?php echo $record['voip_operator_NoTeslim_id']; ?>, '<?php echo htmlspecialchars($record['voip_operator_numara_VoIPNo']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-exchange-alt fa-3x mb-3 d-block"></i>
                                    <h5>Henüz teslim/iade kaydı bulunmuyor</h5>
                                    <p>İlk kaydı eklemek için "Yeni Kayıt" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Teslim/İade Ekleme Modal -->
<div class="modal fade" id="addTeslimModal" tabindex="-1" aria-labelledby="addTeslimModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTeslimModalLabel">
                    <i class="fas fa-plus me-2"></i>Yeni Teslim/İade Kaydı Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="voip_operator_users_id" class="form-label">Alt Bayi <span class="text-danger">*</span></label>
                        <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                            <input type="hidden" name="voip_operator_users_id" value="<?php echo $currentUser['id']; ?>">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?>" readonly>
                            <small class="text-muted">Sadece kendi adınıza kayıt oluşturabilirsiniz</small>
                        <?php else: ?>
                        <select class="form-select" id="voip_operator_users_id" name="voip_operator_users_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_id" class="form-label">VoIP Numara <span class="text-danger">*</span></label>
                        <select class="form-select" id="voip_operator_numara_id" name="voip_operator_numara_id[]" multiple required style="height: 150px;">
                            <?php foreach ($numeros as $numero): ?>
                                <option value="<?php echo $numero['voip_operator_numara_id']; ?>">
                                    <?php echo htmlspecialchars($numero['voip_operator_numara_VoIPNo'] . ' (' . $numero['voip_operator_adi'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            <strong>Birden fazla numara seçmek için Ctrl tuşuna basılı tutun.</strong><br>
                            <i class="fas fa-info-circle text-primary"></i> Sadece daha önce hiç teslim edilmemiş veya iade alınmış numaralar listelenmektedir.
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_NoTeslim_Aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="voip_operator_NoTeslim_Aciklama" name="voip_operator_NoTeslim_Aciklama" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_NoTeslim_TeslimTarihi" class="form-label">Teslim Tarihi</label>
                                <input type="date" class="form-control" id="voip_operator_NoTeslim_TeslimTarihi" name="voip_operator_NoTeslim_TeslimTarihi">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="voip_operator_NoTeslim_IadeTarihi" class="form-label">İade Tarihi</label>
                                <input type="date" class="form-control" id="voip_operator_NoTeslim_IadeTarihi" name="voip_operator_NoTeslim_IadeTarihi">
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

<!-- Teslim/İade Düzenleme Modal -->
<div class="modal fade" id="editTeslimModal" tabindex="-1" aria-labelledby="editTeslimModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTeslimModalLabel">
                    <i class="fas fa-edit me-2"></i>Teslim/İade Kaydı Düzenle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editTeslimForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="voip_operator_NoTeslim_id" id="edit_voip_operator_NoTeslim_id">
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_users_id" class="form-label">Alt Bayi <span class="text-danger">*</span></label>
                        <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                            <input type="hidden" name="voip_operator_users_id" id="edit_voip_operator_users_id_hidden" value="<?php echo $currentUser['id']; ?>">
                            <input type="text" class="form-control" id="edit_voip_operator_users_id_display" readonly>
                            <small class="text-muted">Sadece kendi kayıtlarınızı düzenleyebilirsiniz</small>
                        <?php else: ?>
                        <select class="form-select" id="edit_voip_operator_users_id" name="voip_operator_users_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_id" class="form-label">VoIP Numara <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_voip_operator_numara_id" name="voip_operator_numara_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($allNumeros as $numero): ?>
                                <option value="<?php echo $numero['voip_operator_numara_id']; ?>">
                                    <?php echo htmlspecialchars($numero['voip_operator_numara_VoIPNo'] . ' (' . $numero['voip_operator_adi'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Mevcut numara değiştirilebilir, ancak başkasına teslim edilmiş numara seçilemez.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_NoTeslim_Aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="edit_voip_operator_NoTeslim_Aciklama" name="voip_operator_NoTeslim_Aciklama" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_NoTeslim_TeslimTarihi" class="form-label">Teslim Tarihi</label>
                                <input type="date" class="form-control" id="edit_voip_operator_NoTeslim_TeslimTarihi" name="voip_operator_NoTeslim_TeslimTarihi">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_voip_operator_NoTeslim_IadeTarihi" class="form-label">İade Tarihi</label>
                                <input type="date" class="form-control" id="edit_voip_operator_NoTeslim_IadeTarihi" name="voip_operator_NoTeslim_IadeTarihi">
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
<div class="modal fade" id="deleteTeslimModal" tabindex="-1" aria-labelledby="deleteTeslimModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteTeslimModalLabel">
                    <i class="fas fa-trash me-2"></i>Kayıt Sil
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Bu teslim/iade kaydını silmek istediğinizden emin misiniz?</p>
                <p><strong id="deleteTeslimName"></strong></p>
                <p class="text-danger"><small>Bu işlem geri alınamaz!</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteTeslimForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteTeslimId">
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
// Tüm numaralar ve kullanılabilir numaralar arrayleri
const allNumeros = <?php echo json_encode($allNumeros); ?>;
const availableNumeros = <?php echo json_encode($numeros); ?>;

function editTeslim(record) {
    // Modal formuna veri doldur
    document.getElementById('edit_voip_operator_NoTeslim_id').value = record.voip_operator_NoTeslim_id;
    
    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
    // Kısıtlı kullanıcı için hidden input ve display input
    document.getElementById('edit_voip_operator_users_id_hidden').value = record.voip_operator_users_id;
    document.getElementById('edit_voip_operator_users_id_display').value = (record.first_name || '') + ' ' + (record.last_name || '');
    <?php else: ?>
    // Normal kullanıcı için select
    document.getElementById('edit_voip_operator_users_id').value = record.voip_operator_users_id;
    <?php endif; ?>
    
    // Numara selectini güncelle - mevcut numara + kullanılabilir numaralar
    const numeroSelect = document.getElementById('edit_voip_operator_numara_id');
    numeroSelect.innerHTML = '<option value="">Seçiniz...</option>';
    
    // Önce mevcut numarayı ekle
    const currentNumero = allNumeros.find(n => n.voip_operator_numara_id == record.voip_operator_numara_id);
    if (currentNumero) {
        const option = document.createElement('option');
        option.value = currentNumero.voip_operator_numara_id;
        option.textContent = currentNumero.voip_operator_numara_VoIPNo + ' (' + currentNumero.voip_operator_adi + ')';
        option.selected = true;
        numeroSelect.appendChild(option);
    }
    
    // Sonra kullanılabilir numaraları ekle (mevcut numara hariç)
    availableNumeros.forEach(numero => {
        if (numero.voip_operator_numara_id != record.voip_operator_numara_id) {
            const option = document.createElement('option');
            option.value = numero.voip_operator_numara_id;
            option.textContent = numero.voip_operator_numara_VoIPNo + ' (' + numero.voip_operator_adi + ')';
            numeroSelect.appendChild(option);
        }
    });
    
    document.getElementById('edit_voip_operator_NoTeslim_Aciklama').value = record.voip_operator_NoTeslim_Aciklama || '';
    document.getElementById('edit_voip_operator_NoTeslim_TeslimTarihi').value = record.voip_operator_NoTeslim_TeslimTarihi || '';
    document.getElementById('edit_voip_operator_NoTeslim_IadeTarihi').value = record.voip_operator_NoTeslim_IadeTarihi || '';
    
    // Modal'ı aç
    new bootstrap.Modal(document.getElementById('editTeslimModal')).show();
}

function deleteTeslim(id, voipNo) {
    document.getElementById('deleteTeslimId').value = id;
    document.getElementById('deleteTeslimName').textContent = 'VoIP No: ' + voipNo;
    new bootstrap.Modal(document.getElementById('deleteTeslimModal')).show();
}
</script>

<style>
/* Basit select stilleri */
.form-select {
    background-color: #fff;
    border: 1px solid #ced4da;
    border-radius: 0.375rem;
    padding: 0.375rem 2.25rem 0.375rem 0.75rem;
}

.form-select:focus {
    border-color: #86b7fe;
    outline: 0;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}
</style>

<?php include '../../../includes/footer.php'; ?>