ALTER TABLE playlist_instance_sets
  ADD COLUMN end_cta_label VARCHAR(255) NULL AFTER context,
  ADD COLUMN end_cta_url VARCHAR(512) NULL AFTER end_cta_label,
  ADD COLUMN end_cta_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER end_cta_url;

