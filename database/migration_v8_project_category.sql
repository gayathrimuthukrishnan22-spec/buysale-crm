-- ============================================================
-- Property CRM v8 Migration
-- Adds a 'category' column to projects, so each project can be
-- tagged as House Rent Requirement / Commercial Space Requirement /
-- Flat Lease / Flat Selling / Land Selling / General.
-- The Add/Edit Lead form uses this to only show matching projects
-- once a Requirement Category is picked (General projects always show).
-- Run this ONCE against an existing database.
-- Back up your database before running this in production.
-- ============================================================

ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS category
    ENUM('general','house_rent_requirement','commercial_space_requirement','flat_lease','flat_selling','land_selling')
    NOT NULL DEFAULT 'general'
    AFTER location;