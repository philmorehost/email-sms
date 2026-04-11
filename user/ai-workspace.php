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

        <!-- ── AI Prompt Library ──────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap">
                <h3>⚡ Prompt Library</h3>
                <span style="font-size:.75rem;color:var(--text-muted)" id="promptCount"></span>
            </div>
            <div class="card-body" style="padding-bottom:.5rem">
                <!-- Search -->
                <input type="text" id="promptSearch" class="form-control" placeholder="🔍 Search prompts…" style="margin-bottom:.75rem;font-size:.85rem">
                <!-- Category pills -->
                <div id="promptCats" style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.75rem"></div>
                <!-- Prompt list -->
                <div id="promptList" style="display:flex;flex-direction:column;gap:.35rem;max-height:420px;overflow-y:auto;padding-right:.2rem"></div>
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

// ── Prompt Library ────────────────────────────────────────────────────────
const PROMPT_LIBRARY = [
    // ── Welcome & Onboarding ──────────────────────────────────────────────
    { cat: 'Welcome & Onboarding', text: 'Welcome email for new subscribers with a warm greeting and what to expect' },
    { cat: 'Welcome & Onboarding', text: 'Onboarding sequence email 1: account setup guide for new users' },
    { cat: 'Welcome & Onboarding', text: 'Onboarding email 2: introduce key product features with tips' },
    { cat: 'Welcome & Onboarding', text: 'Onboarding email 3: success story to inspire new users' },
    { cat: 'Welcome & Onboarding', text: 'Welcome gift email with exclusive discount for first purchase' },
    { cat: 'Welcome & Onboarding', text: 'Getting started guide email with step-by-step instructions' },
    { cat: 'Welcome & Onboarding', text: 'New member welcome email with community links and resources' },
    { cat: 'Welcome & Onboarding', text: 'Trial account welcome email with how to get the most from your trial' },
    { cat: 'Welcome & Onboarding', text: 'Confirmation email after successful signup with next steps' },
    { cat: 'Welcome & Onboarding', text: 'Welcome back email for returning customers with loyalty recognition' },

    // ── Promotional & Sales ───────────────────────────────────────────────
    { cat: 'Promotional & Sales', text: 'Black Friday mega sale announcement with countdown timer urgency' },
    { cat: 'Promotional & Sales', text: 'Cyber Monday deals email with curated best offers' },
    { cat: 'Promotional & Sales', text: 'Flash sale announcement — 24-hour only discount with urgency' },
    { cat: 'Promotional & Sales', text: 'Weekend sale email with exclusive promo code for subscribers' },
    { cat: 'Promotional & Sales', text: 'End-of-season clearance sale email with up to 70% off messaging' },
    { cat: 'Promotional & Sales', text: 'Buy one get one free promotion email for retail customers' },
    { cat: 'Promotional & Sales', text: 'Members-only exclusive early access sale invitation email' },
    { cat: 'Promotional & Sales', text: 'Sitewide discount email with promo code and limited validity' },
    { cat: 'Promotional & Sales', text: 'Summer sale announcement with bright tropical theme' },
    { cat: 'Promotional & Sales', text: 'New Year sale email with fresh start messaging and deals' },
    { cat: 'Promotional & Sales', text: 'Mid-year sale email offering best-of-year pricing' },
    { cat: 'Promotional & Sales', text: 'Category spotlight sale email focusing on one product line' },
    { cat: 'Promotional & Sales', text: 'Referral discount email — share and both you and friend save' },
    { cat: 'Promotional & Sales', text: 'Bundle deal email showing savings on product combinations' },
    { cat: 'Promotional & Sales', text: 'Free shipping promotion email with minimum order threshold' },

    // ── Product Launch ────────────────────────────────────────────────────
    { cat: 'Product Launch', text: 'New product launch announcement with feature highlights and CTA' },
    { cat: 'Product Launch', text: 'Product teaser email building anticipation before launch day' },
    { cat: 'Product Launch', text: 'Pre-order launch email with early-bird pricing incentive' },
    { cat: 'Product Launch', text: 'Product waitlist email for high-demand item with notify me CTA' },
    { cat: 'Product Launch', text: 'App version 2.0 launch email highlighting new features' },
    { cat: 'Product Launch', text: 'Service expansion announcement email to existing customers' },
    { cat: 'Product Launch', text: 'Software feature release email with what\'s new rundown' },
    { cat: 'Product Launch', text: 'Exclusive beta access invitation for loyal customers' },
    { cat: 'Product Launch', text: 'Limited edition product drop email with scarcity messaging' },
    { cat: 'Product Launch', text: 'Product of the month spotlight email with review highlights' },

    // ── E-commerce & Cart Recovery ────────────────────────────────────────
    { cat: 'E-commerce', text: 'Abandoned cart recovery email with friendly reminder and discount offer' },
    { cat: 'E-commerce', text: 'Second cart reminder email with stronger urgency and social proof' },
    { cat: 'E-commerce', text: 'Order confirmation email with order summary and delivery estimate' },
    { cat: 'E-commerce', text: 'Shipping confirmation email with tracking link and delivery tips' },
    { cat: 'E-commerce', text: 'Delivery confirmation email with product use tips and upsell' },
    { cat: 'E-commerce', text: 'Back-in-stock alert email for previously waitlisted item' },
    { cat: 'E-commerce', text: 'Price drop alert email for items in customer\'s wishlist' },
    { cat: 'E-commerce', text: 'Browse abandonment email based on recently viewed products' },
    { cat: 'E-commerce', text: 'Post-purchase upsell email recommending complementary products' },
    { cat: 'E-commerce', text: 'Subscription renewal reminder with benefits recap' },
    { cat: 'E-commerce', text: 'Refund processed confirmation email with apology and voucher' },
    { cat: 'E-commerce', text: 'Return request received email with instructions and timeline' },

    // ── Newsletter & Content ──────────────────────────────────────────────
    { cat: 'Newsletter & Content', text: 'Weekly newsletter with industry news, tips, and brand updates' },
    { cat: 'Newsletter & Content', text: 'Monthly roundup email: best content, milestones, and upcoming events' },
    { cat: 'Newsletter & Content', text: 'Thought leadership email sharing expert insights on industry trends' },
    { cat: 'Newsletter & Content', text: 'Tutorial email with step-by-step how-to guide for product feature' },
    { cat: 'Newsletter & Content', text: 'Case study email showcasing customer success story with results' },
    { cat: 'Newsletter & Content', text: 'Blog roundup email featuring top articles from the past month' },
    { cat: 'Newsletter & Content', text: 'Video content announcement email with embedded thumbnail and CTA' },
    { cat: 'Newsletter & Content', text: 'Podcast episode announcement email with episode highlights' },
    { cat: 'Newsletter & Content', text: 'Infographic-style email presenting statistics and data visually' },
    { cat: 'Newsletter & Content', text: 'Curated reading list email for professionals in your industry' },
    { cat: 'Newsletter & Content', text: 'Tips and tricks email: 5 ways to get more value from our product' },
    { cat: 'Newsletter & Content', text: 'Behind-the-scenes company culture email humanising your brand' },

    // ── Customer Retention & Loyalty ──────────────────────────────────────
    { cat: 'Retention & Loyalty', text: 'Customer loyalty reward email celebrating milestone purchases' },
    { cat: 'Retention & Loyalty', text: 'VIP tier upgrade email congratulating customer on exclusive status' },
    { cat: 'Retention & Loyalty', text: 'Points expiry reminder email with urgency to redeem before deadline' },
    { cat: 'Retention & Loyalty', text: 'Anniversary email celebrating one year with your brand' },
    { cat: 'Retention & Loyalty', text: 'Birthday email with personalised discount and warm wishes' },
    { cat: 'Retention & Loyalty', text: 'Thank-you email after major purchase expressing genuine gratitude' },
    { cat: 'Retention & Loyalty', text: 'Customer appreciation email with surprise gift or bonus credit' },
    { cat: 'Retention & Loyalty', text: 'Exclusive reward email for top 10% most active customers' },
    { cat: 'Retention & Loyalty', text: 'Cashback earned notification email with balance and redemption CTA' },
    { cat: 'Retention & Loyalty', text: 'Loyalty programme sign-up invitation email with benefits overview' },

    // ── Re-engagement ─────────────────────────────────────────────────────
    { cat: 'Re-engagement', text: 'Win-back email for customers inactive for 90 days with incentive' },
    { cat: 'Re-engagement', text: 'We miss you email for lapsed subscribers with emotional tone' },
    { cat: 'Re-engagement', text: 'Last chance to stay subscribed email before list pruning' },
    { cat: 'Re-engagement', text: 'Re-engagement survey email asking why they haven\'t purchased lately' },
    { cat: 'Re-engagement', text: 'Come back offer email with personalised "just for you" discount' },
    { cat: 'Re-engagement', text: 'Updated features announcement email targeting churned users' },
    { cat: 'Re-engagement', text: 'Subscription pause reminder email with easy reactivation CTA' },
    { cat: 'Re-engagement', text: 'Account reactivation email with what\'s new since they last visited' },

    // ── Events & Webinars ─────────────────────────────────────────────────
    { cat: 'Events & Webinars', text: 'Webinar invitation email with agenda, speaker bio, and registration CTA' },
    { cat: 'Events & Webinars', text: 'Event reminder email 24 hours before with location and prep tips' },
    { cat: 'Events & Webinars', text: 'Live sale or live stream event announcement email' },
    { cat: 'Events & Webinars', text: 'Conference sponsorship invitation email for potential partners' },
    { cat: 'Events & Webinars', text: 'Post-event follow-up email with recording link and key takeaways' },
    { cat: 'Events & Webinars', text: 'Virtual summit registration email with speaker lineup' },
    { cat: 'Events & Webinars', text: 'Countdown email: 3 days to event with urgency and last seats messaging' },
    { cat: 'Events & Webinars', text: 'In-store event invitation email with map and RSVP CTA' },
    { cat: 'Events & Webinars', text: 'Workshop sign-up email with curriculum overview and limited spots urgency' },

    // ── Seasonal & Holiday ────────────────────────────────────────────────
    { cat: 'Seasonal & Holiday', text: 'Christmas gift guide email with top picks across price ranges' },
    { cat: 'Seasonal & Holiday', text: 'New Year email with reflection on achievements and excitement for ahead' },
    { cat: 'Seasonal & Holiday', text: 'Valentine\'s Day gift ideas email with romantic theme' },
    { cat: 'Seasonal & Holiday', text: 'Easter holiday sale email with egg hunt promo code mechanic' },
    { cat: 'Seasonal & Holiday', text: 'Mother\'s Day gift recommendation email with heartfelt messaging' },
    { cat: 'Seasonal & Holiday', text: 'Father\'s Day promotion email targeting gift-givers' },
    { cat: 'Seasonal & Holiday', text: 'Eid Mubarak celebration email with festive design and special offer' },
    { cat: 'Seasonal & Holiday', text: 'Diwali festival sale email with warm golden theme' },
    { cat: 'Seasonal & Holiday', text: 'Back-to-school supplies promotion email for parents and students' },
    { cat: 'Seasonal & Holiday', text: 'Halloween spooky sale email with themed design and offers' },
    { cat: 'Seasonal & Holiday', text: 'Thanksgiving gratitude email thanking customers for their support' },
    { cat: 'Seasonal & Holiday', text: 'Independence Day patriotic sale email with national theme' },

    // ── B2B & Professional Services ───────────────────────────────────────
    { cat: 'B2B & Professional', text: 'Cold outreach email introducing your B2B service to a potential client' },
    { cat: 'B2B & Professional', text: 'Follow-up email after sales demo with proposal summary' },
    { cat: 'B2B & Professional', text: 'Partnership proposal email to potential business collaborators' },
    { cat: 'B2B & Professional', text: 'Quarterly business review email for enterprise clients' },
    { cat: 'B2B & Professional', text: 'Contract renewal reminder email with usage highlights and next steps' },
    { cat: 'B2B & Professional', text: 'New feature briefing email to B2B customers with ROI highlights' },
    { cat: 'B2B & Professional', text: 'Invoice/payment reminder email with professional and polite tone' },
    { cat: 'B2B & Professional', text: 'Client onboarding kickoff email with project timeline and contacts' },
    { cat: 'B2B & Professional', text: 'Upsell email for enterprise plan to growing mid-market customer' },
    { cat: 'B2B & Professional', text: 'Annual report highlights email sent to stakeholders' },

    // ── Transactional & Account ───────────────────────────────────────────
    { cat: 'Transactional', text: 'Password reset email with clear instructions and security tips' },
    { cat: 'Transactional', text: 'Two-factor authentication code email with urgency note' },
    { cat: 'Transactional', text: 'Account suspended notice email with reason and appeal process' },
    { cat: 'Transactional', text: 'Subscription payment failed email with update billing CTA' },
    { cat: 'Transactional', text: 'Plan upgrade confirmation email with new features unlocked' },
    { cat: 'Transactional', text: 'Data privacy update email explaining GDPR/policy changes' },
    { cat: 'Transactional', text: 'Terms of service update email with summary of key changes' },
    { cat: 'Transactional', text: 'Account deletion confirmation email with data download option' },

    // ── Healthcare & Wellness ─────────────────────────────────────────────
    { cat: 'Healthcare & Wellness', text: 'Appointment confirmation email with clinic details and prep instructions' },
    { cat: 'Healthcare & Wellness', text: 'Health check-up reminder email with booking CTA' },
    { cat: 'Healthcare & Wellness', text: 'Prescription refill reminder email with easy reorder link' },
    { cat: 'Healthcare & Wellness', text: 'Wellness programme welcome email with first-week plan' },
    { cat: 'Healthcare & Wellness', text: 'Telehealth onboarding email with app download and first session tips' },
    { cat: 'Healthcare & Wellness', text: 'Fitness challenge kickoff email with week one goals' },
    { cat: 'Healthcare & Wellness', text: 'Nutrition plan delivery confirmation with meal prep tips' },

    // ── Real Estate & Finance ─────────────────────────────────────────────
    { cat: 'Real Estate & Finance', text: 'New property listing alert email for registered buyers' },
    { cat: 'Real Estate & Finance', text: 'Mortgage rate update email informing clients of new rates' },
    { cat: 'Real Estate & Finance', text: 'Investment opportunity brief email for financial services clients' },
    { cat: 'Real Estate & Finance', text: 'Open house invitation email with property photos and details' },
    { cat: 'Real Estate & Finance', text: 'Loan approval congratulations email with next steps' },
    { cat: 'Real Estate & Finance', text: 'Monthly portfolio update email for wealth management clients' },
    { cat: 'Real Estate & Finance', text: 'Insurance renewal reminder email with coverage summary' },

    // ── Education & Coaching ──────────────────────────────────────────────
    { cat: 'Education & Coaching', text: 'Course enrollment confirmation email with access credentials' },
    { cat: 'Education & Coaching', text: 'Live class reminder email with Zoom link and agenda' },
    { cat: 'Education & Coaching', text: 'Certificate completion congratulations email' },
    { cat: 'Education & Coaching', text: 'New course launch announcement for existing students' },
    { cat: 'Education & Coaching', text: 'Study streak congratulations email with motivational message' },
    { cat: 'Education & Coaching', text: 'Coaching session booking confirmation with preparation tips' },
    { cat: 'Education & Coaching', text: 'Alumni newsletter email celebrating student success stories' },
    { cat: 'Education & Coaching', text: 'Scholarship announcement email with eligibility criteria and CTA' },

    // ── Food & Restaurant ─────────────────────────────────────────────────
    { cat: 'Food & Restaurant', text: 'New menu launch email with mouth-watering descriptions' },
    { cat: 'Food & Restaurant', text: 'Table reservation confirmation email with address and date' },
    { cat: 'Food & Restaurant', text: 'Food delivery promotion email with discount for first order' },
    { cat: 'Food & Restaurant', text: 'Weekly specials email with featured dishes and limited offer' },
    { cat: 'Food & Restaurant', text: 'Loyalty card milestone email with free meal reward' },
    { cat: 'Food & Restaurant', text: 'Restaurant anniversary email with celebration offer for diners' },

    // ── Travel & Hospitality ──────────────────────────────────────────────
    { cat: 'Travel & Hospitality', text: 'Flight booking confirmation email with itinerary and check-in CTA' },
    { cat: 'Travel & Hospitality', text: 'Hotel booking confirmation with welcome offer and amenities' },
    { cat: 'Travel & Hospitality', text: 'Travel deal announcement email for exclusive subscriber pricing' },
    { cat: 'Travel & Hospitality', text: 'Holiday package promotion email with destination highlights' },
    { cat: 'Travel & Hospitality', text: 'Check-in reminder email with early bird upgrade offer' },
    { cat: 'Travel & Hospitality', text: 'Post-stay thank-you email requesting review with loyalty points' },

    // ── SaaS & Tech ───────────────────────────────────────────────────────
    { cat: 'SaaS & Tech', text: 'Free trial expiry reminder email with conversion offer' },
    { cat: 'SaaS & Tech', text: 'Usage milestone email celebrating API calls or data processed' },
    { cat: 'SaaS & Tech', text: 'Downtime apology email with explanation, fix update, and credit offer' },
    { cat: 'SaaS & Tech', text: 'Integration announcement email: your tool now connects with X' },
    { cat: 'SaaS & Tech', text: 'Developer changelog email summarising API updates and breaking changes' },
    { cat: 'SaaS & Tech', text: 'Security breach notification email with steps taken and what to do' },
    { cat: 'SaaS & Tech', text: 'Annual subscription savings email convincing monthly users to upgrade' },
    { cat: 'SaaS & Tech', text: 'Product roadmap preview email building excitement for upcoming features' },

    // ── Non-Profit & Social Cause ─────────────────────────────────────────
    { cat: 'Non-Profit & Social', text: 'Fundraising campaign launch email with compelling cause story' },
    { cat: 'Non-Profit & Social', text: 'Donation thank-you email with impact metrics from contributions' },
    { cat: 'Non-Profit & Social', text: 'Volunteer recruitment email with event details and sign-up CTA' },
    { cat: 'Non-Profit & Social', text: 'Year-end giving appeal email for year-end tax deductible donations' },
    { cat: 'Non-Profit & Social', text: 'Campaign milestone update email showing progress to goal' },
    { cat: 'Non-Profit & Social', text: 'Awareness campaign email for social or environmental cause' },
];

// Build category list
const allCats = ['All', ...new Set(PROMPT_LIBRARY.map(p => p.cat))];
let activeCat  = 'All';
let searchTerm = '';

const catContainer  = document.getElementById('promptCats');
const promptList    = document.getElementById('promptList');
const promptCount   = document.getElementById('promptCount');
const promptSearch  = document.getElementById('promptSearch');

function renderCats() {
    catContainer.innerHTML = '';
    allCats.forEach(cat => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm ' + (cat === activeCat ? 'btn-primary' : 'btn-secondary');
        btn.style.cssText = 'font-size:.72rem;padding:.2rem .6rem;white-space:nowrap';
        btn.textContent = cat;
        btn.addEventListener('click', () => { activeCat = cat; renderCats(); renderPrompts(); });
        catContainer.appendChild(btn);
    });
}

function renderPrompts() {
    const filtered = PROMPT_LIBRARY.filter(p => {
        const catMatch  = activeCat === 'All' || p.cat === activeCat;
        const termMatch = searchTerm === '' || p.text.toLowerCase().includes(searchTerm) || p.cat.toLowerCase().includes(searchTerm);
        return catMatch && termMatch;
    });
    promptCount.textContent = filtered.length + ' prompt' + (filtered.length !== 1 ? 's' : '');
    promptList.innerHTML = '';
    if (filtered.length === 0) {
        promptList.innerHTML = '<p style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:.75rem">No prompts match your search.</p>';
        return;
    }
    filtered.forEach(p => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-secondary';
        btn.style.cssText = 'text-align:left;white-space:normal;line-height:1.4;font-size:.82rem';
        btn.textContent = p.text;
        btn.addEventListener('click', () => {
            inputEl.value = p.text;
            inputEl.focus();
            // On mobile, scroll chat into view
            inputEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        promptList.appendChild(btn);
    });
}

promptSearch.addEventListener('input', function () {
    searchTerm = this.value.toLowerCase().trim();
    renderPrompts();
});

renderCats();
renderPrompts();

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
