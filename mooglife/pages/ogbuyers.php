<?php
// mooglife/pages/ogbuyers.php
// OG Buyers snapshot from mg_moog_og_buyers + mg_moog_wallets.

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

function ml_elig_badge(int $is): string {
    $ok = $is === 1;
    $color = $ok ? '#16a34a' : '#b91c1c';
    $label = $ok ? 'ELIGIBLE' : 'INELIGIBLE';
    return '<span class="pill" style="background:' . $color . ';">' . esc($label) . '</span>';
}

function ml_tier_badge(int $tier): string {
    if ($tier <= 0) {
        return '<span class="pill" style="background:#4b5563;">NONE</span>';
    }
    $colors = [
        1 => '#22c55e',
        2 => '#3b82f6',
        3 => '#a855f7',
    ];
    $color = $colors[$tier] ?? '#22c55e';
    return '<span class="pill" style="background:' . $color . ';">Tier ' . (int)$tier . '</span>';
}

// ---------------------------------------------------------------------
// Detect table (if not present, show message)
// ---------------------------------------------------------------------
$check = $db->query("SHOW TABLES LIKE 'mg_moog_og_buyers'");
if (!$check || $check->num_rows === 0) {
    if ($check) $check->close();
    ?>
    <h1>OG Buyers</h1>
    <div class="card">
        <p><code>mg_moog_og_buyers</code> table not found. You may need to re-run the installer or your OG snapshot script.</p>
    </div>
    <?php
    return;
}
$check->close();

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
$tier  = isset($_GET['tier']) ? (int)$_GET['tier'] : 0; // 0 = all
$elig  = isset($_GET['elig']) ? trim($_GET['elig']) : 'all'; // all, yes, no
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}

// ---------------------------------------------------------------------
// Build WHERE
// ---------------------------------------------------------------------
$where  = '1=1';
$params = [];
$types  = '';

// search wallet, label, tags, notes
if ($q !== '') {
    $where .= ' AND (b.wallet LIKE ? OR w.label LIKE ? OR w.tags LIKE ? OR b.notes LIKE ? OR b.label_tags LIKE ?)';
    $like   = '%' . $q . '%';
    $types .= 'sssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// tier filter
if ($tier > 0) {
    $where .= ' AND b.og_tier = ?';
    $types .= 'i';
    $params[] = $tier;
}

// eligibility filter
if ($elig === 'yes') {
    $where .= ' AND b.is_eligible = 1';
} elseif ($elig === 'no') {
    $where .= ' AND b.is_eligible = 0';
}

// ---------------------------------------------------------------------
// Summary stats (quick counts per-tier & eligible)
// ---------------------------------------------------------------------
$summary = [
    'total'        => 0,
    'eligible'     => 0,
    'tier1'        => 0,
    'tier2'        => 0,
    'tier3'        => 0,
    'eligible_bal' => 0.0,
];

$res = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) AS eligible,
        SUM(CASE WHEN og_tier = 1 THEN 1 ELSE 0 END) AS tier1,
        SUM(CASE WHEN og_tier = 2 THEN 1 ELSE 0 END) AS tier2,
        SUM(CASE WHEN og_tier = 3 THEN 1 ELSE 0 END) AS tier3,
        COALESCE(SUM(CASE WHEN is_eligible = 1 THEN current_balance ELSE 0 END),0) AS eligible_bal
    FROM mg_moog_og_buyers
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total']        = (int)$row['total'];
    $summary['eligible']     = (int)$row['eligible'];
    $summary['tier1']        = (int)$row['tier1'];
    $summary['tier2']        = (int)$row['tier2'];
    $summary['tier3']        = (int)$row['tier3'];
    $summary['eligible_bal'] = (float)$row['eligible_bal'];
}

// ---------------------------------------------------------------------
// Main query
// ---------------------------------------------------------------------
$sql = "
    SELECT
        b.wallet,
        b.first_buy_time,
        b.first_buy_amount,
        b.total_bought,
        b.total_sold,
        b.current_balance,
        b.buy_tx_count,
        b.sell_tx_count,
        b.is_eligible,
        b.og_tier,
        b.label_tags,
        b.exclude_reason,
        b.notes,
        b.snapshot_at,
        w.label,
        w.type,
        w.tags
    FROM mg_moog_og_buyers b
    LEFT JOIN mg_moog_wallets w
        ON w.wallet = b.wallet
    WHERE {$where}
    ORDER BY b.og_tier DESC, b.current_balance DESC
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
<h1>OG Buyers</h1>
<p class="muted">
    Snapshot from <code>mg_moog_og_buyers</code>, joined with labels from <code>mg_moog_wallets</code>.
    This page is read-only: you generate the snapshot with your own script/cron.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total OG Snapshot</div>
        <div class="card-value">
            <?php echo number_format($summary['total']); ?>
        </div>
        <div class="card-sub">
            Rows in <code>mg_moog_og_buyers</code>.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Eligible OG</div>
        <div class="card-value">
            <?php echo number_format($summary['eligible']); ?>
        </div>
        <div class="card-sub">
            With <code>is_eligible = 1</code>, holding
            <?php echo number_format($summary['eligible_bal'], 3); ?> MOOG total.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Tier Breakdown</div>
        <div class="card-sub">
            Tier 1: <?php echo number_format($summary['tier1']); ?><br>
            Tier 2: <?php echo number_format($summary['tier2']); ?><br>
            Tier 3: <?php echo number_format($summary['tier3']); ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Rows Loaded</div>
        <div class="card-value">
            <?php echo number_format($totalRows); ?>
        </div>
        <div class="card-sub">
            Showing up to <?php echo (int)$limit; ?> matches (ordered by tier & balance).
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Filters</h2>
    <form method="get" class="search-row">
        <input type="hidden" name="p" value="ogbuyers">

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Search</label>
                <input
                    type="text"
                    name="q"
                    value="<?php echo esc($q); ?>"
                    placeholder="Wallet, label, tags, notes..."
                    style="width:220px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Tier</label>
                <select
                    name="tier"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="0" <?php if ($tier === 0) echo 'selected'; ?>>All tiers</option>
                    <option value="1" <?php if ($tier === 1) echo 'selected'; ?>>Tier 1</option>
                    <option value="2" <?php if ($tier === 2) echo 'selected'; ?>>Tier 2</option>
                    <option value="3" <?php if ($tier === 3) echo 'selected'; ?>>Tier 3</option>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Eligibility</label>
                <select
                    name="elig"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="all" <?php if ($elig === 'all') echo 'selected'; ?>>All</option>
                    <option value="yes" <?php if ($elig === 'yes') echo 'selected'; ?>>Eligible only</option>
                    <option value="no"  <?php if ($elig === 'no')  echo 'selected'; ?>>Ineligible only</option>
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
    <h2 style="margin-top:0;">OG Buyer Snapshot</h2>

    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Wallet</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Label</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tier</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Eligibility</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Current Bal</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Total Bought</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Total Sold</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Buys / Sells</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tags / Notes</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">First Buy / Snapshot</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="10" style="padding:8px;border-bottom:1px solid #111827;">
                        No OG buyers match the current filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_link($r['wallet'], $r['label'] ?: null, true); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($r['label']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo ml_tier_badge((int)$r['og_tier']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo ml_elig_badge((int)$r['is_eligible']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$r['current_balance'], 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$r['total_bought'], 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$r['total_sold'], 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            B: <?php echo (int)$r['buy_tx_count']; ?>
                            /
                            S: <?php echo (int)$r['sell_tx_count']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php
                            $tags  = trim((string)$r['tags']);
                            $ltags = trim((string)$r['label_tags']);
                            $notes = trim((string)$r['notes']);
                            if ($tags !== '') {
                                echo '<div><strong>Wallet tags:</strong> ' . esc($tags) . '</div>';
                            }
                            if ($ltags !== '') {
                                echo '<div><strong>OG tags:</strong> ' . esc($ltags) . '</div>';
                            }
                            if ($r['exclude_reason'] !== '') {
                                echo '<div><strong>Exclude:</strong> ' . esc($r['exclude_reason']) . '</div>';
                            }
                            if ($notes !== '') {
                                echo '<div><strong>Notes:</strong> ' . esc($notes) . '</div>';
                            }
                            if ($tags === '' && $ltags === '' && $notes === '' && $r['exclude_reason'] === '') {
                                echo '<span class="muted">—</span>';
                            }
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php if (!empty($r['first_buy_time'])): ?>
                                First: <?php echo esc(ml_dt($r['first_buy_time'])); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($r['snapshot_at'])): ?>
                                Snap: <?php echo esc(ml_dt($r['snapshot_at'])); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
