<?php
// mooglife/api/system.php
// System / settings API: environment, DB info, table checks, last sync timestamps.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

// ----------------------------------------------------------
// Helper: check if a table exists
// ----------------------------------------------------------
function table_exists(mysqli $db, string $table): bool
{
    try {
        $safe = $db->real_escape_string($table);
        $res  = $db->query("SHOW TABLES LIKE '{$safe}'");
        if ($res && $res->num_rows > 0) {
            $res->close();
            return true;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore, treat as missing
    }
    return false;
}

// ----------------------------------------------------------
// Helper: get max timestamp from table if column exists
// ----------------------------------------------------------
function table_max_time(
    mysqli $db,
    string $table,
    array $candidateCols
): ?string {
    foreach ($candidateCols as $col) {
        try {
            $safeTable = $db->real_escape_string($table);
            $safeCol   = $db->real_escape_string($col);
            $res       = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
            if ($res && $res->num_rows > 0) {
                $res->close();
                // column exists: query max
                $sql = "SELECT MAX(`{$col}`) AS max_ts FROM `{$table}`";
                if (!$q = $db->query($sql)) {
                    return null;
                }
                $row = $q->fetch_assoc();
                $q->close();
                if (!empty($row['max_ts'])) {
                    return (string)$row['max_ts'];
                }
                return null;
            }
            if ($res) {
                $res->close();
            }
        } catch (Throwable $e) {
            // ignore and try next candidate
        }
    }
    return null;
}

// ----------------------------------------------------------
// Basic app info
// ----------------------------------------------------------
$appName    = 'Mooglife Local';
$appEnv     = 'local';
$appVersion = defined('MOOGLIFE_VERSION') ? MOOGLIFE_VERSION : 'dev-local';

$phpVersion = PHP_VERSION;
$os         = php_uname('s') . ' ' . php_uname('r');

// DB info
$dbName    = null;
$dbCharset = null;
$dbServer  = null;
$dbHost    = null;

try {
    if ($res = $db->query("SELECT DATABASE() AS db")) {
        $row = $res->fetch_assoc();
        $dbName = $row['db'] ?? null;
        $res->close();
    }
} catch (Throwable $e) {
    $dbName = null;
}

try {
    $dbCharset = $db->character_set_name();
} catch (Throwable $e) {
    $dbCharset = null;
}

try {
    $dbServer = $db->server_info;
    $dbHost   = $db->host_info;
} catch (Throwable $e) {
    $dbServer = null;
    $dbHost   = null;
}

// ----------------------------------------------------------
// Table existence checks
// ----------------------------------------------------------
$tables = [
    'mg_market_history'  => table_exists($db, 'mg_market_history'),
    'mg_market_cache'    => table_exists($db, 'mg_market_cache'),
    'mg_moog_holders'    => table_exists($db, 'mg_moog_holders'),
    'mg_moog_tx'         => table_exists($db, 'mg_moog_tx'),
    'mg_og_buyers'       => table_exists($db, 'mg_og_buyers'),
    'mg_moog_airdrops'   => table_exists($db, 'mg_moog_airdrops'),
    'moog_airdrops'      => table_exists($db, 'moog_airdrops'),
    'mg_og_rewards'      => table_exists($db, 'mg_og_rewards'),
    'og_rewards'         => table_exists($db, 'og_rewards'),
    'mg_ogrewards'       => table_exists($db, 'mg_ogrewards'),
    'mg_admin_users'     => table_exists($db, 'mg_admin_users'),
    'mg_settings'        => table_exists($db, 'mg_settings'),
];

// Choose actual airdrop / og rewards table names, if any
$airTable = null;
foreach (['mg_moog_airdrops', 'moog_airdrops'] as $t) {
    if ($tables[$t] ?? false) {
        $airTable = $t;
        break;
    }
}

$ogRewardsTable = null;
foreach (['mg_og_rewards', 'og_rewards', 'mg_ogrewards'] as $t) {
    if ($tables[$t] ?? false) {
        $ogRewardsTable = $t;
        break;
    }
}

// ----------------------------------------------------------
// Last sync timestamps
// ----------------------------------------------------------
$sync = [
    'market_last'   => null,
    'holders_last'  => null,
    'tx_last'       => null,
    'airdrops_last' => null,
    'og_rewards_last' => null,
];

if ($tables['mg_market_history']) {
    $sync['market_last'] = table_max_time($db, 'mg_market_history', ['created_at', 'updated_at']);
} elseif ($tables['mg_market_cache']) {
    $sync['market_last'] = table_max_time($db, 'mg_market_cache', ['updated_at', 'created_at']);
}

if ($tables['mg_moog_holders']) {
    $sync['holders_last'] = table_max_time($db, 'mg_moog_holders', ['updated_at', 'created_at']);
}

if ($tables['mg_moog_tx']) {
    $sync['tx_last'] = table_max_time($db, 'mg_moog_tx', ['block_time', 'created_at', 'seen_at']);
}

if ($airTable !== null) {
    $sync['airdrops_last'] = table_max_time($db, $airTable, ['created_at', 'updated_at', 'drop_time']);
}

if ($ogRewardsTable !== null) {
    $sync['og_rewards_last'] = table_max_time($db, $ogRewardsTable, ['created_at', 'reward_time', 'updated_at']);
}

// ----------------------------------------------------------
// Settings from mg_settings (if present)
// ----------------------------------------------------------
$settings = [];
if ($tables['mg_settings']) {
    try {
        $sql = "SELECT setting_key, setting_value FROM mg_settings ORDER BY setting_key ASC";
        if ($res = $db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $key   = (string)$row['setting_key'];
                $value = $row['setting_value'];
                $settings[$key] = $value;
            }
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore settings errors; they are optional
    }
}

// ----------------------------------------------------------
// Build response
// ----------------------------------------------------------
$data = [
    'app' => [
        'name'    => $appName,
        'env'     => $appEnv,
        'version' => $appVersion,
    ],
    'server' => [
        'php_version' => $phpVersion,
        'os'          => $os,
    ],
    'database' => [
        'name'     => $dbName,
        'charset'  => $dbCharset,
        'server'   => $dbServer,
        'host'     => $dbHost,
    ],
    'tables' => [
        'exists' => $tables,
        'airdrop_table'     => $airTable,
        'og_rewards_table'  => $ogRewardsTable,
    ],
    'sync' => $sync,
    'settings' => $settings,
];

api_ok($data);
