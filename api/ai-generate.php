<?php
/**
 * AI Template Generation Endpoint
 * POST /api/ai-generate.php
 * Body: { "prompt": "...", "mode": "generate|refine" }
 *
 * Deducts tokens ONLY after a successful DeepSeek API response.
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

// Rate limit: 20 generations per minute per user
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['ai_gen_times'] = array_values(array_filter(
    $_SESSION['ai_gen_times'] ?? [],
    fn($t) => ($now - $t) < 60
));
if (count($_SESSION['ai_gen_times']) >= 20) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}
$_SESSION['ai_gen_times'][] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$prompt    = trim($body['prompt'] ?? '');
$mode      = in_array($body['mode'] ?? '', ['generate', 'refine'], true) ? $body['mode'] : 'generate';
$templateId = isset($body['template_id']) ? (int)$body['template_id'] : null;

if ($prompt === '') {
    echo json_encode(['success' => false, 'message' => 'Prompt is required.']);
    exit;
}
if (mb_strlen($prompt) > 2000) {
    echo json_encode(['success' => false, 'message' => 'Prompt too long (max 2000 characters).']);
    exit;
}

$db = getDB();

// ── Ensure tables exist (inline migration) ───────────────────────────────────
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
        action ENUM('purchase','generate','chat','refund','admin_grant') NOT NULL,
        template_id INT NULL,
        campaign_id INT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_atl_user (user_id),
        INDEX idx_atl_action (action)
    )");
} catch (\Exception $e) {}

// ── Load settings ─────────────────────────────────────────────────────────────
$settings = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Exception $e) {}

$apiKey   = trim($settings['deepseek_api_key'] ?? '');
$model    = $settings['deepseek_model'] ?? 'deepseek-chat';
$costGen  = max(1, (int)($settings['ai_tokens_per_generation'] ?? 50));

if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'AI generation is not configured. Please contact support.']);
    exit;
}

// ── Check token balance ───────────────────────────────────────────────────────
$balance = 0;
try {
    $bStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $bStmt->execute([$userId]);
    $balance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

if ($balance < $costGen) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Insufficient AI tokens. You need ' . $costGen . ' tokens but have ' . $balance . '. <a href="/billing.php?tab=ai_tokens">Buy tokens</a>.',
        'balance'  => $balance,
        'required' => $costGen,
    ]);
    exit;
}

// ── Build system prompt ───────────────────────────────────────────────────────
$systemPrompt = 'You are an expert Email Marketing Designer. Generate a beautiful, professional HTML email template based on the user\'s request. '
    . 'Requirements: '
    . '1. Use ONLY inline CSS styles (no external stylesheets, no <style> tags). '
    . '2. Use a table-based layout for maximum email client compatibility. '
    . '3. Include a clear Call-to-Action (CTA) button. '
    . '4. Ensure the design is mobile-responsive using fluid widths (max-width:600px centered). '
    . '5. Use modern typography, proper spacing, and a visually appealing color scheme matching the theme. '
    . '6. Return ONLY the complete HTML code, starting with <!DOCTYPE html> — no markdown, no explanation, no code fences.';

if ($mode === 'refine') {
    $systemPrompt = 'You are an expert Email Marketing Designer. The user will provide existing HTML email content and instructions on how to refine it. '
        . 'Apply their requested changes while preserving the table-based layout and inline CSS styles. '
        . 'Return ONLY the complete updated HTML code — no markdown, no explanation, no code fences.';
}

// ── Call DeepSeek API ─────────────────────────────────────────────────────────
$requestBody = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $prompt],
    ],
    'max_tokens'  => 4096,
    'temperature' => 0.7,
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
    error_log('DeepSeek cURL error: ' . $curlErr);
    echo json_encode(['success' => false, 'message' => 'Failed to connect to AI service. Please try again.']);
    exit;
}

$data = json_decode($resp, true);
if ($httpCode !== 200 || empty($data['choices'][0]['message']['content'])) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log('DeepSeek API error: ' . $errMsg);
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . $errMsg]);
    exit;
}

$generatedHtml = trim($data['choices'][0]['message']['content']);

// Strip markdown code fences if the model added them anyway
$generatedHtml = preg_replace('/^```(?:html)?\s*/i', '', $generatedHtml);
$generatedHtml = preg_replace('/\s*```\s*$/i', '', $generatedHtml);
$generatedHtml = trim($generatedHtml);

// ── Deduct tokens (only after success) ───────────────────────────────────────
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO user_ai_tokens (user_id, balance) VALUES (?,?) ON DUPLICATE KEY UPDATE balance=GREATEST(0, balance-?), updated_at=NOW()")
       ->execute([$userId, 0, $costGen]);
    $db->prepare("INSERT INTO ai_token_ledger (user_id, delta, action, template_id, description) VALUES (?,?,'generate',?,?)")
       ->execute([$userId, -$costGen, $templateId, 'Template generation: ' . mb_substr($prompt, 0, 100)]);
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    error_log('Token deduction error: ' . $e->getMessage());
    // Don't fail the request — user gets their HTML
}

// Fetch updated balance
$newBalance = 0;
try {
    $bStmt->execute([$userId]);
    $newBalance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

echo json_encode([
    'success'  => true,
    'html'     => $generatedHtml,
    'tokens_used' => $costGen,
    'balance'  => $newBalance,
]);
