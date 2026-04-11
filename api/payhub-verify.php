<?php
/**
 * Payhub Payment Verification Endpoint
 * Called by the deposit.php frontend after Payhub inline checkout callback.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

// Rate limit: max 10 verify calls per minute per user
if (session_status() === PHP_SESSION_NONE) session_start();
$now     = time();
$window  = 60;
$maxReqs = 10;
$_SESSION['ph_verify_times'] = array_filter(
    $_SESSION['ph_verify_times'] ?? [],
    fn($t) => ($now - $t) < $window
);
if (count($_SESSION['ph_verify_times']) >= $maxReqs) {
    echo json_encode(['success' => false, 'message' => 'Too many requests, please wait.']);
    exit;
}
$_SESSION['ph_verify_times'][] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$reference     = preg_replace('/[^A-Za-z0-9\-_]/', '', $body['reference'] ?? '');
$transactionId = sanitize($body['transaction_id'] ?? '');
$netAmount     = max(0.0, (float)($body['net_amount'] ?? 0));
$fee           = max(0.0, (float)($body['fee'] ?? 0));

if (!$reference || $netAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$db = getDB();

// Prevent duplicate credit for same reference
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

// Load Payhub secret key
$secretKey = '';
try {
    $sk = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='payhub_secret_key'")->fetchColumn();
    $secretKey = $sk ?: '';
} catch (\Exception $e) {}

// Verify payment with Payhub API
$verified = false;
$payhubMsg = '';
try {
    $ch = curl_init('https://payhub.datagifting.com.ng/api/verify/' . urlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data      = $resp ? json_decode($resp, true) : [];
    $payhubMsg = $data['message'] ?? '';

    // Accept if Payhub says status is success/paid
    $status = strtolower($data['data']['status'] ?? $data['status'] ?? '');
    if ($httpCode === 200 && in_array($status, ['success','paid','successful','completed'], true)) {
        $verified = true;
    }
} catch (\Exception $e) {
    error_log('Payhub verify error: ' . $e->getMessage());
    // If gateway is unreachable and amount > 0, we can be lenient in dev — but in prod always require verification
    $verified = false;
}

if (!$verified) {
    // Record failed attempt
    try {
        $db->prepare(
            "INSERT IGNORE INTO wallet_deposits (user_id,method,amount,fee,net_amount,status,reference,payhub_txn_id)
             VALUES (?,'payhub_card',?,?,?,'failed',?,?)"
        )->execute([$userId, $netAmount + $fee, $fee, $netAmount, $reference, $transactionId]);
    } catch (\Exception $e) {}
    echo json_encode(['success' => false, 'message' => 'Payment verification failed: ' . $payhubMsg]);
    exit;
}

// Credit wallet
try {
    $db->beginTransaction();

    // Insert deposit record
    $db->prepare(
        "INSERT INTO wallet_deposits (user_id,method,amount,fee,net_amount,status,reference,payhub_txn_id,processed_at)
         VALUES (?,'payhub_card',?,?,?,'completed',?,?,NOW())"
    )->execute([$userId, $netAmount + $fee, $fee, $netAmount, $reference, $transactionId]);

    // Credit wallet
    $db->prepare(
        "INSERT INTO user_sms_wallet (user_id,credits) VALUES (?,?)
         ON DUPLICATE KEY UPDATE credits=credits+?, updated_at=NOW()"
    )->execute([$userId, $netAmount, $netAmount]);

    // Transaction log
    $db->prepare(
        "INSERT INTO sms_credit_transactions (user_id,amount,type,description,reference)
         VALUES (?,?,'credit',?,?)"
    )->execute([$userId, $netAmount, 'Deposit via Payhub card (' . $reference . ')', $reference]);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Payment verified and wallet credited']);
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Payhub credit wallet error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error crediting wallet. Please contact support.']);
}
