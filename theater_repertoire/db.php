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
    $isConcertProgram = isset($data['is_concert_program']) ? (int)$data['is_concert_program'] : 0;
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
            $stmt = $pdo->prepare("UPDATE plays SET short_name = ?, site_title = ?, full_name = ?, wiki_link = ?, hall = ?, special_mark = ?, is_subscription = ?, is_concert_program = ? WHERE id = ?");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription, $isConcertProgram, $data['id']]);
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
            $stmt = $pdo->prepare("INSERT INTO plays (short_name, site_title, full_name, wiki_link, hall, special_mark, is_subscription, is_concert_program) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription, $isConcertProgram]);
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

function saveTemplateElement(
    int $playId,
    string $elementType,
    string $elementValue,
    int $sortOrder,
    ?int $headingLevel = null,
    bool $usePreviousCast = false,
    ?string $specialGroup = null
): void {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO template_elements (
            play_id,
            element_type,
            element_value,
            use_previous_cast,
            special_group,
            sort_order,
            heading_level
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $playId,
        $elementType,
        $elementValue,
        $usePreviousCast ? 1 : 0,
        $specialGroup ?: null,
        $sortOrder,
        $headingLevel,
    ]);
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

function getLastCastForPlay(int $playId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT
            prlc.role_id,
            prlc.artist_id,
            prlc.custom_artist_name,
            prlc.is_first_time,
            a.first_name,
            a.last_name
        FROM play_role_last_cast prlc
        LEFT JOIN artists a ON a.artist_id = prlc.artist_id
        WHERE prlc.play_id = ?
        ORDER BY prlc.role_id, prlc.sort_order_in_role
    ");
    $stmt->execute([$playId]);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $roleId = (int)$row['role_id'];
        $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string)($row['custom_artist_name'] ?? ''));
        }
        if ($name === '') {
            continue;
        }
        if (!empty($row['is_first_time'])) {
            $name .= " (''впервые в роли'')";
        }
        if (!isset($result[$roleId])) {
            $result[$roleId] = [];
        }
        $result[$roleId][] = $name;
    }

    foreach ($result as $roleId => $names) {
        $result[$roleId] = array_values(array_unique($names));
    }

    return $result;
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
          AND COALESCE(te.ignore_in_schedule, 0) = 0
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

function getTemzaEventsForReview(string $monthLabel): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT
            te.*,
            tt.play_id,
            tt.temza_title AS original_temza_title,
            p.site_title AS play_site_title,
            p.full_name AS play_full_name,
            p.is_concert_program,
            er.ticket_code,
            te.responsibles_json,
            te.called_json,
            u.username AS published_by_username
        FROM temza_events te
        LEFT JOIN temza_titles tt ON tt.id = te.temza_title_id
        LEFT JOIN plays p ON p.id = tt.play_id
        LEFT JOIN events_raw er ON er.id = te.matched_event_id
        LEFT JOIN users u ON u.id = te.published_by
        WHERE te.month_label = ?
        ORDER BY
            te.event_date IS NULL,
            te.event_date,
            te.start_time,
            te.id
    ");
    $stmt->execute([$monthLabel]);
    return $stmt->fetchAll();
}

function updateTemzaEventMatch(int $temzaEventId, ?int $eventId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE temza_events SET matched_event_id = ?, updated_at = NOW() WHERE id = ?");
    return $stmt->execute([$eventId, $temzaEventId]);
}

function publishTemzaEvent(int $temzaEventId, int $userId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE temza_events
           SET published_at = NOW(),
               published_by = ?
         WHERE id = ?
    ");
    return $stmt->execute([$userId, $temzaEventId]);
}

function resetTemzaEventPublication(int $temzaEventId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE temza_events SET published_at = NULL, published_by = NULL WHERE id = ?");
    return $stmt->execute([$temzaEventId]);
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

function normalizeTemzaToken(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(['ё', 'Ё'], ['е', 'е'], $value);
    $value = preg_replace('/[«»"\'.!?()\[\]{}]/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function loadRoleMap(PDO $pdo, PDOStatement $stmt, ?int $playId): array
{
    if (!$playId) {
        return [];
    }
    $stmt->execute([$playId]);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalized = normalizeTemzaToken($row['temza_role'] ?? '');
        if ($normalized === '') {
            continue;
        }
        $map[$normalized] = [
            'target_role_id' => $row['target_role_id'] ? (int)$row['target_role_id'] : null,
            'target_group_name' => $row['target_group_name'] ?? null,
            'split_comma' => (int)($row['split_comma'] ?? 1) === 1,
            'ignore_role' => (int)($row['ignore_role'] ?? 0) === 1,
        ];
    }
    return $map;
}

function splitRoleTokens(string $role, bool $allowSplit = true): array
{
    if (!$allowSplit) {
        return [trim($role)];
    }

    $result = [];
    $buffer = '';
    $depth = 0;
    $chars = preg_split('//u', $role, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chars as $ch) {
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')' && $depth > 0) {
            $depth--;
        }

        if ($ch === ',' && $depth === 0) {
            if (trim($buffer) !== '') {
                $result[] = trim($buffer);
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }
    if (trim($buffer) !== '') {
        $result[] = trim($buffer);
    }

    return $result ?: [trim($role)];
}

function resolveCastEntries(
    PDO $pdo,
    PDOStatement $roleMapStmt,
    PDOStatement $deleteCastStmt,
    PDOStatement $insertCastStmt,
    int $temzaEventId,
    ?int $playId,
    array $castRows
): void {
    $deleteCastStmt->execute([$temzaEventId]);

    if (!$castRows) {
        return;
    }

    $roleMap = loadRoleMap($pdo, $roleMapStmt, $playId);

    $seenAssignments = [];

    foreach ($castRows as $entry) {
        $rawRole = trim($entry['role'] ?? '');
        $actor = trim($entry['actor'] ?? '');
        if ($rawRole === '' || $actor === '') {
            continue;
        }

        $notesArray = isset($entry['roleNotes']) && is_array($entry['roleNotes']) ? $entry['roleNotes'] : [];
        $notes = $notesArray ? implode('; ', $notesArray) : null;
        $isDebut = !empty($entry['isDebut']) ? 1 : 0;

        $normalizedFull = normalizeTemzaToken($rawRole);
        $splitAllowed = true;
        if ($normalizedFull !== '' && isset($roleMap[$normalizedFull])) {
            $splitAllowed = $roleMap[$normalizedFull]['split_comma'];
        }

        $tokens = splitRoleTokens($rawRole, $splitAllowed);

        foreach ($tokens as $token) {
            $normalizedToken = normalizeTemzaToken($token);
            $mapping = $normalizedToken !== '' ? ($roleMap[$normalizedToken] ?? null) : null;
            $ignoreRole = $mapping ? !empty($mapping['ignore_role']) : false;
            $mappedRoleId = (!$ignoreRole && $mapping && $mapping['target_role_id']) ? (int)$mapping['target_role_id'] : null;
            $mappedGroup = (!$ignoreRole && $mapping) ? ($mapping['target_group_name'] ?? null) : null;
            $status = $ignoreRole ? 'ignored' : (($mappedRoleId || $mappedGroup) ? 'mapped' : 'pending');

            if ($ignoreRole) {
                $mappedRoleId = null;
                $mappedGroup = null;
            }

            $assignmentKey = null;
            if ($mappedRoleId !== null) {
                $assignmentKey = 'role:' . $mappedRoleId . '|actor:' . $actor;
            } elseif ($mappedGroup !== null) {
                $assignmentKey = 'group:' . $mappedGroup . '|actor:' . $actor;
            }

            if ($assignmentKey && isset($seenAssignments[$assignmentKey])) {
                continue;
            }
            if ($assignmentKey) {
                $seenAssignments[$assignmentKey] = true;
            }

            $insertCastStmt->execute([
                $temzaEventId,
                $playId,
                $token,
                $rawRole,
                $normalizedToken ?: null,
                $actor,
                $notes,
                $isDebut,
                $mappedRoleId,
                $mappedGroup,
                $status,
            ]);
        }
    }
}

function rebuildTemzaEventCast(int $temzaEventId): array
{
    $pdo = getDBConnection();
    $eventStmt = $pdo->prepare("
        SELECT
            te.*,
            tt.play_id,
            tt.temza_title
        FROM temza_events te
        LEFT JOIN temza_titles tt ON tt.id = te.temza_title_id
        WHERE te.id = ?
    ");
    $eventStmt->execute([$temzaEventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        return [
            'success' => false,
            'message' => 'Не найдено событие Temza.',
        ];
    }

    $castRows = [];
    if (!empty($event['cast_json'])) {
        $decoded = json_decode($event['cast_json'], true);
        if (is_array($decoded)) {
            $castRows = $decoded;
        }
    }

    if (!$castRows) {
        return [
            'success' => false,
            'message' => 'Для этого события нет сохранённого состава.',
        ];
    }

    $roleMapStmt = $pdo->prepare('SELECT temza_role, target_role_id, target_group_name, split_comma, ignore_role FROM temza_role_map WHERE play_id = ?');
    $deleteCastStmt = $pdo->prepare('DELETE FROM temza_cast_resolved WHERE temza_event_id = ?');
    $insertCastStmt = $pdo->prepare('
        INSERT INTO temza_cast_resolved (
            temza_event_id,
            play_id,
            temza_role_raw,
            temza_role_source,
            temza_role_normalized,
            temza_actor,
            temza_role_notes,
            is_debut,
            mapped_role_id,
            mapped_group,
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    resolveCastEntries(
        $pdo,
        $roleMapStmt,
        $deleteCastStmt,
        $insertCastStmt,
        $temzaEventId,
        $event['play_id'] ? (int)$event['play_id'] : null,
        $castRows
    );

    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'pending') AS pending,
            SUM(status = 'mapped') AS mapped,
            SUM(status = 'ignored') AS ignored
        FROM temza_cast_resolved
        WHERE temza_event_id = ?
    ");
    $statsStmt->execute([$temzaEventId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'success' => true,
        'event' => [
            'id' => (int)$event['id'],
            'temza_title' => $event['temza_title'] ?? null,
            'event_date' => $event['event_date'] ?? null,
            'start_time' => $event['start_time'] ?? null,
            'month_label' => $event['month_label'] ?? null,
        ],
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'pending' => (int)($stats['pending'] ?? 0),
            'mapped' => (int)($stats['mapped'] ?? 0),
            'ignored' => (int)($stats['ignored'] ?? 0),
        ],
    ];
}

function getTemzaEventsForPlay(int $playId, ?string $monthLabel = null): array
{
    $pdo = getDBConnection();
    $sql = "
        SELECT
            te.id,
            te.event_date,
            te.start_time,
            te.month_label,
            te.preview_title,
            te.date_label,
            te.time_label,
            te.hall,
            te.status,
            te.scraped_at,
            COUNT(tcr.id) AS cast_total,
            SUM(tcr.status = 'pending') AS pending_count,
            SUM(tcr.status = 'mapped') AS mapped_count,
            SUM(tcr.status = 'ignored') AS ignored_count
        FROM temza_events te
        JOIN temza_titles tt ON tt.id = te.temza_title_id
        LEFT JOIN temza_cast_resolved tcr ON tcr.temza_event_id = te.id
        WHERE tt.play_id = ?
    ";
    $params = [$playId];
    if ($monthLabel && $monthLabel !== 'all') {
        $sql .= " AND te.month_label = ? ";
        $params[] = $monthLabel;
    }
    $sql .= "
        GROUP BY te.id
        ORDER BY te.event_date IS NULL, te.event_date, te.start_time, te.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

function getTemzaRoleMapEntries(int $playId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT
            temza_role,
            temza_role_normalized,
            target_role_id,
            target_group_name,
            split_comma,
            ignore_role
        FROM temza_role_map
        WHERE play_id = ?
    ");
    $stmt->execute([$playId]);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalized = $row['temza_role_normalized'] ?? '';
        if ($normalized === '' && !empty($row['temza_role'])) {
            $normalized = normalizeTemzaRole($row['temza_role']);
        }
        if ($normalized === '') {
            continue;
        }
        $map[$normalized] = $row;
    }
    return $map;
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

function getTemzaCastForEvent(int $temzaEventId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT tcr.*, r.sort_order
        FROM temza_cast_resolved tcr
        LEFT JOIN roles r ON r.role_id = tcr.mapped_role_id
        WHERE tcr.temza_event_id = ?
        ORDER BY
            (r.sort_order IS NULL),
            r.sort_order,
            tcr.mapped_group IS NULL,
            tcr.mapped_group,
            tcr.id
    ");
    $stmt->execute([$temzaEventId]);
    return $stmt->fetchAll();
}

function buildTemzaEventCardText(int $temzaEventId, ?int $playId, ?string $ticketCode = null, array $extra = []): array
{
    $result = [
        'text' => null,
        'warnings' => [],
        'has_data' => false,
    ];

    if (!$playId) {
        $result['warnings'][] = 'Событие не сопоставлено со спектаклем.';
        return $result;
    }

    $templateElements = getTemplateElementsForPlay($playId);
    $roles = getRolesByPlay($playId);
    $roleIndex = [];
    $normalizedRoleIndex = [];
    foreach ($roles as $role) {
        $roleId = (int)$role['role_id'];
        $roleName = $role['role_name'] ?? '';
        $roleIndex[$roleId] = $roleName;
        $normalized = normalizeRoleLabelForTemza($roleName);
        if ($normalized !== '') {
            $normalizedRoleIndex[$normalized] = $roleId;
        }
    }

    if (!$templateElements) {
        $result['warnings'][] = 'Для спектакля не задан шаблон карточки.';
    }

    $castRows = getTemzaCastForEvent($temzaEventId);
    if (!$castRows) {
        $result['warnings'][] = 'Нет записей о составе для этого спектакля.';
        return $result;
    }

    $castByRole = [];
    $groups = [];
    $extraGroups = [];
    $pendingEntries = [];

    foreach ($castRows as $row) {
        $status = $row['status'] ?? 'pending';
        if ($status === 'ignored') {
            continue;
        }

        $actor = trim((string)($row['temza_actor'] ?? ''));
        if ($actor === '') {
            continue;
        }

        if ($status === 'pending' || (empty($row['mapped_role_id']) && empty($row['mapped_group']))) {
            $pendingEntries[] = [
                'role' => $row['temza_role_source'] ?? $row['temza_role_raw'] ?? '',
                'actor' => $row['temza_actor'] ?? '',
            ];
        }

        $formattedEntries = splitActorTokens($actor, !empty($row['is_debut']));
        if (!$formattedEntries) {
            continue;
        }

        if (!empty($row['mapped_role_id'])) {
            $roleId = (int)$row['mapped_role_id'];
            foreach ($formattedEntries as $entry) {
                $castByRole[$roleId][] = $entry;
            }
        } elseif (!empty($row['mapped_group'])) {
            $groupName = trim((string)$row['mapped_group']);
            if ($groupName !== '') {
                foreach ($formattedEntries as $entry) {
                    $groups[$groupName][] = $entry;
                }
            }
        }
    }

    $roleSpecialGroupById = [];
    $specialGroupRoleMap = [];
    if ($templateElements) {
        foreach ($templateElements as $element) {
            if (($element['element_type'] ?? '') === 'role' && !empty($element['special_group'])) {
                $roleId = (int)($element['element_value'] ?? 0);
                if ($roleId) {
                    $roleSpecialGroupById[$roleId] = $element['special_group'];
                    $specialGroupRoleMap[$element['special_group']][] = $roleId;
                }
            }
        }
    }

    if ($templateElements) {
        $previousCastCache = null;
        foreach ($templateElements as $element) {
            if (($element['element_type'] ?? '') === 'role' && !empty($element['use_previous_cast'])) {
                $roleId = (int)($element['element_value'] ?? 0);
                if (!$roleId) {
                    continue;
                }
                if (isset($roleSpecialGroupById[$roleId])) {
                    continue;
                }
                if (!empty($castByRole[$roleId])) {
                    continue;
                }
                $roleName = $roleIndex[$roleId] ?? '';
                $hasGroupMatch = false;
                if ($roleName !== '' && $groups) {
                    $normalizedRole = normalizeRoleLabelForTemza($roleName);
                    if ($normalizedRole !== '') {
                        foreach ($groups as $groupName => $_) {
                            if ($normalizedRole === normalizeRoleLabelForTemza($groupName)) {
                                $hasGroupMatch = true;
                                break;
                            }
                        }
                    }
                }
                if ($hasGroupMatch) {
                    continue;
                }
                if ($previousCastCache === null) {
                    $previousCastCache = getLastCastForPlay($playId);
                }
                if (!empty($previousCastCache[$roleId])) {
                    $castByRole[$roleId] = array_merge($previousCastCache[$roleId], $castByRole[$roleId] ?? []);
                }
            }
        }
    }

    foreach ($castByRole as $roleId => $entries) {
        $castByRole[$roleId] = array_values(array_unique($entries));
    }
    foreach ($groups as $groupName => $entries) {
        $groups[$groupName] = array_values(array_unique($entries));
    }

    $responsiblesData = decodeTemzaJsonAssoc($extra['responsibles_json'] ?? null);
    $calledData = decodeTemzaJsonAssoc($extra['called_json'] ?? null);

    $conductorNames = extractNamesByPattern($responsiblesData, '/дириж/iu');
    $concertmasterPattern = '/(концертмейстер|пианист|клавесин|клавесинист|клавир|джаз[-\\s]?бо?-?браун|джаз[-\\s]?браун)/iu';
    $responsibleConcertmasters = extractNamesByPattern($responsiblesData, $concertmasterPattern);
    $calledConcertmasters = extractNamesByPattern($calledData, $concertmasterPattern);
    $manualAssignments = [
        'conductor' => array_values(array_unique($conductorNames)),
        'concertmaster' => array_values(array_unique(array_merge($responsibleConcertmasters, $calledConcertmasters))),
    ];
    if ($specialGroupRoleMap) {
        foreach ($specialGroupRoleMap as $groupKey => $roleIds) {
            if (empty($manualAssignments[$groupKey])) {
                continue;
            }
            foreach ($roleIds as $roleId) {
                if ($roleId && empty($castByRole[$roleId])) {
                    $castByRole[$roleId] = $manualAssignments[$groupKey];
                }
            }
            $manualAssignments[$groupKey] = [];
        }
    }
    if (!empty($manualAssignments['conductor'])) {
        appendManualAssignmentsToCast($castByRole, $groups, $extraGroups, $normalizedRoleIndex, $roleIndex, 'дирижер', $manualAssignments['conductor'], 'Дирижёр', true);
    }
    if (!empty($manualAssignments['concertmaster'])) {
        appendManualAssignmentsToCast($castByRole, $groups, $extraGroups, $normalizedRoleIndex, $roleIndex, 'концертмейстер', $manualAssignments['concertmaster'], 'Концертмейстер', false);
    }

    $lines = [];
    $usedRoleIds = [];
    $hasTicketPlaceholder = false;

    $groupNormalizedIndex = [];
    foreach ($groups as $groupName => $artists) {
        $norm = normalizeRoleLabelForTemza($groupName);
        if ($norm !== '') {
            $groupNormalizedIndex[$norm] = $groupName;
        }
    }

    if ($templateElements) {
        foreach ($templateElements as $element) {
            $type = $element['element_type'];
            $value = $element['element_value'];
            if ($type === 'heading') {
                $level = max(2, (int)($element['heading_level'] ?? 3));
                $headingText = trim(trim((string)$value), '= ');
                if ($headingText !== '') {
                    $lines[] = str_repeat('=', $level) . $headingText . str_repeat('=', $level);
                }
            } elseif ($type === 'image' && $value !== '') {
                $lines[] = $value;
            } elseif ($type === 'newline') {
                $lines[] = '';
            } elseif ($type === 'role') {
                $roleId = (int)$value;
                if (!isset($roleIndex[$roleId])) {
                    continue;
                }
                $roleName = $roleIndex[$roleId];
                if ($roleName === '') {
                    continue;
                }
                $usedRoleIds[$roleId] = true;
                $artists = $castByRole[$roleId] ?? [];
                if (!$artists) {
                    $norm = normalizeRoleLabelForTemza($roleName);
                    if ($norm !== '' && isset($groupNormalizedIndex[$norm])) {
                        $groupKey = $groupNormalizedIndex[$norm];
                        $artists = $groups[$groupKey] ?? [];
                        if ($artists) {
                            unset($groups[$groupKey], $groupNormalizedIndex[$norm]);
                            $lines[] = $roleName . ': ' . implode(', ', $artists);
                            continue;
                        }
                    }
                }
                $line = $roleName . ' — ';
                if ($artists) {
                    $line .= implode(', ', $artists);
                } else {
                    $line .= "''СОСТАВ УТОЧНЯЕТСЯ''";
                }
                $lines[] = $line;
            } elseif ($type === 'ticket_button') {
                $customLink = trim((string)$value);
                $ticketLine = '';
                if ($customLink !== '') {
                    $ticketLine = $customLink;
                } elseif ($ticketCode) {
                    $ticketLine = "'''[http://www.zazerkal.spb.ru/tickets/{$ticketCode}.htm|КУПИТЬ БИЛЕТ]'''";
                }
                if ($ticketLine !== '') {
                    if ($lines && end($lines) !== '') {
                        $lines[] = '';
                    }
                    $lines[] = $ticketLine;
                    $hasTicketPlaceholder = true;
                }
            }
        }
    }

    $remainingRoles = array_diff_key($castByRole, $usedRoleIds);
    if ($remainingRoles) {
        if ($lines && end($lines) !== '') {
            $lines[] = '';
        }
        foreach ($remainingRoles as $roleId => $artists) {
            $roleName = $roleIndex[$roleId] ?? '';
            if ($roleName === '') {
                continue;
            }
            if (empty($artists)) {
                $norm = normalizeRoleLabelForTemza($roleName);
                if ($norm !== '' && isset($groupNormalizedIndex[$norm])) {
                    $groupKey = $groupNormalizedIndex[$norm];
                    $groupArtists = $groups[$groupKey] ?? [];
                    if ($groupArtists) {
                        unset($groups[$groupKey], $groupNormalizedIndex[$norm]);
                        $lines[] = $roleName . ': ' . implode(', ', $groupArtists);
                    }
                }
                continue;
            }
            $lines[] = $roleName . ' — ' . implode(', ', $artists);
        }
    }

    $groupsToRender = [];
    if (!empty($extraGroups)) {
        foreach ($extraGroups as $groupName => $shouldShow) {
            if (empty($shouldShow) || empty($groups[$groupName])) {
                continue;
            }
            $groupsToRender[$groupName] = $groups[$groupName];
        }
    }

    if ($groupsToRender) {
        if ($lines && end($lines) !== '') {
            $lines[] = '';
        }
        foreach ($groupsToRender as $groupName => $artists) {
            if (empty($artists)) {
                continue;
            }
            $label = trim($groupName);
            if ($label === '') {
                continue;
            }
            if (!preg_match('/[:：]$/u', $label)) {
                $label .= ':';
            }
            $lines[] = $label . ' ' . implode(', ', $artists);
        }
    }

    if ($ticketCode && !$hasTicketPlaceholder) {
        if ($lines && end($lines) !== '') {
            $lines[] = '';
        }
        $lines[] = "'''[http://www.zazerkal.spb.ru/tickets/{$ticketCode}.htm|КУПИТЬ БИЛЕТ]'''";
    }

    $text = trim(implode("\n", $lines));
    if ($text !== '') {
        $result['text'] = $text;
        $result['has_data'] = true;
    }
    if ($pendingEntries) {
        foreach ($pendingEntries as $pending) {
            $roleLabel = trim((string)($pending['role'] ?? 'Роль'));
            if ($roleLabel === '') {
                $roleLabel = 'Роль';
            }
            $actorLabel = trim((string)($pending['actor'] ?? ''));
            if ($actorLabel === '') {
                $actorLabel = '—';
            }
            $result['warnings'][] = "{$roleLabel} — {$actorLabel}";
        }
    }

    return $result;
}

function fetchTemzaEventsSnapshot(PDO $pdo, string $monthLabel): array
{
    $stmt = $pdo->prepare("
        SELECT
            te.id,
            te.event_date,
            te.start_time,
            te.hall,
            te.month_label,
            tt.play_id,
            tt.temza_title,
            p.site_title,
            p.full_name,
            tcr.id AS cast_id,
            tcr.temza_role_raw,
            tcr.temza_role_source,
            tcr.temza_role_normalized,
            tcr.temza_role_notes,
            tcr.temza_actor,
            tcr.is_debut,
            tcr.mapped_role_id,
            tcr.mapped_group,
            tcr.status,
            r.role_name,
            r.sort_order
        FROM temza_events te
        LEFT JOIN temza_titles tt ON tt.id = te.temza_title_id
        LEFT JOIN plays p ON p.id = tt.play_id
        LEFT JOIN temza_cast_resolved tcr ON tcr.temza_event_id = te.id
        LEFT JOIN roles r ON r.role_id = tcr.mapped_role_id
        WHERE te.month_label = ?
        ORDER BY te.event_date, te.start_time, te.id, r.sort_order, tcr.id
    ");
    $stmt->execute([$monthLabel]);

    $snapshot = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = buildTemzaEventKey($row);
        if (!isset($snapshot[$key])) {
            $playLabel = formatPlayTitle($row['site_title'] ?? null, $row['full_name'] ?? null);
            if ($playLabel === '') {
                $playLabel = trim((string)($row['temza_title'] ?? ''));
            }
            $snapshot[$key] = [
                'id' => (int)$row['id'],
                'play_id' => $row['play_id'] ? (int)$row['play_id'] : null,
                'play_label' => $playLabel !== '' ? $playLabel : trim((string)($row['temza_title'] ?? '')),
                'event_date' => $row['event_date'],
                'start_time' => $row['start_time'],
                'hall' => $row['hall'],
                'cast' => [],
                'role_labels' => [],
            ];
        }

        if (empty($row['cast_id'])) {
            continue;
        }

        $status = $row['status'] ?? 'pending';
        if ($status === 'ignored') {
            continue;
        }
        $roleKey = resolveTemzaRoleKey($row);
        if ($roleKey === null) {
            continue;
        }

        $label = resolveTemzaRoleLabel($row);
        if ($label !== null) {
            $snapshot[$key]['role_labels'][$roleKey] = $label;
        }

        $presentation = buildTemzaCastPresentation($row);
        if ($presentation === null) {
            continue;
        }

        if (!isset($snapshot[$key]['cast'][$roleKey])) {
            $snapshot[$key]['cast'][$roleKey] = [];
        }
        $snapshot[$key]['cast'][$roleKey][] = $presentation;
    }

    foreach ($snapshot as &$item) {
        foreach ($item['cast'] as $roleKey => &$entries) {
            $entries = array_values(array_unique($entries));
            sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($entries);
    }
    unset($item);

    return $snapshot;
}

function buildTemzaEventKey(array $row): string
{
    $date = $row['event_date'] ?? '';
    $time = $row['start_time'] ?? '';
    $hall = mb_strtolower(trim((string)($row['hall'] ?? '')));
    if ($date === '' || $time === '') {
        return 'id:' . (int)$row['id'];
    }
    return implode('|', [$date, $time, $hall]);
}

function resolveTemzaRoleKey(array $row): ?string
{
    if (!empty($row['mapped_role_id'])) {
        return 'role:' . (int)$row['mapped_role_id'];
    }
    if (!empty($row['mapped_group'])) {
        return 'group:' . mb_strtolower(trim((string)$row['mapped_group']));
    }
    if (!empty($row['temza_role_normalized'])) {
        return 'raw:' . $row['temza_role_normalized'];
    }
    if (!empty($row['temza_role_raw'])) {
        return 'raw:' . normalizeTemzaRole($row['temza_role_raw']);
    }
    return null;
}

function resolveTemzaRoleLabel(array $row): ?string
{
    if (!empty($row['mapped_group'])) {
        return $row['mapped_group'];
    }
    if (!empty($row['role_name'])) {
        return $row['role_name'];
    }
    if (!empty($row['temza_role_source'])) {
        return $row['temza_role_source'];
    }
    if (!empty($row['temza_role_raw'])) {
        return $row['temza_role_raw'];
    }
    return null;
}

function buildTemzaCastPresentation(array $row): ?string
{
    $actor = trim((string)($row['temza_actor'] ?? ''));
    if ($actor === '') {
        return null;
    }
    $parts = [$actor];
    $notes = trim((string)($row['temza_role_notes'] ?? ''));
    if ($notes !== '') {
        $parts[] = "({$notes})";
    }
    if (!empty($row['is_debut'])) {
        $parts[] = "(''впервые в роли'')";
    }
    return implode(' ', $parts);
}

function logTemzaChanges(PDO $pdo, string $monthLabel, array $previousSnapshot): void
{
    if (!$previousSnapshot) {
        return;
    }

    $currentSnapshot = fetchTemzaEventsSnapshot($pdo, $monthLabel);
    if (!$currentSnapshot) {
        return;
    }

    $currentEventIds = array_values(array_map(fn($item) => (int)$item['id'], $currentSnapshot));
    if ($currentEventIds) {
        $placeholders = implode(',', array_fill(0, count($currentEventIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM temza_change_log WHERE temza_event_id IN ($placeholders)");
        $stmt->execute($currentEventIds);
    }

    foreach ($previousSnapshot as $key => $oldData) {
        if (!isset($currentSnapshot[$key])) {
            continue;
        }
        $newData = $currentSnapshot[$key];

        $changes = [];
        if ((int)($oldData['play_id'] ?? 0) !== (int)($newData['play_id'] ?? 0)) {
            $changes[] = [
                'type' => 'play',
                'before' => $oldData['play_label'] ?? null,
                'after' => $newData['play_label'] ?? null,
            ];
        }

        $castDiff = diffTemzaCast($oldData['cast'] ?? [], $newData['cast'] ?? [], $newData['role_labels'] + ($oldData['role_labels'] ?? []));
        if ($castDiff) {
            $changes = array_merge($changes, $castDiff);
        }

        if ($changes) {
            insertTemzaChangeLog($pdo, (int)$newData['id'], $changes);
        }
    }
}

function diffTemzaCast(array $oldCast, array $newCast, array $roleLabels): array
{
    $changes = [];
    $allKeys = array_unique(array_merge(array_keys($oldCast), array_keys($newCast)));
    foreach ($allKeys as $roleKey) {
        $before = $oldCast[$roleKey] ?? [];
        $after = $newCast[$roleKey] ?? [];
        if ($before === $after) {
            continue;
        }
        $changes[] = [
            'type' => 'cast',
            'role' => $roleLabels[$roleKey] ?? $roleKey,
            'before' => $before,
            'after' => $after,
        ];
    }
    return $changes;
}

function insertTemzaChangeLog(PDO $pdo, int $temzaEventId, array $changes): void
{
    $stmt = $pdo->prepare("
        INSERT INTO temza_change_log (temza_event_id, changes_json)
        VALUES (?, ?)
    ");
    $stmt->execute([
        $temzaEventId,
        json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function getTemzaChangeLogForEvents(array $eventIds): array
{
    if (!$eventIds) {
        return [];
    }
    $pdo = getDBConnection();
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = $pdo->prepare("
        SELECT temza_event_id, changes_json, created_at
        FROM temza_change_log
        WHERE temza_event_id IN ($placeholders)
        ORDER BY created_at DESC
    ");
    $stmt->execute($eventIds);

    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eventId = (int)$row['temza_event_id'];
        if (!isset($result[$eventId])) {
            $result[$eventId] = [];
        }
        $result[$eventId][] = [
            'changes' => json_decode($row['changes_json'] ?? '[]', true) ?: [],
            'created_at' => $row['created_at'],
        ];
    }
    return $result;
}

function decodeTemzaJsonAssoc($value): array
{
    if (!$value) {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeRoleLabelForTemza(string $value): string
{
    $clean = preg_replace("/('{2,})/u", '', $value);
    if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $clean, $match)) {
        $clean = $match[1];
    }
    return normalizeTemzaRole($clean);
}

function extractNamesByPattern(array $data, string $pattern): array
{
    $names = [];
    foreach ($data as $label => $value) {
        if (!preg_match($pattern, (string)$label)) {
            continue;
        }
        $names = array_merge($names, flattenManualNameList($value));
    }
    $names = array_values(array_filter(array_unique($names), fn($name) => $name !== ''));
    return $names;
}

function flattenManualNameList($value): array
{
    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items = array_merge($items, flattenManualNameList($item));
            } else {
                $items[] = (string)$item;
            }
        }
    } else {
        $items = preg_split('/[,;\/\n]+/u', (string)$value);
    }
    $result = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $result = array_merge($result, splitManualNameTokens($item));
    }
    return $result;
}

function appendManualAssignmentsToCast(
    array &$castByRole,
    array &$groups,
    array &$extraGroups,
    array $normalizedRoleIndex,
    array $roleIndex,
    string $targetRoleNormalized,
    array $names,
    string $fallbackGroupLabel,
    bool $forceGroupOutput = false
): void {
    if (!$names) {
        return;
    }
    $targetRoleId = $targetRoleNormalized !== '' ? ($normalizedRoleIndex[$targetRoleNormalized] ?? null) : null;

    foreach ($names as $name) {
        if ($targetRoleId) {
            if (!isset($castByRole[$targetRoleId])) {
                $castByRole[$targetRoleId] = [];
            }
            if (!in_array($name, $castByRole[$targetRoleId], true)) {
                $castByRole[$targetRoleId][] = $name;
            }
        } else {
            if (!isset($groups[$fallbackGroupLabel])) {
                $groups[$fallbackGroupLabel] = [];
            }
            if (!in_array($name, $groups[$fallbackGroupLabel], true)) {
                $groups[$fallbackGroupLabel][] = $name;
            }
        }
    }

    if ($targetRoleId && isset($castByRole[$targetRoleId])) {
        $castByRole[$targetRoleId] = array_values(array_unique($castByRole[$targetRoleId]));
    } elseif (!$targetRoleId && isset($groups[$fallbackGroupLabel])) {
        $groups[$fallbackGroupLabel] = array_values(array_unique($groups[$fallbackGroupLabel]));
        if ($forceGroupOutput) {
            $extraGroups[$fallbackGroupLabel] = true;
        }
    }
}

function splitActorTokens(string $actor, bool $isDebut): array
{
    $actor = trim($actor);
    if ($actor === '') {
        return [];
    }

    $normalized = preg_replace('/([а-яёa-z])([А-ЯЁA-Z])/u', '$1,$2', $actor);
    $candidates = preg_split('/\s*,\s*/u', $normalized);
    if (!$candidates || count($candidates) === 0) {
        $candidates = [$actor];
    }

    $entries = [];
    foreach ($candidates as $candidate) {
        $candidate = trim(preg_replace('/\s+/u', ' ', $candidate));
        if ($candidate === '') {
            continue;
        }
        if ($isDebut) {
            $candidate .= " (''впервые в роли'')";
        }
        $entries[] = $candidate;
    }

    return $entries ? array_values(array_unique($entries)) : [];
}

function splitManualNameTokens(string $value): array
{
    if ($value === '') {
        return [];
    }
    $clean = preg_replace('/\((.*?)\)/u', '', $value);
    $clean = trim($clean);
    if ($clean === '' || preg_match('/^ввод\b/iu', $clean) || preg_match('/страхует\b/iu', $clean)) {
        return [];
    }
    $normalized = preg_replace('/([а-яёa-z])([А-ЯЁA-Z])/u', '$1,$2', $clean);
    $parts = preg_split('/\s*,\s*/u', $normalized);
    if (!$parts || count($parts) === 0) {
        $parts = [$clean];
    }
    $result = [];
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part));
        if ($part === '' || preg_match('/^ввод\b/iu', $part) || preg_match('/страхует\b/iu', $part)) {
            continue;
        }
        $result[] = $part;
    }
    return $result;
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
