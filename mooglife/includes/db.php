<?php
// includes/db.php
// Central DB + helpers for Mooglife / GoblinsHQ.

/**
 * Main DB connection for GoblinsHQ.
 *
 * @return mysqli
 */
function mg_db(): mysqli
{
    static $conn;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $host = 'localhost';
    $user = 'root';
    $pass = '';           // default WAMP
    $name = 'goblinshq';

    $conn = new mysqli($host, $user, $pass, $name);

    if ($conn->connect_error) {
        die('DB connection failed: ' . $conn->connect_error);
    }

    // Charset for emojis / special chars
    $conn->set_charset('utf8mb4');

    return $conn;
}

/**
 * Backwards-compatible alias.
 * Some scripts call moog_db(), some mg_db().
 */
function moog_db(): mysqli
{
    return mg_db();
}

/**
 * Render a clickable wallet link that goes to the wallet profile.
 *
 * Usage:
 *   echo wallet_link($row['wallet']);                            // raw address
 *   echo wallet_link($row['wallet'], $row['label'] ?? null, true); // use label or short addr
 *
 * @param string      $wallet  Full wallet address
 * @param string|null $label   Optional label to show instead of raw address
 * @param bool        $short   If true, shorten the address when no label
 *
 * @return string HTML <a> tag
 */
function wallet_link(string $wallet, ?string $label = null, bool $short = false): string
{
    $wallet = trim($wallet);

    // Decide display text
    if ($label !== null && $label !== '') {
        $display = $label;
    } elseif ($short) {
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
