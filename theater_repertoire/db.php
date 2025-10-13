<?php
require_once 'config.php';

// Функции для работы со спектаклями
function getAllPlays() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM plays ORDER BY site_title");
    return $stmt->fetchAll();
}

function getPlayByShortName($shortName) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM plays WHERE short_name = ?");
    $stmt->execute([$shortName]);
    return $stmt->fetch();
}

function getPlayById($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM plays WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function checkPlayExists($shortName, $excludeId = null) {
    $pdo = getDBConnection();
    if ($excludeId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM plays WHERE short_name = ? AND id != ?");
        $stmt->execute([$shortName, $excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM plays WHERE short_name = ?");
        $stmt->execute([$shortName]);
    }
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

function savePlay($data) {
    $pdo = getDBConnection();
    $isSubscription = isset($data['is_subscription']) ? (int)$data['is_subscription'] : 0;
    $specialMark = $data['special_mark'] ?? '';
    $siteTitle = $data['site_title'] ?? null;
    if ($siteTitle !== null && $siteTitle === '') {
        $siteTitle = null;
    }

    if (isset($data['id']) && $data['id']) {
        // Обновление - проверяем, не конфликтует ли новое сокращение с другими записями
        if (checkPlayExists($data['short_name'], $data['id'])) {
            return ['success' => false, 'message' => 'Спектакль с таким сокращением уже существует'];
        }

        try {
            $stmt = $pdo->prepare("UPDATE plays SET short_name = ?, site_title = ?, full_name = ?, wiki_link = ?, hall = ?, special_mark = ?, is_subscription = ? WHERE id = ?");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription, $data['id']]);
            return ['success' => true, 'message' => 'Спектакль обновлён'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Ошибка при обновлении спектакля: ' . $e->getMessage()];
        }
    } else {
        // Создание - проверяем, не существует ли уже спектакль с таким сокращением
        if (checkPlayExists($data['short_name'])) {
            return ['success' => false, 'message' => 'Спектакль с таким сокращением уже существует'];
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO plays (short_name, site_title, full_name, wiki_link, hall, special_mark, is_subscription) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['short_name'], $siteTitle, $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription]);
            return ['success' => true, 'message' => 'Спектакль добавлен'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Ошибка при добавлении спектакля: ' . $e->getMessage()];
        }
    }
}

function deletePlay($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM plays WHERE id = ?");
    $stmt->execute([$id]);
}

// Функции для шаблонов
function getTemplateByPlayId($playId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM play_templates WHERE play_id = ?");
    $stmt->execute([$playId]);
    return $stmt->fetch();
}

function saveTemplate($playId, $templateText) {
    $pdo = getDBConnection();
    $existing = getTemplateByPlayId($playId);

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE play_templates SET template_text = ? WHERE play_id = ?");
        $stmt->execute([$templateText, $playId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO play_templates (play_id, template_text) VALUES (?, ?)");
        $stmt->execute([$playId, $templateText]);
    }
}

// Функции для истории
function saveRepertoireHistory($monthYear, $sourceText, $resultWiki) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO repertoire_history (month_year, source_text, result_wiki) VALUES (?, ?, ?)");
    $stmt->execute([$monthYear, $sourceText, $resultWiki]);
    return $pdo->lastInsertId();
}

function getRepertoireHistory($limit = 10) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM repertoire_history ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getRepertoireById($id) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM repertoire_history WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Функции аутентификации
function authenticateUser($username, $password) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        return true;
    }
    return false;
}

function getCurrentUser() {
    return $_SESSION['username'] ?? null;
}

// === Работа с распаршенными событиями афиши ===

function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function clearEventsRawByMonthYear($month, $year) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM events_raw WHERE month = ? AND year = ?");
    $stmt->execute([(int)$month, (int)$year]);
}

function insertRawEvent($eventData) {
    $pdo = getDBConnection();
    $columns = [
        'batch_token', 'event_date', 'event_time', 'title', 'normalized_title',
        'age_category', 'ticket_code', 'ticket_url', 'repertoire_url', 'background_url',
        'play_id', 'play_short_name', 'month', 'year'
    ];

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = sprintf(
        "INSERT INTO events_raw (%s) VALUES (%s)",
        implode(',', $columns),
        $placeholders
    );

    $stmt = $pdo->prepare($sql);
    $values = [];
    foreach ($columns as $column) {
        $values[] = $eventData[$column] ?? null;
    }
    $stmt->execute($values);
}

function getRawEventsByBatch($batchToken) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT er.*, p.full_name AS play_full_name FROM events_raw er LEFT JOIN plays p ON er.play_id = p.id WHERE er.batch_token = ? ORDER BY er.event_date, er.event_time");
    $stmt->execute([$batchToken]);
    return $stmt->fetchAll();
}

function getRawEventsByMonthYear($month, $year) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT er.*, p.full_name AS play_full_name FROM events_raw er LEFT JOIN plays p ON er.play_id = p.id WHERE er.month = ? AND er.year = ? ORDER BY er.event_date, er.event_time");
    $stmt->execute([(int)$month, (int)$year]);
    return $stmt->fetchAll();
}

function updateEventPlayMapping($eventId, $playId = null) {
    $pdo = getDBConnection();
    $playShortName = null;

    if ($playId) {
        $play = getPlayById($playId);
        if (!$play) {
            throw new RuntimeException('Указанный спектакль не найден');
        }
        $playShortName = $play['short_name'];
    }

    $stmt = $pdo->prepare("UPDATE events_raw SET play_id = ?, play_short_name = ? WHERE id = ?");
    $stmt->execute([$playId, $playShortName, $eventId]);
}
?>
