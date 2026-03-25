ALTER TABLE playlist_instance_set_items
  ADD COLUMN subtitle VARCHAR(255) NOT NULL DEFAULT '' AFTER title;
