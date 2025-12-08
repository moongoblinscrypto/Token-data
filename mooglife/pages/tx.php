<?php
// mooglife/pages/tx.php
// MOOG transaction history with filters.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$dir    = isset($_GET['dir']) ? trim($_GET['dir']) : 'all';  // all, BUY, SELL, TRANSFER
$source = isset($_GET['source']) ? trim($_GET['source']) : '';
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if ($limit <= 0 || $limit > 500) {
    $limit = 100;
}

// ---------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function wallet_label_row(?string $wallet, ?string $label, ?string $type): string
{
    if (!$wallet) {
        return '';
    }

    $display = $label ?: $wallet;
    $short   = null;

    if (!$label && strlen($wallet) > 8) {
        $short = substr($wallet, 0, 4) . '...' . substr($wallet, -4);
    }

    $text = $label ? $label : ($short ?: $wallet);

    $class = 'pill';
    if ($type) {
        $class .= ' pill-' . strtolower($type);
    }

    $html  = wallet_link($wallet, $text, true);
    if ($type) {
        $html .= '<br><span class="pill" style="background:#020617;border:1px solid #111827;font-size:10px;margin-top:2px;display:inline-block;">'
              . esc($type)
              . '</span>';
    }

    return $html;
}

// ---------------------------------------------------------------------
// Build query
// ---------------------------------------------------------------------
$where  = '1=1';
$params = [];
$types  = '';

// search: wallet or tx hash
if ($q !== '') {
    $where .= ' AND (t.from_wallet LIKE ? OR t.to_wallet LIKE ? OR t.tx_hash LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// direction filter
if (in_array($dir, ['BUY', 'SELL', 'TRANSFER'], true)) {
    $where .= ' AND t.direction = ?';
    $types .= 's';
    $params[] = $dir;
}

// source filter
if ($source !== '') {
    $where .= ' AND t.source LIKE ?';
    $types .= 's';
    $params[] = '%' . $source . '%';
}

$sql = "
    SELECT
        t.block_time,
        t.direction,
        t.amount_moog,
        t.source,
        t.tx_hash,
        t.from_wallet,
        wf.label AS from_label,
        wf.type  AS from_type,
        t.to_wallet,
        wt.label AS to_label,
        wt.type  AS to_type
    FROM mg_moog_tx t
    LEFT JOIN mg_moog_wallets wf ON wf.wallet = t.from_wallet
    LEFT JOIN mg_moog_wallets wt ON wt.wallet = t.to_wallet
    WHERE {$where}
    ORDER BY t.block_time DESC
    LIMIT ?
";

$types   .= 'i';
$params[] = $limit;

// prepare
$stmt = $db->prepare($sql);
if (!$stmt) {
    die('SQL prepare failed: ' . esc($db->error));
}

// bind params
$stmt_params   = [];
$stmt_params[] = &$types;
foreach ($params as $k => $v) {
    $stmt_params[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $stmt_params);

// execute + fetch
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// simple count = rows we pulled
$totalTx = count($rows);
?>
<h1>MOOG Transactions</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Rows Loaded</div>
        <div class="card-value"><?php echo number_format($totalTx); ?></div>
        <div class="card-sub">
            Showing up to <?php echo (int)$limit; ?> rows from <code>mg_moog_tx</code>.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Filters</div>
        <div class="card-sub">
            <form method="get" class="search-row" style="margin-top:8px;">
                <input type="hidden" name="p" value="tx">

                <div style="margin-bottom:8px;">
                    <label style="font-size:12px;display:block;margin-bottom:2px;">Search (wallet or tx hash)</label>
                    <input
                        type="text"
                        name="q"
                        value="<?php echo esc($q); ?>"
                        placeholder="Wallet or transaction hash..."
                        style="width:100%;max-width:260px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                    >
                </div>

                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                    <div>
                        <label style="font-size:12px;display:block;margin-bottom:2px;">Direction</label>
                        <select
                            name="dir"
                            style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                        >
                            <option value="all" <?php if ($dir === 'all') echo 'selected'; ?>>All</option>
                            <option value="BUY" <?php if ($dir === 'BUY') echo 'selected'; ?>>BUY</option>
                            <option value="SELL" <?php if ($dir === 'SELL') echo 'selected'; ?>>SELL</option>
                            <option value="TRANSFER" <?php if ($dir === 'TRANSFER') echo 'selected'; ?>>TRANSFER</option>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:12px;display:block;margin-bottom:2px;">Source</label>
                        <input
                            type="text"
                            name="source"
                            value="<?php echo esc($source); ?>"
                            placeholder="e.g. Birdeye"
                            style="width:140px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                        >
                    </div>

                    <div>
                        <label style="font-size:12px;display:block;margin-bottom:2px;">Limit</label>
                        <input
                            type="text"
                            name="limit"
                            value="<?php echo esc($limit); ?>"
                            style="width:80px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                        >
                    </div>

                    <div>
                        <button type="submit" style="margin-top:2px;padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-weight:600;cursor:pointer;">
                            Apply
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent Transactions</h2>

    <?php if ($totalTx === 0): ?>
        <p>No transactions found. Try syncing via the Sync page or relaxing filters.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Time</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Direction</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Amount (MOOG)</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">From</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">To</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Source</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tx Hash</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php
                            $bt = $r['block_time'] ?? '';
                            if ($bt) {
                                $ts = strtotime($bt);
                                echo esc($ts !== false ? date('Y-m-d H:i', $ts) : $bt);
                            }
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($r['direction']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$r['amount_moog'], 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_label_row($r['from_wallet'], $r['from_label'], $r['from_type']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_label_row($r['to_wallet'], $r['to_label'], $r['to_type']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <span class="pill" style="background:#111827;"><?php echo esc($r['source']); ?></span>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <div style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <code><?php echo esc($r['tx_hash']); ?></code>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
