<?php
// mooglife/pages/market.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

// Pull last 100 market snapshots (oldest first for chart)
$data   = [];
$latest = null;

$res = $db->query("
    SELECT token_symbol, token_mint, price_usd, market_cap_usd, fdv_usd,
           liquidity_usd, volume24h_usd, price_change_24h, holders,
           sol_price_usd, updated_at
    FROM mg_market_cache
    ORDER BY updated_at DESC
    LIMIT 100
");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    $res->free();
}

$data = array_reverse($data); // oldest → newest

if ($data) {
    $latest = $data[count($data) - 1];
}

// Build arrays for chart
$labels       = [];
$priceSeries  = [];
$liqSeries    = [];
$solSeries    = [];

foreach ($data as $row) {
    $labels[]      = $row['updated_at'];
    $priceSeries[] = (float)$row['price_usd'];
    $liqSeries[]   = (float)$row['liquidity_usd'];
    $solSeries[]   = (float)$row['sol_price_usd'];
}

// JSON for JS
$labelsJson  = json_encode($labels);
$priceJson   = json_encode($priceSeries);
$liqJson     = json_encode($liqSeries);
$solJson     = json_encode($solSeries);
?>
<h1>Market History</h1>
<p class="muted">
    Snapshots from <code>mg_market_cache</code> (fed by DexScreener) – price, liquidity, SOL price and volume over time.
</p>

<?php if ($latest): ?>
<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">MOOG Price (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['price_usd'], 10); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">FDV (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['fdv_usd'], 2); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Liquidity (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['liquidity_usd'], 2); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">SOL Price (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['sol_price_usd'], 2); ?>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Price & Liquidity (last <?php echo count($labels); ?> points)</h3>
    <canvas id="marketChart" style="max-height:320px;"></canvas>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">SOL Price (same window)</h3>
    <canvas id="solChart" style="max-height:260px;"></canvas>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:20px;">
    <p>No market data yet. Run a sync from the <strong>Sync</strong> page.</p>
</div>
<?php endif; ?>

<h2 style="margin-top:20px;">Raw Market Snapshots</h2>
<table class="data">
    <thead>
        <tr>
            <th>When</th>
            <th>Price (USD)</th>
            <th>FDV (USD)</th>
            <th>Liquidity (USD)</th>
            <th>Volume 24h (USD)</th>
            <th>Change 24h (%)</th>
            <th>SOL Price (USD)</th>
            <th>Holders</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$data): ?>
        <tr><td colspan="8">No data yet.</td></tr>
    <?php else: ?>
        <?php foreach (array_reverse($data) as $row): // newest first in table ?>
            <tr>
                <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
                <td>$<?php echo number_format((float)$row['price_usd'], 10); ?></td>
                <td>$<?php echo number_format((float)$row['fdv_usd'], 2); ?></td>
                <td>$<?php echo number_format((float)$row['liquidity_usd'], 2); ?></td>
                <td>$<?php echo number_format((float)$row['volume24h_usd'], 2); ?></td>
                <td><?php echo number_format((float)$row['price_change_24h'], 2); ?>%</td>
                <td>$<?php echo number_format((float)$row['sol_price_usd'], 2); ?></td>
                <td><?php echo (int)$row['holders']; ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php if ($latest): ?>
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels   = <?php echo $labelsJson; ?>;
const prices   = <?php echo $priceJson; ?>;
const liq      = <?php echo $liqJson; ?>;
const solPrice = <?php echo $solJson; ?>;

(function () {
    const ctx = document.getElementById('marketChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Price (USD)',
                    data: prices,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.15)',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25,
                    yAxisID: 'y1'
                },
                {
                    label: 'Liquidity (USD)',
                    data: liq,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25,
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
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
                    grid: { display: false }
                },
                y1: {
                    type: 'linear',
                    position: 'left',
                    title: { display: true, text: 'Price (USD)' }
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Liquidity (USD)' }
                }
            },
            plugins: {
                legend: { labels: { usePointStyle: true } }
            }
        }
    });
})();

(function () {
    const ctx = document.getElementById('solChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'SOL Price (USD)',
                    data: solPrice,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.15)',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
                    grid: { display: false }
                },
                y: {
                    title: { display: true, text: 'SOL Price (USD)' }
                }
            },
            plugins: {
                legend: { labels: { usePointStyle: true } }
            }
        }
    });
})();
</script>
<?php endif; ?>
