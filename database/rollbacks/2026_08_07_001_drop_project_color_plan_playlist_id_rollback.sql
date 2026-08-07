-- Rollback for 2026_08_07_001_drop_project_color_plan_playlist_id.sql.

SET @has_project_color_plan_playlist_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'playlist_id'
);

SET @sql := IF(
  @has_project_color_plan_playlist_id > 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans ADD COLUMN playlist_id INT NULL AFTER scheme_title'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_project_color_plan_playlist_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND INDEX_NAME = 'idx_project_color_plans_playlist_id'
);

SET @sql := IF(
  @has_project_color_plan_playlist_index > 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans ADD INDEX idx_project_color_plans_playlist_id (playlist_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_project_color_plan_playlist_fk := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND CONSTRAINT_NAME = 'fk_project_color_plans_playlist'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
  @has_project_color_plan_playlist_fk > 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans ADD CONSTRAINT fk_project_color_plans_playlist FOREIGN KEY (playlist_id) REFERENCES playlists(playlist_id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
