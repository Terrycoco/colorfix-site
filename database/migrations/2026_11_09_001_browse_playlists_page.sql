-- One-time data correction only. Review existing ana_pages row #4 and any
-- /browse-playlists registration before applying through the established SQL
-- migration process. Never delete/reinsert this page or rewrite its events.
-- Preserve the existing OG image and all analytics_events.resource_id = 4 rows.
-- Refuse to change an unexpected row/path or a conflicting registration.
UPDATE ana_pages AS page
LEFT JOIN ana_pages AS conflict
  ON conflict.path = '/browse-playlists' AND conflict.id <> 4
SET page.title = 'Browse Playlists',
    page.path = '/browse-playlists',
    page.track_enabled = 1,
    page.include_in_sitemap = 1,
    page.og_title = 'Browse Color Transformations & Paint Ideas | ColorFix by Terry',
    page.og_description = 'Explore before-and-after color transformations, exterior and interior paint ideas, and designer insights from ColorFix by Terry.'
WHERE page.id = 4
  AND page.path IN ('/picker/?set=11', '/picker?set=11', '/browse-playlists')
  AND conflict.id IS NULL;
