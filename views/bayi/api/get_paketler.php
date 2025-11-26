<?php
// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();

header('Content-Type: application/json');

try {
    $conn = getDatabaseConnection();
    
    $table = $_POST['table'] ?? '';
    $idField = $_POST['idField'] ?? '';
    $nameField = $_POST['nameField'] ?? '';
    $priceField = $_POST['priceField'] ?? '';
    
    // SQL injection koruması için izin verilen tablo isimlerini kontrol et
    $allowedTables = ['API_GetSatelliteCampaignList', 'API_GetNeoCampaignList'];
    
    if (!in_array($table, $allowedTables)) {
        throw new Exception('Geçersiz tablo');
    }
    
    // Ödeme türü ve durum alanlarını belirle
    $odemeTuruField = '';
    $durumField = '';
    if ($table == 'API_GetSatelliteCampaignList') {
        $odemeTuruField = 'API_GetSatelliteCampaignList_odeme_turu_id';
        $durumField = 'API_GetSatelliteCampaignList_durum';
    } elseif ($table == 'API_GetNeoCampaignList') {
        $odemeTuruField = 'API_GetNeoCampaignList_odeme_turu_id';
        $durumField = 'API_GetNeoCampaignList_durum';
    }
    
    $sql = "SELECT 
                t.$idField as id, 
                t.$nameField as name, 
                t.$priceField as price,
                ot.API_odeme_turu_ad as odeme_turu
            FROM $table t
            LEFT JOIN API_odeme_turu ot ON t.$odemeTuruField = ot.API_odeme_turu_ID
            WHERE t.$durumField = 1
            ORDER BY t.$nameField";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $paketler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($paketler);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
