import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
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
  const [maskRows, setMaskRows] = useState([createMaskRow()]);
  const [maskOptions, setMaskOptions] = useState([]);

  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState("");
  const [existingAsset, setExistingAsset] = useState(null);
  const [existingMasks, setExistingMasks] = useState([]);
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
    const trimmed = assetId.trim();
    if (!trimmed) {
      setExistingAsset(null);
      setExistingMasks([]);
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
    if (!styleTrim && !tagsTrim) {
      setError("Add a Style or at least one Tag so you can find this photo later.");
      return;
    }

    setBusy(true);

    try {
      const form = new FormData();
      if (assetId.trim()) form.append("asset_id", assetId.trim());

      // Optional meta supported by controller
      if (styleTrim) form.append("style", styleTrim);
      if (verdict.trim()) form.append("verdict", verdict.trim());
      if (status.trim()) form.append("status", status.trim());
      if (lighting.trim()) form.append("lighting", lighting.trim());
      if (rights.trim()) form.append("rights", rights.trim());
      if (tagsTrim) form.append("tags", tagsTrim);
      if (categoryPath.trim()) form.append("category_path", categoryPath.trim());

      if (fileBase) form.append("prepared_base", fileBase, fileBase.name);
      if (fileTexture) form.append("texture_overlay", fileTexture, fileTexture.name);

      // Optional masks[]
      let maskCount = 0;
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
        maskCount++;
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
        throw new Error(json?.message || json?.error || `HTTP ${res.status}`);
      }

      setResult(json);
      if (typeof json.category_path === "string") {
        setCategoryPath(json.category_path);
      }
      if (!assetId && json.asset_id) setAssetId(json.asset_id);
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
      const res = await fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(normalizedId)}`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      const data = await res.json();
      if (!res.ok || data?.error) {
        throw new Error(data?.message || data?.error || "Failed to load asset");
      }
      setExistingAsset(data);
      setExistingMasks(Array.isArray(data.masks) ? data.masks : []);
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
    } catch (err) {
      setExistingAsset(null);
      setExistingMasks([]);
      if (silent) {
        setExistingStatus({ loading: false, error: err?.message || "Failed to load asset", success: "" });
      } else {
        setExistingStatus({ loading: false, error: err?.message || "Failed to load asset", success: "" });
      }
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
          <label>Category Path (optional)</label>
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
    </div>
  );
}
