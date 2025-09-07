<?php
require_once 'db.php';

class RepertoireParser {
    private $plays = [];

    public function __construct() {
        $this->plays = $this->loadPlays();
    }

    private function loadPlays() {
        $plays = getAllPlays();
        $playsMap = [];
        foreach ($plays as $play) {
            $playsMap[$play['short_name']] = $play;
        }
        return $playsMap;
    }

    public function parseRepertoire($text) {
        $lines = explode("\n", trim($text));
        $monthYear = '';
        $events = [];
        $unknownPlays = [];

        foreach ($lines as $line) {
            $originalLine = $line;
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) continue;

            // Первая строка - месяц и год
            if (strpos($trimmedLine, ' ') !== false && !preg_match('/^\d+/', $trimmedLine)) {
                $monthYear = $trimmedLine;
                continue;
            }

            // Сначала проверяем на строки продолжения (начинаются с пробела/табуляции)
            if (preg_match('/^[\s\t]+(\d+)[\s\t]+(.+?)[\s\t]+(\d+)[\s\t]*$/', $originalLine, $matches)) {
                $time = $matches[1];
                $shortName = trim($matches[2]);
                $code = $matches[3];

                // echo "РАСПОЗНАНА продолжение: время=$time, спектакль='$shortName', код=$code\n";

                if (!empty($events)) {
                    $lastEvent = end($events);
                    $date = $lastEvent['date'];

                    $play = $this->plays[$shortName] ?? null;
                    if ($play) {
                        $events[] = [
                            'date' => $date,
                            'time' => $time,
                            'play' => $play,
                            'code' => $code
                        ];
                        // echo "ДОБАВЛЕНО продолжение: {$play['full_name']} на $date\n";
                    } else {
                        if (!in_array($shortName, $unknownPlays)) {
                            $unknownPlays[] = $shortName;
                        }
                        // echo "НЕ НАЙДЕН спектакль: '$shortName'\n";
                    }
                } else {
                    // echo "НЕТ предыдущих событий для продолжения\n";
                }
            }
            // Парсим строку события: дата время сокращение код
            // Обрабатываем как табуляции, так и пробелы
            elseif (preg_match('/^(\d+)[\s\t]+(\d+)[\s\t]+(.+?)[\s\t]+(\d+)$/', $trimmedLine, $matches)) {
                $date = $matches[1];
                $time = $matches[2];
                $shortName = trim($matches[3]);
                $code = $matches[4];

                // echo "РАСПОЗНАНА полная: дата=$date, время=$time, спектакль='$shortName', код=$code\n";

                $play = $this->plays[$shortName] ?? null;
                if ($play) {
                    $events[] = [
                        'date' => $date,
                        'time' => $time,
                        'play' => $play,
                        'code' => $code
                    ];
                    // echo "ДОБАВЛЕНА полная: {$play['full_name']}\n";
                } else {
                    if (!in_array($shortName, $unknownPlays)) {
                        $unknownPlays[] = $shortName;
                    }
                    // echo "НЕ НАЙДЕН спектакль: '$shortName'\n";
                }
            } else {
                // Отладка: если строка не распознана ни одним паттерном
                // $visibleLine = str_replace("\t", "→", str_replace(" ", "·", $originalLine));
                // echo "НЕ РАСПОЗНАНА: '$visibleLine'\n";
            }
        }

        // Если есть неизвестные спектакли, возвращаем ошибку
        if (!empty($unknownPlays)) {
            return $this->generateErrorMessage($unknownPlays);
        }

        return $this->generateWikiMarkup($monthYear, $events);
    }

    private function generateWikiMarkup($monthYear, $events) {
        if (empty($events)) return '';

        $wiki = "== Репертуар на $monthYear ==\n\n";
        $wiki .= "'''ВНИМАНИЕ! В составах возможны изменения!''' \n\n";
        $wiki .= "{|\n";
        $wiki .= "|- \n";
        $wiki .= "| <center>'''Дата'''</center>\n";
        $wiki .= "| <center>'''Название спектакля'''</center>\n";
        $wiki .= "| <center>'''Зал'''</center>\n";

        // Группируем события по дате и названию спектакля для обработки дубликатов
        $groupedEvents = [];
        foreach ($events as $event) {
            $key = $event['date'] . '_' . $event['play']['short_name'];
            if (!isset($groupedEvents[$key])) {
                $groupedEvents[$key] = [];
            }
            $groupedEvents[$key][] = $event;
        }

        foreach ($events as $event) {
            $wiki .= "|- \n";

            // Форматируем дату со временем
            $dateStr = $this->formatDate($event['date'], $monthYear, $event['time']);
            $wiki .= "| $dateStr\n";

            // Название спектакля с ссылкой
            $playName = $event['play']['full_name'];
            $specialMark = $event['play']['special_mark'] ?? '';
            $isSubscription = $event['play']['is_subscription'] ?? 0;

            // Формируем полное название с специальной отметкой
            $fullPlayName = $specialMark ? "$specialMark $playName" : $playName;

            if ($isSubscription) {
                // Для абонементов - внешняя ссылка на покупку билетов
                $ticketLink = "[[https://www.zazerkal.spb.ru/tickets/{$event['code']}.htm|купить билет]]";
                $wiki .= "| '''Клуб юных петербуржцев $fullPlayName''' \n<br/>'''$ticketLink'''\n";
            } else {
                // Для обычных спектаклей - ссылка "в ролях"
                $wikiLink = $event['play']['wiki_link'];

                // Проверяем, есть ли другие спектакли с тем же названием в этот день
                $key = $event['date'] . '_' . $event['play']['short_name'];
                $sameDayEvents = $groupedEvents[$key];
                $hasMultiple = count($sameDayEvents) > 1;

                // Форматируем дату для ссылки: DD.MM.YY
                $parts = explode(' ', $monthYear);
                $monthNum = $this->getMonthNumber($parts[0]);
                $yearShort = substr($parts[1] ?? '', -2);

                $dateForLink = sprintf('%02d.%02d.%s', $event['date'], $monthNum, $yearShort);
                $linkName = $wikiLink . '_' . $dateForLink;

                // Если несколько спектаклей в один день, добавляем время к ссылке
                if ($hasMultiple) {
                    $linkName .= '_' . $event['time'];
                }

                $link = "[[$linkName|в ролях]]";
                $wiki .= "| '''$fullPlayName''' \n<br/>$link\n";
            }

            // Зал
            $wiki .= "| {$event['play']['hall']}\n";
        }

        $wiki .= "|}\n";

        return $wiki;
    }

    private function generateErrorMessage($unknownPlays) {
        $error = "❌ ОШИБКА: В базе данных не найдены следующие спектакли:\n\n";
        $error .= "Неизвестные сокращения:\n";
        foreach ($unknownPlays as $play) {
            $error .= "• \"$play\"\n";
        }
        $error .= "\nПожалуйста, добавьте эти спектакли в разделе \"Управление спектаклями\" перед созданием афиши.\n\n";
        $error .= "Для каждого спектакля укажите:\n";
        $error .= "• Сокращение (например: \"$play\")\n";
        $error .= "• Полное название с вики-разметкой\n";
        $error .= "• Ссылку для вики\n";
        $error .= "• Зал проведения\n";
        $error .= "• Специальную отметку (если нужно)\n";
        $error .= "• Тип спектакля (обычный или абонемент)\n";

        return $error;
    }

    private function formatDate($date, $monthYear, $time) {
        // Разбираем месяц и год
        $parts = explode(' ', $monthYear);
        $month = $this->getMonthName($parts[0]);
        $year = $parts[1] ?? '';

        // Форматируем время: поддерживаем как HH, так и HHMM форматы
        $formattedTime = $this->formatTime($time);

        return "$date $month, $formattedTime";
    }

    private function formatTime($time) {
        $timeStr = (string)$time;

        // Если время в формате HHMM (например, 1530)
        if (strlen($timeStr) === 4) {
            $hours = substr($timeStr, 0, 2);
            $minutes = substr($timeStr, 2, 2);
            return sprintf('%02d:%02d', $hours, $minutes);
        }
        // Если время в формате HH (например, 15)
        elseif (strlen($timeStr) === 2 || strlen($timeStr) === 1) {
            return sprintf('%02d:00', (int)$timeStr);
        }
        // На всякий случай, если формат неожиданный
        else {
            return sprintf('%02d:00', (int)$timeStr);
        }
    }

    private function getMonthName($month) {
        $months = [
            'январь' => 'января',
            'февраль' => 'февраля',
            'март' => 'марта',
            'апрель' => 'апреля',
            'май' => 'мая',
            'июнь' => 'июня',
            'июль' => 'июля',
            'август' => 'августа',
            'сентябрь' => 'сентября',
            'октябрь' => 'октября',
            'ноябрь' => 'ноября',
            'декабрь' => 'декабря'
        ];
        return $months[strtolower($month)] ?? $month;
    }

    private function getMonthNumber($month) {
        $months = [
            'январь' => 1,
            'февраль' => 2,
            'март' => 3,
            'апрель' => 4,
            'май' => 5,
            'июнь' => 6,
            'июль' => 7,
            'август' => 8,
            'сентябрь' => 9,
            'октябрь' => 10,
            'ноябрь' => 11,
            'декабрь' => 12
        ];
        return $months[strtolower($month)] ?? 1;
    }

    public function generatePlayPage($shortName, $code) {
        $play = $this->plays[$shortName] ?? null;
        if (!$play) return '';

        $template = getTemplateByPlayId($play['id']);
        if (!$template) {
            return "==В ролях:==\n''СОСТАВ УТОЧНЯЕТСЯ''\n\n'''[https://www.zazerkal.spb.ru/tickets/{$code}.htm|КУПИТЬ БИЛЕТ]'''";
        }

        return str_replace('{CODE}', $code, $template['template_text']);
    }
}

// Вспомогательная функция для использования парсера
function parseRepertoireText($text) {
    $parser = new RepertoireParser();
    return $parser->parseRepertoire($text);
}

function generatePlayPageContent($shortName, $code) {
    $parser = new RepertoireParser();
    return $parser->generatePlayPage($shortName, $code);
}
?>
