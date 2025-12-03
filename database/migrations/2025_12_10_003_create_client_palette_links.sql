CREATE TABLE IF NOT EXISTS client_applied_palettes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  applied_palette_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('owner','viewer','shared') DEFAULT 'owner',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_palette (client_id, applied_palette_id),
  KEY idx_cap_client (client_id),
  KEY idx_cap_palette (applied_palette_id),
  CONSTRAINT fk_cap_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_cap_palette FOREIGN KEY (applied_palette_id)
    REFERENCES applied_palettes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
