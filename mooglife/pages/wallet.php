<?php
// mooglife/pages/wallet.php
// Wallet Manager: inspect a single wallet across holders, OG, tx, and airdrops.

require __DIR__ . '/../includes/auth.php';
mg_require_login();

$currentUser = mg_current_user();

require_once __DIR__ . '/../includes/db.php';
$db = mg_db();

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ------------------------------
// Read input
// ------------------------------
$wallet = trim((string)($_GET['wallet'] ?? ''));
$wallet = $wallet !== '' ? $wallet : '';

// Optional limits
$txLimit    = isset($_GET['tx_limit']) ? (int)$_GET['tx_limit'] : 100;
$airLimit   = isset($_GET['air_limit']) ? (int)$_GET['air_limit'] : 100;
if ($txLimit <= 0)  $txLimit = 100;
if ($airLimit <= 0) $airLimit = 100;

// ------------------------------
// Helper: detect airdrop table
// ------------------------------
$airdropTable = null;
try {
    $res = $db->query("SHOW TABLES LIKE 'mg_moog_airdrops'");
    if ($res && $res->num_rows > 0) {
        $airdropTable = 'mg_moog_airdrops';
    }
    if ($res) {
        $res->close();
    }

    if ($airdropTable === null) {
        $res = $db->query("SHOW TABLES LIKE 'moog_airdrops'");
        if ($res && $res->num_rows > 0) {
            $airdropTable = 'moog_airdrops';
        }
        if ($res) {
            $res->close();
        }
    }
} catch (Throwable $e) {
    // ignore; handled as "no table" below
}

// ------------------------------
// Load data if wallet is set
// ------------------------------
$holderRow    = null;
$holderError  = null;

$ogBuyerRow   = null;
$ogBuyerError = null;

$ogRewards    = [];
$ogRewardsError = null;

$txRows       = [];
$txError      = null;

$airRows      = [];
$airError     = null;
$airSummary   = null;

if ($wallet !== '') {
    // Holder profile
    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_moog_holders'");
        if ($res && $res->num_rows > 0) {
            $res->close();

            // NOTE: `rank` is reserved → backtick it
            $sql = "
                SELECT
                    wallet,
                    ui_amount,
                    percent,
                    `rank`,
                    token_account,
                    updated_at
                FROM mg_moog_holders
                WHERE wallet = ?
                LIMIT 1
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $wallet);
                $stmt->execute();
                $r = $stmt->get_result();
                $holderRow = $r->fetch_assoc() ?: null;
                $stmt->close();
            }
        } elseif ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        $holderError = $e->getMessage();
    }

    // OG buyer snapshot (mg_moog_og_buyers)
    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_moog_og_buyers'");
        if ($res && $res->num_rows > 0) {
            $res->close();

            $sql = "
                SELECT
                    wallet,
                    first_buy_time,
                    first_buy_amount,
                    total_bought,
                    total_sold,
                    current_balance,
                    buy_tx_count,
                    sell_tx_count,
                    is_eligible,
                    og_tier,
                    label_tags,
                    exclude_reason,
                    notes,
                    snapshot_at
                FROM mg_moog_og_buyers
                WHERE wallet = ?
                LIMIT 1
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $wallet);
                $stmt->execute();
                $r = $stmt->get_result();
                $ogBuyerRow = $r->fetch_assoc() ?: null;
                $stmt->close();
            }
        } elseif ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        $ogBuyerError = $e->getMessage();
    }

    // OG rewards (mg_moog_og_rewards)
    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_moog_og_rewards'");
        if ($res && $res->num_rows > 0) {
            $res->close();

            $sql = "
                SELECT
                    id,
                    wallet,
                    planned_amount,
                    tx_hash,
                    status,
                    notes,
                    created_at,
                    updated_at
                FROM mg_moog_og_rewards
                WHERE wallet = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 100
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $wallet);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $ogRewards[] = $row;
                }
                $stmt->close();
            }
        } elseif ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        $ogRewardsError = $e->getMessage();
    }

    // Transactions (mg_moog_tx)
    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_moog_tx'");
        if ($res && $res->num_rows > 0) {
            $res->close();

            // NO price_usd or tx_signature here – matches your table
            $sql = "
                SELECT
                    id,
                    block_time,
                    direction,
                    amount_moog,
                    from_wallet,
                    to_wallet,
                    source
                FROM mg_moog_tx
                WHERE from_wallet = ? OR to_wallet = ?
                ORDER BY block_time DESC, id DESC
                LIMIT ?
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('ssi', $wallet, $wallet, $txLimit);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $txRows[] = $row;
                }
                $stmt->close();
            }
        } elseif ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        $txError = $e->getMessage();
    }

    // Airdrops (mg_moog_airdrops or moog_airdrops)
    if ($airdropTable !== null) {
        try {
            // Summary
            $sql = "
                SELECT
                    COUNT(*) AS drops,
                    SUM(amount) AS total_amount
                FROM {$airdropTable}
                WHERE wallet_address = ?
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $wallet);
                $stmt->execute();
                $r = $stmt->get_result();
                $airSummary = $r->fetch_assoc() ?: null;
                $stmt->close();
            }

            // Rows
            $sql = "
                SELECT
                    id,
                    wallet_address,
                    amount,
                    source,
                    name,
                    created_at
                FROM {$airdropTable}
                WHERE wallet_address = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('si', $wallet, $airLimit);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($row = $r->fetch_assoc()) {
                    $airRows[] = $row;
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            $airError = $e->getMessage();
        }
    }
}
?>

<h1>Wallet Manager</h1>
<p class="muted">
    Inspect a single wallet across holders, OG status, rewards, transactions, and airdrops.
</p>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;font-size:16px;">Find Wallet</h2>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="p" value="wallet">

        <div style="flex:1;min-width:260px;">
            <label style="display:block;font-size:13px;margin-bottom:4px;">Wallet Address</label>
            <input type="text"
                   name="wallet"
                   class="input"
                   placeholder="Enter a Solana wallet address"
                   value="<?php echo h($wallet); ?>"
                   style="width:100%;">
        </div>

        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Tx Limit</label>
            <input type="number"
                   name="tx_limit"
                   class="input"
                   value="<?php echo (int)$txLimit; ?>"
                   min="10" max="500"
                   style="width:80px;">
        </div>

        <div>
            <label style="display:block;font-size:13px;margin-bottom:4px;">Airdrop Limit</label>
            <input type="number"
                   name="air_limit"
                   class="input"
                   value="<?php echo (int)$airLimit; ?>"
                   min="10" max="500"
                   style="width:80px;">
        </div>

        <div>
            <button type="submit" class="btn primary" style="margin-top:2px;">Load Wallet</button>
        </div>
    </form>
</div>

<?php if ($wallet === ''): ?>
    <div class="card">
        <p class="muted" style="font-size:13px;">
            Enter a wallet address above to load its details.
            You can click a wallet from the <strong>Holders</strong>, <strong>OG Buyers</strong>, or <strong>Tx</strong> pages
            to jump here with everything pre-loaded.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;font-size:16px;">Wallet Overview</h2>
    <p style="font-size:13px;margin-bottom:8px;">
        <strong>Wallet:</strong>
        <span style="font-family:monospace;"><?php echo h($wallet); ?></span>
    </p>
    <p style="font-size:12px;" class="muted">
        (Consider linking to Solscan / Birdeye here later.)
    </p>
</div>

<div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px;">
    <!-- Holder card -->
    <div class="card" style="flex:1;min-width:260px;">
        <h3 style="margin-top:0;font-size:15px;">Holder Snapshot</h3>
        <?php if ($holderError): ?>
            <p class="muted" style="font-size:12px;color:#fca5a5;">
                Error: <?php echo h($holderError); ?>
            </p>
        <?php elseif (!$holderRow): ?>
            <p class="muted" style="font-size:13px;">
                This wallet is not currently in <code>mg_moog_holders</code>.
            </p>
        <?php else: ?>
            <table class="table" style="font-size:12px;">
                <tbody>
                    <tr>
                        <th style="width:120px;">MOOG Balance</th>
                        <td><?php echo number_format((float)$holderRow['ui_amount'], 6); ?></td>
                    </tr>
                    <tr>
                        <th>Percent of Pool</th>
                        <td><?php echo number_format((float)$holderRow['percent'], 4); ?>%</td>
                    </tr>
                    <tr>
                        <th>Rank</th>
                        <td>#<?php echo (int)$holderRow['rank']; ?></td>
                    </tr>
                    <tr>
                        <th>Token Account</th>
                        <td style="font-family:monospace;"><?php echo h($holderRow['token_account']); ?></td>
                    </tr>
                    <tr>
                        <th>Last Updated</th>
                        <td><?php echo h($holderRow['updated_at']); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- OG Buyer card -->
    <div class="card" style="flex:1;min-width:260px;">
        <h3 style="margin-top:0;font-size:15px;">OG Buyer Status</h3>
        <?php if ($ogBuyerError): ?>
            <p class="muted" style="font-size:12px;color:#fca5a5;">
                Error: <?php echo h($ogBuyerError); ?>
            </p>
        <?php elseif (!$ogBuyerRow): ?>
            <p class="muted" style="font-size:13px;">
                No OG buyer record found for this wallet.
            </p>
        <?php else: ?>
            <?php
                $eligible = (int)$ogBuyerRow['is_eligible'] === 1;
            ?>
            <table class="table" style="font-size:12px;">
                <tbody>
                    <tr>
                        <th style="width:120px;">OG Eligible</th>
                        <td>
                            <?php if ($eligible): ?>
                                <span class="pill" style="background:#14532d;color:#bbf7d0;">YES</span>
                            <?php else: ?>
                                <span class="pill" style="background:#4b5563;color:#e5e7eb;">NO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>OG Tier</th>
                        <td><?php echo h($ogBuyerRow['og_tier']); ?></td>
                    </tr>
                    <tr>
                        <th>First Buy Time</th>
                        <td><?php echo h($ogBuyerRow['first_buy_time']); ?></td>
                    </tr>
                    <tr>
                        <th>First Buy Amount</th>
                        <td><?php echo number_format((float)$ogBuyerRow['first_buy_amount'], 6); ?></td>
                    </tr>
                    <tr>
                        <th>Total Bought</th>
                        <td><?php echo number_format((float)$ogBuyerRow['total_bought'], 6); ?></td>
                    </tr>
                    <tr>
                        <th>Total Sold</th>
                        <td><?php echo number_format((float)$ogBuyerRow['total_sold'], 6); ?></td>
                    </tr>
                    <tr>
                        <th>Current Balance</th>
                        <td><?php echo number_format((float)$ogBuyerRow['current_balance'], 6); ?></td>
                    </tr>
                    <tr>
                        <th>Buys / Sells</th>
                        <td>
                            <?php echo (int)$ogBuyerRow['buy_tx_count']; ?> buys /
                            <?php echo (int)$ogBuyerRow['sell_tx_count']; ?> sells
                        </td>
                    </tr>
                    <tr>
                        <th>Labels</th>
                        <td><?php echo h($ogBuyerRow['label_tags']); ?></td>
                    </tr>
                    <tr>
                        <th>Exclude Reason</th>
                        <td><?php echo h($ogBuyerRow['exclude_reason']); ?></td>
                    </tr>
                    <tr>
                        <th>Notes</th>
                        <td><?php echo nl2br(h($ogBuyerRow['notes'])); ?></td>
                    </tr>
                    <tr>
                        <th>Snapshot At</th>
                        <td><?php echo h($ogBuyerRow['snapshot_at']); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin-top:0;font-size:15px;">OG Rewards</h3>
    <?php if ($ogRewardsError): ?>
        <p class="muted" style="font-size:12px;color:#fca5a5;">
            Error: <?php echo h($ogRewardsError); ?>
        </p>
    <?php elseif (!$ogRewards): ?>
        <p class="muted" style="font-size:13px;">
            No OG rewards recorded for this wallet yet.
        </p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table" style="font-size:12px;min-width:700px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Amount (MOOG)</th>
                        <th>Status</th>
                        <th>Tx Hash</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ogRewards as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><?php echo number_format((float)$row['planned_amount'], 6); ?></td>
                        <td><?php echo h($row['status']); ?></td>
                        <td style="font-family:monospace;"><?php echo h($row['tx_hash']); ?></td>
                        <td><?php echo h($row['created_at']); ?></td>
                        <td><?php echo h($row['updated_at']); ?></td>
                        <td><?php echo nl2br(h($row['notes'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px;">
    <!-- Tx history -->
    <div class="card" style="flex:1;min-width:320px;">
        <h3 style="margin-top:0;font-size:15px;">Recent Transactions (limit <?php echo (int)$txLimit; ?>)</h3>
        <?php if ($txError): ?>
            <p class="muted" style="font-size:12px;color:#fca5a5;">
                Error: <?php echo h($txError); ?>
            </p>
        <?php elseif (!$txRows): ?>
            <p class="muted" style="font-size:13px;">
                No transactions recorded for this wallet in <code>mg_moog_tx</code>.
            </p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table" style="font-size:12px;min-width:720px;">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Dir</th>
                            <th>Amount (MOOG)</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($txRows as $row): ?>
                        <tr>
                            <td><?php echo h($row['block_time']); ?></td>
                            <td><?php echo strtoupper(h($row['direction'])); ?></td>
                            <td><?php echo number_format((float)$row['amount_moog'], 6); ?></td>
                            <td style="font-family:monospace;"><?php echo h($row['from_wallet']); ?></td>
                            <td style="font-family:monospace;"><?php echo h($row['to_wallet']); ?></td>
                            <td><?php echo h($row['source']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Airdrops -->
    <div class="card" style="flex:1;min-width:320px;">
        <h3 style="margin-top:0;font-size:15px;">Airdrops (limit <?php echo (int)$airLimit; ?>)</h3>
        <?php if ($airdropTable === null): ?>
            <p class="muted" style="font-size:13px;">
                No airdrop table found (<code>mg_moog_airdrops</code> or <code>moog_airdrops</code>).
            </p>
        <?php elseif ($airError): ?>
            <p class="muted" style="font-size:12px;color:#fca5a5;">
                Error: <?php echo h($airError); ?>
            </p>
        <?php else: ?>
            <?php
                $drops = (int)($airSummary['drops'] ?? 0);
                $total = (float)($airSummary['total_amount'] ?? 0.0);
            ?>
            <p style="font-size:12px;margin-bottom:6px;">
                Drops: <strong><?php echo $drops; ?></strong> &mdash;
                Total Airdropped: <strong><?php echo number_format($total, 6); ?> MOOG</strong>
            </p>
            <?php if (!$airRows): ?>
                <p class="muted" style="font-size:13px;">
                    No airdrops logged for this wallet.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table" style="font-size:12px;min-width:720px;">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Amount (MOOG)</th>
                                <th>Source</th>
                                <th>Name</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($airRows as $row): ?>
                            <tr>
                                <td><?php echo h($row['created_at']); ?></td>
                                <td><?php echo number_format((float)$row['amount'], 6); ?></td>
                                <td><?php echo h($row['source']); ?></td>
                                <td><?php echo h($row['name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
