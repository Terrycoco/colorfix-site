import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import PhotoRenderer from "@components/PhotoRenderer";
import MaskBlendHistory from "@components/MaskBlendHistory";
import MaskOverlayModal from "@components/MaskOverlayModal";
import { API_FOLDER } from "@helpers/config";
import "./masktester.css";
import { overlayPresetConfig, bucketForLightness } from "@config/overlayPresets";

const ROLE_SEQUENCE = ["body", "trim", "accent"];
const MASK_TEXTURE_OPTIONS = ["smooth_flat", "rough_stucco", "semi_gloss", "textured_wood", "small_detail"];
const ROLE_TO_MASKS = {
  body: ["body", "stucco", "siding", "brick"],
  trim: ["trim", "fascia", "bellyband", "gutter", "windowtrim", "garage", "railing"],
  accent: ["accent", "frontdoor", "door", "shutters"],
};
const makeSkipSwatch = () => ({ __skip: true });
const isSkipSwatch = (sw) => !!(sw && sw.__skip);

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
export default function AdminMaskTesterPage() {
  const query = useQuery();
  const navigate = useNavigate();
  const assetId = query.get("asset") || "";
  const initialMaskParam = query.get("mask") || "";

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
  const [viewMode] = useState("after"); // fixed to "after" for testing mode


  // role-level colors (Body/Trim/Accent)
  const [roleGroups, setRoleGroups] = useState({
    body: null,
    trim: null,
    accent: null,
  });

  // mask-level overrides (e.g. fascia manually changed)
  const [maskOverrides, setMaskOverrides] = useState({});
  const [maskOverlays, setMaskOverlays] = useState({});
  const [maskTextures, setMaskTextures] = useState({});
  const [overlayStatus, setOverlayStatus] = useState({});
  const [overlayBaselines, setOverlayBaselines] = useState({});
  const [textureBaselines, setTextureBaselines] = useState({});
  const [selectedMask, setSelectedMask] = useState(initialMaskParam);
  const [saveModalOpen, setSaveModalOpen] = useState(false);
  const [saveTitle, setSaveTitle] = useState("");
  const [saveNotes, setSaveNotes] = useState("");
  const [saveClientName, setSaveClientName] = useState("");
  const [saveClientEmail, setSaveClientEmail] = useState("");
  const [saveClientPhone, setSaveClientPhone] = useState("");
  const [saveClientNotes, setSaveClientNotes] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saveBusy, setSaveBusy] = useState(false);
  const [saveResult, setSaveResult] = useState(null);
  const [saveRenderChoice, setSaveRenderChoice] = useState("skip");

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
        navigate(`/admin/mask-tester?${nav.toString()}`, { replace: true });
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
    navigate(`/admin/mask-tester?${nav.toString()}`);
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
      setOverlayBaselines({});
      setTextureBaselines({});
      setOverlayStatus({});
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
          photo_id: typeof data.photo_id === "number" ? data.photo_id : null,
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
                base_lightness: typeof m.base_lightness === "number" ? Number(m.base_lightness) : null,
              }))
            : [],
          precomputed: data.precomputed || undefined,
        };
        const overlayMap = {};
        const overlayBaselineMap = {};
        const textureMap = {};
        const textureBaselineMap = {};
        (data.masks || []).forEach((m) => {
          if (!m?.role) return;
          const normalizedOverlay = cloneOverlayStruct(m.overlay);
          overlayMap[m.role] = normalizedOverlay;
          overlayBaselineMap[m.role] = cloneOverlayStruct(normalizedOverlay);
          const normalizedTexture = normalizeTextureValue(m.original_texture);
          textureMap[m.role] = normalizedTexture;
          textureBaselineMap[m.role] = normalizedTexture;
        });
        setAsset(normalized);
        setMaskOverlays(overlayMap);
        setOverlayBaselines(overlayBaselineMap);
        setMaskTextures(textureMap);
        setTextureBaselines(textureBaselineMap);
        setMaskOverrides({});
        setOverlayStatus({});
        setRoleGroups({ body: null, trim: null, accent: null });
        setAssignments({});
        const firstMask = (data.masks && data.masks.length > 0) ? data.masks[0].role : "";
        setSelectedMask(firstMask || "");
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
    const lightnessRaw =
      obj.lightness ??
      obj.lab_l ??
      obj.L ??
      obj.hcl_l ??
      obj.hcl?.l ??
      null;
    const lightness = Number.isFinite(Number(lightnessRaw))
      ? Number(lightnessRaw)
      : null;
    return {
      ...obj,
      color_id: Number(colorId),
      hex6: hex6.toUpperCase(),
      lightness,
    };
  }

  function lightnessFromSwatch(sw) {
    if (!sw) return null;
    if (typeof sw.lightness === "number" && Number.isFinite(sw.lightness)) return Number(sw.lightness);
    if (typeof sw.lab_l === "number" && Number.isFinite(sw.lab_l)) return Number(sw.lab_l);
    if (typeof sw.hcl_l === "number" && Number.isFinite(sw.hcl_l)) return Number(sw.hcl_l);
    return null;
  }

  function blankShadow() {
    return { l_offset: 0, tint_hex: null, tint_opacity: 0 };
  }

  function blankOverlay() {
    const base = OVERLAY_TIERS.reduce((acc, tier) => {
      acc[tier] = { mode: null, opacity: null };
      return acc;
    }, {});
    base._shadow = blankShadow();
    return base;
  }

  function normalizeShadowStruct(raw) {
    const src = raw && typeof raw === "object" ? raw : {};
    const offset = typeof src.l_offset === "number" && Number.isFinite(src.l_offset)
      ? Math.max(-50, Math.min(50, src.l_offset))
      : 0;
    let tint = src.tint_hex;
    if (typeof tint === "string") {
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
    const tintOpacity = typeof src.tint_opacity === "number" && Number.isFinite(src.tint_opacity)
      ? Math.max(0, Math.min(1, src.tint_opacity))
      : 0;
    return { l_offset: offset, tint_hex: tint, tint_opacity: tintOpacity };
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
    base._shadow = normalizeShadowStruct(raw?._shadow);
    return base;
  }

  function normalizeTextureValue(value) {
    if (typeof value !== "string") return "";
    return value.trim().toLowerCase();
  }

  const cloneOverlayStruct = (raw) => normalizeOverlayPayload(raw || {});
  const normalizeHex6 = (value) => {
    if (!value) return null;
    let hex = value.trim();
    if (hex.startsWith("#")) hex = hex.slice(1);
    if (hex.length === 3) {
      hex = hex.split("").map((c) => c + c).join("");
    }
    hex = hex.toUpperCase();
    return /^[0-9A-F]{6}$/.test(hex) ? hex : null;
  };

  const shadowsEqual = (a, b) =>
    (a?.l_offset ?? 0) === (b?.l_offset ?? 0) &&
    (a?.tint_hex || null) === (b?.tint_hex || null) &&
    (Math.round((a?.tint_opacity ?? 0) * 1000) === Math.round((b?.tint_opacity ?? 0) * 1000));

  const overlaysEqualStruct = (aRaw, bRaw) => {
    const a = cloneOverlayStruct(aRaw);
    const b = cloneOverlayStruct(bRaw);
    const tiersEqual = OVERLAY_TIERS.every((tier) => {
      const modeA = a[tier].mode || null;
      const modeB = b[tier].mode || null;
      const opA = a[tier].opacity == null ? null : Number(a[tier].opacity);
      const opB = b[tier].opacity == null ? null : Number(b[tier].opacity);
      return modeA === modeB && opA === opB;
    });
    return tiersEqual && shadowsEqual(a._shadow, b._shadow);
  };

  const computeDirtyFlag = (mask, nextOverlayStruct, nextTextureValue) => {
    const baselineOverlay = overlayBaselines[mask] || blankOverlay();
    const baselineTexture = textureBaselines[mask] ?? "";
    const currentOverlay = cloneOverlayStruct(nextOverlayStruct ?? maskOverlays[mask] ?? {});
    const textureCandidate =
      nextTextureValue != null ? nextTextureValue : maskTextures[mask] || "";
    const currentTexture = normalizeTextureValue(textureCandidate);
    return (
      !overlaysEqualStruct(currentOverlay, baselineOverlay) ||
      currentTexture !== (baselineTexture || "")
    );
  };

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

  const selectedMaskData = useMemo(() => {
    if (!asset || !Array.isArray(asset.masks)) return null;
    return asset.masks.find((m) => m.role === selectedMask) || null;
  }, [asset, selectedMask]);

  function getAssignedSwatchForMask(mask) {
    if (maskOverrides[mask]) {
      if (isSkipSwatch(maskOverrides[mask])) return null;
      return maskOverrides[mask];
    }
    const group = defaultRoleByMask[mask] || maskToRoleGroup(mask);
    return roleGroups[group] || null;
  }

  const maskSuggestions = useMemo(() => {
    if (!asset) return {};
    const map = {};
    (asset.masks || []).forEach((mask) => {
      const role = mask.role;
      const baseL = typeof mask.base_lightness === "number" ? mask.base_lightness : null;
      const swatch = getAssignedSwatchForMask(role);
      const targetL = lightnessFromSwatch(swatch);
      if (baseL == null || targetL == null) return;
      const baseBucket = bucketForLightness(baseL, overlayPresetConfig.baseBuckets);
      const targetBucket = bucketForLightness(targetL, overlayPresetConfig.targetBuckets);
      if (!baseBucket || !targetBucket) return;
      const preset = overlayPresetConfig.grid?.[baseBucket]?.[targetBucket];
      if (!preset) return;
      map[role] = {
        baseLightness: baseL,
        targetLightness: targetL,
        baseBucket,
        targetBucket,
        preset,
      };
    });
    return map;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [asset, maskOverrides, roleGroups]);

  const paletteEntries = useMemo(() => {
    if (!asset) return [];
    const rows = [];
    (asset.masks || []).forEach((mask) => {
      const maskRole = mask.role;
      if (!maskRole) return;
      const override = maskOverrides[maskRole];
      const explicitSkip = override && isSkipSwatch(override);
      const swatch = explicitSkip ? null : getAssignedSwatchForMask(maskRole);
      if (!swatch && !explicitSkip) return;
      const overlay = cloneOverlayStruct(maskOverlays[maskRole]);
      const targetLightness = swatch ? lightnessFromSwatch(swatch) : null;
      const tierKey = targetLightness != null
        ? bucketForLightness(targetLightness, overlayPresetConfig.targetBuckets)
        : null;
      const tier = tierKey ? overlay[tierKey] : null;
      const hex6 = swatch ? normalizeHex6(swatch.hex6 || swatch.hex) : null;
      const shadow = overlay._shadow || blankShadow();
      rows.push({
        mask_role: maskRole,
        color_id: swatch?.color_id ?? null,
        color_name: explicitSkip ? "Original Photo" : (swatch?.name || swatch?.label || swatch?.code || ""),
        color_brand: swatch?.brand || swatch?.color_brand || null,
        color_code: swatch?.code || swatch?.color_code || null,
        color_hex: hex6,
        blend_mode: explicitSkip ? "original" : (tier?.mode || null),
        blend_opacity: explicitSkip ? 0 : (typeof tier?.opacity === "number" ? tier.opacity : null),
        shadow_l_offset: shadow.l_offset ?? 0,
        shadow_tint_hex: shadow.tint_hex ? shadow.tint_hex.replace("#", "").toUpperCase() : null,
        shadow_tint_opacity: shadow.tint_opacity ?? 0,
        base_lightness: mask?.base_lightness ?? null,
        target_lightness: targetLightness,
        bucket_label: tierKey,
        is_original: explicitSkip,
        has_color: !!swatch,
      });
    });
    return rows;
  }, [asset, maskOverrides, roleGroups, maskOverlays]);

  const hasPaletteEntries = paletteEntries.length > 0;

  /* ---------- Apply to renderer ---------- */
  async function applyColors(forcedOverrides = null) {
    try {
      setError("");
      const overridesMap = forcedOverrides || maskOverrides;

      const collectSwatch = (sw) => {
        if (isSkipSwatch(sw)) return { __skip: true };
        const norm = normalizePick(sw);
        if (!norm) return null;
        const idNum = Number(norm.color_id);
        if (!Number.isFinite(idNum)) return null;
        return { ...norm, color_id: idNum };
      };

      const requiredSwatches = [];
      ROLE_SEQUENCE.forEach((group) => {
        const sw = collectSwatch(roleGroups[group]);
        if (sw && !sw.__skip) requiredSwatches.push(sw);
      });
      Object.values(overridesMap || {}).forEach((sw) => {
        const norm = collectSwatch(sw);
        if (norm && !norm.__skip) requiredSwatches.push(norm);
      });

      if (!requiredSwatches.length) {
        setAssignments({});
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
        const override = collectSwatch((overridesMap || {})[mask]);
        if (override && override.__skip) {
          resolved[mask] = { __skip: true };
          return;
        }
        const fallbackRole = maskToRoleGroup(mask);
        const roleSwatch = collectSwatch(roleGroups[fallbackRole]);
        const source = override || roleSwatch;
        if (!source || source.__skip) return;
        const detail = byId[source.color_id];
        if (detail) resolved[mask] = detail;
      });

      setAssignments(resolved);
      if (missing.length) {
        setError(`Missing colors: ${missing.join(", ")}`);
      } else {
        setError("");
      }
    } catch (e) {
      setError(e?.message || "Failed to apply colors");
    }
  }

  function clearAllSelections() {
    setRoleGroups({ body: null, trim: null, accent: null });
    setAssignments({});
  }

  /* ---------- Initial search if we arrived w/ q/tags ---------- */
  useEffect(() => {
    if (!assetId && (sQ || sTags)) doSearch({ q: sQ, tagsText: sTags }, sPage || 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!asset?.masks?.length) {
      setSelectedMask("");
      return;
    }
    const maskRoles = asset.masks.map((m) => m.role);
    if (selectedMask && maskRoles.includes(selectedMask)) return;
    if (initialMaskParam && maskRoles.includes(initialMaskParam)) {
      setSelectedMask(initialMaskParam);
    } else {
      setSelectedMask(maskRoles[0]);
    }
  }, [asset, selectedMask, initialMaskParam]);

  const totalPages = Math.max(1, Math.ceil(sTotal / 24));


function handleOverlayChange(mask, tier, field, value) {
  const current = cloneOverlayStruct(maskOverlays[mask]);
  const safeTier = OVERLAY_TIERS.includes(tier) ? tier : "medium";
  const nextValue =
    field === "mode"
      ? (value && typeof value === "string" ? value : null)
      : (value === null || value === "" ? null : Number(value));
  const nextOverlay = {
    ...current,
    [safeTier]: { ...current[safeTier], [field]: nextValue },
  };
  setMaskOverlays((prev) => ({
    ...prev,
    [mask]: nextOverlay,
  }));
  const dirty = computeDirtyFlag(mask, nextOverlay, maskTextures[mask]);
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { ...(prev[mask] || {}), error: "", success: "", dirty },
  }));
}

function handleTextureChange(mask, value) {
  const normalizedValue = normalizeTextureValue(value);
  setMaskTextures((prev) => ({
    ...prev,
    [mask]: normalizedValue,
  }));
  const dirty = computeDirtyFlag(mask, maskOverlays[mask], normalizedValue);
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { ...(prev[mask] || {}), error: "", success: "", dirty },
  }));
}

async function reloadOverlayFromServer(mask, { silent = false } = {}) {
  if (!asset?.asset_id) return;
  const assetId = asset.asset_id;
  if (!silent) {
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { ...(prev[mask] || {}), refreshing: true, error: "", success: "" },
    }));
  }
  try {
    const res = await fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(assetId)}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    const data = await res.json();
    if (!res.ok || data?.error) {
      throw new Error(data?.message || data?.error || "Failed to load mask");
    }
    const maskData = Array.isArray(data?.masks)
      ? data.masks.find((m) => m?.role === mask)
      : null;
    if (!maskData) throw new Error("Mask not found on server");
    const refreshedOverlay = cloneOverlayStruct(maskData.overlay || {});
    const refreshedTexture = normalizeTextureValue(maskData.original_texture || "");
    setMaskOverlays((prev) => ({
      ...prev,
      [mask]: refreshedOverlay,
    }));
    setOverlayBaselines((prev) => ({
      ...prev,
      [mask]: cloneOverlayStruct(refreshedOverlay),
    }));
    setMaskTextures((prev) => ({
      ...prev,
      [mask]: refreshedTexture,
    }));
    setTextureBaselines((prev) => ({
      ...prev,
      [mask]: refreshedTexture,
    }));
    if (!silent) {
      setOverlayStatus((prev) => ({
        ...prev,
        [mask]: { ...(prev[mask] || {}), refreshing: false, error: "", success: "Reloaded", dirty: false },
      }));
    }
  } catch (err) {
    if (!silent) {
      setOverlayStatus((prev) => ({
        ...prev,
        [mask]: { ...(prev[mask] || {}), refreshing: false, error: err?.message || "Failed to reload", success: "" },
      }));
    }
  } finally {
    if (silent) {
      setOverlayStatus((prev) => ({
        ...prev,
        [mask]: { ...(prev[mask] || {}), refreshing: false, dirty: false },
      }));
    }
  }
}

async function handleApplyPresetValues(mask, tier, preset, options = {}) {
  if (!preset) return false;
  const { autoSave = false, autoRender = false, swatch = null, shadow = null, clearColor = false } = options;
  const currentOverlay = normalizeOverlayPayload(maskOverlays[mask]);
  const updated = {
    ...currentOverlay,
    [tier]: {
      mode: preset.mode ?? null,
      opacity: typeof preset.opacity === "number" ? preset.opacity : null,
    },
  };
  if (shadow && typeof shadow === "object") {
    const normalizedShadow = normalizeShadowStruct({
      l_offset: shadow.l_offset,
      tint_hex: shadow.tint_hex,
      tint_opacity: shadow.tint_opacity,
    });
    updated._shadow = normalizedShadow;
  }
  const normalizedMode = typeof preset.mode === "string" ? preset.mode.toLowerCase() : "";
  const shouldClearColor = clearColor || normalizedMode === "original";

  let nextOverrides = maskOverrides;
  if (shouldClearColor) {
    nextOverrides = { ...(maskOverrides || {}) };
    nextOverrides[mask] = makeSkipSwatch();
    setMaskOverrides(nextOverrides);
  } else if (swatch) {
    const normalizedSwatch = normalizePick(swatch);
    if (normalizedSwatch) {
      nextOverrides = { ...(maskOverrides || {}), [mask]: normalizedSwatch };
      setMaskOverrides(nextOverrides);
    }
  }
  setMaskOverlays((prev) => ({
    ...prev,
    [mask]: updated,
  }));
  if (!autoSave) {
    const dirty = computeDirtyFlag(mask, updated, maskTextures[mask]);
    const statusLabel = shouldClearColor
      ? "Original Photo"
      : `${preset.mode} · ${Math.round((preset.opacity ?? 0) * 100)}%`;
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { ...(prev[mask] || {}), error: "", success: `Applied ${statusLabel}`, dirty },
    }));
    return true;
  }
  try {
    await handleOverlaySave(mask, { overrideSettings: updated });
    if (autoRender) {
      await applyColors(nextOverrides);
    }
    return true;
  } catch (err) {
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { ...(prev[mask] || {}), error: err?.message || "Failed to apply preset", success: "" },
    }));
    throw err;
  }
}

async function handleOverlaySave(mask, { overrideSettings = null } = {}) {
  if (!asset?.asset_id) return false;
  const payload = {
    asset_id: asset.asset_id,
    mask,
    settings: normalizeOverlayPayload(overrideSettings || maskOverlays[mask]),
    original_texture: normalizeTextureValue(maskTextures[mask]),
  };
  setOverlayStatus((prev) => ({
    ...prev,
    [mask]: { ...(prev[mask] || {}), saving: true, error: "", success: "" },
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
    const normalizedOverlay = cloneOverlayStruct(data.settings || payload.settings);
    const normalizedTexture = normalizeTextureValue(data.original_texture ?? payload.original_texture);
    setMaskOverlays((prev) => ({ ...prev, [mask]: normalizedOverlay }));
    setOverlayBaselines((prev) => ({ ...prev, [mask]: cloneOverlayStruct(normalizedOverlay) }));
    setMaskTextures((prev) => ({
      ...prev,
      [mask]: normalizedTexture,
    }));
    setTextureBaselines((prev) => ({ ...prev, [mask]: normalizedTexture }));
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
      [mask]: { saving: false, error: "", success: "Saved", dirty: false },
    }));
    return true;
  } catch (err) {
    setOverlayStatus((prev) => ({
      ...prev,
      [mask]: { ...(prev[mask] || {}), saving: false, error: err?.message || "Failed to save", success: "" },
    }));
    throw err;
  }
}

  function openSaveModal() {
    setSaveModalOpen(true);
    setSaveError("");
    setSaveResult(null);
  }

  function closeSaveModal() {
    if (saveBusy) return;
    setSaveModalOpen(false);
    setSaveError("");
    setSaveResult(null);
  }

  async function handleSaveAppliedPalette() {
    if (saveBusy || !asset?.asset_id || !hasPaletteEntries) return;
    setSaveBusy(true);
    setSaveError("");
    setSaveResult(null);
    const clientPayload = {};
    if (saveClientName.trim()) clientPayload.name = saveClientName.trim();
    if (saveClientEmail.trim()) clientPayload.email = saveClientEmail.trim();
    if (saveClientPhone.trim()) clientPayload.phone = saveClientPhone.trim();
    if (saveClientNotes.trim()) clientPayload.notes = saveClientNotes.trim();
    const payload = {
      asset_id: asset.asset_id,
      title: saveTitle.trim(),
      notes: saveNotes.trim(),
      entries: paletteEntries.map((entry) => ({
        mask_role: entry.mask_role,
        color_id: entry.color_id,
        color_name: entry.color_name,
        color_brand: entry.color_brand,
        color_code: entry.color_code,
        color_hex: entry.color_hex,
        blend_mode: entry.blend_mode,
        blend_opacity: entry.blend_opacity,
        shadow_l_offset: entry.shadow_l_offset,
        shadow_tint_hex: entry.shadow_tint_hex,
        shadow_tint_opacity: entry.shadow_tint_opacity,
      })),
    };
    if (saveRenderChoice === "generate") {
      payload.render = { cache: true };
    }
    if (Object.keys(clientPayload).length) {
      payload.client = clientPayload;
    }
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/applied-palettes/save.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to save palette");
      }
      const viewUrl = typeof window !== "undefined"
        ? `${window.location.origin}/view/${data.palette_id}?admin=1`
        : `/view/${data.palette_id}`;
      setSaveResult({
        palette_id: data.palette_id,
        entries: data.entries_saved,
        render_cache: data.render_cache || null,
        render_cache_error: data.render_cache_error || null,
        view_url: viewUrl,
      });
    } catch (err) {
      setSaveError(err?.message || "Failed to save palette");
    } finally {
      setSaveBusy(false);
    }
  }

  return (
    <div className="admin-mask-tester">
      <div className="title">Mask Tester</div>
      <div className="app-bar">
        <div className="left">
          <button className="btn" onClick={() => setFindOpen(!findOpen)}>
            {findOpen ? "Hide Finder" : "Find Photo"}
          </button>
        </div>
        <div className="right">
          {asset && (
            <button
              className="btn primary"
              disabled={!hasPaletteEntries}
              onClick={openSaveModal}
              title={hasPaletteEntries ? "Save this applied palette" : "Apply at least one mask color to enable saving"}
            >
              Save Applied Palette
            </button>
          )}
        </div>
      </div>

      {asset && (
        <div className="mask-picker-row">
          <div className="mask-picker-field">
            <label>Mask / Role</label>
            <select
              value={selectedMask}
              onChange={(e) => setSelectedMask(e.target.value)}
            >
              {(asset.masks || []).map((m) => (
                <option key={m.role} value={m.role}>
                  {m.role}
                </option>
              ))}
            </select>
          </div>
          {selectedMaskData && (
            <div className="mask-picker-meta">
              <span>Texture: {selectedMaskData.original_texture || "—"}</span>
              <span>Original L: {selectedMaskData.base_lightness != null ? selectedMaskData.base_lightness.toFixed(1) : "—"}</span>
            </div>
          )}
        </div>
      )}

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

      </div>
      {asset && (
        <div className="mask-tester-layout">
          <div className="tester-left">
            {selectedMask ? (
              <MaskBlendHistory
                assetId={asset.asset_id}
                maskRole={selectedMask}
                baseLightness={selectedMaskData?.base_lightness ?? null}
                onApplyBlend={(mask, tier, preset, extra) =>
                  handleApplyPresetValues(mask, tier, preset, {
                    autoSave: true,
                    autoRender: true,
                    ...(extra || {}),
                  })
                }
              />
            ) : (
              <div className="panel tester-placeholder">Select a mask above to see tested colors.</div>
            )}
          </div>
          <div className="tester-right">
            <div className="panel renderer-panel">
              <PhotoRenderer
                asset={asset}
                assignments={assignments}
                viewMode={viewMode}
                onStateChange={setRenderState}
              />
            </div>
            <div />
          </div>
        </div>
      )}
      {saveModalOpen && (
        <MaskOverlayModal
          title="Save Applied Palette"
          subtitle={asset?.asset_id || ""}
          onClose={closeSaveModal}
        >
          <div className="save-palette-form">
            <div className="save-palette-fields">
              <label>
                Title / Nickname
                <input
                  type="text"
                  value={saveTitle}
                  onChange={(e) => setSaveTitle(e.target.value)}
                  placeholder="e.g., Manny · Moody Blues"
                />
              </label>
              <label>
                Notes
                <textarea
                  value={saveNotes}
                  onChange={(e) => setSaveNotes(e.target.value)}
                  rows={2}
                  placeholder="Optional internal notes"
                />
              </label>
            </div>
            <div className="save-palette-fields">
              <div className="field-row">
                <label>
                  Client Name
                  <input
                    type="text"
                    value={saveClientName}
                    onChange={(e) => setSaveClientName(e.target.value)}
                  />
                </label>
                <label>
                  Client Email
                  <input
                    type="email"
                    value={saveClientEmail}
                    onChange={(e) => setSaveClientEmail(e.target.value)}
                  />
                </label>
              </div>
              <div className="field-row">
                <label>
                  Client Phone
                  <input
                    type="text"
                    value={saveClientPhone}
                    onChange={(e) => setSaveClientPhone(e.target.value)}
                  />
                </label>
                <label>
                  Client Notes
                  <textarea
                    value={saveClientNotes}
                    onChange={(e) => setSaveClientNotes(e.target.value)}
                    rows={2}
                  />
                </label>
              </div>
            </div>
            <div className="save-palette-summary">
              {paletteEntries.length === 0 && (
                <div className="notice">No masks selected yet.</div>
              )}
              {paletteEntries.length > 0 && (
                <div className="save-entry-list">
                  {paletteEntries.map((entry) => (
                    <div key={entry.mask_role} className="save-entry-row">
                      <div className="save-entry-mask">{entry.mask_role}</div>
                      <div className="save-entry-color">
                        {entry.is_original ? (
                          <span className="original-pill">Original photo</span>
                        ) : (
                          <>
                            <span
                              className="color-chip"
                              style={{ backgroundColor: entry.color_hex ? `#${entry.color_hex}` : "#ccc" }}
                            />
                            <span className="color-name">
                              {entry.color_name || "Untitled"}{entry.color_code ? ` · ${entry.color_code}` : ""}
                            </span>
                            {entry.color_brand && (
                              <span className="color-brand">{entry.color_brand.toUpperCase()}</span>
                            )}
                          </>
                        )}
                      </div>
                      <div className="save-entry-mode">
                        <span className="mode">{entry.blend_mode || "colorize"}</span>
                        {entry.blend_opacity != null && (
                          <span className="opacity">{Math.round((entry.blend_opacity || 0) * 100)}%</span>
                        )}
                      </div>
                      <div className="save-entry-shadow">
                        <span>ΔL {entry.shadow_l_offset ?? 0}</span>
                        {entry.shadow_tint_hex && (
                          <span> tint #{entry.shadow_tint_hex}</span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="save-render-choice">
              <label>
                <input
                  type="checkbox"
                  checked={saveRenderChoice === "generate"}
                  onChange={(e) => setSaveRenderChoice(e.target.checked ? "generate" : "skip")}
                />
                <span>Also cache a rendered photo & thumbnail (takes a few seconds)</span>
              </label>
            </div>
            {saveError && <div className="error">{saveError}</div>}
            {saveResult && (
              <div className="notice">
                <div>
                  Saved palette #{saveResult.palette_id}
                  {saveResult.view_url && (
                    <>
                      {" · "}
                      <a href={saveResult.view_url} target="_blank" rel="noreferrer">
                        open viewer
                      </a>
                    </>
                  )}
                </div>
                {saveResult.render_cache?.render_url && (
                  <div className="render-cache-line">
                    Cached render:{" "}
                    <a href={saveResult.render_cache.render_url} target="_blank" rel="noreferrer">
                      open image
                    </a>
                    {saveResult.render_cache.thumb_url && (
                      <>
                        {" · "}
                        <a href={saveResult.render_cache.thumb_url} target="_blank" rel="noreferrer">
                          view thumbnail
                        </a>
                      </>
                    )}
                  </div>
                )}
                {saveResult.render_cache_error && (
                  <div className="render-cache-error">Render cache error: {saveResult.render_cache_error}</div>
                )}
              </div>
            )}
            <div className="save-palette-actions">
              <button className="btn" type="button" onClick={closeSaveModal} disabled={saveBusy}>
                Close
              </button>
              <button
                className="btn primary"
                type="button"
                disabled={!hasPaletteEntries || saveBusy}
                onClick={handleSaveAppliedPalette}
              >
                {saveBusy ? "Saving…" : "Save Palette"}
              </button>
            </div>
          </div>
        </MaskOverlayModal>
      )}
    </div>
  );
}
