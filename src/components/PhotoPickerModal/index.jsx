import { useCallback, useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { buildImageUrl } from "@helpers/assetImage";
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
  const [thumbNonce, setThumbNonce] = useState(() => String(Date.now()));
  const inputRef = useRef(null);
  const requestRef = useRef(null);

  useEffect(() => {
    if (!open) {
      requestRef.current?.abort();
      requestRef.current = null;
      setItems([]);
      setError("");
      setLoading(false);
      setThumbNonce(String(Date.now()));
      return;
    }
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

  const parseJsonResponse = useCallback(async (res) => {
    const text = await res.text();
    const contentType = String(res.headers.get("content-type") || "").toLowerCase();
    const looksLikeJson = contentType.includes("application/json");

    if (!text.trim()) {
      return {};
    }

    if (!looksLikeJson && text.trim().startsWith("<")) {
      throw new Error("Photo search returned HTML instead of JSON. The session may have expired.");
    }

    try {
      return JSON.parse(text);
    } catch {
      throw new Error("Photo search returned invalid JSON.");
    }
  }, []);

  const runSearch = useCallback(async () => {
    const cleanQuery = query.trim();
    if (!cleanQuery) {
      requestRef.current?.abort();
      requestRef.current = null;
      setItems([]);
      setLoading(false);
      setError("Enter a tag or search term first. Blank searches are disabled.");
      inputRef.current?.focus();
      return;
    }

    requestRef.current?.abort();
    const controller = new AbortController();
    requestRef.current = controller;
    setLoading(true);
    setError("");
    setThumbNonce(String(Date.now()));
    try {
      const params = new URLSearchParams();
      params.set("q", cleanQuery);
      if (sourceType) params.set("source_type", sourceType);
      params.set("limit", "200");
      params.set("_", String(Date.now()));
      const res = await fetch(`${listUrl}?${params.toString()}`, {
        credentials: "include",
        signal: controller.signal,
      });
      const data = await parseJsonResponse(res);
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Search failed");
      if (requestRef.current !== controller) return;
      setItems(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      if (err?.name === "AbortError") return;
      setItems([]);
      setError(err?.message || "Search failed");
    } finally {
      if (requestRef.current === controller) {
        requestRef.current = null;
        setLoading(false);
      }
    }
  }, [listUrl, parseJsonResponse, query, sourceType]);

  const buildPickerImageUrl = useCallback((url, updatedAt = null) => {
    const base = buildImageUrl(url, updatedAt);
    if (!base) return "";
    const sep = base.includes("?") ? "&" : "?";
    return `${base}${sep}picker=${thumbNonce}`;
  }, [thumbNonce]);

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
            />
            <button type="submit" className="ppm-search-btn" disabled={loading}>
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
                raw_rel_path: item.raw_rel_path || "",
                image_url: item.raw_rel_path || item.rel_path || item.image_url || "",
                title: item.title || "",
                tags: item.tags || "",
              })}
            >
              <div className="ppm-thumb">
                {(item.raw_rel_path || item.rel_path || item.image_url) ? (
                  <img
                    src={buildPickerImageUrl(
                      item.raw_rel_path || item.rel_path || item.image_url,
                      item.updated_at || null
                    )}
                    alt=""
                    loading="lazy"
                  />
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
