<?php
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

$pageTitle = "Sippy Otomatik Harcama";
$breadcrumbs = [
    ['title' => 'Yönetim', 'url' => '../../../index.php'],
    ['title' => 'VoIP', 'url' => '#'],
    ['title' => 'Sippy Otomatik Harcama']
];

// AJAX İşlemleri
if (isset($_GET['action']) && $_GET['action'] === 'get_operator') {
    header('Content-Type: application/json');
    try {
        $conn = getDatabaseConnection();
        $sql = "SELECT * FROM voip_operator_tanim WHERE voip_operator_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_GET['id']]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'operator' => $op]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'debug_test') {
        try {
            $url = $_POST['url'];
            $cookie = $_POST['cookie'];
            
            // cURL ile test
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Cookie: ' . $cookie,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            $result = [
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'size' => strlen($response),
                'error' => $curlError ?: null,
                'preview' => htmlspecialchars(substr($response, 0, 2000)),
                'rows' => [],
                'is_excel' => false
            ];
            
            // Parse dene
            if ($httpCode == 200 && !empty($response) && stripos($response, '<html') === false) {
                $isExcel = (stripos($contentType, 'spreadsheet') !== false || stripos($contentType, 'excel') !== false);
                $result['is_excel'] = $isExcel;
                
                $tempDir = realpath(__DIR__ . '/../../../temp') . DIRECTORY_SEPARATOR;
                $fileExt = $isExcel ? '.xlsx' : '.csv';
                $tempFile = $tempDir . 'debug_' . time() . '_' . rand(1000, 9999) . $fileExt;
                file_put_contents($tempFile, $response);
                
                if ($isExcel) {
                    require_once __DIR__ . '/../../../includes/SimpleXLSX.php';
                    if ($xlsx = SimpleXLSX::parse($tempFile)) {
                        $result['rows'] = $xlsx->rows();
                    }
                } else {
                    $handle = fopen($tempFile, 'r');
                    while (($data = fgetcsv($handle, 10000, ',')) !== false) {
                        $result['rows'][] = $data;
                    }
                    fclose($handle);
                }
                
                @unlink($tempFile);
            }
            
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'save_config') {
        try {
            $conn = getDatabaseConnection();
            $sql = "UPDATE voip_operator_tanim SET 
                    voip_operator_sippy_cookie = ?, 
                    voip_operator_sippy_base_url = ?, 
                    voip_operator_sippy_customer_id = ? 
                    WHERE voip_operator_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $_POST['cookie'],
                $_POST['url'],
                $_POST['customer'],
                $_POST['operator_id']
            ]);
            echo json_encode(['success' => true, 'message' => 'Ayarlar kaydedildi']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'process_dates') {
        try {
            set_time_limit(300); // 5 dakika timeout
            
            $conn = getDatabaseConnection();
            $opId = (int)$_POST['operator_id'];
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            
            // Debug log
            error_log("Sippy Process: Operator=$opId, Start=$startDate, End=$endDate");
            
            // Operatör bilgilerini al
            $sql = "SELECT * FROM voip_operator_tanim WHERE voip_operator_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$opId]);
            $operator = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$operator) {
                error_log("Sippy Error: Operatör bulunamadı (ID: $opId)");
                throw new Exception('Operatör bulunamadı (ID: ' . $opId . ')');
            }
            
            if (empty($operator['voip_operator_sippy_cookie'])) {
                throw new Exception('Operatör Sippy cookie eksik');
            }
            
            if (empty($operator['voip_operator_sippy_base_url'])) {
                throw new Exception('Operatör Sippy URL eksik');
            }
            
            error_log("Sippy Process: Operatör bulundu - " . $operator['voip_operator_adi']);
            
            $results = [
                'total_days' => 0,
                'processed_days' => 0,
                'total_records' => 0,
                'added_records' => 0,
                'updated_records' => 0,
                'details' => [],
                'errors' => []
            ];
            
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            
            $tempDir = realpath(__DIR__ . '/../../../temp') . DIRECTORY_SEPARATOR;
            
            foreach ($period as $date) {
                $results['total_days']++;
                
                try {
                    $nextDay = clone $date;
                    $nextDay->modify('+1 day');
                    
                    // Sippy CSV URL
                    $url = rtrim($operator['voip_operator_sippy_base_url'], '?');
                    $url .= '?startDate=' . urlencode($date->format('d-m-Y') . ' 00:00:00');
                    $url .= '&caller=0_0'; // caller parametresi önce olmalı
                    $url .= '&endDate=' . urlencode($nextDay->format('d-m-Y') . ' 00:00:00');
                    $url .= '&cdr_currency=TRY&group_by=5&calls_select=4&from_form=1&action=csv';
                    
                    // Cookie'yi temizle (sadece ilk PHPSESSID'yi al)
                    $cookie = $operator['voip_operator_sippy_cookie'];
                    if (strpos($cookie, ';') !== false) {
                        // Birden fazla cookie varsa, ilk PHPSESSID'yi al
                        preg_match('/PHPSESSID=([^;]+)/', $cookie, $matches);
                        if (isset($matches[1])) {
                            $cookie = 'PHPSESSID=' . $matches[1];
                        }
                    }
                    
                    error_log("Sippy Request [{$date->format('Y-m-d')}]: URL=$url, Cookie=" . substr($cookie, 0, 50));
                    
                    // cURL ile CSV indir
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Cookie: ' . $cookie,
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                    
                    $csvContent = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                    curl_close($ch);
                    
                    error_log("Sippy Response [{$date->format('Y-m-d')}]: HTTP=$httpCode, Type=$contentType, Size=" . strlen($csvContent));
                    
                    if ($curlError) {
                        error_log("Sippy cURL Error [{$date->format('Y-m-d')}]: $curlError");
                        throw new Exception('cURL hatası: ' . $curlError);
                    }
                    
                    if ($httpCode !== 200) {
                        error_log("Sippy HTTP Error [{$date->format('Y-m-d')}]: HTTP $httpCode - Response: " . substr($csvContent, 0, 200));
                        throw new Exception('HTTP ' . $httpCode . ' hatası (Cookie geçersiz olabilir)');
                    }
                    
                    if (empty($csvContent)) {
                        error_log("Sippy Empty Response [{$date->format('Y-m-d')}]");
                        throw new Exception('CSV boş geldi');
                    }
                    
                    // CSV içeriğini kontrol et
                    if (strlen($csvContent) < 10) {
                        error_log("Sippy Invalid CSV [{$date->format('Y-m-d')}]: " . substr($csvContent, 0, 200));
                        throw new Exception('Geçersiz CSV içeriği (Boyut: ' . strlen($csvContent) . ' byte)');
                    }
                    
                    // HTML içeriği gelmiş mi kontrol et (login sayfası vb.)
                    if (stripos($csvContent, '<html') !== false || stripos($csvContent, '<!DOCTYPE') !== false) {
                        error_log("Sippy HTML Response [{$date->format('Y-m-d')}]: " . substr($csvContent, 0, 300));
                        throw new Exception('Sippy oturum geçersiz - HTML yanıtı geldi (Cookie süresi dolmuş olabilir)');
                    }
                    
                    // Content-Type kontrolü
                    $isExcel = (stripos($contentType, 'spreadsheet') !== false || stripos($contentType, 'excel') !== false);
                    $isCsv = (stripos($contentType, 'csv') !== false || stripos($contentType, 'text') !== false);
                    
                    error_log("Sippy Content Type [{$date->format('Y-m-d')}]: $contentType - Excel: " . ($isExcel ? 'Yes' : 'No') . ", CSV: " . ($isCsv ? 'Yes' : 'No'));
                    
                    // Dosyayı geçici klasöre kaydet
                    $fileExt = $isExcel ? '.xlsx' : '.csv';
                    $tempFile = $tempDir . 'sippy_' . time() . '_' . rand(1000, 9999) . $fileExt;
                    file_put_contents($tempFile, $csvContent);
                    
                    error_log("Sippy File saved [{$date->format('Y-m-d')}]: $tempFile");
                    
                    $rows = [];
                    
                    if ($isExcel) {
                        // Excel dosyasını parse et
                        require_once __DIR__ . '/../../../includes/SimpleXLSX.php';
                        
                        if ($xlsx = SimpleXLSX::parse($tempFile)) {
                            $rows = $xlsx->rows();
                            error_log("Sippy Excel parsed [{$date->format('Y-m-d')}]: " . count($rows) . " satır");
                        } else {
                            error_log("Sippy Excel parse error [{$date->format('Y-m-d')}]: " . SimpleXLSX::parseError());
                            throw new Exception('Excel dosyası parse edilemedi: ' . SimpleXLSX::parseError());
                        }
                    } else {
                        // CSV dosyasını parse et
                        $handle = fopen($tempFile, 'r');
                        if (!$handle) {
                            unlink($tempFile);
                            throw new Exception('CSV dosyası açılamadı');
                        }
                        
                        while (($data = fgetcsv($handle, 10000, ',')) !== false) {
                            $rows[] = $data;
                        }
                        fclose($handle);
                        
                        error_log("Sippy CSV parsed [{$date->format('Y-m-d')}]: " . count($rows) . " satır");
                    }
                    
                    error_log("Sippy First row [{$date->format('Y-m-d')}]: " . json_encode($rows[0] ?? []));
                    
                    if (count($rows) < 2) {
                        error_log("Sippy No Data [{$date->format('Y-m-d')}]: Sadece başlık satırı var veya veri yok");
                        $results['details'][] = [
                            'date' => $date->format('Y-m-d'),
                            'status' => 'success',
                            'records' => 0,
                            'added' => 0,
                            'updated' => 0
                        ];
                        $results['processed_days']++;
                        continue;
                    }
                    
                    // VoIP numaralarını map'e al
                    $voipSql = "SELECT n.voip_operator_numara_id, n.voip_operator_numara_VoIPNo 
                                FROM voip_operator_numara n
                                INNER JOIN voip_operator_tanim o ON n.voip_operator_tanim_id = o.voip_operator_id
                                WHERE o.voip_operator_id = ?";
                    $voipStmt = $conn->prepare($voipSql);
                    $voipStmt->execute([$opId]);
                    $voipMap = [];
                    while ($v = $voipStmt->fetch(PDO::FETCH_ASSOC)) {
                        $voipMap[trim($v['voip_operator_numara_VoIPNo'])] = $v['voip_operator_numara_id'];
                    }
                    
                    $added = 0;
                    $updated = 0;
                    
                    // Başlık satırını kontrol et - operatöre göre sütun yapısı farklı
                    $costColumnIndex = 5; // Varsayılan: Cost (Regnum)
                    if (count($rows) > 0) {
                        $header = array_map('trim', $rows[0]);
                        error_log("Sippy CSV Headers [{$date->format('Y-m-d')}]: " . json_encode($header));
                        
                        // "Charged Amount" sütunu varsa (Pasifik Telekom)
                        $chargedAmountIndex = array_search('Charged Amount', $header);
                        if ($chargedAmountIndex !== false) {
                            $costColumnIndex = $chargedAmountIndex;
                            error_log("Sippy Using Charged Amount column (index $costColumnIndex) for operator $opId");
                        } else {
                            // "Cost" sütunu varsa (Regnum)
                            $costIndex = array_search('Cost', $header);
                            if ($costIndex !== false) {
                                $costColumnIndex = $costIndex;
                                error_log("Sippy Using Cost column (index $costColumnIndex) for operator $opId");
                            }
                        }
                    }
                    
                    // CSV satırlarını işle (başlık satırını atla)
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        if (count($row) < 5) continue;
                        
                        $customerName = trim($row[0]);
                        $calls = !empty($row[1]) ? (int)$row[1] : null;
                        $durationMinutes = !empty($row[2]) ? (float)$row[2] : 0; // Duration, min (virgüllü sayı)
                        $cost = !empty($row[$costColumnIndex]) ? (float)$row[$costColumnIndex] : 0; // Dinamik ücret sütunu
                        
                        // "Acct. " önekini temizle
                        if (strpos($customerName, 'Acct. ') === 0) {
                            $customerName = trim(substr($customerName, 6));
                        }
                        
                        if (!isset($voipMap[$customerName])) continue;
                        
                        $numberId = $voipMap[$customerName];
                        $tarih = $date->format('Y-m-d') . ' 00:00:00';
                        
                        // Süreyi HH:MM:SS formatına çevir (dakika + ondalık kısım)
                        $durationStr = null;
                        if ($durationMinutes > 0) {
                            $totalSeconds = round($durationMinutes * 60); // Dakikayı saniyeye çevir
                            $hours = floor($totalSeconds / 3600);
                            $minutes = floor(($totalSeconds % 3600) / 60);
                            $seconds = $totalSeconds % 60;
                            $durationStr = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                        }
                        
                        error_log("Sippy Parse Row [{$date->format('Y-m-d')}]: $customerName, Calls=$calls, Duration=$durationMinutes min → $durationStr, Cost=$cost TRY");
                        
                        // Kayıt var mı kontrol et
                        $checkSql = "SELECT voip_operator_Harcama_id FROM voip_operator_Harcama 
                                     WHERE voip_operator_numara_id = ? 
                                     AND CAST(voip_operator_Harcama_Tarih AS DATE) = CAST(? AS DATE)";
                        $checkStmt = $conn->prepare($checkSql);
                        $checkStmt->execute([$numberId, $tarih]);
                        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Güncelle
                            $updateSql = "UPDATE voip_operator_Harcama SET 
                                          voip_operator_Harcama_CagriSayisi = ?, 
                                          voip_operator_Harcama_Sure = ?, 
                                          voip_operator_Harcama_Ucret = ? 
                                          WHERE voip_operator_Harcama_id = ?";
                            $updateStmt = $conn->prepare($updateSql);
                            $updateStmt->execute([$calls, $durationStr, $cost, $existing['voip_operator_Harcama_id']]);
                            $updated++;
                        } else {
                            // Ekle
                            $insertSql = "INSERT INTO voip_operator_Harcama 
                                          (voip_operator_numara_id, voip_operator_Harcama_CagriSayisi, 
                                           voip_operator_Harcama_Sure, voip_operator_Harcama_Tarih, voip_operator_Harcama_Ucret) 
                                          VALUES (?, ?, ?, ?, ?)";
                            $insertStmt = $conn->prepare($insertSql);
                            $insertStmt->execute([$numberId, $calls, $durationStr, $tarih, $cost]);
                            $added++;
                        }
                    }
                    
                    $results['processed_days']++;
                    $results['added_records'] += $added;
                    $results['updated_records'] += $updated;
                    $results['total_records'] += ($added + $updated);
                    
                    $results['details'][] = [
                        'date' => $date->format('Y-m-d'),
                        'status' => 'success',
                        'records' => ($added + $updated),
                        'added' => $added,
                        'updated' => $updated
                    ];
                    
                    // Debug dosyasını temizle
                    if (isset($tempFile) && file_exists($tempFile)) {
                        unlink($tempFile);
                    }
                    
                } catch (Exception $e) {
                    error_log("Sippy Exception [{$date->format('Y-m-d')}]: " . $e->getMessage());
                    $results['errors'][] = $date->format('Y-m-d') . ': ' . $e->getMessage();
                    $results['details'][] = [
                        'date' => $date->format('Y-m-d'),
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            // İşlem bittiğinde temp klasöründeki sippy debug dosyalarını temizle
            $debugFiles = glob($tempDir . 'sippy_*.csv');
            foreach ($debugFiles as $f) {
                @unlink($f);
            }
            error_log("Sippy Process Completed: " . json_encode($results));
            
            echo json_encode(['success' => true, 'results' => $results]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Operatör listesi
$operators = [];
try {
    $conn = getDatabaseConnection();
    $sql = "SELECT voip_operator_id, voip_operator_adi, voip_operator_sippy_cookie, 
                   voip_operator_sippy_base_url, voip_operator_sippy_customer_id 
            FROM voip_operator_tanim WHERE voip_operator_durum = 1 ORDER BY voip_operator_adi";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $operators = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $operators = [];
}

include '../../../includes/header.php';
?>
<div class="container-fluid mt-4">
    <h2><i class="fas fa-robot me-2"></i>Sippy Otomatik Harcama</h2>

    <div class="alert alert-info mt-3">
        <i class="fas fa-info-circle me-2"></i>
        Sippy sisteminden otomatik CSV indirip harcamaları kaydedin.
    </div>

    <div class="card mt-3">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Operatörler</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Operatör</th>
                        <th>Cookie</th>
                        <th>URL</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operators as $op): ?>
                    <?php $ok = !empty($op['voip_operator_sippy_cookie']) && !empty($op['voip_operator_sippy_base_url']); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($op['voip_operator_adi']); ?></strong></td>
                        <td>
                            <?php if (!empty($op['voip_operator_sippy_cookie'])): ?>
                            <span class="badge bg-success">Var</span>
                            <?php else: ?>
                            <span class="badge bg-warning">Yok</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($op['voip_operator_sippy_base_url'])): ?>
                            <small><?php echo htmlspecialchars(substr($op['voip_operator_sippy_base_url'], 0, 40)); ?>...</small>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $ok ? 'success' : 'danger'; ?>">
                                <?php echo $ok ? 'Hazır' : 'Eksik'; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="editConfig(<?php echo $op['voip_operator_id']; ?>)">
                                <i class="fas fa-cog"></i> Ayarla
                            </button>
                            <?php if ($ok): ?>
                            <button class="btn btn-sm btn-outline-info" onclick="debugOperator(<?php echo $op['voip_operator_id']; ?>)">
                                <i class="fas fa-bug"></i> Test
                            </button>
                            <button class="btn btn-sm btn-outline-success" onclick="processOperator(<?php echo $op['voip_operator_id']; ?>)">
                                <i class="fas fa-play"></i> İşle
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Yapılandırma Modal -->
<div class="modal fade" id="configModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Sippy Yapılandırma</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="config_operator_id">
                <div class="mb-3">
                    <label class="form-label">Cookie *</label>
                    <textarea class="form-control" id="config_cookie" rows="2" placeholder="PHPSESSID=4b8376ed35e7c9017a1b7af5875c9163"></textarea>
                    <small class="text-muted">
                        <strong>Sadece tek PHPSESSID kullanın!</strong><br>
                        ❌ Yanlış: PHPSESSID=xxx; PHPSESSID=yyy<br>
                        ✅ Doğru: PHPSESSID=4b8376ed35e7c9017a1b7af5875c9163
                    </small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Base URL *</label>
                    <input type="text" class="form-control" id="config_url" placeholder="https://sip.example.com/customer_reports.php">
                    <small class="text-muted">Sippy rapor URL'si</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Customer ID</label>
                    <input type="text" class="form-control" id="config_customer" placeholder="c245">
                    <small class="text-muted">Opsiyonel customer ID</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="saveConfig()">
                    <i class="fas fa-save"></i> Kaydet
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Debug Modal -->
<div class="modal fade" id="debugModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Sippy Test - <span id="debug_operator_name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="debug_operator_id">
                <input type="hidden" id="debug_cookie">
                <input type="hidden" id="debug_url">
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Test Tarihi</label>
                        <input type="date" class="form-control" id="debug_date">
                    </div>
                </div>
                
                <button class="btn btn-primary mb-3" onclick="runDebugTest()">
                    <i class="fas fa-play"></i> Test Çalıştır
                </button>
                
                <div id="debug_result" style="display:none;">
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">İstek Bilgileri</div>
                        <div class="card-body">
                            <p><strong>URL:</strong><br><small><code id="debug_request_url"></code></small></p>
                            <p><strong>Cookie:</strong><br><code id="debug_request_cookie"></code></p>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header" id="debug_response_header">Yanıt Bilgileri</div>
                        <div class="card-body">
                            <div id="debug_response_info"></div>
                        </div>
                    </div>
                    
                    <div class="card" id="debug_parse_card" style="display:none;">
                        <div class="card-header">Parse Sonucu</div>
                        <div class="card-body">
                            <div id="debug_parse_result"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- İşlem Modal -->
<div class="modal fade" id="processModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Otomatik Harcama İşleme</h5>
            </div>
            <div class="modal-body">
                <input type="hidden" id="process_operator_id">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Başlangıç Tarihi *</label>
                        <input type="date" class="form-control" id="process_start_date">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bitiş Tarihi *</label>
                        <input type="date" class="form-control" id="process_end_date">
                    </div>
                </div>
                
                <div id="process_progress" style="display:none;">
                    <div class="progress mb-3" style="height: 30px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             id="process_bar" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <div class="border rounded p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
                        <div id="process_status"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="process_close_btn">Kapat</button>
                <button type="button" class="btn btn-success" onclick="startProcess()" id="process_start_btn">
                    <i class="fas fa-play"></i> Başlat
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let configModal, processModal, debugModal;

document.addEventListener('DOMContentLoaded', function() {
    configModal = new bootstrap.Modal(document.getElementById('configModal'));
    processModal = new bootstrap.Modal(document.getElementById('processModal'));
    debugModal = new bootstrap.Modal(document.getElementById('debugModal'));
    
    // Varsayılan tarihler (son 7 gün)
    const today = new Date();
    const weekAgo = new Date();
    weekAgo.setDate(weekAgo.getDate() - 7);
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    
    document.getElementById('process_end_date').value = today.toISOString().split('T')[0];
    document.getElementById('process_start_date').value = weekAgo.toISOString().split('T')[0];
    document.getElementById('debug_date').value = yesterday.toISOString().split('T')[0];
});

function editConfig(id) {
    fetch('?action=get_operator&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('config_operator_id').value = data.operator.voip_operator_id;
                document.getElementById('config_cookie').value = data.operator.voip_operator_sippy_cookie || '';
                document.getElementById('config_url').value = data.operator.voip_operator_sippy_base_url || '';
                document.getElementById('config_customer').value = data.operator.voip_operator_sippy_customer_id || '';
                configModal.show();
            }
        });
}

function saveConfig() {
    const formData = new FormData();
    formData.append('action', 'save_config');
    formData.append('operator_id', document.getElementById('config_operator_id').value);
    formData.append('cookie', document.getElementById('config_cookie').value);
    formData.append('url', document.getElementById('config_url').value);
    formData.append('customer', document.getElementById('config_customer').value);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Ayarlar kaydedildi!');
            configModal.hide();
            location.reload();
        } else {
            alert('Hata: ' + data.message);
        }
    });
}

function debugOperator(id) {
    fetch('?action=get_operator&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const op = data.operator;
                document.getElementById('debug_operator_id').value = op.voip_operator_id;
                document.getElementById('debug_operator_name').textContent = op.voip_operator_adi;
                document.getElementById('debug_cookie').value = op.voip_operator_sippy_cookie;
                document.getElementById('debug_url').value = op.voip_operator_sippy_base_url;
                document.getElementById('debug_result').style.display = 'none';
                debugModal.show();
            }
        });
}

function runDebugTest() {
    const date = document.getElementById('debug_date').value;
    const cookie = document.getElementById('debug_cookie').value;
    const baseUrl = document.getElementById('debug_url').value;
    
    if (!date) {
        alert('Lütfen test tarihi seçin!');
        return;
    }
    
    // Tarihi formatla
    const dateObj = new Date(date);
    const nextDay = new Date(dateObj);
    nextDay.setDate(nextDay.getDate() + 1);
    
    const formatDate = (d) => {
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        return `${day}-${month}-${year} 00:00:00`;
    };
    
    // URL oluştur
    let url = baseUrl.replace(/\?$/, '');
    url += '?startDate=' + encodeURIComponent(formatDate(dateObj));
    url += '&caller=0_0';
    url += '&endDate=' + encodeURIComponent(formatDate(nextDay));
    url += '&cdr_currency=TRY&group_by=5&calls_select=4&from_form=1&action=csv';
    
    // Cookie temizle
    let cleanCookie = cookie;
    if (cookie.indexOf(';') !== -1) {
        const match = cookie.match(/PHPSESSID=([^;]+)/);
        if (match) {
            cleanCookie = 'PHPSESSID=' + match[1];
        }
    }
    
    // Sonuç alanını göster
    document.getElementById('debug_result').style.display = 'block';
    document.getElementById('debug_request_url').textContent = url;
    document.getElementById('debug_request_cookie').textContent = cleanCookie;
    document.getElementById('debug_response_info').innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> İstek gönderiliyor...</div>';
    document.getElementById('debug_parse_card').style.display = 'none';
    
    // Test isteği gönder
    const formData = new FormData();
    formData.append('action', 'debug_test');
    formData.append('url', url);
    formData.append('cookie', cleanCookie);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const result = data.result;
            
            // Yanıt başlığı
            const headerClass = result.http_code === 200 ? 'bg-success' : 'bg-danger';
            document.getElementById('debug_response_header').className = 'card-header text-white ' + headerClass;
            
            // Yanıt bilgileri
            let html = '<div class="alert alert-' + (result.http_code === 200 ? 'success' : 'danger') + '">';
            html += '<strong>HTTP Kodu:</strong> ' + result.http_code + '<br>';
            html += '<strong>Content-Type:</strong> ' + (result.content_type || '-') + '<br>';
            html += '<strong>Yanıt Boyutu:</strong> ' + result.size + ' byte<br>';
            if (result.error) {
                html += '<strong>Hata:</strong> ' + result.error;
            }
            html += '</div>';
            
            // İçerik önizleme
            if (result.preview) {
                html += '<div class="card"><div class="card-header">Yanıt İçeriği (İlk 2000 karakter)</div>';
                html += '<div class="card-body"><pre style="max-height: 300px; overflow: auto;">' + result.preview + '</pre></div></div>';
            }
            
            document.getElementById('debug_response_info').innerHTML = html;
            
            // Parse sonucu
            if (result.rows && result.rows.length > 0) {
                document.getElementById('debug_parse_card').style.display = 'block';
                
                let parseHtml = '<p><strong>Format:</strong> ' + (result.is_excel ? 'Excel (.xlsx)' : 'CSV') + '</p>';
                parseHtml += '<p><strong>Toplam Satır:</strong> ' + result.rows.length + '</p>';
                parseHtml += '<div class="table-responsive"><table class="table table-sm table-bordered">';
                
                for (let i = 0; i < Math.min(10, result.rows.length); i++) {
                    parseHtml += '<tr>';
                    result.rows[i].forEach(col => {
                        parseHtml += '<td><small>' + (col || '') + '</small></td>';
                    });
                    parseHtml += '</tr>';
                }
                
                if (result.rows.length > 10) {
                    parseHtml += '<tr><td colspan="100"><small><em>... ' + (result.rows.length - 10) + ' satır daha</em></small></td></tr>';
                }
                
                parseHtml += '</table></div>';
                document.getElementById('debug_parse_result').innerHTML = parseHtml;
            }
        } else {
            document.getElementById('debug_response_info').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
        }
    })
    .catch(err => {
        document.getElementById('debug_response_info').innerHTML = '<div class="alert alert-danger">İstek hatası: ' + err.message + '</div>';
    });
}

function processOperator(id) {
    document.getElementById('process_operator_id').value = id;
    document.getElementById('process_progress').style.display = 'none';
    document.getElementById('process_status').innerHTML = '';
    document.getElementById('process_bar').style.width = '0%';
    document.getElementById('process_bar').textContent = '0%';
    document.getElementById('process_start_btn').disabled = false;
    document.getElementById('process_close_btn').disabled = false;
    processModal.show();
}

function startProcess() {
    const operatorId = document.getElementById('process_operator_id').value;
    const startDate = document.getElementById('process_start_date').value;
    const endDate = document.getElementById('process_end_date').value;
    
    if (!startDate || !endDate) {
        alert('Lütfen tarih aralığı seçin!');
        return;
    }
    
    console.log('İşlem başlatılıyor:', {operatorId, startDate, endDate});
    
    // Butonları devre dışı bırak
    document.getElementById('process_start_btn').disabled = true;
    document.getElementById('process_close_btn').disabled = true;
    document.getElementById('process_progress').style.display = 'block';
    
    // İlerleme başlangıcı
    document.getElementById('process_status').innerHTML = 
        '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> İşlem başlatılıyor...</div>';
    
    const formData = new FormData();
    formData.append('action', 'process_dates');
    formData.append('operator_id', operatorId);
    formData.append('start_date', startDate);
    formData.append('end_date', endDate);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ' hatası');
        }
        return response.text();
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            const data = JSON.parse(text);
            console.log('Parsed data:', data);
            
            if (data.success) {
                showResults(data.results);
            } else {
                document.getElementById('process_status').innerHTML = 
                    '<div class="alert alert-danger"><strong><i class="fas fa-exclamation-triangle"></i> Hata!</strong><br>' + 
                    data.message + '</div>';
            }
        } catch (e) {
            console.error('JSON parse hatası:', e);
            document.getElementById('process_status').innerHTML = 
                '<div class="alert alert-danger"><strong><i class="fas fa-exclamation-triangle"></i> Parse Hatası!</strong><br>' + 
                'Sunucu yanıtı: <pre>' + text.substring(0, 500) + '</pre></div>';
        }
        document.getElementById('process_close_btn').disabled = false;
    })
    .catch(err => {
        console.error('Fetch hatası:', err);
        document.getElementById('process_status').innerHTML = 
            '<div class="alert alert-danger"><strong><i class="fas fa-exclamation-triangle"></i> İstek Hatası!</strong><br>' + 
            err.message + '</div>';
        document.getElementById('process_close_btn').disabled = false;
    });
}

function showResults(results) {
    const bar = document.getElementById('process_bar');
    const percent = Math.round((results.processed_days / results.total_days) * 100);
    bar.style.width = percent + '%';
    bar.textContent = percent + '%';
    
    let html = '<div class="alert alert-success"><h6><i class="fas fa-check-circle"></i> İşlem Tamamlandı</h6>';
    html += '<ul class="mb-0">';
    html += '<li><strong>Toplam Gün:</strong> ' + results.total_days + '</li>';
    html += '<li><strong>İşlenen Gün:</strong> ' + results.processed_days + '</li>';
    html += '<li><strong>Toplam Kayıt:</strong> ' + results.total_records + '</li>';
    html += '<li><strong>Eklenen:</strong> ' + results.added_records + '</li>';
    html += '<li><strong>Güncellenen:</strong> ' + results.updated_records + '</li>';
    html += '</ul></div>';
    
    if (results.details && results.details.length > 0) {
        html += '<h6 class="mt-3">Detaylar:</h6>';
        html += '<table class="table table-sm table-bordered"><thead><tr>';
        html += '<th>Tarih</th><th>Durum</th><th>Kayıt</th><th>Eklenen</th><th>Güncellenen</th>';
        html += '</tr></thead><tbody>';
        
        results.details.forEach(d => {
            html += '<tr>';
            html += '<td>' + d.date + '</td>';
            html += '<td>';
            if (d.status === 'success') {
                html += '<span class="badge bg-success">Başarılı</span>';
            } else {
                html += '<span class="badge bg-danger">Hata</span>';
                if (d.message) html += '<br><small>' + d.message + '</small>';
            }
            html += '</td>';
            html += '<td>' + (d.records || 0) + '</td>';
            html += '<td>' + (d.added || 0) + '</td>';
            html += '<td>' + (d.updated || 0) + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
    }
    
    if (results.errors && results.errors.length > 0) {
        html += '<div class="alert alert-warning mt-3"><h6>Hatalar:</h6><ul>';
        results.errors.forEach(err => {
            html += '<li>' + err + '</li>';
        });
        html += '</ul></div>';
    }
    
    document.getElementById('process_status').innerHTML = html;
}
</script>
<?php include '../../../includes/footer.php'; ?>
