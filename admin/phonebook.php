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
        redirect('/admin/phonebook.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contact') {
        $phone    = sanitize($_POST['phone'] ?? '');
        $name     = sanitize($_POST['name'] ?? '');
        $group_id = (int)($_POST['group_id'] ?? 0);
        if ($phone === '') { setFlash('Phone is required.', 'error'); redirect('/admin/phonebook.php'); }
        try {
            $stmt = $db->prepare('INSERT IGNORE INTO sms_contacts (phone, name, group_id) VALUES (?, ?, ?)');
            $stmt->execute([$phone, $name ?: null, $group_id ?: null]);
            setFlash('Contact added.');
        } catch (\Exception $e) { setFlash('Error adding contact.', 'error'); }
        redirect('/admin/phonebook.php?tab=contacts');
    }

    if ($action === 'edit_contact') {
        $id       = (int)($_POST['contact_id'] ?? 0);
        $phone    = sanitize($_POST['phone'] ?? '');
        $name     = sanitize($_POST['name'] ?? '');
        $group_id = (int)($_POST['group_id'] ?? 0);
        if (!$id || $phone === '') { setFlash('Invalid data.', 'error'); redirect('/admin/phonebook.php'); }
        try {
            $stmt = $db->prepare('UPDATE sms_contacts SET phone=?, name=?, group_id=? WHERE id=?');
            $stmt->execute([$phone, $name ?: null, $group_id ?: null, $id]);
            setFlash('Contact updated.');
        } catch (\Exception $e) { setFlash('Error updating contact.', 'error'); }
        redirect('/admin/phonebook.php?tab=contacts');
    }

    if ($action === 'delete_contact') {
        $id = (int)($_POST['contact_id'] ?? 0);
        try {
            $stmt = $db->prepare('DELETE FROM sms_contacts WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Contact deleted.');
        } catch (\Exception $e) { setFlash('Error deleting contact.', 'error'); }
        redirect('/admin/phonebook.php?tab=contacts');
    }

    if ($action === 'toggle_subscribe') {
        $id = (int)($_POST['contact_id'] ?? 0);
        try {
            $stmt = $db->prepare('UPDATE sms_contacts SET is_subscribed = NOT is_subscribed WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Subscription toggled.');
        } catch (\Exception $e) { setFlash('Error toggling subscription.', 'error'); }
        redirect('/admin/phonebook.php?tab=contacts');
    }

    if ($action === 'add_group') {
        $name = sanitize($_POST['name'] ?? '');
        if ($name === '') { setFlash('Group name is required.', 'error'); redirect('/admin/phonebook.php?tab=groups'); }
        try {
            $stmt = $db->prepare('INSERT INTO sms_groups (name) VALUES (?)');
            $stmt->execute([$name]);
            setFlash('Group added.');
        } catch (\Exception $e) { setFlash('Error adding group.', 'error'); }
        redirect('/admin/phonebook.php?tab=groups');
    }

    if ($action === 'edit_group') {
        $id   = (int)($_POST['group_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        if (!$id || $name === '') { setFlash('Invalid data.', 'error'); redirect('/admin/phonebook.php?tab=groups'); }
        try {
            $stmt = $db->prepare('UPDATE sms_groups SET name=? WHERE id=?');
            $stmt->execute([$name, $id]);
            setFlash('Group updated.');
        } catch (\Exception $e) { setFlash('Error updating group.', 'error'); }
        redirect('/admin/phonebook.php?tab=groups');
    }

    if ($action === 'delete_group') {
        $id = (int)($_POST['group_id'] ?? 0);
        try {
            $stmt = $db->prepare('DELETE FROM sms_groups WHERE id=?');
            $stmt->execute([$id]);
            setFlash('Group deleted.');
        } catch (\Exception $e) { setFlash('Error deleting group.', 'error'); }
        redirect('/admin/phonebook.php?tab=groups');
    }

    if ($action === 'import_csv') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('CSV file upload failed.', 'error');
            redirect('/admin/phonebook.php?tab=import');
        }
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $handle  = fopen($tmpPath, 'r');
        if (!$handle) { setFlash('Cannot read CSV.', 'error'); redirect('/admin/phonebook.php?tab=import'); }
        $headers = array_map('strtolower', array_map('trim', fgetcsv($handle) ?: []));
        $phoneIdx = array_search('phone', $headers);
        $nameIdx  = array_search('name', $headers);
        if ($phoneIdx === false) { fclose($handle); setFlash('CSV must have a "phone" column.', 'error'); redirect('/admin/phonebook.php?tab=import'); }
        $count = 0;
        try {
            $stmt = $db->prepare('INSERT IGNORE INTO sms_contacts (phone, name, group_id) VALUES (?, ?, ?)');
            while (($row = fgetcsv($handle)) !== false) {
                $phone = trim($row[$phoneIdx] ?? '');
                $name  = $nameIdx !== false ? trim($row[$nameIdx] ?? '') : '';
                if ($phone === '') continue;
                $stmt->execute([$phone, $name ?: null, $group_id ?: null]);
                $count++;
            }
            setFlash("Imported {$count} contacts.");
        } catch (\Exception $e) { setFlash('Error importing CSV.', 'error'); }
        fclose($handle);
        redirect('/admin/phonebook.php?tab=import');
    }

    if ($action === 'paste_phones') {
        $group_id    = (int)($_POST['group_id'] ?? 0);
        $namePrefix  = sanitize($_POST['name_prefix'] ?? '');
        $raw         = $_POST['phones'] ?? '';
        $lines       = preg_split('/[\r\n,]+/', $raw);
        $count = 0;
        try {
            $stmt = $db->prepare('INSERT IGNORE INTO sms_contacts (phone, name, group_id) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $phone = trim($line);
                if ($phone === '') continue;
                $name = $namePrefix !== '' ? $namePrefix . ' ' . $phone : null;
                $stmt->execute([$phone, $name, $group_id ?: null]);
                $count++;
            }
            setFlash("Added {$count} contacts.");
        } catch (\Exception $e) { setFlash('Error adding contacts.', 'error'); }
        redirect('/admin/phonebook.php?tab=import');
    }

    setFlash('Unknown action.', 'error');
    redirect('/admin/phonebook.php');
}

// Data loading
$flash      = popFlash();
$activeTab  = $_GET['tab'] ?? 'contacts';
$search     = sanitize($_GET['search'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 30;
$offset     = ($page - 1) * $perPage;

$groups = [];
try {
    $groups = $db->query('SELECT id, name FROM sms_groups ORDER BY name')->fetchAll();
} catch (\Exception $e) {}

$contacts   = [];
$totalRows  = 0;
$totalPages = 1;
if ($activeTab === 'contacts') {
    try {
        $where  = $search !== '' ? 'WHERE c.phone LIKE ? OR c.name LIKE ?' : '';
        $params = $search !== '' ? ["%{$search}%", "%{$search}%"] : [];
        $countStmt = $db->prepare("SELECT COUNT(*) FROM sms_contacts c {$where}");
        $countStmt->execute($params);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalRows / $perPage) ?: 1;

        $stmt = $db->prepare("SELECT c.*, g.name AS group_name FROM sms_contacts c LEFT JOIN sms_groups g ON g.id=c.group_id {$where} ORDER BY c.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();
    } catch (\Exception $e) {}
}

$stats = ['total' => 0, 'subscribed' => 0, 'unsubscribed' => 0];
try {
    $row = $db->query("SELECT COUNT(*) AS total, SUM(is_subscribed=1) AS subscribed, SUM(is_subscribed=0) AS unsubscribed FROM sms_contacts")->fetch();
    $stats = ['total' => (int)$row['total'], 'subscribed' => (int)$row['subscribed'], 'unsubscribed' => (int)$row['unsubscribed']];
} catch (\Exception $e) {}

$groupsWithCount = [];
if ($activeTab === 'groups') {
    try {
        $groupsWithCount = $db->query("SELECT g.id, g.name, g.created_at, COUNT(c.id) AS contact_count FROM sms_groups g LEFT JOIN sms_contacts c ON c.group_id=g.id GROUP BY g.id ORDER BY g.name")->fetchAll();
    } catch (\Exception $e) {}
}

$pageTitle  = 'SMS Phone Book';
$activePage = 'phonebook';
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--card-bg,#1e293b);color:var(--text-muted,#94a3b8);cursor:pointer;border-radius:6px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--border-color,#334155)}
.tab-btn.active{background:var(--primary,#6c63ff);color:#fff;border-color:var(--primary,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:2rem;min-width:320px;max-width:480px;width:100%}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:1rem;text-align:center}
.stat-val{font-size:1.8rem;font-weight:700;color:var(--primary,#6c63ff)}
.stat-label{font-size:.85rem;color:var(--text-muted,#94a3b8)}
.collapsible-toggle{cursor:pointer;user-select:none}
.collapsible-body{display:none}
.collapsible-body.open{display:block}
</style>

<h1>SMS Phone Book</h1>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="tabs">
    <a href="/admin/phonebook.php?tab=contacts" class="tab-btn <?= $activeTab === 'contacts' ? 'active' : '' ?>">Contacts</a>
    <a href="/admin/phonebook.php?tab=groups"   class="tab-btn <?= $activeTab === 'groups'   ? 'active' : '' ?>">Groups</a>
    <a href="/admin/phonebook.php?tab=import"   class="tab-btn <?= $activeTab === 'import'   ? 'active' : '' ?>">Import</a>
</div>

<!-- CONTACTS TAB -->
<div class="tab-pane <?= $activeTab === 'contacts' ? 'active' : '' ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-val"><?= $stats['total'] ?></div><div class="stat-label">Total</div></div>
        <div class="stat-card"><div class="stat-val"><?= $stats['subscribed'] ?></div><div class="stat-label">Subscribed</div></div>
        <div class="stat-card"><div class="stat-val"><?= $stats['unsubscribed'] ?></div><div class="stat-label">Unsubscribed</div></div>
    </div>

    <div class="card" style="margin-bottom:1rem">
        <div class="card-header collapsible-toggle" onclick="this.nextElementSibling.classList.toggle('open')">
            ➕ Add Contact
        </div>
        <div class="collapsible-body card-body">
            <form method="POST" action="/admin/phonebook.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_contact">
                <div class="form-group">
                    <label>Phone <span style="color:red">*</span></label>
                    <input type="text" name="phone" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" class="form-control">
                </div>
                <div class="form-group">
                    <label>Group</label>
                    <select name="group_id" class="form-control">
                        <option value="0">— None —</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Add Contact</button>
            </form>
        </div>
    </div>

    <form method="GET" action="/admin/phonebook.php" style="display:flex;gap:.5rem;margin-bottom:1rem">
        <input type="hidden" name="tab" value="contacts">
        <input type="text" name="search" class="form-control" placeholder="Search phone or name…" value="<?= htmlspecialchars($search) ?>" style="max-width:300px">
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($search): ?><a href="/admin/phonebook.php?tab=contacts" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>Phone</th><th>Name</th><th>Group</th><th>Subscribed</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($contacts as $c): ?>
        <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['phone']) ?></td>
            <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['group_name'] ?? '—') ?></td>
            <td>
                <?php if ($c['is_subscribed']): ?>
                    <span class="badge badge-success">Yes</span>
                <?php else: ?>
                    <span class="badge badge-danger">No</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($c['created_at']) ?></td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="openEditModal(<?= (int)$c['id'] ?>,<?= htmlspecialchars(json_encode($c['phone'])) ?>,<?= htmlspecialchars(json_encode($c['name'] ?? '')) ?>,<?= (int)($c['group_id'] ?? 0) ?>)">Edit</button>
                <form method="POST" action="/admin/phonebook.php" style="display:inline" onsubmit="return confirm('Toggle subscription?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_subscribe">
                    <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">Toggle</button>
                </form>
                <form method="POST" action="/admin/phonebook.php" style="display:inline" onsubmit="return confirm('Delete contact?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($contacts)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted)">No contacts found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="/admin/phonebook.php?tab=contacts&page=<?= $p ?>&search=<?= urlencode($search) ?>"
           class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- GROUPS TAB -->
<div class="tab-pane <?= $activeTab === 'groups' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-body">
            <h3 style="margin-top:0">Add Group</h3>
            <form method="POST" action="/admin/phonebook.php" style="display:flex;gap:.5rem;align-items:flex-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_group">
                <div class="form-group" style="margin:0;flex:1">
                    <input type="text" name="name" class="form-control" placeholder="Group name" required>
                </div>
                <button type="submit" class="btn btn-primary">Add Group</button>
            </form>
        </div>
    </div>

    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>ID</th><th>Name</th><th>Contacts</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($groupsWithCount as $g): ?>
        <tr>
            <td><?= (int)$g['id'] ?></td>
            <td><?= htmlspecialchars($g['name']) ?></td>
            <td><?= (int)$g['contact_count'] ?></td>
            <td><?= htmlspecialchars($g['created_at']) ?></td>
            <td>
                <button class="btn btn-sm btn-secondary" onclick="openEditGroupModal(<?= (int)$g['id'] ?>,<?= htmlspecialchars(json_encode($g['name'])) ?>)">Edit</button>
                <form method="POST" action="/admin/phonebook.php" style="display:inline" onsubmit="return confirm('Delete group?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($groupsWithCount)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted)">No groups yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- IMPORT TAB -->
<div class="tab-pane <?= $activeTab === 'import' ? 'active' : '' ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;flex-wrap:wrap">
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">Upload CSV</h3>
                <p style="color:var(--text-muted);font-size:.9rem">CSV must have a <code>phone</code> column. <code>name</code> is optional.</p>
                <form method="POST" action="/admin/phonebook.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="import_csv">
                    <div class="form-group">
                        <label>CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="form-group">
                        <label>Assign to Group</label>
                        <select name="group_id" class="form-control">
                            <option value="0">— None —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0">Paste Phone Numbers</h3>
                <p style="color:var(--text-muted);font-size:.9rem">One per line or comma-separated.</p>
                <form method="POST" action="/admin/phonebook.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="paste_phones">
                    <div class="form-group">
                        <label>Phone Numbers</label>
                        <textarea name="phones" class="form-control" rows="6" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Name Prefix (optional)</label>
                        <input type="text" name="name_prefix" class="form-control" placeholder="e.g. Customer">
                    </div>
                    <div class="form-group">
                        <label>Assign to Group</label>
                        <select name="group_id" class="form-control">
                            <option value="0">— None —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Contacts</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Contact Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3 style="margin-top:0">Edit Contact</h3>
        <form method="POST" action="/admin/phonebook.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_contact">
            <input type="hidden" name="contact_id" id="edit_contact_id">
            <div class="form-group">
                <label>Phone <span style="color:red">*</span></label>
                <input type="text" name="phone" id="edit_phone" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" id="edit_name" class="form-control">
            </div>
            <div class="form-group">
                <label>Group</label>
                <select name="group_id" id="edit_group_id" class="form-control">
                    <option value="0">— None —</option>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal-overlay" id="editGroupModal">
    <div class="modal-box">
        <h3 style="margin-top:0">Edit Group</h3>
        <form method="POST" action="/admin/phonebook.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_group">
            <input type="hidden" name="group_id" id="edit_group_modal_id">
            <div class="form-group">
                <label>Name <span style="color:red">*</span></label>
                <input type="text" name="name" id="edit_group_name" class="form-control" required>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editGroupModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, phone, name, groupId) {
    document.getElementById('edit_contact_id').value = id;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_name').value = name;
    var sel = document.getElementById('edit_group_id');
    for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = (parseInt(sel.options[i].value) === groupId);
    }
    document.getElementById('editModal').classList.add('open');
}
function openEditGroupModal(id, name) {
    document.getElementById('edit_group_modal_id').value = id;
    document.getElementById('edit_group_name').value = name;
    document.getElementById('editGroupModal').classList.add('open');
}
document.getElementById('editModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
document.getElementById('editGroupModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
