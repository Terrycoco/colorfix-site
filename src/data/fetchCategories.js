// @data/fetchCategories.js  (replace your current helper)
const serverRoot = 'https://colorfix.terrymarr.com/api';

function toNum(x) {
  return x == null || x === '' ? null : Number(x);
}

function normalize(rows) {
  // coerce numbers and lowercase type; keep names exactly as-is
  const norm = rows.map(r => ({
    ...r,
    type: String(r.type || '').toLowerCase(),
    hue_min: toNum(r.hue_min),
    hue_max: toNum(r.hue_max),
    chroma_min: toNum(r.chroma_min),
    chroma_max: toNum(r.chroma_max),
    light_min: toNum(r.light_min),
    light_max: toNum(r.light_max),
    sort_order: toNum(r.sort_order),
  }));

  // put hue first and sort hue by hue_min (signed degrees are OK: -40..7 etc.)
  const hues = norm.filter(r => r.type === 'hue')
                   .sort((a, b) => (a.hue_min ?? 0) - (b.hue_min ?? 0));
  const others = norm.filter(r => r.type !== 'hue');

  // Return one flat array (hue first) so existing consumers just filter by type
  return [...hues, ...others];
}

export default async function fetchCategories() {
  const tryOnce = async (url) => {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    const rows = Array.isArray(data) ? data : (data?.data || []);
    if (!Array.isArray(rows)) throw new Error('Bad payload');
    return normalize(rows);
  };

  // 1) prefer v2 (identical shape after normalize)
  const v2 = `${serverRoot}/v2/get-categories.php?t=${Date.now()}`;
  try {
    const cats = await tryOnce(v2);
    // sanity: ensure we actually got usable hue rows
    const hasHue = cats.some(c => c.type === 'hue' && c.hue_min != null && c.hue_max != null);
    if (hasHue) return cats;
    // fall through to v1 if somehow empty/missing hue geometry
  } catch (_) {
    // fall back to v1
  }

  // 2) fallback to v1 (your original endpoint)
  const v1 = `${serverRoot}/get-categories.php?t=${Date.now()}`;
  return tryOnce(v1);
}
