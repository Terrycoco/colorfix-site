import { useState } from "react";

/**
 * PhotoSearchBar
 * Props:
 *  - initialQ?: string
 *  - initialTags?: string   // "adobe,white" or "adobe|white"
 *  - onSearch: ({ q, tagsText }) => void
 */
export default function PhotoSearchBar({ initialQ = "", initialTags = "", onSearch }) {
  const [q, setQ] = useState(initialQ);
  const [tagsText, setTagsText] = useState(initialTags);

  function submit() {
    if (onSearch) onSearch({ q: q.trim(), tagsText: tagsText.trim() });
  }

  return (
    <div className="photo-searchbar">
      <div className="psb-field">
        <label className="psb-label">Text</label>
        <input
          className="psb-input"
          type="text"
          placeholder="address, note, asset_id…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>
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
          onClick={() => { setQ(""); setTagsText(""); if (onSearch) onSearch({ q: "", tagsText: "" }); }}
        >Clear</button>
      </div>
    </div>
  );
}
