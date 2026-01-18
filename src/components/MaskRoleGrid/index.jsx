import { useEffect, useMemo, useRef, useState } from "react";
import MaskSettingsTable from "@components/MaskSettingsTable";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./mask-role-grid.css";

export default function MaskRoleGrid({
  masks = [],
  entries = {},
  activeColorByMask = null,
  onChange,
  onApply,
  onShadow,
  showRole = false,
}) {
  const [activeRole, setActiveRole] = useState("");
  const editorRef = useRef(null);

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
        blend_mode: entry.blend_mode || "colorize",
        blend_opacity: entry.blend_opacity ?? null,
        shadow_l_offset: entry.shadow_l_offset ?? 0,
        shadow_tint_hex: entry.shadow_tint_hex || "",
        shadow_tint_opacity: entry.shadow_tint_opacity ?? 0,
        approved: entry.approved ?? true,
      };
    });
  }, [masks, entries]);

  useEffect(() => {
    if (!activeRole) return;
    const onPointerDown = (e) => {
      if (!editorRef.current) return;
      if (editorRef.current.contains(e.target)) return;
      setActiveRole("");
    };
    document.addEventListener("pointerdown", onPointerDown, { capture: true });
    return () => document.removeEventListener("pointerdown", onPointerDown, { capture: true });
  }, [activeRole]);

  function handleMode(row, value) {
    onChange?.(row.mask_role, { blend_mode: value });
  }

  function handleOpacity(row, value) {
    onChange?.(row.mask_role, { blend_opacity: parsePct(value) });
  }

  function handleOffset(row, value) {
    onChange?.(row.mask_role, { shadow_l_offset: parseOffset(value) });
  }

  function handleApplyRow(row) {
    onApply?.(row);
  }

  function handleChangeColor(color) {
    if (!activeRole) return;
    onChange?.(activeRole, { color });
    setActiveRole("");
  }

  return (
    <div className={`mask-grid-table ${showRole ? "show-role" : ""}`}>
      <div className="color-editor-bar" ref={editorRef}>
        <div className="color-editor-title">
          {activeRole ? `Change color for ${(activeRole || "").toUpperCase()}` : "Select a row to change color"}
        </div>
        <FuzzySearchColorSelect
          value={activeRole ? entries[activeRole]?.color || null : null}
          onSelect={handleChangeColor}
          placeholder={activeRole ? "Color name or code" : "Pick a row first"}
          allowManualHex
          showLabel={false}
          compact
          autoFocus={false}
          preventAutoFocus
          suppressFocus={!activeRole}
          manualOpen={false}
        />
        {activeRole && (
          <button type="button" className="btn small" onClick={() => setActiveRole("")}>
            Close
          </button>
        )}
      </div>
      <MaskSettingsTable
        rows={rows}
        showRole={showRole}
        onApply={handleApplyRow}
        onSave={null}
        onShadow={onShadow}
        onDelete={null}
        onChangeMode={handleMode}
        onChangePercent={handleOpacity}
        onChangeOffset={handleOffset}
        onPickColor={(row) => setActiveRole(row.mask_role)}
        activeColorIdByRow={(row) => (activeColorByMask ? activeColorByMask[row.mask_role] : null)}
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

function parseOffset(v) {
  if (v === "" || v == null) return null;
  const num = Number(v);
  if (!Number.isFinite(num)) return null;
  return Math.max(-50, Math.min(50, num));
}
