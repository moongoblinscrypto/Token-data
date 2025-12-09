<?php
// mooglife/api/og_rewards.php
// OG Rewards API: list OG reward records, with optional wallet/type/status filters.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// ---------------------------------------------------------------------
// Resolve OG rewards table name
// Tries these in order so it works with your existing schema.
// ---------------------------------------------------------------------
$ogTable = null;
foreach (['mg_og_rewards', 'og_rewards', 'mg_ogrewards'] as $candidate) {
    try {
        $safe = $db->real_escape_string($candidate);
        $res  = $db->query("SHOW TABLES LIKE '{$safe}'");
        if ($res && $res->num_rows > 0) {
            $ogTable = $candidate;
            $res->close();
            break;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore and try next
    }
}

if ($ogTable === null) {
    api_error('OG rewards table not found (expected mg_og_rewards / og_rewards / mg_ogrewards)', 500);
}

// ---------------------------------------------------------------------
// Detect key columns so we don't guess wrong
// ---------------------------------------------------------------------
$walletColumn = null;
foreach (['wallet', 'wallet_address'] as $col) {
    try {
        $safeCol = $db->real_escape_string($col);
        $res     = $db->query("SHOW COLUMNS FROM `{$ogTable}` LIKE '{$safeCol}'");
        if ($res && $res->num_rows > 0) {
            $walletColumn = $col;
            $res->close();
            break;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// Order-by column: prefer created_at, then reward_time, else id
$orderColumn = 'id';
foreach (['created_at', 'reward_time'] as $col) {
    try {
        $safeCol = $db->real_escape_string($col);
        $res     = $db->query("SHOW COLUMNS FROM `{$ogTable}` LIKE '{$safeCol}'");
        if ($res && $res->num_rows > 0) {
            $orderColumn = $col;
            $res->close();
            break;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// Optional filter columns
$hasType   = false;
$hasStatus = false;

foreach (['reward_type' => 'hasType', 'status' => 'hasStatus'] as $col => $flag) {
    try {
        $safeCol = $db->real_escape_string($col);
        $res     = $db->query("SHOW COLUMNS FROM `{$ogTable}` LIKE '{$safeCol}'");
        if ($res && $res->num_rows > 0) {
            if ($flag === 'hasType')   { $hasType = true; }
            if ($flag === 'hasStatus') { $hasStatus = true; }
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// ---------------------------------------------------------------------
// Query params
// ---------------------------------------------------------------------
$wallet = (string) api_get('wallet', '');
$wallet = preg_replace('/\s+/', '', $wallet);

$type   = (string) api_get('type', '');
$type   = trim($type);

$status = (string) api_get('status', '');
$status = trim($status);

$limit  = (int) api_get('limit', 100);
if ($limit < 1)    $limit = 1;
if ($limit > 1000) $limit = 1000;

// ---------------------------------------------------------------------
// Build query
// We intentionally use SELECT * so the API always returns the full row,
// no matter what columns you add later.
// ---------------------------------------------------------------------
$where  = [];
$params = [];
$types  = '';

// Wallet filter (only if column exists)
if ($wallet !== '' && $walletColumn !== null) {
    $where[]  = "`{$walletColumn}` = ?";
    $types   .= 's';
    $params[] = $wallet;
}

// Reward type filter (if column exists)
if ($type !== '' && $hasType) {
    $where[]  = "reward_type = ?";
    $types   .= 's';
    $params[] = $type;
}

// Status filter (if column exists)
if ($status !== '' && $hasStatus) {
    $where[]  = "status = ?";
    $types   .= 's';
    $params[] = $status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        *
    FROM `{$ogTable}`
    {$whereSql}
    ORDER BY `{$orderColumn}` DESC, id DESC
    LIMIT ?
";

$types  .= 'i';
$params[] = $limit;

$stmt = $db->prepare($sql);
if (!$stmt) {
    api_error('DB error preparing OG rewards query', 500, $db->error);
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
    'table'   => $ogTable,
    'limit'   => $limit,
    'wallet'  => ($wallet !== '' ? $wallet : null),
    'type'    => ($type   !== '' ? $type   : null),
    'status'  => ($status !== '' ? $status : null),
    'count'   => count($rows),
    'items'   => $rows,
]);
