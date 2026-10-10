-- Additive phase: deploy the new readers/writers before running the separate drop script.
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'photo_library' AND column_name = 'palette_id'), 'SELECT 1',
    'ALTER TABLE photo_library ADD COLUMN palette_id BIGINT UNSIGNED NULL, ADD INDEX idx_photo_library_palette (palette_id), ADD CONSTRAINT fk_photo_library_palette FOREIGN KEY (palette_id) REFERENCES saved_palettes(id) ON DELETE SET NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'project_photos' AND column_name = 'room_id'), 'SELECT 1',
    'ALTER TABLE project_photos ADD COLUMN room_id VARCHAR(64) NULL AFTER photo_library_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A duplicate key stops the migration rather than choosing among conflicting palettes.
-- Obsolete photo-set membership is not a source of photo palette assignments.
DROP TEMPORARY TABLE IF EXISTS photo_palette_conflicts_must_be_resolved;
CREATE TEMPORARY TABLE photo_palette_conflicts_must_be_resolved (
    photo_library_id INT UNSIGNED PRIMARY KEY,
    palette_id BIGINT UNSIGNED NOT NULL
);
INSERT INTO photo_palette_conflicts_must_be_resolved
SELECT DISTINCT photo_library_id, palette_id FROM (
    SELECT pp.photo_library_id, pp.palette_id FROM project_photos pp
        WHERE pp.`before` = 0 AND pp.palette_id IS NOT NULL
    UNION ALL
    SELECT pl.photo_library_id, p.saved_palette_id FROM photo_library pl
        JOIN saved_palette_photos p ON pl.source_type = 'saved_palette_photo' AND pl.source_id = p.id
        WHERE p.photo_type <> 'before'
    UNION ALL
    SELECT photo_library_id, palette_id FROM photo_library WHERE palette_id IS NOT NULL
) candidates;

-- Also reject a photo used as both Before and After; it cannot have both meanings.
INSERT INTO photo_palette_conflicts_must_be_resolved
SELECT DISTINCT pp.photo_library_id, c.palette_id FROM project_photos pp
JOIN photo_palette_conflicts_must_be_resolved c ON c.photo_library_id = pp.photo_library_id
WHERE pp.`before` = 1;

START TRANSACTION;
UPDATE project_photos pp
JOIN project_palettes pal ON pal.project_id = pp.project_id AND pal.saved_palette_id = pp.palette_id
JOIN projects p ON p.id = pp.project_id
SET pp.room_id = JSON_UNQUOTE(JSON_EXTRACT(p.rooms,
    REPLACE(JSON_UNQUOTE(JSON_SEARCH(p.rooms, 'one', TRIM(pal.area_label), NULL, '$[*].name')), '.name', '.id')))
WHERE pp.room_id IS NULL;

UPDATE photo_library pl
JOIN photo_palette_conflicts_must_be_resolved c ON c.photo_library_id = pl.photo_library_id
SET pl.palette_id = c.palette_id, pl.has_palette = 1;

UPDATE photo_library pl
JOIN project_photos pp ON pp.photo_library_id = pl.photo_library_id AND pp.`before` = 1
SET pl.palette_id = NULL, pl.has_palette = 0;
COMMIT;
DROP TEMPORARY TABLE photo_palette_conflicts_must_be_resolved;
