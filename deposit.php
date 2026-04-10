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

// ── Ensure payment tables exist ────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS wallet_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        method ENUM('payhub_card','virtual_bank','manual_transfer') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        fee DECIMAL(12,2) DEFAULT 0.00,
        net_amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','completed','failed','rejected') DEFAULT 'pending',
        reference VARCHAR(100),
        payhub_txn_id VARCHAR(100),
        bank_transfer_proof TEXT,
        admin_note VARCHAR(255),
        processed_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        INDEX idx_user_deposit (user_id),
        INDEX idx_status_deposit (status)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS virtual_bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        account_name VARCHAR(150),
        account_number VARCHAR(30),
        bank_name VARCHAR(100),
        customer_id VARCHAR(100),
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Exception $e) {}

// ── Load app settings ──────────────────────────────────────────────────────
$settings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (\Exception $e) {}

$s = fn(string $k, string $d = '') => $settings[$k] ?? $d;

$currSym        = $s('currency_symbol', '₦');
$depositFeePct  = max(0, (float)$s('deposit_fee_percent', '0'));
$payhubEnabled  = $s('payhub_enabled') === '1';
$vbEnabled      = $s('virtual_bank_enabled') === '1';
$mtEnabled      = $s('manual_transfer_enabled') === '1';
$payhubKey      = $s('payhub_api_key');
$bankAccName    = $s('bank_account_name');
$bankAccNum     = $s('bank_account_number');
$bankName       = $s('bank_name');
$bankCharges    = (float)$s('bank_transfer_charges', '0');
$bankNote       = $s('bank_transfer_note');

// ── Flash helpers ──────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['dep_flash'] = ['msg' => $msg, 'type' => $type];
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $f = $_SESSION['dep_flash'] ?? ['msg' => '', 'type' => 'success'];
    unset($_SESSION['dep_flash']);
    return $f;
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/deposit.php');
    }
    $action = $_POST['action'] ?? '';

    // ── Resync / create virtual bank account ──────────────────────────────
    if ($action === 'resync_virtual_bank') {
        if (!$vbEnabled || !$payhubKey) {
            setFlash('Virtual bank account is not enabled.', 'error');
            redirect('/deposit.php#virtual-bank');
        }
        // Call Payhub: create customer if not exist, then fetch virtual account
        $email    = $user['email'] ?? '';
        $uname    = $user['username'] ?? '';
        $phone    = $user['phone'] ?? '08000000000';
        try {
            // 1. Create / fetch Payhub customer
            $ch = curl_init('https://payhub.datagifting.com.ng/api/customer/create');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_POSTFIELDS     => json_encode([
                    'api_key'  => $payhubKey,
                    'email'    => $email,
                    'name'     => $uname,
                    'phone'    => $phone,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = $resp ? json_decode($resp, true) : [];
            $customerId = $data['data']['customer_id'] ?? $data['customer_id'] ?? null;

            if (!$customerId) {
                // Try to get existing virtual bank without customer creation
                $customerId = 'user_' . $userId;
            }

            // 2. Generate virtual bank account
            $ch2 = curl_init('https://payhub.datagifting.com.ng/api/virtual-account/create');
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_POSTFIELDS     => json_encode([
                    'api_key'     => $payhubKey,
                    'customer_id' => $customerId,
                    'email'       => $email,
                    'name'        => $uname,
                    'phone'       => $phone,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp2    = curl_exec($ch2);
            curl_close($ch2);
            $data2    = $resp2 ? json_decode($resp2, true) : [];

            $accNum  = $data2['data']['account_number'] ?? $data2['account_number'] ?? null;
            $accName = $data2['data']['account_name']   ?? $data2['account_name']   ?? $uname;
            $bkName  = $data2['data']['bank_name']      ?? $data2['bank_name']      ?? 'Payhub Bank';

            if ($accNum) {
                $db->prepare(
                    "INSERT INTO virtual_bank_accounts (user_id, account_name, account_number, bank_name, customer_id)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE account_name=?, account_number=?, bank_name=?, customer_id=?, updated_at=NOW()"
                )->execute([$userId, $accName, $accNum, $bkName, $customerId,
                             $accName, $accNum, $bkName, $customerId]);
                setFlash('Virtual bank account synced successfully.');
            } else {
                setFlash('Could not generate virtual bank account. Please try again or contact support.', 'error');
            }
        } catch (\Exception $e) {
            error_log('Payhub VBA error: ' . $e->getMessage());
            setFlash('Error connecting to payment gateway. Please try again.', 'error');
        }
        redirect('/deposit.php#virtual-bank');
    }

    // ── Submit manual bank transfer notification ──────────────────────────
    if ($action === 'submit_manual_transfer') {
        if (!$mtEnabled) {
            setFlash('Manual transfers are not enabled.', 'error');
            redirect('/deposit.php#manual-transfer');
        }
        $amount = (float)($_POST['amount'] ?? 0);
        $proof  = sanitize($_POST['proof'] ?? '');
        if ($amount < 100) {
            setFlash('Minimum deposit amount is ' . $currSym . '100.', 'error');
            redirect('/deposit.php#manual-transfer');
        }
        $fee       = round($amount * ($depositFeePct / 100), 2) + $bankCharges;
        $netAmount = max(0, $amount - $fee);
        $ref       = 'MT-' . strtoupper(bin2hex(random_bytes(6)));
        try {
            $db->prepare(
                "INSERT INTO wallet_deposits (user_id, method, amount, fee, net_amount, status, reference, bank_transfer_proof)
                 VALUES (?,      'manual_transfer',?,?,?,  'pending',?,?)"
            )->execute([$userId, $amount, $fee, $netAmount, $ref, $proof]);
            setFlash('Transfer notification submitted! Your deposit will be credited once admin approves it.');
        } catch (\Exception $e) {
            setFlash('Error submitting notification.', 'error');
        }
        redirect('/deposit.php#manual-transfer');
    }
}

$flash = popFlash();

// ── Load wallet balance ────────────────────────────────────────────────────
$walletBalance = 0.0;
try {
    $w = $db->prepare("SELECT credits FROM user_sms_wallet WHERE user_id=?");
    $w->execute([$userId]);
    $walletBalance = (float)($w->fetchColumn() ?: 0.0);
} catch (\Exception $e) {}

// ── Load virtual bank account ─────────────────────────────────────────────
$vba = null;
if ($vbEnabled) {
    try {
        $vs = $db->prepare("SELECT * FROM virtual_bank_accounts WHERE user_id=? AND is_active=1");
        $vs->execute([$userId]);
        $vba = $vs->fetch() ?: null;
    } catch (\Exception $e) {}
}

// ── Recent deposit history ─────────────────────────────────────────────────
$deposits = [];
try {
    $ds = $db->prepare("SELECT * FROM wallet_deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $ds->execute([$userId]);
    $deposits = $ds->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'Deposit Funds';
$activePage = 'deposit';
require_once __DIR__ . '/includes/layout_header.php';
?>
<style>
.dep-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:2rem}
.dep-method-card{border-radius:16px;padding:1.75rem;border:2px solid var(--glass-border);background:var(--glass-bg);transition:all .25s}
.dep-method-card.active{border-color:var(--accent)}
.dep-method-icon{font-size:2.5rem;margin-bottom:.75rem}
.dep-method-title{font-size:1.1rem;font-weight:700;margin-bottom:.25rem}
.dep-method-desc{font-size:.85rem;color:var(--text-muted);line-height:1.5}
.vba-box{background:linear-gradient(135deg,rgba(108,99,255,.12),rgba(0,212,255,.08));border:1px solid rgba(108,99,255,.3);border-radius:16px;padding:1.75rem}
.vba-acct{font-size:2rem;font-weight:900;letter-spacing:.05em;color:var(--accent)}
.vba-label{font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem}
.vba-meta{font-size:.95rem;font-weight:600;margin-bottom:.15rem}
.fee-note{font-size:.78rem;color:var(--text-muted);background:rgba(255,165,2,.06);border:1px solid rgba(255,165,2,.2);border-radius:8px;padding:.6rem .9rem;margin-top:.75rem}
</style>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1>💰 Deposit Funds</h1>
            <p>Add money to your wallet. Current balance: <strong><?= htmlspecialchars($currSym) ?><?= number_format($walletBalance, 2) ?></strong></p>
        </div>
        <a href="/billing.php" class="btn btn-secondary">← Back to Billing</a>
    </div>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<?php if (!$payhubEnabled && !$vbEnabled && !$mtEnabled): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem">
        <div style="font-size:3rem;margin-bottom:1rem">🔧</div>
        <h3>No payment methods are enabled</h3>
        <p style="color:var(--text-muted);margin-top:.5rem">Please contact an administrator to enable deposit options.</p>
    </div>
</div>
<?php else: ?>

<!-- ── METHOD CARDS ─────────────────────────────────────────────────────── -->
<div class="dep-methods">
    <?php if ($payhubEnabled): ?>
    <div class="dep-method-card">
        <div class="dep-method-icon">💳</div>
        <div class="dep-method-title">Card / Online Payment</div>
        <div class="dep-method-desc">Pay instantly with debit/credit card via Payhub secure checkout.</div>
        <?php if ($depositFeePct > 0): ?>
        <div class="fee-note">⚠️ <?= $depositFeePct ?>% processing fee applies</div>
        <?php endif; ?>
        <a href="#card-payment" class="btn btn-primary" style="width:100%;margin-top:1.25rem;text-align:center">Pay with Card</a>
    </div>
    <?php endif; ?>
    <?php if ($vbEnabled): ?>
    <div class="dep-method-card">
        <div class="dep-method-icon">🏦</div>
        <div class="dep-method-title">Virtual Bank Account</div>
        <div class="dep-method-desc">Transfer any amount to your unique virtual account. Credited automatically.</div>
        <a href="#virtual-bank" class="btn btn-secondary" style="width:100%;margin-top:1.25rem;text-align:center">View My Account</a>
    </div>
    <?php endif; ?>
    <?php if ($mtEnabled): ?>
    <div class="dep-method-card">
        <div class="dep-method-icon">📤</div>
        <div class="dep-method-title">Manual Bank Transfer</div>
        <div class="dep-method-desc">Transfer to our bank account and notify us. Credited after admin confirmation.</div>
        <?php if ($bankCharges > 0 || $depositFeePct > 0): ?>
        <div class="fee-note">⚠️ <?= $bankCharges > 0 ? $currSym . number_format($bankCharges,2) . ' charge' : '' ?><?= ($bankCharges > 0 && $depositFeePct > 0) ? ' + ' : '' ?><?= $depositFeePct > 0 ? $depositFeePct . '% fee' : '' ?> applies</div>
        <?php endif; ?>
        <a href="#manual-transfer" class="btn btn-secondary" style="width:100%;margin-top:1.25rem;text-align:center">Notify Transfer</a>
    </div>
    <?php endif; ?>
</div>

<!-- ── CARD PAYMENT (Payhub Inline Checkout) ──────────────────────────── -->
<?php if ($payhubEnabled && $payhubKey): ?>
<div class="card" id="card-payment" style="margin-bottom:1.5rem;scroll-margin-top:80px">
    <div class="card-header"><h3>💳 Card / Online Payment</h3></div>
    <div class="card-body">
        <?php if ($depositFeePct > 0): ?>
        <div class="alert alert-warning">⚠️ A <?= $depositFeePct ?>% processing fee will be added to your deposit amount.</div>
        <?php endif; ?>
        <div class="form-group" style="max-width:340px">
            <label>Amount to Deposit (<?= htmlspecialchars($currSym) ?>)</label>
            <input type="number" id="cardAmount" min="100" step="100" placeholder="e.g. 5000" style="font-size:1.25rem;font-weight:700">
            <?php if ($depositFeePct > 0): ?>
            <p class="form-hint" id="cardFeeNote">Fee: — &nbsp;|&nbsp; You receive: —</p>
            <?php endif; ?>
        </div>
        <button id="payhubPayBtn" class="btn btn-primary btn-lg" onclick="initPayhubCheckout()">
            🔒 Pay Now
        </button>
        <p style="font-size:.8rem;color:var(--text-muted);margin-top:.75rem">Secured by Payhub. Your card details are never stored.</p>
    </div>
</div>
<script>
function initPayhubCheckout() {
    const amount = parseFloat(document.getElementById('cardAmount').value);
    if (!amount || amount < 100) {
        alert('Please enter an amount of at least <?= htmlspecialchars($currSym) ?>100.');
        return;
    }
    const feeRate = <?= $depositFeePct ?> / 100;
    const fee     = Math.round(amount * feeRate * 100) / 100;
    const total   = amount + fee;
    const ref     = 'PH-' + Date.now() + '-<?= $userId ?>';

    // Payhub inline checkout
    if (typeof PayhubCheckout !== 'undefined') {
        PayhubCheckout({
            key:        '<?= htmlspecialchars($payhubKey, ENT_JS) ?>',
            email:      '<?= htmlspecialchars($user['email'] ?? '', ENT_JS) ?>',
            amount:     Math.round(total * 100), // kobo/smallest unit
            currency:   'NGN',
            ref:        ref,
            metadata:   { user_id: <?= $userId ?>, net_amount: amount },
            callback:   function(resp) { verifyPayhubPayment(resp, amount, fee, ref); },
            onClose:    function() { console.log('Payhub checkout closed'); }
        });
    } else {
        // Fallback: redirect to Payhub hosted page
        window.open('https://payhub.datagifting.com.ng/pay?key=<?= urlencode($payhubKey) ?>&amount=' + Math.round(total*100) + '&ref=' + ref, '_blank');
    }
}

function verifyPayhubPayment(resp, netAmount, fee, ref) {
    fetch('/api/payhub-verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            reference: ref,
            transaction_id: resp.reference || resp.trxref,
            net_amount: netAmount,
            fee: fee
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = '/deposit.php?success=1';
        } else {
            alert('Payment verification failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(() => {
        alert('Payment verification error. Please contact support with reference: ' + ref);
    });
}

<?php if ($depositFeePct > 0): ?>
document.getElementById('cardAmount').addEventListener('input', function() {
    const amt = parseFloat(this.value) || 0;
    const fee = Math.round(amt * <?= $depositFeePct ?> / 100 * 100) / 100;
    document.getElementById('cardFeeNote').textContent =
        'Fee: <?= htmlspecialchars($currSym) ?>' + fee.toFixed(2) + '  |  You receive: <?= htmlspecialchars($currSym) ?>' + (amt).toFixed(2);
});
<?php endif; ?>
</script>
<!-- Load Payhub inline JS (loads dynamically) -->
<script>
(function() {
    var s = document.createElement('script');
    s.src = 'https://payhub.datagifting.com.ng/js/inline.js';
    s.async = true;
    document.head.appendChild(s);
})();
</script>
<?php endif; ?>

<!-- ── VIRTUAL BANK ACCOUNT ─────────────────────────────────────────────── -->
<?php if ($vbEnabled): ?>
<div class="card" id="virtual-bank" style="margin-bottom:1.5rem;scroll-margin-top:80px">
    <div class="card-header">
        <h3>🏦 Virtual Bank Account</h3>
        <form method="POST" action="/deposit.php" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="resync_virtual_bank">
            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Regenerate your virtual bank account? Your previous account may be deactivated.')">
                🔄 Resync Account
            </button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($vba): ?>
        <div class="vba-box">
            <p style="margin-bottom:1.25rem;color:var(--text-muted);font-size:.9rem">Transfer any amount to this account and your wallet will be credited automatically.</p>
            <div style="margin-bottom:1rem">
                <div class="vba-label">Account Number</div>
                <div class="vba-acct"><?= htmlspecialchars($vba['account_number']) ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div>
                    <div class="vba-label">Account Name</div>
                    <div class="vba-meta"><?= htmlspecialchars($vba['account_name']) ?></div>
                </div>
                <div>
                    <div class="vba-label">Bank</div>
                    <div class="vba-meta"><?= htmlspecialchars($vba['bank_name']) ?></div>
                </div>
            </div>
            <p style="font-size:.78rem;color:var(--text-muted);margin-top:1rem">Last synced: <?= htmlspecialchars(date('M j, Y H:i', strtotime($vba['updated_at']))) ?></p>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:2.5rem">
            <div style="font-size:3rem;margin-bottom:1rem">🏦</div>
            <h3 style="margin-bottom:.5rem">No Virtual Bank Account</h3>
            <p style="color:var(--text-muted);margin-bottom:1.5rem">Click "Resync Account" to generate your unique virtual bank account number.</p>
            <form method="POST" action="/deposit.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="resync_virtual_bank">
                <button type="submit" class="btn btn-primary">🔄 Generate Virtual Bank Account</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── MANUAL BANK TRANSFER ─────────────────────────────────────────────── -->
<?php if ($mtEnabled && $bankAccNum): ?>
<div class="card" id="manual-transfer" style="margin-bottom:1.5rem;scroll-margin-top:80px">
    <div class="card-header"><h3>📤 Manual Bank Transfer</h3></div>
    <div class="card-body">
        <div style="background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
            <h4 style="margin-bottom:1rem;font-size:.95rem">Transfer to:</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Account Name</div>
                    <div style="font-weight:700"><?= htmlspecialchars($bankAccName) ?></div>
                </div>
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Account Number</div>
                    <div style="font-weight:700;font-size:1.1rem;color:var(--accent)"><?= htmlspecialchars($bankAccNum) ?></div>
                </div>
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Bank</div>
                    <div style="font-weight:700"><?= htmlspecialchars($bankName) ?></div>
                </div>
            </div>
            <?php if ($bankNote): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--glass-border);font-size:.85rem;color:var(--text-muted)">
                <?= nl2br(htmlspecialchars($bankNote)) ?>
            </div>
            <?php endif; ?>
            <?php if ($bankCharges > 0): ?>
            <div class="fee-note" style="margin-top:.75rem">
                ⚠️ A fixed charge of <?= htmlspecialchars($currSym) ?><?= number_format($bankCharges, 2) ?> applies to manual transfers.
                <?php if ($depositFeePct > 0): ?> Plus <?= $depositFeePct ?>% processing fee.<?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" action="/deposit.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="submit_manual_transfer">
            <div class="form-row">
                <div class="form-group">
                    <label>Amount Transferred (<?= htmlspecialchars($currSym) ?>) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="amount" id="mtAmount" min="100" step="100" placeholder="e.g. 5000" required>
                    <?php if ($bankCharges > 0 || $depositFeePct > 0): ?>
                    <p class="form-hint" id="mtFeeNote">Enter amount to see fees</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Payment Proof / Reference <span style="color:var(--danger)">*</span></label>
                <textarea name="proof" rows="3" placeholder="Paste your bank transfer receipt, transaction ID, or screenshot description..." required></textarea>
                <p class="form-hint">Include your bank transaction reference or any proof that helps us locate the payment.</p>
            </div>
            <button type="submit" class="btn btn-primary">📤 Submit Transfer Notification</button>
        </form>
    </div>
</div>
<script>
document.getElementById('mtAmount')?.addEventListener('input', function() {
    const amt = parseFloat(this.value) || 0;
    const fixed = <?= $bankCharges ?>;
    const feeRate = <?= $depositFeePct ?> / 100;
    const pctFee = Math.round(amt * feeRate * 100) / 100;
    const totalFee = fixed + pctFee;
    const net = Math.max(0, amt - totalFee);
    const n = document.getElementById('mtFeeNote');
    if (n) n.textContent = 'Fees: <?= htmlspecialchars($currSym) ?>' + totalFee.toFixed(2) + '  |  Credited to wallet: <?= htmlspecialchars($currSym) ?>' + net.toFixed(2);
});
</script>
<?php endif; ?>

<?php endif; // any method enabled ?>

<!-- ── DEPOSIT HISTORY ───────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.5rem">
    <div class="card-header"><h3>📋 Deposit History</h3></div>
    <?php if (empty($deposits)): ?>
    <p class="empty-state">No deposits yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Method</th>
                <th>Amount</th>
                <th>Fee</th>
                <th>Credited</th>
                <th>Status</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deposits as $dep):
            $methodLabel = [
                'payhub_card'      => '💳 Card',
                'virtual_bank'     => '🏦 Virtual Bank',
                'manual_transfer'  => '📤 Manual Transfer',
            ][$dep['method']] ?? $dep['method'];
        ?>
        <tr>
            <td><?= htmlspecialchars(date('M j, Y H:i', strtotime($dep['created_at']))) ?></td>
            <td><?= $methodLabel ?></td>
            <td><?= htmlspecialchars($currSym) ?><?= number_format((float)$dep['amount'], 2) ?></td>
            <td><?= htmlspecialchars($currSym) ?><?= number_format((float)$dep['fee'], 2) ?></td>
            <td><?= htmlspecialchars($currSym) ?><?= number_format((float)$dep['net_amount'], 2) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($dep['status']) ?>"><?= ucfirst($dep['status']) ?></span></td>
            <td><code style="font-size:.78rem"><?= htmlspecialchars($dep['reference'] ?? '—') ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php
if (isset($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.showToast) showToast('Deposit successful! Your wallet has been credited.', 'success', 5000);
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
