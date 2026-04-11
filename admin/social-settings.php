<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

setSecurityHeaders();
requireAdmin();

$db   = getDB();
$user = getCurrentUser();

AyrshareClient::migrate($db);

// ── Flash helpers ─────────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['soc_flash_msg']  = $msg;
    $_SESSION['soc_flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['soc_flash_msg']  ?? '';
    $type = $_SESSION['soc_flash_type'] ?? 'success';
    unset($_SESSION['soc_flash_msg'], $_SESSION['soc_flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        header('Location: /admin/social-settings.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $fields = [
            'ayrshare_api_key'                 => trim($_POST['ayrshare_api_key'] ?? ''),
            'social_enabled'                   => isset($_POST['social_enabled']) ? '1' : '0',
            'social_tokens_per_post_now'       => max(1, (int)($_POST['social_tokens_per_post_now'] ?? 1)),
            'social_tokens_per_scheduled_post' => max(1, (int)($_POST['social_tokens_per_scheduled_post'] ?? 5)),
            'social_tokens_per_ab_variant'     => max(1, (int)($_POST['social_tokens_per_ab_variant'] ?? 2)),
        ];
        foreach ($fields as $k => $v) {
            $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?, updated_at=NOW()")
               ->execute([$k, $v, $v]);
        }
        setFlash('Settings saved.');
        header('Location: /admin/social-settings.php?tab=settings');
        exit;
    }

    if ($action === 'add_package') {
        $name   = trim($_POST['pkg_name'] ?? '');
        $tokens = max(1, (int)($_POST['pkg_tokens'] ?? 0));
        $price  = max(0.01, (float)($_POST['pkg_price'] ?? 0));
        if ($name === '') { setFlash('Package name is required.', 'error'); header('Location: /admin/social-settings.php?tab=packages'); exit; }
        $db->prepare("INSERT INTO social_token_packages (name,tokens,price) VALUES(?,?,?)")->execute([$name, $tokens, $price]);
        setFlash('Package added.');
        header('Location: /admin/social-settings.php?tab=packages');
        exit;
    }

    if ($action === 'toggle_package') {
        $pid = (int)($_POST['pkg_id'] ?? 0);
        $db->prepare("UPDATE social_token_packages SET is_active = NOT is_active WHERE id=?")->execute([$pid]);
        setFlash('Package updated.');
        header('Location: /admin/social-settings.php?tab=packages');
        exit;
    }

    if ($action === 'delete_package') {
        $pid = (int)($_POST['pkg_id'] ?? 0);
        $db->prepare("DELETE FROM social_token_packages WHERE id=?")->execute([$pid]);
        setFlash('Package deleted.');
        header('Location: /admin/social-settings.php?tab=packages');
        exit;
    }

    if ($action === 'grant_tokens') {
        $targetId = (int)($_POST['target_user_id'] ?? 0);
        $amount   = (int)($_POST['grant_amount'] ?? 0);
        $note     = trim($_POST['grant_note'] ?? '');
        if ($targetId <= 0 || $amount === 0) { setFlash('Invalid input.', 'error'); header('Location: /admin/social-settings.php?tab=usage'); exit; }
        AyrshareClient::addTokens($db, $targetId, $amount, 'admin_grant', $note ?: 'Admin grant');
        setFlash('Granted ' . $amount . ' social tokens to user #' . $targetId . '.');
        header('Location: /admin/social-settings.php?tab=usage');
        exit;
    }

    header('Location: /admin/social-settings.php');
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$settings  = AyrshareClient::loadSettings($db);
$activeTab = $_GET['tab'] ?? 'settings';
$flash     = popFlash();

$packages = $db->query("SELECT * FROM social_token_packages ORDER BY price ASC")->fetchAll();

$userBalances = [];
try {
    $userBalances = $db->query(
        "SELECT ust.*, u.username FROM user_social_tokens ust
         LEFT JOIN users u ON u.id=ust.user_id
         ORDER BY ust.balance DESC LIMIT 50"
    )->fetchAll();
} catch (\Exception $e) {}

$ledger = [];
try {
    $ledger = $db->query(
        "SELECT sct.*, u.username FROM social_credit_transactions sct
         LEFT JOIN users u ON u.id=sct.user_id
         ORDER BY sct.created_at DESC LIMIT 100"
    )->fetchAll();
} catch (\Exception $e) {}

$allUsers = $db->query("SELECT id, username FROM users WHERE role='user' ORDER BY username ASC")->fetchAll();

$campaigns = [];
try {
    $campaigns = $db->query(
        "SELECT sc.*, u.username FROM social_campaigns sc
         LEFT JOIN users u ON u.id=sc.user_id
         ORDER BY sc.created_at DESC LIMIT 100"
    )->fetchAll();
} catch (\Exception $e) {}

// ── Page ──────────────────────────────────────────────────────────────────────
$pageTitle  = 'Social Media Settings';
$activePage = 'social_settings';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header" style="margin-bottom:1.5rem">
    <h1 class="page-title">📱 Social Media Marketing Settings</h1>
    <p style="color:var(--text-muted)">Configure Ayrshare API, token pricing, and monitor campaigns.</p>
</div>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:1.5rem">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="tabs" style="margin-bottom:1.5rem">
    <a href="?tab=settings"  class="tab-btn <?= $activeTab === 'settings'  ? 'active' : '' ?>">⚙️ Settings</a>
    <a href="?tab=packages"  class="tab-btn <?= $activeTab === 'packages'  ? 'active' : '' ?>">📦 Token Packages</a>
    <a href="?tab=campaigns" class="tab-btn <?= $activeTab === 'campaigns' ? 'active' : '' ?>">📋 Campaigns</a>
    <a href="?tab=usage"     class="tab-btn <?= $activeTab === 'usage'     ? 'active' : '' ?>">📊 Usage &amp; Grants</a>
</div>

<!-- SETTINGS TAB -->
<div class="tab-pane <?= $activeTab === 'settings' ? 'active' : '' ?>">
<div class="card">
    <div class="card-header"><h3>🔑 API &amp; Pricing Configuration</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group" style="margin-bottom:1.5rem">
                <label class="form-label">Ayrshare API Key</label>
                <input type="password" name="ayrshare_api_key" class="form-control"
                       value="<?= htmlspecialchars($settings['ayrshare_api_key']) ?>"
                       placeholder="sk-…" autocomplete="new-password">
                <small style="color:var(--text-muted)">
                    Get your key at <a href="https://www.ayrshare.com" target="_blank" rel="noopener">ayrshare.com</a>.
                    Requires a Business Plan to enable user profile creation.
                </small>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="social_enabled" value="1"
                           <?= $settings['social_enabled'] === '1' ? 'checked' : '' ?>>
                    Enable Social Media Marketing feature for all users
                </label>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
                <div class="form-group">
                    <label class="form-label">Tokens — Post Now</label>
                    <input type="number" name="social_tokens_per_post_now" class="form-control"
                           value="<?= (int)($settings['social_tokens_per_post_now'] ?? 1) ?>" min="1">
                    <small style="color:var(--text-muted)">Deducted per immediate post</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Tokens — Scheduled Post</label>
                    <input type="number" name="social_tokens_per_scheduled_post" class="form-control"
                           value="<?= (int)($settings['social_tokens_per_scheduled_post'] ?? 5) ?>" min="1">
                    <small style="color:var(--text-muted)">Deducted per scheduled post</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Tokens — A/B Variant</label>
                    <input type="number" name="social_tokens_per_ab_variant" class="form-control"
                           value="<?= (int)($settings['social_tokens_per_ab_variant'] ?? 2) ?>" min="1">
                    <small style="color:var(--text-muted)">Per extra A/B variant generated</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">💾 Save Settings</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:1.5rem">
    <div class="card-header"><h3>📋 Cron Job Setup</h3></div>
    <div class="card-body">
        <p style="color:var(--text-muted);margin-bottom:1rem">
            Add the following cron entry to fire scheduled posts every 5 minutes:
        </p>
        <code style="display:block;background:rgba(0,0,0,.3);padding:1rem;border-radius:8px;font-size:.9rem">
            */5 * * * * php <?= htmlspecialchars(dirname(__DIR__)) ?>/cron/social-scheduler.php >> /var/log/social-scheduler.log 2>&amp;1
        </code>
    </div>
</div>
</div>

<!-- PACKAGES TAB -->
<div class="tab-pane <?= $activeTab === 'packages' ? 'active' : '' ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

    <div class="card">
        <div class="card-header"><h3>➕ Add Token Package</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_package">
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Package Name</label>
                    <input type="text" name="pkg_name" class="form-control" placeholder="Starter Pack" required>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Tokens</label>
                    <input type="number" name="pkg_tokens" class="form-control" min="1" placeholder="100" required>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Price (<?= htmlspecialchars(currencySymbol()) ?>)</label>
                    <input type="number" name="pkg_price" class="form-control" min="0.01" step="0.01" placeholder="500.00" required>
                </div>
                <button type="submit" class="btn btn-primary">➕ Add Package</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>📦 Existing Packages</h3></div>
        <?php if (empty($packages)): ?>
        <div class="card-body" style="color:var(--text-muted);text-align:center;padding:2rem">No packages yet.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Tokens</th><th>Price</th><th>Active</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($packages as $pkg): ?>
            <tr>
                <td><?= htmlspecialchars($pkg['name']) ?></td>
                <td><?= number_format((int)$pkg['tokens']) ?></td>
                <td><?= htmlspecialchars(currencySymbol()) ?><?= number_format((float)$pkg['price'], 2) ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action" value="toggle_package">
                        <input type="hidden" name="pkg_id" value="<?= (int)$pkg['id'] ?>">
                        <button class="btn btn-sm <?= $pkg['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                            <?= $pkg['is_active'] ? '✅' : '⛔' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete package?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_package">
                        <input type="hidden" name="pkg_id" value="<?= (int)$pkg['id'] ?>">
                        <button class="btn btn-sm btn-danger">🗑</button>
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

<!-- CAMPAIGNS TAB -->
<div class="tab-pane <?= $activeTab === 'campaigns' ? 'active' : '' ?>">
<div class="card">
    <div class="card-header"><h3>📋 All Social Campaigns</h3></div>
    <?php if (empty($campaigns)): ?>
    <div class="card-body" style="color:var(--text-muted);text-align:center;padding:2rem">No campaigns yet.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>User</th><th>Platforms</th><th>Status</th><th>Scheduled</th><th>Posted</th><th>Created</th></tr></thead>
        <tbody>
        <?php
        $statusColors = ['draft'=>'#aaa','scheduled'=>'#f59e0b','posting'=>'#6c63ff','posted'=>'#10b981','failed'=>'#ef4444'];
        foreach ($campaigns as $c):
        ?>
        <tr>
            <td>#<?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['username'] ?? '#' . $c['user_id']) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($c['platform_mask']) ?></td>
            <td>
                <span style="background:<?= ($statusColors[$c['status']] ?? '#aaa') ?>22;color:<?= $statusColors[$c['status']] ?? '#aaa' ?>;padding:2px 8px;border-radius:6px;font-size:.8rem">
                    <?= htmlspecialchars($c['status']) ?>
                </span>
            </td>
            <td style="font-size:.82rem"><?= $c['scheduled_at'] ? htmlspecialchars(substr($c['scheduled_at'], 0, 16)) : '—' ?></td>
            <td style="font-size:.82rem"><?= $c['posted_at'] ? htmlspecialchars(substr($c['posted_at'], 0, 16)) : '—' ?></td>
            <td style="font-size:.82rem"><?= timeAgo($c['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- USAGE & GRANTS TAB -->
<div class="tab-pane <?= $activeTab === 'usage' ? 'active' : '' ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;align-items:start">

    <div class="card">
        <div class="card-header"><h3>🏦 User Social Token Balances</h3></div>
        <?php if (empty($userBalances)): ?>
        <div class="card-body" style="color:var(--text-muted);text-align:center;padding:2rem">No social token activity yet.</div>
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

    <div class="card">
        <div class="card-header"><h3>🎁 Grant Social Tokens</h3></div>
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
                    <input type="number" name="grant_amount" class="form-control" min="1" placeholder="50" required>
                </div>
                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Note (optional)</label>
                    <input type="text" name="grant_note" class="form-control" placeholder="Reason" maxlength="255">
                </div>
                <button type="submit" class="btn btn-primary">🎁 Grant Tokens</button>
            </form>
        </div>
    </div>

</div>

<div class="card">
    <div class="card-header"><h3>📋 Recent Social Token Ledger (last 100)</h3></div>
    <?php if (empty($ledger)): ?>
    <div class="card-body" style="text-align:center;color:var(--text-muted);padding:2rem">No activity yet.</div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Delta</th><th>Description</th></tr></thead>
        <tbody>
        <?php
        $actionColors = ['purchase'=>'#10b981','post_now'=>'#6c63ff','scheduled_post'=>'#f59e0b','ab_variant'=>'#06b6d4','refund'=>'#06b6d4','admin_grant'=>'#8b5cf6'];
        foreach ($ledger as $row):
        ?>
        <tr>
            <td style="font-size:.82rem"><?= timeAgo($row['created_at']) ?></td>
            <td><?= htmlspecialchars($row['username'] ?? '#' . $row['user_id']) ?></td>
            <td>
                <span style="background:<?= ($actionColors[$row['action']] ?? '#666') ?>22;color:<?= $actionColors[$row['action']] ?? '#aaa' ?>;padding:2px 8px;border-radius:6px;font-size:.8rem">
                    <?= htmlspecialchars($row['action']) ?>
                </span>
            </td>
            <td style="color:<?= $row['delta'] > 0 ? 'var(--success,#10b981)' : 'var(--danger,#ef4444)' ?>;font-weight:600">
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
