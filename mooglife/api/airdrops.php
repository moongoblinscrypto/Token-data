<?php
// mooglife/api/airdrops.php
// Airdrops API: raw airdrops + per-wallet / global summaries.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// Resolve airdrop table (mg_moog_airdrops or moog_airdrops)
$airTable = null;
try {
    $res = $db->query("SHOW TABLES LIKE 'mg_moog_airdrops'");
    if ($res && $res->num_rows > 0) {
        $airTable = 'mg_moog_airdrops';
    }
    if ($res) {
        $res->close();
    }

    if ($airTable === null) {
        $res = $db->query("SHOW TABLES LIKE 'moog_airdrops'");
        if ($res && $res->num_rows > 0) {
            $airTable = 'moog_airdrops';
        }
        if ($res) {
            $res->close();
        }
    }
} catch (Throwable $e) {
    // ignore, handled below
}

if ($airTable === null) {
    api_error('Airdrop table not found (mg_moog_airdrops / moog_airdrops).', 500);
}

// Input
$limit   = (int)api_get('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}

// Cap limit
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

$wallet  = trim((string)api_get('wallet', ''));
$summary = (string)api_get('summary', '');
$summary = ($summary === '1' || strtolower($summary) === 'true');

// Summary mode is PRO/internal only
$tier = moog_api_effective_tier();
if ($summary && in_array($tier, ['free', 'anonymous'], true)) {
    api_error(
        'Airdrop summary mode is only available for PRO or INTERNAL API keys.',
        402,
        ['tier' => $tier]
    );
}

// Summary for single wallet
if ($wallet !== '' && $summary) {
    try {
        $sql = "
            SELECT
                wallet_address,
                COUNT(*) AS drops,
                SUM(amount) AS total_amount
            FROM {$airTable}
            WHERE wallet_address = ?
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            api_error('DB error preparing airdrop summary query', 500, $db->error);
        }
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            api_ok([
                'wallet' => $wallet,
                'drops'  => 0,
                'total'  => 0,
            ]);
        }

        api_ok([
            'wallet' => $wallet,
            'drops'  => (int)$row['drops'],
            'total'  => (float)$row['total_amount'],
        ]);
    } catch (Throwable $e) {
        api_error('Failed to load airdrop summary for wallet', 500, $e->getMessage());
    }
}

// Global summary
if ($summary && $wallet === '') {
    try {
        $sql = "
            SELECT
                wallet_address,
                COUNT(*) AS drops,
                SUM(amount) AS total_amount
            FROM {$airTable}
            GROUP BY wallet_address
            ORDER BY total_amount DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            api_error('DB error preparing global airdrop summary query', 500, $db->error);
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'wallet' => $row['wallet_address'],
                'drops'  => (int)$row['drops'],
                'total'  => (float)$row['total_amount'],
            ];
        }
        $stmt->close();

        api_ok([
            'summary' => true,
            'limit'   => $limit,
            'rows'    => $rows,
        ]);
    } catch (Throwable $e) {
        api_error('Failed to load global airdrop summary', 500, $e->getMessage());
    }
}

// Raw airdrop rows
try {
    $sql = "
        SELECT
            id,
            wallet_address,
            amount,
            source,
            name,
            created_at
        FROM {$airTable}
    ";

    $params = [];
    $types  = '';

    if ($wallet !== '') {
        $sql     .= " WHERE wallet_address = ?";
        $params[] = $wallet;
        $types   .= 's';
    }

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";

    $params[] = $limit;
    $types   .= 'i';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing airdrop query', 500, $db->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id'        => (int)$row['id'],
            'wallet'    => $row['wallet_address'],
            'amount'    => (float)$row['amount'],
            'source'    => $row['source'],
            'name'      => $row['name'],
            'created_at'=> $row['created_at'],
        ];
    }
    $stmt->close();

    api_ok([
        'summary' => false,
        'wallet'  => $wallet !== '' ? $wallet : null,
        'limit'   => $limit,
        'rows'    => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load airdrops', 500, $e->getMessage());
}
