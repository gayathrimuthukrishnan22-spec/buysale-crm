-- ------------------------------------------------------------
-- Migration v10: add "rental_tented" as its own property category.
--
-- Previously "Rental" was a single category. This adds a second,
-- separate category ("Rental Tented") so the two save into their
-- own buckets instead of sharing one list. Run this once on
-- existing databases; fresh installs already get it via schema.sql.
-- ------------------------------------------------------------
ALTER TABLE properties
  MODIFY COLUMN category ENUM('buy','sale','rental','lease','rental_tented') NOT NULL;
