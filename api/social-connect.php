<?php
/**
 * Social Connect Endpoint
 * GET  /api/social-connect.php?action=link        — generate Ayrshare link URL for user
 * GET  /api/social-connect.php?action=status      — get connected platforms
 * POST /api/social-connect.php  {action:'disconnect'} — remove profile
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
$userId   = (int)($user['id'] ?? 0);
$username = $user['username'] ?? 'User';

$db = getDB();
AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
if (($settings['social_enabled'] ?? '0') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Social Media Marketing is not enabled on this platform.']);
    exit;
}

$apiKey = trim($settings['ayrshare_api_key'] ?? '');
if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'Social API not configured. Contact support.']);
    exit;
}

$client = new AyrshareClient($apiKey);
$action = $_GET['action'] ?? ($_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? ''));

// ── GET /api/social-connect.php?action=link ────────────────────────────────
if ($action === 'link') {
    // Ensure user has an Ayrshare profile
    $connStmt = $db->prepare("SELECT ayrshare_profile_key FROM social_connections WHERE user_id=?");
    $connStmt->execute([$userId]);
    $profileKey = $connStmt->fetchColumn();

    if (!$profileKey) {
        // Create a new profile
        $result = $client->createProfile($userId, $username . ' (user #' . $userId . ')');
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => 'Could not create social profile: ' . $result['message']]);
            exit;
        }
        $profileKey = $result['data']['profileKey'] ?? '';
        if ($profileKey === '') {
            echo json_encode(['success' => false, 'message' => 'Ayrshare did not return a profile key.']);
            exit;
        }
        $db->prepare("INSERT INTO social_connections (user_id, ayrshare_profile_key) VALUES (?,?) ON DUPLICATE KEY UPDATE ayrshare_profile_key=?")
           ->execute([$userId, $profileKey, $profileKey]);
    }

    // Generate JWT link URL
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? '';
    $redirectUrl = $protocol . '://' . $host . '/user/social.php?tab=connect&linked=1';

    $linkResult = $client->generateLinkUrl($profileKey, $redirectUrl);
    if (!$linkResult['success']) {
        echo json_encode(['success' => false, 'message' => 'Could not generate link URL: ' . $linkResult['message']]);
        exit;
    }

    $url = $linkResult['data']['url'] ?? '';
    echo json_encode(['success' => true, 'url' => $url, 'profileKey' => $profileKey]);
    exit;
}

// ── GET /api/social-connect.php?action=status ──────────────────────────────
if ($action === 'status') {
    $connStmt = $db->prepare("SELECT ayrshare_profile_key, platforms_json FROM social_connections WHERE user_id=?");
    $connStmt->execute([$userId]);
    $conn = $connStmt->fetch();

    if (!$conn) {
        echo json_encode(['success' => true, 'connected' => false, 'platforms' => []]);
        exit;
    }

    $profileKey = $conn['ayrshare_profile_key'];
    $result     = $client->getLinkedPlatforms($profileKey);
    $platforms  = [];
    if ($result['success']) {
        $platforms = $result['data']['activeSocialAccounts'] ?? $result['data']['platforms'] ?? [];
        // Cache in DB
        $db->prepare("UPDATE social_connections SET platforms_json=? WHERE user_id=?")
           ->execute([json_encode($platforms), $userId]);
    } else {
        // Use cached value
        $platforms = json_decode($conn['platforms_json'] ?? '[]', true) ?: [];
    }

    echo json_encode(['success' => true, 'connected' => true, 'platforms' => $platforms, 'profileKey' => $profileKey]);
    exit;
}

// ── POST {action:'disconnect'} ─────────────────────────────────────────────
if ($action === 'disconnect') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!verifyCsrf($body['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $connStmt = $db->prepare("SELECT ayrshare_profile_key FROM social_connections WHERE user_id=?");
    $connStmt->execute([$userId]);
    $profileKey = $connStmt->fetchColumn();

    if ($profileKey) {
        $client->deleteProfile($profileKey);
        $db->prepare("DELETE FROM social_connections WHERE user_id=?")->execute([$userId]);
    }

    echo json_encode(['success' => true, 'message' => 'Social accounts disconnected.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
