<?php
// mooglife/pages/dashboard.php
// Main dashboard: top stats + mini holders + mini tx.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ml_dt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('Y-m-d H:i', $ts);
}

// ---------------------------------------------------------
// 1) Latest market snapshot (mg_market_cache)
// ---------------------------------------------------------
$market = [
    'price_usd'        => 0.0,
    'fdv_usd'          => 0.0,
    'liquidity_usd'    => 0.0,
    'volume24h_usd'    => 0.0,
    'price_change_24h' => 0.0,
    'holders'          => 0,
    'sol_price_usd'    => 0.0,
    'updated_at'       => null,
];

$res = $db->query("
    SELECT token_symbol, token_mint, price_usd, market_cap_usd,
           fdv_usd, liquidity_usd, volume24h_usd, price_change_24h,
           holders, sol_price_usd, updated_at
    FROM mg_market_cache
    ORDER BY updated_at DESC
    LIMIT 1
");
if ($res && ($row = $res->fetch_assoc())) {
    $market['price_usd']        = (float)($row['price_usd'] ?? 0);
    $market['fdv_usd']          = (float)($row['fdv_usd'] ?? 0);
    $market['liquidity_usd']    = (float)($row['liquidity_usd'] ?? 0);
    $market['volume24h_usd']    = (float)($row['volume24h_usd'] ?? 0);
    $market['price_change_24h'] = (float)($row['price_change_24h'] ?? 0);
    $market['holders']          = (int)($row['holders'] ?? 0);
    $market['sol_price_usd']    = (float)($row['sol_price_usd'] ?? 0);
    $market['updated_at']       = $row['updated_at'] ?? null;
    $res->close();
}

// ---------------------------------------------------------
// 2) Holders stats (mg_moog_holders)
// ---------------------------------------------------------
$totalHolders = 0;
$topHolder    = null;

$res = $db->query("SELECT COUNT(*) AS c FROM mg_moog_holders");
if ($res && ($row = $res->fetch_assoc())) {
    $totalHolders = (int)$row['c'];
    $res->close();
}

$res = $db->query("
    SELECT wallet, ui_amount, percent
    FROM mg_moog_holders
    ORDER BY ui_amount DESC
    LIMIT 1
");
if ($res && ($row = $res->fetch_assoc())) {
    $topHolder = $row;
    $res->close();
}

// ---------------------------------------------------------
// 3) Airdrop summary (moog_airdrops)
// ---------------------------------------------------------
$airSummary = [
    'count' => 0,
    'sum'   => 0.0,
];

$res = $db->query("
    SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
    FROM moog_airdrops
");
if ($res && ($row = $res->fetch_assoc())) {
    $airSummary['count'] = (int)$row['c'];
    $airSummary['sum']   = (float)$row['s'];
    $res->close();
}

// ---------------------------------------------------------
// 4) Tx summary (mg_moog_tx)
// ---------------------------------------------------------
$txSummary = [
    'count'      => 0,
    'last_time'  => null,
];

$res = $db->query("
    SELECT COUNT(*) AS c, MAX(block_time) AS last_time
    FROM mg_moog_tx
");
if ($res && ($row = $res->fetch_assoc())) {
    $txSummary['count']     = (int)$row['c'];
    $txSummary['last_time'] = $row['last_time'] ?? null;
    $res->close();
}

// ---------------------------------------------------------
// 5) Optional OG snapshot summary (mg_moog_og_buyers if exists)
// ---------------------------------------------------------
$ogSummary = [
    'enabled'  => false,
    'total'    => 0,
    'eligible' => 0,
];

$check = $db->query("SHOW TABLES LIKE 'mg_moog_og_buyers'");
if ($check && $check->num_rows > 0) {
    $ogSummary['enabled'] = true;
    $check->close();

    $res = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) AS eligible
        FROM mg_moog_og_buyers
    ");
    if ($res && ($row = $res->fetch_assoc())) {
        $ogSummary['total']    = (int)$row['total'];
        $ogSummary['eligible'] = (int)$row['eligible'];
        $res->close();
    }
} elseif ($check) {
    $check->close();
}

// ---------------------------------------------------------
// 6) Mini: top 10 holders (mg_moog_holders + mg_moog_wallets)
// ---------------------------------------------------------
$miniHolders = [];

$res = $db->query("
    SELECT
        h.wallet,
        h.ui_amount  AS balance_moog,
        h.percent    AS pct,
        w.label      AS label,
        w.type       AS type
    FROM mg_moog_holders h
    LEFT JOIN mg_moog_wallets w
        ON w.wallet = h.wallet
    ORDER BY h.ui_amount DESC
    LIMIT 10
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $miniHolders[] = $row;
    }
    $res->close();
}

// ---------------------------------------------------------
// 7) Mini: recent 10 tx (mg_moog_tx)
// ---------------------------------------------------------
$miniTx = [];

$res = $db->query("
    SELECT
        block_time,
        direction,
        amount_moog,
        from_wallet,
        to_wallet,
        source,
        tx_hash
    FROM mg_moog_tx
    ORDER BY block_time DESC
    LIMIT 10
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $miniTx[] = $row;
    }
    $res->close();
}

?>
<h1>Dashboard</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">MOOG Price</div>
        <div class="card-value">
            $<?php echo number_format($market['price_usd'], 10); ?>
        </div>
        <div class="card-sub">
            24h change:
            <span style="font-weight:600;
                color:<?php echo ($market['price_change_24h'] >= 0) ? '#22c55e' : '#f97316'; ?>">
                <?php echo number_format($market['price_change_24h'], 2); ?>%
            </span>
            <?php if ($market['updated_at']): ?>
                <br>
                <span style="font-size:12px;">
                    Last market snapshot: <?php echo esc(ml_dt($market['updated_at'])); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Liquidity</div>
        <div class="card-value">
            $<?php echo number_format($market['liquidity_usd'], 2); ?>
        </div>
        <div class="card-sub">
            From latest DexScreener snapshot.
        </div>
    </div>

    <div class="card">
        <div class="card-label">24h Volume</div>
        <div class="card-value">
            $<?php echo number_format($market['volume24h_usd'], 2); ?>
        </div>
        <div class="card-sub">
            24h volume in USD.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Tracked Holders</div>
        <div class="card-value">
            <?php echo number_format($totalHolders ?: $market['holders']); ?>
        </div>
        <div class="card-sub">
            From <code>mg_moog_holders</code> (and market cache).
        </div>
    </div>
</div>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Airdrops Logged</div>
        <div class="card-value">
            <?php echo number_format($airSummary['count']); ?>
        </div>
        <div class="card-sub">
            Total airdropped: <?php echo number_format($airSummary['sum'], 0); ?> MOOG
        </div>
    </div>

    <div class="card">
        <div class="card-label">Total Tx Recorded</div>
        <div class="card-value">
            <?php echo number_format($txSummary['count']); ?>
        </div>
        <div class="card-sub">
            Last tx:
            <?php echo $txSummary['last_time'] ? esc(ml_dt($txSummary['last_time'])) : '—'; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">SOL Price</div>
        <div class="card-value">
            $<?php echo number_format($market['sol_price_usd'], 2); ?>
        </div>
        <div class="card-sub">
            From latest market snapshot.
        </div>
    </div>

    <?php if ($ogSummary['enabled']): ?>
        <div class="card">
            <div class="card-label">OG Snapshot</div>
            <div class="card-value">
                <?php echo number_format($ogSummary['eligible']); ?> / <?php echo number_format($ogSummary['total']); ?>
            </div>
            <div class="card-sub">
                Eligible OG vs total in <code>mg_moog_og_buyers</code>.
            </div>
        </div>
    <?php endif; ?>
</div>

<div style="display:flex;flex-wrap:wrap;gap:20px;">

    <!-- Mini Top Holders -->
    <div class="card" style="flex:1 1 380px;min-width:320px;">
        <h2 style="margin-top:0;">Top Holders (Top 10)</h2>
        <div style="overflow-x:auto;">
            <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">#</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Wallet</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Label</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Balance (MOOG)</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">%</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$miniHolders): ?>
                    <tr>
                        <td colspan="5" style="padding:8px;border-bottom:1px solid #111827;">
                            No holder data yet. Run a holder sync from the <a href="?p=sync">Sync</a> page.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $rank = 0; ?>
                    <?php foreach ($miniHolders as $h): ?>
                        <?php
                        $rank++;
                        $w  = $h['wallet'];
                        $lb = $h['label'] ?? '';
                        ?>
                        <tr>
                            <td style="padding:6px;border-bottom:1px solid #111827;"><?php echo $rank; ?></td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                                <?php echo wallet_link($w, $lb !== '' ? $lb : null, true); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc($lb); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                                <?php echo number_format((float)$h['balance_moog'], 3); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                                <?php echo number_format((float)$h['pct'], 4); ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:6px;font-size:12px;">
            See full list on the <a href="?p=holders">Holders</a> page.
        </p>
    </div>

    <!-- Mini Recent Tx -->
    <div class="card" style="flex:1 1 380px;min-width:320px;">
        <h2 style="margin-top:0;">Recent Tx (Last 10)</h2>
        <div style="overflow-x:auto;">
            <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Time</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Dir</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Amount</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">From</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">To</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Source</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$miniTx): ?>
                    <tr>
                        <td colspan="6" style="padding:8px;border-bottom:1px solid #111827;">
                            No tx data yet. Run a tx sync from the <a href="?p=sync">Sync</a> page.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($miniTx as $t): ?>
                        <tr>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc(ml_dt($t['block_time'])); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo esc($t['direction']); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                                <?php echo number_format((float)$t['amount_moog'], 3); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                                <?php echo wallet_link($t['from_wallet'], null, true); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                                <?php echo wallet_link($t['to_wallet'], null, true); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <span class="pill" style="background:#111827;"><?php echo esc($t['source']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:6px;font-size:12px;">
            See full feed on the <a href="?p=tx">Tx History</a> page.
        </p>
    </div>

</div>
