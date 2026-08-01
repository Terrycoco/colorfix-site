CREATE TABLE IF NOT EXISTS project_photos (
    project_photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    project_id BIGINT UNSIGNED NOT NULL,
    photo_library_id INT UNSIGNED NOT NULL,

    role VARCHAR(50) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (project_photo_id),

    UNIQUE KEY uq_project_photos_project_photo (project_id, photo_library_id),
    INDEX idx_project_photos_project_sort (project_id, sort_order),
    INDEX idx_project_photos_photo_library_id (photo_library_id),

    CONSTRAINT fk_project_photos_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_project_photos_photo_library
        FOREIGN KEY (photo_library_id)
        REFERENCES photo_library(photo_library_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
