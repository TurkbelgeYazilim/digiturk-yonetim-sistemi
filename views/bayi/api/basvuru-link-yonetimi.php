<?php
$pageTitle = "Başvuru Link Yönetimi";
$breadcrumbs = [
    ['title' => 'Başvuru Link Yönetimi']
];

// Auth kontrol
require_once '../../../auth.php';
$currentUser = checkAuth();
checkUserStatus();
updateLastActivity();

$currentPageUrl = basename($_SERVER['PHP_SELF']);

$sayfaYetkileri = [
    'gor' => 0,
    'kendi_kullanicini_gor' => 0,
    'ekle' => 0,
    'duzenle' => 0,
    'sil' => 0
];

$isAdmin = isset($currentUser['group_id']) && (int)$currentUser['group_id'] === 1;

if ($isAdmin) {
    $sayfaYetkileri = [
        'gor' => 1,
        'kendi_kullanicini_gor' => 0,
        'ekle' => 1,
        'duzenle' => 1,
        'sil' => 1
    ];
} else {
    try {
        $conn = getDatabaseConnection();
        $yetkiSql = "
            SELECT 
                tsy.gor,
                tsy.kendi_kullanicini_gor,
                tsy.ekle,
                tsy.duzenle,
                tsy.sil
            FROM dbo.tanim_sayfalar ts
            INNER JOIN dbo.tanim_sayfa_yetkiler tsy ON ts.sayfa_id = tsy.sayfa_id
            WHERE ts.sayfa_url = ?
            AND tsy.user_group_id = ?
            AND ts.durum = 1
            AND tsy.durum = 1
        ";
        $yetkiStmt = $conn->prepare($yetkiSql);
        $yetkiStmt->execute([$currentPageUrl, $currentUser['group_id']]);
        $yetkiResult = $yetkiStmt->fetch(PDO::FETCH_ASSOC);

        if ($yetkiResult) {
            $sayfaYetkileri = [
                'gor' => (int)$yetkiResult['gor'],
                'kendi_kullanicini_gor' => (int)$yetkiResult['kendi_kullanicini_gor'],
                'ekle' => (int)$yetkiResult['ekle'],
                'duzenle' => (int)$yetkiResult['duzenle'],
                'sil' => (int)$yetkiResult['sil']
            ];
        }
    } catch (Exception $e) {
        $sayfaYetkileri = [
            'gor' => 0,
            'kendi_kullanicini_gor' => 1,
            'ekle' => 0,
            'duzenle' => 0,
            'sil' => 0
        ];
    }
}

if (!$sayfaYetkileri['gor']) {
    header('Location: /index.php?error=yetki_yok');
    exit;
}

$message = '';
$messageType = '';
$loginOptions = [];
$loginError = '';
$selectedLoginId = null;
$selectedLoginCd = '';
$basvuruLinkBaseUrl = 'https://digiturk.ilekasoft.com/views/Bayi/api/basvuru.php?api_ID=';

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDatabaseConnection();
        
        // İşlemler buraya gelecek
        
        $message = 'İşlem başarılı.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Hata: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

$campaignList = [];
$campaignError = '';

try {
    $conn = getDatabaseConnection();
    $sql = "SELECT api_iris_kullanici_ID, api_iris_kullanici_LoginCd, users_ID FROM API_kullanici";
    $conditions = [];
    $params = [];

    if (!$isAdmin && !empty($sayfaYetkileri['kendi_kullanicini_gor']) && (int)$sayfaYetkileri['kendi_kullanicini_gor'] === 1) {
        $conditions[] = "users_ID = ?";
        $params[] = $currentUser['id'];
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY api_iris_kullanici_LoginCd';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $uniqueLogins = [];
    foreach ($records as $record) {
        $loginCd = $record['api_iris_kullanici_LoginCd'];
        if ($loginCd === null) {
            continue;
        }

        $loginCd = trim((string)$loginCd);
        if ($loginCd === '') {
            continue;
        }

        if (!array_key_exists($loginCd, $uniqueLogins)) {
            $uniqueLogins[$loginCd] = (int)$record['api_iris_kullanici_ID'];
        }
    }

    foreach ($uniqueLogins as $loginCd => $loginId) {
        $loginOptions[] = [
            'loginCd' => $loginCd,
            'id' => $loginId
        ];
    }

    if (count($loginOptions) === 1) {
        $selectedLoginCd = $loginOptions[0]['loginCd'];
        $selectedLoginId = $loginOptions[0]['id'];
    }
    
    // Kampanya listesini çek
    $campaignSql = "SELECT API_CampaignList_ID, API_CampaignList_CampaignName 
                    FROM API_CampaignList 
                    WHERE API_CampaignList_Durum = 1 
                    ORDER BY API_CampaignList_CampaignName";
    $campaignStmt = $conn->prepare($campaignSql);
    $campaignStmt->execute();
    $campaignList = $campaignStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Paket listesini çek (Uydu kampanyası için)
    $packageSql = "SELECT 
                        API_GetSatelliteCampaignList_ID,
                        API_GetSatelliteCampaignList_PaketAdi,
                        API_GetSatelliteCampaignList_Fiyat,
                        API_GetSatelliteCampaignList_odeme_turu_id
                    FROM API_GetSatelliteCampaignList 
                    WHERE API_GetSatelliteCampaignList_durum = 1 
                    ORDER BY API_GetSatelliteCampaignList_PaketAdi";
    $packageStmt = $conn->prepare($packageSql);
    $packageStmt->execute();
    $packageList = $packageStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Neo paket listesini çek (Neo kampanyası için)
    $neoPackageSql = "SELECT 
                        API_GetNeoCampaignList_ID,
                        API_GetNeoCampaignList_PaketAdi,
                        API_GetNeoCampaignList_Fiyat,
                        API_GetNeoCampaignList_odeme_turu_id
                    FROM API_GetNeoCampaignList 
                    WHERE API_GetNeoCampaignList_durum = 1 
                    ORDER BY API_GetNeoCampaignList_PaketAdi";
    $neoPackageStmt = $conn->prepare($neoPackageSql);
    $neoPackageStmt->execute();
    $neoPackageList = $neoPackageStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $loginError = 'Login kodları alınamadı: ' . $e->getMessage();
    $campaignError = 'Kampanya listesi alınamadı: ' . $e->getMessage();
}

$initialLoginIdJs = $selectedLoginId !== null ? (int)$selectedLoginId : 'null';
$basvuruLinkBaseJs = json_encode($basvuruLinkBaseUrl, JSON_UNESCAPED_SLASHES);
$campaignListJs = json_encode($campaignList, JSON_UNESCAPED_UNICODE);
$packageListJs = json_encode($packageList, JSON_UNESCAPED_UNICODE);
$neoPackageListJs = json_encode($neoPackageList, JSON_UNESCAPED_UNICODE);

$additionalCSS = <<<CSS
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<style>
    #basvuruLinkTable thead th {
        vertical-align: middle;
        padding: 12px 8px;
    }

    #basvuruLinkTable tbody td {
        vertical-align: middle;
    }

    .link-cell-wrapper {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
    }

    .link-cell-wrapper .link-text {
        flex: 1 1 250px;
        word-break: break-all;
        display: block;
    }

    .link-cell-wrapper .link-anchor {
        color: var(--bs-link-color, #0d6efd);
        text-decoration: none;
        word-break: break-all;
        display: inline-block;
    }

    .link-cell-wrapper .link-anchor:hover {
        text-decoration: underline;
    }
</style>
CSS;

$additionalJS = <<<JS
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    const basvuruBaseUrl = {$basvuruLinkBaseJs};
    const initialLoginId = {$initialLoginIdJs};
    const campaignList = {$campaignListJs};
    const packageList = {$packageListJs};
    const neoPackageList = {$neoPackageListJs};
    const loginSelect = $('#login-select');
    
    console.log('Base URL:', basvuruBaseUrl);
    console.log('Initial Login ID:', initialLoginId);
    console.log('Campaign List:', campaignList);
    console.log('Package List:', packageList);
    console.log('Neo Package List:', neoPackageList);

    window.basvuruTable = $('#basvuruLinkTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [],
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50], [5, 10, 25, 50]],
        dom: 'rtip'
    });

    const buildLinkRow = function(description, loginId, campaignId, paketId, aciklama) {
        let link = basvuruBaseUrl + loginId;
        if (campaignId) {
            link += '&kampanya=' + campaignId;
        }
        if (paketId) {
            link += '&paket=' + paketId;
        }
        console.log('Building link - Description:', description, 'Login ID:', loginId, 'Campaign ID:', campaignId, 'Paket ID:', paketId, 'Result:', link);
        
        const linkHtml = '<a href="' + link + '" target="_blank" class="text-primary">' + link + '</a>';
        const buttonHtml = '<button type="button" class="btn btn-outline-secondary btn-sm copy-link" data-link="' + link + '"><i class="fas fa-copy me-1"></i>Kopyala</button>';
        
        // iframe kodu oluştur
        const iframeCode = '<iframe src="' + link + '" width="100%" height="800px" frameborder="0" scrolling="yes"></iframe>';
        const iframeButtonHtml = '<button type="button" class="btn btn-outline-primary btn-sm copy-iframe" data-iframe="' + iframeCode.replace(/"/g, '&quot;') + '"><i class="fas fa-code me-1"></i>iframe Kopyala</button>';
        
        const html = linkHtml + ' ' + buttonHtml;
        const aciklamaText = aciklama || '-';

        return [description, html, iframeButtonHtml, aciklamaText];
    };

    const getSelectedLoginData = function() {
        const option = loginSelect.find('option:selected');
        const loginId = option.data('userId');
        const loginCd = option.val();
        
        console.log('Selected option:', option);
        console.log('Login ID from data-user-id:', loginId);
        console.log('Login Code:', loginCd);

        if (!loginId) {
            return null;
        }

        return {
            id: loginId,
            loginCd: loginCd
        };
    };

    let lastSelectedCampaign = '';
    
    window.applyLinkFilters = function(triggerDraw = true) {
        const table = window.basvuruTable;
        const kampanyaFilter = $('#filterKampanya').val() || '';
        const descriptionFilter = $('#filterDescription').val() || '';
        const linkFilter = $('#filterLink').val() || '';
        const aciklamaFilter = $('#filterAciklama').val() || '';

        // Önceki kampanya filtresini temizle
        $.fn.dataTable.ext.search = [];

        // Kampanya filtresini özel olarak işle
        if (kampanyaFilter) {
            lastSelectedCampaign = kampanyaFilter;
            let showPackages = false;
            
            $.fn.dataTable.ext.search.push(
                function(settings, data, dataIndex) {
                    const tanımlama = data[0] || '';
                    
                    // Eğer "Genel Başvuru" seçildiyse
                    if (kampanyaFilter === 'Genel Başvuru') {
                        return tanımlama === 'Genel Başvuru';
                    }
                    
                    // Kampanya adıyla eşleşiyorsa
                    if (tanımlama === kampanyaFilter) {
                        showPackages = true;
                        return true;
                    }
                    
                    // Alt paket kontrolü - └─ ile başlıyorsa ve showPackages true ise
                    if (tanımlama.indexOf('└─') === 0 || tanımlama.indexOf('  └─') === 0) {
                        if (showPackages) {
                            // Başka bir kampanyaya gelene kadar paketleri göster
                            if (tanımlama.indexOf('└─') === 0 || tanımlama.indexOf('  └─') === 0) {
                                return true;
                            }
                        }
                    } else {
                        // Kampanya olmayan bir satıra geldiysek paket göstermeyi durdur
                        if (tanımlama !== kampanyaFilter) {
                            showPackages = false;
                        }
                    }
                    
                    return false;
                }
            );
        }

        table.column(0).search(descriptionFilter);
        table.column(1).search(linkFilter);
        table.column(3).search(aciklamaFilter);

        if (triggerDraw) {
            table.draw();
        }
    };

    window.clearLinkFilters = function() {
        $('#filterKampanya').val('');
        $('#filterDescription').val('');
        $('#filterLink').val('');
        $('#filterAciklama').val('');

        const table = window.basvuruTable;
        // Tüm özel filtreleri temizle
        $.fn.dataTable.ext.search = [];
        table.search('').columns().search('');
        table.draw();
    };

    window.refreshBasvuruTable = function() {
        const table = window.basvuruTable;
        table.clear();

        const selected = getSelectedLoginData();
        if (selected) {
            // Genel başvuru linki ekle
            table.row.add(buildLinkRow('Genel Başvuru', selected.id, null, null, 'Tüm kampanyaları ve paketleri fiyatlarıyla birlikte listeler, Kutusuz TV Paketi seçililirse adres bölümünü atlar'));
            
            // Kampanya linkleri ekle
            if (campaignList && campaignList.length > 0) {
                campaignList.forEach(function(campaign) {
                    const campaignName = campaign.API_CampaignList_CampaignName || 'Kampanya';
                    const campaignId = campaign.API_CampaignList_ID;
                    table.row.add(buildLinkRow(campaignName, selected.id, campaignId, null, campaignName + ' kampanyası için özel başvuru formu'));
                    
                    // Eğer kampanya ID = 1 ise, uydu paket listesini bu kampanyanın altına ekle
                    if (campaignId == 1 && packageList && packageList.length > 0) {
                        packageList.forEach(function(paket) {
                            const paketAdi = paket.API_GetSatelliteCampaignList_PaketAdi || 'Paket';
                            const fiyat = paket.API_GetSatelliteCampaignList_Fiyat || '0';
                            const odemeTuru = paket.API_GetSatelliteCampaignList_odeme_turu_id;
                            const odemeTuruText = odemeTuru == 1 ? 'Kredi Kartlı' : (odemeTuru == 2 ? 'Faturalı' : 'Bilinmiyor');
                            const paketId = paket.API_GetSatelliteCampaignList_ID;
                            
                            const paketTanim = '  └─ ' + paketAdi + ' / ' + fiyat + ' TL / ' + odemeTuruText;
                            const paketAciklama = paketAdi + ' paketi - ' + fiyat + ' TL - ' + odemeTuruText + ' - Doğrudan paket seçili başvuru formu';
                            
                            table.row.add(buildLinkRow(paketTanim, selected.id, campaignId, paketId, paketAciklama));
                        });
                    }
                    
                    // Eğer kampanya ID = 2 ise, neo paket listesini bu kampanyanın altına ekle
                    if (campaignId == 2 && neoPackageList && neoPackageList.length > 0) {
                        neoPackageList.forEach(function(paket) {
                            const paketAdi = paket.API_GetNeoCampaignList_PaketAdi || 'Paket';
                            const fiyat = paket.API_GetNeoCampaignList_Fiyat || '0';
                            const odemeTuru = paket.API_GetNeoCampaignList_odeme_turu_id;
                            const odemeTuruText = odemeTuru == 1 ? 'Kredi Kartlı' : (odemeTuru == 2 ? 'Faturalı' : 'Bilinmiyor');
                            const paketId = paket.API_GetNeoCampaignList_ID;
                            
                            const paketTanim = '  └─ ' + paketAdi + ' / ' + fiyat + ' TL / ' + odemeTuruText;
                            const paketAciklama = paketAdi + ' paketi - ' + fiyat + ' TL - ' + odemeTuruText + ' - Doğrudan paket seçili başvuru formu';
                            
                            table.row.add(buildLinkRow(paketTanim, selected.id, campaignId, paketId, paketAciklama));
                        });
                    }
                });
            }
        }

        window.applyLinkFilters(false);
        table.draw();
    };

    loginSelect.on('change', function() {
        window.refreshBasvuruTable();
    });

    $('#filterKampanya').on('change', function() {
        window.applyLinkFilters();
    });

    $('#filterDescription, #filterLink, #filterAciklama').on('keypress', function(e) {
        if (e.which === 13 || e.key === 'Enter') {
            window.applyLinkFilters();
        }
    });

    // Link kopyalama
    $('#basvuruLinkTable tbody').on('click', '.copy-link', function() {
        const button = $(this);
        const link = button.data('link');

        if (!link) {
            return;
        }

        const showFeedback = function() {
            const originalHtml = button.html();
            button.removeClass('btn-outline-secondary').addClass('btn-success');
            button.html('<i class="fas fa-check me-1"></i>Kopyalandı');
            setTimeout(function() {
                button.removeClass('btn-success').addClass('btn-outline-secondary');
                button.html(originalHtml);
            }, 2000);
        };

        const fallbackCopy = function(text) {
            const tempInput = $('<input type="text" class="visually-hidden">');
            $('body').append(tempInput);
            tempInput.val(text).select();
            document.execCommand('copy');
            tempInput.remove();
            showFeedback();
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(showFeedback).catch(function() {
                fallbackCopy(link);
            });
        } else {
            fallbackCopy(link);
        }
    });

    // iframe kodu kopyalama
    $('#basvuruLinkTable tbody').on('click', '.copy-iframe', function() {
        const button = $(this);
        const iframeCode = button.data('iframe');

        if (!iframeCode) {
            return;
        }

        const showFeedback = function() {
            const originalHtml = button.html();
            button.removeClass('btn-outline-primary').addClass('btn-success');
            button.html('<i class="fas fa-check me-1"></i>Kopyalandı');
            setTimeout(function() {
                button.removeClass('btn-success').addClass('btn-outline-primary');
                button.html(originalHtml);
            }, 2000);
        };

        const fallbackCopy = function(text) {
            const tempInput = $('<input type="text" class="visually-hidden">');
            $('body').append(tempInput);
            tempInput.val(text).select();
            document.execCommand('copy');
            tempInput.remove();
            showFeedback();
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(iframeCode).then(showFeedback).catch(function() {
                fallbackCopy(iframeCode);
            });
        } else {
            fallbackCopy(iframeCode);
        }
    });

    if (initialLoginId !== null) {
        const matchingOption = loginSelect.find('option[data-user-id="' + initialLoginId + '"]');
        if (matchingOption.length) {
            loginSelect.val(matchingOption.val());
        }
        window.refreshBasvuruTable();
    } else {
        window.basvuruTable.draw();
    }
});
</script>
JS;

include '../../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-tasks me-2"></i>Başvuru Link Yönetimi</h2>
            </div>
        </div>
    </div>

    <?php if ($loginError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($loginError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($campaignError): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($campaignError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($loginOptions) && !$loginError): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i>Hesabınıza tanımlı herhangi bir login kodu bulunamadı. Yetkili yöneticiniz ile iletişime geçiniz.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-12 col-md-6 col-lg-4">
            <label for="login-select" class="form-label">Login Kodu</label>
            <select id="login-select" class="form-select" <?php echo empty($loginOptions) ? 'disabled' : ''; ?>>
                <option value="" <?php echo $selectedLoginId !== null ? '' : 'selected'; ?>><?php echo empty($loginOptions) ? 'Kayıt bulunamadı' : 'Seçiniz'; ?></option>
                <?php foreach ($loginOptions as $option): ?>
                    <option value="<?php echo htmlspecialchars($option['loginCd']); ?>" data-user-id="<?php echo (int)$option['id']; ?>" <?php echo $selectedLoginCd === $option['loginCd'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($option['loginCd']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtreleme</h5>
        </div>
        <div class="card-body">
            <div class="row g-3" id="filterSection">
                <div class="col-md-3">
                    <label class="form-label">Kampanya</label>
                    <select id="filterKampanya" class="form-select">
                        <option value="">Tümü</option>
                        <option value="Genel Başvuru">Genel Başvuru</option>
                        <?php foreach ($campaignList as $campaign): ?>
                            <option value="<?php echo htmlspecialchars($campaign['API_CampaignList_CampaignName']); ?>">
                                <?php echo htmlspecialchars($campaign['API_CampaignList_CampaignName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanımlama</label>
                    <input type="text" id="filterDescription" class="form-control" placeholder="Tanımlama ara...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Link</label>
                    <input type="text" id="filterLink" class="form-control" placeholder="Link ara...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Açıklama</label>
                    <input type="text" id="filterAciklama" class="form-control" placeholder="Açıklama ara...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary flex-grow-1" onclick="applyLinkFilters()">
                            <i class="fas fa-search me-1"></i>Filtrele
                        </button>
                        <button type="button" class="btn btn-secondary flex-grow-1" onclick="clearLinkFilters()">
                            <i class="fas fa-times me-1"></i>Temizle
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-link me-2"></i>Başvuru Linkleri</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="basvuruLinkTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Tanımlama</th>
                            <th>Link</th>
                            <th>iframe Kodu</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>