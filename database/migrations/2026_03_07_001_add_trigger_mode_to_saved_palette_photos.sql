ALTER TABLE saved_palette_photos
  ADD COLUMN trigger_mode VARCHAR(10) NOT NULL DEFAULT 'any';
