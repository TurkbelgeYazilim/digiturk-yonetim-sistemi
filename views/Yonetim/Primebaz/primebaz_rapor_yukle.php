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

class PrimebazMSSQLConnection {
    private $connection;
    
    public function __construct() {
        // getDatabaseConnection fonksiyonunu kullan
        $this->connection = getDatabaseConnection();
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function createPrimebazRaporTable() {
        $sql = "
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='primebaz_rapor' AND xtype='U')
        CREATE TABLE primebaz_rapor (
            id INT IDENTITY(1,1) PRIMARY KEY,
            prim_detay NVARCHAR(400) NULL,
            prim_donem NVARCHAR(100) NULL,
            memo_acan_personel_kimlik NVARCHAR(100) NULL,
            bolge NVARCHAR(200) NULL,
            bayi_yoneticisi NVARCHAR(200) NULL,
            bayi_tip NVARCHAR(100) NULL,
            bayi_kodu INT NULL,
            bayi_adi NVARCHAR(300) NULL,
            sozlesme_no NVARCHAR(100) NULL,
            account_no INT NULL,
            outlet_no INT NULL,
            potansiyel_no INT NULL,
            kampanya_kodu NVARCHAR(200) NULL,
            sozlesme_durumu INT NULL,
            uye_durumu NVARCHAR(200) NULL,
            odeme_durum INT NULL,
            odeme_tarihi DATE NULL,
            prim_ust_urun_grubu NVARCHAR(200) NULL,
            basic_basari_skala NVARCHAR(40) NULL,
            cinema_basari_skala NVARCHAR(40) NULL,
            sport_basari_skala NVARCHAR(40) NULL,
            genel_basari_skala NVARCHAR(40) NULL,
            isp_basari_skala NVARCHAR(40) NULL,
            uyelik_turu NVARCHAR(100) NULL,
            kurulum_ziyaret_tipi NVARCHAR(200) NULL,
            kurulum_tipi_aciklama NVARCHAR(400) NULL,
            eski_ref_prim DECIMAL(12,2) NULL,
            odenecek_prim DECIMAL(12,2) NULL,
            mahsup_tutari_odeme_durumu DECIMAL(12,2) NULL,
            yonlendirme NVARCHAR(300) NULL,
            prim_durum_aciklama NVARCHAR(1000) NULL,
            dosya_adi NVARCHAR(510) NULL,
            yuklenme_tarihi DATETIME NOT NULL DEFAULT GETDATE()
        );
        ";
        
        try {
            $this->connection->exec($sql);
        } catch (PDOException $e) {
            throw new Exception('Tablo oluşturma hatası: ' . $e->getMessage());
        }
    }
    
    public function truncateTable() {
        $sql = "TRUNCATE TABLE primebaz_rapor";
        $this->connection->exec($sql);
    }
    
    public function convertDateFormat($dateString) {
        if (empty($dateString)) {
            return null;
        }
        
        // Excel'den gelen tarih formatları
        $formats = [
            'd.m.Y',
            'd/m/Y',
            'm/d/Y',
            'Y-m-d',
            'd-m-Y',
            'Y/m/d',
            'd.m.Y H:i:s',
            'd/m/Y H:i:s',
            'Y-m-d H:i:s'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // Excel serial date kontrolü (örn: 44927)
        if (is_numeric($dateString) && $dateString > 1 && $dateString < 100000) {
            try {
                $unixTimestamp = ($dateString - 25569) * 86400;
                $date = new DateTime('@' . $unixTimestamp);
                return $date->format('Y-m-d');
            } catch (Exception $e) {
                return null;
            }
        }
        
        return null;
    }
    
    public function convertDecimal($value) {
        if (empty($value) || !is_numeric($value)) {
            return null;
        }
        return floatval($value);
    }
    
    public function convertInteger($value) {
        if (empty($value) || !is_numeric($value)) {
            return null;
        }
        return intval($value);
    }
    
    public function readExcelAsXML($excelFilePath) {
        // .xlsx dosyalarını ZIP olarak aç ve XML'den oku
        if (pathinfo($excelFilePath, PATHINFO_EXTENSION) !== 'xlsx') {
            throw new Exception('Sadece .xlsx formatı destekleniyor. Lütfen dosyayı .xlsx formatında kaydedin.');
        }
        
        if (!file_exists($excelFilePath)) {
            throw new Exception('Excel dosyası bulunamadı: ' . $excelFilePath);
        }
        
        if (!is_readable($excelFilePath)) {
            throw new Exception('Excel dosyası okunamıyor: ' . $excelFilePath);
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($excelFilePath);
        if ($result !== TRUE) {
            $errorMessages = [
                ZipArchive::ER_OK => 'No error',
                ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
                ZipArchive::ER_RENAME => 'Renaming temporary file failed',
                ZipArchive::ER_CLOSE => 'Closing zip archive failed',
                ZipArchive::ER_SEEK => 'Seek error',
                ZipArchive::ER_READ => 'Read error',
                ZipArchive::ER_WRITE => 'Write error',
                ZipArchive::ER_CRC => 'CRC error',
                ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
                ZipArchive::ER_NOENT => 'No such file',
                ZipArchive::ER_EXISTS => 'File already exists',
                ZipArchive::ER_OPEN => 'Can not open file',
                ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
                ZipArchive::ER_ZLIB => 'Zlib error',
                ZipArchive::ER_MEMORY => 'Memory allocation failure',
                ZipArchive::ER_CHANGED => 'Entry has been changed',
                ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
                ZipArchive::ER_EOF => 'Premature EOF',
                ZipArchive::ER_INVAL => 'Invalid argument',
                ZipArchive::ER_NOZIP => 'Not a zip archive',
                ZipArchive::ER_INTERNAL => 'Internal error',
                ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                ZipArchive::ER_REMOVE => 'Can not remove file',
                ZipArchive::ER_DELETED => 'Entry has been deleted'
            ];
            
            $errorMsg = isset($errorMessages[$result]) ? $errorMessages[$result] : 'Unknown error';
            throw new Exception('Excel dosyası açılamadı. Hata kodu: ' . $result . ' (' . $errorMsg . '). Dosya bozuk olabilir.');
        }
        
        // Shared strings oku
        $sharedStrings = [];
        $sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXML) {
            $xml = @simplexml_load_string($sharedStringsXML);
            if ($xml === false) {
                error_log('PRIMEBAZ: SharedStrings XML parse hatası');
            } else {
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }
        }
        
        // İlk worksheet'i oku
        $worksheetXML = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$worksheetXML) {
            // Alternatif worksheet isimlerini dene
            $worksheetXML = $zip->getFromName('xl/worksheets/sheet.xml');
            if (!$worksheetXML) {
                throw new Exception('Worksheet bulunamadı. Dosyada geçerli bir çalışma sayfası yok.');
            }
        }
        
        $xml = @simplexml_load_string($worksheetXML);
        if ($xml === false) {
            throw new Exception('Worksheet XML parse edilemedi. Dosya bozuk olabilir.');
        }
        
        if (!isset($xml->sheetData)) {
            throw new Exception('Worksheet\'te veri bulunamadı.');
        }
        $rows = [];
        $rowIndex = 0;
        
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $cellIndex = 0;
            $rowIndex++;
            
            try {
                foreach ($row->c as $cell) {
                    $cellValue = '';
                    
                    try {
                        // Cell type kontrolü
                        $cellType = (string)$cell['t'];
                        if ($cellType === 's') {
                            // Shared string
                            $stringIndex = (int)$cell->v;
                            $cellValue = isset($sharedStrings[$stringIndex]) ? $sharedStrings[$stringIndex] : '';
                        } else {
                            // Normal değer
                            $cellValue = (string)$cell->v;
                        }
                    } catch (Exception $e) {
                        error_log("PRIMEBAZ: Cell okuma hatası - Row $rowIndex, Cell $cellIndex: " . $e->getMessage());
                        $cellValue = ''; // Hatalı cell'i boş bırak
                    }
                    
                    $rowData[] = $cellValue;
                    $cellIndex++;
                }
            } catch (Exception $e) {
                error_log("PRIMEBAZ: Row okuma hatası - Row $rowIndex: " . $e->getMessage());
                // Hatalı satırı atla
                continue;
            }
            
            $rows[] = $rowData;
        }
        
        $zip->close();
        return $rows;
    }
    
    public function convertExcelToCsv($excelFilePath) {
        $csvFilePath = str_replace(['.xlsx', '.xls'], '.csv', $excelFilePath);
        
        // Eğer aynı isimde CSV dosyası varsa onu kullan
        if (file_exists($csvFilePath)) {
            return $csvFilePath;
        }
        
        try {
            // .xlsx dosyalarını XML olarak oku
            if (pathinfo($excelFilePath, PATHINFO_EXTENSION) === 'xlsx') {
                $rows = $this->readExcelAsXML($excelFilePath);
                
                // CSV dosyası oluştur
                $handle = fopen($csvFilePath, 'w');
                if (!$handle) {
                    throw new Exception('CSV dosyası oluşturulamadı');
                }
                
                foreach ($rows as $row) {
                    fputcsv($handle, $row, ';');
                }
                
                fclose($handle);
                return $csvFilePath;
            } else {
                throw new Exception('.xls formatı desteklenmiyor. Lütfen dosyayı .xlsx formatında kaydedin.');
            }
            
        } catch (Exception $e) {
            throw new Exception('Excel dosyası okunamadı: ' . $e->getMessage() . '. Lütfen dosyayı Excel\'de açıp CSV formatında (".csv") kaydedin.');
        }
    }
    
    public function processExcelFile($filePath, $fileName, $batchSize = 1000) {
        // Dosya türüne göre işlem yap
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($extension === 'csv') {
            $csvFilePath = $filePath; // Zaten CSV
        } else {
            // Excel dosyasını CSV'ye dönüştür
            $csvFilePath = $this->convertExcelToCsv($filePath);
        }
        
        try {
            $handle = fopen($csvFilePath, 'r');
            if (!$handle) {
                throw new Exception('Dönüştürülen CSV dosyası açılamadı');
            }
            
            // BOM kontrolü
            $bom = fread($handle, 4);
            if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
                fseek($handle, 3);
            } else {
                rewind($handle);
            }
            
            // Başlık satırını oku
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
            
            $headers = array_map('trim', $headers);
            
            // Sütun eşleştirmesi (büyük/küçük harf duyarsız)
            // Internal column names (veritabanı sütunları)
            $expectedColumns = [
                'prim_detay', 'prim_donem', 'memo_acan_personel_kimlik', 'bolge', 'bayi_yoneticisi',
                'bayi_tip', 'bayi_kodu', 'bayi_adi', 'sozlesme_no', 'account_no', 'outlet_no',
                'potansiyel_no', 'kampanya_kodu', 'sozlesme_durumu', 'uye_durumu', 'odeme_durum',
                'odeme_tarihi', 'prim_ust_urun_grubu', 'basic_basari_skala', 'cinema_basari_skala',
                'sport_basari_skala', 'genel_basari_skala', 'isp_basari_skala', 'uyelik_turu',
                'kurulum_ziyaret_tipi', 'kurulum_tipi_aciklama', 'eski_ref_prim', 'odenecek_prim',
                'mahsup_tutari_odeme_durumu', 'yonlendirme', 'prim_durum_aciklama'
            ];
            
            // Display names (kullanıcıya gösterilen başlıklar)
            $expectedDisplayColumns = [
                'PRİM DETAY', 'PRİM DÖNEM', 'MEMO_ACAN_PERSONEL_KIMLIK', 'BÖLGE', 'BAYİ YÖNETİCİSİ',
                'BAYİ TİP', 'BAYİ KODU', 'BAYİ ADI', 'SOZLESME_NO', 'ACCOUNT_NO', 'OUTLET NO',
                'POTANSİYEL_NO', 'KAMPANYA KODU', 'SÖZLEŞME DURUMU', 'ÜYE DURUMU', 'ODEME_DURUM',
                'ODEME_TARIHI', 'PRIM_UST_URUN_GRUBU', 'BASIC_BASARI_SKALA', 'CINEMA_BASARI_SKALA',
                'SPORT_BASARI_SKALA', 'GENEL_BASARI_SKALA', 'ISP_BASARI_SKALA', 'UYELIK_TURU',
                'KURULUM_ZIYARET_TIPI', 'KURULUM_TIPI_ACIKLAMA', 'ESKI_REF_PRIM', 'ODENECEK_PRIM',
                'MAHSUP TUTARI-ÖDEME DURUMU', 'Yönlendirme', 'PRİM DURUM AÇIKLAMA'
            ];
            
            $columnMapping = [];
            foreach ($expectedColumns as $i => $expected) {
                $displayName = $expectedDisplayColumns[$i];
                foreach ($headers as $index => $header) {
                    $headerClean = strtolower(trim($header));
                    // Hem internal hem display names ile eşleştir
                    if ($headerClean === strtolower($expected) || $headerClean === strtolower($displayName)) {
                        $columnMapping[$expected] = $index;
                        break;
                    }
                }
            }
            
            // Delimiter tespiti
            $delimiter = ';';
            $currentPos = ftell($handle);
            $testLine = fgets($handle);
            if (substr_count($testLine, ';') < substr_count($testLine, ',')) {
                $delimiter = ',';
            }
            fseek($handle, $currentPos);
            
            // INSERT statement hazırla
            $insertSql = "INSERT INTO primebaz_rapor (
                prim_detay, prim_donem, memo_acan_personel_kimlik, bolge, bayi_yoneticisi,
                bayi_tip, bayi_kodu, bayi_adi, sozlesme_no, account_no, outlet_no,
                potansiyel_no, kampanya_kodu, sozlesme_durumu, uye_durumu, odeme_durum,
                odeme_tarihi, prim_ust_urun_grubu, basic_basari_skala, cinema_basari_skala,
                sport_basari_skala, genel_basari_skala, isp_basari_skala, uyelik_turu,
                kurulum_ziyaret_tipi, kurulum_tipi_aciklama, eski_ref_prim, odenecek_prim,
                mahsup_tutari_odeme_durumu, yonlendirme, prim_durum_aciklama, dosya_adi
            ) VALUES (" . str_repeat('?,', 31) . "?)";
            
            $insertStmt = $this->connection->prepare($insertSql);
            
            $this->connection->beginTransaction();
            
            $successCount = 0;
            $errorCount = 0;
            $skippedRows = 0;
            $errors = [];
            $batchCount = 0;
            $rowCount = 0;
            
            // Satır satır işle
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowCount++;
                
                // Boş satırları atla
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
                
                // Sütun sayısı kontrolü - Eksik sütunlar olsa bile işleme devam et
                // Sadece fazla sütunları kırp
                if (count($row) > count($expectedColumns)) {
                    $row = array_slice($row, 0, count($expectedColumns));
                }
                
                // Verileri hazırla
                $processedRow = [];
                foreach ($expectedColumns as $column) {
                    $index = $columnMapping[$column] ?? null;
                    $value = ($index !== null && isset($row[$index])) ? trim($row[$index]) : null;
                    
                    if ($value === '') {
                        $value = null;
                    }
                    
                    // Veri tipine göre dönüşüm
                    if ($column === 'odeme_tarihi') {
                        $value = $this->convertDateFormat($value);
                    } elseif (in_array($column, ['bayi_kodu', 'account_no', 'outlet_no', 'potansiyel_no', 'sozlesme_durumu', 'odeme_durum'])) {
                        $value = $this->convertInteger($value);
                    } elseif (in_array($column, ['eski_ref_prim', 'odenecek_prim', 'mahsup_tutari_odeme_durumu'])) {
                        $value = $this->convertDecimal($value);
                    }
                    
                    $processedRow[] = $value;
                }
                $processedRow[] = $fileName; // dosya_adi
                
                try {
                    $insertStmt->execute($processedRow);
                    $successCount++;
                } catch (PDOException $e) {
                    $errorCount++;
                    $errorDetails = [
                        'row' => $rowCount + 1,
                        'data' => $processedRow,
                        'error' => $e->getMessage(),
                        'code' => $e->getCode()
                    ];
                    $errors[] = "Satır " . ($rowCount + 1) . ": " . $e->getMessage();
                    
                    // Detaylı hata loglama
                    error_log("PRIMEBAZ UPLOAD ERROR - Row " . ($rowCount + 1) . ": " . $e->getMessage());
                    error_log("PRIMEBAZ UPLOAD ERROR - Data: " . json_encode($processedRow));
                    
                    if ($errorCount <= 5) {
                        error_log("Error on row " . ($rowCount + 1) . ": " . $e->getMessage());
                    }
                    
                    // İlk 10 hatada işlemi durdur
                    if ($errorCount >= 10) {
                        throw new Exception("Fazla hata oluştu. İşlem durduruldu. Son hata: " . $e->getMessage());
                    }
                }
                
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
            
            // Geçici CSV dosyasını sil (sadece Excel'den dönüştürülmüşse)
            if ($extension !== 'csv' && $csvFilePath !== $filePath && file_exists($csvFilePath)) {
                unlink($csvFilePath);
            }
            
            return [
                'success' => true,
                'successful' => $successCount,
                'errors' => $errorCount,
                'totalProcessed' => $successCount + $errorCount,
                'skippedRows' => $skippedRows,
                'error_details' => $errors,
                'filename' => $fileName
            ];
            
        } catch (Exception $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            fclose($handle);
            throw new Exception('CSV dosyası işleme hatası: ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Primebaz Rapor Yükleme';
$breadcrumbs = [
    ['title' => 'Primebaz Rapor Yükleme']
];

$message = '';
$messageType = '';
$excelFiles = [];
$uploadResult = null;

// Excel ve CSV dosyalarını listele
$uploadsDir = __DIR__ . '/uploads/primebaz';
if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir);
    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls', 'csv'])) {
            $excelFiles[] = $file;
        }
    }
}

// AJAX istekleri için
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        if ($_POST['action'] === 'get_file_info') {
            $selectedFile = $_POST['file'] ?? '';
            if (empty($selectedFile) || !in_array($selectedFile, $excelFiles)) {
                echo json_encode(['success' => false, 'error' => 'Geçersiz dosya seçimi']);
                exit;
            }
            
            $filePath = $uploadsDir . '/' . $selectedFile;
            
            try {
                // Dosya türüne göre işlem yap
                $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                
                if ($extension === 'csv') {
                    $csvFilePath = $filePath; // Zaten CSV
                } else {
                    // Excel dosyasını CSV'ye dönüştür
                    $db = new PrimebazMSSQLConnection();
                    $csvFilePath = $db->convertExcelToCsv($filePath);
                }
                
                $handle = fopen($csvFilePath, 'r');
                if (!$handle) {
                    throw new Exception('CSV dosyası açılamadı');
                }
                
                // BOM kontrolü
                $bom = fread($handle, 4);
                if (substr($bom, 0, 3) === "\xEF\xBB\xBF") {
                    fseek($handle, 3);
                } else {
                    rewind($handle);
                }
                
                // Başlık satırını oku
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
                
                $headers = array_map('trim', $headers);
                
                // Toplam satır sayısını hesapla
                $totalRows = 0;
                $delimiter = ';';
                $testLine = fgets($handle);
                if (substr_count($testLine, ';') < substr_count($testLine, ',')) {
                    $delimiter = ',';
                }
                rewind($handle);
                fgetcsv($handle, 0, $delimiter); // Skip header
                
                while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                    $hasData = false;
                    foreach ($row as $cell) {
                        if (!empty(trim($cell))) {
                            $hasData = true;
                            break;
                        }
                    }
                    if ($hasData) {
                        $totalRows++;
                    }
                }
                
                fclose($handle);
                
                // Geçici CSV dosyasını temizle (sadece Excel'den dönüştürülmüşse)
                if ($extension !== 'csv' && $csvFilePath !== $filePath && file_exists($csvFilePath)) {
                    unlink($csvFilePath);
                }
                
                // Beklenen sütunlarla karşılaştır
                // Internal column names (veritabanı sütunları)
                $expectedColumns = [
                    'prim_detay', 'prim_donem', 'memo_acan_personel_kimlik', 'bolge', 'bayi_yoneticisi',
                    'bayi_tip', 'bayi_kodu', 'bayi_adi', 'sozlesme_no', 'account_no', 'outlet_no',
                    'potansiyel_no', 'kampanya_kodu', 'sozlesme_durumu', 'uye_durumu', 'odeme_durum',
                    'odeme_tarihi', 'prim_ust_urun_grubu', 'basic_basari_skala', 'cinema_basari_skala',
                    'sport_basari_skala', 'genel_basari_skala', 'isp_basari_skala', 'uyelik_turu',
                    'kurulum_ziyaret_tipi', 'kurulum_tipi_aciklama', 'eski_ref_prim', 'odenecek_prim',
                    'mahsup_tutari_odeme_durumu', 'yonlendirme', 'prim_durum_aciklama'
                ];
                
                // Display names (kullanıcıya gösterilen başlıklar)
                $expectedDisplayColumns = [
                    'PRİM DETAY', 'PRİM DÖNEM', 'MEMO_ACAN_PERSONEL_KIMLIK', 'BÖLGE', 'BAYİ YÖNETİCİSİ',
                    'BAYİ TİP', 'BAYİ KODU', 'BAYİ ADI', 'SOZLESME_NO', 'ACCOUNT_NO', 'OUTLET NO',
                    'POTANSİYEL_NO', 'KAMPANYA KODU', 'SÖZLEŞME DURUMU', 'ÜYE DURUMU', 'ODEME_DURUM',
                    'ODEME_TARIHI', 'PRIM_UST_URUN_GRUBU', 'BASIC_BASARI_SKALA', 'CINEMA_BASARI_SKALA',
                    'SPORT_BASARI_SKALA', 'GENEL_BASARI_SKALA', 'ISP_BASARI_SKALA', 'UYELIK_TURU',
                    'KURULUM_ZIYARET_TIPI', 'KURULUM_TIPI_ACIKLAMA', 'ESKI_REF_PRIM', 'ODENECEK_PRIM',
                    'MAHSUP TUTARI-ÖDEME DURUMU', 'Yönlendirme', 'PRİM DURUM AÇIKLAMA'
                ];
                
                $matches = [];
                $missingColumns = [];
                $extraColumns = [];
                
                // Sütun eşleştirmeleri (büyük/küçük harf duyarsız)
                foreach ($expectedColumns as $i => $expected) {
                    $displayName = $expectedDisplayColumns[$i];
                    $found = false;
                    foreach ($headers as $index => $header) {
                        $headerClean = strtolower(trim($header));
                        // Hem internal hem display names ile eşleştir
                        if ($headerClean === strtolower($expected) || $headerClean === strtolower($displayName)) {
                            $matches[$expected] = $index;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $missingColumns[] = $displayName; // Display name göster
                    }
                }
                
                foreach ($headers as $header) {
                    $found = false;
                    $headerClean = strtolower(trim($header));
                    foreach ($expectedColumns as $i => $expected) {
                        $displayName = $expectedDisplayColumns[$i];
                        if ($headerClean === strtolower($expected) || $headerClean === strtolower($displayName)) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found && !empty(trim($header))) {
                        $extraColumns[] = $header;
                    }
                }
                
                $isCompatible = true; // Eksik sütunlar olsa bile yüklemeye izin ver
                
                echo json_encode([
                    'success' => true,
                    'headers' => $headers,
                    'expectedColumns' => $expectedDisplayColumns, // Display names gönder
                    'matches' => $matches,
                    'missingColumns' => $missingColumns,
                    'extraColumns' => $extraColumns,
                    'isCompatible' => $isCompatible,
                    'totalRows' => $totalRows,
                    'fileSize' => round(filesize($filePath) / 1024 / 1024, 2) . ' MB'
                ]);
                
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
            
            if (empty($selectedFile) || !in_array($selectedFile, $excelFiles)) {
                echo json_encode(['success' => false, 'error' => 'Geçersiz dosya seçimi']);
                exit;
            }
            
            try {
                $db = new PrimebazMSSQLConnection();
                $db->createPrimebazRaporTable();
                
                if ($truncate) {
                    $db->truncateTable();
                }
                
                $result = $db->processExcelFile($uploadsDir . '/' . $selectedFile, $selectedFile);
                echo json_encode($result);
            } catch (Exception $e) {
                $errorDetails = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'selectedFile' => $selectedFile,
                    'truncate' => $truncate,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Detaylı hata loglama
                error_log("PRIMEBAZ UPLOAD FAILED: " . json_encode($errorDetails));
                error_log("Upload hatası: " . json_encode($errorDetails));
                
                echo json_encode($errorDetails);
            }
            exit;
        }
    }
}

include '../../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="card-title mb-0">
                    <i class="fas fa-file-excel me-2"></i>
                    Primebaz Rapor Yükleme
                </h4>
            </div>
            <div class="card-body">
                <?php if ($sayfaYetkileri['ekle'] != 1): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Rapor yükleme yetkiniz bulunmamaktadır.
                    </div>
                <?php elseif (empty($excelFiles)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Uploads/primebaz klasöründe Excel (.xlsx) veya CSV dosyası bulunamadı.
                        <hr>
                        <small>
                            <strong>Dosya yükleme adımları:</strong><br>
                            1. Excel dosyanızı .xlsx formatında kaydedin<br>
                            2. Dosyayı <code>uploads/primebaz/</code> klasörüne kopyalayın<br>
                            3. Sayfayı yenileyin
                        </small>
                    </div>
                <?php else: ?>
                    <form id="uploadForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="excelFile" class="form-label">Excel/CSV Dosyası Seçin</label>
                                    <select class="form-select" id="excelFile" name="excelFile" required>
                                        <option value="">-- Dosya Seçin --</option>
                                        <?php foreach ($excelFiles as $file): ?>
                                            <option value="<?php echo htmlspecialchars($file); ?>">
                                                <?php echo htmlspecialchars($file); ?>
                                                <span class="text-muted">(<?php echo strtoupper(pathinfo($file, PATHINFO_EXTENSION)); ?>)</span>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Desteklenen formatlar: .xlsx, .csv</div>
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
    console.log('=== Primebaz Upload System Initialized ===');
    console.log('Page loaded successfully, event handlers being attached...');
    
    let analysisData = null;
    
    // Dosya seçimi değiştiğinde
    $('#excelFile').change(function() {
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
        const selectedFile = $('#excelFile').val();
        console.log('=== File Analysis Started ===');
        console.log('Selected file:', selectedFile);
        
        if (!selectedFile) {
            console.warn('No file selected for analysis');
            return;
        }
        
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Analiz Ediliyor...');
        
        console.log('Sending analysis request...');
        $.post('', {
            action: 'get_file_info',
            file: selectedFile
        })
        .done(function(response) {
            console.log('Analysis response received:', response);
            if (response.success) {
                console.log('Analysis successful - Rows:', response.total_rows, 'Columns:', response.column_count);
                analysisData = response;
                displayAnalysis(response);
                $('#fileAnalysis').show();
                $('#uploadBtn').prop('disabled', false); // Her zaman aktif et
            } else {
                console.error('Analysis failed:', response.error);
                alert('Hata: ' + response.error);
            }
        })
        .fail(function(xhr, status, error) {
            console.error('Analysis request failed:', {xhr, status, error});
            alert('Sunucu hatası oluştu');
        })
        .always(function() {
            $('#analyzeBtn').prop('disabled', false).html('<i class="fas fa-search me-2"></i>Dosyayı Analiz Et');
        });
    });
    
    // Upload form
    $('#uploadForm').submit(function(e) {
        e.preventDefault();
        
        console.log('=== Upload Process Started ===');
        console.log('Analysis data:', analysisData);
        
        if (!analysisData) {
            console.warn('No analysis data available');
            alert('Önce dosya analizi yapın');
            return;
        }
        
        const truncate = $('#truncateTable').is(':checked');
        console.log('Truncate table option:', truncate);
        
        if (truncate) {
            console.log('Requesting confirmation for table truncation...');
            if (!confirm('UYARI: Bu işlem mevcut tüm Primebaz rapor verilerini silecektir. Devam etmek istediğinizden emin misiniz?')) {
                console.log('User cancelled truncation');
                return;
            }
            console.log('User confirmed truncation');
        }
        
        // Büyük dosya için ek uyarı
        if (analysisData.totalRows > 10000) {
            if (!confirm(`Bu dosyada ${analysisData.totalRows.toLocaleString()} kayıt bulunuyor. Yükleme işlemi birkaç dakika sürebilir ve sayfayı kapatmamanız gerekir. Devam etmek istiyor musunuz?`)) {
                return;
            }
        }
        
        startUpload();
    });
    
    function displayAnalysis(data) {
        let html = `
            <div class="row">
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="display-6 text-primary">${data.headers.length}</div>
                        <div class="text-muted">Dosya Sütun Sayısı</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="display-6 text-success">${Object.keys(data.matches).length}</div>
                        <div class="text-muted">Eşleşen Sütun</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="display-6 text-info">${data.totalRows || 'N/A'}</div>
                        <div class="text-muted">Toplam Kayıt</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="display-6 text-secondary">${data.fileSize || 'N/A'}</div>
                        <div class="text-muted">Dosya Boyutu</div>
                    </div>
                </div>
            </div>
            <hr>
        `;
        
        if (data.isCompatible || data.missingColumns.length === 0) {
            html += `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Tam Uyumlu!</strong> Excel dosyası veritabanı yapısıyla tam uyumlu. Yükleme işlemi başlatılabilir.
                </div>
            `;
        } else {
            html += `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Uyumlu - Eksik Sütunlar Var!</strong> Bazı sütunlar eksik ama yükleme yapılabilir. 
                    Eksik sütunlar NULL olarak kaydedilecek, eşleşen sütunlar normal şekilde yüklenecektir.
                </div>
            `;
        }
        
        // Büyük dosya uyarısı
        if (data.totalRows > 5000) {
            html += `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Büyük Dosya:</strong> ${data.totalRows.toLocaleString()} kayıt tespit edildi. 
                    Yükleme işlemi birkaç dakika sürebilir. Lütfen sayfayı kapatmayın.
                </div>
            `;
        }
        
        if (data.missingColumns.length > 0) {
            html += `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Eksik Sütunlar (NULL olarak kaydedilecek):</strong><br>
                    Bu sütunlar veritabanında NULL değeri alacaktır.<br>
                    ${data.missingColumns.map(col => `<span class="badge bg-secondary me-1">${col}</span>`).join('')}
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
        
        $('#analysisContent').html(html);
    }
    
    function startUpload() {
        console.log('=== Starting Upload Function ===');
        const selectedFile = $('#excelFile').val();
        const truncate = $('#truncateTable').is(':checked') ? '1' : '0';
        
        console.log('Upload parameters:', {
            file: selectedFile,
            truncate: truncate,
            totalRows: analysisData?.totalRows || 'unknown'
        });
        
        $('#uploadBtn').prop('disabled', true);
        $('#progressContainer').show();
        $('#resultContainer').hide();
        
        updateProgress(0, 'Yükleme başlatılıyor...');
        
        // Büyük dosyalar için progress simulation
        let progressInterval;
        let currentProgress = 0;
        
        if (analysisData && analysisData.totalRows > 1000) {
            progressInterval = setInterval(function() {
                if (currentProgress < 90) {
                    currentProgress += Math.random() * 2;
                                                updateProgress(Math.min(currentProgress, 90), 'Excel/CSV dosyası işleniyor... (' + Math.floor(currentProgress) + '%)');
                }
            }, 1500);
        }
        
        console.log('Sending upload request...');
        $.post('', {
            action: 'upload',
            file: selectedFile,
            truncate: truncate
        })
        .done(function(response) {
            console.log('Upload completed successfully:', response);
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
            console.error('Upload Failed - Full Error Details:', {
                status: status,
                error: error,
                responseText: xhr.responseText,
                statusCode: xhr.status
            });
            
            try {
                if (xhr.responseText) {
                    const response = JSON.parse(xhr.responseText);
                    errorMessage = response.error || errorMessage;
                    console.error('Parsed Error Response:', response);
                    
                    // Display detailed error if available
                    if (response.detailed_error) {
                        errorMessage += '\n\nDetay: ' + response.detailed_error;
                    }
                }
            } catch (e) {
                console.error('Failed to parse error response:', e);
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
        
        // Handle multi-line error messages
        if (text.includes('\n')) {
            const lines = text.split('\n');
            $('#progressText').html(lines.map(line => 
                line.trim() ? '<div>' + $('<div>').text(line.trim()).html() + '</div>' : ''
            ).join(''));
        } else {
            $('#progressText').text(text);
        }
        
        if (percent === 100) {
            $('#progressBar').removeClass('progress-bar-animated');
        }
    }
    
    function displayResult(result) {
        let html = '';
        
        if (result.success) {
            html = `
                <div class="row text-center mb-3">
                    <div class="col-md-3">
                        <div class="display-6 text-primary">${result.totalProcessed || 0}</div>
                        <div class="text-muted">İşlenen Kayıt</div>
                    </div>
                    <div class="col-md-3">
                        <div class="display-6 text-success">${result.successful || 0}</div>
                        <div class="text-muted">Başarılı</div>
                    </div>
                    <div class="col-md-3">
                        <div class="display-6 text-danger">${result.errors || 0}</div>
                        <div class="text-muted">Hata</div>
                    </div>
                    <div class="col-md-3">
                        <div class="display-6 text-info">${result.skippedRows || 0}</div>
                        <div class="text-muted">Atlanan</div>
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