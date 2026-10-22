-- ============================================================
-- Property CRM v4 Migration
-- Replaces the 'customer_type' options on leads with requirement
-- categories: House Rent Requirement, Commercial Space Requirement,
-- Flat Lease, Land Selling.
-- Run this ONCE against an existing v2/v3 database.
-- Back up your database before running this in production.
-- ============================================================

USE property_crm;

ALTER TABLE leads
  MODIFY COLUMN customer_type ENUM('house_rent_requirement','commercial_space_requirement','flat_lease','land_selling')
  DEFAULT 'house_rent_requirement';

-- Existing rows that held an old value (individual/corporate/nri/investor)
-- are reset to the new default so no lead is left with an invalid value.
UPDATE leads
  SET customer_type = 'house_rent_requirement'
  WHERE customer_type NOT IN ('house_rent_requirement','commercial_space_requirement','flat_lease','land_selling')
     OR customer_type IS NULL;
