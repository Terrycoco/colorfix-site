ALTER TABLE playlist_items
  ADD COLUMN exclude_from_thumbs TINYINT(1) NOT NULL DEFAULT 0;
