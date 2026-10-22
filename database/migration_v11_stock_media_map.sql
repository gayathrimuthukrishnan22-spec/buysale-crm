-- ------------------------------------------------------------
-- Migration v11: "Our Stock" media (image/video/audio) + map pin link.
--
-- Adds a Google Maps link to a stock entry (properties.map_link) and
-- extends property_images so it can hold images, videos and audio
-- files for a property/stock entry, not just images. Run this once
-- on existing databases; fresh installs already get these via
-- schema.sql.
-- ------------------------------------------------------------
ALTER TABLE properties
  ADD COLUMN map_link VARCHAR(500) DEFAULT NULL AFTER city;

ALTER TABLE property_images
  ADD COLUMN media_type ENUM('image','video','audio') NOT NULL DEFAULT 'image' AFTER file_path,
  ADD COLUMN original_name VARCHAR(255) DEFAULT NULL AFTER media_type,
  ADD INDEX idx_property_media (property_id, media_type);
