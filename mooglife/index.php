<?php
// mooglife/index.php
require __DIR__ . '/includes/layout/header.php';
require __DIR__ . '/includes/layout/navbar.php';

$page = $_GET['p'] ?? 'dashboard';
$file = __DIR__ . '/pages/' . basename($page) . '.php';

if (!file_exists($file)) {
    echo "<h1>404</h1><p>Page not found.</p>";
} else {
    require $file;
}

require __DIR__ . '/includes/layout/footer.php';
