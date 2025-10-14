<?php
require_once 'config.php';
require_once 'db.php';
require_once 'web_parser.php';
require_once 'wiki_generator.php';

requireAuth();

$plays = getAllPlays();

$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
$defaultMonth = $currentMonth;
$defaultYear = $currentYear;

if ($currentMonth === 12) {
    $defaultMonth = 1;
    $defaultYear = $currentYear + 1;
} else {
    $defaultMonth = $currentMonth + 1;
}

$selectedMonth = (int)($_POST['month'] ?? $defaultMonth);
$selectedYear = (int)($_POST['year'] ?? $defaultYear);
$maxPages = max(1, (int)($_POST['max_pages'] ?? 5));

$message = '';
$errors = [];
$events = [];
$batchToken = null;
$wikiOutput = '';
$sourceOutput = '';
$unmatchedEvents = [];
$generatedMonthYear = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'parse') {
        try {
            if ($selectedYear < 2000 || $selectedYear > 2100) {
                throw new InvalidArgumentException('Укажите корректный год.');
            }

            if ($selectedMonth < 1 || $selectedMonth > 12) {
                throw new InvalidArgumentException('Укажите корректный месяц.');
            }

            $parser = new WebPosterParser($plays);
            $batchToken = generateUuidV4();

            clearEventsRawByMonthYear($selectedMonth, $selectedYear);

            $parsedEvents = $parser->collectEvents($selectedMonth, $selectedYear, $maxPages);

            if (empty($parsedEvents)) {
                $message = 'Для выбранного месяца события не найдены.';
            }

            foreach ($parsedEvents as $event) {
                $eventId = insertRawEvent(array_merge($event, [
                    'batch_token' => $batchToken,
                    'month' => $selectedMonth,
                    'year' => $selectedYear,
                ]));

                // Сразу создаем базовый шаблон для VK
                if ($eventId && !empty($event['ticket_code'])) {
                    $templateText = sprintf(
                        "==В ролях:==\n''СОСТАВ УТОЧНЯЕТСЯ''\n\n'''[http://www.zazerkal.spb.ru/tickets/%s.htm|КУПИТЬ БИЛЕТ]'''",
                        $event['ticket_code']
                    );
                    updateVkPostText($eventId, $templateText);
                }
            }

            if ($batchToken) {
                $_SESSION['last_batch_token'] = $batchToken;
            }

            $events = $batchToken ? getRawEventsByBatch($batchToken) : [];
            if (!empty($events)) {
                $message = sprintf('Загружено %d событий за %02d.%d.', count($events), $selectedMonth, $selectedYear);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $events = [];
        }
    } elseif ($action === 'save_mapping') {
        $selectedMonth = (int)($_POST['selected_month'] ?? $selectedMonth);
        $selectedYear = (int)($_POST['selected_year'] ?? $selectedYear);
        $eventsMapping = $_POST['events'] ?? [];

        try {
            foreach ($eventsMapping as $eventId => $playId) {
                $eventId = (int)$eventId;
                if ($eventId <= 0) {
                    continue;
                }

                if ($playId === '' || $playId === null) {
                    updateEventPlayMapping($eventId, null);
                    continue;
                }

                updateEventPlayMapping($eventId, (int)$playId);
            }

            $message = 'Сопоставление спектаклей обновлено.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $events = getRawEventsByMonthYear($selectedMonth, $selectedYear);
    } elseif ($action === 'generate') {
        $selectedMonth = (int)($_POST['selected_month'] ?? $selectedMonth);
        $selectedYear = (int)($_POST['selected_year'] ?? $selectedYear);

        try {
            if ($selectedYear < 2000 || $selectedYear > 2100) {
                throw new InvalidArgumentException('Укажите корректный год.');
            }

            if ($selectedMonth < 1 || $selectedMonth > 12) {
                throw new InvalidArgumentException('Укажите корректный месяц.');
            }

            $events = getRawEventsByMonthYear($selectedMonth, $selectedYear);
            if (empty($events)) {
                throw new RuntimeException('Для выбранного периода события не найдены. Сначала загрузите афишу и сохраните сопоставление.');
            }

            $generator = new WikiGenerator();
            $result = $generator->generate($selectedMonth, $selectedYear, $events);
            $wikiOutput = $result['wiki'];
            $sourceOutput = $result['source'];
            $unmatchedEvents = $result['unmatched'];
            $generatedMonthYear = $result['month_key'];
            $message = sprintf('Афиша за %s сформирована.', $result['month_title']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
} else {
    $events = getRawEventsByMonthYear($selectedMonth, $selectedYear);
}

$lastBatchToken = $_SESSION['last_batch_token'] ?? null;
if (empty($events) && $lastBatchToken) {
    $events = getRawEventsByBatch($lastBatchToken);
    if (!empty($events)) {
        $selectedMonth = (int)$events[0]['month'];
        $selectedYear = (int)$events[0]['year'];
    }
}

function monthOptions(int $selected): string
{
    $months = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    $options = '';
    foreach ($months as $number => $name) {
        $options .= sprintf(
            '<option value="%d" %s>%s</option>',
            $number,
            $number === $selected ? 'selected' : '',
            $name
        );
    }

    return $options;
}

function yearOptions(int $selectedYear, int $range = 3): string
{
    $current = (int)date('Y');
    $years = range($current - 1, $current + $range);

    $options = '';
    foreach ($years as $year) {
        $options .= sprintf(
            '<option value="%d" %s>%d</option>',
            $year,
            $year === $selectedYear ? 'selected' : '',
            $year
        );
    }

    return $options;
}

function playSiteTitle(array $play): string
{
    if (!empty($play['site_title'])) {
        return $play['site_title'];
    }

    $fullName = $play['full_name'] ?? '';
    if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $fullName, $match)) {
        return $match[1];
    }

    return $fullName;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Парсинг афиши - Репертуар театра</title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Парсинг афиши</h1>
            <div>
                <a href="index.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none;">Главная</a>
                <a href="schedule.php" class="btn-primary" style="padding: 10px 20px; text-decoration: none; margin-left: 10px;">Управление составами</a>
                <a href="admin.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none; margin-left: 10px;">Спектакли</a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="message error">
                <?php foreach ($errors as $error): ?>
                    <p style="margin: 4px 0;"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <h2>Загрузить афишу с сайта</h2>
            <form method="post" class="form-inline" style="display: flex; gap: 15px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="parse">
                <div>
                    <label for="month">Месяц</label>
                    <select id="month" name="month">
                        <?php echo monthOptions($selectedMonth); ?>
                    </select>
                </div>
                <div>
                    <label for="year">Год</label>
                    <select id="year" name="year">
                        <?php echo yearOptions($selectedYear); ?>
                    </select>
                </div>
                <div>
                    <label for="max_pages">Страниц</label>
                    <input type="number" id="max_pages" name="max_pages" value="<?php echo htmlspecialchars($maxPages); ?>" min="1" max="10">
                </div>
                <div class="buttons">
                    <button type="submit" class="btn-primary">Обновить афишу</button>
                </div>
            </form>
        </div>

        <div class="section">
            <h2>Результат парсинга</h2>

            <?php if (empty($events)): ?>
                <p>Нет данных за выбранный период.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_mapping">
                    <input type="hidden" name="selected_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                    <input type="hidden" name="selected_year" value="<?php echo htmlspecialchars($selectedYear); ?>">

                    <table>
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Время</th>
                                <th>Название на сайте</th>
                                <th>Возраст</th>
                                <th>Код билета</th>
                                <th>Спектакль</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($event['event_date']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($event['event_time'], 0, 5)); ?></td>
                                    <td><?php echo htmlspecialchars($event['title']); ?></td>
                                    <td><?php echo htmlspecialchars($event['age_category']); ?></td>
                                    <td>
                                        <?php if (!empty($event['ticket_code'])): ?>
                                            <a href="<?php echo htmlspecialchars($event['ticket_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($event['ticket_code']); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select name="events[<?php echo (int)$event['id']; ?>]">
                                            <option value="">— Не выбрано —</option>
                                            <?php foreach ($plays as $play): ?>
                                                <?php $optionLabel = $play['short_name'] . ' — ' . playSiteTitle($play); ?>
                                                <option value="<?php echo (int)$play['id']; ?>" <?php echo ($event['play_id'] == $play['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($optionLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="buttons" style="margin-top: 20px;">
                        <button type="submit" class="btn-primary">Сохранить сопоставление</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>Сформированная афиша</h2>

            <form method="post" style="margin-bottom: 20px; display: inline-flex; gap: 10px; flex-wrap: wrap;">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="selected_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                <input type="hidden" name="selected_year" value="<?php echo htmlspecialchars($selectedYear); ?>">
                <button type="submit" class="btn-primary">Сформировать афишу</button>
            </form>

            <?php if ($wikiOutput === '' && $sourceOutput === '' && empty($unmatchedEvents)): ?>
                <p>Сформируйте афишу после сопоставления спектаклей.</p>
            <?php else: ?>
                <?php if (!empty($unmatchedEvents)): ?>
                    <div class="message error" style="margin-bottom: 20px;">
                        <strong>Не сопоставлены спектакли:</strong>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ($unmatchedEvents as $event): ?>
                                <li><?php echo htmlspecialchars($event['event_date'] . ' ' . substr($event['event_time'], 0, 5) . ' — ' . $event['title']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p style="margin-top: 10px;">Назначьте спектакли в таблице выше и повторите формирование афиши.</p>
                    </div>
                <?php endif; ?>

                <?php if ($wikiOutput !== ''): ?>
                    <div class="form-group">
                        <label for="wiki_output_area">Wiki-разметка:</label>
                        <div class="result-text" id="wiki_output_preview" style="max-height: 400px; overflow-y: auto;">
                            <?php echo nl2br(htmlspecialchars($wikiOutput)); ?>
                        </div>
                        <textarea id="wiki_output_area" readonly rows="12" style="min-height: 200px; margin-top: 12px; display: none;"><?php echo htmlspecialchars($wikiOutput, ENT_NOQUOTES); ?></textarea>
                        <button type="button" class="btn-secondary" onclick="copyWikiMarkup()">Скопировать Wiki</button>
                    </div>
                <?php endif; ?>

                <?php if ($sourceOutput !== ''): ?>
                    <div class="form-group">
                        <label for="source_output_area">Исходный текст:</label>
                        <div class="result-text" id="source_output_preview" style="max-height: 250px; overflow-y: auto;">
                            <?php echo nl2br(htmlspecialchars($sourceOutput)); ?>
                        </div>
                        <textarea id="source_output_area" readonly rows="8" style="min-height: 160px; margin-top: 12px; display: none;"><?php echo htmlspecialchars($sourceOutput, ENT_NOQUOTES); ?></textarea>
                        <button type="button" class="btn-secondary" onclick="copySourceText()">Скопировать исходник</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyWikiMarkup() {
            const source = document.getElementById('wiki_output_area');
            if (!source) {
                return;
            }
            navigator.clipboard.writeText(source.value)
                .then(() => alert('Wiki-разметка скопирована.'))
                .catch(() => alert('Не удалось скопировать текст.'));
        }

        function copySourceText() {
            const source = document.getElementById('source_output_area');
            if (!source) {
                return;
            }
            navigator.clipboard.writeText(source.value)
                .then(() => alert('Исходный текст скопирован.'))
                .catch(() => alert('Не удалось скопировать текст.'));
        }
    </script>
</body>
</html>
