<?php

/**
 * Backfills vk_page_name for events_raw records of a specific month/year.
 * Usage: php scripts/backfill_vk_page_names.php <month> <year>
 */

if (PHP_SAPI !== 'cli') {
    echo "Run this script via CLI: php scripts/backfill_vk_page_names.php <month> <year>\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

if ($argc < 3) {
    echo "Usage: php scripts/backfill_vk_page_names.php <month> <year>\n";
    exit(1);
}

$month = (int)$argv[1];
$year = (int)$argv[2];

if ($month < 1 || $month > 12 || $year < 2000) {
    echo "Invalid month/year.\n";
    exit(1);
}

$pdo = getDBConnection();

$stmt = $pdo->prepare("
    SELECT
        er.id,
        er.event_date,
        er.event_time,
        er.vk_page_name,
        er.play_id,
        p.wiki_link
    FROM events_raw er
    JOIN plays p ON p.id = er.play_id
    WHERE MONTH(er.event_date) = ? AND YEAR(er.event_date) = ?
    ORDER BY er.event_date, er.event_time, er.id
");
$stmt->execute([$month, $year]);
$events = $stmt->fetchAll();

if (empty($events)) {
    echo "No events found for {$month}.{$year}.\n";
    exit(0);
}

$occurrences = [];
foreach ($events as $event) {
    $date = $event['event_date'] ?? '';
    $playId = (int)($event['play_id'] ?? 0);
    if ($date === '' || $playId === 0) {
        continue;
    }

    $key = $date . '_' . $playId;
    if (!isset($occurrences[$key])) {
        $occurrences[$key] = 0;
    }
    $occurrences[$key]++;
}

$updated = 0;
$skipped = 0;

foreach ($events as $event) {
    if (!empty($event['vk_page_name'])) {
        continue;
    }

    $pageName = generatePageNameForEvent($event, $occurrences);
    if (!$pageName) {
        $skipped++;
        echo sprintf(
            "[skip] event #%d (%s) could not get page name\n",
            $event['id'],
            $event['event_date'] ?? 'unknown date'
        );
        continue;
    }

    updateVkPageName((int)$event['id'], $pageName);
    $updated++;
    echo sprintf("[ok] event #%d -> %s\n", $event['id'], $pageName);
}

echo "Done. Updated: {$updated}, skipped: {$skipped}.\n";

function generatePageNameForEvent(array $event, array $occurrences): ?string
{
    $wikiLink = trim((string)($event['wiki_link'] ?? ''));
    if ($wikiLink === '') {
        return null;
    }

    $date = $event['event_date'] ?? '';
    if ($date === '') {
        return null;
    }

    try {
        $dateObj = new DateTimeImmutable($date);
    } catch (Throwable $e) {
        return null;
    }

    $playId = (int)($event['play_id'] ?? 0);
    if ($playId === 0) {
        return null;
    }

    $key = $date . '_' . $playId;
    $hasMultiple = ($occurrences[$key] ?? 0) > 1;

    $dateForLink = $dateObj->format('d.m.y');
    $pageName = $wikiLink . '_' . $dateForLink;

    if ($hasMultiple) {
        $pageName .= '_' . formatTimeForLink($event['event_time'] ?? '');
    }

    return rtrim($pageName, '_');
}

function formatTimeForLink(string $time): string
{
    if ($time === '') {
        return '';
    }

    $parts = explode(':', $time);
    $hours = sprintf('%02d', (int)($parts[0] ?? 0));
    $minutes = sprintf('%02d', (int)($parts[1] ?? 0));

    if ($minutes === '00') {
        return (string)((int)$hours);
    }

    return $hours . $minutes;
}
