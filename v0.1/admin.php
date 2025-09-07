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
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        textarea {
            min-height: 100px;
            font-family: monospace;
            resize: vertical;
        }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .actions {
            display: flex;
            gap: 5px;
        }
        .template-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
    </style>
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
                                <a href="admin.php?edit=<?php echo urlencode($play['short_name']); ?>" class="btn-secondary" style="padding: 5px 10px; text-decoration: none; font-size: 12px;">Редактировать</a>
                                <a href="admin.php?template=<?php echo urlencode($play['short_name']); ?>" class="btn-primary" style="padding: 5px 10px; text-decoration: none; font-size: 12px;">Состав</a>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="play_id" value="<?php echo $play['id']; ?>">
                                    <button type="submit" name="delete_play" class="btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('Удалить спектакль?')">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>


    </div>
</body>
</html>
