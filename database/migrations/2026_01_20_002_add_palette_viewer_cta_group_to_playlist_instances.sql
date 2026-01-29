ALTER TABLE playlist_instances
  ADD COLUMN palette_viewer_cta_group_id INT NULL AFTER cta_group_id;

-- DOWN
-- ALTER TABLE playlist_instances DROP COLUMN palette_viewer_cta_group_id;
