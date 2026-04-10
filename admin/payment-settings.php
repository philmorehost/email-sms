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
    'currency_symbol'         => '₦',
    'currency_name'           => 'Naira',
    'deposit_fee_percent'     => '0',
    'payhub_api_key'          => '',
    'payhub_secret_key'       => '',
    'payhub_enabled'          => '0',
    'virtual_bank_enabled'    => '0',
    'manual_transfer_enabled' => '0',
    'bank_account_name'       => '',
    'bank_account_number'     => '',
    'bank_name'               => '',
    'bank_transfer_charges'   => '0',
    'bank_transfer_note'      => '',
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

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
