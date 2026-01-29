import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import PhotoRenderer from "@components/PhotoRenderer";
import MaskBlendHistory from "@components/MaskBlendHistory";
import MaskOverlayModal from "@components/MaskOverlayModal";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import MaskSettingsGrid from "@components/MaskSettingsGrid";
import AppliedPaletteMaskEditor from "@components/AppliedPaletteMaskEditor";
import PhotoSearchPicker from "@components/PhotoSearchPicker";
import { API_FOLDER } from "@helpers/config";
import {
  buildPreviewAssignments,
  normalizeEntryForSave,
  normalizeShadowStruct,
  getMaskBlendSettingForColor,
  mergeMaskBlendSetting,
  resolveBlendOpacity,
  resolveTargetBucket,
} from "@helpers/maskRenderUtils";
import "./masktester.css";
import { overlayPresetConfig, bucketForLightness } from "@config/overlayPresets";
const TESTER_COLORS_URL = `${API_FOLDER}/v2/mask-training/tester-colors.php`;
const GUESS_URL = `${API_FOLDER}/v2/mask-training/guess.php`;
const HOA_LIST_URL = `${API_FOLDER}/v2/admin/hoas/list.php`;
const HOA_COLORS_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/by-hoa.php`;

const ROLE_SEQUENCE = ["body", "trim", "accent"];
const OVERLAY_TIERS = ["dark", "medium", "light"];
const MASK_TEXTURE_OPTIONS = ["smooth_flat", "rough_stucco", "semi_gloss", "textured_wood", "small_detail"];
const ROLE_TO_MASKS = {
  body: ["body", "stucco", "siding", "brick"],
  trim: ["trim", "fascia", "bellyband", "gutter", "windowtrim", "garage", "railing"],
  accent: ["accent", "frontdoor", "door", "shutters"],
};
const makeSkipSwatch = () => ({ __skip: true });
const isSkipSwatch = (sw) => !!(sw && sw.__skip);
const AP_GET_URL = `${API_FOLDER}/v2/admin/applied-palettes/get.php`;
const AP_UPDATE_URL = `${API_FOLDER}/v2/admin/applied-palettes/update-entries.php`;
const AP_UPDATE_META_URL = `${API_FOLDER}/v2/admin/applied-palettes/update.php`;
const normalizeRoleKey = (role) => {
  const base = String(role || "").trim().toLowerCase();
  if (base.length > 3 && base.endsWith("s") && !base.endsWith("ss")) {
    return base.slice(0, -1);
  }
  return base;
};
const blankShadow = () => ({ l_offset: 0, tint_hex: null, tint_opacity: 0 });
const blankOverlay = () => {
  const base = OVERLAY_TIERS.reduce((acc, tier) => {
    acc[tier] = { mode: null, opacity: null };
    return acc;
  }, {});
  base._shadow = blankShadow();
  return base;
};
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
const mergeTesterColorLists = (a = [], b = []) => {
  const out = [];
  const seen = new Set();
  [...a, ...b].forEach((row) => {
    const key = Number(row?.color_id);
    if (!key || seen.has(key)) return;
    seen.add(key);
    out.push(row);
  });
  return out;
};
const sortColorsByName = (list = []) =>
  [...list].sort((a, b) => {
    const nameA = String(a?.name || a?.color_name || a?.code || a?.color_code || "").toLowerCase();
    const nameB = String(b?.name || b?.color_name || b?.code || b?.color_code || "").toLowerCase();
    if (nameA && nameB) {
      const primary = nameA.localeCompare(nameB);
      if (primary !== 0) return primary;
    }
    const codeA = String(a?.code || a?.color_code || a?.color_id || "").toLowerCase();
    const codeB = String(b?.code || b?.color_code || b?.color_id || "").toLowerCase();
    return codeA.localeCompare(codeB);
  });

/* ---------- URL query helper ---------- */
function useQuery() {
  const { search } = useLocation();
  return useMemo(() => new URLSearchParams(search), [search]);
}

/* ---------- Page ---------- */
export default function AdminMaskTesterPage({
  baseRoute = "/admin/mask-tester",
  forcedAssetId = "",
  forcedTesterLabel = "",
  forcedTesterColors = null,
  forcedTesterColorsByMask = null,
  forcedTesterColorsAny = null,
  schemeMode = false,
  schemeOptions = null,
  schemeSelection = "",
  onSchemeChange = null,
  defaultTesterView = "",
  schemeMappingLink = "",
  schemeColorIds = null,
  allSchemeColorsByMask = null,
  allSchemeColorsAny = null,
  hideTesterSourceControls = false,
  hideFinder = false,
  roleAliasMap = null,
  titleOverride = "",
  defaultBlendMode = "colorize",
  defaultBlendOpacity = 0.5,
} = {}) {
  const query = useQuery();
  const navigate = useNavigate();
  const appliedPaletteIdParam = query.get("ap") || query.get("applied") || "";
  const assetParam = query.get("asset") || "";
  const initialMaskParam = query.get("mask") || "";
  const initialViewParam = query.get("view") || "";
  const initialTesterView = initialViewParam === "all"
    ? "all"
    : initialViewParam === "mask"
      ? "mask"
      : (defaultTesterView || "mask");

  // search panel state
  const [findOpen, setFindOpen] = useState(
    hideFinder ? false : !(appliedPaletteIdParam || assetParam || forcedAssetId)
  );
  const initialSearchPage = Number(query.get("page") || 1);
  const initialSearchQ = query.get("q") || "";
  const initialSearchTags = query.get("tags") || "";

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
  const [appliedTierByMask, setAppliedTierByMask] = useState({});
  const [selectedMask, setSelectedMask] = useState(initialMaskParam);
  const [saveModalOpen, setSaveModalOpen] = useState(false);
  const [saveTitle, setSaveTitle] = useState("");
  const [saveDisplayTitle, setSaveDisplayTitle] = useState("");
  const [saveNotes, setSaveNotes] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saveBusy, setSaveBusy] = useState(false);
  const [saveNotice, setSaveNotice] = useState("");
  const [saveResult, setSaveResult] = useState(null);
  const [saveSuccessAt, setSaveSuccessAt] = useState(0);
  const [saveRenderChoice, setSaveRenderChoice] = useState("generate");
  const [saveMode, setSaveMode] = useState("new"); // new | update
  const [existingPalettes, setExistingPalettes] = useState([]);
  const [existingPaletteId, setExistingPaletteId] = useState("");
  const [existingLoadError, setExistingLoadError] = useState("");
  const [existingLoading, setExistingLoading] = useState(false);
  const [loadPreviousId, setLoadPreviousId] = useState("");
  const [loadPreviousError, setLoadPreviousError] = useState("");
  const [loadPreviousBusy, setLoadPreviousBusy] = useState(false);
  const [bulkSaveState, setBulkSaveState] = useState({ saving: false, message: "", error: "" });
  const [lastSavedSig, setLastSavedSig] = useState("");
  const [apPalette, setApPalette] = useState(null);
  const [apEntriesMap, setApEntriesMap] = useState({});
  const [apEntriesBaseline, setApEntriesBaseline] = useState({});
  const [apAppliedSettingsByMask, setApAppliedSettingsByMask] = useState({});
  const [apDraftSettingsByMask, setApDraftSettingsByMask] = useState({});
  const [apMaskBlendSettingsByMask, setApMaskBlendSettingsByMask] = useState({});
  const apMaskBlendPendingRef = useRef({});
  const apGlobalSigRef = useRef("");
  const [apLoadError, setApLoadError] = useState("");
  const [apMetaTitle, setApMetaTitle] = useState("");
  const [apMetaDisplayTitle, setApMetaDisplayTitle] = useState("");
  const [apMetaNotes, setApMetaNotes] = useState("");
  const [apMetaSaving, setApMetaSaving] = useState(false);
  const [apMetaError, setApMetaError] = useState("");
  const [apMetaNotice, setApMetaNotice] = useState("");
  const [testerColors, setTesterColors] = useState([]);
  const [testerColorsByMask, setTesterColorsByMask] = useState({});
  const [testerColorsAny, setTesterColorsAny] = useState([]);
  const [testerSourceType, setTesterSourceType] = useState("standard");
  const [testerSourceHoaId, setTesterSourceHoaId] = useState(null);
  const [testerSourceLabel, setTesterSourceLabel] = useState("Standard Test");
  const [testerSourceModalOpen, setTesterSourceModalOpen] = useState(false);
  const [testerSourceSelection, setTesterSourceSelection] = useState("standard");
  const [testerSourceBusy, setTesterSourceBusy] = useState(false);
  const [testerSourceError, setTesterSourceError] = useState("");
  const [hoaOptions, setHoaOptions] = useState([]);
  const [hoaLoading, setHoaLoading] = useState(false);
  const [hoaError, setHoaError] = useState("");
  const [seedWarning, setSeedWarning] = useState("");
  const [seeding, setSeeding] = useState(false);
  const [applyingAll, setApplyingAll] = useState(false);
  const [selectorVersion, setSelectorVersion] = useState(0);
  const [seedError, setSeedError] = useState("");
  const [testerView, setTesterView] = useState(initialTesterView); // mask | all
  const [allMaskColorId, setAllMaskColorId] = useState("");
  const [allMaskCustomColor, setAllMaskCustomColor] = useState(null);
  const [allMaskFilterColorId, setAllMaskFilterColorId] = useState("");
  const [maskAddColorId, setMaskAddColorId] = useState("");
  const [maskAddCustomColor, setMaskAddCustomColor] = useState(null);
  const [schemeAddNotice, setSchemeAddNotice] = useState("");
  const [schemeAddError, setSchemeAddError] = useState("");
  const [schemeAddBusy, setSchemeAddBusy] = useState(false);
  const [activeColorByMask, setActiveColorByMask] = useState({});
  const [schemeClearing, setSchemeClearing] = useState(false);
  const [schemeSeeding, setSchemeSeeding] = useState(false);
  const [schemeSeedNotice, setSchemeSeedNotice] = useState("");
  const [mappingOpen, setMappingOpen] = useState(false);
  const apAutoSaveRef = useRef({ timer: null, lastSig: "" });
  const apInitRef = useRef({ id: null });
  const testerWarningRef = useRef("");
  const schemeSeedRef = useRef({ key: "", masks: {} });
  const schemeRowsRef = useRef({});
  const approvedRowsCacheRef = useRef({ key: "", rows: [] });
  const saveSuccessTimerRef = useRef(null);
  const apDraftRef = useRef({ timer: null });
  const apSyncRef = useRef({ timer: null, inFlight: false, lastSig: "" });
  const apDraftRestoredRef = useRef(false);
  const testerDraftRef = useRef({ timer: null });
  const testerDraftRestoredRef = useRef(false);

  const assetId = (apPalette?.palette?.asset_id) || forcedAssetId || assetParam || "";
  const apDraftKey = useMemo(() => {
    if (!assetId) return "";
    const paletteId = apPalette?.palette?.id ? String(apPalette.palette.id) : "new";
    return `ap-mask-draft:${assetId}:${paletteId}`;
  }, [assetId, apPalette?.palette?.id]);
  const apSyncKey = useMemo(() => {
    if (!apPalette?.palette?.id) return "";
    return `ap-mask-sync:${apPalette.palette.id}`;
  }, [apPalette?.palette?.id]);
  const testerDraftKey = useMemo(() => {
    if (!assetId) return "";
    const schemeKey = schemeSelection ? String(schemeSelection) : "none";
    return `mask-tester-draft:${assetId}:${schemeKey}`;
  }, [assetId, schemeSelection]);

  function readLocalJson(key) {
    if (!key || typeof window === "undefined") return null;
    try {
      const raw = window.localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function writeLocalJson(key, value) {
    if (!key || typeof window === "undefined") return;
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
      // ignore storage errors
    }
  }

  function clearLocalKey(key) {
    if (!key || typeof window === "undefined") return;
    try {
      window.localStorage.removeItem(key);
    } catch {
      // ignore storage errors
    }
  }

  useEffect(() => {
    if (!assetId) {
      setExistingPalettes([]);
      setLoadPreviousId("");
      setLoadPreviousError("");
      return;
    }
    loadExistingPalettes(assetId);
  }, [assetId]);

  useEffect(() => {
    if (!saveSuccessAt) return undefined;
    if (saveSuccessTimerRef.current) {
      clearTimeout(saveSuccessTimerRef.current);
    }
    saveSuccessTimerRef.current = setTimeout(() => {
      setSaveSuccessAt(0);
    }, 4000);
    return () => {
      if (saveSuccessTimerRef.current) {
        clearTimeout(saveSuccessTimerRef.current);
      }
    };
  }, [saveSuccessAt]);

  const dirtyMasks = useMemo(
    () => Object.keys(overlayStatus || {}).filter((m) => overlayStatus[m]?.dirty),
    [overlayStatus]
  );
  const maskTesterColors = useMemo(
    () => getTesterColorsForMask(selectedMask),
    [selectedMask, testerColors, testerColorsByMask, testerColorsAny, testerSourceType]
  );
  const sortedTesterColors = useMemo(
    () => sortColorsByName(testerColors),
    [testerColors]
  );
  const sortedMaskTesterColors = useMemo(
    () => sortColorsByName(maskTesterColors),
    [maskTesterColors]
  );
  const schemeMasksSortedByColor = useMemo(() => {
    if (!schemeMode || !asset?.masks?.length) return asset?.masks || [];
    const masks = [...(asset.masks || [])];
    const colorNameForMask = (role) => {
      const override = maskOverrides?.[role];
      if (override && !isSkipSwatch(override)) {
        return String(override.name || override.color_name || override.code || override.color_code || "").toLowerCase();
      }
      const activeId = activeColorByMask?.[role];
      if (!activeId) return "";
      const pool = getTesterColorsForMask(role);
      const hit = pool.find((c) => Number(c.color_id) === Number(activeId));
      return String(hit?.name || hit?.color_name || hit?.code || hit?.color_code || "").toLowerCase();
    };
    masks.sort((a, b) => {
      const nameA = colorNameForMask(a.role);
      const nameB = colorNameForMask(b.role);
      if (nameA && nameB) {
        const primary = nameA.localeCompare(nameB);
        if (primary !== 0) return primary;
      } else if (nameA || nameB) {
        return nameA ? -1 : 1;
      }
      return String(a.role || "").localeCompare(String(b.role || ""));
    });
    return masks;
  }, [schemeMode, asset?.masks, maskOverrides, activeColorByMask, testerColorsByMask, testerColorsAny, testerSourceType]);

  useEffect(() => {
    return () => {
      if (apAutoSaveRef.current.timer) {
        clearTimeout(apAutoSaveRef.current.timer);
        apAutoSaveRef.current.timer = null;
      }
    };
  }, []);

  useEffect(() => {
    schemeRowsRef.current = {};
  }, [assetId]);

  useEffect(() => {
    approvedRowsCacheRef.current = { key: "", rows: [] };
  }, [assetId, selectorVersion]);

  useEffect(() => {
    if (!apPalette?.palette?.id || !asset?.masks?.length) return;
    if (apInitRef.current.id === apPalette.palette.id) return;
    const nextOverrides = {};
    Object.values(apEntriesMap || {}).forEach((entry) => {
      const normalized = normalizePick(entry?.color);
      if (normalized && entry?.mask_role) {
        nextOverrides[entry.mask_role] = normalized;
      }
    });
    setMaskOverrides(nextOverrides);
    applyColors(nextOverrides);
    apInitRef.current.id = apPalette.palette.id;
  }, [apPalette?.palette?.id, apEntriesMap, asset?.masks]);

  useEffect(() => {
    if (testerSourceType !== "standard") return;
    if (testerColors.length) return;
    loadStandardTesterColors().catch(() => {});
  }, [testerSourceType, testerColors.length]);

  useEffect(() => {
    if (!testerSourceModalOpen || hoaOptions.length || hoaLoading) return;
    setHoaLoading(true);
    setHoaError("");
    fetch(HOA_LIST_URL, { credentials: "include" })
      .then((r) => r.json())
      .then((payload) => {
        if (payload?.ok && Array.isArray(payload.items)) {
          setHoaOptions(payload.items);
        } else {
          setHoaError(payload?.error || "Failed to load HOAs");
        }
      })
      .catch((err) => setHoaError(err?.message || "Failed to load HOAs"))
      .finally(() => setHoaLoading(false));
  }, [testerSourceModalOpen, hoaOptions.length, hoaLoading]);

  useEffect(() => {
    if (testerSourceType !== "hoa") {
      setSeedWarning("");
      testerWarningRef.current = "";
      return;
    }
    if (!asset?.masks?.length) return;
    const missingRoles = [];
    const missingColors = [];
    asset.masks.forEach((m) => {
      const role = m.role;
      if (!role) return;
      const aliasRole = roleAliasMap?.[role];
      const key = normalizeRoleKey(aliasRole || role);
      if (!Object.prototype.hasOwnProperty.call(testerColorsByMask, key)) {
        if (aliasRole) {
          missingColors.push(role);
        } else {
          missingRoles.push(role);
        }
      }
    });
    if (!missingRoles.length && !missingColors.length) {
      setSeedWarning("");
      testerWarningRef.current = "";
      return;
    }
    const parts = [];
    if (missingRoles.length) {
      parts.push(`Not mapped to existing role: ${missingRoles.join(", ")}`);
    }
    if (missingColors.length) {
      parts.push(`No scheme colors for: ${missingColors.join(", ")}`);
    }
    const message = parts.join(" · ");
    setSeedWarning(message);
    testerWarningRef.current = message;
  }, [testerSourceType, testerColorsByMask, testerColorsAny, asset?.masks, roleAliasMap]);

  function resolveAddColor(colorInput) {
    if (!selectedMask) return null;
    if (colorInput === "custom") {
      return normalizePick(maskAddCustomColor);
    }
    const pool = getTesterColorsForMask(selectedMask);
    const tc = pool.find((t) => Number(t.color_id) === Number(colorInput));
    return normalizePick(tc);
  }

  function formatHoaLabel(hoa) {
    if (!hoa) return "";
    const name = hoa.name || "";
    const city = hoa.city || "";
    const state = hoa.state || "";
    let label = name;
    if (city) label = label ? `${label}, ${city}` : city;
    if (state) label = label ? `${label} ${state}` : state;
    return label;
  }

  function resetTesterSelections() {
    setMaskAddColorId("");
    setMaskAddCustomColor(null);
    setAllMaskColorId("");
    setAllMaskCustomColor(null);
    setAllMaskFilterColorId("");
    setSchemeAddNotice("");
    setSchemeAddError("");
  }

  function colorAllowedForSchemeRole(allowedRoles, schemeRole) {
    if (!schemeRole) return false;
    const allowedRaw = String(allowedRoles || "").trim().toLowerCase();
    if (!allowedRaw || allowedRaw === "any") return true;
    const allowedTokens = splitRoleTokens(allowedRaw).map(normalizeRoleKey).filter(Boolean);
    if (!allowedTokens.length) return true;
    const schemeTokens = splitRoleTokens(schemeRole).map(normalizeRoleKey).filter(Boolean);
    return schemeTokens.some((token) => allowedTokens.includes(token));
  }

  function openTesterSourceModal() {
    if (hideTesterSourceControls) return;
    const selection = testerSourceType === "hoa" && testerSourceHoaId
      ? `hoa:${testerSourceHoaId}`
      : "standard";
    setTesterSourceSelection(selection);
    setTesterSourceError("");
    setTesterSourceModalOpen(true);
  }

  function closeTesterSourceModal() {
    if (testerSourceBusy) return;
    setTesterSourceModalOpen(false);
  }

  async function loadStandardTesterColors() {
    const payload = await fetch(TESTER_COLORS_URL, { credentials: "include" })
      .then((r) => r.json())
      .catch(() => null);
    if (!payload?.ok || !Array.isArray(payload.items)) {
      throw new Error(payload?.error || "Failed to load tester colors");
    }
    setTesterColors(payload.items);
    setTesterColorsByMask({});
    setTesterColorsAny([]);
    return payload.items;
  }

  async function loadHoaTesterColors(hoaId) {
    const res = await fetch(`${HOA_COLORS_URL}?hoa_id=${encodeURIComponent(hoaId)}`, {
      credentials: "include",
      cache: "no-store",
    })
      .then((r) => r.json())
      .catch(() => null);
    if (!res?.ok) {
      throw new Error(res?.error || "Failed to load HOA scheme colors");
    }
    const rawByRole = res?.colors_by_role || {};
    const byRole = {};
    Object.entries(rawByRole).forEach(([role, list]) => {
      const key = normalizeRoleKey(role);
      byRole[key] = Array.isArray(list) ? list : [];
    });
    const anyColors = Array.isArray(res?.any_colors) ? res.any_colors : [];
    let union = [...anyColors];
    Object.values(byRole).forEach((list) => {
      union = mergeTesterColorLists(union, list);
    });
    setTesterColors(union);
    setTesterColorsByMask(byRole);
    setTesterColorsAny(anyColors);
    return { hoa: res.hoa, byRole, anyColors, union };
  }

  useEffect(() => {
    if (!forcedTesterColors && !forcedTesterColorsByMask && !forcedTesterColorsAny) return;
    const byRole = forcedTesterColorsByMask || {};
    const anyColors = forcedTesterColorsAny || [];
    let union = Array.isArray(forcedTesterColors) ? forcedTesterColors : [...anyColors];
    if (!Array.isArray(forcedTesterColors)) {
      Object.values(byRole).forEach((list) => {
        union = mergeTesterColorLists(union, list);
      });
    }
    setTesterColors(union);
    setTesterColorsByMask(byRole);
    setTesterColorsAny(anyColors);
    setTesterSourceType("hoa");
    setTesterSourceLabel(forcedTesterLabel || "HOA Scheme");
  }, [forcedTesterColors, forcedTesterColorsAny, forcedTesterColorsByMask, forcedTesterLabel]);

  function getTesterColorsForMask(maskRole) {
    if (!maskRole) return [];
    if (testerSourceType !== "hoa") return testerColors;
    const aliasRole = roleAliasMap?.[maskRole];
    const key = normalizeRoleKey(aliasRole || maskRole);
    const roleList = testerColorsByMask[key] || [];
    return mergeTesterColorLists(roleList, testerColorsAny);
  }

  function getAllSchemeColorsForMask(maskRole) {
    if (!maskRole) return [];
    if (!allSchemeColorsByMask && !allSchemeColorsAny) return [];
    const aliasRole = roleAliasMap?.[maskRole];
    const key = normalizeRoleKey(aliasRole || maskRole);
    const roleList = allSchemeColorsByMask?.[key] || [];
    return mergeTesterColorLists(roleList, allSchemeColorsAny || []);
  }

  function getMappedTesterColorsForMask(maskRole) {
    if (!maskRole) return [];
    if (testerSourceType !== "hoa") return testerColors;
    const aliasRole = roleAliasMap?.[maskRole];
    const key = normalizeRoleKey(aliasRole || maskRole);
    return testerColorsByMask[key] || [];
  }

  function getTesterColorIdsForMask(maskRole) {
    if (!maskRole) return [];
    const list = schemeMode ? getMappedTesterColorsForMask(maskRole) : getTesterColorsForMask(maskRole);
    if (schemeMode && !list.length) return [];
    const ids = [];
    const seen = new Set();
    list.forEach((row) => {
      const id = Number(row?.color_id);
      if (!id || seen.has(id)) return;
      seen.add(id);
      ids.push(id);
    });
    return ids;
  }

  function getAllSchemeColorIdsForMask(maskRole) {
    if (!maskRole) return [];
    const list = getAllSchemeColorsForMask(maskRole);
    if (!list.length) return [];
    const ids = [];
    const seen = new Set();
    list.forEach((row) => {
      const id = Number(row?.color_id);
      if (!id || seen.has(id)) return;
      seen.add(id);
      ids.push(id);
    });
    return ids;
  }

  async function handleApplyTesterSource() {
    if (!selectedMask) return;
    setTesterSourceBusy(true);
    setTesterSourceError("");
    try {
      if (testerSourceSelection === "standard") {
        const items = await loadStandardTesterColors();
        setTesterSourceType("standard");
        setTesterSourceHoaId(null);
        setTesterSourceLabel("Standard Test");
        resetTesterSelections();
        await handleSeedTestColors(items);
        setTesterSourceModalOpen(false);
        return;
      }
      if (testerSourceSelection.startsWith("hoa:")) {
        const hoaId = Number(testerSourceSelection.slice(4));
        if (!hoaId) throw new Error("Select a valid HOA");
        const res = await loadHoaTesterColors(hoaId);
        setTesterSourceType("hoa");
        setTesterSourceHoaId(hoaId);
        setTesterSourceLabel(formatHoaLabel(res.hoa) || "HOA Scheme");
        resetTesterSelections();
        const maskColors = mergeTesterColorLists(res.byRole[normalizeRoleKey(selectedMask)] || [], res.anyColors);
        await handleSeedTestColors(maskColors);
        setTesterSourceModalOpen(false);
        return;
      }
      throw new Error("Select a tester source");
    } catch (err) {
      setTesterSourceError(err?.message || "Failed to load tester colors");
    } finally {
      setTesterSourceBusy(false);
    }
  }

  async function saveTestColor(maskRole, colorPayload) {
    if (!assetId || !maskRole || !colorPayload?.color_id) return;
    const entry = {
      asset_id: assetId,
      mask: maskRole,
      entry: {
        color_id: colorPayload.color_id,
        color_name: colorPayload.name || colorPayload.color_name || colorPayload.code || colorPayload.color_code || null,
        color_brand: colorPayload.brand || colorPayload.color_brand || null,
        color_code: colorPayload.code || colorPayload.color_code || null,
        color_hex: (colorPayload.hex6 || colorPayload.hex || "").replace("#", ""),
        blend_mode: colorPayload.blend_mode || "colorize",
        blend_opacity: colorPayload.blend_opacity ?? 0.5,
        shadow_l_offset: colorPayload.shadow_l_offset ?? 0,
        shadow_tint_hex: colorPayload.shadow_tint_hex || null,
        shadow_tint_opacity: colorPayload.shadow_tint_opacity ?? 0,
      },
    };
    if (colorPayload.approved !== undefined && colorPayload.approved !== null) {
      entry.entry.approved = colorPayload.approved;
    }
    const res = await fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(entry),
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || "Failed to save mask blend");
    }
    return data.setting || null;
  }

  async function handleAddColorToMask(colorInput) {
    if (!assetId || !selectedMask) return;
    const baseColor = resolveAddColor(colorInput);
    if (!baseColor?.color_id) return;

    // Guess settings for this mask/color
    let preset = { mode: defaultBlendMode, opacity: defaultBlendOpacity };
    let shadow = { l_offset: 0, tint_hex: null, tint_opacity: 0 };
    try {
      const guess = await fetch(GUESS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          mask_role: selectedMask,
          color_id: baseColor.color_id,
          asset_id: assetId,
          photo_id: asset?.photo_id,
          debug: 1,
        }),
      })
        .then((r) => r.json())
        .catch(() => null);
      const g = guess?.ok ? guess.guess : null;
      if (guess?.debug) {
        console.info("mask-guess debug", guess.debug);
      }
      if (g) {
        preset = { mode: g.blend_mode || defaultBlendMode, opacity: g.blend_opacity ?? defaultBlendOpacity };
        shadow = {
          l_offset: g.shadow_l_offset ?? 0,
          tint_hex: g.shadow_tint_hex || null,
          tint_opacity: g.shadow_tint_opacity ?? 0,
        };
      }
    } catch (err) {
      console.warn("Guess failed", err);
    }

    await saveTestColor(selectedMask, {
      ...baseColor,
      blend_mode: preset.mode,
      blend_opacity: preset.opacity,
      shadow_l_offset: shadow.l_offset,
      shadow_tint_hex: shadow.tint_hex,
      shadow_tint_opacity: shadow.tint_opacity,
      approved: schemeMode ? 1 : 0,
    });
    setActiveColorByMask((prev) => ({ ...prev, [selectedMask]: baseColor.color_id }));
    setSelectorVersion((v) => v + 1);
  }

  async function handleAddColorToScheme(colorInput) {
    if (!schemeMode || !schemeSelection || !selectedMask) return;
    const baseColor = resolveAddColor(colorInput);
    if (!baseColor?.color_id) return;
    const allowedRole = roleAliasMap?.[selectedMask] || selectedMask;
    setSchemeAddBusy(true);
    setSchemeAddError("");
    setSchemeAddNotice("");
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/hoa-schemes/colors/add.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          scheme_id: Number(schemeSelection),
          color_id: Number(baseColor.color_id),
          allowed_roles: allowedRole || "any",
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to add scheme color");
      }
      const roleKey = normalizeRoleKey(allowedRole || selectedMask);
      setTesterColorsByMask((prev) => {
        const next = { ...(prev || {}) };
        const existing = next[roleKey] || [];
        next[roleKey] = mergeTesterColorLists(existing, [baseColor]);
        return next;
      });
      setTesterColors((prev) => mergeTesterColorLists(prev || [], [baseColor]));
      setSchemeAddNotice("Added to scheme.");
      await handleAddColorToMask(colorInput);
    } catch (err) {
      setSchemeAddError(err?.message || "Failed to add scheme color");
    } finally {
      setSchemeAddBusy(false);
    }
  }

  async function handleOverrideSchemeColor(maskRole, color) {
    if (!schemeMode || !schemeSelection || !maskRole) return;
    const baseColor = normalizePick(color);
    if (!baseColor?.color_id) return;
    const allowedRole = roleAliasMap?.[maskRole] || maskRole;
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/hoa-schemes/colors/add.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          scheme_id: Number(schemeSelection),
          color_id: Number(baseColor.color_id),
          allowed_roles: allowedRole || "any",
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to add scheme color");
      }
      const roleKey = normalizeRoleKey(allowedRole || maskRole);
      setTesterColorsByMask((prev) => {
        const next = { ...(prev || {}) };
        const existing = next[roleKey] || [];
        next[roleKey] = mergeTesterColorLists(existing, [baseColor]);
        return next;
      });
      setTesterColors((prev) => mergeTesterColorLists(prev || [], [baseColor]));
    } catch (err) {
      setSchemeAddError(err?.message || "Failed to add scheme color");
    }
  }

  // picking photo
  function onPick(item, meta = {}) {
    const nav = new URLSearchParams();
    nav.set("asset", item.asset_id);
    if (meta.q) nav.set("q", meta.q);
    if (meta.tagsText) nav.set("tags", meta.tagsText);
    if (meta.page) nav.set("page", String(meta.page));
    navigate(`${baseRoute}?${nav.toString()}`);
    setFindOpen(false);
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
      setRoleGroups({ body: null, trim: null, accent: null });
      setAssignments({});
      setSelectedMask("");
      return;
    }
    setLoading(true);
    setError("");
    fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(assetId)}&_=${Date.now()}`, {
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) {
          setError(data.error);
          setAsset(null);
          return;
        }
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

  function buildAppliedSettingsMap(map, settingsByMask = {}) {
    const settings = {};
    Object.values(map || {}).forEach((row) => {
      if (!row?.mask_role) return;
      const colorId = row?.color?.id || row?.color_id || null;
      const setting = getMaskBlendSettingForColor(settingsByMask[row.mask_role], colorId);
      const merged = setting ? mergeMaskBlendSetting(row, setting) : row;
      settings[row.mask_role] = {
        blend_mode: merged.blend_mode ?? null,
        blend_opacity: merged.blend_opacity ?? null,
        shadow_l_offset: merged.shadow_l_offset ?? 0,
        shadow_tint_hex: merged.shadow_tint_hex || null,
        shadow_tint_opacity: merged.shadow_tint_opacity ?? 0,
          color_l: merged.color_l ?? null,
          color_h: merged.color_h ?? null,
          color_c: merged.color_c ?? null,
      };
    });
    return settings;
  }

  function applyGlobalBlendSettingsToEntries(map, settingsByMask, draftByMask) {
    const next = { ...(map || {}) };
    let changed = false;
    Object.values(next).forEach((row) => {
      if (!row?.mask_role) return;
      if (draftByMask?.[row.mask_role]) return;
      if (row._global_synced) return;
      const colorId = row.color?.id || row.color_id || null;
      if (!colorId) {
        next[row.mask_role] = { ...row, _global_synced: true };
        changed = true;
        return;
      }
      const setting = getMaskBlendSettingForColor(settingsByMask[row.mask_role], colorId);
      if (!setting) {
        next[row.mask_role] = { ...row, _global_synced: true };
        changed = true;
        return;
      }
      const merged = mergeMaskBlendSetting(row, setting);
      next[row.mask_role] = { ...merged, _global_synced: true };
      changed = true;
    });
    return changed ? next : map;
  }

  async function loadAppliedPalette(id, { silent = false } = {}) {
    if (!id) {
      setApPalette(null);
      setApEntriesMap({});
      setApEntriesBaseline({});
      setApAppliedSettingsByMask({});
      setApDraftSettingsByMask({});
      setApLoadError("");
      return null;
    }
    const idNum = Number(id);
    if (!Number.isFinite(idNum) || idNum <= 0) {
      setApLoadError("Invalid applied palette id");
      return null;
    }
    if (!silent) {
      setApLoadError("");
    }
    try {
      const res = await fetch(`${AP_GET_URL}?id=${idNum}&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!data?.ok) throw new Error(data?.error || "Failed to load applied palette");
      setApPalette(data);
      setApMetaTitle(data?.palette?.title || "");
      setApMetaDisplayTitle(data?.palette?.display_title || "");
      setApMetaNotes(data?.palette?.notes || "");
      const map = {};
      (data.entries || []).forEach((e) => {
        const hexRaw = e.color_hex || e.color_hex6 || e.hex6 || "";
        const hex6 = hexRaw.replace(/[^0-9a-f]/gi, "").slice(0, 6).toUpperCase();
        const lightnessRaw = e.color_hcl_l ?? e.color_lab_l ?? null;
        const lightness =
          lightnessRaw != null && Number.isFinite(Number(lightnessRaw))
            ? Number(lightnessRaw)
            : null;
        map[e.mask_role] = {
          mask_role: e.mask_role,
          color: {
            id: e.color_id,
            name: e.color_name || e.color_code || "",
            code: e.color_code || "",
            brand: e.color_brand || "",
            hex6,
            lightness,
            hcl_l: e.color_hcl_l ?? null,
            lab_l: e.color_lab_l ?? null,
          },
          blend_mode: e.setting_blend_mode ?? e.blend_mode ?? "",
          blend_opacity: e.setting_blend_opacity ?? e.blend_opacity ?? null,
          shadow_l_offset: e.setting_shadow_l_offset ?? e.lightness_offset ?? null,
          shadow_tint_hex: e.setting_shadow_tint_hex || e.tint_hex || "",
          shadow_tint_opacity: e.setting_shadow_tint_opacity ?? e.tint_opacity ?? null,
          color_l: lightness ?? null,
          color_h: e.setting_color_h ?? null,
          color_c: e.setting_color_c ?? null,
          _global_synced: false,
        };
      });
      setApEntriesMap(map);
      setApEntriesBaseline(cloneEntriesMap(map));
      setApAppliedSettingsByMask(buildAppliedSettingsMap(map, apMaskBlendSettingsByMask));
      setApDraftSettingsByMask({});
      return map;
    } catch (err) {
      if (!silent) {
        setApLoadError(err?.message || "Failed to load applied palette");
      }
      return null;
    }
  }

  // Load applied palette if query specifies one
  useEffect(() => {
    loadAppliedPalette(appliedPaletteIdParam);
  }, [appliedPaletteIdParam]);

  useEffect(() => {
    apDraftRestoredRef.current = false;
  }, [apDraftKey]);

  useEffect(() => {
    if (!apDraftKey || !assetId) return;
    if (apDraftRestoredRef.current) return;
    if (Object.keys(apEntriesMap || {}).length) return;
    const draft = readLocalJson(apDraftKey);
    const draftEntries = draft?.entries_map || {};
    if (!Object.keys(draftEntries).length) return;
    apDraftRestoredRef.current = true;
    setApEntriesMap(draftEntries);
    setApEntriesBaseline(cloneEntriesMap(draftEntries));
    setApAppliedSettingsByMask(buildAppliedSettingsMap(draftEntries, apMaskBlendSettingsByMask));
    setApDraftSettingsByMask(draft?.draft_settings_by_mask || {});
    setSaveNotice("Restored local draft.");
  }, [apDraftKey, assetId, apEntriesMap, apMaskBlendSettingsByMask]);

  useEffect(() => {
    testerDraftRestoredRef.current = false;
  }, [testerDraftKey]);

  useEffect(() => {
    if (!testerDraftKey || !asset?.asset_id || apPalette) return;
    if (testerDraftRestoredRef.current) return;
    const draft = readLocalJson(testerDraftKey);
    if (!draft) return;
    testerDraftRestoredRef.current = true;
    if (draft.role_groups) {
      setRoleGroups(draft.role_groups);
    }
    if (draft.mask_overrides) {
      setMaskOverrides(draft.mask_overrides);
    }
    if (draft.mask_overlays) {
      setMaskOverlays(draft.mask_overlays);
    }
    if (draft.mask_textures) {
      setMaskTextures(draft.mask_textures);
    }
    if (draft.active_color_by_mask) {
      setActiveColorByMask(draft.active_color_by_mask);
    }
    if (draft.selected_mask) {
      setSelectedMask(draft.selected_mask);
    }
    if (draft.mask_overrides || draft.role_groups) {
      const overrides = draft.mask_overrides || {};
      applyColors(overrides);
    }
    setSaveNotice("Restored local tester draft.");
  }, [testerDraftKey, asset?.asset_id, apPalette]);

  function findMaskBlendSetting(maskRole, colorId) {
    return getMaskBlendSettingForColor(apMaskBlendSettingsByMask[maskRole], colorId);
  }

  async function loadMaskBlendSettings(maskRole) {
    if (!apPalette?.palette?.asset_id || !maskRole) return [];
    if (apMaskBlendPendingRef.current[maskRole]) {
      return apMaskBlendPendingRef.current[maskRole];
    }
    const assetId = apPalette.palette.asset_id;
    const promise = fetch(
      `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(maskRole)}&${Date.now()}`,
      { credentials: "include", cache: "no-store" }
    )
      .then((r) => r.json())
      .then((data) => (data?.ok && Array.isArray(data.settings) ? data.settings : []))
      .catch(() => []);
    apMaskBlendPendingRef.current[maskRole] = promise;
    const settings = await promise;
    apMaskBlendPendingRef.current[maskRole] = null;
    setApMaskBlendSettingsByMask((prev) => ({
      ...(prev || {}),
      [maskRole]: settings,
    }));
    return settings;
  }

  useEffect(() => {
    if (!apPalette?.palette?.asset_id) return;
    const maskRoles = Object.values(apEntriesMap || {}).map((row) => row?.mask_role).filter(Boolean);
    if (!maskRoles.length) return;
    maskRoles.forEach((maskRole) => {
      if (apMaskBlendSettingsByMask[maskRole]) return;
      loadMaskBlendSettings(maskRole);
    });
  }, [apPalette?.palette?.asset_id, apEntriesMap, apMaskBlendSettingsByMask]);

  useEffect(() => {
    if (!apPalette?.palette?.asset_id) return;
    if (!Object.keys(apEntriesMap || {}).length) return;
    const synced = applyGlobalBlendSettingsToEntries(apEntriesMap, apMaskBlendSettingsByMask, apDraftSettingsByMask);
    if (synced !== apEntriesMap) {
      setApEntriesMap(synced);
    }
    setApAppliedSettingsByMask(buildAppliedSettingsMap(synced, apMaskBlendSettingsByMask));
  }, [apPalette?.palette?.asset_id, apEntriesMap, apMaskBlendSettingsByMask, apDraftSettingsByMask]);

  async function handleSaveApMeta() {
    if (!apPalette?.palette?.id || apMetaSaving) return;
    setApMetaSaving(true);
    setApMetaError("");
    setApMetaNotice("");
    try {
      const res = await fetch(AP_UPDATE_META_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          palette_id: apPalette.palette.id,
          title: apMetaTitle.trim(),
          display_title: apMetaDisplayTitle.trim(),
          notes: apMetaNotes.trim(),
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to update palette details");
      }
      setApMetaNotice("Updated palette details.");
      setApPalette((prev) => (
        prev
          ? {
              ...prev,
              palette: {
                ...prev.palette,
                title: apMetaTitle.trim(),
                display_title: apMetaDisplayTitle.trim(),
                notes: apMetaNotes.trim(),
              },
            }
          : prev
      ));
    } catch (err) {
      setApMetaError(err?.message || "Failed to update palette details");
    } finally {
      setApMetaSaving(false);
    }
  }

  const maskGridRows = useMemo(() => {
    const maskMap = {};
    (asset?.masks || []).forEach((m) => {
      maskMap[m.role] = { role: m.role, base_lightness: m.base_lightness, original_texture: m.original_texture };
    });
    Object.keys(apEntriesMap || {}).forEach((mask) => {
      if (!maskMap[mask]) maskMap[mask] = { role: mask };
    });
    return Object.values(maskMap).sort((a, b) => (a.role || "").localeCompare(b.role || ""));
  }, [asset?.masks, apEntriesMap]);

  function handleMaskGridChange(maskRole, updates) {
    // Update applied entries map for admin AP mode; otherwise keep overrides
    setApEntriesMap((prev) => {
      const current = prev[maskRole] || { mask_role: maskRole };
      let nextBlend = {};
      if (updates.color) {
        const normalized = normalizePick(updates.color);
        const hit = normalized ? findMaskBlendSetting(maskRole, normalized.color_id) : null;
        if (hit) {
          const merged = mergeMaskBlendSetting(current, hit);
          nextBlend = {
            blend_mode: merged.blend_mode ?? "",
            blend_opacity: merged.blend_opacity ?? null,
            shadow_l_offset: merged.shadow_l_offset ?? 0,
            shadow_tint_hex: merged.shadow_tint_hex || "",
            shadow_tint_opacity: merged.shadow_tint_opacity ?? 0,
            color_l: merged.color_l ?? null,
            color_h: merged.color_h ?? null,
            color_c: merged.color_c ?? null,
          };
        }
      }
      const next = {
        ...prev,
        [maskRole]: {
          ...current,
          ...updates,
          ...nextBlend,
          mask_role: maskRole,
          _global_synced:
            !!updates.color && Object.keys(nextBlend).length > 0 ||
            updates.blend_mode != null ||
            updates.blend_opacity != null ||
            updates.shadow_l_offset != null ||
            updates.shadow_tint_hex != null ||
            updates.shadow_tint_opacity != null ||
            updates.color_l != null ||
            updates.color_h != null ||
            updates.color_c != null,
        },
      };
      return next;
    });
    if (updates.blend_mode != null || updates.blend_opacity != null || updates.shadow_l_offset != null || updates.shadow_tint_hex != null || updates.shadow_tint_opacity != null || updates.color_l != null || updates.color_h != null || updates.color_c != null) {
      setApDraftSettingsByMask((prev) => {
        const current = prev?.[maskRole] || {};
        return {
          ...(prev || {}),
          [maskRole]: {
            ...current,
            ...(updates.blend_mode != null ? { blend_mode: updates.blend_mode } : {}),
            ...(updates.blend_opacity != null ? { blend_opacity: updates.blend_opacity } : {}),
            ...(updates.shadow_l_offset != null ? { shadow_l_offset: updates.shadow_l_offset } : {}),
            ...(updates.shadow_tint_hex != null ? { shadow_tint_hex: updates.shadow_tint_hex || null } : {}),
            ...(updates.shadow_tint_opacity != null ? { shadow_tint_opacity: updates.shadow_tint_opacity } : {}),
            ...(updates.color_l != null ? { color_l: updates.color_l } : {}),
            ...(updates.color_h != null ? { color_h: updates.color_h } : {}),
            ...(updates.color_c != null ? { color_c: updates.color_c } : {}),
          },
        };
      });
    }
    if (updates.color) {
      const normalized = normalizePick(updates.color);
      loadMaskBlendSettings(maskRole).then((settings) => {
        const hit = getMaskBlendSettingForColor(settings, normalized?.color_id);
        if (!hit) return;
        setApEntriesMap((prev) => {
          const current = prev[maskRole] || { mask_role: maskRole };
          return {
            ...prev,
            [maskRole]: { ...mergeMaskBlendSetting(current, hit), _global_synced: true },
          };
        });
      });
    }
  }

  async function handleMaskGridApply(rowOrMask) {
    const maskRole = typeof rowOrMask === "string" ? rowOrMask : rowOrMask?.mask_role;
    if (!maskRole) return;
    const entry = apEntriesMap[maskRole];
    if (!entry) return;
    const rowSettings = {
      blend_mode: entry.blend_mode ?? null,
      blend_opacity: entry.blend_opacity ?? null,
      shadow_l_offset: entry.shadow_l_offset ?? 0,
      shadow_tint_hex: entry.shadow_tint_hex || null,
      shadow_tint_opacity: entry.shadow_tint_opacity ?? 0,
    };
    setApAppliedSettingsByMask((prev) => ({
      ...(prev || {}),
      [maskRole]: {
        ...(prev?.[maskRole] || {}),
        ...rowSettings,
      },
    }));
    const baseColor = entry.color || (entry.color_id
      ? {
          id: entry.color_id,
          color_id: entry.color_id,
          name: entry.color_name || entry.color_code || "",
          code: entry.color_code || "",
          brand: entry.color_brand || "",
          hex6: entry.color_hex || "",
          lightness: entry.color_l ?? null,
          hcl_l: entry.hcl_l ?? null,
          lab_l: entry.lab_l ?? null,
        }
      : null);
    if (!baseColor) return;
    const normalized = normalizePick(baseColor);
    if (!normalized) return;
    const { overrides, active } = buildOverridesFromEntries(apEntriesMap);
    overrides[maskRole] = normalized;
    setMaskOverrides(overrides);
    setActiveColorByMask(active);
    setApDraftSettingsByMask((prev) => {
      if (!prev?.[maskRole]) return prev || {};
      const next = { ...(prev || {}) };
      delete next[maskRole];
      return next;
    });
    await applyColors(overrides);
  }

  async function handleApplyApBlend(maskRole, tier, preset, options = {}) {
    if (!maskRole || !preset) return;
    const { swatch = null, shadow = null, clearColor = false } = options;
    const normalizedSwatch = !clearColor && swatch ? normalizePick(swatch) : null;

    setApEntriesMap((prev) => {
      const current = prev[maskRole] || { mask_role: maskRole };
      const next = { ...current, mask_role: maskRole };

      if (clearColor) {
        next.color = null;
        next.color_id = null;
        next.color_hex = "";
        next.color_name = "";
        next.color_code = "";
        next.color_brand = "";
      } else if (normalizedSwatch) {
        next.color = {
          id: normalizedSwatch.color_id ?? normalizedSwatch.id,
          color_id: normalizedSwatch.color_id ?? normalizedSwatch.id,
          name: normalizedSwatch.name || normalizedSwatch.code || "",
          code: normalizedSwatch.code || normalizedSwatch.color_code || "",
          brand: normalizedSwatch.brand || normalizedSwatch.color_brand || "",
          hex6: normalizedSwatch.hex6 || normalizedSwatch.hex || "",
          lightness:
            typeof normalizedSwatch.lightness === "number"
              ? normalizedSwatch.lightness
              : typeof normalizedSwatch.hcl_l === "number"
                ? normalizedSwatch.hcl_l
                : typeof normalizedSwatch.lab_l === "number"
                  ? normalizedSwatch.lab_l
                  : null,
          hcl_l: normalizedSwatch.hcl_l ?? null,
          hcl_h: normalizedSwatch.hcl_h ?? normalizedSwatch.h ?? null,
          hcl_c: normalizedSwatch.hcl_c ?? normalizedSwatch.c ?? null,
          lab_l: normalizedSwatch.lab_l ?? null,
        };
        next.color_id = normalizedSwatch.color_id ?? normalizedSwatch.id ?? null;
        next.color_hex = normalizedSwatch.hex6 || normalizedSwatch.hex || "";
        next.color_name = normalizedSwatch.name || normalizedSwatch.code || "";
        next.color_code = normalizedSwatch.code || normalizedSwatch.color_code || "";
        next.color_brand = normalizedSwatch.brand || normalizedSwatch.color_brand || "";
      }

      if (preset.mode != null) {
        next.blend_mode = preset.mode;
      }
      if (typeof preset.opacity === "number") {
        next.blend_opacity = preset.opacity;
      }

      if (shadow && typeof shadow === "object") {
        const normalizedShadow = normalizeShadowStruct({
          l_offset: shadow.l_offset,
          tint_hex: shadow.tint_hex,
          tint_opacity: shadow.tint_opacity,
        });
        next.shadow_l_offset = normalizedShadow.l_offset;
        next.shadow_tint_hex = normalizedShadow.tint_hex ? normalizedShadow.tint_hex.replace("#", "") : "";
        next.shadow_tint_opacity = normalizedShadow.tint_opacity;
      }

      const nextMap = { ...prev, [maskRole]: next };
      scheduleAppliedPaletteAutoSave(nextMap);
      return nextMap;
    });

    const nextOverrides = { ...(maskOverrides || {}) };
    if (clearColor) {
      nextOverrides[maskRole] = makeSkipSwatch();
    } else if (normalizedSwatch) {
      nextOverrides[maskRole] = normalizedSwatch;
      setActiveColorByMask((prev) => ({ ...prev, [maskRole]: normalizedSwatch.color_id }));
    }
    setMaskOverrides(nextOverrides);
    setApAppliedSettingsByMask((prev) => ({
      ...(prev || {}),
      [maskRole]: {
        blend_mode: preset.mode ?? null,
        blend_opacity: typeof preset.opacity === "number" ? preset.opacity : null,
        shadow_l_offset: shadow?.l_offset ?? 0,
        shadow_tint_hex: shadow?.tint_hex || null,
        shadow_tint_opacity: shadow?.tint_opacity ?? 0,
      },
    }));
    await applyColors(nextOverrides);
  }

  const apEntriesForSave = useMemo(
    () => Object.values(apEntriesMap || {}).map((row) => normalizeEntryForSave(row)).filter(Boolean),
    [apEntriesMap]
  );

  const apBaselineSignature = useMemo(() => buildApSignature(apEntriesBaseline), [apEntriesBaseline]);
  const apCurrentSignature = useMemo(() => buildApSignature(apEntriesMap), [apEntriesMap]);
  const apHasChanges = apBaselineSignature !== apCurrentSignature;

  const seedDefaultBlendMode = testerSourceType === "hoa" ? "multiply" : defaultBlendMode;
  function resolveSeedBlendMode(guessMode, colorMode) {
    const normalizedGuess = String(guessMode || "").trim().toLowerCase();
    const normalizedColor = String(colorMode || "").trim().toLowerCase();
    if (testerSourceType === "hoa") {
      if (normalizedGuess && normalizedGuess !== "colorize") return normalizedGuess;
      if (normalizedColor && normalizedColor !== "colorize") return normalizedColor;
      return seedDefaultBlendMode;
    }
    return normalizedGuess || normalizedColor || seedDefaultBlendMode;
  }

  const apOverlayOverrides = useMemo(() => {
    const overrides = {};
    Object.keys(apAppliedSettingsByMask || {}).forEach((maskRole) => {
      const setting = apAppliedSettingsByMask[maskRole];
      if (!setting) return;
      const mode = setting.blend_mode || "colorize";
      const opacity = typeof setting.blend_opacity === "number" ? setting.blend_opacity : 0;
      overrides[maskRole] = {
        dark: { mode, opacity },
        medium: { mode, opacity },
        light: { mode, opacity },
        _shadow: {
          l_offset: setting.shadow_l_offset ?? 0,
          tint_hex: setting.shadow_tint_hex || null,
          tint_opacity: setting.shadow_tint_opacity ?? 0,
        },
      };
    });
    return overrides;
  }, [apAppliedSettingsByMask]);

  const renderOverlayOverrides = useMemo(() => {
    if (apPalette) return apOverlayOverrides;
    if (!asset?.masks?.length) return null;
    const overrides = {};
    (asset.masks || []).forEach((mask) => {
      if (!mask?.role) return;
      overrides[mask.role] = normalizeOverlayPayload(maskOverlays[mask.role]);
    });
    return overrides;
  }, [apPalette, apOverlayOverrides, asset?.masks, maskOverlays]);

  async function updateAppliedPaletteEntries(entries, { rerender = false, silent = false } = {}) {
    if (!apPalette?.palette?.id) return;
    if (!entries.length) {
      if (!silent) setSaveError("Apply at least one mask color to save.");
      return;
    }
    setSaveBusy(true);
    if (!silent) {
      setSaveError("");
      setSaveNotice("");
    }
    try {
      const res = await fetch(AP_UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          palette_id: apPalette.palette.id,
          entries,
          render: rerender ? { cache: true } : undefined,
          clear_render: rerender,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to update applied palette");
      }
      if (!silent) setSaveNotice(rerender ? "Updated & rerendered." : "Updated.");
      if (!silent) {
        await loadAppliedPalette(apPalette.palette.id, { silent: true });
      }
    } catch (err) {
      setSaveError(err?.message || "Failed to update applied palette");
    } finally {
      setSaveBusy(false);
    }
  }

  async function flushAppliedPaletteSync({ force = false } = {}) {
    if (!apSyncKey || !apPalette?.palette?.id) return;
    if (apSyncRef.current.inFlight) return;
    const pending = readLocalJson(apSyncKey);
    if (!pending || !Array.isArray(pending.entries) || !pending.entries.length) return;
    const sig = pending.signature || JSON.stringify(pending.entries);
    if (!force && sig === apSyncRef.current.lastSig) return;
    apSyncRef.current.inFlight = true;
    try {
      const res = await fetch(AP_UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          palette_id: apPalette.palette.id,
          entries: pending.entries,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to sync applied palette");
      }
      apSyncRef.current.lastSig = sig;
      clearLocalKey(apSyncKey);
    } catch (err) {
      // keep pending for retry
    } finally {
      apSyncRef.current.inFlight = false;
    }
  }

  function scheduleAppliedPaletteAutoSave(nextEntriesMap) {
    if (!assetId) return;
    const entries = Object.values(nextEntriesMap || {})
      .map((row) => normalizeEntryForSave(row))
      .filter(Boolean);
    entries.sort((a, b) => (a.mask_role || "").localeCompare(b.mask_role || ""));
    if (!entries.length) return;
    if (apDraftRef.current.timer) {
      clearTimeout(apDraftRef.current.timer);
    }
    apDraftRef.current.timer = setTimeout(() => {
      apDraftRef.current.timer = null;
      writeLocalJson(apDraftKey, {
        asset_id: assetId,
        palette_id: apPalette?.palette?.id ?? null,
        entries_map: nextEntriesMap || {},
        draft_settings_by_mask: apDraftSettingsByMask || {},
        updated_at: Date.now(),
      });
    }, 200);

    if (!apPalette?.palette?.id) return;
    const sig = JSON.stringify(entries);
    if (sig === apSyncRef.current.lastSig) return;
    if (apSyncRef.current.timer) {
      clearTimeout(apSyncRef.current.timer);
    }
    apSyncRef.current.timer = setTimeout(() => {
      apSyncRef.current.timer = null;
      writeLocalJson(apSyncKey, {
        palette_id: apPalette.palette.id,
        entries,
        signature: sig,
        updated_at: Date.now(),
      });
      flushAppliedPaletteSync({ force: true });
    }, 600);
  }

  useEffect(() => {
    if (!apSyncKey || !apPalette?.palette?.id) return;
    function handleOnline() {
      flushAppliedPaletteSync({ force: true });
    }
    window.addEventListener("online", handleOnline);
    const interval = window.setInterval(() => {
      flushAppliedPaletteSync();
    }, 8000);
    flushAppliedPaletteSync({ force: true });
    return () => {
      window.removeEventListener("online", handleOnline);
      window.clearInterval(interval);
    };
  }, [apSyncKey, apPalette?.palette?.id]);

  useEffect(() => {
    if (!Object.keys(apEntriesMap || {}).length) return;
    scheduleAppliedPaletteAutoSave(apEntriesMap);
  }, [apEntriesMap]);

  useEffect(() => {
    if (!testerDraftKey || !asset?.asset_id || apPalette) return;
    if (testerDraftRef.current.timer) {
      clearTimeout(testerDraftRef.current.timer);
    }
    testerDraftRef.current.timer = setTimeout(() => {
      testerDraftRef.current.timer = null;
      writeLocalJson(testerDraftKey, {
        asset_id: asset.asset_id,
        scheme_id: schemeSelection || null,
        role_groups: roleGroups,
        mask_overrides: maskOverrides,
        mask_overlays: maskOverlays,
        mask_textures: maskTextures,
        active_color_by_mask: activeColorByMask,
        selected_mask: selectedMask,
        updated_at: Date.now(),
      });
    }, 200);
  }, [
    testerDraftKey,
    asset?.asset_id,
    apPalette,
    roleGroups,
    maskOverrides,
    maskOverlays,
    maskTextures,
    activeColorByMask,
    selectedMask,
    schemeSelection,
  ]);

  async function handleUpdateAppliedPalette({ rerender = false } = {}) {
    if (!apEntriesForSave.length) {
      setSaveError("Apply at least one mask color to save.");
      return;
    }
    await updateAppliedPaletteEntries(apEntriesForSave, { rerender, silent: false });
  }

  async function handleRevertAppliedPalette() {
    if (!apPalette?.palette?.id) return;
    const latest = await loadAppliedPalette(apPalette.palette.id, { silent: true });
    const baseline = latest ? cloneEntriesMap(latest) : cloneEntriesMap(apEntriesBaseline);
    if (!Object.keys(baseline || {}).length) return;
    setApEntriesMap(baseline);
    setApAppliedSettingsByMask(buildAppliedSettingsMap(baseline));
    const { overrides, active } = buildOverridesFromEntries(baseline);
    setMaskOverrides(overrides);
    setActiveColorByMask(active);
    await applyColors(overrides);
    setSaveNotice("Reverted to saved palette.");
    setSaveError("");
  }
  // Seed starter tester colors for the currently selected mask
  async function handleSeedTestColors(colorsOverride) {
    const colorsToSeed = Array.isArray(colorsOverride)
      ? colorsOverride
      : getTesterColorsForMask(selectedMask);
    if (!selectedMask || !colorsToSeed.length || !assetId) return;
    setSeeding(true);
    setSeedError("");
    try {
      // Load existing rows to avoid overriding current settings/approval
      const existing = await fetch(`${TESTER_COLORS_URL.replace("tester-colors.php","../admin/mask-blend/list.php")}?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(selectedMask)}`, {
        credentials: "include",
        cache: "no-store",
      }).then((r) => r.json()).catch(() => null);
      const existingIds = new Set(
        (existing?.settings || [])
          .filter((row) => row.color_id)
          .map((row) => Number(row.color_id))
      );
      await Promise.all(
        colorsToSeed.map(async (tc) => {
          const color = normalizePick(tc);
          if (!color?.color_id) return;
          if (existingIds.has(Number(color.color_id))) return; // skip if already present
          const guess = await fetch(GUESS_URL, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              mask_role: selectedMask,
              color_id: color.color_id,
              asset_id: assetId,
              photo_id: asset?.photo_id,
              debug: 1,
            }),
          })
            .then((r) => r.json())
            .catch(() => null);
          const g = guess?.ok ? guess.guess : null;
          if (guess?.debug) {
            console.info("mask-guess debug", guess.debug);
          }
          const resolvedBlendMode = resolveSeedBlendMode(g?.blend_mode, color.blend_mode);
          await saveTestColor(selectedMask, {
            ...color,
            blend_mode: resolvedBlendMode,
            blend_opacity: g?.blend_opacity ?? color.blend_opacity ?? defaultBlendOpacity,
            shadow_l_offset: g?.shadow_l_offset ?? 0,
            shadow_tint_hex: g?.shadow_tint_hex || null,
            shadow_tint_opacity: g?.shadow_tint_opacity ?? 0,
            approved: schemeMode ? 1 : 0,
          });
        })
      );
      // Refresh by bumping selectorVersion so MaskBlendHistory reloads
      setSelectorVersion((v) => v + 1);
    } catch (err) {
      console.error(err);
      setSeedError(err?.message || "Failed to seed test colors");
    } finally {
      setSeeding(false);
    }
  }

  async function seedSchemeColorsForMask(maskRole, { force = false } = {}) {
    if (!maskRole || !assetId) return;
    const mappedColors = getMappedTesterColorsForMask(maskRole);
    const colorsToSeed = mappedColors.length
      ? mappedColors
      : getTesterColorsForMask(maskRole);
    if (!colorsToSeed.length) return;
    try {
      const listUrl = TESTER_COLORS_URL.replace("tester-colors.php", "../admin/mask-blend/list.php");
      const existing = await fetch(`${listUrl}?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(maskRole)}`, {
        credentials: "include",
        cache: "no-store",
      }).then((r) => r.json()).catch(() => null);
      const existingRows = Array.isArray(existing?.settings) ? existing.settings : [];
      const existingIds = new Set(
        existingRows
          .filter((row) => row.color_id)
          .map((row) => Number(row.color_id))
      );
      let approvedRows = existingRows.filter((row) => Number(row?.approved) === 1);
      const photoRule = (() => {
        if (!approvedRows.length) return null;
        const byMode = new Map();
        approvedRows.forEach((row) => {
          const mode = String(row?.blend_mode || "").toLowerCase();
          if (!mode) return;
          const lightness = row?.color_l ?? row?.base_lightness ?? null;
          if (lightness == null) return;
          const bucket = byMode.get(mode) || { count: 0, sumL: 0, sumOpacity: 0 };
          bucket.count += 1;
          bucket.sumL += Number(lightness);
          bucket.sumOpacity += Number(row?.blend_opacity ?? 0);
          byMode.set(mode, bucket);
        });
        if (!byMode.size) return null;
        const modes = Array.from(byMode.entries()).map(([mode, data]) => ({
          mode,
          avgL: data.sumL / data.count,
          avgOpacity: data.sumOpacity / data.count,
          count: data.count,
        }));
        modes.sort((a, b) => b.count - a.count);
        if (modes.length === 1) {
          return {
            primaryMode: modes[0].mode,
            primaryOpacity: modes[0].avgOpacity || defaultBlendOpacity,
          };
        }
        const bright = modes.reduce((a, b) => (a.avgL > b.avgL ? a : b));
        const dark = modes.reduce((a, b) => (a.avgL < b.avgL ? a : b));
        const threshold = (bright.avgL + dark.avgL) / 2;
        return {
          brightMode: bright.mode,
          brightOpacity: bright.avgOpacity || defaultBlendOpacity,
          darkMode: dark.mode,
          darkOpacity: dark.avgOpacity || defaultBlendOpacity,
          threshold,
        };
      })();
      let added = 0;
      for (const tc of colorsToSeed) {
        const color = normalizePick(tc);
        if (!color?.color_id) continue;
        if (existingIds.has(Number(color.color_id))) continue;
        const guess = await fetch(GUESS_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            mask_role: maskRole,
            color_id: color.color_id,
            asset_id: assetId,
            photo_id: asset?.photo_id,
            debug: 1,
          }),
        })
          .then((r) => r.json())
          .catch(() => null);
        const g = guess?.ok ? guess.guess : null;
        if (guess?.debug) {
          console.info("mask-guess debug", guess.debug);
        }
        const lightness = color.lightness ?? color.hcl_l ?? color.lab_l ?? null;
        const useFallback = (!g || g.neighbors_used === 0 || !g.blend_mode) && !!photoRule;
        let fallbackMode = lightness != null && lightness >= 65 ? "hardlight" : "multiply";
        let fallbackOpacity = lightness != null && lightness >= 65 ? 0.5 : 1;
        if (useFallback) {
          if (photoRule.threshold != null && lightness != null) {
            if (lightness >= photoRule.threshold) {
              fallbackMode = photoRule.brightMode || fallbackMode;
              fallbackOpacity = photoRule.brightOpacity ?? fallbackOpacity;
            } else {
              fallbackMode = photoRule.darkMode || fallbackMode;
              fallbackOpacity = photoRule.darkOpacity ?? fallbackOpacity;
            }
          } else {
            fallbackMode = photoRule.primaryMode || fallbackMode;
            fallbackOpacity = photoRule.primaryOpacity ?? fallbackOpacity;
          }
        }
        const resolvedBlendMode = useFallback
          ? fallbackMode
          : resolveSeedBlendMode(g?.blend_mode, color.blend_mode);
        await saveTestColor(maskRole, {
          ...color,
          blend_mode: resolvedBlendMode,
          blend_opacity: useFallback ? fallbackOpacity : (g?.blend_opacity ?? color.blend_opacity ?? defaultBlendOpacity),
          shadow_l_offset: g?.shadow_l_offset ?? 0,
          shadow_tint_hex: g?.shadow_tint_hex || null,
          shadow_tint_opacity: g?.shadow_tint_opacity ?? 0,
          approved: 0,
        });
        added += 1;
      }
      setSelectorVersion((v) => v + 1);
      return added;
    } catch (err) {
      console.error(err);
      throw err;
    }
  }

  async function triggerSchemeSeed() {
    setSchemeSeedNotice("");
    if (!schemeMode) return;
    if (!schemeSelection) {
      setSchemeSeedNotice("Select a scheme first.");
      return;
    }
    if (!assetId || !asset?.masks?.length) {
      setSchemeSeedNotice("Load a photo first.");
      return;
    }
    if (!testerColors.length && !Object.keys(testerColorsByMask || {}).length) {
      setSchemeSeedNotice("Scheme colors not loaded yet.");
      return;
    }
    if (schemeSeeding) return;
    setSchemeSeeding(true);
    try {
      schemeSeedRef.current = { key: `${assetId}:${schemeSelection}`, masks: {} };
      if (testerView === "mask" && selectedMask) {
        const added = await seedSchemeColorsForMask(selectedMask, { force: true });
        setSchemeSeedNotice(added ? `Seeded ${added}.` : "No mapped colors to seed.");
      } else {
        const pending = (asset.masks || []).map((m) => m.role).filter(Boolean);
        let totalAdded = 0;
        let skipped = 0;
        for (const maskRole of pending) {
          const added = await seedSchemeColorsForMask(maskRole, { force: true });
          if (!added) {
            skipped += 1;
          } else {
            totalAdded += added || 0;
          }
        }
        if (totalAdded) {
          setSchemeSeedNotice(`Seeded ${totalAdded}.${skipped ? ` Skipped ${skipped} unmapped.` : ""}`);
        } else {
          setSchemeSeedNotice("No mapped colors to seed.");
        }
      }
    } catch (err) {
      setSchemeSeedNotice(err?.message || "Seed failed.");
    } finally {
      setSchemeSeeding(false);
    }
  }

  async function handleSeedAllMasksWithColor(colorInput) {
    if (!asset || !asset?.masks?.length) return;
    if (!colorInput) return;
    setSeeding(true);
    setSeedError("");
    try {
      let baseColor = null;
      if (colorInput === "custom") {
        baseColor = normalizePick(allMaskCustomColor);
      } else {
        const tc = testerColors.find((t) => Number(t.color_id) === Number(colorInput));
        if (!tc) throw new Error("Tester color not found");
        baseColor = normalizePick(tc);
      }
      if (!baseColor?.color_id) throw new Error("Color not valid");
      const color = baseColor;
      await Promise.all(
        asset.masks.map(async (m) => {
          const guess = await fetch(GUESS_URL, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              mask_role: m.role,
              color_id: color.color_id,
              asset_id: assetId,
              photo_id: asset?.photo_id,
              debug: 1,
            }),
          })
            .then((r) => r.json())
            .catch(() => null);
          const g = guess?.ok ? guess.guess : null;
          if (guess?.debug) {
            console.info("mask-guess debug", guess.debug);
          }
          await saveTestColor(m.role, {
            ...color,
            blend_mode: g?.blend_mode || color.blend_mode || defaultBlendMode,
            blend_opacity: g?.blend_opacity ?? color.blend_opacity ?? defaultBlendOpacity,
            shadow_l_offset: g?.shadow_l_offset ?? 0,
            shadow_tint_hex: g?.shadow_tint_hex || null,
            shadow_tint_opacity: g?.shadow_tint_opacity ?? 0,
            approved: schemeMode ? 1 : 0,
          });
        })
      );
      setSelectorVersion((v) => v + 1);
    } catch (err) {
      console.error(err);
      setSeedError(err?.message || "Failed to seed all masks");
    } finally {
      setSeeding(false);
    }
  }

  function getAllowedSchemeColorIds(maskRole) {
    if (!maskRole) return [];
    const mapped = getTesterColorIdsForMask(maskRole);
    if (mapped.length) return mapped;
    const all = getAllSchemeColorIdsForMask(maskRole);
    if (all.length) return all;
    if (Array.isArray(schemeColorIds) && schemeColorIds.length) return schemeColorIds;
    return [];
  }

  function buildSchemeOverridesFromSelection(
    rowsByMask = null,
    { preferActive = true, fillMissing = false } = {}
  ) {
    if (!asset?.masks?.length) return { overrides: {}, activeMap: {} };
    const overrides = {};
    const activeMap = { ...activeColorByMask };
    const missingSwatch = {
      color_id: -1,
      name: "Missing",
      code: "",
      brand: "",
      hex6: "FF00FF",
      lightness: 50,
      hcl_l: 50,
      hcl_h: null,
      hcl_c: null,
    };
    (asset.masks || []).forEach((m) => {
      const role = m.role;
      if (!role) return;
      const pool = getTesterColorsForMask(role);
      const fallbackPool = pool.length ? pool : (schemeMode ? testerColors : []);
      const allowedIds = schemeMode ? getAllowedSchemeColorIds(role) : [];
      const selectedId = preferActive ? activeColorByMask[role] : null;
      let chosen = null;
      if (selectedId && fallbackPool.length) {
        chosen = fallbackPool.find((c) => Number(c.color_id) === Number(selectedId));
        if (allowedIds.length && chosen && !allowedIds.includes(Number(chosen.color_id))) {
          chosen = null;
        }
      }
      if (!chosen) {
        const rows = rowsByMask?.[role] || schemeRowsRef.current?.[role] || [];
        const allowedRows = allowedIds.length
          ? rows.filter((row) => allowedIds.includes(Number(row?.color_id)))
          : rows;
        const approvedRow = allowedRows.find((row) => Number(row?.approved) === 1 || row?.draft_approved === true);
        const fallbackRow = approvedRow || allowedRows[0];
        if (fallbackRow?.color_id) {
          chosen = {
            color_id: fallbackRow.color_id,
            name: fallbackRow.color_name || fallbackRow.color_code || "",
            code: fallbackRow.color_code || "",
            brand: fallbackRow.color_brand || "",
            hex6: fallbackRow.color_hex || "",
            lightness: fallbackRow.color_l ?? null,
            hcl_l: fallbackRow.color_l ?? null,
            hcl_h: fallbackRow.color_h ?? null,
            hcl_c: fallbackRow.color_c ?? null,
          };
        }
      }
      if (!chosen && fallbackPool.length) {
        const fallbackList = allowedIds.length
          ? fallbackPool.filter((c) => allowedIds.includes(Number(c.color_id)))
          : fallbackPool;
        chosen = fallbackList[0] || null;
      }
      if (!chosen && fillMissing) {
        chosen = missingSwatch;
      }
      if (chosen?.color_id) {
        activeMap[role] = chosen.color_id;
      }
      const normalized = normalizePick(chosen);
      if (normalized) overrides[role] = normalized;
    });
    return { overrides, activeMap };
  }

  async function applySchemeSelections() {
    const rowsByMask = {};
    const missing = [];
    for (const m of asset?.masks || []) {
      const role = m.role;
      if (!role) continue;
      const cached = schemeRowsRef.current?.[role] || [];
      if (cached.length) {
        rowsByMask[role] = cached;
      } else {
        missing.push(role);
      }
    }
    if (missing.length) {
      for (const role of missing) {
        try {
          const res = await fetch(
            `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(role)}&${Date.now()}`,
            { credentials: "include", cache: "no-store" }
          );
          const data = await res.json();
          rowsByMask[role] = data?.ok && Array.isArray(data.settings) ? data.settings : [];
        } catch {
          rowsByMask[role] = [];
        }
      }
    }
    const { overrides, activeMap } = buildSchemeOverridesFromSelection(rowsByMask, {
      preferActive: true,
      fillMissing: false,
    });
    if (!Object.keys(overrides).length || !asset?.masks?.length) return;
    setActiveColorByMask(activeMap);
    setApplyingAll(true);
    try {
      const mergedOverrides = { ...(maskOverrides || {}), ...overrides };
      const settingsByMask = rowsByMask;
      for (const m of asset.masks || []) {
        const swatch = mergedOverrides[m.role];
        if (!swatch?.color_id) continue;
        const rows = settingsByMask[m.role] || [];
        const hit = rows
          .filter((row) => Number(row.color_id) === Number(swatch.color_id))
          .reduce((latest, row) => {
            if (!latest) return row;
            const latestId = Number(latest.id ?? 0);
            const rowId = Number(row.id ?? 0);
            return rowId > latestId ? row : latest;
          }, null);
        const resolvedOpacity = hit ? resolveBlendOpacity(hit, defaultBlendOpacity) : defaultBlendOpacity;

        const preset = hit
          ? {
            mode: hit.draft_mode || hit.blend_mode || defaultBlendMode,
            opacity: resolvedOpacity,
            }
          : { mode: defaultBlendMode, opacity: defaultBlendOpacity };
        const shadowSource = hit?.draft_shadow || {
          l_offset: hit?.shadow_l_offset ?? 0,
          tint_hex: hit?.shadow_tint_hex || null,
          tint_opacity: hit?.shadow_tint_opacity ?? 0,
        };
        const shadow = hit ? normalizeShadowStruct(shadowSource) : null;
        const fallbackLightness =
          swatch.lightness ??
          swatch.hcl_l ??
          m?.stats?.l_avg01 ??
          60;
        const tierLabel = resolveTargetBucket(hit, fallbackLightness, overlayPresetConfig.targetBuckets);
        await handleApplyPresetValues(m.role, tierLabel, preset, {
          swatch,
          shadow,
          clearColor: preset.mode === "original",
          autoRender: false,
          autoSave: false,
        });
      }
      setMaskOverrides(mergedOverrides);
      await applyColors(mergedOverrides);
    } finally {
      setApplyingAll(false);
    }
  }

  useEffect(() => {
    if (!schemeMode || !schemeSelection || !assetId || !asset?.masks?.length) return;
    schemeRowsRef.current = {};
    setActiveColorByMask({});
    let cancelled = false;
    async function loadSchemeSelections() {
      const rowsByMask = {};
      for (const m of asset.masks || []) {
        const role = m.role;
        if (!role) continue;
        try {
          const res = await fetch(
            `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(role)}&${Date.now()}`,
            { credentials: "include", cache: "no-store" }
          );
          const data = await res.json();
          rowsByMask[role] = data?.ok && Array.isArray(data.settings) ? data.settings : [];
        } catch {
          rowsByMask[role] = [];
        }
      }
      if (cancelled) return;
      const { overrides, activeMap } = buildSchemeOverridesFromSelection(rowsByMask, {
        preferActive: false,
        fillMissing: false,
      });
      setActiveColorByMask(activeMap);
      setMaskOverrides(overrides);
    }
    loadSchemeSelections();
    return () => {
      cancelled = true;
    };
  }, [schemeMode, schemeSelection, assetId, asset?.masks?.length]);

  function showOriginalPhoto() {
    setAssignments({});
    setError("");
  }

  async function clearSchemeSettings() {
    if (!schemeMode || !assetId || !asset?.masks?.length) return;
    if (schemeClearing) return;
    setSchemeClearing(true);
    try {
      const otherSchemes = (schemeOptions || []).filter((s) => String(s.id) !== String(schemeSelection));
      const currentSchemeColors = await fetch(
        `${API_FOLDER}/v2/admin/hoa-schemes/colors/list.php?scheme_id=${encodeURIComponent(schemeSelection)}&_=${Date.now()}`,
        { credentials: "include", cache: "no-store" }
      ).then((r) => r.json()).catch(() => null);
      const currentColors = Array.isArray(currentSchemeColors?.items) ? currentSchemeColors.items : [];
      const currentSchemeColorIds = new Set(
        currentColors.map((row) => Number(row?.color_id)).filter(Boolean)
      );

      const keepColorIds = new Set();
      await Promise.all(
        otherSchemes.map(async (scheme) => {
          const colorsRes = await fetch(
            `${API_FOLDER}/v2/admin/hoa-schemes/colors/list.php?scheme_id=${encodeURIComponent(scheme.id)}&_=${Date.now()}`,
            { credentials: "include", cache: "no-store" }
          ).then((r) => r.json()).catch(() => null);
          const colors = Array.isArray(colorsRes?.items) ? colorsRes.items : [];
          colors.forEach((row) => {
            const colorId = Number(row?.color_id);
            if (colorId) keepColorIds.add(colorId);
          });
        })
      );

      for (const mask of asset.masks) {
        const res = await fetch(
          `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(mask.role)}&${Date.now()}`,
          { credentials: "include", cache: "no-store" }
        );
        const data = await res.json();
        const rows = Array.isArray(data?.settings) ? data.settings : [];
        for (const row of rows) {
          const colorId = Number(row?.color_id);
          if (!colorId) continue;
          if (!currentSchemeColorIds.has(colorId)) continue;
          if (keepColorIds.has(colorId)) continue;
          await fetch(`${API_FOLDER}/v2/admin/mask-blend/delete.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              asset_id: assetId,
              mask: mask.role,
              id: row.id,
            }),
          });
        }
      }
      setActiveColorByMask({});
      setMaskOverrides({});
      setAssignments({});
      setSelectorVersion((v) => v + 1);
    } catch (err) {
      console.warn("Failed to clear scheme settings", err);
    } finally {
      setSchemeClearing(false);
    }
  }

  // Apply the currently selected per-color to all masks (render only, no save)
  async function handleApplyAllMasksColor(colorInput) {
    if (!asset || !asset?.masks?.length) return;
    let baseColor = null;
    if (colorInput === "custom") {
      baseColor = normalizePick(allMaskCustomColor);
    } else {
      const tc = testerColors.find((t) => Number(t.color_id) === Number(colorInput));
      baseColor = normalizePick(tc);
    }
    if (!baseColor?.color_id) return;

    setApplyingAll(true);
    try {
      // Fetch existing settings for all masks up-front to avoid per-mask drift and speed things up
      const settingsByMask = {};
      await Promise.all(
        (asset.masks || []).map(async (m) => {
          try {
            const res = await fetch(
              `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(m.role)}&${Date.now()}`,
              { credentials: "include", cache: "no-store" }
            );
            const data = await res.json();
            if (data?.ok && Array.isArray(data.settings)) {
              settingsByMask[m.role] = data.settings;
            } else {
              settingsByMask[m.role] = [];
            }
          } catch {
            settingsByMask[m.role] = [];
          }
        })
      );

      const nextOverrides = { ...(maskOverrides || {}) };
      for (const m of asset.masks) {
        let preset = null;
        let shadow = null;
        let tierLightness = null;
        const hit = (settingsByMask[m.role] || []).find((row) => Number(row.color_id) === Number(baseColor.color_id));
        if (hit) {
          preset = {
            mode: hit.blend_mode,
            opacity: resolveBlendOpacity(hit, defaultBlendOpacity),
          };
          shadow = {
            l_offset: hit.shadow_l_offset ?? 0,
            tint_hex: hit.shadow_tint_hex || null,
            tint_opacity: hit.shadow_tint_opacity ?? 0,
          };
          tierLightness = hit.color_l ?? hit.base_lightness ?? null;
        } else {
          const guess = await fetch(GUESS_URL, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              mask_role: m.role,
              color_id: baseColor.color_id,
              asset_id: assetId,
              photo_id: asset?.photo_id,
              debug: 1,
            }),
          })
            .then((r) => r.json())
            .catch(() => null);
          const g = guess?.ok ? guess.guess : null;
          if (guess?.debug) {
            console.info("mask-guess debug", guess.debug);
          }
          preset = {
            mode: g?.blend_mode || "colorize",
            opacity: g?.blend_opacity ?? 0.5,
          };
          shadow = g
            ? {
                l_offset: g.shadow_l_offset ?? 0,
                tint_hex: g.shadow_tint_hex || null,
                tint_opacity: g.shadow_tint_opacity ?? 0,
              }
            : null;
          tierLightness = g?.color_l ?? g?.base_lightness ?? null;
        }
        const tierLabel = bucketForLightness(
          (tierLightness ?? hit?.color_l ?? hit?.base_lightness ?? baseColor.lightness ?? baseColor.hcl_l ?? m?.stats?.l_avg01 ?? 60),
          overlayPresetConfig.targetBuckets
        );
        await handleApplyPresetValues(m.role, tierLabel, preset, {
          swatch: baseColor,
          shadow,
          clearColor: preset.mode === "original",
          autoRender: false,
          autoSave: true,
        });
        // Also persist mask-blend so both modes stay in sync
        try {
          const mbPayload = {
            asset_id: assetId,
            mask: m.role,
            entry: {
              id: hit?.id ?? null,
              color_id: baseColor.color_id,
              color_name: baseColor.name || baseColor.label || baseColor.code || null,
              color_brand: baseColor.brand || baseColor.color_brand || null,
              color_code: baseColor.code || baseColor.color_code || null,
              color_hex: (baseColor.hex6 || baseColor.hex || "").replace("#", ""),
              base_lightness: m?.stats?.l_avg01 ?? hit?.base_lightness ?? null,
              color_l: baseColor.lightness ?? baseColor.lab_l ?? baseColor.hcl_l ?? null,
              color_h: baseColor.hcl_h ?? null,
              color_c: baseColor.hcl_c ?? null,
              blend_mode: preset.mode,
              blend_opacity: preset.opacity,
              shadow_l_offset: shadow?.l_offset ?? 0,
              shadow_tint_hex: shadow?.tint_hex ? shadow.tint_hex.replace("#", "") : null,
              shadow_tint_opacity: shadow?.tint_opacity ?? 0,
              approved: schemeMode ? 1 : 0,
            },
          };
          await fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(mbPayload),
          });
        } catch (err) {
          console.warn("Failed to persist mask-blend setting for", m.role, err);
        }
        nextOverrides[m.role] = baseColor;
      }
      setMaskOverrides(nextOverrides);
      await applyColors(nextOverrides);
      setSelectorVersion((v) => v + 1);
    } finally {
      setApplyingAll(false);
    }
  }

  const gridEntries = useMemo(() => {
    const map = {};
    (asset?.masks || []).forEach((m) => {
      const fromAp = apEntriesMap[m.role];
      const fromOverride = maskOverrides[m.role];
      const colorSource = fromAp?.color || (fromOverride ? normalizePick(fromOverride) : null);
      const color = colorSource
        ? {
            id: colorSource.id || colorSource.color_id,
            name: colorSource.name || colorSource.code || "",
            code: colorSource.code || "",
            brand: colorSource.brand || "",
            hex6: colorSource.hex6 || "",
            lightness:
              typeof colorSource.lightness === "number"
                ? colorSource.lightness
                : typeof colorSource.hcl_l === "number"
                  ? colorSource.hcl_l
                  : typeof colorSource.lab_l === "number"
                    ? colorSource.lab_l
                    : null,
            hcl_l: colorSource.hcl_l ?? null,
            lab_l: colorSource.lab_l ?? null,
          }
        : null;
      map[m.role] = {
        mask_role: m.role,
        color,
        blend_mode: fromAp?.blend_mode || "",
        blend_opacity: fromAp?.blend_opacity ?? null,
        shadow_l_offset: fromAp?.shadow_l_offset ?? null,
        shadow_tint_hex: fromAp?.shadow_tint_hex || "",
        shadow_tint_opacity: fromAp?.shadow_tint_opacity ?? null,
      };
    });
    return map;
  }, [asset?.masks, apEntriesMap, maskOverrides]);

  /* ---------- Helpers ---------- */

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
    const hclHRaw = obj.hcl_h ?? obj.h ?? obj.hcl?.h ?? null;
    const hclCRaw = obj.hcl_c ?? obj.c ?? obj.hcl?.c ?? null;
    const hcl_h = Number.isFinite(Number(hclHRaw)) ? Number(hclHRaw) : null;
    const hcl_c = Number.isFinite(Number(hclCRaw)) ? Number(hclCRaw) : null;
    return {
      ...obj,
      color_id: Number(colorId),
      hex6: hex6.toUpperCase(),
      lightness,
      hcl_h,
      hcl_c,
    };
  }

  function lightnessFromSwatch(sw) {
    if (!sw) return null;
    if (typeof sw.lightness === "number" && Number.isFinite(sw.lightness)) return Number(sw.lightness);
    if (typeof sw.lab_l === "number" && Number.isFinite(sw.lab_l)) return Number(sw.lab_l);
    if (typeof sw.hcl_l === "number" && Number.isFinite(sw.hcl_l)) return Number(sw.hcl_l);
    return null;
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
      const defaultTier = targetLightness != null
        ? bucketForLightness(targetLightness, overlayPresetConfig.targetBuckets)
        : null;
      const tierKey = appliedTierByMask[maskRole] || defaultTier;
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
        color_l: targetLightness,
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
      if (!forcedOverrides) {
        ROLE_SEQUENCE.forEach((group) => {
          const sw = collectSwatch(roleGroups[group]);
          if (sw && !sw.__skip) requiredSwatches.push(sw);
        });
      }
      Object.values(overridesMap || {}).forEach((sw) => {
        const norm = collectSwatch(sw);
        if (norm && !norm.__skip) requiredSwatches.push(norm);
      });

      if (!requiredSwatches.length) {
        setAssignments({});
        return;
      }

      const uniqueIds = Array.from(new Set(requiredSwatches.map((sw) => sw.color_id)));
      const byId = {};
      const idsNeedingFetch = [];
      // Seed from the swatch objects we already have (avoids false "missing" when the API is fine)
      requiredSwatches.forEach((sw) => {
        if (!sw) return;
        const hasHex = sw.hex6 && sw.hex6.length >= 3;
        const Lval = lightnessFromSwatch(sw);
        if (hasHex && Number.isFinite(Lval)) {
          byId[sw.color_id] = {
            hex6: sw.hex6.toUpperCase(),
            L: Number(Lval),
            a: Number(sw.lab_a ?? sw.a ?? 0),
            b: Number(sw.lab_b ?? sw.b ?? 0),
          };
        } else {
          idsNeedingFetch.push(sw.color_id);
        }
      });

      const idsToFetch = uniqueIds.filter((id) => !byId[id] && idsNeedingFetch.includes(id));
      if (idsToFetch.length) {
        const responses = await Promise.all(
          idsToFetch.map(async (id) => {
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

        for (const { id, data } of responses) {
          if (data?.ok && data.color) {
            const c = data.color;
            byId[id] = {
              hex6: (c.hex6 || "").toUpperCase(),
              L: Number(c.lab_l ?? c.L ?? 0),
              a: Number(c.lab_a ?? c.a ?? 0),
              b: Number(c.lab_b ?? c.b ?? 0),
            };
          }
        }
      }

      const missing = uniqueIds.filter((id) => !byId[id]).map((id) => `#${id}`);

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

      if (missing.length) {
        setAssignments((prev) => ({ ...(prev || {}), ...resolved }));
        setError(`Missing colors: ${missing.join(", ")}`);
      } else {
        setAssignments(resolved);
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

  useEffect(() => {
    if (!asset?.masks?.length) return;
    setAppliedTierByMask((prev) => {
      const next = { ...(prev || {}) };
      (asset.masks || []).forEach((m) => {
        if (!next[m.role]) {
          next[m.role] = null;
        }
      });
      return next;
    });
  }, [asset?.masks]);

  function handleSearchQueryChange({ q, tagsText, page }) {
    const nav = new URLSearchParams();
    if (assetId) nav.set("asset", assetId);
    if (q) nav.set("q", q);
    if (tagsText) nav.set("tags", tagsText);
    if (page) nav.set("page", String(page));
    navigate(`${baseRoute}?${nav.toString()}`, { replace: true });
  }


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
  setAppliedTierByMask((prev) => ({ ...(prev || {}), [mask]: safeTier }));
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
    if (mask && tier) {
      setAppliedTierByMask((prev) => ({ ...(prev || {}), [mask]: tier }));
    }
    setMaskOverlays((prev) => ({
      ...prev,
      [mask]: updated,
    }));
    if (!autoSave) {
      const dirty = computeDirtyFlag(mask, updated, maskTextures[mask]);
      const statusLabel = shouldClearColor
        ? "Original Photo"
        : `${preset.mode} · ${(Number(preset.opacity ?? 0) * 100).toFixed(1)}%`;
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

  function handleApShadowEdit(maskRole) {
    const entry = apEntriesMap[maskRole] || { mask_role: maskRole };
    const currentOffset = entry.shadow_l_offset ?? 0;
    const currentTintPct = entry.shadow_tint_opacity != null
      ? (entry.shadow_tint_opacity * 100).toFixed(1)
      : "0.0";
    const offsetStr = window.prompt("Shadow L offset (negative darker, positive lighter)", String(currentOffset));
    if (offsetStr === null) return;
    const offsetNum = clampNumber(Number(offsetStr), -50, 50);
    const tintStr = window.prompt("Shadow tint opacity % (0-100)", String(currentTintPct));
    if (tintStr === null) {
      handleMaskGridChange(maskRole, { shadow_l_offset: offsetNum });
      return;
    }
    const tintNum = clampNumber(Number(tintStr), 0, 100);
    handleMaskGridChange(maskRole, {
      shadow_l_offset: offsetNum,
      shadow_tint_opacity: tintNum / 100,
    });
  }

  function buildApSignature(map) {
    const entries = Object.values(map || {})
      .map((row) => normalizeEntryForSave(row))
      .filter(Boolean);
    entries.sort((a, b) => (a.mask_role || "").localeCompare(b.mask_role || ""));
    return JSON.stringify(entries);
  }

  function cloneEntriesMap(map) {
    return JSON.parse(JSON.stringify(map || {}));
  }

  function buildOverridesFromEntries(map) {
    const overrides = {};
    const active = {};
    Object.values(map || {}).forEach((entry) => {
      const normalized = normalizePick(entry?.color);
      if (!normalized || !entry?.mask_role) return;
      overrides[entry.mask_role] = normalized;
      active[entry.mask_role] = normalized.color_id;
    });
    return { overrides, active };
  }

async function handleOverlaySave(mask, { overrideSettings = null } = {}) {
  if (!asset?.asset_id) return false;
  const payload = {
    asset_id: asset.asset_id,
    mask,
    settings: {
      ...normalizeOverlayPayload(overrideSettings || maskOverlays[mask]),
      approved: 1,
    },
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
    const raw = await res.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch {
      throw new Error(`Blend save failed (${res.status}): ${raw.slice(0, 120)}`);
    }
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
    setSaveNotice("");
    setSaveResult(null);
    setSaveRenderChoice("generate");
    setSaveMode("new");
    setSaveTitle("");
    setSaveDisplayTitle("");
    setSaveNotes("");
    setExistingPaletteId("");
    setExistingLoadError("");
    if (asset?.asset_id) {
      loadExistingPalettes(asset.asset_id);
    }
  }

  function closeSaveModal() {
    if (saveBusy) return;
    setSaveModalOpen(false);
    setSaveError("");
    setSaveNotice("");
    setSaveResult(null);
  }

  function handleSelectExistingPalette(value) {
    setExistingPaletteId(value);
    const selected = existingPalettes.find((row) => String(row.id) === String(value));
    if (selected) {
      setSaveMode("update");
      setSaveTitle(selected.title || "");
      setSaveDisplayTitle(selected.display_title || "");
      setSaveNotes(selected.notes || "");
    } else {
      setSaveMode("new");
    }
  }

  async function loadExistingPalettes(assetId) {
    setExistingLoading(true);
    setExistingLoadError("");
    try {
      const params = new URLSearchParams();
      params.set("asset_id", assetId);
      params.set("limit", "200");
      params.set("_", String(Date.now()));
      const res = await fetch(`${API_FOLDER}/v2/admin/applied-palettes/list.php?${params.toString()}`, {
        credentials: "include",
        headers: { Accept: "application/json" },
        cache: "no-store",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to load palettes");
      }
      setExistingPalettes(data.items || []);
    } catch (err) {
      setExistingLoadError(err?.message || "Failed to load palettes");
    } finally {
      setExistingLoading(false);
    }
  }

  async function handleLoadPreviousPalette(paletteId) {
    const idNum = Number(paletteId);
    if (!Number.isFinite(idNum) || idNum <= 0) {
      setLoadPreviousId("");
      setLoadPreviousError("");
      return;
    }
    if (!assetId) return;
    setLoadPreviousBusy(true);
    setLoadPreviousError("");
    setLoadPreviousId(String(idNum));
    try {
      const res = await fetch(`${AP_GET_URL}?id=${idNum}&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load applied palette");
      const entries = Array.isArray(data.entries) ? data.entries : [];
      const rolesNeedingLookup = new Set();
      entries.forEach((e) => {
        if (!e?.mask_role) return;
        const needsMode = e.setting_blend_mode == null && e.blend_mode == null;
        const needsOpacity = e.setting_blend_opacity == null && e.blend_opacity == null;
        const needsShadow = e.setting_shadow_l_offset == null && e.shadow_l_offset == null;
        if (needsMode || needsOpacity || needsShadow) {
          rolesNeedingLookup.add(e.mask_role);
        }
      });
      const blendSettingsByRole = {};
      if (rolesNeedingLookup.size) {
        const lookups = Array.from(rolesNeedingLookup).map(async (maskRole) => {
          try {
            const url = `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(maskRole)}&${Date.now()}`;
            const r = await fetch(url, { credentials: "include", cache: "no-store" });
            const payload = await r.json();
            if (payload?.ok && Array.isArray(payload.settings)) {
              blendSettingsByRole[maskRole] = payload.settings;
            }
          } catch {
            // ignore lookup errors; fall back to defaults
          }
        });
        await Promise.all(lookups);
      }
      const overrides = {};
      const active = {};
      const nextOverlays = { ...(maskOverlays || {}) };
      const blendSaves = [];
      entries.forEach((e) => {
        const maskRole = e.mask_role;
        if (!maskRole) return;
        const hexRaw = e.color_hex || e.color_hex6 || e.hex6 || "";
        const hex6 = hexRaw.replace(/[^0-9a-f]/gi, "").slice(0, 6).toUpperCase();
        const lightnessRaw = e.color_hcl_l ?? e.color_lab_l ?? null;
        const color = normalizePick({
          color_id: e.color_id,
          name: e.color_name || e.color_code || "",
          code: e.color_code || "",
          brand: e.color_brand || "",
          hex6,
          lightness: lightnessRaw,
          hcl_l: e.color_hcl_l ?? null,
          lab_l: e.color_lab_l ?? null,
        });
        if (!color) return;
        overrides[maskRole] = color;
        active[maskRole] = color.color_id;
        const fallbackSetting = getMaskBlendSettingForColor(blendSettingsByRole[maskRole], e.color_id);
        const mode =
          e.setting_blend_mode ??
          e.blend_mode ??
          fallbackSetting?.blend_mode ??
          defaultBlendMode;
        const opacity =
          e.setting_blend_opacity ??
          e.blend_opacity ??
          fallbackSetting?.blend_opacity ??
          defaultBlendOpacity;
        const shadow = normalizeShadowStruct({
          l_offset: e.setting_shadow_l_offset ?? e.shadow_l_offset ?? fallbackSetting?.shadow_l_offset ?? 0,
          tint_hex: e.setting_shadow_tint_hex || e.shadow_tint_hex || fallbackSetting?.shadow_tint_hex || null,
          tint_opacity: e.setting_shadow_tint_opacity ?? e.shadow_tint_opacity ?? fallbackSetting?.shadow_tint_opacity ?? 0,
        });
        const nextOverlay = cloneOverlayStruct(nextOverlays[maskRole]);
        OVERLAY_TIERS.forEach((tier) => {
          nextOverlay[tier] = {
            mode: typeof mode === "string" && mode ? mode : null,
            opacity: typeof opacity === "number" ? opacity : defaultBlendOpacity,
          };
        });
        nextOverlay._shadow = shadow;
        nextOverlays[maskRole] = nextOverlay;

        if (assetId && e.color_id) {
          blendSaves.push({
            mask_role: maskRole,
            color_id: e.color_id,
            color_name: e.color_name || null,
            color_brand: e.color_brand || null,
            color_code: e.color_code || null,
            color_hex: hex6 || null,
            blend_mode: typeof mode === "string" && mode ? mode : defaultBlendMode,
            blend_opacity: typeof opacity === "number" ? opacity : defaultBlendOpacity,
            shadow_l_offset: shadow.l_offset ?? 0,
            shadow_tint_hex: shadow.tint_hex ? shadow.tint_hex.replace("#", "") : null,
            shadow_tint_opacity: shadow.tint_opacity ?? 0,
          });
        }
      });
      setMaskOverrides(overrides);
      setActiveColorByMask(active);
      setMaskOverlays(nextOverlays);
      setOverlayStatus((prev) => {
        const next = { ...(prev || {}) };
        Object.keys(nextOverlays).forEach((mask) => {
          const dirty = computeDirtyFlag(mask, nextOverlays[mask], maskTextures[mask]);
          next[mask] = { ...(next[mask] || {}), dirty };
        });
        return next;
      });
      if (blendSaves.length) {
        await Promise.all(
          blendSaves.map((entry) =>
            fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
              method: "POST",
              credentials: "include",
              headers: { "Content-Type": "application/json", Accept: "application/json" },
              body: JSON.stringify({ asset_id: assetId, mask: entry.mask_role, entry }),
            }).catch(() => null)
          )
        );
        setSelectorVersion((v) => v + 1);
      }
      await applyColors(overrides);
    } catch (err) {
      setLoadPreviousError(err?.message || "Failed to load applied palette");
    } finally {
      setLoadPreviousBusy(false);
    }
  }

  async function handleSaveAppliedPalette() {
    if (saveBusy || !asset?.asset_id || !hasPaletteEntries) return;
    if (saveMode === "update" && !existingPaletteId) {
      setSaveError("Select a palette to update.");
      return;
    }
    if (Object.values(overlayStatus || {}).some((row) => row?.dirty)) {
      await handleSaveAllDirtyMasks();
    }
    const normalizedEntries = [...paletteEntries]
      .map((entry) => ({
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
        color_l: entry.color_l,
      }))
      .sort((a, b) => a.mask_role.localeCompare(b.mask_role));
    const signaturePayload = {
      asset_id: asset.asset_id,
      title: saveTitle.trim(),
      notes: saveNotes.trim(),
      render: saveRenderChoice,
      entries: normalizedEntries,
    };
    const signature = JSON.stringify(signaturePayload);
    if (signature === lastSavedSig) {
      setSaveNotice("No changes since the last save.");
      return;
    }
    setSaveBusy(true);
    setSaveError("");
    setSaveNotice("");
    setSaveResult(null);
    try {
      await Promise.all(
        paletteEntries
          .filter((entry) => entry?.color_id && !entry?.is_original)
          .map((entry) =>
            saveTestColor(entry.mask_role, {
              color_id: entry.color_id,
              name: entry.color_name,
              brand: entry.color_brand,
              code: entry.color_code,
              hex6: entry.color_hex,
              blend_mode: entry.blend_mode ?? defaultBlendMode,
              blend_opacity: entry.blend_opacity ?? defaultBlendOpacity,
              shadow_l_offset: entry.shadow_l_offset ?? 0,
              shadow_tint_hex: entry.shadow_tint_hex || null,
              shadow_tint_opacity: entry.shadow_tint_opacity ?? 0,
              approved: 1,
            })
          )
      );
      setSelectorVersion((v) => v + 1);
    } catch (err) {
      setSaveError(err?.message || "Failed to save mask settings.");
      setSaveBusy(false);
      return;
    }
    const payload = {
      asset_id: asset.asset_id,
      title: saveTitle.trim(),
      display_title: saveDisplayTitle.trim(),
      notes: saveNotes.trim(),
      entries: paletteEntries.map((entry) => ({
        mask_role: entry.mask_role,
        color_id: entry.color_id,
        color_name: entry.color_name,
        color_brand: entry.color_brand,
        color_code: entry.color_code,
        color_hex: entry.color_hex,
        base_lightness: entry.base_lightness,
        color_l: entry.color_l,
        blend_mode: entry.blend_mode,
        blend_opacity: entry.blend_opacity,
        shadow_l_offset: entry.shadow_l_offset,
        shadow_tint_hex: entry.shadow_tint_hex,
        shadow_tint_opacity: entry.shadow_tint_opacity,
      })),
    };
    const shouldCacheRender = saveRenderChoice === "generate" || saveMode === "update";
    if (shouldCacheRender) {
      payload.render = { cache: true };
    }
    try {
      const request = saveMode === "update"
        ? fetch(`${API_FOLDER}/v2/admin/applied-palettes/update-entries.php`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            palette_id: Number(existingPaletteId),
            title: saveTitle.trim(),
            display_title: saveDisplayTitle.trim(),
            notes: saveNotes.trim(),
            entries: payload.entries,
            render: payload.render,
            clear_render: shouldCacheRender,
          }),
        })
        : fetch(`${API_FOLDER}/v2/admin/applied-palettes/save.php`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(payload),
        });
      const res = await request;
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to save palette");
      }
      const paletteId = saveMode === "update" ? Number(existingPaletteId) : data.palette_id;
      const viewUrl = typeof window !== "undefined"
        ? `${window.location.origin}/view/${paletteId}?admin=1`
        : `/view/${paletteId}`;
      setSaveResult({
        palette_id: paletteId,
        entries: data.entries_saved ?? data.entries ?? null,
        render_cache: data.render_cache || data.render_cache_info || null,
        render_cache_error: data.render_cache_error || null,
        view_url: viewUrl,
      });
      setSaveSuccessAt(Date.now());
      setExistingPalettes((prev) => {
        const next = Array.isArray(prev) ? [...prev] : [];
        const idx = next.findIndex((row) => String(row?.id) === String(paletteId));
        const updated = {
          ...(idx >= 0 ? next[idx] : {}),
          id: paletteId,
          title: saveTitle.trim(),
          display_title: saveDisplayTitle.trim(),
          notes: saveNotes.trim(),
          asset_id: asset?.asset_id || (idx >= 0 ? next[idx]?.asset_id : undefined),
        };
        if (idx >= 0) {
          next[idx] = updated;
        } else {
          next.unshift(updated);
        }
        return next;
      });
      if (asset?.asset_id) {
        await loadExistingPalettes(asset.asset_id);
        setExistingPaletteId(String(paletteId));
        setSaveMode("update");
      }
      setLastSavedSig(signature);
    } catch (err) {
      setSaveError(err?.message || "Failed to save palette");
    } finally {
      setSaveBusy(false);
    }
  }

  async function handleSaveAllDirtyMasks() {
    if (bulkSaveState.saving || !asset?.asset_id) return;
    const dirtyMasks = Object.keys(overlayStatus || {}).filter((m) => overlayStatus[m]?.dirty);
    const approveSchemeRows = async () => {
      if (!schemeMode || !asset?.masks?.length) return 0;
      const rowsByMask = schemeRowsRef.current || {};
      const approveTasks = [];
      for (const mask of asset.masks || []) {
        const role = mask.role;
        let rows = rowsByMask[role];
        if (!rows) {
          try {
            const res = await fetch(
              `${API_FOLDER}/v2/admin/mask-blend/list.php?asset_id=${encodeURIComponent(assetId)}&mask=${encodeURIComponent(role)}&${Date.now()}`,
              { credentials: "include", cache: "no-store" }
            );
            const data = await res.json();
            rows = data?.ok && Array.isArray(data.settings) ? data.settings : [];
          } catch {
            rows = [];
          }
        }
        (rows || []).forEach((row) => {
          if (!row?.id || !row?.color_id) return;
          const entry = {
            id: row.id,
            color_id: row.color_id,
            color_name: row.color_name,
            color_brand: row.color_brand,
            color_code: row.color_code,
            color_hex: row.color_hex,
            base_lightness: row.base_lightness,
            color_l: row.color_l,
            color_h: row.color_h,
            color_c: row.color_c,
            blend_mode: row.draft_mode || row.blend_mode || defaultBlendMode,
            blend_opacity: resolveBlendOpacity(row, defaultBlendOpacity),
            shadow_l_offset: row.draft_shadow?.l_offset ?? row.shadow_l_offset ?? 0,
            shadow_tint_hex: row.draft_shadow?.tint_hex
              ? row.draft_shadow.tint_hex.replace("#", "")
              : (row.shadow_tint_hex || null),
            shadow_tint_opacity: row.draft_shadow?.tint_opacity ?? row.shadow_tint_opacity ?? 0,
            approved: 1,
          };
          approveTasks.push(
            fetch(`${API_FOLDER}/v2/admin/mask-blend/save.php`, {
              method: "POST",
              credentials: "include",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ asset_id: assetId, mask: role, entry }),
            }).catch(() => null)
          );
        });
      }
      if (approveTasks.length) {
        await Promise.all(approveTasks);
      }
      return approveTasks.length;
    };

    if (!dirtyMasks.length) {
      setBulkSaveState({ saving: true, message: "Refreshing…", error: "" });
      const approvedCount = await approveSchemeRows();
      setSelectorVersion((v) => v + 1);
      setBulkSaveState({
        saving: false,
        message: approvedCount ? "Approved & refreshed" : "Refreshed",
        error: "",
      });
      setTimeout(() => {
        setBulkSaveState((prev) => ({ ...prev, message: "" }));
      }, 1500);
      return;
    }

    setBulkSaveState({ saving: true, message: "Saving…", error: "" });
    setOverlayStatus((prev) => {
      const next = { ...prev };
      dirtyMasks.forEach((mask) => {
        next[mask] = { ...(next[mask] || {}), approved: true };
      });
      return next;
    });
    const errors = [];
    for (const mask of dirtyMasks) {
      try {
        await handleOverlaySave(mask);
      } catch (err) {
        errors.push(`${mask}: ${err?.message || "failed"}`);
      }
    }
    await approveSchemeRows();
    setSelectorVersion((v) => v + 1);
    if (errors.length) {
      setBulkSaveState({ saving: false, message: "", error: errors.join("; ") });
    } else {
      setBulkSaveState({
        saving: false,
        message: `Saved ${dirtyMasks.length} mask${dirtyMasks.length === 1 ? "" : "s"}`,
        error: "",
      });
      setTimeout(() => {
        setBulkSaveState((prev) => ({ ...prev, message: "" }));
      }, 1500);
    }
  }

  const titleLabel = titleOverride
    || (apPalette?.palette?.id
      ? `Mask Editor for ${apPalette.palette.title || `Palette #${apPalette.palette.id}`}`
      : "Mask Tester");

  return (
    <div className="admin-mask-tester">
      <div className="title">{titleLabel}</div>
      {apPalette && (
        <div className="ap-title-editor">
          <label>
            Applied Palette Handle
            <input
              type="text"
              value={apMetaTitle}
              onChange={(e) => setApMetaTitle(e.target.value)}
            />
          </label>
          <label>
            Display Title (viewer)
            <input
              type="text"
              value={apMetaDisplayTitle}
              onChange={(e) => setApMetaDisplayTitle(e.target.value)}
            />
          </label>
          <button
            className="btn primary"
            type="button"
            onClick={handleSaveApMeta}
            disabled={apMetaSaving}
          >
            {apMetaSaving ? "Saving…" : "Save Titles"}
          </button>
          {apMetaError && <div className="error">{apMetaError}</div>}
          {apMetaNotice && <div className="notice">{apMetaNotice}</div>}
        </div>
      )}
      <div className="app-bar">
        <div className="left">
          {!hideFinder && (
            <button className="btn" onClick={() => setFindOpen(!findOpen)}>
              {findOpen ? "Hide Finder" : "Find Photo"}
            </button>
          )}
        </div>
        <div className="right">
          {asset && apPalette && (
            <>
              <button
                className="btn"
                disabled={saveBusy || !apEntriesForSave.length}
                onClick={() => handleUpdateAppliedPalette({ rerender: true })}
                title={apEntriesForSave.length ? "Update and rerender this applied palette" : "Apply at least one mask color to enable saving"}
              >
                {saveBusy ? "Saving…" : "Save & Rerender"}
              </button>
              <button
                className="btn"
                disabled={!apHasChanges}
                onClick={handleRevertAppliedPalette}
                title={apHasChanges ? "Revert to saved applied palette" : "No unsaved changes"}
              >
                Undo Changes
              </button>
            </>
          )}
          {asset && !apPalette && (
            <button
              className="btn primary"
              disabled={!hasPaletteEntries}
              onClick={openSaveModal}
              title={hasPaletteEntries ? "Save this applied palette" : "Apply at least one mask color to enable saving"}
            >
              Save Applied Palette
            </button>
          )}
          {asset && !apPalette && (
            <div className="mask-tester-actions">
              <button
                className="btn"
                disabled={bulkSaveState.saving}
                onClick={handleSaveAllDirtyMasks}
                title="Save all dirty mask settings"
              >
                {bulkSaveState.saving ? "Saving All…" : "Save All Masks"}
              </button>
              <button
                className="btn btn-small"
                onClick={showOriginalPhoto}
                title="Show the original photo"
              >
                Original
              </button>
              {schemeMode && (
                <>
                  {schemeMappingLink && (
                    <button
                      className="btn btn-small"
                      onClick={() => setMappingOpen(true)}
                      title="Open scheme mapping for this HOA/photo"
                    >
                      Mapping
                    </button>
                  )}
                </>
              )}
            </div>
          )}
        </div>
      </div>


      {findOpen && !hideFinder && (
        <PhotoSearchPicker
          initialQ={initialSearchQ}
          initialTags={initialSearchTags}
          initialPage={initialSearchPage}
          onPick={onPick}
          onQueryChange={handleSearchQueryChange}
        />
      )}

      {mappingOpen && schemeMappingLink && (
        <div
          style={{
            position: "fixed",
            inset: 0,
            background: "rgba(0,0,0,0.45)",
            zIndex: 9999,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            padding: 24,
          }}
          onClick={() => setMappingOpen(false)}
        >
          <div
            style={{
              background: "#fff",
              borderRadius: 12,
              width: "min(1200px, 96vw)",
              height: "min(80vh, 900px)",
              boxShadow: "0 18px 40px rgba(0,0,0,0.25)",
              position: "relative",
              overflow: "hidden",
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <div
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                padding: "10px 14px",
                borderBottom: "1px solid #eee",
                background: "#fafafa",
              }}
            >
              <strong>Scheme Mapping</strong>
              <button className="btn btn-small" onClick={() => setMappingOpen(false)}>
                Close
              </button>
            </div>
            <iframe
              title="HOA Scheme Mapping"
              src={schemeMappingLink}
              style={{ width: "100%", height: "calc(100% - 44px)", border: "0" }}
            />
          </div>
        </div>
      )}

      <div className="content">
        {!assetId && !findOpen && !hideFinder && (
          <div className="notice">Use “Find Photo” to select an image.</div>
        )}
        {loading && <div className="notice">Loading asset…</div>}
        {error && <div className="error">{error}</div>}
        {bulkSaveState.message && <div className="notice">{bulkSaveState.message}</div>}
        {bulkSaveState.error && <div className="error">{bulkSaveState.error}</div>}
        {seedWarning && <div className="notice">{seedWarning}</div>}
        {seedError && <div className="error">{seedError}</div>}
      </div>
      {asset && (
        <div className="mask-tester-layout">
          <div className="tester-left">
            {apPalette ? (
              <div className="panel">
              <div className="panel-head">
                <div className="panel-title">Masks (Applied Palette)</div>
                {apLoadError && <div className="error">{apLoadError}</div>}
              </div>
              <MaskSettingsGrid
                masks={maskGridRows}
                entries={apEntriesMap}
                activeColorByMask={activeColorByMask}
                onChange={handleMaskGridChange}
                onApply={handleMaskGridApply}
                onShadow={handleApShadowEdit}
                showRole
              />
            </div>
          ) : selectedMask ? (
            <div className="panel">
              <div className="panel-head" style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                <div className="mask-tester-load-prev">
                  <label>Load Previous</label>
                  <select
                    value={loadPreviousId}
                    onChange={(e) => handleLoadPreviousPalette(e.target.value)}
                    disabled={!asset?.asset_id || loadPreviousBusy}
                    onFocus={() => {
                      if (asset?.asset_id) {
                        loadExistingPalettes(asset.asset_id);
                      }
                    }}
                  >
                    <option value="">Select palette</option>
                    {existingLoading && (
                      <option value="" disabled>
                        Loading…
                      </option>
                    )}
                    {existingPalettes.map((p) => (
                      <option key={p.id} value={p.id}>
                        {(p.title || `Palette #${p.id}`) + (p.display_title ? ` — ${p.display_title}` : "")}
                      </option>
                    ))}
                  </select>
                  {loadPreviousBusy && <span className="mask-tester-load-prev__status">Loading…</span>}
                  {loadPreviousError && <span className="mask-tester-load-prev__error">{loadPreviousError}</span>}
                </div>
                <div style={{ display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap" }}>
                  <div className="pill-toggle">
                    <button
                      className="btn"
                        onClick={() => setTesterView("mask")}
                        disabled={testerView === "mask"}
                        style={{
                          padding: "4px 8px",
                          fontSize: 12,
                          backgroundColor: testerView === "mask" ? "#1f75ff" : "#e5e7eb",
                          color: testerView === "mask" ? "#fff" : "#333",
                        }}
                      >
                        By Mask
                      </button>
                      <button
                        className="btn"
                        onClick={() => setTesterView("all")}
                        disabled={testerView === "all"}
                        style={{
                          padding: "4px 8px",
                          fontSize: 12,
                          backgroundColor: testerView === "all" ? "#1f75ff" : "#e5e7eb",
                          color: testerView === "all" ? "#fff" : "#333",
                        }}
                      >
                        {schemeMode ? "By Scheme" : "By Color"}
                      </button>
                    </div>
                    {testerView === "mask" ? (
                      <select
                        value={selectedMask || ""}
                        onChange={(e) => setSelectedMask(e.target.value || null)}
                        style={{ minWidth: 160, padding: "6px 8px", borderRadius: 6, border: "1px solid #ccc" }}
                      >
                        <option value="">Select mask</option>
                        {(asset?.masks || []).map((m) => (
                          <option key={m.role} value={m.role}>{m.role}</option>
                        ))}
                      </select>
                    ) : (
                      schemeMode ? (
                        <>
                          <select
                            value={schemeSelection}
                            onChange={(e) => onSchemeChange && onSchemeChange(e.target.value)}
                            style={{ minWidth: 200, padding: "6px 8px", borderRadius: 6, border: "1px solid #ccc" }}
                          >
                            <option value="">Select scheme</option>
                            {(schemeOptions || []).map((scheme) => (
                              <option key={scheme.id} value={scheme.id}>
                                {scheme.scheme_code} {scheme.notes ? `• ${scheme.notes}` : ""}
                              </option>
                            ))}
                          </select>
                          <button
                            className="btn btn-small"
                            onClick={triggerSchemeSeed}
                            title="Seed scheme colors"
                            disabled={!schemeSelection || !asset?.masks?.length || schemeSeeding}
                          >
                            {schemeSeeding ? "Seeding…" : "Seed"}
                          </button>
                          {schemeSeedNotice && (
                            <span style={{ fontSize: 11, color: "#666" }}>{schemeSeedNotice}</span>
                          )}
                        </>
                      ) : (
                        <select
                          value={allMaskColorId}
                          onChange={(e) => {
                            const val = e.target.value;
                            setAllMaskColorId(val);
                            if (val !== "other") setAllMaskCustomColor(null);
                          }}
                          style={{ minWidth: 160, padding: "6px 8px", borderRadius: 6, border: "1px solid #ccc" }}
                        >
                          <option value="">Select tester color</option>
                          {sortedTesterColors.map((tc) => (
                            <option key={tc.color_id} value={tc.color_id}>
                              {tc.name} ({tc.note || tc.code || tc.color_id})
                            </option>
                          ))}
                          <option value="other">Other…</option>
                        </select>
                      )
                    )}
                  </div>

                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    <select
                      value={testerView === "mask" ? maskAddColorId : allMaskColorId}
                      onChange={(e) => {
                        const val = e.target.value;
                        if (testerView === "mask") {
                          setMaskAddColorId(val);
                          if (val !== "other") setMaskAddCustomColor(null);
                        } else {
                          setAllMaskColorId(val);
                          if (val !== "other") setAllMaskCustomColor(null);
                        }
                      }}
                      style={{ minWidth: 170, padding: "6px 8px", borderRadius: 6, border: "1px solid #ccc" }}
                    >
                      <option value="">Select color</option>
                      {(testerView === "mask" ? sortedMaskTesterColors : sortedTesterColors).map((tc) => (
                        <option key={tc.color_id} value={tc.color_id}>
                          {tc.name} ({tc.note || tc.code || tc.color_id})
                        </option>
                      ))}
                      <option value="other">Other…</option>
                    </select>

                    {(testerView === "mask" ? maskAddColorId : allMaskColorId) === "other" && (
                      <div style={{ minWidth: 220, position: "relative", zIndex: 3, pointerEvents: "auto" }}>
                        <FuzzySearchColorSelect
                          key={testerView === "mask" ? "mask-other" : "color-other"}
                          onSelect={(c) => (testerView === "mask" ? setMaskAddCustomColor(c) : setAllMaskCustomColor(c))}
                          onEmpty={() => (testerView === "mask" ? setMaskAddCustomColor(null) : setAllMaskCustomColor(null))}
                          value={testerView === "mask" ? maskAddCustomColor : allMaskCustomColor}
                          label=""
                          showLabel={false}
                          autoFocus
                          manualOpen={false}
                          suppressFocus={false}
                        />
                      </div>
                    )}

                    {testerView === "mask" ? (
                      <>
                        {!hideTesterSourceControls && (
                          <button
                            className="btn"
                            onClick={openTesterSourceModal}
                            disabled={!selectedMask || seeding}
                            title="Choose a tester source and seed colors for this mask"
                          >
                            {seeding ? "Seeding…" : "Add Test Colors"}
                          </button>
                        )}
                        <button
                          className="btn"
                          onClick={() =>
                            schemeMode
                              ? handleAddColorToScheme(maskAddColorId === "other" ? "custom" : maskAddColorId)
                              : handleAddColorToMask(maskAddColorId === "other" ? "custom" : maskAddColorId)
                          }
                          disabled={
                            seeding ||
                            !selectedMask ||
                            (!(maskAddColorId || maskAddCustomColor)) ||
                            (maskAddColorId === "other" && !maskAddCustomColor)
                          }
                          title="Add this color to the selected mask with guessed settings"
                        >
                          {schemeMode ? (schemeAddBusy ? "Adding…" : "Add Color to Scheme") : "Add Color"}
                        </button>
                        {schemeMode && schemeAddNotice && (
                          <span style={{ fontSize: 12, color: "#2e7d32" }}>{schemeAddNotice}</span>
                        )}
                        {schemeMode && schemeAddError && (
                          <span style={{ fontSize: 12, color: "#b40000" }}>{schemeAddError}</span>
                        )}
                        {!hideTesterSourceControls && (
                          <span style={{ fontSize: 12, color: "#555" }}>
                            Source: {testerSourceType === "standard" ? "Standard Test" : testerSourceLabel}
                          </span>
                        )}
                      </>
                    ) : (
                      <>
                        {!schemeMode && (
                          <>
                            <button
                              className="btn"
                              onClick={() => {
                                const target = allMaskColorId === "other" ? "custom" : allMaskColorId;
                                if (target === "custom" && !allMaskCustomColor) return;
                                handleSeedAllMasksWithColor(target);
                              }}
                              disabled={
                                seeding ||
                                (!allMaskColorId && !allMaskCustomColor) ||
                                (allMaskColorId === "other" && !allMaskCustomColor)
                              }
                              title="Add this tester color to all masks with guessed settings"
                            >
                              {seeding ? "Seeding…" : "Add To All Masks"}
                            </button>
                            <button
                              className="btn primary"
                              onClick={() => handleApplyAllMasksColor(allMaskColorId === "other" ? "custom" : allMaskColorId)}
                              disabled={
                                applyingAll ||
                                !asset ||
                                (!allMaskColorId && !allMaskCustomColor) ||
                                (allMaskColorId === "other" && !allMaskCustomColor)
                              }
                              title="Render this color across all masks"
                            >
                              {applyingAll ? "Applying…" : "Apply"}
                            </button>
                          </>
                        )}
                      </>
                    )}
                  </div>
                </div>
                <div style={{ display: "flex", justifyContent: "flex-start", gap: 8, margin: "6px 0" }}>
                  <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    <button
                      className="btn"
                      onClick={handleSaveAllDirtyMasks}
                      disabled={bulkSaveState.saving}
                      title="Save all dirty mask settings"
                    >
                      {bulkSaveState.saving ? "Saving All…" : "Save All Masks"}
                    </button>
                    {schemeMode && (
                      <>
                        <button
                          className="btn btn-small"
                          onClick={applySchemeSelections}
                          disabled={!schemeSelection || !asset || testerView !== "all" || applyingAll}
                          title="Apply the currently selected scheme colors across all masks"
                        >
                          Apply All
                        </button>
                        <button
                          className="btn btn-small"
                          onClick={clearSchemeSettings}
                          disabled={schemeClearing}
                          title="Clear scheme settings for this photo"
                        >
                          {schemeClearing ? "Clearing…" : "Clear Scheme"}
                        </button>
                      </>
                    )}
                    {(bulkSaveState.message || bulkSaveState.error) && (
                      <span
                        style={{
                          fontSize: 12,
                          color: bulkSaveState.error ? "#c62828" : "#2e7d32",
                        }}
                      >
                        {bulkSaveState.error || bulkSaveState.message}
                      </span>
                    )}
                  </div>
                  <button
                    className="btn"
                    onClick={() => setSelectorVersion((v) => v + 1)}
                    title="Refresh mask settings"
                  >
                    Refresh
                  </button>
                  {testerView === "all" && (
                    !schemeMode && (
                      <button
                        className="btn primary"
                        onClick={() => handleApplyAllMasksColor(allMaskColorId === "other" ? "custom" : allMaskColorId)}
                        disabled={
                          !asset ||
                          (!allMaskColorId && !allMaskCustomColor) ||
                          (allMaskColorId === "other" && !allMaskCustomColor) ||
                          applyingAll
                        }
                        title="Render the selected color on all masks"
                      >
                        {applyingAll ? "Applying…" : "Apply"}
                      </button>
                    )
                  )}
                </div>
                {testerView === "mask" ? (
                  <MaskBlendHistory
                    key={selectedMask || "mask"}
                    assetId={asset.asset_id}
                    maskRole={selectedMask}
                    baseLightness={selectedMaskData?.stats?.l_avg01 ?? selectedMaskData?.base_lightness ?? null}
                    selectorVersion={selectorVersion}
                    activeColorId={activeColorByMask[selectedMask]}
                    onSelectRow={(row) => setActiveColorByMask((prev) => ({ ...prev, [selectedMask]: row.color_id }))}
                    onRowsChange={(rows) => {
                      if (schemeMode && selectedMask) {
                        schemeRowsRef.current[selectedMask] = rows;
                      }
                    }}
                    filterColorIds={
                      schemeMode
                        ? (testerView === "mask" ? null : getTesterColorIdsForMask(selectedMask))
                        : null
                    }
                    forceApproved={schemeMode ? 1 : null}
                    sortByColor={schemeMode}
                    sortByTargetLightness
                    onApplyBlend={(mask, tier, preset, extra) =>
                      handleApplyPresetValues(mask, tier, preset, {
                        autoSave: true,
                        autoRender: true,
                        ...(extra || {}),
                      })
                    }
                    onOverrideColor={(color) => handleOverrideSchemeColor(selectedMask, color)}
                  />
                ) : (
                  <div>
                    {schemeMode ? (
                      <div>
                        {asset?.masks?.length ? (
                          (schemeMode ? schemeMasksSortedByColor : [...(asset?.masks || [])].sort((a, b) => {
                            const la = Number(a?.stats?.l_avg01 ?? a?.base_lightness ?? 999);
                            const lb = Number(b?.stats?.l_avg01 ?? b?.base_lightness ?? 999);
                            return la - lb;
                          }))
                            .map((m) => (
                              <div key={m.role} style={{ marginBottom: 12 }}>
                                <MaskBlendHistory
                                  assetId={asset.asset_id}
                                  maskRole={m.role}
                                  baseLightness={m?.stats?.l_avg01 ?? m?.base_lightness ?? null}
                                  selectorVersion={selectorVersion}
                                  rowTitle={m.role}
                                  hideHeader
                                  forceSortByBaseLightness={!schemeMode}
                                  activeColorId={activeColorByMask[m.role]}
                                  onSelectRow={(row) => setActiveColorByMask((prev) => ({ ...prev, [m.role]: row.color_id }))}
                                  onRowsChange={(rows) => {
                                    if (schemeMode) {
                                      schemeRowsRef.current[m.role] = rows;
                                    }
                                  }}
                                  filterColorIds={schemeMode ? getTesterColorIdsForMask(m.role) : null}
                                  forceApproved={schemeMode ? 1 : null}
                                  sortByColor={schemeMode}
                                  onApplyBlend={(mask, tier, preset, extra) =>
                                    handleApplyPresetValues(mask, tier, preset, {
                                      autoSave: true,
                                      autoRender: true,
                                      ...(extra || {}),
                                    })
                                  }
                                  onOverrideColor={(color) => handleOverrideSchemeColor(m.role, color)}
                                />
                              </div>
                            ))
                        ) : (
                          <div className="notice">Load a photo to see masks.</div>
                        )}
                      </div>
                    ) : allMaskColorId && allMaskColorId !== "other" ? (
                      [...(asset?.masks || [])]
                        .sort((a, b) => {
                          const la = Number(a?.stats?.l_avg01 ?? a?.base_lightness ?? 999);
                          const lb = Number(b?.stats?.l_avg01 ?? b?.base_lightness ?? 999);
                          return la - lb;
                        })
                        .map((m) => (
                        <div key={m.role} style={{ marginBottom: 12 }}>
                          <MaskBlendHistory
                            assetId={asset.asset_id}
                            maskRole={m.role}
                            baseLightness={m?.stats?.l_avg01 ?? m?.base_lightness ?? null}
                            selectorVersion={selectorVersion}
                            rowTitle={m.role}
                            hideHeader
                            filterColorId={allMaskColorId}
                            forceSortByBaseLightness
                            activeColorId={activeColorByMask[m.role]}
                            onSelectRow={(row) => setActiveColorByMask((prev) => ({ ...prev, [m.role]: row.color_id }))}
                            onApplyBlend={(mask, tier, preset, extra) =>
                              handleApplyPresetValues(mask, tier, preset, {
                                autoSave: true,
                                autoRender: true,
                                ...(extra || {}),
                              })
                            }
                            onOverrideColor={(color) => handleOverrideSchemeColor(m.role, color)}
                          />
                        </div>
                      ))
                    ) : (
                      <div className="notice">Choose a tester color to view it across all masks.</div>
                    )}
                  </div>
                )}
              </div>
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
                overlayOverrides={renderOverlayOverrides}
                onStateChange={setRenderState}
              />
            </div>
            <div />
          </div>
        </div>
      )}
      {apPalette && (
        <div className="panel" style={{ marginTop: 20 }}>
          <div className="panel-head">
            <div className="panel-title">Applied Palette Notes</div>
          </div>
          <div className="panel-body" style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            <label>
              Description
              <textarea
                value={apMetaNotes}
                onChange={(e) => setApMetaNotes(e.target.value)}
                rows={2}
                placeholder="Optional description"
              />
            </label>
            <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
              <button className="btn primary" type="button" disabled={apMetaSaving} onClick={handleSaveApMeta}>
                {apMetaSaving ? "Saving…" : "Save Notes"}
              </button>
              {apMetaNotice && <div className="notice">{apMetaNotice}</div>}
              {apMetaError && <div className="error">{apMetaError}</div>}
            </div>
          </div>
        </div>
      )}
      {testerSourceModalOpen && !hideTesterSourceControls && (
        <MaskOverlayModal
          title="Add Test Colors"
          subtitle={selectedMask ? `Mask: ${selectedMask}` : ""}
          onClose={closeTesterSourceModal}
        >
          <div style={{ display: "flex", flexDirection: "column", gap: 12, minWidth: 320 }}>
            <label>
              Tester Source
              <select
                value={testerSourceSelection}
                onChange={(e) => setTesterSourceSelection(e.target.value)}
                disabled={testerSourceBusy}
              >
                <option value="standard">Standard Test</option>
                {hoaOptions.length > 0 ? (
                  <optgroup label="HOAs">
                    {hoaOptions.map((hoa) => {
                      const label = formatHoaLabel(hoa) || `HOA #${hoa.id}`;
                      return (
                        <option key={hoa.id} value={`hoa:${hoa.id}`}>
                          {label}
                        </option>
                      );
                    })}
                  </optgroup>
                ) : (
                  <option value="" disabled>
                    {hoaLoading ? "Loading HOAs…" : "No HOAs available"}
                  </option>
                )}
              </select>
            </label>
            {hoaError && <div className="error">{hoaError}</div>}
            {testerSourceError && <div className="error">{testerSourceError}</div>}
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn" type="button" onClick={closeTesterSourceModal} disabled={testerSourceBusy}>
                Cancel
              </button>
              <button
                className="btn primary"
                type="button"
                onClick={handleApplyTesterSource}
                disabled={testerSourceBusy || !testerSourceSelection}
              >
                {testerSourceBusy ? "Loading…" : "Load & Seed"}
              </button>
            </div>
          </div>
        </MaskOverlayModal>
      )}
      {saveModalOpen && (
        <MaskOverlayModal
          title="Save Applied Palette"
          subtitle={asset?.asset_id || ""}
          onClose={closeSaveModal}
        >
          <div className="save-palette-form">
            <div className="save-palette-fields">
              <div className="field-row">
                <label>
                  Save Mode
                  <select
                    value={saveMode}
                    onChange={(e) => setSaveMode(e.target.value)}
                  >
                    <option value="new">Create new palette</option>
                    <option value="update">Update existing palette</option>
                  </select>
                </label>
                <label>
                  Load Existing
                  <select
                    value={existingPaletteId}
                    onChange={(e) => handleSelectExistingPalette(e.target.value)}
                    disabled={!asset?.asset_id}
                    onFocus={() => {
                      if (asset?.asset_id) {
                        loadExistingPalettes(asset.asset_id);
                      }
                    }}
                  >
                    <option value="">Select palette</option>
                    {existingLoading && (
                      <option value="" disabled>
                        Loading…
                      </option>
                    )}
                    {existingPalettes.map((p) => (
                      <option key={p.id} value={p.id}>
                        {(p.title || `Palette #${p.id}`) + (p.display_title ? ` — ${p.display_title}` : "")}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
              {existingLoadError && <div className="error">{existingLoadError}</div>}
              <label>
                Handle (internal)
                <input
                  type="text"
                  value={saveTitle}
                  onChange={(e) => setSaveTitle(e.target.value)}
                  placeholder="e.g., HOA/CitrusHeights/Sch1"
                />
              </label>
              <label>
                Display Title (viewer)
                <input
                  type="text"
                  value={saveDisplayTitle}
                  onChange={(e) => setSaveDisplayTitle(e.target.value)}
                  placeholder="e.g., Classic Cedar Exterior"
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
                          <span className="opacity">{(Number(entry.blend_opacity || 0) * 100).toFixed(1)}%</span>
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
            {saveNotice && <div className="notice">{saveNotice}</div>}
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
                disabled={!hasPaletteEntries || saveBusy || (saveMode === "update" && !existingPaletteId)}
                onClick={handleSaveAppliedPalette}
              >
                {saveBusy ? "Saving…" : (saveMode === "update" ? "Update Palette" : "Save Palette")}
              </button>
              {!saveBusy && (saveNotice || saveResult || saveSuccessAt) && (
                <span style={{ fontSize: 12, color: "#2e7d32" }}>
                  Saved
                </span>
              )}
            </div>
          </div>
        </MaskOverlayModal>
      )}
    </div>
  );
}
