# 📋 Değişiklik Geçmişi

Bu dosya projedeki tüm önemli değişiklikleri kronolojik olarak listeler.

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