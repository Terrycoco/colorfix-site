SET FOREIGN_KEY_CHECKS = 0;

SET @fk_client := (
  SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palettes'
    AND COLUMN_NAME = 'client_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @drop_fk_client := IF(@fk_client IS NULL, 'SELECT 1', CONCAT('ALTER TABLE saved_palettes DROP FOREIGN KEY ', @fk_client));
PREPARE stmt FROM @drop_fk_client; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_email := (
  SELECT INDEX_NAME
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palettes'
    AND COLUMN_NAME = 'sent_to_email'
  LIMIT 1
);
SET @drop_idx_email := IF(@idx_email IS NULL, 'SELECT 1', CONCAT('DROP INDEX ', @idx_email, ' ON saved_palettes'));
PREPARE stmt FROM @drop_idx_email; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_client := (
  SELECT INDEX_NAME
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palettes'
    AND COLUMN_NAME = 'client_id'
  LIMIT 1
);
SET @drop_idx_client := IF(@idx_client IS NULL, 'SELECT 1', CONCAT('DROP INDEX ', @idx_client, ' ON saved_palettes'));
PREPARE stmt FROM @drop_idx_client; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE saved_palettes
  DROP COLUMN client_id,
  DROP COLUMN sent_to_email,
  DROP COLUMN sent_at;

SET FOREIGN_KEY_CHECKS = 1;
