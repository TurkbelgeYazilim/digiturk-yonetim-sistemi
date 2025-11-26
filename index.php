<?php
$pageTitle = "Ana Sayfa";
$breadcrumbs = [
    ['title' => 'Ana Sayfa']
];

// Auth kontrol
require_once 'auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Hoş Geldiniz -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <h1 class="display-4 text-primary mb-3">
                        <i class="fas fa-chart-line me-3"></i>Digiturk Rapor Sistemi
                    </h1>
                    <p class="lead text-muted">
                        Hoş geldiniz, <strong><?php echo htmlspecialchars($currentUser['name']); ?></strong>
                    </p>
                    <p class="text-muted">
                        Yetki seviyeniz: <span class="badge bg-primary"><?php 
                        echo htmlspecialchars($currentUser['group_name'] ?? 'Kullanıcı');
                        ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Hızlı Erişim -->
        <div class="col-md-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i>Hızlı Erişim
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php 
                        // Veritabanı bağlantısı
                        $dbConfig = require 'config/mssql.php';
                        $connectionInfo = array(
                            "Database" => $dbConfig['database'],
                            "UID" => $dbConfig['username'],
                            "PWD" => $dbConfig['password'],
                            "CharacterSet" => "UTF-8"
                        );
                        $conn = sqlsrv_connect($dbConfig['host'], $connectionInfo);
                        
                        if ($conn === false) {
                            die("Veritabanı bağlantı hatası: " . print_r(sqlsrv_errors(), true));
                        }
                        
                        // Dashboard'da gösterilecek sayfaları çek
                        $dashboardQuery = "
                            SELECT 
                                s.sayfa_id,
                                s.sayfa_adi,
                                s.sayfa_url,
                                s.sayfa_ikon,
                                s.sira_no,
                                m.menu_url,
                                mo.modul_url
                            FROM tanim_sayfalar s
                            INNER JOIN tanim_menuler m ON s.menu_id = m.menu_id
                            INNER JOIN tanim_moduller mo ON m.modul_id = mo.modul_id
                            WHERE s.dashboard = 1 AND s.durum = 1
                            ORDER BY s.sira_no ASC, s.sayfa_id ASC
                        ";
                        
                        $dashboardStmt = sqlsrv_query($conn, $dashboardQuery);
                        
                        if ($dashboardStmt === false) {
                            echo '<div class="col-12"><div class="alert alert-warning">Dashboard verileri yüklenemedi.</div></div>';
                        } else {
                            $renkler = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark'];
                            $renkIndex = 0;
                            
                            // Sayfa yoksa bilgi göster
                            $sayfaVarMi = false;
                            
                            while ($sayfa = sqlsrv_fetch_array($dashboardStmt, SQLSRV_FETCH_ASSOC)) {
                                $sayfaVarMi = true;
                                $sayfaAdi = htmlspecialchars($sayfa['sayfa_adi']);
                                $sayfaUrl = htmlspecialchars($sayfa['sayfa_url']);
                                $sayfaIkon = htmlspecialchars($sayfa['sayfa_ikon'] ?? 'fa-file');
                                $menuUrl = htmlspecialchars($sayfa['menu_url']);
                                $modulUrl = htmlspecialchars($sayfa['modul_url']);
                                
                                // URL oluştur
                                $fullUrl = "views/{$modulUrl}/{$menuUrl}/{$sayfaUrl}";
                                
                                // Renk seç
                                $renk = $renkler[$renkIndex % count($renkler)];
                                $renkIndex++;
                        ?>
                        <div class="col-md-4 col-6">
                            <a href="<?php echo $fullUrl; ?>" class="btn btn-outline-<?php echo $renk; ?> w-100 py-3">
                                <i class="fas <?php echo $sayfaIkon; ?> fa-2x d-block mb-2"></i>
                                <small><?php echo $sayfaAdi; ?></small>
                            </a>
                        </div>
                        <?php 
                            }
                            
                            if (!$sayfaVarMi) {
                                echo '<div class="col-12"><div class="alert alert-info mb-0">';
                                echo '<i class="fas fa-info-circle me-2"></i>';
                                echo 'Henüz dashboard\'a eklenmiş sayfa bulunmuyor. ';
                                echo 'Sayfa eklemek için <code>tanim_sayfalar</code> tablosunda <code>dashboard=1</code> yapın.';
                                echo '</div></div>';
                            }
                            
                            sqlsrv_free_stmt($dashboardStmt);
                        }
                        
                        sqlsrv_close($conn);
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sistem Bilgileri -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Sistem Bilgileri
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="fas fa-server me-2 text-primary"></i>
                            <strong>PHP:</strong> <?php echo PHP_VERSION; ?>
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-database me-2 text-success"></i>
                            <strong>Veritabanı:</strong> SQL Server
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-clock me-2 text-info"></i>
                            <strong>Tarih:</strong> <?php echo date('d.m.Y H:i:s'); ?>
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-user me-2 text-warning"></i>
                            <strong>Kullanıcı:</strong> <?php echo htmlspecialchars($currentUser['name']); ?>
                        </li>
                        <li class="mb-0">
                            <i class="fas fa-envelope me-2 text-secondary"></i>
                            <strong>E-posta:</strong> <?php echo htmlspecialchars($currentUser['email']); ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>