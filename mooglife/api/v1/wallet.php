<?php
// mooglife/api/v1/wallet.php
// Public v1 - combined wallet view (holder profile + tx + airdrops).

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/** @var mysqli $db */

$wallet = trim((string)api_get('wallet', ''));
if ($wallet === '') {
    api_error('wallet parameter is required', 400, ['param' => 'wallet']);
}

$tier = moog_api_effective_tier();

// TX + airdrop per-tier caps
$txLimitReq  = (int)api_get('tx_limit', 50);
$airLimitReq = (int)api_get('air_limit', 25);
if ($txLimitReq <= 0)  $txLimitReq  = 50;
if ($airLimitReq <= 0) $airLimitReq = 25;

$txLimit = moog_api_cap_limit(
    $txLimitReq,
    [
        'free'      => 20,
        'anonymous' => 20,
        'pro'       => 100,
        'internal'  => 500,
        'default'   => 50,
    ],
    false
);

$airLimit = moog_api_cap_limit(
    $airLimitReq,
    [
        'free'      => 10,
        'anonymous' => 10,
        'pro'       => 50,
        'internal'  => 200,
        'default'   => 25,
    ],
    false
);

// ---------------------
// Holder profile
// ---------------------
$holderProfile = null;
$holderFound   = false;

try {
    // Ensure holders table exists
    $res = $db->query("SHOW TABLES LIKE 'mg_moog_holders'");
    if ($res && $res->num_rows > 0) {
        $res->close();

        $sql = "
            SELECT
                wallet,
                ui_amount,
                percent,
                rank,
                token_account,
                updated_at
            FROM mg_moog_holders
            WHERE wallet = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $wallet);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($row = $r->fetch_assoc()) {
                $holderFound   = true;
                $holderProfile = [
                    'wallet'        => $row['wallet'],
                    'ui_amount'     => isset($row['ui_amount']) ? (float)$row['ui_amount'] : null,
                    'percent'       => isset($row['percent']) ? (float)$row['percent'] : null,
                    'rank'          => isset($row['rank']) ? (int)$row['rank'] : null,
                    'token_account' => $row['token_account'] ?? null,
                    'updated_at'    => $row['updated_at'] ?? null,
                ];
            }
            $stmt->close();
        }
    } elseif ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    // If holders table explodes, just return null profile
}

// ---------------------
// Recent transactions
// ---------------------
$txRows = [];

try {
    $res = $db->query("SHOW TABLES LIKE 'mg_moog_tx'");
    if ($res && $res->num_rows > 0) {
        $res->close();

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
            WHERE from_wallet = ? OR to_wallet = ?
            ORDER BY block_time DESC, id DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssi', $wallet, $wallet, $txLimit);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $txRows[] = [
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
        }
    } elseif ($res) {
        $res->close();
    }
} catch (Throwable $e) {
    // TX failures just mean empty list
}

// ---------------------
// Airdrops summary + rows
// ---------------------
$airSummary = null;
$airRows    = [];

try {
    // Detect which airdrop table exists
    $airTable = null;

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

    if ($airTable !== null) {
        // Summary
        $sql = "
            SELECT
                COUNT(*)    AS drops,
                SUM(amount) AS total_amount
            FROM {$airTable}
            WHERE wallet_address = ?
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $wallet);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($row = $r->fetch_assoc()) {
                $airSummary = [
                    'drops' => (int)$row['drops'],
                    'total' => (float)$row['total_amount'],
                ];
            }
            $stmt->close();
        }

        // Raw rows
        $sql = "
            SELECT
                id,
                wallet_address,
                amount,
                source,
                name,
                created_at
            FROM {$airTable}
            WHERE wallet_address = ?
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('si', $wallet, $airLimit);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) {
                $airRows[] = [
                    'id'         => (int)$row['id'],
                    'wallet'     => $row['wallet_address'],
                    'amount'     => (float)$row['amount'],
                    'source'     => $row['source'],
                    'name'       => $row['name'],
                    'created_at' => $row['created_at'],
                ];
            }
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    // If airdrops explode, just return null summary and empty rows
}

// ---------------------
// Final payload
// ---------------------

api_ok([
    'wallet' => $wallet,
    'tier'   => $tier,

    'holder' => [
        'found'   => $holderFound,
        'profile' => $holderProfile,
    ],

    'tx' => [
        'limit' => $txLimit,
        'rows'  => $txRows,
    ],

    'airdrops' => [
        'limit'   => $airLimit,
        'summary' => $airSummary,
        'rows'    => $airRows,
    ],
]);
