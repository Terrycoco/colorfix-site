CREATE TABLE IF NOT EXISTS applied_palettes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  photo_id INT UNSIGNED NOT NULL,
  asset_id VARCHAR(32) NOT NULL,
  title VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  share_token CHAR(12) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_applied_palettes_token (share_token),
  KEY idx_applied_palettes_photo (photo_id),
  KEY idx_applied_palettes_asset (asset_id),
  CONSTRAINT fk_applied_palettes_photo FOREIGN KEY (photo_id)
    REFERENCES photos(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS applied_palette_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  applied_palette_id BIGINT UNSIGNED NOT NULL,
  mask_role VARCHAR(64) NOT NULL,
  color_id INT DEFAULT NULL,
  mask_setting_id BIGINT UNSIGNED DEFAULT NULL,
  mask_setting_revision INT UNSIGNED DEFAULT NULL,
  color_name VARCHAR(120) DEFAULT NULL,
  color_brand VARCHAR(10) DEFAULT NULL,
  color_code VARCHAR(50) DEFAULT NULL,
  color_hex CHAR(6) DEFAULT NULL,
  blend_mode VARCHAR(32) DEFAULT NULL,
  blend_opacity FLOAT DEFAULT NULL,
  shadow_l_offset FLOAT DEFAULT NULL,
  shadow_tint_hex CHAR(7) DEFAULT NULL,
  shadow_tint_opacity FLOAT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applied_entries_palette (applied_palette_id),
  KEY idx_applied_entries_mask (mask_role),
  KEY idx_applied_entries_setting (mask_setting_id),
  CONSTRAINT fk_applied_entries_palette FOREIGN KEY (applied_palette_id)
    REFERENCES applied_palettes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
