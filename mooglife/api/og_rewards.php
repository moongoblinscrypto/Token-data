<?php
// mooglife/api/og_rewards.php
// OG Rewards API: list OG rewards, with optional filters.
//
// Schema-aware:
// - Detects table name (mg_moog_og_rewards / mg_og_rewards / og_rewards / mg_ogrewards)
// - Detects wallet, type, status columns when present
// - Returns raw DB rows so schema changes won't break the API.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// -------------------------------------
// Detect OG rewards table
// -------------------------------------
$candidateTables = ['mg_moog_og_rewards', 'mg_og_rewards', 'og_rewards', 'mg_ogrewards'];
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
    api_error('Failed to inspect OG rewards tables.', 500, $e->getMessage());
}

if ($ogTable === null) {
    api_error('OG rewards table not found (mg_moog_og_rewards / mg_og_rewards / og_rewards / mg_ogrewards).', 500);
}

// -------------------------------------
// Inspect columns
// -------------------------------------
$columns     = [];
$walletCol   = null;
$typeCol     = null;
$statusCol   = null;

// candidate names we try to match for each semantic
$walletCands = ['wallet', 'wallet_address', 'holder_wallet'];
$typeCands   = ['reward_type', 'type'];
$statusCands = ['status', 'reward_status'];

try {
    $res = $db->query("SHOW COLUMNS FROM `{$ogTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $colName   = (string)$row['Field'];
            $columns[] = $colName;
        }
        $res->close();
    }

    foreach ($walletCands as $cand) {
        if (in_array($cand, $columns, true)) {
            $walletCol = $cand;
            break;
        }
    }
    foreach ($typeCands as $cand) {
        if (in_array($cand, $columns, true)) {
            $typeCol = $cand;
            break;
        }
    }
    foreach ($statusCands as $cand) {
        if (in_array($cand, $columns, true)) {
            $statusCol = $cand;
            break;
        }
    }
} catch (Throwable $e) {
    api_error('Failed to inspect OG rewards columns.', 500, $e->getMessage());
}

// -------------------------------------
// Inputs
// -------------------------------------
$wallet = trim((string)api_get('wallet', ''));
$type   = trim((string)api_get('type', ''));
$status = trim((string)api_get('status', ''));

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
        'pro'       => 1000,
        'internal'  => 5000,
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

// Apply filters only if corresponding column exists
if ($wallet !== '' && $walletCol !== null) {
    $where[]  = "`{$walletCol}` = ?";
    $params[] = $wallet;
    $types   .= 's';
}

if ($type !== '' && $typeCol !== null) {
    $where[]  = "`{$typeCol}` = ?";
    $params[] = $type;
    $types   .= 's';
}

if ($status !== '' && $statusCol !== null) {
    $where[]  = "`{$statusCol}` = ?";
    $params[] = $status;
    $types   .= 's';
}

// Choose ORDER BY column
$orderCol = null;
foreach (['created_at', 'reward_time', 'updated_at', 'id'] as $cand) {
    if (in_array($cand, $columns, true)) {
        $orderCol = $cand;
        break;
    }
}
if ($orderCol === null) {
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
        api_error('DB error preparing OG rewards query.', 500, $db->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row; // raw row
    }
    $stmt->close();

    api_ok([
        'tier'       => $tier,
        'table'      => $ogTable,
        'wallet_col' => $walletCol,
        'type_col'   => $typeCol,
        'status_col' => $statusCol,
        'limit'      => $limit,
        'wallet'     => $wallet !== '' ? $wallet : null,
        'type'       => $type !== '' ? $type : null,
        'status'     => $status !== '' ? $status : null,
        'rows'       => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load OG rewards.', 500, $e->getMessage());
}
