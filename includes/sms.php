<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class PhilmoreSMS {
    private string $apiToken;
    private string $baseUrl = 'https://app.philmoresms.com/api';

    public function __construct(string $apiToken) {
        $this->apiToken = $apiToken;
    }

    /**
     * Factory: load token from sms_api_config, decrypt, and return instance.
     */
    public static function fromDB(): ?self {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT api_token_encrypted FROM sms_api_config WHERE id = 1 AND is_active = TRUE LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            if (!$row || empty($row['api_token_encrypted']) || !defined('APP_KEY')) {
                return null;
            }
            $token = decryptData($row['api_token_encrypted'], APP_KEY);
            if (empty($token)) {
                return null;
            }
            return new self($token);
        } catch (\Exception $e) {
            error_log('PhilmoreSMS::fromDB error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * POST form-encoded data to $endpoint. Token is injected automatically.
     */
    private function request(string $endpoint, array $params = []): array {
        $params['token'] = $this->apiToken;
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $response];
        }

        return $data ?? [];
    }

    public function checkBalance(): array {
        return $this->request('/balance.php');
    }

    public function sendBulkSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/sms.php', [
            'senderID'   => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ]);
    }

    public function sendCorporateSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/corporate.php', [
            'senderID'   => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ]);
    }

    public function sendGlobalSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/global-sms.php', [
            'senderID'   => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ]);
    }

    public function sendVoiceSMS(string $callerID, string $recipients, string $message): array {
        return $this->request('/voice.php', [
            'callerID'   => $callerID,
            'recipients' => $recipients,
            'message'    => $message,
        ]);
    }

    public function sendVoiceAudio(string $callerID, string $recipients, string $audioUrl): array {
        return $this->request('/voice_audio.php', [
            'callerID'   => $callerID,
            'recipients' => $recipients,
            'audio'      => $audioUrl,
        ]);
    }

    public function generateAndSendOTP(
        string $senderID,
        string $recipient,
        string $appnamecode,
        string $templatecode,
        string $otpType = 'NUMERIC',
        int $otpLength = 6,
        int $duration = 5
    ): array {
        return $this->request('/sendotp.php', [
            'senderID'     => $senderID,
            'recipients'   => $recipient,
            'appnamecode'  => $appnamecode,
            'templatecode' => $templatecode,
            'otp_type'     => $otpType,
            'otp_length'   => $otpLength,
            'otp_duration' => $duration,
        ]);
    }

    public function sendPreGeneratedOTP(string $senderID, string $recipient, string $otp, string $templatecode): array {
        return $this->request('/send_otp.php', [
            'senderID'     => $senderID,
            'recipients'   => $recipient,
            'otp'          => $otp,
            'templatecode' => $templatecode,
        ]);
    }

    public function verifyOTP(string $recipient, string $otp): array {
        return $this->request('/verifyotp.php', [
            'recipient' => $recipient,
            'otp'       => $otp,
        ]);
    }

    public function submitSenderID(string $senderID, string $sampleMessage): array {
        return $this->request('/senderID.php', [
            'senderID' => $senderID,
            'message'  => $sampleMessage,
        ]);
    }

    public function checkSenderIDStatus(string $senderID): array {
        return $this->request('/check_senderID.php', [
            'senderID' => $senderID,
        ]);
    }

    public function submitCallerID(string $callerID): array {
        return $this->request('/callerID.php', [
            'callerID' => $callerID,
        ]);
    }

    public function checkCallerIDStatus(string $callerID): array {
        return $this->request('/check_callerID.php', [
            'callerID' => $callerID,
        ]);
    }
}
