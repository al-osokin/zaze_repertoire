<?php
require_once 'config.php';
require_once 'db.php';
require_once 'app/Models/PlayTemplateParser.php'; // Подключаем парсер для возможного использования

requireAuth();

function normalizePlaceholderSignature(string $text): string {
    if ($text === '') {
        return '';
    }
    $upper = mb_strtoupper(trim($text), 'UTF-8');
    $normalized = preg_replace('/[\s\-]+/u', '', $upper);
    return $normalized ?? '';
}

$pdo = getDBConnection();
$customNamePlaceholder = '-- СОСТАВ УТОЧНЯЕТСЯ --';
$customNamePlaceholderSignature = normalizePlaceholderSignature($customNamePlaceholder);
$artistTypeLabels = [
    'artist' => 'Артист',
    'conductor' => 'Дирижёр',
    'pianist' => 'Концертмейстер',
    'group' => 'Ансамбль / коллектив'
];
$performanceId = $_GET['performance_id'] ?? null;
$message = '';

if (!$performanceId) {
    die("ID представления не указан.");
}

// 1. Получаем информацию о представлении
$stmt = $pdo->prepare("
    SELECT
        er.id AS performance_id, er.event_date, er.event_time, er.play_id,
        p.full_name AS play_name
    FROM events_raw er
    JOIN plays p ON er.play_id = p.id
    WHERE er.id = ?
");
$stmt->execute([$performanceId]);
$performance = $stmt->fetch();

if (!$performance) {
    die("Представление не найдено.");
}

// 2. Получаем все роли для данного спектакля
$rolesStmt = $pdo->prepare("SELECT * FROM roles WHERE play_id = ? ORDER BY sort_order");
$rolesStmt->execute([$performance['play_id']]);
$roles = $rolesStmt->fetchAll();

// 3. Получаем уже назначенных артистов для этого представления
$assignedStmt = $pdo->prepare("
    SELECT role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time
    FROM performance_roles_artists
    WHERE performance_id = ?
    ORDER BY role_id, sort_order_in_role
");
$assignedStmt->execute([$performanceId]);
$assignedArtists = [];
$hasRealAssignments = false;
foreach ($assignedStmt->fetchAll() as $item) {
    $assignedArtists[$item['role_id']][] = $item;

    if ($hasRealAssignments) {
        continue;
    }

    $customName = trim((string)($item['custom_artist_name'] ?? ''));
    if (!empty($item['artist_id']) || ($customName !== '' && normalizePlaceholderSignature($customName) !== $customNamePlaceholderSignature)) {
        $hasRealAssignments = true;
    }
}

if (!$hasRealAssignments) {
    $assignedArtists = [];
}

// Если для этого представления еще нет назначенных ролей,
// подготавливаем данные для формы на основе последнего сохраненного состава или шаблона,
// но НЕ СОХРАНЯЕМ их в базу данных до нажатия кнопки "Сохранить состав".
if (empty($assignedArtists)) {
    $playId = $performance['play_id'];
    $defaultsStmt = $pdo->prepare("
        SELECT role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time
        FROM play_role_last_cast
        WHERE play_id = ?
        ORDER BY role_id, sort_order_in_role
    ");
    $defaultsStmt->execute([$playId]);
    $defaultCast = $defaultsStmt->fetchAll();

    if (empty($defaultCast)) {
        $defaultCast = fetchLastPerformanceCast($pdo, (int)$playId, (int)$performanceId, $customNamePlaceholderSignature);
    }

    if (!empty($defaultCast)) {
        // Используем последний сохраненный состав для этого спектакля
        foreach ($defaultCast as $item) {
            $assignedArtists[$item['role_id']][] = $item;
        }
    } else {
        // Если нет "последнего состава", используем пустые роли из шаблона
        foreach ($roles as $role) {
            $assignedArtists[$role['role_id']][] = [
                'role_id' => $role['role_id'],
                'artist_id' => null,
                'custom_artist_name' => '',
                'sort_order_in_role' => 0,
                'is_first_time' => 0
            ];
        }
    }
}

// 4. Получаем всех артистов для выпадающих списков
$allArtistsStmt = $pdo->query("SELECT artist_id, first_name, last_name, type FROM artists ORDER BY last_name, first_name");
$allArtists = $allArtistsStmt->fetchAll();
$allArtistsByTypeList = [];
foreach ($allArtists as $artist) {
    $typeKey = $artist['type'] ?? '';
    $allArtistsByTypeList[$typeKey][] = $artist;
}

$roleArtistExclusions = [
    // 264 => [170], // пример исключений ролей
];

// Функция для получения и сортировки артистов для конкретной роли
function getArtistsForRole($pdo, $roleId, $artistType, $allArtistsByTypeList, array $roleArtistExclusions = [], $selectedArtistId = null) {
    $typesForRole = [$artistType];
    if ($artistType === 'artist') {
        $typesForRole[] = 'group';
    }

    $typePlaceholders = implode(',', array_fill(0, count($typesForRole), '?'));

    // Получаем частых артистов для этой роли
    $frequentArtistsStmt = $pdo->prepare("
        SELECT a.artist_id, a.first_name, a.last_name
        FROM role_artist_history rah
        JOIN artists a ON rah.artist_id = a.artist_id
        WHERE rah.role_id = ? AND a.type IN ($typePlaceholders)
        ORDER BY rah.assignment_count DESC, rah.last_assigned_date DESC
    ");
    $frequentArtistsStmt->execute(array_merge([$roleId], $typesForRole));
    $frequentArtists = $frequentArtistsStmt->fetchAll();

    $excludedIds = $roleArtistExclusions[$roleId] ?? [];
    if (!empty($excludedIds)) {
        $frequentArtists = array_values(array_filter(
            $frequentArtists,
            fn($artist) => !in_array($artist['artist_id'], $excludedIds, true)
        ));
    }

    // Получаем всех артистов нужного типа, отсортированных по фамилии
    $allArtistsOfType = [];
    foreach ($typesForRole as $typeKey) {
        if (!empty($allArtistsByTypeList[$typeKey])) {
            $allArtistsOfType = array_merge($allArtistsOfType, $allArtistsByTypeList[$typeKey]);
        }
    }
    
    // Создаем копию массива для сортировки, чтобы не изменять исходный
    $sortedArtists = $allArtistsOfType;
    usort($sortedArtists, function($a, $b) {
        return strcmp($a['last_name'], $b['last_name']);
    });

    $frequentArtistIds = array_map(fn($a) => $a['artist_id'], $frequentArtists);

    // Фильтруем всех артистов, чтобы убрать тех, кто уже в списке частых
    $otherArtists = array_filter(
        $sortedArtists,
        fn($a) => !in_array($a['artist_id'], $frequentArtistIds, true)
            && (empty($excludedIds) || !in_array($a['artist_id'], $excludedIds, true))
    );

    // Объединяем: частые артисты + остальные артисты (по алфавиту)
    $orderedArtists = array_merge($frequentArtists, $otherArtists);

    if ($selectedArtistId) {
        $orderedArtists = prioritizeSelectedArtist($orderedArtists, (int)$selectedArtistId, (int)$roleId, $pdo);
    }

    return $orderedArtists;
}


// 5. Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_cast') {
        $pdo->beginTransaction();
        try {
            // Удаляем старые назначения
            $deleteStmt = $pdo->prepare("DELETE FROM performance_roles_artists WHERE performance_id = ?");
            $deleteStmt->execute([$performanceId]);

            $assignments = $_POST['roles'] ?? [];
            $insertAssignStmt = $pdo->prepare(
                "INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time) VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($assignments as $roleId => $artistsData) {
                foreach ($artistsData as $sortOrder => $artistInfo) {
                    $artistId = $artistInfo['artist_id'] ?? null;
                    $customName = trim($artistInfo['custom_name'] ?? '');
                    if ($customName !== '' && normalizePlaceholderSignature($customName) === $customNamePlaceholderSignature) {
                        $customName = '';
                    }

                    $isCreatingNewArtist = ($artistId === 'new_artist');
                    $hasArtistSelection = !empty($artistId) && !$isCreatingNewArtist;
                    if (!$hasArtistSelection && $customName === '') {
                        continue;
                    }

                    if ($isCreatingNewArtist && !empty($customName)) {
                        // Логика создания нового артиста
                        $type = $artistInfo['new_type'] ?? $artistInfo['expected_type'] ?? 'artist';
                        $type = array_key_exists($type, $artistTypeLabels) ? $type : 'artist';

                        if ($type === 'group') {
                            $firstName = '';
                            $lastName = $customName;
                        } else {
                            $parts = preg_split('/\s+/u', $customName);
                            $lastName = array_pop($parts);
                            $firstName = implode(' ', $parts);
                        }

                        $checkStmt = $pdo->prepare("SELECT artist_id FROM artists WHERE first_name = ? AND last_name = ? AND type = ?");
                        $checkStmt->execute([$firstName, $lastName, $type]);
                        $existingArtistId = $checkStmt->fetchColumn();

                        if ($existingArtistId) {
                            $artistId = $existingArtistId;
                            $isCreatingNewArtist = false;
                        } else {
                            $insertArtistStmt = $pdo->prepare("INSERT INTO artists (first_name, last_name, type) VALUES (?, ?, ?)");
                            $insertArtistStmt->execute([$firstName, $lastName, $type]);
                            $artistId = $pdo->lastInsertId();
                            $isCreatingNewArtist = false;
                        }
                        $customName = '';
                    }

                    $finalArtistId = ($isCreatingNewArtist || $artistId === '') ? null : $artistId;
                    $isFirstTime = !empty($artistInfo['first_time']) ? 1 : 0;

                    $customNameForInsert = ($customName === '') ? null : $customName;
                    $insertAssignStmt->execute([$performanceId, $roleId, $finalArtistId, $customNameForInsert, $sortOrder, $isFirstTime]);

                    // Обновляем историю
                    if ($artistId && $artistId !== 'new_artist' && is_numeric($artistId)) {
                         $historyStmt = $pdo->prepare("
                            INSERT INTO role_artist_history (role_id, artist_id, assignment_count, last_assigned_date)
                            VALUES (?, ?, 1, NOW())
                            ON DUPLICATE KEY UPDATE assignment_count = assignment_count + 1, last_assigned_date = NOW();
                        ");
                        $historyStmt->execute([$roleId, $artistId]);
                    }
                }
            }

            $pdo->commit();
            saveLastCastSnapshot($pdo, (int)$performanceId, (int)$performance['play_id'], $customNamePlaceholderSignature);
            $message = "Состав успешно сохранен!";
            // Перезагружаем данные после сохранения и добавляем якорь для прокрутки
            header("Location: edit_cast.php?performance_id=$performanceId&message=" . urlencode($message) . "&saved=1");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Ошибка сохранения: " . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'generate_card') {
        // Реализация генерации карточки
        $generatedWikiCard = generateVkCard($performanceId);
        updateVkPostText($performanceId, $generatedWikiCard);
        $message = "Карточка сгенерирована и сохранена.";
        header("Location: edit_cast.php?performance_id=$performanceId&message=" . urlencode($message) . "&show_card=1");
        exit;
    }
}

// Генерация Wiki-карточки (для отображения при загрузке страницы, если есть show_card)
$cardRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_card');
$generatedWikiCard = '';
if ($cardRequest || isset($_GET['show_card'])) {
    $cardData = buildPerformanceCard($performanceId, true);

    if (!$cardData['has_artists']) {
        $message = "Состав не заполнен, карточку генерировать не из чего.";
    } else {
        $generatedWikiCard = $cardData['text'];
        // updateVkPostText($performanceId, $generatedWikiCard); // Уже сделано выше при POST-запросе

        // Логика сохранения состава по умолчанию (play_role_last_cast)
        try {
            $pdo->beginTransaction();

            $deleteDefaultsStmt = $pdo->prepare("DELETE FROM play_role_last_cast WHERE play_id = ?");
            $deleteDefaultsStmt->execute([$performance['play_id']]);

            $currentAssignmentsStmt = $pdo->prepare("
                SELECT role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time
                FROM performance_roles_artists
                WHERE performance_id = ?
                ORDER BY role_id, sort_order_in_role
            ");
            $currentAssignmentsStmt->execute([$performanceId]);

            $insertDefaultStmt = $pdo->prepare("
                INSERT INTO play_role_last_cast (play_id, role_id, sort_order_in_role, artist_id, custom_artist_name, is_first_time)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($currentAssignmentsStmt->fetchAll() as $assignment) {
                $assignmentCustomName = trim($assignment['custom_artist_name'] ?? '');
                if ($assignmentCustomName !== '' && normalizePlaceholderSignature($assignmentCustomName) === $customNamePlaceholderSignature) {
                    $assignmentCustomName = '';
                }
                if (empty($assignment['artist_id']) && $assignmentCustomName === '') {
                    continue;
                }
                $insertDefaultStmt->execute([
                    $performance['play_id'],
                    $assignment['role_id'],
                    $assignment['sort_order_in_role'],
                    $assignment['artist_id'],
                    $assignmentCustomName === '' ? null : $assignmentCustomName,
                    $assignment['is_first_time']
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Не удалось сохранить состав по умолчанию для спектакля {$performance['play_id']}: " . $e->getMessage());
        }

        // $message = "Карточка сгенерирована и сохранена."; // Уже установлено выше
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактирование состава</title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Редактировать состав</h1>
        <h2><?php echo htmlspecialchars($performance['play_name']); ?> (<?php echo date('d.m.Y H:i', strtotime($performance['event_date'] . ' ' . $performance['event_time'])); ?>)</h2>
        <a href="schedule.php" class="btn-secondary">Назад к афише</a>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="message"><?php echo htmlspecialchars($_GET['message']); ?></div>
    <?php elseif (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="section">
        <form method="post">
            <input type="hidden" name="action" value="save_cast">
            <table>
                <thead>
                    <tr>
                        <th>Роль</th>
                        <th>Исполнители</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($role['role_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($role['role_description'] ?? ''); ?></small>
                            </td>
                            <td id="role-<?php echo $role['role_id']; ?>">
                                <?php
                                $currentArtists = $assignedArtists[$role['role_id']] ?? [['artist_id' => null, 'custom_artist_name' => '', 'is_first_time' => 0]];
                                foreach ($currentArtists as $idx => $assigned):
                                ?>
                                <?php
                                $shouldShowCustom = !empty($assigned['custom_artist_name']) && empty($assigned['artist_id']);
                                $expectedType = $role['expected_artist_type'] ?? 'artist';
                                if (!isset($artistTypeLabels[$expectedType])) {
                                    $expectedType = 'artist';
                                }
                                $newTypeInitial = $expectedType;
                                ?>
                                <div class="artist-row" style="margin-bottom: 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <select name="roles[<?php echo $role['role_id']; ?>][<?php echo $idx; ?>][artist_id]" class="artist-select">
                                        <option value="">-- СОСТАВ УТОЧНЯЕТСЯ --</option>
                                        <?php
                                        $artistsForRole = getArtistsForRole($pdo, $role['role_id'], $role['expected_artist_type'], $allArtistsByTypeList, $roleArtistExclusions, $assigned['artist_id'] ?? null);
                                        foreach ($artistsForRole as $artist): ?>
                                            <option value="<?php echo $artist['artist_id']; ?>" <?php echo ($assigned['artist_id'] == $artist['artist_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($artist['last_name'] . ' ' . $artist['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="new_artist">-- Новый артист --</option>
                                    </select>
                                    <input type="text" class="custom-artist-input" name="roles[<?php echo $role['role_id']; ?>][<?php echo $idx; ?>][custom_name]" placeholder="Введите имя, фамилию или группу" style="<?php echo $shouldShowCustom ? 'display:inline-block;' : 'display:none;'; ?>" value="<?php echo htmlspecialchars($assigned['custom_artist_name'] ?? ''); ?>">
                                    <input type="hidden" class="expected-type" name="roles[<?php echo $role['role_id']; ?>][<?php echo $idx; ?>][expected_type]" value="<?php echo $role['expected_artist_type']; ?>">
                                    <select name="roles[<?php echo $role['role_id']; ?>][<?php echo $idx; ?>][new_type]" class="new-artist-type" style="<?php echo $shouldShowCustom ? 'display:inline-block;' : 'display:none;'; ?>">
                                        <?php foreach ($artistTypeLabels as $typeValue => $typeLabel): ?>
                                            <option value="<?php echo $typeValue; ?>" <?php echo ($newTypeInitial === $typeValue) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($typeLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label style="font-size: 0.9em; display: inline-flex; align-items: center; gap: 4px;">
                                        <input type="checkbox" name="roles[<?php echo $role['role_id']; ?>][<?php echo $idx; ?>][first_time]" value="1" <?php echo !empty($assigned['is_first_time']) ? 'checked' : ''; ?>>
                                        <span>впервые в роли</span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                                <button type="button" class="btn-secondary btn-add-artist" data-role-id="<?php echo $role['role_id']; ?>">+ Добавить исполнителя</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="buttons" style="margin-top: 20px;">
                <button type="submit" class="btn-primary">Сохранить состав</button>
            </div>
        </form>
    </div>

    <div class="section" id="card-section">
        <h2>Сгенерировать карточку</h2>
        <form method="post">
            <input type="hidden" name="action" value="generate_card">
            <button type="submit" class="btn-primary">Сгенерировать и сохранить карточку</button>
        </form>

        <?php if ($generatedWikiCard): ?>
        <div style="margin-top: 20px;">
            <textarea id="wiki_card_output" readonly rows="10" style="width: 100%; font-family: monospace;"><?php echo htmlspecialchars($generatedWikiCard); ?></textarea>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="copyToClipboard('wiki_card_output')">Скопировать</button>
                    <button type="button"
                            class="btn-info"
                            onclick="publishCardToVK(<?php echo (int)$performanceId; ?>, <?php echo htmlspecialchars(json_encode($performance['play_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)">
                        Опубликовать в VK
                    </button>
                </div>
                <a href="schedule.php" class="btn-secondary">Назад к афише</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('btn-add-artist')) {
        const roleId = e.target.dataset.roleId;
        const container = document.getElementById('role-' + roleId);
        const artistRows = container.querySelectorAll('.artist-row');
        const newIndex = artistRows.length;
        const newRow = artistRows[0].cloneNode(true);

        // Очищаем значения в новой строке
        const select = newRow.querySelector('.artist-select');
        const textInput = newRow.querySelector('.custom-artist-input');
        const hiddenInput = newRow.querySelector('.expected-type');
        const checkbox = newRow.querySelector('input[type=checkbox]');
        const typeSelect = newRow.querySelector('.new-artist-type');

        if (select) {
            select.name = `roles[${roleId}][${newIndex}][artist_id]`;
            select.selectedIndex = 0;
        }

        if (textInput) {
            textInput.name = `roles[${roleId}][${newIndex}][custom_name]`;
            textInput.value = '';
            textInput.style.display = 'none';
        }

        if (hiddenInput) {
            hiddenInput.name = `roles[${roleId}][${newIndex}][expected_type]`;
        }

        if (typeSelect) {
            typeSelect.name = `roles[${roleId}][${newIndex}][new_type]`;
            typeSelect.value = hiddenInput ? hiddenInput.value : 'artist';
            typeSelect.style.display = 'none';
        }

        if (checkbox) {
            checkbox.name = `roles[${roleId}][${newIndex}][first_time]`;
            checkbox.checked = false;
        }

        container.insertBefore(newRow, e.target);
    }
});

document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('artist-select')) {
        const row = e.target.closest('.artist-row');
        const customNameInput = row ? row.querySelector('.custom-artist-input') : null;
        const typeSelect = row ? row.querySelector('.new-artist-type') : null;
        const expectedTypeInput = row ? row.querySelector('.expected-type') : null;
        if (e.target.value === 'new_artist') {
            if (customNameInput) {
                customNameInput.style.display = 'inline-block';
            }
            if (typeSelect) {
                typeSelect.style.display = 'inline-block';
                typeSelect.value = expectedTypeInput ? expectedTypeInput.value : 'artist';
            }
        } else {
            if (customNameInput) {
                customNameInput.style.display = 'none';
                customNameInput.value = '';
            }
            if (typeSelect) {
                typeSelect.style.display = 'none';
                typeSelect.value = expectedTypeInput ? expectedTypeInput.value : 'artist';
            }
        }
    }
});

function copyToClipboard(elementId) {
    const textArea = document.getElementById(elementId);
    if (!textArea) return;

    const value = textArea.value.trim();
    if (!value) {
        showToast('Нет текста для копирования', 'error');
        return;
    }

    const copyPromise = (navigator.clipboard && typeof navigator.clipboard.writeText === 'function')
        ? navigator.clipboard.writeText(value)
        : new Promise((resolve, reject) => {
            const temp = document.createElement('textarea');
            temp.value = value;
            temp.setAttribute('readonly', '');
            temp.style.position = 'absolute';
            temp.style.left = '-9999px';
            document.body.appendChild(temp);
            temp.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(temp);
            ok ? resolve() : reject(new Error('не удалось скопировать текст'));
        });

    copyPromise.then(() => {
        showToast('Текст карточки скопирован', 'success');
    }).catch((err) => {
        console.error(err);
        showToast(`Ошибка: ${err.message || err}`, 'error');
    });
}

async function publishCardToVK(performanceId, playName = '') {
    if (!performanceId) {
        showToast('Не указан performance_id', 'error');
        return;
    }

    try {
        const response = await fetch('publish_to_vk.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ performance_id: performanceId })
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            const name = playName || data.play_name || 'спектакль';
            showToast(`Карточка для "${name}" опубликована!`, 'success');
        } else {
            showToast(data.message || 'Ошибка публикации', 'error');
        }
    } catch (error) {
        console.error(error);
        showToast(`Ошибка публикации: ${error.message}`, 'error');
    }
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

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

<?php if ($cardRequest || isset($_GET['show_card']) || isset($_GET['saved'])): ?>
window.addEventListener('load', () => {
    const section = document.getElementById('card-section');
    if (section) {
        section.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
});
<?php endif; ?>
</script>

</body>
</html>
<?php

function saveLastCastSnapshot(PDO $pdo, int $performanceId, int $playId, string $placeholderSignature): void
{
    try {
        $pdo->beginTransaction();

        $deleteDefaultsStmt = $pdo->prepare("DELETE FROM play_role_last_cast WHERE play_id = ?");
        $deleteDefaultsStmt->execute([$playId]);

        $currentAssignmentsStmt = $pdo->prepare("
            SELECT role_id, artist_id, custom_artist_name, sort_order_in_role, is_first_time
            FROM performance_roles_artists
            WHERE performance_id = ?
            ORDER BY role_id, sort_order_in_role
        ");
        $currentAssignmentsStmt->execute([$performanceId]);

        $insertDefaultStmt = $pdo->prepare("
            INSERT INTO play_role_last_cast (play_id, role_id, sort_order_in_role, artist_id, custom_artist_name, is_first_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($currentAssignmentsStmt->fetchAll() as $assignment) {
            $assignmentCustomName = trim((string)($assignment['custom_artist_name'] ?? ''));
            if ($assignmentCustomName !== '' && normalizePlaceholderSignature($assignmentCustomName) === $placeholderSignature) {
                $assignmentCustomName = '';
            }

            if (empty($assignment['artist_id']) && $assignmentCustomName === '') {
                continue;
            }

            $insertDefaultStmt->execute([
                $playId,
                $assignment['role_id'],
                $assignment['sort_order_in_role'],
                $assignment['artist_id'],
                $assignmentCustomName === '' ? null : $assignmentCustomName,
                $assignment['is_first_time']
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Не удалось сохранить состав по умолчанию для спектакля {$playId}: " . $e->getMessage());
    }
}

/**
 * Берёт состав последнего сыгранного представления спектакля.
 *
 * @return array<int,array<string,mixed>>
 */
function fetchLastPerformanceCast(PDO $pdo, int $playId, int $currentPerformanceId, string $placeholderSignature): array
{
    $stmt = $pdo->prepare("
        SELECT
            er.id AS performance_id,
            er.event_date,
            er.event_time,
            pra.role_id,
            pra.artist_id,
            pra.custom_artist_name,
            pra.sort_order_in_role,
            pra.is_first_time
        FROM events_raw er
        JOIN performance_roles_artists pra ON pra.performance_id = er.id
        WHERE er.play_id = ?
        ORDER BY er.event_date DESC, er.event_time DESC, er.id DESC, pra.role_id, pra.sort_order_in_role
    ");
    $stmt->execute([$playId]);

    $buffer = [];
    $currentId = null;
    $hasReal = false;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $perfId = (int)$row['performance_id'];
        if ($perfId === $currentPerformanceId) {
            continue;
        }

        if ($currentId === null) {
            $currentId = $perfId;
        }

        if ($perfId !== $currentId) {
            if ($hasReal) {
                return $buffer;
            }
            $buffer = [];
            $hasReal = false;
            $currentId = $perfId;
        }

        $customName = trim((string)($row['custom_artist_name'] ?? ''));
        $isReal = !empty($row['artist_id']) || ($customName !== '' && normalizePlaceholderSignature($customName) !== $placeholderSignature);
        if ($isReal) {
            $hasReal = true;
        }

        $buffer[] = [
            'role_id' => (int)$row['role_id'],
            'artist_id' => $row['artist_id'],
            'custom_artist_name' => $customName,
            'sort_order_in_role' => (int)$row['sort_order_in_role'],
            'is_first_time' => (int)$row['is_first_time'],
        ];
    }

    if ($hasReal && !empty($buffer)) {
        return $buffer;
    }

    return [];
}

/**
 * Перемещает выбранного артиста в начало списка, чтобы select сразу фокусировался на нем.
 *
 * @param array<int,array<string,mixed>> $artists
 * @return array<int,array<string,mixed>>
 */
function prioritizeSelectedArtist(array $artists, int $selectedArtistId, int $roleId, PDO $pdo): array
{
    foreach ($artists as $index => $artist) {
        if ((int)($artist['artist_id'] ?? 0) === $selectedArtistId) {
            if ($index === 0) {
                return $artists;
            }
            $selected = $artists[$index];
            unset($artists[$index]);
            array_unshift($artists, $selected);
            return array_values($artists);
        }
    }

    $stmt = $pdo->prepare('SELECT artist_id, first_name, last_name FROM artists WHERE artist_id = ?');
    $stmt->execute([$selectedArtistId]);
    $artistRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($artistRow) {
        $artistRow['type'] = $artistRow['type'] ?? 'artist';
        array_unshift($artists, [
            'artist_id' => (int)$artistRow['artist_id'],
            'first_name' => $artistRow['first_name'] ?? '',
            'last_name' => $artistRow['last_name'] ?? '',
        ]);
    }

    return $artists;
}
