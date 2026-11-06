import React, { StrictMode, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { AppStateProvider, useAppState } from '../../src/context/AppStateContext';
import BrandFilterModal from '../../src/components/BrandFilter';
import MyPalettePage from '../../src/pages/MyPalettePage';
import PaletteTranslationPage from '../../src/pages/PaletteTranslationPage';
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom';
import '../../src/styles/ZIndex.css';
import '../../src/styles/global.css';

// Exercise the real shared state and persistence without contacting any backend.
const paletteMode = new URLSearchParams(location.search).has('palette');
const colors = [
  { id: 1, name: 'Fixture SW', brand: 'sw', code: 'SW1', hex6: '123456', hcl_h: 10, hcl_c: 20, hcl_l: 50 },
  { id: 2, name: 'Fixture BM', brand: 'bm', code: 'BM2', hex6: '345678', hcl_h: 130, hcl_c: 25, hcl_l: 55 },
  { id: 3, name: 'Fixture Behr', brand: 'behr', code: 'BE3', hex6: '567890', hcl_h: 250, hcl_c: 30, hcl_l: 60 },
];
localStorage.setItem('pals.brand_filters.v1', JSON.stringify(paletteMode ? ['sw'] : ['behr', 'bm', 'sw']));
if (paletteMode) {
  localStorage.setItem('pals.palette.v1', JSON.stringify([1, 2]));
  localStorage.setItem('cf_palette_collapsed', 'false');
}
window.brandFilterTest = { requests: [], errors: [], state: null };
window.addEventListener('error', (event) => window.brandFilterTest.errors.push(event.message));
window.addEventListener('unhandledrejection', (event) => window.brandFilterTest.errors.push(String(event.reason)));
window.fetch = async (url) => {
  const parsed = new URL(String(url), location.href);
  const path = parsed.pathname;
  window.brandFilterTest.requests.push(parsed.href);
  let response;
  if (path.includes('get-categories.php')) response = [{ id: 1, type: 'hue', name: 'Red', hue_min: 0, hue_max: 20 }];
  else if (path.endsWith('/device-auth.php') || path.endsWith('/session.php')) response = { ok: false };
  else if (path.endsWith('/get-swatch.php')) response = colors.find((color) => String(color.id) === parsed.searchParams.get('id'));
  else if (path.endsWith('/fuzzy-search.php')) response = { results: colors };
  else if (path.endsWith('/get-friends.php')) response = { items: [colors[0]] };
  else if (path.endsWith('/v2/translate.php')) {
    if (window.brandFilterTest.failTranslation) {
      return new Response(JSON.stringify({ ok: false, error: 'Fixture unavailable' }), { status: 503 });
    }
    const source = colors.find((color) => String(color.id) === parsed.searchParams.get('source_id'));
    await new Promise((resolve) => setTimeout(resolve, source.id * 250));
    response = {
      ok: true, source,
      items: colors.filter((color) => color.brand !== source.brand).map((color) => ({
        color: { ...color, id: source.id * 100 + color.id, name: `Closest ${color.brand} for ${source.name}` },
        delta_e: 1.2,
      })),
    };
  }
  else throw new Error(`Unexpected fixture request: ${path}`);
  return new Response(JSON.stringify(response), { headers: { 'Content-Type': 'application/json' } });
};

export function BrandFilterFixture() {
  const location = useLocation();
  const [open, setOpen] = useState(false);
  const { searchFilters, brandFiltersAppliedSeq, palette } = useAppState();
  window.brandFilterTest.state = { brands: searchFilters.brands, applied: brandFiltersAppliedSeq };
  window.brandFilterTest.palette = palette.map((color) => color.id);
  window.brandFilterTest.route = location.pathname + location.search;
  return <>
    <h1>Brand filter regression fixture</h1>
    <button onClick={() => setOpen(true)}>Open brand filters</button>
    <output>{JSON.stringify(window.brandFilterTest.state)}</output>
    <BrandFilterModal open={open} onClose={() => setOpen(false)} />
    {paletteMode && <Routes>
      <Route path="/palette/translate" element={<PaletteTranslationPage />} />
      <Route path="*" element={<MyPalettePage />} />
    </Routes>}
  </>;
}
createRoot(document.getElementById('root')).render(<StrictMode><MemoryRouter><AppStateProvider><BrandFilterFixture /></AppStateProvider></MemoryRouter></StrictMode>);
