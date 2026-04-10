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

// ─── Flash helpers ────────────────────────────────────────────────────────────
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

// ─── POST HANDLERS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/ip-management.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_blacklist') {
        $ip        = trim($_POST['ip_address'] ?? '');
        $reason    = sanitize($_POST['reason'] ?? '');
        $blockType = $_POST['block_type'] ?? 'permanent';
        $blockUntil = trim($_POST['block_until'] ?? '');

        if (!in_array($blockType, ['permanent', 'one_day', 'one_week', 'one_month', 'one_year'], true)) {
            $blockType = 'permanent';
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            setFlash('Invalid IP address.', 'error');
            redirect('/admin/ip-management.php?tab=blacklist');
        }

        // Compute block_until from block_type if not explicitly set
        if ($blockType !== 'permanent' && $blockUntil === '') {
            $intervals = [
                'one_day'   => '+1 day',
                'one_week'  => '+1 week',
                'one_month' => '+1 month',
                'one_year'  => '+1 year',
            ];
            $blockUntil = date('Y-m-d H:i:s', strtotime($intervals[$blockType]));
        }
        $blockUntilVal = ($blockType === 'permanent' || $blockUntil === '') ? null : $blockUntil;

        try {
            $db->prepare(
                "INSERT INTO ip_blacklist (ip_address, reason, block_type, block_until)
                 VALUES (:ip, :r, :bt, :bu)
                 ON DUPLICATE KEY UPDATE reason=:r2, block_type=:bt2, block_until=:bu2"
            )->execute([
                ':ip'  => $ip, ':r'   => $reason, ':bt'  => $blockType, ':bu'  => $blockUntilVal,
                ':r2'  => $reason, ':bt2' => $blockType, ':bu2' => $blockUntilVal,
            ]);
            setFlash('IP added to blacklist.');
        } catch (\Exception $e) {
            setFlash('Failed to add IP: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=blacklist');
    }

    if ($action === 'remove_blacklist') {
        $ip = trim($_POST['ip_address'] ?? '');
        try {
            $db->prepare("DELETE FROM ip_blacklist WHERE ip_address = :ip")->execute([':ip' => $ip]);
            setFlash('IP removed from blacklist.');
        } catch (\Exception $e) {
            setFlash('Failed to remove IP: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=blacklist');
    }

    if ($action === 'add_whitelist') {
        $ip        = trim($_POST['ip_address'] ?? '');
        $label     = sanitize($_POST['label'] ?? '');
        $isTrusted = isset($_POST['is_trusted']) ? 1 : 0;

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            setFlash('Invalid IP address.', 'error');
            redirect('/admin/ip-management.php?tab=whitelist');
        }
        try {
            $db->prepare(
                "INSERT INTO ip_whitelist (ip_address, label, is_trusted)
                 VALUES (:ip, :l, :t)
                 ON DUPLICATE KEY UPDATE label=:l2, is_trusted=:t2"
            )->execute([':ip' => $ip, ':l' => $label, ':t' => $isTrusted, ':l2' => $label, ':t2' => $isTrusted]);
            setFlash('IP added to whitelist.');
        } catch (\Exception $e) {
            setFlash('Failed to add IP: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=whitelist');
    }

    if ($action === 'update_whitelist') {
        $ip        = trim($_POST['ip_address'] ?? '');
        $isTrusted = (int)($_POST['is_trusted'] ?? 0);
        try {
            $db->prepare("UPDATE ip_whitelist SET is_trusted = :t WHERE ip_address = :ip")
               ->execute([':t' => $isTrusted, ':ip' => $ip]);
            setFlash('Whitelist entry updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=whitelist');
    }

    if ($action === 'remove_whitelist') {
        $ip = trim($_POST['ip_address'] ?? '');
        try {
            $db->prepare("DELETE FROM ip_whitelist WHERE ip_address = :ip")->execute([':ip' => $ip]);
            setFlash('IP removed from whitelist.');
        } catch (\Exception $e) {
            setFlash('Failed to remove IP: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=whitelist');
    }

    if ($action === 'clear_attempts') {
        $period = $_POST['clear_period'] ?? 'all';
        try {
            if ($period === '24h') {
                $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            } elseif ($period === '7d') {
                $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            } else {
                $db->exec("DELETE FROM login_attempts");
            }
            setFlash('Login attempts cleared.');
        } catch (\Exception $e) {
            setFlash('Failed to clear attempts: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/ip-management.php?tab=attempts');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/ip-management.php');
}

// ─── DATA ─────────────────────────────────────────────────────────────────────
$flash      = popFlash();
$activeTab  = $_GET['tab'] ?? 'blacklist';
if (!in_array($activeTab, ['blacklist', 'whitelist', 'attempts'], true)) {
    $activeTab = 'blacklist';
}

$attemptsFilter = $_GET['filter'] ?? '24h';
if (!in_array($attemptsFilter, ['24h', '7d', 'all'], true)) {
    $attemptsFilter = '24h';
}

try {
    $blacklist = $db->query(
        "SELECT * FROM ip_blacklist ORDER BY added_at DESC"
    )->fetchAll();
} catch (\Exception $e) {
    $blacklist = [];
}

try {
    $whitelist = $db->query(
        "SELECT * FROM ip_whitelist ORDER BY added_at DESC"
    )->fetchAll();
} catch (\Exception $e) {
    $whitelist = [];
}

try {
    $attemptsWhere = match($attemptsFilter) {
        '24h' => "WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        '7d'  => "WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
        default => '',
    };
    $attempts = $db->query(
        "SELECT * FROM login_attempts $attemptsWhere ORDER BY attempted_at DESC LIMIT 500"
    )->fetchAll();
} catch (\Exception $e) {
    $attempts = [];
}

$pageTitle  = 'IP Management';
$activePage = 'ip';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" style="margin-bottom:1.5rem;display:flex;gap:.5rem;border-bottom:2px solid var(--border-color)">
    <a href="?tab=blacklist" class="tab-btn <?= $activeTab === 'blacklist' ? 'active' : '' ?>"
       style="padding:.6rem 1.25rem;text-decoration:none;border-bottom:2px solid <?= $activeTab === 'blacklist' ? 'var(--primary)' : 'transparent' ?>;color:<?= $activeTab === 'blacklist' ? 'var(--primary)' : 'var(--text-muted)' ?>;font-weight:600">
        🚫 Blacklist (<?= count($blacklist) ?>)
    </a>
    <a href="?tab=whitelist" class="tab-btn <?= $activeTab === 'whitelist' ? 'active' : '' ?>"
       style="padding:.6rem 1.25rem;text-decoration:none;border-bottom:2px solid <?= $activeTab === 'whitelist' ? 'var(--primary)' : 'transparent' ?>;color:<?= $activeTab === 'whitelist' ? 'var(--primary)' : 'var(--text-muted)' ?>;font-weight:600">
        ✅ Whitelist (<?= count($whitelist) ?>)
    </a>
    <a href="?tab=attempts" class="tab-btn <?= $activeTab === 'attempts' ? 'active' : '' ?>"
       style="padding:.6rem 1.25rem;text-decoration:none;border-bottom:2px solid <?= $activeTab === 'attempts' ? 'var(--primary)' : 'transparent' ?>;color:<?= $activeTab === 'attempts' ? 'var(--primary)' : 'var(--text-muted)' ?>;font-weight:600">
        🔑 Login Attempts (<?= count($attempts) ?>)
    </a>
</div>

<?php if ($activeTab === 'blacklist'): ?>
<!-- ══ BLACKLIST TAB ════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start">

<div class="card">
    <div class="card-header">
        <h3 class="card-title">🚫 IP Blacklist</h3>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Reason</th>
                    <th>Block Type</th>
                    <th>Block Until</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($blacklist as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['ip_address']) ?></td>
                <td><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                <td>
                    <?php
                    $btClass = $row['block_type'] === 'permanent' ? 'badge-danger' : 'badge-warning';
                    ?>
                    <span class="badge <?= $btClass ?>"><?= htmlspecialchars($row['block_type']) ?></span>
                </td>
                <td><?= $row['block_until'] ? htmlspecialchars($row['block_until']) : '—' ?></td>
                <td><?= htmlspecialchars($row['added_at']) ?></td>
                <td>
                    <form method="POST" action="/admin/ip-management.php" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <input type="hidden" name="action" value="remove_blacklist">
                        <input type="hidden" name="ip_address" value="<?= htmlspecialchars($row['ip_address']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('Remove <?= htmlspecialchars(addslashes($row['ip_address'])) ?> from blacklist?')">
                            Remove
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($blacklist)): ?>
            <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">No blacklisted IPs.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add to Blacklist Form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">➕ Add to Blacklist</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/ip-management.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_blacklist">
            <div class="form-group">
                <label class="form-label">IP Address</label>
                <input type="text" name="ip_address" class="form-control" required
                       placeholder="e.g. 192.168.1.100">
            </div>
            <div class="form-group">
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-control" maxlength="255"
                       placeholder="Reason for blocking">
            </div>
            <div class="form-group">
                <label class="form-label">Block Type</label>
                <select name="block_type" class="form-control" id="blockTypeSelect"
                        onchange="toggleBlockUntil(this.value)">
                    <option value="permanent">Permanent</option>
                    <option value="one_day">1 Day</option>
                    <option value="one_week">1 Week</option>
                    <option value="one_month">1 Month</option>
                    <option value="one_year">1 Year</option>
                </select>
            </div>
            <div class="form-group" id="blockUntilGroup" style="display:none">
                <label class="form-label">Block Until (optional override)</label>
                <input type="datetime-local" name="block_until" class="form-control">
            </div>
            <button type="submit" class="btn btn-danger" style="width:100%">Add to Blacklist</button>
        </form>
    </div>
</div>

</div><!-- grid -->

<?php elseif ($activeTab === 'whitelist'): ?>
<!-- ══ WHITELIST TAB ════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start">

<div class="card">
    <div class="card-header">
        <h3 class="card-title">✅ IP Whitelist</h3>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Label</th>
                    <th>Sessions</th>
                    <th>Trusted</th>
                    <th>Last Login</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($whitelist as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['ip_address']) ?></td>
                <td><?= htmlspecialchars($row['label'] ?? '') ?></td>
                <td><?= (int)($row['session_count'] ?? 0) ?></td>
                <td><?= $row['is_trusted'] ? '✓' : '✗' ?></td>
                <td><?= $row['last_login'] ? htmlspecialchars($row['last_login']) : '—' ?></td>
                <td><?= htmlspecialchars($row['added_at']) ?></td>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <!-- Trust / Untrust -->
                        <form method="POST" action="/admin/ip-management.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_whitelist">
                            <input type="hidden" name="ip_address" value="<?= htmlspecialchars($row['ip_address']) ?>">
                            <input type="hidden" name="is_trusted" value="<?= $row['is_trusted'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">
                                <?= $row['is_trusted'] ? 'Untrust' : 'Trust' ?>
                            </button>
                        </form>
                        <!-- Remove -->
                        <form method="POST" action="/admin/ip-management.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="remove_whitelist">
                            <input type="hidden" name="ip_address" value="<?= htmlspecialchars($row['ip_address']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Remove <?= htmlspecialchars(addslashes($row['ip_address'])) ?> from whitelist?')">
                                Remove
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($whitelist)): ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No whitelisted IPs.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add to Whitelist Form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">➕ Add to Whitelist</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/ip-management.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_whitelist">
            <div class="form-group">
                <label class="form-label">IP Address</label>
                <input type="text" name="ip_address" class="form-control" required
                       placeholder="e.g. 10.0.0.1">
            </div>
            <div class="form-group">
                <label class="form-label">Label</label>
                <input type="text" name="label" class="form-control" maxlength="100"
                       placeholder="Office, VPN, etc.">
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_trusted" value="1">
                    Mark as Trusted
                </label>
            </div>
            <button type="submit" class="btn btn-success" style="width:100%">Add to Whitelist</button>
        </form>
    </div>
</div>

</div><!-- grid -->

<?php else: ?>
<!-- ══ LOGIN ATTEMPTS TAB ════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <h3 class="card-title">🔑 Login Attempts</h3>
        <div style="display:flex;gap:.5rem;align-items:center">
            <!-- Filter -->
            <a href="?tab=attempts&filter=24h"
               class="btn btn-sm <?= $attemptsFilter === '24h' ? 'btn-primary' : 'btn-secondary' ?>">Last 24h</a>
            <a href="?tab=attempts&filter=7d"
               class="btn btn-sm <?= $attemptsFilter === '7d' ? 'btn-primary' : 'btn-secondary' ?>">Last 7 days</a>
            <a href="?tab=attempts&filter=all"
               class="btn btn-sm <?= $attemptsFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
            <!-- Clear -->
            <form method="POST" action="/admin/ip-management.php" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="clear_attempts">
                <input type="hidden" name="clear_period" value="<?= htmlspecialchars($attemptsFilter) ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Clear login attempts for selected filter?')">
                    🗑 Clear Attempts
                </button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Username</th>
                    <th>Attempted At</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($attempts as $att): ?>
            <tr>
                <td><?= htmlspecialchars($att['ip_address']) ?></td>
                <td><?= htmlspecialchars($att['username'] ?? '') ?></td>
                <td><?= htmlspecialchars($att['attempted_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($attempts)): ?>
            <tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--text-muted)">No login attempts found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleBlockUntil(val) {
    document.getElementById('blockUntilGroup').style.display =
        (val === 'permanent') ? 'none' : 'block';
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
