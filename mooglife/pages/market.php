<?php
// mooglife/pages/market.php
// Market Stats – uses mg_market_history for cards + charts.

require_once __DIR__ . '/../includes/auth.php';
mg_require_login();

require_once __DIR__ . '/../includes/db.php';
$db = mg_db();

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------
$MARKET_TABLE = 'mg_market_history';
$MAX_POINTS   = 400;

// Helpers
function ml_safe_float($row, $key, $default = 0.0): float
{
    if (!is_array($row)) return (float)$default;
    if (!array_key_exists($key, $row)) return (float)$default;
    $v = $row[$key];

    // If DB returns strings, normalize
    if ($v === null || $v === '') return (float)$default;
    return (float)$v;
}

function ml_safe_int($row, $key, $default = 0): int
{
    if (!is_array($row)) return (int)$default;
    if (!array_key_exists($key, $row)) return (int)$default;
    $v = $row[$key];
    if ($v === null || $v === '') return (int)$default;
    return (int)$v;
}

// ---------------------------------------------------------------------
// Load counts + latest + chart rows
// ---------------------------------------------------------------------
$totalSnapshots = 0;
$latest = null;
$chartRows = [];

try {
    $rs = $db->query("SELECT COUNT(*) AS c FROM `{$MARKET_TABLE}`");
    if ($rs && ($r = $rs->fetch_assoc())) $totalSnapshots = (int)$r['c'];

    $rs2 = $db->query("SELECT * FROM `{$MARKET_TABLE}` ORDER BY `created_at` DESC LIMIT 1");
    if ($rs2 && ($r2 = $rs2->fetch_assoc())) $latest = $r2;

    $rs3 = $db->query("
        SELECT `created_at`, `price_usd`, `liquidity_usd`, `sol_price_usd`, `holders`
        FROM `{$MARKET_TABLE}`
        ORDER BY `created_at` DESC
        LIMIT {$MAX_POINTS}
    ");
    if ($rs3) {
        while ($rr = $rs3->fetch_assoc()) $chartRows[] = $rr;
    }
} catch (Throwable $e) {
    $totalSnapshots = 0;
    $latest = null;
    $chartRows = [];
}

// Oldest -> newest
$chartRows = array_reverse($chartRows);

// ---------------------------------------------------------------------
// Cards (latest row)
// ---------------------------------------------------------------------
$priceUsd      = $latest ? ml_safe_float($latest, 'price_usd')       : 0.0;
$fdvUsd        = $latest ? ml_safe_float($latest, 'fdv_usd')         : 0.0;
$liquidityUsd  = $latest ? ml_safe_float($latest, 'liquidity_usd')   : 0.0;
$volume24hUsd  = $latest ? ml_safe_float($latest, 'volume24h_usd')   : 0.0;
$solPriceUsd   = $latest ? ml_safe_float($latest, 'sol_price_usd')   : 0.0;
$holdersCount  = $latest ? ml_safe_int($latest, 'holders')           : 0;

$priceChange24 = ($latest && array_key_exists('price_change_24h', $latest) && $latest['price_change_24h'] !== null)
    ? (float)$latest['price_change_24h']
    : 0.0;

?>
<div class="page-header">
    <h1>Market Stats</h1>
    <p class="sub">
        Live snapshots from <code><?php echo htmlspecialchars($MARKET_TABLE, ENT_QUOTES, 'UTF-8'); ?></code>.
        Currently tracking <?php echo number_format($totalSnapshots); ?> snapshots.
    </p>
</div>

<div class="grid grid-4 gap-3" style="margin-bottom:18px;">
    <div class="card card-metric">
        <div class="card-sub">MOOG Price (USD)</div>
        <div class="metric">$<?php echo number_format($priceUsd, 9); ?></div>
        <div class="metric-sub">
            24h Change:
            <?php
                $cls  = $priceChange24 > 0 ? 'pill up' : ($priceChange24 < 0 ? 'pill down' : 'pill');
                $sign = $priceChange24 > 0 ? '+' : '';
            ?>
            <span class="<?php echo $cls; ?>">
                <?php echo $sign . number_format($priceChange24, 2); ?>%
            </span>
        </div>
        <?php if ($latest && !empty($latest['created_at'])): ?>
            <div class="metric-footer">Last update: <?php echo htmlspecialchars($latest['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>

    <div class="card card-metric">
        <div class="card-sub">FDV (USD)</div>
        <div class="metric">$<?php echo number_format($fdvUsd, 2); ?></div>
        <div class="metric-sub">Market cap style FDV</div>
    </div>

    <div class="card card-metric">
        <div class="card-sub">Liquidity (USD)</div>
        <div class="metric">$<?php echo number_format($liquidityUsd, 2); ?></div>
        <div class="metric-sub">24h Volume: $<?php echo number_format($volume24hUsd, 2); ?></div>
    </div>

    <div class="card card-metric">
        <div class="card-sub">SOL Price (USD)</div>
        <div class="metric">$<?php echo number_format($solPriceUsd, 2); ?></div>
        <div class="metric-sub">Holders (snapshot): <?php echo number_format($holdersCount); ?></div>
    </div>
</div>

<?php if (count($chartRows) === 0): ?>
    <div class="card">
        <div class="card-sub">Market History</div>
        <p>No market history rows could be loaded from <code><?php echo htmlspecialchars($MARKET_TABLE, ENT_QUOTES, 'UTF-8'); ?></code>.</p>
    </div>
<?php else: ?>

<?php
// Build chart arrays.
// IMPORTANT: if sol_price_usd is 0, treat it as NULL so Chart.js doesn't compress to zero.
$labels = [];
$priceSeries = [];
$liquiditySeries = [];
$solSeries = [];

$minSol = null;
$maxSol = null;

foreach ($chartRows as $r) {
    $labels[] = $r['created_at'];

    $p = ml_safe_float($r, 'price_usd', 0.0);
    $l = ml_safe_float($r, 'liquidity_usd', 0.0);

    $sRaw = ml_safe_float($r, 'sol_price_usd', 0.0);
    $s = ($sRaw <= 0.0) ? null : $sRaw; // <<< key fix

    $priceSeries[] = $p;
    $liquiditySeries[] = $l;
    $solSeries[] = $s;

    if ($s !== null) {
        $minSol = ($minSol === null) ? $s : min($minSol, $s);
        $maxSol = ($maxSol === null) ? $s : max($maxSol, $s);
    }
}

$solSuggestedMin = ($minSol !== null) ? max(1, $minSol - 5) : 1;
$solSuggestedMax = ($maxSol !== null) ? ($maxSol + 5) : 200;
?>

    <div class="card" style="margin-bottom:18px;">
        <div class="card-sub">Price &amp; Liquidity (last <?php echo count($labels); ?> points)</div>
        <canvas id="moogPriceLiquidityChart" height="120"></canvas>
        <div class="card-footer">
            Left axis is MOOG price × 1,000,000 (visibility). Right axis is liquidity (USD).
            Cards above show true price.
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div class="card-sub">MOOG Candles (15-minute buckets)</div>
        <div class="card-footer">
            Candles will require true OHLC data (or building OHLC from per-trade ticks). For now we keep the line chart stable.
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div class="card-sub">SOL Price (same window)</div>
        <canvas id="solPriceChart" height="120"></canvas>
        <div class="card-footer">
            Note: any rows where <code>sol_price_usd = 0</code> are ignored to prevent axis compression.
        </div>
    </div>

    <script>
    (function () {
        const labels = <?php echo json_encode($labels); ?>;

        // Force numeric conversion (prevents weird string math)
        const prices = <?php echo json_encode($priceSeries); ?>.map(v => Number(v) || 0);
        const liquidity = <?php echo json_encode($liquiditySeries); ?>.map(v => Number(v) || 0);

        // solSeries contains nulls (so gaps instead of “drop to zero”)
        const solPrices = <?php echo json_encode($solSeries); ?>.map(v => (v === null ? null : (Number(v) || null)));

        const scaledPrices = prices.map(v => v * 1000000);

        if (!window.Chart) {
            console.log("Chart.js missing on this page");
            return;
        }

        // Price + Liquidity
        const ctx1 = document.getElementById('moogPriceLiquidityChart');
        if (ctx1) {
            new Chart(ctx1, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'MOOG Price × 1,000,000',
                            data: scaledPrices,
                            yAxisID: 'yPrice',
                            borderWidth: 1.5,
                            pointRadius: 0,
                            tension: 0.15
                        },
                        {
                            label: 'Liquidity (USD)',
                            data: liquidity,
                            yAxisID: 'yLiq',
                            borderWidth: 1.5,
                            pointRadius: 0,
                            tension: 0.15
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { ticks: { autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } },
                        yPrice: { position: 'left', grid: { display: false }, beginAtZero: false },
                        yLiq: { position: 'right', grid: { display: false }, beginAtZero: false }
                    },
                    plugins: { legend: { labels: { usePointStyle: true } } }
                }
            });
        }

        // SOL
        const ctx2 = document.getElementById('solPriceChart');
        if (ctx2) {
            new Chart(ctx2, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'SOL Price (USD)',
                        data: solPrices,
                        borderWidth: 1.5,
                        pointRadius: 0,
                        tension: 0.15,
                        spanGaps: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { ticks: { autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } },
                        y: {
                            grid: { display: false },
                            beginAtZero: false,
                            suggestedMin: <?php echo json_encode($solSuggestedMin); ?>,
                            suggestedMax: <?php echo json_encode($solSuggestedMax); ?>
                        }
                    },
                    plugins: { legend: { labels: { usePointStyle: true } } }
                }
            });
        }
    })();
    </script>

<?php endif; ?>
