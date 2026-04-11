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
    mfa_enabled BOOLEAN DEFAULT TRUE,
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
    provider ENUM('smtp','sendgrid','mailgun','ses','resend','postmark','brevo','mailjet','aweber') DEFAULT 'smtp',
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
    ses_region VARCHAR(50),
    resend_api_key_encrypted VARCHAR(500),
    postmark_api_key_encrypted VARCHAR(500),
    brevo_api_key_encrypted VARCHAR(500),
    mailjet_api_key_encrypted VARCHAR(500),
    mailjet_secret_key_encrypted VARCHAR(500),
    aweber_access_token_encrypted VARCHAR(500),
    aweber_account_id VARCHAR(100),
    aweber_list_id VARCHAR(100)
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
    user_id INT NULL,
    sender_id VARCHAR(11) NOT NULL UNIQUE,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    sample_message TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sid_user (user_id)
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

-- OTP Verification (for email MFA login)
CREATE TABLE IF NOT EXISTS login_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_otp (user_id, otp_code)
);

-- Trusted Devices (30-day remember)
CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_token (device_token),
    INDEX idx_user_id (user_id)
);

-- Email Subscription Plans
CREATE TABLE IF NOT EXISTS email_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monthly_email_limit INT NOT NULL DEFAULT 1000,
    emails_per_hour INT NOT NULL DEFAULT 0,
    is_special BOOLEAN NOT NULL DEFAULT FALSE,
    allowed_providers JSON NULL,
    features JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User Subscriptions to Email Plans
CREATE TABLE IF NOT EXISTS user_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('active','cancelled','expired') DEFAULT 'active',
    emails_used INT DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY unique_user_plan (user_id)
);

-- SMS Credit Packages
CREATE TABLE IF NOT EXISTS sms_credit_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    credits INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    billing_period ENUM('one_time','monthly','quarterly','yearly') NOT NULL DEFAULT 'one_time',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User SMS Credit Wallet
CREATE TABLE IF NOT EXISTS user_sms_wallet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    credits DECIMAL(12,2) DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- SMS Credit Transactions
CREATE TABLE IF NOT EXISTS sms_credit_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    description VARCHAR(255),
    reference VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_tx (user_id)
);

-- API Keys (for external API access)
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    api_secret VARCHAR(64) NOT NULL,
    permissions JSON,
    is_active BOOLEAN DEFAULT TRUE,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_key (api_key)
);

-- Campaign Analytics (email opens, clicks, delivery)
CREATE TABLE IF NOT EXISTS email_campaign_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    recipient_email VARCHAR(100),
    event_type ENUM('sent','delivered','opened','clicked','bounced','unsubscribed','spam') NOT NULL,
    event_data JSON,
    event_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    INDEX idx_event_type (event_type)
);

-- Unsubscribe List (email)
CREATE TABLE IF NOT EXISTS email_unsubscribes (
    email VARCHAR(100) PRIMARY KEY,
    reason VARCHAR(255),
    unsubscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Scheduled Jobs (for send-later campaigns)
CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type ENUM('email_campaign','sms_campaign') NOT NULL,
    campaign_id INT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    status ENUM('pending','running','done','failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheduled (scheduled_at, status)
);

-- Caller IDs
CREATE TABLE IF NOT EXISTS sms_caller_ids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_id VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SMS Credit Purchase Requests (user-initiated, admin-approved)
CREATE TABLE IF NOT EXISTS sms_purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    package_id INT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    notes VARCHAR(255),
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    processed_by INT NULL,
    INDEX idx_user_req (user_id),
    INDEX idx_status_req (status)
);

-- Default SMS price per unit/page and other app settings defaults
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('sms_price_per_unit', '6.50'),
    ('currency_symbol', '₦'),
    ('currency_name', 'Naira'),
    ('deposit_fee_percent', '0'),
    ('payhub_api_key', ''),
    ('payhub_secret_key', ''),
    ('payhub_enabled', '0'),
    ('virtual_bank_enabled', '0'),
    ('manual_transfer_enabled', '0'),
    ('bank_account_name', ''),
    ('bank_account_number', ''),
    ('bank_name', ''),
    ('bank_transfer_charges', '0'),
    ('bank_transfer_note', ''),
    -- Flutterwave
    ('flutterwave_public_key', ''),
    ('flutterwave_secret_key', ''),
    ('flutterwave_enabled', '0'),
    ('flutterwave_fee_percent', '1.4'),
    -- PayPal
    ('paypal_client_id', ''),
    ('paypal_client_secret', ''),
    ('paypal_mode', 'sandbox'),
    ('paypal_enabled', '0'),
    ('paypal_fee_percent', '3.49'),
    -- Stripe
    ('stripe_publishable_key', ''),
    ('stripe_secret_key', ''),
    ('stripe_enabled', '0'),
    ('stripe_fee_percent', '2.9'),
    -- Plisio
    ('plisio_api_key', ''),
    ('plisio_currency', 'BTC'),
    ('plisio_enabled', '0'),
    ('plisio_fee_percent', '0.5'),
    -- Hidden FX markup for USD gateways (admin only, N0-N100)
    ('fx_markup_ngn', '0'),
    -- DeepSeek AI integration
    ('deepseek_api_key', ''),
    ('deepseek_model', 'deepseek-chat'),
    ('ai_tokens_per_generation', '50'),
    ('ai_tokens_per_chat_1k', '10')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- Wallet Deposits (all deposit methods)
CREATE TABLE IF NOT EXISTS wallet_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    method VARCHAR(30) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL,
    fee DECIMAL(12,2) DEFAULT 0.00,
    net_amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending','completed','failed','rejected') DEFAULT 'pending',
    reference VARCHAR(100),
    payhub_txn_id VARCHAR(100),
    bank_transfer_proof TEXT,
    admin_note VARCHAR(255),
    processed_by INT NULL,
    usd_amount DECIMAL(12,4) NULL DEFAULT NULL,
    exchange_rate DECIMAL(12,4) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_user_deposit (user_id),
    INDEX idx_status_deposit (status)
);

-- Virtual Bank Accounts (Payhub generated)
CREATE TABLE IF NOT EXISTS virtual_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    account_name VARCHAR(150),
    account_number VARCHAR(30),
    bank_name VARCHAR(100),
    customer_id VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Email Verification OTPs (for public registration)
CREATE TABLE IF NOT EXISTS email_verification_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT '',
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_otp (email, otp_code)
);

-- AI Token Packages (admin-created credit packages for AI features)
CREATE TABLE IF NOT EXISTS ai_token_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    tokens INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Per-user AI Token Balance
CREATE TABLE IF NOT EXISTS user_ai_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    balance INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_uat_user (user_id)
);

-- AI Token Ledger (audit log of every deduction and purchase)
CREATE TABLE IF NOT EXISTS ai_token_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    delta INT NOT NULL,
    action ENUM('purchase','generate','chat','refund','admin_grant') NOT NULL,
    template_id INT NULL,
    campaign_id INT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_atl_user (user_id),
    INDEX idx_atl_action (action)
);

-- Per-user email server settings (for special email plan subscribers)
CREATE TABLE IF NOT EXISTS user_smtp_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'My SMTP',
    provider ENUM('smtp','sendgrid','mailgun','ses','resend','postmark','brevo') NOT NULL DEFAULT 'smtp',
    is_active BOOLEAN DEFAULT FALSE,
    smtp_host VARCHAR(255),
    smtp_port SMALLINT DEFAULT 587,
    smtp_username VARCHAR(255),
    smtp_password_enc TEXT,
    smtp_encryption ENUM('tls','ssl','none') DEFAULT 'tls',
    from_name VARCHAR(100),
    from_email VARCHAR(100),
    api_key_enc TEXT,
    extra_json JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uss_user (user_id)
);
