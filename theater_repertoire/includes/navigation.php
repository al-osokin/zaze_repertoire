<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (!function_exists('handleLogoutRequest')) {
    function handleLogoutRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
            logout();
        }
    }
}

if (!function_exists('renderMainNavigation')) {
    /**
     * Renders the primary navigation menu.
     *
     * @param string|null $activeKey Active menu key: schedule|scraper|plays|settings.
     */
    function renderMainNavigation(?string $activeKey = null): void
    {
        $menuItems = [
            'schedule' => ['label' => 'Афиша', 'href' => 'schedule.php'],
            'scraper'  => ['label' => 'Парсинг', 'href' => 'scraper.php'],
            'plays'    => ['label' => 'Спектакли', 'href' => 'admin.php'],
        ];

        $activeKey = $activeKey ?: pathinfo($_SERVER['PHP_SELF'] ?? '', PATHINFO_FILENAME);
        $currentUser = getCurrentUser();
        $settingsActive = $activeKey === 'settings';

        ?>
        <nav class="main-nav">
            <div class="nav-brand">
                <a href="schedule.php" class="nav-logo">ZAZE</a>
            </div>
            <div class="nav-links">
                <?php foreach ($menuItems as $key => $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>"
                       class="nav-link <?php echo $key === $activeKey ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($item['label']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="nav-actions">
                <a href="vk_settings.php"
                   class="nav-icon-link <?php echo $settingsActive ? 'active' : ''; ?>"
                   title="Настройки VK">
                    <span aria-hidden="true">&#9881;</span>
                    <span class="sr-only">Настройки VK</span>
                </a>
                <?php if ($currentUser): ?>
                    <div class="nav-user">
                        <span class="nav-user-name"><?php echo htmlspecialchars($currentUser); ?></span>
                        <form method="post">
                            <button type="submit" name="logout" class="btn-secondary btn-logout">Выход</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
        <?php
    }
}
