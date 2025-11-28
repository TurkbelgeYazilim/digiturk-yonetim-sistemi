---
applyTo: '**'
---
-- [dbo].[user_groups].[id]=1 olan kullanıcılar tüm sayfalarda tam yetkiye sahip olmalı
-- [dbo].[tanim_sayfa_yetkiler].[kendi_kullanicini_gor]=1 ise, kullanıcı sadece kendi bilgilerini görebilmeli yani filtre alanında kullanıcı seçimi pasif olmalı ve sadece kendi kullanıcı bilgileri listelenmeli
-- Debug sadece [dbo].[user_groups].[id]=1 olan kullanıcılarda aktif olmalı

## 📁 Dokümantasyon Organizasyonu

### Ana dizin (./)
- `README.md` - Proje ana tanıtımı ve hızlı başlangıç
- `CHANGELOG.md` - Kısa versiyon geçmişi ve özet

### .github/ klasörü
- `.github/RELEASES/` - Detaylı versiyon notları (v1.1.0.md vb.)
- `.github/docs/` - Teknik dokümantasyon
- `.github/instructions/` - Proje geliştirme talimatları
- `.github/workflows/` - CI/CD pipeline'ları

### Kural: 
Ana dizinde maksimum 2-3 .md dosyası tutulmalı, detaylı dokümantasyon .github/ altında organize edilmeli.