<?php
require_once 'config.php';
requireAuth();
require_once 'includes/navigation.php';
handleLogoutRequest();

require_once 'parser.php';

$message = '';
$result = '';
$sourceText = $_POST['source_text'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['parse'])) {
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
    <div class="container">
        <?php renderMainNavigation(); ?>
        <div class="header">
            <div>
                <h1>Создать афишу вручную</h1>
                <p class="header-subtitle">Черновик для разовых публикаций</p>
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
