<?php
require_once 'config.php';
requireAuth();

$pdo = getDBConnection();

$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');

$stmt = $pdo->prepare("
    SELECT
        er.id AS performance_id,
        er.event_date,
        er.event_time,
        p.full_name AS play_name,
        (SELECT COUNT(DISTINCT pra.artist_id) FROM performance_roles_artists pra WHERE pra.performance_id = er.id AND pra.artist_id IS NOT NULL) AS assigned_artists_count,
        (SELECT COUNT(*) FROM roles r WHERE r.play_id = er.play_id) AS total_roles_count
    FROM
        events_raw er
    JOIN
        plays p ON er.play_id = p.id
    WHERE
        MONTH(er.event_date) = ? AND YEAR(er.event_date) = ?
    ORDER BY
        er.event_date, er.event_time
");
$stmt->execute([$month, $year]);
$performances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Афиша на месяц - Управление составами</title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Афиша на <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h1>
            <div>
                <a href="index.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none;">Главная</a>
                <a href="scraper.php" class="btn-secondary" style="padding: 10px 20px; text-decoration: none; margin-left: 10px;">Парсинг афиши</a>
            </div>
        </div>

        <div class="section">
            <div class="month-nav" style="margin-bottom: 20px;">
                <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 -1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 -1 month')); ?>" class="btn-secondary">Предыдущий месяц</a>
                <a href="?month=<?php echo date('m', strtotime($year.'-'.$month.'-01 +1 month')); ?>&year=<?php echo date('Y', strtotime($year.'-'.$month.'-01 +1 month')); ?>" class="btn-secondary">Следующий месяц</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Время</th>
                        <th>Спектакль</th>
                        <th>Статус состава</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($performances as $performance): ?>
                    <tr>
                        <td><?php echo date('d.m.Y', strtotime($performance['event_date'])); ?></td>
                        <td><?php echo date('H:i', strtotime($performance['event_time'])); ?></td>
                        <td><?php echo htmlspecialchars($performance['play_name']); ?></td>
                        <td>
                            <?php
                                if ($performance['total_roles_count'] == 0) {
                                    echo "Роли не определены";
                                } elseif ($performance['assigned_artists_count'] == 0) {
                                    echo "Не заполнено";
                                } elseif ($performance['assigned_artists_count'] < $performance['total_roles_count']) {
                                    echo "Частично (" . $performance['assigned_artists_count'] . "/" . $performance['total_roles_count'] . ")";
                                } else {
                                    echo "Заполнено";
                                }
                            ?>
                        </td>
                        <td class="actions">
                             <a href="edit_cast.php?performance_id=<?php echo $performance['performance_id']; ?>" class="btn-icon btn-primary btn-cast" title="Редактировать состав"></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
