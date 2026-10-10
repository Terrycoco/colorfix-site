ALTER TABLE applied_palettes
  ADD COLUMN needs_rerender TINYINT(1) NOT NULL DEFAULT 0 AFTER updated_at;
