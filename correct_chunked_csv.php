<?php
header('Content-Type: application/json');

// Veritabanı bağlantısı için config dosyasını dahil et
$config = include 'config/mssql.php';

try {
    $action = $_GET['action'] ?? '';
    $filename = $_GET['file'] ?? '';
    
    if (empty($filename)) {
        throw new Exception('Dosya adı belirtilmedi');
    }
    
    $uploadDir = 'uploads/';
    $filePath = $uploadDir . $filename;
    
    // Dosya varlığını kontrol et
    if (!file_exists($filePath)) {
        throw new Exception('Dosya bulunamadı: ' . $filename);
    }
    
    if ($action === 'info') {
        // Dosya bilgilerini döndür
        $fileSize = filesize($filePath);
        
        // Toplam satır sayısını hesapla
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Dosya açılamadı');
        }
        
        $lineCount = 0;
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);
        
        // İlk satır başlık olduğu için veri satır sayısı
        $dataLines = $lineCount - 1;
        
        // Önerilen chunk boyutu (dosya boyutuna göre)
        $recommendedChunkSize = 25;
        if ($fileSize < 1024 * 1024) { // 1MB'den küçükse
            $recommendedChunkSize = 50;
        } elseif ($fileSize > 10 * 1024 * 1024) { // 10MB'den büyükse
            $recommendedChunkSize = 10;
        }
        
        echo json_encode([
            'success' => true,
            'file' => $filename,
            'file_size' => $fileSize,
            'total_lines' => $dataLines,
            'total_rows' => $dataLines,
            'recommended_chunk_size' => $recommendedChunkSize,
            'chunk_size' => $recommendedChunkSize
        ]);
        
    } elseif ($action === 'process') {
        // CSV işleme
        $offset = intval($_GET['offset'] ?? 0);
        $chunkSize = intval($_GET['chunk_size'] ?? 25);
        
        // Veritabanı bağlantısı
        $connectionString = "sqlsrv:Server={$config['host']};Database={$config['database']}";
        $pdo = new PDO($connectionString, $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Dosya açılamadı');
        }
        
        // İlk satırı atla (başlıklar)
        $headers = fgets($handle);
        
        // Offset pozisyonuna git
        $currentLine = 0;
        while ($currentLine < $offset && fgets($handle) !== false) {
            $currentLine++;
        }
        
        $processed = 0;
        $updated = 0;
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $debugInfo = [];
        
        // Chunk kadar satır işle
        while ($processed < $chunkSize && ($line = fgets($handle)) !== false) {
            try {
                $data = array_map('trim', explode(';', trim($line)));
                
                // Boş satırları atla
                if (empty(trim($line)) || count($data) < 5) {
                    $processed++;
                    continue;
                }
                
                // Tüm veriyi temizle ve validate et
                for ($i = 0; $i < count($data); $i++) {
                    $data[$i] = trim($data[$i]);
                    if ($data[$i] === '' || $data[$i] === 'null' || $data[$i] === 'NULL') {
                        $data[$i] = null;
                    }
                }
                
                // MEMO_ID kontrolü - boş olabilir, numeric olmayan değerleri null yap
                $memoIdRaw = isset($data[5]) ? $data[5] : null;
                $memoId = null;
                
                if ($memoIdRaw !== null && $memoIdRaw !== '') {
                    // Sadece numeric değerleri kabul et, diğerlerini null yap
                    if (is_numeric($memoIdRaw) && $memoIdRaw > 0) {
                        $memoId = intval($memoIdRaw);
                    } else {
                        // İptal, Tamamlandı gibi metinler için bu satırı atla
                        $debugInfo[] = "Satır " . ($offset + $processed + 1) . ": MEMO_ID non-numeric değer: '" . $memoIdRaw . "' - satır atlandı";
                        $processed++;
                        $skipped++;
                        continue;
                    }
                } else {
                    // MEMO_ID boş ise bu satırı atla (unique constraint problemini önlemek için)
                    $debugInfo[] = "Satır " . ($offset + $processed + 1) . ": MEMO_ID boş - satır atlandı";
                    $processed++;
                    $skipped++;
                    continue;
                }
                
                // Uzun metinleri kes (SQL sütun boyutlarına göre)
                $fieldLimits = [
                    29 => 100, // AKTIVE_EDILEN_SOZLESMEKMP
                    30 => 100, // AKTIVE_EDILEN_SOZLESMEDURUM
                    31 => 1000, // TALEP_TAKIP_NOTU
                    37 => 500,  // MEMO_SON_CEVAP
                    38 => 1000  // MEMO_SON_ACIKLAMA
                ];
                
                foreach ($fieldLimits as $fieldIndex => $maxLength) {
                    if (isset($data[$fieldIndex]) && $data[$fieldIndex] !== null) {
                        if (strlen($data[$fieldIndex]) > $maxLength) {
                            $originalLength = strlen($data[$fieldIndex]);
                            $data[$fieldIndex] = substr($data[$fieldIndex], 0, $maxLength);
                            $debugInfo[] = "Satır " . ($offset + $processed + 1) . ": Sütun $fieldIndex kesildi ($originalLength -> $maxLength karakter)";
                        }
                    }
                }
                
                if (true) { // Her durumda işleme devam et
                    // Tarih alanlarını özel olarak işle (indeks değerleri CSV sırasına göre)
                    $dateFields = [
                        10 => 'MEMO_KAPANIS_TARIHI',    // CSV'de 11. sütun (index 10)
                        16 => 'TALEP_GIRIS_TARIHI',     // CSV'de 17. sütun (index 16) 
                        35 => 'RANDEVU_TARIHI'          // CSV'de 36. sütun (index 35)
                    ];
                    
                    foreach ($dateFields as $dateIndex => $fieldName) {
                        if (isset($data[$dateIndex]) && $data[$dateIndex] !== null) {
                            $dateValue = trim($data[$dateIndex]);
                            if (!empty($dateValue)) {
                                // Türkçe tarih formatlarını kontrol et
                                $dateValue = str_replace('.', '-', $dateValue); // 01.01.2024 -> 01-01-2024
                                $timestamp = strtotime($dateValue);
                                if ($timestamp === false) {
                                    // Geçersiz tarih formatı - null yap
                                    $data[$dateIndex] = null;
                                    $debugInfo[] = "Tarih dönüştürme hatası: $fieldName = '$dateValue'";
                                } else {
                                    // Geçerli tarih - MSSQL formatına dönüştür
                                    $data[$dateIndex] = date('Y-m-d H:i:s', $timestamp);
                                }
                            } else {
                                $data[$dateIndex] = null;
                            }
                        }
                    }
                    
                    // Mevcut kaydı kontrol et
                    $checkSql = "SELECT COUNT(*) FROM [digiturk].[iris_rapor] WHERE [MEMO_ID] = ?";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute([$memoId]);
                    $exists = $checkStmt->fetchColumn() > 0;
                    
                    if ($exists) {
                        // Güncelleme - MEMO_ID hariç tüm alanları güncelle
                        $updateSql = "UPDATE [digiturk].[iris_rapor] SET 
                            [TALEP_ID] = ?, [TALEP_TURU] = ?, [UYDU_BASVURU_POTANSIYEL_NO] = ?, 
                            [UYDU_BASVURU_UYE_NO] = ?, [DT_MUSTERI_NO] = ?, [MEMO_KAYIT_TIPI] = ?, 
                            [MEMO_ID_TIP] = ?, [MEMO_KODU] = ?, [MEMO_KAPANIS_TARIHI] = ?, 
                            [MEMO_YONLENEN_BAYI_KODU] = ?, [MEMO_YONLENEN_BAYI_ADI] = ?, 
                            [MEMO_YONLENEN_BAYI_YONETICISI] = ?, [MEMO_YONLENEN_BAYI_BOLGE] = ?, 
                            [MEMO_YONLENEN_BAYI_TEKNIK_YNTC] = ?, [TALEP_GIRIS_TARIHI] = ?, 
                            [TALEBI_GIREN_BAYI_KODU] = ?, [TALEBI_GIREN_BAYI_ADI] = ?, 
                            [TALEBI_GIREN_PERSONEL] = ?, [TALEBI_GIREN_PERSONELNO] = ?, 
                            [TALEBI_GIREN_PERSONELKODU] = ?, [TALEBI_GIREN_PERSONEL_ALTBAYI] = ?, 
                            [TALEP_KAYNAK] = ?, [SATIS_DURUMU] = ?, [INTERNET_SUREC_DURUMU] = ?, 
                            [AKTIVE_EDILEN_UYENO] = ?, [AKTIVE_EDILEN_OUTLETNO] = ?, 
                            [AKTIVE_EDILEN_SOZLESMENO] = ?, [AKTIVE_EDILEN_SOZLESMEKMP] = ?, 
                            [AKTIVE_EDILEN_SOZLESMEDURUM] = ?, [TALEP_TAKIP_NOTU] = ?, 
                            [GUNCEL_OUTLET_DURUM] = ?, [TEYIT_DURUM] = ?, [TEYIT_ARAMA_DURUM] = ?, 
                            [RANDEVU_TARIHI] = ?, [MEMO_SON_DURUM] = ?, [MEMO_SON_CEVAP] = ?, 
                            [MEMO_SON_ACIKLAMA] = ?, [guncellenme_tarihi] = GETDATE(), [dosya_adi] = ? 
                            WHERE [MEMO_ID] = ?";
                        
                        $updateStmt = $pdo->prepare($updateSql);
                        
                        // Parametreleri doğru sırada hazırla (CSV sütun sırasına göre, MEMO_ID hariç)
                        $updateParams = [
                            $data[0],  // TALEP_ID
                            $data[1],  // TALEP_TURU
                            $data[2],  // UYDU_BASVURU_POTANSIYEL_NO
                            $data[3],  // UYDU_BASVURU_UYE_NO
                            $data[4],  // DT_MUSTERI_NO
                            $data[6],  // MEMO_KAYIT_TIPI (MEMO_ID atlandı)
                            $data[7],  // MEMO_ID_TIP
                            $data[8],  // MEMO_KODU
                            $data[10], // MEMO_KAPANIS_TARIHI
                            $data[11], // MEMO_YONLENEN_BAYI_KODU
                            $data[12], // MEMO_YONLENEN_BAYI_ADI
                            $data[13], // MEMO_YONLENEN_BAYI_YONETICISI
                            $data[14], // MEMO_YONLENEN_BAYI_BOLGE
                            $data[15], // MEMO_YONLENEN_BAYI_TEKNIK_YNTC
                            $data[16], // TALEP_GIRIS_TARIHI
                            $data[17], // TALEBI_GIREN_BAYI_KODU
                            $data[18], // TALEBI_GIREN_BAYI_ADI
                            $data[19], // TALEBI_GIREN_PERSONEL
                            $data[20], // TALEBI_GIREN_PERSONELNO
                            $data[21], // TALEBI_GIREN_PERSONELKODU
                            $data[22], // TALEBI_GIREN_PERSONEL_ALTBAYI
                            $data[23], // TALEP_KAYNAK
                            $data[24], // SATIS_DURUMU
                            $data[25], // INTERNET_SUREC_DURUMU
                            $data[26], // AKTIVE_EDILEN_UYENO
                            $data[27], // AKTIVE_EDILEN_OUTLETNO
                            $data[28], // AKTIVE_EDILEN_SOZLESMENO
                            $data[29], // AKTIVE_EDILEN_SOZLESMEKMP
                            $data[30], // AKTIVE_EDILEN_SOZLESMEDURUM
                            $data[31], // TALEP_TAKIP_NOTU
                            $data[32], // GUNCEL_OUTLET_DURUM
                            $data[33], // TEYIT_DURUM
                            $data[34], // TEYIT_ARAMA_DURUM
                            $data[35], // RANDEVU_TARIHI
                            $data[36], // MEMO_SON_DURUM
                            $data[37], // MEMO_SON_CEVAP
                            $data[38], // MEMO_SON_ACIKLAMA
                            $filename, // dosya_adi
                            $memoId    // WHERE MEMO_ID
                        ];
                        
                        $updateStmt->execute($updateParams);
                        $updated++;
                    } else {
                        // Ekleme - id alanı IDENTITY olduğu için dahil edilmez
                        $insertSql = "INSERT INTO [digiturk].[iris_rapor] (
                            [TALEP_ID], [TALEP_TURU], [UYDU_BASVURU_POTANSIYEL_NO], 
                            [UYDU_BASVURU_UYE_NO], [DT_MUSTERI_NO], [MEMO_ID], [MEMO_KAYIT_TIPI], 
                            [MEMO_ID_TIP], [MEMO_KODU], [MEMO_KAPANIS_TARIHI], 
                            [MEMO_YONLENEN_BAYI_KODU], [MEMO_YONLENEN_BAYI_ADI], 
                            [MEMO_YONLENEN_BAYI_YONETICISI], [MEMO_YONLENEN_BAYI_BOLGE], 
                            [MEMO_YONLENEN_BAYI_TEKNIK_YNTC], [TALEP_GIRIS_TARIHI], 
                            [TALEBI_GIREN_BAYI_KODU], [TALEBI_GIREN_BAYI_ADI], 
                            [TALEBI_GIREN_PERSONEL], [TALEBI_GIREN_PERSONELNO], 
                            [TALEBI_GIREN_PERSONELKODU], [TALEBI_GIREN_PERSONEL_ALTBAYI], 
                            [TALEP_KAYNAK], [SATIS_DURUMU], [INTERNET_SUREC_DURUMU], 
                            [AKTIVE_EDILEN_UYENO], [AKTIVE_EDILEN_OUTLETNO], 
                            [AKTIVE_EDILEN_SOZLESMENO], [AKTIVE_EDILEN_SOZLESMEKMP], 
                            [AKTIVE_EDILEN_SOZLESMEDURUM], [TALEP_TAKIP_NOTU], 
                            [GUNCEL_OUTLET_DURUM], [TEYIT_DURUM], [TEYIT_ARAMA_DURUM], 
                            [RANDEVU_TARIHI], [MEMO_SON_DURUM], [MEMO_SON_CEVAP], 
                            [MEMO_SON_ACIKLAMA], [eklenme_tarihi], [dosya_adi]
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,GETDATE(),?)";
                        
                        $insertStmt = $pdo->prepare($insertSql);
                        
                        // Parametreleri doğru sırada hazırla
                        $insertParams = [
                            $data[0],  // TALEP_ID
                            $data[1],  // TALEP_TURU
                            $data[2],  // UYDU_BASVURU_POTANSIYEL_NO
                            $data[3],  // UYDU_BASVURU_UYE_NO
                            $data[4],  // DT_MUSTERI_NO
                            $memoId,   // MEMO_ID (artık her zaman geçerli bir değer)
                            $data[6],  // MEMO_KAYIT_TIPI
                            $data[7],  // MEMO_ID_TIP
                            $data[8],  // MEMO_KODU
                            $data[10], // MEMO_KAPANIS_TARIHI
                            $data[11], // MEMO_YONLENEN_BAYI_KODU
                            $data[12], // MEMO_YONLENEN_BAYI_ADI
                            $data[13], // MEMO_YONLENEN_BAYI_YONETICISI
                            $data[14], // MEMO_YONLENEN_BAYI_BOLGE
                            $data[15], // MEMO_YONLENEN_BAYI_TEKNIK_YNTC
                            $data[16], // TALEP_GIRIS_TARIHI
                            $data[17], // TALEBI_GIREN_BAYI_KODU
                            $data[18], // TALEBI_GIREN_BAYI_ADI
                            $data[19], // TALEBI_GIREN_PERSONEL
                            $data[20], // TALEBI_GIREN_PERSONELNO
                            $data[21], // TALEBI_GIREN_PERSONELKODU
                            $data[22], // TALEBI_GIREN_PERSONEL_ALTBAYI
                            $data[23], // TALEP_KAYNAK
                            $data[24], // SATIS_DURUMU
                            $data[25], // INTERNET_SUREC_DURUMU
                            $data[26], // AKTIVE_EDILEN_UYENO
                            $data[27], // AKTIVE_EDILEN_OUTLETNO
                            $data[28], // AKTIVE_EDILEN_SOZLESMENO
                            $data[29], // AKTIVE_EDILEN_SOZLESMEKMP
                            $data[30], // AKTIVE_EDILEN_SOZLESMEDURUM
                            $data[31], // TALEP_TAKIP_NOTU
                            $data[32], // GUNCEL_OUTLET_DURUM
                            $data[33], // TEYIT_DURUM
                            $data[34], // TEYIT_ARAMA_DURUM
                            $data[35], // RANDEVU_TARIHI
                            $data[36], // MEMO_SON_DURUM
                            $data[37], // MEMO_SON_CEVAP
                            $data[38], // MEMO_SON_ACIKLAMA
                            $filename  // dosya_adi
                        ];
                        
                        $insertStmt->execute($insertParams);
                        $inserted++;
                    }
                }
                
            } catch (Exception $e) {
                $errors[] = "Satır " . ($offset + $processed + 1) . ": " . $e->getMessage();
                $debugInfo[] = "Hata detayı: " . $e->getFile() . ":" . $e->getLine() . " - " . $e->getMessage();
                if (isset($memoId)) {
                    $debugInfo[] = "MEMO_ID: " . $memoId;
                }
            }
            
            $processed++;
        }
        
        $nextOffset = $offset + $processed;
        $hasMore = !feof($handle);
        
        fclose($handle);
        
        echo json_encode([
            'success' => true,
            'processed' => $processed,
            'updated' => $updated,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_offset' => $nextOffset,
            'has_more' => $hasMore,
            'debug_info' => $debugInfo
        ]);
        
    } else {
        throw new Exception('Geçersiz action parametresi');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'processed' => 0,
        'updated' => 0,
        'inserted' => 0,
        'skipped' => 0,
        'errors' => [$e->getMessage()],
        'next_offset' => 0,
        'has_more' => false
    ]);
}
?>