START TRANSACTION;

CREATE TABLE IF NOT EXISTS saved_palette_set_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_set_id BIGINT UNSIGNED NOT NULL,
  photo_library_id INT UNSIGNED NULL,
  legacy_saved_palette_photo_id BIGINT UNSIGNED NULL,
  rel_path VARCHAR(512) NULL,
  photo_type VARCHAR(16) NOT NULL DEFAULT 'full',
  trigger_mode VARCHAR(10) NOT NULL DEFAULT 'any',
  trigger_color_id BIGINT UNSIGNED NULL,
  show_in_gallery TINYINT(1) NOT NULL DEFAULT 0,
  use_palette_default_roles TINYINT(1) NOT NULL DEFAULT 1,
  caption VARCHAR(255) NULL,
  alt_text TEXT NULL,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_saved_palette_set_photos_legacy (legacy_saved_palette_photo_id),
  KEY idx_saved_palette_set_photos_set_order (saved_palette_set_id, order_index),
  KEY idx_saved_palette_set_photos_set_type (saved_palette_set_id, photo_type),
  KEY idx_saved_palette_set_photos_photo_library (photo_library_id),
  KEY idx_saved_palette_set_photos_trigger (trigger_color_id),
  CONSTRAINT fk_saved_palette_set_photos_set
    FOREIGN KEY (saved_palette_set_id) REFERENCES saved_palette_sets(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_saved_palette_set_photos_photo_library
    FOREIGN KEY (photo_library_id) REFERENCES photo_library(photo_library_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
