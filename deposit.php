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

// ── Ensure tables & columns exist ─────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS wallet_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        method VARCHAR(30) NOT NULL DEFAULT '',
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
try { $db->exec("ALTER TABLE wallet_deposits MODIFY COLUMN method VARCHAR(30) NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE wallet_deposits ADD COLUMN usd_amount DECIMAL(12,4) NULL DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE wallet_deposits ADD COLUMN exchange_rate DECIMAL(12,4) NULL DEFAULT NULL"); } catch (\Exception $e) {}

// ── Load settings ──────────────────────────────────────────────────────────
$settings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (\Exception $e) {}

$s = fn(string $k, string $d = '') => $settings[$k] ?? $d;

$currSym    = $s('currency_symbol', '&#8358;');
$payhubKey  = $s('payhub_api_key');
$fwPubKey   = $s('flutterwave_public_key');
$strPubKey  = $s('stripe_publishable_key');
$ppClientId = $s('paypal_client_id');
$ppMode     = $s('paypal_mode', 'sandbox');

$payhubEnabled = $s('payhub_enabled')          === '1';
$vbEnabled     = $s('virtual_bank_enabled')    === '1';
$fwEnabled     = $s('flutterwave_enabled')     === '1';
$ppEnabled     = $s('paypal_enabled')          === '1';
$strEnabled    = $s('stripe_enabled')          === '1';
$plEnabled     = $s('plisio_enabled')          === '1';
$mtEnabled     = $s('manual_transfer_enabled') === '1';

$depositFeePct = max(0, (float)$s('deposit_fee_percent', '0'));
$fwFeePct      = max(0, (float)$s('flutterwave_fee_percent', '1.4'));
$ppFeePct      = max(0, (float)$s('paypal_fee_percent', '3.49'));
$strFeePct     = max(0, (float)$s('stripe_fee_percent', '2.9'));
$plFeePct      = max(0, (float)$s('plisio_fee_percent', '0.5'));
$bankCharges   = (float)$s('bank_transfer_charges', '0');
$bankFeePct    = max(0, (float)$s('deposit_fee_percent', '0'));

$bankAccName = $s('bank_account_name');
$bankAccNum  = $s('bank_account_number');
$bankName    = $s('bank_name');
$bankNote    = $s('bank_transfer_note');

$anyEnabled = $payhubEnabled || $vbEnabled || $fwEnabled || $ppEnabled || $strEnabled || $plEnabled || $mtEnabled;

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

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/deposit.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'resync_virtual_bank') {
        if (!$vbEnabled || !$payhubKey) {
            setFlash('Virtual bank account is not currently available.', 'error');
            redirect('/deposit.php#virtual-bank');
        }
        try {
            $ch = curl_init('https://payhub.datagifting.com.ng/api/customer/create');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => json_encode([
                    'api_key' => $payhubKey, 'email' => $user['email'] ?? '',
                    'name' => $user['full_name'] ?? ($user['username'] ?? 'User'),
                    'phone' => $user['phone'] ?? '',
                    'customer_ref' => 'USR-' . $userId . '-' . time(),
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $custData = json_decode(curl_exec($ch) ?: '{}', true);
            curl_close($ch);
            $customerId = $custData['data']['customer_id'] ?? $custData['customer_id'] ?? null;

            $ch2 = curl_init('https://payhub.datagifting.com.ng/api/virtual-account/create');
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => json_encode([
                    'api_key' => $payhubKey, 'customer_id' => $customerId,
                    'email' => $user['email'] ?? '',
                    'name' => $user['full_name'] ?? ($user['username'] ?? 'User'),
                    'bvn' => '',
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $vbData = json_decode(curl_exec($ch2) ?: '{}', true);
            curl_close($ch2);

            $accountNum  = $vbData['data']['account_number'] ?? $vbData['account_number'] ?? null;
            $accountName = $vbData['data']['account_name']   ?? $vbData['account_name']   ?? ($user['full_name'] ?? 'User');
            $bankNm      = $vbData['data']['bank_name']       ?? $vbData['bank_name']       ?? 'Virtual Bank';

            if ($accountNum) {
                $db->prepare(
                    "INSERT INTO virtual_bank_accounts (user_id,account_name,account_number,bank_name,customer_id)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE account_name=?,account_number=?,bank_name=?,customer_id=?,updated_at=NOW()"
                )->execute([$userId,$accountName,$accountNum,$bankNm,$customerId,
                             $accountName,$accountNum,$bankNm,$customerId]);
                setFlash('Virtual bank account synced successfully!');
            } else {
                setFlash('Could not generate virtual bank account. Please try again or contact support.', 'error');
            }
        } catch (\Exception $e) {
            error_log('VBA resync error: ' . $e->getMessage());
            setFlash('Error connecting to payment gateway. Please try again.', 'error');
        }
        redirect('/deposit.php#virtual-bank');
    }

    if ($action === 'submit_manual_transfer') {
        if (!$mtEnabled) { setFlash('Manual transfer is not available.', 'error'); redirect('/deposit.php'); }
        $amount = max(0.0, (float)($_POST['amount'] ?? 0));
        $proof  = sanitize($_POST['proof'] ?? '');
        if ($amount < 100) { setFlash('Minimum deposit amount is ' . htmlspecialchars($currSym) . '100.', 'error'); redirect('/deposit.php#manual-transfer'); }
        if (!$proof)       { setFlash('Please provide payment proof or reference.', 'error'); redirect('/deposit.php#manual-transfer'); }
        try {
            $fee = $bankCharges + ($amount * $bankFeePct / 100);
            $net = max(0, $amount - $fee);
            $ref = 'MT-' . strtoupper(bin2hex(random_bytes(5)));
            $db->prepare(
                "INSERT INTO wallet_deposits (user_id,method,amount,fee,net_amount,status,reference,bank_transfer_proof)
                 VALUES (?,'manual_transfer',?,?,?,'pending',?,?)"
            )->execute([$userId, $amount, $fee, $net, $ref, $proof]);
            setFlash('Transfer notification submitted! Your deposit will be credited after admin approval.');
        } catch (\Exception $e) {
            error_log('Manual transfer submit error: ' . $e->getMessage());
            setFlash('Error submitting notification. Please try again.', 'error');
        }
        redirect('/deposit.php#manual-transfer');
    }
}

// ── Wallet balance ──────────────────────────────────────────────────────────
$walletBalance = 0.0;
try {
    $wb = $db->prepare("SELECT credits FROM user_sms_wallet WHERE user_id=?");
    $wb->execute([$userId]);
    $walletBalance = (float)($wb->fetchColumn() ?: 0.0);
} catch (\Exception $e) {}

// ── Virtual bank ────────────────────────────────────────────────────────────
$vba = null;
try {
    $vs = $db->prepare("SELECT * FROM virtual_bank_accounts WHERE user_id=? AND is_active=1");
    $vs->execute([$userId]);
    $vba = $vs->fetch() ?: null;
} catch (\Exception $e) {}

// ── Deposit history ──────────────────────────────────────────────────────────
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
.gw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.85rem;margin-bottom:1.75rem}
.gw-card{border:2px solid var(--glass-border);border-radius:14px;padding:1.1rem .9rem;text-align:center;cursor:pointer;background:var(--glass-bg);transition:border-color .2s,transform .15s,box-shadow .2s;user-select:none}
.gw-card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 6px 24px rgba(108,99,255,.18)}
.gw-card.selected{border-color:var(--accent);background:rgba(108,99,255,.12);box-shadow:0 0 0 2px rgba(108,99,255,.3)}
.gw-card-icon{font-size:2rem;margin-bottom:.4rem}
.gw-card-name{font-size:.82rem;font-weight:700;color:var(--text-primary)}
.gw-card-fee{font-size:.72rem;color:var(--text-muted);margin-top:.15rem}
.gw-card-badge{font-size:.65rem;padding:.15rem .45rem;border-radius:50px;background:rgba(16,185,129,.15);color:#10b981;font-weight:700;display:inline-block;margin-top:.25rem}
.gw-card-badge.usd{background:rgba(59,130,246,.15);color:#3b82f6}
#rateAlert{position:fixed;top:0;left:0;right:0;z-index:9999;background:linear-gradient(90deg,#f59e0b,#ef4444);color:#fff;text-align:center;padding:.7rem 1rem;font-size:.9rem;font-weight:600;transform:translateY(-100%);transition:transform .4s ease;box-shadow:0 4px 20px rgba(245,158,11,.4)}
#rateAlert.visible{transform:translateY(0)}
#rateAlert button{background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;padding:.2rem .6rem;cursor:pointer;margin-left:.75rem;font-size:.8rem}
.fx-calc{background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:14px;padding:1.4rem 1.5rem;margin-bottom:1.5rem}
.fx-rate-display{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem}
.fx-rate-pill{background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.25);border-radius:50px;padding:.3rem .9rem;font-size:.84rem;font-weight:600;color:var(--accent)}
.fx-rate-updated{font-size:.75rem;color:var(--text-muted)}
.fx-row{display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem}
.fx-col{flex:1;min-width:160px}
.fx-col label{font-size:.8rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em}
.fx-breakdown{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem;margin-top:1rem}
.fx-item{background:rgba(255,255,255,.04);border-radius:8px;padding:.55rem .8rem;font-size:.82rem}
.fx-item .label{font-size:.72rem;color:var(--text-muted);margin-bottom:.2rem}
.fx-item .value{font-weight:700;color:var(--text-primary)}
.fx-item.highlight .value{color:var(--accent)}
.pay-section{display:none;animation:fadeIn .3s ease}
.pay-section.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.vba-box{background:linear-gradient(135deg,rgba(108,99,255,.12),rgba(0,212,255,.08));border:1px solid rgba(108,99,255,.3);border-radius:16px;padding:1.75rem}
.vba-acct{font-size:2rem;font-weight:900;letter-spacing:.05em;color:var(--accent)}
.vba-label{font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem}
.vba-meta{font-size:.95rem;font-weight:600;margin-bottom:.15rem}
.fee-note{font-size:.78rem;color:var(--text-muted);background:rgba(255,165,2,.06);border:1px solid rgba(255,165,2,.2);border-radius:8px;padding:.6rem .9rem;margin-top:.75rem}
#stripe-card-element{background:var(--bg-secondary);border:1px solid var(--glass-border);border-radius:10px;padding:.9rem 1rem;transition:border-color .2s}
#stripe-card-errors{color:#ef4444;font-size:.82rem;margin-top:.4rem;min-height:1.2em}
</style>

<div id="rateAlert">
    <span id="rateAlertText">Exchange rate updated!</span>
    <button onclick="dismissRateAlert()">Dismiss</button>
</div>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1>&#128176; Deposit Funds</h1>
            <p>Add money to your wallet. Balance: <strong><?php echo htmlspecialchars($currSym); ?><?php echo number_format($walletBalance, 2); ?></strong></p>
        </div>
        <a href="/billing.php" class="btn btn-secondary">&larr; Back to Billing</a>
    </div>
</div>

<?php $flash = popFlash(); if ($flash['msg']): ?>
<div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>">
    <?php echo htmlspecialchars($flash['msg']); ?>
</div>
<?php endif; ?>

<?php if (!$anyEnabled): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:1rem">&#128295;</div>
    <h3>No payment methods are currently enabled</h3>
    <p style="color:var(--text-muted);margin-top:.5rem">Please contact an administrator to enable deposit options.</p>
</div></div>
<?php else: ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>Select Payment Method</h3></div>
    <div class="card-body">
        <div class="gw-grid">
            <?php if ($payhubEnabled && $payhubKey): ?>
            <div class="gw-card" onclick="switchGateway('payhub_card')" id="gwc-payhub_card">
                <div class="gw-card-icon">&#128179;</div>
                <div class="gw-card-name">Card (Payhub)</div>
                <div class="gw-card-fee"><?php echo $depositFeePct > 0 ? $depositFeePct.'% fee' : 'No fee'; ?></div>
                <div class="gw-card-badge">NGN</div>
            </div>
            <?php endif; ?>
            <?php if ($vbEnabled): ?>
            <div class="gw-card" onclick="switchGateway('virtual_bank')" id="gwc-virtual_bank">
                <div class="gw-card-icon">&#127970;</div>
                <div class="gw-card-name">Virtual Bank</div>
                <div class="gw-card-fee">Auto credit</div>
                <div class="gw-card-badge">NGN</div>
            </div>
            <?php endif; ?>
            <?php if ($fwEnabled && $fwPubKey): ?>
            <div class="gw-card" onclick="switchGateway('flutterwave')" id="gwc-flutterwave">
                <div class="gw-card-icon">&#129419;</div>
                <div class="gw-card-name">Flutterwave</div>
                <div class="gw-card-fee"><?php echo $fwFeePct; ?>% fee</div>
                <div class="gw-card-badge">NGN</div>
            </div>
            <?php endif; ?>
            <?php if ($ppEnabled && $ppClientId): ?>
            <div class="gw-card" onclick="switchGateway('paypal')" id="gwc-paypal">
                <div class="gw-card-icon">&#127827;</div>
                <div class="gw-card-name">PayPal</div>
                <div class="gw-card-fee"><?php echo $ppFeePct; ?>% fee</div>
                <div class="gw-card-badge usd">USD</div>
            </div>
            <?php endif; ?>
            <?php if ($strEnabled && $strPubKey): ?>
            <div class="gw-card" onclick="switchGateway('stripe')" id="gwc-stripe">
                <div class="gw-card-icon">&#9889;</div>
                <div class="gw-card-name">Stripe</div>
                <div class="gw-card-fee"><?php echo $strFeePct; ?>% fee</div>
                <div class="gw-card-badge usd">USD</div>
            </div>
            <?php endif; ?>
            <?php if ($plEnabled): ?>
            <div class="gw-card" onclick="switchGateway('plisio')" id="gwc-plisio">
                <div class="gw-card-icon">&#8383;</div>
                <div class="gw-card-name">Crypto (Plisio)</div>
                <div class="gw-card-fee"><?php echo $plFeePct; ?>% fee</div>
                <div class="gw-card-badge usd">USD&#8594;Crypto</div>
            </div>
            <?php endif; ?>
            <?php if ($mtEnabled && $bankAccNum): ?>
            <div class="gw-card" onclick="switchGateway('manual_transfer')" id="gwc-manual_transfer">
                <div class="gw-card-icon">&#128228;</div>
                <div class="gw-card-name">Bank Transfer</div>
                <div class="gw-card-fee"><?php echo $bankCharges > 0 ? htmlspecialchars($currSym).number_format($bankCharges,0).' + ' : ''; ?><?php echo $bankFeePct > 0 ? $bankFeePct.'%' : 'No fee'; ?></div>
                <div class="gw-card-badge">NGN</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- FX Calculator (shown for USD gateways) -->
<div class="fx-calc" id="fxCalcBox" style="display:none">
    <div class="fx-rate-display">
        <span style="font-weight:700;color:var(--text-primary)">&#128178; Live Exchange Rate</span>
        <span class="fx-rate-pill" id="fxRatePill">Loading...</span>
        <span class="fx-rate-updated" id="fxRateAge"></span>
        <span id="fxLoadingSpinner" style="font-size:.8rem;color:var(--text-muted)">&#8635; Fetching...</span>
    </div>
    <div class="fx-row">
        <div class="fx-col">
            <label>Amount (<?php echo htmlspecialchars($currSym); ?>NGN)</label>
            <input type="number" id="fxNgnInput" min="1000" step="500" placeholder="e.g. 50000" oninput="recalcFx()" style="font-size:1.2rem;font-weight:700">
        </div>
        <div class="fx-col">
            <label>You Pay (USD)</label>
            <input type="text" id="fxUsdDisplay" readonly placeholder="$0.00" style="font-size:1.2rem;font-weight:700;background:var(--bg-secondary)">
        </div>
    </div>
    <div class="fx-breakdown">
        <div class="fx-item"><div class="label">Exchange Rate</div><div class="value" id="fxRate">&#8212;</div></div>
        <div class="fx-item"><div class="label">Gateway Fee</div><div class="value" id="fxFeeUsd">&#8212;</div></div>
        <div class="fx-item"><div class="label">Total USD Charged</div><div class="value" id="fxTotalUsd">&#8212;</div></div>
        <div class="fx-item highlight"><div class="label">Credited to Wallet (NGN)</div><div class="value" id="fxNetNgn">&#8212;</div></div>
    </div>
    <p style="font-size:.75rem;color:var(--text-muted);margin-top:.75rem">
        &#9889; Exchange rates update every 30 seconds. Your wallet is always credited in <?php echo htmlspecialchars($currSym); ?>NGN.
    </p>
</div>

<!-- PAYHUB CARD SECTION -->
<?php if ($payhubEnabled && $payhubKey): ?>
<div class="card pay-section" id="sec-payhub_card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#128179; Pay with Card (Payhub)</h3></div>
    <div class="card-body">
        <?php if ($depositFeePct > 0): ?><div class="alert alert-warning" style="margin-bottom:1rem">&#9888; A <?php echo $depositFeePct; ?>% processing fee will be added.</div><?php endif; ?>
        <div class="form-group" style="max-width:320px">
            <label>Amount to Deposit (<?php echo htmlspecialchars($currSym); ?>)</label>
            <input type="number" id="payhubAmount" min="100" step="100" placeholder="e.g. 5000" oninput="updatePayhubFee()" style="font-size:1.2rem;font-weight:700">
            <p class="form-hint" id="payhubFeeNote" style="display:none"></p>
        </div>
        <button class="btn btn-primary btn-lg" onclick="initPayhubCheckout()">&#128274; Pay Now</button>
        <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">Secured by Payhub. Your card details are never stored.</p>
    </div>
</div>
<script>
var _payhubFeePct = <?php echo (float)$depositFeePct; ?>;
var _currSym = <?php echo json_encode($currSym); ?>;
function updatePayhubFee() {
    var amt = parseFloat(document.getElementById('payhubAmount').value) || 0;
    var fee = Math.round(amt * _payhubFeePct / 100 * 100) / 100;
    var note = document.getElementById('payhubFeeNote');
    if (_payhubFeePct > 0 && amt > 0) {
        note.style.display = '';
        note.textContent = 'Fee: ' + _currSym + fee.toFixed(2) + '  |  You receive: ' + _currSym + amt.toFixed(2);
    } else { note.style.display = 'none'; }
}
function initPayhubCheckout() {
    var amt = parseFloat(document.getElementById('payhubAmount').value);
    if (!amt || amt < 100) { alert('Please enter an amount of at least ' + _currSym + '100.'); return; }
    var fee = Math.round(amt * _payhubFeePct / 100 * 100) / 100;
    var total = amt + fee;
    var ref = 'PH-' + Date.now() + '-<?php echo $userId; ?>';
    if (typeof PayhubCheckout !== 'undefined') {
        PayhubCheckout({
            key: <?php echo json_encode($payhubKey); ?>,
            email: <?php echo json_encode($user['email'] ?? ''); ?>,
            amount: Math.round(total * 100),
            currency: 'NGN',
            ref: ref,
            callback: function(resp) { verifyPayhubPayment(resp, amt, fee, ref); },
            onClose: function() {}
        });
    } else { alert('Payment gateway is loading, please try again in a moment.'); }
}
function verifyPayhubPayment(resp, netAmount, fee, ref) {
    fetch('/api/payhub-verify.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ reference: ref, transaction_id: resp.reference || resp.trxref, net_amount: netAmount, fee: fee })
    }).then(function(r){ return r.json(); }).then(function(data){
        if (data.success) { window.location.href = '/deposit.php?success=1'; }
        else { alert('Verification failed: ' + (data.message || 'Unknown error')); }
    }).catch(function(){ alert('Verification error. Contact support with ref: ' + ref); });
}
</script>
<script async src="https://payhub.datagifting.com.ng/js/inline.js"></script>
<?php endif; ?>

<!-- VIRTUAL BANK SECTION -->
<?php if ($vbEnabled): ?>
<div class="card pay-section" id="sec-virtual_bank" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3>&#127970; Virtual Bank Account</h3>
        <form method="POST" action="/deposit.php" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
            <input type="hidden" name="action" value="resync_virtual_bank">
            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Regenerate your virtual bank account? The old account may be deactivated.')">
                &#128260; Resync Account
            </button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($vba): ?>
        <div class="vba-box">
            <p style="margin-bottom:1.25rem;color:var(--text-muted);font-size:.9rem">Transfer any amount to this account — your wallet is credited automatically.</p>
            <div style="margin-bottom:1rem">
                <div class="vba-label">Account Number</div>
                <div class="vba-acct"><?php echo htmlspecialchars($vba['account_number']); ?></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div><div class="vba-label">Account Name</div><div class="vba-meta"><?php echo htmlspecialchars($vba['account_name']); ?></div></div>
                <div><div class="vba-label">Bank</div><div class="vba-meta"><?php echo htmlspecialchars($vba['bank_name']); ?></div></div>
            </div>
            <p style="font-size:.75rem;color:var(--text-muted);margin-top:1rem">Last synced: <?php echo htmlspecialchars(date('M j, Y H:i', strtotime($vba['updated_at']))); ?></p>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:2.5rem">
            <div style="font-size:3rem;margin-bottom:1rem">&#127970;</div>
            <h3 style="margin-bottom:.5rem">No Virtual Bank Account Yet</h3>
            <p style="color:var(--text-muted);margin-bottom:1.5rem">Click below to generate your unique virtual bank account.</p>
            <form method="POST" action="/deposit.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                <input type="hidden" name="action" value="resync_virtual_bank">
                <button type="submit" class="btn btn-primary">&#128260; Generate Virtual Bank Account</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- FLUTTERWAVE SECTION -->
<?php if ($fwEnabled && $fwPubKey): ?>
<div class="card pay-section" id="sec-flutterwave" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#129419; Pay with Flutterwave</h3></div>
    <div class="card-body">
        <?php if ($fwFeePct > 0): ?><div class="alert alert-warning" style="margin-bottom:1rem">&#9888; A <?php echo $fwFeePct; ?>% processing fee will be added.</div><?php endif; ?>
        <div class="form-group" style="max-width:320px">
            <label>Amount to Deposit (<?php echo htmlspecialchars($currSym); ?>)</label>
            <input type="number" id="fwAmount" min="100" step="100" placeholder="e.g. 5000" oninput="updateFwFee()" style="font-size:1.2rem;font-weight:700">
            <p class="form-hint" id="fwFeeNote" style="display:none"></p>
        </div>
        <button class="btn btn-primary btn-lg" onclick="initFlutterwaveCheckout()">&#129419; Pay with Flutterwave</button>
        <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">Accepts cards, bank transfers and USSD in NGN.</p>
    </div>
</div>
<script>
var _fwFeePct = <?php echo (float)$fwFeePct; ?>;
function updateFwFee() {
    var amt = parseFloat(document.getElementById('fwAmount').value) || 0;
    var fee = Math.round(amt * _fwFeePct / 100 * 100) / 100;
    var note = document.getElementById('fwFeeNote');
    if (_fwFeePct > 0 && amt > 0) {
        note.style.display = '';
        note.textContent = 'Fee: ' + _currSym + fee.toFixed(2) + '  |  You receive: ' + _currSym + amt.toFixed(2);
    } else { note.style.display = 'none'; }
}
function initFlutterwaveCheckout() {
    var amt = parseFloat(document.getElementById('fwAmount').value);
    if (!amt || amt < 100) { alert('Please enter an amount of at least ' + _currSym + '100.'); return; }
    var fee = Math.round(amt * _fwFeePct / 100 * 100) / 100;
    var total = Math.round((amt + fee) * 100) / 100;
    var ref = 'FW-' + Date.now() + '-<?php echo $userId; ?>';
    if (typeof FlutterwaveCheckout === 'undefined') { alert('Flutterwave is loading, please try again.'); return; }
    FlutterwaveCheckout({
        public_key: <?php echo json_encode($fwPubKey); ?>,
        tx_ref: ref,
        amount: total,
        currency: 'NGN',
        payment_options: 'card,banktransfer,ussd',
        customer: { email: <?php echo json_encode($user['email'] ?? ''); ?>, name: <?php echo json_encode($user['full_name'] ?? ($user['username'] ?? 'User')); ?> },
        customizations: { title: 'Wallet Deposit', description: 'Add funds to your wallet', logo: '' },
        callback: function(data) {
            if (data.status === 'successful') {
                fetch('/api/flutterwave-verify.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ transaction_id: data.transaction_id, reference: ref, net_amount: amt, fee: fee })
                }).then(function(r){ return r.json(); }).then(function(d){
                    if (d.success) window.location.href = '/deposit.php?success=1';
                    else alert('Verification failed: ' + (d.message || 'Error'));
                });
            } else { alert('Payment was not completed.'); }
        },
        onclose: function() {}
    });
}
</script>
<script async src="https://checkout.flutterwave.com/v3.js"></script>
<?php endif; ?>

<!-- PAYPAL SECTION -->
<?php if ($ppEnabled && $ppClientId): ?>
<div class="card pay-section" id="sec-paypal" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#127827; Pay with PayPal</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:1rem">
            &#128178; Charged in <strong>USD</strong>. Use the FX Calculator above to see how much you pay. Wallet credited in <?php echo htmlspecialchars($currSym); ?>NGN.
        </div>
        <div class="form-group" style="max-width:320px;margin-bottom:1.25rem">
            <label>NGN Amount to Deposit</label>
            <input type="number" id="ppNgnInput" min="1000" step="500" placeholder="e.g. 50000"
                   oninput="syncFxFromGateway('paypal')" style="font-size:1.2rem;font-weight:700">
        </div>
        <div id="paypal-button-container" style="max-width:400px"></div>
        <p id="ppLoadingMsg" style="font-size:.85rem;color:var(--text-muted)">Enter amount above then click the PayPal button.</p>
    </div>
</div>
<?php endif; ?>

<!-- STRIPE SECTION -->
<?php if ($strEnabled && $strPubKey): ?>
<div class="card pay-section" id="sec-stripe" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#9889; Pay with Stripe</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:1rem">
            &#128178; Charged in <strong>USD</strong>. Use the FX Calculator above. Wallet credited in <?php echo htmlspecialchars($currSym); ?>NGN.
        </div>
        <div class="form-group" style="max-width:320px;margin-bottom:1.25rem">
            <label>NGN Amount to Deposit</label>
            <input type="number" id="strNgnInput" min="1000" step="500" placeholder="e.g. 50000"
                   oninput="syncFxFromGateway('stripe')" style="font-size:1.2rem;font-weight:700">
        </div>
        <div class="form-group" style="max-width:420px">
            <label>Card Details</label>
            <div id="stripe-card-element"></div>
            <div id="stripe-card-errors"></div>
        </div>
        <button class="btn btn-primary btn-lg" id="stripePayBtn" onclick="initStripePayment()" disabled>&#9889; Pay with Stripe</button>
        <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">&#128274; Secured by Stripe. Card details never touch our servers.</p>
    </div>
</div>
<?php endif; ?>

<!-- PLISIO SECTION -->
<?php if ($plEnabled): ?>
<div class="card pay-section" id="sec-plisio" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#8383; Pay with Cryptocurrency (Plisio)</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:1rem">
            &#128178; Invoiced in <strong>USD</strong>, paid in crypto. Use the FX Calculator above. Wallet credited in <?php echo htmlspecialchars($currSym); ?>NGN after payment.
        </div>
        <div class="form-group" style="max-width:320px;margin-bottom:1.25rem">
            <label>NGN Amount to Deposit</label>
            <input type="number" id="plNgnInput" min="1000" step="500" placeholder="e.g. 50000"
                   oninput="syncFxFromGateway('plisio')" style="font-size:1.2rem;font-weight:700">
        </div>
        <button class="btn btn-primary btn-lg" onclick="initPlisioCheckout()">&#8383; Pay with Crypto</button>
        <p style="font-size:.78rem;color:var(--text-muted);margin-top:.75rem">You will be redirected to Plisio's secure crypto payment page.</p>
    </div>
</div>
<?php endif; ?>

<!-- MANUAL BANK TRANSFER SECTION -->
<?php if ($mtEnabled && $bankAccNum): ?>
<div class="card pay-section" id="sec-manual_transfer" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>&#128228; Manual Bank Transfer</h3></div>
    <div class="card-body">
        <div style="background:var(--glass-bg);border:1px solid var(--glass-border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
            <h4 style="margin-bottom:1rem;font-size:.95rem">Transfer to:</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Account Name</div>
                    <div style="font-weight:700"><?php echo htmlspecialchars($bankAccName); ?></div>
                </div>
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Account Number</div>
                    <div style="font-weight:700;font-size:1.1rem;color:var(--accent)"><?php echo htmlspecialchars($bankAccNum); ?></div>
                </div>
                <div>
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem">Bank</div>
                    <div style="font-weight:700"><?php echo htmlspecialchars($bankName); ?></div>
                </div>
            </div>
            <?php if ($bankNote): ?>
            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--glass-border);font-size:.85rem;color:var(--text-muted)"><?php echo nl2br(htmlspecialchars($bankNote)); ?></div>
            <?php endif; ?>
            <?php if ($bankCharges > 0 || $bankFeePct > 0): ?>
            <div class="fee-note">&#9888; <?php echo $bankCharges > 0 ? htmlspecialchars($currSym).number_format($bankCharges,2).' fixed charge' : ''; ?><?php echo ($bankCharges > 0 && $bankFeePct > 0) ? ' + ' : ''; ?><?php echo $bankFeePct > 0 ? $bankFeePct.'% fee' : ''; ?> applies.</div>
            <?php endif; ?>
        </div>
        <form method="POST" action="/deposit.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
            <input type="hidden" name="action" value="submit_manual_transfer">
            <div class="form-group" style="max-width:320px">
                <label>Amount Transferred (<?php echo htmlspecialchars($currSym); ?>) *</label>
                <input type="number" name="amount" id="mtAmount" min="100" step="100" placeholder="e.g. 5000" required oninput="updateMtFee()">
                <p class="form-hint" id="mtFeeNote" style="display:none"></p>
            </div>
            <div class="form-group">
                <label>Payment Proof / Reference *</label>
                <textarea name="proof" rows="3" placeholder="Bank transaction reference, receipt number..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">&#128228; Submit Transfer Notification</button>
        </form>
    </div>
</div>
<script>
var _bankCharges = <?php echo (float)$bankCharges; ?>;
var _bankFeePct  = <?php echo (float)$bankFeePct; ?>;
function updateMtFee() {
    var amt = parseFloat(document.getElementById('mtAmount').value) || 0;
    var fee = Math.round((_bankCharges + amt * _bankFeePct / 100) * 100) / 100;
    var net = Math.max(0, amt - fee);
    var note = document.getElementById('mtFeeNote');
    if (amt > 0) {
        note.style.display = '';
        note.textContent = 'Total fees: ' + _currSym + fee.toFixed(2) + '  |  Credited: ' + _currSym + net.toFixed(2);
    } else { note.style.display = 'none'; }
}
</script>
<?php endif; ?>

<?php endif; // $anyEnabled ?>

<!-- DEPOSIT HISTORY -->
<div class="card" style="margin-top:1.5rem">
    <div class="card-header"><h3>&#128203; Deposit History</h3></div>
    <?php if (empty($deposits)): ?>
    <p class="empty-state">No deposits yet.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr><th>Date</th><th>Method</th><th>Amount</th><th>Fee</th><th>Credited</th><th>Status</th><th>Reference</th></tr>
        </thead>
        <tbody>
        <?php
        $methodLabels = [
            'payhub_card'     => '&#128179; Card',
            'virtual_bank'    => '&#127970; Virtual Bank',
            'flutterwave'     => '&#129419; Flutterwave',
            'paypal'          => '&#127827; PayPal',
            'stripe'          => '&#9889; Stripe',
            'plisio'          => '&#8383; Crypto',
            'manual_transfer' => '&#128228; Manual Transfer',
        ];
        foreach ($deposits as $dep):
            $methodLabel = $methodLabels[$dep['method']] ?? htmlspecialchars($dep['method']);
            $statusBadge = ['pending'=>'warning','completed'=>'success','failed'=>'danger','rejected'=>'danger'][$dep['status']] ?? 'secondary';
        ?>
        <tr>
            <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($dep['created_at']))); ?></td>
            <td><?php echo $methodLabel; ?></td>
            <td>
                <?php echo htmlspecialchars($currSym); ?><?php echo number_format((float)$dep['amount'], 2); ?>
                <?php if (!empty($dep['usd_amount'])): ?>
                <br><small style="color:var(--text-muted)">($<?php echo number_format((float)$dep['usd_amount'], 2); ?> USD)</small>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($currSym); ?><?php echo number_format((float)$dep['fee'], 2); ?></td>
            <td><?php echo htmlspecialchars($currSym); ?><?php echo number_format((float)$dep['net_amount'], 2); ?></td>
            <td><span class="badge badge-<?php echo $statusBadge; ?>"><?php echo ucfirst($dep['status']); ?></span></td>
            <td><code style="font-size:.76rem"><?php echo htmlspecialchars($dep['reference'] ?? '&#8212;'); ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- MAIN JAVASCRIPT -->
<script>
var GATEWAY_FEES = {
    paypal: <?php echo (float)$ppFeePct; ?>,
    stripe: <?php echo (float)$strFeePct; ?>,
    plisio: <?php echo (float)$plFeePct; ?>
};
var _currSym = <?php echo json_encode($currSym); ?>;
var currentGateway   = null;
var liveRate         = null;
var rateAlertArmed   = true;
var stripeInstance   = null;
var stripeCard       = null;
var paypalRendered   = false;

function switchGateway(gw) {
    document.querySelectorAll('.gw-card').forEach(function(c){ c.classList.remove('selected'); });
    var card = document.getElementById('gwc-' + gw);
    if (card) card.classList.add('selected');
    document.querySelectorAll('.pay-section').forEach(function(s){ s.classList.remove('active'); });
    var sec = document.getElementById('sec-' + gw);
    if (sec) { sec.classList.add('active'); sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    currentGateway = gw;
    var usdGateways = ['paypal','stripe','plisio'];
    var fxBox = document.getElementById('fxCalcBox');
    if (usdGateways.indexOf(gw) !== -1) {
        fxBox.style.display = '';
        fxBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        if (!liveRate) fetchRate();
    } else { fxBox.style.display = 'none'; }
    if (gw === 'stripe') initStripeElements();
    if (gw === 'paypal' && !paypalRendered) renderPayPalButtons();
}

function syncFxFromGateway(gw) {
    var map = { paypal: 'ppNgnInput', stripe: 'strNgnInput', plisio: 'plNgnInput' };
    var el = document.getElementById(map[gw]);
    if (el && el.value) {
        document.getElementById('fxNgnInput').value = el.value;
        recalcFx();
    }
}

function recalcFx() {
    var ngn = parseFloat(document.getElementById('fxNgnInput').value) || 0;
    if (!liveRate || ngn <= 0) return;
    var gw      = currentGateway;
    var feePct  = GATEWAY_FEES[gw] || 0;
    var usdRaw  = ngn / liveRate;
    var usdFee  = usdRaw * feePct / 100;
    var usdTotal= usdRaw + usdFee;
    var ngnFee  = usdFee * liveRate;
    var netNgn  = Math.max(0, ngn - ngnFee);
    document.getElementById('fxUsdDisplay').value   = '$' + usdTotal.toFixed(2);
    document.getElementById('fxRate').textContent    = _currSym + liveRate.toFixed(2) + ' / $1';
    document.getElementById('fxFeeUsd').textContent  = feePct + '% ($' + usdFee.toFixed(4) + ')';
    document.getElementById('fxTotalUsd').textContent= '$' + usdTotal.toFixed(2);
    document.getElementById('fxNetNgn').textContent  = _currSym + netNgn.toFixed(2);
    var inputMap = { paypal: 'ppNgnInput', stripe: 'strNgnInput', plisio: 'plNgnInput' };
    if (inputMap[gw]) document.getElementById(inputMap[gw]).value = ngn;
}

function getFxValues(gw) {
    var ngn = parseFloat(document.getElementById('fxNgnInput').value) || 0;
    if (!liveRate || ngn <= 0) return null;
    var feePct  = GATEWAY_FEES[gw] || 0;
    var usdRaw  = ngn / liveRate;
    var usdFee  = usdRaw * feePct / 100;
    var usdTotal= usdRaw + usdFee;
    var ngnFee  = usdFee * liveRate;
    var netNgn  = Math.max(0, ngn - ngnFee);
    return { ngn: ngn, usdAmount: usdRaw, usdFee: usdFee, usdTotal: usdTotal, ngnFee: ngnFee, netNgn: netNgn, exchangeRate: liveRate, feePct: feePct };
}

function fetchRate() {
    var spinner = document.getElementById('fxLoadingSpinner');
    spinner.style.display = '';
    fetch('/api/exchange-rate.php').then(function(r){ return r.json(); }).then(function(data){
        spinner.style.display = 'none';
        if (!data.success) return;
        var newRate = data.display_rate;
        if (liveRate !== null && rateAlertArmed) {
            var changePct = Math.abs(newRate - liveRate) / liveRate * 100;
            var ngnInput  = parseFloat(document.getElementById('fxNgnInput').value) || 0;
            if (changePct >= 0.5 && ngnInput > 0) {
                var dir = newRate > liveRate ? 'increased' : 'decreased';
                document.getElementById('rateAlertText').textContent =
                    '\u26A0\uFE0F Exchange rate ' + dir + ' to ' + _currSym + newRate.toFixed(2) +
                    ' per $1 USD. Amount has been recalculated.';
                document.getElementById('rateAlert').classList.add('visible');
                setTimeout(dismissRateAlert, 8000);
            }
        }
        liveRate = newRate;
        document.getElementById('fxRatePill').textContent = data.formatted;
        var ageText = data.source === 'live' ? 'just now' : Math.round(data.cache_age / 60) + 'm ago';
        document.getElementById('fxRateAge').textContent = 'Updated ' + ageText;
        recalcFx();
    }).catch(function(){
        spinner.style.display = 'none';
    });
}

function dismissRateAlert() {
    document.getElementById('rateAlert').classList.remove('visible');
    rateAlertArmed = false;
    setTimeout(function(){ rateAlertArmed = true; }, 30000);
}

setInterval(fetchRate, 30000);
</script>

<!-- Stripe JS + init -->
<?php if ($strEnabled && $strPubKey): ?>
<script>
function initStripeElements() {
    if (stripeInstance) return;
    if (typeof Stripe === 'undefined') { setTimeout(initStripeElements, 500); return; }
    stripeInstance = Stripe(<?php echo json_encode($strPubKey); ?>);
    var elements = stripeInstance.elements();
    stripeCard = elements.create('card', {
        style: { base: { fontSize: '16px', color: '#e2e8f0' }, invalid: { color: '#ef4444' } }
    });
    stripeCard.mount('#stripe-card-element');
    stripeCard.on('change', function(e) {
        document.getElementById('stripe-card-errors').textContent = e.error ? e.error.message : '';
        document.getElementById('stripePayBtn').disabled = !e.complete;
    });
}
async function initStripePayment() {
    var fx = getFxValues('stripe');
    if (!fx || fx.ngn < 1000) { alert('Please enter a valid NGN amount (min ' + _currSym + '1,000).'); return; }
    var btn = document.getElementById('stripePayBtn');
    btn.disabled = true; btn.textContent = 'Processing...';
    try {
        var r = await fetch('/api/stripe-intent.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ usd_amount: fx.usdTotal, ngn_amount: fx.ngn, exchange_rate: fx.exchangeRate, fee: fx.ngnFee, net_ngn: fx.netNgn })
        });
        var data = await r.json();
        if (!data.success) { alert('Error: ' + data.message); btn.disabled = false; btn.textContent = '\u26A1 Pay with Stripe'; return; }
        var result = await stripeInstance.confirmCardPayment(data.client_secret, { payment_method: { card: stripeCard } });
        if (result.error) {
            document.getElementById('stripe-card-errors').textContent = result.error.message;
            btn.disabled = false; btn.textContent = '\u26A1 Pay with Stripe'; return;
        }
        var vr = await fetch('/api/stripe-verify.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ payment_intent_id: result.paymentIntent.id, reference: data.reference })
        });
        var vd = await vr.json();
        if (vd.success) { window.location.href = '/deposit.php?success=1'; }
        else { alert('Verification error: ' + vd.message); btn.disabled = false; btn.textContent = '\u26A1 Pay with Stripe'; }
    } catch(e) { alert('Network error. Please try again.'); btn.disabled = false; btn.textContent = '\u26A1 Pay with Stripe'; }
}
(function(){
    var s = document.createElement('script');
    s.src = 'https://js.stripe.com/v3/';
    s.onload = function(){ if (currentGateway === 'stripe') initStripeElements(); };
    document.head.appendChild(s);
})();
</script>
<?php endif; ?>

<!-- PayPal JS SDK + buttons -->
<?php if ($ppEnabled && $ppClientId): ?>
<script>
function renderPayPalButtons() {
    if (typeof paypal === 'undefined') { setTimeout(renderPayPalButtons, 600); return; }
    paypalRendered = true;
    var container = document.getElementById('paypal-button-container');
    if (!container) return;
    container.innerHTML = '';
    var ppMsg = document.getElementById('ppLoadingMsg');
    if (ppMsg) ppMsg.style.display = 'none';
    paypal.Buttons({
        createOrder: async function() {
            var fx = getFxValues('paypal');
            if (!fx || fx.ngn < 1000) { alert('Please enter a valid NGN amount.'); throw new Error('invalid'); }
            var r = await fetch('/api/paypal-capture.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'create_order', usd_amount: fx.usdTotal, ngn_amount: fx.ngn, exchange_rate: fx.exchangeRate, fee: fx.ngnFee, net_ngn: fx.netNgn })
            });
            var data = await r.json();
            if (!data.success) throw new Error(data.message);
            window._ppRef = data.reference;
            return data.order_id;
        },
        onApprove: async function(data) {
            var r = await fetch('/api/paypal-capture.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'capture_order', order_id: data.orderID, reference: window._ppRef })
            });
            var d = await r.json();
            if (d.success) { window.location.href = '/deposit.php?success=1'; }
            else { alert('Capture error: ' + d.message); }
        },
        onError: function(err) { alert('PayPal error. Please try again.'); }
    }).render('#paypal-button-container');
}
(function(){
    var s = document.createElement('script');
    s.src = 'https://www.paypal.com/sdk/js?client-id=<?php echo urlencode($ppClientId); ?>&currency=USD';
    s.onload = function(){ if (currentGateway === 'paypal') renderPayPalButtons(); };
    document.head.appendChild(s);
})();
</script>
<?php endif; ?>

<!-- Plisio -->
<?php if ($plEnabled): ?>
<script>
async function initPlisioCheckout() {
    var fx = getFxValues('plisio');
    if (!fx || fx.ngn < 1000) { alert('Please enter a valid NGN amount (min ' + _currSym + '1,000).'); return; }
    var r = await fetch('/api/plisio-create.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ usd_amount: fx.usdTotal, ngn_amount: fx.ngn, exchange_rate: fx.exchangeRate, fee: fx.ngnFee, net_ngn: fx.netNgn })
    });
    var data = await r.json();
    if (data.success && data.invoice_url) {
        window.open(data.invoice_url, '_blank', 'noopener,noreferrer');
    } else { alert('Error creating crypto invoice: ' + (data.message || 'Unknown error')); }
}
</script>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.showToast) showToast('Deposit successful! Your wallet has been credited.', 'success', 6000);
});
</script>
<?php endif; ?>
<?php if (isset($_GET['plisio_failed'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.showToast) showToast('Crypto payment was not completed. Please try again.', 'error', 6000);
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
