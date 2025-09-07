<?php
require_once 'config.php';

echo "<h1>Проверка пароля</h1>";

// Получаем данные пользователя из БД
$username = 'osokin'; // Измените на ваш логин

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        echo "<h2>Данные пользователя:</h2>";
        echo "<p>ID: " . $user['id'] . "</p>";
        echo "<p>Логин: " . $user['username'] . "</p>";
        echo "<p>Хэш пароля: " . $user['password_hash'] . "</p>";

        echo "<h2>Тестируем различные пароли:</h2>";

        $testPasswords = [
            'admin123',
            'password',
            '123456',
            'osokin',
            '40362151',
            'test'
        ];

        foreach ($testPasswords as $password) {
            $isValid = password_verify($password, $user['password_hash']);
            echo "<p>Пароль '$password': " . ($isValid ? '<span style="color: green;">✅ Корректный</span>' : '<span style="color: red;">❌ Некорректный</span>') . "</p>";
        }

        echo "<h2>Если ни один пароль не подошел:</h2>";
        echo "<p>Возможно, пароль был изменен при создании schema.sql</p>";
        echo "<p>Проверьте файл schema.sql и найдите строку с INSERT INTO users</p>";

    } else {
        echo "<p style='color: red;'>Пользователь '$username' не найден в базе данных</p>";

        // Показываем всех пользователей
        echo "<h2>Все пользователи в БД:</h2>";
        $stmt = $pdo->query("SELECT username FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($users)) {
            echo "<p style='color: red;'>В базе данных нет пользователей!</p>";
            echo "<p>Выполните schema.sql заново</p>";
        } else {
            echo "<ul>";
            foreach ($users as $user) {
                echo "<li>$user</li>";
            }
            echo "</ul>";
        }
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Ошибка: " . $e->getMessage() . "</p>";
}
?>
