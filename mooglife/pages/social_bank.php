<?php
// mooglife/pages/social_bank.php
// Full Social Bank manager for x_posts: write, search, filter, delete, copy for X/FB.

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
    </div>
    <?php
    return;
}
$check->close();

// ---------------------------------------------------------------------
// Handle POST actions: add, delete, toggle, mark_used
// ---------------------------------------------------------------------
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new post
    if ($action === 'add') {
        $body     = trim($_POST['body'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $tags     = trim($_POST['tags'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($body === '') {
            $flash = ['ok' => false, 'msg' => 'Body is required to create a post.'];
        } else {
            $stmt = $db->prepare("
                INSERT INTO x_posts (body, category, tags, is_active, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                $flash = ['ok' => false, 'msg' => 'DB error: ' . $db->error];
            } else {
                $stmt->bind_param('sssds', $body, $category, $tags, $is_active, $notes);
                // NOTE: 'd' for is_active still works because it is numeric; if you prefer exact, change to 'i'.
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Post added to Social Bank.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Insert failed: ' . $stmt->error];
                }
                $stmt->close();
            }
        }
    }

    // Delete post
    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM x_posts WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Post #' . $id . ' deleted.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Delete failed: ' . $stmt->error];
                }
                $stmt->close();
            } else {
                $flash = ['ok' => false, 'msg' => 'DB error preparing delete: ' . $db->error];
            }
        }
    }

    // Toggle active / inactive
    if ($action === 'toggle_active') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE x_posts
                SET is_active = CASE WHEN COALESCE(is_active,1) = 1 THEN 0 ELSE 1 END
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Toggled active state for post #' . $id . '.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Toggle failed: ' . $stmt->error];
                }
                $stmt->close();
            } else {
                $flash = ['ok' => false, 'msg' => 'DB error preparing toggle: ' . $db->error];
            }
        }
    }

    // Mark used (increments times_used + sets last_used_at = NOW())
    if ($action === 'mark_used') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE x_posts
                SET times_used = COALESCE(times_used,0) + 1,
                    last_used_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Marked post #' . $id . ' as used now.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Mark-used failed: ' . $stmt->error];
                }
                $stmt->close();
            } else {
                $flash = ['ok' => false, 'msg' => 'DB error preparing mark-used: ' . $db->error];
            }
        }
    }
}

// ---------------------------------------------------------------------
// Filters (GET)
// ---------------------------------------------------------------------
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$catFilter = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$show     = isset($_GET['show']) ? trim($_GET['show']) : 'active'; // active, inactive, all
$limit    = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}

// Load category list for filter dropdown
$categories = [];
$res = $db->query("
    SELECT DISTINCT COALESCE(NULLIF(category,''),'(uncategorized)') AS cat
    FROM x_posts
    ORDER BY cat
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row['cat'];
    }
    $res->close();
}

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
// Build WHERE for main list
// ---------------------------------------------------------------------
$where  = '1=1';
$params = [];
$types  = '';

// search in body, tags, notes
if ($q !== '') {
    $where .= ' AND (body LIKE ? OR tags LIKE ? OR notes LIKE ?)';
    $like   = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// category filter
if ($catFilter !== '' && $catFilter !== '(uncategorized)') {
    $where .= ' AND category = ?';
    $types .= 's';
    $params[] = $catFilter;
} elseif ($catFilter === '(uncategorized)') {
    $where .= " AND (category IS NULL OR category = '')";
}

// active/inactive filter
if ($show === 'active') {
    $where .= ' AND COALESCE(is_active,1) = 1';
} elseif ($show === 'inactive') {
    $where .= ' AND COALESCE(is_active,1) = 0';
}

// ---------------------------------------------------------------------
// Load posts list
// ---------------------------------------------------------------------
$sql = "
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
    WHERE {$where}
    ORDER BY created_at DESC, id DESC
    LIMIT ?
";

$types   .= 'i';
$params[] = $limit;

$stmt = $db->prepare($sql);
if (!$stmt) {
    die('SQL prepare failed: ' . esc($db->error));
}

$stmt_params   = [];
$stmt_params[] = &$types;
foreach ($params as $k => $v) {
    $stmt_params[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $stmt_params);

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$totalRows = count($rows);
?>
<h1>Social Bank</h1>
<p class="muted">
    This is your main post-writing bank for X, FB, etc.  
    Write posts, search them, filter by category, copy to your composer, and mark them as used.
</p>

<?php if ($flash !== null): ?>
    <div style="margin-bottom:12px;padding:8px 10px;border-radius:6px;
        background:<?php echo $flash['ok'] ? '#022c22' : '#450a0a'; ?>;
        color:<?php echo $flash['ok'] ? '#bbf7d0' : '#fecaca'; ?>;
        font-size:13px;">
        <?php echo esc($flash['msg']); ?>
    </div>
<?php endif; ?>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Posts</div>
        <div class="card-value"><?php echo number_format($summary['total']); ?></div>
        <div class="card-sub">All rows in <code>x_posts</code>.</div>
    </div>

    <div class="card">
        <div class="card-label">Active</div>
        <div class="card-value"><?php echo number_format($summary['active']); ?></div>
        <div class="card-sub">Eligible to use in scheduling.</div>
    </div>

    <div class="card">
        <div class="card-label">Used At Least Once</div>
        <div class="card-value"><?php echo number_format($summary['used']); ?></div>
        <div class="card-sub">times_used &gt; 0 (tracked when you mark as used).</div>
    </div>

    <div class="card">
        <div class="card-label">Never Used</div>
        <div class="card-value"><?php echo number_format($summary['never_used']); ?></div>
        <div class="card-sub">Good pool to post from next.</div>
    </div>
</div>

<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">

    <!-- Composer / Clipboard -->
    <div class="card" style="flex:1 1 420px;min-width:320px;">
        <h2 style="margin-top:0;">Composer / Clipboard</h2>
        <p class="muted" style="font-size:12px;margin-bottom:6px;">
            Click "Load" on any row below to pull that post into this box.  
            Then use the button to copy for X/FB.
        </p>
        <textarea
            id="post-composer"
            style="width:100%;min-height:160px;padding:8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:14px;white-space:pre-wrap;"
        ></textarea>
        <button
            id="copy-composer-btn"
            type="button"
            style="margin-top:8px;padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-weight:600;cursor:pointer;"
        >
            Copy Composer Text
        </button>
        <p class="muted" style="font-size:11px;margin-top:6px;">
            Tip: After copying, hit "Mark Used" on that row to bump its usage counter.
        </p>
    </div>

    <!-- Add New Post -->
    <div class="card" style="flex:1 1 320px;min-width:280px;">
        <h2 style="margin-top:0;">Add New Post</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Body *</label>
                <textarea
                    name="body"
                    required
                    style="width:100%;min-height:120px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:13px;"
                ></textarea>
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Category (optional)</label>
                <input
                    type="text"
                    name="category"
                    placeholder="e.g. Meme, Education, Holder Update"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Tags (optional)</label>
                <input
                    type="text"
                    name="tags"
                    placeholder="#moog #solana #goblins"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Notes (optional)</label>
                <input
                    type="text"
                    name="notes"
                    placeholder="internal note only"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;">
                    <input type="checkbox" name="is_active" checked>
                    <span style="margin-left:4px;">Active (include in rotation)</span>
                </label>
            </div>

            <button type="submit"
                    style="margin-top:4px;padding:6px 12px;border-radius:6px;border:none;background:#3b82f6;color:#f9fafb;font-weight:600;cursor:pointer;">
                Save Post
            </button>
        </form>
    </div>

</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Filters</h2>
    <form method="get">
        <input type="hidden" name="p" value="social_bank">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Search</label>
                <input
                    type="text"
                    name="q"
                    value="<?php echo esc($q); ?>"
                    placeholder="Search body, tags, notes..."
                    style="width:240px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Category</label>
                <select
                    name="cat"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo esc($c); ?>"
                            <?php if ($catFilter === $c) echo 'selected'; ?>>
                            <?php echo esc($c); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Status</label>
                <select
                    name="show"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="active"   <?php if ($show === 'active') echo 'selected'; ?>>Active</option>
                    <option value="inactive" <?php if ($show === 'inactive') echo 'selected'; ?>>Inactive</option>
                    <option value="all"      <?php if ($show === 'all') echo 'selected'; ?>>All</option>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Limit</label>
                <input
                    type="number"
                    name="limit"
                    value="<?php echo esc($limit); ?>"
                    min="50"
                    max="1000"
                    step="50"
                    style="width:90px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div>
                <button type="submit"
                        style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-weight:600;cursor:pointer;">
                    Apply
                </button>
            </div>

        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">Posts (Newest First)</h2>
    <p class="muted" style="font-size:12px;margin-bottom:6px;">
        Loaded <?php echo number_format($totalRows); ?> posts. Click "Load" to put any post into the composer.  
        Use "Mark Used" after you publish it to X/FB to track usage.
    </p>

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
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="9" style="padding:8px;border-bottom:1px solid #111827;">
                        No posts match the filters. Try adjusting search/category/status.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo (int)$p['id']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($p['category'] ?? ''); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;max-width:360px;">
                            <div class="post-preview" style="max-height:72px;overflow:hidden;text-overflow:ellipsis;">
                                <?php echo nl2br(esc($p['body'])); ?>
                            </div>
                            <textarea class="post-body-src" style="display:none;"><?php echo esc($p['body']); ?></textarea>
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
                        <td style="padding:6px;border-bottom:1px solid #111827;white-space:nowrap;">
                            <!-- Load into composer -->
                            <button
                                type="button"
                                class="load-post-btn"
                                style="padding:3px 8px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-size:11px;cursor:pointer;margin-bottom:2px;">
                                Load
                            </button>

                            <!-- Mark used -->
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="mark_used">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit"
                                        style="padding:3px 8px;border-radius:6px;border:none;background:#0ea5e9;color:#e0f2fe;font-size:11px;cursor:pointer;margin-bottom:2px;">
                                    Mark Used
                                </button>
                            </form>

                            <!-- Toggle active -->
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit"
                                        style="padding:3px 8px;border-radius:6px;border:none;background:#6b7280;color:#e5e7eb;font-size:11px;cursor:pointer;margin-bottom:2px;">
                                    <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>

                            <!-- Delete -->
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this post?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit"
                                        style="padding:3px 8px;border-radius:6px;border:none;background:#b91c1c;color:#fee2e2;font-size:11px;cursor:pointer;">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var composer = document.getElementById('post-composer');
    var copyBtn  = document.getElementById('copy-composer-btn');

    // Load post body into composer
    document.querySelectorAll('.load-post-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var row  = this.closest('tr');
            var ta   = row.querySelector('textarea.post-body-src');
            if (ta && composer) {
                composer.value = ta.value;
                composer.focus();
                // Optional: scroll composer into view
                composer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Copy composer text to clipboard
    if (copyBtn && composer && navigator.clipboard) {
        copyBtn.addEventListener('click', function() {
            var text = composer.value || '';
            navigator.clipboard.writeText(text).then(function() {
                alert('Composer text copied to clipboard.');
            }).catch(function() {
                alert('Copy failed. You can still select + Ctrl+C manually.');
            });
        });
    }
});
</script>
