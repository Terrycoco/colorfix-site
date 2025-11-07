// AdvancedResultsPage.jsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import { toWheelRange } from '@helpers/hueHelper';
import { runAdvancedSearch } from '@data/advancedSearch';
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import SwatchGallery from '@components/SwatchGallery';

export default function AdvancedResultsPage() {
  const { advancedSearch, searchFilters } = useAppState();
  const {
    _submitSeq,        // ← set by Search button
    hueMin, hueMax, cMin, cMax, lMin, lMax, hex6
  } = advancedSearch || {};
  const [state, setState] = useState({ loading: true, error: '', rows: [], total: 0 });

  // brand codes helper
  const getBrandCodes = (sf) =>
    (Array.isArray(sf?.brands) ? sf.brands : [])
      .map(s => String(s).trim().toLowerCase())
      .filter(Boolean); // ['sw','de',...]

  // Build payload snapshot ONLY when user clicks Search
  const payloadOnSubmit = useMemo(() => {
    // if no submit yet, return null to avoid firing
    if (!_submitSeq) return null;

    const hx = (hex6 || '').trim();
    if (hx) {
      // HEX mode: exact-only fetch, ignore other facets and brands (brands filtered client-side)
      return { hex6: hx, limit: 600, offset: 0 };
    }

    // No hex → H/C/L payload
    const a = hueMin !== '' ? Number(hueMin) : (hueMax !== '' ? (Number(hueMax) + 359) % 360 : null);
    const b = hueMax !== '' ? Number(hueMax) : (hueMin !== '' ? (Number(hueMin) + 1) % 360 : null);
    const wheel = (a != null && b != null) ? toWheelRange(a, b) : null;

    // Optionally include brands on submit (server-side filter) — or omit if you prefer client-filter only.
    const brand = getBrandCodes(searchFilters);

    return {
      ...(wheel ? { hue_min: wheel.wheelMin, hue_max: wheel.wheelMax } : {}),
      ...(cMin !== '' ? { c_min: Number(cMin) } : {}),
      ...(cMax !== '' ? { c_max: Number(cMax) } : {}),
      ...(lMin !== '' ? { l_min: Number(lMin) } : {}),
      ...(lMax !== '' ? { l_max: Number(lMax) } : {}),
      ...(brand.length ? { brand } : {}),
      limit: 600,
      offset: 0,
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [_submitSeq]); // ← ONLY changes when Search is clicked

  // Fetch once per Search click
  useEffect(() => {
    if (!payloadOnSubmit) return;
    const abort = new AbortController();

    (async () => {
      setState(s => ({ ...s, loading: true, error: '' }));
      try {
        const data = await runAdvancedSearch(payloadOnSubmit, { signal: abort.signal });
        if (!data || data.ok === false) throw new Error(data?.error || data?._err || 'Search error');
        setState({ loading: false, error: '', rows: data.items || [], total: data.total || 0 });
      } catch (e) {
        if (!abort.signal.aborted) {
          setState({ loading: false, error: e.message || 'Network error', rows: [], total: 0 });
        }
      }
    })();

    return () => abort.abort();
  }, [payloadOnSubmit]);

  // Instant brand filtering (only in HEX mode; no new fetches)
  const clientFilteredRows = useMemo(() => {
    const hx = (hex6 || '').trim();
    if (!hx) return state.rows; // non-HEX mode already server-filtered on submit
    const active = getBrandCodes(searchFilters);
    if (!active.length) return state.rows;
    return state.rows.filter(r => {
      const b = (r.brand ?? r?.color?.brand ?? '').toString().toLowerCase();
      return active.includes(b);
    });
  }, [state.rows, searchFilters, hex6]);

  const items = clientFilteredRows;

  if (state.loading) return <div className="gallery-status">Searching…</div>;
  if (state.error)   return <div className="gallery-status">Error: {state.error}</div>;
  if (!items.length) return <div className="gallery-status">No results.</div>;

  return (
    <div className="gallery">
      <SwatchGallery
        items={items}
        SwatchComponent={PaletteSwatch}
        swatchPropName="color"
        className="sg-palette"
        gap={14}
        aspectRatio="5 / 4"
      />
    </div>
  );
}
