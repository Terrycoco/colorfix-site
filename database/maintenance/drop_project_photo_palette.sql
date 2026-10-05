-- Run only AFTER deploying and verifying the Library-owned palette code.
-- Preserve this old column until the copy and Before-photo room assignments are complete.
CREATE TEMPORARY TABLE project_photo_palette_copy_must_be_complete (id INT PRIMARY KEY);
INSERT INTO project_photo_palette_copy_must_be_complete VALUES (1);
INSERT INTO project_photo_palette_copy_must_be_complete
SELECT 1 WHERE EXISTS (
    SELECT 1 FROM project_photos pp JOIN photo_library pl ON pl.photo_library_id = pp.photo_library_id
    WHERE (pp.`before` = 0 AND pp.palette_id IS NOT NULL AND NOT (pp.palette_id <=> pl.palette_id))
       OR (pp.`before` = 1 AND pp.palette_id IS NOT NULL AND pp.room_id IS NULL)
       OR (pp.`before` = 1 AND pl.palette_id IS NOT NULL)
);

SET @foreign_keys = (SELECT GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', constraint_name, '`'))
    FROM information_schema.key_column_usage WHERE table_schema = DATABASE()
    AND table_name = 'project_photos' AND column_name = 'palette_id' AND referenced_table_name IS NOT NULL);
SET @sql = IF(@foreign_keys IS NULL, 'SELECT 1', CONCAT('ALTER TABLE project_photos ', @foreign_keys));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
ALTER TABLE project_photos DROP COLUMN palette_id;
DROP TEMPORARY TABLE project_photo_palette_copy_must_be_complete;
