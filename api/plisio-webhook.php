<?php
/**
 * Plisio IPN (Instant Payment Notification) webhook handler.
 * Plisio sends a GET request to this URL after a transaction status changes.
 * Verifies via Plisio API then credits the user's NGN wallet.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// Plisio sends GET; respond 200 quickly for all cases so they don't retry
header('Content-Type: text/plain');

$status      = strtolower($_GET['status']       ?? '');
$reference   = preg_replace('/[^A-Za-z0-9\-_]/', '', $_GET['order_number'] ?? '');
$txnId       = preg_replace('/[^A-Za-z0-9\-_]/', '', $_GET['txn_id']       ?? '');

if (!$reference) {
    http_response_code(400);
    exit('BAD_REQUEST');
}

$db = getDB();

// Load Plisio API key for verification
$plisioKey = '';
try {
    $plisioKey = $db->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='plisio_api_key'"
    )->fetchColumn() ?: '';
} catch (\Exception $e) {}

// Verify callback authenticity via Plisio API if we have a txnId
if ($plisioKey && $txnId) {
    $ch = curl_init(
        'https://plisio.net/api/v1/operations/' . urlencode($txnId) .
        '?api_key=' . urlencode($plisioKey)
    );
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $verified = $resp ? json_decode($resp, true) : [];
    $txStatus = strtolower($verified['data']['status'] ?? '');
    $txRef    = $verified['data']['order_number'] ?? '';

    if ($txRef !== $reference) {
        // Reference mismatch – ignore
        http_response_code(200);
        exit('OK');
    }
    // Use Plisio's verified status
    $status = $txStatus;
}

// Only process completed or mismatch (overpaid) payments
if (!in_array($status, ['completed', 'mismatch'], true)) {
    http_response_code(200);
    exit('OK');
}

// Get pending deposit record
try {
    $dep = $db->prepare(
        "SELECT id, user_id, net_amount FROM wallet_deposits
         WHERE reference=? AND status='pending'
         LIMIT 1"
    );
    $dep->execute([$reference]);
    $depRow = $dep->fetch();
} catch (\Exception $e) {
    error_log('Plisio webhook DB error: ' . $e->getMessage());
    http_response_code(200);
    exit('OK');
}

if (!$depRow) {
    // Already processed or not found
    http_response_code(200);
    exit('OK');
}

$uid    = (int)$depRow['user_id'];
$netNgn = (float)$depRow['net_amount'];

try {
    $db->beginTransaction();

    $db->prepare(
        "UPDATE wallet_deposits SET status='completed', processed_at=NOW() WHERE id=?"
    )->execute([$depRow['id']]);

    $db->prepare(
        "INSERT INTO user_sms_wallet (user_id,credits) VALUES (?,?)
         ON DUPLICATE KEY UPDATE credits=credits+?, updated_at=NOW()"
    )->execute([$uid, $netNgn, $netNgn]);

    $db->prepare(
        "INSERT INTO sms_credit_transactions (user_id,amount,type,description,reference)
         VALUES (?,?,'credit',?,?)"
    )->execute([$uid, $netNgn, 'Deposit via Plisio crypto (' . $reference . ')', $reference]);

    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Plisio webhook credit error: ' . $e->getMessage());
}

http_response_code(200);
echo 'OK';
