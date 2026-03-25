CREATE TEMPORARY TABLE tmp_converted_saved_palette_ids (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY
)
SELECT sp.id
FROM saved_palettes sp
WHERE sp.private_notes LIKE 'converted_from_applied_palette_id:%';

DELETE pl
FROM photo_library pl
JOIN saved_palette_photos spp
  ON spp.id = pl.source_id
JOIN tmp_converted_saved_palette_ids tmp
  ON tmp.id = spp.saved_palette_id
WHERE pl.source_type IN ('saved_palette_photo', 'saved_before');

DELETE spv
FROM saved_palette_views spv
JOIN tmp_converted_saved_palette_ids tmp
  ON tmp.id = spv.saved_palette_id;

DELETE spm
FROM saved_palette_members spm
JOIN tmp_converted_saved_palette_ids tmp
  ON tmp.id = spm.saved_palette_id;

DELETE spp
FROM saved_palette_photos spp
JOIN tmp_converted_saved_palette_ids tmp
  ON tmp.id = spp.saved_palette_id;

DELETE sp
FROM saved_palettes sp
JOIN tmp_converted_saved_palette_ids tmp
  ON tmp.id = sp.id;

DROP TEMPORARY TABLE tmp_converted_saved_palette_ids;
