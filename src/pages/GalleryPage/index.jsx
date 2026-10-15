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

  // Applying filters is an explicit action, not an initial-load trigger.
  const didMountApplyRef = useRef(false);

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

    if (!Number.isSafeInteger(Number(query_id)) || Number(query_id) <= 0) return;

    const { allowNavigate = false } = opts;

    const reqId = ++activeReqRef.current;

    if (abortRef.current) abortRef.current.abort();

    const controller = new AbortController();

    abortRef.current = controller;

    let res;

    try {

      const v2 = `${API_FOLDER}/v2/run-query.php?t=${Date.now()}`;

      // Keep the current Gallery (including the hero wheel) visible while
      // a replacement request is in flight. The successful response replaces it.
      setNoResults(false);

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

      // Stored query metadata is not always marked 'swatch' (e.g. query 18).

      // Detect swatch-shaped rows just as this page's isSwatch rendering does.

      const shouldFilterBrands =

        String(data?.meta?.item_type || '').toLowerCase() === 'swatch' ||

        (data.results || []).some((row) =>

          row?.item_type === 'swatch' ||

          row?.item_type === 'no-render' ||

          Object.prototype.hasOwnProperty.call(row || {}, 'hcl_l') ||

          Object.prototype.hasOwnProperty.call(row || {}, 'hex6')

        );

    // The new GALLERY endpoint owns photo placement in its combined response.
    // Identify it from its response, not a growing list of query IDs. Joined
    // vw_gallery rows carry photo_id/photo_url even when a color has no photo.
    // Older non-GALLERY queries retain the legacy separate photo lookup.
    const galleryOwnsPhotos =
      data?.meta?.gallery_owns_photos === true ||
      data?.gallery_photo_debug != null ||
      (data.results || []).some((row) =>
        Object.prototype.hasOwnProperty.call(row || {}, 'photo_id') &&
        Object.prototype.hasOwnProperty.call(row || {}, 'photo_url')
      );

    let rowsWithGallery = data.results || [];

    if (shouldFilterBrands && !galleryOwnsPhotos) {

      const colorIds = [...new Set(

        rowsWithGallery

          .filter((row) => row?.item_type !== 'picture-swatch')

          .map((row) => Number(row?.id ?? row?.color_id))

          .filter((id) => Number.isSafeInteger(id) && id > 0)

      )];

      if (colorIds.length) {

        try {

          const chunks = [];

          for (let i = 0; i < colorIds.length; i += 300) {

            chunks.push(colorIds.slice(i, i + 300));

          }

          const galleries = [];

          // Small batches keep the endpoint safe for very large color results.

          for (const batch of chunks) {

            const response = await fetch(`${API_FOLDER}/v2/gallery-results.php`, {

              method: 'POST',

              headers: { 'Content-Type': 'application/json' },

              body: JSON.stringify({ color_ids: batch }),

              signal: controller.signal,

            });

            if (!response.ok) throw new Error(`Gallery HTTP ${response.status}`);

            const body = await response.json();

            if (!body.ok || !Array.isArray(body.items)) throw new Error('Invalid Gallery response');

            galleries.push(...body.items);

          }

          if (activeReqRef.current !== reqId) return;

          const photoIds = new Set();

          const photosByColor = new Map();

          for (const photo of galleries) {

            const photoId = Number(photo.photo_library_id);

            if (photoIds.has(photoId)) continue;

            photoIds.add(photoId);

            const colorId = Number(photo.source_color_id);

            const related = photosByColor.get(colorId) || [];

            related.push(photo);

            photosByColor.set(colorId, related);

          }

          // Replace only legacy injected pictures; keep swatches and their order.

          rowsWithGallery = [];

          for (const row of data.results || []) {

            if (row?.item_type === 'picture-swatch') continue;

            rowsWithGallery.push(row);

            const colorId = Number(row?.id ?? row?.color_id);

            for (const photo of photosByColor.get(colorId) || []) {

              rowsWithGallery.push({

                ...photo,

                hue_cats: row.hue_cats ?? null,

                light_cat_name: row.light_cat_name ?? row.__light_cat_name_outer ?? null,

                light_cat_order: row.light_cat_order ?? row.__light_cat_order_outer ?? null,

                hcl_h: row.hcl_h ?? null,

                hcl_c: row.hcl_c ?? null,

                hcl_l: row.hcl_l ?? null,

              });

            }

          }

        } catch (error) {

          if (error.name === 'AbortError') return;

          console.error('Gallery photo lookup failed; retaining original results:', error);

        }

      }

    }



      const filteredResults =

        brandCodes.length && shouldFilterBrands

          ? rowsWithGallery.filter((row) => {

            if (row?.item_type === 'picture-swatch') {

              const source = rowsWithGallery.find((swatch) =>

                swatch?.item_type !== 'picture-swatch' &&

                Number(swatch?.id ?? swatch?.color_id) === Number(row.source_color_id)

              );

              const sourceBrand = String(source?.brand ?? source?.color?.brand ?? '').trim().toLowerCase();

              return brandCodes.includes(sourceBrand);

            }

            const brand = String(

                row?.brand ?? row?.color?.brand ?? ''

              )

                .trim()

                .toLowerCase();

              return brand && brandCodes.includes(brand);

            })

          : rowsWithGallery;

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

  // Brand selections are draft state. Fetch only when Apply is clicked.
  // Skip the initial render: the route effect already sends the current
  // brand filters with its first vw_gallery request.
  useEffect(() => {

    if (!didMountApplyRef.current) {
      didMountApplyRef.current = true;
      return;
    }

    if (!effectiveQueryId) return;

    const id = parseInt(effectiveQueryId, 10);

    runQueryById(id, paramObj, { allowNavigate: false });

    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brandFiltersAppliedSeq]);

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

  const mergedItems = useMemo(
    () => mergeWithInserts(searchItems, insertItems),
    [searchItems, insertItems]
  );

  const isHue = groupMode === 'hue';

  const isWheelItem = (item) => {

    const type = String(item?.item_type || '').toLowerCase();

    return type === 'colorwheel' || type === 'colorwheel-ticked';

  };

  const heroItems = useMemo(
    () => isHue ? mergedItems.filter(isWheelItem) : [],
    [mergedItems, isHue]
  );

  const galleryItems = useMemo(
    () => isHue ? mergedItems.filter((item) => !isWheelItem(item)) : mergedItems,
    [mergedItems, isHue]
  );

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
