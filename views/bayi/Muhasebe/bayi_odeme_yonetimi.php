<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = "Bayi Ödeme Yönetimi";
$breadcrumbs = [
    ['title' => 'Ana Sayfa', 'url' => '../../../index.php'],
    ['title' => 'Bayi Ödeme Yönetimi']
];

// Auth kontrol
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
    // Admin için tüm yetkileri aç
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0, // 0 = Herkesi görebilir
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

// Kullanıcının grup bilgisini al (geriye dönük uyumluluk için)
$userGroupId = $currentUser['group_id'] ?? null;
$isRestrictedUser = ($sayfaYetkileri['kendi_kullanicini_gor'] == 1); // kendi_kullanicini_gor = 1 ise kısıtlı

// Database connection - auth.php'deki fonksiyonu kullan
function getConnection() {
    return getDatabaseConnection();
}

function createTables($conn) {
    $sql = "
    -- User groups tablosuna default grupları ekle
    IF NOT EXISTS (SELECT 1 FROM user_groups WHERE group_name = 'admin')
    BEGIN
        INSERT INTO user_groups (group_name, group_description) VALUES ('admin', 'Sistem Yöneticileri');
    END
    
    IF NOT EXISTS (SELECT 1 FROM user_groups WHERE group_name = 'bayi')
    BEGIN
        INSERT INTO user_groups (group_name, group_description) VALUES ('bayi', 'Bayi Kullanıcıları');
    END
    
    -- Ödeme türleri tablosu kontrol ve düzeltme
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='odeme_turleri' AND xtype='U')
    BEGIN
        CREATE TABLE odeme_turleri (
            id INT IDENTITY(1,1) PRIMARY KEY,
            tur_adi NVARCHAR(100) NOT NULL,
            tur_kodu NVARCHAR(20) NOT NULL UNIQUE,
            aciklama NVARCHAR(1500),
            renk_kodu NVARCHAR(7) DEFAULT '#007bff',
            ikon NVARCHAR(50) DEFAULT 'fa-money-bill',
            aktif BIT DEFAULT 1,
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME NULL
        );
    END
    ELSE
    BEGIN
        -- Eksik alanları kontrol et ve ekle
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'odeme_turleri' AND COLUMN_NAME = 'tur_kodu')
        BEGIN
            ALTER TABLE odeme_turleri ADD tur_kodu NVARCHAR(20) NULL;
            -- Mevcut kayıtlar için tur_kodu oluştur
            UPDATE odeme_turleri SET tur_kodu = 'TUR_' + CAST(id AS NVARCHAR(10)) WHERE tur_kodu IS NULL;
            -- Constraint ekle
            IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_NAME = 'UQ_odeme_turleri_tur_kodu')
            BEGIN
                ALTER TABLE odeme_turleri ADD CONSTRAINT UQ_odeme_turleri_tur_kodu UNIQUE (tur_kodu);
            END
        END
    END

    -- Varsayılan ödeme türlerini ekle (sadece tablo boşsa)
    IF NOT EXISTS (SELECT 1 FROM odeme_turleri)
    BEGIN
        INSERT INTO odeme_turleri (tur_adi, tur_kodu, aciklama, renk_kodu, ikon) VALUES 
        ('Hakediş Ödemesi', 'HAKEDIS', 'Bayi hakediş ödemeleri', '#28a745', 'fa-hand-holding-usd'),
        ('Prim Ödemesi', 'PRIM', 'Bayi prim ödemeleri', '#17a2b8', 'fa-star'),
        ('Fatura Kesildi', 'FATURA', 'Bayi fatura kesintileri', '#dc3545', 'fa-file-invoice-dollar'),
        ('Diğer Ödeme', 'DIGER', 'Diğer ödeme türleri', '#6c757d', 'fa-coins'),
        ('Kesinti', 'KESINTI', 'Bayi kesintileri', '#fd7e14', 'fa-minus-circle');
    END
    ";
    
    try {
        $conn->exec($sql);
    } catch (Exception $e) {
        error_log("Tablo oluşturma hatası: " . $e->getMessage());
    }
}

try {
    $conn = getConnection();
    
    // Tablolar mevcut mu kontrol et ve oluştur
    createTables($conn);
    
    // Tabloların varlığını test et
    try {
        $test_query = "SELECT COUNT(*) as count FROM odeme_turleri";
        $test_stmt = $conn->prepare($test_query);
        $test_stmt->execute();
        $test_result = $test_stmt->fetch();
        error_log("Ödeme türleri tablosu test: " . $test_result['count'] . " kayıt bulundu");
    } catch (Exception $e) {
        error_log("Tablo test hatası: " . $e->getMessage());
    }
    
    // AJAX isteği kontrolü
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        try {
            $action = $_POST['action'];
            
            if ($action === 'get_payments') {
                // Ödemeleri getir
                $bayi_id = $_POST['bayi_id'] ?? '';
                $odeme_turu = $_POST['odeme_turu'] ?? '';
                $baslangic_tarih = $_POST['baslangic_tarih'] ?? '';
                $bitis_tarih = $_POST['bitis_tarih'] ?? '';
                
                // Kısıtlı kullanıcı ise sadece kendi kayıtlarını göster
                if ($isRestrictedUser) {
                    $bayi_id = $currentUser['id'];
                }
                
                $sql = "SELECT 
                            bho.id,
                            u.first_name + ' ' + u.last_name as bayi_adi,
                            u.email as bayi_email,
                            ot.tur_adi,
                            ot.renk_kodu,
                            ot.ikon,
                            bho.odeme_tutari,
                            bho.odeme_tarihi,
                            bho.aciklama,
                            bho.referans_no,
                            bho.evrak_dosya_adi,
                            bho.evrak_dosya_yolu,
                            bho.created_at,
                            ISNULL(cb.first_name + ' ' + cb.last_name, 'Sistem') as created_by
                        FROM bayi_hakedis_odeme bho
                        INNER JOIN users u ON bho.user_id = u.id
                        INNER JOIN odeme_turleri ot ON bho.odeme_turu_id = ot.id
                        LEFT JOIN users cb ON bho.created_by = cb.id
                        WHERE 1=1";
                
                $params = [];
                
                if ($bayi_id) {
                    $sql .= " AND bho.user_id = ?";
                    $params[] = $bayi_id;
                }
                
                if ($odeme_turu) {
                    $sql .= " AND bho.odeme_turu_id = ?";
                    $params[] = $odeme_turu;
                }
                
                if ($baslangic_tarih) {
                    $sql .= " AND bho.odeme_tarihi >= ?";
                    $params[] = $baslangic_tarih;
                }
                
                if ($bitis_tarih) {
                    $sql .= " AND bho.odeme_tarihi <= ?";
                    $params[] = $bitis_tarih;
                }
                
                $sql .= " ORDER BY bho.created_at DESC";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Filtreye göre toplamları hesapla
                $toplamSql = "SELECT 
                                COUNT(*) as toplam_islem,
                                SUM(CASE WHEN bho.odeme_tutari > 0 THEN bho.odeme_tutari ELSE 0 END) as toplam_odeme,
                                SUM(CASE WHEN bho.odeme_tutari < 0 THEN ABS(bho.odeme_tutari) ELSE 0 END) as toplam_kesinti
                              FROM bayi_hakedis_odeme bho
                              WHERE 1=1";
                
                $toplamParams = [];
                
                if ($bayi_id) {
                    $toplamSql .= " AND bho.user_id = ?";
                    $toplamParams[] = $bayi_id;
                }
                
                if ($odeme_turu) {
                    $toplamSql .= " AND bho.odeme_turu_id = ?";
                    $toplamParams[] = $odeme_turu;
                }
                
                if ($baslangic_tarih) {
                    $toplamSql .= " AND bho.odeme_tarihi >= ?";
                    $toplamParams[] = $baslangic_tarih;
                }
                
                if ($bitis_tarih) {
                    $toplamSql .= " AND bho.odeme_tarihi <= ?";
                    $toplamParams[] = $bitis_tarih;
                }
                
                $toplamStmt = $conn->prepare($toplamSql);
                $toplamStmt->execute($toplamParams);
                $toplamlar = $toplamStmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true, 
                    'data' => $payments,
                    'totals' => [
                        'toplam_islem' => $toplamlar['toplam_islem'] ?? 0,
                        'toplam_odeme' => $toplamlar['toplam_odeme'] ?? 0,
                        'toplam_kesinti' => $toplamlar['toplam_kesinti'] ?? 0
                    ]
                ]);
                exit;
            }
            
            if ($action === 'find_bayi_by_abone_no') {
                // Abone numarasına göre bayi bul
                $abone_no = $_POST['abone_no'] ?? '';
                
                if (empty($abone_no)) {
                    echo json_encode(['success' => false, 'message' => 'Abone numarası boş olamaz']);
                    exit;
                }
                
                // İris rapor tablosunda abone numarasını ara (daha geniş arama)
                $iris_sql = "SELECT TOP 1 TALEBI_GIREN_PERSONELNO, DT_MUSTERI_NO, TALEBI_GIREN_PERSONEL_ALTBAYI, TALEBI_GIREN_PERSONEL
                            FROM digiturk.iris_rapor 
                            WHERE DT_MUSTERI_NO LIKE ? 
                               OR UYDU_BASVURU_POTANSIYEL_NO LIKE ?
                               OR UYDU_BASVURU_UYE_NO LIKE ?
                               OR AKTIVE_EDILEN_UYENO LIKE ?
                            ORDER BY eklenme_tarihi DESC";
                
                $search_pattern = '%' . $abone_no . '%';
                $iris_stmt = $conn->prepare($iris_sql);
                $iris_stmt->execute([$search_pattern, $search_pattern, $search_pattern, $search_pattern]);
                $iris_result = $iris_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$iris_result) {
                    // Debug: Toplam kayıt sayısını kontrol et
                    $count_sql = "SELECT COUNT(*) as total FROM digiturk.iris_rapor";
                    $count_stmt = $conn->prepare($count_sql);
                    $count_stmt->execute();
                    $count_result = $count_stmt->fetch();
                    
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Bu abone numarası için kayıt bulunamadı',
                        'debug' => [
                            'searched_number' => $abone_no,
                            'total_iris_records' => $count_result['total']
                        ]
                    ]);
                    exit;
                }
                
                $personel_no = $iris_result['TALEBI_GIREN_PERSONELNO'];
                
                if (!$personel_no) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Personel numarası boş',
                        'debug' => [
                            'found_customer_no' => $iris_result['DT_MUSTERI_NO'] ?? 'N/A'
                        ]
                    ]);
                    exit;
                }
                
                // Users_bayi tablosunda personel kimlik numarasını ara
                $bayi_sql = "SELECT user_id, personel_kimlik_no, iris_altbayi
                            FROM users_bayi 
                            WHERE personel_kimlik_no = ?";
                
                $bayi_stmt = $conn->prepare($bayi_sql);
                $bayi_stmt->execute([$personel_no]);
                $bayi_result = $bayi_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$bayi_result) {
                    // Debug: Tüm personel numaralarını kontrol et
                    $all_personel_sql = "SELECT COUNT(*) as total, 
                                                MIN(personel_kimlik_no) as min_no,
                                                MAX(personel_kimlik_no) as max_no
                                         FROM users_bayi";
                    $all_personel_stmt = $conn->prepare($all_personel_sql);
                    $all_personel_stmt->execute();
                    $all_personel_result = $all_personel_stmt->fetch();
                    
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Bu personel numarası için bayi bulunamadı',
                        'debug' => [
                            'searched_personel_no' => $personel_no,
                            'talebi_giren_personel' => $iris_result['TALEBI_GIREN_PERSONEL'] ?? 'N/A',
                            'iris_personel_altbayi' => $iris_result['TALEBI_GIREN_PERSONEL_ALTBAYI'] ?? 'N/A',
                            'found_customer_no' => $iris_result['DT_MUSTERI_NO'] ?? 'N/A',
                            'total_bayi_records' => $all_personel_result['total'],
                            'min_personel_no' => $all_personel_result['min_no'],
                            'max_personel_no' => $all_personel_result['max_no']
                        ]
                    ]);
                    exit;
                }
                
                $user_id = $bayi_result['user_id'];
                
                // Users tablosundan bayi bilgilerini getir (kısıtlamaları kaldırıldı)
                $user_sql = "SELECT u.id, u.first_name + ' ' + u.last_name as name, u.email, u.status,
                                   ug.group_name
                            FROM users u
                            LEFT JOIN user_groups ug ON u.user_group_id = ug.id
                            WHERE u.id = ?";
                
                $user_stmt = $conn->prepare($user_sql);
                $user_stmt->execute([$user_id]);
                $user_result = $user_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user_result) {
                    echo json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı']);
                    exit;
                }
                
                // Debug bilgisi ekle
                $status_info = '';
                if ($user_result['status'] !== 'AKTIF') {
                    $status_info = ' (Durum: ' . $user_result['status'] . ')';
                }
                
                $group_info = '';
                if ($user_result['group_name']) {
                    $group_info = ' (Grup: ' . $user_result['group_name'] . ')';
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => $user_result,
                    'message' => 'Bayi bulundu: ' . $user_result['name'] . $status_info . $group_info
                ]);
                exit;
            }
            
            if ($action === 'add_payment') {
                // Ekleme yetkisi kontrolü
                if ($sayfaYetkileri['ekle'] != 1) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme ekleme yetkiniz bulunmamaktadır.']);
                    exit;
                }
                
                // Yeni ödeme ekle
                $user_id = $_POST['user_id'];
                $odeme_turu_id = $_POST['odeme_turu_id'];
                $odeme_tutari = $_POST['tutar'];
                $odeme_tarihi = $_POST['odeme_tarihi'];
                $aciklama = $_POST['aciklama'] ?? '';
                $referans_no = $_POST['referans_no'] ?? '';
                
                // Dosya yükleme işlemi
                $evrak_dosya_adi = null;
                $evrak_dosya_yolu = null;
                $evrak_dosya_boyutu = null;
                
                if (isset($_FILES['dekont_dosya']) && $_FILES['dekont_dosya']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/dekontlar/';
                    
                    // Upload dizinini oluştur
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            echo json_encode(['success' => false, 'message' => 'Upload dizini oluşturulamadı']);
                            exit;
                        }
                    }
                    
                    $file_info = pathinfo($_FILES['dekont_dosya']['name']);
                    $extension = strtolower($file_info['extension']);
                    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    
                    if (in_array($extension, $allowed_extensions)) {
                        $unique_name = 'dekont_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $extension;
                        $upload_path = $upload_dir . $unique_name;
                        
                        if (move_uploaded_file($_FILES['dekont_dosya']['tmp_name'], $upload_path)) {
                            $evrak_dosya_adi = $_FILES['dekont_dosya']['name'];
                            $evrak_dosya_yolu = 'uploads/dekontlar/' . $unique_name;
                            $evrak_dosya_boyutu = $_FILES['dekont_dosya']['size'];
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi']);
                            exit;
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Desteklenmeyen dosya türü']);
                        exit;
                    }
                }
                
                // Açıklama alanını 500 karakterle sınırla (veritabanı limiti)
                $aciklama_kesik = mb_substr($aciklama, 0, 1500, 'UTF-8');
                
                $sql = "INSERT INTO bayi_hakedis_odeme (
                            user_id, odeme_turu_id, odeme_tutari, odeme_tarihi, aciklama, referans_no,
                            evrak_dosya_adi, evrak_dosya_yolu, evrak_dosya_boyutu, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute([
                    $user_id, $odeme_turu_id, $odeme_tutari, $odeme_tarihi, $aciklama_kesik, $referans_no,
                    $evrak_dosya_adi, $evrak_dosya_yolu, $evrak_dosya_boyutu, $currentUser['id']
                ]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Ödeme kaydı başarıyla eklendi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ödeme kaydı eklenirken hata oluştu']);
                }
                exit;
            }
            
            if ($action === 'get_payment_details') {
                // Ödeme detaylarını getir
                $payment_id = $_POST['payment_id'] ?? '';
                
                if (empty($payment_id)) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme ID boş olamaz']);
                    exit;
                }
                
                $sql = "SELECT 
                            bho.id,
                            bho.user_id,
                            bho.odeme_turu_id,
                            bho.odeme_tutari,
                            CONVERT(varchar(10), bho.odeme_tarihi, 23) as odeme_tarihi,
                            bho.aciklama,
                            bho.referans_no,
                            bho.evrak_dosya_adi,
                            bho.evrak_dosya_yolu,
                            u.first_name + ' ' + u.last_name as bayi_adi,
                            ot.tur_adi
                        FROM bayi_hakedis_odeme bho
                        INNER JOIN users u ON bho.user_id = u.id
                        INNER JOIN odeme_turleri ot ON bho.odeme_turu_id = ot.id
                        WHERE bho.id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([$payment_id]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment) {
                    echo json_encode(['success' => true, 'data' => $payment]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ödeme kaydı bulunamadı']);
                }
                exit;
            }
            
            if ($action === 'update_payment') {
                // Düzenleme yetkisi kontrolü
                if ($sayfaYetkileri['duzenle'] != 1) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme düzenleme yetkiniz bulunmamaktadır.']);
                    exit;
                }
                
                // Ödeme güncelle
                $payment_id = $_POST['payment_id'];
                $user_id = $_POST['user_id'];
                $odeme_turu_id = $_POST['odeme_turu_id'];
                $odeme_tutari = $_POST['tutar'];
                $odeme_tarihi = $_POST['odeme_tarihi'];
                $aciklama = $_POST['aciklama'] ?? '';
                $referans_no = $_POST['referans_no'] ?? '';
                
                // Dosya güncelleme işlemi
                $evrak_update_sql = "";
                $evrak_params = [];
                
                if (isset($_FILES['dekont_dosya']) && $_FILES['dekont_dosya']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/dekontlar/';
                    
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            echo json_encode(['success' => false, 'message' => 'Upload dizini oluşturulamadı']);
                            exit;
                        }
                    }
                    
                    $file_info = pathinfo($_FILES['dekont_dosya']['name']);
                    $extension = strtolower($file_info['extension']);
                    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    
                    if (in_array($extension, $allowed_extensions)) {
                        $unique_name = 'dekont_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $extension;
                        $upload_path = $upload_dir . $unique_name;
                        
                        if (move_uploaded_file($_FILES['dekont_dosya']['tmp_name'], $upload_path)) {
                            $evrak_update_sql = ", evrak_dosya_adi = ?, evrak_dosya_yolu = ?, evrak_dosya_boyutu = ?";
                            $evrak_params = [
                                $_FILES['dekont_dosya']['name'],
                                'uploads/dekontlar/' . $unique_name,
                                $_FILES['dekont_dosya']['size']
                            ];
                        }
                    }
                }
                
                // Açıklama alanını 500 karakterle sınırla
                $aciklama_kesik = mb_substr($aciklama, 0, 500, 'UTF-8');
                
                $sql = "UPDATE bayi_hakedis_odeme SET 
                            user_id = ?, 
                            odeme_turu_id = ?, 
                            odeme_tutari = ?, 
                            odeme_tarihi = ?, 
                            aciklama = ?,
                            referans_no = ?,
                            updated_at = GETDATE(),
                            updated_by = ?
                            {$evrak_update_sql}
                        WHERE id = ?";
                
                $params = [
                    $user_id, $odeme_turu_id, $odeme_tutari, $odeme_tarihi, $aciklama_kesik, $referans_no, $currentUser['id']
                ];
                $params = array_merge($params, $evrak_params);
                $params[] = $payment_id;
                
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute($params);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Ödeme kaydı başarıyla güncellendi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ödeme kaydı güncellenirken hata oluştu']);
                }
                exit;
            }
            
        } catch (Exception $e) {
            error_log("AJAX Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            echo json_encode([
                'success' => false, 
                'message' => 'Hata: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ]);
        }
        exit;
    }
    
    // Bayileri getir (users tablosundan user_groups ile)
    // Önce grup kontrolü yapalım
    $group_check_sql = "SELECT id, group_name FROM user_groups";
    $group_check_stmt = $conn->prepare($group_check_sql);
    $group_check_stmt->execute();
    $groups = $group_check_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Mevcut gruplar: " . json_encode($groups));
    
    // Eğer kullanıcı grup 3 ise, sadece kendi kaydını getir
    if ($isRestrictedUser) {
        $bayiler_sql = "SELECT u.id, u.first_name + ' ' + u.last_name as name, u.email,
                               ug.group_name
                        FROM users u 
                        LEFT JOIN user_groups ug ON u.user_group_id = ug.id 
                        WHERE u.status = 1 AND u.id = ?
                        ORDER BY u.first_name, u.last_name";
        $bayiler_stmt = $conn->prepare($bayiler_sql);
        $bayiler_stmt->execute([$currentUser['id']]);
        $bayiler = $bayiler_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $bayiler_sql = "SELECT u.id, u.first_name + ' ' + u.last_name as name, u.email,
                               ug.group_name
                        FROM users u 
                        LEFT JOIN user_groups ug ON u.user_group_id = ug.id 
                        WHERE u.status = 1 
                        ORDER BY u.first_name, u.last_name";
        $bayiler_stmt = $conn->prepare($bayiler_sql);
        $bayiler_stmt->execute();
        $bayiler = $bayiler_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    error_log("Bulunan bayiler: " . count($bayiler));
    
    // Ödeme türlerini getir
    $odeme_turleri_sql = "SELECT * FROM odeme_turleri WHERE aktif = 1 ORDER BY tur_adi";
    $odeme_turleri_stmt = $conn->prepare($odeme_turleri_sql);
    $odeme_turleri_stmt->execute();
    $odeme_turleri = $odeme_turleri_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Özet istatistikleri
    $stats_sql = "SELECT 
                    COUNT(*) as toplam_islem,
                    SUM(CASE WHEN odeme_tutari > 0 THEN odeme_tutari ELSE 0 END) as toplam_odeme,
                    SUM(CASE WHEN odeme_tutari < 0 THEN ABS(odeme_tutari) ELSE 0 END) as toplam_kesinti,
                    COUNT(CASE WHEN created_at >= CAST(GETDATE() AS DATE) THEN 1 END) as bugun_islem
                  FROM bayi_hakedis_odeme";
    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

include '../../../includes/header.php';

// Hata mesajını göster
if (isset($error)) {
    echo '<div class="container-fluid mt-3">';
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo '<strong>Hata!</strong> ' . htmlspecialchars($error);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    echo '</div>';
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>
                    <i class="fas fa-money-bill-wave text-success me-2"></i>
                    Bayi Ödeme Yönetimi
                    <small class="text-muted">(<?php echo count($bayiler); ?> bayi bulundu)</small>
                </h2>
                <?php if ($sayfaYetkileri['ekle'] == 1): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                    <i class="fas fa-plus me-2"></i>Yeni Ödeme Ekle
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- İstatistik Kartları -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-primary mb-3">
                        <i class="fas fa-list-ol fa-3x"></i>
                    </div>
                    <h3 class="text-primary"><?php echo number_format($stats['toplam_islem'] ?? 0); ?></h3>
                    <p class="text-muted mb-0">Toplam İşlem</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-success mb-3">
                        <i class="fas fa-hand-holding-usd fa-3x"></i>
                    </div>
                    <h3 class="text-success">₺<?php echo number_format($stats['toplam_odeme'] ?? 0, 2); ?></h3>
                    <p class="text-muted mb-0">Toplam Ödeme</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-danger mb-3">
                        <i class="fas fa-minus-circle fa-3x"></i>
                    </div>
                    <h3 class="text-danger">₺<?php echo number_format($stats['toplam_kesinti'] ?? 0, 2); ?></h3>
                    <p class="text-muted mb-0">Toplam Kesinti</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-info mb-3">
                        <i class="fas fa-calendar-day fa-3x"></i>
                    </div>
                    <h3 class="text-info"><?php echo number_format($stats['bugun_islem'] ?? 0); ?></h3>
                    <p class="text-muted mb-0">Bugünkü İşlem</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtreler -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-filter me-2"></i>Filtreler
            </h5>
        </div>
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Bayi</label>
                        <select class="form-select" name="bayi_id" <?php echo $isRestrictedUser ? 'disabled' : ''; ?>>
                            <option value="">Tüm Bayiler</option>
                            <?php foreach ($bayiler as $bayi): ?>
                                <option value="<?php echo $bayi['id']; ?>" <?php echo $isRestrictedUser ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bayi['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isRestrictedUser): ?>
                            <!-- Kısıtlı kullanıcı için hidden input ile değeri gönder -->
                            <input type="hidden" name="bayi_id" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> Sadece kendi kayıtlarınızı görüntüleyebilirsiniz
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ödeme Türü</label>
                        <select class="form-select" name="odeme_turu">
                            <option value="">Tüm Türler</option>
                            <?php foreach ($odeme_turleri as $tur): ?>
                                <option value="<?php echo $tur['id']; ?>"><?php echo htmlspecialchars($tur['tur_adi']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Başlangıç Tarihi</label>
                        <input type="date" class="form-control" name="baslangic_tarih">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Bitiş Tarihi</label>
                        <input type="date" class="form-control" name="bitis_tarih">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filtrele
                        </button>
                        <button type="reset" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-2"></i>Temizle
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Ödeme Listesi -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Ödeme Listesi
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="paymentsTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Bayi</th>
                            <th>Ödeme Türü</th>
                            <th>Tutar</th>
                            <th>Tarih</th>
                            <th>Referans No</th>
                            <th>Evrak</th>
                            <th>Oluşturan</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                                <p class="mt-2 text-muted">Veriler yükleniyor...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Yeni Ödeme Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalTitle">
                    <i class="fas fa-plus me-2"></i>Yeni Ödeme Ekle
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addPaymentForm" enctype="multipart/form-data">
                <input type="hidden" id="paymentId" name="payment_id" value="">
                <div class="modal-body">
                    <!-- Abone No ile Bayi Arama -->
                    <?php if (!$isRestrictedUser): ?>
                    <div class="card mb-3 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-primary">
                                <i class="fas fa-search me-2"></i>Abone No ile Bayi Bul
                            </h6>
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <input type="text" class="form-control" id="aboneNoInput" placeholder="Abone numarasını giriniz...">
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-outline-primary w-100" id="findBayiBtn">
                                        <i class="fas fa-search me-2"></i>Bul
                                    </button>
                                </div>
                            </div>
                            <div id="bayiSearchResult" class="mt-2"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Bayi <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" id="bayiSelect" required <?php echo $isRestrictedUser ? 'disabled' : ''; ?>>
                                <option value="">Bayi Seçin (<?php echo count($bayiler); ?> adet)</option>
                                <?php if (empty($bayiler)): ?>
                                    <option value="" disabled>Hiç bayi bulunamadı</option>
                                <?php else: ?>
                                    <?php foreach ($bayiler as $bayi): ?>
                                        <option value="<?php echo $bayi['id']; ?>" <?php echo $isRestrictedUser ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bayi['name']); ?> (Grup: <?php echo $bayi['group_name'] ?? 'Yok'; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if ($isRestrictedUser): ?>
                                <!-- Kısıtlı kullanıcı için hidden input ile değeri gönder -->
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> Sadece kendi kayıtlarınızı görüntüleyebilirsiniz
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ödeme Türü <span class="text-danger">*</span></label>
                            <select class="form-select" name="odeme_turu_id" required>
                                <option value="">Ödeme Türü Seçin</option>
                                <?php foreach ($odeme_turleri as $tur): ?>
                                    <option value="<?php echo $tur['id']; ?>" data-color="<?php echo $tur['renk_kodu']; ?>">
                                        <?php echo htmlspecialchars($tur['tur_adi']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tutar (₺) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="tutar" required>
                            <div class="form-text">Kesinti için negatif (-) değer giriniz</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ödeme Tarihi <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="odeme_tarihi" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Referans No</label>
                            <input type="text" class="form-control" name="referans_no" placeholder="Ödeme referans numarası" maxlength="100">
                            <div class="form-text">Ödeme işleminin referans numarası (isteğe bağlı)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Dekont/Belge</label>
                            <input type="file" class="form-control" name="dekont_dosya" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text">PDF, JPG, PNG, DOC dosyaları desteklenmektedir</div>
                            <div id="currentFileInfo" class="mt-2" style="display: none;">
                                <small class="text-muted">Mevcut dosya: </small>
                                <div id="currentFile"></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" rows="3" maxlength="1500" placeholder="Ödeme ile ilgili açıklama..."></textarea>
                            <div class="form-text">Maksimum 1500 karakter <span id="charCount" class="text-muted">(0/1500)</span></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="paymentSubmitBtn">
                        <i class="fas fa-save me-2"></i>Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.alert-sm {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

.bg-light {
    background-color: #f8f9fa !important;
}

#bayiSearchResult .alert {
    margin-bottom: 0;
}

.card-title {
    margin-bottom: 0.75rem;
    font-weight: 600;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// PHP'den JavaScript'e yetki bilgilerini aktar
const sayfaYetkileri = {
    ekle: <?php echo $sayfaYetkileri['ekle']; ?>,
    duzenle: <?php echo $sayfaYetkileri['duzenle']; ?>,
    sil: <?php echo $sayfaYetkileri['sil']; ?>
};

$(document).ready(function() {
    // Sayfa yüklendiğinde ödemeleri getir
    loadPayments();
    
    // Abone No ile bayi bulma
    $('#findBayiBtn').on('click', function() {
        const aboneNo = $('#aboneNoInput').val().trim();
        const resultDiv = $('#bayiSearchResult');
        const btn = $(this);
        const originalText = btn.html();
        
        if (!aboneNo) {
            resultDiv.html('<div class="alert alert-warning alert-sm mb-0">Lütfen abone numarasını giriniz</div>');
            return;
        }
        
        // Buton durumunu değiştir
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Aranıyor...');
        resultDiv.html('');
        
        $.ajax({
            url: '',
            type: 'POST',
            data: {
                action: 'find_bayi_by_abone_no',
                abone_no: aboneNo
            },
            dataType: 'json',
            success: function(response) {
                console.log('Bayi arama yanıtı:', response);
                
                if (response && response.success) {
                    // Bayi bulundu, dropdown'da seç
                    $('#bayiSelect').val(response.data.id);
                    
                    resultDiv.html(`
                        <div class="alert alert-success alert-sm mb-0">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Bayi Bulundu:</strong> ${response.data.name}
                            <br><small class="text-muted">${response.data.email}</small>
                        </div>
                    `);
                } else {
                    let errorMessage = response.message || 'Bayi bulunamadı';
                    let debugInfo = '';
                    
                    // Debug bilgisi varsa daha okunabilir formatta göster
                    if (response.debug) {
                        let debugItems = [];
                        
                        if (response.debug.searched_personel_no) {
                            debugItems.push(`<strong>Aranan Personel No:</strong> ${response.debug.searched_personel_no}`);
                        }
                        
                        if (response.debug.talebi_giren_personel) {
                            debugItems.push(`<strong>Talebi Giren Personel:</strong> ${response.debug.talebi_giren_personel}`);
                        }
                        
                        if (response.debug.iris_personel_altbayi) {
                            debugItems.push(`<strong>Alt Bayi:</strong> ${response.debug.iris_personel_altbayi}`);
                        }
                        
                        if (response.debug.found_customer_no) {
                            debugItems.push(`<strong>Bulunan Müşteri No:</strong> ${response.debug.found_customer_no}`);
                        }
                        
                        if (response.debug.total_bayi_records) {
                            debugItems.push(`<strong>Toplam Bayi Kayıt:</strong> ${response.debug.total_bayi_records}`);
                        }
                        
                        if (debugItems.length > 0) {
                            debugInfo = '<br><div class="mt-2 p-2 bg-light border rounded"><small>' + debugItems.join('<br>') + '</small></div>';
                        }
                    }
                    
                    resultDiv.html(`
                        <div class="alert alert-danger alert-sm mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${errorMessage}
                            ${debugInfo}
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error('Bayi arama hatası:', xhr.responseText, status, error);
                resultDiv.html(`
                    <div class="alert alert-danger alert-sm mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Arama sırasında hata oluştu: ${error}
                    </div>
                `);
            },
            complete: function() {
                // Buton durumunu eski haline getir
                btn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Enter tuşu ile arama
    $('#aboneNoInput').on('keypress', function(e) {
        if (e.which === 13) { // Enter tuşu
            e.preventDefault();
            $('#findBayiBtn').click();
        }
    });
    
    // Modal kapanırken arama sonuçlarını temizle
    $('#addPaymentModal').on('hidden.bs.modal', function() {
        $('#aboneNoInput').val('');
        $('#bayiSearchResult').html('');
        $('#bayiSelect').val('');
    });
    
    // Filtre formu submit
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadPayments();
    });
    
    // Filtre sıfırlama
    $('#filterForm').on('reset', function() {
        setTimeout(loadPayments, 100);
    });
    
    // Yeni ödeme/Düzenleme formu submit
    $('#addPaymentForm').on('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        const paymentId = $('#paymentId').val();
        const isEdit = paymentId !== '';
        
        // Submit button'u disable et
        const loadingText = isEdit ? 
            '<i class="fas fa-spinner fa-spin me-2"></i>Güncelleniyor...' : 
            '<i class="fas fa-spinner fa-spin me-2"></i>Kaydediliyor...';
        submitBtn.prop('disabled', true).html(loadingText);
        
        const formData = new FormData(this);
        formData.append('action', isEdit ? 'update_payment' : 'add_payment');
        
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 30000,
            success: function(response) {
                console.log(`${isEdit ? 'Update' : 'Add'} payment response:`, response);
                
                // JSON parse et eğer string ise
                if (typeof response === 'string') {
                    try {
                        response = JSON.parse(response);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        showAlert('danger', 'Sunucu cevabı işlenemedi');
                        return;
                    }
                }
                
                if (response && response.success) {
                    $('#addPaymentModal').modal('hide');
                    resetPaymentModal(); // Modal'ı sıfırla
                    loadPayments();
                    const successMessage = isEdit ? 
                        (response.message || 'Güncelleme başarılı') : 
                        (response.message || 'İşlem başarılı');
                    showAlert('success', successMessage);
                } else {
                    const errorMessage = isEdit ? 
                        (response.message || 'Güncelleme başarısız') : 
                        (response.message || 'İşlem başarısız');
                    showAlert('danger', errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr.responseText, status, error);
                let errorMessage = isEdit ? 
                    'Güncelleme sırasında hata oluştu: ' : 
                    'İşlem sırasında hata oluştu: ';
                
                if (xhr.status === 500) {
                    errorMessage += 'Sunucu hatası (HTTP 500)';
                } else if (xhr.status === 0) {
                    errorMessage += 'Bağlantı hatası';
                } else {
                    errorMessage += error;
                }
                
                showAlert('danger', errorMessage);
            },
            complete: function() {
                // Submit button'u tekrar aktif et
                submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Modal reset fonksiyonu
    function resetPaymentModal() {
        // Form'u sıfırla
        $('#addPaymentForm')[0].reset();
        
        // Hidden ID'yi temizle
        $('#paymentId').val('');
        
        // Modal başlığını ve buton metnini ekleme moduna çevir
        $('#paymentModalTitle').html('<i class="fas fa-plus me-2"></i>Yeni Ödeme Ekle');
        $('#paymentSubmitBtn').html('<i class="fas fa-save me-2"></i>Kaydet');
        
        // Abone no arama alanını göster
        $('#addPaymentModal .bg-light').show();
        
        // Mevcut dosya bilgisini gizle
        $('#currentFileInfo').hide();
        $('#currentFile').html('');
        
        // Arama sonuçlarını temizle
        $('#aboneNoInput').val('');
        $('#bayiSearchResult').html('');
        $('#bayiSelect').val('');
        
        // Bugünün tarihini set et
        $('#addPaymentModal input[name="odeme_tarihi"]').val('<?php echo date('Y-m-d'); ?>');
    }
    
    // Modal açılırken (Yeni Ödeme Ekle butonu)
    $('button[data-bs-target="#addPaymentModal"]').on('click', function() {
        resetPaymentModal();
    });
    
    // Modal kapanırken temizle
    $('#addPaymentModal').on('hidden.bs.modal', function() {
        resetPaymentModal();
    });
    
    // Açıklama karakter sayacı
    $('textarea[name="aciklama"]').on('input', function() {
        const currentLength = $(this).val().length;
        const maxLength = 1500;
        const remaining = maxLength - currentLength;
        
        const counter = $('#charCount');
        counter.text(`(${currentLength}/${maxLength})`);
        
        if (remaining < 50) {
            counter.removeClass('text-muted').addClass('text-warning');
        } else if (remaining < 20) {
            counter.removeClass('text-warning').addClass('text-danger');
        } else {
            counter.removeClass('text-warning text-danger').addClass('text-muted');
        }
        
        // 1500 karakteri aştıysa kes
        if (currentLength > maxLength) {
            $(this).val($(this).val().substring(0, maxLength));
        }
    });
    
    function loadPayments() {
        const formData = new FormData($('#filterForm')[0]);
        formData.append('action', 'get_payments');
        
        console.log('Loading payments...');
        
        // Show loading state
        const tbody = $('#paymentsTableBody');
        tbody.html(`
            <tr>
                <td colspan="8" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="mt-2 text-muted">Veriler yükleniyor...</p>
                </td>
            </tr>
        `);
        
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 30000, // 30 second timeout
            success: function(response) {
                console.log('Load payments response:', response);
                if (response && response.success) {
                    displayPayments(response.data || []);
                    updateStatistics(response.totals || {});
                } else {
                    showAlert('danger', 'Veriler yüklenirken hata oluştu: ' + (response.message || 'Bilinmeyen hata'));
                    console.error('Response error:', response);
                    displayPayments([]);
                    updateStatistics({});
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error Details:', {
                    responseText: xhr.responseText,
                    status: xhr.status,
                    statusText: xhr.statusText,
                    error: error
                });
                
                let errorMessage = 'Veriler yüklenirken hata oluştu: ';
                
                if (xhr.status === 500) {
                    errorMessage += 'Sunucu hatası (HTTP 500)';
                    console.error('Server error details:', xhr.responseText);
                } else if (xhr.status === 0) {
                    errorMessage += 'Bağlantı hatası';
                } else {
                    errorMessage += `HTTP ${xhr.status}: ${error}`;
                }
                
                showAlert('danger', errorMessage);
                displayPayments([]);
            }
        });
    }
    
    function displayPayments(payments) {
        const tbody = $('#paymentsTableBody');
        tbody.empty();
        
        if (!payments || payments.length === 0) {
            tbody.html(`
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                        <p class="text-muted">Hiç ödeme kaydı bulunamadı</p>
                    </td>
                </tr>
            `);
            return;
        }
        
        payments.forEach(function(payment) {
            const evrakLink = payment.evrak_dosya_adi ? 
                `<a href="${payment.evrak_dosya_yolu}" target="_blank" class="btn btn-sm btn-outline-info">
                    <i class="fas fa-file-alt"></i>
                </a>` : '<span class="text-muted">-</span>';
            
            const row = `
                <tr>
                    <td>
                        <div>
                            <strong>${payment.bayi_adi || 'N/A'}</strong><br>
                            <small class="text-muted">${payment.bayi_email || 'N/A'}</small>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background-color: ${payment.renk_kodu || '#6c757d'}">
                            <i class="fas ${payment.ikon || 'fa-money-bill'} me-1"></i>
                            ${payment.tur_adi || 'N/A'}
                        </span>
                    </td>
                    <td>
                        <strong class="${payment.odeme_tutari >= 0 ? 'text-success' : 'text-danger'}">
                            ₺${parseFloat(payment.odeme_tutari || 0).toLocaleString('tr-TR', {minimumFractionDigits: 2})}
                        </strong>
                    </td>
                    <td>${payment.odeme_tarihi ? new Date(payment.odeme_tarihi).toLocaleDateString('tr-TR') : 'N/A'}</td>
                    <td>
                        <span class="text-muted">${payment.referans_no || '-'}</span>
                    </td>
                    <td class="text-center">${evrakLink}</td>
                    <td>
                        <small class="text-muted">${payment.created_by || 'N/A'}</small><br>
                        <small class="text-muted">${payment.created_at ? new Date(payment.created_at).toLocaleDateString('tr-TR') : 'N/A'}</small>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            ${sayfaYetkileri.duzenle == 1 ? `
                            <button class="btn btn-sm btn-outline-primary" onclick="editPayment(${payment.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            ` : ''}
                            ${sayfaYetkileri.sil == 1 ? `
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePayment(${payment.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                            ` : ''}
                            ${sayfaYetkileri.duzenle == 0 && sayfaYetkileri.sil == 0 ? '<span class="badge bg-secondary">Yetki Yok</span>' : ''}
                        </div>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }
    
    function updateStatistics(totals) {
        // İstatistik kartlarını güncelle
        const toplamIslem = totals.toplam_islem || 0;
        const toplamOdeme = totals.toplam_odeme || 0;
        const toplamKesinti = totals.toplam_kesinti || 0;
        
        // Toplam İşlem kartını güncelle
        $('.col-md-3:nth-child(1) h3').text(number_format(toplamIslem));
        
        // Toplam Ödeme kartını güncelle
        $('.col-md-3:nth-child(2) h3').text('₺' + number_format(toplamOdeme, 2));
        
        // Toplam Kesinti kartını güncelle
        $('.col-md-3:nth-child(3) h3').text('₺' + number_format(toplamKesinti, 2));
    }
    
    function number_format(number, decimals = 0) {
        return parseFloat(number).toLocaleString('tr-TR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }
    
    function showAlert(type, message) {
        const alert = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        $('.container-fluid').prepend(alert);
        
        // 5 saniye sonra otomatik kapat
        setTimeout(function() {
            $('.alert').fadeOut();
        }, 5000);
    }
});

function editPayment(id) {
    // Mevcut modalı düzenleme için hazırla
    console.log('Edit payment:', id);
    
    $.ajax({
        url: '',
        type: 'POST',
        data: {
            action: 'get_payment_details',
            payment_id: id
        },
        dataType: 'json',
        success: function(response) {
            if (response && response.success) {
                const payment = response.data;
                
                // Modal başlığını ve buton metnini düzenle moduna çevir
                $('#paymentModalTitle').html('<i class="fas fa-edit me-2"></i>Ödeme Düzenle');
                $('#paymentSubmitBtn').html('<i class="fas fa-save me-2"></i>Güncelle');
                
                // Hidden payment ID'yi set et
                $('#paymentId').val(payment.id);
                
                // Form alanlarını doldur
                $('#addPaymentModal select[name="user_id"]').val(payment.user_id);
                $('#addPaymentModal select[name="odeme_turu_id"]').val(payment.odeme_turu_id);
                $('#addPaymentModal input[name="tutar"]').val(payment.odeme_tutari);
                
                // Ödeme tarihini doğru formatta set et
                let paymentDate = '';
                if (payment.odeme_tarihi) {
                    // SQL Server datetime formatını Y-m-d formatına çevir
                    const date = new Date(payment.odeme_tarihi);
                    if (!isNaN(date.getTime())) {
                        paymentDate = date.toISOString().split('T')[0]; // YYYY-MM-DD formatı
                    }
                }
                $('#addPaymentModal input[name="odeme_tarihi"]').val(paymentDate);
                
                $('#addPaymentModal input[name="referans_no"]').val(payment.referans_no || '');
                $('#addPaymentModal textarea[name="aciklama"]').val(payment.aciklama || '');
                
                // Abone no arama alanını gizle (düzenlemede gerek yok)
                $('#addPaymentModal .bg-light').hide();
                
                // Mevcut dosya bilgisini göster
                const currentFileDiv = $('#currentFile');
                const currentFileInfo = $('#currentFileInfo');
                
                if (payment.evrak_dosya_adi) {
                    currentFileDiv.html(`
                        <a href="${payment.evrak_dosya_yolu}" target="_blank" class="text-primary">
                            <i class="fas fa-file-alt me-1"></i>${payment.evrak_dosya_adi}
                        </a>
                        <small class="text-muted d-block">Yeni dosya seçerseniz eskisi değiştirilir</small>
                    `);
                    currentFileInfo.show();
                } else {
                    currentFileInfo.hide();
                }
                
                // Karakter sayacını güncelle
                $('textarea[name="aciklama"]').trigger('input');
                
                // Modal'ı aç
                $('#addPaymentModal').modal('show');
            } else {
                showAlert('danger', 'Ödeme bilgileri getirilemedi: ' + (response.message || 'Bilinmeyen hata'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Get payment details error:', error);
            showAlert('danger', 'Ödeme bilgileri getirilirken hata oluştu: ' + error);
        }
    });
}

function deletePayment(id) {
    if (confirm('Bu ödeme kaydını silmek istediğinizden emin misiniz?')) {
        // Silme işlemi
        console.log('Delete payment:', id);
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>
