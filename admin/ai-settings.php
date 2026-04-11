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

// ── Inline migrations ─────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ai_token_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        tokens INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_uat_user (user_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ai_token_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        delta INT NOT NULL,
        action ENUM('purchase','generate','chat','refund','admin_grant') NOT NULL,
        template_id INT NULL,
        campaign_id INT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_atl_user (user_id),
        INDEX idx_atl_action (action)
    )");
    $db->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
        ('deepseek_api_key', ''),
        ('deepseek_model', 'deepseek-chat'),
        ('ai_tokens_per_generation', '50'),
        ('ai_tokens_per_chat_1k', '10'),
        ('ai_tokens_per_sms', '5')
    ");
} catch (\Exception $e) { error_log('ai-settings migration: ' . $e->getMessage()); }

// ── Flash helpers ─────────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['ai_flash_msg']  = $msg;
    $_SESSION['ai_flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['ai_flash_msg']  ?? '';
    $type = $_SESSION['ai_flash_type'] ?? 'success';
    unset($_SESSION['ai_flash_msg'], $_SESSION['ai_flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        header('Location: /admin/ai-settings.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Save API settings
    if ($action === 'save_api_settings') {
        $apiKey    = trim($_POST['deepseek_api_key'] ?? '');
        $model     = in_array($_POST['deepseek_model'] ?? '', ['deepseek-chat', 'deepseek-reasoner'], true)
                     ? $_POST['deepseek_model'] : 'deepseek-chat';
        $perGen    = max(1, (int)($_POST['ai_tokens_per_generation'] ?? 50));
        $perChat1k = max(1, (int)($_POST['ai_tokens_per_chat_1k'] ?? 10));
        $perSms    = max(1, (int)($_POST['ai_tokens_per_sms'] ?? 5));

        try {
            $uStmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            foreach ([
                'deepseek_api_key'          => $apiKey,
                'deepseek_model'            => $model,
                'ai_tokens_per_generation'  => (string)$perGen,
                'ai_tokens_per_chat_1k'     => (string)$perChat1k,
                'ai_tokens_per_sms'         => (string)$perSms,
            ] as $k => $v) {
                $uStmt->execute([$k, $v, $v]);
            }
            setFlash('AI settings saved.');
        } catch (\Exception $e) { setFlash('Error saving settings.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=settings');
        exit;
    }

    // Add package
    if ($action === 'add_package') {
        $name      = sanitize($_POST['name'] ?? '');
        $tokens    = max(1, (int)($_POST['tokens'] ?? 0));
        $price     = max(0.0, (float)($_POST['price'] ?? 0));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') { setFlash('Package name is required.', 'error'); header('Location: /admin/ai-settings.php?tab=packages'); exit; }
        try {
            $db->prepare('INSERT INTO ai_token_packages (name, tokens, price, is_active) VALUES (?,?,?,?)')
               ->execute([$name, $tokens, $price, $is_active]);
            setFlash('Package added.');
        } catch (\Exception $e) { setFlash('Error adding package.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=packages');
        exit;
    }

    // Edit package
    if ($action === 'edit_package') {
        $id        = (int)($_POST['package_id'] ?? 0);
        $name      = sanitize($_POST['name'] ?? '');
        $tokens    = max(1, (int)($_POST['tokens'] ?? 0));
        $price     = max(0.0, (float)($_POST['price'] ?? 0));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if (!$id || $name === '') { setFlash('Invalid data.', 'error'); header('Location: /admin/ai-settings.php?tab=packages'); exit; }
        try {
            $db->prepare('UPDATE ai_token_packages SET name=?, tokens=?, price=?, is_active=? WHERE id=?')
               ->execute([$name, $tokens, $price, $is_active, $id]);
            setFlash('Package updated.');
        } catch (\Exception $e) { setFlash('Error updating package.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=packages');
        exit;
    }

    // Delete package
    if ($action === 'delete_package') {
        $id = (int)($_POST['package_id'] ?? 0);
        try {
            $db->prepare('DELETE FROM ai_token_packages WHERE id=?')->execute([$id]);
            setFlash('Package deleted.');
        } catch (\Exception $e) { setFlash('Error deleting package.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=packages');
        exit;
    }

    // Toggle package
    if ($action === 'toggle_package') {
        $id = (int)($_POST['package_id'] ?? 0);
        try {
            $db->prepare('UPDATE ai_token_packages SET is_active=NOT is_active WHERE id=?')->execute([$id]);
            setFlash('Package status toggled.');
        } catch (\Exception $e) { setFlash('Error toggling package.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=packages');
        exit;
    }

    // Admin grant tokens to user
    if ($action === 'grant_tokens') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $amount       = (int)($_POST['grant_amount'] ?? 0);
        $note         = sanitize($_POST['grant_note'] ?? '');
        if ($targetUserId <= 0 || $amount === 0) { setFlash('Invalid grant data.', 'error'); header('Location: /admin/ai-settings.php?tab=usage'); exit; }
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO user_ai_tokens (user_id, balance) VALUES (?,?) ON DUPLICATE KEY UPDATE balance=balance+?, updated_at=NOW()")
               ->execute([$targetUserId, $amount, $amount]);
            $db->prepare("INSERT INTO ai_token_ledger (user_id, delta, action, description) VALUES (?,?,'admin_grant',?)")
               ->execute([$targetUserId, $amount, $note ?: 'Admin grant']);
            $db->commit();
            setFlash('Tokens granted successfully.');
        } catch (\Exception $e) { $db->rollBack(); setFlash('Error granting tokens.', 'error'); }
        header('Location: /admin/ai-settings.php?tab=usage');
        exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'settings';
$flash     = popFlash();

// App settings
$settings = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Exception $e) {}
$s = fn(string $k, string $d = '') => $settings[$k] ?? $d;

// Packages
$packages = [];
try { $packages = $db->query("SELECT * FROM ai_token_packages ORDER BY price ASC")->fetchAll(); } catch (\Exception $e) {}

// Usage ledger (recent 100)
$ledger = [];
try {
    $ledger = $db->query(
        "SELECT l.*, u.username FROM ai_token_ledger l
         LEFT JOIN users u ON u.id=l.user_id
         ORDER BY l.created_at DESC LIMIT 100"
    )->fetchAll();
} catch (\Exception $e) {}

// Per-user token balances
$userBalances = [];
try {
    $userBalances = $db->query(
        "SELECT t.*, u.username FROM user_ai_tokens t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.balance DESC LIMIT 50"
    )->fetchAll();
} catch (\Exception $e) {}

// Users list for grant form
$allUsers = [];
try { $allUsers = $db->query("SELECT id, username FROM users WHERE role='user' ORDER BY username")->fetchAll(); } catch (\Exception $e) {}

// Total tokens consumed this month
$monthlyConsumed = 0;
try {
    $row = $db->query("SELECT SUM(ABS(delta)) FROM ai_token_ledger WHERE action IN ('generate','chat') AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();
    $monthlyConsumed = (int)($row ?: 0);
} catch (\Exception $e) {}

$pageTitle  = 'AI Settings';
$activePage = 'ai_settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🤖 AI Settings</h1>
        <p class="page-subtitle">Configure DeepSeek integration, token packages, and monitor usage</p>
    </div>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:1rem">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" style="margin-bottom:1.5rem">
    <a href="/admin/ai-settings.php?tab=settings"  class="tab-btn <?= $activeTab === 'settings'  ? 'active' : '' ?>">⚙️ API Settings</a>
    <a href="/admin/ai-settings.php?tab=packages"  class="tab-btn <?= $activeTab === 'packages'  ? 'active' : '' ?>">📦 Token Packages</a>
    <a href="/admin/ai-settings.php?tab=usage"     class="tab-btn <?= $activeTab === 'usage'     ? 'active' : '' ?>">📊 Usage & Grants</a>
</div>

<!-- ── API SETTINGS TAB ──────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'settings' ? 'active' : '' ?>">
    <div class="card" style="max-width:680px">
        <div class="card-header"><h3>🔑 DeepSeek API Configuration</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_api_settings">

                <div class="form-group" style="margin-bottom:1.2rem">
                    <label class="form-label">DeepSeek API Key</label>
                    <input type="password" name="deepseek_api_key" class="form-control"
                           value="<?= htmlspecialchars($s('deepseek_api_key')) ?>"
                           placeholder="sk-xxxxxxxx" autocomplete="new-password">
                    <small style="color:var(--text-muted)">Get your key from <a href="https://platform.deepseek.com" target="_blank" rel="noopener">platform.deepseek.com</a></small>
                </div>

                <div class="form-group" style="margin-bottom:1.2rem">
                    <label class="form-label">Default Model</label>
                    <select name="deepseek_model" class="form-control">
                        <option value="deepseek-chat"     <?= $s('deepseek_model') === 'deepseek-chat'     ? 'selected' : '' ?>>deepseek-chat (fast, cost-effective)</option>
                        <option value="deepseek-reasoner" <?= $s('deepseek_model') === 'deepseek-reasoner' ? 'selected' : '' ?>>deepseek-reasoner (complex layouts)</option>
                    </select>
                    <small style="color:var(--text-muted)">Users generating complex multi-section layouts benefit from deepseek-reasoner.</small>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.2rem">
                    <div class="form-group">
                        <label class="form-label">Tokens per Template Generation</label>
                        <input type="number" name="ai_tokens_per_generation" class="form-control" min="1" max="10000"
                               value="<?= (int)$s('ai_tokens_per_generation', '50') ?>">
                        <small style="color:var(--text-muted)">Deducted per full template generation.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tokens per 1,000 AI Words (Chat)</label>
                        <input type="number" name="ai_tokens_per_chat_1k" class="form-control" min="1" max="10000"
                               value="<?= (int)$s('ai_tokens_per_chat_1k', '10') ?>">
                        <small style="color:var(--text-muted)">Proportional deduction for chat/refinement responses.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tokens per SMS Generation</label>
                        <input type="number" name="ai_tokens_per_sms" class="form-control" min="1" max="10000"
                               value="<?= (int)$s('ai_tokens_per_sms', '5') ?>">
                        <small style="color:var(--text-muted)">Deducted each time the AI writes an SMS message for a user.</small>
                    </div>
                </div>

                <div style="background:rgba(108,99,255,.08);border:1px solid rgba(108,99,255,.25);border-radius:12px;padding:1rem;margin-bottom:1.25rem;font-size:.87rem;color:var(--text-muted)">
                    <strong>ℹ️ Token deduction policy:</strong> Tokens are only deducted <em>after</em> a successful API response.
                    Failed or timeout requests do not consume tokens.
                </div>

                <div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:1rem;margin-bottom:1.25rem;font-size:.87rem">
                    <strong>📊 This month:</strong> <?= number_format($monthlyConsumed) ?> AI tokens consumed across all users.
                </div>

                <button type="submit" class="btn btn-primary">💾 Save Settings</button>
            </form>
        </div>
    </div>
</div>

<!-- ── PACKAGES TAB ───────────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'packages' ? 'active' : '' ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

        <!-- Add package -->
        <div class="card">
            <div class="card-header"><h3>➕ Add Token Package</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_package">
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">Package Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Starter Pack" required maxlength="100">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem">
                        <div class="form-group">
                            <label class="form-label">AI Tokens</label>
                            <input type="number" name="tokens" class="form-control" min="1" placeholder="1000" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Price (<?= htmlspecialchars(currencySymbol()) ?>)</label>
                            <input type="number" name="price" class="form-control" min="0" step="0.01" placeholder="500.00" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                            <input type="checkbox" name="is_active" checked> Active (visible to users)
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Package</button>
                </form>
            </div>
        </div>

        <!-- Packages list -->
        <div class="card">
            <div class="card-header"><h3>📦 Existing Packages</h3></div>
            <?php if (empty($packages)): ?>
            <div class="card-body" style="text-align:center;color:var(--text-muted);padding:2rem">No packages yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Name</th><th>Tokens</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($packages as $pkg): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pkg['name']) ?></strong></td>
                    <td><?= number_format((int)$pkg['tokens']) ?></td>
                    <td><?= formatMoney((float)$pkg['price']) ?></td>
                    <td>
                        <?php if ($pkg['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <!-- Edit -->
                        <button class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('editPkgModal<?= $pkg['id'] ?>').style.display='flex'">
                            ✏️
                        </button>
                        <!-- Toggle -->
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle_package">
                            <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><?= $pkg['is_active'] ? '🔴' : '🟢' ?></button>
                        </form>
                        <!-- Delete -->
                        <form method="POST" onsubmit="return confirm('Delete this package?')" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_package">
                            <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                        </form>
                    </td>
                </tr>

                <!-- Edit modal -->
                <tr id="editPkgModal<?= $pkg['id'] ?>" style="display:none">
                    <td colspan="5">
                        <form method="POST" style="display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:.5rem;align-items:end;padding:.75rem;background:rgba(108,99,255,.07);border-radius:8px">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="edit_package">
                            <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                            <div>
                                <label class="form-label" style="font-size:.8rem">Name</label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($pkg['name']) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:.8rem">Tokens</label>
                                <input type="number" name="tokens" class="form-control" min="1" value="<?= (int)$pkg['tokens'] ?>" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:.8rem">Price</label>
                                <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= number_format((float)$pkg['price'], 2) ?>" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:.8rem">Active</label>
                                <input type="checkbox" name="is_active" <?= $pkg['is_active'] ? 'checked' : '' ?> style="margin-top:.5rem">
                            </div>
                            <div style="display:flex;gap:.3rem">
                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="document.getElementById('editPkgModal<?= $pkg['id'] ?>').style.display='none'">✕</button>
                            </div>
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
</div>

<!-- ── USAGE & GRANTS TAB ──────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'usage' ? 'active' : '' ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;align-items:start">

        <!-- User balances -->
        <div class="card">
            <div class="card-header"><h3>🏦 User Token Balances</h3></div>
            <?php if (empty($userBalances)): ?>
            <div class="card-body" style="color:var(--text-muted);text-align:center;padding:2rem">No AI token activity yet.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead><tr><th>User</th><th>Balance</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($userBalances as $ub): ?>
                <tr>
                    <td><?= htmlspecialchars($ub['username'] ?? '#' . $ub['user_id']) ?></td>
                    <td><strong><?= number_format((int)$ub['balance']) ?></strong> tokens</td>
                    <td style="font-size:.82rem;color:var(--text-muted)"><?= timeAgo($ub['updated_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Grant tokens -->
        <div class="card">
            <div class="card-header"><h3>🎁 Grant Tokens to User</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="grant_tokens">
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">Select User</label>
                        <select name="target_user_id" class="form-control" required>
                            <option value="">-- Choose user --</option>
                            <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">Tokens to Grant</label>
                        <input type="number" name="grant_amount" class="form-control" min="1" placeholder="500" required>
                        <small style="color:var(--text-muted)">Use a negative number to deduct tokens.</small>
                    </div>
                    <div class="form-group" style="margin-bottom:1rem">
                        <label class="form-label">Note (optional)</label>
                        <input type="text" name="grant_note" class="form-control" placeholder="Reason for grant" maxlength="255">
                    </div>
                    <button type="submit" class="btn btn-primary">🎁 Grant Tokens</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Ledger -->
    <div class="card">
        <div class="card-header"><h3>📋 Recent AI Token Ledger (last 100)</h3></div>
        <?php if (empty($ledger)): ?>
        <div class="card-body" style="text-align:center;color:var(--text-muted);padding:2rem">No activity yet.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Delta</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $row): ?>
            <tr>
                <td style="font-size:.82rem"><?= timeAgo($row['created_at']) ?></td>
                <td><?= htmlspecialchars($row['username'] ?? '#' . $row['user_id']) ?></td>
                <td>
                    <?php $actionColors = ['purchase'=>'#10b981','generate'=>'#f59e0b','chat'=>'#6c63ff','refund'=>'#06b6d4','admin_grant'=>'#8b5cf6']; ?>
                    <span style="background:<?= $actionColors[$row['action']] ?? '#666' ?>22;color:<?= $actionColors[$row['action']] ?? '#aaa' ?>;padding:2px 8px;border-radius:6px;font-size:.8rem">
                        <?= htmlspecialchars($row['action']) ?>
                    </span>
                </td>
                <td style="color:<?= $row['delta'] > 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600">
                    <?= $row['delta'] > 0 ? '+' : '' ?><?= number_format((int)$row['delta']) ?>
                </td>
                <td style="font-size:.85rem"><?= htmlspecialchars($row['description'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.tabs{display:flex;gap:.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--glass-bg,rgba(255,255,255,0.05));color:var(--text-muted,#606070);cursor:pointer;border-radius:8px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--glass-border,rgba(255,255,255,0.1))}
.tab-btn.active{background:var(--accent,#6c63ff);color:#fff;border-color:var(--accent,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
</style>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
