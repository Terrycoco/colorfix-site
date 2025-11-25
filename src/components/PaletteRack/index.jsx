import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./paletterack.css";

const ROLE_ORDER = ["body", "trim", "accent"];

export default function PaletteRack({
  open,
  tags = [],
  onClose,
  onApply,
  onInspect,
}) {
  const [search, setSearch] = useState("");
  const [favOnly, setFavOnly] = useState(false);
  const [bodyFamily, setBodyFamily] = useState("all");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [items, setItems] = useState([]);
  const [families, setFamilies] = useState([]);

  const normalizedTags = useMemo(
    () => (Array.isArray(tags) ? tags.filter(Boolean) : []),
    [tags]
  );

  useEffect(() => {
    if (!open) return;
    fetchPalettes();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, search, favOnly, bodyFamily, normalizedTags.join("|")]);

  async function fetchPalettes() {
    setLoading(true);
    setError("");
    try {
      const payload = {
        tags: normalizedTags,
        q: search || undefined,
        favorites_only: favOnly ? 1 : 0,
        body_family: bodyFamily !== "all" ? bodyFamily : undefined,
        limit: 40,
      };
      const res = await fetch(`${API_FOLDER}/v2/admin/palette-rack.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || "Failed to load palettes");
      setItems(data.items || []);
      setFamilies(data.body_families || []);
    } catch (err) {
      setError(err?.message || "Failed to load palettes");
    } finally {
      setLoading(false);
    }
  }

  function handleApply(palette) {
    onApply && onApply(palette);
  }

  function handleInspect(e, palette) {
    e.stopPropagation();
    onInspect && onInspect(palette);
  }

  return (
    <>
    <div
      className={`palette-rack-overlay ${open ? "open" : ""}`}
      onClick={onClose}
    />
    <div className={`palette-rack ${open ? "open" : ""}`} aria-hidden={!open}>
      <div className="rack-header">
        <div>
          <div className="rack-title">Palette Rack</div>
          {normalizedTags.length > 0 && (
            <div className="rack-sub">
              Matching tags: {normalizedTags.join(", ")}
            </div>
          )}
        </div>
        <button className="rack-close" onClick={onClose} aria-label="Close palette rack">
          ✕
        </button>
      </div>

      <div className="rack-controls">
        <input
          type="text"
          placeholder="Nickname or tag"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select value={bodyFamily} onChange={(e) => setBodyFamily(e.target.value)}>
          <option value="all">All bodies</option>
          {families.map((fam) => (
            <option key={fam} value={fam}>{fam}</option>
          ))}
        </select>
        <label className="fav-toggle">
          <input
            type="checkbox"
            checked={favOnly}
            onChange={(e) => setFavOnly(e.target.checked)}
          />
          Favorites
        </label>
      </div>

      {loading && <div className="rack-status">Loading palettes…</div>}
      {error && <div className="rack-error">{error}</div>}

      <div className="rack-list">
        {!loading && !items.length && (
          <div className="rack-status">No palettes found.</div>
        )}
        {items.map((item) => (
          <button
            type="button"
            className="rack-row"
            key={item.palette_id}
            onClick={() => handleApply(item)}
          >
            <div className="rack-swatches">
              {ROLE_ORDER.map((role) => {
                const data = item.roles?.[role];
                const hex = data?.hex6 ? `#${data.hex6}` : "#DDD";
                return (
                  <span
                    key={role}
                    className="rack-chip"
                    style={{ backgroundColor: hex }}
                    title={`${role}: ${data?.name || "n/a"}`}
                  />
                );
              })}
            </div>
            <div className="rack-meta">
              <div className="rack-name">
                {item.nickname || `Palette #${item.palette_id}`}
                {item.terry_fav ? <span className="rack-badge">★</span> : null}
                <button
                  type="button"
                  className="rack-edit"
                  onClick={(e) => handleInspect(e, item)}
                  title="Edit meta"
                >
                  ✎
                </button>
              </div>
              <div className="rack-tags">
                <span className="rack-tag">{item.body_family || "unclassified"}</span>
                {item.tag_hits > 0 && (
                  <span className="rack-tag match">{item.tag_hits} tag match</span>
                )}
                {(item.tags || []).slice(0, 3).map((tag) => (
                  <span key={tag} className="rack-tag ghost">{tag}</span>
                ))}
              </div>
            </div>
          </button>
        ))}
      </div>
    </div>
    </>
  );
}
