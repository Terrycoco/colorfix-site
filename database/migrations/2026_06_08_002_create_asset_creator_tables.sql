CREATE TABLE IF NOT EXISTS asset_creator_jobs (
  asset_creator_job_id INT NOT NULL AUTO_INCREMENT,
  creator_key VARCHAR(120) NOT NULL,
  source_type VARCHAR(60) NULL,
  source_id INT NULL,
  playlist_instance_id INT NULL,
  title VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  instructions_json MEDIUMTEXT NULL,
  notes TEXT NULL,
  last_run_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_creator_job_id),
  KEY idx_asset_creator_jobs_creator (creator_key),
  KEY idx_asset_creator_jobs_source (source_type, source_id),
  KEY idx_asset_creator_jobs_status (status),
  KEY idx_asset_creator_jobs_playlist_instance (playlist_instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_creator_inputs (
  asset_creator_input_id INT NOT NULL AUTO_INCREMENT,
  asset_creator_job_id INT NOT NULL,
  asset_library_id INT NULL,
  role VARCHAR(80) NOT NULL DEFAULT 'input',
  sort_order DECIMAL(10,3) NOT NULL DEFAULT 0,
  metadata_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_creator_input_id),
  KEY idx_asset_creator_inputs_job (asset_creator_job_id),
  KEY idx_asset_creator_inputs_asset (asset_library_id),
  KEY idx_asset_creator_inputs_role (role),
  CONSTRAINT fk_asset_creator_inputs_job
    FOREIGN KEY (asset_creator_job_id)
    REFERENCES asset_creator_jobs (asset_creator_job_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_creator_outputs (
  asset_creator_output_id INT NOT NULL AUTO_INCREMENT,
  asset_creator_job_id INT NOT NULL,
  asset_library_id INT NOT NULL,
  role VARCHAR(80) NOT NULL DEFAULT 'output',
  status VARCHAR(40) NOT NULL DEFAULT 'created',
  generated_at DATETIME NULL,
  metadata_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_creator_output_id),
  KEY idx_asset_creator_outputs_job (asset_creator_job_id),
  KEY idx_asset_creator_outputs_asset (asset_library_id),
  KEY idx_asset_creator_outputs_status (status),
  CONSTRAINT fk_asset_creator_outputs_job
    FOREIGN KEY (asset_creator_job_id)
    REFERENCES asset_creator_jobs (asset_creator_job_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
