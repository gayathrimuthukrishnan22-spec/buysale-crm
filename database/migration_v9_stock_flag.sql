-- ------------------------------------------------------------
-- Migration v9: separate "Our Stock" (owner-supplied inventory)
-- from the existing lead/requirement tracking on the properties table.
--
-- Without this flag, an owner's rental/lease listing entered via
-- "Our Stock" would land in the same category bucket as tenant
-- requirement leads (properties-rental.php / properties-lease.php),
-- mixing supply data with demand data. Run this once on existing
-- databases; fresh installs already get the column via schema.sql.
-- ------------------------------------------------------------
ALTER TABLE properties
  ADD COLUMN is_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER category,
  ADD INDEX idx_is_stock (is_stock);
