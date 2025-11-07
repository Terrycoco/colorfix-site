import React from "react";
import "./browse-palettes.css";

export default function PaletteRow({ palette, onClick }) {
  const members =
    palette.members ??
    (palette.member_cluster_ids || []).map((id) => ({
      cluster_id: id,
      rep_hex: null,
    }));

  const meta = palette.meta || {};
  const nickname = meta.nickname || null;
  const terryFav = Number(meta.terry_fav || 0) === 1;
  const terrySays = meta.terry_says ? String(meta.terry_says).trim() : "";
  const tags = Array.isArray(meta.tags) ? meta.tags : [];

  return (
    <div
      className="bpv1-chipcard"
      aria-label={`Palette, ${palette.size} colors`}
      onClick={() => onClick?.(palette)}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => { if (e.key === "Enter") onClick?.(palette); }}
    >
      <div className="bpv1-rowline">
        {/* Left: swatch strip */}
        <div className="bpv1-row">
          {members.map((m, i) => (
            <div
              key={m.cluster_id ?? i}
              className="bpv1-mini"
              style={m.rep_hex ? { background: m.rep_hex } : { background: "#eee" }}
              title={m.rep_hex || ""}
            />
          ))}
        </div>

        {/* Right: details */}
        <div className="bpv1-details">
          <div className="bpv1-titleline">
            {terryFav && (
              <span className="bpv1-heart" aria-label="Terry’s favorite" title="Terry’s favorite">♥</span>
            )}
            <span className="bpv1-nickname">
              {nickname ? nickname : `#${palette.palette_id ?? ""}`}
            </span>
            
          </div>

          {/* Optional “Terry says” line (fades if long; no label) */}
          {terrySays && <div className="bpv1-terry">Terry says: {terrySays}</div>}

          {/* Tag chips (no “Tags:” label) */}
          {tags.length > 0 && (
            <div className="bpv1-tags">
              {tags.map((t) => (
                <span key={t} className="bpv1-tagchip">{t}</span>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
