CREATE TABLE IF NOT EXISTS playlist_instance_url_reservations (
  playlist_instance_url_reservation_id INT NOT NULL AUTO_INCREMENT,
  playlist_id INT NOT NULL,
  channel VARCHAR(60) NOT NULL,
  reservation_key VARCHAR(160) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  path VARCHAR(255) NOT NULL,
  public_url VARCHAR(512) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'reserved',
  playlist_instance_id INT NULL,
  metadata_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (playlist_instance_url_reservation_id),
  UNIQUE KEY uq_pi_url_reservations_key (reservation_key),
  UNIQUE KEY uq_pi_url_reservations_slug (slug),
  KEY idx_pi_url_reservations_playlist_channel (playlist_id, channel),
  KEY idx_pi_url_reservations_instance (playlist_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
