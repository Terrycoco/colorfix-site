CREATE TABLE IF NOT EXISTS asset_library (
  asset_library_id INT NOT NULL AUTO_INCREMENT,
  legacy_photo_library_id INT NULL,
  asset_kind VARCHAR(30) NOT NULL DEFAULT 'image',
  mime_type VARCHAR(120) NULL,
  rel_path VARCHAR(500) NOT NULL,
  title VARCHAR(255) NULL,
  tags TEXT NULL,
  alt_text TEXT NULL,
  note TEXT NULL,
  source_type VARCHAR(80) NULL,
  source_id INT NULL,
  client_id INT NULL,
  width INT NULL,
  height INT NULL,
  duration_seconds DECIMAL(10,3) NULL,
  file_size_bytes BIGINT NULL,
  checksum VARCHAR(128) NULL,
  metadata_json MEDIUMTEXT NULL,
  is_inactive TINYINT(1) NOT NULL DEFAULT 0,
  is_retired TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_library_id),
  UNIQUE KEY uq_asset_library_legacy_photo (legacy_photo_library_id),
  KEY idx_asset_library_kind (asset_kind),
  KEY idx_asset_library_rel_path (rel_path),
  KEY idx_asset_library_source (source_type, source_id),
  KEY idx_asset_library_client (client_id),
  KEY idx_asset_library_inactive (is_inactive, is_retired)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_photo_asset_library_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'photo_library'
    AND COLUMN_NAME = 'asset_library_id'
);

SET @alter_photo_asset_library_sql := IF(
  @has_photo_asset_library_id = 0,
  'ALTER TABLE photo_library ADD COLUMN asset_library_id INT NULL AFTER photo_library_id',
  'SELECT 1'
);

PREPARE stmt FROM @alter_photo_asset_library_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_publish_output_library_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publish_outputs'
    AND COLUMN_NAME = 'library_asset_id'
);

SET @alter_publish_output_library_sql := IF(
  @has_publish_output_library_id = 0,
  'ALTER TABLE publish_outputs ADD COLUMN library_asset_id INT NULL AFTER asset_path, ADD KEY idx_publish_outputs_library_asset (library_asset_id)',
  'SELECT 1'
);

PREPARE stmt FROM @alter_publish_output_library_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO asset_library (
  legacy_photo_library_id,
  asset_kind,
  mime_type,
  rel_path,
  title,
  tags,
  alt_text,
  note,
  source_type,
  source_id,
  client_id,
  is_inactive,
  created_at,
  updated_at
)
SELECT
  pl.photo_library_id,
  CASE
    WHEN LOWER(pl.rel_path) REGEXP '\\.(mp4|mov|webm|m4v)(\\?.*)?$' THEN 'video'
    WHEN LOWER(pl.rel_path) REGEXP '\\.(pdf|ppt|pptx|key)(\\?.*)?$' THEN 'document'
    ELSE 'image'
  END AS asset_kind,
  CASE
    WHEN LOWER(pl.rel_path) REGEXP '\\.jpe?g(\\?.*)?$' THEN 'image/jpeg'
    WHEN LOWER(pl.rel_path) REGEXP '\\.png(\\?.*)?$' THEN 'image/png'
    WHEN LOWER(pl.rel_path) REGEXP '\\.gif(\\?.*)?$' THEN 'image/gif'
    WHEN LOWER(pl.rel_path) REGEXP '\\.webp(\\?.*)?$' THEN 'image/webp'
    WHEN LOWER(pl.rel_path) REGEXP '\\.mp4(\\?.*)?$' THEN 'video/mp4'
    WHEN LOWER(pl.rel_path) REGEXP '\\.mov(\\?.*)?$' THEN 'video/quicktime'
    WHEN LOWER(pl.rel_path) REGEXP '\\.webm(\\?.*)?$' THEN 'video/webm'
    WHEN LOWER(pl.rel_path) REGEXP '\\.pdf(\\?.*)?$' THEN 'application/pdf'
    WHEN LOWER(pl.rel_path) REGEXP '\\.pptx(\\?.*)?$' THEN 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ELSE NULL
  END AS mime_type,
  pl.rel_path,
  pl.title,
  pl.tags,
  pl.alt_text,
  pl.note,
  pl.source_type,
  pl.source_id,
  pl.client_id,
  COALESCE(pl.is_inactive, 0),
  COALESCE(pl.created_at, NOW()),
  COALESCE(pl.updated_at, pl.created_at, NOW())
FROM photo_library pl
WHERE NOT EXISTS (
  SELECT 1
  FROM asset_library al
  WHERE al.legacy_photo_library_id = pl.photo_library_id
);

UPDATE photo_library pl
JOIN asset_library al
  ON al.legacy_photo_library_id = pl.photo_library_id
SET pl.asset_library_id = al.asset_library_id
WHERE pl.asset_library_id IS NULL;
