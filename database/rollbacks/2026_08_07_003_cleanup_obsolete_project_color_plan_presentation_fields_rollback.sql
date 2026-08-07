-- Rollback for 2026_08_07_003_cleanup_obsolete_project_color_plan_presentation_fields.sql.

SET @has_project_color_plan_client_description := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_color_plans'
    AND COLUMN_NAME = 'client_description'
);

SET @sql := IF(
  @has_project_color_plan_client_description > 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans ADD COLUMN client_description TEXT NULL AFTER scheme_title'
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
  @has_project_color_plan_painter_note > 0,
  'SELECT 1',
  'ALTER TABLE project_color_plans ADD COLUMN painter_note TEXT NULL AFTER client_description'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS project_color_plan_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_color_plan_id BIGINT UNSIGNED NOT NULL,
  photo_library_id INT UNSIGNED NULL,
  rel_path VARCHAR(512) NOT NULL,
  photo_type VARCHAR(16) NOT NULL DEFAULT 'full',
  caption VARCHAR(255) NULL,
  alt_text TEXT NULL,
  show_client TINYINT(1) NOT NULL DEFAULT 1,
  show_painter TINYINT(1) NOT NULL DEFAULT 1,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_color_plan_photos_plan (project_color_plan_id),
  KEY idx_project_color_plan_photos_plan_order (project_color_plan_id, order_index),
  KEY idx_project_color_plan_photos_photo_library_id (photo_library_id),
  KEY idx_project_color_plan_photos_client_order (project_color_plan_id, show_client, order_index),
  KEY idx_project_color_plan_photos_painter_order (project_color_plan_id, show_painter, order_index),
  CONSTRAINT fk_project_color_plan_photos_plan
    FOREIGN KEY (project_color_plan_id)
    REFERENCES project_color_plans(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_color_plan_photos_photo_library
    FOREIGN KEY (photo_library_id)
    REFERENCES photo_library(photo_library_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
