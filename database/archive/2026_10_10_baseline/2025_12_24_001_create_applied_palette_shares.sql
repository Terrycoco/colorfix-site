CREATE TABLE IF NOT EXISTS applied_palette_shares (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  applied_palette_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('sms','email') NOT NULL DEFAULT 'sms',
  target_phone VARCHAR(32) DEFAULT NULL,
  target_email VARCHAR(255) DEFAULT NULL,
  note TEXT DEFAULT NULL,
  share_url VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_applied_palette_shares_palette (applied_palette_id),
  KEY idx_applied_palette_shares_client (client_id),
  CONSTRAINT fk_applied_palette_shares_palette FOREIGN KEY (applied_palette_id)
    REFERENCES applied_palettes(id) ON DELETE CASCADE,
  CONSTRAINT fk_applied_palette_shares_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
