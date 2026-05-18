CREATE TABLE IF NOT EXISTS client_activity (
  client_activity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id BIGINT UNSIGNED NOT NULL,
  activity_type VARCHAR(60) NOT NULL,
  summary VARCHAR(255) NULL DEFAULT NULL,
  details MEDIUMTEXT NULL,
  related_client_email_id BIGINT UNSIGNED NULL DEFAULT NULL,
  metadata_json MEDIUMTEXT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_activity_id),
  KEY idx_client_activity_client (client_id),
  KEY idx_client_activity_type (activity_type),
  KEY idx_client_activity_occurred_at (occurred_at),
  KEY idx_client_activity_email (related_client_email_id),
  CONSTRAINT fk_client_activity_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_client_activity_email
    FOREIGN KEY (related_client_email_id) REFERENCES client_emails(client_email_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
