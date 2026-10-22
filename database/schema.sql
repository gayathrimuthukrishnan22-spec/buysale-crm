-- ============================================================
-- Property CRM v2 — Enterprise Real Estate CRM
-- Database Schema (MySQL 5.7+/8.0, MariaDB 10.3+)
-- Fresh install. If upgrading an existing v1 database instead,
-- use database/migration_v2.sql against your existing data.
-- ============================================================

CREATE DATABASE IF NOT EXISTS property_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE property_crm;

-- ------------------------------------------------------------
-- 1. USERS (Super Admin / Admin / Manager / Sales Executive / Telecaller / Employee / Accountant)
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) DEFAULT NULL,
    mobile VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','admin','manager','sales_executive','telecaller','employee','accountant')
        NOT NULL DEFAULT 'employee',
    reports_to INT UNSIGNED DEFAULT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    permissions JSON DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reports_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. SESSIONS
-- ------------------------------------------------------------
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    last_activity DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. RBAC: permission catalogue + per-role default grants
-- ------------------------------------------------------------
CREATE TABLE permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(60) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    module VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role ENUM('super_admin','admin','manager','sales_executive','telecaller','employee','accountant') NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    PRIMARY KEY (role, permission_key),
    FOREIGN KEY (permission_key) REFERENCES permissions(`key`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. PROJECTS
-- ------------------------------------------------------------
CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    builder VARCHAR(150) DEFAULT NULL,
    location VARCHAR(200) DEFAULT NULL,
    category ENUM('general','house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling') NOT NULL DEFAULT 'general',
    total_units INT UNSIGNED DEFAULT 0,
    available_units INT UNSIGNED DEFAULT 0,
    sold_units INT UNSIGNED DEFAULT 0,
    rental_units INT UNSIGNED DEFAULT 0,
    status ENUM('active','completed','on_hold') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. PROPERTIES (Buy / Sale / Rental listings)
-- ------------------------------------------------------------
CREATE TABLE properties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_code VARCHAR(20) NOT NULL UNIQUE,
    category ENUM('buy','sale','rental','lease','rental_tenant') NOT NULL,
    is_stock TINYINT(1) NOT NULL DEFAULT 0,
    client_name VARCHAR(100) NOT NULL,
    client_mobile VARCHAR(20) NOT NULL,
    client_email VARCHAR(120) DEFAULT NULL,
    property_type VARCHAR(50) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(80) DEFAULT NULL,
    map_link VARCHAR(500) DEFAULT NULL,
    budget DECIMAL(14,2) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('active','pending','closed','cancelled','dead','payment_verification') NOT NULL DEFAULT 'active',
    subscription_matches_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
    subscription_paid TINYINT(1) NOT NULL DEFAULT 0,
    subscription_renewed_at DATETIME DEFAULT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    -- lead_id -> leads(id) FK is added further down via ALTER TABLE, once
    -- the leads table itself has been created (leads is defined after
    -- properties in this file, so the FK can't be declared inline here).
    INDEX idx_category (category),
    INDEX idx_is_stock (is_stock),
    INDEX idx_status (status),
    INDEX idx_client_mobile (client_mobile),
    INDEX idx_assigned (assigned_to),
    INDEX idx_lead_id (lead_id)
) ENGINE=InnoDB;

-- Which stock property(ies) a client requirement (properties.is_stock = 0) has
-- been "Connected" to via Find Matching Stock. Each row uses up one unit of
-- the client's subscription (see properties.subscription_matches_used).
-- Once a client has any row here, they've been placed with stock and no
-- further match suggestions are shown until the subscription is renewed
-- (which clears these rows so fresh suggestions can appear again).
CREATE TABLE property_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    stock_id INT UNSIGNED NOT NULL,
    connected_by INT UNSIGNED DEFAULT NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (stock_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_client (client_id),
    INDEX idx_stock (stock_id)
) ENGINE=InnoDB;

CREATE TABLE property_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    media_type ENUM('image','video','audio') NOT NULL DEFAULT 'image',
    original_name VARCHAR(255) DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property_media (property_id, media_type)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. LEADS — customer inquiries through to deal closure.
--    Revenue/profit/commission fields double as the "deal" record;
--    when status reaches deal_closed, closed_at + actual_* fields are set.
-- ------------------------------------------------------------
CREATE TABLE leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_code VARCHAR(20) NOT NULL UNIQUE,

    client_name VARCHAR(100) NOT NULL,
    client_mobile VARCHAR(20) NOT NULL,
    whatsapp_number VARCHAR(20) DEFAULT NULL,
    client_email VARCHAR(120) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city VARCHAR(80) DEFAULT NULL,
    state VARCHAR(80) DEFAULT NULL,
    country VARCHAR(80) DEFAULT 'India',
    customer_type ENUM('house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling') DEFAULT 'house_rent_requirement',

    -- Identity & address (collected on the public website booking form)
    dob DATE DEFAULT NULL,
    gender VARCHAR(20) DEFAULT NULL,
    occupation VARCHAR(120) DEFAULT NULL,
    religion VARCHAR(60) DEFAULT NULL,
    caste VARCHAR(60) DEFAULT NULL,
    aadhaar_number VARCHAR(20) DEFAULT NULL,
    pan_number VARCHAR(20) DEFAULT NULL,
    district VARCHAR(80) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,

    requirement ENUM('buy','sale','rental','lease','rental_tenant') NOT NULL,
    property_type VARCHAR(50) DEFAULT NULL,
    budget DECIMAL(14,2) DEFAULT NULL,
    preferred_location VARCHAR(150) DEFAULT NULL,
    property_id INT UNSIGNED DEFAULT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    -- Extra property-requirement fields that vary by requirement type
    -- (BHK, furnished status, loan required, lease duration, etc.), stored as JSON.
    requirement_details LONGTEXT DEFAULT NULL,
    source_channel VARCHAR(40) DEFAULT NULL,

    property_value DECIMAL(14,2) DEFAULT NULL,
    commission_rate DECIMAL(5,2) DEFAULT NULL,
    commission_amount DECIMAL(14,2) DEFAULT NULL,
    expected_revenue DECIMAL(14,2) DEFAULT NULL,
    actual_revenue DECIMAL(14,2) DEFAULT NULL,
    expected_profit DECIMAL(14,2) DEFAULT NULL,
    actual_profit DECIMAL(14,2) DEFAULT NULL,
    employee_incentive DECIMAL(14,2) DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,

    monthly_rent DECIMAL(12,2) DEFAULT NULL,
    security_deposit DECIMAL(12,2) DEFAULT NULL,
    lease_duration_months SMALLINT UNSIGNED DEFAULT NULL,
    owner_name VARCHAR(100) DEFAULT NULL,
    owner_mobile VARCHAR(20) DEFAULT NULL,

    cancelled_reason ENUM(
        'customer_rejected','budget_issue','competitor_won','property_not_available',
        'loan_rejected','employee_delay','no_response','price_issue','documentation_issue','other'
    ) DEFAULT NULL,
    cancelled_reason_notes TEXT DEFAULT NULL,

    assigned_to INT UNSIGNED DEFAULT NULL,
    status ENUM('new','contacted','follow_up','meeting_scheduled','site_visit','negotiation',
                'documentation','booking','deal_closed','cancelled','lost') NOT NULL DEFAULT 'new',
    source VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    next_followup_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_mobile (client_mobile),
    INDEX idx_assigned (assigned_to),
    INDEX idx_followup (next_followup_at),
    INDEX idx_project (project_id),
    INDEX idx_closed_at (closed_at)
) ENGINE=InnoDB;

-- Now that leads exists, add the properties.lead_id FK that couldn't be
-- declared inline back in the properties table (leads didn't exist yet then).
ALTER TABLE properties
    ADD FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 6b. LEAD DOCUMENTS — Aadhaar / PAN / property / payment-proof
--     uploads captured on the public website booking form.
-- ------------------------------------------------------------
CREATE TABLE lead_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(60) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lead (lead_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6c. LEAD PAYMENTS — advance-payment proof from the booking form.
-- ------------------------------------------------------------
CREATE TABLE lead_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) DEFAULT NULL,
    method VARCHAR(30) DEFAULT NULL,
    screenshot_path VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lead (lead_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. FOLLOW-UPS / MEETINGS
-- ------------------------------------------------------------
CREATE TABLE followups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    scheduled_at DATETIME NOT NULL,
    meeting_type ENUM('call','site_visit','office_meeting','other') NOT NULL DEFAULT 'call',
    remarks TEXT DEFAULT NULL,
    outcome ENUM('pending','done','rescheduled','no_response','cancelled') NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED NOT NULL,
    last_modified_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (last_modified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lead (lead_id),
    INDEX idx_scheduled (scheduled_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. ACTIVITY / AUDIT LOG
-- ------------------------------------------------------------
CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','property','followup','user','project') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    field_changed VARCHAR(80) DEFAULT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    performed_by INT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. Seed: permission catalogue + default role grants
-- ------------------------------------------------------------
INSERT INTO permissions (`key`, label, module) VALUES
('leads.view', 'View leads', 'Leads'),
('leads.view_all', 'View all leads (not just own)', 'Leads'),
('leads.create', 'Create leads', 'Leads'),
('leads.edit', 'Edit leads', 'Leads'),
('leads.delete', 'Delete leads', 'Leads'),
('leads.assign', 'Assign / reassign leads', 'Leads'),
('deals.close', 'Close deals & record revenue', 'Deals'),
('deals.cancel', 'Cancel / mark lost deals', 'Deals'),
('properties.view', 'View properties', 'Properties'),
('properties.manage', 'Add / edit / delete properties', 'Properties'),
('projects.view', 'View projects', 'Projects'),
('projects.manage', 'Add / edit / delete projects', 'Projects'),
('followups.manage', 'Schedule & manage follow-ups/meetings', 'Follow-ups'),
('employees.manage', 'Create / edit / disable employee accounts', 'Employees'),
('employees.permissions', 'Customize role & permission overrides', 'Employees'),
('reports.view', 'View reports', 'Reports'),
('revenue.view', 'View revenue / profit / commission figures', 'Revenue'),
('audit.view', 'View audit log', 'Audit');

INSERT INTO role_permissions (role, permission_key) SELECT 'super_admin', `key` FROM permissions;
INSERT INTO role_permissions (role, permission_key) SELECT 'admin', `key` FROM permissions;

INSERT INTO role_permissions (role, permission_key) VALUES
('manager','leads.view'),('manager','leads.view_all'),('manager','leads.create'),('manager','leads.edit'),
('manager','leads.assign'),('manager','deals.close'),('manager','deals.cancel'),
('manager','properties.view'),('manager','properties.manage'),
('manager','projects.view'),('manager','followups.manage'),
('manager','employees.manage'),('manager','reports.view'),('manager','revenue.view'),('manager','audit.view');

INSERT INTO role_permissions (role, permission_key) VALUES
('sales_executive','leads.view'),('sales_executive','leads.create'),('sales_executive','leads.edit'),
('sales_executive','deals.close'),('sales_executive','deals.cancel'),
('sales_executive','properties.view'),('sales_executive','projects.view'),('sales_executive','followups.manage');

INSERT INTO role_permissions (role, permission_key) VALUES
('telecaller','leads.view'),('telecaller','leads.create'),('telecaller','leads.edit'),
('telecaller','followups.manage'),('telecaller','properties.view');

INSERT INTO role_permissions (role, permission_key) VALUES
('employee','leads.view'),('employee','leads.create'),('employee','leads.edit'),
('employee','properties.view'),('employee','followups.manage');

INSERT INTO role_permissions (role, permission_key) VALUES
('accountant','leads.view'),('accountant','properties.view'),('accountant','projects.view'),
('accountant','reports.view'),('accountant','revenue.view');

-- ------------------------------------------------------------
-- 10. Seed: default super admin (username: admin)
-- NOTE: placeholder hash below — run /setup/setup_admin.php once after
-- install to set a real password, then delete that script.
-- ------------------------------------------------------------
INSERT INTO users (name, username, email, mobile, password_hash, role, status)
VALUES ('Administrator', 'admin', 'admin@example.com', '9999999999',
'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'active');

