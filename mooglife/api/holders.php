<?php
// mooglife/api/holders.php
// Holders API: top MOOG holders list + single-wallet profile.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// We assume mg_moog_holders structure roughly:
// id, wallet, amount, ui_amount, percent, rank, token_account, updated_at

// Resolve table name (future-proofing)
$holdersTable = 'mg_moog_holders';

try {
    $res = $db->query("SHOW TABLES LIKE 'mg_moog_holders'");
    if (!$res || $res->num_rows === 0) {
        if ($res) {
            $res->close();
        }
        api_error('Holders table mg_moog_holders not found.', 500);
    }
    if ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    api_error('Failed to verify holders table.', 500, $e->getMessage());
}

// ------------------------
// Inputs
// ------------------------

$wallet = trim((string)api_get('wallet', ''));

// Default list limit
$limit = (int)api_get('limit', 100);
if ($limit <= 0) {
    $limit = 100;
}

// Per-tier caps (no hard block, just clamp)
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

// ------------------------
// Single wallet profile
// ------------------------
if ($wallet !== '') {
    try {
        $sql = "
            SELECT
                wallet,
                ui_amount,
                percent,
                rank,
                token_account,
                updated_at
            FROM {$holdersTable}
            WHERE wallet = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            api_error('DB error preparing holder profile query', 500, $db->error);
        }
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?: null;
        $stmt->close();

        if (!$row) {
            api_ok([
                'wallet'  => $wallet,
                'found'   => false,
                'profile' => null,
            ]);
        }

        $profile = [
            'wallet'        => $row['wallet'],
            'ui_amount'     => isset($row['ui_amount']) ? (float)$row['ui_amount'] : null,
            'percent'       => isset($row['percent']) ? (float)$row['percent'] : null,
            'rank'          => isset($row['rank']) ? (int)$row['rank'] : null,
            'token_account' => $row['token_account'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];

        api_ok([
            'wallet'  => $wallet,
            'found'   => true,
            'tier'    => $tier,
            'profile' => $profile,
        ]);
    } catch (Throwable $e) {
        api_error('Failed to load holder profile', 500, $e->getMessage());
    }
}

// ------------------------
// Top holders list
// ------------------------

try {
    // Optional: aggregate stats
    $totalSupply = null;
    $holderCount = null;

    try {
        $aggSql = "
            SELECT
                COUNT(*)      AS holders_count,
                SUM(ui_amount) AS total_ui_amount
            FROM {$holdersTable}
        ";
        if ($aggRes = $db->query($aggSql)) {
            if ($aggRow = $aggRes->fetch_assoc()) {
                $holderCount = (int)$aggRow['holders_count'];
                $totalSupply = (float)$aggRow['total_ui_amount'];
            }
            $aggRes->close();
        }
    } catch (Throwable $e) {
        // aggregation is optional; ignore errors here
    }

    // Main list query
    $sql = "
        SELECT
            wallet,
            ui_amount,
            percent,
            rank,
            token_account,
            updated_at
        FROM {$holdersTable}
        ORDER BY ui_amount DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing holders list query', 500, $db->error);
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'wallet'        => $row['wallet'],
            'ui_amount'     => isset($row['ui_amount']) ? (float)$row['ui_amount'] : null,
            'percent'       => isset($row['percent']) ? (float)$row['percent'] : null,
            'rank'          => isset($row['rank']) ? (int)$row['rank'] : null,
            'token_account' => $row['token_account'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];
    }
    $stmt->close();

    api_ok([
        'tier'          => $tier,
        'limit'         => $limit,
        'holders_count' => $holderCount,
        'total_ui'      => $totalSupply,
        'rows'          => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Failed to load holders list', 500, $e->getMessage());
}
