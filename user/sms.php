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

// Determine which tab to show after a POST (re-open the relevant tab)
$activeTab = $_GET['tab'] ?? 'send';
if ($msg && $msgType !== 'error') {
    // Successful send → stay on send tab; successful sender ID → open sender tab
    if (!empty($_POST['action'])) {
        $activeTab = $_POST['action'] === 'register_sender_id' ? 'sender' : 'send';
    }
} elseif ($msg && $msgType === 'error') {
    if (!empty($_POST['action'])) {
        $activeTab = $_POST['action'] === 'register_sender_id' ? 'sender' : 'send';
    }
}
$validTabs = ['send','ai','sender','campaigns','pricing'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'send';
?>
<style>
/* ── Page ─────────────────────────────────────────────────────────────── */
.sms-page-header {
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem
}
.sms-balance-chip {
    background:rgba(108,99,255,.1);border:1px solid rgba(108,99,255,.25);
    border-radius:12px;padding:.6rem 1.1rem;text-align:right
}
.sms-balance-chip .bal-label { font-size:.75rem;color:var(--text-muted) }
.sms-balance-chip .bal-value { font-size:1.4rem;font-weight:800;color:var(--accent);line-height:1.1 }
.sms-balance-chip .bal-link  { font-size:.75rem;color:#6c63ff;text-decoration:none }
.sms-balance-chip .bal-link:hover { text-decoration:underline }

/* ── Tab bar ──────────────────────────────────────────────────────────── */
.sms-tabs {
    display:flex;gap:0;overflow-x:auto;border-bottom:2px solid rgba(255,255,255,.08);
    margin-bottom:1.5rem;-webkit-overflow-scrolling:touch;scrollbar-width:none
}
.sms-tabs::-webkit-scrollbar { display:none }
.sms-tab-btn {
    flex:0 0 auto;display:flex;align-items:center;gap:.4rem;
    padding:.65rem 1.1rem;font-size:.88rem;font-weight:600;white-space:nowrap;
    background:none;border:none;border-bottom:2px solid transparent;
    color:var(--text-muted);cursor:pointer;transition:.15s;margin-bottom:-2px
}
.sms-tab-btn:hover { color:var(--text-primary) }
.sms-tab-btn.active { color:var(--accent);border-bottom-color:var(--accent) }
.sms-tab-btn .tab-badge {
    background:rgba(108,99,255,.18);color:#a78bfa;
    border-radius:10px;padding:1px 6px;font-size:.7rem;font-weight:700
}

/* ── Tab panes ────────────────────────────────────────────────────────── */
.sms-tab-pane { display:none }
.sms-tab-pane.active { display:block }

/* ── Two-column grid, responsive ──────────────────────────────────────── */
.sms-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start
}
@media (max-width:768px) {
    .sms-grid { grid-template-columns:1fr }
    .sms-tab-btn { padding:.55rem .85rem;font-size:.82rem }
}

/* ── Char counter / pages badge ────────────────────────────────────────── */
.char-counter { font-size:.8rem;color:var(--text-muted);text-align:right;margin-top:.25rem }
.pages-badge {
    display:inline-flex;align-items:center;gap:.35rem;
    background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.25);
    color:#a78bfa;border-radius:6px;padding:.2rem .65rem;font-size:.8rem;font-weight:600
}

/* ── Cost summary box ──────────────────────────────────────────────────── */
.cost-box {
    background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.2);
    border-radius:10px;padding:1rem;margin-bottom:1rem
}

/* ── Pricing rows ─────────────────────────────────────────────────────── */
.price-row {
    display:flex;justify-content:space-between;align-items:center;
    padding:.6rem .9rem;background:rgba(255,255,255,.03);border-radius:8px;font-size:.88rem
}

/* ── AI result box ────────────────────────────────────────────────────── */
.ai-result-box {
    background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.25);
    border-radius:12px;padding:1.25rem;margin-top:1.5rem
}

/* ── Token badge ──────────────────────────────────────────────────────── */
.token-badge {
    background:rgba(108,99,255,.15);border:1px solid rgba(108,99,255,.3);
    color:#a78bfa;padding:3px 12px;border-radius:20px;font-size:.8rem;font-weight:600
}

/* ── Empty state ──────────────────────────────────────────────────────── */
.sms-empty { padding:2rem;text-align:center;color:var(--text-muted);font-size:.9rem }
</style>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="sms-page-header">
    <div>
        <h1 style="margin:0 0 .25rem">📤 SMS Marketing</h1>
        <p style="color:var(--text-muted);margin:0;font-size:.9rem">Send campaigns, write AI-powered messages, and manage sender IDs.</p>
    </div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:stretch">
        <div class="sms-balance-chip">
            <div class="bal-label">💳 Wallet</div>
            <div class="bal-value"><?= htmlspecialchars($currSym) ?><?= number_format($walletBalance, 2) ?></div>
            <a href="/billing.php" class="bal-link">+ Top Up</a>
        </div>
        <?php if ($aiEnabled): ?>
        <div class="sms-balance-chip">
            <div class="bal-label">🤖 AI Tokens</div>
            <div class="bal-value" id="smsAiBalance"><?= number_format($aiBalance) ?></div>
            <a href="/billing.php?tab=ai_tokens" class="bal-link">+ Buy Tokens</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.25rem">
    <?= $msgType === 'error' ? '⚠ ' : '✅ ' ?><?= $msg ?>
</div>
<?php endif; ?>

<!-- ── Tab bar ─────────────────────────────────────────────────────────────── -->
<div class="sms-tabs" role="tablist" id="smsTabs">
    <button class="sms-tab-btn <?= $activeTab === 'send'      ? 'active' : '' ?>" data-tab="send"      role="tab">🚀 Quick Send</button>
    <?php if ($aiEnabled): ?>
    <button class="sms-tab-btn <?= $activeTab === 'ai'        ? 'active' : '' ?>" data-tab="ai"        role="tab">🤖 AI Writer <span class="tab-badge">AI</span></button>
    <?php endif; ?>
    <button class="sms-tab-btn <?= $activeTab === 'campaigns' ? 'active' : '' ?>" data-tab="campaigns" role="tab">📋 Campaigns <span class="tab-badge"><?= count($myCampaigns) ?></span></button>
    <button class="sms-tab-btn <?= $activeTab === 'sender'    ? 'active' : '' ?>" data-tab="sender"    role="tab">🆔 Sender IDs</button>
    <button class="sms-tab-btn <?= $activeTab === 'pricing'   ? 'active' : '' ?>" data-tab="pricing"   role="tab">💰 Pricing</button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1 — Quick Send                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="sms-tab-pane <?= $activeTab === 'send' ? 'active' : '' ?>" id="tab-send" role="tabpanel">
    <div class="sms-grid">
        <!-- Send form -->
        <div class="card">
            <div class="card-header"><h3>🚀 Quick Send</h3></div>
            <div class="card-body">
                <form method="POST" id="sendForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="send_sms">

                    <div class="form-group">
                        <label class="form-label">Sender ID</label>
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
                        <label class="form-label">Recipients <span style="color:red">*</span></label>
                        <textarea name="recipients" class="form-control" rows="4" required id="recipients"
                            placeholder="Enter phone numbers, one per line:&#10;08012345678&#10;09087654321"></textarea>
                        <small style="color:var(--text-muted)">One number per line. International format accepted.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message <span style="color:red">*</span></label>
                        <textarea name="message" class="form-control" rows="5" id="messageBox" required maxlength="1530"
                            placeholder="Type your SMS here… or use the AI Writer tab to generate one."></textarea>
                        <div class="char-counter">
                            <span id="charCount">0</span> chars ·
                            <span class="pages-badge" id="pagesBadge">0 pages</span> ·
                            <span id="costPreview" style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?>/recipient</span>
                        </div>
                    </div>

                    <div id="costSummary" class="cost-box" style="display:none">
                        <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:.25rem">Estimated cost</div>
                        <div id="costDetail" style="font-size:1.1rem;font-weight:700;color:var(--accent)"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" id="sendBtn">
                        <span class="btn-text">📤 Send Now</span>
                        <span class="btn-loader" style="display:none">⏳ Sending…</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Tips / guide sidebar -->
        <div>
            <div class="card" style="margin-bottom:1.25rem">
                <div class="card-header"><h3>📖 Sending Tips</h3></div>
                <div class="card-body" style="font-size:.875rem;color:var(--text-muted);line-height:1.7">
                    <p style="margin:0 0 .6rem">✅ <strong>Standard SMS</strong> is 160 characters (1 page).</p>
                    <p style="margin:0 0 .6rem">✅ Longer messages use <strong>153 chars/page</strong> after the first.</p>
                    <p style="margin:0 0 .6rem">✅ Cost = <strong>pages × recipients × unit price</strong>.</p>
                    <p style="margin:0 0 .6rem">✅ Use the <strong>AI Writer</strong> tab to auto-generate copy.</p>
                    <p style="margin:0">✅ Need a custom sender name? Register a <strong>Sender ID</strong> first.</p>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>⚡ Quick Phrases</h3></div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:.4rem">
                    <?php
                    $phrases = [
                        ['🔥 Flash Sale', 'Flash sale: 40% off everything today only. Limited stock!'],
                        ['🎁 Promo Code', 'Use code SAVE20 for 20% off your next order. Valid today only.'],
                        ['🔔 Reminder', 'Friendly reminder: your appointment is tomorrow. Reply YES to confirm.'],
                        ['⭐ Review', 'Thank you for your purchase! Share your experience and get 10% off next time.'],
                        ['🎉 Event', 'You\'re invited! Join us this Saturday for our exclusive launch event.'],
                    ];
                    foreach ($phrases as [$lbl, $txt]): ?>
                    <button class="btn btn-sm btn-secondary quick-phrase"
                        style="text-align:left;white-space:normal;line-height:1.4;font-size:.82rem"
                        data-text="<?= htmlspecialchars($txt) ?>">
                        <?= htmlspecialchars($lbl) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2 — AI Writer                                                          -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($aiEnabled): ?>
<div class="sms-tab-pane <?= $activeTab === 'ai' ? 'active' : '' ?>" id="tab-ai" role="tabpanel">
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
            <h3>🤖 AI SMS Writer</h3>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                <span class="token-badge" id="smsAiBadge"><?= number_format($aiBalance) ?> tokens</span>
                <a href="/billing.php?tab=ai_tokens" style="font-size:.78rem;color:#6c63ff">+ Buy Tokens</a>
            </div>
        </div>
        <div class="card-body">
            <div class="sms-grid">
                <!-- Left — AI inputs -->
                <div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">📝 What should the SMS be about?</label>
                        <textarea id="aiSmsTopic" class="form-control" rows="3"
                            placeholder="e.g. 50% flash sale on all shoes this weekend only — shop before Sunday midnight…"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">📏 Target Length</label>
                        <select id="aiSmsPages" class="form-control">
                            <option value="1">1 page — up to 160 chars (concise)</option>
                            <option value="2" selected>2 pages — up to 306 chars (recommended)</option>
                            <option value="3">3 pages — up to 459 chars (detailed)</option>
                            <option value="4">4 pages — up to 612 chars (extended)</option>
                        </select>
                        <small style="color:var(--text-muted)">Shorter = cheaper. Cost = pages × recipients × unit rate.</small>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">🏷️ Brand / Sender Name <span style="color:var(--text-muted)">(optional)</span></label>
                        <input type="text" id="aiSmsBrand" class="form-control" placeholder="e.g. ShopZone, TechMart, FoodExpress" maxlength="30">
                    </div>
                    <div class="form-group" style="margin-bottom:1.25rem">
                        <label class="form-label">🔗 Call-to-Action hint <span style="color:var(--text-muted)">(optional)</span></label>
                        <input type="text" id="aiSmsCta" class="form-control" placeholder="e.g. Visit shopzone.com/sale or Call 0800-SHOP" maxlength="80">
                    </div>
                    <button class="btn btn-primary" id="btnAiSms" style="width:100%">
                        <span id="aiSmsBtnText">✨ Generate SMS (<?= $costPerSms ?> token<?= $costPerSms !== 1 ? 's' : '' ?>)</span>
                        <span id="aiSmsLoader" style="display:none">🔄 Generating…</span>
                    </button>
                    <div id="aiSmsErr" style="color:#f87171;font-size:.83rem;margin-top:.5rem;display:none"></div>
                </div>

                <!-- Right — Quick topic chips -->
                <div>
                    <label class="form-label">⚡ Quick SMS Topics</label>
                    <div style="display:flex;flex-direction:column;gap:.4rem;max-height:360px;overflow-y:auto;padding-right:.2rem">
                        <?php
                        $smsTopics = [
                            ['🔥 Flash Sale',           'Flash sale: 40% off everything today only. Limited stock!'],
                            ['🎁 Free Gift',             'Exclusive free gift with every purchase this weekend.'],
                            ['⏰ Last Chance',           'Last chance! Offer expires at midnight tonight.'],
                            ['🆕 New Arrival',           'Exciting new arrivals are now in stock — be the first to shop!'],
                            ['🎉 Holiday Promo',         'Celebrate the season with our special holiday discount.'],
                            ['📦 Order Ready',           'Your order is ready for pickup/delivery — track it now.'],
                            ['💳 Bill Reminder',         'Friendly reminder: your payment is due soon. Pay now to avoid disruption.'],
                            ['🔔 Appointment',           'Reminder: you have an appointment scheduled. Reply YES to confirm.'],
                            ['⭐ Review Request',         'How was your experience? Leave a quick review and get 10% off your next order.'],
                            ['👥 Referral Bonus',        'Refer a friend and earn bonus credits when they sign up!'],
                            ['🎂 Birthday Offer',        'Happy birthday! Enjoy a special discount just for you today.'],
                            ['💡 Product Tip',           'Quick tip to get the most out of your recent purchase.'],
                            ['🚨 Urgent Alert',          'Important update regarding your account — action required.'],
                            ['🤝 Loyalty Reward',        'As a valued customer, you have earned a loyalty reward. Redeem now.'],
                            ['📱 App Download',          'Download our app today and get an exclusive welcome bonus.'],
                            ['🛍️ Back in Stock',         'Great news! Your wishlist item is back in stock. Grab it before it sells out.'],
                            ['💰 Pay Day Sale',          'It\'s pay day! Treat yourself with our special pay day deals — this weekend only.'],
                            ['🎓 Student Discount',      'Students get 25% off! Show your student ID at checkout or use code STUDENT25.'],
                        ];
                        foreach ($smsTopics as [$label, $topic]): ?>
                        <button class="btn btn-sm btn-secondary sms-quick-topic"
                            style="text-align:left;white-space:normal;line-height:1.3;font-size:.82rem"
                            data-topic="<?= htmlspecialchars($topic) ?>">
                            <?= htmlspecialchars($label) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Generated result -->
            <div id="aiSmsResult" class="ai-result-box" style="display:none">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
                    <strong style="color:#10b981">✅ AI Generated SMS</strong>
                    <div style="display:flex;gap:.75rem;align-items:center;font-size:.8rem;color:var(--text-muted)">
                        <span id="aiSmsCharInfo"></span>
                        <span id="aiSmsPagesInfo"></span>
                    </div>
                </div>
                <textarea id="aiSmsOutput" class="form-control" rows="4" style="font-size:.9rem;margin-bottom:.75rem"></textarea>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
                    <button class="btn btn-primary btn-sm" id="btnUseSms">📋 Use in Quick Send</button>
                    <button class="btn btn-sm btn-secondary" id="btnRegenSms">🔄 Regenerate</button>
                    <span style="font-size:.8rem;color:var(--text-muted)" id="aiSmsTokenInfo"></span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3 — Campaigns                                                          -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="sms-tab-pane <?= $activeTab === 'campaigns' ? 'active' : '' ?>" id="tab-campaigns" role="tabpanel">
    <div class="card">
        <div class="card-header"><h3>📋 My Campaigns</h3></div>
        <?php if (empty($myCampaigns)): ?>
        <div class="sms-empty">
            <div style="font-size:2.5rem;margin-bottom:.5rem">📭</div>
            <p style="margin:0">No campaigns yet. Use Quick Send to get started!</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table" style="font-size:.87rem">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th style="white-space:nowrap">Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($myCampaigns as $c): ?>
                <tr>
                    <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= htmlspecialchars(substr($c['name'], 0, 40)) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $c['status'] === 'sent' ? 'success' : ($c['status'] === 'failed' ? 'danger' : 'warning') ?>">
                            <?= htmlspecialchars($c['status']) ?>
                        </span>
                    </td>
                    <td><?= number_format((int)$c['sent_count']) ?></td>
                    <td style="white-space:nowrap"><?= timeAgo($c['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4 — Sender IDs                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="sms-tab-pane <?= $activeTab === 'sender' ? 'active' : '' ?>" id="tab-sender" role="tabpanel">
    <div class="sms-grid">
        <!-- Register form -->
        <div class="card">
            <div class="card-header"><h3>🆔 Register Sender ID</h3></div>
            <div class="card-body">
                <p style="color:var(--text-muted);font-size:.875rem;margin-bottom:1rem;line-height:1.6">
                    Sender IDs appear as the <em>"From"</em> name on your SMS. They can be up to
                    <strong>11 alphanumeric characters</strong> and require admin approval before use.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="register_sender_id">
                    <div class="form-group">
                        <label class="form-label">Sender ID <span style="color:red">*</span></label>
                        <input type="text" name="new_sender_id" class="form-control" maxlength="11"
                            pattern="[A-Za-z0-9]+" required placeholder="e.g. MYBRAND"
                            style="text-transform:uppercase;letter-spacing:.05em">
                        <small style="color:var(--text-muted)">Max 11 alphanumeric characters, no spaces.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sample Message <span style="color:var(--text-muted)">(optional)</span></label>
                        <textarea name="sample_message" class="form-control" rows="3"
                            placeholder="Example SMS that will be sent using this sender ID…"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">🚀 Submit for Approval</button>
                </form>
            </div>
        </div>

        <!-- My sender IDs -->
        <div class="card">
            <div class="card-header"><h3>📋 My Sender IDs</h3></div>
            <?php if (empty($mySenderIds)): ?>
            <div class="sms-empty">
                <div style="font-size:2rem;margin-bottom:.5rem">🆔</div>
                <p style="margin:0">No sender IDs registered yet.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table" style="font-size:.87rem">
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

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 5 — Pricing                                                            -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="sms-tab-pane <?= $activeTab === 'pricing' ? 'active' : '' ?>" id="tab-pricing" role="tabpanel">
    <div class="sms-grid">
        <div class="card">
            <div class="card-header"><h3>💰 SMS Pricing</h3></div>
            <div class="card-body">
                <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.1rem">
                    Unit rate: <strong style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?> per page/recipient</strong>
                </p>
                <div style="display:grid;gap:.5rem;margin-bottom:1rem">
                    <?php for ($p = 1; $p <= 4; $p++):
                        $cmin = $p === 1 ? 1 : (($p-1)*153+1);
                        $cmax = $p === 1 ? 160 : $p*153;
                    ?>
                    <div class="price-row">
                        <div>
                            <strong><?= $p ?> page<?= $p > 1 ? 's' : '' ?></strong>
                            <span style="color:var(--text-muted);font-size:.82rem;margin-left:.5rem"><?= $cmin ?>–<?= $cmax ?> chars</span>
                        </div>
                        <strong style="color:var(--accent)"><?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice * $p, 2) ?>/recipient</strong>
                    </div>
                    <?php endfor; ?>
                </div>
                <p style="font-size:.8rem;color:var(--text-muted);margin:0">
                    After 160 characters, each additional page uses 153 characters.<br>
                    <strong>Cost formula:</strong> pages × recipients × <?= htmlspecialchars($currSym) ?><?= number_format($smsUnitPrice, 2) ?>
                </p>
            </div>
        </div>

        <?php if ($aiEnabled): ?>
        <div class="card">
            <div class="card-header"><h3>🤖 AI Token Pricing</h3></div>
            <div class="card-body">
                <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1rem">
                    AI features are powered by tokens. Your current balance:
                    <strong style="color:var(--accent)"><?= number_format($aiBalance) ?> tokens</strong>
                </p>
                <div class="price-row" style="margin-bottom:.5rem">
                    <span style="color:var(--text-muted)">Per SMS generation</span>
                    <strong style="color:var(--accent)"><?= $costPerSms ?> token<?= $costPerSms !== 1 ? 's' : '' ?></strong>
                </div>
                <div style="margin-top:1rem">
                    <a href="/billing.php?tab=ai_tokens" class="btn btn-primary" style="width:100%">💰 Buy AI Tokens</a>
                </div>
                <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem;margin-bottom:0">
                    Tokens are only deducted after a successful AI response. Failed requests cost nothing.
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
(function () {
    const tabs  = document.querySelectorAll('.sms-tab-btn');
    const panes = document.querySelectorAll('.sms-tab-pane');

    function activate(tabId) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
        panes.forEach(p => p.classList.toggle('active', p.id === 'tab-' + tabId));
        history.replaceState(null, '', '?tab=' + tabId);
    }

    tabs.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.tab)));
})();

// ── Quick Send — char counter & cost preview ──────────────────────────────────
(function() {
    const msgBox   = document.getElementById('messageBox');
    const charCnt  = document.getElementById('charCount');
    const pageBadge= document.getElementById('pagesBadge');
    const costPrev = document.getElementById('costPreview');
    const recipBox = document.getElementById('recipients');
    const costSum  = document.getElementById('costSummary');
    const costDet  = document.getElementById('costDetail');
    const unitPrice= <?= json_encode($smsUnitPrice) ?>;
    const currSym  = <?= json_encode($currSym) ?>;

    function pages(len) {
        if (len <= 0)   return 0;
        if (len <= 160) return 1;
        return Math.ceil(len / 153);
    }
    function recipCount() {
        return recipBox.value.split('\n').map(s => s.trim()).filter(s => s.length > 0).length;
    }
    function update() {
        const len  = msgBox.value.length;
        const pg   = pages(len);
        const recs = recipCount();
        const cost = pg * recs * unitPrice;

        charCnt.textContent   = len;
        pageBadge.textContent = pg + ' page' + (pg !== 1 ? 's' : '');
        costPrev.textContent  = currSym + (pg * unitPrice).toFixed(2) + '/recipient';

        if (recs > 0 && pg > 0) {
            costSum.style.display = 'block';
            costDet.textContent   = pg + ' page' + (pg !== 1 ? 's' : '') + ' × ' + recs
                + ' recipient' + (recs !== 1 ? 's' : '') + ' × ' + currSym
                + unitPrice.toFixed(2) + ' = ' + currSym + cost.toFixed(2);
        } else {
            costSum.style.display = 'none';
        }
    }

    msgBox.addEventListener('input', update);
    recipBox.addEventListener('input', update);
    update();

    // Quick phrase chips
    document.querySelectorAll('.quick-phrase').forEach(btn => {
        btn.addEventListener('click', function () {
            msgBox.value = this.dataset.text;
            msgBox.dispatchEvent(new Event('input'));
            msgBox.focus();
        });
    });

    document.getElementById('sendForm').addEventListener('submit', function () {
        document.querySelector('#sendForm .btn-text').style.display = 'none';
        document.querySelector('#sendForm .btn-loader').style.display = 'inline';
        document.getElementById('sendBtn').disabled = true;
    });
})();

// ── AI SMS Writer ─────────────────────────────────────────────────────────────
(function () {
    const btnAi     = document.getElementById('btnAiSms');
    if (!btnAi) return; // AI not enabled

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
    const msgBox    = document.getElementById('messageBox');
    // Both balance display elements (header chip + tab badge)
    const balEls    = [document.getElementById('smsAiBalance'), document.getElementById('smsAiBadge')];

    function setLoading(on) {
        btnAi.disabled        = on;
        loader.style.display  = on ? '' : 'none';
        btnText.style.display = on ? 'none' : '';
    }

    async function generate() {
        const topic = topicBox.value.trim();
        if (!topic) { topicBox.focus(); return; }
        errBox.style.display  = 'none';
        result.style.display  = 'none';
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
                errBox.innerHTML     = data.message || 'Generation failed. Please try again.';
                errBox.style.display = '';
            } else {
                output.value         = data.text;
                charInfo.textContent = data.char_count + ' / ' + data.max_chars + ' chars';
                pagesInfo.textContent= data.pages + ' SMS page' + (data.pages > 1 ? 's' : '');
                tokenInfo.textContent= '\u2212' + data.tokens_used + ' token' + (data.tokens_used !== 1 ? 's' : '') + ' used';
                balEls.forEach(el => { if (el) el.textContent = data.balance.toLocaleString() + ' tokens'; });
                result.style.display = '';
                pagesBox.value       = String(data.pages);
            }
        } catch (_e) {
            errBox.textContent   = 'Network error. Please try again.';
            errBox.style.display = '';
        } finally {
            setLoading(false);
        }
    }

    btnAi.addEventListener('click', generate);
    btnRegen.addEventListener('click', generate);

    // Use generated text in Quick Send tab
    btnUse.addEventListener('click', function () {
        if (!msgBox) return;
        msgBox.value = output.value;
        msgBox.dispatchEvent(new Event('input'));
        // Switch to send tab
        document.querySelector('.sms-tab-btn[data-tab="send"]').click();
        setTimeout(() => { msgBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); msgBox.focus(); }, 80);
    });

    // Quick topic chips
    document.querySelectorAll('.sms-quick-topic').forEach(btn => {
        btn.addEventListener('click', function () {
            topicBox.value = this.dataset.topic;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
