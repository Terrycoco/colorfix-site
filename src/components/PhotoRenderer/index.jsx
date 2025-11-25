import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./photorenderer.css";

/**
 * Props:
 *  - asset: { asset_id, repairedUrl, preparedUrl, masks: [...] }
 *  - assignments: { [role]: { hex6, L, a, b } }
 *  - viewMode: "before" | "after" | "prepared"
 */
export default function PhotoRenderer({ asset, assignments, viewMode, onStateChange }) {
  const [compositeUrl, setCompositeUrl] = useState("");
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState("");
  const [isFull, setIsFull] = useState(false);

  // Build role -> HEX6
  const hexMap = useMemo(() => {
    const out = {};
    if (assignments) {
      for (const [role, obj] of Object.entries(assignments)) {
        const hex = (obj?.hex6 || "").toString().trim().toUpperCase();
        if (/^[0-9A-F]{6}$/.test(hex)) out[role] = hex;
      }
    }
    return out;
  }, [assignments]);

  // Render when assignments change and we're in "after" mode
  useEffect(() => {
    if (!asset?.asset_id) {
      onStateChange?.({ loading: false, error: "" });
      return;
    }

    if (viewMode !== "after" || Object.keys(hexMap).length === 0) {
      setCompositeUrl("");
      setErr("");
      onStateChange?.({ loading: false, error: "" });
      return;
    }

    setLoading(true);
    setErr("");
    onStateChange?.({ loading: true, error: "" });
    fetch(`${API_FOLDER}/v2/photos/render-apply.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        asset_id: asset.asset_id,
        map: hexMap,
        mode: "colorize",
        alpha: 0.9
      })
    })
      .then(r => r.json())
      .then(data => {
        if (data?.error) throw new Error(data.error);
        const url = data?.render_url || "";
        setCompositeUrl(url ? `${url}?ts=${Date.now()}` : "");
        onStateChange?.({ loading: false, error: "" });
      })
      .catch(e => {
        const msg = e?.message || "Render failed";
        setErr(msg);
        onStateChange?.({ loading: false, error: msg });
      })
      .finally(() => {
        setLoading(false);
      });
  }, [asset?.asset_id, hexMap, viewMode]);

  // Which image to show
  const imgSrc = useMemo(() => {
    if (!asset) return "";
    if (viewMode === "before")  return asset.repairedUrl || asset.preparedUrl || "";
    if (viewMode === "prepared") return asset.preparedUrl || "";
    return compositeUrl || asset.preparedUrl || "";
  }, [asset, compositeUrl, viewMode]);

  // Fullscreen UX: block body scroll, close on Escape
  useEffect(() => {
    if (isFull) {
      const prev = document.body.style.overflow;
      document.body.style.overflow = "hidden";
      const onKey = (e) => { if (e.key === "Escape") setIsFull(false); };
      window.addEventListener("keydown", onKey, { passive: true });
      return () => {
        window.removeEventListener("keydown", onKey);
        document.body.style.overflow = prev;
      };
    }
  }, [isFull]);

  const toggleFull = () => setIsFull(v => !v);

  return (
    <div className="photo-renderer">
      <div className="render-stage">
        {!imgSrc && <div className="status">No image</div>}
        {imgSrc && (
          <img
            className="render-img"
            src={imgSrc}
            alt=""
            draggable={false}
            onClick={toggleFull}
          />
        )}
      </div>

      {/* Fullscreen overlay */}
      {isFull && (
        <div className="full-overlay" onClick={toggleFull} role="dialog" aria-label="Fullscreen image">
          <img className="full-img" src={imgSrc} alt="" draggable={false} />
          <div className="full-hint">Tap to close · Esc to exit</div>
        </div>
      )}
    </div>
  );
}
