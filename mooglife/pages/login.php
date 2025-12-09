<?php
// mooglife/pages/login.php
// Login screen + first admin creation (no header() redirects).

require __DIR__ . '/../includes/auth.php';

$db = mg_auth_db();
$has_admin_table = mg_admin_table_exists();

$error_msg    = '';
$success_msg  = '';
$creating_first = false;
$admin_count  = 0;
$redirect_to  = null;

// If table exists, see if we already have users
if ($has_admin_table) {
    try {
        $res = $db->query("SELECT COUNT(*) AS c FROM mg_admin_users");
        if ($res && ($row = $res->fetch_assoc())) {
            $admin_count = (int)$row['c'];
        }
        if ($res) $res->close();
    } catch (Throwable $e) {
        $error_msg = 'Error reading mg_admin_users: ' . $e->getMessage();
    }
    if ($admin_count === 0) {
        $creating_first = true;
    }
}

// Handle POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$has_admin_table) {
        $error_msg = 'mg_admin_users table does not exist yet.';
    } else {
        if ($admin_count === 0) {
            $creating_first = true;
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error_msg = 'Username and password are required.';
        } else {
            if ($creating_first) {
                // Create first admin user
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stmt = $db->prepare("
                        INSERT INTO mg_admin_users (username, password_hash, role, is_active, created_at)
                        VALUES (?, ?, 'superadmin', 1, NOW())
                    ");
                    if (!$stmt) {
                        $error_msg = 'DB error (create admin): ' . $db->error;
                    } else {
                        $stmt->bind_param('ss', $username, $hash);
                        if ($stmt->execute()) {
                            $newId = $stmt->insert_id;
                            $stmt->close();
                            mg_login_user((int)$newId);

                            $dest = $_SESSION['mg_after_login'] ?? 'dashboard';
                            unset($_SESSION['mg_after_login']);
                            $redirect_to = '?p=' . urlencode($dest);
                            $success_msg = 'Admin created. Redirecting...';
                        } else {
                            $error_msg = 'Insert failed: ' . $stmt->error;
                            $stmt->close();
                        }
                    }
                } catch (Throwable $e) {
                    $error_msg = 'Error creating admin: ' . $e->getMessage();
                }
            } else {
                // Normal login
                try {
                    $stmt = $db->prepare("
                        SELECT id, username, password_hash, is_active
                        FROM mg_admin_users
                        WHERE username = ?
                        LIMIT 1
                    ");
                    if (!$stmt) {
                        $error_msg = 'DB error (login): ' . $db->error;
                    } else {
                        $stmt->bind_param('s', $username);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $row = $res->fetch_assoc();
                        $stmt->close();

                        if (!$row) {
                            $error_msg = 'Invalid username or password.';
                        } elseif ((int)$row['is_active'] !== 1) {
                            $error_msg = 'This account is disabled.';
                        } elseif (!password_verify($password, $row['password_hash'])) {
                            $error_msg = 'Invalid username or password.';
                        } else {
                            // Login OK
                            $id = (int)$row['id'];
                            mg_login_user($id);
                            try {
                                $stmt2 = $db->prepare("UPDATE mg_admin_users SET last_login_at = NOW() WHERE id = ?");
                                if ($stmt2) {
                                    $stmt2->bind_param('i', $id);
                                    $stmt2->execute();
                                    $stmt2->close();
                                }
                            } catch (Throwable $e) {
                                // ignore
                            }

                            $dest = $_SESSION['mg_after_login'] ?? 'dashboard';
                            unset($_SESSION['mg_after_login']);
                            $redirect_to = '?p=' . urlencode($dest);
                            $success_msg = 'Login successful. Redirecting...';
                        }
                    }
                } catch (Throwable $e) {
                    $error_msg = 'Error during login: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<h1>Admin Login</h1>

<?php if (!$has_admin_table): ?>
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
        <p class="muted" style="font-size:12px;">
            After creating the table, reload this page to create your first admin.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#450a0a;color:#fecaca;font-size:13px;">
        <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php elseif ($success_msg): ?>
    <div style="margin-bottom:10px;padding:8px 10px;border-radius:6px;background:#022c22;color:#bbf7d0;font-size:13px;">
        <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($redirect_to): ?>
            <br><span style="font-size:11px;">If nothing happens, <a href="<?php echo htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8'); ?>">click here</a>.</span>
            <script>
                setTimeout(function () {
                    window.location.href = <?php echo json_encode($redirect_to); ?>;
                }, 600);
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width:480px;">
    <?php if ($creating_first): ?>
        <h2 style="margin-top:0;">Create First Admin User</h2>
        <p class="muted" style="font-size:13px;margin-bottom:10px;">
            No admin accounts exist yet. Create your first <strong>superadmin</strong>.
        </p>
    <?php else: ?>
        <h2 style="margin-top:0;">Sign In</h2>
        <p class="muted" style="font-size:13px;margin-bottom:10px;">
            Enter your admin credentials to access Mooglife.
        </p>
    <?php endif; ?>

    <form method="post">
        <div style="margin-bottom:10px;">
            <label style="font-size:12px;display:block;margin-bottom:2px;">Username</label>
            <input
                type="text"
                name="username"
                value=""
                style="width:100%;padding:6px 8px;border-radius:6px;
                       border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
            >
        </div>

        <div style="margin-bottom:14px;">
            <label style="font-size:12px;display:block;margin-bottom:2px;">Password</label>
            <input
                type="password"
                name="password"
                value=""
                style="width:100%;padding:6px 8px;border-radius:6px;
                       border:1px solid #1f2937;background:#020617;color:#e5e7eb;"
            >
        </div>

        <button type="submit"
                style="padding:8px 14px;border-radius:6px;border:none;background:#22c55e;
                       color:#020617;font-weight:600;cursor:pointer;">
            <?php echo $creating_first ? 'Create Admin & Continue' : 'Login'; ?>
        </button>
    </form>
</div>
