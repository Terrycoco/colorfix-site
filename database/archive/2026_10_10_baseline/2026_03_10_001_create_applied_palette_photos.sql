-- 2026_03_10_001_create_applied_palette_photos.sql
-- Photos attached to applied palettes.

CREATE TABLE IF NOT EXISTS applied_palette_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  applied_palette_id BIGINT UNSIGNED NOT NULL,
  rel_path VARCHAR(512) NOT NULL,
  photo_type VARCHAR(16) NOT NULL DEFAULT 'full',
  trigger_mode VARCHAR(10) NOT NULL DEFAULT 'any',
  trigger_color_id BIGINT UNSIGNED NULL,
  caption VARCHAR(255) NULL,
  alt_text TEXT NULL,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applied_palette_photos_palette (applied_palette_id),
  KEY idx_applied_palette_photos_trigger (trigger_color_id),
  CONSTRAINT fk_applied_palette_photos_palette FOREIGN KEY (applied_palette_id)
    REFERENCES applied_palettes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
