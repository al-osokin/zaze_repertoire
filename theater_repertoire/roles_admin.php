<?php
require_once 'config.php';
require_once 'db.php';
requireAuth();

$pdo = getDBConnection();
$playId = $_GET['play_id'] ?? null;
$message = '';

if (!$playId) {
    die("ID спектакля не указан.");
}

$play = getPlayById($playId);
if (!$play) {
    die("Спектакль не найден.");
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_role') {
        $roleId = $_POST['role_id'] ?? null;
        $roleName = trim($_POST['role_name'] ?? '');
        $roleDescription = trim($_POST['role_description'] ?? '');
        $expectedArtistType = $_POST['expected_artist_type'] ?? 'artist';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($roleName)) {
            $message = "Название роли не может быть пустым.";
        } else {
            try {
                if ($roleId) {
                    // Обновление роли
                    $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, role_description = ?, expected_artist_type = ?, sort_order = ?, updated_at = NOW() WHERE role_id = ? AND play_id = ?");
                    $stmt->execute([$roleName, $roleDescription, $expectedArtistType, $sortOrder, $roleId, $playId]);
                    $message = "Роль обновлена.";
                } else {
                    // Создание новой роли
                    $stmt = $pdo->prepare("INSERT INTO roles (play_id, role_name, role_description, expected_artist_type, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$playId, $roleName, $roleDescription, $expectedArtistType, $sortOrder]);
                    $message = "Роль добавлена.";
                }
                header("Location: roles_admin.php?play_id=$playId&message=" . urlencode($message));
                exit;
            } catch (PDOException $e) {
                $message = "Ошибка сохранения роли: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_role') {
        $roleId = $_POST['role_id'] ?? null;
        if ($roleId) {
            try {
                $stmt = $pdo->prepare("DELETE FROM roles WHERE role_id = ? AND play_id = ?");
                $stmt->execute([$roleId, $playId]);
                $message = "Роль удалена.";
                header("Location: roles_admin.php?play_id=$playId&message=" . urlencode($message));
                exit;
            } catch (PDOException $e) {
                $message = "Ошибка удаления роли: " . $e->getMessage();
            }
        }
    }
}

// Получаем все роли для данного спектакля
$roles = [];
$rolesStmt = $pdo->prepare("SELECT * FROM roles WHERE play_id = ? ORDER BY sort_order, role_name");
$rolesStmt->execute([$playId]);
$roles = $rolesStmt->fetchAll();

// Для формы редактирования/добавления
$editRole = null;
if (isset($_GET['edit_role_id'])) {
    $editRoleId = $_GET['edit_role_id'];
    $editRoleStmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = ? AND play_id = ?");
    $editRoleStmt->execute([$editRoleId, $playId]);
    $editRole = $editRoleStmt->fetch();
}

$artistTypes = ['artist', 'conductor', 'pianist', 'other'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление ролями для <?php echo htmlspecialchars($play['full_name']); ?></title>
    <link rel="stylesheet" href="css/main.css">
    <link href="https://cdn.tailwindcss.com" rel="stylesheet">
    <link rel="stylesheet" href="app/globals.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Управление ролями</h1>
            <h2>Спектакль: <?php echo htmlspecialchars($play['full_name']); ?></h2>
            <a href="admin.php" class="btn-secondary">Назад к спектаклям</a>
        </div>

        <?php if (!empty($_GET['message'])): ?>
            <div class="message"><?php echo htmlspecialchars($_GET['message']); ?></div>
        <?php elseif (!empty($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>Список ролей</h2>
            <table>
                <thead>
                    <tr>
                        <th>Роль</th>
                        <th>Описание</th>
                        <th>Тип артиста</th>
                        <th>Порядок</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                            <td><?php echo htmlspecialchars($role['role_description'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($role['expected_artist_type']); ?></td>
                            <td><?php echo htmlspecialchars($role['sort_order']); ?></td>
                            <td class="actions">
                                <a href="roles_admin.php?play_id=<?php echo $playId; ?>&edit_role_id=<?php echo $role['role_id']; ?>" class="btn-icon btn-secondary btn-edit" title="Редактировать роль"></a>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?php echo $role['role_id']; ?>">
                                    <button type="submit" class="btn-icon btn-danger btn-delete" title="Удалить роль" onclick="return confirm('Удалить роль <?php echo htmlspecialchars($role['role_name']); ?>?')"></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2><?php echo $editRole ? 'Редактировать роль' : 'Добавить новую роль'; ?></h2>
            <form method="post">
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="play_id" value="<?php echo $playId; ?>">
                <?php if ($editRole): ?>
                    <input type="hidden" name="role_id" value="<?php echo htmlspecialchars($editRole['role_id']); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="role_name">Название роли:</label>
                    <input type="text" id="role_name" name="role_name" value="<?php echo htmlspecialchars($editRole['role_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="role_description">Описание роли (необязательно):</label>
                    <input type="text" id="role_description" name="role_description" value="<?php echo htmlspecialchars($editRole['role_description'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="expected_artist_type">Ожидаемый тип артиста:</label>
                    <select id="expected_artist_type" name="expected_artist_type">
                        <?php foreach ($artistTypes as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo (($editRole['expected_artist_type'] ?? 'artist') === $type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort_order">Порядок сортировки:</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?php echo htmlspecialchars($editRole['sort_order'] ?? 0); ?>">
                </div>

                <div class="buttons">
                    <button type="submit" class="btn-primary">Сохранить роль</button>
                    <?php if ($editRole): ?>
                        <a href="roles_admin.php?play_id=<?php echo $playId; ?>" class="btn-secondary">Отмена</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
