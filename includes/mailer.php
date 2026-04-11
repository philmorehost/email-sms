<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class Mailer {
    private array $settings = [];

    public function __construct(int $userId = 0) {
        try {
            $db = getDB();
            // Try per-user SMTP settings first (special plan users)
            if ($userId > 0) {
                $ustmt = $db->prepare(
                    "SELECT * FROM user_smtp_settings WHERE user_id = ? AND is_active = 1 LIMIT 1"
                );
                $ustmt->execute([$userId]);
                $userSettings = $ustmt->fetch();
                if ($userSettings) {
                    // Map user SMTP record to the same key format as smtp_settings
                    $provider = $userSettings['provider'];
                    $mapped   = [
                        'provider'              => $provider,
                        'from_email'            => $userSettings['from_email'] ?? '',
                        'from_name'             => $userSettings['from_name'] ?? '',
                        'host'                  => $userSettings['smtp_host'] ?? '',
                        'port'                  => $userSettings['smtp_port'] ?? 587,
                        'username'              => $userSettings['smtp_username'] ?? '',
                        'password_encrypted'    => $userSettings['smtp_password_enc'] ?? '',
                        'encryption'            => $userSettings['smtp_encryption'] ?? 'tls',
                    ];
                    // For API-key providers, put the encrypted key under the provider-specific field
                    if (!empty($userSettings['api_key_enc'])) {
                        $mapped[$provider . '_api_key_encrypted'] = $userSettings['api_key_enc'];
                    }
                    $this->settings = $mapped;
                    return;
                }
            }
            // Fall back to system-wide SMTP settings
            $stmt = $db->prepare("SELECT * FROM smtp_settings WHERE id = 1");
            $stmt->execute();
            $this->settings = $stmt->fetch() ?: [];
        } catch (\Exception $e) {
            error_log('Mailer init error: ' . $e->getMessage());
        }
    }

    public function send(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $provider = $this->settings['provider'] ?? 'smtp';
        switch ($provider) {
            case 'sendgrid': return $this->sendViaSendGrid($to, $subject, $htmlBody, $textBody);
            case 'mailgun':  return $this->sendViaMailgun($to, $subject, $htmlBody, $textBody);
            case 'ses':      return $this->sendViaSES($to, $subject, $htmlBody, $textBody);
            case 'resend':   return $this->sendViaResend($to, $subject, $htmlBody, $textBody);
            case 'postmark': return $this->sendViaPostmark($to, $subject, $htmlBody, $textBody);
            case 'brevo':    return $this->sendViaBrevo($to, $subject, $htmlBody, $textBody);
            case 'mailjet':  return $this->sendViaMailjet($to, $subject, $htmlBody, $textBody);
            case 'aweber':   return $this->sendViaAWeber($to, $subject, $htmlBody, $textBody);
            default:         return $this->sendViaSMTP($to, $subject, $htmlBody, $textBody);
        }
    }

    private function sendViaSMTP(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $host       = $this->settings['host'] ?? '';
        $port       = (int)($this->settings['port'] ?? 587);
        $username   = $this->settings['username'] ?? '';
        $password   = '';
        $encryption = $this->settings['encryption'] ?? 'tls';
        $fromEmail  = $this->settings['from_email'] ?? $username;
        $fromName   = $this->settings['from_name'] ?? 'Marketing Suite';

        if (!empty($this->settings['password_encrypted']) && defined('APP_KEY')) {
            $password = decryptData($this->settings['password_encrypted'], APP_KEY);
        }

        if (empty($host)) {
            error_log('SMTP host not configured');
            return false;
        }

        // Build MIME message
        $boundary = md5(uniqid((string)time(), true));
        $toStr    = is_array($to[0]) ? implode(', ', array_map(fn($r) => "{$r['name']} <{$r['email']}>", $to)) : implode(', ', $to);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "To: {$toStr}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= ($textBody ?: strip_tags($htmlBody)) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // SMTP connection via socket
        $prefix = match ($encryption) {
            'ssl'  => 'ssl://',
            default => '',
        };

        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("SMTP connection failed: {$errstr} ({$errno})");
            return false;
        }

        try {
            $this->smtpExpect($socket, '220');
            $this->smtpSend($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $response = $this->smtpReadAll($socket);

            if ($encryption === 'tls') {
                $this->smtpSend($socket, 'STARTTLS');
                $this->smtpExpect($socket, '220');
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpSend($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $this->smtpReadAll($socket);
            }

            if (!empty($username)) {
                $this->smtpSend($socket, 'AUTH LOGIN');
                $this->smtpExpect($socket, '334');
                $this->smtpSend($socket, base64_encode($username));
                $this->smtpExpect($socket, '334');
                $this->smtpSend($socket, base64_encode($password));
                $this->smtpExpect($socket, '235');
            }

            $this->smtpSend($socket, "MAIL FROM:<{$fromEmail}>");
            $this->smtpExpect($socket, '250');

            $recipients = is_array($to[0]) ? array_column($to, 'email') : $to;
            foreach ($recipients as $recipient) {
                $this->smtpSend($socket, "RCPT TO:<{$recipient}>");
                $this->smtpExpect($socket, '250');
            }

            $this->smtpSend($socket, 'DATA');
            $this->smtpExpect($socket, '354');
            fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->smtpExpect($socket, '250');
            $this->smtpSend($socket, 'QUIT');
        } catch (\Exception $e) {
            error_log('SMTP send error: ' . $e->getMessage());
            fclose($socket);
            return false;
        }

        fclose($socket);
        return true;
    }

    private function smtpSend($socket, string $cmd): void {
        fwrite($socket, $cmd . "\r\n");
    }

    private function smtpExpect($socket, string $expectedCode): string {
        $response = fgets($socket, 512);
        if (substr((string)$response, 0, 3) !== $expectedCode) {
            throw new \RuntimeException("SMTP error: expected {$expectedCode}, got: {$response}");
        }
        return (string)$response;
    }

    private function smtpReadAll($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if ($line[3] === ' ') break;
        }
        return $response;
    }

    private function sendViaSendGrid(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey = '';
        if (!empty($this->settings['sendgrid_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['sendgrid_api_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey)) return false;

        $recipients = [];
        foreach ($to as $recipient) {
            if (is_array($recipient)) {
                $recipients[] = ['email' => $recipient['email'], 'name' => $recipient['name'] ?? ''];
            } else {
                $recipients[] = ['email' => $recipient];
            }
        }

        $payload = [
            'personalizations' => [['to' => $recipients]],
            'from'             => ['email' => $this->settings['from_email'] ?? '', 'name' => $this->settings['from_name'] ?? ''],
            'subject'          => $subject,
            'content'          => [
                ['type' => 'text/plain', 'value' => $textBody ?: strip_tags($htmlBody)],
                ['type' => 'text/html',  'value' => $htmlBody],
            ],
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 202;
    }

    private function sendViaMailgun(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey = '';
        $domain = $this->settings['mailgun_domain'] ?? '';
        if (!empty($this->settings['mailgun_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['mailgun_api_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey) || empty($domain)) return false;

        $toList = [];
        foreach ($to as $recipient) {
            $toList[] = is_array($recipient) ? "{$recipient['name']} <{$recipient['email']}>" : $recipient;
        }

        $ch = curl_init("https://api.mailgun.net/v3/{$domain}/messages");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => 'api:' . $apiKey,
            CURLOPT_POSTFIELDS     => [
                'from'    => ($this->settings['from_name'] ?? '') . ' <' . ($this->settings['from_email'] ?? '') . '>',
                'to'      => implode(',', $toList),
                'subject' => $subject,
                'html'    => $htmlBody,
                'text'    => $textBody ?: strip_tags($htmlBody),
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function sendViaSES(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $accessKey = '';
        $secretKey = '';
        $region    = $this->settings['ses_region'] ?? 'us-east-1';

        if (!empty($this->settings['ses_key_encrypted']) && defined('APP_KEY')) {
            $accessKey = decryptData($this->settings['ses_key_encrypted'], APP_KEY);
        }
        if (!empty($this->settings['ses_secret_encrypted']) && defined('APP_KEY')) {
            $secretKey = decryptData($this->settings['ses_secret_encrypted'], APP_KEY);
        }
        if (empty($accessKey) || empty($secretKey)) return false;

        $toList = [];
        foreach ($to as $recipient) {
            $toList[] = is_array($recipient) ? $recipient['email'] : $recipient;
        }

        $date        = gmdate('Ymd');
        $datetime    = gmdate('Ymd\THis\Z');
        $service     = 'ses';
        $endpoint    = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";

        $payload = json_encode([
            'FromEmailAddress' => ($this->settings['from_name'] ?? '') . ' <' . ($this->settings['from_email'] ?? '') . '>',
            'Destination'      => ['ToAddresses' => $toList],
            'Content'          => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body'    => [
                        'Html' => ['Data' => $htmlBody,                       'Charset' => 'UTF-8'],
                        'Text' => ['Data' => $textBody ?: strip_tags($htmlBody), 'Charset' => 'UTF-8'],
                    ],
                ],
            ],
        ]);

        $bodyHash = hash('sha256', (string)$payload);
        $headers  = [
            'content-type'         => 'application/json',
            'host'                 => "email.{$region}.amazonaws.com",
            'x-amz-content-sha256' => $bodyHash,
            'x-amz-date'           => $datetime,
        ];

        // Build canonical request
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders    = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
            $signedHeaders    .= "{$k};";
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = implode("\n", ['POST', '/v2/email/outbound-emails', '', $canonicalHeaders, $signedHeaders, $bodyHash]);
        $credentialScope  = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign     = implode("\n", ['AWS4-HMAC-SHA256', $datetime, $credentialScope, hash('sha256', $canonicalRequest)]);

        $kDate    = hash_hmac('sha256', $date,     'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region,   $kDate,              true);
        $kService = hash_hmac('sha256', $service,  $kRegion,            true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService,      true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $curlHeaders,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function sendViaResend(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey = '';
        if (!empty($this->settings['resend_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['resend_api_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey)) return false;

        $toEmails = [];
        foreach ($to as $recipient) {
            $toEmails[] = is_array($recipient) ? $recipient['email'] : $recipient;
        }

        $fromName  = $this->settings['from_name'] ?? '';
        $fromEmail = $this->settings['from_email'] ?? '';
        $fromAddr  = $fromName !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail;

        $payload = [
            'from'    => $fromAddr,
            'to'      => $toEmails,
            'subject' => $subject,
            'html'    => $htmlBody,
            'text'    => $textBody ?: strip_tags($htmlBody),
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 || $httpCode === 201;
    }

    private function sendViaPostmark(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey = '';
        if (!empty($this->settings['postmark_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['postmark_api_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey)) return false;

        $toList = [];
        foreach ($to as $recipient) {
            $toList[] = is_array($recipient) ? $recipient['email'] : $recipient;
        }

        $fromName  = $this->settings['from_name'] ?? '';
        $fromEmail = $this->settings['from_email'] ?? '';
        $fromAddr  = $fromName !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail;

        $payload = [
            'From'          => $fromAddr,
            'To'            => implode(',', $toList),
            'Subject'       => $subject,
            'HtmlBody'      => $htmlBody,
            'TextBody'      => $textBody ?: strip_tags($htmlBody),
            'MessageStream' => 'outbound',
        ];

        $ch = curl_init('https://api.postmarkapp.com/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'X-Postmark-Server-Token: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function sendViaBrevo(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey = '';
        if (!empty($this->settings['brevo_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['brevo_api_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey)) return false;

        $toList = [];
        foreach ($to as $recipient) {
            if (is_array($recipient)) {
                $toList[] = ['email' => $recipient['email'], 'name' => $recipient['name'] ?? ''];
            } else {
                $toList[] = ['email' => $recipient];
            }
        }

        $payload = [
            'sender'      => ['name' => $this->settings['from_name'] ?? '', 'email' => $this->settings['from_email'] ?? ''],
            'to'          => $toList,
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => $textBody ?: strip_tags($htmlBody),
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 201;
    }

    private function sendViaMailjet(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $apiKey    = '';
        $secretKey = '';
        if (!empty($this->settings['mailjet_api_key_encrypted']) && defined('APP_KEY')) {
            $apiKey = decryptData($this->settings['mailjet_api_key_encrypted'], APP_KEY);
        }
        if (!empty($this->settings['mailjet_secret_key_encrypted']) && defined('APP_KEY')) {
            $secretKey = decryptData($this->settings['mailjet_secret_key_encrypted'], APP_KEY);
        }
        if (empty($apiKey) || empty($secretKey)) return false;

        $toList = [];
        foreach ($to as $recipient) {
            if (is_array($recipient)) {
                $toList[] = ['Email' => $recipient['email'], 'Name' => $recipient['name'] ?? ''];
            } else {
                $toList[] = ['Email' => $recipient];
            }
        }

        $payload = [
            'Messages' => [[
                'From'     => ['Email' => $this->settings['from_email'] ?? '', 'Name' => $this->settings['from_name'] ?? ''],
                'To'       => $toList,
                'Subject'  => $subject,
                'HTMLPart' => $htmlBody,
                'TextPart' => $textBody ?: strip_tags($htmlBody),
            ]],
        ];

        $ch = curl_init('https://api.mailjet.com/v3.1/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $apiKey . ':' . $secretKey,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private function sendViaAWeber(array $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        $accessToken = '';
        $accountId   = $this->settings['aweber_account_id'] ?? '';
        $listId      = $this->settings['aweber_list_id'] ?? '';
        if (!empty($this->settings['aweber_access_token_encrypted']) && defined('APP_KEY')) {
            $accessToken = decryptData($this->settings['aweber_access_token_encrypted'], APP_KEY);
        }
        if (empty($accessToken) || empty($accountId) || empty($listId)) return false;

        $payload = [
            'subject'   => $subject,
            'body_html' => $htmlBody,
            'body_text' => $textBody ?: strip_tags($htmlBody),
        ];

        $url = "https://api.aweber.com/1.0/accounts/{$accountId}/lists/{$listId}/broadcasts";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 201;
    }
}
