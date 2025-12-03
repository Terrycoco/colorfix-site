import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import PropTypes from "prop-types";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { API_FOLDER } from "@helpers/config";
import { bucketForLightness, getPresetForBuckets, overlayPresetConfig } from "@config/overlayPresets";
import "./maskblendhistory.css";

function normalizeHex(hex) {
  if (!hex) return "";
  const h = hex.startsWith("#") ? hex.slice(1) : hex;
  return h.toUpperCase();
}

function lightnessFromColor(color) {
  if (!color) return null;
  if (typeof color.lightness === "number") return color.lightness;
  if (typeof color.lab_l === "number") return color.lab_l;
  if (typeof color.hcl_l === "number") return color.hcl_l;
  return null;
}

function normalizeShadowDraft(shadow) {
  const src = shadow && typeof shadow === "object" ? shadow : {};
  const offset = typeof src.l_offset === "number" && Number.isFinite(src.l_offset)
    ? Math.max(-50, Math.min(50, src.l_offset))
    : 0;
  let tint = src.tint_hex || null;
  if (typeof tint === "string" && tint) {
    let val = tint.trim();
    if (!val.startsWith("#")) val = `#${val}`;
    if (/^#?[0-9A-F]{3}$/i.test(val)) {
      const h = val.replace("#", "").toUpperCase();
      val = `#${h[0]}${h[0]}${h[1]}${h[1]}${h[2]}${h[2]}`;
    }
    tint = /^#[0-9A-F]{6}$/i.test(val) ? val.toUpperCase() : null;
  } else {
    tint = null;
  }
  const opacity = typeof src.tint_opacity === "number" && Number.isFinite(src.tint_opacity)
    ? Math.max(0, Math.min(1, src.tint_opacity))
    : 0;
  return {
    l_offset: Number(offset.toFixed(1)),
    tint_hex: tint,
    tint_opacity: Number(opacity.toFixed(2)),
  };
}

const SHADOW_PRESETS = [
  { id: "none", label: "None", tint_hex: null, tint_opacity: 0 },
  { id: "soft-gray", label: "Soft Gray 20%", tint_hex: "#4A4A4A", tint_opacity: 0.2 },
  { id: "charcoal", label: "Charcoal 35%", tint_hex: "#1C1C1E", tint_opacity: 0.35 },
  { id: "cool-blue", label: "Moody Blue 30%", tint_hex: "#2C3E50", tint_opacity: 0.3 },
  { id: "warm-amber", label: "Warm Amber 18%", tint_hex: "#6A3A1E", tint_opacity: 0.18 },
];

const ORIGINAL_MODE = "original";

function enrichRow(row) {
  const percent = Math.round((row.blend_opacity ?? 0) * 100);
  const shadow = normalizeShadowDraft({
    l_offset: row.shadow_l_offset ?? 0,
    tint_hex: row.shadow_tint_hex || null,
    tint_opacity: row.shadow_tint_opacity ?? 0,
  });
  return {
    ...row,
    percent,
    draft_mode: row.blend_mode,
    draft_percent: percent,
    draft_shadow: shadow,
    shadow_l_offset: shadow.l_offset,
    shadow_tint_hex: shadow.tint_hex,
    shadow_tint_opacity: shadow.tint_opacity,
  };
}

function iconKeyHandler(e, handler) {
  if (e.key === "Enter" || e.key === " ") {
    e.preventDefault();
    handler();
  }
}

export default function MaskBlendHistory({
  assetId,
  maskRole,
  baseLightness,
  onApplyBlend,
}) {
  const [rows, setRows] = useState([]);
  const rowsRef = useRef(rows);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [newColor, setNewColor] = useState(null);
  const [newMode, setNewMode] = useState("flatpaint");
  const [newOpacity, setNewOpacity] = useState("100");
  const [selectorVersion, setSelectorVersion] = useState(0);
  const [savingRow, setSavingRow] = useState(null);
  const [applyingRow, setApplyingRow] = useState(null);
  const [sortField, setSortField] = useState("target_lightness");
  const [sortDir, setSortDir] = useState("asc");
  const renderSortIcon = (field) =>
    sortField === field ? (sortDir === "asc" ? "↑" : "↓") : "";
  const [shadowEditorRow, setShadowEditorRow] = useState(null);
  const [savingAll, setSavingAll] = useState(false);
  const newModeLower = (newMode || "").toLowerCase();
  const isNewOriginal = newModeLower === ORIGINAL_MODE;

  function resetNewRow() {
    setNewColor(null);
    setNewMode("flatpaint");
    setNewOpacity("100");
    setSelectorVersion((v) => v + 1);
  }

  useEffect(() => {
    if (!assetId || !maskRole) return;
    fetchRows();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [assetId, maskRole]);

  useEffect(() => {
    setNewColor(null);
    setNewMode("flatpaint");
    setNewOpacity("100");
    setSelectorVersion((v) => v + 1);
    setShadowEditorRow(null);
  }, [assetId, maskRole]);

  useEffect(() => {
    if (isNewOriginal) {
      setNewColor(null);
      setNewOpacity("0");
    }
  }, [isNewOriginal]);

  async function fetchRows() {
    if (!assetId || !maskRole) return;
    setLoading(true);
    setError("");
    try {
      const cacheBust = `_=${Date.now()}`;
      const res = await fetch(
        `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(maskRole)}&${cacheBust}`,
        { credentials: "include", cache: "no-store" }
      );
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Load failed");
      }
      setRows((data.settings || []).map((row) => enrichRow(row)));
    } catch (err) {
      setError(err?.message || "Failed to load settings");
    } finally {
      setLoading(false);
    }
  }

  function updateRowDraft(id, field, value) {
    setRows((prev) =>
      prev.map((row) =>
        row.id === id
          ? {
              ...row,
              [field]: value,
            }
          : row
      )
    );
  }

function updateRowShadowDraft(id, field, value) {
    setRows((prev) =>
      prev.map((row) => {
        if (row.id !== id) return row;
        const next = { ...row.draft_shadow };
        const nextInput = { ...(row.draft_shadow_input || {}) };
        if (field === "l_offset") {
          if (value === "" || value === "-" || value === "+") {
            nextInput.l_offset = value;
            return { ...row, draft_shadow_input: nextInput };
          }
          const parsed = parseFloat(value);
          if (!Number.isFinite(parsed)) {
            return row;
          }
          next.l_offset = Math.max(-50, Math.min(50, parsed));
          delete nextInput.l_offset;
        } else if (field === "tint_hex") {
          next.tint_hex = value ? value.toUpperCase() : null;
        } else if (field === "tint_opacity") {
          const parsed = Number(value);
          if (!Number.isFinite(parsed)) {
            next.tint_opacity = 0;
          } else {
            next.tint_opacity = Math.max(0, Math.min(1, parsed));
          }
        }
        const updated = { ...row, draft_shadow: next };
        if (Object.keys(nextInput).length) {
          updated.draft_shadow_input = nextInput;
        } else {
          delete updated.draft_shadow_input;
        }
        return updated;
      })
    );
  }

  function revertRowShadow(id) {
    setRows((prev) =>
      prev.map((row) => {
        if (row.id !== id) return row;
        const base = normalizeShadowDraft({
          l_offset: row.shadow_l_offset ?? 0,
          tint_hex: row.shadow_tint_hex || null,
          tint_opacity: row.shadow_tint_opacity ?? 0,
        });
        const updated = { ...row, draft_shadow: base };
        if (row.draft_shadow_input) {
          delete updated.draft_shadow_input;
        }
        return updated;
      })
    );
  }

  function toggleShadowEditor(rowId) {
    setShadowEditorRow((prev) => (prev === rowId ? null : rowId));
  }

  useEffect(() => {
    rowsRef.current = rows;
  }, [rows]);

  const baseShadowForRow = (row) =>
    normalizeShadowDraft({
      l_offset: row.shadow_l_offset ?? 0,
      tint_hex: row.shadow_tint_hex || null,
      tint_opacity: row.shadow_tint_opacity ?? 0,
    });

  function shadowsEqual(a, b) {
    const round = (val) => (Number.isFinite(val) ? Number(val.toFixed(3)) : 0);
    return (
      round(a?.l_offset ?? 0) === round(b?.l_offset ?? 0) &&
      (a?.tint_hex || null) === (b?.tint_hex || null) &&
      Math.round(((a?.tint_opacity ?? 0) * 1000)) === Math.round(((b?.tint_opacity ?? 0) * 1000))
    );
  }

  function isRowDirty(row) {
    const modeDirty = (row.draft_mode || row.blend_mode) !== row.blend_mode;
    const percentDraft = Number(row.draft_percent ?? row.percent);
    const resolvedPercent = Number.isFinite(percentDraft) ? percentDraft : row.percent;
    const percentDirty = Math.round(resolvedPercent) !== Math.round(row.percent);
    const shadowDirty = !shadowsEqual(row.draft_shadow, baseShadowForRow(row));
    return modeDirty || percentDirty || shadowDirty;
  }

  const dirtyRowIds = useMemo(() => rows.filter(isRowDirty).map((row) => row.id), [rows]);

  useEffect(() => {
    if (shadowEditorRow == null) return;
    const targetRow = rows.find((row) => row.id === shadowEditorRow);
    if (!targetRow) {
      setShadowEditorRow(null);
      return;
    }
    const modeLower = (targetRow.draft_mode || targetRow.blend_mode || "").toLowerCase();
    if (modeLower === ORIGINAL_MODE) {
      setShadowEditorRow(null);
    }
  }, [rows, shadowEditorRow]);

  async function persistRowById(rowId) {
    const latestRow = rowsRef.current.find((r) => r.id === rowId);
    if (!latestRow) return null;
    const shadow = normalizeShadowDraft(latestRow.draft_shadow);
    const payload = {
      asset_id: assetId,
      mask: maskRole,
      entry: {
        id: latestRow.id,
        color_id: latestRow.color_id,
        color_name: latestRow.color_name,
        color_brand: latestRow.color_brand,
        color_code: latestRow.color_code,
        color_hex: latestRow.color_hex,
        base_lightness: latestRow.base_lightness,
        target_lightness: latestRow.target_lightness,
        target_h: latestRow.target_h,
        target_c: latestRow.target_c,
        blend_mode: latestRow.draft_mode || latestRow.blend_mode,
        blend_opacity: (Number(latestRow.draft_percent ?? latestRow.percent) || 0) / 100,
        shadow_l_offset: shadow.l_offset,
        shadow_tint_hex: shadow.tint_hex,
        shadow_tint_opacity: shadow.tint_opacity,
      },
    };
    const res = await fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
    const saved = enrichRow(data.setting);
    setRows((prev) => prev.map((r) => (r.id === saved.id ? saved : r)));
    return saved;
  }

  async function handleSaveRow(row) {
    const targetId = row?.id;
    if (!targetId) return;
    setSavingRow(targetId);
    setError("");
    try {
      await persistRowById(targetId);
    } catch (err) {
      setError(err?.message || "Failed to save row");
    } finally {
      setSavingRow(null);
    }
  }

  async function handleSaveAll() {
    if (!dirtyRowIds.length || savingAll) return;
    setSavingAll(true);
    setError("");
    try {
      for (const rowId of dirtyRowIds) {
        setSavingRow(rowId);
        await persistRowById(rowId);
      }
    } catch (err) {
      setError(err?.message || "Failed to save all");
    } finally {
      setSavingRow(null);
      setSavingAll(false);
    }
  }

  async function handleDeleteRow(row) {
    if (!window.confirm("Delete this test?")) return;
    try {
      await fetch(`${API_FOLDER}/v2/admin/mask-blend/delete.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_id: assetId, mask: maskRole, id: row.id }),
      });
      setRows((prev) => prev.filter((r) => r.id !== row.id));
      setShadowEditorRow((prev) => (prev === row.id ? null : prev));
      await fetchRows();
    } catch (err) {
      setError(err?.message || "Failed to delete row");
    }
  }

  async function handleApplyRow(row) {
    const latestRow = rowsRef.current.find((r) => r.id === row.id) ?? row;
    if (!onApplyBlend) return;
    const tier =
      latestRow.target_bucket ||
      bucketForLightness(latestRow.target_lightness, overlayPresetConfig.targetBuckets);
    const rowSwatch = {
      id: latestRow.color_id,
      color_id: latestRow.color_id,
      name: latestRow.color_name || latestRow.color_code || "",
      brand: latestRow.color_brand || "",
      color_brand: latestRow.color_brand || "",
      code: latestRow.color_code || "",
      color_code: latestRow.color_code || "",
      hex6: latestRow.color_hex,
      lightness: latestRow.target_lightness,
      hcl_l: latestRow.target_lightness,
      hcl_h: latestRow.target_h,
      hcl_c: latestRow.target_c,
    };
    const effectiveMode = (latestRow.draft_mode || latestRow.blend_mode || '').toLowerCase();
    const isOriginal = effectiveMode === ORIGINAL_MODE;
    const tierForOriginal = bucketForLightness(
      latestRow.base_lightness ?? latestRow.target_lightness ?? 60,
      overlayPresetConfig.targetBuckets
    );
    const tierToUse = isOriginal ? tierForOriginal : tier;
    const shadow = normalizeShadowDraft(latestRow.draft_shadow);

    setApplyingRow(latestRow.id);
    setError("");
    try {
      await Promise.resolve(
        onApplyBlend(
          maskRole,
          tierToUse,
          {
            mode: latestRow.draft_mode || latestRow.blend_mode,
            opacity: isOriginal ? 0 : (Number(latestRow.draft_percent ?? latestRow.percent) || 0) / 100,
          },
          { swatch: isOriginal ? null : rowSwatch, shadow, clearColor: isOriginal }
        )
      );
    } catch (err) {
      setError(err?.message || "Failed to apply blend");
    } finally {
      setApplyingRow(null);
    }
  }

  const newColorLightness = useMemo(() => lightnessFromColor(newColor), [newColor]);
  const newTargetBucket = useMemo(() => {
    if (newColorLightness == null) return null;
    return bucketForLightness(newColorLightness, overlayPresetConfig.targetBuckets);
  }, [newColorLightness]);

  useEffect(() => {
    if (!newColor || newColorLightness == null) return;
    const baseBucket = bucketForLightness(baseLightness ?? newColorLightness, overlayPresetConfig.baseBuckets);
    const preset = getPresetForBuckets(baseBucket, newTargetBucket);
    setNewMode("flatpaint");
    setNewOpacity("100");
  }, [newColor, newColorLightness, newTargetBucket, baseLightness]);

  async function handleSaveNew() {
    const isOriginalNew = (newMode || "").toLowerCase() === ORIGINAL_MODE;
    if (!isOriginalNew && (!newColor || newColorLightness == null)) return;
    const defaultShadow = { l_offset: 0, tint_hex: null, tint_opacity: 0 };
    const payload = {
      asset_id: assetId,
      mask: maskRole,
      entry: {
        color_id: isOriginalNew ? null : (newColor?.id ?? newColor?.color_id ?? null),
        color_name: isOriginalNew ? "Original Photo" : (newColor?.name || newColor?.code || ""),
        color_brand: isOriginalNew ? null : (newColor?.brand || ""),
        color_code: isOriginalNew ? null : (newColor?.code || ""),
        color_hex: isOriginalNew ? "000000" : normalizeHex(newColor?.hex6 || newColor?.hex),
        base_lightness: baseLightness ?? newColorLightness ?? 0,
        target_lightness: isOriginalNew ? (baseLightness ?? newColorLightness ?? 0) : newColorLightness,
        target_h: isOriginalNew ? null : (newColor?.hcl_h ?? newColor?.h ?? null),
        target_c: isOriginalNew ? null : (newColor?.hcl_c ?? newColor?.c ?? null),
        blend_mode: newMode || "colorize",
        blend_opacity: isOriginalNew ? 0 : (Number(newOpacity) || 0) / 100,
        shadow_l_offset: defaultShadow.l_offset,
        shadow_tint_hex: defaultShadow.tint_hex,
        shadow_tint_opacity: defaultShadow.tint_opacity,
      },
    };
    setSavingRow(-1);
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
      const saved = enrichRow(data.setting);
      setNewColor(null);
      setNewMode("flatpaint");
      setNewOpacity("100");
      setRows((prev) => {
        const filtered = prev.filter((row) => row.id !== saved.id);
        return [...filtered, saved];
      });
      await fetchRows();
    } catch (err) {
      setError(err?.message || "Failed to save test");
    } finally {
      setSavingRow(null);
    }
  }

  async function handleApplyNew() {
    const isOriginal = (newMode || "").toLowerCase() === ORIGINAL_MODE;
    if (!onApplyBlend) return;
    if (!isOriginal && (!newColor || newTargetBucket == null)) return;
    setApplyingRow("new");
    setError("");
    try {
      const fallbackBucket = bucketForLightness(
        baseLightness ?? newColorLightness ?? 60,
        overlayPresetConfig.targetBuckets
      );
      await Promise.resolve(
        onApplyBlend(
          maskRole,
          isOriginal ? fallbackBucket : newTargetBucket,
          {
            mode: newMode || "colorize",
            opacity: isOriginal ? 0 : (Number(newOpacity) || 0) / 100,
          },
          { swatch: isOriginal ? null : newColor, clearColor: isOriginal }
        )
      );
    } catch (err) {
      setError(err?.message || "Failed to apply blend");
    } finally {
      setApplyingRow(null);
    }
  }

  function updateSort(field) {
    setSortField((prev) => {
      if (prev === field) {
        setSortDir((d) => (d === "asc" ? "desc" : "asc"));
        return prev;
      }
      setSortDir("asc");
      return field;
    });
  }

  const sortedRows = useMemo(() => {
    const arr = [...rows];
    arr.sort((a, b) => {
      let valA;
      let valB;
      switch (sortField) {
        case "color_name":
          valA = (a.color_name || a.color_code || "").toLowerCase();
          valB = (b.color_name || b.color_code || "").toLowerCase();
          break;
        case "blend_mode":
          valA = a.draft_mode || a.blend_mode;
          valB = b.draft_mode || b.blend_mode;
          break;
        case "blend_opacity":
          valA = Number(a.draft_percent ?? a.percent);
          valB = Number(b.draft_percent ?? b.percent);
          break;
        case "target_lightness":
        default:
          valA = a.target_lightness;
          valB = b.target_lightness;
          break;
      }
      if (typeof valA === "string") {
        return sortDir === "asc" ? valA.localeCompare(valB) : valB.localeCompare(valA);
      }
      return sortDir === "asc" ? valA - valB : valB - valA;
    });
    return arr;
  }, [rows, sortField, sortDir]);

  return (
    <div className="mask-blend-history">
      <div className="mbh-header">
        <div>
          <strong>Tested Colors</strong>
          {loading && <span className="mbh-status">Loading…</span>}
          {error && <span className="mbh-error">{error}</span>}
        </div>
        <div className="mbh-header-actions">
          <button
            type="button"
            className="btn btn-text"
            onClick={() => {
              resetNewRow();
              setTimeout(() => {
                const el = document.querySelector(".mbh-add-table .fuzzy-dropdown input");
                el?.focus();
              }, 0);
            }}
          >
            New Color
          </button>
          <button
            type="button"
            className="btn btn-text"
            disabled={savingAll || !dirtyRowIds.length}
            onClick={handleSaveAll}
          >
            {savingAll ? "Saving…" : `Save All${dirtyRowIds.length ? ` (${dirtyRowIds.length})` : ""}`}
          </button>
          <button type="button" className="btn btn-text" onClick={fetchRows}>
            Refresh
          </button>
        </div>
      </div>

      <div className="mbh-add-table">
        <table>
          <thead>
            <tr>
              <th>Color</th>
              <th className="col-lightness">L</th>
              <th className="col-mode">Mode</th>
              <th className="col-percent">%</th>
              <th className="col-actions">Actions</th>
           </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <FuzzySearchColorSelect
                  key={selectorVersion}
                  onSelect={(color) => setNewColor(color)}
                  onEmpty={() => setNewColor(null)}
                  value={newColor}
                  autoFocus={false}
                  label=""
                  showLabel={false}
                />
              </td>
              <td className="mbh-grid-target">
                {isNewOriginal
                  ? (baseLightness != null ? Math.round(baseLightness) : "—")
                  : (newColorLightness != null ? Math.round(newColorLightness) : "—")}
              </td>
              <td className="mbh-grid-mode">
              <select value={newMode} onChange={(e) => setNewMode(e.target.value)}>
                <option value="colorize">Colorize</option>
                <option value="softlight">Soft Light</option>
                <option value="overlay">Overlay</option>
                <option value="multiply">Multiply</option>
                <option value="screen">Screen</option>
                <option value="hardlight">Hard Light</option>
                <option value="luminosity">Luminosity</option>
                <option value="flatpaint">Flat Paint</option>
                <option value="original">Original Photo</option>
              </select>
              </td>
              <td className="mbh-grid-percent">
                <input
                  type="number"
                  min="0"
                  max="100"
                  value={isNewOriginal ? "0" : newOpacity}
                  disabled={isNewOriginal}
                  onChange={(e) => setNewOpacity(e.target.value)}
                />
              </td>
              <td className="mbh-grid-actions">
                <div
                  className="mbh-pill-btn"
                  role="button"
                  tabIndex={applyingRow === "new" || (!isNewOriginal && !newColor) ? -1 : 0}
                  aria-disabled={applyingRow === "new" || (!isNewOriginal && !newColor)}
                  onClick={() => {
                    if (applyingRow === "new" || (!isNewOriginal && !newColor)) return;
                    handleApplyNew();
                  }}
                  onKeyDown={(e) => {
                    if (applyingRow === "new" || (!isNewOriginal && !newColor)) return;
                    iconKeyHandler(e, handleApplyNew);
                  }}
                >
                  {applyingRow === "new" ? "Applying…" : "Apply"}
                </div>
                <div
                  className="mbh-pill-btn primary"
                  role="button"
                  tabIndex={savingRow === -1 || (!isNewOriginal && !newColor) ? -1 : 0}
                  aria-disabled={savingRow === -1 || (!isNewOriginal && !newColor)}
                  onClick={() => {
                    if (savingRow === -1 || (!isNewOriginal && !newColor)) return;
                    handleSaveNew();
                  }}
                  onKeyDown={(e) => {
                    if (savingRow === -1 || (!isNewOriginal && !newColor)) return;
                    iconKeyHandler(e, handleSaveNew);
                  }}
                >
                  {savingRow === -1 ? "Saving…" : "Save"}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="mbh-table-wrapper">
        <table className="mbh-table">
 
          <tbody>
            {sortedRows.map((row) => {
              const rowModeLower = (row.draft_mode || row.blend_mode || "").toLowerCase();
              const rowIsOriginal = rowModeLower === ORIGINAL_MODE;
              const displayName = rowIsOriginal
                ? "Original Photo"
                : (row.color_name || row.color_code || row.color_hex);
              return (
              <Fragment key={row.id}>
              <tr>
                <td>
                  <div className="mbh-color-cell">
                    <div
                      className={`mbh-color-swatch ${rowIsOriginal ? "is-original" : ""}`}
                      style={rowIsOriginal ? undefined : { backgroundColor: `#${row.color_hex}` }}
                    />
                    <div className="mbh-color-meta">
                      <div>{displayName}</div>
                      <div className="mbh-color-sub">
                        {row.color_brand || ""} · Base L {Math.round(row.base_lightness)}
                      </div>
                    </div>
                  </div>
                </td>
                <td>{Math.round(row.target_lightness)}</td>
                <td>
                  <select
                    value={row.draft_mode}
                    onChange={(e) => updateRowDraft(row.id, "draft_mode", e.target.value)}
                  >
                    <option value="colorize">Colorize</option>
                    <option value="softlight">Soft Light</option>
                    <option value="overlay">Overlay</option>
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
                    value={row.draft_percent}
                    onChange={(e) => updateRowDraft(row.id, "draft_percent", e.target.value)}
                    disabled={rowIsOriginal}
                  />
                </td>
                <td className="mbh-actions">
                  <div className="mbh-action-icons">
                    <div
                      className="icon-btn"
                      role="button"
                      tabIndex={applyingRow === row.id ? -1 : 0}
                      aria-disabled={applyingRow === row.id}
                      title="Apply test"
                      onClick={() => {
                        if (applyingRow === row.id) return;
                        handleApplyRow(row);
                      }}
                      onKeyDown={(e) => {
                        if (applyingRow === row.id) return;
                        iconKeyHandler(e, () => handleApplyRow(row));
                      }}
                    >
                      <span className="icon icon-play" aria-hidden="true" />
                      <span className="sr-only">Apply</span>
                    </div>
                    <div
                      className="icon-btn"
                      role="button"
                      tabIndex={savingRow === row.id ? -1 : 0}
                      aria-disabled={savingRow === row.id}
                      title="Save test"
                      onClick={() => {
                        if (savingRow === row.id) return;
                        handleSaveRow(row);
                      }}
                      onKeyDown={(e) => {
                        if (savingRow === row.id) return;
                        iconKeyHandler(e, () => handleSaveRow(row));
                      }}
                    >
                      <span className="icon icon-save" aria-hidden="true" />
                      <span className="sr-only">Save</span>
                    </div>
                    <div
                      className={`icon-btn ${shadowEditorRow === row.id ? "active" : ""}`}
                      role="button"
                      tabIndex={rowIsOriginal ? -1 : 0}
                      title="Shadow settings"
                      aria-pressed={shadowEditorRow === row.id}
                      aria-disabled={rowIsOriginal}
                      onClick={() => {
                        if (rowIsOriginal) return;
                        toggleShadowEditor(row.id);
                      }}
                      onKeyDown={(e) => {
                        if (rowIsOriginal) return;
                        iconKeyHandler(e, () => toggleShadowEditor(row.id));
                      }}
                    >
                      <span className="icon icon-shadow" aria-hidden="true" />
                      <span className="sr-only">Shadow settings</span>
                    </div>
                    <div
                      className="icon-btn danger"
                      role="button"
                      tabIndex={0}
                      title="Delete test"
                      onClick={() => handleDeleteRow(row)}
                      onKeyDown={(e) => iconKeyHandler(e, () => handleDeleteRow(row))}
                    >
                      <span className="icon icon-delete" aria-hidden="true" />
                      <span className="sr-only">Delete</span>
                    </div>
                  </div>
                </td>
              </tr>
              {shadowEditorRow === row.id && (
                <tr key={`${row.id}-shadow`} className="mbh-shadow-row">
                  <td colSpan={5}>
                    <div className="mbh-shadow-editor">
                      <label>
                        Offset (L)
                        <input
                          type="text"
                          inputMode="numeric"
                          pattern="-?[0-9]*"
                          value={
                            row.draft_shadow_input?.l_offset ??
                            (typeof row.draft_shadow.l_offset === "number"
                              ? String(row.draft_shadow.l_offset)
                              : "")
                          }
                          onChange={(e) => updateRowShadowDraft(row.id, "l_offset", e.target.value)}
                          onBlur={(e) => updateRowShadowDraft(row.id, "l_offset", e.target.value)}
                        />
                      </label>
                      <label>
                        Tint Preset
                        <select
                          value={
                            (SHADOW_PRESETS.find((preset) =>
                              preset.tint_hex === (row.draft_shadow.tint_hex || null) &&
                              Math.round((preset.tint_opacity ?? 0) * 100) === Math.round((row.draft_shadow.tint_opacity ?? 0) * 100)
                            )?.id) || "custom"
                          }
                          onChange={(e) => {
                            const preset = SHADOW_PRESETS.find((p) => p.id === e.target.value);
                            if (!preset) {
                              updateRowShadowDraft(row.id, "tint_hex", row.draft_shadow.tint_hex);
                              return;
                            }
                            updateRowShadowDraft(row.id, "tint_hex", preset.tint_hex);
                            updateRowShadowDraft(row.id, "tint_opacity", preset.tint_opacity);
                          }}
                        >
                          <option value="custom">Custom</option>
                          {SHADOW_PRESETS.map((preset) => (
                            <option key={preset.id} value={preset.id}>
                              {preset.label}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label>
                        Tint Color
                        <div className="mbh-shadow-color">
                          <input
                            type="color"
                            value={row.draft_shadow.tint_hex || "#000000"}
                            onChange={(e) => updateRowShadowDraft(row.id, "tint_hex", e.target.value)}
                          />
                          <div className="mbh-shadow-hex">{row.draft_shadow.tint_hex || "none"}</div>
                          <div
                            className="mbh-pill-btn ghost"
                            role="button"
                            tabIndex={0}
                            onClick={() => updateRowShadowDraft(row.id, "tint_hex", null)}
                            onKeyDown={(e) => iconKeyHandler(e, () => updateRowShadowDraft(row.id, "tint_hex", null))}
                          >
                            Clear
                          </div>
                        </div>
                      </label>
                      <label>
                        Tint %
                        <input
                          type="number"
                          min="0"
                          max="100"
                          value={Math.round((row.draft_shadow.tint_opacity || 0) * 100)}
                          onChange={(e) =>
                            updateRowShadowDraft(row.id, "tint_opacity", Math.max(0, Math.min(1, Number(e.target.value) / 100)))
                          }
                        />
                      </label>
                      <div className="mbh-shadow-buttons">
                        <div
                          className="mbh-pill-btn ghost"
                          role="button"
                          tabIndex={0}
                          onClick={() => revertRowShadow(row.id)}
                          onKeyDown={(e) => iconKeyHandler(e, () => revertRowShadow(row.id))}
                        >
                          Reset
                        </div>
                        <div
                          className="mbh-pill-btn"
                          role="button"
                          tabIndex={savingRow === row.id ? -1 : 0}
                          aria-disabled={savingRow === row.id}
                          onClick={() => {
                            if (savingRow === row.id) return;
                            handleSaveRow(row);
                          }}
                          onKeyDown={(e) => {
                            if (savingRow === row.id) return;
                            iconKeyHandler(e, () => handleSaveRow(row));
                          }}
                        >
                          Save Row
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              )}
              </Fragment>
              );
            })}
            {!loading && !rows.length && (
              <tr>
                <td colSpan={6} className="mbh-empty">
                  No tests yet. Add a color above to get started.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

MaskBlendHistory.propTypes = {
  assetId: PropTypes.string,
  maskRole: PropTypes.string.isRequired,
  baseLightness: PropTypes.number,
  onApplyBlend: PropTypes.func,
};

MaskBlendHistory.defaultProps = {
  assetId: "",
  baseLightness: null,
  onApplyBlend: null,
};
