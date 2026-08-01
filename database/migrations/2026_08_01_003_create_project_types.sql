CREATE TABLE IF NOT EXISTS project_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_project_types_slug (slug),
    INDEX idx_project_types_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO project_types
    (name, slug, is_active, sort_order)
VALUES
    ('Exterior', 'exterior', 1, 10),
    ('Interior', 'interior', 1, 20),
    ('Living Room', 'living-room', 1, 30),
    ('Dining Room', 'dining-room', 1, 40),
    ('Kitchen', 'kitchen', 1, 50),
    ('Bathroom', 'bathroom', 1, 60),
    ('Bedroom', 'bedroom', 1, 70),
    ('Entry', 'entry', 1, 80),
    ('Hallway', 'hallway', 1, 90),
    ('Office', 'office', 1, 100),
    ('Tasting Room', 'tasting-room', 1, 110),
    ('Speakeasy', 'speakeasy', 1, 120),
    ('Other', 'other', 1, 999)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order),
    updated_at = CURRENT_TIMESTAMP;
