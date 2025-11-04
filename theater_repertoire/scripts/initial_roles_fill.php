<?php
// initial_roles_fill.php

// Предполагается, что config.php находится в корневой директории проекта
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Models/PlayTemplateParser.php';

use App\Models\PlayTemplateParser;

// Получаем соединение с БД из config.php
$pdo = getDBConnection();

$parser = new PlayTemplateParser($pdo);

echo "Запускаем первоначальное заполнение ролей и артистов...\n";

// Получаем все шаблоны спектаклей
$stmt = $pdo->query("SELECT pt.play_id, pt.template_text FROM play_templates pt JOIN plays p ON pt.play_id = p.id");
$playTemplates = $stmt->fetchAll();

foreach ($playTemplates as $template) {
    if (empty($template['template_text'])) {
        echo "Пропускаем play_id: " . $template['play_id'] . " (пустой шаблон).\n";
        continue;
    }
    echo "Парсинг шаблона для play_id: " . $template['play_id'] . "\n";
    try {
        $parser->parseTemplate($template['play_id'], $template['template_text']);
        echo "  - Шаблон успешно обработан.\n";
    } catch (Exception $e) {
        echo "  - Ошибка при парсинге play_id " . $template['play_id'] . ": " . $e->getMessage() . "\n";
    }
}

echo "Первоначальное заполнение завершено.\n";
