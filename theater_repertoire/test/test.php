<?php
// Диагностический файл для проверки работоспособности PHP
echo "<h1>PHP Test</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p>Script Path: " . __FILE__ . "</p>";

// Проверка расширений
$extensions = ['pdo', 'pdo_mysql', 'mbstring'];
echo "<h2>PHP Extensions:</h2>";
foreach ($extensions as $ext) {
    echo "<p>$ext: " . (extension_loaded($ext) ? '✓ Loaded' : '✗ Not loaded') . "</p>";
}

// Проверка прав доступа
echo "<h2>File Permissions:</h2>";
$files = ['config.php', 'db.php', 'parser.php', 'generate_password.php'];
foreach ($files as $file) {
    if (file_exists($file)) {
        $perms = substr(sprintf('%o', fileperms($file)), -4);
        echo "<p>$file: $perms</p>";
    } else {
        echo "<p>$file: File not found</p>";
    }
}

echo "<h2>Environment:</h2>";
echo "<pre>";
print_r($_SERVER);
echo "</pre>";
?>
