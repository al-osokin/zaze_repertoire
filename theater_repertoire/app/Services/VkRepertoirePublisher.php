<?php

class VkRepertoirePublisher
{
    private VKApiClient $client;
    private int $groupId;
    private int $rateDelayMicros;

    /** @var array<int, string> */
    private array $monthTitles = [
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

    /** @var array<int, int> */
    private array $monthOrder = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8];

    /** @var array<int, int> */
    private array $monthOrderIndex = [];

    public function __construct(VKApiClient $client, int $groupId, int $rateDelayMicros = 500000)
    {
        $this->client = $client;
        $this->groupId = $groupId;
        $this->rateDelayMicros = $rateDelayMicros;
        $this->monthOrderIndex = array_flip($this->monthOrder);
    }

    /**
     * Publishes a monthly repertoire page, updates the archive and creates placeholders.
     *
     * @param int $month
     * @param int $year
     * @param string $wikiText
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function publishMonthlyRepertoire(int $month, int $year, string $wikiText, array $events): array
    {
        $pageId = $this->publishMonthlyPage($month, $year, $wikiText);
        $archiveResult = $this->updateArchivePage($month, $year);
        $placeholders = $this->ensurePlaceholders($events);

        return [
            'page_id' => $pageId,
            'archive' => $archiveResult,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Publishes (creates or updates) the monthly repertoire page.
     *
     * @return int page_id returned by VK
     */
    public function publishMonthlyPage(int $month, int $year, string $wikiText): int
    {
        $title = $this->formatMonthlyPageTitle($month, $year);
        $result = $this->client->savePage($title, $wikiText, $this->groupId);

        if ($this->isErrorResponse($result) || $result === null) {
            $message = $this->buildErrorMessage($result, 'Не удалось сохранить страницу афиши');
            throw new RuntimeException($message);
        }

        if (is_int($result)) {
            return $result;
        }

        return (int)($result['page_id'] ?? 0);
    }

    /**
     * Updates the Archive page so that it contains a link to the newly published month.
     *
     * @return array<string, mixed>
     */
    public function updateArchivePage(int $month, int $year): array
    {
        $archiveTitle = 'Архив';
        $response = $this->client->getPageByTitle($archiveTitle, $this->groupId, true);

        $pageId = null;
        $source = '';

        if ($this->isErrorResponse($response)) {
            $errorCode = $response['error']['error_code'] ?? null;
            if ($errorCode !== 15) {
                $message = $this->buildErrorMessage($response, 'Не удалось получить страницу Архив');
                throw new RuntimeException($message);
            }
            $source = "==Архив афиши==\n";
        } else {
            $pageId = $response['page_id'] ?? null;
            $source = (string)($response['source'] ?? '');
            if ($source === '') {
                $source = "==Архив афиши==\n";
            }
        }

        $updatedSource = $this->injectMonthIntoArchive($source, $month, $year);
        if ($updatedSource === $source) {
            return [
                'updated' => false,
                'page_id' => $pageId,
            ];
        }

        $saveResult = $this->client->savePage($archiveTitle, $updatedSource, $this->groupId, $pageId ? (int)$pageId : null);
        if ($this->isErrorResponse($saveResult) || $saveResult === null) {
            $message = $this->buildErrorMessage($saveResult, 'Не удалось обновить страницу Архив');
            throw new RuntimeException($message);
        }

        if (is_int($saveResult)) {
            $pageId = $saveResult;
        } elseif (is_array($saveResult) && isset($saveResult['page_id'])) {
            $pageId = (int)$saveResult['page_id'];
        }

        return [
            'updated' => true,
            'page_id' => $pageId,
        ];
    }

    /**
     * Ensures that each performance has a wiki page with a placeholder cast.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function ensurePlaceholders(array $events): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($events as $event) {
            $pageName = trim((string)($event['vk_page_name'] ?? ''));
            if ($pageName === '') {
                $skipped++;
                continue;
            }

            try {
                $existing = $this->client->getPageByTitle($pageName, $this->groupId, true);

                if ($this->isErrorResponse($existing)) {
                    $errorCode = $existing['error']['error_code'] ?? null;
                    if ($errorCode !== 15) {
                        $errors[] = $this->buildErrorMessage($existing, "Не удалось получить страницу {$pageName}");
                        $skipped++;
                        continue;
                    }

                    $this->createPlaceholder($pageName, $event);
                    $created++;
                    usleep($this->rateDelayMicros);
                    continue;
                }

                $source = trim((string)($existing['source'] ?? ''));
                if ($source === '' || stripos($source, "''СОСТАВ УТОЧНЯЕТСЯ''") !== false) {
                    $this->createPlaceholder($pageName, $event, (int)($existing['page_id'] ?? 0));
                    $created++;
                    usleep($this->rateDelayMicros);
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors[] = sprintf('Ошибка при создании плейсхолдера %s: %s', $pageName, $e->getMessage());
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function createPlaceholder(string $pageName, array $event, int $pageId = 0): void
    {
        $text = $this->buildPlaceholderText($event);
        $result = $this->client->savePage($pageName, $text, $this->groupId, $pageId ?: null);

        if ($this->isErrorResponse($result) || $result === null) {
            throw new RuntimeException($this->buildErrorMessage($result, "Не удалось сохранить плейсхолдер {$pageName}"));
        }
    }

    private function buildPlaceholderText(array $event): string
    {
        $lines = [
            '==В ролях:==',
            "''СОСТАВ УТОЧНЯЕТСЯ''",
        ];

        $ticketCode = trim((string)($event['ticket_code'] ?? ''));
        if ($ticketCode !== '') {
            $lines[] = '';
            $lines[] = "'''[http://www.zazerkal.spb.ru/tickets/{$ticketCode}.htm|КУПИТЬ БИЛЕТ]'''";
        }

        return implode("\n", $lines);
    }

    private function injectMonthIntoArchive(string $source, int $month, int $year): string
    {
        $archive = $this->parseArchive($source);
        $seasonStart = $this->getSeasonStartYear($month, $year);

        if (!$this->addEntryToArchive($archive, $seasonStart, $month, $year)) {
            return $source;
        }

        return $this->buildArchiveSource($archive);
    }

    /**
     * @return array{preamble: array<int,string>, seasons: array<int,array<string,mixed>>, order: array<int,int>}
     */
    private function parseArchive(string $source): array
    {
        $lines = preg_split("/\r?\n/", $source);
        if ($lines === false) {
            $lines = [$source];
        }

        $preamble = [];
        $seasons = [];
        $order = [];
        $currentSeason = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^===\s*(\d+)-й сезон\s+(\d{4})-(\d{4})\s*===\s*$/u', $trimmed, $matches)) {
                if ($currentSeason !== null) {
                    $currentSeason['entries'] = $this->extractEntries($currentSeason['raw_lines']);
                    $seasons[$currentSeason['start_year']] = [
                        'number' => $currentSeason['number'],
                        'header' => $currentSeason['header'],
                        'entries' => $currentSeason['entries'],
                    ];
                    $order[] = $currentSeason['start_year'];
                }

                $currentSeason = [
                    'number' => (int)$matches[1],
                    'start_year' => (int)$matches[2],
                    'end_year' => (int)$matches[3],
                    'header' => $line === '' ? $trimmed : $line,
                    'raw_lines' => [],
                ];
            } else {
                if ($currentSeason !== null) {
                    $currentSeason['raw_lines'][] = $line;
                } else {
                    $preamble[] = $line;
                }
            }
        }

        if ($currentSeason !== null) {
            $currentSeason['entries'] = $this->extractEntries($currentSeason['raw_lines']);
            $seasons[$currentSeason['start_year']] = [
                'number' => $currentSeason['number'],
                'header' => $currentSeason['header'],
                'entries' => $currentSeason['entries'],
            ];
            $order[] = $currentSeason['start_year'];
        }

        return [
            'preamble' => $preamble,
            'seasons' => $seasons,
            'order' => $order,
        ];
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,array{month:int,year:int}>
     */
    private function extractEntries(array $lines): array
    {
        $entries = [];

        foreach ($lines as $line) {
            $entry = $this->parseEntryLine($line);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array{month:int,year:int}|null
     */
    private function parseEntryLine(string $line): ?array
    {
        if (!preg_match('/^\*\s*\[\[([^\]|]+)\|([^\]]+)\]\]/u', trim($line), $matches)) {
            return null;
        }

        if (!preg_match('/(.+?)_(\d{4})$/u', $matches[1], $pair)) {
            return null;
        }

        $monthName = $pair[1];
        $year = (int)$pair[2];
        $month = array_search($monthName, $this->monthTitles, true);

        if ($month === false) {
            return null;
        }

        return [
            'month' => (int)$month,
            'year' => $year,
        ];
    }

    /**
     * @param array<string,mixed> $archive
     */
    private function addEntryToArchive(array &$archive, int $seasonStartYear, int $month, int $year): bool
    {
        $entry = ['month' => $month, 'year' => $year];

        if (isset($archive['seasons'][$seasonStartYear])) {
            return $this->insertEntryIntoSeason($archive['seasons'][$seasonStartYear], $entry);
        }

        $seasonNumber = $this->nextSeasonNumber($archive['seasons']);
        $seasonHeader = sprintf('===%d-й сезон %d-%d===', $seasonNumber, $seasonStartYear, $seasonStartYear + 1);

        $season = [
            'number' => $seasonNumber,
            'header' => $seasonHeader,
            'entries' => [],
        ];

        $this->insertEntryIntoSeason($season, $entry);
        $archive['seasons'][$seasonStartYear] = $season;
        $archive['order'] = $this->insertSeasonStartYear($archive['order'], $seasonStartYear);
        return true;
    }

    /**
     * @param array<string,mixed> $season
     */
    private function insertEntryIntoSeason(array &$season, array $entry): bool
    {
        foreach ($season['entries'] as $existing) {
            if ($existing['month'] === $entry['month'] && $existing['year'] === $entry['year']) {
                return false;
            }
        }

        $newOrder = $this->monthOrderIndex[$entry['month']] ?? 999;
        $inserted = false;

        foreach ($season['entries'] as $index => $existing) {
            $existingOrder = $this->monthOrderIndex[$existing['month']] ?? 999;
            if ($newOrder < $existingOrder) {
                array_splice($season['entries'], $index, 0, [$entry]);
                $inserted = true;
                break;
            }
        }

        if (!$inserted) {
            $season['entries'][] = $entry;
        }

        return true;
    }

    /**
     * @param array<int,int> $order
     * @return array<int,int>
     */
    private function insertSeasonStartYear(array $order, int $seasonStartYear): array
    {
        if (in_array($seasonStartYear, $order, true)) {
            return $order;
        }

        if (empty($order)) {
            return [$seasonStartYear];
        }

        $inserted = false;
        foreach ($order as $index => $existingYear) {
            if ($seasonStartYear > $existingYear) {
                array_splice($order, $index, 0, [$seasonStartYear]);
                $inserted = true;
                break;
            }
        }

        if (!$inserted) {
            $order[] = $seasonStartYear;
        }

        return $order;
    }

    /**
     * @param array<int,array<string,mixed>> $seasons
     */
    private function nextSeasonNumber(array $seasons): int
    {
        if (empty($seasons)) {
            return 1;
        }

        $max = 0;
        foreach ($seasons as $season) {
            $max = max($max, (int)($season['number'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param array<string,mixed> $archive
     */
    private function buildArchiveSource(array $archive): string
    {
        $lines = $archive['preamble'];
        $lines = $this->trimTrailingEmptyLines($lines);

        foreach ($archive['order'] as $startYear) {
            $season = $archive['seasons'][$startYear];
            $lines[] = $season['header'];

            foreach ($season['entries'] as $entry) {
                $lines[] = $this->buildEntryLine($entry['month'], $entry['year']);
            }

            $lines[] = '';
        }

        $result = implode("\n", $lines);
        return rtrim($result) . "\n";
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private function trimTrailingEmptyLines(array $lines): array
    {
        while (!empty($lines) && trim(end($lines)) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    private function buildEntryLine(int $month, int $year): string
    {
        $monthTitle = $this->monthTitles[$month] ?? '';
        return sprintf('* [[%s_%d|%s %d]]', $monthTitle, $year, $monthTitle, $year);
    }

    private function getSeasonStartYear(int $month, int $year): int
    {
        return $month >= 9 ? $year : $year - 1;
    }

    private function formatMonthlyPageTitle(int $month, int $year): string
    {
        $monthTitle = $this->monthTitles[$month] ?? '';
        return $monthTitle . '_' . $year;
    }

    private function isErrorResponse(mixed $response): bool
    {
        return is_array($response) && isset($response['error']);
    }

    private function buildErrorMessage(mixed $response, string $default): string
    {
        if ($this->isErrorResponse($response)) {
            $error = $response['error'];
            $errorCode = $error['error_code'] ?? '';
            $errorMsg = $error['error_msg'] ?? 'Unknown error';
            return sprintf('%s: %s (%s)', $default, $errorMsg, $errorCode);
        }

        return $default;
    }
}
