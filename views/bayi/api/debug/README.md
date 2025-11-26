# 🔍 Başvuru Sistemi Debug Araçları

Bu klasör, başvuru sistemi için debug ve test araçlarını içerir.

## 📁 Dosyalar

### 1. `session_debug.php`
**Session durumunu kontrol eder**

**Özellikler:**
- ✅ Session ID ve durumu
- ✅ Başvuru session değişkenleri (`basvuru_kimlik`, `basvuru_adres`, `basvuru_paket`, `basvuru_id`, `basvuru_params`)
- ✅ Tüm session içeriği
- ✅ Test linkleri (Neo, Satellite, Genel başvuru)
- ✅ Session temizleme butonu

**Kullanım:**
```
https://digiturk.ilekasoft.com/views/Bayi/api/debug/session_debug.php
```

---

### 2. `check_inserts.php`
**Veritabanına kaydedilen son başvuruları gösterir**

**Özellikler:**
- ✅ İstatistikler (Toplam, Bugün, Neo, Satellite, Belirsiz)
- ✅ Son N kayıt görüntüleme (20/50/100)
- ✅ Detaylı başvuru bilgileri
- ✅ Kampanya tip gösterimi (Neo/Satellite)
- ✅ NULL değer kontrolü

**Kullanım:**
```
https://digiturk.ilekasoft.com/views/Bayi/api/debug/check_inserts.php
https://digiturk.ilekasoft.com/views/Bayi/api/debug/check_inserts.php?limit=50
```

---

## 🚀 Kullanım Senaryoları

### Senaryo 1: Session Sorunu
1. `session_debug.php` aç
2. Session değişkenlerini kontrol et
3. Eksik olan değişkeni tespit et
4. Gerekirse session temizle ve yeni başla

### Senaryo 2: Kayıt Kontrol
1. `check_inserts.php` aç
2. Son kayıtlara bak
3. NULL değerleri tespit et
4. İstatistikleri incele

### Senaryo 3: Test Akışı
1. `session_debug.php` → Session temizle
2. Test linki ile başvuru başlat
3. Her adımda `session_debug.php` ile kontrol et
4. Sonunda `check_inserts.php` ile kayıt kontrol et

---

## 📊 Test Linkleri

### Neo Kampanya (Adres Atlanır)
```
https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=2
```

### Neo Kampanya + Direkt Paket (Adres ve Paket Atlanır)
```
https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=2&paket=37
```

### Satellite Kampanya (Normal Akış)
```
https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7&kampanya=1
```

### Genel Başvuru (Kampanya Seçilecek)
```
https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=7
```

---

## ⚠️ Önemli Notlar

1. **Güvenlik:** Bu debug araçları production ortamında YETKİ KONTROLÜ ile korunmalıdır!
2. **Session:** Session temizleme butonu tüm session'u siler, dikkatli kullanın
3. **Performans:** `check_inserts.php` limit parametresi ile kayıt sayısını kontrol edin
4. **Loglama:** Tüm işlemler PHP error_log'a kaydedilir

---

## 🔧 Bakım

**Oluşturma Tarihi:** 21 Kasım 2025  
**Geliştirici:** Batuhan Kahraman  
**Email:** batuhan.kahraman@ileka.com.tr  
**Telefon:** +90 501 357 10 85

---

## 📝 Değişiklik Geçmişi

### v1.0.0 (21.11.2025)
- İlk sürüm oluşturuldu
- session_debug.php: Session kontrolü ve temizleme
- check_inserts.php: Kayıt görüntüleme ve istatistikler
