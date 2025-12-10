<?php
// mooglife/api/v1/index.php
// Public Moog API v1 - index + endpoint catalog.

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// Build base URL for this version
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

// dirname('/mooglife/api/v1/index.php') → '/mooglife/api/v1'
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php')), '/');
$baseUrl   = $scheme . '://' . $host . $scriptDir;

$endpoints = [
    [
        'path'        => '/market',
        'url'         => $baseUrl . '/market.php',
        'description' => 'MOOG market snapshot or history (mode=snapshot|history).',
        'params'      => [
            'mode'  => 'snapshot | history (history is PRO+/internal only for external callers)',
            'limit' => 'number of rows for history (tier-capped)',
        ],
    ],
    [
        'path'        => '/holders',
        'url'         => $baseUrl . '/holders.php',
        'description' => 'Top holders list, or single wallet profile.',
        'params'      => [
            'limit'  => 'max rows (tier-capped)',
            'wallet' => 'if set, returns just that wallet profile',
        ],
    ],
    [
        'path'        => '/tx',
        'url'         => $baseUrl . '/tx.php',
        'description' => 'Recent MOOG transactions with filters.',
        'params'      => [
            'limit'     => 'max rows (tier-capped)',
            'wallet'    => 'filter by wallet (from OR to)',
            'direction' => 'buy | sell',
            'source'    => 'raydium | birdeye_v3 | etc.',
        ],
    ],
    [
        'path'        => '/airdrops',
        'url'         => $baseUrl . '/airdrops.php',
        'description' => 'Raw airdrops or summary views.',
        'params'      => [
            'limit'   => 'max rows (tier-capped)',
            'wallet'  => 'filter by wallet',
            'summary' => '1 for summary mode (PRO+/internal only)',
        ],
    ],
    [
        'path'        => '/wallet',
        'url'         => $baseUrl . '/wallet.php',
        'description' => 'Combined wallet view: holder profile + tx + airdrops.',
        'params'      => [
            'wallet'    => 'required wallet address',
            'tx_limit'  => 'max tx rows (tier-capped)',
            'air_limit' => 'max airdrop rows (tier-capped)',
        ],
    ],
];

api_ok([
    'name'      => 'Moog Public API',
    'version'   => 'v1',
    'base_url'  => $baseUrl,
    'tier'      => moog_api_effective_tier(),
    'endpoints' => $endpoints,
]);
