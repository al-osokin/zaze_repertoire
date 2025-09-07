<?php
// Включаем отображение всех ошибок для диагностики
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Тест на ошибки PHP</h1>";
echo "<p>Если вы видите эту страницу, PHP работает корректно.</p>";

// Тестируем подключение к config.php
echo "<h2>Тест подключения config.php:</h2>";
try {
    require_once 'config.php';
    echo "<p style='color: green;'>✅ config.php загружен успешно</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка в config.php: " . $e->getMessage() . "</p>";
}

// Тестируем подключение к db.php
echo "<h2>Тест подключения db.php:</h2>";
try {
    require_once 'db.php';
    echo "<p style='color: green;'>✅ db.php загружен успешно</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка в db.php: " . $e->getMessage() . "</p>";
}

// Тестируем функции из db.php
echo "<h2>Тест функций БД:</h2>";
if (function_exists('getDBConnection')) {
    try {
        $pdo = getDBConnection();
        echo "<p style='color: green;'>✅ Функция getDBConnection() работает</p>";

        // Проверяем таблицы
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>Найдено таблиц: " . count($tables) . "</p>";

    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Ошибка подключения к БД: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Функция getDBConnection() не найдена</p>";
}

// Тестируем функцию authenticateUser
echo "<h2>Тест функции authenticateUser:</h2>";
if (function_exists('authenticateUser')) {
    try {
        $result = authenticateUser('osokin', '40362151');
        echo "<p>Результат аутентификации: " . ($result ? 'true' : 'false') . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Ошибка в authenticateUser(): " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Функция authenticateUser() не найдена</p>";
}

echo "<h2>Информация о сервере:</h2>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</li>";
echo "<li>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</li>";
echo "</ul>";
?>
