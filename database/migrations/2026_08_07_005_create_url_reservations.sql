CREATE TABLE IF NOT EXISTS url_reservation_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_key VARCHAR(80) NOT NULL,
  label VARCHAR(160) NOT NULL,
  resolver_key VARCHAR(80) NOT NULL,
  delivery_mode VARCHAR(20) NOT NULL DEFAULT 'render',
  route_template VARCHAR(255) NULL,
  parameter_schema_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_url_reservation_types_type_key (type_key),
  KEY idx_url_reservation_types_resolver (resolver_key),
  KEY idx_url_reservation_types_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS url_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  reservation_type_id BIGINT UNSIGNED NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  experience_key VARCHAR(50) NULL,
  source_key VARCHAR(80) NULL,
  params_json JSON NULL,
  label VARCHAR(255) NULL,
  og_title VARCHAR(255) NULL,
  og_description TEXT NULL,
  og_image_url VARCHAR(512) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  revoked_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_url_reservations_token (token),
  KEY idx_url_reservations_type_resource (reservation_type_id, resource_id),
  KEY idx_url_reservations_experience (experience_key),
  KEY idx_url_reservations_source (source_key),
  KEY idx_url_reservations_active_expiry (is_active, expires_at),
  CONSTRAINT fk_url_reservations_type
    FOREIGN KEY (reservation_type_id)
    REFERENCES url_reservation_types(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO url_reservation_types
  (type_key, label, resolver_key, delivery_mode, route_template, parameter_schema_json, is_active)
VALUES
  ('project_experience', 'Project Experience', 'project_experience', 'render', NULL, NULL, 1),
  ('color_plan_viewer', 'Color Plan Viewer', 'color_plan_viewer', 'render', NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  resolver_key = VALUES(resolver_key),
  delivery_mode = VALUES(delivery_mode),
  route_template = VALUES(route_template),
  parameter_schema_json = VALUES(parameter_schema_json),
  is_active = VALUES(is_active),
  updated_at = NOW();
