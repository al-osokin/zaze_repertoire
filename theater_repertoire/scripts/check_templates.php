<?php
// check_templates.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Models/PlayTemplateParser.php';
require_once __DIR__ . '/../db.php'; // Для функций getPlayById, getTemplateElementsForPlay

use App\Models\PlayTemplateParser;

$pdo = getDBConnection();
$parser = new PlayTemplateParser($pdo);

echo "Запускаем проверку шаблонов спектаклей...\n";

$stmt = $pdo->query("SELECT id, short_name FROM plays");
$plays = $stmt->fetchAll();

$errorsFound = false;

foreach ($plays as $play) {
    $playId = $play['id'];
    $shortName = $play['short_name'];

    echo "Проверяем шаблон для спектакля '{$shortName}' (ID: {$playId})...\n";

    // Получаем текст шаблона
    $templateStmt = $pdo->prepare("SELECT template_text FROM play_templates WHERE play_id = ?");
    $templateStmt->execute([$playId]);
    $templateText = $templateStmt->fetchColumn();

    if (empty($templateText)) {
        echo "  - ВНИМАНИЕ: Шаблон пуст или отсутствует в play_templates.\n";
        $errorsFound = true;
        continue;
    }

    // Попытка парсинга шаблона
    try {
        // Парсер удаляет старые элементы и создает новые
        $parser->parseTemplate($playId, $templateText);
        echo "  - Шаблон успешно распарсен.\n";

        // Проверяем, были ли созданы элементы в template_elements
        $elements = getTemplateElementsForPlay($playId);
        if (empty($elements)) {
            echo "  - ОШИБКА: После парсинга не найдено элементов в template_elements.\n";
            $errorsFound = true;
        } else {
            echo "  - Найдено " . count($elements) . " элементов в template_elements.\n";
        }

    } catch (Exception $e) {
        echo "  - КРИТИЧЕСКАЯ ОШИБКА ПАРСИНГА: " . $e->getMessage() . "\n";
        $errorsFound = true;
    }
    echo "\n";
}

if ($errorsFound) {
    echo "Проверка завершена с ошибками. Пожалуйста, просмотрите логи выше.\n";
} else {
    echo "Проверка завершена успешно. Все шаблоны распарсены корректно.\n";
}

?>
