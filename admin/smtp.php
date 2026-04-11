<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/sms.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();

$msg     = '';
$msgType = 'success';

// ─── POST HANDLERS ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfOk = verifyCsrf($_POST['csrf_token'] ?? '');

    if (!$csrfOk) {
        $msg     = 'Invalid security token. Please try again.';
        $msgType = 'error';
    } else {

        // ── 1. Set active provider ────────────────────────────────────────────
        if ($action === 'set_provider') {
            $allowed  = ['smtp','sendgrid','mailgun','ses','resend','postmark','brevo','mailjet','aweber'];
            $provider = $_POST['provider'] ?? '';
            if (!in_array($provider, $allowed, true)) {
                $msg     = 'Invalid provider selected.';
                $msgType = 'error';
            } else {
                try {
                    $db->prepare("UPDATE smtp_settings SET provider = :p WHERE id = 1")
                       ->execute([':p' => $provider]);
                    $msg     = 'Active provider updated successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to update provider: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 2. Save SMTP settings ─────────────────────────────────────────────
        elseif ($action === 'save_smtp') {
            $host       = sanitize($_POST['host'] ?? '');
            $port       = max(1, min(65535, (int)($_POST['port'] ?? 587)));
            $username   = sanitize($_POST['username'] ?? '');
            $password   = $_POST['password'] ?? '';
            $encryption = $_POST['encryption'] ?? 'tls';
            $fromEmail  = sanitize($_POST['from_email'] ?? '');
            $fromName   = sanitize($_POST['from_name'] ?? '');

            if (!in_array($encryption, ['tls','ssl','none'], true)) {
                $encryption = 'tls';
            }

            try {
                if ($password !== '') {
                    $enc = encryptData($password, APP_KEY);
                    $db->prepare(
                        "UPDATE smtp_settings SET host=:h, port=:p, username=:u, password_encrypted=:pw,
                         encryption=:enc, from_email=:fe, from_name=:fn WHERE id=1"
                    )->execute([
                        ':h' => $host, ':p' => $port, ':u' => $username,
                        ':pw' => $enc, ':enc' => $encryption,
                        ':fe' => $fromEmail, ':fn' => $fromName,
                    ]);
                } else {
                    $db->prepare(
                        "UPDATE smtp_settings SET host=:h, port=:p, username=:u,
                         encryption=:enc, from_email=:fe, from_name=:fn WHERE id=1"
                    )->execute([
                        ':h' => $host, ':p' => $port, ':u' => $username,
                        ':enc' => $encryption, ':fe' => $fromEmail, ':fn' => $fromName,
                    ]);
                }
                $msg     = 'SMTP settings saved successfully.';
                $msgType = 'success';
            } catch (\Exception $e) {
                $msg     = 'Failed to save SMTP settings: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

        // ── 3. Save SendGrid ──────────────────────────────────────────────────
        elseif ($action === 'save_sendgrid') {
            $apiKey = $_POST['sendgrid_api_key'] ?? '';
            if ($apiKey === '') {
                $msg     = 'No API key provided — nothing updated.';
                $msgType = 'error';
            } else {
                try {
                    $enc = encryptData($apiKey, APP_KEY);
                    $db->prepare("UPDATE smtp_settings SET sendgrid_api_key_encrypted=:k WHERE id=1")
                       ->execute([':k' => $enc]);
                    $msg     = 'SendGrid API key saved successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to save SendGrid settings: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 4. Save Mailgun ───────────────────────────────────────────────────
        elseif ($action === 'save_mailgun') {
            $apiKey = $_POST['mailgun_api_key'] ?? '';
            $domain = sanitize($_POST['mailgun_domain'] ?? '');
            try {
                if ($apiKey !== '') {
                    $enc = encryptData($apiKey, APP_KEY);
                    $db->prepare("UPDATE smtp_settings SET mailgun_api_key_encrypted=:k, mailgun_domain=:d WHERE id=1")
                       ->execute([':k' => $enc, ':d' => $domain]);
                } else {
                    $db->prepare("UPDATE smtp_settings SET mailgun_domain=:d WHERE id=1")
                       ->execute([':d' => $domain]);
                }
                $msg     = 'Mailgun settings saved successfully.';
                $msgType = 'success';
            } catch (\Exception $e) {
                $msg     = 'Failed to save Mailgun settings: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

        // ── 5. Save SES ───────────────────────────────────────────────────────
        elseif ($action === 'save_ses') {
            $key    = $_POST['ses_key'] ?? '';
            $secret = $_POST['ses_secret'] ?? '';
            $region = sanitize($_POST['ses_region'] ?? '');
            try {
                $params = [':r' => $region];
                $sets   = ['ses_region=:r'];
                if ($key !== '') {
                    $sets[]        = 'ses_key_encrypted=:k';
                    $params[':k']  = encryptData($key, APP_KEY);
                }
                if ($secret !== '') {
                    $sets[]        = 'ses_secret_encrypted=:s';
                    $params[':s']  = encryptData($secret, APP_KEY);
                }
                $db->prepare("UPDATE smtp_settings SET " . implode(', ', $sets) . " WHERE id=1")
                   ->execute($params);
                $msg     = 'Amazon SES settings saved successfully.';
                $msgType = 'success';
            } catch (\Exception $e) {
                $msg     = 'Failed to save SES settings: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

        // ── 6. Save Resend ────────────────────────────────────────────────────
        elseif ($action === 'save_resend') {
            $apiKey = $_POST['resend_api_key'] ?? '';
            if ($apiKey === '') {
                $msg     = 'No API key provided — nothing updated.';
                $msgType = 'error';
            } else {
                try {
                    $enc = encryptData($apiKey, APP_KEY);
                    $db->prepare("UPDATE smtp_settings SET resend_api_key_encrypted=:k WHERE id=1")
                       ->execute([':k' => $enc]);
                    $msg     = 'Resend API key saved successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to save Resend settings: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 7. Save Postmark ──────────────────────────────────────────────────
        elseif ($action === 'save_postmark') {
            $apiKey = $_POST['postmark_api_key'] ?? '';
            if ($apiKey === '') {
                $msg     = 'No API token provided — nothing updated.';
                $msgType = 'error';
            } else {
                try {
                    $enc = encryptData($apiKey, APP_KEY);
                    $db->prepare("UPDATE smtp_settings SET postmark_api_key_encrypted=:k WHERE id=1")
                       ->execute([':k' => $enc]);
                    $msg     = 'Postmark API token saved successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to save Postmark settings: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 8. Save Brevo ─────────────────────────────────────────────────────
        elseif ($action === 'save_brevo') {
            $apiKey = $_POST['brevo_api_key'] ?? '';
            if ($apiKey === '') {
                $msg     = 'No API key provided — nothing updated.';
                $msgType = 'error';
            } else {
                try {
                    $enc = encryptData($apiKey, APP_KEY);
                    $db->prepare("UPDATE smtp_settings SET brevo_api_key_encrypted=:k WHERE id=1")
                       ->execute([':k' => $enc]);
                    $msg     = 'Brevo API key saved successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to save Brevo settings: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 9. Save Mailjet ───────────────────────────────────────────────────
        elseif ($action === 'save_mailjet') {
            $apiKey    = $_POST['mailjet_api_key'] ?? '';
            $secretKey = $_POST['mailjet_secret_key'] ?? '';
            try {
                $sets   = [];
                $params = [];
                if ($apiKey !== '') {
                    $sets[]        = 'mailjet_api_key_encrypted=:k';
                    $params[':k']  = encryptData($apiKey, APP_KEY);
                }
                if ($secretKey !== '') {
                    $sets[]        = 'mailjet_secret_key_encrypted=:s';
                    $params[':s']  = encryptData($secretKey, APP_KEY);
                }
                if (empty($sets)) {
                    $msg     = 'No credentials provided — nothing updated.';
                    $msgType = 'error';
                } else {
                    $db->prepare("UPDATE smtp_settings SET " . implode(', ', $sets) . " WHERE id=1")
                       ->execute($params);
                    $msg     = 'Mailjet credentials saved successfully.';
                    $msgType = 'success';
                }
            } catch (\Exception $e) {
                $msg     = 'Failed to save Mailjet settings: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

        // ── 10. Save AWeber ───────────────────────────────────────────────────
        elseif ($action === 'save_aweber') {
            $token     = $_POST['aweber_access_token'] ?? '';
            $accountId = sanitize($_POST['aweber_account_id'] ?? '');
            $listId    = sanitize($_POST['aweber_list_id'] ?? '');
            try {
                if ($token !== '') {
                    $enc = encryptData($token, APP_KEY);
                    $db->prepare(
                        "UPDATE smtp_settings SET aweber_access_token_encrypted=:t,
                         aweber_account_id=:a, aweber_list_id=:l WHERE id=1"
                    )->execute([':t' => $enc, ':a' => $accountId, ':l' => $listId]);
                } else {
                    $db->prepare(
                        "UPDATE smtp_settings SET aweber_account_id=:a, aweber_list_id=:l WHERE id=1"
                    )->execute([':a' => $accountId, ':l' => $listId]);
                }
                $msg     = 'AWeber settings saved successfully.';
                $msgType = 'success';
            } catch (\Exception $e) {
                $msg     = 'Failed to save AWeber settings: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

        // ── 11. Save SMS API ──────────────────────────────────────────────────
        elseif ($action === 'save_sms_api') {
            $apiToken = $_POST['api_token'] ?? '';
            if ($apiToken === '') {
                $msg     = 'No API token provided — nothing updated.';
                $msgType = 'error';
            } else {
                try {
                    $enc = encryptData($apiToken, APP_KEY);
                    $db->prepare("UPDATE sms_api_config SET api_token_encrypted=:t WHERE id=1")
                       ->execute([':t' => $enc]);
                    $msg     = 'SMS API token saved successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    $msg     = 'Failed to save SMS API token: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

        // ── 12. Test connection ───────────────────────────────────────────────
        elseif ($action === 'test_connection') {
            $providerToTest = $_POST['provider_to_test'] ?? '';
            if ($providerToTest === 'sms') {
                $sms = PhilmoreSMS::fromDB();
                if ($sms === null) {
                    $msg     = 'SMS API not configured. Please save your API token first.';
                    $msgType = 'error';
                } else {
                    $result = $sms->checkBalance();
                    if (!empty($result['error'])) {
                        $msg     = 'SMS balance check failed: ' . htmlspecialchars((string)$result['error']);
                        $msgType = 'error';
                    } else {
                        $msg     = 'SMS balance check successful. Response: ' . htmlspecialchars(json_encode($result));
                        $msgType = 'success';
                    }
                }
            } else {
                $mailer    = new Mailer();
                $recipient = [['email' => $user['email'] ?? '', 'name' => $user['username'] ?? '']];
                $subject   = 'Test Email — ' . (defined('APP_NAME') ? APP_NAME : 'Marketing Suite');
                $body      = '<p>This is a test email sent from your <strong>' . htmlspecialchars($providerToTest) . '</strong> configuration.</p>';
                try {
                    $sent = $mailer->send($recipient, $subject, $body);
                    if ($sent) {
                        $msg     = 'Test email sent successfully to ' . htmlspecialchars($user['email'] ?? '') . '.';
                        $msgType = 'success';
                    } else {
                        $msg     = 'Test email failed to send. Check your provider configuration.';
                        $msgType = 'error';
                    }
                } catch (\Exception $e) {
                    $msg     = 'Test email error: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

    } // end csrfOk
}

// ─── Load current settings ────────────────────────────────────────────────────

$settings = [];
try {
    $settings = $db->query("SELECT * FROM smtp_settings WHERE id = 1")->fetch() ?: [];
} catch (\Exception $e) { $settings = []; }

$smsConfig = [];
try {
    $smsConfig = $db->query("SELECT * FROM sms_api_config WHERE id = 1")->fetch() ?: [];
} catch (\Exception $e) { $smsConfig = []; }

$activeProvider = $settings['provider'] ?? 'smtp';

$pageTitle  = 'SMTP & API Settings';
$activePage = 'smtp';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div class="page-header-row">
        <h1>⚙️ SMTP & API Settings</h1>
    </div>
</div>

<?php if ($msg !== ''): ?>
<div class="alert alert-<?= htmlspecialchars($msgType) ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ─── Active Provider Selector ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3>Active Email Provider</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="set_provider">
            <div class="form-group">
                <label class="form-label">Active Provider</label>
                <select name="provider" class="form-control" onchange="this.form.submit()">
                    <option value="smtp"      <?= $activeProvider === 'smtp'      ? 'selected' : '' ?>>SMTP</option>
                    <option value="sendgrid"  <?= $activeProvider === 'sendgrid'  ? 'selected' : '' ?>>SendGrid</option>
                    <option value="mailgun"   <?= $activeProvider === 'mailgun'   ? 'selected' : '' ?>>Mailgun</option>
                    <option value="ses"       <?= $activeProvider === 'ses'       ? 'selected' : '' ?>>Amazon SES</option>
                    <option value="resend"    <?= $activeProvider === 'resend'    ? 'selected' : '' ?>>Resend</option>
                    <option value="postmark"  <?= $activeProvider === 'postmark'  ? 'selected' : '' ?>>Postmark</option>
                    <option value="brevo"     <?= $activeProvider === 'brevo'     ? 'selected' : '' ?>>Brevo</option>
                    <option value="mailjet"   <?= $activeProvider === 'mailjet'   ? 'selected' : '' ?>>Mailjet</option>
                    <option value="aweber"    <?= $activeProvider === 'aweber'    ? 'selected' : '' ?>>AWeber</option>
                </select>
            </div>
        </form>
        <p class="form-text">Currently active: <span class="badge badge-sent"><?= htmlspecialchars($activeProvider) ?></span></p>
    </div>
</div>

<!-- ─── Provider Tabs ─────────────────────────────────────────────────────────── -->
<div class="tab-container">
    <div class="tabs">
        <button class="tab-btn active" data-tab="smtp">SMTP</button>
        <button class="tab-btn" data-tab="sendgrid">SendGrid</button>
        <button class="tab-btn" data-tab="mailgun">Mailgun</button>
        <button class="tab-btn" data-tab="ses">Amazon SES</button>
        <button class="tab-btn" data-tab="resend">Resend</button>
        <button class="tab-btn" data-tab="postmark">Postmark</button>
        <button class="tab-btn" data-tab="brevo">Brevo</button>
        <button class="tab-btn" data-tab="mailjet">Mailjet</button>
        <button class="tab-btn" data-tab="aweber">AWeber</button>
        <button class="tab-btn" data-tab="sms-api">SMS API</button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SMTP
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content active" id="tab-smtp">
        <div class="card">
            <div class="card-header">
                <h3>📧 SMTP Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_smtp">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="host" class="form-control"
                                   value="<?= htmlspecialchars($settings['host'] ?? '') ?>"
                                   placeholder="smtp.example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Port</label>
                            <input type="number" name="port" class="form-control"
                                   value="<?= htmlspecialchars((string)($settings['port'] ?? 587)) ?>"
                                   min="1" max="65535">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control"
                                   value="<?= htmlspecialchars($settings['username'] ?? '') ?>"
                                   placeholder="your@email.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <div style="position:relative">
                                <input type="password" id="smtp_password" name="password" class="form-control"
                                       placeholder="<?= !empty($settings['password_encrypted']) ? 'Enter new value to change' : 'Enter password' ?>">
                                <button type="button" onclick="togglePwd('smtp_password')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['password_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Encryption</label>
                            <select name="encryption" class="form-control">
                                <option value="tls"  <?= ($settings['encryption'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl"  <?= ($settings['encryption'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                                <option value="none" <?= ($settings['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">From Email</label>
                            <input type="text" name="from_email" class="form-control"
                                   value="<?= htmlspecialchars($settings['from_email'] ?? '') ?>"
                                   placeholder="noreply@yourdomain.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">From Name</label>
                        <input type="text" name="from_name" class="form-control"
                               value="<?= htmlspecialchars($settings['from_name'] ?? '') ?>"
                               placeholder="Your Company Name">
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save SMTP Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="smtp">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SendGrid
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-sendgrid">
        <div class="card">
            <div class="card-header">
                <h3>SendGrid Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_sendgrid">

                    <div class="form-group">
                        <label class="form-label">API Key</label>
                        <div style="position:relative">
                            <input type="password" id="sendgrid_api_key" name="sendgrid_api_key" class="form-control"
                                   placeholder="<?= !empty($settings['sendgrid_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API key' ?>">
                            <button type="button" onclick="togglePwd('sendgrid_api_key')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($settings['sendgrid_api_key_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save SendGrid Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="sendgrid">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Mailgun
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-mailgun">
        <div class="card">
            <div class="card-header">
                <h3>Mailgun Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_mailgun">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">API Key</label>
                            <div style="position:relative">
                                <input type="password" id="mailgun_api_key" name="mailgun_api_key" class="form-control"
                                       placeholder="<?= !empty($settings['mailgun_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API key' ?>">
                                <button type="button" onclick="togglePwd('mailgun_api_key')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['mailgun_api_key_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Domain</label>
                            <input type="text" name="mailgun_domain" class="form-control"
                                   value="<?= htmlspecialchars($settings['mailgun_domain'] ?? '') ?>"
                                   placeholder="mg.yourdomain.com">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Mailgun Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="mailgun">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Amazon SES
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-ses">
        <div class="card">
            <div class="card-header">
                <h3>Amazon SES Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_ses">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Access Key ID</label>
                            <div style="position:relative">
                                <input type="password" id="ses_key" name="ses_key" class="form-control"
                                       placeholder="<?= !empty($settings['ses_key_encrypted']) ? 'Enter new value to change' : 'Enter Access Key ID' ?>">
                                <button type="button" onclick="togglePwd('ses_key')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['ses_key_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Secret Access Key</label>
                            <div style="position:relative">
                                <input type="password" id="ses_secret" name="ses_secret" class="form-control"
                                       placeholder="<?= !empty($settings['ses_secret_encrypted']) ? 'Enter new value to change' : 'Enter Secret Access Key' ?>">
                                <button type="button" onclick="togglePwd('ses_secret')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['ses_secret_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <input type="text" name="ses_region" class="form-control"
                               value="<?= htmlspecialchars($settings['ses_region'] ?? '') ?>"
                               placeholder="us-east-1">
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save SES Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="ses">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Resend
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-resend">
        <div class="card">
            <div class="card-header">
                <h3>Resend Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_resend">

                    <div class="form-group">
                        <label class="form-label">API Key</label>
                        <div style="position:relative">
                            <input type="password" id="resend_api_key" name="resend_api_key" class="form-control"
                                   placeholder="<?= !empty($settings['resend_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API key' ?>">
                            <button type="button" onclick="togglePwd('resend_api_key')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($settings['resend_api_key_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Resend Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="resend">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Postmark
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-postmark">
        <div class="card">
            <div class="card-header">
                <h3>Postmark Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_postmark">

                    <div class="form-group">
                        <label class="form-label">Server API Token</label>
                        <div style="position:relative">
                            <input type="password" id="postmark_api_key" name="postmark_api_key" class="form-control"
                                   placeholder="<?= !empty($settings['postmark_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API token' ?>">
                            <button type="button" onclick="togglePwd('postmark_api_key')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($settings['postmark_api_key_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Postmark Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="postmark">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Brevo
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-brevo">
        <div class="card">
            <div class="card-header">
                <h3>Brevo Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_brevo">

                    <div class="form-group">
                        <label class="form-label">API Key</label>
                        <div style="position:relative">
                            <input type="password" id="brevo_api_key" name="brevo_api_key" class="form-control"
                                   placeholder="<?= !empty($settings['brevo_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API key' ?>">
                            <button type="button" onclick="togglePwd('brevo_api_key')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($settings['brevo_api_key_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Brevo Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="brevo">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: Mailjet
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-mailjet">
        <div class="card">
            <div class="card-header">
                <h3>Mailjet Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_mailjet">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">API Key</label>
                            <div style="position:relative">
                                <input type="password" id="mailjet_api_key" name="mailjet_api_key" class="form-control"
                                       placeholder="<?= !empty($settings['mailjet_api_key_encrypted']) ? 'Enter new value to change' : 'Enter API key' ?>">
                                <button type="button" onclick="togglePwd('mailjet_api_key')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['mailjet_api_key_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Secret Key</label>
                            <div style="position:relative">
                                <input type="password" id="mailjet_secret_key" name="mailjet_secret_key" class="form-control"
                                       placeholder="<?= !empty($settings['mailjet_secret_key_encrypted']) ? 'Enter new value to change' : 'Enter Secret key' ?>">
                                <button type="button" onclick="togglePwd('mailjet_secret_key')"
                                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                            </div>
                            <?php if (!empty($settings['mailjet_secret_key_encrypted'])): ?>
                            <span class="form-text">Currently configured — leave blank to keep</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Mailjet Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="mailjet">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: AWeber
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-aweber">
        <div class="card">
            <div class="card-header">
                <h3>AWeber Configuration</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_aweber">

                    <div class="form-group">
                        <label class="form-label">Access Token</label>
                        <div style="position:relative">
                            <input type="password" id="aweber_access_token" name="aweber_access_token" class="form-control"
                                   placeholder="<?= !empty($settings['aweber_access_token_encrypted']) ? 'Enter new value to change' : 'Enter Access Token' ?>">
                            <button type="button" onclick="togglePwd('aweber_access_token')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($settings['aweber_access_token_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Account ID</label>
                            <input type="text" name="aweber_account_id" class="form-control"
                                   value="<?= htmlspecialchars($settings['aweber_account_id'] ?? '') ?>"
                                   placeholder="Enter Account ID">
                        </div>
                        <div class="form-group">
                            <label class="form-label">List ID</label>
                            <input type="text" name="aweber_list_id" class="form-control"
                                   value="<?= htmlspecialchars($settings['aweber_list_id'] ?? '') ?>"
                                   placeholder="Enter List ID">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save AWeber Settings</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="aweber">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Sends a test email to <?= htmlspecialchars($user['email'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SMS API
         ══════════════════════════════════════════════════════════════════════ -->
    <div class="tab-content" id="tab-sms-api">
        <div class="card">
            <div class="card-header">
                <h3>📱 PhilmoreSMS API Configuration</h3>
            </div>
            <div class="card-body">
                <p class="form-text" style="margin-bottom:1rem">Configure your PhilmoreSMS API token to enable SMS campaigns.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_sms_api">

                    <div class="form-group">
                        <label class="form-label">PhilmoreSMS API Token</label>
                        <div style="position:relative">
                            <input type="password" id="sms_api_token" name="api_token" class="form-control"
                                   placeholder="<?= !empty($smsConfig['api_token_encrypted']) ? 'Enter new value to change' : 'Enter API token' ?>">
                            <button type="button" onclick="togglePwd('sms_api_token')"
                                    style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-secondary)">👁</button>
                        </div>
                        <?php if (!empty($smsConfig['api_token_encrypted'])): ?>
                        <span class="form-text">Currently configured — leave blank to keep</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save SMS API Token</button>
                </form>
            </div>
            <div class="card-footer" style="border-top:1px solid var(--border);padding:1rem 1.5rem;display:flex;align-items:center;gap:1rem;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="test_connection">
                    <input type="hidden" name="provider_to_test" value="sms">
                    <button type="submit" class="btn btn-secondary btn-sm">🧪 Test Connection</button>
                </form>
                <span class="form-text">Checks SMS balance</span>
            </div>
        </div>
    </div>

</div><!-- /.tab-container -->

<script>
function togglePwd(id) {
    const f = document.getElementById(id);
    f.type = f.type === 'password' ? 'text' : 'password';
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
