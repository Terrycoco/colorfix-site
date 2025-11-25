import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import PhotoRenderer from "@components/PhotoRenderer";
import PaletteInspector from "@components/PaletteInspector";
import PaletteRack from "@components/PaletteRack";
import { API_FOLDER } from "@helpers/config";
import "./photopreview.css?v=5";

const ROLE_SEQUENCE = ["body", "trim", "accent"];
const MASK_TEXTURE_OPTIONS = ["smooth_flat", "rough_stucco", "semi_gloss", "textured_wood", "small_detail"];
const ROLE_TO_MASKS = {
  body: ["body", "stucco", "siding", "brick"],
  trim: ["trim", "fascia", "bellyband", "gutter", "windowtrim", "garage", "railing"],
  accent: ["accent", "frontdoor", "door", "shutters"],
};

import RolesBar from "@components/RolesBar";
import MasksPanel from "@components/MasksPanel";
import MaskOverlayEditor from "@components/MaskOverlayEditor";

/* ---------- URL query helper ---------- */
function useQuery() {
  const { search } = useLocation();
  return useMemo(() => new URLSearchParams(search), [search]);
}

/* ---------- Embedded search bits (unchanged) ---------- */
function SearchBar({ initialQ = "", initialTags = "", onSearch }) {
  const [q, setQ] = useState(initialQ);
  const [tagsText, setTagsText] = useState(initialTags);
  function submit() {
    onSearch && onSearch({ q: q.trim(), tagsText: tagsText.trim() });
  }
  return (
    <div className="photo-searchbar">
      <div className="psb-field">
        <label className="psb-label">Tags</label>
        <input
          className="psb-input"
          type="text"
          placeholder="comma or | separated (e.g., adobe,white)"
          value={tagsText}
          onChange={(e) => setTagsText(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>
      <div className="psb-field">
        <label className="psb-label">Text</label>
        <input
          className="psb-input"
          type="text"
          placeholder="address, note, asset_id…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>

      <div className="psb-actions">
        <button className="psb-btn psb-primary" onClick={submit}>Search</button>
        <button
          className="psb-btn"
          onClick={() => {
            setQ("");
            setTagsText("");
            onSearch && onSearch({ q: "", tagsText: "" });
          }}
        >
          Clear
        </button>
      </div>
    </div>
  );
}

function PhotoGrid({ items = [], onPick, emptyText = "No results" }) {
  if (!items.length) return <div className="photo-grid-empty">{emptyText}</div>;
  return (
    <div className="photo-grid">
      {items.map((item) => (
        <div
          key={item.asset_id}
          className="photo-card"
          onClick={() => onPick && onPick(item)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === "Enter") onPick && onPick(item); }}
        >
          <div className="photo-thumb-wrap">
            {item.thumb_url ? (
              <img className="photo-thumb" src={item.thumb_url} alt="" loading="lazy" />
            ) : (
              <div className="photo-thumb placeholder">No preview</div>
            )}
          </div>
          <div className="photo-meta">
            <div className="photo-title">{item.title || item.asset_id}</div>
            <div className="photo-tags">
              {(item.tags || []).map((t) => (
                <span key={t} className="photo-tag">{t}</span>
              ))}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- Page ---------- */
export default function AdminPhotoPreviewPage() {
  const query = useQuery();
  const navigate = useNavigate();
  const assetId = query.get("asset") || "";

  // search panel state
  const [findOpen, setFindOpen] = useState(!assetId);
  const [sLoading, setSLoading] = useState(false);
  const [sItems, setSItems] = useState([]);
  const [sTotal, setSTotal] = useState(0);
  const [sError, setSError] = useState("");
  const [sPage, setSPage] = useState(1);
  const [sQ, setSQ] = useState(query.get("q") || "");
  const [sTags, setSTags] = useState(query.get("tags") || "");

  // asset + render state
  const [loading, setLoading] = useState(false);
  const [asset, setAsset] = useState(null);
  const [error, setError] = useState("");
  const [assignments, setAssignments] = useState({});
  const [renderState, setRenderState] = useState({ loading: false, error: "" });
  const [viewMode, setViewMode] = useState("after"); // before | after | prepared


// role-level colors (Body/Trim/Accent)
const [roleGroups, setRoleGroups] = useState({
  body: null,
  trim: null,
  accent: null,
});
const [paletteSaveState, setPaletteSaveState] = useState({ loading: false, error: "", success: "" });
const [paletteInspectorData, setPaletteInspectorData] = useState(null);
const [paletteRackOpen, setPaletteRackOpen] = useState(false);

// mask-level overrides (e.g. fascia manually changed)
const [maskOverrides, setMaskOverrides] = useState({});
const [maskOverlays, setMaskOverlays] = useState({});
const [maskTextures, setMaskTextures] = useState({});
const [overlayStatus, setOverlayStatus] = useState({});
const [overlayModalMask, setOverlayModalMask] = useState(null);


  // NEW: per-mask intent — follow a role, or use a direct color
  // { [mask]: { mode: "role"|"chip", role?: "body"|"trim"|"accent", swatch?: {...} } }
  const [maskMap, setMaskMap] = useState({});



  // responsive controls drawer
  const [controlsOpen, setControlsOpen] = useState(true);
  useEffect(() => {
    const mm = window.matchMedia("(max-width: 899px)");
    const setByMq = () => setControlsOpen(!mm.matches);
    setByMq();
    mm.addEventListener("change", setByMq);
    return () => mm.removeEventListener("change", setByMq);
  }, []);

  /* ---------- Search handlers ---------- */
  function doSearch({ q, tagsText }, p = 1) {
    setSLoading(true);
    setSError("");

    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (tagsText) params.set("tags", tagsText);
    params.set("page", String(p));
    params.set("limit", "24");

    fetch(`${API_FOLDER}/v2/photos/search.php?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) {
          setSError(data.error);
          setSItems([]);
          setSTotal(0);
          return;
        }
        const items = (data.items || []).map((it) => ({
          ...it,
          thumb_url: it.thumb_url || "",
          _thumbLoading: false,
        }));
        setSItems(items);
        setSTotal(data.total || 0);
        setSPage(data.page || p);
        setSQ(q || "");
        setSTags(tagsText || "");

        const nav = new URLSearchParams();
        if (assetId) nav.set("asset", assetId);
        if (q) nav.set("q", q);
        if (tagsText) nav.set("tags", tagsText);
        nav.set("page", String(data.page || p));
        navigate(`/admin/photo-preview?${nav.toString()}`, { replace: true });
      })
      .catch((e) => setSError(e?.message || "Search failed"))
      .finally(() => setSLoading(false));
  }

  // picking photo
  function onPick(item) {
    const nav = new URLSearchParams();
    nav.set("asset", item.asset_id);
    if (sQ) nav.set("q", sQ);
    if (sTags) nav.set("tags", sTags);
    navigate(`/admin/photo-preview?${nav.toString()}`);
    setFindOpen(false);
    setSError("");
    setError("");
  }

  /* ---------- Asset fetch ---------- */
  useEffect(() => {
    if (!assetId) {
      setAsset(null);
      setMaskOverlays({});
      setMaskTextures({});
      return;
    }
    setLoading(true);
    setError("");
    fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(assetId)}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) { setError(data.error); setAsset(null); return; }
        const normalized = {
          asset_id: data.asset_id,
          width: data.width,
          height: data.height,
          repairedUrl: data.repaired_url || "",
          preparedUrl: data.prepared_url || "",
          categoryPath: data.category_path || "",
          tags: Array.isArray(data.tags) ? data.tags : [],
          masks: Array.isArray(data.masks)
            ? data.masks.map((m) => ({
                role: m.role,
                url: m.url,
                original_texture: m.original_texture || null,
              }))
            : [],
          precomputed: data.precomputed || undefined,
        };
        const overlayMap = {};
        const textureMap = {};
        (data.masks || []).forEach((m) => {
          if (!m?.role) return;
          overlayMap[m.role] = normalizeOverlayPayload(m.overlay);
          textureMap[m.role] = normalizeTextureValue(m.original_texture);
        });
        setAsset(normalized);
        setMaskOverlays(overlayMap);
        setMaskTextures(textureMap);
        setOverlayStatus({});
        setOverlayModalMask(null);
        setRoleGroups({ body: null, trim: null, accent: null });
        setMaskMap({});
        setPaletteSaveState({ loading: false, error: "", success: "" });
        setPaletteInspectorData(null);
        setPaletteRackOpen(false);
        setAssignments({});
        setViewMode("after");
      })
      .catch((e) => setError(e?.message || "Failed to fetch asset"))
      .finally(() => setLoading(false));
  }, [assetId]);

  /* ---------- Helpers ---------- */
  const OVERLAY_TIERS = ["dark", "medium", "light"];

  function normalizePick(obj) {
    if (!obj) return null;
    const colorId = obj.color_id ?? obj.id ?? obj.ID ?? null;
    if (!colorId) return null;
    const hexRaw = obj.hex6 || obj.hex || "";
    const hex6 = hexRaw.startsWith("#") ? hexRaw.slice(1) : hexRaw;
    return { ...obj, color_id: Number(colorId), hex6: hex6.toUpperCase() };
  }

  function blankOverlay() {
    return OVERLAY_TIERS.reduce((acc, tier) => {
      acc[tier] = { mode: null, opacity: null };
      return acc;
    }, {});
  }

  function normalizeOverlayPayload(raw) {
    const base = blankOverlay();
    OVERLAY_TIERS.forEach((tier) => {
      const row = raw?.[tier] || {};
      base[tier] = {
        mode: typeof row.mode === "string" && row.mode ? row.mode : null,
        opacity: typeof row.opacity === "number" && Number.isFinite(row.opacity) ? row.opacity : null,
      };
    });
    return base;
  }

  function normalizeTextureValue(value) {
    if (typeof value !== "string") return "";
    return value.trim().toLowerCase();
  }

  // Default visual grouping for masks (purely for section headers)
function maskToRoleGroup(mask) {
  const m = (mask || "").toLowerCase();
  if (ROLE_TO_MASKS.body.includes(m)) return "body";
  if (ROLE_TO_MASKS.accent.includes(m)) return "accent";
  return "trim";
}

  const defaultRoleByMask = (asset?.masks || []).reduce((acc, m) => {
    acc[m.role] = maskToRoleGroup(m.role);
    return acc;
  }, {});

  // When a mask chooses "Use Color", optionally push that color into RolesBar (so you can test fan-in)
  function addOrUpdateRoleColor(roleGroup, swatch) {
    const norm = normalizePick(swatch);
    setRoleGroups(prev => {
      const cur = prev[roleGroup];
      if (cur && Number(cur.color_id) === Number(norm.color_id)) return prev;
      return { ...prev, [roleGroup]: norm };
    });
  }

  /* ---------- Apply to renderer ---------- */
  async function applyColors() {
    try {
      setError("");

      const collectSwatch = (sw) => {
        const norm = normalizePick(sw);
        if (!norm) return null;
        const idNum = Number(norm.color_id);
        if (!Number.isFinite(idNum)) return null;
        return { ...norm, color_id: idNum };
      };

      const requiredSwatches = [];
      ROLE_SEQUENCE.forEach((group) => {
        const sw = collectSwatch(roleGroups[group]);
        if (sw) requiredSwatches.push(sw);
      });
      Object.values(maskOverrides).forEach((sw) => {
        const norm = collectSwatch(sw);
        if (norm) requiredSwatches.push(norm);
      });

      if (!requiredSwatches.length) {
        setAssignments({});
        setViewMode("after");
        return;
      }

      const uniqueIds = Array.from(new Set(requiredSwatches.map((sw) => sw.color_id)));
      const responses = await Promise.all(
        uniqueIds.map(async (id) => {
          const url = `${API_FOLDER}/v2/get-color.php?id=${id}`;
          try {
            const r = await fetch(url, { credentials: "include", headers: { Accept: "application/json" } });
            const raw = await r.text();
            let data;
            try { data = JSON.parse(raw); } catch { data = { ok: false, _raw: raw }; }
            return { id, url, status: r.status, data };
          } catch (e) {
            return { id, url, status: 0, data: { ok: false, _err: e?.message || "fetch failed" } };
          }
        })
      );

      const byId = {};
      const missing = [];
      for (const { id, data } of responses) {
        if (data?.ok && data.color) {
          const c = data.color;
          byId[id] = {
            hex6: (c.hex6 || "").toUpperCase(),
            L: Number(c.lab_l ?? c.L ?? 0),
            a: Number(c.lab_a ?? c.a ?? 0),
            b: Number(c.lab_b ?? c.b ?? 0),
          };
        } else {
          missing.push(`#${id}`);
        }
      }

      const resolved = {};
      (asset?.masks || []).forEach(({ role: mask }) => {
        const override = collectSwatch(maskOverrides[mask]);
        const fallbackRole = maskToRoleGroup(mask);
        const roleSwatch = collectSwatch(roleGroups[fallbackRole]);
        const source = override || roleSwatch;
        if (!source) return;
        const detail = byId[source.color_id];
        if (detail) resolved[mask] = detail;
      });

      setAssignments(resolved);
      if (missing.length) {
        setError(`Missing colors: ${missing.join(", ")}`);
      } else {
        setError("");
      }
      setViewMode("after");
    } catch (e) {
      setError(e?.message || "Failed to apply colors");
    }
  }

  function clearAllSelections() {
    setRoleGroups({ body: null, trim: null, accent: null });
    setMaskMap({});
    setAssignments({});
    setViewMode("after");
  }

  function handlePaletteApply(palette) {
    if (!palette || !palette.roles) return;
    const next = { ...roleGroups };
    ROLE_SEQUENCE.forEach((slug) => {
      const sw = palette.roles[slug];
      next[slug] = sw ? normalizePick(sw) : null;
    });
    setRoleGroups(next);
    setPaletteRackOpen(false);
    setViewMode("after");
  }

  function handlePaletteInspect(palette) {
    if (!palette) return;
    setPaletteInspectorData({
      palette_id: palette.palette_id,
      id: palette.palette_id,
      name: palette.nickname || `Palette #${palette.palette_id}`,
      meta: {
        nickname: palette.nickname || "",
        terry_says: "",
        terry_fav: palette.terry_fav || 0,
        tags: palette.tags || [],
      },
    });
    setPaletteRackOpen(false);
  }

  async function savePaletteFromRoles() {
    if (!hasRoleSelections || paletteSaveState.loading) return;
    const payload = { asset_id: asset?.asset_id || null, roles: {} };
    ROLE_SEQUENCE.forEach((slug) => {
      const sw = roleGroups[slug];
      if (sw?.color_id) payload.roles[slug] = Number(sw.color_id);
    });
    if (!Object.keys(payload.roles).length) return;

    setPaletteSaveState({ loading: true, error: "", success: "" });
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/palette-role-save.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to save palette");
      }
      setPaletteSaveState({
        loading: false,
        error: "",
        success: `Saved palette #${data.palette_id}`,
      });
      setPaletteInspectorData({
        palette_id: data.palette_id,
        id: data.palette_id,
        name: data.meta?.nickname || "",
        meta: data.meta || {},
      });
    } catch (err) {
      setPaletteSaveState({
        loading: false,
        error: err?.message || "Failed to save palette",
        success: "",
      });
    }
  }

  /* ---------- Initial search if we arrived w/ q/tags ---------- */
  useEffect(() => {
    if (!assetId && (sQ || sTags)) doSearch({ q: sQ, tagsText: sTags }, sPage || 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const totalPages = Math.max(1, Math.ceil(sTotal / 24));
  const hasRoleSelections = ROLE_SEQUENCE.some((slug) => {
    const sw = roleGroups[slug];
    return sw && sw.color_id;
  });
  const assetTags = Array.isArray(asset?.tags) ? asset.tags : [];


function handleMaskSelect(mask, swatch) {
  setMaskOverrides((prev) => {
    const next = { ...prev };
    if (!swatch) {
      next[mask] = null; // temporarily clear but keep override entry
    } else {
      next[mask] = normalizePick(swatch); // set override
    }
    return next;
  });
}

function handleMaskRevert(mask) {
  setMaskOverrides((prev) => {
    if (!Object.prototype.hasOwnProperty.call(prev, mask)) return prev;
    const next = { ...prev };
    delete next[mask];
    return next;
  });
}

function handleOverlayChange(mask, tier, field, value) {
  setMaskOverlays((prev) => {
    const normalized = normalizeOverlayPayload(prev[mask]);
    const safeTier = OVERLAY_TIERS.includes(tier) ? tier : "medium";
    const nextValue =
      field === "mode"
        ? (value && typeof value === "string" ? value : null)
        : (value === null || value === "" ? null : Number(value));
    return {
      ...prev,
      [mask]: {
        ...normalized,
        [safeTier]: { ...normalized[safeTier], [field]: nextValue },
      },
    };
  });
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { ...(prev[mask] || {}), error: "", success: "" },
  }));
}

function handleTextureChange(mask, value) {
  setMaskTextures((prev) => ({
    ...prev,
    [mask]: (value || "").toLowerCase(),
  }));
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { ...(prev[mask] || {}), error: "", success: "" },
  }));
}

async function handleOverlaySave(mask) {
  if (!asset?.asset_id) return;
  const payload = {
    asset_id: asset.asset_id,
    mask,
    settings: normalizeOverlayPayload(maskOverlays[mask]),
    original_texture: normalizeTextureValue(maskTextures[mask]),
  };
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { saving: true, error: "", success: "" },
  }));
  try {
    const res = await fetch(`${API_FOLDER}/v2/admin/mask-overlay.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || "Failed to save blend settings");
    }
    const normalized = normalizeOverlayPayload(data.settings || payload.settings);
    setMaskOverlays((prev) => ({ ...prev, [mask]: normalized }));
    setMaskTextures((prev) => ({
      ...prev,
      [mask]: normalizeTextureValue(data.original_texture ?? payload.original_texture),
    }));
    setAsset((prev) => {
      if (!prev) return prev;
      return {
        ...prev,
        masks: (prev.masks || []).map((m) =>
          m.role === mask ? { ...m, original_texture: normalizeTextureValue(data.original_texture ?? payload.original_texture) } : m
        ),
      };
    });
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { saving: false, error: "", success: "Saved" },
    }));
  } catch (err) {
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { saving: false, error: err?.message || "Failed to save", success: "" },
    }));
  }
}







  return (
    <div className="admin-photo-preview">


      <div className="title">Admin Photo Preview</div>
      <div className="app-bar">
        <div className="left">
          <button className="btn" onClick={() => setFindOpen(!findOpen)}>
            {findOpen ? "Hide Finder" : "Find Photo"}
          </button>
          <div className="view-switch">
            <button
              className={"tab" + (viewMode === "before" ? " is-active" : "")}
              onClick={() => setViewMode("before")}
            >
              Before
            </button>
            <button
              className={"tab" + (viewMode === "prepared" ? " is-active" : "")}
              onClick={() => setViewMode("prepared")}
              title="Admin-only"
            >
              Prepared
            </button>
            <button
              className={"tab" + (viewMode === "after" ? " is-active" : "")}
              onClick={() => setViewMode("after")}
            >
              After
            </button>
          </div>
          <button className="btn" onClick={() => setPaletteRackOpen(true)}>
            See Palettes
          </button>
        </div>
        <div className="right">
          <RolesBar
            values={roleGroups}
            onRoleChange={(role, swatch) =>
              setRoleGroups(prev => ({ ...prev, [role]: normalizePick(swatch) }))
            }
            size="sm"
            showNames
          />
        </div>
      </div>

      {findOpen && (
        <div className="finder">
          <SearchBar
            initialQ={sQ}
            initialTags={sTags}
            onSearch={(payload) => doSearch(payload, 1)}
          />
          {sLoading && <div className="notice">Loading…</div>}
          {sError && <div className="error">{sError}</div>}
          <PhotoGrid items={sItems} onPick={onPick} emptyText="No photos matched." />
          {totalPages > 1 && (
            <div className="pager">
              <button
                className="btn"
                disabled={sPage <= 1}
                onClick={() => doSearch({ q: sQ, tagsText: sTags }, sPage - 1)}
              >
                Prev
              </button>
              <div className="page-info">{sPage} / {totalPages}</div>
              <button
                className="btn"
                disabled={sPage >= totalPages}
                onClick={() => doSearch({ q: sQ, tagsText: sTags }, sPage + 1)}
              >
                Next
              </button>
            </div>
          )}
        </div>
      )}

      <div className="content">
        {!assetId && !findOpen && <div className="notice">Use “Find Photo” to select an image.</div>}
        {loading && <div className="notice">Loading asset…</div>}
        {error && <div className="error">{error}</div>}

        {asset && (
          <div className="preview-grid">
            <div className="renderer-col">
              <PhotoRenderer
                asset={asset}
                assignments={assignments}
                viewMode={viewMode}
                onStateChange={setRenderState}
              />
              <div className="renderer-meta">
                <span className="meta-row"><strong>Asset:</strong> {asset.asset_id}</span>
                <span className="meta-row"><strong>View:</strong> {viewMode}</span>
                <span className="meta-row">
                  <strong>Roles:</strong> {Object.keys(assignments).length ? Object.keys(assignments).join(", ") : "none"}
                </span>
              </div>
              {(renderState.loading || renderState.error) && (
                <div className="renderer-status">
                  {renderState.loading && <span className="status">Rendering…</span>}
                  {renderState.error && <span className="error">{renderState.error}</span>}
                </div>
              )}
            </div>

            <div className="actions-column">
              <button type="button" className="btn primary" onClick={applyColors}>Apply Colors</button>
              <button type="button" className="btn" onClick={clearAllSelections}>Clear All</button>
              <button type="button" className="btn" onClick={() => {/* noop download placeholder */}}>
                Download
              </button>
              <button
                type="button"
                className="btn"
                disabled={!hasRoleSelections || paletteSaveState.loading}
                onClick={savePaletteFromRoles}
              >
                {paletteSaveState.loading ? "Saving…" : "Save Palette"}
              </button>
              {paletteSaveState.error && (
                <div className="error" style={{ marginTop: 10 }}>{paletteSaveState.error}</div>
              )}
              {paletteSaveState.success && (
                <div className="notice" style={{ marginTop: 10 }}>{paletteSaveState.success}</div>
              )}
            </div>

            {/* Controls drawer */}
            <aside className={`controls-drawer ${controlsOpen ? "open" : ""}`} aria-hidden={!controlsOpen}>
              <div className="drawer-header">
                <div className="drawer-title">Assign Colors</div>
                <button className="drawer-close" onClick={() => setControlsOpen(false)} aria-label="Close controls">✕</button>
              </div>

              <div className="panel">
                <MasksPanel
                  masks={asset.masks}
                  roleGroups={roleGroups}
                  overrides={maskOverrides}
                  maskToRoleGroup={maskToRoleGroup}
                  onSelect={handleMaskSelect}
                  onRevert={handleMaskRevert}
                  onEditOverlay={(mask) => setOverlayModalMask(mask)}
                />
              </div>

              <div className="panel">
                <div className="panel-title">Notes</div>
              </div>
            </aside>

            {/* Floating action button (mobile only) */}
            <button
              className="fab"
              onClick={() => setControlsOpen(true)}
              aria-label="Open controls"
            >
              Colors
            </button>
          </div>
        )}
      </div>
      {paletteInspectorData && (
        <PaletteInspector
          palette={paletteInspectorData}
          onClose={() => setPaletteInspectorData(null)}
          onPatched={(meta) =>
            setPaletteInspectorData((prev) =>
              prev ? { ...prev, meta: { ...(prev.meta || {}), ...meta } } : prev
            )
          }
          topOffset={64}
        />
      )}
      <PaletteRack
        open={paletteRackOpen}
        tags={assetTags}
        onClose={() => setPaletteRackOpen(false)}
        onApply={handlePaletteApply}
        onInspect={handlePaletteInspect}
      />
      {overlayModalMask && (
        <div className="overlay-modal" role="dialog" aria-modal="true">
          <div className="overlay-modal__backdrop" onClick={() => setOverlayModalMask(null)} />
          <div className="overlay-modal__panel">
            <div className="overlay-modal__header">
              <div className="overlay-modal__title">
                Edit Blend · {overlayModalMask}
              </div>
              <button
                type="button"
                className="overlay-modal__close"
                onClick={() => setOverlayModalMask(null)}
              >
                ✕
              </button>
            </div>
            <MaskOverlayEditor
              masks={asset?.masks?.filter((m) => m.role === overlayModalMask) || []}
              overlays={maskOverlays}
              textures={maskTextures}
              onChange={handleOverlayChange}
              onTextureChange={handleTextureChange}
              onSave={handleOverlaySave}
              status={overlayStatus}
              textureOptions={MASK_TEXTURE_OPTIONS}
              onCancel={() => setOverlayModalMask(null)}
            />
          </div>
        </div>
      )}
    </div>
  );
}
