<?php
// mooglife/index.php
// Main router for Mooglife Local dashboard.

declare(strict_types=1);

// Figure out which page to load
$page = $_GET['p'] ?? 'dashboard';
$page = basename($page); // basic safety

$pagesDir   = __DIR__ . '/pages';
$pageFile   = $pagesDir . '/' . $page . '.php';
$notFound   = $pagesDir . '/404.php';

// If the page file doesn't exist, fall back to 404
if (!is_file($pageFile)) {
    $page     = '404';
    $pageFile = $notFound;
}

// Make current page available to layout/includes if needed
$currentPage = $page;

// Layout pieces
$headerFile = __DIR__ . '/includes/layout/header.php';
$navFile    = __DIR__ . '/includes/layout/navbar.php';
$footerFile = __DIR__ . '/includes/layout/footer.php';

// Render layout
if (is_file($headerFile)) {
    require $headerFile;
}

if (is_file($navFile)) {
    require $navFile;
}

// Main page content
require $pageFile;

if (is_file($footerFile)) {
    require $footerFile;
}
