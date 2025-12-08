<?php
// mooglife/includes/layout/navbar.php

$current = $_GET['p'] ?? 'dashboard';

function nav_link($slug, $label, $current) {
    $url = '?p=' . urlencode($slug);
    $cls = ($slug === $current) ? 'active' : '';
    echo '<a class="'.$cls.'" href="'.$url.'">'.htmlspecialchars($label).'</a>';
}
?>

<style>
.nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    color: #fff;
    padding: 12px 10px;
    margin-bottom: 8px;
}
.nav-brand img {
    height: 32px;
    width: auto;
}
.wallet-jump {
    margin-top: auto;
    padding: 10px;
}
.wallet-jump input[type="text"] {
    width: 100%;
    margin-bottom: 6px;
}
.wallet-jump button {
    width: 100%;
}
</style>

<div class="sidebar">
    <div class="nav-brand">
        <img src="assets/img/logo-mooglife.png" alt="Mooglife">
        <span>Mooglife Local</span>
    </div>

    <?php
        nav_link('dashboard',  'Dashboard',    $current);
        nav_link('market',     'Market',       $current);
        nav_link('sync',       'Sync',         $current);
        nav_link('holders',    'Holders',      $current);
        nav_link('ogbuyers',   'OG Buyers',    $current);
        nav_link('ogrewards',  'OG Rewards',   $current);
        nav_link('layout',     'Admin Layout', $current);
        nav_link('airdrops',   'Airdrops',     $current);
        nav_link('tx',         'Tx History',   $current);
        nav_link('xposts',     'X Posts',      $current);
        nav_link('settings',   'Settings',     $current);
		nav_link('ai_startup', 'AI Startup',   $current);
    ?>

    <div class="wallet-jump">
        <form method="get" style="display:flex;flex-direction:column;gap:6px;">
            <input type="hidden" name="p" value="wallet">
            <input
                type="text"
                name="wallet"
                placeholder="Jump to wallet..."
                style="padding:4px 8px;border-radius:6px;border:1px solid #1f2937;
                       background:#020617;color:#e5e7eb;font-size:12px;"
            >
            <button type="submit"
                    style="padding:4px 8px;border-radius:6px;border:none;background:#3b82f6;
                           color:#f9fafb;font-size:12px;cursor:pointer;">
                Go
            </button>
        </form>
    </div>
</div>

<div class="main">
