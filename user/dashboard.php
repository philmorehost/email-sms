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
$uid  = (int)($user['id'] ?? 0);

// ── Wallet balance ─────────────────────────────────────────────────────────
$walletBalance = 0.0;
try {
    $row = $db->prepare("SELECT credits FROM user_sms_wallet WHERE user_id = ?");
    $row->execute([$uid]);
    $r = $row->fetch();
    $walletBalance = $r ? (float)$r['credits'] : 0.0;
} catch (\Exception $e) {}

// ── SMS price per unit ─────────────────────────────────────────────────────
$smsUnitPrice = 6.50;
try {
    $pr = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sms_price_per_unit'")->fetch();
    if ($pr) $smsUnitPrice = (float)$pr['setting_value'];
} catch (\Exception $e) {}

// ── SMS monthly usage ──────────────────────────────────────────────────────
$smsMonthlyDebit = 0.0;
$smsPagesThisMonth = 0;
try {
    $mStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM sms_credit_transactions
         WHERE user_id = ? AND type = 'debit'
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $mStmt->execute([$uid]);
    $smsMonthlyDebit  = (float)($mStmt->fetchColumn() ?: 0.0);
    $smsPagesThisMonth = $smsUnitPrice > 0 ? (int)round($smsMonthlyDebit / $smsUnitPrice) : 0;
} catch (\Exception $e) {}

// ── Email subscription ─────────────────────────────────────────────────────
$mySubscription = null;
try {
    $subStmt = $db->prepare(
        "SELECT s.*, ep.name AS plan_name, ep.monthly_email_limit, ep.price AS plan_price
         FROM user_subscriptions s
         JOIN email_plans ep ON ep.id = s.plan_id
         WHERE s.user_id = ? AND s.status = 'active'
         LIMIT 1"
    );
    $subStmt->execute([$uid]);
    $mySubscription = $subStmt->fetch() ?: null;
} catch (\Exception $e) {}

// ── Email campaigns sent this month ───────────────────────────────────────
$emailsUsedThisMonth = 0;
try {
    $euStmt = $db->prepare(
        "SELECT COALESCE(SUM(sent_count),0) FROM email_campaigns
         WHERE created_by = ?
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $euStmt->execute([$uid]);
    $emailsUsedThisMonth = (int)($euStmt->fetchColumn() ?: 0);
    // Keep user_subscriptions in sync
    if ($mySubscription) {
        $db->prepare("UPDATE user_subscriptions SET emails_used = ? WHERE user_id = ? AND status = 'active'")
           ->execute([$emailsUsedThisMonth, $uid]);
        $mySubscription['emails_used'] = $emailsUsedThisMonth;
    }
} catch (\Exception $e) {}

// ── Campaign stats ─────────────────────────────────────────────────────────
$stats = ['campaigns' => 0, 'sent' => 0, 'email_campaigns' => 0, 'pending_req' => 0];
try {
    $s = $db->prepare("SELECT COUNT(*) FROM sms_campaigns WHERE created_by = ?");
    $s->execute([$uid]);
    $stats['campaigns'] = (int)$s->fetchColumn();

    $s2 = $db->prepare("SELECT COALESCE(SUM(sent_count),0) FROM sms_campaigns WHERE created_by = ?");
    $s2->execute([$uid]);
    $stats['sent'] = (int)$s2->fetchColumn();

    $s3 = $db->prepare("SELECT COUNT(*) FROM sms_purchase_requests WHERE user_id = ? AND status = 'pending'");
    $s3->execute([$uid]);
    $stats['pending_req'] = (int)$s3->fetchColumn();

    $s4 = $db->prepare("SELECT COUNT(*) FROM email_campaigns WHERE created_by = ?");
    $s4->execute([$uid]);
    $stats['email_campaigns'] = (int)$s4->fetchColumn();
} catch (\Exception $e) {}

// ── Recent SMS transactions ────────────────────────────────────────────────
$recentTx = [];
try {
    $txS = $db->prepare("SELECT * FROM sms_credit_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $txS->execute([$uid]);
    $recentTx = $txS->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'My Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.wallet-banner {
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    border-radius: 20px;
    padding: 2rem 2.5rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}
.wallet-banner::after {
    content: '₦';
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 8rem;
    font-weight: 900;
    opacity: .08;
    line-height: 1;
}
.wallet-info strong { display: block; font-size: 2.5rem; font-weight: 900; }
.wallet-info span   { font-size: .9rem; opacity: .85; }
.wallet-ctas { display: flex; gap: .75rem; flex-wrap: wrap; }
.wallet-ctas a {
    background: rgba(255,255,255,.2);
    color: #fff;
    border: 1px solid rgba(255,255,255,.3);
    padding: .6rem 1.4rem;
    border-radius: 50px;
    font-size: .88rem;
    font-weight: 600;
    transition: all .2s;
}
.wallet-ctas a:hover { background: rgba(255,255,255,.35); color: #fff; }
.wallet-ctas a.primary { background: #fff; color: #6c63ff; }
.wallet-ctas a.primary:hover { background: rgba(255,255,255,.9); }
</style>

<div class="page-header">
    <h1>👋 Welcome back, <?= htmlspecialchars($user['username'] ?? '') ?></h1>
    <p>Here's your account overview.</p>
</div>

<div class="page-header">
    <h1>👋 Welcome back, <?= htmlspecialchars($user['username'] ?? '') ?></h1>
    <p>Here's your account overview for <?= date('F Y') ?>.</p>
</div>

<!-- Dual balance banners -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.75rem">
    <!-- SMS Wallet -->
    <div class="wallet-banner">
        <div class="wallet-info">
            <span>📱 SMS Wallet Balance</span>
            <strong>₦<?= number_format($walletBalance, 2) ?></strong>
            <?php $smsPages = ($smsUnitPrice > 0) ? (int)floor($walletBalance / $smsUnitPrice) : 0; ?>
            <small style="opacity:.7;font-size:.8rem">≈ <?= number_format($smsPages) ?> pages · ₦<?= number_format($smsUnitPrice, 2) ?>/page</small>
            <small style="opacity:.6;font-size:.75rem;display:block;margin-top:.15rem">This month: <?= number_format($smsPagesThisMonth) ?> pages used (₦<?= number_format($smsMonthlyDebit, 2) ?>)</small>
        </div>
        <div class="wallet-ctas">
            <a href="/billing.php" class="primary">+ Buy Credits</a>
            <a href="/user/sms.php">📤 Send SMS</a>
        </div>
    </div>
    <!-- Email Plan -->
    <?php if ($mySubscription):
        $emailLimit = (int)$mySubscription['monthly_email_limit'];
        $emailUsed  = (int)$mySubscription['emails_used'];
        $emailLeft  = max(0, $emailLimit - $emailUsed);
        $pct        = $emailLimit > 0 ? min(100, (int)round($emailUsed / $emailLimit * 100)) : 0;
        $barColor   = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
    ?>
    <div class="wallet-banner" style="background:linear-gradient(135deg,#10b981,#06b6d4)">
        <div class="wallet-info" style="flex:1">
            <span>📧 Email Plan — <?= htmlspecialchars($mySubscription['plan_name']) ?></span>
            <strong><?= number_format($emailLeft) ?></strong>
            <small style="opacity:.7;font-size:.8rem">emails remaining this period</small>
            <small style="opacity:.6;font-size:.75rem;display:block;margin-top:.15rem"><?= number_format($emailUsed) ?> / <?= number_format($emailLimit) ?> used (<?= $pct ?>%)</small>
            <div style="background:rgba(255,255,255,.25);border-radius:50px;height:6px;margin-top:.5rem;overflow:hidden">
                <div style="background:#fff;opacity:.9;height:100%;width:<?= $pct ?>%;border-radius:50px"></div>
            </div>
        </div>
        <div class="wallet-ctas">
            <a href="/billing.php?tab=email_plans">📊 Usage</a>
        </div>
    </div>
    <?php else: ?>
    <div class="wallet-banner" style="background:linear-gradient(135deg,#475569,#334155)">
        <div class="wallet-info">
            <span>📧 Email Plan</span>
            <strong style="font-size:1.5rem">Not Subscribed</strong>
            <small style="opacity:.7;font-size:.8rem">Subscribe to start sending email campaigns</small>
        </div>
        <div class="wallet-ctas">
            <a href="/billing.php?tab=email_plans" class="primary">📧 View Plans</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Grid -->
<div class="stats-grid" style="margin-bottom:2rem">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#6c63ff,#00d4ff)">💬</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['campaigns']) ?></div>
            <div class="stat-label">SMS Campaigns</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#00d4ff,#00ff88)">📤</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['sent']) ?></div>
            <div class="stat-label">SMS Messages Sent</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#10b981,#06b6d4)">📧</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['email_campaigns']) ?></div>
            <div class="stat-label">Email Campaigns</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ffa502,#ff6b35)">💳</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($smsPages) ?></div>
            <div class="stat-label">SMS Pages Remaining</div>
        </div>
    </div>
    <?php if ($mySubscription): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#10b981,#34d399)">✉️</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($emailLeft) ?></div>
            <div class="stat-label">Emails Left This Period</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($stats['pending_req'] > 0): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ff4757,#c0392b)">⏳</div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending_req'] ?></div>
            <div class="stat-label">Pending Credit Requests</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Monthly Balance Summary -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.75rem">
    <!-- SMS this month -->
    <div class="card">
        <div class="card-header"><h3>📱 SMS — This Month</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center">
                <div>
                    <div style="font-size:1.8rem;font-weight:800;color:#6c63ff"><?= number_format($smsPages) ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Pages Remaining</div>
                </div>
                <div>
                    <div style="font-size:1.8rem;font-weight:800;color:#f59e0b"><?= number_format($smsPagesThisMonth) ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Pages Used This Month</div>
                </div>
            </div>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border-color,rgba(255,255,255,.1));font-size:.85rem;color:var(--text-muted)">
                Wallet: <strong>₦<?= number_format($walletBalance, 2) ?></strong>
                &nbsp;·&nbsp; ₦<?= number_format($smsUnitPrice, 2) ?>/page
                &nbsp;·&nbsp; <a href="/billing.php">Buy more</a>
            </div>
        </div>
    </div>
    <!-- Email this month -->
    <div class="card">
        <div class="card-header"><h3>📧 Email — This Month</h3></div>
        <div class="card-body">
            <?php if ($mySubscription): ?>
            <?php
            $emailLimit = (int)$mySubscription['monthly_email_limit'];
            $emailUsed  = $emailsUsedThisMonth;
            $emailLeft  = max(0, $emailLimit - $emailUsed);
            $pct        = $emailLimit > 0 ? min(100, round($emailUsed / $emailLimit * 100, 1)) : 0;
            $barColor   = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center">
                <div>
                    <div style="font-size:1.8rem;font-weight:800;color:#10b981"><?= number_format($emailLeft) ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Emails Remaining</div>
                </div>
                <div>
                    <div style="font-size:1.8rem;font-weight:800;color:#f59e0b"><?= number_format($emailUsed) ?></div>
                    <div style="font-size:.8rem;color:var(--text-muted)">Emails Used</div>
                </div>
            </div>
            <div style="margin-top:.75rem">
                <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--text-muted);margin-bottom:.3rem">
                    <span><?= $pct ?>% of <?= number_format($emailLimit) ?>/mo limit used</span>
                    <span>Plan: <?= htmlspecialchars($mySubscription['plan_name']) ?></span>
                </div>
                <div style="background:rgba(255,255,255,.08);border-radius:50px;height:8px;overflow:hidden">
                    <div style="background:<?= $barColor ?>;height:100%;width:<?= $pct ?>%;border-radius:50px"></div>
                </div>
            </div>
            <div style="margin-top:.75rem;font-size:.82rem;color:var(--text-muted)">
                <a href="/billing.php?tab=email_plans">Manage Subscription</a>
                <?php if ($mySubscription['expires_at']): ?>
                &nbsp;·&nbsp; Renews: <?= htmlspecialchars(date('M j, Y', strtotime($mySubscription['expires_at']))) ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:1.5rem 1rem;color:var(--text-muted)">
                <p>No active email plan.</p>
                <a href="/billing.php?tab=email_plans" class="btn btn-primary btn-sm">Subscribe Now</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header"><h3>⚡ Quick Actions</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <a href="/user/sms.php" class="btn btn-primary" style="text-align:center">📤 New SMS Campaign</a>
            <a href="/billing.php?tab=email_plans" class="btn btn-primary" style="text-align:center;background:linear-gradient(135deg,#10b981,#06b6d4);border:none">📧 Email Plans</a>
            <a href="/billing.php" class="btn btn-secondary" style="text-align:center">💳 Buy SMS Credits</a>
            <a href="/billing.php?tab=transactions" class="btn btn-secondary" style="text-align:center">📊 Transactions</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>📋 Recent Transactions</h3>
            <a href="/billing.php?tab=transactions" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <?php if (empty($recentTx)): ?>
        <p class="empty-state" style="padding:1.5rem">No transactions yet. <a href="/billing.php">Buy credits</a> to get started.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Type</th><th>Amount</th><th>Description</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentTx as $tx): ?>
            <tr>
                <td>
                    <?php if ($tx['type'] === 'credit'): ?>
                    <span class="badge badge-success">➕ Credit</span>
                    <?php else: ?>
                    <span class="badge badge-danger">➖ Debit</span>
                    <?php endif; ?>
                </td>
                <td style="color:<?= $tx['type'] === 'credit' ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600">
                    <?= $tx['type'] === 'credit' ? '+' : '−' ?>₦<?= number_format((float)$tx['amount'], 2) ?>
                </td>
                <td><?= htmlspecialchars($tx['description'] ?? '') ?></td>
                <td><?= timeAgo($tx['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- SMS Page Pricing Info -->
<div class="card" style="margin-top:1.5rem">
    <div class="card-header"><h3>📐 SMS Pricing Reference</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem">
            <?php for ($pages = 1; $pages <= 5; $pages++):
                $chars_min = $pages === 1 ? 1 : (($pages - 1) * 153 + 1);
                $chars_max = $pages === 1 ? 160 : ($pages * 153);
            ?>
            <div style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.2);border-radius:12px;padding:1rem;text-align:center">
                <strong style="display:block;font-size:1.25rem;color:#6c63ff"><?= $pages ?> page<?= $pages > 1 ? 's' : '' ?></strong>
                <span style="font-size:.8rem;color:#a0a0b0"><?= $chars_min ?>–<?= $chars_max ?> chars</span>
                <strong style="display:block;margin-top:.25rem">₦<?= number_format($smsUnitPrice * $pages, 2) ?>/recipient</strong>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
