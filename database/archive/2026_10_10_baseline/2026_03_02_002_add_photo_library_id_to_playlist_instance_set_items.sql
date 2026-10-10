ALTER TABLE playlist_instance_set_items
  ADD COLUMN photo_library_id INT UNSIGNED NULL AFTER photo_url;

ALTER TABLE playlist_instance_set_items
  ADD KEY idx_playlist_instance_set_items_photo (photo_library_id);
