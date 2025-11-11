<?php
require_once 'config.php';
requireAuth();
require_once 'includes/navigation.php';
handleLogoutRequest();

$playOverview = getTemzaPlayOverview();
$temzaPlays = getTemzaPlaysList();

if (!$temzaPlays) {
    $temzaPlays = [];
}

$selectedPlayId = isset($_GET['play_id']) ? (int)$_GET['play_id'] : 0;
if (!$selectedPlayId && $playOverview) {
    $selectedPlayId = (int)$playOverview[0]['id'];
}

$selectedMonth = $_GET['month'] ?? 'all';
$monthsForPlay = $selectedPlayId ? getTemzaMonthsForPlay($selectedPlayId) : [];
if ($selectedMonth !== 'all' && $monthsForPlay && !in_array($selectedMonth, $monthsForPlay, true)) {
    $selectedMonth = 'all';
}

$flashMessage = $_SESSION['temza_roles_flash']['message'] ?? null;
$flashType = $_SESSION['temza_roles_flash']['type'] ?? 'success';
unset($_SESSION['temza_roles_flash']);
$errors = [];

function redirectTemzaRoles(int $playId, string $month): void
{
    $query = http_build_query([
        'play_id' => $playId,
        'month' => $month,
    ]);
    header("Location: temza_roles.php?{$query}");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $playId = isset($_POST['play_id']) ? (int)$_POST['play_id'] : 0;
    $month = $_POST['current_month'] ?? 'all';

    if (!$playId) {
        $errors[] = 'Не выбран спектакль.';
    }

    if ($action === 'save_role_mapping' && !$errors) {
        $temzaRoleRaw = trim($_POST['temza_role_raw'] ?? '');
        $temzaRoleNormalized = $_POST['temza_role_normalized'] ?? '';
        $temzaRoleSource = trim($_POST['temza_role_source'] ?? '');
        $temzaRoleSourceNormalized = $_POST['temza_role_source_normalized'] ?? '';
        if ($temzaRoleSourceNormalized === '' && $temzaRoleSource !== '') {
            $temzaRoleSourceNormalized = normalizeTemzaRole($temzaRoleSource);
        }
        if ($temzaRoleRaw === '' && $temzaRoleNormalized === '') {
            $errors[] = 'Пустое название роли.';
        } else {
            if ($temzaRoleNormalized === '') {
                $temzaRoleNormalized = normalizeTemzaRole($temzaRoleRaw);
            }
            $splitComma = isset($_POST['split_comma']) ? (bool)$_POST['split_comma'] : false;
            if (!$splitComma && $temzaRoleSource !== '') {
                $temzaRoleRaw = $temzaRoleSource;
                $temzaRoleNormalized = $temzaRoleSourceNormalized ?: normalizeTemzaRole($temzaRoleSource);
            }
            $targetRoleId = isset($_POST['target_role_id']) && $_POST['target_role_id'] !== ''
                ? (int)$_POST['target_role_id']
                : null;
            $targetGroupName = trim($_POST['target_group_name'] ?? '');
            if ($targetGroupName === '') {
                $targetGroupName = null;
            }
            $ignoreRole = isset($_POST['ignore_role']) ? (bool)$_POST['ignore_role'] : false;

            if ($targetRoleId === null && $targetGroupName === null && !$ignoreRole) {
                deleteTemzaRoleMapping($playId, $temzaRoleNormalized);
                reapplyTemzaRoleMapping($playId, $temzaRoleNormalized);
                $_SESSION['temza_roles_flash'] = [
                    'type' => 'success',
                    'message' => 'Сопоставление удалено.',
                ];
                redirectTemzaRoles($playId, $month);
            } else {
                saveTemzaRoleMapping($playId, $temzaRoleRaw ?: $temzaRoleNormalized, $temzaRoleNormalized, $splitComma, $targetRoleId, $targetGroupName, $ignoreRole);
                reapplyTemzaRoleMapping($playId, $temzaRoleNormalized);
                $_SESSION['temza_roles_flash'] = [
                    'type' => 'success',
                    'message' => 'Сопоставление сохранено.',
                ];
                redirectTemzaRoles($playId, $month);
            }
        }
    } elseif ($action === 'clear_role_mapping' && !$errors) {
        $temzaRoleNormalized = $_POST['temza_role_normalized'] ?? '';
        if ($temzaRoleNormalized !== '') {
            deleteTemzaRoleMapping($playId, $temzaRoleNormalized);
            reapplyTemzaRoleMapping($playId, $temzaRoleNormalized);
            $_SESSION['temza_roles_flash'] = [
                'type' => 'success',
                'message' => 'Сопоставление удалено.',
            ];
        }
        redirectTemzaRoles($playId, $month);
    } elseif ($action === 'apply_role_suggestion' && !$errors) {
        $temzaRoleRaw = trim($_POST['temza_role_raw'] ?? '');
        $temzaRoleNormalized = $_POST['temza_role_normalized'] ?? '';
        $temzaRoleSource = trim($_POST['temza_role_source'] ?? '');
        $temzaRoleSourceNormalized = $_POST['temza_role_source_normalized'] ?? '';
        if ($temzaRoleSourceNormalized === '' && $temzaRoleSource !== '') {
            $temzaRoleSourceNormalized = normalizeTemzaRole($temzaRoleSource);
        }
        $suggestedRoleId = isset($_POST['suggested_role_id']) ? (int)$_POST['suggested_role_id'] : 0;
        $splitComma = isset($_POST['split_comma']) ? (bool)$_POST['split_comma'] : false;
        if (!$splitComma && $temzaRoleSource !== '') {
            $temzaRoleRaw = $temzaRoleSource;
            $temzaRoleNormalized = $temzaRoleSourceNormalized ?: normalizeTemzaRole($temzaRoleSource);
        }
        $ignoreRole = isset($_POST['ignore_role']) ? (bool)$_POST['ignore_role'] : false;
        if ($suggestedRoleId > 0 && $temzaRoleNormalized !== '') {
            saveTemzaRoleMapping($playId, $temzaRoleRaw ?: $temzaRoleNormalized, $temzaRoleNormalized, $splitComma, $suggestedRoleId, null, $ignoreRole);
            reapplyTemzaRoleMapping($playId, $temzaRoleNormalized);
            $_SESSION['temza_roles_flash'] = [
                'type' => 'success',
                'message' => 'Гипотеза применена.',
            ];
        }
        redirectTemzaRoles($playId, $month);
    } elseif ($action === 'rebuild_events_bulk' && !$errors) {
        $monthFilter = $month === 'all' ? null : $month;
        $events = getTemzaEventsForPlay($playId, $monthFilter);
        if (!$events) {
            $_SESSION['temza_roles_flash'] = [
                'type' => 'error',
                'message' => 'Нет показов Temza для пересборки по выбранным фильтрам.',
            ];
        } else {
            $success = 0;
            $failed = 0;
            foreach ($events as $event) {
                $result = rebuildTemzaEventCast((int)$event['id']);
                if (!empty($result['success'])) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            $_SESSION['temza_roles_flash'] = [
                'type' => $failed ? 'error' : 'success',
                'message' => $failed
                    ? sprintf('Пересобрано %d показов, %d с ошибкой.', $success, $failed)
                    : sprintf('Пересобрано %d показов Temza.', $success),
            ];
        }
        redirectTemzaRoles($playId, $month);
    }
}

$summaryRows = ($selectedPlayId && !$errors)
    ? getTemzaRoleSummary($selectedPlayId, $selectedMonth === 'all' ? null : $selectedMonth)
    : [];
$rolesForPlay = $selectedPlayId ? getRolesByPlay($selectedPlayId) : [];
$roleMapEntries = $selectedPlayId ? getTemzaRoleMapEntries($selectedPlayId) : [];

if ($summaryRows) {
    $roleNameById = [];
    foreach ($rolesForPlay as $roleRow) {
        $roleNameById[(int)$roleRow['role_id']] = $roleRow['role_name'] ?? '';
    }
    foreach ($summaryRows as &$row) {
        $sourceNormalized = '';
        if (!empty($row['source_role'])) {
            $sourceNormalized = normalizeTemzaRole($row['source_role']);
            $row['source_role_normalized'] = $sourceNormalized;
        } else {
            $row['source_role_normalized'] = '';
        }
        if (
            $sourceNormalized
            && isset($roleMapEntries[$sourceNormalized])
        ) {
            $mapEntry = $roleMapEntries[$sourceNormalized];
            if ($row['split_comma'] === null) {
                $row['split_comma'] = $mapEntry['split_comma'];
            }
            if (
                empty($row['target_role_id'])
                && empty($row['target_group_name'])
                && empty($row['ignore_role'])
            ) {
                $row['target_role_id'] = $mapEntry['target_role_id'];
                $row['target_group_name'] = $mapEntry['target_group_name'];
                $row['ignore_role'] = $mapEntry['ignore_role'];
                if (!empty($row['target_role_id']) && empty($row['mapping_role_name'])) {
                    $roleId = (int)$row['target_role_id'];
                    if (isset($roleNameById[$roleId])) {
                        $row['mapping_role_name'] = $roleNameById[$roleId];
                    }
                }
            }
            if (
                !empty($row['target_role_id'])
                || !empty($row['target_group_name'])
                || !empty($row['ignore_role'])
            ) {
                $row['has_mapping'] = 1;
            }
        }
    }
    unset($row);
}

function stripWikiMarkupLocal(string $value): string
{
    if (preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $value, $match)) {
        return $match[1];
    }
    return $value;
}

function buildRoleNameIndex(array $roles): array
{
    $index = [];
    foreach ($roles as $role) {
        $name = $role['role_name'] ?? '';
        $clean = stripWikiMarkupLocal($name);
        $normalized = normalizeTemzaRole($clean);
        if ($normalized === '') {
            continue;
        }
        $index[$normalized] = [
            'role_id' => (int)$role['role_id'],
            'role_name' => $name,
        ];
    }
    return $index;
}

$roleNameIndex = buildRoleNameIndex($rolesForPlay);

function getRoleSuggestion(array $row, array $roleNameIndex, int $playId): ?array
{
    if (!empty($row['target_role_id']) || !empty($row['target_group_name'])) {
        return null;
    }

    $normalized = $row['temza_role_normalized'] ?? '';
    if ($normalized === '') {
        return null;
    }

    if (isset($roleNameIndex[$normalized])) {
        return [
            'role_id' => $roleNameIndex[$normalized]['role_id'],
            'role_name' => $roleNameIndex[$normalized]['role_name'],
            'reason' => 'По названию роли',
        ];
    }

    foreach ($roleNameIndex as $normName => $roleData) {
        if ($normName === '') {
            continue;
        }
        if (strpos($normalized, $normName) === 0 || strpos($normName, $normalized) === 0) {
            return [
                'role_id' => $roleData['role_id'],
                'role_name' => $roleData['role_name'],
                'reason' => 'По совпадению названия',
            ];
        }
    }

    $actorSuggestion = suggestTemzaRoleByActor($playId, $normalized);
    if ($actorSuggestion) {
        return $actorSuggestion;
    }

    return null;
}

function formatPlayLabel(array $play): string
{
    $title = $play['site_title'] ?? '';
    if ($title) {
        return $title;
    }
    $full = $play['full_name'] ?? '';
    if ($full && preg_match('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', $full, $m)) {
        return $m[1];
    }
    return $full ?: 'Спектакль';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Temza — сопоставление ролей</title>
    <link rel="stylesheet" href="css/main.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="app/globals.css">
    <style>
        .temza-table td {
            vertical-align: top;
        }
.role-samples {
    font-size: 0.85rem;
    color: #6b7280;
}
.role-samples-caption {
    font-weight: 600;
    margin-right: 4px;
}
.role-samples--source {
    font-size: 0.78rem;
    color: #9ca3af;
}
.temza-form-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .temza-form-inline select,
        .temza-form-inline input[type="text"] {
            min-width: 180px;
        }
        .temza-form-inline button {
            padding: 6px 12px;
        }
.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.75rem;
    background: #eef2ff;
    color: #4338ca;
}
.temza-layout {
    display: flex;
    gap: 20px;
}
.temza-sidebar {
    width: 240px;
    background: #f3f4f6;
    border-radius: 8px;
    padding: 16px;
}
.temza-sidebar ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.temza-sidebar li {
    margin-bottom: 8px;
}
.temza-sidebar li a {
    text-decoration: none;
    color: #111827;
}
.temza-sidebar li.pending a {
    color: #b91c1c;
    font-weight: 600;
}
.temza-sidebar li.active a {
    text-decoration: underline;
}
.temza-content {
    flex: 1;
}
.temza-row-confirmed {
    background: #ecfdf5;
}
.temza-row-confirmed td {
    border-top: 1px solid #d1fae5;
}
.temza-role-options {
    margin-top: 6px;
}
.temza-role-options label {
    margin-right: 12px;
}
    </style>
</head>
<body>
<div class="container">
    <?php renderMainNavigation('temza_roles'); ?>
    <div class="header">
        <div>
            <h1>Temza — сопоставление ролей</h1>
            <p class="header-subtitle">
                Приводим названия ролей из Temza к ролям и группам в карточках спектаклей.
            </p>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert <?php echo $flashType === 'error' ? 'alert-error' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <form method="get" class="temza-form-inline">
            <input type="hidden" name="play_id" value="<?php echo (int)$selectedPlayId; ?>">
            <label>
                <span class="label">Месяц</span>
                <select name="month" onchange="this.form.submit()">
                    <option value="all" <?php echo $selectedMonth === 'all' ? 'selected' : ''; ?>>Все</option>
                    <?php foreach ($monthsForPlay as $month): ?>
                        <option value="<?php echo htmlspecialchars($month); ?>" <?php echo $selectedMonth === $month ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($month); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="btn-primary">Показать</button></noscript>
        </form>
    </div>

    <?php if (!$selectedPlayId): ?>
        <div class="section">
            <p>Нет спектаклей с загруженными данными Temza. Сначала сопоставьте названия на странице «Темза», затем повторите импорт.</p>
        </div>
    <?php else: ?>
        <div class="temza-layout">
            <aside class="temza-sidebar">
                <h3>Спектакли Temza</h3>
                <ul>
                    <?php foreach ($playOverview as $play): 
                        $pending = (int)($play['role_pending'] ?? 0);
                        $isActive = (int)$play['id'] === $selectedPlayId;
                        $class = $pending > 0 ? 'pending' : 'ready';
                        if ($isActive) $class .= ' active';
                        $query = http_build_query([
                            'play_id' => (int)$play['id'],
                            'month' => $selectedMonth,
                        ]);
                    ?>
                        <li class="<?php echo $class; ?>">
                            <a href="temza_roles.php?<?php echo $query; ?>">
                                <?php echo htmlspecialchars(formatPlayLabel($play)); ?>
                                <?php if ($pending > 0): ?>
                                    <span class="badge" style="margin-left: 4px;"><?php echo $pending; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>
            <div class="temza-content">
                <div class="section">
                    <h2>Роли Temza → карточка спектакля</h2>
                    <form method="post" class="temza-form-inline" style="margin-bottom: 12px;">
                        <input type="hidden" name="action" value="rebuild_events_bulk">
                        <input type="hidden" name="play_id" value="<?php echo (int)$selectedPlayId; ?>">
                        <input type="hidden" name="current_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                        <button type="submit" class="btn-secondary">
                            Пересобрать составы
                            <?php if ($selectedMonth !== 'all'): ?>
                                за <?php echo htmlspecialchars($selectedMonth); ?>
                            <?php endif; ?>
                        </button>
                        <span class="text-sm text-gray-600">Применяет текущие правила к всем показам выбранного спектакля.</span>
                    </form>
            <?php if (!$summaryRows): ?>
                <p>Нет данных для выбранных фильтров.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="temza-table">
                        <thead>
                        <tr>
                            <th style="width: 30%;">Роль Temza</th>
                            <th style="width: 20%;">Статистика</th>
                            <th style="width: 30%;">Сопоставление</th>
                            <th style="width: 20%;">Гипотеза / статус</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($summaryRows as $index => $row):
                            $formId = 'temza-role-form-' . $index;
                            $suggestion = getRoleSuggestion($row, $roleNameIndex, $selectedPlayId);
                            $splitChecked = !isset($row['split_comma']) || (int)$row['split_comma'] === 1;
                            $effectiveNormalizedValue = $row['temza_role_normalized'] ?? '';
                            if (!$splitChecked && !empty($row['source_role_normalized'])) {
                                $effectiveNormalizedValue = $row['source_role_normalized'];
                            }
                        ?>
                            <tr class="<?php echo !empty($row['has_mapping']) ? 'temza-row-confirmed' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($row['sample_role'] ?? '—'); ?></strong>
                                    <div class="role-samples">
                                        <?php if (!empty($row['actor_samples'])): ?>
                                            <span class="role-samples-caption">Примеры:</span>
                                            <span><?php echo htmlspecialchars($row['actor_samples']); ?></span>
                                        <?php else: ?>
                                            &nbsp;
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($row['source_role'])): ?>
                                        <div class="role-samples role-samples--source">
                                            <span class="role-samples-caption">Temza</span>
                                            <span><?php echo htmlspecialchars($row['source_role']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['months'])): ?>
                                        <div class="role-samples">Месяцы: <?php echo htmlspecialchars($row['months']); ?></div>
                                    <?php endif; ?>
                                    <div class="role-samples temza-role-options">
                                        <div>
                                            <input type="hidden" name="split_comma" value="0" form="<?php echo $formId; ?>">
                                            <label>
                                                <input type="checkbox"
                                                       name="split_comma"
                                                       value="1"
                                                       form="<?php echo $formId; ?>"
                                                    <?php echo $splitChecked ? 'checked' : ''; ?>>
                                                Делить по запятым
                                            </label>
                                        </div>
                                        <div>
                                            <input type="hidden" name="ignore_role" value="0" form="<?php echo $formId; ?>">
                                            <label>
                                                <input type="checkbox"
                                                       name="ignore_role"
                                                       value="1"
                                                       form="<?php echo $formId; ?>"
                                                    <?php echo !empty($row['ignore_role']) ? 'checked' : ''; ?>>
                                                Игнорировать роль
                                            </label>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>Всего: <?php echo (int)$row['usage_count']; ?></div>
                                    <div>Не сопоставлено: <?php echo (int)$row['pending_count']; ?></div>
                                    <?php if (!empty($row['first_date'])): ?>
                                        <div>Впервые: <?php echo htmlspecialchars(date('d.m.Y', strtotime($row['first_date']))); ?>
                                            <?php if (!empty($row['first_time'])): ?>
                                                <?php echo htmlspecialchars(substr($row['first_time'], 0, 5)); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" id="<?php echo $formId; ?>" class="temza-form-inline">
                                        <input type="hidden" name="play_id" value="<?php echo (int)$selectedPlayId; ?>">
                                        <input type="hidden" name="current_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                                        <input type="hidden" name="temza_role_raw" value="<?php echo htmlspecialchars($row['sample_role'] ?? ''); ?>">
                                        <input type="hidden" name="temza_role_source" value="<?php echo htmlspecialchars($row['source_role'] ?? ''); ?>">
                                        <input type="hidden" name="temza_role_source_normalized" value="<?php echo htmlspecialchars(!empty($row['source_role']) ? normalizeTemzaRole($row['source_role']) : ''); ?>">
                                        <input type="hidden" name="temza_role_normalized" value="<?php echo htmlspecialchars($effectiveNormalizedValue); ?>">
                                        <input type="hidden" name="suggested_role_id" value="<?php echo $suggestion['role_id'] ?? ''; ?>">
                                        <input type="hidden" name="action" value="save_role_mapping">
                                        <label>
                                            <span class="label">Роль в карточке</span>
                                            <select name="target_role_id">
                                                <option value="">— не выбрано —</option>
                                                <?php foreach ($rolesForPlay as $role): ?>
                                                    <option value="<?php echo (int)$role['role_id']; ?>"
                                                        <?php echo ((int)($row['target_role_id'] ?? 0) === (int)$role['role_id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="label">Группа</span>
                                            <input type="text" name="target_group_name"
                                                   value="<?php echo htmlspecialchars($row['target_group_name'] ?? ''); ?>"
                                                   placeholder="например, Купцы">
                                        </label>
                                        <button type="submit" class="btn-primary">Сохранить</button>
                                        <button type="submit" name="action" value="clear_role_mapping" class="btn-secondary">Очистить</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if (!empty($row['target_role_id'])): ?>
                                        <div class="role-samples">→ <?php echo htmlspecialchars($row['mapping_role_name'] ?? ''); ?></div>
                                    <?php elseif (!empty($row['target_group_name'])): ?>
                                        <div class="role-samples">→ группа «<?php echo htmlspecialchars($row['target_group_name']); ?>»</div>
                                    <?php elseif (!empty($row['ignore_role'])): ?>
                                        <div class="role-samples">→ роль игнорируется</div>
                                    <?php else: ?>
                                        <div class="role-samples text-muted">Правило не задано</div>
                                    <?php endif; ?>
                                    <?php if ($suggestion): ?>
                                        <div class="role-samples" style="margin-top: 6px;">
                                            <span class="badge">Гипотеза: <?php echo htmlspecialchars($suggestion['role_name'] ?? ''); ?></span>
                                            <span><?php echo htmlspecialchars($suggestion['reason'] ?? ''); ?></span>
                                        </div>
                                        <button type="submit"
                                                form="<?php echo $formId; ?>"
                                                name="action"
                                                value="apply_role_suggestion"
                                                class="btn-secondary"
                                                style="margin-top: 6px;">
                                            Принять гипотезу
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
