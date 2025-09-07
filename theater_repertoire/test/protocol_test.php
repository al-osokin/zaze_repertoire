<?php
// Тест для проверки протоколов и ссылок
echo "<h1>Проверка протоколов и ссылок</h1>";

// Информация о запросе
echo "<h2>Информация о текущем запросе:</h2>";
echo "<p><strong>REQUEST_URI:</strong> " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p><strong>HTTP_HOST:</strong> " . $_SERVER['HTTP_HOST'] . "</p>";
echo "<p><strong>SERVER_NAME:</strong> " . $_SERVER['SERVER_NAME'] . "</p>";
echo "<p><strong>HTTPS:</strong> " . ($_SERVER['HTTPS'] ?? 'не установлен') . "</p>";
echo "<p><strong>REQUEST_SCHEME:</strong> " . ($_SERVER['REQUEST_SCHEME'] ?? 'не установлен') . "</p>";
echo "<p><strong>SERVER_PORT:</strong> " . $_SERVER['SERVER_PORT'] . "</p>";

// Текущий протокол
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
echo "<p><strong>Определенный протокол:</strong> $protocol</p>";

// Сессия
session_start();
echo "<h2>Сессия:</h2>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>User ID:</strong> " . ($_SESSION['user_id'] ?? 'не установлен') . "</p>";
echo "<p><strong>Username:</strong> " . ($_SESSION['username'] ?? 'не установлен') . "</p>";

// Тестовые ссылки
echo "<h2>Тестовые ссылки:</h2>";
echo "<p><a href='admin.php'>Ссылка на admin.php (относительная)</a></p>";
echo "<p><a href='{$protocol}://" . $_SERVER['HTTP_HOST'] . "/admin.php'>Ссылка на admin.php (абсолютная с текущим протоколом)</a></p>";
echo "<p><a href='https://" . $_SERVER['HTTP_HOST'] . "/admin.php'>Ссылка на admin.php (HTTPS принудительно)</a></p>";

// Проверка доступности файлов
echo "<h2>Проверка файлов:</h2>";
$files_to_check = ['admin.php', 'config.php', 'db.php'];
foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "<p><span style='color: green;'>✅</span> $file - найден</p>";
    } else {
        echo "<p><span style='color: red;'>❌</span> $file - НЕ найден</p>";
    }
}

// Загружаем config.php и db.php для проверки функций
echo "<h2>Загрузка файлов:</h2>";
if (file_exists('config.php')) {
    echo "<p><span style='color: green;'>✅</span> config.php найден</p>";
    require_once 'config.php';
    echo "<p><span style='color: green;'>✅</span> config.php загружен</p>";
} else {
    echo "<p><span style='color: red;'>❌</span> config.php НЕ найден</p>";
}

if (file_exists('db.php')) {
    echo "<p><span style='color: green;'>✅</span> db.php найден</p>";
    require_once 'db.php';
    echo "<p><span style='color: green;'>✅</span> db.php загружен</p>";
} else {
    echo "<p><span style='color: red;'>❌</span> db.php НЕ найден</p>";
}

// Проверка функций
echo "<h2>Проверка функций:</h2>";
if (function_exists('requireAuth')) {
    echo "<p><span style='color: green;'>✅</span> requireAuth() - функция существует</p>";
} else {
    echo "<p><span style='color: red;'>❌</span> requireAuth() - функция НЕ существует</p>";
}

if (function_exists('getCurrentUser')) {
    echo "<p><span style='color: green;'>✅</span> getCurrentUser() - функция существует</p>";
} else {
    echo "<p><span style='color: red;'>❌</span> getCurrentUser() - функция НЕ существует</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>Вернуться к index.php</a></p>";
?>
