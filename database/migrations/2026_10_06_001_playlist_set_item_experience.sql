ALTER TABLE playlist_set_items
    ADD COLUMN experience_key VARCHAR(50) NOT NULL DEFAULT 'public' AFTER item_type;
