# Versiyon Kaydı - Başvuru Sistemi

## v1.0.0 - Public Başvuru Sistemi (22 Kasım 2025)

### 🎯 Özellikler

#### 📝 Başvuru Sayfaları
1. **basvuru.php** - Kimlik Bilgileri Formu
   - Public erişim (auth/header/footer yok)
   - URL parametreleri: `api_ID`, `kampanya`, `paket`
   - Neo kampanya kontrolü
   - Otomatik bbkAddressCode üretimi (Neo için)
   - Session yönetimi
   - MSSQL INSERT işlemi

2. **basvuru-adres.php** - Adres Seçimi (Satellite için)
   - Cascade adres seçimi (API 3-10)
   - bbkAddressCode kaydı
   - Neo kampanyasında atlanır

3. **basvuru-paket.php** - Paket Seçimi
   - Neo/Satellite paket filtreleme
   - KOI bazlı filtreleme
   - Ödeme türü seçimi
   - Otomatik kampanya tespiti
   - KURAL 3: Paket parametresi varsa atla

4. **basvuru-tesekkurler.php** - Teşekkür/Başarı Sayfası
   - Başvuru özeti
   - Yeni başvuru linki

#### 🔄 Akış Mantığı

**Senaryo 1: Neo Kampanya (kampanya=2)**
```
basvuru.php → basvuru-paket.php → basvuru-tesekkurler.php
(Adres atlanır, otomatik bbkAddressCode)
```

**Senaryo 2: Neo + Paket (kampanya=2&paket=X)**
```
basvuru.php → basvuru-tesekkurler.php
(Adres ve paket atlanır)
```

**Senaryo 3: Satellite Kampanya (kampanya=1)**
```
basvuru.php → basvuru-adres.php → basvuru-paket.php → basvuru-tesekkurler.php
(Normal akış)
```

**Senaryo 4: Genel Başvuru (sadece api_ID)**
```
basvuru.php → basvuru-adres.php → basvuru-paket.php → basvuru-tesekkurler.php
(Kullanıcı kampanyayı paket seçerken belirler)
```

#### 📊 Veritabanı

**Tablo:** `[dbo].[API_basvuruListesi]`

**Kaydedilen Bilgiler:**
- Kimlik: firstName, surname, email, phone, birthDate, citizenNumber, genderType
- Adres: bbkAddressCode (Neo için otomatik, Satellite için seçimli)
- Başvuru: api_ID (kullanici_ID), kampanya_ID, paket_ID
- Sistem: kaynakSite, durum_ID, tarihler

**Özel Mantık:**
- Neo kampanya (ID=2): bbkAddressCode 130109-111069460 arası random unique
- Kampanya NULL ise: Paket seçiminde otomatik tespit (Neo/Satellite)
- Ödeme türü: Paket tablolarında tutulur (ayrı kolon yok)

#### 🐛 Debug Araçları

**Konum:** `views/Bayi/api/debug/`

1. **session_debug.php**
   - Session kontrolü
   - Session temizleme
   - Test linkleri

2. **check_inserts.php**
   - Son kayıtlar
   - İstatistikler
   - Kampanya dağılımı

3. **README.md**
   - Kullanım kılavuzu
   - Test senaryoları

### 🔧 Teknik Detaylar

#### Session Değişkenleri
```php
$_SESSION['basvuru_params'] = [
    'api_ID' => int,
    'kampanya' => int (1=Satellite, 2=Neo),
    'paket' => int
];

$_SESSION['basvuru_kimlik'] = [
    'firstName', 'surname', 'email', 
    'phoneAreaNumber', 'phoneNumber',
    'birthDate', 'citizenNumber', 'genderType',
    'identityCardType_ID', 'il_ID', 'il_code'
];

$_SESSION['basvuru_adres'] = [
    'county_code', 'quarter_code', 'street_code',
    'building_code', 'door_code', 'bbkAddressCode'
];

$_SESSION['basvuru_paket'] = [
    'paketId' => int,
    'odemeTuruId' => int,
    'kampanyaId' => int (otomatik tespit)
];

$_SESSION['basvuru_id'] = int; // INSERT sonrası ID
```

#### Önemli Fonksiyonlar
- `getDatabaseConnection()` - PDO bağlantısı
- Neo kampanya kontrolü - `$isNeo = ($kampanyaId == 2)`
- bbkAddressCode üretimi - Random unique check
- Kampanya otomatik tespit - Neo/Satellite tablolarında arama

### 🐛 Çözülen Hatalar

1. ✅ **Session kaybolma** - URL parametreleri POST'a eklendi
2. ✅ **basvuru_id boş** - SCOPE_IDENTITY fallback eklendi
3. ✅ **$conn undefined** - AJAX handler'larda bağlantı eklendi
4. ✅ **getDatabaseConnection() duplicate** - Fonksiyon çift tanım kaldırıldı
5. ✅ **Kampanya NULL kalma** - Paket seçiminde otomatik tespit eklendi
6. ✅ **Fatal error (500)** - KURAL 3 bloğu düzenlendi

### 📁 Dosya Yapısı

```
views/Bayi/api/
├── basvuru.php (35 KB)
├── basvuru-adres.php (29 KB)
├── basvuru-paket.php (47 KB)
├── basvuru-tesekkurler.php (12 KB)
└── debug/
    ├── session_debug.php
    ├── check_inserts.php
    └── README.md
```

### 🔐 Güvenlik Notları

⚠️ **Önemli:** Başvuru sayfaları PUBLIC erişime açık (auth kontrolü YOK)
- Sadece müşteri başvuruları için
- Admin işlemleri için farklı sayfalar kullanılmalı
- Debug araçları production'da yetki ile korunmalı

### 📚 Kullanım

**Test Linkleri:**
```
Neo: https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=2
Neo+Paket: https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=2&paket=37
Satellite: https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=1
Genel: https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7
```

**Debug:**
```
Session: https://digiturk.ilekasoft.com/views/Bayi/api/debug/session_debug.php
Kayıtlar: https://digiturk.ilekasoft.com/views/Bayi/api/debug/check_inserts.php
```

### 👨‍💻 Geliştirici

**Batuhan Kahraman**
- Email: batuhan.kahraman@ileka.com.tr
- Telefon: +90 501 357 10 85
- GitHub: https://github.com/Batuhan-Kahraman/

### 📅 Tarihçe

- **21 Kasım 2025** - Geliştirme başladı
- **21 Kasım 2025** - Kimlik, Adres, Paket sayfaları tamamlandı
- **21 Kasım 2025** - Session ve INSERT hataları çözüldü
- **21 Kasım 2025** - Kampanya otomatik tespit eklendi
- **21 Kasım 2025** - Debug araçları oluşturuldu
- **22 Kasım 2025** - v1.0.0 tamamlandı

---

## 🚀 Sonraki Adımlar

- [ ] Admin paneli entegrasyonu
- [ ] API gönderimi (Digiturk API)
- [ ] Email bildirimleri
- [ ] SMS bildirimleri
- [ ] Başvuru durumu takibi
- [ ] Raporlama ekranı
