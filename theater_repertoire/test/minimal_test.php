<?php
// Минимальный тест - только базовые функции
echo "PHP работает<br>";
echo "Текущая директория: " . __DIR__ . "<br>";
echo "Время: " . date('H:i:s') . "<br>";

// Проверяем сессии
session_start();
echo "Session ID: " . session_id() . "<br>";

// Проверяем подключение к config
if (file_exists('config.php')) {
    echo "config.php найден<br>";
    require_once 'config.php';
    echo "config.php загружен<br>";
} else {
    echo "config.php НЕ найден<br>";
}

// Проверяем подключение к БД
try {
    $pdo = getDBConnection();
    echo "БД подключена<br>";
} catch (Exception $e) {
    echo "Ошибка БД: " . $e->getMessage() . "<br>";
}

echo "Тест завершен";
?>
