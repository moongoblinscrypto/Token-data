<?php
// mooglife/includes/widgets/mini_holders.php

require_once __DIR__ . '/../db.php';
$db = moog_db();

// Join holders with wallet labels
$sql = "
    SELECT
        h.wallet,
        h.ui_amount      AS balance_moog,
        h.percent        AS pct,
        w.label          AS label
    FROM mg_moog_holders h
    LEFT JOIN mg_moog_wallets w
        ON w.wallet = h.wallet
    ORDER BY h.ui_amount DESC
    LIMIT 5
";

$rows = [];
$res  = $db->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $res->free();
}
?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Top 5 Holders</h3>
    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Label</th>
            <th>Wallet</th>
            <th>MOOG</th>
            <th>%</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5">No holder data yet.</td></tr>
        <?php else: ?>
            <?php $i = 1; foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($r['label'] ?: '(unlabeled)'); ?></td>
                    <td style="font-size:11px;">
                        <?php
                        // clickable link to wallet profile
                        echo wallet_link(
                            $r['wallet'],
                            $r['label'] ?: null,
                            true  // shorten if no label
                        );
                        ?>
                    </td>
                    <td><?php echo number_format((float)$r['balance_moog'], 3); ?></td>
                    <td><?php echo number_format((float)$r['pct'], 6); ?>%</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="muted" style="margin-top:6px;font-size:12px;">
        Full details on the <a href="?p=holders">Holders</a> page.
    </p>
</div>
