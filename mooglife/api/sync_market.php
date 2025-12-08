<?php
// mooglife/api/sync_market.php
// Local version of market sync using DexScreener + mg_moog_holders (mysqli)

header('Content-Type: application/json');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/settings.php';

$db = moog_db();

// ---------------------------------------------------------------------
// CONFIG (pulled from settings where possible)
// ---------------------------------------------------------------------

$MOOG_SYMBOL = ml_get_setting('token_symbol', 'MOOG');
$MOOG_TOKEN_MINT = ml_get_setting('token_mint', '9s4HkvcwVMmGuS98FWH7DAuqo93woicQsMwf4D6MjZwW');

// Raydium pool (hard-coded for now; can move to settings later)
$MOOG_POOL_ADDR = 'As3LVGczwcR4QZ9hQFCedV8gzQBiLutTXkCdZ8QGTaVz';

// DexScreener endpoints
$DEX_URL_TOKEN = 'https://api.dexscreener.com/latest/dex/tokens/' . $MOOG_TOKEN_MINT;
$DEX_URL_PAIR  = 'https://api.dexscreener.com/latest/dex/pairs/solana/' . $MOOG_POOL_ADDR;

// ---------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------

function fetch_json_local(string $url, int $timeout = 15): array
{
    $ch = curl_init($url);
    $headers = [
        'User-Agent: MoogLifeLocal/1.0 (+http://localhost/mooglife)'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,

        // 👇 Localhost dev: skip SSL verification to avoid CA bundle issues
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Curl error calling {$url}: {$err}");
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP {$code} from {$url}. Body: {$body}");
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from {$url}: {$body}");
    }

    return $json;

    if ($body === false) {
        throw new RuntimeException("Curl error calling {$url}: {$err}");
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP {$code} from {$url}. Body: {$body}");
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from {$url}: {$body}");
    }

    return $json;
}

function jexit(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------
// 1) Pull MOOG data from DexScreener (token endpoint, then pair fallback)
// ---------------------------------------------------------------------

try {
    $pairs = [];

    // Try token endpoint first
    $dataToken = fetch_json_local($DEX_URL_TOKEN);
    if (!empty($dataToken['pairs']) && is_array($dataToken['pairs'])) {
        $pairs = $dataToken['pairs'];
        $source = 'token';
    } else {
        // Fallback: pair endpoint
        $dataPair = fetch_json_local($DEX_URL_PAIR);
        if (!empty($dataPair['pairs']) && is_array($dataPair['pairs'])) {
            $pairs = $dataPair['pairs'];
            $source = 'pair';
        }
    }

    if (empty($pairs)) {
        $sample1 = isset($dataToken) ? substr(json_encode($dataToken), 0, 240) : 'no token json';
        $sample2 = isset($dataPair)  ? substr(json_encode($dataPair),  0, 240) : 'no pair json';
        throw new RuntimeException("No pairs returned by DexScreener. token={$sample1} pair={$sample2}");
    }

    // Use first pair (main MOOG/SOL pool)
    $pair = $pairs[0];

    $priceUsd     = isset($pair['priceUsd'])           ? (float)$pair['priceUsd']           : 0.0;
    $priceNative  = isset($pair['priceNative'])        ? (float)$pair['priceNative']        : 0.0;
    $liquidityUsd = isset($pair['liquidity']['usd'])   ? (float)$pair['liquidity']['usd']   : 0.0;
    $fdvUsd       = isset($pair['fdv'])                ? (float)$pair['fdv']                : 0.0;
    $vol24Usd     = isset($pair['volume']['h24'])      ? (float)$pair['volume']['h24']      : 0.0;
    $change24Pct  = isset($pair['priceChange']['h24']) ? (float)$pair['priceChange']['h24'] : 0.0;

    $solPriceUsd = 0.0;
    if ($priceUsd > 0 && $priceNative > 0) {
        $solPriceUsd = $priceUsd / $priceNative;
    }

    // No separate circulating supply yet → mirror FDV into market_cap
    $marketCapUsd = $fdvUsd;
} catch (Throwable $e) {
    jexit([
        'ok'    => false,
        'step'  => 'dexscreener',
        'error' => $e->getMessage(),
    ], 500);
}

// ---------------------------------------------------------------------
// 2) Holder count from local mg_moog_holders
// ---------------------------------------------------------------------

try {
    $res = $db->query("SELECT COUNT(*) AS cnt FROM mg_moog_holders");
    $row = $res ? $res->fetch_assoc() : null;
    $holders = $row ? (int)$row['cnt'] : 0;
} catch (Throwable $e) {
    $holders = 0; // don’t fail entire sync for this
}

// ---------------------------------------------------------------------
// 3) Write to mg_market_cache (mysqli, upsert on token_symbol)
// ---------------------------------------------------------------------

try {
    $sql = "
        INSERT INTO mg_market_cache (
            token_symbol,
            token_mint,
            price_usd,
            market_cap_usd,
            fdv_usd,
            liquidity_usd,
            volume24h_usd,
            price_change_24h,
            holders,
            sol_price_usd,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
        ON DUPLICATE KEY UPDATE
            price_usd        = VALUES(price_usd),
            market_cap_usd   = VALUES(market_cap_usd),
            fdv_usd          = VALUES(fdv_usd),
            liquidity_usd    = VALUES(liquidity_usd),
            volume24h_usd    = VALUES(volume24h_usd),
            price_change_24h = VALUES(price_change_24h),
            holders          = VALUES(holders),
            sol_price_usd    = VALUES(sol_price_usd),
            updated_at       = VALUES(updated_at)
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

       // 2x string, 6x double, 1x int, 1x double  → 10 params
    $stmt->bind_param(
        'ssddddddid',
        $MOOG_SYMBOL,      // s
        $MOOG_TOKEN_MINT,  // s
        $priceUsd,         // d
        $marketCapUsd,     // d
        $fdvUsd,           // d
        $liquidityUsd,     // d
        $vol24Usd,         // d
        $change24Pct,      // d
        $holders,          // i
        $solPriceUsd       // d
    );


    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
} catch (Throwable $e) {
    jexit([
        'ok'    => false,
        'step'  => 'db_write',
        'error' => $e->getMessage(),
    ], 500);
}

// ---------------------------------------------------------------------
// 4) Done
// ---------------------------------------------------------------------

jexit([
    'ok'            => true,
    'source'        => 'dexscreener',
    'symbol'        => $MOOG_SYMBOL,
    'mint'          => $MOOG_TOKEN_MINT,
    'price_usd'     => $priceUsd,
    'fdv_usd'       => $fdvUsd,
    'liquidity_usd' => $liquidityUsd,
    'volume24h_usd' => $vol24Usd,
    'change24_pct'  => $change24Pct,
    'holders'       => $holders,
    'sol_price_usd' => $solPriceUsd,
    'rows'          => $affected ?? 0,
]);
