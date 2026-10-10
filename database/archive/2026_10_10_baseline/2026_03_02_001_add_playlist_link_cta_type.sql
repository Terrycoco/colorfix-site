INSERT INTO cta_types (action_key, label, description, is_active)
SELECT 'playlist_link', 'Playlist Link', 'Link back to a playlist instance', 1
WHERE NOT EXISTS (
  SELECT 1 FROM cta_types WHERE action_key = 'playlist_link'
);
