ALTER TABLE playlist_instances
  ADD COLUMN cta_overrides JSON NULL AFTER cta_context_key;
