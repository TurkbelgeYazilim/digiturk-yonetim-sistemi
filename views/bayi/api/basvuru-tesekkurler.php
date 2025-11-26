<?php
/**
 * Başvuru Sayfası - Teşekkürler (Final Sayfa)
 * 
 * ⚠️ ÖNEMLİ İSTİSNA: Bu sayfa dışarıdan (public) erişime açıktır!
 * - Auth kontrolü YOK
 * - Yetki kontrolü YOK
 * - Header/Footer/Navigation YOK, Kullanma!
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 * @phone +90 501 357 10 85
 */

// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Başvuru tamamlanmış mı kontrol et
if (!isset($_SESSION['basvuru_id']) || !isset($_SESSION['basvuru_kimlik'])) {
    header('Location: basvuru.php');
    exit;
}

// Başvuru bilgilerini al
$basvuruId = $_SESSION['basvuru_id'];
$kimlik = $_SESSION['basvuru_kimlik'];
$firstName = isset($kimlik['firstName']) ? $kimlik['firstName'] : '';
$surname = isset($kimlik['surname']) ? $kimlik['surname'] : '';
$email = isset($kimlik['email']) ? $kimlik['email'] : '';

// URL parametrelerini al (Yeni başvuru butonu için)
$apiId = isset($_GET['api_ID']) ? intval($_GET['api_ID']) : (isset($_SESSION['basvuru_params']['api_ID']) ? $_SESSION['basvuru_params']['api_ID'] : null);
$yeniBasvuruLink = $apiId ? 'basvuru.php?api_ID=' . $apiId : 'basvuru.php';

// Session'ı temizle (başvuru tamamlandı)
// NOT: İsterseniz session'ı burada temizleyebilirsiniz
// unset($_SESSION['basvuru_id']);
// unset($_SESSION['basvuru_kimlik']);
// unset($_SESSION['basvuru_adres']);
// unset($_SESSION['basvuru_paket']);
// unset($_SESSION['basvuru_params']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Digiturk Başvuru - Teşekkürler">
    <title>Teşekkürler - Digiturk Başvuru</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .tesekkur-container {
            padding: 40px 20px;
        }
        
        .tesekkur-card {
            background: white;
            padding: 60px 40px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            max-width: 700px;
            margin: 0 auto;
        }
        
        .success-icon {
            font-size: 6rem;
            color: #10b981;
            animation: scaleIn 0.5s ease-out;
            margin-bottom: 30px;
        }
        
        @keyframes scaleIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .tesekkur-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }
        
        .tesekkur-subtitle {
            font-size: 1.3rem;
            color: #10b981;
            font-weight: 600;
            margin-bottom: 30px;
        }
        
        .tesekkur-text {
            font-size: 1.1rem;
            color: #666;
            line-height: 1.8;
            margin-bottom: 30px;
        }
        
        .basvuru-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px;
            border-radius: 12px;
            color: white;
            margin-bottom: 30px;
        }
        
        .basvuru-info h5 {
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }
        
        .basvuru-info p {
            margin-bottom: 8px;
            font-size: 1rem;
        }
        
        .basvuru-info strong {
            font-weight: 600;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 12px;
            border-left: 4px solid #10b981;
            margin-bottom: 30px;
        }
        
        .info-box h5 {
            color: #333;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .steps-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .steps-list li {
            padding: 12px 0;
            color: #666;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
        }
        
        .steps-list i {
            color: #10b981;
            font-size: 1.3rem;
            margin-right: 12px;
            min-width: 24px;
        }
        
        .contact-info {
            padding: 25px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .contact-info h6 {
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .contact-info p {
            margin-bottom: 8px;
            color: #666;
        }
        
        .contact-info a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-info a:hover {
            text-decoration: underline;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
        }
        
        @media (max-width: 768px) {
            .tesekkur-card {
                padding: 40px 25px;
            }
            
            .tesekkur-title {
                font-size: 1.8rem;
            }
            
            .tesekkur-subtitle {
                font-size: 1.1rem;
            }
            
            .success-icon {
                font-size: 4rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="tesekkur-container">
    <div class="tesekkur-card text-center">
        <!-- Success Icon -->
        <div class="success-icon">
            <i class="bi bi-check-circle-fill"></i>
        </div>
        
        <!-- Title -->
        <h1 class="tesekkur-title">Başvurunuz Alındı!</h1>
        <p class="tesekkur-subtitle">Sayın <?php echo htmlspecialchars($firstName . ' ' . $surname); ?></p>
        
        <!-- Message -->
        <p class="tesekkur-text">
            Digiturk başvurunuz başarıyla kaydedildi. Başvuru bilgileriniz inceleniyor ve 
            en kısa sürede temsilcimiz sizinle iletişime geçecektir.
        </p>
        
        <!-- Başvuru Bilgisi -->
        <div class="basvuru-info text-start">
            <h5><i class="bi bi-file-text me-2"></i>Başvuru Bilgileriniz</h5>
            <p><strong>Başvuru Numarası:</strong> #<?php echo str_pad($basvuruId, 6, '0', STR_PAD_LEFT); ?></p>
            <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($firstName . ' ' . $surname); ?></p>
            <p><strong>E-posta:</strong> <?php echo htmlspecialchars($email); ?></p>
            <p class="mb-0"><strong>Tarih:</strong> <?php echo date('d.m.Y H:i'); ?></p>
        </div>
        
        <!-- Sonraki Adımlar -->
        <div class="info-box text-start">
            <h5><i class="bi bi-list-check me-2"></i>Sonraki Adımlar</h5>
            <ul class="steps-list">
                <li>
                    <i class="bi bi-1-circle-fill"></i>
                    <span>Başvurunuz değerlendirilecek</span>
                </li>
                <li>
                    <i class="bi bi-2-circle-fill"></i>
                    <span>Temsilcimiz sizinle iletişime geçecek</span>
                </li>
                <li>
                    <i class="bi bi-3-circle-fill"></i>
                    <span>Kurulum tarihi belirlenecek</span>
                </li>
                <li>
                    <i class="bi bi-4-circle-fill"></i>
                    <span>Profesyonel ekibimiz kurulumu yapacak</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Sayfa yüklendiğinde konfeti animasyonu (opsiyonel)
document.addEventListener('DOMContentLoaded', function() {
    console.log('✓ Başvuru tamamlandı - ID: <?php echo $basvuruId; ?>');
    
    // Tarayıcı geri butonunu engelle (opsiyonel)
    window.history.pushState(null, "", window.location.href);
    window.onpopstate = function() {
        window.history.pushState(null, "", window.location.href);
    };
});
</script>

</body>
</html>
