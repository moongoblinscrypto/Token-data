<?php
// mooglife/api/tx.php
// Transactions API: recent MOOG transactions with filters.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// Read filters
$limit = (int)api_get('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}

// Cap limit by tier (no hard block, just clamp)
$limit = moog_api_cap_limit(
    $limit,
    [
        'free'      => 200,
        'anonymous' => 200,
        'pro'       => 1000,
        'internal'  => 5000,
        'default'   => 200,
    ],
    false
);

$direction = strtolower((string)api_get('direction', ''));
$wallet    = (string)api_get('wallet', '');
$source    = strtolower((string)api_get('source', ''));

$where  = [];
$params = [];
$types  = '';

// direction filter
if ($direction === 'buy' || $direction === 'sell') {
    $where[]  = 'LOWER(direction) = ?';
    $params[] = $direction;
    $types   .= 's';
}

// wallet filter (match either side)
if ($wallet !== '') {
    $where[]  = '(from_wallet = ? OR to_wallet = ?)';
    $params[] = $wallet;
    $params[] = $wallet;
    $types   .= 'ss';
}

// source filter
if ($source !== '') {
    $where[]  = 'LOWER(source) = ?';
    $params[] = $source;
    $types   .= 's';
}

$sql = "
    SELECT
        id,
        tx_signature,
        block_time,
        direction,
        amount_moog,
        price_usd,
        from_wallet,
        to_wallet,
        source
    FROM mg_moog_tx
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY block_time DESC, id DESC LIMIT ?';

$params[] = $limit;
$types   .= 'i';

try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing tx query', 500, $db->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id'           => (int)$row['id'],
            'tx_signature' => $row['tx_signature'],
            'block_time'   => $row['block_time'],
            'direction'    => strtoupper((string)$row['direction']),
            'amount_moog'  => (float)$row['amount_moog'],
            'price_usd'    => isset($row['price_usd']) ? (float)$row['price_usd'] : null,
            'from_wallet'  => $row['from_wallet'],
            'to_wallet'    => $row['to_wallet'],
            'source'       => $row['source'],
        ];
    }
    $stmt->close();

    api_ok([
        'limit'     => $limit,
        'direction' => $direction ?: null,
        'wallet'    => $wallet ?: null,
        'source'    => $source ?: null,
        'rows'      => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load transactions', 500, $e->getMessage());
}
