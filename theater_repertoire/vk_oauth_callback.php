<?php
require_once 'config.php';
require_once 'db.php';
require_once 'vk_config.php';

function logVkOAuthEvent(string $message): void
{
    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $record = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    file_put_contents($logDir . '/vk_oauth.log', $record, FILE_APPEND);
}

function redirectWithError(string $message): void
{
    $_SESSION['vk_oauth_error'] = $message;
    logVkOAuthEvent('ERROR: ' . $message);
    header('Location: vk_settings.php?error=1');
    exit;
}

function redirectWithSuccess(string $message): void
{
    $_SESSION['vk_oauth_success'] = $message;
    logVkOAuthEvent('SUCCESS: ' . $message);
    header('Location: vk_settings.php?success=1');
    exit;
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$deviceId = $_GET['device_id'] ?? null;

if (!$code) {
    redirectWithError('Не удалось получить параметр code от VK.');
}

if (!$state) {
    redirectWithError('Параметр state отсутствует в ответе VK.');
}

$authRequests = $_SESSION['vk_oauth_requests'] ?? [];
if (!is_array($authRequests) || !isset($authRequests[$state])) {
    redirectWithError('Запрос авторизации не найден или устарел. Попробуйте ещё раз.');
}

$requestMeta = $authRequests[$state];
unset($_SESSION['vk_oauth_requests'][$state]);
$flow = $requestMeta['flow'] ?? 'classic';

logVkOAuthEvent(sprintf('Received code for state %s (flow: %s)', $state, $flow));

if ($flow === 'vkid') {
    if (!$deviceId) {
        redirectWithError('VK ID не вернул device_id. Попробуйте снова.');
    }

    $codeVerifier = $requestMeta['code_verifier'] ?? null;
    if (!$codeVerifier) {
        redirectWithError('Не найден code_verifier для VK ID авторизации.');
    }

    $tokenUrlParams = [
        'client_id' => VK_APP_ID,
        'client_secret' => VK_APP_SECRET,
        'redirect_uri' => VK_REDIRECT_URI,
        'code' => $code,
        'device_id' => $deviceId,
        'code_verifier' => $codeVerifier,
        'grant_type' => 'authorization_code',
    ];

    $tokenUrl = 'https://id.vk.ru/oauth2/auth';
} else {
    $tokenUrlParams = [
        'client_id' => VK_APP_ID,
        'client_secret' => VK_APP_SECRET,
        'redirect_uri' => VK_REDIRECT_URI,
        'code' => $code,
    ];

    $tokenUrl = 'https://oauth.vk.com/access_token';
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenUrlParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

if ($response === false) {
    $error = curl_error($ch) ?: 'Неизвестная ошибка CURL';
    curl_close($ch);
    redirectWithError('Не удалось связаться с VK: ' . $error);
}

curl_close($ch);

$data = json_decode($response, true);
if (!is_array($data)) {
    redirectWithError('Некорректный ответ VK. Попробуйте позже.');
}

if (isset($data['error'])) {
    $errorDescription = $data['error_description'] ?? ($data['error'] ?? 'Unknown error');
    redirectWithError('VK вернул ошибку: ' . $errorDescription);
}

$accessToken = $data['access_token'] ?? null;
if (!$accessToken) {
    redirectWithError('VK не вернул access_token. Попробуйте повторить авторизацию.');
}

saveSystemSetting('vk_access_token', $accessToken);
redirectWithSuccess('Токен успешно обновлён.');
