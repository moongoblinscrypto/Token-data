<?php
// mooglife/pages/market.php
// Market dashboard using mg_market_history.
// Price chart uses MOOG price × 1,000,000 so small moves actually show.
// No external plugins, just Chart.js.

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

mg_require_login();
$db = mg_db();

// ------------------------------------------------------
// Load snapshots from mg_market_history if present,
// otherwise fallback to mg_market_cache.
// ------------------------------------------------------

$rows   = [];
$latest = null;

// Check for history table
$hasHistory = false;
try {
    $res = $db->query("SHOW TABLES LIKE 'mg_market_history'");
    if ($res && $res->num_rows > 0) {
        $hasHistory = true;
    }
    if ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    $hasHistory = false;
}

if ($hasHistory) {
    $sql = "
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
            created_at AS snapshot_time
        FROM mg_market_history
        ORDER BY created_at ASC
        LIMIT 1000
    ";
} else {
    $sql = "
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
            updated_at AS snapshot_time
        FROM mg_market_cache
        ORDER BY updated_at ASC
        LIMIT 100
    ";
}

if ($res = $db->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $res->close();
}

if ($rows) {
    $latest = end($rows);
}

// ------------------------------------------------------
// Build arrays for charts
// - PriceScaled = price_usd × 1,000,000
// - Auto y-axis zoom based on min/max of scaled price
// ------------------------------------------------------
$labels       = [];
$priceScaled  = [];
$liq          = [];
$solLabels    = [];
$solPrice     = [];

$minScaled = null;
$maxScaled = null;

foreach ($rows as $row) {
    $tsStr = $row['snapshot_time'] ?? '';
    $t     = strtotime($tsStr);
    if (!$t) continue;

    $label = date('m-d H:i', $t);

    $labels[]   = $label;
    $solLabels[] = $label;

    $pReal   = (float)$row['price_usd'];
    $pScaled = $pReal * 1000000.0;
    $l       = (float)$row['liquidity_usd'];
    $s       = (float)$row['sol_price_usd'];

    $priceScaled[] = $pScaled;
    $liq[]         = $l;
    $solPrice[]    = $s;

    if ($minScaled === null || $pScaled < $minScaled) $minScaled = $pScaled;
    if ($maxScaled === null || $pScaled > $maxScaled) $maxScaled = $pScaled;
}

// If no data, avoid NaNs
if ($minScaled === null) {
    $minScaled = 0.0;
    $maxScaled = 0.0;
}

// Build padded y-range for scaled price axis
if ($minScaled === $maxScaled) {
    $pad    = ($minScaled > 0) ? $minScaled * 0.2 : 0.1;
    $y1Min  = $minScaled - $pad;
    $y1Max  = $maxScaled + $pad;
} else {
    $range  = $maxScaled - $minScaled;
    $y1Min  = $minScaled - $range * 0.15;
    $y1Max  = $maxScaled + $range * 0.15;
    if ($y1Min < 0) $y1Min = 0;
}

// JSON for JS
$labelsJson      = json_encode($labels, JSON_UNESCAPED_SLASHES);
$priceScaledJson = json_encode($priceScaled, JSON_UNESCAPED_SLASHES);
$liqJson         = json_encode($liq, JSON_UNESCAPED_SLASHES);
$labelsSolJson   = json_encode($solLabels, JSON_UNESCAPED_SLASHES);
$solJson         = json_encode($solPrice, JSON_UNESCAPED_SLASHES);

$pointsCount = count($labels);
$y1MinJson   = json_encode($y1Min);
$y1MaxJson   = json_encode($y1Max);
?>
<h1>Market Stats</h1>
<p class="muted">
    Live snapshots from <code><?php echo $hasHistory ? 'mg_market_history' : 'mg_market_cache'; ?></code>.
    Currently tracking <strong><?php echo (int)$pointsCount; ?></strong> snapshot<?php echo $pointsCount === 1 ? '' : 's'; ?>.
</p>

<?php if ($pointsCount < 2): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#1e293b;color:#e5e7eb;font-size:13px;">
        You only have <strong><?php echo (int)$pointsCount; ?></strong> data point<?php echo $pointsCount === 1 ? '' : 's'; ?>.
        As your cron runs and fills <code>mg_market_history</code>, this will shape into a proper trend.
    </div>
<?php endif; ?>

<?php if ($latest): ?>
<div class="cards" style="margin-bottom:15px;">
    <div class="card">
        <div class="card-label">MOOG Price (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['price_usd'], 10); ?>
        </div>
        <div class="card-sub">
            Last update: <?php echo htmlspecialchars($latest['snapshot_time'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">FDV (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['fdv_usd'], 2); ?>
        </div>
        <div class="card-sub">
            Market cap: $<?php echo number_format((float)$latest['market_cap_usd'], 2); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Liquidity (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['liquidity_usd'], 2); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Volume 24h (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['volume24h_usd'], 2); ?>
        </div>
        <div class="card-sub">
            24h Change: <?php echo number_format((float)$latest['price_change_24h'], 2); ?>%
        </div>
    </div>
    <div class="card">
        <div class="card-label">SOL Price (USD)</div>
        <div class="card-value">
            $<?php echo number_format((float)$latest['sol_price_usd'], 2); ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Holders</div>
        <div class="card-value">
            <?php echo (int)$latest['holders']; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Price &amp; Liquidity (last <?php echo (int)$pointsCount; ?> points)</h3>
    <div style="position:relative;width:100%;height:320px;">
        <canvas id="marketChart"></canvas>
    </div>
    <p class="muted" style="font-size:11px;margin-top:6px;">
        Left axis is <strong>MOOG price × 1,000,000</strong> (so tiny moves look more like Gecko),
        right axis is <strong>liquidity (USD)</strong>. Cards above still show the true USD price.
    </p>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">SOL Price (same window)</h3>
    <div style="position:relative;width:100%;height:260px;">
        <canvas id="solChart"></canvas>
    </div>
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
    <?php if (!$rows): ?>
        <tr><td colspan="8">No data yet.</td></tr>
    <?php else: ?>
        <?php foreach (array_reverse($rows) as $row): // newest first in table ?>
            <tr>
                <td><?php echo htmlspecialchars($row['snapshot_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
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
<!-- Chart.js only (no plugins) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels      = <?php echo $labelsJson; ?>;
const priceScaled = <?php echo $priceScaledJson; ?>;
const liq         = <?php echo $liqJson; ?>;
const solLabels   = <?php echo $labelsSolJson; ?>;
const solPrice    = <?php echo $solJson; ?>;
const priceYMin   = <?php echo $y1MinJson; ?>;
const priceYMax   = <?php echo $y1MaxJson; ?>;

(function () {
    const ctx = document.getElementById('marketChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Price × 1,000,000',
                    data: priceScaled,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.15)',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHitRadius: 5,
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
                    pointHitRadius: 5,
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
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
                    grid: { display: false }
                },
                y1: {
                    type: 'linear',
                    position: 'left',
                    min: priceYMin,
                    max: priceYMax,
                    title: { display: true, text: 'Price × 1,000,000 (USD)' },
                    ticks: {
                        callback: function(value) {
                            return value.toFixed(3);
                        }
                    }
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Liquidity (USD)' }
                }
            },
            plugins: {
                legend: { labels: { usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.datasetIndex === 0) {
                                const scaled = ctx.parsed.y;
                                const real   = scaled / 1000000.0;
                                return 'Price: ' + scaled.toFixed(3) +
                                       ' (=$' + real.toFixed(10) + ')';
                            }
                            return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2);
                        }
                    }
                }
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
            labels: solLabels,
            datasets: [
                {
                    label: 'SOL Price (USD)',
                    data: solPrice,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.15)',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHitRadius: 5,
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
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
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
