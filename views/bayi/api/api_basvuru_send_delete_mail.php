<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit;
}

// POST kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek metodu']);
    exit;
}

// JSON verisi al
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Preview mode kontrolü
if (isset($data['preview']) && $data['preview'] === true) {
    // Sadece dinamik CC listesini döndür
    $organisationCodes = $data['organisationCodes'] ?? [];
    
    if (empty($organisationCodes)) {
        echo json_encode(['success' => true, 'dynamicCc' => []]);
        exit;
    }
    
    $config = require_once '../../../config/mssql.php';
    
    try {
        $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']}";
        $conn = new PDO($dsn, $config['username'], $config['password']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $placeholders = str_repeat('?,', count($organisationCodes) - 1) . '?';
        $orgCcQuery = $conn->prepare("
            SELECT DISTINCT u.email
            FROM users u
            INNER JOIN API_kullanici a ON a.users_ID = u.id
            WHERE a.api_iris_kullanici_OrganisationCd IN ($placeholders)
            AND u.user_group_id = 2
            AND u.status = 1
            AND u.email IS NOT NULL
            AND u.email != ''
        ");
        $orgCcQuery->execute($organisationCodes);
        $orgCcResults = $orgCcQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $dynamicCc = array_map(function($row) { return trim($row['email']); }, $orgCcResults);
        
        echo json_encode(['success' => true, 'dynamicCc' => $dynamicCc]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Normal mail gönderimi
$selectedItems = $data;

if (empty($selectedItems) || !is_array($selectedItems)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Geçersiz veri']);
    exit;
}

// Config ve mail helper'ı dahil et
$config = require_once '../../../config/mssql.php';
require_once '../../../includes/mail_helper.php';

try {
    // PDO bağlantısı kur
    $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']}";
    $conn = new PDO($dsn, $config['username'], $config['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Kullanıcı bilgilerini al (grup halinde, tekrarlı sorguları önlemek için)
    $kullaniciIds = array_unique(array_filter(array_column($selectedItems, 'kullaniciId')));
    $kullaniciEmails = [];
    
    if (!empty($kullaniciIds)) {
        $placeholders = str_repeat('?,', count($kullaniciIds) - 1) . '?';
        $kullaniciQuery = $conn->prepare("
            SELECT u.id, u.email 
            FROM users u
            WHERE u.id IN ($placeholders) AND u.status = 1
        ");
        $kullaniciQuery->execute($kullaniciIds);
        $kullaniciResults = $kullaniciQuery->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($kullaniciResults as $row) {
            if (!empty($row['email'])) {
                $kullaniciEmails[$row['id']] = $row['email'];
            }
        }
    }
    
    // Organisation Code'ları topla
    $organisationCodes = array_unique(array_filter(array_column($selectedItems, 'organisationCode')));
    
    // Mail içeriğini hazırla
    $phoneList = [];
    $tcList = [];
    $ccEmails = ['broadbandsales@digiturk.com.tr'];
    
    // Dinamik CC: Organisation Code'a göre Grup 2 kullanıcıları
    if (!empty($organisationCodes)) {
        $placeholders = str_repeat('?,', count($organisationCodes) - 1) . '?';
        $orgCcQuery = $conn->prepare("
            SELECT DISTINCT u.email
            FROM users u
            INNER JOIN API_kullanici a ON a.users_ID = u.id
            WHERE a.api_iris_kullanici_OrganisationCd IN ($placeholders)
            AND u.user_group_id = 2
            AND u.status = 1
            AND u.email IS NOT NULL
            AND u.email != ''
        ");
        $orgCcQuery->execute($organisationCodes);
        $orgCcResults = $orgCcQuery->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orgCcResults as $row) {
            if (!empty($row['email']) && !in_array(trim($row['email']), $ccEmails)) {
                $ccEmails[] = trim($row['email']);
            }
        }
    }
    
    foreach ($selectedItems as $item) {
        // Telefon numarasını formatla
        $phone = trim($item['phoneCountry']) . ' ' . trim($item['phoneArea']) . ' ' . trim($item['phoneNumber']);
        $phoneList[] = $phone;
        
        // TC kimlik numarası
        if (!empty($item['citizen'])) {
            $tcList[] = trim($item['citizen']);
        }
        
        // Kullanıcı email'ini CC'ye ekle
        if (!empty($item['kullaniciId']) && isset($kullaniciEmails[$item['kullaniciId']])) {
            $userEmail = trim($kullaniciEmails[$item['kullaniciId']]);
            if (!empty($userEmail) && !in_array($userEmail, $ccEmails)) {
                $ccEmails[] = $userEmail;
            }
        }
    }
    
    // Mail içeriği
    $subject = "Numara Sildirme";
    
    $body = "Merhaba,\n\n";
    $body .= "Aşağıdaki numaraları silebilir miyiz?\n\n";
    $body .= "Telefon Numaraları:\n";
    $body .= implode("\n", $phoneList);
    
    if (!empty($tcList)) {
        $body .= "\n\nTC Kimlik Numaraları:\n";
        $body .= implode("\n", $tcList);
    }
    
    $body .= "\n\nTeşekkürler.";
    
    // Mail gönder
    $to = 'WH_dgtbayisatisdestek@concentrix.com';
    $cc = implode(', ', $ccEmails);
    $bcc = 'batuhan.kahraman@ileka.com.tr';
    
    $result = sendMail($to, $subject, $body, false, $cc, $bcc);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Mail başarıyla gönderildi',
            'count' => count($selectedItems)
        ]);
    } else {
        throw new Exception('Mail gönderilemedi');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
