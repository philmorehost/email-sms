<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

// Override CSP to allow GrapesJS from CDN
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' " .
        "https://unpkg.com " .
        "https://js.stripe.com " .
        "https://www.paypal.com " .
        "https://checkout.flutterwave.com " .
        "https://payhub.datagifting.com.ng; " .
    "style-src 'self' 'unsafe-inline' https://unpkg.com; " .
    "img-src 'self' data: blob: https:; " .
    "font-src 'self' data: https://unpkg.com; " .
    "frame-src 'none'; " .
    "connect-src 'self';",
    true
);

requireAuth();

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

// ── Inline migration ─────────────────────────────────────────────────────────
try {
    $cols = $db->query("SHOW COLUMNS FROM email_templates LIKE 'created_by'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE email_templates ADD COLUMN created_by INT NULL");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Exception $e) {}

// ── Load template if editing ──────────────────────────────────────────────────
$templateId  = (int)($_GET['id'] ?? 0);
$template    = null;
if ($templateId > 0) {
    try {
        $tStmt = $db->prepare("SELECT * FROM email_templates WHERE id=? AND (created_by=? OR created_by IS NULL)");
        $tStmt->execute([$templateId, $userId]);
        $template = $tStmt->fetch();
    } catch (\Exception $e) {}
}

// ── Save template (POST) ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }

    $name       = mb_substr(trim($body['name'] ?? 'Untitled Template'), 0, 100);
    $subject    = mb_substr(trim($body['subject'] ?? ''), 0, 255);
    $jsonDesign = $body['json_design'] ?? '';
    $htmlContent = $body['html_content'] ?? '';
    $saveId     = isset($body['template_id']) ? (int)$body['template_id'] : 0;

    if ($jsonDesign !== '' && is_array(json_decode($jsonDesign, true))) {
        $jsonDesign = $jsonDesign; // already valid JSON string
    } else {
        $jsonDesign = json_encode($body['json_design'] ?? []);
    }

    try {
        if ($saveId > 0) {
            // Update existing (only if owned by user)
            $db->prepare("UPDATE email_templates SET name=?, subject=?, json_design=?, html_content=?, updated_at=NOW() WHERE id=? AND (created_by=? OR created_by IS NULL)")
               ->execute([$name, $subject, $jsonDesign, $htmlContent, $saveId, $userId]);
            echo json_encode(['success' => true, 'template_id' => $saveId]);
        } else {
            // Create new
            $db->prepare("INSERT INTO email_templates (name, subject, json_design, html_content, created_by) VALUES (?,?,?,?,?)")
               ->execute([$name, $subject, $jsonDesign, $htmlContent, $userId]);
            $newId = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'template_id' => $newId]);
        }
    } catch (\Exception $e) {
        error_log('email-editor save: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error saving template.']);
    }
    exit;
}

// ── Load AI token balance ─────────────────────────────────────────────────────
$aiBalance = 0;
try {
    $abStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $abStmt->execute([$userId]);
    $aiBalance = (int)($abStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

// ── Load cost config ──────────────────────────────────────────────────────────
$costGen = 50;
try {
    $cRow = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_tokens_per_generation'")->fetchColumn();
    if ($cRow !== false) $costGen = (int)$cRow;
} catch (\Exception $e) {}

$pageTitle  = $template ? ('Edit: ' . htmlspecialchars($template['name'])) : 'New Email Template';
$activePage = 'email_editor';

// Build initial GrapesJS data
$initialJson = $template ? ($template['json_design'] ?? '') : '';
$initialHtml = $template ? ($template['html_content'] ?? '') : '';
$tplName     = $template ? ($template['name'] ?? '') : '';
$tplSubject  = $template ? ($template['subject'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<!-- GrapesJS -->
<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://unpkg.com/grapesjs-preset-newsletter@1.0.1/dist/grapesjs-preset-newsletter.min.js"></script>
<style>
/* ── Editor Layout ─────────────────────────────────────────────── */
body{margin:0;overflow:hidden}
.editor-wrap{display:flex;height:100vh;flex-direction:column}
.editor-topbar{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;background:var(--surface,#1a1a2e);border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;flex-wrap:wrap}
.editor-topbar .tpl-fields{display:flex;gap:.5rem;flex:1;min-width:0}
.editor-topbar .tpl-fields input{flex:1;min-width:120px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--text-primary,#e0e0e0);padding:.4rem .75rem;border-radius:8px;font-size:.88rem}
.editor-topbar .tpl-fields input:focus{outline:none;border-color:var(--accent,#6c63ff)}
.editor-body{display:flex;flex:1;overflow:hidden}

/* ── GrapesJS canvas area ──────────────────────────────────────── */
#grapesjs-editor{flex:1;overflow:hidden}
.gjs-editor{height:100%}

/* ── AI Sidebar ────────────────────────────────────────────────── */
.ai-sidebar{width:320px;flex-shrink:0;display:flex;flex-direction:column;background:var(--surface,#1a1a2e);border-left:1px solid rgba(255,255,255,.08);overflow-y:auto;transition:width .25s}
.ai-sidebar.collapsed{width:0;overflow:hidden}
.ai-sidebar-inner{padding:1rem;display:flex;flex-direction:column;gap:1rem;min-width:320px}
.ai-sidebar-header{display:flex;justify-content:space-between;align-items:center}
.ai-sidebar-header h3{margin:0;font-size:1rem;color:var(--text-primary,#e0e0e0)}
.token-badge{background:rgba(108,99,255,.2);border:1px solid rgba(108,99,255,.4);color:#a78bfa;padding:3px 10px;border-radius:20px;font-size:.8rem;font-weight:600;white-space:nowrap}

/* Generate section */
.ai-gen-section{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:1rem}
.ai-gen-section h4{margin:0 0 .75rem;font-size:.9rem;color:var(--text-primary,#e0e0e0)}
.ai-gen-section textarea{width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--text-primary,#e0e0e0);border-radius:8px;padding:.6rem .75rem;font-size:.85rem;resize:vertical;min-height:90px;font-family:inherit}
.ai-gen-section textarea:focus{outline:none;border-color:var(--accent,#6c63ff)}
.ai-cost{font-size:.78rem;color:var(--text-muted,#606070);margin:.4rem 0 .6rem}

/* Chat section */
.ai-chat-section{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:1rem;display:flex;flex-direction:column;gap:.75rem}
.ai-chat-section h4{margin:0;font-size:.9rem;color:var(--text-primary,#e0e0e0)}
.chat-messages{max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:.6rem}
.chat-msg{padding:.6rem .75rem;border-radius:10px;font-size:.83rem;line-height:1.5}
.chat-msg.user{background:rgba(108,99,255,.15);color:#c4b5fd;align-self:flex-end;max-width:90%}
.chat-msg.assistant{background:rgba(255,255,255,.06);color:var(--text-primary,#e0e0e0);max-width:95%}
.chat-msg.assistant a{color:#60a5fa}
.chat-input-row{display:flex;gap:.4rem}
.chat-input-row textarea{flex:1;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:var(--text-primary,#e0e0e0);border-radius:8px;padding:.5rem .65rem;font-size:.83rem;resize:none;height:62px;font-family:inherit}
.chat-input-row textarea:focus{outline:none;border-color:var(--accent,#6c63ff)}

/* Spinner */
.ai-spinner{display:none;text-align:center;padding:.5rem;font-size:.82rem;color:var(--text-muted,#606070)}
.ai-spinner.active{display:block}
.ai-err{color:#f87171;font-size:.8rem;margin-top:.4rem;display:none}
.ai-err.active{display:block}

/* Device preview buttons inside GrapesJS topbar */
.gjs-pn-commands .gjs-pn-buttons{flex-wrap:wrap}
</style>
</head>
<body>
<div class="editor-wrap">

    <!-- Top bar -->
    <div class="editor-topbar">
        <a href="/admin/email.php" class="btn btn-sm btn-secondary" style="text-decoration:none">← Back</a>
        <div class="tpl-fields">
            <input type="text" id="tplName"    placeholder="Template name…" value="<?= htmlspecialchars($tplName) ?>">
            <input type="text" id="tplSubject" placeholder="Email subject…" value="<?= htmlspecialchars($tplSubject) ?>">
        </div>
        <button class="btn btn-sm btn-secondary" id="btnPreviewMobile" title="Mobile preview">📱</button>
        <button class="btn btn-sm btn-secondary" id="btnPreviewDesktop" title="Desktop preview">🖥️</button>
        <button class="btn btn-primary btn-sm" id="btnSave">💾 Save</button>
        <button class="btn btn-sm btn-secondary" id="btnToggleAI" title="Toggle AI Sidebar">🤖 AI</button>
    </div>

    <div class="editor-body">
        <!-- GrapesJS canvas -->
        <div id="grapesjs-editor"></div>

        <!-- AI Sidebar -->
        <aside class="ai-sidebar" id="aiSidebar">
            <div class="ai-sidebar-inner">
                <div class="ai-sidebar-header">
                    <h3>🤖 AI Assistant</h3>
                    <span class="token-badge" id="tokenBadge"><?= number_format($aiBalance) ?> tokens</span>
                </div>

                <!-- Generate from prompt -->
                <div class="ai-gen-section">
                    <h4>✨ Generate Template</h4>
                    <textarea id="genPrompt" placeholder="e.g. Black Friday sale for luxury watches with dark gold theme…"></textarea>
                    <div class="ai-cost">⚡ Costs <?= (int)$costGen ?> tokens per generation</div>
                    <button class="btn btn-primary" style="width:100%" id="btnGenerate">Generate</button>
                    <div class="ai-spinner" id="genSpinner">🔄 Generating template…</div>
                    <div class="ai-err" id="genError"></div>
                </div>

                <!-- Chat / Refine -->
                <div class="ai-chat-section">
                    <h4>💬 AI Copywriter Chat</h4>
                    <div class="chat-messages" id="chatMessages">
                        <div class="chat-msg assistant">Hi! I'm your AI copywriter. Ask me to refine subject lines, write CTAs, or improve your email copy.</div>
                    </div>
                    <div class="chat-input-row">
                        <textarea id="chatInput" placeholder="Ask me anything about your email…" rows="2"></textarea>
                        <button class="btn btn-primary btn-sm" id="btnChat" style="align-self:flex-end">Send</button>
                    </div>
                    <div class="ai-spinner" id="chatSpinner">🔄 Thinking…</div>
                    <div class="ai-err" id="chatError"></div>
                    <div style="font-size:.75rem;color:var(--text-muted,#606070);margin-top:.3rem">Token cost: proportional to response length</div>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
(function () {
'use strict';

const TEMPLATE_ID   = <?= json_encode($templateId > 0 ? $templateId : null) ?>;
const INITIAL_JSON  = <?= json_encode($initialJson ?: '') ?>;
const INITIAL_HTML  = <?= json_encode($initialHtml ?: '') ?>;

// ── GrapesJS init ──────────────────────────────────────────────────────────
const editor = grapesjs.init({
    container: '#grapesjs-editor',
    plugins: ['grapesjs-preset-newsletter'],
    pluginsOpts: {
        'grapesjs-preset-newsletter': {
            modalLabelImport: 'Paste your HTML here',
            modalLabelExport: 'Copy this HTML',
            importPlaceholder: '',
            inlineCss: true,
        },
    },
    height: '100%',
    width:  'auto',
    storageManager: false,
    deviceManager: {
        devices: [
            { name: 'Desktop', width: '' },
            { name: 'Mobile',  width: '375px', widthMedia: '480px' },
        ],
    },
    panels: {
        defaults: [
            {
                id: 'panel-top',
                el: '.editor-topbar',
                buttons: [],
            },
            {
                id:      'basic-actions',
                el:      '.panel__basic-actions',
                buttons: [],
            },
        ],
    },
});

// Load saved design
if (INITIAL_JSON) {
    try {
        const parsed = JSON.parse(INITIAL_JSON);
        if (parsed && typeof parsed === 'object') {
            editor.loadProjectData(parsed);
        } else {
            // Fallback: load raw HTML
            if (INITIAL_HTML) editor.setComponents(INITIAL_HTML);
        }
    } catch (e) {
        if (INITIAL_HTML) editor.setComponents(INITIAL_HTML);
    }
} else {
    // Check for HTML from AI workspace redirect
    const wsHtml = sessionStorage.getItem('ai_ws_html');
    if (wsHtml) {
        sessionStorage.removeItem('ai_ws_html');
        editor.setComponents(wsHtml);
    }
}

// Fix non-responsive edit: re-init select after every drop
editor.on('component:add', function (component) {
    setTimeout(() => {
        editor.select(component);
        editor.trigger('component:deselected', component);
    }, 10);
});

// ── Device preview buttons ────────────────────────────────────────────────
document.getElementById('btnPreviewMobile').addEventListener('click', () => {
    editor.setDevice('Mobile');
});
document.getElementById('btnPreviewDesktop').addEventListener('click', () => {
    editor.setDevice('Desktop');
});

// ── Save ──────────────────────────────────────────────────────────────────
document.getElementById('btnSave').addEventListener('click', async () => {
    const btn = document.getElementById('btnSave');
    const name    = document.getElementById('tplName').value.trim() || 'Untitled Template';
    const subject = document.getElementById('tplSubject').value.trim();
    const jsonDesign  = JSON.stringify(editor.getProjectData());
    const htmlContent = editor.runCommand('gjs-get-inlined-html') || editor.getHtml();

    btn.textContent = '⏳ Saving…';
    btn.disabled = true;

    try {
        const res = await fetch('/user/email-editor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                subject,
                json_design:  jsonDesign,
                html_content: htmlContent,
                template_id:  currentTemplateId,
            }),
        });
        const data = await res.json();
        if (data.success) {
            currentTemplateId = data.template_id;
            btn.textContent = '✅ Saved!';
            setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 2000);
        } else {
            btn.textContent = '❌ Error';
            btn.disabled = false;
            alert(data.message || 'Error saving template.');
        }
    } catch (e) {
        btn.textContent = '❌ Error';
        btn.disabled = false;
    }
});

let currentTemplateId = TEMPLATE_ID;

// ── AI Sidebar toggle ─────────────────────────────────────────────────────
const aiSidebar = document.getElementById('aiSidebar');
document.getElementById('btnToggleAI').addEventListener('click', () => {
    aiSidebar.classList.toggle('collapsed');
});

// ── Token balance poller ──────────────────────────────────────────────────
async function refreshBalance() {
    try {
        const res = await fetch('/api/ai-token-balance.php');
        if (!res.ok) return;
        const data = await res.json();
        if (typeof data.balance === 'number') {
            document.getElementById('tokenBadge').textContent = data.balance.toLocaleString() + ' tokens';
        }
    } catch (e) {}
}
setInterval(refreshBalance, 30000);

// ── Generate template ─────────────────────────────────────────────────────
document.getElementById('btnGenerate').addEventListener('click', async () => {
    const prompt = document.getElementById('genPrompt').value.trim();
    if (!prompt) { showErr('genError', 'Please enter a prompt.'); return; }

    showSpinner('genSpinner', true);
    hideErr('genError');
    document.getElementById('btnGenerate').disabled = true;

    try {
        const res = await fetch('/api/ai-generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt, mode: 'generate', template_id: currentTemplateId }),
        });
        const data = await res.json();
        if (data.success) {
            editor.setComponents(data.html);
            document.getElementById('tokenBadge').textContent = data.balance.toLocaleString() + ' tokens';
            document.getElementById('genPrompt').value = '';
        } else {
            showErr('genError', data.message || 'Generation failed.');
        }
    } catch (e) {
        showErr('genError', 'Network error. Please try again.');
    } finally {
        showSpinner('genSpinner', false);
        document.getElementById('btnGenerate').disabled = false;
    }
});

// ── Chat ──────────────────────────────────────────────────────────────────
const chatHistory = [];

document.getElementById('btnChat').addEventListener('click', sendChat);
document.getElementById('chatInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
});

async function sendChat() {
    const input = document.getElementById('chatInput').value.trim();
    if (!input) return;

    addChatMsg('user', input);
    chatHistory.push({ role: 'user', content: input });
    document.getElementById('chatInput').value = '';
    document.getElementById('btnChat').disabled = true;
    showSpinner('chatSpinner', true);
    hideErr('chatError');

    try {
        const res = await fetch('/api/ai-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: chatHistory, template_id: currentTemplateId }),
        });
        const data = await res.json();
        if (data.success) {
            chatHistory.push({ role: 'assistant', content: data.reply });
            addChatMsg('assistant', data.reply);
            document.getElementById('tokenBadge').textContent = data.balance.toLocaleString() + ' tokens';
        } else {
            showErr('chatError', data.message || 'Chat failed.');
        }
    } catch (e) {
        showErr('chatError', 'Network error. Please try again.');
    } finally {
        showSpinner('chatSpinner', false);
        document.getElementById('btnChat').disabled = false;
    }
}

function addChatMsg(role, text) {
    const el = document.createElement('div');
    el.className = 'chat-msg ' + role;
    // Allow basic HTML links in assistant messages
    el.innerHTML = role === 'assistant'
        ? text.replace(/</g, '&lt;').replace(/https?:\/\/\S+/g, (url) => `<a href="${url}" target="_blank" rel="noopener">${url}</a>`)
        : escHtml(text);
    const container = document.getElementById('chatMessages');
    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function showSpinner(id, show) {
    document.getElementById(id).classList.toggle('active', show);
}
function showErr(id, msg) {
    const el = document.getElementById(id);
    el.innerHTML = msg;
    el.classList.add('active');
}
function hideErr(id) {
    const el = document.getElementById(id);
    el.classList.remove('active');
}

})();
</script>
</body>
</html>
