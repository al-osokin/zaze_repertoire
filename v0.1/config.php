<?php
// Конфигурация базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'avo_zaze');
define('DB_USER', 'avo_zazeuser'); // Замените на реальные данные
define('DB_PASS', 'xQ6cC8jW3g'); // Замените на реальные данные

// Настройки сессии
session_start();

// Функция для подключения к БД
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Функция для проверки аутентификации
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        // Используем JavaScript редирект вместо header()
        echo "<script>window.location.href = 'login.php';</script>";
        exit;
    }
}

// Функция для выхода
function logout() {
    session_destroy();
    // Используем JavaScript редирект вместо header()
    echo "<script>window.location.href = 'login.php';</script>";
    exit;
}
?>
