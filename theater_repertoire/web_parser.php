<?php

class WebPosterParser
{
    private const BASE_URL = 'https://www.zazerkal.spb.ru/events/';
    private const ROOT_URL = 'https://www.zazerkal.spb.ru/';

    private $playsByNormalized = [];
    private $plays = [];

    public function __construct(array $plays)
    {
        $this->plays = $plays;
        $this->preparePlayIndex($plays);
    }

    public function collectEvents(int $targetMonth, int $targetYear, int $maxPages = 5): array
    {
        $allEvents = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $html = $this->fetchPage($page);
            if (!$html) {
                break;
            }

            $scheduleMap = $this->extractScheduleMap($html);
            $pageEvents = $this->parseEvents($html, $scheduleMap);

            if (empty($pageEvents) && $page === 1) {
                throw new RuntimeException('Не удалось извлечь события с первой страницы афиши. Проверьте структуру сайта.');
            }

            $filtered = array_filter($pageEvents, function (array $event) use ($targetMonth, $targetYear) {
                return (int)$event['event_month'] === $targetMonth && (int)$event['event_year'] === $targetYear;
            });

            if (!empty($filtered)) {
                $allEvents = array_merge($allEvents, array_map(function (array $event) {
                    unset($event['event_month'], $event['event_year']);
                    return $event;
                }, $filtered));
            }

            // Если на странице не найдено карточек вовсе, прекращаем обход
            if (empty($pageEvents)) {
                break;
            }
        }

        return $allEvents;
    }

    private function preparePlayIndex(array $plays): void
    {
        foreach ($plays as $play) {
            $displayName = $this->getPlaySiteTitle($play);
            $normalized = $this->normalizeTitle($displayName);

            if (!empty($normalized)) {
                $this->playsByNormalized[$normalized] = $play;
            }
        }
    }

    private function getPlaySiteTitle(array $play): string
    {
        $siteTitle = $play['site_title'] ?? '';
        if ($siteTitle !== null && $siteTitle !== '') {
            return $siteTitle;
        }

        return $this->extractDisplayName($play['full_name'] ?? '');
    }

    private function fetchPage(int $page): ?string
    {
        $url = self::BASE_URL;
        if ($page > 1) {
            $url .= '?p=' . $page . '&event=&eventdate=';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PosterParser/1.0; +https://www.zazerkal.spb.ru)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            throw new RuntimeException(sprintf('Ошибка при получении страницы %s: %s (HTTP %s)', $url, $error ?: 'неизвестная', $httpCode));
        }

        return $result ?: null;
    }

    private function extractScheduleMap(string $html): array
    {
        $map = [];

        if (preg_match("/var\s+massDate\s*=\s*'([^']*)'/u", $html, $dateMatch)
            && preg_match("/var\s+massLinks\s*=\s*'([^']*)'/u", $html, $linkMatch)
        ) {
            $dates = array_map('trim', array_filter(explode(',', $dateMatch[1])));
            $links = array_map('trim', array_filter(explode(',', $linkMatch[1])));

            $count = min(count($dates), count($links));
            for ($i = 0; $i < $count; $i++) {
                $code = $this->extractDigits($links[$i]);
                if ($code && !empty($dates[$i])) {
                    $map[$code] = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dates[$i]);
                }
            }
        }

        return $map;
    }

    private function parseEvents(string $html, array $scheduleMap): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $eventNodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' event ')]");

        $events = [];

        foreach ($eventNodes as $eventNode) {
            /** @var DOMElement $eventNode */
            $ticketHref = $this->getNodeAttribute($xpath, './/div[contains(@class,"ticket")]//a', 'href', $eventNode);
            $ticketCode = $ticketHref ? $this->extractDigits($ticketHref) : null;
            $repertoireHref = $this->getNodeAttribute($xpath, './/a', 'href', $eventNode);
            $title = $this->getNodeText($xpath, './/div[contains(@class,"desc")]', $eventNode);
            $age = $this->getNodeText($xpath, './/div[contains(@class,"age-block")]', $eventNode);
            $backgroundStyle = $eventNode->getAttribute('style');
            $backgroundUrl = $this->extractBackgroundUrl($backgroundStyle);

            $cardDay = $this->getNodeText($xpath, './/div[contains(@class,"event_col-day")]', $eventNode);
            $cardMonthText = $this->getNodeText($xpath, './/div[contains(@class,"event_col-date")]/p[1]', $eventNode);
            $cardTimeText = $this->getNodeText($xpath, './/div[contains(@class,"event_col-time")]/p[1]', $eventNode);

            $dateTime = null;
            if ($ticketCode && isset($scheduleMap[$ticketCode]) && $scheduleMap[$ticketCode] instanceof DateTimeInterface) {
                $dateTime = $scheduleMap[$ticketCode];
            } else {
                $fallbackMonth = $this->resolveMonthNumber($cardMonthText);
                $fallbackDay = (int)$this->extractDigits($cardDay);
                $fallbackTime = $this->normalizeTime($cardTimeText);

                if ($fallbackMonth === null) {
                    $fallbackMonth = $targetMonth;
                }

                $timeParts = explode(':', $fallbackTime);
                if ($fallbackDay > 0 && count($timeParts) >= 2) {
                    $dateTime = DateTimeImmutable::createFromFormat(
                        'Y-n-j H:i',
                        sprintf('%d-%d-%d %s', $targetYear, $fallbackMonth, $fallbackDay, $fallbackTime)
                    );
                }
            }

            if (!$dateTime instanceof DateTimeInterface) {
                continue;
            }

            $normalizedTitle = $this->normalizeTitle($title);
            $matchedPlay = $this->matchPlay($normalizedTitle);

            $events[] = [
                'event_date' => $dateTime->format('Y-m-d'),
                'event_time' => $dateTime->format('H:i:s'),
                'event_month' => (int)$dateTime->format('n'),
                'event_year' => (int)$dateTime->format('Y'),
                'title' => $title,
                'normalized_title' => $normalizedTitle,
                'age_category' => $age,
                'ticket_code' => $ticketCode,
                'ticket_url' => $ticketHref ? $this->makeAbsoluteUrl($ticketHref) : null,
                'repertoire_url' => $repertoireHref ? $this->makeAbsoluteUrl($repertoireHref) : null,
                'background_url' => $backgroundUrl ? $this->makeAbsoluteUrl($backgroundUrl) : null,
                'play_id' => $matchedPlay['id'] ?? null,
                'play_short_name' => $matchedPlay['short_name'] ?? null,
            ];
        }

        return $events;
    }

    private function getNodeText(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $node = $xpath->query($query, $context)->item(0);
        if (!$node) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private function getNodeAttribute(DOMXPath $xpath, string $query, string $attribute, DOMElement $context): ?string
    {
        $node = $xpath->query($query, $context)->item(0);
        if (!$node || !$node->hasAttribute($attribute)) {
            return null;
        }
        return trim($node->getAttribute($attribute));
    }

    private function extractDigits(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        if (preg_match('/(\d{3,})/', $value, $match)) {
            return $match[1];
        }
        return null;
    }

    private function extractBackgroundUrl(?string $style): ?string
    {
        if (!$style) {
            return null;
        }
        if (preg_match('/url\(([^\)]+)\)/', $style, $match)) {
            return trim($match[1], "'\"");
        }
        return null;
    }

    private function makeAbsoluteUrl(string $path): string
    {
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }

        $cleanPath = preg_replace('#^\.\./#', '', ltrim($path, '/'));
        return rtrim(self::ROOT_URL, '/') . '/' . $cleanPath;
    }

    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');
        $title = str_replace('ё', 'е', $title);
        $title = preg_replace('/[^a-z0-9а-яё]+/u', ' ', $title);
        return trim(preg_replace('/\s+/u', ' ', $title));
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $match)) {
            return sprintf('%02d:%02d', (int)$match[1], (int)$match[2]);
        }
        if (preg_match('/^(\d{1,2})$/', $time, $match)) {
            return sprintf('%02d:00', (int)$match[1]);
        }
        return '00:00';
    }

    private function resolveMonthNumber(?string $monthText): ?int
    {
        if (!$monthText) {
            return null;
        }

        $monthText = mb_strtolower(trim($monthText), 'UTF-8');
        $map = [
            'января' => 1,
            'февраля' => 2,
            'марта' => 3,
            'апреля' => 4,
            'мая' => 5,
            'июня' => 6,
            'июля' => 7,
            'августа' => 8,
            'сентября' => 9,
            'октября' => 10,
            'ноября' => 11,
            'декабря' => 12,
        ];

        return $map[$monthText] ?? null;
    }

    private function extractDisplayName(string $fullName): string
    {
        if (preg_match('/\[\[(.+?)\|(.*?)\]\]/u', $fullName, $match)) {
            return $match[2];
        }
        if (preg_match('/\[\[(.+?)\]\]/u', $fullName, $match)) {
            return $match[1];
        }
        return $fullName;
    }

    private function matchPlay(string $normalizedTitle): ?array
    {
        if (empty($normalizedTitle)) {
            return null;
        }

        if (isset($this->playsByNormalized[$normalizedTitle])) {
            return $this->playsByNormalized[$normalizedTitle];
        }

        foreach ($this->playsByNormalized as $normalizedName => $play) {
            if (mb_strpos($normalizedTitle, $normalizedName, 0, 'UTF-8') !== false
                || mb_strpos($normalizedName, $normalizedTitle, 0, 'UTF-8') !== false
            ) {
                return $play;
            }
        }

        return null;
    }
}
