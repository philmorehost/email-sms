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

// ── My campaign stats ──────────────────────────────────────────────────────
$stats = ['campaigns' => 0, 'sent' => 0, 'pending_req' => 0];
try {
    $stats['campaigns']   = (int)$db->prepare("SELECT COUNT(*) FROM sms_campaigns WHERE created_by = ?")->execute([$uid]) ? (int)$db->query("SELECT COUNT(*) FROM sms_campaigns")->fetchColumn() : 0;
    $s = $db->prepare("SELECT COUNT(*) FROM sms_campaigns WHERE created_by = ?");
    $s->execute([$uid]);
    $stats['campaigns'] = (int)$s->fetchColumn();
    $s2 = $db->prepare("SELECT COALESCE(SUM(sent_count),0) FROM sms_campaigns WHERE created_by = ?");
    $s2->execute([$uid]);
    $stats['sent'] = (int)$s2->fetchColumn();
    $s3 = $db->prepare("SELECT COUNT(*) FROM sms_purchase_requests WHERE user_id = ? AND status = 'pending'");
    $s3->execute([$uid]);
    $stats['pending_req'] = (int)$s3->fetchColumn();
} catch (\Exception $e) {}

// Recent transactions
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

<!-- Wallet Banner -->
<div class="wallet-banner">
    <div class="wallet-info">
        <span>SMS Wallet Balance</span>
        <strong>₦<?= number_format($walletBalance, 2) ?></strong>
        <small style="opacity:.7;font-size:.8rem">₦<?= number_format($smsUnitPrice, 2) ?>/page · 1 page = up to 160 chars</small>
    </div>
    <div class="wallet-ctas">
        <a href="/billing.php" class="primary">+ Buy Credits</a>
        <a href="/user/sms.php">📤 Send SMS</a>
        <a href="/billing.php?tab=transactions">📊 Transactions</a>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid" style="margin-bottom:2rem">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#6c63ff,#00d4ff)">💬</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['campaigns']) ?></div>
            <div class="stat-label">My SMS Campaigns</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#00d4ff,#00ff88)">📤</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['sent']) ?></div>
            <div class="stat-label">Messages Sent</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#ffa502,#ff6b35)">💳</div>
        <div class="stat-info">
            <div class="stat-value">₦<?= number_format($walletBalance, 2) ?></div>
            <div class="stat-label">Wallet Balance</div>
        </div>
    </div>
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

<!-- Quick Actions -->
<div class="dashboard-grid">
    <div class="card">
        <div class="card-header"><h3>⚡ Quick Actions</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <a href="/user/sms.php" class="btn btn-primary" style="text-align:center">📤 New SMS Campaign</a>
            <a href="/billing.php" class="btn btn-secondary" style="text-align:center">💳 Buy Credits</a>
            <a href="/billing.php?tab=requests" class="btn btn-secondary" style="text-align:center">📋 My Requests</a>
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
