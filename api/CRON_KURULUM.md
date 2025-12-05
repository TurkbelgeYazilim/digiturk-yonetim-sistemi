# Cron Job Kurulum Dokümantasyonu

## 📋 Mevcut Cron Job'lar

| Cron Job | Çalışma Sıklığı | Açıklama |
|----------|----------------|----------|
| `cron_token_yenile.php` | Her 6 saat | Token'ları otomatik yeniler |
| `cron_email_duzelt.php` | Manuel | E-posta sorunlarını düzeltir |
| `cron_basvuru_gonder.php` | Her 5 dakika | API'ye başvuru gönderir |
| `cron_bbk_yenile.php` | Her 30 dakika | bbkAddressCode hatalarını temizler |

---

## 1️⃣ Token Otomatik Yenileme

### Plesk Cron Job Ayarları

### 1. Plesk Paneline Giriş
- Plesk paneline giriş yapın
- İlgili domain'i seçin (digiturk.ilekasoft.com)

### 2. Zamanlanmış Görevler (Scheduled Tasks)
- Sol menüden **"Scheduled Tasks"** veya **"Zamanlanmış Görevler"** seçeneğine tıklayın
- **"Add Task"** veya **"Görev Ekle"** butonuna tıklayın

### 3. Cron Job Ayarları

#### Yöntem 1: URL ile Çalıştırma (Önerilen - Plesk için)

**Komut:**
```bash
curl "https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025"
```

veya

```bash
wget -q -O - "https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025"
```

#### Yöntem 2: PHP CLI ile Çalıştırma (Alternatif)

**Komut:**
```bash
cd d:\inetpub\ilekasoft.com\digiturk.ilekasoft.com\api && php cron_token_yenile.php
```

#### Zamanlama (Her 6 Saatte Bir):
- **Dakika:** 0
- **Saat:** */6 (veya: 0,6,12,18)
- **Gün:** *
- **Ay:** *
- **Haftanın Günü:** *

**Alternatif Zamanlama Seçenekleri:**

1. **Sabah 00:00, 06:00, 12:00, 18:00**
   - Dakika: 0
   - Saat: 0,6,12,18
   
2. **Her 6 saatte bir (otomatik)**
   - Dakika: 0
   - Saat: */6

#### Cron Formatı:
```
0 */6 * * * curl "https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025"
```

### 4. E-posta Bildirimleri (İsteğe Bağlı)
- Hata durumunda e-posta almak isterseniz e-posta adresinizi girin
- İstemiyorsanız boş bırakın

### 5. Kaydet
- **"OK"** veya **"Kaydet"** butonuna tıklayın

---

## Manuel Test

Cron job'u kurmadan önce manuel olarak test edebilirsiniz:

### Tarayıcıdan Test (Önerilen):
```
https://digiturk.ilekasoft.com/api/cron_token_yenile.php?key=CRON_SECRET_KEY_2025
```

**Not:** Güvenlik için key parametresi zorunludur. Key'i değiştirmek için `cron_token_yenile.php` dosyasındaki `$secretKey` değişkenini güncelleyin.

### PowerShell ile Test:
```powershell
cd d:\inetpub\ilekasoft.com\digiturk.ilekasoft.com\api
php cron_token_yenile.php
```

### Log Dosyası Kontrolü:
```powershell
cat d:\inetpub\ilekasoft.com\digiturk.ilekasoft.com\temp\cron_token_log.txt
```

---

## Nasıl Çalışır?

### 1. Cron Job (Sunucu Tarafı)
- Her 6 saatte bir otomatik çalışır
- Sayfa açık olmasa bile çalışır
- Tüm aktif kullanıcıların tokenlarını kontrol eder
- Son güncelleme 6 saatten eskiyse yeniler
- Tüm işlemleri `temp/cron_token_log.txt` dosyasına loglar

### 2. Otomatik Yenileme (Sayfa Tarafı)
- Kullanıcı sayfayı açtığında çalışır
- İlk yükleme 5 dakika sonra kontrol eder
- Sonra her 6 saatte bir otomatik kontrol yapar
- Sadece düzenleme yetkisi olan kullanıcılar için çalışır

---

## Log Takibi

Log dosyası: `d:\inetpub\ilekasoft.com\digiturk.ilekasoft.com\temp\cron_token_log.txt`

### Log İçeriği:
```
[2025-11-11 14:30:00] === Token Yenileme Başladı ===
[2025-11-11 14:30:01] Veritabanı bağlantısı başarılı
[2025-11-11 14:30:01] 5 aktif kullanıcı bulundu
[2025-11-11 14:30:01] API URL: https://api.example.com/token
[2025-11-11 14:30:01] ---
[2025-11-11 14:30:01] İşleniyor: ID=1, Org=ORG123, Login=user1
[2025-11-11 14:30:01] Son güncelleme: 2025-11-11 08:15:00 (6.25 saat önce)
[2025-11-11 14:30:02] HTTP Kodu: 200
[2025-11-11 14:30:02] BAŞARILI: Token yenilendi - eyJhbGciOiJIUzI1NiI...
[2025-11-11 14:30:02] ---
[2025-11-11 14:30:05] === İşlem Tamamlandı ===
[2025-11-11 14:30:05] Toplam: 5 kullanıcı
[2025-11-11 14:30:05] Başarılı: 3
[2025-11-11 14:30:05] Hatalı: 1
[2025-11-11 14:30:05] Atlandı: 1
```

---

## Sorun Giderme

### 1. Cron Job Çalışmıyor
- Plesk panelinde "History" veya "Geçmiş" sekmesinden cron job loglarını kontrol edin
- PHP path'ini kontrol edin: `php -v` komutu ile test edin
- Klasör yolunun doğru olduğundan emin olun

### 2. Token Yenilenmiyor
- `temp/cron_token_log.txt` dosyasını kontrol edin
- API URL'inin doğru olduğunu kontrol edin (API_Link tablosu)
- API kullanıcı bilgilerinin doğru olduğunu kontrol edin

### 3. Log Dosyası Oluşmuyor
- `temp` klasörünün yazma izinleri olduğundan emin olun
- Manuel olarak dosya oluşturun: `New-Item -Path "temp\cron_token_log.txt" -ItemType File`

### 4. Tüm Kullanıcılar Atlanıyor
- Son güncelleme tarihleri 6 saatten yeni olabilir
- Log dosyasında "Son güncelleme" satırlarını kontrol edin

---

## 2️⃣ Otomatik Başvuru Gönderimi

### Plesk Cron Job Ayarları

**Komut:**
```bash
curl "https://digiturk.ilekasoft.com/api/cron_basvuru_gonder.php?key=CRON_SECRET_KEY_2025"
```

**Zamanlama: Her 5 dakika**
- **Dakika:** 0,5,10,15,20,25,30,35,40,45,50,55
- **Saat:** *
- **Gün:** *
- **Ay:** *
- **Haftanın Günü:** *

**Cron Formatı:**
```
0,5,10,15,20,25,30,35,40,45,50,55 * * * * curl "https://digiturk.ilekasoft.com/api/cron_basvuru_gonder.php?key=CRON_SECRET_KEY_2025"
```

### Nasıl Çalışır?
- Her 5 dakikada bir çalışır
- ResponseCode NULL olan başvuruları seçer
- En fazla 5 başvuruyu işler (timeout önlemi)
- API'ye POST request gönderir
- Sonuçları veritabanına kaydeder
- Case çakışması durumunda otomatik retry yapar

### Log Takibi
Log dosyası: `logs/cron_basvuru_log.txt`

### Manuel Test
```
https://digiturk.ilekasoft.com/api/cron_basvuru_gonder.php?key=CRON_SECRET_KEY_2025
```

---

## 3️⃣ bbkAddressCode Yenileme

### Plesk Cron Job Ayarları

**Komut:**
```bash
curl "https://digiturk.ilekasoft.com/api/cron_bbk_yenile.php?key=CRON_SECRET_KEY_2025"
```

**Zamanlama: Her 30 dakika**
- **Dakika:** 0,30
- **Saat:** *
- **Gün:** *
- **Ay:** *
- **Haftanın Günü:** *

**Cron Formatı:**
```
0,30 * * * * curl "https://digiturk.ilekasoft.com/api/cron_bbk_yenile.php?key=CRON_SECRET_KEY_2025"
```

### Nasıl Çalışır?
- Her 30 dakikada bir çalışır
- bbkAddressCode hataları olan başvuruları bulur
- Yeni random kod üretir (130109 - 111069460 arası)
- Başvuruyu tekrar gönderime hazırlar
- En fazla 50 başvuruyu işler (sadece DB işlemi)

### Tespit Edilen Hatalar
```
- "Value cannot be null"
- "Parameter name: source"
- "Geçersiz GeoLocationId değeri:0"
```

### Log Takibi
Log dosyası: `logs/cron_bbk_log.txt`

### Manuel Test
```
https://digiturk.ilekasoft.com/api/cron_bbk_yenile.php?key=CRON_SECRET_KEY_2025
```

---

## 4️⃣ E-posta Düzeltme (Manuel)

### Manuel Çalıştırma

**Komut:**
```bash
curl "https://digiturk.ilekasoft.com/api/cron_email_duzelt.php?key=CRON_SECRET_KEY_2025"
```

**Not:** Bu cron job otomatik çalışmaz, sadece gerektiğinde manuel olarak çalıştırılır.

### Nasıl Çalışır?
- CSV dosyalarındaki e-posta sorunlarını düzeltir
- Veritabanında güncelleme yapar
- Log kaydı oluşturur

---

## ⚙️ Genel Ayarlar

### Güvenlik Key'i
Tüm cron job'lar aynı secret key kullanır: `CRON_SECRET_KEY_2025`

Key'i değiştirmek için: `config/cron.php` dosyasını düzenleyin

### Log Klasörü
Tüm log dosyaları: `logs/` klasöründe

### Log Temizleme
Log dosyaları zamanla büyüyebilir, periyodik olarak temizleyin:
```powershell
# Eski logları yedekle
Copy-Item logs\cron_basvuru_log.txt logs\cron_basvuru_log_backup.txt
# Log dosyasını temizle
Clear-Content logs\cron_basvuru_log.txt
```

---

## 🔧 Sorun Giderme

### 1. Cron Job Çalışmıyor
- Plesk panelinde "History" sekmesinden logları kontrol edin
- Key parametresinin doğru olduğunu kontrol edin
- URL'nin erişilebilir olduğunu test edin

### 2. Başvurular Gönderilmiyor
- `logs/cron_basvuru_log.txt` dosyasını kontrol edin
- Veritabanında `ResponseCode_ID IS NULL` kayıtlar var mı?
- Token'ların geçerli olduğunu kontrol edin

### 3. bbkAddressCode Yenileme Çalışmıyor
- `logs/cron_bbk_log.txt` dosyasını kontrol edin
- Hatalı başvuru var mı? (ResponseMessage sütununda ilgili hatalar)

### 4. Log Dosyası Oluşmuyor
- `logs` klasörünün yazma izinleri olduğundan emin olun
- IIS kullanıcısının (IIS_IUSRS) write yetkisi olmalı

---

## 📊 Performans İpuçları

### Başvuru Gönderimi
- Her 5 dakikada 5 başvuru = Saatte 60 başvuru
- Daha hızlı işlem için `MAX_KAYIT` değerini artırabilirsiniz
- Ancak timeout riski artar

### bbkAddressCode Yenileme
- Her 30 dakikada 50 kayıt = Saatte 100 kayıt
- Sadece DB işlemi olduğu için hızlı çalışır
- Normal akışı yavaşlatmaz

---

## 📝 Önemli Notlar

1. **İlk Çalışma:** Cron job ilk kez çalıştığında tüm kullanıcıları güncelleyebilir
2. **API Limitleri:** API rate limit varsa, script kullanıcılar arasında 1-2 saniye bekler
3. **Yetkilendirme:** Sadece `api_iris_kullanici_durum = 1` olan kullanıcılar işlenir
4. **Hata Yönetimi:** API hatalarında bile veritabanı güncellenir (hata kodları kaydedilir)
5. **Log Boyutu:** Log dosyaları zamanla büyüyebilir, periyodik olarak temizleyin
6. **Timeout:** Başvuru gönderimi için 15 saniye timeout süresi vardır

---

## Test Komutu

Cron job'u test etmek için:

```powershell
# PowerShell'de çalıştırın
cd d:\inetpub\ilekasoft.com\digiturk.ilekasoft.com\api
php cron_token_yenile.php

# Log dosyasını görüntüleyin
Get-Content ..\temp\cron_token_log.txt -Tail 50
```

Çıktı şöyle görünmelidir:
```
[2025-11-11 14:30:00] === Token Yenileme Başladı ===
[2025-11-11 14:30:01] Veritabanı bağlantısı başarılı
...
[2025-11-11 14:30:05] === İşlem Tamamlandı ===
```

---

## İletişim

Sorun yaşarsanız log dosyasını kontrol edin ve gerekirse:
- Batuhan Kahraman
- E-posta: batuhan.kahraman@ileka.com.tr
- Tel: +90 501 357 10 85
