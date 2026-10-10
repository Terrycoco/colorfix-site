ALTER TABLE clients
  ADD COLUMN first_name VARCHAR(120) NULL AFTER name;

UPDATE clients
SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1))
WHERE (first_name IS NULL OR TRIM(first_name) = '')
  AND name IS NOT NULL
  AND TRIM(name) <> '';
