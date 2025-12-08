<?php
// mooglife/cron/cron_sync_all.php
// Run all sync jobs (market, holders, tx) and log into mg_sync_log.
//
// You can call this from:
//   - Browser: http://localhost/mooglife/cron/cron_sync_all.php
//   - CLI:     php cron_sync_all.php

declare(strict_types=1);

header('Content-Type: application/json');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php'; // moog_db()

$db = moog_db();

/**
 * Base URL for your Mooglife API.
 *
 * LOCAL WAMP:
 *   http://localhost/mooglife
 *
 * WHEN YOU MOVE TO VPS:
 *   Change this to https://moongoblins.net/mooglife
 */
$BASE_URL = 'http://localhost/mooglife';

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------

function call_api(string $base, string $path): array
{
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 60,
            'ignore_errors' => true, // <- IMPORTANT: return body even on HTTP 4xx/5xx
        ]
    ]);

    $start = microtime(true);
    $raw   = @file_get_contents($url, false, $ctx);
    $end   = microtime(true);

    $durationMs = (int) round(($end - $start) * 1000);
    $statusLine = $http_response_header[0] ?? '';
    $code       = 0;

    if ($statusLine) {
        // parse "HTTP/1.1 500 Internal Server Error"
        if (preg_match('~\s(\d{3})\s~', $statusLine, $m)) {
            $code = (int)$m[1];
        }
    }

    if ($raw === false) {
        return [
            'ok'          => false,
            'error'       => 'http_failed',
            'msg'         => 'Failed to call '.$url,
            'status'      => $statusLine ?: 'no status',
            'raw'         => null,
            'duration_ms' => $durationMs,
        ];
    }

    $json = json_decode($raw, true);

    // If JSON decoded and looks like our normal API response, return it + HTTP info
    if (is_array($json)) {
        if (!isset($json['ok']) && $code >= 400) {
            // error JSON that didn't set ok; mark it
            $json['ok']    = false;
            $json['error'] = $json['error'] ?? 'http_'.$code;
        }
        $json['http_status'] = $statusLine;
        $json['http_code']   = $code;
        $json['duration_ms'] = $json['duration_ms'] ?? $durationMs;
        return $json;
    }

    // Non-JSON body with an HTTP error
    if ($code >= 400) {
        return [
            'ok'          => false,
            'error'       => 'bad_json_http_'.$code,
            'msg'         => 'Invalid JSON from '.$url,
            'status'      => $statusLine,
            'raw'         => $raw,
            'duration_ms' => $durationMs,
        ];
    }

    // Non-JSON but 2xx – weird, still mark as error
    return [
        'ok'          => false,
        'error'       => 'bad_json',
        'msg'         => 'Invalid JSON from '.$url,
        'status'      => $statusLine,
        'raw'         => $raw,
        'duration_ms' => $durationMs,
    ];
}

// Log the result of each sync job into mg_sync_log
function log_sync(mysqli $db, string $job, array $result): void
{
    // 7 columns, 6 placeholders + NOW() = 7 values
    $sql = "
        INSERT INTO mg_sync_log (
            job,
            ok,
            step,
            message,
            payload_json,
            duration_ms,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW()
        )
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        // If logging fails, just echo and bail out (don’t break the cron)
        echo "log_sync prepare() failed: " . $db->error . PHP_EOL;
        return;
    }

    // Normalize fields from $result
    $okInt      = !empty($result['ok']) ? 1 : 0;
    $step       = $result['step']      ?? null;
    $message    = $result['error']
               ?? $result['msg']
               ?? null;
    $payload    = json_encode(
        $result,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $durationMs = isset($result['duration_ms']) ? (int)$result['duration_ms'] : null;

    // s i s s s i  => job, ok, step, message, payload_json, duration_ms
    $stmt->bind_param(
        "sisssi",
        $job,
        $okInt,
        $step,
        $message,
        $payload,
        $durationMs
    );

    $stmt->execute();
    $stmt->close();
}


// ---------------------------------------------------------
// Run jobs
// ---------------------------------------------------------

$results = [];
$jobs    = [
    'market'  => 'api/sync_market.php',
    'holders' => 'api/sync_holders.php',
    'tx'      => 'api/sync_tx.php',
];

foreach ($jobs as $job => $path) {
    $res = call_api($BASE_URL, $path);
    log_sync($db, $job, $res);
    $results[$job] = $res;
}

// Also log a meta "all" job summarizing everything.
// Treat Birdeye 429 for tx as a "soft" OK for the overall job
$txOk = !empty($results['tx']['ok']);

if (
    !$txOk &&
    isset($results['tx']['error']) &&
    strpos((string)$results['tx']['error'], '429') !== false
) {
    // still log the tx error itself, but don't fail the whole cron run
    $txOk = true;
}

$allOk = (
    !empty($results['market']['ok']) &&
    !empty($results['holders']['ok']) &&
    $txOk
);

log_sync($db, 'all', [
    'ok'  => $allOk,
    'msg' => 'cron_sync_all complete'
]);


// Output summary
echo json_encode([
    'ok'      => $allOk,
    'results' => $results,
], JSON_PRETTY_PRINT);
