START TRANSACTION;
DELETE FROM photos_mask_stats;
DELETE FROM photos_tags;
DELETE FROM photos_variants;
DELETE FROM photos;
COMMIT;
