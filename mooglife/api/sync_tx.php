<?php
// /mooglife/api/sync_tx.php
// Pull recent MOOG trades from Birdeye into mg_moog_tx.

declare(strict_types=1);

header('Content-Type: application/json');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php'; // moog_db()

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------

function api_fail(string $step, string $msg, ?array $extra = null): void
{
    http_response_code(500);
    $out = [
        'ok'   => false,
        'step' => $step,
        'error'=> $msg,
    ];
    if ($extra !== null) {
        $out['extra'] = $extra;
    }
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Read a setting from system_settings (key/value table).
 */
function get_setting(mysqli $db, string $key, ?string $default = null): ?string
{
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    $rowOk = $stmt->fetch();
    $stmt->close();

    if (!$rowOk) {
        return $default;
    }
    return $val;
}

/**
 * Simple Birdeye GET wrapper.
 */
function birdeye_get(string $url, string $apiKey): array
{
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
        'x-chain: solana',
        'User-Agent: MoogLifeLocal/1.0 (+https://moongoblins.net)',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
        // local WAMP SSL quirks
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        api_fail('birdeye_http', 'Curl error: ' . $err);
    }

    if ($code < 200 || $code >= 300) {
        api_fail('birdeye_http', "HTTP {$code} from Birdeye", ['body' => $body]);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        api_fail('birdeye_json', 'Invalid JSON from Birdeye', ['body' => $body]);
    }

    return $json;
}

// ---------------------------------------------------------
// 1) Load config
// ---------------------------------------------------------

$db = moog_db();

$tokenSymbol   = get_setting($db, 'token_symbol', 'MOOG');
$tokenMint     = get_setting($db, 'token_mint', '9s4HkvcwVMmGuS98FWH7DAuqo93woicQsMwf4D6MjZwW');
$tokenDecimals = (int) get_setting($db, 'token_decimals', '9');
$birdeyeKey    = get_setting($db, 'birdeye_api_key', '');

if ($birdeyeKey === '') {
    api_fail('config', 'Birdeye API key not set in system_settings.');
}

if ($tokenDecimals <= 0) {
    $tokenDecimals = 9;
}

// ---------------------------------------------------------
// 2) Fetch trades from Birdeye (Trades - Token)
// ---------------------------------------------------------

$endpoint = 'https://public-api.birdeye.so/defi/txs/token';

// recent last 100 swaps, newest first
$params = [
    'address'        => $tokenMint,
    'offset'         => 0,
    'limit'          => 50,
    'tx_type'        => 'swap',
    'sort_type'      => 'desc',
    'ui_amount_mode' => 'scaled', // so ui_amount is already human-sized
];

$url = $endpoint . '?' . http_build_query($params);

$json = birdeye_get($url, $birdeyeKey);

// Birdeye usually returns { data: [ ... ] }
$data = $json['data'] ?? null;
if (!is_array($data) || empty($data)) {
    api_fail('birdeye_data', 'No trades in Birdeye response.', ['sample' => $json]);
}

$trades = $data;

// ---------------------------------------------------------
// 3) Upsert into mg_moog_tx
// ---------------------------------------------------------

$db->begin_transaction();

try {
    $sql = "
        INSERT INTO mg_moog_tx
            (tx_hash, block_time, from_wallet, to_wallet, amount_moog, direction, source)
        VALUES
            (?, ?, ?, ?, ?, ?, 'birdeye_token_trades')
        ON DUPLICATE KEY UPDATE
            block_time  = VALUES(block_time),
            amount_moog = VALUES(amount_moog),
            direction   = VALUES(direction),
            source      = VALUES(source)
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

    $txHash       = '';
    $blockTimeStr = '';
    $fromWallet   = '';
    $toWallet     = '';
    $amountMoog   = 0.0;
    $direction    = 'TRANSFER';

    // s s s s d s
    $stmt->bind_param(
        'ssssds',
        $txHash,
        $blockTimeStr,
        $fromWallet,
        $toWallet,
        $amountMoog,
        $direction
    );

    $inserted = 0;

    foreach ($trades as $t) {
        // ------------- tx hash -------------
        $txHash = (string)($t['txHash'] ?? $t['txhash'] ?? $t['signature'] ?? '');
        if ($txHash === '') {
            continue; // skip weird rows
        }

        // ------------- time -------------
        $ts = $t['blockUnixTime'] ?? $t['block_unix_time'] ?? $t['blockTime'] ?? $t['block_time'] ?? null;
        if ($ts === null) {
            $blockTimeStr = gmdate('Y-m-d H:i:s');
        } else {
            $blockTimeStr = gmdate('Y-m-d H:i:s', (int)$ts);
        }

        // ------------- from / to (best effort) -------------
        $fromWallet = (string)(
            $t['owner']    ??
            $t['from']     ??
            $t['walletFrom'] ??
            $t['maker']    ??
            ''
        );
        $toWallet = (string)(
            $t['to']       ??
            $t['walletTo'] ??
            $t['taker']    ??
            ''
        );

        // ------------- amount -------------
        if (isset($t['ui_amount'])) {
            $amountMoog = (float)$t['ui_amount'];
        } elseif (isset($t['amount_token'])) {
            $amountMoog = (float)$t['amount_token'];
        } elseif (isset($t['amount'])) {
            $raw = (float)$t['amount'];
            $amountMoog = $tokenDecimals > 0
                ? $raw / pow(10, $tokenDecimals)
                : $raw;
        } else {
            $amountMoog = 0.0;
        }

        // ------------- direction -------------
        $side = strtolower((string)($t['side'] ?? $t['tradeSide'] ?? ''));
        if ($side === 'buy') {
            $direction = 'BUY';
        } elseif ($side === 'sell') {
            $direction = 'SELL';
        } else {
            $direction = 'TRANSFER';
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed for tx ' . $txHash . ': ' . $stmt->error);
        }
        $inserted++;
    }

    $stmt->close();
    $db->commit();

} catch (Throwable $e) {
    $db->rollback();
    api_fail('db_write', $e->getMessage());
}

// ---------------------------------------------------------
// 4) Done
// ---------------------------------------------------------

echo json_encode([
    'ok'              => true,
    'symbol'          => $tokenSymbol,
    'mint'            => $tokenMint,
    'trades_returned' => count($trades),
    'rows_written'    => $inserted,
], JSON_PRETTY_PRINT);
