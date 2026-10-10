-- Property / Project workflow schema updates.
-- This migration preserves legacy columns and image data.

ALTER TABLE properties
    MODIFY COLUMN address_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN name VARCHAR(255) NOT NULL;

SET @clients_has_mailing_address_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'mailing_address_id'
);
SET @sql := IF(
    @clients_has_mailing_address_id = 0,
    'ALTER TABLE clients ADD COLUMN mailing_address_id BIGINT UNSIGNED NULL AFTER notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @clients_has_mailing_address_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND INDEX_NAME = 'idx_clients_mailing_address_id'
);
SET @sql := IF(
    @clients_has_mailing_address_idx = 0,
    'ALTER TABLE clients ADD INDEX idx_clients_mailing_address_id (mailing_address_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @clients_has_mailing_address_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND CONSTRAINT_NAME = 'fk_clients_mailing_address'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
    @clients_has_mailing_address_fk = 0,
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_mailing_address FOREIGN KEY (mailing_address_id) REFERENCES addresses(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @projects_has_experience_key := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'experience_key'
);
SET @sql := IF(
    @projects_has_experience_key = 0,
    'ALTER TABLE projects ADD COLUMN experience_key VARCHAR(30) NOT NULL DEFAULT ''concept'' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @projects_has_slug := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'slug'
);
SET @sql := IF(
    @projects_has_slug = 0,
    'ALTER TABLE projects ADD COLUMN slug VARCHAR(255) NULL AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @projects_has_slug_uq := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND INDEX_NAME = 'uq_projects_slug'
);
SET @sql := IF(
    @projects_has_slug_uq = 0,
    'ALTER TABLE projects ADD UNIQUE KEY uq_projects_slug (slug)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @projects_has_experience_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND INDEX_NAME = 'idx_projects_experience_key'
);
SET @sql := IF(
    @projects_has_experience_idx = 0,
    'ALTER TABLE projects ADD INDEX idx_projects_experience_key (experience_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_playlists_has_is_current := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_playlists'
      AND COLUMN_NAME = 'is_current'
);
SET @sql := IF(
    @project_playlists_has_is_current = 0,
    'ALTER TABLE project_playlists ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0 AFTER playlist_id, ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_current, ADD COLUMN revision_number INT UNSIGNED NULL AFTER is_locked, ADD COLUMN label VARCHAR(255) NULL AFTER revision_number, ADD COLUMN locked_at DATETIME NULL AFTER label, ADD COLUMN superseded_at DATETIME NULL AFTER locked_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @project_playlists_has_current_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_playlists'
      AND INDEX_NAME = 'idx_project_playlists_project_current'
);
SET @sql := IF(
    @project_playlists_has_current_idx = 0,
    'ALTER TABLE project_playlists ADD INDEX idx_project_playlists_project_current (project_id, is_current)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @saved_palette_photos_has_photo_library_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'saved_palette_photos'
      AND COLUMN_NAME = 'photo_library_id'
);
SET @sql := IF(
    @saved_palette_photos_has_photo_library_id = 0,
    'ALTER TABLE saved_palette_photos ADD COLUMN photo_library_id INT UNSIGNED NULL AFTER saved_palette_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @saved_palette_photos_has_photo_library_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'saved_palette_photos'
      AND INDEX_NAME = 'idx_saved_palette_photos_photo_library_id'
);
SET @sql := IF(
    @saved_palette_photos_has_photo_library_idx = 0,
    'ALTER TABLE saved_palette_photos ADD INDEX idx_saved_palette_photos_photo_library_id (photo_library_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE saved_palette_photos spp
JOIN (
    SELECT rel_path, MIN(photo_library_id) AS photo_library_id
    FROM photo_library
    GROUP BY rel_path
) pl
  ON pl.rel_path = spp.rel_path
SET spp.photo_library_id = pl.photo_library_id
WHERE spp.photo_library_id IS NULL;

SET @saved_palette_photos_has_photo_library_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'saved_palette_photos'
      AND CONSTRAINT_NAME = 'fk_saved_palette_photos_photo_library'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
    @saved_palette_photos_has_photo_library_fk = 0,
    'ALTER TABLE saved_palette_photos ADD CONSTRAINT fk_saved_palette_photos_photo_library FOREIGN KEY (photo_library_id) REFERENCES photo_library(photo_library_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE player_experiences
SET name = 'Concept'
WHERE experience_key = 'prospect'
  AND name = 'Prospect';
