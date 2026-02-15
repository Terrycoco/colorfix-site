// GalleryPage.jsx
import { useParams, useNavigate, useLocation } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import { API_FOLDER } from '@helpers/config';
import { useEffect, useState, useRef, useMemo } from 'react';
import Gallery from '@components/Gallery/Gallery';
import { mergeWithInserts } from '@helpers/mergeHelper';
import TopSpacer from '@layout/TopSpacer';
import useHashJumpAfterLayout from "@hooks/useHashJumpAfterLayout.js";

const GalleryPage = () => {
  const activeReqRef = useRef(0);
  const abortRef = useRef(null);

  const { queryId } = useParams();
  const navigate = useNavigate();
  const location = useLocation();

  const searchParams = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const paramObj = useMemo(() => Object.fromEntries(searchParams.entries()), [searchParams]);

  const [searchItems, setSearchItems] = useState([]);
  const [insertItems, setInsertItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [showBackToTop, setShowBackToTop] = useState(false);

  // Decide if this page is a swatch gallery.
  // 1) meta.item_type === 'swatch' (preferred when present)
  // 2) OR any result item looks like a swatch (has item_type 'swatch' or swatch-y fields)
  const isSwatch = useMemo(() => {
    if (meta?.item_type === 'swatch') return true;
    const first = (Array.isArray(searchItems) && searchItems.length) ? searchItems[0] : null;
    if (!first) return false;
    if (first.item_type === 'swatch') return true;
    // Heuristic: swatches typically have hex6/hcl_l
    return ('hex6' in first) || ('hcl_l' in first);
  }, [meta?.item_type, searchItems]);

  const { searchFilters, setNoResults, brandFiltersAppliedSeq } = useAppState();

  useEffect(() => {
    return () => { if (abortRef.current) abortRef.current.abort(); };
  }, []);

  useHashJumpAfterLayout({ offsetPx: 140 });

  function makeServerFilters(sf) {
    const codes = Array.isArray(sf?.brands)
      ? sf.brands.map(s => String(s).trim().toLowerCase()).filter(Boolean)
      : [];
    return codes.length ? { brand: codes } : {};
  }

  const runQueryById = async (query_id, params = [], opts = {}) => {
    const { allowNavigate = false } = opts;
    const reqId = ++activeReqRef.current;

    if (abortRef.current) abortRef.current.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    let res;
    try {
      const v2 = `${API_FOLDER}/v2/run-query.php?t=${Date.now()}`;

      // reset view while loading
      setNoResults(false);
      setSearchItems([]);
      setInsertItems([]);
      setMeta(null);

      const serverFilters = makeServerFilters(searchFilters);

      res = await fetch(v2, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query_id,
          params,                 // includes group_mode from URL
          searchFilters: serverFilters,
        }),
        signal: controller.signal,
      });

      const data = await res.json();

      if (activeReqRef.current !== reqId) return;
      const returnedId = Number(data?.meta?.meta_id ?? query_id);
      if (returnedId !== Number(query_id)) return;

      if (data.error) {
        console.error('Query failed:', data.error);
        return;
      }

      if ((data.results || []).length === 0) {
        setNoResults(true);
        setSearchItems([]);
        setInsertItems([]);
        setMeta(data.meta || null);
        return;
      }

      setNoResults(false);
      setSearchItems(data.results || []);
      setInsertItems(data.inserts || []);
      setMeta(data.meta);

      const onResultsRoute = location.pathname.startsWith('/results/');
      if (allowNavigate && onResultsRoute && location.pathname !== `/results/${query_id}`) {
        navigate(`/results/${query_id}`);
      }

      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      if (err.name !== 'AbortError') {
        console.error('Failed to parse JSON:', err);
        if (res && !res.ok) {
          const raw = await res.text().catch(() => '');
          console.error('Raw response:', raw);
        }
      }
    }
  };

  // URL param helpers
  function setUrlParamAndNavigate(key, value) {
    const sp = new URLSearchParams(location.search);
    if (value == null || value === '') sp.delete(key);
    else sp.set(key, String(value));
    navigate(`${location.pathname}?${sp.toString()}`, { replace: true });
  }

  const groupMode = ['lightness', 'chroma'].includes(paramObj.group_mode) ? paramObj.group_mode : 'hue';
  function setGroupMode(next) {
    const allowed = ['hue', 'lightness', 'chroma'];
    const mode = allowed.includes(next) ? next : 'hue';
    setUrlParamAndNavigate('group_mode', mode === 'hue' ? 'hue' : mode);
  }

  // Initial / route-change
  useEffect(() => {
    if (!queryId) return;
    const id = parseInt(queryId, 10);
    runQueryById(id, paramObj, { allowNavigate: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queryId, location.search]);

  // Re-run on Apply signal
  useEffect(() => {
    if (!queryId) return;
    const id = parseInt(queryId, 10);
    runQueryById(id, paramObj, { allowNavigate: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brandFiltersAppliedSeq]);

  // Re-run when brand selection changes
  useEffect(() => {
    if (!queryId) return;
    const id = parseInt(queryId, 10);
    runQueryById(id, paramObj, { allowNavigate: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(searchFilters?.brands || [])]);

  useEffect(() => {
    const onScroll = () => setShowBackToTop(window.scrollY > 500);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Optional: scrub lingering group_mode on non-swatch pages
  useEffect(() => {
    if (meta && !isSwatch && searchParams.has('group_mode')) {
      setUrlParamAndNavigate('group_mode', ''); // remove param
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meta?.item_type, isSwatch]);

  return (
    <div className="gallery-wrapper">
      <TopSpacer />

      {isSwatch && (
        <div className="gallery-controls">
          <button
            type="button"
            onClick={() => setGroupMode('hue')}
            aria-pressed={groupMode === 'hue'}
            className={`btn ${groupMode === 'hue' ? 'btn-active' : ''}`}
            title="Group by Hue"
          >
            <span className="btn-label-full">Group by Hue</span>
            <span className="btn-label-short">Hue</span>
          </button>
          <button
            type="button"
            onClick={() => setGroupMode('lightness')}
            aria-pressed={groupMode === 'lightness'}
            className={`btn ${groupMode === 'lightness' ? 'btn-active' : ''}`}
            title="Group by Lightness"
          >
            <span className="btn-label-full">Group by Lightness</span>
            <span className="btn-label-short">Lightness</span>
          </button>
          <button
            type="button"
            onClick={() => setGroupMode('chroma')}
            aria-pressed={groupMode === 'chroma'}
            className={`btn ${groupMode === 'chroma' ? 'btn-active' : ''}`}
            title="Group by Chroma"
          >
            <span className="btn-label-full">Group by Chroma</span>
            <span className="btn-label-short">Chroma</span>
          </button>
        </div>
      )}

      <Gallery
        items={mergeWithInserts(searchItems, insertItems)}
        runQueryById={runQueryById}
        meta={meta}
      />
      {showBackToTop && (
        <button
          type="button"
          className="back-to-top"
          onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
        >
          Back to top
        </button>
      )}
    </div>
  );
};

export default GalleryPage;
