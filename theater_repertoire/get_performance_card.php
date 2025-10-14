<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

header('Content-Type: application/json');

if (!isset($_GET['performance_id'])) {
    echo json_encode(['success' => false, 'message' => 'Не указан performance_id']);
    exit;
}

$performanceId = (int)$_GET['performance_id'];
$pdo = getDBConnection();

$stmt = $pdo->prepare("
    SELECT er.vk_post_text, er.event_date, er.event_time, p.full_name
    FROM events_raw er
    LEFT JOIN plays p ON er.play_id = p.id
    WHERE er.id = ?
");
$stmt->execute([$performanceId]);
$data = $stmt->fetch();

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Представление не найдено']);
    exit;
}

if (empty(trim($data['vk_post_text'] ?? ''))) {
    echo json_encode([
        'success' => false,
        'message' => 'Карточка ещё не сгенерирована для этого представления',
        'play_name' => $data['full_name'] ?? ''
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'text' => $data['vk_post_text'],
    'play_name' => $data['full_name'] ?? '',
    'event_date' => $data['event_date'],
    'event_time' => $data['event_time']
]);
