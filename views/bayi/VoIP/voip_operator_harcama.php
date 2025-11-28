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

// Session'dan mesaj al (varsa)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'] ?? 'info';
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
} else {
    $message = '';
    $messageType = '';
}

// VoIP Harcama işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'bulk_add':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Toplu ekleme yetkiniz bulunmamaktadır.');
                }
                
                $bulkTarih = $_POST['bulk_tarih'];
                $bulkData = trim($_POST['bulk_data']);
                
                if (empty($bulkData)) {
                    throw new Exception('Lütfen eklenecek verileri giriniz.');
                }
                
                // Satırları ayır
                $lines = explode("\n", $bulkData);
                $processedCount = 0;
                $errorCount = 0;
                $errors = [];
                
                // VoIP numaralarını önce al (hızlı arama için)
                $voipNumMap = [];
                $voipSql = "SELECT voip_operator_numara_id, voip_operator_numara_VoIPNo FROM voip_operator_numara";
                $voipStmt = $conn->prepare($voipSql);
                $voipStmt->execute();
                while ($row = $voipStmt->fetch(PDO::FETCH_ASSOC)) {
                    $voipNumMap[trim($row['voip_operator_numara_VoIPNo'])] = $row['voip_operator_numara_id'];
                }
                
                foreach ($lines as $lineIndex => $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue; // Boş satırları atla
                    }
                    
                    $rowNumber = $lineIndex + 1;
                    
                    // Tab veya birden fazla boşlukla ayrılmış olabilir
                    $parts = preg_split('/\t+|\s{2,}/', $line);
                    
                    if (count($parts) < 4) {
                        $errors[] = "Satır $rowNumber: Eksik sütun (en az 4 sütun gerekli: Numara, Adet, Süre, Ücret)";
                        $errorCount++;
                        continue;
                    }
                    
                    $numara = trim($parts[0]);
                    
                    // Başlık satırını atla (Numara, Number, No vs.)
                    if (preg_match('/^(numara|number|no|telefon|voip)/i', $numara)) {
                        continue;
                    }
                    
                    // Adet ve Süre: virgül ve noktaları temizle (5,463 -> 5463)
                    $adet = !empty($parts[1]) ? (int)str_replace([',', '.'], '', trim($parts[1])) : null;
                    $sureRaw = trim($parts[2]);
                    
                    // Ücret: virgülü nokta ile değiştir (7,942 -> 7942.00 veya 7.942 -> 7942.00)
                    $ucretRaw = trim($parts[3]);
                    // Eğer hem nokta hem virgül varsa: 1.234,56 formatı (Avrupa) -> 1234.56
                    if (strpos($ucretRaw, '.') !== false && strpos($ucretRaw, ',') !== false) {
                        // 1.234,56 veya 1,234.56 formatı
                        if (strrpos($ucretRaw, ',') > strrpos($ucretRaw, '.')) {
                            // Virgül en sonda -> Avrupa formatı (1.234,56)
                            $ucret = (float)str_replace(['.', ','], ['', '.'], $ucretRaw);
                        } else {
                            // Nokta en sonda -> ABD formatı (1,234.56)
                            $ucret = (float)str_replace(',', '', $ucretRaw);
                        }
                    } elseif (strpos($ucretRaw, ',') !== false) {
                        // Sadece virgül var: binlik ayracı olabilir (7,942 -> 7942)
                        $ucret = (float)str_replace(',', '', $ucretRaw);
                    } elseif (strpos($ucretRaw, '.') !== false) {
                        // Sadece nokta var: binlik ayracı olabilir (7.942 -> 7942)
                        $ucret = (float)str_replace('.', '', $ucretRaw);
                    } else {
                        // Hiç ayraç yok
                        $ucret = (float)$ucretRaw;
                    }
                    
                    // Numara formatını temizle (sadece rakamlar)
                    $cleanNumara = preg_replace('/[^0-9]/', '', $numara);
                    
                    // Numara boş veya çok kısa ise atla
                    if (empty($cleanNumara) || strlen($cleanNumara) < 10) {
                        $errors[] = "Satır $rowNumber: Geçersiz numara formatı: '$numara'";
                        $errorCount++;
                        continue;
                    }
                    
                    // VoIP numara ID'sini bul (hem 90'lı hem 90'sız dene)
                    $voipNumaraId = null;
                    $searchVariants = [
                        $cleanNumara,              // Orjinal numara
                        '90' . $cleanNumara,       // 90 eklenmiş hali
                        ltrim($cleanNumara, '90'), // 90 çıkarılmış hali
                        $numara                    // Format değiştirilmemiş hali
                    ];
                    
                    foreach ($searchVariants as $variant) {
                        if (!empty($variant) && isset($voipNumMap[$variant])) {
                            $voipNumaraId = $voipNumMap[$variant];
                            break;
                        }
                    }
                    
                    if (!$voipNumaraId) {
                        $errors[] = "Satır $rowNumber: VoIP numara bulunamadı: '$numara' (denenen: $cleanNumara, 90$cleanNumara)";
                        $errorCount++;
                        continue;
                    }
                    
                    // Süreyi formatla (dakika cinsinden geliyorsa hh:mm formatına çevir)
                    $durationStr = null;
                    if ($sureRaw) {
                        // Virgül veya noktayı temizle (29,139 -> 29139 dakika)
                        $sureClean = str_replace([',', '.'], '', $sureRaw);
                        if (is_numeric($sureClean)) {
                            $minutes = (int)$sureClean;
                            $hours = floor($minutes / 60);
                            $mins = $minutes % 60;
                            $durationStr = sprintf('%02d:%02d', $hours, $mins);
                        } else {
                            $durationStr = $sureRaw;
                        }
                    }
                    
                    // Tarihi formatla
                    $formattedDate = $bulkTarih . ' 00:00:00';
                    
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
                        
                        if ($updateStmt->execute([$adet, $durationStr, $ucret, $existingRecord['voip_operator_Harcama_id']])) {
                            $processedCount++;
                        } else {
                            $errors[] = "Satır $rowNumber: Güncelleme hatası";
                            $errorCount++;
                        }
                    } else {
                        // Yeni kayıt ekle
                        $insertSql = "INSERT INTO voip_operator_Harcama 
                                      (voip_operator_numara_id, voip_operator_Harcama_CagriSayisi, voip_operator_Harcama_Sure, 
                                       voip_operator_Harcama_Tarih, voip_operator_Harcama_Ucret) 
                                      VALUES (?, ?, ?, ?, ?)";
                        $insertStmt = $conn->prepare($insertSql);
                        
                        if ($insertStmt->execute([$voipNumaraId, $adet, $durationStr, $formattedDate, $ucret])) {
                            $processedCount++;
                        } else {
                            $errors[] = "Satır $rowNumber: Veritabanı hatası";
                            $errorCount++;
                        }
                    }
                }
                
                if ($processedCount > 0) {
                    $message = "Panodan toplu ekleme başarıyla tamamlandı. $processedCount kayıt işlendi (eklendi/güncellendi).";
                    if ($errorCount > 0) {
                        $message .= " $errorCount kayıtta hata oluştu.";
                    }
                    $messageType = 'success';
                } else {
                    $message = 'Hiçbir kayıt işlenemedi. Lütfen veri formatını kontrol edin.';
                    $messageType = 'warning';
                }
                
                if (!empty($errors)) {
                    $message .= '<br><small>Hatalar: ' . implode(', ', array_slice($errors, 0, 10)) . '</small>';
                }
                break;
                
            case 'upload_excel':
                if ($sayfaYetkileri['ekle'] != 1) {
                    throw new Exception('Excel yükleme yetkiniz bulunmamaktadır.');
                }
                
                // Dosya yükleme hatalarını detaylı kontrol et
                if (!isset($_FILES['excel_file'])) {
                    throw new Exception('Excel dosyası gönderilmedi.');
                }
                
                $fileError = $_FILES['excel_file']['error'];
                if ($fileError !== UPLOAD_ERR_OK) {
                    $errorMessages = [
                        UPLOAD_ERR_INI_SIZE => 'Dosya boyutu php.ini dosyasındaki upload_max_filesize değerini aşıyor.',
                        UPLOAD_ERR_FORM_SIZE => 'Dosya boyutu HTML formundaki MAX_FILE_SIZE değerini aşıyor.',
                        UPLOAD_ERR_PARTIAL => 'Dosya sadece kısmen yüklendi.',
                        UPLOAD_ERR_NO_FILE => 'Hiçbir dosya yüklenmedi.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı.',
                        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
                        UPLOAD_ERR_EXTENSION => 'Bir PHP eklentisi dosya yüklemeyi durdurdu.'
                    ];
                    $errorMsg = $errorMessages[$fileError] ?? 'Bilinmeyen dosya yükleme hatası (kod: ' . $fileError . ')';
                    throw new Exception('Excel dosyası yükleme hatası: ' . $errorMsg);
                }
                
                $excelTarih = $_POST['excel_tarih'];
                $skipFirstRow = isset($_POST['skip_first_row']);
                
                // Dosya uzantısını kontrol et
                $fileName = $_FILES['excel_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['xlsx', 'xls', 'csv'])) {
                    throw new Exception('Sadece .xlsx, .xls ve .csv dosyaları kabul edilir.');
                }
                
                // Dosyayı geçici klasöre taşı (temp klasörü)
                $uploadDir = realpath(__DIR__ . '/../../../temp');
                
                if ($uploadDir === false) {
                    // Klasör yoksa oluştur
                    $uploadDir = __DIR__ . '/../../../temp';
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new Exception('Temp klasörü oluşturulamadı: ' . $uploadDir);
                    }
                    $uploadDir = realpath($uploadDir);
                }
                
                // Sondaki slash'i ekle
                $uploadDir = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                
                if (!is_writable($uploadDir)) {
                    throw new Exception('Temp klasörüne yazma izni yok: ' . $uploadDir);
                }
                
                $uploadFile = $uploadDir . 'voip_harcama_' . time() . '.' . $fileExtension;
                $tempFile = $_FILES['excel_file']['tmp_name'];
                
                if (!file_exists($tempFile)) {
                    throw new Exception('Geçici dosya bulunamadı.');
                }
                
                if (!move_uploaded_file($tempFile, $uploadFile)) {
                    throw new Exception('Dosya taşıma hatası. Kaynak: ' . $tempFile . ' Hedef: ' . $uploadFile);
                }
                
                try {
                    $rows = [];
                    
                    // CSV mi Excel mi kontrol et
                    if ($fileExtension === 'csv') {
                        // CSV dosyasını oku
                        if (($handle = fopen($uploadFile, 'r')) !== false) {
                            while (($data = fgetcsv($handle, 10000, ',')) !== false) {
                                $rows[] = $data;
                            }
                            fclose($handle);
                        } else {
                            throw new Exception('CSV dosyası açılamadı.');
                        }
                    } else {
                        // SimpleXLSX kütüphanesini kullan
                        $simpleXLSXPath = realpath(__DIR__ . '/../../../includes/SimpleXLSX.php');
                        if ($simpleXLSXPath === false || !file_exists($simpleXLSXPath)) {
                            throw new Exception('SimpleXLSX kütüphanesi bulunamadı: ' . __DIR__ . '/../../../includes/SimpleXLSX.php');
                        }
                        require_once $simpleXLSXPath;
                        
                        if (!class_exists('SimpleXLSX')) {
                            throw new Exception('SimpleXLSX sınıfı yüklenemedi.');
                        }
                        
                        if ($xlsx = SimpleXLSX::parse($uploadFile)) {
                            $rows = $xlsx->rows();
                        } else {
                            throw new Exception('Excel dosyası okunamadı: ' . SimpleXLSX::parseError());
                        }
                    }
                    
                    if (!empty($rows)) {
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
                        throw new Exception('Dosya okunamadı veya boş.');
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
        
        // İşlem başarılı, mesajı session'a kaydet ve yönlendir
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
        
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
        
        // Hatayı logla
        error_log('VoIP Harcama Hatası [' . $action . ']: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        
        // Hata durumunda da session'a kaydet ve yönlendir
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
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
$filterOperator = $_GET['filter_operator'] ?? '';
$filterTarihBaslangic = $_GET['filter_tarih_baslangic'] ?? '';
$filterTarihBitis = $_GET['filter_tarih_bitis'] ?? '';
$filterAltBayi = $_GET['filter_alt_bayi'] ?? '';

// VoIP Operatör listesini al (filtreleme için)
$voipOperatorler = [];
try {
    $operatorSql = "SELECT voip_operator_id, voip_operator_adi 
                    FROM voip_operator_tanim 
                    WHERE voip_operator_durum = 1 
                    ORDER BY voip_operator_adi";
    $operatorStmt = $conn->prepare($operatorSql);
    $operatorStmt->execute();
    $voipOperatorler = $operatorStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $voipOperatorler = [];
}

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
    
    if ($filterOperator) {
        $whereConditions[] = "o.voip_operator_id = ?";
        $params[] = $filterOperator;
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
                <div class="btn-group" role="group">
                    <button class="btn btn-success" onclick="exportToExcel()">
                        <i class="fas fa-file-excel me-1"></i>Excel İndir
                    </button>
                    <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadExcelModal">
                        <i class="fas fa-file-import me-1"></i>Dosya Yükle (Excel/CSV)
                    </button>
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
                        <i class="fas fa-clipboard me-1"></i>Panodan Ekle
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHarcamaModal">
                        <i class="fas fa-plus me-1"></i>Yeni Harcama
                    </button>
                    <?php endif; ?>
                </div>
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
                    <h3 class="text-success"><?php echo number_format($istatistikler['toplam_ucret'], 2, ',', '.'); ?> ₺</h3>
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
                <div class="col-md-2">
                    <label class="form-label">VoIP Operatör</label>
                    <select name="filter_operator" class="form-select select2-search">
                        <option value="">Tümü</option>
                        <?php foreach ($voipOperatorler as $operator): ?>
                            <option value="<?php echo $operator['voip_operator_id']; ?>" <?php echo $filterOperator == $operator['voip_operator_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($operator['voip_operator_adi']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
            </form>
            <div class="row mt-2">
                <div class="col-md-12">
                    <a href="voip_operator_harcama.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times me-1"></i>Filtreleri Temizle
                    </a>
                </div>
            </div>
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
                                        <strong class="text-success"><?php echo number_format($harcama['voip_operator_Harcama_Ucret'], 2, ',', '.'); ?></strong>
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

<!-- Panodan Toplu Ekleme Modal -->
<div class="modal fade" id="bulkAddModal" tabindex="-1" aria-labelledby="bulkAddModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkAddModalLabel">
                    <i class="fas fa-clipboard me-2"></i>Panodan Toplu Ekleme
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_add">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Kullanım:</strong> Excel'den veya başka yerden kopyaladığınız verileri aşağıdaki alana yapıştırın.<br>
                        <strong>Format:</strong> Her satırda <code>Numara [TAB] Adet [TAB] Süre [TAB] Ücret</code> şeklinde olmalı.<br>
                        <small class="text-muted">
                            <strong>Örnek:</strong><br>
                            3125616023&nbsp;&nbsp;&nbsp;&nbsp;5463&nbsp;&nbsp;&nbsp;&nbsp;525&nbsp;&nbsp;&nbsp;&nbsp;135<br>
                            3125616024&nbsp;&nbsp;&nbsp;&nbsp;466&nbsp;&nbsp;&nbsp;&nbsp;984&nbsp;&nbsp;&nbsp;&nbsp;238<br>
                            3125616027&nbsp;&nbsp;&nbsp;&nbsp;450534&nbsp;&nbsp;&nbsp;&nbsp;29139&nbsp;&nbsp;&nbsp;&nbsp;7942
                        </small>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="bulk_tarih" class="form-label">Harcama Tarihi (Gün) <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="bulk_tarih" name="bulk_tarih" required>
                            <div class="form-text">
                                <i class="fas fa-info-circle text-primary"></i> 
                                Tüm kayıtlar bu tarih ile kaydedilecek. Aynı gün + aynı numara varsa güncellenir.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">İstatistik</label>
                            <div class="p-2 bg-light rounded">
                                <div class="d-flex justify-content-between">
                                    <span>Satır Sayısı:</span>
                                    <strong id="bulk_line_count">0</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Geçerli Satır:</span>
                                    <strong id="bulk_valid_count" class="text-success">0</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_data" class="form-label">Veri <span class="text-danger">*</span></label>
                        <textarea class="form-control font-monospace" id="bulk_data" name="bulk_data" rows="15" required 
                                  placeholder="Numara    Adet    Süre (Dakika)    Ücret&#10;3125616023    5463    525    135&#10;3125616024    466    984    238&#10;3125616027    450534    29139    7942"></textarea>
                        <div class="form-text">
                            Excel'den kopyala-yapıştır yapabilirsiniz. TAB veya çoklu boşluk ile ayrılmış olmalı.
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Dikkat:</strong> Numara alanında sistemde kayıtlı VoIP numaraları olmalıdır. Eşleşmeyen numaralar atlanır.
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-sync-alt me-2"></i>
                        <strong>Mükerrer Kayıt:</strong> Aynı numara ve aynı tarihte kayıt varsa otomatik güncellenir.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-info" id="bulkAddBtn">
                        <i class="fas fa-save me-1"></i>Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Excel Yükleme Modal -->
<div class="modal fade" id="uploadExcelModal" tabindex="-1" aria-labelledby="uploadExcelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadExcelModalLabel">
                    <i class="fas fa-file-excel me-2"></i>VoIP Harcama Dosyası Yükle (Excel/CSV)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload_excel">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Dosya Formatı:</strong><br>
                        Customer Name, Number of Calls, Duration (min), Billed Duration (min), Charged Amount, Cost, Currency<br>
                        <small class="text-muted">
                            • Customer Name: "Acct. 902129221364" veya "902129221364" formatında olabilir<br>
                            • Ücret: "Cost" sütunu yoksa "Charged Amount" sütunu kullanılır<br>
                            • Dosya adından tarih otomatik parse edilir (örn: Customer_Summary_Report_from_2025-11-25_...)
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="excel_tarih" class="form-label">Harcama Tarihi <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="excel_tarih" name="excel_tarih" required>
                        <div class="form-text">
                            <i class="fas fa-info-circle text-primary"></i> 
                            Dosya adından otomatik algılanır veya manuel seçebilirsiniz. Yüklenen tüm kayıtlar bu tarih ile kaydedilecek.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Excel/CSV Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                        <div class="form-text">Sadece .xlsx, .xls ve .csv dosyaları kabul edilir</div>
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

// Panodan ekleme için satır sayacı
function updateBulkStats() {
    const bulkData = document.getElementById('bulk_data');
    if (!bulkData) return;
    
    const text = bulkData.value.trim();
    const lines = text.split('\n').filter(line => line.trim() !== '');
    
    const lineCount = lines.length;
    let validCount = 0;
    
    lines.forEach(line => {
        const parts = line.split(/\t+|\s{2,}/);
        if (parts.length >= 4) {
            validCount++;
        }
    });
    
    document.getElementById('bulk_line_count').textContent = lineCount;
    document.getElementById('bulk_valid_count').textContent = validCount;
    document.getElementById('bulk_valid_count').className = validCount === lineCount && lineCount > 0 ? 'text-success' : 'text-warning';
}

// Sayfa yüklendiğinde bugünün tarihini varsayılan olarak ayarla
document.addEventListener('DOMContentLoaded', function() {
    console.log('VoIP Harcama sayfası yüklendi');
    
    const now = new Date();
    const localISOTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
    document.getElementById('voip_operator_Harcama_Tarih').value = localISOTime;
    
    // Excel modal için bugünün tarihini ayarla
    const today = now.toISOString().slice(0, 10);
    document.getElementById('excel_tarih').value = today;
    
    // Panodan ekleme modal için de tarih ayarla
    const bulkTarihInput = document.getElementById('bulk_tarih');
    if (bulkTarihInput) {
        bulkTarihInput.value = today;
    }
    
    // Panodan ekleme textarea için canlı istatistik
    const bulkDataInput = document.getElementById('bulk_data');
    if (bulkDataInput) {
        bulkDataInput.addEventListener('input', updateBulkStats);
        bulkDataInput.addEventListener('paste', function() {
            setTimeout(updateBulkStats, 100);
        });
    }
    
    console.log('Varsayılan tarihler ayarlandı');
    
    // Excel/CSV dosyası seçildiğinde dosya adından tarihi parse et
    const excelFileInput = document.getElementById('excel_file');
    const excelTarihInput = document.getElementById('excel_tarih');
    
    if (excelFileInput) {
        excelFileInput.addEventListener('change', function(e) {
            if (this.files && this.files.length > 0) {
                const fileName = this.files[0].name;
                console.log('Seçilen dosya:', fileName);
                
                // Dosya adından tarih parse et
                // Format örnekleri:
                // Customer_Summary_Report_from_2025-11-25_00_00_00_to_2025-11-26_00_00_00.csv
                // Report_2025-11-25.csv
                // data_from_2025-11-25.xlsx
                
                let parsedDate = null;
                
                // "from_YYYY-MM-DD" pattern
                let datePattern = /from_(\d{4})-(\d{2})-(\d{2})/;
                let match = fileName.match(datePattern);
                
                if (match) {
                    parsedDate = `${match[1]}-${match[2]}-${match[3]}`;
                } else {
                    // Herhangi bir "YYYY-MM-DD" pattern
                    datePattern = /(\d{4})-(\d{2})-(\d{2})/;
                    match = fileName.match(datePattern);
                    if (match) {
                        parsedDate = `${match[1]}-${match[2]}-${match[3]}`;
                    }
                }
                
                if (parsedDate) {
                    excelTarihInput.value = parsedDate;
                    console.log('Dosya adından tarih parse edildi:', parsedDate);
                    
                    // Kullanıcıya bilgi ver
                    const dateLabel = excelTarihInput.previousElementSibling;
                    if (dateLabel) {
                        dateLabel.innerHTML = 'Harcama Tarihi <span class="text-success"><i class="fas fa-check-circle"></i> Dosyadan otomatik</span> <span class="text-danger">*</span>';
                        setTimeout(() => {
                            dateLabel.innerHTML = 'Harcama Tarihi <span class="text-danger">*</span>';
                        }, 5000);
                    }
                } else {
                    console.log('Dosya adından tarih parse edilemedi, varsayılan tarih kullanılıyor');
                }
            }
        });
    }
    
    // Excel upload form için loading efekti ve validasyon
    const uploadForm = document.querySelector('#uploadExcelModal form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            console.log('Excel upload form gönderiliyor...');
            
            // Form validasyonu
            const excelFile = document.getElementById('excel_file');
            const excelTarih = document.getElementById('excel_tarih');
            
            if (!excelFile.files || excelFile.files.length === 0) {
                e.preventDefault();
                console.error('Excel dosyası seçilmedi!');
                alert('Lütfen bir Excel dosyası seçin!');
                return false;
            }
            
            if (!excelTarih.value) {
                e.preventDefault();
                console.error('Tarih seçilmedi!');
                alert('Lütfen harcama tarihini seçin!');
                return false;
            }
            
            console.log('Excel dosyası:', excelFile.files[0].name);
            console.log('Tarih:', excelTarih.value);
            console.log('Form verileri geçerli, gönderiliyor...');
            
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

// Excel'e aktar
function exportToExcel() {
    // Tabloyu al
    const table = document.querySelector('.table-responsive table');
    if (!table) {
        alert('Tablo bulunamadı!');
        return;
    }
    
    // Tablo verilerini kopyala
    const clonedTable = table.cloneNode(true);
    
    // İşlemler sütununu kaldır (son sütun)
    const headerRows = clonedTable.querySelectorAll('thead tr');
    headerRows.forEach(row => {
        const lastTh = row.querySelector('th:last-child');
        if (lastTh && lastTh.textContent.trim() === 'İşlemler') {
            lastTh.remove();
        }
    });
    
    const bodyRows = clonedTable.querySelectorAll('tbody tr');
    bodyRows.forEach(row => {
        const lastTd = row.querySelector('td:last-child');
        if (lastTd) {
            lastTd.remove();
        }
    });
    
    // HTML tablosunu Excel formatına çevir
    let html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    html += '<head>';
    html += '<meta charset="utf-8">';
    html += '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    html += '<x:Name>VoIP Harcamalar</x:Name>';
    html += '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>';
    html += '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    html += '</head>';
    html += '<body>';
    html += '<table>';
    html += clonedTable.innerHTML;
    html += '</table>';
    html += '</body>';
    html += '</html>';
    
    // Dosya adı oluştur
    const today = new Date();
    const dateStr = today.getFullYear() + '-' + 
                    String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(today.getDate()).padStart(2, '0');
    const filename = 'VoIP_Harcamalar_' + dateStr + '.xls';
    
    // Blob oluştur ve indir
    const blob = new Blob(['\ufeff', html], {
        type: 'application/vnd.ms-excel'
    });
    
    // İndirme linki oluştur
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    
    // Temizlik
    URL.revokeObjectURL(link.href);
}
</script>

<?php include '../../../includes/footer.php'; ?>