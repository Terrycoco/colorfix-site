ALTER TABLE applied_palette_entries
  ADD COLUMN mask_setting_id BIGINT UNSIGNED NULL AFTER color_id,
  ADD COLUMN mask_setting_revision INT UNSIGNED NULL AFTER mask_setting_id,
  ADD INDEX idx_ap_entries_mask_setting (mask_setting_id);
