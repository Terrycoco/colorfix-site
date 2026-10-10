CREATE TABLE IF NOT EXISTS project_photos (
    project_id BIGINT UNSIGNED NOT NULL,
    photo_library_id INT UNSIGNED NOT NULL,
    `use` TINYINT(1) NOT NULL DEFAULT 1,
    palette_id BIGINT UNSIGNED NULL,
    zoom TINYINT(1) NOT NULL DEFAULT 1,
    main TINYINT(1) NOT NULL DEFAULT 0,
    `before` TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (project_id, photo_library_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (photo_library_id) REFERENCES photo_library(photo_library_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @project_photo_order_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'project_photos' AND column_name = 'sort_order'),
    'SELECT 1',
    'ALTER TABLE project_photos ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0'
);
PREPARE project_photo_order_stmt FROM @project_photo_order_sql;
EXECUTE project_photo_order_stmt;
DEALLOCATE PREPARE project_photo_order_stmt;
