<?php
// /mooglife/api/sync_holders.php
// Sync top MOOG holders from Birdeye into mg_moog_holders (top 100).

declare(strict_types=1);

header('Content-Type: application/json');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';  // gives mg_db()

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
 * Read a setting from mg_system_settings (key/value table).
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
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        // Local WAMP certs can be funky; relax SSL checks:
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
// 1) Load config from DB
// ---------------------------------------------------------

$db = moog_db();

$tokenSymbol   = get_setting($db, 'token_symbol', 'MOOG');
$tokenMint     = get_setting($db, 'token_mint', '9s4HkvcwVMmGuS98FWH7DAuqo93woicQsMwf4D6MjZwW');
$tokenDecimals = (int) get_setting($db, 'token_decimals', '9');
$birdeyeKey    = get_setting($db, 'birdeye_api_key', '');

if ($birdeyeKey === '') {
    api_fail('config', 'Birdeye API key not set in settings.');
}

// Try to get circulating supply from mg_market_cache (fdv / price)
$circSupply = null;
$mcSql = "SELECT price_usd, fdv_usd FROM mg_market_cache WHERE token_symbol = ? LIMIT 1";
if ($stmt = $db->prepare($mcSql)) {
    $stmt->bind_param('s', $tokenSymbol);
    $stmt->execute();
    $stmt->bind_result($priceUsd, $fdvUsd);
    if ($stmt->fetch() && $priceUsd > 0 && $fdvUsd > 0) {
        $circSupply = (float)$fdvUsd / (float)$priceUsd;
    }
    $stmt->close();
}
// Fallback to hardcoded 100M circ if not available
if ($circSupply === null || $circSupply <= 0) {
    $circSupply = 100000000.0;
}

// ---------------------------------------------------------
// 2) Fetch top holders from Birdeye (v3 Token - Holder)
// ---------------------------------------------------------

$limit  = 100;  // free plan max; top 100 is all we need right now
$offset = 0;

$endpoint = 'https://public-api.birdeye.so/defi/v3/token/holder';
$query    = http_build_query([
    'address' => $tokenMint,
    'offset'  => $offset,
    'limit'   => $limit,
]);
$url = $endpoint . '?' . $query;

$json = birdeye_get($url, $birdeyeKey);

// Birdeye responses are generally { data: { items: [...] } } or { data: [...] }
$data  = $json['data'] ?? [];
$items = [];
if (isset($data['items']) && is_array($data['items'])) {
    $items = $data['items'];
} elseif (is_array($data)) {
    $items = $data;
}

if (empty($items)) {
    api_fail('birdeye_data', 'No holder items returned from Birdeye.', ['data_sample' => $data]);
}

// Each item should have: amount (string), decimals, mint, owner, token_account
// We'll trust item.decimals, but fall back to settings if missing.
$firstDecimals = $items[0]['decimals'] ?? $tokenDecimals;
if (!is_int($firstDecimals)) {
    $firstDecimals = (int)$firstDecimals;
}
if ($firstDecimals <= 0) {
    $firstDecimals = $tokenDecimals;
}

// ---------------------------------------------------------
// 3) Replace mg_moog_holders contents with fresh snapshot
// ---------------------------------------------------------

$db->begin_transaction();

try {
    // Wipe existing holders
    $db->query("TRUNCATE TABLE mg_moog_holders");

    $sql = "
        INSERT INTO mg_moog_holders
            (wallet, raw_amount, ui_amount, percent, token_account, updated_at)
        VALUES
            (?, ?, ?, ?, ?, NOW())
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

    // wallet, raw_amount, ui_amount, percent, token_account
    $wallet       = '';
    $rawAmountF   = 0.0;
    $uiAmount     = 0.0;
    $percent      = 0.0;
    $tokenAccount = '';

    $stmt->bind_param('sddss', $wallet, $rawAmountF, $uiAmount, $percent, $tokenAccount);

    $inserted = 0;

    $decPow = pow(10, $firstDecimals);

    foreach ($items as $it) {
        $wallet       = (string)($it['owner'] ?? '');
        $tokenAccount = (string)($it['token_account'] ?? '');
        $amountStr    = (string)($it['amount'] ?? '0');

        if ($wallet === '' || $amountStr === '') {
            continue;
        }

        $rawAmountF = (float)$amountStr;
        $uiAmount   = $decPow > 0 ? $rawAmountF / $decPow : 0.0;

        $percent = $circSupply > 0 ? ($uiAmount / $circSupply) * 100.0 : 0.0;

        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed for wallet ' . $wallet . ': ' . $stmt->error);
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
// 4) Done – return summary JSON
// ---------------------------------------------------------

$out = [
    'ok'               => true,
    'symbol'           => $tokenSymbol,
    'mint'             => $tokenMint,
    'holders_returned' => count($items),
    'rows_inserted'    => $inserted,
    'decimals_used'    => $firstDecimals,
    'circ_supply_used' => $circSupply,
];

echo json_encode($out, JSON_PRETTY_PRINT);
