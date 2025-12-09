<?php
// mooglife/pages/wallet.php
// Wallet Manager / Holder lookup (ranks by top bag).

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';

mg_require_login();
$db = mg_db();

// ------------------------------------------------------
// Get requested wallet (from GET or POST)
// ------------------------------------------------------
$searchWallet = trim($_GET['wallet'] ?? ($_POST['wallet'] ?? ''));
$searchWallet = preg_replace('/\s+/', '', $searchWallet); // strip spaces/newlines

$selected = null;
$errorMsg = '';

// ------------------------------------------------------
// Load single wallet profile with dynamic rank
// (rank by ui_amount DESC for all holders)
// ------------------------------------------------------
if ($searchWallet !== '') {
    try {
        $sql = "
            SELECT ranked.wallet,
                   ranked.ui_amount,
                   ranked.percent,
                   ranked.rn AS `rank`
            FROM (
                SELECT h.wallet,
                       h.ui_amount,
                       h.percent,
                       (@r := @r + 1) AS rn
                FROM mg_moog_holders AS h
                JOIN (SELECT @r := 0) AS vars
                ORDER BY h.ui_amount DESC
            ) AS ranked
            WHERE ranked.wallet = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $searchWallet);
            $stmt->execute();
            $res = $stmt->get_result();
            $selected = $res->fetch_assoc() ?: null;
            $stmt->close();
        } else {
            $errorMsg = 'DB error (prepare holder lookup): ' . $db->error;
        }
    } catch (Throwable $e) {
        $errorMsg = 'Error loading wallet: ' . $e->getMessage();
    }

    if (!$selected && !$errorMsg) {
        $errorMsg = 'Wallet not found in current holders list.';
    }
}

// ------------------------------------------------------
// Load Top 100 holders with dynamic rank (by bag size)
// ------------------------------------------------------
$holders = [];
try {
    $sqlTop = "
        SELECT ranked.wallet,
               ranked.ui_amount,
               ranked.percent,
               ranked.rn AS `rank`
        FROM (
            SELECT h.wallet,
                   h.ui_amount,
                   h.percent,
                   (@r2 := @r2 + 1) AS rn
            FROM mg_moog_holders AS h
            JOIN (SELECT @r2 := 0) AS vars2
            ORDER BY h.ui_amount DESC
        ) AS ranked
        ORDER BY ranked.rn ASC
        LIMIT 100
    ";
    if ($res = $db->query($sqlTop)) {
        while ($row = $res->fetch_assoc()) {
            $holders[] = $row;
        }
        $res->close();
    }
} catch (Throwable $e) {
    $errorMsg = $errorMsg ?: ('Error loading holders: ' . $e->getMessage());
}
?>
<h1>Wallet Manager</h1>
<p class="muted">
    Search any known MOOG holder wallet, or pick from the top 100 list below.
    Other pages can deep-link here with <code>?p=wallet&amp;wallet=ADDRESS</code>.
    Ranks are calculated live by top bag (highest MOOG balance).
</p>

<div class="card" style="margin-bottom:20px;max-width:720px;">
    <h2 style="margin-top:0;">Find Wallet</h2>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="hidden" name="p" value="wallet">
        <div style="flex:1 1 260px;">
            <label style="font-size:12px;display:block;margin-bottom:2px;">Wallet address</label>
            <input
                type="text"
                name="wallet"
                value="<?php echo htmlspecialchars($searchWallet, ENT_QUOTES, 'UTF-8'); ?>"
                style="width:100%;padding:6px 8px;border-radius:6px;
                       border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                placeholder="Paste Solana wallet..."
            >
        </div>
        <div>
            <label style="font-size:12px;display:block;margin-bottom:2px;">&nbsp;</label>
            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;
                           color:#020617;font-weight:600;cursor:pointer;">
                Load Wallet
            </button>
        </div>
    </form>
    <p class="muted" style="font-size:11px;margin-top:6px;">
        Hint: click any wallet in the Top Holders table to jump straight to its profile.
    </p>
</div>

<?php if ($errorMsg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;font-size:13px;">
        <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($selected): ?>
    <?php
        $w    = $selected['wallet'];
        $ui   = (float)$selected['ui_amount'];
        $pct  = (float)$selected['percent'];
        $rank = (int)$selected['rank'];
    ?>
    <div class="card" style="margin-bottom:20px;">
        <h2 style="margin-top:0;">Wallet Profile</h2>

        <div style="margin-bottom:10px;">
            <div style="font-size:12px;color:#9ca3af;margin-bottom:2px;">Wallet</div>
            <div style="font-size:14px;">
                <?php echo wallet_link($w, null, false); ?>
            </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px;">
            <div>
                <div class="card-label">Rank (by bag)</div>
                <div class="card-value" style="font-size:18px;"><?php echo $rank; ?></div>
            </div>
            <div>
                <div class="card-label">Balance (MOOG)</div>
                <div class="card-value" style="font-size:18px;">
                    <?php echo number_format($ui, 3); ?>
                </div>
            </div>
            <div>
                <div class="card-label">% of Supply (tracked)</div>
            <div class="card-value" style="font-size:18px;">
                    <?php echo number_format($pct, 6); ?>%
                </div>
            </div>
        </div>

        <div style="margin-top:10px;font-size:12px;" class="muted">
            Tx history for this wallet is available on:
            <ul style="margin:4px 0 0 18px;padding:0;font-size:12px;">
                <li>Mooglife <strong>Tx History</strong> page (filter by wallet address)</li>
                <li><?php echo wallet_link($w, 'View on Solscan', false); ?></li>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Top 100 Holders</h2>
    <p class="muted" style="font-size:12px;margin-bottom:6px;">
        Click a wallet to view its profile above. Data comes from <code>mg_moog_holders</code>,
        ranked by <strong>largest MOOG balance</strong>.
    </p>
    <div style="overflow-x:auto;">
        <table class="data">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Wallet</th>
                    <th>Balance (MOOG)</th>
                    <th>% Supply</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$holders): ?>
                <tr>
                    <td colspan="5">No holder data found. Run a holder sync.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($holders as $h): ?>
                    <?php
                        $w    = $h['wallet'];
                        $ui   = (float)$h['ui_amount'];
                        $pct  = (float)$h['percent'];
                        $rank = (int)$h['rank'];
                        $linkUrl = '?p=wallet&wallet=' . urlencode($w);
                    ?>
                    <tr>
                        <td><?php echo $rank; ?></td>
                        <td>
                            <a href="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8'); ?>"
                               style="color:#7dd3fc;text-decoration:none;">
                                <?php echo htmlspecialchars(substr($w, 0, 4) . '…' . substr($w, -4), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($ui, 3); ?></td>
                        <td><?php echo number_format($pct, 6); ?>%</td>
                        <td>
                            <a href="<?php echo htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8'); ?>"
                               class="btn secondary"
                               style="padding:3px 8px;font-size:11px;">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
