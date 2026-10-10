-- 2025_11_23_002_add_mask_original_texture.sql
-- Adds per-mask original_texture descriptor for learning blend settings

ALTER TABLE photos_variants
  ADD COLUMN original_texture VARCHAR(64) NULL AFTER role;
