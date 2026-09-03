// src/pages/GalleryPage/index.jsx
import { useParams, useNavigate, useLocation } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import { API_FOLDER } from '@helpers/config';
import { useEffect, useState, useRef, useMemo } from 'react';
import Gallery from '@components/Gallery/Gallery';
import { mergeWithInserts } from '@helpers/mergeHelper';
import TopSpacer from '@layout/TopSpacer';
import useHashJumpAfterLayout from '@hooks/useHashJumpAfterLayout.js';

const GalleryPage = ({ defaultQueryId = null }) => {
  const activeReqRef = useRef(0);
  const abortRef = useRef(null);
  const lastScrollRef = useRef(0);

  const { queryId } = useParams();
  const effectiveQueryId = queryId || defaultQueryId;
  const navigate = useNavigate();
  const location = useLocation();

  const searchParams = useMemo(
    () => new URLSearchParams(location.search),
    [location.search]
  );
  const paramObj = useMemo(
    () => Object.fromEntries(searchParams.entries()),
    [searchParams]
  );

  const [searchItems, setSearchItems] = useState([]);
  const [insertItems, setInsertItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [showBackToTop, setShowBackToTop] = useState(false);
  const [showSortPeek, setShowSortPeek] = useState(true);
  const [isMobile, setIsMobile] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(max-width: 1000px)').matches;
  });

  const {
    searchFilters,
    setNoResults,
    brandFiltersAppliedSeq,
    showPalette,
  } = useAppState();

  // Decide if this page is a swatch gallery.
  // 1) meta.item_type === 'swatch' (preferred when present)
  // 2) OR the first result looks like a swatch.
  const isSwatch = useMemo(() => {
    if (meta?.item_type === 'swatch') return true;

    const first =
      Array.isArray(searchItems) && searchItems.length
        ? searchItems[0]
        : null;

    if (!first) return false;
    if (first.item_type === 'swatch') return true;

    return 'hex6' in first || 'hcl_l' in first;
  }, [meta?.item_type, searchItems]);

  useEffect(() => {
    return () => {
      if (abortRef.current) abortRef.current.abort();
    };
  }, []);

  useHashJumpAfterLayout({ offsetPx: 140 });

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;

    const mq = window.matchMedia('(max-width: 1000px)');
    const handleChange = (event) => setIsMobile(event.matches);

    setIsMobile(mq.matches);
    mq.addEventListener('change', handleChange);

    return () => mq.removeEventListener('change', handleChange);
  }, []);

  function makeServerFilters(sf) {
    const codes = Array.isArray(sf?.brands)
      ? sf.brands
          .map((s) => String(s).trim().toLowerCase())
          .filter(Boolean)
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
          params,
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

      const brandCodes = Array.isArray(searchFilters?.brands)
        ? searchFilters.brands
            .map((s) => String(s).trim().toLowerCase())
            .filter(Boolean)
        : [];

      const shouldFilterBrands =
        (data?.meta?.item_type || '').toLowerCase() === 'swatch';

      const filteredResults =
        brandCodes.length && shouldFilterBrands
          ? (data.results || []).filter((row) => {
              const brand = String(
                row?.brand ?? row?.color?.brand ?? ''
              )
                .trim()
                .toLowerCase();

              return brand && brandCodes.includes(brand);
            })
          : data.results || [];

      setNoResults(filteredResults.length === 0);
      setSearchItems(filteredResults);
      setInsertItems(
        Array.isArray(data.inserts) ? [...data.inserts] : []
      );
      setMeta(data.meta || null);

      const onResultsRoute = location.pathname.startsWith('/results/');
      if (
        allowNavigate &&
        onResultsRoute &&
        location.pathname !== `/results/${query_id}`
      ) {
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

  function setUrlParamAndNavigate(key, value) {
    const sp = new URLSearchParams(location.search);

    if (value == null || value === '') {
      sp.delete(key);
    } else {
      sp.set(key, String(value));
    }

    const query = sp.toString();
    navigate(
      query ? `${location.pathname}?${query}` : location.pathname,
      { replace: true }
    );
  }

  const groupMode = ['lightness', 'chroma'].includes(paramObj.group_mode)
    ? paramObj.group_mode
    : 'hue';

  function setGroupMode(next) {
    const allowed = ['hue', 'lightness', 'chroma'];
    const mode = allowed.includes(next) ? next : 'hue';
    setUrlParamAndNavigate('group_mode', mode);
  }

  // Initial load / route change.
  useEffect(() => {
    if (!effectiveQueryId) return;

    const id = parseInt(effectiveQueryId, 10);
    runQueryById(id, paramObj, { allowNavigate: true });

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [effectiveQueryId, location.search]);

  // Re-run on Apply signal.
  useEffect(() => {
    if (!effectiveQueryId) return;

    const id = parseInt(effectiveQueryId, 10);
    runQueryById(id, paramObj, { allowNavigate: false });

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brandFiltersAppliedSeq]);

  // Re-run when brand selection changes.
  useEffect(() => {
    if (!effectiveQueryId) return;

    const id = parseInt(effectiveQueryId, 10);
    runQueryById(id, paramObj, { allowNavigate: false });

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(searchFilters?.brands || [])]);

  useEffect(() => {
    const onScroll = () => {
      const y = window.scrollY;
      setShowBackToTop(y > 500);

      const last = lastScrollRef.current;
      const delta = y - last;

      if (delta < -2) {
        setShowSortPeek(true);
      } else if (delta > 2) {
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
      if (
        e.key === 'ArrowUp' ||
        e.key === 'PageUp' ||
        e.key === 'Home'
      ) {
        setShowSortPeek(true);
      }

      if (
        e.key === 'ArrowDown' ||
        e.key === 'PageDown' ||
        e.key === ' ' ||
        e.key === 'End'
      ) {
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

  // Scrub lingering group_mode from non-swatch pages.
  useEffect(() => {
    if (meta && !isSwatch && searchParams.has('group_mode')) {
      setUrlParamAndNavigate('group_mode', '');
    }

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meta?.item_type, isSwatch]);

  const mergedItems = mergeWithInserts(searchItems, insertItems);

  const isHue = groupMode === 'hue';

  const isWheelItem = (item) => {
    const type = String(item?.item_type || '').toLowerCase();
    return type === 'colorwheel' || type === 'colorwheel-ticked';
  };

  const heroItems = isHue
    ? mergedItems.filter(isWheelItem)
    : [];

  const galleryItems = isHue
    ? mergedItems.filter((item) => !isWheelItem(item))
    : mergedItems;

  return (
    <div className="gallery-wrapper">
      <TopSpacer disabled={isMobile} />

      {isSwatch && (
        <div
          className={`gallery-controls${
            showSortPeek ? ' is-peek' : ''
          }${showPalette ? ' has-palette' : ''}`}
        >
          <button
            type="button"
            onClick={() => setGroupMode('hue')}
            aria-pressed={groupMode === 'hue'}
            className={`btn ${
              groupMode === 'hue' ? 'btn-active' : ''
            }`}
            title="Group by Hue"
          >
            <span className="btn-label-full">Group by Hue</span>
            <span className="btn-label-short">Hue</span>
          </button>

          <button
            type="button"
            onClick={() => setGroupMode('lightness')}
            aria-pressed={groupMode === 'lightness'}
            className={`btn ${
              groupMode === 'lightness' ? 'btn-active' : ''
            }`}
            title="Group by Lightness"
          >
            <span className="btn-label-full">
              Group by Lightness
            </span>
            <span className="btn-label-short">Lightness</span>
          </button>

          <button
            type="button"
            onClick={() => setGroupMode('chroma')}
            aria-pressed={groupMode === 'chroma'}
            className={`btn ${
              groupMode === 'chroma' ? 'btn-active' : ''
            }`}
            title="Group by Chroma"
          >
            <span className="btn-label-full">Group by Chroma</span>
            <span className="btn-label-short">Chroma</span>
          </button>
        </div>
      )}

      <Gallery
        items={galleryItems}
        heroItems={heroItems}
        runQueryById={runQueryById}
        meta={meta}
      />

      {showBackToTop && (
        <button
          type="button"
          className="back-to-top"
          onClick={() =>
            window.scrollTo({ top: 0, behavior: 'smooth' })
          }
        >
          Back to top
        </button>
      )}
    </div>
  );
};

export default GalleryPage;
