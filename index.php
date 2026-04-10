<?php
declare(strict_types=1);

$lockFile   = __DIR__ . '/config/.installed';
$configFile = __DIR__ . '/config/config.php';

if (!file_exists($lockFile) || !file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

// Fetch public-facing data (SMS price, packages, email plans) — gracefully fallback if DB unavailable
$smsUnitPrice = 6.50;
$packages     = [];
$emailPlans   = [];
$appName      = 'PhilmoreHost';
$currSym      = '₦';

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/includes/db.php';
    if (defined('APP_NAME')) $appName = APP_NAME;
    $db = getDB();
    $row = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sms_price_per_unit'")->fetch();
    if ($row) $smsUnitPrice = (float)$row['setting_value'];
    $symRow = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'currency_symbol'")->fetch();
    if ($symRow && $symRow['setting_value'] !== '') $currSym = $symRow['setting_value'];
    $packages   = $db->query("SELECT * FROM sms_credit_packages WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
    $emailPlans = $db->query("SELECT * FROM email_plans WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
} catch (\Exception $e) {}

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($appName) ?> — Bulk SMS &amp; Email Marketing Platform</title>
<meta name="description" content="Send bulk SMS and email campaigns at scale. Competitive pricing, powerful features, and a robust API.">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
/* ─── Landing-specific overrides ───────────────────────────────── */
body { display: block; }

/* ── NAV ── */
.lp-nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 200;
    display: flex; align-items: center; justify-content: space-between;
    padding: .9rem 5%;
    background: rgba(10,10,20,.85);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255,255,255,.07);
    transition: background .3s;
}
.lp-nav-brand { font-size: 1.25rem; font-weight: 800;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.lp-nav-links { display: flex; gap: 2rem; align-items: center; }
.lp-nav-links a { color: #b8b8cc; font-size: .9rem; transition: color .2s; }
.lp-nav-links a:hover { color: #fff; }
.lp-nav-actions { display: flex; gap: .75rem; align-items: center; }

/* ── HERO ── */
.hero {
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    text-align: center;
    padding: 7rem 5% 5rem;
    position: relative; overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 50% 40%, rgba(108,99,255,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 80%, rgba(0,212,255,.1) 0%, transparent 50%);
    pointer-events: none;
}
.hero-inner { max-width: 800px; position: relative; z-index: 1; }
.hero-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    background: rgba(108,99,255,.15); border: 1px solid rgba(108,99,255,.4);
    color: #a78bfa; border-radius: 50px; padding: .3rem 1rem; font-size: .8rem; font-weight: 600;
    margin-bottom: 1.75rem; letter-spacing: .04em;
}
.hero h1 {
    font-size: clamp(2.5rem, 6vw, 4rem); font-weight: 900; line-height: 1.1;
    margin-bottom: 1.25rem;
    background: linear-gradient(135deg, #fff 0%, #a78bfa 50%, #00d4ff 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero p {
    font-size: 1.1rem; color: #b8b8cc; max-width: 580px; margin: 0 auto 2.5rem; line-height: 1.7;
}
.hero-ctas { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.hero-stats {
    display: flex; gap: 3rem; justify-content: center; margin-top: 4rem;
    flex-wrap: wrap;
}
.hero-stat strong { display: block; font-size: 2rem; font-weight: 800;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero-stat span { font-size: .85rem; color: #8888a8; }

/* ── SECTION ── */
.lp-section { padding: 6rem 5%; }
.lp-section-alt { background: rgba(255,255,255,.02); border-top: 1px solid rgba(255,255,255,.05); border-bottom: 1px solid rgba(255,255,255,.05); }
.section-label {
    display: inline-block; font-size: .75rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: #6c63ff; margin-bottom: .75rem;
}
.section-title { font-size: clamp(1.75rem, 4vw, 2.5rem); font-weight: 800; margin-bottom: 1rem; }
.section-sub { color: #b8b8cc; max-width: 580px; line-height: 1.7; margin-bottom: 3rem; }
.section-center { text-align: center; }
.section-center .section-sub { margin: 0 auto 3rem; }

/* ── FEATURES ── */
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; }
.feature-card {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px; padding: 2rem; transition: all .3s;
    position: relative; overflow: hidden;
}
.feature-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, #6c63ff, #00d4ff); opacity: 0; transition: opacity .3s;
}
.feature-card:hover { transform: translateY(-6px); background: rgba(255,255,255,.07); border-color: rgba(108,99,255,.3); }
.feature-card:hover::before { opacity: 1; }
.feature-icon { font-size: 2.5rem; margin-bottom: 1rem; }
.feature-card h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: .5rem; }
.feature-card p { color: #b8b8cc; font-size: .9rem; line-height: 1.6; }

/* ── HOW IT WORKS ── */
.steps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 2rem; }
.step { text-align: center; padding: 1.5rem; }
.step-num {
    width: 56px; height: 56px; border-radius: 50%;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; font-weight: 800; margin: 0 auto 1.25rem;
    box-shadow: 0 0 30px rgba(108,99,255,.4);
}
.step h3 { font-size: 1rem; font-weight: 700; margin-bottom: .4rem; }
.step p { color: #b8b8cc; font-size: .88rem; line-height: 1.6; }

/* ── PRICING ── */
.pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; max-width: 1100px; margin: 0 auto; }
.pricing-card {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: 24px; padding: 2rem; position: relative; overflow: hidden;
    transition: all .3s;
}
.pricing-card.featured {
    background: rgba(108,99,255,.12); border-color: rgba(108,99,255,.4);
    box-shadow: 0 0 60px rgba(108,99,255,.2);
}
.pricing-card:hover { transform: translateY(-5px); }
.pricing-badge {
    position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    color: #fff; font-size: .72rem; font-weight: 700; padding: .25rem 1rem;
    border-radius: 50px; letter-spacing: .05em; white-space: nowrap;
}
.pricing-period { font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; color: #6c63ff; font-weight: 700; margin-bottom: .75rem; }
.pricing-name { font-size: 1.25rem; font-weight: 800; margin-bottom: .25rem; }
.pricing-credits { font-size: 2.5rem; font-weight: 900; color: #6c63ff; line-height: 1; }
.pricing-credits-label { font-size: .8rem; color: #8888a8; margin-bottom: .75rem; }
.pricing-price { font-size: 1.8rem; font-weight: 800; margin-bottom: 1.5rem; }
.pricing-price small { font-size: .9rem; font-weight: 400; color: #b8b8cc; }
.pricing-features { list-style: none; margin-bottom: 1.75rem; }
.pricing-features li { display: flex; align-items: center; gap: .6rem; padding: .4rem 0; font-size: .9rem; color: #c0c0d0; border-bottom: 1px solid rgba(255,255,255,.04); }
.pricing-features li:last-child { border: none; }
.pricing-features .check { color: #00ff88; }
.pricing-unit { font-size: .8rem; color: #b8b8cc; text-align: center; margin-top: .5rem; }

/* ── SMS CALC ── */
.sms-calc-section { background: rgba(108,99,255,.06); border: 1px solid rgba(108,99,255,.15); border-radius: 24px; padding: 2.5rem; max-width: 760px; margin: 0 auto; }
.calc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
.calc-item { background: rgba(255,255,255,.04); border-radius: 12px; padding: .9rem 1.2rem; text-align: center; }
.calc-item strong { display: block; font-size: 1.25rem; color: #6c63ff; font-weight: 800; }
.calc-item small { font-size: .78rem; color: #8888a8; }

/* ── TESTIMONIALS / TRUST ── */
.trust-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
.trust-card {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px; padding: 1.75rem;
}
.trust-card p { color: #b8b8cc; font-size: .9rem; line-height: 1.7; margin-bottom: 1rem; font-style: italic; }
.trust-author { display: flex; align-items: center; gap: .75rem; }
.trust-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #6c63ff, #00d4ff); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: .9rem; }
.trust-name { font-weight: 700; font-size: .9rem; }
.trust-role { font-size: .78rem; color: #8888a8; }

/* ── CTA SECTION ── */
.cta-section {
    padding: 6rem 5%; text-align: center;
    background: radial-gradient(ellipse 80% 60% at 50% 50%, rgba(108,99,255,.15) 0%, transparent 70%);
}
.cta-section h2 { font-size: clamp(1.75rem, 4vw, 2.75rem); font-weight: 900; margin-bottom: 1rem; }
.cta-section p { color: #b8b8cc; margin-bottom: 2.5rem; font-size: 1rem; }
.cta-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }

/* ── FOOTER ── */
.lp-footer {
    background: rgba(5,5,12,.98); border-top: 1px solid rgba(255,255,255,.07);
    padding: 3rem 5% 2rem;
}
.lp-footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 2rem; margin-bottom: 3rem; }
@media (max-width: 700px) { .lp-footer-grid { grid-template-columns: 1fr 1fr; } }
.lp-footer-brand strong {
    font-size: 1.1rem; font-weight: 800;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    display: block; margin-bottom: .5rem;
}
.lp-footer-brand p { color: #8888a8; font-size: .85rem; line-height: 1.7; }
.lp-footer h4 { font-size: .85rem; font-weight: 700; color: #b8b8cc; margin-bottom: 1rem; letter-spacing: .05em; text-transform: uppercase; }
.lp-footer ul { list-style: none; }
.lp-footer ul li { margin-bottom: .5rem; }
.lp-footer ul li a { color: #8888a8; font-size: .85rem; transition: color .2s; }
.lp-footer ul li a:hover { color: #a78bfa; }
.lp-footer-bottom { border-top: 1px solid rgba(255,255,255,.05); padding-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.lp-footer-bottom p { color: #8888a8; font-size: .82rem; }

/* ── BUTTONS (landing overrides) ── */
.btn-lg { padding: .875rem 2.25rem; font-size: 1rem; border-radius: 50px; }
.btn-outline {
    background: transparent; border: 1px solid rgba(255,255,255,.2); color: #e0e0e0;
    padding: .75rem 1.75rem; border-radius: 50px; font-size: .9rem; cursor: pointer;
    transition: all .2s; display: inline-block; font-weight: 600;
}
.btn-outline:hover { border-color: #6c63ff; color: #6c63ff; background: rgba(108,99,255,.08); }

/* ── Mobile nav ── */
@media (max-width: 640px) {
    .lp-nav-links { display: none; }
    .hero h1 { font-size: 2.2rem; }
}
/* ── Anchor offset for fixed nav ── */
[id] { scroll-margin-top: 80px; }
</style>
</head>
<body>

<!-- ══ NAVBAR ═══════════════════════════════════════════════════════════════ -->
<nav class="lp-nav">
    <span class="lp-nav-brand">📧 <?= htmlspecialchars($appName) ?></span>
    <div class="lp-nav-links">
        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>
        <a href="#sms-pricing">SMS Pricing</a>
        <a href="#email-pricing">Email Plans</a>
        <a href="#contact">Contact</a>
    </div>
    <div class="lp-nav-actions">
        <button class="btn-outline" id="themeToggle" style="padding:.45rem 1rem;font-size:.85rem">
            <span id="themeIcon"><?= $theme === 'dark' ? '🌙' : '☀️' ?></span>
        </button>
        <a href="/login.php" class="btn-outline" style="padding:.55rem 1.25rem;font-size:.9rem">Login</a>
        <a href="/register.php" class="btn btn-primary btn-lg" style="padding:.55rem 1.5rem;font-size:.9rem;border-radius:50px">Get Started</a>
    </div>
</nav>

<!-- ══ HERO ══════════════════════════════════════════════════════════════════ -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-badge">🚀 Trusted Bulk Messaging Platform</div>
        <h1>Reach Every Customer<br>Instantly at Scale</h1>
        <p>Send bulk SMS and email campaigns to thousands of contacts with blazing-fast delivery, real-time analytics, and affordable per-page pricing.</p>
        <div class="hero-ctas">
            <a href="/register.php" class="btn btn-primary btn-lg">Start Free Today</a>
            <a href="#pricing" class="btn-outline btn-lg">View Pricing</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat"><strong><?= $currSym ?><?= number_format($smsUnitPrice, 2) ?></strong><span>Per SMS Page</span></div>
            <div class="hero-stat"><strong>99.9%</strong><span>Uptime SLA</span></div>
            <div class="hero-stat"><strong>5s</strong><span>Avg. Delivery</span></div>
            <div class="hero-stat"><strong>24/7</strong><span>Support</span></div>
        </div>
    </div>
</section>

<!-- ══ FEATURES ══════════════════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt" id="features">
    <div style="max-width:1200px;margin:0 auto">
        <div class="section-center">
            <span class="section-label">Why Choose Us</span>
            <h2 class="section-title">Everything You Need to<br>Run Winning Campaigns</h2>
            <p class="section-sub">From bulk SMS to drip email sequences — one platform, zero complexity.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>Bulk SMS Campaigns</h3>
                <p>Reach customers via bulk, corporate, or global SMS routes. Per-page billing ensures you only pay for what you use — <?= $currSym ?><?= number_format($smsUnitPrice, 2) ?>/page.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📧</div>
                <h3>Email Marketing</h3>
                <p>Design beautiful emails with our template builder, schedule campaigns, track opens and clicks, and manage unsubscribes automatically.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Real-time Analytics</h3>
                <p>Track delivery rates, open rates, click-throughs, and failures as they happen. Export reports in CSV with a single click.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔐</div>
                <h3>Enterprise Security</h3>
                <p>MFA on every account, brute-force protection, IP whitelisting/blacklisting, country firewall, and end-to-end encrypted API credentials.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📅</div>
                <h3>Campaign Scheduling</h3>
                <p>Set campaigns to send at any future date and time in any timezone. Never miss the right moment to reach your audience.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">👥</div>
                <h3>Contact Management</h3>
                <p>Import, organise, and segment contacts into groups. Manage phonebooks and email lists from one unified interface.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔑</div>
                <h3>API Access</h3>
                <p>Integrate SMS and email into your own app with our REST API. Generate API keys, manage permissions, and track usage per key.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">💳</div>
                <h3>Flexible Credits</h3>
                <p>Buy SMS credit packages on one-time, monthly, quarterly, or yearly plans. Credits never expire on one-time purchases.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🗺️</div>
                <h3>Sender ID Management</h3>
                <p>Register custom Sender IDs and Caller IDs. Get fast approval and appear as your brand name in every message.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══ HOW IT WORKS ══════════════════════════════════════════════════════════ -->
<section class="lp-section" id="how-it-works">
    <div style="max-width:1100px;margin:0 auto">
        <div class="section-center">
            <span class="section-label">Simple Process</span>
            <h2 class="section-title">Up &amp; Running in Minutes</h2>
            <p class="section-sub">No complex setup. Just sign up, top up, and send.</p>
        </div>
        <div class="steps-grid">
            <div class="step">
                <div class="step-num">1</div>
                <h3>Create Account</h3>
                <p>Register with your email and verify with a one-time code sent instantly to your inbox.</p>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <h3>Buy Credits</h3>
                <p>Choose a credit package that suits your volume — from small bursts to enterprise-scale monthly plans.</p>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <h3>Add Contacts</h3>
                <p>Import your contact list, organise into groups, and manage opt-outs automatically.</p>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <h3>Send &amp; Track</h3>
                <p>Launch your campaign instantly or schedule it. Watch delivery reports roll in live.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══ SMS PAGE CALCULATION ═══════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt">
    <div style="max-width:900px;margin:0 auto;text-align:center">
        <span class="section-label">Fair Billing</span>
        <h2 class="section-title">Transparent Per-Page Pricing</h2>
        <p class="section-sub" style="margin:0 auto 2rem">You are charged per SMS page — not per message. A single page is up to 160 characters. Multi-page messages use 153 characters per page.</p>
        <div class="sms-calc-section">
            <div style="display:flex;align-items:center;justify-content:center;gap:.75rem;margin-bottom:.25rem">
                <span style="font-size:1.5rem">💰</span>
                <h3 style="margin:0;font-size:1.25rem">Current price: <span style="color:#6c63ff"><?= $currSym ?><?= number_format($smsUnitPrice, 2) ?>/page</span></h3>
            </div>
            <p style="color:#b8b8cc;font-size:.88rem;margin-bottom:0">Per-recipient, per-page charge</p>
            <div class="calc-grid">
                <div class="calc-item">
                    <strong><?= $currSym ?><?= number_format($smsUnitPrice, 2) ?></strong>
                    <small>1 page (≤160 chars)</small>
                </div>
                <div class="calc-item">
                    <strong><?= $currSym ?><?= number_format($smsUnitPrice * 2, 2) ?></strong>
                    <small>2 pages (161–306 chars)</small>
                </div>
                <div class="calc-item">
                    <strong><?= $currSym ?><?= number_format($smsUnitPrice * 3, 2) ?></strong>
                    <small>3 pages (307–459 chars)</small>
                </div>
                <div class="calc-item">
                    <strong><?= $currSym ?><?= number_format($smsUnitPrice * 4, 2) ?></strong>
                    <small>4 pages (460–612 chars)</small>
                </div>
            </div>
            <p style="color:#8888a8;font-size:.8rem;margin-top:1.25rem">
                After 160 chars, each additional page = 153 characters. Pages × recipients × <?= $currSym ?><?= number_format($smsUnitPrice, 2) ?> = total debit.
            </p>
        </div>
    </div>
</section>

<!-- ══ PRICING ═══════════════════════════════════════════════════════════════ -->
<section class="lp-section" id="sms-pricing">
    <div style="max-width:1200px;margin:0 auto">
        <div class="section-center">
            <span class="section-label">SMS Pricing</span>
            <h2 class="section-title">Affordable SMS Credit Packages</h2>
            <p class="section-sub">Buy SMS units upfront and use them whenever you need. The more you buy, the better the value.</p>
        </div>

        <?php if (empty($packages)): ?>
        <div style="text-align:center;padding:3rem;color:#8888a8">
            <p style="font-size:1.1rem">Contact us for custom pricing tailored to your volume.</p>
            <a href="/register.php" class="btn btn-primary btn-lg" style="margin-top:1.5rem;display:inline-block">Get a Quote</a>
        </div>
        <?php else: ?>
        <?php
        $billingLabels = ['one_time'=>'One-Time','monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'];
        $popularIdx    = count($packages) > 1 ? (int)floor(count($packages) / 2) : -1;
        ?>
        <div class="pricing-grid">
        <?php foreach ($packages as $i => $pkg):
            $isPopular = ($i === $popularIdx);
            $bLabel    = $billingLabels[$pkg['billing_period'] ?? 'one_time'] ?? 'One-Time';
        ?>
            <div class="pricing-card <?= $isPopular ? 'featured' : '' ?>">
                <?php if ($isPopular): ?><div class="pricing-badge">⭐ Most Popular</div><?php endif; ?>
                <div class="pricing-period"><?= htmlspecialchars($bLabel) ?></div>
                <div class="pricing-name"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="pricing-credits"><?= number_format((int)$pkg['credits']) ?></div>
                <div class="pricing-credits-label">SMS Units / Credits</div>
                <div class="pricing-price">
                    <?= $currSym ?><?= number_format((float)$pkg['price'], 2) ?>
                    <?php if ($pkg['billing_period'] !== 'one_time'): ?>
                    <small>/<?= strtolower($bLabel) ?></small>
                    <?php endif; ?>
                </div>
                <ul class="pricing-features">
                    <li><span class="check">✓</span><?= number_format((int)$pkg['credits']) ?> SMS credits</li>
                    <li><span class="check">✓</span><?= $currSym ?><?= number_format($smsUnitPrice, 2) ?>/page pricing</li>
                    <li><span class="check">✓</span>Bulk, Corporate &amp; Global routes</li>
                    <li><span class="check">✓</span>Real-time delivery reports</li>
                    <?php if ($pkg['billing_period'] !== 'one_time'): ?>
                    <li><span class="check">✓</span>Auto-renewed <?= strtolower($bLabel) ?></li>
                    <?php else: ?>
                    <li><span class="check">✓</span>Credits never expire</li>
                    <?php endif; ?>
                </ul>
                <a href="/register.php" class="btn <?= $isPopular ? 'btn-primary' : 'btn-outline' ?>" style="width:100%;text-align:center;display:block;border-radius:50px;padding:.75rem 1.5rem">
                    Get Started
                </a>
                <p class="pricing-unit">≈ <?= number_format((int)$pkg['credits']) ?> individual SMS pages</p>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══ EMAIL PLANS ════════════════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt" id="email-pricing">
    <div style="max-width:1200px;margin:0 auto">
        <div class="section-center">
            <span class="section-label">Email Plans</span>
            <h2 class="section-title">Monthly Email Marketing Plans</h2>
            <p class="section-sub">Choose a monthly email plan with a generous send limit. Scale up any time.</p>
        </div>

        <?php if (empty($emailPlans)): ?>
        <div style="text-align:center;padding:3rem;color:#8888a8">
            <p style="font-size:1.1rem">Contact us for custom email plan pricing.</p>
            <a href="/register.php" class="btn btn-primary btn-lg" style="margin-top:1.5rem;display:inline-block">Get a Quote</a>
        </div>
        <?php else: ?>
        <?php $epPopularIdx = count($emailPlans) > 1 ? (int)floor(count($emailPlans) / 2) : -1; ?>
        <div class="pricing-grid">
        <?php foreach ($emailPlans as $ei => $ep):
            $isPopular   = ($ei === $epPopularIdx);
            $featuresArr = json_decode($ep['features'] ?? '[]', true) ?: [];
        ?>
            <div class="pricing-card <?= $isPopular ? 'featured' : '' ?>">
                <?php if ($isPopular): ?><div class="pricing-badge">⭐ Most Popular</div><?php endif; ?>
                <div class="pricing-period" style="color:#10b981">Monthly Email Plan</div>
                <div class="pricing-name"><?= htmlspecialchars($ep['name']) ?></div>
                <div class="pricing-credits" style="color:#10b981"><?= number_format((int)$ep['monthly_email_limit']) ?></div>
                <div class="pricing-credits-label">Emails / Month</div>
                <div class="pricing-price">
                    <?= $currSym ?><?= number_format((float)$ep['price'], 2) ?>
                    <small>/month</small>
                </div>
                <?php if (!empty($ep['description'])): ?>
                <p style="color:#b8b8cc;font-size:.85rem;margin-bottom:.75rem;line-height:1.5"><?= htmlspecialchars($ep['description']) ?></p>
                <?php endif; ?>
                <ul class="pricing-features">
                    <li><span class="check" style="color:#10b981">✓</span><?= number_format((int)$ep['monthly_email_limit']) ?> emails/month</li>
                    <li><span class="check" style="color:#10b981">✓</span>Template builder included</li>
                    <li><span class="check" style="color:#10b981">✓</span>Real-time open/click tracking</li>
                    <li><span class="check" style="color:#10b981">✓</span>Unsubscribe management</li>
                    <?php foreach (array_slice($featuresArr, 0, 2) as $f): ?>
                    <li><span class="check" style="color:#10b981">✓</span><?= htmlspecialchars($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="/register.php" class="btn <?= $isPopular ? 'btn-primary' : 'btn-outline' ?>" style="width:100%;text-align:center;display:block;border-radius:50px;padding:.75rem 1.5rem;<?= $isPopular ? '' : 'border-color:rgba(16,185,129,.5);color:#10b981' ?>">
                    Subscribe Now
                </a>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══ TESTIMONIALS ═══════════════════════════════════════════════════════════ -->
<section class="lp-section lp-section-alt">
    <div style="max-width:1100px;margin:0 auto">
        <div class="section-center">
            <span class="section-label">Trusted By</span>
            <h2 class="section-title">What Our Customers Say</h2>
        </div>
        <div class="trust-grid">
            <div class="trust-card">
                <p>"We switched from another provider and immediately noticed the delivery speed. Our OTP messages now arrive in under 3 seconds."</p>
                <div class="trust-author">
                    <div class="trust-avatar">AO</div>
                    <div><div class="trust-name">Adebayo O.</div><div class="trust-role">CTO, FinTech Startup</div></div>
                </div>
            </div>
            <div class="trust-card">
                <p>"The per-page billing is transparent and fair. I know exactly what I'll pay before sending. No surprises on the invoice."</p>
                <div class="trust-author">
                    <div class="trust-avatar">CU</div>
                    <div><div class="trust-name">Chioma U.</div><div class="trust-role">Marketing Manager, Retail Chain</div></div>
                </div>
            </div>
            <div class="trust-card">
                <p>"Setting up bulk SMS took less than 10 minutes. The dashboard is clean and the analytics are exactly what we needed."</p>
                <div class="trust-author">
                    <div class="trust-avatar">EK</div>
                    <div><div class="trust-name">Emeka K.</div><div class="trust-role">Co-founder, E-commerce Platform</div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ CTA ════════════════════════════════════════════════════════════════════ -->
<section class="cta-section" id="contact">
    <div style="max-width:700px;margin:0 auto">
        <h2>Ready to Reach Your Audience?</h2>
        <p>Join hundreds of businesses already sending smarter with <?= htmlspecialchars($appName) ?>. Sign up in seconds — no credit card required to register.</p>
        <div class="cta-btns">
            <a href="/register.php" class="btn btn-primary btn-lg">Create Free Account</a>
            <a href="/login.php" class="btn-outline btn-lg">Sign In</a>
        </div>
    </div>
</section>

<!-- ══ FOOTER ═════════════════════════════════════════════════════════════════ -->
<footer class="lp-footer">
    <div class="lp-footer-grid">
        <div class="lp-footer-brand">
            <strong>📧 <?= htmlspecialchars($appName) ?></strong>
            <p>A powerful bulk SMS and email marketing platform built for Nigerian businesses and beyond. Fast delivery, fair pricing, enterprise security.</p>
        </div>
        <div>
            <h4>Platform</h4>
            <ul>
                <li><a href="#features">Features</a></li>
                <li><a href="#sms-pricing">SMS Pricing</a></li>
                <li><a href="#email-pricing">Email Plans</a></li>
                <li><a href="#how-it-works">How It Works</a></li>
            </ul>
        </div>
        <div>
            <h4>Account</h4>
            <ul>
                <li><a href="/register.php">Register</a></li>
                <li><a href="/login.php">Login</a></li>
                <li><a href="/billing.php">Buy Credits</a></li>
            </ul>
        </div>
        <div>
            <h4>Support</h4>
            <ul>
                <li><a href="mailto:support@<?= strtolower(str_replace(' ', '', $appName)) ?>.com">Email Support</a></li>
                <li><a href="/login.php">Admin Panel</a></li>
            </ul>
        </div>
    </div>
    <div class="lp-footer-bottom">
        <p>© <?= date('Y') ?> <?= htmlspecialchars($appName) ?>. All rights reserved.</p>
        <p style="color:#8888a8">Powered by PhilmoreHost</p>
    </div>
</footer>

<script>
// Theme toggle
const themeToggle = document.getElementById('themeToggle');
const themeIcon   = document.getElementById('themeIcon');
const html        = document.documentElement;

themeToggle.addEventListener('click', function() {
    const current = html.getAttribute('data-theme');
    const next    = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    themeIcon.textContent = next === 'dark' ? '🌙' : '☀️';
    document.cookie = 'theme=' + next + ';path=/;max-age=31536000';
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
    });
});

// Navbar glass on scroll
window.addEventListener('scroll', () => {
    document.querySelector('.lp-nav').style.background =
        window.scrollY > 50 ? 'rgba(10,10,20,.98)' : 'rgba(10,10,20,.85)';
});
</script>
</body>
</html>

