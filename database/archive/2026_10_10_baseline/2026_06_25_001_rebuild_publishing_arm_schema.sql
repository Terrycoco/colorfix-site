-- Rebuild the publishing arm around the platform-neutral job -> asset ->
-- publication -> schedule model.
--
-- The publishing arm is still in test, so this migration intentionally drops
-- the disposable draft/queue tables and recreates them with the final
-- responsibility boundaries. It preserves publishing_channels and
-- publisher_sync_runs so OAuth/channel metadata and board-sync history survive.

DROP TABLE IF EXISTS publication_schedule_attempts;
DROP TABLE IF EXISTS publication_schedule;
DROP TABLE IF EXISTS publisher_attempts;
DROP TABLE IF EXISTS publications;
DROP TABLE IF EXISTS publishing_assets;
DROP TABLE IF EXISTS publishing_jobs;
DROP TABLE IF EXISTS publish_outputs;
DROP TABLE IF EXISTS publish_jobs;

CREATE TABLE publishing_jobs (
  publishing_job_id INT NOT NULL AUTO_INCREMENT,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'test',
  source_type VARCHAR(60) NOT NULL,
  source_id INT NOT NULL,
  asset_creator_job_id INT NULL,
  playlist_instance_id INT NULL,
  cta_group_id INT NULL,
  landing_page_id INT NULL,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  metadata_json JSON NULL,
  approved_at DATETIME NULL,
  queued_at DATETIME NULL,
  published_at DATETIME NULL,
  archived_at DATETIME NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publishing_job_id),
  KEY idx_publishing_jobs_channel_status (publishing_channel_id, environment, status),
  KEY idx_publishing_jobs_platform_track (platform, environment, status),
  KEY idx_publishing_jobs_source (source_type, source_id),
  KEY idx_publishing_jobs_creator_job (asset_creator_job_id),
  KEY idx_publishing_jobs_playlist_instance (playlist_instance_id),
  KEY idx_publishing_jobs_cta_group (cta_group_id),
  KEY idx_publishing_jobs_landing_page (landing_page_id),
  CONSTRAINT fk_publishing_jobs_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publishing_assets (
  publishing_asset_id INT NOT NULL AUTO_INCREMENT,
  publishing_job_id INT NOT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'test',
  source_type VARCHAR(60) NOT NULL,
  source_id INT NOT NULL,
  asset_creator_output_id INT NULL,
  asset_library_id INT NULL,
  playlist_instance_id INT NULL,
  cta_group_id INT NULL,
  landing_page_id INT NULL,
  asset_type VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  alt_text TEXT NULL,
  image_url VARCHAR(500) NULL,
  media_path VARCHAR(500) NULL,
  media_url VARCHAR(500) NULL,
  destination_url VARCHAR(500) NULL,
  canonical_destination_url VARCHAR(500) NULL,
  tracked_destination_url VARCHAR(500) NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'draft',
  metadata_json JSON NULL,
  last_error_code VARCHAR(120) NULL,
  last_error_message TEXT NULL,
  next_attempt_at DATETIME NULL,
  published_at DATETIME NULL,
  locked_at DATETIME NULL,
  idempotency_key VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publishing_asset_id),
  UNIQUE KEY uq_publishing_assets_idempotency (idempotency_key),
  KEY idx_publishing_assets_job (publishing_job_id),
  KEY idx_publishing_assets_channel_status (publishing_channel_id, environment, status),
  KEY idx_publishing_assets_platform_track (platform, environment, status),
  KEY idx_publishing_assets_source (source_type, source_id),
  KEY idx_publishing_assets_creator_output (asset_creator_output_id),
  KEY idx_publishing_assets_asset_library (asset_library_id),
  KEY idx_publishing_assets_playlist_instance (playlist_instance_id),
  KEY idx_publishing_assets_cta_group (cta_group_id),
  KEY idx_publishing_assets_landing_page (landing_page_id),
  KEY idx_publishing_assets_next_attempt (status, next_attempt_at),
  CONSTRAINT fk_publishing_assets_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publishing_assets_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publications (
  publication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publishing_job_id INT NOT NULL,
  publishing_asset_id INT NOT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  status VARCHAR(60) NOT NULL DEFAULT 'published',
  external_post_id VARCHAR(255) NULL,
  external_post_url VARCHAR(500) NULL,
  request_payload_json JSON NULL,
  response_payload_json JSON NULL,
  metadata_json JSON NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_id),
  KEY idx_publications_job (publishing_job_id),
  KEY idx_publications_asset (publishing_asset_id),
  KEY idx_publications_channel_track (publishing_channel_id, environment, status),
  KEY idx_publications_platform_track (platform, environment, status),
  KEY idx_publications_external_post (platform, external_post_id),
  CONSTRAINT fk_publications_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publications_asset
    FOREIGN KEY (publishing_asset_id)
    REFERENCES publishing_assets (publishing_asset_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publications_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publisher_attempts (
  publisher_attempt_id INT NOT NULL AUTO_INCREMENT,
  publishing_job_id INT NOT NULL,
  publishing_asset_id INT NULL,
  publication_id BIGINT UNSIGNED NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  publisher_service VARCHAR(120) NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  status VARCHAR(60) NOT NULL DEFAULT 'pending',
  request_payload_json JSON NULL,
  response_payload_json JSON NULL,
  external_post_id VARCHAR(255) NULL,
  external_post_url VARCHAR(500) NULL,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publisher_attempt_id),
  KEY idx_publisher_attempts_job (publishing_job_id),
  KEY idx_publisher_attempts_asset (publishing_asset_id),
  KEY idx_publisher_attempts_publication (publication_id),
  KEY idx_publisher_attempts_channel_status (publishing_channel_id, environment, status),
  KEY idx_publisher_attempts_platform_status (platform, environment, status),
  KEY idx_publisher_attempts_retry (status, next_retry_at),
  CONSTRAINT fk_publisher_attempts_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publisher_attempts_asset
    FOREIGN KEY (publishing_asset_id)
    REFERENCES publishing_assets (publishing_asset_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_publisher_attempts_publication
    FOREIGN KEY (publication_id)
    REFERENCES publications (publication_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_publisher_attempts_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_schedule (
  publication_schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publishing_job_id INT NOT NULL,
  publishing_asset_id INT NOT NULL,
  channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  scheduled_at DATETIME NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'America/Los_Angeles',
  status VARCHAR(40) NOT NULL DEFAULT 'waiting',
  priority INT NOT NULL DEFAULT 0,
  attempt_count INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  claimed_by VARCHAR(120) NULL,
  claimed_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_error_code VARCHAR(120) NULL,
  last_error_message TEXT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_schedule_id),
  KEY idx_publication_schedule_job (publishing_job_id),
  KEY idx_publication_schedule_asset (publishing_asset_id),
  KEY idx_publication_schedule_due (status, scheduled_at),
  KEY idx_publication_schedule_retry (status, next_retry_at),
  KEY idx_publication_schedule_track (channel_id, environment, scheduled_at),
  KEY idx_publication_schedule_platform (platform, environment, scheduled_at),
  CONSTRAINT fk_publication_schedule_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_asset
    FOREIGN KEY (publishing_asset_id)
    REFERENCES publishing_assets (publishing_asset_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_channel
    FOREIGN KEY (channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_schedule_attempts (
  publication_schedule_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_schedule_id BIGINT UNSIGNED NOT NULL,
  publishing_job_id INT NOT NULL,
  publishing_asset_id INT NOT NULL,
  attempt_number INT NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  retryable TINYINT(1) NOT NULL DEFAULT 0,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  platform_post_id VARCHAR(255) NULL,
  published_url VARCHAR(500) NULL,
  response_summary TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_schedule_attempt_id),
  KEY idx_publication_schedule_attempts_schedule (publication_schedule_id),
  KEY idx_publication_schedule_attempts_job (publishing_job_id),
  KEY idx_publication_schedule_attempts_asset (publishing_asset_id),
  CONSTRAINT fk_publication_schedule_attempts_schedule
    FOREIGN KEY (publication_schedule_id)
    REFERENCES publication_schedule (publication_schedule_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_attempts_job
    FOREIGN KEY (publishing_job_id)
    REFERENCES publishing_jobs (publishing_job_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publication_schedule_attempts_asset
    FOREIGN KEY (publishing_asset_id)
    REFERENCES publishing_assets (publishing_asset_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
