CREATE TABLE IF NOT EXISTS milestone_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    preview_count TINYINT UNSIGNED NOT NULL DEFAULT 3,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_milestone_category_order (sort_order),
    INDEX idx_milestone_category_archived (is_archived),
    INDEX idx_milestone_category_deleted (deleted_at)
);

CREATE TABLE IF NOT EXISTS milestones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    achieved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_milestones_category
        FOREIGN KEY (category_id)
        REFERENCES milestone_categories(id),
    INDEX idx_milestone_category_order (category_id, sort_order),
    INDEX idx_milestone_achieved (achieved_at),
    INDEX idx_milestone_deleted (deleted_at)
);
