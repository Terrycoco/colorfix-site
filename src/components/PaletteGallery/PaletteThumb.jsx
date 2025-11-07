import React, { useMemo } from "react";
import "./palettegallery.css";

export default function PaletteThumb({
  id,
  name,
  hexes = "",
  palette,
  onClick
}) {
  // collapsed chips (prefer members for Brand·Name hover, else CSV hex)
  const chips = useMemo(() => {
    const members = Array.isArray(palette?.members) ? palette.members : [];
    if (members.length) {
      return members.map((m) => {
        const src = m?.color ? m.color : (m || {});
        const hex = src.hex
          ? (src.hex.startsWith("#") ? src.hex : `#${src.hex}`)
          : (src.hex6 ? `#${String(src.hex6).replace(/^#/,"")}` : "");
        const title = [src.brand, src.name, src.color_name].filter(Boolean).join(" · ") || hex || "…";
        return { hex: hex || "#ccc", title };
      });
    }
    if (typeof hexes !== "string" || !hexes) return [];
    return hexes.split(",").map((h) => {
      const six = (h.startsWith("#") ? h.slice(1) : h).trim();
      const ok  = /^[0-9A-Fa-f]{6}$/.test(six);
      const hex = ok ? `#${six}` : "#cccccc";
      return { hex, title: hex };
    });
  }, [palette, hexes]);

  const columns = Math.max(1, chips.length);

  return (
    <div
      className="pg-card"
      data-id={id}
      role="button"
      tabIndex={0}
      onClick={onClick}
      onKeyDown={(e) => { if (e.key === "Enter") onClick(); }}
    >
      <div className="pg-head">
        <div className="pg-title" title={name}>
          {name}
        </div>
      </div>

      <div
        className="pg-strip"
        style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}
      >
        {chips.map(({ hex, title }, idx) => (
          <div
            key={idx}
            className="pg-chip"
            title={title}
            style={{ backgroundColor: hex }}
          />
        ))}
      </div>
    </div>
  );
}
