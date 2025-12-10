<?php
// mooglife/pages/api_usage.php
// Admin view for API usage logs.

require __DIR__ . '/../includes/auth.php';
mg_require_login();

$currentUser = mg_current_user();

require_once __DIR__ . '/../includes/db.php';
$db = mg_db();

$errors = [];
$notices = [];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Check table exists
$hasLog = false;
try {
    $res = $db->query("SHOW TABLES LIKE 'mg_api_usage_log'");
    if ($res && $res->num_rows > 0) {
        $hasLog = true;
    }
    if ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    $errors[] = 'Failed to inspect mg_api_usage_log table: ' . $e->getMessage();
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
if ($days <= 0) {
    $days = 1;
}
if ($days > 30) {
    $days = 30;
}

$sinceTs = date('Y-m-d H:i:s', time() - $days * 86400);

$filterTier    = trim((string)($_GET['tier'] ?? ''));
$filterEndpoint = trim((string)($_GET['endpoint'] ?? ''));

$summaryByTier = [];
$summaryByEndpoint = [];
$recentRows = [];

if ($hasLog) {
    // Summary by tier
    try {
        $sql = "
            SELECT tier, COUNT(*) AS cnt
            FROM mg_api_usage_log
            WHERE created_at >= ?
        ";
        $params = [$sinceTs];
        $types  = 's';

        if ($filterTier !== '') {
            $sql    .= " AND tier = ?";
            $params[] = $filterTier;
            $types   .= 's';
        }

        if ($filterEndpoint !== '') {
            $sql    .= " AND endpoint = ?";
            $params[] = $filterEndpoint;
            $types   .= 's';
        }

        $sql .= " GROUP BY tier ORDER BY cnt DESC";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $summaryByTier[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $errors[] = 'Failed to load summary by tier: ' . $e->getMessage();
    }

    // Summary by endpoint
    try {
        $sql = "
            SELECT endpoint, COUNT(*) AS cnt
            FROM mg_api_usage_log
            WHERE created_at >= ?
        ";
        $params = [$sinceTs];
        $types  = 's';

        if ($filterTier !== '') {
            $sql    .= " AND tier = ?";
            $params[] = $filterTier;
            $types   .= 's';
        }

        if ($filterEndpoint !== '') {
            $sql    .= " AND endpoint = ?";
            $params[] = $filterEndpoint;
            $types   .= 's';
        }

        $sql .= " GROUP BY endpoint ORDER BY cnt DESC LIMIT 20";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $summaryByEndpoint[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $errors[] = 'Failed to load summary by endpoint: ' . $e->getMessage();
    }

    // Recent rows
    try {
        $sql = "
            SELECT
                id,
                api_key_id,
                api_key_label,
                tier,
                endpoint,
                method,
                ip,
                created_at
            FROM mg_api_usage_log
            WHERE created_at >= ?
        ";
        $params = [$sinceTs];
        $types  = 's';

        if ($filterTier !== '') {
            $sql    .= " AND tier = ?";
            $params[] = $filterTier;
            $types   .= 's';
        }

        if ($filterEndpoint !== '') {
            $sql    .= " AND endpoint = ?";
            $params[] = $filterEndpoint;
            $types   .= 's';
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 200";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $recentRows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $errors[] = 'Failed to load recent API usage rows: ' . $e->getMessage();
    }
}
?>

<h1>API Usage</h1>
<p class="muted">
    View recent requests hitting the Moog API layer.<br>
    This helps you watch bots, partners, and any abuse once things scale.
</p>

<?php if ($errors): ?>
    <div class="card" style="border-left:4px solid #b91c1c;margin-bottom:12px;">
        <strong>Errors:</strong>
        <ul style="margin:6px 0 0 18px;font-size:13px;">
            <?php foreach ($errors as $e): ?>
                <li><?php echo h($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$hasLog): ?>
    <div class="card">
        <p class="muted" style="font-size:13px;">
            <strong>mg_api_usage_log</strong> table not found.<br>
            Create it in your database to enable usage tracking.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Filters</h2>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="p" value="api_usage">

        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Window (days)</label>
            <input type="number" name="days" class="input"
                   value="<?php echo $days; ?>"
                   min="1" max="30" style="width:80px;">
        </div>

        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Tier</label>
            <input type="text" name="tier" class="input"
                   placeholder="free / pro / internal / anonymous"
                   value="<?php echo h($filterTier); ?>"
                   style="width:180px;">
        </div>

        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Endpoint contains</label>
            <input type="text" name="endpoint" class="input"
                   placeholder="/api/holders.php"
                   value="<?php echo h($filterEndpoint); ?>"
                   style="width:220px;">
        </div>

        <div>
            <button type="submit" class="btn primary" style="margin-top:2px;">Apply</button>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Summary (last <?php echo $days; ?> day<?php echo $days === 1 ? '' : 's'; ?>)</h2>
    <div style="display:flex;flex-wrap:wrap;gap:16px;">
        <div style="flex:1;min-width:220px;">
            <h3 style="font-size:14px;margin:0 0 6px 0;">By Tier</h3>
            <?php if (!$summaryByTier): ?>
                <p class="muted" style="font-size:13px;">No requests logged yet.</p>
            <?php else: ?>
                <table class="table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Tier</th>
                            <th>Requests</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summaryByTier as $row): ?>
                        <tr>
                            <td><?php echo h($row['tier']); ?></td>
                            <td><?php echo (int)$row['cnt']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="flex:1;min-width:260px;">
            <h3 style="font-size:14px;margin:0 0 6px 0;">Top Endpoints</h3>
            <?php if (!$summaryByEndpoint): ?>
                <p class="muted" style="font-size:13px;">No requests logged yet.</p>
            <?php else: ?>
                <table class="table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Requests</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summaryByEndpoint as $row): ?>
                        <tr>
                            <td><?php echo h($row['endpoint']); ?></td>
                            <td><?php echo (int)$row['cnt']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent Requests (max 200 rows)</h2>
    <?php if (!$recentRows): ?>
        <p class="muted" style="font-size:13px;">No requests in this window.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table" style="font-size:12px;min-width:800px;">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Tier</th>
                        <th>Method</th>
                        <th>Endpoint</th>
                        <th>Key ID</th>
                        <th>Key Label</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentRows as $row): ?>
                    <tr>
                        <td><?php echo h($row['created_at']); ?></td>
                        <td><?php echo h($row['tier']); ?></td>
                        <td><?php echo h($row['method']); ?></td>
                        <td><?php echo h($row['endpoint']); ?></td>
                        <td><?php echo $row['api_key_id'] !== null ? (int)$row['api_key_id'] : ''; ?></td>
                        <td><?php echo $row['api_key_label'] !== null ? h($row['api_key_label']) : '<span class="muted">anonymous</span>'; ?></td>
                        <td><?php echo h($row['ip']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
