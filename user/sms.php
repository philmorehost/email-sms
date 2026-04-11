<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/sms.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);

// Migration: ensure created_by column exists
try {
    $cols = $db->query("SHOW COLUMNS FROM sms_campaigns LIKE 'created_by'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE sms_campaigns ADD COLUMN created_by INT NULL");
    }
} catch (\Exception $e) {}

// Migration: ensure user_id column exists on sms_sender_ids
try {
    $cols2 = $db->query("SHOW COLUMNS FROM sms_sender_ids LIKE 'user_id'")->fetchAll();
    if (empty($cols2)) {
        $db->exec("ALTER TABLE sms_sender_ids ADD COLUMN user_id INT NULL AFTER id, ADD INDEX idx_sid_user (user_id)");
    }
} catch (\Exception $e) {}

// ── Helpers (same as admin/sms.php) ──────────────────────────────────────────
function calculateSmsPages2(string $message): int {
    $len = mb_strlen($message);
    if ($len <= 0) return 0;
    if ($len <= 160) return 1;
    return (int)ceil($len / 153);
}
function getSmsPrice2(PDO $db): float {
    try {
        $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='sms_price_per_unit'");
        $s->execute(); $r = $s->fetch();
        return $r ? (float)$r['setting_value'] : 0.0;
    } catch (\Exception $e) { return 0.0; }
}
function getWallet2(PDO $db, int $uid): float {
    try {
        $s = $db->prepare("SELECT credits FROM user_sms_wallet WHERE user_id=?");
        $s->execute([$uid]); $r = $s->fetch();
        return $r ? (float)$r['credits'] : 0.0;
    } catch (\Exception $e) { return 0.0; }
}

$msg     = '';
$msgType = 'success';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $msg = 'Invalid security token.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'send_sms') {
            $phones  = array_values(array_unique(array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')))));
            $message = trim($_POST['message'] ?? '');
            $sender  = sanitize($_POST['sender_id'] ?? '');
            $route   = 'bulk';
            if (!in_array($route, ['bulk'], true)) $route = 'bulk';

            if (empty($phones)) {
                $msg = 'Please enter at least one phone number.'; $msgType = 'error';
            } elseif ($message === '') {
                $msg = 'Message cannot be empty.'; $msgType = 'error';
            } else {
                $unitPrice   = getSmsPrice2($db);
                $pages       = calculateSmsPages2($message);
                $totalCost   = round($pages * count($phones) * $unitPrice, 2);
                $balance     = getWallet2($db, $uid);

                if ($totalCost > 0 && $balance < $totalCost) {
                    $sym = currencySymbol();
                    $msg = sprintf(
                        'Insufficient balance. Need %s%.2f (%d page%s × %d recipient%s × %s%.2f). Balance: %s%.2f. <a href="/billing.php">Buy credits</a>.',
                        $sym, $totalCost, $pages, $pages > 1 ? 's' : '', count($phones), count($phones) > 1 ? 's' : '', $sym, $unitPrice, $sym, $balance
                    );
                    $msgType = 'error';
                } else {
                    try {
                        // Save campaign
                        $ins = $db->prepare(
                            "INSERT INTO sms_campaigns (name, sender_id, message, route, status, total_recipients, created_at, created_by)
                             VALUES (?, ?, ?, ?, 'sending', ?, NOW(), ?)"
                        );
                        $ins->execute(['Quick Send — ' . date('d/m/Y H:i'), $sender, $message, $route, count($phones), $uid]);
                        $cid = (int)$db->lastInsertId();

                        $sms = PhilmoreSMS::fromDB();
                        if ($sms === null) {
                            $db->prepare("UPDATE sms_campaigns SET status='failed' WHERE id=?")->execute([$cid]);
                            $msg = 'SMS API not configured. Contact your administrator.'; $msgType = 'error';
                        } else {
                            $recipStr = implode(',', $phones);
                            $result   = $sms->sendBulkSMS($sender, $recipStr, $message);

                            if (!empty($result['success'])) {
                                $db->prepare("UPDATE sms_campaigns SET status='sent', sent_count=?, sent_at=NOW() WHERE id=?")->execute([count($phones), $cid]);
                                if ($totalCost > 0) {
                                    $db->prepare("INSERT INTO user_sms_wallet (user_id, credits) VALUES (?, 0) ON DUPLICATE KEY UPDATE user_id=user_id")->execute([$uid]);
                                    $db->prepare("UPDATE user_sms_wallet SET credits=GREATEST(0,credits-?), updated_at=NOW() WHERE user_id=?")->execute([$totalCost, $uid]);
                                    $db->prepare("INSERT INTO sms_credit_transactions (user_id, amount, type, description, reference) VALUES (?,?,'debit',?,?)")->execute([$uid, $totalCost, "Quick Send #{$cid}", "qsend_{$cid}"]);
                                }
                                $msg = sprintf('✅ Sent to %d recipient%s. Cost: %s%.2f', count($phones), count($phones) > 1 ? 's' : '', currencySymbol(), $totalCost);
                            } else {
                                $db->prepare("UPDATE sms_campaigns SET status='failed', failed_count=? WHERE id=?")->execute([count($phones), $cid]);
                                $msg = 'Failed: ' . ($result['message'] ?? 'Unknown error'); $msgType = 'error';
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('user/sms.php send error: ' . $e->getMessage());
                        $msg = 'An error occurred. Please try again.'; $msgType = 'error';
                    }
                }
            }
        }

        if ($action === 'register_sender_id') {
            $sid     = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $_POST['new_sender_id'] ?? '')));
            $sample  = trim($_POST['sample_message'] ?? '');
            if ($sid === '' || strlen($sid) > 11) {
                $msg = 'Sender ID must be 1–11 alphanumeric characters.'; $msgType = 'error';
            } else {
                try {
                    // Check if this user already submitted this ID
                    $chk = $db->prepare("SELECT id FROM sms_sender_ids WHERE user_id=? AND sender_id=?");
                    $chk->execute([$uid, $sid]);
                    if ($chk->fetch()) {
                        $msg = 'You have already submitted this Sender ID.'; $msgType = 'error';
                    } else {
                        // Check if ID already taken by another user
                        $taken = $db->prepare("SELECT id FROM sms_sender_ids WHERE sender_id=?");
                        $taken->execute([$sid]);
                        if ($taken->fetch()) {
                            $msg = 'This Sender ID is already registered. Please choose a different one.'; $msgType = 'error';
                        } else {
                            $db->prepare("INSERT INTO sms_sender_ids (user_id, sender_id, sample_message, status, submitted_at) VALUES (?,?,?,'pending',NOW())")
                               ->execute([$uid, $sid, $sample]);
                            $msg = '✅ Sender ID submitted for approval. An admin will review it shortly.';
                        }
                    }
                } catch (\Exception $e) {
                    error_log('register_sender_id: ' . $e->getMessage());
                    $msg = 'An error occurred. Please try again.'; $msgType = 'error';
                }
            }
        }
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$walletBalance = getWallet2($db, $uid);
$smsUnitPrice  = getSmsPrice2($db);
$currSym       = currencySymbol();

// Approved sender IDs available system-wide
$senderIds = [];
try {
    $senderIds = $db->query("SELECT sender_id FROM sms_sender_ids WHERE status='approved' ORDER BY sender_id")->fetchAll(\PDO::FETCH_COLUMN);
} catch (\Exception $e) {}

// This user's own sender ID registrations
$mySenderIds = [];
try {
    $siStmt = $db->prepare("SELECT * FROM sms_sender_ids WHERE user_id=? ORDER BY submitted_at DESC");
    $siStmt->execute([$uid]);
    $mySenderIds = $siStmt->fetchAll();
} catch (\Exception $e) {}

$myCampaigns = [];
try {
    $s = $db->prepare("SELECT * FROM sms_campaigns WHERE created_by = ? ORDER BY created_at DESC LIMIT 20");
    $s->execute([$uid]);
    $myCampaigns = $s->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'Send SMS';
$activePage = 'sms';

// ── AI token balance ──────────────────────────────────────────────────────────
$aiBalance  = 0;
$costPerSms = 5;
$aiEnabled  = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $balRow = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $balRow->execute([$uid]);
    $aiBalance = (int)($balRow->fetchColumn() ?: 0);
    $aiSettings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('ai_tokens_per_sms','deepseek_api_key')")->fetchAll(\PDO::FETCH_KEY_PAIR);
    $costPerSms = max(1, (int)($aiSettings['ai_tokens_per_sms'] ?? 5));
    $aiEnabled  = !empty(trim($aiSettings['deepseek_api_key'] ?? ''));
} catch (\Exception $e) {}

require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.char-counter {
    font-size:.8rem;color:var(--text-muted);text-align:right;margin-top:.25rem
}
.pages-badge {
    display:inline-flex;align-items:center;gap:.35rem;
    background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.25);
    color:#a78bfa;border-radius:6px;padding:.2rem .65rem;font-size:.8rem;font-weight:600
}
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <div>
        <h1>📤 Send SMS</h1>
        <p style="color:var(--text-muted)">Send bulk SMS messages to your contacts.</p>
    </div>
    <div style="text-align:right">
        <div style="font-size:.8rem;color:var(--text-muted)">Wallet Balance</div>
        <div style="font-size:1.5rem;font-weight:800;color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($walletBalance, 2) ?></div>
        <a href="/billing.php" style="font-size:.78rem;color:#6c63ff">+ Top Up</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
    <?= $msgType === 'error' ? '⚠ ' : '✅ ' ?><?= $msg ?>
</div>
<?php endif; ?>

<div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
<!-- ── Quick Send Form ──────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h3>🚀 Quick Send</h3></div>
    <div class="card-body">
        <form method="POST" id="sendForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="send_sms">

            <div class="form-group">
                <label>Sender ID</label>
                <select name="sender_id" class="form-control">
                    <?php if (empty($senderIds)): ?>
                    <option value="">— None available (contact admin) —</option>
                    <?php else: ?>
                    <?php foreach ($senderIds as $sid): ?>
                    <option value="<?= htmlspecialchars($sid) ?>"><?= htmlspecialchars($sid) ?></option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <input type="hidden" name="route" value="bulk">
            <div class="form-group">
                <label>Recipients <span style="color:red">*</span></label>
                <textarea name="recipients" class="form-control" rows="4"
                          placeholder="Enter phone numbers, one per line:&#10;08012345678&#10;09087654321" required id="recipients"></textarea>
                <small style="color:var(--text-muted)">One number per line. International format also accepted.</small>
            </div>

            <div class="form-group">
                <label>Message <span style="color:red">*</span></label>
                <textarea name="message" class="form-control" rows="5" id="messageBox"
                          placeholder="Type your SMS message here..." required maxlength="1530"></textarea>
                <div class="char-counter">
                    <span id="charCount">0</span> characters ·
                    <span class="pages-badge" id="pagesBadge">1 page</span> ·
                    <span id="costPreview" style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?>/recipient</span>
                </div>
            </div>

            <div id="costSummary" style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.2);border-radius:10px;padding:1rem;margin-bottom:1rem;display:none">
                <div style="font-size:.9rem;color:var(--text-muted)">Cost estimate</div>
                <div id="costDetail" style="font-size:1.1rem;font-weight:700;color:var(--accent)"></div>
            </div>

            <button type="submit" class="btn btn-primary btn-full" id="sendBtn">
                <span class="btn-text">📤 Send Now</span>
                <span class="btn-loader" style="display:none">⏳ Sending...</span>
            </button>
        </form>
    </div>
</div>

<!-- ── Pricing reference ───────────────────────────────────────────────────── -->
<div>
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header"><h3>💰 SMS Pricing</h3></div>
        <div class="card-body">
            <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1rem">
                Current price: <strong style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?>/page/recipient</strong>
            </p>
            <div style="display:grid;gap:.5rem">
                <?php for ($p = 1; $p <= 4; $p++):
                    $cmin = $p === 1 ? 1 : (($p-1)*153+1);
                    $cmax = $p === 1 ? 160 : $p*153;
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem .9rem;background:rgba(255,255,255,.03);border-radius:8px;font-size:.88rem">
                    <span style="color:var(--text-muted)"><?= $cmin ?>–<?= $cmax ?> chars (<?= $p ?> page<?= $p>1?'s':'' ?>)</span>
                    <strong style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice * $p, 2) ?>/recipient</strong>
                </div>
                <?php endfor; ?>
            </div>
            <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">
                After 160 chars, each page = 153 chars. Debit = pages × recipients × <?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?>.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>📋 My Campaigns</h3></div>
        <?php if (empty($myCampaigns)): ?>
        <p class="empty-state" style="padding:1.5rem">No campaigns yet.</p>
        <?php else: ?>
        <div class="table-wrap" style="max-height:340px;overflow-y:auto">
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($myCampaigns as $c): ?>
            <tr>
                <td><?= htmlspecialchars(substr($c['name'], 0, 30)) ?></td>
                <td><span class="badge badge-<?= $c['status'] === 'sent' ? 'success' : ($c['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= $c['status'] ?></span></td>
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
</div>

<!-- ── AI SMS Writer ──────────────────────────────────────────────────────────── -->
<?php if ($aiEnabled): ?>
<div style="margin-top:2rem">
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
        <h3>🤖 AI SMS Writer</h3>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
            <span style="background:rgba(108,99,255,.15);border:1px solid rgba(108,99,255,.3);color:#a78bfa;padding:3px 12px;border-radius:20px;font-size:.8rem;font-weight:600" id="smsAiBalance"><?= number_format($aiBalance) ?> tokens</span>
            <a href="/billing.php?tab=ai_tokens" style="font-size:.78rem;color:#6c63ff">+ Buy tokens</a>
        </div>
    </div>
    <div class="card-body">
        <div class="dashboard-grid" style="grid-template-columns:1fr 1fr;gap:1.5rem">
            <!-- Left: AI input -->
            <div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">📝 What should the SMS be about?</label>
                    <textarea id="aiSmsTopic" class="form-control" rows="3"
                        placeholder="e.g. 50% flash sale on all shoes this weekend only — shop before Sunday midnight…"></textarea>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">📏 Target Length</label>
                    <select id="aiSmsPages" class="form-control">
                        <option value="1">1 page — up to 160 chars (standard)</option>
                        <option value="2" selected>2 pages — up to 306 chars (recommended)</option>
                        <option value="3">3 pages — up to 459 chars (long)</option>
                        <option value="4">4 pages — up to 612 chars (extended)</option>
                    </select>
                    <small style="color:var(--text-muted)">Price charged = pages × recipients × unit rate. Shorter = cheaper to send.</small>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">🏷️ Brand / Sender Name <span style="color:var(--text-muted)">(optional)</span></label>
                    <input type="text" id="aiSmsBrand" class="form-control" placeholder="e.g. ShopZone, TechMart, FoodExpress" maxlength="30">
                </div>
                <div class="form-group" style="margin-bottom:1.25rem">
                    <label class="form-label">🔗 Call-to-Action hint <span style="color:var(--text-muted)">(optional)</span></label>
                    <input type="text" id="aiSmsCta" class="form-control" placeholder="e.g. Visit shopzone.com/sale | Call 0800-SHOP" maxlength="80">
                </div>
                <button class="btn btn-primary" id="btnAiSms" style="width:100%">
                    <span id="aiSmsBtnText">✨ Generate SMS (<?= $costPerSms ?> token<?= $costPerSms !== 1 ? 's' : '' ?>)</span>
                    <span id="aiSmsLoader" style="display:none">🔄 Generating…</span>
                </button>
                <div id="aiSmsErr" style="color:#f87171;font-size:.83rem;margin-top:.5rem;display:none"></div>
            </div>
            <!-- Right: Quick topic prompts -->
            <div>
                <label class="form-label">⚡ Quick SMS Topics</label>
                <div style="display:flex;flex-direction:column;gap:.45rem;max-height:340px;overflow-y:auto;padding-right:.25rem" id="smsQuickTopics">
                    <?php
                    $smsTopics = [
                        ['🔥 Flash Sale', 'Flash sale: 40% off everything today only. Limited stock!'],
                        ['🎁 Free Gift', 'Exclusive free gift with every purchase this weekend.'],
                        ['⏰ Last Chance', 'Last chance! Offer expires at midnight tonight.'],
                        ['🆕 New Arrival', 'Exciting new arrivals are now in stock — be the first to shop!'],
                        ['🎉 Holiday Promo', 'Celebrate the season with our special holiday discount.'],
                        ['📦 Order Ready', 'Your order is ready for pickup/delivery — track it now.'],
                        ['💳 Bill Reminder', 'Friendly reminder: your payment is due soon. Pay now to avoid disruption.'],
                        ['🔔 Appointment Reminder', 'Reminder: you have an appointment scheduled. Reply to confirm or reschedule.'],
                        ['⭐ Review Request', 'How was your experience? Leave a quick review and get 10% off your next order.'],
                        ['👥 Referral Bonus', 'Refer a friend and earn bonus credits when they sign up!'],
                        ['🎂 Birthday Offer', 'Happy birthday! Enjoy a special discount just for you today.'],
                        ['💡 Product Tip', 'Quick tip to get the most out of your recent purchase.'],
                        ['🚨 Urgent Alert', 'Important update regarding your account — action required.'],
                        ['🤝 Loyalty Reward', 'As a valued customer, you have earned a loyalty reward. Redeem now.'],
                        ['📱 App Download', 'Download our app today and get an exclusive welcome bonus.'],
                    ];
                    foreach ($smsTopics as [$label, $topic]): ?>
                    <button class="btn btn-sm btn-secondary sms-quick-topic" style="text-align:left;white-space:normal;line-height:1.3;font-size:.82rem"
                        data-topic="<?= htmlspecialchars($topic) ?>">
                        <?= htmlspecialchars($label) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Generated SMS result -->
        <div id="aiSmsResult" style="display:none;margin-top:1.5rem;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.25);border-radius:12px;padding:1.25rem">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
                <strong style="color:#10b981">✅ AI Generated SMS</strong>
                <div style="display:flex;gap:.5rem;align-items:center;font-size:.8rem;color:var(--text-muted)">
                    <span id="aiSmsCharInfo"></span>
                    <span id="aiSmsPagesInfo"></span>
                </div>
            </div>
            <textarea id="aiSmsOutput" class="form-control" rows="4" style="font-size:.9rem;margin-bottom:.75rem"></textarea>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                <button class="btn btn-primary btn-sm" id="btnUseSms">📋 Use in Message Box</button>
                <button class="btn btn-sm btn-secondary" id="btnRegenSms">🔄 Regenerate</button>
                <span style="font-size:.8rem;color:var(--text-muted);align-self:center" id="aiSmsTokenInfo"></span>
            </div>
        </div>
    </div>
</div>
</div>
<?php else: ?>
<div style="margin-top:2rem">
<div class="card">
    <div class="card-body" style="text-align:center;padding:2rem;color:var(--text-muted)">
        <p>🤖 AI SMS Writer is not configured yet. <a href="/billing.php?tab=ai_tokens">Buy AI Tokens</a> to enable it once the admin configures the service.</p>
    </div>
</div>
</div>
<?php endif; ?>

<!-- ── Sender ID Registration ───────────────────────────────────────────────── -->
<div style="margin-top:2rem">
<div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
    <!-- Register form -->
    <div class="card">
        <div class="card-header"><h3>🆔 Register Sender ID</h3></div>
        <div class="card-body">
            <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1rem">
                Sender IDs appear as the "From" name on your SMS. They are up to 11 alphanumeric characters and require admin approval before use.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="register_sender_id">
                <div class="form-group">
                    <label>Sender ID <span style="color:red">*</span></label>
                    <input type="text" name="new_sender_id" class="form-control" maxlength="11" pattern="[A-Za-z0-9]+" required placeholder="e.g. MYBRAND">
                    <small style="color:var(--text-muted)">Max 11 alphanumeric characters, no spaces.</small>
                </div>
                <div class="form-group">
                    <label>Sample Message <span style="color:var(--text-muted)">(optional)</span></label>
                    <textarea name="sample_message" class="form-control" rows="3" placeholder="Example SMS that will be sent with this sender ID..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            </form>
        </div>
    </div>

    <!-- My sender IDs -->
    <div class="card">
        <div class="card-header"><h3>📋 My Sender IDs</h3></div>
        <?php if (empty($mySenderIds)): ?>
        <p class="empty-state" style="padding:1.5rem">No sender IDs registered yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Sender ID</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($mySenderIds as $si): ?>
            <tr>
                <td><strong><?= htmlspecialchars($si['sender_id']) ?></strong></td>
                <td>
                    <?php
                    $badgeMap = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
                    $badge    = $badgeMap[$si['status']] ?? 'warning';
                    ?>
                    <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($si['status']) ?></span>
                </td>
                <td><?= htmlspecialchars(substr($si['submitted_at'] ?? '', 0, 10)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
(function() {
    const msgBox   = document.getElementById('messageBox');
    const charCnt  = document.getElementById('charCount');
    const pageBadge= document.getElementById('pagesBadge');
    const costPrev = document.getElementById('costPreview');
    const recipBox = document.getElementById('recipients');
    const costSum  = document.getElementById('costSummary');
    const costDet  = document.getElementById('costDetail');
    const unitPrice= <?= $smsUnitPrice ?>;
    const currSym  = <?= json_encode($currSym) ?>;

    function pages(len) {
        if (len <= 0)   return 0;
        if (len <= 160) return 1;
        return Math.ceil(len / 153);
    }
    function recipCount() {
        return recipBox.value.split('\n').map(s=>s.trim()).filter(s=>s.length>0).length;
    }
    function update() {
        const len   = msgBox.value.length;
        const pg    = pages(len);
        const recs  = recipCount();
        const cost  = pg * recs * unitPrice;

        charCnt.textContent  = len;
        pageBadge.textContent= pg + ' page' + (pg!==1?'s':'');
        costPrev.textContent = currSym + (pg * unitPrice).toFixed(2) + '/recipient';

        if (recs > 0 && pg > 0) {
            costSum.style.display = 'block';
            costDet.textContent   = pg + ' page' + (pg!==1?'s':'') + ' × ' + recs + ' recipient' + (recs!==1?'s':'') + ' × ' + currSym + unitPrice.toFixed(2) + ' = ' + currSym + cost.toFixed(2);
        } else {
            costSum.style.display = 'none';
        }
    }

    msgBox.addEventListener('input', update);
    recipBox.addEventListener('input', update);
    update();

    document.getElementById('sendForm').addEventListener('submit', function() {
        document.querySelector('#sendForm .btn-text').style.display = 'none';
        document.querySelector('#sendForm .btn-loader').style.display = 'inline';
        document.getElementById('sendBtn').disabled = true;
    });
})();

// ── AI SMS Writer ─────────────────────────────────────────────────────────────
(function () {
    const btnAi     = document.getElementById('btnAiSms');
    const btnUse    = document.getElementById('btnUseSms');
    const btnRegen  = document.getElementById('btnRegenSms');
    const topicBox  = document.getElementById('aiSmsTopic');
    const pagesBox  = document.getElementById('aiSmsPages');
    const brandBox  = document.getElementById('aiSmsBrand');
    const ctaBox    = document.getElementById('aiSmsCta');
    const output    = document.getElementById('aiSmsOutput');
    const result    = document.getElementById('aiSmsResult');
    const errBox    = document.getElementById('aiSmsErr');
    const loader    = document.getElementById('aiSmsLoader');
    const btnText   = document.getElementById('aiSmsBtnText');
    const charInfo  = document.getElementById('aiSmsCharInfo');
    const pagesInfo = document.getElementById('aiSmsPagesInfo');
    const tokenInfo = document.getElementById('aiSmsTokenInfo');
    const balBadge  = document.getElementById('smsAiBalance');
    const msgBox    = document.getElementById('messageBox');

    if (!btnAi) return; // AI not enabled

    function setLoading(on) {
        btnAi.disabled = on;
        loader.style.display  = on ? '' : 'none';
        btnText.style.display = on ? 'none' : '';
    }

    async function generate() {
        const topic = topicBox.value.trim();
        if (!topic) { topicBox.focus(); return; }
        errBox.style.display = 'none';
        result.style.display = 'none';
        setLoading(true);

        try {
            const resp = await fetch('/api/ai-sms.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    topic: topic,
                    pages: parseInt(pagesBox.value, 10),
                    brand: brandBox.value.trim(),
                    cta:   ctaBox.value.trim(),
                }),
            });
            const data = await resp.json();
            if (!data.success) {
                errBox.innerHTML = data.message || 'Generation failed. Please try again.';
                errBox.style.display = '';
            } else {
                output.value    = data.text;
                charInfo.textContent  = data.char_count + ' / ' + data.max_chars + ' chars';
                pagesInfo.textContent = data.pages + ' SMS page' + (data.pages > 1 ? 's' : '');
                tokenInfo.textContent = '−' + data.tokens_used + ' token' + (data.tokens_used !== 1 ? 's' : '') + ' used';
                if (balBadge) balBadge.textContent = data.balance.toLocaleString() + ' tokens';
                result.style.display = '';

                // Auto-sync pages selector with actual result
                pagesBox.value = String(data.pages);
            }
        } catch (e) {
            errBox.textContent = 'Network error. Please try again.';
            errBox.style.display = '';
        } finally {
            setLoading(false);
        }
    }

    btnAi.addEventListener('click', generate);
    btnRegen.addEventListener('click', generate);

    // Copy AI text into the main send form
    btnUse.addEventListener('click', function () {
        if (!msgBox) return;
        msgBox.value = output.value;
        msgBox.dispatchEvent(new Event('input'));
        msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        msgBox.focus();
    });

    // Quick topic chips
    document.querySelectorAll('.sms-quick-topic').forEach(btn => {
        btn.addEventListener('click', function () {
            topicBox.value = this.dataset.topic;
            topicBox.dispatchEvent(new Event('input'));
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
