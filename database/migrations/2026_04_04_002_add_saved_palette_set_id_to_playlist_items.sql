ALTER TABLE playlist_items
  ADD COLUMN saved_palette_set_id INT NULL AFTER photo_library_id,
  ADD KEY idx_playlist_items_saved_palette_set_id (saved_palette_set_id);
