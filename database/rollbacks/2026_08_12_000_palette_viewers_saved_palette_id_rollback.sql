-- Roll back Palette Viewers to the temporary source_type/source_id model.
-- Viewer and photo rows are preserved.

SET @has_source_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'source_type'
);

SET @sql := IF(
    @has_source_type = 0,
    'ALTER TABLE palette_viewers ADD COLUMN source_type VARCHAR(50) NULL AFTER palette_viewer_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_source_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'source_id'
);

SET @sql := IF(
    @has_source_id = 0,
    'ALTER TABLE palette_viewers ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE palette_viewers
SET
    source_type = 'saved_palette',
    source_id = saved_palette_id
WHERE source_type IS NULL
   OR source_id IS NULL;

SELECT COUNT(*) INTO @bad_source_columns
FROM palette_viewers
WHERE source_type IS NULL
   OR source_type = ''
   OR source_id IS NULL
   OR source_id <= 0;

SET @sql := IF(
    @bad_source_columns = 0,
    'SELECT 1',
    'ALTER TABLE __palette_viewers_invalid_rollback_source_rows__ ADD COLUMN fail INT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE palette_viewers
    MODIFY source_type VARCHAR(50) NOT NULL,
    MODIFY source_id BIGINT UNSIGNED NOT NULL;

SET @has_source_index := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND INDEX_NAME = 'idx_palette_viewers_source'
);

SET @sql := IF(
    @has_source_index = 0,
    'CREATE INDEX idx_palette_viewers_source ON palette_viewers (source_type, source_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @saved_palette_fk := (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'saved_palette_id'
      AND REFERENCED_TABLE_NAME = 'saved_palettes'
      AND REFERENCED_COLUMN_NAME = 'id'
    LIMIT 1
);

SET @sql := IF(
    @saved_palette_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE palette_viewers DROP FOREIGN KEY `', REPLACE(@saved_palette_fk, '`', '``'), '`')
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @saved_palette_index_drops := (
    SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', REPLACE(INDEX_NAME, '`', '``'), '`') SEPARATOR ', ')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'saved_palette_id'
      AND INDEX_NAME <> 'PRIMARY'
);

SET @sql := IF(
    @saved_palette_index_drops IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE palette_viewers ', @saved_palette_index_drops)
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_saved_palette_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'saved_palette_id'
);

SET @sql := IF(
    @has_saved_palette_id = 1,
    'ALTER TABLE palette_viewers DROP COLUMN saved_palette_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
