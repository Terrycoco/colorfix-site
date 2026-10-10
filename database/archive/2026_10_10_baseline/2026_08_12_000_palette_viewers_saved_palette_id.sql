-- Make canonical Palette Viewers explicitly belong to one Saved Palette.
-- This validates existing temporary source rows before dropping source_type/source_id.

SET @has_source_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'source_type'
);

SET @has_source_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'source_id'
);

SET @sql := IF(
    @has_source_type = 1 AND @has_source_id = 1,
    "SELECT COUNT(*) INTO @bad_palette_viewers
       FROM palette_viewers pv
       LEFT JOIN saved_palettes sp
         ON sp.id = pv.source_id
      WHERE pv.source_type <> 'saved_palette'
         OR pv.source_id IS NULL
         OR sp.id IS NULL",
    "SET @bad_palette_viewers := 0"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @bad_palette_viewers = 0,
    'SELECT 1',
    'ALTER TABLE __palette_viewers_incompatible_source_rows__ ADD COLUMN fail INT'
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
    @has_saved_palette_id = 0,
    'ALTER TABLE palette_viewers ADD COLUMN saved_palette_id BIGINT UNSIGNED NULL AFTER palette_viewer_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_source_type = 1 AND @has_source_id = 1,
    "UPDATE palette_viewers
        SET saved_palette_id = source_id
      WHERE saved_palette_id IS NULL
        AND source_type = 'saved_palette'",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @bad_saved_palette_ids
FROM palette_viewers pv
LEFT JOIN saved_palettes sp
  ON sp.id = pv.saved_palette_id
WHERE pv.saved_palette_id IS NULL
   OR sp.id IS NULL;

SET @sql := IF(
    @bad_saved_palette_ids = 0,
    'SELECT 1',
    'ALTER TABLE __palette_viewers_invalid_saved_palette_ids__ ADD COLUMN fail INT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE palette_viewers
    MODIFY saved_palette_id BIGINT UNSIGNED NOT NULL;

SET @has_saved_palette_index := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'saved_palette_id'
      AND INDEX_NAME <> 'PRIMARY'
);

SET @sql := IF(
    @has_saved_palette_index = 0,
    'CREATE INDEX idx_palette_viewers_saved_palette_id ON palette_viewers (saved_palette_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_saved_palette_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'saved_palette_id'
      AND REFERENCED_TABLE_NAME = 'saved_palettes'
      AND REFERENCED_COLUMN_NAME = 'id'
);

SET @sql := IF(
    @has_saved_palette_fk = 0,
    'ALTER TABLE palette_viewers ADD CONSTRAINT fk_palette_viewers_saved_palette FOREIGN KEY (saved_palette_id) REFERENCES saved_palettes(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @source_index_drops := (
    SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', REPLACE(INDEX_NAME, '`', '``'), '`') SEPARATOR ', ')
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME IN ('source_type', 'source_id')
      AND INDEX_NAME <> 'PRIMARY'
);

SET @sql := IF(
    @source_index_drops IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE palette_viewers ', @source_index_drops)
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_source_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'palette_viewers'
      AND COLUMN_NAME = 'source_type'
);

SET @sql := IF(
    @has_source_type = 1,
    'ALTER TABLE palette_viewers DROP COLUMN source_type',
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
    @has_source_id = 1,
    'ALTER TABLE palette_viewers DROP COLUMN source_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Validation/reporting queries for manual inspection after running this migration:
-- SELECT 'palette_viewers' AS metric, COUNT(*) AS value FROM palette_viewers;
-- SELECT 'palette_viewer_photos' AS metric, COUNT(*) AS value FROM palette_viewer_photos;
-- SELECT 'palette_viewers_without_saved_palette' AS metric, COUNT(*) AS value FROM palette_viewers pv LEFT JOIN saved_palettes sp ON sp.id = pv.saved_palette_id WHERE sp.id IS NULL;
-- SELECT saved_palette_id, COUNT(*) AS viewer_count FROM palette_viewers GROUP BY saved_palette_id HAVING COUNT(*) > 1 ORDER BY viewer_count DESC, saved_palette_id;
