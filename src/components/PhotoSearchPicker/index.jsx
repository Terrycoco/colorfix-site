import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./photo-search-picker.css";

const DEFAULT_LIMIT = 24;
const SEARCH_URL = `${API_FOLDER}/v2/photos/search.php`;

function SearchBar({ initialQ = "", initialTags = "", onSearch }) {
  const [q, setQ] = useState(initialQ);
  const [tagsText, setTagsText] = useState(initialTags);

  useEffect(() => {
    setQ(initialQ);
    setTagsText(initialTags);
  }, [initialQ, initialTags]);

  function submit() {
    const nextTags = tagsText.trim();
    const nextQ = nextTags ? "" : q.trim();
    onSearch && onSearch({ q: nextQ, tagsText: nextTags });
  }

  return (
    <div className="photo-searchbar">
      <div className="psb-field">
        <label className="psb-label">Tags</label>
        <input
          className="psb-input"
          type="text"
          placeholder="comma or | separated (e.g., adobe,white)"
          value={tagsText}
          onChange={(e) => setTagsText(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>
      <div className="psb-actions">
        <button className="psb-btn psb-primary" onClick={submit}>Search</button>
        <button
          className="psb-btn"
          onClick={() => {
            setQ("");
            setTagsText("");
            onSearch && onSearch({ q: "", tagsText: "" });
          }}
        >
          Clear
        </button>
      </div>
    </div>
  );
}

function PhotoGrid({ items = [], onPick, emptyText = "No results" }) {
  if (!items.length) return <div className="photo-grid-empty">{emptyText}</div>;
  return (
    <div className="photo-grid">
      {items.map((item) => (
        <div
          key={item.asset_id}
          className="photo-card"
          onClick={() => onPick && onPick(item)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === "Enter") onPick && onPick(item); }}
        >
          <div className="photo-thumb-wrap">
            {item.thumb_url ? (
              <img className="photo-thumb" src={item.thumb_url} alt="" loading="lazy" />
            ) : (
              <div className="photo-thumb placeholder">No preview</div>
            )}
          </div>
          <div className="photo-meta">
            <div className="photo-title">{item.title || "Untitled"}</div>
            <div className="photo-tags">
              <span className="photo-tag">{item.asset_id}</span>
            </div>
            <div className="photo-tags">
              {(item.tags || []).map((t) => (
                <span key={t} className="photo-tag">{t}</span>
              ))}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

export default function PhotoSearchPicker({
  initialQ = "",
  initialTags = "",
  initialPage = 1,
  pageSize = DEFAULT_LIMIT,
  emptyText = "No photos matched.",
  autoSearch = true,
  onPick,
  onQueryChange,
}) {
  const [loading, setLoading] = useState(false);
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(initialPage);
  const [q, setQ] = useState(initialQ);
  const [tagsText, setTagsText] = useState(initialTags);
  const [error, setError] = useState("");

  useEffect(() => {
    setQ(initialQ);
    setTagsText(initialTags);
    setPage(initialPage);
    if (autoSearch && (initialQ || initialTags)) {
      doSearch({ q: initialQ, tagsText: initialTags }, initialPage);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialQ, initialTags, initialPage, autoSearch]);

  function doSearch({ q: nextQ, tagsText: nextTags }, nextPage = 1) {
    setLoading(true);
    setError("");

    const params = new URLSearchParams();
    if (nextQ) params.set("q", nextQ);
    if (nextTags) params.set("tags", nextTags);
    params.set("page", String(nextPage));
    params.set("limit", String(pageSize));
    params.set("_", String(Date.now()));

    fetch(`${SEARCH_URL}?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
      cache: "no-store",
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) {
          setError(data.error);
          setItems([]);
          setTotal(0);
          return;
        }
        const nextItems = (data.items || []).map((it) => ({
          ...it,
          thumb_url: it.thumb_url || "",
          _thumbLoading: false,
        }));
        const nextPageNum = data.page || nextPage;
        setItems(nextItems);
        setTotal(data.total || 0);
        setPage(nextPageNum);
        setQ(nextQ || "");
        setTagsText(nextTags || "");
        onQueryChange && onQueryChange({
          q: nextQ || "",
          tagsText: nextTags || "",
          page: nextPageNum,
          total: data.total || 0,
        });
      })
      .catch((e) => setError(e?.message || "Search failed"))
      .finally(() => setLoading(false));
  }

  const totalPages = Math.max(1, Math.ceil(total / pageSize));

  return (
    <div className="photo-search-picker">
      <SearchBar initialQ={q} initialTags={tagsText} onSearch={(payload) => doSearch(payload, 1)} />
      {loading && <div className="psp-status">Loading…</div>}
      {error && <div className="psp-status error">{error}</div>}
      <PhotoGrid
        items={items}
        onPick={(item) => onPick && onPick(item, { q, tagsText, page })}
        emptyText={emptyText}
      />
      {totalPages > 1 && (
        <div className="pager">
          <button
            className="psp-btn"
            disabled={page <= 1}
            onClick={() => doSearch({ q, tagsText }, page - 1)}
          >
            Prev
          </button>
          <div className="page-info">{page} / {totalPages}</div>
          <button
            className="psp-btn"
            disabled={page >= totalPages}
            onClick={() => doSearch({ q, tagsText }, page + 1)}
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
