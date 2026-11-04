
<?php
require_once __DIR__ . '/includes/session.php';
$tab_id = startAppSession($_GET['context'] ?? $_GET['tab_id'] ?? null);
$allowedTabs = ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'];
$returnTab = in_array($_GET['tab'] ?? '', $allowedTabs, true) ? $_GET['tab'] : 'dashboard';

$applicationId = getenv('POSTER_APPLICATION_ID');
$appUrl = rtrim((string)getenv('APP_URL'), '/');
$appEnvironment = strtolower((string)(getenv('APP_ENV') ?: 'production'));
$parsedAppUrl = $appUrl !== '' ? parse_url($appUrl) : false;
$appHost = is_array($parsedAppUrl) ? strtolower((string)($parsedAppUrl['host'] ?? '')) : '';
$validAppUrl = is_array($parsedAppUrl)
    && filter_var($appUrl, FILTER_VALIDATE_URL) !== false
    && $appHost !== ''
    && in_array($parsedAppUrl['scheme'] ?? '', ['https', 'http'], true)
    && ($appEnvironment !== 'production' || ($parsedAppUrl['scheme'] ?? '') === 'https')
    && empty($parsedAppUrl['user'])
    && empty($parsedAppUrl['pass'])
    && empty($parsedAppUrl['query'])
    && empty($parsedAppUrl['fragment'])
    && !in_array($appHost, ['localhost', 'yourdomain.com'], true)
    && !preg_match('/\.(?:example|invalid|test)$/i', $appHost);
$loginConfigurationError = '';
if (!$validAppUrl) {
    http_response_code(500);
    $loginConfigurationError = 'APP_URL ni Poster Developer sozlamalaridagi haqiqiy HTTPS domeniga o‘rnating.';
} elseif (!is_string($applicationId) || $applicationId === '') {
    http_response_code(500);
    $loginConfigurationError = 'POSTER_APPLICATION_ID sozlamasi mavjud emas.';
}
if (empty($_SESSION['oauth_state']) || !is_string($_SESSION['oauth_state'])) {
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
}
$redirectUri = $validAppUrl
    ? $appUrl . '/index.php?' . http_build_query(array_merge(
        ['context' => $tab_id, 'state' => $_SESSION['oauth_state']],
        isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true) ? ['tab' => $_GET['tab']] : []
    ))
    : '';
$oauthUrl = $loginConfigurationError === ''
    ? 'https://joinposter.com/oauth?' . http_build_query([
        'application_id' => $applicationId,
        'redirect_uri' => $redirectUri,
    ])
    : '';

// Agar session mavjud bo'lsa, app.php ga o'tish
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: /' . $returnTab . '/' . rawurlencode($tab_id));
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Poster App</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 50px auto; padding: 30px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        .btn { display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; }
        .btn:hover { background: #45a049; }
        .info { text-align: center; margin: 20px 0; color: #666; }
        .tab-id { font-size: 12px; color: #999; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Poster App</h1>
        <div class="info">
            <p>Poster akkauntingizga kiring</p>
            <p style="font-size: 14px; color: #888;">Tab ID: <?php echo htmlspecialchars($tab_id); ?></p>
        </div>
        <div style="text-align: center;">
            <?php if ($loginConfigurationError !== ''): ?>
                <p role="alert" style="color:#b91c1c;"><?= htmlspecialchars($loginConfigurationError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
                <a href="<?= htmlspecialchars($oauthUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn">
                    Poster orqali kirish
                </a>
            <?php endif; ?>
        </div>
        <div class="tab-id">
            <small>Bu tab alohida himoyalangan sessiyada ishlaydi</small>
        </div>
    </div>
</body>
<script>
    if (window.top !== window.self) {
        window.top.postMessage({ hideSpinner: true }, '*');
    }
</script>

</html>