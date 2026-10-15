import React, { useEffect, useMemo, useRef, useState } from 'react';

import { useLocation } from 'react-router-dom';

import { useAppState } from '@context/AppStateContext';

import GalleryGrid from './GalleryGrid';

import GalleryItem from './GalleryItem';

import SwatchItem from '../GalleryItems/SwatchItem';

import ImageItem from '../GalleryItems/ImageItem';

import SearchItem from '../GalleryItems/SearchItem';

import QuoteItem from '../GalleryItems/QuoteItem';

import BackItem from '../GalleryItems/BackItem';

import BrandItem from '../GalleryItems/BrandItem';

import ButtonItem from '../GalleryItems/ButtonItem';

import HeaderItem from '../GalleryItems/HeaderItem';

import NameSearchItem from '../GalleryItems/NameSearchItem';

import WheelItem from '../GalleryItems/WheelItem';

import ColorWheelItem from '../GalleryItems/ColorWheelItem';

import ColorWheelTicked from '../GalleryItems/ColorWheelTicked';

import PictureSwatchItem from '../GalleryItems/PictureSwatchItem';

import FeaturedArticleItem from '../GalleryItems/FeaturedArticleItem';

import PlaylistItem from '../GalleryItems/PlaylistItem';

import TextItem from '../GalleryItems/TextItem';

import FrontBlurbItem from '../GalleryItems/FrontBlurbItem';

import FrontPagePlaylistSetItem from '../GalleryItems/FrontPagePlaylistSetItem';

import AutoHideFooter from '@components/AutoHideFooter';

import './gallery.css';



export const renderContent = (item) => {

  const key = item.id || item.query_id || `${Math.random()}-${item.item_type || 'itm'}`;

  const type = (item.item_type || '').toLowerCase();

  switch (type) {

    case 'swatch':      return <SwatchItem key={key} item={item} />;

    case 'picture-swatch': return <PictureSwatchItem key={key} item={item} />;

    case 'image':       return <ImageItem key={key} item={item} />;

    case 'search':      return <SearchItem key={key} item={item} />;

    case 'quote':       return <QuoteItem key={key} item={item} />;

    case 'back':        return <BackItem key={key} item={item} />;

    case 'brand':       return <BrandItem key={key} item={item} />;

    case 'button':      return <ButtonItem key={key} item={item} />;

    case 'playlist':    return <PlaylistItem key={key} item={item} />;

    case 'front-page-playlist-set':

      return <FrontPagePlaylistSetItem key={key} item={item} />;

    case 'name-search': return <NameSearchItem key={key} item={item} />;

    case 'front-blurb': return <FrontBlurbItem key={key} item={item} />;

    case 'text':        return <TextItem key={key} item={item} />;

    case 'wheel':       return <WheelItem key={key} item={item} />;

    case 'colorwheel':  return <ColorWheelItem key={key} item={item} />;

    case 'colorwheel-ticked':

    case 'wheel-ticked':

      return <ColorWheelTicked key={key} item={item} />;

    case 'featured-article':

      return <FeaturedArticleItem key={key} item={item} />;

    default:            return null;

  }

};



function slugify(s) {

  return String(s || '')

    .toLowerCase()

    .replace(/\s+/g, '-')

    .replace(/[^a-z0-9-]/g, '')

    .replace(/-+/g, '-')

    .replace(/^-+|-+$/g, '') || 'top';

}



function getNumericLabelPrefix(value) {

  const text = String(value || '').trim();

  const match = text.match(/^(-?\d+(?:\.\d+)?)/);

  if (!match) return null;

  const num = Number(match[1]);

  return Number.isFinite(num) ? num : null;

}



const FIRST_BATCH = 120;

const NEXT_BATCH = 60;



function Gallery({ items, meta, heroItems = [], breakpointCols, className = '' }) {

  // React only mounts the visible prefix. All returned data stays in memory.

  const [batchState, setBatchState] = useState({ source: items, limit: FIRST_BATCH });

  const [pendingJump, setPendingJump] = useState(null);

  const sentinelRef = useRef(null);

  const renderLimit = batchState.source === items ? batchState.limit : FIRST_BATCH;

  useEffect(() => {

    setBatchState({ source: items, limit: FIRST_BATCH });

    setPendingJump(null);

  }, [items]);



  const location = useLocation();

  const { categories = [] } = useAppState();

  const hideDisclaimerRoutes = ['/results/4', '/results/3', '/results/2'];

  const shouldHideDisclaimer = hideDisclaimerRoutes.some(route => location.pathname === route);



  // Mode decided by runner; default to hue

  const groupMode = ['lightness', 'chroma'].includes(meta?.params?.group_mode)

    ? meta.params.group_mode

    : 'hue';



  // Pick label field per mode (fallbacks included)

  const getGroupLabel = (item) => {

    if (groupMode === 'lightness') {

      return item.light_cat_name || item.__light_cat_name_outer || 'TOP';

    }

    if (groupMode === 'chroma') {

      return 'TOP';

    }

    return item.hue_cats || 'TOP';

  };



  const getGroupOrder = (item) => {

    if (groupMode !== 'lightness' || !item) return null;

    const candidates = [

      item.light_cat_order,

      item.light_order,

      item.light_cat_sort,

      item.__light_cat_order_outer,

    ];

    for (const c of candidates) {

      const num = Number(c);

      if (Number.isFinite(num)) return num;

    }

    return null;

  };



  const hueOrderMap = useMemo(() => {

    const map = new Map();

    if (!Array.isArray(categories)) return map;

    categories.forEach((cat) => {

      if (!cat?.name || String(cat.type || '').toLowerCase() !== 'hue') return;

      const key = String(cat.name).trim().toLowerCase();

      const order = Number(cat.sort_order);

      if (Number.isFinite(order)) map.set(key, order);

      else if (Number.isFinite(cat.hue_min)) map.set(key, Number(cat.hue_min));

    });

    return map;

  }, [categories]);



  const getHueGroupOrder = (item) => {

    if (groupMode !== 'hue' || !item) return null;

    const candidates = [

      item.hue_cat_order,

      item.hue_order,

      item.hue_cat_sort,

      item.__hue_cat_order_outer,

    ];

    for (const c of candidates) {

      const num = Number(c);

      if (Number.isFinite(num)) return num;

    }

    const label = String(item.hue_cats || '').trim().toLowerCase();

    if (label && hueOrderMap.has(label)) return hueOrderMap.get(label);

    return null;

  };



  // ---- Coalesce by group name (first-seen order), so each group renders ONCE ----

  const sections = [];

  const seen = new Map(); // groupName -> section { groupName, items: [], groupOrder }



  let lastLabel = null;

  let lastOrder = null;

  for (const item of items) {

    // Patch inserted items to inherit last label if missing

    if (item.insert_position > 0 && lastLabel) {

      if (groupMode === 'lightness' && !item.light_cat_name) item.light_cat_name = lastLabel;

      if (groupMode === 'hue' && !item.hue_cats) item.hue_cats = lastLabel;

      if (groupMode === 'lightness' && lastOrder != null && item.__light_cat_order_outer == null) {

        item.__light_cat_order_outer = lastOrder;

      }

    }



    const label = getGroupLabel(item);

    const orderVal = getGroupOrder(item) ?? getHueGroupOrder(item);

    lastLabel = label || lastLabel;

    lastOrder = (orderVal != null ? orderVal : lastOrder);



    if (!seen.has(label)) {

      const section = {

        groupName: label || 'TOP',

        items: [],

        groupOrder: orderVal != null ? orderVal : (lastOrder != null ? lastOrder : 999),

        orderDefined: orderVal != null

      };

      seen.set(label, section);

      sections.push(section);

    }

    const section = seen.get(label);

    if (orderVal != null) {

      section.groupOrder = Math.min(section.groupOrder ?? 999, orderVal);

      section.orderDefined = true;

    }

    section.items.push(item);

  }



  // Force section order in lightness mode: Light → Medium → Dark

  if (groupMode === 'lightness' || groupMode === 'hue') {

    sections.sort((a, b) => {

      const ra = a.groupOrder ?? 999;

      const rb = b.groupOrder ?? 999;

      if (ra !== rb) return ra - rb;

      const na = getNumericLabelPrefix(a.groupName);

      const nb = getNumericLabelPrefix(b.groupName);

      if (na != null && nb != null && na !== nb) return na - nb;

      return String(a.groupName).localeCompare(String(b.groupName), undefined, {

        numeric: true,

        sensitivity: 'base',

      });

    });

  }



  // Build jump bar names from ALL sections, including those not mounted yet.

  const groupNames = sections

    .map(s => s.groupName)

    .filter(g => g !== 'TOP');



  const progressive = /^\/(?:admin\/)?results\/\d+\/?$/.test(location.pathname) && items.length > FIRST_BATCH;



  // Keep existing hue/lightness/chroma ordering. Sort each section before

  // slicing, otherwise the later batches would be in the wrong order.

  const sortedSectionItems = (section) => {

    if (groupMode === 'lightness') {

      return [...section.items].sort((a, b) => {

        const la = Number(a.hcl_l ?? 0), lb = Number(b.hcl_l ?? 0);

        if (lb !== la) return lb - la;

        const ca = Number(a.hcl_c ?? 0), cb = Number(b.hcl_c ?? 0);

        if (ca !== cb) return ca - cb;

        return Number(a.hcl_h ?? 0) - Number(b.hcl_h ?? 0);

      });

    }

    if (groupMode === 'chroma') {

      return [...section.items].sort((a, b) => {

        const ca = Number(a.hcl_c ?? 0), cb = Number(b.hcl_c ?? 0);

        if (cb !== ca) return cb - ca;

        const ha = Number(a.hcl_h ?? 0), hb = Number(b.hcl_h ?? 0);

        if (ha !== hb) return ha - hb;

        return Number(b.hcl_l ?? 0) - Number(a.hcl_l ?? 0);

      });

    }

    return [...section.items].sort((a, b) => {

      const ha = Number(a.hcl_h ?? 0), hb = Number(b.hcl_h ?? 0);

      if (ha !== hb) return ha - hb;

      const la = Number(a.hcl_l ?? 0), lb = Number(b.hcl_l ?? 0);

      if (lb !== la) return lb - la;

      return Number(a.hcl_c ?? 0) - Number(b.hcl_c ?? 0);

    });

  };



  const visibleSections = [];

  let remaining = progressive ? renderLimit : Infinity;

  for (const section of sections) {

    if (remaining <= 0) break;

    const ordered = sortedSectionItems(section);

    const visible = ordered.slice(0, remaining);

    if (visible.length) visibleSections.push({ ...section, visible });

    remaining -= visible.length;

  }



  // One observer, watching the end of the mounted prefix. No extra API calls.

  useEffect(() => {

    if (!progressive || renderLimit >= items.length || !sentinelRef.current) return;

    if (typeof IntersectionObserver === 'undefined') return;

    const observer = new IntersectionObserver((entries) => {

      if (entries.some((entry) => entry.isIntersecting)) {

        observer.disconnect();

        setBatchState((prev) => ({

          source: items,

          limit: Math.min(items.length, (prev.source === items ? prev.limit : FIRST_BATCH) + NEXT_BATCH),

        }));

      }

    }, { rootMargin: '0px 0px 2400px 0px' });

    observer.observe(sentinelRef.current);

    return () => observer.disconnect();

  }, [items, progressive, renderLimit]);



  // Jump navigation (including direct #swatch-ID links) must work even when

  // the destination isn't mounted. Expand the ordered prefix first.

  const requestJump = (targetId) => {

    let index = 0;

    for (const section of sections) {

      const headerId = `section-${slugify(section.groupName)}`;

      const ordered = sortedSectionItems(section);

      if (targetId === headerId) {

        setBatchState({ source: items, limit: Math.min(items.length, Math.max(renderLimit, index + Math.max(1, NEXT_BATCH))) });

        setPendingJump(targetId);

        return;

      }

      const within = ordered.findIndex((item) => `swatch-${item.id}` === targetId);

      if (within >= 0) {

        setBatchState({ source: items, limit: Math.min(items.length, Math.max(renderLimit, index + within + NEXT_BATCH)) });

        setPendingJump(targetId);

        return;

      }

      index += ordered.length;

    }

  };



  useEffect(() => {

    if (!progressive || !items.length) return;

    const hash = decodeURIComponent(location.hash || '').replace(/^#/, '');

    if (hash.startsWith('swatch-') || hash.startsWith('section-')) requestJump(hash);

    // Only react to navigation/data replacement, not incremental mounts.

    // eslint-disable-next-line react-hooks/exhaustive-deps

  }, [items, location.hash, progressive]);



  useEffect(() => {

    if (!pendingJump) return;

    const handle = requestAnimationFrame(() => {

      const target = document.getElementById(pendingJump);

      if (!target) return;

      target.scrollIntoView({ block: 'start' });

      setPendingJump(null);

    });

    return () => cancelAnimationFrame(handle);

  }, [pendingJump, renderLimit]);



  return (

    <div className={`gallery w-full max-w-6xl mx-auto ${className}`.trim()}>

      {meta?.has_header == 1 && <HeaderItem meta={meta} />}



      {heroItems.length > 0 && (

        <div className="gallery-hero">

          {heroItems.map((item) => (

            <div

              key={item.id || item.query_id || `${Math.random()}-hero`}

              className="gallery-hero-item"

            >

              {renderContent(item)}

            </div>

          ))}

        </div>

      )}



      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-1 gap-2">

        <div className="flex gap-2">{/* other controls here */}</div>

      </div>



      {visibleSections.map((section, idx) => {

        const { groupName } = section;

        const showHeader = groupName !== 'TOP';

        const anchorId = `section-${slugify(groupName)}`;

        const sectionItems = section.visible;



        const isPlaylistRail = (item) =>

          String(item?.item_type || '').toLowerCase() ===

          'front-page-playlist-set';



        const playlistRail = sectionItems.find(isPlaylistRail);

        const normalItems = sectionItems.filter((item) => !isPlaylistRail(item));



        const orderedItems =

          playlistRail && normalItems.length

            ? [

                normalItems[0],

                playlistRail,

                ...normalItems.slice(1),

              ]

            : sectionItems;



        return (

          <React.Fragment key={`section-${slugify(groupName)}-${idx}`}>

            {showHeader && (

              <div

                id={anchorId}

                className="section-head w-full text-center my-6 text-xl font-bold opacity-80 scroll-mt-18"

              >

                {groupName}

              </div>

            )}



            {playlistRail ? (

              <div

                style={{

                  display: 'flow-root',

                  width: '100%',

                  paddingLeft: '0.3rem',

                  paddingRight: '0.3rem',

                  boxSizing: 'border-box',

                }}

              >

                {orderedItems.map((item) => {

                  const rail = isPlaylistRail(item);



                  return (

                    <div

                      key={item.id || item.query_id || `${Math.random()}-gi`}

                      style={{

                        float: rail ? 'right' : 'left',

                        width: '50%',

                        paddingLeft: '0.3rem',

                        marginBottom: '0.3rem',

                        boxSizing: 'border-box',

                      }}

                    >

                      <GalleryItem>

                        {renderContent(item)}

                      </GalleryItem>

                    </div>

                  );

                })}

              </div>

            ) : (

              <GalleryGrid

                key={`grid-${slugify(groupName)}-${idx}`}

                breakpointCols={breakpointCols}

              >

                {sectionItems.map((item) => (

                  <GalleryItem key={item.id || item.query_id || `${Math.random()}-gi`}>

                    {renderContent(item)}

                  </GalleryItem>

                ))}

              </GalleryGrid>

            )}

          </React.Fragment>

        );

      })}



      {progressive && renderLimit < items.length && (

        <div ref={sentinelRef} style={{ height: 1, width: '100%' }} aria-hidden="true" />

      )}

      {progressive && renderLimit < items.length && typeof IntersectionObserver === 'undefined' && (

        <button type="button" onClick={() => setBatchState({ source: items, limit: Math.min(items.length, renderLimit + NEXT_BATCH) })}>

          Load more colors

        </button>

      )}



      {!shouldHideDisclaimer && groupNames.length > 1 && (

        <AutoHideFooter

          bottomHoverZone={120}

          bottomScrollThreshold={160}

          idleTimeout={2200}

          showOnInitial={false}

        >

          <div className="footer-section">

            <div className="jump-bar-inner">

              {groupNames.map((name, i) => {

                const targetId = `section-${slugify(name)}`;

                return (

                  <span

                    key={`${targetId}-${i}`}

                    className="jump-link"

                    onClick={() => {

                      const el = document.getElementById(targetId);

                      if (el) {

                        el.scrollIntoView();

                      } else if (progressive) {

                        requestJump(targetId);

                      }

                      history.replaceState(null, '', window.location.pathname + window.location.search);

                    }}

                  >

                    {name}

                  </span>

                );

              })}

            </div>

            <div className="category-disclaimer">

              NOTE: The HCL Color Wheel is a gradual blending of colors and category divisions are arbitrary.

              Colors near the edges will have characteristics of the other side.

            </div>

          </div>

        </AutoHideFooter>

      )}

    </div>

  );

}



export default Gallery;
