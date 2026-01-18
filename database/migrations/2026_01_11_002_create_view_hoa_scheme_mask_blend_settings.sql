CREATE OR REPLACE VIEW vw_scheme_mask_blend_settings AS
SELECT
  s.id AS scheme_id,
  s.hoa_id,
  s.scheme_code,
  mm.asset_id,
  mm.mask_role,
  mm.scheme_role,
  sc.id AS scheme_color_id,
  sc.color_id,
  sc.allowed_roles,
  sc.notes AS scheme_color_notes,
  mbs.id AS mask_blend_id,
  mbs.photo_id,
  mbs.blend_mode,
  mbs.blend_opacity,
  mbs.shadow_l_offset,
  mbs.shadow_tint_hex,
  mbs.shadow_tint_opacity,
  mbs.approved,
  mbs.updated_at
FROM hoa_schemes s
JOIN hoa_scheme_colors sc ON sc.scheme_id = s.id
JOIN hoa_scheme_mask_maps mm ON mm.scheme_id = s.id
LEFT JOIN mask_blend_settings mbs
  ON mbs.asset_id = mm.asset_id
 AND mbs.mask_role = mm.mask_role
 AND mbs.color_id = sc.color_id
WHERE sc.allowed_roles = 'any'
   OR FIND_IN_SET(mm.scheme_role, sc.allowed_roles);
