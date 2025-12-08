<?php
// mooglife/pages/wallet.php
// Full Goblin Profile for a single wallet, safe even if some tables don't exist.

require __DIR__ . '/../includes/db.php';

$db = moog_db();

/**
 * Check if a table exists in the current DB.
 */
function mg_table_exists(mysqli $db, string $name): bool {
    $name = $db->real_escape_string($name);
    $res  = $db->query("SHOW TABLES LIKE '{$name}'");
    if (!$res) return false;
    $ok = $res->num_rows > 0;
    $res->free();
    return $ok;
}

function short_addr(string $w): string {
    return substr($w, 0, 4) . '...' . substr($w, -4);
}

// ---------------------------------------------------------------------
// Wallet param
// ---------------------------------------------------------------------
$wallet = isset($_GET['wallet']) ? trim($_GET['wallet']) : '';
if ($wallet === '') {
    ?>
    <h1>Wallet Profile</h1>
    <div class="card">
        No wallet selected. Use the <strong>Jump to wallet...</strong> box
        in the sidebar or click any wallet link in the tables.
    </div>
    <?php
    return;
}

// ---------------------------------------------------------------------
// 1) Holder + label info
// ---------------------------------------------------------------------
$holder        = null;
$label         = null;
$type          = null;
$tags          = null;
$balance       = 0.0;
$percent       = 0.0;
$holderUpdated = null;

if (mg_table_exists($db, 'mg_moog_holders')) {
    $sql = "
        SELECT h.ui_amount, h.percent, h.updated_at,
               w.label, w.type, w.tags
        FROM mg_moog_holders h
        LEFT JOIN mg_moog_wallets w ON w.wallet = h.wallet
        WHERE h.wallet = ?
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $holder        = $row;
            $balance       = (float)$row['ui_amount'];
            $percent       = (float)$row['percent'];
            $label         = $row['label'] ?? null;
            $type          = $row['type']  ?? null;
            $tags          = $row['tags']  ?? null;
            $holderUpdated = $row['updated_at'] ?? null;
        }
        $stmt->close();
    }
}

// Rank among holders
$rank = null;
if ($balance > 0 && mg_table_exists($db, 'mg_moog_holders')) {
    $stmt = $db->prepare("
        SELECT COUNT(*) + 1 AS r
        FROM mg_moog_holders
        WHERE ui_amount > ?
    ");
    if ($stmt) {
        $stmt->bind_param('d', $balance);
        $stmt->execute();
        $stmt->bind_result($r);
        if ($stmt->fetch()) {
            $rank = (int)$r;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 2) Airdrop summary (moog_airdrops if it exists)
// ---------------------------------------------------------------------
$airdropCount = 0;
$airdropTotal = 0.0;
$hasAirdrops  = mg_table_exists($db, 'moog_airdrops');

if ($hasAirdrops) {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
        FROM moog_airdrops
        WHERE wallet_address = ?
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $stmt->bind_result($c, $s);
        if ($stmt->fetch()) {
            $airdropCount = (int)$c;
            $airdropTotal = (float)$s;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 3) Trade / tx summary from mg_moog_tx
// ---------------------------------------------------------------------
$txCount   = 0;
$firstSeen = null;
$lastSeen  = null;
$buyTotal  = 0.0;
$sellTotal = 0.0;
$xferTotal = 0.0;
$hasTxTable = mg_table_exists($db, 'mg_moog_tx');

if ($hasTxTable) {
    // first/last / count
    $stmt = $db->prepare("
        SELECT
            COUNT(*)        AS c,
            MIN(block_time) AS first_seen,
            MAX(block_time) AS last_seen
        FROM mg_moog_tx
        WHERE from_wallet = ? OR to_wallet = ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $wallet, $wallet);
        $stmt->execute();
        $stmt->bind_result($c, $fs, $ls);
        if ($stmt->fetch()) {
            $txCount   = (int)$c;
            $firstSeen = $fs;
            $lastSeen  = $ls;
        }
        $stmt->close();
    }

    // sums by direction
    $stmt = $db->prepare("
        SELECT direction, COALESCE(SUM(amount_moog),0) AS amt
        FROM mg_moog_tx
        WHERE from_wallet = ? OR to_wallet = ?
        GROUP BY direction
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $wallet, $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dir = strtoupper($row['direction'] ?? '');
            $amt = (float)$row['amt'];
            if ($dir === 'BUY') {
                $buyTotal += $amt;
            } elseif ($dir === 'SELL') {
                $sellTotal += $amt;
            } else {
                $xferTotal += $amt;
            }
        }
        $stmt->close();
    }
}

$netFlow = $buyTotal - $sellTotal;

// ---------------------------------------------------------------------
// 4) OG status (mg_og_buyers + mg_og_rewards) – all optional
// ---------------------------------------------------------------------
$isOgBuyer     = false;
$ogBuySize     = 0.0;
$ogRewardCount = 0;
$ogRewardTotal = 0.0;

$hasOgBuyers  = mg_table_exists($db, 'mg_og_buyers');
$hasOgRewards = mg_table_exists($db, 'mg_og_rewards');

if ($hasOgBuyers) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount_moog),0) AS amt
        FROM mg_og_buyers
        WHERE wallet = ?
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $stmt->bind_result($amt);
        if ($stmt->fetch()) {
            $ogBuySize = (float)$amt;
            if ($ogBuySize > 0) {
                $isOgBuyer = true;
            }
        }
        $stmt->close();
    }
}

if ($hasOgRewards) {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c, COALESCE(SUM(amount_moog),0) AS s
        FROM mg_og_rewards
        WHERE wallet = ?
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $stmt->bind_result($c, $s);
        if ($stmt->fetch()) {
            $ogRewardCount = (int)$c;
            $ogRewardTotal = (float)$s;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 5) Goblin score + badges
// ---------------------------------------------------------------------
$score = 0;

// 1 point per 1M MOOG
$score += (int)round($balance / 1_000_000);

// activity
$score += $txCount * 2;
$score += $airdropCount * 5;

// OG
if ($isOgBuyer) {
    $score += 100;
}

// big holder bonus
if ($percent > 0.5) {
    $score += 250;
} elseif ($percent > 0.1) {
    $score += 100;
}

$tier = 'Goblin Tier';
if ($percent > 1) {
    $tier = 'Whale Tier';
} elseif ($percent > 0.1) {
    $tier = 'Shark Tier';
}

$activityBadge = 'Quiet Goblin';
if ($txCount >= 50) {
    $activityBadge = 'War Goblin';
} elseif ($txCount >= 10) {
    $activityBadge = 'Active Goblin';
}

// ---------------------------------------------------------------------
// 6) Detail tables
// ---------------------------------------------------------------------

// Recent tx – NOTE: no price_usd column here
$recentTx = [];
if ($hasTxTable) {
    $stmt = $db->prepare("
        SELECT block_time, tx_hash, direction, amount_moog,
               from_wallet, to_wallet
        FROM mg_moog_tx
        WHERE from_wallet = ? OR to_wallet = ?
        ORDER BY block_time DESC
        LIMIT 25
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $wallet, $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recentTx[] = $row;
        }
        $stmt->close();
    }
}

// Airdrops
$recentDrops = [];
if ($hasAirdrops) {
    $stmt = $db->prepare("
        SELECT id, amount, source, notes, tx_hash, created_at
        FROM moog_airdrops
        WHERE wallet_address = ?
        ORDER BY created_at DESC
        LIMIT 25
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recentDrops[] = $row;
        }
        $stmt->close();
    }
}

// OG rewards rows
$recentRewards = [];
if ($hasOgRewards) {
    $stmt = $db->prepare("
        SELECT id, amount_moog, status, tx_hash, created_at, updated_at
        FROM mg_og_rewards
        WHERE wallet = ?
        ORDER BY created_at DESC
        LIMIT 25
    ");
    if ($stmt) {
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recentRewards[] = $row;
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------
// 7) Render
// ---------------------------------------------------------------------
$displayLabel = $label ?: short_addr($wallet);
?>

<style>
.wallet-badges {
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:4px;
}
.badge {
    display:inline-block;
    padding:2px 6px;
    border-radius:999px;
    font-size:11px;
    line-height:1.4;
}
.badge-tier    { background:#1f2937; color:#e5e7eb; border:1px solid #4b5563; }
.badge-og      { background:#0f766e; color:#d1fae5; }
.badge-active  { background:#1d4ed8; color:#dbeafe; }
.badge-score   { background:#581c87; color:#e9d5ff; }
.profile-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
    gap:12px;
}
</style>

<h1>Wallet Profile</h1>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin:0;"><?php echo htmlspecialchars($displayLabel); ?></h2>
    <div style="font-size:12px;color:#9ca3af;margin-top:4px;">
        <?php echo htmlspecialchars($wallet); ?>
        <?php if ($holderUpdated): ?>
            &middot; Snapshot updated <?php echo htmlspecialchars($holderUpdated); ?>
        <?php endif; ?>
    </div>

    <div class="wallet-badges">
        <span class="badge badge-tier"><?php echo htmlspecialchars($tier); ?></span>
        <span class="badge badge-active"><?php echo htmlspecialchars($activityBadge); ?></span>
        <?php if ($isOgBuyer): ?>
            <span class="badge badge-og">OG Buyer</span>
        <?php endif; ?>
        <span class="badge badge-score">Goblin Score: <?php echo number_format($score); ?></span>
    </div>

    <?php if ($tags): ?>
        <div style="margin-top:6px;font-size:11px;color:#9ca3af;">
            Tags: <?php echo htmlspecialchars($tags); ?>
        </div>
    <?php endif; ?>
</div>

<div class="cards" style="margin-bottom:16px;">
    <div class="card">
        <div class="card-label">Balance (MOOG)</div>
        <div class="card-value"><?php echo number_format($balance, 3); ?></div>
        <div class="card-sub">
            <?php echo number_format($percent, 6); ?>% of tracked supply
            <?php if ($rank !== null): ?>
                &middot; Rank #<?php echo number_format($rank); ?>
            <?php else: ?>
                <?php if ($balance <= 0): ?>
                    &middot; Not in current top snapshot
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Activity</div>
        <div class="card-value"><?php echo number_format($txCount); ?> tx</div>
        <div class="card-sub">
            <?php if ($firstSeen): ?>
                First: <?php echo htmlspecialchars($firstSeen); ?><br>
            <?php endif; ?>
            <?php if ($lastSeen): ?>
                Last: <?php echo htmlspecialchars($lastSeen); ?>
            <?php else: ?>
                No on-chain swaps recorded yet.
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Airdrops</div>
        <div class="card-value">
            <?php echo number_format($airdropCount); ?> /
            <?php echo number_format($airdropTotal); ?>
        </div>
        <div class="card-sub">
            Count / total MOOG airdropped
            <?php if (!$hasAirdrops): ?>
                (airdrop table not created yet)
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-label">Net MOOG Flow</div>
        <div class="card-value">
            <?php echo number_format($netFlow, 3); ?>
        </div>
        <div class="card-sub">
            Buys: <?php echo number_format($buyTotal, 3); ?> &middot;
            Sells: <?php echo number_format($sellTotal, 3); ?>
        </div>
    </div>
</div>

<div class="profile-grid" style="margin-bottom:16px;">
    <div class="card">
        <h3 style="margin-top:0;">Recent MOOG Swaps</h3>
        <table class="data">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Dir</th>
                    <th>Amount</th>
                    <th>Counterparty</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recentTx): ?>
                <tr><td colspan="4">No swaps recorded for this wallet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentTx as $tx): ?>
                    <?php
                        $dir = strtoupper($tx['direction'] ?? '');
                        $counter = ($tx['from_wallet'] === $wallet)
                            ? $tx['to_wallet']
                            : $tx['from_wallet'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tx['block_time']); ?></td>
                        <td><?php echo htmlspecialchars($dir ?: '?'); ?></td>
                        <td><?php echo number_format((float)$tx['amount_moog'], 3); ?></td>
                        <td>
                            <?php
                            if ($counter) {
                                echo wallet_link($counter, short_addr($counter), true);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Airdrops to this Wallet</h3>
        <table class="data">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Amount</th>
                    <th>Source</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$hasAirdrops): ?>
                <tr><td colspan="4">Airdrop table not created yet.</td></tr>
            <?php elseif (!$recentDrops): ?>
                <tr><td colspan="4">No airdrops recorded for this wallet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentDrops as $d): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($d['created_at']); ?></td>
                        <td><?php echo number_format((float)$d['amount'], 3); ?></td>
                        <td><?php echo htmlspecialchars($d['source'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($d['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">OG Rewards</h3>
        <table class="data">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>Tx Hash</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$hasOgRewards): ?>
                <tr><td colspan="4">OG rewards table not created yet.</td></tr>
            <?php elseif (!$recentRewards): ?>
                <tr><td colspan="4">No OG rewards for this wallet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentRewards as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['status'] ?? ''); ?></td>
                        <td><?php echo number_format((float)$r['amount_moog'], 3); ?></td>
                        <td>
                            <?php
                            $h = $r['tx_hash'] ?? '';
                            echo $h ? htmlspecialchars(short_addr($h)) : '-';
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['updated_at'] ?? $r['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
