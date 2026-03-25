import { useCallback, useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./photo-picker-modal.css";

export default function PhotoPickerModal({
  open = false,
  title = "Pick Photo",
  sourceType = "",
  onClose,
  onPick,
}) {
  const QUERY_KEY = "photo-picker:last-query";
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [cacheNonce, setCacheNonce] = useState(() => String(Date.now()));
  const inputRef = useRef(null);

  const withPickerNonce = useCallback((url) => {
    const value = String(url || "").trim();
    if (!value) return "";
    const sep = value.includes("?") ? "&" : "?";
    return `${value}${sep}picker=${cacheNonce}`;
  }, [cacheNonce]);

  useEffect(() => {
    if (!open) {
      setItems([]);
      setError("");
      setLoading(false);
      return;
    }
    setCacheNonce(String(Date.now()));
    try {
      const stored = window.sessionStorage.getItem(QUERY_KEY);
      if (stored) setQuery(stored);
    } catch {
      /* ignore */
    }
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    return () => clearTimeout(t);
  }, [open]);

  useEffect(() => {
    try {
      window.sessionStorage.setItem(QUERY_KEY, query);
    } catch {
      /* ignore */
    }
  }, [query]);

  const listUrl = `${API_FOLDER}/v2/admin/photo-library/list.php`;

  const runSearch = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (query.trim()) params.set("q", query.trim());
      if (sourceType) params.set("source_type", sourceType);
      params.set("limit", "200");
      params.set("_", String(Date.now()));
      const res = await fetch(`${listUrl}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Search failed");
      setItems(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setItems([]);
      setError(err?.message || "Search failed");
    } finally {
      setLoading(false);
    }
  }, [listUrl, query, sourceType]);

  useEffect(() => {
    if (!open) return;
  }, [open]);

  if (!open) return null;

  return (
    <div className="ppm-overlay" role="dialog" aria-modal="true">
      <div className="ppm-backdrop" onClick={onClose} />
      <div className="ppm-panel">
        <div className="ppm-header">
          <div className="ppm-title">{title}</div>
          <button type="button" className="ppm-close" onClick={onClose}>
            Close
          </button>
        </div>
        <form
          className="ppm-search"
          onSubmit={(e) => {
            e.preventDefault();
            runSearch();
          }}
        >
          <label className="ppm-label">Search (title or tags)</label>
          <div className="ppm-search-row">
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="e.g., door, cottage, adobe"
              ref={inputRef}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  runSearch();
                }
              }}
            />
            <button type="submit" className="ppm-search-btn">
              Search
            </button>
            <button
              type="button"
              className="ppm-clear-btn"
              onClick={() => { setQuery(""); setItems([]); setError(""); inputRef.current?.focus(); }}
            >
              Clear
            </button>
          </div>
        </form>
        {loading && <div className="ppm-status">Loading…</div>}
        {error && <div className="ppm-status error">{error}</div>}
        {!loading && !error && !query.trim() && (
          <div className="ppm-status">Enter a search term, then press Enter or click Search.</div>
        )}
        {!loading && !error && items.length === 0 && (
          <div className="ppm-status">No photos matched.</div>
        )}
        <div className="ppm-grid">
          {items.map((item) => (
            <button
              key={item.photo_library_id}
              type="button"
              className="ppm-card"
              onClick={() => onPick && onPick({
                photo_library_id: item.photo_library_id,
                image_url: withPickerNonce(item.image_url || item.rel_path || ""),
                title: item.title || "",
                tags: item.tags || "",
              })}
            >
              <div className="ppm-thumb">
                {(item.image_url || item.rel_path) ? (
                  <img src={withPickerNonce(item.image_url || item.rel_path)} alt="" loading="lazy" />
                ) : (
                  <div className="ppm-thumb-placeholder">No preview</div>
                )}
              </div>
              <div className="ppm-meta">
                <div className="ppm-name">{item.title || "Untitled"}</div>
                <div className="ppm-sub">#{item.photo_library_id}</div>
              </div>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
