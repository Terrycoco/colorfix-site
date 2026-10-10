CREATE TABLE IF NOT EXISTS client_types (
  client_type_id INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_type_id),
  UNIQUE KEY uniq_client_types_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO client_types (`key`, label, sort_order, is_active)
VALUES
  ('homeowner', 'Homeowner', 10, 1),
  ('contractor', 'Contractor', 20, 1),
  ('hoa', 'HOA', 30, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

SET @has_client_type_key := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'client_type_key'
);

SET @add_client_type_key_sql := IF(
  @has_client_type_key = 0,
  'ALTER TABLE clients ADD COLUMN client_type_key VARCHAR(50) NOT NULL DEFAULT ''homeowner'' AFTER notes',
  'SELECT 1'
);
PREPARE add_client_type_key_stmt FROM @add_client_type_key_sql;
EXECUTE add_client_type_key_stmt;
DEALLOCATE PREPARE add_client_type_key_stmt;

SET @has_legacy_client_type := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'client_type'
);

SET @backfill_from_legacy_sql := IF(
  @has_legacy_client_type > 0,
  'UPDATE clients
     SET client_type_key = CASE
       WHEN COALESCE(NULLIF(TRIM(client_type), ''''), ''homeowner'') IN (SELECT `key` FROM client_types WHERE is_active = 1)
         THEN COALESCE(NULLIF(TRIM(client_type), ''''), ''homeowner'')
       ELSE ''homeowner''
     END',
  'UPDATE clients SET client_type_key = COALESCE(NULLIF(TRIM(client_type_key), ''''), ''homeowner'')'
);
PREPARE backfill_from_legacy_stmt FROM @backfill_from_legacy_sql;
EXECUTE backfill_from_legacy_stmt;
DEALLOCATE PREPARE backfill_from_legacy_stmt;

