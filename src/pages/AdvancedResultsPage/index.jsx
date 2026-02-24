// AdvancedResultsPage.jsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import { toWheelRange } from '@helpers/hueHelper';
import { runAdvancedSearch } from '@data/advancedSearch';
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import SwatchGallery from '@components/SwatchGallery';
import '@components/Gallery/gallery.css';

const DEFAULT_LIMIT = 600;

const parseFloatParam = (value) => {
  if (value === null || value === undefined || value === '') return null;
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
};

function buildPayloadFromQuery(search) {
  if (!search) return null;
  const params = new URLSearchParams(search);
  if (![...params.keys()].length) return null;
  const payload = { limit: DEFAULT_LIMIT, offset: 0 };
  let hasAny = false;

  const setNum = (paramName, payloadName = paramName) => {
    const num = parseFloatParam(params.get(paramName));
    if (num !== null) {
      payload[payloadName] = num;
      hasAny = true;
    }
  };

  const setList = (keys, payloadName) => {
    const collected = [];
    keys.forEach((key) => {
      params.getAll(key).forEach((item) => {
        item.split(',').forEach((piece) => {
          const trimmed = piece.trim();
          if (trimmed) collected.push(trimmed.toLowerCase());
        });
      });
    });
    if (collected.length) {
      payload[payloadName] = Array.from(new Set(collected));
      hasAny = true;
    }
  };

  const supercat = params.get('supercat') || params.get('supercat_slug');
  if (supercat) {
    payload.supercat_slug = supercat;
    hasAny = true;
  }

  const hex = params.get('hex6') || params.get('hex');
  if (hex) {
    payload.hex6 = hex;
    hasAny = true;
  }

  setNum('hue_min');
  setNum('hue_max');
  setNum('c_min');
  setNum('c_max');
  setNum('l_min');
  setNum('l_max');
  setList(['brand', 'brands'], 'brand');

  return hasAny ? payload : null;
}

export default function AdvancedResultsPage() {
  const { advancedSearch, searchFilters, showPalette } = useAppState();
  const location = useLocation();
  const queryPayload = useMemo(() => buildPayloadFromQuery(location.search), [location.search]);

  const {
    _submitSeq,        // ← set by Search button
    hueMin, hueMax, cMin, cMax, lMin, lMax, hex6, supercatSlug
  } = advancedSearch || {};
  const [state, setState] = useState({ loading: true, error: '', rows: [], total: 0 });
  const [sortMode, setSortMode] = useState('hue');
  const [showSortPeek, setShowSortPeek] = useState(true);
  const lastScrollRef = useRef(0);

  // brand codes helper
  const getBrandCodes = (sf) =>
    (Array.isArray(sf?.brands) ? sf.brands : [])
      .map(s => String(s).trim().toLowerCase())
      .filter(Boolean); // ['sw','de',...]

  const activeHex = ((queryPayload?.hex6 ?? hex6) || '').trim();

  // Build payload snapshot ONLY when user clicks Search (or query params supplied)
  const payloadOnSubmit = useMemo(() => {
    if (queryPayload) return queryPayload;
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
      ...(supercatSlug ? { supercat_slug: supercatSlug } : {}),
      limit: DEFAULT_LIMIT,
      offset: 0,
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queryPayload, _submitSeq, hueMin, hueMax, cMin, cMax, lMin, lMax, hex6, searchFilters, supercatSlug]); // ensure payload reruns if inputs change before next submit

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

  useEffect(() => {
    const onScroll = () => {
      const y = window.scrollY;
      const last = lastScrollRef.current;
      const delta = y - last;
      const goingDown = delta > 2;
      const goingUp = delta < -2;
      if (goingUp) {
        setShowSortPeek(true);
      } else if (goingDown) {
        setShowSortPeek(false);
      }

      lastScrollRef.current = y;
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      window.removeEventListener('scroll', onScroll);
    };
  }, []);

  useEffect(() => {
    const onWheel = (e) => {
      if (typeof e.deltaY !== 'number') return;
      if (e.deltaY < 0) setShowSortPeek(true);
      if (e.deltaY > 0) setShowSortPeek(false);
    };
    const onKeyDown = (e) => {
      if (e.key === 'ArrowUp' || e.key === 'PageUp' || e.key === 'Home') {
        setShowSortPeek(true);
      }
      if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === ' ' || e.key === 'End') {
        setShowSortPeek(false);
      }
    };
    window.addEventListener('wheel', onWheel, { passive: true });
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('wheel', onWheel);
      window.removeEventListener('keydown', onKeyDown);
    };
  }, []);

  // Instant brand filtering (always applied client-side)
  const clientFilteredRows = useMemo(() => {
    const active = getBrandCodes(searchFilters);
    if (!active.length) return state.rows;
    return state.rows.filter(r => {
      const b = (r.brand ?? r?.color?.brand ?? '').toString().trim().toLowerCase();
      return active.includes(b);
    });
  }, [state.rows, searchFilters]);

  const items = clientFilteredRows;
  const sortedItems = useMemo(() => {
    if (!items.length) return items;
    const getValue = (row) => {
      const swatch = row?.color ?? row;
      const raw = sortMode === 'chroma'
        ? swatch?.hcl_c
        : sortMode === 'lightness'
          ? swatch?.hcl_l
          : swatch?.hcl_h;
      const num = Number(raw);
      return Number.isFinite(num) ? num : null;
    };
    return [...items].sort((a, b) => {
      const aVal = getValue(a);
      const bVal = getValue(b);
      if (aVal == null && bVal == null) return 0;
      if (aVal == null) return 1;
      if (bVal == null) return -1;
      return aVal - bVal;
    });
  }, [items, sortMode]);

  if (state.loading) return <div className="gallery-status">Searching…</div>;
  if (state.error)   return <div className="gallery-status">Error: {state.error}</div>;
  if (!items.length) return <div className="gallery-status">No results.</div>;

  return (
    <div className="gallery">
      <div className={`gallery-controls adv-results-controls${showSortPeek ? " is-peek" : ""}${showPalette ? " has-palette" : ""}`}>
        <button
          type="button"
          onClick={() => setSortMode('hue')}
          aria-pressed={sortMode === 'hue'}
          className={`btn ${sortMode === 'hue' ? 'btn-active' : ''}`}
          title="Sort by Hue"
        >
          Hue
        </button>
        <button
          type="button"
          onClick={() => setSortMode('chroma')}
          aria-pressed={sortMode === 'chroma'}
          className={`btn ${sortMode === 'chroma' ? 'btn-active' : ''}`}
          title="Sort by Chroma"
        >
          Chroma
        </button>
        <button
          type="button"
          onClick={() => setSortMode('lightness')}
          aria-pressed={sortMode === 'lightness'}
          className={`btn ${sortMode === 'lightness' ? 'btn-active' : ''}`}
          title="Sort by Lightness"
        >
          Lightness
        </button>
      </div>
      <SwatchGallery
        items={sortedItems}
        SwatchComponent={PaletteSwatch}
        swatchPropName="color"
        className="sg-palette"
        gap={14}
        aspectRatio="5 / 4"
      />
    </div>
  );
}
