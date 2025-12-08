<?php
// mooglife/pages/sync.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

$flash_market  = null;
$flash_holders = null;
$flash_tx      = null;

/**
 * Call a local API via HTTP and return decoded JSON or error.
 */
function call_local_api(string $path): array {
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
            'ok'    => false,
            'error' => 'http_failed',
            'msg'   => 'Failed to call '.$url,
            'status'=> $status,
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok'    => false,
            'error' => 'bad_json',
            'msg'   => 'Invalid JSON from '.$url,
            'raw'   => $raw,
        ];
    }
    return $json;
}

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'market') {
        $res = call_local_api('api/sync_market.php');
        $flash_market = $res;
    }

    if ($do === 'holders') {
        $res = call_local_api('api/sync_holders.php');
        $flash_holders = $res;
    }

    if ($do === 'tx') {
        $res = call_local_api('api/sync_tx.php');
        $flash_tx = $res;
    }
}

// read last updated timestamps
$last_market  = null;
$last_holders = null;
$last_tx      = null;

$res = $db->query("SELECT updated_at FROM mg_market_cache ORDER BY updated_at DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_market = $row['updated_at'];
}

$res = $db->query("SELECT updated_at FROM mg_moog_holders ORDER BY updated_at DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_holders = $row['updated_at'];
}

$res = $db->query("SELECT block_time FROM mg_moog_tx ORDER BY block_time DESC LIMIT 1");
if ($res && ($row = $res->fetch_assoc())) {
    $last_tx = $row['block_time'];
}

function render_flash_box(?array $data) {
    if ($data === null) return;
    $ok = !empty($data['ok']);
    $style = $ok
        ? 'margin:10px 0;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;'
        : 'margin:10px 0;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;';
    echo '<div style="'.$style.'">';
    echo $ok ? 'Sync OK' : 'Sync FAILED';
    if (isset($data['error'])) {
        echo ' – '.htmlspecialchars((string)$data['error']);
    }
    if (isset($data['msg'])) {
        echo '<br><span style="font-size:12px;">'.htmlspecialchars((string)$data['msg']).'</span>';
    }
    if ($ok && isset($data['price_usd'])) {
        echo '<br><span style="font-size:12px;">Price: $'.number_format((float)$data['price_usd'], 10).'</span>';
    }
    if ($ok && isset($data['holders_returned'])) {
        echo '<br><span style="font-size:12px;">Holders returned: '.(int)$data['holders_returned'].'</span>';
    }
    if ($ok && isset($data['trades_returned'])) {
        echo '<br><span style="font-size:12px;">Trades returned: '.(int)$data['trades_returned'].'</span>';
    }
    echo '</div>';
}

// ---------------------------------------------------------------------
// Load recent sync log (last 50 rows)
// ---------------------------------------------------------------------
$logs = [];
$res = $db->query("SELECT id, job, ok, step, message, duration_ms, created_at FROM mg_sync_log ORDER BY id DESC LIMIT 50");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
}

function job_badge(string $job, int $ok): string {
    $bg = $ok ? '#16a34a' : '#b91c1c';
    return '<span class="pill" style="background:'.$bg.';">'
        . htmlspecialchars($job)
        . '</span>';
}
?>
<h1>Sync Center</h1>
<p class="muted">
    Trigger live sync jobs for MOOG data and see recent sync history from <code>mg_sync_log</code>.
</p>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Market Last Sync</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_market ? htmlspecialchars($last_market) : 'Never'; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Holders Last Sync</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_holders ? htmlspecialchars($last_holders) : 'Never'; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Tx History Last Sync</div>
        <div class="card-value" style="font-size:16px;">
            <?php echo $last_tx ? htmlspecialchars($last_tx) : 'Never'; ?>
        </div>
    </div>
</div>

<div style="display:flex;gap:20px;flex-wrap:wrap;">
    <!-- Market sync card -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Market (DexScreener)</h3>
        <p class="muted">
            Refresh price, FDV, liquidity, volume and SOL price in <code>mg_market_cache</code>.
        </p>
        <form method="post">
            <input type="hidden" name="do" value="market">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#3b82f6;color:#f9fafb;cursor:pointer;">
                Sync Market Now
            </button>
        </form>
        <?php render_flash_box($flash_market); ?>
    </div>

    <!-- Holders sync card -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Holders (Birdeye)</h3>
        <p class="muted">
            Fetch top 100 MOOG holders from Birdeye into <code>mg_moog_holders</code>.
        </p>
        <form method="post">
            <input type="hidden" name="do" value="holders">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#020617;cursor:pointer;">
                Sync Holders Now
            </button>
        </form>
        <?php render_flash_box($flash_holders); ?>
    </div>

    <!-- Tx sync card -->
    <div class="card" style="flex:1 1 260px;">
        <h3 style="margin-top:0;">Tx History (Birdeye)</h3>
        <p class="muted">
            Pull recent MOOG swaps into <code>mg_moog_tx</code> for the Tx History page.
        </p>
        <form method="post">
            <input type="hidden" name="do" value="tx">
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#f97316;color:#020617;cursor:pointer;">
                Sync Tx Now
            </button>
        </form>
        <?php render_flash_box($flash_tx); ?>
    </div>
</div>

<h2 style="margin-top:30px;">Recent Sync Log</h2>
<p class="muted">
    Last 50 entries from <code>mg_sync_log</code>.
</p>

<table class="data">
    <thead>
        <tr>
            <th>ID</th>
            <th>Job</th>
            <th>OK</th>
            <th>Step</th>
            <th>Message</th>
            <th>Duration (ms)</th>
            <th>When</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$logs): ?>
        <tr><td colspan="7">No sync log entries yet.</td></tr>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo (int)$log['id']; ?></td>
                <td><?php echo job_badge($log['job'], (int)$log['ok']); ?></td>
                <td><?php echo $log['ok'] ? 'YES' : 'NO'; ?></td>
                <td><?php echo htmlspecialchars((string)$log['step']); ?></td>
                <td><?php echo htmlspecialchars((string)$log['message']); ?></td>
                <td><?php echo $log['duration_ms'] !== null ? (int)$log['duration_ms'] : ''; ?></td>
                <td><?php echo htmlspecialchars((string)$log['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<p class="muted" style="margin-top:10px;font-size:12px;">
    Detailed JSON for each run is stored in <code>payload_json</code> in the DB if you ever need deep debugging.
</p>
