<?php
/**
 * SESSION DEBUG SAYFASI
 * Başvuru sistemi session durumunu kontrol et
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 * @phone +90 501 357 10 85
 */

session_start();

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Debug - Başvuru Sistemi</title>
    <style>
        body { 
            font-family: 'Consolas', 'Courier New', monospace; 
            padding: 20px; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .section { 
            margin: 20px 0; 
            padding: 20px; 
            background: #2d2d2d; 
            border-radius: 8px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .key { color: #9cdcfe; font-weight: bold; }
        .value { color: #ce9178; }
        h1 { color: #4ec9b0; border-bottom: 2px solid #4ec9b0; padding-bottom: 10px; }
        h2 { color: #4ec9b0; margin-top: 0; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        pre { 
            background: #1e1e1e; 
            padding: 15px; 
            border-radius: 5px; 
            overflow-x: auto; 
            border-left: 3px solid #4ec9b0;
        }
        a { 
            color: #569cd6; 
            text-decoration: none; 
            padding: 8px 15px; 
            display: inline-block; 
            background: #264f78; 
            border-radius: 5px; 
            margin: 5px;
            transition: background 0.3s;
        }
        a:hover { background: #1e3a5f; }
        .btn-clear {
            background: #a31515;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-clear:hover { background: #8b1010; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Session Debug - Başvuru Sistemi</h1>
        
        <div class="section">
            <h2>📋 Session Durumu</h2>
            <p><span class="key">Session ID:</span> <span class="value"><?php echo session_id(); ?></span></p>
            <p><span class="key">Session Status:</span> <span class="value"><?php echo session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE'; ?></span></p>
            <p><span class="key">Timestamp:</span> <span class="value"><?php echo date('Y-m-d H:i:s'); ?></span></p>
        </div>

        <div class="section">
            <h2>🎯 Başvuru Session Değişkenleri</h2>
            
            <?php if (isset($_SESSION['basvuru_kimlik'])): ?>
                <p class="success">✅ basvuru_kimlik: VAR</p>
                <pre><?php print_r($_SESSION['basvuru_kimlik']); ?></pre>
            <?php else: ?>
                <p class="error">❌ basvuru_kimlik: YOK</p>
            <?php endif; ?>

            <?php if (isset($_SESSION['basvuru_adres'])): ?>
                <p class="success">✅ basvuru_adres: VAR</p>
                <pre><?php print_r($_SESSION['basvuru_adres']); ?></pre>
            <?php else: ?>
                <p class="error">❌ basvuru_adres: YOK</p>
            <?php endif; ?>

            <?php if (isset($_SESSION['basvuru_paket'])): ?>
                <p class="success">✅ basvuru_paket: VAR</p>
                <pre><?php print_r($_SESSION['basvuru_paket']); ?></pre>
            <?php else: ?>
                <p class="error">❌ basvuru_paket: YOK</p>
            <?php endif; ?>

            <?php if (isset($_SESSION['basvuru_id'])): ?>
                <p class="success">✅ basvuru_id: <span class="value"><?php echo $_SESSION['basvuru_id']; ?></span></p>
            <?php else: ?>
                <p class="error">❌ basvuru_id: YOK</p>
            <?php endif; ?>

            <?php if (isset($_SESSION['basvuru_params'])): ?>
                <p class="success">✅ basvuru_params: VAR</p>
                <pre><?php print_r($_SESSION['basvuru_params']); ?></pre>
            <?php else: ?>
                <p class="error">❌ basvuru_params: YOK</p>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>📦 Tüm Session İçeriği</h2>
            <?php if (empty($_SESSION)): ?>
                <p class="warning">⚠️ Session boş</p>
            <?php else: ?>
                <pre><?php print_r($_SESSION); ?></pre>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>🔗 Test Linkleri</h2>
            <div style="margin: 10px 0;">
                <a href="../basvuru.php?api_ID=7&kampanya=2">🔗 Neo Başvuru (Paket Seçimli)</a>
                <a href="../basvuru.php?api_ID=7&kampanya=2&paket=37">🔗 Neo Başvuru (Direkt Paket)</a>
                <a href="../basvuru.php?api_ID=7&kampanya=1">🔗 Satellite Başvuru</a>
                <a href="../basvuru.php?api_ID=7">🔗 Genel Başvuru</a>
            </div>
            <div style="margin: 10px 0;">
                <a href="check_inserts.php">📊 Son Kayıtlar</a>
                <a href="session_debug.php">🔄 Yenile</a>
            </div>
            <div style="margin: 20px 0;">
                <form method="post" style="display: inline;">
                    <button type="submit" name="clear_session" class="btn-clear">🗑️ Session Temizle</button>
                </form>
            </div>
        </div>
    </div>

    <?php
    // Session temizleme
    if (isset($_POST['clear_session'])) {
        session_destroy();
        echo '<script>alert("Session temizlendi!"); window.location.href = "session_debug.php";</script>';
    }
    ?>
</body>
</html>
