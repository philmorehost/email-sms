<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

setSecurityHeaders();
requireAuth();

$db     = getDB();
$user   = getCurrentUser();
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
    $db->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        plan_id INT NOT NULL,
        status ENUM('active','cancelled','expired') DEFAULT 'active',
        emails_used INT DEFAULT 0,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        UNIQUE KEY unique_user_sub (user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS email_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        monthly_email_limit INT NOT NULL DEFAULT 1000,
        features JSON,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

// ── POST handler ──────────────────────────────────────────────────────────────
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
                    $existStmt = $db->prepare("SELECT id FROM sms_purchase_requests WHERE user_id = ? AND package_id = ? AND status = 'pending'");
                    $existStmt->execute([$userId, $packageId]);
                    if ($existStmt->fetch()) {
                        setFlash('You already have a pending request for this package. Please wait for admin approval.', 'error');
                    } else {
                        $db->prepare("INSERT INTO sms_purchase_requests (user_id, package_id) VALUES (?, ?)")
                           ->execute([$userId, $packageId]);
                        setFlash('Purchase request submitted! An admin will credit your wallet shortly.');
                    }
                }
            } catch (\Exception $e) {
                error_log('billing purchase_request: ' . $e->getMessage());
                setFlash('Error submitting request. Please try again.', 'error');
            }
        }
        redirect('/billing.php?tab=wallet');
    }

    // ── Subscribe to email plan ───────────────────────────────────────────────
    if ($action === 'subscribe_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($planId <= 0) {
            setFlash('Please select a valid plan.', 'error');
        } else {
            try {
                $pStmt = $db->prepare("SELECT * FROM email_plans WHERE id = ? AND is_active = 1");
                $pStmt->execute([$planId]);
                $plan = $pStmt->fetch();
                if (!$plan) {
                    setFlash('Plan not found or inactive.', 'error');
                } else {
                    // billing_period for email plans is monthly; set expires_at to 30 days
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                    $db->prepare(
                        "INSERT INTO user_subscriptions (user_id, plan_id, status, emails_used, started_at, expires_at)
                         VALUES (?, ?, 'active', 0, NOW(), ?)
                         ON DUPLICATE KEY UPDATE plan_id=?, status='active', emails_used=0, started_at=NOW(), expires_at=?"
                    )->execute([$userId, $planId, $expires, $planId, $expires]);
                    setFlash('Successfully subscribed to "' . $plan['name'] . '"! Your email allowance resets in 30 days.');
                }
            } catch (\Exception $e) {
                error_log('billing subscribe_plan: ' . $e->getMessage());
                setFlash('Error subscribing. Please try again.', 'error');
            }
        }
        redirect('/billing.php?tab=email_plans');
    }

    // ── Cancel email subscription ─────────────────────────────────────────────
    if ($action === 'cancel_subscription') {
        try {
            $db->prepare("UPDATE user_subscriptions SET status='cancelled' WHERE user_id=?")->execute([$userId]);
            setFlash('Email subscription cancelled.');
        } catch (\Exception $e) {
            setFlash('Error cancelling subscription.', 'error');
        }
        redirect('/billing.php?tab=email_plans');
    }
}

$flash     = popFlash();
$activeTab = $_GET['tab'] ?? 'wallet';

// ── SMS Wallet ────────────────────────────────────────────────────────────────
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

// ── SMS monthly usage (current calendar month) ────────────────────────────────
$smsMonthlyDebit = 0.0;
$smsPagesThisMonth = 0;
try {
    $mStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0) AS total
         FROM sms_credit_transactions
         WHERE user_id = ? AND type = 'debit'
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $mStmt->execute([$userId]);
    $smsMonthlyDebit = (float)($mStmt->fetchColumn() ?: 0.0);
    $smsPagesThisMonth = $smsUnitPrice > 0 ? (int)round($smsMonthlyDebit / $smsUnitPrice) : 0;
} catch (\Exception $e) {}

// ── Available SMS packages ─────────────────────────────────────────────────────
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

// ── Email plans ───────────────────────────────────────────────────────────────
$emailPlans = [];
try {
    $emailPlans = $db->query("SELECT * FROM email_plans WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
} catch (\Exception $e) {}

// ── Current email subscription ────────────────────────────────────────────────
$mySubscription = null;
$myPlan         = null;
try {
    $subStmt = $db->prepare(
        "SELECT s.*, ep.name AS plan_name, ep.monthly_email_limit, ep.price AS plan_price,
                ep.features, ep.description
         FROM user_subscriptions s
         JOIN email_plans ep ON ep.id = s.plan_id
         WHERE s.user_id = ? AND s.status = 'active'
         LIMIT 1"
    );
    $subStmt->execute([$userId]);
    $mySubscription = $subStmt->fetch() ?: null;
    if ($mySubscription) {
        $myPlan = $mySubscription; // same join result
    }
} catch (\Exception $e) {}

// ── Email usage this month (emails sent by user's campaigns this month) ────────
$emailsUsedThisMonth = 0;
try {
    $euStmt = $db->prepare(
        "SELECT COALESCE(SUM(sent_count), 0)
         FROM email_campaigns
         WHERE created_by = ?
           AND YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())"
    );
    $euStmt->execute([$userId]);
    $emailsUsedThisMonth = (int)($euStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

// Also sync the emails_used in user_subscriptions from actual campaign data
if ($mySubscription) {
    try {
        $db->prepare("UPDATE user_subscriptions SET emails_used = ? WHERE user_id = ? AND status = 'active'")
           ->execute([$emailsUsedThisMonth, $userId]);
        $mySubscription['emails_used'] = $emailsUsedThisMonth;
    } catch (\Exception $e) {}
}

$pageTitle  = 'Credits & Subscriptions';
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

<h1>💳 Credits &amp; Subscriptions</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Dual balance hero -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.75rem">
    <div class="wallet-hero" style="text-align:left;padding:1.75rem 2rem">
        <div style="font-size:.8rem;opacity:.75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.25rem">📱 SMS Wallet Balance</div>
        <div class="wallet-amount">₦<?= number_format($walletBalance, 2) ?></div>
        <?php $smsPages = ($smsUnitPrice > 0) ? (int)floor($walletBalance / $smsUnitPrice) : 0; ?>
        <div class="wallet-subtext">≈ <?= number_format($smsPages) ?> SMS pages &nbsp;·&nbsp; ₦<?= number_format($smsUnitPrice, 2) ?>/page</div>
        <div class="wallet-subtext" style="margin-top:.4rem">Used this month: <?= number_format($smsPagesThisMonth) ?> pages (₦<?= number_format($smsMonthlyDebit, 2) ?>)</div>
    </div>
    <div class="wallet-hero" style="background:linear-gradient(135deg,#10b981,#06b6d4);text-align:left;padding:1.75rem 2rem">
        <div style="font-size:.8rem;opacity:.75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.25rem">📧 Email Plan</div>
        <?php if ($mySubscription): ?>
            <?php
            $emailLimit   = (int)$mySubscription['monthly_email_limit'];
            $emailUsed    = (int)$mySubscription['emails_used'];
            $emailLeft    = max(0, $emailLimit - $emailUsed);
            $pct          = $emailLimit > 0 ? min(100, (int)round($emailUsed / $emailLimit * 100)) : 0;
            ?>
            <div class="wallet-amount" style="font-size:2.5rem"><?= number_format($emailLeft) ?></div>
            <div class="wallet-subtext">emails remaining this period</div>
            <div class="wallet-subtext" style="margin-top:.4rem"><?= number_format($emailUsed) ?> / <?= number_format($emailLimit) ?> used (<?= $pct ?>%) &nbsp;·&nbsp; <?= htmlspecialchars($mySubscription['plan_name']) ?></div>
        <?php else: ?>
            <div class="wallet-amount" style="font-size:2.2rem">No Plan</div>
            <div class="wallet-subtext">Subscribe to an email plan to send campaigns</div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="/billing.php?tab=wallet"       class="tab-btn <?= $activeTab === 'wallet'       ? 'active' : '' ?>">💼 Buy SMS Credits</a>
    <a href="/billing.php?tab=email_plans"  class="tab-btn <?= $activeTab === 'email_plans'  ? 'active' : '' ?>">📧 Email Plans</a>
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

<!-- ── EMAIL PLANS TAB ────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'email_plans' ? 'active' : '' ?>">

    <!-- Current subscription status -->
    <?php if ($mySubscription): ?>
    <?php
    $emailLimit = (int)$mySubscription['monthly_email_limit'];
    $emailUsed  = (int)$mySubscription['emails_used'];
    $emailLeft  = max(0, $emailLimit - $emailUsed);
    $pct        = $emailLimit > 0 ? min(100, round($emailUsed / $emailLimit * 100, 1)) : 0;
    $barColor   = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
    ?>
    <div class="card" style="margin-bottom:1.75rem;border-color:rgba(16,185,129,.4)">
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem">
                <div>
                    <h3 style="margin:0 0 .25rem;color:var(--text-primary)">
                        ✅ Active: <strong><?= htmlspecialchars($mySubscription['plan_name']) ?></strong>
                    </h3>
                    <?php if (!empty($mySubscription['description'])): ?>
                    <p style="color:var(--text-muted);margin:.25rem 0 .75rem;font-size:.9rem"><?= htmlspecialchars($mySubscription['description']) ?></p>
                    <?php endif; ?>
                    <p style="margin:.25rem 0;color:var(--text-muted);font-size:.88rem">
                        Expires: <?= $mySubscription['expires_at'] ? htmlspecialchars(date('M j, Y', strtotime($mySubscription['expires_at']))) : 'No expiry' ?>
                        &nbsp;·&nbsp; Started: <?= htmlspecialchars(date('M j, Y', strtotime($mySubscription['started_at']))) ?>
                    </p>
                </div>
                <form method="POST" action="/billing.php" onsubmit="return confirm('Cancel your email subscription?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="cancel_subscription">
                    <button type="submit" class="btn btn-sm btn-danger">Cancel Subscription</button>
                </form>
            </div>
            <!-- Usage bar -->
            <div style="margin-top:1rem">
                <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.4rem">
                    <span style="color:var(--text-muted)">Emails used this period</span>
                    <strong style="color:<?= $barColor ?>"><?= number_format($emailUsed) ?> / <?= number_format($emailLimit) ?> (<?= $pct ?>%)</strong>
                </div>
                <div style="background:rgba(255,255,255,.08);border-radius:50px;height:10px;overflow:hidden">
                    <div style="background:<?= $barColor ?>;height:100%;width:<?= $pct ?>%;border-radius:50px;transition:width .4s"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-top:.35rem;color:var(--text-muted)">
                    <span><?= number_format($emailLeft) ?> emails remaining</span>
                    <span><?= number_format($emailLimit - $emailUsed > 0 ? $emailLimit - $emailUsed : 0) ?> left of <?= number_format($emailLimit) ?></span>
                </div>
            </div>
        </div>
    </div>
    <h3 style="margin-bottom:1rem">Change Plan</h3>
    <?php else: ?>
    <div class="card" style="margin-bottom:1.75rem;background:rgba(245,158,11,.06);border-color:rgba(245,158,11,.3)">
        <div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <span style="font-size:2rem">📭</span>
            <div>
                <strong style="display:block;margin-bottom:.2rem">No active email plan</strong>
                <span style="color:var(--text-muted);font-size:.9rem">Subscribe to a plan below to start sending email campaigns.</span>
            </div>
        </div>
    </div>
    <h3 style="margin-bottom:1rem">Choose an Email Plan</h3>
    <?php endif; ?>

    <?php if (empty($emailPlans)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:3rem;color:var(--text-muted)">
            <p>No email plans available yet. Please contact an administrator.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="packages-grid">
        <?php
        $popularIndex = count($emailPlans) > 1 ? (int)floor(count($emailPlans) / 2) : -1;
        foreach ($emailPlans as $idx => $ep):
            $isPopular     = ($idx === $popularIndex);
            $featuresArr   = json_decode($ep['features'] ?? '[]', true) ?: [];
            $isCurrentPlan = ($mySubscription && (int)$mySubscription['plan_id'] === (int)$ep['id']);
        ?>
        <div class="pkg-card <?= $isPopular ? 'popular' : '' ?>" <?= $isCurrentPlan ? 'style="border-color:#10b981;box-shadow:0 0 0 2px rgba(16,185,129,.3)"' : '' ?>>
            <?php if ($isCurrentPlan): ?>
            <div class="pkg-popular-badge" style="background:linear-gradient(135deg,#10b981,#06b6d4)">✅ Current Plan</div>
            <?php elseif ($isPopular): ?>
            <div class="pkg-popular-badge">⭐ Most Popular</div>
            <?php endif; ?>
            <div class="pkg-period">Monthly Email Plan</div>
            <div class="pkg-name"><?= htmlspecialchars($ep['name']) ?></div>
            <div class="pkg-credits"><?= number_format((int)$ep['monthly_email_limit']) ?></div>
            <div class="pkg-credits-label">Emails / Month</div>
            <div class="pkg-price">
                $<?= number_format((float)$ep['price'], 2) ?>
                <small>/month</small>
            </div>
            <?php if (!empty($featuresArr)): ?>
            <ul style="list-style:none;padding:0;margin:.75rem 0;font-size:.83rem;color:var(--text-muted)">
                <?php foreach ($featuresArr as $f): ?>
                <li style="margin-bottom:.3rem">✓ <?= htmlspecialchars($f) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if (!$isCurrentPlan): ?>
            <form method="POST" action="/billing.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="subscribe_plan">
                <input type="hidden" name="plan_id" value="<?= (int)$ep['id'] ?>">
                <button type="submit" class="btn btn-primary" style="width:100%"
                        onclick="return confirm('Subscribe to <?= htmlspecialchars(addslashes($ep['name'])) ?>?')">
                    <?= $mySubscription ? 'Switch to This Plan' : 'Subscribe' ?>
                </button>
            </form>
            <?php else: ?>
            <button class="btn btn-secondary" style="width:100%;cursor:default" disabled>Current Plan</button>
            <?php endif; ?>
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
