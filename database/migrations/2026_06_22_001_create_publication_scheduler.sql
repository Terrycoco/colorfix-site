SET @has_publishing_jobs := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
);

SET @has_asset_creator_job_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'asset_creator_job_id'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_asset_creator_job_id = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN asset_creator_job_id INT NULL AFTER asset_library_id, ADD KEY idx_publishing_jobs_creator_job (asset_creator_job_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_asset_creator_output_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'asset_creator_output_id'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_asset_creator_output_id = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN asset_creator_output_id INT NULL AFTER asset_creator_job_id, ADD KEY idx_publishing_jobs_creator_output (asset_creator_output_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_environment := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'environment'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_environment = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN environment VARCHAR(40) NOT NULL DEFAULT ''production'' AFTER platform, ADD KEY idx_publishing_jobs_env_status (environment, status)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_playlist_instance_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'playlist_instance_id'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_playlist_instance_id = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN playlist_instance_id INT NULL AFTER source_id, ADD KEY idx_publishing_jobs_playlist_instance (playlist_instance_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_cta_group_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'cta_group_id'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_cta_group_id = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN cta_group_id INT NULL AFTER playlist_instance_id, ADD KEY idx_publishing_jobs_cta_group (cta_group_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_landing_page_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'landing_page_id'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_landing_page_id = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN landing_page_id INT NULL AFTER cta_group_id, ADD KEY idx_publishing_jobs_landing_page (landing_page_id)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_canonical_destination_url := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'canonical_destination_url'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_canonical_destination_url = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN canonical_destination_url VARCHAR(500) NULL AFTER destination_url',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tracked_destination_url := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'tracked_destination_url'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_tracked_destination_url = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN tracked_destination_url VARCHAR(500) NULL AFTER canonical_destination_url',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_alt_text := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'alt_text'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_alt_text = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN alt_text TEXT NULL AFTER description',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_media_path := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'media_path'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_media_path = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN media_path VARCHAR(500) NULL AFTER image_url',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_media_url := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'media_url'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_media_url = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN media_url VARCHAR(500) NULL AFTER media_path',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_locked_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'locked_at'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_locked_at = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN locked_at DATETIME NULL AFTER published_at',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idempotency_key := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'idempotency_key'
);
SET @sql := IF(@has_publishing_jobs > 0 AND @has_idempotency_key = 0,
  'ALTER TABLE publishing_jobs ADD COLUMN idempotency_key VARCHAR(255) NULL AFTER locked_at, ADD UNIQUE KEY uq_publishing_jobs_idempotency (idempotency_key)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE publishing_jobs
   SET media_url = COALESCE(NULLIF(media_url, ''), NULLIF(image_url, '')),
       canonical_destination_url = COALESCE(NULLIF(canonical_destination_url, ''), NULLIF(destination_url, '')),
       tracked_destination_url = COALESCE(NULLIF(tracked_destination_url, ''), NULLIF(destination_url, '')),
       environment = COALESCE(NULLIF(environment, ''), JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.environment')), 'production')
 WHERE @has_publishing_jobs > 0;

CREATE TABLE IF NOT EXISTS publication_schedule (
  publication_schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publishing_job_id INT NOT NULL,
  channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  scheduled_at DATETIME NOT NULL,
  timezone VARCHAR(100) NOT NULL DEFAULT 'America/Los_Angeles',
  status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
  priority INT NOT NULL DEFAULT 100,
  attempt_count INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  claimed_at DATETIME NULL,
  claimed_by VARCHAR(255) NULL,
  last_attempt_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_error_code VARCHAR(120) NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_schedule_id),
  UNIQUE KEY uq_publication_schedule_publishing_job (publishing_job_id),
  KEY idx_publication_schedule_due (status, scheduled_at),
  KEY idx_publication_schedule_retry (status, next_retry_at),
  KEY idx_publication_schedule_track (channel_id, environment, scheduled_at),
  KEY idx_publication_schedule_platform (platform, environment, scheduled_at),
  CONSTRAINT fk_publication_schedule_publishing_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_channel
    FOREIGN KEY (channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publication_schedule_attempts (
  publication_schedule_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_schedule_id BIGINT UNSIGNED NOT NULL,
  publishing_job_id INT NOT NULL,
  attempt_number INT NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  retryable TINYINT(1) NOT NULL DEFAULT 0,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  platform_post_id VARCHAR(255) NULL,
  published_url TEXT NULL,
  response_summary MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_schedule_attempt_id),
  KEY idx_publication_schedule_attempts_schedule (publication_schedule_id),
  KEY idx_publication_schedule_attempts_publishing_job (publishing_job_id),
  CONSTRAINT fk_publication_schedule_attempts_schedule
    FOREIGN KEY (publication_schedule_id)
    REFERENCES publication_schedule (publication_schedule_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_attempts_publishing_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE publishing_channels
   SET metadata_json = JSON_SET(
     COALESCE(NULLIF(metadata_json, ''), JSON_OBJECT()),
     '$.scheduler.scheduler_enabled', true,
     '$.scheduler.default_timezone', 'America/Los_Angeles',
     '$.scheduler.default_max_posts_per_day', 3,
     '$.scheduler.default_minimum_spacing_minutes', 240,
     '$.scheduler.default_window_start', '08:00',
     '$.scheduler.default_window_end', '20:00',
     '$.scheduler.avoid_same_source_back_to_back', true,
     '$.scheduler.retry_delay_minutes', 15,
     '$.scheduler.max_attempts', 3,
     '$.scheduler.processing_timeout_minutes', 30
   )
 WHERE metadata_json IS NULL
    OR JSON_EXTRACT(metadata_json, '$.scheduler') IS NULL;
