<?php
/**
 * Flutterwave payment verification.
 * Called after inline checkout callback with transaction_id + tx_ref.
 * Verifies with Flutterwave API, credits NGN wallet on success.
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

// Rate limit: 10 verifications/min
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['fw_vtimes'] = array_filter($_SESSION['fw_vtimes'] ?? [], fn($t) => ($now - $t) < 60);
if (count($_SESSION['fw_vtimes']) >= 10) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}
$_SESSION['fw_vtimes'][] = $now;

$transactionId = (int)($body['transaction_id'] ?? 0);
$reference     = preg_replace('/[^A-Za-z0-9\-_]/', '', $body['reference'] ?? '');
$netAmount     = max(0.0, (float)($body['net_amount'] ?? 0));
$fee           = max(0.0, (float)($body['fee'] ?? 0));

if (!$transactionId || !$reference || $netAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$db = getDB();

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

// Load Flutterwave secret key
$secretKey = '';
try {
    $secretKey = $db->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='flutterwave_secret_key'"
    )->fetchColumn() ?: '';
} catch (\Exception $e) {}

if (!$secretKey) {
    echo json_encode(['success' => false, 'message' => 'Flutterwave not configured']);
    exit;
}

// Verify transaction with Flutterwave API
$verified       = false;
$verifiedAmount = 0.0;
try {
    $ch = curl_init("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$secretKey}",
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = $resp ? json_decode($resp, true) : [];
    if (($data['status'] ?? '') === 'success') {
        $txData   = $data['data'] ?? [];
        $txStatus = strtolower($txData['status'] ?? '');
        $txRef    = $txData['tx_ref']   ?? '';
        $txAmount = (float)($txData['amount'] ?? 0);
        $txCurr   = strtoupper($txData['currency'] ?? '');

        if ($txStatus === 'successful' && $txRef === $reference && $txCurr === 'NGN') {
            $verified       = true;
            $verifiedAmount = $txAmount;
        }
    }
} catch (\Exception $e) {
    error_log('Flutterwave verify error: ' . $e->getMessage());
}

if (!$verified) {
    try {
        $db->prepare(
            "INSERT IGNORE INTO wallet_deposits
             (user_id,method,amount,fee,net_amount,status,reference)
             VALUES (?,'flutterwave',?,?,?,'failed',?)"
        )->execute([$userId, $netAmount + $fee, $fee, $netAmount, $reference]);
    } catch (\Exception $e) {}
    echo json_encode(['success' => false, 'message' => 'Payment verification failed']);
    exit;
}

// Credit NGN wallet
try {
    $db->beginTransaction();

    $db->prepare(
        "INSERT INTO wallet_deposits
         (user_id,method,amount,fee,net_amount,status,reference,processed_at)
         VALUES (?,'flutterwave',?,?,?,'completed',?,NOW())"
    )->execute([$userId, $verifiedAmount, $fee, $netAmount, $reference]);

    $db->prepare(
        "INSERT INTO user_sms_wallet (user_id,credits) VALUES (?,?)
         ON DUPLICATE KEY UPDATE credits=credits+?, updated_at=NOW()"
    )->execute([$userId, $netAmount, $netAmount]);

    $db->prepare(
        "INSERT INTO sms_credit_transactions (user_id,amount,type,description,reference)
         VALUES (?,?,'credit',?,?)"
    )->execute([$userId, $netAmount, 'Deposit via Flutterwave (' . $reference . ')', $reference]);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Payment verified and wallet credited']);
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Flutterwave credit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error crediting wallet. Please contact support.']);
}
