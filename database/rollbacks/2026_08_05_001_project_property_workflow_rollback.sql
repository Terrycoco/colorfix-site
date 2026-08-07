-- Rollback for 2026_08_05_001_project_property_workflow.sql.
-- Run manually only after confirming no new rows depend on these columns.

UPDATE player_experiences
SET name = 'Prospect'
WHERE experience_key = 'prospect'
  AND name = 'Concept';

ALTER TABLE saved_palette_photos
    DROP FOREIGN KEY fk_saved_palette_photos_photo_library;

ALTER TABLE saved_palette_photos
    DROP INDEX idx_saved_palette_photos_photo_library_id;

ALTER TABLE saved_palette_photos
    DROP COLUMN photo_library_id;

ALTER TABLE project_playlists
    DROP INDEX idx_project_playlists_project_current;

ALTER TABLE project_playlists
    DROP COLUMN superseded_at,
    DROP COLUMN locked_at,
    DROP COLUMN label,
    DROP COLUMN revision_number,
    DROP COLUMN is_locked,
    DROP COLUMN is_current;

ALTER TABLE projects
    DROP INDEX idx_projects_experience_key,
    DROP INDEX uq_projects_slug,
    DROP COLUMN slug,
    DROP COLUMN experience_key;

ALTER TABLE clients
    DROP FOREIGN KEY fk_clients_mailing_address;

ALTER TABLE clients
    DROP INDEX idx_clients_mailing_address_id;

ALTER TABLE clients
    DROP COLUMN mailing_address_id;

ALTER TABLE properties
    MODIFY COLUMN address_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN name VARCHAR(255) NULL;
