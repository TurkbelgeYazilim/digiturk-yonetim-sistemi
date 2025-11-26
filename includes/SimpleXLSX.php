<?php
/**
 * SimpleXLSX - Basit Excel okuma kütüphanesi
 * PhpSpreadsheet alternatifi, daha hafif
 */
class SimpleXLSX {
    private $data = [];
    private static $error = '';
    
    public static function parse($filename) {
        if (!file_exists($filename)) {
            self::$error = 'Dosya bulunamadı';
            return false;
        }
        
        try {
            // ZIP olarak aç (xlsx dosyası aslında bir ZIP arşivi)
            $zip = new ZipArchive();
            if ($zip->open($filename) !== TRUE) {
                self::$error = 'Excel dosyası açılamadı';
                return false;
            }
            
            // shared strings dosyasını oku
            $sharedStrings = [];
            if (($sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($sharedStringsXml);
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $sharedStrings[] = (string)$si->t;
                    }
                }
            }
            
            // worksheet'i oku
            $worksheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($worksheetXml === false) {
                self::$error = 'Worksheet okunamadı';
                $zip->close();
                return false;
            }
            
            $xml = simplexml_load_string($worksheetXml);
            if (!$xml) {
                self::$error = 'XML parse hatası';
                $zip->close();
                return false;
            }
            
            $zip->close();
            
            $instance = new self();
            $instance->parseWorksheet($xml, $sharedStrings);
            
            return $instance;
            
        } catch (Exception $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }
    
    private function parseWorksheet($xml, $sharedStrings) {
        $rows = [];
        
        if (isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                $colIndex = 0;
                
                if (isset($row->c)) {
                    foreach ($row->c as $cell) {
                        $cellValue = '';
                        
                        // Hücre referansından sütun indexini al
                        $cellRef = (string)$cell['r'];
                        preg_match('/([A-Z]+)/', $cellRef, $matches);
                        if (isset($matches[1])) {
                            $colIndex = $this->columnIndexFromString($matches[1]);
                        }
                        
                        // Hücre değerini al
                        if (isset($cell->v)) {
                            $cellValue = (string)$cell->v;
                            
                            // Eğer tip shared string ise, shared strings'den al
                            if (isset($cell['t']) && (string)$cell['t'] === 's') {
                                $index = (int)$cellValue;
                                if (isset($sharedStrings[$index])) {
                                    $cellValue = $sharedStrings[$index];
                                }
                            }
                        }
                        
                        // Row array'ini genişlet
                        while (count($rowData) <= $colIndex) {
                            $rowData[] = '';
                        }
                        
                        $rowData[$colIndex] = $cellValue;
                    }
                }
                
                $rows[] = $rowData;
            }
        }
        
        $this->data = $rows;
    }
    
    private function columnIndexFromString($column) {
        $index = 0;
        $length = strlen($column);
        
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - ord('A'));
        }
        
        return $index;
    }
    
    public function rows() {
        return $this->data;
    }
    
    public static function parseError() {
        return self::$error;
    }
}
?>