export function positiveIds(values) {
  return [...new Set(values.map(Number).filter((id) => Number.isSafeInteger(id) && id > 0))];
}

// v2 returns one source color and the closest color in each other brand.
// Include the original for its own brand, preserving a complete palette per brand.
export function groupTranslations(translations) {
  const byBrand = new Map();
  const failures = [];
  translations.forEach((translation, sourceIndex) => {
    const picks = [
      { color: translation.source, delta_e: 0, is_twin: true },
      ...(Array.isArray(translation.items) ? translation.items : []),
    ];
    const seenBrands = new Set();
    for (const pick of picks) {
      const color = pick?.color;
      const brand = String(color?.brand || '').trim().toLowerCase();
      if (!color?.id || !brand || brand === 'true' || seenBrands.has(brand)) continue;
      seenBrands.add(brand);
      if (!byBrand.has(brand)) byBrand.set(brand, []);
      byBrand.get(brand).push({
        ...pick, color, src_order: sourceIndex + 1,
        brand_name: color.brand_name || brand,
      });
    }
  });
  const items = [];
  const brands = [];
  for (const brand of [...byBrand.keys()].sort()) {
    const rows = byBrand.get(brand);
    // Preserve the legacy requirement of one distinct match per source color.
    if (rows.length !== translations.length || new Set(rows.map((row) => row.color.id)).size !== rows.length) {
      failures.push({ brand, reason: 'No complete set of distinct matches for this palette.' });
      continue;
    }
    brands.push(brand);
    rows.forEach((row) => items.push({ ...row, group_order: brands.length }));
  }
  return { items, brands, failures, count: items.length, src_count: translations.length, src_kind: 'colors' };
}

export async function requestTranslation(url, { signal, fetchImpl = fetch, timeoutMs = 30000 } = {}) {
  const controller = new AbortController();
  let timedOut = false;
  const abort = () => controller.abort();
  if (signal?.aborted) controller.abort();
  signal?.addEventListener('abort', abort, { once: true });
  const timer = setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);
  try {
    const response = await fetchImpl(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
    const text = await response.text();
    if (!response.ok) throw new Error(`Translation service returned HTTP ${response.status}.`);
    let data;
    try { data = JSON.parse(text); }
    catch { throw new Error('The translation service returned an unreadable response. Please try again.'); }
    if (!data?.ok) throw new Error(data?.error || 'The translation service could not find matches.');
    return data;
  } catch (error) {
    if (timedOut) throw new Error('Translation took too long. Please try again.');
    throw error;
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', abort);
  }
}

export async function translateColors(api, ids, { signal, onProgress, fetchImpl, timeoutMs } = {}) {
  const results = new Array(ids.length);
  const controller = new AbortController();
  const abort = () => controller.abort();
  if (signal?.aborted) controller.abort();
  signal?.addEventListener('abort', abort, { once: true });
  let next = 0;
  let completed = 0;
  async function worker() {
    while (next < ids.length && !controller.signal.aborted) {
      const index = next++;
      const data = await requestTranslation(`${api}/v2/translate.php?source_id=${ids[index]}`, {
        signal: controller.signal, fetchImpl, timeoutMs,
      });
      if (!data.source?.id) throw new Error(`No source color was returned for color #${ids[index]}.`);
      results[index] = data;
      onProgress?.(++completed);
    }
  }
  try {
    // Bound requests so large palettes don't flood the matching service.
    await Promise.all(Array.from({ length: Math.min(2, ids.length) }, worker));
    if (controller.signal.aborted) throw new DOMException('Translation cancelled', 'AbortError');
    return groupTranslations(results);
  } finally {
    controller.abort();
    signal?.removeEventListener('abort', abort);
  }
}
