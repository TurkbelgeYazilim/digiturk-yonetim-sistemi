<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Iris Rapor Sistemi - CSV ve Excel dosya yükleme ve işleme platformu">
    <meta name="keywords" content="iris, rapor, csv, excel, veritabanı, digitürk">
    <meta name="author" content="İlekasoft">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>iLEKASoft Digiturk Rapor Sistemi</title>
    
    <?php
    // Dinamik base path hesaplama
    $currentFile = $_SERVER['PHP_SELF'];
    $depth = substr_count($currentFile, '/') - 1;
    $basePath = ($depth > 0) ? str_repeat('../', $depth) : '';
    ?>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-color: #1e293b;
            --light-color: #f8fafc;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, var(--primary-color) 0%, #1d4ed8 100%);
        }
        
        .main-content {
            min-height: calc(100vh - 200px);
            padding-top: 2rem;
        }
        
        .card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border-radius: 12px;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, #1d4ed8 100%);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: "›";
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        /* Dropdown menü düzeltmeleri */
        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-top: 0.5rem;
        }
        
        .dropdown-item {
            padding: 0.5rem 1rem;
            border-radius: 4px;
            margin: 0.1rem 0.5rem;
        }
        
        .dropdown-item:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .navbar-nav .dropdown-toggle::after {
            margin-left: 0.5em;
        }
        
        /* Alt menü (dropend) düzeltmeleri */
        .dropdown-submenu {
            position: relative;
        }
        
        .dropdown-submenu .dropdown-menu {
            position: absolute;
            top: 0;
            left: 100%;
            margin-top: -1px;
            margin-left: 0;
            display: none;
        }
        
        .dropdown-submenu:hover .dropdown-menu {
            display: block;
        }
        
        .dropdown-submenu .dropdown-toggle::after {
            display: inline-block;
            margin-left: auto;
            vertical-align: 0.255em;
            content: "";
            border-top: 0.3em solid transparent;
            border-bottom: 0.3em solid transparent;
            border-left: 0.3em solid;
            transform: rotate(0deg);
        }
        
        /* Mobil responsive menü düzeltmeleri */
        @media (max-width: 991.98px) {
            .dropdown-submenu .dropdown-menu {
                position: static !important;
                float: none;
                margin: 0;
                padding-left: 1rem;
                box-shadow: none;
                border: none;
                background: rgba(0,0,0,0.05);
            }
            
            .dropdown-submenu:hover .dropdown-menu {
                display: block;
            }
        }
        
        /* Select2 Bootstrap uyumu */
        .select2-container--default .select2-selection--single {
            height: calc(2.25rem + 2px);
            padding: 0.375rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0;
            padding-right: 20px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(2.25rem);
        }
        
        .select2-dropdown {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #ced4da;
            border-radius: 0.375rem;
        }
        
        /* Toggle/Switch Bileşeni */
        .form-switch .form-check-input {
            width: 2.5em;
            height: 1.25em;
            margin-top: 0.25em;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e");
            background-position: left center;
            border-radius: 2em;
            transition: background-position 0.15s ease-in-out, background-color 0.15s ease-in-out;
        }
        
        .form-switch .form-check-input:checked {
            background-color: #198754;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e");
            background-position: right center;
        }
        
        .form-switch .form-check-input:focus {
            border-color: #86efac;
            box-shadow: 0 0 0 0.25rem rgba(34, 197, 94, 0.25);
        }
        
        /* Checkbox Toggle Stilemesi */
        .form-check-input {
            width: 1.25em;
            height: 1.25em;
            margin-top: 0.125em;
            vertical-align: top;
            border: 1px solid #dee2e6;
            border-radius: 0.25em;
            appearance: none;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 8l4 4 8-8'/%3e%3c/svg%3e");
            background-position: center;
            background-repeat: no-repeat;
            background-size: contain;
            border-color: #dee2e6;
            transition: border-color 0.15s ease-in-out, background-color 0.15s ease-in-out;
        }
        
        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .form-check-input:hover {
            border-color: #0d6efd;
        }
        
        .form-check-input:focus {
            border-color: #0d6efd;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
    </style>
    
    <?php if (isset($additionalCSS)): ?>
        <?php echo $additionalCSS; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $basePath; ?>index.php">
                <img src="<?php echo $basePath; ?>assets/images/logo.png" alt="iLEKASoft Digiturk Rapor Sistemi" height="32">
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" 
                           href="<?php echo $basePath; ?>index.php">
                            <i class="fas fa-home me-1"></i>
                            Ana Sayfa
                        </a>
                    </li>
                    
                    <?php 
                    // Session kontrolü
                    if (session_status() == PHP_SESSION_NONE) {
                        session_start();
                    }
                    
                    // Kullanıcı giriş yapmış mı kontrol et
                    if (isset($_SESSION['user_id'])): 
                        // Veritabanı bağlantısı
                        $dbConfig = require __DIR__ . '/../config/mssql.php';
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
                        
                        // Kullanıcının grup ID'sini al
                        $userGroupId = $_SESSION['user_group_id'] ?? null;
                        $isAdmin = ($userGroupId == 1); // Admin kontrolü
                        
                        // Aktif modülleri ve kullanıcının yetki durumunu çek
                        if ($isAdmin) {
                            // Admin ise tüm modülleri göster
                            $modulQuery = "SELECT modul_id, modul_adi, modul_url, modul_ikon,
                                                 1 AS yetki_var
                                          FROM tanim_moduller
                                          WHERE durum = 1
                                          ORDER BY sira_no ASC, modul_id ASC";
                            $modulStmt = sqlsrv_query($conn, $modulQuery);
                        } else {
                            // Normal kullanıcı için yetki kontrolü yap
                            $modulQuery = "SELECT m.modul_id, m.modul_adi, m.modul_url, m.modul_ikon,
                                                 ISNULL(y.gor, 0) AS yetki_var
                                          FROM tanim_moduller m
                                          LEFT JOIN tanim_modul_menu_yetkiler y 
                                            ON m.modul_id = y.modul_id 
                                            AND y.user_group_id = ? 
                                            AND y.durum = 1
                                          WHERE m.durum = 1
                                          ORDER BY m.sira_no ASC, m.modul_id ASC";
                            $modulParams = array($userGroupId);
                            $modulStmt = sqlsrv_query($conn, $modulQuery, $modulParams);
                        }
                        
                        if ($modulStmt === false) {
                            die("Modül sorgusu hatası: " . print_r(sqlsrv_errors(), true));
                        }
                        
                        // Her modül için menü ve sayfa yapısını oluştur
                        while ($modul = sqlsrv_fetch_array($modulStmt, SQLSRV_FETCH_ASSOC)):
                            $modulId = $modul['modul_id'];
                            $modulAdi = htmlspecialchars($modul['modul_adi']);
                            $modulUrl = htmlspecialchars($modul['modul_url']);
                            $modulIkon = htmlspecialchars($modul['modul_ikon'] ?? 'fa-cogs');
                            $modulYetkisi = (int)$modul['yetki_var'];
                            
                            // Admin değilse ve modül yetkisi 0 ise modülü gösterme
                            if (!$isAdmin && $modulYetkisi === 0) {
                                continue;
                            }
                    ?>
                    
                    <!-- <?php echo $modulAdi; ?> Modülü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas <?php echo $modulIkon; ?> me-1"></i>
                            <?php echo $modulAdi; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php 
                            // Bu modüle ait aktif menüleri ve kullanıcının yetki durumunu çek
                            if ($isAdmin) {
                                // Admin ise tüm menüleri göster
                                $menuQuery = "SELECT menu_id, menu_adi, menu_url, ikon,
                                                    1 AS yetki_var
                                             FROM tanim_menuler
                                             WHERE modul_id = ? AND durum = 1
                                             ORDER BY sira_no ASC, menu_id ASC";
                                $menuParams = array($modulId);
                                $menuStmt = sqlsrv_query($conn, $menuQuery, $menuParams);
                            } else {
                                // Normal kullanıcı için yetki kontrolü yap
                                $menuQuery = "SELECT m.menu_id, m.menu_adi, m.menu_url, m.ikon,
                                                    ISNULL(y.gor, 0) AS yetki_var
                                             FROM tanim_menuler m
                                             LEFT JOIN tanim_modul_menu_yetkiler y 
                                               ON m.menu_id = y.menu_id 
                                               AND y.user_group_id = ? 
                                               AND y.durum = 1
                                             WHERE m.modul_id = ? AND m.durum = 1
                                             ORDER BY m.sira_no ASC, m.menu_id ASC";
                                $menuParams = array($userGroupId, $modulId);
                                $menuStmt = sqlsrv_query($conn, $menuQuery, $menuParams);
                            }
                            
                            if ($menuStmt === false) {
                                die("Menü sorgusu hatası: " . print_r(sqlsrv_errors(), true));
                            }
                            
                            // Her menü için sayfa listesini oluştur
                            while ($menu = sqlsrv_fetch_array($menuStmt, SQLSRV_FETCH_ASSOC)):
                                $menuId = $menu['menu_id'];
                                $menuAdi = htmlspecialchars($menu['menu_adi']);
                                $menuUrl = htmlspecialchars($menu['menu_url']);
                                $menuIkon = htmlspecialchars($menu['ikon'] ?? 'fa-folder');
                                $menuYetkisi = (int)$menu['yetki_var'];
                                
                                // Admin değilse ve menü yetkisi 0 ise menüyü gösterme
                                if (!$isAdmin && $menuYetkisi === 0) {
                                    continue;
                                }
                            ?>
                            
                            <!-- <?php echo $menuAdi; ?> Alt Menüsü -->
                            <li class="dropdown-submenu">
                                <a class="dropdown-item dropdown-toggle" href="#" role="button">
                                    <i class="fas <?php echo $menuIkon; ?> me-2"></i><?php echo $menuAdi; ?>
                                </a>
                                <ul class="dropdown-menu">
                                    <?php 
                                    // Bu menüye ait aktif sayfaları ve kullanıcının yetki durumunu çek
                                    if ($isAdmin) {
                                        // Admin ise tüm sayfaları göster
                                        $sayfaQuery = "SELECT sayfa_id, sayfa_adi, sayfa_url, sayfa_ikon,
                                                             1 AS yetki_var
                                                      FROM tanim_sayfalar 
                                                      WHERE menu_id = ? AND durum = 1 
                                                      ORDER BY sira_no ASC, sayfa_id ASC";
                                        $sayfaParams = array($menuId);
                                        $sayfaStmt = sqlsrv_query($conn, $sayfaQuery, $sayfaParams);
                                    } else {
                                        // Normal kullanıcı için yetki kontrolü yap
                                        $sayfaQuery = "SELECT s.sayfa_id, s.sayfa_adi, s.sayfa_url, s.sayfa_ikon,
                                                             ISNULL(y.gor, 0) AS yetki_var
                                                      FROM tanim_sayfalar s
                                                      LEFT JOIN tanim_sayfa_yetkiler y 
                                                        ON s.sayfa_id = y.sayfa_id 
                                                        AND y.user_group_id = ? 
                                                        AND y.durum = 1
                                                      WHERE s.menu_id = ? AND s.durum = 1 
                                                      ORDER BY s.sira_no ASC, s.sayfa_id ASC";
                                        $sayfaParams = array($userGroupId, $menuId);
                                        $sayfaStmt = sqlsrv_query($conn, $sayfaQuery, $sayfaParams);
                                    }
                                    
                                    if ($sayfaStmt === false) {
                                        die("Sayfa sorgusu hatası: " . print_r(sqlsrv_errors(), true));
                                    }
                                    
                                    // Her sayfayı listele
                                    while ($sayfa = sqlsrv_fetch_array($sayfaStmt, SQLSRV_FETCH_ASSOC)):
                                        $sayfaAdi = htmlspecialchars($sayfa['sayfa_adi']);
                                        $sayfaUrl = htmlspecialchars($sayfa['sayfa_url']);
                                        $sayfaIkon = htmlspecialchars($sayfa['sayfa_ikon'] ?? 'fa-file');
                                        $sayfaYetkisi = (int)$sayfa['yetki_var'];
                                        $sayfaPath = $basePath . 'views/' . $modulUrl . '/' . $menuUrl . '/' . $sayfaUrl;
                                        
                                        // Admin değilse ve sayfa yetkisi 0 ise sayfayı gösterme
                                        if (!$isAdmin && $sayfaYetkisi === 0) {
                                            continue;
                                        }
                                    ?>
                                    <li><a class="dropdown-item" href="<?php echo $sayfaPath; ?>">
                                        <i class="fas <?php echo $sayfaIkon; ?> me-2"></i><?php echo $sayfaAdi; ?>
                                    </a></li>
                                    <?php 
                                    endwhile; 
                                    sqlsrv_free_stmt($sayfaStmt);
                                    ?>
                                </ul>
                            </li>
                            
                            <?php 
                            endwhile; 
                            sqlsrv_free_stmt($menuStmt);
                            ?>
                        </ul>
                    </li>
                    
                    <?php 
                        endwhile;
                        sqlsrv_free_stmt($modulStmt);
                        
                        // Bağlantıyı kapat
                        sqlsrv_close($conn);
                    endif; 
                    ?>
                </ul>
                
                <ul class="navbar-nav">
                    <?php 
                    if (isset($_SESSION['user_id'])): 
                        $userName = $_SESSION['user_name'] ?? 'Kullanıcı';
                        $userEmail = $_SESSION['user_email'] ?? '';
                        $userGroupName = $_SESSION['user_group_name'] ?? '';
                    ?>
                        <!-- Kullanıcı Menüsü -->
                        <li class="nav-item dropdown me-2">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-2"></i>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($userName); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li class="dropdown-header">
                                    <small class="text-muted"><?php echo htmlspecialchars($userEmail); ?></small>
                                    <br><small class="text-info"><?php echo htmlspecialchars($userGroupName); ?></small>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?php echo $basePath; ?>logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Çıkış Yap
                                </a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <!-- Login Butonu -->
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $basePath; ?>login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>
                                Giriş Yap
                            </a>
                        </li>
                    <?php endif; ?>
                    

                </ul>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
    <div class="container mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?php echo $basePath; ?>index.php" class="text-decoration-none">
                        <i class="fas fa-home"></i> Ana Sayfa
                    </a>
                </li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (isset($crumb['url'])): ?>
                        <li class="breadcrumb-item">
                            <a href="<?php echo $crumb['url']; ?>" class="text-decoration-none">
                                <?php echo $crumb['title']; ?>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">
                            <?php echo $crumb['title']; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
    </div>
    <?php endif; ?>

    <!-- Main Content Container -->
    <div class="main-content">
        <div class="container">

    <script>
        // Dropdown alt menü işlevselliği
        document.addEventListener('DOMContentLoaded', function() {
            // Submenu hover ve click işlevselliği
            const submenus = document.querySelectorAll('.dropdown-submenu');
            
            submenus.forEach(function(submenu) {
                const toggle = submenu.querySelector('.dropdown-toggle');
                const menu = submenu.querySelector('.dropdown-menu');
                
                if (!toggle || !menu) return;
                
                // Desktop - Hover ile aç/kapat
                if (window.innerWidth > 992) {
                    submenu.addEventListener('mouseenter', function() {
                        menu.style.display = 'block';
                    });
                    
                    submenu.addEventListener('mouseleave', function() {
                        menu.style.display = 'none';
                    });
                    
                    // Desktop'ta da click ile toggle ekleyelim
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const isVisible = menu.style.display === 'block';
                        menu.style.display = isVisible ? 'none' : 'block';
                    });
                } else {
                    // Mobile - Click ile toggle
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Diğer tüm submenüleri kapat
                        document.querySelectorAll('.dropdown-submenu .dropdown-menu').forEach(function(otherMenu) {
                            if (otherMenu !== menu) {
                                otherMenu.style.display = 'none';
                            }
                        });
                        
                        const isVisible = menu.style.display === 'block';
                        menu.style.display = isVisible ? 'none' : 'block';
                    });
                }
            });
            
            // Ana dropdown kapanırken tüm submenüleri kapat
            const mainDropdowns = document.querySelectorAll('.navbar .dropdown');
            mainDropdowns.forEach(function(dropdown) {
                dropdown.addEventListener('hidden.bs.dropdown', function() {
                    document.querySelectorAll('.dropdown-submenu .dropdown-menu').forEach(function(menu) {
                        menu.style.display = 'none';
                    });
                });
            });
            
            // Ana dropdown açıldığında console'a log (debug için)
            mainDropdowns.forEach(function(dropdown) {
                dropdown.addEventListener('shown.bs.dropdown', function() {
                    console.log('Dropdown açıldı', dropdown);
                });
            });
        });
    </script>
