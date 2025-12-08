<?php
// mooglife/pages/market.php
// Market overview for MOOG using mg_market_cache snapshots.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Load latest 100 market snapshots
// ---------------------------------------------------------------------
$data   = [];
$latest = null;

$res = $db->query("
    SELECT
        token_symbol,
        token_mint,
        price_usd,
        market_cap_usd,
        fdv_usd,
        liquidity_usd,
        volume24h_usd,
        price_change_24h,
        holders,
        sol_price_usd,
        updated_at
    FROM mg_market_cache
    ORDER BY updated_at DESC
    LIMIT 100
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    $res->close();
}

if ($data) {
    $latest = $data[0];
}

// Helper
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Build arrays for charts (oldest -> newest)
$labels        = [];
$prices        = [];
$liquidities   = [];
$solPrices     = [];

if ($data) {
    $reversed = array_reverse($data); // oldest first
    foreach ($reversed as $row) {
        $ts = $row['updated_at'] ?? '';
        if ($ts) {
            $t = strtotime($ts);
            $labels[] = $t !== false ? date('m-d H:i', $t) : $ts;
        } else {
            $labels[] = '';
        }

        $prices[]      = isset($row['price_usd'])        ? (float)$row['price_usd']        : null;
        $liquidities[] = isset($row['liquidity_usd'])    ? (float)$row['liquidity_usd']    : null;
        $solPrices[]   = isset($row['sol_price_usd'])    ? (float)$row['sol_price_usd']    : null;
    }
}
?>
<h1>Market Overview</h1>

<?php if (!$latest): ?>

<div class="card">
    <p>No market data found in <code>mg_market_cache</code>. Run a market sync from the <strong>Sync</strong> page.</p>
</div>

<?php else: ?>

<div class="cards" style="margin-bottom:20px;">

    <div class="card">
        <div class="card-label">MOOG Price</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['price_usd'], 10); ?>
        </div>
        <div class="card-sub">
            24h change:
            <span style="font-weight:600;
                color:<?php echo ((float)$latest['price_change_24h'] >= 0) ? '#22c55e' : '#f97316'; ?>">
                <?php echo number_format((float)$latest['price_change_24h'], 2); ?>%
            </span>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Liquidity</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['liquidity_usd'], 2); ?>
        </div>
        <div class="card-sub">
            From DexScreener <code>liquidity.usd</code>
        </div>
    </div>

    <div class="card">
        <div class="card-label">24h Volume</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['volume24h_usd'], 2); ?>
        </div>
        <div class="card-sub">
            Last 24 hours trading volume.
        </div>
    </div>

    <div class="card">
        <div class="card-label">FDV</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['fdv_usd'], 2); ?>
        </div>
        <div class="card-sub">
            Fully Diluted Valuation.
        </div>
    </div>

    <div class="card">
        <div class="card-label">SOL Price</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['sol_price_usd'], 2); ?>
        </div>
        <div class="card-sub">
            Derived from DexScreener pair.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Tracked Holders</div>
        <div class="card-value">
            <?php echo number_format((int)$latest['holders']); ?>
        </div>
        <div class="card-sub">
            Snapshot holder count (same as holders table).
        </div>
    </div>

</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">MOOG Price & Liquidity (Last <?php echo count($labels); ?> Snapshots)</h2>
    <?php if (!$labels): ?>
        <p>No series data available yet. Once multiple market snapshots exist, a chart will display here.</p>
    <?php else: ?>
        <div style="position:relative;height:260px;max-height:260px;">
            <canvas id="priceChart"></canvas>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">SOL Price (Same Window)</h2>
    <?php if (!$labels): ?>
        <p>No series data available yet.</p>
    <?php else: ?>
        <div style="position:relative;height:260px;max-height:260px;">
            <canvas id="solChart"></canvas>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Raw Market Snapshots</h2>
    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">When</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Price (USD)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">FDV (USD)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Liquidity (USD)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Volume 24h (USD)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Change 24h (%)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">SOL Price (USD)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Holders</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$data): ?>
                <tr>
                    <td colspan="8" style="padding:6px;border-bottom:1px solid #111827;">No data yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php
                            $ts = $row['updated_at'] ?? '';
                            if ($ts) {
                                $t = strtotime($ts);
                                echo esc($t !== false ? date('Y-m-d H:i:s', $t) : $ts);
                            }
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            $<?php echo number_format((float)$row['price_usd'], 10); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            $<?php echo number_format((float)$row['fdv_usd'], 2); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            $<?php echo number_format((float)$row['liquidity_usd'], 2); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            $<?php echo number_format((float)$row['volume24h_usd'], 2); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo number_format((float)$row['price_change_24h'], 2); ?>%
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            $<?php echo number_format((float)$row['sol_price_usd'], 4); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo number_format((int)$row['holders']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Prepare JS data
$js_labels      = json_encode($labels);
$js_prices      = json_encode($prices);
$js_liquidities = json_encode($liquidities);
$js_solPrices   = json_encode($solPrices);
?>

<!-- Chart.js CDN (only used on this page) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    const labels      = <?php echo $js_labels; ?>;
    const prices      = <?php echo $js_prices; ?>;
    const liquidities = <?php echo $js_liquidities; ?>;
    const solPrices   = <?php echo $js_solPrices; ?>;

    if (labels.length && document.getElementById('priceChart')) {
        const ctx1 = document.getElementById('priceChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'MOOG Price (USD)',
                        data: prices,
                        borderWidth: 2,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Liquidity (USD)',
                        data: liquidities,
                        borderWidth: 2,
                        borderDash: [4, 4],
                        yAxisID: 'y2'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
                    },
                    y1: {
                        position: 'left'
                    },
                    y2: {
                        position: 'right',
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: {
                        labels: { boxWidth: 12 }
                    }
                }
            }
        });
    }

    if (labels.length && document.getElementById('solChart')) {
        const ctx2 = document.getElementById('solChart').getContext('2d');
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'SOL Price (USD)',
                        data: solPrices,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
                    }
                }
            }
        });
    }
})();
</script>

<?php endif; ?>
