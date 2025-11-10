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

$months = getTemzaMonths();
$defaultMonth = $months[0] ?? date('Y-m');
$currentMonth = $_GET['month'] ?? ($_POST['current_month'] ?? $defaultMonth);
if ($months && !in_array($currentMonth, $months, true)) {
    $currentMonth = $defaultMonth;
}

$titleFilter = $_GET['title_filter'] ?? 'unmapped';
$eventFilter = $_GET['event_filter'] ?? 'unmatched';

function temzaBuildQuery(string $month, string $titleFilter, string $eventFilter): string
{
    return http_build_query([
        'month' => $month,
        'title_filter' => $titleFilter,
        'event_filter' => $eventFilter,
    ]);
}

$redirectQuery = temzaBuildQuery($currentMonth, $titleFilter, $eventFilter);
$flashMessage = $_SESSION['temza_flash']['message'] ?? null;
$flashType = $_SESSION['temza_flash']['type'] ?? 'success';
unset($_SESSION['temza_flash']);

$errors = [];

function temzaRedirect(string $query = ''): void
{
    $location = 'temza.php' . ($query ? '?' . $query : '');
    header("Location: {$location}");
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
        .temza-forms select {
            min-width: 220px;
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
                        <tr>
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
</div>
</body>
</html>
