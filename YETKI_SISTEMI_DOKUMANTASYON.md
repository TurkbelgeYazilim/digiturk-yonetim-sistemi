# 🔐 Sayfa Yetkilendirme Sistemi Dokümantasyonu

## 📋 İçindekiler
1. [Genel Bakış](#genel-bakış)
2. [Kurulum](#kurulum)
3. [Yetki Değerleri](#yetki-değerleri)
4. [Kullanım Örnekleri](#kullanım-örnekleri)
5. [Admin Kontrolü](#admin-kontrolü)
6. [Veritabanı Yapısı](#veritabanı-yapısı)
7. [Checklist](#checklist)

---

## 🎯 Genel Bakış

Bu sistem, sayfaların kullanıcı gruplarına göre yetkilendirilmesini sağlar.

**Ana Özellikler:**
- ✅ Sayfa bazlı erişim kontrolü
- ✅ Kullanıcı grubu bazlı yetkilendirme
- ✅ Admin bypass (group_id = 1)
- ✅ Kendi kayıtlarını görme kısıtı
- ✅ Ekleme, düzenleme, silme yetkileri

---

## 🔧 Kurulum

### Adım 1: Auth Kontrolü ve Yetki Sistemi Kodu

Her sayfanın başına (header'dan önce) aşağıdaki kodu ekleyin:

```php
<?php
$pageTitle = "Sayfa Başlığı";
$breadcrumbs = [
    ['title' => 'Sayfa Başlığı']
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

// Buradan sonra sayfa kodları devam eder...
?>
```

### Adım 2: Path Ayarlaması

Farklı klasör yapılarına göre path'leri düzenleyin:

| Klasör Seviyesi | Path |
|----------------|------|
| `/views/Yonetim/Muhasebe/` | `../../../` |
| `/views/Yonetim/` | `../../` |
| `/views/` | `../` |

---

## 📊 Yetki Değerleri

### Yetki Tablosu

| Yetki | Değer | Anlamı | Kullanım Alanı |
|-------|-------|--------|----------------|
| **gor** | 1 | ✅ Sayfaya girebilir | Sayfa erişim kontrolü |
| **gor** | 0 | ❌ Sayfaya giremez | Redirect yapılır |
| **kendi_kullanicini_gor** | 1 | 👤 Sadece kendi kayıtlarını görür | SQL filtreleme, dropdown disable |
| **kendi_kullanicini_gor** | 0 | 👥 Herkesi görür | Tam erişim |
| **ekle** | 1 | ✅ Ekleme yapabilir | "Yeni Ekle" butonu |
| **ekle** | 0 | ❌ Ekleme yapamaz | Buton gizlenir |
| **duzenle** | 1 | ✅ Düzenleme yapabilir | Edit butonu, Export, Yazdır |
| **duzenle** | 0 | ❌ Düzenleme yapamaz | Buton gizlenir |
| **sil** | 1 | ✅ Silme yapabilir | Delete butonu |
| **sil** | 0 | ❌ Silme yapamaz | Buton gizlenir |

### Değer Mantığı

```
1 = Aktif / İzinli / Yapabilir
0 = Pasif / İzinsiz / Yapamaz
```

**ÖNEMLİ:** `kendi_kullanicini_gor` tersi mantıkta çalışır:
- `1` = Kısıtlı (sadece kendini görür)
- `0` = Serbest (herkesi görür)

---

## 🎯 Kullanım Örnekleri

### 1️⃣ Buton Göster/Gizle

#### Yeni Ekleme Butonu
```php
<?php if ($sayfaYetkileri['ekle'] == 1): ?>
<button class="btn btn-primary" onclick="openNewModal()">
    <i class="fas fa-plus me-1"></i>Yeni Ekle
</button>
<?php endif; ?>
```

#### Export ve Yazdır Butonları
```php
<?php if ($sayfaYetkileri['duzenle'] == 1): ?>
<button class="btn btn-outline-primary" onclick="exportToExcel()">
    <i class="fas fa-file-excel me-1"></i>Excel'e Aktar
</button>
<button class="btn btn-outline-secondary" onclick="printReport()">
    <i class="fas fa-print me-1"></i>Yazdır
</button>
<?php endif; ?>
```

### 2️⃣ Dropdown Aktif/Pasif Yapma

#### Bayi Seçimi Dropdown
```php
<select class="form-select" id="bayi_ad_soyad" name="bayi_ad_soyad" 
    <?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>>
    <option value="">Tümü</option>
    <?php foreach ($bayiAdSoyadOptions as $id => $bayiData): ?>
        <?php 
        // kendi_kullanicini_gor = 1 ise sadece kendi kaydını göster
        if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1 && $id != $currentUser['id']) {
            continue;
        }
        ?>
        <option value="<?php echo htmlspecialchars($id); ?>">
            <?php echo htmlspecialchars($bayiData['full_name']); ?>
        </option>
    <?php endforeach; ?>
</select>

<?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1): ?>
    <!-- Kısıtlı kullanıcı için hidden input ile değeri gönder -->
    <input type="hidden" name="bayi_ad_soyad" value="<?php echo htmlspecialchars($currentUser['id']); ?>">
    <small class="text-muted">
        <i class="fas fa-info-circle"></i> Sadece kendi kayıtlarınızı görüntüleyebilirsiniz
    </small>
<?php endif; ?>
```

### 3️⃣ SQL Veri Filtreleme

#### Kayıt Listeleme
```php
// kendi_kullanicini_gor = 1 ise sadece kendi kayıtlarını getir
if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
    $sql = "SELECT * FROM tablo WHERE user_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$currentUser['id']]);
} else {
    // kendi_kullanicini_gor = 0 ise tüm kayıtları getir
    $sql = "SELECT * FROM tablo ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
}
```

#### Bayi Listesi Çekme
```php
try {
    $conn = getDatabaseConnection();
    
    // kendi_kullanicini_gor = 1 ise sadece kendi kaydını çek
    if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1) {
        $bayiSql = "SELECT id, first_name, last_name, email 
                   FROM users 
                   WHERE id = ? AND status = 'AKTIF'";
        $bayiStmt = $conn->prepare($bayiSql);
        $bayiStmt->execute([$currentUser['id']]);
        $bayiler = $bayiStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // kendi_kullanicini_gor = 0 ise tüm bayileri göster
        $bayiSql = "SELECT id, first_name, last_name, email 
                   FROM users 
                   WHERE user_group_id IN (SELECT id FROM user_groups WHERE group_name IN ('bayi', 'Bayi'))
                   AND status = 'AKTIF'
                   ORDER BY first_name, last_name";
        $bayiStmt = $conn->prepare($bayiSql);
        $bayiStmt->execute();
        $bayiler = $bayiStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $bayiler = [];
}
```

### 4️⃣ Tablo İşlem Butonları

#### Düzenle ve Sil Butonları
```php
<td>
    <div class="btn-group" role="group">
        <?php if ($sayfaYetkileri['duzenle'] == 1): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" 
                onclick="editRecord(<?php echo $row['id']; ?>)">
            <i class="fas fa-edit"></i>
        </button>
        <?php endif; ?>
        
        <?php if ($sayfaYetkileri['sil'] == 1): ?>
        <button type="button" class="btn btn-sm btn-outline-danger" 
                onclick="deleteRecord(<?php echo $row['id']; ?>)">
            <i class="fas fa-trash"></i>
        </button>
        <?php endif; ?>
        
        <?php if ($sayfaYetkileri['duzenle'] == 0 && $sayfaYetkileri['sil'] == 0): ?>
        <span class="badge bg-secondary">Yetki Yok</span>
        <?php endif; ?>
    </div>
</td>
```

### 5️⃣ Filtre Dropdown'ları

#### Tablo Filtre Satırı
```php
<tr class="filter-row">
    <th>
        <select class="form-select form-select-sm table-filter" data-column="1" 
                <?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>>
            <?php if ($sayfaYetkileri['kendi_kullanicini_gor'] == 1 && !empty($bayiler)): ?>
                <option value="<?php echo $bayiler[0]['id']; ?>" selected>
                    <?php echo htmlspecialchars($bayiler[0]['first_name'] . ' ' . $bayiler[0]['last_name']); ?>
                </option>
            <?php else: ?>
                <option value="">Tüm Bayiler</option>
                <?php foreach ($filterData['bayiler'] as $bayiId => $bayiName): ?>
                    <option value="<?php echo $bayiId; ?>">
                        <?php echo htmlspecialchars($bayiName); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </th>
</tr>
```

### 6️⃣ Form Submit Kontrolü

#### Ekleme/Düzenleme İzni
```php
// Hakediş tanım işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            // Ekleme yetkisi kontrolü
            if ($sayfaYetkileri['ekle'] != 1) {
                $message = 'Ekleme yetkiniz bulunmamaktadır.';
                $messageType = 'danger';
                break;
            }
            // ... ekleme kodu
            break;
            
        case 'edit':
            // Düzenleme yetkisi kontrolü
            if ($sayfaYetkileri['duzenle'] != 1) {
                $message = 'Düzenleme yetkiniz bulunmamaktadır.';
                $messageType = 'danger';
                break;
            }
            // ... düzenleme kodu
            break;
            
        case 'delete':
            // Silme yetkisi kontrolü
            if ($sayfaYetkileri['sil'] != 1) {
                $message = 'Silme yetkiniz bulunmamaktadır.';
                $messageType = 'danger';
                break;
            }
            // ... silme kodu
            break;
    }
}
```

---

## 🔑 Admin Kontrolü

### Admin Bypass Sistemi

**Admin kullanıcılar** (`group_id = 1`) için tüm yetki kontrolleri otomatik olarak bypass edilir:

```php
// Admin kontrolü (group_id = 1 ise tüm yetkilere sahip)
$isAdmin = ($currentUser['group_id'] == 1);

if ($isAdmin) {
    // Admin için tüm yetkileri aç
    $sayfaYetkileri = [
        'gor' => 1,              // Sayfaya girebilir
        'kendi_kullanicini_gor' => 0,  // 0 = Herkesi görebilir
        'ekle' => 1,             // Ekleyebilir
        'duzenle' => 1,          // Düzenleyebilir
        'sil' => 1               // Silebilir
    ];
}
```

### Admin Özellikleri

✅ Tüm sayfalara erişebilir  
✅ Tüm kullanıcıların kayıtlarını görebilir  
✅ Ekleme, düzenleme, silme yapabilir  
✅ Veritabanında yetki tanımı olmasa bile erişir  
✅ Excel export, yazdırma gibi tüm özellikleri kullanabilir  

---

## 🗄️ Veritabanı Yapısı

### 1. `tanim_sayfalar` Tablosu
Sistem sayfalarının listesi

```sql
CREATE TABLE tanim_sayfalar (
    sayfa_id INT PRIMARY KEY IDENTITY(1,1),
    menu_id INT,
    sayfa_adi NVARCHAR(100),
    sayfa_url NVARCHAR(200),  -- Örn: bayi_hakedis_hesapla.php
    aciklama NVARCHAR(500),
    durum BIT DEFAULT 1,       -- 1 = Aktif, 0 = Pasif
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE(),
    sira_no INT,
    sayfa_ikon NVARCHAR(50)
)
```

### 2. `tanim_sayfa_yetkiler` Tablosu
Sayfa bazlı yetki tanımları

```sql
CREATE TABLE tanim_sayfa_yetkiler (
    sayfa_yetki_id INT PRIMARY KEY IDENTITY(1,1),
    sayfa_id INT,                    -- tanim_sayfalar ile ilişki
    user_group_id INT,               -- user_groups ile ilişki
    gor BIT DEFAULT 0,               -- 1 = Görür, 0 = Göremez
    kendi_kullanicini_gor BIT DEFAULT 0,  -- 1 = Sadece kendi, 0 = Herkes
    ekle BIT DEFAULT 0,              -- 1 = Ekler, 0 = Eklemez
    duzenle BIT DEFAULT 0,           -- 1 = Düzenler, 0 = Düzenlemez
    sil BIT DEFAULT 0,               -- 1 = Siler, 0 = Silmez
    durum BIT DEFAULT 1,             -- 1 = Aktif, 0 = Pasif
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE()
)
```

### 3. `user_groups` Tablosu
Kullanıcı grupları

```sql
CREATE TABLE user_groups (
    id INT PRIMARY KEY IDENTITY(1,1),
    group_name NVARCHAR(50),         -- Örn: Admin, Bayi, Muhasebe
    group_description NVARCHAR(200),
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE()
)
```

### Örnek Veri Ekleme

#### Sayfa Ekleme
```sql
INSERT INTO tanim_sayfalar (sayfa_adi, sayfa_url, durum)
VALUES ('Bayi Hakediş Hesaplama', 'bayi_hakedis_hesapla.php', 1)
```

#### Yetki Ekleme (Admin - Tüm Yetkiler)
```sql
INSERT INTO tanim_sayfa_yetkiler (sayfa_id, user_group_id, gor, kendi_kullanicini_gor, ekle, duzenle, sil, durum)
VALUES (1, 1, 1, 0, 1, 1, 1, 1)  -- Admin grubu (group_id=1) için
```

#### Yetki Ekleme (Bayi - Kısıtlı)
```sql
INSERT INTO tanim_sayfa_yetkiler (sayfa_id, user_group_id, gor, kendi_kullanicini_gor, ekle, duzenle, sil, durum)
VALUES (1, 3, 1, 1, 1, 1, 0, 1)  -- Bayi grubu (group_id=3) için
-- gor=1 (Görür), kendi_kullanicini_gor=1 (Sadece kendini), ekle=1, duzenle=1, sil=0
```

---

## ✅ Checklist (Her Sayfa İçin)

### Temel Kurulum
- [ ] Auth kontrol kodu eklendi
- [ ] Yetki sistemi kodu eklendi
- [ ] `getDatabaseConnection()` çağrıldı
- [ ] `$currentPageUrl = basename($_SERVER['PHP_SELF'])` tanımlandı

### Admin Kontrolü
- [ ] `$isAdmin = ($currentUser['group_id'] == 1)` kontrolü yapıldı
- [ ] Admin için tüm yetkiler otomatik açıldı

### Veritabanı Kontrolleri
- [ ] `tanim_sayfalar` tablosunda sayfa kaydı var
- [ ] `tanim_sayfa_yetkiler` tablosunda grup yetkileri tanımlı
- [ ] `sayfa_url` dosya adı ile eşleşiyor

### UI Kontrolleri
- [ ] "Yeni Ekle" butonuna `ekle` yetkisi eklendi
- [ ] Dropdown'lara `kendi_kullanicini_gor` kontrolü eklendi
- [ ] Düzenle butonuna `duzenle` yetkisi eklendi
- [ ] Sil butonuna `sil` yetkisi eklendi
- [ ] Export/yazdır butonlarına `duzenle` yetkisi eklendi
- [ ] Filtre dropdown'larına `kendi_kullanicini_gor` kontrolü eklendi

### SQL Kontrolleri
- [ ] Kayıt listeleme sorgusuna `kendi_kullanicini_gor` filtresi eklendi
- [ ] Bayi listesi sorgusuna `kendi_kullanicini_gor` filtresi eklendi
- [ ] Form submit işlemlerine yetki kontrolü eklendi

### Test Senaryoları
- [ ] Admin olarak giriş yapıp tüm işlemleri test et
- [ ] Kısıtlı kullanıcı ile sadece kendi kayıtlarını görebildiğini test et
- [ ] Yetkisiz kullanıcı ile sayfaya erişemediğini test et
- [ ] Butonların yetkiye göre göründüğünü/gizlendiğini test et

---

## 🔍 Hata Ayıklama

### Yaygın Hatalar ve Çözümleri

#### 1. "Yetki tanımlanmamış" Hatası
**Sebep:** Veritabanında sayfa veya yetki kaydı yok  
**Çözüm:**
```sql
-- Sayfa var mı kontrol et
SELECT * FROM tanim_sayfalar WHERE sayfa_url = 'dosya_adi.php'

-- Yetki var mı kontrol et
SELECT * FROM tanim_sayfa_yetkiler 
WHERE sayfa_id = X AND user_group_id = Y
```

#### 2. Admin Erişemiyor
**Sebep:** `group_id` kontrolü yanlış  
**Çözüm:**
```php
// Kullanıcının group_id'sini kontrol et
var_dump($currentUser['group_id']);

// Admin kontrolünü doğrula
$isAdmin = ($currentUser['group_id'] == 1);  // 1 olmalı
```

#### 3. Dropdown Çalışmıyor
**Sebep:** `kendi_kullanicini_gor` mantığı ters  
**Çözüm:**
```php
// 1 = Kısıtlı (disabled olmalı)
// 0 = Serbest (enabled olmalı)
<?php echo $sayfaYetkileri['kendi_kullanicini_gor'] == 1 ? 'disabled' : ''; ?>
```

---

## 📝 Notlar

### Önemli Hatırlatmalar

1. **Path Ayarları:** Her sayfa için doğru path kullanın (`../../../` vs `../../`)
2. **SQL Injection:** Tüm SQL sorgularında prepared statement kullanın
3. **Error Log:** Hataları `error_log()` ile kaydedin
4. **Security:** Yetki kontrolü hem frontend hem backend'de olmalı
5. **Test:** Her yetki senaryosunu mutlaka test edin

### Güvenlik İpuçları

- ✅ Yetki kontrolünü her zaman backend'de yapın
- ✅ Frontend kontrolü sadece UX içindir, güvenlik için değil
- ✅ Admin bypass kodunu her sayfada uygulayın
- ✅ Yetki tanımı yoksa erişimi reddedin
- ✅ Hataları kullanıcıya detaylı göstermeyin

---

## 📞 Destek

Sorularınız için:
- **Geliştirici:** Batuhan Kahraman
- **E-posta:** batuhan.kahraman@ileka.com.tr
- **Telefon:** +90 501 357 10 85

---

**Son Güncelleme:** 21 Ekim 2025  
**Versiyon:** 1.0.0
