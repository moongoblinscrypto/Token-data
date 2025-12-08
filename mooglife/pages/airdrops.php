<?php
// mooglife/pages/airdrops.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

// Filters
$q        = isset($_GET['q']) ? trim($_GET['q']) : '';
$status   = isset($_GET['status']) ? $_GET['status'] : 'all';      // all, paid, unpaid
$verified = isset($_GET['verified']) ? $_GET['verified'] : 'all';  // all, yes, no

// --- Summary cards (totals) ---
$summary = [
    'total_drops'   => 0,
    'total_amount'  => 0,
    'paid_amount'   => 0,
    'unpaid_amount' => 0,
];

$res = $db->query("
    SELECT 
        COUNT(*) AS total_drops,
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN paid = 1 THEN amount ELSE 0 END), 0) AS paid_amount,
        COALESCE(SUM(CASE WHEN paid = 0 THEN amount ELSE 0 END), 0) AS unpaid_amount
    FROM moog_airdrops
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total_drops']   = (int)$row['total_drops'];
    $summary['total_amount']  = (int)$row['total_amount'];
    $summary['paid_amount']   = (int)$row['paid_amount'];
    $summary['unpaid_amount'] = (int)$row['unpaid_amount'];
}

// --- Query list ---
$sql = "
    SELECT id, wallet_address, amount, source, name, paid, verified, notes, date_added
    FROM moog_airdrops
    WHERE 1=1
";

$params = [];
$types  = '';

if ($q !== '') {
    $sql .= " AND (wallet_address LIKE ? OR name LIKE ? OR source LIKE ? OR notes LIKE ?) ";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

if ($status === 'paid') {
    $sql .= " AND paid = 1 ";
} elseif ($status === 'unpaid') {
    $sql .= " AND paid = 0 ";
}

if ($verified === 'yes') {
    $sql .= " AND verified = 1 ";
} elseif ($verified === 'no') {
    $sql .= " AND verified = 0 ";
}

$sql .= " ORDER BY date_added DESC, id DESC";

$stmt = $db->prepare($sql);
if (!$stmt) {
    die("Failed to prepare: " . $db->error);
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

function badge($text, $ok = true) {
    $bg = $ok ? '#16a34a' : '#b91c1c';
    return '<span class="pill" style="background:'.$bg.';">'
         . htmlspecialchars($text)
         . '</span>';
}
?>
<h1>Airdrop Log</h1>
<p class="muted">
    Airdrop records from <code>moog_airdrops</code>.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Drops</div>
        <div class="card-value"><?php echo number_format($summary['total_drops']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Total Amount</div>
        <div class="card-value"><?php echo number_format($summary['total_amount']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Paid Amount</div>
        <div class="card-value"><?php echo number_format($summary['paid_amount']); ?></div>
    </div>
    <div class="card">
        <div class="card-label">Unpaid Amount</div>
        <div class="card-value"><?php echo number_format($summary['unpaid_amount']); ?></div>
    </div>
</div>

<form method="get" class="search-row">
    <input type="hidden" name="p" value="airdrops">
    <input type="text" name="q" placeholder="Search wallet, name..., source, notes..." value="<?php echo htmlspecialchars($q); ?>">

    <select name="status" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all"    <?php if($status==='all')    echo 'selected'; ?>>All</option>
        <option value="paid"   <?php if($status==='paid')   echo 'selected'; ?>>Paid</option>
        <option value="unpaid" <?php if($status==='unpaid') echo 'selected'; ?>>Unpaid</option>
    </select>

    <select name="verified" style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <option value="all" <?php if($verified==='all') echo 'selected'; ?>>All</option>
        <option value="yes" <?php if($verified==='yes') echo 'selected'; ?>>Verified</option>
        <option value="no"  <?php if($verified==='no')  echo 'selected'; ?>>Unverified</option>
    </select>

    <button type="submit">Filter</button>
</form>

<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Wallet</th>
            <th>Name</th>
            <th>Source</th>
            <th>Amount</th>
            <th>Paid</th>
            <th>Verified</th>
            <th>Notes</th>
            <th>Added</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="9">No airdrops found for this filter.</td></tr>
    <?php else: ?>
        <?php $i = 1; foreach ($rows as $r): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td style="font-size:11px;">
                <?php echo wallet_link($r['wallet_address'], null, true); ?>
                </td>
                <td><?php echo htmlspecialchars($r['name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['source'] ?? ''); ?></td>
                <td><?php echo number_format((int)$r['amount']); ?></td>
                <td>
                    <?php
                    if ($r['paid'] == 1) {
                        echo badge('PAID', true);
                    } else {
                        echo badge('UNPAID', false);
                    }
                    ?>
                </td>
                <td>
                    <?php
                    if ($r['verified'] == 1) {
                        echo badge('VERIFIED', true);
                    } else {
                        echo badge('UNVERIFIED', false);
                    }
                    ?>
                </td>
                <td><?php echo htmlspecialchars($r['notes'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['date_added']); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
