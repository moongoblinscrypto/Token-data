<?php
// mooglife/pages/wallet.php
// Wallet profile view: show everything we know about a single wallet.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Resolve requested wallet
// ---------------------------------------------------------------------
$wallet = isset($_GET['wallet']) ? trim($_GET['wallet']) : '';
$wallet = substr($wallet, 0, 64); // basic safety

if ($wallet === '') {
    ?>
    <h1>Wallet profile</h1>
    <p>No wallet provided. Use the sidebar “Jump to wallet…” form or add <code>&amp;wallet=...</code> to the URL.</p>
    <?php
    return;
}

// simple esc helper
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// 1) Basic metadata from mg_moog_wallets
// ---------------------------------------------------------------------
$meta = null;

$stmt = $db->prepare("
    SELECT wallet, label, type, tags,
           socials_x, socials_discord, socials_telegram, socials_notes,
           created_at, updated_at
    FROM mg_moog_wallets
    WHERE wallet = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('s', $wallet);
    $stmt->execute();
    $res = $stmt->get_result();
    $meta = $res->fetch_assoc() ?: null;
    $stmt->close();
}

// ---------------------------------------------------------------------
// 2) Holder snapshot from mg_moog_holders
// ---------------------------------------------------------------------
$holder = null;

$stmt = $db->prepare("
    SELECT wallet, ui_amount, percent, updated_at
    FROM mg_moog_holders
    WHERE wallet = ?
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('s', $wallet);
    $stmt->execute();
    $res = $stmt->get_result();
    $holder = $res->fetch_assoc() ?: null;
    $stmt->close();
}

// ---------------------------------------------------------------------
// 3) Airdrop summary from moog_airdrops
// ---------------------------------------------------------------------
$airCount = 0;
$airTotal = 0.0;

$stmt = $db->prepare("
    SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s
    FROM moog_airdrops
    WHERE wallet_address = ?
");
if ($stmt) {
    $stmt->bind_param('s', $wallet);
    $stmt->execute();
    $stmt->bind_result($c, $s);
    if ($stmt->fetch()) {
        $airCount = (int)$c;
        $airTotal = (float)$s;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// 4) OG buyer snapshot from mg_moog_og_buyers (if table exists)
// ---------------------------------------------------------------------
$ogBuyer   = null;
$ogEnabled = false;

$check = $db->query("SHOW TABLES LIKE 'mg_moog_og_buyers'");
if ($check && $check->num_rows > 0) {
    $ogEnabled = true;
    $check->close();

    $stmt = $db->prepare("
        SELECT wallet,
               first_buy_time,
               first_buy_amount,
               total_bought,
               total_sold,
               current_balance,
               buy_tx_count,
               sell_tx_count,
               is_eligible,
               og_tier,
               label_tags,
               exclude_reason,
               notes,
               snapshot_at
        FROM mg_moog_og_buyers
        WHERE wallet = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        $ogBuyer = $res->fetch_assoc() ?: null;
        $stmt->close();
    }
} elseif ($check) {
    $check->close();
}

// ---------------------------------------------------------------------
// 5) OG rewards from mg_moog_og_rewards (if table exists)
// ---------------------------------------------------------------------
$ogRewards   = [];
$rewardsOn   = false;
$check = $db->query("SHOW TABLES LIKE 'mg_moog_og_rewards'");
if ($check && $check->num_rows > 0) {
    $rewardsOn = true;
    $check->close();

    $stmt = $db->prepare("
        SELECT wallet, planned_amount, tx_hash, status, notes, created_at, updated_at
        FROM mg_moog_og_rewards
        WHERE wallet = ?
        ORDER BY created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ogRewards[] = $row;
        }
        $stmt->close();
    }
} elseif ($check) {
    $check->close();
}

// ---------------------------------------------------------------------
// 6) Trade totals + recent trades from mg_moog_tx
// ---------------------------------------------------------------------
// Totals
$inMoog     = 0.0;
$outMoog    = 0.0;
$tradeCount = 0;

$stmt = $db->prepare("
    SELECT
        SUM(CASE WHEN to_wallet   = ? THEN amount_moog ELSE 0 END) AS amt_in,
        SUM(CASE WHEN from_wallet = ? THEN amount_moog ELSE 0 END) AS amt_out,
        COUNT(*) AS tx_count
    FROM mg_moog_tx
    WHERE from_wallet = ? OR to_wallet = ?
");
if ($stmt) {
    $stmt->bind_param('ssss', $wallet, $wallet, $wallet, $wallet);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $inMoog     = (float)($row['amt_in']  ?? 0);
        $outMoog    = (float)($row['amt_out'] ?? 0);
        $tradeCount = (int)($row['tx_count'] ?? 0);
    }
    $stmt->close();
}
$netFlow = $inMoog - $outMoog;

// Recent trades
$recentTx = [];

$stmt = $db->prepare("
    SELECT
        block_time,
        tx_hash,
        direction,
        amount_moog,
        from_wallet,
        to_wallet,
        source
    FROM mg_moog_tx
    WHERE from_wallet = ? OR to_wallet = ?
    ORDER BY block_time DESC
    LIMIT 25
");
if ($stmt) {
    $stmt->bind_param('ss', $wallet, $wallet);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recentTx[] = $row;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------
// Derive simple display variables
// ---------------------------------------------------------------------
$label         = $meta['label']         ?? '';
$type          = $meta['type']          ?? '';
$tags          = $meta['tags']          ?? '';
$socials_x     = $meta['socials_x']     ?? '';
$socials_disc  = $meta['socials_discord'] ?? '';
$socials_tg    = $meta['socials_telegram'] ?? '';
$socials_notes = $meta['socials_notes'] ?? '';
$metaCreated   = $meta['created_at']    ?? null;
$metaUpdated   = $meta['updated_at']    ?? null;

$balance       = $holder ? (float)$holder['ui_amount'] : 0.0;
$balancePct    = $holder ? (float)$holder['percent']   : 0.0;
$holderUpdated = $holder['updated_at'] ?? null;

$ogTier        = $ogBuyer ? (int)$ogBuyer['og_tier'] : 0;
$isEligible    = $ogBuyer ? (int)$ogBuyer['is_eligible'] === 1 : false;
$firstBuyTime  = $ogBuyer['first_buy_time']  ?? null;
$firstBuyAmt   = $ogBuyer ? (float)$ogBuyer['first_buy_amount'] : 0.0;
$totalBought   = $ogBuyer ? (float)$ogBuyer['total_bought'] : 0.0;
$totalSold     = $ogBuyer ? (float)$ogBuyer['total_sold']   : 0.0;
$ogTags        = $ogBuyer['label_tags']     ?? '';
$excludeReason = $ogBuyer['exclude_reason'] ?? '';
$ogNotes       = $ogBuyer['notes']          ?? '';
$snapshotAt    = $ogBuyer['snapshot_at']    ?? null;

// small helpers
function format_dt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('Y-m-d H:i', $ts);
}

?>
<h1>Wallet profile</h1>

<div class="cards" style="margin-bottom:20px;">

    <div class="card">
        <div class="card-label">Wallet</div>
        <div class="card-value" style="word-break:break-all;">
            <code><?php echo esc($wallet); ?></code>
        </div>
        <div class="card-sub">
            <?php if ($label !== ''): ?>
                Label: <strong><?php echo esc($label); ?></strong><br>
            <?php endif; ?>
            <?php if ($type !== ''): ?>
                Type: <code><?php echo esc($type); ?></code><br>
            <?php endif; ?>
            <?php if ($tags !== ''): ?>
                Tags: <?php echo esc($tags); ?><br>
            <?php endif; ?>
            <?php if ($metaCreated): ?>
                Created in GoblinsHQ: <?php echo esc(format_dt($metaCreated)); ?><br>
            <?php endif; ?>
            <?php if ($metaUpdated): ?>
                Last updated: <?php echo esc(format_dt($metaUpdated)); ?>
            <?php endif; ?>
            <?php if (!$meta): ?>
                <span class="muted" style="display:block;margin-top:4px;font-size:12px;">
                    No metadata found in <code>mg_moog_wallets</code> yet.
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Balance</div>
        <div class="card-value">
            <?php echo number_format($balance, 3); ?> MOOG
        </div>
        <div class="card-sub">
            Share of tracked supply:
            <?php echo number_format($balancePct, 4); ?>%
            <?php if ($holderUpdated): ?>
                <br>Snapshot updated: <?php echo esc(format_dt($holderUpdated)); ?>
            <?php endif; ?>
            <?php if (!$holder): ?>
                <br><span class="muted" style="font-size:12px;">
                    Wallet not in current <code>mg_moog_holders</code> snapshot (balance may be 0 or below top cutoff).
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Airdrops</div>
        <div class="card-value">
            <?php echo number_format($airCount); ?> drops
        </div>
        <div class="card-sub">
            Total airdropped:
            <?php echo number_format($airTotal, 0); ?> MOOG
            <?php if ($airCount === 0): ?>
                <br><span class="muted" style="font-size:12px;">No records in <code>moog_airdrops</code>.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Trades</div>
        <div class="card-value">
            <?php echo number_format($tradeCount); ?> swaps
        </div>
        <div class="card-sub">
            In: <?php echo number_format($inMoog, 3); ?> MOOG<br>
            Out: <?php echo number_format($outMoog, 3); ?> MOOG<br>
            Net flow: <strong><?php echo number_format($netFlow, 3); ?> MOOG</strong>
        </div>
    </div>

    <?php if ($ogEnabled): ?>
        <div class="card">
            <div class="card-label">OG Buyer</div>
            <?php if ($ogBuyer): ?>
                <div class="card-value">
                    Tier <?php echo (int)$ogTier; ?>
                    <?php if ($isEligible): ?>
                        <span class="pill" style="background:#16a34a;margin-left:8px;">ELIGIBLE</span>
                    <?php else: ?>
                        <span class="pill" style="background:#b91c1c;margin-left:8px;">INELIGIBLE</span>
                    <?php endif; ?>
                </div>
                <div class="card-sub">
                    First buy: <?php echo esc(format_dt($firstBuyTime)); ?>
                    (<?php echo number_format($firstBuyAmt, 3); ?> MOOG)<br>
                    Total bought: <?php echo number_format($totalBought, 3); ?> MOOG<br>
                    Total sold: <?php echo number_format($totalSold, 3); ?> MOOG<br>
                    Buys: <?php echo (int)$ogBuyer['buy_tx_count']; ?>,
                    Sells: <?php echo (int)$ogBuyer['sell_tx_count']; ?><br>
                    <?php if ($ogTags !== ''): ?>
                        Tags: <?php echo esc($ogTags); ?><br>
                    <?php endif; ?>
                    <?php if ($excludeReason !== ''): ?>
                        Exclude reason: <?php echo esc($excludeReason); ?><br>
                    <?php endif; ?>
                    <?php if ($ogNotes !== ''): ?>
                        Notes: <?php echo esc($ogNotes); ?><br>
                    <?php endif; ?>
                    <?php if ($snapshotAt): ?>
                        Snapshot at: <?php echo esc(format_dt($snapshotAt)); ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card-sub">
                    Wallet not present in <code>mg_moog_og_buyers</code> snapshot.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($rewardsOn): ?>
    <div class="card" style="margin-bottom:20px;">
        <h2 style="margin-top:0;">OG Rewards</h2>
        <?php if (empty($ogRewards)): ?>
            <p>No OG reward records for this wallet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                    <tr>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Planned Amount</th>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Status</th>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tx Hash</th>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Notes</th>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Created</th>
                        <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ogRewards as $r): ?>
                        <tr>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo number_format((float)$r['planned_amount'], 3); ?> MOOG
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <span class="pill">
                                    <?php echo esc($r['status']); ?>
                                </span>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                                <?php if (!empty($r['tx_hash'])): ?>
                                    <code><?php echo esc($r['tx_hash']); ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc($r['notes'] ?? ''); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc(format_dt($r['created_at'])); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc(format_dt($r['updated_at'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Recent Trades</h2>
    <?php if (empty($recentTx)): ?>
        <p>No trades recorded in <code>mg_moog_tx</code> for this wallet yet.</p>
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
                <?php foreach ($recentTx as $tx): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc(format_dt($tx['block_time'])); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($tx['direction']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format((float)$tx['amount_moog'], 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_link($tx['from_wallet'], null, true); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_link($tx['to_wallet'], null, true); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($tx['source']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php if (!empty($tx['tx_hash'])): ?>
                                <code><?php echo esc($tx['tx_hash']); ?></code>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
