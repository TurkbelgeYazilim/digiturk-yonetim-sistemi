<?php
/**
 * BAŞVURU KAYITLARI KONTROL SAYFASI
 * Son başvuru kayıtlarını görüntüle ve kontrol et
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 * @phone +90 501 357 10 85
 */

require_once '../../../../config/mssql.php';

function getDatabaseConnection() {
    $configFile = __DIR__ . '/../../../../config/mssql.php';
    $config = include $configFile;
    
    try {
        $dsn = "sqlsrv:Server={$config['host']};Database={$config['database']};TrustServerCertificate=yes";
        $connection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $connection;
    } catch (PDOException $e) {
        die('Veritabanı bağlantı hatası: ' . $e->getMessage());
    }
}

$conn = getDatabaseConnection();

// Limit parametresi
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
if ($limit > 100) $limit = 100; // Maksimum 100

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Başvuru Kayıtları - Debug</title>
    <style>
        body { 
            font-family: 'Consolas', 'Courier New', monospace; 
            padding: 20px; 
            background: #1e1e1e; 
            color: #d4d4d4; 
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #4ec9b0; border-bottom: 2px solid #4ec9b0; padding-bottom: 10px; }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0; 
            background: #2d2d2d; 
            font-size: 12px;
        }
        th, td { 
            padding: 10px; 
            border: 1px solid #444; 
            text-align: left; 
            white-space: nowrap;
        }
        th { background: #3d3d3d; color: #4ec9b0; font-weight: bold; }
        tr:hover { background: #383838; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .null { color: #808080; font-style: italic; }
        .info-box {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #4ec9b0;
        }
        a { 
            color: #569cd6; 
            text-decoration: none; 
            padding: 8px 15px; 
            display: inline-block; 
            background: #264f78; 
            border-radius: 5px; 
            margin: 5px;
        }
        a:hover { background: #1e3a5f; }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #4ec9b0;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #4ec9b0;
        }
        .stat-label {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Başvuru Kayıtları - Debug</h1>
        
        <?php
        try {
            // İstatistikler
            $statsNeo = $conn->query("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi] WHERE [API_basvuru_CampaignList_ID] = 2")->fetch();
            $statsSat = $conn->query("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi] WHERE [API_basvuru_CampaignList_ID] = 1")->fetch();
            $statsNull = $conn->query("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi] WHERE [API_basvuru_CampaignList_ID] IS NULL")->fetch();
            $statsTotal = $conn->query("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi]")->fetch();
            $statsToday = $conn->query("SELECT COUNT(*) as count FROM [dbo].[API_basvuruListesi] WHERE CAST([API_basvuru_olusturma_tarih] AS DATE) = CAST(GETDATE() AS DATE)")->fetch();
            
            echo '<div class="stats">';
            echo '<div class="stat-card">';
            echo '<div class="stat-value">' . $statsTotal['count'] . '</div>';
            echo '<div class="stat-label">Toplam Başvuru</div>';
            echo '</div>';
            
            echo '<div class="stat-card">';
            echo '<div class="stat-value">' . $statsToday['count'] . '</div>';
            echo '<div class="stat-label">Bugünkü Başvurular</div>';
            echo '</div>';
            
            echo '<div class="stat-card">';
            echo '<div class="stat-value">' . $statsNeo['count'] . '</div>';
            echo '<div class="stat-label">Neo Kampanya</div>';
            echo '</div>';
            
            echo '<div class="stat-card">';
            echo '<div class="stat-value">' . $statsSat['count'] . '</div>';
            echo '<div class="stat-label">Satellite Kampanya</div>';
            echo '</div>';
            
            echo '<div class="stat-card">';
            echo '<div class="stat-value class="warning">' . $statsNull['count'] . '</div>';
            echo '<div class="stat-label">Kampanya Belirsiz</div>';
            echo '</div>';
            echo '</div>';
            
            // Son kayıtları getir
            $sql = "
                SELECT TOP {$limit}
                    [API_basvuru_ID],
                    [API_basvuru_firstName],
                    [API_basvuru_surname],
                    [API_basvuru_email],
                    [API_basvuru_phoneAreaNumber],
                    [API_basvuru_phoneNumber],
                    [API_basvuru_citizenNumber],
                    [API_basvuru_bbkAddressCode],
                    [API_basvuru_kullanici_ID],
                    [API_basvuru_CampaignList_ID],
                    [API_basvuru_Paket_ID],
                    [API_basvuru_basvuru_durum_ID],
                    [API_basvuru_olusturma_tarih]
                FROM [dbo].[API_basvuruListesi]
                ORDER BY [API_basvuru_olusturma_tarih] DESC
            ";
            
            $stmt = $conn->query($sql);
            $kayitlar = $stmt->fetchAll();
            
            if (count($kayitlar) > 0) {
                echo '<div class="info-box">';
                echo '<span class="success">✅ Son ' . count($kayitlar) . ' kayıt gösteriliyor</span>';
                echo ' | <a href="?limit=50">50 Kayıt</a>';
                echo ' | <a href="?limit=100">100 Kayıt</a>';
                echo '</div>';
                
                echo '<div style="overflow-x: auto;">';
                echo '<table>';
                echo '<thead><tr>';
                echo '<th>ID</th>';
                echo '<th>Ad Soyad</th>';
                echo '<th>Email</th>';
                echo '<th>Telefon</th>';
                echo '<th>TC</th>';
                echo '<th>BBK Code</th>';
                echo '<th>User</th>';
                echo '<th>Kampanya</th>';
                echo '<th>Paket</th>';
                echo '<th>Durum</th>';
                echo '<th>Tarih</th>';
                echo '</tr></thead>';
                echo '<tbody>';
                
                foreach ($kayitlar as $kayit) {
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($kayit['API_basvuru_ID']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($kayit['API_basvuru_firstName'] . ' ' . $kayit['API_basvuru_surname']) . '</td>';
                    echo '<td>' . htmlspecialchars($kayit['API_basvuru_email'] ?? '-') . '</td>';
                    echo '<td>' . htmlspecialchars($kayit['API_basvuru_phoneAreaNumber'] . ' ' . $kayit['API_basvuru_phoneNumber']) . '</td>';
                    echo '<td>' . htmlspecialchars($kayit['API_basvuru_citizenNumber']) . '</td>';
                    
                    // BBK Code
                    if ($kayit['API_basvuru_bbkAddressCode']) {
                        echo '<td class="success">' . htmlspecialchars($kayit['API_basvuru_bbkAddressCode']) . '</td>';
                    } else {
                        echo '<td class="null">NULL</td>';
                    }
                    
                    // User ID
                    echo '<td>' . ($kayit['API_basvuru_kullanici_ID'] ?? '<span class="null">NULL</span>') . '</td>';
                    
                    // Kampanya
                    if ($kayit['API_basvuru_CampaignList_ID'] == 1) {
                        echo '<td class="warning">Satellite</td>';
                    } elseif ($kayit['API_basvuru_CampaignList_ID'] == 2) {
                        echo '<td class="success">Neo</td>';
                    } else {
                        echo '<td class="error">NULL</td>';
                    }
                    
                    // Paket
                    echo '<td>' . ($kayit['API_basvuru_Paket_ID'] ?? '<span class="null">NULL</span>') . '</td>';
                    
                    // Durum
                    echo '<td>' . ($kayit['API_basvuru_basvuru_durum_ID'] ?? '1') . '</td>';
                    
                    // Tarih
                    echo '<td>' . ($kayit['API_basvuru_olusturma_tarih'] ? date('d.m.Y H:i', strtotime($kayit['API_basvuru_olusturma_tarih'])) : '-') . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
                echo '</div>';
                
            } else {
                echo '<p class="error">❌ Hiç kayıt bulunamadı!</p>';
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Hata: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
        
        <hr style="border-color: #444; margin: 30px 0;">
        <div>
            <a href="session_debug.php">🔍 Session Debug</a>
            <a href="check_inserts.php">🔄 Yenile</a>
            <a href="../basvuru.php?api_ID=7&kampanya=2">📝 Yeni Başvuru (Neo)</a>
        </div>
    </div>
</body>
</html>
