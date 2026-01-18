import { useEffect, useMemo, useRef, useState } from "react";
import PropTypes from "prop-types";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { API_FOLDER } from "@helpers/config";
import { bucketForLightness, getPresetForBuckets, overlayPresetConfig } from "@config/overlayPresets";
import { resolveBlendOpacity, resolveTargetBucket } from "@helpers/maskRenderUtils";
import MaskSettingsTable from "@components/MaskSettingsTable";
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

const toPercent = (value) => {
  const num = Number(value ?? 0);
  if (!Number.isFinite(num)) return 0;
  return Number((num * 100).toFixed(1));
};

function enrichRow(row) {
  const percent = toPercent(row.blend_opacity ?? 0);
  const shadow = normalizeShadowDraft({
    l_offset: row.shadow_l_offset ?? 0,
    tint_hex: row.shadow_tint_hex || null,
    tint_opacity: row.shadow_tint_opacity ?? 0,
  });
  const approved = !!row.approved;
  return {
    ...row,
    approved,
    draft_approved: approved,
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

function sortRowsByColorName(list = []) {
  return [...list].sort((a, b) => {
    const nameA = (a.color_name || a.color_code || "").toLowerCase();
    const nameB = (b.color_name || b.color_code || "").toLowerCase();
    const primary = nameA.localeCompare(nameB);
    if (primary !== 0) return primary;
    return (a.color_l ?? a.target_lightness ?? 0) - (b.color_l ?? b.target_lightness ?? 0);
  });
}

function sortRowsByTargetLightness(list = []) {
  return [...list].sort((a, b) => {
    const la = a.color_l ?? a.target_lightness ?? a.hcl_l ?? 0;
    const lb = b.color_l ?? b.target_lightness ?? b.hcl_l ?? 0;
    if (la !== lb) return la - lb;
    const nameA = (a.color_name || a.color_code || "").toLowerCase();
    const nameB = (b.color_name || b.color_code || "").toLowerCase();
    return nameA.localeCompare(nameB);
  });
}

export default function MaskBlendHistory({
  assetId,
  maskRole,
  baseLightness,
  selectorVersion = 0,
  onApplyBlend,
  onRowsChange = null,
  onSelectRow = null,
  activeRowId = null,
  activeColorId = null,
  rowTitle = null,
  hideHeader = false,
  filterColorId = null,
  filterColorIds = null,
  forceApproved = null,
  sortByColor = false,
  sortByTargetLightness = false,
  forceSortByBaseLightness = false,
}) {
  const [rows, setRows] = useState([]);
  const rowsRef = useRef(rows);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [newColor, setNewColor] = useState(null);
  const [newMode, setNewMode] = useState("flatpaint");
  const [newOpacity, setNewOpacity] = useState("100");
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

  const tableRows = useMemo(() => {
    return rows.map((row) => {
      const effectiveBase =
        baseLightness != null && Number.isFinite(Number(baseLightness))
          ? Number(baseLightness)
          : row.base_lightness;
      return {
        ...row,
        color_hex: row.color_hex,
        color_name: row.color_name,
        color_brand: row.color_brand,
        color_code: row.color_code,
        color_id: row.color_id,
        base_lightness: effectiveBase,
        target_lightness: row.target_lightness,
        blend_mode: row.draft_mode || row.blend_mode,
        blend_opacity: resolveBlendOpacity(row, 0.5),
        mask_role: row.mask_role || maskRole,
      };
    });
  }, [rows, maskRole, baseLightness]);

  function resetNewRow() {
    setNewColor(null);
    setNewMode("flatpaint");
    setNewOpacity("100");
  }

  useEffect(() => {
    if (!assetId || !maskRole) return;
    fetchRows();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [assetId, maskRole, selectorVersion]);

  useEffect(() => {
    setRows([]);
    setError("");
    setNewColor(null);
    setNewMode("flatpaint");
    setNewOpacity("100");
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
      const nextRows = (data.settings || []).map((row) => enrichRow(row));
      const sorted = sortByTargetLightness
        ? sortRowsByTargetLightness(nextRows)
        : (sortByColor ? sortRowsByColorName(nextRows) : nextRows);
      setRows(sorted);
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

  useEffect(() => {
    if (!onRowsChange) return;
    onRowsChange(rows);
  }, [rows, onRowsChange]);

  useEffect(() => {
    if (!sortByColor) return;
    setRows((prev) => sortRowsByColorName(prev));
  }, [sortByColor]);

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
    const basePercent = Number(row.percent ?? 0);
    const resolvedPercent = Number.isFinite(percentDraft) ? percentDraft : basePercent;
    const percentDirty = Number(resolvedPercent.toFixed(1)) !== Number(basePercent.toFixed(1));
    const shadowDirty = !shadowsEqual(row.draft_shadow, baseShadowForRow(row));
    const approvedDirty = (row.draft_approved ?? row.approved) !== row.approved;
    return modeDirty || percentDirty || shadowDirty || approvedDirty;
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
        blend_opacity: resolveBlendOpacity(latestRow, 0.5),
        shadow_l_offset: shadow.l_offset,
        shadow_tint_hex: shadow.tint_hex,
        shadow_tint_opacity: shadow.tint_opacity,
        approved: 1,
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
    const saved = enrichRow({ ...data.setting, approved: 1 });
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
    const tier = resolveTargetBucket(latestRow, latestRow.target_lightness);
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
    const effectiveMode = (latestRow.draft_mode || latestRow.blend_mode || "").toLowerCase();
    const isOriginal = effectiveMode === ORIGINAL_MODE;
    const tierForOriginal = bucketForLightness(
      latestRow.base_lightness ?? latestRow.target_lightness ?? 60,
      overlayPresetConfig.targetBuckets
    );
    const tierToUse = isOriginal ? tierForOriginal : tier;
    const shadow = normalizeShadowDraft(latestRow.draft_shadow);
    const resolvedOpacity = resolveBlendOpacity(latestRow, 0.5);

    setApplyingRow(latestRow.id);
    setError("");
    try {
      await Promise.resolve(
        onApplyBlend(
          maskRole,
          tierToUse,
          {
            mode: latestRow.draft_mode || latestRow.blend_mode,
            opacity: isOriginal ? 0 : resolvedOpacity,
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
        approved: typeof forceApproved === "number" ? forceApproved : 0,
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
    if (forceSortByBaseLightness) {
      arr.sort((a, b) => {
        const aL = Number(baseLightness != null ? baseLightness : a.base_lightness ?? 0);
        const bL = Number(baseLightness != null ? baseLightness : b.base_lightness ?? 0);
        return aL - bL;
      });
      return arr;
    }
    if (sortByColor) {
      arr.sort((a, b) => {
        const valA = (a.color_name || a.color_code || "").toLowerCase();
        const valB = (b.color_name || b.color_code || "").toLowerCase();
        const primary = valA.localeCompare(valB);
        if (primary !== 0) return primary;
        return (a.target_lightness ?? 0) - (b.target_lightness ?? 0);
      });
      return arr;
    }
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
      const primary = sortDir === "asc" ? valA - valB : valB - valA;
      if (primary !== 0) return primary;
      // secondary sort by base lightness for stability
      const baseA = Number(a.base_lightness ?? 0);
      const baseB = Number(b.base_lightness ?? 0);
      return sortDir === "asc" ? baseA - baseB : baseB - baseA;
    });
    return arr;
  }, [rows, sortField, sortDir, forceSortByBaseLightness, sortByColor, baseLightness]);

  const visibleRows = useMemo(() => {
    if (Array.isArray(filterColorIds) && filterColorIds.length) {
      const allowed = new Set(filterColorIds.map((id) => Number(id)));
      return sortedRows.filter((row) => allowed.has(Number(row.color_id)));
    }
    if (Array.isArray(filterColorIds) && filterColorIds.length === 0) {
      return [];
    }
    if (!filterColorId) return sortedRows;
    const idNum = Number(filterColorId);
    return sortedRows.filter((row) => Number(row.color_id) === idNum);
  }, [sortedRows, filterColorId, filterColorIds]);
  const sortedVisibleRows = useMemo(() => {
    if (!sortByColor) return visibleRows;
    return sortRowsByColorName(visibleRows);
  }, [visibleRows, sortByColor]);

  return (
    <div className="mask-blend-history">
      {!hideHeader && (
        <>
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

        </>
      )}

      {rowTitle && <div className="mbh-row-title">{rowTitle}</div>}
      <MaskSettingsTable
        rows={sortedVisibleRows}
        onSelectRow={onSelectRow}
        activeRowId={activeRowId}
        activeColorId={activeColorId}
        onChangeMode={(row, value) => updateRowDraft(row.id, "draft_mode", value)}
        onChangePercent={(row, value) => updateRowDraft(row.id, "draft_percent", value)}
        onChangeOffset={(row, value) => updateRowShadowDraft(row.id, "l_offset", value)}
        onToggleApproved={(row, checked) => updateRowDraft(row.id, "draft_approved", checked)}
        onApply={(row) => {
          if (applyingRow === row.id) return;
          handleApplyRow(row);
        }}
        onSave={(row) => {
          if (savingRow === row.id) return;
          handleSaveRow(row);
        }}
        onShadow={(row) => {
          const rowModeLower = (row.draft_mode || row.blend_mode || "").toLowerCase();
          if (rowModeLower === ORIGINAL_MODE) return;
          toggleShadowEditor(row.id);
        }}
        onDelete={(row) => handleDeleteRow(row)}
        renderExtraRow={(row) => {
          if (shadowEditorRow !== row.id) return null;
          return (
            <tr key={`${row.id}-shadow`} className="mbh-shadow-row">
              <td colSpan={7}>
                <div className="mbh-shadow-editor">
                  <label>
                    Offset (L)
                    <input
                      type="text"
                      inputMode="text"
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
                          toPercent(preset.tint_opacity ?? 0) === toPercent(row.draft_shadow.tint_opacity ?? 0)
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
                      step="0.1"
                      inputMode="decimal"
                      value={toPercent(row.draft_shadow.tint_opacity || 0)}
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
          );
        }}
      />
      {!loading && !rows.length && (
        <div className="mbh-empty">No tests yet. Add a color above to get started.</div>
      )}
    </div>
  );
}

MaskBlendHistory.propTypes = {
  assetId: PropTypes.string,
  maskRole: PropTypes.string.isRequired,
  baseLightness: PropTypes.number,
  onApplyBlend: PropTypes.func,
  onRowsChange: PropTypes.func,
  rowTitle: PropTypes.oneOfType([PropTypes.string, PropTypes.node]),
  hideHeader: PropTypes.bool,
  filterColorId: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  filterColorIds: PropTypes.arrayOf(
    PropTypes.oneOfType([PropTypes.string, PropTypes.number])
  ),
  forceApproved: PropTypes.number,
  sortByColor: PropTypes.bool,
  sortByTargetLightness: PropTypes.bool,
  forceSortByBaseLightness: PropTypes.bool,
};

MaskBlendHistory.defaultProps = {
  assetId: "",
  baseLightness: null,
  onApplyBlend: null,
  onRowsChange: null,
  rowTitle: null,
  hideHeader: false,
  filterColorId: null,
  filterColorIds: null,
  forceApproved: null,
  sortByColor: false,
  sortByTargetLightness: false,
  forceSortByBaseLightness: false,
};
