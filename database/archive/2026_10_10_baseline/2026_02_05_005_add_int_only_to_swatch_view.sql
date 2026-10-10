CREATE OR REPLACE VIEW `swatch_view` AS
SELECT
    `c`.`id` AS `id`,
    `c`.`name` AS `name`,
    `c`.`code` AS `code`,
    `c`.`brand` AS `brand`,
    `c`.`chip_num` AS `chip_num`,
    `c`.`r` AS `r`,
    `c`.`g` AS `g`,
    `c`.`b` AS `b`,
    `c`.`hex6` AS `hex6`,
    CONCAT('#', `c`.`hex6`) AS `hex`,
    `c`.`hcl_l` AS `hcl_l`,
    `c`.`hcl_c` AS `hcl_c`,
    `c`.`hcl_h` AS `hcl_h`,
    `co`.`name` AS `brand_name`,
    `c`.`hue_cats` AS `hue_cats`,
    `c`.`hue_cat_order` AS `hue_cat_order`,
    `c`.`neutral_cats` AS `neutral_cats`,
    `c`.`is_stain` AS `is_stain`,
    `c`.`cluster_id` AS `cluster_id`,
    `c`.`light_cat_id` AS `light_cat_id`,
    `cd`.`name` AS `light_cat_name`,
    `cd`.`sort_order` AS `light_cat_order`,
    IF(`c`.`exterior` = 0 OR `c`.`exterior` IS NULL, 1, 0) AS `int_only`
FROM (`colors` `c`
    LEFT JOIN `company` `co` ON (`co`.`code` = `c`.`brand`)
    LEFT JOIN `category_definitions` `cd`
        ON (`cd`.`id` = `c`.`light_cat_id` AND `cd`.`type` = 'Lightness'))
WHERE (`c`.`is_inactive` = 0);
