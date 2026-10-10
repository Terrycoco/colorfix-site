CREATE TABLE player_experiences (
    player_experience_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    experience_key VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    slide_flag VARCHAR(50) NOT NULL,
    palette_viewer_key VARCHAR(50) NOT NULL,
    cta_page_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (player_experience_id),
    UNIQUE KEY uq_player_experiences_experience_key (experience_key),
    KEY idx_player_experiences_cta_page_id (cta_page_id),
    KEY idx_player_experiences_active_sort (is_active, sort_order),

    CONSTRAINT fk_player_experiences_cta_page
        FOREIGN KEY (cta_page_id)
        REFERENCES cta_groups (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
