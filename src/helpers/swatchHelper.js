import { API_FOLDER } from '@helpers/config';

export async function normalizeSwatch(raw) {
  const requiredKeys = ['id', 'name', 'hex', 'hcl_h', 'hcl_c', 'hcl_l', 'chip_num', 'cluster_id', 'brand'];
  const hasAllCoreFields = requiredKeys.every(
    k => raw?.[k] !== undefined && raw?.[k] !== null
  );

  // helper to coerce to 0/1
  const normStain = (v) => (v == null ? 0 : Number(v) ? 1 : 0);

  if (hasAllCoreFields) {
    return {
      id: raw.id,
      name: raw.name,
      code: raw.code ?? '',
      brand: raw.brand ?? '',
      r: raw.r ?? 0,
      g: raw.g ?? 0,
      b: raw.b ?? 0,
      hex: raw.hex ?? '',
      hcl_l: raw.hcl_l ?? 0,
      hcl_c: raw.hcl_c ?? 0,
      hcl_h: raw.hcl_h ?? 0,
      brand_name: raw.brand_name ?? '',
      hue_cats: raw.hue_cats ?? '',
      hue_cat_order: raw.hue_cat_order ?? 99,
      neutral_cats: raw.neutral_cats ?? '',
      is_stain: normStain(raw.is_stain),     // ← added
      chip_num: raw.chip_num ?? '',
      cluster_id: raw.cluster_id ?? 0
    };
  }

  const swatchId = raw.id ?? raw.color_id ?? null;
  if (!swatchId) {
    console.warn('normalizeSwatch: no ID provided', raw);
    return null;
  }

  try {
    const res = await fetch(`${API_FOLDER}/get-swatch.php?id=${swatchId}`);
    const rawBody = await res.text();
    if (!res.ok) throw new Error(`Fetch failed (${res.status}): ${rawBody.slice(0, 120)}`);
    let full;
    try {
      full = JSON.parse(rawBody);
    } catch {
      throw new Error(`Bad swatch JSON (${res.status}): ${rawBody.slice(0, 120)}`);
    }

    // merge and still coerce is_stain
    return {
      ...full,
      ...raw, // raw can override (e.g., role/UI props)
      is_stain: normStain(raw.is_stain ?? full.is_stain), // ← ensure 0/1
    };
  } catch (err) {
    console.error('normalizeSwatch error:', err);
    return null;
  }
}
