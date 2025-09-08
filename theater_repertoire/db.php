<?php
require_once 'config.php';

// Функции для работы со спектаклями
function getAllPlays() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM plays ORDER BY short_name");
    return $stmt->fetchAll();
}

function getPlayByShortName($shortName) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM plays WHERE short_name = ?");
    $stmt->execute([$shortName]);
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

    if (isset($data['id']) && $data['id']) {
        // Обновление - проверяем, не конфликтует ли новое сокращение с другими записями
        if (checkPlayExists($data['short_name'], $data['id'])) {
            return ['success' => false, 'message' => 'Спектакль с таким сокращением уже существует'];
        }

        try {
            $stmt = $pdo->prepare("UPDATE plays SET short_name = ?, full_name = ?, wiki_link = ?, hall = ?, special_mark = ?, is_subscription = ? WHERE id = ?");
            $stmt->execute([$data['short_name'], $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription, $data['id']]);
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
            $stmt = $pdo->prepare("INSERT INTO plays (short_name, full_name, wiki_link, hall, special_mark, is_subscription) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['short_name'], $data['full_name'], $data['wiki_link'], $data['hall'], $specialMark, $isSubscription]);
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
?>
