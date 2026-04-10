import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PhotoSearchPicker from "@components/PhotoSearchPicker";
import SavedPaletteEditorModal from "@components/SavedPaletteEditorModal";
import "./uploader.css";

const TEXTURE_SUGGESTIONS = [
  "smooth_flat",
  "rough_stucco",
  "semi_gloss",
  "textured_wood",
  "small_detail",
];

/**
 * Admin uploader for prepared bases (dark/medium/light) + optional masks[]
 * POST → /api/v2/admin/photo-upload.php
 * Expects response shape: { ok, asset_id, photo_id, base_size, touched: [{kind,role?,w,h}] }
 */
export default function PhotoPreparedUploader() {
  const [savedPalettes, setSavedPalettes] = useState([]);
  const [attachedPaletteId, setAttachedPaletteId] = useState("");
  const [attachedPaletteSetId, setAttachedPaletteSetId] = useState("");
  const [attachedPalettePhotoType, setAttachedPalettePhotoType] = useState("full");
  const [paletteModalOpen, setPaletteModalOpen] = useState(false);
  const [assetId, setAssetId] = useState("");
  const [stylePrimary, setStylePrimary] = useState("");
  const [verdict, setVerdict] = useState("");
  const [status, setStatus] = useState("");
  const [lighting, setLighting] = useState("");
  const [rights, setRights] = useState("");
  const [tags, setTags] = useState("");
  const [categoryPath, setCategoryPath] = useState("");

  const [fileBase, setFileBase] = useState(null);
  const [fileTexture, setFileTexture] = useState(null);
  const createMaskRow = () => ({
    id: `${Date.now()}-${Math.random()}`,
    file: null,
    slug: "",
    texture: "",
    modes: { dark: "colorize", medium: "colorize", light: "colorize" },
    opacities: { dark: 1, medium: 1, light: 1 },
  });
  const createExtraRow = () => ({
    id: `${Date.now()}-${Math.random()}`,
    file: null,
    slug: "",
  });
  const [maskRows, setMaskRows] = useState([createMaskRow()]);
  const [extraRows, setExtraRows] = useState([createExtraRow()]);
  const [maskOptions, setMaskOptions] = useState([]);

  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState("");
  const [existingAsset, setExistingAsset] = useState(null);
  const [existingMasks, setExistingMasks] = useState([]);
  const [existingExtras, setExistingExtras] = useState([]);
  const [maskDeleteStatus, setMaskDeleteStatus] = useState({ role: "", error: "", success: "" });
  const [existingStatus, setExistingStatus] = useState({ loading: false, error: "", success: "" });
  const [lastLoadedId, setLastLoadedId] = useState("");

  useEffect(() => {
    fetch(`${API_FOLDER}/v2/admin/roles-masks.php`, { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        setMaskOptions(Array.isArray(data?.masks) ? data.masks : []);
      })
      .catch(() => setMaskOptions([]));
  }, []);

  useEffect(() => {
    fetch(`${API_FOLDER}/v2/admin/saved-palettes.php?limit=200&with_photos=1&_=${Date.now()}`, {
      credentials: "include",
    })
      .then((res) => res.json())
      .then((data) => {
        const items = Array.isArray(data?.items) ? data.items : [];
        items.sort((a, b) => {
          const aLabel = a?.nickname || a?.palette_hash || `Saved #${a?.id || ""}`;
          const bLabel = b?.nickname || b?.palette_hash || `Saved #${b?.id || ""}`;
          return aLabel.localeCompare(bLabel, undefined, { sensitivity: "base" });
        });
        setSavedPalettes(items);
      })
      .catch(() => setSavedPalettes([]));
  }, []);

  useEffect(() => {
    const trimmed = assetId.trim();
    if (!trimmed) {
      setExistingAsset(null);
      setExistingMasks([]);
      setExistingExtras([]);
      setMaskDeleteStatus({ role: "", error: "", success: "" });
      setLastLoadedId("");
      setExistingStatus((prev) => ({ ...prev, loading: false, error: "", success: "" }));
      return;
    }
    const normalized = trimmed.toUpperCase();
    if (normalized === lastLoadedId) return;
    if (!/^PHO_[A-Z0-9]{3,}$/i.test(normalized)) return;
    const handle = setTimeout(() => {
      loadExistingAsset(normalized, { silent: true });
    }, 600);
    return () => clearTimeout(handle);
  }, [assetId, lastLoadedId]);



  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    setResult(null);

    const styleTrim = stylePrimary.trim();
    const tagsTrim = tags.trim();
    const categoryTrim = categoryPath.trim();
    const assetTrim = (assetId.trim() || existingAsset?.asset_id || "").trim();
    const hasExtraFiles = extraRows.some((row) => row.file);
    if (!assetTrim && !categoryTrim) {
      setError("Category Path is required (e.g., exteriors/cottage).");
      return;
    }
    if (!styleTrim && !tagsTrim) {
      setError("Add a Style or at least one Tag so you can find this photo later.");
      return;
    }
    if (!assetTrim && !fileBase && hasExtraFiles) {
      setError("Extra photos need an Asset ID (or upload a prepared base).");
      return;
    }

    setBusy(true);

    try {
      const form = new FormData();
      if (assetTrim) form.append("asset_id", assetTrim);

      // Optional meta supported by controller
      if (styleTrim) form.append("style", styleTrim);
      if (verdict.trim()) form.append("verdict", verdict.trim());
      if (status.trim()) form.append("status", status.trim());
      if (lighting.trim()) form.append("lighting", lighting.trim());
      if (rights.trim()) form.append("rights", rights.trim());
      if (tagsTrim) form.append("tags", tagsTrim);
      if (categoryTrim) form.append("category_path", categoryTrim);

      if (fileBase) form.append("prepared_base", fileBase, fileBase.name);
      if (fileTexture) form.append("texture_overlay", fileTexture, fileTexture.name);

      extraRows.forEach((row) => {
        if (!row.file) return;
        form.append("extras[]", row.file, row.file.name);
        form.append("extra_slugs[]", row.slug || "");
      });

      // Optional masks[]
      maskRows.forEach((row) => {
        if (!row.file) return;
        form.append("masks[]", row.file, row.file.name);
        form.append("mask_slugs[]", row.slug || "");
        form.append("mask_original_texture[]", row.texture || "");
        form.append("mask_mode_dark[]", row.modes.dark);
        form.append("mask_opacity_dark[]", row.opacities.dark ?? 1);
        form.append("mask_mode_medium[]", row.modes.medium);
        form.append("mask_opacity_medium[]", row.opacities.medium ?? 1);
        form.append("mask_mode_light[]", row.modes.light);
        form.append("mask_opacity_light[]", row.opacities.light ?? 1);
      });

      console.groupCollapsed("Upload payload");
      for (const [key, value] of form.entries()) {
        if (value instanceof File) {
          console.log(key, "→ file:", value.name, value.size, "bytes");
        } else {
          console.log(key, "→", value);
        }
      }
      console.groupEnd();

      const res = await fetch(`${API_FOLDER}/v2/admin/photo-upload.php`, {
        method: "POST",
        body: form,
      });

      const text = await res.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch {
        throw new Error(`Invalid JSON:\n${text.slice(0, 400)}`);
      }

      if (!res.ok || json?.error) {
        const where = json?.where ? ` (${json.where})` : "";
        throw new Error(`${json?.message || json?.error || `HTTP ${res.status}`}${where}`);
      }

      setResult(json);
      if (typeof json.category_path === "string") {
        setCategoryPath(json.category_path);
      }
      const nextAssetId = json.asset_id || assetId;
      if (!assetId && json.asset_id) setAssetId(json.asset_id);
      if (attachedPaletteId && nextAssetId) {
        const asset = await loadExistingAsset(nextAssetId, { silent: true });
        await attachAssetToSavedPalette(asset, attachedPaletteId, attachedPalettePhotoType);
      }
    } catch (err) {
      setError(err?.message || String(err));
    } finally {
      setBusy(false);
    }
  }

  async function loadExistingAsset(targetId = assetId.trim(), { silent = false } = {}) {
    const id = targetId.trim();
    if (!id) {
      setExistingAsset(null);
      setExistingMasks([]);
      setLastLoadedId("");
      if (!silent) {
        setExistingStatus({ loading: false, error: "Enter an asset ID first", success: "" });
      }
      return;
    }
    const normalizedId = id.toUpperCase();
    if (!silent) {
      setExistingStatus({ loading: true, error: "", success: "" });
    } else {
      setExistingStatus((prev) => ({ ...prev, loading: true }));
    }
    try {
      const res = await fetch(
        `${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(normalizedId)}&_=${Date.now()}`,
        {
          credentials: "include",
          cache: "no-store",
          headers: { Accept: "application/json" },
        }
      );
      const data = await res.json();
      if (!res.ok || data?.error) {
        throw new Error(data?.message || data?.error || "Failed to load asset");
      }
      setExistingAsset(data);
      setExistingMasks(Array.isArray(data.masks) ? data.masks : []);
      setExistingExtras(Array.isArray(data.extras) ? data.extras : []);
      setStylePrimary(data.style_primary || "");
      setVerdict(data.verdict || "");
      setStatus(data.status || "");
      setLighting(data.lighting || "");
      setRights(data.rights_status || "");
      setCategoryPath(data.category_path || "");
      setTags(Array.isArray(data.tags) ? data.tags.join(", ") : "");
      setLastLoadedId(normalizedId);
      setExistingStatus({
        loading: false,
        error: "",
        success: `Loaded ${normalizedId}`,
      });
      return data;
    } catch (err) {
      setExistingAsset(null);
      setExistingMasks([]);
      setExistingExtras([]);
      setMaskDeleteStatus({ role: "", error: "", success: "" });
      if (silent) {
        setExistingStatus({ loading: false, error: err?.message || "Failed to load asset", success: "" });
      } else {
        setExistingStatus({ loading: false, error: err?.message || "Failed to load asset", success: "" });
      }
      return null;
    }
  }

  async function attachAssetToSavedPalette(asset, paletteId, photoType) {
    const relPath = asset?.prepared_url || asset?.repaired_url || "";
    if (!paletteId || !relPath) {
      throw new Error("Uploaded photo could not be attached to a saved palette.");
    }
    const normalizedPath = (() => {
      try {
        const url = new URL(relPath, window.location.origin);
        return url.pathname || relPath;
      } catch {
        return relPath;
      }
    })();
    const res = await fetch(`${API_FOLDER}/v2/admin/saved-palette-photos/add-from-library.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        palette_id: Number(paletteId),
        set_id: attachedPaletteSetId && attachedPaletteSetId !== "__new__" ? Number(attachedPaletteSetId) : null,
        create_new_set: attachedPaletteSetId === "__new__",
        raw_rel_path: normalizedPath,
        rel_path: normalizedPath,
        photo_type: photoType || "full",
        trigger_mode: photoType === "before" ? "none" : "any",
        caption: photoType === "before" ? "Before" : null,
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || "Failed to attach photo to saved palette");
    }
  }

  async function handleDeleteMask(maskRole) {
    if (!maskRole || !existingAsset?.asset_id) return;
    const ok = window.confirm(`Remove mask "${maskRole}"? This deletes it from palettes and flags rerender.`);
    if (!ok) return;
    setMaskDeleteStatus({ role: maskRole, error: "", success: "" });
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/photo-mask-delete.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          asset_id: existingAsset.asset_id,
          mask_role: maskRole,
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        const message = data?.error || "Failed to delete mask";
        if (!/mask not found/i.test(message)) {
          throw new Error(message);
        }
      }
      setExistingMasks((prev) => prev.filter((m) => m.role !== maskRole));
      setExistingAsset((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          masks: Array.isArray(prev.masks) ? prev.masks.filter((m) => m.role !== maskRole) : prev.masks,
        };
      });
      setMaskDeleteStatus({
        role: "",
        error: "",
        success: /mask not found/i.test(data?.error || "") ? `Already removed ${maskRole}` : `Removed ${maskRole}`,
      });
    } catch (err) {
      setMaskDeleteStatus({ role: maskRole, error: err?.message || "Failed to delete mask", success: "" });
    }
  }

  return (
    <div className="prep-uploader">
      <h2>Upload Prepared Base + Texture + Masks</h2>

      <form onSubmit={onSubmit} className="prep-form" encType="multipart/form-data">
        <datalist id="mask-texture-options">
          {TEXTURE_SUGGESTIONS.map((opt) => (
            <option key={opt} value={opt} />
          ))}
        </datalist>
        <PhotoSearchPicker
          onPick={(item) => {
            const id = item?.asset_id || "";
            if (!id) return;
            setAssetId(id);
            loadExistingAsset(id, { silent: true });
          }}
        />
        <div className="row asset-row">
          <label>Asset ID (optional)</label>
          <div className="asset-input-group">
            <input
              type="text"
              placeholder="e.g., PHO_ABC123 (leave blank to auto-generate)"
              value={assetId}
              onChange={(e) => setAssetId(e.target.value)}
            />
            <button
              type="button"
              className="load-asset-btn"
              onClick={loadExistingAsset}
              disabled={!assetId.trim() || existingStatus.loading}
            >
              {existingStatus.loading ? "Loading…" : "Load Existing"}
            </button>
          </div>
        </div>
        {existingStatus.error && <div className="error">{existingStatus.error}</div>}
        {existingStatus.success && <div className="notice">{existingStatus.success}</div>}

        <div className="row">
          <label>Category Path (required)</label>
          <input
            type="text"
            placeholder="e.g., exteriors/cottage"
            value={categoryPath}
            onChange={(e) => setCategoryPath(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Style (required if no tags)</label>
          <input
            type="text"
            placeholder="e.g., Adobe, Ranch, Victorian"
            value={stylePrimary}
            onChange={(e) => setStylePrimary(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Verdict (optional)</label>
          <input
            type="text"
            placeholder="e.g., fan, love-it, nope"
            value={verdict}
            onChange={(e) => setVerdict(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Status (optional)</label>
          <input
            type="text"
            placeholder="e.g., draft, keeper"
            value={status}
            onChange={(e) => setStatus(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Lighting (optional)</label>
          <input
            type="text"
            placeholder="e.g., shade, bright sun"
            value={lighting}
            onChange={(e) => setLighting(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Rights (optional)</label>
          <input
            type="text"
            placeholder="e.g., owned, ok-to-post"
            value={rights}
            onChange={(e) => setRights(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Tags (required if no style)</label>
          <input
            type="text"
            placeholder="e.g., white, shutters, adobe, front-door"
            value={tags}
            onChange={(e) => setTags(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Attached Saved Palette (optional)</label>
          <div className="asset-input-group">
            <select
              value={attachedPaletteId}
              onChange={(e) => {
                setAttachedPaletteId(e.target.value);
                setAttachedPaletteSetId("");
              }}
            >
              <option value="">None</option>
              {savedPalettes.map((palette) => (
                <option key={palette.id} value={palette.id}>
                  {palette.nickname || palette.palette_hash || `Saved #${palette.id}`}
                </option>
              ))}
            </select>
            <button type="button" className="load-asset-btn" onClick={() => setPaletteModalOpen(true)}>
              {attachedPaletteId ? "Edit Palette" : "Create / Pick"}
            </button>
          </div>
        </div>

        {attachedPaletteId && (
          <div className="row">
            <label>Photo Group</label>
            <select
              value={attachedPaletteSetId}
              onChange={(e) => setAttachedPaletteSetId(e.target.value)}
            >
              <option value="">Primary / Default Group</option>
              {(savedPalettes.find((palette) => String(palette.id) === String(attachedPaletteId))?.sets || []).map((set) => (
                <option key={set.id} value={set.id}>
                  {set.title || set.slug || `Set #${set.id}`}
                </option>
              ))}
              <option value="__new__">Create New Group</option>
            </select>
          </div>
        )}

        {attachedPaletteId && (
          <div className="row">
            <label>Palette Photo Type</label>
            <select value={attachedPalettePhotoType} onChange={(e) => setAttachedPalettePhotoType(e.target.value)}>
              <option value="full">Full</option>
              <option value="before">Before</option>
              <option value="zoom">Zoom</option>
            </select>
          </div>
        )}

        {existingAsset && (
          <div className="existing-info">
            <div className="section-title">Current Asset</div>
            <div className="existing-preview">
              {(() => {
                const thumb =
                  existingAsset.prepared_url ||
                  existingAsset.repaired_url ||
                  (existingAsset.masks && existingAsset.masks[0]?.url) ||
                  "";
                if (!thumb) return <div className="preview-placeholder">No preview</div>;
                return <img src={thumb} alt={existingAsset.asset_id || "preview"} />;
              })()}
              <div className="existing-files">
                {existingAsset.asset_id && <div className="asset-id-display">{existingAsset.asset_id}</div>}
                {Array.isArray(existingAsset.tags) && existingAsset.tags.length > 0 && (
                  <div className="asset-tags">
                    {existingAsset.tags.map((tag) => (
                      <span key={tag}>{tag}</span>
                    ))}
                  </div>
                )}
                {existingAsset.prepared_url && (
                  <div>
                    <strong>Prepared Base:</strong>{" "}
                    <a href={existingAsset.prepared_url} target="_blank" rel="noreferrer">
                      {existingAsset.prepared_url}
                    </a>
                  </div>
                )}
                {existingAsset.repaired_url && (
                  <div>
                    <strong>Repaired:</strong>{" "}
                    <a href={existingAsset.repaired_url} target="_blank" rel="noreferrer">
                      {existingAsset.repaired_url}
                    </a>
                  </div>
                )}
                {existingAsset.prepared_tiers &&
                  Object.entries(existingAsset.prepared_tiers).map(([tier, url]) =>
                    url ? (
                      <div key={tier}>
                        <strong>Prepared {tier}:</strong>{" "}
                        <a href={url} target="_blank" rel="noreferrer">
                          {url}
                        </a>
                      </div>
                    ) : null
                  )}
              </div>
            </div>
            {existingMasks.length > 0 && (
              <div className="existing-mask-list">
                <div className="section-title">Existing Masks</div>
                <ul>
                  {existingMasks.map((mask) => (
                    <li key={mask.role}>
                      <span className="mask-label">{mask.role}</span>
                      {mask.filename && <span className="mask-file">{mask.filename}</span>}
                      {mask.url && (
                        <a href={mask.url} target="_blank" rel="noreferrer">
                          View
                        </a>
                      )}
                      <button
                        type="button"
                        className="mask-delete-btn"
                        onClick={() => handleDeleteMask(mask.role)}
                        disabled={maskDeleteStatus.role === mask.role}
                      >
                        {maskDeleteStatus.role === mask.role ? "Removing…" : "Remove"}
                      </button>
                    </li>
                  ))}
                </ul>
                {maskDeleteStatus.error && <div className="error">{maskDeleteStatus.error}</div>}
                {maskDeleteStatus.success && <div className="notice">{maskDeleteStatus.success}</div>}
              </div>
            )}
            {existingExtras.length > 0 && (
              <div className="existing-mask-list">
                <div className="section-title">Extra Photos</div>
                <ul>
                  {existingExtras.map((extra) => (
                    <li key={extra.role || extra.filename}>
                      <span className="mask-label">{extra.role || "extra"}</span>
                      {extra.filename && <span className="mask-file">{extra.filename}</span>}
                      {extra.url && (
                        <a href={extra.url} target="_blank" rel="noreferrer">
                          View
                        </a>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        <div className="row">
          <label>Prepared Base</label>
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.webp"
            onChange={(e) => setFileBase(e.target.files?.[0] || null)}
          />
        </div>

        <div className="row">
          <label>Texture Overlay (PNG)</label>
          <input
            type="file"
            accept=".png"
            onChange={(e) => setFileTexture(e.target.files?.[0] || null)}
          />
        </div>

        <div className="extras-section">
          <div className="masks-header">
            <label>Extra Photos (optional)</label>
            <button type="button" onClick={() => setExtraRows((rows) => [...rows, createExtraRow()])}>
              + Add Photo
            </button>
          </div>
          {extraRows.map((row, idx) => (
            <div key={row.id} className="mask-row">
              <div className="mask-row-head">
                <span>Extra #{idx + 1}</span>
                {extraRows.length > 1 && (
                  <button type="button" onClick={() => setExtraRows((rows) => rows.filter((r) => r.id !== row.id))}>
                    Remove
                  </button>
                )}
              </div>
              <input
                type="file"
                accept=".jpg,.jpeg,.png,.webp"
                onChange={(e) =>
                  setExtraRows((rows) =>
                    rows.map((r) => (r.id === row.id ? { ...r, file: e.target.files?.[0] || null } : r))
                  )
                }
              />
              <div className="mask-slug-select">
                <label>Label (used in filename)</label>
                <input
                  type="text"
                  placeholder="e.g., before, side-yard, elevation-b"
                  value={row.slug}
                  onChange={(e) =>
                    setExtraRows((rows) =>
                      rows.map((r) => (r.id === row.id ? { ...r, slug: e.target.value } : r))
                    )
                  }
                />
              </div>
            </div>
          ))}
        </div>

        <div className="masks-section">
          <div className="masks-header">
            <label>Masks (PNG,+ blend settings)</label>
            <button type="button" onClick={() => setMaskRows((rows) => [...rows, createMaskRow()])}>
              + Add Mask
            </button>
          </div>
          {maskRows.map((row, idx) => (
            <div key={row.id} className="mask-row">
              <div className="mask-row-head">
                <span>Mask #{idx + 1}</span>
            {maskRows.length > 1 && (
              <button type="button" onClick={() => setMaskRows((rows) => rows.filter((r) => r.id !== row.id))}>
                Remove
              </button>
            )}
              </div>
              <input
                type="file"
                accept=".png"
                onChange={(e) =>
                  setMaskRows((rows) =>
                    rows.map((r) => (r.id === row.id ? { ...r, file: e.target.files?.[0] || null } : r))
                  )
                }
              />
              <div className="mask-slug-select">
                <label>Mask Name</label>
                    <div className="mask-slug-input">
                      <select
                        value={row.slug.startsWith("mask-") ? "" : row.slug}
                        onChange={(e) =>
                          setMaskRows((rows) =>
                            rows.map((r) => (r.id === row.id ? { ...r, slug: e.target.value || "" } : r))
                          )
                        }
                      >
                        <option value="">Auto (from filename)</option>
                        {maskOptions.map((opt) => (
                          <option key={opt.mask_slug} value={opt.mask_slug}>
                            {opt.mask_slug}
                          </option>
                        ))}
                      </select>
                      <input
                        type="text"
                        value={row.slug}
                        onChange={(e) =>
                          setMaskRows((rows) =>
                            rows.map((r) => (r.id === row.id ? { ...r, slug: e.target.value } : r))
                          )
                        }
                      />
                    </div>
              </div>
              <div className="mask-texture-select">
                <label>Original Texture</label>
                <input
                  type="text"
                  list="mask-texture-options"
                  placeholder="smooth_flat, rough_stucco…"
                  value={row.texture}
                  onChange={(e) =>
                    setMaskRows((rows) =>
                      rows.map((r) =>
                        r.id === row.id ? { ...r, texture: e.target.value || "" } : r
                      )
                    )
                  }
                />
              </div>
              <div className="mask-tier-grid">
                {["dark", "medium", "light"].map((tier) => (
                  <div key={tier} className="mask-tier">
                    <div className="mask-tier-label">{tier.toUpperCase()}</div>
                    <select
                      value={row.modes[tier]}
                      onChange={(e) =>
                        setMaskRows((rows) =>
                          rows.map((r) =>
                            r.id === row.id
                              ? { ...r, modes: { ...r.modes, [tier]: e.target.value } }
                              : r
                          )
                        )
                      }
                    >
                      <option value="colorize">Colorize (default)</option>
                      <option value="hardlight">Hard Light</option>
                      <option value="softlight">Soft Light</option>
                      <option value="overlay">Overlay</option>
                      <option value="multiply">Multiply</option>
                      <option value="screen">Screen</option>
                      <option value="luminosity">Luminosity</option>
                    </select>
                    <input
                      type="number"
                      min="0"
                      max="1"
                      step="0.05"
                      value={row.opacities[tier]}
                      onChange={(e) =>
                        setMaskRows((rows) =>
                          rows.map((r) =>
                            r.id === row.id
                              ? {
                                  ...r,
                                  opacities: {
                                    ...r.opacities,
                                    [tier]: parseFloat(e.target.value) || 0,
                                  },
                                }
                              : r
                          )
                        )
                      }
                    />
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="actions">
          <button type="submit" disabled={busy}>
            {busy ? "Uploading…" : "Upload"}
          </button>
               <button type="button" onClick={() => setMaskRows((rows) => [...rows, createMaskRow()])}>
              + Add Mask
            </button>
        </div>
      </form>

      {error && <div className="error">{error}</div>}

      {result && (
        <div className="result">
          <div><strong>Saved</strong></div>
          <div>Asset ID: {result.asset_id}</div>
          <div>Photo ID: {result.photo_id}</div>
          {result.base_size?.w && result.base_size?.h && (
            <div>Base Size: {result.base_size.w} × {result.base_size.h}</div>
          )}
          <ul>
            {(result.touched || []).map((t, i) => (
              <li key={`${t.kind}-${t.role || ''}-${i}`}>
                {t.kind}{t.role ? `:${t.role}` : ""} → {t.w || "?"}×{t.h || "?"}
              </li>
            ))}
          </ul>
        </div>
      )}

      <SavedPaletteEditorModal
        open={paletteModalOpen}
        paletteId={attachedPaletteId || null}
        attachment={existingAsset?.prepared_url || existingAsset?.repaired_url ? {
          rel_path: existingAsset.prepared_url || existingAsset.repaired_url,
          photo_type: attachedPalettePhotoType,
        } : null}
        onClose={() => setPaletteModalOpen(false)}
        onSaved={({ paletteId, palette, attachedPhoto }) => {
          setPaletteModalOpen(false);
          if (!paletteId) return;
          setAttachedPaletteId(String(paletteId));
          const pickedSetId = attachedPhoto?.saved_palette_set_id || null;
          const defaultSet = (palette?.sets || []).find((set) => Number(set.id) === Number(pickedSetId))
            || (palette?.sets || []).find((set) => Number(set.is_default) === 1)
            || palette?.sets?.[0]
            || null;
          setAttachedPaletteSetId(defaultSet?.id ? String(defaultSet.id) : "");
          if (palette) {
            setSavedPalettes((prev) => {
              const next = prev.filter((item) => String(item.id) !== String(paletteId));
              return [palette, ...next];
            });
          }
        }}
      />
    </div>
  );
}
