ALTER TABLE supercat_clauses
  DROP COLUMN neutral_category_id,
  DROP COLUMN hue_category_id,
  DROP COLUMN light_category_id,
  DROP COLUMN chroma_category_id;

ALTER TABLE supercat_clauses
  ADD COLUMN neutral_name VARCHAR(80) DEFAULT NULL,
  ADD COLUMN hue_name VARCHAR(80) DEFAULT NULL,
  ADD COLUMN light_name VARCHAR(80) DEFAULT NULL,
  ADD COLUMN chroma_name VARCHAR(80) DEFAULT NULL;
