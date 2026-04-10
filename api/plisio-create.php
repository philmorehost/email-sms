<?php
/**
 * Plisio crypto invoice creation.
 * POST: { usd_amount, ngn_amount, exchange_rate, fee, net_ngn }
 * Returns: { invoice_url, reference }
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

// Rate limit: 5 invoice creations/min (prevent abuse of crypto invoices)
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['pl_rtimes'] = array_filter($_SESSION['pl_rtimes'] ?? [], fn($t) => ($now - $t) < 60);
if (count($_SESSION['pl_rtimes']) >= 5) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}
$_SESSION['pl_rtimes'][] = $now;

$db = getDB();

// Load Plisio settings
$plisioKey      = '';
$plisioCurrency = 'BTC';  // Default crypto
try {
    $rows = $db->query(
        "SELECT setting_key, setting_value FROM app_settings
         WHERE setting_key IN ('plisio_api_key','plisio_currency')"
    )->fetchAll();
    foreach ($rows as $r) {
        if ($r['setting_key'] === 'plisio_api_key')  $plisioKey      = $r['setting_value'];
        if ($r['setting_key'] === 'plisio_currency') $plisioCurrency = $r['setting_value'] ?: 'BTC';
    }
} catch (\Exception $e) {}

if (!$plisioKey) {
    echo json_encode(['success' => false, 'message' => 'Plisio not configured']);
    exit;
}

$usdAmount    = round(max(1.0, (float)($body['usd_amount']    ?? 0)), 2);
$ngnAmount    = max(0.0, (float)($body['ngn_amount']           ?? 0));
$exchangeRate = max(0.0, (float)($body['exchange_rate']        ?? 0));
$fee          = max(0.0, (float)($body['fee']                  ?? 0));
$netNgn       = max(0.0, (float)($body['net_ngn']              ?? $ngnAmount));

if ($usdAmount <= 0 || $ngnAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit;
}

$reference = 'PL-' . strtoupper(bin2hex(random_bytes(6)));
$email     = $user['email'] ?? '';

// Build callback and redirect URLs
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$callbackUrl = "{$scheme}://{$host}/api/plisio-webhook.php";
$successUrl  = "{$scheme}://{$host}/deposit.php?success=1";
$failUrl     = "{$scheme}://{$host}/deposit.php?plisio_failed=1";

// Create Plisio invoice (source_currency=USD, amount in USD)
$params = [
    'api_key'         => $plisioKey,
    'currency'        => $plisioCurrency,
    'order_name'      => 'Wallet Deposit',
    'order_number'    => $reference,
    'amount'          => number_format($usdAmount, 2, '.', ''),
    'source_currency' => 'USD',
    'callback_url'    => $callbackUrl,
    'success_url'     => $successUrl,
    'fail_url'        => $failUrl,
];
if ($email) $params['email'] = $email;

$ch = curl_init('https://plisio.net/api/v1/invoices/new?' . http_build_query($params));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = $resp ? json_decode($resp, true) : [];

if ($code === 200 && ($data['status'] ?? '') === 'success' && !empty($data['data']['invoice_url'])) {
    // Store pending deposit record
    try {
        $db->prepare(
            "INSERT INTO wallet_deposits
             (user_id,method,amount,fee,net_amount,status,reference,usd_amount,exchange_rate)
             VALUES (?,'plisio',?,?,?,'pending',?,?,?)"
        )->execute([$userId, $ngnAmount, $fee, $netNgn, $reference, $usdAmount, $exchangeRate]);
    } catch (\Exception $e) {
        error_log('Plisio create store error: ' . $e->getMessage());
    }
    echo json_encode([
        'success'     => true,
        'invoice_url' => $data['data']['invoice_url'],
        'reference'   => $reference,
    ]);
} else {
    $msg = $data['data']['message'] ?? ($data['message'] ?? 'Failed to create Plisio invoice');
    echo json_encode(['success' => false, 'message' => $msg]);
}
