import { Fragment } from "react";
import PropTypes from "prop-types";
import "@components/MaskBlendHistory/maskblendhistory.css";

function iconKeyHandler(e, handler) {
  if (e.key === "Enter" || e.key === " ") {
    e.preventDefault();
    handler();
  }
}

export default function MaskSettingsTable({
  rows = [],
  onPickColor,
  onChangeMode,
  onChangePercent,
  onChangeOffset,
  onToggleApproved,
  onApply,
  onSave,
  onShadow,
  onDelete,
  onSelectRow,
  activeRowId = null,
  activeColorId = null,
  activeColorIdByRow = null,
  showRole = false,
  renderExtraRow = null,
}) {
  const formatPercentValue = (value) => {
    if (value == null || value === "") return "";
    if (typeof value === "string") return value;
    const num = Number(value);
    return Number.isFinite(num) ? num.toFixed(1) : "";
  };

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

  return (
    <div className="mbh-table-wrapper">
      <table className="mbh-table">
        <thead>
          <tr>
            {showRole && <th className="col-role">Role</th>}
            <th>Color</th>
            <th className="col-lightness">L</th>
            <th className="col-mode">Mode</th>
            <th className="col-percent">%</th>
            <th className="col-offset">Off</th>
            <th className="col-approved">Approved</th>
            <th className="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const modeValue = row.draft_mode ?? row.blend_mode ?? "";
            const modeLower = (modeValue || "").toLowerCase();
            const isOriginal = modeLower === "original";
            const displayName = isOriginal
              ? "Original Photo"
              : (row.color_name || row.color_code || row.color_hex || row.title || "");
            const swatchHex = row.color_hex
              ? `#${row.color_hex.replace("#", "")}`
              : "#ccc";
            const percentValue =
              row.draft_percent ??
              row.percent ??
              (row.blend_opacity == null ? "" : row.blend_opacity * 100);
            const percentDisplay = formatPercentValue(percentValue);
            const offsetValue =
              row.draft_shadow_input?.l_offset ??
              (typeof row.draft_shadow?.l_offset === "number"
                ? String(row.draft_shadow.l_offset)
                : row.shadow_l_offset ?? "");
            const approvedValue = row.draft_approved ?? row.approved ?? false;
            const resolvedActiveColorId = typeof activeColorIdByRow === "function"
              ? activeColorIdByRow(row)
              : (activeColorIdByRow && (activeColorIdByRow[row.id] ?? activeColorIdByRow[row.mask_role]));
            const isActive =
              (activeRowId != null && activeRowId === row.id) ||
              (resolvedActiveColorId != null && resolvedActiveColorId === row.color_id) ||
              (activeColorId != null && activeColorId === row.color_id);

            const rowKey = row.id || row.color_id || row.mask_role;
            return (
              <Fragment key={rowKey}>
                <tr
                  className={isActive ? "mbh-row active" : "mbh-row"}
                  onClick={() => onSelectRow && onSelectRow(row)}
                  onKeyDown={(e) => {
                    if (!onSelectRow) return;
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      onSelectRow(row);
                    }
                  }}
                  tabIndex={onSelectRow ? 0 : undefined}
                >
                  {showRole && (
                    <td className="mbh-role">
                      {(row.mask_role || row.role || "").toUpperCase()}
                    </td>
                  )}
                  <td>
                    <div
                      className="mbh-color-cell"
                      role={onPickColor ? "button" : undefined}
                      tabIndex={onPickColor ? 0 : undefined}
                      onClick={(e) => {
                        if (!onPickColor) return;
                        e.stopPropagation();
                        onPickColor(row);
                      }}
                      onKeyDown={(e) => {
                        if (!onPickColor) return;
                        if (e.key === "Enter" || e.key === " ") {
                          e.preventDefault();
                          onPickColor(row);
                        }
                      }}
                    >
                      <div
                        className={`mbh-color-swatch ${isOriginal ? "is-original" : ""}`}
                        style={isOriginal ? undefined : { backgroundColor: swatchHex }}
                      />
                      <div className="mbh-color-meta">
                        <div>{displayName || "Add color"}</div>
                        <div className="mbh-color-sub">
                          {(row.color_brand || "").trim() || "—"} · {formatHcl(row)}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>{row.base_lightness != null ? Math.round(row.base_lightness) : "—"}</td>
                  <td>
                    <select
                      value={modeValue}
                      onChange={(e) => onChangeMode && onChangeMode(row, e.target.value)}
                    >
                      <option value="colorize">Colorize</option>
                      <option value="softlight">Soft Light</option>
                      <option value="overlay">Overlay</option>
                      <option value="linearburn">Linear Burn</option>
                      <option value="multiply">Multiply</option>
                      <option value="screen">Screen</option>
                      <option value="hardlight">Hard Light</option>
                      <option value="luminosity">Luminosity</option>
                      <option value="flatpaint">Flat Paint</option>
                      <option value="original">Original Photo</option>
                    </select>
                  </td>
                  <td>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      step="0.1"
                      inputMode="decimal"
                      value={percentDisplay}
                      onChange={(e) => onChangePercent && onChangePercent(row, e.target.value)}
                      disabled={isOriginal}
                    />
                  </td>
                  <td>
                    <input
                      type="text"
                      className="col-offset"
                      inputMode="text"
                      pattern="-?[0-9]*"
                      value={offsetValue}
                      onChange={(e) => onChangeOffset && onChangeOffset(row, e.target.value)}
                      onBlur={(e) => onChangeOffset && onChangeOffset(row, e.target.value)}
                    />
                  </td>
                  <td className="mbh-approved">
                    <label className="mbh-approved-toggle">
                      <input
                        type="checkbox"
                        checked={!!approvedValue}
                        onChange={(e) => onToggleApproved && onToggleApproved(row, e.target.checked)}
                        disabled={!onToggleApproved}
                      />
                    </label>
                  </td>
                  <td className="mbh-actions">
                    <div className="mbh-action-icons">
                      <div
                        className="icon-btn"
                        role="button"
                        tabIndex={onApply ? 0 : -1}
                        aria-disabled={!onApply}
                        title="Apply test"
                        onClick={() => onApply && onApply(row)}
                        onKeyDown={(e) => onApply && iconKeyHandler(e, () => onApply(row))}
                      >
                        <span className="icon icon-play" aria-hidden="true" />
                        <span className="sr-only">Apply</span>
                      </div>
                      <div
                        className="icon-btn"
                        role="button"
                        tabIndex={onSave ? 0 : -1}
                        aria-disabled={!onSave}
                        title="Save test"
                        onClick={() => onSave && onSave(row)}
                        onKeyDown={(e) => onSave && iconKeyHandler(e, () => onSave(row))}
                      >
                        <span className="icon icon-save" aria-hidden="true" />
                        <span className="sr-only">Save</span>
                      </div>
                      <div
                        className="icon-btn"
                        role="button"
                        tabIndex={onShadow ? 0 : -1}
                        aria-disabled={!onShadow}
                        title="Shadow settings"
                        onClick={() => onShadow && onShadow(row)}
                        onKeyDown={(e) => onShadow && iconKeyHandler(e, () => onShadow(row))}
                      >
                        <span className="icon icon-shadow" aria-hidden="true" />
                        <span className="sr-only">Shadow settings</span>
                      </div>
                      <div
                        className="icon-btn danger"
                        role="button"
                        tabIndex={onDelete ? 0 : -1}
                        aria-disabled={!onDelete}
                        title="Delete test"
                        onClick={() => onDelete && onDelete(row)}
                        onKeyDown={(e) => onDelete && iconKeyHandler(e, () => onDelete(row))}
                      >
                        <span className="icon icon-delete" aria-hidden="true" />
                        <span className="sr-only">Delete</span>
                      </div>
                    </div>
                  </td>
                </tr>
                {renderExtraRow ? renderExtraRow(row) : null}
              </Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

MaskSettingsTable.propTypes = {
  rows: PropTypes.arrayOf(PropTypes.object),
  onPickColor: PropTypes.func,
  onChangeMode: PropTypes.func,
  onChangePercent: PropTypes.func,
  onChangeOffset: PropTypes.func,
  onToggleApproved: PropTypes.func,
  onApply: PropTypes.func,
  onSave: PropTypes.func,
  onShadow: PropTypes.func,
  onDelete: PropTypes.func,
  onSelectRow: PropTypes.func,
  activeRowId: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  activeColorId: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  activeColorIdByRow: PropTypes.oneOfType([PropTypes.object, PropTypes.func]),
  showRole: PropTypes.bool,
  renderExtraRow: PropTypes.func,
};
