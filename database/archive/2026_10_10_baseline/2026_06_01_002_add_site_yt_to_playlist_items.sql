SET @has_site := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'site'
);

SET @alter_site_sql := IF(
  @has_site = 0,
  'ALTER TABLE playlist_items ADD COLUMN site TINYINT(1) NOT NULL DEFAULT 1 AFTER is_share_image',
  'SELECT 1'
);

PREPARE stmt FROM @alter_site_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_yt := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'yt'
);

SET @alter_yt_sql := IF(
  @has_yt = 0,
  'ALTER TABLE playlist_items ADD COLUMN yt TINYINT(1) NOT NULL DEFAULT 1 AFTER site',
  'SELECT 1'
);

PREPARE stmt FROM @alter_yt_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
