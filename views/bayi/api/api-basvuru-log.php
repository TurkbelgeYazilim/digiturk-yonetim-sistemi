<?php
// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = "API Gönderim Log";
$breadcrumbs = [
    ['title' => 'API Gönderim Log']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

// Admin yetkisi kontrolü
$isAdmin = isset($currentUser['group_id']) && $currentUser['group_id'] == 1;

// Sayfa yetki kontrolü
if (!checkPagePermission('api-basvuru-log.php')) {
    http_response_code(403);
    die('Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa yetkilerini belirle
if ($isAdmin) {
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0,
        'ekle' => 0,
        'duzenle' => 0,
        'sil' => 1
    ];
} else {
    try {
        $conn = getDatabaseConnection();
        $currentPageUrl = basename($_SERVER['PHP_SELF']);
        
        $yetkiSql = "
            SELECT 
                tsy.gor,
                tsy.kendi_kullanicini_gor,
                tsy.ekle,
                tsy.duzenle,
                tsy.sil
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
                'ekle' => 0,
                'duzenle' => 0,
                'sil' => (int)$yetkiResult['sil']
            ];
        } else {
            $sayfaYetkileri = [
                'gor' => 1,
                'kendi_kullanicini_gor' => 1,
                'ekle' => 0,
                'duzenle' => 0,
                'sil' => 0
            ];
        }
    } catch (Exception $e) {
        $sayfaYetkileri = [
            'gor' => 1,
            'kendi_kullanicini_gor' => 1,
            'ekle' => 0,
            'duzenle' => 0,
            'sil' => 0
        ];
    }
}

if (!$sayfaYetkileri['gor']) {
    http_response_code(403);
    die('Bu sayfayı görme yetkiniz bulunmamaktadır.');
}

$message = '';
$messageType = '';

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'delete':
                if (!$sayfaYetkileri['sil']) {
                    throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
                }
                
                $logId = $_POST['log_id'];
                $sql = "DELETE FROM API_Gonderim_Log WHERE log_ID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$logId]);
                
                $message = 'Log kaydı başarıyla silindi.';
                $messageType = 'success';
                break;
        }
        
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Log kayıtlarını getir
try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT 
                log.*,
                bl.API_basvuru_firstName,
                bl.API_basvuru_surname,
                bl.API_basvuru_citizenNumber,
                u.first_name as islem_yapan_ad,
                u.last_name as islem_yapan_soyad,
                u.email as islem_yapan_email,
                ak.api_iris_kullanici_LoginCd
            FROM API_Gonderim_Log log
            LEFT JOIN API_basvuruListesi bl ON log.log_basvuru_ID = bl.API_basvuru_ID
            LEFT JOIN users u ON log.log_islem_yapan_kullanici_ID = u.id
            LEFT JOIN API_kullanici ak ON bl.API_basvuru_kullanici_ID = ak.api_iris_kullanici_ID";
    
    if ($sayfaYetkileri['kendi_kullanicini_gor']) {
        $sql .= " WHERE log.log_islem_yapan_kullanici_ID = ?";
        $params = [$currentUser['id']];
    } else {
        $params = [];
    }
    
    $sql .= " ORDER BY log.log_olusturma_tarihi DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // Kullanıcı listesi (filtre için)
    if (!$sayfaYetkileri['kendi_kullanicini_gor']) {
        $usersSql = "SELECT DISTINCT u.first_name, u.last_name
                     FROM users u
                     INNER JOIN API_Gonderim_Log log ON u.id = log.log_islem_yapan_kullanici_ID
                     WHERE u.first_name IS NOT NULL AND u.last_name IS NOT NULL
                     ORDER BY u.first_name, u.last_name";
        $usersStmt = $conn->prepare($usersSql);
        $usersStmt->execute();
        $users = $usersStmt->fetchAll();
    } else {
        $users = [];
    }
    
    $canDelete = $sayfaYetkileri['sil'];
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata oluştu: ' . $e->getMessage();
    $messageType = 'danger';
    $logs = [];
    $users = [];
}

include '../../../includes/header.php';
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<style>
.full-width-section {
    width: 100%;
    max-width: 100%;
    margin-left: 0;
    margin-right: 0;
}
#logTable {
    width: 100% !important;
}
</style>

<div class="full-width-section mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-history me-2"></i>API Gönderim Log</h2>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <?php
        $icon = 'info-circle';
        switch ($messageType) {
            case 'success': $icon = 'check-circle'; break;
            case 'danger': $icon = 'exclamation-triangle'; break;
            case 'warning': $icon = 'exclamation-triangle'; break;
        }
        ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $icon; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
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
            <div class="row g-3">
                <?php if (!$sayfaYetkileri['kendi_kullanicini_gor']): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label">İşlem Yapan</label>
                    <select id="filterUser" class="form-select">
                        <option value="">Tümü</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-2">
                    <label class="form-label">İstek Tipi</label>
                    <select id="filterTip" class="form-select">
                        <option value="">Tümü</option>
                        <option value="Normal Başvuru">Normal Başvuru</option>
                        <option value="Durum Sorgulama">Durum Sorgulama</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Durum</label>
                    <select id="filterDurum" class="form-select">
                        <option value="">Tümü</option>
                        <option value="Başarılı">Başarılı</option>
                        <option value="Başarısız">Başarısız</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">HTTP Status</label>
                    <input type="text" id="filterHttpStatus" class="form-control" placeholder="200, 405...">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Başvuru ID</label>
                    <input type="text" id="filterBasvuruId" class="form-control" placeholder="Başvuru ID...">
                </div>
                <div class="col-12 col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Log Kayıtları</h5>
        </div>
        <div class="card-body">
            <?php if (empty($logs)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Henüz log kaydı bulunmuyor.</p>
                </div>
            <?php else: ?>
                <table class="table table-striped table-hover" id="logTable" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Başvuru ID</th>
                            <th>Başvuru Sahibi</th>
                            <th>İşlem Yapan</th>
                            <th>İstek Tipi</th>
                            <th>HTTP<br>Status</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['log_ID']; ?></td>
                                <td>
                                    <a href="api_basvuru_yonetimi.php?id=<?php echo $log['log_basvuru_ID']; ?>" target="_blank">
                                        <?php echo $log['log_basvuru_ID']; ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars(($log['API_basvuru_firstName'] ?? '') . ' ' . ($log['API_basvuru_surname'] ?? '')); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars(($log['islem_yapan_ad'] ?? '') . ' ' . ($log['islem_yapan_soyad'] ?? '')); ?></strong>
                                </td>
                                <td>
                                    <?php if ($log['log_istek_tipi'] == 'Normal Başvuru'): ?>
                                        <span class="badge bg-info"><?php echo $log['log_istek_tipi']; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><?php echo $log['log_istek_tipi']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = 'secondary';
                                    if ($log['log_http_status'] >= 200 && $log['log_http_status'] < 300) {
                                        $statusClass = 'success';
                                    } elseif ($log['log_http_status'] >= 400) {
                                        $statusClass = 'danger';
                                    }
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $log['log_http_status'] ?? '-'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['log_islem_basarili']): ?>
                                        <span class="badge bg-success">Başarılı</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Başarısız</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('d.m.Y H:i:s', strtotime($log['log_olusturma_tarihi'])); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-info" 
                                                onclick="showDetailModal(<?php echo htmlspecialchars(json_encode($log)); ?>)"
                                                title="Detay">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($canDelete): ?>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="deleteLog(<?php echo $log['log_ID']; ?>)"
                                                    title="Sil">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Detay Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Log Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-primary mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-upload me-2"></i>İstek Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-bordered mb-0">
                                    <tr>
                                        <td><strong>İstek Tipi:</strong></td>
                                        <td id="detail_istek_tipi"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>API URL:</strong></td>
                                        <td id="detail_api_url" style="word-break: break-all;"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Method:</strong></td>
                                        <td id="detail_method"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Token:</strong></td>
                                        <td id="detail_token" style="word-break: break-all; font-size: 11px;"></td>
                                    </tr>
                                </table>
                                <div class="mt-2">
                                    <strong>Request Body:</strong>
                                    <pre id="detail_request_body" class="bg-dark text-light p-2 rounded mt-1" style="max-height: 200px; overflow-y: auto; font-size: 11px;"></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-success mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-download me-2"></i>Yanıt Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-bordered mb-0">
                                    <tr>
                                        <td><strong>HTTP Status:</strong></td>
                                        <td id="detail_http_status"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Response Code:</strong></td>
                                        <td id="detail_response_code"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Durum:</strong></td>
                                        <td id="detail_durum"></td>
                                    </tr>
                                </table>
                                <div class="mt-2">
                                    <strong>Response Body:</strong>
                                    <pre id="detail_response_body" class="bg-dark text-light p-2 rounded mt-1" style="max-height: 200px; overflow-y: auto; font-size: 11px;"></pre>
                                </div>
                                <div class="mt-2" id="detail_hata_container" style="display: none;">
                                    <strong class="text-danger">Hata Mesajı:</strong>
                                    <div id="detail_hata_mesaji" class="alert alert-danger mt-1 p-2" style="font-size: 12px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card border-info">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="fas fa-database me-2"></i>Veritabanına Kaydedilen Değerler</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-bordered mb-0">
                                    <tr>
                                        <td><strong>Response Code ID:</strong></td>
                                        <td id="detail_saved_response_code_id"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Başvuru Durum ID:</strong></td>
                                        <td id="detail_saved_basvuru_durum_id"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Müşteri No:</strong></td>
                                        <td id="detail_saved_musteri_no"></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-bordered mb-0">
                                    <tr>
                                        <td><strong>Talep Kayıt No:</strong></td>
                                        <td id="detail_saved_talep_no"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Memo ID:</strong></td>
                                        <td id="detail_saved_memo_id"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    window.logTable = $('#logTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: false,
        scrollX: false,
        autoWidth: true,
        dom: 'rtip',
        columnDefs: [
            { orderable: true, targets: '_all' }
        ]
    });
});

function applyFilters() {
    var table = window.logTable;
    
    <?php if (!$sayfaYetkileri['kendi_kullanicini_gor']): ?>
    var userFilter = $('#filterUser').val();
    table.column(3).search(userFilter);
    <?php endif; ?>
    
    var tipFilter = $('#filterTip').val();
    table.column(4).search(tipFilter);
    
    var durumFilter = $('#filterDurum').val();
    table.column(6).search(durumFilter);
    
    var httpStatusFilter = $('#filterHttpStatus').val();
    table.column(5).search(httpStatusFilter);
    
    var basvuruIdFilter = $('#filterBasvuruId').val();
    table.column(1).search(basvuruIdFilter);
    
    table.draw();
}

function showDetailModal(log) {
    $('#detail_istek_tipi').text(log.log_istek_tipi || '-');
    $('#detail_api_url').text(log.log_api_url || '-');
    $('#detail_method').text(log.log_api_method || '-');
    $('#detail_token').text(log.log_request_token || '-');
    
    // Request Body
    if (log.log_request_body) {
        try {
            var requestBody = JSON.parse(log.log_request_body);
            $('#detail_request_body').text(JSON.stringify(requestBody, null, 2));
        } catch (e) {
            $('#detail_request_body').text(log.log_request_body);
        }
    } else {
        $('#detail_request_body').text('Body yok');
    }
    
    // Response
    $('#detail_http_status').html('<span class="badge bg-' + 
        (log.log_http_status >= 200 && log.log_http_status < 300 ? 'success' : 'danger') + 
        '">' + (log.log_http_status || '-') + '</span>');
    $('#detail_response_code').text(log.log_response_code !== null ? log.log_response_code : '-');
    $('#detail_durum').html('<span class="badge bg-' + 
        (log.log_islem_basarili ? 'success' : 'danger') + 
        '">' + (log.log_islem_basarili ? 'Başarılı' : 'Başarısız') + '</span>');
    
    // Response Body
    if (log.log_response_body) {
        try {
            var responseBody = JSON.parse(log.log_response_body);
            $('#detail_response_body').text(JSON.stringify(responseBody, null, 2));
        } catch (e) {
            $('#detail_response_body').text(log.log_response_body);
        }
    } else {
        $('#detail_response_body').text('-');
    }
    
    // Hata mesajı
    if (log.log_hata_mesaji) {
        $('#detail_hata_mesaji').text(log.log_hata_mesaji);
        $('#detail_hata_container').show();
    } else {
        $('#detail_hata_container').hide();
    }
    
    // Kaydedilen değerler
    $('#detail_saved_response_code_id').text(log.log_kaydedilen_ResponseCodeID || '-');
    $('#detail_saved_basvuru_durum_id').text(log.log_kaydedilen_BasvuruDurumID || '-');
    $('#detail_saved_musteri_no').text(log.log_kaydedilen_MusteriNo || '-');
    $('#detail_saved_talep_no').text(log.log_kaydedilen_TalepKayitNo || '-');
    $('#detail_saved_memo_id').text(log.log_kaydedilen_MemoID || '-');
    
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function deleteLog(id) {
    if (confirm('Bu log kaydını silmek istediğinize emin misiniz?')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        form.appendChild(actionInput);
        
        var idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'log_id';
        idInput.value = id;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>