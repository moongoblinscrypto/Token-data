<?php
// mooglife/api/og_buyers.php
// OG Buyers API: list OG buyers and/or filter by wallet.
//
// Schema-aware:
// - Detects table name (mg_moog_og_buyers / mg_og_buyers / og_buyers)
// - Detects the wallet column (wallet or wallet_address)
// - Returns raw DB rows so future schema changes won't break the API.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// -------------------------------------
// Detect OG buyers table
// -------------------------------------
$candidateTables = ['mg_moog_og_buyers', 'mg_og_buyers', 'og_buyers'];
$ogTable = null;

try {
    foreach ($candidateTables as $t) {
        $safe = $db->real_escape_string($t);
        $res  = $db->query("SHOW TABLES LIKE '{$safe}'");
        if ($res && $res->num_rows > 0) {
            $ogTable = $t;
            $res->close();
            break;
        }
        if ($res) {
            $res->close();
        }
    }
} catch (Throwable $e) {
    api_error('Failed to inspect OG buyers tables.', 500, $e->getMessage());
}

if ($ogTable === null) {
    api_error('OG buyers table not found (mg_moog_og_buyers / mg_og_buyers / og_buyers).', 500);
}

// -------------------------------------
// Inspect columns
// -------------------------------------
$columns    = [];
$walletCols = ['wallet', 'wallet_address'];
$walletCol  = null;

try {
    $res = $db->query("SHOW COLUMNS FROM `{$ogTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $colName   = (string)$row['Field'];
            $columns[] = $colName;
        }
        $res->close();
    }

    foreach ($walletCols as $cand) {
        if (in_array($cand, $columns, true)) {
            $walletCol = $cand;
            break;
        }
    }
} catch (Throwable $e) {
    api_error('Failed to inspect OG buyers columns.', 500, $e->getMessage());
}

// -------------------------------------
// Inputs
// -------------------------------------
$wallet = trim((string)api_get('wallet', ''));

// Default list limit
$limit = (int)api_get('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}

// Per-tier caps
$limit = moog_api_cap_limit(
    $limit,
    [
        'free'      => 100,
        'anonymous' => 100,
        'pro'       => 500,
        'internal'  => 2000,
        'default'   => 100,
    ],
    false
);

$tier = moog_api_effective_tier();

// -------------------------------------
// Build query
// -------------------------------------
$where  = [];
$params = [];
$types  = '';

// Wallet filter only if we know which column is wallet
if ($wallet !== '' && $walletCol !== null) {
    $where[]  = "`{$walletCol}` = ?";
    $params[] = $wallet;
    $types   .= 's';
}

// Choose an ORDER BY column in a sensible priority
$orderCol = null;
foreach (['snapshot_at', 'created_at', 'first_buy_time', 'updated_at', 'id'] as $cand) {
    if (in_array($cand, $columns, true)) {
        $orderCol = $cand;
        break;
    }
}
if ($orderCol === null) {
    // fallback to first column if nothing else
    $orderCol = $columns[0] ?? 'id';
}

$sql = "SELECT * FROM `{$ogTable}`";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY `{$orderCol}` DESC LIMIT ?";

$params[] = $limit;
$types   .= 'i';

// -------------------------------------
// Execute
// -------------------------------------
try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing OG buyers query.', 500, $db->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        // return raw row as-is to avoid schema assumptions
        $rows[] = $row;
    }
    $stmt->close();

    api_ok([
        'tier'       => $tier,
        'table'      => $ogTable,
        'wallet_col' => $walletCol,
        'limit'      => $limit,
        'wallet'     => $wallet !== '' ? $wallet : null,
        'rows'       => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load OG buyers.', 500, $e->getMessage());
}
