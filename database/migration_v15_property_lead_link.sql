-- ------------------------------------------------------------
-- Migration v15: link website-booking properties back to their Lead's
-- payment record, and gate them behind Payment Verification.
--
-- Previously api/public/register.php (book.html) created the `properties`
-- row with status = 'active' immediately, in parallel with the `leads` row
-- that correctly stayed hidden until the advance payment was verified.
-- That meant a Buy/Sale/Rental entry from the website showed up on the
-- admin Buy/Sale/Rental pages straight away, regardless of whether the
-- payment proof was ever checked.
--
-- Note: 'pending' already exists on this enum as a real, user-selectable
-- business status (see the Status filter / Add-Buyer form on Buy/Sale/
-- Rental pages) and means something else entirely — it is NOT reused here.
-- Instead a new 'payment_verification' status is added specifically for
-- this website-intake gate, so it can be safely hidden from the normal
-- list without touching manually-set "Pending" properties.
--
-- properties.lead_id lets api/leads/verify-payment.php find and update the
-- matching property when an admin verifies or rejects the payment:
--   verify -> property status flips from 'payment_verification' to 'active',
--             AND subscription_paid is set to 1 (the website advance fee
--             IS the ₹1000/₹500 subscription fee, so verifying it here also
--             unlocks "Find Matching Stock" immediately — no separate
--             manual "mark subscription paid" step needed afterwards)
--   reject -> property row is deleted
--
-- Existing rows are left NULL/unchanged — this only changes behaviour for
-- properties created after this migration runs.
-- ------------------------------------------------------------
ALTER TABLE properties
  MODIFY COLUMN status ENUM('active','pending','closed','cancelled','dead','payment_verification') NOT NULL DEFAULT 'active',
  ADD COLUMN lead_id INT UNSIGNED DEFAULT NULL AFTER created_by,
  ADD INDEX idx_lead_id (lead_id),
  ADD CONSTRAINT fk_properties_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL;
