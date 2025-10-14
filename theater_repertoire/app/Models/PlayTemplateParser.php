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
        $lines = preg_split("/\r\n|\r|\n/", $templateText);
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

            // Попытка разобрать строки с ролями и артистами
            if (!str_contains($line, '—')) {
                if (preg_match('/^\'\'\'([^\'\'\']+)\'\'\'(?:,\s*([^—]+))?\s*$/u', $line, $matches)) {
                    $roleName = trim(str_replace(["'''", "''"], '', $matches[1]));
                    $roleDescription = isset($matches[2]) ? trim($matches[2]) : null;
                    if ($roleDescription !== null && $roleDescription === '') {
                        $roleDescription = null;
                    }
                    $expectedArtistType = $this->detectExpectedArtistType($roleName);

                    $rolesData[] = [
                        'role_name' => $roleName,
                        'role_description' => $roleDescription,
                        'expected_artist_type' => $expectedArtistType,
                        'initial_artists' => [],
                        'sort_order' => $currentSortOrder++
                    ];
                }
                continue;
            }

            $parts = preg_split('/\s+—\s+/u', $line, 2);
            if (!$parts || count($parts) < 2) {
                continue;
            }

            [$rawRolePart, $artistNamesStr] = array_map('trim', $parts);
            if ($artistNamesStr === '') {
                continue;
            }

            $cleanRolePart = preg_replace('/\s+/u', ' ', trim(str_replace(["'''", "''"], '', $rawRolePart)));
            if ($cleanRolePart === '') {
                continue;
            }

            $roleName = $cleanRolePart;
            $roleDescription = null;

            if (preg_match('/^(.+?),\s*(.+)$/u', $cleanRolePart, $roleMatches)) {
                $roleName = trim($roleMatches[1]);
                $roleDescription = trim($roleMatches[2]);
            }
            if ($roleDescription !== null && $roleDescription === '') {
                $roleDescription = null;
            }

            $expectedArtistType = $this->detectExpectedArtistType($roleName);

            $artistNames = array_map('trim', explode(',', $artistNamesStr));
            $initialArtists = [];

            foreach ($artistNames as $artistFullName) {
                $artistFullName = trim(str_replace(["'''", "''"], '', $artistFullName));
                if ($artistFullName === '') {
                    continue;
                }

                // Убираем пометки вида (''впервые в роли'') или (впервые в роли)
                $artistFullName = preg_replace("/\\(''+.*?''\\)/u", '', $artistFullName);
                $artistFullName = preg_replace("/\(([^)]*впервые в роли[^)]*)\)/iu", '', $artistFullName);
                $artistFullName = preg_replace('/\s+/u', ' ', trim($artistFullName));
                if ($artistFullName === '') {
                    continue;
                }

                $firstName = '';
                $lastName = '';

                $knownGroupNames = ['Ансамбль', 'ансамбль', 'Хор', 'хор', 'Детский хор', 'оркестр', 'Оркестр'];
                if (in_array($artistFullName, $knownGroupNames, true)) {
                    $lastName = $artistFullName;
                } else {
                    $parts = preg_split('/\s+/u', $artistFullName);
                    if (count($parts) === 1) {
                        $lastName = $artistFullName;
                    } else {
                        $lastName = array_pop($parts);
                        $firstName = implode(' ', $parts);
                    }
                }

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
        }
        
        // Теперь сохраняем распарсенные роли в БД
        $this->saveParsedRoles($playId, $rolesData);

        return $rolesData;
    }

    private function detectExpectedArtistType(string $roleName): string
    {
        if (mb_stripos($roleName, 'Дирижёр') !== false || mb_stripos($roleName, 'Дирижер') !== false) {
            return 'conductor';
        }

        if (
            mb_stripos($roleName, 'Клавесин') !== false ||
            mb_stripos($roleName, 'Концертмейстер') !== false ||
            mb_stripos($roleName, 'Пианист') !== false
        ) {
            return 'pianist';
        }

        return 'artist';
    }

    /**
     * Ищет артиста по имени, фамилии и типу, или создает нового, если не найден.
     * @param string $firstName
     * @param string $lastName
     * @param string $type
     * @return int ID артиста
     */
    private function findOrCreateArtist(string $inputFirstName, string $inputLastName, string $type): int
    {
        // Поиск по точному совпадению (Имя Фамилия)
        $stmt = $this->pdo->prepare("SELECT artist_id FROM artists WHERE first_name = ? AND last_name = ? AND type = ?");
        $stmt->execute([$inputFirstName, $inputLastName, $type]);
        $artistId = $stmt->fetchColumn();

        if ($artistId) {
            return $artistId;
        }

        // Если не найдено, поиск по обратному порядку (Фамилия Имя), если оба поля не пустые
        if (!empty($inputFirstName) && !empty($inputLastName)) {
            $stmt = $this->pdo->prepare("SELECT artist_id FROM artists WHERE first_name = ? AND last_name = ? AND type = ?");
            $stmt->execute([$inputLastName, $inputFirstName, $type]); // Поиск с поменянными местами
            $artistId = $stmt->fetchColumn();

            if ($artistId) {
                // Если найдено по обратному порядку, возвращаем ID.
                // Для консистентности можно было бы обновить запись в БД на канонический вид,
                // но для текущей задачи достаточно найти существующую.
                return $artistId;
            }
        }

        // Если артист не найден ни в одном порядке, создаем новую запись
        $stmt = $this->pdo->prepare("INSERT INTO artists (first_name, last_name, type) VALUES (?, ?, ?)");
        $stmt->execute([$inputFirstName, $inputLastName, $type]);
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
            $roleName = $role['role_name'];
            $roleDescription = $role['role_description'];
            if ($roleDescription !== null) {
                $roleDescription = trim($roleDescription);
                if ($roleDescription === '') {
                    $roleDescription = null;
                }
            }

            // Ищем роль. Если есть - обновляем, иначе создаем
            $stmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? AND role_name = ? AND (role_description = ? OR (role_description IS NULL AND ? IS NULL))");
            $stmt->execute([$playId, $roleName, $roleDescription, $roleDescription]);
            $roleId = $stmt->fetchColumn();

            if ($roleId) {
                // Обновляем существующую роль
                $stmt = $this->pdo->prepare("UPDATE roles SET expected_artist_type = ?, sort_order = ?, updated_at = NOW() WHERE role_id = ?");
                $stmt->execute([$role['expected_artist_type'], $role['sort_order'], $roleId]);
            } else {
                // Создаем новую роль
                $stmt = $this->pdo->prepare("INSERT INTO roles (play_id, role_name, role_description, expected_artist_type, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$playId, $roleName, $roleDescription, $role['expected_artist_type'], $role['sort_order']]);
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
