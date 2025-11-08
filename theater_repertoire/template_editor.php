<?php
require_once 'config.php';
require_once 'db.php';
require_once 'app/Models/PlayTemplateParser.php';
use App\Models\PlayTemplateParser;
requireAuth();

$message = '';
$playId = $_GET['play_id'] ?? null;
$play = null;
$templateElements = [];

if ($playId) {
    $play = getPlayById($playId);
    if ($play) {
        $templateElements = getTemplateElementsForPlay($playId);
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
                if ($elementType === 'newline') {
                    $shouldSave = true;
                } elseif (!empty($elementType) && $elementValue !== '') {
                    $shouldSave = true;
                }

                if ($shouldSave) {
                    saveTemplateElement($playId, $elementType, $elementValue, $sortOrder, $headingLevel);
                }
            }
            $message = 'Шаблон успешно сохранен.';
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
    <title>Редактирование шаблона спектакля: <?php echo htmlspecialchars($play['full_name'] ?? ''); ?></title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com/2.2.19/tailwind.min.css" rel="stylesheet">
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Редактирование шаблона: <?php echo htmlspecialchars($play['full_name'] ?? ''); ?></h1>
            <div>
                <a href="admin.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none;">К управлению спектаклями</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($play): ?>
            <div class="section">
                <h2>Элементы шаблона</h2>
                <div id="template-elements-list">
                    <?php foreach ($templateElements as $element): ?>
                        <div class="element-item" data-id="<?php echo $element['id']; ?>" data-type="<?php echo htmlspecialchars($element['element_type']); ?>" data-value="<?php echo htmlspecialchars($element['element_value']); ?>" data-heading-level="<?php echo (int)($element['heading_level'] ?? 0); ?>">
                            <span class="handle">☰</span>
                            <div class="content">
                                <?php if ($element['element_type'] === 'heading'): ?>
                                    <strong>Заголовок (уровень <?php echo (int)($element['heading_level'] ?? 2); ?>):</strong> <span class="element-text"><?php echo htmlspecialchars($element['element_value']); ?></span>
                                <?php elseif ($element['element_type'] === 'image'): ?>
                                    <strong>Изображение:</strong> <span class="element-text"><?php echo htmlspecialchars($element['element_value']); ?></span>
                                <?php elseif ($element['element_type'] === 'role'): ?>
                                    <?php
                                        $role = getRoleById($element['element_value']);
                                        echo '<strong>Роль:</strong> <span class="element-text">' . htmlspecialchars($role['role_name'] ?? 'Неизвестная роль') . '</span>';
                                    ?>
                                <?php elseif ($element['element_type'] === 'newline'): ?>
                                    <em>Пустая строка</em>
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

        new Sortable(templateElementsList, {
            handle: '.handle',
            animation: 150,
            onEnd: updateElementsJson
        });

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
            // Здесь нужно будет получить список ролей из БД или предложить ввести ID роли
            // Для простоты пока предложим ввести ID роли
            const roleId = prompt('Введите ID роли:');
            if (roleId) {
                // В реальном приложении здесь нужно будет сделать AJAX-запрос для получения имени роли по ID
                // и отобразить его пользователю.
                const newItem = createTemplateElement('role', roleId);
                templateElementsList.appendChild(newItem);
                updateElementsJson();
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
                    newValue = prompt('Редактировать ID роли:', item.dataset.value);
                } else if (item.dataset.type === 'newline') {
                    alert('Пустую строку редактировать не нужно. Вы можете удалить её и добавить заново.');
                    newValue = null;
                }

                if (newValue !== null && newValue !== '') {
                    item.dataset.value = newValue;
                    const textSpan = item.querySelector('.element-text');
                    if (textSpan) {
                        if (item.dataset.type === 'role') {
                            // Здесь также нужно обновить отображаемое имя роли, если это возможно
                            textSpan.textContent = `ID: ${newValue}`; // Временно
                        } else if (item.dataset.type === 'heading') {
                            textSpan.textContent = newValue;
                            const strong = item.querySelector('.content strong');
                            if (strong && item.dataset.headingLevel) {
                                strong.textContent = `Заголовок (уровень ${item.dataset.headingLevel}):`;
                            }
                        } else {
                            textSpan.textContent = newValue;
                        }
                    }
                    updateElementsJson();
                }
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

            let contentHtml = '';
            if (type === 'heading') {
                const level = div.dataset.headingLevel || 2;
                contentHtml = `<strong>Заголовок (уровень ${level}):</strong> <span class="element-text">${value}</span>`;
            } else if (type === 'image') {
                contentHtml = `<strong>Изображение:</strong> <span class="element-text">${value}</span>`;
            } else if (type === 'role') {
                contentHtml = `<strong>Роль:</strong> <span class="element-text">ID: ${value}</span>`; // Временно
            } else if (type === 'newline') {
                contentHtml = `<em>Пустая строка</em>`;
            }

            div.innerHTML = `
                <span class="handle">☰</span>
                <div class="content">${contentHtml}</div>
                <div class="actions">
                    <button type="button" class="btn-icon btn-secondary btn-edit-element" title="Редактировать">✏️</button>
                    <button type="button" class="btn-icon btn-danger btn-delete-element" title="Удалить">🗑️</button>
                </div>
            `;
            return div;
        }

        updateElementsJson();
    </script>
</body>
</html>
