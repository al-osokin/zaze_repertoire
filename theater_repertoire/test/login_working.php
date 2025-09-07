<?php
require_once 'config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Простая проверка вместо authenticateUser()
    if ($username === 'osokin' && $password === '40362151') {
        // Устанавливаем сессию
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = $username;

        // JavaScript редирект вместо header()
        echo "<script>window.location.href = 'index.php';</script>";
        exit;
    } else {
        $message = 'Неверное имя пользователя или пароль';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему - Репертуар театра</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .login-form {
            background: white;
            padding: 30px;
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
            color: #dc3545;
            margin-bottom: 15px;
            text-align: center;
        }
        .debug-info {
            background: #f8f9fa;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-form">
        <h2 style="text-align: center; margin-bottom: 20px;">Рабочая версия входа</h2>

        <div class="debug-info">
            <strong>Отладка:</strong><br>
            Session ID: <?php echo session_id(); ?><br>
            Метод: <?php echo $_SERVER['REQUEST_METHOD']; ?>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="username">Имя пользователя:</label>
                <input type="text" id="username" name="username" value="osokin" required>
            </div>

            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" value="40362151" required>
            </div>

            <button type="submit">Войти</button>
        </form>

        <div style="text-align: center; margin-top: 20px;">
            <a href="index.php" style="color: #007bff;">Перейти к главной странице</a>
        </div>
    </div>
</body>
</html>
