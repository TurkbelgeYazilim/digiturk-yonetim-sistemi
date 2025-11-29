<?php
/**
 * Başvuru Formu Endpoint
 * Başvuru formunu HTML olarak döner (iframe için)
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 */

// CORS ayarları
$allowedOrigin = '*';
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $allowedOrigin = $_SERVER['HTTP_ORIGIN'];
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $refererParts = parse_url($_SERVER['HTTP_REFERER']);
    $allowedOrigin = ($refererParts['scheme'] ?? 'https') . '://' . ($refererParts['host'] ?? '');
}
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('P3P: CP="CAO PSA OUR"');

// Basic Auth kontrolü
require_once '../auth-middleware.php';

// Parametreleri al
$kampanyaId = isset($_GET['kampanya']) ? intval($_GET['kampanya']) : null;
$paketId = isset($_GET['paket']) ? intval($_GET['paket']) : null;

// Auth'dan gelen default API ID'yi kullan
$apiId = $authUser['default_api_id'];

// Mevcut başvuru sayfasına yönlendir
$redirectUrl = 'https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=' . $apiId;
if ($kampanyaId) $redirectUrl .= '&kampanya=' . $kampanyaId;
if ($paketId) $redirectUrl .= '&paket=' . $paketId;

// iframe içinde yönlendirme
header('Location: ' . $redirectUrl);
exit;
