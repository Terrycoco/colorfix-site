import React, { useEffect, useState } from "react";
import { savePaletteMeta } from "@helpers/savePaletteMeta";
import { getPaletteTags } from "@helpers/getPaletteTags"; // tiny helper that GETs /v2/get-palette-tags.php

function PaletteMetaEditor({ palette, onPatched, isAdmin = true }) {
  if (!isAdmin) return null;

  const meta = palette?.meta || {};

  // --- Meta fields ---
  const [nickname, setNickname]   = useState(meta.nickname || "");
  const [terrySays, setTerrySays] = useState(meta.terry_says || "");
  const [fav, setFav]             = useState(!!meta.terry_fav);

  // --- Tags ---
  const [tags, setTags]           = useState(Array.isArray(meta.tags) ? meta.tags : []);
  const [tagInput, setTagInput]   = useState("");
  const [loadingTags, setLoadingTags] = useState(false);

  // Lazy-load tags if not provided in meta
  useEffect(() => {
    let ignore = false;
    async function load() {
      if (!palette?.palette_id) return;
      if (Array.isArray(meta.tags)) return; // already have them
      setLoadingTags(true);
      try {
        const t = await getPaletteTags(palette.palette_id);
        if (!ignore) setTags(t || []);
      } catch {
        // optional: toast or ignore
      } finally {
        if (!ignore) setLoadingTags(false);
      }
    }
    load();
    return () => { ignore = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palette?.palette_id]);

  function addTagsFromInput() {
    const raw = tagInput.trim();
    if (!raw) return;
    const parts = raw.split(/[,\|]+/).map(s => s.trim()).filter(Boolean);
    if (!parts.length) return;
    const next = Array.from(new Set([...(tags || []), ...parts]));
    setTags(next);
    setTagInput("");
  }
  function removeTag(t) {
    setTags((prev) => (prev || []).filter(x => x !== t));
  }

  const [saving, setSaving] = useState(false);

  async function handleSave() {
    if (!palette?.palette_id) return;
    setSaving(true);
    try {
      await savePaletteMeta({
        palette_id: palette.palette_id,
        nickname,
        terry_says: terrySays,
        terry_fav: fav ? 1 : 0,
        tags, // ← send full set
      });
      onPatched?.({ nickname, terry_says: terrySays, terry_fav: fav ? 1 : 0, tags });
    } catch (e) {
      alert(e?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="panel pi-meta-editor" style={{ marginTop: 10 }}>
      <div style={{ display: "grid", gap: 10 }}>
        <label style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <input type="checkbox" checked={fav} onChange={(e)=>setFav(e.target.checked)} />
          <span>Terry’s favorite</span>
        </label>

        <label style={{ display: "grid", gap: 4 }}>
          <span style={{ fontSize: 12, opacity: 0.8 }}>Nickname</span>
          <input value={nickname} onChange={(e)=>setNickname(e.target.value)} />
        </label>

        <label style={{ display: "grid", gap: 4 }}>
          <span style={{ fontSize: 12, opacity: 0.8 }}>Terry says</span>
          <textarea rows={2} value={terrySays} onChange={(e)=>setTerrySays(e.target.value)} />
        </label>

        {/* Tags editor */}
        <div style={{ display: "grid", gap: 6 }}>
          <div style={{ fontSize: 12, opacity: 0.8 }}>
            {loadingTags ? "Loading tags…" : "Tags (comma or | to add)"}
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <input
              value={tagInput}
              onChange={(e)=>setTagInput(e.target.value)}
              onKeyDown={(e)=>{ if (e.key === "Enter") { e.preventDefault(); addTagsFromInput(); } }}
              placeholder="adobe, exteriors, cottage"
              style={{ flex: 1 }}
            />
            <button type="button" className="btn" onClick={addTagsFromInput}>Add</button>
          </div>

          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            {(tags || []).map((t) => (
              <span key={t} className="chip" style={{ padding:'2px 8px', border:'1px solid #ddd', borderRadius: 12 }}>
                {t}
                <button
                  type="button"
                  onClick={() => removeTag(t)}
                  aria-label={`Remove ${t}`}
                  style={{ marginLeft: 6, border:'none', background:'transparent', cursor:'pointer' }}
                  title="Remove tag"
                >
                  ×
                </button>
              </span>
            ))}
          </div>
        </div>

        <div className="actions">
          <button className="btn primary" onClick={handleSave} disabled={saving}>
            {saving ? "Saving…" : "Save"}
          </button>
        </div>
      </div>
    </div>
  );
}

export default PaletteMetaEditor;
