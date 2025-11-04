# План разработки пользовательского интерфейса

Этот документ описывает этапы разработки пользовательского интерфейса для администрирования афиши, включая страницы списка представлений и редактирования состава.

## Этап 4: Разработка Интерфейса Пользователя (PHP + HTML/CSS/JS)

**Задача:** Создать страницу для администрирования афиши, где можно будет выбрать представление, отредактировать состав и сгенерировать карточку.

### 4.1. Страница списка представлений на месяц

Предполагается, что у вас уже есть некая страница, которая отображает список спектаклей на месяц из `events_raw`. Нам нужно будет добавить ссылку/кнопку "Редактировать состав" для каждого представления.

**Файл:** `views/admin/month_schedule.php` (или аналогичный)

```php
<?php
// month_schedule.php - Пример отображения списка представлений

// Подключение к БД и другие инициализации
require_once __DIR__ . '/../../config/database.php'; // Пример пути к файлу конфигурации БД

$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$stmt = $pdo->prepare("
    SELECT
        er.id AS performance_id,
        er.event_date,
        er.event_time,
        er.title,
        er.ticket_code,
        p.full_name AS play_name,
        er.vk_post_text IS NOT NULL AS has_vk_card,
        (SELECT COUNT(*) FROM performance_roles_artists pra WHERE pra.performance_id = er.id) AS assigned_artists_count,
        (SELECT COUNT(*) FROM roles r WHERE r.play_id = er.play_id) AS total_roles_count
    FROM
        events_raw er
    JOIN
        plays p ON er.play_id = p.id
    WHERE
        er.month = ? AND er.year = ?
    ORDER BY
        er.event_date, er.event_time
");
$stmt->execute([$month, $year]);
$performances = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Афиша на <?php echo $month . '.' . $year; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css"> <!-- Ваш CSS -->
</head>
<body>
    <h1>Афиша на <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h1>
    <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 -1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 -1 month')); ?>">Предыдущий месяц</a>
    <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 +1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 +1 month')); ?>">Следующий месяц</a>

    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Время</th>
                <th>Спектакль</th>
                <th>Статус состава</th>
                <th>Карточка VK</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($performances as $performance): ?>
            <tr>
                <td><?php echo date('d.m.Y', strtotime($performance['event_date'])); ?></td>
                <td><?php echo date('H:i', strtotime($performance['event_time'])); ?></td>
                <td><?php echo htmlspecialchars($performance['play_name']); ?></td>
                <td>
                    <?php
                        if ($performance['total_roles_count'] == 0) {
                            echo "Роли не определены";
                        } elseif ($performance['assigned_artists_count'] == 0) {
                            echo "Не заполнено";
                        } elseif ($performance['assigned_artists_count'] < $performance['total_roles_count']) {
                            echo "Частично заполнено";
                        } else {
                            echo "Заполнено";
                        }
                    ?>
                </td>
                <td><?php echo $performance['has_vk_card'] ? 'Сформирована' : 'Нет'; ?></td>
                <td>
                    <a href="edit_cast.php?performance_id=<?php echo $performance['performance_id']; ?>">Редактировать состав</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
```

### 4.2. Диалоговая форма "Редактирование состава"

Это будет отдельная страница или модальное окно, куда пользователь переходит по ссылке "Редактировать состав".

**Файл:** `views/admin/edit_cast.php`

```php
<?php
// edit_cast.php

// Подключение к БД и другие инициализации
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Models/PlayTemplateParser.php'; // Подключаем парсер, если он нужен для инициализации

$performanceId = $_GET['performance_id'] ?? null;

if (!$performanceId) {
    die("ID представления не указан.");
}

try {
    // 1. Получаем информацию о представлении
    $stmt = $pdo->prepare("
        SELECT
            er.id AS performance_id,
            er.event_date,
            er.event_time,
            er.title,
            er.ticket_code,
            er.play_id,
            p.full_name AS play_name,
            pt.template_text AS play_template_text,
            er.vk_post_text
        FROM
            events_raw er
        JOIN
            plays p ON er.play_id = p.id
        LEFT JOIN
            play_templates pt ON p.id = pt.play_id
        WHERE
            er.id = ?
    ");
    $stmt->execute([$performanceId]);
    $performance = $stmt->fetch();

    if (!$performance) {
        die("Представление не найдено.");
    }

    // 2. Инициализация ролей для данного представления, если их еще нет
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM performance_roles_artists WHERE performance_id = ?");
    $stmt->execute([$performanceId]);
    $existingRolesCount = $stmt->fetchColumn();

    if ($existingRolesCount === 0 && $performance['play_template_text']) {
        // Если ролей для этого представления еще нет, парсим шаблон и заполняем
        $parser = new App\Models\PlayTemplateParser($pdo);
        $parser->parseTemplate($performance['play_id'], $performance['play_template_text']); // Этот вызов заполнит roles и history
        // Теперь нужно создать записи в performance_roles_artists с пустым составом
        $this->initializePerformanceRoles($pdo, $performance['performance_id'], $performance['play_id']);
    }

    // 3. Получаем список ролей для спектакля и назначенных на них артистов для данного представления
    $stmt = $pdo->prepare("
        SELECT
            r.role_id,
            r.role_name,
            r.role_description,
            r.expected_artist_type,
            pra.performance_role_artist_id,
            pra.artist_id,
            pra.custom_artist_name,
            pra.sort_order_in_role
        FROM
            roles r
        LEFT JOIN
            performance_roles_artists pra ON r.role_id = pra.role_id AND pra.performance_id = ?
        WHERE
            r.play_id = ?
        ORDER BY
            r.sort_order, r.role_id, pra.sort_order_in_role
    ");
    $stmt->execute([$performanceId, $performance['play_id']]);
    $assignedRoles = [];
    foreach ($stmt->fetchAll() as $row) {
        $roleKey = $row['role_id'];
        if (!isset($assignedRoles[$roleKey])) {
            $assignedRoles[$roleKey] = [
                'role_id' => $row['role_id'],
                'role_name' => $row['role_name'],
                'role_description' => $row['role_description'],
                'expected_artist_type' => $row['expected_artist_type'],
                'artists' => []
            ];
        }
        if ($row['performance_role_artist_id']) { // Если артист назначен
            $assignedRoles[$roleKey]['artists'][] = [
                'performance_role_artist_id' => $row['performance_role_artist_id'],
                'artist_id' => $row['artist_id'],
                'custom_artist_name' => $row['custom_artist_name'],
                'sort_order_in_role' => $row['sort_order_in_role']
            ];
        } else {
             // Если нет назначенных артистов, но роль существует, добавляем пустой слот
             if (empty($assignedRoles[$roleKey]['artists'])) {
                 $assignedRoles[$roleKey]['artists'][] = [
                     'performance_role_artist_id' => null,
                     'artist_id' => null,
                     'custom_artist_name' => 'СОСТАВ УТОЧНЯЕТСЯ',
                     'sort_order_in_role' => 0
                 ];
             }
        }
    }


    // 4. Получаем списки артистов для выпадающих списков
    $allArtistsStmt = $pdo->query("SELECT artist_id, first_name, last_name, type FROM artists ORDER BY last_name, first_name");
    $allArtists = $allArtistsStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC); // Группируем по type
    // $allArtists будет иметь вид: ['artist' => [...], 'conductor' => [...], ...]

    // 5. Обработка POST-запросов (сохранение состава, генерация карточки)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action']) && $_POST['action'] === 'save_cast') {
            // Реализация сохранения состава
            // Удаляем старые назначения для этого performance_id
            $deleteStmt = $pdo->prepare("DELETE FROM performance_roles_artists WHERE performance_id = ?");
            $deleteStmt->execute([$performanceId]);

            $newAssignments = $_POST['roles'] ?? [];
            foreach ($newAssignments as $roleId => $artistsData) {
                foreach ($artistsData as $sortOrder => $artistInput) {
                    $artistId = null;
                    $customArtistName = null;

                    if ($artistInput['artist_id'] === 'new_artist') {
                        // Обработка нового артиста
                        $fullName = trim($artistInput['custom_name']);
                        $parts = explode(' ', $fullName);
                        $lastName = array_pop($parts);
                        $firstName = implode(' ', $parts);
                        $expectedType = $artistInput['expected_type'] ?? 'artist'; // Тип передается с формой

                        $artistId = $parser->findOrCreateArtist($firstName, $lastName, $expectedType);
                    } elseif ($artistInput['artist_id'] === 'none') {
                        // "СОСТАВ УТОЧНЯЕТСЯ" или пусто
                        $artistId = null;
                        $customArtistName = 'СОСТАВ УТОЧНЯЕТСЯ'; // Или оставляем null
                    } else {
                        $artistId = (int)$artistInput['artist_id'];
                    }

                    // Сохраняем назначение
                    $insertStmt = $pdo->prepare("
                        INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$performanceId, $roleId, $artistId, $customArtistName, $sortOrder]);

                    // Обновляем историю назначений
                    if ($artistId) {
                        $historyStmt = $pdo->prepare("
                            INSERT INTO role_artist_history (role_id, artist_id, assignment_count, last_assigned_date)
                            VALUES (?, ?, 1, NOW())
                            ON DUPLICATE KEY UPDATE assignment_count = assignment_count + 1, last_assigned_date = NOW();
                        ");
                        $historyStmt->execute([$roleId, $artistId]);
                    }
                }
            }
            $_SESSION['message'] = "Состав сохранен!";
            header("Location: edit_cast.php?performance_id=$performanceId");
            exit;
        } elseif (isset($_POST['action']) && $_POST['action'] === 'generate_card') {
            // Реализация генерации карточки
            // Здесь будет вызов функции, которая формирует вики-разметку
            // Например: $vkPostText = generateVkCard($performanceId);
            // updateVkPostText($performanceId, $vkPostText);
            $_SESSION['message'] = "Карточка сгенерирована (функционал будет реализован позже)!";
            header("Location: edit_cast.php?performance_id=$performanceId");
            exit;
        }
    }

} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
} catch (Exception $e) {
    die("Произошла ошибка: " . $e->getMessage());
}

// Вспомогательная функция для инициализации performance_roles_artists
function initializePerformanceRoles(\PDO $pdo, int $performanceId, int $playId): void {
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? ORDER BY sort_order");
    $stmt->execute([$playId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($roles as $roleId) {
        $insertStmt = $pdo->prepare("
            INSERT INTO performance_roles_artists (performance_id, role_id, artist_id, custom_artist_name, sort_order_in_role)
            VALUES (?, ?, NULL, 'СОСТАВ УТОЧНЯЕТСЯ', 0)
        ");
        $insertStmt->execute([$performanceId, $roleId]);
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактирование состава: <?php echo htmlspecialchars($performance['play_name']); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css"> <!-- Ваш CSS -->
    <style>
        /* Стили для формы */
        .role-entry {
            margin-bottom: 15px;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 5px;
        }
        .role-entry label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .artist-select-group {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .artist-select-group select,
        .artist-select-group input[type="text"] {
            margin-right: 10px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        .add-artist-btn, .remove-artist-btn {
            padding: 5px 10px;
            cursor: pointer;
        }
        .vk-card-preview {
            white-space: pre-wrap;
            background-color: #f0f0f0;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <h1>Редактирование состава: <?php echo htmlspecialchars($performance['play_name']); ?></h1>
    <p>Дата: <?php echo date('d.m.Y H:i', strtotime($performance['event_date'] . ' ' . $performance['event_time'])); ?></p>

    <?php if (isset($_SESSION['message'])): ?>
        <p style="color: green;"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_cast">
        <?php foreach ($assignedRoles as $role): ?>
            <div class="role-entry" data-role-id="<?php echo $role['role_id']; ?>">
                <label><?php echo htmlspecialchars($role['role_name'] . ($role['role_description'] ? ', ' . $role['role_description'] : '')); ?></label>
                <div class="artists-container">
                    <?php foreach ($role['artists'] as $index => $artist): ?>
                        <div class="artist-select-group">
                            <select name="roles[<?php echo $role['role_id']; ?>][<?php echo $index; ?>][artist_id]"
                                    data-expected-type="<?php echo $role['expected_artist_type']; ?>"
                                    onchange="toggleCustomArtistInput(this)">
                                <option value="none">СОСТАВ УТОЧНЯЕТСЯ</option>
                                <?php
                                    $currentArtistType = $role['expected_artist_type'];
                                    if (!isset($allArtists[$currentArtistType])) {
                                        $currentArtistType = 'artist'; // Fallback
                                    }
                                ?>
                                <?php foreach ($allArtists[$currentArtistType] ?? [] as $artistOption): ?>
                                    <option value="<?php echo $artistOption['artist_id']; ?>"
                                            <?php echo ($artist['artist_id'] == $artistOption['artist_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($artistOption['first_name'] . ' ' . $artistOption['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="new_artist">Добавить нового артиста...</option>
                            </select>
                            <input type="text"
                                   name="roles[<?php echo $role['role_id']; ?>][<?php echo $index; ?>][custom_name]"
                                   placeholder="Имя Фамилия"
                                   value="<?php echo htmlspecialchars($artist['custom_artist_name'] ?? ''); ?>"
                                   style="<?php echo ($artist['artist_id'] === null && $artist['custom_artist_name'] !== 'СОСТАВ УТОЧНЯЕТСЯ') ? '' : 'display:none;'; ?>">
                            <input type="hidden" name="roles[<?php echo $role['role_id']; ?>][<?php echo $index; ?>][expected_type]" value="<?php echo $role['expected_artist_type']; ?>">
                            <?php if ($index > 0 || count($role['artists']) > 1): ?>
                                <button type="button" class="remove-artist-btn" onclick="removeArtist(this)">-</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-artist-btn" onclick="addArtist(this)">+ Добавить артиста на роль</button>
            </div>
        <?php endforeach; ?>
        <button type="submit">Сохранить состав</button>
    </form>

    <hr>

    <h2>Предварительный просмотр карточки VK</h2>
    <form method="POST">
        <input type="hidden" name="action" value="generate_card">
        <button type="submit">Сгенерировать карточку VK</button>
    </form>
    <div class="vk-card-preview">
        <?php echo htmlspecialchars($performance['vk_post_text'] ?? 'Карточка еще не сгенерирована.'); ?>
    </div>

    <script>
        function toggleCustomArtistInput(selectElement) {
            const customInput = selectElement.nextElementSibling;
            if (selectElement.value === 'new_artist') {
                customInput.style.display = '';
                customInput.value = ''; // Очищаем поле для нового ввода
            } else {
                customInput.style.display = 'none';
                customInput.value = '';
            }
        }

        function addArtist(buttonElement) {
            const roleEntry = buttonElement.closest('.role-entry');
            const artistsContainer = roleEntry.querySelector('.artists-container');
            const roleId = roleEntry.dataset.roleId;
            const expectedType = roleEntry.querySelector('select').dataset.expectedType; // Берем тип из первого селекта

            const newIndex = artistsContainer.children.length;

            const newArtistGroup = document.createElement('div');
            newArtistGroup.classList.add('artist-select-group');
            newArtistGroup.innerHTML = `
                <select name="roles[${roleId}][${newIndex}][artist_id]"
                        data-expected-type="${expectedType}"
                        onchange="toggleCustomArtistInput(this)">
                    <option value="none">СОСТАВ УТОЧНЯЕТСЯ</option>
                    <?php foreach ($allArtists['artist'] ?? [] as $artistOption): // По умолчанию показываем 'artist' ?>
                        <option value="<?php echo $artistOption['artist_id']; ?>">
                            <?php echo htmlspecialchars($artistOption['first_name'] . ' ' . $artistOption['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="new_artist">Добавить нового артиста...</option>
                </select>
                <input type="text"
                       name="roles[${roleId}][${newIndex}][custom_name]"
                       placeholder="Имя Фамилия"
                       style="display:none;">
                <input type="hidden" name="roles[${roleId}][${newIndex}][expected_type]" value="${expectedType}">
                <button type="button" class="remove-artist-btn" onclick="removeArtist(this)">-</button>
            `;
            artistsContainer.appendChild(newArtistGroup);

            // Обновляем опции для нового селекта, чтобы соответствовать expectedType
            const newSelect = newArtistGroup.querySelector('select');
            updateArtistOptions(newSelect, expectedType);
        }

        function removeArtist(buttonElement) {
            const artistGroup = buttonElement.closest('.artist-select-group');
            const artistsContainer = artistGroup.closest('.artists-container');
            if (artistsContainer.children.length > 1) {
                artistGroup.remove();
            } else {
                // Если остался только один элемент, очищаем его, но не удаляем
                const selectElement = artistsContainer.querySelector('select');
                selectElement.value = 'none';
                toggleCustomArtistInput(selectElement);
                const customNameInput = artistsContainer.querySelector('input[type="text"]');
                if (customNameInput) customNameInput.value = 'СОСТАВ УТОЧНЯЕТСЯ';
            }
        }

        function updateArtistOptions(selectElement, type) {
            // Эта функция должна быть реализована на сервере или данные должны быть доступны на клиенте
            // Для простоты, здесь предполагается, что $allArtists доступен в JS (что не так напрямую)
            // В реальном приложении это будет AJAX-запрос или данные, сериализованные в JS
            const allArtists = <?php echo json_encode($allArtists); ?>;
            const options = allArtists[type] || allArtists['artist'] || []; // Fallback to 'artist'

            selectElement.innerHTML = '<option value="none">СОСТАВ УТОЧНЯЕТСЯ</option>';
            options.forEach(artist => {
                const option = document.createElement('option');
                option.value = artist.artist_id;
                option.textContent = artist.first_name + ' ' + artist.last_name;
                selectElement.appendChild(option);
            });
            const newArtistOption = document.createElement('option');
            newArtistOption.value = 'new_artist';
            newArtistOption.textContent = 'Добавить нового артиста...';
            selectElement.appendChild(newArtistOption);
        }

        // Инициализация: убедиться, что поля для кастомных артистов скрыты/показаны правильно при загрузке
        document.querySelectorAll('.artist-select-group select').forEach(select => toggleCustomArtistInput(select));

    </script>
</body>
</html>
