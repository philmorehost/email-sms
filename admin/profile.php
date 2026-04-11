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
$uid  = (int)($user['id'] ?? 0);

// ─── Flash helpers ─────────────────────────────────────────────────────────────
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

// ─── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/profile.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Update profile info (username + email) ─────────────────────────────────
    if ($action === 'update_profile') {
        $newUsername = sanitize($_POST['username'] ?? '');
        $newEmail    = sanitizeEmail($_POST['email'] ?? '');

        if ($newUsername === '' || $newEmail === '') {
            setFlash('Username and email are required.', 'error');
        } else {
            // Check uniqueness
            try {
                $chkStmt = $db->prepare(
                    "SELECT COUNT(*) FROM users WHERE (username=:u OR email=:e) AND id != :id"
                );
                $chkStmt->execute([':u' => $newUsername, ':e' => $newEmail, ':id' => $uid]);
                if ((int)$chkStmt->fetchColumn() > 0) {
                    setFlash('That username or email is already taken.', 'error');
                } else {
                    $db->prepare("UPDATE users SET username=:u, email=:e WHERE id=:id")
                       ->execute([':u' => $newUsername, ':e' => $newEmail, ':id' => $uid]);
                    // Refresh session user data
                    $_SESSION['user_username'] = $newUsername;
                    $_SESSION['user_email']    = $newEmail;
                    setFlash('Profile updated successfully.');
                }
            } catch (\Exception $e) {
                setFlash('Failed to update profile: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/profile.php');
    }

    // ── Change password ────────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

        if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
            setFlash('All password fields are required.', 'error');
        } elseif (strlen($newPw) < 8) {
            setFlash('New password must be at least 8 characters.', 'error');
        } elseif ($newPw !== $confirmPw) {
            setFlash('New passwords do not match.', 'error');
        } else {
            try {
                $row = $db->prepare("SELECT password FROM users WHERE id=:id");
                $row->execute([':id' => $uid]);
                $hash = $row->fetchColumn();

                if (!$hash || !password_verify($currentPw, $hash)) {
                    setFlash('Current password is incorrect.', 'error');
                } else {
                    $newHash = password_hash($newPw, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE users SET password=:p WHERE id=:id")
                       ->execute([':p' => $newHash, ':id' => $uid]);
                    setFlash('Password changed successfully.');
                }
            } catch (\Exception $e) {
                setFlash('Failed to change password: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/profile.php');
    }

    // ── Toggle MFA ─────────────────────────────────────────────────────────────
    if ($action === 'toggle_mfa') {
        try {
            $db->prepare("UPDATE users SET mfa_enabled = NOT mfa_enabled WHERE id=:id")
               ->execute([':id' => $uid]);
            setFlash('MFA status updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update MFA: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/profile.php');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/profile.php');
}

// ─── DATA ──────────────────────────────────────────────────────────────────────
$flash = popFlash();

// Fetch fresh row for display
try {
    $profileStmt = $db->prepare(
        "SELECT id, username, email, role, mfa_enabled, last_login, created_at
         FROM users WHERE id=:id"
    );
    $profileStmt->execute([':id' => $uid]);
    $profile = $profileStmt->fetch();
} catch (\Exception $e) {
    $profile = $user;
}

// Recent login history (last 5)
$loginHistory = [];
try {
    $loginHistory = $db->prepare(
        "SELECT ip_address, user_agent, created_at
         FROM login_attempts
         WHERE user_id=:uid AND success=1
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $loginHistory->execute([':uid' => $uid]);
    $loginHistory = $loginHistory->fetchAll();
} catch (\Exception $e) {
    $loginHistory = [];
}

$pageTitle  = 'My Profile';
$activePage = 'admin_profile';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- ── Profile Info ──────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">👤 Profile Information</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/profile.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                           value="<?= htmlspecialchars($profile['username'] ?? '') ?>"
                           required maxlength="50">
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($profile['email'] ?? '') ?>"
                           required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <input type="text" class="form-control"
                           value="<?= htmlspecialchars($profile['role'] ?? '') ?>" readonly
                           style="opacity:.6;cursor:default">
                </div>
                <button type="submit" class="btn btn-primary">Update Profile</button>
            </form>
        </div>
    </div>

    <!-- ── Change Password ───────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔑 Change Password</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/profile.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
    </div>

    <!-- ── Security & MFA ────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🛡️ Security</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 0;border-bottom:1px solid rgba(255,255,255,.06)">
                <div>
                    <div style="font-weight:600;color:var(--text-primary)">Two-Factor Authentication (MFA)</div>
                    <div style="font-size:.82rem;color:var(--text-muted);margin-top:.2rem">
                        Require an email OTP on every login.
                    </div>
                </div>
                <form method="POST" action="/admin/profile.php" style="flex-shrink:0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_mfa">
                    <button type="submit"
                            class="btn btn-sm <?= ($profile['mfa_enabled'] ?? 0) ? 'btn-danger' : 'btn-success' ?>"
                            onclick="return confirm('Toggle MFA for your account?')">
                        <?= ($profile['mfa_enabled'] ?? 0) ? '🔴 Disable MFA' : '🟢 Enable MFA' ?>
                    </button>
                </form>
            </div>
            <div style="padding:.75rem 0">
                <div style="font-weight:600;color:var(--text-primary);margin-bottom:.35rem">Account Details</div>
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tr>
                        <td style="padding:.3rem 0;color:var(--text-muted);width:140px">Account ID</td>
                        <td style="padding:.3rem 0">#<?= (int)($profile['id'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:.3rem 0;color:var(--text-muted)">Last Login</td>
                        <td style="padding:.3rem 0">
                            <?= !empty($profile['last_login']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($profile['last_login']))) : '—' ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:.3rem 0;color:var(--text-muted)">Member Since</td>
                        <td style="padding:.3rem 0">
                            <?= !empty($profile['created_at']) ? htmlspecialchars(date('Y-m-d', strtotime($profile['created_at']))) : '—' ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:.3rem 0;color:var(--text-muted)">MFA Status</td>
                        <td style="padding:.3rem 0">
                            <?php if ($profile['mfa_enabled'] ?? 0): ?>
                                <span class="badge badge-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Disabled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Recent Login Activity ─────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📋 Recent Login Activity</h3>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($loginHistory)): ?>
            <p style="padding:1.25rem;color:var(--text-muted);font-size:.88rem">No login history available.</p>
            <?php else: ?>
            <table class="table" style="font-size:.83rem">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>IP Address</th>
                        <th>Browser / Device</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loginHistory as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($entry['created_at']))) ?></td>
                    <td><?= htmlspecialchars($entry['ip_address'] ?? '—') ?></td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($entry['user_agent'] ?? '') ?>">
                        <?= htmlspecialchars(substr($entry['user_agent'] ?? '—', 0, 80)) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
