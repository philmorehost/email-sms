<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

setSecurityHeaders();
requireAuth();

$db   = getDB();
$user = getCurrentUser();
$uid  = (int)($user['id'] ?? 0);

AyrshareClient::migrate($db);

$settings = AyrshareClient::loadSettings($db);
$enabled  = ($settings['social_enabled'] ?? '0') === '1';

// ── Flash helpers ─────────────────────────────────────────────────────────────
function setFlash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['soc_user_flash'] = $msg;
    $_SESSION['soc_user_type']  = $type;
}
function popFlash(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $msg  = $_SESSION['soc_user_flash'] ?? '';
    $type = $_SESSION['soc_user_type']  ?? 'success';
    unset($_SESSION['soc_user_flash'], $_SESSION['soc_user_type']);
    return ['msg' => $msg, 'type' => $type];
}

$msg     = '';
$msgType = 'success';

// ── POST: save draft campaign ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enabled) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('Invalid security token.', 'error');
        header('Location: /user/social.php?tab=create');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_draft') {
        $caption   = trim($_POST['caption'] ?? '');
        $hashtags  = trim($_POST['hashtags'] ?? '');
        $imageUrl  = trim($_POST['image_url'] ?? '');
        $platforms = implode(',', array_filter(array_map('trim', (array)($_POST['platforms'] ?? []))));

        if ($caption === '') {
            setFlash('Caption cannot be empty.', 'error');
            header('Location: /user/social.php?tab=create');
            exit;
        }

        $db->prepare(
            "INSERT INTO social_campaigns (user_id,platform_mask,caption,hashtags,image_url,status,created_at)
             VALUES (?,?,?,?,?,'draft',NOW())"
        )->execute([$uid, $platforms, $caption, $hashtags, $imageUrl]);
        $cid = (int)$db->lastInsertId();
        setFlash('Draft saved! You can schedule or publish it from the Campaigns tab.');
        header('Location: /user/social.php?tab=campaigns');
        exit;
    }

    if ($action === 'delete_campaign') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        $db->prepare("DELETE FROM social_campaigns WHERE id=? AND user_id=? AND status IN ('draft','failed')")
           ->execute([$cid, $uid]);
        setFlash('Campaign deleted.');
        header('Location: /user/social.php?tab=campaigns');
        exit;
    }

    header('Location: /user/social.php');
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'create';
$flash     = popFlash();

// Social token balance
$socBalance = 0;
try {
    $bStmt = $db->prepare("SELECT balance FROM user_social_tokens WHERE user_id=?");
    $bStmt->execute([$uid]);
    $socBalance = (int)($bStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

// AI token balance
$aiBalance = 0;
try {
    $abStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $abStmt->execute([$uid]);
    $aiBalance = (int)($abStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

// Connected platforms
$connection = null;
$connectedPlatforms = [];
try {
    $cStmt = $db->prepare("SELECT * FROM social_connections WHERE user_id=?");
    $cStmt->execute([$uid]);
    $connection = $cStmt->fetch();
    if ($connection && $connection['platforms_json']) {
        $connectedPlatforms = json_decode($connection['platforms_json'], true) ?: [];
    }
} catch (\Exception $e) {}

// User campaigns
$campaigns = [];
try {
    $campStmt = $db->prepare("SELECT * FROM social_campaigns WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $campStmt->execute([$uid]);
    $campaigns = $campStmt->fetchAll();
} catch (\Exception $e) {}

$draftCount     = count(array_filter($campaigns, fn($c) => $c['status'] === 'draft'));
$scheduledCount = count(array_filter($campaigns, fn($c) => $c['status'] === 'scheduled'));
$postedCount    = count(array_filter($campaigns, fn($c) => $c['status'] === 'posted'));

$csrfToken = csrfToken();

$tokenCostNow       = max(1, (int)($settings['social_tokens_per_post_now'] ?? 1));
$tokenCostScheduled = max(1, (int)($settings['social_tokens_per_scheduled_post'] ?? 5));
$tokenCostVariant   = max(1, (int)($settings['social_tokens_per_ab_variant'] ?? 2));

$hasDeepSeek = trim($settings['deepseek_api_key'] ?? '') !== '';

$pageTitle  = 'Social Media Marketing';
$activePage = 'social';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<!-- Page header with balance chips -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <div>
        <h1 class="page-title">📱 Social Media Marketing</h1>
        <p style="color:var(--text-muted);margin:0">AI-powered posting to Facebook, Instagram, LinkedIn, Twitter/X, TikTok and more.</p>
    </div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <a href="/billing.php?tab=social_tokens" style="display:flex;align-items:center;gap:.4rem;padding:.4rem .9rem;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);border-radius:10px;text-decoration:none;color:#34d399;font-size:.85rem">
            <span>📱</span><span><?= number_format($socBalance) ?> Social Tokens</span>
        </a>
        <a href="/billing.php?tab=ai_tokens" style="display:flex;align-items:center;gap:.4rem;padding:.4rem .9rem;background:rgba(108,99,255,.12);border:1px solid rgba(108,99,255,.3);border-radius:10px;text-decoration:none;color:#a78bfa;font-size:.85rem">
            <span>🤖</span><span><?= number_format($aiBalance) ?> AI Tokens</span>
        </a>
    </div>
</div>

<?php if (!$enabled): ?>
<div class="alert alert-warning" style="padding:2rem;text-align:center;border-radius:12px">
    <h3 style="margin:0 0 .5rem">📱 Social Media Marketing coming soon</h3>
    <p style="margin:0;color:var(--text-muted)">This feature is not yet enabled. Please contact the administrator.</p>
</div>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; exit; ?>
<?php endif; ?>

<?php if ($flash['msg']): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:1.5rem">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- Tab navigation -->
<div class="soc-tabs" style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;overflow-x:auto;padding-bottom:.25rem">
    <a href="?tab=create"    class="tab-btn <?= $activeTab === 'create'    ? 'active' : '' ?>">✏️ Create Post</a>
    <a href="?tab=ai"        class="tab-btn <?= $activeTab === 'ai'        ? 'active' : '' ?>">🤖 AI Writer</a>
    <a href="?tab=scheduler" class="tab-btn <?= $activeTab === 'scheduler' ? 'active' : '' ?>">🕐 Scheduler</a>
    <a href="?tab=campaigns" class="tab-btn <?= $activeTab === 'campaigns' ? 'active' : '' ?>">
        📋 Campaigns
        <?php if ($scheduledCount > 0): ?>
        <span style="background:#f59e0b;color:#000;border-radius:10px;padding:1px 7px;font-size:.75rem;margin-left:.3rem"><?= $scheduledCount ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=connect"   class="tab-btn <?= $activeTab === 'connect'   ? 'active' : '' ?>">
        🔗 Connect Socials
        <?php if ($connection): ?>
        <span style="background:#10b981;color:#fff;border-radius:10px;padding:1px 7px;font-size:.75rem;margin-left:.3rem">✓</span>
        <?php endif; ?>
    </a>
</div>

<!-- ── TAB 1: CREATE POST ──────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'create' ? 'active' : '' ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start" class="soc-grid">

    <div class="card">
        <div class="card-header"><h3>✏️ Compose Post</h3></div>
        <div class="card-body">
            <form method="POST" id="createForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_draft">

                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Platforms</label>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                        <?php
                        $allPlatforms = ['facebook'=>'Facebook','instagram'=>'Instagram','linkedin'=>'LinkedIn','twitter'=>'Twitter/X','tiktok'=>'TikTok','pinterest'=>'Pinterest','youtube'=>'YouTube'];
                        foreach ($allPlatforms as $slug => $label):
                            $icon = ['facebook'=>'📘','instagram'=>'📸','linkedin'=>'💼','twitter'=>'🐦','tiktok'=>'🎵','pinterest'=>'📌','youtube'=>'▶️'][$slug] ?? '📱';
                        ?>
                        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;padding:.3rem .7rem;border:1px solid var(--glass-border);border-radius:8px;font-size:.85rem">
                            <input type="checkbox" name="platforms[]" value="<?= $slug ?>"
                                <?= in_array($slug, $connectedPlatforms, true) ? 'checked' : '' ?>>
                            <?= $icon ?> <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Caption</label>
                    <textarea name="caption" id="captionArea" class="form-control" rows="6"
                              placeholder="Write your post caption here…" maxlength="63206"></textarea>
                    <small id="charCount" style="color:var(--text-muted)">0 chars</small>
                </div>

                <div class="form-group" style="margin-bottom:1rem">
                    <label class="form-label">Hashtags</label>
                    <input type="text" name="hashtags" id="hashtagsField" class="form-control"
                           placeholder="#marketing #2026 #brand">
                </div>

                <div class="form-group" style="margin-bottom:1.5rem">
                    <label class="form-label">Image URL <span style="color:var(--text-muted)">(optional)</span></label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://…/image.jpg">
                </div>

                <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                    <button type="submit" class="btn btn-secondary">💾 Save as Draft</button>
                    <button type="button" class="btn btn-primary" id="postNowBtn" title="Requires <?= $tokenCostNow ?> social token(s)">
                        🚀 Post Now (<?= $tokenCostNow ?> token<?= $tokenCostNow > 1 ? 's' : '' ?>)
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div>
        <!-- Post preview -->
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header"><h3>👁️ Preview</h3></div>
            <div class="card-body">
                <div id="preview" style="background:rgba(255,255,255,.04);border:1px solid var(--glass-border);border-radius:10px;padding:1rem;min-height:6rem;white-space:pre-wrap;font-size:.9rem;color:var(--text-primary)">
                    Your post preview will appear here…
                </div>
            </div>
        </div>

        <!-- Quick-use from AI -->
        <div class="card" id="aiApplyCard" style="display:none">
            <div class="card-header"><h3>🤖 AI-Generated Caption</h3></div>
            <div class="card-body">
                <p id="aiAppliedCaption" style="font-size:.9rem;color:var(--text-primary);white-space:pre-wrap"></p>
                <p id="aiAppliedHashtags" style="font-size:.85rem;color:#a78bfa"></p>
                <button class="btn btn-sm btn-primary" id="useAiCaption">✅ Use This Caption</button>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ── TAB 2: AI WRITER ────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'ai' ? 'active' : '' ?>">
<?php if (!$hasDeepSeek): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:3rem">
        <div style="font-size:3rem;margin-bottom:1rem">🤖</div>
        <h3>AI Writer Not Available</h3>
        <p style="color:var(--text-muted)">The AI API key is not configured. Contact your administrator to enable AI-powered social content generation.</p>
        <a href="/billing.php?tab=ai_tokens" class="btn btn-primary" style="margin-top:1rem">Browse AI Plans</a>
    </div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start" class="soc-grid">

    <div class="card">
        <div class="card-header"><h3>🤖 AI Social Content Agent</h3></div>
        <div class="card-body">
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Campaign Goal</label>
                <textarea id="aiGoal" class="form-control" rows="3" maxlength="500"
                          placeholder="e.g. Promote my new PHP e-commerce script for freelancers"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Target Audience <span style="color:var(--text-muted)">(optional)</span></label>
                <input type="text" id="aiAudience" class="form-control" placeholder="e.g. Freelance developers aged 25-40">
            </div>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Brand Name <span style="color:var(--text-muted)">(optional)</span></label>
                <input type="text" id="aiBrand" class="form-control" placeholder="e.g. PhilmoreHost">
            </div>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Platforms</label>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                    <?php foreach ($allPlatforms as $slug => $label):
                        $icon = ['facebook'=>'📘','instagram'=>'📸','linkedin'=>'💼','twitter'=>'🐦','tiktok'=>'🎵','pinterest'=>'📌','youtube'=>'▶️'][$slug] ?? '📱';
                    ?>
                    <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;padding:.3rem .7rem;border:1px solid var(--glass-border);border-radius:8px;font-size:.85rem">
                        <input type="checkbox" class="aiPlatformCheck" value="<?= $slug ?>"
                               <?= in_array($slug, ['facebook','instagram','linkedin'], true) ? 'checked' : '' ?>>
                        <?= $icon ?> <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div class="form-group">
                    <label class="form-label">Tone</label>
                    <select id="aiTone" class="form-control">
                        <option value="casual">Casual</option>
                        <option value="professional">Professional</option>
                        <option value="trendy">Trendy</option>
                        <option value="punchy">Punchy</option>
                        <option value="educational">Educational</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">A/B Variants</label>
                    <select id="aiVariants" class="form-control">
                        <option value="1">1 variant</option>
                        <option value="2">2 variants (+<?= $tokenCostVariant ?> social token each)</option>
                        <option value="3">3 variants</option>
                        <option value="4">4 variants</option>
                        <option value="5">5 variants</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary" id="aiGenerateBtn" style="width:100%">
                🤖 Generate Content (<?= (int)($settings['ai_tokens_per_generation'] ?? 50) ?> AI tokens)
            </button>
            <div id="aiSpinner" style="display:none;text-align:center;padding:1.5rem;color:var(--text-muted)">
                ⏳ AI is crafting your posts…
            </div>
        </div>
    </div>

    <div>
        <div class="card" id="aiResultsCard" style="display:none">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
                <h3>✨ Generated Variants</h3>
                <div id="variantTabs" style="display:flex;gap:.3rem"></div>
            </div>
            <div class="card-body" id="aiResultsBody">
            </div>
        </div>
        <div class="card" id="aiEmptyCard">
            <div class="card-body" style="text-align:center;padding:3rem;color:var(--text-muted)">
                <div style="font-size:3rem;margin-bottom:.5rem">✨</div>
                <p>Your AI-generated captions and hashtags will appear here.</p>
            </div>
        </div>
    </div>

</div>
<?php endif; ?>
</div>

<!-- ── TAB 3: SCHEDULER ────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'scheduler' ? 'active' : '' ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start" class="soc-grid">

    <div class="card">
        <div class="card-header"><h3>🕐 Schedule a Post</h3></div>
        <div class="card-body">
            <?php $draftCampaigns = array_filter($campaigns, fn($c) => in_array($c['status'], ['draft','failed'], true)); ?>
            <?php if (empty($draftCampaigns)): ?>
            <p style="color:var(--text-muted);text-align:center;padding:2rem">
                No draft campaigns. <a href="?tab=create">Create one first</a>.
            </p>
            <?php else: ?>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Select Draft Campaign</label>
                <select id="schedCampaignId" class="form-control">
                    <option value="">-- Choose --</option>
                    <?php foreach ($draftCampaigns as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                            data-platforms="<?= htmlspecialchars($c['platform_mask']) ?>">
                        #<?= (int)$c['id'] ?> — <?= htmlspecialchars(mb_substr($c['caption'] ?? '', 0, 60)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Schedule Date &amp; Time (UTC)</label>
                <input type="datetime-local" id="schedDateTime" class="form-control">
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
                <button class="btn btn-secondary" id="bestTimeBtn">🧠 Smart Suggest</button>
                <button class="btn btn-primary" id="schedulePostBtn">
                    📅 Schedule (<?= $tokenCostScheduled ?> token<?= $tokenCostScheduled > 1 ? 's' : '' ?>)
                </button>
            </div>
            <div id="schedResult" style="display:none"></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" id="bestTimesCard" style="display:none">
        <div class="card-header"><h3>🧠 Smart Time Suggestions</h3></div>
        <div class="card-body" id="bestTimesBody">
            <p style="color:var(--text-muted)">Loading recommendations…</p>
        </div>
    </div>

</div>
</div>

<!-- ── TAB 4: CAMPAIGNS ────────────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'campaigns' ? 'active' : '' ?>">

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem" class="soc-stats-grid">
    <div class="card" style="text-align:center;padding:1rem">
        <div style="font-size:2rem;font-weight:700;color:var(--accent)"><?= $draftCount ?></div>
        <div style="color:var(--text-muted);font-size:.85rem">Draft</div>
    </div>
    <div class="card" style="text-align:center;padding:1rem">
        <div style="font-size:2rem;font-weight:700;color:#f59e0b"><?= $scheduledCount ?></div>
        <div style="color:var(--text-muted);font-size:.85rem">Scheduled</div>
    </div>
    <div class="card" style="text-align:center;padding:1rem">
        <div style="font-size:2rem;font-weight:700;color:#10b981"><?= $postedCount ?></div>
        <div style="color:var(--text-muted);font-size:.85rem">Posted</div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3>📋 My Campaigns</h3>
        <a href="?tab=create" class="btn btn-sm btn-primary">+ New Campaign</a>
    </div>
    <?php if (empty($campaigns)): ?>
    <div class="card-body" style="text-align:center;color:var(--text-muted);padding:3rem">
        <div style="font-size:3rem;margin-bottom:.5rem">📋</div>
        No campaigns yet. <a href="?tab=create">Create your first post</a>.
    </div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead><tr><th>#</th><th>Platforms</th><th>Caption</th><th>Status</th><th>Scheduled</th><th>Posted</th><th></th></tr></thead>
        <tbody>
        <?php
        $statusBadge = [
            'draft'     => ['#aaa',    '📝 Draft'],
            'scheduled' => ['#f59e0b', '📅 Scheduled'],
            'posting'   => ['#6c63ff', '⏳ Posting'],
            'posted'    => ['#10b981', '✅ Posted'],
            'failed'    => ['#ef4444', '❌ Failed'],
        ];
        foreach ($campaigns as $c):
            [$sc, $sl] = $statusBadge[$c['status']] ?? ['#aaa', $c['status']];
        ?>
        <tr>
            <td style="font-size:.82rem">#<?= (int)$c['id'] ?></td>
            <td style="font-size:.8rem"><?= htmlspecialchars(str_replace(',', ', ', $c['platform_mask'])) ?></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem">
                <?= htmlspecialchars(mb_substr($c['caption'] ?? '', 0, 80)) ?>
            </td>
            <td>
                <span style="background:<?= $sc ?>22;color:<?= $sc ?>;padding:2px 8px;border-radius:6px;font-size:.8rem"><?= $sl ?></span>
            </td>
            <td style="font-size:.82rem"><?= $c['scheduled_at'] ? htmlspecialchars(substr($c['scheduled_at'], 0, 16)) : '—' ?></td>
            <td style="font-size:.82rem"><?= $c['posted_at']    ? htmlspecialchars(substr($c['posted_at'], 0, 16))    : '—' ?></td>
            <td>
                <?php if (in_array($c['status'], ['draft','failed'], true)): ?>
                <div style="display:flex;gap:.4rem">
                    <button class="btn btn-sm btn-primary postNowRowBtn"
                            data-id="<?= (int)$c['id'] ?>"
                            title="Post Now (<?= $tokenCostNow ?> token<?= $tokenCostNow > 1 ? 's' : '' ?>)">🚀</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this draft?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_campaign">
                        <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">🗑</button>
                    </form>
                </div>
                <?php elseif ($c['status'] === 'posted' && $c['ayrshare_post_id']): ?>
                <a href="/user/social-analytics.php?campaign_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">📊</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ── TAB 5: CONNECT SOCIALS ─────────────────────────────────────────────── -->
<div class="tab-pane <?= $activeTab === 'connect' ? 'active' : '' ?>">
<div style="max-width:640px">

    <?php if (isset($_GET['linked'])): ?>
    <div class="alert alert-success" style="margin-bottom:1.5rem">✅ Social accounts linked! Your platforms are now synced.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3>🔗 Connected Social Accounts</h3></div>
        <div class="card-body">
            <?php if ($connection): ?>
            <div style="margin-bottom:1.5rem">
                <p style="color:var(--text-muted);margin-bottom:1rem">Your Ayrshare profile is active. Connected platforms:</p>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem">
                    <?php
                    $icons = ['facebook'=>'📘','instagram'=>'📸','linkedin'=>'💼','twitter'=>'🐦','tiktok'=>'🎵','pinterest'=>'📌','youtube'=>'▶️'];
                    if (!empty($connectedPlatforms)):
                        foreach ($connectedPlatforms as $p):
                            $p = is_array($p) ? ($p['network'] ?? $p['platform'] ?? json_encode($p)) : (string)$p;
                    ?>
                    <span style="display:flex;align-items:center;gap:.3rem;padding:.35rem .75rem;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);border-radius:10px;font-size:.85rem;color:#34d399">
                        <?= $icons[$p] ?? '📱' ?> <?= htmlspecialchars(ucfirst($p)) ?>
                    </span>
                    <?php endforeach;
                    else: ?>
                    <p style="color:var(--text-muted)">No platforms connected yet. Click "Add/Manage Socials" below.</p>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                    <button class="btn btn-primary" id="connectMoreBtn">➕ Add / Manage Socials</button>
                    <button class="btn btn-secondary" id="refreshStatusBtn">🔄 Refresh Status</button>
                    <button class="btn btn-danger" id="disconnectBtn">🔌 Disconnect All</button>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:2rem">
                <div style="font-size:4rem;margin-bottom:1rem">🔗</div>
                <h3 style="margin-bottom:.5rem">Connect Your Social Accounts</h3>
                <p style="color:var(--text-muted);margin-bottom:1.5rem">
                    Connect once and post to Facebook, Instagram, LinkedIn, Twitter/X, TikTok, Pinterest, and YouTube simultaneously.
                </p>
                <button class="btn btn-primary btn-lg" id="connectBtn">🔗 Connect Social Accounts</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Token pricing info -->
    <div class="card" style="margin-top:1.5rem">
        <div class="card-header"><h3>💡 Token Pricing</h3></div>
        <div class="card-body">
            <table class="table" style="margin:0">
                <tbody>
                    <tr><td>🚀 Post Now</td><td><strong><?= $tokenCostNow ?></strong> social token<?= $tokenCostNow > 1 ? 's' : '' ?></td></tr>
                    <tr><td>📅 Scheduled Post (AI timing)</td><td><strong><?= $tokenCostScheduled ?></strong> social token<?= $tokenCostScheduled > 1 ? 's' : '' ?></td></tr>
                    <tr><td>🧪 A/B Variant (extra)</td><td><strong><?= $tokenCostVariant ?></strong> social token<?= $tokenCostVariant > 1 ? 's' : '' ?> each</td></tr>
                    <tr><td>🤖 AI Content Generation</td><td><strong><?= (int)($settings['ai_tokens_per_generation'] ?? 50) ?></strong> AI tokens per run</td></tr>
                </tbody>
            </table>
            <div style="margin-top:1rem">
                <a href="/billing.php?tab=social_tokens" class="btn btn-primary">Buy Social Tokens</a>
                <a href="/billing.php?tab=ai_tokens" class="btn btn-secondary" style="margin-left:.5rem">Buy AI Tokens</a>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.tab-btn{padding:.5rem 1.1rem;border:1px solid var(--glass-border,rgba(255,255,255,.1));background:var(--glass-bg,rgba(255,255,255,.05));color:var(--text-muted,#606070);cursor:pointer;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;font-size:.9rem;white-space:nowrap}
.tab-btn.active{background:var(--accent,#6c63ff);color:#fff;border-color:var(--accent,#6c63ff)}
.tab-pane{display:none}.tab-pane.active{display:block}
@media(max-width:768px){.soc-grid,.soc-stats-grid{grid-template-columns:1fr!important}}
</style>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;

// ── Character counter ────────────────────────────────────────────────────────
const captionArea = document.getElementById('captionArea');
const charCount   = document.getElementById('charCount');
const preview     = document.getElementById('preview');
const hashField   = document.getElementById('hashtagsField');

function updatePreview(){
    const cap  = captionArea ? captionArea.value : '';
    const hash = hashField   ? hashField.value   : '';
    if(charCount) charCount.textContent = cap.length + ' chars';
    if(preview) preview.textContent = cap + (hash ? '\n\n' + hash : '') || 'Your post preview will appear here…';
}
if(captionArea) captionArea.addEventListener('input', updatePreview);
if(hashField)   hashField.addEventListener('input', updatePreview);

// ── Post Now (from compose tab) ──────────────────────────────────────────────
const postNowBtn = document.getElementById('postNowBtn');
if(postNowBtn){
    postNowBtn.addEventListener('click', async () => {
        const caption   = captionArea ? captionArea.value.trim() : '';
        const hashtags  = hashField   ? hashField.value.trim()   : '';
        const imageUrl  = document.querySelector('[name=image_url]')?.value.trim() ?? '';
        const platforms = [...document.querySelectorAll('[name="platforms[]"]:checked')].map(c => c.value);
        if(!caption){ alert('Caption is required.'); return; }
        if(!platforms.length){ alert('Select at least one platform.'); return; }
        if(!confirm('Post Now to: ' + platforms.join(', ') + '? This will deduct <?= $tokenCostNow ?> social token(s).')) return;

        // First save draft, then post
        postNowBtn.disabled = true;
        postNowBtn.textContent = '⏳ Posting…';
        try {
            // Save draft via form submit to get campaign_id, but use AJAX
            const draftRes = await fetch('/api/social-campaign-save.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({csrf_token:CSRF, caption, hashtags, image_url:imageUrl, platforms})
            });
            let cid = 0;
            if(draftRes.ok){
                const dr = await draftRes.json();
                cid = dr.campaign_id ?? 0;
            }
            if(!cid){ alert('Could not save draft. Please try again.'); postNowBtn.disabled=false; postNowBtn.textContent='🚀 Post Now (<?= $tokenCostNow ?> token<?= $tokenCostNow > 1 ? 's' : '' ?>)'; return; }

            const postRes = await fetch('/api/social-post.php', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({csrf_token:CSRF, campaign_id:cid, action:'post_now'})
            });
            const r = await postRes.json();
            if(r.success){ alert('✅ ' + r.message + '\nTokens remaining: ' + r.balance); location.href='/user/social.php?tab=campaigns'; }
            else { alert('❌ ' + r.message); }
        } catch(e){ alert('Network error. Please try again.'); }
        postNowBtn.disabled=false;
        postNowBtn.textContent='🚀 Post Now (<?= $tokenCostNow ?> token<?= $tokenCostNow > 1 ? 's' : '' ?>)';
    });
}

// ── Post Now (from campaigns table) ─────────────────────────────────────────
document.querySelectorAll('.postNowRowBtn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const cid = btn.dataset.id;
        if(!confirm('Post campaign #' + cid + ' now? Costs <?= $tokenCostNow ?> social token(s).')) return;
        btn.disabled = true;
        const res = await fetch('/api/social-post.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({csrf_token:CSRF, campaign_id:parseInt(cid), action:'post_now'})
        });
        const r = await res.json();
        alert(r.success ? '✅ ' + r.message : '❌ ' + r.message);
        if(r.success) location.reload();
        else btn.disabled = false;
    });
});

// ── AI Writer ─────────────────────────────────────────────────────────────────
const aiGenerateBtn  = document.getElementById('aiGenerateBtn');
const aiSpinner      = document.getElementById('aiSpinner');
const aiResultsCard  = document.getElementById('aiResultsCard');
const aiEmptyCard    = document.getElementById('aiEmptyCard');
const aiResultsBody  = document.getElementById('aiResultsBody');
const variantTabs    = document.getElementById('variantTabs');

if(aiGenerateBtn){
    aiGenerateBtn.addEventListener('click', async () => {
        const goal     = document.getElementById('aiGoal')?.value.trim()      ?? '';
        const audience = document.getElementById('aiAudience')?.value.trim()  ?? '';
        const brand    = document.getElementById('aiBrand')?.value.trim()     ?? '';
        const tone     = document.getElementById('aiTone')?.value             ?? 'casual';
        const variants = parseInt(document.getElementById('aiVariants')?.value ?? '1');
        const platforms= [...document.querySelectorAll('.aiPlatformCheck:checked')].map(c=>c.value);
        if(!goal){ alert('Please enter a campaign goal.'); return; }
        if(!platforms.length){ alert('Select at least one platform.'); return; }

        aiGenerateBtn.disabled = true;
        if(aiSpinner) aiSpinner.style.display='block';
        if(aiResultsCard) aiResultsCard.style.display='none';
        if(aiEmptyCard)   aiEmptyCard.style.display='none';

        try {
            const res = await fetch('/api/ai-social.php', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({goal,target_audience:audience,brand_name:brand,platforms,tone,num_variants:variants})
            });
            const r = await res.json();
            if(!r.success){ alert('❌ ' + r.message); if(aiEmptyCard) aiEmptyCard.style.display='block'; return; }

            renderAiVariants(r.variants);
            if(aiResultsCard) aiResultsCard.style.display='block';
        } catch(e){ alert('Network error.'); if(aiEmptyCard) aiEmptyCard.style.display='block'; }
        finally {
            aiGenerateBtn.disabled=false;
            if(aiSpinner) aiSpinner.style.display='none';
        }
    });
}

let allVariants = [];

function renderAiVariants(variants){
    allVariants = variants;
    if(!aiResultsBody || !variantTabs) return;

    variantTabs.innerHTML = '';
    variants.forEach((v,i)=>{
        const btn = document.createElement('button');
        btn.textContent = 'V' + (i+1);
        btn.className = 'tab-btn' + (i===0?' active':'');
        btn.style.fontSize='.78rem';btn.style.padding='.25rem .6rem';
        btn.addEventListener('click',()=>{ showVariant(i); variantTabs.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); });
        variantTabs.appendChild(btn);
    });

    showVariant(0);
}

function showVariant(idx){
    if(!allVariants[idx]||!aiResultsBody) return;
    const variant = allVariants[idx];
    const iconMap = {facebook:'📘',instagram:'📸',linkedin:'💼',twitter:'🐦',tiktok:'🎵',pinterest:'📌',youtube:'▶️'};
    aiResultsBody.innerHTML = variant.posts.map(post => `
        <div style="border:1px solid var(--glass-border);border-radius:10px;padding:1rem;margin-bottom:1rem">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                <strong style="font-size:.9rem">${iconMap[post.platform]??'📱'} ${post.platform.charAt(0).toUpperCase()+post.platform.slice(1)}</strong>
                <button class="btn btn-sm btn-primary useVariantBtn"
                        data-caption="${escHtml(post.caption)}"
                        data-hashtags="${escHtml(post.hashtags??'')}">
                    ✏️ Use in Create Post
                </button>
            </div>
            <p style="white-space:pre-wrap;font-size:.88rem;margin-bottom:.5rem">${escHtml(post.caption)}</p>
            <p style="color:#a78bfa;font-size:.82rem">${escHtml(post.hashtags??'')}</p>
        </div>
    `).join('');

    aiResultsBody.querySelectorAll('.useVariantBtn').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const cap  = btn.dataset.caption;
            const hash = btn.dataset.hashtags;
            // Switch to create tab and populate
            if(captionArea)  captionArea.value = cap;
            if(hashField)    hashField.value   = hash;
            updatePreview();
            // Show AI apply card
            const applyCard = document.getElementById('aiApplyCard');
            if(applyCard){
                applyCard.style.display='block';
                document.getElementById('aiAppliedCaption').textContent = cap;
                document.getElementById('aiAppliedHashtags').textContent= hash;
            }
            // Switch tab
            document.querySelector('[href="?tab=create"]')?.click();
            location.href='?tab=create';
        });
    });
}

function escHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// ── Smart Time Suggester ──────────────────────────────────────────────────────
const bestTimeBtn   = document.getElementById('bestTimeBtn');
const bestTimesCard = document.getElementById('bestTimesCard');
const bestTimesBody = document.getElementById('bestTimesBody');

if(bestTimeBtn){
    bestTimeBtn.addEventListener('click', async () => {
        const sel = document.getElementById('schedCampaignId');
        const opt = sel?.selectedOptions[0];
        const platforms = opt?.dataset.platforms?.split(',').filter(Boolean).join(',') ?? 'facebook,instagram';
        bestTimesCard.style.display='block';
        bestTimesBody.innerHTML='<p style="color:var(--text-muted)">⏳ Loading recommendations…</p>';
        try {
            const res = await fetch('/api/social-best-time.php?platforms=' + encodeURIComponent(platforms));
            const r = await res.json();
            if(!r.success){ bestTimesBody.innerHTML='<p style="color:var(--danger)">Could not load suggestions.</p>'; return; }
            let html = r.has_personal_data ? '<p style="color:#10b981;margin-bottom:.75rem">📊 Using your follower activity data.</p>' :
                       '<p style="color:var(--text-muted);margin-bottom:.75rem">📖 Based on global best practices.</p>';
            Object.entries(r.suggestions).forEach(([platform, times])=>{
                html += `<strong style="font-size:.85rem">${platform.charAt(0).toUpperCase()+platform.slice(1)}</strong><div style="display:flex;flex-wrap:wrap;gap:.5rem;margin:.4rem 0 1rem">`;
                times.forEach(t=>{
                    const dt = new Date(t.scheduled_at).toLocaleString(undefined,{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
                    html += `<button class="btn btn-sm btn-secondary applyTimeBtn" data-dt="${escHtml(t.scheduled_at)}">${dt}</button>`;
                });
                html += '</div>';
            });
            bestTimesBody.innerHTML = html;
            bestTimesBody.querySelectorAll('.applyTimeBtn').forEach(b=>{
                b.addEventListener('click',()=>{
                    const iso = b.dataset.dt.replace('Z','');
                    const local = iso.substring(0,16);
                    const schedInput = document.getElementById('schedDateTime');
                    if(schedInput) schedInput.value = local;
                });
            });
        } catch(e){ bestTimesBody.innerHTML='<p style="color:var(--danger)">Network error.</p>'; }
    });
}

// ── Schedule post ─────────────────────────────────────────────────────────────
const schedulePostBtn = document.getElementById('schedulePostBtn');
if(schedulePostBtn){
    schedulePostBtn.addEventListener('click', async ()=>{
        const cid  = parseInt(document.getElementById('schedCampaignId')?.value??'0');
        const dtLo = document.getElementById('schedDateTime')?.value??'';
        if(!cid)  { alert('Select a campaign.'); return; }
        if(!dtLo) { alert('Select a date & time.'); return; }
        const dtUTC = new Date(dtLo).toISOString();
        if(!confirm('Schedule campaign #'+cid+' for '+dtLo+' UTC? Costs <?= $tokenCostScheduled ?> social token(s).')) return;
        schedulePostBtn.disabled=true;
        const res = await fetch('/api/social-post.php',{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({csrf_token:CSRF,campaign_id:cid,action:'schedule',scheduled_at:dtUTC})
        });
        const r = await res.json();
        const div = document.getElementById('schedResult');
        if(div){
            div.style.display='block';
            div.className='alert alert-'+(r.success?'success':'danger');
            div.innerHTML = (r.success?'✅ ':'❌ ') + r.message;
        }
        if(r.success) setTimeout(()=>location.href='/user/social.php?tab=campaigns', 1500);
        else schedulePostBtn.disabled=false;
    });
}

// ── Connect / Disconnect social accounts ─────────────────────────────────────
async function openConnectFlow(){
    const res = await fetch('/api/social-connect.php?action=link');
    const r = await res.json();
    if(r.success && r.url){ window.open(r.url,'_blank','width=700,height=600'); }
    else { alert('❌ ' + r.message); }
}
document.getElementById('connectBtn')?.addEventListener('click', openConnectFlow);
document.getElementById('connectMoreBtn')?.addEventListener('click', openConnectFlow);

document.getElementById('refreshStatusBtn')?.addEventListener('click', async ()=>{
    const res = await fetch('/api/social-connect.php?action=status');
    const r = await res.json();
    if(r.success){ alert('✅ Status refreshed. Connected: ' + (r.platforms.join?r.platforms.join(', '):JSON.stringify(r.platforms))); location.reload(); }
    else { alert('❌ ' + r.message); }
});

document.getElementById('disconnectBtn')?.addEventListener('click', async ()=>{
    if(!confirm('Disconnect all social accounts?')) return;
    const res = await fetch('/api/social-connect.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'disconnect', csrf_token:CSRF})
    });
    const r = await res.json();
    alert(r.success ? '✅ Disconnected.' : '❌ ' + r.message);
    if(r.success) location.reload();
});

// ── Use AI caption in Create Post tab ────────────────────────────────────────
document.getElementById('useAiCaption')?.addEventListener('click', ()=>{
    updatePreview();
    document.getElementById('aiApplyCard').style.display='none';
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
