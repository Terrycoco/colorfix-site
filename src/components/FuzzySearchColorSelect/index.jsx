import { useState, useEffect, useRef } from 'react';
import { API_FOLDER } from '@helpers/config';
import './fuzzysearch.css';

function useIOSNoZoomOnFocus(inputRef) {
  useEffect(() => {
    const vp = document.querySelector('meta[name="viewport"]');
    if (!vp) return;
    const base = "width=device-width, initial-scale=1, viewport-fit=cover";
    const onFocus = () => vp.setAttribute("content", base + ", maximum-scale=1");
    const onBlur  = () => vp.setAttribute("content", base);
    const el = inputRef?.current;
    if (!el) return;
    el.addEventListener("focus", onFocus, { passive: true });
    el.addEventListener("blur", onBlur, { passive: true });
    return () => {
      el.removeEventListener("focus", onFocus);
      el.removeEventListener("blur", onBlur);
    };
  }, [inputRef]);
}

export default function FuzzySearchColorSelect({
  onSelect,
  onFocus,
  onEmpty,
  className,
  label,
  value,
  ghostValue,
  autoFocus = true,
  preventAutoFocus = false,
  suppressFocus = false,
  manualOpen = false,
  mobileBreakpoint = 768,
  showLabel = true,
  compact = false,
}) {
  const initialText = () => (value && (value.name || value.code || value.hex6 || value.hex || '')) || '';
  const [query, setQuery] = useState(initialText);
  const [results, setResults] = useState([]);
  const [highlightedIndex, setHighlightedIndex] = useState(-1);
  const [open, setOpen] = useState(false);
  const [selectedColor, setSelectedColor] = useState(value || null);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia(`(max-width: ${mobileBreakpoint}px)`).matches;
  });

  const rootRef = useRef(null);
  const inputRef = useRef(null);
  const sheetInputRef = useRef(null);
  const pickingRef = useRef(false);
  const reqSeq = useRef(0);
  const suppressNextSearchRef = useRef(false);

  useIOSNoZoomOnFocus(inputRef);

  const isControlled = typeof value !== "undefined";
  const lastValueRef = useRef(value);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const mq = window.matchMedia(`(max-width: ${mobileBreakpoint}px)`);
    const handler = (e) => setIsMobile(e.matches);
    handler(mq);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, [mobileBreakpoint]);

  // 🔸 Auto-focus on mount ONLY if there's no initial value
  useEffect(() => {
    if (!autoFocus || isMobile || preventAutoFocus) return;
    const hasInitial = !!(value && value.id);
    if (!hasInitial) inputRef.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoFocus, isMobile, preventAutoFocus]); // run once per autoFocus flag

  // If we explicitly want to suppress focus (e.g., tables), blur on mount.
  useEffect(() => {
    if (!suppressFocus && !manualOpen) return;
    const id = setTimeout(() => {
      setOpen(false);
      inputRef.current?.blur();
    }, 0);
    return () => clearTimeout(id);
  }, [suppressFocus, manualOpen]);

  // 🔸 Sync with parent value; close/blur if a value is set, focus if cleared
  useEffect(() => {
    if (!isControlled) return;
    if (lastValueRef.current === value) return;
    lastValueRef.current = value;

    if (value && value.id) {
      setSelectedColor(value);
      setQuery(value.name || value.code || '');
      setOpen(false);
      setResults([]);
      setHighlightedIndex(-1);
      suppressNextSearchRef.current = true;
      inputRef.current?.blur();     // close dropdown visually
    } else {
      setSelectedColor(null);
      setQuery('');
      setOpen(false);
      setResults([]);
      setHighlightedIndex(-1);
      if (autoFocus && !isMobile) {
        // focus when cleared so you can type immediately
        inputRef.current?.focus();
      }
    }
  }, [isControlled, value, autoFocus, isMobile]);

  useEffect(() => {
    function onDocPointerDown(e) {
      if (!rootRef.current) return;
      if (rootRef.current.contains(e.target)) return;
      if (pickingRef.current) return;
      setOpen(false);
    }
    document.addEventListener('pointerdown', onDocPointerDown, { capture: true });
    return () => document.removeEventListener('pointerdown', onDocPointerDown, { capture: true });
  }, []);

function getFontColor(color) {
  const lightness = color?.lightness ?? color?.hcl_l ?? color?.lab_l ?? hexLightness(color?.hex6 || color?.hex);
  if (typeof lightness === "number" && !Number.isNaN(lightness)) {
    return lightness > 70 ? "black" : "white";
  }
  return "white";
}

function colorToHex(color) {
  if (!color) return null;
  if (color.hex6) return `#${color.hex6}`;
  if (color.hex) return color.hex.startsWith('#') ? color.hex : `#${color.hex}`;
  if (typeof color.r === 'number' && typeof color.g === 'number' && typeof color.b === 'number') {
      return `rgb(${color.r}, ${color.g}, ${color.b})`;
    }
    return null;
  }

function pick(color) {
    if (!color) return;
    pickingRef.current = true;
    try {
      onSelect && onSelect(color);
      if (!isControlled) {
        setSelectedColor(color);
        setQuery(color.name || color.code || '');
      }
      suppressNextSearchRef.current = true;
    } finally {
      setResults([]);
      setHighlightedIndex(-1);
      setOpen(false);
      if (isMobile) {
        setSheetOpen(false);
        sheetInputRef.current?.blur();
      } else {
        inputRef.current?.blur();
      }
      setTimeout(() => { pickingRef.current = false; }, 0);
    }
  }

  function runSearch(text) {
    const q = (text || '').trim();
    if (q.length < 2) {
      setResults([]);
      setOpen(false);
      setHighlightedIndex(-1);
      return;
    }
    const seq = ++reqSeq.current;
    const url = `${API_FOLDER}/v2/fuzzy-search.php?q=${encodeURIComponent(q)}&ts=${Date.now()}`;
    fetch(url)
      .then(r => r.text())
      .then(txt => {
        if (seq !== reqSeq.current) return;
        let data;
        try { data = JSON.parse(txt); } catch { data = []; }
        const raw = Array.isArray(data)
          ? data
          : (Array.isArray(data?.results) ? data.results
            : (Array.isArray(data?.items) ? data.items : []));
        const arr = raw.filter(r => r && (r.name || r.code));
        setResults(arr);
        setHighlightedIndex(-1);
        setOpen(arr.length > 0);
      })
      .catch(() => {
        if (seq !== reqSeq.current) return;
        setResults([]);
        setOpen(false);
        setHighlightedIndex(-1);
      });
  }

  useEffect(() => {
    if (suppressNextSearchRef.current) {
      suppressNextSearchRef.current = false;
      return;
    }
    const t = setTimeout(() => runSearch(query), 220);
    return () => clearTimeout(t);
  }, [query]);

  function handleKeyDown(e) {
    if (open && results.length > 0) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setHighlightedIndex(i => Math.min(i + 1, results.length - 1));
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setHighlightedIndex(i => Math.max(i - 1, -1));
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightedIndex >= 0 && results[highlightedIndex]) {
          pick(results[highlightedIndex]);
        }
        return;
      }
      if (e.key === 'Escape') {
        setOpen(false);
        setHighlightedIndex(-1);
        return;
      }
    }
    if (e.key === 'Enter' && (!open || results.length === 0)) {
      e.preventDefault();
      if (window.matchMedia?.('(pointer: coarse)').matches) {
        inputRef.current?.blur();
      }
    }
  }

  function clearSelection() {
    if (!isControlled) {
      if (selectedColor) setSelectedColor(null);
      setQuery('');
    }
    onSelect && onSelect(null);
    setResults([]);
    setHighlightedIndex(-1);
    setOpen(false);
    inputRef.current?.focus();
  }

  const displayColor = selectedColor || ghostValue || null;
  const placeholderText =
    !selectedColor && ghostValue
      ? `inherits ${ghostValue.name || ghostValue.code || "role color"}`
      : "Color name or code";


  useEffect(() => {
    if (sheetOpen && isMobile) {
      document.body.style.overflow = 'hidden';
      setTimeout(() => sheetInputRef.current?.focus(), 60);
    } else {
      document.body.style.overflow = '';
    }
    return () => { document.body.style.overflow = ''; };
  }, [sheetOpen, isMobile]);

  return (
    <div
      ref={rootRef}
      className={`fuzzy-dropdown ${className || ''} ${compact ? "fuzzy-compact" : ""}`}
      onMouseDownCapture={(e) => e.stopPropagation()}
      onClickCapture={(e) => e.stopPropagation()}
      onKeyDownCapture={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
      style={className?.includes("full-width") ? { width: "100%" } : undefined}
    >
      {showLabel && (
        <label className="dropdown-label">
          {label ? label : "Enter A Color"}
        </label>
      )}

      {isMobile ? (
        <button
          type="button"
          className="fuzzy-mobile-trigger"
          onClick={() => setSheetOpen(true)}
          style={{
            backgroundColor: colorToHex(displayColor) || '#f7f7f7',
            color: displayColor ? getFontColor(displayColor) : '#222',
          }}
        >
          {selectedColor
            ? `${selectedColor.name} (${selectedColor.brand})`
            : "Tap to choose a color"}
        </button>
      ) : (
        <div className="fuzzy-input-wrap">
          <input
            type="search"
            inputMode="search"
            enterKeyHint="done"
            value={query}
            autoComplete="off"
            ref={inputRef}
            tabIndex={suppressFocus ? -1 : undefined}
            onFocus={(e) => {
              if (suppressFocus || manualOpen) { e.target.blur(); return; }
              onFocus && onFocus(e);
              if (!selectedColor && results.length > 0) setOpen(true);
              const q = query.trim();
              if (!selectedColor && q.length >= 2 && results.length === 0) runSearch(q);
            }}
            onChange={(e) => {
              const val = e.target.value;
              setQuery(val);
              if (val.trim().length === 0 && selectedColor) {
                setSelectedColor(null);
                onSelect && onSelect(null);
              }
              if (val.trim().length >= 2) setOpen(true);
            }}
            onMouseDown={(e) => {
              if (!manualOpen) return;
              e.preventDefault();
              const val = query.trim();
              if (val.length >= 2) runSearch(val);
              setOpen(true);
            }}
            onClick={(e) => {
              if (!manualOpen) return;
              e.preventDefault();
              const val = query.trim();
              if (val.length >= 2) runSearch(val);
              setOpen(true);
            }}
            onInput={(e) => {
              const val = e.currentTarget.value || '';
              if (val.length === 0 && selectedColor) {
                setSelectedColor(null);
                onSelect && onSelect(null);
              }
            }}
            placeholder={placeholderText}
            onKeyDown={handleKeyDown}
            onBlur={() => {
              setOpen(false);
              if (!selectedColor && query.trim().length === 0) {
                onEmpty && onEmpty();
              }
            }}
            aria-autocomplete="list"
            aria-expanded={open}
            aria-controls="fuzzy-results"
            formNoValidate
            style={{
              backgroundColor: colorToHex(displayColor) || '#fff',
              color: displayColor ? getFontColor(displayColor) : '#000',
            }}
          />

          {(query.length > 0 || selectedColor || ghostValue) && (
            <button
              type="button"
              className="fuzzy-clear-btn"
              aria-label="Clear color"
              onPointerDown={(e) => {
                e.preventDefault();
                clearSelection();
              }}
              onClick={(e) => {
                e.preventDefault();
                clearSelection();
              }}
              style={{
                color: displayColor ? getFontColor(displayColor) : '#000',
              }}
            >
              <span className="fuzzy-clear-icon" aria-hidden="true">×</span>
            </button>
          )}
        </div>
      )}

      {open && results.length > 0 && !isMobile && (
        <ul id="fuzzy-results" className="results" role="listbox">
          {results.map((c, index) => (
            <li
              key={c.id ?? `${c.name}-${index}`}
              className={`result-item ${index === highlightedIndex ? 'highlighted' : ''}`}
              onMouseEnter={() => setHighlightedIndex(index)}
              onPointerDown={(e) => { e.preventDefault(); e.stopPropagation(); pick(c); }}
              onClick={(e) => { e.preventDefault(); e.stopPropagation(); pick(c); }}
              role="option"
              aria-selected={index === highlightedIndex}
              tabIndex={-1}
            >
              <div
                className="swatch"
                style={{
                  backgroundColor: c.hex6 ? `#${c.hex6}` : `rgb(${c.r ?? 0}, ${c.g ?? 0}, ${c.b ?? 0})`
                }}
              />
              <span className="color-name">{c.name}</span>
              <span className="color-brand">({c.brand})</span>
            </li>
          ))}
        </ul>
      )}

      {isMobile && sheetOpen && (
        <>
          <div className="fuzzy-sheet">
            <div className="fuzzy-sheet-header">
              <h3>Choose a Color</h3>
              <button type="button" onClick={() => setSheetOpen(false)} aria-label="Close color picker">
                ✕
              </button>
            </div>
            <div className="fuzzy-sheet-body">
              <input
                ref={sheetInputRef}
                type="search"
                inputMode="search"
                enterKeyHint="search"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Color name or code"
                className="fuzzy-sheet-input"
              />
              <div className="fuzzy-sheet-results">
                {results.map((c) => (
                  <button
                    key={c.id}
                    type="button"
                    className="fuzzy-sheet-item"
                    onClick={() => pick(c)}
                  >
                    <span
                      className="sheet-swatch"
                      style={{
                        backgroundColor: c.hex6 ? `#${c.hex6}` : `rgb(${c.r ?? 0}, ${c.g ?? 0}, ${c.b ?? 0})`
                      }}
                    />
                    <span className="sheet-name">{c.name}</span>
                    <span className="sheet-brand">{c.brand}</span>
                  </button>
                ))}
                {results.length === 0 && query.trim().length >= 2 && (
                  <div className="fuzzy-sheet-empty">No colors matched.</div>
                )}
              </div>
            </div>
          </div>
          <div className="fuzzy-sheet-backdrop" onClick={() => setSheetOpen(false)} />
        </>
      )}
    </div>
  );
}

function hexLightness(hex) {
  if (!hex) return null;
  const clean = hex.toString().replace(/[^0-9a-f]/gi, '');
  if (clean.length !== 6) return null;
  const r = parseInt(clean.slice(0, 2), 16) / 255;
  const g = parseInt(clean.slice(2, 4), 16) / 255;
  const b = parseInt(clean.slice(4, 6), 16) / 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2; // 0-1
  return l * 100;
}
