<?php
/**
 * Save Social Campaign Draft (AJAX)
 * POST /api/social-campaign-save.php
 * Body: { csrf_token, caption, hashtags, image_url, platforms[] }
 * Returns: { success, campaign_id }
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

if (!verifyCsrf($body['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$db = getDB();
AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
if (($settings['social_enabled'] ?? '0') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Social Media Marketing is not enabled.']);
    exit;
}

$caption   = trim($body['caption']   ?? '');
$hashtags  = trim($body['hashtags']  ?? '');
$imageUrl  = trim($body['image_url'] ?? '');
$platforms = is_array($body['platforms'] ?? null)
    ? implode(',', array_filter(array_map('trim', $body['platforms'])))
    : trim((string)($body['platforms'] ?? ''));

if ($caption === '') {
    echo json_encode(['success' => false, 'message' => 'Caption is required.']);
    exit;
}

$db->prepare(
    "INSERT INTO social_campaigns (user_id, platform_mask, caption, hashtags, image_url, status, created_at)
     VALUES (?, ?, ?, ?, ?, 'draft', NOW())"
)->execute([$userId, $platforms, $caption, $hashtags, $imageUrl]);

$campaignId = (int)$db->lastInsertId();

echo json_encode(['success' => true, 'campaign_id' => $campaignId]);
