import { bucketForLightness, overlayPresetConfig } from "@config/overlayPresets";

export function cleanHex(value) {
  if (!value) return "";
  return value.replace(/[^0-9a-f]/gi, "").slice(0, 6).toUpperCase();
}

export function normalizeShadowStruct(raw) {
  const src = raw && typeof raw === "object" ? raw : {};
  const offset = typeof src.l_offset === "number" && Number.isFinite(src.l_offset)
    ? Math.max(-50, Math.min(50, src.l_offset))
    : 0;
  let tint = src.tint_hex;
  if (typeof tint === "string") {
    let val = tint.trim();
    if (!val.startsWith("#")) val = `#${val}`;
    if (/^#?[0-9A-F]{3}$/i.test(val)) {
      const h = val.replace("#", "").toUpperCase();
      val = `#${h[0]}${h[0]}${h[1]}${h[1]}${h[2]}${h[2]}`;
    }
    tint = /^#[0-9A-F]{6}$/i.test(val) ? val.toUpperCase() : null;
  } else {
    tint = null;
  }
  const tintOpacity = typeof src.tint_opacity === "number" && Number.isFinite(src.tint_opacity)
    ? Math.max(0, Math.min(1, src.tint_opacity))
    : 0;
  return { l_offset: offset, tint_hex: tint, tint_opacity: tintOpacity };
}

const DEFAULT_SHADOW_TINT = "2C3E50"; // Moody Blue, matches tester preset

export function normalizeEntryForSave(entry) {
  if (!entry?.color?.id) return null;
  const hasOffset = entry.shadow_l_offset != null && entry.shadow_l_offset !== 0;
  const tintOpacity = entry.shadow_tint_opacity ?? (hasOffset ? 1 : null);
  const rawHex = entry.shadow_tint_hex ? entry.shadow_tint_hex.replace("#", "") : "";
  const needsDefaultTint =
    (!rawHex || !rawHex.length) &&
    (tintOpacity && tintOpacity > 0 || hasOffset);
  const tintHex = rawHex || (needsDefaultTint ? DEFAULT_SHADOW_TINT : null);
  return {
    mask_role: entry.mask_role,
    color_id: entry.color.id,
    blend_mode: entry.blend_mode || null,
    blend_opacity: entry.blend_opacity ?? null,
    shadow_l_offset: entry.shadow_l_offset ?? null,
    shadow_tint_hex: tintHex,
    shadow_tint_opacity: tintOpacity,
    target_lightness: entry.target_lightness ?? entry.color?.lightness ?? null,
    target_h: entry.target_h ?? null,
    target_c: entry.target_c ?? null,
  };
}

export function buildPreviewAssignments(entryMap) {
  const map = {};
  Object.values(entryMap || {}).forEach((entry) => {
    let hex = cleanHex(entry?.color?.hex6 || entry?.color?.hex || "");
    if (hex.length === 3) {
      hex = `${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`;
    }
    if (hex.length === 6 && entry?.mask_role) {
      map[entry.mask_role] = {
        hex6: hex,
        blend_mode: entry.blend_mode || null,
        blend_opacity: entry.blend_opacity ?? null,
        shadow: normalizeShadowStruct({
          l_offset: entry.shadow_l_offset,
          tint_hex:
            entry.shadow_tint_hex ||
            (entry.shadow_tint_opacity > 0 || (entry.shadow_l_offset != null && entry.shadow_l_offset !== 0)
              ? `#${DEFAULT_SHADOW_TINT}`
              : null),
          tint_opacity: entry.shadow_tint_opacity ?? (entry.shadow_l_offset != null && entry.shadow_l_offset !== 0 ? 1 : null),
        }),
      };
    }
  });
  return map;
}

export function getMaskBlendSettingForColor(settingsList, colorId) {
  if (!Array.isArray(settingsList) || !colorId) return null;
  return settingsList.find((row) => Number(row.color_id) === Number(colorId)) || null;
}

export function mergeMaskBlendSetting(entry, setting) {
  if (!setting || !entry) return entry;
  return {
    ...entry,
    blend_mode: setting.blend_mode ?? entry.blend_mode ?? "",
    blend_opacity: setting.blend_opacity ?? entry.blend_opacity ?? null,
    shadow_l_offset: setting.shadow_l_offset ?? entry.shadow_l_offset ?? 0,
    shadow_tint_hex: setting.shadow_tint_hex || entry.shadow_tint_hex || "",
    shadow_tint_opacity: setting.shadow_tint_opacity ?? entry.shadow_tint_opacity ?? 0,
    target_lightness: setting.target_lightness ?? entry.target_lightness ?? null,
    target_h: setting.target_h ?? entry.target_h ?? null,
    target_c: setting.target_c ?? entry.target_c ?? null,
  };
}

export function resolveBlendOpacity(row, fallback = 0.5) {
  let opacity = null;
  if (row?.draft_percent != null) {
    opacity = Number(row.draft_percent) / 100;
  } else if (row?.blend_opacity != null) {
    opacity = Number(row.blend_opacity);
  } else if (row?.percent != null) {
    opacity = Number(row.percent) / 100;
  }
  if (!Number.isFinite(opacity)) opacity = fallback;
  if (opacity > 1) opacity = opacity / 100;
  return Math.max(0, Math.min(1, opacity));
}

export function resolveTargetBucket(row, fallbackLightness = null, targetBuckets = overlayPresetConfig.targetBuckets) {
  if (row?.target_bucket) return row.target_bucket;
  const lightness = row?.target_lightness ?? row?.base_lightness ?? fallbackLightness;
  const resolved = Number.isFinite(Number(lightness)) ? Number(lightness) : 60;
  return bucketForLightness(resolved, targetBuckets);
}
