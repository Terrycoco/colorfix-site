-- 2025_11_23_003_create_saved_palettes.sql
-- Adds curated saved palettes + members + view tracking (no users FK)

CREATE TABLE IF NOT EXISTS saved_palettes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  palette_hash CHAR(64) NOT NULL,
  brand CHAR(4) NULL,
  client_id BIGINT UNSIGNED NULL,
  nickname VARCHAR(255) NULL,
  notes TEXT NULL,
  terry_fav TINYINT(1) NOT NULL DEFAULT 0,
  sent_to_email VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_saved_palettes_hash (palette_hash),
  KEY idx_saved_palettes_brand (brand),
  KEY idx_saved_palettes_email (sent_to_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_palette_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_id BIGINT UNSIGNED NOT NULL,
  color_id BIGINT UNSIGNED NOT NULL,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_palette_views (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_id BIGINT UNSIGNED NOT NULL,
  viewer_email VARCHAR(255) NULL,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
