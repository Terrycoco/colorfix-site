import { useMemo, useState } from "react";
import MaskTable from "@components/MaskTable";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./mask-role-grid.css";

export default function MaskRoleGrid({
  masks = [],
  entries = {},
  onChange,
  onApply,
  onShadow,
  showRole = false,
}) {
  const [activeRole, setActiveRole] = useState("");

  const rows = useMemo(() => {
    return (masks || []).map((mask) => {
      const entry = entries[mask.role] || {};
      const color = entry.color || null;
      const targetLightness =
        entry.target_lightness != null
          ? Number(entry.target_lightness)
          : typeof color?.lightness === "number"
            ? color.lightness
            : typeof color?.lab_l === "number"
              ? color.lab_l
              : typeof color?.hcl_l === "number"
                ? color.hcl_l
                : null;
      return {
        id: mask.role,
        mask_role: mask.role,
        role: mask.role,
        color_id: color?.id || color?.color_id || null,
        color_hex: (color?.hex6 || color?.hex || "").replace("#", ""),
        color_name: color?.name || color?.code || "",
        color_code: color?.code || "",
        color_brand: color?.brand || "",
        base_lightness: mask.base_lightness,
        target_lightness: targetLightness ?? mask.base_lightness ?? null,
        blend_mode: entry.blend_mode || "",
        blend_opacity: entry.blend_opacity ?? null,
        shadow_l_offset: entry.shadow_l_offset ?? 0,
        shadow_tint_hex: entry.shadow_tint_hex || "",
        shadow_tint_opacity: entry.shadow_tint_opacity ?? 0,
      };
    });
  }, [masks, entries]);

  function handleMode(row, value) {
    onChange?.(row.mask_role, { blend_mode: value });
  }

  function handleOpacity(row, value) {
    onChange?.(row.mask_role, { blend_opacity: parsePct(value) });
  }

  function handleApplyRow(row) {
    onApply?.(row.mask_role);
  }

  function handleChangeColor(color) {
    if (!activeRole) return;
    onChange?.(activeRole, { color });
    setActiveRole("");
  }

  return (
    <div className={`mask-grid-table ${showRole ? "show-role" : ""}`}>
      {activeRole && (
        <div className="color-editor-bar">
          <div className="color-editor-title">Change color for {(activeRole || "").toUpperCase()}</div>
          <FuzzySearchColorSelect
            value={entries[activeRole]?.color || null}
            onSelect={handleChangeColor}
            placeholder="Color name or code"
            allowManualHex
            showLabel={false}
            compact
            autoFocus
            preventAutoFocus={false}
            suppressFocus={false}
            manualOpen={false}
          />
          <button type="button" className="btn small" onClick={() => setActiveRole("")}>
            Close
          </button>
        </div>
      )}
      <MaskTable
        rows={rows}
        showRole={showRole}
        onApply={handleApplyRow}
        onSave={null}
        onShadow={onShadow}
        onDelete={null}
        onChangeMode={handleMode}
        onChangeOpacity={handleOpacity}
        onClickColor={(row) => setActiveRole(row.mask_role)}
      />
    </div>
  );
}

function parsePct(v) {
  if (v === "" || v == null) return null;
  const num = Number(v);
  if (!Number.isFinite(num)) return null;
  return Math.max(0, Math.min(1, num / 100));
}
