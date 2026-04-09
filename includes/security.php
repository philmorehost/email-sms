<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function checkSecurityFirewall(string $ip, string $username = ''): array {
    try {
        $db = getDB();

        // Check IP blacklist
        $stmt = $db->prepare("SELECT * FROM ip_blacklist WHERE ip_address = ? AND (block_until IS NULL OR block_until > NOW())");
        $stmt->execute([$ip]);
        $blacklisted = $stmt->fetch();
        if ($blacklisted) {
            logSecurityEvent('blocked_ip', [
                'ip_address' => $ip,
                'username'   => $username,
                'details'    => 'IP is blacklisted: ' . ($blacklisted['reason'] ?? 'No reason'),
            ]);
            return ['allowed' => false, 'reason' => 'Your IP address has been blocked.'];
        }

        // Check country firewall
        $countryCode = getCountryFromIP($ip);
        if ($countryCode) {
            $stmt = $db->prepare("SELECT status FROM country_firewall WHERE country_code = ?");
            $stmt->execute([$countryCode]);
            $country = $stmt->fetch();
            if ($country && $country['status'] === 'blacklisted') {
                logSecurityEvent('blocked_country', [
                    'ip_address'   => $ip,
                    'username'     => $username,
                    'country_code' => $countryCode,
                    'details'      => 'Country is blacklisted',
                ]);
                return ['allowed' => false, 'reason' => 'Access from your country is not allowed.'];
            }
        }

        // Check brute force
        $stmt = $db->prepare("SELECT id FROM security_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch();

        if ($settings) {
            $stmt = $db->prepare("SELECT * FROM security_settings WHERE id = 1");
            $stmt->execute();
            $settings = $stmt->fetch();

            $period = (int)($settings['brute_force_period'] ?? 15);
            $maxIp   = (int)($settings['max_failures_ip'] ?? 5);
            $maxUser = (int)($settings['max_failures_user'] ?? 3);

            // Check IP attempts
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
            $stmt->execute([$ip, $period]);
            $ipAttempts = (int)$stmt->fetchColumn();

            if ($ipAttempts >= $maxIp) {
                return ['allowed' => false, 'reason' => 'Too many login attempts from your IP. Please wait.'];
            }

            // Check username attempts
            if ($username) {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_attempts WHERE username = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $stmt->execute([$username, $period]);
                $userAttempts = (int)$stmt->fetchColumn();

                if ($userAttempts >= $maxUser) {
                    return ['allowed' => false, 'reason' => 'Too many failed login attempts for this account.'];
                }
            }
        }

        return ['allowed' => true, 'reason' => ''];
    } catch (\Exception $e) {
        error_log('Security firewall error: ' . $e->getMessage());
        return ['allowed' => true, 'reason' => ''];
    }
}

function recordFailedLogin(string $ip, string $username): void {
    try {
        $db = getDB();

        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
        $stmt->execute([$ip, $username]);

        logSecurityEvent('failed_login', [
            'ip_address' => $ip,
            'username'   => $username,
            'details'    => 'Failed login attempt',
        ]);

        // Check thresholds for auto-ban
        $stmt = $db->prepare("SELECT * FROM security_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch();

        if (!$settings) return;

        $period = (int)($settings['brute_force_period'] ?? 15);
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->execute([$ip, $period]);
        $attempts = (int)$stmt->fetchColumn();

        $blockType = null;
        $blockUntil = null;

        $oneDay   = (int)($settings['one_day_block_threshold']   ?? 2);
        $oneWeek  = (int)($settings['one_week_block_threshold']  ?? 5);
        $oneMonth = (int)($settings['one_month_block_threshold'] ?? 10);
        $oneYear  = (int)($settings['one_year_block_threshold']  ?? 20);

        if ($attempts >= $oneYear) {
            $blockType  = 'one_year';
            $blockUntil = date('Y-m-d H:i:s', strtotime('+1 year'));
        } elseif ($attempts >= $oneMonth) {
            $blockType  = 'one_month';
            $blockUntil = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($attempts >= $oneWeek) {
            $blockType  = 'one_week';
            $blockUntil = date('Y-m-d H:i:s', strtotime('+1 week'));
        } elseif ($attempts >= $oneDay) {
            $blockType  = 'one_day';
            $blockUntil = date('Y-m-d H:i:s', strtotime('+1 day'));
        }

        if ($blockType) {
            $stmt = $db->prepare("INSERT INTO ip_blacklist (ip_address, reason, block_until, block_type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), block_until=VALUES(block_until), block_type=VALUES(block_type)");
            $stmt->execute([$ip, "Auto-banned after {$attempts} failed attempts", $blockUntil, $blockType]);

            logSecurityEvent('auto_ban', [
                'ip_address' => $ip,
                'username'   => $username,
                'details'    => "Auto-banned ({$blockType}) after {$attempts} failed attempts",
            ]);
        }
    } catch (\Exception $e) {
        error_log('recordFailedLogin error: ' . $e->getMessage());
    }
}

function recordSuccessfulLogin(string $ip, int $userId): void {
    try {
        $db = getDB();

        // Upsert whitelist entry
        $stmt = $db->prepare("INSERT INTO ip_whitelist (ip_address, session_count, last_login) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE session_count = session_count + 1, last_login = NOW()");
        $stmt->execute([$ip]);

        // Check if should mark as trusted (>=5 sessions)
        $stmt = $db->prepare("SELECT session_count FROM ip_whitelist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $wl = $stmt->fetch();
        if ($wl && (int)$wl['session_count'] >= 5) {
            $stmt = $db->prepare("UPDATE ip_whitelist SET is_trusted = TRUE WHERE ip_address = ?");
            $stmt->execute([$ip]);
        }

        // Clear failed attempts for this IP
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);

        // Update last_login
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);

        logSecurityEvent('successful_login', [
            'ip_address' => $ip,
            'details'    => "User ID {$userId} logged in successfully",
        ]);
    } catch (\Exception $e) {
        error_log('recordSuccessfulLogin error: ' . $e->getMessage());
    }
}

function isIPTrusted(string $ip): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT is_trusted FROM ip_whitelist WHERE ip_address = ? AND is_trusted = TRUE");
        $stmt->execute([$ip]);
        return (bool)$stmt->fetchColumn();
    } catch (\Exception $e) {
        return false;
    }
}

function logSecurityEvent(string $eventType, array $data): void {
    try {
        $db = getDB();
        $ip          = $data['ip_address'] ?? null;
        $username    = $data['username'] ?? null;
        $countryCode = $data['country_code'] ?? null;
        $details     = $data['details'] ?? null;
        $isTrusted   = isIPTrusted($ip ?? '');

        $stmt = $db->prepare("INSERT INTO security_logs (event_type, ip_address, username, country_code, details, is_trusted) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$eventType, $ip, $username, $countryCode, $details, $isTrusted]);
    } catch (\Exception $e) {
        error_log('logSecurityEvent error: ' . $e->getMessage());
    }
}

function getRecentSecurityLogs(int $limit = 50): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM security_logs ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (\Exception $e) {
        return [];
    }
}
