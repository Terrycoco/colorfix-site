-- Drop obsolete caption copy from viewer-owned Color Plan photos.
--
-- Viewer photos only need their visual role, such as FULL, BEFORE, or INSET.

SET @has_project_color_plan_viewer_photo_caption := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plan_viewer_photos'
    AND COLUMN_NAME = 'caption'
);

SET @sql := IF(
  @has_project_color_plan_viewer_photo_caption = 0,
  'SELECT 1',
  'ALTER TABLE project_color_plan_viewer_photos DROP COLUMN caption'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
