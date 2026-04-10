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

// CSV export must be before any session/HTML output
if (isset($_GET['export_tx'])) {
    $filterUser   = (int)($_GET['user_id'] ?? 0);
    $filterType   = $_GET['type'] ?? '';
    $filterFrom   = $_GET['date_from'] ?? '';
    $filterTo     = $_GET['date_to'] ?? '';

    $where  = ['1=1'];
    $params = [];
    if ($filterUser > 0)  { $where[] = 't.user_id=?'; $params[] = $filterUser; }
    if (in_array($filterType, ['credit','debit'], true)) { $where[] = 't.type=?'; $params[] = $filterType; }
    if ($filterFrom !== '') { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $filterFrom; }
    if ($filterTo !== '')   { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $filterTo; }

    $sql = 'SELECT t.id, u.username, t.type, t.amount, t.description, t.reference, t.created_at
            FROM sms_credit_transactions t
            LEFT JOIN users u ON u.id=t.user_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY t.created_at DESC';

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (\Exception $e) { $rows = []; }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'User', 'Type', 'Amount', 'Description', 'Reference', 'Date']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['id'], $row['username'], $row['type'], $row['amount'], $row['description'], $row['reference'], $row['created_at']]);
    }
    fclose($out);
    exit;
}

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
        redirect('/admin/billing.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_credits') {
        $userId     = (int)($_POST['user_id'] ?? 0);
        $packageId  = (int)($_POST['package_id'] ?? 0);
        $customAmt  = (float)($_POST['custom_amount'] ?? 0);
        $desc       = sanitize($_POST['description'] ?? 'Admin top-up');
        if (!$userId) { setFlash('Select a user.', 'error'); redirect('/admin/billing.php?tab=overview'); }
        $amount = 0.0;
        $ref    = '';
        if ($packageId > 0) {
            try {
                $pkg = $db->prepare('SELECT * FROM sms_credit_packages WHERE id=? AND is_active=1');
                $pkg->execute([$packageId]);
                $pkgRow = $pkg->fetch();
                if (!$pkgRow) { setFlash('Package not found.', 'error'); redirect('/admin/billing.php?tab=overview'); }
                $amount = (float)$pkgRow['credits'];
                $ref    = 'pkg_' . $pkgRow['id'];
            } catch (\Exception $e) { setFlash('Error loading package.', 'error'); redirect('/admin/billing.php?tab=overview'); }
        } else {
            $amount = $customAmt;
            $ref    = 'manual_' . time();
        }
        if ($amount <= 0) { setFlash('Amount must be positive.', 'error'); redirect('/admin/billing.php?tab=overview'); }
        try {
            $db->prepare('INSERT INTO user_sms_wallet (user_id,credits) VALUES(?,?) ON DUPLICATE KEY UPDATE credits=credits+?, updated_at=NOW()')
               ->execute([$userId, $amount, $amount]);
            $db->prepare('INSERT INTO sms_credit_transactions (user_id,amount,type,description,reference) VALUES(?,?,\'credit\',?,?)')
               ->execute([$userId, $amount, $desc, $ref]);
            setFlash("Added {$amount} credits.");
        } catch (\Exception $e) { setFlash('Error adding credits.', 'error'); }
        redirect('/admin/billing.php?tab=overview');
    }

    if ($action === 'cancel_subscription') {
        $subId = (int)($_POST['sub_id'] ?? 0);
        try {
            $db->prepare('UPDATE user_subscriptions SET status=\'cancelled\' WHERE id=?')->execute([$subId]);
            setFlash('Subscription cancelled.');
        } catch (\Exception $e) { setFlash('Error cancelling subscription.', 'error'); }
        redirect('/admin/billing.php?tab=subscriptions');
    }

    if ($action === 'assign_subscription') {
        $userId    = (int)($_POST['user_id'] ?? 0);
        $planId    = (int)($_POST['plan_id'] ?? 0);
        $expiresAt = sanitize($_POST['expires_at'] ?? '');
        if (!$userId || !$planId) { setFlash('User and plan required.', 'error'); redirect('/admin/billing.php?tab=subscriptions'); }
        $expVal = $expiresAt !== '' ? $expiresAt : null;
        try {
            $db->prepare('INSERT INTO user_subscriptions (user_id,plan_id,status,expires_at) VALUES(?,?,\'active\',?) ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),status=\'active\',expires_at=VALUES(expires_at),emails_used=0')
               ->execute([$userId, $planId, $expVal]);
            setFlash('Subscription assigned.');
        } catch (\Exception $e) { setFlash('Error assigning subscription.', 'error'); }
        redirect('/admin/billing.php?tab=subscriptions');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/billing.php');
}

$flash     = popFlash();
$activeTab = $_GET['tab'] ?? 'overview';

// Overview stats
$totalCreditsIssued   = 0;
$activeSubsCount      = 0;
try {
    $row = $db->query("SELECT COALESCE(SUM(amount),0) AS total FROM sms_credit_transactions WHERE type='credit'")->fetch();
    $totalCreditsIssued = (float)$row['total'];
    $activeSubsCount = (int)$db->query("SELECT COUNT(*) FROM user_subscriptions WHERE status='active'")->fetchColumn();
} catch (\Exception $e) {}

$users = [];
try {
    $users = $db->query('SELECT id, username FROM users ORDER BY username')->fetchAll();
} catch (\Exception $e) {}

$activePackages = [];
try {
    $activePackages = $db->query('SELECT id, name, credits, price FROM sms_credit_packages WHERE is_active=1 ORDER BY price')->fetchAll();
} catch (\Exception $e) {}

$emailPlans = [];
try {
    $emailPlans = $db->query('SELECT id, name FROM email_plans WHERE is_active=1 ORDER BY name')->fetchAll();
} catch (\Exception $e) {}

// Transactions tab filters
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterType = $_GET['type'] ?? '';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo   = $_GET['date_to'] ?? '';

$transactions = [];
if ($activeTab === 'transactions') {
    try {
        $where  = ['1=1'];
        $params = [];
        if ($filterUser > 0) { $where[] = 't.user_id=?'; $params[] = $filterUser; }
        if (in_array($filterType, ['credit','debit'], true)) { $where[] = 't.type=?'; $params[] = $filterType; }
        if ($filterFrom !== '') { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $filterFrom; }
        if ($filterTo !== '')   { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $filterTo; }
        $sql = 'SELECT t.*, u.username FROM sms_credit_transactions t LEFT JOIN users u ON u.id=t.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.created_at DESC LIMIT 200';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();
    } catch (\Exception $e) {}
}

$subscriptions = [];
if ($activeTab === 'subscriptions') {
    try {
        $subscriptions = $db->query("SELECT s.*, u.username, p.name AS plan_name, p.monthly_email_limit FROM user_subscriptions s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN email_plans p ON p.id=s.plan_id ORDER BY s.started_at DESC")->fetchAll();
    } catch (\Exception $e) {}
}

$pageTitle  = 'Billing & Credits';
$activePage = 'billing';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--card-bg,#1e293b);color:var(--text-muted,#94a3b8);cursor:pointer;border-radius:6px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--border-color,#334155)}
.tab-btn.active{background:var(--primary,#6c63ff);color:#fff;border-color:var(--primary,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:1rem;text-align:center}
.stat-val{font-size:1.8rem;font-weight:700;color:var(--primary,#6c63ff)}
.stat-label{font-size:.85rem;color:var(--text-muted,#94a3b8)}
.progress-bar{background:var(--border-color,#334155);border-radius:3px;height:8px;overflow:hidden;margin-top:4px}
.progress-fill{background:var(--primary,#6c63ff);height:8px;border-radius:3px}
</style>

<h1>Billing &amp; Credits</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="tabs">
    <a href="/admin/billing.php?tab=overview"       class="tab-btn <?= $activeTab === 'overview'       ? 'active' : '' ?>">Overview</a>
    <a href="/admin/billing.php?tab=transactions"   class="tab-btn <?= $activeTab === 'transactions'   ? 'active' : '' ?>">Transactions</a>
    <a href="/admin/billing.php?tab=subscriptions"  class="tab-btn <?= $activeTab === 'subscriptions'  ? 'active' : '' ?>">Subscriptions</a>
    <a href="/admin/plans.php?tab=purchase_requests" class="tab-btn">Purchase Requests</a>
</div>

<!-- OVERVIEW TAB -->
<div class="tab-pane <?= $activeTab === 'overview' ? 'active' : '' ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-val"><?= number_format($totalCreditsIssued, 0) ?></div><div class="stat-label">Credits Issued</div></div>
        <div class="stat-card"><div class="stat-val"><?= $activeSubsCount ?></div><div class="stat-label">Active Subscriptions</div></div>
    </div>

    <div class="card">
        <div class="card-body">
            <h3 style="margin-top:0">Top Up Credits</h3>
            <form method="POST" action="/admin/billing.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_credits">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div class="form-group">
                        <label>User <span style="color:red">*</span></label>
                        <select name="user_id" class="form-control" required>
                            <option value="">— Select User —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Package</label>
                        <select name="package_id" class="form-control" id="packageSelect" onchange="toggleCustomAmount(this.value)">
                            <option value="0">Custom Amount</option>
                            <?php foreach ($activePackages as $pkg): ?>
                            <option value="<?= (int)$pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> — <?= number_format((int)$pkg['credits']) ?> credits (₦<?= number_format((float)$pkg['price'],2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="customAmountWrap">
                        <label>Custom Amount (credits)</label>
                        <input type="number" name="custom_amount" class="form-control" min="1" step="1" value="100">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" value="Admin top-up">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Credits</button>
            </form>
        </div>
    </div>
</div>

<!-- TRANSACTIONS TAB -->
<div class="tab-pane <?= $activeTab === 'transactions' ? 'active' : '' ?>">
    <form method="GET" action="/admin/billing.php" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:flex-end">
        <input type="hidden" name="tab" value="transactions">
        <div class="form-group" style="margin:0">
            <label style="font-size:.85rem">User</label>
            <select name="user_id" class="form-control">
                <option value="0">All Users</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.85rem">Type</label>
            <select name="type" class="form-control">
                <option value="">All</option>
                <option value="credit" <?= $filterType === 'credit' ? 'selected' : '' ?>>Credit</option>
                <option value="debit"  <?= $filterType === 'debit'  ? 'selected' : '' ?>>Debit</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.85rem">From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterFrom) ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:.85rem">To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterTo) ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <a href="/admin/billing.php?tab=transactions&export_tx=1&user_id=<?= $filterUser ?>&type=<?= urlencode($filterType) ?>&date_from=<?= urlencode($filterFrom) ?>&date_to=<?= urlencode($filterTo) ?>" class="btn btn-secondary">Export CSV</a>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Description</th><th>Reference</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
        <tr>
            <td><?= (int)$tx['id'] ?></td>
            <td><?= htmlspecialchars($tx['username'] ?? '') ?></td>
            <td>
                <?php if ($tx['type'] === 'credit'): ?>
                    <span class="badge badge-success">Credit</span>
                <?php else: ?>
                    <span class="badge badge-danger">Debit</span>
                <?php endif; ?>
            </td>
            <td><?= number_format((float)$tx['amount'], 2) ?></td>
            <td><?= htmlspecialchars($tx['description'] ?? '') ?></td>
            <td><?= htmlspecialchars($tx['reference'] ?? '') ?></td>
            <td><?= htmlspecialchars($tx['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($transactions)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">No transactions found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- SUBSCRIPTIONS TAB -->
<div class="tab-pane <?= $activeTab === 'subscriptions' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-body">
            <h3 style="margin-top:0">Assign Subscription</h3>
            <form method="POST" action="/admin/billing.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="assign_subscription">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                    <div class="form-group">
                        <label>User <span style="color:red">*</span></label>
                        <select name="user_id" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Plan <span style="color:red">*</span></label>
                        <select name="plan_id" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach ($emailPlans as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expires At</label>
                        <input type="date" name="expires_at" class="form-control">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Assign Subscription</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>User</th><th>Plan</th><th>Usage</th><th>Status</th><th>Started</th><th>Expires</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($subscriptions as $sub): ?>
        <?php
            $limit   = (int)($sub['monthly_email_limit'] ?? 0);
            $used    = (int)($sub['emails_used'] ?? 0);
            $pct     = $limit > 0 ? min(100, round(($used / $limit) * 100)) : 0;
        ?>
        <tr>
            <td><?= htmlspecialchars($sub['username'] ?? '') ?></td>
            <td><?= htmlspecialchars($sub['plan_name'] ?? '') ?></td>
            <td>
                <?= number_format($used) ?> / <?= number_format($limit) ?>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td>
                <?php if ($sub['status'] === 'active'): ?>
                    <span class="badge badge-success">Active</span>
                <?php elseif ($sub['status'] === 'cancelled'): ?>
                    <span class="badge badge-danger">Cancelled</span>
                <?php else: ?>
                    <span class="badge badge-secondary"><?= htmlspecialchars($sub['status']) ?></span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($sub['started_at']) ?></td>
            <td><?= $sub['expires_at'] ? htmlspecialchars($sub['expires_at']) : '—' ?></td>
            <td>
                <?php if ($sub['status'] === 'active'): ?>
                <form method="POST" action="/admin/billing.php" style="display:inline" onsubmit="return confirm('Cancel subscription?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="cancel_subscription">
                    <input type="hidden" name="sub_id" value="<?= (int)$sub['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($subscriptions)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">No subscriptions found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function toggleCustomAmount(val) {
    document.getElementById('customAmountWrap').style.display = (val === '0') ? '' : 'none';
}
toggleCustomAmount(document.getElementById('packageSelect').value);
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
