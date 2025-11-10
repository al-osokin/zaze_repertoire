<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/import_temza_json.php /path/to/temza-2025-11.json [more.json]\n");
    exit(1);
}

$pdo = getDBConnection();

$deleteByMonthStmt = $pdo->prepare('DELETE FROM temza_events WHERE month_label = ?');
$insertTitleStmt = $pdo->prepare('INSERT INTO temza_titles (temza_title, is_subscription) VALUES (?, ?)');
$selectTitleStmt = $pdo->prepare('SELECT id, is_subscription, is_confirmed FROM temza_titles WHERE temza_title = ?');
$updateTitleStmt = $pdo->prepare('UPDATE temza_titles SET is_subscription = ?, updated_at = NOW() WHERE id = ?');
$updateSuggestionStmt = $pdo->prepare('
    UPDATE temza_titles
       SET suggested_play_id = ?,
           suggestion_confidence = ?,
           updated_at = NOW()
     WHERE id = ?
       AND is_confirmed = 0
');
$insertEventStmt = $pdo->prepare('
    INSERT INTO temza_events (
        temza_title_id,
        temza_title,
        preview_title,
        preview_details,
        date_label,
        time_label,
        month_label,
        event_date,
        start_time,
        hall,
        chips_json,
        cast_json,
        responsibles_json,
        department_tasks_json,
        called_json,
        notes_json,
        raw_html,
        raw_json,
        scraped_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
');

function encodeJson($value): ?string
{
    if ($value === null) {
        return null;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function parseEventDate(?string $monthLabel, ?string $dateLabel): ?string
{
    if (!$monthLabel || !preg_match('/^(\\d{4})-(\\d{2})$/', $monthLabel, $monthMatch)) {
        return null;
    }
    if (!$dateLabel || !preg_match('/(\\d{1,2})/u', $dateLabel, $dayMatch)) {
        return null;
    }
    $year = (int)$monthMatch[1];
    $month = (int)$monthMatch[2];
    $day = (int)$dayMatch[1];
    if ($day < 1 || $day > 31) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function parseStartTime(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $value = trim($value);
    if (!preg_match('/^(\\d{1,2}):(\\d{2})$/', $value, $match)) {
        return null;
    }
    $hours = (int)$match[1];
    $minutes = (int)$match[2];
    if ($hours > 23 || $minutes > 59) {
        return null;
    }
    return sprintf('%02d:%02d:00', $hours, $minutes);
}

function parseScrapedAt(?string $value): string
{
    if ($value) {
        try {
            $dt = new DateTime($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // ignore
        }
    }
    return date('Y-m-d H:i:s');
}

function normalizeTitle(string $value): string
{
    $value = mb_strtolower($value);
    $value = str_replace(['ё'], ['е'], $value);
    $value = preg_replace('/[«»"\'.,!?()\\[\\]{}]/u', ' ', $value);
    $value = preg_replace('/\\s+/u', ' ', $value);
    return trim($value);
}

function stripWikiMarkup(string $value): string
{
    if (preg_match('/\\[\\[(?:[^|\\]]+\\|)?([^\\]]+)\\]\\]/u', $value, $match)) {
        return $match[1];
    }
    return $value;
}

function cleanTemzaTitle(string $title): string
{
    $title = preg_replace('/^(сп\\.|абонемент)(\\s*\\(\\d+\\))?\\s*/iu', '', $title);
    return trim($title);
}

function buildPlayIndex(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, short_name, site_title, full_name FROM plays");
    $index = [];
    while ($play = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $candidates = [];
        if (!empty($play['short_name'])) {
            $candidates[] = $play['short_name'];
        }
        if (!empty($play['site_title'])) {
            $candidates[] = $play['site_title'];
        }
        if (!empty($play['full_name'])) {
            $candidates[] = stripWikiMarkup($play['full_name']);
        }
        foreach ($candidates as $candidate) {
            $normalized = normalizeTitle($candidate);
            if ($normalized === '') {
                continue;
            }
            $index[$normalized] = [
                'play_id' => (int)$play['id'],
                'confidence' => 100,
            ];
        }
    }
    return $index;
}

function getOrCreateTitle(PDO $pdo, PDOStatement $selectStmt, PDOStatement $insertStmt, PDOStatement $updateStmt, string $title, bool $isSubscription): array
{
    $selectStmt->execute([$title]);
    $existing = $selectStmt->fetch();
    if ($existing) {
        if ((int)$existing['is_subscription'] !== (int)$isSubscription) {
            $updateStmt->execute([$isSubscription ? 1 : 0, $existing['id']]);
        }
        return [
            'id' => (int)$existing['id'],
            'is_confirmed' => ((int)($existing['is_confirmed'] ?? 0)) === 1,
        ];
    }

    $insertStmt->execute([$title, $isSubscription ? 1 : 0]);
    return [
        'id' => (int)$pdo->lastInsertId(),
        'is_confirmed' => false,
    ];
}

function autoMatchEvents(PDO $pdo, string $monthLabel): void
{
    $sql = "
        UPDATE temza_events te
        JOIN (
            SELECT er.id,
                   er.event_date,
                   er.event_time,
                   p.hall
            FROM events_raw er
            JOIN plays p ON p.id = er.play_id
            JOIN (
                SELECT er.event_date, er.event_time, p.hall
                FROM events_raw er
                JOIN plays p ON p.id = er.play_id
                GROUP BY er.event_date, er.event_time, p.hall
                HAVING COUNT(*) = 1
            ) uniq
              ON uniq.event_date = er.event_date
             AND uniq.event_time = er.event_time
             AND ((uniq.hall IS NULL AND p.hall IS NULL) OR uniq.hall = p.hall)
        ) matched
          ON matched.event_date = te.event_date
         AND matched.event_time = te.start_time
         AND (
              te.hall IS NULL
              OR te.hall = ''
              OR te.hall = matched.hall
          )
        SET te.matched_event_id = matched.id
        WHERE te.month_label = ?
          AND te.event_date IS NOT NULL
          AND te.start_time IS NOT NULL
    ";

$stmt = $pdo->prepare($sql);
    $stmt->execute([$monthLabel]);
}

$playIndex = buildPlayIndex($pdo);
$files = array_slice($argv, 1);

foreach ($files as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, "File not found: {$filePath}\n");
        continue;
    }

    $json = file_get_contents($filePath);
    $payload = json_decode($json, true);
    if (!$payload) {
        fwrite(STDERR, "Unable to parse JSON: {$filePath}\n");
        continue;
    }

    $monthLabel = $payload['month'] ?? null;
    if (!$monthLabel) {
        fwrite(STDERR, "Missing month in payload: {$filePath}\n");
        continue;
    }

    $spectacles = $payload['spectacles'] ?? [];
    $scrapedAt = parseScrapedAt($payload['scrapedAt'] ?? null);

    $pdo->beginTransaction();
    $deleteByMonthStmt->execute([$monthLabel]);

    $inserted = 0;
    foreach ($spectacles as $spectacle) {
        $title = $spectacle['title'] ?? $spectacle['previewTitle'] ?? null;
        if (!$title) {
            continue;
        }

        $isSubscription = preg_match('/^АБОНЕМЕНТ/i', $title) === 1;
        $titleRecord = getOrCreateTitle($pdo, $selectTitleStmt, $insertTitleStmt, $updateTitleStmt, $title, $isSubscription);
        $temzaTitleId = $titleRecord['id'];

        if (!$titleRecord['is_confirmed']) {
            $normalized = normalizeTitle(cleanTemzaTitle($title));
            if ($normalized !== '' && isset($playIndex[$normalized])) {
                $suggestion = $playIndex[$normalized];
                $updateSuggestionStmt->execute([
                    $suggestion['play_id'],
                    $suggestion['confidence'],
                    $temzaTitleId,
                ]);
            }
        }

        $dateLabel = $spectacle['date'] ?? null;
        $timeLabel = $spectacle['time'] ?? null;
        $eventDate = parseEventDate($monthLabel, $dateLabel);
        $startTime = parseStartTime($spectacle['startTime'] ?? null);

        $chipsJson = encodeJson($spectacle['chips'] ?? []);
        $castJson = encodeJson($spectacle['cast'] ?? []);
        $responsiblesJson = encodeJson($spectacle['responsibles'] ?? new stdClass());
        $departmentTasksJson = encodeJson($spectacle['departmentTasks'] ?? []);
        $calledJson = encodeJson($spectacle['called'] ?? new stdClass());
        $notesJson = encodeJson($spectacle['notes'] ?? []);
        $rawHtml = $spectacle['rawHtml'] ?? null;
        $rawJson = encodeJson($spectacle);

        $insertEventStmt->execute([
            $temzaTitleId,
            $title,
            $spectacle['previewTitle'] ?? null,
            $spectacle['previewDetails'] ?? null,
            $dateLabel,
            $timeLabel,
            $monthLabel,
            $eventDate,
            $startTime,
            $spectacle['hall'] ?? null,
            $chipsJson,
            $castJson,
            $responsiblesJson,
            $departmentTasksJson,
            $calledJson,
            $notesJson,
            $rawHtml,
            $rawJson,
            $scrapedAt,
        ]);

        $inserted++;
    }

    autoMatchEvents($pdo, $monthLabel);
    $pdo->commit();

    echo sprintf(
        "Imported %d entries from %s into month %s\n",
        $inserted,
        basename($filePath),
        $monthLabel
    );
}
