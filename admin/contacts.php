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
        redirect('/admin/contacts.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Add Contact ───────────────────────────────────────────────────────────
    if ($action === 'add_contact') {
        $email      = sanitizeEmail($_POST['email'] ?? '');
        $firstName  = sanitize($_POST['first_name'] ?? '');
        $lastName   = sanitize($_POST['last_name'] ?? '');
        $phone      = sanitize($_POST['phone'] ?? '');
        $groupId    = (int)($_POST['group_id'] ?? 0);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('A valid email address is required.', 'error');
            redirect('/admin/contacts.php');
        }

        try {
            $stmt = $db->prepare(
                "INSERT INTO email_contacts (email, first_name, last_name, phone, group_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   first_name = VALUES(first_name),
                   last_name  = VALUES(last_name),
                   phone      = VALUES(phone),
                   group_id   = VALUES(group_id)"
            );
            $stmt->execute([
                $email,
                $firstName ?: null,
                $lastName  ?: null,
                $phone     ?: null,
                $groupId   ?: null,
            ]);
            setFlash('Contact saved successfully.');
        } catch (\Throwable $e) {
            setFlash('Failed to save contact: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php');
    }

    // ── Edit Contact ──────────────────────────────────────────────────────────
    if ($action === 'edit_contact') {
        $contactId  = (int)($_POST['contact_id'] ?? 0);
        $email      = sanitizeEmail($_POST['email'] ?? '');
        $firstName  = sanitize($_POST['first_name'] ?? '');
        $lastName   = sanitize($_POST['last_name'] ?? '');
        $phone      = sanitize($_POST['phone'] ?? '');
        $groupId    = (int)($_POST['group_id'] ?? 0);

        if ($contactId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('Valid contact ID and email are required.', 'error');
            redirect('/admin/contacts.php');
        }

        try {
            $stmt = $db->prepare(
                "UPDATE email_contacts
                 SET email=?, first_name=?, last_name=?, phone=?, group_id=?
                 WHERE id=?"
            );
            $stmt->execute([
                $email,
                $firstName ?: null,
                $lastName  ?: null,
                $phone     ?: null,
                $groupId   ?: null,
                $contactId,
            ]);
            setFlash('Contact updated successfully.');
        } catch (\Throwable $e) {
            setFlash('Failed to update contact: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php');
    }

    // ── Delete Contact ────────────────────────────────────────────────────────
    if ($action === 'delete_contact') {
        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) {
            setFlash('Invalid contact ID.', 'error');
            redirect('/admin/contacts.php');
        }
        try {
            $stmt = $db->prepare("DELETE FROM email_contacts WHERE id=?");
            $stmt->execute([$contactId]);
            setFlash('Contact deleted.');
        } catch (\Throwable $e) {
            setFlash('Failed to delete contact: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php');
    }

    // ── Toggle Subscribe ──────────────────────────────────────────────────────
    if ($action === 'toggle_subscribe') {
        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) {
            setFlash('Invalid contact ID.', 'error');
            redirect('/admin/contacts.php');
        }
        try {
            $stmt = $db->prepare("UPDATE email_contacts SET is_subscribed = NOT is_subscribed WHERE id=?");
            $stmt->execute([$contactId]);
            setFlash('Subscription status toggled.');
        } catch (\Throwable $e) {
            setFlash('Failed to toggle subscription: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php');
    }

    // ── Add Group ─────────────────────────────────────────────────────────────
    if ($action === 'add_group') {
        $name        = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        if ($name === '') {
            setFlash('Group name is required.', 'error');
            redirect('/admin/contacts.php?tab=groups');
        }

        try {
            $stmt = $db->prepare("INSERT INTO contact_groups (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description ?: null]);
            setFlash('Group created successfully.');
        } catch (\Throwable $e) {
            setFlash('Failed to create group: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php?tab=groups');
    }

    // ── Edit Group ────────────────────────────────────────────────────────────
    if ($action === 'edit_group') {
        $groupId     = (int)($_POST['group_id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        if ($groupId <= 0 || $name === '') {
            setFlash('Valid group ID and name are required.', 'error');
            redirect('/admin/contacts.php?tab=groups');
        }

        try {
            $stmt = $db->prepare("UPDATE contact_groups SET name=?, description=? WHERE id=?");
            $stmt->execute([$name, $description ?: null, $groupId]);
            setFlash('Group updated successfully.');
        } catch (\Throwable $e) {
            setFlash('Failed to update group: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php?tab=groups');
    }

    // ── Delete Group ──────────────────────────────────────────────────────────
    if ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId <= 0) {
            setFlash('Invalid group ID.', 'error');
            redirect('/admin/contacts.php?tab=groups');
        }
        try {
            $stmt = $db->prepare("DELETE FROM contact_groups WHERE id=?");
            $stmt->execute([$groupId]);
            setFlash('Group deleted.');
        } catch (\Throwable $e) {
            setFlash('Failed to delete group: ' . $e->getMessage(), 'error');
        }
        redirect('/admin/contacts.php?tab=groups');
    }

    // ── Import CSV ────────────────────────────────────────────────────────────
    if ($action === 'import_csv') {
        $groupId        = (int)($_POST['group_id'] ?? 0);
        $skipDuplicates = isset($_POST['skip_duplicates']);
        $imported = 0;
        $skipped  = 0;
        $invalid  = 0;

        if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('CSV file upload failed.', 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $handle  = fopen($tmpFile, 'r');

        if ($handle === false) {
            setFlash('Could not read uploaded file.', 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        // Read header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            setFlash('CSV file is empty or unreadable.', 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        // Normalise header names to lowercase for case-insensitive matching
        $headerLower = array_map('strtolower', array_map('trim', $header));
        $colEmail     = array_search('email',      $headerLower, true);
        $colFirst     = array_search('first_name', $headerLower, true);
        $colLast      = array_search('last_name',  $headerLower, true);
        $colPhone     = array_search('phone',      $headerLower, true);

        if ($colEmail === false) {
            fclose($handle);
            setFlash('CSV must contain an "email" column header.', 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $email = trim($row[$colEmail] ?? '');
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid++;
                    continue;
                }

                $firstName = $colFirst !== false ? sanitize($row[$colFirst] ?? '') : '';
                $lastName  = $colLast  !== false ? sanitize($row[$colLast]  ?? '') : '';
                $phone     = $colPhone !== false ? sanitize($row[$colPhone] ?? '') : '';

                if ($skipDuplicates) {
                    $stmt = $db->prepare(
                        "INSERT IGNORE INTO email_contacts (email, first_name, last_name, phone, group_id)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $email,
                        $firstName ?: null,
                        $lastName  ?: null,
                        $phone     ?: null,
                        $groupId   ?: null,
                    ]);
                    if ($stmt->rowCount() > 0) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO email_contacts (email, first_name, last_name, phone, group_id)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                           first_name = VALUES(first_name),
                           last_name  = VALUES(last_name),
                           phone      = VALUES(phone),
                           group_id   = VALUES(group_id)"
                    );
                    $stmt->execute([
                        $email,
                        $firstName ?: null,
                        $lastName  ?: null,
                        $phone     ?: null,
                        $groupId   ?: null,
                    ]);
                    $imported++;
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            setFlash('Import error: ' . $e->getMessage(), 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        fclose($handle);
        setFlash("Imported: $imported, Skipped: $skipped, Invalid: $invalid");
        redirect('/admin/contacts.php?tab=import');
    }

    // ── Paste Emails ──────────────────────────────────────────────────────────
    if ($action === 'paste_emails') {
        $emailList = $_POST['email_list'] ?? '';
        $groupId   = (int)($_POST['group_id'] ?? 0);
        $imported  = 0;
        $skipped   = 0;
        $invalid   = 0;

        // Split on newlines and commas
        $raw = preg_split('/[\r\n,]+/', $emailList);

        try {
            $stmt = $db->prepare(
                "INSERT IGNORE INTO email_contacts (email, group_id) VALUES (?, ?)"
            );
            foreach ($raw as $item) {
                $email = trim($item);
                if ($email === '') continue;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid++;
                    continue;
                }
                $stmt->execute([$email, $groupId ?: null]);
                if ($stmt->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
        } catch (\Throwable $e) {
            setFlash('Import error: ' . $e->getMessage(), 'error');
            redirect('/admin/contacts.php?tab=import');
        }

        setFlash("Imported: $imported, Skipped: $skipped, Invalid: $invalid");
        redirect('/admin/contacts.php?tab=import');
    }
}

// ─── DATA LOADING ─────────────────────────────────────────────────────────────
$flash     = popFlash();
$activeTab = in_array($_GET['tab'] ?? '', ['contacts', 'groups', 'import'])
    ? ($_GET['tab'] ?? 'contacts')
    : 'contacts';
$search  = sanitize($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

// Groups for selects
try {
    $groups = $db->query("SELECT id, name FROM contact_groups ORDER BY name")->fetchAll();
} catch (\Throwable $e) {
    $groups = [];
}

// Stats
try {
    $totalContacts = (int)$db->query("SELECT COUNT(*) FROM email_contacts")->fetchColumn();
    $subscribed    = (int)$db->query("SELECT COUNT(*) FROM email_contacts WHERE is_subscribed=1")->fetchColumn();
    $unsubscribed  = (int)$db->query("SELECT COUNT(*) FROM email_contacts WHERE is_subscribed=0")->fetchColumn();
} catch (\Throwable $e) {
    $totalContacts = $subscribed = $unsubscribed = 0;
}

// Contacts with optional search + pagination
try {
    if ($search !== '') {
        $likeSearch = "%$search%";
        $stmt = $db->prepare(
            "SELECT ec.*, cg.name AS group_name
             FROM email_contacts ec
             LEFT JOIN contact_groups cg ON cg.id = ec.group_id
             WHERE ec.email LIKE ? OR ec.first_name LIKE ? OR ec.last_name LIKE ?
             ORDER BY ec.created_at DESC
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute([$likeSearch, $likeSearch, $likeSearch]);
        $contacts = $stmt->fetchAll();

        $stmtC = $db->prepare(
            "SELECT COUNT(*) FROM email_contacts ec
             WHERE ec.email LIKE ? OR ec.first_name LIKE ? OR ec.last_name LIKE ?"
        );
        $stmtC->execute([$likeSearch, $likeSearch, $likeSearch]);
        $totalFiltered = (int)$stmtC->fetchColumn();
    } else {
        $contacts = $db->query(
            "SELECT ec.*, cg.name AS group_name
             FROM email_contacts ec
             LEFT JOIN contact_groups cg ON cg.id = ec.group_id
             ORDER BY ec.created_at DESC
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        )->fetchAll();
        $totalFiltered = $totalContacts;
    }
} catch (\Throwable $e) {
    $contacts      = [];
    $totalFiltered = 0;
}
$totalPages = (int)ceil($totalFiltered / $perPage);

// Groups with contact count
try {
    $groupList = $db->query(
        "SELECT cg.*, COUNT(ec.id) AS contact_count
         FROM contact_groups cg
         LEFT JOIN email_contacts ec ON ec.group_id = cg.id
         GROUP BY cg.id
         ORDER BY cg.created_at DESC"
    )->fetchAll();
} catch (\Throwable $e) {
    $groupList = [];
}

// ─── PAGE META ────────────────────────────────────────────────────────────────
$pageTitle  = 'Email Contacts';
$activePage = 'contacts';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<style>
.tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tab-btn{padding:.5rem 1.25rem;border:none;background:var(--card-bg,#1e293b);color:var(--text-muted,#94a3b8);cursor:pointer;border-radius:6px;text-decoration:none;display:inline-block;font-size:.9rem;border:1px solid var(--border-color,#334155)}
.tab-btn.active{background:var(--primary,#6c63ff);color:#fff;border-color:var(--primary,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
.pagination{display:flex;gap:.25rem;margin-top:1rem;flex-wrap:wrap}
.pagination a,.pagination span{padding:.3rem .65rem;border-radius:4px;text-decoration:none;background:var(--card-bg,#1e293b);color:var(--text-muted,#94a3b8);border:1px solid var(--border-color,#334155);font-size:.85rem}
.pagination a:hover{background:var(--primary,#6c63ff);color:#fff}
.pagination .current{background:var(--primary,#6c63ff);color:#fff}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.collapse{display:none}.collapse.open{display:block}
</style>

<!-- Page header -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <h1 style="margin:0">Email Contacts</h1>
    <button class="btn btn-primary" onclick="toggleCollapse('addContactForm')">+ Add Contact</button>
</div>

<!-- Flash message -->
<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
    <a href="/admin/contacts.php?tab=contacts" class="tab-btn <?= $activeTab === 'contacts' ? 'active' : '' ?>">All Contacts</a>
    <a href="/admin/contacts.php?tab=groups"   class="tab-btn <?= $activeTab === 'groups'   ? 'active' : '' ?>">Groups</a>
    <a href="/admin/contacts.php?tab=import"   class="tab-btn <?= $activeTab === 'import'   ? 'active' : '' ?>">Import</a>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB: All Contacts
═══════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $activeTab === 'contacts' ? 'active' : '' ?>">

    <!-- Stats -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div class="stat-card card" style="padding:1rem;text-align:center">
            <div style="font-size:1.75rem;font-weight:700"><?= $totalContacts ?></div>
            <div style="color:var(--text-muted)">Total Contacts</div>
        </div>
        <div class="stat-card card" style="padding:1rem;text-align:center">
            <div style="font-size:1.75rem;font-weight:700;color:#22c55e"><?= $subscribed ?></div>
            <div style="color:var(--text-muted)">Subscribed</div>
        </div>
        <div class="stat-card card" style="padding:1rem;text-align:center">
            <div style="font-size:1.75rem;font-weight:700;color:#ef4444"><?= $unsubscribed ?></div>
            <div style="color:var(--text-muted)">Unsubscribed</div>
        </div>
    </div>

    <!-- Search -->
    <form method="GET" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
        <input type="hidden" name="tab" value="contacts">
        <input type="text" name="search" class="form-control" placeholder="Search email, name…"
               value="<?= htmlspecialchars($search) ?>" style="max-width:320px">
        <button class="btn btn-secondary">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/admin/contacts.php?tab=contacts" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Add Contact (collapsible) -->
    <div id="addContactForm" class="collapse card" style="padding:1.25rem;margin-bottom:1.25rem">
        <h3 style="margin-top:0">Add New Contact</h3>
        <form method="POST" action="/admin/contacts.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_contact">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
                <div>
                    <label>Email <span style="color:#ef4444">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div>
                    <label>First Name</label>
                    <input type="text" name="first_name" class="form-control">
                </div>
                <div>
                    <label>Last Name</label>
                    <input type="text" name="last_name" class="form-control">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <div>
                    <label>Group</label>
                    <select name="group_id" class="form-control">
                        <option value="0">— No Group —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save Contact</button>
                <button type="button" class="btn btn-secondary" onclick="toggleCollapse('addContactForm')">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Contacts table -->
    <div class="card" style="overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Phone</th>
                    <th>Group</th>
                    <th>Subscribed</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($contacts)): ?>
                <tr><td colspan="8" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No contacts found.</td></tr>
            <?php else: ?>
                <?php foreach ($contacts as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['email']) ?></td>
                    <td><?= htmlspecialchars($c['first_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['last_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                    <td><?= $c['group_name'] ? htmlspecialchars($c['group_name']) : '—' ?></td>
                    <td>
                        <?php if ($c['is_subscribed']): ?>
                            <span class="badge badge-success">Yes</span>
                        <?php else: ?>
                            <span class="badge badge-danger">No</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?= htmlspecialchars(substr($c['created_at'] ?? '', 0, 10)) ?></td>
                    <td style="white-space:nowrap;display:flex;gap:.3rem;flex-wrap:wrap">
                        <!-- Edit -->
                        <button class="btn btn-sm btn-secondary"
                            onclick="openEditModal(
                                <?= (int)$c['id'] ?>,
                                <?= json_encode($c['email']) ?>,
                                <?= json_encode($c['first_name'] ?? '') ?>,
                                <?= json_encode($c['last_name'] ?? '') ?>,
                                <?= json_encode($c['phone'] ?? '') ?>,
                                <?= (int)($c['group_id'] ?? 0) ?>
                            )">Edit</button>
                        <!-- Toggle subscribe -->
                        <form method="POST" action="/admin/contacts.php" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle_subscribe">
                            <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $c['is_subscribed'] ? 'btn-warning' : 'btn-success' ?>">
                                <?= $c['is_subscribed'] ? 'Unsub' : 'Sub' ?>
                            </button>
                        </form>
                        <!-- Delete -->
                        <form method="POST" action="/admin/contacts.php" style="display:inline"
                              onsubmit="return confirm('Delete this contact?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_contact">
                            <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $pBase = '/admin/contacts.php?tab=contacts' . ($search !== '' ? '&search=' . urlencode($search) : '');
        if ($page > 1): ?>
            <a href="<?= $pBase ?>&page=<?= $page - 1 ?>">&laquo; Prev</a>
        <?php endif;
        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);
        for ($p = $start; $p <= $end; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= $pBase ?>&page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor;
        if ($page < $totalPages): ?>
            <a href="<?= $pBase ?>&page=<?= $page + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /contacts tab -->

<!-- ══════════════════════════════════════════════════════════════
     TAB: Groups
═══════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $activeTab === 'groups' ? 'active' : '' ?>">

    <!-- Add Group form -->
    <div class="card" style="padding:1.25rem;margin-bottom:1.5rem">
        <h3 style="margin-top:0">Add New Group</h3>
        <form method="POST" action="/admin/contacts.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_group">
            <div style="display:grid;grid-template-columns:1fr 2fr auto;gap:1rem;align-items:end;flex-wrap:wrap">
                <div>
                    <label>Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div>
                    <label>Description</label>
                    <input type="text" name="description" class="form-control">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Add Group</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Groups table -->
    <div class="card" style="overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Contacts</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($groupList)): ?>
                <tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No groups yet.</td></tr>
            <?php else: ?>
                <?php foreach ($groupList as $g): ?>
                <tr>
                    <td><?= htmlspecialchars($g['name']) ?></td>
                    <td><?= htmlspecialchars($g['description'] ?? '') ?></td>
                    <td><?= (int)$g['contact_count'] ?></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars(substr($g['created_at'] ?? '', 0, 10)) ?></td>
                    <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <button class="btn btn-sm btn-secondary"
                            onclick="openEditGroupModal(
                                <?= (int)$g['id'] ?>,
                                <?= json_encode($g['name']) ?>,
                                <?= json_encode($g['description'] ?? '') ?>
                            )">Edit</button>
                        <form method="POST" action="/admin/contacts.php" style="display:inline"
                              onsubmit="return confirm('Delete this group? Contacts will not be deleted.')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div><!-- /groups tab -->

<!-- ══════════════════════════════════════════════════════════════
     TAB: Import
═══════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $activeTab === 'import' ? 'active' : '' ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.5rem">

        <!-- CSV Upload -->
        <div class="card" style="padding:1.25rem">
            <h3 style="margin-top:0">Upload CSV File</h3>
            <p style="color:var(--text-muted);font-size:.9rem">
                First row must be headers. Required column: <code>email</code>.
                Optional: <code>first_name</code>, <code>last_name</code>, <code>phone</code>.
            </p>
            <form method="POST" action="/admin/contacts.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="import_csv">
                <div style="margin-bottom:.75rem">
                    <label>CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required class="form-control">
                </div>
                <div style="margin-bottom:.75rem">
                    <label>Assign to Group (optional)</label>
                    <select name="group_id" class="form-control">
                        <option value="0">— No Group —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom:1rem">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="skip_duplicates" value="1" checked>
                        Skip duplicates
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">Import CSV</button>
            </form>
        </div>

        <!-- Paste Emails -->
        <div class="card" style="padding:1.25rem">
            <h3 style="margin-top:0">Paste Emails</h3>
            <p style="color:var(--text-muted);font-size:.9rem">
                One email per line, or comma-separated. Duplicates are automatically skipped.
            </p>
            <form method="POST" action="/admin/contacts.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="paste_emails">
                <div style="margin-bottom:.75rem">
                    <label>Email Addresses</label>
                    <textarea name="email_list" rows="8" class="form-control"
                              placeholder="one@example.com&#10;two@example.com"></textarea>
                </div>
                <div style="margin-bottom:1rem">
                    <label>Assign to Group (optional)</label>
                    <select name="group_id" class="form-control">
                        <option value="0">— No Group —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Import Emails</button>
            </form>
        </div>

    </div>
</div><!-- /import tab -->

<!-- ══════════════════════════════════════════════════════════════
     MODAL: Edit Contact
═══════════════════════════════════════════════════════════════ -->
<div id="editContactModal" class="modal-overlay" onclick="if(event.target===this)closeEditModal()">
    <div class="card" style="width:100%;max-width:540px;padding:1.5rem;position:relative">
        <h3 style="margin-top:0">Edit Contact</h3>
        <form method="POST" action="/admin/contacts.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_contact">
            <input type="hidden" name="contact_id" id="editContactId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div style="grid-column:1/-1">
                    <label>Email <span style="color:#ef4444">*</span></label>
                    <input type="email" name="email" id="editEmail" class="form-control" required>
                </div>
                <div>
                    <label>First Name</label>
                    <input type="text" name="first_name" id="editFirstName" class="form-control">
                </div>
                <div>
                    <label>Last Name</label>
                    <input type="text" name="last_name" id="editLastName" class="form-control">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" name="phone" id="editPhone" class="form-control">
                </div>
                <div>
                    <label>Group</label>
                    <select name="group_id" id="editGroupId" class="form-control">
                        <option value="0">— No Group —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:1.25rem;display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: Edit Group
═══════════════════════════════════════════════════════════════ -->
<div id="editGroupModal" class="modal-overlay" onclick="if(event.target===this)closeEditGroupModal()">
    <div class="card" style="width:100%;max-width:480px;padding:1.5rem;position:relative">
        <h3 style="margin-top:0">Edit Group</h3>
        <form method="POST" action="/admin/contacts.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_group">
            <input type="hidden" name="group_id" id="editGroupIdField">
            <div style="margin-bottom:.75rem">
                <label>Name <span style="color:#ef4444">*</span></label>
                <input type="text" name="name" id="editGroupName" class="form-control" required>
            </div>
            <div style="margin-bottom:1rem">
                <label>Description</label>
                <textarea name="description" id="editGroupDescription" rows="3" class="form-control"></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Save Group</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditGroupModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCollapse(id) {
    var el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function openEditModal(id, email, firstName, lastName, phone, groupId) {
    document.getElementById('editContactId').value  = id;
    document.getElementById('editEmail').value      = email;
    document.getElementById('editFirstName').value  = firstName;
    document.getElementById('editLastName').value   = lastName;
    document.getElementById('editPhone').value      = phone;
    var sel = document.getElementById('editGroupId');
    if (sel) sel.value = groupId || 0;
    document.getElementById('editContactModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editContactModal').classList.remove('open');
}

function openEditGroupModal(id, name, description) {
    document.getElementById('editGroupIdField').value      = id;
    document.getElementById('editGroupName').value         = name;
    document.getElementById('editGroupDescription').value  = description;
    document.getElementById('editGroupModal').classList.add('open');
}
function closeEditGroupModal() {
    document.getElementById('editGroupModal').classList.remove('open');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
