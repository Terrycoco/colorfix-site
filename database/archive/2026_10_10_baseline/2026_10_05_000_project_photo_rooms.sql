SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'project_photos' AND column_name = 'room_id'), 'SELECT 1',
    'ALTER TABLE project_photos ADD COLUMN room_id VARCHAR(64) NULL AFTER photo_library_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE project_photos pp
JOIN project_palettes pal ON pal.project_id = pp.project_id AND pal.saved_palette_id = pp.palette_id
JOIN projects p ON p.id = pp.project_id
SET pp.room_id = JSON_UNQUOTE(JSON_EXTRACT(p.rooms,
    REPLACE(JSON_UNQUOTE(JSON_SEARCH(p.rooms, 'one', TRIM(pal.area_label), NULL, '$[*].name')), '.name', '.id')))
WHERE pp.room_id IS NULL;
