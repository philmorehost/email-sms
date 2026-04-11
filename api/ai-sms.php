<?php
/**
 * AI SMS Generation Endpoint
 * POST /api/ai-sms.php
 * Body: {
 *   "topic":      "Your campaign idea",
 *   "pages":      1|2|3|4,          // target SMS page count
 *   "brand":      "BrandName",      // optional
 *   "cta":        "Shop now at …"   // optional call-to-action hint
 * }
 *
 * Charges `ai_tokens_per_sms` tokens ONLY after a successful DeepSeek response.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

// ── Rate limit: 30 SMS generations per minute per user ───────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['ai_sms_times'] = array_values(array_filter(
    $_SESSION['ai_sms_times'] ?? [],
    fn($t) => ($now - $t) < 60
));
if (count($_SESSION['ai_sms_times']) >= 30) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}
$_SESSION['ai_sms_times'][] = $now;

// ── Method check ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$topic = trim($body['topic'] ?? '');
$pages = max(1, min(4, (int)($body['pages'] ?? 1)));
$brand = trim($body['brand'] ?? '');
$cta   = trim($body['cta'] ?? '');

if ($topic === '') {
    echo json_encode(['success' => false, 'message' => 'Topic is required.']);
    exit;
}
if (mb_strlen($topic) > 500) {
    echo json_encode(['success' => false, 'message' => 'Topic too long (max 500 characters).']);
    exit;
}

// Character limits per page count (GSM 7-bit standard)
$charLimits = [1 => 160, 2 => 306, 3 => 459, 4 => 612];
$maxChars   = $charLimits[$pages];

$db = getDB();

// ── Inline migration ──────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_uat_user (user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ai_token_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        delta INT NOT NULL,
        action ENUM('purchase','generate','chat','refund','admin_grant','sms') NOT NULL DEFAULT 'sms',
        template_id INT NULL,
        campaign_id INT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_atl_user (user_id)
    )");
    // Ensure 'sms' exists in action ENUM
    $db->exec("ALTER TABLE ai_token_ledger MODIFY COLUMN action ENUM('purchase','generate','chat','refund','admin_grant','sms') NOT NULL DEFAULT 'sms'");
    $db->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('ai_tokens_per_sms','5')");
} catch (\Exception $e) {}

// ── Load settings ─────────────────────────────────────────────────────────────
$settings = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Exception $e) {}

$apiKey  = trim($settings['deepseek_api_key'] ?? '');
$model   = $settings['deepseek_model'] ?? 'deepseek-chat';
$cost    = max(1, (int)($settings['ai_tokens_per_sms'] ?? 5));

if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'AI is not configured. Please contact support.']);
    exit;
}

// ── Check token balance ───────────────────────────────────────────────────────
$balance = 0;
try {
    $bStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $bStmt->execute([$userId]);
    $balance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

if ($balance < $cost) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Insufficient AI tokens. You need ' . $cost . ' tokens but have ' . $balance . '. <a href="/billing.php?tab=ai_tokens">Buy tokens</a>.',
        'balance'  => $balance,
        'required' => $cost,
    ]);
    exit;
}

// ── Build prompt ──────────────────────────────────────────────────────────────
$systemPrompt = 'You are an expert SMS marketing copywriter. Your task is to write a highly effective, concise SMS marketing message. '
    . 'STRICT RULES: '
    . '1. The message MUST NOT exceed ' . $maxChars . ' characters (this is for ' . $pages . ' SMS page' . ($pages > 1 ? 's' : '') . '). '
    . '2. Write in plain text only — no HTML, no markdown, no emoji (unless the user specifically asks). '
    . '3. Include a clear call to action. '
    . '4. Be direct and persuasive — every word counts. '
    . '5. Return ONLY the SMS message text, nothing else. No explanation, no prefix, no character count.';

$userMsg = 'Write an SMS message about: ' . $topic;
if ($brand !== '') $userMsg .= "\nBrand: " . $brand;
if ($cta   !== '') $userMsg .= "\nCall-to-action hint: " . $cta;
$userMsg .= "\nTarget length: max " . $maxChars . " characters (" . $pages . " SMS page" . ($pages > 1 ? 's' : '') . ").";

// ── Call DeepSeek API ─────────────────────────────────────────────────────────
$requestBody = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMsg],
    ],
    'max_tokens'  => 300,
    'temperature' => 0.75,
]);

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);

$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || !$resp) {
    error_log('DeepSeek SMS cURL error: ' . $curlErr);
    echo json_encode(['success' => false, 'message' => 'Failed to connect to AI service. Please try again.']);
    exit;
}

$data = json_decode($resp, true);
if ($httpCode !== 200 || empty($data['choices'][0]['message']['content'])) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log('DeepSeek SMS API error: ' . $errMsg);
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . htmlspecialchars($errMsg)]);
    exit;
}

$smsText = trim($data['choices'][0]['message']['content']);

// Strip any accidental quotes or markdown
$smsText = trim($smsText, '"\'');
$smsText = preg_replace('/^```\w*\s*/i', '', $smsText);
$smsText = preg_replace('/\s*```\s*$/i', '', $smsText);
$smsText = trim($smsText);

// Hard-trim to max chars as a safety net
if (mb_strlen($smsText) > $maxChars) {
    $smsText = mb_substr($smsText, 0, $maxChars);
    // Try to cut at last space to avoid mid-word truncation
    $lastSpace = mb_strrpos($smsText, ' ');
    if ($lastSpace > $maxChars - 20) {
        $smsText = mb_substr($smsText, 0, $lastSpace);
    }
    $smsText = trim($smsText);
}

// ── Deduct tokens ONLY after success ─────────────────────────────────────────
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO user_ai_tokens (user_id, balance) VALUES (?,0) ON DUPLICATE KEY UPDATE balance=GREATEST(0, balance-?), updated_at=NOW()")
       ->execute([$userId, $cost]);
    $db->prepare("INSERT INTO ai_token_ledger (user_id, delta, action, description) VALUES (?,?,'sms',?)")
       ->execute([$userId, -$cost, 'SMS generation: ' . mb_substr($topic, 0, 80)]);
    $db->commit();
} catch (\Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('SMS token deduction error: ' . $e->getMessage());
    // Don't fail the request — user gets their SMS text
}

// Return updated balance
$newBalance = 0;
try {
    $bStmt->execute([$userId]);
    $newBalance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

echo json_encode([
    'success'     => true,
    'text'        => $smsText,
    'char_count'  => mb_strlen($smsText),
    'max_chars'   => $maxChars,
    'pages'       => $pages,
    'tokens_used' => $cost,
    'balance'     => $newBalance,
]);
