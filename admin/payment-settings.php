<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

setSecurityHeaders();
requireAdmin();

$db   = getDB();
$user = getCurrentUser();

// ── Ensure all required settings keys exist ────────────────────────────────
$defaults = [
    'currency_symbol'           => '₦',
    'currency_name'             => 'Naira',
    'deposit_fee_percent'       => '0',
    // Payhub
    'payhub_api_key'            => '',
    'payhub_secret_key'         => '',
    'payhub_enabled'            => '0',
    'virtual_bank_enabled'      => '0',
    // Flutterwave
    'flutterwave_public_key'    => '',
    'flutterwave_secret_key'    => '',
    'flutterwave_enabled'       => '0',
    'flutterwave_fee_percent'   => '1.4',
    // PayPal
    'paypal_client_id'          => '',
    'paypal_client_secret'      => '',
    'paypal_mode'               => 'sandbox',
    'paypal_enabled'            => '0',
    'paypal_fee_percent'        => '3.49',
    // Stripe
    'stripe_publishable_key'    => '',
    'stripe_secret_key'         => '',
    'stripe_enabled'            => '0',
    'stripe_fee_percent'        => '2.9',
    // Plisio
    'plisio_api_key'            => '',
    'plisio_currency'           => 'BTC',
    'plisio_enabled'            => '0',
    'plisio_fee_percent'        => '0.5',
    // FX markup (hidden from users; applies to USD gateways)
    'fx_markup_ngn'             => '0',
    // Manual transfer
    'manual_transfer_enabled'   => '0',
    'bank_account_name'         => '',
    'bank_account_number'       => '',
    'bank_name'                 => '',
    'bank_transfer_charges'     => '0',
    'bank_transfer_note'        => '',
];
foreach ($defaults as $k => $v) {
    try {
        $db->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?,?)")
           ->execute([$k, $v]);
    } catch (\Exception $e) {}
}
// Ensure deposit tables exist
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

// Migrate wallet_deposits: extend method column to VARCHAR, add FX columns
try { $db->exec("ALTER TABLE wallet_deposits MODIFY COLUMN method VARCHAR(30) NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE wallet_deposits ADD COLUMN usd_amount DECIMAL(12,4) NULL DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE wallet_deposits ADD COLUMN exchange_rate DECIMAL(12,4) NULL DEFAULT NULL"); } catch (\Exception $e) {}

// ── Flash helpers ──────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['ps_flash'] = ['msg' => $msg, 'type' => $type];
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $f = $_SESSION['ps_flash'] ?? ['msg' => '', 'type' => 'success'];
    unset($_SESSION['ps_flash']);
    return $f;
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/payment-settings.php');
    }
    $action = $_POST['action'] ?? '';

    // Save currency
    if ($action === 'save_currency') {
        $sym  = sanitize($_POST['currency_symbol'] ?? '₦');
        $name = sanitize($_POST['currency_name'] ?? 'Naira');
        if ($sym === '') $sym = '₦';
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('currency_symbol',?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$sym, $sym]);
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('currency_name',?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$name, $name]);
        setFlash('Currency settings saved.');
        redirect('/admin/payment-settings.php#currency');
    }

    // Save Payhub settings
    if ($action === 'save_payhub') {
        $apiKey    = sanitize($_POST['payhub_api_key']       ?? '');
        $secretKey = sanitize($_POST['payhub_secret_key']    ?? '');
        $enabled   = isset($_POST['payhub_enabled'])   ? '1' : '0';
        $vbEnabled = isset($_POST['virtual_bank_enabled']) ? '1' : '0';
        $feeStr    = number_format(max(0, min(100, (float)($_POST['deposit_fee_percent'] ?? 0))), 2, '.', '');

        foreach ([
            ['payhub_api_key', $apiKey],
            ['payhub_secret_key', $secretKey],
            ['payhub_enabled', $enabled],
            ['virtual_bank_enabled', $vbEnabled],
            ['deposit_fee_percent', $feeStr],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('Payhub settings saved.');
        redirect('/admin/payment-settings.php#payhub');
    }

    // Save manual bank transfer settings
    if ($action === 'save_bank_transfer') {
        $bName     = sanitize($_POST['bank_account_name']   ?? '');
        $bNumber   = sanitize($_POST['bank_account_number'] ?? '');
        $bankName  = sanitize($_POST['bank_name']           ?? '');
        $charges   = number_format(max(0, (float)($_POST['bank_transfer_charges'] ?? 0)), 2, '.', '');
        $note      = sanitize($_POST['bank_transfer_note']  ?? '');
        $enabled   = isset($_POST['manual_transfer_enabled']) ? '1' : '0';

        foreach ([
            ['bank_account_name',       $bName],
            ['bank_account_number',     $bNumber],
            ['bank_name',               $bankName],
            ['bank_transfer_charges',   $charges],
            ['bank_transfer_note',      $note],
            ['manual_transfer_enabled', $enabled],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('Bank transfer settings saved.');
        redirect('/admin/payment-settings.php#bank');
    }

    // Save Flutterwave settings
    if ($action === 'save_flutterwave') {
        $feeStr = number_format(max(0, min(100, (float)($_POST['flutterwave_fee_percent'] ?? 1.4))), 2, '.', '');
        foreach ([
            ['flutterwave_public_key',  sanitize($_POST['flutterwave_public_key']  ?? '')],
            ['flutterwave_secret_key',  sanitize($_POST['flutterwave_secret_key']  ?? '')],
            ['flutterwave_enabled',     isset($_POST['flutterwave_enabled'])   ? '1' : '0'],
            ['flutterwave_fee_percent', $feeStr],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('Flutterwave settings saved.');
        redirect('/admin/payment-settings.php#flutterwave');
    }

    // Save PayPal settings
    if ($action === 'save_paypal') {
        $feeStr = number_format(max(0, min(100, (float)($_POST['paypal_fee_percent'] ?? 3.49))), 2, '.', '');
        $mode   = ($_POST['paypal_mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
        foreach ([
            ['paypal_client_id',     sanitize($_POST['paypal_client_id']     ?? '')],
            ['paypal_client_secret', sanitize($_POST['paypal_client_secret'] ?? '')],
            ['paypal_mode',          $mode],
            ['paypal_enabled',       isset($_POST['paypal_enabled'])  ? '1' : '0'],
            ['paypal_fee_percent',   $feeStr],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('PayPal settings saved.');
        redirect('/admin/payment-settings.php#paypal');
    }

    // Save Stripe settings
    if ($action === 'save_stripe') {
        $feeStr = number_format(max(0, min(100, (float)($_POST['stripe_fee_percent'] ?? 2.9))), 2, '.', '');
        foreach ([
            ['stripe_publishable_key', sanitize($_POST['stripe_publishable_key'] ?? '')],
            ['stripe_secret_key',      sanitize($_POST['stripe_secret_key']      ?? '')],
            ['stripe_enabled',         isset($_POST['stripe_enabled'])   ? '1' : '0'],
            ['stripe_fee_percent',     $feeStr],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('Stripe settings saved.');
        redirect('/admin/payment-settings.php#stripe');
    }

    // Save Plisio settings
    if ($action === 'save_plisio') {
        $feeStr    = number_format(max(0, min(100, (float)($_POST['plisio_fee_percent'] ?? 0.5))), 2, '.', '');
        $currency  = preg_replace('/[^A-Za-z]/', '', $_POST['plisio_currency'] ?? 'BTC');
        if (!$currency) $currency = 'BTC';
        foreach ([
            ['plisio_api_key',      sanitize($_POST['plisio_api_key'] ?? '')],
            ['plisio_currency',     strtoupper($currency)],
            ['plisio_enabled',      isset($_POST['plisio_enabled'])  ? '1' : '0'],
            ['plisio_fee_percent',  $feeStr],
        ] as [$k, $v]) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$k, $v, $v]);
        }
        setFlash('Plisio settings saved.');
        redirect('/admin/payment-settings.php#plisio');
    }

    // Save FX markup (hidden admin-only setting for USD gateways)
    if ($action === 'save_fx_markup') {
        $markup = number_format(max(0, min(100, (float)($_POST['fx_markup_ngn'] ?? 0))), 2, '.', '');
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('fx_markup_ngn',?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$markup, $markup]);
        setFlash('FX markup saved.');
        redirect('/admin/payment-settings.php#fx');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/payment-settings.php');
}

// ── Load settings ─────────────────────────────────────────────────────────
$settings = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (\Exception $e) {}

$s = function(string $key, string $default = '') use ($settings): string {
    return $settings[$key] ?? $default;
};

$flash      = popFlash();
$pageTitle  = 'Payment Settings';
$activePage = 'payment_settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<div class="page-header">
    <h1>💳 Payment Settings</h1>
    <p>Configure currency, Payhub payment gateway, virtual bank accounts, and manual bank transfer details.</p>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- ── Currency ────────────────────────────────────────────────────────── -->
<div class="card mb-3" id="currency" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>🌍 Currency Settings</h3></div>
    <div class="card-body">
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_currency">
            <div class="form-row">
                <div class="form-group">
                    <label>Currency Symbol <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="currency_symbol" value="<?= htmlspecialchars($s('currency_symbol', '₦')) ?>" required maxlength="10" placeholder="₦">
                    <p class="form-hint">The symbol shown before all monetary amounts site-wide (e.g. ₦, $, €, £).</p>
                </div>
                <div class="form-group">
                    <label>Currency Name</label>
                    <input type="text" name="currency_name" value="<?= htmlspecialchars($s('currency_name', 'Naira')) ?>" maxlength="50" placeholder="Naira">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save Currency</button>
        </form>
    </div>
</div>

<!-- ── Payhub ─────────────────────────────────────────────────────────── -->
<div class="card mb-3" id="payhub" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3>🔗 Payhub Payment Gateway</h3>
        <a href="https://payhub.datagifting.com.ng/docs.php" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📖 View Docs</a>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_payhub">
            <div class="form-row">
                <div class="form-group">
                    <label>Payhub Public / API Key</label>
                    <input type="text" name="payhub_api_key" value="<?= htmlspecialchars($s('payhub_api_key')) ?>" placeholder="pk_live_...">
                </div>
                <div class="form-group">
                    <label>Payhub Secret Key</label>
                    <input type="password" name="payhub_secret_key" value="<?= htmlspecialchars($s('payhub_secret_key')) ?>" placeholder="sk_live_...">
                    <p class="form-hint">Leave blank to keep the existing key.</p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Deposit Fee (%)</label>
                    <input type="number" name="deposit_fee_percent" value="<?= htmlspecialchars($s('deposit_fee_percent', '0')) ?>" min="0" max="100" step="0.01" placeholder="0">
                    <p class="form-hint">Fee charged on every deposit (e.g. 1.5 for 1.5%). Set 0 to disable.</p>
                </div>
            </div>
            <div style="display:flex;gap:2rem;margin-bottom:1.25rem;flex-wrap:wrap">
                <label class="checkbox-group">
                    <input type="checkbox" name="payhub_enabled" value="1" <?= $s('payhub_enabled') === '1' ? 'checked' : '' ?>>
                    <span>Enable Payhub Card / Inline Checkout</span>
                </label>
                <label class="checkbox-group">
                    <input type="checkbox" name="virtual_bank_enabled" value="1" <?= $s('virtual_bank_enabled') === '1' ? 'checked' : '' ?>>
                    <span>Enable Virtual Bank Account (Payhub)</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Save Payhub Settings</button>
        </form>
    </div>
</div>

<!-- ── Manual Bank Transfer ───────────────────────────────────────────── -->
<div class="card" id="bank">
    <div class="card-header"><h3>🏦 Manual Bank Transfer</h3></div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Users will see these bank details on the deposit page and can upload payment proof for admin approval.
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_bank_transfer">
            <div class="form-row">
                <div class="form-group">
                    <label>Account Name</label>
                    <input type="text" name="bank_account_name" value="<?= htmlspecialchars($s('bank_account_name')) ?>" placeholder="PhilmoreHost Ltd">
                </div>
                <div class="form-group">
                    <label>Account Number</label>
                    <input type="text" name="bank_account_number" value="<?= htmlspecialchars($s('bank_account_number')) ?>" placeholder="0123456789">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name" value="<?= htmlspecialchars($s('bank_name')) ?>" placeholder="First Bank of Nigeria">
                </div>
                <div class="form-group">
                    <label>Transfer Charges (<?= htmlspecialchars($s('currency_symbol', '₦')) ?>)</label>
                    <input type="number" name="bank_transfer_charges" value="<?= htmlspecialchars($s('bank_transfer_charges', '0')) ?>" min="0" step="0.01" placeholder="0.00">
                    <p class="form-hint">Fixed charge added to manual transfers (e.g. bank USSD fee).</p>
                </div>
            </div>
            <div class="form-group">
                <label>Transfer Instructions / Note</label>
                <textarea name="bank_transfer_note" rows="3" placeholder="Please send proof of payment via WhatsApp..."><?= htmlspecialchars($s('bank_transfer_note')) ?></textarea>
            </div>
            <label class="checkbox-group" style="margin-bottom:1.25rem">
                <input type="checkbox" name="manual_transfer_enabled" value="1" <?= $s('manual_transfer_enabled') === '1' ? 'checked' : '' ?>>
                <span>Enable Manual Bank Transfer</span>
            </label>
            <br>
            <button type="submit" class="btn btn-primary">Save Bank Transfer Settings</button>
        </form>
    </div>
</div>

<!-- ── FX Markup (admin-only hidden charge for USD gateways) ──────────── -->
<div class="card" id="fx" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>💱 Foreign Exchange Markup <span style="font-size:.75rem;font-weight:400;color:var(--warning);margin-left:.5rem">🔒 Admin Only</span></h3>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Add a fixed amount (in <?= htmlspecialchars($s('currency_name','Naira')) ?>) per $1 USD on top of the live market exchange rate.
            This covers the difference between the market rate and what you actually buy USD for.
            <strong>Users will see the combined rate but not this markup separately.</strong>
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_fx_markup">
            <div class="form-group" style="max-width:320px">
                <label>Markup per $1 USD (<?= htmlspecialchars($s('currency_symbol','₦')) ?>)
                    <span style="font-size:.78rem;color:var(--text-muted)">0 – 100</span>
                </label>
                <input type="number" name="fx_markup_ngn" value="<?= htmlspecialchars($s('fx_markup_ngn', '0')) ?>" min="0" max="100" step="0.01" placeholder="0.00">
                <p class="form-hint">
                    e.g. If market rate is <?= htmlspecialchars($s('currency_symbol','₦')) ?>1,500/USD and you set <?= htmlspecialchars($s('currency_symbol','₦')) ?>50,
                    users pay at <?= htmlspecialchars($s('currency_symbol','₦')) ?>1,550/USD.
                    Applies to PayPal, Stripe, and Plisio (USD-denominated gateways).
                </p>
            </div>
            <button type="submit" class="btn btn-primary">Save FX Markup</button>
        </form>
    </div>
</div>

<!-- ── Flutterwave ────────────────────────────────────────────────────── -->
<div class="card" id="flutterwave" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>🦋 Flutterwave</h3>
        <a href="https://developer.flutterwave.com" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📖 Docs</a>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Accept cards and bank transfers in NGN via Flutterwave Rave inline checkout.
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_flutterwave">
            <div class="form-row">
                <div class="form-group">
                    <label>Public Key</label>
                    <input type="text" name="flutterwave_public_key" value="<?= htmlspecialchars($s('flutterwave_public_key')) ?>" placeholder="FLWPUBK_TEST-...">
                </div>
                <div class="form-group">
                    <label>Secret Key</label>
                    <input type="password" name="flutterwave_secret_key" value="<?= htmlspecialchars($s('flutterwave_secret_key')) ?>" placeholder="FLWSECK_TEST-...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Fee (%)</label>
                    <input type="number" name="flutterwave_fee_percent" value="<?= htmlspecialchars($s('flutterwave_fee_percent', '1.4')) ?>" min="0" max="100" step="0.01" placeholder="1.40">
                    <p class="form-hint">Charged to the user on top of their deposit amount.</p>
                </div>
            </div>
            <label class="checkbox-group" style="margin-bottom:1.25rem">
                <input type="checkbox" name="flutterwave_enabled" value="1" <?= $s('flutterwave_enabled') === '1' ? 'checked' : '' ?>>
                <span>Enable Flutterwave</span>
            </label><br>
            <button type="submit" class="btn btn-primary">Save Flutterwave Settings</button>
        </form>
    </div>
</div>

<!-- ── PayPal ─────────────────────────────────────────────────────────── -->
<div class="card" id="paypal" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>🅿️ PayPal</h3>
        <a href="https://developer.paypal.com" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📖 Docs</a>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Users pay in <strong>USD</strong>. Amounts are auto-converted from/to NGN at the live rate (+FX markup).
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_paypal">
            <div class="form-row">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="paypal_client_id" value="<?= htmlspecialchars($s('paypal_client_id')) ?>" placeholder="AQ...">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="paypal_client_secret" value="<?= htmlspecialchars($s('paypal_client_secret')) ?>" placeholder="EJ...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Mode</label>
                    <select name="paypal_mode">
                        <option value="sandbox" <?= $s('paypal_mode','sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
                        <option value="live"    <?= $s('paypal_mode') === 'live'    ? 'selected' : '' ?>>Live (production)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fee (%)</label>
                    <input type="number" name="paypal_fee_percent" value="<?= htmlspecialchars($s('paypal_fee_percent', '3.49')) ?>" min="0" max="100" step="0.01" placeholder="3.49">
                    <p class="form-hint">Displayed to users as a USD fee on checkout.</p>
                </div>
            </div>
            <label class="checkbox-group" style="margin-bottom:1.25rem">
                <input type="checkbox" name="paypal_enabled" value="1" <?= $s('paypal_enabled') === '1' ? 'checked' : '' ?>>
                <span>Enable PayPal</span>
            </label><br>
            <button type="submit" class="btn btn-primary">Save PayPal Settings</button>
        </form>
    </div>
</div>

<!-- ── Stripe ─────────────────────────────────────────────────────────── -->
<div class="card" id="stripe" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>⚡ Stripe</h3>
        <a href="https://stripe.com/docs" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📖 Docs</a>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Accept card payments globally in <strong>USD</strong> via Stripe Elements. Auto-converts to NGN on success.
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_stripe">
            <div class="form-row">
                <div class="form-group">
                    <label>Publishable Key</label>
                    <input type="text" name="stripe_publishable_key" value="<?= htmlspecialchars($s('stripe_publishable_key')) ?>" placeholder="pk_test_...">
                </div>
                <div class="form-group">
                    <label>Secret Key</label>
                    <input type="password" name="stripe_secret_key" value="<?= htmlspecialchars($s('stripe_secret_key')) ?>" placeholder="sk_test_...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Fee (%)</label>
                    <input type="number" name="stripe_fee_percent" value="<?= htmlspecialchars($s('stripe_fee_percent', '2.9')) ?>" min="0" max="100" step="0.01" placeholder="2.9">
                </div>
            </div>
            <label class="checkbox-group" style="margin-bottom:1.25rem">
                <input type="checkbox" name="stripe_enabled" value="1" <?= $s('stripe_enabled') === '1' ? 'checked' : '' ?>>
                <span>Enable Stripe</span>
            </label><br>
            <button type="submit" class="btn btn-primary">Save Stripe Settings</button>
        </form>
    </div>
</div>

<!-- ── Plisio (Crypto) ────────────────────────────────────────────────── -->
<div class="card" id="plisio" style="margin-top:1.5rem">
    <div class="card-header">
        <h3>₿ Plisio – Cryptocurrency</h3>
        <a href="https://plisio.net/api" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📖 Docs</a>
    </div>
    <div class="card-body">
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
            Accept BTC, ETH, LTC and other cryptocurrencies via Plisio. Charged in <strong>USD</strong>, auto-converted to NGN.
        </p>
        <form method="POST" action="/admin/payment-settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_plisio">
            <div class="form-row">
                <div class="form-group">
                    <label>Plisio API Key</label>
                    <input type="password" name="plisio_api_key" value="<?= htmlspecialchars($s('plisio_api_key')) ?>" placeholder="...">
                </div>
                <div class="form-group">
                    <label>Default Cryptocurrency</label>
                    <select name="plisio_currency">
                        <?php
                        $cryptos = ['BTC'=>'Bitcoin (BTC)','ETH'=>'Ethereum (ETH)','LTC'=>'Litecoin (LTC)','USDT'=>'Tether (USDT)','BNB'=>'BNB','XMR'=>'Monero (XMR)','DOGE'=>'Dogecoin (DOGE)'];
                        $cur = strtoupper($s('plisio_currency','BTC'));
                        foreach ($cryptos as $code => $label):
                        ?>
                        <option value="<?= $code ?>" <?= $cur === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Users are redirected to Plisio's hosted page where they pay in this crypto.</p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Fee (%)</label>
                    <input type="number" name="plisio_fee_percent" value="<?= htmlspecialchars($s('plisio_fee_percent', '0.5')) ?>" min="0" max="100" step="0.01" placeholder="0.5">
                </div>
            </div>
            <label class="checkbox-group" style="margin-bottom:1.25rem">
                <input type="checkbox" name="plisio_enabled" value="1" <?= $s('plisio_enabled') === '1' ? 'checked' : '' ?>>
                <span>Enable Plisio Crypto Payments</span>
            </label><br>
            <button type="submit" class="btn btn-primary">Save Plisio Settings</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
