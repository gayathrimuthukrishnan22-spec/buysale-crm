-- ------------------------------------------------------------
-- Migration v12: client subscription for "Find Matching Stock".
--
-- A Buy/Rental/Lease/Tented-Rental client requirement (properties.is_stock = 0)
-- pays Rs.1000 for a subscription that lets staff "Connect" them to 2 stock
-- matches. Once both are used, the client automatically becomes a 'dead'
-- lead (new status value). Renewing the subscription (another Rs.1000)
-- resets the match count to 0 and brings the client back to 'active'.
--
-- Run this once on existing databases; fresh installs already get these
-- via schema.sql.
-- ------------------------------------------------------------
ALTER TABLE properties
  MODIFY COLUMN status ENUM('active','pending','closed','cancelled','dead') NOT NULL DEFAULT 'active',
  ADD COLUMN subscription_matches_used TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN subscription_renewed_at DATETIME DEFAULT NULL AFTER subscription_matches_used;
