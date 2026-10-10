-- Remove obsolete presentation responsibilities from private Project Color Plans.
--
-- Viewer-owned tables now store audience copy and Photo Library selections.
-- This migration is destructive and should be run only after confirming no
-- remaining production dependency on these legacy fields/tables.

DROP TABLE IF EXISTS project_color_plan_photos;

SET @has_project_color_plan_client_description := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'client_description'
);

SET @sql := IF(
  @has_project_color_plan_client_description = 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans DROP COLUMN client_description'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_project_color_plan_painter_note := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'painter_note'
);

SET @sql := IF(
  @has_project_color_plan_painter_note = 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans DROP COLUMN painter_note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
