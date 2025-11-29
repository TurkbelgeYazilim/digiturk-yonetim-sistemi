<?php
/**
 * Ödeme Tipleri Endpoint
 * Mevcut ödeme tiplerini JSON olarak döner
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
    
    // Ödeme tiplerini statik olarak döndür
    $paymentTypes = [
        [
            'id' => 1,
            'name' => 'Kredi Kartlı',
            'code' => 'credit_card',
            'description' => 'Kredi kartı ile ödeme'
        ],
        [
            'id' => 2,
            'name' => 'Faturalı',
            'code' => 'invoice',
            'description' => 'Fatura ile ödeme'
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'authenticated_user' => $authUser['email'],
        'data' => $paymentTypes,
        'count' => count($paymentTypes)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}