-- Final publishing pipeline shape:
-- Analyzer -> Creator -> Packager -> Scheduler -> Publisher -> Published Assets.
-- Publishing data is still disposable at this stage, so this intentionally
-- replaces the earlier draft publishing/scheduler tables.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS publication_schedule_attempts;
DROP TABLE IF EXISTS publication_schedule;
DROP TABLE IF EXISTS publications;
DROP TABLE IF EXISTS publisher_attempts;
DROP TABLE IF EXISTS scheduler_queue_item_attempts;
DROP TABLE IF EXISTS publishing_assets;
DROP TABLE IF EXISTS publishing_jobs;
DROP TABLE IF EXISTS publish_outputs;
DROP TABLE IF EXISTS publish_jobs;
DROP TABLE IF EXISTS publishing_pipeline_events;
DROP TABLE IF EXISTS published_assets;
DROP TABLE IF EXISTS scheduler_queue_items;
DROP TABLE IF EXISTS packages;
DROP TABLE IF EXISTS package_batches;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE package_batches (
  package_batch_id INT NOT NULL AUTO_INCREMENT,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  channel VARCHAR(120) NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'test',
  source_type VARCHAR(80) NOT NULL,
  source_id INT NOT NULL,
  analyzer_job_id INT NULL,
  creator_job_id INT NULL,
  playlist_instance_id INT NULL,
  cta_group_id INT NULL,
  landing_page_id INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'packaged',
  notes TEXT NULL,
  metadata_json JSON NULL,
  packaged_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  queued_at DATETIME NULL,
  completed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (package_batch_id),
  KEY idx_package_batches_channel_status (publishing_channel_id, environment, status),
  KEY idx_package_batches_platform_status (platform, environment, status),
  KEY idx_package_batches_source (source_type, source_id),
  KEY idx_package_batches_creator_job (creator_job_id),
  KEY idx_package_batches_instance (playlist_instance_id),
  KEY idx_package_batches_cta (cta_group_id),
  CONSTRAINT fk_package_batches_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE packages (
  package_id INT NOT NULL AUTO_INCREMENT,
  package_batch_id INT NOT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  channel VARCHAR(120) NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'test',
  source_type VARCHAR(80) NOT NULL,
  source_id INT NOT NULL,
  analyzer_job_id INT NULL,
  creator_job_id INT NULL,
  source_asset_id INT NULL,
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
  board_id VARCHAR(120) NULL,
  board_name VARCHAR(255) NULL,
  board_url VARCHAR(500) NULL,
  board_slug VARCHAR(255) NULL,
  duplicate_fingerprint VARCHAR(191) NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'packaged',
  metadata_json JSON NULL,
  published_at DATETIME NULL,
  locked_at DATETIME NULL,
  last_error_code VARCHAR(120) NULL,
  last_error_message TEXT NULL,
  idempotency_key VARCHAR(191) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (package_id),
  UNIQUE KEY uq_packages_idempotency (idempotency_key),
  KEY idx_packages_batch (package_batch_id),
  KEY idx_packages_channel_status (publishing_channel_id, environment, status),
  KEY idx_packages_platform_status (platform, environment, status),
  KEY idx_packages_source (source_type, source_id),
  KEY idx_packages_creator_job (creator_job_id),
  KEY idx_packages_source_asset (source_asset_id),
  KEY idx_packages_asset_library (asset_library_id),
  KEY idx_packages_duplicate (duplicate_fingerprint),
  CONSTRAINT fk_packages_batch
    FOREIGN KEY (package_batch_id)
    REFERENCES package_batches (package_batch_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_packages_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduler_queue_items (
  queue_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT NOT NULL,
  package_batch_id INT NOT NULL,
  publishing_channel_id INT NULL,
  channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  channel VARCHAR(120) NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  status VARCHAR(60) NOT NULL DEFAULT 'waiting',
  priority INT NOT NULL DEFAULT 100,
  scheduled_at DATETIME NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
  attempt_count INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  claimed_at DATETIME NULL,
  claimed_by VARCHAR(120) NULL,
  last_attempt_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  completed_at DATETIME NULL,
  skip_reason VARCHAR(255) NULL,
  matching_published_asset_id BIGINT UNSIGNED NULL,
  last_error_code VARCHAR(120) NULL,
  last_error_message TEXT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (queue_item_id),
  KEY idx_scheduler_queue_package_status (package_id, status),
  KEY idx_scheduler_queue_batch (package_batch_id),
  KEY idx_scheduler_queue_track (channel_id, environment, status),
  KEY idx_scheduler_queue_platform (platform, environment, status),
  KEY idx_scheduler_queue_due (status, scheduled_at, next_retry_at),
  KEY idx_scheduler_queue_claim (status, claimed_at),
  CONSTRAINT fk_scheduler_queue_package
    FOREIGN KEY (package_id)
    REFERENCES packages (package_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_queue_batch
    FOREIGN KEY (package_batch_id)
    REFERENCES package_batches (package_batch_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_queue_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publisher_attempts (
  attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_item_id BIGINT UNSIGNED NULL,
  package_id INT NOT NULL,
  package_batch_id INT NOT NULL,
  analyzer_job_id INT NULL,
  creator_job_id INT NULL,
  source_asset_id INT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  channel VARCHAR(120) NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  publisher_service VARCHAR(120) NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  status VARCHAR(60) NOT NULL,
  request_payload_json JSON NULL,
  response_payload_json JSON NULL,
  external_post_id VARCHAR(191) NULL,
  external_post_url VARCHAR(500) NULL,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_id),
  KEY idx_publisher_attempts_queue (queue_item_id),
  KEY idx_publisher_attempts_package (package_id),
  KEY idx_publisher_attempts_batch (package_batch_id),
  KEY idx_publisher_attempts_creator_job (creator_job_id),
  KEY idx_publisher_attempts_channel_status (publishing_channel_id, environment, status),
  KEY idx_publisher_attempts_platform_status (platform, environment, status),
  CONSTRAINT fk_publisher_attempts_queue
    FOREIGN KEY (queue_item_id)
    REFERENCES scheduler_queue_items (queue_item_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_publisher_attempts_package
    FOREIGN KEY (package_id)
    REFERENCES packages (package_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publisher_attempts_batch
    FOREIGN KEY (package_batch_id)
    REFERENCES package_batches (package_batch_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publisher_attempts_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduler_queue_item_attempts (
  queue_item_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_item_id BIGINT UNSIGNED NOT NULL,
  package_batch_id INT NOT NULL,
  package_id INT NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  retryable TINYINT(1) NOT NULL DEFAULT 0,
  response_summary TEXT NULL,
  platform_post_id VARCHAR(191) NULL,
  published_url VARCHAR(500) NULL,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (queue_item_attempt_id),
  KEY idx_scheduler_queue_item_attempts_queue (queue_item_id),
  KEY idx_scheduler_queue_item_attempts_package (package_id),
  CONSTRAINT fk_scheduler_queue_item_attempts_queue
    FOREIGN KEY (queue_item_id)
    REFERENCES scheduler_queue_items (queue_item_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_queue_item_attempts_package
    FOREIGN KEY (package_id)
    REFERENCES packages (package_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_queue_item_attempts_batch
    FOREIGN KEY (package_batch_id)
    REFERENCES package_batches (package_batch_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE published_assets (
  published_asset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT NOT NULL,
  queue_item_id BIGINT UNSIGNED NULL,
  attempt_id BIGINT UNSIGNED NULL,
  package_batch_id INT NOT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  channel VARCHAR(120) NULL,
  environment VARCHAR(40) NOT NULL DEFAULT 'production',
  source_type VARCHAR(80) NULL,
  source_id INT NULL,
  analyzer_job_id INT NULL,
  creator_job_id INT NULL,
  source_asset_id INT NULL,
  asset_library_id INT NULL,
  playlist_instance_id INT NULL,
  cta_group_id INT NULL,
  landing_page_id INT NULL,
  asset_type VARCHAR(80) NULL,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  alt_text TEXT NULL,
  media_url VARCHAR(500) NULL,
  destination_url VARCHAR(500) NULL,
  canonical_destination_url VARCHAR(500) NULL,
  tracked_destination_url VARCHAR(500) NULL,
  board_id VARCHAR(120) NULL,
  board_name VARCHAR(255) NULL,
  board_url VARCHAR(500) NULL,
  board_slug VARCHAR(255) NULL,
  external_post_id VARCHAR(191) NULL,
  external_post_url VARCHAR(500) NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'published',
  duplicate_fingerprint VARCHAR(191) NULL,
  response_payload_json JSON NULL,
  metadata_json JSON NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_from_channel TINYINT(1) NOT NULL DEFAULT 0,
  removed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (published_asset_id),
  KEY idx_published_assets_package (package_id),
  KEY idx_published_assets_queue (queue_item_id),
  KEY idx_published_assets_attempt (attempt_id),
  KEY idx_published_assets_track (publishing_channel_id, environment, published_at),
  KEY idx_published_assets_platform (platform, environment, published_at),
  KEY idx_published_assets_duplicate (duplicate_fingerprint, published_at),
  KEY idx_published_assets_external (platform, external_post_id),
  CONSTRAINT fk_published_assets_package
    FOREIGN KEY (package_id)
    REFERENCES packages (package_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_published_assets_queue
    FOREIGN KEY (queue_item_id)
    REFERENCES scheduler_queue_items (queue_item_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_published_assets_attempt
    FOREIGN KEY (attempt_id)
    REFERENCES publisher_attempts (attempt_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_published_assets_batch
    FOREIGN KEY (package_batch_id)
    REFERENCES package_batches (package_batch_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_published_assets_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publishing_pipeline_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analyzer_job_id INT NULL,
  creator_job_id INT NULL,
  package_batch_id INT NULL,
  package_id INT NULL,
  queue_item_id BIGINT UNSIGNED NULL,
  attempt_id BIGINT UNSIGNED NULL,
  published_asset_id BIGINT UNSIGNED NULL,
  stage VARCHAR(80) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  old_status VARCHAR(60) NULL,
  new_status VARCHAR(60) NULL,
  message TEXT NULL,
  actor VARCHAR(120) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_pipeline_events_package (package_id),
  KEY idx_pipeline_events_queue (queue_item_id),
  KEY idx_pipeline_events_published (published_asset_id),
  KEY idx_pipeline_events_stage (stage, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
