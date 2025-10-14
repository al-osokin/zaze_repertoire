Отличная идея, работать в VS Code через плагин Cline и с файлами на Google Drive будет намного эффективнее! Это позволит мне более непосредственно помогать с кодом и структурой проекта.

Я сформирую подробный файл в формате Markdown (`.md`) со всеми инструкциями, SQL-скриптами, набросками PHP-кода и объяснениями. Я представлю его здесь в виде текста, и вы сможете скопировать его и сохранить на Google Drive с именем, например, `theatre_schedule_project_plan.md`.

После этого вы сможете открыть этот файл в VS Code, и мы будем работать над ним, обновляя и добавляя код по мере продвижения.

---

Вот содержимое файла `theatre_schedule_project_plan.md`:

```markdown
# Проект "Администрирование Афиши Театра" - План Разработки и Интеграции

Этот документ содержит детальный план по проектированию и разработке системы для управления составом исполнителей спектаклей и формирования карточек для социальных сетей. Мы будем использовать PHP и MariaDB, интегрируясь с вашей существующей базой данных.

## 1. Обзор и Цели Проекта

**Текущая ситуация:**
*   Реализовано формирование месячной афиши спектаклей на PHP + MariaDB.
*   Таблица `plays` содержит список спектаклей.
*   Таблица `play_templates` содержит шаблоны карточек спектаклей в вики-разметке.
*   Таблица `events_raw` содержит информацию о конкретных представлениях (афиша).
*   Карточки публикуются в VK, но состав заполняется вручную.

**Цели новой системы:**
1.  **Удобное назначение артистов:** Предоставить диалоговую форму для выбора артистов на роли в каждом представлении.
2.  **Гибкость назначений:** Учитывать, что один артист может играть несколько ролей, и на одной роли может быть несколько исполнителей.
3.  **Автоматизация заполнения:** Запоминать предпочтительных артистов для каждой роли.
4.  **Расширенный список исполнителей:** Учитывать не только актеров, но и дирижеров, пианистов и т.д.
5.  **Парсинг шаблонов:** Автоматически извлекать роли и начальный состав из существующих шаблонов `play_templates`.
6.  **Генерация карточек:** Формировать готовую вики-разметку для карточки спектакля с назначенным составом.

## 2. Обновленная Структура Базы Данных

Мы добавим новые таблицы и модифицируем одну существующую.

### 2.1. Добавляемые таблицы

```sql
--
-- Таблица 'artists' - для всех исполнителей (актеры, дирижеры, пианисты)
--
CREATE TABLE `artists` (
  `artist_id` INT(11) NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `type` ENUM('artist', 'conductor', 'pianist', 'other') DEFAULT 'artist', -- Категория исполнителя
  `vk_link` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`artist_id`),
  UNIQUE KEY `idx_unique_artist_name_type` (`first_name`, `last_name`, `type`) -- Защита от дубликатов
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'roles' - список уникальных ролей для каждого спектакля
--
CREATE TABLE `roles` (
  `role_id` INT(11) NOT NULL AUTO_INCREMENT,
  `play_id` INT(11) NOT NULL,                               -- Ссылка на plays.id
  `role_name` VARCHAR(255) NOT NULL,                        -- Название роли (например, "Герман", "Пушкин")
  `role_description` VARCHAR(255) NULL DEFAULT NULL,        -- Дополнительный текст (например, ", офицер", ", внучка графини")
  `expected_artist_type` ENUM('artist', 'conductor', 'pianist', 'other') DEFAULT 'artist', -- Ожидаемый тип исполнителя
  `sort_order` INT(11) DEFAULT 0,                           -- Порядок вывода ролей в карточке
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  ``updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `idx_unique_role_per_play` (`play_id`, `role_name`, `role_description`), -- Уникальность роли в рамках спектакля
  CONSTRAINT `fk_roles_play_id` FOREIGN KEY (`play_id`) REFERENCES `plays` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'performance_roles_artists' - назначение артистов на роли для конкретного представления
--
CREATE TABLE `performance_roles_artists` (
  `performance_role_artist_id` INT(11) NOT NULL AUTO_INCREMENT,
  `performance_id` INT(11) NOT NULL,                     -- Ссылка на events_raw.id
  `role_id` INT(11) NOT NULL,                            -- Ссылка на roles.role_id
  `artist_id` INT(11) NULL DEFAULT NULL,                 -- Ссылка на artists.artist_id (NULL, если "СОСТАВ УТОЧНЯЕТСЯ")
  `custom_artist_name` VARCHAR(255) NULL DEFAULT NULL,   -- Для временного хранения имени нового артиста
  `sort_order_in_role` INT(11) DEFAULT 0,                -- Порядок, если несколько артистов на одной роли (для групповых ролей)
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`performance_role_artist_id`),
  UNIQUE KEY `idx_unique_performance_role_artist` (`performance_id`, `role_id`, `artist_id`, `sort_order_in_role`), -- Защита от дубликатов
  CONSTRAINT `fk_pra_performance_id` FOREIGN KEY (`performance_id`) REFERENCES `events_raw` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pra_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pra_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Таблица 'role_artist_history' - история назначений артистов на роли (для формирования "частых" исполнителей)
--
CREATE TABLE `role_artist_history` (
  `role_artist_history_id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `artist_id` INT(11) NOT NULL,
  `last_assigned_date` DATETIME DEFAULT CURRENT_TIMESTAMP(),
  `assignment_count` INT(11) DEFAULT 1,
  PRIMARY KEY (`role_artist_history_id`),
  UNIQUE KEY `idx_unique_role_artist_combo` (`role_id`, `artist_id`), -- Один артист на одну роль, история ведется по паре
  CONSTRAINT `fk_rah_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rah_artist_id` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`artist_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 2.2. Модификация `events_raw`

Добавим два поля в вашу существующую таблицу `events_raw`.

```sql
ALTER TABLE `events_raw`
ADD COLUMN `vk_post_text` TEXT NULL DEFAULT NULL AFTER `background_url`,
ADD COLUMN `is_published_vk` TINYINT(1) DEFAULT 0 AFTER `vk_post_text`;
```

## 3. Этапы Разработки и Реализации

### Этап 1: Подготовка Базы Данных

**Задача:** Создать новые таблицы и модифицировать существующую.

**Действия:**
1.  Выполните SQL-скрипты из раздела 2.1 и 2.2 на вашей базе данных `avo_zaze`.
    *   Рекомендуется сделать резервную копию базы данных перед выполнением.
    *   Можно использовать phpMyAdmin, DBeaver или консольный клиент `mariadb`.
    ```bash
    # Пример выполнения через консоль
    mariadb -u your_user -p avo_zaze < your_sql_script_with_new_tables.sql
    ```
2.  **Подтвердите:** Убедитесь, что все таблицы созданы и поля добавлены.

### Этап 2: Разработка Парсера Шаблонов (`PlayTemplateParser.php`)

**Задача:** Создать PHP-класс или функцию для парсинга `play_templates.template_text` и заполнения таблиц `roles` и `artists` (если найдены в шаблоне).

**Файл:** `app/Models/PlayTemplateParser.php` (или `app/Services/PlayTemplateParser.php`, в зависимости от вашей архитектуры)

```php
<?php
namespace App\Models; // Или App\Services

class PlayTemplateParser
{
    private $pdo; // Объект PDO для работы с базой данных

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Основная функция для парсинга шаблона спектакля и заполнения таблиц roles и artists.
     * @param int $playId ID спектакля из таблицы 'plays'.
     * @param string $templateText Полный текст шаблона из play_templates.template_text.
     * @return array Массив распарсенных ролей с их начальными артистами.
     */
    public function parseTemplate(int $playId, string $templateText): array
    {
        $rolesData = [];
        $lines = explode("\n", $templateText);
        $currentSortOrder = 0; // Для упорядочивания ролей

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Игнорируем заголовки типа ==В ролях:==
            if (str_starts_with($line, '==') && !str_starts_with($line, '===')) {
                continue;
            }

            // Игнорируем заглушку "СОСТАВ УТОЧНЯЕТСЯ"
            if (str_contains($line, 'СОСТАВ УТОЧНЯЕТСЯ')) {
                continue;
            }

            // Игнорируем фото и ссылку на билеты
            if (str_starts_with($line, '[[photo') || str_contains($line, '|КУПИТЬ БИЛЕТ]')) {
                continue;
            }

            // Регекс для заголовков разделов (например, ===Пастораль «Искренность пастушки»===)
            if (preg_match('/^={3}(.+?)={3}/u', $line, $matches)) {
                // Можно использовать для группировки ролей или для sort_order
                // Пока просто пропускаем, но можно сохранять в какой-то мета-информации для role.sort_order
                continue;
            }

            // Регекс для строк ролей с артистами: '''Роль''', описание — Артист1, Артист2
            if (preg_match('/^\'\'\'([^''\']+)\'\'\'(?:,\s*([^—]+))?\s*—\s*(.+)/u', $line, $matches)) {
                $roleName = trim($matches[1]);
                $roleDescription = isset($matches[2]) ? trim($matches[2]) : null;
                $artistNamesStr = trim($matches[3]);
                $initialArtists = [];
                $expectedArtistType = 'artist';

                // Определяем тип исполнителя по названию роли
                if (mb_stripos($roleName, 'Дирижёр') !== false) {
                    $expectedArtistType = 'conductor';
                } elseif (mb_stripos($roleName, 'Клавесин') !== false || mb_stripos($roleName, 'Концертмейстер') !== false) {
                    $expectedArtistType = 'pianist';
                }
                // Можно добавить другие условия для 'other'

                // Разделяем строку артистов на отдельные имена
                $artistNames = array_map('trim', explode(',', $artistNamesStr));
                foreach ($artistNames as $artistFullName) {
                    // Разделяем имя на имя и фамилию (простое предположение: последнее слово - фамилия)
                    $parts = explode(' ', $artistFullName);
                    $lastName = array_pop($parts);
                    $firstName = implode(' ', $parts);

                    $artistId = $this->findOrCreateArtist($firstName, $lastName, $expectedArtistType);
                    if ($artistId) {
                        $initialArtists[] = $artistId;
                    }
                }

                $rolesData[] = [
                    'role_name' => $roleName,
                    'role_description' => $roleDescription,
                    'expected_artist_type' => $expectedArtistType,
                    'initial_artists' => $initialArtists,
                    'sort_order' => $currentSortOrder++
                ];
            } else {
                // Если есть роли без артистов (которые не являются СОСТАВ УТОЧНЯЕТСЯ)
                // Пример: '''Некая роль'''
                if (preg_match('/^\'\'\'([^''\']+)\'\'\'(?:,\s*([^—]+))?\s*$/u', $line, $matches)) {
                    $roleName = trim($matches[1]);
                    $roleDescription = isset($matches[2]) ? trim($matches[2]) : null;
                    $expectedArtistType = 'artist'; // По умолчанию

                    // Определяем тип исполнителя по названию роли
                    if (mb_stripos($roleName, 'Дирижёр') !== false) {
                        $expectedArtistType = 'conductor';
                    } elseif (mb_stripos($roleName, 'Клавесин') !== false || mb_stripos($roleName, 'Концертмейстер') !== false) {
                        $expectedArtistType = 'pianist';
                    }

                    $rolesData[] = [
                        'role_name' => $roleName,
                        'role_description' => $roleDescription,
                        'expected_artist_type' => $expectedArtistType,
                        'initial_artists' => [], // Нет начальных артистов
                        'sort_order' => $currentSortOrder++
                    ];
                }
            }
        }
        
        // Теперь сохраняем распарсенные роли в БД
        $this->saveParsedRoles($playId, $rolesData);

        return $rolesData;
    }

    /**
     * Ищет артиста по имени, фамилии и типу, или создает нового, если не найден.
     * @param string $firstName
     * @param string $lastName
     * @param string $type
     * @return int ID артиста
     */
    private function findOrCreateArtist(string $firstName, string $lastName, string $type): int
    {
        $stmt = $this->pdo->prepare("SELECT artist_id FROM artists WHERE first_name = ? AND last_name = ? AND type = ?");
        $stmt->execute([$firstName, $lastName, $type]);
        $artistId = $stmt->fetchColumn();

        if ($artistId) {
            return $artistId;
        }

        $stmt = $this->pdo->prepare("INSERT INTO artists (first_name, last_name, type) VALUES (?, ?, ?)");
        $stmt->execute([$firstName, $lastName, $type]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Сохраняет распарсенные роли в таблицы roles и role_artist_history.
     * @param int $playId
     * @param array $rolesData
     */
    private function saveParsedRoles(int $playId, array $rolesData): void
    {
        foreach ($rolesData as $role) {
            // Ищем роль. Если есть - обновляем, иначе создаем
            $stmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? AND role_name = ? AND (role_description = ? OR (role_description IS NULL AND ? IS NULL))");
            $stmt->execute([$playId, $role['role_name'], $role['role_description'], $role['role_description']]);
            $roleId = $stmt->fetchColumn();

            if ($roleId) {
                // Обновляем существующую роль
                $stmt = $this->pdo->prepare("UPDATE roles SET expected_artist_type = ?, sort_order = ?, updated_at = NOW() WHERE role_id = ?");
                $stmt->execute([$role['expected_artist_type'], $role['sort_order'], $roleId]);
            } else {
                // Создаем новую роль
                $stmt = $this->pdo->prepare("INSERT INTO roles (play_id, role_name, role_description, expected_artist_type, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$playId, $role['role_name'], $role['role_description'], $role['expected_artist_type'], $role['sort_order']]);
                $roleId = $this->pdo->lastInsertId();
            }

            // Сохраняем начальных артистов в историю назначений
            foreach ($role['initial_artists'] as $artistId) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO role_artist_history (role_id, artist_id, assignment_count, last_assigned_date)
                    VALUES (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE assignment_count = assignment_count + 1, last_assigned_date = NOW();
                ");
                $stmt->execute([$roleId, $artistId]);
            }
        }
    }
}
```

**Рекомендации по `PlayTemplateParser.php`:**
*   Для связи с базой данных используется PDO. Вам нужно будет передавать объект `PDO` в конструктор класса `PlayTemplateParser`.
*   Разделение имени и фамилии артиста (`$firstName = implode(' ', $parts); $lastName = array_pop($parts);`) — это простое решение. В некоторых случаях (например, двойные фамилии, или если в шаблоне всегда сначала фамилия) может потребоваться более сложная логика или ручная корректировка после первого парсинга.
*   Регексы настроены на ваш пример. Желательно протестировать на нескольких разных шаблонах, чтобы убедиться в их универсальности.
*   Для групповых ролей типа "Пушкин" парсер корректно разделит артистов по запятым и добавит каждого из них в `role_artist_history` для этой роли.

### Этап 3: Скрипт для Первоначального Заполнения (`initial_roles_fill.php`)

**Задача:** Запустить парсер для всех существующих шаблонов в `play_templates`.

**Файл:** `scripts/initial_roles_fill.php`

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

**Действия:**
1.  Создайте файл `initial_roles_fill.php` в папке `scripts/`.
2.  Настройте параметры подключения к базе данных (`$dbUser`, `$dbPass`).
3.  Запустите этот скрипт из командной строки:
    ```bash
    php scripts/initial_roles_fill.php
    ```
4.  **Проверьте:** Убедитесь, что таблицы `artists`, `roles`, `role_artist_history` заполнились данными.

### Этап 4: Разработка Интерфейса Пользователя (PHP + HTML/CSS/JS)

**Задача:** Создать страницу для администрирования афиши, где можно будет выбрать представление, отредактировать состав и сгенерировать карточку.

#### 4.1. Страница списка представлений на месяц

Предполагается, что у вас уже есть некая страница, которая отображает список спектаклей на месяц из `events_raw`. Нам нужно будет добавить ссылку/кнопку "Редактировать состав" для каждого представления.

**Файл:** `views/admin/month_schedule.php` (или аналогичный)

```php
<?php
// month_schedule.php - Пример отображения списка представлений

// Подключение к БД и другие инициализации
require_once __DIR__ . '/../../config/database.php'; // Пример пути к файлу конфигурации БД

$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$stmt = $pdo->prepare("
    SELECT
        er.id AS performance_id,
        er.event_date,
        er.event_time,
        er.title,
        er.ticket_code,
        p.full_name AS play_name,
        er.vk_post_text IS NOT NULL AS has_vk_card,
        (SELECT COUNT(*) FROM performance_roles_artists pra WHERE pra.performance_id = er.id) AS assigned_artists_count,
        (SELECT COUNT(*) FROM roles r WHERE r.play_id = er.play_id) AS total_roles_count
    FROM
        events_raw er
    JOIN
        plays p ON er.play_id = p.id
    WHERE
        er.month = ? AND er.year = ?
    ORDER BY
        er.event_date, er.event_time
");
$stmt->execute([$month, $year]);
$performances = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Афиша на <?php echo $month . '.' . $year; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css"> <!-- Ваш CSS -->
</head>
<body>
    <h1>Афиша на <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h1>
    <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 -1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 -1 month')); ?>">Предыдущий месяц</a>
    <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 +1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 +1 month')); ?>">Следующий месяц</a>

    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Время</th>
                <th>Спектакль</th>
                <th>Статус состава</th>
                <th>Карточка VK</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($performances as $performance): ?>
            <tr>
                <td><?php echo date('d.m.Y', strtotime($performance['event_date'])); ?></td>
                <td><?php echo date('H:i', strtotime($performance['event_time'])); ?></td>
                <td><?php echo htmlspecialchars($performance['play_name']); ?></td>
                <td>
                    <?php
                        if ($performance['total_roles_count'] == 0) {
                            echo "Роли не определены";
                        } elseif ($performance['assigned_artists_count'] == 0) {
                            echo "Не заполнено";
                        } elseif ($performance['assigned_artists_count'] < $performance['total_roles_count']) {
                            echo "Частично заполнено";
                        } else {
                            echo "Заполнено";
                        }
                    ?>
                </td>
                <td><?php echo $performance['has_vk_card'] ? 'Сформирована' : 'Нет'; ?></td>
                <td>
                    <a href="edit_cast.php?performance_id=<?php echo $performance['performance_id']; ?>">Редактировать состав</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
```

#### 4.2. Диалоговая форма "Редактирование состава"

Это будет отдельная страница или модальное окно, куда пользователь переходит по ссылке "Редактировать состав".

**Файл:** `views/admin/edit_cast.php`

```php
<?php
// edit_cast.php

// Подключение к БД и другие инициализации
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Models/PlayTemplateParser.php'; // Подключаем парсер, если он нужен для инициализации

$performanceId = $_GET['performance_id'] ?? null;

if (!$performanceId) {
    die("ID представления не указан.");
}

try {
    // 1. Получаем информацию о представлении
    $stmt = $pdo->prepare("
        SELECT
            er.id AS performance_id,
            er.event_date,
            er.event_time,
            er.title,
            er.ticket_code,
            er.play_id,
            p.full_name AS play_name,
            pt.template_text AS play_template_text,
            er.vk_post_text
        FROM
            events_raw er
        JOIN
            plays p ON er.play_id = p.id
        LEFT JOIN
            play_templates pt ON p.id = pt.play_id
        WHERE
            er.id = ?
    ");
    $stmt->execute([$performanceId]);
    $performance = $stmt->fetch();

    if (!$performance) {
        die("Представление не найдено.");
    }

    // 2. Инициализация ролей для данного представления, если их еще нет
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM performance_roles_artists WHERE performance_id = ?");
    $stmt->execute([$performanceId]);
    $existingRolesCount = $stmt->fetchColumn();

    if ($existingRolesCount === 0 && $performance['play_template_text']) {
        // Если ролей для этого представления еще нет, парсим шаблон и заполняем
        $parser = new App\Models\PlayTemplateParser($pdo);
        $parser->parseTemplate($performance['play_id'], $performance['play_template_text']); // Этот вызов заполнит roles и history
        // Теперь нужно создать записи в performance_roles_artists с пустым составом
        $this->initializePerformanceRoles($pdo, $performance['performance_id'], $performance['play_id']);
    }

    // 3. Получаем список ролей для спектакля и назначенных на них артистов для данного представления
    $stmt = $pdo->prepare("
        SELECT
            r.role_id,
            r.role_name,
            r.role_description,
            r.expected_artist_type,
            pra.performance_role_artist_id,
            pra.artist_id,
            pra.custom_artist_name,
            pra.sort_order_in_role
        FROM
            roles r
        LEFT JOIN
            performance_roles_artists pra ON r.role_id = pra.role_id AND pra.performance_id = ?
        WHERE
            r.play_id = ?
        ORDER BY
            r.sort_order, r.role_id, pra.sort_order_in_role
    ");
    $stmt->execute([$performanceId, $performance['play_id']]);
    $assignedRoles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roleKey = $row['role_id'];
        if (!isset($assignedRoles[$roleKey])) {
            $assignedRoles[$roleKey] = [
                'role_id' => $row['role_id'],
                'role_name' => $row['role_name'],
                'role_description' => $row['role_description'],
                'expected_artist_type' => $row['expected_artist_type'],
                'artists' => []
            ];
        }
        if ($row['performance_role_artist_id']) { // Если артист назначен
            $assignedRoles[$roleKey]['artists'][] = [
                'performance_role_artist_id' => $row['performance_role_artist_id'],
                'artist_id' => $row['artist_id'],
                'custom_artist_name' => $row['custom_artist_name'],
                'sort_order_in_role' => $row['sort_order_in_role']
            ];
        } else {
             // Если нет назначенных артистов, но роль существует, добавляем пустой слот
             if (empty($assignedRoles[$roleKey]['artists'])) {
                 $assignedRoles[$roleKey]['artists'][] = [
                     'performance_role_artist_id' => null,
                     'artist_id' => null,
                     'custom_artist_name' => 'СОСТАВ УТОЧНЯЕТСЯ',
                     'sort_order_in_role' => 0
                 ];
             }
        }
    }


    // 4. Получаем списки артистов для выпадающих списков
    $allArtistsStmt = $pdo->query("SELECT artist_id, first_name, last_name, type FROM artists ORDER BY last_name, first_name");
    $allArtists = $allArtistsStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC); // Группируем по type
    // $allArtists будет иметь вид: ['artist' => [...], 'conductor' => [...], ...]

    // 5. Обработка POST-запросов (сохранение состава, генерация карточки)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action']) && $_POST['action'] === 'save_cast') {
            // Реализация сохранения состава
            // Удаляем старые назначения для этого performance_id
            $deleteStmt = $pdo->prepare("DELETE FROM performance_roles_artists WHERE performance_id = ?");
            $deleteStmt->execute([$performanceId]);

            $newAssignments = $_POST['roles'] ?? [];
            foreach ($newAssignments as $roleId => $artistsData) {
                foreach ($artistsData as $sortOrder => $artistInput) {
                    $artistId = null;
                    $customArtistName = null;

                    if ($artistInput['artist_id'] === 'new_artist') {
                        // Обработка нового артиста
                        $fullName = trim($artistInput['custom_name']);
                        $parts = explode(' ', $fullName);
                        $lastName = array_pop($parts);
                        $firstName = implode(' ', $parts);
                        $expectedType = $artistInput['expected_type'] ?? 'artist'; // Тип передается с формой

                        $artistId = $parser->findOrCreateArtist($firstName, $lastName, $expectedType);
                    } elseif ($artistInput['artist_id'] === 'none') {
                        // "СОСТАВ УТОЧНЯЕТСЯ" или пусто
                        $artistId = null;
                        $customArtistName = 'СОСТАВ УТОЧНЯЕТСЯ'; // Или оставляем null
                    } else {
                        $artistId = (int)$artistInput['artist_id'];
                    }

                    // Сохраняем назначение
                    $insertStmt = $pdo->prepare("
                        INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$performanceId, $roleId, $artistId, $customArtistName, $sortOrder]);

                    // Обновляем историю назначений
                    if ($artistId) {
                        $historyStmt = $pdo->prepare("
                            INSERT INTO role_artist_history (role_id, artist_id, assignment_count, last_assigned_date)
                            VALUES (?, ?, 1, NOW())
                            ON DUPLICATE KEY UPDATE assignment_count = assignment_count + 1, last_assigned_date = NOW();
                        ");
                        $historyStmt->execute([$roleId, $artistId]);
                    }
                }
            }
            $_SESSION['message'] = "Состав сохранен!";
            header("Location: edit_cast.php?performance_id=$performanceId");
            