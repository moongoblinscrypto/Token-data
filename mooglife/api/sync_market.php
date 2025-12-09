<?php
// mooglife/api/sync_market.php
// Local market sync using DexScreener + mg_moog_holders.
// Also appends each snapshot into mg_market_history so the chart
// can show a real line instead of a single dot.

header('Content-Type: application/json');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/settings.php';

$db = moog_db();

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------

$MOOG_SYMBOL     = ml_get_setting('token_symbol', 'MOOG');
$MOOG_TOKEN_MINT = ml_get_setting('token_mint', '9s4HkvcwVMmGuS98FWH7DAuqo93woicQsMwf4D6MjZwW');

// Raydium pool (can be moved into settings later)
$MOOG_POOL_ADDR  = ml_get_setting('raydium_pool', 'As3LVGczwcR4QZ9hQFCedV8gzQBiLutTXkCdZ8QGTaVz');

// DexScreener endpoints
$DEX_URL_TOKEN = 'https://api.dexscreener.com/latest/dex/tokens/' . $MOOG_TOKEN_MINT;
$DEX_URL_PAIR  = 'https://api.dexscreener.com/latest/dex/pairs/solana/' . $MOOG_POOL_ADDR;

// Sol price (CoinGecko)
$COINGECKO_SOL = 'https://api.coingecko.com/api/v3/simple/price?ids=solana&vs_currencies=usd';

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function jexit(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function fetch_json(string $url, int $timeout = 20): array
{
    $ch = curl_init($url);
    if (!$ch) {
        throw new RuntimeException('curl_init_failed');
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: Mooglife-Local/1.0',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        // Local dev: skip SSL verification if needed
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('curl_error: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('http_status_' . $code);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('json_decode_failed');
    }

    return $json;
}

// ---------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------

try {

    // 1) Fetch DexScreener data (token + pair, pick first pair we can)
    $dataToken = null;
    $dataPair  = null;
    $pairs     = [];

    try {
        $dataToken = fetch_json($DEX_URL_TOKEN, 20);
        if (!empty($dataToken['pairs']) && is_array($dataToken['pairs'])) {
            $pairs = $dataToken['pairs'];
        }
    } catch (Throwable $e) {
        // Soft fail, we might still get from pair endpoint
    }

    if (!$pairs) {
        try {
            $dataPair = fetch_json($DEX_URL_PAIR, 20);
            if (!empty($dataPair['pairs']) && is_array($dataPair['pairs'])) {
                $pairs = $dataPair['pairs'];
            }
        } catch (Throwable $e) {
            // We'll handle below if still no pairs
        }
    }

    if (!$pairs) {
        $sample1 = $dataToken ? substr(json_encode($dataToken), 0, 240) : 'no token json';
        $sample2 = $dataPair  ? substr(json_encode($dataPair),  0, 240) : 'no pair json';
        jexit([
            'ok'     => false,
            'error'  => 'no_pairs',
            'hint'   => 'DexScreener returned no pairs for token/pool',
            'token'  => $sample1,
            'pair'   => $sample2,
        ], 502);
    }

    // Use first pair (main MOOG/SOL pool)
    $pair = $pairs[0];

    $priceUsd     = isset($pair['priceUsd']) ? (float)$pair['priceUsd'] : 0.0;
    $fdvUsd       = isset($pair['fdv']) ? (float)$pair['fdv'] : 0.0;
    $liqUsd       = isset($pair['liquidity']['usd']) ? (float)$pair['liquidity']['usd'] : 0.0;
    $vol24Usd     = isset($pair['volume']['h24']) ? (float)$pair['volume']['h24'] : 0.0;
    $change24Pct  = isset($pair['priceChange']['h24']) ? (float)$pair['priceChange']['h24'] : 0.0;

    // If market_cap not available separately, use FDV as a proxy for now
    $marketCapUsd = $fdvUsd;

    // 2) Holder count from mg_moog_holders (if available)
    $holders = 0;
    try {
        $res = $db->query("SELECT COUNT(*) AS cnt FROM mg_moog_holders");
        if ($res) {
            $row = $res->fetch_assoc();
            if ($row && isset($row['cnt'])) {
                $holders = (int)$row['cnt'];
            }
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore, holders stay 0
    }

    // 3) SOL price from CoinGecko
    $solPriceUsd = 0.0;
    try {
        $cg = fetch_json($COINGECKO_SOL, 10);
        if (isset($cg['solana']['usd'])) {
            $solPriceUsd = (float)$cg['solana']['usd'];
        }
    } catch (Throwable $e) {
        // ignore, solPriceUsd stays 0
    }

    // 4) Ensure history table exists
    $sqlHistoryTable = "
        CREATE TABLE IF NOT EXISTS mg_market_history (
          id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          token_symbol     VARCHAR(16)  NOT NULL,
          token_mint       VARCHAR(64)  NOT NULL,
          price_usd        DECIMAL(30,10) DEFAULT NULL,
          market_cap_usd   DECIMAL(30,2)  DEFAULT NULL,
          fdv_usd          DECIMAL(30,2)  DEFAULT NULL,
          liquidity_usd    DECIMAL(30,2)  DEFAULT NULL,
          volume24h_usd    DECIMAL(30,2)  DEFAULT NULL,
          price_change_24h DECIMAL(10,4)  DEFAULT NULL,
          holders          INT UNSIGNED   DEFAULT NULL,
          sol_price_usd    DECIMAL(30,2)  DEFAULT NULL,
          created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_symbol_created (token_symbol, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->query($sqlHistoryTable);

    // 5) Upsert into mg_market_cache (single current snapshot)
    $affected = 0;

    $sqlCache = "
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

    $stmt = $db->prepare($sqlCache);
    if (!$stmt) {
        jexit([
            'ok'    => false,
            'error' => 'prepare_cache_failed',
            'msg'   => $db->error,
        ], 500);
    }

    $stmt->bind_param(
        'ssddddddid',
        $MOOG_SYMBOL,
        $MOOG_TOKEN_MINT,
        $priceUsd,
        $marketCapUsd,
        $fdvUsd,
        $liqUsd,
        $vol24Usd,
        $change24Pct,
        $holders,
        $solPriceUsd
    );

    if (!$stmt->execute()) {
        $msg = $stmt->error;
        $stmt->close();
        jexit([
            'ok'    => false,
            'error' => 'execute_cache_failed',
            'msg'   => $msg,
        ], 500);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    // 6) Append row into mg_market_history for chart
    $historyId = null;
    $sqlHist = "
        INSERT INTO mg_market_history (
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
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ";

    $stmt2 = $db->prepare($sqlHist);
    if ($stmt2) {
        $stmt2->bind_param(
            'ssddddddid',
            $MOOG_SYMBOL,
            $MOOG_TOKEN_MINT,
            $priceUsd,
            $marketCapUsd,
            $fdvUsd,
            $liqUsd,
            $vol24Usd,
            $change24Pct,
            $holders,
            $solPriceUsd
        );
        if ($stmt2->execute()) {
            $historyId = $stmt2->insert_id;
        }
        $stmt2->close();
    }

    // 7) Done
    jexit([
        'ok'            => true,
        'source'        => 'dexscreener',
        'symbol'        => $MOOG_SYMBOL,
        'mint'          => $MOOG_TOKEN_MINT,
        'price_usd'     => $priceUsd,
        'fdv_usd'       => $fdvUsd,
        'market_cap'    => $marketCapUsd,
        'liquidity_usd' => $liqUsd,
        'volume24h_usd' => $vol24Usd,
        'change24_pct'  => $change24Pct,
        'holders'       => $holders,
        'sol_price_usd' => $solPriceUsd,
        'rows'          => $affected,
        'history_id'    => $historyId,
    ]);

} catch (Throwable $e) {
    jexit([
        'ok'    => false,
        'error' => 'exception',
        'msg'   => $e->getMessage(),
    ], 500);
}
