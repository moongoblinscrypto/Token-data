<?php
// mooglife/pages/dashboard.php
require __DIR__ . '/../includes/db.php';

$db       = moog_db();
$registry = require __DIR__ . '/../includes/widgets/registry.php';
$section  = 'dashboard';

// Ensure all registry widgets exist in mg_admin_widgets
foreach ($registry as $slug => $def) {
    if (($def['section'] ?? 'dashboard') !== $section) continue;

    $title = $def['title'] ?? $slug;
    $stmt  = $db->prepare("
        INSERT IGNORE INTO mg_admin_widgets (section, slug, title, is_enabled, sort_order)
        VALUES (?, ?, ?, 1, 0)
    ");
    $stmt->bind_param('sss', $section, $slug, $title);
    $stmt->execute();
    $stmt->close();
}

// Load layout for this section
$widgets = [];
$res = $db->query("
    SELECT id, slug, title, is_enabled, sort_order
    FROM mg_admin_widgets
    WHERE section = 'dashboard'
    ORDER BY sort_order ASC, id ASC
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $slug = $row['slug'];
        if (!isset($registry[$slug])) continue;
        $row['def'] = $registry[$slug];
        $widgets[]  = $row;
    }
}
?>
<h1>Mooglife Dashboard</h1>
<p class="muted">
    Clean v2 dashboard running on your localhost.
</p>

<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">
    <div style="flex:1 1 260px;">
        <p class="muted" style="font-size:12px;margin:0;">
            Widget layout controlled from <a href="?p=layout">Admin Layout</a>.
        </p>
    </div>
</div>

<?php
// Render each enabled widget in sorted order
foreach ($widgets as $w) {
    if (!$w['is_enabled']) continue;

    $file = $w['def']['file'] ?? null;
    if (!$file || !is_file($file)) {
        echo '<div class="card" style="margin-bottom:20px;">';
        echo '<strong>Missing widget file for </strong>'
           . htmlspecialchars($w['title'])
           . ' (slug: ' . htmlspecialchars($w['slug']) . ')';
        echo '</div>';
        continue;
    }

    include $file;
}
?>
