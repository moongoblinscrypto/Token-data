<?php
// mooglife/index.php

require __DIR__ . '/includes/layout/header.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout/navbar.php';

// Requested page
$page = $_GET['p'] ?? 'dashboard';
$page = basename($page); // sanitize

// Pages that do NOT require login
$public_pages = ['login'];

// Guard: if not logged in and page is protected, send to login
if (!mg_is_logged_in() && !in_array($page, $public_pages, true)) {
    // remember where they wanted to go
    $_SESSION['mg_after_login'] = $page;
    $page = 'login';
}

$file = __DIR__ . '/pages/' . $page . '.php';

if (!file_exists($file)) {
    echo "<h1>404</h1><p>Page not found.</p>";
} else {
    require $file;
}

require __DIR__ . '/includes/layout/footer.php';
