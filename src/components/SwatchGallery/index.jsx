// SwatchGallery.jsx
import React from 'react';
import './swatchgallery.css';

export default function SwatchGallery({
  items = [],
  SwatchComponent,
  swatchPropName = 'color',
  // you only need these two to size desktop:
  itemMaxWidth = 280,   // desktop track cap (px or '200px')
  gap = 12,             // desktop gap only
  // the rest just work as before / safe defaults:
  minWidth = 140,
  aspectRatio = '5 / 4',
  className = '',
  emptyMessage = 'No swatches to show.',
  swatchProps = {},
  groupBy = null,
  groupOrderBy = null,
  showGroupHeaders = false,
  groupHeaderRenderer = null,
  onSelectColor,
}) {
  if (!SwatchComponent) return null;

  // expose sizing via CSS variables (mobile layout stays CSS-driven)
  const min = typeof minWidth === 'number' ? `${minWidth}px` : String(minWidth);
  const max = itemMaxWidth != null
    ? (typeof itemMaxWidth === 'number' ? `${itemMaxWidth}px` : String(itemMaxWidth))
    : null;
  const gapDesktop = typeof gap === 'number' ? `${gap}px` : String(gap);

  const vars = {
    '--sg-min': min,
    '--sg-max': max ?? '280px',          // desktop cap (default)
    '--sg-gap-desktop': gapDesktop,      // desktop-only gap
    '--sg-aspect': aspectRatio,
  };

  function makeSwatchProps(item) {
    // If caller uses swatchPropName="color" and row has nested .color, pass nested swatch
    const swatch = (swatchPropName === 'color' && item?.color) ? item.color : item;
    const props = { [swatchPropName]: swatch, ...swatchProps };
    if (onSelectColor) props.onSelectColor = (e) => onSelectColor(swatch, e);
    return props;
  }

  // ---- ungrouped ----
  if (!showGroupHeaders || !groupBy) {
    return (
      <div className={`sg-root ${className}`}>
        {!items?.length ? (
          <div className="sg-empty">{emptyMessage}</div>
        ) : (
          <div className="sg-grid sg-auto-fit" style={vars}>
            {items.map((item, i) => (
              <div className="sg-item" key={item.color?.id ?? item.id ?? item.hex6 ?? i}>
                <div className="sg-card-shell">
                  <SwatchComponent {...makeSwatchProps(item)} />
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  // ---- grouped ----
  const withGroup = (items || []).map((item, i) => ({
    item,
    g: String(item[groupBy] ?? item.brand_name ?? 'Other'),
    go: groupOrderBy != null ? Number(item[groupOrderBy] ?? 9999) : 9999,
    id: item.color?.id ?? item.id ?? item.hex6 ?? i,
  }));
  withGroup.sort((a, b) => (a.go - b.go) || a.g.localeCompare(b.g));

  const counts = {};
  for (const r of withGroup) counts[r.g] = (counts[r.g] || 0) + 1;

  const out = [];
  let prev = null;
  for (let i = 0; i < withGroup.length; i++) {
    const r = withGroup[i];
    if (r.g !== prev) {
      prev = r.g;
      out.push({ kind: 'header', key: `hdr-${i}-${r.g}`, label: r.g, count: counts[r.g] });
    }
    out.push({ kind: 'item', key: `it-${r.g}-${r.id}`, item: r.item });
  }

  return (
    <div className={`sg-root ${className}`}>
      {!withGroup.length ? (
        <div className="sg-empty">{emptyMessage}</div>
      ) : (
        <div className="sg-grid sg-auto-fit" style={vars}>
          {out.map((node) =>
            node.kind === 'header' ? (
              <div className="sg-group-header" key={node.key} style={{ gridColumn: '1 / -1' }}>
                {groupHeaderRenderer
                  ? groupHeaderRenderer(node.label, node.count)
                  : (
                    <div className="sg-group-line">
                      <span className="sg-group-title">{node.label}</span>
                      <span className="sg-group-count">({node.count})</span>
                    </div>
                  )}
              </div>
            ) : (
              <div className="sg-item" key={node.key}>
                <div className="sg-card-shell">
                  <SwatchComponent {...makeSwatchProps(node.item)} />
                </div>
              </div>
            )
          )}
        </div>
      )}
    </div>
  );
}
