CREATE TABLE palette_viewer_links (
    palette_viewer_link_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(16) NOT NULL,
    token TEXT NOT NULL,
    payload_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (palette_viewer_link_id),
    UNIQUE KEY uq_palette_viewer_links_code (code)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
