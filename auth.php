<?php
// Veritabanı bağlantı fonksiyonu
function getDatabaseConnection() {
    $configFile = __DIR__ . '/config/mssql.php';
    
    if (!file_exists($configFile)) {
        throw new Exception('Veritabanı konfigürasyon dosyası bulunamadı: ' . $configFile);
    }
    
    $config = include $configFile;
    
    try {
        $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']};TrustServerCertificate=yes";
        
        $connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        return $connection;
        
    } catch (PDOException $e) {
        throw new Exception('Veritabanı bağlantı hatası: ' . $e->getMessage());
    }
}

// Auth kontrol fonksiyonu
function checkAuth() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        // Root dizine göre login.php'ye yönlendir
        $rootPath = str_replace('\\', '/', realpath(__DIR__));
        $currentPath = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['PHP_SELF'])));
        $relativePath = str_repeat('../', substr_count(str_replace($rootPath, '', $currentPath), '/'));
        header('Location: ' . $relativePath . 'login.php');
        exit;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'name' => $_SESSION['user_name'],
        'phone' => $_SESSION['user_phone'],
        'group_name' => $_SESSION['user_group_name'] ?? null,
        'group_id' => $_SESSION['user_group_id'] ?? null,
        'permissions' => $_SESSION['user_permissions'] ?? null
    ];
}

// Admin yetkisi kontrol fonksiyonu (geçici olarak tüm kullanıcılar için erişim açık)
function checkAdminAuth() {
    $user = checkAuth();
    
    // Yetki kısıtlaması geçici olarak kaldırıldı
    // Herhangi bir login olmuş kullanıcı erişebilir
    
    return $user;
}

// Belirli yetki kontrolü fonksiyonu (geçici olarak tüm kullanıcılar için erişim açık)
function checkPermission($module, $action) {
    $user = checkAuth();
    
    // user_groups.id = 1 olan kullanıcılar tüm işlemleri yapabilir
    if (isset($user['group_id']) && $user['group_id'] == 1) {
        return true;
    }
    
    try {
        $conn = getDatabaseConnection();
        
        // Module bazlı yetki kontrolü (sayfa URL'sine göre)
        $sql = "SELECT COUNT(*) as has_permission
                FROM tanim_sayfa_yetkiler tsy
                INNER JOIN tanim_sayfalar ts ON tsy.sayfa_id = ts.sayfa_id
                WHERE tsy.user_group_id = ? 
                AND ts.sayfa_url LIKE ?
                AND tsy.durum = 1 
                AND ts.durum = 1
                AND (
                    (? = 'view' AND tsy.gor = 1) OR
                    (? = 'add' AND tsy.ekle = 1) OR
                    (? = 'edit' AND tsy.duzenle = 1) OR
                    (? = 'delete' AND tsy.sil = 1)
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $user['group_id'],
            '%' . $module . '%',
            $action, $action, $action, $action
        ]);
        
        $result = $stmt->fetch();
        return $result['has_permission'] > 0;
        
    } catch (Exception $e) {
        return false;
    }
}

// Sayfa yetki kontrolü fonksiyonu
function checkPagePermission($page) {
    $user = checkAuth();
    
    // user_groups.id = 1 olan kullanıcılar tüm sayfalara erişebilir
    if (isset($user['group_id']) && $user['group_id'] == 1) {
        return true;
    }
    
    // Admin grubu tüm sayfalara erişebilir
    if (isset($user['group_name']) && strtolower($user['group_name']) === 'admin') {
        return true;
    }
    
    try {
        $conn = getDatabaseConnection();
        
        // Sayfa URL'sine göre yetki kontrolü
        $sql = "SELECT COUNT(*) as has_access
                FROM tanim_sayfa_yetkiler tsy
                INNER JOIN tanim_sayfalar ts ON tsy.sayfa_id = ts.sayfa_id
                WHERE tsy.user_group_id = ? 
                AND ts.sayfa_url LIKE ?
                AND tsy.gor = 1
                AND tsy.durum = 1 
                AND ts.durum = 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$user['group_id'], '%' . $page . '%']);
        
        $result = $stmt->fetch();
        return $result['has_access'] > 0;
        
    } catch (Exception $e) {
        return false;
    }
}

// Kullanıcının sadece kendi verilerini görebilir mi kontrolü
function checkOwnDataOnly() {
    $user = checkAuth();
    
    // user_groups.id = 1 olan kullanıcılar tüm verileri görebilir
    if (isset($user['group_id']) && $user['group_id'] == 1) {
        return false;
    }
    
    try {
        $conn = getDatabaseConnection();
        
        $sql = "SELECT COUNT(*) as has_restriction
                FROM tanim_sayfa_yetkiler tsy
                WHERE tsy.user_group_id = ? 
                AND tsy.kendi_kullanicinu_gor = 1
                AND tsy.durum = 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$user['group_id']]);
        
        $result = $stmt->fetch();
        return $result['has_restriction'] > 0;
        
    } catch (Exception $e) {
        return false;
    }
}

// Kullanıcı grup bilgilerini getir
function getUserGroup($userId) {
    try {
        $conn = getDatabaseConnection();
        
        $sql = "SELECT ug.id, ug.group_name, ug.group_description 
                FROM user_groups ug 
                INNER JOIN users u ON u.user_group_id = ug.id 
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// Kullanıcı bilgilerini güncelle
function updateLastActivity() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id'])) {
        try {
            $conn = getDatabaseConnection();
            
            $sql = "UPDATE users SET updated_at = GETDATE() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$_SESSION['user_id']]);
        } catch (Exception $e) {
            // Hata loglanabilir
        }
    }
}

// Kullanıcı durumunu kontrol et
function checkUserStatus() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id'])) {
        try {
            $conn = getDatabaseConnection();
            
            $sql = "SELECT status FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || $user['status'] != 1) {
                // Kullanıcı pasif hale getirilmiş, oturumu sonlandır
                session_destroy();
                header('Location: login.php?error=account_deactivated');
                exit;
            }
        } catch (Exception $e) {
            // Hata durumunda güvenlik için çıkış yap
            session_destroy();
            header('Location: login.php?error=system_error');
            exit;
        }
    }
}

// Session timeout kontrolü (30 dakika)
function checkSessionTimeout() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    $timeout = 30 * 60; // 30 dakika
    
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > $timeout) {
            session_destroy();
            header('Location: login.php?error=session_timeout');
            exit;
        }
    }
    
    $_SESSION['last_activity'] = time();
}
?>
