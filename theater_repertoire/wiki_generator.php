<?php

require_once 'db.php';

class WikiGenerator
{
    /** @var array<int, array<string, mixed>> */
    private array $playsById = [];

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

    /** @var array<int, string> */
    private array $monthTitlesLower = [
        1 => 'январь',
        2 => 'февраль',
        3 => 'март',
        4 => 'апрель',
        5 => 'май',
        6 => 'июнь',
        7 => 'июль',
        8 => 'август',
        9 => 'сентябрь',
        10 => 'октябрь',
        11 => 'ноябрь',
        12 => 'декабрь',
    ];

    /** @var array<int, string> */
    private array $monthNamesGenitive = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];

    public function __construct()
    {
        foreach (getAllPlays() as $play) {
            if (!isset($play['id'])) {
                continue;
            }
            $this->playsById[(int)$play['id']] = $play;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array{wiki:string,source:string,unmatched:array<int,array<string,mixed>>,month_key:string,month_title:string}
     */
    public function generate(int $month, int $year, array $events): array
    {
        $sortedEvents = $this->sortEvents($events);
        $occurrences = $this->calculateOccurrences($sortedEvents);

        $wikiRows = '';
        $sourceLines = [];
        $unmatched = [];

        foreach ($sortedEvents as $event) {
            $playId = isset($event['play_id']) ? (int)$event['play_id'] : null;
            if (!$playId || !isset($this->playsById[$playId])) {
                $unmatched[] = $event;
                continue;
            }

            $play = $this->playsById[$playId];
            $wikiRows .= $this->buildWikiRow($event, $play, $occurrences);
            $sourceLines[] = $this->buildSourceLine($event, $play);
        }

        return [
            'wiki' => $this->assembleWiki($month, $year, $wikiRows),
            'source' => $this->assembleSource($month, $year, $sourceLines),
            'unmatched' => $unmatched,
            'month_key' => $this->getMonthKey($month, $year),
            'month_title' => $this->getMonthTitle($month, $year),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function sortEvents(array $events): array
    {
        usort($events, static function (array $a, array $b) {
            $dateA = $a['event_date'] ?? '';
            $dateB = $b['event_date'] ?? '';

            if ($dateA === $dateB) {
                $timeA = $a['event_time'] ?? '';
                $timeB = $b['event_time'] ?? '';
                if ($timeA === $timeB) {
                    return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
                }

                return strcmp($timeA, $timeB);
            }

            return strcmp($dateA, $dateB);
        });

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, int>
     */
    private function calculateOccurrences(array $events): array
    {
        $occurrences = [];

        foreach ($events as $event) {
            $playId = isset($event['play_id']) ? (int)$event['play_id'] : null;
            if (!$playId) {
                continue;
            }

            $key = $this->buildOccurrenceKey($event, $playId);
            if (!isset($occurrences[$key])) {
                $occurrences[$key] = 0;
            }
            $occurrences[$key] += 1;
        }

        return $occurrences;
    }

    private function buildOccurrenceKey(array $event, int $playId): string
    {
        $date = $event['event_date'] ?? '';
        return $date . '_' . $playId;
    }

    private function buildWikiRow(array $event, array $play, array $occurrences): string
    {
        $playId = (int)$play['id'];
        $key = $this->buildOccurrenceKey($event, $playId);
        $hasMultiple = ($occurrences[$key] ?? 0) > 1;

        $dateStr = $this->formatDateForWiki($event['event_date'] ?? '', $event['event_time'] ?? '');
        $fullPlayName = $this->formatFullPlayName($play);

        $row = "|-\n";
        $row .= '| ' . $dateStr . "\n";

        if (!empty($play['is_subscription'])) {
            $ticketLink = $this->formatTicketLink($event['ticket_code'] ?? '', $event['ticket_url'] ?? '');
            $row .= "| '''Клуб юных петербуржцев $fullPlayName'''";
            if ($ticketLink !== '') {
                $row .= " \n<br/>'''$ticketLink'''";
            }
        } else {
            $pageName = $this->generatePageName($event, $play, $hasMultiple);
            if ($pageName) {
                updateVkPageName((int)$event['id'], $pageName);
                $link = sprintf('[[%s|в ролях]]', $pageName);
            } else {
                $link = '';
            }

            $row .= "| '''$fullPlayName'''";
            if ($link !== '') {
                $row .= " \n<br/>$link";
            }
        }

        $row .= "\n";
        $row .= '| ' . ($play['hall'] ?? '') . "\n";

        return $row;
    }

    private function formatFullPlayName(array $play): string
    {
        $specialMark = trim((string)($play['special_mark'] ?? ''));
        $fullName = (string)($play['full_name'] ?? '');

        return $specialMark !== '' ? $specialMark . ' ' . $fullName : $fullName;
    }

    private function formatTicketLink(string $code, string $directUrl = ''): string
    {
        $trimmedCode = trim($code);

        if ($directUrl !== '') {
            return sprintf('[%s|купить билет]', $directUrl);
        }

        if ($trimmedCode === '') {
            return '';
        }

        return sprintf('[http://www.zazerkal.spb.ru/tickets/%s.htm|купить билет]', $trimmedCode);
    }

    private function generatePageName(array $event, array $play, bool $hasMultiple): ?string
    {
        $wikiLink = trim((string)($play['wiki_link'] ?? ''));
        if ($wikiLink === '') {
            return null;
        }

        $date = $event['event_date'] ?? '';
        if ($date === '') {
            return null;
        }

        try {
            $dateObj = new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return null;
        }

        $dateForLink = $dateObj->format('d.m.y');
        $pageName = $wikiLink . '_' . $dateForLink;

        if ($hasMultiple) {
            $pageName .= '_' . $this->formatTimeForLink($event['event_time'] ?? '');
        }

        return $pageName;
    }

    private function formatRolesLink(array $event, array $play, bool $hasMultiple): string
    {
        $pageName = $this->generatePageName($event, $play, $hasMultiple);
        if ($pageName) {
            return sprintf('[[%s|в ролях]]', $pageName);
        }
        return '';
    }

    private function formatDateForWiki(string $date, string $time): string
    {
        try {
            $dateObj = new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return $date;
        }

        $day = (int)$dateObj->format('j');
        $monthNum = (int)$dateObj->format('n');
        $monthName = $this->monthNamesGenitive[$monthNum] ?? $dateObj->format('m');
        $formattedTime = $this->formatTimeForDisplay($time);

        return sprintf('%d %s, %s', $day, $monthName, $formattedTime);
    }

    private function formatTimeForDisplay(string $time): string
    {
        if ($time === '') {
            return '';
        }

        $parts = explode(':', $time);
        $hours = (int)($parts[0] ?? 0);
        $minutes = (int)($parts[1] ?? 0);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function formatTimeForLink(string $time): string
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

    private function assembleWiki(int $month, int $year, string $rows): string
    {
        $rows = trim($rows);
        if ($rows === '') {
            return '';
        }

        $monthLower = $this->monthTitlesLower[$month] ?? '';
        $wiki = sprintf("== Репертуар на %s %d ==\n\n", $monthLower, $year);
        $wiki .= "'''ВНИМАНИЕ! В составах возможны изменения!'''\n\n";
        $wiki .= "{|\n";
        $wiki .= "|- \n";
        $wiki .= "| <center>'''Дата'''</center>\n";
        $wiki .= "| <center>'''Название спектакля'''</center>\n";
        $wiki .= "| <center>'''Зал'''</center>\n";
        $wiki .= $rows . "\n";
        $wiki .= "|}\n";

        return $wiki;
    }

    /**
     * @param array<int, string> $lines
     */
    private function assembleSource(int $month, int $year, array $lines): string
    {
        $lines = array_values(array_filter($lines, static fn($line) => trim((string)$line) !== ''));
        $header = $this->getMonthKey($month, $year);

        if (empty($lines)) {
            return $header;
        }

        return $header . PHP_EOL . implode(PHP_EOL, $lines);
    }

    private function buildSourceLine(array $event, array $play): string
    {
        $date = $event['event_date'] ?? '';
        $time = $event['event_time'] ?? '';
        $ticketCode = trim((string)($event['ticket_code'] ?? ''));

        try {
            $dateObj = new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return '';
        }

        $day = (int)$dateObj->format('j');
        $timeToken = $this->formatTimeForLink($time);
        $title = $this->resolveSiteTitle($event, $play);

        $fields = [
            (string)$day,
            $timeToken,
            $title,
            $ticketCode,
        ];

        return rtrim(implode("\t", $fields));
    }

    private function resolveSiteTitle(array $event, array $play): string
    {
        $siteTitle = trim((string)($play['site_title'] ?? ''));
        if ($siteTitle !== '') {
            return $siteTitle;
        }

        $fullName = (string)($play['full_name'] ?? '');
        if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $fullName, $match)) {
            return $match[1];
        }

        return $event['title'] ?? $fullName;
    }

    private function getMonthKey(int $month, int $year): string
    {
        $monthLower = $this->monthTitlesLower[$month] ?? '';
        return sprintf('%s %d', $monthLower, $year);
    }

    private function getMonthTitle(int $month, int $year): string
    {
        $monthTitle = $this->monthTitles[$month] ?? '';
        return sprintf('%s %d', $monthTitle, $year);
    }
}
