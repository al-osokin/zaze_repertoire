<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

$message = '';
$repertoire = null;

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $repertoire = getRepertoireById($id);
}

$history = getRepertoireHistory(20); // Больше записей для истории
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>История афиш - Репертуар театра</title>
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo $repertoire ? 'Просмотр афиши' : 'История афиш'; ?></h1>
            <a href="index.php" class="btn-secondary">Назад к главной</a>
        </div>

        <?php if ($repertoire): ?>
            <div class="section">
                <div class="history-header">
                    <h2 class="history-title"><?php echo htmlspecialchars($repertoire['month_year']); ?></h2>
                    <span class="history-date"><?php echo htmlspecialchars($repertoire['created_at']); ?></span>
                </div>

                <div class="tabs">
                    <button class="tab active" onclick="showTab('source')">Исходный текст</button>
                    <button class="tab" onclick="showTab('result')">Результат</button>
                </div>

                <div id="source" class="tab-content active">
                    <h3>Черновик</h3>
                    <div class="source-text"><?php echo htmlspecialchars($repertoire['source_text']); ?></div>
                    <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($repertoire['source_text']); ?>')">Копировать черновик</button>
                </div>

                <div id="result" class="tab-content">
                    <h3>Готовая афиша</h3>
                    <div class="result-text"><?php echo htmlspecialchars($repertoire['result_wiki']); ?></div>
                    <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($repertoire['result_wiki']); ?>')">Копировать афишу</button>
                </div>
            </div>
        <?php else: ?>
            <div class="section">
                <h2>История созданных афиш</h2>

                <?php if (empty($history)): ?>
                    <div class="no-history">
                        <h3>История пуста</h3>
                        <p>Вы ещё не сохранили ни одной афиши</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($history as $item): ?>
                        <div class="history-item">
                            <div class="history-header">
                                <h3 class="history-title"><?php echo htmlspecialchars($item['month_year']); ?></h3>
                                <span class="history-date"><?php echo htmlspecialchars($item['created_at']); ?></span>
                            </div>

                            <div class="tabs">
                                <button class="tab active" onclick="showTab('source-<?php echo $item['id']; ?>')">Исходный текст</button>
                                <button class="tab" onclick="showTab('result-<?php echo $item['id']; ?>')">Результат</button>
                            </div>

                            <div id="source-<?php echo $item['id']; ?>" class="tab-content active">
                                <div class="source-text"><?php echo nl2br(htmlspecialchars(substr($item['source_text'], 0, 200))); ?><?php echo strlen($item['source_text']) > 200 ? '...' : ''; ?></div>
                                <a href="history.php?id=<?php echo $item['id']; ?>" style="color: #007bff;">Просмотреть полностью</a>
                            </div>

                            <div id="result-<?php echo $item['id']; ?>" class="tab-content">
                                <div class="result-text"><?php echo nl2br(htmlspecialchars(substr($item['result_wiki'], 0, 300))); ?><?php echo strlen($item['result_wiki']) > 300 ? '...' : ''; ?></div>
                                <a href="history.php?id=<?php echo $item['id']; ?>" style="color: #007bff;">Просмотреть полностью</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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

        function showTab(tabId) {
            // Скрываем все вкладки в текущем контейнере
            const container = event.target.closest('.history-item') || document;
            const tabs = container.querySelectorAll('.tab');
            const contents = container.querySelectorAll('.tab-content');

            tabs.forEach(tab => tab.classList.remove('active'));
            contents.forEach(content => content.classList.remove('active'));

            // Показываем выбранную вкладку
            event.target.classList.add('active');
            const targetContent = container.querySelector('#' + tabId);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        }

        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Текст скопирован в буфер обмена!', 'success');
            }).catch(err => {
                console.error('Ошибка копирования:', err);
                showToast('Не удалось скопировать текст', 'error');
            });
        }
    </script>
</body>
</html>
