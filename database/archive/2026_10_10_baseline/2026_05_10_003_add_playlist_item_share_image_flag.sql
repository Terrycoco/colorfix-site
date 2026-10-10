SET @has_is_share_image := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'is_share_image'
);

SET @alter_sql := IF(
  @has_is_share_image = 0,
  'ALTER TABLE playlist_items ADD COLUMN is_share_image TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_from_thumbs',
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

