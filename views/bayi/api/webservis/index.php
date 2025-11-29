<?php
/**
 * Digiturk Web Servis API Dokümantasyon Arayüzü
 * Swagger benzeri interaktif API test ve dokümantasyon sayfası
 * 
 * @author Batuhan Kahraman
 * @email batuhan.kahraman@ileka.com.tr
 */

// Hata gösterimini aç (geliştirme için)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Auth kontrol
require_once '../../../../auth.php';

// Session başlat
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Kullanıcı kontrolü
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

// CORS ayarları
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Config dosyası
require_once '../../../../config/mssql.php';

// getDatabaseConnection() auth.php'den gelir, tekrar tanımlamaya gerek yok

// Kullanıcının API ID'lerini çek
$userApiIds = [];
$defaultApiId = null;
$isAdmin = isset($currentUser['group_id']) && (int)$currentUser['group_id'] === 1;

try {
    $conn = getDatabaseConnection();
    
    // Admin ise tüm API kullanıcılarını getir, değilse sadece kendi kullanıcısına ait olanları
    if ($isAdmin) {
        $sql = "SELECT DISTINCT 
                    api_iris_kullanici_ID, 
                    api_iris_kullanici_LoginCd 
                FROM API_kullanici 
                WHERE api_iris_kullanici_LoginCd IS NOT NULL 
                ORDER BY api_iris_kullanici_LoginCd";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    } else {
        $sql = "SELECT DISTINCT 
                    api_iris_kullanici_ID, 
                    api_iris_kullanici_LoginCd 
                FROM API_kullanici 
                WHERE users_ID = ? 
                AND api_iris_kullanici_LoginCd IS NOT NULL 
                ORDER BY api_iris_kullanici_LoginCd";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$currentUser['id']]);
    }
    
    $records = $stmt->fetchAll();
    
    // Benzersiz loginCode'ları topla
    $uniqueLogins = [];
    foreach ($records as $record) {
        $loginCd = trim((string)$record['api_iris_kullanici_LoginCd']);
        if ($loginCd !== '' && !array_key_exists($loginCd, $uniqueLogins)) {
            $uniqueLogins[$loginCd] = (int)$record['api_iris_kullanici_ID'];
        }
    }
    
    foreach ($uniqueLogins as $loginCd => $apiId) {
        $userApiIds[] = [
            'id' => $apiId,
            'login_code' => $loginCd
        ];
    }
    
    // İlk API ID'yi default olarak seç
    if (!empty($userApiIds)) {
        $defaultApiId = $userApiIds[0]['id'];
    }
    
} catch (Exception $e) {
    error_log("API ID çekme hatası: " . $e->getMessage());
}

// API Endpoint tanımları
$apiEndpoints = [
    [
        'name' => 'Başvuru Formu',
        'method' => 'GET',
        'endpoint' => '/views/bayi/api/webservis/endpoints/basvuru-form.php',
        'description' => 'Başvuru formunu HTML olarak döner (iframe için kullanılır). Basic Auth gerektirir.',
        'params' => [
            ['name' => 'kampanya', 'type' => 'integer', 'required' => false, 'description' => 'Kampanya ID (1=Kutolu, 2=Neo)'],
            ['name' => 'paket', 'type' => 'integer', 'required' => false, 'description' => 'Paket ID (ön seçili paket)']
        ],
        'response' => [
            'type' => 'HTML',
            'description' => 'Başvuru formu HTML içeriği (redirect)'
        ],
        'example' => '?kampanya=1&paket=5'
    ],
    [
        'name' => 'Kampanya Listesi',
        'method' => 'GET',
        'endpoint' => '/views/bayi/api/webservis/endpoints/campaigns.php',
        'description' => 'Aktif kampanya listesini döner. Basic Auth gerektirir.',
        'params' => [],
        'response' => [
            'type' => 'JSON',
            'description' => 'Kampanya listesi array'
        ],
        'example' => ''
    ],
    [
        'name' => 'Paket Listesi',
        'method' => 'GET',
        'endpoint' => '/views/bayi/api/webservis/endpoints/packages.php',
        'description' => 'Kampanyaya göre paket listesini döner. Basic Auth gerektirir.',
        'params' => [
            ['name' => 'kampanya', 'type' => 'integer', 'required' => true, 'description' => 'Kampanya ID (1=Kutulu, 2=Neo)']
        ],
        'response' => [
            'type' => 'JSON',
            'description' => 'Paket listesi array'
        ],
        'example' => '?kampanya=1'
    ],
    [
        'name' => 'Ödeme Tipleri',
        'method' => 'GET',
        'endpoint' => '/views/bayi/api/webservis/endpoints/payment-types.php',
        'description' => 'Mevcut ödeme tiplerini döner. Basic Auth gerektirir.',
        'params' => [],
        'response' => [
            'type' => 'JSON',
            'description' => 'Ödeme tipi listesi (1=Kredi Kartlı, 2=Faturalı)'
        ],
        'example' => ''
    ],
    [
        'name' => 'Kullanıcı ve API Bilgisi',
        'method' => 'GET',
        'endpoint' => '/views/bayi/api/webservis/endpoints/user-info.php',
        'description' => 'Kimlik doğrulaması yapılan kullanıcının bilgilerini ve API hesaplarını döner. Basic Auth gerektirir.',
        'params' => [],
        'response' => [
            'type' => 'JSON',
            'description' => 'Kullanıcı ve API bilgileri'
        ],
        'example' => ''
    ]
];

$baseUrl = 'https://digiturk.ilekasoft.com';
$baseUrlJs = json_encode($baseUrl);

// Header için değişkenler
$pageTitle = "Web Servis API Dokümantasyonu";
$breadcrumbs = [
    ['title' => 'Web Servis API']
];

$additionalCSS = <<<CSS
<!-- Prism.js for syntax highlighting -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet">
<style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 60px 0;
            margin-bottom: 40px;
        }

        .hero-section h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .hero-section .lead {
            font-size: 1.2rem;
            opacity: 0.95;
        }

        .endpoint-card {
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .endpoint-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .endpoint-header {
            cursor: pointer;
            padding: 20px;
            background: white;
            border-left: 4px solid var(--primary-color);
        }

        .endpoint-header:hover {
            background: #f8f9fa;
        }

        .method-badge {
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.875rem;
        }

        .method-get {
            background: #e0f2fe;
            color: #0369a1;
        }

        .method-post {
            background: #dcfce7;
            color: #15803d;
        }

        .endpoint-body {
            padding: 20px;
            background: #f8f9fa;
        }

        .param-table {
            margin-top: 15px;
        }

        .param-table th {
            background: #e5e7eb;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .required-badge {
            background: var(--danger-color);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75rem;
        }

        .optional-badge {
            background: #94a3b8;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75rem;
        }

        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
            overflow-x: auto;
        }

        .try-it-section {
            background: white;
            padding: 20px;
            border-radius: 6px;
            margin-top: 15px;
            border: 2px solid #e5e7eb;
        }

        .response-preview {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }

        .embed-generator {
            background: #fef3c7;
            padding: 20px;
            border-radius: 6px;
            margin-top: 20px;
            border-left: 4px solid var(--warning-color);
        }

        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            font-size: 0.75rem;
        }

        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            margin-bottom: 20px;
        }

        .stats-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stats-card .label {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 5px;
        }

        .sidebar {
            position: sticky;
            top: 20px;
        }

        .nav-link {
            color: #334155;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 5px;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: #f1f5f9;
            color: var(--primary-color);
        }

        .nav-link.active {
            background: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .hero-section h1 {
                font-size: 1.75rem;
            }
            
            .sidebar {
                position: relative;
                top: 0;
                margin-bottom: 30px;
            }
        }
    </style>
CSS;

// Header'ı dahil et
include '../../../../includes/header.php';
?>



    <div class="container mb-5 mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="sidebar">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted mb-3">Hızlı Erişim</h6>
                            <nav class="nav flex-column">
                                <a class="nav-link active" href="#overview"><i class="fas fa-home me-2"></i>Genel Bakış</a>
                                <a class="nav-link" href="#endpoints"><i class="fas fa-plug me-2"></i>API Endpoints</a>
                                <a class="nav-link" href="#iframe-usage"><i class="fas fa-window-maximize me-2"></i>iframe Kullanımı</a>
                                <a class="nav-link" href="#authentication"><i class="fas fa-key me-2"></i>Kimlik Doğrulama</a>
                            </nav>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="stats-card mt-3">
                        <div class="number"><?php echo count($apiEndpoints); ?></div>
                        <div class="label">Aktif Endpoint</div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Overview -->
                <div id="overview" class="mb-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h3 class="mb-3"><i class="fas fa-info-circle text-primary me-2"></i>Genel Bakış</h3>
                            <p>Bu API, Digiturk başvuru formlarını web sitenize entegre etmenizi sağlar. iframe kullanarak kolayca gömebilir veya REST API ile kendi arayüzünüzü oluşturabilirsiniz.</p>
                            
                            <div class="row mt-4">
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-check-circle text-success fa-2x me-3"></i>
                                        </div>
                                        <div>
                                            <h6>iframe Desteği</h6>
                                            <p class="text-muted small mb-0">Hazır HTML formları, direkt sitenize gömün</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-check-circle text-success fa-2x me-3"></i>
                                        </div>
                                        <div>
                                            <h6>REST API</h6>
                                            <p class="text-muted small mb-0">JSON verilerle özel arayüz oluşturun</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-check-circle text-success fa-2x me-3"></i>
                                        </div>
                                        <div>
                                            <h6>CORS Desteği</h6>
                                            <p class="text-muted small mb-0">Her domainten erişim mümkün</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-check-circle text-success fa-2x me-3"></i>
                                        </div>
                                        <div>
                                            <h6>Responsive</h6>
                                            <p class="text-muted small mb-0">Mobil ve desktop uyumlu</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info mt-4">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>Base URL:</strong> <code><?php echo $baseUrl; ?></code>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Endpoints -->
                <div id="endpoints" class="mb-5">
                    <h3 class="mb-4"><i class="fas fa-plug text-primary me-2"></i>API Endpoints</h3>
                    
                    <div class="alert alert-success mb-4">
                        <i class="fas fa-rocket me-2"></i>
                        <strong>API Test:</strong> Aşağıdaki cURL örnekleri ve Postman ile test edebilirsiniz. 
                        Kullanıcı adı/şifre olarak sisteme giriş yaparken kullandığınız bilgileri kullanın.
                    </div>
                    
                    <?php foreach ($apiEndpoints as $index => $api): ?>
                    <div class="endpoint-card card" id="endpoint-<?php echo $index; ?>">
                        <div class="endpoint-header" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $index; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="method-badge method-<?php echo strtolower($api['method']); ?>">
                                            <?php echo $api['method']; ?>
                                        </span>
                                        <div>
                                            <h5 class="mb-1"><?php echo $api['name']; ?></h5>
                                            <code class="text-muted"><?php echo $api['endpoint']; ?></code>
                                        </div>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </div>
                        </div>
                        
                        <div class="collapse" id="collapse-<?php echo $index; ?>">
                            <div class="endpoint-body">
                                <p class="mb-3"><?php echo $api['description']; ?></p>

                                <?php if (!empty($api['params'])): ?>
                                <h6 class="mt-4 mb-2">Parametreler</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm param-table">
                                        <thead>
                                            <tr>
                                                <th>Parametre</th>
                                                <th>Tip</th>
                                                <th>Zorunlu</th>
                                                <th>Açıklama</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($api['params'] as $param): ?>
                                            <tr>
                                                <td><code><?php echo $param['name']; ?></code></td>
                                                <td><span class="badge bg-secondary"><?php echo $param['type']; ?></span></td>
                                                <td>
                                                    <?php if ($param['required']): ?>
                                                        <span class="required-badge">Zorunlu</span>
                                                    <?php else: ?>
                                                        <span class="optional-badge">Opsiyonel</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $param['description']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>

                                <h6 class="mt-4 mb-2">Response</h6>
                                <div class="alert alert-light">
                                    <strong>Tip:</strong> <span class="badge bg-info"><?php echo $api['response']['type']; ?></span><br>
                                    <strong>Açıklama:</strong> <?php echo $api['response']['description']; ?>
                                </div>

                                <h6 class="mt-4 mb-2">Örnek İstek</h6>
                                <div class="code-block position-relative">
                                    <button class="btn btn-sm btn-outline-light copy-btn" onclick="copyToClipboard('example-<?php echo $index; ?>')">
                                        <i class="fas fa-copy"></i> Kopyala
                                    </button>
                                    <pre id="example-<?php echo $index; ?>"><code><?php echo $baseUrl . $api['endpoint'] . $api['example']; ?></code></pre>
                                </div>


                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- iframe Kullanımı -->
                <div id="iframe-usage" class="mb-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h3 class="mb-3"><i class="fas fa-window-maximize text-primary me-2"></i>iframe'de Kullanım</h3>
                            <p>Başvuru formunu kendi web sitenize gömebilirsiniz. Tarayıcı otomatik olarak kullanıcıdan kimlik doğrulaması isteyecektir.</p>
                            
                            <h6 class="mt-4">Temel iframe Kullanımı:</h6>
                            <div class="code-block position-relative">
                                <button class="btn btn-sm btn-outline-light copy-btn" onclick="copyToClipboard('iframe-basic')">
                                    <i class="fas fa-copy"></i> Kopyala
                                </button>
                                <pre id="iframe-basic"><code>&lt;iframe 
  src="<?php echo $baseUrl; ?>/views/bayi/api/webservis/endpoints/basvuru-form.php" 
  width="100%" 
  height="800px" 
  frameborder="0" 
  scrolling="yes"&gt;
&lt;/iframe&gt;</code></pre>
                            </div>

                            <h6 class="mt-4">Kampanya ve Paket ile:</h6>
                            <div class="code-block position-relative">
                                <button class="btn btn-sm btn-outline-light copy-btn" onclick="copyToClipboard('iframe-params')">
                                    <i class="fas fa-copy"></i> Kopyala
                                </button>
                                <pre id="iframe-params"><code>&lt;iframe 
  src="<?php echo $baseUrl; ?>/views/bayi/api/webservis/endpoints/basvuru-form.php?kampanya=1&paket=5" 
  width="100%" 
  height="800px" 
  frameborder="0" 
  scrolling="yes"&gt;
&lt;/iframe&gt;</code></pre>
                            </div>

                            <div class="alert alert-info mt-4">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Not:</strong> iframe ilk açıldığında tarayıcı kullanıcıdan kullanıcı adı ve şifre isteyecektir. 
                                Bu bilgiler Basic Auth ile güvenli şekilde gönderilir.
                            </div>

                            <h6 class="mt-4">Parametreler:</h6>
                            <ul>
                                <li><code>kampanya</code> - Kampanya ID (1=Kutulu TV, 2=Kutusuz TV/NEO) - Opsiyonel</li>
                                <li><code>paket</code> - Paket ID (ön seçili paket) - Opsiyonel</li>
                            </ul>

                            <div class="alert alert-warning mt-4">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Önemli:</strong> iframe'in doğru çalışması için sitenizin HTTPS protokolü kullanması önerilir.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Authentication -->
                <div id="authentication" class="mb-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h3 class="mb-3"><i class="fas fa-key text-primary me-2"></i>Kimlik Doğrulama</h3>
                            <p>Tüm API endpoint'leri <strong>HTTP Basic Authentication</strong> gerektirir. digiturk.ilekasoft.com'a giriş yaptığınız kullanıcı adı (e-posta) ve şifrenizi kullanın.</p>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Basic Auth Kullanımı:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><strong>Username:</strong> E-posta adresiniz (örn: kullanici@firma.com)</li>
                                    <li><strong>Password:</strong> Sistem şifreniz</li>
                                    <li>API ID otomatik olarak hesabınızdan çekilir</li>
                                </ul>
                            </div>

                            <h6 class="mt-4">Postman'de Kullanım:</h6>
                            <ol>
                                <li>Authorization tab'ına git</li>
                                <li>Type olarak <code>Basic Auth</code> seç</li>
                                <li>Username: E-posta adresin</li>
                                <li>Password: Sistem şifren</li>
                                <li>Send!</li>
                            </ol>

                            <h6 class="mt-4">cURL ile Örnek:</h6>
                            <div class="code-block">
                                <pre><code>curl -u kullanici@firma.com:sifre \
  https://digiturk.ilekasoft.com/temp/webservis/endpoints/campaigns.php</code></pre>
                            </div>

                            <h6 class="mt-4">JavaScript ile Örnek:</h6>
                            <div class="code-block">
                                <pre><code>fetch('https://digiturk.ilekasoft.com/temp/webservis/endpoints/campaigns.php', {
  headers: {
    'Authorization': 'Basic ' + btoa('kullanici@firma.com:sifre')
  }
}).then(r => r.json()).then(data => console.log(data));</code></pre>
                            </div>

                            <div class="alert alert-warning mt-4">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Güvenlik:</strong> Kullanıcı adı ve şifrenizi güvenli tutun. HTTPS üzerinden iletilir.
                            </div>

                            <h6 class="mt-4">Güvenlik Özellikleri:</h6>
                            <ul>
                                <li>HTTP Basic Authentication (RFC 7617)</li>
                                <li>HTTPS zorunluluğu (TLS şifreleme)</li>
                                <li>Kullanıcı bazlı API erişim kontrolü</li>
                                <li>CORS desteği</li>
                                <li>Session bazlı form takibi</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
$additionalJS = <<<HTML
<!-- Prism.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>

<script>
HTML;

$additionalJS .= "\nconst BASE_URL = " . json_encode($baseUrl) . ";\n\n";

$additionalJS .= <<<'JS'
    // Kopyalama fonksiyonu
    function copyToClipboard(elementId) {
        const element = document.getElementById(elementId);
        const text = element.textContent;
        
        navigator.clipboard.writeText(text).then(() => {
            // Başarı mesajı göster
            const btn = event.target.closest('button');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Kopyalandı!';
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('btn-success');
            }, 2000);
        });
    }
JS;

$additionalJS .= <<<'JS'
    






    // Smooth scroll for navigation
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                
                // Update active nav
                document.querySelectorAll('.nav-link').forEach(link => {
                    link.classList.remove('active');
                });
                this.classList.add('active');
            }
        });
    });
</script>
JS;

// Footer'ı dahil et
include '../../../../includes/footer.php';
?>
