SET @has_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND INDEX_NAME = 'idx_clients_last_first_id'
);

SET @create_index_sql := IF(
  @has_index = 0,
  'ALTER TABLE clients ADD INDEX idx_clients_last_first_id (last_name, first_name, id)',
  'SELECT 1'
);

PREPARE stmt FROM @create_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
