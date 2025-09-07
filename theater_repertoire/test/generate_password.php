<?php
// Скрипт для генерации хэша пароля
// Использование: измените $password и запустите скрипт

$password = '40362151'; // Измените на желаемый пароль

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Пароль: $password\n";
echo "Хэш: $hash\n";
echo "\n";
echo "Используйте этот хэш в schema.sql:\n";
echo "INSERT INTO users (username, password_hash) VALUES\n";
echo "('admin', '$hash');\n";
?>
