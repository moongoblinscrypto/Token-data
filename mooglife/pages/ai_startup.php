<?php
// mooglife/pages/ai_startup.php
// AI Startup Console – shows live schema + project conventions for any AI instance.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// -----------------------------------------------------------------------------
// Detect active database
// -----------------------------------------------------------------------------
$dbName = 'goblinshq';
if ($res = $db->query("SELECT DATABASE() AS dbname")) {
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['dbname'])) {
            $dbName = $row['dbname'];
        }
    }
    $res->close();
}

// -----------------------------------------------------------------------------
// Load table list from information_schema
// -----------------------------------------------------------------------------
$tables = [];
$errorMsg = '';

$stmt = $db->prepare("
    SELECT TABLE_NAME
    FROM information_schema.tables
    WHERE table_schema = ?
    ORDER BY TABLE_NAME
");
if ($stmt) {
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tables[] = $row['TABLE_NAME'];
    }
    $stmt->close();
} else {
    $errorMsg = "Failed to load tables from information_schema.tables: " . $db->error;
}

// -----------------------------------------------------------------------------
// Load columns for each table
// -----------------------------------------------------------------------------
$columns = [];

if ($errorMsg === '' && !empty($tables)) {
    $col = $db->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY,
               COLUMN_DEFAULT, EXTRA
        FROM information_schema.columns
        WHERE table_schema = ?
          AND table_name   = ?
        ORDER BY ORDINAL_POSITION
    ");

    if ($col) {
        foreach ($tables as $tbl) {
            $col->bind_param('ss', $dbName, $tbl);
            $col->execute();
            $res = $col->get_result();
            $columns[$tbl] = [];
            while ($row = $res->fetch_assoc()) {
                $columns[$tbl][] = $row;
            }
        }
        $col->close();
    } else {
        $errorMsg = "Failed to load columns: " . $db->error;
    }
}

// -----------------------------------------------------------------------------
// Helper for HTML escaping
// -----------------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------------------------------------
// AI cheatsheet text (copy-paste into AI to reboot context)
// -----------------------------------------------------------------------------
$cheatsheet = <<<TXT
Mooglife / GoblinsHQ – AI Startup Context

Routing:
- index.php loads pages via ?p=slug
- Pages live in /pages/
- Shared header/footer/navbar inside /includes/layout/
- Database helper mg_db() in /includes/db.php (with alias moog_db())

Core tables:
- mg_market_cache
- mg_moog_holders
- mg_moog_tx
- mg_moog_wallets
- mg_moog_og_buyers
- mg_moog_og_rewards
- mg_sync_log
- moog_airdrops
- system_settings
- x_posts

Conventions:
- All DB access through mg_db()
- All wallet links through wallet_link()
- All external sync logic lives under /api/
- Cron jobs live under /cron/
- New features should create new pages in /pages/ and add nav entry
TXT;
?>
<h1>AI Startup Console</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Active Database</div>
        <div class="card-value"><?php echo esc($dbName); ?></div>
        <div class="card-sub">Schema loaded live via information_schema.</div>
    </div>

    <div class="card">
        <div class="card-label">Table Count</div>
        <div class="card-value"><?php echo number_format(count($tables)); ?></div>
        <div class="card-sub">Showing MG_* + system tables.</div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Cheatsheet for AI (Copy-paste into new ChatGPT session)</h2>
    <textarea style="width:100%;min-height:250px;background:#020617;color:#e5e7eb;border:1px solid #1f2937;border-radius:6px;font-size:12px;padding:8px;"><?php echo esc($cheatsheet); ?></textarea>
</div>

<?php if ($errorMsg !== ''): ?>
<div class="card" style="border:1px solid #b91c1c;">
    <h2 style="margin-top:0;color:#f97316;">Error Loading Schema</h2>
    <p><?php echo esc($errorMsg); ?></p>
</div>
<?php else: ?>

<div class="card">
    <h2 style="margin-top:0;">Tables & Columns</h2>

    <?php foreach ($tables as $tbl): ?>
        <div style="margin-bottom:30px;">
            <h3><code><?php echo esc($tbl); ?></code></h3>

            <?php if (empty($columns[$tbl])): ?>
                <p style="font-size:12px;" class="muted">No columns returned.</p>
            <?php else: ?>
                <table class="data" style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                    <tr>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Column</th>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Type</th>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Null</th>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Key</th>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Default</th>
                        <th style="text-align:left;border-bottom:1px solid #1f2937;padding:6px;">Extra</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($columns[$tbl] as $c): ?>
                        <tr>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><code><?php echo esc($c['COLUMN_NAME']); ?></code></td>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><?php echo esc($c['DATA_TYPE']); ?></td>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><?php echo esc($c['IS_NULLABLE']); ?></td>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><?php echo esc($c['COLUMN_KEY']); ?></td>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><?php echo esc($c['COLUMN_DEFAULT']); ?></td>
                            <td style="border-bottom:1px solid #111827;padding:6px;"><?php echo esc($c['EXTRA']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>
