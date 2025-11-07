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

export default function FuzzySearchColorSelect({ onSelect, onFocus, className, label }) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [highlightedIndex, setHighlightedIndex] = useState(-1);
  const [open, setOpen] = useState(false);
  const [selectedColor, setSelectedColor] = useState(null);

  const rootRef = useRef(null);
  const inputRef = useRef(null);
  const pickingRef = useRef(false);
  const reqSeq = useRef(0);
  const suppressNextSearchRef = useRef(false);

  useIOSNoZoomOnFocus(inputRef);

  useEffect(() => {
    if (inputRef.current) inputRef.current.focus();
    setSelectedColor(null);
  }, []);

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
    return color?.hcl_l > 70 ? "black" : "white";
    // (fallback handled by style below when no selectedColor)
  }

  function pick(color) {
    if (!color) return;
    pickingRef.current = true;
    try {
      onSelect && onSelect(color);
      setSelectedColor(color);
      suppressNextSearchRef.current = true;
      setQuery(color.name || '');
    } finally {
      setResults([]);
      setHighlightedIndex(-1);
      setOpen(false);
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
    const v1 = `${API_FOLDER}/search-colors-fuzzy.php?q=${encodeURIComponent(q)}&ts=${Date.now()}`;
    const v2 = `${API_FOLDER}/v2/fuzzy-search.php?q=${encodeURIComponent(q)}&ts=${Date.now()}`
    const url = v2;
    fetch(url)
  .then(r => r.text())
  .then(txt => {
    if (seq !== reqSeq.current) return;

    // Safely parse JSON (handles HTML/error pages too)
    let data;
    try { data = JSON.parse(txt); } catch { data = []; }

    // Accept shapes: raw array, {results: [...]}, {items: [...]}
    const raw = Array.isArray(data)
      ? data
      : (Array.isArray(data?.results) ? data.results
        : (Array.isArray(data?.items) ? data.items : []));

    // Filter out junk rows; keep ones that can render a name/code
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
    // When list is closed, prevent mobile "Next" jump; keep focus or blur intentionally
    if (e.key === 'Enter' && (!open || results.length === 0)) {
      e.preventDefault();
      // optional: blur on coarse pointers to avoid jumping focus
      if (window.matchMedia?.('(pointer: coarse)').matches) {
        inputRef.current?.blur();
      }
    }
  }

  function clearSelection() {
    if (selectedColor) {
      setSelectedColor(null);
      onSelect && onSelect(null);
    }
    setQuery('');
    setResults([]);
    setHighlightedIndex(-1);
    setOpen(false);
  }

  return (
    <div
      ref={rootRef}
      className={`fuzzy-dropdown ${className || ''}`}
      onKeyDownCapture={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
    >
      <label htmlFor="fuzzy-input" className="dropdown-label">{label ? label : "Enter A Color"}</label>

      <div className="fuzzy-input-wrap">
        <input
          id="fuzzy-input"
          type="search"
          inputMode="search"
          enterKeyHint="done"
          value={query}
          autoComplete="off"
          ref={inputRef}
          onFocus={(e) => {
            onFocus && onFocus(e);
            if (results.length > 0) setOpen(true);
            const q = query.trim();
            if (q.length >= 2 && results.length === 0) runSearch(q);
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
          onInput={(e) => {
            const val = e.currentTarget.value || '';
            if (val.length === 0 && selectedColor) {
              setSelectedColor(null);
              onSelect && onSelect(null);
            }
          }}
          placeholder="Enter a name or color code"
          onKeyDown={handleKeyDown}
          aria-autocomplete="list"
          aria-expanded={open}
          aria-controls="fuzzy-results"
          formNoValidate
          style={{
            backgroundColor: selectedColor?.hex6 ? `#${selectedColor.hex6}` : '#fff',
            color: selectedColor ? getFontColor(selectedColor) : '#000',
          }}
        />

        {/* Custom clear button — confined to right padding zone */}
        {query.length > 0 && (
          <button
            type="button"
            className="fuzzy-clear-btn"
            aria-label="Clear search"
            onPointerDown={(e) => {
              e.preventDefault();       // keep focus in input (iOS)
              clearSelection();
              inputRef.current?.focus();
            }}
            style={{
              color: selectedColor ? getFontColor(selectedColor) : '#000',
            }}
          >
            ×
          </button>
        )}
      </div>

      {open && results.length > 0 && (
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
    </div>
  );
}
