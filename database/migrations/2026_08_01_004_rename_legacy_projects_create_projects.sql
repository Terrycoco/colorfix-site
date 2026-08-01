SET @has_old_projects := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'slug'
);

SET @has_new_projects := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND COLUMN_NAME = 'property_id'
);

SET @has_legacy_projects := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'legacy_projects'
);

SET @has_project_links := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_links'
);

SET @has_legacy_project_links := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'legacy_project_links'
);

SET @rename_projects_sql := IF(
    @has_old_projects > 0 AND @has_new_projects = 0 AND @has_legacy_projects = 0 AND @has_project_links > 0 AND @has_legacy_project_links = 0,
    'RENAME TABLE project_links TO legacy_project_links, projects TO legacy_projects',
    IF(
        @has_old_projects > 0 AND @has_new_projects = 0 AND @has_legacy_projects = 0,
        'RENAME TABLE projects TO legacy_projects',
        'SELECT 1'
    )
);
PREPARE rename_projects_stmt FROM @rename_projects_sql;
EXECUTE rename_projects_stmt;
DEALLOCATE PREPARE rename_projects_stmt;

CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    property_id BIGINT UNSIGNED NOT NULL,
    project_type_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'prospect',
    notes TEXT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_projects_property_id (property_id),
    INDEX idx_projects_project_type_id (project_type_id),
    INDEX idx_projects_status (status),

    CONSTRAINT fk_projects_property
        FOREIGN KEY (property_id)
        REFERENCES properties(id),

    CONSTRAINT fk_projects_project_type
        FOREIGN KEY (project_type_id)
        REFERENCES project_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
