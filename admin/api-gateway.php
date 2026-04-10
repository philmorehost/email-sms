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

$flash = '';
$flashType = 'success';

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($csrfToken)) {
        $_SESSION['flash'] = 'Invalid security token.';
        $_SESSION['flash_type'] = 'error';
        redirect('/admin/api-gateway.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'generate_key') {
        $keyName     = sanitize($_POST['key_name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        if (empty($keyName)) {
            $_SESSION['flash'] = 'Key name is required.';
            $_SESSION['flash_type'] = 'error';
            redirect('/admin/api-gateway.php#keys');
        }
        try {
            $apiKey    = bin2hex(random_bytes(32));
            $apiSecret = bin2hex(random_bytes(32));
            $permsJson = json_encode(array_values(array_filter($permissions, fn($p) => is_string($p))));
            $stmt = $db->prepare("INSERT INTO api_keys (user_id, key_name, api_key, api_secret, permissions) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $keyName, $apiKey, $apiSecret, $permsJson]);
            $_SESSION['flash'] = 'API key generated successfully.';
            $_SESSION['flash_type'] = 'success';
            $_SESSION['new_key'] = $apiKey;
            $_SESSION['new_secret'] = $apiSecret;
        } catch (\Exception $e) {
            $_SESSION['flash'] = 'Error generating key: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        redirect('/admin/api-gateway.php#keys');
    }

    if ($action === 'delete_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$keyId, $user['id']]);
            $_SESSION['flash'] = 'API key deleted.';
            $_SESSION['flash_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        redirect('/admin/api-gateway.php#keys');
    }

    if ($action === 'toggle_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE api_keys SET is_active = NOT is_active WHERE id = ? AND user_id = ?");
            $stmt->execute([$keyId, $user['id']]);
            $_SESSION['flash'] = 'API key status updated.';
            $_SESSION['flash_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        redirect('/admin/api-gateway.php#keys');
    }

    if ($action === 'regenerate_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        try {
            $newKey    = bin2hex(random_bytes(32));
            $newSecret = bin2hex(random_bytes(32));
            $stmt = $db->prepare("UPDATE api_keys SET api_key = ?, api_secret = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$newKey, $newSecret, $keyId, $user['id']]);
            $_SESSION['flash'] = 'API key regenerated successfully.';
            $_SESSION['flash_type'] = 'success';
            $_SESSION['new_key'] = $newKey;
            $_SESSION['new_secret'] = $newSecret;
        } catch (\Exception $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
        redirect('/admin/api-gateway.php#keys');
    }

    redirect('/admin/api-gateway.php');
}

// ── Flash messages ─────────────────────────────────────────────────────────────
if (!empty($_SESSION['flash'])) {
    $flash     = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}
$newKey    = $_SESSION['new_key'] ?? null;
$newSecret = $_SESSION['new_secret'] ?? null;
unset($_SESSION['new_key'], $_SESSION['new_secret']);

// ── Fetch API keys ─────────────────────────────────────────────────────────────
$apiKeys = [];
try {
    $stmt = $db->prepare("SELECT * FROM api_keys WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $apiKeys = $stmt->fetchAll();
} catch (\Exception $e) {}

$activeTab = $_GET['tab'] ?? 'keys';
$csrfToken = csrfToken();
$appName   = defined('APP_NAME') ? APP_NAME : 'Marketing Suite';
$baseUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');

$pageTitle  = 'API Gateway';
$activePage = 'api';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <h1>🔑 API Gateway</h1>
    <p>Manage API keys and integrate <?= htmlspecialchars($appName) ?> into your external applications.</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'success' ? 'success' : 'error' ?>">
    <?= $flashType === 'success' ? '✅' : '⚠' ?> <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<?php if ($newKey): ?>
<div class="alert alert-success" style="word-break:break-all">
    <strong>🎉 New key generated — copy it now, it won't be shown again!</strong><br>
    <strong>API Key:</strong> <code><?= htmlspecialchars($newKey) ?></code><br>
    <strong>API Secret:</strong> <code><?= htmlspecialchars($newSecret ?? '') ?></code>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-container">
<div class="tabs">
    <button class="tab-btn <?= $activeTab === 'keys' ? 'active' : '' ?>" data-tab="keys">🔑 My API Keys</button>
    <button class="tab-btn <?= $activeTab === 'docs' ? 'active' : '' ?>" data-tab="docs">📖 Documentation</button>
    <button class="tab-btn <?= $activeTab === 'logs' ? 'active' : '' ?>" data-tab="logs">📋 Logs</button>
</div>

<!-- ── Keys Tab ── -->
<div id="tab-keys" class="tab-content <?= $activeTab === 'keys' ? 'active' : '' ?>">
<div class="card">
    <div class="card-header">
        <h3>Your API Keys</h3>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('generateModal').style.display='flex'">+ Generate New Key</button>
    </div>
    <?php if (empty($apiKeys)): ?>
    <p class="empty-state">No API keys yet. Generate one to get started.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>API Key</th>
                <th>Permissions</th>
                <th>Status</th>
                <th>Last Used</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apiKeys as $key): ?>
        <tr>
            <td><?= htmlspecialchars($key['key_name']) ?></td>
            <td>
                <code class="key-masked" data-full="<?= htmlspecialchars($key['api_key']) ?>">
                    <?= htmlspecialchars(substr($key['api_key'], 0, 8)) ?>••••••••••••••••
                </code>
                <button class="btn btn-sm btn-secondary" onclick="revealKey(this)" style="margin-left:.5rem">👁</button>
            </td>
            <td>
                <?php
                $perms = json_decode($key['permissions'] ?? '[]', true) ?: [];
                foreach ($perms as $p): ?>
                <span class="badge badge-draft" style="font-size:.7rem"><?= htmlspecialchars($p) ?></span>
                <?php endforeach; ?>
            </td>
            <td>
                <span class="badge badge-<?= $key['is_active'] ? 'sent' : 'failed' ?>">
                    <?= $key['is_active'] ? 'Active' : 'Disabled' ?>
                </span>
            </td>
            <td><?= $key['last_used_at'] ? timeAgo($key['last_used_at']) : 'Never' ?></td>
            <td><?= timeAgo($key['created_at']) ?></td>
            <td>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="toggle_key">
                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"><?= $key['is_active'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Regenerate this key? The old key will stop working.')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="regenerate_key">
                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">🔄 Regen</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this API key permanently?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_key">
                    <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">🗑</button>
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

<!-- ── Docs Tab ── -->
<div id="tab-docs" class="tab-content <?= $activeTab === 'docs' ? 'active' : '' ?>">
<div class="card">
    <div class="card-header"><h3>📖 API Documentation</h3></div>
    <div style="padding:1.5rem;line-height:1.8">

    <h4 style="color:var(--accent);margin-bottom:.5rem">🔐 Authentication</h4>
    <p>All API requests must include your API key in the <code>Authorization</code> header:</p>
    <pre class="code-block">Authorization: Bearer YOUR_API_KEY</pre>

    <hr style="border-color:var(--glass-border);margin:1.5rem 0">

    <h4 style="color:var(--accent);margin-bottom:.5rem">📡 Base URL</h4>
    <pre class="code-block"><?= htmlspecialchars($baseUrl) ?>/api/v1</pre>

    <hr style="border-color:var(--glass-border);margin:1.5rem 0">

    <h4 style="color:var(--accent);margin-bottom:1rem">📋 Endpoints</h4>

    <!-- Status -->
    <div class="api-endpoint">
        <div class="api-method get">GET</div>
        <div class="api-path">/api/v1/status</div>
        <p>Check API status and your account information.</p>
        <pre class="code-block">curl -X GET "<?= htmlspecialchars($baseUrl) ?>/api/v1/status" \
  -H "Authorization: Bearer YOUR_API_KEY"</pre>
        <p><strong>Response:</strong></p>
        <pre class="code-block">{
  "status": "ok",
  "user": "your_username",
  "sms_credits": 500.00,
  "email_plan": "Pro"
}</pre>
    </div>

    <!-- Send Email Campaign -->
    <div class="api-endpoint">
        <div class="api-method post">POST</div>
        <div class="api-path">/api/v1/campaigns/email</div>
        <p>Create and send an email campaign programmatically.</p>
        <table class="table" style="margin:.75rem 0">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>name</td><td>string</td><td>✓</td><td>Campaign name</td></tr>
                <tr><td>subject</td><td>string</td><td>✓</td><td>Email subject line</td></tr>
                <tr><td>html_content</td><td>string</td><td>✓</td><td>HTML body of the email</td></tr>
                <tr><td>recipients</td><td>array</td><td>✓</td><td>Array of email addresses</td></tr>
                <tr><td>from_name</td><td>string</td><td></td><td>Sender name</td></tr>
                <tr><td>from_email</td><td>string</td><td></td><td>Sender email address</td></tr>
                <tr><td>schedule_at</td><td>ISO8601</td><td></td><td>Send at a future datetime</td></tr>
            </tbody>
        </table>
        <pre class="code-block">curl -X POST "<?= htmlspecialchars($baseUrl) ?>/api/v1/campaigns/email" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Campaign",
    "subject": "Hello World",
    "html_content": "&lt;h1&gt;Hello!&lt;/h1&gt;",
    "recipients": ["user@example.com"]
  }'</pre>
    </div>

    <!-- Send SMS Campaign -->
    <div class="api-endpoint">
        <div class="api-method post">POST</div>
        <div class="api-path">/api/v1/campaigns/sms</div>
        <p>Send an SMS campaign to one or more recipients.</p>
        <table class="table" style="margin:.75rem 0">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>name</td><td>string</td><td>✓</td><td>Campaign name</td></tr>
                <tr><td>sender_id</td><td>string</td><td>✓</td><td>Approved Sender ID (max 11 chars)</td></tr>
                <tr><td>recipients</td><td>string</td><td>✓</td><td>Comma-separated phone numbers</td></tr>
                <tr><td>message</td><td>string</td><td>✓</td><td>SMS message content</td></tr>
                <tr><td>route</td><td>string</td><td></td><td>bulk (default), corporate, global</td></tr>
            </tbody>
        </table>
        <pre class="code-block">curl -X POST "<?= htmlspecialchars($baseUrl) ?>/api/v1/campaigns/sms" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Promo SMS",
    "sender_id": "MyBrand",
    "recipients": "2348012345678,2349087654321",
    "message": "Special offer just for you!",
    "route": "bulk"
  }'</pre>
    </div>

    <!-- List Contacts -->
    <div class="api-endpoint">
        <div class="api-method get">GET</div>
        <div class="api-path">/api/v1/contacts</div>
        <p>Retrieve your email contact list.</p>
        <pre class="code-block">curl -X GET "<?= htmlspecialchars($baseUrl) ?>/api/v1/contacts?page=1&limit=50" \
  -H "Authorization: Bearer YOUR_API_KEY"</pre>
    </div>

    <!-- Add Contact -->
    <div class="api-endpoint">
        <div class="api-method post">POST</div>
        <div class="api-path">/api/v1/contacts</div>
        <p>Add a new email contact.</p>
        <table class="table" style="margin:.75rem 0">
            <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>email</td><td>string</td><td>✓</td><td>Contact email address</td></tr>
                <tr><td>first_name</td><td>string</td><td></td><td>First name</td></tr>
                <tr><td>last_name</td><td>string</td><td></td><td>Last name</td></tr>
                <tr><td>phone</td><td>string</td><td></td><td>Phone number</td></tr>
                <tr><td>group_id</td><td>integer</td><td></td><td>Contact group ID</td></tr>
            </tbody>
        </table>
        <pre class="code-block">curl -X POST "<?= htmlspecialchars($baseUrl) ?>/api/v1/contacts" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"email":"new@example.com","first_name":"Jane","last_name":"Doe"}'</pre>
    </div>

    <!-- Check Balance -->
    <div class="api-endpoint">
        <div class="api-method get">GET</div>
        <div class="api-path">/api/v1/balance</div>
        <p>Check your current SMS credit balance.</p>
        <pre class="code-block">curl -X GET "<?= htmlspecialchars($baseUrl) ?>/api/v1/balance" \
  -H "Authorization: Bearer YOUR_API_KEY"</pre>
        <p><strong>Response:</strong></p>
        <pre class="code-block">{
  "credits": 1234.50,
  "currency": "NGN"
}</pre>
    </div>

    </div>
</div>
</div>

<!-- ── Logs Tab ── -->
<div id="tab-logs" class="tab-content <?= $activeTab === 'logs' ? 'active' : '' ?>">
<div class="card">
    <div class="card-header"><h3>📋 API Request Logs</h3></div>
    <p class="empty-state" style="padding:2rem">API request logging will be available in a future update. Your API key activity (last_used_at) is tracked per key.</p>
</div>
</div>

</div><!-- .tab-container -->

<!-- Generate Key Modal -->
<div id="generateModal" class="modal" style="display:none">
    <div class="modal-backdrop" onclick="document.getElementById('generateModal').style.display='none'"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Generate New API Key</h3>
            <button class="modal-close" onclick="document.getElementById('generateModal').style.display='none'">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="generate_key">
            <div class="form-group">
                <label for="key_name">Key Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="key_name" name="key_name" placeholder="e.g. My App, Production" required>
            </div>
            <div class="form-group">
                <label>Permissions</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.5rem">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="permissions[]" value="send_email"> Send Email
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="permissions[]" value="send_sms"> Send SMS
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="permissions[]" value="list_campaigns"> List Campaigns
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="permissions[]" value="manage_contacts"> Manage Contacts
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1rem">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('generateModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">🔑 Generate Key</button>
            </div>
        </form>
    </div>
</div>

<style>
.api-endpoint {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.api-method {
    display: inline-block;
    padding: .25rem .75rem;
    border-radius: var(--radius-sm);
    font-weight: 700;
    font-size: .8rem;
    margin-right: .75rem;
    vertical-align: middle;
}
.api-method.get  { background: rgba(0,212,255,.15); color: var(--accent-2); }
.api-method.post { background: rgba(0,255,136,.15); color: var(--success); }
.api-path {
    display: inline-block;
    font-family: monospace;
    font-size: 1rem;
    color: var(--text-primary);
    vertical-align: middle;
    margin-bottom: .75rem;
}
.code-block {
    background: rgba(0,0,0,.3);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    padding: 1rem;
    font-family: monospace;
    font-size: .82rem;
    overflow-x: auto;
    white-space: pre;
    color: var(--text-secondary);
    margin: .5rem 0;
}
.key-masked { font-family: monospace; font-size: .85rem; }
</style>

<script>
function revealKey(btn) {
    const code = btn.previousElementSibling;
    if (btn.textContent === '👁') {
        code.textContent = code.dataset.full;
        btn.textContent = '🙈';
    } else {
        code.textContent = code.dataset.full.slice(0, 8) + '••••••••••••••••';
        btn.textContent = '👁';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
