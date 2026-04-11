<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mailer.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();

$msg     = '';
$msgType = 'success';

/**
 * Fetch contacts for a campaign and send emails via Mailer.
 * Returns [sentCount, failCount].
 */
function sendCampaignEmails(\PDO $db, int $campaignId, int $groupId, string $subject, string $htmlBody, int $createdBy = 0): array {
    if ($groupId > 0) {
        $cStmt = $db->prepare(
            "SELECT email, first_name, last_name FROM email_contacts WHERE group_id = ? AND is_subscribed = 1"
        );
        $cStmt->execute([$groupId]);
    } else {
        $cStmt = $db->query(
            "SELECT email, first_name, last_name FROM email_contacts WHERE is_subscribed = 1"
        );
    }
    $contacts = $cStmt->fetchAll();

    // Resolve per-hour limit (applies to ALL email servers) and monthly limit
    // (applies ONLY when user is on Default SMTP — no active custom user_smtp_settings).
    $emailsPerHour    = 0;
    $monthlyLimit     = 0;
    $enforceMonthly   = false; // only enforced when using Default/system SMTP

    if ($createdBy > 0) {
        try {
            $planStmt = $db->prepare(
                "SELECT ep.emails_per_hour, ep.monthly_email_limit FROM user_subscriptions us
                 JOIN email_plans ep ON ep.id = us.plan_id
                 WHERE us.user_id = ? AND us.status = 'active' LIMIT 1"
            );
            $planStmt->execute([$createdBy]);
            $planRow = $planStmt->fetch();
            if ($planRow) {
                $emailsPerHour = (int)$planRow['emails_per_hour'];
                $monthlyLimit  = (int)$planRow['monthly_email_limit'];
            }

            // Monthly limit only applies when the user has NO active custom SMTP config
            // (i.e. they are using the system Default SMTP).
            $customStmt = $db->prepare(
                "SELECT id FROM user_smtp_settings WHERE user_id = ? AND is_active = 1 LIMIT 1"
            );
            $customStmt->execute([$createdBy]);
            $hasCustomSmtp  = (bool)$customStmt->fetch();
            $enforceMonthly = !$hasCustomSmtp && $monthlyLimit > 0;
        } catch (\Exception $e) {}
    }

    $db->prepare("UPDATE email_campaigns SET status='sending' WHERE id=?")->execute([$campaignId]);

    $mailer    = new Mailer($createdBy);
    $sentCount = 0;
    $failCount = 0;
    $sentThisHour      = 0;
    $emailsUsedMonth   = 0;
    $hourLimitReached  = false;
    $monthLimitReached = false;

    // Count emails already sent in the last hour for this user (all servers)
    if ($emailsPerHour > 0 && $createdBy > 0) {
        try {
            $hStmt = $db->prepare(
                "SELECT COALESCE(SUM(sent_count),0) FROM email_campaigns
                 WHERE created_by = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $hStmt->execute([$createdBy]);
            $sentThisHour = (int)$hStmt->fetchColumn();
        } catch (\Exception $e) {}
    }

    // Count emails sent this calendar month for Default SMTP enforcement
    if ($enforceMonthly && $createdBy > 0) {
        try {
            $mStmt = $db->prepare(
                "SELECT COALESCE(SUM(sent_count),0) FROM email_campaigns
                 WHERE created_by = ? AND sent_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
            );
            $mStmt->execute([$createdBy]);
            $emailsUsedMonth = (int)$mStmt->fetchColumn();
        } catch (\Exception $e) {}
    }

    foreach ($contacts as $contact) {
        // Enforce hourly limit (all servers)
        if ($emailsPerHour > 0 && $sentThisHour + $sentCount >= $emailsPerHour) {
            $hourLimitReached = true;
            $failCount += count($contacts) - $sentCount - $failCount;
            break;
        }
        // Enforce monthly limit (Default SMTP only)
        if ($enforceMonthly && $emailsUsedMonth + $sentCount >= $monthlyLimit) {
            $monthLimitReached = true;
            $failCount += count($contacts) - $sentCount - $failCount;
            break;
        }

        $personalised = str_replace(
            ['{{first_name}}', '{{last_name}}', '{{email}}'],
            [
                htmlspecialchars($contact['first_name'] ?? ''),
                htmlspecialchars($contact['last_name'] ?? ''),
                htmlspecialchars($contact['email']),
            ],
            $htmlBody
        );
        $to = [['email' => $contact['email'], 'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))]];
        if ($mailer->send($to, $subject, $personalised)) {
            $sentCount++;
        } else {
            $failCount++;
        }
    }

    $limitReached = $hourLimitReached || $monthLimitReached;
    $finalStatus  = $limitReached ? 'failed' : (($failCount > 0 && $sentCount === 0) ? 'failed' : 'sent');
    $db->prepare(
        "UPDATE email_campaigns SET status=?, sent_count=?, failed_count=?, total_recipients=?, sent_at=NOW() WHERE id=?"
    )->execute([$finalStatus, $sentCount, $failCount, count($contacts), $campaignId]);

    return [$sentCount, $failCount];
}

// ─── POST HANDLERS ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create Campaign ───────────────────────────────────────────────────
    if ($action === 'create_campaign') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token. Please try again.';
            $msgType = 'error';
        } else {
            $name          = sanitize($_POST['name'] ?? '');
            $subject       = sanitize($_POST['subject'] ?? '');
            $templateId    = (int)($_POST['template_id'] ?? 0);
            $groupId       = (int)($_POST['group_id'] ?? 0);
            $htmlContent   = trim($_POST['html_content'] ?? '');
            $scheduleType  = $_POST['schedule_type'] ?? 'now';
            $scheduledAt   = $_POST['scheduled_at'] ?? '';
            $timezone      = sanitize($_POST['timezone'] ?? 'UTC');

            if ($name === '') {
                $msg     = 'Campaign name is required.';
                $msgType = 'error';
            } elseif ($subject === '') {
                $msg     = 'Subject is required.';
                $msgType = 'error';
            } elseif ($templateId === 0 && $htmlContent === '') {
                $msg     = 'Please provide HTML content or select a template.';
                $msgType = 'error';
            } else {
                try {
                    // If custom HTML was provided, save it as a new template
                    if ($templateId === 0 && $htmlContent !== '') {
                        $stmt = $db->prepare(
                            "INSERT INTO email_templates (name, subject, html_content, created_by, created_at, updated_at)
                             VALUES (?, ?, ?, ?, NOW(), NOW())"
                        );
                        $stmt->execute([$name . ' (auto-saved from campaign)', $subject, $htmlContent, $user['id'] ?? null]);
                        $templateId = (int)$db->lastInsertId();
                    }

                    // Count total recipients
                    if ($groupId > 0) {
                        $cntStmt = $db->prepare("SELECT COUNT(*) FROM email_contacts WHERE group_id = ? AND is_subscribed = 1");
                        $cntStmt->execute([$groupId]);
                    } else {
                        $cntStmt = $db->query("SELECT COUNT(*) FROM email_contacts WHERE is_subscribed = 1");
                    }
                    $totalRecipients = (int)$cntStmt->fetchColumn();

                    $status      = ($scheduleType === 'later') ? 'scheduled' : 'draft';
                    $scheduledTs = ($scheduleType === 'later' && $scheduledAt !== '') ? $scheduledAt : null;

                    $stmt = $db->prepare(
                        "INSERT INTO email_campaigns
                             (name, subject, template_id, group_id, status, scheduled_at, total_recipients, sent_count, failed_count, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
                    );
                    $stmt->execute([
                        $name,
                        $subject,
                        $templateId ?: null,
                        $groupId ?: null,
                        $status,
                        $scheduledTs,
                        $totalRecipients,
                    ]);
                    $campaignId = (int)$db->lastInsertId();

                    if ($scheduleType === 'now') {
                        // Fetch html_content from template
                        $tplStmt = $db->prepare("SELECT html_content FROM email_templates WHERE id = ?");
                        $tplStmt->execute([$templateId]);
                        $tpl  = $tplStmt->fetch();
                        $body = $tpl ? ($tpl['html_content'] ?? '') : $htmlContent;

                        [$sentCount, $failCount] = sendCampaignEmails($db, $campaignId, $groupId, $subject, $body, (int)($user['id'] ?? 0));

                        $msg     = "Campaign sent: {$sentCount} delivered, {$failCount} failed.";
                        $msgType = $failCount > 0 && $sentCount === 0 ? 'error' : ($failCount > 0 ? 'warning' : 'success');
                    } elseif ($scheduleType === 'later') {
                        $stmt = $db->prepare(
                            "INSERT INTO scheduled_jobs (job_type, campaign_id, scheduled_at, timezone, status, created_at)
                             VALUES ('email_campaign', ?, ?, ?, 'pending', NOW())"
                        );
                        $stmt->execute([$campaignId, $scheduledTs, $timezone]);

                        $msg     = 'Campaign scheduled successfully.';
                        $msgType = 'success';
                    } else {
                        $msg     = 'Campaign created as draft.';
                        $msgType = 'success';
                    }
                } catch (\Exception $e) {
                    error_log('create_campaign error: ' . $e->getMessage());
                    $msg     = 'Failed to create campaign. Please try again.';
                    $msgType = 'error';
                }
            }
        }
    }

    // ── Delete Campaign ───────────────────────────────────────────────────
    elseif ($action === 'delete_campaign') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $db->prepare("DELETE FROM email_campaigns WHERE id = ?")->execute([$id]);
                $msg     = 'Campaign deleted.';
                $msgType = 'success';
            } catch (\Exception $e) {
                error_log('delete_campaign error: ' . $e->getMessage());
                $msg     = 'Failed to delete campaign.';
                $msgType = 'error';
            }
        }
    }

    // ── Send Draft/Scheduled Campaign ─────────────────────────────────────
    elseif ($action === 'send_campaign') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $cmpStmt = $db->prepare("SELECT * FROM email_campaigns WHERE id = ?");
                $cmpStmt->execute([$id]);
                $campaign = $cmpStmt->fetch();

                if (!$campaign) {
                    $msg     = 'Campaign not found.';
                    $msgType = 'error';
                } else {
                    $body = '';
                    if ($campaign['template_id']) {
                        $tplStmt = $db->prepare("SELECT html_content FROM email_templates WHERE id = ?");
                        $tplStmt->execute([$campaign['template_id']]);
                        $tpl  = $tplStmt->fetch();
                        $body = $tpl ? ($tpl['html_content'] ?? '') : '';
                    }

                    $groupId = (int)($campaign['group_id'] ?? 0);

                    [$sentCount, $failCount] = sendCampaignEmails($db, $id, $groupId, $campaign['subject'], $body, (int)($user['id'] ?? 0));

                    $msg     = "Campaign sent: {$sentCount} delivered, {$failCount} failed.";
                    $msgType = $failCount > 0 && $sentCount === 0 ? 'error' : ($failCount > 0 ? 'warning' : 'success');
                }
            } catch (\Exception $e) {
                error_log('send_campaign error: ' . $e->getMessage());
                $msg     = 'Failed to send campaign.';
                $msgType = 'error';
            }
        }
    }

    // ── Create Template ───────────────────────────────────────────────────
    elseif ($action === 'create_template') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $tplName    = sanitize($_POST['tpl_name'] ?? '');
            $tplSubject = sanitize($_POST['tpl_subject'] ?? '');
            $tplHtml    = trim($_POST['tpl_html_content'] ?? '');

            if ($tplName === '') {
                $msg     = 'Template name is required.';
                $msgType = 'error';
            } elseif ($tplHtml === '') {
                $msg     = 'Template HTML content is required.';
                $msgType = 'error';
            } else {
                try {
                    $stmt = $db->prepare(
                        "INSERT INTO email_templates (name, subject, html_content, created_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW())"
                    );
                    $stmt->execute([$tplName, $tplSubject, $tplHtml, $user['id'] ?? null]);
                    $msg     = 'Template created successfully.';
                    $msgType = 'success';
                } catch (\Exception $e) {
                    error_log('create_template error: ' . $e->getMessage());
                    $msg     = 'Failed to create template.';
                    $msgType = 'error';
                }
            }
        }
    }

    // ── Delete Template ───────────────────────────────────────────────────
    elseif ($action === 'delete_template') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $db->prepare("DELETE FROM email_templates WHERE id = ?")->execute([$id]);
                $msg     = 'Template deleted.';
                $msgType = 'success';
            } catch (\Exception $e) {
                error_log('delete_template error: ' . $e->getMessage());
                $msg     = 'Failed to delete template.';
                $msgType = 'error';
            }
        }
    }
}

// ─── DATA FOR PAGE ───────────────────────────────────────────────────────────

$filterStatus = $_GET['status'] ?? 'all';

$campaigns = [];
try {
    if ($filterStatus !== 'all' && in_array($filterStatus, ['draft','scheduled','sending','sent','failed'], true)) {
        $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$filterStatus]);
    } else {
        $stmt = $db->query("SELECT * FROM email_campaigns ORDER BY created_at DESC");
    }
    $campaigns = $stmt->fetchAll();
} catch (\Exception $e) {
    error_log('fetch campaigns error: ' . $e->getMessage());
}

$templates = [];
try {
    $templates = $db->query("SELECT * FROM email_templates ORDER BY created_at DESC")->fetchAll();
} catch (\Exception $e) {
    error_log('fetch templates error: ' . $e->getMessage());
}

$groups = [];
try {
    $groups = $db->query("SELECT id, name FROM contact_groups ORDER BY name ASC")->fetchAll();
} catch (\Exception $e) {
    error_log('fetch groups error: ' . $e->getMessage());
}

$smtp = [];
try {
    $smtp = $db->query("SELECT from_email, from_name FROM smtp_settings WHERE id = 1")->fetch() ?: [];
} catch (\Exception $e) {
    error_log('fetch smtp error: ' . $e->getMessage());
}

$csrfToken  = csrfToken();
$pageTitle  = 'Email Campaigns';
$activePage = 'email';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div class="page-header-row">
        <h1>📧 Email Campaigns</h1>
        <div class="page-header-actions">
            <a href="#new-campaign" class="btn btn-primary tab-link" data-tab="new-campaign">+ New Campaign</a>
        </div>
    </div>
</div>

<?php if ($msg !== ''): ?>
<div class="alert alert-<?= htmlspecialchars($msgType) ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ─── Tabs ────────────────────────────────────────────────────────────── -->
<div class="tabs">
    <button class="tab-btn active" data-tab="campaigns">Campaigns</button>
    <button class="tab-btn" data-tab="new-campaign">New Campaign</button>
    <button class="tab-btn" data-tab="templates">Templates</button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Campaigns
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content active" id="tab-campaigns">
    <div class="card">
        <div class="card-header">
            <h3>All Campaigns</h3>
            <!-- Filter -->
            <form method="GET" action="" style="display:flex;gap:.5rem;align-items:center;">
                <select name="status" class="form-control" style="width:auto;">
                    <?php foreach (['all' => 'All Statuses', 'draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($campaigns)): ?>
            <p class="empty-state">No campaigns found. <a href="#new-campaign" class="tab-link" data-tab="new-campaign">Create your first campaign</a>.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Recipients</th>
                        <th>Sent / Failed</th>
                        <th>Scheduled / Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['subject']) ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                    <td><?= number_format((int)$c['total_recipients']) ?></td>
                    <td><?= number_format((int)$c['sent_count']) ?> / <?= number_format((int)$c['failed_count']) ?></td>
                    <td>
                        <?php if (!empty($c['scheduled_at'])): ?>
                            📅 <?= htmlspecialchars($c['scheduled_at']) ?>
                        <?php else: ?>
                            <?= timeAgo($c['created_at']) ?>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        <?php if (in_array($c['status'], ['draft', 'scheduled', 'failed'], true)): ?>
                        <form method="POST" action="" style="display:inline;">
                            <input type="hidden" name="action" value="send_campaign">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <button type="submit" class="btn btn-primary btn-sm">▶ Send</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this campaign? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_campaign">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
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

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: New Campaign
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-new-campaign">
    <div class="card">
        <div class="card-header">
            <h3>Create New Campaign</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="campaignForm">
                <input type="hidden" name="action" value="create_campaign">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="camp_name">Campaign Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="camp_name" name="name" class="form-control" required placeholder="e.g. July Newsletter">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="camp_subject">Email Subject <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="camp_subject" name="subject" class="form-control" required placeholder="e.g. Check out our latest news!">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="camp_from_name">From Name</label>
                        <input type="text" id="camp_from_name" name="from_name" class="form-control"
                               placeholder="<?= htmlspecialchars($smtp['from_name'] ?? 'Your Name') ?>">
                        <span class="form-text">For reference only — the active SMTP from name is used when sending.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="camp_from_email">From Email</label>
                        <input type="email" id="camp_from_email" name="from_email" class="form-control"
                               placeholder="<?= htmlspecialchars($smtp['from_email'] ?? 'you@example.com') ?>">
                        <span class="form-text">For reference only — the active SMTP from email is used when sending.</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="camp_template">Email Template</label>
                        <select id="camp_template" name="template_id" class="form-control" onchange="toggleHtmlContent(this)">
                            <option value="0">— None (write custom HTML) —</option>
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>"><?= htmlspecialchars($tpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="camp_group">Contact Group</label>
                        <select id="camp_group" name="group_id" class="form-control">
                            <option value="0">All Subscribers</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="htmlContentWrap">
                    <!-- Creator / Clipper mode switch -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                        <label class="form-label" style="margin:0">HTML Content <span style="color:var(--danger)">*</span></label>
                        <div class="mode-switcher" id="campModeSwitcher">
                            <button type="button" class="mode-btn active" data-mode="clipper" onclick="setCampMode('clipper',this)">✂️ Clipper</button>
                            <button type="button" class="mode-btn" data-mode="creator" onclick="setCampMode('creator',this)">🎨 Creator</button>
                        </div>
                    </div>
                    <!-- Clipper: raw HTML textarea -->
                    <div id="campClipperPane">
                        <textarea id="camp_html" name="html_content" class="form-control" rows="12"
                                  placeholder="Paste or write your email HTML here. Use {{first_name}}, {{last_name}}, {{email}} for personalisation."></textarea>
                        <span class="form-text">Supports <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{email}}</code> placeholders.</span>
                    </div>
                    <!-- Creator: visual drag-and-drop builder -->
                    <div id="campCreatorPane" style="display:none">
                        <div style="display:grid;grid-template-columns:180px 1fr;gap:1rem">
                            <div class="builder-blocks">
                                <h4>📦 Blocks</h4>
                                <div class="builder-blocks-list" id="campBlocksList"></div>
                            </div>
                            <div class="builder-canvas">
                                <div id="campEmailCanvas">
                                    <div class="canvas-drop-zone" id="campCanvasDropZone">
                                        <span>⬇ Drag blocks here to build your email</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <span class="form-text" style="margin-top:.5rem">Drag blocks from the left panel onto the canvas. Switch to Clipper mode to edit the raw HTML.</span>
                    </div>
                </div>

                <!-- Schedule -->
                <div class="form-group">
                    <label class="form-label">Schedule</label>
                    <div style="display:flex;gap:1.5rem;margin-top:.4rem;">
                        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                            <input type="radio" name="schedule_type" value="now" checked onchange="toggleScheduleFields(this.value)">
                            Send Now
                        </label>
                        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                            <input type="radio" name="schedule_type" value="later" onchange="toggleScheduleFields(this.value)">
                            Send Later
                        </label>
                    </div>
                </div>

                <div id="scheduleFields" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="camp_scheduled_at">Scheduled Date &amp; Time</label>
                            <input type="datetime-local" id="camp_scheduled_at" name="scheduled_at" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="camp_timezone">Timezone</label>
                            <select id="camp_timezone" name="timezone" class="form-control">
                                <option value="UTC">UTC</option>
                                <option value="Africa/Lagos">Africa/Lagos (WAT)</option>
                                <option value="America/New_York">America/New_York (EST/EDT)</option>
                                <option value="America/Chicago">America/Chicago (CST/CDT)</option>
                                <option value="America/Denver">America/Denver (MST/MDT)</option>
                                <option value="America/Los_Angeles">America/Los_Angeles (PST/PDT)</option>
                                <option value="America/Sao_Paulo">America/Sao_Paulo (BRT)</option>
                                <option value="Europe/London">Europe/London (GMT/BST)</option>
                                <option value="Europe/Paris">Europe/Paris (CET/CEST)</option>
                                <option value="Europe/Berlin">Europe/Berlin (CET/CEST)</option>
                                <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                                <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
                                <option value="Asia/Singapore">Asia/Singapore (SGT)</option>
                                <option value="Asia/Tokyo">Asia/Tokyo (JST)</option>
                                <option value="Asia/Shanghai">Asia/Shanghai (CST)</option>
                                <option value="Australia/Sydney">Australia/Sydney (AEST/AEDT)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card-footer" style="padding:1rem 0 0;border-top:1px solid var(--border);">
                    <button type="submit" class="btn btn-primary">🚀 Create Campaign</button>
                    <a href="#campaigns" class="btn btn-secondary tab-link" data-tab="campaigns">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Templates
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-templates">
    <div class="card">
        <div class="card-header">
            <h3>Email Templates</h3>
            <button class="btn btn-primary btn-sm" data-modal="modal-new-template">+ New Template</button>
        </div>
        <div class="card-body">
            <?php if (empty($templates)): ?>
            <p class="empty-state">No templates yet. Create one to reuse across campaigns.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Subject</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $tpl): ?>
                <tr>
                    <td><?= htmlspecialchars($tpl['name']) ?></td>
                    <td><?= htmlspecialchars($tpl['subject'] ?? '') ?></td>
                    <td><?= timeAgo($tpl['created_at']) ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this template?');">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
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

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: New Template
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-new-template">
    <div class="modal">
        <div class="modal-header">
            <h3>New Email Template</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_template">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="tpl_name">Template Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="tpl_name" name="tpl_name" class="form-control" required placeholder="e.g. Welcome Email">
                </div>
                <div class="form-group">
                    <label class="form-label" for="tpl_subject">Default Subject</label>
                    <input type="text" id="tpl_subject" name="tpl_subject" class="form-control" placeholder="e.g. Welcome to our service!">
                </div>
                <div class="form-group">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                        <label class="form-label" style="margin:0">HTML Content <span style="color:var(--danger)">*</span></label>
                        <div class="mode-switcher" id="tplModeSwitcher">
                            <button type="button" class="mode-btn active" data-mode="clipper" onclick="setTplMode('clipper',this)">✂️ Clipper</button>
                            <button type="button" class="mode-btn" data-mode="creator" onclick="setTplMode('creator',this)">🎨 Creator</button>
                        </div>
                    </div>
                    <!-- Clipper: raw HTML -->
                    <div id="tplClipperPane">
                        <textarea id="tpl_html_content" name="tpl_html_content" class="form-control" rows="10"
                                  placeholder="Paste your email HTML here. Use {{first_name}}, {{last_name}}, {{email}} for personalisation."></textarea>
                        <span class="form-text">Supports <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{email}}</code> placeholders.</span>
                    </div>
                    <!-- Creator: visual builder -->
                    <div id="tplCreatorPane" style="display:none">
                        <div style="display:grid;grid-template-columns:180px 1fr;gap:1rem">
                            <div class="builder-blocks">
                                <h4>📦 Blocks</h4>
                                <div class="builder-blocks-list" id="tplBlocksList"></div>
                            </div>
                            <div class="builder-canvas">
                                <div id="tplEmailCanvas">
                                    <div class="canvas-drop-zone" id="tplCanvasDropZone">
                                        <span>⬇ Drag blocks here to build your email</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <span class="form-text" style="margin-top:.5rem">Drag blocks onto the canvas. Switch to Clipper to edit raw HTML.</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Template</button>
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle HTML content textarea based on template selection
function toggleHtmlContent(sel) {
    var wrap = document.getElementById('htmlContentWrap');
    if (!wrap) return;
    wrap.style.display = (sel.value === '0') ? 'block' : 'none';
}

// Toggle schedule fields based on radio selection
function toggleScheduleFields(val) {
    var fields = document.getElementById('scheduleFields');
    if (!fields) return;
    fields.style.display = (val === 'later') ? 'block' : 'none';
}

// ── Creator / Clipper mode switch helpers ─────────────────────────────────────
function makeBuilder(canvasId, dropZoneId, blocksListId, htmlTargetId) {
    var blockTypes = {
        header:  { icon: '🔤', label: 'Header',   html: '<h1 style="text-align:center;color:#333;font-family:sans-serif;padding:20px">Your Header Text</h1>' },
        text:    { icon: '📝', label: 'Text',      html: '<p style="color:#555;font-family:sans-serif;padding:10px 20px;line-height:1.6">Your paragraph text goes here.</p>' },
        image:   { icon: '🖼️', label: 'Image',     html: '<div style="text-align:center;padding:10px"><img src="https://via.placeholder.com/600x200" style="max-width:100%;height:auto" alt="Image"></div>' },
        button:  { icon: '🔘', label: 'Button',    html: '<div style="text-align:center;padding:20px"><a href="#" style="background:#6c63ff;color:#fff;padding:12px 30px;border-radius:8px;text-decoration:none;font-family:sans-serif;font-weight:bold">Click Here</a></div>' },
        divider: { icon: '➖', label: 'Divider',   html: '<hr style="border:none;border-top:1px solid #eee;margin:10px 20px">' },
        spacer:  { icon: '⬜', label: 'Spacer',    html: '<div style="height:20px"></div>' },
    };
    var blocks    = [];
    var dragData  = null;
    var canvas    = document.getElementById(canvasId);
    var dropZone  = document.getElementById(dropZoneId);
    var blocksList= document.getElementById(blocksListId);
    var htmlTarget= document.getElementById(htmlTargetId);
    if (!canvas || !dropZone) return;

    Object.entries(blockTypes).forEach(function([type, info]) {
        var el = document.createElement('div');
        el.className   = 'block-item';
        el.draggable   = true;
        el.dataset.type= type;
        el.innerHTML   = '<span>' + info.icon + '</span><span>' + info.label + '</span>';
        el.addEventListener('dragstart', function(e) { dragData = type; e.dataTransfer.effectAllowed = 'copy'; });
        if (blocksList) blocksList.appendChild(el);
    });

    dropZone.addEventListener('dragover',  function(e) { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', function()  { dropZone.classList.remove('drag-over'); });
    dropZone.addEventListener('drop',      function(e) {
        e.preventDefault(); dropZone.classList.remove('drag-over');
        if (dragData) { addBlock(dragData); dragData = null; }
    });

    function addBlock(type, customHtml) {
        var id   = 'blk_' + Date.now();
        var html = customHtml || blockTypes[type].html || '';
        blocks.push({ id: id, type: type, html: html });
        render(); syncHtml();
    }

    function render() {
        if (blocks.length === 0) { dropZone.style.display = 'flex'; return; }
        dropZone.style.display = 'none';
        canvas.querySelectorAll('.canvas-block').forEach(function(b) { b.remove(); });
        blocks.forEach(function(block, idx) {
            var el = document.createElement('div');
            el.className  = 'canvas-block';
            el.dataset.id = block.id;
            el.innerHTML =
                '<div class="canvas-block-actions">' +
                    '<button type="button" class="btn btn-sm btn-secondary" onclick="editBuilderBlock(\'' + canvasId + '\',\'' + block.id + '\')" title="Edit">✏️</button>' +
                    '<button type="button" class="btn btn-sm btn-secondary" onclick="moveBuilderBlock(\'' + canvasId + '\',' + idx + ',-1)" ' + (idx===0?'disabled':'') + ' title="Up">↑</button>' +
                    '<button type="button" class="btn btn-sm btn-secondary" onclick="moveBuilderBlock(\'' + canvasId + '\',' + idx + ',1)" ' + (idx===blocks.length-1?'disabled':'') + ' title="Down">↓</button>' +
                    '<button type="button" class="btn btn-sm btn-danger"    onclick="removeBuilderBlock(\'' + canvasId + '\',\'' + block.id + '\')" title="Delete">🗑️</button>' +
                '</div>' +
                '<div class="canvas-block-content">' + block.html + '</div>';
            canvas.insertBefore(el, dropZone);
        });
    }

    function syncHtml() {
        var full = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:sans-serif;background:#fff;margin:0;padding:0}.email-wrap{max-width:600px;margin:0 auto;background:#fff}</style></head><body><div class="email-wrap">' + blocks.map(function(b){return b.html;}).join('\n') + '</div></body></html>';
        if (htmlTarget) htmlTarget.value = full;
    }

    // Expose block manipulation to global onclick handlers using the canvasId as key
    if (!window.__builders) window.__builders = {};
    window.__builders[canvasId] = { blocks: blocks, render: render, syncHtml: syncHtml };
}

window.editBuilderBlock = function(canvasId, id) {
    var b = window.__builders && window.__builders[canvasId];
    if (!b) return;
    var block = b.blocks.find(function(x){ return x.id === id; });
    if (!block) return;
    var newHtml = prompt('Edit HTML:', block.html);
    if (newHtml !== null) { block.html = newHtml; b.render(); b.syncHtml(); }
};
window.removeBuilderBlock = function(canvasId, id) {
    var b = window.__builders && window.__builders[canvasId];
    if (!b) return;
    b.blocks.splice(b.blocks.findIndex(function(x){ return x.id === id; }), 1);
    b.render(); b.syncHtml();
    if (b.blocks.length === 0) { var dz = document.getElementById(canvasId).querySelector('.canvas-drop-zone'); if(dz) dz.style.display='flex'; }
};
window.moveBuilderBlock = function(canvasId, idx, dir) {
    var b = window.__builders && window.__builders[canvasId];
    if (!b) return;
    var target = idx + dir;
    if (target < 0 || target >= b.blocks.length) return;
    var tmp = b.blocks[idx]; b.blocks[idx] = b.blocks[target]; b.blocks[target] = tmp;
    b.render(); b.syncHtml();
};

function setCampMode(mode, btn) {
    document.querySelectorAll('#campModeSwitcher .mode-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    var clipper = document.getElementById('campClipperPane');
    var creator = document.getElementById('campCreatorPane');
    if (mode === 'creator') {
        clipper.style.display = 'none';
        creator.style.display = 'block';
        // Copy current textarea content to builder HTML target if it exists
        makeBuilder('campEmailCanvas', 'campCanvasDropZone', 'campBlocksList', 'camp_html');
    } else {
        creator.style.display = 'none';
        clipper.style.display = 'block';
    }
}

function setTplMode(mode, btn) {
    document.querySelectorAll('#tplModeSwitcher .mode-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    var clipper = document.getElementById('tplClipperPane');
    var creator = document.getElementById('tplCreatorPane');
    if (mode === 'creator') {
        clipper.style.display = 'none';
        creator.style.display = 'block';
        makeBuilder('tplEmailCanvas', 'tplCanvasDropZone', 'tplBlocksList', 'tpl_html_content');
    } else {
        creator.style.display = 'none';
        clipper.style.display = 'block';
    }
}

// Handle tab-link anchors (e.g. "New Campaign" button in page header)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tab-link[data-tab]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var target = this.getAttribute('data-tab');
            var btn = document.querySelector('.tab-btn[data-tab="' + target + '"]');
            if (btn) btn.click();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
