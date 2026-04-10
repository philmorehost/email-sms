<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config/config.php';
$lockFile   = __DIR__ . '/config/.installed';
if (!file_exists($lockFile) || !file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

setSecurityHeaders();
startSecureSession();

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error   = '';
$warning = '';

$otpPending = !empty($_SESSION['otp_pending_user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verifyCsrf($token)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!empty($_POST['otp_step'])) {
        // ── Step 2: OTP verification ──────────────────────────────────────
        if (empty($_SESSION['otp_pending_user_id'])) {
            redirect('/login.php');
        }

        if (isset($_POST['back_to_login'])) {
            unset($_SESSION['otp_pending_user_id'], $_SESSION['otp_pending_email']);
            redirect('/login.php');
        }

        $otp           = trim($_POST['otp'] ?? '');
        $rememberDevice = !empty($_POST['remember_device']);
        $ip             = getClientIP();

        if (!rateLimit('otp_' . $ip, 10, 60)) {
            $error = 'Too many attempts. Please wait a minute.';
        } else {
            $result = verifyLoginOTP(
                (int)$_SESSION['otp_pending_user_id'],
                $otp,
                $rememberDevice,
                $ip,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            if ($result['success']) {
                redirect('/dashboard.php');
            } else {
                $error      = $result['message'];
                $otpPending = true;
            }
        }
    } else {
        // ── Step 1: Username + password ───────────────────────────────────
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } else {
            $ip = getClientIP();
            if (!rateLimit('login_' . $ip, 10, 60)) {
                $error = 'Too many login attempts. Please wait a minute.';
            } else {
                $result = login($username, $password);
                if ($result['success']) {
                    redirect('/dashboard.php');
                } elseif (!empty($result['otp_required'])) {
                    $userId = (int)$result['user_id'];
                    // Mask email for display
                    $db   = getDB();
                    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $emailRow     = $stmt->fetch();
                    $rawEmail     = $emailRow['email'] ?? '';
                    $atPos        = strpos($rawEmail, '@');
                    $maskedEmail  = $atPos > 1
                        ? substr($rawEmail, 0, 1) . '***' . substr($rawEmail, $atPos)
                        : '***@***';

                    $_SESSION['otp_pending_user_id'] = $userId;
                    $_SESSION['otp_pending_email']   = $maskedEmail;
                    $otpPending = true;
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

$csrfToken = csrfToken();
$theme     = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-wrap { width: 100%; max-width: 420px; margin: 1.5rem; position: relative; }
.login-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px;
    padding: 2.5rem;
    backdrop-filter: blur(20px);
    box-shadow: 0 25px 50px rgba(0,0,0,0.4);
}
.login-logo { text-align: center; margin-bottom: 2rem; }
.login-logo h1 { font-size: 1.5rem; background: linear-gradient(135deg, #6c63ff, #00d4ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.login-logo p { color: #888; font-size: 0.85rem; margin-top: 0.25rem; }
.login-theme-toggle { position: absolute; top: 0; right: 0; }
.otp-hint { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem; text-align: center; }
.remember-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; color: var(--text-secondary); }
.back-link { display: block; text-align: center; margin-top: 1rem; font-size: 0.875rem; color: var(--text-muted); }
.back-link:hover { color: var(--accent); }
</style>
</head>
<body>
<div class="login-wrap">
<button class="theme-toggle login-theme-toggle" id="themeToggle" title="Toggle theme">
  <span class="theme-icon"><?= $theme === 'dark' ? '🌙' : '☀️' ?></span>
</button>
<div class="login-card">
    <div class="login-logo">
        <h1>📧 <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></h1>
        <p><?= $otpPending ? 'Two-factor authentication' : 'Sign in to your account' ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($otpPending): ?>
    <!-- ── OTP Form ────────────────────────────────────────────────────── -->
    <p class="otp-hint">Enter the 6-digit code sent to <strong><?= htmlspecialchars($_SESSION['otp_pending_email'] ?? '') ?></strong></p>
    <form method="POST" id="otpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="otp_step" value="1">
        <div class="form-group">
            <label for="otp">Verification Code</label>
            <input type="text" id="otp" name="otp" maxlength="6" pattern="\d{6}"
                   inputmode="numeric" autocomplete="one-time-code"
                   placeholder="000000" required autofocus>
        </div>
        <div class="remember-row">
            <input type="checkbox" id="remember_device" name="remember_device" value="1">
            <label for="remember_device">Remember this device for 30 days</label>
        </div>
        <button type="submit" class="btn btn-primary btn-full" id="otpBtn">
            <span class="btn-text">Verify</span>
            <span class="btn-loader" style="display:none">⏳ Verifying...</span>
        </button>
    </form>
    <form method="POST" id="backForm" style="margin:0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="otp_step" value="1">
        <button type="submit" name="back_to_login" value="1" class="back-link" style="background:none;border:none;cursor:pointer;width:100%">← Back to Login</button>
    </form>
    <?php else: ?>
    <!-- ── Login Form ─────────────────────────────────────────────────── -->
    <form method="POST" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="form-group">
            <label for="username">Username or Email</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full" id="loginBtn">
            <span class="btn-text">Sign In</span>
            <span class="btn-loader" style="display:none">⏳ Signing in...</span>
        </button>
    </form>
    <?php endif; ?>
</div>
</div>

<script src="/assets/js/app.js"></script>
<script>
<?php if ($otpPending): ?>
document.getElementById('otpForm').addEventListener('submit', function() {
    document.querySelector('#otpForm .btn-text').style.display = 'none';
    document.querySelector('#otpForm .btn-loader').style.display = 'inline';
    document.getElementById('otpBtn').disabled = true;
});
<?php else: ?>
document.getElementById('loginForm').addEventListener('submit', function() {
    document.querySelector('.btn-text').style.display = 'none';
    document.querySelector('.btn-loader').style.display = 'inline';
    document.getElementById('loginBtn').disabled = true;
});
<?php endif; ?>
</script>
</body>
</html>
