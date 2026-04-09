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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verifyCsrf($token)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        // Rate limit
        $ip = getClientIP();
        if (!rateLimit('login_' . $ip, 10, 60)) {
            $error = 'Too many login attempts. Please wait a minute.';
        } else {
            $result = login($username, $password);
            if ($result['success']) {
                redirect('/dashboard.php');
            } else {
                $error = $result['message'];
            }
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-wrap { width: 100%; max-width: 420px; margin: 1.5rem; }
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
</style>
</head>
<body>
<div class="login-wrap">
<div class="login-card">
    <div class="login-logo">
        <h1>📧 <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></h1>
        <p>Sign in to your account</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

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
</div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function() {
    document.querySelector('.btn-text').style.display = 'none';
    document.querySelector('.btn-loader').style.display = 'inline';
    document.getElementById('loginBtn').disabled = true;
});
</script>
</body>
</html>
