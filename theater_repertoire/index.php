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
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
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

        function copyToClipboard() {
            const text = document.getElementById('result-text').textContent;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Результат скопирован в буфер обмена!', 'success');
            }).catch(err => {
                showToast('Ошибка копирования', 'error');
            });
        }
    </script>
</body>
</html>
