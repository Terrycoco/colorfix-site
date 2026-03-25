CREATE TABLE IF NOT EXISTS `user_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(100) NOT NULL,
  `playlist_id` BIGINT UNSIGNED NULL,
  `playlist_instance_id` BIGINT UNSIGNED NULL,
  `cta_id` BIGINT UNSIGNED NULL,
  `session_id` VARCHAR(100) NULL,
  `referrer` VARCHAR(1000) NULL,
  `user_agent` VARCHAR(1000) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_events_event_type` (`event_type`),
  KEY `idx_user_events_playlist_instance_id` (`playlist_instance_id`),
  KEY `idx_user_events_playlist_id` (`playlist_id`),
  KEY `idx_user_events_cta_id` (`cta_id`),
  KEY `idx_user_events_session_id` (`session_id`),
  KEY `idx_user_events_created_at` (`created_at`),
  KEY `idx_user_events_instance_event_created` (`playlist_instance_id`, `event_type`, `created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE IF EXISTS `user_events`;
