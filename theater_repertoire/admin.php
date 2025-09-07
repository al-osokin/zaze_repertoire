<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_play'])) {
        $data = [
            'id' => $_POST['play_id'] ?? null,
            'short_name' => trim($_POST['short_name'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'wiki_link' => trim($_POST['wiki_link'] ?? ''),
            'hall' => trim($_POST['hall'] ?? ''),
            'special_mark' => trim($_POST['special_mark'] ?? ''),
            'is_subscription' => $_POST['is_subscription'] ?? 0
        ];

        if (!empty($data['short_name']) && !empty($data['full_name']) && !empty($data['hall'])) {
            savePlay($data);
            $message = 'Спектакль сохранён';
        } else {
            $message = 'Заполните все обязательные поля';
        }
    } elseif (isset($_POST['delete_play'])) {
        $id = $_POST['play_id'] ?? null;
        if ($id) {
            deletePlay($id);
            $message = 'Спектакль удалён';
        }
    } elseif (isset($_POST['save_template'])) {
        $playId = $_POST['template_play_id'] ?? null;
        $templateText = $_POST['template_text'] ?? '';

        if ($playId && !empty($templateText)) {
            saveTemplate($playId, $templateText);
            $message = 'Шаблон сохранён';
        } else {
            $message = 'Выберите спектакль и введите текст шаблона';
        }
    }
}


// Обработка редактирования шаблона
$editTemplate = null;
$templatePlay = null;
if (isset($_GET['template'])) {
    $templatePlay = getPlayByShortName($_GET['template']);
    if ($templatePlay) {
        $editTemplate = getTemplateByPlayId($templatePlay['id']);
    }
}

$plays = getAllPlays();
$editPlay = null;
if (isset($_GET['edit'])) {
    $editPlay = getPlayByShortName($_GET['edit']);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление спектаклями - Репертуар театра</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Управление спектаклями</h1>
            <a href="index.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none;">Назад к главной</a>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>Список спектаклей</h2>
            <table>
                <thead>
                    <tr>
                        <th>Сокращение</th>
                        <th>Полное название</th>
                        <th>Зал</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plays as $play): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($play['short_name']); ?></td>
                            <td><?php echo htmlspecialchars($play['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($play['hall']); ?></td>
                            <td class="actions">
                                <a href="admin.php?edit=<?php echo urlencode($play['short_name']); ?>" class="btn-icon btn-secondary btn-edit" title="Редактировать спектакль"></a>
                                <a href="admin.php?template=<?php echo urlencode($play['short_name']); ?>" class="btn-icon btn-primary btn-cast" title="Редактировать состав"></a>
                                <button type="button" class="btn-icon btn-success btn-copy" title="Копировать шаблон" onclick="copyTemplate('<?php echo htmlspecialchars($play['short_name']); ?>')"></button>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="play_id" value="<?php echo $play['id']; ?>">
                                    <button type="submit" name="delete_play" class="btn-icon btn-danger btn-delete" title="Удалить спектакль" onclick="return confirm('Удалить спектакль?')"></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2><?php echo $editPlay ? 'Редактировать спектакль' : 'Добавить спектакль'; ?></h2>
            <form method="post">
                <?php if ($editPlay): ?>
                    <input type="hidden" name="play_id" value="<?php echo htmlspecialchars($editPlay['id']); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="short_name">Сокращение:</label>
                    <input type="text" id="short_name" name="short_name" value="<?php echo htmlspecialchars($editPlay['short_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="full_name">Полное название (с вики-разметкой):</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($editPlay['full_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="wiki_link">Ссылка для вики (без скобок):</label>
                    <input type="text" id="wiki_link" name="wiki_link" value="<?php echo htmlspecialchars($editPlay['wiki_link'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="hall">Зал:</label>
                    <input type="text" id="hall" name="hall" value="<?php echo htmlspecialchars($editPlay['hall'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="special_mark">Специальная отметка (например, "ПРЕМЬЕРА!"):</label>
                    <input type="text" id="special_mark" name="special_mark" value="<?php echo htmlspecialchars($editPlay['special_mark'] ?? ''); ?>" placeholder="Оставьте пустым для обычных спектаклей">
                </div>

                <div class="form-group">
                    <label for="is_subscription">
                        <input type="checkbox" id="is_subscription" name="is_subscription" value="1" <?php echo ($editPlay['is_subscription'] ?? 0) ? 'checked' : ''; ?>>
                        Это абонемент (использовать внешнюю ссылку на билеты)
                    </label>
                </div>

                <button type="submit" name="save_play" class="btn-primary">Сохранить спектакль</button>
                <?php if ($editPlay): ?>
                    <a href="admin.php" class="btn-secondary" style="margin-left: 10px;">Отмена</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($templatePlay): ?>
        <div class="section">
            <h2>Редактирование шаблона: <?php echo htmlspecialchars($templatePlay['short_name']); ?> - <?php echo htmlspecialchars($templatePlay['full_name']); ?></h2>
            <form method="post">
                <input type="hidden" name="template_play_id" value="<?php echo htmlspecialchars($templatePlay['id']); ?>">

                <div class="form-group">
                    <label for="template_text">Текст шаблона (используйте {CODE} для кода мероприятия):</label>
                    <textarea id="template_text" name="template_text" placeholder="==В ролях:==&#10;''СОСТАВ УТОЧНЯЕТСЯ''&#10;&#10;'''[https://www.zazerkal.spb.ru/tickets/{CODE}.htm|КУПИТЬ БИЛЕТ]'''"><?php echo htmlspecialchars($editTemplate['template_text'] ?? '==В ролях:==
\'\'СОСТАВ УТОЧНЯЕТСЯ\'\'

\'\'\'[https://www.zazerkal.spb.ru/tickets/{CODE}.htm|КУПИТЬ БИЛЕТ]\'\'\''); ?></textarea>
                </div>

                <button type="submit" name="save_template" class="btn-primary">Сохранить шаблон</button>
                <a href="admin.php" class="btn-secondary" style="margin-left: 10px;">Отмена</a>
            </form>
        </div>
        <?php endif; ?>


    </div>

    <script>
        // Функция для показа toast уведомлений
        function showToast(message, type = 'success') {
            // Удаляем существующие toast
            const existingToast = document.querySelector('.toast');
            if (existingToast) {
                existingToast.remove();
            }

            // Создаем новый toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;

            // Добавляем в DOM
            document.body.appendChild(toast);

            // Показываем toast
            setTimeout(() => toast.classList.add('show'), 10);

            // Автоматически скрываем через 3 секунды
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        async function copyTemplate(shortName) {
            try {
                // Получаем шаблон через AJAX
                const response = await fetch(`get_template.php?short_name=${encodeURIComponent(shortName)}`);

                if (!response.ok) {
                    throw new Error(`HTTP ошибка: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    await navigator.clipboard.writeText(data.template);
                    showToast(`Шаблон для "${data.play_name}" скопирован!`, 'success');
                } else {
                    // Если шаблон не найден, создаем базовый шаблон
                    const defaultTemplate = `==В ролях:==
''СОСТАВ УТОЧНЯЕТСЯ''

'''[https://www.zazerkal.spb.ru/tickets/{CODE}.htm|КУПИТЬ БИЛЕТ]'''`;

                    await navigator.clipboard.writeText(defaultTemplate);
                    showToast(`Базовый шаблон скопирован. Создайте свой через кнопку "Состав".`, 'info');
                }
            } catch (error) {
                console.error('Ошибка копирования:', error);
                showToast(`Ошибка копирования: ${error.message}`, 'error');
            }
        }
    </script>
</body>
</html>
