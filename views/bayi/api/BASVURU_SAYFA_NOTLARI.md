# Başvuru Sayfası - Özel Notlar

## ⚠️ ÖNEMLİ İSTİSNA

**basvuru.php** sayfası projedeki diğer sayfalardan farklı olarak **PUBLIC** erişime açıktır!

### Neden?
Bu sayfa müşterilerin (end-user) doğrudan başvuru yapması için kullanılacak.
- Bayi/API kullanıcıları müşterilere link paylaşacak
- Müşteriler bu linke tıklayarak başvuru yapacak
- Giriş yapmalarına gerek yok

### Bu Sayfada YOKLAR:
- ❌ Auth kontrolü (`checkAuth()`)
- ❌ Yetki kontrolü (`checkPagePermission()`)
- ❌ Header include (`includes/header.php`)
- ❌ Footer include (`includes/footer.php`)
- ❌ Breadcrumb
- ❌ Admin menüsü

### Bu Sayfada VARLAR:
- ✅ Kendi HTML yapısı (Standalone)
- ✅ Bootstrap & Font Awesome
- ✅ Veritabanı bağlantısı
- ✅ Session yönetimi
- ✅ Form validasyonu
- ✅ AJAX işlemleri

## 📋 URL Parametreleri

Sayfaya şu şekilde erişilir:

```
https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=4&kampanya=1&paket=154
```

### Parametreler:
- **api_ID**: API kullanıcı ID'si (zorunlu)
- **kampanya**: Kampanya ID'si (1=Kutulu TV, 2=Kutusuz NEO)
- **paket**: Paket ID'si (opsiyonel)

### Session'da Saklanır:
```php
$_SESSION['basvuru_params'] = [
    'api_ID' => 4,
    'kampanya' => 1,
    'paket' => 154
];
```

## 🔄 İş Akışı

1. **Müşteri** → Link'e tıklar
2. **Kimlik Bilgileri** → Bu sayfa (basvuru.php)
3. **Adres Bilgileri** → basvuru-adres.php (NEO için atlanır)
4. **Paket Seçimi** → basvuru-paket.php
5. **Özet & Onay** → basvuru-ozet.php
6. **API Gönderimi** → send_to_api.php

## 🎯 Kampanya Tipleri

### Kutolu TV (kampanya=1)
- Normal başvuru süreci
- Adres bilgileri gerekli
- Full adres seçimi

### Kutusuz NEO (kampanya=2)
- Hızlandırılmış süreç
- Adres bilgileri GEREKMİYOR
- Otomatik bbkAddressCode üretimi

## 🔧 Geliştirme Notları

### Test URL'leri:
```
# Test debug
http://digiturk.ilekasoft.com/temp/test_basvuru.php?api_ID=4&kampanya=1&paket=154

# Genel başvuru
http://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=4

# Kutolu TV
http://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=4&kampanya=1&paket=154

# Kutusuz NEO
http://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=4&kampanya=2&paket=93
```

### Admin Debug:
Form altında session bilgileri görünür (sadece admin kullanıcılar için).

## 🗄️ Veritabanı Mapping

### Hedef Tablo: [dbo].[API_basvuruListesi]

#### URL Parametreleri → Veritabanı
| Parametre | Session Key | Veritabanı Kolonu | Açıklama |
|-----------|-------------|-------------------|----------|
| api_ID | `api_ID` | `[API_basvuru_kullanici_ID]` | Başvuruyu yapan API kullanıcısı |
| kampanya | `kampanya` | `[API_basvuru_CampaignList_ID]` | 1=Kutulu TV, 2=Kutusuz NEO |
| paket | `paket` | `[API_basvuru_Paket_ID]` | Seçilen paket ID (opsiyonel) |

#### Kimlik Bilgileri Formu → Veritabanı
| Form Alanı | Session Key | Veritabanı Kolonu | Validasyon |
|------------|-------------|-------------------|------------|
| İsim | `firstName` | `[API_basvuru_firstName]` | Zorunlu, max 50 karakter |
| Soyisim | `surname` | `[API_basvuru_surname]` | Zorunlu, max 50 karakter |
| E-posta | `email` | `[API_basvuru_email]` | Opsiyonel, max 100 karakter |
| Ülke Kodu | `phoneCountryNumber` | `[API_basvuru_phoneCountryNumber]` | Sabit: '90' |
| Alan Kodu | `phoneAreaNumber` | `[API_basvuru_phoneAreaNumber]` | Zorunlu, 3 hane (5XX) |
| Telefon | `phoneNumber` | `[API_basvuru_phoneNumber]` | Zorunlu, 7 hane |
| Doğum Tarihi | `birthDate` | `[API_basvuru_birthDate]` | Zorunlu, 18+ yaş kontrolü |
| TC Kimlik | `citizenNumber` | `[API_basvuru_citizenNumber]` | Zorunlu, 11 hane |
| Cinsiyet | `genderType` | `[API_basvuru_genderType]` | Zorunlu, BAY veya BAYAN |
| Kimlik Tipi | `identityCardType_ID` | `[API_basvuru_identityCardType_ID]` | FK: API_GetCardTypeList |
| İl | `il_ID`, `il_code` | - | Adres seçiminde kullanılacak |

#### Neo Kampanya Otomatik Adres
| Session Key | Veritabanı Kolonu | Açıklama |
|-------------|-------------------|----------|
| `bbkAddressCode` | `[API_basvuru_bbkAddressCode]` | Random unique kod (130109 - 111069460) |

#### Sistem Alanları (Otomatik)
| Veritabanı Kolonu | Değer | Açıklama |
|-------------------|-------|----------|
| `[API_basvuru_kaynakSite]` | 'digiturk.ilekasoft.com' | Kaynak site |
| `[API_basvuru_basvuru_durum_ID]` | 1 | Varsayılan durum (Yeni) |
| `[API_basvuru_olusturma_tarih]` | GETDATE() | Kayıt oluşturma zamanı |
| `[API_basvuru_guncelleme_tarihi]` | GETDATE() | Son güncelleme zamanı |

#### API Response Alanları (Sonradan Doldurulacak)
| Veritabanı Kolonu | Açıklama |
|-------------------|----------|
| `[API_basvuru_ResponseCode_ID]` | API'den gelen response kodu |
| `[API_basvuru_ResponseMessage]` | API'den gelen mesaj |
| `[API_basvuru_MusteriNo]` | API'den dönen müşteri numarası |
| `[API_basvuru_TalepKayitNo]` | API'den dönen talep kayıt no |
| `[API_basvuru_MemoID]` | API'den dönen memo ID |
| `[API_basvuru_Basvuru_Aciklama]` | Ek açıklamalar |

---

**Tarih:** 21 Kasım 2025
**Geliştirici:** Batuhan Kahraman
**E-posta:** batuhan.kahraman@ileka.com.tr
**Telefon:** +90 501 357 10 85
