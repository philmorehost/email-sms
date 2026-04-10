<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

setSecurityHeaders();
requireAuth();
$db   = getDB();
$user = getCurrentUser();

function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['flash_msg']  ?? '';
    $type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/analytics.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'remove_unsubscribe') {
        $email = sanitize($_POST['email'] ?? '');
        if ($email === '') { setFlash('Email required.', 'error'); redirect('/admin/analytics.php'); }
        try {
            $db->prepare('DELETE FROM email_unsubscribes WHERE email=?')->execute([$email]);
            setFlash('Removed from unsubscribe list.');
        } catch (\Exception $e) { setFlash('Error removing unsubscribe.', 'error'); }
        redirect('/admin/analytics.php');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/analytics.php');
}

$flash = popFlash();

// Stats
$totalEmailSent    = 0;
$totalSmsSent      = 0;
$emailOpenRate     = 0.0;
$clickThroughRate  = 0.0;
$failedDeliveries  = 0;
$activeSubscribers = 0;

try {
    $totalEmailSent   = (int)$db->query("SELECT COALESCE(SUM(sent_count),0) FROM email_campaigns")->fetchColumn();
    $totalSmsSent     = (int)$db->query("SELECT COALESCE(SUM(sent_count),0) FROM sms_campaigns")->fetchColumn();

    $analyticsRow = $db->query("
        SELECT
            SUM(event_type='opened')    AS opens,
            SUM(event_type='sent')      AS sent_ev,
            SUM(event_type='clicked')   AS clicks
        FROM email_campaign_analytics
    ")->fetch();
    $sentEv           = (int)($analyticsRow['sent_ev'] ?? 0);
    $emailOpenRate    = $sentEv > 0 ? round(((int)$analyticsRow['opens'] / $sentEv) * 100, 1) : 0.0;
    $clickThroughRate = $sentEv > 0 ? round(((int)$analyticsRow['clicks'] / $sentEv) * 100, 1) : 0.0;

    $emailFailed  = (int)$db->query("SELECT COALESCE(SUM(failed_count),0) FROM email_campaigns")->fetchColumn();
    $smsFailed    = (int)$db->query("SELECT COALESCE(SUM(failed_count),0) FROM sms_campaigns")->fetchColumn();
    $failedDeliveries = $emailFailed + $smsFailed;

    $emailSubs  = (int)$db->query("SELECT COUNT(*) FROM email_contacts WHERE is_subscribed=1")->fetchColumn();
    $smsSubs    = (int)$db->query("SELECT COUNT(*) FROM sms_contacts WHERE is_subscribed=1")->fetchColumn();
    $activeSubscribers = $emailSubs + $smsSubs;
} catch (\Exception $e) {}

// Bar chart: email campaigns last 30 days
$rawChart = [];
try {
    $rows = $db->query("SELECT DATE(created_at) AS day, SUM(sent_count) AS total FROM email_campaigns WHERE created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY day ORDER BY day")->fetchAll();
    foreach ($rows as $r) { $rawChart[$r['day']] = (int)$r['total']; }
} catch (\Exception $e) {}

$chartData = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartData[] = ['day' => $day, 'total' => $rawChart[$day] ?? 0];
}

// Top campaigns
$topCampaigns = [];
try {
    $topCampaigns = $db->query("SELECT name, sent_count, status FROM email_campaigns ORDER BY sent_count DESC LIMIT 10")->fetchAll();
} catch (\Exception $e) {}

// Recent activity
$recentActivity = [];
try {
    $recentActivity = $db->query("SELECT a.*, ec.name AS campaign_name FROM email_campaign_analytics a LEFT JOIN email_campaigns ec ON ec.id=a.campaign_id ORDER BY a.event_at DESC LIMIT 20")->fetchAll();
} catch (\Exception $e) {}

// Campaign stats
$campaignStats = [];
try {
    $campaignStats = $db->query("
        SELECT ec.id, ec.name, ec.sent_count,
            COALESCE(SUM(a.event_type='opened'),0)       AS opens,
            COALESCE(SUM(a.event_type='clicked'),0)      AS clicks,
            COALESCE(SUM(a.event_type='bounced'),0)      AS bounces,
            COALESCE(SUM(a.event_type='unsubscribed'),0) AS unsubs
        FROM email_campaigns ec
        LEFT JOIN email_campaign_analytics a ON a.campaign_id=ec.id
        GROUP BY ec.id
        ORDER BY ec.created_at DESC
        LIMIT 20
    ")->fetchAll();
} catch (\Exception $e) {}

// Unsubscribes
$unsubPage    = max(1, (int)($_GET['unsub_page'] ?? 1));
$unsubPerPage = 20;
$unsubOffset  = ($unsubPage - 1) * $unsubPerPage;
$unsubTotal   = 0;
$unsubList    = [];
try {
    $unsubTotal = (int)$db->query("SELECT COUNT(*) FROM email_unsubscribes")->fetchColumn();
    $stmt = $db->prepare("SELECT email, reason, unsubscribed_at FROM email_unsubscribes ORDER BY unsubscribed_at DESC LIMIT {$unsubPerPage} OFFSET {$unsubOffset}");
    $stmt->execute();
    $unsubList = $stmt->fetchAll();
} catch (\Exception $e) {}
$unsubPages = (int)ceil($unsubTotal / $unsubPerPage) ?: 1;

$pageTitle  = 'Analytics';
$activePage = 'analytics';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:1rem;text-align:center}
.stat-val{font-size:1.8rem;font-weight:700;color:var(--primary,#6c63ff)}
.stat-label{font-size:.85rem;color:var(--text-muted,#94a3b8)}
.section-title{font-size:1.1rem;font-weight:600;margin:2rem 0 1rem}
.bar-chart{display:flex;flex-direction:column;gap:.3rem}
.bar-row{display:flex;align-items:center;gap:.5rem;font-size:.8rem}
.bar-label{width:80px;color:var(--text-muted);text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-track{flex:1;background:var(--border-color,#334155);border-radius:3px;height:14px}
.bar-fill{background:var(--primary,#6c63ff);height:14px;border-radius:3px;transition:width .3s}
.bar-val{width:40px;color:var(--text-muted);font-size:.8rem}
</style>

<h1>Analytics</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-val"><?= number_format($totalEmailSent) ?></div><div class="stat-label">Emails Sent</div></div>
    <div class="stat-card"><div class="stat-val"><?= number_format($totalSmsSent) ?></div><div class="stat-label">SMS Sent</div></div>
    <div class="stat-card"><div class="stat-val"><?= $emailOpenRate ?>%</div><div class="stat-label">Email Open Rate</div></div>
    <div class="stat-card"><div class="stat-val"><?= $clickThroughRate ?>%</div><div class="stat-label">Click-Through Rate</div></div>
    <div class="stat-card"><div class="stat-val"><?= number_format($failedDeliveries) ?></div><div class="stat-label">Failed Deliveries</div></div>
    <div class="stat-card"><div class="stat-val"><?= number_format($activeSubscribers) ?></div><div class="stat-label">Active Subscribers</div></div>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-body">
        <h2 class="section-title" style="margin-top:0">Campaign Activity — Last 30 Days</h2>
        <div class="bar-chart">
        <?php $maxVal = max(1, max(array_column($chartData, 'total'))); ?>
        <?php foreach ($chartData as $row): ?>
        <div class="bar-row">
            <span class="bar-label"><?= htmlspecialchars($row['day']) ?></span>
            <div class="bar-track">
                <div class="bar-fill" style="width:<?= min(100, round(($row['total'] / $maxVal) * 100)) ?>%"></div>
            </div>
            <span class="bar-val"><?= (int)$row['total'] ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-body">
        <h2 class="section-title" style="margin-top:0">Top Performing Campaigns</h2>
        <?php if (!empty($topCampaigns)): ?>
        <?php $maxSent = max(1, max(array_column($topCampaigns, 'sent_count'))); ?>
        <div class="bar-chart">
        <?php foreach ($topCampaigns as $tc): ?>
        <div class="bar-row">
            <span class="bar-label" title="<?= htmlspecialchars($tc['name']) ?>"><?= htmlspecialchars($tc['name']) ?></span>
            <div class="bar-track">
                <div class="bar-fill" style="width:<?= min(100, round(((int)$tc['sent_count'] / $maxSent) * 100)) ?>%"></div>
            </div>
            <span class="bar-val"><?= number_format((int)$tc['sent_count']) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="color:var(--text-muted)">No campaign data yet.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-body">
        <h2 class="section-title" style="margin-top:0">Recent Activity</h2>
        <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Campaign</th><th>Event</th><th>Recipient</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $ev): ?>
            <tr>
                <td><?= htmlspecialchars($ev['campaign_name'] ?? '—') ?></td>
                <td>
                    <?php
                    $badgeClass = match($ev['event_type']) {
                        'opened'       => 'badge-success',
                        'clicked'      => 'badge-primary',
                        'bounced'      => 'badge-danger',
                        'unsubscribed' => 'badge-warning',
                        'spam'         => 'badge-danger',
                        default        => 'badge-secondary',
                    };
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ev['event_type']) ?></span>
                </td>
                <td><?= htmlspecialchars($ev['recipient_email'] ?? '—') ?></td>
                <td><?= htmlspecialchars($ev['event_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentActivity)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No activity yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-body">
        <h2 class="section-title" style="margin-top:0">Email Campaign Stats</h2>
        <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Campaign</th><th>Sent</th><th>Opens</th><th>Open Rate</th><th>Clicks</th><th>Bounces</th><th>Unsubs</th></tr></thead>
            <tbody>
            <?php foreach ($campaignStats as $cs): ?>
            <?php $openRate = (int)$cs['sent_count'] > 0 ? round(((int)$cs['opens'] / (int)$cs['sent_count']) * 100, 1) : 0; ?>
            <tr>
                <td><?= htmlspecialchars($cs['name']) ?></td>
                <td><?= number_format((int)$cs['sent_count']) ?></td>
                <td><?= number_format((int)$cs['opens']) ?></td>
                <td><?= $openRate ?>%</td>
                <td><?= number_format((int)$cs['clicks']) ?></td>
                <td><?= number_format((int)$cs['bounces']) ?></td>
                <td><?= number_format((int)$cs['unsubs']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($campaignStats)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">No campaigns yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="section-title" style="margin-top:0">Email Unsubscribes (<?= $unsubTotal ?>)</h2>
        <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Email</th><th>Reason</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($unsubList as $us): ?>
            <tr>
                <td><?= htmlspecialchars($us['email']) ?></td>
                <td><?= htmlspecialchars($us['reason'] ?? '—') ?></td>
                <td><?= htmlspecialchars($us['unsubscribed_at']) ?></td>
                <td>
                    <form method="POST" action="/admin/analytics.php" style="display:inline" onsubmit="return confirm('Remove from unsubscribe list?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action" value="remove_unsubscribe">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($us['email']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($unsubList)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No unsubscribes.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($unsubPages > 1): ?>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
            <?php for ($p = 1; $p <= $unsubPages; $p++): ?>
            <a href="/admin/analytics.php?unsub_page=<?= $p ?>"
               class="btn btn-sm <?= $p === $unsubPage ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
