import { useMemo, useState } from "react";
import LogoAnimated from "@components/LogoAnimated";
import "./appliedpaletteviewer.css";

const BRAND_LABELS = {
  de: "Dunn Edwards",
  sw: "Sherwin-Williams",
  behr: "Behr",
  bm: "Benjamin Moore",
  benjaminmoore: "Benjamin Moore",
  valspar: "Valspar",
  ppg: "PPG",
  glidden: "Glidden",
  pratt: "Pratt & Lambert",
};

export default function AppliedPaletteViewer({
  palette,
  renderInfo,
  entries = [],
  adminMode = false,
  showBackButton = true,
  onBack,
  showLogo = true,
  footer,
}) {
  const [photoExpanded, setPhotoExpanded] = useState(false);

  const titleRaw = palette?.display_title || palette?.title || "ColorFix Palette";
  const title = String(titleRaw).replace(/\s*--\s*/g, " — ");
  const colorGroups = useMemo(() => groupEntriesByColor(entries), [entries]);
  const renderUrl = useMemo(() => {
    const raw = renderInfo?.render_url || "";
    if (!raw) return "";
    const bust = renderInfo?.cache_bust;
    if (!bust) return raw;
    const joiner = raw.includes("?") ? "&" : "?";
    return `${raw}${joiner}v=${encodeURIComponent(bust)}`;
  }, [renderInfo]);

  const handleBack = () => {
    if (onBack) {
      onBack();
      return;
    }
    if (typeof window !== "undefined" && window.history.length > 1) {
      window.history.back();
    }
  };

  return (
    <div className={`apv-shell ${adminMode ? "apv-shell--admin" : ""}`}>
      <button
        className="apv-exit"
        onClick={handleBack}
        aria-label="Exit palette viewer"
      >
        ×
      </button>
      <div className="apv-header">
        <div className="apv-header-slot">
          {showBackButton ? (
            <button className="apv-btn apv-btn--ghost" onClick={handleBack}>
              ← Back
            </button>
          ) : (
            showLogo && (
              <button className="apv-logo-button" onClick={() => (window.location.href = "/")}>
                <LogoAnimated />
              </button>
            )
          )}
        </div>
        <div className="apv-header-logo" />
        <div className="apv-header-slot apv-header-slot--right">
          <div className="apv-header-spacer" />
        </div>
      </div>

      <div className="apv-content">
        <div className="apv-column apv-column--photo">
          <div className="apv-photo-wrap" onClick={() => setPhotoExpanded(true)}>
            {renderUrl ? (
              <img src={renderUrl} alt="Rendered palette" className="apv-photo" />
            ) : (
              <div className="apv-photo placeholder">No render available</div>
            )}
          </div>
        </div>

        <div className="apv-column apv-column--details">
          <div className="apv-info">
            <h1>{title}</h1>
            {palette?.notes && <p className="apv-notes">{palette.notes}</p>}
          </div>

          {entries.length > 0 && (
            <div className="apv-entries">
              {colorGroups.map((group) => (
                <div key={group.key} className="apv-entry">
                  <div className="apv-color">
                    <span
                      className="apv-swatch"
                      style={{ backgroundColor: group.color_hex6 ? `#${group.color_hex6}` : "#ccc" }}
                    />
                    <div className="apv-color-meta">
                      <div className="apv-name">
                        {group.color_name || `Color #${group.color_id}`}
                        {group.color_code ? `, ${group.color_code}` : ""}
                      </div>
                      <div className="apv-brand">{brandLabel(group.color_brand)}</div>
                      {group.masks.length > 0 && (
                        <div className="apv-masks">
                          {group.masks.join(", ")}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
      {photoExpanded && renderInfo?.render_url && (
        <div className="apv-photo-fullscreen" onClick={() => setPhotoExpanded(false)}>
          <img src={renderUrl} alt="Rendered palette full view" />
          <div className="apv-photo-fullscreen-hint">Tap to close</div>
        </div>
      )}
      {footer && <div className="apv-footer">{footer}</div>}
    </div>
  );
}

function groupEntriesByColor(entries) {
  if (!Array.isArray(entries)) return [];
  const groups = new Map();
  const order = [];
  entries.forEach((entry) => {
    const colorId = entry.color_id ?? null;
    const hex6 = entry.color_hex6 || entry.color_hex || "";
    const key = colorId ? `id:${colorId}` : hex6 ? `hex:${hex6}` : `mask:${entry.mask_role}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        color_id: colorId,
        color_hex6: hex6,
        color_name: entry.color_name || "",
        color_code: entry.color_code || "",
        color_brand: entry.color_brand || "",
        masks: [],
      });
      order.push(key);
    }
    const group = groups.get(key);
    if (entry.mask_role && !group.masks.includes(entry.mask_role)) {
      group.masks.push(entry.mask_role);
    }
  });
  return order.map((key) => groups.get(key));
}

function brandLabel(code) {
  if (!code) return "";
  const key = code.toString().trim().toLowerCase();
  return BRAND_LABELS[key] || code.toString();
}
