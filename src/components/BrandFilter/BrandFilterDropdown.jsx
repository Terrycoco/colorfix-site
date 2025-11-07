// src/components/BrandFilter/BrandFilterDropdown.jsx
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useAppState } from "@context/AppStateContext";
import './brandfilter.css';

const DEFAULT_BRANDS = [
  { code: "behr",    name: "Behr" },
  { code: "bm",      name: "Benjamin Moore" },
  { code: "de",      name: "Dunn Edwards" },
  { code: "fb",      name: "Farrow & Ball" },
  { code: "ppg",     name: "PPG" },
  { code: "sw",      name: "Sherwin Williams" },
  { code: "valspar", name: "Valspar" },
  { code: "vist",    name: "Vista Paints"}
];

export default function BrandFilterDropdown({
  anchorRef,          // ref to MiniBrandIcon wrapper
  open,
  onClose,
  brands = DEFAULT_BRANDS,
}) {
  const { searchFilters, setFilterValues, applyBrandFilters } = useAppState();

  // initial selection from app state
  const initial = useMemo(
    () => (Array.isArray(searchFilters?.brands) ? searchFilters.brands : []),
    [searchFilters?.brands]
  );

  // IMPORTANT: declare draft BEFORE anything uses it
  const [draft, setDraft] = useState(initial);

  // now safe to derive from draft
  const hasAny = draft.length > 0;

  // keep draft in sync when re-opening
  useEffect(() => {
    if (open) setDraft(initial);
  }, [open, initial]);

  function toggle(code) {
    const c = String(code || "").trim().toLowerCase();
    setDraft(prev => (prev.includes(c) ? prev.filter(x => x !== c) : [...prev, c]));
  }

  function apply() {
    setFilterValues("brands", draft);
    applyBrandFilters(draft);    
    onClose?.();
  }
  

  function clearAndClose() {
    setFilterValues("brands", []);
    onClose?.();
  }

  // position under anchor
  const panelRef = useRef(null);
  const [pos, setPos] = useState({ top: 0, left: 0, minWidth: 240 });

  useLayoutEffect(() => {
    if (!open || !anchorRef?.current) return;
    const r = anchorRef.current.getBoundingClientRect();
    setPos({
      top: r.bottom + window.scrollY + 8,
      left: Math.min(r.left + window.scrollX, window.scrollX + window.innerWidth - 320),
      minWidth: Math.max(240, r.width),
    });
  }, [open, anchorRef]);

  // close on outside click / Esc
  useEffect(() => {
    if (!open) return;
    const onDown = (e) => {
      if (panelRef.current?.contains(e.target)) return;
      if (anchorRef.current?.contains(e.target)) return;
      onClose?.();
    };
    const onKey = (e) => { if (e.key === "Escape") onClose?.(); };
    document.addEventListener("pointerdown", onDown, true);
    document.addEventListener("keydown", onKey, true);
    return () => {
      document.removeEventListener("pointerdown", onDown, true);
      document.removeEventListener("keydown", onKey, true);
    };
  }, [open, onClose, anchorRef]);

  if (!open) return null;

  return createPortal(
    <div
      ref={panelRef}
      className="bf-panel"
      role="dialog"
      aria-label="Filter by brand"
      style={{ top: pos.top, left: pos.left, minWidth: pos.minWidth }}
    >
      <div className="bf-header">Select one or more brands</div>
       <div className="bf-note">Click on funnel icon any time to set a Brand filter</div>
       <div className="bf-note">Funnel icon will show <span>ORANGE</span> when a filter is applied</div>
      <ul className="bf-list">
        {brands.map(b => {
          const checked = draft.includes(b.code);
          return (
            <li key={b.code} className={`bf-row ${checked ? "is-checked" : ""}`}>
              <label className="bf-label">
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={() => toggle(b.code)}
                />
                <span className="bf-name">{b.name}</span>
              </label>
            </li>
          );
        })}
      </ul>

      <div className="bf-actions">
         <button
            className="bf-btn ghost"
            onClick={hasAny ? clearAndClose : onClose}
            aria-label={hasAny ? 'Clear' : 'Close'}
        >
            {hasAny ? 'Clear' : 'Close'}
        </button>
        <button className="bf-btn primary"     disabled={!hasAny}  onClick={apply} >
          Apply
        </button>
      </div>
    </div>,
    document.body
  );
}
