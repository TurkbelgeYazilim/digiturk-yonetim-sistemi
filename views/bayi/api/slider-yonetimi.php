<?php
// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// Dinamik sayfa bilgilerini al
$sayfaBilgileri = [];
try {
    $conn = getDatabaseConnection();
    
    // Mevcut sayfa URL'sini al
    $currentPageUrl = basename($_SERVER['PHP_SELF']);
    
    // Sayfa bilgilerini çek
    $sayfaSql = "
        SELECT 
            sayfa_adi,
            sayfa_aciklama,
            sayfa_icon
        FROM dbo.tanim_sayfalar 
        WHERE sayfa_url = ? 
        AND durum = 1
    ";
    
    $sayfaStmt = $conn->prepare($sayfaSql);
    $sayfaStmt->execute([$currentPageUrl]);
    $sayfaBilgileri = $sayfaStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Hata durumunda varsayılan değerler
    $sayfaBilgileri = [
        'sayfa_adi' => 'Slider Yönetimi',
        'sayfa_aciklama' => 'API Slider yönetim sistemi',
        'sayfa_icon' => 'fa-images'
    ];
}

// Sayfa bilgilerini değişkenlere ata
$pageTitle = $sayfaBilgileri['sayfa_adi'] ?? 'Slider Yönetimi';
$pageDescription = $sayfaBilgileri['sayfa_aciklama'] ?? 'API Slider yönetim sistemi';
$pageIcon = $sayfaBilgileri['sayfa_icon'] ?? 'fa-images';

// Sayfa yetki kontrolü
$pagePermissions = checkPagePermission($currentPageUrl);
if (!$pagePermissions) {
    header('Location: /index.php');
    exit;
}

$message = '';
$messageType = '';

// Admin kontrolü - user_groups.id = 1 olanlar tam yetkiye sahip
$isAdmin = ($currentUser['group_id'] == 1);

// Dosya yükleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    try {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Dosya yükleme hatası']);
            exit;
        }
        
        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Sadece resim dosyaları yüklenebilir']);
            exit;
        }
        
        // Dosya adını oluştur
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'slider_' . uniqid() . '.' . $extension;
        
        // Upload klasörünü kontrol et
        $uploadDir = __DIR__ . '/../../../uploads/sliders/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $uploadPath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $url = '/uploads/sliders/' . $filename;
            echo json_encode(['success' => true, 'url' => $url]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Dosya kaydedilemedi']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Paket listesi AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'get_paketler') {
    try {
        $conn = getDatabaseConnection();
        $campaignId = $_POST['campaign_id'];
        
        if ($campaignId == 1) {
            // Satellite paketleri
            $sql = "SELECT 
                        API_GetSatelliteCampaignList_ID as id,
                        API_GetSatelliteCampaignList_PaketAdi as paket_adi,
                        API_GetSatelliteCampaignList_Fiyat as fiyat
                    FROM dbo.API_GetSatelliteCampaignList
                    WHERE API_GetSatelliteCampaignList_durum = 1
                    ORDER BY API_GetSatelliteCampaignList_PaketAdi";
        } elseif ($campaignId == 2) {
            // Neo paketleri
            $sql = "SELECT 
                        API_GetNeoCampaignList_ID as id,
                        API_GetNeoCampaignList_PaketAdi as paket_adi,
                        API_GetNeoCampaignList_Fiyat as fiyat
                    FROM dbo.API_GetNeoCampaignList
                    WHERE API_GetNeoCampaignList_durum = 1
                    ORDER BY API_GetNeoCampaignList_PaketAdi";
        } else {
            echo json_encode(['success' => false, 'message' => 'Geçersiz kampanya']);
            exit;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $paketler = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'paketler' => $paketler]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Durum güncelleme AJAX isteği
if (isset($_POST['action']) && $_POST['action'] === 'update_durum') {
    if (!$isAdmin && !$pagePermissions['duzenle']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok']);
        exit;
    }
    try {
        $conn = getDatabaseConnection();
        $durumValue = $_POST['durum'] === 'true' ? 1 : 0;
        $sql = "UPDATE dbo.API_Slider SET api_iris_slider_durum = ?, api_iris_slider_guncelleme_tarihi = GETDATE() WHERE api_iris_slider_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$durumValue, $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Durum başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Ekleme işlemi
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!$isAdmin && !$pagePermissions['ekle']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok']);
        exit;
    }
    try {
        $conn = getDatabaseConnection();
        
        $sql = "INSERT INTO dbo.API_Slider 
                (api_iris_slider_Adi, api_iris_slider_GorselURL, api_iris_slider_MobilGorselURL, 
                 api_iris_slider_BitisTarihi, api_iris_slider_CampaignList_ID, api_iris_slider_Paket_ID, 
                 api_iris_slider_durum, api_iris_slider_olusturma_tarihi) 
                VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $_POST['slider_adi'],
            $_POST['gorsel_url'],
            $_POST['mobil_gorsel_url'],
            $_POST['bitis_tarihi'] ?: null,
            $_POST['campaign_id'] ?: null,
            $_POST['paket_id'] ?: null,
            $_POST['durum'] ?? 1
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Slider başarıyla eklendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Güncelleme işlemi
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    if (!$isAdmin && !$pagePermissions['duzenle']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok']);
        exit;
    }
    try {
        $conn = getDatabaseConnection();
        
        $sql = "UPDATE dbo.API_Slider SET 
                api_iris_slider_Adi = ?,
                api_iris_slider_GorselURL = ?,
                api_iris_slider_MobilGorselURL = ?,
                api_iris_slider_BitisTarihi = ?,
                api_iris_slider_CampaignList_ID = ?,
                api_iris_slider_Paket_ID = ?,
                api_iris_slider_durum = ?,
                api_iris_slider_guncelleme_tarihi = GETDATE()
                WHERE api_iris_slider_ID = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $_POST['slider_adi'],
            $_POST['gorsel_url'],
            $_POST['mobil_gorsel_url'],
            $_POST['bitis_tarihi'] ?: null,
            $_POST['campaign_id'] ?: null,
            $_POST['paket_id'] ?: null,
            $_POST['durum'] ?? 1,
            $_POST['id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Slider başarıyla güncellendi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Silme işlemi
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$isAdmin && !$pagePermissions['sil']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok']);
        exit;
    }
    try {
        $conn = getDatabaseConnection();
        
        $sql = "DELETE FROM dbo.API_Slider WHERE api_iris_slider_ID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Slider başarıyla silindi']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
}

// Veritabanından verileri çek
try {
    // Slider verileri
    $sliderSql = "SELECT 
                    s.*,
                    cl.API_CampaignList_CampaignName
                  FROM dbo.API_Slider s
                  LEFT JOIN dbo.API_CampaignList cl ON s.api_iris_slider_CampaignList_ID = cl.API_CampaignList_ID
                  ORDER BY s.api_iris_slider_ID DESC";
    $sliderStmt = $conn->prepare($sliderSql);
    $sliderStmt->execute();
    $sliderVerileri = $sliderStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kampanya listesi
    $campaignSql = "SELECT API_CampaignList_ID, API_CampaignList_CampaignName FROM dbo.API_CampaignList ORDER BY API_CampaignList_CampaignName ASC";
    $campaignStmt = $conn->prepare($campaignSql);
    $campaignStmt->execute();
    $kampanyalar = $campaignStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $message = 'Veriler yüklenirken hata: ' . $e->getMessage();
    $messageType = 'danger';
}

include '../../../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="<?php echo htmlspecialchars($pageIcon); ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h2>
                <?php if ($isAdmin || $pagePermissions['ekle']): ?>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sliderModal" onclick="resetModal()">
                        <i class="fas fa-plus me-1"></i>Yeni Slider Ekle
                    </button>
                </div>
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

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="<?php echo htmlspecialchars($pageIcon); ?> me-2"></i><?php echo htmlspecialchars($pageTitle); ?>
            </h5>
        </div>
        <div class="card-body">
            <!-- Filtreleme Kartı -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Filtreleme
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Slider Adı</label>
                            <input type="text" class="form-control form-control-sm" id="filter-adi" placeholder="Slider adı...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Kampanya</label>
                            <input type="text" class="form-control form-control-sm" id="filter-kampanya" placeholder="Kampanya...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select form-select-sm" id="filter-durum">
                                <option value="">Tümü</option>
                                <option value="checked">Aktif</option>
                                <option value="unchecked">Pasif</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()">
                                    <i class="fas fa-filter me-1"></i>Filtrele
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                    <i class="fas fa-times me-1"></i>Temizle
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                        
            <div class="table-responsive">
                <table class="table table-hover" id="sliderTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Slider Adı</th>
                            <th>Masaüstü Görsel</th>
                            <th>Mobil Görsel</th>
                            <th>Bitiş Tarihi</th>
                            <th>Kampanya</th>
                            <th>Paket ID</th>
                            <th>Durum</th>
                            <th>Oluşturma Tarihi</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($sliderVerileri) && !empty($sliderVerileri)): ?>
                            <?php foreach ($sliderVerileri as $slider): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($slider['api_iris_slider_ID']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($slider['api_iris_slider_Adi']); ?></strong></td>
                                    <td>
                                        <?php if ($slider['api_iris_slider_GorselURL']): ?>
                                            <a href="<?php echo htmlspecialchars($slider['api_iris_slider_GorselURL']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-image"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($slider['api_iris_slider_MobilGorselURL']): ?>
                                            <a href="<?php echo htmlspecialchars($slider['api_iris_slider_MobilGorselURL']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-mobile-alt"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $slider['api_iris_slider_BitisTarihi'] ? date('d.m.Y H:i', strtotime($slider['api_iris_slider_BitisTarihi'])) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($slider['API_CampaignList_CampaignName'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($slider['api_iris_slider_Paket_ID'] ?? '-'); ?></td>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input durum-checkbox" type="checkbox" 
                                                   data-id="<?php echo $slider['api_iris_slider_ID']; ?>"
                                                   <?php echo $slider['api_iris_slider_durum'] ? 'checked' : ''; ?>>
                                        </div>
                                    </td>
                                    <td><?php echo $slider['api_iris_slider_olusturma_tarihi'] ? date('d.m.Y H:i', strtotime($slider['api_iris_slider_olusturma_tarihi'])) : '-'; ?></td>
                                    <td>
                                        <?php if ($isAdmin || $pagePermissions['duzenle']): ?>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="editSlider(<?php echo htmlspecialchars(json_encode($slider)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($isAdmin || $pagePermissions['sil']): ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteSlider(<?php echo $slider['api_iris_slider_ID']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$isAdmin && !$pagePermissions['duzenle'] && !$pagePermissions['sil']): ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
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

<!-- Slider Modal -->
<div class="modal fade" id="sliderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Yeni Slider Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="sliderForm">
                    <input type="hidden" id="slider_id" name="id">
                    <input type="hidden" id="action" name="action" value="add">
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Slider Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="slider_adi" name="slider_adi" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Masaüstü Görsel</label>
                            <input type="file" class="form-control" id="gorsel_file" accept="image/*" onchange="uploadImage(this, 'gorsel_url')">
                            <input type="text" class="form-control mt-2" id="gorsel_url" name="gorsel_url" placeholder="veya URL girin">
                            <small class="text-muted">Dosya seçin veya URL girin</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mobil Görsel</label>
                            <input type="file" class="form-control" id="mobil_gorsel_file" accept="image/*" onchange="uploadImage(this, 'mobil_gorsel_url')">
                            <input type="text" class="form-control mt-2" id="mobil_gorsel_url" name="mobil_gorsel_url" placeholder="veya URL girin">
                            <small class="text-muted">Dosya seçin veya URL girin</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bitiş Tarihi</label>
                            <input type="datetime-local" class="form-control" id="bitis_tarihi" name="bitis_tarihi">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kampanya</label>
                            <select class="form-select" id="campaign_id" name="campaign_id">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($kampanyalar as $kampanya): ?>
                                    <option value="<?php echo $kampanya['API_CampaignList_ID']; ?>">
                                        <?php echo htmlspecialchars($kampanya['API_CampaignList_CampaignName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Paket</label>
                            <select class="form-select" id="paket_id" name="paket_id">
                                <option value="">Önce kampanya seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Durum</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="durum" name="durum" value="1" checked>
                                <label class="form-check-label" for="durum">Aktif</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>İptal
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveSlider()">
                    <i class="fas fa-save me-1"></i>Kaydet
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// DataTable başlatma
$(document).ready(function() {
    // Tablo yapısını kontrol et
    var theadCols = $('#sliderTable thead th').length;
    var tbodyRows = $('#sliderTable tbody tr').length;
    
    console.log('=== TABLO YAPISI ===');
    console.log('Thead sütun sayısı:', theadCols);
    console.log('Tbody satır sayısı:', tbodyRows);
    
    $('#sliderTable tbody tr').each(function(index) {
        var cols = $(this).find('td').length;
        var colspan = $(this).find('td[colspan]').attr('colspan');
        console.log('Satır ' + (index + 1) + ' sütun sayısı:', cols, colspan ? '(colspan: ' + colspan + ')' : '');
    });
    
    var table = $('#sliderTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.25/i18n/Turkish.json",
            "emptyTable": "Kayıt bulunamadı"
        },
        "pageLength": 25,
        "lengthMenu": [10, 25, 50, 100],
        "order": [[0, "desc"]],
        "columnDefs": [
            { "orderable": false, "targets": [7, -1] }
        ],
        "ordering": true,
        "info": true,
        "paging": true,
        "searching": true
    });
    
    // Filtreleme fonksiyonları
    window.applyFilters = function() {
        table.column(1).search($('#filter-adi').val());
        table.column(5).search($('#filter-kampanya').val());
        applyCheckboxFilters();
        table.draw();
    };
    
    function applyCheckboxFilters() {
        var durumFilter = $('#filter-durum').val();
        
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('sliderTable') === -1;
        });
        
        if (durumFilter) {
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'sliderTable') {
                    return true;
                }
                
                var row = table.row(dataIndex).node();
                var durumCheckbox = $(row).find('td:eq(7) input[type="checkbox"]');
                
                if (durumFilter === 'checked' && !durumCheckbox.is(':checked')) {
                    return false;
                }
                if (durumFilter === 'unchecked' && durumCheckbox.is(':checked')) {
                    return false;
                }
                
                return true;
            });
        }
    }
    
    window.clearFilters = function() {
        $('#filter-adi, #filter-kampanya').val('');
        $('#filter-durum').val('');
        
        table.search('').columns().search('').draw();
        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search.filter(function(fn) {
            return fn.toString().indexOf('sliderTable') === -1;
        });
        table.draw();
    };
    
    // Enter tuşuna basınca filtrele
    $('#filter-adi, #filter-kampanya').on('keypress', function(e) {
        if (e.which === 13) {
            applyFilters();
        }
    });
    
    // Kampanya değişince paketleri yükle
    $('#campaign_id').on('change', function() {
        const campaignId = $(this).val();
        const $paketSelect = $('#paket_id');
        
        $paketSelect.html('<option value="">Yükleniyor...</option>');
        
        if (!campaignId) {
            $paketSelect.html('<option value="">Önce kampanya seçiniz...</option>');
            return;
        }
        
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                action: 'get_paketler',
                campaign_id: campaignId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $paketSelect.html('<option value="">Paket seçiniz...</option>');
                    response.paketler.forEach(function(paket) {
                        $paketSelect.append(
                            '<option value="' + paket.id + '">' + 
                            paket.paket_adi + ' - ' + paket.fiyat + ' TL' +
                            '</option>'
                        );
                    });
                } else {
                    $paketSelect.html('<option value="">Hata oluştu</option>');
                    showMessage('danger', response.message);
                }
            },
            error: function() {
                $paketSelect.html('<option value="">Hata oluştu</option>');
                showMessage('danger', 'Paketler yüklenirken hata oluştu');
            }
        });
    });
});

// Dosya yükleme fonksiyonu
function uploadImage(input, targetInputId) {
    if (!input.files || !input.files[0]) {
        return;
    }
    
    const file = input.files[0];
    const formData = new FormData();
    formData.append('action', 'upload_image');
    formData.append('image', file);
    
    // Yükleniyor göstergesi
    $('#' + targetInputId).val('Yükleniyor...').prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#' + targetInputId).val(response.url);
                showMessage('success', 'Dosya başarıyla yüklendi');
            } else {
                $('#' + targetInputId).val('');
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $('#' + targetInputId).val('');
            showMessage('danger', 'Dosya yüklenirken hata oluştu');
        },
        complete: function() {
            $('#' + targetInputId).prop('disabled', false);
            $(input).val('');
        }
    });
}

// Modal reset
function resetModal() {
    $('#sliderForm')[0].reset();
    $('#slider_id').val('');
    $('#action').val('add');
    $('#modalTitle').text('Yeni Slider Ekle');
    $('#durum').prop('checked', true);
    $('#paket_id').html('<option value="">Önce kampanya seçiniz...</option>');
}

// Slider düzenleme
function editSlider(slider) {
    $('#slider_id').val(slider.api_iris_slider_ID);
    $('#action').val('update');
    $('#modalTitle').text('Slider Düzenle');
    $('#slider_adi').val(slider.api_iris_slider_Adi);
    $('#gorsel_url').val(slider.api_iris_slider_GorselURL);
    $('#mobil_gorsel_url').val(slider.api_iris_slider_MobilGorselURL);
    
    if (slider.api_iris_slider_BitisTarihi) {
        const date = new Date(slider.api_iris_slider_BitisTarihi);
        const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        $('#bitis_tarihi').val(localDate.toISOString().slice(0, 16));
    }
    
    $('#campaign_id').val(slider.api_iris_slider_CampaignList_ID || '');
    
    // Kampanya seçildiyse paketleri yükle
    if (slider.api_iris_slider_CampaignList_ID) {
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                action: 'get_paketler',
                campaign_id: slider.api_iris_slider_CampaignList_ID
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#paket_id').html('<option value="">Paket seçiniz...</option>');
                    response.paketler.forEach(function(paket) {
                        $('#paket_id').append(
                            '<option value="' + paket.id + '">' + 
                            paket.paket_adi + ' - ' + paket.fiyat + ' TL' +
                            '</option>'
                        );
                    });
                    $('#paket_id').val(slider.api_iris_slider_Paket_ID || '');
                }
            }
        });
    }
    
    $('#durum').prop('checked', slider.api_iris_slider_durum == 1);
    
    $('#sliderModal').modal('show');
}

// Slider kaydetme
function saveSlider() {
    const formData = new FormData($('#sliderForm')[0]);
    formData.set('durum', $('#durum').is(':checked') ? 1 : 0);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
                $('#sliderModal').modal('hide');
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('danger', response.message);
            }
        },
        error: function() {
            showMessage('danger', 'İşlem sırasında bir hata oluştu.');
        }
    });
}

// Slider silme
function deleteSlider(id) {
    if (!confirm('Bu slider\'ı silmek istediğinizden emin misiniz?')) {
        return;
    }
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'delete',
            id: id
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('danger', response.message);
            }
        },
        error: function() {
            showMessage('danger', 'Silme işlemi sırasında bir hata oluştu.');
        }
    });
}

// Durum checkbox değiştirme
$(document).on('change', '.durum-checkbox', function() {
    const id = $(this).data('id');
    const isChecked = $(this).is(':checked');
    const $this = $(this);
    
    $this.prop('disabled', true);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'update_durum',
            id: id,
            durum: isChecked
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                $this.prop('checked', !isChecked);
                showMessage('danger', response.message);
            }
        },
        error: function() {
            $this.prop('checked', !isChecked);
            showMessage('danger', 'Durum güncellenirken bir hata oluştu.');
        },
        complete: function() {
            $this.prop('disabled', false);
        }
    });
});

// Mesaj gösterme
function showMessage(type, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    $('.alert').remove();
    $('.container-fluid').first().prepend(alertHtml);
    
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 3000);
}
</script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<?php include '../../../includes/footer.php'; ?>