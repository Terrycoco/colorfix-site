ALTER TABLE `mask_blend_settings`
  ADD COLUMN `shadow_l_offset` FLOAT DEFAULT NULL,
  ADD COLUMN `shadow_tint_hex` CHAR(7) COLLATE utf8_unicode_ci DEFAULT NULL,
  ADD COLUMN `shadow_tint_opacity` FLOAT DEFAULT NULL;
