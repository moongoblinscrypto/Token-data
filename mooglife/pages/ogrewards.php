<?php
// mooglife/pages/ogrewards.php
// OG Rewards Control Panel: plan and track OG payouts.

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ml_dt($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('Y-m-d H:i', $ts);
}

function og_status_clean($status) {
    $status = strtoupper(trim((string)$status));
    $allowed = ['PENDING', 'SENT', 'FAILED', 'CANCELLED'];
    if (!in_array($status, $allowed, true)) {
        return 'PENDING';
    }
    return $status;
}

// ---------------------------------------------------------------------
// Make sure base tables exist
// ---------------------------------------------------------------------
$has_og_buyers  = false;
$has_og_rewards = false;
$has_wallets    = false;

$res = $db->query("SHOW TABLES LIKE 'mg_moog_og_buyers'");
if ($res && $res->num_rows > 0) $has_og_buyers = true;
if ($res) $res->close();

$res = $db->query("SHOW TABLES LIKE 'mg_moog_og_rewards'");
if ($res && $res->num_rows > 0) $has_og_rewards = true;
if ($res) $res->close();

$res = $db->query("SHOW TABLES LIKE 'mg_moog_wallets'");
if ($res && $res->num_rows > 0) $has_wallets = true;
if ($res) $res->close();

if (!$has_og_buyers) {
    ?>
    <h1>OG Rewards</h1>
    <div class="card">
        <p><code>mg_moog_og_buyers</code> table not found.</p>
        <p class="muted" style="font-size:13px;">
            Run your OG snapshot script first so we have OG buyers to work with.
        </p>
    </div>
    <?php
    return;
}

// If rewards table missing, we can still show OG list, but no edit.
if (!$has_og_rewards) {
    ?>
    <h1>OG Rewards</h1>
    <div class="card">
        <p><code>mg_moog_og_rewards</code> table not found.</p>
        <p class="muted" style="font-size:13px;">
            According to db-structure it should contain:
            wallet, planned_amount, tx_hash, status, notes, created_at, updated_at.
        </p>
    </div>
    <?php
    return;
}

// ---------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------
$flash = null;

// ---------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add/update reward plan for one wallet
    if ($action === 'save_reward') {
        $wallet = trim($_POST['wallet'] ?? '');
        $wallet = substr($wallet, 0, 64);

        $planned_amount = trim($_POST['planned_amount'] ?? '');
        $status         = og_status_clean($_POST['status'] ?? 'PENDING');
        $tx_hash        = trim($_POST['tx_hash'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        if ($wallet === '') {
            $flash = ['ok' => false, 'msg' => 'Missing wallet for reward.'];
        } else {
            if ($planned_amount === '' || !is_numeric($planned_amount)) {
                $planned_amount = '0';
            }

            $stmt = $db->prepare("
                INSERT INTO mg_moog_og_rewards (wallet, planned_amount, tx_hash, status, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    planned_amount = VALUES(planned_amount),
                    tx_hash        = VALUES(tx_hash),
                    status         = VALUES(status),
                    notes          = VALUES(notes)
            ");
            if (!$stmt) {
                $flash = ['ok' => false, 'msg' => 'DB error (save): ' . $db->error];
            } else {
                // treat decimal as string (MySQL will cast)
                $stmt->bind_param('sssss', $wallet, $planned_amount, $tx_hash, $status, $notes);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Reward for wallet saved.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Save failed: ' . $stmt->error];
                }
                $stmt->close();
            }
        }
    }

    // Delete reward row for a wallet
    if ($action === 'delete_reward') {
        $wallet = trim($_POST['wallet'] ?? '');
        $wallet = substr($wallet, 0, 64);

        if ($wallet === '') {
            $flash = ['ok' => false, 'msg' => 'Missing wallet for delete.'];
        } else {
            $stmt = $db->prepare("DELETE FROM mg_moog_og_rewards WHERE wallet = ? LIMIT 1");
            if (!$stmt) {
                $flash = ['ok' => false, 'msg' => 'DB error (delete): ' . $db->error];
            } else {
                $stmt->bind_param('s', $wallet);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Reward row deleted for wallet.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Delete failed: ' . $stmt->error];
                }
                $stmt->close();
            }
        }
    }

    // Quick mark as SENT (optional separate action)
    if ($action === 'mark_sent') {
        $wallet = trim($_POST['wallet'] ?? '');
        $wallet = substr($wallet, 0, 64);
        $tx_hash = trim($_POST['tx_hash'] ?? '');

        if ($wallet === '') {
            $flash = ['ok' => false, 'msg' => 'Missing wallet for mark-sent.'];
        } else {
            $stmt = $db->prepare("
                INSERT INTO mg_moog_og_rewards (wallet, planned_amount, tx_hash, status, notes)
                VALUES (?, 0, ?, 'SENT', '')
                ON DUPLICATE KEY UPDATE
                    status  = 'SENT',
                    tx_hash = VALUES(tx_hash)
            ");
            if (!$stmt) {
                $flash = ['ok' => false, 'msg' => 'DB error (mark-sent): ' . $db->error];
            } else {
                $stmt->bind_param('ss', $wallet, $tx_hash);
                if ($stmt->execute()) {
                    $flash = ['ok' => true, 'msg' => 'Reward marked as SENT for wallet.'];
                } else {
                    $flash = ['ok' => false, 'msg' => 'Mark-sent failed: ' . $stmt->error];
                }
                $stmt->close();
            }
        }
    }
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q          = isset($_GET['q']) ? trim($_GET['q']) : '';
$tierFilter = isset($_GET['tier']) ? trim($_GET['tier']) : '';
$eligFilter = isset($_GET['elig']) ? trim($_GET['elig']) : 'all'; // all, eligible, ineligible
$statusFilter = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : 'ALL'; // ALL / PENDING / SENT / FAILED / CANCELLED / NONE
$limit      = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}

// Distinct tiers
$tiers = [];
$res = $db->query("SELECT DISTINCT og_tier FROM mg_moog_og_buyers ORDER BY og_tier ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $tiers[] = (int)$row['og_tier'];
    }
    $res->close();
}

// ---------------------------------------------------------------------
// Summary cards
// ---------------------------------------------------------------------
$summary = [
    'total_og'      => 0,
    'eligible_og'   => 0,
    'rewards_count' => 0,
    'rewards_sum'   => 0.0,
    'sent_count'    => 0,
    'sent_sum'      => 0.0,
];

$res = $db->query("
    SELECT
        COUNT(*) AS total_og,
        SUM(CASE WHEN is_eligible = 1 THEN 1 ELSE 0 END) AS eligible_og
    FROM mg_moog_og_buyers
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['total_og']    = (int)$row['total_og'];
    $summary['eligible_og'] = (int)$row['eligible_og'];
    $res->close();
}

$res = $db->query("
    SELECT
        COUNT(*) AS c,
        COALESCE(SUM(planned_amount),0) AS s
    FROM mg_moog_og_rewards
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['rewards_count'] = (int)$row['c'];
    $summary['rewards_sum']   = (float)$row['s'];
    $res->close();
}

$res = $db->query("
    SELECT
        COUNT(*) AS c,
        COALESCE(SUM(planned_amount),0) AS s
    FROM mg_moog_og_rewards
    WHERE status = 'SENT'
");
if ($res && ($row = $res->fetch_assoc())) {
    $summary['sent_count'] = (int)$row['c'];
    $summary['sent_sum']   = (float)$row['s'];
    $res->close();
}

// ---------------------------------------------------------------------
// Build WHERE for main OG list
// ---------------------------------------------------------------------
$where  = '1=1';
$bind   = '';
$params = [];

// search by wallet, label, label_tags
if ($q !== '') {
    $where .= ' AND (ob.wallet LIKE ? OR w.label LIKE ? OR ob.label_tags LIKE ?)';
    $like = '%' . $q . '%';
    $bind .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// tier filter
if ($tierFilter !== '' && $tierFilter !== 'all') {
    $tierInt = (int)$tierFilter;
    if ($tierInt > 0) {
        $where .= ' AND ob.og_tier = ?';
        $bind  .= 'i';
        $params[] = $tierInt;
    }
}

// eligibility filter
if ($eligFilter === 'eligible') {
    $where .= ' AND ob.is_eligible = 1';
} elseif ($eligFilter === 'ineligible') {
    $where .= ' AND ob.is_eligible = 0';
}

// status filter
// We have LEFT JOIN mg_moog_og_rewards r
if ($statusFilter === 'NONE') {
    $where .= ' AND r.status IS NULL';
} elseif ($statusFilter !== 'ALL' && $statusFilter !== '') {
    $where .= ' AND r.status = ?';
    $bind  .= 's';
    $params[] = $statusFilter;
}

// ---------------------------------------------------------------------
// Main query: OG buyers + wallets + rewards
// ---------------------------------------------------------------------
$sql = "
    SELECT
        ob.wallet,
        ob.first_buy_time,
        ob.first_buy_amount,
        ob.total_bought,
        ob.total_sold,
        ob.current_balance,
        ob.buy_tx_count,
        ob.sell_tx_count,
        ob.is_eligible,
        ob.og_tier,
        ob.label_tags,
        ob.exclude_reason,
        ob.notes       AS og_notes,
        ob.snapshot_at,

        w.label        AS wallet_label,
        w.type         AS wallet_type,

        r.id           AS reward_id,
        r.planned_amount,
        r.tx_hash,
        r.status       AS reward_status,
        r.notes        AS reward_notes,
        r.created_at   AS reward_created_at,
        r.updated_at   AS reward_updated_at
    FROM mg_moog_og_buyers ob
    LEFT JOIN mg_moog_wallets   w ON w.wallet = ob.wallet
    LEFT JOIN mg_moog_og_rewards r ON r.wallet = ob.wallet
    WHERE {$where}
    ORDER BY ob.og_tier ASC, ob.is_eligible DESC, ob.first_buy_time ASC
    LIMIT ?
";

$bind .= 'i';
$params[] = $limit;

$stmt = $db->prepare($sql);
if (!$stmt) {
    die('SQL prepare failed: ' . esc($db->error));
}

$bindParams   = [];
$bindParams[] = &$bind;
foreach ($params as $k => $v) {
    $bindParams[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bindParams);

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$totalRows = count($rows);

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------
?>
<h1>OG Rewards</h1>
<p class="muted">
    Plan and track OG reward payouts from <code>mg_moog_og_rewards</code>, joined with OG snapshot and wallet labels.
</p>

<?php if ($flash !== null): ?>
    <div style="margin-bottom:12px;padding:8px 10px;border-radius:6px;
        background:<?php echo $flash['ok'] ? '#022c22' : '#450a0a'; ?>;
        color:<?php echo $flash['ok'] ? '#bbf7d0' : '#fecaca'; ?>;
        font-size:13px;">
        <?php echo esc($flash['msg']); ?>
    </div>
<?php endif; ?>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Total OG Wallets</div>
        <div class="card-value"><?php echo number_format($summary['total_og']); ?></div>
        <div class="card-sub">Rows in <code>mg_moog_og_buyers</code>.</div>
    </div>

    <div class="card">
        <div class="card-label">Eligible OGs</div>
        <div class="card-value"><?php echo number_format($summary['eligible_og']); ?></div>
        <div class="card-sub"><code>is_eligible = 1</code>.</div>
    </div>

    <div class="card">
        <div class="card-label">Rewards Planned</div>
        <div class="card-value"><?php echo number_format($summary['rewards_count']); ?></div>
        <div class="card-sub">
            Total planned: <?php echo number_format($summary['rewards_sum'], 0); ?> MOOG
        </div>
    </div>

    <div class="card">
        <div class="card-label">Rewards Sent</div>
        <div class="card-value"><?php echo number_format($summary['sent_count']); ?></div>
        <div class="card-sub">
            Total sent: <?php echo number_format($summary['sent_sum'], 0); ?> MOOG
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Filters</h2>
    <form method="get">
        <input type="hidden" name="p" value="ogrewards">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Search</label>
                <input
                    type="text"
                    name="q"
                    value="<?php echo esc($q); ?>"
                    placeholder="Wallet, label, label tags..."
                    style="width:220px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Tier</label>
                <select
                    name="tier"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="all">All</option>
                    <?php foreach ($tiers as $t): ?>
                        <option value="<?php echo (int)$t; ?>" <?php if ((string)$t === $tierFilter) echo 'selected'; ?>>
                            Tier <?php echo (int)$t; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Eligibility</label>
                <select
                    name="elig"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="all"       <?php if ($eligFilter === 'all') echo 'selected'; ?>>All</option>
                    <option value="eligible"  <?php if ($eligFilter === 'eligible') echo 'selected'; ?>>Eligible</option>
                    <option value="ineligible"<?php if ($eligFilter === 'ineligible') echo 'selected'; ?>>Ineligible</option>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Reward Status</label>
                <select
                    name="status"
                    style="padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <?php
                    $statuses = ['ALL','PENDING','SENT','FAILED','CANCELLED','NONE'];
                    foreach ($statuses as $s):
                    ?>
                        <option value="<?php echo $s; ?>" <?php if ($statusFilter === $s) echo 'selected'; ?>>
                            <?php echo $s; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="font-size:12px;display:block;margin-bottom:2px;">Limit</label>
                <input
                    type="number"
                    name="limit"
                    value="<?php echo esc($limit); ?>"
                    min="50"
                    max="1000"
                    step="50"
                    style="width:90px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div>
                <button type="submit"
                        style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;color:#022c22;font-weight:600;cursor:pointer;">
                    Apply
                </button>
            </div>

        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">OG Reward List</h2>
    <p class="muted" style="font-size:12px;margin-bottom:6px;">
        Loaded <?php echo number_format($totalRows); ?> OG wallets.  
        Each row lets you set a reward amount, status, tx hash, and notes, then save/update.
    </p>

    <div style="overflow-x:auto;">
        <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
            <tr>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Wallet / Label</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tier / Eligible</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">OG Stats</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Reward Plan</th>
                <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="5" style="padding:8px;border-bottom:1px solid #111827;">
                        No OG buyers match these filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $isEligible  = ((int)$r['is_eligible'] === 1);
                    $rewardId    = $r['reward_id'];
                    $rewardStat  = $r['reward_status'] ?? '';
                    $rewardPlanned = $r['planned_amount'] ?? null;
                    $rewardNotes   = $r['reward_notes'] ?? '';
                    $rewardTx      = $r['tx_hash'] ?? '';
                    ?>
                    <tr>
                        <!-- Wallet / Label -->
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;vertical-align:top;">
                            <div>
                                <?php echo wallet_link($r['wallet'], $r['wallet_label'] ?: null, true); ?>
                            </div>
                            <?php if ($r['wallet_label'] !== '' || $r['wallet_type'] !== ''): ?>
                                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                                    <?php if ($r['wallet_label'] !== ''): ?>
                                        <span><?php echo esc($r['wallet_label']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['wallet_type'] !== ''): ?>
                                        &nbsp;·&nbsp;<span><?php echo esc($r['wallet_type']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($r['label_tags'] !== ''): ?>
                                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                                    Tags: <?php echo esc($r['label_tags']); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Tier / Eligible -->
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;vertical-align:top;">
                            <div>
                                Tier <?php echo (int)$r['og_tier']; ?>
                                <?php if ($isEligible): ?>
                                    <span class="pill" style="margin-left:4px;">ELIGIBLE</span>
                                <?php else: ?>
                                    <span class="pill" style="margin-left:4px;background:#4b5563;">NOT ELIGIBLE</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['exclude_reason'] !== ''): ?>
                                <div style="font-size:11px;color:#f97316;margin-top:2px;">
                                    Exclude: <?php echo esc($r['exclude_reason']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($r['snapshot_at']): ?>
                                <div style="font-size:10px;color:#9ca3af;margin-top:4px;">
                                    Snapshot: <?php echo esc(ml_dt($r['snapshot_at'])); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- OG stats -->
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;vertical-align:top;">
                            <div>
                                Current: <?php echo number_format((float)$r['current_balance'], 3); ?> MOOG
                            </div>
                            <div>
                                Total Bought: <?php echo number_format((float)$r['total_bought'], 3); ?> MOOG
                            </div>
                            <div>
                                Total Sold: <?php echo number_format((float)$r['total_sold'], 3); ?> MOOG
                            </div>
                            <div>
                                Buys: <?php echo (int)$r['buy_tx_count']; ?> ·
                                Sells: <?php echo (int)$r['sell_tx_count']; ?>
                            </div>
                            <?php if ($r['og_notes'] !== ''): ?>
                                <div style="margin-top:4px;color:#9ca3af;">
                                    Notes: <?php echo esc($r['og_notes']); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Reward Plan -->
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;vertical-align:top;">
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="save_reward">
                                <input type="hidden" name="wallet" value="<?php echo esc($r['wallet']); ?>">

                                <div style="margin-bottom:4px;">
                                    <label style="font-size:11px;display:block;margin-bottom:1px;">Planned Amount (MOOG)</label>
                                    <input
                                        type="text"
                                        name="planned_amount"
                                        value="<?php echo $rewardPlanned !== null ? esc($rewardPlanned) : ''; ?>"
                                        placeholder="0"
                                        style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                                    >
                                </div>

                                <div style="margin-bottom:4px;">
                                    <label style="font-size:11px;display:block;margin-bottom:1px;">Status</label>
                                    <select
                                        name="status"
                                        style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;font-size:11px;"
                                    >
                                        <?php
                                        $slist = ['PENDING','SENT','FAILED','CANCELLED'];
                                        $curS  = $rewardStat ?: 'PENDING';
                                        foreach ($slist as $s):
                                        ?>
                                            <option value="<?php echo $s; ?>" <?php if ($curS === $s) echo 'selected'; ?>>
                                                <?php echo $s; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div style="margin-bottom:4px;">
                                    <label style="font-size:11px;display:block;margin-bottom:1px;">Tx Hash</label>
                                    <input
                                        type="text"
                                        name="tx_hash"
                                        value="<?php echo esc($rewardTx); ?>"
                                        placeholder="optional"
                                        style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                                    >
                                </div>

                                <div style="margin-bottom:4px;">
                                    <label style="font-size:11px;display:block;margin-bottom:1px;">Notes</label>
                                    <input
                                        type="text"
                                        name="notes"
                                        value="<?php echo esc($rewardNotes); ?>"
                                        placeholder="internal note"
                                        style="width:100%;padding:4px 6px;border-radius:4px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                                    >
                                </div>

                                <?php if ($rewardId): ?>
                                    <div style="font-size:10px;color:#9ca3af;margin-bottom:4px;">
                                        Reward row #<?php echo (int)$rewardId; ?><br>
                                        Created: <?php echo esc(ml_dt($r['reward_created_at'])); ?><br>
                                        Updated: <?php echo esc(ml_dt($r['reward_updated_at'])); ?>
                                    </div>
                                <?php endif; ?>

                                <button type="submit"
                                        style="padding:4px 8px;border-radius:4px;border:none;background:#3b82f6;color:#f9fafb;font-size:11px;font-weight:600;cursor:pointer;">
                                    Save
                                </button>
                            </form>
                        </td>

                        <!-- Actions -->
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;vertical-align:top;">
                            <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                <a href="?p=wallet&amp;wallet=<?php echo esc($r['wallet']); ?>"
                                   style="padding:3px 8px;border-radius:4px;background:#22c55e;color:#022c22;text-decoration:none;font-size:11px;">
                                    Wallet
                                </a>
                                <a href="?p=ogbuyers&amp;q=<?php echo esc($r['wallet']); ?>"
                                   style="padding:3px 8px;border-radius:4px;background:#0ea5e9;color:#e0f2fe;text-decoration:none;font-size:11px;">
                                    OG Record
                                </a>

                                <form method="post" style="margin:0;" onsubmit="return confirm('Mark reward as SENT for this wallet?');">
                                    <input type="hidden" name="action" value="mark_sent">
                                    <input type="hidden" name="wallet" value="<?php echo esc($r['wallet']); ?>">
                                    <input type="hidden" name="tx_hash" value="<?php echo esc($rewardTx); ?>">
                                    <button type="submit"
                                            style="padding:3px 8px;border-radius:4px;border:none;background:#16a34a;color:#ecfdf5;font-size:11px;cursor:pointer;">
                                        Mark Sent
                                    </button>
                                </form>

                                <?php if ($rewardId): ?>
                                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete reward row for this wallet?');">
                                        <input type="hidden" name="action" value="delete_reward">
                                        <input type="hidden" name="wallet" value="<?php echo esc($r['wallet']); ?>">
                                        <button type="submit"
                                                style="padding:3px 8px;border-radius:4px;border:none;background:#b91c1c;color:#fee2e2;font-size:11px;cursor:pointer;">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
