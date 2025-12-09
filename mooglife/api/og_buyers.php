<?php
// mooglife/api/og_buyers.php
// OG buyers API.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

$wallet = (string) api_get('wallet', '');
$wallet = preg_replace('/\s+/', '', $wallet);

// Single wallet lookup
if ($wallet !== '') {
    $sql = "
        SELECT
            id,
            wallet,
            amount_moog,
            first_buy_time,
            last_buy_time,
            notes,
            source
        FROM mg_og_buyers
        WHERE wallet = ?
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing OG wallet query', 500, $db->error);
    }
    $stmt->bind_param('s', $wallet);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        api_error('Wallet is not in OG buyers list', 404, ['wallet' => $wallet]);
    }

    api_ok([
        'wallet' => $wallet,
        'og'     => $row,
    ]);
}

// Otherwise: full OG list (you can add limit/order later)
$sql = "
    SELECT
        id,
        wallet,
        amount_moog,
        first_buy_time,
        last_buy_time,
        notes,
        source
    FROM mg_og_buyers
    ORDER BY first_buy_time ASC, id ASC
";
if (!$res = $db->query($sql)) {
    api_error('DB error loading OG buyers', 500, $db->error);
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$res->close();

api_ok([
    'count' => count($rows),
    'items' => $rows,
]);
