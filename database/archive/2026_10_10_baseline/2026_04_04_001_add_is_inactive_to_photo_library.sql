ALTER TABLE photo_library
  ADD COLUMN is_inactive TINYINT(1) NOT NULL DEFAULT 0 AFTER has_palette;
