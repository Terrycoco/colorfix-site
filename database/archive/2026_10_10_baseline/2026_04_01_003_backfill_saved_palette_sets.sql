START TRANSACTION;

INSERT INTO saved_palette_sets (
  saved_palette_id,
  slug,
  title,
  is_default,
  order_index,
  created_at,
  updated_at
)
SELECT
  sp.id,
  'primary',
  CASE
    WHEN COALESCE(NULLIF(TRIM(sp.nickname), ''), '') <> '' THEN CONCAT(sp.nickname, ' Primary')
    ELSE 'Primary Set'
  END,
  1,
  0,
  NOW(),
  NOW()
FROM saved_palettes sp
LEFT JOIN saved_palette_sets sps
  ON sps.saved_palette_id = sp.id
 AND sps.slug = 'primary'
WHERE sps.id IS NULL;

INSERT INTO saved_palette_set_photos (
  saved_palette_set_id,
  photo_library_id,
  legacy_saved_palette_photo_id,
  rel_path,
  photo_type,
  trigger_mode,
  trigger_color_id,
  show_in_gallery,
  use_palette_default_roles,
  caption,
  alt_text,
  order_index,
  created_at,
  updated_at
)
SELECT
  sps.id,
  pl.photo_library_id,
  spp.id,
  spp.rel_path,
  COALESCE(NULLIF(TRIM(spp.photo_type), ''), 'full'),
  COALESCE(NULLIF(TRIM(spp.trigger_mode), ''), 'any'),
  spp.trigger_color_id,
  COALESCE(pl.show_in_gallery, 0),
  1,
  spp.caption,
  spp.alt_text,
  COALESCE(spp.order_index, 0),
  COALESCE(spp.created_at, NOW()),
  NOW()
FROM saved_palette_photos spp
JOIN saved_palette_sets sps
  ON sps.saved_palette_id = spp.saved_palette_id
 AND sps.slug = 'primary'
LEFT JOIN photo_library pl
  ON pl.source_id = spp.id
 AND pl.source_type IN ('saved_palette_photo', 'saved_before')
LEFT JOIN saved_palette_set_photos sspp
  ON sspp.legacy_saved_palette_photo_id = spp.id
WHERE sspp.id IS NULL;

COMMIT;
