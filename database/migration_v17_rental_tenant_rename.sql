-- ------------------------------------------------------------
-- Migration v17: rename the "rental_tented" property category to
-- "rental_tenant".
--
-- "Tented Rental" was always meant to be "Tenant Rental" (a client
-- looking to rent a house, i.e. a tenant) — not an event/marriage-tent
-- booking service. The wrong word was used everywhere: the category
-- value itself, page titles, menu label, and the public website's
-- service option. This migration fixes it at the data level; run
-- this once on existing databases. Fresh installs already get the
-- corrected value via schema.sql.
--
-- IMPORTANT: run this AFTER deploying the updated PHP files (which
-- already expect 'rental_tenant'), and take a DB backup first.
-- ------------------------------------------------------------

-- 1. Widen the enum to accept both old and new values, so the UPDATE
--    below doesn't fail on existing 'rental_tented' rows.
ALTER TABLE properties
  MODIFY COLUMN category ENUM('buy','sale','rental','lease','rental_tented','rental_tenant') NOT NULL;

-- 2. Migrate existing rows to the corrected value.
UPDATE properties SET category = 'rental_tenant' WHERE category = 'rental_tented';

-- 3. Drop the old value now that no row uses it.
ALTER TABLE properties
  MODIFY COLUMN category ENUM('buy','sale','rental','lease','rental_tenant') NOT NULL;

-- 4. leads.requirement never had this category at all (pre-existing bug,
--    not introduced by this rename) — every website booking for this
--    service was getting truncated/rejected at the leads table. Add it.
ALTER TABLE leads
  MODIFY COLUMN requirement ENUM('buy','sale','rental','lease','rental_tenant') NOT NULL;
