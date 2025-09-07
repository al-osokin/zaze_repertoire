<?php
require_once 'config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Тестируем аутентификацию БЕЗ редиректа
    if (authenticateUser($username, $password)) {
        $message = '✅ Аутентификация успешна! Сессия установлена.';
    } else {
        $message = '❌ Неверное имя пользователя или пароль';
    }
}

// Проверяем текущую сессию
$currentUser = getCurrentUser();
$sessionInfo = '';
if ($currentUser) {
    $sessionInfo = "<p>✅ Текущий пользователь: <strong>$currentUser</strong></p>";
    $sessionInfo .= "<p>Session ID: " . session_id() . "</p>";
} else {
    $sessionInfo = "<p>❌ Пользователь не аутентифицирован</p>";
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отладка входа - Репертуар театра</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .debug-section {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        .login-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .links {
            text-align: center;
            margin-top: 20px;
        }
        .links a {
            display: inline-block;
            margin: 0 10px;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <h1>🔧 Отладка входа в систему</h1>

    <div class="debug-section">
        <h2>Статус сессии:</h2>
        <?php echo $sessionInfo; ?>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="login-form">
        <h2>Тест входа (без редиректа)</h2>
        <form method="post">
            <div class="form-group">
                <label for="username">Имя пользователя:</label>
                <input type="text" id="username" name="username" value="osokin" required>
            </div>

            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" value="40362151" required>
            </div>

            <button type="submit">Тестировать вход</button>
        </form>
    </div>

    <div class="debug-section">
        <h2>Тестовые данные:</h2>
        <p><strong>Логин:</strong> osokin</p>
        <p><strong>Пароль:</strong> 40362151</p>
        <p><em>Эти данные должны работать</em></p>
    </div>

    <div class="links">
        <a href="index.php">Перейти к index.php</a>
        <a href="login.php">Обычный login.php</a>
    </div>
</body>
</html>
