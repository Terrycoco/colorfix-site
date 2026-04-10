START TRANSACTION;

CREATE TABLE IF NOT EXISTS saved_palette_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(128) NOT NULL,
  title VARCHAR(255) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_saved_palette_sets_palette_slug (saved_palette_id, slug),
  KEY idx_saved_palette_sets_palette_order (saved_palette_id, order_index),
  CONSTRAINT fk_saved_palette_sets_palette
    FOREIGN KEY (saved_palette_id) REFERENCES saved_palettes(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
