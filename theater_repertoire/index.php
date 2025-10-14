<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    logout();
}
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
            <h1>Репертуар театра</h1>
            <div>
                <span>Привет, <?php echo htmlspecialchars(getCurrentUser()); ?>!</span>
                <form method="post" style="display: inline; margin-left: 10px;">
                    <button type="submit" name="logout" class="btn-secondary">Выход</button>
                </form>
            </div>
        </div>

        <div class="section">
            <h2>Быстрые действия</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
                <a href="scraper.php" class="btn-secondary" style="justify-content: center; font-size: 16px; padding: 18px;">Парсинг афиши</a>
                <a href="schedule.php" class="btn-primary" style="justify-content: center; font-size: 16px; padding: 18px;">Заполнение составов</a>
                <a href="admin.php" class="btn-secondary" style="justify-content: center; font-size: 16px; padding: 18px;">Управление спектаклями</a>
                <a href="manual.php" class="btn-secondary" style="justify-content: center; font-size: 16px; padding: 18px;">Создать вручную</a>
            </div>
        </div>

        <div class="section">
            <h2>Справка</h2>
            <ul style="margin: 0; padding-left: 18px; line-height: 1.8;">
                <li><strong>Парсинг афиши</strong> — загрузите расписание с сайта театра для последующей работы.</li>
                <li><strong>Заполнение составов</strong> — назначьте артистов на роли для каждого спектакля в афише месяца.</li>
                <li><strong>Управление спектаклями</strong> — редактируйте список спектаклей и их шаблоны.</li>
                <li><strong>Создать вручную</strong> — используйте табличный черновик, чтобы вручную собрать афишу.</li>
            </ul>
        </div>
    </div>
</body>
</html>
