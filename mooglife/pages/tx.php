<?php
// mooglife/pages/tx.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

/**
 * mg_moog_tx:
 *  id, tx_hash, block_time, from_wallet, to_wallet, amount_moog, direction (BUY/SELL/TRANSFER), source
 * mg_moog_wallets:
 *  wallet, label, type, tags ...
 */

// filters
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$dir      = isset($_GET['dir']) ? $_GET['dir'] : 'all';      // all, BUY, SELL, TRANSFER
$source   = isset($_GET['source']) ? trim($_GET['source']) : '';
$wallet   = isset($_GET['wallet']) ? trim($_GET['wallet']) : '';

$sql = "
    SELECT 
        t.block_time,
        t.tx_hash,
        t.from_wallet,
        t.to_wallet,
        t.amount_moog,
        t.direction,
        t.source,
        wf.label AS from_label,
        wf.type  AS from_type,
        wt.label AS to_label,
        wt.type  AS to_type
    FROM mg_moog_tx t
    LEFT JOIN mg_moog_wallets wf ON wf.wallet = t.from_wallet
    LEFT JOIN mg_moog_wallets wt ON wt.wallet = t.to_wallet
    WHERE 1=1
";

$params = [];
$types  = '';

if ($q !== '') {
    $sql .= " AND (t.tx_hash LIKE ? OR t.from_wallet LIKE ? OR t.to_wallet LIKE ?) ";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

if ($dir !== 'all') {
    $sql .= " AND t.direction = ? ";
    $params[] = $dir;
    $types   .= 's';
}

if ($source !== '') {
    $sql .= " AND t.source = ? ";
    $params[] = $source;
    $types   .= 's';
}

if ($wallet !== '') {
    $sql .= " AND (t.from_wallet = ? OR t.to_wallet = ?) ";
    $params[] = $wallet;
    $params[] = $wallet;
    $types   .= 'ss';
}

$sql .= " ORDER BY t.block_time DESC, t.id DESC LIMIT 200";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

function dir_badge($dir) {
    $dir = strtoupper((string)$dir);
    if ($dir === 'BUY') {
        return '<span class="pill" style="background:#22c55e;">BUY</span>';
    }
    if ($dir === 'SELL') {
        return '<span class="pill" style="background:#ef4444;">SELL</span>';
    }
    return '<span class="pill" style="background:#6b7280;">X</span>';
}

function wallet_label($wallet, $label, $type) {
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
<h1>Tx History</h1>
<p class="muted">
    Recent MOOG transactions from <code>mg_moog_tx</code>, joined with labels from <code>mg_moog_wallets</code>.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Rows Shown</div>
        <div class="card-value"><?php echo number_format(count($rows)); ?></div>
    </div>
</div>

<form method="get" class="search-row" style="margin-bottom:16px;">
    <input type="hidden" name="p" value="tx">
    <input type="text" name="q" placeholder="Search tx hash or wallet..." value="<?php echo htmlspecialchars($q); ?>">

    <select name="dir" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all"      <?php if($dir==='all')      echo 'selected'; ?>>All</option>
        <option value="BUY"      <?php if($dir==='BUY')      echo 'selected'; ?>>Buys</option>
        <option value="SELL"     <?php if($dir==='SELL')     echo 'selected'; ?>>Sells</option>
        <option value="TRANSFER" <?php if($dir==='TRANSFER') echo 'selected'; ?>>Transfers</option>
    </select>

    <input type="text" name="source" placeholder="Source (e.g. RAYDIUM)" value="<?php echo htmlspecialchars($source); ?>">
    <input type="text" name="wallet" placeholder="Filter by wallet" value="<?php echo htmlspecialchars($wallet); ?>">

    <button type="submit">Filter</button>
</form>

<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>When</th>
            <th>Direction</th>
            <th>From</th>
            <th>To</th>
            <th>Amount (MOOG)</th>
            <th>Source</th>
            <th>Tx Hash</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="8">No transactions found for this filter.</td></tr>
    <?php else: ?>
        <?php $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($r['block_time']); ?></td>
                <td><?php echo dir_badge($r['direction']); ?></td>
                <td><?php echo wallet_label($r['from_wallet'], $r['from_label'], $r['from_type']); ?></td>
                <td><?php echo wallet_label($r['to_wallet'],   $r['to_label'],   $r['to_type']); ?></td>
                <td><?php echo number_format((float)$r['amount_moog'], 3); ?></td>
                <td><span class="pill" style="background:#111827;"><?php echo htmlspecialchars($r['source']); ?></span></td>
                <td>
                    <div style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <code><?php echo htmlspecialchars($r['tx_hash']); ?></code>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
