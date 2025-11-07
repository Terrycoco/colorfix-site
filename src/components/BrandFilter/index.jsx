import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { useAppState } from '@context/AppStateContext';
import './brandfilter.css';

/** Master brand list (codes you store → labels you show). Edit as needed. */
const BRANDS = [
  { code: 'behr',   label: 'Behr' },
  { code: 'bm',     label: 'Benjamin Moore' },
  { code: 'de',     label: 'Dunn Edwards' },
  { code: 'fb',    label: 'Farrow & Ball' },
  { code: 'ppg',    label: 'PPG' },
  { code: 'sw',     label: 'Sherwin-Williams' },
  { code: 'vs',     label: 'Valspar' },
  { code: 'vist',  label: 'Vista Paint' },
];

export default function BrandFilterModal({ open, onClose }) {
  // Always return before hooks when closed (prevents hooks-order errors)
  if (!open) return null;

  const { searchFilters, toggleFilter } = useAppState();

  const backdropRef = useRef(null);
  const armRef = useRef(false); // prevent instant close from opener tap

  // Normalize active selections from AppState to a Set of codes
  const activeSet =
    searchFilters?.brands instanceof Set
      ? searchFilters.brands
      : Array.isArray(searchFilters?.brands)
      ? new Set(searchFilters.brands.filter(Boolean))
      : new Set();

  // Sort our hardcoded brands alphabetically by LABEL for display
  const list = [...BRANDS].sort((a, b) =>
    a.label.localeCompare(b.label, undefined, { sensitivity: 'base' })
  );

  function handleToggle(code) {
    // Toggle the CODE in AppState (unchanged behavior)
    toggleFilter?.('brands', code);
  }

  function handleClear() {
    const visibleCodes = new Set(list.map(b => b.code));
    for (const code of activeSet) {
      if (visibleCodes.has(code)) toggleFilter?.('brands', code); // turn off
    }
  }

  // Arm backdrop close slightly after mount to avoid "open→close" on mobile
  useEffect(() => {
    armRef.current = false;
    const t = setTimeout(() => { armRef.current = true; }, 200);
    return () => clearTimeout(t);
  }, []);

  function handleBackdropClick(e) {
    if (!armRef.current) return;
    if (e.target === e.currentTarget) onClose?.();
  }

  // ESC closes
  useEffect(() => {
    const onEsc = (e) => { if (e.key === 'Escape') onClose?.(); };
    window.addEventListener('keydown', onEsc, { passive: true });
    return () => window.removeEventListener('keydown', onEsc);
  }, [onClose]);

  return createPortal(
    <div className="bf-backdrop" ref={backdropRef} onClick={handleBackdropClick}>
      <div className="bf-modal" role="dialog" aria-modal="true" aria-label="Filter by brand">
        <header className="bf-header">
          <h3 className="bf-title">Filter by brand</h3>
        </header>

        <div className="bf-body">
          <ul className="bf-list" role="listbox" aria-label="Brands">
            {list.map(({ code, label }) => {
              const checked = activeSet.has(code);
              return (
                <li key={code} className="bf-item">
                  <label className={`bf-row ${checked ? 'is-checked' : ''}`}>
                    <input
                      className="bf-checkbox"
                      type="checkbox"
                      checked={checked}
                      onChange={() => handleToggle(code)}
                    />
                    <span className="bf-brand">{label}</span>
                  </label>
                </li>
              );
            })}
          </ul>
        </div>

        <footer className="bf-footer">
          <button className="bf-btn ghost" type="button" onClick={handleClear}>Clear</button>
          <button className="bf-btn" type="button" onClick={onClose}>Done</button>
        </footer>
      </div>
    </div>,
    document.body
  );
}
