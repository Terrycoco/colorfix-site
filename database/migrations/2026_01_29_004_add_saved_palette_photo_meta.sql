-- 2026_01_29_004_add_saved_palette_photo_meta.sql
-- Adds trigger color + photo type metadata to saved palette photos.

ALTER TABLE saved_palette_photos
  ADD COLUMN photo_type VARCHAR(16) NOT NULL DEFAULT 'full' AFTER rel_path,
  ADD COLUMN trigger_color_id BIGINT UNSIGNED NULL AFTER photo_type,
  ADD KEY idx_saved_palette_photos_trigger (trigger_color_id);
