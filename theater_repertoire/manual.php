<?php
require_once 'config.php';
requireAuth();

require_once 'parser.php';

$message = '';
$result = '';
$sourceText = $_POST['source_text'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout'])) {
        logout();
    } elseif (isset($_POST['parse'])) {
        if (trim($sourceText) === '') {
            $message = 'Введите текст черновика.';
        } else {
            $result = parseRepertoireText($sourceText);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создать афишу вручную</title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Создать афишу вручную</h1>
            <div>
                <a href="index.php" class="btn-secondary" style="margin-right: 10px; text-decoration: none; padding: 10px 18px;">Главная</a>
                <a href="scraper.php" class="btn-secondary" style="margin-right: 10px; text-decoration: none; padding: 10px 18px;">Парсинг афиши</a>
                <a href="admin.php" class="btn-secondary" style="margin-right: 10px; text-decoration: none; padding: 10px 18px;">Спектакли</a>
                <form method="post" style="display: inline;">
                    <button type="submit" name="logout" class="btn-secondary">Выход</button>
                </form>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message error"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>Черновик</h2>
            <form method="post">
                <div class="form-group">
                    <label for="source_text">Текст черновика:</label>
                    <textarea id="source_text" name="source_text" placeholder="Вставьте текст черновика здесь..." rows="12"><?php echo htmlspecialchars($sourceText); ?></textarea>
                </div>

                <div class="buttons">
                    <button type="submit" name="parse" class="btn-primary">Преобразовать</button>
                </div>
            </form>
        </div>

        <?php if ($result): ?>
            <div class="section">
                <h2><?php echo strpos($result, '❌ ОШИБКА:') === 0 ? 'Обнаружены ошибки' : 'Результат'; ?></h2>
                <div class="result-text <?php echo strpos($result, '❌ ОШИБКА:') === 0 ? 'error-result' : ''; ?>" id="manual_result_preview">
                    <?php echo nl2br(htmlspecialchars($result)); ?>
                </div>
                <?php if (strpos($result, '❌ ОШИБКА:') !== 0): ?>
                    <textarea id="manual_result" style="display: none;" readonly><?php echo htmlspecialchars($result, ENT_NOQUOTES); ?></textarea>
                    <button type="button" class="btn-secondary" onclick="copyManualResult()">Скопировать результат</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function copyManualResult() {
            const target = document.getElementById('manual_result');
            if (!target) {
                return;
            }
            navigator.clipboard.writeText(target.value)
                .then(() => alert('Результат скопирован.'))
                .catch(() => alert('Не удалось скопировать результат.'));
        }
    </script>
</body>
</html>
