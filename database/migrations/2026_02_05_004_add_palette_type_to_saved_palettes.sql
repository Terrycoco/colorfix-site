ALTER TABLE saved_palettes
  ADD COLUMN palette_type VARCHAR(20) NOT NULL DEFAULT 'exterior' AFTER brand;
