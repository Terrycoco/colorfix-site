ALTER TABLE playlist_instance_sets
  ADD COLUMN subtitle VARCHAR(255) NULL AFTER title;

-- DOWN
-- ALTER TABLE playlist_instance_sets DROP COLUMN subtitle;
