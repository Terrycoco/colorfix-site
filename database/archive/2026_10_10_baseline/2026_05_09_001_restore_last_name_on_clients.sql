SET @has_last_name := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'last_name'
);

SET @alter_sql := IF(
  @has_last_name = 0,
  'ALTER TABLE clients ADD COLUMN last_name VARCHAR(120) NULL AFTER first_name',
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE clients
SET
  first_name = CASE
    WHEN TRIM(COALESCE(first_name, '')) <> '' THEN TRIM(first_name)
    WHEN TRIM(COALESCE(name, '')) = '' THEN NULL
    ELSE TRIM(SUBSTRING_INDEX(TRIM(name), ' ', 1))
  END,
  last_name = CASE
    WHEN TRIM(COALESCE(last_name, '')) <> '' THEN TRIM(last_name)
    WHEN TRIM(COALESCE(name, '')) = '' THEN NULL
    WHEN INSTR(TRIM(name), ' ') > 0 THEN TRIM(SUBSTRING_INDEX(TRIM(name), ' ', -1))
    ELSE NULL
  END
WHERE
  TRIM(COALESCE(first_name, '')) = ''
  OR TRIM(COALESCE(last_name, '')) = '';
