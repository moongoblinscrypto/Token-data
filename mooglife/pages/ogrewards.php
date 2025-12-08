<?php
// mooglife/pages/ogrewards.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

/**
 * mg_moog_og_rewards:
 *  id, wallet, planned_amount, tx_hash, status (PENDING/SENT/FAILED/CANCELLED),
 *  notes, created_at, updated_at
 *
 * mg_moog_wallets:
 *  wallet, label, type, tags ...
 */

$flash_success = '';
$flash_error   = '';

// ---------------------------------------------------------------------
// POST actions (add / update)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $wallet        = trim($_POST['wallet'] ?? '');
        $planned_amount= trim($_POST['planned_amount'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');
        $status        = 'PENDING';

        if ($wallet === '' || $planned_amount === '' || !is_numeric($planned_amount)) {
            $flash_error = 'Wallet and numeric amount are required.';
        } else {
            $amt = (float)$planned_amount;

            $stmt = $db->prepare("
                INSERT INTO mg_moog_og_rewards
                    (wallet, planned_amount, status, notes, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, NOW(), NOW())
            ");
            if (!$stmt) {
                $flash_error = 'DB error: ' . $db->error;
            } else {
                $stmt->bind_param('sdss', $wallet, $amt, $status, $notes);
                if ($stmt->execute()) {
                    $flash_success = 'OG reward created for wallet ' . substr($wallet, 0, 8) . '...';
                } else {
                    $flash_error = 'Insert failed: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = strtoupper(trim($_POST['status'] ?? 'PENDING'));
        $txhash = trim($_POST['tx_hash'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');

        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE mg_moog_og_rewards
                SET status = ?, tx_hash = ?, notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('sssi', $status, $txhash, $notes, $id);
                if ($stmt->execute()) {
                    $flash_success = "Reward #{$id} updated.";
                } else {
                    $flash_error = 'Update failed: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $flash_error = 'DB error: ' . $db->error;
            }
        }
    }
}

// ---------------------------------------------------------------------
// GET actions (simple delete)
// ---------------------------------------------------------------------
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM mg_moog_og_rewards WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $flash_success = "Reward #{$id} deleted.";
    }
}

// ---------------------------------------------------------------------
// summary
// ---------------------------------------------------------------------
$summary = [
    'total_records'  => 0,
    'total_planned'  => 0.0,
    'total_sent'     => 0.0,
    'pending_count'  => 0,
    'sent_count'     => 0,
];

$res = $db->query("
    SELECT 
        COUNT(*) AS total_records,
        COALESCE(SUM(planned_amount),0) AS total_planned,
        COALESCE(SUM(CASE WHEN status='SENT' THEN planned_amount ELSE 0 END),0) AS total_sent,
        SUM(status='PENDING') AS pending_count,
        SUM(status='SENT')    AS sent_count
    FROM mg_moog_og_rewards
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total_records'] = (int)$row['total_records'];
    $summary['total_planned'] = (float)$row['total_planned'];
    $summary['total_sent']    = (float)$row['total_sent'];
    $summary['pending_count'] = (int)$row['pending_count'];
    $summary['sent_count']    = (int)$row['sent_count'];
}

// ---------------------------------------------------------------------
// filters
// ---------------------------------------------------------------------
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, PENDING, SENT, FAILED, CANCELLED;
$only_no_tx = isset($_GET['no_tx']) && $_GET['no_tx'] === '1';

$statuses = ['PENDING','SENT','FAILED','CANCELLED'];

// ---------------------------------------------------------------------
// main query
// ---------------------------------------------------------------------
$sql = "
    SELECT 
        r.id,
        r.wallet,
        r.planned_amount,
        r.tx_hash,
        r.status,
        r.notes,
        r.created_at,
        r.updated_at,
        w.label,
        w.type,
        w.tags
    FROM mg_moog_og_rewards r
    LEFT JOIN mg_moog_wallets w ON w.wallet = r.wallet
";

$where  = [];
$params = [];
$types  = '';

if ($q !== '') {
    $where[] = "(r.wallet LIKE ? OR r.tx_hash LIKE ? OR r.notes LIKE ? OR w.label LIKE ? OR w.tags LIKE ?)";
    $like = '%' . $q . '%';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types   .= 's';
    }
}

if (in_array($status, $statuses, true)) {
    $where[] = "r.status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($only_no_tx) {
    $where[] = "(r.tx_hash IS NULL OR r.tx_hash = '')";
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY r.status ASC, r.created_at ASC, r.id ASC";

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

// ---------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------
function reward_status_badge($status) {
    $status = strtoupper((string)$status);
    switch ($status) {
        case 'PENDING':
            return '<span class="pill" style="background:#f97316;">PENDING</span>';
        case 'SENT':
            return '<span class="pill" style="background:#16a34a;">SENT</span>';
        case 'FAILED':
            return '<span class="pill" style="background:#b91c1c;">FAILED</span>';
        case 'CANCELLED':
            return '<span class="pill" style="background:#4b5563;">CANCELLED</span>';
        default:
            return '<span class="pill" style="background:#4b5563;">UNKNOWN</span>';
    }
}

function wallet_label_line($wallet, $label, $type) {
    // clickable wallet with optional label + type
    $link = wallet_link($wallet, $label ?: null, true);

    if ($label) {
        $typeText = $type ? ' • ' . htmlspecialchars($type) : '';
        return $link . '<br><span class="pill" style="background:#111827;">'
             . htmlspecialchars($label) . $typeText . '</span>';
    }

    return $link;
}

?>
<h1>OG Rewards</h1>
<p class="muted">
    Planned OG reward payouts from <code>mg_moog_og_rewards</code>, joined with labels from <code>mg_moog_wallets</code>.
</p>

<?php if ($flash_success): ?>
    <div style="margin:10px 0;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;">
        <?php echo htmlspecialchars($flash_success); ?>
    </div>
<?php elseif ($flash_error): ?>
    <div style="margin:10px 0;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;">
        <?php echo htmlspecialchars($flash_error); ?>
    </div>
<?php endif; ?>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Reward Records</div>
        <div class="card-value"><?php echo number_format($summary['total_records']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Total Planned (MOOG)</div>
        <div class="card-value"><?php echo number_format($summary['total_planned'], 3); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Total Sent (MOOG)</div>
        <div class="card-value"><?php echo number_format($summary['total_sent'], 3); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Pending / Sent</div>
        <div class="card-value">
            <?php echo number_format($summary['pending_count']); ?>
            /
            <?php echo number_format($summary['sent_count']); ?>
        </div>
    </div>
</div>

<!-- New OG Reward form -->
<div class="card" style="margin-bottom:20px;max-width:700px;">
    <h3 style="margin-top:0;">New OG Reward</h3>
    <form method="post">
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <div style="flex:2 1 260px;">
                <label>Wallet</label><br>
                <input type="text" name="wallet"
                       placeholder="Recipient wallet address"
                       style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
            </div>
            <div style="flex:1 1 160px;">
                <label>Amount (MOOG)</label><br>
                <input type="text" name="planned_amount"
                       placeholder="e.g. 50000"
                       style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
            </div>
        </div>
        <div style="margin-top:8px;">
            <label>Notes</label><br>
            <textarea name="notes" rows="2"
                      style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"></textarea>
        </div>
        <div style="margin-top:10px;">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#020617;cursor:pointer;">
                Add Reward
            </button>
        </div>
    </form>
</div>

<form method="get" class="search-row">
    <input type="hidden" name="p" value="ogrewards">
    <input type="text" name="q" placeholder="Search wallet, label, notes, tx hash..." value="<?php echo htmlspecialchars($q); ?>">

    <select name="status" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all" <?php if($status==='all') echo 'selected'; ?>>All statuses</option>
        <?php foreach ($statuses as $st): ?>
            <option value="<?php echo $st; ?>" <?php if($status===$st) echo 'selected'; ?>>
                <?php echo $st; ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label style="font-size:13px;color:#e5e7eb;display:flex;align-items:center;gap:4px;">
        <input type="checkbox" name="no_tx" value="1" <?php if($only_no_tx) echo 'checked'; ?>>
        No Tx hash
    </label>

    <button type="submit">Filter</button>
</form>

<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Wallet</th>
            <th>Amount (MOOG)</th>
            <th>Status</th>
            <th>Tx Hash</th>
            <th>Notes</th>
            <th>Created</th>
            <th>Updated</th>
            <th style="width:130px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="9">No OG rewards found for this filter.</td></tr>
    <?php else: ?>
        <?php $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo wallet_label_line($r['wallet'], $r['label'] ?? '', $r['type'] ?? ''); ?></td>
                <td><?php echo number_format((float)$r['planned_amount'], 3); ?></td>
                <td><?php echo reward_status_badge($r['status']); ?></td>
                <td>
                    <?php if (!empty($r['tx_hash'])): ?>
                        <div style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <code><?php echo htmlspecialchars($r['tx_hash']); ?></code>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($r['notes'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo htmlspecialchars($r['updated_at']); ?></td>
                <td>
                    <form method="post" style="display:flex;flex-direction:column;gap:4px;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">

                        <input type="text" name="tx_hash"
                               placeholder="Tx hash"
                               value="<?php echo htmlspecialchars($r['tx_hash'] ?? ''); ?>"
                               style="width:100%;padding:3px 5px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:11px;">

                        <select name="status" style="padding:3px 5px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:11px;">
                            <?php foreach ($statuses as $st): ?>
                                <option value="<?php echo $st; ?>" <?php if($r['status']===$st) echo 'selected'; ?>>
                                    <?php echo $st; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" name="notes"
                               placeholder="Notes"
                               value="<?php echo htmlspecialchars($r['notes'] ?? ''); ?>"
                               style="width:100%;padding:3px 5px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:11px;">

                        <div style="display:flex;gap:4px;margin-top:3px;">
                            <button type="submit"
                                    style="flex:1;padding:3px 5px;border-radius:4px;border:none;background:#22c55e;color:#020617;font-size:11px;cursor:pointer;">
                                Save
                            </button>
                            <a href="?p=ogrewards&delete=<?php echo (int)$r['id']; ?>"
                               onclick="return confirm('Delete reward #<?php echo (int)$r['id']; ?>?');"
                               style="flex:1;text-align:center;padding:3px 5px;border-radius:4px;background:#b91c1c;color:#f9fafb;font-size:11px;text-decoration:none;">
                                Delete
                            </a>
                        </div>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
