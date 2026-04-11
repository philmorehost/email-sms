<?php
/**
 * Social Media Post Scheduler Cron Job
 * Run every 5 minutes:
 *   * * /5 * * php /path/to/cron/social-scheduler.php
 *
 * Finds social_campaigns with status='scheduled' and scheduled_at <= NOW(),
 * fires the post via Ayrshare, and updates the campaign status.
 */
declare(strict_types=1);

// CLI-only execution guard
if (PHP_SAPI !== 'cli' && !getenv('CRON_SECRET')) {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/social.php';

$db = getDB();
AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
if (($settings['social_enabled'] ?? '0') !== '1') {
    exit("Social feature disabled.\n");
}

$apiKey = trim($settings['ayrshare_api_key'] ?? '');
if ($apiKey === '') {
    exit("Ayrshare API key not configured.\n");
}

$client = new AyrshareClient($apiKey);

// Fetch due campaigns
$stmt = $db->prepare(
    "SELECT sc.*, conn.ayrshare_profile_key
     FROM social_campaigns sc
     JOIN social_connections conn ON conn.user_id = sc.user_id
     WHERE sc.status = 'scheduled'
       AND sc.scheduled_at <= NOW()
     ORDER BY sc.scheduled_at ASC
     LIMIT 50"
);
$stmt->execute();
$campaigns = $stmt->fetchAll();

$processed = 0;
$failed    = 0;

foreach ($campaigns as $campaign) {
    $campaignId = (int)$campaign['id'];
    $profileKey = $campaign['ayrshare_profile_key'];

    // Mark as posting to prevent duplicate execution
    $lock = $db->prepare("UPDATE social_campaigns SET status='posting' WHERE id=? AND status='scheduled'");
    $lock->execute([$campaignId]);
    if ($lock->rowCount() === 0) {
        // Another process already picked it up
        continue;
    }

    $caption   = trim((string)($campaign['caption'] ?? ''));
    $hashtags  = trim((string)($campaign['hashtags'] ?? ''));
    $postText  = $hashtags !== '' ? $caption . "\n\n" . $hashtags : $caption;
    $platforms = array_values(array_filter(array_map('trim', explode(',', $campaign['platform_mask'] ?? ''))));
    $mediaUrls = $campaign['image_url'] ? [$campaign['image_url']] : [];

    if (empty($platforms)) {
        $db->prepare("UPDATE social_campaigns SET status='failed' WHERE id=?")->execute([$campaignId]);
        echo "Campaign #{$campaignId}: no platforms, marking failed.\n";
        $failed++;
        continue;
    }

    $result = $client->postNow($profileKey, $platforms, $postText, $mediaUrls);

    if ($result['success']) {
        $postId = $result['data']['id'] ?? $result['data']['postIds'][0] ?? null;
        $db->prepare("UPDATE social_campaigns SET status='posted', posted_at=NOW(), ayrshare_post_id=? WHERE id=?")
           ->execute([$postId, $campaignId]);
        echo "Campaign #{$campaignId}: posted successfully. Ayrshare ID: {$postId}\n";
        $processed++;
    } else {
        $db->prepare("UPDATE social_campaigns SET status='failed' WHERE id=?")->execute([$campaignId]);
        echo "Campaign #{$campaignId}: FAILED — " . $result['message'] . "\n";
        $failed++;
    }

    // Brief pause to avoid rate-limiting Ayrshare
    usleep(300000); // 300ms
}

echo "Done. Processed: {$processed}, Failed: {$failed}.\n";
