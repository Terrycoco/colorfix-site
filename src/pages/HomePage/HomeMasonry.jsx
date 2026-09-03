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

export default function HomeMasonry({
  items = [],
  playlistItem = null,
  isMobile = false,
}) {
  const measureRef = useRef(null);
  const [width, setWidth] = useState(0);

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
      setWidth(nextWidth);
    };

    measure();

    const observer = new ResizeObserver(measure);
    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  const columnWidth =
    width > 0
      ? Math.max(
          1,
          (width - gutter * (columnCount - 1)) / columnCount
        )
      : isMobile
        ? 160
        : 280;

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
        {width > 0 && (
          <Masonry
            key={`${columnCount}-${Math.round(width)}`}
            items={masonryItems}
            render={HomeMasonryCard}
            columnWidth={columnWidth}
            columnGutter={gutter}
            rowGutter={gutter}
            maxColumnCount={columnCount}
          />
        )}
      </div>
    </div>
  );
}