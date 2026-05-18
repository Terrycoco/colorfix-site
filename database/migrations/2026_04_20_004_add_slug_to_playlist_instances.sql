ALTER TABLE playlist_instances
  ADD COLUMN slug VARCHAR(191) NULL AFTER playlist_id,
  ADD UNIQUE KEY uq_playlist_instances_slug (slug);

UPDATE playlist_instances pi
JOIN playlists p
  ON p.playlist_id = pi.playlist_id
LEFT JOIN (
  SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
  FROM playlist_instances
  WHERE is_active = 1
    AND share_enabled = 1
  GROUP BY playlist_id
) shareable
  ON shareable.playlist_id = pi.playlist_id
SET pi.slug = CASE
  WHEN pi.playlist_instance_id = shareable.playlist_instance_id THEN p.slug
  ELSE CONCAT(p.slug, '-', pi.playlist_instance_id)
END
WHERE pi.slug IS NULL
  AND p.slug IS NOT NULL
  AND TRIM(p.slug) <> '';
