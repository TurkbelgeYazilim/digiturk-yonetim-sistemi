<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Kampanya Listesi Endpoint
 * Aktif kampanyaları JSON olarak döner
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
require_once '../../../../../config/mssql.php';

function getDatabaseConnection() {
    $configFile = __DIR__ . '/../../../../../config/mssql.php';
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

try {
    $conn = getDatabaseConnection();
    
    $sql = "SELECT 
                API_CampaignList_ID as id,
                API_CampaignList_CampaignName as name,
                API_CampaignList_Durum as active
            FROM API_CampaignList 
            WHERE API_CampaignList_Durum = 1 
            ORDER BY API_CampaignList_CampaignName";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $campaigns = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'authenticated_user' => $authUser['email'],
        'data' => $campaigns,
        'count' => count($campaigns)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

