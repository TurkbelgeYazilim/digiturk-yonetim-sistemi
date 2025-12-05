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
        <div class="col-12 mb-4">
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
                        
                        // Dashboard'da gösterilecek sayfaları çek (yetkilere göre filtreli)
                        $userGroupId = $currentUser['group_id'];
                        
                        if ($userGroupId == 1) {
                            // Admin: Tüm sayfaları göster
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
                        } else {
                            // Diğer kullanıcılar: Sadece yetkili olduğu sayfaları göster
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
                                INNER JOIN tanim_sayfa_yetkiler sy ON s.sayfa_id = sy.sayfa_id
                                WHERE s.dashboard = 1 
                                AND s.durum = 1 
                                AND sy.user_group_id = ?
                                AND sy.gor = 1
                                AND sy.durum = 1
                                ORDER BY s.sira_no ASC, s.sayfa_id ASC
                            ";
                        }
                        
                        // Parametreli sorgu hazırla
                        if ($userGroupId == 1) {
                            $dashboardStmt = sqlsrv_query($conn, $dashboardQuery);
                        } else {
                            $params = array($userGroupId);
                            $dashboardStmt = sqlsrv_query($conn, $dashboardQuery, $params);
                        }
                        
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

        <!-- Son Geliştirmeler -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-rocket me-2"></i>Son Geliştirmeler
                    </h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item mb-3">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1 text-success">v1.1.0 - Web Servis API</h6>
                                <p class="small text-muted mb-1">28 Kasım 2025</p>
                                <p class="small mb-0">Swagger benzeri API dokümantasyonu ve iframe desteği eklendi.</p>
                            </div>
                        </div>
                        <div class="timeline-item mb-3">
                            <div class="timeline-marker bg-primary"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1 text-primary">VoIP Toplu Harcama</h6>
                                <p class="small text-muted mb-1">28 Kasım 2025</p>
                                <p class="small mb-0">Panodan kopyala-yapıştır ve Excel/CSV yükleme özelliği.</p>
                            </div>
                        </div>
                        <div class="timeline-item mb-3">
                            <div class="timeline-marker bg-warning"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1 text-warning">Sippy Otomatik Çekim</h6>
                                <p class="small text-muted mb-1">28 Kasım 2025</p>
                                <p class="small mb-0">Sippy sisteminden otomatik harcama verisi çekme sistemi.</p>
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-secondary"></div>
                            <div class="timeline-content">
                                <h6 class="mb-1 text-secondary">Dashboard Sadelik</h6>
                                <p class="small text-muted mb-1">28 Kasım 2025</p>
                                <p class="small mb-0">Sistem bilgileri kartı kaldırılarak daha temiz görünüm.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #007bff, #28a745, #ffc107, #6c757d);
}

.timeline-item {
    position: relative;
    padding-left: 25px;
}

.timeline-marker {
    position: absolute;
    left: 0;
    top: 4px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.timeline-content h6 {
    font-size: 0.9rem;
    font-weight: 600;
}

.timeline-content p.small {
    font-size: 0.8rem;
    line-height: 1.4;
}
</style>

<?php include 'includes/footer.php'; ?>