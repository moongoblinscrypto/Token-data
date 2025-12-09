<?php
// includes/db.php
// Central DB + helpers for Mooglife / GoblinsHQ.

/**
 * Main DB connection for GoblinsHQ / Mooglife.
 *
 * Uses constants from includes/config.php if defined:
 *   DB_HOST, DB_USER, DB_PASS, DB_NAME
 * Otherwise falls back to localhost / root / goblinshq.
 *
 * Declared inside function_exists guard so it won't redeclare
 * if this file is included multiple times.
 */

if (!function_exists('mg_db')) {

    function mg_db(): mysqli
    {
        static $db = null;

        if ($db instanceof mysqli) {
            return $db;
        }

        // Optional: load config if you use it for DB creds.
        $configPath = __DIR__ . '/config.php';
        if (file_exists($configPath)) {
            // This file can define DB_HOST, DB_USER, DB_PASS, DB_NAME constants.
            require_once $configPath;
        }

        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $name = defined('DB_NAME') ? DB_NAME : 'goblinshq';

        $db = @new mysqli($host, $user, $pass, $name);

        if ($db->connect_errno) {
            die(
                'Database connection failed: ' .
                htmlspecialchars($db->connect_error, ENT_QUOTES, 'UTF-8')
            );
        }

        $db->set_charset('utf8mb4');

        return $db;
    }

}

/**
 * Backwards-compat wrapper for older code that calls moog_db().
 */
if (!function_exists('moog_db')) {
    function moog_db(): mysqli
    {
        return mg_db();
    }
}
