CREATE TABLE IF NOT EXISTS rex_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(255) NOT NULL,
  resolver_key VARCHAR(120) NOT NULL,
  resource_type VARCHAR(120) NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  context_json JSON NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rex_reservations_token (token),
  KEY idx_rex_reservations_resource (resource_type, resource_id),
  KEY idx_rex_reservations_resolver (resolver_key),
  KEY idx_rex_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rex_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  alias VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rex_aliases_alias (alias),
  KEY idx_rex_aliases_reservation (reservation_id),
  CONSTRAINT fk_rex_aliases_reservation
    FOREIGN KEY (reservation_id)
    REFERENCES rex_reservations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
