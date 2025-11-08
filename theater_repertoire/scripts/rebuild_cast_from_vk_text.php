<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$pdo = getDBConnection();

$problemStmt = $pdo->query(<<<SQL
    SELECT er.id AS performance_id, er.play_id
    FROM events_raw er
    JOIN plays p ON p.id = er.play_id
    LEFT JOIN performance_roles_artists pra ON pra.performance_id = er.id
    GROUP BY er.id
    HAVING COUNT(pra.role_id) <> (
        SELECT COUNT(*) FROM roles r WHERE r.play_id = er.play_id
    )
SQL);
$problemPerformances = $problemStmt->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($problemPerformances)) {
    echo "Нет спектаклей для переразбора.\n";
    exit;
}

$artistMap = buildArtistMap($pdo);

$selectEvent = $pdo->prepare('SELECT id, play_id, vk_post_text FROM events_raw WHERE id = ?');
$selectRoles = $pdo->prepare('SELECT role_id, role_name, sort_order FROM roles WHERE play_id = ? ORDER BY sort_order');
$deleteAssignments = $pdo->prepare('DELETE FROM performance_roles_artists WHERE performance_id = ?');
$insertAssignment = $pdo->prepare('INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time) VALUES (?, ?, ?, ?, ?, ?)');

$processed = 0;
$skipped = 0;

foreach ($problemPerformances as $performanceId) {
    $selectEvent->execute([$performanceId]);
    $event = $selectEvent->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        continue;
    }

    $text = trim((string)$event['vk_post_text']);
    if ($text === '') {
        echo "[skip] performance {$performanceId}: пустой vk_post_text\n";
        $skipped++;
        continue;
    }

    $selectRoles->execute([$event['play_id']]);
    $roles = $selectRoles->fetchAll(PDO::FETCH_ASSOC);
    if (empty($roles)) {
        echo "[skip] performance {$performanceId}: роли не найдены\n";
        $skipped++;
        continue;
    }

    $roleMap = [];
    foreach ($roles as $role) {
        $normalized = normalizeRoleLabel($role['role_name']);
        $roleMap[$normalized] = $role['role_id'];
    }

    $assignments = parseVkText($text, $roleMap, $artistMap);

    if (empty($assignments)) {
        echo "[skip] performance {$performanceId}: не удалось разобрать текст\n";
        $skipped++;
        continue;
    }

    $pdo->beginTransaction();
    $deleteAssignments->execute([$performanceId]);
    foreach ($assignments as $row) {
        $insertAssignment->execute([
            $performanceId,
            $row['role_id'],
            $row['artist_id'],
            $row['custom_artist_name'],
            $row['sort_order_in_role'],
            $row['is_first_time'],
        ]);
    }
    $pdo->commit();

    echo "[ok] performance {$performanceId}: сохранено " . count($assignments) . " записей\n";
    $processed++;
}

echo "Готово. Исправлено: {$processed}, пропущено: {$skipped}.\n";

function buildArtistMap(PDO $pdo): array {
    $stmt = $pdo->query('SELECT artist_id, first_name, last_name FROM artists');
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $artist) {
        $variants = [];
        $full = trim($artist['first_name'] . ' ' . $artist['last_name']);
        $variants[] = $full;
        $variants[] = $artist['last_name'] . ' ' . $artist['first_name'];
        foreach ($variants as $name) {
            $map[normalizePersonName($name)] = (int)$artist['artist_id'];
        }
    }
    return $map;
}

function parseVkText(string $text, array $roleMap, array $artistMap): array {
    $lines = preg_split('/\r?\n/', $text);
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '—')) {
            continue;
        }
        [$roleRaw, $artistsRaw] = array_map('trim', explode('—', $line, 2));
        $normalizedRole = normalizeRoleLabel($roleRaw);
        if (!isset($roleMap[$normalizedRole])) {
            continue;
        }
        $roleId = $roleMap[$normalizedRole];
        $artistsRaw = preg_replace('/\(.*?\)/u', '', $artistsRaw);
        $artistChunks = preg_split('/[,;]+/u', $artistsRaw);
        $sort = 0;
        foreach ($artistChunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || stripos($chunk, 'СОСТАВ УТОЧНЯЕТСЯ') !== false) {
                continue;
            }
            $isFirst = stripos($chunk, 'впервые в роли') !== false ? 1 : 0;
            $nameClean = normalizePersonName($chunk);
            $artistId = $artistMap[$nameClean] ?? null;
            $custom = null;
            if (!$artistId) {
                $custom = stripQuotes($chunk);
            }
            $result[] = [
                'role_id' => $roleId,
                'artist_id' => $artistId,
                'custom_artist_name' => $custom,
                'sort_order_in_role' => $sort,
                'is_first_time' => $isFirst,
            ];
            $sort++;
        }
    }
    return $result;
}

function normalizeRoleLabel(string $label): string {
    $label = stripQuotes($label);
    $label = trim($label);
    return mb_strtoupper($label, 'UTF-8');
}

function normalizePersonName(string $name): string {
    $name = stripQuotes($name);
    $name = preg_replace('/\(.*?\)/u', '', $name);
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return mb_strtoupper($name, 'UTF-8');
}

function stripQuotes(string $value): string {
    $value = str_replace(["'''", "''"], '', $value);
    $value = trim($value, "' \t\n");
    return $value;
}
