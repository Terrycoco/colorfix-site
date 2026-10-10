ALTER TABLE playlist_items
    ADD COLUMN client TINYINT(1) NOT NULL DEFAULT 1 AFTER prospect;
