<?php
/**
 * API Kullanıcı Bilgisi Endpoint
 * Kimlik doğrulaması yapılan kullanıcının API bilgilerini döner
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

try {
    
    // Auth'dan gelen kullanıcı bilgilerini döndür
    echo json_encode([
        'success' => true,
        'user' => [
            'name' => $authUser['name'],
            'email' => $authUser['email'],
            'is_admin' => $authUser['is_admin']
        ],
        'api_accounts' => [
            'count' => count($authUser['api_ids']),
            'api_ids' => $authUser['api_ids'],
            'login_codes' => $authUser['api_logins'],
            'default_api_id' => $authUser['default_api_id']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
