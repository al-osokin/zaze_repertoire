<?php
header('Content-Type: application/json');

require_once 'config.php';
requireAuth();
require_once 'db.php';
require_once 'vk_config.php';
require_once 'app/ApiClient/VKApiClient.php';
require_once 'get_performance_card.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'message' => 'Метод не поддерживается'], 405);
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    respondJson(['success' => false, 'message' => 'Пустой запрос'], 400);
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    respondJson(['success' => false, 'message' => 'Некорректный JSON: ' . json_last_error_msg()], 400);
}

if (!is_array($input) || !isset($input['performance_id']) || !is_numeric($input['performance_id'])) {
    respondJson(['success' => false, 'message' => 'Некорректный идентификатор представления'], 400);
}

$performanceId = (int)$input['performance_id'];

try {
    $pageTitle = getVkPageNameByPerformanceId($performanceId);
    if (!$pageTitle) {
        throw new RuntimeException('Для этого представления ещё не сгенерирована страница. Создайте месячную афишу.');
    }

    $cardData = generatePerformanceCardData($performanceId);
    if (!$cardData['success'] || empty($cardData['text'])) {
        throw new RuntimeException($cardData['message'] ?? 'Карточка ещё не готова.');
    }
    $cardText = $cardData['text'];

    $accessToken = getSystemSetting('vk_access_token');
    if (!$accessToken) {
        throw new RuntimeException('VK access token не найден. Пожалуйста, авторизуйтесь в настройках.');
    }

    $vkClient = new VKApiClient($accessToken, VK_API_VERSION);
    $result = $vkClient->savePage($pageTitle, $cardText, (int)VK_API_GROUP_ID);

    if ($result === null) {
        throw new RuntimeException('Не удалось выполнить запрос к VK API. Попробуйте ещё раз.');
    }

    if (is_array($result) && isset($result['error'])) {
        $vkError = $result['error'];
        $errorCodeValue = $vkError['error_code'] ?? null;
        $errorMessage = $vkError['error_msg'] ?? 'Unknown error';
        if ($errorCodeValue === 5) {
            deleteSystemSetting('vk_access_token');
            throw new RuntimeException('VK API error (5): ' . $errorMessage . '. Токен сброшен, авторизуйтесь заново.');
        }

        $errorCode = $errorCodeValue ? ' (' . $errorCodeValue . ')' : '';
        throw new RuntimeException('VK API error' . $errorCode . ': ' . $errorMessage);
    }

    $pageId = null;
    if (is_array($result)) {
        $pageId = $result['page_id'] ?? null;
    } elseif (is_numeric($result)) {
        $pageId = (int)$result;
    }

    if (!$pageId) {
        throw new RuntimeException('VK API вернул неожиданный ответ без page_id.');
    }

    updateVkPostText($performanceId, $cardText);

    respondJson([
        'success' => true,
        'performance_id' => $performanceId,
        'page_id' => $pageId,
        'page_title' => $pageTitle,
        'play_name' => $cardData['play_name'] ?? null,
        'event_date' => $cardData['event_date'] ?? null,
        'event_time' => $cardData['event_time'] ?? null,
    ]);
} catch (RuntimeException $e) {
    respondJson(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respondJson(['success' => false, 'message' => 'Внутренняя ошибка сервиса VK публикации'], 500);
}

function respondJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
