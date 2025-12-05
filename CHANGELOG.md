# 📋 Değişiklik Geçmişi

Bu dosya projedeki tüm önemli değişiklikleri kronolojik olarak listeler.

## [v1.2.0] - 2025-12-04 - Yetki Sistemi ve Otomasyon Geliştirmeleri

### ✨ Yeni Özellikler
- **Cron Otomasyonları:** 3 adet otomatik düzeltme cron job'u
  - bbkAddressCode yenileme (her 30 dakika)
  - ResponseCode düzeltme (her 10 dakika)
  - E-mail otomatik tamamlama (her 5 dakika)
- **Bayi Modülleri:** 
  - Bayi tanımlama sayfası (users_bayi yönetimi)
  - IRIS rapor yükleme sistemi (CSV streaming)
  - Prim dönem tanımlama modülü
- **Görünürlük Kontrolü:** API başvuru durumlarında agent/back office filtresi
- **Token Yönetimi:** API kullanıcısı silme özelliği

### 🔧 Teknik İyileştirmeler
- Hierarchical permission system (recursive CTE queries)
- AJAX checkbox updates for visibility flags
- CSV streaming ile büyük dosya yükleme (memory optimized)
- SQL injection önlemleri (parametrized queries)

### 📁 Yeni/Güncellenen Dosyalar
- `api/cron_bbk_yenile.php` - bbkAddressCode hata düzeltme
- `api/cron_duzeltme.php` - ResponseCode reset otomasyonu
- `api/cron_email_duzelt.php` - E-mail otomatik tamamlama
- `views/Bayi/IrisRapor/bayi_tanimlama.php` - Bayi yönetimi
- `views/Bayi/IrisRapor/iris_rapor_yukle.php` - CSV yükleme
- `views/Bayi/Muhasebe/bayi_hakedis_prim_donem.php` - Dönem tanımlama
- `views/Bayi/api/basvurum_durum_ve_kimlik_turu_yonetimi.php` - Görünürlük kontrolleri
- `views/Bayi/api/kullanici_token_yonetimi.php` - Delete fonksiyonu

---

## [v1.1.0] - 2025-11-28 - Web Servis API Sistemi

### ✨ Yeni Özellikler
- **Web Servis API:** Swagger benzeri interaktif dokümantasyon arayüzü
- **HTTP Basic Auth:** RFC 7617 uyumlu kimlik doğrulama sistemi
- **API Endpoints:** 5 adet RESTful JSON endpoint
- **iframe Desteği:** Cross-origin başvuru formu entegrasyonu
- **VoIP Toplu Ekleme:** Pano ve Excel/CSV dosya desteği
- **Sippy Otomatik Çekim:** Harcama verilerini otomatik alma

### 🔧 Teknik İyileştirmeler
- Cross-origin session yönetimi (SameSite=None)
- Dynamic origin header desteği
- Memory ve performans optimizasyonu
- Format otomatik algılama sistemi

### 📁 Yeni Dosyalar
- `views/bayi/webservis/` - Web servis API sistemi
- `views/bayi/VoIP/sippy-otomatik-harcama-ekle.php` - Sippy entegrasyonu
- `.github/RELEASES/v1.1.0.md` - Detaylı sürüm notları

**Detaylı bilgi:** [v1.1.0 Release Notes](.github/RELEASES/v1.1.0.md)

---

## [v1.0.0] - 2025-11-22 - Başvuru Sistemi

### ✨ Yeni Özellikler
- Public başvuru formu (Neo & Satellite)
- Session yönetimi ve kampanya tespiti
- Debug araçları

---

## [v0.9.0] - 2025-10-21 - Yetki Sistemi

### ✨ Yeni Özellikler
- Sayfa bazlı yetkilendirme
- Admin bypass sistemi
- Kendi kaydını görme özelliği

---

## [v0.8.0] - 2025-09-01 - İris Rapor

### ✨ Yeni Özellikler
- CSV/Excel yükleme sistemi
- Rapor analizi ve karşılaştırma
- MSSQL entegrasyonu

---

**Format:** Bu changelog [Keep a Changelog](https://keepachangelog.com/) formatını takip eder.
**Versiyonlama:** [Semantic Versioning](https://semver.org/) kullanılır.