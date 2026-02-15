ALTER TABLE playlist_items
    ADD COLUMN palette_hash CHAR(64) NULL AFTER ap_id;
