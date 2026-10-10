SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'analyzer_role'
);

SET @sql := IF(
  @col_exists = 0,
  "ALTER TABLE playlist_items ADD COLUMN analyzer_role VARCHAR(32) NOT NULL DEFAULT 'ignore' AFTER yt",
  "SELECT 'playlist_items.analyzer_role already exists'"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
