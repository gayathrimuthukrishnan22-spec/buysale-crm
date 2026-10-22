-- ============================================================
-- Property CRM v7 Migration
-- Adds 'Flat Selling' as a Requirement Category option on leads
-- (alongside House Rent Requirement, Commercial Space Requirement,
-- Flat Lease, Land Selling).
-- Run this ONCE against an existing v4+ database.
-- Back up your database before running this in production.
-- ============================================================
ALTER TABLE leads
  MODIFY COLUMN customer_type ENUM('house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling')
  DEFAULT 'house_rent_requirement';