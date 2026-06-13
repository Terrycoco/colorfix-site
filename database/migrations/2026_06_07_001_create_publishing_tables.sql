CREATE TABLE IF NOT EXISTS publish_jobs (
  publish_job_id INT NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(40) NOT NULL DEFAULT 'playlist',
  source_id INT NOT NULL,
  playlist_instance_id INT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publish_job_id),
  KEY idx_publish_jobs_source (source_type, source_id),
  KEY idx_publish_jobs_status (status),
  KEY idx_publish_jobs_playlist_instance (playlist_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publish_outputs (
  publish_output_id INT NOT NULL AUTO_INCREMENT,
  publish_job_id INT NOT NULL,
  channel_key VARCHAR(80) NOT NULL,
  output_type VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  title VARCHAR(255) NULL,
  description TEXT NULL,
  tracking_code VARCHAR(120) NOT NULL,
  tracking_url VARCHAR(500) NULL,
  destination_url VARCHAR(500) NULL,
  external_url VARCHAR(500) NULL,
  asset_path VARCHAR(500) NULL,
  metadata_json MEDIUMTEXT NULL,
  generated_at DATETIME NULL,
  staged_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publish_output_id),
  UNIQUE KEY uq_publish_outputs_tracking_code (tracking_code),
  KEY idx_publish_outputs_job (publish_job_id),
  KEY idx_publish_outputs_channel_status (channel_key, status),
  CONSTRAINT fk_publish_outputs_job
    FOREIGN KEY (publish_job_id)
    REFERENCES publish_jobs (publish_job_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
