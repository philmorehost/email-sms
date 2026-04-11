<?php
/**
 * Social Post Endpoint
 * POST /api/social-post.php
 * Body: {
 *   "campaign_id": 123,          // existing draft campaign ID
 *   "action":      "post_now" | "schedule",
 *   "scheduled_at": "2026-04-15T14:00:00Z"  // required if action=schedule
 * }
 *
 * Deducts social tokens, calls Ayrshare, updates campaign status.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON.']);
    exit;
}

$db = getDB();
AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
if (($settings['social_enabled'] ?? '0') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Social Media Marketing is not enabled.']);
    exit;
}

$apiKey = trim($settings['ayrshare_api_key'] ?? '');
if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'Social API not configured.']);
    exit;
}

$action     = $body['action']     ?? '';
$campaignId = (int)($body['campaign_id'] ?? 0);

if (!in_array($action, ['post_now', 'schedule'], true) || $campaignId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

// Load the campaign — must belong to this user
$campStmt = $db->prepare("SELECT * FROM social_campaigns WHERE id=? AND user_id=?");
$campStmt->execute([$campaignId, $userId]);
$campaign = $campStmt->fetch();
if (!$campaign) {
    echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
    exit;
}
if (!in_array($campaign['status'], ['draft', 'failed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Campaign is not in a postable state.']);
    exit;
}

// Load user's Ayrshare profile key
$connStmt = $db->prepare("SELECT ayrshare_profile_key FROM social_connections WHERE user_id=?");
$connStmt->execute([$userId]);
$profileKey = $connStmt->fetchColumn();
if (!$profileKey) {
    echo json_encode(['success' => false, 'message' => 'No social accounts connected. Please connect your accounts first.']);
    exit;
}

// Determine cost
$tokenAction = $action === 'post_now' ? 'post_now' : 'scheduled_post';
$cost        = $action === 'post_now'
    ? max(1, (int)($settings['social_tokens_per_post_now'] ?? 1))
    : max(1, (int)($settings['social_tokens_per_scheduled_post'] ?? 5));

// Check & deduct balance
$bStmt = $db->prepare("SELECT balance FROM user_social_tokens WHERE user_id=?");
$bStmt->execute([$userId]);
$balance = (int)($bStmt->fetchColumn() ?: 0);
if ($balance < $cost) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Insufficient social tokens. Need ' . $cost . ', have ' . $balance . '. <a href="/billing.php?tab=social_tokens">Buy tokens</a>.',
        'balance'  => $balance,
        'required' => $cost,
    ]);
    exit;
}

// Prepare post content (caption + hashtags combined)
$caption   = trim((string)($campaign['caption'] ?? ''));
$hashtags  = trim((string)($campaign['hashtags'] ?? ''));
$postText  = $hashtags !== '' ? $caption . "\n\n" . $hashtags : $caption;

$platforms = array_filter(array_map('trim', explode(',', $campaign['platform_mask'] ?? '')));
$mediaUrls = $campaign['image_url'] ? [$campaign['image_url']] : [];

if (empty($platforms)) {
    echo json_encode(['success' => false, 'message' => 'No platforms selected for this campaign.']);
    exit;
}

$client = new AyrshareClient($apiKey);

if ($action === 'post_now') {
    // Mark as posting
    $db->prepare("UPDATE social_campaigns SET status='posting' WHERE id=?")->execute([$campaignId]);

    $result = $client->postNow($profileKey, array_values($platforms), $postText, $mediaUrls);

    if ($result['success']) {
        $postId = $result['data']['id'] ?? $result['data']['postIds'][0] ?? null;
        $deducted = AyrshareClient::deductTokens($db, $userId, $cost, $tokenAction, "Post Now campaign #{$campaignId}", $campaignId);
        $db->prepare("UPDATE social_campaigns SET status='posted', posted_at=NOW(), ayrshare_post_id=? WHERE id=?")
           ->execute([$postId, $campaignId]);
    } else {
        $db->prepare("UPDATE social_campaigns SET status='failed' WHERE id=?")->execute([$campaignId]);
        echo json_encode(['success' => false, 'message' => 'Post failed: ' . $result['message']]);
        exit;
    }
} else {
    // schedule
    $scheduledAt = trim($body['scheduled_at'] ?? '');
    if ($scheduledAt === '') {
        echo json_encode(['success' => false, 'message' => 'scheduled_at is required for scheduling.']);
        exit;
    }
    // Validate ISO 8601 format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $scheduledAt)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use ISO 8601 (e.g. 2026-04-15T14:00:00Z).']);
        exit;
    }

    $result = $client->schedulePost($profileKey, array_values($platforms), $postText, $scheduledAt, $mediaUrls);

    if ($result['success']) {
        $postId = $result['data']['id'] ?? null;
        $deducted = AyrshareClient::deductTokens($db, $userId, $cost, $tokenAction, "Scheduled campaign #{$campaignId}", $campaignId);
        $mysqlDt  = date('Y-m-d H:i:s', strtotime($scheduledAt));
        $db->prepare("UPDATE social_campaigns SET status='scheduled', scheduled_at=?, ayrshare_post_id=? WHERE id=?")
           ->execute([$mysqlDt, $postId, $campaignId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Scheduling failed: ' . $result['message']]);
        exit;
    }
}

// Return updated balance
$bStmt->execute([$userId]);
$newBalance = (int)($bStmt->fetchColumn() ?: 0);

echo json_encode([
    'success'      => true,
    'message'      => $action === 'post_now' ? 'Post published successfully!' : 'Post scheduled successfully!',
    'campaign_id'  => $campaignId,
    'tokens_used'  => $cost,
    'balance'      => $newBalance,
]);
