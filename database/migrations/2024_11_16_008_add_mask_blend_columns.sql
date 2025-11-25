-- 2024_11_16_008_add_mask_blend_columns.sql
-- Adds per-tier overlay blend settings for mask variants

ALTER TABLE photos_variants
  ADD COLUMN overlay_mode_dark VARCHAR(32) NULL AFTER updated_at,
  ADD COLUMN overlay_opacity_dark DECIMAL(4,2) NULL AFTER overlay_mode_dark,
  ADD COLUMN overlay_mode_medium VARCHAR(32) NULL AFTER overlay_opacity_dark,
  ADD COLUMN overlay_opacity_medium DECIMAL(4,2) NULL AFTER overlay_mode_medium,
  ADD COLUMN overlay_mode_light VARCHAR(32) NULL AFTER overlay_opacity_medium,
  ADD COLUMN overlay_opacity_light DECIMAL(4,2) NULL AFTER overlay_mode_light;
