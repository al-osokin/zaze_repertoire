<?php
require_once 'config.php';
require_once 'db.php';
require_once 'vk_config.php';
requireAuth();

$accessToken = getSystemSetting('vk_access_token');

if (!isset($_SESSION['vk_oauth_requests']) || !is_array($_SESSION['vk_oauth_requests'])) {
    $_SESSION['vk_oauth_requests'] = [];
}

// Очистка устаревших запросов (старше 15 минут)
$now = time();
foreach ($_SESSION['vk_oauth_requests'] as $stateKey => $requestData) {
    $created = (int)($requestData['created_at'] ?? 0);
    if ($created === 0 || ($now - $created) > 900) {
        unset($_SESSION['vk_oauth_requests'][$stateKey]);
    }
}

$flashError = $_SESSION['vk_oauth_error'] ?? null;
$flashSuccess = $_SESSION['vk_oauth_success'] ?? null;
unset($_SESSION['vk_oauth_error'], $_SESSION['vk_oauth_success']);

$state = bin2hex(random_bytes(16));
$useVkIdFlow = strtolower(VK_AUTH_FLOW) === 'vkid';

if ($useVkIdFlow) {
    $codeVerifier = bin2hex(random_bytes(32));
    $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    $_SESSION['vk_oauth_requests'][$state] = [
        'code_verifier' => $codeVerifier,
        'created_at' => $now,
        'flow' => 'vkid',
    ];

    $requestedScopes = ['vkid.personal_info', 'pages', 'offline'];
    $authUrlParams = [
        'client_id' => VK_APP_ID,
        'redirect_uri' => VK_REDIRECT_URI,
        'display' => 'page',
        'scope' => implode(' ', $requestedScopes),
        'response_type' => 'code',
        'v' => VK_API_VERSION,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'state' => $state,
    ];
    $authorizationUrl = 'https://id.vk.ru/authorize?' . http_build_query($authUrlParams);
} else {
    $_SESSION['vk_oauth_requests'][$state] = [
        'created_at' => $now,
        'flow' => 'classic',
    ];

    $requestedScopes = ['pages', 'offline'];
    $authUrlParams = [
        'client_id' => VK_APP_ID,
        'redirect_uri' => VK_REDIRECT_URI,
        'display' => 'page',
        'scope' => implode(',', $requestedScopes),
        'response_type' => 'code',
        'state' => $state,
        'revoke' => 1,
    ];
    $authorizationUrl = 'https://oauth.vk.com/authorize?' . http_build_query($authUrlParams);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Настройки интеграции с VK</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 15px; }
        .alert-error { background: #ffecec; border: 1px solid #ffb6b6; color: #a40000; }
        .alert-success { background: #e8fff0; border: 1px solid #b6edc8; color: #146c2e; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Настройки интеграции с ВКонтакте</h1>

        <?php if ($flashError): ?>
            <div class="alert alert-error">
                <?php echo nl2br(htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8')); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success">
                <?php echo nl2br(htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8')); ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <h2>Статус авторизации</h2>
            <?php if ($accessToken): ?>
                <p style="color: green;">Приложение авторизовано. Токен доступа получен.</p>
                <p><small>Сохраненный токен: <?php echo htmlspecialchars(substr($accessToken, 0, 8) . '...'); ?></small></p>
                <p>Если у вас возникли проблемы, вы можете повторно авторизовать приложение.</p>
            <?php else: ?>
                <p style="color: red;">Приложение не авторизовано.</p>
                <p>Для начала работы необходимо получить токен доступа от VK.</p>
            <?php endif; ?>
            <p style="margin-top: 10px; font-size: 0.95rem; color: #555;">
                Redirect URI из настроек: <code><?php echo htmlspecialchars(VK_REDIRECT_URI, ENT_QUOTES, 'UTF-8'); ?></code>
            </p>
        </div>

        <div class="section">
            <h2>Действия</h2>
            <p>Нажмите на кнопку ниже, чтобы перейти на сайт VK и разрешить приложению доступ к вашему аккаунту для управления вики-страницами.</p>
            <p style="font-size:0.9rem;color:#555;">
                Запрашиваемые права: <?php echo htmlspecialchars(implode(', ', $requestedScopes), ENT_QUOTES, 'UTF-8'); ?><br/>
                Режим авторизации: <?php echo $useVkIdFlow ? 'VK ID (PKCE)' : 'Классический OAuth (для доступа pages)'; ?>
            </p>
            <a href="<?php echo htmlspecialchars($authorizationUrl); ?>" class="btn-primary">
                <?php echo $accessToken ? 'Авторизоваться повторно' : 'Авторизоваться через VK'; ?>
            </a>
            <p style="margin-top: 15px;">
                <a href="vk_pages_test.php" class="btn-secondary">Посмотреть список вики-страниц (pages.getTitles)</a>
            </p>
        </div>

        <div class="section">
            <a href="index.php">Вернуться на главную</a>
        </div>
    </div>
</body>
</html>
