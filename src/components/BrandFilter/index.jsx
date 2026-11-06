import useOverlayLifecycle from "../../hooks/useOverlayLifecycle";
import { useEffect, useRef, useState } from 'react';
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

// Mount a fresh editing session on each open, without updating application filters.
export default function BrandFilterModal({ open, onClose }) {
  return open ? <BrandFilterEditor onClose={onClose} /> : null;
}

function brandCodes(brands) {
  const values = brands instanceof Set ? [...brands] : Array.isArray(brands) ? brands : [];
  return [...new Set(values.filter(Boolean).map(String))].sort();
}

function BrandFilterEditor({ onClose }) {
  const { searchFilters, applyBrandFilters } = useAppState();
  const [draft, setDraft] = useState(() => new Set(brandCodes(searchFilters?.brands)));
  const armRef = useRef(false); // prevent instant close from opener tap

  // Sort our hardcoded brands alphabetically by LABEL for display
  const list = [...BRANDS].sort((a, b) =>
    a.label.localeCompare(b.label, undefined, { sensitivity: 'base' })
  );

  function handleToggle(code) {
    setDraft((previous) => {
      const next = new Set(previous);
      if (next.has(code)) next.delete(code);
      else next.add(code);
      return next;
    });
  }

  function handleClear() {
    setDraft(new Set());
  }

  function handleDone() {
    const selectedBrands = brandCodes(draft);
    if (JSON.stringify(selectedBrands) !== JSON.stringify(brandCodes(searchFilters?.brands))) {
      applyBrandFilters?.(selectedBrands);
    }
    onClose?.();
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

  const overlayRef = useOverlayLifecycle({ layer: "dialog", onEscape: onClose });

  return createPortal(
    <div className="bf-backdrop" ref={overlayRef} onClick={handleBackdropClick}>
      <div className="bf-modal" role="dialog" aria-modal="true" aria-label="Filter by brand">
        <header className="bf-header">
          <h3 className="bf-title">Filter by brand</h3>
        </header>

        <div className="bf-body">
          <ul className="bf-list" role="listbox" aria-label="Brands">
            {list.map(({ code, label }) => {
              const checked = draft.has(code);
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
          <button className="bf-btn" type="button" onClick={handleDone}>Done</button>
        </footer>
      </div>
    </div>,
    document.body
  );
}
