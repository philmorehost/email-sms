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
            $route   = sanitize($_POST['route'] ?? 'bulk');
            if (!in_array($route, ['bulk','corporate','global'], true)) $route = 'bulk';

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
                    $msg = sprintf(
                        'Insufficient balance. Need ₦%.2f (%d page%s × %d recipient%s × ₦%.2f). Balance: ₦%.2f. <a href="/billing.php">Buy credits</a>.',
                        $totalCost, $pages, $pages > 1 ? 's' : '', count($phones), count($phones) > 1 ? 's' : '', $unitPrice, $balance
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
                            $result   = match ($route) {
                                'corporate' => $sms->sendCorporateSMS($sender, $recipStr, $message),
                                'global'    => $sms->sendGlobalSMS($sender, $recipStr, $message),
                                default     => $sms->sendBulkSMS($sender, $recipStr, $message),
                            };

                            if (!empty($result['success'])) {
                                $db->prepare("UPDATE sms_campaigns SET status='sent', sent_count=?, sent_at=NOW() WHERE id=?")->execute([count($phones), $cid]);
                                if ($totalCost > 0) {
                                    $db->prepare("INSERT INTO user_sms_wallet (user_id, credits) VALUES (?, 0) ON DUPLICATE KEY UPDATE user_id=user_id")->execute([$uid]);
                                    $db->prepare("UPDATE user_sms_wallet SET credits=GREATEST(0,credits-?), updated_at=NOW() WHERE user_id=?")->execute([$totalCost, $uid]);
                                    $db->prepare("INSERT INTO sms_credit_transactions (user_id, amount, type, description, reference) VALUES (?,?,'debit',?,?)")->execute([$uid, $totalCost, "Quick Send #{$cid}", "qsend_{$cid}"]);
                                }
                                $msg = sprintf('✅ Sent to %d recipient%s. Cost: ₦%.2f', count($phones), count($phones) > 1 ? 's' : '', $totalCost);
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
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────
$walletBalance = getWallet2($db, $uid);
$smsUnitPrice  = getSmsPrice2($db);

$senderIds = [];
try {
    $senderIds = $db->query("SELECT sender_id FROM sms_sender_ids WHERE status='approved' ORDER BY sender_id")->fetchAll(\PDO::FETCH_COLUMN);
} catch (\Exception $e) {}

$myCampaigns = [];
try {
    $s = $db->prepare("SELECT * FROM sms_campaigns WHERE created_by = ? ORDER BY created_at DESC LIMIT 20");
    $s->execute([$uid]);
    $myCampaigns = $s->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'Send SMS';
$activePage = 'sms';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.char-counter {
    font-size:.8rem;color:#606070;text-align:right;margin-top:.25rem
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
        <div style="font-size:1.5rem;font-weight:800;color:var(--accent)">₦<?= number_format($walletBalance, 2) ?></div>
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

            <div class="form-group">
                <label>Route</label>
                <select name="route" class="form-control">
                    <option value="bulk">Bulk SMS</option>
                    <option value="corporate">Corporate SMS</option>
                    <option value="global">Global SMS</option>
                </select>
            </div>

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
                    <span id="costPreview" style="color:var(--accent)">₦<?= number_format($smsUnitPrice, 2) ?>/recipient</span>
                </div>
            </div>

            <div id="costSummary" style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.2);border-radius:10px;padding:1rem;margin-bottom:1rem;display:none">
                <div style="font-size:.9rem;color:#a0a0b0">Cost estimate</div>
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
                Current price: <strong style="color:var(--accent)">₦<?= number_format($smsUnitPrice, 2) ?>/page/recipient</strong>
            </p>
            <div style="display:grid;gap:.5rem">
                <?php for ($p = 1; $p <= 4; $p++):
                    $cmin = $p === 1 ? 1 : (($p-1)*153+1);
                    $cmax = $p === 1 ? 160 : $p*153;
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem .9rem;background:rgba(255,255,255,.03);border-radius:8px;font-size:.88rem">
                    <span style="color:var(--text-muted)"><?= $cmin ?>–<?= $cmax ?> chars (<?= $p ?> page<?= $p>1?'s':'' ?>)</span>
                    <strong style="color:var(--accent)">₦<?= number_format($smsUnitPrice * $p, 2) ?>/recipient</strong>
                </div>
                <?php endfor; ?>
            </div>
            <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">
                After 160 chars, each page = 153 chars. Debit = pages × recipients × ₦<?= number_format($smsUnitPrice, 2) ?>.
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
        costPrev.textContent = '₦' + (pg * unitPrice).toFixed(2) + '/recipient';

        if (recs > 0 && pg > 0) {
            costSum.style.display = 'block';
            costDet.textContent   = pg + ' page' + (pg!==1?'s':'') + ' × ' + recs + ' recipient' + (recs!==1?'s':'') + ' × ₦' + unitPrice.toFixed(2) + ' = ₦' + cost.toFixed(2);
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
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
