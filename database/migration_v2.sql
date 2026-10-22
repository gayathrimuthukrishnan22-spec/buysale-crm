-- ============================================================
-- Property CRM v2 Migration
-- Upgrades an existing v1 install (roles: admin/employee) to v2:
--   - Expanded RBAC (6 roles + granular permission overrides)
--   - Projects module
--   - Deal / revenue / profit / commission fields on leads
--   - Extended lead workflow status + cancelled/lost reasons
--   - Extended lead contact/source/customer fields
-- Run this ONCE against an existing database. Safe to re-run
-- (uses IF NOT EXISTS / conditional checks where MySQL allows).
-- Back up your database before running this in production.
-- ============================================================

USE property_crm;

-- ------------------------------------------------------------
-- 1. Expand user roles + add manager hierarchy
-- ------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','manager','sales_executive','telecaller','employee','accountant')
  NOT NULL DEFAULT 'employee';

-- Reassign existing v1 'admin' -> 'admin' (unchanged) and 'employee' -> 'employee' (unchanged); no data change needed.

ALTER TABLE users ADD COLUMN IF NOT EXISTS reports_to INT UNSIGNED DEFAULT NULL AFTER role;
-- Run once: if this FK already exists on re-run, drop it first or skip this line.
ALTER TABLE users ADD CONSTRAINT fk_users_reports_to FOREIGN KEY (reports_to) REFERENCES users(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 2. Permission catalogue + per-role defaults (RBAC)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(60) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    module VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role ENUM('super_admin','admin','manager','sales_executive','telecaller','employee','accountant') NOT NULL,
    permission_key VARCHAR(60) NOT NULL,
    PRIMARY KEY (role, permission_key),
    FOREIGN KEY (permission_key) REFERENCES permissions(`key`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Permission catalogue
INSERT IGNORE INTO permissions (`key`, label, module) VALUES
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

-- Default grants per role
INSERT IGNORE INTO role_permissions (role, permission_key)
SELECT 'super_admin', `key` FROM permissions;

INSERT IGNORE INTO role_permissions (role, permission_key)
SELECT 'admin', `key` FROM permissions; -- admin = full access, same as super_admin, minus nothing for now

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('manager','leads.view'),('manager','leads.view_all'),('manager','leads.create'),('manager','leads.edit'),
('manager','leads.assign'),('manager','deals.close'),('manager','deals.cancel'),
('manager','properties.view'),('manager','properties.manage'),
('manager','projects.view'),('manager','followups.manage'),
('manager','employees.manage'),('manager','reports.view'),('manager','revenue.view'),('manager','audit.view');

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('sales_executive','leads.view'),('sales_executive','leads.create'),('sales_executive','leads.edit'),
('sales_executive','deals.close'),('sales_executive','deals.cancel'),
('sales_executive','properties.view'),('sales_executive','projects.view'),('sales_executive','followups.manage');

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('telecaller','leads.view'),('telecaller','leads.create'),('telecaller','leads.edit'),
('telecaller','followups.manage'),('telecaller','properties.view');

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('employee','leads.view'),('employee','leads.create'),('employee','leads.edit'),
('employee','properties.view'),('employee','followups.manage');

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
('accountant','leads.view'),('accountant','properties.view'),('accountant','projects.view'),
('accountant','reports.view'),('accountant','revenue.view');

-- ------------------------------------------------------------
-- 3. Projects module
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    builder VARCHAR(150) DEFAULT NULL,
    location VARCHAR(200) DEFAULT NULL,
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
-- 4. Extend LEADS: contact/source/customer fields + project link
-- ------------------------------------------------------------
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(20) DEFAULT NULL AFTER client_mobile,
  ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL AFTER client_email,
  ADD COLUMN IF NOT EXISTS city VARCHAR(80) DEFAULT NULL AFTER address,
  ADD COLUMN IF NOT EXISTS state VARCHAR(80) DEFAULT NULL AFTER city,
  ADD COLUMN IF NOT EXISTS country VARCHAR(80) DEFAULT 'India' AFTER state,
  ADD COLUMN IF NOT EXISTS customer_type ENUM('individual','corporate','nri','investor') DEFAULT 'individual' AFTER country,
  ADD COLUMN IF NOT EXISTS project_id INT UNSIGNED DEFAULT NULL AFTER property_id;

-- Run once: if this FK already exists on re-run, drop it first or skip this line.
ALTER TABLE leads ADD CONSTRAINT fk_leads_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 5. Extend LEADS: full status workflow (replaces short v1 workflow)
--    Existing values map forward: converted -> deal_closed
-- ------------------------------------------------------------
ALTER TABLE leads MODIFY COLUMN status
  ENUM('new','contacted','follow_up','meeting_scheduled','site_visit','negotiation',
       'documentation','booking','deal_closed','cancelled','lost')
  NOT NULL DEFAULT 'new';

UPDATE leads SET status = 'deal_closed' WHERE status = 'converted';

-- ------------------------------------------------------------
-- 6. Extend LEADS: deal / revenue / profit / commission fields
-- ------------------------------------------------------------
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS property_value DECIMAL(14,2) DEFAULT NULL AFTER budget,
  ADD COLUMN IF NOT EXISTS commission_rate DECIMAL(5,2) DEFAULT NULL COMMENT 'percent' AFTER property_value,
  ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(14,2) DEFAULT NULL AFTER commission_rate,
  ADD COLUMN IF NOT EXISTS expected_revenue DECIMAL(14,2) DEFAULT NULL AFTER commission_amount,
  ADD COLUMN IF NOT EXISTS actual_revenue DECIMAL(14,2) DEFAULT NULL AFTER expected_revenue,
  ADD COLUMN IF NOT EXISTS expected_profit DECIMAL(14,2) DEFAULT NULL AFTER actual_revenue,
  ADD COLUMN IF NOT EXISTS actual_profit DECIMAL(14,2) DEFAULT NULL AFTER expected_profit,
  ADD COLUMN IF NOT EXISTS employee_incentive DECIMAL(14,2) DEFAULT NULL AFTER actual_profit,
  ADD COLUMN IF NOT EXISTS closed_at DATETIME DEFAULT NULL AFTER employee_incentive;

-- Rental-specific deal fields
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS monthly_rent DECIMAL(12,2) DEFAULT NULL AFTER closed_at,
  ADD COLUMN IF NOT EXISTS security_deposit DECIMAL(12,2) DEFAULT NULL AFTER monthly_rent,
  ADD COLUMN IF NOT EXISTS lease_duration_months SMALLINT UNSIGNED DEFAULT NULL AFTER security_deposit,
  ADD COLUMN IF NOT EXISTS owner_name VARCHAR(100) DEFAULT NULL AFTER lease_duration_months,
  ADD COLUMN IF NOT EXISTS owner_mobile VARCHAR(20) DEFAULT NULL AFTER owner_name;

-- Cancelled / Lost reason tracking
ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS cancelled_reason ENUM(
      'customer_rejected','budget_issue','competitor_won','property_not_available',
      'loan_rejected','employee_delay','no_response','price_issue','documentation_issue','other'
  ) DEFAULT NULL AFTER owner_mobile;

ALTER TABLE leads ADD COLUMN IF NOT EXISTS cancelled_reason_notes TEXT DEFAULT NULL AFTER cancelled_reason;

ALTER TABLE leads
  ADD INDEX IF NOT EXISTS idx_project (project_id),
  ADD INDEX IF NOT EXISTS idx_closed_at (closed_at);

-- ------------------------------------------------------------
-- 7. Rename `source` -> keep as lead_source alias for spec wording (no data change, just documented)
--    (v1 already has `source VARCHAR(50)`; spec calls it "Lead Source" - same field, no migration needed.)
-- ------------------------------------------------------------
