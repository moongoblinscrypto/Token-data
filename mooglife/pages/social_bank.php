<?php
// mooglife/pages/social_bank.php
// Read-only overview of your social-media post bank (x_posts).

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ml_dt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('Y-m-d H:i', $ts);
}

// ---------------------------------------------------------------------
// Check that x_posts exists
// ---------------------------------------------------------------------
$check = $db->query("SHOW TABLES LIKE 'x_posts'");
if (!$check || $check->num_rows === 0) {
    if ($check) $check->close();
    ?>
    <h1>Social Bank</h1>
    <div class="card">
        <p><code>x_posts</code> table not found. This page expects your social post bank table to exist.</p>
        <p>Once <code>x_posts</code> is created (and populated), this page will show stats and recent posts.</p>
    </div>
    <?php
    return;
}
$check->close();

// ---------------------------------------------------------------------
// Summary counts
// ---------------------------------------------------------------------
$summary = [
    'total'      => 0,
    'active'     => 0,
    'used'       => 0,
    'never_used' => 0,
];

$res = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(is_active,1) = 1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN COALESCE(times_used,0) > 0 THEN 1 ELSE 0 END) AS used,
        SUM(CASE WHEN COALESCE(times_used,0) = 0 THEN 1 ELSE 0 END) AS never_used
    FROM x_posts
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total']      = (int)$row['total'];
    $summary['active']     = (int)$row['active'];
    $summary['used']       = (int)$row['used'];
    $summary['never_used'] = (int)$row['never_used'];
    $res->close();
}

// ---------------------------------------------------------------------
// Category breakdown
// ---------------------------------------------------------------------
$cats = [];

$res = $db->query("
    SELECT
        COALESCE(category, '(uncategorized)') AS cat,
        COUNT(*) AS posts,
        SUM(CASE WHEN COALESCE(is_active,1) = 1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN COALESCE(times_used,0) > 0 THEN 1 ELSE 0 END) AS used
    FROM x_posts
    GROUP BY COALESCE(category, '(uncategorized)')
    ORDER BY posts DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cats[] = $row;
    }
    $res->close();
}

// ---------------------------------------------------------------------
// Recent posts (newest first)
// ---------------------------------------------------------------------
$posts = [];

$res = $db->query("
    SELECT
        id,
        body,
        category,
        tags,
        is_active,
        times_used,
        last_used_at,
        created_at,
        notes
    FROM x_posts
    ORDER BY created_at DESC, id DESC
    LIMIT 30
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $posts[] = $row;
    }
    $res->close();
}

?>
<h1>Social Bank</h1>
<p class="muted">
    Overview of your social-media post bank in <code>x_posts</code>.  
    Use <a href="?p=xposts">X Posts</a> for full editing and management; this page is a quick stats dashboard.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Posts</div>
        <div class="card-value"><?php echo number_format($summary['total']); ?></div>
        <div class="card-sub">All rows in <code>x_posts</code>.</div>
    </div>

    <div class="card">
        <div class="card-label">Active</div>
        <div class="card-value"><?php echo number_format($summary['active']); ?></div>
        <div class="card-sub">With <code>is_active = 1</code> (or NULL).</div>
    </div>

    <div class="card">
        <div class="card-label">Used At Least Once</div>
        <div class="card-value"><?php echo number_format($summary['used']); ?></div>
        <div class="card-sub">times_used &gt; 0</div>
    </div>

    <div class="card">
        <div class="card-label">Never Used</div>
        <div class="card-value"><?php echo number_format($summary['never_used']); ?></div>
        <div class="card-sub">Good candidates to schedule next.</div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">By Category</h2>
    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Category</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Posts</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Active</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Used</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$cats): ?>
                <tr>
                    <td colspan="4" style="padding:8px;border-bottom:1px solid #111827;">
                        No posts found. Add posts via <a href="?p=xposts">X Posts</a>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;"><?php echo esc($c['cat']); ?></td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((int)$c['posts']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((int)$c['active']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((int)$c['used']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent Posts (Last 30)</h2>

    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">ID</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Category</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Body</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tags</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Status</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Times Used</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Last Used</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Created</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$posts): ?>
                <tr>
                    <td colspan="9" style="padding:8px;border-bottom:1px solid #111827;">
                        No posts found yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo (int)$p['id']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($p['category'] ?? ''); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;max-width:360px;">
                            <?php echo nl2br(esc($p['body'])); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($p['tags'] ?? ''); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php
                            $isActive = ($p['is_active'] === null || $p['is_active'] == 1);
                            echo $isActive
                                ? '<span class="pill">ACTIVE</span>'
                                : '<span class="pill" style="background:#4b5563;">INACTIVE</span>';
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo (int)$p['times_used']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc(ml_dt($p['last_used_at'] ?? '')); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc(ml_dt($p['created_at'] ?? '')); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($p['notes'] ?? ''); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="muted" style="margin-top:6px;font-size:12px;">
        To add, edit, or delete posts, use the <a href="?p=xposts">X Posts</a> page.
    </p>
</div>
