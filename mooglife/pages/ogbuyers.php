<?php
// mooglife/pages/ogbuyers.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

/**
 * mg_moog_og_buyers:
 *  id, wallet, first_buy_time, first_buy_amount, total_bought, total_sold,
 *  current_balance, buy_tx_count, sell_tx_count, is_eligible, og_tier,
 *  label_tags, exclude_reason, notes, snapshot_at
 *
 * mg_moog_wallets:
 *  wallet, label, type, tags ...
 */

// filters
$q   = isset($_GET['q']) ? trim($_GET['q']) : '';
$tier = isset($_GET['tier']) ? (int)$_GET['tier'] : 0;
$elig = isset($_GET['elig']) ? $_GET['elig'] : 'all'; // all, yes, no

$sql = "
    SELECT b.*,
           w.label,
           w.type,
           w.tags
    FROM mg_moog_og_buyers b
    LEFT JOIN mg_moog_wallets w
      ON w.wallet = b.wallet
    WHERE 1=1
";

$params = [];
$types  = '';

if ($q !== '') {
    $sql .= " AND (b.wallet LIKE ? OR w.label LIKE ? OR w.tags LIKE ? OR b.notes LIKE ?) ";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

if ($tier > 0) {
    $sql .= " AND b.og_tier = ? ";
    $params[] = $tier;
    $types   .= 'i';
}

if ($elig === 'yes') {
    $sql .= " AND b.is_eligible = 1 ";
} elseif ($elig === 'no') {
    $sql .= " AND b.is_eligible = 0 ";
}

$sql .= " ORDER BY b.current_balance DESC";

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

function og_tier_badge($tier) {
    $tier = (int)$tier;
    if ($tier <= 0) return '<span class="pill" style="background:#4b5563;">NONE</span>';
    if ($tier === 1) return '<span class="pill" style="background:#22c55e;">T1</span>';
    if ($tier === 2) return '<span class="pill" style="background:#3b82f6;">T2</span>';
    if ($tier === 3) return '<span class="pill" style="background:#a855f7;">T3</span>';
    return '<span class="pill" style="background:#eab308;">T'.$tier.'</span>';
}

function elig_badge($is) {
    if ($is) {
        return '<span class="pill" style="background:#16a34a;">ELIGIBLE</span>';
    }
    return '<span class="pill" style="background:#b91c1c;">INELIGIBLE</span>';
}
?>
<h1>OG Buyers</h1>
<p class="muted">
    Snapshot of OG buyers from <code>mg_moog_og_buyers</code>, joined with labels from <code>mg_moog_wallets</code>.
</p>

<form method="get" class="search-row" style="margin-bottom:16px;">
    <input type="hidden" name="p" value="ogbuyers">
    <input type="text" name="q" placeholder="Search wallet, label, tags, notes..." value="<?php echo htmlspecialchars($q); ?>">

    <select name="tier" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="0" <?php if($tier===0) echo 'selected'; ?>>All tiers</option>
        <option value="1" <?php if($tier===1) echo 'selected'; ?>>Tier 1</option>
        <option value="2" <?php if($tier===2) echo 'selected'; ?>>Tier 2</option>
        <option value="3" <?php if($tier===3) echo 'selected'; ?>>Tier 3</option>
    </select>

    <select name="elig" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all" <?php if($elig==='all') echo 'selected'; ?>>All</option>
        <option value="yes" <?php if($elig==='yes') echo 'selected'; ?>>Eligible</option>
        <option value="no"  <?php if($elig==='no')  echo 'selected'; ?>>Ineligible</option>
    </select>

    <button type="submit">Filter</button>
</form>

<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Wallet</th>
            <th>Label</th>
            <th>Tier</th>
            <th>Eligible</th>
            <th>Current Balance</th>
            <th>Total Bought</th>
            <th>Total Sold</th>
            <th>Buys</th>
            <th>Sells</th>
            <th>Exclude / Notes</th>
            <th>First Buy</th>
            <th>Snapshot</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="13">No OG buyers found for this filter.</td></tr>
    <?php else: ?>
        <?php $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td style="font-size:11px;">
                <?php echo wallet_link($r['wallet'], $r['label'] ?? null, true); ?>
                </td>
                <td><?php echo htmlspecialchars($r['label'] ?? ''); ?></td>
                <td><?php echo og_tier_badge((int)$r['og_tier']); ?></td>
                <td><?php echo elig_badge((int)$r['is_eligible']); ?></td>
                <td><?php echo number_format((float)$r['current_balance'], 3); ?></td>
                <td><?php echo number_format((float)$r['total_bought'], 3); ?></td>
                <td><?php echo number_format((float)$r['total_sold'], 3); ?></td>
                <td><?php echo (int)$r['buy_tx_count']; ?></td>
                <td><?php echo (int)$r['sell_tx_count']; ?></td>
                <td>
                    <?php
                    if (!empty($r['exclude_reason'])) {
                        echo '<strong>Reason:</strong> '.htmlspecialchars($r['exclude_reason']).'<br>';
                    }
                    echo htmlspecialchars($r['notes'] ?? '');
                    ?>
                </td>
                <td><?php echo htmlspecialchars($r['first_buy_time']); ?></td>
                <td><?php echo htmlspecialchars($r['snapshot_at']); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
