-- ============================================================
-- Property CRM v5 Migration
-- Adds everything needed to receive registrations from the public
-- booking website (buysalerentalproperty.com/book.html) as Leads:
--   - identity & address fields on `leads`
--   - `lease` as a valid requirement value
--   - requirement_details JSON + source_channel on `leads`
--   - `lead_documents` and `lead_payments` tables
--   - makes `leads.created_by` nullable (public submissions have no
--     logged-in employee/admin)
-- Run this ONCE against an existing v2/v3/v4 database.
-- Back up your database before running this in production.
-- ============================================================

USE property_crm;

ALTER TABLE leads
  MODIFY COLUMN requirement ENUM('buy','sale','rental','lease') NOT NULL,
  MODIFY COLUMN created_by INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dob DATE DEFAULT NULL AFTER customer_type,
  ADD COLUMN IF NOT EXISTS gender VARCHAR(20) DEFAULT NULL AFTER dob,
  ADD COLUMN IF NOT EXISTS occupation VARCHAR(120) DEFAULT NULL AFTER gender,
  ADD COLUMN IF NOT EXISTS religion VARCHAR(60) DEFAULT NULL AFTER occupation,
  ADD COLUMN IF NOT EXISTS caste VARCHAR(60) DEFAULT NULL AFTER religion,
  ADD COLUMN IF NOT EXISTS aadhaar_number VARCHAR(20) DEFAULT NULL AFTER caste,
  ADD COLUMN IF NOT EXISTS pan_number VARCHAR(20) DEFAULT NULL AFTER aadhaar_number,
  ADD COLUMN IF NOT EXISTS district VARCHAR(80) DEFAULT NULL AFTER pan_number,
  ADD COLUMN IF NOT EXISTS pincode VARCHAR(10) DEFAULT NULL AFTER district,
  ADD COLUMN IF NOT EXISTS requirement_details LONGTEXT DEFAULT NULL AFTER project_id,
  ADD COLUMN IF NOT EXISTS source_channel VARCHAR(40) DEFAULT NULL AFTER requirement_details;

-- Drop the old RESTRICT FK on created_by and recreate as SET NULL, if it
-- still has its original name from schema.sql. Skip this block if your
-- FK already has a different name / was already altered.
-- (Run manually if the ALTER above reports a duplicate-FK error.)

CREATE TABLE IF NOT EXISTS lead_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(60) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lead (lead_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lead_payments (
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
