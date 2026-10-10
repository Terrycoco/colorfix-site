UPDATE photo_library pl
JOIN saved_palette_set_photos spsp
  ON spsp.photo_library_id = pl.photo_library_id
SET
  pl.show_in_gallery = CASE
    WHEN spsp.show_in_gallery = 1 THEN 1
    ELSE pl.show_in_gallery
  END,
  pl.has_palette = 1,
  pl.updated_at = NOW()
WHERE spsp.photo_library_id IS NOT NULL
  AND (spsp.show_in_gallery = 1 OR pl.has_palette = 0);
