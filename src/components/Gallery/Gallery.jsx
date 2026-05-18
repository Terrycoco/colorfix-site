import React, { useMemo } from 'react';
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

const renderContent = (item) => {
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

function Gallery({ items, meta, heroItems = [], breakpointCols, className = '' }) {
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

  // Build jump bar names (exclude TOP) in the same order sections will render
  const groupNames = sections
    .map(s => s.groupName)
    .filter(g => g !== 'TOP');

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

      {sections.map((section, idx) => {
        const { groupName } = section;
        const showHeader = groupName !== 'TOP';
        const anchorId = `section-${slugify(groupName)}`;

        const sectionItems = (groupMode === 'lightness')
          ? [...section.items].sort((a, b) => {
              const la = Number(a.hcl_l ?? 0), lb = Number(b.hcl_l ?? 0);
              if (lb !== la) return lb - la;             // higher L first
              const ca = Number(a.hcl_c ?? 0), cb = Number(b.hcl_c ?? 0);
              if (ca !== cb) return ca - cb;             // lower chroma first
              const ha = Number(a.hcl_h ?? 0), hb = Number(b.hcl_h ?? 0);
              return ha - hb;                             // then hue
            })
          : (groupMode === 'chroma')
          ? [...section.items].sort((a, b) => {
              const ca = Number(a.hcl_c ?? 0), cb = Number(b.hcl_c ?? 0);
              if (cb !== ca) return cb - ca;             // higher chroma first
              const ha = Number(a.hcl_h ?? 0), hb = Number(b.hcl_h ?? 0);
              if (ha !== hb) return ha - hb;             // then hue
              const la = Number(a.hcl_l ?? 0), lb = Number(b.hcl_l ?? 0);
              return lb - la;                             // then lightness
            })
          : [...section.items].sort((a, b) => {
              const ha = Number(a.hcl_h ?? 0), hb = Number(b.hcl_h ?? 0);
              if (ha !== hb) return ha - hb;             // lower hue first
              const la = Number(a.hcl_l ?? 0), lb = Number(b.hcl_l ?? 0);
              if (lb !== la) return lb - la;             // lighter first
              const ca = Number(a.hcl_c ?? 0), cb = Number(b.hcl_c ?? 0);
              return ca - cb;                            // softer first
            });

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
          </React.Fragment>
        );
      })}

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
                        history.replaceState(null, '', window.location.pathname);
                      }
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
