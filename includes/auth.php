<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

function requireAuth(): void {
    startSecureSession();
    if (empty($_SESSION['user_id'])) {
        redirect('/login.php');
    }
    // Check session validity
    if (!empty($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        logout();
        redirect('/login.php');
    }
}

function requireAdmin(): void {
    requireAuth();
    $user = getCurrentUser();
    if (!$user || !in_array($user['role'], ['superadmin', 'admin'])) {
        redirect('/dashboard.php');
    }
}

function login(string $username, string $password): array {
    try {
        $ip = getClientIP();

        // Check security firewall
        $firewall = checkSecurityFirewall($ip, $username);
        if (!$firewall['allowed']) {
            return ['success' => false, 'message' => $firewall['reason'], 'user' => null];
        }

        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_suspended = FALSE");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            recordFailedLogin($ip, $username);
            return ['success' => false, 'message' => 'Invalid username or password.', 'user' => null];
        }

        // Check user blacklist
        $stmt = $db->prepare("SELECT reason FROM user_blacklist WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $blacklisted = $stmt->fetch();
        if ($blacklisted) {
            return ['success' => false, 'message' => 'Your account has been suspended.', 'user' => null];
        }

        $userId = (int)$user['id'];

        // MFA check
        if (!empty($user['mfa_enabled'])) {
            if (isDeviceTrusted($userId)) {
                // Trusted device — skip OTP
            } else {
                sendLoginOTP($userId, $user['email']);
                return ['success' => false, 'otp_required' => true, 'user_id' => $userId, 'email' => $user['email']];
            }
        }

        // Successful login
        startSecureSession();
        session_regenerate_id(true);

        $_SESSION['user_id']      = $userId;
        $_SESSION['username']     = $user['username'];
        $_SESSION['role']         = $user['role'];
        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['logged_in_at'] = time();

        recordSuccessfulLogin($ip, $userId);

        return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
    } catch (\Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.', 'user' => null];
    }
}

function isDeviceTrusted(int $userId): bool {
    $token = $_COOKIE['trusted_device_token'] ?? '';
    if ($token === '') {
        return false;
    }
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id FROM trusted_devices WHERE user_id = ? AND device_token = ? AND expires_at > NOW()");
        $stmt->execute([$userId, $token]);
        return (bool)$stmt->fetch();
    } catch (\Exception $e) {
        error_log('isDeviceTrusted error: ' . $e->getMessage());
        return false;
    }
}

function sendLoginOTP(int $userId, string $email): bool {
    try {
        $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600);

        $db   = getDB();
        // Invalidate any previous unused OTPs for this user
        $db->prepare("UPDATE login_otps SET used = TRUE WHERE user_id = ? AND used = FALSE")->execute([$userId]);

        $stmt = $db->prepare("INSERT INTO login_otps (user_id, otp_code, expires_at, used) VALUES (?, ?, ?, FALSE)");
        $stmt->execute([$userId, $otp, $expires]);

        $mailer  = new Mailer();
        $subject = 'Your Login Verification Code';
        $html    = '<p>Your verification code is: <strong style="font-size:1.4em;letter-spacing:0.15em">' . $otp . '</strong></p>'
                 . '<p>This code expires in 10 minutes. Do not share it with anyone.</p>';
        $text    = "Your verification code is: {$otp}\nThis code expires in 10 minutes.";

        return $mailer->send([['email' => $email, 'name' => '']], $subject, $html, $text);
    } catch (\Exception $e) {
        error_log('sendLoginOTP error: ' . $e->getMessage());
        return false;
    }
}

function verifyLoginOTP(int $userId, string $otp, bool $rememberDevice, string $ip, string $userAgent): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT id FROM login_otps WHERE user_id = ? AND otp_code = ? AND used = FALSE AND expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$userId, $otp]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Invalid or expired code. Please try again.'];
        }

        // Mark OTP used
        $db->prepare("UPDATE login_otps SET used = TRUE WHERE id = ?")->execute([$row['id']]);

        if ($rememberDevice) {
            $token   = bin2hex(random_bytes(32)); // 64-char hex
            $expires = date('Y-m-d H:i:s', time() + 30 * 86400);
            $db->prepare(
                "INSERT INTO trusted_devices (user_id, device_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)"
            )->execute([$userId, $token, $ip, $userAgent, $expires]);

            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            setcookie('trusted_device_token', $token, [
                'expires'  => time() + 30 * 86400,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $secure,
                'samesite' => 'Strict',
            ]);
        }

        // Fetch user and complete session
        $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        startSecureSession();
        session_regenerate_id(true);

        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['username']     = $user['username'];
        $_SESSION['role']         = $user['role'];
        $_SESSION['user_agent']   = $userAgent;
        $_SESSION['logged_in_at'] = time();

        unset($_SESSION['otp_pending_user_id'], $_SESSION['otp_pending_email']);

        recordSuccessfulLogin($ip, (int)$user['id']);

        return ['success' => true];
    } catch (\Exception $e) {
        error_log('verifyLoginOTP error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
}


function logout(): void {
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function getCurrentUser(): ?array {
    startSecureSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, email, role, notify_on_login, created_at, last_login FROM users WHERE id = ? AND is_suspended = FALSE");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    } catch (\Exception $e) {
        return null;
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    return !empty($_SESSION['user_id']);
}

// ── Registration helpers ──────────────────────────────────────────────────────

/**
 * Initiate registration: validate uniqueness, store pending OTP.
 * Returns ['success'=>bool, 'message'=>string]
 */
function registerUser(string $username, string $email, string $password, string $fullName = ''): array {
    try {
        $db = getDB();

        // Ensure email_verification_otps table exists
        $db->exec("CREATE TABLE IF NOT EXISTS email_verification_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(10) NOT NULL,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) DEFAULT '',
            expires_at TIMESTAMP NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_otp (email, otp_code)
        )");

        // Check username uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username is already taken. Please choose another.'];
        }

        // Check email uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'An account with this email already exists. Please login.'];
        }

        $otp  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $exp  = date('Y-m-d H:i:s', time() + 900); // 15 min

        // Invalidate existing pending OTPs for this email
        $db->prepare("UPDATE email_verification_otps SET used = TRUE WHERE email = ? AND used = FALSE")->execute([$email]);

        $db->prepare("INSERT INTO email_verification_otps (email, otp_code, username, password_hash, full_name, expires_at) VALUES (?,?,?,?,?,?)")
           ->execute([$email, $otp, $username, $hash, $fullName, $exp]);

        $mailer  = new Mailer();
        $subject = 'Verify your email — ' . (defined('APP_NAME') ? APP_NAME : 'Marketing Suite');
        $html = '
<div style="font-family:system-ui,sans-serif;max-width:480px;margin:auto;background:#0f0f18;color:#e0e0e0;border-radius:16px;padding:2.5rem;border:1px solid rgba(255,255,255,0.1)">
  <h2 style="margin:0 0 1rem;background:linear-gradient(135deg,#6c63ff,#00d4ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Welcome to ' . htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Marketing Suite') . '!</h2>
  <p style="color:#a0a0b0;margin-bottom:1.5rem">Thanks for signing up, <strong style="color:#e0e0e0">' . htmlspecialchars($username) . '</strong>. Use the code below to verify your email address.</p>
  <div style="background:rgba(108,99,255,0.15);border:1px solid rgba(108,99,255,0.4);border-radius:12px;padding:1.5rem;text-align:center;margin-bottom:1.5rem">
    <div style="font-size:2.5rem;font-weight:800;letter-spacing:.3em;color:#6c63ff">' . $otp . '</div>
    <p style="color:#a0a0b0;margin:.5rem 0 0;font-size:.85rem">Expires in 15 minutes</p>
  </div>
  <p style="color:#606070;font-size:.8rem">If you did not create an account, you can safely ignore this email.</p>
</div>';
        $text = "Your verification code is: {$otp}\nExpires in 15 minutes.";
        $mailer->send([['email' => $email, 'name' => $username]], $subject, $html, $text);

        return ['success' => true, 'message' => 'Verification code sent to your email.'];
    } catch (\Exception $e) {
        error_log('registerUser error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
}

/**
 * Verify registration OTP and create the user account.
 * Returns ['success'=>bool, 'message'=>string]
 */
function verifyRegistrationOTP(string $email, string $otp): array {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT * FROM email_verification_otps WHERE email = ? AND otp_code = ? AND used = FALSE AND expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$email, $otp]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Invalid or expired code. Please try again.'];
        }

        // Mark used
        $db->prepare("UPDATE email_verification_otps SET used = TRUE WHERE id = ?")->execute([$row['id']]);

        // Check uniqueness again (race condition)
        $chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk->execute([$row['username'], $email]);
        if ($chk->fetch()) {
            return ['success' => false, 'message' => 'Username or email already registered. Please login.'];
        }

        // Create user
        $ins = $db->prepare(
            "INSERT INTO users (username, email, password, role, is_suspended, mfa_enabled, notify_on_login, created_at)
             VALUES (?, ?, ?, 'user', FALSE, TRUE, TRUE, NOW())"
        );
        $ins->execute([$row['username'], $email, $row['password_hash']]);
        $userId = (int)$db->lastInsertId();

        // Auto-login
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION['user_id']      = $userId;
        $_SESSION['username']     = $row['username'];
        $_SESSION['role']         = 'user';
        $_SESSION['user_agent']   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['logged_in_at'] = time();

        return ['success' => true, 'message' => 'Account created successfully! Welcome aboard.', 'user_id' => $userId];
    } catch (\Exception $e) {
        error_log('verifyRegistrationOTP error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
}

/**
 * Resend registration OTP.
 */
function resendRegistrationOTP(string $email): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM email_verification_otps WHERE email = ? AND used = FALSE ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $exp = date('Y-m-d H:i:s', time() + 900);

        $db->prepare("UPDATE email_verification_otps SET used = TRUE WHERE email = ? AND used = FALSE")->execute([$email]);
        $db->prepare("INSERT INTO email_verification_otps (email, otp_code, username, password_hash, full_name, expires_at) VALUES (?,?,?,?,?,?)")
           ->execute([$email, $otp, $row['username'], $row['password_hash'], $row['full_name'], $exp]);

        $mailer  = new Mailer();
        $subject = 'New Verification Code — ' . (defined('APP_NAME') ? APP_NAME : 'Marketing Suite');
        $html = '<p>Your new verification code is: <strong style="font-size:1.4em;letter-spacing:.2em">' . $otp . '</strong></p><p>Expires in 15 minutes.</p>';
        $text = "Your new verification code: {$otp}\nExpires in 15 minutes.";
        $mailer->send([['email' => $email, 'name' => $row['username']]], $subject, $html, $text);
        return true;
    } catch (\Exception $e) {
        error_log('resendRegistrationOTP error: ' . $e->getMessage());
        return false;
    }
}
