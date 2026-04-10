<?php
/**
 * PayPal order creation & capture endpoint.
 * action=create_order  → creates a USD PayPal order, stores pending deposit, returns order_id.
 * action=capture_order → captures the approved order, credits NGN wallet.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

// Rate limit: 10 req/min
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['pp_rtimes'] = array_filter($_SESSION['pp_rtimes'] ?? [], fn($t) => ($now - $t) < 60);
if (count($_SESSION['pp_rtimes']) >= 10) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}
$_SESSION['pp_rtimes'][] = $now;

$db = getDB();

// Load PayPal settings
$paypalCfg = [];
try {
    $rows = $db->query(
        "SELECT setting_key, setting_value FROM app_settings
         WHERE setting_key IN ('paypal_client_id','paypal_client_secret','paypal_mode')"
    )->fetchAll();
    foreach ($rows as $r) $paypalCfg[$r['setting_key']] = $r['setting_value'];
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Config error']);
    exit;
}

$clientId     = $paypalCfg['paypal_client_id']     ?? '';
$clientSecret = $paypalCfg['paypal_client_secret'] ?? '';
$mode         = ($paypalCfg['paypal_mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
$baseUrl      = $mode === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com';

if (!$clientId || !$clientSecret) {
    echo json_encode(['success' => false, 'message' => 'PayPal not configured']);
    exit;
}

// ── OAuth helper ──────────────────────────────────────────────────────────
function paypalGetToken(string $baseUrl, string $clientId, string $clientSecret): ?string
{
    $ch = curl_init("{$baseUrl}/v1/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => "{$clientId}:{$clientSecret}",
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : [];
    return $data['access_token'] ?? null;
}

$action = $body['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────
// CREATE ORDER
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'create_order') {
    $usdAmount   = round(max(0.01, (float)($body['usd_amount']    ?? 0)), 2);
    $ngnAmount   = max(0.0, (float)($body['ngn_amount']           ?? 0));
    $exchangeRate= max(0.0, (float)($body['exchange_rate']        ?? 0));
    $fee         = max(0.0, (float)($body['fee']                  ?? 0));
    $netNgn      = max(0.0, (float)($body['net_ngn']              ?? $ngnAmount));

    if ($usdAmount <= 0 || $ngnAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid amount']);
        exit;
    }

    $reference = 'PP-' . strtoupper(bin2hex(random_bytes(6)));

    $token = paypalGetToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'PayPal authentication failed']);
        exit;
    }

    $orderPayload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $reference,
            'amount' => [
                'currency_code' => 'USD',
                'value'         => number_format($usdAmount, 2, '.', ''),
            ],
            'description' => 'Wallet Deposit',
        ]],
        'application_context' => ['shipping_preference' => 'NO_SHIPPING'],
    ];

    $ch = curl_init("{$baseUrl}/v2/checkout/orders");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode($orderPayload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = $resp ? json_decode($resp, true) : [];
    if ($code === 201 && !empty($data['id'])) {
        // Store pending deposit
        try {
            $db->prepare(
                "INSERT INTO wallet_deposits
                 (user_id,method,amount,fee,net_amount,status,reference,usd_amount,exchange_rate)
                 VALUES (?,'paypal',?,?,?,'pending',?,?,?)"
            )->execute([$userId, $ngnAmount, $fee, $netNgn, $reference, $usdAmount, $exchangeRate]);
        } catch (\Exception $e) {
            error_log('PayPal create_order store error: ' . $e->getMessage());
        }
        echo json_encode([
            'success'   => true,
            'order_id'  => $data['id'],
            'reference' => $reference,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create PayPal order: ' . ($data['message'] ?? 'Unknown error'),
        ]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// CAPTURE ORDER
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'capture_order') {
    $orderId   = preg_replace('/[^A-Za-z0-9\-_]/', '', $body['order_id']   ?? '');
    $reference = preg_replace('/[^A-Za-z0-9\-_]/', '', $body['reference']  ?? '');

    if (!$orderId || !$reference) {
        echo json_encode(['success' => false, 'message' => 'Missing order_id or reference']);
        exit;
    }

    // Prevent duplicate credit
    try {
        $dup = $db->prepare("SELECT id FROM wallet_deposits WHERE reference=? AND status='completed'");
        $dup->execute([$reference]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Payment already processed']);
            exit;
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB error']);
        exit;
    }

    $token = paypalGetToken($baseUrl, $clientId, $clientSecret);
    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'PayPal authentication failed']);
        exit;
    }

    $ch = curl_init("{$baseUrl}/v2/checkout/orders/{$orderId}/capture");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data   = $resp ? json_decode($resp, true) : [];
    $status = strtoupper($data['status'] ?? '');

    if ($code === 201 && $status === 'COMPLETED') {
        try {
            // Load the pending deposit record to get NGN amounts
            $dep = $db->prepare(
                "SELECT id, net_amount FROM wallet_deposits
                 WHERE reference=? AND user_id=? AND status='pending'
                 LIMIT 1"
            );
            $dep->execute([$reference, $userId]);
            $depRow = $dep->fetch();

            if (!$depRow) {
                echo json_encode(['success' => false, 'message' => 'Deposit record not found']);
                exit;
            }
            $netNgn = (float)$depRow['net_amount'];

            $db->beginTransaction();
            $db->prepare(
                "UPDATE wallet_deposits SET status='completed', processed_at=NOW() WHERE id=?"
            )->execute([$depRow['id']]);
            $db->prepare(
                "INSERT INTO user_sms_wallet (user_id,credits) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE credits=credits+?, updated_at=NOW()"
            )->execute([$userId, $netNgn, $netNgn]);
            $db->prepare(
                "INSERT INTO sms_credit_transactions (user_id,amount,type,description,reference)
                 VALUES (?,?,'credit',?,?)"
            )->execute([$userId, $netNgn, 'Deposit via PayPal (' . $reference . ')', $reference]);
            $db->commit();

            echo json_encode(['success' => true, 'message' => 'Payment captured and wallet credited']);
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('PayPal capture credit error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error crediting wallet. Please contact support.']);
        }
    } else {
        // Mark as failed
        try {
            $db->prepare(
                "UPDATE wallet_deposits SET status='failed'
                 WHERE reference=? AND user_id=? AND status='pending'"
            )->execute([$reference, $userId]);
        } catch (\Exception $e) {}
        echo json_encode([
            'success' => false,
            'message' => 'PayPal capture failed: ' . ($data['message'] ?? 'Order not completed'),
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
