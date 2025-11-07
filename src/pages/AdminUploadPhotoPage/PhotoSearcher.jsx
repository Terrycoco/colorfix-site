// src/components/PhotoSearcher/index.jsx
import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import './adminupload.css';

export default function PhotoSearcher({ onPickAsset, initialQuery = "" }) {
  const [searchQ, setSearchQ] = useState(initialQuery);
  const [searching, setSearching] = useState(false);
  const [searchRes, setSearchRes] = useState([]);

  async function runSearch(q) {
    setSearching(true);
    try {
      const params = new URLSearchParams();
      if (q) params.set("q", q.trim());
      // cache-buster
      params.set("_", String(Date.now()));

      const r = await fetch(
        `${API_FOLDER}/v2/admin/photo-search.php?` + params.toString(),
        { cache: "no-store" }
      );
      const j = await r.json();
      setSearchRes(Array.isArray(j.items) ? j.items : []);
    } catch {
      setSearchRes([]);
    } finally {
      setSearching(false);
    }
  }

  // Optional: auto-run if initialQuery provided
  useEffect(() => {
    if (initialQuery) runSearch(initialQuery);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialQuery]);

  return (
    <div className="aup-card" style={{ marginBottom: 16 }}>
      <div className="aup-card-title">Find Existing Asset (by tag, asset, or style)</div>

      <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
        <input
          className="aup-input"
          placeholder="Type a tag (e.g., black-shutters) or asset prefix (PHO_) or style…"
          value={searchQ}
          onChange={(e) => setSearchQ(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") runSearch(searchQ);
          }}
          style={{ flex: 1 }}
        />
        <button
          type="button"
          className="aup-button aup-button--secondary"
          onClick={() => runSearch(searchQ)}
          disabled={searching}
        >
          {searching ? "Searching…" : "Search"}
        </button>
      </div>

      {searchRes.length > 0 && (
        <div style={{ marginTop: 10 }}>
          <div className="aup-help">Click one to continue uploads into it.</div>
          <ul style={{ listStyle: "none", padding: 0, marginTop: 8 }}>
            {searchRes.map((it) => (
              <li
                key={it.asset_id}
                style={{
                  display: "flex",
                  gap: 8,
                  alignItems: "center",
                  padding: "6px 0",
                  borderBottom: "1px solid #3333",
                }}
              >
                <button
                  type="button"
                  className="aup-button aup-button--secondary"
                  onClick={() => onPickAsset && onPickAsset(it.asset_id)}
                >
                  Use
                </button>
                <div style={{ fontFamily: "monospace" }}>{it.asset_id}</div>
                <div className="aup-help">
                  {it.width ? `${it.width}×${it.height}` : "?"} · {it.variants} files
                  {it.style_primary ? ` · style: ${it.style_primary}` : ""}
                  {it.verdict ? ` · ${it.verdict}` : ""}
                  {it.status ? ` · ${it.status}` : ""}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
