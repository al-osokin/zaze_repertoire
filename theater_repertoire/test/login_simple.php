<?php
// Простейшая версия login.php без редиректа
echo "Начало выполнения login_simple.php<br>";
echo "Время: " . date('H:i:s') . "<br>";

// Проверяем метод запроса
echo "Метод запроса: " . $_SERVER['REQUEST_METHOD'] . "<br>";

// Проверяем сессию
session_start();
echo "Session ID: " . session_id() . "<br>";

// Проверяем POST данные
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "Получены POST данные:<br>";
    echo "Username: " . ($_POST['username'] ?? 'не указано') . "<br>";
    echo "Password: " . (isset($_POST['password']) ? 'указано' : 'не указано') . "<br>";

    // Простая проверка учетных данных
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === 'osokin' && $password === '40362151') {
        echo "<span style='color: green;'>✅ Учетные данные корректны!</span><br>";
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = $username;
        echo "Сессия установлена<br>";
    } else {
        echo "<span style='color: red;'>❌ Неверные учетные данные</span><br>";
    }
} else {
    echo "POST данные не получены<br>";
}

// Проверяем текущую сессию
echo "Текущая сессия:<br>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'не установлен') . "<br>";
echo "Username: " . ($_SESSION['username'] ?? 'не установлен') . "<br>";

echo "<hr>";

// Простая форма входа
?>
<form method="post">
    <label>Логин:</label>
    <input type="text" name="username" value="osokin"><br>
    <label>Пароль:</label>
    <input type="password" name="password" value="40362151"><br>
    <button type="submit">Войти</button>
</form>

<?php
echo "<br><a href='index.php'>Перейти к index.php</a>";
?>
