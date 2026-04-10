<?php
/**
 * Stripe PaymentIntent creation.
 * POST: { usd_amount, ngn_amount, exchange_rate, fee, net_ngn }
 * Returns: { client_secret, payment_intent_id, reference }
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
$_SESSION['si_rtimes'] = array_filter($_SESSION['si_rtimes'] ?? [], fn($t) => ($now - $t) < 60);
if (count($_SESSION['si_rtimes']) >= 10) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}
$_SESSION['si_rtimes'][] = $now;

$db = getDB();

// Load Stripe secret key
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

$usdAmount    = round(max(0.50, (float)($body['usd_amount']    ?? 0)), 2); // Stripe min $0.50
$ngnAmount    = max(0.0, (float)($body['ngn_amount']           ?? 0));
$exchangeRate = max(0.0, (float)($body['exchange_rate']        ?? 0));
$fee          = max(0.0, (float)($body['fee']                  ?? 0));
$netNgn       = max(0.0, (float)($body['net_ngn']              ?? $ngnAmount));

if ($usdAmount <= 0 || $ngnAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit;
}

$reference   = 'ST-' . strtoupper(bin2hex(random_bytes(6)));
$amountCents = (int)round($usdAmount * 100);

// Create Stripe PaymentIntent
$fields = http_build_query([
    'amount'                  => $amountCents,
    'currency'                => 'usd',
    'description'             => 'Wallet Deposit',
    'metadata[reference]'     => $reference,
    'metadata[user_id]'       => $userId,
    'metadata[ngn_net_amount]'=> $netNgn,
    'metadata[exchange_rate]' => $exchangeRate,
]);

$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => $fields,
    CURLOPT_USERPWD        => "{$secretKey}:",
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = $resp ? json_decode($resp, true) : [];

if ($code === 200 && !empty($data['client_secret'])) {
    // Store pending deposit record
    try {
        $db->prepare(
            "INSERT INTO wallet_deposits
             (user_id,method,amount,fee,net_amount,status,reference,usd_amount,exchange_rate)
             VALUES (?,'stripe',?,?,?,'pending',?,?,?)"
        )->execute([$userId, $ngnAmount, $fee, $netNgn, $reference, $usdAmount, $exchangeRate]);
    } catch (\Exception $e) {
        error_log('Stripe intent store error: ' . $e->getMessage());
    }
    echo json_encode([
        'success'            => true,
        'client_secret'      => $data['client_secret'],
        'payment_intent_id'  => $data['id'],
        'reference'          => $reference,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $data['error']['message'] ?? 'Failed to create payment intent',
    ]);
}
