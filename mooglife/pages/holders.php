<?php
// mooglife/pages/holders.php
// Holders list + per-wallet summary (airdrops + trades).

require __DIR__ . '/../includes/db.php';

$db = moog_db();

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit  = 200; // top 200 by balance

// Build WHERE + params
$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (h.wallet LIKE ? OR w.label LIKE ? OR w.tags LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = &$like;
    $params[] = &$like;
    $params[] = &$like;
    $types   .= 'sss';
}

// ---------------------------------------------------------------------
// Query: holders + wallet meta + airdrop summary + trade summary
// ---------------------------------------------------------------------
$sql = "
    SELECT
        h.wallet,
        h.ui_amount,
        h.percent,
        h.updated_at,
        w.label,
        w.type,
        w.tags,

        COALESCE(ad.count_airdrops, 0) AS count_airdrops,
        COALESCE(ad.total_airdrops, 0) AS total_airdrops,

        COALESCE(tx.trade_count, 0)    AS trade_count,
        tx.last_trade                  AS last_trade

    FROM mg_moog_holders h
    LEFT JOIN mg_moog_wallets w
        ON w.wallet = h.wallet

    LEFT JOIN (
        SELECT
            wallet_address AS wallet,
            COUNT(*)       AS count_airdrops,
            SUM(amount)    AS total_airdrops
        FROM moog_airdrops
        GROUP BY wallet_address
    ) ad ON ad.wallet = h.wallet

    LEFT JOIN (
        SELECT
            t.wallet,
            COUNT(*)      AS trade_count,
            MAX(t.block_time) AS last_trade
        FROM (
            SELECT from_wallet AS wallet, block_time FROM mg_moog_tx
            UNION ALL
            SELECT to_wallet   AS wallet, block_time FROM mg_moog_tx
        ) t
        GROUP BY t.wallet
    ) tx ON tx.wallet = h.wallet

    WHERE $where
    ORDER BY h.ui_amount DESC
    LIMIT $limit
";

$stmt = $db->prepare($sql);
if ($stmt === false) {
    die('SQL prepare failed: ' . htmlspecialchars($db->error));
}

if ($types !== '') {
    array_unshift($params, $types);
    call_user_func_array([$stmt, 'bind_param'], $params);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// Quick total holders count (for the card)
$totalHolders = count($rows);
?>
<h1>Holders</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Tracked Holders</div>
        <div class="card-value"><?php echo number_format($totalHolders); ?></div>
        <div class="card-sub">
            Top <?php echo $limit; ?> by balance from <code>mg_moog_holders</code>.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Search</div>
        <div class="card-sub">
            <form method="get" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="p" value="holders">
                <input
                    type="text"
                    name="q"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Wallet, label, tag..."
                    style="padding:4px 8px;border-radius:6px;border:1px solid #1f2937;
                           background:#020617;color:#e5e7eb;font-size:12px;width:220px;"
                >
                <button type="submit"
                        style="padding:4px 8px;border-radius:6px;border:none;background:#3b82f6;
                               color:#f9fafb;font-size:12px;cursor:pointer;font-size:12px;">
                    Filter
                </button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Top Holders (with activity summary)</h3>

    <table class="data">
        <thead>
            <tr>
                <th>#</th>
                <th>Label</th>
                <th>Wallet</th>
                <th>MOOG</th>
                <th>%</th>
                <th>Airdrops<br><span style="font-size:11px;">(# / total)</span></th>
                <th>Trades<br><span style="font-size:11px;">(# / last)</span></th>
                <th>Tags</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8">No holders found for this filter.</td></tr>
        <?php else: ?>
            <?php
            $i = 0;
            foreach ($rows as $r):
                $i++;
                $label   = $r['label'] ?: '(unlabeled)';
                $wallet  = $r['wallet'];
                $balance = (float)$r['ui_amount'];
                $pct     = (float)$r['percent'];

                $dropCount  = (int)$r['count_airdrops'];
                $dropTotal  = (float)$r['total_airdrops'];

                $tradeCount = (int)$r['trade_count'];
                $lastTrade  = $r['last_trade'];
            ?>
                <tr>
                    <td><?php echo $i; ?></td>

                    <td>
                        <?php
                        // Label links to wallet profile
                        echo wallet_link($wallet, $label, false);
                        ?>
                    </td>

                    <td style="font-size:11px;">
                        <?php
                        // Shortened wallet link
                        echo wallet_link($wallet, null, true);
                        ?>
                    </td>

                    <td><?php echo number_format($balance, 3); ?></td>
                    <td><?php echo number_format($pct, 6); ?>%</td>

                    <td>
                        <?php
                        echo number_format($dropCount)
                             . ' / '
                             . number_format($dropTotal);
                        ?>
                    </td>

                    <td>
                        <?php
                        echo number_format($tradeCount);
                        if ($lastTrade) {
                            echo '<br><span style="font-size:11px;">'
                               . htmlspecialchars($lastTrade)
                               . '</span>';
                        }
                        ?>
                    </td>

                    <td><?php echo htmlspecialchars($r['tags'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
