<?php
require_once 'config.php';
require_once 'db.php';

function generatePerformanceCardData(int $performanceId): array
{
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT er.event_date, er.event_time, p.site_title, p.full_name
        FROM events_raw er
        LEFT JOIN plays p ON er.play_id = p.id
        WHERE er.id = ?
    ");
    $stmt->execute([$performanceId]);
    $data = $stmt->fetch();

    if (!$data) {
        return ['success' => false, 'message' => 'Представление не найдено'];
    }

    $card = buildPerformanceCard($performanceId, true);

    if (!$card['has_artists']) {
        $playName = formatPlayTitle($data['site_title'] ?? null, $data['full_name'] ?? null);
        return [
            'success' => false,
            'message' => 'Карточка ещё не сгенерирована для этого представления',
            'play_name' => $playName
        ];
    }

    return [
        'success' => true,
        'text' => $card['text'],
        'play_name' => formatPlayTitle($data['site_title'] ?? null, $data['full_name'] ?? null),
        'event_date' => $data['event_date'],
        'event_time' => $data['event_time']
    ];
}

// If the script is called directly, process the request and output JSON
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    requireAuth();
    header('Content-Type: application/json');

    if (!isset($_GET['performance_id'])) {
        echo json_encode(['success' => false, 'message' => 'Не указан performance_id']);
        exit;
    }

    $performanceId = (int)$_GET['performance_id'];
    $result = generatePerformanceCardData($performanceId);
    echo json_encode($result);
}
