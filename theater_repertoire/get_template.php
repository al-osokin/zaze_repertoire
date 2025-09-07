<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

header('Content-Type: application/json');

if (!isset($_GET['short_name'])) {
    echo json_encode(['success' => false, 'message' => 'Не указан short_name']);
    exit;
}

$shortName = trim($_GET['short_name']);

// Получаем спектакль по short_name
$play = getPlayByShortName($shortName);

if (!$play) {
    echo json_encode(['success' => false, 'message' => 'Спектакль не найден: ' . $shortName]);
    exit;
}

// Получаем шаблон для спектакля
$template = getTemplateByPlayId($play['id']);

if (!$template) {
    // Возвращаем признак, что шаблон не найден, но спектакль существует
    echo json_encode(['success' => false, 'message' => 'Шаблон не найден', 'play_name' => $play['full_name']]);
    exit;
}

// Возвращаем шаблон
echo json_encode([
    'success' => true,
    'template' => $template['template_text'],
    'play_name' => $play['full_name']
]);
?>
