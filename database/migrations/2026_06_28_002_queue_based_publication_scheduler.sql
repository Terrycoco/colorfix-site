SET @scheduled_nullable := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule'
    AND COLUMN_NAME = 'scheduled_at'
    AND IS_NULLABLE = 'YES'
);

SET @alter_scheduled_at_sql := IF(
  @scheduled_nullable = 0,
  'ALTER TABLE publication_schedule MODIFY COLUMN scheduled_at DATETIME NULL',
  'SELECT 1'
);

PREPARE stmt FROM @alter_scheduled_at_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE publication_schedule
   SET status = 'waiting',
       scheduled_at = NULL,
       claimed_at = NULL,
       claimed_by = NULL
 WHERE status = 'scheduled'
   AND completed_at IS NULL;
