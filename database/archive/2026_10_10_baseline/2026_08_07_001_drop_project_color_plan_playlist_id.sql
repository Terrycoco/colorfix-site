-- Remove obsolete final-playlist linkage from private Project Color Plans.
--
-- Project playback now uses project playlists and PES-resolved viewer behavior.
-- project_color_plans.playlist_id is intentionally no longer used.

SET @has_project_color_plan_playlist_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'playlist_id'
);

SET @project_color_plan_playlist_fk := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'playlist_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);

SET @sql := IF(
  @has_project_color_plan_playlist_id = 0 OR @project_color_plan_playlist_fk IS NULL,
  'SELECT 1',
  CONCAT(
    'ALTER TABLE project_color_plans DROP FOREIGN KEY `',
    REPLACE(@project_color_plan_playlist_fk, '`', '``'),
    '`'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_color_plan_playlist_index := (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'playlist_id'
  ORDER BY INDEX_NAME = 'idx_project_color_plans_playlist_id' DESC
  LIMIT 1
);

SET @sql := IF(
  @has_project_color_plan_playlist_id = 0 OR @project_color_plan_playlist_index IS NULL,
  'SELECT 1',
  CONCAT(
    'ALTER TABLE project_color_plans DROP INDEX `',
    REPLACE(@project_color_plan_playlist_index, '`', '``'),
    '`'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @has_project_color_plan_playlist_id = 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans DROP COLUMN playlist_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
