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
        redirect('/admin/countries.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_country') {
        $code   = strtoupper(trim($_POST['country_code'] ?? ''));
        $name   = sanitize($_POST['country_name'] ?? '');
        $status = $_POST['status'] ?? 'not_specified';
        if (!in_array($status, ['whitelisted', 'blacklisted', 'not_specified'], true)) {
            $status = 'not_specified';
        }
        if (strlen($code) !== 2 || !ctype_alpha($code) || $name === '') {
            setFlash('A valid 2-letter country code and name are required.', 'error');
        } else {
            try {
                $db->prepare(
                    "INSERT INTO country_firewall (country_code, country_name, status) VALUES (:c, :n, :s)
                     ON DUPLICATE KEY UPDATE country_name = :n2, status = :s2"
                )->execute([':c' => $code, ':n' => $name, ':s' => $status, ':n2' => $name, ':s2' => $status]);
                setFlash('Country added/updated.');
            } catch (\Exception $e) {
                setFlash('Failed to add country: ' . $e->getMessage(), 'error');
            }
        }
        redirect('/admin/countries.php');
    }

    if ($action === 'update_status') {
        $code   = strtoupper(trim($_POST['country_code'] ?? ''));
        $status = $_POST['status'] ?? 'not_specified';
        if (!in_array($status, ['whitelisted', 'blacklisted', 'not_specified'], true)) {
            $status = 'not_specified';
        }
        try {
            $db->prepare("UPDATE country_firewall SET status = :s WHERE country_code = :c")
               ->execute([':s' => $status, ':c' => $code]);
            setFlash('Country status updated.');
        } catch (\Exception $e) {
            setFlash('Failed to update status: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/countries.php');
    }

    if ($action === 'remove_country') {
        $code = strtoupper(trim($_POST['country_code'] ?? ''));
        try {
            $db->prepare("DELETE FROM country_firewall WHERE country_code = :c")->execute([':c' => $code]);
            setFlash('Country removed.');
        } catch (\Exception $e) {
            setFlash('Failed to remove country: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/countries.php');
    }

    if ($action === 'clear_all') {
        try {
            $db->exec("DELETE FROM country_firewall");
            setFlash('All country firewall rules cleared.');
        } catch (\Exception $e) {
            setFlash('Failed to clear rules: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/countries.php');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/countries.php');
}

// ─── DATA ─────────────────────────────────────────────────────────────────────
$flash = popFlash();

try {
    $countries = $db->query("SELECT * FROM country_firewall ORDER BY country_name")->fetchAll();
} catch (\Exception $e) {
    $countries = [];
}

$whitelisted   = count(array_filter($countries, fn($c) => $c['status'] === 'whitelisted'));
$blacklisted   = count(array_filter($countries, fn($c) => $c['status'] === 'blacklisted'));
$totalInDb     = count($countries);

$pageTitle  = 'Country Firewall';
$activePage = 'countries';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-value"><?= $whitelisted ?></div>
        <div class="stat-label">Whitelisted</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $blacklisted ?></div>
        <div class="stat-label">Blacklisted</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $totalInDb ?></div>
        <div class="stat-label">Total in DB</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start">

<!-- Country Table -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <h3 class="card-title">🌍 Country Firewall Rules</h3>
        <div style="display:flex;gap:.5rem;align-items:center">
            <input type="text" id="countrySearch" class="form-control form-control-sm"
                   placeholder="Search countries…" style="width:200px"
                   oninput="filterCountries(this.value)">
            <button type="button" class="btn btn-sm btn-danger"
                    onclick="document.getElementById('clearAllModal').style.display='flex'">
                ⚠ Clear All
            </button>
        </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table" id="countriesTable">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Country Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($countries as $country): ?>
            <tr class="country-row">
                <td><?= htmlspecialchars($country['country_code']) ?></td>
                <td><?= htmlspecialchars($country['country_name']) ?></td>
                <td>
                    <?php
                    $sc = match($country['status']) {
                        'whitelisted'   => 'badge-success',
                        'blacklisted'   => 'badge-danger',
                        default         => 'badge-secondary',
                    };
                    ?>
                    <span class="badge <?= $sc ?>"><?= htmlspecialchars($country['status']) ?></span>
                </td>
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <?php if ($country['status'] !== 'whitelisted'): ?>
                        <form method="POST" action="/admin/countries.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="country_code" value="<?= htmlspecialchars($country['country_code']) ?>">
                            <input type="hidden" name="status" value="whitelisted">
                            <button type="submit" class="btn btn-sm btn-success">Whitelist</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($country['status'] !== 'blacklisted'): ?>
                        <form method="POST" action="/admin/countries.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="country_code" value="<?= htmlspecialchars($country['country_code']) ?>">
                            <input type="hidden" name="status" value="blacklisted">
                            <button type="submit" class="btn btn-sm btn-danger">Blacklist</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="/admin/countries.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="remove_country">
                            <input type="hidden" name="country_code" value="<?= htmlspecialchars($country['country_code']) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"
                                    onclick="return confirm('Remove <?= htmlspecialchars(addslashes($country['country_name'])) ?>?')">
                                Remove
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($countries)): ?>
            <tr id="noCountriesRow">
                <td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">No country rules defined.</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Country Form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">➕ Add Country</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/countries.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_country">
            <div class="form-group">
                <label class="form-label">Country Code (2-letter)</label>
                <input type="text" name="country_code" class="form-control" required
                       maxlength="2" minlength="2" placeholder="e.g. US"
                       oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="form-group">
                <label class="form-label">Country Name</label>
                <input type="text" name="country_name" class="form-control" required
                       maxlength="100" placeholder="e.g. United States">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="not_specified">Not Specified</option>
                    <option value="whitelisted">Whitelisted</option>
                    <option value="blacklisted">Blacklisted</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Add Country</button>
        </form>
    </div>
</div>

</div><!-- grid -->

<!-- Clear All Modal -->
<div id="clearAllModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center">
    <div class="card" style="width:100%;max-width:420px;margin:auto">
        <div class="card-header">
            <h3 class="card-title">⚠ Clear All Country Rules</h3>
        </div>
        <div class="card-body">
            <p>This will permanently remove ALL country firewall rules. Are you sure?</p>
            <div style="display:flex;gap:.75rem;margin-top:1rem">
                <form method="POST" action="/admin/countries.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="btn btn-danger">Yes, Clear All</button>
                </form>
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('clearAllModal').style.display='none'">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function filterCountries(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#countriesTable tbody .country-row').forEach(function(row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}
document.getElementById('clearAllModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
