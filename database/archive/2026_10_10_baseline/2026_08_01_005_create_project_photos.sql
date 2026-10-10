CREATE TABLE IF NOT EXISTS project_photos (
    project_id BIGINT UNSIGNED NOT NULL,
    photo_library_id INT UNSIGNED NOT NULL,

    `use` TINYINT(1) NOT NULL DEFAULT 0,
    palette_id BIGINT UNSIGNED NULL,
    zoom TINYINT(1) NOT NULL DEFAULT 1,
    main TINYINT(1) NOT NULL DEFAULT 0,
    `before` TINYINT(1) NOT NULL DEFAULT 0,

    PRIMARY KEY (project_id, photo_library_id),
    INDEX idx_project_photos_photo_library_id (photo_library_id),

    CONSTRAINT fk_project_photos_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_project_photos_photo_library
        FOREIGN KEY (photo_library_id)
        REFERENCES photo_library(photo_library_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_project_photos_palette
        FOREIGN KEY (palette_id)
        REFERENCES saved_palettes(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
