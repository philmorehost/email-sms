<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

setSecurityHeaders();
requireAuth();

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

// ── Inline migration ─────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_ai_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Exception $e) {}

// ── Load AI token balance ─────────────────────────────────────────────────────
$aiBalance = 0;
try {
    $abStmt = $db->prepare("SELECT balance FROM user_ai_tokens WHERE user_id=?");
    $abStmt->execute([$userId]);
    $aiBalance = (int)($abStmt->fetchColumn() ?: 0);
} catch (\Exception $e) {}

// ── Load cost config ──────────────────────────────────────────────────────────
$costPer1k = 10;
try {
    $cRow = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_tokens_per_chat_1k'")->fetchColumn();
    if ($cRow !== false) $costPer1k = (int)$cRow;
} catch (\Exception $e) {}

$aiEnabled = false;
try {
    $keyRow = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='deepseek_api_key'")->fetchColumn();
    $aiEnabled = !empty($keyRow);
} catch (\Exception $e) {}

$pageTitle  = 'AI Copywriter Workspace';
$activePage = 'ai_workspace';
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">🤖 AI Copywriter Workspace</h1>
        <p class="page-subtitle">Generate marketing ideas, email copy, subject lines, and templates with AI</p>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <span class="token-badge-lg" id="wsTokenBadge"><?= number_format($aiBalance) ?> tokens</span>
        <a href="/billing.php?tab=ai_tokens" class="btn btn-sm btn-secondary">💰 Buy Tokens</a>
        <a href="/user/email-editor.php" class="btn btn-sm btn-primary">✏️ Open Template Editor</a>
    </div>
</div>

<?php if (!$aiEnabled): ?>
<div class="card" style="border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.06)">
    <div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <span style="font-size:2rem">⚠️</span>
        <div>
            <strong style="display:block;margin-bottom:.2rem">AI not configured</strong>
            <span style="color:var(--text-muted);font-size:.9rem">An admin needs to configure the DeepSeek API key in Admin → AI Settings before AI features are available.</span>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;margin-top:1rem">

    <!-- Chat area -->
    <div class="card" style="display:flex;flex-direction:column;height:calc(100vh - 220px);min-height:400px">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
            <h3 style="margin:0">💬 Chat with AI</h3>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                <button class="btn btn-sm btn-secondary" id="btnClearChat">🗑️ Clear</button>
                <button class="btn btn-sm btn-secondary" id="btnSaveTemplate" style="display:none">💾 Save as Template</button>
            </div>
        </div>
        <div style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.75rem" id="wsChatMessages">
            <div class="ws-msg assistant">
                <div class="ws-msg-avatar">🤖</div>
                <div class="ws-msg-body">
                    Hello! I'm your AI Email Copywriter. I can help you:
                    <ul style="margin:.5rem 0 0;padding-left:1.25rem;color:inherit">
                        <li>Write compelling subject lines</li>
                        <li>Draft email body copy and CTAs</li>
                        <li>Generate complete HTML email templates</li>
                        <li>Brainstorm campaign ideas</li>
                    </ul>
                    <p style="margin:.75rem 0 0">What would you like to create today?</p>
                </div>
            </div>
        </div>
        <div style="padding:1rem;border-top:1px solid rgba(255,255,255,.08)">
            <div style="display:flex;gap:.5rem;align-items:flex-end">
                <textarea id="wsInput" class="form-control" rows="3"
                    placeholder="Ask me to write an email, generate a subject line, or create a full template…"
                    style="resize:none;flex:1;height:72px"></textarea>
                <div style="display:flex;flex-direction:column;gap:.4rem">
                    <button class="btn btn-primary" id="wsBtnSend">Send</button>
                    <button class="btn btn-sm btn-secondary" id="wsBtnTemplate" title="Generate HTML template from last AI message">📧 To Template</button>
                </div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.5rem;flex-wrap:wrap;gap:.25rem">
                <div style="font-size:.78rem;color:var(--text-muted)">
                    <span id="wsSpinner" style="display:none">🔄 AI is thinking…</span>
                    <span id="wsErr" style="color:#f87171;display:none"></span>
                </div>
                <div style="font-size:.78rem;color:var(--text-muted)">Token cost: ~<?= $costPer1k ?>/1k words · Balance: <strong id="wsBalanceInline"><?= number_format($aiBalance) ?></strong></div>
            </div>
        </div>
    </div>

    <!-- Quick prompts panel -->
    <div style="display:flex;flex-direction:column;gap:1rem">

        <div class="card">
            <div class="card-header"><h3>⚡ Quick Prompts</h3></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem">
                <?php
                $quickPrompts = [
                    '✉️ Welcome email for new subscribers',
                    '🛍️ Black Friday sale announcement',
                    '🎁 Holiday season email with promo code',
                    '🔔 Product launch announcement',
                    '💡 Weekly newsletter template',
                    '🤝 Partnership/collaboration email',
                    '📊 Monthly report / recap email',
                    '🎉 Customer loyalty reward email',
                ];
                foreach ($quickPrompts as $qp): ?>
                <button class="btn btn-sm btn-secondary quick-prompt" style="text-align:left;white-space:normal;line-height:1.4"
                        data-prompt="<?= htmlspecialchars($qp) ?>">
                    <?= htmlspecialchars($qp) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>📋 Subject Line Helper</h3></div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:.75rem">
                    <label class="form-label" style="font-size:.85rem">Topic / Campaign</label>
                    <input type="text" id="subjectTopic" class="form-control" placeholder="e.g. summer sale 50% off" style="font-size:.88rem">
                </div>
                <button class="btn btn-sm btn-primary" id="btnGenSubjects" style="width:100%">Generate 5 Subject Lines</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>💰 Your AI Tokens</h3></div>
            <div class="card-body" style="text-align:center">
                <div style="font-size:2.5rem;font-weight:800;color:var(--accent)"><?= number_format($aiBalance) ?></div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">tokens remaining</div>
                <a href="/billing.php?tab=ai_tokens" class="btn btn-primary" style="width:100%">💰 Buy More Tokens</a>
            </div>
        </div>

    </div>
</div>

<!-- Save as Template modal -->
<div id="saveTemplateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center">
    <div class="card" style="max-width:480px;width:90%;margin:0 auto">
        <div class="card-header"><h3>💾 Save as Email Template</h3></div>
        <div class="card-body">
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Template Name</label>
                <input type="text" id="saveTemplateName" class="form-control" placeholder="My AI Template" maxlength="100">
            </div>
            <div class="form-group" style="margin-bottom:1rem">
                <label class="form-label">Email Subject</label>
                <input type="text" id="saveTemplateSubject" class="form-control" placeholder="Subject line…" maxlength="255">
            </div>
            <div style="display:flex;gap:.5rem">
                <button class="btn btn-primary" id="btnConfirmSave">Save Template</button>
                <button class="btn btn-secondary" onclick="document.getElementById('saveTemplateModal').style.display='none'">Cancel</button>
            </div>
            <div id="saveTemplateErr" style="color:#f87171;font-size:.85rem;margin-top:.5rem;display:none"></div>
        </div>
    </div>
</div>

<style>
.token-badge-lg{background:rgba(108,99,255,.2);border:1px solid rgba(108,99,255,.4);color:#a78bfa;padding:4px 14px;border-radius:20px;font-size:.9rem;font-weight:700}
.ws-msg{display:flex;gap:.75rem;align-items:flex-start}
.ws-msg.user{flex-direction:row-reverse}
.ws-msg-avatar{flex-shrink:0;font-size:1.4rem;margin-top:.2rem}
.ws-msg-body{background:rgba(255,255,255,.05);border-radius:12px;padding:.75rem 1rem;font-size:.87rem;line-height:1.6;max-width:90%;color:var(--text-primary,#e0e0e0)}
.ws-msg.user .ws-msg-body{background:rgba(108,99,255,.15);color:#c4b5fd}
.ws-msg-body pre{background:rgba(0,0,0,.3);border-radius:8px;padding:.75rem;overflow-x:auto;font-size:.8rem;margin:.5rem 0}
.ws-msg-body code{font-family:monospace}
</style>

<script>
(function () {
'use strict';

const chatHistory = [];
let lastHtmlContent = '';

const messagesEl = document.getElementById('wsChatMessages');
const inputEl    = document.getElementById('wsInput');
const spinnerEl  = document.getElementById('wsSpinner');
const errEl      = document.getElementById('wsErr');

// ── Quick prompts ─────────────────────────────────────────────────────────
document.querySelectorAll('.quick-prompt').forEach(btn => {
    btn.addEventListener('click', () => {
        inputEl.value = btn.dataset.prompt;
        inputEl.focus();
    });
});

// ── Subject line helper ───────────────────────────────────────────────────
document.getElementById('btnGenSubjects').addEventListener('click', () => {
    const topic = document.getElementById('subjectTopic').value.trim();
    if (!topic) { alert('Please enter a topic.'); return; }
    inputEl.value = `Generate 5 compelling email subject lines for a campaign about: ${topic}. Make them varied — use urgency, curiosity, personalization, numbers, and questions. List them numbered.`;
    inputEl.focus();
});

// ── Send message ──────────────────────────────────────────────────────────
document.getElementById('wsBtnSend').addEventListener('click', sendMessage);
inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

async function sendMessage() {
    const msg = inputEl.value.trim();
    if (!msg) return;

    appendMsg('user', escHtml(msg));
    chatHistory.push({ role: 'user', content: msg });
    inputEl.value = '';
    showStatus(true, false, '');

    try {
        const res = await fetch('/api/ai-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: chatHistory }),
        });
        const data = await res.json();
        if (data.success) {
            chatHistory.push({ role: 'assistant', content: data.reply });
            const rendered = renderReply(data.reply);
            appendMsg('assistant', rendered, data.reply);
            updateBalance(data.balance);
            // Check if reply looks like HTML
            if (data.reply.includes('<') && data.reply.includes('</')) {
                lastHtmlContent = data.reply;
                document.getElementById('btnSaveTemplate').style.display = 'inline-block';
                document.getElementById('wsBtnTemplate').style.display   = 'inline-block';
            }
        } else {
            showStatus(false, true, data.message || 'Request failed.');
        }
    } catch (e) {
        showStatus(false, true, 'Network error. Please try again.');
    }

    showStatus(false, false, '');
}

// ── "To Template" button: open editor with last HTML ─────────────────────
document.getElementById('wsBtnTemplate').style.display = 'none';
document.getElementById('wsBtnTemplate').addEventListener('click', () => {
    if (!lastHtmlContent) return;
    // Store HTML in sessionStorage and open editor
    sessionStorage.setItem('ai_ws_html', lastHtmlContent);
    window.location.href = '/user/email-editor.php?from_ws=1';
});

// ── Save as template modal ─────────────────────────────────────────────────
document.getElementById('btnSaveTemplate').style.display = 'none';
document.getElementById('btnSaveTemplate').addEventListener('click', () => {
    document.getElementById('saveTemplateModal').style.display = 'flex';
});
document.getElementById('btnConfirmSave').addEventListener('click', async () => {
    const name    = document.getElementById('saveTemplateName').value.trim();
    const subject = document.getElementById('saveTemplateSubject').value.trim();
    const errEl2  = document.getElementById('saveTemplateErr');
    if (!name) { errEl2.style.display='block'; errEl2.textContent='Template name is required.'; return; }
    errEl2.style.display = 'none';

    try {
        const res = await fetch('/user/email-editor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, subject, html_content: lastHtmlContent, json_design: '{}' }),
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('saveTemplateModal').style.display = 'none';
            // Offer to open in editor
            if (confirm('Template saved! Open it in the editor now?')) {
                window.location.href = '/user/email-editor.php?id=' + data.template_id;
            }
        } else {
            errEl2.style.display = 'block';
            errEl2.textContent = data.message || 'Error saving template.';
        }
    } catch (e) {
        errEl2.style.display = 'block';
        errEl2.textContent = 'Network error. Please try again.';
    }
});

// ── Clear chat ─────────────────────────────────────────────────────────────
document.getElementById('btnClearChat').addEventListener('click', () => {
    if (!confirm('Clear chat history?')) return;
    chatHistory.length = 0;
    lastHtmlContent = '';
    messagesEl.innerHTML = '';
    document.getElementById('btnSaveTemplate').style.display = 'none';
    document.getElementById('wsBtnTemplate').style.display   = 'none';
    appendMsg('assistant', 'Chat cleared. Ready to help!', '');
});

// ── Token balance poller ──────────────────────────────────────────────────
async function refreshBalance() {
    try {
        const res = await fetch('/api/ai-token-balance.php');
        if (!res.ok) return;
        const data = await res.json();
        if (typeof data.balance === 'number') updateBalance(data.balance);
    } catch (e) {}
}
setInterval(refreshBalance, 30000);

function updateBalance(bal) {
    const fmt = Number(bal).toLocaleString();
    document.getElementById('wsTokenBadge').textContent    = fmt + ' tokens';
    document.getElementById('wsBalanceInline').textContent = fmt;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function appendMsg(role, htmlContent, rawContent) {
    const wrap = document.createElement('div');
    wrap.className = 'ws-msg ' + role;
    const avatarStr = role === 'user' ? '👤' : '🤖';
    wrap.innerHTML = `<div class="ws-msg-avatar">${avatarStr}</div><div class="ws-msg-body">${htmlContent}</div>`;
    messagesEl.appendChild(wrap);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function renderReply(text) {
    // Simple markdown-like rendering
    let out = escHtml(text);
    // Code blocks
    out = out.replace(/```(?:html|css|js|javascript)?\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
    // Inline code
    out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
    // Bold
    out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Numbered list items
    out = out.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
    out = out.replace(/(<li>[\s\S]*?<\/li>)/g, '<ol>$1</ol>');
    // Bullet list
    out = out.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    // Line breaks
    out = out.replace(/\n\n/g, '<br><br>').replace(/\n/g, '<br>');
    return out;
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showStatus(loading, error, msg) {
    spinnerEl.style.display = loading ? 'inline' : 'none';
    errEl.style.display     = error   ? 'inline' : 'none';
    if (error) errEl.innerHTML = msg;
    document.getElementById('wsBtnSend').disabled = loading;
}

// Load from AI workspace if redirected from workspace
if (new URLSearchParams(location.search).get('from_ws') === '1') {
    const storedHtml = sessionStorage.getItem('ai_ws_html');
    if (storedHtml) {
        sessionStorage.removeItem('ai_ws_html');
        // The editor will handle this on its own page
    }
}

})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
