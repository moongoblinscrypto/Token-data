<?php
// mooglife/pages/holders.php
// Holders list + per-wallet summary (airdrops + trades).

require __DIR__ . '/../includes/db.php';

$db = mg_db();

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit  = 200; // top N by balance

// ---------------------------------------------------------------------
// Build main query
// ---------------------------------------------------------------------
//
// Tables used (all created by install.php):
// - mg_moog_holders (wallet, ui_amount, percent, ...)
// - mg_moog_wallets (wallet, label, tags, ...)
// - moog_airdrops   (wallet_address, amount, ...)
// - mg_moog_tx      (from_wallet, to_wallet, block_time, amount_moog, ...)
//
$rows = [];
$sql  = '';
$stmt = null;

if ($search !== '') {
    // Search by wallet, label, or tags
    $sql = "
        SELECT
            h.wallet,
            h.ui_amount,
            h.percent,
            w.label,
            w.tags,
            COALESCE(ad.count_airdrops, 0) AS count_airdrops,
            COALESCE(ad.total_airdrops, 0) AS total_airdrops,
            COALESCE(tx.trade_count, 0)    AS trade_count,
            tx.last_trade
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
                COUNT(*)         AS trade_count,
                MAX(t.block_time) AS last_trade
            FROM (
                SELECT from_wallet AS wallet, block_time
                FROM mg_moog_tx
                UNION ALL
                SELECT to_wallet   AS wallet, block_time
                FROM mg_moog_tx
            ) t
            GROUP BY t.wallet
        ) tx ON tx.wallet = h.wallet
        WHERE
            (h.wallet LIKE ?
             OR w.label LIKE ?
             OR w.tags  LIKE ?)
        ORDER BY h.ui_amount DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die('SQL prepare failed: ' . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8'));
    }

    $like = '%' . $search . '%';
    $stmt->bind_param('sssi', $like, $like, $like, $limit);
} else {
    // No search, just top N by balance
    $sql = "
        SELECT
            h.wallet,
            h.ui_amount,
            h.percent,
            w.label,
            w.tags,
            COALESCE(ad.count_airdrops, 0) AS count_airdrops,
            COALESCE(ad.total_airdrops, 0) AS total_airdrops,
            COALESCE(tx.trade_count, 0)    AS trade_count,
            tx.last_trade
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
                COUNT(*)         AS trade_count,
                MAX(t.block_time) AS last_trade
            FROM (
                SELECT from_wallet AS wallet, block_time
                FROM mg_moog_tx
                UNION ALL
                SELECT to_wallet   AS wallet, block_time
                FROM mg_moog_tx
            ) t
            GROUP BY t.wallet
        ) tx ON tx.wallet = h.wallet
        ORDER BY h.ui_amount DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        die('SQL prepare failed: ' . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8'));
    }

    $stmt->bind_param('i', $limit);
}

// Execute + fetch
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// Simple count of holders (just what we loaded)
$totalHolders = count($rows);

// Helper for HTML escape
function esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<h1>Holders</h1>

<div class="cards" style="margin-bottom:20px;">
    <div class="card">
        <div class="card-label">Tracked Holders</div>
        <div class="card-value">
            <?php echo number_format($totalHolders); ?>
        </div>
        <div class="card-sub">
            Top <?php echo (int)$limit; ?> by balance from <code>mg_moog_holders</code>.
        </div>
    </div>

    <div class="card">
        <div class="card-label">Search</div>
        <div class="card-sub">
            Filter by wallet, label, or tags (using LIKE).
        </div>
        <form method="get" class="search-row" style="margin-top:8px;">
            <input type="hidden" name="p" value="holders">
            <input
                type="text"
                name="q"
                placeholder="Search wallet, label, tags..."
                value="<?php echo esc($search); ?>"
                style="width:100%;max-width:260px;padding:6px 8px;border-radius:6px;border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
            >
        </form>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Top Holders</h2>

    <?php if ($totalHolders === 0): ?>
        <p>No holder data found. Run a holders sync from the Sync page.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">#</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Wallet</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Label</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Balance (MOOG)</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">%</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Airdrops</th>
                    <th style="text-align:right;padding:6px;border-bottom:1px solid #1f2937;">Trades</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Tags</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $i = 0;
                foreach ($rows as $r):
                    $i++;
                    $wallet       = $r['wallet'];
                    $label        = $r['label'] ?? '';
                    $tags         = $r['tags'] ?? '';
                    $balance      = (float)($r['ui_amount'] ?? 0);
                    $percent      = (float)($r['percent'] ?? 0);
                    $dropCount    = (int)($r['count_airdrops'] ?? 0);
                    $dropTotal    = (float)($r['total_airdrops'] ?? 0);
                    $tradeCount   = (int)($r['trade_count'] ?? 0);
                    $lastTradeRaw = $r['last_trade'] ?? null;

                    $lastTradeStr = '';
                    if ($lastTradeRaw) {
                        $ts = strtotime($lastTradeRaw);
                        if ($ts !== false) {
                            $lastTradeStr = date('Y-m-d H:i', $ts);
                        } else {
                            $lastTradeStr = $lastTradeRaw;
                        }
                    }
                ?>
                    <tr>
                        <td style="padding:6px;border-bottom:1px solid #111827;"><?php echo $i; ?></td>
                        <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                            <?php echo wallet_link($wallet, $label !== '' ? $label : null, true); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($label); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format($balance, 3); ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php echo number_format($percent, 4); ?>%
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php
                            if ($dropCount > 0) {
                                echo number_format($dropCount) . '×';
                                echo '<br><span style="font-size:11px;">'
                                   . number_format($dropTotal, 0)
                                   . ' MOOG</span>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <?php
                            if ($tradeCount > 0) {
                                echo number_format($tradeCount);
                                if ($lastTradeStr !== '') {
                                    echo '<br><span style="font-size:11px;">'
                                       . esc($lastTradeStr)
                                       . '</span>';
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">
                            <?php echo esc($tags); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
