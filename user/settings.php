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
$uid  = (int)($user['id'] ?? 0);

// ── Migrations ────────────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_smtp_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        label VARCHAR(100) NOT NULL DEFAULT 'My SMTP',
        provider ENUM('smtp','sendgrid','mailgun','ses','resend','postmark','brevo') NOT NULL DEFAULT 'smtp',
        is_active BOOLEAN DEFAULT FALSE,
        smtp_host VARCHAR(255),
        smtp_port SMALLINT DEFAULT 587,
        smtp_username VARCHAR(255),
        smtp_password_enc TEXT,
        smtp_encryption ENUM('tls','ssl','none') DEFAULT 'tls',
        from_name VARCHAR(100),
        from_email VARCHAR(100),
        api_key_enc TEXT,
        extra_json JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_uss_user (user_id)
    )");
} catch (\Exception $e) { error_log('user/settings.php migration: ' . $e->getMessage()); }

// ── Load user's subscription ──────────────────────────────────────────────────
$mySubscription = null;
try {
    $subStmt = $db->prepare(
        "SELECT s.*, ep.name AS plan_name, ep.is_special, ep.allowed_providers
         FROM user_subscriptions s
         JOIN email_plans ep ON ep.id = s.plan_id
         WHERE s.user_id = ? AND s.status = 'active' LIMIT 1"
    );
    $subStmt->execute([$uid]);
    $mySubscription = $subStmt->fetch() ?: null;
} catch (\Exception $e) {}

$isSpecialPlan = !empty($mySubscription['is_special']);
$allowedProviders = [];
if ($isSpecialPlan && !empty($mySubscription['allowed_providers'])) {
    $allowedProviders = json_decode($mySubscription['allowed_providers'], true) ?: [];
}

// ── Load user's SMTP configs ──────────────────────────────────────────────────
$userSmtpList = [];
try {
    $ustmt = $db->prepare("SELECT * FROM user_smtp_settings WHERE user_id = ? ORDER BY is_active DESC, id ASC");
    $ustmt->execute([$uid]);
    $userSmtpList = $ustmt->fetchAll();
} catch (\Exception $e) {}

$msg     = '';
$msgType = 'success';

// ── POST Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $msg = 'Invalid security token.'; $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_smtp' && $isSpecialPlan) {
            $label     = sanitize($_POST['label'] ?? 'My SMTP');
            $provider  = $_POST['provider'] ?? 'smtp';
            if (!in_array($provider, ['smtp','sendgrid','mailgun','ses','resend','postmark','brevo'], true)) {
                $provider = 'smtp';
            }
            if (!empty($allowedProviders) && !in_array($provider, $allowedProviders, true)) {
                $msg = 'This provider is not allowed by your plan.'; $msgType = 'error';
            } else {
                $fromName   = sanitize($_POST['from_name'] ?? '');
                $fromEmail  = sanitize($_POST['from_email'] ?? '');
                $apiKeyRaw  = $_POST['api_key'] ?? '';
                $apiKeyEnc  = $apiKeyRaw !== '' ? encryptData($apiKeyRaw, APP_KEY) : null;
                $smtpHost   = sanitize($_POST['smtp_host'] ?? '');
                $smtpPort   = (int)($_POST['smtp_port'] ?? 587);
                $smtpUser   = sanitize($_POST['smtp_username'] ?? '');
                $smtpPwRaw  = $_POST['smtp_password'] ?? '';
                $smtpPwEnc  = $smtpPwRaw !== '' ? encryptData($smtpPwRaw, APP_KEY) : null;
                $smtpEnc    = $_POST['smtp_encryption'] ?? 'tls';
                if (!in_array($smtpEnc, ['tls','ssl','none'], true)) $smtpEnc = 'tls';
                try {
                    $db->prepare(
                        "INSERT INTO user_smtp_settings (user_id, label, provider, from_name, from_email, api_key_enc, smtp_host, smtp_port, smtp_username, smtp_password_enc, smtp_encryption)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$uid, $label, $provider, $fromName, $fromEmail, $apiKeyEnc, $smtpHost, $smtpPort, $smtpUser, $smtpPwEnc, $smtpEnc]);
                    $msg = '✅ Email server configuration added.';
                } catch (\Exception $e) {
                    error_log('add_smtp: ' . $e->getMessage());
                    $msg = 'Failed to save. Please try again.'; $msgType = 'error';
                }
            }
        }

        if ($action === 'set_active_smtp' && $isSpecialPlan) {
            $smtpId = (int)($_POST['smtp_id'] ?? 0);
            try {
                $db->prepare("UPDATE user_smtp_settings SET is_active = 0 WHERE user_id = ?")->execute([$uid]);
                if ($smtpId > 0) {
                    $db->prepare("UPDATE user_smtp_settings SET is_active = 1 WHERE id = ? AND user_id = ?")->execute([$smtpId, $uid]);
                }
                $msg = $smtpId > 0 ? '✅ Active email server updated.' : '✅ Switched to Default SMTP.';
            } catch (\Exception $e) {
                $msg = 'Failed to update.'; $msgType = 'error';
            }
        }

        if ($action === 'delete_smtp' && $isSpecialPlan) {
            $smtpId = (int)($_POST['smtp_id'] ?? 0);
            try {
                $db->prepare("DELETE FROM user_smtp_settings WHERE id = ? AND user_id = ?")->execute([$smtpId, $uid]);
                $msg = '✅ Configuration deleted.';
            } catch (\Exception $e) {
                $msg = 'Failed to delete.'; $msgType = 'error';
            }
        }

        // Reload list after change
        try {
            $ustmt = $db->prepare("SELECT * FROM user_smtp_settings WHERE user_id = ? ORDER BY is_active DESC, id ASC");
            $ustmt->execute([$uid]);
            $userSmtpList = $ustmt->fetchAll();
        } catch (\Exception $e) {}
    }
}

$providerLabels = [
    'smtp'      => '📧 SMTP',
    'sendgrid'  => '📨 SendGrid',
    'mailgun'   => '📬 Mailgun',
    'ses'       => '📮 AWS SES',
    'resend'    => '📤 Resend',
    'postmark'  => '✉️ Postmark',
    'brevo'     => '📩 Brevo',
];

$pageTitle  = 'Email Settings';
$activePage = 'settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <h1>⚙️ Email Settings</h1>
    <p style="color:var(--text-muted)">Manage your personal email server configurations.</p>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.5rem">
    <?= $msgType === 'error' ? '⚠ ' : '✅ ' ?><?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if (!$isSpecialPlan): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem">
        <div style="font-size:2rem;margin-bottom:1rem">🔒</div>
        <h3 style="margin-bottom:.5rem">Special Plan Required</h3>
        <p style="color:var(--text-muted);margin-bottom:1.5rem">
            Personalised email server settings are available on <strong>Special Email Plans</strong> only.
            Subscribe to a special plan to configure your own SMTP, SendGrid, Mailgun, or other email providers.
        </p>
        <a href="/billing.php?tab=email_plans" class="btn btn-primary">📧 View Plans</a>
    </div>
</div>
<?php else: ?>

<!-- Active server info banner -->
<div class="card" style="margin-bottom:1.5rem;border-color:rgba(16,185,129,.4)">
    <div class="card-body">
        <?php
        $activeSmtp = null;
        foreach ($userSmtpList as $s) { if ($s['is_active']) { $activeSmtp = $s; break; } }
        ?>
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <div style="font-size:2rem">📡</div>
            <div>
                <div style="font-weight:700;color:var(--text-primary)">Active Email Server</div>
                <?php if ($activeSmtp): ?>
                <div style="color:var(--success)"><?= htmlspecialchars($providerLabels[$activeSmtp['provider']] ?? $activeSmtp['provider']) ?> — <?= htmlspecialchars($activeSmtp['label']) ?></div>
                <?php else: ?>
                <div style="color:var(--accent)">Default SMTP (system-wide provider)</div>
                <?php endif; ?>
            </div>
            <?php if ($activeSmtp): ?>
            <form method="POST" style="margin-left:auto">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="set_active_smtp">
                <input type="hidden" name="smtp_id" value="0">
                <button type="submit" class="btn btn-secondary btn-sm">↩ Use Default SMTP</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns:1fr 1fr">

<!-- Add new config -->
<div class="card">
    <div class="card-header"><h3>➕ Add Email Server</h3></div>
    <div class="card-body">
        <?php if (!empty($allowedProviders)): ?>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">
            Allowed providers for your plan: <strong><?= htmlspecialchars(implode(', ', $allowedProviders)) ?></strong>
        </p>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_smtp">
            <div class="form-group">
                <label>Label</label>
                <input type="text" name="label" class="form-control" placeholder="e.g. My SendGrid" value="My SMTP">
            </div>
            <div class="form-group">
                <label>Provider</label>
                <select name="provider" class="form-control" id="smtpProvider" onchange="toggleProviderFields(this.value)">
                    <?php
                    $displayProviders = !empty($allowedProviders) ? $allowedProviders : array_keys($providerLabels);
                    foreach ($displayProviders as $pv):
                        if (!isset($providerLabels[$pv])) continue;
                    ?>
                    <option value="<?= htmlspecialchars($pv) ?>"><?= htmlspecialchars($providerLabels[$pv]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>From Name</label>
                <input type="text" name="from_name" class="form-control" placeholder="Your Company">
            </div>
            <div class="form-group">
                <label>From Email</label>
                <input type="email" name="from_email" class="form-control" placeholder="noreply@example.com">
            </div>

            <!-- API key providers -->
            <div id="apiKeyFields">
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="api_key" class="form-control" placeholder="Enter API key" autocomplete="new-password">
                </div>
            </div>

            <!-- SMTP fields -->
            <div id="smtpFields" style="display:none">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" placeholder="smtp.example.com">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="587" min="1" max="65535">
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="smtp_username" class="form-control" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="smtp_password" class="form-control" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Encryption</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="tls">TLS (STARTTLS)</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add Configuration</button>
        </form>
    </div>
</div>

<!-- My configs list -->
<div class="card">
    <div class="card-header"><h3>📋 My Configurations</h3></div>
    <?php if (empty($userSmtpList)): ?>
    <p class="empty-state" style="padding:1.5rem">No configurations yet. Add one to get started.</p>
    <?php else: ?>
    <div class="table-wrap" style="overflow-y:auto">
    <table class="table" style="font-size:.85rem">
        <thead><tr><th>Label</th><th>Provider</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($userSmtpList as $s): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($s['label']) ?></strong>
                <?php if ($s['from_email']): ?>
                <br><small style="color:var(--text-muted)"><?= htmlspecialchars($s['from_email']) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($providerLabels[$s['provider']] ?? $s['provider']) ?></td>
            <td>
                <?php if ($s['is_active']): ?>
                <span class="badge badge-success">✅ Active</span>
                <?php else: ?>
                <span class="badge badge-warning">Inactive</span>
                <?php endif; ?>
            </td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <?php if (!$s['is_active']): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="set_active_smtp">
                    <input type="hidden" name="smtp_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Activate</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this configuration?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_smtp">
                    <input type="hidden" name="smtp_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div>
<?php endif; ?>

<script>
function toggleProviderFields(provider) {
    var apiFields  = document.getElementById('apiKeyFields');
    var smtpFields = document.getElementById('smtpFields');
    if (provider === 'smtp') {
        apiFields.style.display  = 'none';
        smtpFields.style.display = 'block';
    } else {
        apiFields.style.display  = 'block';
        smtpFields.style.display = 'none';
    }
}
// Run on page load
(function() {
    var sel = document.getElementById('smtpProvider');
    if (sel) toggleProviderFields(sel.value);
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
