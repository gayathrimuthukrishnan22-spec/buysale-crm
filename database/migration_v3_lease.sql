-- ============================================================
-- Property CRM v3 Migration
-- Adds a 4th property category: 'lease' (alongside buy/sale/rental)
-- Run this ONCE against an existing v2 database.
-- Back up your database before running this in production.
-- ============================================================

USE property_crm;

ALTER TABLE properties
  MODIFY COLUMN category ENUM('buy','sale','rental','lease') NOT NULL;
