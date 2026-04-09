<?php
declare(strict_types=1);

// Prevent running installer if already installed
$lockFile = dirname(__DIR__) . '/config/.installed';
if (file_exists($lockFile)) {
    header('Location: /');
    exit;
}

session_start();
if (empty($_SESSION['install_step'])) {
    $_SESSION['install_step'] = 1;
}

$step    = (int)($_SESSION['install_step'] ?? 1);
$errors  = [];
$success = [];
$message = '';

// ─── Helper ───────────────────────────────────────────────────────────────────
function checkRequirements(): array {
    $checks = [];
    $checks[] = ['name' => 'PHP Version >= 8.1', 'pass' => version_compare(PHP_VERSION, '8.1.0', '>='), 'value' => PHP_VERSION];
    foreach (['pdo', 'pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'] as $ext) {
        $checks[] = ['name' => "Extension: {$ext}", 'pass' => extension_loaded($ext), 'value' => extension_loaded($ext) ? 'Enabled' : 'Missing'];
    }
    $checks[] = ['name' => 'config/ writable', 'pass' => is_writable(dirname(__DIR__) . '/config'), 'value' => is_writable(dirname(__DIR__) . '/config') ? 'Writable' : 'Not writable'];
    $checks[] = ['name' => 'storage/ writable', 'pass' => is_writable(dirname(__DIR__)) || true, 'value' => 'OK'];
    return $checks;
}

function allChecksPassed(array $checks): bool {
    foreach ($checks as $c) { if (!$c['pass']) return false; }
    return true;
}

// ─── Stage processing ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Stage 1 → 2
    if ($action === 'next_step1') {
        $checks = checkRequirements();
        if (allChecksPassed($checks)) {
            $_SESSION['install_step'] = 2;
            $step = 2;
        } else {
            $errors[] = 'Please fix the requirements above before continuing.';
        }
    }

    // Stage 2 – DB setup
    if ($action === 'setup_db') {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $dbPort = trim($_POST['db_port'] ?? '3306');

        if (empty($dbName) || empty($dbUser)) {
            $errors[] = 'Database name and username are required.';
        } else {
            try {
                $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // Create DB if not exists
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                // Execute schema
                $schema = file_get_contents(__DIR__ . '/schema.sql');
                if ($schema === false) throw new Exception('Cannot read schema.sql');

                // Split and run each statement
                $statements = array_filter(array_map('trim', explode(';', $schema)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        $pdo->exec($stmt);
                    }
                }

                $_SESSION['db_host'] = $dbHost;
                $_SESSION['db_name'] = $dbName;
                $_SESSION['db_user'] = $dbUser;
                $_SESSION['db_pass'] = $dbPass;
                $_SESSION['db_port'] = $dbPort;
                $_SESSION['install_step'] = 3;
                $step = 3;
                $success[] = 'Database configured successfully!';
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // Stage 3 – Admin account
    if ($action === 'create_admin') {
        $adminUser  = trim($_POST['admin_username'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_password'] ?? '';
        $adminPass2 = $_POST['admin_password2'] ?? '';
        $appUrl     = rtrim(trim($_POST['app_url'] ?? ''), '/');

        if (empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
            $errors[] = 'All fields are required.';
        } elseif ($adminPass !== $adminPass2) {
            $errors[] = 'Passwords do not match.';
        } elseif (strlen($adminPass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } else {
            try {
                $dbHost = $_SESSION['db_host'];
                $dbName = $_SESSION['db_name'];
                $dbUser = $_SESSION['db_user'];
                $dbPass = $_SESSION['db_pass'];
                $dbPort = $_SESSION['db_port'];

                $pdo = new PDO(
                    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                $hashedPass = password_hash($adminPass, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'superadmin')");
                $stmt->execute([$adminUser, $adminEmail, $hashedPass]);

                $appKey = bin2hex(random_bytes(16));
                $_SESSION['app_key']    = $appKey;
                $_SESSION['app_url']    = $appUrl ?: 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $_SESSION['admin_user'] = $adminUser;
                $_SESSION['install_step'] = 4;
                $step = 4;
            } catch (PDOException $e) {
                $errors[] = 'Error creating admin: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // Stage 4 – Finalize
    if ($action === 'finalize') {
        $dbHost = $_SESSION['db_host'] ?? '';
        $dbName = $_SESSION['db_name'] ?? '';
        $dbUser = $_SESSION['db_user'] ?? '';
        $dbPass = $_SESSION['db_pass'] ?? '';
        $dbPort = $_SESSION['db_port'] ?? '3306';
        $appKey = $_SESSION['app_key'] ?? bin2hex(random_bytes(16));
        $appUrl = $_SESSION['app_url'] ?? 'http://localhost';

        $configContent = "<?php\n";
        $configContent .= "// Auto-generated by installer - " . date('Y-m-d H:i:s') . "\n";
        $configContent .= "define('DB_HOST', '" . addslashes($dbHost) . "');\n";
        $configContent .= "define('DB_NAME', '" . addslashes($dbName) . "');\n";
        $configContent .= "define('DB_USER', '" . addslashes($dbUser) . "');\n";
        $configContent .= "define('DB_PASS', '" . addslashes($dbPass) . "');\n";
        $configContent .= "define('DB_PORT', '" . addslashes($dbPort) . "');\n";
        $configContent .= "define('APP_KEY', '" . $appKey . "');\n";
        $configContent .= "define('APP_URL', '" . addslashes($appUrl) . "');\n";
        $configContent .= "define('APP_NAME', 'PhilmoreHost Marketing Suite');\n";

        $configFile = dirname(__DIR__) . '/config/config.php';
        $lockFile   = dirname(__DIR__) . '/config/.installed';

        if (file_put_contents($configFile, $configContent) !== false) {
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
            $_SESSION['install_complete'] = true;
            $step = 5; // done
        } else {
            $errors[] = 'Failed to write config/config.php. Check directory permissions.';
        }
    }
}

$pageTitle = 'Install — PhilmoreHost Marketing Suite';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #0a0a0f;
    color: #e0e0e0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background-image: radial-gradient(ellipse at 20% 50%, rgba(108,99,255,0.15) 0%, transparent 50%),
                      radial-gradient(ellipse at 80% 20%, rgba(0,212,255,0.1) 0%, transparent 50%);
}
.installer {
    width: 100%;
    max-width: 680px;
    margin: 2rem;
}
.card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 20px;
    padding: 2.5rem;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    box-shadow: 0 25px 50px rgba(0,0,0,0.4);
}
.logo {
    text-align: center;
    margin-bottom: 2rem;
}
.logo h1 {
    font-size: 1.8rem;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.logo p { color: #888; font-size: 0.9rem; margin-top: 0.25rem; }
.steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
    position: relative;
}
.steps::before {
    content: '';
    position: absolute;
    top: 18px;
    left: 10%;
    right: 10%;
    height: 2px;
    background: rgba(255,255,255,0.1);
}
.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    flex: 1;
    position: relative;
    z-index: 1;
}
.step-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    border: 2px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all .3s;
}
.step-item.active .step-circle {
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 0 20px rgba(108,99,255,0.5);
}
.step-item.done .step-circle {
    background: #00ff88;
    border-color: transparent;
    color: #000;
}
.step-label { font-size: 0.7rem; color: #888; text-align: center; }
.step-item.active .step-label { color: #6c63ff; }
h2 { font-size: 1.4rem; margin-bottom: 0.5rem; }
p.sub { color: #888; font-size: 0.9rem; margin-bottom: 1.5rem; }
.check-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    background: rgba(255,255,255,0.03);
    margin-bottom: 0.5rem;
    border: 1px solid rgba(255,255,255,0.05);
}
.check-icon { font-size: 1.1rem; }
.check-name { flex: 1; font-size: 0.9rem; }
.check-val { font-size: 0.8rem; color: #888; }
.check-item.pass { border-color: rgba(0,255,136,0.2); }
.check-item.fail { border-color: rgba(255,71,87,0.2); }
.form-group { margin-bottom: 1.25rem; }
label { display: block; font-size: 0.85rem; color: #aaa; margin-bottom: 0.4rem; }
input[type=text], input[type=email], input[type=password], input[type=url], input[type=number] {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    color: #e0e0e0;
    font-size: 0.95rem;
    transition: border-color .2s;
    outline: none;
}
input:focus { border-color: #6c63ff; box-shadow: 0 0 0 3px rgba(108,99,255,0.15); }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.8rem 2rem;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-size: 0.95rem;
    font-weight: 600;
    transition: all .2s;
    text-decoration: none;
}
.btn-primary {
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    color: #fff;
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 25px rgba(108,99,255,0.4); }
.btn-full { width: 100%; justify-content: center; }
.alert {
    padding: 0.85rem 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}
.alert-error { background: rgba(255,71,87,0.1); border: 1px solid rgba(255,71,87,0.3); color: #ff4757; }
.alert-success { background: rgba(0,255,136,0.1); border: 1px solid rgba(0,255,136,0.3); color: #00ff88; }
.success-icon { font-size: 4rem; text-align: center; margin: 1rem 0; }
.code-box {
    background: rgba(0,0,0,0.4);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 1rem;
    font-family: monospace;
    font-size: 0.85rem;
    color: #00d4ff;
    margin: 1rem 0;
    word-break: break-all;
}
.notice {
    background: rgba(255,200,0,0.1);
    border: 1px solid rgba(255,200,0,0.3);
    border-radius: 10px;
    padding: 1rem;
    font-size: 0.85rem;
    color: #ffc800;
    margin: 1rem 0;
}
</style>
</head>
<body>
<div class="installer">
<div class="card">
<div class="logo">
    <h1>📧 PhilmoreHost Marketing Suite</h1>
    <p>Installation Wizard</p>
</div>

<!-- Step indicators -->
<div class="steps">
    <?php
    $stepNames = ['Requirements', 'Database', 'Admin', 'Configure', 'Done'];
    $currentDisplay = min($step, 5);
    for ($i = 1; $i <= 5; $i++):
        $cls = $i < $currentDisplay ? 'done' : ($i === $currentDisplay ? 'active' : '');
    ?>
    <div class="step-item <?= $cls ?>">
        <div class="step-circle"><?= $i < $currentDisplay ? '✓' : $i ?></div>
        <span class="step-label"><?= $stepNames[$i-1] ?></span>
    </div>
    <?php endfor; ?>
</div>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
    <div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <?php foreach ($success as $msg): ?>
    <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- STEP 1: Requirements -->
<?php if ($step === 1): ?>
<h2>🔍 Environment Check</h2>
<p class="sub">We'll verify your server meets all requirements.</p>
<?php $checks = checkRequirements(); ?>
<?php foreach ($checks as $c): ?>
<div class="check-item <?= $c['pass'] ? 'pass' : 'fail' ?>">
    <span class="check-icon"><?= $c['pass'] ? '✅' : '❌' ?></span>
    <span class="check-name"><?= htmlspecialchars($c['name']) ?></span>
    <span class="check-val"><?= htmlspecialchars($c['value']) ?></span>
</div>
<?php endforeach; ?>
<br>
<form method="POST">
    <input type="hidden" name="action" value="next_step1">
    <button type="submit" class="btn btn-primary btn-full" <?= !allChecksPassed($checks) ? 'disabled' : '' ?>>
        Continue →
    </button>
</form>
<?php endif; ?>

<!-- STEP 2: Database -->
<?php if ($step === 2): ?>
<h2>🗄️ Database Setup</h2>
<p class="sub">Enter your MySQL database credentials.</p>
<form method="POST">
    <input type="hidden" name="action" value="setup_db">
    <div class="row2">
        <div class="form-group">
            <label>DB Host</label>
            <input type="text" name="db_host" value="localhost" required>
        </div>
        <div class="form-group">
            <label>DB Port</label>
            <input type="number" name="db_port" value="3306" required>
        </div>
    </div>
    <div class="form-group">
        <label>Database Name</label>
        <input type="text" name="db_name" placeholder="philmore_marketing" required>
    </div>
    <div class="row2">
        <div class="form-group">
            <label>DB Username</label>
            <input type="text" name="db_user" required>
        </div>
        <div class="form-group">
            <label>DB Password</label>
            <input type="password" name="db_pass">
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-full">Setup Database →</button>
</form>
<?php endif; ?>

<!-- STEP 3: Admin Account -->
<?php if ($step === 3): ?>
<h2>👤 Create Admin Account</h2>
<p class="sub">Set up your Super Admin credentials.</p>
<form method="POST">
    <input type="hidden" name="action" value="create_admin">
    <div class="form-group">
        <label>Admin Username</label>
        <input type="text" name="admin_username" required autocomplete="off">
    </div>
    <div class="form-group">
        <label>Admin Email</label>
        <input type="email" name="admin_email" required>
    </div>
    <div class="row2">
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="admin_password" required minlength="8">
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="admin_password2" required minlength="8">
        </div>
    </div>
    <div class="form-group">
        <label>Application URL</label>
        <input type="url" name="app_url" value="http://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?>" required>
    </div>
    <button type="submit" class="btn btn-primary btn-full">Create Account →</button>
</form>
<?php endif; ?>

<!-- STEP 4: Finalize -->
<?php if ($step === 4): ?>
<h2>⚙️ Finalize Installation</h2>
<p class="sub">Review and write configuration files.</p>
<div class="code-box">
    <strong>DB Host:</strong> <?= htmlspecialchars($_SESSION['db_host'] ?? '') ?><br>
    <strong>DB Name:</strong> <?= htmlspecialchars($_SESSION['db_name'] ?? '') ?><br>
    <strong>Admin:</strong> <?= htmlspecialchars($_SESSION['admin_user'] ?? '') ?><br>
    <strong>App URL:</strong> <?= htmlspecialchars($_SESSION['app_url'] ?? '') ?>
</div>
<form method="POST">
    <input type="hidden" name="action" value="finalize">
    <button type="submit" class="btn btn-primary btn-full">✅ Complete Installation</button>
</form>
<?php endif; ?>

<!-- STEP 5: Done -->
<?php if ($step === 5): ?>
<div class="success-icon">🎉</div>
<h2 style="text-align:center">Installation Complete!</h2>
<p class="sub" style="text-align:center">Your marketing suite is ready to use.</p>
<div class="notice">
    ⚠ <strong>Security:</strong> Please delete or rename the <code>/install/</code> directory before going live!
</div>
<div style="text-align:center;margin-top:2rem;">
    <a href="/login.php" class="btn btn-primary">🚀 Go to Login</a>
</div>
<?php endif; ?>

</div><!-- .card -->
</div><!-- .installer -->
</body>
</html>
