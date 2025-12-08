<?php
// mooglife/includes/widgets/mini_tx.php

require_once __DIR__ . '/../db.php';
$db = moog_db();

$rows = [];
$sql  = "
    SELECT block_time, tx_hash, from_wallet, to_wallet, amount_moog, direction, source
    FROM mg_moog_tx
    ORDER BY block_time DESC
    LIMIT 8
";
$res  = $db->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $res->free();
}
?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Recent MOOG Swaps</h3>
    <table class="data">
        <thead>
        <tr>
            <th>When</th>
            <th>Dir</th>
            <th>Amount (MOOG)</th>
            <th>From</th>
            <th>To</th>
            <th>Tx</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No tx history yet.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><?php echo htmlspecialchars($t['block_time']); ?></td>
                    <td>
                        <?php
                        $dir = strtoupper((string)$t['direction']);
                        if ($dir === 'BUY') {
                            echo '<span class="pill" style="background:#22c55e;">BUY</span>';
                        } elseif ($dir === 'SELL') {
                            echo '<span class="pill" style="background:#ef4444;">SELL</span>';
                        } else {
                            echo '<span class="pill" style="background:#6b7280;">X</span>';
                        }
                        ?>
                    </td>
                    <td><?php echo number_format((float)$t['amount_moog'], 3); ?></td>
                    <td style="font-size:11px;">
                        <?php echo wallet_link($t['from_wallet'], null, true); ?>
                    </td>
                    <td style="font-size:11px;">
                        <?php echo wallet_link($t['to_wallet'], null, true); ?>
                    </td>
                    <td style="font-size:11px;">
                        <code><?php echo htmlspecialchars($t['tx_hash']); ?></code>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:6px;font-size:12px;">
        See full feed on the <a href="?p=tx">Tx History</a> page.
    </p>
</div>
