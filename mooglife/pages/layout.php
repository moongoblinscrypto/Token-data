<?php
// mooglife/pages/layout.php
require __DIR__ . '/../includes/db.php';

$db       = moog_db();
$registry = require __DIR__ . '/../includes/widgets/registry.php';
$section  = 'dashboard';
$flash    = null;

// Ensure registry widgets exist in DB
foreach ($registry as $slug => $def) {
    if (($def['section'] ?? 'dashboard') !== $section) continue;
    $title = $def['title'] ?? $slug;

    $stmt = $db->prepare("
        INSERT IGNORE INTO mg_admin_widgets (section, slug, title, is_enabled, sort_order)
        VALUES (?, ?, ?, 1, 0)
    ");
    $stmt->bind_param('sss', $section, $slug, $title);
    $stmt->execute();
    $stmt->close();
}

// Handle POST updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled = $_POST['enabled'] ?? [];
    $order   = $_POST['order']   ?? [];

    $res = $db->query("
        SELECT id, slug
        FROM mg_admin_widgets
        WHERE section = 'dashboard'
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id   = (int)$row['id'];
            $slug = $row['slug'];

            $isEnabled = isset($enabled[$slug]) ? 1 : 0;
            $sort      = isset($order[$slug]) ? (int)$order[$slug] : 0;

            $stmt = $db->prepare("
                UPDATE mg_admin_widgets
                SET is_enabled = ?, sort_order = ?
                WHERE id = ?
            ");
            $stmt->bind_param('iii', $isEnabled, $sort, $id);
            $stmt->execute();
            $stmt->close();
        }
        $flash = 'Layout updated.';
    }
}

// Load current layout
$widgets = [];
$res = $db->query("
    SELECT id, slug, title, is_enabled, sort_order
    FROM mg_admin_widgets
    WHERE section = 'dashboard'
    ORDER BY sort_order ASC, id ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $widgets[] = $row;
    }
}

function pill($text, $ok = true) {
    $bg = $ok ? '#16a34a' : '#6b7280';
    return '<span class="pill" style="background:'.$bg.';">'
        . htmlspecialchars($text)
        . '</span>';
}
?>
<h1>Admin Layout</h1>
<p class="muted">
    Control which widgets appear on the <strong>Dashboard</strong> and in what order.
</p>

<?php if ($flash): ?>
    <div style="margin:10px 0;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;">
        <?php echo htmlspecialchars($flash); ?>
    </div>
<?php endif; ?>

<form method="post">
    <div class="card">
        <h3 style="margin-top:0;">Dashboard Widgets</h3>
        <table class="data">
            <thead>
            <tr>
                <th>Enabled</th>
                <th>Widget</th>
                <th>Slug</th>
                <th>Order</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$widgets): ?>
                <tr><td colspan="4">No widgets found.</td></tr>
            <?php else: ?>
                <?php foreach ($widgets as $w): ?>
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox"
                                   name="enabled[<?php echo htmlspecialchars($w['slug']); ?>]"
                                   <?php echo $w['is_enabled'] ? 'checked' : ''; ?>>
                        </td>
                        <td><?php echo pill($w['title'], (bool)$w['is_enabled']); ?></td>
                        <td><code><?php echo htmlspecialchars($w['slug']); ?></code></td>
                        <td style="width:90px;">
                            <input type="number"
                                   name="order[<?php echo htmlspecialchars($w['slug']); ?>]"
                                   value="<?php echo (int)$w['sort_order']; ?>"
                                   style="width:70px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <p class="muted" style="margin-top:8px;font-size:12px;">
            Lower <strong>Order</strong> shows first. You can reuse numbers; rows are then sorted by ID.
        </p>

        <button type="submit"
                style="margin-top:10px;padding:6px 12px;border:none;border-radius:6px;background:#3b82f6;color:#f9fafb;cursor:pointer;">
            Save Layout
        </button>
    </div>
</form>

<p class="muted" style="margin-top:10px;font-size:12px;">
    Registry lives in <code>includes/widgets/registry.php</code>. Add new widgets there and they will appear here automatically.
</p>
