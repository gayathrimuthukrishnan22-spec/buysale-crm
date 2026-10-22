-- ============================================================
-- Property CRM v16 Migration
-- Enables the public "Add Property Stock" page (add-property.html)
-- to save directly into `properties` via api/public/property-add.php,
-- the same way book.html saves into `properties` via register.php.
--
-- NOTE: migration_v6_website_property_sync.sql shipped with a bug —
-- it ran `ALTER TABLE propertycrm ...`, but `propertycrm` is the
-- DATABASE name (see config/database.php DB_NAME), not a table. There
-- is no table called `propertycrm`, so that statement would fail with
-- "table doesn't exist" and never actually applied. This migration
-- re-applies the intended change against the correct table, `properties`,
-- and is safe to run whether or not v6 was attempted.
--
-- Run this ONCE against an existing v15 database.
-- Back up your database before running this in production.
-- ============================================================

ALTER TABLE properties
  MODIFY COLUMN created_by INT UNSIGNED DEFAULT NULL;

-- Only add source_channel if migration_v6 didn't already (harmlessly)
-- add it to `properties` some other way. Safe to run even if it
-- already exists, on MySQL 8.0.29+ / MariaDB 10.5.2+.
ALTER TABLE properties
  ADD COLUMN IF NOT EXISTS source_channel VARCHAR(40) DEFAULT NULL AFTER notes;
