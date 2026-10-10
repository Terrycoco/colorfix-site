DROP VIEW IF EXISTS vw_master_mask_blend;

CREATE OR REPLACE VIEW vw_master_mask_blend AS
SELECT
  mbs.id AS id,
  mbs.id AS mask_blend_id,
  mbs.photo_id,
  mbs.asset_id,
  mbs.mask_role,
  mbs.color_id,
  stats.l_avg01 AS base_lightness,
  mbs.blend_mode,
  mbs.blend_opacity,
  mbs.shadow_l_offset,
  mbs.shadow_tint_hex,
  mbs.shadow_tint_opacity,
  mbs.is_preset,
  mbs.approved,
  mbs.notes,
  mbs.created_at,
  mbs.updated_at,
  c.name AS color_name,
  c.brand AS color_brand,
  c.code AS color_code,
  c.hex6 AS color_hex,
  c.hcl_l AS color_l,
  c.hcl_h AS color_h,
  c.hcl_c AS color_c,
  stats.l_avg01 AS mask_base_lightness,
  pv.original_texture AS mask_original_texture
FROM mask_blend_settings mbs
LEFT JOIN colors c ON c.id = mbs.color_id
LEFT JOIN photos_mask_stats stats
  ON stats.photo_id = mbs.photo_id
 AND stats.role = mbs.mask_role
LEFT JOIN (
  SELECT pv1.photo_id, pv1.role, MAX(pv1.id) AS max_id
  FROM photos_variants pv1
  WHERE pv1.kind LIKE 'mask%'
  GROUP BY pv1.photo_id, pv1.role
) pv_idx
  ON pv_idx.photo_id = mbs.photo_id
 AND pv_idx.role = mbs.mask_role
LEFT JOIN photos_variants pv
  ON pv.id = pv_idx.max_id;
