import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import "./print-applied.css";

const API_URL = "/api/v2/applied-palettes/render.php";

export default function PrintAppliedPalettePage() {
  const { paletteId } = useParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const brandLabel = (code, name) => name || code || "";

  useEffect(() => {
    if (!paletteId) return;
    setState({ loading: true, error: "", data: null });
    const controller = new AbortController();
    fetch(`${API_URL}?id=${encodeURIComponent(paletteId)}&_=${Date.now()}`, {
      signal: controller.signal,
    })
      .then((r) => r.json())
      .then((res) => {
        if (!res?.ok) throw new Error(res?.error || "Failed to load palette");
        setState({ loading: false, error: "", data: res });
      })
      .catch((err) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: err?.message || "Failed to load", data: null });
      });
    return () => controller.abort();
  }, [paletteId]);

  const { render, palette, entries = [] } = state.data || {};

  const printNow = () => {
    if (typeof window !== "undefined") window.print();
  };

  if (state.loading) return <div className="print-shell"><div className="print-status">Loading…</div></div>;
  if (state.error) return <div className="print-shell"><div className="print-status error">{state.error}</div></div>;

  return (
    <div className="print-shell">
      <div className="print-header no-print">
        <div className="print-actions">
          <button className="print-btn" onClick={printNow}>Print</button>
          <button className="print-btn primary" onClick={printNow}>Save as PDF</button>
        </div>
      </div>

      <div className="print-body">
        {render?.render_url && (
          <div className="print-photo">
            <img src={render.render_url} alt="Rendered palette" />
          </div>
        )}

        <div className="print-meta">
          {(palette?.display_title || palette?.title) && (
            <div className="print-name">{palette?.display_title || palette?.title}</div>
          )}
          {palette?.notes && <div className="print-notes">{palette.notes}</div>}
        </div>

        <div className="print-swatches">
          {entries.map((e) => {
            const hex = e.color_hex6 || "";
            const brand = brandLabel(e.color_brand, e.color_brand_name);
            return (
              <div className="print-swatch" key={`${e.mask_role}-${e.color_id || hex}`}>
                <svg className="swatch-chip" role="presentation" aria-hidden="true">
                  <rect width="100%" height="100%" fill={hex ? `#${hex}` : "#cccccc"} />
                </svg>
                <div className="swatch-meta">
                  <div className="swatch-role">{e.mask_role}</div>
                  <div className="swatch-name">{e.color_name || `Color #${e.color_id}`}</div>
                  <div className="swatch-detail">
                    {brand && <span>{brand}</span>}
                    {e.color_code && <span> · {e.color_code}</span>}
                    {hex && <span> · #{hex}</span>}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <div className="print-footer print-only">
          <img src="/logo.png" alt="ColorFix" />
          <div className="print-footer-text">ColorFix</div>
        </div>
      </div>
    </div>
  );
}
