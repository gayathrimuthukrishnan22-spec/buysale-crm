-- ------------------------------------------------------------
-- Migration v14: explicit "has the client paid the ₹1000 subscription"
-- flag, checked manually at registration time (or afterwards via the
-- "Mark Subscription Paid" quick action).
--
-- Previously every new Buy/Rental/Lease/Tented-Rental lead could use
-- "Find Matching Stock" / "Connect" immediately, with no record of whether
-- the ₹1000 had actually been collected — the system just silently assumed
-- it had. This adds a real flag: matching stays off for a lead until
-- someone explicitly confirms payment, either by ticking "Subscription
-- Paid" on the Add form or using "Mark Subscription Paid" later.
--
-- Existing leads are set to subscription_paid = 1 on this migration so
-- behaviour for anything already in the system is unchanged — the new
-- "must confirm payment first" rule only applies to leads created after
-- this migration runs.
--
-- Run this once on existing databases; fresh installs already get this
-- via schema.sql.
-- ------------------------------------------------------------
ALTER TABLE properties
  ADD COLUMN subscription_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_matches_used;

UPDATE properties
   SET subscription_paid = 1
 WHERE is_stock = 0 AND category IN ('buy','rental','lease','rental_tented');
