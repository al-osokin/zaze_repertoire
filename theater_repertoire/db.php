<?php
// Force reload
require_once 'config.php';

// Функции для работы со спектаклями
function getAllPlays() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM plays ORDER BY site_title");
    return $stmt->fetchAll();
}

function getPlayByShortName($shortName) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM plays WHERE short_name = ?");
    $stmt->execute([$shortName]);
    return $stmt->fetch();
}

function getPlayById($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM plays WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function checkPlayExists($shortName, $excludeId = null) {
    $pdo = getDBConnection();
    if ($excludeId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM plays WHERE short_name = ? AND id != ?");
        $stmt->execute([$shortName, $excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM plays WHERE short_name = ?");
        $stmt->execute([$shortName]);
    }
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

function savePlay($data) {
    $pdo = getDBConnection();
    $isSubscription = isset($data['is_subscription']) ? (int)$data['is_subscription'] : 0;
    $specialMark = $data['special_mark'] ?? '';
    $siteTitle = $data['site_title'] ?? null;
    if ($siteTitle !== null && $siteTitle === '') {
        $siteTitle = null;
    }

    if (isset($data['id']) && $data['id']) {
        // Обновление - проверяем, не конфликтует ли новое сокращение с другими записями
        if (checkPlayExists($data['short_name'], $data['id'])) {
            return ['success' => false, 'message' => 'Спектакль с таким сокращением уже существует'];
        }

        try {
            $stmt = $pdo->prepare("UPDATE plays SET short_name = ?, site_title = ?, full_name = ?, wiki_link = ?, hall = ?, special_mark = ?, is_subscription = ? WHERE id = ?");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription, $data['id']]);
            return ['success' => true, 'message' => 'Спектакль обновлён'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Ошибка при обновлении спектакля: ' . $e->getMessage()];
        }
    } else {
        // Создание - проверяем, не существует ли уже спектакль с таким сокращением
        if (checkPlayExists($data['short_name'])) {
            return ['success' => false, 'message' => 'Спектакль с таким сокращением уже существует'];
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO plays (short_name, site_title, full_name, wiki_link, hall, special_mark, is_subscription) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription]);
            return ['success' => true, 'message' => 'Спектакль добавлен'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Ошибка при добавлении спектакля: ' . $e->getMessage()];
        }
    }
}

function deletePlay($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM plays WHERE id = ?");
    $stmt->execute([$id]);
}

// Функции для шаблонов
function getTemplateElementsForPlay(int $playId): array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM template_elements WHERE play_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$playId]);
    return $stmt->fetchAll();
}

function saveTemplateElement(int $playId, string $elementType, string $elementValue, int $sortOrder, ?int $headingLevel = null): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO template_elements (play_id, element_type, element_value, sort_order, heading_level) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$playId, $elementType, $elementValue, $sortOrder, $headingLevel]);
}

function deleteTemplateElementsByPlayId(int $playId): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM template_elements WHERE play_id = ?");
    $stmt->execute([$playId]);
}

function getTemplateByPlayId(int $playId): ?array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM play_templates WHERE play_id = ?");
    $stmt->execute([$playId]);
    return $stmt->fetch();
}

function getRolesByPlay(int $playId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT role_id, role_name FROM roles WHERE play_id = ? ORDER BY sort_order, role_name");
    $stmt->execute([$playId]);
    return $stmt->fetchAll();
}

function getRoleById(int $roleId): ?array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = ?");
    $stmt->execute([$roleId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function updatePlayTemplate(int $playId, string $templateText): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE play_templates SET template_text = ?, updated_at = NOW() WHERE play_id = ?");
    $stmt->execute([$templateText, $playId]);
}

// Функции для истории
function saveRepertoireHistory($monthYear, $sourceText, $resultWiki) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO repertoire_history (month_year, source_text, result_wiki) VALUES (?, ?, ?)");
    $stmt->execute([$monthYear, $sourceText, $resultWiki]);
    return $pdo->lastInsertId();
}

function getRepertoireHistory($limit = 10) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM repertoire_history ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getRepertoireById($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM repertoire_history WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Функции аутентификации
function authenticateUser($username, $password) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        return true;
    }
    return false;
}

function getCurrentUser() {
    return $_SESSION['username'] ?? null;
}

function formatPlayTitle(?string $siteTitle, ?string $fullName): string
{
    $siteTitle = trim((string)($siteTitle ?? ''));
    if ($siteTitle !== '') {
        return $siteTitle;
    }

    $fullName = trim((string)($fullName ?? ''));
    if ($fullName === '') {
        return '';
    }

    if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $fullName, $match)) {
        return $match[1];
    }

    return $fullName;
}

function getVkPageNameByPerformanceId(int $performanceId): ?string
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT vk_page_name FROM events_raw WHERE id = ?");
    $stmt->execute([$performanceId]);
    $result = $stmt->fetchColumn();
    return $result ?: null;
}

function getPerformanceDetailsById(int $performanceId): ?array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT
            er.id AS performance_id,
            er.event_date,
            er.event_time,
            er.play_id,
            p.wiki_link,
            (SELECT COUNT(*)
             FROM events_raw er2
             WHERE er2.play_id = er.play_id AND er2.event_date = er.event_date
            ) AS occurrences_on_day
        FROM
            events_raw er
        JOIN
            plays p ON er.play_id = p.id
        WHERE
            er.id = ?
    ");
    $stmt->execute([$performanceId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

// === Работа с распаршенными событиями афиши ===

function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function clearEventsRawByMonthYear($month, $year) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM events_raw WHERE month = ? AND year = ?");
    $stmt->execute([(int)$month, (int)$year]);
}

function insertRawEvent($eventData) {
    $pdo = getDBConnection();
    $columns = [
        'batch_token', 'event_date', 'event_time', 'title', 'normalized_title',
        'age_category', 'ticket_code', 'ticket_url', 'repertoire_url', 'background_url',
        'play_id', 'play_short_name', 'month', 'year'
    ];

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = sprintf(
        "INSERT INTO events_raw (%s) VALUES (%s)",
        implode(',', $columns),
        $placeholders
    );

    $stmt = $pdo->prepare($sql);
    $values = [];
    foreach ($columns as $column) {
        $values[] = $eventData[$column] ?? null;
    }
    $stmt->execute($values);
    return $pdo->lastInsertId();
}

function getRawEventsByBatch($batchToken) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT er.*, p.full_name AS play_full_name FROM events_raw er LEFT JOIN plays p ON er.play_id = p.id WHERE er.batch_token = ? ORDER BY er.event_date, er.event_time");
    $stmt->execute([$batchToken]);
    return $stmt->fetchAll();
}

function getRawEventsByMonthYear($month, $year) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT er.*, p.full_name AS play_full_name FROM events_raw er LEFT JOIN plays p ON er.play_id = p.id WHERE er.month = ? AND er.year = ? ORDER BY er.event_date, er.event_time");
    $stmt->execute([(int)$month, (int)$year]);
    return $stmt->fetchAll();
}

function updateEventPlayMapping($eventId, $playId = null) {
    $pdo = getDBConnection();
    $playShortName = null;

    if ($playId) {
        $play = getPlayById($playId);
        if (!$play) {
            throw new RuntimeException('Указанный спектакль не найден');
        }
        $playShortName = $play['short_name'];
    }

    $stmt = $pdo->prepare("UPDATE events_raw SET play_id = ?, play_short_name = ? WHERE id = ?");
    $stmt->execute([$playId, $playShortName, $eventId]);
}

function updateVkPageName(int $eventId, string $pageName): void
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE events_raw SET vk_page_name = ? WHERE id = ?");
    $stmt->execute([$pageName, $eventId]);
}

function updateVkPostText($eventId, $text) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE events_raw SET vk_post_text = ? WHERE id = ?");
    $stmt->execute([$text, $eventId]);
}

function buildPerformanceCard(int $performanceId, bool $includePhoto = true): array {
    $pdo = getDBConnection();

    $result = [
        'text' => '',
        'has_artists' => false,
        'play_id' => null,
        'ticket_code' => null,
    ];

    $perfStmt = $pdo->prepare("SELECT play_id, ticket_code FROM events_raw WHERE id = ?");
    $perfStmt->execute([$performanceId]);
    $performance = $perfStmt->fetch();
    if (!$performance) {
        return $result;
    }

    $result['play_id'] = (int)$performance['play_id'];
    $result['ticket_code'] = $performance['ticket_code'] ?? null;

    $rolesStmt = $pdo->prepare("
        SELECT
            r.role_name,
            pra.role_id,
            pra.artist_id,
            pra.custom_artist_name,
            pra.is_first_time,
            a.first_name,
            a.last_name
        FROM performance_roles_artists pra
        JOIN roles r ON pra.role_id = r.role_id
        LEFT JOIN artists a ON pra.artist_id = a.artist_id
        WHERE pra.performance_id = ?
        ORDER BY r.sort_order, pra.sort_order_in_role
    ");
    $rolesStmt->execute([$performanceId]);
    $castData = $rolesStmt->fetchAll();

    $groupedCast = [];
    foreach ($castData as $cast) {
        // Нормализуем имя роли для ключа группировки
        $normalizedRoleName = normalizeRoleName($cast['role_name']);
        if (!isset($groupedCast[$normalizedRoleName])) {
            $groupedCast[$normalizedRoleName] = [
                'name' => $cast['role_name'], // Сохраняем оригинальное имя с разметкой
                'artists' => [],
            ];
        }

        $firstTimeSuffix = !empty($cast['is_first_time']) ? " (''впервые в роли'')" : '';

        if (!empty($cast['artist_id'])) {
            $artistName = trim(($cast['first_name'] ?? '') . ' ' . ($cast['last_name'] ?? ''));
            if ($artistName !== '') {
                $groupedCast[$normalizedRoleName]['artists'][] = $artistName . $firstTimeSuffix;
            }
        } elseif (!empty($cast['custom_artist_name'])) {
            $customName = trim($cast['custom_artist_name']);
            if ($customName !== '' && mb_strtoupper($customName, 'UTF-8') !== 'СОСТАВ УТОЧНЯЕТСЯ') {
                $groupedCast[$normalizedRoleName]['artists'][] = $customName . $firstTimeSuffix;
            }
        }
    }

    $lines = [];
    $elements = getTemplateElementsForPlay($result['play_id']);

    foreach ($elements as $element) {
        if ($element['element_type'] === 'heading') {
            $level = (int)($element['heading_level'] ?? 3); // По умолчанию 3, если не указан
            if ($level < 2) {
                $level = 2;
            }
            $headingText = trim((string)$element['element_value']);
            $headingText = trim($headingText, '= ');
            if ($headingText !== '') {
                $lines[] = str_repeat('=', $level) . $headingText . str_repeat('=', $level);
            }
        } elseif ($element['element_type'] === 'image') {
            $lines[] = $element['element_value'];
        } elseif ($element['element_type'] === 'newline') {
            $lines[] = '';
        } elseif ($element['element_type'] === 'role') {
            $role = getRoleById($element['element_value']);
            if ($role) {
                $normalizedRoleNameFromTemplate = normalizeRoleName($role['role_name']);
                if (isset($groupedCast[$normalizedRoleNameFromTemplate])) {
                    $castEntry = $groupedCast[$normalizedRoleNameFromTemplate];
                    if (!empty($castEntry['artists'])) {
                        $lines[] = $castEntry['name'] . ' — ' . implode(', ', $castEntry['artists']);
                    } else {
                        $lines[] = $castEntry['name'] . ' — ' . "''СОСТАВ УТОЧНЯЕТСЯ''";
                    }
                } else {
                    $lines[] = $role['role_name'] . ' — ' . "''СОСТАВ УТОЧНЯЕТСЯ''";
                }
            }
        }
    }

    $hasArtists = !empty($lines); // Если есть хоть какие-то элементы, считаем, что есть артисты/состав
    if (!$hasArtists) {
        return $result;
    }

    if (!empty($performance['ticket_code'])) {
        $lines[] = '';
        $lines[] = "'''[http://www.zazerkal.spb.ru/tickets/" . $performance['ticket_code'] . ".htm|КУПИТЬ БИЛЕТ]'''";
    }

    $result['text'] = implode("\n", $lines);
    $result['has_artists'] = true;

    return $result;
}

/**
 * Нормализует имя роли, удаляя вики-разметку и лишние пробелы.
 * @param string $roleName
 * @return string
 */
function normalizeRoleName(string $roleName): string
{
    // Удаляем тройные кавычки и лишние пробелы
    $normalized = str_replace(["'''", "''"], '', $roleName);
    return trim(preg_replace('/\s+/u', ' ', $normalized));
}

/**
 * Вспомогательная функция для инициализации performance_roles_artists
 * Заполняет таблицу performance_roles_artists ролями из play_id с пустым составом.
 * @param \PDO $pdo
 * @param int $performanceId
 * @param int $playId
 */
function initializePerformanceRoles(\PDO $pdo, int $performanceId, int $playId): void {
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? ORDER BY sort_order");
    $stmt->execute([$playId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($roles as $roleId) {
        $insertStmt = $pdo->prepare("
            INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role)
            VALUES (?, ?, NULL, '-- СОСТАВ УТОЧНЯЕТСЯ --', 0)
        ");
        $insertStmt->execute([$performanceId, $roleId]);
    }
}

/**
 * Генерирует вики-карточку для VK на основе данных о представлении и его составе.
 * @param int $performanceId ID представления.
 * @return string Сгенерированная вики-разметка.
 */
function generateVkCard(int $performanceId): string {
    $cardData = buildPerformanceCard($performanceId, true);
    return $cardData['text'];
}

// === Temza helpers ===

function getTemzaMonths(): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT DISTINCT month_label FROM temza_events ORDER BY month_label DESC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function getTemzaTitleMappings(bool $onlyUnmapped = false): array
{
    $pdo = getDBConnection();
    $sql = "
        SELECT tt.id,
               tt.temza_title,
               tt.is_subscription,
               tt.play_id,
               tt.suggested_play_id,
               tt.suggestion_confidence,
               tt.is_confirmed,
               p.site_title AS play_site_title,
               p.full_name AS play_full_name,
               p.short_name AS play_short_name,
               sp.site_title AS suggested_site_title,
               sp.full_name AS suggested_full_name,
               sp.short_name AS suggested_short_name
        FROM temza_titles tt
        LEFT JOIN plays p ON p.id = tt.play_id
        LEFT JOIN plays sp ON sp.id = tt.suggested_play_id
    ";
    if ($onlyUnmapped) {
        $sql .= " WHERE tt.play_id IS NULL";
    }
    $sql .= " ORDER BY tt.temza_title";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function updateTemzaTitleMapping(int $titleId, ?int $playId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE temza_titles
           SET play_id = ?,
               is_confirmed = ?,
               updated_at = NOW()
         WHERE id = ?
    ");
    $isConfirmed = $playId ? 1 : 0;
    return $stmt->execute([$playId, $isConfirmed, $titleId]);
}

function applyTemzaSuggestion(int $titleId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE temza_titles
           SET play_id = suggested_play_id,
               is_confirmed = CASE WHEN suggested_play_id IS NULL THEN 0 ELSE 1 END,
               updated_at = NOW()
         WHERE id = ?
           AND suggested_play_id IS NOT NULL
    ");
    $stmt->execute([$titleId]);
    return $stmt->rowCount() > 0;
}

function parseTemzaMonthLabel(string $monthLabel): ?array
{
    if (preg_match('/^(\\d{4})-(\\d{2})$/', $monthLabel, $matches)) {
        return ['year' => (int)$matches[1], 'month' => (int)$matches[2]];
    }
    return null;
}

function getTemzaEventsForMonth(string $monthLabel, bool $onlyUnmatched = false): array
{
    $pdo = getDBConnection();
    $sql = "
        SELECT
            te.*,
            tt.play_id AS mapped_play_id,
            tt.is_subscription,
            p.site_title AS mapped_play_site_title,
            p.full_name AS mapped_play_full_name,
            er.title AS matched_event_title,
            er.event_date AS matched_event_date,
            er.event_time AS matched_event_time,
            er.play_id AS event_play_id,
            ep.site_title AS event_play_site_title,
            ep.full_name AS event_play_full_name
        FROM temza_events te
        LEFT JOIN temza_titles tt ON tt.id = te.temza_title_id
        LEFT JOIN plays p ON p.id = tt.play_id
        LEFT JOIN events_raw er ON er.id = te.matched_event_id
        LEFT JOIN plays ep ON ep.id = er.play_id
        WHERE te.month_label = ?
    ";
    if ($onlyUnmatched) {
        $sql .= " AND te.matched_event_id IS NULL AND te.ignore_in_schedule = 0";
    }
    $sql .= "
        ORDER BY
            te.event_date IS NULL,
            te.event_date,
            te.start_time,
            te.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$monthLabel]);
    return $stmt->fetchAll();
}

function getEventsRawOptionsForMonth(string $monthLabel): array
{
    $parts = parseTemzaMonthLabel($monthLabel);
    if (!$parts) {
        return [];
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT er.id,
               er.event_date,
               er.event_time,
               er.title,
               er.play_id,
               p.site_title,
               p.full_name
        FROM events_raw er
        LEFT JOIN plays p ON p.id = er.play_id
        WHERE er.year = ? AND er.month = ?
        ORDER BY er.event_date, er.event_time, er.id
    ");
    $stmt->execute([$parts['year'], $parts['month']]);
    return $stmt->fetchAll();
}

function updateTemzaEventMatch(int $temzaEventId, ?int $eventId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE temza_events SET matched_event_id = ?, updated_at = NOW() WHERE id = ?");
    return $stmt->execute([$eventId, $temzaEventId]);
}

function getTemzaTitleStatsForMonth(string $monthLabel): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT
            temza_title_id,
            MIN(event_date) AS min_date,
            MIN(start_time) AS min_time,
            COUNT(*) AS events_count
        FROM temza_events
        WHERE month_label = ?
        GROUP BY temza_title_id
    ");
    $stmt->execute([$monthLabel]);
    $stats = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($row['temza_title_id'])) {
            continue;
        }
        $stats[(int)$row['temza_title_id']] = [
            'min_date' => $row['min_date'],
            'min_time' => $row['min_time'],
            'events_count' => (int)$row['events_count'],
        ];
    }
    return $stats;
}

function updateTemzaEventIgnore(int $temzaEventId, bool $ignore): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE temza_events
           SET ignore_in_schedule = ?,
               updated_at = NOW()
         WHERE id = ?
    ");
    return $stmt->execute([$ignore ? 1 : 0, $temzaEventId]);
}

function normalizeTemzaRole(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['ё', 'Ё'], ['е', 'е'], $value);
    $value = preg_replace('/[«»"\'.,!?]/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function getTemzaPlaysList(): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT DISTINCT p.id,
                        p.site_title,
                        p.full_name
        FROM temza_cast_resolved tcr
        JOIN plays p ON p.id = tcr.play_id
        ORDER BY p.site_title
    ");
    return $stmt->fetchAll();
}

function getTemzaMonthsForPlay(int $playId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT DISTINCT te.month_label
        FROM temza_cast_resolved tcr
        JOIN temza_events te ON te.id = tcr.temza_event_id
        WHERE tcr.play_id = ?
          AND tcr.temza_role_normalized IS NOT NULL
          AND tcr.temza_role_normalized <> ''
        ORDER BY te.month_label DESC
    ");
    $stmt->execute([$playId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function getTemzaRoleSummary(int $playId, ?string $monthLabel = null): array
{
    $pdo = getDBConnection();
    $sql = "
        SELECT
            tcr.temza_role_normalized,
            MIN(tcr.temza_role_raw) AS sample_role,
            MIN(tcr.temza_role_source) AS source_role,
            COUNT(*) AS usage_count,
            SUM(CASE WHEN tcr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            MIN(te.event_date) AS first_date,
            MIN(te.start_time) AS first_time,
            GROUP_CONCAT(DISTINCT te.month_label ORDER BY te.month_label DESC SEPARATOR ', ') AS months,
            SUBSTRING(GROUP_CONCAT(DISTINCT tcr.temza_actor ORDER BY tcr.temza_actor SEPARATOR ', '), 1, 120) AS actor_samples,
            MAX(tcr.is_debut) AS has_debut,
            trm.split_comma,
            trm.target_role_id,
            trm.target_group_name,
            trm.temza_role AS mapping_raw_role,
            r.role_name AS mapping_role_name,
            trm.ignore_role,
            CASE WHEN trm.target_role_id IS NOT NULL OR trm.target_group_name IS NOT NULL OR trm.ignore_role = 1 THEN 1 ELSE 0 END AS has_mapping
        FROM temza_cast_resolved tcr
        JOIN temza_events te ON te.id = tcr.temza_event_id
        LEFT JOIN temza_role_map trm
               ON trm.play_id = tcr.play_id
              AND trm.temza_role_normalized = tcr.temza_role_normalized
        LEFT JOIN roles r ON r.role_id = trm.target_role_id
        WHERE tcr.play_id = ?
    ";
    $params = [$playId];
    if ($monthLabel && $monthLabel !== 'all') {
        $sql .= " AND te.month_label = ? ";
        $params[] = $monthLabel;
    }
    $sql .= "
        GROUP BY tcr.temza_role_normalized
        ORDER BY has_mapping ASC, sample_role
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function saveTemzaRoleMapping(
    int $playId,
    string $temzaRoleRaw,
    string $temzaRoleNormalized,
    bool $splitComma,
    ?int $targetRoleId,
    ?string $targetGroupName,
    bool $ignoreRole
): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO temza_role_map (
            play_id,
            temza_role,
            temza_role_normalized,
            target_role_id,
            target_group_name,
            split_comma,
            ignore_role
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            temza_role = VALUES(temza_role),
            target_role_id = VALUES(target_role_id),
            target_group_name = VALUES(target_group_name),
            split_comma = VALUES(split_comma),
            ignore_role = VALUES(ignore_role),
            updated_at = NOW()
    ");
    $stmt->execute([
        $playId,
        $temzaRoleRaw,
        $temzaRoleNormalized,
        $targetRoleId,
        $targetGroupName,
        $splitComma ? 1 : 0,
        $ignoreRole ? 1 : 0,
    ]);
}

function deleteTemzaRoleMapping(int $playId, string $temzaRoleNormalized): void
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM temza_role_map WHERE play_id = ? AND temza_role_normalized = ?");
    $stmt->execute([$playId, $temzaRoleNormalized]);
}

function reapplyTemzaRoleMapping(int $playId, string $temzaRoleNormalized): void
{
    $pdo = getDBConnection();
    $mappingStmt = $pdo->prepare("
        SELECT target_role_id, target_group_name, ignore_role
        FROM temza_role_map
        WHERE play_id = ? AND temza_role_normalized = ?
    ");
    $mappingStmt->execute([$playId, $temzaRoleNormalized]);
    $mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC);

    if ($mapping) {
        if (!empty($mapping['ignore_role'])) {
            $status = 'ignored';
            $mappedRoleId = null;
            $mappedGroup = null;
        } else {
            $status = ($mapping['target_role_id'] || $mapping['target_group_name']) ? 'mapped' : 'pending';
            $mappedRoleId = $mapping['target_role_id'];
            $mappedGroup = $mapping['target_group_name'];
        }
        $updateStmt = $pdo->prepare("
            UPDATE temza_cast_resolved
               SET mapped_role_id = ?,
                   mapped_group = ?,
                   status = ?
             WHERE play_id = ?
               AND temza_role_normalized = ?
        ");
        $updateStmt->execute([
            $mappedRoleId,
            $mappedGroup,
            $status,
            $playId,
            $temzaRoleNormalized,
        ]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE temza_cast_resolved
               SET mapped_role_id = NULL,
                   mapped_group = NULL,
                   status = 'pending'
             WHERE play_id = ?
               AND temza_role_normalized = ?
        ");
        $updateStmt->execute([$playId, $temzaRoleNormalized]);
    }
}

function getTemzaPlayOverview(): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.site_title,
            p.full_name,
            COUNT(DISTINCT CASE WHEN tcr.temza_role_normalized IS NOT NULL AND tcr.temza_role_normalized <> '' THEN tcr.temza_role_normalized END) AS role_total,
            COUNT(DISTINCT CASE WHEN tcr.status = 'pending' THEN tcr.temza_role_normalized END) AS role_pending
        FROM temza_cast_resolved tcr
        JOIN plays p ON p.id = tcr.play_id
        GROUP BY p.id
        ORDER BY p.site_title
    ");
    return $stmt->fetchAll();
}

function suggestTemzaRoleByActor(int $playId, string $roleNormalized): ?array
{
    if ($roleNormalized === '') {
        return null;
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT pra.role_id, r.role_name, COUNT(*) AS matches_count
        FROM temza_cast_resolved tcr
        JOIN temza_events te ON te.id = tcr.temza_event_id
        JOIN events_raw er ON er.id = te.matched_event_id
        JOIN performance_roles_artists pra ON pra.performance_id = er.id
        LEFT JOIN artists a ON a.artist_id = pra.artist_id
        LEFT JOIN roles r ON r.role_id = pra.role_id
        WHERE tcr.play_id = ?
          AND tcr.temza_role_normalized = ?
          AND te.matched_event_id IS NOT NULL
          AND (
                (pra.custom_artist_name IS NOT NULL AND pra.custom_artist_name <> '' AND pra.custom_artist_name = tcr.temza_actor)
             OR (
                    a.artist_id IS NOT NULL
                AND TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''))) = tcr.temza_actor
             )
          )
        GROUP BY pra.role_id, r.role_name
        ORDER BY matches_count DESC
        LIMIT 1
    ");
    $stmt->execute([$playId, $roleNormalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['role_id']) {
        return null;
    }
    return [
        'role_id' => (int)$row['role_id'],
        'role_name' => $row['role_name'] ?? '',
        'reason' => 'По исполнителю',
    ];
}

// === System Settings ===

function getSystemSetting(string $key): ?string {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? $result : null;
}

function saveSystemSetting(string $key, string $value): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function deleteSystemSetting(string $key): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
}

?>
