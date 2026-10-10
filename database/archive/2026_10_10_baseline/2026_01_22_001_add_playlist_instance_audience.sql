ALTER TABLE playlist_instances
  ADD COLUMN audience VARCHAR(50) NULL AFTER cta_context_key;

-- Down
ALTER TABLE playlist_instances
  DROP COLUMN audience;
