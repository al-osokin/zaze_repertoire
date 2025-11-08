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
        $elementsData = [];
        $lines = preg_split("/\r\n|\r|\n/", $templateText);
        $currentSortOrder = 0;

        // Удаляем все существующие элементы для данного play_id
        $stmt = $this->pdo->prepare("DELETE FROM template_elements WHERE play_id = ?");
        $stmt->execute([$playId]);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                $this->saveTemplateElement($playId, 'newline', '', $currentSortOrder++);
                continue;
            }

            // Обработка заголовков типа ==В ролях:==
            if (str_starts_with($line, '==') && !str_starts_with($line, '===')) {
                $this->saveTemplateElement($playId, 'heading', trim(str_replace('==', '', $line)), $currentSortOrder++, 2); // Уровень 2
                continue;
            }

            // Игнорируем заглушку "СОСТАВ УТОЧНЯЕТСЯ"
            if (str_contains($line, 'СОСТАВ УТОЧНЯЕТСЯ')) {
                continue;
            }

            // Игнорируем ссылку на билеты
            if (str_contains($line, '|КУПИТЬ БИЛЕТ]')) {
                continue;
            }

            // Обработка картинок [[photo-ID_PHOTO|SIZE|TEXT]]
            if (str_starts_with($line, '[[photo-') && str_ends_with($line, ']]')) {
                $this->saveTemplateElement($playId, 'image', $line, $currentSortOrder++);
                continue;
            }

            // Обработка заголовков разделов (например, ===Пастораль «Искренность пастушки»===)
            if (preg_match('/^={3}(.+?)={3}/u', $line, $matches)) {
                $this->saveTemplateElement($playId, 'heading', trim($matches[1]), $currentSortOrder++, 3); // Уровень 3
                continue;
            }

            // Попытка разобрать строки с ролями и артистами
            $rawRolePart = null;
            $artistNamesStr = null;

            if (str_contains($line, '—')) {
                $parts = preg_split('/\s+—\s+/u', $line, 2);
                if (!$parts || count($parts) < 2) {
                    continue;
                }
                [$rawRolePart, $artistNamesStr] = array_map('trim', $parts);
            } elseif (preg_match('/^(\'\'\'[^\'\'\']+\'\'\')\s*:\s*(.+)$/u', $line, $matches)) {
                $rawRolePart = trim($matches[1]);
                $artistNamesStr = trim($matches[2]);
            } elseif (preg_match('/^(\'\'\'[^\'\'\']+\'\'\')\s*$/u', $line, $matches)) {
                $roleName = trim($matches[1]);
                $expectedArtistType = $this->detectExpectedArtistType($roleName);

                $roleId = $this->findOrCreateRole($playId, $roleName, $expectedArtistType, $currentSortOrder);
                $this->saveTemplateElement($playId, 'role', $roleId, $currentSortOrder++);
                continue;
            } else {
                continue;
            }

            if ($artistNamesStr === null || $artistNamesStr === '') {
                continue;
            }

            $roleName = preg_replace('/\s+/u', ' ', trim($rawRolePart)); // Сохраняем полную вики-разметку
            if ($roleName === '') {
                continue;
            }

            $expectedArtistType = $this->detectExpectedArtistType($roleName);

            $roleId = $this->findOrCreateRole($playId, $roleName, $expectedArtistType, $currentSortOrder);
            $this->saveTemplateElement($playId, 'role', $roleId, $currentSortOrder++);
        }
        return $elementsData; // Возвращаем пустой массив, так как элементы сохраняются напрямую
    }

    private function saveTemplateElement(int $playId, string $elementType, string $elementValue, int $sortOrder, ?int $headingLevel = null): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO template_elements (play_id, element_type, element_value, sort_order, heading_level) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$playId, $elementType, $elementValue, $sortOrder, $headingLevel]);
    }

    private function findOrCreateRole(int $playId, string $roleName, string $expectedArtistType, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? AND role_name = ?");
        $stmt->execute([$playId, $roleName]);
        $roleId = $stmt->fetchColumn();

        if ($roleId) {
            $stmt = $this->pdo->prepare("UPDATE roles SET expected_artist_type = ?, sort_order = ?, updated_at = NOW() WHERE role_id = ?");
            $stmt->execute([$expectedArtistType, $sortOrder, $roleId]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO roles (play_id, role_name, expected_artist_type, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$playId, $roleName, $expectedArtistType, $sortOrder]);
            $roleId = $this->pdo->lastInsertId();
        }
        return $roleId;
    }

    private function saveRoleArtistHistory(int $roleId, int $artistId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO role_artist_history (role_id, artist_id, assignment_count, last_assigned_date)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE assignment_count = assignment_count + 1, last_assigned_date = NOW();
        ");
        $stmt->execute([$roleId, $artistId]);
    }

    private function detectExpectedArtistType(string $roleName): string
    {
        // Используем глобальную функцию normalizeRoleName
        $normalizedRoleName = \normalizeRoleName($roleName);

        if (mb_stripos($normalizedRoleName, 'Дирижёр') !== false || mb_stripos($normalizedRoleName, 'Дирижер') !== false) {
            return 'conductor';
        }

        if (
            mb_stripos($normalizedRoleName, 'Клавесин') !== false ||
            mb_stripos($normalizedRoleName, 'Концертмейстер') !== false ||
            mb_stripos($normalizedRoleName, 'Пианист') !== false
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

}
