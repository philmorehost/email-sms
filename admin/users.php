<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

setSecurityHeaders();
requireAdmin();

$db   = getDB();
$user = getCurrentUser();

// ─── Flash helpers ────────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['flash_msg']  ?? '';
    $type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

// ─── POST HANDLERS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/users.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = sanitize($_POST['username'] ?? '');
        $email    = sanitizeEmail($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'user';
        if (!in_array($role, ['admin', 'user'], true)) $role = 'user';

        if ($username === '' || $email === '' || $password === '') {
            setFlash('Username, email and password are required.', 'error');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    "INSERT INTO users (username, email, password, role) VALUES (:u, :e, :p, :r)"
                );
                $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash, ':r' => $role]);
                setFlash('User created successfully.');
            } catch (\Exception $e) {
                setFlash('Failed to create user: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/users.php');
    }

    if ($action === 'toggle_suspend') {
        $uid = (int)($_POST['user_id'] ?? 0);
        try {
            $db->prepare("UPDATE users SET is_suspended = NOT is_suspended WHERE id = :id")
               ->execute([':id' => $uid]);
            setFlash('Suspension status updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update suspension: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/users.php');
    }

    if ($action === 'set_role') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? 'user';
        if (!in_array($role, ['superadmin', 'admin', 'user'], true)) $role = 'user';
        try {
            $db->prepare("UPDATE users SET role = :r WHERE id = :id")
               ->execute([':r' => $role, ':id' => $uid]);
            setFlash('Role updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update role: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/users.php');
    }

    if ($action === 'toggle_mfa') {
        $uid = (int)($_POST['user_id'] ?? 0);
        try {
            $db->prepare("UPDATE users SET mfa_enabled = NOT mfa_enabled WHERE id = :id")
               ->execute([':id' => $uid]);
            setFlash('MFA status updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update MFA: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/users.php');
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)($user['id'] ?? 0)) {
            setFlash('You cannot delete your own account.', 'error');
        } else {
            try {
                $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $uid]);
                setFlash('User deleted.');
            } catch (\Exception $e) {
                setFlash('Failed to delete user: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/users.php');
    }

    if ($action === 'add_credits') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $desc   = sanitize($_POST['description'] ?? 'Admin credit');
        if ($uid <= 0 || $amount <= 0) {
            setFlash('Invalid user or amount.', 'error');
        } else {
            try {
                $db->prepare(
                    "INSERT INTO user_sms_wallet (user_id, credits) VALUES (:uid, :amt)
                     ON DUPLICATE KEY UPDATE credits = credits + :amt2, updated_at = NOW()"
                )->execute([':uid' => $uid, ':amt' => $amount, ':amt2' => $amount]);
                $db->prepare(
                    "INSERT INTO sms_credit_transactions (user_id, amount, type, description)
                     VALUES (:uid, :amt, 'credit', :desc)"
                )->execute([':uid' => $uid, ':amt' => $amount, ':desc' => $desc]);
                setFlash('SMS credits added successfully.');
            } catch (\Exception $e) {
                setFlash('Failed to add credits: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/users.php');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/users.php');
}

// ─── DATA ─────────────────────────────────────────────────────────────────────
$flash = popFlash();

try {
    $users = $db->query(
        "SELECT u.*,
                COALESCE(w.credits, 0) AS sms_credits,
                ep.name AS plan_name,
                us.status AS sub_status
         FROM users u
         LEFT JOIN user_sms_wallet w ON w.user_id = u.id
         LEFT JOIN user_subscriptions us ON us.user_id = u.id
         LEFT JOIN email_plans ep ON ep.id = us.plan_id
         ORDER BY u.created_at DESC"
    )->fetchAll();
} catch (\Exception $e) {
    $users = [];
}

try {
    $stats = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(role IN ('admin','superadmin')) AS admins,
            SUM(is_suspended = 1) AS suspended
         FROM users"
    )->fetch();
    $activeSubs = $db->query(
        "SELECT COUNT(*) FROM user_subscriptions WHERE status = 'active'"
    )->fetchColumn();
} catch (\Exception $e) {
    $stats      = ['total' => 0, 'admins' => 0, 'suspended' => 0];
    $activeSubs = 0;
}

$pageTitle  = 'User Management';
$activePage = 'users';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Stats bar -->
<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['admins'] ?? 0) ?></div>
        <div class="stat-label">Admins</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['suspended'] ?? 0) ?></div>
        <div class="stat-label">Suspended</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)$activeSubs ?></div>
        <div class="stat-label">Active Subscriptions</div>
    </div>
</div>

<!-- Add User -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3 class="card-title">➕ Add New User</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/users.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required maxlength="50">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group" style="align-self:flex-end">
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">👥 All Users</h3>
    </div>
    <div class="card-body" style="padding:0">
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>MFA</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>SMS Credits</th>
                    <th>Plan</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <?php
                    $roleClass = match($u['role']) {
                        'superadmin' => 'badge-danger',
                        'admin'      => 'badge-warning',
                        default      => 'badge-secondary',
                    };
                    ?>
                    <span class="badge <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span>
                </td>
                <td><?= $u['mfa_enabled'] ? '✓' : '✗' ?></td>
                <td>
                    <?php if ($u['is_suspended']): ?>
                        <span class="badge badge-danger">Suspended</span>
                    <?php else: ?>
                        <span class="badge badge-success">Active</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['last_login'] ? htmlspecialchars(timeAgo($u['last_login'])) : '—' ?></td>
                <td><?= number_format((float)$u['sms_credits'], 2) ?></td>
                <td><?= $u['plan_name'] ? htmlspecialchars($u['plan_name']) : '—' ?></td>
                <td><?= htmlspecialchars(date('Y-m-d', strtotime($u['created_at']))) ?></td>
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <!-- Toggle Suspend -->
                        <form method="POST" action="/admin/users.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle_suspend">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $u['is_suspended'] ? 'btn-success' : 'btn-warning' ?>"
                                    onclick="return confirm('Toggle suspension for <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                                <?= $u['is_suspended'] ? 'Unsuspend' : 'Suspend' ?>
                            </button>
                        </form>
                        <!-- Set Role -->
                        <form method="POST" action="/admin/users.php" style="display:inline;display:flex;gap:.25rem">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select name="role" class="form-control form-control-sm" style="width:auto">
                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="superadmin" <?= $u['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-secondary">Set</button>
                        </form>
                        <!-- Toggle MFA -->
                        <form method="POST" action="/admin/users.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle_mfa">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"
                                    onclick="return confirm('Toggle MFA for <?= htmlspecialchars(addslashes($u['username'])) ?>?')">
                                MFA
                            </button>
                        </form>
                        <!-- Add Credits -->
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="openCreditsModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')">
                            Credits
                        </button>
                        <!-- Delete -->
                        <?php if ((int)$u['id'] !== (int)($user['id'] ?? 0)): ?>
                        <form method="POST" action="/admin/users.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>? This cannot be undone.')">
                                Delete
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="11" style="text-align:center;padding:2rem;color:var(--text-muted)">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Add Credits Modal -->
<div id="creditsModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
    <div class="card" style="width:100%;max-width:480px;margin:auto">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3 class="card-title">💳 Add SMS Credits</h3>
            <button onclick="closeCreditsModal()" class="btn btn-sm btn-secondary">✕</button>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/users.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_credits">
                <input type="hidden" name="user_id" id="creditsUserId">
                <div class="form-group">
                    <label class="form-label">User: <strong id="creditsUsername"></strong></label>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount (credits)</label>
                    <input type="number" name="amount" class="form-control" min="1" step="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="Admin credit top-up" maxlength="255">
                </div>
                <button type="submit" class="btn btn-primary">Add Credits</button>
            </form>
        </div>
    </div>
</div>

<script>
function openCreditsModal(uid, username) {
    document.getElementById('creditsUserId').value = uid;
    document.getElementById('creditsUsername').textContent = username;
    const m = document.getElementById('creditsModal');
    m.style.display = 'flex';
}
function closeCreditsModal() {
    document.getElementById('creditsModal').style.display = 'none';
}
document.getElementById('creditsModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreditsModal();
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
