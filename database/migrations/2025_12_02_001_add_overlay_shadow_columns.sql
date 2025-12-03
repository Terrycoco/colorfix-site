ALTER TABLE photos_variants
  ADD COLUMN overlay_shadow_l_offset FLOAT DEFAULT NULL,
  ADD COLUMN overlay_shadow_tint CHAR(7) DEFAULT NULL,
  ADD COLUMN overlay_shadow_tint_opacity FLOAT DEFAULT NULL;
