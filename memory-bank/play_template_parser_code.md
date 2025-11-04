# Код класса PlayTemplateParser

Этот документ содержит PHP-код класса `PlayTemplateParser`, который отвечает за парсинг шаблонов спектаклей и заполнение таблиц `roles` и `artists`.

## Файл: `app/Models/PlayTemplateParser.php`

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

## Рекомендации по `PlayTemplateParser.php` (из `theatre_schedule_project_plan.md`):
*   Для связи с базой данных используется PDO. Вам нужно будет передавать объект `PDO` в конструктор класса `PlayTemplateParser`.
*   Разделение имени и фамилии артиста (`$firstName = implode(' ', $parts); $lastName = array_pop($parts);`) — это простое решение. В некоторых случаях (например, двойные фамилии, или если в шаблоне всегда сначала фамилия) может потребоваться более сложная логика или ручная корректировка после первого парсинга.
*   Регексы настроены на ваш пример. Желательно протестировать на нескольких разных шаблонах, чтобы убедиться в их универсальности.
*   Для групповых ролей типа "Пушкин" парсер корректно разделит артистов по запятым и добавит каждого из них в `role_artist_history` для этой роли.
