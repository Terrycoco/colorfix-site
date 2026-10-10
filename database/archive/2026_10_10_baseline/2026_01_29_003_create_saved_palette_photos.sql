-- 2026_01_29_003_create_saved_palette_photos.sql
-- Photos attached to saved palettes.

CREATE TABLE IF NOT EXISTS saved_palette_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_id BIGINT UNSIGNED NOT NULL,
  rel_path VARCHAR(512) NOT NULL,
  caption VARCHAR(255) NULL,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_saved_palette_photos_palette (saved_palette_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
