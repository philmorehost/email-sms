<?php
declare(strict_types=1);

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitizeEmail(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

function getClientIP(): string {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    // Fallback to REMOTE_ADDR even if private
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function encryptData(string $data, string $key): string {
    $iv = random_bytes(16);
    $keyHash = hash('sha256', $key, true);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $keyHash, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $encrypted);
}

function decryptData(string $encrypted, string $key): string {
    $data = base64_decode($encrypted);
    if ($data === false || strlen($data) < 16) {
        return '';
    }
    $iv = substr($data, 0, 16);
    $cipherText = substr($data, 16);
    $keyHash = hash('sha256', $key, true);
    $decrypted = openssl_decrypt($cipherText, 'AES-256-CBC', $keyHash, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

function rateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): bool {
    $dir = __DIR__ . '/../storage/rate_limits';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . md5($key) . '.json';
    $now = time();
    $data = ['count' => 0, 'window_start' => $now];

    if (file_exists($file)) {
        $stored = json_decode(file_get_contents($file), true);
        if ($stored && ($now - $stored['window_start']) < $windowSeconds) {
            $data = $stored;
        }
    }

    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxRequests;
}

function getCountryFromIP(string $ip): ?string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    // Try ip-api.com (free tier)
    $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['countryCode'])) {
            return strtoupper($data['countryCode']);
        }
    }

    return null;
}

function csrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(32);
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $valid = !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        // Rotate token after use
        $_SESSION['csrf_token'] = generateToken(32);
    }
    return $valid;
}

function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

function timeAgo(\DateTimeInterface|string $datetime): string {
    if (is_string($datetime)) {
        $datetime = new \DateTime($datetime);
    }
    $diff = (new \DateTime())->getTimestamp() - $datetime->getTimestamp();

    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return $datetime->format('M j, Y');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

/**
 * Return the currency symbol configured by admin (default: ₦).
 */
function currencySymbol(): string {
    static $sym = null;
    if ($sym !== null) return $sym;
    try {
        $db  = getDB();
        $row = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='currency_symbol'")->fetch();
        $sym = ($row && $row['setting_value'] !== '') ? $row['setting_value'] : '₦';
    } catch (\Exception $e) {
        $sym = '₦';
    }
    return $sym;
}

/**
 * Format a monetary amount with the configured currency symbol.
 */
function formatMoney(float $amount, int $decimals = 2): string {
    return currencySymbol() . number_format($amount, $decimals);
}

function setSecurityHeaders(): void {
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:;");
}
