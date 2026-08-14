-- Repair the migrated Cottage before Photo Library row that points at a
-- non-existent un-hashed prepared/base.jpg file. The current prepared image
-- exists at the hashed path below, and canonical Palette Viewer photos resolve
-- through photo_library_id when present.

CREATE TABLE IF NOT EXISTS migration_20260813_photo_library_path_repairs (
    photo_library_id INT UNSIGNED NOT NULL,
    previous_rel_path VARCHAR(1024) NOT NULL,
    new_rel_path VARCHAR(1024) NOT NULL,
    repair_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (photo_library_id, repair_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migration_20260813_photo_library_path_repairs (
    photo_library_id,
    previous_rel_path,
    new_rel_path,
    repair_key
)
SELECT
    photo_library_id,
    rel_path,
    '/photos/exteriors/cottage/PHO_1L6697/prepared/base_b5d2e3ad1592.jpg',
    'cottage_before_base_hash'
FROM photo_library
WHERE photo_library_id = 381
  AND rel_path = '/photos/exteriors/cottage/PHO_1L6697/prepared/base.jpg'
  AND NOT EXISTS (
      SELECT 1
      FROM migration_20260813_photo_library_path_repairs existing
      WHERE existing.photo_library_id = photo_library.photo_library_id
        AND existing.repair_key = 'cottage_before_base_hash'
  );

UPDATE photo_library pl
INNER JOIN migration_20260813_photo_library_path_repairs m
    ON m.photo_library_id = pl.photo_library_id
SET pl.rel_path = m.new_rel_path,
    pl.updated_at = NOW()
WHERE m.repair_key = 'cottage_before_base_hash'
  AND pl.rel_path = CONVERT(m.previous_rel_path USING utf8mb4) COLLATE utf8mb4_general_ci;

-- Validation:
-- SELECT photo_library_id, rel_path FROM photo_library WHERE photo_library_id = 381;
