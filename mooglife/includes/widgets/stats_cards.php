<?php
// mooglife/includes/widgets/stats_cards.php
require_once __DIR__ . '/../db.php';
$db = moog_db();

// ---- holders ----
$totalHolders = 0;
$res = $db->query("SELECT COUNT(*) AS c FROM mg_moog_holders");
if ($res) {
    if ($row = $res->fetch_assoc()) {
        $totalHolders = (int)$row['c'];
    }
    $res->free();
}

// ---- latest market snapshot ----
$priceUsd      = 0.0;
$volume24Usd   = 0.0;
$fdvUsd        = 0.0;
$liquidityUsd  = 0.0;
$lastUpdated   = null;

$res = $db->query("
    SELECT price_usd, volume24h_usd, fdv_usd, liquidity_usd, updated_at
    FROM mg_market_cache
    ORDER BY updated_at DESC
    LIMIT 1
");
if ($res && $row = $res->fetch_assoc()) {
    $priceUsd     = (float)$row['price_usd'];
    $volume24Usd  = (float)$row['volume24h_usd'];   // <-- REAL 24h vol from DexScreener
    $fdvUsd       = (float)$row['fdv_usd'];
    $liquidityUsd = (float)$row['liquidity_usd'];
    $lastUpdated  = $row['updated_at'];
    $res->free();
}
?>
<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total Holders</div>
        <div class="card-value">
            <?php echo number_format($totalHolders); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">24h Volume (USD)</div>
        <div class="card-value">
            $<?php echo number_format($volume24Usd, 2); ?>
        </div>
        <?php if ($lastUpdated): ?>
            <div class="card-sub">Last sync: <?php echo htmlspecialchars($lastUpdated); ?></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-label">Price</div>
        <div class="card-value">
            $<?php echo number_format($priceUsd, 9); ?>
        </div>
    </div>
</div>
