<?php
// Shared layout header - included by all dashboard/admin pages
if (!isset($pageTitle)) $pageTitle = 'Dashboard';
if (!isset($activePage)) $activePage = '';
if (!isset($user)) $user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
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
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Marketing</span>
            <a href="/admin/email.php" class="nav-item <?= $activePage === 'email' ? 'active' : '' ?>">
                <span class="nav-icon">📧</span><span class="nav-label">Email Marketing</span>
            </a>
            <a href="/admin/sms.php" class="nav-item <?= $activePage === 'sms' ? 'active' : '' ?>">
                <span class="nav-icon">💬</span><span class="nav-label">SMS Marketing</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Settings</span>
            <a href="/admin/smtp.php" class="nav-item <?= $activePage === 'smtp' ? 'active' : '' ?>">
                <span class="nav-icon">⚙️</span><span class="nav-label">SMTP & APIs</span>
            </a>
            <a href="/admin/users.php" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
                <span class="nav-icon">👥</span><span class="nav-label">Users</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">Security</span>
            <a href="/admin/security.php" class="nav-item <?= $activePage === 'security' ? 'active' : '' ?>">
                <span class="nav-icon">🛡️</span><span class="nav-label">Security Settings</span>
            </a>
            <a href="/admin/countries.php" class="nav-item <?= $activePage === 'countries' ? 'active' : '' ?>">
                <span class="nav-icon">🌍</span><span class="nav-label">Country Firewall</span>
            </a>
            <a href="/admin/ip-management.php" class="nav-item <?= $activePage === 'ip' ? 'active' : '' ?>">
                <span class="nav-icon">🔐</span><span class="nav-label">IP Management</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <span class="user-avatar"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></span>
            <div>
                <div class="user-name"><?= htmlspecialchars($user['username'] ?? '') ?></div>
                <div class="user-role"><?= htmlspecialchars($user['role'] ?? '') ?></div>
            </div>
        </div>
        <a href="/logout.php" class="nav-item nav-logout">
            <span class="nav-icon">🚪</span><span class="nav-label">Logout</span>
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content">
<div class="content-inner">
