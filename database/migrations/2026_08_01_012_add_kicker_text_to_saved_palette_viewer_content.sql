ALTER TABLE saved_palette_viewer_content
  ADD COLUMN kicker_text VARCHAR(255) NULL AFTER kicker_id;

UPDATE saved_palette_viewer_content vc
LEFT JOIN kickers k
  ON k.kicker_id = vc.kicker_id
SET vc.kicker_text = k.display_text,
    vc.updated_at = NOW()
WHERE vc.kicker_text IS NULL
  AND vc.kicker_id IS NOT NULL
  AND k.display_text IS NOT NULL;
