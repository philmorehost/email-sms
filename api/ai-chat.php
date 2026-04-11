<?php
/**
 * AI Chat / Copywriter Refinement Endpoint
 * POST /api/ai-chat.php
 * Body: { "messages": [{"role":"user","content":"..."},...], "template_id": null }
 *
 * Multi-turn conversation for refining copy, subject lines, CTAs, etc.
 * Tokens deducted proportionally to response word count AFTER success.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Auth
$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

// Rate limit: 30 chat calls per minute per user
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['ai_chat_times'] = array_values(array_filter(
    $_SESSION['ai_chat_times'] ?? [],
    fn($t) => ($now - $t) < 60
));
if (count($_SESSION['ai_chat_times']) >= 30) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}
$_SESSION['ai_chat_times'][] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$messages   = $body['messages'] ?? [];
$templateId = isset($body['template_id']) ? (int)$body['template_id'] : null;

// Validate messages array
if (!is_array($messages) || empty($messages)) {
    echo json_encode(['success' => false, 'message' => 'Messages array is required.']);
    exit;
}

// Sanitise and cap history to last 10 messages to control costs
$cleanMessages = [];
foreach (array_slice($messages, -10) as $msg) {
    $role    = in_array($msg['role'] ?? '', ['user', 'assistant'], true) ? $msg['role'] : 'user';
    $content = mb_substr(trim($msg['content'] ?? ''), 0, 2000);
    if ($content !== '') {
        $cleanMessages[] = ['role' => $role, 'content' => $content];
    }
}
if (empty($cleanMessages)) {
    echo json_encode(['success' => false, 'message' => 'No valid messages provided.']);
    exit;
}

$db = getDB();

// ── Inline migration ─────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ai_token_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        delta INT NOT NULL,
        action ENUM('purchase','generate','chat','refund','admin_grant') NOT NULL,
        template_id INT NULL,
        campaign_id INT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Exception $e) {}

// ── Load settings ─────────────────────────────────────────────────────────────
$settings = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Exception $e) {}

$apiKey      = trim($settings['deepseek_api_key'] ?? '');
$model       = $settings['deepseek_model'] ?? 'deepseek-chat';
$costPer1k   = max(1, (int)($settings['ai_tokens_per_chat_1k'] ?? 10));

if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'AI service is not configured. Please contact support.']);
    exit;
}

// ── Minimum balance check (at least 1 token) ─────────────────────────────────
$balance = 0;
try {
    $bStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $bStmt->execute([$userId]);
    $balance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

if ($balance < 1) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Insufficient AI tokens. <a href="/billing.php?tab=ai_tokens">Buy tokens</a>.',
        'balance'  => 0,
    ]);
    exit;
}

// ── System prompt for chat mode ───────────────────────────────────────────────
$systemMsg = [
    'role'    => 'system',
    'content' => 'You are an expert Email Marketing Copywriter and Designer. '
        . 'Help users craft compelling email subject lines, body copy, CTAs, and content ideas. '
        . 'When asked to generate or update HTML email templates, always use inline CSS and table-based layouts. '
        . 'Be concise, creative, and results-driven. '
        . 'If the user asks you to write HTML, return only the HTML — no markdown fences.',
];

$fullMessages = array_merge([$systemMsg], $cleanMessages);

// ── Call DeepSeek API ─────────────────────────────────────────────────────────
$requestBody = json_encode([
    'model'       => $model,
    'messages'    => $fullMessages,
    'max_tokens'  => 2048,
    'temperature' => 0.75,
]);

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
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
    error_log('DeepSeek chat cURL error: ' . $curlErr);
    echo json_encode(['success' => false, 'message' => 'Failed to connect to AI service. Please try again.']);
    exit;
}

$data = json_decode($resp, true);
if ($httpCode !== 200 || empty($data['choices'][0]['message']['content'])) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log('DeepSeek chat API error: ' . $errMsg);
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . $errMsg]);
    exit;
}

$reply = trim($data['choices'][0]['message']['content']);

// ── Calculate token cost proportional to word count ──────────────────────────
$wordCount   = str_word_count(strip_tags($reply));
$costTokens  = max(1, (int)ceil($wordCount / 1000 * $costPer1k));
$costTokens  = min($costTokens, $balance); // never deduct more than they have

// ── Deduct tokens (only after success) ───────────────────────────────────────
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO user_ai_tokens (user_id, balance) VALUES (?,0) ON DUPLICATE KEY UPDATE balance=GREATEST(0, balance-?), updated_at=NOW()")
       ->execute([$userId, $costTokens]);
    $db->prepare("INSERT INTO ai_token_ledger (user_id, delta, action, template_id, description) VALUES (?,?,'chat',?,?)")
       ->execute([$userId, -$costTokens, $templateId, 'Chat: ' . $wordCount . ' words']);
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Chat token deduction error: ' . $e->getMessage());
}

// Fetch updated balance
$newBalance = 0;
try {
    $bStmt->execute([$userId]);
    $newBalance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

echo json_encode([
    'success'     => true,
    'reply'       => $reply,
    'tokens_used' => $costTokens,
    'word_count'  => $wordCount,
    'balance'     => $newBalance,
]);
