<?php
// mooglife/pages/xposts.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

/**
 * x_posts structure (from GoblinsHQ):
 * id, body, category, tags, is_active, times_used, last_used_at, created_at, notes
 */

// filters
$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$status  = isset($_GET['status']) ? $_GET['status'] : 'active'; // active, inactive, all
$cat     = isset($_GET['cat']) ? trim($_GET['cat']) : 'all';

// get distinct categories for dropdown
$cats = [];
$res = $db->query("SELECT DISTINCT category FROM x_posts WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cats[] = $row['category'];
    }
}

// summary counts
$summary = [
    'total'   => 0,
    'active'  => 0,
    'used'    => 0,
    'unused'  => 0,
];

$res = $db->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN is_active = 1 OR is_active IS NULL THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN times_used > 0 THEN 1 ELSE 0 END) AS used,
        SUM(CASE WHEN (times_used IS NULL OR times_used = 0) THEN 1 ELSE 0 END) AS unused
    FROM x_posts
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total']  = (int)$row['total'];
    $summary['active'] = (int)$row['active'];
    $summary['used']   = (int)$row['used'];
    $summary['unused'] = (int)$row['unused'];
}

// main query
$sql = "SELECT id, body, category, tags, is_active, times_used, last_used_at, created_at, notes
        FROM x_posts";

$where  = [];
$params = [];
$types  = '';

if ($q !== '') {
    $where[] = "(body LIKE ? OR tags LIKE ? OR notes LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($status === 'active') {
    $where[] = "(is_active = 1 OR is_active IS NULL)";
} elseif ($status === 'inactive') {
    $where[] = "is_active = 0";
}

if ($cat !== '' && $cat !== 'all') {
    $where[] = "category = ?";
    $params[] = $cat;
    $types .= 's';
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY created_at DESC, id DESC LIMIT 250";

$stmt = $db->prepare($sql);
if ($stmt === false) {
    die("Query error: " . $db->error);
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();
?>
<h1>X Posts</h1>
<p class="muted">
    Your social-media post bank from <code>x_posts</code>. Search, filter, and copy posts for X.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Posts</div>
        <div class="card-value"><?php echo number_format($summary['total']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Active</div>
        <div class="card-value"><?php echo number_format($summary['active']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Used At Least Once</div>
        <div class="card-value"><?php echo number_format($summary['used']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Never Used</div>
        <div class="card-value"><?php echo number_format($summary['unused']); ?></div>
    </div>
</div>

<form method="get" class="search-row">
    <input type="hidden" name="p" value="xposts">
    <input type="text" name="q" placeholder="Search text, tags, notes..." value="<?php echo htmlspecialchars($q); ?>">

    <select name="status" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="active"   <?php if($status==='active')   echo 'selected'; ?>>Active</option>
        <option value="inactive" <?php if($status==='inactive') echo 'selected'; ?>>Inactive</option>
        <option value="all"      <?php if($status==='all')      echo 'selected'; ?>>All</option>
    </select>

    <select name="cat" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all">All categories</option>
        <?php foreach ($cats as $c): ?>
            <option value="<?php echo htmlspecialchars($c); ?>" <?php if($cat===$c) echo 'selected'; ?>>
                <?php echo htmlspecialchars($c); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit">Filter</button>
</form>

<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Category</th>
            <th>Body</th>
            <th>Tags</th>
            <th>Active</th>
            <th>Times Used</th>
            <th>Last Used</th>
            <th>Created</th>
            <th>Copy</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="9">No posts found for this filter.</td></tr>
    <?php else: ?>
        <?php $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td>
                    <?php if ($r['category']): ?>
                        <span class="badge-cat"><?php echo htmlspecialchars($r['category']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="body-cell" id="post-body-<?php echo (int)$r['id']; ?>">
                    <?php echo nl2br(htmlspecialchars($r['body'])); ?>
                </td>
                <td><?php echo htmlspecialchars($r['tags'] ?? ''); ?></td>
                <td>
                    <?php
                    $isActive = ($r['is_active'] === null || $r['is_active'] == 1);
                    echo $isActive 
                        ? '<span class="pill">ACTIVE</span>' 
                        : '<span class="pill" style="background:#4b5563;">INACTIVE</span>';
                    ?>
                </td>
                <td><?php echo (int)$r['times_used']; ?></td>
                <td><?php echo htmlspecialchars($r['last_used_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                <td>
                    <button type="button" onclick="copyPost(<?php echo (int)$r['id']; ?>)" style="padding:4px 8px;border-radius:6px;border:none;background:#38bdf8;color:#020617;cursor:pointer;">
                        Copy
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<script>
function copyPost(id) {
    var el = document.getElementById('post-body-' + id);
    if (!el) return;
    var text = el.innerText || el.textContent || '';
    if (!navigator.clipboard) {
        // fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        alert('Copied to clipboard');
        return;
    }
    navigator.clipboard.writeText(text).then(function() {
        alert('Copied to clipboard');
    }).catch(function() {
        alert('Copy failed');
    });
}
</script>
