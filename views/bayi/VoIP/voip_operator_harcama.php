<?php
// Auth kontrolü
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// Sayfa yetkilendirme kontrolü
$sayfaYetkileri = [
    'gor' => false,
    'kendi_kullanicini_gor' => false,
    'ekle' => false,
    'duzenle' => false,
    'sil' => false
];

// Admin kontrolü (group_id = 1 ise tüm yetkilere sahip)
$isAdmin = ($currentUser['group_id'] == 1);

if ($isAdmin) {
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0,
        'ekle' => 1,
        'duzenle' => 1,
        'sil' => 1
    ];
} else {
    try {
        $conn = getDatabaseConnection();
        $currentPageUrl = basename($_SERVER['PHP_SELF']);
        
        $yetkiSql = "
            SELECT tsy.gor, tsy.kendi_kullanicini_gor, tsy.ekle, tsy.duzenle, tsy.sil
            FROM dbo.tanim_sayfalar ts
            INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
            WHERE ts.sayfa_url = ? AND tsy.user_group_id = ? AND ts.durum = 1 AND tsy.durum = 1
        ";
        
        $yetkiStmt = $conn->prepare($yetkiSql);
        $yetkiStmt->execute([$currentPageUrl, $currentUser['group_id']]);
        $yetkiResult = $yetkiStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($yetkiResult) {
            $sayfaYetkileri = [
                'gor' => (int)$yetkiResult['gor'],
                'kendi_kullanicini_gor' => (int)$yetkiResult['kendi_kullanicini_gor'],
                'ekle' => (int)$yetkiResult['ekle'],
                'duzenle' => (int)$yetkiResult['duzenle'],
                'sil' => (int)$yetkiResult['sil']
            ];
            
            if ($sayfaYetkileri['gor'] == 0) {
                header('Location: ../../../index.php?error=yetki_yok');
                exit;
            }
        } else {
            header('Location: ../../../index.php?error=yetki_tanimlanmamis');
            exit;
        }
    } catch (Exception $e) {
        error_log("Yetki kontrol hatası: " . $e->getMessage());
        header('Location: ../../../index.php?error=sistem_hatasi');
        exit;
    }
}

$pageTitle = "VoIP Numara Harcamalar";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '../../../index.php'],
    ['title' => 'VoIP', 'url' => '#'],
    ['title' => 'VoIP Numara Harcamalar']
];

$message = '';
$messageType = '';

// VoIP Harcama işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'upload_excel':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Excel yükleme yetkiniz bulunmamaktadır.');
                }
                if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Excel dosyası yükleme hatası.');
                }
                
                $excelTarih = $_POST['excel_tarih'];
                $skipFirstRow = isset($_POST['skip_first_row']);
                
                // Dosya uzantısını kontrol et
                $fileName = $_FILES['excel_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['xlsx', 'xls'])) {
                    throw new Exception('Sadece .xlsx ve .xls dosyaları kabul edilir.');
                }
                
                // Dosyayı geçici klasöre taşı
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $uploadFile = $uploadDir . 'temp_excel_' . time() . '.' . $fileExtension;
                if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $uploadFile)) {
                    throw new Exception('Dosya yükleme hatası.');
                }
                
                try {
                    // SimpleXLSX kütüphanesini kullan (PhpSpreadsheet alternatifi)
                    require_once 'includes/SimpleXLSX.php';
                    
                    if ($xlsx = SimpleXLSX::parse($uploadFile)) {
                        $rows = $xlsx->rows();
                        $processedCount = 0;
                        $errorCount = 0;
                        $errors = [];
                        
                        // Sütun başlıklarını kontrol et (eğer ilk satır başlık ise)
                        $costColumnIndex = 5; // Varsayılan Cost sütunu (6. sütun)
                        $chargedAmountColumnIndex = 4; // Charged Amount sütunu (5. sütun)
                        
                        if ($skipFirstRow && count($rows) > 0) {
                            $headerRow = $rows[0];
                            // Cost sütununu ara
                            $costFound = false;
                            for ($col = 0; $col < count($headerRow); $col++) {
                                $header = strtolower(trim($headerRow[$col] ?? ''));
                                if (strpos($header, 'cost') !== false) {
                                    $costColumnIndex = $col;
                                    $costFound = true;
                                    break;
                                }
                            }
                            
                            // Cost bulunamadıysa Charged Amount'u ara
                            if (!$costFound) {
                                for ($col = 0; $col < count($headerRow); $col++) {
                                    $header = strtolower(trim($headerRow[$col] ?? ''));
                                    if (strpos($header, 'charged amount') !== false || strpos($header, 'charged') !== false) {
                                        $costColumnIndex = $col;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // VoIP numaralarını önce al (hızlı arama için)
                        $voipNumMap = [];
                        $voipSql = "SELECT voip_operator_numara_id, voip_operator_numara_VoIPNo FROM voip_operator_numara";
                        $voipStmt = $conn->prepare($voipSql);
                        $voipStmt->execute();
                        while ($row = $voipStmt->fetch(PDO::FETCH_ASSOC)) {
                            $voipNumMap[trim($row['voip_operator_numara_VoIPNo'])] = $row['voip_operator_numara_id'];
                        }
                        
                        $startRow = $skipFirstRow ? 1 : 0;
                        
                        for ($i = $startRow; $i < count($rows); $i++) {
                            $row = $rows[$i];
                            
                            if (count($row) < 6) {
                                $errors[] = "Satır " . ($i + 1) . ": Eksik sütun";
                                $errorCount++;
                                continue;
                            }
                            
                            $customerName = trim($row[0] ?? ''); // Customer Name
                            $numberOfCalls = !empty($row[1]) ? (int)$row[1] : null; // Number of Calls
                            $duration = trim($row[2] ?? ''); // Duration, min
                            $cost = !empty($row[$costColumnIndex]) ? (float)$row[$costColumnIndex] : 0; // Cost veya Charged Amount
                            
                            // Customer Name'den "Acct. " kısmını temizle
                            $cleanCustomerName = $customerName;
                            if (strpos($customerName, 'Acct. ') === 0) {
                                $cleanCustomerName = trim(substr($customerName, 6)); // "Acct. " kısmını çıkar
                            }
                            
                            // VoIP numara ID'sini bul (temizlenmiş isimle)
                            $voipNumaraId = null;
                            if (isset($voipNumMap[$cleanCustomerName])) {
                                $voipNumaraId = $voipNumMap[$cleanCustomerName];
                            } elseif (isset($voipNumMap[$customerName])) {
                                // Orijinal isimle de dene
                                $voipNumaraId = $voipNumMap[$customerName];
                            }
                            
                            if (!$voipNumaraId) {
                                $errors[] = "Satır " . ($i + 1) . ": VoIP numara bulunamadı: '$customerName' (aranan: '$cleanCustomerName')";
                                $errorCount++;
                                continue;
                            }
                            
                            // Duration formatını kontrol et ve dönüştür
                            $durationStr = null;
                            if ($duration) {
                                // Eğer sayısal ise dakika cinsinden, hh:mm formatına çevir
                                if (is_numeric($duration)) {
                                    $minutes = (int)$duration;
                                    $hours = floor($minutes / 60);
                                    $mins = $minutes % 60;
                                    $durationStr = sprintf('%02d:%02d', $hours, $mins);
                                } else {
                                    $durationStr = $duration;
                                }
                            }
                            
                            // Tarihi formatla
                            $formattedDate = $excelTarih . ' 00:00:00';
                            
                            // Aynı numara ve aynı tarihteki mevcut kaydı kontrol et
                            $checkSql = "SELECT voip_operator_Harcama_id FROM voip_operator_Harcama 
                                         WHERE voip_operator_numara_id = ? 
                                         AND CAST(voip_operator_Harcama_Tarih AS DATE) = CAST(? AS DATE)";
                            $checkStmt = $conn->prepare($checkSql);
                            $checkStmt->execute([$voipNumaraId, $formattedDate]);
                            $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($existingRecord) {
                                // Mevcut kayıt varsa güncelle
                                $updateSql = "UPDATE voip_operator_Harcama SET 
                                              voip_operator_Harcama_CagriSayisi = ?, 
                                              voip_operator_Harcama_Sure = ?, 
                                              voip_operator_Harcama_Ucret = ? 
                                              WHERE voip_operator_Harcama_id = ?";
                                $updateStmt = $conn->prepare($updateSql);
                                
                                if ($updateStmt->execute([$numberOfCalls, $durationStr, $cost, $existingRecord['voip_operator_Harcama_id']])) {
                                    $processedCount++;
                                } else {
                                    $errors[] = "Satır " . ($i + 1) . ": Güncelleme hatası";
                                    $errorCount++;
                                }
                            } else {
                                // Yeni kayıt ekle
                                $insertSql = "INSERT INTO voip_operator_Harcama 
                                              (voip_operator_numara_id, voip_operator_Harcama_CagriSayisi, voip_operator_Harcama_Sure, 
                                               voip_operator_Harcama_Tarih, voip_operator_Harcama_Ucret) 
                                              VALUES (?, ?, ?, ?, ?)";
                                $insertStmt = $conn->prepare($insertSql);
                                
                                if ($insertStmt->execute([$voipNumaraId, $numberOfCalls, $durationStr, $formattedDate, $cost])) {
                                    $processedCount++;
                                } else {
                                    $errors[] = "Satır " . ($i + 1) . ": Veritabanı hatası";
                                    $errorCount++;
                                }
                            }
                        }
                        
                        // Geçici dosyayı sil
                        unlink($uploadFile);
                        
                        // İstatistikler için ayrı sayaçlar ekle
                        $addedCount = 0;
                        $updatedCount = 0;
                        
                        // Performans için kayıt türlerini takip etmek yerine genel işlem sayısını kullanıyoruz
                        if ($processedCount > 0) {
                            $message = "Excel dosyası başarıyla yüklendi. $processedCount kayıt işlendi (eklendi/güncellendi).";
                            if ($errorCount > 0) {
                                $message .= " $errorCount kayıtta hata oluştu.";
                            }
                            $messageType = 'success';
                        } else {
                            $message = 'Hiçbir kayıt işlenemedi. Lütfen dosya formatını kontrol edin.';
                            $messageType = 'warning';
                        }
                        
                        if (!empty($errors)) {
                            $message .= '<br><small>Hatalar: ' . implode(', ', array_slice($errors, 0, 5)) . '</small>';
                        }
                        
                    } else {
                        unlink($uploadFile);
                        throw new Exception('Excel dosyası okunamadı: ' . SimpleXLSX::parseError());
                    }
                    
                } catch (Exception $e) {
                    if (file_exists($uploadFile)) {
                        unlink($uploadFile);
                    }
                    throw new Exception('Excel işleme hatası: ' . $e->getMessage());
                }
                break;
                
            case 'add':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                
                $voipOperatorNumaraId = (int)$_POST['voip_operator_numara_id'];
                $cagriSayisi = $_POST['voip_operator_Harcama_CagriSayisi'] ? (int)$_POST['voip_operator_Harcama_CagriSayisi'] : null;
                $sure = trim($_POST['voip_operator_Harcama_Sure']) ?: null;
                $tarih = $_POST['voip_operator_Harcama_Tarih'];
                
                // Tarih formatını MSSQL için dönüştür
                if ($tarih) {
                    $dateTime = new DateTime($tarih);
                    $tarih = $dateTime->format('Y-m-d H:i:s');
                }
                
                $ucret = (float)$_POST['voip_operator_Harcama_Ucret'];
                
                // Aynı numara ve aynı tarihteki mevcut kaydı kontrol et
                $checkSql = "SELECT voip_operator_Harcama_id FROM voip_operator_Harcama 
                             WHERE voip_operator_numara_id = ? 
                             AND CAST(voip_operator_Harcama_Tarih AS DATE) = CAST(? AS DATE)";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$voipOperatorNumaraId, $tarih]);
                $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingRecord) {
                    // Mevcut kayıt varsa güncelle
                    $sql = "UPDATE voip_operator_Harcama SET 
                            voip_operator_Harcama_CagriSayisi = ?, voip_operator_Harcama_Sure = ?, 
                            voip_operator_Harcama_Tarih = ?, voip_operator_Harcama_Ucret = ? 
                            WHERE voip_operator_Harcama_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$cagriSayisi, $sure, $tarih, $ucret, $existingRecord['voip_operator_Harcama_id']]);
                    $message = 'Aynı tarihte kayıt bulundu, mevcut kayıt güncellendi.';
                } else {
                    // Yeni kayıt ekle
                    $sql = "INSERT INTO voip_operator_Harcama 
                            (voip_operator_numara_id, voip_operator_Harcama_CagriSayisi, voip_operator_Harcama_Sure, 
                             voip_operator_Harcama_Tarih, voip_operator_Harcama_Ucret) 
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$voipOperatorNumaraId, $cagriSayisi, $sure, $tarih, $ucret]);
                    $message = 'VoIP harcama kaydı başarıyla eklendi.';
                }
                
                $messageType = 'success';
                break;
                
            case 'edit':
                if ($sayfaYetkileri['duzenle'] != 1) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['voip_operator_Harcama_id'];
                $voipOperatorNumaraId = (int)$_POST['voip_operator_numara_id'];
                $cagriSayisi = $_POST['voip_operator_Harcama_CagriSayisi'] ? (int)$_POST['voip_operator_Harcama_CagriSayisi'] : null;
                $sure = trim($_POST['voip_operator_Harcama_Sure']) ?: null;
                $tarih = $_POST['voip_operator_Harcama_Tarih'];
                
                // Tarih formatını MSSQL için dönüştür
                if ($tarih) {
                    $dateTime = new DateTime($tarih);
                    $tarih = $dateTime->format('Y-m-d H:i:s');
                }
                
                $ucret = (float)$_POST['voip_operator_Harcama_Ucret'];
                
                $sql = "UPDATE voip_operator_Harcama SET 
                        voip_operator_numara_id = ?, voip_operator_Harcama_CagriSayisi = ?, voip_operator_Harcama_Sure = ?, 
                        voip_operator_Harcama_Tarih = ?, voip_operator_Harcama_Ucret = ? 
                        WHERE voip_operator_Harcama_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$voipOperatorNumaraId, $cagriSayisi, $sure, $tarih, $ucret, $id]);
                
                $message = 'VoIP harcama kaydı başarıyla güncellendi.';
                $messageType = 'success';
                break;
                
            case 'delete':
                if ($sayfaYetkileri['sil'] != 1) {
                    throw new Exception('Silme yetkiniz bulunmamaktadır.');
                }
                
                $id = (int)$_POST['id'];
                
                $sql = "DELETE FROM voip_operator_Harcama WHERE voip_operator_Harcama_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                $message = 'VoIP harcama kaydı başarıyla silindi.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// VoIP Numaralarını al
try {
    $conn = getDatabaseConnection();
    
    $numeraSql = "SELECT n.voip_operator_numara_id, n.voip_operator_numara_VoIPNo, o.voip_operator_adi 
                  FROM voip_operator_numara n 
                  LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id 
                  WHERE n.voip_operator_numara_durum = 1 
                  ORDER BY o.voip_operator_adi, n.voip_operator_numara_VoIPNo";
    $numeraStmt = $conn->prepare($numeraSql);
    $numeraStmt->execute();
    $voipNumbers = $numeraStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $voipNumbers = [];
}

// Filtreleme parametrelerini al
$filterNumara = $_GET['filter_numara'] ?? '';
$filterTarihBaslangic = $_GET['filter_tarih_baslangic'] ?? '';
$filterTarihBitis = $_GET['filter_tarih_bitis'] ?? '';
$filterAltBayi = $_GET['filter_alt_bayi'] ?? '';

// Alt Bayi listesini al (filtreleme için)
$altBayilar = [];
try {
    $altBayiSql = "SELECT DISTINCT u.id, (u.first_name + ' ' + u.last_name) as alt_bayi_adi
                   FROM users u
                   INNER JOIN voip_operator_NoTeslim nt ON u.id = nt.voip_operator_users_id
                   WHERE u.first_name IS NOT NULL AND u.last_name IS NOT NULL
                   ORDER BY alt_bayi_adi";
    $altBayiStmt = $conn->prepare($altBayiSql);
    $altBayiStmt->execute();
    $altBayilar = $altBayiStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $altBayilar = [];
}

// VoIP Harcamalarını listele
$harcamalar = [];
$error = '';
try {
    $conn = getDatabaseConnection();
    
    $whereConditions = [];
    $params = [];
    
    // Kendi kullanıcısını görme yetkisi kontrolü - sadece kendi alt bayilerini göster
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $whereConditions[] = "latest_assignment.voip_operator_users_id = ?";
        $params[] = $currentUser['id'];
        // Filtreyi de otomatik olarak ayarla
        $filterAltBayi = $currentUser['id'];
    }
    
    if ($filterNumara) {
        $whereConditions[] = "h.voip_operator_numara_id = ?";
        $params[] = $filterNumara;
    }
    
    if ($filterTarihBaslangic) {
        $whereConditions[] = "CAST(h.voip_operator_Harcama_Tarih AS DATE) >= ?";
        $params[] = $filterTarihBaslangic;
    }
    
    if ($filterTarihBitis) {
        $whereConditions[] = "CAST(h.voip_operator_Harcama_Tarih AS DATE) <= ?";
        $params[] = $filterTarihBitis;
    }
    
    // Kendi kullanıcısını görmüyorsa, filtre uygulanabilir
    if ($sayfaYetkileri['kendi_kullanicini_gor'] != 1 && $filterAltBayi) {
        $whereConditions[] = "latest_assignment.voip_operator_users_id = ?";
        $params[] = $filterAltBayi;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT h.*, n.voip_operator_numara_VoIPNo, o.voip_operator_adi,
                   u.first_name, u.last_name,
                   (u.first_name + ' ' + u.last_name) as alt_bayi_adi
            FROM voip_operator_Harcama h 
            LEFT JOIN voip_operator_numara n ON h.voip_operator_numara_id = n.voip_operator_numara_id
            LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
            LEFT JOIN (
                SELECT nt.voip_operator_numara_id, 
                       nt.voip_operator_users_id,
                       ROW_NUMBER() OVER (PARTITION BY nt.voip_operator_numara_id ORDER BY nt.voip_operator_NoTeslim_TeslimTarihi DESC) as rn
                FROM voip_operator_NoTeslim nt
                WHERE nt.voip_operator_users_id IS NOT NULL
            ) latest_assignment ON n.voip_operator_numara_id = latest_assignment.voip_operator_numara_id AND latest_assignment.rn = 1
            LEFT JOIN users u ON latest_assignment.voip_operator_users_id = u.id
            $whereClause
            ORDER BY h.voip_operator_Harcama_Tarih DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $harcamalar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Toplam istatistikleri hesapla
    $toplamSql = "SELECT 
                    COUNT(*) as toplam_kayit,
                    ISNULL(SUM(h.voip_operator_Harcama_CagriSayisi), 0) as toplam_cagri,
                    ISNULL(SUM(h.voip_operator_Harcama_Ucret), 0) as toplam_ucret
                  FROM voip_operator_Harcama h 
                  LEFT JOIN voip_operator_numara n ON h.voip_operator_numara_id = n.voip_operator_numara_id
                  LEFT JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
                  LEFT JOIN (
                      SELECT nt.voip_operator_numara_id, 
                             nt.voip_operator_users_id,
                             ROW_NUMBER() OVER (PARTITION BY nt.voip_operator_numara_id ORDER BY nt.voip_operator_NoTeslim_TeslimTarihi DESC) as rn
                      FROM voip_operator_NoTeslim nt
                      WHERE nt.voip_operator_users_id IS NOT NULL
                  ) latest_assignment ON n.voip_operator_numara_id = latest_assignment.voip_operator_numara_id AND latest_assignment.rn = 1
                  LEFT JOIN users u ON latest_assignment.voip_operator_users_id = u.id
                  $whereClause";
    $toplamStmt = $conn->prepare($toplamSql);
    $toplamStmt->execute($params);
    $istatistikler = $toplamStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-dollar-sign me-2"></i>VoIP Numara Harcamalar</h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <div class="btn-group" role="group">
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadExcelModal">
                        <i class="fas fa-file-excel me-1"></i>Excel Yükle
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHarcamaModal">
                        <i class="fas fa-plus me-1"></i>Yeni Harcama
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Kendi kullanıcısını görme yetkisi uyarısı -->
    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Sadece kendi alt bayinize ait harcama kayıtlarını görüntüleyebilirsiniz.
    </div>
    <?php endif; ?>

    <!-- İstatistikler -->
    <?php if (isset($istatistikler)): ?>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-primary">
                        <i class="fas fa-list-ol fa-2x mb-2"></i>
                    </div>
                    <h5 class="card-title">Toplam Kayıt</h5>
                    <h3 class="text-primary"><?php echo number_format($istatistikler['toplam_kayit']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-info">
                        <i class="fas fa-phone fa-2x mb-2"></i>
                    </div>
                    <h5 class="card-title">Toplam Çağrı</h5>
                    <h3 class="text-info"><?php echo number_format($istatistikler['toplam_cagri']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-success">
                        <i class="fas fa-lira-sign fa-2x mb-2"></i>
                    </div>
                    <h5 class="card-title">Toplam Ücret</h5>
                    <h3 class="text-success"><?php echo number_format($istatistikler['toplam_ucret'], 2); ?> ₺</h3>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtreleme -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreleme
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">VoIP Numara</label>
                    <select name="filter_numara" class="form-select select2-search">
                        <option value="">Tümü</option>
                        <?php foreach ($voipNumbers as $number): ?>
                            <option value="<?php echo $number['voip_operator_numara_id']; ?>" <?php echo $filterNumara == $number['voip_operator_numara_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($number['voip_operator_adi']) . ' - ' . htmlspecialchars($number['voip_operator_numara_VoIPNo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Alt Bayi</label>
                    <select name="filter_alt_bayi" class="form-select select2-search" <?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>>
                        <option value="">Tümü</option>
                        <?php foreach ($altBayilar as $bayi): ?>
                            <option value="<?php echo $bayi['id']; ?>" <?php echo $filterAltBayi == $bayi['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($bayi['alt_bayi_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
                    <small class="text-muted">Otomatik filtrelenmiştir</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Başlangıç Tarihi</label>
                    <input type="date" name="filter_tarih_baslangic" class="form-control" value="<?php echo $filterTarihBaslangic; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bitiş Tarihi</label>
                    <input type="date" name="filter_tarih_bitis" class="form-control" value="<?php echo $filterTarihBitis; ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <a href="voip_operator_harcama.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- VoIP Harcamalar Tablosu -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>VoIP Harcama Listesi
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>VoIP Operatör</th>
                            <th>VoIP No</th>
                            <th>Alt Bayi</th>
                            <th>Çağrı Sayısı</th>
                            <th>Süre</th>
                            <th>Tarih</th>
                            <th>Ücret (₺)</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($harcamalar)): ?>
                            <?php foreach ($harcamalar as $harcama): ?>
                                <tr>
                                    <td><?php echo $harcama['voip_operator_Harcama_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($harcama['voip_operator_adi'] ?? 'Bilinmeyen'); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($harcama['voip_operator_numara_VoIPNo']); ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($harcama['alt_bayi_adi'])): ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($harcama['alt_bayi_adi']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($harcama['voip_operator_Harcama_CagriSayisi']): ?>
                                            <span class="badge bg-info"><?php echo number_format($harcama['voip_operator_Harcama_CagriSayisi']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $harcama['voip_operator_Harcama_Sure'] ? htmlspecialchars($harcama['voip_operator_Harcama_Sure']) : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td>
                                        <?php echo $harcama['voip_operator_Harcama_Tarih'] ? date('d.m.Y H:i', strtotime($harcama['voip_operator_Harcama_Tarih'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo number_format($harcama['voip_operator_Harcama_Ucret'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="editHarcama(<?php echo htmlspecialchars(json_encode($harcama)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($sayfaYetkileri['sil'] == 1): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteHarcama(<?php echo $harcama['voip_operator_Harcama_id']; ?>, '<?php echo htmlspecialchars($harcama['voip_operator_numara_VoIPNo']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-dollar-sign fa-3x mb-3 d-block"></i>
                                    <h5>Henüz harcama kaydı bulunmuyor</h5>
                                    <p>İlk harcama kaydını eklemek için "Yeni Harcama" butonuna tıklayın.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Excel Yükleme Modal -->
<div class="modal fade" id="uploadExcelModal" tabindex="-1" aria-labelledby="uploadExcelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadExcelModalLabel">
                    <i class="fas fa-file-excel me-2"></i>VoIP Harcama Excel Dosyası Yükle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload_excel">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Excel Dosyası Formatı:</strong><br>
                        Customer Name, Number of Calls, Duration (min), Billed Duration (min), Charged Amount, Cost, Currency<br>
                        <small class="text-muted">Customer Name: "Acct. 902129221364" veya "902129221364" formatında olabilir<br>
                        Ücret: "Cost" sütunu yoksa "Charged Amount" sütunu kullanılır</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="excel_tarih" class="form-label">Harcama Tarihi <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="excel_tarih" name="excel_tarih" required>
                        <div class="form-text">Yüklenen tüm kayıtlar bu tarih ile kaydedilecek</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Excel Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                        <div class="form-text">Sadece .xlsx ve .xls dosyaları kabul edilir</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="skip_first_row" name="skip_first_row" checked>
                            <label class="form-check-label" for="skip_first_row">
                                İlk satırı atla (başlık satırı)
                            </label>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Dikkat:</strong> Customer Name alanında mevcut VoIP numaralarınız bulunmalıdır.<br>
                        <small>Format: "902129221364" veya "Acct. 902129221364" (sistem otomatik olarak "Acct. " kısmını temizler)</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-sync-alt me-2"></i>
                        <strong>Mükerrer Kayıt:</strong> Aynı numara ve aynı tarihte kayıt varsa otomatik güncellenir.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success" id="uploadBtn">
                        <i class="fas fa-upload me-1"></i>Yükle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Yükleniyor...</span>
                </div>
                <p class="mt-3">Excel dosyası işleniyor...</p>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Harcama Ekleme Modal -->
<div class="modal fade" id="addHarcamaModal" tabindex="-1" aria-labelledby="addHarcamaModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addHarcamaModalLabel">
                    <i class="fas fa-plus me-2"></i>Yeni VoIP Harcama Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="voip_operator_numara_id" class="form-label">VoIP Numara <span class="text-danger">*</span></label>
                        <select class="form-select select2-search" id="voip_operator_numara_id" name="voip_operator_numara_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($voipNumbers as $number): ?>
                                <option value="<?php echo $number['voip_operator_numara_id']; ?>">
                                    <?php echo htmlspecialchars($number['voip_operator_adi']) . ' - ' . htmlspecialchars($number['voip_operator_numara_VoIPNo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_Harcama_CagriSayisi" class="form-label">Çağrı Sayısı</label>
                        <input type="number" class="form-control" id="voip_operator_Harcama_CagriSayisi" name="voip_operator_Harcama_CagriSayisi" min="0" placeholder="örn: 2089">
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_Harcama_Sure" class="form-label">Süre (dk:sn)</label>
                        <input type="text" class="form-control" id="voip_operator_Harcama_Sure" name="voip_operator_Harcama_Sure" maxlength="10" placeholder="örn: 03:45">
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_Harcama_Tarih" class="form-label">Tarih <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="voip_operator_Harcama_Tarih" name="voip_operator_Harcama_Tarih" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="voip_operator_Harcama_Ucret" class="form-label">Ücret (₺) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="voip_operator_Harcama_Ucret" name="voip_operator_Harcama_Ucret" step="0.00001" min="0" required placeholder="örn: 53.54167">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Harcama Düzenleme Modal -->
<div class="modal fade" id="editHarcamaModal" tabindex="-1" aria-labelledby="editHarcamaModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editHarcamaModalLabel">
                    <i class="fas fa-edit me-2"></i>VoIP Harcama Düzenle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editHarcamaForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="voip_operator_Harcama_id" id="edit_voip_operator_Harcama_id">
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_numara_id" class="form-label">VoIP Numara <span class="text-danger">*</span></label>
                        <select class="form-select select2-search" id="edit_voip_operator_numara_id" name="voip_operator_numara_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($voipNumbers as $number): ?>
                                <option value="<?php echo $number['voip_operator_numara_id']; ?>">
                                    <?php echo htmlspecialchars($number['voip_operator_adi']) . ' - ' . htmlspecialchars($number['voip_operator_numara_VoIPNo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_Harcama_CagriSayisi" class="form-label">Çağrı Sayısı</label>
                        <input type="number" class="form-control" id="edit_voip_operator_Harcama_CagriSayisi" name="voip_operator_Harcama_CagriSayisi" min="0" placeholder="örn: 2089">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_Harcama_Sure" class="form-label">Süre (dk:sn)</label>
                        <input type="text" class="form-control" id="edit_voip_operator_Harcama_Sure" name="voip_operator_Harcama_Sure" maxlength="10" placeholder="örn: 03:45">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_Harcama_Tarih" class="form-label">Tarih <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="edit_voip_operator_Harcama_Tarih" name="voip_operator_Harcama_Tarih" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_voip_operator_Harcama_Ucret" class="form-label">Ücret (₺) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="edit_voip_operator_Harcama_Ucret" name="voip_operator_Harcama_Ucret" step="0.00001" min="0" required placeholder="örn: 53.54167">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Güncelle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Silme Onay Modal -->
<div class="modal fade" id="deleteHarcamaModal" tabindex="-1" aria-labelledby="deleteHarcamaModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteHarcamaModalLabel">
                    <i class="fas fa-trash me-2"></i>Harcama Sil
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Bu VoIP harcama kaydını silmek istediğinizden emin misiniz?</p>
                <p><strong id="deleteHarcamaInfo"></strong></p>
                <p class="text-danger"><small>Bu işlem geri alınamaz!</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteHarcamaForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteHarcamaId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Sil
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Select2'yi tüm select2-search class'lı elementlere uygula
    $('.select2-search').select2({
        placeholder: 'Ara ve seç...',
        allowClear: true,
        language: {
            noResults: function() {
                return "Sonuç bulunamadı";
            },
            searching: function() {
                return "Aranıyor...";
            },
            inputTooShort: function() {
                return "En az 1 karakter giriniz";
            }
        }
    });
    
    // Modal açıldığında Select2'yi yeniden başlat
    $('#addHarcamaModal, #editHarcamaModal').on('shown.bs.modal', function () {
        $(this).find('.select2-search').select2({
            placeholder: 'Ara ve seç...',
            allowClear: true,
            dropdownParent: $(this),
            language: {
                noResults: function() {
                    return "Sonuç bulunamadı";
                },
                searching: function() {
                    return "Aranıyor...";
                },
                inputTooShort: function() {
                    return "En az 1 karakter giriniz";
                }
            }
        });
    });
});

// Sayfa yüklendiğinde bugünün tarihini varsayılan olarak ayarla
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    const localISOTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
    document.getElementById('voip_operator_Harcama_Tarih').value = localISOTime;
    
    // Excel modal için bugünün tarihini ayarla
    const today = now.toISOString().slice(0, 10);
    document.getElementById('excel_tarih').value = today;
    
    // Excel upload form için loading efekti
    const uploadForm = document.querySelector('#uploadExcelModal form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function() {
            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
            loadingModal.show();
            
            // Upload modal'ını kapat
            const uploadModal = bootstrap.Modal.getInstance(document.getElementById('uploadExcelModal'));
            if (uploadModal) {
                uploadModal.hide();
            }
        });
    }
});

function editHarcama(harcama) {
    // Modal formuna veri doldur
    document.getElementById('edit_voip_operator_Harcama_id').value = harcama.voip_operator_Harcama_id;
    $('#edit_voip_operator_numara_id').val(harcama.voip_operator_numara_id).trigger('change');
    document.getElementById('edit_voip_operator_Harcama_CagriSayisi').value = harcama.voip_operator_Harcama_CagriSayisi || '';
    document.getElementById('edit_voip_operator_Harcama_Sure').value = harcama.voip_operator_Harcama_Sure || '';
    document.getElementById('edit_voip_operator_Harcama_Ucret').value = harcama.voip_operator_Harcama_Ucret;
    
    // Tarih formatını datetime-local için uygun hale getir
    if (harcama.voip_operator_Harcama_Tarih) {
        const date = new Date(harcama.voip_operator_Harcama_Tarih);
        const localISOTime = new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
        document.getElementById('edit_voip_operator_Harcama_Tarih').value = localISOTime;
    }
    
    // Modal'ı aç
    new bootstrap.Modal(document.getElementById('editHarcamaModal')).show();
}

function deleteHarcama(id, voipNo) {
    document.getElementById('deleteHarcamaId').value = id;
    document.getElementById('deleteHarcamaInfo').textContent = voipNo + ' numarası için harcama kaydı';
    new bootstrap.Modal(document.getElementById('deleteHarcamaModal')).show();
}
</script>

<?php include '../../../includes/footer.php'; ?>