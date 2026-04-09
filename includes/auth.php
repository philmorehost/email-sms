<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';

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

        // Successful login
        startSecureSession();
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['logged_in_at'] = time();

        recordSuccessfulLogin($ip, (int)$user['id']);

        return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
    } catch (\Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.', 'user' => null];
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
