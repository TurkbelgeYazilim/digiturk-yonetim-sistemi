<?php
session_start();

// Veritabanı bağlantı fonksiyonu auth.php dosyasından include edilecek
require_once 'auth.php';

// Zaten giriş yapmışsa ana sayfaya yönlendir
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$info = '';

// URL'den gelen hata mesajlarını göster
if (isset($_GET['error'])) {
    $errorCode = $_GET['error'];
    switch ($errorCode) {
        case 'account_deactivated':
            $error = 'Hesabınız deaktif durumda. Yöneticiye başvurunuz.';
            break;
        case 'session_timeout':
            $error = 'Oturumunuzun süresi doldu. Lütfen tekrar giriş yapınız.';
            break;
        case 'system_error':
            $error = 'Sistem hatası oluştu. Lütfen daha sonra tekrar deneyiniz.';
            break;
        case 'unauthorized':
            $error = 'Bu sayfaya erişim yetkiniz yok.';
            break;
        default:
            $error = 'Bilinmeyen bir hata oluştu.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'E-posta ve şifre alanları zorunludur.';
    } else {
        try {
            $conn = getDatabaseConnection();
            
            // Kullanıcıyı e-posta ile bul (grup bilgileri ile birlikte)
            $sql = "SELECT u.id, u.email, u.password_hash, u.first_name, u.last_name, u.phone, u.status, 
                           u.login_attempts, u.locked_until, u.user_group_id,
                           ug.group_name, ug.group_description
                    FROM users u 
                    LEFT JOIN user_groups ug ON u.user_group_id = ug.id
                    WHERE u.email = ? AND u.status = 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Hesap kilitli mi kontrol et
                if ($user['locked_until'] && new DateTime($user['locked_until']) > new DateTime()) {
                    $lockTime = new DateTime($user['locked_until']);
                    $error = 'Hesabınız ' . $lockTime->format('d.m.Y H:i') . ' tarihine kadar kilitlidir.';
                } else {
                    // Şifre doğru mu kontrol et
                    if (password_verify($password, $user['password_hash'])) {
                        // Başarılı giriş
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['user_phone'] = $user['phone'];
                        $_SESSION['user_group_id'] = $user['user_group_id'];
                        $_SESSION['user_group_name'] = $user['group_name'];
                        $_SESSION['user_permissions'] = null; // permissions alanı yok
                        
                        // Login attempts sıfırla ve son giriş tarihini güncelle
                        $updateSql = "UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = GETDATE() WHERE id = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->execute([$user['id']]);
                        
                        header('Location: index.php');
                        exit;
                    } else {
                        // Yanlış şifre - giriş denemesi arttır
                        $attempts = $user['login_attempts'] + 1;
                        $lockUntil = null;
                        
                        // 5 yanlış denemeden sonra hesabı 30 dakika kilitle
                        if ($attempts >= 5) {
                            $lockUntil = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                            $error = 'Çok fazla yanlış deneme yaptınız. Hesabınız 30 dakika süreyle kilitlendi.';
                        } else {
                            $remaining = 5 - $attempts;
                            $error = "Yanlış şifre. Kalan deneme hakkınız: $remaining";
                        }
                        
                        $updateSql = "UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->execute([$attempts, $lockUntil, $user['id']]);
                    }
                }
            } else {
                $error = 'Geçersiz e-posta adresi veya hesabınız aktif değil.';
            }
        } catch (Exception $e) {
            $error = 'Sistem hatası: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Digitürk İleka</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-body {
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
            border-bottom-color: #667eea;
            box-shadow: none;
            background: transparent;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
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
                <div class="login-card">
                    <div class="login-header">
                        <div class="mb-3">
                            <img src="assets/images/logo.png" alt="İleka Logo" style="height: 60px;" class="mb-3">
                        </div>
                    </div>
                    
                    <div class="login-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Hata!</strong> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($info): ?>
                            <div class="alert alert-info alert-dismissible fade show" role="alert">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php echo htmlspecialchars($info); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Başarılı!</strong> <?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="loginForm">
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
                            
                            <div class="input-group">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Şifreniz"
                                       required>
                                <i class="fas fa-eye-slash position-absolute end-0 top-50 translate-middle-y me-3" 
                                   style="cursor: pointer; z-index: 10;" 
                                   onclick="togglePassword()"
                                   id="passwordToggle"></i>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                                        <label class="form-check-label text-muted" for="remember_me">
                                            Beni hatırla
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 text-end">
                                    <a href="forgot-password.php" class="text-decoration-none">
                                        <small>Şifremi unuttum</small>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-login btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>
                                    Giriş Yap
                                </button>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="text-muted mb-0">
                                <small>
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Güvenli bağlantı ile korunmaktadır
                                </small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Şifre görünürlüğü toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        }
        
        // Form validasyonu
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Lütfen tüm alanları doldurunuz.');
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
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Giriş yapılıyor...';
            submitBtn.disabled = true;
            
            // Hata durumunda loading'i geri al
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
        
        // Enter tuşu ile form submit
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });
        
        // Auto focus
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>
