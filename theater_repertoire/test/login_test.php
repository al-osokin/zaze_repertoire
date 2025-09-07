<?php
require_once 'config.php';

// Имитируем процесс входа
echo "<h1>Тест процесса входа</h1>";

// Шаг 1: Проверяем данные для входа
$testUsername = 'osokin'; // Измените на ваш логин
$testPassword = '40362151'; // Измените на ваш пароль

echo "<h2>Тестовые данные:</h2>";
echo "<p>Логин: $testUsername</p>";
echo "<p>Пароль: $testPassword</p>";

// Шаг 2: Тестируем аутентификацию
echo "<h2>Шаг 2: Аутентификация</h2>";
echo "<p>Начинаем процесс аутентификации...</p>";

// Шаг 2.1: Проверяем подключение к БД
echo "<p>Шаг 2.1: Проверяем подключение к БД...</p>";
try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Подключение к БД успешно</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка подключения к БД: " . $e->getMessage() . "</p>";
    exit;
}

// Шаг 2.2: Выполняем запрос к БД
echo "<p>Шаг 2.2: Выполняем запрос SELECT...</p>";
try {
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    echo "<p>Запрос подготовлен</p>";

    $stmt->execute([$testUsername]);
    echo "<p>Запрос выполнен</p>";

    $user = $stmt->fetch();
    echo "<p>Результат получен</p>";

    if ($user) {
        echo "<p style='color: green;'>✅ Пользователь найден в БД</p>";
        echo "<p>User ID: " . $user['id'] . "</p>";

        // Шаг 2.3: Проверяем пароль
        echo "<p>Шаг 2.3: Проверяем пароль...</p>";
        $passwordValid = password_verify($testPassword, $user['password_hash']);
        echo "<p>Результат проверки пароля: " . ($passwordValid ? 'true' : 'false') . "</p>";

        if ($passwordValid) {
            echo "<p style='color: green;'>✅ Пароль корректный</p>";

            // Шаг 2.4: Устанавливаем сессию
            echo "<p>Шаг 2.4: Устанавливаем сессию...</p>";
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $testUsername;
            echo "<p style='color: green;'>✅ Сессия установлена</p>";

            $result = true;
        } else {
            echo "<p style='color: red;'>❌ Пароль некорректный</p>";
            $result = false;
        }
    } else {
        echo "<p style='color: red;'>❌ Пользователь НЕ найден в БД</p>";
        $result = false;
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка при выполнении запроса: " . $e->getMessage() . "</p>";
    $result = false;
}

if ($result) {
    echo "<p style='color: green;'>✅ Аутентификация успешна!</p>";

    // Шаг 3: Проверяем сессию после аутентификации
    echo "<h2>Шаг 3: Сессия после входа</h2>";
    echo "<p>Session ID: " . session_id() . "</p>";
    echo "<p>User ID: " . ($_SESSION['user_id'] ?? 'не установлен') . "</p>";
    echo "<p>Username: " . ($_SESSION['username'] ?? 'не установлен') . "</p>";

    // Шаг 4: Тестируем перенаправление
    echo "<h2>Шаг 4: Тест перенаправления</h2>";
    if (isset($_GET['redirect'])) {
        echo "<p>Выполняем перенаправление на index.php...</p>";
        header('Location: index.php');
        exit;
    } else {
        echo "<p><a href='?redirect=1'>Выполнить перенаправление</a></p>";
    }

    // Шаг 5: Тестируем requireAuth
    echo "<h2>Шаг 5: Тест requireAuth</h2>";
    try {
        requireAuth();
        echo "<p style='color: green;'>✅ requireAuth() прошел успешно</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Ошибка в requireAuth(): " . $e->getMessage() . "</p>";
    }

} else {
    echo "<p style='color: red;'>❌ Аутентификация не удалась</p>";

    // Проверяем, существует ли пользователь
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$testUsername]);
    $user = $stmt->fetch();

    if ($user) {
        echo "<p>Пользователь найден в БД</p>";
        echo "<p>Хэш пароля: " . $user['password_hash'] . "</p>";

        // Проверяем пароль отдельно
        $passwordValid = password_verify($testPassword, $user['password_hash']);
        echo "<p>Пароль корректный: " . ($passwordValid ? 'Да' : 'Нет') . "</p>";
    } else {
        echo "<p style='color: red;'>Пользователь НЕ найден в БД</p>";
    }
}

// Шаг 6: Показываем все переменные сессии
echo "<h2>Все переменные сессии:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
?>
