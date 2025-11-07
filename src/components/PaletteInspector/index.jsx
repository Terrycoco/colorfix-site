// PaletteInspector.jsx
import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import SwatchGallery from "@components/SwatchGallery";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import { API_FOLDER as API } from "@helpers/config";
import "./paletteInspector.css";
import {isAdmin} from '@helpers/authHelper';
import PaletteMetaEditor from './PaletteMetaEditor';

export default function PaletteInspector({ palette, onClose, onPatched, topOffset = 56 }) {
  const [colors, setColors] = useState([]);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
const paletteId = Number(palette?.palette_id ?? palette?.id ?? 0);

  useEffect(() => {
    let alive = true;
    async function fetchDetails() {
      if (!paletteId) return;
      setLoading(true);
      try {
        const resp = await fetch(`${API}/get-palette-details.php`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ palette_id: paletteId }),
        });
        const data = await resp.json();
        if (!alive) return;
        setColors(Array.isArray(data?.items) ? data.items : []);
      } catch {
        if (alive) setColors([]);
      } finally {
        if (alive) setLoading(false);
      }
    }
    fetchDetails();
    return () => { alive = false; };
  }, [paletteId]);

  if (!palette) return null;

  return (
    <div
      className="pi-overlay"
      style={{ "--pi-top-offset": `${topOffset}px` }}
      onClick={onClose}
    >
      <div className="pi-modal" onClick={(e) => e.stopPropagation()}>
        <header className="pi-header">
          <h2>{palette?.name || `Palette #${paletteId}`}</h2>
          <button className="pi-close" onClick={onClose} aria-label="Close">✕</button>
        </header>
<SwatchGallery
  className="pi-swatches"
  items={colors}
  SwatchComponent={PaletteSwatch}
  swatchPropName="color"
  columns={null}      // auto-fit on wider screens
  minWidth={140}
  gap={12}
  aspectRatio="5 / 4"
  emptyMessage={loading ? "Loading swatches…" : "No colors in this palette."}
  swatchProps={{ widthPercent: 100 }}   // ← fill grid cell
/>


<footer className="pi-footer" style={{ display: "flex", justifyContent: "flex-end", padding: "12px 16px 16px" }}>
  <button
    className="pi-btn"
    type="button"
    onClick={() => navigate(`/palette/${paletteId}/brands`)}
  >
    See In Each Brand
  </button>
</footer>
{isAdmin() && <PaletteMetaEditor palette={palette} onPatched={onPatched} />}

      </div>
    </div>
  );
}


