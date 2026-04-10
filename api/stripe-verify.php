<?php
/**
 * Stripe PaymentIntent verification.
 * POST: { payment_intent_id, reference }
 * Retrieves the PaymentIntent from Stripe, verifies status = 'succeeded',
 * then credits the NGN wallet.
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

$piId      = preg_replace('/[^A-Za-z0-9_]/', '', $body['payment_intent_id'] ?? '');
$reference = preg_replace('/[^A-Za-z0-9\-_]/', '', $body['reference']       ?? '');

if (!$piId || !$reference) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Rate limit: 10 req/min
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['sv_rtimes'] = array_filter($_SESSION['sv_rtimes'] ?? [], fn($t) => ($now - $t) < 60);
if (count($_SESSION['sv_rtimes']) >= 10) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}
$_SESSION['sv_rtimes'][] = $now;

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

$secretKey = '';
try {
    $secretKey = $db->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='stripe_secret_key'"
    )->fetchColumn() ?: '';
} catch (\Exception $e) {}

if (!$secretKey) {
    echo json_encode(['success' => false, 'message' => 'Stripe not configured']);
    exit;
}

// Retrieve PaymentIntent from Stripe
$ch = curl_init("https://api.stripe.com/v1/payment_intents/{$piId}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERPWD        => "{$secretKey}:",
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data   = $resp ? json_decode($resp, true) : [];
$status = strtolower($data['status'] ?? '');

if ($code !== 200 || $status !== 'succeeded') {
    // Mark pending as failed
    try {
        $db->prepare(
            "UPDATE wallet_deposits SET status='failed'
             WHERE reference=? AND user_id=? AND status='pending'"
        )->execute([$reference, $userId]);
    } catch (\Exception $e) {}
    echo json_encode([
        'success' => false,
        'message' => 'Payment not completed (status: ' . $status . ')',
    ]);
    exit;
}

// Verify reference matches metadata (security check)
$metaRef = $data['metadata']['reference'] ?? '';
if ($metaRef !== $reference) {
    echo json_encode(['success' => false, 'message' => 'Reference mismatch – possible fraud']);
    exit;
}

// Load pending deposit record
try {
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
    )->execute([$userId, $netNgn, 'Deposit via Stripe (' . $reference . ')', $reference]);
    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Payment verified and wallet credited']);
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Stripe credit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error crediting wallet. Please contact support.']);
}
