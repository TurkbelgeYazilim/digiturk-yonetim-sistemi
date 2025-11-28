<?php
/**
 * Web Servis API Basic Authentication Middleware
 * 
 * Kullanıcı adı ve şifre ile kimlik doğrulama yapar
 * [dbo].[users] tablosundan kontrol eder
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 */

require_once __DIR__ . '/../../../config/mssql.php';

function authenticateBasicAuth() {
    // JSON response için header
    header('Content-Type: application/json; charset=UTF-8');
    
    // Basic Auth header'ını kontrol et
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
        // Auth bilgisi yok, istem gönder
        header('WWW-Authenticate: Basic realm="Digiturk Web Servis API"');
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode([
            'success' => false,
            'error' => 'Kimlik doğrulama gereklidir',
            'message' => 'Lütfen kullanıcı adı ve şifrenizi girin'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $username = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'];
    
    try {
        // Veritabanı bağlantısı
        $configFile = __DIR__ . '/../../../config/mssql.php';
        $config = include $configFile;
        
        $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']};TrustServerCertificate=yes";
        $conn = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        // Kullanıcıyı email ile bul
        $sql = "SELECT 
                    u.id,
                    u.email,
                    u.password_hash,
                    u.first_name,
                    u.last_name,
                    u.status,
                    u.user_group_id
                FROM [dbo].[users] u
                WHERE u.email = ? AND u.status = 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Kullanıcı bulunamadı
            header('WWW-Authenticate: Basic realm="Digiturk Web Servis API"');
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode([
                'success' => false,
                'error' => 'Geçersiz kullanıcı adı veya şifre'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Şifre kontrolü
        if (!password_verify($password, $user['password_hash'])) {
            header('WWW-Authenticate: Basic realm="Digiturk Web Servis API"');
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode([
                'success' => false,
                'error' => 'Geçersiz kullanıcı adı veya şifre'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Kullanıcının API ID'lerini çek
        $isAdmin = (int)$user['user_group_id'] === 1;
        
        if ($isAdmin) {
            // Admin ise tüm API kullanıcıları
            $apiSql = "SELECT DISTINCT 
                        api_iris_kullanici_ID, 
                        api_iris_kullanici_LoginCd 
                    FROM API_kullanici 
                    WHERE api_iris_kullanici_LoginCd IS NOT NULL 
                    ORDER BY api_iris_kullanici_LoginCd";
            $apiStmt = $conn->prepare($apiSql);
            $apiStmt->execute();
        } else {
            // Normal kullanıcı ise sadece kendisine ait olanlar
            $apiSql = "SELECT DISTINCT 
                        api_iris_kullanici_ID, 
                        api_iris_kullanici_LoginCd 
                    FROM API_kullanici 
                    WHERE users_ID = ? 
                    AND api_iris_kullanici_LoginCd IS NOT NULL 
                    ORDER BY api_iris_kullanici_LoginCd";
            $apiStmt = $conn->prepare($apiSql);
            $apiStmt->execute([$user['id']]);
        }
        
        $apiRecords = $apiStmt->fetchAll();
        
        // Benzersiz API ID'leri topla
        $apiIds = [];
        $uniqueLogins = [];
        
        foreach ($apiRecords as $record) {
            $loginCd = trim((string)$record['api_iris_kullanici_LoginCd']);
            if ($loginCd !== '' && !array_key_exists($loginCd, $uniqueLogins)) {
                $apiId = (int)$record['api_iris_kullanici_ID'];
                $uniqueLogins[$loginCd] = $apiId;
                $apiIds[] = $apiId;
            }
        }
        
        if (empty($apiIds)) {
            // API kullanıcısı yok
            header('HTTP/1.0 403 Forbidden');
            echo json_encode([
                'success' => false,
                'error' => 'Bu hesaba tanımlı API kullanıcısı bulunamadı',
                'message' => 'Lütfen yöneticinizle iletişime geçin'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Başarılı auth, kullanıcı bilgilerini döndür
        return [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'is_admin' => $isAdmin,
            'api_ids' => $apiIds,
            'api_logins' => $uniqueLogins,
            'default_api_id' => $apiIds[0] // İlk API ID'yi default olarak döndür
        ];
        
    } catch (Exception $e) {
        header('HTTP/1.0 500 Internal Server Error');
        echo json_encode([
            'success' => false,
            'error' => 'Sistem hatası',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Auth kontrolü yap ve kullanıcı bilgilerini döndür
$authUser = authenticateBasicAuth();
