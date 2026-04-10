<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class Mailer {
    private array $settings = [];

    public function __construct() {
        try {
            $db = getDB();
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
}
