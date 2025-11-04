<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../app/Models/PlayTemplateParser.php';

use App\Models\PlayTemplateParser;

$pdo = getDBConnection();
$parser = new PlayTemplateParser($pdo);

echo "Начинаем обновление всех шаблонов...\n";

$playsStmt = $pdo->query("SELECT id, full_name FROM plays");
$plays = $playsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plays as $play) {
    $playId = $play['id'];
    $playName = htmlspecialchars($play['full_name']);
    echo "Обрабатываем шаблон для спектакля '{$playName}' (ID: {$playId})...\n";

    $templateStmt = $pdo->prepare("SELECT template_text FROM play_templates WHERE play_id = ?");
    $templateStmt->execute([$playId]);
    $templateText = $templateStmt->fetchColumn();

    if (!$templateText) {
        echo "  - ВНИМАНИЕ: Шаблон пуст или отсутствует в play_templates. Пропускаем.\n";
        continue;
    }

    // Просто парсим существующий шаблон, чтобы обновить template_elements
    try {
        $parser->parseTemplate($playId, $templateText);
        echo "  - Шаблон успешно распарсен и template_elements обновлены.\n";
    } catch (Exception $e) {
        echo "  - ОШИБКА при парсинге шаблона: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "Обновление всех шаблонов завершено.\n";
?>
