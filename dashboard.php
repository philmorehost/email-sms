<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

setSecurityHeaders();
requireAuth();

$user = getCurrentUser();

// Redirect regular users to their own dashboard
if (!in_array($user['role'] ?? '', ['superadmin', 'admin'], true)) {
    redirect('/user/dashboard.php');
}

$db   = getDB();

// Stats
$stats = [];
try {
    $stats['email_contacts']   = (int)$db->query("SELECT COUNT(*) FROM email_contacts WHERE is_subscribed=1")->fetchColumn();
    $stats['sms_contacts']     = (int)$db->query("SELECT COUNT(*) FROM sms_contacts WHERE is_subscribed=1")->fetchColumn();
    $stats['email_campaigns']  = (int)$db->query("SELECT COUNT(*) FROM email_campaigns")->fetchColumn();
    $stats['sms_campaigns']    = (int)$db->query("SELECT COUNT(*) FROM sms_campaigns")->fetchColumn();
    $stats['emails_sent']      = (int)$db->query("SELECT COALESCE(SUM(sent_count),0) FROM email_campaigns")->fetchColumn();
    $stats['sms_sent']         = (int)$db->query("SELECT COALESCE(SUM(sent_count),0) FROM sms_campaigns")->fetchColumn();
} catch (\Exception $e) {
    $stats = array_fill_keys(['email_contacts','sms_contacts','email_campaigns','sms_campaigns','emails_sent','sms_sent'], 0);
}

// Recent campaigns
$recentEmail = [];
$recentSMS   = [];
try {
    $recentEmail = $db->query("SELECT * FROM email_campaigns ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $recentSMS   = $db->query("SELECT * FROM sms_campaigns ORDER BY created_at DESC LIMIT 5")->fetchAll();
} catch (\Exception $e) {}

$recentLogs = getRecentSecurityLogs(10);
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <p>Welcome back, <strong><?= htmlspecialchars($user['username'] ?? '') ?></strong></p>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#6c63ff,#00d4ff)">📧</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['email_contacts']) ?></div>
            <div class="stat-label">Email Subscribers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#00d4ff,#00ff88)">📱</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['sms_contacts']) ?></div>
            <div class="stat-label">SMS Contacts</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#00ff88,#6c63ff)">📨</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['emails_sent']) ?></div>
            <div class="stat-label">Emails Sent</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ff6b6b,#ffa500)">💬</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['sms_sent']) ?></div>
            <div class="stat-label">SMS Sent</div>
        </div>
    </div>
</div>

<!-- Recent Campaigns Grid -->
<div class="dashboard-grid">
    <!-- Recent Email Campaigns -->
    <div class="card">
        <div class="card-header">
            <h3>📧 Recent Email Campaigns</h3>
            <a href="/admin/email.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <?php if (empty($recentEmail)): ?>
        <p class="empty-state">No campaigns yet. <a href="/admin/email.php">Create one</a></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentEmail as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><span class="badge badge-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
                <td><?= number_format((int)$c['sent_count']) ?></td>
                <td><?= timeAgo($c['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent SMS Campaigns -->
    <div class="card">
        <div class="card-header">
            <h3>📱 Recent SMS Campaigns</h3>
            <a href="/admin/sms.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <?php if (empty($recentSMS)): ?>
        <p class="empty-state">No campaigns yet. <a href="/admin/sms.php">Create one</a></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentSMS as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><span class="badge badge-<?= $c['status'] ?>"><?= $c['status'] ?></span></td>
                <td><?= number_format((int)$c['sent_count']) ?></td>
                <td><?= timeAgo($c['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Security Log Widget -->
<div class="card" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>🛡️ Real-time Security Log</h3>
        <span class="live-badge">● LIVE</span>
    </div>
    <div id="securityLog" class="security-log">
        <?php foreach ($recentLogs as $log): ?>
        <div class="log-entry <?= $log['is_trusted'] ? 'trusted' : '' ?>">
            <span class="log-icon"><?= $log['is_trusted'] ? '👑' : getEventIcon($log['event_type']) ?></span>
            <span class="log-event"><?= htmlspecialchars($log['event_type']) ?></span>
            <span class="log-ip"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></span>
            <span class="log-detail"><?= htmlspecialchars(substr($log['details'] ?? '', 0, 60)) ?></span>
            <span class="log-time"><?= timeAgo($log['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recentLogs)): ?>
        <p class="empty-state">No security events yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php
function getEventIcon(string $type): string {
    return match($type) {
        'failed_login'    => '🔴',
        'successful_login'=> '🟢',
        'auto_ban'        => '🚫',
        'blocked_ip'      => '🛑',
        'blocked_country' => '🌍',
        default           => '📋',
    };
}
require_once __DIR__ . '/includes/layout_footer.php';
?>
