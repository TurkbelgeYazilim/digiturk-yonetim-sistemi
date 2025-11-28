<?php
/**
 * Paket Listesi Endpoint
 * Kampanyaya göre paket listesini JSON olarak döner
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 */

// Basic Auth kontrolü
require_once '../auth-middleware.php';
// $authUser değişkeni auth-middleware'den geliyor

// CORS ayarları
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Veritabanı bağlantısı
require_once '../../../../config/mssql.php';

function getDatabaseConnection() {
    $configFile = __DIR__ . '/../../../../config/mssql.php';
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

// Kampanya ID kontrolü
$kampanyaId = isset($_GET['kampanya']) ? intval($_GET['kampanya']) : null;

if (!$kampanyaId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'kampanya parametresi zorunludur',
        'usage' => 'packages.php?kampanya=1'
    ]);
    exit;
}

try {
    $conn = getDatabaseConnection();
    $packages = [];
    
    // Kampanya 1 = Uydu (Kutulu TV)
    if ($kampanyaId == 1) {
        $sql = "SELECT 
                    API_GetSatelliteCampaignList_ID as id,
                    API_GetSatelliteCampaignList_PaketAdi as name,
                    API_GetSatelliteCampaignList_Fiyat as price,
                    API_GetSatelliteCampaignList_odeme_turu_id as payment_type,
                    API_GetSatelliteCampaignList_Hediye as gift,
                    API_GetSatelliteCampaignList_Periyot as period
                FROM API_GetSatelliteCampaignList 
                WHERE API_GetSatelliteCampaignList_durum = 1 
                ORDER BY API_GetSatelliteCampaignList_PaketAdi";
    }
    // Kampanya 2 = Neo (Kutusuz TV)
    elseif ($kampanyaId == 2) {
        $sql = "SELECT 
                    API_GetNeoCampaignList_ID as id,
                    API_GetNeoCampaignList_PaketAdi as name,
                    API_GetNeoCampaignList_Fiyat as price,
                    API_GetNeoCampaignList_odeme_turu_id as payment_type,
                    API_GetNeoCampaignList_Hediye as gift,
                    API_GetNeoCampaignList_Periyot as period
                FROM API_GetNeoCampaignList 
                WHERE API_GetNeoCampaignList_durum = 1 
                ORDER BY API_GetNeoCampaignList_PaketAdi";
    } else {
        throw new Exception('Geçersiz kampanya ID. Sadece 1 (Kutulu) veya 2 (Neo) kullanılabilir.');
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $packages = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'authenticated_user' => $authUser['email'],
        'kampanya_id' => $kampanyaId,
        'kampanya_name' => $kampanyaId == 1 ? 'Kutulu TV Paketi' : 'Kutusuz TV Paketi (NEO)',
        'data' => $packages,
        'count' => count($packages)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
