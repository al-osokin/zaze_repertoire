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
        .history-item {
            border: 1px solid #dee2e6;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            background-color: #f8f9fa;
        }
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .history-title {
            font-size: 18px;
            font-weight: bold;
            color: #007bff;
            margin: 0;
        }
        .history-date {
            color: #6c757d;
            font-size: 14px;
        }
        .source-text, .result-text {
            background-color: white;
            border: 1px solid #dee2e6;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }
        .tabs {
            display: flex;
            margin-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 16px;
        }
        .tab.active {
            border-bottom-color: #007bff;
            color: #007bff;
            font-weight: bold;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .copy-btn {
            background-color: #17a2b8;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
        }
        .no-history {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
    </style>
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
                alert('Текст скопирован в буфер обмена!');
            }).catch(err => {
                console.error('Ошибка копирования:', err);
                alert('Не удалось скопировать текст');
            });
        }
    </script>
</body>
</html>
