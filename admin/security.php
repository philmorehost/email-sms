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

// ─── CSV export (before any output) ──────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === '1') {
    requireAdmin();
    try {
        $logs = $db->query(
            "SELECT created_at, event_type, ip_address, username, country_code, details, is_trusted
             FROM security_logs ORDER BY created_at DESC"
        )->fetchAll();
    } catch (\Exception $e) {
        $logs = [];
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="security_logs_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'Event Type', 'IP Address', 'Username', 'Country', 'Details', 'Trusted']);
    foreach ($logs as $row) {
        fputcsv($out, [
            $row['created_at'],
            $row['event_type'],
            $row['ip_address'],
            $row['username'],
            $row['country_code'],
            $row['details'],
            $row['is_trusted'] ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit;
}

// ─── POST HANDLERS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        redirect('/admin/security.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $bfPeriod      = max(1, (int)($_POST['brute_force_period'] ?? 15));
        $maxIp         = max(1, (int)($_POST['max_failures_ip'] ?? 5));
        $maxUser       = max(1, (int)($_POST['max_failures_user'] ?? 3));
        $oneDayTh      = max(0, (int)($_POST['one_day_block_threshold'] ?? 2));
        $oneWeekTh     = max(0, (int)($_POST['one_week_block_threshold'] ?? 5));
        $oneMonthTh    = max(0, (int)($_POST['one_month_block_threshold'] ?? 10));
        $oneYearTh     = max(0, (int)($_POST['one_year_block_threshold'] ?? 20));
        $notifyAdmin   = isset($_POST['notify_admin_on_bf']) ? 1 : 0;
        $protectAdmin  = isset($_POST['protect_admin_accounts']) ? 1 : 0;

        try {
            $db->prepare(
                "UPDATE security_settings SET
                    brute_force_period        = :bf,
                    max_failures_ip           = :mip,
                    max_failures_user         = :mu,
                    one_day_block_threshold   = :od,
                    one_week_block_threshold  = :ow,
                    one_month_block_threshold = :om,
                    one_year_block_threshold  = :oy,
                    notify_admin_on_bf        = :na,
                    protect_admin_accounts    = :pa
                 WHERE id = 1"
            )->execute([
                ':bf'  => $bfPeriod,  ':mip' => $maxIp,     ':mu'  => $maxUser,
                ':od'  => $oneDayTh,  ':ow'  => $oneWeekTh, ':om'  => $oneMonthTh,
                ':oy'  => $oneYearTh, ':na'  => $notifyAdmin, ':pa' => $protectAdmin,
            ]);
            setFlash('Security settings saved.');
        } catch (\Exception $e) {
            setFlash('Failed to save settings: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/security.php');
    }

    if ($action === 'clear_logs') {
        try {
            $db->exec("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            setFlash('Security logs older than 30 days have been cleared.');
        } catch (\Exception $e) {
            setFlash('Failed to clear logs: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/security.php');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/security.php');
}

// ─── DATA ─────────────────────────────────────────────────────────────────────
$flash = popFlash();

try {
    $settings = $db->query("SELECT * FROM security_settings WHERE id = 1")->fetch();
    if (!$settings) {
        $settings = [
            'brute_force_period'        => 15,
            'max_failures_ip'           => 5,
            'max_failures_user'         => 3,
            'one_day_block_threshold'   => 2,
            'one_week_block_threshold'  => 5,
            'one_month_block_threshold' => 10,
            'one_year_block_threshold'  => 20,
            'notify_admin_on_bf'        => 1,
            'protect_admin_accounts'    => 1,
        ];
    }
} catch (\Exception $e) {
    $settings = [];
}

try {
    $blockedIps   = (int)$db->query("SELECT COUNT(*) FROM ip_blacklist WHERE block_until IS NULL OR block_until > NOW()")->fetchColumn();
    $whitelistIps = (int)$db->query("SELECT COUNT(*) FROM ip_whitelist")->fetchColumn();
    $failedLogins = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $logEvents    = (int)$db->query("SELECT COUNT(*) FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $recentLogs   = $db->query(
        "SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 20"
    )->fetchAll();
} catch (\Exception $e) {
    $blockedIps = $whitelistIps = $failedLogins = $logEvents = 0;
    $recentLogs = [];
}

$pageTitle  = 'Security Settings';
$activePage = 'security';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

<!-- Left: Settings Form -->
<div>
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <h3 class="card-title">🛡️ Security Settings</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/security.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="save_settings">

                <div class="form-group">
                    <label class="form-label">Brute Force Period (minutes)</label>
                    <input type="number" name="brute_force_period" class="form-control"
                           value="<?= (int)($settings['brute_force_period'] ?? 15) ?>" min="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Login Failures per IP</label>
                    <input type="number" name="max_failures_ip" class="form-control"
                           value="<?= (int)($settings['max_failures_ip'] ?? 5) ?>" min="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Failures per User</label>
                    <input type="number" name="max_failures_user" class="form-control"
                           value="<?= (int)($settings['max_failures_user'] ?? 3) ?>" min="1" required>
                </div>

                <hr style="border-color:var(--border-color);margin:1rem 0">
                <p class="form-label" style="font-weight:600;margin-bottom:.75rem">Block Thresholds (cumulative failures)</p>

                <div class="form-group">
                    <label class="form-label">1-Day Block Threshold</label>
                    <input type="number" name="one_day_block_threshold" class="form-control"
                           value="<?= (int)($settings['one_day_block_threshold'] ?? 2) ?>" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">1-Week Block Threshold</label>
                    <input type="number" name="one_week_block_threshold" class="form-control"
                           value="<?= (int)($settings['one_week_block_threshold'] ?? 5) ?>" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">1-Month Block Threshold</label>
                    <input type="number" name="one_month_block_threshold" class="form-control"
                           value="<?= (int)($settings['one_month_block_threshold'] ?? 10) ?>" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">1-Year Block Threshold</label>
                    <input type="number" name="one_year_block_threshold" class="form-control"
                           value="<?= (int)($settings['one_year_block_threshold'] ?? 20) ?>" min="0" required>
                </div>

                <hr style="border-color:var(--border-color);margin:1rem 0">

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="notify_admin_on_bf" value="1"
                               <?= !empty($settings['notify_admin_on_bf']) ? 'checked' : '' ?>>
                        Notify Admin on Brute Force
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="protect_admin_accounts" value="1"
                               <?= !empty($settings['protect_admin_accounts']) ? 'checked' : '' ?>>
                        Protect Admin Accounts
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📊 Security Statistics</h3>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $blockedIps ?></div>
                    <div class="stat-label">Blocked IPs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $whitelistIps ?></div>
                    <div class="stat-label">Whitelisted IPs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $failedLogins ?></div>
                    <div class="stat-label">Failed Logins (24h)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $logEvents ?></div>
                    <div class="stat-label">Log Events (7d)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Right: Recent Logs -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <h3 class="card-title">🗒️ Recent Security Logs</h3>
        <div style="display:flex;gap:.5rem">
            <a href="/admin/security.php?export=1" class="btn btn-sm btn-secondary">⬇ Export CSV</a>
            <form method="POST" action="/admin/security.php" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete all security logs older than 30 days?')">
                    🗑 Clear Old Logs
                </button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table" style="font-size:.85rem">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>IP</th>
                    <th>User</th>
                    <th>Country</th>
                    <th>Details</th>
                    <th>✓</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
            <tr>
                <td style="white-space:nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                <td>
                    <?php
                    $evtClass = match(true) {
                        str_contains($log['event_type'], 'block') => 'badge-danger',
                        str_contains($log['event_type'], 'fail')  => 'badge-warning',
                        str_contains($log['event_type'], 'login') => 'badge-success',
                        default                                   => 'badge-secondary',
                    };
                    ?>
                    <span class="badge <?= $evtClass ?>"><?= htmlspecialchars($log['event_type']) ?></span>
                </td>
                <td><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                <td><?= htmlspecialchars($log['username'] ?? '') ?></td>
                <td><?= htmlspecialchars($log['country_code'] ?? '') ?></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= htmlspecialchars($log['details'] ?? '') ?>">
                    <?= htmlspecialchars($log['details'] ?? '') ?>
                </td>
                <td><?= $log['is_trusted'] ? '✓' : '' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentLogs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No log entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- grid -->

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
