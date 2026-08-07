-- Viewer-owned audience presentation setup for private Project Color Plans.
--
-- Color Plans hold room/design/specification data. These tables hold
-- Concept, Client, and Painter viewer copy plus audience-specific Photo
-- Library selections and roles.

CREATE TABLE IF NOT EXISTS project_color_plan_viewers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_color_plan_id BIGINT UNSIGNED NOT NULL,
  viewer_key VARCHAR(20) NOT NULL,
  concept_title VARCHAR(255) NULL,
  challenge TEXT NULL,
  design_direction TEXT NULL,
  scheme_title VARCHAR(255) NULL,
  final_design_description TEXT NULL,
  overall_painter_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_color_plan_viewers_plan_key (project_color_plan_id, viewer_key),
  KEY idx_project_color_plan_viewers_plan (project_color_plan_id),
  CONSTRAINT fk_project_color_plan_viewers_plan
    FOREIGN KEY (project_color_plan_id)
    REFERENCES project_color_plans(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_color_plan_viewer_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_color_plan_viewer_id BIGINT UNSIGNED NOT NULL,
  photo_library_id INT UNSIGNED NULL,
  rel_path VARCHAR(512) NOT NULL,
  photo_type VARCHAR(16) NOT NULL DEFAULT 'FULL',
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_color_plan_viewer_photos_viewer (project_color_plan_viewer_id),
  KEY idx_project_color_plan_viewer_photos_order (project_color_plan_viewer_id, order_index),
  KEY idx_project_color_plan_viewer_photos_photo_library_id (photo_library_id),
  CONSTRAINT fk_project_color_plan_viewer_photos_viewer
    FOREIGN KEY (project_color_plan_viewer_id)
    REFERENCES project_color_plan_viewers(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_color_plan_viewer_photos_photo_library
    FOREIGN KEY (photo_library_id)
    REFERENCES photo_library(photo_library_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
