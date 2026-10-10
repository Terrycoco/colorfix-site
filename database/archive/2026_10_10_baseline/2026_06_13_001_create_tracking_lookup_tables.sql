CREATE TABLE IF NOT EXISTS tracking_audiences (
  tracking_audience_id INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  definition TEXT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tracking_audience_id),
  UNIQUE KEY uniq_tracking_audiences_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracking_sources (
  tracking_source_id INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  definition TEXT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tracking_source_id),
  UNIQUE KEY uniq_tracking_sources_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracking_event_types (
  tracking_event_type_id INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(100) NOT NULL,
  label VARCHAR(120) NOT NULL,
  definition TEXT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tracking_event_type_id),
  UNIQUE KEY uniq_tracking_event_types_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tracking_audiences (`key`, label, definition, sort_order, is_active)
VALUES
  ('any', 'Any', 'Default audience. Normal public-safe content with no special viewer group.', 10, 1),
  ('client', 'Client', 'Content packaged for a specific client or prospect.', 20, 1),
  ('public', 'Public', 'Content intentionally packaged for general public visitors.', 30, 1),
  ('hoa', 'HOA', 'HOA/private or semi-private content. Use when the instance should be shown only in HOA-specific contexts.', 40, 1),
  ('admin', 'Admin', 'Internal testing or admin preview traffic.', 50, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  definition = VALUES(definition),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO tracking_sources (`key`, label, definition, sort_order, is_active)
VALUES
  ('direct', 'Direct', 'Unknown arrival source. The viewer opened a URL with no source marker, such as a typed URL, bookmark, browser restore, or copied link with tracking removed.', 10, 1),
  ('site', 'Site', 'Viewer arrived through normal ColorFix site navigation when no more specific source is known.', 20, 1),
  ('browse', 'Browse', 'Viewer arrived through the Browse Playlists or picker experience.', 30, 1),
  ('watch_next', 'Watch Next', 'Viewer arrived by clicking a Watch Next CTA from another playlist.', 40, 1),
  ('share', 'Share', 'Viewer arrived through a shared playlist link.', 50, 1),
  ('email', 'Email', 'Viewer arrived through an email link generated or sent by ColorFix.', 60, 1),
  ('pinterest', 'Pinterest', 'Viewer arrived from a Pinterest pin or Pinterest campaign link.', 70, 1),
  ('youtube', 'YouTube', 'Viewer arrived from a YouTube video, description, comment, or campaign link.', 80, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  definition = VALUES(definition),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO tracking_event_types (`key`, label, definition, sort_order, is_active)
VALUES
  ('playlist_open', 'Playlist Open', 'A viewer opened a playlist/player instance.', 10, 1),
  ('playlist_complete', 'Playlist Complete', 'A viewer reached the end of a playlist. Reserved for completion tracking.', 20, 1),
  ('replay_click', 'Replay Click', 'A viewer clicked a replay CTA to watch the same playlist again.', 30, 1),
  ('share_click', 'Share Click', 'A viewer clicked a share CTA or copied/shared the playlist link. Reserved for share tracking.', 40, 1),
  ('watch_next_click', 'Watch Next Click', 'A viewer clicked a Watch Next CTA to continue to another playlist.', 50, 1),
  ('palette_click', 'Palette Click', 'A viewer clicked through to see colors, palette details, or colors used. Reserved for palette engagement tracking.', 60, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  definition = VALUES(definition),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

-- Rollback:
-- DROP TABLE IF EXISTS tracking_event_types;
-- DROP TABLE IF EXISTS tracking_sources;
-- DROP TABLE IF EXISTS tracking_audiences;
