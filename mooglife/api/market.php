<?php
// mooglife/api/market.php
// Market API: latest snapshot + optional history.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// Decide mode
$mode  = strtolower((string) api_get('mode', 'latest'));
$limit = (int) api_get('limit', 100);
if ($limit < 1)   $limit = 1;
if ($limit > 1000) $limit = 1000;

// Check if history table exists
$hasHistory = false;
try {
    $res = $db->query("SHOW TABLES LIKE 'mg_market_history'");
    if ($res && $res->num_rows > 0) {
        $hasHistory = true;
    }
    if ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    // ignore, will fall back
}

// Base columns we expose
$cols = "
    token_symbol,
    token_mint,
    price_usd,
    market_cap_usd,
    fdv_usd,
    liquidity_usd,
    volume24h_usd,
    price_change_24h,
    holders,
    sol_price_usd
";

if ($mode === 'history' && $hasHistory) {
    // Last N rows from mg_market_history (newest first)
    $sql = "
        SELECT
            $cols,
            created_at AS snapshot_time
        FROM mg_market_history
        ORDER BY created_at DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing history query', 500, $db->error);
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
        'source'  => 'mg_market_history',
        'count'   => count($rows),
        'items'   => $rows,
    ]);
}

// Default: latest snapshot (from history if available, else cache)
if ($hasHistory) {
    $sql = "
        SELECT
            $cols,
            created_at AS snapshot_time
        FROM mg_market_history
        ORDER BY created_at DESC
        LIMIT 1
    ";
} else {
    $sql = "
        SELECT
            $cols,
            updated_at AS snapshot_time
        FROM mg_market_cache
        ORDER BY updated_at DESC
        LIMIT 1
    ";
}

if (!$res = $db->query($sql)) {
    api_error('DB error loading market snapshot', 500, $db->error);
}

$row = $res->fetch_assoc();
$res->close();

if (!$row) {
    api_error('No market data available', 404);
}

api_ok([
    'source' => $hasHistory ? 'mg_market_history' : 'mg_market_cache',
    'item'   => $row,
]);
