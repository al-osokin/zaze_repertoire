<?php
require_once 'config.php';
requireAuth();
require_once 'db.php';
require_once 'vk_config.php';
require_once 'app/ApiClient/VKApiClient.php';
require_once 'includes/navigation.php';
handleLogoutRequest();

$accessToken = getSystemSetting('vk_access_token');
$pages = [];
$error = null;

if (!$accessToken) {
    $error = 'VK access token отсутствует. Авторизуйтесь в настройках.';
} else {
    $client = new VKApiClient($accessToken, VK_API_VERSION);
    $vkResponse = $client->getPagesList((int)VK_API_GROUP_ID);

    if ($vkResponse === null) {
        $error = 'Не удалось выполнить запрос к VK API.';
    } elseif (isset($vkResponse['error'])) {
        $vkError = $vkResponse['error'];
        $errorMsg = $vkError['error_msg'] ?? 'Unknown error';
        $errorCode = $vkError['error_code'] ?? '';

        if ((int)$errorCode === 5) {
            deleteSystemSetting('vk_access_token');
            $error = 'VK API error (код 5): ' . $errorMsg . '. Токен сброшен, авторизуйтесь заново.';
        } else {
            if ($errorCode !== '') {
                $errorMsg .= " (код $errorCode)";
            }
            $error = 'VK API error: ' . $errorMsg;
        }
    } else {
        $pages = $vkResponse;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Тест pages.getTitles</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f5f5f5; }
        .container { max-width: 960px; margin: 0 auto; padding: 20px; }
        .error { padding: 12px; background: #ffecec; border: 1px solid #ffb3b3; color: #c00; border-radius: 4px; }
        .success { padding: 12px; background: #e8fff0; border: 1px solid #b6edc8; color: #1a7f3b; border-radius: 4px; }
        .actions { margin-top: 10px; }
        .btn-primary { display: inline-block; background: #007bff; color: white; padding: 8px 14px; border-radius: 4px; text-decoration: none; }
        .btn-primary:hover { background: #0069d9; }
        .meta { color: #555; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="container">
        <?php renderMainNavigation('settings'); ?>
        <div class="header">
            <div>
                <h1>Тест VK API: pages.getTitles</h1>
                <p class="header-subtitle">Группа <?php echo htmlspecialchars(VK_API_GROUP_ID, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <div class="actions">
            <a href="vk_settings.php" class="btn-primary">Настройки VK</a>
            <a href="vk_pages_test.php" class="btn-primary" style="margin-left:8px;">Обновить список</a>
        </div>
        <?php if ($error): ?>
            <div class="error" style="margin-top: 20px;"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php else: ?>
            <div class="success" style="margin-top: 20px;">
                Найдено страниц: <?php echo count($pages); ?>
            </div>
            <?php if (!empty($pages)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Page ID</th>
                            <th>Title</th>
                            <th>Редактируется</th>
                            <th>Создатель</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $index => $page): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($page['page_id'] ?? $page['id'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($page['title'] ?? '(без названия)', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($page['current_user_can_edit']) ? 'Да' : 'Нет'; ?></td>
                                <td><?php echo htmlspecialchars($page['creator_id'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
