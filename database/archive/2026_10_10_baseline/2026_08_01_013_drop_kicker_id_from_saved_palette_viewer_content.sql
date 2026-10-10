SET @has_kicker_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palette_viewer_content'
    AND COLUMN_NAME = 'kicker_id'
);

SET @sql := IF(
  @has_kicker_id > 0,
  'UPDATE saved_palette_viewer_content vc
     LEFT JOIN kickers k
       ON k.kicker_id = vc.kicker_id
      SET vc.kicker_text = CASE
            WHEN vc.kicker_text IS NULL OR TRIM(vc.kicker_text) = '''' THEN k.display_text
            ELSE vc.kicker_text
          END,
          vc.updated_at = NOW()
    WHERE vc.kicker_id IS NOT NULL
      AND k.display_text IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palette_viewer_content'
    AND CONSTRAINT_NAME = 'fk_spvc_kicker'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
  @has_fk > 0,
  'ALTER TABLE saved_palette_viewer_content DROP FOREIGN KEY fk_spvc_kicker',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palette_viewer_content'
    AND INDEX_NAME = 'idx_spvc_kicker_id'
);

SET @sql := IF(
  @has_index > 0,
  'ALTER TABLE saved_palette_viewer_content DROP INDEX idx_spvc_kicker_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_kicker_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'saved_palette_viewer_content'
    AND COLUMN_NAME = 'kicker_id'
);

SET @sql := IF(
  @has_kicker_id > 0,
  'ALTER TABLE saved_palette_viewer_content DROP COLUMN kicker_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
