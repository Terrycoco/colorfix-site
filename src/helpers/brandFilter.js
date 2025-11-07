// src/utils/brandFilter.js
export function filterSwatchesByBrand(swatches, activeBrandCodes = []) {
  if (!Array.isArray(swatches) || swatches.length === 0) return swatches || [];
  if (!activeBrandCodes || activeBrandCodes.length === 0) return swatches;
  const allowed = new Set(activeBrandCodes.map(s => (s || '').trim()));
  return swatches.filter(sw => allowed.has((sw.brand || '').trim()));
}

// For grouped data: [{ header, items:[...] }]
export function filterGroupsByBrand(groups, activeBrandCodes = [], itemsKey = 'items') {
  if (!Array.isArray(groups) || groups.length === 0) return groups || [];
  if (!activeBrandCodes || activeBrandCodes.length === 0) return groups;

  const allowed = new Set(activeBrandCodes.map(s => (s || '').trim()));
  return groups
    .map(g => {
      const items = Array.isArray(g[itemsKey]) ? g[itemsKey] : [];
      const filtered = items.filter(sw => allowed.has((sw.brand || '').trim()));
      return { ...g, [itemsKey]: filtered };
    })
    .filter(g => g[itemsKey]?.length);
}
