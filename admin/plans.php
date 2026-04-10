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

function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['flash_msg']  ?? '';
    $type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    return ['msg' => $msg, 'type' => $type];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/plans.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_plan') {
        $name        = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $limit       = (int)($_POST['monthly_email_limit'] ?? 1000);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $featuresRaw = $_POST['features'] ?? '';
        $featuresArr = array_values(array_filter(array_map('trim', explode("\n", $featuresRaw))));
        $features    = json_encode($featuresArr);
        if ($name === '') { setFlash('Plan name is required.', 'error'); redirect('/admin/plans.php?tab=email_plans'); }
        try {
            $stmt = $db->prepare('INSERT INTO email_plans (name,description,price,monthly_email_limit,features,is_active) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$name, $description, $price, $limit, $features, $is_active]);
            setFlash('Plan added.');
        } catch (\Exception $e) { setFlash('Error adding plan.', 'error'); }
        redirect('/admin/plans.php?tab=email_plans');
    }

    if ($action === 'edit_plan') {
        $id          = (int)($_POST['plan_id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $limit       = (int)($_POST['monthly_email_limit'] ?? 1000);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $featuresRaw = $_POST['features'] ?? '';
        $featuresArr = array_values(array_filter(array_map('trim', explode("\n", $featuresRaw))));
        $features    = json_encode($featuresArr);
        if (!$id || $name === '') { setFlash('Invalid data.', 'error'); redirect('/admin/plans.php?tab=email_plans'); }
        try {
            $stmt = $db->prepare('UPDATE email_plans SET name=?,description=?,price=?,monthly_email_limit=?,features=?,is_active=? WHERE id=?');
            $stmt->execute([$name, $description, $price, $limit, $features, $is_active, $id]);
            setFlash('Plan updated.');
        } catch (\Exception $e) { setFlash('Error updating plan.', 'error'); }
        redirect('/admin/plans.php?tab=email_plans');
    }

    if ($action === 'delete_plan') {
        $id = (int)($_POST['plan_id'] ?? 0);
        try {
            $stmt = $db->prepare('DELETE FROM email_plans WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Plan deleted.');
        } catch (\Exception $e) { setFlash('Error deleting plan.', 'error'); }
        redirect('/admin/plans.php?tab=email_plans');
    }

    if ($action === 'toggle_plan') {
        $id = (int)($_POST['plan_id'] ?? 0);
        try {
            $stmt = $db->prepare('UPDATE email_plans SET is_active=NOT is_active WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Plan status toggled.');
        } catch (\Exception $e) { setFlash('Error toggling plan.', 'error'); }
        redirect('/admin/plans.php?tab=email_plans');
    }

    if ($action === 'add_package') {
        $name           = sanitize($_POST['name'] ?? '');
        $credits        = (int)($_POST['credits'] ?? 0);
        $price          = (float)($_POST['price'] ?? 0);
        $billing_period = sanitize($_POST['billing_period'] ?? 'one_time');
        if (!in_array($billing_period, ['one_time','monthly','quarterly','yearly'], true)) $billing_period = 'one_time';
        $is_active      = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') { setFlash('Package name is required.', 'error'); redirect('/admin/plans.php?tab=sms_packages'); }
        try {
            $stmt = $db->prepare('INSERT INTO sms_credit_packages (name,credits,price,billing_period,is_active) VALUES (?,?,?,?,?)');
            $stmt->execute([$name, $credits, $price, $billing_period, $is_active]);
            setFlash('Package added.');
        } catch (\Exception $e) { setFlash('Error adding package.', 'error'); }
        redirect('/admin/plans.php?tab=sms_packages');
    }

    if ($action === 'edit_package') {
        $id             = (int)($_POST['package_id'] ?? 0);
        $name           = sanitize($_POST['name'] ?? '');
        $credits        = (int)($_POST['credits'] ?? 0);
        $price          = (float)($_POST['price'] ?? 0);
        $billing_period = sanitize($_POST['billing_period'] ?? 'one_time');
        if (!in_array($billing_period, ['one_time','monthly','quarterly','yearly'], true)) $billing_period = 'one_time';
        $is_active      = isset($_POST['is_active']) ? 1 : 0;
        if (!$id || $name === '') { setFlash('Invalid data.', 'error'); redirect('/admin/plans.php?tab=sms_packages'); }
        try {
            $stmt = $db->prepare('UPDATE sms_credit_packages SET name=?,credits=?,price=?,billing_period=?,is_active=? WHERE id=?');
            $stmt->execute([$name, $credits, $price, $billing_period, $is_active, $id]);
            setFlash('Package updated.');
        } catch (\Exception $e) { setFlash('Error updating package.', 'error'); }
        redirect('/admin/plans.php?tab=sms_packages');
    }

    if ($action === 'save_sms_price') {
        $price = (float)($_POST['sms_price_per_unit'] ?? 0);
        if ($price < 0) { setFlash('Price cannot be negative.', 'error'); redirect('/admin/plans.php?tab=sms_settings'); }
        try {
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('sms_price_per_unit', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$price, $price]);
            setFlash('SMS price per unit updated.');
        } catch (\Exception $e) { setFlash('Error saving price.', 'error'); }
        redirect('/admin/plans.php?tab=sms_settings');
    }

    if ($action === 'approve_purchase') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        try {
            $reqStmt = $db->prepare('SELECT r.*, p.credits FROM sms_purchase_requests r JOIN sms_credit_packages p ON p.id = r.package_id WHERE r.id = ? AND r.status = \'pending\'');
            $reqStmt->execute([$reqId]);
            $req = $reqStmt->fetch();
            if (!$req) { setFlash('Request not found or already processed.', 'error'); redirect('/admin/plans.php?tab=purchase_requests'); }
            $db->prepare('INSERT INTO user_sms_wallet (user_id, credits) VALUES (?, ?) ON DUPLICATE KEY UPDATE credits = credits + ?, updated_at = NOW()')->execute([$req['user_id'], $req['credits'], $req['credits']]);
            $db->prepare('INSERT INTO sms_credit_transactions (user_id, amount, type, description, reference) VALUES (?, ?, \'credit\', ?, ?)')->execute([$req['user_id'], $req['credits'], 'Package purchase approved', 'purchase_' . $reqId]);
            $db->prepare('UPDATE sms_purchase_requests SET status = \'approved\', processed_at = NOW(), processed_by = ? WHERE id = ?')->execute([(int)($user['id'] ?? 0), $reqId]);
            setFlash('Purchase approved and credits added.');
        } catch (\Exception $e) { setFlash('Error approving request.', 'error'); }
        redirect('/admin/plans.php?tab=purchase_requests');
    }

    if ($action === 'reject_purchase') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');
        try {
            $db->prepare('UPDATE sms_purchase_requests SET status = \'rejected\', notes = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = \'pending\'')->execute([$notes, (int)($user['id'] ?? 0), $reqId]);
            setFlash('Purchase request rejected.');
        } catch (\Exception $e) { setFlash('Error rejecting request.', 'error'); }
        redirect('/admin/plans.php?tab=purchase_requests');
    }

    if ($action === 'delete_package') {
        $id = (int)($_POST['package_id'] ?? 0);
        try {
            $stmt = $db->prepare('DELETE FROM sms_credit_packages WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Package deleted.');
        } catch (\Exception $e) { setFlash('Error deleting package.', 'error'); }
        redirect('/admin/plans.php?tab=sms_packages');
    }

    if ($action === 'toggle_package') {
        $id = (int)($_POST['package_id'] ?? 0);
        try {
            $stmt = $db->prepare('UPDATE sms_credit_packages SET is_active=NOT is_active WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Package status toggled.');
        } catch (\Exception $e) { setFlash('Error toggling package.', 'error'); }
        redirect('/admin/plans.php?tab=sms_packages');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/plans.php');
}

$flash     = popFlash();
$activeTab = $_GET['tab'] ?? 'email_plans';

$plans = [];
try {
    $plans = $db->query('SELECT * FROM email_plans ORDER BY created_at DESC')->fetchAll();
} catch (\Exception $e) {}

$packages = [];
try {
    $packages = $db->query('SELECT * FROM sms_credit_packages ORDER BY created_at DESC')->fetchAll();
} catch (\Exception $e) {}

// Migration: ensure billing_period column exists for existing installs
try {
    $cols = $db->query("SHOW COLUMNS FROM sms_credit_packages LIKE 'billing_period'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE sms_credit_packages ADD COLUMN billing_period ENUM('one_time','monthly','quarterly','yearly') NOT NULL DEFAULT 'one_time' AFTER price");
    }
} catch (\Exception $e) {}

// Migration: ensure sms_purchase_requests table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS sms_purchase_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        package_id INT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        notes VARCHAR(255),
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT NULL,
        INDEX idx_user_req (user_id),
        INDEX idx_status_req (status)
    )");
} catch (\Exception $e) {}

// Migration: ensure sms_price_per_unit setting exists
try {
    $db->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('sms_price_per_unit', '6.50')");
} catch (\Exception $e) {}

$smsPrice = 6.50;
try {
    $row = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sms_price_per_unit'")->fetch();
    if ($row) $smsPrice = (float)$row['setting_value'];
} catch (\Exception $e) {}

$purchaseRequests = [];
try {
    $purchaseRequests = $db->query(
        "SELECT r.*, u.username, p.name AS package_name, p.credits, p.price, p.billing_period
         FROM sms_purchase_requests r
         LEFT JOIN users u ON u.id = r.user_id
         LEFT JOIN sms_credit_packages p ON p.id = r.package_id
         ORDER BY r.requested_at DESC LIMIT 200"
    )->fetchAll();
} catch (\Exception $e) {}

$pageTitle  = 'Plans & Packages';
$activePage = 'plans';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--card-bg,#1e293b);color:var(--text-muted,#94a3b8);cursor:pointer;border-radius:6px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--border-color,#334155)}
.tab-btn.active{background:var(--primary,#6c63ff);color:#fff;border-color:var(--primary,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:2rem;min-width:320px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto}
</style>

<h1>Plans &amp; Packages</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="tabs">
    <a href="/admin/plans.php?tab=email_plans"       class="tab-btn <?= $activeTab === 'email_plans'       ? 'active' : '' ?>">Email Plans</a>
    <a href="/admin/plans.php?tab=sms_packages"      class="tab-btn <?= $activeTab === 'sms_packages'      ? 'active' : '' ?>">SMS Packages</a>
    <a href="/admin/plans.php?tab=sms_settings"      class="tab-btn <?= $activeTab === 'sms_settings'      ? 'active' : '' ?>">SMS Settings</a>
    <a href="/admin/plans.php?tab=purchase_requests" class="tab-btn <?= $activeTab === 'purchase_requests' ? 'active' : '' ?>">
        Purchase Requests
        <?php
            $pendingCount = count(array_filter($purchaseRequests, fn($r) => $r['status'] === 'pending'));
            if ($pendingCount > 0): ?>
            <span style="background:#ff4757;color:#fff;border-radius:50%;padding:0 5px;font-size:.75rem;margin-left:4px"><?= $pendingCount ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- EMAIL PLANS TAB -->
<div class="tab-pane <?= $activeTab === 'email_plans' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-body">
            <h3 style="margin-top:0">Add Email Plan</h3>
            <form method="POST" action="/admin/plans.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_plan">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div class="form-group">
                        <label>Name <span style="color:red">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Price ($/mo)</label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Monthly Email Limit</label>
                        <input type="number" name="monthly_email_limit" class="form-control" min="0" value="1000">
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
                        <input type="checkbox" name="is_active" id="add_plan_active" value="1" checked>
                        <label for="add_plan_active" style="margin:0">Active</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Features (one per line)</label>
                    <textarea name="features" class="form-control" rows="4" placeholder="Unlimited contacts&#10;Priority support"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Add Plan</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Price</th><th>Email Limit</th><th>Active</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p): ?>
        <?php $featuresArr = json_decode($p['features'] ?? '[]', true) ?: []; ?>
        <tr>
            <td><?= (int)$p['id'] ?></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['description'] ?? '') ?></td>
            <td>$<?= number_format((float)$p['price'], 2) ?></td>
            <td><?= number_format((int)$p['monthly_email_limit']) ?></td>
            <td><?= $p['is_active'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-danger">No</span>' ?></td>
            <td><?= htmlspecialchars($p['created_at']) ?></td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="openEditPlanModal(
                    <?= (int)$p['id'] ?>,
                    <?= htmlspecialchars(json_encode($p['name'])) ?>,
                    <?= htmlspecialchars(json_encode($p['description'] ?? '')) ?>,
                    <?= htmlspecialchars(json_encode($p['price'])) ?>,
                    <?= (int)$p['monthly_email_limit'] ?>,
                    <?= htmlspecialchars(json_encode($p['features'] ?? '[]')) ?>,
                    <?= (int)$p['is_active'] ?>
                )">Edit</button>
                <form method="POST" action="/admin/plans.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_plan">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">Toggle</button>
                </form>
                <form method="POST" action="/admin/plans.php" style="display:inline" onsubmit="return confirm('Delete plan?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_plan">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($plans)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No plans yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- SMS PACKAGES TAB -->
<div class="tab-pane <?= $activeTab === 'sms_packages' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-body">
            <h3 style="margin-top:0">Add SMS Package</h3>
            <form method="POST" action="/admin/plans.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_package">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div class="form-group">
                        <label>Name <span style="color:red">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>SMS Credits (units/pages)</label>
                        <input type="number" name="credits" class="form-control" min="1" value="1000">
                    </div>
                    <div class="form-group">
                        <label>Price (₦)</label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" value="500.00">
                    </div>
                    <div class="form-group">
                        <label>Billing Period</label>
                        <select name="billing_period" class="form-control">
                            <option value="one_time">One-Time</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
                        <input type="checkbox" name="is_active" id="add_pkg_active" value="1" checked>
                        <label for="add_pkg_active" style="margin:0">Active</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Package</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>Name</th><th>Credits</th><th>Price</th><th>Billing</th><th>Active</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($packages as $pkg): ?>
        <?php $billingLabels = ['one_time'=>'One-Time','monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly']; ?>
        <tr>
            <td><?= (int)$pkg['id'] ?></td>
            <td><?= htmlspecialchars($pkg['name']) ?></td>
            <td><?= number_format((int)$pkg['credits']) ?></td>
            <td>₦<?= number_format((float)$pkg['price'], 2) ?></td>
            <td><?= htmlspecialchars($billingLabels[$pkg['billing_period'] ?? 'one_time'] ?? 'One-Time') ?></td>
            <td><?= $pkg['is_active'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-danger">No</span>' ?></td>
            <td><?= htmlspecialchars($pkg['created_at']) ?></td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="openEditPackageModal(
                    <?= (int)$pkg['id'] ?>,
                    <?= htmlspecialchars(json_encode($pkg['name'])) ?>,
                    <?= (int)$pkg['credits'] ?>,
                    <?= htmlspecialchars(json_encode($pkg['price'])) ?>,
                    <?= htmlspecialchars(json_encode($pkg['billing_period'] ?? 'one_time')) ?>,
                    <?= (int)$pkg['is_active'] ?>
                )">Edit</button>
                <form method="POST" action="/admin/plans.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_package">
                    <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">Toggle</button>
                </form>
                <form method="POST" action="/admin/plans.php" style="display:inline" onsubmit="return confirm('Delete package?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_package">
                    <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($packages)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No packages yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal-overlay" id="editPlanModal">
    <div class="modal-box">
        <h3 style="margin-top:0">Edit Email Plan</h3>
        <form method="POST" action="/admin/plans.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_plan">
            <input type="hidden" name="plan_id" id="ep_id">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label>Name <span style="color:red">*</span></label>
                    <input type="text" name="name" id="ep_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Price ($/mo)</label>
                    <input type="number" name="price" id="ep_price" class="form-control" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label>Monthly Email Limit</label>
                    <input type="number" name="monthly_email_limit" id="ep_limit" class="form-control" min="0">
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
                    <input type="checkbox" name="is_active" id="ep_active" value="1">
                    <label for="ep_active" style="margin:0">Active</label>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="ep_description" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label>Features (one per line)</label>
                <textarea name="features" id="ep_features" class="form-control" rows="4"></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editPlanModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Package Modal -->
<div class="modal-overlay" id="editPackageModal">
    <div class="modal-box">
        <h3 style="margin-top:0">Edit SMS Package</h3>
        <form method="POST" action="/admin/plans.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_package">
            <input type="hidden" name="package_id" id="epkg_id">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label>Name <span style="color:red">*</span></label>
                    <input type="text" name="name" id="epkg_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Credits</label>
                    <input type="number" name="credits" id="epkg_credits" class="form-control" min="1">
                </div>
                <div class="form-group">
                    <label>Price (₦)</label>
                    <input type="number" name="price" id="epkg_price" class="form-control" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label>Billing Period</label>
                    <select name="billing_period" id="epkg_billing" class="form-control">
                        <option value="one_time">One-Time</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
                    <input type="checkbox" name="is_active" id="epkg_active" value="1">
                    <label for="epkg_active" style="margin:0">Active</label>
                </div>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editPackageModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- SMS SETTINGS TAB -->
<div class="tab-pane <?= $activeTab === 'sms_settings' ? 'active' : '' ?>">
    <div class="card" style="max-width:500px">
        <div class="card-body">
            <h3 style="margin-top:0">💰 SMS Price Per Unit</h3>
            <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem">
                Set the price charged per SMS page/unit.<br>
                <strong>1 page</strong> = up to 160 characters.<br>
                <strong>Multi-page</strong>: each page = 153 characters (so 306 chars = 2 pages, 459 = 3 pages, etc.)
            </p>
            <form method="POST" action="/admin/plans.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_sms_price">
                <div class="form-group">
                    <label>Price Per SMS Unit/Page (₦)</label>
                    <input type="number" name="sms_price_per_unit" class="form-control"
                           step="0.01" min="0" value="<?= htmlspecialchars((string)$smsPrice) ?>"
                           style="max-width:200px">
                    <small style="color:var(--text-muted)">
                        Example: ₦<?= number_format($smsPrice, 2) ?>/unit → 2-page SMS costs ₦<?= number_format($smsPrice * 2, 2) ?>
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Save Price</button>
            </form>
        </div>
    </div>
</div>

<!-- PURCHASE REQUESTS TAB -->
<div class="tab-pane <?= $activeTab === 'purchase_requests' ? 'active' : '' ?>">
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>User</th><th>Package</th><th>Credits</th><th>Price</th><th>Billing</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($purchaseRequests as $req):
            $billingLabels = ['one_time'=>'One-Time','monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'];
        ?>
        <tr>
            <td><?= (int)$req['id'] ?></td>
            <td><?= htmlspecialchars($req['username'] ?? '—') ?></td>
            <td><?= htmlspecialchars($req['package_name'] ?? '—') ?></td>
            <td><?= number_format((int)$req['credits']) ?></td>
            <td>₦<?= number_format((float)$req['price'], 2) ?></td>
            <td><?= htmlspecialchars($billingLabels[$req['billing_period'] ?? 'one_time'] ?? 'One-Time') ?></td>
            <td>
                <?php if ($req['status'] === 'pending'): ?>
                    <span class="badge badge-warning">Pending</span>
                <?php elseif ($req['status'] === 'approved'): ?>
                    <span class="badge badge-success">Approved</span>
                <?php else: ?>
                    <span class="badge badge-danger">Rejected</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($req['requested_at']) ?></td>
            <td>
                <?php if ($req['status'] === 'pending'): ?>
                <form method="POST" action="/admin/plans.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="approve_purchase">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Approve and credit user?')">Approve</button>
                </form>
                <form method="POST" action="/admin/plans.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reject_purchase">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="notes" value="">
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?')">Reject</button>
                </form>
                <?php else: ?>
                    <span style="color:var(--text-muted);font-size:.85rem">Processed</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($purchaseRequests)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-muted)">No purchase requests yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function openEditPlanModal(id, name, desc, price, limit, featuresJson, isActive) {
    document.getElementById('ep_id').value = id;
    document.getElementById('ep_name').value = name;
    document.getElementById('ep_description').value = desc;
    document.getElementById('ep_price').value = price;
    document.getElementById('ep_limit').value = limit;
    document.getElementById('ep_active').checked = !!isActive;
    var features = [];
    try { features = JSON.parse(featuresJson); } catch(e) {}
    document.getElementById('ep_features').value = Array.isArray(features) ? features.join('\n') : '';
    document.getElementById('editPlanModal').classList.add('open');
}
function openEditPackageModal(id, name, credits, price, billingPeriod, isActive) {
    document.getElementById('epkg_id').value = id;
    document.getElementById('epkg_name').value = name;
    document.getElementById('epkg_credits').value = credits;
    document.getElementById('epkg_price').value = price;
    document.getElementById('epkg_billing').value = billingPeriod;
    document.getElementById('epkg_active').checked = !!isActive;
    document.getElementById('editPackageModal').classList.add('open');
}
document.getElementById('editPlanModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
document.getElementById('editPackageModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
