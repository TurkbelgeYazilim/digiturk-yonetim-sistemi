<?php
session_start();

$message = '';
$messageType = '';

// Veritabanı bağlantı fonksiyonu auth.php dosyasından include edilecek
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $message = 'E-posta adresi zorunludur.';
        $messageType = 'danger';
    } else {
        try {
            $conn = getDatabaseConnection();
            
            // Kullanıcıyı e-posta ile bul
            $sql = "SELECT id, first_name, last_name FROM users WHERE email = ? AND status = 'AKTIF'";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Gerçek bir uygulamada burada e-posta gönderilir
                // Şimdilik geçici şifre olarak "temp123" veriyoruz
                $tempPassword = 'temp123';
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                
                $updateSql = "UPDATE users SET password_hash = ?, updated_at = GETDATE() WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$passwordHash, $user['id']]);
                
                $message = 'Geçici şifreniz: <strong>temp123</strong><br>Lütfen giriş yaptıktan sonra şifrenizi değiştirin.';
                $messageType = 'success';
            } else {
                $message = 'Bu e-posta adresi ile kayıtlı aktif bir kullanıcı bulunamadı.';
                $messageType = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Sistem hatası: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - Digitürk İleka</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .forgot-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .forgot-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .forgot-body {
            padding: 2rem;
        }
        .form-control {
            border: none;
            border-bottom: 2px solid #e0e0e0;
            border-radius: 0;
            padding: 15px 0;
            background: transparent;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-bottom-color: #f59e0b;
            box-shadow: none;
            background: transparent;
        }
        .btn-reset {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.3);
        }
        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .input-icon {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            z-index: 10;
        }
        .form-control {
            padding-left: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="forgot-card">
                    <div class="forgot-header">
                        <div class="mb-3">
                            <i class="fas fa-key fa-3x"></i>
                        </div>
                        <h3 class="mb-0">Şifremi Unuttum</h3>
                        <p class="mb-0">E-posta adresinizi girin</p>
                    </div>
                    
                    <div class="forgot-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                <?php echo $message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="forgotForm">
                            <div class="input-group">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       placeholder="E-posta adresiniz"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       required>
                            </div>
                            
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-warning btn-reset btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>
                                    Şifre Sıfırla
                                </button>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <a href="login.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>
                                Giriş Sayfasına Dön
                            </a>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Geçici şifre ile giriş yaptıktan sonra şifrenizi değiştirmeyi unutmayın.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validasyonu
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            
            if (!email) {
                e.preventDefault();
                alert('Lütfen e-posta adresinizi giriniz.');
                return false;
            }
            
            // Email format kontrolü
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Lütfen geçerli bir e-posta adresi giriniz.');
                return false;
            }
            
            // Loading göster
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>İşleniyor...';
            submitBtn.disabled = true;
            
            // Hata durumunda loading'i geri al
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
        
        // Auto focus
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>
