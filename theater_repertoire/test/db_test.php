<?php
require_once 'config.php';

echo "<h1>Тест подключения к базе данных</h1>";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Подключение к БД успешно!</p>";

    // Проверяем таблицы
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>Найденные таблицы:</h2>";
    if (empty($tables)) {
        echo "<p style='color: red;'>❌ Таблицы не найдены! Загрузите schema.sql</p>";
    } else {
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    }

    // Проверяем пользователей
    if (in_array('users', $tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        echo "<p>Пользователей в БД: " . $result['count'] . "</p>";

        if ($result['count'] > 0) {
            $stmt = $pdo->query("SELECT username FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<p>Логины: " . implode(', ', $users) . "</p>";
        }
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Ошибка подключения: " . $e->getMessage() . "</p>";

    echo "<h2>Возможные причины:</h2>";
    echo "<ul>";
    echo "<li>Неправильные данные подключения в config.php</li>";
    echo "<li>База данных не существует</li>";
    echo "<li>Недостаточно прав доступа для пользователя БД</li>";
    echo "<li>Проблемы с сетевым подключением</li>";
    echo "</ul>";

    echo "<h2>Текущие настройки:</h2>";
    echo "<ul>";
    echo "<li>Хост: " . DB_HOST . "</li>";
    echo "<li>База: " . DB_NAME . "</li>";
    echo "<li>Пользователь: " . DB_USER . "</li>";
    echo "<li>Пароль: " . (empty(DB_PASS) ? 'пустой' : 'установлен') . "</li>";
    echo "</ul>";
}
?>
