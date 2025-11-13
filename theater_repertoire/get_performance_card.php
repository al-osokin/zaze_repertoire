<?php
require_once 'config.php';
require_once 'db.php';

function generatePerformanceCardData(int $performanceId): array
{
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT er.event_date, er.event_time, p.site_title, p.full_name
        FROM events_raw er
        LEFT JOIN plays p ON er.play_id = p.id
        WHERE er.id = ?
    ");
    $stmt->execute([$performanceId]);
    $data = $stmt->fetch();

    if (!$data) {
        return ['success' => false, 'message' => 'Представление не найдено'];
    }

    $card = buildPerformanceCard($performanceId, true);

    if (!$card['has_artists']) {
        $temzaStmt = $pdo->prepare("SELECT te.*, tt.play_id, p.site_title AS temza_site_title, p.full_name AS temza_full_name, er.ticket_code\n            FROM temza_events te\n            LEFT JOIN temza_titles tt ON tt.id = te.temza_title_id\n            LEFT JOIN plays p ON p.id = tt.play_id\n            LEFT JOIN events_raw er ON er.id = te.matched_event_id\n            WHERE te.matched_event_id = ?\n            ORDER BY te.scraped_at DESC, te.updated_at DESC, te.id DESC\n            LIMIT 1");
        $temzaStmt->execute([$performanceId]);
        $temzaEvent = $temzaStmt->fetch();
        if ($temzaEvent && !empty($temzaEvent['id']) && !empty($temzaEvent['play_id'])) {
            $temzaCard = buildTemzaEventCardText(
                (int)$temzaEvent['id'],
                (int)$temzaEvent['play_id'],
                $temzaEvent['ticket_code'] ?? null,
                [
                    'responsibles_json' => $temzaEvent['responsibles_json'] ?? null,
                    'called_json' => $temzaEvent['called_json'] ?? null,
                ]
            );
            if (!empty($temzaCard['text'])) {
                return [
                    'success' => true,
                    'text' => $temzaCard['text'],
                    'play_name' => formatPlayTitle($temzaEvent['temza_site_title'] ?? null, $temzaEvent['temza_full_name'] ?? null),
                    'event_date' => $temzaEvent['event_date'] ?? $data['event_date'],
                    'event_time' => $temzaEvent['start_time'] ?? $data['event_time'],
                ];
            }
        }

        $playName = formatPlayTitle($data['site_title'] ?? null, $data['full_name'] ?? null);
        return [
            'success' => false,
            'message' => 'Карточка ещё не сгенерирована для этого представления',
            'play_name' => $playName
        ];
    }

    return [
        'success' => true,
        'text' => $card['text'],
        'play_name' => formatPlayTitle($data['site_title'] ?? null, $data['full_name'] ?? null),
        'event_date' => $data['event_date'],
        'event_time' => $data['event_time']
    ];
}

// If the script is called directly, process the request and output JSON
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    requireAuth();
    header('Content-Type: application/json');

    if (!isset($_GET['performance_id'])) {
        echo json_encode(['success' => false, 'message' => 'Не указан performance_id']);
        exit;
    }

    $performanceId = (int)$_GET['performance_id'];
    $result = generatePerformanceCardData($performanceId);
    echo json_encode($result);
}
