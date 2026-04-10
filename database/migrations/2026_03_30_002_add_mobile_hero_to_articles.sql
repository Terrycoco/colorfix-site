START TRANSACTION;

ALTER TABLE articles
    ADD COLUMN hero_mobile_asset_id INT UNSIGNED NULL AFTER hero_asset_id;

COMMIT;
