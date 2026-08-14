-- Roll back only the path repaired by
-- 2026_08_13_006_repair_cottage_before_photo_library_path.sql.

CREATE TABLE IF NOT EXISTS migration_20260813_photo_library_path_repairs (
    photo_library_id INT UNSIGNED NOT NULL,
    previous_rel_path VARCHAR(1024) NOT NULL,
    new_rel_path VARCHAR(1024) NOT NULL,
    repair_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (photo_library_id, repair_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE photo_library pl
INNER JOIN migration_20260813_photo_library_path_repairs m
    ON m.photo_library_id = pl.photo_library_id
SET pl.rel_path = m.previous_rel_path,
    pl.updated_at = NOW()
WHERE m.repair_key = 'cottage_before_base_hash'
  AND pl.rel_path = CONVERT(m.new_rel_path USING utf8mb4) COLLATE utf8mb4_general_ci;

DELETE FROM migration_20260813_photo_library_path_repairs
WHERE repair_key = 'cottage_before_base_hash';
