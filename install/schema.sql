SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Users & Roles
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin','admin','user') DEFAULT 'user',
    is_suspended BOOLEAN DEFAULT FALSE,
    notify_on_login BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- Security Settings
CREATE TABLE IF NOT EXISTS security_settings (
    id INT PRIMARY KEY DEFAULT 1,
    brute_force_period INT DEFAULT 15,
    max_failures_ip INT DEFAULT 5,
    max_failures_user INT DEFAULT 3,
    one_day_block_threshold INT DEFAULT 2,
    one_week_block_threshold INT DEFAULT 5,
    one_month_block_threshold INT DEFAULT 10,
    one_year_block_threshold INT DEFAULT 20,
    notify_admin_on_bf BOOLEAN DEFAULT TRUE,
    protect_admin_accounts BOOLEAN DEFAULT TRUE
);

-- Failed Login Attempts
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_username (username),
    INDEX idx_time (attempted_at)
);

-- IP Whitelist
CREATE TABLE IF NOT EXISTS ip_whitelist (
    ip_address VARCHAR(45) PRIMARY KEY,
    session_count INT DEFAULT 0,
    is_trusted BOOLEAN DEFAULT FALSE,
    label VARCHAR(100),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- IP Blacklist
CREATE TABLE IF NOT EXISTS ip_blacklist (
    ip_address VARCHAR(45) PRIMARY KEY,
    reason VARCHAR(255),
    block_until TIMESTAMP NULL,
    block_type ENUM('permanent','one_day','one_week','one_month','one_year') DEFAULT 'permanent',
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Country Firewall
CREATE TABLE IF NOT EXISTS country_firewall (
    country_code CHAR(2) PRIMARY KEY,
    country_name VARCHAR(100) NOT NULL,
    status ENUM('whitelisted','blacklisted','not_specified') DEFAULT 'not_specified'
);

-- User Blacklist
CREATE TABLE IF NOT EXISTS user_blacklist (
    user_id INT PRIMARY KEY,
    reason VARCHAR(255),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Security Logs
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    username VARCHAR(50),
    country_code CHAR(2),
    details TEXT,
    is_trusted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
);

-- SMTP Settings
CREATE TABLE IF NOT EXISTS smtp_settings (
    id INT PRIMARY KEY DEFAULT 1,
    provider ENUM('smtp','sendgrid','mailgun','ses') DEFAULT 'smtp',
    host VARCHAR(255),
    port INT DEFAULT 587,
    username VARCHAR(255),
    password_encrypted VARCHAR(500),
    encryption ENUM('tls','ssl','none') DEFAULT 'tls',
    from_email VARCHAR(100),
    from_name VARCHAR(100),
    sendgrid_api_key_encrypted VARCHAR(500),
    mailgun_api_key_encrypted VARCHAR(500),
    mailgun_domain VARCHAR(255),
    ses_key_encrypted VARCHAR(500),
    ses_secret_encrypted VARCHAR(500),
    ses_region VARCHAR(50)
);

-- Email Contacts
CREATE TABLE IF NOT EXISTS email_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(30),
    custom_fields JSON,
    group_id INT,
    is_subscribed BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email (email)
);

-- Contact Groups
CREATE TABLE IF NOT EXISTS contact_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Email Templates
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    json_design LONGTEXT,
    html_content LONGTEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Email Campaigns
CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template_id INT,
    group_id INT,
    status ENUM('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    total_recipients INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS Contacts
CREATE TABLE IF NOT EXISTS sms_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100),
    group_id INT,
    is_subscribed BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS Groups
CREATE TABLE IF NOT EXISTS sms_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS Sender IDs
CREATE TABLE IF NOT EXISTS sms_sender_ids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id VARCHAR(11) NOT NULL UNIQUE,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    sample_message TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS Campaigns
CREATE TABLE IF NOT EXISTS sms_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sender_id VARCHAR(11) NOT NULL,
    message TEXT NOT NULL,
    route ENUM('bulk','corporate','global','voice') DEFAULT 'bulk',
    group_id INT,
    status ENUM('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    total_recipients INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS DLR (Delivery Reports)
CREATE TABLE IF NOT EXISTS sms_dlr (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT,
    recipient VARCHAR(30) NOT NULL,
    status VARCHAR(50),
    dlr_timestamp TIMESTAMP NULL,
    message_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PhilmoreSMS API Config
CREATE TABLE IF NOT EXISTS sms_api_config (
    id INT PRIMARY KEY DEFAULT 1,
    api_token_encrypted VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE
);

-- App Settings
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default security settings
INSERT INTO security_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id=1;
INSERT INTO smtp_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id=1;
INSERT INTO sms_api_config (id) VALUES (1) ON DUPLICATE KEY UPDATE id=1;
