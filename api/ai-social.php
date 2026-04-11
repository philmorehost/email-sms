<?php
/**
 * AI Social Media Content Generation Endpoint
 * POST /api/ai-social.php
 * Body: {
 *   "goal":          "Promote my new PHP script",
 *   "target_audience": "Developers aged 25-40",
 *   "brand_name":    "PhilmoreHost",       // optional
 *   "platforms":     ["facebook","linkedin","twitter","instagram","tiktok"],
 *   "tone":          "professional" | "trendy" | "casual" | "punchy",
 *   "num_variants":  1-5                    // A/B testing variants (default 1)
 * }
 *
 * Returns per-platform caption + hashtag variants.
 * Costs: ai_tokens_per_generation base + social_tokens_per_ab_variant × (variants-1).
 * The AI tokens are charged for the DeepSeek call; social tokens for A/B extras.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

// ── Rate limit: 20 per minute ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['ai_social_times'] = array_values(array_filter(
    $_SESSION['ai_social_times'] ?? [],
    fn($t) => ($now - $t) < 60
));
if (count($_SESSION['ai_social_times']) >= 20) {
    echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}
$_SESSION['ai_social_times'][] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$goal           = trim($body['goal']           ?? '');
$targetAudience = trim($body['target_audience'] ?? '');
$brandName      = trim($body['brand_name']     ?? '');
$platforms      = is_array($body['platforms'])  ? array_map('strval', $body['platforms']) : ['facebook'];
$tone           = trim($body['tone']           ?? 'casual');
$numVariants    = max(1, min(5, (int)($body['num_variants'] ?? 1)));

if ($goal === '') {
    echo json_encode(['success' => false, 'message' => 'Goal is required.']);
    exit;
}
if (mb_strlen($goal) > 500) {
    echo json_encode(['success' => false, 'message' => 'Goal too long (max 500 characters).']);
    exit;
}

$allowedTones     = ['professional', 'trendy', 'casual', 'punchy', 'educational'];
$allowedPlatforms = ['facebook', 'instagram', 'linkedin', 'twitter', 'tiktok', 'pinterest', 'youtube'];
$tone      = in_array($tone, $allowedTones, true) ? $tone : 'casual';
$platforms = array_values(array_unique(array_filter($platforms, fn($p) => in_array($p, $allowedPlatforms, true))));
if (empty($platforms)) $platforms = ['facebook'];

$db = getDB();
AyrshareClient::migrate($db);

// ── Load settings ─────────────────────────────────────────────────────────────
$settings = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Exception $e) {}

$apiKey       = trim($settings['deepseek_api_key'] ?? '');
$model        = $settings['deepseek_model'] ?? 'deepseek-chat';
$aiCostBase   = max(1, (int)($settings['ai_tokens_per_generation'] ?? 50));
$abVariantCost = max(1, (int)($settings['social_tokens_per_ab_variant'] ?? 2));

if (!isset($settings['social_enabled']) || $settings['social_enabled'] !== '1') {
    echo json_encode(['success' => false, 'message' => 'Social Media Marketing is not enabled.']);
    exit;
}
if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'AI is not configured. Please contact support.']);
    exit;
}

// ── Check AI token balance ─────────────────────────────────────────────────────
$aiBalance = 0;
try {
    $bStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $bStmt->execute([$userId]);
    $aiBalance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

if ($aiBalance < $aiCostBase) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Insufficient AI tokens. Need ' . $aiCostBase . ', have ' . $aiBalance . '. <a href="/billing.php?tab=ai_tokens">Buy AI tokens</a>.',
        'balance'  => $aiBalance,
        'required' => $aiCostBase,
    ]);
    exit;
}

// ── Check social token balance for A/B variants ───────────────────────────────
$socialCost = 0;
if ($numVariants > 1) {
    $socialCost = ($numVariants - 1) * $abVariantCost;
    $socBal     = 0;
    try {
        $sbStmt = $db->prepare("SELECT balance FROM user_social_tokens WHERE user_id=?");
        $sbStmt->execute([$userId]);
        $socBal = (int)($sbStmt->fetchColumn() ?: 0);
    } catch (\Exception $e) {}
    if ($socBal < $socialCost) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Insufficient social tokens for ' . $numVariants . ' A/B variants. Need ' . $socialCost . ' social tokens, have ' . $socBal . '. <a href="/billing.php?tab=social_tokens">Buy social tokens</a>.',
        ]);
        exit;
    }
}

// ── Platform character limits ─────────────────────────────────────────────────
$charLimits = [
    'twitter'   => 280,
    'facebook'  => 63206,
    'instagram' => 2200,
    'linkedin'  => 3000,
    'tiktok'    => 2200,
    'pinterest' => 500,
    'youtube'   => 5000,
];

// ── Platform tone guidance ────────────────────────────────────────────────────
$platformPersonas = [
    'linkedin'  => 'professional, data-driven, industry insights, no excessive emoji',
    'twitter'   => 'punchy, witty, under 240 chars, strong hook, max 2-3 hashtags',
    'instagram' => 'visual storytelling, lifestyle, emojis welcome, 5-10 hashtags',
    'facebook'  => 'conversational, community-focused, 1-3 hashtags',
    'tiktok'    => 'trendy, Gen-Z friendly, viral hooks, 3-5 trending hashtags',
    'pinterest' => 'inspirational, descriptive, keyword-rich, 2-5 hashtags',
    'youtube'   => 'engaging, benefit-focused, CTA prominent, 2-3 hashtags',
];

$platformList = implode(', ', $platforms);
$platformDetail = implode("\n", array_map(
    fn($p) => "- {$p}: " . ($platformPersonas[$p] ?? 'engaging') . ', max ' . ($charLimits[$p] ?? 2200) . ' chars',
    $platforms
));

// ── Build prompt ──────────────────────────────────────────────────────────────
$variantNote = $numVariants > 1
    ? "Generate EXACTLY {$numVariants} different variants of each platform post for A/B testing."
    : "Generate 1 variant per platform.";

$systemPrompt = <<<PROMPT
You are an expert social media marketing copywriter for 2026. Your job is to write highly optimised social media posts tailored to each platform's audience, character limit, and tone.

RULES:
1. {$variantNote}
2. Each post MUST stay within the platform character limit (caption + hashtags combined).
3. Tone override: "{$tone}" — apply this tone globally unless a platform persona contradicts it.
4. Include relevant, trending hashtags at the end of each post.
5. Return ONLY valid JSON — no commentary, no markdown fences.

OUTPUT FORMAT (strict JSON):
{
  "variants": [
    {
      "variant_number": 1,
      "posts": [
        {
          "platform": "facebook",
          "caption": "...",
          "hashtags": "#tag1 #tag2"
        }
      ]
    }
  ]
}
PROMPT;

$userMsg = "Goal: {$goal}";
if ($targetAudience !== '') $userMsg .= "\nTarget audience: {$targetAudience}";
if ($brandName !== '')      $userMsg .= "\nBrand: {$brandName}";
$userMsg .= "\nPlatforms and constraints:\n{$platformDetail}";

// ── Call DeepSeek ─────────────────────────────────────────────────────────────
$requestBody = json_encode([
    'model'    => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userMsg],
    ],
    'max_tokens'  => 2000,
    'temperature' => 0.85,
    'response_format' => ['type' => 'json_object'],
]);

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
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
    echo json_encode(['success' => false, 'message' => 'Failed to connect to AI service.']);
    exit;
}

$data = json_decode($resp, true);
if ($httpCode !== 200 || empty($data['choices'][0]['message']['content'])) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . htmlspecialchars($errMsg)]);
    exit;
}

$rawContent = trim($data['choices'][0]['message']['content']);
// Strip accidental markdown fences
$rawContent = preg_replace('/^```(?:json)?\s*/i', '', $rawContent);
$rawContent = preg_replace('/\s*```\s*$/i', '', $rawContent);

$parsed = json_decode($rawContent, true);
if (!is_array($parsed) || empty($parsed['variants'])) {
    // Try to salvage partial JSON or wrap single-variant response
    echo json_encode(['success' => false, 'message' => 'AI returned an unexpected format. Please try again.']);
    exit;
}

$variants = $parsed['variants'];

// ── Enforce character limits ───────────────────────────────────────────────────
foreach ($variants as &$variant) {
    foreach ($variant['posts'] as &$post) {
        $platform = $post['platform'] ?? '';
        $limit    = $charLimits[$platform] ?? 2200;
        $full     = ($post['caption'] ?? '') . "\n\n" . ($post['hashtags'] ?? '');
        if (mb_strlen($full) > $limit) {
            // Trim caption to fit
            $hashLen        = mb_strlen("\n\n" . ($post['hashtags'] ?? ''));
            $captionLimit   = $limit - $hashLen - 5;
            $post['caption'] = mb_substr($post['caption'] ?? '', 0, max(50, $captionLimit)) . '…';
        }
    }
    unset($post);
}
unset($variant);

// ── Deduct AI tokens ──────────────────────────────────────────────────────────
try {
    $db->prepare("INSERT INTO user_ai_tokens (user_id,balance) VALUES(?,0) ON DUPLICATE KEY UPDATE balance=GREATEST(0,balance-?), updated_at=NOW()")
       ->execute([$userId, $aiCostBase]);
    $db->prepare("INSERT INTO ai_token_ledger (user_id,delta,action,description) VALUES(?,?,'generate',?)")
       ->execute([$userId, -$aiCostBase, 'Social content: ' . mb_substr($goal, 0, 80)]);
} catch (\Exception $e) {
    error_log('ai-social AI token deduction: ' . $e->getMessage());
}

// ── Deduct social tokens for A/B variants ────────────────────────────────────
if ($socialCost > 0) {
    AyrshareClient::deductTokens($db, $userId, $socialCost, 'ab_variant', "A/B variants ({$numVariants}): " . mb_substr($goal, 0, 60));
}

// Return updated balances
$newAiBalance     = 0;
$newSocialBalance = 0;
try {
    $bStmt->execute([$userId]);
    $newAiBalance = (int)($bStmt->fetchColumn() ?: 0);
    $sbStmt2 = $db->prepare("SELECT balance FROM user_social_tokens WHERE user_id=?");
    $sbStmt2->execute([$userId]);
    $newSocialBalance = (int)($sbStmt2->fetchColumn() ?: 0);
} catch (\Exception $e) {}

echo json_encode([
    'success'          => true,
    'variants'         => $variants,
    'num_variants'     => count($variants),
    'platforms'        => $platforms,
    'ai_tokens_used'   => $aiCostBase,
    'social_tokens_used' => $socialCost,
    'ai_balance'       => $newAiBalance,
    'social_balance'   => $newSocialBalance,
]);
