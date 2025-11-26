<?php
header('Content-Type: application/json');

try {
    $uploadDir = 'uploads/';
    
    // Uploads klasörünün varlığını kontrol et
    if (!is_dir($uploadDir)) {
        throw new Exception('Uploads klasörü bulunamadı');
    }
    
    // Klasördeki dosyaları oku
    $files = scandir($uploadDir);
    $csvFiles = [];
    
    foreach ($files as $file) {
        // . ve .. klasörlerini atla, sadece .csv dosyalarını al
        if ($file !== '.' && $file !== '..' && is_file($uploadDir . $file)) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'csv') {
                $csvFiles[] = $file;
            }
        }
    }
    
    // Dosyaları tarih sırasına göre sırala (en yeni önce)
    usort($csvFiles, function($a, $b) use ($uploadDir) {
        $timeA = filemtime($uploadDir . $a);
        $timeB = filemtime($uploadDir . $b);
        return $timeB - $timeA; // Azalan sıra (en yeni önce)
    });
    
    echo json_encode($csvFiles);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>