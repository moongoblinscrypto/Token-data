<?php
// mooglife/install.php
// One-shot installer for GoblinsHQ / Mooglife DB schema (goblinshq or custom).

error_reporting(E_ALL);
ini_set('display_errors', 1);

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$results = [];
$error   = '';
$success = false;

if ($method === 'POST') {
    $db_host    = trim($_POST['db_host'] ?? 'localhost');
    $db_user    = trim($_POST['db_user'] ?? 'root');
    $db_pass    = $_POST['db_pass'] ?? '';
    $db_name    = trim($_POST['db_name'] ?? 'goblinshq');
    $create_db  = isset($_POST['create_db']) ? true : false;

    if ($db_host === '' || $db_user === '' || $db_name === '') {
        $error = 'Host, user, and database name are required.';
    } else {
        // 1) Connect (server level)
        $conn = @new mysqli($db_host, $db_user, $db_pass);
        if ($conn->connect_error) {
            $error = 'Connection failed: ' . $conn->connect_error;
        } else {
            // 2) Optionally create DB
            if ($create_db) {
                $dbNameEsc = $conn->real_escape_string($db_name);
                if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                    $error = 'Failed to create database `' . h($db_name) . '`: ' . $conn->error;
                }
            }

            if ($error === '') {
                // 3) Select DB
                if (!$conn->select_db($db_name)) {
                    $error = 'Failed to select database `' . h($db_name) . '`: ' . $conn->error;
                } else {
                    // 4) Schema (CREATE TABLE IF NOT EXISTS …)
                    $schemaSql = <<<SQL
-- Core widget registry
CREATE TABLE IF NOT EXISTS mg_admin_widgets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section     VARCHAR(32)  NOT NULL,
    slug        VARCHAR(64)  NOT NULL,
    title       VARCHAR(100) NOT NULL,
    is_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Market cache (DexScreener snapshots)
CREATE TABLE IF NOT EXISTS mg_market_cache (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_symbol     VARCHAR(16)  NOT NULL,
    token_mint       VARCHAR(64)  NOT NULL,
    price_usd        DECIMAL(30,9)   DEFAULT NULL,
    market_cap_usd   DECIMAL(30,2)   DEFAULT NULL,
    fdv_usd          DECIMAL(30,2)   DEFAULT NULL,
    liquidity_usd    DECIMAL(30,2)   DEFAULT NULL,
    volume24h_usd    DECIMAL(30,2)   DEFAULT NULL,
    price_change_24h DECIMAL(10,4)   DEFAULT NULL,
    holders          INT UNSIGNED    DEFAULT NULL,
    sol_price_usd    DECIMAL(20,4)   DEFAULT NULL,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token_symbol (token_symbol),
    KEY idx_token_mint (token_mint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top MOOG holders (Birdeye)
CREATE TABLE IF NOT EXISTS mg_moog_holders (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    wallet        VARCHAR(64)     NOT NULL,
    raw_amount    DECIMAL(40,0)   NOT NULL,
    ui_amount     DECIMAL(30,9)   NOT NULL,
    percent       DECIMAL(10,6)   DEFAULT NULL,
    token_account VARCHAR(64)     DEFAULT NULL,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_wallet (wallet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OG buyers snapshot
CREATE TABLE IF NOT EXISTS mg_moog_og_buyers (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    wallet          VARCHAR(64)    NOT NULL,
    first_buy_time  DATETIME       NOT NULL,
    first_buy_amount DECIMAL(30,9) NOT NULL,
    total_bought    DECIMAL(30,9)  NOT NULL,
    total_sold      DECIMAL(30,9)  NOT NULL,
    current_balance DECIMAL(30,9)  NOT NULL,
    buy_tx_count    INT UNSIGNED   NOT NULL,
    sell_tx_count   INT UNSIGNED   NOT NULL,
    is_eligible     TINYINT(1)     NOT NULL DEFAULT 1,
    og_tier         INT            NOT NULL DEFAULT 0,
    label_tags      VARCHAR(255)   DEFAULT NULL,
    exclude_reason  VARCHAR(255)   DEFAULT NULL,
    notes           TEXT           DEFAULT NULL,
    snapshot_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_wallet (wallet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OG rewards table
CREATE TABLE IF NOT EXISTS mg_moog_og_rewards (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    wallet         VARCHAR(64)    NOT NULL,
    planned_amount DECIMAL(30,9)  NOT NULL,
    tx_hash        VARCHAR(120)   DEFAULT NULL,
    status         ENUM('PENDING','SENT','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    notes          TEXT           DEFAULT NULL,
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_wallet (wallet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MOOG TX history (Birdeye swaps)
CREATE TABLE IF NOT EXISTS mg_moog_tx (
    id          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    tx_hash     VARCHAR(120)   NOT NULL,
    block_time  DATETIME       NOT NULL,
    from_wallet VARCHAR(64)    NOT NULL,
    to_wallet   VARCHAR(64)    NOT NULL,
    amount_moog DECIMAL(30,9)  NOT NULL,
    direction   ENUM('BUY','SELL','TRANSFER') NOT NULL,
    source      VARCHAR(50)    NOT NULL,
    created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tx_hash (tx_hash),
    KEY idx_block_time (block_time),
    KEY idx_from_wallet (from_wallet),
    KEY idx_to_wallet (to_wallet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet metadata / labels
CREATE TABLE IF NOT EXISTS mg_moog_wallets (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    wallet          VARCHAR(64)    NOT NULL,
    label           VARCHAR(100)   DEFAULT NULL,
    type            ENUM('LP_DEV_HOLD','DEV_TREASURY','AIRDROP','EXCHANGE','USER','PROGRAM','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    tags            VARCHAR(255)   DEFAULT NULL,
    socials_x       VARCHAR(80)    DEFAULT NULL,
    socials_discord VARCHAR(80)    DEFAULT NULL,
    socials_telegram VARCHAR(80)   DEFAULT NULL,
    socials_notes   TEXT           DEFAULT NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_wallet (wallet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync log (cron + manual)
CREATE TABLE IF NOT EXISTS mg_sync_log (
    id           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    job          VARCHAR(32)    NOT NULL,
    ok           TINYINT(1)     NOT NULL,
    step         VARCHAR(64)    DEFAULT NULL,
    message      TEXT           DEFAULT NULL,
    payload_json TEXT           DEFAULT NULL,
    duration_ms  INT UNSIGNED   DEFAULT NULL,
    created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_job (job),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Airdrop log used by holders.php
CREATE TABLE IF NOT EXISTS moog_airdrops (
    id             INT          NOT NULL AUTO_INCREMENT,
    wallet_address VARCHAR(128) NOT NULL,
    amount         BIGINT       NOT NULL,
    source         VARCHAR(32)  DEFAULT NULL,
    name           VARCHAR(128) DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wallet_address (wallet_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings (used by includes/settings.php)
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT         DEFAULT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Social / X post bank
CREATE TABLE IF NOT EXISTS x_posts (
    id           INT           NOT NULL AUTO_INCREMENT,
    body         TEXT          NOT NULL,
    category     VARCHAR(50)   DEFAULT NULL,
    tags         VARCHAR(255)  DEFAULT NULL,
    is_active    TINYINT(1)    DEFAULT 1,
    times_used   INT           DEFAULT 0,
    last_used_at DATETIME      DEFAULT NULL,
    created_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    notes        VARCHAR(255)  DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_category (category),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

                    $statements = preg_split('/;[\r\n]+/', $schemaSql);
                    foreach ($statements as $sql) {
                        $sql = trim($sql);
                        if ($sql === '' || strpos($sql, '--') === 0) {
                            continue;
                        }
                        $ok = $conn->query($sql);
                        $results[] = [
                            'sql'   => $sql,
                            'ok'    => (bool)$ok,
                            'error' => $ok ? '' : $conn->error,
                        ];
                    }

                    // Seed some core settings (safe with PRIMARY KEY)
                    $seedSettings = [
                        'site_name'     => 'Mooglife Local / GoblinsHQ',
                        'token_symbol'  => 'MOOG',
                        'token_mint'    => '',
                        'token_decimals'=> '9',
                        'birdeye_api_key' => '',
                        'sync_enabled'  => '1',
                    ];
                    foreach ($seedSettings as $k => $v) {
                        $kEsc = $conn->real_escape_string($k);
                        $vEsc = $conn->real_escape_string($v);
                        $sql  = "INSERT INTO system_settings (setting_key, setting_value)
                                 VALUES ('{$kEsc}','{$vEsc}')
                                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)";
                        $conn->query($sql);
                    }

                    $success = true;
                }
            }

            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mooglife / GoblinsHQ Installer</title>
    <style>
        body {
            background: #020617;
            color: #e5e7eb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 40px 20px;
        }
        .wrap {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            margin-top: 0;
            font-size: 26px;
        }
        .card {
            background: #020617;
            border: 1px solid #1f2937;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #374151;
            background: #020617;
            color: #e5e7eb;
            margin-bottom: 12px;
        }
        .checkbox-row {
            margin-bottom: 12px;
            font-size: 14px;
        }
        button {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            background: #22c55e;
            color: #022c22;
            font-weight: 600;
            font-size: 14px;
        }
        .error {
            background: #7f1d1d;
            border: 1px solid #b91c1c;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .success {
            background: #064e3b;
            border: 1px solid #16a34a;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        pre.sql {
            background: #020617;
            border: 1px solid #111827;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        table.results {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        table.results th,
        table.results td {
            border: 1px solid #1f2937;
            padding: 6px 8px;
            vertical-align: top;
        }
        table.results th {
            background: #020617;
        }
        .ok {
            color: #22c55e;
            font-weight: 600;
        }
        .fail {
            color: #f97316;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Mooglife / GoblinsHQ – Installer</h1>

    <div class="card">
        <p style="font-size:14px;line-height:1.5;">
            This script will create all core tables (<code>mg_moog_*</code>, <code>mg_market_cache</code>,
            <code>system_settings</code>, <code>x_posts</code>, <code>moog_airdrops</code>) in the
            database you specify. It is safe to run multiple times; existing tables are kept.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php elseif ($success): ?>
        <div class="success">
            Database install completed for <strong><?php echo h($db_name); ?></strong>.
            Scroll down to see per-statement results.
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <label>
                DB Host
                <input type="text" name="db_host" value="<?php echo h($_POST['db_host'] ?? 'localhost'); ?>">
            </label>

            <label>
                DB User
                <input type="text" name="db_user" value="<?php echo h($_POST['db_user'] ?? 'root'); ?>">
            </label>

            <label>
                DB Password
                <input type="password" name="db_pass" value="<?php echo h($_POST['db_pass'] ?? ''); ?>">
            </label>

            <label>
                DB Name
                <input type="text" name="db_name" value="<?php echo h($_POST['db_name'] ?? 'goblinshq'); ?>">
            </label>

            <div class="checkbox-row">
                <label>
                    <input type="checkbox" name="create_db" <?php echo isset($_POST['create_db']) ? 'checked' : ''; ?>>
                    Create database if it does not exist
                </label>
            </div>

            <button type="submit">Run Install</button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <div class="card">
            <h2 style="margin-top:0;font-size:18px;">Execution Results</h2>
            <table class="results">
                <thead>
                <tr>
                    <th style="width:60px;">OK?</th>
                    <th>Statement</th>
                    <th style="width:220px;">Error</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="<?php echo $r['ok'] ? 'ok' : 'fail'; ?>">
                            <?php echo $r['ok'] ? 'OK' : 'FAIL'; ?>
                        </td>
                        <td><pre class="sql"><?php echo h($r['sql']); ?></pre></td>
                        <td><?php echo h($r['error']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
