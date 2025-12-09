<?php
// mooglife/api/bootstrap.php
// Common bootstrap for all Moog API endpoints.

declare(strict_types=1);

// Basic JSON headers
header('Content-Type: application/json; charset=utf-8');
// For now open to all; later you can lock this down or add API keys.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-Key');

// Handle CORS preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Pull in your DB + helpers
require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/helpers.php')) {
    require_once __DIR__ . '/../includes/helpers.php';
}

// Get shared DB connection
$db = mg_db();

/**
 * Send a JSON response and exit.
 *
 * @param mixed $data
 * @param int   $status
 */
function api_ok($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok'   => true,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error and exit.
 *
 * @param string $message
 * @param int    $status
 * @param mixed  $extra
 */
function api_error(string $message, int $status = 400, $extra = null): void
{
    http_response_code($status);
    $payload = [
        'ok'      => false,
        'error'   => $message,
    ];
    if ($extra !== null) {
        $payload['meta'] = $extra;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Helper to read a query param with default.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function api_get(string $key, $default = null)
{
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
    }
    return $default;
}
