// src/components/MiniBrandIcon.jsx
import { useMemo } from 'react';
import { useAppState } from '@context/AppStateContext';
import './brandfilter.css';

export default function MiniBrandIcon({ onClick }) {
  const { searchFilters } = useAppState();

  const hasActive = useMemo(() => {
    const b = searchFilters?.brands;
    if (!b) return false;
    return Array.isArray(b) ? b.length > 0 : (b instanceof Set ? b.size > 0 : !!b);
  }, [searchFilters]);

  const BOX  = 26;   // hitbox
const ICON = 20;   // svg size
const color = hasActive ? '#ff8c00' : '#fff';

return (
  <div
    role="button"
    tabIndex={0}
    onClick={onClick}
    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick?.(e); } }}
    aria-label="Filter by brand"
    title="Filter by brand"
    style={{
      width: BOX,
      height: BOX,
      display: 'grid',         // ← grid centers the child
      placeItems: 'center',    // ← perfectly centered
      overflow: 'hidden',
      background: 'transparent',
      border: 0,
      padding: 0,
      margin: 0,
      lineHeight: 0,
      color,                   // currentColor drives the SVG
    }}
  >
    <svg
      viewBox="0 0 24 24"
      width={ICON}
      height={ICON}
      aria-hidden="true"
      style={{ display: 'block' /* optional: transform: 'translateY(-1px)' for a tiny nudge */ }}
    >
      <path
        d="M3 5h18l-7 8v5l-4 2v-7L3 5z"
        fill={hasActive ? 'currentColor' : 'none'}
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
    </svg>
  </div>
);
}
