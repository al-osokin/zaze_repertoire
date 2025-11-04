# Скрипт для первоначального заполнения ролей и артистов

Этот документ содержит PHP-скрипт `initial_roles_fill.php`, предназначенный для однократного запуска с целью парсинга всех существующих шаблонов спектаклей и заполнения новых таблиц `roles` и `artists`.

## Файл: `scripts/initial_roles_fill.php`

```php
<?php
// initial_roles_fill.php

// Включите автозагрузчик, если используете Composer (PSR-4)
require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\PlayTemplateParser; // Убедитесь, что путь к классу правильный

// Конфигурация базы данных
$dbHost = 'localhost';
$dbName = 'avo_zaze';
$dbUser = 'your_user'; // Замените на вашего пользователя БД
$dbPass = 'your_password'; // Замените на ваш пароль БД

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

$parser = new PlayTemplateParser($pdo);

echo "Запускаем первоначальное заполнение ролей и артистов...\n";

// Получаем все шаблоны спектаклей
$stmt = $pdo->query("SELECT pt.play_id, pt.template_text FROM play_templates pt");
$playTemplates = $stmt->fetchAll();

foreach ($playTemplates as $template) {
    echo "Парсинг шаблона для play_id: " . $template['play_id'] . "\n";
    try {
        $parsedRoles = $parser->parseTemplate($template['play_id'], $template['template_text']);
        echo "  - Успешно распарсено " . count($parsedRoles) . " ролей.\n";
    } catch (Exception $e) {
        echo "  - Ошибка при парсинге play_id " . $template['play_id'] . ": " . $e->getMessage() . "\n";
    }
}

echo "Первоначальное заполнение завершено.\n";

```

## Действия для выполнения:
1.  Создайте файл `initial_roles_fill.php` в папке `scripts/` (если его еще нет).
2.  Настройте параметры подключения к базе данных (`$dbUser`, `$dbPass`) в скрипте.
3.  Запустите этот скрипт из командной строки:
    ```bash
    php scripts/initial_roles_fill.php
    ```
4.  **Проверьте:** Убедитесь, что таблицы `artists`, `roles`, `role_artist_history` заполнились данными.
