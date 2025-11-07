import React, { useEffect, useState, useRef } from "react";
import { API_FOLDER } from "@helpers/config";
import './tagmultiselect.css';

/**
 * TagMultiSelect
 * Props:
 * - selected (string[])
 * - onChange(newTags)
 * - placeholder? (default: "Search tags…")
 */
export default function TagMultiSelect({ selected = [], onChange, placeholder = "Search tags…" }) {
  const [query, setQuery] = useState("");
  const [suggestions, setSuggestions] = useState([]);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const timerRef = useRef(null);

  // Debounced fetch
  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    if (query.trim() === "") {
      setSuggestions([]);
      return;
    }

    timerRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const res = await fetch(`${API_FOLDER}/v2/palette-tag-search.php?q=${encodeURIComponent(query)}&limit=20`);
        const json = await res.json();
        setSuggestions(json.items || []);
        setOpen(true);
      } catch {
        setSuggestions([]);
      } finally {
        setLoading(false);
      }
    }, 250);

    return () => clearTimeout(timerRef.current);
  }, [query]);

  function toggleTag(tag) {
    const next = selected.includes(tag)
      ? selected.filter((t) => t !== tag)
      : [...selected, tag];
    onChange(next);
  }

  return (
    <div className="tag-select">
      <input
        type="text"
        className="bpv1-select"
        placeholder={placeholder}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onFocus={() => setOpen(true)}
      />
      {loading && <div className="tag-loading">Loading…</div>}

      {open && suggestions.length > 0 && (
        <div className="tag-dropdown">
          {suggestions.map((s) => (
            <div
              key={s.tag}
              className={`tag-suggestion ${selected.includes(s.tag) ? "is-selected" : ""}`}
              onClick={() => toggleTag(s.tag)}
            >
              <span>{s.tag}</span>
              {s.count > 0 && <small>{s.count}</small>}
            </div>
          ))}
        </div>
      )}

      {/* Selected chips */}
      {selected.length > 0 && (
        <div className="tag-chips">
          {selected.map((t) => (
            <span key={t} className="tag-chip" onClick={() => toggleTag(t)}>
              {t} ×
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
