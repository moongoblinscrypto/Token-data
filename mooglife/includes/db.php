<?php
// includes/db.php
// Central DB + helpers for Mooglife / GoblinsHQ.

// Optional: load config if you use it for DB creds.
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    // This file can define DB_HOST, DB_USER, DB_PASS, DB_NAME constants.
    require_once $configPath;
}

/**
 * Main DB connection for GoblinsHQ.
 *
 * Uses constants from includes/config.php if defined:
 *   DB_HOST, DB_USER, DB_PASS, DB_NAME
 * Otherwise falls back to localhost / root / goblinshq.
 *
 * @return mysqli
 */
function mg_db(): mysqli
{
    static $conn;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $name = defined('DB_NAME') ? DB_NAME : 'goblinshq';

    $conn = @new mysqli($host, $user, $pass, $name);

    if ($conn->connect_error) {
        // Fail hard in local environment so problems are obvious.
        die('DB connection failed: ' . htmlspecialchars($conn->connect_error, ENT_QUOTES, 'UTF-8'));
    }

    // UTF-8 everywhere
    if (!$conn->set_charset('utf8mb4')) {
        // Not fatal, but log it.
        error_log('Failed to set MySQL charset to utf8mb4: ' . $conn->error);
    }

    return $conn;
}

/**
 * Backwards-compatible alias.
 * Some scripts call moog_db(), but mg_db() is the real implementation.
 *
 * Do NOT define this twice.
 *
 * @return mysqli
 */
if (!function_exists('moog_db')) {
    function moog_db(): mysqli
    {
        return mg_db();
    }
}

/**
 * Helper: render a clickable wallet link to the wallet profile page.
 *
 * @param string      $wallet  Full wallet address
 * @param string|null $label   Optional label (if provided and non-empty, used as display)
 * @param bool        $short   If true, shorten the wallet (Fses...8aug) when no label
 *
 * @return string HTML <a> tag with <code>...</code> inside.
 */
if (!function_exists('wallet_link')) {
    function wallet_link(string $wallet, ?string $label = null, bool $short = false): string
    {
        $wallet = trim($wallet);

        if ($label !== null && $label !== '') {
            $display = $label;
        } elseif ($short && strlen($wallet) > 8) {
            // e.g. Fses...8aug
            $display = substr($wallet, 0, 4) . '...' . substr($wallet, -4);
        } else {
            $display = $wallet;
        }

        $href = '?p=wallet&wallet=' . urlencode($wallet);

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="wallet-link">'
             . '<code>' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</code>'
             . '</a>';
    }
}
