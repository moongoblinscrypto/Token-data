<?php
// mooglife/pages/sync.php
// Sync Center – run market/holders/tx sync and see recent logs.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

$flash_market  = null;
$flash_holders = null;
$flash_tx      = null;

// ---------------------------------------------------------------------
// Helper: HTTP call to local API and decode JSON
// ---------------------------------------------------------------------
function ml_call_local_api(string $path): array
{
    // For local WAMP; update this when moving to VPS if needed.
    $base = 'http://localhost/mooglife';
    $url  = rtrim($base, '/') . '/' . ltrim($path, '/');

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 60,
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        $status = isset($http_response_header[0]) ? $http_response_header[0] : 'no status';
        return [
            'ok'     => false,
            'error'  => 'http_failed',
            'msg'    => 'Failed to call ' . $url,
            'status' => $status,
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok'    => false,
            'error' => 'bad_json',
            'msg'   => 'Invalid JSON from ' . $url,
            'raw'   => $raw,
        ];
    }

    return $json;
}

// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'market') {
        $flash_market = ml_call_local_api('api/sync_market.php');
    }

    if ($do === 'holders') {
        $flash_holders = ml_call_local_api('api/sync_holders.php');
    }

    if ($do === 'tx') {
        $flash_tx = ml_call_local_api('api/sync_tx.php');
    }
}

// ---------------------------------------------------------------------
// Last updated timestamps
// ---------------------------------------------------------------------
$last_market  = null;
$last_holders = null;
$last_tx      = null;

// mg_market_cache.updated_at
$res = $db->query("SELECT updated_at FROM mg_market_cache ORDER BY updated_at DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_market = $row['updated_at'];
}

// mg_moog_holders.updated_at
$res = $db->query("SELECT updated_at FROM mg_moog_holders ORDER BY updated_at DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_holders = $row['updated_at'];
}

// mg_moog_tx.block_time
$res = $db->query("SELECT block_time FROM mg_moog_tx ORDER BY block_time DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_tx = $row['block_time'];
}

// ---------------------------------------------------------------------
// Load recent sync log entries
// ---------------------------------------------------------------------
$logs = [];
$res = $db->query("
    SELECT id, job, ok, step, message, duration_ms, created_at
    FROM mg_sync_log
    ORDER BY id DESC
    LIMIT 50
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ml_render_flash_box(?array $data): void
{
    if ($data === null) {
        return;
    }

    $ok    = !empty($data['ok']);
    $style = $ok
        ? 'margin:10px 0;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;'
        : 'margin:10px 0;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;';

    echo '<div style="' . $style . '">';
    echo $ok ? 'Sync OK' : 'Sync FAILED';

    if (isset($data['error'])) {
        echo ' – ' . esc($data['error']);
    }
    if (isset($data['msg'])) {
        echo '<br><span style="font-size:12px;">' . esc($data['msg']) . '</span>';
    }

    // Optional extras from API
    if ($ok && isset($data['price_usd'])) {
        echo '<br><span style="font-size:12px;">Price: $' . number_format((float)$data['price_usd'], 10) . '</span>';
    }
    if ($ok && isset($data['holders_returned'])) {
        echo '<br><span style="font-size:12px;">Holders returned: ' . (int)$data['holders_returned'] . '</span>';
    }
    if ($ok && isset($data['trades_returned'])) {
        echo '<br><span style="font-size:12px;">Trades returned: ' . (int)$data['trades_returned'] . '</span>';
    }

    echo '</div>';
}

function ml_job_badge(string $job, int $ok): string
{
    $label = $job;
    $color = '#1f2937';

    if ($job === 'market') {
        $color = '#0ea5e9';
    } elseif ($job === 'holders') {
        $color = '#22c55e';
    } elseif ($job === 'tx') {
        $color = '#f97316';
    } elseif ($job === 'cron_all') {
        $color = '#a855f7';
    }

    $statusColor = $ok ? '#16a34a' : '#b91c1c';

    return '<span class="pill" style="background:' . $color . ';margin-right:4px;">'
         . esc($label)
         . '</span>'
         . '<span class="pill" style="background:' . $statusColor . ';font-size:11px;">'
         . ($ok ? 'OK' : 'FAIL')
         . '</span>';
}
?>
<h1>Sync Center</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Market Last Update</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_market ? esc($last_market) : 'Never'; ?>
        </div>
        <div class="card-sub">From <code>mg_market_cache.updated_at</code></div>
    </div>

    <div class="card">
        <div class="card-label">Holders Last Update</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_holders ? esc($last_holders) : 'Never'; ?>
        </div>
        <div class="card-sub">From <code>mg_moog_holders.updated_at</code></div>
    </div>

    <div class="card">
        <div class="card-label">Tx History Last Update</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_tx ? esc($last_tx) : 'Never'; ?>
        </div>
        <div class="card-sub">From <code>mg_moog_tx.block_time</code></div>
    </div>
</div>

<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">

    <!-- Market sync -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Market (DexScreener)</h3>
        <p class="muted" style="font-size:13px;">
            Refresh price, FDV, liquidity, volume and SOL price in <code>mg_market_cache</code>.
        </p>
        <form method="post">
            <input type="hidden" name="do" value="market">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#3b82f6;color:#f9fafb;cursor:pointer;">
                Sync Market Now
            </button>
        </form>
        <?php ml_render_flash_box($flash_market); ?>
    </div>

    <!-- Holders sync -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Holders (Birdeye)</h3>
        <p class="muted" style="font-size:13px;">
            Refresh top MOOG holders into <code>mg_moog_holders</code>.
            Uses Birdeye holder API (limit 100 per request).
        </p>
        <form method="post">
            <input type="hidden" name="do" value="holders">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;cursor:pointer;">
                Sync Holders Now
            </button>
        </form>
        <?php ml_render_flash_box($flash_holders); ?>
    </div>

    <!-- Tx sync -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Tx History (Birdeye)</h3>
        <p class="muted" style="font-size:13px;">
            Refresh recent MOOG swap history into <code>mg_moog_tx</code>.
            Built to fail gracefully if Birdeye rate-limits.
        </p>
        <form method="post">
            <input type="hidden" name="do" value="tx">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#f97316;color:#111827;cursor:pointer;">
                Sync Tx Now
            </button>
        </form>
        <?php ml_render_flash_box($flash_tx); ?>
    </div>

</div>

<div class="card">
    <h2 style="margin-top:0;">Recent Sync Log</h2>
    <p class="muted" style="font-size:12px;margin-bottom:10px;">
        Showing last 50 entries from <code>mg_sync_log</code>. Detailed JSON payloads are stored in
        <code>payload_json</code> if you need deep debugging.
    </p>

    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">ID</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Job / Status</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Step</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Message</th>
                <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Duration (ms)</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">When</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr>
                    <td colspan="6" style="padding:8px;border-bottom:1px solid #111827;">
                        No sync log entries yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo (int)$log['id']; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo ml_job_badge((string)$log['job'], (int)$log['ok']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($log['step']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;max-width:320px;">
                            <?php echo esc($log['message']); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo $log['duration_ms'] !== null ? (int)$log['duration_ms'] : ''; ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($log['created_at']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
