// src/hooks/useBrandFilter.js
export function applyBrandFilter(items, getBrand, activeBrands) {
  // activeBrands: Array or Set of selected brand names/slugs
  if (!items || !Array.isArray(items)) return [];
  const selected = Array.isArray(activeBrands)
    ? new Set(activeBrands.filter(Boolean))
    : (activeBrands instanceof Set ? activeBrands : new Set());

  if (selected.size === 0) return items; // no brand filter applied
  return items.filter(item => {
    const b = (getBrand?.(item) ?? '').trim();
    return selected.has(b);
  });
}

// Optional helper for pages: derive unique brands from a dataset
export function uniqueBrands(items, getBrand) {
  const set = new Set();
  for (const it of items || []) {
    const b = (getBrand?.(it) ?? '').trim();
    if (b) set.add(b);
  }
  return Array.from(set).sort((a,b)=>a.localeCompare(b));
}
