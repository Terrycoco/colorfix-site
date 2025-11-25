import PropTypes from "prop-types";
import "./maskoverlayeditor.css";

const TIERS = ["dark", "medium", "light"];
const BLEND_OPTIONS = ["colorize", "hardlight", "softlight", "overlay", "multiply", "screen", "luminosity"];

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
      {masks.map(({ role }) => {
        const overlay = ensureOverlayStruct(overlays[role]);
        const maskStatus = status[role] || {};
        const textureValue = textures?.[role] || "";
        return (
          <div className="mask-overlay-card" key={role}>
              <div className="mask-overlay-card__head">
                <div className="mask-overlay-card__title">{role}</div>
                <div className="mask-overlay-card__actions">
                  <div className="mask-overlay-card__action">
                    <button
                      type="button"
                      className="btn btn-text"
                      onClick={() => onCancel && onCancel(role)}
                    >
                      Cancel
                    </button>
                  </div>
                  <div className="mask-overlay-card__action">
                    <button
                      type="button"
                      className="btn"
                      disabled={maskStatus.saving}
                      onClick={() => onSave(role)}
                    >
                      {maskStatus.saving ? "Saving…" : "Save"}
                    </button>
                  </div>
                </div>
              </div>
            <div className="mask-overlay-texture">
              <label>Original Texture</label>
              <input
                type="text"
                list="mask-overlay-texture-options"
                placeholder="smooth_flat, rough_stucco…"
                value={textureValue}
                onChange={(e) => onTextureChange && onTextureChange(role, e.target.value)}
              />
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
                      <option key={opt} value={opt}>{opt}</option>
                    ))}
                  </select>
                  <div className="mask-overlay-percent">
                    <input
                      type="number"
                      min="0"
                      max="100"
                      step="1"
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
                </div>
              ))}
            </div>
            {maskStatus.error && <div className="error">{maskStatus.error}</div>}
            {maskStatus.success && <div className="notice">{maskStatus.success}</div>}
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
};

MaskOverlayEditor.defaultProps = {
  textures: {},
  textureOptions: [],
  onTextureChange: null,
  onCancel: null,
};
