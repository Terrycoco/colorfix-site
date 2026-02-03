-- 2026_01_29_002_add_saved_palette_member_role.sql
-- Adds role metadata to saved palette members.

ALTER TABLE saved_palette_members
  ADD COLUMN role_name VARCHAR(64) NULL AFTER color_id;
