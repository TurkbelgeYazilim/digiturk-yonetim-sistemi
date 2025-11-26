<?php
header('Content-Type: application/json');

try {
    $filename = $_GET['file'] ?? '';
    if (empty($filename)) {
        throw new Exception('Dosya adı belirtilmedi');
    }
    
    $uploadDir = 'uploads/';
    $filePath = $uploadDir . $filename;
    
    // Dosya varlığını kontrol et
    if (!file_exists($filePath)) {
        throw new Exception('Dosya bulunamadı: ' . $filename);
    }
    
    // CSV dosyasının ilk satırını oku (başlıklar)
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new Exception('Dosya açılamadı');
    }
    
    $firstLine = fgets($handle);
    fclose($handle);
    
    if (!$firstLine) {
        throw new Exception('Dosya boş veya okunamadı');
    }
    
    // CSV başlıklarını ayır (noktalı virgül ile ayrılmış)
    $csvHeaders = array_map('trim', explode(';', trim($firstLine)));
    
    // Beklenen SQL sütunları (iris_rapor tablosundan)
    $expectedColumns = [
        'TALEP_ID',
        'TALEP_TURU',
        'UYDU_BASVURU_POTANSIYEL_NO',
        'UYDU_BASVURU_UYE_NO',
        'DT_MUSTERI_NO',
        'MEMO_ID',
        'MEMO_KAYIT_TIPI',
        'MEMO_ID_TIP',
        'MEMO_KODU',
        'MEMO_KAPANIS_TARIHI',
        'MEMO_YONLENEN_BAYI_KODU',
        'MEMO_YONLENEN_BAYI_ADI',
        'MEMO_YONLENEN_BAYI_YONETICISI',
        'MEMO_YONLENEN_BAYI_BOLGE',
        'MEMO_YONLENEN_BAYI_TEKNIK_YNTC',
        'TALEP_GIRIS_TARIHI',
        'TALEBI_GIREN_BAYI_KODU',
        'TALEBI_GIREN_BAYI_ADI',
        'TALEBI_GIREN_PERSONEL',
        'TALEBI_GIREN_PERSONELNO',
        'TALEBI_GIREN_PERSONELKODU',
        'TALEBI_GIREN_PERSONEL_ALTBAYI',
        'TALEP_KAYNAK',
        'SATIS_DURUMU',
        'INTERNET_SUREC_DURUMU',
        'AKTIVE_EDILEN_UYENO',
        'AKTIVE_EDILEN_OUTLETNO',
        'AKTIVE_EDILEN_SOZLESMENO',
        'AKTIVE_EDILEN_SOZLESMEKMP',
        'AKTIVE_EDILEN_SOZLESMEDURUM',
        'TALEP_TAKIP_NOTU',
        'GUNCEL_OUTLET_DURUM',
        'TEYIT_DURUM',
        'TEYIT_ARAMA_DURUM',
        'RANDEVU_TARIHI',
        'MEMO_SON_DURUM',
        'MEMO_SON_CEVAP',
        'MEMO_SON_ACIKLAMA'
    ];
    
    // Karşılaştırma yap
    $csvHeadersCount = count($csvHeaders);
    $expectedCount = count($expectedColumns);
    
    // Eşleşen sütunları bul
    $matchingColumns = array_intersect($csvHeaders, $expectedColumns);
    $matchingCount = count($matchingColumns);
    
    // CSV'de eksik olan sütunlar
    $missingInCsv = array_diff($expectedColumns, $csvHeaders);
    
    // CSV'de fazla olan sütunlar
    $extraInCsv = array_diff($csvHeaders, $expectedColumns);
    
    // Sıralama uyumsuzlukları
    $orderMismatch = [];
    for ($i = 0; $i < min($csvHeadersCount, $expectedCount); $i++) {
        if (isset($csvHeaders[$i]) && isset($expectedColumns[$i]) && $csvHeaders[$i] !== $expectedColumns[$i]) {
            $orderMismatch[] = [
                'position' => $i + 1,
                'expected' => $expectedColumns[$i],
                'found' => $csvHeaders[$i]
            ];
        }
    }
    
    // Uyumluluk kontrolü - Esnek yaklaşım
    // Eğer CSV'deki tüm sütunlar beklenen sütunlar arasındaysa uyumlu sayalım
    $isCompatible = (count($extraInCsv) == 0 && $matchingCount > 0);
    
    // Alternatif: Daha esnek kontrol - en az %80 eşleşme varsa uyumlu sayalım
    $matchPercentage = $expectedCount > 0 ? ($matchingCount / $expectedCount) * 100 : 0;
    if ($matchPercentage >= 80) {
        $isCompatible = true;
    }
    
    // Uyumluluk mesajı
    $compatibilityMessage = $isCompatible 
        ? 'CSV dosyası SQL tablosu ile uyumlu' 
        : 'CSV dosyası SQL tablosu ile uyumlu değil';
    
    if (!$isCompatible && count($missingInCsv) > 0) {
        $compatibilityMessage .= ' - Eksik sütunlar var';
    }
    
    if (!$isCompatible && count($extraInCsv) > 0) {
        $compatibilityMessage .= ' - Fazla sütunlar var';
    }
    
    echo json_encode([
        'success' => true,
        'is_compatible' => $isCompatible,
        'compatibility_message' => $compatibilityMessage,
        'total_csv_columns' => $csvHeadersCount,
        'total_expected_columns' => $expectedCount,
        'matching_columns' => $matchingCount,
        'match_percentage' => round($matchPercentage, 1),
        'missing_in_csv' => array_values($missingInCsv),
        'extra_in_csv' => array_values($extraInCsv),
        'order_mismatch' => $orderMismatch,
        'csv_headers' => $csvHeaders,
        'expected_headers' => $expectedColumns
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'is_compatible' => false
    ]);
}
?>