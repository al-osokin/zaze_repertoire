<?php
require_once 'config.php';
require_once 'parser.php';

// Тестируем парсер с примером из сентябрь 2025.txt
$testInput = "сентябрь 2025
18	19 Тоска	1603
19	19	Летучка	1602
20	12	Любимая	1613
	15	СП	1604
	19	СП	1605
21	12	Теремок	1611
	1530	Теремок	1610
	19	Порги	11606
27	12	Клык	1608
	16	Клык	1607
28	12	Свиньи	1612
	19	Салтан	1609
13	15	Апельсин	1380
14	18	Неизвестный	9999";

echo "<h1>Тест парсера репертуара</h1>";
echo "<h2>Входные данные:</h2>";
echo "<pre>" . htmlspecialchars($testInput) . "</pre>";

// Показываем спектакли из базы данных
echo "<h2>Спектакли в базе данных:</h2>";
$plays = getAllPlays();
echo "<ul>";
foreach ($plays as $play) {
    echo "<li><strong>{$play['short_name']}</strong> -> {$play['full_name']} (зал: {$play['hall']})</li>";
}
echo "</ul>";

echo "<h2>Результат:</h2>";
$result = parseRepertoireText($testInput);
echo "<pre>" . htmlspecialchars($result) . "</pre>";
?>
