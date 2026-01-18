import { useMemo } from "react";
import PropTypes from "prop-types";
import "./mask-table.css";

export default function MaskTable({
  rows = [],
  onApply,
  onSave,
  onShadow,
  onDelete,
  onChangeMode,
  onChangeOpacity,
  onChangeOffset,
  showRole = false,
  onClickColor,
}) {
  const formatHcl = (row) => {
    const h = row?.color_h;
    const c = row?.color_c;
    const l = row?.color_l;
    const hasAny = [h, c, l].some((val) => Number.isFinite(Number(val)));
    if (!hasAny) return "H:— C:— L:—";
    const hVal = Number.isFinite(Number(h)) ? Math.round(Number(h)) : "—";
    const cVal = Number.isFinite(Number(c)) ? Math.round(Number(c)) : "—";
    const lVal = Number.isFinite(Number(l)) ? Math.round(Number(l)) : "—";
    return `H:${hVal} C:${cVal} L:${lVal}`;
  };

  const normalized = useMemo(
    () =>
      (rows || []).map((row) => ({
        ...row,
        opacityPct: row.blend_opacity == null ? "" : (row.blend_opacity * 100).toFixed(1),
        shadowOffset: row.shadow_l_offset == null ? "" : row.shadow_l_offset,
        displayLightness:
          row.base_lightness != null ? Math.round(row.base_lightness) : "—",
      })),
    [rows]
  );

  const showOffset = !!onChangeOffset;

  return (
    <div className={`mask-table ${showRole ? "show-role" : ""} ${showOffset ? "with-offset" : ""}`}>
      <div className="mask-table-header">
        {showRole && <div className="col role-col">Role</div>}
        <div className="col swatch-col">Color</div>
        <div className="col light-col">L</div>
        <div className="col mode-col">Mode</div>
        <div className="col pct-col">%</div>
        {showOffset && <div className="col off-col">Off</div>}
        <div className="col actions-col">Actions</div>
      </div>
      <div className="mask-table-body">
        {normalized.map((row) => (
          <div key={row.id || row.color_id || row.mask_role} className="mask-table-row">
            {showRole && <div className="col role-col">{(row.mask_role || row.role || "").toUpperCase()}</div>}
            <div
              className="col swatch-col"
              onClick={() => onClickColor && onClickColor(row)}
              role={onClickColor ? "button" : undefined}
              tabIndex={onClickColor ? 0 : undefined}
              onKeyDown={(e) => {
                if (!onClickColor) return;
                if (e.key === "Enter" || e.key === " ") {
                  e.preventDefault();
                  onClickColor(row);
                }
              }}
            >
              <div
                className={`swatch-chip ${!row.color_id ? "swatch-chip--empty" : ""}`}
                style={{ backgroundColor: row.color_hex ? `#${row.color_hex}` : "#e6e6e6" }}
              >
                {!row.color_id && <span className="swatch-plus">+</span>}
              </div>
              <div className="swatch-meta">
                <div className="swatch-name">
                  {row.color_name || row.color_code || (row.color_id ? `Color #${row.color_id}` : "Add color")}
                </div>
                <div className="swatch-brand">
                  {(row.color_brand || row.brand || "").trim() || "—"} · {formatHcl(row)}
                </div>
              </div>
            </div>
            <div className="col light-col">{row.displayLightness}</div>
            <div className="col mode-col">
              <select
                value={row.blend_mode || row.draft_mode || ""}
                onChange={(e) => onChangeMode && onChangeMode(row, e.target.value)}
              >
                {BLEND_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="col pct-col">
              <input
                type="number"
                min="0"
                max="100"
                value={row.opacityPct}
                step="0.1"
                inputMode="decimal"
                onChange={(e) => onChangeOpacity && onChangeOpacity(row, e.target.value)}
              />
            </div>
            {showOffset && (
              <div className="col off-col">
                <input
                  type="number"
                  min="-50"
                  max="50"
                  value={row.shadowOffset}
                  onChange={(e) => onChangeOffset && onChangeOffset(row, e.target.value)}
                  disabled={!onChangeOffset}
                />
              </div>
            )}
            <div className="col actions-col">
              <div className="action-icons">
                <button className="icon-btn" title="Apply" onClick={() => onApply && onApply(row)}>
                  <span className="icon icon-play" aria-hidden="true" />
                  <span className="sr-only">Apply</span>
                </button>
                <button className="icon-btn" title="Save" onClick={() => onSave && onSave(row)}>
                  <span className="icon icon-save" aria-hidden="true" />
                  <span className="sr-only">Save</span>
                </button>
                <button className="icon-btn" title="Shadow" onClick={() => onShadow && onShadow(row)} disabled={!onShadow}>
                  <span className="icon icon-shadow" aria-hidden="true" />
                  <span className="sr-only">Shadow</span>
                </button>
                <button className="icon-btn danger" title="Delete" onClick={() => onDelete && onDelete(row)}>
                  <span className="icon icon-delete" aria-hidden="true" />
                  <span className="sr-only">Delete</span>
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

const BLEND_OPTIONS = [
  { value: "colorize", label: "Colorize" },
  { value: "softlight", label: "Soft Light" },
  { value: "overlay", label: "Overlay" },
  { value: "linearburn", label: "Linear Burn" },
  { value: "multiply", label: "Multiply" },
  { value: "screen", label: "Screen" },
  { value: "hardlight", label: "Hard Light" },
  { value: "luminosity", label: "Luminosity" },
  { value: "flatpaint", label: "Flat Paint" },
  { value: "original", label: "Original Photo" },
];

MaskTable.propTypes = {
  rows: PropTypes.arrayOf(PropTypes.object),
  onApply: PropTypes.func,
  onSave: PropTypes.func,
  onShadow: PropTypes.func,
  onDelete: PropTypes.func,
  onChangeMode: PropTypes.func,
  onChangeOpacity: PropTypes.func,
  onChangeOffset: PropTypes.func,
  showRole: PropTypes.bool,
  onClickColor: PropTypes.func,
};

MaskTable.defaultProps = {
  rows: [],
  onApply: null,
  onSave: null,
  onShadow: null,
  onDelete: null,
  onChangeMode: null,
  onChangeOpacity: null,
  onChangeOffset: null,
  showRole: false,
  onClickColor: null,
};
