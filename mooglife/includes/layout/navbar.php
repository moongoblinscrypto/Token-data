<?php
// mooglife/includes/layout/navbar.php
require_once __DIR__ . '/../auth.php';

$currentPage = $_GET['p'] ?? 'dashboard';
$currentPage = basename($currentPage);
$currentUser = mg_current_user();

/**
 * Render a sidebar nav link.
 */
function ml_nav_item(string $page, string $label): void
{
    global $currentPage;
    $active = ($currentPage === $page) ? 'active' : '';
    $url    = '?p=' . urlencode($page);
    echo '<a href="' . $url . '" class="' . $active . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}
?>
<div class="sidebar">
    <div style="margin-bottom:16px;">
        <div style="font-size:13px;color:#9ca3af;margin-bottom:4px;">Navigation</div>
        <?php
            ml_nav_item('dashboard',   'Dashboard');
            ml_nav_item('holders',     'Holders');
            ml_nav_item('wallet',      'Wallet Manager');
            ml_nav_item('tx',          'Tx History');
            ml_nav_item('market',      'Market Stats');
            ml_nav_item('airdrops',    'Airdrops');
            ml_nav_item('ogrewards',   'OG Rewards');
            ml_nav_item('ogbuyers',    'OG Buyers');
            ml_nav_item('api_docs',    'Moog API');      // 🔹 NEW
            ml_nav_item('settings',    'Settings');
            ml_nav_item('admin_users', 'Admin Users');
        ?>
    </div>

    <div style="margin-top:auto;padding-top:12px;border-top:1px solid #111827;font-size:12px;color:#9ca3af;">
        <?php if ($currentUser): ?>
            <div style="margin-bottom:6px;">
                Logged in as<br>
                <strong><?php echo htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="pill" style="margin-left:4px;">
                    <?php echo htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <a href="?p=logout" class="btn secondary" style="padding:4px 8px;font-size:12px;">
                Logout
            </a>
        <?php else: ?>
            <a href="?p=login" class="btn secondary" style="padding:4px 8px;font-size:12px;">
                Login
            </a>
        <?php endif; ?>
    </div>
</div>
<div class="main">

