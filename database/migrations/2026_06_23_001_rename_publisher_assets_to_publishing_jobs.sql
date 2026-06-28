SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publisher_attempts'
    AND COLUMN_NAME = 'publisher_asset_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE publisher_attempts DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule'
    AND COLUMN_NAME = 'publication_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE publication_schedule DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule_attempts'
    AND COLUMN_NAME = 'publication_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE publication_schedule_attempts DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_table := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publisher_assets'
);
SET @has_new_table := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
);
SET @sql := IF(@has_old_table > 0 AND @has_new_table = 0,
  'RENAME TABLE publisher_assets TO publishing_jobs',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'publisher_asset_id'
);
SET @has_new_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publishing_jobs'
    AND COLUMN_NAME = 'publishing_job_id'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
  'ALTER TABLE publishing_jobs CHANGE COLUMN publisher_asset_id publishing_job_id INT NOT NULL AUTO_INCREMENT',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publisher_attempts'
    AND COLUMN_NAME = 'publisher_asset_id'
);
SET @has_new_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publisher_attempts'
    AND COLUMN_NAME = 'publishing_job_id'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
  'ALTER TABLE publisher_attempts CHANGE COLUMN publisher_asset_id publishing_job_id INT NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule'
    AND COLUMN_NAME = 'publication_id'
);
SET @has_new_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule'
    AND COLUMN_NAME = 'publishing_job_id'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
  'ALTER TABLE publication_schedule CHANGE COLUMN publication_id publishing_job_id INT NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule_attempts'
    AND COLUMN_NAME = 'publication_id'
);
SET @has_new_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publication_schedule_attempts'
    AND COLUMN_NAME = 'publishing_job_id'
);
SET @sql := IF(@has_old_col > 0 AND @has_new_col = 0,
  'ALTER TABLE publication_schedule_attempts CHANGE COLUMN publication_id publishing_job_id INT NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_publisher_attempts_publishing_job'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE publisher_attempts ADD CONSTRAINT fk_publisher_attempts_publishing_job FOREIGN KEY (publishing_job_id) REFERENCES publishing_jobs (publishing_job_id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_publication_schedule_publishing_job'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE publication_schedule ADD CONSTRAINT fk_publication_schedule_publishing_job FOREIGN KEY (publishing_job_id) REFERENCES publishing_jobs (publishing_job_id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_publication_schedule_attempts_publishing_job'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE publication_schedule_attempts ADD CONSTRAINT fk_publication_schedule_attempts_publishing_job FOREIGN KEY (publishing_job_id) REFERENCES publishing_jobs (publishing_job_id) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
