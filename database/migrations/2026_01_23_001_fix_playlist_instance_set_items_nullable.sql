SET FOREIGN_KEY_CHECKS = 0;

SET @fk_name := (
  SELECT kcu.constraint_name
  FROM information_schema.key_column_usage kcu
  WHERE kcu.table_schema = DATABASE()
    AND kcu.table_name = 'playlist_instance_set_items'
    AND kcu.column_name = 'playlist_instance_id'
    AND kcu.referenced_table_name IS NOT NULL
  LIMIT 1
);
SET @drop_fk := IF(
  @fk_name IS NULL,
  'SELECT 1',
  'ALTER TABLE playlist_instance_set_items DROP FOREIGN KEY fk_playlist_instance_set_items_instance'
);
PREPARE stmt FROM @drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE playlist_instance_set_items
  MODIFY COLUMN playlist_instance_id INT NULL DEFAULT NULL;

UPDATE playlist_instance_set_items
SET playlist_instance_id = NULL
WHERE item_type = 'set'
   OR playlist_instance_id IS NULL
   OR playlist_instance_id <= 0;

UPDATE playlist_instance_set_items psi
LEFT JOIN playlist_instances pi
  ON psi.playlist_instance_id = pi.playlist_instance_id
SET psi.playlist_instance_id = NULL
WHERE psi.playlist_instance_id IS NOT NULL
  AND pi.playlist_instance_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Down (intentionally omitted to avoid FK re-add failures on legacy data)
