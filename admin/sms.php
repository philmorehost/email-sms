<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/sms.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();

$msg     = '';
$msgType = 'success';

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
            $name         = sanitize($_POST['name'] ?? '');
            $route        = sanitize($_POST['route'] ?? '');
            $senderID     = sanitize($_POST['sender_id'] ?? '');
            $callerID     = sanitize($_POST['caller_id'] ?? '');
            $message      = trim($_POST['message'] ?? '');
            $audioUrl     = trim($_POST['audio_url'] ?? '');
            $groupId      = (int)($_POST['group_id'] ?? 0);
            $customNums   = trim($_POST['custom_recipients'] ?? '');
            $scheduleType = $_POST['schedule_type'] ?? 'now';
            $scheduledAt  = $_POST['scheduled_at'] ?? '';
            $timezone     = sanitize($_POST['timezone'] ?? 'UTC');

            $validRoutes = ['bulk', 'corporate', 'global', 'voice', 'voice_audio'];

            if ($name === '') {
                $msg     = 'Campaign name is required.';
                $msgType = 'error';
            } elseif (!in_array($route, $validRoutes, true)) {
                $msg     = 'Invalid route selected.';
                $msgType = 'error';
            } elseif (in_array($route, ['bulk', 'corporate', 'global'], true) && $senderID === '') {
                $msg     = 'Sender ID is required for this route.';
                $msgType = 'error';
            } elseif (in_array($route, ['voice', 'voice_audio'], true) && $callerID === '') {
                $msg     = 'Caller ID is required for voice routes.';
                $msgType = 'error';
            } elseif ($route !== 'voice_audio' && $message === '') {
                $msg     = 'Message is required.';
                $msgType = 'error';
            } elseif ($route === 'voice_audio' && $audioUrl === '') {
                $msg     = 'Audio URL is required for Voice Audio route.';
                $msgType = 'error';
            } else {
                try {
                    // Build recipients list
                    $phones = [];
                    if ($groupId > 0) {
                        $cStmt = $db->prepare(
                            "SELECT phone FROM sms_contacts WHERE group_id = ? AND is_subscribed = 1"
                        );
                        $cStmt->execute([$groupId]);
                        $phones = $cStmt->fetchAll(\PDO::FETCH_COLUMN);
                    } elseif ($customNums !== '') {
                        foreach (explode(',', $customNums) as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $phones[] = $p;
                            }
                        }
                    }

                    $totalRecipients = count($phones);

                    // DB route is always one of the four enum values; voice_audio maps to 'voice'
                    $dbRoute     = ($route === 'voice_audio') ? 'voice' : $route;
                    $dbSenderID  = in_array($route, ['voice', 'voice_audio'], true) ? $callerID : $senderID;

                    $status      = ($scheduleType === 'later') ? 'scheduled' : 'draft';
                    $scheduledTs = ($scheduleType === 'later' && $scheduledAt !== '') ? $scheduledAt : null;
                    $dbMessage   = ($route === 'voice_audio') ? $audioUrl : $message;

                    $stmt = $db->prepare(
                        "INSERT INTO sms_campaigns
                             (name, sender_id, message, route, group_id, status, scheduled_at, total_recipients, sent_count, failed_count, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
                    );
                    $stmt->execute([
                        $name,
                        $dbSenderID,
                        $dbMessage,
                        $dbRoute,
                        $groupId ?: null,
                        $status,
                        $scheduledTs,
                        $totalRecipients,
                    ]);
                    $campaignId = (int)$db->lastInsertId();

                    if ($scheduleType === 'now') {
                        $sms = PhilmoreSMS::fromDB();
                        if ($sms === null) {
                            $db->prepare("UPDATE sms_campaigns SET status='failed' WHERE id=?")->execute([$campaignId]);
                            $msg     = 'SMS API not configured. Campaign saved as failed.';
                            $msgType = 'error';
                        } elseif (empty($phones)) {
                            $db->prepare("UPDATE sms_campaigns SET status='failed' WHERE id=?")->execute([$campaignId]);
                            $msg     = 'No recipients found. Campaign saved as failed.';
                            $msgType = 'error';
                        } else {
                            $recipientStr = implode(',', $phones);
                            $db->prepare("UPDATE sms_campaigns SET status='sending' WHERE id=?")->execute([$campaignId]);

                            $result = match ($route) {
                                'bulk'        => $sms->sendBulkSMS($senderID, $recipientStr, $message),
                                'corporate'   => $sms->sendCorporateSMS($senderID, $recipientStr, $message),
                                'global'      => $sms->sendGlobalSMS($senderID, $recipientStr, $message),
                                'voice'       => $sms->sendVoiceSMS($callerID, $recipientStr, $message),
                                'voice_audio' => $sms->sendVoiceAudio($callerID, $recipientStr, $audioUrl),
                                default       => ['success' => false, 'message' => 'Unknown route'],
                            };

                            if (!empty($result['success'])) {
                                $sentCount   = $totalRecipients;
                                $failedCount = 0;
                                $finalStatus = 'sent';
                            } else {
                                $sentCount   = 0;
                                $failedCount = $totalRecipients;
                                $finalStatus = 'failed';
                            }

                            $db->prepare(
                                "UPDATE sms_campaigns SET status=?, sent_count=?, failed_count=?, sent_at=NOW() WHERE id=?"
                            )->execute([$finalStatus, $sentCount, $failedCount, $campaignId]);

                            if ($finalStatus === 'sent') {
                                $msg     = "Campaign sent to {$sentCount} recipient(s).";
                                $msgType = 'success';
                            } else {
                                $errDetail = $result['message'] ?? 'Unknown error';
                                $msg       = "Campaign failed to send: {$errDetail}";
                                $msgType   = 'error';
                            }
                        }
                    } elseif ($scheduleType === 'later') {
                        $stmt = $db->prepare(
                            "INSERT INTO scheduled_jobs (job_type, campaign_id, scheduled_at, timezone, status, created_at)
                             VALUES ('sms_campaign', ?, ?, ?, 'pending', NOW())"
                        );
                        $stmt->execute([$campaignId, $scheduledTs, $timezone]);
                        $msg     = 'Campaign scheduled successfully.';
                        $msgType = 'success';
                    } else {
                        $msg     = 'Campaign created as draft.';
                        $msgType = 'success';
                    }
                } catch (\Exception $e) {
                    error_log('sms create_campaign error: ' . $e->getMessage());
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
                $db->prepare("DELETE FROM sms_campaigns WHERE id = ?")->execute([$id]);
                $msg     = 'Campaign deleted.';
                $msgType = 'success';
            } catch (\Exception $e) {
                error_log('sms delete_campaign error: ' . $e->getMessage());
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
                $cmpStmt = $db->prepare("SELECT * FROM sms_campaigns WHERE id = ?");
                $cmpStmt->execute([$id]);
                $campaign = $cmpStmt->fetch();

                if (!$campaign) {
                    $msg     = 'Campaign not found.';
                    $msgType = 'error';
                } else {
                    $sms = PhilmoreSMS::fromDB();
                    if ($sms === null) {
                        $msg     = 'SMS API not configured.';
                        $msgType = 'error';
                    } else {
                        $groupId = (int)($campaign['group_id'] ?? 0);
                        if ($groupId > 0) {
                            $cStmt = $db->prepare(
                                "SELECT phone FROM sms_contacts WHERE group_id = ? AND is_subscribed = 1"
                            );
                            $cStmt->execute([$groupId]);
                            $phones = $cStmt->fetchAll(\PDO::FETCH_COLUMN);
                        } else {
                            $phones = $db->query(
                                "SELECT phone FROM sms_contacts WHERE is_subscribed = 1"
                            )->fetchAll(\PDO::FETCH_COLUMN);
                        }

                        if (empty($phones)) {
                            $msg     = 'No recipients found for this campaign.';
                            $msgType = 'error';
                        } else {
                            $recipientStr = implode(',', $phones);
                            $dbRoute      = $campaign['route'];
                            $senderOrCaller = $campaign['sender_id'];
                            $msgText      = $campaign['message'];

                            $db->prepare("UPDATE sms_campaigns SET status='sending' WHERE id=?")->execute([$id]);

                            // voice_audio campaigns are stored with route='voice' and message=audioUrl;
                            // detect by checking if the message is a URL.
                            $isVoiceAudio = ($dbRoute === 'voice' && filter_var($msgText, FILTER_VALIDATE_URL) !== false);

                            $result = match (true) {
                                $dbRoute === 'bulk'      => $sms->sendBulkSMS($senderOrCaller, $recipientStr, $msgText),
                                $dbRoute === 'corporate' => $sms->sendCorporateSMS($senderOrCaller, $recipientStr, $msgText),
                                $dbRoute === 'global'    => $sms->sendGlobalSMS($senderOrCaller, $recipientStr, $msgText),
                                $isVoiceAudio            => $sms->sendVoiceAudio($senderOrCaller, $recipientStr, $msgText),
                                $dbRoute === 'voice'     => $sms->sendVoiceSMS($senderOrCaller, $recipientStr, $msgText),
                                default                  => ['success' => false, 'message' => 'Unknown route'],
                            };

                            $total = count($phones);
                            if (!empty($result['success'])) {
                                $db->prepare(
                                    "UPDATE sms_campaigns SET status='sent', sent_count=?, failed_count=0, sent_at=NOW() WHERE id=?"
                                )->execute([$total, $id]);
                                $msg     = "Campaign sent to {$total} recipient(s).";
                                $msgType = 'success';
                            } else {
                                $db->prepare(
                                    "UPDATE sms_campaigns SET status='failed', sent_count=0, failed_count=?, sent_at=NOW() WHERE id=?"
                                )->execute([$total, $id]);
                                $errDetail = $result['message'] ?? 'Unknown error';
                                $msg       = "Campaign failed: {$errDetail}";
                                $msgType   = 'error';
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log('sms send_campaign error: ' . $e->getMessage());
                $msg     = 'Failed to send campaign.';
                $msgType = 'error';
            }
        }
    }

    // ── Add Sender ID ─────────────────────────────────────────────────────
    elseif ($action === 'add_sender_id') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $senderID      = sanitize($_POST['sender_id'] ?? '');
            $sampleMessage = trim($_POST['sample_message'] ?? '');

            if ($senderID === '') {
                $msg     = 'Sender ID is required.';
                $msgType = 'error';
            } elseif (!preg_match('/^[A-Za-z0-9]{1,11}$/', $senderID)) {
                $msg     = 'Sender ID must be alphanumeric and at most 11 characters.';
                $msgType = 'error';
            } elseif ($sampleMessage === '') {
                $msg     = 'Sample message is required.';
                $msgType = 'error';
            } else {
                try {
                    $sms = PhilmoreSMS::fromDB();
                    if ($sms === null) {
                        $msg     = 'SMS API not configured.';
                        $msgType = 'error';
                    } else {
                        $result = $sms->submitSenderID($senderID, $sampleMessage);

                        $stmt = $db->prepare(
                            "INSERT INTO sms_sender_ids (sender_id, sample_message, status, submitted_at)
                             VALUES (?, ?, 'pending', NOW())
                             ON DUPLICATE KEY UPDATE sample_message = VALUES(sample_message), status = 'pending', submitted_at = NOW()"
                        );
                        $stmt->execute([$senderID, $sampleMessage]);

                        if (!empty($result['success'])) {
                            $msg     = 'Sender ID submitted successfully and is pending approval.';
                            $msgType = 'success';
                        } else {
                            $errDetail = $result['message'] ?? 'Unknown error';
                            $msg       = "Sender ID saved, but API submission failed: {$errDetail}";
                            $msgType   = 'warning';
                        }
                    }
                } catch (\Exception $e) {
                    error_log('sms add_sender_id error: ' . $e->getMessage());
                    $msg     = 'Failed to submit Sender ID.';
                    $msgType = 'error';
                }
            }
        }
    }

    // ── Delete Sender ID ──────────────────────────────────────────────────
    elseif ($action === 'delete_sender_id') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $db->prepare("DELETE FROM sms_sender_ids WHERE id = ?")->execute([$id]);
                $msg     = 'Sender ID deleted.';
                $msgType = 'success';
            } catch (\Exception $e) {
                error_log('sms delete_sender_id error: ' . $e->getMessage());
                $msg     = 'Failed to delete Sender ID.';
                $msgType = 'error';
            }
        }
    }

    // ── Add Caller ID ─────────────────────────────────────────────────────
    elseif ($action === 'add_caller_id') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $callerID = sanitize($_POST['caller_id'] ?? '');

            if ($callerID === '') {
                $msg     = 'Caller ID is required.';
                $msgType = 'error';
            } elseif (!preg_match('/^\+?[0-9\s\-]{7,20}$/', $callerID)) {
                $msg     = 'Caller ID must be a valid phone number.';
                $msgType = 'error';
            } else {
                try {
                    $sms = PhilmoreSMS::fromDB();
                    if ($sms === null) {
                        $msg     = 'SMS API not configured.';
                        $msgType = 'error';
                    } else {
                        $result = $sms->submitCallerID($callerID);

                        $stmt = $db->prepare(
                            "INSERT INTO sms_caller_ids (caller_id, status, submitted_at)
                             VALUES (?, 'pending', NOW())
                             ON DUPLICATE KEY UPDATE status = 'pending', submitted_at = NOW()"
                        );
                        $stmt->execute([$callerID]);

                        if (!empty($result['success'])) {
                            $msg     = 'Caller ID submitted successfully and is pending approval.';
                            $msgType = 'success';
                        } else {
                            $errDetail = $result['message'] ?? 'Unknown error';
                            $msg       = "Caller ID saved, but API submission failed: {$errDetail}";
                            $msgType   = 'warning';
                        }
                    }
                } catch (\Exception $e) {
                    error_log('sms add_caller_id error: ' . $e->getMessage());
                    $msg     = 'Failed to submit Caller ID.';
                    $msgType = 'error';
                }
            }
        }
    }

    // ── Delete Caller ID ──────────────────────────────────────────────────
    elseif ($action === 'delete_caller_id') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrf($token)) {
            $msg     = 'Invalid security token.';
            $msgType = 'error';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $db->prepare("DELETE FROM sms_caller_ids WHERE id = ?")->execute([$id]);
                $msg     = 'Caller ID deleted.';
                $msgType = 'success';
            } catch (\Exception $e) {
                error_log('sms delete_caller_id error: ' . $e->getMessage());
                $msg     = 'Failed to delete Caller ID.';
                $msgType = 'error';
            }
        }
    }
}

// ─── DATA FOR PAGE ───────────────────────────────────────────────────────────

$campaigns = [];
try {
    $campaigns = $db->query("SELECT * FROM sms_campaigns ORDER BY created_at DESC")->fetchAll();
} catch (\Exception $e) {
    error_log('sms fetch campaigns error: ' . $e->getMessage());
}

$groups = [];
try {
    $groups = $db->query("SELECT id, name FROM sms_groups ORDER BY name ASC")->fetchAll();
} catch (\Exception $e) {
    error_log('sms fetch groups error: ' . $e->getMessage());
}

$senderIDs = [];
try {
    $senderIDs = $db->query("SELECT * FROM sms_sender_ids ORDER BY submitted_at DESC")->fetchAll();
} catch (\Exception $e) {
    error_log('sms fetch sender_ids error: ' . $e->getMessage());
}

$callerIDs = [];
try {
    $callerIDs = $db->query("SELECT * FROM sms_caller_ids ORDER BY submitted_at DESC")->fetchAll();
} catch (\Exception $e) {
    error_log('sms fetch caller_ids error: ' . $e->getMessage());
}

$approvedSenderIDs = array_filter($senderIDs, fn($s) => $s['status'] === 'approved');
$approvedCallerIDs = array_filter($callerIDs, fn($c) => $c['status'] === 'approved');

$csrfToken  = csrfToken();
$pageTitle  = 'SMS Campaigns';
$activePage = 'sms';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div class="page-header-row">
        <h1>💬 SMS Campaigns</h1>
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
    <button class="tab-btn" data-tab="sender-ids">Sender IDs</button>
    <button class="tab-btn" data-tab="caller-ids">Caller IDs</button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Campaigns
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-campaigns">
    <div class="card">
        <div class="card-header">
            <h3>All Campaigns</h3>
        </div>
        <div class="card-body">
            <?php if (empty($campaigns)): ?>
            <p class="empty-state">No campaigns yet. <a href="#new-campaign" class="tab-link" data-tab="new-campaign">Create your first campaign</a>.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Sender / Caller ID</th>
                        <th>Route</th>
                        <th>Status</th>
                        <th>Recipients</th>
                        <th>Sent / Failed</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['sender_id']) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($c['route']) ?>">
                            <?= htmlspecialchars($c['route']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($c['status']) ?>">
                            <?= htmlspecialchars($c['status']) ?>
                        </span>
                    </td>
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
<div class="tab-content" id="tab-new-campaign" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h3>Create New SMS Campaign</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="smsCampaignForm">
                <input type="hidden" name="action" value="create_campaign">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="sms_name">Campaign Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="sms_name" name="name" class="form-control" required placeholder="e.g. July Promo Blast">
                </div>

                <div class="form-group">
                    <label class="form-label" for="sms_route">Route <span style="color:var(--danger)">*</span></label>
                    <select id="sms_route" name="route" class="form-control" required onchange="onRouteChange(this.value)">
                        <option value="">— Select a route —</option>
                        <option value="bulk">Bulk</option>
                        <option value="corporate">Corporate</option>
                        <option value="global">Global</option>
                        <option value="voice">Voice (TTS)</option>
                        <option value="voice_audio">Voice Audio</option>
                    </select>
                    <span class="form-text">Choose how the SMS will be delivered.</span>
                </div>

                <!-- Sender ID (bulk / corporate / global) -->
                <div class="form-group" id="senderIdWrap">
                    <label class="form-label" for="sms_sender_id">Sender ID <span style="color:var(--danger)">*</span></label>
                    <?php if (empty($approvedSenderIDs)): ?>
                    <p class="form-text" style="color:var(--warning);">⚠ No approved Sender IDs. <a href="#sender-ids" class="tab-link" data-tab="sender-ids">Submit one</a> first.</p>
                    <input type="hidden" name="sender_id" value="">
                    <?php else: ?>
                    <select id="sms_sender_id" name="sender_id" class="form-control">
                        <option value="">— Select Sender ID —</option>
                        <?php foreach ($approvedSenderIDs as $sid): ?>
                        <option value="<?= htmlspecialchars($sid['sender_id']) ?>"><?= htmlspecialchars($sid['sender_id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- Caller ID (voice / voice_audio) -->
                <div class="form-group" id="callerIdWrap" style="display:none;">
                    <label class="form-label" for="sms_caller_id">Caller ID <span style="color:var(--danger)">*</span></label>
                    <?php if (empty($approvedCallerIDs)): ?>
                    <p class="form-text" style="color:var(--warning);">⚠ No approved Caller IDs. <a href="#caller-ids" class="tab-link" data-tab="caller-ids">Submit one</a> first.</p>
                    <input type="hidden" name="caller_id" value="">
                    <?php else: ?>
                    <select id="sms_caller_id" name="caller_id" class="form-control">
                        <option value="">— Select Caller ID —</option>
                        <?php foreach ($approvedCallerIDs as $cid): ?>
                        <option value="<?= htmlspecialchars($cid['caller_id']) ?>"><?= htmlspecialchars($cid['caller_id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- Contact Group -->
                <div class="form-group">
                    <label class="form-label" for="sms_group">Contact Group</label>
                    <select id="sms_group" name="group_id" class="form-control" onchange="onGroupChange(this.value)">
                        <option value="0">— Paste custom numbers below —</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Custom Recipients (shown when group = 0) -->
                <div class="form-group" id="customRecipientsWrap">
                    <label class="form-label" for="sms_custom_recipients">Custom Recipients</label>
                    <textarea id="sms_custom_recipients" name="custom_recipients" class="form-control" rows="3"
                              placeholder="Comma-separated phone numbers, e.g. +2348012345678, +2347098765432"></textarea>
                    <span class="form-text">Enter phone numbers separated by commas.</span>
                </div>

                <!-- Message (all routes except voice_audio) -->
                <div class="form-group" id="messageWrap">
                    <label class="form-label" for="sms_message">Message <span style="color:var(--danger)">*</span></label>
                    <textarea id="sms_message" name="message" class="form-control" rows="4"
                              placeholder="Type your SMS message here..." oninput="updateSmsCounter(this)"></textarea>
                    <span class="form-text" id="smsCounter">0 characters — 0 SMS</span>
                </div>

                <!-- Audio URL (voice_audio only) -->
                <div class="form-group" id="audioUrlWrap" style="display:none;">
                    <label class="form-label" for="sms_audio_url">Audio URL <span style="color:var(--danger)">*</span></label>
                    <input type="url" id="sms_audio_url" name="audio_url" class="form-control"
                           placeholder="https://example.com/audio.mp3">
                    <span class="form-text">Public URL to the audio file for voice broadcast.</span>
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
                            <label class="form-label" for="sms_scheduled_at">Scheduled Date &amp; Time</label>
                            <input type="datetime-local" id="sms_scheduled_at" name="scheduled_at" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="sms_timezone">Timezone</label>
                            <select id="sms_timezone" name="timezone" class="form-control">
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
     TAB: Sender IDs
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-sender-ids" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h3>Sender IDs</h3>
            <button class="btn btn-primary btn-sm" data-modal="modal-add-sender-id">+ Add Sender ID</button>
        </div>
        <div class="card-body">
            <?php if (empty($senderIDs)): ?>
            <p class="empty-state">No sender IDs yet. Add one to start sending campaigns.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Sender ID</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($senderIDs as $sid): ?>
                <tr>
                    <td><?= htmlspecialchars($sid['sender_id']) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($sid['status']) ?>">
                            <?= htmlspecialchars($sid['status']) ?>
                        </span>
                    </td>
                    <td><?= timeAgo($sid['submitted_at']) ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this Sender ID?');">
                            <input type="hidden" name="action" value="delete_sender_id">
                            <input type="hidden" name="id" value="<?= (int)$sid['id'] ?>">
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
     TAB: Caller IDs
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-caller-ids" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h3>Caller IDs</h3>
            <button class="btn btn-primary btn-sm" data-modal="modal-add-caller-id">+ Add Caller ID</button>
        </div>
        <div class="card-body">
            <?php if (empty($callerIDs)): ?>
            <p class="empty-state">No caller IDs yet. Add one to start voice campaigns.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Caller ID</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($callerIDs as $cid): ?>
                <tr>
                    <td><?= htmlspecialchars($cid['caller_id']) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($cid['status']) ?>">
                            <?= htmlspecialchars($cid['status']) ?>
                        </span>
                    </td>
                    <td><?= timeAgo($cid['submitted_at']) ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete this Caller ID?');">
                            <input type="hidden" name="action" value="delete_caller_id">
                            <input type="hidden" name="id" value="<?= (int)$cid['id'] ?>">
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
     MODAL: Add Sender ID
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-add-sender-id">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Sender ID</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_sender_id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="new_sender_id">Sender ID <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="new_sender_id" name="sender_id" class="form-control" required
                           maxlength="11" placeholder="e.g. MyBrand">
                    <span class="form-text">Alphanumeric, max 11 characters.</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_sample_message">Sample Message <span style="color:var(--danger)">*</span></label>
                    <textarea id="new_sample_message" name="sample_message" class="form-control" rows="4" required
                              placeholder="Enter a sample message that will be sent using this Sender ID."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit Sender ID</button>
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: Add Caller ID
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-add-caller-id">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Caller ID</h3>
            <button class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_caller_id">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="new_caller_id">Caller ID <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="new_caller_id" name="caller_id" class="form-control" required
                           placeholder="+2348012345678">
                    <span class="form-text">Enter a valid phone number in international format.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit Caller ID</button>
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle Sender ID vs Caller ID fields and message vs audio URL based on route
function onRouteChange(route) {
    var senderWrap  = document.getElementById('senderIdWrap');
    var callerWrap  = document.getElementById('callerIdWrap');
    var messageWrap = document.getElementById('messageWrap');
    var audioWrap   = document.getElementById('audioUrlWrap');

    var isVoice      = (route === 'voice' || route === 'voice_audio');
    var isVoiceAudio = (route === 'voice_audio');

    if (senderWrap)  senderWrap.style.display  = isVoice ? 'none' : 'block';
    if (callerWrap)  callerWrap.style.display  = isVoice ? 'block' : 'none';
    if (messageWrap) messageWrap.style.display = isVoiceAudio ? 'none' : 'block';
    if (audioWrap)   audioWrap.style.display   = isVoiceAudio ? 'block' : 'none';
}

// Show / hide custom recipients textarea
function onGroupChange(val) {
    var wrap = document.getElementById('customRecipientsWrap');
    if (!wrap) return;
    wrap.style.display = (val === '0') ? 'block' : 'none';
}

// SMS character counter
function updateSmsCounter(textarea) {
    var counter = document.getElementById('smsCounter');
    if (!counter) return;
    var len  = textarea.value.length;
    var sms  = 1;
    if (len > 160) {
        sms = Math.ceil((len - 160) / 153) + 1;
    }
    counter.textContent = len + ' character' + (len !== 1 ? 's' : '') + ' — ' + sms + ' SMS';
}

// Toggle schedule datetime fields
function toggleScheduleFields(val) {
    var fields = document.getElementById('scheduleFields');
    if (!fields) return;
    fields.style.display = (val === 'later') ? 'block' : 'none';
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
