# 🎯 Digiturk Yönetim Sistemi

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![MSSQL](https://img.shields.io/badge/Database-MSSQL-red)](https://www.microsoft.com/sql-server)
[![License](https://img.shields.io/badge/License-Private-yellow)](LICENSE)

Digiturk Bireysel Bayi Yönetim ve Otomasyon Platformu - Kapsamlı API entegrasyonu, hakediş yönetimi, VoIP operasyonları ve raporlama sistemi.

---

## 📋 İçindekiler

- [Özellikler](#-özellikler)
- [Gereksinimler](#-gereksinimler)
- [Kurulum](#-kurulum)
- [Konfigürasyon](#-konfigürasyon)
- [Modüller](#-modüller)
- [Veritabanı](#-veritabanı)
- [Cron Jobs](#-cron-jobs)
- [Güvenlik](#-güvenlik)
- [Kullanım](#-kullanım)
- [Dokümantasyon](#-dokümantasyon)
- [Destek](#-destek)

---

## ✨ Özellikler

### 🔗 Digiturk API Entegrasyonu
- ✅ Neo & Satellite kampanya yönetimi
- ✅ Otomatik token yenileme (Her 6 saatte)
- ✅ Başvuru formu sistemi (Public & Bayiye özel)
- ✅ Paket yönetimi ve KOI bazlı filtreleme
- ✅ Adres cascade seçimi (API 3-10)
- ✅ Link yönetimi (Bayilere özel başvuru linkleri)
- ✅ Slider ve kampanya görselleri
- ✅ Başvuru log ve takip sistemi

### 📊 İris Rapor Sistemi
- ✅ CSV/Excel dosya yükleme (Toplu veri aktarımı)
- ✅ Rapor analizi ve karşılaştırma
- ✅ Bayi günlük raporları
- ✅ Abone bulma ve sorgulama
- ✅ Otomatik backup sistemi

### 💰 Muhasebe & Hakediş
- ✅ Bayi hakediş hesaplama
- ✅ Prim dönem yönetimi
- ✅ Ödeme takibi ve yönetimi
- ✅ Hakediş tanımlama ve kurallar
- ✅ Ödeme türleri (Nakit, Havale, EFT)

### 📞 VoIP Yönetimi
- ✅ Operatör tanımlama
- ✅ Numara havuzu yönetimi
- ✅ Numara teslim takibi
- ✅ Harcama ve maliyet analizi

### 🔐 Yetkilendirme Sistemi
- ✅ Kullanıcı grupları (Admin, Bayi, Muhasebe)
- ✅ Sayfa bazlı yetkilendirme (Görme, Ekleme, Düzenleme, Silme)
- ✅ Kendi kaydını görme kısıtlaması
- ✅ Admin bypass (group_id=1 tam yetki)
- ✅ Modül & Menü yetki sistemi

### 🤖 Otomasyon
- ✅ Token otomatik yenileme (Cron)
- ✅ Başvuru otomatik gönderimi (Cron)
- ✅ Log yönetimi
- ✅ E-posta bildirimleri (SMTP)

---

## 🛠 Gereksinimler

### Sunucu Gereksinimleri
- **PHP:** 7.4 veya üzeri
- **Web Server:** IIS veya Apache
- **Database:** Microsoft SQL Server 2016+ (SQLEXPRESS destekli)
- **Extensions:**
  - PDO & PDO_SQLSRV
  - mbstring
  - curl
  - json
  - zip (Excel işlemleri için)

### Opsiyonel
- **Composer** (PHPMailer bağımlılıkları için)
- **Cron/Task Scheduler** (Otomasyon için)

---

## 📦 Kurulum

### 1️⃣ Projeyi İndirin

```bash
git clone https://github.com/TurkbelgeYazilim/digiturk-yonetim-sistemi.git
cd digiturk-yonetim-sistemi
```

### 2️⃣ Konfigürasyon Dosyalarını Oluşturun

#### Database Ayarları
```powershell
Copy-Item config/mssql.example.php config/mssql.php
```

`config/mssql.php` dosyasını düzenleyin:
```php
return [
    'url' => 'digiturk.example.com',
    'host' => 'localhost\\SQLEXPRESS',
    'database' => 'digiturk_bireysel_ilekasoft_DB',
    'username' => 'your_db_user',
    'password' => 'your_secure_password',
];
```

#### SMTP Ayarları
```powershell
Copy-Item config/smtp.example.php config/smtp.php
```

`config/smtp.php` dosyasını düzenleyin:
```php
return [
    'host' => 'smtp.gmail.com',
    'port' => 465,
    'username' => 'your-email@example.com',
    'password' => 'your-app-password',
    'from_email' => 'your-email@example.com',
    'from_name' => 'Digitürk İleka',
    'encryption' => 'ssl',
    'debug' => false,
];
```

#### Cron Güvenlik Anahtarı
```powershell
Copy-Item config/cron.example.php config/cron.php
```

`config/cron.php` dosyasını düzenleyin:
```php
return [
    'secret_key' => 'uzun-ve-güvenli-bir-anahtar-buraya',
];
```

### 3️⃣ Gerekli Klasörleri Oluşturun

```powershell
# Windows PowerShell
New-Item -Path "uploads" -ItemType Directory -Force
New-Item -Path "logs" -ItemType Directory -Force
New-Item -Path "temp" -ItemType Directory -Force
New-Item -Path "App_Data" -ItemType Directory -Force
```

### 4️⃣ Veritabanını Kurun

SQL Server Management Studio veya Azure Data Studio'da:

```sql
-- 1. Veritabanını oluştur
CREATE DATABASE digiturk_bireysel_ilekasoft_DB;

-- 2. Schema'yı import et
-- config/digiturk_bireysel_ilekasoft_DB.sql dosyasını çalıştırın
```

### 5️⃣ PHPMailer Kurulumu (Opsiyonel)

Composer varsa:
```bash
composer install
```

Composer yoksa, PHPMailer manuel olarak `includes/PHPMailer/` klasöründe.

### 6️⃣ Web Sunucu Ayarları

#### IIS için:
- Site'i IIS Manager'da ekleyin
- Anonymous Authentication'ı etkinleştirin
- PHP'yi FastCGI ile yapılandırın

#### Apache için:
`.htaccess` dosyası mevcut, `mod_rewrite` etkin olmalı.

---

## ⚙️ Konfigürasyon

### Dosya İzinleri

Aşağıdaki klasörlere yazma izni verin:

```
uploads/          (0755)
logs/             (0755)
temp/             (0755)
App_Data/         (0755)
```

### URL Yapısı

Sistem şu URL yapısını kullanır:
```
https://digiturk.example.com/
├── views/bayi/           # Bayi paneli
├── views/Yonetim/        # Yönetim paneli
└── api/                  # Cron jobs ve API endpoints
```

---

## 📚 Modüller

### 1. Başvuru Sistemi
**Dosyalar:** `views/bayi/api/basvuru*.php`

Public başvuru formu - Neo ve Satellite kampanyaları için otomatik akış.

**Test Linkleri:**
```
Neo: /views/bayi/api/basvuru.php?api_ID=7&kampanya=2
Neo+Paket: /views/bayi/api/basvuru.php?api_ID=7&kampanya=2&paket=37
Satellite: /views/bayi/api/basvuru.php?api_ID=7&kampanya=1
```

### 2. API Yönetimi
**Dosyalar:** `views/bayi/api/`

- Token yönetimi
- Kampanya yönetimi (Neo/Uydu)
- Link yönetimi
- Slider yönetimi

### 3. İris Rapor
**Dosyalar:** `views/Yonetim/IrisRapor/`, `views/bayi/IrisRapor/`

CSV/Excel yükleme ve analiz sistemi.

### 4. Muhasebe
**Dosyalar:** `views/bayi/Muhasebe/`

Hakediş hesaplama, tanımlama ve ödeme yönetimi.

### 5. VoIP
**Dosyalar:** `views/bayi/VoIP/`

Operatör, numara, teslim ve harcama yönetimi.

### 6. Tanımlar (Admin)
**Dosyalar:** `views/Yonetim/Tanimlar/`

Kullanıcı, grup, yetki ve dönem tanımlamaları.

---

## 🗄️ Veritabanı

### Ana Tablolar

| Tablo | Açıklama |
|-------|----------|
| `API_basvuruListesi` | Müşteri başvuruları |
| `API_kullanici` | API kullanıcıları ve tokenlar |
| `iris_rapor` | Yüklenen İris raporları |
| `primebaz_rapor` | Primebaz raporları |
| `bayi_hakedis_odeme` | Hakediş ödemeleri |
| `voip_operator_numara` | VoIP numara havuzu |
| `users` | Sistem kullanıcıları |
| `user_groups` | Kullanıcı grupları |
| `tanim_sayfalar` | Sayfa tanımları |
| `tanim_sayfa_yetkiler` | Sayfa yetkileri |

### Schema

Tam veritabanı şeması: `config/digiturk_bireysel_ilekasoft_DB.sql`

---

## ⏰ Cron Jobs

### 1. Token Otomatik Yenileme

**Dosya:** `api/cron_token_yenile.php`

**Zamanlama:** Her 6 saatte bir

**Plesk Ayarı:**
```bash
0 */6 * * * curl "https://digiturk.example.com/api/cron_token_yenile.php?key=YOUR_SECRET_KEY"
```

**Manuel Test:**
```bash
php api/cron_token_yenile.php
```

### 2. Başvuru Otomatik Gönderimi

**Dosya:** `api/cron_basvuru_gonder.php`

**Zamanlama:** Her 5 dakika

**Plesk Ayarı:**
```bash
*/5 * * * * curl "https://digiturk.example.com/api/cron_basvuru_gonder.php?key=YOUR_SECRET_KEY"
```

**Manuel Test:**
```bash
php api/cron_basvuru_gonder.php
```

### Log Dosyaları

```
logs/cron_token_log.txt       # Token yenileme logları
logs/cron_basvuru_log.txt     # Başvuru gönderim logları
```

**Detaylı kurulum:** `api/CRON_KURULUM.md`

---

## 🔒 Güvenlik

### Hassas Dosyalar

Aşağıdaki dosyalar `.gitignore`'da ve GitHub'a gönderilmez:

```
config/mssql.php              # Veritabanı şifreleri
config/smtp.php               # E-posta şifreleri
config/cron.php               # Cron secret key
uploads/*.csv                 # Müşteri verileri
logs/*.txt                    # Log dosyaları
```

### Güvenlik Kontrolleri

- ✅ SQL Injection koruması (Prepared Statements)
- ✅ XSS koruması (htmlspecialchars)
- ✅ CSRF token (Form işlemlerinde)
- ✅ Session güvenliği
- ✅ Cron job secret key kontrolü
- ✅ Sayfa bazlı yetkilendirme
- ✅ Admin bypass sistemi

### Önerilen Ayarlar

`php.ini` dosyasında:
```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M
```

---

## 🚀 Kullanım

### İlk Giriş

1. Tarayıcıda `https://digiturk.example.com/` adresine gidin
2. Varsayılan admin hesabı ile giriş yapın:
   - **Kullanıcı:** admin
   - **Şifre:** (Veritabanında tanımlı)

### Yeni Kullanıcı Ekleme

`views/Yonetim/Tanimlar/users.php` sayfasından:
1. "Yeni Kullanıcı Ekle"
2. Kullanıcı grubu seçin (Admin, Bayi, Muhasebe)
3. Kaydedin

### Yetki Tanımlama

`views/Yonetim/Tanimlar/sayfa_tanim_yetkileri.php` sayfasından:
1. Sayfa seçin
2. Kullanıcı grubu seçin
3. Yetkileri ayarlayın (Görme, Ekleme, Düzenleme, Silme)

### İris Rapor Yükleme

`views/Yonetim/IrisRapor/iris_rapor_yukle.php` sayfasından:
1. CSV/Excel dosyasını seçin
2. "Yükle" butonuna tıklayın
3. İşlem tamamlandığında sonuçları görün

---

## 📖 Dokümantasyon

Proje içindeki detaylı dokümantasyon:

| Dosya | Açıklama |
|-------|----------|
| `README.md` | Genel kurulum ve kullanım (bu dosya) |
| `README_IRIS_RAPOR.md` | İris Rapor sistemi (eski README) |
| `VERSIYON_v1.0.0_Basvuru_Sistemi.md` | Başvuru sistemi detayları |
| `YETKI_SISTEMI_DOKUMANTASYON.md` | Yetki sistemi kılavuzu |
| `api/CRON_KURULUM.md` | Cron job kurulum rehberi |
| `views/bayi/api/BASVURU_SAYFA_NOTLARI.md` | Başvuru sayfası notları |

---

## 🐛 Sorun Giderme

### Veritabanı Bağlantı Hatası

```
PDO::__construct(): could not find driver
```

**Çözüm:** SQL Server için PHP PDO driver'larını yükleyin:
- Windows: `php_pdo_sqlsrv.dll` extension'ını etkinleştirin
- Linux: `pdo_sqlsrv` paketini yükleyin

### Token Yenilenmiyor

**Kontroller:**
1. Cron job çalışıyor mu? → Plesk'te "History" kontrol edin
2. Log dosyası oluşuyor mu? → `logs/cron_token_log.txt`
3. API kullanıcı bilgileri doğru mu? → `API_kullanici` tablosu

### Dosya Yüklenmiyor

**Kontroller:**
1. Klasör izinleri → `uploads/` yazılabilir mi?
2. PHP upload limitleri → `upload_max_filesize`, `post_max_size`
3. Dosya formatı → CSV, XLSX destekleniyor

---

## 🤝 Katkıda Bulunma

Bu proje private bir projedir. 

Öneriler ve hata bildirimleri için:
- Issue açın
- Pull request gönderin

---

## 👨‍💻 Geliştirici

**Batuhan Kahraman**
- 📧 Email: batuhan.kahraman@ileka.com.tr
- 📱 Telefon: +90 501 357 10 85
- 🐙 GitHub: [@Batuhan-Kahraman](https://github.com/Batuhan-Kahraman)

---

## 📜 Lisans

Bu proje özel mülkiyettedir.

Yetkisiz kullanım, kopyalama veya dağıtım yasaktır.

---

## 📅 Versiyon Geçmişi

### v1.0.0 - Başvuru Sistemi (22 Kasım 2025)
- ✅ Public başvuru formu (Neo & Satellite)
- ✅ Session yönetimi
- ✅ Otomatik kampanya tespiti
- ✅ Debug araçları

### v0.9.0 - Yetki Sistemi (21 Ekim 2025)
- ✅ Sayfa bazlı yetkilendirme
- ✅ Admin bypass
- ✅ Kendi kaydını görme

### v0.8.0 - İris Rapor (Eylül 2025)
- ✅ CSV/Excel yükleme
- ✅ Rapor analizi
- ✅ Karşılaştırma sistemi

---

## 🙏 Teşekkürler

- **Digiturk** - API desteği
- **iLEKA Yazılım** - Proje desteği

---

<div align="center">

**Made with ❤️ for iLEKA **

[🔝 Yukarı Çık](#-digiturk-yönetim-sistemi)

</div>
