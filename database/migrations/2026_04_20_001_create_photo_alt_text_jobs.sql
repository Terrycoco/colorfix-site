ALTER TABLE photo_library
  ADD COLUMN ai_alt_text TEXT NULL AFTER alt_text,
  ADD COLUMN ai_alt_metadata_json MEDIUMTEXT NULL AFTER ai_alt_text,
  ADD COLUMN ai_alt_model VARCHAR(80) NULL AFTER ai_alt_metadata_json,
  ADD COLUMN ai_alt_generated_at DATETIME NULL AFTER ai_alt_model;

CREATE TABLE IF NOT EXISTS photo_alt_text_jobs (
  job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  photo_library_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 12,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (job_id),
  UNIQUE KEY uq_photo_alt_text_job_photo (photo_library_id),
  KEY idx_photo_alt_text_jobs_ready (status, next_attempt_at),
  CONSTRAINT fk_photo_alt_text_jobs_photo
    FOREIGN KEY (photo_library_id)
    REFERENCES photo_library(photo_library_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
