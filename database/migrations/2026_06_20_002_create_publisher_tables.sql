CREATE TABLE IF NOT EXISTS publishing_channels (
  publishing_channel_id INT NOT NULL AUTO_INCREMENT,
  platform VARCHAR(80) NOT NULL,
  channel_key VARCHAR(120) NOT NULL,
  publisher_service VARCHAR(120) NOT NULL,
  label VARCHAR(160) NOT NULL,
  account_name VARCHAR(255) NULL,
  external_account_id VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  api_base_url VARCHAR(500) NULL,
  encrypted_auth_payload MEDIUMBLOB NULL,
  auth_nonce VARBINARY(64) NULL,
  auth_tag VARBINARY(64) NULL,
  auth_key_ref VARCHAR(120) NULL,
  auth_encryption_alg VARCHAR(80) NULL DEFAULT 'aes-256-gcm',
  auth_expires_at DATETIME NULL,
  auth_refreshed_at DATETIME NULL,
  metadata_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publishing_channel_id),
  UNIQUE KEY uq_publishing_channels_key (channel_key),
  KEY idx_publishing_channels_platform_status (platform, status),
  KEY idx_publishing_channels_external (platform, external_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publisher_assets (
  publisher_asset_id INT NOT NULL AUTO_INCREMENT,
  publishing_channel_id INT NULL,
  publish_output_id INT NULL,
  asset_library_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_id INT NOT NULL,
  asset_type VARCHAR(120) NOT NULL,
  title VARCHAR(255) NULL,
  description TEXT NULL,
  image_url VARCHAR(500) NULL,
  destination_url VARCHAR(500) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  external_id VARCHAR(255) NULL,
  external_url VARCHAR(500) NULL,
  metadata_json MEDIUMTEXT NULL,
  last_error_code VARCHAR(120) NULL,
  last_error_message TEXT NULL,
  next_attempt_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publisher_asset_id),
  KEY idx_publisher_assets_channel_status (publishing_channel_id, status),
  KEY idx_publisher_assets_platform_status (platform, status),
  KEY idx_publisher_assets_source (source_type, source_id),
  KEY idx_publisher_assets_asset_library (asset_library_id),
  KEY idx_publisher_assets_publish_output (publish_output_id),
  KEY idx_publisher_assets_next_attempt (status, next_attempt_at),
  CONSTRAINT fk_publisher_assets_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_publisher_assets_output
    FOREIGN KEY (publish_output_id)
    REFERENCES publish_outputs (publish_output_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publisher_attempts (
  publisher_attempt_id INT NOT NULL AUTO_INCREMENT,
  publisher_asset_id INT NOT NULL,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  publisher_service VARCHAR(120) NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  request_payload_json MEDIUMTEXT NULL,
  response_payload_json MEDIUMTEXT NULL,
  external_id VARCHAR(255) NULL,
  external_url VARCHAR(500) NULL,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  next_retry_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publisher_attempt_id),
  KEY idx_publisher_attempts_asset (publisher_asset_id),
  KEY idx_publisher_attempts_channel_status (publishing_channel_id, status),
  KEY idx_publisher_attempts_platform_status (platform, status),
  KEY idx_publisher_attempts_retry (status, next_retry_at),
  CONSTRAINT fk_publisher_attempts_asset
    FOREIGN KEY (publisher_asset_id)
    REFERENCES publisher_assets (publisher_asset_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_publisher_attempts_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publisher_sync_runs (
  publisher_sync_run_id INT NOT NULL AUTO_INCREMENT,
  publishing_channel_id INT NULL,
  platform VARCHAR(80) NOT NULL,
  sync_kind VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  request_payload_json MEDIUMTEXT NULL,
  response_payload_json MEDIUMTEXT NULL,
  error_code VARCHAR(120) NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publisher_sync_run_id),
  KEY idx_publisher_sync_runs_channel (publishing_channel_id, sync_kind, status),
  KEY idx_publisher_sync_runs_platform (platform, sync_kind, status),
  CONSTRAINT fk_publisher_sync_runs_channel
    FOREIGN KEY (publishing_channel_id)
    REFERENCES publishing_channels (publishing_channel_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO publishing_channels (
  platform,
  channel_key,
  publisher_service,
  label,
  account_name,
  status,
  api_base_url,
  metadata_json
)
SELECT
  'pinterest',
  'pinterest_colorfix_makeovers',
  'PinterestPublisher',
  'Pinterest - ColorFix Makeovers',
  'terrymarr',
  'pending_api_access',
  'https://api.pinterest.com/v5',
  JSON_OBJECT(
    'board_id', CAST(NULL AS CHAR),
    'board_name', 'ColorFix Makeovers',
    'board_url', 'https://www.pinterest.com/terrymarr/colorfix-makeovers/',
    'board_slug', 'terrymarr/colorfix-makeovers'
  )
WHERE NOT EXISTS (
  SELECT 1 FROM publishing_channels WHERE channel_key = 'pinterest_colorfix_makeovers'
);
