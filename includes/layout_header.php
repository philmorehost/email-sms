<?php
// Shared layout header - included by all dashboard/admin pages
if (!isset($pageTitle)) $pageTitle = 'Dashboard';
if (!isset($activePage)) $activePage = '';
if (!isset($user)) $user = getCurrentUser();
$theme      = $_COOKIE['theme'] ?? 'dark';
$isAdmin    = in_array($user['role'] ?? '', ['superadmin', 'admin'], true);
$isSuperAdmin = ($user['role'] ?? '') === 'superadmin';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — <?= defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Marketing Suite' ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-layout">

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <span class="brand-icon">📧</span>
        <span class="brand-name">PhilmoreHost</span>
        <button class="sidebar-toggle" id="sidebarToggle">☰</button>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <span class="nav-section-title">Main</span>
            <a href="/dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span>
            </a>
            <a href="/billing.php" class="nav-item <?= $activePage === 'billing' ? 'active' : '' ?>">
                <span class="nav-icon">💳</span><span class="nav-label">Billings</span>
            </a>
            <a href="/deposit.php" class="nav-item <?= $activePage === 'deposit' ? 'active' : '' ?>">
                <span class="nav-icon">💰</span><span class="nav-label">Deposit Funds</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">SMS</span>
            <a href="/user/sms.php" class="nav-item <?= $activePage === 'sms' ? 'active' : '' ?>">
                <span class="nav-icon">💬</span><span class="nav-label">Send SMS</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Settings</span>
            <a href="/user/settings.php" class="nav-item <?= $activePage === 'settings' ? 'active' : '' ?>">
                <span class="nav-icon">⚙️</span><span class="nav-label">Email Settings</span>
            </a>
        </div>

        <?php if ($isAdmin): ?>
        <div class="nav-section">
            <span class="nav-section-title">Admin — Marketing</span>
            <a href="/admin/email.php" class="nav-item <?= $activePage === 'email' ? 'active' : '' ?>">
                <span class="nav-icon">📧</span><span class="nav-label">Email Marketing</span>
            </a>
            <a href="/admin/sms.php" class="nav-item <?= $activePage === 'admin_sms' ? 'active' : '' ?>">
                <span class="nav-icon">📤</span><span class="nav-label">SMS Campaigns</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Admin — Management</span>
            <a href="/admin/plans.php" class="nav-item <?= $activePage === 'plans' ? 'active' : '' ?>">
                <span class="nav-icon">📦</span><span class="nav-label">Plans &amp; Packages</span>
            </a>
            <a href="/admin/billing.php" class="nav-item <?= $activePage === 'billing_admin' ? 'active' : '' ?>">
                <span class="nav-icon">💰</span><span class="nav-label">Billing &amp; Credits</span>
            </a>
            <a href="/admin/users.php" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
                <span class="nav-icon">👥</span><span class="nav-label">Users</span>
            </a>
            <a href="/admin/contacts.php" class="nav-item <?= $activePage === 'contacts' ? 'active' : '' ?>">
                <span class="nav-icon">📒</span><span class="nav-label">Contacts</span>
            </a>
            <a href="/admin/analytics.php" class="nav-item <?= $activePage === 'analytics' ? 'active' : '' ?>">
                <span class="nav-icon">📊</span><span class="nav-label">Analytics</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Admin — Settings</span>
            <a href="/admin/smtp.php" class="nav-item <?= $activePage === 'smtp' ? 'active' : '' ?>">
                <span class="nav-icon">⚙️</span><span class="nav-label">SMTP &amp; APIs</span>
            </a>
            <a href="/admin/payment-settings.php" class="nav-item <?= $activePage === 'payment_settings' ? 'active' : '' ?>">
                <span class="nav-icon">💳</span><span class="nav-label">Payment Settings</span>
            </a>
            <a href="/admin/security.php" class="nav-item <?= $activePage === 'security' ? 'active' : '' ?>">
                <span class="nav-icon">🛡️</span><span class="nav-label">Security</span>
            </a>
            <a href="/admin/countries.php" class="nav-item <?= $activePage === 'countries' ? 'active' : '' ?>">
                <span class="nav-icon">🌍</span><span class="nav-label">Country Firewall</span>
            </a>
            <a href="/admin/ip-management.php" class="nav-item <?= $activePage === 'ip' ? 'active' : '' ?>">
                <span class="nav-icon">🔐</span><span class="nav-label">IP Management</span>
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <span class="user-avatar"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></span>
            <div>
                <div class="user-name"><?= htmlspecialchars($user['username'] ?? '') ?></div>
                <div class="user-role"><?= htmlspecialchars($user['role'] ?? '') ?></div>
            </div>
        </div>
        <button class="theme-toggle" id="themeToggle" title="Toggle theme">
          <span class="theme-icon"><?= $theme === 'dark' ? '🌙' : '☀️' ?></span>
        </button>
        <a href="/logout.php" class="nav-item nav-logout">
            <span class="nav-icon">🚪</span><span class="nav-label">Logout</span>
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content">
<div class="content-inner">
