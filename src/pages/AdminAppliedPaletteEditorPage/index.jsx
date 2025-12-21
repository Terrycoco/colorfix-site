import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { buildPreviewAssignments, normalizeEntryForSave, cleanHex, normalizeShadowStruct } from "@helpers/maskRenderUtils";
import MaskRoleGrid from "@components/MaskRoleGrid";
import "./ap-editor.css";

const GET_URL = `${API_FOLDER}/v2/admin/applied-palettes/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/applied-palettes/update-entries.php`;
const SAVE_NEW_URL = `${API_FOLDER}/v2/admin/applied-palettes/save.php`;
const CLEAR_URL = `${API_FOLDER}/v2/admin/applied-palettes/clear-render.php`;
const PREVIEW_URL = `${API_FOLDER}/v2/admin/applied-palettes/preview.php`;

const BLEND_OPTIONS = [
  { value: "", label: "Default" },
  { value: "colorize", label: "Colorize" },
  { value: "hardlight", label: "Hard Light" },
  { value: "softlight", label: "Soft Light" },
  { value: "overlay", label: "Overlay" },
  { value: "multiply", label: "Multiply" },
  { value: "screen", label: "Screen" },
  { value: "luminosity", label: "Luminosity" },
  { value: "flatpaint", label: "Flat Paint" },
  { value: "original", label: "Original Photo" },
];

export default function AdminAppliedPaletteEditorPage() {
  const { paletteId } = useParams();
  const navigate = useNavigate();
  const origin = typeof window !== "undefined" ? window.location.origin : "";

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [palette, setPalette] = useState(null);
  const [asset, setAsset] = useState(null);
  const [assetError, setAssetError] = useState("");
  const [renderInfo, setRenderInfo] = useState(null);
  const [entryMap, setEntryMap] = useState({});
  const [entryBaseline, setEntryBaseline] = useState({});
  const [meta, setMeta] = useState({ title: "", notes: "", tags: "" });
  const [metaBaseline, setMetaBaseline] = useState({ title: "", notes: "", tags: "" });
  const [saving, setSaving] = useState(false);
  const [saveStatus, setSaveStatus] = useState("");
  const [saveError, setSaveError] = useState("");
  const [clearStatus, setClearStatus] = useState("");
  const [clearError, setClearError] = useState("");
  const [clearCache, setClearCache] = useState(true);
  const [previewAssignments, setPreviewAssignments] = useState({});
  const [previewVersion, setPreviewVersion] = useState(0);
  const [previewState, setPreviewState] = useState({ loading: false, error: "" });
  const [autoPreview, setAutoPreview] = useState(false);
  const [previewMode, setPreviewMode] = useState("edits"); // saved | edits
  const [savedImageError, setSavedImageError] = useState(false);
  const [previewImage, setPreviewImage] = useState({ url: "", ts: 0, error: "" });
  const [fullPreviewUrl, setFullPreviewUrl] = useState("");

  useEffect(() => {
    if (!paletteId) return;
    navigate(`/admin/mask-tester?ap=${paletteId}&view=all`, { replace: true });
  }, [paletteId, navigate]);

  const previewSource = useMemo(() => buildPreviewAssignments(entryMap), [entryMap]);
  const maskRows = useMemo(() => {
    const maskMap = {};
    (asset?.masks || []).forEach((m) => {
      maskMap[m.role] = { role: m.role, base_lightness: m.base_lightness, original_texture: m.original_texture };
    });
    // keep any roles present in current or baseline entries so cleared colors don't hide the row
    [entryMap, entryBaseline].forEach((map) => {
      Object.keys(map || {}).forEach((mask) => {
        if (!maskMap[mask]) maskMap[mask] = { role: mask };
      });
    });
    return Object.values(maskMap).sort((a, b) => (a.role || "").localeCompare(b.role || ""));
  }, [asset?.masks, entryMap, entryBaseline]);

  const entriesForSave = useMemo(() => {
    return Object.values(entryMap)
      .map((row) => normalizeEntryForSave(row))
      .filter(Boolean);
  }, [entryMap]);

  const hasChanges = useMemo(() => {
    if (meta.title !== metaBaseline.title || meta.notes !== metaBaseline.notes || meta.tags !== metaBaseline.tags) {
      return true;
    }
    return serializeEntryMap(entryMap) !== serializeEntryMap(entryBaseline);
  }, [entryMap, entryBaseline, meta, metaBaseline]);


  useEffect(() => {
    if (!autoPreview) return;
    const t = setTimeout(() => {
      setPreviewAssignments({ ...previewSource });
      setPreviewVersion((v) => v + 1);
      triggerPreviewRender("edits");
    }, 200);
    return () => clearTimeout(t);
  }, [previewSource, autoPreview]);

  useEffect(() => {
    if (!Object.keys(previewAssignments || {}).length) {
      setPreviewState({ loading: false, error: "" });
    }
  }, [previewAssignments]);

  const renderUrl = renderInfo?.render_rel_path ? `${origin}${renderInfo.render_rel_path}` : null;
  const hasThumb = renderInfo?.render_thumb_rel_path;
  const previewMap = previewAssignments;
  const previewCount = Object.keys(previewSource || {}).length;
  const renderedCount = Object.keys(previewMap || {}).length;
  const assignedCount = entriesForSave.length;
  const savedAssignments = useMemo(() => buildPreviewAssignments(entryBaseline), [entryBaseline]);
  const savedHasAssignments = Object.keys(savedAssignments || {}).length > 0;
  const savedAvailable = !!renderUrl && !savedImageError;
  const savedDisabled = !savedAvailable;

  useEffect(() => {
    setSavedImageError(false);
  }, [renderUrl, previewMode]);

  useEffect(() => {
    if (savedDisabled && previewMode === "saved") {
      setPreviewMode("edits");
    }
  }, [savedDisabled, previewMode]);

  async function fetchPalette(id, { silent = false } = {}) {
    if (!silent) setLoading(true);
    setError("");
    setSaveStatus("");
    setSaveError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, { credentials: "include", cache: "no-store" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load palette");
      const normalizedEntries = mapEntriesToState(data.entries || []);
      setEntryMap(normalizedEntries);
      setEntryBaseline(cloneEntryMap(normalizedEntries));
      const paletteMeta = {
        title: data.palette?.title || "",
        notes: data.palette?.notes || "",
        tags: data.palette?.tags || "",
      };
      setMeta(paletteMeta);
      setMetaBaseline(paletteMeta);
      setPalette({
        ...data.palette,
        needs_rerender: !!data.palette?.needs_rerender,
      });
      setRenderInfo(renderInfoFromPalette(data.palette));
      setClearStatus("");
      setClearError("");
      if (data.palette?.asset_id) {
        fetchAsset(data.palette.asset_id);
      }
    } catch (err) {
      setError(err?.message || "Failed to load palette");
    } finally {
      if (!silent) setLoading(false);
    }
  }

  async function fetchAsset(assetId) {
    setAsset(null);
    setAssetError("");
    try {
      const res = await fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(assetId)}`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      const data = await res.json();
      if (data?.error) throw new Error(data.error);
      const preparedTierUrl =
        (data.prepared_tiers && (data.prepared_tiers.medium || data.prepared_tiers.light || data.prepared_tiers.dark)) || "";
      setAsset({
        asset_id: data.asset_id,
        width: data.width,
        height: data.height,
        repairedUrl: data.repaired_url || data.repairedUrl || "",
        preparedUrl: data.prepared_url || data.preparedUrl || preparedTierUrl || "",
        preparedTiers: data.prepared_tiers || null,
        masks: Array.isArray(data.masks)
          ? data.masks.map((m) => ({
              role: m.role,
              base_lightness: typeof m.base_lightness === "number" ? Number(m.base_lightness) : null,
              original_texture: m.original_texture || null,
            }))
          : [],
      });
    } catch (err) {
      setAssetError(err?.message || "Failed to load asset");
    }
  }

  function updateEntry(mask, updates) {
    setEntryMap((prev) => {
      const current = prev[mask] || makeEmptyEntry(mask);
      return {
        ...prev,
        [mask]: {
          ...current,
          ...updates,
          mask_role: mask,
        },
      };
    });
    setSaveStatus("");
    setSaveError("");
  }

  function handlePickColor(mask, color) {
    const normalized = color ? normalizeColor(color) : null;
    const targetL =
      normalized?.lightness ?? normalized?.lab_l ?? normalized?.hcl_l ?? null;
    updateEntry(mask, { color: normalized, target_lightness: targetL });
  }

  function handleBlendChange(mask, mode) {
    updateEntry(mask, { blend_mode: mode || "" });
  }

  function handleOpacityChange(mask, value) {
    const num = value === "" ? null : clampNumber(Number(value) / 100, 0, 1);
    updateEntry(mask, { blend_opacity: num });
  }

  function handleShadowOffset(mask, value) {
    const num = value === "" ? null : clampNumber(Number(value), -50, 50);
    updateEntry(mask, { shadow_l_offset: num });
  }

  function handleShadowEdit(mask) {
    const entry = entryMap[mask] || makeEmptyEntry(mask);
    const currentOffset = entry.shadow_l_offset ?? 0;
    const currentTintPct = entry.shadow_tint_opacity != null ? Math.round(entry.shadow_tint_opacity * 100) : 0;
    const offsetStr = window.prompt(`Shadow L offset (negative darker, positive lighter)`, String(currentOffset));
    if (offsetStr === null) return;
    const offsetNum = clampNumber(Number(offsetStr), -50, 50);
    const tintStr = window.prompt(`Shadow tint opacity % (0-100)`, String(currentTintPct));
    if (tintStr === null) {
      updateEntry(mask, { shadow_l_offset: offsetNum });
      return;
    }
    const tintNum = clampNumber(Number(tintStr), 0, 100);
    updateEntry(mask, {
      shadow_l_offset: offsetNum,
      shadow_tint_opacity: tintNum != null ? tintNum / 100 : null,
    });
  }

  function handleApplyMask(mask) {
    const entry = entryMap[mask];
    if (!entry?.color?.hex6 && !entry?.color?.hex) return;
    // Use full entry settings (blend/opacity/shadow) from previewSource
    setPreviewAssignments({ ...previewSource });
    setPreviewVersion((v) => v + 1);
    triggerPreviewRender("edits");
  }

  async function triggerPreviewRender(targetMode = "edits") {
    if (targetMode !== "edits") {
      setPreviewMode("saved");
      return;
    }
    if (!palette?.id || !entriesForSave.length) {
      setPreviewState({ loading: false, error: "Assign at least one mask color." });
      return;
    }
    setPreviewMode("edits");
    setPreviewAssignments({ ...previewSource });
    setPreviewVersion((v) => v + 1);
    setPreviewState({ loading: true, error: "" });
    setPreviewImage((prev) => ({ ...prev, error: "" }));
    try {
      const res = await fetch(PREVIEW_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: palette.id, entries: entriesForSave }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok || !data?.render?.render_rel_path) {
        throw new Error(data?.error || "Preview render failed");
      }
      const rel = data.render.render_rel_path;
      const abs = data.render.render_url || `${origin}${rel}`;
      const ts = Date.now();
      setPreviewImage({ url: `${abs}?ts=${ts}`, ts, error: "" });
      setPreviewState({ loading: false, error: "" });
    } catch (err) {
      setPreviewState({ loading: false, error: err?.message || "Preview render failed" });
      setPreviewImage((prev) => ({ ...prev, error: err?.message || "Preview render failed" }));
    }
  }

  function clearEntry(mask) {
    setEntryMap((prev) => {
      const next = { ...prev };
      delete next[mask];
      return next;
    });
    setSaveStatus("");
    setSaveError("");
  }

  async function handleClearRender() {
    if (!palette?.id) return;
    setClearStatus("Clearing cached render…");
    setClearError("");
    try {
      const res = await fetch(CLEAR_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: palette.id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to clear render");
      setRenderInfo(null);
      setPalette((prev) => (prev ? { ...prev, needs_rerender: true } : prev));
      setClearStatus("Cached render deleted");
    } catch (err) {
      setClearError(err?.message || "Failed to delete render");
      setClearStatus("");
    }
  }

  async function handleSave({ rerender = false } = {}) {
    if (!palette?.id) return;
    if (!entriesForSave.length) {
      setSaveError("Assign at least one mask color before saving.");
      return;
    }
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: palette.id,
          title: meta.title,
          notes: meta.notes,
          tags: meta.tags,
          entries: entriesForSave,
          clear_render: clearCache || rerender,
          render: rerender ? { cache: true } : undefined,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Save failed");
      }
      const renderPayload = rerender && data.render_cache
        ? {
            render_rel_path: data.render_cache.render_rel_path || null,
            render_thumb_rel_path: data.render_cache.thumb_rel_path || null,
          }
        : null;
      if (renderPayload) {
        setRenderInfo(renderPayload);
      } else if (clearCache || rerender) {
        setRenderInfo(null);
      }
      const nextNeedsRerender = rerender ? !!data.render_cache_error : true;
      setPalette((prev) =>
        prev
          ? {
              ...prev,
              needs_rerender: nextNeedsRerender,
              render_rel_path: renderPayload?.render_rel_path ?? (clearCache || rerender ? null : prev.render_rel_path),
              render_thumb_rel_path: renderPayload?.render_thumb_rel_path ?? (clearCache || rerender ? null : prev.render_thumb_rel_path),
            }
          : prev
      );
      setSaveStatus(rerender ? "Saved and re-rendered" : "Saved");
      if (data.render_cache_error) {
        setSaveError(data.render_cache_error);
      }
      await fetchPalette(palette.id, { silent: true });
    } catch (err) {
      setSaveError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  async function handleSaveAsNew({ rerender = false } = {}) {
    if (!asset?.asset_id) {
      setSaveError("Missing asset to save.");
      return;
    }
    if (!entriesForSave.length) {
      setSaveError("Assign at least one mask color before saving.");
      return;
    }
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      const payload = {
        asset_id: asset.asset_id,
        title: meta.title,
        notes: meta.notes,
        tags: meta.tags,
        entries: entriesForSave,
        render: rerender ? { cache: true } : undefined,
      };
      const res = await fetch(SAVE_NEW_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Save failed");
      }
      const newId = data.palette_id;
      const viewUrl = typeof window !== "undefined"
        ? `${window.location.origin}/view/${newId}?admin=1`
        : `/view/${newId}`;
      setSaveStatus(`Saved as new palette #${newId}${rerender ? " (rendering)" : ""}`);
      setSaveResult({
        palette_id: newId,
        entries: data.entries_saved,
        render_cache: data.render_cache || null,
        render_cache_error: data.render_cache_error || null,
        view_url: viewUrl,
      });
    } catch (err) {
      setSaveError(err?.message || "Failed to save as new palette");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div className="ap-editor"><div>Loading palette…</div></div>;
  }

  if (error) {
    return (
      <div className="ap-editor">
        <div className="error">{error}</div>
        <button className="btn" onClick={() => navigate(-1)}>Back</button>
      </div>
    );
  }

  return (
    <div className="ap-shell">
      <div className="ap-bar">
        <div className="ap-bar__left">
          <div className="ap-title">Applied Palette #{palette?.id}</div>
          <div className="ap-sub">
            <span>Asset {palette?.asset_id || "—"}</span>
            {palette?.title && <span className="ap-sub__title">· {palette.title}</span>}
            {palette?.needs_rerender ? <span className="badge warn">Needs rerender</span> : <span className="badge ok">In sync</span>}
          </div>
        </div>
        <div className="ap-bar__actions">
          <button className="btn" onClick={() => navigate("/admin/applied-palettes")}>Back</button>
          {palette?.asset_id && (
            <button
              className="btn"
              onClick={() => window.open(`/admin/mask-tester?asset=${encodeURIComponent(palette.asset_id)}`, "_blank", "noopener")}
            >
              Mask Tester
            </button>
          )}
          {palette?.id && (
            <button className="btn" onClick={() => window.open(`/view/${palette.id}?admin=1`, "_blank", "noopener")}>
              View Palette
            </button>
          )}
          <button
            className="btn primary"
            disabled={saving || !entriesForSave.length}
            onClick={() => handleSave({ rerender: true })}
            title={entriesForSave.length ? "Save changes and render now" : "Add at least one mask color"}
          >
            Save & Rerender
          </button>
          <button
            className="btn"
            disabled={saving || !entriesForSave.length}
            onClick={() => handleSave({ rerender: false })}
            title={entriesForSave.length ? "Save changes without rendering" : "Add at least one mask color"}
          >
            Save (no render)
          </button>
        </div>
      </div>

      {assetError && <div className="error">Asset: {assetError}</div>}

      <div className="ap-layout">
        <div className="ap-left">
          <div className="ap-panel">
          <div className="ap-panel__head">
            <div className="ap-panel__title">Details</div>
            {hasChanges && <div className="badge warn">Unsaved</div>}
          </div>
          <div className="ap-fields">
              <label>
                <span>Nickname / Title</span>
                <input
                  value={meta.title}
                  onChange={(e) => setMeta((prev) => ({ ...prev, title: e.target.value }))}
                />
              </label>
              <label>
                <span>Description / Notes</span>
                <textarea
                  rows={3}
                  value={meta.notes}
                  onChange={(e) => setMeta((prev) => ({ ...prev, notes: e.target.value }))}
                />
              </label>
              <label>
                <span>Tags (comma separated)</span>
                <input
                  value={meta.tags}
                  onChange={(e) => setMeta((prev) => ({ ...prev, tags: e.target.value }))}
                />
              </label>
              <label className="ap-check">
                <input
                  type="checkbox"
                  checked={clearCache}
                  onChange={(e) => setClearCache(e.target.checked)}
                />
                Delete cached render when saving
              </label>
            </div>
            <div className="ap-actions-row">
              <button
                className="btn primary"
                disabled={saving || !hasChanges || !entriesForSave.length}
                onClick={() => handleSave({ rerender: false })}
                title={entriesForSave.length ? "Save changes and mark for rerender" : "Add at least one mask color"}
              >
                Save (no render)
              </button>
              <button
                className="btn"
                disabled={saving || !entriesForSave.length}
                onClick={() => handleSave({ rerender: true })}
                title={entriesForSave.length ? "Save changes and build cached render" : "Add at least one mask color"}
              >
                Save & Rerender
              </button>
              <button
                className="btn"
                disabled={saving || !entriesForSave.length}
                onClick={() => handleSaveAsNew({ rerender: false })}
                title={entriesForSave.length ? "Save as a new applied palette without rendering" : "Add at least one mask color"}
              >
                Save As New
              </button>
              <button
                className="btn"
                disabled={saving || !entriesForSave.length}
                onClick={() => handleSaveAsNew({ rerender: true })}
                title={entriesForSave.length ? "Save as a new applied palette and render now" : "Add at least one mask color"}
              >
                Save As New & Rerender
              </button>
            </div>
            <div className="ap-status">
              {saveError && <div className="error">{saveError}</div>}
              {saveStatus && <div className="notice">{saveStatus}</div>}
            </div>
          </div>

        <div className="ap-panel">
          <div className="ap-panel__head">
              <div className="ap-panel__title">Masks</div>
              <div className="ap-panel__meta">{assignedCount}/{maskRows.length} assigned</div>
            </div>
          <MaskRoleGrid
            masks={maskRows}
            entries={entryMap}
            onChange={(maskRole, updates) => {
              if (updates.color) {
                handlePickColor(maskRole, updates.color);
              } else {
                updateEntry(maskRole, updates);
              }
            }}
            onApply={handleApplyMask}
            onShadow={handleShadowEdit}
            showRole
          />
        </div>

        </div>

        <div className="ap-right">
          <div className="ap-panel ap-panel--sticky">
            <div className="ap-panel__head">
              <div>
                <div className="ap-panel__title">Preview</div>
                <div className="ap-panel__sub">Saved render vs live edits.</div>
              </div>
              <div className="ap-panel__controls">
                <div className="ap-seg">
                  {[
                    { value: "saved", label: "Saved" },
                    { value: "edits", label: "Edits" },
                  ].map((mode) => (
                    <button
                      key={mode.value}
                      className={`${previewMode === mode.value ? "seg-btn active" : "seg-btn"}${mode.value === "saved" && savedDisabled ? " seg-btn--disabled" : ""}`}
                      onClick={() => {
                        if (mode.value === "saved" && savedDisabled) return;
                        setPreviewMode(mode.value);
                      }}
                      disabled={mode.value === "saved" && savedDisabled}
                    >
                      {mode.label}
                    </button>
                  ))}
                </div>

                <button
                  className="btn"
                  onClick={() => triggerPreviewRender("edits")}
                  disabled={!previewCount}
                  title={previewCount ? "Render with current unsaved colors" : "Assign at least one mask color"}
                >
                  Render edits
                </button>
                {previewMode === "saved" && savedAvailable && (
                  <button className="btn" onClick={handleClearRender} disabled={saving}>
                    Delete Render
                  </button>
                )}
              </div>
            </div>
            {previewMode === "saved" ? (
              savedAvailable ? (
                <div className="ap-render-thumb">
                  <img
                    src={renderUrl}
                    alt="Saved render"
                    onError={() => setSavedImageError(true)}
                    onClick={() => setFullPreviewUrl(renderUrl)}
                  />
                  {hasThumb && <div className="ap-render-meta">Saved render</div>}
                </div>
              ) : (
                <div className="ap-placeholder">No cached render saved.</div>
              )
            ) : previewImage.url ? (
              <div className="ap-render-thumb">
                <img
                  src={previewImage.url}
                  alt="Preview render"
                  onClick={() => setFullPreviewUrl(previewImage.url)}
                />
              </div>
            ) : asset ? (
              <div className="ap-placeholder">Render edits to see preview.</div>
            ) : (
              <div className="ap-placeholder">Load an asset to preview.</div>
            )}
            {previewMode === "edits" && (
              <div className="ap-render-status">
                <span>{previewCount} masks staged</span>
                {previewState.loading && <span className="pill">Rendering…</span>}
                {previewState.error && <span className="pill pill-warn">{previewState.error}</span>}
              </div>
            )}
            {previewMode === "saved" && (
              <div className="ap-render-status">
                <span>Saved render preview</span>
                {clearStatus && <span className="pill">{clearStatus}</span>}
                {clearError && <span className="pill pill-warn">{clearError}</span>}
                {savedImageError && <span className="pill pill-warn">Saved image missing.</span>}
              </div>
            )}
          </div>
        </div>
      </div>
      {fullPreviewUrl && (
        <div className="ap-preview-full" onClick={() => setFullPreviewUrl("")} role="dialog" aria-label="Full preview">
          <img src={fullPreviewUrl} alt="Full preview" />
          <div className="ap-preview-full-hint">Click to close</div>
        </div>
      )}
    </div>
  );
}

function normalizeColor(color) {
  if (!color) return null;
  const hexRaw =
    color.hex6 ||
    color.hex ||
    color.color_hex6 ||
    color.color_hex ||
    color.hex_6 ||
    color.color_hex ||
    color.hex_code ||
    color.color_hex_code;
  const hex6 = cleanHex(hexRaw || "");
  const id = color.id ?? color.color_id ?? color.ID ?? null;
  const name = color.name || color.color_name || color.title || "";
  const code = color.code || color.color_code || (hex6 ? `#${hex6}` : "");
  const lightness =
    color.lightness ??
    color.lab_l ??
    color.hcl_l ??
    color.L ??
    color.hcl?.l ??
    null;
  return {
    id,
    name: name || code || (id ? `Color #${id}` : ""),
    code,
    brand: color.brand || color.color_brand || "",
    hex6,
    lightness: typeof lightness === "number" && Number.isFinite(lightness) ? lightness : null,
    lab_l: color.lab_l ?? null,
    hcl_l: color.hcl_l ?? null,
  };
}

function makeEmptyEntry(maskRole) {
  return {
    mask_role: maskRole,
    color: null,
    blend_mode: "",
    blend_opacity: null,
    shadow_l_offset: null,
    shadow_tint_hex: "",
    shadow_tint_opacity: null,
  };
}

function clampNumber(val, min, max) {
  if (!Number.isFinite(val)) return null;
  if (min != null && val < min) return min;
  if (max != null && val > max) return max;
  return val;
}

function mapEntriesToState(entries) {
  const map = {};
  (entries || []).forEach((entry) => {
    const norm = makeEmptyEntry(entry.mask_role || "");
    norm.color = normalizeColor(entry);
    const blendMode = entry.setting_blend_mode ?? "";
    const blendOpacity =
      entry.setting_blend_opacity != null
        ? Number(entry.setting_blend_opacity)
        : null;
    const shadowLOffset =
      entry.setting_shadow_l_offset != null
        ? Number(entry.setting_shadow_l_offset)
        : null;
    const shadowTintHex = cleanHex(entry.setting_shadow_tint_hex || "");
    const shadowTintOpacity =
      entry.setting_shadow_tint_opacity != null
        ? Number(entry.setting_shadow_tint_opacity)
        : null;
    norm.blend_mode = blendMode;
    norm.blend_opacity = blendOpacity;
    norm.shadow_l_offset = shadowLOffset;
    norm.shadow_tint_hex = shadowTintHex;
    norm.shadow_tint_opacity = shadowTintOpacity;
    norm.target_lightness =
      entry.setting_target_lightness != null
        ? Number(entry.setting_target_lightness)
        : null;
    norm.target_h =
      entry.setting_target_h != null
        ? Number(entry.setting_target_h)
        : null;
    norm.target_c =
      entry.setting_target_c != null
        ? Number(entry.setting_target_c)
        : null;
    norm.mask_setting_id = entry.mask_setting_id || null;
    norm.mask_setting_revision = entry.mask_setting_revision || null;
    map[norm.mask_role] = norm;
  });
  return map;
}

function cloneEntryMap(map) {
  const out = {};
  Object.keys(map || {}).forEach((key) => {
    const row = map[key];
    out[key] = {
      ...row,
      color: row.color ? { ...row.color } : null,
    };
  });
  return out;
}

function serializeEntryMap(map) {
  const rows = Object.keys(map || {})
    .sort()
    .map((key) => {
      const row = map[key];
      return {
        mask: key,
        color: row?.color?.id || null,
        blend: row?.blend_mode || "",
        opacity: row?.blend_opacity ?? null,
        l: row?.shadow_l_offset ?? null,
        tint: row?.shadow_tint_hex || "",
        tintOpacity: row?.shadow_tint_opacity ?? null,
        target_lightness: row?.target_lightness ?? null,
        target_h: row?.target_h ?? null,
        target_c: row?.target_c ?? null,
        mask_setting_id: row?.mask_setting_id ?? null,
        mask_setting_revision: row?.mask_setting_revision ?? null,
      };
    });
  return JSON.stringify(rows);
}

function renderInfoFromPalette(palette) {
  if (!palette) return null;
  if (!palette.render_rel_path && !palette.render_thumb_rel_path) return null;
  return {
    render_rel_path: palette.render_rel_path || null,
    render_thumb_rel_path: palette.render_thumb_rel_path || null,
  };
}
