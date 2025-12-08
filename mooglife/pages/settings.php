<?php
// mooglife/pages/settings.php
require __DIR__ . '/../includes/db.php';
$db = moog_db();

/**
 * Helper functions for settings
 */
function get_setting($db, $key, $default = '') {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    if ($stmt->fetch()) {
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return $default;
}

function save_setting($db, $key, $value) {
    // try update first
    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    if (!$stmt) return;
    $stmt->bind_param('ss', $value, $key);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        // insert if not exist
        $stmt2 = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        if ($stmt2) {
            $stmt2->bind_param('ss', $key, $value);
            $stmt2->execute();
            $stmt2->close();
        }
    } else {
        $stmt->close();
    }
}

$save_ok = false;
$error_msg = '';

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_name       = trim($_POST['site_name'] ?? '');
    $token_symbol    = trim($_POST['token_symbol'] ?? '');
    $token_mint      = trim($_POST['token_mint'] ?? '');
    $token_decimals  = trim($_POST['token_decimals'] ?? '');
    $birdeye_api_key = trim($_POST['birdeye_api_key'] ?? '');
    $sync_enabled    = isset($_POST['sync_enabled']) ? '1' : '0';

    // simple validation
    if ($token_symbol === '' || $token_mint === '') {
        $error_msg = 'Token symbol and mint are required.';
    } else {
        save_setting($db, 'site_name',        $site_name);
        save_setting($db, 'token_symbol',     $token_symbol);
        save_setting($db, 'token_mint',       $token_mint);
        save_setting($db, 'token_decimals',   $token_decimals);
        save_setting($db, 'birdeye_api_key',  $birdeye_api_key);
        save_setting($db, 'sync_enabled',     $sync_enabled);
        $save_ok = true;
    }
}

// load current values
$site_name       = get_setting($db, 'site_name', 'Mooglife / GoblinsHQ');
$token_symbol    = get_setting($db, 'token_symbol', 'MOOG');
$token_mint      = get_setting($db, 'token_mint', '');
$token_decimals  = get_setting($db, 'token_decimals', '9');
$birdeye_api_key = get_setting($db, 'birdeye_api_key', '');
$sync_enabled    = get_setting($db, 'sync_enabled', '1') === '1';
?>
<h1>Settings</h1>
<p class="muted">
    Core config for Mooglife & upcoming Birdeye sync. Values are stored in <code>system_settings</code>.
</p>

<?php if ($save_ok): ?>
    <div style="margin:10px 0;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;">
        Settings saved.
    </div>
<?php elseif ($error_msg): ?>
    <div style="margin:10px 0;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;">
        <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<form method="post" style="max-width:600px;">
    <div style="margin-bottom:12px;">
        <label>Site Name</label><br>
        <input type="text" name="site_name"
               value="<?php echo htmlspecialchars($site_name); ?>"
               style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
    </div>

    <h3>Token Info</h3>
    <div style="margin-bottom:8px;">
        <label>Token Symbol</label><br>
        <input type="text" name="token_symbol"
               value="<?php echo htmlspecialchars($token_symbol); ?>"
               style="width:200px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
    </div>
    <div style="margin-bottom:8px;">
        <label>Token Mint (Solana)</label><br>
        <input type="text" name="token_mint"
               value="<?php echo htmlspecialchars($token_mint); ?>"
               style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
    </div>
    <div style="margin-bottom:12px;">
        <label>Token Decimals</label><br>
        <input type="number" name="token_decimals"
               value="<?php echo htmlspecialchars($token_decimals); ?>"
               style="width:120px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
    </div>

    <h3>Birdeye API</h3>
    <div style="margin-bottom:8px;">
        <label>Birdeye API Key</label><br>
        <input type="password" name="birdeye_api_key"
               value="<?php echo htmlspecialchars($birdeye_api_key); ?>"
               style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;">
        <div class="muted">
            This will be used by sync scripts to query price, volume, holders, etc.
        </div>
    </div>

    <div style="margin-bottom:16px;">
        <label>
            <input type="checkbox" name="sync_enabled" value="1"
                   <?php if ($sync_enabled) echo 'checked'; ?>>
            Enable automatic sync (Birdeye)
        </label>
    </div>

    <button type="submit"
            style="padding:8px 14px;border-radius:6px;border:none;background:#22c55e;color:#020617;cursor:pointer;">
        Save Settings
    </button>
</form>
