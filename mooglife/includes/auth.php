<?php
// mooglife/includes/auth.php
// Session + admin auth helpers. Safe against multiple includes.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!function_exists('mg_auth_db')) {

    /**
     * Wrapper around mg_db() so auth code has its own entry point.
     */
    function mg_auth_db(): mysqli
    {
        return mg_db();
    }

    /**
     * Check if mg_admin_users table exists.
     */
    function mg_admin_table_exists(): bool
    {
        static $checked = false;
        static $exists  = false;

        if ($checked) {
            return $exists;
        }

        $checked = true;

        try {
            $db  = mg_auth_db();
            $res = $db->query("SHOW TABLES LIKE 'mg_admin_users'");
            if ($res) {
                $exists = ($res->num_rows > 0);
                $res->close();
            } else {
                $exists = false;
            }
        } catch (Throwable $e) {
            $exists = false;
        }

        return $exists;
    }

    /**
     * Session timeout (seconds).
     */
    function mg_session_ttl_seconds(): int
    {
        // 60 minutes idle timeout
        return 3600;
    }

    /**
     * Is an admin user currently logged in (and not timed out)?
     */
    function mg_is_logged_in(): bool
    {
        if (!mg_admin_table_exists()) {
            // No admin table yet = no auth
            return false;
        }

        if (empty($_SESSION['admin_user_id'])) {
            return false;
        }

        $now = time();
        $ttl = mg_session_ttl_seconds();

        if (isset($_SESSION['admin_last_activity'])) {
            $diff = $now - (int)$_SESSION['admin_last_activity'];
            if ($diff > $ttl) {
                mg_logout_user();
                return false;
            }
        }

        // Touch last activity
        $_SESSION['admin_last_activity'] = $now;

        return true;
    }

    /**
     * Get the current admin user row (or null).
     */
    function mg_current_user(): ?array
    {
        static $cached = null;

        if (!mg_is_logged_in()) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $id = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        try {
            $db = mg_auth_db();
            $stmt = $db->prepare("
                SELECT id, username, role, is_active, created_at, last_login_at
                FROM mg_admin_users
                WHERE id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                return null;
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc() ?: null;
            $stmt->close();

            if (!$row || (int)$row['is_active'] !== 1) {
                mg_logout_user();
                return null;
            }

            $cached = $row;
            return $cached;

        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Log a user in by id.
     */
    function mg_login_user(int $id): void
    {
        $_SESSION['admin_user_id']      = $id;
        $_SESSION['admin_last_activity'] = time();
    }

    /**
     * Log current user out.
     */
    function mg_logout_user(): void
    {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_last_activity']);
    }

    /**
     * Simple guard: require login or show a message + stop.
     */
    function mg_require_login(): void
    {
        if (mg_is_logged_in()) {
            return;
        }

        echo '<h1>Login Required</h1>';
        echo '<div class="card"><p>You must be logged in to view this page.</p>';
        echo '<p class="muted" style="font-size:13px;">Please <a href="?p=login">log in</a> and try again.</p></div>';
        exit;
    }

    /**
     * Ensure current user has one of the given roles.
     *
     * @param string[] $roles
     */
    function mg_require_role(array $roles): void
    {
        if (!mg_admin_table_exists()) {
            mg_require_login();
        }

        $user = mg_current_user();
        if (!$user) {
            mg_require_login();
        }

        $role = (string)($user['role'] ?? '');
        if (!in_array($role, $roles, true)) {
            echo '<h1>Access Denied</h1>';
            echo '<div class="card">';
            echo '<p>You do not have permission to access this area.</p>';
            echo '<p class="muted" style="font-size:13px;">Your role: <code>'
                . htmlspecialchars($role, ENT_QUOTES, 'UTF-8')
                . '</code></p>';
            echo '</div>';
            exit;
        }
    }
}
