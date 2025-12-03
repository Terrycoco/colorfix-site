// src/components/BoardScroller.jsx
import { useNavigate, useLocation} from 'react-router-dom';
import {useEffect, useState, useRef} from 'react';
import { useAppState } from '@context/AppStateContext';
import './boardscroller.css';
import StickyPaletteBar from '@components/StickyPaletteBar';
import { PaletteOutlineIcon } from '@components/Icons/PaletteIcons';
import useCanGoBack from "@hooks/useCanGoBack.js";
import MiniBrandIcon from '@components/BrandFilter/MiniBrandIcon';
import BrandFilterModal from "@components/BrandFilter";


const BoardScroller = () => {
  const navigate = useNavigate();
  const location= useLocation();
  const { palette, paletteCollapsed, setPaletteCollapsed, searchFilters } = useAppState();
  const canGoBack = useCanGoBack();
  const isPaletteRoute = location.pathname.startsWith('/my-palette');
  const hasSwatches    = Array.isArray(palette) && palette.length > 0;
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const count = (searchFilters?.brands || []).filter(Boolean).length;
  const filterIsActive   = count > 0;   
  const [pulse, setPulse] = useState(false);
  const [showSticky, setShowSticky] = useState(false);


    //USE EFFECTS
useEffect(() => {
  if (location.state?.openBrandFilter) {
    // open the dropdown
    setOpen(true);

    // brief highlight on the icon (optional)
    setPulse(true);
    const t = setTimeout(() => setPulse(false), 1200);

    // consume the flag so it doesn’t re-open on back/forward
    navigate('.', { replace: true, state: { ...location.state, openBrandFilter: undefined } });

    return () => clearTimeout(t);
  }
}, [location.state?.openBrandFilter, navigate, setOpen]);



useEffect(() => {
  // nav height (fallback 40)
  const getBarH = () => {
    const v = getComputedStyle(document.documentElement)
      .getPropertyValue('--bar-h').trim();
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : 40;
  };

  if (!isPaletteRoute) {
    setShowSticky(hasSwatches && !paletteCollapsed);
    return;
  }

  // ===== /my-palette logic (IO + sentinel) =====
  const barH = getBarH();

  // Use a 1px sentinel right AFTER the hero.
  // If you didn't add one in the page, we create a temporary one.
  let sentinel = document.getElementById('palette-sentinel');
  let created = false;

  if (!sentinel) {
    const hero =
      document.getElementById('palette-hero') ||
      document.getElementById('top');
    if (hero && hero.parentNode) {
      sentinel = document.createElement('div');
      sentinel.id = 'palette-sentinel-temp';
      sentinel.setAttribute('aria-hidden', 'true');
      sentinel.style.cssText = 'height:1px;position:relative;pointer-events:none;';
      hero.insertAdjacentElement('afterend', sentinel);
      created = true;
    }
  }

  if (!sentinel) { setShowSticky(false); return; }

  // Hysteresis: show when sentinel is below nav by >= 8px,
  // hide again only after it goes back above by >= 16px
  const SHOW_MARGIN = barH + 8;
  const HIDE_MARGIN = barH + 16;

  let last = showSticky;       // current visible state
  let rafQueued = false;

  const setIfChanged = (next) => {
    if (next !== last) {
      last = next;
      setShowSticky(next);
    }
  };

  // IO with negative top margin places the trigger line at the nav bottom.
  const io = new IntersectionObserver(
    ([entry]) => {
      // Batch into next frame to avoid scroll-thread jank on iOS
      if (rafQueued) return;
      rafQueued = true;
      requestAnimationFrame(() => {
        rafQueued = false;
        const top = sentinel.getBoundingClientRect().top;
        if (!last) {
          // currently hidden → only show once it's comfortably past
          setIfChanged(top <= SHOW_MARGIN);
        } else {
          // currently shown → only hide after clearly above again
          setIfChanged(top > HIDE_MARGIN);
        }
      });
    },
    { root: null, rootMargin: `-${barH}px 0px 0px 0px`, threshold: 0 }
  );

  // Initial check (handles reload mid-page)
  const r0 = sentinel.getBoundingClientRect();
  setIfChanged(r0.top <= SHOW_MARGIN);

  io.observe(sentinel);

  return () => {
    io.disconnect();
    if (created) { try { sentinel.remove(); } catch {} }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [isPaletteRoute, hasSwatches, paletteCollapsed]);






const togglePaletteBar = () => {
    setPaletteCollapsed((prev) => !prev);
  };

  const paletteArrowLabel = paletteCollapsed ? "Show palette" : "Hide palette";
  const paletteArrowIcon = paletteCollapsed ? "▼" : "▲";

//HANDLERS
const goToPalette = (e) => {
    e?.preventDefault?.(); e?.stopPropagation?.();
    if (isPaletteRoute) {

     // Scroll to the new hero id
    const el = document.getElementById('palette-hero');
     if (el?.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
     else window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {

     navigate('/my-palette#palette-hero');
    }
  };

 return (
  <>
   <div className="board-scroller" style={{ '--center-gap': '80px' }}>
      <div className="board-scroller__inner">

        {/* LEFT */}
        <div className="scroller-left">
          {canGoBack && (
            <button className="board-tab" onClick={() => navigate(-1)}>← Back</button>
          )}
        </div>

        {/* CENTER (always centered) */}
        <div className="scroller-center">
          <button
            type="button"
            className="icon-button"
            onClick={goToPalette}
            aria-label="My Palette"
          >
            <PaletteOutlineIcon className="palette-icon" title="My Palette" />
          </button>
        </div>

        {/* RIGHT (funnel to the left of the logo) */}
        <div className="scroller-right">
          {hasSwatches && (
            <button
              type="button"
              className="palette-toggle-btn"
              onClick={togglePaletteBar}
              aria-label={paletteArrowLabel}
              title={paletteArrowLabel}
            >
              {paletteArrowIcon}
            </button>
          )}
  <span
  ref={btnRef}
  className={`mini-brand-wrap ${filterIsActive ? 'has-active' : ''} ${pulse ? 'pulse' : ''}`}
  style={{ display: 'inline-flex', pointerEvents: 'auto', position: 'relative', zIndex: 3 }}
  onClick={() => setOpen(true)}
  role="button"
  tabIndex={0}
  onKeyDown={(e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      setOpen(true);
    }
  }}
  aria-label="Filter brands"
  aria-expanded={open}
>
  <MiniBrandIcon title="Filter brands" />
</span>




          <button className="board-tab logo-btn" onClick={() => navigate('/results/4')}>
            <span className="logo-text">ColorFix</span>
          </button>
        </div>

      </div>
    </div>

    {/* Dropdown can live outside; it portals to <body> anyway */}
    <BrandFilterModal
      anchorRef={btnRef}
      open={open}
      onClose={() => setOpen(false)}
    />

    <div className={`sticky-palette-wrapper ${(showSticky && hasSwatches && !paletteCollapsed) ? 'is-visible' : 'is-hidden'}`}>
      {hasSwatches && !paletteCollapsed && (
        <div className="sticky-palette__inner">
          <StickyPaletteBar />
        </div>
      )}
    </div>
  </>
);

  
}

export default BoardScroller;
