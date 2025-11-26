<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once '../../../auth.php';

// Kullanıcı kimlik doğrulaması
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

// Sayfa başlığı ve breadcrumb
$pageTitle = 'Iris Karşılaştırma';
$breadcrumbs = [
    ['title' => 'Iris Karşılaştırma']
];

// Değişkenleri başlat
$duplicates = [];
$totalDuplicateMemos = 0;
$totalDuplicateRecords = 0;
$error = null;

// Veritabanı bağlantısı
try {
    $conn = getDatabaseConnection();
    
    // Önce tablo ve sütunların varlığını kontrol et
    $checkTableSql = "SELECT COUNT(*) as table_exists FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'digiturk' AND TABLE_NAME = 'iris_rapor'";
    $checkStmt = $conn->prepare($checkTableSql);
    $checkStmt->execute();
    $tableCheck = $checkStmt->fetch();
    
    if ($tableCheck['table_exists'] == 0) {
        throw new Exception('iris_rapor tablosu bulunamadı. Lütfen önce raporları yükleyiniz.');
    }
    
    // Gerekli sütunların varlığını kontrol et
    $checkColumnsSql = "
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'digiturk' AND TABLE_NAME = 'iris_rapor' 
        AND COLUMN_NAME IN ('MEMO_ID', 'GUNCEL_OUTLET_DURUM', 'TEYIT_DURUM', 'dosya_adi', 'eklenme_tarihi', 'guncellenme_tarihi')
    ";
    $checkColStmt = $conn->prepare($checkColumnsSql);
    $checkColStmt->execute();
    $columns = $checkColStmt->fetchAll();
    
    if (count($columns) < 4) { // En az temel sütunlar olmalı
        throw new Exception('iris_rapor tablosunda gerekli sütunlar bulunamadı. Tablo yapısı eksik veya hatalı.');
    }
    
    // Mükerrer MEMO_ID'leri getir
    $sql = "
        WITH DuplicateMemos AS (
            SELECT MEMO_ID, COUNT(*) as kayit_sayisi
            FROM digiturk.iris_rapor 
            WHERE MEMO_ID IS NOT NULL AND MEMO_ID != ''
            GROUP BY MEMO_ID
            HAVING COUNT(*) > 1
        )
        SELECT 
            ISNULL(ir.dosya_adi, 'Belirtilmemiş') as 'Dosya Adı',
            ir.MEMO_ID as 'Memo ID',
            ISNULL(ir.GUNCEL_OUTLET_DURUM, '') as 'Güncel Outlet',
            ISNULL(ir.TEYIT_DURUM, '') as 'Teyit Durumu',
            ir.eklenme_tarihi as 'Eklenme Tarihi',
            ir.guncellenme_tarihi as 'Güncellenme Tarihi',
            dm.kayit_sayisi as 'Toplam Kayıt Sayısı'
        FROM digiturk.iris_rapor ir
        INNER JOIN DuplicateMemos dm ON ir.MEMO_ID = dm.MEMO_ID
        ORDER BY ir.MEMO_ID, ir.eklenme_tarihi DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Toplam mükerrer MEMO_ID sayısı
    $countSql = "
        SELECT COUNT(DISTINCT MEMO_ID) as total_duplicate_memos,
               SUM(kayit_sayisi) as total_duplicate_records
        FROM (
            SELECT MEMO_ID, COUNT(*) as kayit_sayisi
            FROM digiturk.iris_rapor 
            WHERE MEMO_ID IS NOT NULL AND MEMO_ID != ''
            GROUP BY MEMO_ID
            HAVING COUNT(*) > 1
        ) as temp
    ";
    
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $stats = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalDuplicateMemos = $stats['total_duplicate_memos'] ?? 0;
    $totalDuplicateRecords = $stats['total_duplicate_records'] ?? 0;

} catch (Exception $e) {
    $error = "Hata oluştu: " . $e->getMessage();
    // Log the error for debugging
    error_log("Iris Karsilastirma Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
}
?>

<?php 
try {
    include '../../../includes/header.php'; 
} catch (Exception $e) {
    echo "<!DOCTYPE html><html><head><title>Hata</title></head><body>";
    echo "<div class='alert alert-danger'>Header dosyası yüklenirken hata: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-copy me-2"></i>
                    Iris Rapor - Mükerrer MEMO_ID Karşılaştırma
                </h5>
                <?php if (isset($duplicates) && is_array($duplicates) && count($duplicates) > 0): ?>
                <button class="btn btn-success btn-sm" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i>
                    Excel'e Aktar
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php else: ?>
                    <!-- İstatistikler -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($totalDuplicateMemos); ?></h3>
                                    <p class="mb-0">Mükerrer MEMO_ID Sayısı</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-0"><?php echo number_format($totalDuplicateRecords); ?></h3>
                                    <p class="mb-0">Toplam Mükerrer Kayıt Sayısı</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($duplicates) && is_array($duplicates) && count($duplicates) > 0): ?>
                        <!-- Filtre ve Arama -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="searchInput" placeholder="MEMO_ID veya Dosya Adı ara...">
                            </div>
                            <div class="col-md-4">
                                <select class="form-control" id="outletFilter">
                                    <option value="">Tüm Outlet Durumları</option>
                                    <?php
                                    $outlets = array_unique(array_column($duplicates, 'Güncel Outlet'));
                                    $outlets = array_filter($outlets); // Boş değerleri filtrele
                                    foreach ($outlets as $outlet):
                                        if (!empty($outlet)):
                                    ?>
                                    <option value="<?php echo htmlspecialchars($outlet); ?>">
                                        <?php echo htmlspecialchars($outlet); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-control" id="teyitFilter">
                                    <option value="">Tüm Teyit Durumları</option>
                                    <?php
                                    $teyitler = array_unique(array_column($duplicates, 'Teyit Durumu'));
                                    $teyitler = array_filter($teyitler); // Boş değerleri filtrele
                                    foreach ($teyitler as $teyit):
                                        if (!empty($teyit)):
                                    ?>
                                    <option value="<?php echo htmlspecialchars($teyit); ?>">
                                        <?php echo htmlspecialchars($teyit); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Tablo -->
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="duplicatesTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Sıra</th>
                                        <th>Dosya Adı</th>
                                        <th>MEMO_ID</th>
                                        <th>Güncel Outlet</th>
                                        <th>Teyit Durumu</th>
                                        <th>Kayıt Sayısı</th>
                                        <th>Eklenme Tarihi</th>
                                        <th>Güncellenme Tarihi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (isset($duplicates) && is_array($duplicates)):
                                        $currentMemoId = null;
                                        $counter = 1;
                                        foreach ($duplicates as $row): 
                                            $isNewGroup = ($currentMemoId !== $row['Memo ID']);
                                            if ($isNewGroup) {
                                                $currentMemoId = $row['Memo ID'];
                                            }
                                    ?>
                                    <tr class="<?php echo $isNewGroup ? 'table-warning' : ''; ?>">
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo htmlspecialchars($row['Dosya Adı'] ?? 'Belirtilmemiş'); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['Memo ID'] ?? ''); ?></strong>
                                            <?php if ($isNewGroup): ?>
                                                <span class="badge bg-danger ms-2"><?php echo intval($row['Toplam Kayıt Sayısı'] ?? 0); ?> adet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['Güncel Outlet'])): ?>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($row['Güncel Outlet']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['Teyit Durumu'])): ?>
                                                <span class="badge bg-<?php echo (strpos(strtolower($row['Teyit Durumu']), 'onay') !== false) ? 'success' : 'secondary'; ?>">
                                                    <?php echo htmlspecialchars($row['Teyit Durumu']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark">
                                                <?php echo intval($row['Toplam Kayıt Sayısı'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['Eklenme Tarihi'])): ?>
                                                <?php echo date('d.m.Y H:i', strtotime($row['Eklenme Tarihi'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['Güncellenme Tarihi'])): ?>
                                                <?php echo date('d.m.Y H:i', strtotime($row['Güncellenme Tarihi'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                        endforeach; 
                                    endif;
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Sayfalama ve sonuçlar -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <p class="text-muted">
                                    Toplam <strong><?php echo isset($duplicates) && is_array($duplicates) ? count($duplicates) : 0; ?></strong> mükerrer kayıt gösteriliyor.
                                </p>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Harika!</strong> Sistemde mükerrer MEMO_ID bulunamadı.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$additionalJS = '
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
$(document).ready(function() {
    // Arama fonksiyonu
    function filterTable() {
        var searchValue = $("#searchInput").val().toLowerCase();
        var outletValue = $("#outletFilter").val();
        var teyitValue = $("#teyitFilter").val();
        
        $("#duplicatesTable tbody tr").each(function() {
            var row = $(this);
            var memoId = row.find("td:eq(2)").text().toLowerCase();
            var dosyaAdi = row.find("td:eq(1)").text().toLowerCase();
            var outlet = row.find("td:eq(3)").text();
            var teyit = row.find("td:eq(4)").text();
            
            var showRow = true;
            
            // Arama filtresi
            if (searchValue && memoId.indexOf(searchValue) === -1 && dosyaAdi.indexOf(searchValue) === -1) {
                showRow = false;
            }
            
            // Outlet filtresi
            if (outletValue && outlet.indexOf(outletValue) === -1) {
                showRow = false;
            }
            
            // Teyit filtresi
            if (teyitValue && teyit.indexOf(teyitValue) === -1) {
                showRow = false;
            }
            
            if (showRow) {
                row.show();
            } else {
                row.hide();
            }
        });
    }
    
    // Olay dinleyicileri
    $("#searchInput").on("keyup", filterTable);
    $("#outletFilter").on("change", filterTable);
    $("#teyitFilter").on("change", filterTable);
});

// Excel\'e aktarma fonksiyonu
function exportToExcel() {
    // Görünür satırları al
    var visibleRows = [];
    $("#duplicatesTable tbody tr:visible").each(function() {
        var row = [];
        $(this).find("td").each(function(index) {
            if (index === 2) { // MEMO_ID sütunu - badge\'ı temizle
                row.push($(this).find("strong").text());
            } else if (index === 3 || index === 4) { // Badge içeren sütunlar
                var badgeText = $(this).find(".badge").text();
                row.push(badgeText || $(this).text().replace("-", ""));
            } else if (index === 5) { // Kayıt sayısı
                row.push($(this).find(".badge").text());
            } else {
                row.push($(this).text());
            }
        });
        visibleRows.push(row);
    });
    
    if (visibleRows.length === 0) {
        alert("Excel\'e aktarılacak veri bulunamadı!");
        return;
    }
    
    // Başlık satırı
    var headers = ["Sıra", "Dosya Adı", "MEMO_ID", "Güncel Outlet", "Teyit Durumu", "Kayıt Sayısı", "Eklenme Tarihi", "Güncellenme Tarihi"];
    
    // Workbook oluştur
    var wb = XLSX.utils.book_new();
    var wsData = [headers].concat(visibleRows);
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    
    // Sütun genişliklerini ayarla
    ws["!cols"] = [
        {wch: 8},   // Sıra
        {wch: 25},  // Dosya Adı
        {wch: 15},  // MEMO_ID
        {wch: 20},  // Güncel Outlet
        {wch: 20},  // Teyit Durumu
        {wch: 12},  // Kayıt Sayısı
        {wch: 18},  // Eklenme Tarihi
        {wch: 18}   // Güncellenme Tarihi
    ];
    
    XLSX.utils.book_append_sheet(wb, ws, "Mükerrer MEMO_ID");
    
    // Dosya adı oluştur
    var today = new Date();
    var dateStr = today.getFullYear() + "-" + 
                  String(today.getMonth() + 1).padStart(2, "0") + "-" + 
                  String(today.getDate()).padStart(2, "0");
    var filename = "iris_mukerrer_memo_id_" + dateStr + ".xlsx";
    
    XLSX.writeFile(wb, filename);
}
</script>
';
?>

<?php
try {
    include '../../../includes/footer.php';
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Footer dosyası yüklenirken hata: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "</body></html>";
}
?>
