<?php
// Тестовый файл для проверки admin.php
echo "Начало выполнения admin_test.php<br>";
echo "Время: " . date('H:i:s') . "<br>";

// Проверяем сессию
session_start();
echo "Session ID: " . session_id() . "<br>";

// Проверяем config.php
if (file_exists('config.php')) {
    echo "<span style='color: green;'>✅</span> config.php найден<br>";
    require_once 'config.php';
    echo "<span style='color: green;'>✅</span> config.php загружен<br>";
} else {
    echo "<span style='color: red;'>❌</span> config.php НЕ найден<br>";
}

// Проверяем db.php
if (file_exists('db.php')) {
    echo "<span style='color: green;'>✅</span> db.php найден<br>";
    require_once 'db.php';
    echo "<span style='color: green;'>✅</span> db.php загружен<br>";
} else {
    echo "<span style='color: red;'>❌</span> db.php НЕ найден<br>";
}

// Проверяем текущую сессию
echo "Текущая сессия:<br>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'не установлен') . "<br>";
echo "Username: " . ($_SESSION['username'] ?? 'не установлен') . "<br>";

// Проверяем функцию getCurrentUser
echo "getCurrentUser(): " . (getCurrentUser() ?? 'null') . "<br>";

// Проверяем requireAuth (без редиректа)
echo "Проверяем requireAuth...<br>";
if (!isset($_SESSION['user_id'])) {
    echo "<span style='color: red;'>❌ requireAuth: Пользователь не аутентифицирован</span><br>";
} else {
    echo "<span style='color: green;'>✅ requireAuth: Пользователь аутентифицирован</span><br>";
}

echo "<hr>";

// Проверяем подключение к БД
try {
    $pdo = getDBConnection();
    echo "БД подключена<br>";

    // Проверяем таблицы
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Найденные таблицы: " . implode(', ', $tables) . "<br>";

} catch (Exception $e) {
    echo "Ошибка БД: " . $e->getMessage() . "<br>";
}

echo "<br><a href='index.php'>Вернуться к index.php</a>";
?>
