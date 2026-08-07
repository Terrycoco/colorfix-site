-- Private Project Color Plans.
--
-- These tables intentionally do not alter or link to the public saved_palette
-- tables. Most overlapping fields mirror the saved-palette schema exactly.
-- The one intentional type difference is project_color_plan_members.color_id:
-- saved_palette_members.color_id is BIGINT UNSIGNED, but colors.id is INT.
-- This table uses INT NOT NULL so referential integrity to colors(id) works.

CREATE TABLE IF NOT EXISTS project_color_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  palette_type VARCHAR(20) NOT NULL DEFAULT 'exterior',
  nickname VARCHAR(255) NULL,
  notes TEXT NULL,
  private_notes TEXT NULL,
  area_name VARCHAR(255) NULL,
  scheme_title VARCHAR(255) NULL,
  revision_number INT UNSIGNED NOT NULL DEFAULT 1,
  issued_at DATETIME NULL,
  locked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_color_plans_project_id (project_id),
  KEY idx_project_color_plans_project_area (project_id, area_name),
  KEY idx_project_color_plans_issued_at (issued_at),
  CONSTRAINT fk_project_color_plans_project
    FOREIGN KEY (project_id)
    REFERENCES projects(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_color_plan_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_color_plan_id BIGINT UNSIGNED NOT NULL,
  color_id INT NOT NULL,
  role_name VARCHAR(64) NULL,
  sheen VARCHAR(64) NULL,
  note TEXT NULL,
  order_index INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_project_color_plan_members_plan (project_color_plan_id),
  KEY idx_project_color_plan_members_plan_order (project_color_plan_id, order_index),
  KEY idx_project_color_plan_members_color_id (color_id),
  CONSTRAINT fk_project_color_plan_members_plan
    FOREIGN KEY (project_color_plan_id)
    REFERENCES project_color_plans(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_project_color_plan_members_color
    FOREIGN KEY (color_id)
    REFERENCES colors(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
