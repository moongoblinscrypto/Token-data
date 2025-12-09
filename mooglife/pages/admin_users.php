<?php
// mooglife/pages/admin_users.php
// Manage admin accounts.

require __DIR__ . '/../includes/auth.php';

// Only superadmin can use this page
mg_require_role(['superadmin']);

$db = mg_auth_db();
$has_admin_table = mg_admin_table_exists();

$error_msg   = '';
$success_msg = '';

$current = mg_current_user();

// If table missing, show instructions
if (!$has_admin_table): ?>
    <h1>Admin Users</h1>
    <div class="card">
        <p><code>mg_admin_users</code> table not found.</p>
        <p class="muted" style="font-size:13px;">
            Create it in your Mooglife database:
        </p>
        <pre style="background:#020617;border-radius:6px;padding:8px;font-size:12px;overflow:auto;">
CREATE TABLE mg_admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(32)  NOT NULL DEFAULT 'admin',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        </pre>
    </div>
    <?php
    return;
endif;

// Handle POST (add/update/delete/reset)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role     = trim($_POST['role'] ?? 'admin');
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if ($username === '' || $password === '') {
            $error_msg = 'Username and password are required.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("
                    INSERT INTO mg_admin_users (username, password_hash, role, is_active, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                if (!$stmt) {
                    $error_msg = 'DB error (add): ' . $db->error;
                } else {
                    $stmt->bind_param('sssi', $username, $hash, $role, $active);
                    if ($stmt->execute()) {
                        $success_msg = 'Admin user added.';
                    } else {
                        $error_msg = 'Insert failed: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $error_msg = 'Error adding user: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update_user') {
        $id      = (int)($_POST['id'] ?? 0);
        $role    = trim($_POST['role'] ?? 'admin');
        $active  = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0) {
            try {
                $stmt = $db->prepare("
                    UPDATE mg_admin_users
                    SET role = ?, is_active = ?
                    WHERE id = ?
                ");
                if (!$stmt) {
                    $error_msg = 'DB error (update): ' . $db->error;
                } else {
                    $stmt->bind_param('sii', $role, $active, $id);
                    if ($stmt->execute()) {
                        $success_msg = 'Admin user updated.';
                    } else {
                        $error_msg = 'Update failed: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $error_msg = 'Error updating user: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'reset_pw') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');

        if ($id > 0 && $password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("UPDATE mg_admin_users SET password_hash = ? WHERE id = ?");
                if (!$stmt) {
                    $error_msg = 'DB error (reset pw): ' . $db->error;
                } else {
                    $stmt->bind_param('si', $hash, $id);
                    if ($stmt->execute()) {
                        $success_msg = 'Password reset.';
                    } else {
                        $error_msg = 'Reset failed: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $error_msg = 'Error resetting password: ' . $e->getMessage();
            }
        } else {
            $error_msg = 'Password required for reset.';
        }
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            // prevent deleting yourself
            if ($current && (int)$current['id'] === $id) {
                $error_msg = 'You cannot delete your own account.';
            } else {
                try {
                    // Do not allow deleting last admin
                    $res = $db->query("SELECT COUNT(*) AS c FROM mg_admin_users");
                    $count = 0;
                    if ($res && ($row = $res->fetch_assoc())) {
                        $count = (int)$row['c'];
                    }
                    if ($res) $res->close();

                    if ($count <= 1) {
                        $error_msg = 'Cannot delete the last admin account.';
                    } else {
                        $stmt = $db->prepare("DELETE FROM mg_admin_users WHERE id = ? LIMIT 1");
                        if (!$stmt) {
                            $error_msg = 'DB error (delete): ' . $db->error;
                        } else {
                            $stmt->bind_param('i', $id);
                            if ($stmt->execute()) {
                                $success_msg = 'Admin user deleted.';
                            } else {
                                $error_msg = 'Delete failed: ' . $stmt->error;
                            }
                            $stmt->close();
                        }
                    }
                } catch (Throwable $e) {
                    $error_msg = 'Error deleting user: ' . $e->getMessage();
                }
            }
        }
    }
}

// Load users
$users = [];
try {
    $res = $db->query("
        SELECT id, username, role, is_active, created_at, last_login_at
        FROM mg_admin_users
        ORDER BY id ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }
        $res->close();
    }
} catch (Throwable $e) {
    $error_msg = 'Error loading users: ' . $e->getMessage();
}

?>
<h1>Admin Users</h1>
<p class="muted">
    Manage who can log into Mooglife. (This page is superadmin-only.)
</p>

<?php if ($error_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;font-size:13px;">
        <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php elseif ($success_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;font-size:13px;">
        <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:20px;">

    <div class="card" style="flex:1 1 320px;min-width:280px;">
        <h2 style="margin-top:0;">Add Admin</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_user">

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Username</label>
                <input
                    type="text"
                    name="username"
                    value=""
                    style="width:100%;padding:6px 8px;border-radius:6px;
                           border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Password</label>
                <input
                    type="password"
                    name="password"
                    value=""
                    style="width:100%;padding:6px 8px;border-radius:6px;
                           border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;display:block;margin-bottom:2px;">Role</label>
                <select
                    name="role"
                    style="width:100%;padding:6px 8px;border-radius:6px;
                           border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
                >
                    <option value="admin">admin</option>
                    <option value="superadmin">superadmin</option>
                </select>
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:12px;">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span style="margin-left:4px;">Active</span>
                </label>
            </div>

            <button type="submit"
                    style="padding:6px 12px;border-radius:6px;border:none;background:#22c55e;
                           color:#020617;font-weight:600;cursor:pointer;">
                Add User
            </button>
        </form>
    </div>

    <div class="card" style="flex:2 1 420px;min-width:320px;">
        <h2 style="margin-top:0;">Existing Admins</h2>
        <div style="overflow-x:auto;">
            <table class="data" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">ID</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Username</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Role</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Status</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Created</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Last Login</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #1f2937;">Edit</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="7" style="padding:8px;border-bottom:1px solid #111827;">
                            No admin users found (this shouldn’t happen if you’re logged in).
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo (int)$u['id']; ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($current && (int)$current['id'] === (int)$u['id']): ?>
                                    <span class="pill" style="margin-left:4px;">you</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;">
                                <?php echo htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:12px;">
                                <?php if ((int)$u['is_active'] === 1): ?>
                                    <span class="pill">active</span>
                                <?php else: ?>
                                    <span class="pill" style="background:#4b5563;">disabled</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;">
                                <?php echo htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;">
                                <?php echo htmlspecialchars($u['last_login_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:6px;border-bottom:1px solid #111827;font-size:11px;">
                                <form method="post" style="margin:0 0 4px 0;">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                        <select name="role"
                                                style="padding:2px 4px;border-radius:4px;border:1px solid #1f2937;
                                                       background:#020617;color:#e5e7eb;font-size:11px;">
                                            <option value="admin"      <?php if ($u['role'] === 'admin') echo 'selected'; ?>>admin</option>
                                            <option value="superadmin" <?php if ($u['role'] === 'superadmin') echo 'selected'; ?>>superadmin</option>
                                        </select>
                                        <label style="font-size:11px;">
                                            <input type="checkbox" name="is_active" value="1"
                                                   <?php if ((int)$u['is_active'] === 1) echo 'checked'; ?>>
                                            active
                                        </label>
                                        <button type="submit"
                                                style="padding:3px 8px;border-radius:4px;border:none;background:#3b82f6;
                                                       color:#f9fafb;font-size:11px;cursor:pointer;">
                                            Save
                                        </button>
                                    </div>
                                </form>

                                <form method="post" style="margin:0 0 4px 0;">
                                    <input type="hidden" name="action" value="reset_pw">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                        <input
                                            type="password"
                                            name="password"
                                            placeholder="New password"
                                            style="padding:2px 4px;border-radius:4px;border:1px solid #1f2937;
                                                   background:#020617;color:#e5e7eb;font-size:11px;"
                                        >
                                        <button type="submit"
                                                style="padding:3px 8px;border-radius:4px;border:none;background:#0ea5e9;
                                                       color:#e0f2fe;font-size:11px;cursor:pointer;">
                                            Reset PW
                                        </button>
                                    </div>
                                </form>

                                <?php if (!$current || (int)$current['id'] !== (int)$u['id']): ?>
                                    <form method="post" style="margin:0;"
                                          onsubmit="return confirm('Delete this admin user?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                        <button type="submit"
                                                style="margin-top:4px;padding:3px 8px;border-radius:4px;border:none;
                                                       background:#b91c1c;color:#fee2e2;font-size:11px;cursor:pointer;">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
