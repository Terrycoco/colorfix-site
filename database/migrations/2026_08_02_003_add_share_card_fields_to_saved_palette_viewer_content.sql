ALTER TABLE saved_palette_viewer_content
    ADD COLUMN share_card_template_id BIGINT UNSIGNED NULL AFTER playlist_url,
    ADD COLUMN share_card_fields_json JSON NULL AFTER share_card_template_id,
    ADD COLUMN share_card_image_path VARCHAR(500) NULL AFTER share_card_fields_json,
    ADD COLUMN share_card_generated_at DATETIME NULL AFTER share_card_image_path,
    ADD KEY idx_spvc_share_card_template_id (share_card_template_id),
    ADD CONSTRAINT fk_spvc_share_card_template
        FOREIGN KEY (share_card_template_id)
        REFERENCES share_card_templates(share_card_template_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

UPDATE saved_palette_viewer_content spvc
JOIN share_card_templates sct
  ON sct.template_key = CASE
    WHEN spvc.template_key = 'concept' THEN 'concept'
    ELSE 'palette'
  END
SET spvc.share_card_template_id = sct.share_card_template_id
WHERE spvc.share_card_template_id IS NULL;
