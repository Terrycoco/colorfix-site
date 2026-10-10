CREATE TABLE IF NOT EXISTS publishing_default_templates (
  publishing_default_template_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform VARCHAR(64) NOT NULL,
  asset_type VARCHAR(96) NOT NULL DEFAULT 'any',
  playlist_type VARCHAR(64) NOT NULL DEFAULT 'any',
  field_key VARCHAR(64) NOT NULL,
  label VARCHAR(160) NULL,
  template_text TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (publishing_default_template_id),
  UNIQUE KEY uniq_publishing_default_template (platform, asset_type, playlist_type, field_key),
  KEY idx_publishing_default_template_lookup (platform, asset_type, playlist_type, field_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO publishing_default_templates
  (platform, asset_type, playlist_type, field_key, label, template_text, is_active)
VALUES
  (
    'pinterest',
    'pinterest_pin',
    'any',
    'description',
    'Pinterest pin description',
    'This [house style / room type] was struggling with [problem]. By [what you changed], the eye is now drawn toward [focal point or benefit]. See the complete before-and-after makeover, color palette, and design reasoning.',
    1
  ),
  (
    'youtube',
    'youtube_playlist_video',
    'any',
    'description',
    'YouTube video description',
    'See the full ColorFix palette and colors here:\n{{playlist_url}}\n\n{{summary}}\n\nColorFix by Terry',
    1
  ),
  (
    'youtube',
    'youtube_playlist_video',
    'palettes',
    'description',
    'YouTube palette playlist description',
    'See the full ColorFix palette and exact colors here:\n{{playlist_url}}\n\n{{playlist_title}}\n\nColorFix by Terry',
    1
  )
ON DUPLICATE KEY UPDATE
  template_text = VALUES(template_text),
  label = VALUES(label),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;
