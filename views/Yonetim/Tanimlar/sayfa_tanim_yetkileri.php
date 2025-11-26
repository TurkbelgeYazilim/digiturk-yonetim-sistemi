<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pageTitle = "Sayfa Tanım ve Yetkilendirme";
$breadcrumbs = [
    ['title' => 'Sayfa Tanım ve Yetkilendirme']
];

// Auth kontrol
try {
    require_once '../../../auth.php';
    $currentUser = checkAdminAuth(); // Sadece admin erişebilir
} catch (Exception $e) {
    die("Auth hatası: " . $e->getMessage());
}

$message = '';
$messageType = '';

// İşlemler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_modul':
                $modulAdi = trim($_POST['modul_adi']);
                $modulUrl = trim($_POST['modul_url']);
                // Başındaki / karakterini kaldır ve temizle
                if (!empty($modulUrl)) {
                    $modulUrl = ltrim($modulUrl, '/');
                } else {
                    $modulUrl = null;
                }
                $aciklama = trim($_POST['aciklama'] ?? '');
                $modulIkon = trim($_POST['modul_ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                
                if (empty($modulAdi)) {
                    $message = 'Modül adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Modül adı kontrolü
                $checkSql = "SELECT modul_id FROM tanim_moduller WHERE modul_adi = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$modulAdi]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu modül adı zaten kullanılmaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                // Modül klasörünü oluştur
                if (!empty($modulUrl)) {
                    $viewsBase = dirname(__DIR__, 2);
                    $modulPath = $viewsBase . '/' . $modulUrl;
                    if (!is_dir($modulPath)) {
                        if (!mkdir($modulPath, 0755, true)) {
                            $message = 'Modül klasörü oluşturulamadı: ' . $modulPath;
                            $messageType = 'danger';
                            break;
                        }
                    }
                }
                
                $sql = "INSERT INTO tanim_moduller (modul_adi, modul_url, aciklama, modul_ikon, sira_no, durum, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$modulAdi, $modulUrl, $aciklama, $modulIkon, $siraNo, $durum]);
                
                $message = 'Modül başarıyla tanımlandı ve klasör oluşturuldu.';
                $messageType = 'success';
                break;
                
            case 'edit_modul':
                $modulId = (int)$_POST['modul_id'];
                $modulAdi = trim($_POST['modul_adi']);
                $modulUrl = trim($_POST['modul_url']);
                // Başındaki / karakterini kaldır ve temizle
                if (!empty($modulUrl)) {
                    $modulUrl = ltrim($modulUrl, '/');
                } else {
                    $modulUrl = null;
                }
                $aciklama = trim($_POST['aciklama'] ?? '');
                $modulIkon = trim($_POST['modul_ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                
                if ($modulId <= 0) {
                    $message = 'Geçersiz modül ID.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($modulAdi)) {
                    $message = 'Modül adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Modül adı kontrolü (mevcut kayıt hariç)
                $checkSql = "SELECT modul_id FROM tanim_moduller WHERE modul_adi = ? AND modul_id != ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$modulAdi, $modulId]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu modül adı başka bir kayıtta kullanılmaktadır.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "UPDATE tanim_moduller SET modul_adi = ?, modul_url = ?, aciklama = ?, modul_ikon = ?, sira_no = ?, durum = ?, updated_at = GETDATE() 
                        WHERE modul_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$modulAdi, $modulUrl, $aciklama, $modulIkon, $siraNo, $durum, $modulId]);
                
                $message = 'Modül başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'add_menu':
                $modulId = (int)$_POST['modul_id'];
                $menuAdi = trim($_POST['menu_adi']);
                $menuUrl = trim($_POST['menu_url']);
                $ikon = trim($_POST['ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                
                if ($modulId <= 0) {
                    $message = 'Lütfen bir modül seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($menuAdi)) {
                    $message = 'Menü adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Menü klasörünü oluştur
                if (!empty($menuUrl)) {
                    // Modülün URL'sini bul
                    $modulSql = "SELECT modul_url FROM tanim_moduller WHERE modul_id = ?";
                    $modulStmt = $conn->prepare($modulSql);
                    $modulStmt->execute([$modulId]);
                    $modul = $modulStmt->fetch();
                    
                    if ($modul && !empty($modul['modul_url'])) {
                        $viewsBase = dirname(__DIR__, 2);
                        $menuPath = $viewsBase . '/' . $modul['modul_url'] . '/' . $menuUrl;
                        if (!is_dir($menuPath)) {
                            if (!mkdir($menuPath, 0755, true)) {
                                $message = 'Menü klasörü oluşturulamadı: ' . $menuPath;
                                $messageType = 'danger';
                                break;
                            }
                        }
                    }
                }
                
                $sql = "INSERT INTO tanim_menuler (modul_id, menu_adi, menu_url, ikon, sira_no, durum, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$modulId, $menuAdi, $menuUrl, $ikon, $siraNo, $durum]);
                
                $message = 'Menü başarıyla tanımlandı ve klasör oluşturuldu.';
                $messageType = 'success';
                break;
                
            case 'edit_menu':
                $menuId = (int)$_POST['menu_id'];
                $modulId = (int)$_POST['modul_id'];
                $menuAdi = trim($_POST['menu_adi']);
                $menuUrl = trim($_POST['menu_url']);
                $ikon = trim($_POST['ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                
                if ($menuId <= 0) {
                    $message = 'Geçersiz menü ID.';
                    $messageType = 'danger';
                    break;
                }
                
                if ($modulId <= 0) {
                    $message = 'Lütfen bir modül seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($menuAdi)) {
                    $message = 'Menü adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Eski menü bilgilerini al (klasör taşıma için)
                $oldMenuSql = "SELECT m.menu_url, m.modul_id, mod.modul_url 
                               FROM tanim_menuler m 
                               LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
                               WHERE m.menu_id = ?";
                $oldMenuStmt = $conn->prepare($oldMenuSql);
                $oldMenuStmt->execute([$menuId]);
                $oldMenuData = $oldMenuStmt->fetch();
                
                // Yeni modül bilgilerini al
                $newModulSql = "SELECT modul_url FROM tanim_moduller WHERE modul_id = ?";
                $newModulStmt = $conn->prepare($newModulSql);
                $newModulStmt->execute([$modulId]);
                $newModulData = $newModulStmt->fetch();
                
                // Modül değiştiyse ve her iki modülün de URL'i varsa klasörü taşı
                if ($oldMenuData && $newModulData && 
                    $oldMenuData['modul_id'] != $modulId && 
                    !empty($oldMenuData['modul_url']) && 
                    !empty($newModulData['modul_url']) && 
                    !empty($oldMenuData['menu_url'])) {
                    
                    $viewsBase = dirname(__DIR__, 2);
                    $oldPath = $viewsBase . '/' . $oldMenuData['modul_url'] . '/' . $oldMenuData['menu_url'];
                    $newPath = $viewsBase . '/' . $newModulData['modul_url'] . '/' . $oldMenuData['menu_url'];
                    
                    // Eski klasör varsa ve yeni klasör yoksa taşı
                    if (is_dir($oldPath) && !is_dir($newPath)) {
                        // Hedef modül klasörü yoksa oluştur
                        $targetModulDir = $viewsBase . '/' . $newModulData['modul_url'];
                        if (!is_dir($targetModulDir)) {
                            mkdir($targetModulDir, 0755, true);
                        }
                        
                        // Menü klasörünü taşı
                        if (rename($oldPath, $newPath)) {
                            $message = 'Menü güncellendi ve klasör yeni modül altına taşındı.';
                        } else {
                            $message = 'Menü güncellendi ancak klasör taşınamadı.';
                        }
                    }
                }
                
                $sql = "UPDATE tanim_menuler SET modul_id = ?, menu_adi = ?, menu_url = ?, ikon = ?, sira_no = ?, durum = ?, updated_at = GETDATE() 
                        WHERE menu_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$modulId, $menuAdi, $menuUrl, $ikon, $siraNo, $durum, $menuId]);
                
                if (empty($message)) {
                    $message = 'Menü başarıyla güncellendi.';
                }
                $messageType = 'success';
                break;
                
            case 'add_sayfa':
                $menuId = (int)$_POST['menu_id'];
                $sayfaAdi = trim($_POST['sayfa_adi']);
                $sayfaUrl = trim($_POST['sayfa_url']);
                $aciklama = trim($_POST['aciklama'] ?? '');
                $sayfaIkon = trim($_POST['sayfa_ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                $dashboard = isset($_POST['dashboard']) ? 1 : 0;
                
                if ($menuId <= 0) {
                    $message = 'Lütfen bir menü seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($sayfaAdi)) {
                    $message = 'Sayfa adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Sayfa dosyasını oluştur
                if (!empty($sayfaUrl)) {
                    // Menü ve modül bilgilerini al
                    $menuSql = "SELECT m.menu_url, mod.modul_url 
                                FROM tanim_menuler m 
                                LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
                                WHERE m.menu_id = ?";
                    $menuStmt = $conn->prepare($menuSql);
                    $menuStmt->execute([$menuId]);
                    $menuData = $menuStmt->fetch();
                    
                    if ($menuData && !empty($menuData['modul_url']) && !empty($menuData['menu_url'])) {
                        $viewsBase = dirname(__DIR__, 2);
                        $filePath = $viewsBase . '/' . $menuData['modul_url'] . '/' . $menuData['menu_url'] . '/' . $sayfaUrl;
                        
                        // Eğer dosya yoksa oluştur
                        if (!file_exists($filePath)) {
                            // Temel PHP template
                            $template = <<<'PHP'
<?php
$pageTitle = "{SAYFA_ADI}";
$breadcrumbs = [
    ['title' => '{SAYFA_ADI}']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

// Sayfa yetkilerini kontrol et
$pagePermissions = checkPagePermission($currentUser['user_id']);
if (!$pagePermissions['gor']) {
    header('Location: /index.php');
    exit;
}

$message = '';
$messageType = '';

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        // İşlemler buraya gelecek
        
        $message = 'İşlem başarılı.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas {SAYFA_IKON} me-2"></i>{SAYFA_ADI}</h2>
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
            <h5 class="mb-0">{SAYFA_ADI}</h5>
        </div>
        <div class="card-body">
            <p>Bu sayfa içeriği buraya gelecek.</p>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>
PHP;
                            
                            $template = str_replace('{SAYFA_ADI}', $sayfaAdi, $template);
                            $template = str_replace('{SAYFA_IKON}', $sayfaIkon ?: 'fa-file', $template);
                            
                            if (!file_put_contents($filePath, $template)) {
                                $message = 'Sayfa dosyası oluşturulamadı: ' . $filePath;
                                $messageType = 'danger';
                                break;
                            }
                        }
                    }
                }
                
                $sql = "INSERT INTO tanim_sayfalar (menu_id, sayfa_adi, sayfa_url, aciklama, sayfa_ikon, sira_no, durum, dashboard, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$menuId, $sayfaAdi, $sayfaUrl, $aciklama, $sayfaIkon, $siraNo, $durum, $dashboard]);
                
                $message = 'Sayfa başarıyla tanımlandı ve dosya oluşturuldu.';
                $messageType = 'success';
                break;
                
            case 'edit_sayfa':
                $sayfaId = (int)$_POST['sayfa_id'];
                $menuId = (int)$_POST['menu_id'];
                $sayfaAdi = trim($_POST['sayfa_adi']);
                $sayfaUrl = trim($_POST['sayfa_url']);
                $aciklama = trim($_POST['aciklama'] ?? '');
                $sayfaIkon = trim($_POST['sayfa_ikon'] ?? '');
                $siraNo = (int)($_POST['sira_no'] ?? 0);
                $durum = $_POST['durum'] ?? 1;
                $dashboard = isset($_POST['dashboard']) ? 1 : 0;
                
                if ($sayfaId <= 0) {
                    $message = 'Geçersiz sayfa ID.';
                    $messageType = 'danger';
                    break;
                }
                
                if ($menuId <= 0) {
                    $message = 'Lütfen bir menü seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if (empty($sayfaAdi)) {
                    $message = 'Sayfa adı alanı zorunludur.';
                    $messageType = 'danger';
                    break;
                }
                
                // Eski sayfa bilgilerini al (dosya taşıma için)
                $oldSayfaSql = "SELECT s.sayfa_url, s.menu_id, m.menu_url, m.modul_id, mod.modul_url 
                               FROM tanim_sayfalar s 
                               LEFT JOIN tanim_menuler m ON s.menu_id = m.menu_id 
                               LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
                               WHERE s.sayfa_id = ?";
                $oldSayfaStmt = $conn->prepare($oldSayfaSql);
                $oldSayfaStmt->execute([$sayfaId]);
                $oldSayfaData = $oldSayfaStmt->fetch();
                
                // Yeni menü bilgilerini al
                $newMenuSql = "SELECT m.menu_url, m.modul_id, mod.modul_url 
                               FROM tanim_menuler m 
                               LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
                               WHERE m.menu_id = ?";
                $newMenuStmt = $conn->prepare($newMenuSql);
                $newMenuStmt->execute([$menuId]);
                $newMenuData = $newMenuStmt->fetch();
                
                // Modül veya menü değiştiyse ve dosya varsa taşı
                if ($oldSayfaData && $newMenuData && 
                    !empty($oldSayfaData['modul_url']) && 
                    !empty($oldSayfaData['menu_url']) && 
                    !empty($newMenuData['modul_url']) && 
                    !empty($newMenuData['menu_url']) && 
                    !empty($oldSayfaData['sayfa_url'])) {
                    
                    $viewsBase = dirname(__DIR__, 2);
                    $oldPath = $viewsBase . '/' . $oldSayfaData['modul_url'] . '/' . $oldSayfaData['menu_url'] . '/' . $oldSayfaData['sayfa_url'];
                    $newPath = $viewsBase . '/' . $newMenuData['modul_url'] . '/' . $newMenuData['menu_url'] . '/' . $oldSayfaData['sayfa_url'];
                    
                    // Eski ve yeni yol farklıysa taşı (menü veya modül değişimi)
                    if ($oldPath !== $newPath && file_exists($oldPath) && !file_exists($newPath)) {
                        // Hedef klasör yoksa oluştur
                        $targetDir = $viewsBase . '/' . $newMenuData['modul_url'] . '/' . $newMenuData['menu_url'];
                        if (!is_dir($targetDir)) {
                            mkdir($targetDir, 0755, true);
                        }
                        
                        // Dosyayı taşı
                        if (rename($oldPath, $newPath)) {
                            $message = 'Sayfa güncellendi ve dosya yeni konuma taşındı.';
                        } else {
                            $message = 'Sayfa güncellendi ancak dosya taşınamadı.';
                        }
                    }
                }
                
                $sql = "UPDATE tanim_sayfalar SET menu_id = ?, sayfa_adi = ?, sayfa_url = ?, aciklama = ?, sayfa_ikon = ?, sira_no = ?, durum = ?, dashboard = ?, updated_at = GETDATE() 
                        WHERE sayfa_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$menuId, $sayfaAdi, $sayfaUrl, $aciklama, $sayfaIkon, $siraNo, $durum, $dashboard, $sayfaId]);
                
                if (empty($message)) {
                    $message = 'Sayfa başarıyla güncellendi.';
                }
                $messageType = 'success';
                break;
                
            case 'add_yetki':
                $sayfaId = (int)$_POST['sayfa_id'];
                $userGroupId = (int)$_POST['user_group_id'];
                $gor = isset($_POST['gor']) ? 1 : 0;
                $kendiKullaniciniGor = isset($_POST['kendi_kullanicini_gor']) ? 1 : 0;
                $ekle = isset($_POST['ekle']) ? 1 : 0;
                $duzenle = isset($_POST['duzenle']) ? 1 : 0;
                $sil = isset($_POST['sil']) ? 1 : 0;
                $durum = $_POST['durum'] ?? 1;
                
                if ($sayfaId <= 0) {
                    $message = 'Lütfen bir sayfa seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if ($userGroupId <= 0) {
                    $message = 'Lütfen bir kullanıcı grubu seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                // Yetki kontrolü - aynı sayfa ve grup için tekrar yetki tanımlanmasın
                $checkSql = "SELECT sayfa_yetki_id FROM tanim_sayfa_yetkiler WHERE sayfa_id = ? AND user_group_id = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$sayfaId, $userGroupId]);
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu sayfa için bu kullanıcı grubuna zaten yetki tanımlanmıştır.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "INSERT INTO tanim_sayfa_yetkiler (sayfa_id, user_group_id, gor, kendi_kullanicini_gor, ekle, duzenle, sil, durum, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$sayfaId, $userGroupId, $gor, $kendiKullaniciniGor, $ekle, $duzenle, $sil, $durum]);
                
                $message = 'Yetki başarıyla tanımlandı.';
                $messageType = 'success';
                break;
                
            case 'edit_yetki':
                $yetkiId = (int)$_POST['yetki_id'];
                $gor = isset($_POST['gor']) ? 1 : 0;
                $kendiKullaniciniGor = isset($_POST['kendi_kullanicini_gor']) ? 1 : 0;
                $ekle = isset($_POST['ekle']) ? 1 : 0;
                $duzenle = isset($_POST['duzenle']) ? 1 : 0;
                $sil = isset($_POST['sil']) ? 1 : 0;
                $durum = $_POST['durum'] ?? 1;
                
                if ($yetkiId <= 0) {
                    $message = 'Geçersiz yetki ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "UPDATE tanim_sayfa_yetkiler SET gor = ?, kendi_kullanicini_gor = ?, ekle = ?, duzenle = ?, sil = ?, durum = ?, updated_at = GETDATE() 
                        WHERE sayfa_yetki_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$gor, $kendiKullaniciniGor, $ekle, $duzenle, $sil, $durum, $yetkiId]);
                
                $message = 'Yetki başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete_yetki':
                $yetkiId = (int)$_POST['yetki_id'];
                
                if ($yetkiId <= 0) {
                    $message = 'Geçersiz yetki ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "DELETE FROM tanim_sayfa_yetkiler WHERE sayfa_yetki_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$yetkiId]);
                
                $message = 'Yetki başarıyla silindi.';
                $messageType = 'success';
                break;
                
            case 'add_modul_menu_yetki':
                $hedefTip = $_POST['hedef_tip'] ?? ''; // 'modul' veya 'menu'
                $hedefId = (int)($_POST['hedef_id'] ?? 0);
                $userGroupId = (int)$_POST['user_group_id'];
                $gor = isset($_POST['gor']) ? 1 : 0;
                $durum = $_POST['durum'] ?? 1;
                
                if (!in_array($hedefTip, ['modul', 'menu'])) {
                    $message = 'Geçersiz hedef tipi.';
                    $messageType = 'danger';
                    break;
                }
                
                if ($hedefId <= 0) {
                    $message = 'Lütfen bir ' . ($hedefTip == 'modul' ? 'modül' : 'menü') . ' seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                if ($userGroupId <= 0) {
                    $message = 'Lütfen bir kullanıcı grubu seçiniz.';
                    $messageType = 'danger';
                    break;
                }
                
                // Yetki kontrolü - aynı hedef ve grup için tekrar yetki tanımlanmasın
                if ($hedefTip == 'modul') {
                    $checkSql = "SELECT modul_menu_yetki_id FROM tanim_modul_menu_yetkiler 
                                 WHERE modul_id = ? AND menu_id IS NULL AND user_group_id = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$hedefId, $userGroupId]);
                } else {
                    $checkSql = "SELECT modul_menu_yetki_id FROM tanim_modul_menu_yetkiler 
                                 WHERE menu_id = ? AND modul_id IS NULL AND user_group_id = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->execute([$hedefId, $userGroupId]);
                }
                
                if ($checkStmt->fetch()) {
                    $message = 'Bu ' . ($hedefTip == 'modul' ? 'modül' : 'menü') . ' için bu kullanıcı grubuna zaten yetki tanımlanmıştır.';
                    $messageType = 'danger';
                    break;
                }
                
                // Yetki ekle
                if ($hedefTip == 'modul') {
                    $sql = "INSERT INTO tanim_modul_menu_yetkiler (modul_id, menu_id, user_group_id, gor, durum, created_at) 
                            VALUES (?, NULL, ?, ?, ?, GETDATE())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$hedefId, $userGroupId, $gor, $durum]);
                } else {
                    $sql = "INSERT INTO tanim_modul_menu_yetkiler (modul_id, menu_id, user_group_id, gor, durum, created_at) 
                            VALUES (NULL, ?, ?, ?, ?, GETDATE())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$hedefId, $userGroupId, $gor, $durum]);
                }
                
                $message = ucfirst($hedefTip) . ' yetkisi başarıyla tanımlandı.';
                $messageType = 'success';
                break;
                
            case 'edit_modul_menu_yetki':
                $yetkiId = (int)$_POST['mm_yetki_id'];
                $gor = isset($_POST['gor']) ? 1 : 0;
                $durum = $_POST['durum'] ?? 1;
                
                if ($yetkiId <= 0) {
                    $message = 'Geçersiz yetki ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "UPDATE tanim_modul_menu_yetkiler SET gor = ?, durum = ?, updated_at = GETDATE() 
                        WHERE modul_menu_yetki_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$gor, $durum, $yetkiId]);
                
                $message = 'Yetki başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete_modul_menu_yetki':
                $yetkiId = (int)$_POST['mm_yetki_id'];
                
                if ($yetkiId <= 0) {
                    $message = 'Geçersiz yetki ID.';
                    $messageType = 'danger';
                    break;
                }
                
                $sql = "DELETE FROM tanim_modul_menu_yetkiler WHERE modul_menu_yetki_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$yetkiId]);
                
                $message = 'Yetki başarıyla silindi.';
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

// Verileri listele
$moduller = [];
$menuler = [];
$sayfalar = [];
$yetkiler = [];
$userGroups = [];
$error = '';

try {
    $conn = getDatabaseConnection();
    
    // Modülleri getir
    $sql = "SELECT * FROM tanim_moduller ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $moduller = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Menüleri getir
    $sql = "SELECT m.*, mod.modul_adi 
            FROM tanim_menuler m 
            LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
            ORDER BY m.sira_no, m.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $menuler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sayfaları getir
    $sql = "SELECT s.*, m.menu_adi, mod.modul_adi 
            FROM tanim_sayfalar s 
            LEFT JOIN tanim_menuler m ON s.menu_id = m.menu_id 
            LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
            ORDER BY s.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $sayfalar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Yetkileri getir
    $sql = "SELECT y.*, s.sayfa_adi, m.menu_adi, mod.modul_adi, ug.group_name as grup_adi
            FROM tanim_sayfa_yetkiler y 
            LEFT JOIN tanim_sayfalar s ON y.sayfa_id = s.sayfa_id 
            LEFT JOIN tanim_menuler m ON s.menu_id = m.menu_id 
            LEFT JOIN tanim_moduller mod ON m.modul_id = mod.modul_id 
            LEFT JOIN user_groups ug ON y.user_group_id = ug.id
            ORDER BY y.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $yetkiler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Kullanıcı gruplarını getir
    $sql = "SELECT id, group_name FROM user_groups ORDER BY group_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $userGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Modül/Menü yetkilerini getir
    $sql = "SELECT 
                mmy.modul_menu_yetki_id,
                mmy.modul_id,
                mmy.menu_id,
                mmy.user_group_id,
                mmy.gor,
                mmy.durum,
                mmy.created_at,
                CASE 
                    WHEN mmy.modul_id IS NOT NULL THEN mod.modul_adi
                    WHEN mmy.menu_id IS NOT NULL THEN m.menu_adi
                END as hedef_adi,
                CASE 
                    WHEN mmy.modul_id IS NOT NULL THEN 'Modül'
                    WHEN mmy.menu_id IS NOT NULL THEN 'Menü'
                END as hedef_tip,
                ug.group_name as grup_adi,
                mod.modul_adi as modul_adi,
                m.menu_adi as menu_adi
            FROM tanim_modul_menu_yetkiler mmy
            LEFT JOIN tanim_moduller mod ON mmy.modul_id = mod.modul_id
            LEFT JOIN tanim_menuler m ON mmy.menu_id = m.menu_id
            LEFT JOIN user_groups ug ON mmy.user_group_id = ug.id
            ORDER BY mmy.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $modulMenuYetkiler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// View klasörlerini tara ve dropdown için hazırla
$viewFolders = []; // Modül için üst seviye klasörler
$viewSubFolders = []; // Menü için alt klasörler (modül bazlı)
$viewFiles = []; // Sayfa için PHP dosyaları (modül/menü bazlı)
try {
    // __DIR__ => .../views/Yonetim/Tanimlar, views dizinine çık
    $viewsBase = dirname(__DIR__, 2);
    if (is_dir($viewsBase)) {
        $iter = new DirectoryIterator($viewsBase);
        foreach ($iter as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) continue;
            $folderName = $fileinfo->getFilename();
            // Üst seviye klasörü ekle
            $viewFolders[] = $folderName;
            
            // Alt klasörleri tara (Menü için)
            $subdir = $viewsBase . DIRECTORY_SEPARATOR . $folderName;
            $subFolderList = [];
            if (is_dir($subdir)) {
                $subIter = new DirectoryIterator($subdir);
                foreach ($subIter as $subInfo) {
                    if ($subInfo->isDot() || !$subInfo->isDir()) continue;
                    $subFolderName = $subInfo->getFilename();
                    $subFolderList[] = $subFolderName;
                    
                    // PHP dosyalarını tara (Sayfa için)
                    $subSubDir = $subdir . DIRECTORY_SEPARATOR . $subFolderName;
                    $phpFilesList = [];
                    if (is_dir($subSubDir)) {
                        $fileIter = new DirectoryIterator($subSubDir);
                        foreach ($fileIter as $file) {
                            if ($file->isDot() || !$file->isFile()) continue;
                            if (pathinfo($file->getFilename(), PATHINFO_EXTENSION) === 'php') {
                                $phpFilesList[] = $file->getFilename();
                            }
                        }
                        sort($phpFilesList);
                    }
                    $viewFiles[$folderName][$subFolderName] = $phpFilesList;
                }
                sort($subFolderList);
            }
            $viewSubFolders[$folderName] = $subFolderList;
        }
        sort($viewFolders);
    }
} catch (Exception $e) {
    // ignore scanning errors, not critical
}

// Kullanılmış modül URL'lerini topla (filtreleme için)
$usedModulUrls = array_column($moduller, 'modul_url');
$allViewFolders = $viewFolders; // Tüm klasörleri sakla (düzenleme için)
$viewFolders = array_diff($viewFolders, $usedModulUrls); // Yeni ekleme için filtrele

// Kullanılmış menü URL'lerini modül bazında topla (filtreleme için)
$usedMenuUrls = [];
foreach ($menuler as $menu) {
    if (!empty($menu['modul_id']) && !empty($menu['menu_url'])) {
        if (!isset($usedMenuUrls[$menu['modul_id']])) {
            $usedMenuUrls[$menu['modul_id']] = [];
        }
        $usedMenuUrls[$menu['modul_id']][] = $menu['menu_url'];
    }
}

// Kullanılmış sayfa URL'lerini menü bazında topla (filtreleme için)
$usedSayfaUrls = [];
foreach ($sayfalar as $sayfa) {
    if (!empty($sayfa['menu_id']) && !empty($sayfa['sayfa_url'])) {
        if (!isset($usedSayfaUrls[$sayfa['menu_id']])) {
            $usedSayfaUrls[$sayfa['menu_id']] = [];
        }
        $usedSayfaUrls[$sayfa['menu_id']][] = $sayfa['sayfa_url'];
    }
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
                <h2><i class="fas fa-shield-alt me-2"></i>Sayfa Tanım ve Yetkilendirme</h2>
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

    <!-- Tab Menü -->
    <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="modul-tab" data-bs-toggle="tab" data-bs-target="#modul" type="button">
                <i class="fas fa-cube me-1"></i>Modül Tanımlama
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="menu-tab" data-bs-toggle="tab" data-bs-target="#menu" type="button">
                <i class="fas fa-bars me-1"></i>Menü Tanımlama
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="sayfa-tab" data-bs-toggle="tab" data-bs-target="#sayfa" type="button">
                <i class="fas fa-file me-1"></i>Sayfa Tanımlama
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="modul-menu-yetki-tab" data-bs-toggle="tab" data-bs-target="#modul-menu-yetki" type="button">
                <i class="fas fa-lock me-1"></i>Modül/Menü Yetkileri
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="yetki-tab" data-bs-toggle="tab" data-bs-target="#yetki" type="button">
                <i class="fas fa-key me-1"></i>Sayfa Yetkileri
            </button>
        </li>
    </ul>

    <!-- Tab İçerikler -->
    <div class="tab-content" id="mainTabsContent">
        
        <!-- Modül Tanımlama -->
        <div class="tab-pane fade show active" id="modul" role="tabpanel">
            <div class="row">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Yeni Modül Ekle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_modul">
                                <div class="mb-3">
                                    <label for="modul_adi" class="form-label">Modül Adı</label>
                                    <input type="text" class="form-control" id="modul_adi" name="modul_adi" required 
                                           placeholder="Örn: Yönetim">
                                </div>
                                <div class="mb-3">
                                    <label for="modul_url" class="form-label">Modül URL (Klasör Adı)</label>
                                    <input type="text" class="form-control" id="modul_url" name="modul_url" 
                                           placeholder="Örn: Yonetim (klasör otomatik oluşturulacak)">
                                    <small class="form-text text-muted">Klasör adı giriniz. Otomatik olarak views klasöründe oluşturulacak.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="modul_aciklama" class="form-label">Açıklama</label>
                                    <textarea class="form-control" id="modul_aciklama" name="aciklama" rows="2" 
                                              placeholder="Modül açıklaması..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="modul_ikon" class="form-label">İkon</label>
                                    <select class="form-select icon-select" id="modul_ikon" name="modul_ikon">
                                        <option value="">İkon seçiniz...</option>
                                        <option value="fa-cube">&#xf1b2; fa-cube (Küp)</option>
                                        <option value="fa-home">&#xf015; fa-home (Ev)</option>
                                        <option value="fa-dashboard">&#xf0e4; fa-dashboard (Panel)</option>
                                        <option value="fa-cog">&#xf013; fa-cog (Ayar)</option>
                                        <option value="fa-users">&#xf0c0; fa-users (Kullanıcılar)</option>
                                        <option value="fa-user">&#xf007; fa-user (Kullanıcı)</option>
                                        <option value="fa-building">&#xf1ad; fa-building (Bina)</option>
                                        <option value="fa-chart-bar">&#xf080; fa-chart-bar (Grafik)</option>
                                        <option value="fa-briefcase">&#xf0b1; fa-briefcase (Çanta)</option>
                                        <option value="fa-money">&#xf0d6; fa-money (Para)</option>
                                        <option value="fa-file-alt">&#xf15c; fa-file-alt (Dosya)</option>
                                        <option value="fa-database">&#xf1c0; fa-database (Veritabanı)</option>
                                        <option value="fa-box">&#xf466; fa-box (Kutu)</option>
                                        <option value="fa-tasks">&#xf0ae; fa-tasks (Görevler)</option>
                                        <option value="fa-shield-alt">&#xf3ed; fa-shield-alt (Kalkan)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="modul_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="modul_sira_no" name="sira_no" value="0">
                                </div>
                                <div class="mb-3">
                                    <label for="modul_durum" class="form-label">Durum</label>
                                    <select class="form-select" id="modul_durum" name="durum">
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-save me-1"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Modül Listesi</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Modül Adı</th>
                                            <th>URL</th>
                                            <th>İkon</th>
                                            <th>Sıra</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($moduller)): ?>
                                            <?php foreach ($moduller as $modul): ?>
                                                <tr>
                                                    <td><?php echo $modul['modul_id']; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($modul['modul_adi']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($modul['modul_url'] ?? '-'); ?></td>
                                                    <td><i class="fas <?php echo htmlspecialchars($modul['modul_ikon'] ?? ''); ?>"></i></td>
                                                    <td><?php echo $modul['sira_no'] ?? 0; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $modul['durum'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $modul['durum'] == 1 ? 'Aktif' : 'Pasif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                onclick="editModul(<?php echo htmlspecialchars(json_encode($modul)); ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">Henüz modül tanımlanmamış</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menü Tanımlama -->
        <div class="tab-pane fade" id="menu" role="tabpanel">
            <div class="row">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Yeni Menü Ekle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_menu">
                                <div class="mb-3">
                                    <label for="menu_modul_id" class="form-label">Modül Seç</label>
                                    <select class="form-select" id="menu_modul_id" name="modul_id" required>
                                        <option value="">Modül seçiniz...</option>
                                        <?php foreach ($moduller as $modul): ?>
                                            <?php if ($modul['durum'] == 1): ?>
                                                <option value="<?php echo $modul['modul_id']; ?>">
                                                    <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="menu_adi" class="form-label">Menü Adı</label>
                                    <input type="text" class="form-control" id="menu_adi" name="menu_adi" required 
                                           placeholder="Örn: Tanımlar">
                                </div>
                                <div class="mb-3">
                                    <label for="menu_url" class="form-label">Menü URL (Alt Klasör Adı)</label>
                                    <input type="text" class="form-control" id="menu_url" name="menu_url" 
                                           placeholder="Örn: Tanimlar (alt klasör otomatik oluşturulacak)">
                                    <small class="form-text text-muted">Alt klasör adı giriniz. Modül klasörü altında oluşturulacak.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="menu_ikon" class="form-label">İkon</label>
                                    <select class="form-select icon-select" id="menu_ikon" name="ikon">
                                        <option value="">İkon seçiniz...</option>
                                        <option value="fa-list">&#xf03a; fa-list (Liste)</option>
                                        <option value="fa-bars">&#xf0c9; fa-bars (Menü)</option>
                                        <option value="fa-th">&#xf00a; fa-th (Izgara)</option>
                                        <option value="fa-folder">&#xf07b; fa-folder (Klasör)</option>
                                        <option value="fa-cog">&#xf013; fa-cog (Ayar)</option>
                                        <option value="fa-wrench">&#xf0ad; fa-wrench (Anahtar)</option>
                                        <option value="fa-chart-line">&#xf201; fa-chart-line (Grafik)</option>
                                        <option value="fa-table">&#xf0ce; fa-table (Tablo)</option>
                                        <option value="fa-file-invoice">&#xf570; fa-file-invoice (Fatura)</option>
                                        <option value="fa-calculator">&#xf1ec; fa-calculator (Hesap Mak.)</option>
                                        <option value="fa-dollar-sign">&#xf155; fa-dollar-sign (Dolar)</option>
                                        <option value="fa-percentage">&#xf295; fa-percentage (Yüzde)</option>
                                        <option value="fa-receipt">&#xf543; fa-receipt (Fiş)</option>
                                        <option value="fa-credit-card">&#xf09d; fa-credit-card (Kart)</option>
                                        <option value="fa-shopping-cart">&#xf07a; fa-shopping-cart (Sepet)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="menu_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="menu_sira_no" name="sira_no" value="0">
                                </div>
                                <div class="mb-3">
                                    <label for="menu_durum" class="form-label">Durum</label>
                                    <select class="form-select" id="menu_durum" name="durum">
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-save me-1"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Menü Listesi</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Modül</th>
                                            <th>Menü Adı</th>
                                            <th>URL</th>
                                            <th>İkon</th>
                                            <th>Sıra</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($menuler)): ?>
                                            <?php foreach ($menuler as $menu): ?>
                                                <tr>
                                                    <td><?php echo $menu['menu_id']; ?></td>
                                                    <td><?php echo htmlspecialchars($menu['modul_adi'] ?? '-'); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($menu['menu_adi']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($menu['menu_url'] ?? '-'); ?></td>
                                                    <td><i class="fas <?php echo htmlspecialchars($menu['ikon'] ?? ''); ?>"></i></td>
                                                    <td><?php echo $menu['sira_no']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $menu['durum'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $menu['durum'] == 1 ? 'Aktif' : 'Pasif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                onclick="editMenu(<?php echo htmlspecialchars(json_encode($menu)); ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">Henüz menü tanımlanmamış</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sayfa Tanımlama -->
        <div class="tab-pane fade" id="sayfa" role="tabpanel">
            <div class="row">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Yeni Sayfa Ekle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_sayfa">
                                <div class="mb-3">
                                    <label for="sayfa_modul_id" class="form-label">Modül Seç</label>
                                    <select class="form-select" id="sayfa_modul_id" onchange="updateMenuDropdown()">
                                        <option value="">Modül seçiniz...</option>
                                        <?php foreach ($moduller as $modul): ?>
                                            <?php if ($modul['durum'] == 1): ?>
                                                <option value="<?php echo $modul['modul_id']; ?>">
                                                    <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_menu_id" class="form-label">Menü Seç</label>
                                    <select class="form-select" id="sayfa_menu_id" name="menu_id" required>
                                        <option value="">Önce modül seçiniz...</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_adi" class="form-label">Sayfa Adı</label>
                                    <input type="text" class="form-control" id="sayfa_adi" name="sayfa_adi" required 
                                           placeholder="Örn: Bayi Hakedis Prim Dönem">
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_url" class="form-label">Sayfa URL (PHP Dosya Adı)</label>
                                    <input type="text" class="form-control" id="sayfa_url" name="sayfa_url" 
                                           placeholder="Örn: bayi_listesi.php (dosya otomatik oluşturulacak)">
                                    <small class="form-text text-muted">PHP dosya adı giriniz (.php uzantısıyla). Otomatik olarak template ile oluşturulacak.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_aciklama" class="form-label">Açıklama</label>
                                    <textarea class="form-control" id="sayfa_aciklama" name="aciklama" rows="2" 
                                              placeholder="Sayfa açıklaması..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_ikon" class="form-label">İkon</label>
                                    <select class="form-select icon-select" id="sayfa_ikon" name="sayfa_ikon">
                                        <option value="">İkon seçiniz...</option>
                                        <option value="fa-file">&#xf15b; fa-file (Dosya)</option>
                                        <option value="fa-file-alt">&#xf15c; fa-file-alt (Döküman)</option>
                                        <option value="fa-file-text">&#xf15c; fa-file-text (Metin)</option>
                                        <option value="fa-file-invoice">&#xf570; fa-file-invoice (Fatura)</option>
                                        <option value="fa-clipboard">&#xf328; fa-clipboard (Pano)</option>
                                        <option value="fa-edit">&#xf044; fa-edit (Düzenle)</option>
                                        <option value="fa-plus-square">&#xf0fe; fa-plus-square (Ekle)</option>
                                        <option value="fa-list-alt">&#xf022; fa-list-alt (Liste)</option>
                                        <option value="fa-check-square">&#xf14a; fa-check-square (İşaretle)</option>
                                        <option value="fa-calendar">&#xf133; fa-calendar (Takvim)</option>
                                        <option value="fa-chart-pie">&#xf200; fa-chart-pie (Pasta Gr.)</option>
                                        <option value="fa-tasks">&#xf0ae; fa-tasks (Görevler)</option>
                                        <option value="fa-user-check">&#xf4fc; fa-user-check (Onay)</option>
                                        <option value="fa-handshake">&#xf2b5; fa-handshake (Anlaşma)</option>
                                        <option value="fa-money-check">&#xf53c; fa-money-check (Çek)</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="sayfa_sira_no" name="sira_no" value="0">
                                </div>
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="dashboard" id="sayfa_dashboard" value="1">
                                        <label class="form-check-label" for="sayfa_dashboard">
                                            Dashboard'da Göster
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="sayfa_durum" class="form-label">Durum</label>
                                    <select class="form-select" id="sayfa_durum" name="durum">
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-info w-100 text-white">
                                    <i class="fas fa-save me-1"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Sayfa Listesi</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Modül</th>
                                            <th>Menü</th>
                                            <th>Sayfa Adı</th>
                                            <th>URL</th>
                                            <th>İkon</th>
                                            <th>Sıra</th>
                                            <th>Dashboard</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($sayfalar)): ?>
                                            <?php foreach ($sayfalar as $sayfa): ?>
                                                <tr>
                                                    <td><?php echo $sayfa['sayfa_id']; ?></td>
                                                    <td><?php echo htmlspecialchars($sayfa['modul_adi'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($sayfa['menu_adi'] ?? '-'); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($sayfa['sayfa_adi']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($sayfa['sayfa_url'] ?? '-'); ?></td>
                                                    <td><i class="fas <?php echo htmlspecialchars($sayfa['sayfa_ikon'] ?? ''); ?>"></i></td>
                                                    <td><?php echo $sayfa['sira_no'] ?? 0; ?></td>
                                                    <td>
                                                        <?php if (!empty($sayfa['dashboard'])): ?>
                                                            <i class="fas fa-check text-success"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-times text-muted"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $sayfa['durum'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $sayfa['durum'] == 1 ? 'Aktif' : 'Pasif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                onclick="editSayfa(<?php echo htmlspecialchars(json_encode($sayfa)); ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4 text-muted">Henüz sayfa tanımlanmamış</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modül/Menü Yetki Tanımlama -->
        <div class="tab-pane fade" id="modul-menu-yetki" role="tabpanel">
            <div class="row">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Yeni Modül/Menü Yetkisi Ekle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="modulMenuYetkiForm" onsubmit="return prepareModulMenuYetkiForm()">
                                <input type="hidden" name="action" value="add_modul_menu_yetki">
                                
                                <div class="mb-3">
                                    <label class="form-label">Hedef Tipi</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="hedef_tip" id="hedef_modul" value="modul" checked onchange="updateHedefDropdown()">
                                        <label class="btn btn-outline-primary" for="hedef_modul">
                                            <i class="fas fa-cube me-1"></i>Modül
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="hedef_tip" id="hedef_menu" value="menu" onchange="updateHedefDropdown()">
                                        <label class="btn btn-outline-primary" for="hedef_menu">
                                            <i class="fas fa-bars me-1"></i>Menü
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="mb-3" id="modul_select_container">
                                    <label for="mm_modul_id" class="form-label">Modül Seç</label>
                                    <select class="form-select" id="mm_modul_id" required>
                                        <option value="">Modül seçiniz...</option>
                                        <?php foreach ($moduller as $modul): ?>
                                            <?php if ($modul['durum'] == 1): ?>
                                                <option value="<?php echo $modul['modul_id']; ?>">
                                                    <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3" id="menu_select_container" style="display:none;">
                                    <label for="mm_menu_modul_id" class="form-label">Modül Seç</label>
                                    <select class="form-select" id="mm_menu_modul_id" onchange="updateMMMenuDropdown()">
                                        <option value="">Modül seçiniz...</option>
                                        <?php foreach ($moduller as $modul): ?>
                                            <?php if ($modul['durum'] == 1): ?>
                                                <option value="<?php echo $modul['modul_id']; ?>">
                                                    <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <label for="mm_menu_id" class="form-label mt-2">Menü Seç</label>
                                    <select class="form-select" id="mm_menu_id">
                                        <option value="">Önce modül seçiniz...</option>
                                    </select>
                                </div>
                                
                                <!-- Hidden input for hedef_id -->
                                <input type="hidden" name="hedef_id" id="hedef_id_input">
                                
                                <div class="mb-3">
                                    <label for="mm_user_group_id" class="form-label">Kullanıcı Grubu</label>
                                    <select class="form-select" id="mm_user_group_id" name="user_group_id" required>
                                        <option value="">Grup seçiniz...</option>
                                        <?php foreach ($userGroups as $group): ?>
                                            <option value="<?php echo $group['id']; ?>">
                                                <?php echo htmlspecialchars($group['group_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label d-block">Yetki</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="gor" id="mm_gor" value="1" checked>
                                        <label class="form-check-label" for="mm_gor">
                                            <i class="fas fa-eye me-1"></i>Görüntüleme (Bu modül/menüyü görebilir)
                                        </label>
                                    </div>
                                    <small class="text-muted">Modül/Menü için sadece görüntüleme yetkisi tanımlanır.</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="mm_durum" class="form-label">Durum</label>
                                    <select class="form-select" id="mm_durum" name="durum">
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="fas fa-save me-1"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Modül/Menü Yetki Listesi</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tip</th>
                                            <th>Hedef</th>
                                            <th>Kullanıcı Grubu</th>
                                            <th>Görüntüleme</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($modulMenuYetkiler)): ?>
                                            <?php foreach ($modulMenuYetkiler as $yetki): ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge bg-<?php echo $yetki['hedef_tip'] == 'Modül' ? 'primary' : 'success'; ?>">
                                                            <?php echo htmlspecialchars($yetki['hedef_tip']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($yetki['hedef_tip'] == 'Modül'): ?>
                                                            <strong><i class="fas fa-cube me-1"></i><?php echo htmlspecialchars($yetki['modul_adi'] ?? '-'); ?></strong>
                                                        <?php else: ?>
                                                            <small class="text-muted"><?php echo htmlspecialchars($yetki['modul_adi'] ?? '-'); ?></small><br>
                                                            <strong><i class="fas fa-bars me-1"></i><?php echo htmlspecialchars($yetki['menu_adi'] ?? '-'); ?></strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($yetki['grup_adi'] ?? '-'); ?></td>
                                                    <td>
                                                        <?php if ($yetki['gor']): ?>
                                                            <i class="fas fa-check-circle text-success"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-times-circle text-danger"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $yetki['durum'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $yetki['durum'] == 1 ? 'Aktif' : 'Pasif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editModulMenuYetki(<?php echo htmlspecialchars(json_encode($yetki)); ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="deleteModulMenuYetki(<?php echo $yetki['modul_menu_yetki_id']; ?>)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">Henüz modül/menü yetkisi tanımlanmamış</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Yetki Tanımlama -->
        <div class="tab-pane fade" id="yetki" role="tabpanel">
            <div class="row">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Yeni Yetki Ekle</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_yetki">
                                <div class="mb-3">
                                    <label for="yetki_modul_id" class="form-label">Modül Seç</label>
                                    <select class="form-select" id="yetki_modul_id" onchange="updateYetkiMenuDropdown()">
                                        <option value="">Modül seçiniz...</option>
                                        <?php foreach ($moduller as $modul): ?>
                                            <?php if ($modul['durum'] == 1): ?>
                                                <option value="<?php echo $modul['modul_id']; ?>">
                                                    <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="yetki_menu_id" class="form-label">Menü Seç</label>
                                    <select class="form-select" id="yetki_menu_id" onchange="updateYetkiSayfaDropdown()">
                                        <option value="">Önce modül seçiniz...</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="yetki_sayfa_id" class="form-label">Sayfa Seç</label>
                                    <select class="form-select" id="yetki_sayfa_id" name="sayfa_id" required>
                                        <option value="">Önce menü seçiniz...</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="user_group_id" class="form-label">Kullanıcı Grubu</label>
                                    <select class="form-select" id="user_group_id" name="user_group_id" required>
                                        <option value="">Grup seçiniz...</option>
                                        <?php foreach ($userGroups as $group): ?>
                                            <option value="<?php echo $group['id']; ?>">
                                                <?php echo htmlspecialchars($group['group_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-block">Yetkiler</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="gor" id="gor" value="1">
                                        <label class="form-check-label" for="gor">Görüntüleme</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="kendi_kullanicini_gor" id="kendi_kullanicini_gor" value="1">
                                        <label class="form-check-label" for="kendi_kullanicini_gor">Kendi Kullanıcısını Gör</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="ekle" id="ekle" value="1">
                                        <label class="form-check-label" for="ekle">Ekleme</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="duzenle" id="duzenle" value="1">
                                        <label class="form-check-label" for="duzenle">Düzenleme</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="sil" id="sil" value="1">
                                        <label class="form-check-label" for="sil">Silme</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="yetki_durum" class="form-label">Durum</label>
                                    <select class="form-select" id="yetki_durum" name="durum">
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="fas fa-save me-1"></i>Kaydet
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Yetki Listesi</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Modül/Menü/Sayfa</th>
                                            <th>Kullanıcı Grubu</th>
                                            <th>Yetkiler</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($yetkiler)): ?>
                                            <?php foreach ($yetkiler as $yetki): ?>
                                                <tr>
                                                    <td>
                                                        <small class="text-muted"><?php echo htmlspecialchars($yetki['modul_adi'] ?? '-'); ?></small><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($yetki['menu_adi'] ?? '-'); ?></small><br>
                                                        <strong><?php echo htmlspecialchars($yetki['sayfa_adi'] ?? '-'); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($yetki['grup_adi'] ?? '-'); ?></td>
                                                    <td>
                                                        <?php if ($yetki['gor']): ?>
                                                            <span class="badge bg-primary me-1">Gör</span>
                                                        <?php endif; ?>
                                                        <?php if ($yetki['kendi_kullanicini_gor']): ?>
                                                            <span class="badge bg-secondary me-1">Kendi</span>
                                                        <?php endif; ?>
                                                        <?php if ($yetki['ekle']): ?>
                                                            <span class="badge bg-success me-1">Ekle</span>
                                                        <?php endif; ?>
                                                        <?php if ($yetki['duzenle']): ?>
                                                            <span class="badge bg-info me-1">Düzenle</span>
                                                        <?php endif; ?>
                                                        <?php if ($yetki['sil']): ?>
                                                            <span class="badge bg-danger me-1">Sil</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $yetki['durum'] == 1 ? 'success' : 'secondary'; ?>">
                                                            <?php echo $yetki['durum'] == 1 ? 'Aktif' : 'Pasif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    onclick="editYetki(<?php echo htmlspecialchars(json_encode($yetki)); ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="deleteYetki(<?php echo $yetki['sayfa_yetki_id']; ?>)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">Henüz yetki tanımlanmamış</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modül Düzenle Modal -->
<div class="modal fade" id="editModulModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_modul">
                <input type="hidden" name="modul_id" id="edit_modul_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Modül Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_modul_adi" class="form-label">Modül Adı</label>
                        <input type="text" class="form-control" id="edit_modul_adi" name="modul_adi" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_modul_url" class="form-label">Modül URL (Klasör Adı)</label>
                        <input type="text" class="form-control" id="edit_modul_url" name="modul_url" 
                               placeholder="Örn: Yonetim">
                        <small class="form-text text-muted">Klasör adı giriniz.</small>
                    </div>
                    <div class="mb-3">
                        <label for="edit_modul_aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="edit_modul_aciklama" name="aciklama" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_modul_ikon" class="form-label">İkon</label>
                        <select class="form-select icon-select" id="edit_modul_ikon" name="modul_ikon">
                            <option value="">İkon seçiniz...</option>
                            <option value="fa-cube">&#xf1b2; fa-cube (Küp)</option>
                            <option value="fa-home">&#xf015; fa-home (Ev)</option>
                            <option value="fa-dashboard">&#xf0e4; fa-dashboard (Panel)</option>
                            <option value="fa-cog">&#xf013; fa-cog (Ayar)</option>
                            <option value="fa-users">&#xf0c0; fa-users (Kullanıcılar)</option>
                            <option value="fa-user">&#xf007; fa-user (Kullanıcı)</option>
                            <option value="fa-building">&#xf1ad; fa-building (Bina)</option>
                            <option value="fa-chart-bar">&#xf080; fa-chart-bar (Grafik)</option>
                            <option value="fa-briefcase">&#xf0b1; fa-briefcase (Çanta)</option>
                            <option value="fa-money">&#xf0d6; fa-money (Para)</option>
                            <option value="fa-file-alt">&#xf15c; fa-file-alt (Dosya)</option>
                            <option value="fa-database">&#xf1c0; fa-database (Veritabanı)</option>
                            <option value="fa-box">&#xf466; fa-box (Kutu)</option>
                            <option value="fa-tasks">&#xf0ae; fa-tasks (Görevler)</option>
                            <option value="fa-shield-alt">&#xf3ed; fa-shield-alt (Kalkan)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_modul_sira_no" class="form-label">Sıra No</label>
                        <input type="number" class="form-control" id="edit_modul_sira_no" name="sira_no" value="0">
                    </div>
                    <div class="mb-3">
                        <label for="edit_modul_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_modul_durum" name="durum">
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

<!-- Menü Düzenle Modal -->
<div class="modal fade" id="editMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_menu">
                <input type="hidden" name="menu_id" id="edit_menu_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Menü Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_menu_modul_id" class="form-label">Modül</label>
                        <select class="form-select" id="edit_menu_modul_id" name="modul_id" required>
                            <option value="">Modül seçiniz...</option>
                            <?php foreach ($moduller as $modul): ?>
                                <?php if ($modul['durum'] == 1): ?>
                                    <option value="<?php echo $modul['modul_id']; ?>">
                                        <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_menu_adi" class="form-label">Menü Adı</label>
                        <input type="text" class="form-control" id="edit_menu_adi" name="menu_adi" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_menu_url" class="form-label">Menü URL (Alt Klasör Adı)</label>
                        <input type="text" class="form-control" id="edit_menu_url" name="menu_url" 
                               placeholder="Örn: Tanimlar">
                        <small class="form-text text-muted">Alt klasör adı giriniz.</small>
                    </div>
                    <div class="mb-3">
                        <label for="edit_menu_ikon" class="form-label">İkon</label>
                        <select class="form-select icon-select" id="edit_menu_ikon" name="ikon">
                            <option value="">İkon seçiniz...</option>
                            <option value="fa-list">&#xf03a; fa-list (Liste)</option>
                            <option value="fa-bars">&#xf0c9; fa-bars (Menü)</option>
                            <option value="fa-th">&#xf00a; fa-th (Izgara)</option>
                            <option value="fa-folder">&#xf07b; fa-folder (Klasör)</option>
                            <option value="fa-cog">&#xf013; fa-cog (Ayar)</option>
                            <option value="fa-wrench">&#xf0ad; fa-wrench (Anahtar)</option>
                            <option value="fa-chart-line">&#xf201; fa-chart-line (Grafik)</option>
                            <option value="fa-table">&#xf0ce; fa-table (Tablo)</option>
                            <option value="fa-file-invoice">&#xf570; fa-file-invoice (Fatura)</option>
                            <option value="fa-calculator">&#xf1ec; fa-calculator (Hesap Mak.)</option>
                            <option value="fa-dollar-sign">&#xf155; fa-dollar-sign (Dolar)</option>
                            <option value="fa-percentage">&#xf295; fa-percentage (Yüzde)</option>
                            <option value="fa-receipt">&#xf543; fa-receipt (Fiş)</option>
                            <option value="fa-credit-card">&#xf09d; fa-credit-card (Kart)</option>
                            <option value="fa-shopping-cart">&#xf07a; fa-shopping-cart (Sepet)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_menu_sira_no" class="form-label">Sıra No</label>
                        <input type="number" class="form-control" id="edit_menu_sira_no" name="sira_no">
                    </div>
                    <div class="mb-3">
                        <label for="edit_menu_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_menu_durum" name="durum">
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

<!-- Sayfa Düzenle Modal -->
<div class="modal fade" id="editSayfaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_sayfa">
                <input type="hidden" name="sayfa_id" id="edit_sayfa_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Sayfa Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_sayfa_modul_id" class="form-label">Modül</label>
                        <select class="form-select" id="edit_sayfa_modul_id" onchange="updateEditSayfaMenuDropdown()">
                            <option value="">Modül seçiniz...</option>
                            <?php foreach ($moduller as $modul): ?>
                                <?php if ($modul['durum'] == 1): ?>
                                    <option value="<?php echo $modul['modul_id']; ?>">
                                        <?php echo htmlspecialchars($modul['modul_adi']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_menu_id" class="form-label">Menü</label>
                        <select class="form-select" id="edit_sayfa_menu_id" name="menu_id" required>
                            <option value="">Önce modül seçiniz...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_adi" class="form-label">Sayfa Adı</label>
                        <input type="text" class="form-control" id="edit_sayfa_adi" name="sayfa_adi" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_url" class="form-label">Sayfa URL (PHP Dosya Adı)</label>
                        <input type="text" class="form-control" id="edit_sayfa_url" name="sayfa_url" 
                               placeholder="Örn: bayi_listesi.php">
                        <small class="form-text text-muted">PHP dosya adı giriniz (.php uzantısıyla).</small>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="edit_sayfa_aciklama" name="aciklama" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_ikon" class="form-label">İkon</label>
                        <select class="form-select icon-select" id="edit_sayfa_ikon" name="sayfa_ikon">
                            <option value="">İkon seçiniz...</option>
                            <option value="fa-file">&#xf15b; fa-file (Dosya)</option>
                            <option value="fa-file-alt">&#xf15c; fa-file-alt (Döküman)</option>
                            <option value="fa-file-text">&#xf15c; fa-file-text (Metin)</option>
                            <option value="fa-file-invoice">&#xf570; fa-file-invoice (Fatura)</option>
                            <option value="fa-clipboard">&#xf328; fa-clipboard (Pano)</option>
                            <option value="fa-edit">&#xf044; fa-edit (Düzenle)</option>
                            <option value="fa-plus-square">&#xf0fe; fa-plus-square (Ekle)</option>
                            <option value="fa-list-alt">&#xf022; fa-list-alt (Liste)</option>
                            <option value="fa-check-square">&#xf14a; fa-check-square (İşaretle)</option>
                            <option value="fa-calendar">&#xf133; fa-calendar (Takvim)</option>
                            <option value="fa-chart-pie">&#xf200; fa-chart-pie (Pasta Gr.)</option>
                            <option value="fa-tasks">&#xf0ae; fa-tasks (Görevler)</option>
                            <option value="fa-user-check">&#xf4fc; fa-user-check (Onay)</option>
                            <option value="fa-handshake">&#xf2b5; fa-handshake (Anlaşma)</option>
                            <option value="fa-money-check">&#xf53c; fa-money-check (Çek)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_sira_no" class="form-label">Sıra No</label>
                        <input type="number" class="form-control" id="edit_sayfa_sira_no" name="sira_no" value="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="dashboard" id="edit_sayfa_dashboard" value="1">
                            <label class="form-check-label" for="edit_sayfa_dashboard">
                                Dashboard'da Göster
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_sayfa_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_sayfa_durum" name="durum">
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

<!-- Modül/Menü Yetki Düzenle Modal -->
<div class="modal fade" id="editModulMenuYetkiModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_modul_menu_yetki">
                <input type="hidden" name="mm_yetki_id" id="edit_mm_yetki_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Modül/Menü Yetkisi Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Hedef</label>
                        <input type="text" class="form-control" id="edit_mm_hedef_info" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı Grubu</label>
                        <input type="text" class="form-control" id="edit_mm_grup_info" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Yetki</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="gor" id="edit_mm_gor" value="1">
                            <label class="form-check-label" for="edit_mm_gor">
                                <i class="fas fa-eye me-1"></i>Görüntüleme
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_mm_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_mm_durum" name="durum">
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

<!-- Yetki Düzenle Modal -->
<div class="modal fade" id="editYetkiModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_yetki">
                <input type="hidden" name="yetki_id" id="edit_yetki_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Yetki Düzenle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Sayfa</label>
                        <input type="text" class="form-control" id="edit_sayfa_info" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı Grubu</label>
                        <input type="text" class="form-control" id="edit_grup_info" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Yetkiler</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="gor" id="edit_gor" value="1">
                            <label class="form-check-label" for="edit_gor">Görüntüleme</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="kendi_kullanicini_gor" id="edit_kendi_kullanicini_gor" value="1">
                            <label class="form-check-label" for="edit_kendi_kullanicini_gor">Kendi Kullanıcısını Gör</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="ekle" id="edit_ekle" value="1">
                            <label class="form-check-label" for="edit_ekle">Ekleme</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="duzenle" id="edit_duzenle" value="1">
                            <label class="form-check-label" for="edit_duzenle">Düzenleme</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="sil" id="edit_sil" value="1">
                            <label class="form-check-label" for="edit_sil">Silme</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_yetki_durum" class="form-label">Durum</label>
                        <select class="form-select" id="edit_yetki_durum" name="durum">
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

<style>
.icon-select {
    font-family: 'Font Awesome 5 Free', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-weight: 900;
}
.icon-select option {
    padding: 8px;
    font-size: 14px;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Menü verilerini JavaScript'e aktar
const menulerData = <?php echo json_encode($menuler); ?>;
const sayfalarData = <?php echo json_encode($sayfalar); ?>;
const modullerData = <?php echo json_encode($moduller); ?>;

// Sayfa tanımı için menü dropdown güncelleme
function updateMenuDropdown() {
    const modulId = document.getElementById('sayfa_modul_id').value;
    const menuDropdown = document.getElementById('sayfa_menu_id');
    
    menuDropdown.innerHTML = '<option value="">Menü seçiniz...</option>';
    
    if (modulId) {
        const filteredMenuler = menulerData.filter(m => m.modul_id == modulId && m.durum == 1);
        filteredMenuler.forEach(menu => {
            const option = document.createElement('option');
            option.value = menu.menu_id;
            option.textContent = menu.menu_adi;
            menuDropdown.appendChild(option);
        });
    }
}

// Yetki tanımı için menü dropdown güncelleme
function updateYetkiMenuDropdown() {
    const modulId = document.getElementById('yetki_modul_id').value;
    const menuDropdown = document.getElementById('yetki_menu_id');
    const sayfaDropdown = document.getElementById('yetki_sayfa_id');
    
    menuDropdown.innerHTML = '<option value="">Menü seçiniz...</option>';
    sayfaDropdown.innerHTML = '<option value="">Önce menü seçiniz...</option>';
    
    if (modulId) {
        const filteredMenuler = menulerData.filter(m => m.modul_id == modulId && m.durum == 1);
        filteredMenuler.forEach(menu => {
            const option = document.createElement('option');
            option.value = menu.menu_id;
            option.textContent = menu.menu_adi;
            menuDropdown.appendChild(option);
        });
    }
}

// Yetki tanımı için sayfa dropdown güncelleme
function updateYetkiSayfaDropdown() {
    const menuId = document.getElementById('yetki_menu_id').value;
    const sayfaDropdown = document.getElementById('yetki_sayfa_id');
    
    sayfaDropdown.innerHTML = '<option value="">Sayfa seçiniz...</option>';
    
    if (menuId) {
        const filteredSayfalar = sayfalarData.filter(s => s.menu_id == menuId && s.durum == 1);
        filteredSayfalar.forEach(sayfa => {
            const option = document.createElement('option');
            option.value = sayfa.sayfa_id;
            option.textContent = sayfa.sayfa_adi;
            sayfaDropdown.appendChild(option);
        });
    }
}

// Modül düzenleme
function editModul(modul) {
    document.getElementById('edit_modul_id').value = modul.modul_id;
    document.getElementById('edit_modul_adi').value = modul.modul_adi;
    document.getElementById('edit_modul_url').value = modul.modul_url || '';
    document.getElementById('edit_modul_aciklama').value = modul.aciklama || '';
    document.getElementById('edit_modul_ikon').value = modul.modul_ikon || '';
    document.getElementById('edit_modul_sira_no').value = modul.sira_no || 0;
    document.getElementById('edit_modul_durum').value = modul.durum;
    
    new bootstrap.Modal(document.getElementById('editModulModal')).show();
}

// Menü düzenleme
function editMenu(menu) {
    document.getElementById('edit_menu_id').value = menu.menu_id;
    document.getElementById('edit_menu_modul_id').value = menu.modul_id;
    document.getElementById('edit_menu_adi').value = menu.menu_adi;
    document.getElementById('edit_menu_url').value = menu.menu_url || '';
    document.getElementById('edit_menu_ikon').value = menu.ikon || '';
    document.getElementById('edit_menu_sira_no').value = menu.sira_no;
    document.getElementById('edit_menu_durum').value = menu.durum;
    
    new bootstrap.Modal(document.getElementById('editMenuModal')).show();
}

// Sayfa düzenleme
function editSayfa(sayfa) {
    document.getElementById('edit_sayfa_id').value = sayfa.sayfa_id;
    document.getElementById('edit_sayfa_adi').value = sayfa.sayfa_adi;
    document.getElementById('edit_sayfa_url').value = sayfa.sayfa_url || '';
    document.getElementById('edit_sayfa_aciklama').value = sayfa.aciklama || '';
    document.getElementById('edit_sayfa_ikon').value = sayfa.sayfa_ikon || '';
    document.getElementById('edit_sayfa_sira_no').value = sayfa.sira_no || 0;
    document.getElementById('edit_sayfa_dashboard').checked = sayfa.dashboard == 1;
    document.getElementById('edit_sayfa_durum').value = sayfa.durum;
    
    // Menü seçimi için modül bulup menü dropdown'unu doldur
    const menu = menulerData.find(m => m.menu_id == sayfa.menu_id);
    if (menu) {
        document.getElementById('edit_sayfa_modul_id').value = menu.modul_id;
        updateEditSayfaMenuDropdown();
        // Dropdown dolduktan sonra menüyü seç
        setTimeout(() => {
            document.getElementById('edit_sayfa_menu_id').value = sayfa.menu_id;
        }, 100);
    }
    
    new bootstrap.Modal(document.getElementById('editSayfaModal')).show();
}

// Sayfa düzenleme modalı için menü dropdown güncelleme
function updateEditSayfaMenuDropdown() {
    const modulId = document.getElementById('edit_sayfa_modul_id').value;
    const menuDropdown = document.getElementById('edit_sayfa_menu_id');
    
    menuDropdown.innerHTML = '<option value="">Menü seçiniz...</option>';
    
    if (modulId) {
        const filteredMenuler = menulerData.filter(m => m.modul_id == modulId && m.durum == 1);
        filteredMenuler.forEach(menu => {
            const option = document.createElement('option');
            option.value = menu.menu_id;
            option.textContent = menu.menu_adi;
            menuDropdown.appendChild(option);
        });
    }
}

// Yetki düzenleme
function editYetki(yetki) {
    document.getElementById('edit_yetki_id').value = yetki.sayfa_yetki_id;
    document.getElementById('edit_sayfa_info').value = yetki.modul_adi + ' / ' + yetki.menu_adi + ' / ' + yetki.sayfa_adi;
    document.getElementById('edit_grup_info').value = yetki.grup_adi;
    document.getElementById('edit_gor').checked = yetki.gor == 1;
    document.getElementById('edit_kendi_kullanicini_gor').checked = yetki.kendi_kullanicini_gor == 1;
    document.getElementById('edit_ekle').checked = yetki.ekle == 1;
    document.getElementById('edit_duzenle').checked = yetki.duzenle == 1;
    document.getElementById('edit_sil').checked = yetki.sil == 1;
    document.getElementById('edit_yetki_durum').value = yetki.durum;
    
    new bootstrap.Modal(document.getElementById('editYetkiModal')).show();
}

// Yetki silme
function deleteYetki(yetkiId) {
    if (confirm('Bu yetkiyi silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_yetki">
            <input type="hidden" name="yetki_id" value="${yetkiId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Modül/Menü Yetki - Hedef Tipi Değişimi
function updateHedefDropdown() {
    const hedefTip = document.querySelector('input[name="hedef_tip"]:checked').value;
    const modulContainer = document.getElementById('modul_select_container');
    const menuContainer = document.getElementById('menu_select_container');
    
    if (hedefTip === 'modul') {
        modulContainer.style.display = 'block';
        menuContainer.style.display = 'none';
        document.getElementById('mm_modul_id').required = true;
        document.getElementById('mm_menu_id').required = false;
    } else {
        modulContainer.style.display = 'none';
        menuContainer.style.display = 'block';
        document.getElementById('mm_modul_id').required = false;
        document.getElementById('mm_menu_id').required = true;
    }
}

// Form submit öncesi hedef_id'yi hazırla
function prepareModulMenuYetkiForm() {
    const hedefTip = document.querySelector('input[name="hedef_tip"]:checked').value;
    const hedefIdInput = document.getElementById('hedef_id_input');
    
    if (hedefTip === 'modul') {
        const modulId = document.getElementById('mm_modul_id').value;
        if (!modulId) {
            alert('Lütfen bir modül seçiniz.');
            return false;
        }
        hedefIdInput.value = modulId;
    } else {
        const menuId = document.getElementById('mm_menu_id').value;
        if (!menuId) {
            alert('Lütfen bir menü seçiniz.');
            return false;
        }
        hedefIdInput.value = menuId;
    }
    
    return true;
}

// Modül/Menü Yetki - Menü Dropdown Güncelleme
function updateMMMenuDropdown() {
    const modulId = document.getElementById('mm_menu_modul_id').value;
    const menuDropdown = document.getElementById('mm_menu_id');
    
    menuDropdown.innerHTML = '<option value="">Menü seçiniz...</option>';
    
    if (modulId) {
        const filteredMenuler = menulerData.filter(m => m.modul_id == modulId && m.durum == 1);
        filteredMenuler.forEach(menu => {
            const option = document.createElement('option');
            option.value = menu.menu_id;
            option.textContent = menu.menu_adi;
            menuDropdown.appendChild(option);
        });
    }
}

// Modül/Menü Yetki Düzenleme
function editModulMenuYetki(yetki) {
    document.getElementById('edit_mm_yetki_id').value = yetki.modul_menu_yetki_id;
    
    let hedefInfo = '';
    if (yetki.hedef_tip === 'Modül') {
        hedefInfo = 'Modül: ' + yetki.modul_adi;
    } else {
        hedefInfo = 'Menü: ' + yetki.menu_adi;
    }
    
    document.getElementById('edit_mm_hedef_info').value = hedefInfo;
    document.getElementById('edit_mm_grup_info').value = yetki.grup_adi;
    document.getElementById('edit_mm_gor').checked = yetki.gor == 1;
    document.getElementById('edit_mm_durum').value = yetki.durum;
    
    new bootstrap.Modal(document.getElementById('editModulMenuYetkiModal')).show();
}

// Modül/Menü Yetki Silme
function deleteModulMenuYetki(yetkiId) {
    if (confirm('Bu modül/menü yetkisini silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_modul_menu_yetki">
            <input type="hidden" name="mm_yetki_id" value="${yetkiId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
