import PropTypes from "prop-types";
import "./maskoverlayeditor.css";
import MaskBlendHistory from "@components/MaskBlendHistory";

const TIERS = ["dark", "medium", "light"];
const BLEND_OPTIONS = [
  { value: "colorize", label: "Colorize", desc: "Lab color; keeps shading" },
  { value: "hardlight", label: "Hard Light", desc: "High contrast overlay" },
  { value: "softlight", label: "Soft Light", desc: "Gentle contrast + warmth" },
  { value: "overlay", label: "Overlay", desc: "Similar to soft light, stronger" },
  { value: "linearburn", label: "Linear Burn", desc: "Subtractive darken" },
  { value: "multiply", label: "Multiply", desc: "Darken; good for bright colors" },
  { value: "screen", label: "Screen", desc: "Lighten; lifts dark colors" },
  { value: "luminosity", label: "Luminosity", desc: "Keep base hue, use target brightness" },
  { value: "flatpaint", label: "Flat Paint", desc: "Skip texture overlay" },
  { value: "original", label: "Original Photo", desc: "Show base photo only" },
];

function formatLabel(tier) {
  return tier.charAt(0).toUpperCase() + tier.slice(1);
}

function ensureOverlayStruct(overlay) {
  const out = {};
  TIERS.forEach((tier) => {
    out[tier] = {
      mode: overlay?.[tier]?.mode ?? "",
      opacity: typeof overlay?.[tier]?.opacity === "number" ? overlay[tier].opacity : null,
    };
  });
  out._shadow = {
    l_offset: typeof overlay?._shadow?.l_offset === "number" ? overlay._shadow.l_offset : 0,
    tint_hex: overlay?._shadow?.tint_hex || null,
    tint_opacity: typeof overlay?._shadow?.tint_opacity === "number" ? overlay._shadow.tint_opacity : 0,
  };
  return out;
}

export default function MaskOverlayEditor({
  masks,
  overlays,
  textures,
  textureOptions,
  onChange,
  onTextureChange,
  onSave,
  status,
  onCancel,
  onApplyColors,
  onReload,
  suggestions,
  onApplyPreset,
  assetId,
  showHistory = true,
}) {
  if (!masks.length) {
    return <div className="mask-overlay-empty">No masks available.</div>;
  }

  return (
    <div className="mask-overlay-editor">
      <datalist id="mask-overlay-texture-options">
        {textureOptions.map((opt) => (
          <option key={opt} value={opt} />
        ))}
      </datalist>
      {masks.map((maskData) => {
        const role = maskData.role;
        const baseLightnessValue =
          typeof maskData.base_lightness === "number" ? maskData.base_lightness : null;
        const overlay = ensureOverlayStruct(overlays[role]);
        const maskStatus = status[role] || {};
        const textureValue = textures?.[role] || "";
        const suggestion = suggestions?.[role];
        return (
          <div className="mask-overlay-card" key={role}>
              <div className="mask-overlay-card__head">
                <div className="mask-overlay-card__title">{role}</div>
                <div className="mask-overlay-card__actions">
                  {onCancel && (
                    <div className="mask-overlay-card__action">
                      <button
                        type="button"
                        className="btn btn-text"
                        onClick={() => onCancel && onCancel(role)}
                      >
                        Cancel
                      </button>
                    </div>
                  )}
                  {onReload && (
                    <div className="mask-overlay-card__action">
                      <button
                        type="button"
                        className="btn btn-text"
                        disabled={maskStatus.saving || maskStatus.refreshing}
                        onClick={() => onReload(role)}
                      >
                        {maskStatus.refreshing ? "Reloading…" : "Reload"}
                      </button>
                    </div>
                  )}
                  {onApplyColors && (
                    <div className="mask-overlay-card__action">
                      <button
                        type="button"
                        className="btn"
                        disabled={maskStatus.saving || maskStatus.refreshing}
                        onClick={() => onApplyColors(role)}
                      >
                        Apply Colors
                      </button>
                    </div>
                  )}
                  <div className="mask-overlay-card__action">
                    <button
                      type="button"
                      className="btn"
                      disabled={maskStatus.saving || maskStatus.refreshing || !maskStatus.dirty}
                      onClick={() => onSave(role)}
                    >
                      {maskStatus.saving ? "Saving…" : "Save"}
                    </button>
                  </div>
                  {maskStatus.dirty && !maskStatus.saving && (
                    <div className="mask-overlay-card__dirty" aria-live="polite">Unsaved</div>
                  )}
                </div>
              </div>
            {suggestion && (
                <div className="mask-overlay-meta">
                  <span>
                    Base L {suggestion.baseLightness.toFixed(1)} ({suggestion.baseBucket})
                  </span>
                  <span>
                    Target L {suggestion.targetLightness.toFixed(1)} ({suggestion.targetBucket})
                  </span>
                </div>
              )}
            <div className="mask-overlay-meta-row">
              <label className="mask-overlay-label">Original Texture</label>
              <input
                type="text"
                list="mask-overlay-texture-options"
                placeholder="smooth_flat, rough_stucco…"
                value={textureValue}
                onChange={(e) => onTextureChange && onTextureChange(role, e.target.value)}
              />
              <div className="mask-overlay-lightness">
                <span className="mask-overlay-label">Original Lightness</span>
                <span>{baseLightnessValue != null ? `L ${baseLightnessValue.toFixed(1)}` : "L —"}</span>
              </div>
            </div>
            <div className="mask-overlay-grid">
              {TIERS.map((tier) => (
                <div key={tier} className="mask-overlay-tier">
                  <div className="mask-overlay-tier__label">{formatLabel(tier)}</div>
                  <select
                    value={overlay[tier].mode}
                    onChange={(e) => onChange(role, tier, "mode", e.target.value || null)}
                  >
                    <option value="">Default (Hard Light)</option>
                    {BLEND_OPTIONS.map((opt) => (
                      <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))}
                  </select>
                  <div className="mask-overlay-percent">
                    <input
                      type="number"
                      min="0"
                      max="100"
                      step="any"
                      value={
                        overlay[tier].opacity == null
                          ? ""
                          : Math.round(overlay[tier].opacity * 100)
                      }
                      placeholder="100"
                      onChange={(e) => {
                        const val = e.target.value;
                        onChange(
                          role,
                          tier,
                          "opacity",
                          val === "" ? null : Number(val) / 100
                        );
                      }}
                    />
                    <span className="mask-overlay-percent__suffix">%</span>
                  </div>
                  {suggestion && suggestion.targetBucket === tier && suggestion.preset && onApplyPreset && (
                    <button
                      type="button"
                      className="btn btn-text mask-overlay-tier__apply"
                      onClick={() => onApplyPreset(role, tier, suggestion.preset)}
                      disabled={maskStatus.saving || maskStatus.refreshing}
                    >
                      Use {suggestion.preset.mode} · {Math.round((suggestion.preset.opacity ?? 0) * 100)}%
                    </button>
                  )}
                </div>
              ))}
            </div>
            {maskStatus.error && <div className="error">{maskStatus.error}</div>}
            {maskStatus.success && <div className="notice">{maskStatus.success}</div>}
            {assetId && showHistory && (
              <MaskBlendHistory
                assetId={assetId}
                maskRole={role}
                baseLightness={baseLightnessValue}
                onApplyBlend={(mask, tier, preset) =>
                  onApplyPreset && onApplyPreset(mask, tier, preset)
                }
              />
            )}
            <div className="blend-help">
              {BLEND_OPTIONS.map(({ value, label, desc }) => (
                <div key={value} className="blend-help__item">
                  <strong>{label}</strong>
                  <span>{desc}</span>
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

MaskOverlayEditor.propTypes = {
  masks: PropTypes.arrayOf(
    PropTypes.shape({
      role: PropTypes.string.isRequired,
    })
  ).isRequired,
  overlays: PropTypes.object.isRequired,
  textures: PropTypes.object,
  textureOptions: PropTypes.arrayOf(PropTypes.string),
  onChange: PropTypes.func.isRequired,
  onTextureChange: PropTypes.func,
  onSave: PropTypes.func.isRequired,
  status: PropTypes.object.isRequired,
  onCancel: PropTypes.func,
  onApplyColors: PropTypes.func,
  onReload: PropTypes.func,
  suggestions: PropTypes.object,
  onApplyPreset: PropTypes.func,
  assetId: PropTypes.string,
  showHistory: PropTypes.bool,
};

MaskOverlayEditor.defaultProps = {
  textures: {},
  textureOptions: [],
  onTextureChange: null,
  onCancel: null,
  onApplyColors: null,
  onReload: null,
  suggestions: {},
  onApplyPreset: null,
  assetId: null,
  showHistory: true,
};
