<?php
require_once 'config.php';
requireAuth();
require_once 'includes/navigation.php';
handleLogoutRequest();

$allPlays = getAllPlays();
$playOptions = [];
foreach ($allPlays as $play) {
    $playOptions[(int)$play['id']] = formatPlayTitle($play['site_title'] ?? null, $play['full_name'] ?? null);
}

function temzaBuildQuery(string $month, string $titleFilter, string $eventFilter): string
{
    return http_build_query([
        'month' => $month,
        'title_filter' => $titleFilter,
        'event_filter' => $eventFilter,
    ]);
}

function temzaNormalizeMonthLabel(int $year, int $month): string
{
    return sprintf('%04d-%02d', $year, max(1, min(12, $month)));
}

function temzaParseMonthsInput(string $input): array
{
    $tokens = preg_split('/[\s,]+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        throw new InvalidArgumentException('Укажите хотя бы один месяц (например, "current" или "2025-11").');
    }

    $now = new DateTimeImmutable('first day of this month');
    $months = [];

    foreach ($tokens as $token) {
        $normalized = mb_strtolower(trim($token));
        if ($normalized === 'current') {
            $months[] = temzaNormalizeMonthLabel((int)$now->format('Y'), (int)$now->format('n'));
            continue;
        }

        if ($normalized === 'next') {
            $next = $now->modify('+1 month');
            $months[] = temzaNormalizeMonthLabel((int)$next->format('Y'), (int)$next->format('n'));
            continue;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $normalized, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            if ($month < 1 || $month > 12) {
                throw new InvalidArgumentException("Неверный месяц: {$token}. Используйте формат YYYY-MM.");
            }
            $months[] = temzaNormalizeMonthLabel($year, $month);
            continue;
        }

        throw new InvalidArgumentException("Не удалось распознать месяц «{$token}». Используйте current, next или формат YYYY-MM.");
    }

    return array_values(array_unique($months));
}

function temzaRunScraper(array $monthLabels): array
{
    $scraperDir = TEMZA_SCRAPER_DIR ? (is_dir(TEMZA_SCRAPER_DIR) ? TEMZA_SCRAPER_DIR : realpath(TEMZA_SCRAPER_DIR)) : null;
    if (!$scraperDir || !is_dir($scraperDir)) {
        throw new RuntimeException('Каталог temza_scraper не найден.');
    }

    $monthsArg = implode(',', $monthLabels);
    $command = sprintf('npm run dev -- --months=%s', escapeshellarg($monthsArg));

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cwd = getcwd();
    chdir($scraperDir);
    $process = proc_open($command, $descriptorSpec, $pipes);
    chdir($cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить процесс скрапинга.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $log = trim($stdout . PHP_EOL . $stderr);

    return [
        'exit_code' => $exitCode,
        'log' => $log,
    ];
}

function temzaRunImporter(array $monthLabels): array
{
    $theaterDir = THEATER_APP_DIR ? (is_dir(THEATER_APP_DIR) ? THEATER_APP_DIR : realpath(THEATER_APP_DIR)) : null;
    $scraperOutputDir = TEMZA_OUTPUT_DIR ? (is_dir(TEMZA_OUTPUT_DIR) ? TEMZA_OUTPUT_DIR : realpath(TEMZA_OUTPUT_DIR)) : null;
    if (!$theaterDir || !$scraperOutputDir) {
        throw new RuntimeException('Не найдены директории проекта или выгрузки Temza.');
    }

    $scriptPath = $theaterDir . '/scripts/import_temza_json.php';
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Скрипт импорта не найден.');
    }

    $filePaths = [];
    foreach ($monthLabels as $label) {
        $file = $scraperOutputDir . '/temza-' . $label . '.json';
        if (!is_file($file)) {
            throw new RuntimeException("Файл выгрузки за {$label} не найден ({$file}).");
        }
        $filePaths[] = $file;
    }

    $phpBinary = defined('PHP_CLI_BINARY') ? PHP_CLI_BINARY : (PHP_BINARY ?: '/usr/bin/php');
    $cmdParts = array_merge([$phpBinary, $scriptPath], $filePaths);
    $command = implode(' ', array_map('escapeshellarg', $cmdParts));

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cwd = getcwd();
    chdir($theaterDir);
    $process = proc_open($command, $descriptorSpec, $pipes);
    chdir($cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить импорт Temza.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $log = trim($stdout . PHP_EOL . $stderr);

    return [
        'exit_code' => $exitCode,
        'log' => $log,
    ];
}

function temzaCollectScrapeSummaries(array $monthLabels): array
{
    $outputDir = TEMZA_OUTPUT_DIR ? (is_dir(TEMZA_OUTPUT_DIR) ? TEMZA_OUTPUT_DIR : realpath(TEMZA_OUTPUT_DIR)) : null;
    $summaries = [];

    foreach ($monthLabels as $label) {
        $filePath = $outputDir ? $outputDir . '/temza-' . $label . '.json' : null;
        $info = [
            'month' => $label,
            'exists' => $filePath && is_file($filePath),
            'path' => $filePath,
            'total' => null,
            'scraped_at' => null,
            'size' => null,
        ];

        if ($info['exists']) {
            $payload = json_decode(file_get_contents($filePath), true);
            if (is_array($payload)) {
                $info['total'] = $payload['total'] ?? (is_array($payload['spectacles'] ?? null) ? count($payload['spectacles']) : null);
                $info['scraped_at'] = $payload['scrapedAt'] ?? null;
            }
            $info['size'] = filesize($filePath);
        }

        $summaries[] = $info;
    }

    return $summaries;
}

$months = getTemzaMonths();
$defaultMonth = $months[0] ?? date('Y-m');
$storedMonth = $_SESSION['temza_selected_month'] ?? null;
$requestedMonth = $_GET['month'] ?? ($_POST['current_month'] ?? null);
$currentMonth = $requestedMonth ?: ($storedMonth ?: $defaultMonth);
if ($months && !in_array($currentMonth, $months, true)) {
    $currentMonth = $defaultMonth;
}
$_SESSION['temza_selected_month'] = $currentMonth;

$titleFilter = $_GET['title_filter'] ?? 'unmapped';
$eventFilter = $_GET['event_filter'] ?? 'unmatched';

$redirectQuery = temzaBuildQuery($currentMonth, $titleFilter, $eventFilter);
$flashMessage = $_SESSION['temza_flash']['message'] ?? null;
$flashType = $_SESSION['temza_flash']['type'] ?? 'success';
unset($_SESSION['temza_flash']);
$scrapeSummary = $_SESSION['temza_scrape_summary'] ?? null;
unset($_SESSION['temza_scrape_summary']);
$scrapeMonthsPreset = $_SESSION['temza_last_months'] ?? 'current,next';
$importSummary = $_SESSION['temza_import_summary'] ?? null;
unset($_SESSION['temza_import_summary']);
$importMonthsPreset = $_SESSION['temza_last_import_months'] ?? $currentMonth;
$projectRoot = PROJECT_ROOT;
$changeLogs = [];

$errors = [];

function temzaRedirect(string $query = ''): void
{
    $location = 'temza.php' . ($query ? '?' . $query : '');
    header("Location: {$location}");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'] ?? '';
        $temzaEventId = (int)($_POST['temza_event_id'] ?? 0);
        $userId = $_SESSION['user_id'] ?? null;

        if ($temzaEventId <= 0) {
            throw new RuntimeException('Некорректный идентификатор события Temza.');
        }
        if (!$userId) {
            throw new RuntimeException('Необходимо авторизоваться.');
        }

        if ($action === 'publish_event') {
            if (!publishTemzaEvent($temzaEventId, (int)$userId)) {
                throw new RuntimeException('Не удалось отметить событие как опубликованное.');
            }
        } elseif ($action === 'reset_event_publish') {
            if (!resetTemzaEventPublication($temzaEventId)) {
                throw new RuntimeException('Не удалось снять отметку публикации.');
            }
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }

        $state = temzaBuildPublishStatePayload($temzaEventId);
        echo json_encode([
            'success' => true,
            'published' => $state['published'],
            'badge' => $state['badge'],
            'published_at' => $state['published_at'],
            'published_by' => $state['published_by'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = $_POST['redirect'] ?? $redirectQuery;
    if ($action === 'update_title_mapping') {
        $titleId = (int)($_POST['title_id'] ?? 0);
        $playIdInput = $_POST['play_id'] ?? '';
        $playId = $playIdInput === '' ? null : (int)$playIdInput;

        if ($titleId <= 0) {
            $errors[] = 'Некорректный идентификатор названия.';
        } elseif ($playId !== null && !array_key_exists($playId, $playOptions)) {
            $errors[] = 'Выберите существующий спектакль.';
        } else {
            updateTemzaTitleMapping($titleId, $playId);
            $_SESSION['temza_flash'] = [
                'type' => 'success',
                'message' => 'Сопоставление названия обновлено.',
            ];
            temzaRedirect($redirect);
        }
    } elseif ($action === 'update_event_match') {
        $temzaEventId = (int)($_POST['temza_event_id'] ?? 0);
        $eventIdInput = $_POST['event_id'] ?? '';
        $eventId = $eventIdInput === '' ? null : (int)$eventIdInput;

        if ($temzaEventId <= 0) {
            $errors[] = 'Некорректный идентификатор события Temza.';
        } else {
            if ($eventId !== null) {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM events_raw WHERE id = ?");
                $stmt->execute([$eventId]);
                if (!$stmt->fetchColumn()) {
                    $errors[] = 'Выбранное событие афиши не найдено.';
                }
            }

            if (!$errors) {
                updateTemzaEventMatch($temzaEventId, $eventId);
                $_SESSION['temza_flash'] = [
                    'type' => 'success',
                    'message' => $eventId ? 'Событие сопоставлено с афишей.' : 'Сопоставление с афишей снято.',
                ];
                temzaRedirect($redirect);
            }
        }
    } elseif ($action === 'update_event_ignore') {
        $temzaEventId = (int)($_POST['temza_event_id'] ?? 0);
        $ignore = isset($_POST['ignore']) ? 1 : 0;
        if ($temzaEventId <= 0) {
            $errors[] = 'Некорректный идентификатор события.';
        } else {
            updateTemzaEventIgnore($temzaEventId, (bool)$ignore);
            $_SESSION['temza_flash'] = [
                'type' => 'success',
                'message' => $ignore ? 'Событие исключено из сопоставления.' : 'Событие снова участвует в сопоставлении.',
            ];
            temzaRedirect($redirect);
        }
    } elseif ($action === 'apply_suggestion') {
        $titleId = (int)($_POST['title_id'] ?? 0);
        if ($titleId <= 0) {
            $errors[] = 'Некорректный идентификатор названия.';
        } else {
            if (applyTemzaSuggestion($titleId)) {
                $_SESSION['temza_flash'] = [
                    'type' => 'success',
                    'message' => 'Гипотеза подтверждена.',
                ];
            } else {
                $_SESSION['temza_flash'] = [
                    'type' => 'error',
                    'message' => 'Нечего подтверждать: гипотеза отсутствует.',
                ];
            }
            temzaRedirect($redirect);
        }
    } elseif ($action === 'run_scraper') {
        $monthsRaw = trim($_POST['scrape_months'] ?? '');
        if ($monthsRaw !== '') {
            $scrapeMonthsPreset = $monthsRaw;
        }

        try {
            $monthLabels = temzaParseMonthsInput($monthsRaw ?: $scrapeMonthsPreset);
            $scrapeResult = temzaRunScraper($monthLabels);
            $summaries = temzaCollectScrapeSummaries($monthLabels);

            $_SESSION['temza_scrape_summary'] = [
                'meta' => [
                    'exit_code' => $scrapeResult['exit_code'],
                ],
                'summaries' => $summaries,
                'log' => $scrapeResult['log'],
            ];
            $_SESSION['temza_last_months'] = implode(', ', $monthLabels);

            if ($scrapeResult['exit_code'] === 0) {
                $_SESSION['temza_flash'] = [
                    'type' => 'success',
                    'message' => 'Скрапер Temza завершился успешно.',
                ];
            } else {
                $_SESSION['temza_flash'] = [
                    'type' => 'error',
                    'message' => 'Скрапер завершился с ошибкой. Проверьте лог ниже.',
                ];
            }

            temzaRedirect($redirect);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'run_importer') {
        $monthsRaw = trim($_POST['import_months'] ?? '');
        if ($monthsRaw !== '') {
            $importMonthsPreset = $monthsRaw;
        }

        try {
            $monthLabels = temzaParseMonthsInput($monthsRaw ?: $importMonthsPreset);
            $importResult = temzaRunImporter($monthLabels);
            $_SESSION['temza_import_summary'] = [
                'meta' => [
                    'exit_code' => $importResult['exit_code'],
                    'months' => $monthLabels,
                ],
                'log' => $importResult['log'],
            ];
            $_SESSION['temza_last_import_months'] = implode(', ', $monthLabels);

            $_SESSION['temza_flash'] = [
                'type' => $importResult['exit_code'] === 0 ? 'success' : 'error',
                'message' => $importResult['exit_code'] === 0
                    ? 'Импорт JSON Temza завершён успешно.'
                    : 'Импорт завершился с ошибкой. Проверьте лог ниже.',
            ];
            temzaRedirect($redirect);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($action === 'publish_event' || $action === 'reset_event_publish') {
        $temzaEventId = (int)($_POST['temza_event_id'] ?? 0);
        if ($temzaEventId <= 0) {
            $errors[] = 'Некорректный идентификатор события Temza.';
        } else {
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                $errors[] = 'Сессия пользователя недействительна. Авторизуйтесь заново.';
            } else {
                if ($action === 'publish_event') {
                    if (publishTemzaEvent($temzaEventId, (int)$userId)) {
                        $_SESSION['temza_flash'] = [
                            'type' => 'success',
                            'message' => 'Карточка помечена как отправленная в VK.',
                        ];
                    }
                } else {
                    if (resetTemzaEventPublication($temzaEventId)) {
                        $_SESSION['temza_flash'] = [
                            'type' => 'info',
                            'message' => 'Отметка об отправке снята.',
                        ];
                    }
                }
                temzaRedirect($redirect);
            }
        }
    }
}

$allTitleMappings = getTemzaTitleMappings(false);
$unmappedTitleCount = count(array_filter($allTitleMappings, fn($row) => !$row['play_id']));
$titleRows = $titleFilter === 'unmapped'
    ? array_values(array_filter($allTitleMappings, fn($row) => !$row['play_id']))
    : array_values($allTitleMappings);

$allEvents = $currentMonth ? getTemzaEventsForMonth($currentMonth, false) : [];
$unmatchedEventCount = count(array_filter($allEvents, fn($row) => !$row['matched_event_id']));
$ignoredEventCount = count(array_filter($allEvents, fn($row) => !empty($row['ignore_in_schedule'])));
$unmatchedEventCount = count(array_filter($allEvents, fn($row) => !$row['matched_event_id'] && empty($row['ignore_in_schedule'])));

if ($eventFilter === 'unmatched') {
    $eventRows = array_values(array_filter($allEvents, fn($row) => !$row['matched_event_id'] && empty($row['ignore_in_schedule'])));
} elseif ($eventFilter === 'ignored') {
    $eventRows = array_values(array_filter($allEvents, fn($row) => !empty($row['ignore_in_schedule'])));
} else {
    $eventRows = array_values($allEvents);
}

$eventOptions = $currentMonth ? getEventsRawOptionsForMonth($currentMonth) : [];
$titleStats = $currentMonth ? getTemzaTitleStatsForMonth($currentMonth) : [];
$reviewEvents = [];
if ($currentMonth) {
    $reviewEvents = getTemzaEventsForReview($currentMonth);
    foreach ($reviewEvents as &$reviewEvent) {
        $reviewEvent['card'] = buildTemzaEventCardText(
            (int)$reviewEvent['id'],
            isset($reviewEvent['play_id']) ? (int)$reviewEvent['play_id'] : null,
            $reviewEvent['ticket_code'] ?? null,
            [
                'responsibles_json' => $reviewEvent['responsibles_json'] ?? null,
                'called_json' => $reviewEvent['called_json'] ?? null,
            ]
        );
    }
    unset($reviewEvent);
    $changeLogs = getTemzaChangeLogForEvents(array_map(fn($row) => (int)$row['id'], $reviewEvents));
}

function temzaFormatPlay(?string $siteTitle, ?string $fullName): string
{
    $value = formatPlayTitle($siteTitle, $fullName);
    return $value !== '' ? $value : '—';
}

function temzaFormatDate(?string $date): string
{
    return $date ? date('d.m.Y', strtotime($date)) : '—';
}

function temzaFormatTime(?string $time): string
{
    if (!$time) {
        return '—';
    }
    return substr($time, 0, 5);
}

function temzaFormatPublishedBadge(?string $publishedAt, ?string $username): ?string
{
    if (!$publishedAt) {
        return null;
    }
    $date = date('d.m.Y', strtotime($publishedAt));
    $suffix = $username ? ' · ' . $username : '';
    return sprintf('Отправлено %s%s', $date, $suffix);
}

function temzaFormatEventOption(array $option): string
{
    $date = temzaFormatDate($option['event_date'] ?? null);
    $time = temzaFormatTime($option['event_time'] ?? null);
    $title = trim($option['title'] ?? '');
    $playName = temzaFormatPlay($option['site_title'] ?? null, $option['full_name'] ?? null);
    if ($playName !== '—' && stripos($title, $playName) === false) {
        $title .= $title ? " ({$playName})" : $playName;
    }
    return trim("{$date} {$time} — {$title}");
}

function temzaCleanTitle(?string $title): string
{
    if ($title === null) {
        return '';
    }
    $clean = preg_replace('/^(сп\.|абонемент)(\s*\(\d+\))?\s*/iu', '', $title);
    $clean = trim($clean);
    return $clean !== '' ? $clean : trim($title);
}

function temzaBuildPublishStatePayload(int $temzaEventId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT te.id, te.published_at, te.published_by, u.username
        FROM temza_events te
        LEFT JOIN users u ON u.id = te.published_by
        WHERE te.id = ?
    ");
    $stmt->execute([$temzaEventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'published' => false,
            'badge' => null,
            'published_at' => null,
            'published_by' => null,
        ];
    }

    $badge = temzaFormatPublishedBadge($row['published_at'] ?? null, $row['username'] ?? null);
    return [
        'published' => !empty($row['published_at']),
        'badge' => $badge,
        'published_at' => $row['published_at'],
        'published_by' => $row['username'] ?? null,
    ];
}

usort($eventRows, function (array $a, array $b): int {
    $dateA = $a['event_date'] ?? '';
    $dateB = $b['event_date'] ?? '';
    if ($dateA === $dateB) {
        $timeA = $a['start_time'] ?? '';
        $timeB = $b['start_time'] ?? '';
        if ($timeA === $timeB) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        }
        return $timeA <=> $timeB;
    }
    if ($dateA === '') {
        return 1;
    }
    if ($dateB === '') {
        return -1;
    }
    return $dateA <=> $dateB;
});
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Темза — сопоставление спектаклей</title>
    <link rel="stylesheet" href="css/main.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="app/globals.css">
    <style>
        .temza-tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            background: #eef2ff;
            color: #4338ca;
            margin-left: 6px;
        }
        .temza-table td {
            vertical-align: top;
        }
        .temza-original {
            display: block;
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-ok {
            background: #dcfce7;
            color: #166534;
        }
        .status-missing {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-muted {
            background: #e5e7eb;
            color: #374151;
        }
        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .temza-forms select {
            min-width: 220px;
        }
        .temza-scrape-summary table {
            width: 100%;
            margin-top: 8px;
        }
        .temza-row-cancelled {
            background: #fef2f2;
        }
        .temza-scrape-summary th,
        .temza-scrape-summary td {
            padding: 6px 8px;
        }
        .temza-log {
            margin-top: 12px;
        }
        .temza-log pre {
            background: #111827;
            color: #e5e7eb;
            padding: 12px;
            border-radius: 6px;
            max-height: 260px;
            overflow: auto;
            font-size: 0.85rem;
        }
        .temza-review-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            background: #fff;
        }
        .temza-review-card.is-approved {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }
        .temza-card-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .temza-card-actions form {
            display: inline-block;
            margin-left: 8px;
        }
        .temza-review-card pre {
            background: #111827;
            color: #e5e7eb;
            padding: 12px;
            border-radius: 6px;
            overflow: auto;
            max-height: 320px;
            font-size: 0.9rem;
        }
        .temza-warning-list {
            background: #fef2f2;
            color: #991b1b;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .temza-warning-list ul {
            margin: 4px 0 0 18px;
        }
        .temza-muted {
            color: #6b7280;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<div class="container">
    <?php renderMainNavigation('temza'); ?>
    <div class="header">
        <div>
            <h1>Темза — сопоставление спектаклей</h1>
            <p class="header-subtitle">
                Названия и события из Temza, подготовка к наполнению карточек.
            </p>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert <?php echo $flashType === 'error' ? 'alert-error' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>Сбор данных из Темзы</h2>
        <form method="post" class="temza-filter-form" style="margin-bottom: 16px;">
            <input type="hidden" name="action" value="run_scraper">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
            <label class="form-control">
                <span class="label">Какие месяцы скрапить</span>
                <input type="text"
                       name="scrape_months"
                       value="<?php echo htmlspecialchars($scrapeMonthsPreset); ?>"
                       placeholder="current,next или 2025-11,2025-10"
                       class="input">
                <small class="form-hint">Допустимы current, next и список в формате YYYY-MM (через запятую/пробел).</small>
            </label>
            <div style="margin-top: 12px;">
                <button type="submit" class="btn-primary">Запустить скрапер Temza</button>
            </div>
        </form>

        <?php if ($scrapeSummary): ?>
            <div class="temza-scrape-summary">
                <h3>Результаты последнего запуска</h3>
                <table class="temza-table">
                    <thead>
                        <tr>
                            <th>Месяц</th>
                            <th>Записей</th>
                            <th>Сохранено</th>
                            <th>Файл</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scrapeSummary['summaries'] as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['month']); ?></td>
                                <td><?php echo $row['exists'] ? htmlspecialchars((string)($row['total'] ?? '—')) : '—'; ?></td>
                                <td><?php echo $row['scraped_at'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($row['scraped_at']))) : '—'; ?></td>
                                <td>
                                    <?php if ($row['exists'] && $row['path']): ?>
                                        <?php
                                            $displayPath = $row['path'];
                                            if ($projectRoot && strpos($displayPath, $projectRoot) === 0) {
                                                $displayPath = ltrim(substr($displayPath, strlen($projectRoot)), '/');
                                            }
                                        ?>
                                        <code><?php echo htmlspecialchars($displayPath); ?></code>
                                    <?php else: ?>
                                        <span class="status-badge status-missing">файл не найден</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!empty($scrapeSummary['log'])): ?>
                    <details class="temza-log">
                        <summary>Показать лог (exit code: <?php echo (int)($scrapeSummary['meta']['exit_code'] ?? -1); ?>)</summary>
                        <pre><?php echo htmlspecialchars($scrapeSummary['log']); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Импорт JSON → база данных</h2>
        <form method="post" class="temza-filter-form" style="margin-bottom: 16px;">
            <input type="hidden" name="action" value="run_importer">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
            <label class="form-control">
                <span class="label">Какие месяцы импортировать</span>
                <input type="text"
                       name="import_months"
                       value="<?php echo htmlspecialchars($importMonthsPreset); ?>"
                       placeholder="<?php echo htmlspecialchars($currentMonth); ?>"
                       class="input">
                <small class="form-hint">Используются файлы temza-YYYY-MM.json из каталога temza_scraper/output. Допустимы current, next и список месяцев.</small>
            </label>
            <div style="margin-top: 12px;">
                <button type="submit" class="btn-secondary">Импортировать в базу</button>
            </div>
        </form>

        <?php if ($importSummary): ?>
            <div class="temza-scrape-summary">
                <h3>Результат импорта</h3>
                <p class="temza-muted">
                    Месяцы: <?php echo htmlspecialchars(implode(', ', $importSummary['meta']['months'] ?? [])); ?>.
                    Код завершения: <?php echo (int)($importSummary['meta']['exit_code'] ?? -1); ?>.
                </p>
                <?php if (!empty($importSummary['log'])): ?>
                    <details class="temza-log">
                        <summary>Показать лог импорта</summary>
                        <pre><?php echo htmlspecialchars($importSummary['log']); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <form method="get" class="temza-filter-form">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="form-control">
                    <span class="label">Месяц выгрузки</span>
                    <select name="month" class="select">
                        <?php foreach ($months as $monthLabel): ?>
                            <option value="<?php echo htmlspecialchars($monthLabel); ?>"
                                <?php echo $monthLabel === $currentMonth ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($monthLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-control">
                    <span class="label">Названия</span>
                    <select name="title_filter" class="select">
                        <option value="all" <?php echo $titleFilter === 'all' ? 'selected' : ''; ?>>Все</option>
                        <option value="unmapped" <?php echo $titleFilter === 'unmapped' ? 'selected' : ''; ?>>Только без сопоставления (<?php echo $unmappedTitleCount; ?>)</option>
                    </select>
                </label>
                <label class="form-control">
                    <span class="label">События</span>
                    <select name="event_filter" class="select">
                        <option value="all" <?php echo $eventFilter === 'all' ? 'selected' : ''; ?>>Все</option>
                        <option value="unmatched" <?php echo $eventFilter === 'unmatched' ? 'selected' : ''; ?>>Нуждаются в сопоставлении (<?php echo $unmatchedEventCount; ?>)</option>
                        <option value="ignored" <?php echo $eventFilter === 'ignored' ? 'selected' : ''; ?>>Помечены как вне афиши (<?php echo $ignoredEventCount; ?>)</option>
                    </select>
                </label>
            </div>
            <div style="margin-top: 16px;">
                <button type="submit" class="btn-primary">Применить фильтры</button>
            </div>
        </form>
    </div>

    <div class="section">
        <h2>Сопоставление названий Temza ↔ спектакли</h2>
        <?php if (!$allTitleMappings): ?>
            <p>Данных из Temza пока нет. Загрузите JSON и повторите попытку.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="temza-table">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Название в Temza</th>
                            <th style="width: 110px;">Дата</th>
                            <th style="width: 90px;">Время</th>
                            <th>Привязанный спектакль</th>
                            <th style="width: 220px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($titleRows as $row): 
                            $originalTitle = trim($row['temza_title'] ?? '');
                            $displayTitle = temzaCleanTitle($originalTitle);
                            $stats = $titleStats[$row['id']] ?? null;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($displayTitle ?: $originalTitle); ?></strong>
                                    <?php if ($displayTitle !== $originalTitle && $originalTitle !== ''): ?>
                                        <span class="temza-original"><?php echo htmlspecialchars($originalTitle); ?></span>
                                    <?php endif; ?>
                                    <?php if ($row['is_subscription']): ?>
                                        <span class="temza-tag">АБОНЕМЕНТ</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        if ($stats && $stats['min_date']) {
                                            echo htmlspecialchars(temzaFormatDate($stats['min_date']));
                                            if ($stats['events_count'] > 1) {
                                                echo '<div class="text-muted text-small">+' . ((int)$stats['events_count'] - 1) . '</div>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        if ($stats && $stats['min_time']) {
                                            echo htmlspecialchars(temzaFormatTime($stats['min_time']));
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        if ($row['play_id']) {
                                            echo '<div><strong>' . htmlspecialchars(temzaFormatPlay($row['play_site_title'] ?? null, $row['play_full_name'] ?? null)) . '</strong></div>';
                                            if (!$row['is_confirmed']) {
                                                echo '<div class="text-muted text-small">Не подтверждено</div>';
                                            }
                                        } elseif ($row['suggested_play_id']) {
                                            $suggestedName = temzaFormatPlay($row['suggested_site_title'] ?? null, $row['suggested_full_name'] ?? null);
                                            echo '<div class="text-muted">Предполагается: ' . htmlspecialchars($suggestedName) . '</div>';
                                            if (!empty($row['suggestion_confidence'])) {
                                                echo '<div class="text-small text-muted">Уверенность: ' . (int)$row['suggestion_confidence'] . '%</div>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">Не сопоставлено</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <form method="post" class="temza-forms">
                                        <input type="hidden" name="action" value="update_title_mapping">
                                        <input type="hidden" name="title_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                        <select name="play_id">
                                            <option value="">— не выбрано —</option>
                                            <?php foreach ($playOptions as $playId => $playName): ?>
                                                <option value="<?php echo (int)$playId; ?>"
                                                    <?php echo ((int)$row['play_id'] === (int)$playId) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($playName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary" style="margin-left: 8px;">Сохранить</button>
                                    </form>
                                    <?php if (!$row['play_id'] && $row['suggested_play_id']): ?>
                                        <form method="post" class="temza-forms" style="margin-top: 8px;">
                                            <input type="hidden" name="action" value="apply_suggestion">
                                            <input type="hidden" name="title_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                            <button type="submit" class="btn-secondary">Принять гипотезу</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Сопоставление событий Temza ↔ афиша</h2>
        <?php if (!$allEvents): ?>
            <p>Для выбранного месяца нет загруженных событий.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="temza-table">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Дата</th>
                            <th style="width: 80px;">Время</th>
                            <th>Событие Temza</th>
                            <th>Привязка афиши</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($eventRows as $event): 
                        $eventOriginalTitle = trim($event['temza_title'] ?? '');
                        $eventDisplayTitle = temzaCleanTitle($eventOriginalTitle);
                    ?>
                        <tr class="<?php echo ($event['status'] ?? '') === 'cancelled' ? 'temza-row-cancelled' : ''; ?>">
                            <td><?php echo htmlspecialchars(temzaFormatDate($event['event_date'] ?? null)); ?></td>
                            <td><?php echo htmlspecialchars(temzaFormatTime($event['start_time'] ?? null)); ?></td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($eventDisplayTitle ?: $eventOriginalTitle ?: '—'); ?></strong>
                                    <?php if ($eventDisplayTitle !== $eventOriginalTitle && $eventOriginalTitle !== ''): ?>
                                        <span class="temza-original"><?php echo htmlspecialchars($eventOriginalTitle); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($event['hall'])): ?>
                                        <span class="temza-tag"><?php echo htmlspecialchars($event['hall']); ?></span>
                                    <?php endif; ?>
                                    <?php if (($event['status'] ?? '') === 'cancelled'): ?>
                                        <span class="status-badge status-danger" style="margin-left: 6px;">Отмена</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($event['preview_details'])): ?>
                                    <div class="text-muted"><?php echo htmlspecialchars($event['preview_details']); ?></div>
                                <?php endif; ?>
                                <?php
                                    $mappedPlayName = temzaFormatPlay($event['mapped_play_site_title'] ?? null, $event['mapped_play_full_name'] ?? null);
                                    if ($event['mapped_play_id']): ?>
                                        <div class="text-small">Привязано к спектаклю: <?php echo htmlspecialchars($mappedPlayName); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($event['ignore_in_schedule'])): ?>
                                    <div class="status-badge status-muted">Игнорируется</div>
                                    <?php if ($event['matched_event_id']): ?>
                                        <div class="text-small" style="margin-top: 6px;">
                                            <?php echo htmlspecialchars(temzaFormatDate($event['matched_event_date'] ?? null)); ?>
                                            <?php echo htmlspecialchars(temzaFormatTime($event['matched_event_time'] ?? null)); ?>
                                            —
                                            <?php echo htmlspecialchars($event['matched_event_title'] ?? 'Событие афиши'); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($event['matched_event_id']): ?>
                                    <div class="status-badge status-ok">Сопоставлено</div>
                                    <div class="text-small" style="margin-top: 6px;">
                                        <?php echo htmlspecialchars(temzaFormatDate($event['matched_event_date'] ?? null)); ?>
                                        <?php echo htmlspecialchars(temzaFormatTime($event['matched_event_time'] ?? null)); ?>
                                        —
                                        <?php echo htmlspecialchars($event['matched_event_title'] ?? 'Событие афиши'); ?>
                                    </div>
                                    <form method="post" class="temza-forms" style="margin-top: 6px;">
                                        <input type="hidden" name="action" value="update_event_match">
                                        <input type="hidden" name="temza_event_id" value="<?php echo (int)$event['id']; ?>">
                                        <input type="hidden" name="event_id" value="">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                        <button type="submit" class="btn-secondary">Снять сопоставление</button>
                                    </form>
                                <?php else: ?>
                                    <div class="status-badge status-missing">Нет совпадения</div>
                                    <form method="post" class="temza-forms" style="margin-top: 6px;">
                                        <input type="hidden" name="action" value="update_event_match">
                                        <input type="hidden" name="temza_event_id" value="<?php echo (int)$event['id']; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                        <select name="event_id">
                                            <option value="">— выбрать событие афиши —</option>
                                            <?php foreach ($eventOptions as $option): ?>
                                                <option value="<?php echo (int)$option['id']; ?>">
                                                    <?php echo htmlspecialchars(temzaFormatEventOption($option)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-primary" style="margin-left: 8px;">Сохранить</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="temza-forms" style="margin-top: 8px;">
                                    <input type="hidden" name="action" value="update_event_ignore">
                                    <input type="hidden" name="temza_event_id" value="<?php echo (int)$event['id']; ?>">
                                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectQuery); ?>">
                                    <input type="hidden" name="ignore" value="0">
                                    <label style="display: inline-flex; gap: 6px; align-items: center;">
                                    <input type="checkbox" name="ignore" value="1" <?php echo !empty($event['ignore_in_schedule']) ? 'checked' : ''; ?>>
                                        <span>Игнорировать (вне афиши)</span>
                                    </label>
                                    <button type="submit" class="btn-secondary" style="margin-left: 8px;">Применить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($reviewEvents): ?>
        <div class="section">
            <h2>Предпросмотр карточек (<?php echo htmlspecialchars($currentMonth); ?>)</h2>
            <p class="temza-muted" style="margin-bottom: 16px;">
                Проверьте автоматическую сборку состава перед тем, как использовать данные в карточках.
            </p>
            <?php foreach ($reviewEvents as $preview): ?>
                <?php
                    $eventDate = temzaFormatDate($preview['event_date'] ?? null);
                    $eventTime = temzaFormatTime($preview['start_time'] ?? null);
                    $playLabel = temzaFormatPlay($preview['play_site_title'] ?? null, $preview['play_full_name'] ?? null);
                    $titleDisplay = $playLabel !== '—'
                        ? $playLabel
                        : temzaCleanTitle($preview['temza_title'] ?? $preview['original_temza_title'] ?? '');
                    $cardPlayTitle = $titleDisplay ?: 'Спектакль';
                    $cardData = $preview['card'] ?? ['text' => null, 'warnings' => [], 'has_data' => false];
                    $publishedBadge = temzaFormatPublishedBadge($preview['published_at'] ?? null, $preview['published_by_username'] ?? null);
                    $eventChanges = $changeLogs[(int)$preview['id']] ?? [];
                    $isCancelled = ($preview['status'] ?? '') === 'cancelled';
                    $performanceId = $preview['matched_event_id'] ?? null;
                    $publishDisabled = $isCancelled || !empty($cardData['warnings']) || !$performanceId;
                    $publishTitle = '';
                    if ($publishDisabled) {
                        $publishTitle = $isCancelled
                            ? 'Нельзя отправить: отмена спектакля'
                            : (!empty($cardData['warnings'])
                                ? 'Нельзя отправить: есть несопоставленные роли'
                                : (!$performanceId ? 'Событие не сопоставлено с афишей' : ''));
                    }
                ?>
                <div class="temza-review-card <?php echo $preview['published_at'] ? 'is-approved' : ''; ?>"
                     data-temza-event-id="<?php echo (int)$preview['id']; ?>"
                     data-performance-id="<?php echo $performanceId ? (int)$performanceId : ''; ?>"
                     data-play-title="<?php echo htmlspecialchars($cardPlayTitle, ENT_QUOTES, 'UTF-8'); ?>"
                     data-publish-disabled="<?php echo $publishDisabled ? '1' : '0'; ?>"
                     data-publish-disabled-title="<?php echo htmlspecialchars($publishTitle, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="temza-card-meta">
                        <div>
                            <strong>
                                <?php echo htmlspecialchars($eventDate); ?>
                                <?php if ($eventTime !== '—'): ?>
                                    <?php echo htmlspecialchars($eventTime); ?>
                                <?php endif; ?>
                                — <?php echo htmlspecialchars($titleDisplay ?: 'Без названия'); ?>
                            </strong>
                            <?php if (!empty($preview['hall'])): ?>
                                <span class="temza-tag" style="margin-left: 8px;"><?php echo htmlspecialchars($preview['hall']); ?></span>
                            <?php endif; ?>
                            <?php if ($isCancelled): ?>
                                <div class="status-badge status-danger" style="margin-top: 6px;">Отмена</div>
                            <?php endif; ?>
                            <div class="temza-publish-badge" data-publish-badge>
                                <?php if ($publishedBadge): ?>
                                    <div class="status-badge status-ok" style="margin-top: 6px;"><?php echo htmlspecialchars($publishedBadge); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="temza-card-actions" data-publish-actions>
                            <?php if ($preview['published_at']): ?>
                                <button type="button"
                                        class="btn-secondary temza-reset-btn"
                                        data-temza-event-id="<?php echo (int)$preview['id']; ?>">
                                    Снять отметку
                                </button>
                            <?php else: ?>
                                <button type="button"
                                        class="btn-success temza-publish-btn"
                                        data-performance-id="<?php echo $performanceId ? (int)$performanceId : ''; ?>"
                                        data-temza-event-id="<?php echo (int)$preview['id']; ?>"
                                        data-play-title="<?php echo htmlspecialchars($cardPlayTitle, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $publishDisabled ? 'disabled title="' . htmlspecialchars($publishTitle, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                    Отправить в VK
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($cardData['warnings'])): ?>
                        <div class="temza-warning-list">
                            <strong>Требует внимания:</strong>
                            <ul>
                                <?php foreach ($cardData['warnings'] as $warning): ?>
                                    <li><?php echo htmlspecialchars($warning); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($eventChanges): ?>
                        <div class="temza-warning-list" style="background:#eff6ff;color:#1d4ed8;">
                            <strong>Последние изменения состава:</strong>
                            <ul>
                                <?php foreach ($eventChanges as $changeEntry): ?>
                                    <?php foreach ($changeEntry['changes'] as $change): ?>
                                        <?php if (($change['type'] ?? '') === 'play'): ?>
                                            <li>Спектакль: <?php echo htmlspecialchars(($change['before'] ?? '—') . ' → ' . ($change['after'] ?? '—')); ?></li>
                                        <?php elseif (($change['type'] ?? '') === 'cast'): ?>
                                            <li>
                                                <?php echo htmlspecialchars($change['role'] ?? 'Роль'); ?>:
                                                <?php echo htmlspecialchars(implode(', ', $change['before'] ?? ['—']) . ' → ' . implode(', ', $change['after'] ?? ['—'])); ?>
                                            </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($cardData['text'])): ?>
                        <pre><?php echo htmlspecialchars($cardData['text']); ?></pre>
                    <?php else: ?>
                        <p class="temza-muted">Карточка ещё не собрана автоматически.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function() {
    document.addEventListener('click', async (event) => {
        const publishButton = event.target.closest('.temza-publish-btn');
        if (publishButton) {
            event.preventDefault();
            await handleTemzaPublish(publishButton);
            return;
        }

        const resetButton = event.target.closest('.temza-reset-btn');
        if (resetButton) {
            event.preventDefault();
            await handleTemzaReset(resetButton);
        }
    });

    async function handleTemzaPublish(button) {
        if (button.disabled) {
            return;
        }
        const performanceId = button.dataset.performanceId;
        const temzaEventId = button.dataset.temzaEventId;
        const playTitle = button.dataset.playTitle || 'спектакль';
        const card = button.closest('.temza-review-card');

        if (!performanceId) {
            showToast('Событие не сопоставлено с афишей.', 'error');
            return;
        }
        if (!temzaEventId || !card) {
            showToast('Не найдено событие Temza.', 'error');
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Публикуем...';
        try {
            const vkResponse = await fetch('publish_to_vk.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ performance_id: performanceId }),
            });
            if (!vkResponse.ok) {
                throw new Error(`VK HTTP ${vkResponse.status}`);
            }
            const vkData = await vkResponse.json();
            if (!vkData.success) {
                throw new Error(vkData.message || 'Ошибка публикации в VK');
            }

            const state = await toggleTemzaPublishState('publish_event', temzaEventId);
            applyTemzaPublishState(card, state);
            showToast(`Карточка для "${playTitle}" опубликована.`, 'success');
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Ошибка публикации', 'error');
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    async function handleTemzaReset(button) {
        if (button.disabled) {
            return;
        }
        const temzaEventId = button.dataset.temzaEventId;
        const card = button.closest('.temza-review-card');
        if (!temzaEventId || !card) {
            showToast('Не найдено событие Temza.', 'error');
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Снимаем...';
        try {
            const state = await toggleTemzaPublishState('reset_event_publish', temzaEventId);
            applyTemzaPublishState(card, state);
            showToast('Отметка о публикации снята.', 'success');
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Не удалось снять отметку', 'error');
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    async function toggleTemzaPublishState(action, temzaEventId) {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', action);
        formData.append('temza_event_id', temzaEventId);

        const response = await fetch('temza.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Не удалось обновить статус');
        }
        return data;
    }

    function applyTemzaPublishState(card, state) {
        const badgeContainer = card.querySelector('[data-publish-badge]');
        if (badgeContainer) {
            badgeContainer.innerHTML = '';
            if (state.published && state.badge) {
                const badge = document.createElement('div');
                badge.className = 'status-badge status-ok';
                badge.style.marginTop = '6px';
                badge.textContent = state.badge;
                badgeContainer.appendChild(badge);
            }
        }

        const actionsContainer = card.querySelector('[data-publish-actions]');
        if (actionsContainer) {
            actionsContainer.innerHTML = '';
            if (state.published) {
                card.classList.add('is-approved');
                actionsContainer.appendChild(createResetButton(card));
            } else {
                card.classList.remove('is-approved');
                actionsContainer.appendChild(createPublishButton(card));
            }
        }
    }

    function createResetButton(card) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-secondary temza-reset-btn';
        button.dataset.temzaEventId = card.dataset.temzaEventId;
        button.textContent = 'Снять отметку';
        return button;
    }

    function createPublishButton(card) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-success temza-publish-btn';
        button.dataset.temzaEventId = card.dataset.temzaEventId;
        button.dataset.performanceId = card.dataset.performanceId || '';
        button.dataset.playTitle = card.dataset.playTitle || 'спектакль';
        const publishDisabled = card.dataset.publishDisabled === '1';
        if (publishDisabled) {
            button.disabled = true;
            if (card.dataset.publishDisabledTitle) {
                button.title = card.dataset.publishDisabledTitle;
            }
        }
        button.textContent = 'Отправить в VK';
        return button;
    }

    function showToast(message, type = 'success') {
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
})();
</script>
</body>
</html>
