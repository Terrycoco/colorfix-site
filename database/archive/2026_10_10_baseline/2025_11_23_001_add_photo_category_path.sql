-- 2025_11_23_001_add_photo_category_path.sql
ALTER TABLE photos
  ADD COLUMN category_path VARCHAR(255) NULL AFTER lighting;
