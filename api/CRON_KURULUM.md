# Token Otomatik Yenileme - Cron Job Kurulumu

## Plesk Cron Job Ayarları

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

## Önemli Notlar

1. **İlk Çalışma:** Cron job ilk kez çalıştığında tüm kullanıcıları güncelleyebilir
2. **API Limitleri:** API rate limit varsa, script kullanıcılar arasında 2 saniye bekler
3. **Yetkilendirme:** Sadece `api_iris_kullanici_durum = 1` olan kullanıcılar işlenir
4. **Hata Yönetimi:** API hatalarında bile veritabanı güncellenir (hata kodları kaydedilir)
5. **Log Boyutu:** Log dosyası zamanla büyüyebilir, periyodik olarak temizleyin

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
