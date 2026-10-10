-- 2024_11_16_007_alter_supercat_clause_ranges.sql
-- Adds range/scope columns so supercat clauses can express >=, <=, or between logic

ALTER TABLE supercat_clauses
  ADD COLUMN hue_scope VARCHAR(16) NOT NULL DEFAULT 'exact' AFTER hue_name,
  ADD COLUMN hue_min_name VARCHAR(64) NULL AFTER hue_scope,
  ADD COLUMN hue_max_name VARCHAR(64) NULL AFTER hue_min_name,

  ADD COLUMN light_scope VARCHAR(16) NOT NULL DEFAULT 'exact' AFTER light_name,
  ADD COLUMN light_min_name VARCHAR(64) NULL AFTER light_scope,
  ADD COLUMN light_max_name VARCHAR(64) NULL AFTER light_min_name,

  ADD COLUMN chroma_scope VARCHAR(16) NOT NULL DEFAULT 'exact' AFTER chroma_name,
  ADD COLUMN chroma_min_name VARCHAR(64) NULL AFTER chroma_scope,
  ADD COLUMN chroma_max_name VARCHAR(64) NULL AFTER chroma_min_name;

