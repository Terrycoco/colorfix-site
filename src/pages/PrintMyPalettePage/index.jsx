import { useMemo } from "react";
import { useAppState } from "@context/AppStateContext";
import "./print-my.css";

export default function PrintMyPalettePage() {
  const { palette } = useAppState();
  const items = useMemo(() => Array.isArray(palette) ? palette : [], [palette]);

  const printNow = () => {
    if (typeof window !== "undefined") window.print();
  };

  const hexOf = (row) => {
    const color = row?.color ?? row;
    const raw = color?.hex6 || color?.hex || color?.rep_hex || color?.hex_code || "";
    if (!raw) return "#cccccc";
    return raw.startsWith("#") ? raw : `#${raw}`;
  };

  return (
    <div className="print-shell">
      <div className="print-header no-print">
        <div className="print-title">My Palette (Print)</div>
        <div className="print-actions">
          <button className="print-btn" onClick={printNow}>Print</button>
          <button className="print-btn primary" onClick={printNow}>Save as PDF</button>
        </div>
      </div>

      <div className="print-brand">
        <div className="print-logo">ColorFix</div>
        <div className="print-tagline">Here&apos;s your MyPalette from ColorFix.</div>
      </div>

      {items.length === 0 ? (
        <div className="print-status">No colors in your palette.</div>
      ) : (
        <div className="print-swatches">
          {items.map((row) => {
            const color = row?.color ?? row;
            const hex = hexOf(row);
            const brand = color?.brand_name || color?.brand || "";
            return (
              <div className="print-swatch" key={color?.id || color?.code || hex}>
                <svg className="swatch-chip" role="presentation" aria-hidden="true">
                  <rect width="100%" height="100%" fill={hex} />
                </svg>
                <div className="swatch-meta">
                  <div className="swatch-name">{color?.name || `Color #${color?.id || ""}`}</div>
                  <div className="swatch-detail">
                    {brand && <span>{brand}</span>}
                    {color?.code && <span> · {color.code}</span>}
                    {hex && <span> · {hex.toUpperCase()}</span>}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
