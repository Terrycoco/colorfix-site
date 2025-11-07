import React, { useState } from "react";
import PaletteThumb from "./PaletteThumb.jsx";
import PaletteInspector from "@components/PaletteInspector";
import "./palettegallery.css";

export default function PaletteGallery({
  palettes = [],
  onAddPalette,
  onSelect
}) {
  const [inspected, setInspected] = useState(null);

  return (
    <div className="palette-gallery">
      {palettes.map((p, i) => {
        const pid = p.id ?? i;
        return (
          <PaletteThumb
            key={pid}
            id={pid}
            name={p.name || (p.id ? `Palette #${p.id}` : "Palette")}
            hexes={typeof p.hexes === "string" ? p.hexes : ""}
            palette={p}
            onClick={() => setInspected(p)}   // 👈 open inspector
          />
        );
      })}

      {inspected && (
        <PaletteInspector
          palette={inspected}
          onClose={() => setInspected(null)}
          onTranslate={(p) =>
            console.log("Translate this palette to Dunn-Edwards:", p)
          }
        />
      )}
    </div>
  );
}
