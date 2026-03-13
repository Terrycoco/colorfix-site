CREATE TABLE IF NOT EXISTS `project_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `asset_type` VARCHAR(50) NOT NULL,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(100) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project_links_project_id` (`project_id`),
  KEY `idx_project_links_asset` (`asset_type`, `asset_id`),
  KEY `idx_project_links_project_asset_type` (`project_id`, `asset_type`),
  UNIQUE KEY `uq_project_links_project_asset_role` (`project_id`, `asset_type`, `asset_id`, `role`),
  CONSTRAINT `fk_project_links_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO `project_links` (
  `project_id`,
  `asset_type`,
  `asset_id`,
  `role`,
  `sort_order`,
  `notes`
) VALUES (
  1,
  'photo',
  96,
  'living-room-before',
  10,
  'Original living room photo for Mojdeh project.'
);

INSERT INTO `project_links` (
  `project_id`,
  `asset_type`,
  `asset_id`,
  `role`,
  `sort_order`,
  `notes`
) VALUES (
  1,
  'photo',
  92,
  'living-room-after',
  20,
  'Updated living room photo for Mojdeh project.'
);

INSERT INTO `project_links` (
  `project_id`,
  `asset_type`,
  `asset_id`,
  `role`,
  `sort_order`,
  `notes`
) VALUES (
  1,
  'playlist',
  13,
  'main',
  30,
  'Primary playlist for Mojdeh Interior Makeover.'
);

INSERT INTO `project_links` (
  `project_id`,
  `asset_type`,
  `asset_id`,
  `role`,
  `sort_order`,
  `notes`
) VALUES (
  1,
  'palette',
  91,
  'living-room',
  40,
  'Palette used for the main living room after concept.'
);

INSERT INTO `project_links` (
  `project_id`,
  `asset_type`,
  `asset_id`,
  `role`,
  `sort_order`,
  `notes`
) VALUES (
  1,
  'pin',
  501,
  'before-after',
  50,
  'Pinterest asset for Mojdeh before/after story.'
);

-- Rollback:
-- DROP TABLE IF EXISTS `project_links`;
