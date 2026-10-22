-- ============================================================
-- Property CRM v6 Migration
-- Allows the public booking website (book.html) to also create a
-- matching row in `properties` (Buy/Sale/Rental/Lease pages), not
-- just in `leads`. Public submissions have no logged-in employee,
-- so `created_by` must be nullable here too (same reasoning as the
-- v5 migration for leads.created_by).
-- Run this ONCE against an existing v5 database.
-- Back up your database before running this in production.
-- ============================================================

ALTER TABLE propertycrm
  MODIFY COLUMN created_by INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS source_channel VARCHAR(40) DEFAULT NULL AFTER notes;