<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';
$lockFile   = dirname(__DIR__) . '/config/.installed';
if (!file_exists($lockFile) || !file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

require_once $configFile;
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';

setSecurityHeaders();
startSecureSession();

// ── Already logged-in routing ─────────────────────────────────────────────────
if (isLoggedIn()) {
    $u = getCurrentUser();
    if ($u && in_array($u['role'], ['superadmin', 'admin'], true)) {
        redirect('/admin/users.php');
    }
    // Logged in but not admin
    redirect('/dashboard.php');
}

$error      = '';
$success    = '';
$otpPending = !empty($_SESSION['admin_otp_pending_user_id']);

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (!empty($_POST['otp_step'])) {
        // ── Step 2: OTP verification ──────────────────────────────────────────
        if (empty($_SESSION['admin_otp_pending_user_id'])) {
            redirect('/admin/');
        }

        if (isset($_POST['back_to_login'])) {
            unset($_SESSION['admin_otp_pending_user_id'], $_SESSION['admin_otp_pending_email']);
            redirect('/admin/');
        }

        $ip  = getClientIP();
        $otp = trim($_POST['otp'] ?? '');

        if (!rateLimit('admin_otp_' . $ip, 10, 60)) {
            $error      = 'Too many attempts. Please wait a minute.';
            $otpPending = true;
        } else {
            $result = verifyLoginOTP(
                (int)$_SESSION['admin_otp_pending_user_id'],
                $otp,
                !empty($_POST['remember_device']),
                $ip,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );

            if ($result['success']) {
                // Ensure the verified user is actually an admin
                $user = getCurrentUser();
                if (!$user || !in_array($user['role'], ['superadmin', 'admin'], true)) {
                    logout();
                    $error = 'Access denied. Administrator privileges required.';
                    unset($_SESSION['admin_otp_pending_user_id'], $_SESSION['admin_otp_pending_email']);
                    $otpPending = false;
                } else {
                    unset($_SESSION['admin_otp_pending_user_id'], $_SESSION['admin_otp_pending_email']);
                    redirect('/admin/users.php');
                }
            } else {
                $error      = $result['message'];
                $otpPending = true;
            }
        }
    } else {
        // ── Step 1: Username + password ───────────────────────────────────────
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } else {
            $ip = getClientIP();
            if (!rateLimit('admin_login_' . $ip, 10, 60)) {
                $error = 'Too many login attempts. Please wait a minute.';
            } else {
                $result = login($username, $password);

                if ($result['success']) {
                    $user = getCurrentUser();
                    if (!$user || !in_array($user['role'], ['superadmin', 'admin'], true)) {
                        logout();
                        $error = 'Access denied. Administrator privileges required.';
                    } else {
                        redirect('/admin/users.php');
                    }
                } elseif (!empty($result['otp_required'])) {
                    $userId   = (int)$result['user_id'];
                    $rawEmail = $result['email'] ?? '';
                    $atPos    = strpos($rawEmail, '@');
                    $masked   = $atPos > 1
                        ? substr($rawEmail, 0, 1) . '***' . substr($rawEmail, $atPos)
                        : '***@***';

                    $_SESSION['admin_otp_pending_user_id'] = $userId;
                    $_SESSION['admin_otp_pending_email']   = $masked;
                    $otpPending = true;
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

$csrfToken = csrfToken();
$appName   = defined('APP_NAME') ? APP_NAME : 'Marketing Suite';
$theme     = $_COOKIE['theme'] ?? 'dark';
$year      = date('Y');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
/* ── Layout ─────────────────────────────────────────────────────── */
html, body {
    min-height: 100vh;
    overflow-x: hidden;
}
body {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    padding: 2rem 1rem;
}

/* ── Animated background orbs ───────────────────────────────────── */
.bg-orb {
    position: fixed;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .35;
    animation: floatOrb 8s ease-in-out infinite;
    pointer-events: none;
    z-index: 0;
}
.bg-orb-1 { width: 480px; height: 480px; background: radial-gradient(circle, #6c63ff, transparent 70%); top: -120px; left: -120px; animation-delay: 0s; }
.bg-orb-2 { width: 400px; height: 400px; background: radial-gradient(circle, #00d4ff, transparent 70%); bottom: -80px; right: -80px; animation-delay: -3s; }
.bg-orb-3 { width: 300px; height: 300px; background: radial-gradient(circle, #00ff88, transparent 70%); top: 40%; left: 55%; animation-delay: -5s; opacity: .18; }

@keyframes floatOrb {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33%       { transform: translate(30px, -20px) scale(1.05); }
    66%       { transform: translate(-20px, 15px) scale(.95); }
}

/* ── Wrapper ─────────────────────────────────────────────────────── */
.admin-login-wrap {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 460px;
}

/* ── Card ────────────────────────────────────────────────────────── */
.admin-card {
    background: rgba(15, 15, 28, 0.72);
    border: 1px solid rgba(108, 99, 255, 0.25);
    border-radius: 24px;
    padding: 2.75rem 2.5rem;
    backdrop-filter: blur(28px);
    -webkit-backdrop-filter: blur(28px);
    box-shadow:
        0 0 0 1px rgba(108,99,255,.12),
        0 30px 80px rgba(0,0,0,.55),
        inset 0 1px 0 rgba(255,255,255,.06);
    animation: cardIn .5s cubic-bezier(.22,1,.36,1) both;
}

@keyframes cardIn {
    from { opacity: 0; transform: translateY(28px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

[data-theme="light"] .admin-card {
    background: rgba(255, 255, 255, 0.88);
    border-color: rgba(108, 99, 255, 0.2);
    box-shadow:
        0 0 0 1px rgba(108,99,255,.10),
        0 30px 80px rgba(0,0,0,.15),
        inset 0 1px 0 rgba(255,255,255,.8);
}

/* ── Admin badge ─────────────────────────────────────────────────── */
.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    background: linear-gradient(135deg, rgba(108,99,255,.2), rgba(0,212,255,.2));
    border: 1px solid rgba(108,99,255,.35);
    border-radius: 50px;
    padding: .35rem .9rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--accent-2);
    margin-bottom: 1.25rem;
}
.admin-badge-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 6px var(--success);
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .5; transform: scale(1.3); }
}

/* ── Branding ────────────────────────────────────────────────────── */
.admin-logo {
    text-align: center;
    margin-bottom: 2rem;
}
.admin-logo-icon {
    font-size: 3rem;
    display: block;
    margin-bottom: .75rem;
    filter: drop-shadow(0 0 18px rgba(108,99,255,.7));
    animation: iconPop .6s cubic-bezier(.34,1.56,.64,1) .2s both;
}
@keyframes iconPop {
    from { transform: scale(0) rotate(-20deg); opacity: 0; }
    to   { transform: scale(1) rotate(0deg);  opacity: 1; }
}
.admin-logo h1 {
    font-size: 1.6rem;
    font-weight: 800;
    background: linear-gradient(135deg, #a78bfa, #6c63ff 40%, #00d4ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: .3rem;
}
.admin-logo p {
    color: var(--text-muted);
    font-size: .83rem;
}

/* ── Form fields ─────────────────────────────────────────────────── */
.form-group { margin-bottom: 1.25rem; }
.form-group label {
    display: block;
    font-size: .82rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: .45rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.input-wrap {
    position: relative;
}
.input-icon {
    position: absolute;
    left: .9rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: .95rem;
    pointer-events: none;
    opacity: .55;
}
.input-wrap input {
    width: 100%;
    padding: .75rem 1rem .75rem 2.5rem;
    background: rgba(255,255,255,.05);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
    font-size: .95rem;
    transition: var(--transition);
    outline: none;
}
.input-wrap input:focus {
    border-color: var(--accent);
    background: rgba(108,99,255,.07);
    box-shadow: 0 0 0 3px rgba(108,99,255,.15);
}
[data-theme="light"] .input-wrap input {
    background: rgba(0,0,0,.04);
    color: var(--text-primary);
}
[data-theme="light"] .input-wrap input:focus {
    background: rgba(108,99,255,.04);
}

/* Password toggle ── */
.input-wrap .pwd-toggle {
    position: absolute;
    right: .9rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    font-size: .9rem;
    opacity: .5;
    transition: opacity .2s;
    padding: 0;
}
.input-wrap .pwd-toggle:hover { opacity: 1; }

/* ── OTP input ───────────────────────────────────────────────────── */
.otp-hint {
    text-align: center;
    color: var(--text-muted);
    font-size: .85rem;
    margin-bottom: 1.25rem;
    line-height: 1.5;
}
.otp-hint strong { color: var(--text-secondary); }
.otp-input {
    text-align: center;
    letter-spacing: .4em;
    font-size: 1.6rem;
    font-weight: 700;
    padding: .75rem 1rem !important;
}

/* ── Remember row ────────────────────────────────────────────────── */
.remember-row {
    display: flex;
    align-items: center;
    gap: .55rem;
    margin-bottom: 1.25rem;
    font-size: .87rem;
    color: var(--text-secondary);
    cursor: pointer;
}
.remember-row input[type=checkbox] { accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer; }

/* ── Submit button ───────────────────────────────────────────────── */
.btn-admin {
    width: 100%;
    padding: .85rem 1rem;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    border: none;
    border-radius: var(--radius-sm);
    color: #fff;
    font-size: .95rem;
    font-weight: 700;
    letter-spacing: .03em;
    cursor: pointer;
    transition: var(--transition);
    box-shadow: 0 4px 20px rgba(108,99,255,.4);
    position: relative;
    overflow: hidden;
}
.btn-admin::before {
    content: '';
    position: absolute;
    top: 0; left: -100%;
    width: 100%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.15), transparent);
    transition: left .45s ease;
}
.btn-admin:hover::before { left: 100%; }
.btn-admin:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(108,99,255,.55);
}
.btn-admin:active { transform: translateY(0); }
.btn-admin:disabled { opacity: .65; cursor: not-allowed; transform: none !important; }

/* ── Alerts ──────────────────────────────────────────────────────── */
.alert {
    border-radius: var(--radius-sm);
    padding: .8rem 1rem;
    margin-bottom: 1.25rem;
    font-size: .87rem;
    display: flex;
    align-items: flex-start;
    gap: .5rem;
}
.alert-error   { background: rgba(255, 71, 87, .12); border: 1px solid rgba(255,71,87,.3);   color: #ff6b7a; }
.alert-success { background: rgba(0, 255, 136, .1);  border: 1px solid rgba(0,255,136,.3);   color: #00e57a; }
.alert-warning { background: rgba(255,165,  0, .1);  border: 1px solid rgba(255,165,0,.3);   color: #ffb340; }

/* ── Back link ───────────────────────────────────────────────────── */
.back-btn {
    display: block;
    width: 100%;
    background: none;
    border: none;
    text-align: center;
    margin-top: .9rem;
    font-size: .85rem;
    color: var(--text-muted);
    cursor: pointer;
    padding: .35rem;
    border-radius: var(--radius-sm);
    transition: var(--transition);
}
.back-btn:hover { color: var(--accent); background: rgba(108,99,255,.07); }

/* ── Divider ─────────────────────────────────────────────────────── */
.divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin: 1.5rem 0;
    color: var(--text-muted);
    font-size: .78rem;
}
.divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--glass-border);
}

/* ── Security notice ─────────────────────────────────────────────── */
.security-notice {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: rgba(108,99,255,.07);
    border: 1px solid rgba(108,99,255,.15);
    border-radius: var(--radius-sm);
    padding: .65rem .9rem;
    font-size: .78rem;
    color: var(--text-muted);
    margin-top: 1.5rem;
}

/* ── Footer ──────────────────────────────────────────────────────── */
.admin-footer {
    text-align: center;
    margin-top: 1.75rem;
    font-size: .75rem;
    color: var(--text-muted);
}
.admin-footer a { color: var(--text-muted); }
.admin-footer a:hover { color: var(--accent-2); }

/* ── Theme toggle ────────────────────────────────────────────────── */
.corner-theme {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 10;
    background: rgba(255,255,255,.07);
    border: 1px solid var(--glass-border);
    border-radius: 50%;
    width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    font-size: 1.1rem;
    transition: var(--transition);
    backdrop-filter: blur(10px);
}
.corner-theme:hover { background: rgba(255,255,255,.14); transform: scale(1.1); }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .admin-card { padding: 2rem 1.5rem; border-radius: 18px; }
    .admin-logo h1 { font-size: 1.35rem; }
}
</style>
</head>
<body>

<!-- Background orbs -->
<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>
<div class="bg-orb bg-orb-3"></div>

<!-- Theme toggle (corner) -->
<button class="corner-theme" id="themeToggle" title="Toggle theme">
    <span class="theme-icon"><?= $theme === 'dark' ? '🌙' : '☀️' ?></span>
</button>

<div class="admin-login-wrap">
<div class="admin-card">

    <!-- Branding -->
    <div class="admin-logo">
        <div style="display:flex;justify-content:center;margin-bottom:.5rem">
            <span class="admin-badge">
                <span class="admin-badge-dot"></span>
                Admin Portal
            </span>
        </div>
        <span class="admin-logo-icon">🛡️</span>
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p><?= $otpPending ? 'Two-factor authentication required' : 'Restricted access — authorised personnel only' ?></p>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($otpPending): ?>
    <!-- ── OTP Step ────────────────────────────────────────────────────── -->
    <p class="otp-hint">
        A 6-digit verification code was sent to<br>
        <strong><?= htmlspecialchars($_SESSION['admin_otp_pending_email'] ?? '') ?></strong>
    </p>

    <form method="POST" id="otpForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="otp_step" value="1">

        <div class="form-group">
            <label for="otp">Verification Code</label>
            <div class="input-wrap">
                <span class="input-icon">🔢</span>
                <input
                    type="text"
                    id="otp"
                    name="otp"
                    class="otp-input"
                    maxlength="6"
                    pattern="\d{6}"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    autofocus>
            </div>
        </div>

        <label class="remember-row">
            <input type="checkbox" name="remember_device" value="1">
            Remember this device for 30 days
        </label>

        <button type="submit" class="btn-admin" id="otpBtn">
            <span class="btn-text">✔ Verify &amp; Access Admin</span>
            <span class="btn-loader" style="display:none">⏳ Verifying…</span>
        </button>
    </form>

    <form method="POST" id="backForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="otp_step" value="1">
        <button type="submit" name="back_to_login" value="1" class="back-btn">← Back to Login</button>
    </form>

    <?php else: ?>
    <!-- ── Login Step ──────────────────────────────────────────────────── -->
    <form method="POST" id="loginForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <label for="username">Username or Email</label>
            <div class="input-wrap">
                <span class="input-icon">👤</span>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    autocomplete="username"
                    placeholder="admin@example.com"
                    required
                    autofocus>
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="input-wrap">
                <span class="input-icon">🔒</span>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="••••••••••••"
                    required>
                <button type="button" class="pwd-toggle" id="pwdToggle" title="Show/hide password">👁</button>
            </div>
        </div>

        <button type="submit" class="btn-admin" id="loginBtn">
            <span class="btn-text">🔐 Sign In to Admin</span>
            <span class="btn-loader" style="display:none">⏳ Authenticating…</span>
        </button>
    </form>

    <div class="security-notice">
        🛡️ This portal is monitored. Unauthorised access attempts are logged and may result in permanent IP bans.
    </div>
    <?php endif; ?>

    <div class="admin-footer">
        &copy; <?= $year ?> <a href="/"><?= htmlspecialchars($appName) ?></a> &mdash; Admin Control Panel
        &nbsp;·&nbsp; <a href="/login.php">User Login</a>
    </div>

</div><!-- .admin-card -->
</div><!-- .admin-login-wrap -->

<script src="/assets/js/app.js"></script>
<script>
// ── Theme toggle ──────────────────────────────────────────────────────────────
(function () {
    const btn  = document.getElementById('themeToggle');
    const html = document.documentElement;
    if (!btn) return;
    btn.addEventListener('click', function () {
        const cur  = html.getAttribute('data-theme') || 'dark';
        const next = cur === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        btn.querySelector('.theme-icon').textContent = next === 'dark' ? '🌙' : '☀️';
        document.cookie = 'theme=' + next + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    });
})();

// ── Password visibility toggle ────────────────────────────────────────────────
(function () {
    const toggle = document.getElementById('pwdToggle');
    const input  = document.getElementById('password');
    if (!toggle || !input) return;
    toggle.addEventListener('click', function () {
        if (input.type === 'password') {
            input.type = 'text';
            toggle.textContent = '🙈';
        } else {
            input.type = 'password';
            toggle.textContent = '👁';
        }
    });
})();

// ── Button loaders ────────────────────────────────────────────────────────────
(function () {
    const loginForm = document.getElementById('loginForm');
    const otpForm   = document.getElementById('otpForm');

    if (loginForm) {
        loginForm.addEventListener('submit', function () {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.querySelector('.btn-text').style.display   = 'none';
            btn.querySelector('.btn-loader').style.display = 'inline';
        });
    }

    if (otpForm) {
        otpForm.addEventListener('submit', function () {
            const otp = document.getElementById('otp');
            if (!/^\d{6}$/.test(otp.value.trim())) {
                otp.focus();
                return false;
            }
            const btn = document.getElementById('otpBtn');
            btn.disabled = true;
            btn.querySelector('.btn-text').style.display   = 'none';
            btn.querySelector('.btn-loader').style.display = 'inline';
        });

        // Auto-submit when 6 digits entered
        const otpInput = document.getElementById('otp');
        if (otpInput) {
            otpInput.addEventListener('input', function () {
                if (/^\d{6}$/.test(this.value.trim())) {
                    otpForm.requestSubmit();
                }
            });
        }
    }
})();
</script>
</body>
</html>
