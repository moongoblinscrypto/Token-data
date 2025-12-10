<?php
// mooglife/pages/api_keys.php
// Admin UI for managing Moog API keys.

require __DIR__ . '/../includes/auth.php';
mg_require_login();

$currentUser = mg_current_user();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
    ?>
    <h1>API Keys</h1>
    <div class="card">
        <p class="muted">You must be an <strong>admin</strong> to manage API keys.</p>
    </div>
    <?php
    return;
}

require_once __DIR__ . '/../includes/db.php';
$db = mg_db();

$errors  = [];
$notices = [];

// Small helper
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $action = trim($action);

    // CREATE NEW KEY
    if ($action === 'create') {
        $label       = trim($_POST['label'] ?? '');
        $tier        = trim($_POST['tier'] ?? 'free');
        $dailyLimit  = trim($_POST['daily_limit'] ?? '');
        $allowedIps  = trim($_POST['allowed_ips'] ?? '');

        if ($label === '') {
            $errors[] = 'Label is required.';
        }

        if (!in_array($tier, ['free', 'pro', 'internal'], true)) {
            $errors[] = 'Invalid tier selected.';
        }

        $dailyLimitValue = null;
        if ($dailyLimit !== '') {
            if (!ctype_digit($dailyLimit)) {
                $errors[] = 'Daily limit must be a positive integer or empty.';
            } else {
                $dailyLimitValue = (int)$dailyLimit;
            }
        }

        if (!$errors) {
            // Generate API key
            try {
                $bytes  = random_bytes(24);
                $apiKey = 'MOOG-' . bin2hex($bytes);
            } catch (Throwable $e) {
                $apiKey = 'MOOG-' . bin2hex(random_bytes(16));
            }

            $ownerId = isset($currentUser['id']) ? (int)$currentUser['id'] : null;

            $sql = "
                INSERT INTO mg_api_keys
                    (api_key, label, owner_user_id, tier, status,
                     daily_limit, allowed_ips, requests_today, day_window_start, created_at, last_used_at)
                VALUES
                    (?, ?, ?, ?, 'active',
                     ?, ?, 0, NULL, NOW(), NULL)
            ";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $errors[] = 'DB error preparing insert: ' . $db->error;
            } else {
                // daily_limit is nullable
                if ($dailyLimitValue === null) {
                    $stmt->bind_param(
                        'ssisis',
                        $apiKey,
                        $label,
                        $ownerId,
                        $tier,
                        $dailyLimitValue,
                        $allowedIps
                    );
                } else {
                    $stmt->bind_param(
                        'ssisis',
                        $apiKey,
                        $label,
                        $ownerId,
                        $tier,
                        $dailyLimitValue,
                        $allowedIps
                    );
                }

                if ($stmt->execute()) {
                    $notices[] = 'API key created successfully.';
                } else {
                    $errors[] = 'Failed to create API key: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }

    // TOGGLE STATUS (active <-> disabled)
    if ($action === 'toggle' && empty($errors)) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $sql = "SELECT status FROM mg_api_keys WHERE id = ? LIMIT 1";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc() ?: null;
                $stmt->close();

                if ($row) {
                    $currentStatus = strtolower((string)$row['status']);
                    $newStatus = ($currentStatus === 'active') ? 'disabled' : 'active';

                    $up = "UPDATE mg_api_keys SET status = ? WHERE id = ? LIMIT 1";
                    $stmt2 = $db->prepare($up);
                    if ($stmt2) {
                        $stmt2->bind_param('si', $newStatus, $id);
                        if ($stmt2->execute()) {
                            $notices[] = 'API key status updated.';
                        } else {
                            $errors[] = 'Failed to update status: ' . $stmt2->error;
                        }
                        $stmt2->close();
                    } else {
                        $errors[] = 'DB error preparing status update.';
                    }
                } else {
                    $errors[] = 'API key not found.';
                }
            } else {
                $errors[] = 'DB error preparing status lookup.';
            }
        } else {
            $errors[] = 'Invalid key ID.';
        }
    }

    // RESET USAGE
    if ($action === 'reset_usage' && empty($errors)) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $sql = "
                UPDATE mg_api_keys
                SET requests_today = 0,
                    day_window_start = NULL
                WHERE id = ?
                LIMIT 1
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $notices[] = 'Usage counters reset for this API key.';
                } else {
                    $errors[] = 'Failed to reset usage: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors[] = 'DB error preparing reset.';
            }
        } else {
            $errors[] = 'Invalid key ID.';
        }
    }
}

// Fetch all API keys
$keys = [];
try {
    $sql = "
        SELECT
            k.*,
            u.username AS owner_username
        FROM mg_api_keys AS k
        LEFT JOIN mg_admin_users AS u
          ON k.owner_user_id = u.id
        ORDER BY k.created_at DESC, k.id DESC
    ";
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $keys[] = $row;
        }
        $res->close();
    }
} catch (Throwable $e) {
    $errors[] = 'Failed to load API keys: ' . $e->getMessage();
}
?>

<h1>API Keys</h1>
<p class="muted">
    Manage Moog API keys, tiers, limits, and usage.<br>
    Keys can be tied to admin users and rate-limited per day.
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

<?php if ($notices): ?>
    <div class="card" style="border-left:4px solid #16a34a;margin-bottom:12px;">
        <strong>OK:</strong>
        <ul style="margin:6px 0 0 18px;font-size:13px;">
            <?php foreach ($notices as $n): ?>
                <li><?php echo h($n); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Create New API Key</h2>
    <form method="post" style="margin-top:10px;">
        <input type="hidden" name="action" value="create">

        <div class="form-row">
            <label for="label" style="display:block;font-size:13px;margin-bottom:4px;">Label</label>
            <input type="text" id="label" name="label" class="input" required
                   placeholder="e.g. Internal Bot, Public Widget, Partner #1">
        </div>

        <div class="form-row" style="margin-top:10px;">
            <label for="tier" style="display:block;font-size:13px;margin-bottom:4px;">Tier</label>
            <select id="tier" name="tier" class="input">
                <option value="free">free (small limits)</option>
                <option value="pro">pro (higher limits)</option>
                <option value="internal">internal (unlimited)</option>
            </select>
        </div>

        <div class="form-row" style="margin-top:10px;">
            <label for="daily_limit" style="display:block;font-size:13px;margin-bottom:4px;">
                Daily Limit (optional)
            </label>
            <input type="text" id="daily_limit" name="daily_limit" class="input"
                   placeholder="leave blank to use default per tier">
            <div class="muted" style="font-size:11px;margin-top:2px;">
                Defaults: free ≈ 1,000 / day, pro ≈ 50,000 / day, internal = unlimited.
            </div>
        </div>

        <div class="form-row" style="margin-top:10px;">
            <label for="allowed_ips" style="display:block;font-size:13px;margin-bottom:4px;">
                Allowed IPs (optional)
            </label>
            <input type="text" id="allowed_ips" name="allowed_ips" class="input"
                   placeholder="e.g. 1.2.3.4, 5.6.7.8">
            <div class="muted" style="font-size:11px;margin-top:2px;">
                Leave blank to allow any IP. Otherwise, comma-separated list.
            </div>
        </div>

        <div style="margin-top:14px;">
            <button type="submit" class="btn primary">Generate API Key</button>
        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">Existing Keys</h2>
    <?php if (!$keys): ?>
        <p class="muted" style="font-size:13px;">No API keys have been created yet.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table" style="width:100%;font-size:12px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Label</th>
                        <th>API Key</th>
                        <th>Owner</th>
                        <th>Tier</th>
                        <th>Status</th>
                        <th>Daily Limit</th>
                        <th>Today</th>
                        <th>Last Used</th>
                        <th>Created</th>
                        <th>Allowed IPs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                    <?php
                        $id          = (int)$k['id'];
                        $label       = $k['label'] ?? '';
                        $apiKey      = $k['api_key'] ?? '';
                        $tier        = strtolower((string)($k['tier'] ?? 'free'));
                        $status      = strtolower((string)($k['status'] ?? 'disabled'));
                        $dailyLimit  = $k['daily_limit'];
                        $reqsToday   = (int)($k['requests_today'] ?? 0);
                        $ownerName   = $k['owner_username'] ?? '';
                        $createdAt   = $k['created_at'] ?? '';
                        $lastUsed    = $k['last_used_at'] ?? '';
                        $allowedIps  = $k['allowed_ips'] ?? '';
                        $dayWindow   = $k['day_window_start'] ?? '';

                        $statusLabel = ($status === 'active') ? 'Active' : 'Disabled';
                        $statusColor = ($status === 'active') ? '#16a34a' : '#9ca3af';

                        $tierLabel = ucfirst($tier);
                        if ($tier === 'internal') {
                            $tierLabel = 'Internal';
                        }

                        $limitLabel = 'default';
                        if ($dailyLimit !== null && $dailyLimit !== '') {
                            $limitLabel = (string)$dailyLimit;
                        } elseif ($tier === 'internal') {
                            $limitLabel = 'unlimited';
                        }

                        $shortKey = (strlen($apiKey) > 18)
                            ? substr($apiKey, 0, 8) . '…' . substr($apiKey, -6)
                            : $apiKey;
                    ?>
                    <tr>
                        <td><?php echo $id; ?></td>
                        <td><?php echo h($label); ?></td>
                        <td>
                            <code title="<?php echo h($apiKey); ?>">
                                <?php echo h($shortKey); ?>
                            </code>
                        </td>
                        <td><?php echo $ownerName ? h($ownerName) : '<span class="muted">–</span>'; ?></td>
                        <td><?php echo h($tierLabel); ?></td>
                        <td>
                            <span style="color:<?php echo $statusColor; ?>;">
                                <?php echo h($statusLabel); ?>
                            </span>
                        </td>
                        <td><?php echo h($limitLabel); ?></td>
                        <td>
                            <?php echo $reqsToday; ?>
                            <?php if ($dayWindow): ?>
                                <span class="muted" style="display:block;font-size:10px;">
                                    day: <?php echo h($dayWindow); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $lastUsed ? h($lastUsed) : '<span class="muted">never</span>'; ?></td>
                        <td><?php echo h($createdAt); ?></td>
                        <td style="max-width:180px;">
                            <span style="font-size:11px;">
                                <?php echo $allowedIps !== '' ? h($allowedIps) : '<span class="muted">any</span>'; ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn secondary" style="padding:2px 6px;font-size:11px;">
                                    <?php echo ($status === 'active') ? 'Disable' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="post" style="display:inline;margin-left:4px;">
                                <input type="hidden" name="action" value="reset_usage">
                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <button type="submit" class="btn secondary" style="padding:2px 6px;font-size:11px;">
                                    Reset
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
