# Iris Rapor - Excel/CSV Dosya Yükleme Sistemi

Bu sistem, Excel veya CSV formatındaki Iris rapor dosyalarını MSSQL veritabanına yüklemek için oluşturulmuştur.

## Dosyalar

- `upload_iris_rapor.php` - Ana yükleme sayfası (HTML interface)
- `process_upload.php` - Dosya işleme scripti
- `MSSQLConnection.php` - Veritabanı bağlantı sınıfı
- `create_table.php` - Tablo oluşturma scripti
- `config/mssql.php` - Veritabanı ayarları

## Kurulum Adımları

### 1. Veritabanı Tablosunu Oluştur
İlk kurulumda tabloyu oluşturmak için:
```bash
php create_table.php
```

### 2. Web Arayüzüne Erişim
Tarayıcınızda şu adresi açın:
```
http://digiturk.ilekasoft.com/upload_iris_rapor.php
```

## Kullanım

### Desteklenen Dosya Formatları
- CSV (Comma Separated Values) - `;` ayırıcı ile
- Excel (.xlsx, .xls) - Gelecekte eklenecek

### Beklenen Başlıklar
Dosyanızın ilk satırında şu başlıkların bulunması gerekir:
```
TALEP_ID;TALEP_TURU;UYDU_BASVURU_POTANSIYEL_NO;UYDU_BASVURU_UYE_NO;DT_MUSTERI_NO;MEMO_ID;MEMO_KAYIT_TIPI;MEMO_ID_TIP;MEMO_KODU;MEMO_KAPANIS_TARIHI;MEMO_YONLENEN_BAYI_KODU;MEMO_YONLENEN_BAYI_ADI;MEMO_YONLENEN_BAYI_YONETICISI;MEMO_YONLENEN_BAYI_BOLGE;MEMO_YONLENEN_BAYI_TEKNIK_YNTC;TALEP_GIRIS_TARIHI;TALEBI_GIREN_BAYI_KODU;TALEBI_GIREN_BAYI_ADI;TALEBI_GIREN_PERSONEL;TALEBI_GIREN_PERSONELNO;TALEBI_GIREN_PERSONELKODU;TALEBI_GIREN_PERSONEL_ALTBAYI;TALEP_KAYNAK;SATIS_DURUMU;INTERNET_SUREC_DURUMU;AKTIVE_EDILEN_UYENO;AKTIVE_EDILEN_OUTLETNO;AKTIVE_EDILEN_SOZLESMENO;AKTIVE_EDILEN_SOZLESMEKMP;AKTIVE_EDILEN_SOZLESMEDURUM;TALEP_TAKIP_NOTU;GUNCEL_OUTLET_DURUM;TEYIT_DURUM;TEYIT_ARAMA_DURUM;RANDEVU_TARIHI;MEMO_SON_DURUM;MEMO_SON_CEVAP;MEMO_SON_ACIKLAMA
```

### Dosya Yükleme Adımları
1. Ana sayfa üzerindeki yükleme alanına dosyayı sürükleyip bırakın veya tıklayarak seçin
2. Dosya bilgileri görüntülendikten sonra "Dosyayı Yükle ve İşle" butonuna tıklayın
3. İşlem progress bar ile takip edilebilir
4. İşlem tamamlandığında sonuçlar ve detaylar görüntülenir

## Özellikler

### Güvenlik
- Dosya boyutu sınırı: 50MB
- Desteklenen dosya formatları kontrolü
- SQL injection koruması

### Performans
- Batch insert işlemi (toplu kayıt)
- Memory limit optimizasyonu
- İşlem süresi takibi

### Hata Yönetimi
- Detaylı hata kayıtları
- Başarısız satır raporlama
- İşlem geri alma (rollback)

### Tarih Formatları
Sistem şu tarih formatlarını destekler:
- `d.m.Y H:i:s` (örn: 4.06.2025 10:10:12)
- `d.m.Y` (örn: 4.06.2025)

## Veritabanı Yapısı

### `iris_rapor` Tablosu
- `iris_rapor_ID` - Otomatik artan primary key
- Excel'den gelen tüm alanlar
- `eklenme_tarihi` - Kayıt ekleme tarihi (otomatik)
- `guncellenme_tarihi` - Güncelleme tarihi (otomatik)
- `odeme_tutari` - Ödeme tutarı (opsiyonel)

## Sorun Giderme

### Bağlantı Sorunları
- `config/mssql.php` dosyasındaki veritabanı ayarlarını kontrol edin
- SQL Server'ın çalıştığından emin olun

### Dosya Yükleme Sorunları
- PHP'nin `upload_max_filesize` ve `post_max_size` ayarlarını kontrol edin
- Dosya izinlerini kontrol edin

### Bellek Sorunları
- Büyük dosyalar için PHP'nin `memory_limit` ayarını artırın
- `max_execution_time` süresini artırın

## Güncelleme Notları

### Versiyon 1.0
- İlk sürüm
- CSV dosya desteği
- Basic web arayüzü
- MSSQL entegrasyonu

### Gelecek Özellikler
- Excel dosya desteği (.xlsx, .xls)
- Toplu silme işlemi
- Veri güncelleme (duplicate handling)
- API endpoint'leri

## Yeni Eklenen Özellikler

### Bayi Hakedis Prim Dönem Yönetimi
- `bayi_hakedis_prim_donem.php` - Dönem tanımlama ve yönetimi sayfası
- `bayi_hakedis_prim_donem` - SQL Server tablosu
- `create_table_bayi_hakedis_prim_donem.php` - Tablo oluşturma scripti
- `sql_scripts/create_bayi_hakedis_prim_donem_table.sql` - SQL script dosyası

#### Özellikler:
- Dönem adı tanımlama
- Başlangıç ve bitiş tarihi belirleme
- Durum yönetimi (AKTIF/PASIF)
- Otomatik tarih validasyonu
- Kullanıcı bazlı audit trail (oluşturan/güncelleyen)
- Bootstrap ile modern arayüz

#### Veritabanı Alanları:
- **id** - Otomatik artan primary key
- **donem_adi** - Dönem adı (benzersiz)
- **baslangic_tarihi** - Dönem başlangıç tarihi
- **bitis_tarihi** - Dönem bitiş tarihi
- **durum** - AKTIF/PASIF durumu
- **olusturma_tarihi** - Kayıt oluşturma tarihi
- **olusturan** - Kaydı oluşturan kullanıcı ID
- **guncelleme_tarihi** - Son güncelleme tarihi
- **guncelleyen** - Son güncelleyen kullanıcı ID

#### Kurulum:
1. Tabloyu oluşturmak için: `http://digiturk.ilekasoft.com/create_table_bayi_hakedis_prim_donem.php` adresini ziyaret edin
2. Yönetim sayfasına erişim: `http://digiturk.ilekasoft.com/bayi_hakedis_prim_donem.php`
