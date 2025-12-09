<?php
// mooglife/api/airdrops.php
// Airdrops API: list airdrop records and per-wallet summaries.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// ---------------------------------------------------------------------
// Resolve airdrop table name: prefer mg_moog_airdrops, fallback moog_airdrops
// ---------------------------------------------------------------------
$airTable = null;
foreach (['mg_moog_airdrops', 'moog_airdrops'] as $candidate) {
    try {
        $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($candidate) . "'");
        if ($res && $res->num_rows > 0) {
            $airTable = $candidate;
            $res->close();
            break;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore, try next
    }
}

if ($airTable === null) {
    api_error('Airdrop table not found (expected mg_moog_airdrops or moog_airdrops)', 500);
}

// Detect if created_at column exists (for nicer ordering/output)
$hasCreatedAt = false;
try {
    $res = $db->query("SHOW COLUMNS FROM `{$airTable}` LIKE 'created_at'");
    if ($res && $res->num_rows > 0) {
        $hasCreatedAt = true;
    }
    if ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    $hasCreatedAt = false;
}

$baseCols = "id, wallet_address, amount, source, name";
$cols     = $baseCols . ($hasCreatedAt ? ", created_at" : "");
$orderCol = $hasCreatedAt ? "created_at" : "id";

// ---------------------------------------------------------------------
// Read query params
// ---------------------------------------------------------------------
$wallet  = (string) api_get('wallet', '');
$wallet  = preg_replace('/\s+/', '', $wallet);
$limit   = (int) api_get('limit', 100);
$summary = strtolower((string) api_get('summary', '0'));

if ($limit < 1)   $limit = 1;
if ($limit > 1000) $limit = 1000;

$summaryMode = in_array($summary, ['1', 'true', 'yes', 'sum', 'summary'], true);

// ---------------------------------------------------------------------
// Routing logic:
//  1) wallet + summary   → summary for that wallet
//  2) wallet only        → list of airdrops for that wallet (latest first)
//  3) summary only       → per-wallet totals (top by MOOG amount)
//  4) no wallet/summary  → latest N airdrop records globally
// ---------------------------------------------------------------------

// 1) Single wallet summary
if ($wallet !== '' && $summaryMode) {
    $sql = "
        SELECT
            wallet_address,
            SUM(amount) AS total_amount,
            COUNT(*)    AS drops_count
        FROM `{$airTable}`
        WHERE wallet_address = ?
        GROUP BY wallet_address
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing wallet summary', 500, $db->error);
    }
    $stmt->bind_param('s', $wallet);
    $stmt->execute();
    $res  = $stmt->get_result();
    $row  = $res->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        api_error('No airdrops found for this wallet', 404, ['wallet' => $wallet]);
    }

    api_ok([
        'mode'   => 'wallet_summary',
        'wallet' => $wallet,
        'data'   => $row,
    ]);
}

// 2) Airdrop list for a specific wallet
if ($wallet !== '' && !$summaryMode) {
    $sql = "
        SELECT
            {$cols}
        FROM `{$airTable}`
        WHERE wallet_address = ?
        ORDER BY {$orderCol} DESC, id DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing wallet airdrop list', 500, $db->error);
    }
    $stmt->bind_param('si', $wallet, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    api_ok([
        'mode'   => 'wallet_list',
        'wallet' => $wallet,
        'limit'  => $limit,
        'count'  => count($rows),
        'items'  => $rows,
    ]);
}

// 3) Summary for all wallets (top recipients)
if ($wallet === '' && $summaryMode) {
    $sql = "
        SELECT
            wallet_address,
            SUM(amount) AS total_amount,
            COUNT(*)    AS drops_count
        FROM `{$airTable}`
        GROUP BY wallet_address
        ORDER BY total_amount DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing global summary', 500, $db->error);
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    api_ok([
        'mode'  => 'summary',
        'limit' => $limit,
        'count' => count($rows),
        'items' => $rows,
    ]);
}

// 4) Global latest N airdrop records
$sql = "
    SELECT
        {$cols}
    FROM `{$airTable}`
    ORDER BY {$orderCol} DESC, id DESC
    LIMIT ?
";

$stmt = $db->prepare($sql);
if (!$stmt) {
    api_error('DB error preparing global airdrop list', 500, $db->error);
}
$stmt->bind_param('i', $limit);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

api_ok([
    'mode'  => 'latest',
    'limit' => $limit,
    'count' => count($rows),
    'items' => $rows,
]);
