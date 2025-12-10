<?php
// mooglife/api/market.php
// Market API: latest snapshot or historical series.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// mode: snapshot | history
$mode = strtolower((string)api_get('mode', 'snapshot'));

// base limit
$limit = (int)api_get('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}

// Apply per-tier caps
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

$tier = moog_api_effective_tier();

// history mode: block for free/anonymous
if ($mode === 'history' && in_array($tier, ['free', 'anonymous'], true)) {
    api_error(
        'Market history is only available for PRO or INTERNAL API keys.',
        402,
        ['tier' => $tier]
    );
}

if ($mode === 'history') {
    // Return historical rows from mg_market_history
    try {
        $sql = "
            SELECT
                id,
                created_at,
                price_usd,
                fdv_usd,
                liquidity_usd,
                volume_24h_usd,
                sol_price_usd,
                holders
            FROM mg_market_history
            ORDER BY created_at DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            api_error('DB error preparing market history query', 500, $db->error);
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'id'             => (int)$row['id'],
                'ts'             => $row['created_at'],
                'price_usd'      => (float)$row['price_usd'],
                'fdv_usd'        => (float)$row['fdv_usd'],
                'liquidity_usd'  => (float)$row['liquidity_usd'],
                'volume_24h_usd' => (float)$row['volume_24h_usd'],
                'sol_price_usd'  => (float)$row['sol_price_usd'],
                'holders'        => isset($row['holders']) ? (int)$row['holders'] : null,
            ];
        }
        $stmt->close();

        api_ok([
            'mode'  => 'history',
            'limit' => $limit,
            'rows'  => $rows,
        ]);
    } catch (Throwable $e) {
        api_error('Failed to load market history', 500, $e->getMessage());
    }
}

// Default: latest snapshot
try {
    // Prefer cache table if present
    $snapshot = null;

    $hasCache = false;
    $res = $db->query("SHOW TABLES LIKE 'mg_market_cache'");
    if ($res && $res->num_rows > 0) {
        $hasCache = true;
    }
    if ($res) {
        $res->close();
    }

    if ($hasCache) {
        $q = $db->query("
            SELECT *
            FROM mg_market_cache
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($q && $row = $q->fetch_assoc()) {
            $snapshot = $row;
        }
        if ($q) {
            $q->close();
        }
    }

    // Fallback to last history row if cache empty
    if (!$snapshot) {
        $q = $db->query("
            SELECT *
            FROM mg_market_history
            ORDER BY created_at DESC
            LIMIT 1
        ");
        if ($q && $row = $q->fetch_assoc()) {
            $snapshot = $row;
        }
        if ($q) {
            $q->close();
        }
    }

    if (!$snapshot) {
        api_error('No market data available yet', 404);
    }

    $data = [
        'ts'             => $snapshot['created_at'] ?? null,
        'price_usd'      => isset($snapshot['price_usd']) ? (float)$snapshot['price_usd'] : null,
        'fdv_usd'        => isset($snapshot['fdv_usd']) ? (float)$snapshot['fdv_usd'] : null,
        'liquidity_usd'  => isset($snapshot['liquidity_usd']) ? (float)$snapshot['liquidity_usd'] : null,
        'volume_24h_usd' => isset($snapshot['volume_24h_usd']) ? (float)$snapshot['volume_24h_usd'] : null,
        'sol_price_usd'  => isset($snapshot['sol_price_usd']) ? (float)$snapshot['sol_price_usd'] : null,
        'holders'        => isset($snapshot['holders']) ? (int)$snapshot['holders'] : null,
    ];

    api_ok([
        'mode'     => 'snapshot',
        'snapshot' => $data,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load market snapshot', 500, $e->getMessage());
}
