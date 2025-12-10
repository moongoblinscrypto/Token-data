<?php
// mooglife/api/bootstrap.php
// Common bootstrap for all Moog API endpoints.
// Now with API key tiers + daily limits, but still friendly for localhost.

declare(strict_types=1);

// -------------------------
// Basic JSON / CORS headers
// -------------------------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-Key, Authorization');

// Handle CORS preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -------------------------
// Core includes
// -------------------------
require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/helpers.php')) {
    require_once __DIR__ . '/../includes/helpers.php';
}

// Global DB connection
$db = mg_db();

// -------------------------
// Response helpers
// -------------------------
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
        'ok'    => false,
        'error' => $message,
    ];
    if ($extra !== null) {
        $payload['meta'] = $extra;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read a query param with default.
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

// -------------------------
// Internal helpers
// -------------------------

/**
 * Read all HTTP headers in a case-insensitive way.
 *
 * @return array<string,string>
 */
function moog_api_all_headers(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
    } else {
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
    }

    return $headers;
}

/**
 * Get a header by name (case-insensitive).
 */
function moog_api_get_header(string $name): ?string
{
    $headers = moog_api_all_headers();
    $key = strtolower($name);
    return $headers[$key] ?? null;
}

/**
 * Load mg_settings into key => value map, if table exists.
 *
 * @param mysqli $db
 * @return array<string,string>
 */
function moog_api_load_settings(mysqli $db): array
{
    $settings = [];

    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_settings'");
        if (!$res || $res->num_rows === 0) {
            if ($res) {
                $res->close();
            }
            return $settings;
        }
        $res->close();

        $sql = "SELECT setting_key, setting_value FROM mg_settings";
        if ($r = $db->query($sql)) {
            while ($row = $r->fetch_assoc()) {
                $k = (string)$row['setting_key'];
                $v = (string)$row['setting_value'];
                $settings[$k] = $v;
            }
            $r->close();
        }
    } catch (Throwable $e) {
        // settings are optional; ignore any error
    }

    return $settings;
}

/**
 * Check whether mg_api_keys table exists.
 */
function moog_api_keys_table_exists(mysqli $db): bool
{
    try {
        $res = $db->query("SHOW TABLES LIKE 'mg_api_keys'");
        if ($res && $res->num_rows > 0) {
            $res->close();
            return true;
        }
        if ($res) {
            $res->close();
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

/**
 * Get default per-tier daily limit if not overridden in DB.
 *
 * @param string $tier
 * @return int|null  null = unlimited
 */
function moog_api_default_daily_limit(string $tier): ?int
{
    switch (strtolower($tier)) {
        case 'free':
            // e.g. 1,000 requests/day
            return 1000;
        case 'pro':
            // e.g. 50,000 requests/day
            return 50000;
        case 'internal':
        case 'admin':
            return null; // unlimited
        default:
            return 1000;
    }
}

/**
 * Authenticate API key (if provided/required), enforce daily limits.
 *
 * Returns array of key data or null if key not required and not provided.
 *
 * @param mysqli $db
 * @param bool   $requireKey
 * @return array<string,mixed>|null
 */
function moog_api_authenticate(mysqli $db, bool $requireKey): ?array
{
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    // Pull API key from headers or query param
    $keyFromHeader = moog_api_get_header('x-api-key');
    if (!$keyFromHeader) {
        // Optional: support "Authorization: Bearer KEY"
        $authHeader = moog_api_get_header('authorization');
        if ($authHeader && str_starts_with(strtolower(trim($authHeader)), 'bearer ')) {
            $keyFromHeader = trim(substr($authHeader, 7));
        }
    }
    $keyFromQuery = api_get('api_key', '');

    $apiKey = trim((string)($keyFromHeader ?: $keyFromQuery));

    // If no key provided and not required, just treat as anonymous access.
    if ($apiKey === '' && !$requireKey) {
        return null;
    }

    // If key is required but mg_api_keys table doesn't exist: config error.
    if (!moog_api_keys_table_exists($db)) {
        api_error(
            'API key system not initialized (mg_api_keys table missing).',
            500,
            ['hint' => 'Create mg_api_keys and add at least one key.']
        );
    }

    // Lookup key
    $sql = "
        SELECT
            id,
            api_key,
            label,
            owner_user_id,
            tier,
            status,
            daily_limit,
            allowed_ips,
            requests_today,
            day_window_start,
            created_at,
            last_used_at
        FROM mg_api_keys
        WHERE api_key = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing API key query', 500, $db->error);
    }
    $stmt->bind_param('s', $apiKey);
    $stmt->execute();
    $res  = $stmt->get_result();
    $row  = $res->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row || strtolower($row['status'] ?? '') !== 'active') {
        api_error('Invalid or inactive API key', 401);
    }

    // Optional IP restriction
    $allowedIps = trim((string)($row['allowed_ips'] ?? ''));
    if ($allowedIps !== '') {
        $list = array_filter(array_map('trim', explode(',', $allowedIps)));
        if ($remoteIp !== '' && !in_array($remoteIp, $list, true)) {
            api_error('API key not allowed from this IP', 403, ['ip' => $remoteIp]);
        }
    }

    // Daily limit enforcement
    $today     = date('Y-m-d');
    $tier      = $row['tier'] ?: 'free';
    $dbLimit   = is_null($row['daily_limit']) ? null : (int)$row['daily_limit'];
    $defLimit  = moog_api_default_daily_limit($tier);
    $limit     = $dbLimit !== null ? $dbLimit : $defLimit; // final daily limit (null = unlimited)

    $requestsToday   = (int)($row['requests_today'] ?? 0);
    $dayWindowStart  = (string)($row['day_window_start'] ?? '');

    // Reset counters if it's a new day
    if ($dayWindowStart !== $today) {
        $requestsToday  = 0;
        $dayWindowStart = $today;
    }

    if ($limit !== null && $requestsToday >= $limit) {
        api_error(
            'Daily API request limit reached for this key',
            429,
            [
                'tier'            => $tier,
                'limit'           => $limit,
                'requests_today'  => $requestsToday,
                'day_window_date' => $dayWindowStart,
            ]
        );
    }

    // Increment usage
    $requestsToday++;
    $now = date('Y-m-d H:i:s');

    $upd = "
        UPDATE mg_api_keys
        SET requests_today = ?,
            day_window_start = ?,
            last_used_at = ?
        WHERE id = ?
        LIMIT 1
    ";
    $stmt2 = $db->prepare($upd);
    if ($stmt2) {
        $id = (int)$row['id'];
        $stmt2->bind_param('issi', $requestsToday, $dayWindowStart, $now, $id);
        $stmt2->execute();
        $stmt2->close();
    }

    // Build a sanitized auth context
    return [
        'id'               => (int)$row['id'],
        'label'            => (string)$row['label'],
        'owner_user_id'    => is_null($row['owner_user_id']) ? null : (int)$row['owner_user_id'],
        'tier'             => $tier,
        'status'           => (string)$row['status'],
        'daily_limit'      => $limit,
        'requests_today'   => $requestsToday,
        'day_window_start' => $dayWindowStart,
    ];
}

/**
 * Get current API auth context (or null).
 *
 * @return array<string,mixed>|null
 */
function moog_api_current_key()
{
    return $GLOBALS['MOOG_API_AUTH'] ?? null;
}

// -------------------------
// API key enforcement logic
// -------------------------

$settings   = moog_api_load_settings($db);
$remoteIp   = $_SERVER['REMOTE_ADDR'] ?? '';
$envIsLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);

// Default behavior:
// - On localhost: API keys are optional (for development).
// - On non-localhost: keys are required only if mg_settings.api_require_key = '1'.
$requireKey = false;
if (!$envIsLocal && !empty($settings['api_require_key']) && $settings['api_require_key'] === '1') {
    $requireKey = true;
}

// Authenticate (if key present or required) and expose global context
$GLOBALS['MOOG_API_AUTH'] = moog_api_authenticate($db, $requireKey);
