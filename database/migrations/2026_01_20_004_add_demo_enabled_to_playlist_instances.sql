ALTER TABLE playlist_instances
  ADD COLUMN demo_enabled TINYINT(1) NOT NULL DEFAULT 0
  AFTER thumbs_enabled;

-- DOWN
-- ALTER TABLE playlist_instances DROP COLUMN demo_enabled;
