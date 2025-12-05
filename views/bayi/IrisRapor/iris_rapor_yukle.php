<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../../auth.php';

// Kullanıcı kimlik doğrulaması
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
    // Admin için tüm yetkileri aç
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0,
        'ekle' => 1,
        'duzenle' => 1,
        'sil' => 1
    ];
} else {
    // Admin değilse normal yetki kontrolü yap
    try {
        $conn = getDatabaseConnection();
        
        // Mevcut sayfa URL'sini al
        $currentPageUrl = basename($_SERVER['PHP_SELF']);
        
        // Sayfa bilgisini ve yetkilerini çek
        $yetkiSql = "
            SELECT 
                tsy.gor,
                tsy.kendi_kullanicini_gor,
                tsy.ekle,
                tsy.duzenle,
                tsy.sil,
                tsy.durum as yetki_durum,
                ts.durum as sayfa_durum
            FROM dbo.tanim_sayfalar ts
            INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
            WHERE ts.sayfa_url = ?
            AND tsy.user_group_id = ?
            AND ts.durum = 1
            AND tsy.durum = 1
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
            
            // Görme yetkisi yoksa (0 ise) sayfaya erişimi engelle
            if ($sayfaYetkileri['gor'] == 0) {
                header('Location: ../../../index.php?error=yetki_yok');
                exit;
            }
        } else {
            // Yetki tanımı bulunamazsa erişimi engelle
            header('Location: ../../../index.php?error=yetki_tanimlanmamis');
            exit;
        }
        
    } catch (Exception $e) {
        // Hata durumunda güvenlik için erişimi engelle
        error_log("Yetki kontrol hatası: " . $e->getMessage());
        header('Location: ../../../index.php?error=sistem_hatasi');
        exit;
    }
}

class IrisMSSQLConnection {
    private $connection;
    
    public function __construct() {
        // getDatabaseConnection fonksiyonunu kullan
        $this->connection = getDatabaseConnection();
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function createIrisRaporTable() {
        $sql = "
        IF NOT EXISTS (SELECT * FROM sys.tables t JOIN sys.schemas s ON t.schema_id = s.schema_id WHERE s.name = 'digiturk' AND t.name = 'iris_rapor')
        CREATE TABLE digiturk.iris_rapor (
            id BIGINT IDENTITY(1,1) PRIMARY KEY,
            TALEP_ID NVARCHAR(50),
            TALEP_TURU NVARCHAR(100),
            UYDU_BASVURU_POTANSIYEL_NO NVARCHAR(50),
            UYDU_BASVURU_UYE_NO NVARCHAR(50),
            DT_MUSTERI_NO NVARCHAR(50),
            MEMO_ID BIGINT NULL,
            MEMO_KAYIT_TIPI NVARCHAR(50),
            MEMO_ID_TIP NVARCHAR(50),
            MEMO_KODU NVARCHAR(100),
            MEMO_KAPANIS_TARIHI DATETIME2,
            MEMO_YONLENEN_BAYI_KODU NVARCHAR(50),
            MEMO_YONLENEN_BAYI_ADI NVARCHAR(200),
            MEMO_YONLENEN_BAYI_YONETICISI NVARCHAR(200),
            MEMO_YONLENEN_BAYI_BOLGE NVARCHAR(100),
            MEMO_YONLENEN_BAYI_TEKNIK_YNTC NVARCHAR(200),
            TALEP_GIRIS_TARIHI DATETIME2,
            TALEBI_GIREN_BAYI_KODU NVARCHAR(50),
            TALEBI_GIREN_BAYI_ADI NVARCHAR(200),
            TALEBI_GIREN_PERSONEL NVARCHAR(200),
            TALEBI_GIREN_PERSONELNO NVARCHAR(50),
            TALEBI_GIREN_PERSONELKODU NVARCHAR(50),
            TALEBI_GIREN_PERSONEL_ALTBAYI NVARCHAR(200),
            TALEP_KAYNAK NVARCHAR(100),
            SATIS_DURUMU NVARCHAR(100),
            INTERNET_SUREC_DURUMU NVARCHAR(100),
            AKTIVE_EDILEN_UYENO NVARCHAR(50),
            AKTIVE_EDILEN_OUTLETNO NVARCHAR(50),
            AKTIVE_EDILEN_SOZLESMENO NVARCHAR(50),
            AKTIVE_EDILEN_SOZLESMEKMP NVARCHAR(100),
            AKTIVE_EDILEN_SOZLESMEDURUM NVARCHAR(100),
            TALEP_TAKIP_NOTU NVARCHAR(1000),
            GUNCEL_OUTLET_DURUM NVARCHAR(100),
            TEYIT_DURUM NVARCHAR(100),
            TEYIT_ARAMA_DURUM NVARCHAR(100),
            RANDEVU_TARIHI DATETIME2,
            MEMO_SON_DURUM NVARCHAR(100),
            MEMO_SON_CEVAP NVARCHAR(500),
            MEMO_SON_ACIKLAMA NVARCHAR(1000),
            eklenme_tarihi DATETIME2 NOT NULL DEFAULT GETDATE(),
            guncellenme_tarihi DATETIME2,
            dosya_adi NVARCHAR(255)
        );
        
        IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID('digiturk.iris_rapor') AND name = 'IX_iris_rapor_MEMO_ID_UNIQUE')
        BEGIN
            CREATE UNIQUE INDEX IX_iris_rapor_MEMO_ID_UNIQUE ON digiturk.iris_rapor (MEMO_ID) WHERE MEMO_ID IS NOT NULL
        END
        ";
        
        try {
            $this->connection->exec($sql);
        } catch (PDOException $e) {
            throw new Exception('Tablo oluşturma hatası: ' . $e->getMessage());
        }
    }
    
    public function truncateTable() {
        $sql = "TRUNCATE TABLE digiturk.iris_rapor";
        $this->connection->exec($sql);
    }
    
    public function convertDateFormat($dateString) {
        if (empty($dateString)) {
            return null;
        }
        
        $formats = [
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd.m.Y',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        
        return null;
    }
    
    public function insertBatchStreaming($filePath, $expectedColumns, $fileName, $batchSize = 1000) {
        ini_set('memory_limit', '512M');
        set_time_limit(1800);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('CSV dosyası açılamadı');
        }
        
        $bom = fread($handle, 4);
        if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
            fseek($handle, 3);
        } else {
            rewind($handle);
        }
        
        $headers = null;
        $currentPos = ftell($handle);
        $headers = fgetcsv($handle, 0, ';', '"', '\\');
        if (!$headers || count($headers) <= 1) {
            fseek($handle, $currentPos);
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!$headers || count($headers) <= 1) {
                fseek($handle, $currentPos);
                $headers = fgetcsv($handle, 0, "\t", '"', '\\');
            }
        }
        
        if (!$headers || count($headers) <= 1) {
            fclose($handle);
            throw new Exception('CSV başlıkları okunamadı');
        }
        
        $columnMapping = [];
        foreach ($expectedColumns as $expected) {
            foreach ($headers as $index => $header) {
                $headerUpper = strtoupper(trim($header));
                $expectedUpper = strtoupper($expected);
                
                // INTERNET_SUREC_DURUMU için hem kendisi hem BASVURU_SUREC_DURUMU kabul edilir
                if ($expectedUpper === 'INTERNET_SUREC_DURUMU') {
                    if ($headerUpper === 'INTERNET_SUREC_DURUMU' || $headerUpper === 'BASVURU_SUREC_DURUMU') {
                        $columnMapping[$expected] = $index;
                        break;
                    }
                } elseif ($headerUpper === $expectedUpper) {
                    $columnMapping[$expected] = $index;
                    break;
                }
            }
        }
        
        $this->connection->beginTransaction();
        
        // Check if MEMO_ID exists
        $checkSql = "SELECT id FROM digiturk.iris_rapor WHERE MEMO_ID = ? AND MEMO_ID IS NOT NULL";
        $checkStmt = $this->connection->prepare($checkSql);
        
        // UPDATE statement - 38 parameters (all columns except MEMO_ID) + 1 for WHERE = 39 total
        $updateSql = "UPDATE digiturk.iris_rapor SET 
            TALEP_ID = ?, TALEP_TURU = ?, UYDU_BASVURU_POTANSIYEL_NO = ?, UYDU_BASVURU_UYE_NO = ?, DT_MUSTERI_NO = ?,
            MEMO_KAYIT_TIPI = ?, MEMO_ID_TIP = ?, MEMO_KODU = ?, MEMO_KAPANIS_TARIHI = ?,
            MEMO_YONLENEN_BAYI_KODU = ?, MEMO_YONLENEN_BAYI_ADI = ?, MEMO_YONLENEN_BAYI_YONETICISI = ?,
            MEMO_YONLENEN_BAYI_BOLGE = ?, MEMO_YONLENEN_BAYI_TEKNIK_YNTC = ?, TALEP_GIRIS_TARIHI = ?,
            TALEBI_GIREN_BAYI_KODU = ?, TALEBI_GIREN_BAYI_ADI = ?, TALEBI_GIREN_PERSONEL = ?,
            TALEBI_GIREN_PERSONELNO = ?, TALEBI_GIREN_PERSONELKODU = ?, TALEBI_GIREN_PERSONEL_ALTBAYI = ?,
            TALEP_KAYNAK = ?, SATIS_DURUMU = ?, INTERNET_SUREC_DURUMU = ?, AKTIVE_EDILEN_UYENO = ?,
            AKTIVE_EDILEN_OUTLETNO = ?, AKTIVE_EDILEN_SOZLESMENO = ?, AKTIVE_EDILEN_SOZLESMEKMP = ?,
            AKTIVE_EDILEN_SOZLESMEDURUM = ?, TALEP_TAKIP_NOTU = ?, GUNCEL_OUTLET_DURUM = ?,
            TEYIT_DURUM = ?, TEYIT_ARAMA_DURUM = ?, RANDEVU_TARIHI = ?, MEMO_SON_DURUM = ?,
            MEMO_SON_CEVAP = ?, MEMO_SON_ACIKLAMA = ?, dosya_adi = ?, guncellenme_tarihi = GETDATE()
            WHERE MEMO_ID = ? AND MEMO_ID IS NOT NULL";
        $updateStmt = $this->connection->prepare($updateSql);
        
        // INSERT statement - 39 parameters (all columns including dosya_adi)
        $insertSql = "INSERT INTO digiturk.iris_rapor (
            TALEP_ID, TALEP_TURU, UYDU_BASVURU_POTANSIYEL_NO, UYDU_BASVURU_UYE_NO, DT_MUSTERI_NO,
            MEMO_ID, MEMO_KAYIT_TIPI, MEMO_ID_TIP, MEMO_KODU, MEMO_KAPANIS_TARIHI,
            MEMO_YONLENEN_BAYI_KODU, MEMO_YONLENEN_BAYI_ADI, MEMO_YONLENEN_BAYI_YONETICISI,
            MEMO_YONLENEN_BAYI_BOLGE, MEMO_YONLENEN_BAYI_TEKNIK_YNTC, TALEP_GIRIS_TARIHI,
            TALEBI_GIREN_BAYI_KODU, TALEBI_GIREN_BAYI_ADI, TALEBI_GIREN_PERSONEL,
            TALEBI_GIREN_PERSONELNO, TALEBI_GIREN_PERSONELKODU, TALEBI_GIREN_PERSONEL_ALTBAYI,
            TALEP_KAYNAK, SATIS_DURUMU, INTERNET_SUREC_DURUMU, AKTIVE_EDILEN_UYENO,
            AKTIVE_EDILEN_OUTLETNO, AKTIVE_EDILEN_SOZLESMENO, AKTIVE_EDILEN_SOZLESMEKMP,
            AKTIVE_EDILEN_SOZLESMEDURUM, TALEP_TAKIP_NOTU, GUNCEL_OUTLET_DURUM,
            TEYIT_DURUM, TEYIT_ARAMA_DURUM, RANDEVU_TARIHI, MEMO_SON_DURUM,
            MEMO_SON_CEVAP, MEMO_SON_ACIKLAMA, dosya_adi
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $insertStmt = $this->connection->prepare($insertSql);
        
        $rowCount = 0;
        $skippedRows = 0;
        $successCount = 0;
        $errorCount = 0;
        $updateCount = 0;
        $insertCount = 0;
        $errors = [];
        $batchCount = 0;
        
        $delimiter = substr_count($headers[0], ',') > substr_count($headers[0], ';') ? ',' : ';';
        
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $hasData = false;
            foreach ($row as $cell) {
                if (!empty(trim($cell))) {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                $skippedRows++;
                continue;
            }
            
            if (count($row) < count($expectedColumns)) {
                $skippedRows++;
                continue;
            }
            if (count($row) > count($expectedColumns)) {
                $row = array_slice($row, 0, count($expectedColumns));
            }
            
            $processedRow = [];
            foreach ($expectedColumns as $column) {
                $index = $columnMapping[$column] ?? null;
                $value = ($index !== null && isset($row[$index])) ? trim($row[$index]) : null;
                
                if ($value === '') {
                    $value = null;
                }
                
                if (in_array($column, ['MEMO_KAPANIS_TARIHI', 'TALEP_GIRIS_TARIHI', 'RANDEVU_TARIHI'])) {
                    $value = $this->convertDateFormat($value);
                }
                
                if ($column === 'MEMO_ID') {
                    if (!empty($value) && is_numeric($value)) {
                        $value = intval($value);
                    } else {
                        $value = null;
                    }
                }
                
                $processedRow[] = $value;
            }
            $processedRow[] = $fileName; // dosya_adi at index 38
            
            $memoId = $processedRow[5]; // MEMO_ID is at index 5
            
            try {
                if ($memoId === null) {
                    // Direct INSERT for null MEMO_ID
                    $insertStmt->execute($processedRow);
                    $insertCount++;
                    $successCount++;
                } else {
                    // Check if exists
                    $checkStmt->execute([$memoId]);
                    $existing = $checkStmt->fetch();
                    
                    if ($existing) {
                        // UPDATE - build update array without MEMO_ID
                        $updateParams = [];
                        // Add first 5 columns (before MEMO_ID)
                        for ($i = 0; $i < 5; $i++) {
                            $updateParams[] = $processedRow[$i];
                        }
                        // Add columns after MEMO_ID (from index 6 to 37)
                        for ($i = 6; $i < 38; $i++) {
                            $updateParams[] = $processedRow[$i];
                        }
                        // Add dosya_adi
                        $updateParams[] = $processedRow[38];
                        // Add MEMO_ID for WHERE clause
                        $updateParams[] = $memoId;
                        
                        $updateStmt->execute($updateParams);
                        $updateCount++;
                        $successCount++;
                    } else {
                        // INSERT new record
                        $insertStmt->execute($processedRow);
                        $insertCount++;
                        $successCount++;
                    }
                }
            } catch (PDOException $e) {
                $errorCount++;
                $errors[] = "Satır " . ($rowCount + 2) . " (MEMO_ID: " . ($memoId ?? 'NULL') . "): " . $e->getMessage();
                
                // Log the actual parameters for debugging
                if ($errorCount <= 5) {
                    error_log("Error on row " . ($rowCount + 2) . " - Parameter count: " . count($processedRow));
                    error_log("MEMO_ID: " . ($memoId ?? 'NULL'));
                }
            }
            
            $rowCount++;
            $batchCount++;
            
            if ($batchCount >= $batchSize) {
                $this->connection->commit();
                $this->connection->beginTransaction();
                $batchCount = 0;
                error_log("Processed $rowCount rows from $fileName");
            }
        }
        
        $this->connection->commit();
        fclose($handle);
        
        return [
            'success' => true,
            'successful' => $successCount,
            'errors' => $errorCount,
            'updated' => $updateCount,
            'inserted' => $insertCount,
            'totalProcessed' => $rowCount,
            'skippedRows' => $skippedRows,
            'error_details' => $errors,
            'filename' => $fileName
        ];
    }
}

$pageTitle = 'Iris Rapor Yükleme';
$breadcrumbs = [
    ['title' => 'Iris Rapor Yükleme']
];

$message = '';
$messageType = '';
$csvFiles = [];
$csvHeaders = [];
$columnMapping = [];
$uploadResult = null;

// CSV dosyalarını listele - Ana uploads klasörünü kullan
$uploadsDir = __DIR__ . '/../../../uploads';
if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir);
    $csvFilesWithTime = [];
    
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'csv') {
            $filePath = $uploadsDir . '/' . $file;
            $modTime = filemtime($filePath);
            $csvFilesWithTime[] = [
                'name' => $file,
                'time' => $modTime
            ];
        }
    }
    
    // Dosyaları oluşturulma tarihine göre yeniden eskiye doğru sırala
    usort($csvFilesWithTime, function($a, $b) {
        return $b['time'] - $a['time']; // Yeniden eskiye (descending order)
    });
    
    // Sadece dosya isimlerini al
    foreach ($csvFilesWithTime as $fileInfo) {
        $csvFiles[] = $fileInfo['name'];
    }
}

// Veritabanı tablo yapısı (beklenen sütunlar)
$expectedColumns = [
    'TALEP_ID',
    'TALEP_TURU', 
    'UYDU_BASVURU_POTANSIYEL_NO',
    'UYDU_BASVURU_UYE_NO',
    'DT_MUSTERI_NO',
    'MEMO_ID',
    'MEMO_KAYIT_TIPI',
    'MEMO_ID_TIP',
    'MEMO_KODU',
    'MEMO_KAPANIS_TARIHI',
    'MEMO_YONLENEN_BAYI_KODU',
    'MEMO_YONLENEN_BAYI_ADI',
    'MEMO_YONLENEN_BAYI_YONETICISI',
    'MEMO_YONLENEN_BAYI_BOLGE',
    'MEMO_YONLENEN_BAYI_TEKNIK_YNTC',
    'TALEP_GIRIS_TARIHI',
    'TALEBI_GIREN_BAYI_KODU',
    'TALEBI_GIREN_BAYI_ADI',
    'TALEBI_GIREN_PERSONEL',
    'TALEBI_GIREN_PERSONELNO',
    'TALEBI_GIREN_PERSONELKODU',
    'TALEBI_GIREN_PERSONEL_ALTBAYI',
    'TALEP_KAYNAK',
    'SATIS_DURUMU',
    'INTERNET_SUREC_DURUMU',
    'AKTIVE_EDILEN_UYENO',
    'AKTIVE_EDILEN_OUTLETNO',
    'AKTIVE_EDILEN_SOZLESMENO',
    'AKTIVE_EDILEN_SOZLESMEKMP',
    'AKTIVE_EDILEN_SOZLESMEDURUM',
    'TALEP_TAKIP_NOTU',
    'GUNCEL_OUTLET_DURUM',
    'TEYIT_DURUM',
    'TEYIT_ARAMA_DURUM',
    'RANDEVU_TARIHI',
    'MEMO_SON_DURUM',
    'MEMO_SON_CEVAP',
    'MEMO_SON_ACIKLAMA'
];

// AJAX istekleri için
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        if ($_POST['action'] === 'get_headers') {
            $selectedFile = $_POST['file'] ?? '';
            if (empty($selectedFile) || !in_array($selectedFile, $csvFiles)) {
                echo json_encode(['success' => false, 'error' => 'Geçersiz dosya seçimi']);
                exit;
            }
            
            $filePath = $uploadsDir . '/' . $selectedFile;
            $headers = [];
            
            try {
                $handle = fopen($filePath, 'r');
                if ($handle !== false) {
                    $bom = fread($handle, 4);
                    if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
                        fseek($handle, 3);
                    } elseif (substr($bom, 0, 2) === "\xFF\xFE" || substr($bom, 0, 2) === "\xFE\xFF") {
                        fseek($handle, 2);
                    } else {
                        rewind($handle);
                    }
                    
                    $headerLine = null;
                    $currentPos = ftell($handle);
                    
                    $headerLine = fgetcsv($handle, 0, ';', '"', '\\');
                    if (!$headerLine || count($headerLine) <= 1) {
                        fseek($handle, $currentPos);
                        $headerLine = fgetcsv($handle, 0, ',', '"', '\\');
                        if (!$headerLine || count($headerLine) <= 1) {
                            fseek($handle, $currentPos);
                            $headerLine = fgetcsv($handle, 0, "\t", '"', '\\');
                        }
                    }
                    
                    if ($headerLine && count($headerLine) > 1) {
                        $headers = array_map('trim', $headerLine);
                        
                        $matches = [];
                        $missingColumns = [];
                        $extraColumns = [];
                        
                        foreach ($expectedColumns as $expected) {
                            $found = false;
                            foreach ($headers as $index => $header) {
                                $headerUpper = strtoupper(trim($header));
                                $expectedUpper = strtoupper($expected);
                                
                                // INTERNET_SUREC_DURUMU için hem kendisi hem BASVURU_SUREC_DURUMU kabul edilir
                                if ($expectedUpper === 'INTERNET_SUREC_DURUMU') {
                                    if ($headerUpper === 'INTERNET_SUREC_DURUMU' || $headerUpper === 'BASVURU_SUREC_DURUMU') {
                                        $matches[$expected] = $index;
                                        $found = true;
                                        break;
                                    }
                                } elseif ($headerUpper === $expectedUpper) {
                                    $matches[$expected] = $index;
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $missingColumns[] = $expected;
                            }
                        }
                        
                        foreach ($headers as $header) {
                            $found = false;
                            $headerUpper = strtoupper(trim($header));
                            foreach ($expectedColumns as $expected) {
                                $expectedUpper = strtoupper($expected);
                                
                                // BASVURU_SUREC_DURUMU, INTERNET_SUREC_DURUMU ile eşleşiyor sayılır
                                if ($expectedUpper === 'INTERNET_SUREC_DURUMU' && $headerUpper === 'BASVURU_SUREC_DURUMU') {
                                    $found = true;
                                    break;
                                }
                                
                                if ($headerUpper === $expectedUpper) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found && !empty(trim($header))) {
                                $extraColumns[] = $header;
                            }
                        }
                        
                        $isCompatible = empty($missingColumns);
                        
                        echo json_encode([
                            'success' => true,
                            'headers' => $headers,
                            'expectedColumns' => $expectedColumns,
                            'matches' => $matches,
                            'missingColumns' => $missingColumns,
                            'extraColumns' => $extraColumns,
                            'isCompatible' => $isCompatible,
                            'totalRows' => countCsvRows($filePath) - 1
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'CSV başlıkları okunamadı']);
                    }
                    fclose($handle);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Dosya açılamadı']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Dosya okuma hatası: ' . $e->getMessage()]);
            }
            exit;
        }
        
        if ($_POST['action'] === 'upload') {
            // Ekleme yetkisi kontrolü
            if ($sayfaYetkileri['ekle'] != 1) {
                echo json_encode(['success' => false, 'error' => 'Rapor yükleme yetkiniz bulunmamaktadır.']);
                exit;
            }
            
            $selectedFile = $_POST['file'] ?? '';
            $truncate = isset($_POST['truncate']) && $_POST['truncate'] === '1';
            
            if (empty($selectedFile) || !in_array($selectedFile, $csvFiles)) {
                echo json_encode(['success' => false, 'error' => 'Geçersiz dosya seçimi']);
                exit;
            }
            
            try {
                $db = new IrisMSSQLConnection();
                $db->createIrisRaporTable();
                
                if ($truncate) {
                    $db->truncateTable();
                }
                
                $result = $db->insertBatchStreaming($uploadsDir . '/' . $selectedFile, $expectedColumns, $selectedFile);
                echo json_encode($result);
            } catch (Exception $e) {
                $errorDetails = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
                error_log("Upload hatası: " . json_encode($errorDetails));
                echo json_encode($errorDetails);
            }
            exit;
        }
    }
}

function countCsvRows($filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) return 0;
    
    $bom = fread($handle, 4);
    if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
        fseek($handle, 3);
    } else {
        rewind($handle);
    }
    
    $currentPos = ftell($handle);
    $testLine = fgets($handle);
    $delimiter = ';';
    if (substr_count($testLine, ';') < substr_count($testLine, ',')) {
        $delimiter = ',';
    }
    fseek($handle, $currentPos);
    
    $count = 0;
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $count++;
    }
    
    fclose($handle);
    return $count;
}

include '../../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="card-title mb-0">
                    <i class="fas fa-upload me-2"></i>
                    Iris Rapor Yükleme
                </h4>
            </div>
            <div class="card-body">
                <?php if ($sayfaYetkileri['ekle'] != 1): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Rapor yükleme yetkiniz bulunmamaktadır.
                    </div>
                <?php elseif (empty($csvFiles)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Uploads klasöründe CSV dosyası bulunamadı.
                    </div>
                <?php else: ?>
                    <form id="uploadForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="csvFile" class="form-label">CSV Dosyası Seçin</label>
                                    <select class="form-select" id="csvFile" name="csvFile" required>
                                        <option value="">-- Dosya Seçin --</option>
                                        <?php foreach ($csvFiles as $file): ?>
                                            <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Yükleme Seçenekleri</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="truncateTable" name="truncateTable" value="1">
                                        <label class="form-check-label text-danger" for="truncateTable">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            Mevcut verileri temizle (Dikkat: Tüm veriler silinir!)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="fileAnalysis" class="mb-4" style="display: none;">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">
                                        <i class="fas fa-analytics me-2"></i>
                                        Dosya Analizi
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div id="analysisContent">
                                        <!-- Analysis content will be loaded here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="button" id="analyzeBtn" class="btn btn-info" disabled>
                                <i class="fas fa-search me-2"></i>
                                Dosyayı Analiz Et
                            </button>
                            <button type="submit" id="uploadBtn" class="btn btn-success" disabled>
                                <i class="fas fa-upload me-2"></i>
                                Yüklemeyi Başlat
                            </button>
                        </div>
                    </form>
                    
                    <div id="progressContainer" class="mt-4" style="display: none;">
                        <div class="card">
                            <div class="card-body">
                                <h6>Yükleme Durumu:</h6>
                                <div class="progress mb-3">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" style="width: 0%"></div>
                                </div>
                                <div id="progressText">Hazırlanıyor...</div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="resultContainer" class="mt-4" style="display: none;">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Yükleme Sonucu
                                </h6>
                            </div>
                            <div class="card-body">
                                <div id="resultContent">
                                    <!-- Result content will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let analysisData = null;
    
    // Dosya seçimi değiştiğinde
    $('#csvFile').change(function() {
        const selectedFile = $(this).val();
        if (selectedFile) {
            $('#analyzeBtn').prop('disabled', false);
            $('#fileAnalysis').hide();
            $('#uploadBtn').prop('disabled', true);
            analysisData = null;
        } else {
            $('#analyzeBtn').prop('disabled', true);
            $('#fileAnalysis').hide();
            $('#uploadBtn').prop('disabled', true);
        }
    });
    
    // Analiz butonu
    $('#analyzeBtn').click(function() {
        const selectedFile = $('#csvFile').val();
        if (!selectedFile) return;
        
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Analiz Ediliyor...');
        
        $.post('', {
            action: 'get_headers',
            file: selectedFile
        })
        .done(function(response) {
            if (response.success) {
                analysisData = response;
                displayAnalysis(response);
                $('#fileAnalysis').show();
                $('#uploadBtn').prop('disabled', !response.isCompatible);
            } else {
                alert('Hata: ' + response.error);
            }
        })
        .fail(function() {
            alert('Sunucu hatası oluştu');
        })
        .always(function() {
            $('#analyzeBtn').prop('disabled', false).html('<i class="fas fa-search me-2"></i>Dosyayı Analiz Et');
        });
    });
    
    // Upload form
    $('#uploadForm').submit(function(e) {
        e.preventDefault();
        
        if (!analysisData || !analysisData.isCompatible) {
            alert('Önce dosya analizi yapın ve uyumluluğu kontrol edin');
            return;
        }
        
        const truncate = $('#truncateTable').is(':checked');
        if (truncate) {
            if (!confirm('UYARI: Bu işlem mevcut tüm Iris rapor verilerini silecektir. Devam etmek istediğinizden emin misiniz?')) {
                return;
            }
        }
        
        // Büyük dosya için ek uyarı
        if (analysisData.totalRows > 20000) {
            if (!confirm(`Bu dosyada ${analysisData.totalRows.toLocaleString()} kayıt bulunuyor. Yükleme işlemi 10-15 dakika sürebilir ve sayfayı kapatmamanız gerekir. Devam etmek istiyor musunuz?`)) {
                return;
            }
        }
        
        startUpload();
    });
    
    function displayAnalysis(data) {
        let html = `
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="display-6 text-primary">${data.headers.length}</div>
                        <div class="text-muted">CSV Sütun Sayısı</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="display-6 text-success">${Object.keys(data.matches).length}</div>
                        <div class="text-muted">Eşleşen Sütun</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="display-6 text-info">${data.totalRows || 'N/A'}</div>
                        <div class="text-muted">Toplam Kayıt</div>
                    </div>
                </div>
            </div>
            <hr>
        `;
        
        if (data.isCompatible) {
            html += `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Uyumlu!</strong> CSV dosyası veritabanı yapısıyla uyumlu. Yükleme işlemi başlatılabilir.
                </div>
            `;
            
            // Büyük dosya uyarısı
            if (data.totalRows > 10000) {
                html += `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Büyük Dosya:</strong> ${data.totalRows.toLocaleString()} kayıt tespit edildi. 
                        Yükleme işlemi 10-15 dakika sürebilir. Lütfen sayfayı kapatmayın.
                    </div>
                `;
            }
        } else {
            html += `
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>Uyumsuz!</strong> CSV dosyasında bazı zorunlu sütunlar eksik.
                </div>
            `;
        }
        
        if (data.missingColumns.length > 0) {
            html += `
                <div class="alert alert-warning">
                    <strong>Eksik Sütunlar:</strong><br>
                    ${data.missingColumns.map(col => `<span class="badge bg-warning text-dark me-1">${col}</span>`).join('')}
                </div>
            `;
        }
        
        if (data.extraColumns.length > 0) {
            html += `
                <div class="alert alert-info">
                    <strong>Fazladan Sütunlar (göz ardı edilecek):</strong><br>
                    ${data.extraColumns.map(col => `<span class="badge bg-info me-1">${col}</span>`).join('')}
                </div>
            `;
        }
        
        // Sütun eşleştirmesi tablosu
        html += `
            <div class="table-responsive mt-3">
                <table class="table table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Beklenen Sütun</th>
                            <th>CSV Sütunu</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        data.expectedColumns.forEach(function(expected) {
            const csvColumn = data.headers[data.matches[expected]] || 'Bulunamadı';
            const status = data.matches[expected] !== undefined ? 
                '<span class="badge bg-success">✓ Eşleşti</span>' : 
                '<span class="badge bg-danger">✗ Eksik</span>';
            
            html += `
                <tr>
                    <td><code>${expected}</code></td>
                    <td>${csvColumn !== 'Bulunamadı' ? csvColumn : '<em>Bulunamadı</em>'}</td>
                    <td>${status}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        $('#analysisContent').html(html);
    }
    
    function startUpload() {
        $('#uploadBtn').prop('disabled', true);
        $('#progressContainer').show();
        $('#resultContainer').hide();
        
        updateProgress(0, 'Yükleme başlatılıyor...');
        
        const selectedFile = $('#csvFile').val();
        const truncate = $('#truncateTable').is(':checked') ? '1' : '0';
        
        // Büyük dosyalar için progress simulation
        let progressInterval;
        let currentProgress = 0;
        
        if (analysisData && analysisData.totalRows > 5000) {
            progressInterval = setInterval(function() {
                if (currentProgress < 90) {
                    currentProgress += Math.random() * 3;
                    updateProgress(Math.min(currentProgress, 90), 'Veriler işleniyor... (' + Math.floor(currentProgress) + '%)');
                }
            }, 1000);
        }
        
        $.post('', {
            action: 'upload',
            file: selectedFile,
            truncate: truncate
        })
        .done(function(response) {
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            updateProgress(100, 'Tamamlandı!');
            
            setTimeout(function() {
                $('#progressContainer').hide();
                displayResult(response);
            }, 1000);
        })
        .fail(function(xhr, status, error) {
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            
            let errorMessage = 'Hata oluştu!';
            try {
                if (xhr.responseText) {
                    const response = JSON.parse(xhr.responseText);
                    errorMessage = response.error || errorMessage;
                    
                    // Detaylı hata bilgisi konsola yazdır
                    console.error('Upload Error Details:', response);
                }
            } catch (e) {
                errorMessage = xhr.responseText || error || 'Bilinmeyen hata';
            }
            
            updateProgress(100, errorMessage);
            $('#progressBar').removeClass('bg-success').addClass('bg-danger');
        })
        .always(function() {
            $('#uploadBtn').prop('disabled', false);
        });
    }
    
    function updateProgress(percent, text) {
        $('#progressBar').css('width', percent + '%');
        $('#progressText').text(text);
        
        if (percent === 100) {
            $('#progressBar').removeClass('progress-bar-animated');
        }
    }
    
    function displayResult(result) {
        let html = '';
        
        if (result.success) {
            html = `
                <div class="row text-center mb-3">
                    <div class="col-md-2">
                        <div class="display-6 text-primary">${result.totalProcessed || 0}</div>
                        <div class="text-muted">İşlenen Kayıt</div>
                    </div>
                    <div class="col-md-2">
                        <div class="display-6 text-success">${result.inserted || 0}</div>
                        <div class="text-muted">Eklenen</div>
                    </div>
                    <div class="col-md-2">
                        <div class="display-6 text-warning">${result.updated || 0}</div>
                        <div class="text-muted">Güncellenen</div>
                    </div>
                    <div class="col-md-2">
                        <div class="display-6 text-danger">${result.errors || 0}</div>
                        <div class="text-muted">Hata</div>
                    </div>
                    <div class="col-md-2">
                        <div class="display-6 text-info">${result.skippedRows || 0}</div>
                        <div class="text-muted">Atlanan</div>
                    </div>
                    <div class="col-md-2">
                        <div class="display-6 text-secondary">${result.totalProcessed || 0}</div>
                        <div class="text-muted">Toplam</div>
                    </div>
                </div>
                
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Başarılı!</strong> <code>${result.filename}</code> dosyası başarıyla işlendi.
                </div>
            `;
            
            if (result.error_details && result.error_details.length > 0) {
                html += `
                    <div class="alert alert-warning">
                        <strong>Hatalar:</strong>
                        <ul class="mb-0 mt-2">
                            ${result.error_details.slice(0, 10).map(error => `<li><small>${error}</small></li>`).join('')}
                        </ul>
                        ${result.error_details.length > 10 ? `<small class="text-muted">... ve ${result.error_details.length - 10} hata daha</small>` : ''}
                    </div>
                `;
            }
        } else {
            html = `
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i>
                    <strong>Hata!</strong> ${result.error}
                </div>
            `;
        }
        
        $('#resultContent').html(html);
        $('#resultContainer').show();
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>
