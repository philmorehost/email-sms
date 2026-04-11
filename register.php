<?php
declare(strict_types=1);

$lockFile   = __DIR__ . '/config/.installed';
$configFile = __DIR__ . '/config/config.php';

if (!file_exists($lockFile) || !file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

setSecurityHeaders();
startSecureSession();

// Already logged in? Go to dashboard
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error      = '';
$success    = '';
$otpPending = !empty($_SESSION['reg_pending_email']);
$appName    = defined('APP_NAME') ? APP_NAME : 'Marketing Suite';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!empty($_POST['otp_step'])) {
        // ── Step 2: OTP verification ──────────────────────────────────────
        if (empty($_SESSION['reg_pending_email'])) {
            redirect('/register.php');
        }

        if (isset($_POST['back_to_register'])) {
            unset($_SESSION['reg_pending_email'], $_SESSION['reg_pending_masked']);
            redirect('/register.php');
        }

        $otp   = trim($_POST['otp'] ?? '');
        $email = $_SESSION['reg_pending_email'];
        $ip    = getClientIP();

        if (!rateLimit('reg_otp_' . $ip, 10, 60)) {
            $error = 'Too many attempts. Please wait a minute.';
        } elseif ($otp === '') {
            $error = 'Please enter the verification code.';
        } else {
            $result = verifyRegistrationOTP($email, $otp);
            if ($result['success']) {
                unset($_SESSION['reg_pending_email'], $_SESSION['reg_pending_masked']);
                redirect('/dashboard.php');
            } else {
                $error      = $result['message'];
                $otpPending = true;
            }
        }

        if (!empty($_POST['resend'])) {
            resendRegistrationOTP($email);
            $success    = 'A new code has been sent to your email.';
            $otpPending = true;
        }

    } else {
        // ── Step 1: Registration form ─────────────────────────────────────
        $username  = sanitize($_POST['username'] ?? '');
        $email     = sanitizeEmail($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $fullName  = sanitize($_POST['full_name'] ?? '');
        $ip        = getClientIP();

        if (!rateLimit('register_' . $ip, 5, 300)) {
            $error = 'Too many registration attempts. Please wait 5 minutes.';
        } elseif ($username === '' || $email === '' || $password === '') {
            $error = 'Username, email and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($username) < 3 || strlen($username) > 30) {
            $error = 'Username must be between 3 and 30 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = 'Username may only contain letters, numbers, and underscores.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $result = registerUser($username, $email, $password, $fullName);
            if ($result['success']) {
                $atPos  = strpos($email, '@');
                $masked = $atPos > 1
                    ? substr($email, 0, 1) . '***' . substr($email, $atPos)
                    : '***@***';
                $_SESSION['reg_pending_email']  = $email;
                $_SESSION['reg_pending_masked'] = $masked;
                $otpPending = true;
            } else {
                $error = $result['message'];
            }
        }
    }
}

$csrfToken  = csrfToken();
$theme      = $_COOKIE['theme'] ?? 'dark';
$maskedEmail = $_SESSION['reg_pending_masked'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $otpPending ? 'Verify Email' : 'Create Account' ?> — <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
/* ─── Auth page layout ─────────────────────────────────────────── */
body { min-height: 100vh; display: flex; align-items: stretch; }

.auth-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    min-height: 100vh;
    width: 100%;
}
@media (max-width: 768px) {
    .auth-layout { grid-template-columns: 1fr; }
    .auth-promo  { display: none; }
}

/* ── Left promo panel ── */
.auth-promo {
    background: linear-gradient(145deg, #0a0a1a, #1a0a2e);
    display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
    padding: 4rem 3.5rem;
    position: relative; overflow: hidden;
}
.auth-promo::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 50%, rgba(108,99,255,.3) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 80% 20%, rgba(0,212,255,.15) 0%, transparent 50%);
    pointer-events: none;
}
.promo-inner { position: relative; z-index: 1; }
.promo-brand {
    font-size: 1.5rem; font-weight: 900; margin-bottom: 3rem;
    background: linear-gradient(135deg, #6c63ff, #00d4ff);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.promo-heading { font-size: 2.25rem; font-weight: 900; line-height: 1.2; margin-bottom: 1.25rem; }
.promo-sub { color: #a0a0b0; line-height: 1.7; margin-bottom: 2.5rem; font-size: .95rem; }
.promo-features { list-style: none; display: flex; flex-direction: column; gap: .85rem; }
.promo-features li { display: flex; align-items: center; gap: .75rem; font-size: .9rem; color: #c0c0d0; }
.promo-features li span.check { color: #00ff88; font-size: 1rem; }

/* ── Right form panel ── */
.auth-form-panel {
    display: flex; flex-direction: column; justify-content: center; align-items: center;
    padding: 3rem 2rem;
    background: var(--bg-primary, #0a0a0f);
}
.auth-form-wrap { width: 100%; max-width: 440px; }

.auth-card {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 24px;
    padding: 2.5rem;
    backdrop-filter: blur(20px);
    box-shadow: 0 25px 50px rgba(0,0,0,.4);
}
.auth-header { text-align: center; margin-bottom: 2rem; }
.auth-header h2 { font-size: 1.75rem; font-weight: 800; margin-bottom: .35rem; }
.auth-header p { color: #a0a0b0; font-size: .9rem; }

.otp-digits {
    display: flex; justify-content: center; gap: .6rem; margin-bottom: 1.5rem;
}
.otp-digits input {
    width: 52px; height: 62px; text-align: center; font-size: 1.6rem; font-weight: 800;
    border: 2px solid rgba(255,255,255,.15); border-radius: 12px;
    background: rgba(255,255,255,.04); color: var(--text-primary, #e0e0e0);
    transition: border-color .2s;
}
.otp-digits input:focus { outline: none; border-color: #6c63ff; box-shadow: 0 0 0 3px rgba(108,99,255,.2); }

.resend-row { text-align: center; margin-top: 1rem; font-size: .85rem; color: #606070; }
.resend-row button { background: none; border: none; color: #6c63ff; cursor: pointer; font-size: .85rem; }
.resend-row button:hover { color: #00d4ff; }

.back-link { display: block; text-align: center; margin-top: 1rem; font-size: .85rem; color: #606070; }
.back-link:hover { color: #a78bfa; }

.auth-footer { text-align: center; margin-top: 1.5rem; font-size: .88rem; color: #606070; }
.auth-footer a { color: #6c63ff; }
.auth-footer a:hover { color: #00d4ff; }

.password-strength { height: 4px; border-radius: 2px; margin-top: .3rem; transition: width .3s, background .3s; background: #333; width: 0%; }
.strength-label { font-size: .75rem; color: #606070; margin-top: .25rem; }

/* Progress dots */
.reg-steps {
    display: flex; justify-content: center; align-items: center; gap: .5rem; margin-bottom: 2rem;
}
.reg-step-dot { width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,.15); }
.reg-step-dot.active { background: #6c63ff; box-shadow: 0 0 8px rgba(108,99,255,.6); }
</style>
</head>
<body>

<div class="auth-layout">
    <!-- ── Left promo panel ─────────────────────────────────────────────── -->
    <div class="auth-promo">
        <div class="promo-inner">
            <div class="promo-brand">📧 <?= htmlspecialchars($appName) ?></div>
            <h2 class="promo-heading">Start Sending<br>Smarter Today</h2>
            <p class="promo-sub">Join hundreds of businesses reaching their audience faster with bulk SMS and email marketing on one unified platform.</p>
            <ul class="promo-features">
                <li><span class="check">✓</span> Instant bulk SMS delivery</li>
                <li><span class="check">✓</span> Fair per-page billing — no surprises</li>
                <li><span class="check">✓</span> Email campaigns with open tracking</li>
                <li><span class="check">✓</span> MFA-secured account by default</li>
                <li><span class="check">✓</span> Real-time delivery analytics</li>
                <li><span class="check">✓</span> Custom Sender ID registration</li>
            </ul>
        </div>
    </div>

    <!-- ── Right form panel ─────────────────────────────────────────────── -->
    <div class="auth-form-panel">
        <div class="auth-form-wrap">

            <!-- Step indicator -->
            <div class="reg-steps">
                <div class="reg-step-dot <?= !$otpPending ? 'active' : '' ?>"></div>
                <div class="reg-step-dot <?= $otpPending ? 'active' : '' ?>"></div>
            </div>

            <div class="auth-card">
                <?php if ($otpPending): ?>
                <!-- ── OTP Step ──────────────────────────────────────────────── -->
                <div class="auth-header">
                    <div style="font-size:3rem;margin-bottom:.5rem">📬</div>
                    <h2>Check Your Email</h2>
                    <p>We sent a 6-digit code to<br><strong style="color:#e0e0e0"><?= htmlspecialchars($maskedEmail) ?></strong></p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" id="otpForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="otp_step" value="1">
                    <!-- Hidden single input that aggregates the 6 digit boxes -->
                    <input type="hidden" name="otp" id="otpHidden">

                    <div class="otp-digits" id="otpBoxes">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" maxlength="1" inputmode="numeric" pattern="\d"
                               class="otp-box" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                               data-idx="<?= $i ?>" autofocus="<?= $i === 0 ? 'autofocus' : '' ?>">
                        <?php endfor; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" id="otpBtn">
                        <span class="btn-text">Verify &amp; Create Account</span>
                        <span class="btn-loader" style="display:none">⏳ Verifying...</span>
                    </button>
                </form>

                <div class="resend-row">
                    Didn't receive it?
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="otp_step" value="1">
                        <button type="submit" name="resend" value="1">Resend Code</button>
                    </form>
                </div>

                <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="otp_step" value="1">
                    <button type="submit" name="back_to_register" value="1" class="back-link">← Back to Registration</button>
                </form>

                <?php else: ?>
                <!-- ── Register Form ─────────────────────────────────────────── -->
                <div class="auth-header">
                    <h2>Create Account</h2>
                    <p>Fill in your details to get started</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-group">
                        <label for="full_name">Full Name <span style="color:#606070;font-size:.8rem">(optional)</span></label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                               placeholder="John Doe" autocomplete="name">
                    </div>

                    <div class="form-group">
                        <label for="username">Username <span style="color:red">*</span></label>
                        <input type="text" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               placeholder="johndoe123" autocomplete="username"
                               pattern="[a-zA-Z0-9_]{3,30}" required autofocus>
                        <small style="color:#606070">3–30 characters, letters, numbers and underscores only.</small>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address <span style="color:red">*</span></label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               placeholder="you@example.com" autocomplete="email" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password <span style="color:red">*</span></label>
                        <input type="password" id="password" name="password"
                               autocomplete="new-password" minlength="8"
                               placeholder="Min. 8 characters" required>
                        <div class="password-strength" id="pwStrength"></div>
                        <div class="strength-label" id="pwLabel"></div>
                    </div>

                    <div class="form-group">
                        <label for="password2">Confirm Password <span style="color:red">*</span></label>
                        <input type="password" id="password2" name="password2"
                               autocomplete="new-password" required placeholder="Repeat password">
                    </div>

                    <button type="submit" class="btn btn-primary btn-full" id="regBtn">
                        <span class="btn-text">Continue →</span>
                        <span class="btn-loader" style="display:none">⏳ Sending code...</span>
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="auth-footer">
                Already have an account? <a href="/login.php">Sign In</a>
                &nbsp;·&nbsp;
                <a href="/">← Back to Home</a>
            </div>

        </div>
    </div>
</div>

<script src="/assets/js/app.js"></script>
<script>
<?php if ($otpPending): ?>
// ── OTP digit boxes ────────────────────────────────────────────────────────
(function() {
    const boxes   = document.querySelectorAll('.otp-box');
    const hidden  = document.getElementById('otpHidden');
    const form    = document.getElementById('otpForm');

    function syncHidden() {
        hidden.value = Array.from(boxes).map(b => b.value).join('');
    }

    boxes.forEach((box, i) => {
        box.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            syncHidden();
            if (this.value && i < boxes.length - 1) boxes[i + 1].focus();
        });
        box.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && i > 0) boxes[i - 1].focus();
        });
        box.addEventListener('paste', function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            if (paste.length > 0) {
                paste.split('').forEach((ch, j) => { if (boxes[i + j]) boxes[i + j].value = ch; });
                syncHidden();
                const nextEmpty = Array.from(boxes).findIndex(b => !b.value);
                if (nextEmpty >= 0) boxes[nextEmpty].focus();
                else boxes[boxes.length - 1].focus();
            }
            e.preventDefault();
        });
    });

    form.addEventListener('submit', function(e) {
        syncHidden();
        if (hidden.value.length !== 6) { e.preventDefault(); alert('Please enter the full 6-digit code.'); return; }
        document.querySelector('#otpForm .btn-text').style.display = 'none';
        document.querySelector('#otpForm .btn-loader').style.display = 'inline';
        document.getElementById('otpBtn').disabled = true;
    });
})();
<?php else: ?>
// ── Password strength ──────────────────────────────────────────────────────
(function() {
    const pw      = document.getElementById('password');
    const bar     = document.getElementById('pwStrength');
    const label   = document.getElementById('pwLabel');
    const pw2     = document.getElementById('password2');

    pw.addEventListener('input', function() {
        const v = this.value;
        let score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;

        const labels  = ['', 'Weak', 'Fair', 'Strong', 'Very Strong'];
        const colors  = ['', '#ff4757', '#ffa502', '#00d4ff', '#00ff88'];
        const widths  = ['0%', '25%', '50%', '75%', '100%'];

        bar.style.width      = widths[score] || '0%';
        bar.style.background = colors[score] || '#333';
        label.textContent    = labels[score] || '';
        label.style.color    = colors[score] || '#606070';
    });

    document.getElementById('registerForm').addEventListener('submit', function(e) {
        if (pw.value !== pw2.value) {
            e.preventDefault();
            pw2.style.borderColor = '#ff4757';
            pw2.focus();
            return;
        }
        document.querySelector('.btn-text').style.display = 'none';
        document.querySelector('.btn-loader').style.display = 'inline';
        document.getElementById('regBtn').disabled = true;
    });
})();
<?php endif; ?>
</script>
</body>
</html>
