import { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Masonry } from 'masonic';

import GalleryItem from '@components/Gallery/GalleryItem';
import { renderContent } from '@components/Gallery/Gallery';

const isPlaylistRail = (item) =>
  String(item?.item_type || '').toLowerCase() === 'front-page-playlist-set';

function HomeMasonryCard({ data }) {
  return (
    <GalleryItem>
      {renderContent(data)}
    </GalleryItem>
  );
}

function getInitialWidth(isMobile) {
  if (typeof window === 'undefined') {
    return isMobile ? 320 : 1200;
  }

  const viewportWidth =
    window.innerWidth ||
    document.documentElement?.clientWidth ||
    0;

  if (viewportWidth <= 0) {
    return isMobile ? 320 : 1200;
  }

  return isMobile
    ? viewportWidth
    : Math.min(viewportWidth, 1320);
}

export default function HomeMasonry({
  items = [],
  playlistItem = null,
  isMobile = false,
}) {
  const measureRef = useRef(null);

  /*
   * Never begin at width 0.
   *
   * Masonic used to be gated behind `width > 0`, which meant the entire
   * front page could stay blank on a browser/device where ResizeObserver
   * did not fire as expected. Start with a safe viewport-based width so
   * there is always something to render, then replace it with the exact
   * measured container width as soon as measurement is available.
   */
  const [width, setWidth] = useState(() => getInitialWidth(isMobile));

  const columnCount = isMobile ? 2 : 4;
  const gutter = isMobile ? 5 : 8;

  /*
   * Homepage-only ordering rule:
   *
   * mobile:
   *   A  PLAYLIST
   *
   * desktop:
   *   A  B  C  PLAYLIST
   *
   * After that, Masonic decides which column is shortest.
   */
  const masonryItems = useMemo(() => {
    const normalItems = (Array.isArray(items) ? items : []).filter(
      (item) => !isPlaylistRail(item)
    );

    if (!playlistItem) {
      return normalItems;
    }

    const next = [...normalItems];

    // 2 columns -> index 1
    // 4 columns -> index 3
    const railIndex = Math.min(columnCount - 1, next.length);

    next.splice(railIndex, 0, playlistItem);

    return next;
  }, [items, playlistItem, columnCount]);

  useLayoutEffect(() => {
    const node = measureRef.current;
    if (!node) return undefined;

    const measure = () => {
      const nextWidth = node.getBoundingClientRect().width;

      if (nextWidth > 0) {
        setWidth(nextWidth);
      }
    };

    measure();

    /*
     * ResizeObserver is preferred, but do not make the homepage depend
     * on it existing or firing correctly. Older/quirky mobile browsers
     * still get a working page and resize support.
     */
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', measure);

      return () => {
        window.removeEventListener('resize', measure);
      };
    }

    const observer = new ResizeObserver(measure);
    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  /*
   * If the responsive mode changes, immediately give Masonic a sensible
   * width for the new mode. The observer will refine it afterward.
   */
  useLayoutEffect(() => {
    setWidth((currentWidth) =>
      currentWidth > 0
        ? currentWidth
        : getInitialWidth(isMobile)
    );
  }, [isMobile]);

  const safeWidth =
    width > 0
      ? width
      : getInitialWidth(isMobile);

  const columnWidth = Math.max(
    1,
    (safeWidth - gutter * (columnCount - 1)) / columnCount
  );

  return (
    <div
      style={{
        width: '100%',
        maxWidth: '1320px',
        margin: '0 auto',
        paddingLeft: isMobile ? '0.3rem' : '0.75rem',
        paddingRight: isMobile ? '0.3rem' : '0.75rem',
        boxSizing: 'border-box',
      }}
    >
      <div ref={measureRef} style={{ width: '100%' }}>
        <Masonry
          key={`${columnCount}-${Math.round(safeWidth)}`}
          items={masonryItems}
          render={HomeMasonryCard}
          columnWidth={columnWidth}
          columnGutter={gutter}
          rowGutter={gutter}
          maxColumnCount={columnCount}
        />
      </div>
    </div>
  );
}
