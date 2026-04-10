<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

// ── Migration: ensure required tables/columns exist ───────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS sms_purchase_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        package_id INT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        notes VARCHAR(255),
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT NULL,
        INDEX idx_user_req (user_id),
        INDEX idx_status_req (status)
    )");
} catch (\Exception $e) {}

// ── Flash helpers ─────────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['billing_flash_msg']  = $msg;
    $_SESSION['billing_flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['billing_flash_msg']  ?? '';
    $type = $_SESSION['billing_flash_type'] ?? 'success';
    unset($_SESSION['billing_flash_msg'], $_SESSION['billing_flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

// ── POST: submit purchase request ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token. Please try again.', 'error');
        redirect('/billing.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'request_purchase') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        if ($packageId <= 0) {
            setFlash('Please select a valid package.', 'error');
        } else {
            try {
                $pkgStmt = $db->prepare("SELECT * FROM sms_credit_packages WHERE id = ? AND is_active = 1");
                $pkgStmt->execute([$packageId]);
                $pkg = $pkgStmt->fetch();
                if (!$pkg) {
                    setFlash('Package not found or inactive.', 'error');
                } else {
                    // Check if user already has a pending request for this package
                    $existStmt = $db->prepare("SELECT id FROM sms_purchase_requests WHERE user_id = ? AND package_id = ? AND status = 'pending'");
                    $existStmt->execute([$userId, $packageId]);
                    if ($existStmt->fetch()) {
                        setFlash('You already have a pending request for this package. Please wait for admin approval.', 'error');
                    } else {
                        $db->prepare("INSERT INTO sms_purchase_requests (user_id, package_id) VALUES (?, ?)")
                           ->execute([$userId, $packageId]);
                        setFlash('Purchase request submitted! An admin will credit your wallet shortly. You will see it reflected in your balance once approved.');
                    }
                }
            } catch (\Exception $e) {
                error_log('billing purchase_request error: ' . $e->getMessage());
                setFlash('Error submitting request. Please try again.', 'error');
            }
        }
        redirect('/billing.php');
    }
}

$flash     = popFlash();
$activeTab = $_GET['tab'] ?? 'wallet';

// ── Wallet balance ─────────────────────────────────────────────────────────────
$walletBalance = 0.0;
try {
    $wStmt = $db->prepare("SELECT credits FROM user_sms_wallet WHERE user_id = ?");
    $wStmt->execute([$userId]);
    $wRow = $wStmt->fetch();
    $walletBalance = $wRow ? (float)$wRow['credits'] : 0.0;
} catch (\Exception $e) {}

// ── SMS price per unit ─────────────────────────────────────────────────────────
$smsUnitPrice = 6.50;
try {
    $pRow = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sms_price_per_unit'")->fetch();
    if ($pRow) $smsUnitPrice = (float)$pRow['setting_value'];
} catch (\Exception $e) {}

// ── Available packages ─────────────────────────────────────────────────────────
$packages = [];
try {
    $packages = $db->query("SELECT * FROM sms_credit_packages WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
} catch (\Exception $e) {}

// ── My purchase requests ──────────────────────────────────────────────────────
$myRequests = [];
try {
    $rStmt = $db->prepare(
        "SELECT r.*, p.name AS package_name, p.credits, p.price, p.billing_period
         FROM sms_purchase_requests r
         LEFT JOIN sms_credit_packages p ON p.id = r.package_id
         WHERE r.user_id = ?
         ORDER BY r.requested_at DESC LIMIT 50"
    );
    $rStmt->execute([$userId]);
    $myRequests = $rStmt->fetchAll();
} catch (\Exception $e) {}

// ── Transaction history ────────────────────────────────────────────────────────
$transactions = [];
try {
    $tStmt = $db->prepare(
        "SELECT * FROM sms_credit_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 100"
    );
    $tStmt->execute([$userId]);
    $transactions = $tStmt->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'SMS Credits & Billing';
$activePage = 'billing';
require_once __DIR__ . '/includes/layout_header.php';
?>
<style>
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--glass-bg,rgba(255,255,255,0.05));color:var(--text-muted,#606070);cursor:pointer;border-radius:8px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--glass-border,rgba(255,255,255,0.1))}
.tab-btn.active{background:var(--accent,#6c63ff);color:#fff;border-color:var(--accent,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}

/* Wallet balance card */
.wallet-hero{background:linear-gradient(135deg,#6c63ff,#00d4ff);border-radius:20px;padding:2.5rem;margin-bottom:2rem;text-align:center;color:#fff;position:relative;overflow:hidden}
.wallet-hero::before{content:'';position:absolute;top:-40%;right:-10%;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.08)}
.wallet-amount{font-size:3.5rem;font-weight:800;letter-spacing:-.02em;line-height:1}
.wallet-label{font-size:.95rem;opacity:.85;margin-top:.5rem}
.wallet-subtext{font-size:.8rem;opacity:.65;margin-top:.35rem}

/* Package cards */
.packages-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.25rem;margin-bottom:2rem}
.pkg-card{background:var(--glass-bg,rgba(255,255,255,0.05));border:1px solid var(--glass-border,rgba(255,255,255,.1));border-radius:16px;padding:1.5rem;transition:transform .2s,box-shadow .2s;position:relative}
.pkg-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(108,99,255,.25)}
.pkg-card.popular{border-color:var(--accent,#6c63ff);box-shadow:0 0 0 2px rgba(108,99,255,.3)}
.pkg-popular-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#6c63ff,#00d4ff);color:#fff;font-size:.72rem;font-weight:700;letter-spacing:.06em;padding:.25rem .85rem;border-radius:50px;white-space:nowrap}
.pkg-period{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--accent,#6c63ff);margin-bottom:.75rem}
.pkg-name{font-size:1.1rem;font-weight:700;margin-bottom:.5rem}
.pkg-credits{font-size:2rem;font-weight:800;color:var(--accent,#6c63ff);line-height:1}
.pkg-credits-label{font-size:.8rem;color:var(--text-muted);margin-bottom:.75rem}
.pkg-price{font-size:1.3rem;font-weight:700;margin-bottom:1rem}
.pkg-price small{font-size:.8rem;font-weight:400;color:var(--text-muted)}

/* SMS calculation box */
.sms-calc{background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.2);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem}
.sms-calc h4{margin:0 0 .75rem;color:var(--text-primary)}
.sms-calc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;font-size:.88rem}
.sms-calc-item{background:rgba(255,255,255,.04);border-radius:8px;padding:.6rem .9rem}
.sms-calc-item strong{display:block;color:var(--text-primary);font-size:1rem}
.sms-calc-item small{color:var(--text-muted)}
</style>

<h1>💳 SMS Credits &amp; Billing</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Wallet hero -->
<div class="wallet-hero">
    <div class="wallet-amount">₦<?= number_format($walletBalance, 2) ?></div>
    <div class="wallet-label">Available SMS Wallet Balance</div>
    <div class="wallet-subtext">₦<?= number_format($smsUnitPrice, 2) ?> per SMS unit/page &nbsp;·&nbsp; 1 page = up to 160 chars</div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="/billing.php?tab=wallet"       class="tab-btn <?= $activeTab === 'wallet'       ? 'active' : '' ?>">💼 Buy Credits</a>
    <a href="/billing.php?tab=requests"     class="tab-btn <?= $activeTab === 'requests'     ? 'active' : '' ?>">📋 My Requests</a>
    <a href="/billing.php?tab=transactions" class="tab-btn <?= $activeTab === 'transactions' ? 'active' : '' ?>">📊 Transactions</a>
</div>

<!-- ── BUY CREDITS TAB ──────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'wallet' ? 'active' : '' ?>">

    <!-- SMS page pricing info -->
    <div class="sms-calc">
        <h4>📐 SMS Page Calculation</h4>
        <div class="sms-calc-grid">
            <div class="sms-calc-item">
                <strong>1 page</strong>
                <small>Up to 160 chars</small>
            </div>
            <div class="sms-calc-item">
                <strong>2 pages</strong>
                <small>161 – 306 chars</small>
            </div>
            <div class="sms-calc-item">
                <strong>3 pages</strong>
                <small>307 – 459 chars</small>
            </div>
            <div class="sms-calc-item">
                <strong>₦<?= number_format($smsUnitPrice, 2) ?>/page</strong>
                <small>Current unit price</small>
            </div>
            <div class="sms-calc-item">
                <strong>₦<?= number_format($smsUnitPrice * 2, 2) ?></strong>
                <small>2-page SMS cost</small>
            </div>
            <div class="sms-calc-item">
                <strong>₦<?= number_format($smsUnitPrice * 3, 2) ?></strong>
                <small>3-page SMS cost</small>
            </div>
        </div>
        <p style="font-size:.82rem;color:var(--text-muted);margin:.75rem 0 0">
            After 160 characters, each page = 153 characters. Debit = (number of pages) × (number of recipients) × (₦<?= number_format($smsUnitPrice, 2) ?>/page).
        </p>
    </div>

    <?php if (empty($packages)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:3rem;color:var(--text-muted)">
            <p>No SMS packages available at the moment. Please contact an administrator.</p>
        </div>
    </div>
    <?php else: ?>
    <p style="color:var(--text-muted);margin-bottom:1.25rem">
        Select a package below. Your request will be reviewed by an admin who will then credit your wallet.
    </p>
    <div class="packages-grid">
        <?php
        $popularIndex = count($packages) > 1 ? (int)floor(count($packages) / 2) : -1;
        $billingLabels = ['one_time' => 'One-Time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
        foreach ($packages as $idx => $pkg):
            $isPopular = ($idx === $popularIndex);
            $bLabel    = $billingLabels[$pkg['billing_period'] ?? 'one_time'] ?? 'One-Time';
        ?>
        <div class="pkg-card <?= $isPopular ? 'popular' : '' ?>">
            <?php if ($isPopular): ?>
            <div class="pkg-popular-badge">⭐ Most Popular</div>
            <?php endif; ?>
            <div class="pkg-period"><?= htmlspecialchars($bLabel) ?></div>
            <div class="pkg-name"><?= htmlspecialchars($pkg['name']) ?></div>
            <div class="pkg-credits"><?= number_format((int)$pkg['credits']) ?></div>
            <div class="pkg-credits-label">SMS Units / Credits</div>
            <div class="pkg-price">
                ₦<?= number_format((float)$pkg['price'], 2) ?>
                <?php if ($pkg['billing_period'] !== 'one_time'): ?>
                <small>/<?= strtolower(substr($bLabel, 0, 2)) ?></small>
                <?php endif; ?>
            </div>
            <form method="POST" action="/billing.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="request_purchase">
                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                <button type="submit" class="btn btn-primary" style="width:100%"
                        onclick="return confirm('Request ' + <?= (int)$pkg['credits'] ?> + ' credits for ₦' + '<?= number_format((float)$pkg['price'], 2) ?>' + '?')">
                    Request Purchase
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── MY REQUESTS TAB ────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'requests' ? 'active' : '' ?>">
    <?php if (empty($myRequests)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:3rem;color:var(--text-muted)">
            <p>No purchase requests yet. <a href="/billing.php?tab=wallet">Buy some credits</a>.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>Package</th><th>Credits</th><th>Price</th><th>Billing</th><th>Status</th><th>Requested</th><th>Processed</th></tr></thead>
        <tbody>
        <?php
        $billingLabels = ['one_time' => 'One-Time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
        foreach ($myRequests as $req):
        ?>
        <tr>
            <td><?= htmlspecialchars($req['package_name'] ?? '—') ?></td>
            <td><?= number_format((int)$req['credits']) ?></td>
            <td>₦<?= number_format((float)$req['price'], 2) ?></td>
            <td><?= htmlspecialchars($billingLabels[$req['billing_period'] ?? 'one_time'] ?? 'One-Time') ?></td>
            <td>
                <?php if ($req['status'] === 'pending'): ?>
                    <span class="badge badge-warning">⏳ Pending</span>
                <?php elseif ($req['status'] === 'approved'): ?>
                    <span class="badge badge-success">✅ Approved</span>
                <?php else: ?>
                    <span class="badge badge-danger">❌ Rejected</span>
                    <?php if (!empty($req['notes'])): ?>
                    <br><small style="color:var(--text-muted)"><?= htmlspecialchars($req['notes']) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td><?= timeAgo($req['requested_at']) ?></td>
            <td><?= $req['processed_at'] ? timeAgo($req['processed_at']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── TRANSACTIONS TAB ───────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'transactions' ? 'active' : '' ?>">
    <?php if (empty($transactions)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:3rem;color:var(--text-muted)">
            <p>No transactions yet. Credits will appear here once your wallet is topped up.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th><th>Reference</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
        <tr>
            <td><?= timeAgo($tx['created_at']) ?></td>
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
            <td><code style="font-size:.8rem"><?= htmlspecialchars($tx['reference'] ?? '') ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
