SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'playlist_items'
    AND COLUMN_NAME = 'finder_start'
);

SET @sql := IF(
  @col_exists = 0,
  "ALTER TABLE playlist_items ADD COLUMN finder_start VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER analyzer_role",
  "SELECT 'playlist_items.finder_start already exists'"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
