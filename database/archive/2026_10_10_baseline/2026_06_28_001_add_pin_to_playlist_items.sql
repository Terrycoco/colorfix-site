SET @has_pin := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'pin'
);

SET @alter_pin_sql := IF(
  @has_pin = 0,
  'ALTER TABLE playlist_items ADD COLUMN pin TINYINT(1) NOT NULL DEFAULT 1 AFTER yt',
  'SELECT 1'
);

PREPARE stmt FROM @alter_pin_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
