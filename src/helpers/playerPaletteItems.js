export function isPaletteEligibleItem(item) {
  if (!item) return false;
  const type = (item?.type || "normal").toLowerCase();
  const attachedType = (item?.saved_palette_photo_type || "").toLowerCase();
  if (type === "intro" || type === "before" || type === "text" || type === "hue-wheel" || type === "non-palette") {
    return false;
  }
  if (attachedType === "before") return false;
  if (item?.exclude_from_thumbs) return false;
  return Boolean(item?.ap_id || item?.palette_hash || item?.saved_palette_set_id);
}

export function getPaletteTargetKey(item) {
  if (!isPaletteEligibleItem(item)) return "";
  const apId = item?.ap_id ?? null;
  const paletteHash = item?.palette_hash ?? null;
  const savedPaletteSetId = Number(item?.saved_palette_set_id || 0) || null;
  if (paletteHash) return `saved:${paletteHash}:${savedPaletteSetId || "default"}`;
  if (savedPaletteSetId) return `saved-set:${savedPaletteSetId}`;
  if (apId) return `applied:${apId}`;
  return "";
}

export function getPaletteItems(data) {
  const items = data?.items || [];
  return items.filter(isPaletteEligibleItem);
}

export function getPaletteTargets(data) {
  const seen = new Set();
  const targets = [];
  for (const item of getPaletteItems(data)) {
    const key = getPaletteTargetKey(item);
    if (!key || seen.has(key)) continue;
    seen.add(key);
    targets.push(item);
  }
  return targets;
}
