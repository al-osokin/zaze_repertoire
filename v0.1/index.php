<?php
require_once 'config.php';
requireAuth();

require_once 'parser.php';

$message = '';
$result = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout'])) {
        logout();
    } elseif (isset($_POST['parse'])) {
        $sourceText = $_POST['source_text'] ?? '';
        if (!empty($sourceText)) {
            $result = parseRepertoireText($sourceText);
        } else {
            $message = 'Введите текст черновика';
        }
    } elseif (isset($_POST['save'])) {
        $sourceText = $_POST['source_text'] ?? '';
        $resultWiki = $_POST['result_wiki'] ?? '';
        $monthYear = $_POST['month_year'] ?? '';

        // Не сохраняем ошибки в историю
        if (strpos($resultWiki, '❌ ОШИБКА:') === 0) {
            $message = 'Невозможно сохранить - в результате есть ошибки';
        } elseif (!empty($sourceText) && !empty($resultWiki) && !empty($monthYear)) {
            saveRepertoireHistory($monthYear, $sourceText, $resultWiki);
            $message = 'Афиша сохранена в историю';
        } else {
            $message = 'Заполните все поля для сохранения';
        }
    }
}

// Загружаем историю
$history = getRepertoireHistory(5);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Репертуар театра</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
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
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .form-section, .result-section {
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
        textarea {
            width: 100%;
            min-height: 200px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            resize: vertical;
        }
        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
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
        .btn-success {
            background-color: #28a745;
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
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .result-text {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        .result-text.error-result {
            background-color: #fff5f5;
            border-color: #feb2b2;
            color: #c53030;
        }
        .copy-btn {
            margin-top: 10px;
            background-color: #17a2b8;
            color: white;
        }
        .history-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .history-item {
            border: 1px solid #dee2e6;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
        .history-item h4 {
            margin: 0 0 5px 0;
            color: #007bff;
        }
        .history-item small {
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Система управления репертуаром театра</h1>
            <div>
                <span>Привет, <?php echo htmlspecialchars(getCurrentUser()); ?>!</span>
                <form method="post" style="display: inline; margin-left: 10px;">
                    <button type="submit" name="logout" class="btn-secondary">Выход</button>
                </form>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'сохранена') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-section">
            <h2>Создание афиши</h2>
            <form method="post">
                <div class="form-group">
                    <label for="source_text">Текст черновика:</label>
                    <textarea id="source_text" name="source_text" placeholder="Вставьте текст черновика или загрузите файл..."><?php echo htmlspecialchars($_POST['source_text'] ?? ''); ?></textarea>
                </div>

                <div class="buttons">
                    <button type="submit" name="parse" class="btn-primary">Преобразовать</button>
                    <a href="admin.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none; display: inline-block;">Управление спектаклями</a>
                    <a href="history.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none; display: inline-block;">История</a>
                </div>
            </form>
        </div>

        <?php if ($result): ?>
            <div class="result-section">
                <h2><?php echo strpos($result, '❌ ОШИБКА:') === 0 ? 'Ошибка обработки' : 'Результат'; ?></h2>
                <div class="result-text <?php echo strpos($result, '❌ ОШИБКА:') === 0 ? 'error-result' : ''; ?>" id="result-text"><?php echo htmlspecialchars($result); ?></div>
                <button class="copy-btn" onclick="copyToClipboard()">Копировать результат</button>

                <?php if (strpos($result, '❌ ОШИБКА:') !== 0): ?>
                <form method="post" style="margin-top: 15px;">
                    <input type="hidden" name="source_text" value="<?php echo htmlspecialchars($_POST['source_text'] ?? ''); ?>">
                    <input type="hidden" name="result_wiki" value="<?php echo htmlspecialchars($result); ?>">
                    <div class="form-group">
                        <label for="month_year">Месяц и год (для истории):</label>
                        <input type="text" id="month_year" name="month_year" value="<?php echo htmlspecialchars($_POST['month_year'] ?? ''); ?>" placeholder="например: сентябрь 2025" required>
                    </div>
                    <button type="submit" name="save" class="btn-success">Сохранить в историю</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="history-section">
            <h2>Недавняя история</h2>
            <?php if (empty($history)): ?>
                <p>История пуста</p>
            <?php else: ?>
                <?php foreach ($history as $item): ?>
                    <div class="history-item">
                        <h4><?php echo htmlspecialchars($item['month_year']); ?></h4>
                        <small><?php echo htmlspecialchars($item['created_at']); ?></small>
                        <p><?php echo nl2br(htmlspecialchars(substr($item['source_text'], 0, 100))); ?>...</p>
                        <a href="history.php?id=<?php echo $item['id']; ?>">Просмотреть</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyToClipboard() {
            const text = document.getElementById('result-text').textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('Результат скопирован в буфер обмена!');
            });
        }
    </script>
</body>
</html>
