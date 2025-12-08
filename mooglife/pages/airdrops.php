<?php
// mooglife/pages/airdrops.php
// Airdrop log + quick add form for moog_airdrops.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// Handle POST (add / delete)
// ---------------------------------------------------------------------
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $wallet = trim($_POST['wallet'] ?? '');
        $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
        $source = trim($_POST['source'] ?? '');
        $name   = trim($_POST['name'] ?? '');

        if ($wallet === '' || $amount <= 0) {
            $flash = [
                'ok'  => false,
                'msg' => 'Wallet and positive amount are required to add an airdrop.'
            ];
        } else {
            $stmt = $db->prepare("
                INSERT INTO moog_airdrops (wallet_address, amount, source, name)
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt) {
                $flash = [
                    'ok'  => false,
                    'msg' => 'DB error: ' . $db->error
                ];
            } else {
                $stmt->bind_param('siss', $wallet, $amount, $source, $name);
                if ($stmt->execute()) {
                    $flash = [
                        'ok'  => true,
                        'msg' => 'Airdrop added for wallet ' . $wallet
                    ];
                } else {
                    $flash = [
                        'ok'  => false,
                        'msg' => 'Insert failed: ' . $stmt->error
                    ];
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM moog_airdrops WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $flash = [
                        'ok'  => true,
                        'msg' => 'Deleted airdrop #' . $id
                    ];
                } else {
                    $flash = [
                        'ok'  => false,
                        'msg' => 'Delete failed: ' . $stmt->error
                    ];
                }
                $stmt->close();
            } else {
                $flash = [
                    'ok'  => false,
                    'msg' => 'DB error preparing delete: ' . $db->error
                ];
            }
        }
    }
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}

// ---------------------------------------------------------------------
// Summary cards
// ---------------------------------------------------------------------
$summary = [
    'total_drops'  => 0,
    'total_amount' => 0,
];

$res = $db->query("
    SELECT
        COUNT(*)       AS total_drops,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM moog_airdrops
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total_drops']  = (int)$row['total_drops'];
    $summary['total_amount'] = (float)$row['total_amount'];
}

// ---------------------------------------------------------------------
// Load list of airdrops (latest first)
// ---------------------------------------------------------------------
$where  = '1=1';
$params = [];
$types  = '';

if ($q !== '') {
    $where .= ' AND (wallet_address LIKE ? OR source LIKE ? OR name LIKE ?)';
    $like   = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT id, wallet_address, amount, source, name, created_at
    FROM moog_airdrops
    WHERE {$where}
    ORDER BY id DESC
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

// small date helper
function ml_format_dt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('Y-m-d H:i', $ts);
}
?>
<h1>Airdrop Log</h1>
<p class="muted">
    Records in <code>moog_airdrops</code>. Amount is stored as a raw integer (MOOG units).
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
        <div class="card-label">Total Airdrops</div>
        <div class="card-value">
            <?php echo number_format($summary['total_drops']); ?>
        </div>
        <div class="card-sub">
            Count of rows in <code>moog_airdrops</code>.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Total Amount</div>
        <div class="card-value">
            <?php echo number_format($summary['total_amount'], 0); ?> MOOG
        </div>
        <div class="card-sub">
            Raw sum of <code>amount</code> (no decimals).
        </div>
    </div>

    <div class="card">
        <div class="card-label">Rows Loaded</div>
        <div class="card-value">
            <?php echo number_format($totalRows); ?>
        </div>
        <div class="card-sub">
            Showing up to <?php echo (int)$limit; ?> rows (newest first).
        </div>
    </div>
</div>

<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">

    <!-- Add new airdrop -->
    <div class="card" style="flex:1 1 320px;min-width:280px;">
        <h2 style="margin-top:0;">Add New Airdrop</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Wallet Address *</label>
                <input
                    type="text"
                    name="wallet"
                    required
                    maxlength="128"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Amount (MOOG, integer) *</label>
                <input
                    type="number"
                    name="amount"
                    min="1"
                    step="1"
                    required
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Source (optional)</label>
                <input
                    type="text"
                    name="source"
                    maxlength="32"
                    placeholder="X, Discord, OG campaign, etc."
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Name / Label (optional)</label>
                <input
                    type="text"
                    name="name"
                    maxlength="128"
                    placeholder="e.g. Early OG drop, Contest #1"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <button type="submit"
                    style="margin-top:4px;padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-weight:600;cursor:pointer;">
                Save Airdrop
            </button>
        </form>
    </div>

    <!-- Filters -->
    <div class="card" style="flex:1 1 260px;min-width:260px;">
        <h2 style="margin-top:0;">Filter Log</h2>
        <form method="get">
            <input type="hidden" name="p" value="airdrops">

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Search</label>
                <input
                    type="text"
                    name="q"
                    value="<?php echo esc($q); ?>"
                    placeholder="Wallet, source, name..."
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Limit</label>
                <input
                    type="number"
                    name="limit"
                    value="<?php echo esc($limit); ?>"
                    min="10"
                    max="1000"
                    step="10"
                    style="width:100%;max-width:120px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <button type="submit"
                    style="margin-top:4px;padding:6px 12px;border-radius:6px;border:none;background:#3b82f6;color:#f9fafb;font-weight:600;cursor:pointer;">
                Apply Filters
            </button>
        </form>
    </div>

</div>

<div class="card">
    <h2 style="margin-top:0;">Recent Airdrops</h2>

    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">ID</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Wallet</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Amount (MOOG)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Source</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Name / Label</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Created</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="7" style="padding:8px;border-bottom:1px solid #111827;">
                        No airdrops found. Add one using the form on the left.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo (int)$r['id']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_link($r['wallet_address'], null, true); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$r['amount'], 0); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($r['source']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($r['name']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc(ml_format_dt($r['created_at'])); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <form method="post" onsubmit="return confirm('Delete this airdrop?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
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
