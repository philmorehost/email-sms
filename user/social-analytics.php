<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);

AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
$enabled  = ($settings['social_enabled'] ?? '0') === '1';

$campaignId = (int)($_GET['campaign_id'] ?? 0);
if ($campaignId <= 0 || !$enabled) {
    header('Location: /user/social.php');
    exit;
}

// Load campaign — must belong to this user
$cStmt = $db->prepare("SELECT * FROM social_campaigns WHERE id=? AND user_id=?");
$cStmt->execute([$campaignId, $uid]);
$campaign = $cStmt->fetch();
if (!$campaign) {
    header('Location: /user/social.php?tab=campaigns');
    exit;
}

// Load analytics — try cache first (1hr TTL)
$analytics = null;
try {
    $cacheStmt = $db->prepare("SELECT analytics_json, cached_at FROM social_analytics_cache WHERE campaign_id=?");
    $cacheStmt->execute([$campaignId]);
    $cached = $cacheStmt->fetch();
    if ($cached && strtotime($cached['cached_at']) > (time() - 3600)) {
        $analytics = json_decode($cached['analytics_json'], true);
    }
} catch (\Exception $e) {}

// If not cached and post was published, fetch from Ayrshare
if ($analytics === null && $campaign['status'] === 'posted' && $campaign['ayrshare_post_id']) {
    $connStmt = $db->prepare("SELECT ayrshare_profile_key FROM social_connections WHERE user_id=?");
    $connStmt->execute([$uid]);
    $profileKey = $connStmt->fetchColumn();

    if ($profileKey) {
        $apiKey = trim($settings['ayrshare_api_key'] ?? '');
        if ($apiKey !== '') {
            $client = new AyrshareClient($apiKey);
            $result = $client->getPostAnalytics($profileKey, $campaign['ayrshare_post_id']);
            if ($result['success'] && !empty($result['data'])) {
                $analytics = $result['data'];
                // Cache result
                try {
                    $db->prepare(
                        "INSERT INTO social_analytics_cache (campaign_id, analytics_json) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE analytics_json=?, cached_at=NOW()"
                    )->execute([$campaignId, json_encode($analytics), json_encode($analytics)]);
                } catch (\Exception $e) {}
            }
        }
    }
}

$pageTitle  = 'Campaign Analytics #' . $campaignId;
$activePage = 'social';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div style="max-width:900px">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem">
    <div>
        <h1 class="page-title">📊 Campaign #<?= $campaignId ?> Analytics</h1>
        <p style="color:var(--text-muted);margin:0">
            <?= htmlspecialchars(str_replace(',', ' · ', $campaign['platform_mask'])) ?>
            &nbsp;·&nbsp;
            <?php if ($campaign['posted_at']): ?>
                Posted <?= timeAgo($campaign['posted_at']) ?>
            <?php else: ?>
                Status: <?= htmlspecialchars($campaign['status']) ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="/user/social.php?tab=campaigns" class="btn btn-secondary">← Back to Campaigns</a>
</div>

<!-- Campaign details -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>📝 Post Details</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem" class="ana-grid">
            <div>
                <label class="form-label">Caption</label>
                <p style="background:rgba(255,255,255,.04);border:1px solid var(--glass-border);border-radius:8px;padding:.75rem;white-space:pre-wrap;font-size:.9rem">
                    <?= htmlspecialchars($campaign['caption'] ?? '') ?>
                </p>
            </div>
            <div>
                <label class="form-label">Hashtags</label>
                <p style="color:#a78bfa;font-size:.9rem"><?= htmlspecialchars($campaign['hashtags'] ?? '—') ?></p>
                <?php if ($campaign['image_url']): ?>
                <label class="form-label" style="margin-top:.75rem">Image</label>
                <img src="<?= htmlspecialchars($campaign['image_url']) ?>" alt="Post image"
                     style="max-width:200px;border-radius:8px;margin-top:.25rem">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Analytics -->
<?php if ($campaign['status'] !== 'posted'): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
        <div style="font-size:3rem;margin-bottom:.5rem">📊</div>
        <p>Analytics are available after the post is published.</p>
        <p style="font-size:.85rem">Current status: <strong><?= htmlspecialchars($campaign['status']) ?></strong></p>
    </div>
</div>
<?php elseif ($analytics === null): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
        <div style="font-size:3rem;margin-bottom:.5rem">⏳</div>
        <p>Analytics are not yet available. They typically appear within 24 hours of posting.</p>
        <button onclick="location.reload()" class="btn btn-secondary" style="margin-top:1rem">🔄 Refresh</button>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h3>📊 Engagement Metrics</h3></div>
    <div class="card-body">

        <?php
        // Try to extract common analytics fields
        $totalLikes    = 0;
        $totalComments = 0;
        $totalShares   = 0;
        $totalReach    = 0;
        $totalClicks   = 0;

        $perPlatform = $analytics['analytics'] ?? $analytics['postAnalytics'] ?? [];
        if (!$perPlatform && isset($analytics['likes'])) {
            $perPlatform = [['platform' => 'all', 'likes' => $analytics['likes'], 'comments' => $analytics['comments'] ?? 0, 'shares' => $analytics['shares'] ?? 0]];
        }

        foreach ($perPlatform as $pa) {
            $totalLikes    += (int)($pa['likes']    ?? $pa['like_count']    ?? 0);
            $totalComments += (int)($pa['comments'] ?? $pa['comment_count'] ?? 0);
            $totalShares   += (int)($pa['shares']   ?? $pa['share_count']   ?? 0);
            $totalReach    += (int)($pa['reach']    ?? $pa['impressions']   ?? 0);
            $totalClicks   += (int)($pa['clicks']   ?? $pa['click_count']   ?? 0);
        }
        ?>

        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem" class="ana-metrics">
            <?php foreach ([
                ['❤️', 'Likes',    $totalLikes,    '#ef4444'],
                ['💬', 'Comments', $totalComments, '#6c63ff'],
                ['🔁', 'Shares',   $totalShares,   '#10b981'],
                ['👁️', 'Reach',    $totalReach,    '#f59e0b'],
                ['🖱️', 'Clicks',   $totalClicks,   '#06b6d4'],
            ] as [$icon, $label, $val, $col]): ?>
            <div style="text-align:center;background:<?= $col ?>15;border:1px solid <?= $col ?>44;border-radius:10px;padding:1rem">
                <div style="font-size:1.5rem"><?= $icon ?></div>
                <div style="font-size:1.75rem;font-weight:700;color:<?= $col ?>"><?= number_format($val) ?></div>
                <div style="font-size:.8rem;color:var(--text-muted)"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($perPlatform)): ?>
        <h4 style="margin-bottom:1rem">Per-Platform Breakdown</h4>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Platform</th><th>Likes</th><th>Comments</th><th>Shares</th><th>Reach</th><th>Clicks</th></tr></thead>
            <tbody>
            <?php foreach ($perPlatform as $pa): ?>
            <tr>
                <td><strong><?= htmlspecialchars(ucfirst($pa['platform'] ?? 'Unknown')) ?></strong></td>
                <td><?= number_format((int)($pa['likes']    ?? 0)) ?></td>
                <td><?= number_format((int)($pa['comments'] ?? 0)) ?></td>
                <td><?= number_format((int)($pa['shares']   ?? 0)) ?></td>
                <td><?= number_format((int)($pa['reach']    ?? $pa['impressions'] ?? 0)) ?></td>
                <td><?= number_format((int)($pa['clicks']   ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

</div>

<style>
@media(max-width:768px){
    .ana-grid,.ana-metrics{grid-template-columns:1fr 1fr!important}
}
@media(max-width:480px){
    .ana-metrics{grid-template-columns:1fr 1fr!important}
}
</style>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
