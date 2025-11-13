<?php
require_once 'config.php';
require_once 'db.php';
require_once 'app/Models/PlayTemplateParser.php';
use App\Models\PlayTemplateParser;
requireAuth();
require_once 'includes/navigation.php';
handleLogoutRequest();

$message = '';
$playId = $_GET['play_id'] ?? null;
$play = null;
$templateElements = [];
$playDisplayTitle = '';
$roleSpecialGroupOptions = [
    '' => '— не выбрано —',
    'conductor' => 'Дирижёр (ответственный)',
    'concertmaster' => 'Концертмейстер / пианист',
];

if ($playId) {
    $play = getPlayById($playId);
    if ($play) {
        $templateElements = getTemplateElementsForPlay($playId);
        $playDisplayTitle = formatPlayTitle($play['site_title'] ?? null, $play['full_name'] ?? null);
    } else {
        $message = 'Спектакль не найден.';
    }
} else {
    $message = 'ID спектакля не указан.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playId) {
    if (isset($_POST['save_elements'])) {
        // Удаляем все существующие элементы для данного play_id
        deleteTemplateElementsByPlayId($playId);

        $elements = json_decode($_POST['elements_json'], true);
        if (is_array($elements)) {
            foreach ($elements as $index => $element) {
                $elementType = $element['type'] ?? '';
                $elementValue = $element['value'] ?? '';
                $sortOrder = $index;

                $headingLevel = null;
                if ($elementType === 'heading') {
                    $headingLevel = isset($element['level']) ? (int)$element['level'] : 2;
                }

                $shouldSave = false;
                if ($elementType === 'newline' || $elementType === 'ticket_button') {
                    $shouldSave = true;
                } elseif (!empty($elementType) && $elementValue !== '') {
                    $shouldSave = true;
                }

                $specialGroup = null;
                if ($elementType === 'role') {
                    $rawSpecialGroup = trim((string)($element['special_group'] ?? ''));
                    if (!array_key_exists($rawSpecialGroup, $roleSpecialGroupOptions)) {
                        $rawSpecialGroup = '';
                    }
                    $specialGroup = $rawSpecialGroup !== '' ? $rawSpecialGroup : null;
                }

                if ($shouldSave) {
                    $usePreviousCast = !empty($element['use_previous_cast']);
                    if ($elementType === 'role') {
                        $existingRoleId = isset($element['role_id']) ? (int)$element['role_id'] : null;
                        $resolvedRoleId = resolveRoleValueToId((int)$playId, (string)$elementValue, $sortOrder, $existingRoleId);
                        if ($resolvedRoleId === null) {
                            continue;
                        }
                        $elementValue = (string)$resolvedRoleId;
                    }
                    saveTemplateElement($playId, $elementType, $elementValue, $sortOrder, $headingLevel, $usePreviousCast, $specialGroup);
                }
            }
            $message = 'Шаблон успешно сохранен.';
            ensurePerformanceRolesForPlay((int)$playId);
            $templateElements = getTemplateElementsForPlay($playId); // Обновляем список элементов
        } else {
            $message = 'Ошибка при сохранении шаблона: неверные данные.';
        }
    } elseif (isset($_POST['add_default_template'])) {
        // Проверяем, есть ли уже элементы для этого спектакля
        if (empty($templateElements)) {
            // Добавляем минимальную шаблонную структуру
            saveTemplateElement($playId, 'heading', 'В ролях:', 0, 2);
            saveTemplateElement($playId, 'heading', 'СОСТАВ УТОЧНЯЕТСЯ', 1, 3);
            saveTemplateElement($playId, 'image', 'default_image.jpg', 2); // Пример
            ensurePerformanceRolesForPlay((int)$playId);
            $message = 'Добавлен минимальный шаблон.';
            $templateElements = getTemplateElementsForPlay($playId);
        } else {
            $message = 'Шаблон уже существует, минимальный шаблон не добавлен.';
        }
    } elseif (isset($_POST['reparse_template'])) {
        $templateRow = getTemplateByPlayId($playId);
        $templateText = trim((string)($templateRow['template_text'] ?? ''));
        if ($templateText === '') {
            $message = 'Текст шаблона пуст. Нечего парсить.';
        } else {
            $parser = new PlayTemplateParser(getDBConnection());
            $parser->parseTemplate((int)$playId, $templateText);
            ensurePerformanceRolesForPlay((int)$playId);
            $templateElements = getTemplateElementsForPlay($playId);
            $message = 'Шаблон перепарсен из текстового варианта.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование шаблона спектакля: <?php echo htmlspecialchars($playDisplayTitle); ?></title>
    <link rel="stylesheet" href="css/main.css">
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link rel="stylesheet" href="app/globals.css">
    <style>
        .element-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin-bottom: 5px;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
        }
        .element-item .handle {
            cursor: grab;
            margin-right: 10px;
        }
        .element-item .content {
            flex-grow: 1;
        }
        .element-item .actions {
            margin-left: 10px;
        }
        .use-previous-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            font-size: 0.85rem;
        }
        .role-flags {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 6px;
            font-size: 0.85rem;
            align-items: center;
        }
        .role-group-indicator {
            color: #4b5563;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php renderMainNavigation('plays'); ?>
        <div class="header">
            <div>
                <h1>Редактирование шаблона: <?php echo htmlspecialchars($playDisplayTitle); ?></h1>
                <p class="header-subtitle">Управление элементами карточки спектакля</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($play): ?>
            <div class="section">
                <h2>Элементы шаблона</h2>
                <div id="template-elements-list">
                    <?php foreach ($templateElements as $element):
                        $elementType = $element['element_type'];
                        $elementValue = $element['element_value'];
                        $roleNameForValue = '';
                        $roleIdAttr = '';
                        $usePrevious = !empty($element['use_previous_cast']);

                if ($elementType === 'role') {
                    $role = getRoleById($elementValue);
                    $roleNameForValue = $role['role_name'] ?? '';
                    $roleIdAttr = (string)$elementValue;
                }
                    ?>
                        <div class="element-item"
                             data-id="<?php echo $element['id']; ?>"
                             data-type="<?php echo htmlspecialchars($elementType); ?>"
                             data-value="<?php echo htmlspecialchars($elementType === 'role' && $roleNameForValue !== '' ? $roleNameForValue : $elementValue); ?>"
                             data-role-id="<?php echo htmlspecialchars($roleIdAttr); ?>"
                             data-heading-level="<?php echo (int)($element['heading_level'] ?? 0); ?>"
                             data-use-previous="<?php echo $usePrevious ? '1' : '0'; ?>"
                             data-special-group="<?php echo htmlspecialchars($element['special_group'] ?? ''); ?>">
                            <span class="handle">☰</span>
                            <div class="content">
                                <?php if ($elementType === 'heading'): ?>
                                    <strong>Заголовок (уровень <?php echo (int)($element['heading_level'] ?? 2); ?>):</strong> <span class="element-text"><?php echo htmlspecialchars($element['element_value']); ?></span>
                                <?php elseif ($elementType === 'image'): ?>
                                    <strong>Изображение:</strong> <span class="element-text"><?php echo htmlspecialchars($element['element_value']); ?></span>
                                <?php elseif ($elementType === 'role'): ?>
                                    <?php
                                        $role = $role ?? getRoleById($elementValue);
                                        $roleDisplay = $role['role_name'] ?? '';
                                        if ($roleDisplay === '' && $roleIdAttr !== '') {
                                            $roleDisplay = 'ID: ' . $roleIdAttr;
                                        }
                                        echo '<strong>Роль:</strong> <span class="element-text">' . htmlspecialchars($roleDisplay ?: 'Неизвестная роль') . '</span>';
                                    ?>
                                    <div class="role-flags">
                                        <label class="use-previous-toggle">
                                            <input type="checkbox" class="toggle-use-previous" <?php echo $usePrevious ? 'checked' : ''; ?>>
                                            Брать предыдущий состав
                                        </label>
                                        <span class="role-group-indicator">
                                            Группа: <?php echo htmlspecialchars($roleSpecialGroupOptions[$element['special_group'] ?? ''] ?? '— не выбрано —'); ?>
                                        </span>
                                    </div>
                                <?php elseif ($elementType === 'newline'): ?>
                                    <em>Пустая строка</em>
                                <?php elseif ($elementType === 'ticket_button'): ?>
                                    <?php $hasCustomLink = trim((string)$elementValue) !== ''; ?>
                                    <strong>Кнопка билетов:</strong>
                                    <span class="element-text">
                                        <?php echo $hasCustomLink ? 'Своя ссылка: ' . htmlspecialchars($elementValue) : 'Авто: ссылка по коду спектакля'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="actions">
                                <button type="button" class="btn-icon btn-secondary btn-edit-element" title="Редактировать">✏️</button>
                                <button type="button" class="btn-icon btn-danger btn-delete-element" title="Удалить">🗑️</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="buttons mt-4">
                    <button type="button" id="add-heading" class="btn-secondary">Добавить заголовок</button>
                    <button type="button" id="add-image" class="btn-secondary">Добавить картинку</button>
                    <button type="button" id="add-role" class="btn-secondary">Добавить роль</button>
                    <button type="button" id="add-newline" class="btn-secondary">Добавить пустую строку</button>
                    <button type="button" id="add-ticket-button" class="btn-secondary">Добавить ссылку на билеты</button>
                </div>

                <form method="post" class="mt-4">
                    <input type="hidden" name="play_id" value="<?php echo htmlspecialchars($playId); ?>">
                    <input type="hidden" name="elements_json" id="elements-json-input">
                    <button type="submit" name="save_elements" class="btn-primary">Сохранить шаблон</button>
                    <button type="submit" name="add_default_template" class="btn-secondary">Добавить минимальный шаблон</button>
                    <button type="submit" name="reparse_template" class="btn-secondary">Перепарсить из текста</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
    <script>
        const templateElementsList = document.getElementById('template-elements-list');
        const elementsJsonInput = document.getElementById('elements-json-input');
        const SPECIAL_GROUP_OPTIONS = <?php echo json_encode($roleSpecialGroupOptions, JSON_UNESCAPED_UNICODE); ?>;

        new Sortable(templateElementsList, {
            handle: '.handle',
            animation: 150,
            onEnd: updateElementsJson
        });

        function escapeHtml(value = '') {
            const div = document.createElement('div');
            div.textContent = value ?? '';
            return div.innerHTML;
        }

        function promptSpecialGroup(currentKey) {
            const optionKeys = Object.keys(SPECIAL_GROUP_OPTIONS);
            const buildMessage = () => {
                const rows = optionKeys.map((key, idx) => `${idx} — ${SPECIAL_GROUP_OPTIONS[key]}`);
                rows.unshift('Выберите группу для роли (введите номер, пусто — без группы):');
                return rows.join('\n');
            };
            while (true) {
                const defaultValue = currentKey ? optionKeys.indexOf(currentKey) : '';
                const input = prompt(buildMessage(), defaultValue !== -1 ? defaultValue : '');
                if (input === null) {
                    return null;
                }
                const trimmed = input.trim();
                if (trimmed === '') {
                    return '';
                }
                if (SPECIAL_GROUP_OPTIONS.hasOwnProperty(trimmed)) {
                    return trimmed;
                }
                const asNumber = Number(trimmed);
                if (Number.isInteger(asNumber) && asNumber >= 0 && asNumber < optionKeys.length) {
                    return optionKeys[asNumber];
                }
                const normalized = trimmed.toLowerCase();
                const matchedKey = optionKeys.find((key) => SPECIAL_GROUP_OPTIONS[key].toLowerCase() === normalized);
                if (matchedKey) {
                    return matchedKey;
                }
                alert('Не удалось определить группу. Укажите номер из списка.');
            }
        }

        function updateRoleGroupIndicator(item) {
            if (!item || item.dataset.type !== 'role') return;
            const indicator = item.querySelector('.role-group-indicator');
            if (!indicator) return;
            const key = item.dataset.specialGroup || '';
            indicator.textContent = `Группа: ${SPECIAL_GROUP_OPTIONS[key] || SPECIAL_GROUP_OPTIONS['']}`;
        }

        function updateElementsJson() {
            const elements = [];
            templateElementsList.querySelectorAll('.element-item').forEach(item => {
                const element = {
                    type: item.dataset.type,
                    value: item.dataset.value ?? ''
                };
                if (item.dataset.headingLevel && parseInt(item.dataset.headingLevel, 10) > 0) {
                    element.level = parseInt(item.dataset.headingLevel, 10);
                }
                if (item.dataset.type === 'role') {
                    if (item.dataset.roleId) {
                        element.role_id = item.dataset.roleId;
                    }
                    element.use_previous_cast = item.dataset.usePrevious === '1';
                    if (item.dataset.specialGroup && item.dataset.specialGroup !== '') {
                        element.special_group = item.dataset.specialGroup;
                    }
                }
                elements.push(element);
            });
            elementsJsonInput.value = JSON.stringify(elements);
        }

        document.getElementById('add-heading').addEventListener('click', () => {
            const headingText = prompt('Введите текст заголовка:');
            if (headingText) {
                const levelInput = prompt('Введите уровень заголовка (2-4):', '2');
                let headingLevel = parseInt(levelInput ?? '2', 10);
                if (!Number.isInteger(headingLevel) || headingLevel < 2 || headingLevel > 5) {
                    headingLevel = 2;
                }
                const newItem = createTemplateElement('heading', headingText, { headingLevel });
                templateElementsList.appendChild(newItem);
                updateElementsJson();
            }
        });

        document.getElementById('add-image').addEventListener('click', () => {
            const imageUrl = prompt('Введите URL изображения:');
            if (imageUrl) {
                const newItem = createTemplateElement('image', imageUrl);
                templateElementsList.appendChild(newItem);
                updateElementsJson();
            }
        });

        document.getElementById('add-role').addEventListener('click', async () => {
            const roleName = prompt('Введите название роли (можно без кавычек, они добавятся автоматически)');
            if (roleName) {
                const trimmed = roleName.trim();
                if (trimmed !== '') {
                    const newItem = createTemplateElement('role', trimmed);
                    templateElementsList.appendChild(newItem);
                    updateElementsJson();
                }
            }
        });

        const addNewlineBtn = document.getElementById('add-newline');
        if (addNewlineBtn) {
            addNewlineBtn.addEventListener('click', () => {
                const newItem = createTemplateElement('newline', '');
                templateElementsList.appendChild(newItem);
                updateElementsJson();
            });
        }

        const addTicketButton = document.getElementById('add-ticket-button');
        if (addTicketButton) {
            addTicketButton.addEventListener('click', () => {
                const customLink = prompt('Введите собственную ссылку (оставьте пустым, чтобы использовать код спектакля):', '');
                const value = customLink ? customLink.trim() : '';
                const newItem = createTemplateElement('ticket_button', value);
                templateElementsList.appendChild(newItem);
                updateElementsJson();
            });
        }

        templateElementsList.addEventListener('click', (event) => {
            const target = event.target;
            const item = target.closest('.element-item');
            if (!item) return;

            if (target.classList.contains('btn-delete-element')) {
                if (confirm('Вы уверены, что хотите удалить этот элемент?')) {
                    item.remove();
                    updateElementsJson();
                }
            } else if (target.classList.contains('btn-edit-element')) {
                let newValue = '';
                if (item.dataset.type === 'heading') {
                    newValue = prompt('Редактировать заголовок:', item.dataset.value);
                    if (newValue !== null) {
                        const levelInput = prompt('Редактировать уровень заголовка (2-4):', item.dataset.headingLevel || '2');
                        let headingLevel = parseInt(levelInput ?? '2', 10);
                        if (!Number.isInteger(headingLevel) || headingLevel < 2 || headingLevel > 5) {
                            headingLevel = 2;
                        }
                        item.dataset.headingLevel = headingLevel;
                    }
                } else if (item.dataset.type === 'image') {
                    newValue = prompt('Редактировать URL изображения:', item.dataset.value);
                } else if (item.dataset.type === 'role') {
                    newValue = prompt('Редактировать название роли:', item.dataset.value);
                } else if (item.dataset.type === 'ticket_button') {
                    const currentValue = item.dataset.value || '';
                    const inputValue = prompt('Редактировать ссылку на билеты (оставьте пустым для автоматической ссылки):', currentValue);
                    if (inputValue !== null) {
                        newValue = inputValue.trim();
                    } else {
                        newValue = null;
                    }
                } else if (item.dataset.type === 'newline') {
                    alert('Пустую строку редактировать не нужно. Вы можете удалить её и добавить заново.');
                    newValue = null;
                }

                let specialGroupChoice = null;
                if (item.dataset.type === 'role' && newValue !== null) {
                    specialGroupChoice = promptSpecialGroup(item.dataset.specialGroup || '');
                    if (specialGroupChoice === null) {
                        specialGroupChoice = item.dataset.specialGroup || '';
                    }
                }

                if (newValue !== null) {
                    const trimmedValue = (newValue || '').trim();
                    if (trimmedValue === '' && item.dataset.type !== 'ticket_button') {
                        alert('Пустое значение недопустимо. Введите текст или отмените изменения.');
                        return;
                    }
                    item.dataset.value = trimmedValue;
                    if (item.dataset.type === 'role' && specialGroupChoice !== null) {
                        item.dataset.specialGroup = specialGroupChoice;
                    }
                    // roleId остаётся, чтобы обновлять существующие роли
                    const textSpan = item.querySelector('.element-text');
                    if (textSpan) {
                        if (item.dataset.type === 'role') {
                            textSpan.textContent = trimmedValue;
                        } else if (item.dataset.type === 'heading') {
                            textSpan.textContent = trimmedValue;
                            const strong = item.querySelector('.content strong');
                            if (strong && item.dataset.headingLevel) {
                                strong.textContent = `Заголовок (уровень ${item.dataset.headingLevel}):`;
                            }
                        } else if (item.dataset.type === 'ticket_button') {
                            textSpan.textContent = trimmedValue ? `Своя ссылка: ${trimmedValue}` : 'Авто: ссылка по коду спектакля';
                        } else {
                            textSpan.textContent = trimmedValue;
                        }
                    }
                    if (item.dataset.type === 'role') {
                        updateRoleGroupIndicator(item);
                    }
                    updateElementsJson();
                }
            }
        });

        templateElementsList.addEventListener('change', (event) => {
            const target = event.target;
            if (target.classList.contains('toggle-use-previous')) {
                const item = target.closest('.element-item');
                if (!item) return;
                item.dataset.usePrevious = target.checked ? '1' : '0';
                updateElementsJson();
            } else if (target.classList.contains('special-group-select')) {
                const item = target.closest('.element-item');
                if (!item) return;
                item.dataset.specialGroup = target.value || '';
                updateElementsJson();
            }
        });

        function createTemplateElement(type, value, options = {}) {
            const div = document.createElement('div');
            div.className = 'element-item';
            div.dataset.type = type;
            div.dataset.value = value;
            if (type === 'heading') {
                div.dataset.headingLevel = options.headingLevel || 2;
            } else {
                div.dataset.headingLevel = '';
            }
            if (type === 'role') {
                div.dataset.roleId = options.roleId || '';
                div.dataset.usePrevious = options.usePreviousCast ? '1' : '0';
                div.dataset.specialGroup = options.specialGroup || '';
            } else {
                div.dataset.usePrevious = '';
                div.dataset.specialGroup = '';
            }

            let contentHtml = '';
            if (type === 'heading') {
                const level = div.dataset.headingLevel || 2;
                contentHtml = `<strong>Заголовок (уровень ${level}):</strong> <span class="element-text">${escapeHtml(value)}</span>`;
            } else if (type === 'image') {
                contentHtml = `<strong>Изображение:</strong> <span class="element-text">${escapeHtml(value)}</span>`;
            } else if (type === 'role') {
                const displayText = value || (options.roleId ? `ID: ${options.roleId}` : '');
                const checkedAttr = (div.dataset.usePrevious === '1') ? 'checked' : '';
                const groupLabel = SPECIAL_GROUP_OPTIONS[div.dataset.specialGroup || ''] || SPECIAL_GROUP_OPTIONS[''];
                contentHtml = `<strong>Роль:</strong> <span class="element-text">${escapeHtml(displayText)}</span>
                    <div class="role-flags">
                        <label class="use-previous-toggle">
                            <input type="checkbox" class="toggle-use-previous" ${checkedAttr}>
                            Брать предыдущий состав
                        </label>
                        <span class="role-group-indicator">Группа: ${escapeHtml(groupLabel)}</span>
                    </div>`;
            } else if (type === 'newline') {
                contentHtml = `<em>Пустая строка</em>`;
            } else if (type === 'ticket_button') {
                const text = value ? `Своя ссылка: ${escapeHtml(value)}` : 'Авто: ссылка по коду спектакля';
                contentHtml = `<strong>Кнопка билетов:</strong> <span class="element-text">${text}</span>`;
            }

            div.innerHTML = `
                <span class="handle">☰</span>
                <div class="content">${contentHtml}</div>
                <div class="actions">
                    <button type="button" class="btn-icon btn-secondary btn-edit-element" title="Редактировать">✏️</button>
                    <button type="button" class="btn-icon btn-danger btn-delete-element" title="Удалить">🗑️</button>
                </div>
            `;
            updateRoleGroupIndicator(div);
            return div;
        }

        updateElementsJson();
    </script>
</body>
</html>

<?php
function resolveRoleValueToId(int $playId, string $rawValue, int $sortOrder, ?int $existingRoleId = null): ?int
{
    $value = trim($rawValue);
    if ($value === '') {
        return null;
    }

    if ($existingRoleId) {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_id = ? AND play_id = ?");
        $stmt->execute([$existingRoleId, $playId]);
        $roleExists = $stmt->fetchColumn();

        if ($roleExists) {
            $roleName = normalizeRoleNameForStorage($value);
            $expectedType = detectExpectedArtistTypeForTemplate($roleName);
            $update = $pdo->prepare("UPDATE roles SET role_name = ?, sort_order = ?, expected_artist_type = ?, updated_at = NOW() WHERE role_id = ?");
            $update->execute([$roleName, $sortOrder, $expectedType, $existingRoleId]);
            return (int)$existingRoleId;
        }
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    $roleName = normalizeRoleNameForStorage($value);
    $expectedType = detectExpectedArtistTypeForTemplate($roleName);

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT role_id FROM roles WHERE play_id = ? AND role_name = ?");
    $stmt->execute([$playId, $roleName]);
    $roleId = $stmt->fetchColumn();

    if ($roleId) {
        $update = $pdo->prepare("UPDATE roles SET sort_order = ?, expected_artist_type = ?, updated_at = NOW() WHERE role_id = ?");
        $update->execute([$sortOrder, $expectedType, $roleId]);
        return (int)$roleId;
    }

    $insert = $pdo->prepare("INSERT INTO roles (play_id, role_name, expected_artist_type, sort_order) VALUES (?, ?, ?, ?)");
    $insert->execute([$playId, $roleName, $expectedType, $sortOrder]);
    return (int)$pdo->lastInsertId();
}

function detectExpectedArtistTypeForTemplate(string $roleName): string
{
    $normalizedRoleName = normalizeRoleName($roleName);

    if (mb_stripos($normalizedRoleName, 'Дирижёр') !== false || mb_stripos($normalizedRoleName, 'Дирижер') !== false) {
        return 'conductor';
    }

    if (
        mb_stripos($normalizedRoleName, 'Клавесин') !== false ||
        mb_stripos($normalizedRoleName, 'Концертмейстер') !== false ||
        mb_stripos($normalizedRoleName, 'Пианист') !== false
    ) {
        return 'pianist';
    }

    return 'artist';
}

function normalizeRoleNameForStorage(string $value): string
{
    $roleName = trim($value);
    if ($roleName === '') {
        return '';
    }
    if (!str_starts_with($roleName, "'''")) {
        $roleName = "'''{$roleName}'''";
    }
    return $roleName;
}
