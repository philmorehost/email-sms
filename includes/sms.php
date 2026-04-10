<?php
declare(strict_types=1);

class PhilmoreSMS {
    private string $apiToken;
    private string $baseUrl = 'https://app.philmoresms.com/api';

    public function __construct(string $apiToken) {
        $this->apiToken = $apiToken;
    }

    private function request(string $endpoint, array $params = [], string $method = 'GET'): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        } elseif (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $data = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response', 'raw' => $response, 'http_code' => $httpCode];
        }

        return array_merge(['http_code' => $httpCode], $data ?? []);
    }

    public function checkBalance(): array {
        return $this->request('/balance');
    }

    public function sendBulkSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/sms/bulk', [
            'sender_id'  => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ], 'POST');
    }

    public function sendCorporateSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/sms/corporate', [
            'sender_id'  => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ], 'POST');
    }

    public function sendGlobalSMS(string $senderID, string $recipients, string $message): array {
        return $this->request('/sms/global', [
            'sender_id'  => $senderID,
            'recipients' => $recipients,
            'message'    => $message,
        ], 'POST');
    }

    public function sendVoiceSMS(string $callerID, string $recipients, string $message): array {
        return $this->request('/sms/voice', [
            'caller_id'  => $callerID,
            'recipients' => $recipients,
            'message'    => $message,
        ], 'POST');
    }

    public function sendVoiceAudio(string $callerID, string $recipients, string $audioUrl): array {
        return $this->request('/sms/voice/audio', [
            'caller_id'  => $callerID,
            'recipients' => $recipients,
            'audio_url'  => $audioUrl,
        ], 'POST');
    }

    public function generateAndSendOTP(
        string $senderID,
        string $recipient,
        string $appname,
        string $templatecode,
        string $otpType = 'NUMERIC',
        int $otpLength = 6,
        int $duration = 5
    ): array {
        return $this->request('/otp/generate', [
            'sender_id'     => $senderID,
            'recipient'     => $recipient,
            'appname'       => $appname,
            'templatecode'  => $templatecode,
            'otp_type'      => $otpType,
            'otp_length'    => $otpLength,
            'duration'      => $duration,
        ], 'POST');
    }

    public function sendPreGeneratedOTP(string $senderID, string $recipient, string $otp, string $templatecode): array {
        return $this->request('/otp/send', [
            'sender_id'    => $senderID,
            'recipient'    => $recipient,
            'otp'          => $otp,
            'templatecode' => $templatecode,
        ], 'POST');
    }

    public function verifyOTP(string $recipient, string $otp): array {
        return $this->request('/otp/verify', [
            'recipient' => $recipient,
            'otp'       => $otp,
        ], 'POST');
    }

    public function submitSenderID(string $senderID, string $sampleMessage): array {
        return $this->request('/sender-ids', [
            'sender_id'      => $senderID,
            'sample_message' => $sampleMessage,
        ], 'POST');
    }

    public function checkSenderIDStatus(string $senderID): array {
        return $this->request('/sender-ids/' . urlencode($senderID));
    }

    public function submitCallerID(string $callerID): array {
        return $this->request('/caller-ids', [
            'caller_id' => $callerID,
        ], 'POST');
    }

    public function checkCallerIDStatus(string $callerID): array {
        return $this->request('/caller-ids/' . urlencode($callerID));
    }
}
