<?php
// mooglife/pages/settings.php
// Central settings for Mooglife (token + API + sync flags).

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Fetch a setting by key.
 */
function get_setting(mysqli $db, string $key, string $default = ''): string {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    if ($stmt->fetch()) {
        $stmt->close();
        return (string)$val;
    }
    $stmt->close();
    return $default;
}

/**
 * Insert or update a setting.
 */
function save_setting(mysqli $db, string $key, string $value): bool {
    $stmt = $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ---------------------------------------------------------
// Ensure system_settings exists
// ---------------------------------------------------------
$check = $db->query("SHOW TABLES LIKE 'system_settings'");
if (!$check || $check->num_rows === 0) {
    if ($check) $check->close();
    ?>
    <h1>Settings</h1>
    <div class="card">
        <p><code>system_settings</code> table not found.</p>
        <p class="muted" style="font-size:13px;">
            According to <code>db-structure</code> it should be:
        </p>
        <pre style="background:#020617;border-radius:6px;padding:8px;font-size:12px;overflow:auto;">
CREATE TABLE system_settings (
  id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key  VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  UNIQUE KEY uk_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        </pre>
        <p class="muted" style="font-size:12px;">
            Create this table, then reload this page.
        </p>
    </div>
    <?php
    return;
}
$check->close();

// ---------------------------------------------------------
// Handle POST (save settings)
// ---------------------------------------------------------
$error_msg   = '';
$success_msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $site_name        = trim($_POST['site_name'] ?? '');
    $token_symbol     = trim($_POST['token_symbol'] ?? '');
    $token_mint       = trim($_POST['token_mint'] ?? '');
    $token_decimals   = trim($_POST['token_decimals'] ?? '');
    $birdeye_api_key  = trim($_POST['birdeye_api_key'] ?? '');
    $sync_enabled     = isset($_POST['sync_enabled']) ? '1' : '0';
    $raydium_pool     = trim($_POST['raydium_pool'] ?? '');
    $dexscreener_pair = trim($_POST['dexscreener_pair'] ?? '');
    $timezone         = trim($_POST['timezone'] ?? '');

    if ($token_symbol === '' || $token_mint === '') {
        $error_msg = 'Token symbol and mint are required.';
    } else {
        // basic sanity for decimals
        if ($token_decimals === '' || !ctype_digit($token_decimals)) {
            $token_decimals = '9';
        }

        save_setting($db, 'site_name',         $site_name);
        save_setting($db, 'token_symbol',      $token_symbol);
        save_setting($db, 'token_mint',        $token_mint);
        save_setting($db, 'token_decimals',    $token_decimals);
        save_setting($db, 'birdeye_api_key',   $birdeye_api_key);
        save_setting($db, 'sync_enabled',      $sync_enabled);
        save_setting($db, 'raydium_pool',      $raydium_pool);
        save_setting($db, 'dexscreener_pair',  $dexscreener_pair);
        save_setting($db, 'timezone',          $timezone);

        $success_msg = 'Settings saved.';
    }
}

// ---------------------------------------------------------
// Load current values
// ---------------------------------------------------------
$site_name        = get_setting($db, 'site_name',        'Mooglife Command Center');
$token_symbol     = get_setting($db, 'token_symbol',     'MOOG');
$token_mint       = get_setting($db, 'token_mint',       '');
$token_decimals   = get_setting($db, 'token_decimals',   '9');
$birdeye_api_key  = get_setting($db, 'birdeye_api_key',  '');
$sync_enabled_raw = get_setting($db, 'sync_enabled',     '1');
$sync_enabled     = ($sync_enabled_raw === '1');

$raydium_pool     = get_setting($db, 'raydium_pool',     '');
$dexscreener_pair = get_setting($db, 'dexscreener_pair', '');
$timezone         = get_setting($db, 'timezone',         'America/Chicago');

// also load all raw settings for debug view
$raw_settings = [];
$res = $db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $raw_settings[] = $row;
    }
    $res->close();
}

?>
<h1>Settings</h1>
<p class="muted">
    Global config for Mooglife. These values should be used by all sync jobs, dashboards, and future upgrades.
</p>

<?php if ($error_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;font-size:13px;">
        <?php echo esc($error_msg); ?>
    </div>
<?php elseif ($success_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;font-size:13px;">
        <?php echo esc($success_msg); ?>
    </div>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:20px;">

    <div class="card" style="flex:1 1 420px;min-width:320px;">
        <h2 style="margin-top:0;">Core Settings</h2>

        <form method="post">
            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Site Name</label>
                <input
                    type="text"
                    name="site_name"
                    value="<?php echo esc($site_name); ?>"
                    placeholder="Mooglife / GoblinsHQ"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Token Symbol *</label>
                <input
                    type="text"
                    name="token_symbol"
                    value="<?php echo esc($token_symbol); ?>"
                    placeholder="MOOG"
                    style="width:100%;max-width:200px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Token Mint Address *</label>
                <input
                    type="text"
                    name="token_mint"
                    value="<?php echo esc($token_mint); ?>"
                    placeholder="Solana mint for MOOG"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Token Decimals</label>
                <input
                    type="number"
                    name="token_decimals"
                    value="<?php echo esc($token_decimals); ?>"
                    min="0"
                    max="18"
                    style="width:100%;max-width:120px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                <p class="muted" style="font-size:11px;margin-top:2px;">
                    MOOG is usually 9 on Solana.
                </p>
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Birdeye API Key</label>
                <input
                    type="text"
                    name="birdeye_api_key"
                    value="<?php echo esc($birdeye_api_key); ?>"
                    placeholder="Your Birdeye API token"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                <p class="muted" style="font-size:11px;margin-top:2px;">
                    Used by sync_market, sync_holders, and sync_tx jobs.
                </p>
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Raydium Pool / Pair Address</label>
                <input
                    type="text"
                    name="raydium_pool"
                    value="<?php echo esc($raydium_pool); ?>"
                    placeholder="Raydium AMM pool address"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Dexscreener Pair ID</label>
                <input
                    type="text"
                    name="dexscreener_pair"
                    value="<?php echo esc($dexscreener_pair); ?>"
                    placeholder="dexscreener.com pair ID"
                    style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:10px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Timezone</label>
                <input
                    type="text"
                    name="timezone"
                    value="<?php echo esc($timezone); ?>"
                    placeholder="America/Chicago"
                    style="width:100%;max-width:220px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                <p class="muted" style="font-size:11px;margin-top:2px;">
                    For cron display and charts (PHP <code>date_default_timezone_set</code> can use this).
                </p>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:12px;">
                    <input type="checkbox" name="sync_enabled" value="1"
                           <?php if ($sync_enabled) echo 'checked'; ?>>
                    <span style="margin-left:4px;">Enable automatic sync (cron jobs / Birdeye)</span>
                </label>
            </div>

            <button type="submit"
                    style="padding:8px 14px;border-radius:6px;border:none;background:#22c55e;color:#020617;font-weight:600;cursor:pointer;">
                Save Settings
            </button>
        </form>
    </div>

    <div class="card" style="flex:1 1 320px;min-width:280px;">
        <h2 style="margin-top:0;">Current Settings (Raw)</h2>
        <p class="muted" style="font-size:12px;margin-bottom:6px;">
            Quick debug view of everything in <code>system_settings</code>.
        </p>
        <div style="max-height:420px;overflow:auto;font-size:12px;">
            <?php if (!$raw_settings): ?>
                <p class="muted">No settings stored yet.</p>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                    <tr>
                        <th style="text-align:left;padding:4px;border-bottom:1px solid #1f2937;">Key</th>
                        <th style="text-align:left;padding:4px;border-bottom:1px solid #1f2937;">Value</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($raw_settings as $row): ?>
                        <tr>
                            <td style="padding:4px;border-bottom:1px solid #111827;">
                                <code><?php echo esc($row['setting_key']); ?></code>
                            </td>
                            <td style="padding:4px;border-bottom:1px solid #111827;word-break:break-all;">
                                <?php
                                $val = (string)$row['setting_value'];
                                if ($row['setting_key'] === 'birdeye_api_key' && $val !== '') {
                                    // mask API key
                                    $mask = substr($val, 0, 4) . '****' . substr($val, -4);
                                    echo esc($mask);
                                } else {
                                    echo nl2br(esc($val));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>
