<?php
/**
 * Ayrshare Unified Social API Wrapper
 *
 * All methods return an array:
 *   ['success' => bool, 'data' => array|null, 'message' => string]
 *
 * Ayrshare API docs: https://docs.ayrshare.com/
 */
declare(strict_types=1);

class AyrshareClient
{
    private string $apiKey;
    private string $baseUrl = 'https://app.ayrshare.com/api';
    private int $timeout = 30;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    // ── Profile management ────────────────────────────────────────────────────

    /**
     * Create a new Ayrshare profile for a user and return the profile key.
     * Uses Business Plan "Create Profile" endpoint.
     */
    public function createProfile(int $userId, string $displayTitle): array
    {
        return $this->request('POST', '/profiles/profile', [
            'title'      => $displayTitle,
            'referenceId' => 'user_' . $userId,
        ]);
    }

    /**
     * Delete a user's Ayrshare profile.
     */
    public function deleteProfile(string $profileKey): array
    {
        return $this->request('DELETE', '/profiles/profile', ['profileKey' => $profileKey]);
    }

    /**
     * Generate a "Link Social Account" JWT URL for the user.
     * The user visits this URL to connect their social accounts.
     */
    public function generateLinkUrl(string $profileKey, string $redirectUrl = ''): array
    {
        $payload = ['profileKey' => $profileKey];
        if ($redirectUrl !== '') {
            $payload['redirect'] = $redirectUrl;
        }
        return $this->request('POST', '/profiles/generateJWT', $payload);
    }

    /**
     * Get list of social platforms the user has connected.
     */
    public function getLinkedPlatforms(string $profileKey): array
    {
        return $this->request('GET', '/user?profileKey=' . urlencode($profileKey));
    }

    // ── Posting ───────────────────────────────────────────────────────────────

    /**
     * Post immediately to one or more platforms.
     *
     * @param string   $profileKey  Ayrshare profile key
     * @param string[] $platforms   e.g. ['facebook','instagram','linkedin']
     * @param string   $post        Caption / post text
     * @param string[] $mediaUrls   Public image/video URLs (optional)
     */
    public function postNow(
        string $profileKey,
        array $platforms,
        string $post,
        array $mediaUrls = []
    ): array {
        $payload = [
            'post'       => $post,
            'platforms'  => $platforms,
            'profileKey' => $profileKey,
        ];
        if (!empty($mediaUrls)) {
            $payload['mediaUrls'] = $mediaUrls;
        }
        return $this->request('POST', '/post', $payload);
    }

    /**
     * Schedule a post for a future UTC datetime.
     *
     * @param string $scheduledDate ISO 8601 UTC, e.g. "2026-04-15T14:00:00Z"
     */
    public function schedulePost(
        string $profileKey,
        array $platforms,
        string $post,
        string $scheduledDate,
        array $mediaUrls = []
    ): array {
        $payload = [
            'post'          => $post,
            'platforms'     => $platforms,
            'profileKey'    => $profileKey,
            'scheduleDate'  => $scheduledDate,
        ];
        if (!empty($mediaUrls)) {
            $payload['mediaUrls'] = $mediaUrls;
        }
        return $this->request('POST', '/post', $payload);
    }

    /**
     * Delete a scheduled or posted item by its Ayrshare post ID.
     */
    public function deletePost(string $profileKey, string $postId): array
    {
        return $this->request('DELETE', '/post', [
            'id'         => $postId,
            'profileKey' => $profileKey,
        ]);
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    /**
     * Fetch engagement analytics for a specific post.
     */
    public function getPostAnalytics(string $profileKey, string $postId): array
    {
        return $this->request(
            'GET',
            '/analytics/post?id=' . urlencode($postId) . '&profileKey=' . urlencode($profileKey)
        );
    }

    /**
     * Fetch social analytics for the profile (last 30 days).
     */
    public function getProfileAnalytics(string $profileKey): array
    {
        return $this->request(
            'GET',
            '/analytics/social?platforms[]=facebook&platforms[]=instagram&platforms[]=linkedin&profileKey=' . urlencode($profileKey)
        );
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $payload = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $curlOpts[CURLOPT_POST]       = true;
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($payload);
        } elseif ($method === 'DELETE') {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            if (!empty($payload)) {
                $curlOpts[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
        }
        // GET: payload already embedded in URL

        curl_setopt_array($ch, $curlOpts);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $body === false) {
            error_log('Ayrshare cURL error: ' . $curlErr);
            return ['success' => false, 'data' => null, 'message' => 'Network error: ' . $curlErr];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['success' => false, 'data' => null, 'message' => 'Invalid response from Ayrshare (HTTP ' . $httpCode . ')'];
        }

        // Ayrshare returns status:'success' or status:'error'
        $ok = ($httpCode >= 200 && $httpCode < 300) && (($data['status'] ?? '') !== 'error');
        $errMsg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $httpCode);

        return [
            'success' => $ok,
            'data'    => $data,
            'message' => $ok ? 'OK' : (string)$errMsg,
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Build instance from app_settings in DB.
     * Returns null if the API key is not configured.
     */
    public static function fromDB(): ?self
    {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='ayrshare_api_key'");
            $stmt->execute();
            $key  = trim((string)($stmt->fetchColumn() ?: ''));
            return $key !== '' ? new self($key) : null;
        } catch (\Exception $e) {
            error_log('AyrshareClient::fromDB error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Helper: load social settings from app_settings.
     */
    public static function loadSettings(\PDO $db): array
    {
        $defaults = [
            'ayrshare_api_key'                  => '',
            'social_enabled'                    => '0',
            'social_tokens_per_post_now'        => '1',
            'social_tokens_per_scheduled_post'  => '5',
            'social_tokens_per_ab_variant'      => '2',
        ];
        try {
            $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(\PDO::FETCH_KEY_PAIR);
            return array_merge($defaults, array_intersect_key($rows, $defaults));
        } catch (\Exception $e) {
            return $defaults;
        }
    }

    /**
     * Ensure all social tables exist (inline migration).
     */
    public static function migrate(\PDO $db): void
    {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS social_connections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                ayrshare_profile_key VARCHAR(255) NOT NULL,
                platforms_json JSON,
                follower_activity_json JSON,
                activity_updated_at TIMESTAMP NULL,
                connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sc_user (user_id)
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS social_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                platform_mask VARCHAR(255) NOT NULL DEFAULT '',
                caption TEXT,
                hashtags TEXT,
                image_url VARCHAR(500),
                status ENUM('draft','scheduled','posting','posted','failed') DEFAULT 'draft',
                scheduled_at DATETIME NULL,
                posted_at DATETIME NULL,
                ayrshare_post_id VARCHAR(255) NULL,
                analytics_json JSON,
                analytics_updated_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_soc_user (user_id),
                INDEX idx_soc_status (status),
                INDEX idx_soc_scheduled (scheduled_at)
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS social_token_packages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                tokens INT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS user_social_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                balance INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ust_user (user_id)
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS social_credit_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                delta INT NOT NULL,
                action ENUM('purchase','post_now','scheduled_post','ab_variant','refund','admin_grant') NOT NULL DEFAULT 'post_now',
                campaign_id INT NULL,
                description VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sct_user (user_id),
                INDEX idx_sct_action (action)
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS social_analytics_cache (
                campaign_id INT PRIMARY KEY,
                analytics_json JSON,
                cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
            $db->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
                ('ayrshare_api_key', ''),
                ('social_enabled', '0'),
                ('social_tokens_per_post_now', '1'),
                ('social_tokens_per_scheduled_post', '5'),
                ('social_tokens_per_ab_variant', '2')
            ");
        } catch (\Exception $e) {
            error_log('Social migrate error: ' . $e->getMessage());
        }
    }

    /**
     * Deduct social tokens from a user, returning false if balance insufficient.
     */
    public static function deductTokens(
        \PDO $db,
        int $userId,
        int $cost,
        string $action,
        string $description,
        int $campaignId = 0
    ): bool {
        try {
            $db->beginTransaction();
            // Ensure row exists
            $db->prepare("INSERT IGNORE INTO user_social_tokens (user_id, balance) VALUES (?,0)")->execute([$userId]);
            // Fetch balance with lock
            $bStmt = $db->prepare("SELECT balance FROM user_social_tokens WHERE user_id=? FOR UPDATE");
            $bStmt->execute([$userId]);
            $bal = (int)($bStmt->fetchColumn() ?: 0);

            if ($bal < $cost) {
                $db->rollBack();
                return false;
            }
            $db->prepare("UPDATE user_social_tokens SET balance=balance-?, updated_at=NOW() WHERE user_id=?")
               ->execute([$cost, $userId]);
            $db->prepare("INSERT INTO social_credit_transactions (user_id,delta,action,campaign_id,description) VALUES(?,?,?,?,?)")
               ->execute([$userId, -$cost, $action, $campaignId ?: null, $description]);
            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Social deductTokens error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add social tokens to a user (purchase / admin grant).
     */
    public static function addTokens(
        \PDO $db,
        int $userId,
        int $amount,
        string $action,
        string $description
    ): void {
        try {
            $db->prepare("INSERT INTO user_social_tokens (user_id,balance) VALUES(?,?) ON DUPLICATE KEY UPDATE balance=balance+?, updated_at=NOW()")
               ->execute([$userId, $amount, $amount]);
            $db->prepare("INSERT INTO social_credit_transactions (user_id,delta,action,description) VALUES(?,?,?,?)")
               ->execute([$userId, $amount, $action, $description]);
        } catch (\Exception $e) {
            error_log('Social addTokens error: ' . $e->getMessage());
        }
    }
}
