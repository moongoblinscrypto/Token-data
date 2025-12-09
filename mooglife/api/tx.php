<?php
// mooglife/api/tx.php
// MOOG transactions API from mg_moog_tx.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

$limit = (int) api_get('limit', 100);
if ($limit < 1)   $limit = 1;
if ($limit > 500) $limit = 500;

$direction = strtoupper((string) api_get('direction', ''));
$wallet    = (string) api_get('wallet', '');
$wallet    = preg_replace('/\s+/', '', $wallet);
$source    = (string) api_get('source', '');
$source    = trim($source);

// Build WHERE + params
$where  = [];
$params = [];
$types  = '';

if ($direction === 'BUY' || $direction === 'SELL') {
    $where[] = 'direction = ?';
    $types  .= 's';
    $params[] = $direction;
}

if ($wallet !== '') {
    // match either side
    $where[] = '(from_wallet = ? OR to_wallet = ?)';
    $types  .= 'ss';
    $params[] = $wallet;
    $params[] = $wallet;
}

if ($source !== '') {
    $where[] = 'source = ?';
    $types  .= 's';
    $params[] = $source;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        id,
        block_time,
        direction,
        amount_moog,
        price_usd,
        from_wallet,
        to_wallet,
        source,
        tx_hash
    FROM mg_moog_tx
    $whereSql
    ORDER BY block_time DESC, id DESC
    LIMIT ?
";

$types  .= 'i';
$params[] = $limit;

$stmt = $db->prepare($sql);
if (!$stmt) {
    api_error('DB error preparing tx query', 500, $db->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

api_ok([
    'limit'     => $limit,
    'direction' => $direction ?: 'ALL',
    'wallet'    => $wallet ?: null,
    'source'    => $source ?: null,
    'count'     => count($rows),
    'items'     => $rows,
]);
