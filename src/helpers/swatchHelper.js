import { API_FOLDER } from '@helpers/config';

export async function normalizeSwatch(raw) {
  const requiredKeys = ['id', 'name', 'hcl_h', 'hcl_c', 'hcl_l', 'brand'];
  const isObject = raw !== null && typeof raw === 'object' && !Array.isArray(raw);
  const hasAllCoreFields = isObject &&
    requiredKeys.every(k => raw[k] !== undefined && raw[k] !== null) &&
    (raw.hex != null || raw.hex6 != null);

  const normStain = (v) => (v == null ? 0 : Number(v) ? 1 : 0);

  if (hasAllCoreFields) {
    return {
      ...raw, // Preserve DTO fields and UI metadata already returned by GALLERY.
      id: raw.id,
      name: raw.name,
      code: raw.code ?? '',
      brand: raw.brand ?? '',
      r: raw.r ?? 0,
      g: raw.g ?? 0,
      b: raw.b ?? 0,
      hex: raw.hex ?? raw.hex6 ?? '',
      hcl_l: raw.hcl_l ?? 0,
      hcl_c: raw.hcl_c ?? 0,
      hcl_h: raw.hcl_h ?? 0,
      brand_name: raw.brand_name ?? '',
      hue_cats: raw.hue_cats ?? '',
      hue_cat_order: raw.hue_cat_order ?? 99,
      neutral_cats: raw.neutral_cats ?? '',
      is_stain: normStain(raw.is_stain),
      chip_num: raw.chip_num ?? '',
      cluster_id: raw.cluster_id ?? 0,
    };
  }

  // Persisted personal palettes store IDs, not full objects.
  // Keep the existing object-input path, but also accept a bare ID.
  const swatchId = isObject ? (raw.id ?? raw.color_id ?? null) : raw;
  if (swatchId === null || swatchId === undefined || String(swatchId).trim() === '') {
    console.warn('normalizeSwatch: no ID provided', raw);
    return null;
  }

  try {
    const res = await fetch(`${API_FOLDER}/get-swatch.php?id=${encodeURIComponent(String(swatchId))}`);
    const rawBody = await res.text();
    if (!res.ok) throw new Error(`Fetch failed (${res.status}): ${rawBody.slice(0, 120)}`);
    let full;
    try {
      full = JSON.parse(rawBody);
    } catch {
      throw new Error(`Bad swatch JSON (${res.status}): ${rawBody.slice(0, 120)}`);
    }

    return {
      ...full,
      ...(isObject ? raw : {}),
      is_stain: normStain(isObject ? (raw.is_stain ?? full.is_stain) : full.is_stain),
    };
  } catch (err) {
    console.error('normalizeSwatch error:', err);
    return null;
  }
}
